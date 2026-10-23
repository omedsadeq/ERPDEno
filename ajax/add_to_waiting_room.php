<?php
/**
 * Add Patient to Waiting Room AJAX Endpoint
 * Adds a patient to the waiting room with optional doctor, priority, and notes
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/VisitDoctorSync.php';

// Check if user is logged in
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
Csrf::require();

$db = Database::getInstance();

// Get POST data
$patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
$doctor_id = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;
$work_type_id = !empty($_POST['work_type_id']) ? (int)$_POST['work_type_id'] : null;
$visit_type = isset($_POST['visit_type']) ? trim($_POST['visit_type']) : 'checkup';
if (!in_array($visit_type, ['checkup', 'treatment'], true)) {
    $visit_type = 'checkup';
}
$priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 0;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

// Validate patient_id
if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ناسنامەی نەخۆش پێویستە']);
    exit;
}

// Check if patient exists
$patient = $db->fetch("SELECT id, full_name, phone FROM patients WHERE id = :id", ['id' => $patient_id]);
if (!$patient) {
    echo json_encode(['success' => false, 'error' => 'نەخۆش نەدۆزرایەوە']);
    exit;
}

// Doctor isolation: a doctor may only queue a patient they already own or that
// reception referred to them, and only onto their OWN queue. This stops a doctor
// from self-assigning an arbitrary patient to gain access to their info.
if ($auth->isDoctor() && !$auth->isAdmin()) {
    AccessGuard::requirePatientGeneral($patient_id, true);
    $doctor_id = (int)($_SESSION['user_id'] ?? 0); // ignore any posted doctor
}

if ($work_type_id) {
    $validWorkType = $db->fetch(
        "SELECT id FROM work_types WHERE id = :id AND is_active = 1",
        ['id' => $work_type_id]
    );
    if (!$validWorkType) {
        $work_type_id = null;
    }
}

// Check if patient is already in waiting room (any active stage)
$existingEntry = $db->fetch(
    "SELECT id, status FROM waiting_room WHERE patient_id = :patient_id AND status IN ('waiting', 'in_progress', 'at_accounting', 'at_xray')",
    ['patient_id' => $patient_id]
);

if ($existingEntry) {
    $statusLabels = [
        'waiting' => 'چاوەڕوانی',
        'in_progress' => 'لە چارەسەرکردندایە',
        'at_accounting' => 'لە ژمێریاری',
        'at_xray' => 'لە ئێکسڕەی'
    ];
    $statusText = $statusLabels[$existingEntry['status']] ?? $existingEntry['status'];
    echo json_encode([
        'success' => false,
        'already_in_waiting' => true,
        'status' => $existingEntry['status'],
        'error' => 'ئەم نەخۆشە پێشتر لە ژووری چاوەڕوانیدایە (' . $statusText . ')'
    ]);
    exit;
}

// Add patient to waiting room
try {
    // The doctor_id in waiting_room references users table, not doctors table
    // We need to find the user_id associated with the doctor, or set to NULL
    $finalDoctorId = null;

    if ($doctor_id) {
        // Check if there's a user associated with this doctor
        // First, check if doctor_id is already a doctor account in users
        // (the x-ray department account is also selectable, like a doctor,
        // for patients who come only for an x-ray)
        $userCheck = $db->fetch("SELECT id, role FROM users WHERE id = :id AND role IN ('doctor', 'xray_department') AND is_active = 1", ['id' => $doctor_id]);
        if ($userCheck) {
            $finalDoctorId = $doctor_id;
            // X-ray is part of checkup (پشکنین), not a separate visit type
            if ($userCheck['role'] === 'xray_department') {
                $visit_type = 'checkup';
            }
        } else {
            // Resolve via the explicit doctors.user_id link, then the legacy name match
            $user = $db->fetch(
                "SELECT u.id FROM doctors d
                 JOIN users u ON u.id = d.user_id
                 WHERE d.id = :id1 AND u.role = 'doctor' AND u.is_active = 1
                 UNION
                 SELECT u.id FROM doctors d
                 JOIN users u ON u.full_name = d.name
                 WHERE d.id = :id2 AND u.role = 'doctor' AND u.is_active = 1
                 LIMIT 1",
                ['id1' => $doctor_id, 'id2' => $doctor_id]
            );
            if ($user) {
                $finalDoctorId = $user['id'];
            }
        }
    }

    $currentUser = $auth->getCurrentUser();

    // No valid doctor resolved: only fall back to the current user when they
    // ARE a doctor. Never store a receptionist/admin as the treating doctor —
    // that corrupted the waiting-room and doctor statistics.
    if ($finalDoctorId === null) {
        if ($currentUser && ($currentUser['role'] ?? '') === 'doctor') {
            $finalDoctorId = $currentUser['id'];
        } else {
            echo json_encode(['success' => false, 'error' => 'تکایە پزیشک هەڵبژێرە — پزیشکی هەڵبژێردراو بە هەژماری بەکارهێنەر پەیوەست نییە']);
            exit;
        }
    }

    $insertData = [
        'patient_id' => $patient_id,
        'doctor_id' => $finalDoctorId,
        'work_type_id' => $work_type_id,
        'visit_type' => $visit_type,
        'check_in_time' => date('Y-m-d H:i:s'),
        'status' => 'waiting',
        'priority' => $priority,
        'notes' => $notes,
        'created_by' => $currentUser['id'] ?? null
    ];

    $waitingRoomId = $db->insert('waiting_room', $insertData);

    if ($waitingRoomId) {
        AuditLog::log('waiting_check_in', 'waiting_room', $waitingRoomId, null, [
            'patient_id' => $patient_id,
            'doctor_id'  => $finalDoctorId,
            'source'     => 'ajax_add_to_waiting_room',
        ]);

        // Record the chosen doctor on the visit row (دکتۆری فەحس / دکتۆری ئیش).
        // Callers may post the exact visit_id; otherwise the patient's latest
        // visit of today is targeted.
        VisitDoctorSync::assignToVisit(
            $db,
            !empty($_POST['visit_id']) ? (int)$_POST['visit_id'] : null,
            $patient_id,
            $visit_type,
            VisitDoctorSync::doctorsIdFromUserId($db, (int)$finalDoctorId)
        );

        // Notify the assigned doctor (fail-soft)
        try {
            require_once __DIR__ . '/../classes/Notification.php';
            (new Notification())->notifyPatientWaiting(
                $finalDoctorId,
                $patient['full_name'],
                $waitingRoomId,
                $currentUser['id'] ?? null
            );
        } catch (Exception $notifyError) {
            error_log('add_to_waiting_room notify failed: ' . $notifyError->getMessage());
        }

        echo json_encode([
            'success' => true,
            'waiting_room_id' => $waitingRoomId,
            'message' => 'نەخۆش بە سەرکەوتوویی زیادکرا بۆ ژووری چاوەڕوانی',
            'patient_name' => $patient['full_name']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'هەڵە لە زیادکردنی نەخۆش بۆ ژووری چاوەڕوانی']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'هەڵە: ' . $e->getMessage()]);
}
