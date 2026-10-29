<?php
/**
 * Professional Waiting Room Management
 * سیستەمی زیرەکی کلینیکی ددان
 */

// Load config and initialize before any output
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Notification.php';
require_once __DIR__ . '/classes/VisitDoctorSync.php';
require_once __DIR__ . '/classes/Settings.php';

$db = Database::getInstance();
$auth = new Auth();
$notification = new Notification();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$isXrayDept = $auth->isXrayDepartment();
$isDoctorRole = $auth->isDoctor();
$hasFullAccess = $isAdmin || $isReceptionist; // Receptionist sees all like admin
// What a doctor may do to their OWN queue: put a patient into it (add_walk_in —
// always onto their own name), CALL one in to چارەسەر (call), finish them
// (complete), send them to the accountant (send_accounting), and edit the
// waiting-room ENTRY (work type / visit type / priority / notes).
//
// `call` is included so a doctor does not have to wait for reception to move
// their own patient into treatment — the common case when the doctor is ready
// and the front desk is busy. It is safe because the ownership guard below
// already rejects any entry whose doctor_id is not the acting user, and the
// handler resolves the room and the treating doctor from the ENTRY's doctor,
// never from whoever clicked.
//
// Still reception/admin only: hold, resume, reject, cancel, set_priority,
// delete, return_doctor, return_waiting, and the full VISIT edit
// (edit_visit.php). Those either affect other doctors' queues or undo a
// handoff, which stays a front-desk decision.
$doctorWaitingActions = ['call', 'complete', 'send_accounting', 'edit_waiting', 'add_walk_in'];
// ڕێکخستنەکان › دەستگەیشتن › «بانگکردنی نەخۆش»: the admin may limit `call` to
// chosen doctors; the others leave calling to reception.
$doctorMayCall = !$isDoctorRole || $isAdmin || Settings::doctorMayCallPatients((int)$currentUser['id']);
if (!$doctorMayCall) {
    $doctorWaitingActions = array_values(array_diff($doctorWaitingActions, ['call']));
}
// Who may put a patient into the queue from this page. The x-ray account may not:
// it receives patients, it does not check them in.
$canAddWalkIn = $isAdmin || $isReceptionist || $isDoctorRole;
$doctors = $auth->getDoctors();
// X-ray department accounts: selectable in the doctor box for x-ray-only patients
$xrayAccounts = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'xray_department' AND is_active = 1 ORDER BY full_name");

// Get filter values
$selectedDoctor = $_GET['doctor'] ?? '';
// Doctor isolation: a non-privileged user (doctor / x-ray) is locked to their OWN
// queue — any ?doctor=<other> in the URL is ignored. Only admin/receptionist may
// filter by an arbitrary doctor.
if (!$hasFullAccess) {
    $selectedDoctor = $currentUser['id'];
}
$selectedDateFrom = $_GET['date_from'] ?? ($_GET['date'] ?? date('Y-m-d'));
$selectedDateTo = $_GET['date_to'] ?? $selectedDateFrom;
$selectedDate = $selectedDateFrom; // For backward compatibility
$selectedStatus = $_GET['status'] ?? 'active';
$waitingRoomRedirectParams = [
    'date_from' => $selectedDateFrom,
    'date_to' => $selectedDateTo,
    'status' => $selectedStatus
];
if ($selectedDoctor !== '') {
    $waitingRoomRedirectParams['doctor'] = $selectedDoctor;
}
$buildWaitingRoomRedirect = static function (array $extra = []) use ($waitingRoomRedirectParams): string {
    return APP_URL . '/waiting-room.php?' . http_build_query(array_merge($waitingRoomRedirectParams, $extra));
};
$hasVisitTypeColumn = !empty($db->fetch("
    SELECT COUNT(*) AS count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'waiting_room'
      AND COLUMN_NAME = 'visit_type'
")['count']);

function syncVisitCheckinTimeFromWaitingRoom(Database $db, array $waitingInfo, string $calledAt): void {
    $patientId = (int)($waitingInfo['patient_id'] ?? 0);
    if ($patientId <= 0) {
        return;
    }

    $visitDateSource = $waitingInfo['check_in_time'] ?? $calledAt;
    $db->query("
        UPDATE visits
        SET checkin_time = :checkin_time
        WHERE id = (
            SELECT id FROM (
                SELECT id
                FROM visits
                WHERE patient_id = :patient_id
                  AND visit_date = DATE(:visit_date_source)
                ORDER BY id DESC
                LIMIT 1
            ) latest_visit
        )
    ", [
        'checkin_time' => date('H:i:s', strtotime($calledAt)),
        'patient_id' => $patientId,
        'visit_date_source' => $visitDateSource,
    ]);
}

function clearVisitCheckinTimeFromWaitingRoom(Database $db, array $waitingInfo): void {
    $patientId = (int)($waitingInfo['patient_id'] ?? 0);
    if ($patientId <= 0) {
        return;
    }

    $visitDateSource = $waitingInfo['check_in_time'] ?? date('Y-m-d H:i:s');
    $db->query("
        UPDATE visits
        SET checkin_time = NULL
        WHERE id = (
            SELECT id FROM (
                SELECT id
                FROM visits
                WHERE patient_id = :patient_id
                  AND visit_date = DATE(:visit_date_source)
                ORDER BY id DESC
                LIMIT 1
            ) latest_visit
        )
    ", [
        'patient_id' => $patientId,
        'visit_date_source' => $visitDateSource,
    ]);
}

// Handle POST actions BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();

    $waitingId = intval($_POST['waiting_id'] ?? 0);
    $action = $_POST['action'];
    $actorId = (int)$currentUser['id'];

    // Load the target row once: used for ownership check, status guard and audit trail
    $waitingRow = $waitingId ? $db->fetch("SELECT * FROM waiting_room WHERE id = :id", ['id' => $waitingId]) : null;

    // Ownership: non-admin/non-receptionist users may only act on their own
    // queue. Exception: the x-ray room calls ANY at_xray patient in turn —
    // the entry's doctor is the requesting doctor, never the x-ray account.
    $isXrayCall = ($action === 'call_xray' && $isXrayDept);
    if ($waitingRow && !$hasFullAccess && !$isXrayCall && (int)$waitingRow['doctor_id'] !== $actorId) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }

    // A doctor whose call permission was taken away gets a message, not a 403.
    if ($action === 'call' && !$doctorMayCall) {
        header('Location: ' . $buildWaitingRoomRedirect(['error' => 'call_denied']));
        exit;
    }

    // Doctors may only run the allowed queue actions; everything else
    // (hold, resume, reject, cancel, priority, edit, delete, return_*)
    // is reception/admin only.
    if ($isDoctorRole && !in_array($action, $doctorWaitingActions, true)) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }

    // Entries in a terminal state can only be reopened explicitly (return_waiting) or deleted
    $terminalStatuses = ['completed', 'rejected', 'cancelled', 'left'];
    if ($waitingRow && in_array($waitingRow['status'], $terminalStatuses, true)
        && !in_array($action, ['return_waiting', 'delete'], true)) {
        header('Location: ' . $buildWaitingRoomRedirect(['blocked' => 1]));
        exit;
    }

    // Small helper: audit every queue status transition with the acting user
    $logWaitingStatus = static function ($newStatus, array $extraNew = []) use ($waitingId, $waitingRow, $actorId, $action) {
        AuditLog::log('waiting_' . $newStatus, 'waiting_room', $waitingId,
            ['status' => $waitingRow['status'] ?? null],
            array_merge(['status' => $newStatus], $extraNew));
        // One row per handoff in the patient-flow timeline
        PatientFlow::record(
            $waitingId,
            (int)($waitingRow['patient_id'] ?? 0),
            $waitingRow['status'] ?? null,
            $newStatus,
            $actorId,
            $action,
            trim($_POST['flow_note'] ?? '')
        );
    };

    if ($action === 'open_case' && $waitingRow) {
        // «کەیس»: straight into the entry's doctor's case for this patient —
        // made now if there is none (CaseManager::quickOpen). A doctor opens
        // their own (the ownership check above), the admin anyone's.
        $caseDoctor = $db->fetch("SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1", ['id' => (int)($waitingRow['doctor_id'] ?? 0)]);
        if (!$caseDoctor || !($isAdmin || $isDoctorRole)) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
        require_once __DIR__ . '/classes/CaseManager.php';
        $caseId = (new CaseManager())->quickOpen((int)$waitingRow['patient_id'], (int)$caseDoctor['id'], $actorId);
        if (!$caseId) {
            header('Location: ' . $buildWaitingRoomRedirect(['error' => 'case_failed']));
            exit;
        }
        header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=chart');
        exit;
    }

    if ($action === 'call' && $waitingId) {
        // Get waiting room details for notification
        $waitingInfo = $db->fetch("
            SELECT wr.*, p.full_name as patient_name
            FROM waiting_room wr
            LEFT JOIN patients p ON wr.patient_id = p.id
            WHERE wr.id = :id
        ", ['id' => $waitingId]);

        $calledAt = date('Y-m-d H:i:s');

        // Which room is the patient called into? A doctor can have two rooms:
        // the client asks the caller to pick; single-room doctors auto-assign.
        // Posted values are only accepted if they match one of the doctor's rooms.
        $roomName = trim((string)($_POST['room_name'] ?? ''));
        $doctorRooms = [];
        if ($waitingInfo && $waitingInfo['doctor_id']) {
            $docRow = $db->fetch("SELECT room_number, room_number_2 FROM users WHERE id = :id", ['id' => $waitingInfo['doctor_id']]);
            $doctorRooms = array_values(array_filter([
                trim((string)($docRow['room_number'] ?? '')),
                trim((string)($docRow['room_number_2'] ?? '')),
            ], static fn($r) => $r !== ''));
        }
        if ($roomName === '' || !in_array($roomName, $doctorRooms, true)) {
            $roomName = $doctorRooms[0] ?? null;
        }

        $db->update('waiting_room', [
            'status' => 'in_progress',
            'called_time' => $calledAt,
            'room_name' => $roomName,
            'updated_by' => $actorId
        ], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('in_progress', $roomName !== null ? ['room_name' => $roomName] : []);
        if ($waitingInfo) {
            syncVisitCheckinTimeFromWaitingRoom($db, $waitingInfo, $calledAt);
            // The doctor who actually calls the patient in is the authoritative
            // دکتۆری فەحس / دکتۆری ئیش — overwrite whatever the dialogs chose.
            VisitDoctorSync::assignToVisit(
                $db,
                null,
                (int)($waitingInfo['patient_id'] ?? 0),
                $waitingInfo['visit_type'] ?? null,
                VisitDoctorSync::doctorsIdFromUserId($db, (int)($waitingInfo['doctor_id'] ?? 0)),
                $waitingInfo['check_in_time'] ?? $calledAt
            );
        }
        if (!empty($_POST['appointment_id'])) {
            $db->update('appointments', ['status' => 'in_progress', 'updated_by' => $actorId], 'id = :id', ['id' => (int)$_POST['appointment_id']]);
        }

        // Send notification to the doctor
        if ($waitingInfo && $waitingInfo['doctor_id']) {
            $notification->notifyPatientCalled(
                $waitingInfo['doctor_id'],
                $waitingInfo['patient_name'],
                $waitingId,
                $currentUser['id']
            );
        }

        header('Location: ' . $buildWaitingRoomRedirect(['called' => 1]));
        exit;
    } elseif ($action === 'complete' && $waitingId) {
        $db->update('waiting_room', [
            'status' => 'completed',
            'check_out_time' => date('Y-m-d H:i:s'),
            'updated_by' => $actorId
        ], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('completed');
        if (!empty($_POST['appointment_id'])) {
            $db->update('appointments', ['status' => 'completed', 'updated_by' => $actorId], 'id = :id', ['id' => (int)$_POST['appointment_id']]);
        }
        $patientRow = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => (int)($waitingRow['patient_id'] ?? 0)]);
        $notification->notifyVisitStatus('completed', (int)($waitingRow['doctor_id'] ?? 0),
            $patientRow['full_name'] ?? '', $waitingId, $actorId);
        header('Location: ' . $buildWaitingRoomRedirect(['completed' => 1]));
        exit;
    } elseif ($action === 'send_accounting' && $waitingId) {
        // Doctor finished the clinical part — patient goes to the accounting station
        $db->update('waiting_room', [
            'status' => 'at_accounting',
            'updated_by' => $actorId
        ], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('at_accounting');

        // Tell accountants and admins a patient is on the way
        $patientRow = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => (int)($waitingRow['patient_id'] ?? 0)]);
        $patientName = $patientRow['full_name'] ?? '';
        $financeUsers = $db->fetchAll("SELECT id FROM users WHERE role IN ('accountant', 'admin') AND is_active = 1");
        foreach ($financeUsers as $financeUser) {
            $notification->create(
                $financeUser['id'],
                'patient_accounting',
                'نەخۆش بۆ ژمێریاری',
                "نەخۆش {$patientName} نێردرا بۆ بەشی ژمێریاری بۆ پارەدان",
                ['waiting_id' => $waitingId, 'patient_name' => $patientName],
                $actorId,
                date('Y-m-d H:i:s', strtotime('+8 hours'))
            );
        }
        header('Location: ' . $buildWaitingRoomRedirect(['sent_accounting' => 1]));
        exit;
    } elseif ($action === 'call_xray' && $waitingId) {
        // X-ray room calls the patient in — like a doctor calling from the
        // queue, but the "room" is the scan room. Turn order is enforced by
        // picking the patient's OWN pending request; the queue panel already
        // shows who is next (urgent first, then first-come-first-served).
        if (!$isXrayDept && !$hasFullAccess) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
        if (($waitingRow['status'] ?? '') !== 'at_xray') {
            header('Location: ' . $buildWaitingRoomRedirect(['blocked' => 1]));
            exit;
        }
        $xreq = $db->fetch(
            "SELECT id, request_number FROM xray_requests
             WHERE patient_id = :pid AND request_status = 'pending_xray'
             ORDER BY (priority = 'urgent') DESC, created_at ASC
             LIMIT 1",
            ['pid' => (int)$waitingRow['patient_id']]
        );
        if (!$xreq) {
            // at_xray without an open request (legacy/manual row) — nothing to start
            header('Location: ' . $buildWaitingRoomRedirect(['no_xray_request' => 1]));
            exit;
        }
        require_once __DIR__ . '/classes/XrayManager.php';
        (new XrayManager())->startXray((int)$xreq['id'], $actorId, $_SESSION['role'] ?? '', 'بانگکردن لە ژووری چاوەڕوانییەوە');

        // Tell the front desk (and the patient's doctor) who was just called in
        $patientRow = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => (int)$waitingRow['patient_id']]);
        $callMsg = "نەخۆشی ئێکسڕەی " . ($patientRow['full_name'] ?? '') . " بانگکرا بۆ ژووری ئێکسڕەی - " . $xreq['request_number'];
        $callData = ['waiting_id' => $waitingId, 'patient_name' => $patientRow['full_name'] ?? '', 'xray_id' => (int)$xreq['id']];
        $notification->notifyRole(['receptionist'], 'patient_called', 'بانگکردنی نەخۆشی ئێکسڕەی', $callMsg,
            $callData, '/waiting-room.php', $actorId, date('Y-m-d H:i:s', strtotime('+2 hours')));
        if (!empty($waitingRow['doctor_id'])) {
            $notification->notifyUsers([(int)$waitingRow['doctor_id']], 'patient_called', 'بانگکردنی نەخۆشی ئێکسڕەی', $callMsg,
                $callData, '/waiting-room.php', $actorId, date('Y-m-d H:i:s', strtotime('+2 hours')));
        }

        header('Location: ' . $buildWaitingRoomRedirect(['called_xray' => 1]));
        exit;
    } elseif ($action === 'return_doctor' && $waitingId) {
        // Patient comes back from accounting / x-ray to the doctor
        $db->update('waiting_room', [
            'status' => 'in_progress',
            'updated_by' => $actorId
        ], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('in_progress', ['returned_from' => $waitingRow['status'] ?? null]);

        if (!empty($waitingRow['doctor_id'])) {
            $patientRow = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => (int)($waitingRow['patient_id'] ?? 0)]);
            $notification->create(
                (int)$waitingRow['doctor_id'],
                'patient_returned',
                'نەخۆش گەڕایەوە',
                "نەخۆش " . ($patientRow['full_name'] ?? '') . " گەڕایەوە بۆ لای تۆ",
                ['waiting_id' => $waitingId],
                $actorId,
                date('Y-m-d H:i:s', strtotime('+1 hour'))
            );
        }
        header('Location: ' . $buildWaitingRoomRedirect(['returned_doctor' => 1]));
        exit;
    } elseif ($action === 'return_waiting' && $waitingId) {
        $waitingInfo = $db->fetch("
            SELECT wr.*
            FROM waiting_room wr
            WHERE wr.id = :id
        ", ['id' => $waitingId]);

        $db->update('waiting_room', [
            'status' => 'waiting',
            'called_time' => null,
            'check_out_time' => null,
            'updated_by' => $actorId
        ], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('waiting', ['reopened' => true]);
        if ($waitingInfo) {
            clearVisitCheckinTimeFromWaitingRoom($db, $waitingInfo);
        }
        if (!empty($_POST['appointment_id'])) {
            $db->update('appointments', ['status' => 'waiting', 'updated_by' => $actorId], 'id = :id', ['id' => (int)$_POST['appointment_id']]);
        }
        header('Location: ' . $buildWaitingRoomRedirect(['returned' => 1]));
        exit;
    } elseif ($action === 'hold' && $waitingId) {
        $db->update('waiting_room', ['status' => 'on_hold', 'updated_by' => $actorId], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('on_hold');
        header('Location: ' . $buildWaitingRoomRedirect(['held' => 1]));
        exit;
    } elseif ($action === 'resume' && $waitingId) {
        $db->update('waiting_room', ['status' => 'waiting', 'updated_by' => $actorId], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('waiting', ['resumed' => true]);
        header('Location: ' . $buildWaitingRoomRedirect(['resumed' => 1]));
        exit;
    } elseif ($action === 'reject' && $waitingId) {
        $rejectReasonKey = trim($_POST['reject_reason'] ?? '');
        $rejectReasonText = '';
        if ($rejectReasonKey !== '') {
            $reasonRow = $db->fetch(
                "SELECT reason_text FROM waiting_room_rejection_reasons WHERE reason_key = :reason_key AND is_active = 1",
                ['reason_key' => $rejectReasonKey]
            );
            $rejectReasonText = trim($reasonRow['reason_text'] ?? '');
        }
        $rejectNotes = trim($_POST['notes'] ?? '');
        $combinedRejectNotes = trim(($rejectReasonText !== '' ? $rejectReasonText : $rejectReasonKey) . ($rejectNotes !== '' ? ' - ' . $rejectNotes : ''));

        $db->update('waiting_room', [
            'status' => 'rejected',
            'notes' => $combinedRejectNotes,
            'updated_by' => $actorId
        ], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('rejected', ['reason' => $combinedRejectNotes]);
        if (!empty($_POST['appointment_id'])) {
            $db->update('appointments', ['status' => 'cancelled', 'cancelled_by' => $actorId, 'cancelled_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorId], 'id = :id', ['id' => (int)$_POST['appointment_id']]);
        }
        $patientRow = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => (int)($waitingRow['patient_id'] ?? 0)]);
        $notification->notifyVisitStatus('rejected', (int)($waitingRow['doctor_id'] ?? 0),
            $patientRow['full_name'] ?? '', $waitingId, $actorId, $combinedRejectNotes);
        header('Location: ' . $buildWaitingRoomRedirect(['rejected' => 1]));
        exit;
    } elseif ($action === 'cancel' && $waitingId) {
        $db->update('waiting_room', ['status' => 'cancelled', 'updated_by' => $actorId], 'id = :id', ['id' => $waitingId]);
        $logWaitingStatus('cancelled');
        $patientRow = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => (int)($waitingRow['patient_id'] ?? 0)]);
        $notification->notifyVisitStatus('cancelled', (int)($waitingRow['doctor_id'] ?? 0),
            $patientRow['full_name'] ?? '', $waitingId, $actorId);
        header('Location: ' . $buildWaitingRoomRedirect(['cancelled' => 1]));
        exit;
    } elseif ($action === 'set_priority' && $waitingId) {
        $priority = intval($_POST['priority'] ?? 0);
        $db->update('waiting_room', ['priority' => $priority, 'updated_by' => $actorId], 'id = :id', ['id' => $waitingId]);
        AuditLog::log('waiting_priority', 'waiting_room', $waitingId,
            ['priority' => $waitingRow['priority'] ?? null], ['priority' => $priority]);
        header('Location: ' . $buildWaitingRoomRedirect(['priority_set' => 1]));
        exit;
    } elseif ($action === 'edit_waiting' && $waitingId) {
        $workTypeId = !empty($_POST['work_type_id']) ? intval($_POST['work_type_id']) : null;
        $visitType = $_POST['visit_type'] ?? 'treatment';

        if (!in_array($visitType, ['checkup', 'treatment', 'xray'], true)) {
            $visitType = 'treatment';
        }

        if ($workTypeId) {
            $validWorkType = $db->fetch(
                "SELECT id FROM work_types WHERE id = :id AND is_active = 1",
                ['id' => $workTypeId]
            );
            if (!$validWorkType) {
                $workTypeId = null;
            }
        }

        $updateData = [
            'work_type_id' => $workTypeId,
            'priority' => intval($_POST['priority'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '')
        ];

        if ($hasVisitTypeColumn) {
            $updateData['visit_type'] = $visitType;
        }

        if ($hasFullAccess) {
            $doctorId = intval($_POST['doctor_id'] ?? 0);
            if ($doctorId > 0) {
                $updateData['doctor_id'] = $doctorId;
                // Reassigned to another doctor → the called-into room is stale
                if ($waitingRow && (int)$waitingRow['doctor_id'] !== $doctorId) {
                    $updateData['room_name'] = null;
                }
            }
        }

        $updateData['updated_by'] = $actorId;
        $db->update('waiting_room', $updateData, 'id = :id', ['id' => $waitingId]);
        AuditLog::logChange('waiting_edit', 'waiting_room', $waitingId, $waitingRow ?: [], $updateData);
        header('Location: ' . $buildWaitingRoomRedirect(['updated' => 1]));
        exit;
    } elseif ($action === 'delete' && $waitingId && $hasFullAccess) {
        // Keep a full snapshot in the audit trail before the hard delete
        if ($waitingRow) {
            AuditLog::log('waiting_delete', 'waiting_room', $waitingId, $waitingRow);
        }
        $db->delete('waiting_room', 'id = :id', ['id' => $waitingId]);
        header('Location: ' . $buildWaitingRoomRedirect(['deleted' => 1]));
        exit;
    } elseif ($action === 'add_walk_in') {
        $patientId = intval($_POST['patient_id'] ?? 0);
        // A doctor queues THEIR OWN patient onto THEIR OWN name: the posted
        // doctor_id is ignored for them, so the box can never be aimed at a
        // colleague or at the x-ray account, whatever the form says.
        $doctorId = ($isDoctorRole && !$isAdmin) ? $actorId : intval($_POST['doctor_id'] ?? 0);
        // The row is inserted straight from this id, so it has to be a real,
        // undeleted patient before anything else happens. This runs BEFORE the
        // access check so a blank/stale box gets the banner, not a bare 403.
        $patientExists = $patientId > 0
            ? $db->fetch("SELECT id FROM patients WHERE id = :id AND deleted_at IS NULL", ['id' => $patientId])
            : null;
        if (!$patientExists) {
            header('Location: ' . $buildWaitingRoomRedirect(['error' => 'no_patient']));
            exit;
        }
        // ...and only for a patient they are allowed to see at all (owns /
        // reception-referred / registered themselves — same rule as everywhere).
        if ($isDoctorRole && !$isAdmin) {
            AccessGuard::requirePatientGeneral($patientId);
        }
        $workTypeId = !empty($_POST['work_type_id']) ? intval($_POST['work_type_id']) : null;
        $visitType = $_POST['visit_type'] ?? 'treatment';

        if (!in_array($visitType, ['checkup', 'treatment', 'xray'], true)) {
            $visitType = 'treatment';
        }

        if ($workTypeId) {
            $validWorkType = $db->fetch(
                "SELECT id FROM work_types WHERE id = :id AND is_active = 1",
                ['id' => $workTypeId]
            );
            if (!$validWorkType) {
                $workTypeId = null;
            }
        }

        // The assigned account must be an active doctor OR the x-ray department
        // account (a patient can come just for an x-ray — chosen like a doctor)
        $validAssignee = $doctorId > 0
            ? $db->fetch("SELECT id, role FROM users WHERE id = :id AND role IN ('doctor', 'xray_department') AND is_active = 1", ['id' => $doctorId])
            : null;
        if (!$validAssignee) {
            header('Location: ' . $buildWaitingRoomRedirect(['error' => 'no_doctor']));
            exit;
        }
        // X-ray is part of checkup (پشکنین), not a separate visit type
        if ($validAssignee['role'] === 'xray_department') {
            $visitType = 'checkup';
        }

        // Guard against duplicate active check-ins for the same patient
        $existingEntry = $db->fetch(
            "SELECT id FROM waiting_room
             WHERE patient_id = :patient_id
               AND status IN ('waiting', 'called', 'in_progress', 'in_treatment', 'on_hold', 'at_accounting', 'at_xray')
             LIMIT 1",
            ['patient_id' => $patientId]
        );
        if ($existingEntry) {
            header('Location: ' . $buildWaitingRoomRedirect(['error' => 'already_waiting']));
            exit;
        }

        // Get patient name for notification
        $patient = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => $patientId]);

        $insertData = [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'work_type_id' => $workTypeId,
            'check_in_time' => date('Y-m-d H:i:s'),
            'status' => 'waiting',
            'priority' => intval($_POST['priority'] ?? 0),
            'notes' => trim($_POST['notes'] ?? ''),
            'created_by' => $actorId
        ];

        if ($hasVisitTypeColumn) {
            $insertData['visit_type'] = $visitType;
        }

        $newWaitingId = $db->insert('waiting_room', $insertData);
        AuditLog::log('waiting_check_in', 'waiting_room', $newWaitingId, null, [
            'patient_id' => $patientId,
            'doctor_id'  => $doctorId,
            'source'     => 'walk_in',
        ]);

        // Record the chosen doctor on the visit row (دکتۆری فەحس / دکتۆری ئیش).
        // The dialog on visits.php posts the exact visit_id; other callers fall
        // back to the patient's latest visit of today.
        VisitDoctorSync::assignToVisit(
            $db,
            !empty($_POST['visit_id']) ? (int)$_POST['visit_id'] : null,
            $patientId,
            $visitType,
            VisitDoctorSync::doctorsIdFromUserId($db, $doctorId)
        );

        // Send notification to the doctor about new patient waiting
        if ($patient && $doctorId) {
            $notification->notifyPatientWaiting(
                $doctorId,
                $patient['full_name'],
                $newWaitingId,
                $currentUser['id']
            );
        }

        header('Location: ' . $buildWaitingRoomRedirect(['added' => 1]));
        exit;
    }
}

// Now include header for output
$pageTitle = 'ژووری چاوەڕوانی';
$breadcrumb = [['title' => 'ژووری چاوەڕوانی']];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/classes/Appointment.php';

$doctorId = $hasFullAccess ? null : $currentUser['id'];

// Build query for waiting room data
$visitTypeSelect = $hasVisitTypeColumn
    ? "COALESCE(wr.visit_type, a.type, 'treatment') AS display_visit_type"
    : "COALESCE(a.type, 'treatment') AS display_visit_type";

$sql = "SELECT wr.*, p.full_name, p.phone, p.patient_code, p.gender,
        u.full_name as doctor_name, u.room_number as doctor_room, u.room_number_2 as doctor_room_2, u.role as doctor_role,
        a.appointment_time, a.type as appointment_type,
        {$visitTypeSelect},
        (
            SELECT v.id
            FROM visits v
            WHERE v.patient_id = wr.patient_id
              AND v.visit_date = DATE(wr.check_in_time)
            ORDER BY v.id DESC
            LIMIT 1
        ) AS edit_visit_id,
        COALESCE(wr.work_type_id, a.work_type_id) AS display_work_type_id,
        wt.work_name AS work_type_name
        FROM waiting_room wr
        LEFT JOIN patients p ON wr.patient_id = p.id
        LEFT JOIN users u ON wr.doctor_id = u.id
        LEFT JOIN appointments a ON wr.appointment_id = a.id
        LEFT JOIN work_types wt ON wt.id = COALESCE(wr.work_type_id, a.work_type_id)
        WHERE (
            wr.status IN ('waiting', 'in_progress', 'on_hold', 'at_accounting', 'at_xray')
            OR DATE(wr.check_in_time) BETWEEN :date_from AND :date_to
        )";

$params = ['date_from' => $selectedDateFrom, 'date_to' => $selectedDateTo];

if ($selectedDoctor) {
    $sql .= " AND wr.doctor_id = :doctor_id";
    $params['doctor_id'] = $selectedDoctor;
} elseif (!$hasFullAccess) {
    $sql .= " AND wr.doctor_id = :doctor_id";
    $params['doctor_id'] = $currentUser['id'];
    $selectedDoctor = $currentUser['id'];
}

// Status filter
if ($selectedStatus === 'active') {
    $sql .= " AND wr.status IN ('waiting', 'in_progress', 'on_hold', 'at_accounting', 'at_xray')";
} elseif ($selectedStatus !== 'all') {
    $sql .= " AND wr.status = :status";
    $params['status'] = $selectedStatus;
}

$sql .= " ORDER BY
    CASE wr.status
        WHEN 'in_progress' THEN 1
        WHEN 'at_accounting' THEN 2
        WHEN 'at_xray' THEN 3
        WHEN 'waiting' THEN 4
        WHEN 'on_hold' THEN 5
        WHEN 'completed' THEN 6
        WHEN 'rejected' THEN 7
        WHEN 'cancelled' THEN 8
    END,
    wr.priority DESC,
    wr.check_in_time ASC";

$waitingList = $db->fetchAll($sql, $params);

// Separate by status for statistics — same doctor scope as the main list
// (a doctor only ever counts their OWN patients)
$statsSql = "SELECT status FROM waiting_room WHERE (
    status IN ('waiting', 'in_progress', 'on_hold', 'at_accounting', 'at_xray')
    OR DATE(check_in_time) BETWEEN :date_from AND :date_to
)";
$statsParams = ['date_from' => $selectedDateFrom, 'date_to' => $selectedDateTo];
if ($selectedDoctor) {
    $statsSql .= " AND doctor_id = :doctor_id";
    $statsParams['doctor_id'] = $selectedDoctor;
}
$allForStats = $db->fetchAll($statsSql, $statsParams);
$stats = [
    'waiting' => 0,
    'in_progress' => 0,
    'on_hold' => 0,
    'at_accounting' => 0,
    'at_xray' => 0,
    'completed' => 0,
    'rejected' => 0,
    'cancelled' => 0
];
foreach ($allForStats as $item) {
    if (isset($stats[$item['status']])) {
        $stats[$item['status']]++;
    }
}
$totalToday = array_sum($stats);
$activeCount = $stats['waiting'] + $stats['in_progress'] + $stats['on_hold'] + $stats['at_accounting'] + $stats['at_xray'];

// Get rejected patients count for badge — same doctor scope
$rejectedSql = "SELECT COUNT(*) as count FROM waiting_room WHERE status IN ('rejected', 'cancelled')";
$rejectedParams = [];
if ($selectedDoctor) {
    $rejectedSql .= " AND doctor_id = :doctor_id";
    $rejectedParams['doctor_id'] = $selectedDoctor;
}
$rejectedCount = $db->fetch($rejectedSql, $rejectedParams)['count'] ?? 0;

// Calculate average wait time for completed patients — same doctor scope
$avgWaitSql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)) as avg_time
    FROM waiting_room
    WHERE DATE(check_in_time) BETWEEN :date_from AND :date_to AND status = 'completed' AND check_out_time IS NOT NULL";
$avgWaitParams = ['date_from' => $selectedDateFrom, 'date_to' => $selectedDateTo];
if ($selectedDoctor) {
    $avgWaitSql .= " AND doctor_id = :doctor_id";
    $avgWaitParams['doctor_id'] = $selectedDoctor;
}
$avgWaitTime = $db->fetch($avgWaitSql, $avgWaitParams);
$avgWait = round($avgWaitTime['avg_time'] ?? 0);

$reportDoctorId = $selectedDoctor !== '' ? (int)$selectedDoctor : (!$hasFullAccess ? (int)$currentUser['id'] : null);
$reportVisitTypeExpr = $hasVisitTypeColumn
    ? "COALESCE(wr.visit_type, a.type, 'treatment')"
    : "COALESCE(a.type, 'treatment')";

$normalizeWaitingReport = function(array $row): array {
    $row['total_entries'] = (int)($row['total_entries'] ?? 0);
    $row['completed_entries'] = (int)($row['completed_entries'] ?? 0);
    $row['waiting_entries'] = (int)($row['waiting_entries'] ?? 0);
    $row['in_progress_entries'] = (int)($row['in_progress_entries'] ?? 0);
    $row['on_hold_entries'] = (int)($row['on_hold_entries'] ?? 0);
    $row['station_entries'] = (int)($row['station_entries'] ?? 0);
    $row['rejected_entries'] = (int)($row['rejected_entries'] ?? 0);
    $row['checkup_entries'] = (int)($row['checkup_entries'] ?? 0);
    $row['treatment_entries'] = (int)($row['treatment_entries'] ?? 0);
    $row['avg_completion_minutes'] = (int)round((float)($row['avg_completion_minutes'] ?? 0));
    $row['active_entries'] = $row['waiting_entries'] + $row['in_progress_entries'] + $row['on_hold_entries'] + $row['station_entries'];
    return $row;
};

$fetchWaitingReportSummary = function(string $from, string $to) use ($db, $reportDoctorId, $reportVisitTypeExpr, $normalizeWaitingReport): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($reportDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $reportDoctorId;
    }

    $row = $db->fetch("
        SELECT
            COUNT(*) AS total_entries,
            SUM(CASE WHEN wr.status = 'completed' THEN 1 ELSE 0 END) AS completed_entries,
            SUM(CASE WHEN wr.status = 'waiting' THEN 1 ELSE 0 END) AS waiting_entries,
            SUM(CASE WHEN wr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_entries,
            SUM(CASE WHEN wr.status = 'on_hold' THEN 1 ELSE 0 END) AS on_hold_entries,
            SUM(CASE WHEN wr.status IN ('at_accounting', 'at_xray') THEN 1 ELSE 0 END) AS station_entries,
            SUM(CASE WHEN wr.status IN ('rejected', 'cancelled') THEN 1 ELSE 0 END) AS rejected_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'checkup' THEN 1 ELSE 0 END) AS checkup_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'treatment' THEN 1 ELSE 0 END) AS treatment_entries,
            AVG(CASE
                WHEN wr.status = 'completed' AND wr.check_out_time IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, wr.check_in_time, wr.check_out_time)
                ELSE NULL
            END) AS avg_completion_minutes
        FROM waiting_room wr
        LEFT JOIN appointments a ON wr.appointment_id = a.id
        WHERE {$where}
    ", $params) ?: [];

    return $normalizeWaitingReport($row);
};

$fetchWaitingDoctorReport = function(string $from, string $to) use ($db, $reportDoctorId, $reportVisitTypeExpr): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($reportDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $reportDoctorId;
    }

    $rows = $db->fetchAll("
        SELECT
            wr.doctor_id,
            u.full_name AS doctor_name,
            u.room_number AS doctor_room,
            COUNT(*) AS total_entries,
            SUM(CASE WHEN wr.status = 'completed' THEN 1 ELSE 0 END) AS completed_entries,
            SUM(CASE WHEN wr.status = 'waiting' THEN 1 ELSE 0 END) AS waiting_entries,
            SUM(CASE WHEN wr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_entries,
            SUM(CASE WHEN wr.status = 'on_hold' THEN 1 ELSE 0 END) AS on_hold_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'checkup' THEN 1 ELSE 0 END) AS checkup_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'treatment' THEN 1 ELSE 0 END) AS treatment_entries,
            AVG(CASE
                WHEN wr.status = 'completed' AND wr.check_out_time IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, wr.check_in_time, wr.check_out_time)
                ELSE NULL
            END) AS avg_completion_minutes
        FROM waiting_room wr
        LEFT JOIN users u ON wr.doctor_id = u.id
        LEFT JOIN appointments a ON wr.appointment_id = a.id
        WHERE {$where}
        GROUP BY wr.doctor_id, u.full_name, u.room_number
        ORDER BY completed_entries DESC, total_entries DESC, doctor_name ASC
    ", $params);

    foreach ($rows as &$row) {
        $row['total_entries'] = (int)($row['total_entries'] ?? 0);
        $row['completed_entries'] = (int)($row['completed_entries'] ?? 0);
        $row['waiting_entries'] = (int)($row['waiting_entries'] ?? 0);
        $row['in_progress_entries'] = (int)($row['in_progress_entries'] ?? 0);
        $row['on_hold_entries'] = (int)($row['on_hold_entries'] ?? 0);
        $row['checkup_entries'] = (int)($row['checkup_entries'] ?? 0);
        $row['treatment_entries'] = (int)($row['treatment_entries'] ?? 0);
        $row['avg_completion_minutes'] = (int)round((float)($row['avg_completion_minutes'] ?? 0));
    }
    unset($row);

    return $rows;
};

$todayDate = date('Y-m-d');
$monthFrom = date('Y-m-01');
$monthTo = date('Y-m-t');
$todayReport = $fetchWaitingReportSummary($todayDate, $todayDate);
$monthReport = $fetchWaitingReportSummary($monthFrom, $monthTo);
$rangeReport = $fetchWaitingReportSummary($selectedDateFrom, $selectedDateTo);
$monthDoctorReport = $fetchWaitingDoctorReport($monthFrom, $monthTo);

$pdfDoctorParam = $reportDoctorId ? '&doctor_id=' . $reportDoctorId : '';
$todayReportPdfUrl = APP_URL . '/waiting-room-report-pdf.php?mode=today' . $pdfDoctorParam;
$monthReportPdfUrl = APP_URL . '/waiting-room-report-pdf.php?mode=month' . $pdfDoctorParam;
$rangeReportPdfUrl = APP_URL . '/waiting-room-report-pdf.php?mode=range&from=' . urlencode($selectedDateFrom) . '&to=' . urlencode($selectedDateTo) . $pdfDoctorParam;
$selectedRangeLabel = $selectedDateFrom === $selectedDateTo ? $selectedDateFrom : ($selectedDateFrom . ' - ' . $selectedDateTo);

// Global types + the ones added here, plus any type already saved on a listed
// entry so an edit never drops a type created on another page
$workTypes = $db->fetchAll("
    SELECT id, work_name
    FROM work_types
    WHERE is_active = 1 AND "
    . work_type_scope_sql('waiting_room', array_column($waitingList, 'display_work_type_id')) . "
    ORDER BY sort_order, id
");

$waitingRoomRejectReasons = $db->fetchAll("
    SELECT id, reason_key, reason_text
    FROM waiting_room_rejection_reasons
    WHERE is_active = 1
    ORDER BY sort_order, id
");
?>

<style>
/* Professional Waiting Room Styles - Compact */
.wr-page-header {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 12px;
    color: white;
}

.wr-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.wr-header-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.wr-header-title h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}

.wr-header-title .wr-live-badge {
    background: rgba(255,255,255,0.2);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.wr-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

/* Stats Cards - Compact Inline */
.wr-stats-grid {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.wr-stat-card {
    background: white;
    border-radius: 6px;
    padding: 8px 14px;
    border: 1px solid #e0e0e0;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    cursor: pointer;
}

.wr-stat-card:hover {
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    transform: translateY(-1px);
}

.wr-stat-card.active { border-right: 3px solid var(--win-accent, #0078d4); }
.wr-stat-card.waiting { border-right: 3px solid #ff9800; }
.wr-stat-card.in-progress { border-right: 3px solid #2196f3; }
.wr-stat-card.completed { border-right: 3px solid #4caf50; }
.wr-stat-card.on-hold { border-right: 3px solid #9c27b0; }
.wr-stat-card.at-accounting { border-right: 3px solid #009688; }
.wr-stat-card.at-xray { border-right: 3px solid #607d8b; }
.wr-stat-card.rejected { border-right: 3px solid #f44336; }

.wr-stat-value {
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
}

.wr-stat-card.active .wr-stat-value { color: var(--win-accent, #0078d4); }
.wr-stat-card.waiting .wr-stat-value { color: #ff9800; }
.wr-stat-card.in-progress .wr-stat-value { color: #2196f3; }
.wr-stat-card.completed .wr-stat-value { color: #4caf50; }
.wr-stat-card.on-hold .wr-stat-value { color: #9c27b0; }
.wr-stat-card.at-accounting .wr-stat-value { color: #009688; }
.wr-stat-card.at-xray .wr-stat-value { color: #607d8b; }
.wr-stat-card.rejected .wr-stat-value { color: #f44336; }

.wr-stat-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
}

.wr-stat-card.is-selected {
    background: #f8fbff;
    box-shadow: 0 0 0 2px rgba(var(--win-accent-rgb, 0, 120, 212), 0.14);
}

.wr-stat-card.is-selected .wr-stat-label {
    color: #1f2937;
    font-weight: 700;
}

.wr-report-section {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 12px;
}

.wr-report-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.wr-report-title {
    font-size: 15px;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.wr-report-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.wr-report-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    border: 1px solid #d7e3f3;
    color: #0f172a;
    background: #f8fbff;
}

.wr-report-btn:hover {
    background: #eef6ff;
}

.wr-report-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}

.wr-report-card {
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    padding: 12px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.wr-report-card.today {
    border-top: 3px solid #0ea5e9;
}

.wr-report-card.month {
    border-top: 3px solid #16a34a;
}

.wr-report-card.range {
    border-top: 3px solid #f57c00;
}

.wr-report-card-title {
    font-size: 13px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.wr-report-card-subtitle {
    font-size: 11px;
    color: #6b7280;
}

.wr-report-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.wr-report-metric {
    border-radius: 8px;
    padding: 10px;
    background: #fff;
    border: 1px solid #edf2f7;
}

.wr-report-metric-value {
    font-size: 18px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 4px;
    color: #111827;
}

.wr-report-metric-label {
    font-size: 11px;
    color: #6b7280;
    font-weight: 600;
}

.wr-report-table-wrap {
    overflow-x: auto;
}

.wr-report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.wr-report-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    padding: 10px 8px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.wr-report-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #eef2f7;
    text-align: center;
    color: #334155;
}

.wr-report-table td:first-child,
.wr-report-table th:first-child {
    text-align: right;
}

.wr-report-table tbody tr:hover {
    background: #fafcff;
}

.wr-report-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}

.wr-report-pill.checkup {
    background: #e0f2fe;
    color: #0369a1;
}

.wr-report-pill.treatment {
    background: #dcfce7;
    color: #15803d;
}

@media (max-width: 1024px) {
    .wr-report-grid {
        grid-template-columns: 1fr;
    }
}

/* Filter Bar - Compact */
.wr-filter-bar {
    background: white;
    border-radius: 6px;
    padding: 8px 12px;
    margin-bottom: 12px;
    border: 1px solid #e0e0e0;
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.wr-filter-group {
    display: flex;
    align-items: center;
    gap: 6px;
}

.wr-filter-label {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    white-space: nowrap;
}

.wr-filter-control {
    padding: 5px 10px;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    font-size: 13px;
    font-family: inherit;
    background: #fafafa;
    transition: all 0.2s;
    min-width: 130px;
}

.wr-filter-control:focus {
    border-color: var(--win-accent, #0078d4);
    background: white;
    box-shadow: 0 0 0 2px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1);
    outline: none;
}

/* Main Content Grid */
.wr-main-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

@media (max-width: 1024px) {
    .wr-main-grid {
        grid-template-columns: 1fr;
    }
}

/* Panel Styles - Compact */
.wr-panel {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.wr-panel-header {
    padding: 10px 14px;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}

.wr-panel-header.waiting {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
}

.wr-panel-header.in-progress {
    background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
    color: white;
}

.wr-panel-header.on-hold {
    background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
    color: white;
}

.wr-panel-header.completed {
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
    color: white;
}

.wr-panel-header.at-accounting {
    background: linear-gradient(135deg, #009688 0%, #00796b 100%);
    color: white;
}

.wr-panel-header.at-xray {
    background: linear-gradient(135deg, #607d8b 0%, #455a64 100%);
    color: white;
}

.wr-panel-title {
    display: flex;
    align-items: center;
    gap: 8px;
}

.wr-panel-badge {
    background: rgba(255,255,255,0.25);
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 12px;
}

.wr-panel-header-tools {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.wr-type-summary {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.wr-type-count {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.18);
}

.wr-type-count.checkup {
    background: rgba(14, 165, 233, 0.22);
}

.wr-type-count.treatment {
    background: rgba(34, 197, 94, 0.24);
}

.wr-type-count.xray {
    background: rgba(96, 125, 139, 0.28);
}

.wr-waiting-type-filters {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.wr-type-filter-btn {
    border: 1px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.16);
    color: #fff;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 12px;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.2s;
}

.wr-type-filter-btn:hover {
    background: rgba(255,255,255,0.24);
}

.wr-type-filter-btn.active {
    background: #fff;
    color: #c2410c;
    border-color: #fff;
}

.wr-panel-body {
    padding: 8px;
    max-height: 420px;
    overflow-y: auto;
}

/* Patient Card - Compact */
.wr-patient-card {
    background: #fafafa;
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 6px;
    border: 1px solid #eee;
    transition: all 0.2s;
}

.wr-patient-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border-color: #ddd;
}

.wr-patient-card:last-child {
    margin-bottom: 0;
}

.wr-patient-card.priority-high {
    border-right: 3px solid #f44336;
    background: #fff5f5;
}

.wr-patient-card.priority-medium {
    border-right: 3px solid #ff9800;
    background: #fff8e1;
}

.wr-patient-top {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}

.wr-queue-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    flex-shrink: 0;
}

.wr-queue-number.waiting { background: #fff3e0; color: #e65100; }
.wr-queue-number.in_progress { background: #e3f2fd; color: #1565c0; }
.wr-queue-number.on_hold { background: #f3e5f5; color: #7b1fa2; }
.wr-queue-number.at_accounting { background: #e0f2f1; color: #00695c; }
.wr-queue-number.at_xray { background: #eceff1; color: #37474f; }

.wr-patient-info {
    flex: 1;
    min-width: 0;
}

.wr-patient-name {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.wr-patient-name a {
    color: #1f2937;
    text-decoration: none;
}

.wr-patient-name a:hover {
    color: var(--win-accent, #0078d4);
}

.wr-priority-badge {
    font-size: 9px;
    padding: 1px 5px;
    border-radius: 3px;
    font-weight: 600;
}

.wr-priority-badge.high { background: #f44336; color: white; }
.wr-priority-badge.medium { background: #ff9800; color: white; }

.wr-patient-meta {
    font-size: 11px;
    color: #6b7280;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.wr-patient-meta span {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.wr-visit-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.5;
}

.wr-visit-type-badge.checkup {
    background: #e0f2fe;
    color: #0369a1;
}

.wr-visit-type-badge.xray {
    background: #eceff1;
    color: #37474f;
}

.wr-visit-type-badge.treatment {
    background: #dcfce7;
    color: #15803d;
}

.wr-filter-empty-state {
    display: none;
    text-align: center;
    padding: 28px 14px;
    color: #6b7280;
}

.wr-filter-empty-state i {
    display: block;
    font-size: 28px;
    margin-bottom: 8px;
    color: #cbd5e1;
}

.wr-wait-time {
    font-weight: 600;
}

.wr-wait-time.long { color: #f44336; }
.wr-wait-time.medium { color: #ff9800; }

.wr-patient-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    padding-top: 6px;
    border-top: 1px solid #eee;
    margin-top: 6px;
}

.wr-btn {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    transition: all 0.2s;
    font-family: inherit;
}

/* «کەیس» — the one the doctor reaches for: bigger, and in a colour of its own */
.wr-btn-case {
    font-size: 14px; font-weight: 800; padding: 10px 20px; border: 0; border-radius: 10px;
    background: linear-gradient(135deg, #6d28d9, #4c1d95); color: #fff;
    box-shadow: 0 4px 12px rgba(76, 29, 149, .32);
}
.wr-btn-case:hover { background: linear-gradient(135deg, #5b21b6, #3b0764); color: #fff; transform: translateY(-1px); }
.wr-btn-call {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
}

.wr-btn-call:hover {
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.4);
}

.wr-btn-complete {
    background: linear-gradient(135deg, #2196f3, #1976d2);
    color: white;
}

.wr-btn-complete:hover {
    box-shadow: 0 2px 8px rgba(33, 150, 243, 0.4);
}

.wr-btn-hold {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
}

.wr-btn-hold:hover {
    box-shadow: 0 2px 8px rgba(156, 39, 176, 0.4);
}

.wr-btn-accounting {
    background: linear-gradient(135deg, #009688, #00796b);
    color: white;
}

.wr-btn-accounting:hover {
    box-shadow: 0 2px 8px rgba(0, 150, 136, 0.4);
}

.wr-btn-xray {
    background: linear-gradient(135deg, #607d8b, #455a64);
    color: white;
}

.wr-btn-xray:hover {
    box-shadow: 0 2px 8px rgba(96, 125, 139, 0.4);
}

.wr-btn-return {
    background: linear-gradient(135deg, #607d8b, #455a64);
    color: white;
}

.wr-btn-return:hover {
    box-shadow: 0 2px 8px rgba(96, 125, 139, 0.4);
}

.wr-btn-resume {
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    color: white;
}

.wr-btn-reject {
    background: linear-gradient(135deg, #f44336, #d32f2f);
    color: white;
}

.wr-btn-reject:hover {
    box-shadow: 0 2px 8px rgba(244, 67, 54, 0.4);
}

.wr-btn-delete {
    background: linear-gradient(135deg, #424242, #212121);
    color: white;
}

.wr-btn-delete:hover {
    box-shadow: 0 2px 8px rgba(33, 33, 33, 0.35);
}

.wr-btn-cancel {
    background: #f5f5f5;
    color: #666;
    border: 1px solid #ddd;
}

.wr-btn-cancel:hover {
    background: #eee;
}

.wr-btn-priority {
    background: #fff3e0;
    color: #e65100;
    border: 1px solid #ffcc80;
}

.wr-btn-edit {
    background: linear-gradient(135deg, #ffb300, #fb8c00);
    color: white;
}

.wr-btn-edit:hover {
    box-shadow: 0 2px 8px rgba(251, 140, 0, 0.35);
}

/* Clickable Card Styles */
.wr-patient-card.clickable {
    cursor: pointer;
    position: relative;
}

.wr-patient-card.clickable:hover {
    border-color: var(--win-accent, #0078d4);
    box-shadow: 0 4px 12px rgba(var(--win-accent-rgb, 0, 120, 212), 0.15);
    transform: translateY(-1px);
}

.wr-patient-card.selected {
    border-color: var(--win-accent, #0078d4);
    background: linear-gradient(135deg, rgba(var(--win-accent-rgb, 0, 120, 212), 0.08) 0%, rgba(var(--win-accent-rgb, 0, 120, 212), 0.03) 100%);
    box-shadow: 0 0 0 2px rgba(var(--win-accent-rgb, 0, 120, 212), 0.2);
}

.wr-patient-card.selected::after {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, var(--win-accent, #0078d4) 0%, #00a8ff 100%);
    border-radius: 4px 0 0 4px;
}

/* Patient Detail Panel */
.wr-detail-panel {
    position: fixed;
    top: 0;
    left: 0;
    width: 420px;
    height: 100vh;
    background: white;
    box-shadow: 4px 0 24px rgba(0, 0, 0, 0.15);
    z-index: 1050;
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    display: flex;
    flex-direction: column;
}

.wr-detail-panel.open {
    transform: translateX(0);
}

.wr-detail-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.4);
    z-index: 1049;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}

.wr-detail-overlay.open {
    opacity: 1;
    visibility: visible;
}

.wr-detail-header {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.wr-detail-header h3 {
    margin: 0;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.wr-detail-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.wr-detail-close:hover {
    background: rgba(255,255,255,0.25);
}

.wr-detail-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
}

.wr-detail-section {
    margin-bottom: 20px;
}

.wr-detail-section-title {
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.wr-detail-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.wr-detail-info-item {
    background: #f8fafc;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.wr-detail-info-item.full-width {
    grid-column: 1 / -1;
}

.wr-detail-info-label {
    font-size: 11px;
    color: #6b7280;
    margin-bottom: 4px;
}

.wr-detail-info-value {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}

.wr-detail-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.wr-detail-status.waiting {
    background: #fff3e0;
    color: #e65100;
}

.wr-detail-status.in_progress {
    background: #e3f2fd;
    color: #1565c0;
}

.wr-detail-status.on_hold {
    background: #f3e5f5;
    color: #7b1fa2;
}

.wr-detail-status.completed {
    background: #e8f5e9;
    color: #2e7d32;
}

.wr-detail-status.rejected,
.wr-detail-status.cancelled {
    background: #ffebee;
    color: #c62828;
}

.wr-detail-actions {
    padding: 16px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.wr-detail-btn {
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    font-family: inherit;
}

.wr-detail-btn-call {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
}

.wr-detail-btn-call:hover {
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
}

.wr-detail-btn-complete {
    background: linear-gradient(135deg, #2196f3, #1976d2);
    color: white;
}

.wr-detail-btn-complete:hover {
    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
}

.wr-detail-btn-hold {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
}

.wr-detail-btn-hold:hover {
    box-shadow: 0 4px 12px rgba(156, 39, 176, 0.4);
}

.wr-detail-btn-return {
    background: linear-gradient(135deg, #607d8b, #455a64);
    color: white;
}

.wr-detail-btn-return:hover {
    box-shadow: 0 4px 12px rgba(96, 125, 139, 0.4);
}

.wr-detail-btn-reject {
    background: linear-gradient(135deg, #f44336, #d32f2f);
    color: white;
}

.wr-detail-btn-reject:hover {
    box-shadow: 0 4px 12px rgba(244, 67, 54, 0.4);
}

.wr-detail-btn-delete {
    background: linear-gradient(135deg, #424242, #212121);
    color: white;
}

.wr-detail-btn-delete:hover {
    box-shadow: 0 4px 12px rgba(33, 33, 33, 0.35);
}

.wr-detail-btn-edit {
    background: linear-gradient(135deg, #ffb300, #fb8c00);
    color: white;
}

.wr-detail-btn-edit:hover {
    box-shadow: 0 4px 12px rgba(251, 140, 0, 0.35);
}

.wr-detail-btn-view {
    background: white;
    color: var(--win-accent, #0078d4);
    border: 1px solid var(--win-accent, #0078d4);
}

.wr-detail-btn-view:hover {
    background: #f0f7ff;
}

/* Search Input Style */
.wr-search-input {
    padding: 5px 10px 5px 30px;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    font-size: 13px;
    font-family: inherit;
    background: #fafafa url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%236b7280' viewBox='0 0 24 24' width='16' height='16'%3E%3Cpath d='M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z'/%3E%3C/svg%3E") 8px center no-repeat;
    transition: all 0.2s;
    min-width: 180px;
}

.wr-search-input:focus {
    border-color: var(--win-accent, #0078d4);
    background-color: white;
    box-shadow: 0 0 0 2px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1);
    outline: none;
}

/* Empty State - Compact */
.wr-empty-state {
    text-align: center;
    padding: 24px 16px;
    color: #9e9e9e;
}

.wr-empty-state i {
    font-size: 32px;
    margin-bottom: 8px;
    opacity: 0.5;
}

.wr-empty-state p {
    margin: 0;
    font-size: 13px;
}

/* Completed Table - Compact */
.wr-completed-section {
    margin-top: 12px;
}

.wr-table {
    width: 100%;
    border-collapse: collapse;
}

.wr-table th,
.wr-table td {
    padding: 8px 10px;
    text-align: right;
    border-bottom: 1px solid #eee;
}

.wr-table th {
    background: #f9fafb;
    font-weight: 600;
    font-size: 12px;
    color: #374151;
}

.wr-table tr:hover td {
    background: #f9fafb;
}

.wr-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.wr-status-badge.completed { background: #e8f5e9; color: #2e7d32; }
.wr-status-badge.rejected { background: #ffebee; color: #c62828; }
.wr-status-badge.cancelled { background: #fafafa; color: #757575; }

/* Dialog Styles - Compact */
.wr-dialog-modern {
    max-width: 380px;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.15);
}

.wr-dialog-header {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);
    color: white;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.wr-dialog-header.reject {
    background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
}

.wr-dialog-header h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.wr-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.wr-dialog-close:hover { background: rgba(255,255,255,0.25); }

.wr-dialog-body { padding: 14px; }

.wr-form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.wr-form-field { display: flex; flex-direction: column; gap: 4px; }

.wr-form-label {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
}

.wr-required { color: #ef4444; margin-left: 2px; }

.wr-form-control {
    padding: 8px 10px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fafafa;
}

.wr-form-control:focus {
    border-color: var(--win-accent, #0078d4);
    background: white;
    box-shadow: 0 0 0 2px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1);
    outline: none;
}

.wr-work-type-combo {
    display: flex;
    gap: 6px;
    align-items: stretch;
}

.wr-work-type-combo .wr-form-control {
    flex: 1;
}

.wr-add-work-btn {
    min-width: 40px;
    border: 1px solid var(--win-accent, #0078d4);
    border-radius: 6px;
    background: var(--win-accent, #0078d4);
    color: #fff;
    cursor: pointer;
    font-size: 15px;
    transition: background 0.2s, transform 0.2s;
}

.wr-add-work-btn:hover {
    background: var(--win-accent-dark, #005a9e);
    transform: translateY(-1px);
}

.wr-add-work-btn:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.2);
}

.wr-inline-error {
    color: #f44336;
    font-size: 13px;
    margin-top: 6px;
    display: none;
}

textarea.wr-form-control {
    resize: vertical;
    min-height: 50px;
}

.wr-dialog-footer {
    padding: 10px 14px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

/* Quick Actions - Compact */
.wr-quick-btn {
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    font-family: inherit;
}

.wr-quick-btn-primary {
    background: white;
    color: var(--win-accent, #0078d4);
    border: 2px solid rgba(255,255,255,0.3);
}

.wr-quick-btn-primary:hover {
    background: rgba(255,255,255,0.9);
}

.wr-quick-btn-tv {
    background: rgba(156, 39, 176, 0.2);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.2);
}

.wr-quick-btn-tv:hover {
    background: rgba(156, 39, 176, 0.35);
    color: #fff;
}

.wr-quick-btn-rejected {
    background: rgba(244, 67, 54, 0.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.2);
}

.wr-quick-btn-rejected:hover {
    background: rgba(244, 67, 54, 0.25);
}

.wr-rejected-count {
    background: #f44336;
    color: white;
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 11px;
    margin-right: 4px;
}

/* ===== Walk-In Patient Search ===== */
.wi-patient-search-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1;
}

.wi-patient-search-row {
    display: flex;
    align-items: stretch;
    gap: 8px;
}

.wi-add-patient-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 112px;
    padding: 0 14px;
    border: 1px solid #16a34a;
    border-radius: 8px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(22, 163, 74, 0.18);
    transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
}

.wi-add-patient-btn:hover {
    background: linear-gradient(135deg, #15803d, #166534);
    box-shadow: 0 6px 14px rgba(22, 163, 74, 0.26);
    transform: translateY(-1px);
}

.wi-add-patient-btn:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.2);
}

.wi-search-icon {
    position: absolute;
    right: 10px;
    color: #6b7280;
    font-size: 13px;
    pointer-events: none;
    z-index: 1;
}

.wi-search-input {
    padding-right: 34px !important;
    padding-left: 32px !important;
    width: 100%;
}

.wi-clear-btn {
    position: absolute;
    left: 8px;
    background: none;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    padding: 0;
    font-size: 15px;
    line-height: 1;
    transition: color 0.2s;
}

.wi-clear-btn:hover { color: #ef4444; }

/* Results dropdown */
.wi-results-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    left: 0;
    background: #fff;
    border: 1.5px solid var(--win-accent, #0078d4);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.13);
    z-index: 2000;
    max-height: 220px;
    overflow-y: auto;
}

.wi-result-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.15s;
}

.wi-result-item:last-child { border-bottom: none; }

.wi-result-item:hover, .wi-result-item:focus {
    background: #eff6ff;
    outline: none;
}

.wi-result-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--win-accent, #0078d4), #00a8ff);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

.wi-result-info { flex: 1; min-width: 0; }

.wi-result-name {
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wi-result-meta {
    font-size: 11px;
    color: #6b7280;
    margin-top: 1px;
    display: flex;
    gap: 8px;
}

.wi-result-code {
    background: #e0e7ff;
    color: #3730a3;
    border-radius: 4px;
    padding: 0px 5px;
    font-size: 10px;
    font-weight: 600;
}

.wi-no-results {
    padding: 16px;
    text-align: center;
    color: #9ca3af;
    font-size: 13px;
}

.wi-searching {
    padding: 12px;
    text-align: center;
    color: #6b7280;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

/* Selected patient badge */
.wi-selected-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
    padding: 7px 10px;
    background: linear-gradient(135deg, #d1fae5, #ecfdf5);
    border: 1px solid #34d399;
    border-radius: 7px;
    font-size: 13px;
    color: #065f46;
    font-weight: 600;
}

.wi-selected-badge i { color: #059669; }

.wi-badge-phone {
    margin-right: auto;
    font-size: 11px;
    color: #6b7280;
    font-weight: 400;
}

/* relative wrapper for dropdown positioning */
.wi-patient-field-wrapper {
    position: relative;
}

.wr-iframe-dialog {
    width: min(1100px, 96vw);
    max-width: 1100px;
}

.wr-iframe-dialog-body {
    padding: 0;
    height: min(82vh, 920px);
    background: #f3f6fb;
    overflow: hidden;
}

.wr-iframe-dialog-body iframe {
    width: 100%;
    height: 100%;
    border: 0;
    display: block;
    background: #f3f6fb;
    overflow: auto;
}

/* =====================================================================
   Mobile Responsive Layer for Waiting Room (≤ 767px and ≤ 480px)
   ===================================================================== */
@media (max-width: 767px) {
    /* Page Header — title + actions stack */
    .wr-page-header {
        padding: 10px 12px;
        border-radius: 6px;
    }
    .wr-header-content {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    .wr-header-title {
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .wr-header-title h2 { font-size: 16px; }
    .wr-header-title > div > div {
        font-size: 12px !important;
        margin-top: 2px !important;
    }
    .wr-live-badge { font-size: 10px; padding: 2px 7px; }

    /* Header action buttons: 2-column grid, full-tap */
    .wr-header-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        width: 100%;
    }
    .wr-quick-btn {
        justify-content: center;
        padding: 10px 8px;
        font-size: 12px;
        min-height: 42px;
        text-align: center;
    }

    /* Stats grid: 3-col on phone, no horizontal overflow */
    .wr-stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 6px;
        margin-bottom: 10px;
    }
    .wr-stat-card {
        flex-direction: column;
        padding: 8px 6px;
        gap: 2px;
        text-align: center;
        min-width: 0;
    }
    .wr-stat-value { font-size: 18px; }
    .wr-stat-label {
        font-size: 11px;
        line-height: 1.2;
        text-align: center;
    }

    /* Filter bar: stack groups, full-width controls */
    .wr-filter-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        padding: 10px;
    }
    .wr-filter-group {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: 4px;
    }
    .wr-filter-control {
        width: 100%;
        min-width: 0;
        font-size: 16px; /* prevent iOS zoom */
        padding: 9px 12px;
        min-height: 40px;
    }
    .wr-filter-label {
        font-size: 11px;
        color: #6b7280;
    }

    /* Main grid already stacks via existing 1024px rule */
    .wr-main-grid { gap: 10px; }

    /* Panel header: title + tools stack */
    .wr-panel-header {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        padding: 10px 12px;
        font-size: 13px;
    }
    .wr-panel-header-tools {
        justify-content: flex-start;
        flex-wrap: wrap;
        gap: 6px;
    }
    .wr-type-summary { gap: 4px; }
    .wr-type-count {
        font-size: 10px;
        padding: 2px 7px;
    }
    .wr-waiting-type-filters { gap: 4px; }
    .wr-type-filter-btn {
        font-size: 11px;
        padding: 4px 9px;
    }
    .wr-search-input { width: 100%; }

    /* Panel body: more height for scrolling */
    .wr-panel-body {
        max-height: none;
        padding: 6px;
    }

    /* Patient card */
    .wr-patient-card { padding: 10px; }
    .wr-patient-top { gap: 8px; margin-bottom: 6px; }
    .wr-queue-number { width: 30px; height: 30px; font-size: 13px; }
    .wr-patient-name { font-size: 14px; flex-wrap: wrap; }
    .wr-patient-meta {
        font-size: 11px;
        gap: 4px 8px;
    }

    /* Patient action buttons full row, tap-friendly */
    .wr-patient-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
        padding-top: 8px;
        margin-top: 8px;
    }
    .wr-btn {
        justify-content: center;
        padding: 9px 6px;
        font-size: 12px;
        min-height: 38px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Detail Panel — full-width slide-in on mobile */
    .wr-detail-panel {
        width: 100%;
        max-width: 100%;
    }
    .wr-detail-header { padding: 14px 14px; }
    .wr-detail-header h3 { font-size: 14px; }
    .wr-detail-body { padding: 14px; }
    .wr-detail-info-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .wr-detail-info-item { padding: 10px; }
    .wr-detail-actions {
        padding: 12px 14px;
        flex-direction: column;
        gap: 8px;
    }
    .wr-detail-btn {
        width: 100%;
        justify-content: center;
        padding: 11px 14px;
        font-size: 13px;
        min-height: 44px;
    }

    /* Report section (currently hidden via if(false) but future-proof) */
    .wr-report-section { padding: 10px; }
    .wr-report-grid { grid-template-columns: 1fr; }
    .wr-report-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .wr-report-actions { width: 100%; }
    .wr-report-actions .wr-report-btn { flex: 1 1 calc(50% - 4px); justify-content: center; }
    .wr-report-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* Walk-In search */
    .wi-patient-search-row { flex-direction: column; }
    .wi-add-patient-btn { min-height: 42px; width: 100%; }

    /* Iframe dialog full-screen */
    .wr-iframe-dialog {
        width: 100vw;
        max-width: 100vw;
        height: 100vh;
        max-height: 100vh;
        margin: 0;
        border-radius: 0;
    }
    .wr-iframe-dialog-body { height: calc(100vh - 60px); }
}

/* Small mobile (≤ 480px) — tightest */
@media (max-width: 480px) {
    .wr-header-actions {
        grid-template-columns: 1fr 1fr;
    }
    .wr-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .wr-stat-value { font-size: 16px; }
    .wr-stat-label { font-size: 10px; }

    /* Patient action buttons single-column when very narrow */
    .wr-patient-actions {
        grid-template-columns: 1fr 1fr;
    }
    .wr-btn { font-size: 11px; padding: 8px 4px; }

    .wr-report-metrics { grid-template-columns: 1fr; }
}

</style>

<?php if (isset($_GET['called'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> نەخۆش بانگکرا</div>
<?php endif; ?>
<?php if (isset($_GET['called_xray'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-x-ray alert-icon"></i> نەخۆش بانگکرا بۆ ژووری ئێکسڕەی</div>
<?php endif; ?>
<?php if (isset($_GET['no_xray_request'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> هیچ داواکارییەکی ئێکسڕەی کراوە نییە بۆ ئەم نەخۆشە — سەرەتا داواکاری دروست بکە</div>
<?php endif; ?>
<?php if (isset($_GET['completed'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> چارەسەر تەواوبوو</div>
<?php endif; ?>
<?php if (isset($_GET['held'])): ?>
<div class="alert alert-info" data-auto-hide><i class="fas fa-pause-circle alert-icon"></i> نەخۆش وەستێنرا</div>
<?php endif; ?>
<?php if (isset($_GET['resumed'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-play-circle alert-icon"></i> نەخۆش گەڕایەوە بۆ چاوەڕوانی</div>
<?php endif; ?>
<?php if (isset($_GET['returned'])): ?>
<div class="alert alert-info" data-auto-hide><i class="fas fa-undo alert-icon"></i> نەخۆش گەڕێندرایەوە بۆ ژووری چاوەڕوانی</div>
<?php endif; ?>
<?php if (isset($_GET['rejected'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-ban alert-icon"></i> نەخۆش ڕەتکرایەوە</div>
<?php endif; ?>
<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> نەخۆش زیادکرا بۆ چاوەڕوانی</div>
<?php endif; ?>
<?php
// add_walk_in has always redirected with ?error=… and nothing ever printed it,
// so a refused check-in looked like a silent no-op.
$wrErrorMessages = [
    'no_doctor'       => 'دکتۆرێکی چالاک هەڵبژێرە',
    'already_waiting' => 'ئەم نەخۆشە پێشتر لە ژووری چاوەڕوانیدایە',
    'no_patient'      => 'نەخۆش نەدۆزرایەوە',
    'call_denied'     => 'دەسەڵاتی بانگکردنی نەخۆشت نییە — پێشوازی نەخۆشەکەت بۆ بانگ دەکات',
];
$wrErrorMessage = $wrErrorMessages[$_GET['error'] ?? ''] ?? null;
?>
<?php if ($wrErrorMessage): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> <?= htmlspecialchars($wrErrorMessage) ?></div>
<?php endif; ?>
<?php if (!$doctorMayCall): ?>
<div class="alert alert-info"><i class="fas fa-bullhorn alert-icon"></i> بانگکردنی نەخۆشەکانت لەلایەن <b>پێشوازی</b>یەوە دەکرێت — کاتێک نەخۆش بانگکرا، لە بەشی «لە چارەسەردا» دەردەکەوێت.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-trash alert-icon"></i> Waiting room entry deleted permanently</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-edit alert-icon"></i> Waiting room entry updated</div>
<?php endif; ?>

<!-- Page Header -->
<div class="wr-page-header">
    <div class="wr-header-content">
        <div class="wr-header-title">
            <i class="fas fa-users fa-lg"></i>
            <div>
                <h2>ژووری چاوەڕوانی</h2>
                <div style="font-size: 13px; opacity: 0.9; margin-top: 4px;">
                    <i class="fas fa-calendar-day"></i> <?= date('Y-m-d') === $selectedDate ? 'ئەمڕۆ' : $selectedDate ?>
                    &nbsp;•&nbsp;
                    <i class="fas fa-clock"></i> <?= date('h:i A') ?>
                </div>
            </div>
            <?php if (date('Y-m-d') === $selectedDate): ?>
            <span class="wr-live-badge"><i class="fas fa-circle"></i> زیندوو</span>
            <?php endif; ?>
        </div>
        <div class="wr-header-actions">
            <a href="<?= APP_URL ?>/waiting-monitor.php" target="_blank" class="wr-quick-btn wr-quick-btn-tv" style="text-decoration: none;" title="شاشەی تەلەڤزیۆن بۆ ژووری چاوەڕوانی">
                <i class="fas fa-tv"></i> شاشەی TV
            </a>
            <a href="<?= APP_URL ?>/waiting-room-report.php" class="wr-quick-btn" style="text-decoration: none; background: rgba(255,255,255,0.12); color:#fff; border:1px solid rgba(255,255,255,0.18);">
                <i class="fas fa-chart-line"></i> ڕاپۆرت
            </a>
            <a href="<?= APP_URL ?>/rejected-patients.php" class="wr-quick-btn wr-quick-btn-rejected" style="text-decoration: none;">
                <i class="fas fa-ban"></i> ڕەتکراوەکان
                <?php if ($rejectedCount > 0): ?>
                <span class="wr-rejected-count"><?= $rejectedCount ?></span>
                <?php endif; ?>
            </a>
            <?php if ($canAddWalkIn): ?>
            <button type="button" class="wr-quick-btn" style="background:#107c10;color:#fff;border:1px solid rgba(255,255,255,0.25);" onclick="openDialog('walkInDialog')">
                <i class="fas fa-user-plus"></i> ناردنی نەخۆش بۆ چاوەڕوانی
            </button>
            <?php endif; ?>
            <?php if ($permission->can('page.new_visit')): ?>
            <a href="<?= APP_URL ?>/new_visit.php" data-new-visit-dialog class="wr-quick-btn wr-quick-btn-primary" style="text-decoration: none;">
                <i class="fas fa-user-plus"></i> نەخۆشی تازە
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="wr-stats-grid">
    <button type="button" class="wr-stat-card active <?= $selectedStatus === 'active' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('active')">
        <div class="wr-stat-value"><?= $activeCount ?></div>
        <div class="wr-stat-label">چالاک</div>
    </button>
    <button type="button" class="wr-stat-card waiting <?= $selectedStatus === 'waiting' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('waiting')">
        <div class="wr-stat-value"><?= $stats['waiting'] ?></div>
        <div class="wr-stat-label">چاوەڕوان</div>
    </button>
    <button type="button" class="wr-stat-card in-progress <?= $selectedStatus === 'in_progress' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('in_progress')">
        <div class="wr-stat-value"><?= $stats['in_progress'] ?></div>
        <div class="wr-stat-label">لە چارەسەردا</div>
    </button>
    <button type="button" class="wr-stat-card on-hold <?= $selectedStatus === 'on_hold' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('on_hold')">
        <div class="wr-stat-value"><?= $stats['on_hold'] ?></div>
        <div class="wr-stat-label">وەستێنراو</div>
    </button>
    <button type="button" class="wr-stat-card at-accounting <?= $selectedStatus === 'at_accounting' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('at_accounting')">
        <div class="wr-stat-value"><?= $stats['at_accounting'] ?></div>
        <div class="wr-stat-label">لە ژمێریاری</div>
    </button>
    <button type="button" class="wr-stat-card at-xray <?= $selectedStatus === 'at_xray' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('at_xray')">
        <div class="wr-stat-value"><?= $stats['at_xray'] ?></div>
        <div class="wr-stat-label">لە ئێکسڕەی</div>
    </button>
    <button type="button" class="wr-stat-card completed <?= $selectedStatus === 'completed' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('completed')">
        <div class="wr-stat-value"><?= $stats['completed'] ?></div>
        <div class="wr-stat-label">تەواوبوو</div>
    </button>
    <button type="button" class="wr-stat-card rejected <?= $selectedStatus === 'rejected' ? 'is-selected' : '' ?>" onclick="applyStatusFilter('rejected')">
        <div class="wr-stat-value"><?= $stats['rejected'] + $stats['cancelled'] ?></div>
        <div class="wr-stat-label">ڕەتکراوە/هەڵوەشاوە</div>
    </button>
</div>

<?php if (false): ?>
<div class="wr-report-section" id="waitingReportSection">
    <div class="wr-report-header">
        <div class="wr-report-title">
            <i class="fas fa-chart-line"></i>
            ڕاپۆرتی ژووری چاوەڕوانی
        </div>
        <div class="wr-report-actions">
            <a href="<?= htmlspecialchars($todayReportPdfUrl) ?>" class="wr-report-btn" target="_blank">
                <i class="fas fa-file-pdf"></i> PDFی ئەمڕۆ
            </a>
            <a href="<?= htmlspecialchars($monthReportPdfUrl) ?>" class="wr-report-btn" target="_blank">
                <i class="fas fa-file-pdf"></i> PDFی ئەم مانگە
            </a>
            <a href="<?= htmlspecialchars($rangeReportPdfUrl) ?>" class="wr-report-btn" target="_blank">
                <i class="fas fa-file-pdf"></i> PDFی بازنەی دیاریکراو
            </a>
        </div>
    </div>

    <div class="wr-report-grid">
        <div class="wr-report-card today">
            <div class="wr-report-card-title">
                <span><i class="fas fa-sun"></i> ئەمڕۆ</span>
                <span class="wr-report-card-subtitle"><?= htmlspecialchars($todayDate) ?></span>
            </div>
            <div class="wr-report-metrics">
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $todayReport['total_entries'] ?></div>
                    <div class="wr-report-metric-label">هاتنە ژووری چاوەڕوانی</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $todayReport['completed_entries'] ?></div>
                    <div class="wr-report-metric-label">تەواوبوو لە ژووری دکتۆر</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $todayReport['checkup_entries'] ?></div>
                    <div class="wr-report-metric-label">پشکنین</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $todayReport['treatment_entries'] ?></div>
                    <div class="wr-report-metric-label">ئیش</div>
                </div>
            </div>
        </div>

        <div class="wr-report-card month">
            <div class="wr-report-card-title">
                <span><i class="fas fa-calendar-alt"></i> ئەم مانگە</span>
                <span class="wr-report-card-subtitle"><?= htmlspecialchars($monthFrom) ?> - <?= htmlspecialchars($monthTo) ?></span>
            </div>
            <div class="wr-report-metrics">
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $monthReport['total_entries'] ?></div>
                    <div class="wr-report-metric-label">کۆی هاتنەکان</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $monthReport['completed_entries'] ?></div>
                    <div class="wr-report-metric-label">تەواوبووی ژووری دکتۆر</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $monthReport['checkup_entries'] ?></div>
                    <div class="wr-report-metric-label">پشکنین</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $monthReport['treatment_entries'] ?></div>
                    <div class="wr-report-metric-label">ئیش</div>
                </div>
            </div>
        </div>

        <div class="wr-report-card range">
            <div class="wr-report-card-title">
                <span><i class="fas fa-calendar-week"></i> بازنەی دیاریکراو</span>
                <span class="wr-report-card-subtitle"><?= htmlspecialchars($selectedRangeLabel) ?></span>
            </div>
            <div class="wr-report-metrics">
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $rangeReport['active_entries'] ?></div>
                    <div class="wr-report-metric-label">ئێستا چالاک</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $rangeReport['rejected_entries'] ?></div>
                    <div class="wr-report-metric-label">ڕەتکراوە/هەڵوەشاوە</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $rangeReport['avg_completion_minutes'] ?></div>
                    <div class="wr-report-metric-label">تێکڕای خولەکی تەواوبوون</div>
                </div>
                <div class="wr-report-metric">
                    <div class="wr-report-metric-value"><?= $rangeReport['in_progress_entries'] ?></div>
                    <div class="wr-report-metric-label">لە ژووری دکتۆردا</div>
                </div>
            </div>
        </div>
    </div>

    <div class="wr-report-table-wrap">
        <table class="wr-report-table">
            <thead>
                <tr>
                    <th>دکتۆر</th>
                    <th>ژوور</th>
                    <th>کۆی هاتنەکان</th>
                    <th>تەواوبوو</th>
                    <th>چاوەڕوان</th>
                    <th>لە ژووری دکتۆردا</th>
                    <th>پشکنین</th>
                    <th>ئیش</th>
                    <th>تێکڕای خولەک</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($monthDoctorReport)): ?>
                <tr>
                    <td colspan="9">هیچ زانیارییەک بۆ ڕاپۆرتی ئەم مانگە نییە</td>
                </tr>
                <?php else: ?>
                <?php foreach ($monthDoctorReport as $doctorReport): ?>
                <tr>
                    <td>د. <?= htmlspecialchars($doctorReport['doctor_name'] ?: 'نادیار') ?></td>
                    <td><?= htmlspecialchars($doctorReport['doctor_room'] ?: '-') ?></td>
                    <td><?= $doctorReport['total_entries'] ?></td>
                    <td><?= $doctorReport['completed_entries'] ?></td>
                    <td><?= $doctorReport['waiting_entries'] ?></td>
                    <td><?= $doctorReport['in_progress_entries'] ?></td>
                    <td><span class="wr-report-pill checkup"><?= $doctorReport['checkup_entries'] ?></span></td>
                    <td><span class="wr-report-pill treatment"><?= $doctorReport['treatment_entries'] ?></span></td>
                    <td><?= $doctorReport['avg_completion_minutes'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="wr-filter-bar">
    <div class="wr-filter-group">
        <label class="wr-filter-label"><i class="fas fa-calendar"></i> لە:</label>
        <input type="date" class="wr-filter-control" id="dateFromFilter" value="<?= $selectedDateFrom ?>"
               onchange="applyFilters()" style="min-width: 120px;">
    </div>

    <div class="wr-filter-group">
        <label class="wr-filter-label">تا:</label>
        <input type="date" class="wr-filter-control" id="dateToFilter" value="<?= $selectedDateTo ?>"
               onchange="applyFilters()" style="min-width: 120px;">
    </div>

    <?php if ($hasFullAccess): ?>
    <div class="wr-filter-group">
        <label class="wr-filter-label"><i class="fas fa-user-md"></i> دکتۆر:</label>
        <select class="wr-filter-control" id="doctorFilter" onchange="applyFilters()">
            <option value="">هەموو دکتۆرەکان</option>
            <?php foreach ($doctors as $doc): ?>
            <option value="<?= $doc['id'] ?>" <?= $selectedDoctor == $doc['id'] ? 'selected' : '' ?>>
                د. <?= htmlspecialchars($doc['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="wr-filter-group">
        <label class="wr-filter-label"><i class="fas fa-filter"></i> دۆخ:</label>
        <select class="wr-filter-control" id="statusFilter" onchange="applyFilters()">
            <option value="active" <?= $selectedStatus === 'active' ? 'selected' : '' ?>>چالاکەکان</option>
            <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>هەموو</option>
            <option value="waiting" <?= $selectedStatus === 'waiting' ? 'selected' : '' ?>>چاوەڕوان</option>
            <option value="in_progress" <?= $selectedStatus === 'in_progress' ? 'selected' : '' ?>>لە چارەسەردا</option>
            <option value="on_hold" <?= $selectedStatus === 'on_hold' ? 'selected' : '' ?>>وەستێنراو</option>
            <option value="at_accounting" <?= $selectedStatus === 'at_accounting' ? 'selected' : '' ?>>لە ژمێریاری</option>
            <option value="at_xray" <?= $selectedStatus === 'at_xray' ? 'selected' : '' ?>>لە ئێکسڕەی</option>
            <option value="completed" <?= $selectedStatus === 'completed' ? 'selected' : '' ?>>تەواوبوو</option>
            <option value="rejected" <?= $selectedStatus === 'rejected' ? 'selected' : '' ?>>ڕەتکراوە</option>
        </select>
    </div>

    <div class="wr-filter-group">
        <input type="text" class="wr-search-input" id="searchFilter"
               placeholder="گەڕان بە ناو یان مۆبایل..."
               oninput="filterCardsLocally()">
    </div>

    <div class="wr-filter-group" style="margin-right: auto;">
        <span style="font-size: 13px; color: #6b7280;">
            <i class="fas fa-hourglass-half"></i>
            تێکڕای چاوەڕوانی: <strong><?= $avgWait ?> خولەک</strong>
        </span>
    </div>
</div>

<?php
// Separate lists by status for display
$waitingPatients = array_filter($waitingList, fn($w) => $w['status'] === 'waiting');
$inProgressPatients = array_filter($waitingList, fn($w) => $w['status'] === 'in_progress');
$onHoldPatients = array_filter($waitingList, fn($w) => $w['status'] === 'on_hold');
$atStationPatients = array_filter($waitingList, fn($w) => in_array($w['status'], ['at_accounting', 'at_xray'], true));
$atAccountingPatients = array_filter($atStationPatients, fn($w) => $w['status'] === 'at_accounting');
$atXrayPatients = array_filter($atStationPatients, fn($w) => $w['status'] === 'at_xray');
$finishedPatients = array_filter($waitingList, fn($w) => in_array($w['status'], ['completed', 'rejected', 'cancelled']));

// X-ray queue info per patient: their next open request + position in the
// GLOBAL turn order (urgent first, then first-come-first-served) — the same
// order the x-ray room works through, so the call button and the numbers on
// the cards always agree with xray/index.php.
$xrayQueueInfo = [];
if (!empty($atXrayPatients)) {
    $queueRows = $db->fetchAll("
        SELECT id, patient_id, request_number, xray_type, priority, request_status, created_at
        FROM xray_requests
        WHERE request_status IN ('pending_xray', 'in_xray')
        ORDER BY (priority = 'urgent') DESC, created_at ASC");
    $queuePos = 0;
    foreach ($queueRows as $qr) {
        if ($qr['request_status'] === 'pending_xray') {
            $queuePos++;
            $qr['queue_pos'] = $queuePos;
        } else {
            $qr['queue_pos'] = null; // already inside the scan room
        }
        if (!isset($xrayQueueInfo[$qr['patient_id']])) {
            $xrayQueueInfo[$qr['patient_id']] = $qr;
        }
    }
    // Panel order mirrors the room: whoever is inside first, then by turn
    uasort($atXrayPatients, function ($a, $b) use ($xrayQueueInfo) {
        $qa = $xrayQueueInfo[$a['patient_id']] ?? null;
        $qb = $xrayQueueInfo[$b['patient_id']] ?? null;
        $ra = $qa ? (($qa['request_status'] === 'in_xray') ? 0 : $qa['queue_pos']) : 999;
        $rb = $qb ? (($qb['request_status'] === 'in_xray') ? 0 : $qb['queue_pos']) : 999;
        return $ra <=> $rb;
    });
}
$xrayTypeLabelsWr = [
    'panoramic' => 'پانۆراما', 'cbct' => 'CBCT',
    'periapical' => 'پێریئەپیکاڵ', 'cephalometric' => 'سیفالۆمەتریک'
];
$waitingCheckupCount = count(array_filter($waitingPatients, fn($w) => ($w['display_visit_type'] ?? 'treatment') === 'checkup'));
$waitingTreatmentCount = count($waitingPatients) - $waitingCheckupCount;
?>

<!-- Main Content -->
<div class="wr-main-grid">
    <!-- Waiting Column -->
    <div class="wr-panel" id="wrWaitingPanel">
        <div class="wr-panel-header waiting">
            <div class="wr-panel-title">
                <i class="fas fa-clock"></i>
                لە چاوەڕوانیدا
                <span class="wr-panel-badge"><?= count($waitingPatients) ?></span>
            </div>
            <div class="wr-panel-header-tools">
                <div class="wr-type-summary">
                    <span class="wr-type-count checkup"><i class="fas fa-stethoscope"></i> پشکنین <?= $waitingCheckupCount ?></span>
                    <span class="wr-type-count treatment"><i class="fas fa-briefcase-medical"></i> ئیش <?= $waitingTreatmentCount ?></span>
                </div>
                <div class="wr-waiting-type-filters">
                    <button type="button" class="wr-type-filter-btn active" data-waiting-filter="all" onclick="setWaitingVisitTypeFilter('all')">هەموو</button>
                    <button type="button" class="wr-type-filter-btn" data-waiting-filter="checkup" onclick="setWaitingVisitTypeFilter('checkup')">پشکنین</button>
                    <button type="button" class="wr-type-filter-btn" data-waiting-filter="treatment" onclick="setWaitingVisitTypeFilter('treatment')">ئیش</button>
                </div>
            </div>
        </div>
        <div class="wr-panel-body">
            <?php if (empty($waitingPatients)): ?>
            <div class="wr-empty-state">
                <i class="fas fa-chair"></i>
                <p>هیچ کەسێک لە چاوەڕوانیدا نییە</p>
            </div>
            <?php else: ?>
            <?php $queueNum = 1; foreach ($waitingPatients as $w):
                $waitMinutes = round((time() - strtotime($w['check_in_time'])) / 60);
                $waitClass = $waitMinutes > 45 ? 'long' : ($waitMinutes > 20 ? 'medium' : '');
                $priorityClass = ($w['priority'] ?? 0) >= 2 ? 'priority-high' : (($w['priority'] ?? 0) == 1 ? 'priority-medium' : '');
            ?>
            <div class="wr-patient-card clickable <?= $priorityClass ?>"
                 data-waiting-id="<?= $w['id'] ?>"
                 data-patient-id="<?= $w['patient_id'] ?>"
                 data-patient-name="<?= htmlspecialchars($w['full_name']) ?>"
                 data-patient-code="<?= htmlspecialchars($w['patient_code'] ?? '') ?>"
                 data-phone="<?= htmlspecialchars($w['phone'] ?? '') ?>"
                 data-gender="<?= htmlspecialchars($w['gender'] ?? '') ?>"
                 data-doctor-id="<?= $w['doctor_id'] ?? '' ?>"
                 data-doctor-name="<?= htmlspecialchars($w['doctor_name'] ?? '') ?>"
                 data-status="waiting"
                 data-priority="<?= $w['priority'] ?? 0 ?>"
                 data-check-in="<?= $w['check_in_time'] ?>"
                 data-arrival-time="<?= $w['check_in_time'] ?>"
                 data-room-entry-time=""
                  data-wait-minutes="<?= $waitMinutes ?>"
                  data-appointment-id="<?= $w['appointment_id'] ?? '' ?>"
                  data-edit-visit-id="<?= $w['edit_visit_id'] ?? '' ?>"
                  data-appointment-time="<?= $w['appointment_time'] ?? '' ?>"
                 data-appointment-type="<?= $w['appointment_type'] ?? '' ?>"
                 data-visit-type="<?= htmlspecialchars($w['display_visit_type'] ?? 'treatment') ?>"
                 data-work-type-id="<?= $w['display_work_type_id'] ?? '' ?>"
                 data-work-type-name="<?= htmlspecialchars($w['work_type_name'] ?? '') ?>"
                 data-notes="<?= htmlspecialchars($w['notes'] ?? '') ?>"
                 onclick="openDetailPanel(this)">
                <div class="wr-patient-top">
                    <div class="wr-queue-number waiting"><?= $queueNum++ ?></div>
                    <div class="wr-patient-info">
                        <div class="wr-patient-name">
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= $w['patient_id'] ?>" onclick="event.stopPropagation();">
                                <?= htmlspecialchars($w['full_name']) ?>
                            </a>
                            <?php if (($w['priority'] ?? 0) >= 2): ?>
                            <span class="wr-priority-badge high">فوری</span>
                            <?php elseif (($w['priority'] ?? 0) == 1): ?>
                            <span class="wr-priority-badge medium">گرنگ</span>
                            <?php endif; ?>
                        </div>
                        <div class="wr-patient-meta">
                            <span><i class="fas fa-user-md"></i> <?= ($w['doctor_role'] ?? '') === 'xray_department' ? 'ئێکسڕەی — ' . htmlspecialchars($w['doctor_name']) : (!empty($w['doctor_name']) ? 'د. ' . htmlspecialchars($w['doctor_name']) : 'تەنها ئێکسڕەی') ?><?php if (!empty($w['doctor_room'])): ?> <span style="background:#0ea5e9;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;margin-right:4px;"><i class="fas fa-door-open"></i> ژوور <?= htmlspecialchars($w['doctor_room']) ?><?= !empty($w['doctor_room_2']) ? ' / ' . htmlspecialchars($w['doctor_room_2']) : '' ?></span><?php endif; ?></span>
                            <?php $vt = $w['display_visit_type'] ?? 'treatment'; ?><span class="wr-visit-type-badge <?= $vt === 'checkup' ? 'checkup' : ($vt === 'xray' ? 'xray' : 'treatment') ?>"><i class="fas <?= $vt === 'checkup' ? 'fa-stethoscope' : ($vt === 'xray' ? 'fa-x-ray' : 'fa-briefcase-medical') ?>"></i> <?= $vt === 'checkup' ? 'پشکینین' : ($vt === 'xray' ? 'ئێکسڕەی' : 'ئیش') ?></span>
                            <?php if (!empty($w['work_type_name'])): ?>
                            <span><i class="fas fa-briefcase-medical"></i> <?= htmlspecialchars($w['work_type_name']) ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-clock"></i> کاتی هاتن: <?= date('h:i A', strtotime($w['check_in_time'])) ?></span>
                            <span class="wr-wait-time <?= $waitClass ?>">
                                <i class="fas fa-hourglass-half"></i> <?= $waitMinutes ?> خولەک
                            </span>
                        </div>
                    </div>
                </div>
                <div class="wr-patient-actions" onclick="event.stopPropagation();">
                    <?php $isXrayDest = empty($w['doctor_id']) || ($w['doctor_role'] ?? '') === 'xray_department'; ?>
                    <?php if (($isDoctorRole || $isAdmin) && !$isXrayDest): // «کەیس»: straight into the doctor's case for this patient ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="open_case">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-case" title="ڕاستەوخۆ بۆ چارتی ددانی کەیسەکە — ئەگەر نەبێت، دروست دەبێت">
                            <i class="fas fa-tooth"></i> کەیس
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if (!$isXrayDest): ?>
                    <?php if ($doctorMayCall): ?>
                    <form method="POST" style="display: inline;" data-doctor-id="<?= (int)($w['doctor_id'] ?? 0) ?>">
                        <input type="hidden" name="action" value="call">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <input type="hidden" name="appointment_id" value="<?= $w['appointment_id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-call">
                            <i class="fas fa-bullhorn"></i> بانگکردن
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php else: ?>
                    <a href="<?= APP_URL ?>/xray/request.php?patient_id=<?= $w['patient_id'] ?>" class="wr-btn wr-btn-xray" style="text-decoration: none;" title="داواکاری ئێکسڕەی">
                        <i class="fas fa-x-ray"></i> ناردن بۆ ئێکسڕەی
                    </a>
                    <?php endif; ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="hold">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-hold">
                            <i class="fas fa-pause"></i> وەستاندن
                        </button>
                    </form>
                    <button type="button" class="wr-btn wr-btn-priority" onclick="openPriorityDialog(<?= $w['id'] ?>, '<?= htmlspecialchars($w['full_name']) ?>')">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <?php if (!$isDoctorRole && !empty($w['edit_visit_id'])): // doctors always get the entry dialog, never the visit editor ?>
                    <a href="<?= APP_URL ?>/edit_visit.php?id=<?= (int)$w['edit_visit_id'] ?>" class="wr-btn wr-btn-edit" style="text-decoration: none;">
                        <i class="fas fa-edit"></i>
                    </a>
                    <?php else: ?>
                    <button type="button" class="wr-btn wr-btn-edit" onclick="openEditDialog(<?= $w['id'] ?>, <?= json_encode($w['full_name']) ?>, <?= (int)($w['doctor_id'] ?? 0) ?>, <?= json_encode($w['display_visit_type'] ?? 'treatment') ?>, <?= json_encode((string)($w['display_work_type_id'] ?? '')) ?>, <?= (int)($w['priority'] ?? 0) ?>, <?= json_encode($w['notes'] ?? '') ?>)">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="wr-btn wr-btn-reject" onclick="openRejectDialog(<?= $w['id'] ?>, <?= $w['appointment_id'] ?? 'null' ?>, '<?= htmlspecialchars($w['full_name']) ?>')">
                        <i class="fas fa-ban"></i>
                    </button>
                    <?php if ($hasFullAccess): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم تۆمارە بە یەکجاری؟');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-delete" title="سڕینەوە بە یەکجاری">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <div class="wr-filter-empty-state" id="wrWaitingFilterEmpty">
                <i class="fas fa-filter"></i>
                <p>هیچ نەخۆشێک بەم جۆرە نەدۆزرایەوە</p>
            </div>
        </div>
    </div>

    <!-- In Progress Column -->
    <div class="wr-panel" id="wrInProgressPanel">
        <div class="wr-panel-header in-progress">
            <div class="wr-panel-title">
                <i class="fas fa-user-md"></i>
                لە چارەسەردا
                <span class="wr-panel-badge"><?= count($inProgressPatients) ?></span>
            </div>
        </div>
        <div class="wr-panel-body">
            <?php if (empty($inProgressPatients)): ?>
            <div class="wr-empty-state">
                <i class="fas fa-stethoscope"></i>
                <p>هیچ کەسێک لە چارەسەردا نییە</p>
            </div>
            <?php else: ?>
            <?php foreach ($inProgressPatients as $w):
                $treatmentStartTime = $w['called_time'] ?: $w['check_in_time'];
                $treatmentMinutes = round((time() - strtotime($treatmentStartTime)) / 60);
            ?>
            <div class="wr-patient-card clickable"
                 data-waiting-id="<?= $w['id'] ?>"
                 data-patient-id="<?= $w['patient_id'] ?>"
                 data-patient-name="<?= htmlspecialchars($w['full_name']) ?>"
                 data-patient-code="<?= htmlspecialchars($w['patient_code'] ?? '') ?>"
                 data-phone="<?= htmlspecialchars($w['phone'] ?? '') ?>"
                 data-gender="<?= htmlspecialchars($w['gender'] ?? '') ?>"
                 data-doctor-id="<?= $w['doctor_id'] ?? '' ?>"
                 data-doctor-name="<?= htmlspecialchars($w['doctor_name'] ?? '') ?>"
                 data-status="in_progress"
                 data-priority="<?= $w['priority'] ?? 0 ?>"
                 data-check-in="<?= $w['check_in_time'] ?>"
                 data-arrival-time="<?= $w['check_in_time'] ?>"
                 data-room-entry-time="<?= $treatmentStartTime ?>"
                  data-wait-minutes="<?= $treatmentMinutes ?>"
                  data-appointment-id="<?= $w['appointment_id'] ?? '' ?>"
                  data-edit-visit-id="<?= $w['edit_visit_id'] ?? '' ?>"
                  data-appointment-time="<?= $w['appointment_time'] ?? '' ?>"
                 data-appointment-type="<?= $w['appointment_type'] ?? '' ?>"
                 data-visit-type="<?= htmlspecialchars($w['display_visit_type'] ?? 'treatment') ?>"
                 data-work-type-id="<?= $w['display_work_type_id'] ?? '' ?>"
                 data-work-type-name="<?= htmlspecialchars($w['work_type_name'] ?? '') ?>"
                 data-notes="<?= htmlspecialchars($w['notes'] ?? '') ?>"
                 onclick="openDetailPanel(this)">
                <div class="wr-patient-top">
                    <div class="wr-queue-number in_progress"><i class="fas fa-syringe"></i></div>
                    <div class="wr-patient-info">
                        <div class="wr-patient-name">
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= $w['patient_id'] ?>" onclick="event.stopPropagation();">
                                <?= htmlspecialchars($w['full_name']) ?>
                            </a>
                        </div>
                        <div class="wr-patient-meta">
                            <span><i class="fas fa-user-md"></i> <?= ($w['doctor_role'] ?? '') === 'xray_department' ? 'ئێکسڕەی — ' . htmlspecialchars($w['doctor_name']) : (!empty($w['doctor_name']) ? 'د. ' . htmlspecialchars($w['doctor_name']) : 'تەنها ئێکسڕەی') ?><?php $activeRoom = $w['room_name'] ?: $w['doctor_room']; if (!empty($activeRoom)): ?> <span style="background:#107C10;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;margin-right:4px;"><i class="fas fa-door-open"></i> ژوور <?= htmlspecialchars($activeRoom) ?></span><?php endif; ?></span>
                            <?php $vt = $w['display_visit_type'] ?? 'treatment'; ?><span class="wr-visit-type-badge <?= $vt === 'checkup' ? 'checkup' : ($vt === 'xray' ? 'xray' : 'treatment') ?>"><i class="fas <?= $vt === 'checkup' ? 'fa-stethoscope' : ($vt === 'xray' ? 'fa-x-ray' : 'fa-briefcase-medical') ?>"></i> <?= $vt === 'checkup' ? 'پشکینین' : ($vt === 'xray' ? 'ئێکسڕەی' : 'ئیش') ?></span>
                            <?php if (!empty($w['work_type_name'])): ?>
                            <span><i class="fas fa-briefcase-medical"></i> <?= htmlspecialchars($w['work_type_name']) ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-clock"></i> کاتی هاتن: <?= date('h:i A', strtotime($w['check_in_time'])) ?></span>
                            <span><i class="fas fa-sign-in-alt"></i> کاتی چوونەژوورەوە: <?= date('h:i A', strtotime($treatmentStartTime)) ?></span>
                            <span class="wr-wait-time">
                                <i class="fas fa-hourglass-half"></i> <?= $treatmentMinutes ?> خولەک
                            </span>
                            <?php if ($w['phone']): ?>
                            <span><i class="fas fa-phone"></i> <?= $w['phone'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="wr-patient-actions" onclick="event.stopPropagation();">
                    <?php if (($isDoctorRole || $isAdmin) && !empty($w['doctor_id']) && ($w['doctor_role'] ?? '') !== 'xray_department'): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="open_case">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-case" title="ڕاستەوخۆ بۆ چارتی ددانی کەیسەکە — ئەگەر نەبێت، دروست دەبێت">
                            <i class="fas fa-tooth"></i> کەیس
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <input type="hidden" name="appointment_id" value="<?= $w['appointment_id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-complete">
                            <i class="fas fa-check"></i> تەواوبوو
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="send_accounting">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-accounting" title="ناردن بۆ ژمێریاری بۆ پارەدان">
                            <i class="fas fa-cash-register"></i> بۆ ژمێریاری
                        </button>
                    </form>
                    <a href="<?= APP_URL ?>/xray/request.php?patient_id=<?= $w['patient_id'] ?>" class="wr-btn wr-btn-xray" style="text-decoration: none;" title="داواکاری ئێکسڕەی">
                        <i class="fas fa-x-ray"></i> ئێکسڕەی
                    </a>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="hold">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-hold">
                            <i class="fas fa-pause"></i> وەستاندن
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="return_waiting">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <input type="hidden" name="appointment_id" value="<?= $w['appointment_id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-return">
                            <i class="fas fa-undo"></i> گەڕاندنەوە
                        </button>
                    </form>
                    <?php if (!$isDoctorRole && !empty($w['edit_visit_id'])): // doctors always get the entry dialog, never the visit editor ?>
                    <a href="<?= APP_URL ?>/edit_visit.php?id=<?= (int)$w['edit_visit_id'] ?>" class="wr-btn wr-btn-edit" style="text-decoration: none;">
                        <i class="fas fa-edit"></i>
                    </a>
                    <?php else: ?>
                    <button type="button" class="wr-btn wr-btn-edit" onclick="openEditDialog(<?= $w['id'] ?>, <?= json_encode($w['full_name']) ?>, <?= (int)($w['doctor_id'] ?? 0) ?>, <?= json_encode($w['display_visit_type'] ?? 'treatment') ?>, <?= json_encode((string)($w['display_work_type_id'] ?? '')) ?>, <?= (int)($w['priority'] ?? 0) ?>, <?= json_encode($w['notes'] ?? '') ?>)">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasFullAccess): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم تۆمارە بە یەکجاری؟');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                        <button type="submit" class="wr-btn wr-btn-delete" title="سڕینەوە بە یەکجاری">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- On Hold Section -->
<?php if (!empty($onHoldPatients)): ?>
<div class="wr-panel" style="margin-top: 12px;">
    <div class="wr-panel-header on-hold">
        <div class="wr-panel-title">
            <i class="fas fa-pause-circle"></i>
            وەستێنراوەکان
            <span class="wr-panel-badge"><?= count($onHoldPatients) ?></span>
        </div>
    </div>
    <div class="wr-panel-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px;">
        <?php foreach ($onHoldPatients as $w):
            $waitMinutes = round((time() - strtotime($w['check_in_time'])) / 60);
        ?>
        <div class="wr-patient-card clickable"
             data-waiting-id="<?= $w['id'] ?>"
             data-patient-id="<?= $w['patient_id'] ?>"
             data-patient-name="<?= htmlspecialchars($w['full_name']) ?>"
             data-patient-code="<?= htmlspecialchars($w['patient_code'] ?? '') ?>"
             data-phone="<?= htmlspecialchars($w['phone'] ?? '') ?>"
             data-gender="<?= htmlspecialchars($w['gender'] ?? '') ?>"
             data-doctor-id="<?= $w['doctor_id'] ?? '' ?>"
             data-doctor-name="<?= htmlspecialchars($w['doctor_name'] ?? '') ?>"
             data-status="on_hold"
             data-priority="<?= $w['priority'] ?? 0 ?>"
             data-check-in="<?= $w['check_in_time'] ?>"
             data-arrival-time="<?= $w['check_in_time'] ?>"
             data-room-entry-time="<?= $w['called_time'] ?? '' ?>"
              data-wait-minutes="<?= $waitMinutes ?>"
              data-appointment-id="<?= $w['appointment_id'] ?? '' ?>"
              data-edit-visit-id="<?= $w['edit_visit_id'] ?? '' ?>"
              data-appointment-time="<?= $w['appointment_time'] ?? '' ?>"
             data-appointment-type="<?= $w['appointment_type'] ?? '' ?>"
             data-visit-type="<?= htmlspecialchars($w['display_visit_type'] ?? 'treatment') ?>"
             data-work-type-id="<?= $w['display_work_type_id'] ?? '' ?>"
             data-work-type-name="<?= htmlspecialchars($w['work_type_name'] ?? '') ?>"
             data-notes="<?= htmlspecialchars($w['notes'] ?? '') ?>"
             onclick="openDetailPanel(this)">
            <div class="wr-patient-top">
                <div class="wr-queue-number on_hold"><i class="fas fa-pause"></i></div>
                <div class="wr-patient-info">
                    <div class="wr-patient-name">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $w['patient_id'] ?>" onclick="event.stopPropagation();">
                            <?= htmlspecialchars($w['full_name']) ?>
                        </a>
                    </div>
                    <div class="wr-patient-meta">
                        <span><i class="fas fa-user-md"></i> <?= ($w['doctor_role'] ?? '') === 'xray_department' ? 'ئێکسڕەی — ' . htmlspecialchars($w['doctor_name']) : (!empty($w['doctor_name']) ? 'د. ' . htmlspecialchars($w['doctor_name']) : 'تەنها ئێکسڕەی') ?></span>
                        <?php $vt = $w['display_visit_type'] ?? 'treatment'; ?><span class="wr-visit-type-badge <?= $vt === 'checkup' ? 'checkup' : ($vt === 'xray' ? 'xray' : 'treatment') ?>"><i class="fas <?= $vt === 'checkup' ? 'fa-stethoscope' : ($vt === 'xray' ? 'fa-x-ray' : 'fa-briefcase-medical') ?>"></i> <?= $vt === 'checkup' ? 'پشکینین' : ($vt === 'xray' ? 'ئێکسڕەی' : 'ئیش') ?></span>
                        <?php if (!empty($w['work_type_name'])): ?>
                        <span><i class="fas fa-briefcase-medical"></i> <?= htmlspecialchars($w['work_type_name']) ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-clock"></i> کاتی هاتن: <?= date('h:i A', strtotime($w['check_in_time'])) ?></span>
                        <?php if (!empty($w['called_time'])): ?>
                        <span><i class="fas fa-sign-in-alt"></i> کاتی چوونەژوورەوە: <?= date('h:i A', strtotime($w['called_time'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="wr-patient-actions" onclick="event.stopPropagation();">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="resume">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-resume">
                        <i class="fas fa-play"></i> گەڕاندنەوە
                    </button>
                </form>
                <?php if ($doctorMayCall): ?>
                <form method="POST" style="display: inline;" data-doctor-id="<?= (int)($w['doctor_id'] ?? 0) ?>">
                    <input type="hidden" name="action" value="call">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <input type="hidden" name="appointment_id" value="<?= $w['appointment_id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-call">
                        <i class="fas fa-bullhorn"></i> بانگکردن
                    </button>
                </form>
                <?php endif; ?>
                <button type="button" class="wr-btn wr-btn-reject" onclick="openRejectDialog(<?= $w['id'] ?>, <?= $w['appointment_id'] ?? 'null' ?>, '<?= htmlspecialchars($w['full_name']) ?>')">
                    <i class="fas fa-ban"></i>
                </button>
                <?php if (!empty($w['edit_visit_id'])): ?>
                <a href="<?= APP_URL ?>/edit_visit.php?id=<?= (int)$w['edit_visit_id'] ?>" class="wr-btn wr-btn-edit" style="text-decoration: none;">
                    <i class="fas fa-edit"></i>
                </a>
                <?php else: ?>
                <button type="button" class="wr-btn wr-btn-edit" onclick="openEditDialog(<?= $w['id'] ?>, <?= json_encode($w['full_name']) ?>, <?= (int)($w['doctor_id'] ?? 0) ?>, <?= json_encode($w['display_visit_type'] ?? 'treatment') ?>, <?= json_encode((string)($w['display_work_type_id'] ?? '')) ?>, <?= (int)($w['priority'] ?? 0) ?>, <?= json_encode($w['notes'] ?? '') ?>)">
                    <i class="fas fa-edit"></i>
                </button>
                <?php endif; ?>
                <?php if ($hasFullAccess): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم تۆمارە بە یەکجاری؟');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-delete" title="سڕینەوە بە یەکجاری">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- X-Ray Queue Section: patients waiting to be CALLED into the scan room -->
<?php if (!empty($atXrayPatients)): ?>
<div class="wr-panel" style="margin-top: 12px;">
    <div class="wr-panel-header at-accounting" style="background:linear-gradient(135deg,#8764b8,#5c2d91);">
        <div class="wr-panel-title">
            <i class="fas fa-x-ray"></i>
            نۆرەی ئێکسڕەی
            <span class="wr-panel-badge"><?= count($atXrayPatients) ?></span>
        </div>
    </div>
    <div class="wr-panel-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px;">
        <?php foreach ($atXrayPatients as $w):
            $xq = $xrayQueueInfo[$w['patient_id']] ?? null;
            $inRoom = $xq && $xq['request_status'] === 'in_xray';
        ?>
        <div class="wr-patient-card clickable"
             data-waiting-id="<?= $w['id'] ?>"
             data-patient-id="<?= $w['patient_id'] ?>"
             data-patient-name="<?= htmlspecialchars($w['full_name']) ?>"
             data-patient-code="<?= htmlspecialchars($w['patient_code'] ?? '') ?>"
             data-phone="<?= htmlspecialchars($w['phone'] ?? '') ?>"
             data-gender="<?= htmlspecialchars($w['gender'] ?? '') ?>"
             data-doctor-id="<?= $w['doctor_id'] ?? '' ?>"
             data-doctor-name="<?= htmlspecialchars($w['doctor_name'] ?? '') ?>"
             data-status="<?= $w['status'] ?>"
             data-priority="<?= $w['priority'] ?? 0 ?>"
             data-check-in="<?= $w['check_in_time'] ?>"
             data-arrival-time="<?= $w['check_in_time'] ?>"
             data-room-entry-time="<?= $w['called_time'] ?? '' ?>"
              data-wait-minutes="<?= round((time() - strtotime($w['check_in_time'])) / 60) ?>"
              data-appointment-id="<?= $w['appointment_id'] ?? '' ?>"
              data-edit-visit-id="<?= $w['edit_visit_id'] ?? '' ?>"
              data-appointment-time="<?= $w['appointment_time'] ?? '' ?>"
             data-appointment-type="<?= $w['appointment_type'] ?? '' ?>"
             data-visit-type="<?= htmlspecialchars($w['display_visit_type'] ?? 'treatment') ?>"
             data-work-type-id="<?= $w['display_work_type_id'] ?? '' ?>"
             data-work-type-name="<?= htmlspecialchars($w['work_type_name'] ?? '') ?>"
             data-notes="<?= htmlspecialchars($w['notes'] ?? '') ?>"
             onclick="openDetailPanel(this)">
            <div class="wr-patient-top">
                <div class="wr-queue-number at_xray" title="<?= $inRoom ? 'لە ژوورەوەیە' : 'نۆرە' ?>">
                    <?php if ($inRoom): ?><i class="fas fa-door-open"></i>
                    <?php elseif ($xq && $xq['queue_pos']): ?><?= $xq['queue_pos'] ?>
                    <?php else: ?><i class="fas fa-x-ray"></i><?php endif; ?>
                </div>
                <div class="wr-patient-info">
                    <div class="wr-patient-name">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $w['patient_id'] ?>" onclick="event.stopPropagation();">
                            <?= htmlspecialchars($w['full_name']) ?>
                        </a>
                        <?php if ($inRoom): ?>
                        <span class="wr-priority-badge high"><i class="fas fa-door-open"></i> لە ژوورەوەیە</span>
                        <?php else: ?>
                        <span class="wr-priority-badge medium"><i class="fas fa-x-ray"></i> چاوەڕوانی نۆرە</span>
                        <?php endif; ?>
                        <?php if ($xq && $xq['priority'] === 'urgent'): ?>
                        <span class="wr-priority-badge high"><i class="fas fa-bolt"></i> پەلە</span>
                        <?php endif; ?>
                    </div>
                    <div class="wr-patient-meta">
                        <?php if ($xq): ?>
                        <span><i class="fas fa-x-ray"></i> <?= htmlspecialchars(($xrayTypeLabelsWr[$xq['xray_type']] ?? $xq['xray_type']) . ' — ' . $xq['request_number']) ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-user-md"></i> <?= ($w['doctor_role'] ?? '') === 'xray_department' ? 'ئێکسڕەی — ' . htmlspecialchars($w['doctor_name']) : (!empty($w['doctor_name']) ? 'د. ' . htmlspecialchars($w['doctor_name']) : 'تەنها ئێکسڕەی') ?></span>
                        <span><i class="fas fa-clock"></i> کاتی هاتن: <?= date('h:i A', strtotime($w['check_in_time'])) ?></span>
                    </div>
                </div>
            </div>
            <div class="wr-patient-actions" onclick="event.stopPropagation();">
                <?php if ($xq && !$inRoom && ($isXrayDept || $hasFullAccess)): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="call_xray">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-call" style="background:linear-gradient(135deg,#8764b8,#5c2d91);">
                        <i class="fas fa-bullhorn"></i> بانگکردن بۆ ژوورەوە
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($xq && ($isXrayDept || $isAdmin)): ?>
                <a href="<?= APP_URL ?>/xray/view.php?id=<?= (int)$xq['id'] ?>" class="wr-btn wr-btn-return" onclick="event.stopPropagation();">
                    <i class="fas fa-images"></i> <?= $inRoom ? 'بارکردن / تەواوکردن' : 'داواکارییەکە' ?>
                </a>
                <?php endif; ?>
                <?php if (!empty($w['doctor_id']) && ($w['doctor_role'] ?? '') !== 'xray_department'): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="return_doctor">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-call">
                        <i class="fas fa-undo"></i> گەڕاندنەوە بۆ دکتۆر
                    </button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <input type="hidden" name="appointment_id" value="<?= $w['appointment_id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-complete">
                        <i class="fas fa-check"></i> تەواوبوو
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- At Accounting Station Section -->
<?php if (!empty($atAccountingPatients)): ?>
<div class="wr-panel" style="margin-top: 12px;">
    <div class="wr-panel-header at-accounting">
        <div class="wr-panel-title">
            <i class="fas fa-cash-register"></i>
            لە ژمێریاری
            <span class="wr-panel-badge"><?= count($atAccountingPatients) ?></span>
        </div>
    </div>
    <div class="wr-panel-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px;">
        <?php foreach ($atAccountingPatients as $w): ?>
        <div class="wr-patient-card clickable"
             data-waiting-id="<?= $w['id'] ?>"
             data-patient-id="<?= $w['patient_id'] ?>"
             data-patient-name="<?= htmlspecialchars($w['full_name']) ?>"
             data-patient-code="<?= htmlspecialchars($w['patient_code'] ?? '') ?>"
             data-phone="<?= htmlspecialchars($w['phone'] ?? '') ?>"
             data-gender="<?= htmlspecialchars($w['gender'] ?? '') ?>"
             data-doctor-id="<?= $w['doctor_id'] ?? '' ?>"
             data-doctor-name="<?= htmlspecialchars($w['doctor_name'] ?? '') ?>"
             data-status="<?= $w['status'] ?>"
             data-priority="<?= $w['priority'] ?? 0 ?>"
             data-check-in="<?= $w['check_in_time'] ?>"
             data-arrival-time="<?= $w['check_in_time'] ?>"
             data-room-entry-time="<?= $w['called_time'] ?? '' ?>"
              data-wait-minutes="<?= round((time() - strtotime($w['check_in_time'])) / 60) ?>"
              data-appointment-id="<?= $w['appointment_id'] ?? '' ?>"
              data-edit-visit-id="<?= $w['edit_visit_id'] ?? '' ?>"
              data-appointment-time="<?= $w['appointment_time'] ?? '' ?>"
             data-appointment-type="<?= $w['appointment_type'] ?? '' ?>"
             data-visit-type="<?= htmlspecialchars($w['display_visit_type'] ?? 'treatment') ?>"
             data-work-type-id="<?= $w['display_work_type_id'] ?? '' ?>"
             data-work-type-name="<?= htmlspecialchars($w['work_type_name'] ?? '') ?>"
             data-notes="<?= htmlspecialchars($w['notes'] ?? '') ?>"
             onclick="openDetailPanel(this)">
            <div class="wr-patient-top">
                <div class="wr-queue-number at_accounting"><i class="fas fa-cash-register"></i></div>
                <div class="wr-patient-info">
                    <div class="wr-patient-name">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $w['patient_id'] ?>" onclick="event.stopPropagation();">
                            <?= htmlspecialchars($w['full_name']) ?>
                        </a>
                        <span class="wr-priority-badge medium"><i class="fas fa-cash-register"></i> لە ژمێریاری</span>
                    </div>
                    <div class="wr-patient-meta">
                        <span><i class="fas fa-user-md"></i> <?= ($w['doctor_role'] ?? '') === 'xray_department' ? 'ئێکسڕەی — ' . htmlspecialchars($w['doctor_name']) : (!empty($w['doctor_name']) ? 'د. ' . htmlspecialchars($w['doctor_name']) : 'تەنها ئێکسڕەی') ?></span>
                        <?php if (!empty($w['work_type_name'])): ?>
                        <span><i class="fas fa-briefcase-medical"></i> <?= htmlspecialchars($w['work_type_name']) ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-clock"></i> کاتی هاتن: <?= date('h:i A', strtotime($w['check_in_time'])) ?></span>
                    </div>
                </div>
            </div>
            <div class="wr-patient-actions" onclick="event.stopPropagation();">
                <?php if ($isAdmin || $auth->isAccountant()): ?>
                <a href="<?= APP_URL ?>/accountant/approval-center.php?tab=station" class="wr-btn wr-btn-return" onclick="event.stopPropagation();">
                    <i class="fas fa-cash-register"></i> وێستگەی ژمێریاری
                </a>
                <?php endif; ?>
                <?php if (!empty($w['doctor_id']) && ($w['doctor_role'] ?? '') !== 'xray_department'): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="return_doctor">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-call">
                        <i class="fas fa-undo"></i> گەڕاندنەوە بۆ دکتۆر
                    </button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="return_waiting">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-return">
                        <i class="fas fa-chair"></i> بۆ چاوەڕوانی
                    </button>
                </form>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                    <input type="hidden" name="appointment_id" value="<?= $w['appointment_id'] ?>">
                    <button type="submit" class="wr-btn wr-btn-complete">
                        <i class="fas fa-check"></i> تەواوبوو
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Completed/History Section -->
<?php if (!empty($finishedPatients)): ?>
<div class="wr-panel wr-completed-section">
    <div class="wr-panel-header completed">
        <div class="wr-panel-title">
            <i class="fas fa-history"></i>
            مێژووی ئەمڕۆ
            <span class="wr-panel-badge"><?= count($finishedPatients) ?></span>
        </div>
    </div>
    <div class="wr-panel-body" style="padding: 0;">
        <div class="table-container">
            <table class="wr-table">
                <thead>
                    <tr>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>کاتی هاتن</th>
                        <th>کاتی دەرچوون</th>
                        <th>ماوە</th>
                        <th>دۆخ</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($finishedPatients as $w): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= $w['patient_id'] ?>" style="color: #1f2937; text-decoration: none;">
                                <?= htmlspecialchars($w['full_name']) ?>
                            </a>
                        </td>
                        <td><?= ($w['doctor_role'] ?? '') === 'xray_department' ? 'ئێکسڕەی — ' . htmlspecialchars($w['doctor_name']) : (!empty($w['doctor_name']) ? 'د. ' . htmlspecialchars($w['doctor_name']) : 'تەنها ئێکسڕەی') ?><?php $rowRoom = $w['room_name'] ?: $w['doctor_room']; if (!empty($rowRoom)): ?> <span style="background:#0ea5e9;color:#fff;padding:1px 7px;border-radius:10px;font-size:10px;margin-right:4px;"><i class="fas fa-door-open"></i> ژوور <?= htmlspecialchars($rowRoom) ?></span><?php endif; ?></td>
                        <td><?= date('h:i A', strtotime($w['check_in_time'])) ?></td>
                        <td><?= $w['check_out_time'] ? date('h:i A', strtotime($w['check_out_time'])) : '-' ?></td>
                        <td>
                            <?php
                            if ($w['check_out_time']) {
                                $duration = round((strtotime($w['check_out_time']) - strtotime($w['check_in_time'])) / 60);
                                echo $duration . ' خولەک';
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($w['status'] === 'completed'): ?>
                            <span class="wr-status-badge completed"><i class="fas fa-check"></i> تەواوبوو</span>
                            <?php elseif ($w['status'] === 'rejected'): ?>
                            <span class="wr-status-badge rejected"><i class="fas fa-ban"></i> ڕەتکرایەوە</span>
                            <?php else: ?>
                            <span class="wr-status-badge cancelled"><i class="fas fa-times"></i> هەڵوەشایەوە</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isDoctorRole && !empty($w['edit_visit_id'])): // doctors always get the entry dialog, never the visit editor ?>
                            <a href="<?= APP_URL ?>/edit_visit.php?id=<?= (int)$w['edit_visit_id'] ?>" class="wr-btn wr-btn-edit" style="text-decoration: none;">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php else: ?>
                            <button type="button" class="wr-btn wr-btn-edit" onclick="openEditDialog(<?= $w['id'] ?>, <?= json_encode($w['full_name']) ?>, <?= (int)($w['doctor_id'] ?? 0) ?>, <?= json_encode($w['display_visit_type'] ?? 'treatment') ?>, <?= json_encode((string)($w['display_work_type_id'] ?? '')) ?>, <?= (int)($w['priority'] ?? 0) ?>, <?= json_encode($w['notes'] ?? '') ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($hasFullAccess): ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم تۆمارە بە یەکجاری؟');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="waiting_id" value="<?= $w['id'] ?>">
                                <button type="submit" class="wr-btn wr-btn-delete" title="سڕینەوە بە یەکجاری">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Walk-in Dialog -->
<div class="dialog-overlay" id="walkInDialog">
    <div class="dialog wr-dialog-modern">
        <div class="wr-dialog-header">
            <h4><i class="fas fa-user-plus"></i> زیادکردنی نەخۆش بۆ چاوەڕوانی</h4>
            <button type="button" class="wr-dialog-close" onclick="closeDialog('walkInDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="walkInForm">
            <input type="hidden" name="action" value="add_walk_in">
            <div class="wr-dialog-body">
                <div class="wr-form-grid">
                    <!-- Patient Search Box -->
                    <div class="wr-form-field">
                        <label class="wr-form-label"><span class="wr-required">*</span> نەخۆش</label>
                        <input type="hidden" name="patient_id" id="walkInPatientId" required>
                        <div class="wi-patient-search-row">
                            <div class="wi-patient-search-wrapper">
                                <i class="fas fa-search wi-search-icon"></i>
                                <input
                                    type="text"
                                    id="walkInPatientSearch"
                                    class="wr-form-control wi-search-input"
                                    placeholder="گەڕان بە ناو یان مۆبایل..."
                                    autocomplete="off"
                                    oninput="walkInSearchPatient(this.value)"
                                    onfocus="walkInSearchPatient(this.value)"
                                >
                                <button type="button" class="wi-clear-btn" id="walkInClearBtn" onclick="walkInClearPatient()" style="display:none;">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <button type="button" class="wi-add-patient-btn" id="walkInAddPatientBtn" onclick="openAddPatientDialog()" style="display:none;">
                                <i class="fas fa-plus"></i>
                                زیادکردن
                            </button>
                        </div>
                        <!-- Selected Patient Info Badge -->
                        <div id="walkInSelectedPatient" class="wi-selected-badge" style="display:none;">
                            <i class="fas fa-user-check"></i>
                            <span id="walkInSelectedName"></span>
                            <span id="walkInSelectedPhone" class="wi-badge-phone"></span>
                        </div>
                        <!-- Dropdown Results List -->
                        <div id="walkInPatientResults" class="wi-results-dropdown" style="display:none;">
                            <div id="walkInPatientList"></div>
                        </div>
                    </div>

                    <div class="wr-form-field">
                        <label class="wr-form-label"><span class="wr-required">*</span> دکتۆر</label>
                        <?php if ($isDoctorRole && !$isAdmin): // their own queue, their own name — nothing to choose ?>
                        <select name="doctor_id" class="wr-form-control" required>
                            <option value="<?= (int)$currentUser['id'] ?>"><?= htmlspecialchars($currentUser['full_name']) ?></option>
                        </select>
                        <?php else: ?>
                        <select name="doctor_id" class="wr-form-control" required
                                onchange="if (this.selectedOptions[0] && this.selectedOptions[0].dataset.xray) { this.form.querySelector('select[name=visit_type]').value = 'checkup'; }">
                            <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id'] ?>" <?= $doc['id'] == ($selectedDoctor ?: $currentUser['id']) ? 'selected' : '' ?>>
                                د. <?= htmlspecialchars($doc['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                            <?php foreach ($xrayAccounts as $xa): ?>
                            <option value="<?= $xa['id'] ?>" data-xray="1">ئێکسڕەی — <?= htmlspecialchars($xa['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="wr-form-field">
                        <label class="wr-form-label"><span class="wr-required">*</span> جۆری نەخۆش</label>
                        <select name="visit_type" class="wr-form-control" required>
                            <option value="checkup">پشکینین</option>
                            <option value="treatment" selected>ئیش</option>
                        </select>
                    </div>
                    <div class="wr-form-field">
                        <label class="wr-form-label">جۆری ئیش</label>
                        <div class="wr-work-type-combo">
                            <select name="work_type_id" id="walkInWorkTypeId" class="wr-form-control">
                                <option value="">هەڵبژێرە...</option>
                                <?php foreach ($workTypes as $workType): ?>
                                <option value="<?= $workType['id'] ?>"><?= htmlspecialchars($workType['work_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="wr-add-work-btn" onclick="openAddWorkTypeDialog()" title="زیادکردنی جۆری ئیشی نوێ">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="wr-form-field">
                        <label class="wr-form-label">ئەولەویەت</label>
                        <select name="priority" class="wr-form-control">
                            <option value="0">ئاسایی</option>
                            <option value="1">گرنگ</option>
                            <option value="2">فوری</option>
                        </select>
                    </div>
                    <div class="wr-form-field">
                        <label class="wr-form-label">تێبینی</label>
                        <textarea name="notes" class="wr-form-control" rows="2" placeholder="تێبینی سەبارەت بە سەردانەکە..."></textarea>
                    </div>
                </div>
            </div>
            <div class="wr-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('walkInDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-success" id="walkInSubmitBtn"><i class="fas fa-plus"></i> زیادکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Waiting Entry Dialog -->
<div class="dialog-overlay" id="editWaitingDialog">
    <div class="dialog wr-dialog-modern">
        <div class="wr-dialog-header" style="background: linear-gradient(135deg, #ffb300 0%, #fb8c00 100%);">
            <h4><i class="fas fa-edit"></i> دەستکاریی تۆماری چاوەڕوانی</h4>
            <button type="button" class="wr-dialog-close" onclick="closeDialog('editWaitingDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_waiting">
            <input type="hidden" name="waiting_id" id="editWaitingId">
            <div class="wr-dialog-body">
                <p style="margin-bottom: 16px; color: #374151;">
                    <strong id="editWaitingPatientName"></strong>
                </p>
                <div class="wr-form-grid">
                    <?php if ($hasFullAccess): ?>
                    <div class="wr-form-field">
                        <label class="wr-form-label">دکتۆر</label>
                        <select name="doctor_id" id="editDoctorId" class="wr-form-control" required>
                            <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id'] ?>">د. <?= htmlspecialchars($doc['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="doctor_id" id="editDoctorId" value="<?= (int)$currentUser['id'] ?>">
                    <?php endif; ?>
                    <div class="wr-form-field">
                        <label class="wr-form-label">جۆری سەردان</label>
                        <select name="visit_type" id="editVisitType" class="wr-form-control" required>
                            <option value="checkup">پشکینین</option>
                            <option value="treatment">ئیش</option>
                        </select>
                    </div>
                    <div class="wr-form-field">
                        <label class="wr-form-label">جۆری کار</label>
                        <div class="wr-work-type-combo">
                            <select name="work_type_id" id="editWorkTypeId" class="wr-form-control">
                                <option value="">هەڵبژێرە...</option>
                                <?php foreach ($workTypes as $workType): ?>
                                <option value="<?= $workType['id'] ?>"><?= htmlspecialchars($workType['work_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="wr-add-work-btn" onclick="openAddWorkTypeDialog('edit')" title="زیادکردنی جۆری کار">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="wr-form-field">
                        <label class="wr-form-label">گرنگی</label>
                        <select name="priority" id="editPriority" class="wr-form-control" required>
                            <option value="0">ئاسایی</option>
                            <option value="1">گرنگ</option>
                            <option value="2">فوری</option>
                        </select>
                    </div>
                    <div class="wr-form-field">
                        <label class="wr-form-label">تێبینی</label>
                        <textarea name="notes" id="editNotes" class="wr-form-control" rows="3" placeholder="تێبینی..."></textarea>
                    </div>
                </div>
            </div>
            <div class="wr-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('editWaitingDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردنی گۆڕانکاری</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Patient Dialog -->
<div class="dialog-overlay" id="addPatientDialog">
    <div class="dialog wr-dialog-modern wr-iframe-dialog">
        <div class="wr-dialog-header">
            <h4><i class="fas fa-user-plus"></i> زیادکردنی نەخۆشی نوێ</h4>
            <button type="button" class="wr-dialog-close" onclick="closeDialog('addPatientDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="wr-dialog-body wr-iframe-dialog-body">
            <iframe id="addPatientFrame" title="زیادکردنی نەخۆش" src="about:blank" loading="lazy"></iframe>
        </div>
    </div>
</div>

<!-- Add Work Type Dialog -->
<div class="dialog-overlay" id="addWorkTypeDialog">
    <div class="dialog wr-dialog-modern" style="max-width: 400px;">
        <div class="wr-dialog-header">
            <h4><i class="fas fa-briefcase-medical"></i> زیادکردنی جۆری ئیشی نوێ</h4>
            <button type="button" class="wr-dialog-close" onclick="closeDialog('addWorkTypeDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="wr-dialog-body">
            <div class="wr-form-field">
                <label class="wr-form-label">ناوی جۆری ئیش <span class="wr-required">*</span></label>
                <input type="text" id="newWorkTypeName" class="wr-form-control" placeholder="بۆ نموونە: تیشکی ددان">
                <div id="addWorkTypeError" class="wr-inline-error"></div>
            </div>
        </div>
        <div class="wr-dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('addWorkTypeDialog')">هەڵوەشاندنەوە</button>
            <button type="button" class="btn btn-primary" onclick="submitNewWorkType()"><i class="fas fa-check"></i> زیادکردن</button>
        </div>
    </div>
</div>
<!-- Reject Dialog -->
<div class="dialog-overlay" id="rejectDialog">
    <div class="dialog wr-dialog-modern">
        <div class="wr-dialog-header reject">
            <h4><i class="fas fa-ban"></i> ڕەتکردنەوەی نەخۆش</h4>
            <button type="button" class="wr-dialog-close" onclick="closeDialog('rejectDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="waiting_id" id="rejectWaitingId">
            <input type="hidden" name="appointment_id" id="rejectAppointmentId">
            <div class="wr-dialog-body">
                <p style="margin-bottom: 16px; color: #374151;">
                    <strong id="rejectPatientName"></strong> ڕەتدەکەیتەوە؟
                </p>
                <div class="wr-form-field">
                    <label class="wr-form-label">هۆکاری ڕەتکردنەوە</label>
                    <div class="wr-work-type-combo">
                    <select name="reject_reason" id="rejectReasonSelect" class="wr-form-control">
                        <option value="">هەڵبژێرە...</option>
                        <option value="نەخۆش نەهات">نەخۆش نەهات</option>
                        <option value="کاتی نەمابوو">کاتی نەمابوو</option>
                        <option value="داوای نەخۆش">داوای نەخۆش</option>
                        <option value="هۆکاری پزیشکی">هۆکاری پزیشکی</option>
                        <option value="هۆکاری تر">هۆکاری تر</option>
                        <?php foreach ($waitingRoomRejectReasons as $reason): ?>
                        <option value="<?= htmlspecialchars($reason['reason_key']) ?>"><?= htmlspecialchars($reason['reason_text']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($hasFullAccess): ?>
                    <button type="button" class="wr-add-work-btn" onclick="openAddRejectReasonDialog()" title="Add reject reason">
                        <i class="fas fa-plus"></i>
                    </button>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="wr-form-field" style="margin-top: 12px;">
                    <label class="wr-form-label">تێبینی زیاتر</label>
                    <textarea name="notes" class="wr-form-control" rows="2" placeholder="تێبینی ئارەزوومەندانە..."></textarea>
                </div>
            </div>
            <div class="wr-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('rejectDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-ban"></i> ڕەتکردنەوە</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Reject Reason Dialog -->
<div class="dialog-overlay" id="addRejectReasonDialog">
    <div class="dialog wr-dialog-modern" style="max-width: 400px;">
        <div class="wr-dialog-header reject">
            <h4><i class="fas fa-plus-circle"></i> Add Reject Reason</h4>
            <button type="button" class="wr-dialog-close" onclick="closeDialog('addRejectReasonDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="wr-dialog-body">
            <div class="wr-form-field">
                <label class="wr-form-label">Reason Name <span class="wr-required">*</span></label>
                <input type="text" id="newRejectReasonText" class="wr-form-control" placeholder="New reject reason">
                <div id="addRejectReasonError" class="wr-inline-error"></div>
            </div>
        </div>
        <div class="wr-dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('addRejectReasonDialog')">هەڵوەشاندنەوە</button>
            <button type="button" class="btn btn-danger" onclick="submitNewRejectReason()"><i class="fas fa-check"></i> Add</button>
        </div>
    </div>
</div>

<!-- Priority Dialog -->
<div class="dialog-overlay" id="priorityDialog">
    <div class="dialog wr-dialog-modern">
        <div class="wr-dialog-header" style="background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);">
            <h4><i class="fas fa-arrow-up"></i> دانانی ئەولەویەت</h4>
            <button type="button" class="wr-dialog-close" onclick="closeDialog('priorityDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="set_priority">
            <input type="hidden" name="waiting_id" id="priorityWaitingId">
            <div class="wr-dialog-body">
                <p style="margin-bottom: 16px; color: #374151;">
                    ئەولەویەت بۆ <strong id="priorityPatientName"></strong>
                </p>
                <div class="wr-form-field">
                    <label class="wr-form-label">ئاستی ئەولەویەت</label>
                    <select name="priority" class="wr-form-control" required>
                        <option value="0">ئاسایی</option>
                        <option value="1">گرنگ</option>
                        <option value="2">فوری</option>
                    </select>
                </div>
            </div>
            <div class="wr-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('priorityDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> پاشەکەوتکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Patient Detail Panel Overlay -->
<div class="wr-detail-overlay" id="detailOverlay" onclick="closeDetailPanel()"></div>

<!-- Patient Detail Panel -->
<div class="wr-detail-panel" id="detailPanel">
    <div class="wr-detail-header">
        <h3><i class="fas fa-user"></i> <span id="detailPatientName">زانیاری نەخۆش</span></h3>
        <button type="button" class="wr-detail-close" onclick="closeDetailPanel()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="wr-detail-body">
        <!-- Patient Info Section -->
        <div class="wr-detail-section">
            <div class="wr-detail-section-title">
                <i class="fas fa-id-card"></i> زانیاری نەخۆش
            </div>
            <div class="wr-detail-info-grid">
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">ناوی تەواو</div>
                    <div class="wr-detail-info-value" id="detailFullName">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">کۆدی نەخۆش</div>
                    <div class="wr-detail-info-value" id="detailPatientCode">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">ژمارەی مۆبایل</div>
                    <div class="wr-detail-info-value" id="detailPhone">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">ڕەگەز</div>
                    <div class="wr-detail-info-value" id="detailGender">-</div>
                </div>
            </div>
        </div>

        <!-- Waiting Info Section -->
        <div class="wr-detail-section">
            <div class="wr-detail-section-title">
                <i class="fas fa-clock"></i> زانیاری چاوەڕوانی
            </div>
            <div class="wr-detail-info-grid">
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">دۆخ</div>
                    <div class="wr-detail-info-value">
                        <span class="wr-detail-status" id="detailStatus">-</span>
                    </div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">ئەولەویەت</div>
                    <div class="wr-detail-info-value" id="detailPriority">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">جۆری نەخۆش</div>
                    <div class="wr-detail-info-value" id="detailVisitType">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">جۆری ئیش</div>
                    <div class="wr-detail-info-value" id="detailWorkType">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">کاتی هاتن</div>
                    <div class="wr-detail-info-value" id="detailCheckIn">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">کاتی چوونەژوورەوە</div>
                    <div class="wr-detail-info-value" id="detailRoomEntryTime">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">ماوەی چاوەڕوانی</div>
                    <div class="wr-detail-info-value" id="detailWaitTime">-</div>
                </div>
                <div class="wr-detail-info-item full-width">
                    <div class="wr-detail-info-label">دکتۆر</div>
                    <div class="wr-detail-info-value" id="detailDoctor">-</div>
                </div>
            </div>
        </div>

        <!-- Appointment Info Section -->
        <div class="wr-detail-section" id="appointmentSection" style="display: none;">
            <div class="wr-detail-section-title">
                <i class="fas fa-calendar-check"></i> زانیاری مەوعید
            </div>
            <div class="wr-detail-info-grid">
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">کاتی مەوعید</div>
                    <div class="wr-detail-info-value" id="detailAppointmentTime">-</div>
                </div>
                <div class="wr-detail-info-item">
                    <div class="wr-detail-info-label">جۆری مەوعید</div>
                    <div class="wr-detail-info-value" id="detailAppointmentType">-</div>
                </div>
            </div>
        </div>

        <!-- Notes Section -->
        <div class="wr-detail-section" id="notesSection" style="display: none;">
            <div class="wr-detail-section-title">
                <i class="fas fa-sticky-note"></i> تێبینی
            </div>
            <div class="wr-detail-info-grid">
                <div class="wr-detail-info-item full-width">
                    <div class="wr-detail-info-value" id="detailNotes" style="font-weight: normal; color: #4b5563;">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="wr-detail-actions" id="detailActions">
        <!-- Buttons will be dynamically populated based on status -->
    </div>
</div>

<script>
// Remove one-time action flags from the address bar after showing their alert once.
(function clearWaitingRoomFlashParams() {
    const flashParams = [
        'called',
        'completed',
        'held',
        'resumed',
        'returned',
        'rejected',
        'added',
        'error',
        'deleted',
        'updated',
        'cancelled',
        'priority_set'
    ];
    const url = new URL(window.location.href);
    let changed = false;

    flashParams.forEach(param => {
        if (url.searchParams.has(param)) {
            url.searchParams.delete(param);
            changed = true;
        }
    });

    if (changed) {
        const cleanQuery = url.searchParams.toString();
        window.history.replaceState(
            {},
            document.title,
            url.pathname + (cleanQuery ? '?' + cleanQuery : '') + url.hash
        );
    }
})();

// Currently selected card
let selectedCard = null;
let waitingVisitTypeFilter = 'all';
let activeWorkTypeTarget = 'walkin';

// Apply filters
function applyFilters() {
    const dateFrom = document.getElementById('dateFromFilter').value;
    const dateTo = document.getElementById('dateToFilter').value;
    const doctor = document.getElementById('doctorFilter')?.value || '';
    const status = document.getElementById('statusFilter').value;

    let url = '?date_from=' + dateFrom + '&date_to=' + dateTo;
    if (doctor) url += '&doctor=' + doctor;
    if (status) url += '&status=' + status;

    location.href = url;
}

function applyStatusFilter(status) {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.value = status;
    }
    applyFilters();
}

// Filter cards locally by search text
function filterCardsLocally() {
    const searchText = document.getElementById('searchFilter').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.wr-patient-card.clickable');

    cards.forEach(card => {
        const name = (card.dataset.patientName || '').toLowerCase();
        const phone = (card.dataset.phone || '').toLowerCase();
        const code = (card.dataset.patientCode || '').toLowerCase();
        const workType = (card.dataset.workTypeName || '').toLowerCase();
        const visitType = (card.dataset.visitType || '').toLowerCase();

        const matchesSearch = !searchText || name.includes(searchText) || phone.includes(searchText) || code.includes(searchText) || workType.includes(searchText) || visitType.includes(searchText);
        card.dataset.searchMatch = matchesSearch ? '1' : '0';
        card.style.display = matchesSearch ? '' : 'none';
    });

    applyWaitingVisitTypeFilter();
}

function setWaitingVisitTypeFilter(filter) {
    waitingVisitTypeFilter = filter || 'all';
    applyWaitingVisitTypeFilter();
}

function applyWaitingVisitTypeFilter() {
    const waitingPanel = document.getElementById('wrWaitingPanel');
    if (!waitingPanel) {
        return;
    }

    const cards = waitingPanel.querySelectorAll('.wr-patient-card.clickable');
    let visibleCount = 0;

    cards.forEach(card => {
        const matchesSearch = card.dataset.searchMatch !== '0';
        const matchesType = waitingVisitTypeFilter === 'all' || (card.dataset.visitType || 'treatment') === waitingVisitTypeFilter;
        const isVisible = matchesSearch && matchesType;

        card.style.display = isVisible ? '' : 'none';
        if (isVisible) {
            visibleCount++;
        }
    });

    waitingPanel.querySelectorAll('[data-waiting-filter]').forEach(button => {
        button.classList.toggle('active', button.dataset.waitingFilter === waitingVisitTypeFilter);
    });

    const emptyState = document.getElementById('wrWaitingFilterEmpty');
    if (emptyState) {
        emptyState.style.display = cards.length > 0 && visibleCount === 0 ? 'block' : 'none';
    }
}

// Open detail panel for a patient card
function openDetailPanel(cardElement) {
    // Deselect previous card
    if (selectedCard) {
        selectedCard.classList.remove('selected');
    }

    // Select new card
    selectedCard = cardElement;
    cardElement.classList.add('selected');

    // Get data from card
    const data = cardElement.dataset;
    const arrivalTime = data.arrivalTime || data.checkIn || '';
    const roomEntryTime = data.roomEntryTime || '';

    // Populate detail panel
    document.getElementById('detailPatientName').textContent = data.patientName;
    document.getElementById('detailFullName').textContent = data.patientName || '-';
    document.getElementById('detailPatientCode').textContent = data.patientCode || '-';
    document.getElementById('detailPhone').textContent = data.phone || '-';
    document.getElementById('detailGender').textContent = getGenderLabel(data.gender);
    document.getElementById('detailDoctor').textContent = 'د. ' + (data.doctorName || '-');
    document.getElementById('detailCheckIn').textContent = formatTime(arrivalTime);
    const detailRoomEntryTime = document.getElementById('detailRoomEntryTime');
    if (detailRoomEntryTime) {
        detailRoomEntryTime.textContent = formatTime(roomEntryTime);
    }
    document.getElementById('detailWaitTime').textContent = data.waitMinutes + ' خولەک';
    document.getElementById('detailPriority').textContent = getPriorityLabel(data.priority);
    document.getElementById('detailVisitType').textContent = getAppointmentTypeLabel(data.visitType || data.appointmentType || 'treatment');
    document.getElementById('detailWorkType').textContent = data.workTypeName || '-';

    // Set status with styling
    const statusEl = document.getElementById('detailStatus');
    statusEl.textContent = getStatusLabel(data.status);
    statusEl.className = 'wr-detail-status ' + data.status;

    // Show/hide appointment section
    const appointmentSection = document.getElementById('appointmentSection');
    if (data.appointmentTime) {
        appointmentSection.style.display = '';
        document.getElementById('detailAppointmentTime').textContent = formatTime(data.appointmentTime);
        document.getElementById('detailAppointmentType').textContent = getAppointmentTypeLabel(data.appointmentType);
    } else {
        appointmentSection.style.display = 'none';
    }

    // Show/hide notes section
    const notesSection = document.getElementById('notesSection');
    if (data.notes) {
        notesSection.style.display = '';
        document.getElementById('detailNotes').textContent = data.notes;
    } else {
        notesSection.style.display = 'none';
    }

    // Build action buttons based on status
    buildActionButtons(data);

    // Open panel
    document.getElementById('detailPanel').classList.add('open');
    document.getElementById('detailOverlay').classList.add('open');
}

// Close detail panel
function closeDetailPanel() {
    document.getElementById('detailPanel').classList.remove('open');
    document.getElementById('detailOverlay').classList.remove('open');

    // Deselect card
    if (selectedCard) {
        selectedCard.classList.remove('selected');
        selectedCard = null;
    }
}

// Build action buttons based on patient status
function buildActionButtons(data) {
    const actionsContainer = document.getElementById('detailActions');
    let html = '';

    // View patient profile button (always shown)
    html += `<a href="<?= APP_URL ?>/patients/view.php?id=${data.patientId}" class="wr-detail-btn wr-detail-btn-view">
        <i class="fas fa-user"></i> پڕۆفایلی نەخۆش
    </a>`;

    if (data.status === 'waiting') {
        html += `<form method="POST" style="display:inline;" data-doctor-id="${data.doctorId || 0}">
            <input type="hidden" name="action" value="call">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-call">
                <i class="fas fa-bullhorn"></i> بانگکردن
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="hold">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-hold">
                <i class="fas fa-pause"></i> وەستاندن
            </button>
        </form>`;
        html += `<button type="button" class="wr-detail-btn wr-detail-btn-reject" onclick="closeDetailPanel(); openRejectDialog(${data.waitingId}, ${data.appointmentId || 'null'}, '${data.patientName}')">
            <i class="fas fa-ban"></i> ڕەتکردنەوە
        </button>`;
    } else if (data.status === 'in_progress') {
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-complete">
                <i class="fas fa-check"></i> تەواوبوو
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="hold">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-hold">
                <i class="fas fa-pause"></i> وەستاندن
            </button>
        </form>`;
    } else if (data.status === 'on_hold') {
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="resume">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-call">
                <i class="fas fa-play"></i> گەڕاندنەوە
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;" data-doctor-id="${data.doctorId || 0}">
            <input type="hidden" name="action" value="call">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-complete">
                <i class="fas fa-bullhorn"></i> بانگکردن
            </button>
        </form>`;
        html += `<button type="button" class="wr-detail-btn wr-detail-btn-reject" onclick="closeDetailPanel(); openRejectDialog(${data.waitingId}, ${data.appointmentId || 'null'}, '${data.patientName}')">
            <i class="fas fa-ban"></i> ڕەتکردنەوە
        </button>`;
    }

    <?php if ($hasFullAccess): ?>
    html += `<form method="POST" style="display:inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم تۆمارە بە یەکجاری؟');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="waiting_id" value="${data.waitingId}">
        <button type="submit" class="wr-detail-btn wr-detail-btn-delete">
            <i class="fas fa-trash"></i> سڕینەوە
        </button>
    </form>`;
    <?php endif; ?>

    actionsContainer.innerHTML = html;
}

function buildActionButtons(data) {
    const actionsContainer = document.getElementById('detailActions');
    const patientNameArg = encodeURIComponent(data.patientName || '');
    const notesArg = encodeURIComponent(data.notes || '');
    const visitType = data.visitType || 'treatment';
    const workTypeId = data.workTypeId || '';
    const doctorId = data.doctorId || 0;
    const priority = parseInt(data.priority || 0, 10);
    const editVisitId = parseInt(data.editVisitId || 0, 10);
    let html = '';

    html += `<a href="<?= APP_URL ?>/patients/view.php?id=${data.patientId}" class="wr-detail-btn wr-detail-btn-view">
        <i class="fas fa-user"></i> پڕۆفایلی نەخۆش
    </a>`;

    <?php if ($auth->isDoctor() && !$isAdmin): ?>
    // Doctor: open the patient profile straight on the "new case" dialog so the
    // doctor can set up a case for a patient reception routed to them.
    html += `<a href="<?= APP_URL ?>/patients/view.php?id=${data.patientId}&new_case=1" class="wr-detail-btn wr-detail-btn-complete">
        <i class="fas fa-briefcase-medical"></i> کەیس دانان
    </a>`;
    <?php endif; ?>

    const editButton = (!IS_DOCTOR_ROLE && editVisitId > 0)
        ? `<a href="<?= APP_URL ?>/edit_visit.php?id=${editVisitId}" class="wr-detail-btn wr-detail-btn-edit">
            <i class="fas fa-edit"></i> دەستکاری
        </a>`
        : `<button type="button" class="wr-detail-btn wr-detail-btn-edit" onclick="closeDetailPanel(); openEditDialogFromEncoded(${data.waitingId}, '${patientNameArg}', ${doctorId}, '${visitType}', '${workTypeId}', ${priority}, '${notesArg}')">
            <i class="fas fa-edit"></i> دەستکاری
        </button>`;

    if (data.status === 'waiting') {
        html += `<form method="POST" style="display:inline;" data-doctor-id="${data.doctorId || 0}">
            <input type="hidden" name="action" value="call">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-call">
                <i class="fas fa-bullhorn"></i> بانگکردن
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="hold">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-hold">
                <i class="fas fa-pause"></i> وەستاندن
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="return_waiting">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-return">
                <i class="fas fa-undo"></i> گەڕاندنەوە
            </button>
        </form>`;
        html += editButton;
        html += `<button type="button" class="wr-detail-btn wr-detail-btn-reject" onclick="closeDetailPanel(); openRejectDialog(${data.waitingId}, ${data.appointmentId || 'null'}, decodeInlineArg('${patientNameArg}'))">
            <i class="fas fa-ban"></i> ڕەتکردنەوە
        </button>`;
    } else if (data.status === 'in_progress') {
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-complete">
                <i class="fas fa-check"></i> تەواوبوو
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="send_accounting">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-call">
                <i class="fas fa-cash-register"></i> بۆ ژمێریاری
            </button>
        </form>`;
        html += `<a href="<?= APP_URL ?>/xray/request.php?patient_id=${data.patientId}" class="wr-detail-btn wr-detail-btn-view">
            <i class="fas fa-x-ray"></i> ئێکسڕەی
        </a>`;
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="hold">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-hold">
                <i class="fas fa-pause"></i> وەستاندن
            </button>
        </form>`;
        html += editButton;
    } else if (data.status === 'at_accounting' || data.status === 'at_xray') {
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="return_doctor">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-call">
                <i class="fas fa-undo"></i> گەڕاندنەوە بۆ دکتۆر
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-complete">
                <i class="fas fa-check"></i> تەواوبوو
            </button>
        </form>`;
        html += editButton;
    } else if (data.status === 'on_hold') {
        html += `<form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="resume">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-call">
                <i class="fas fa-play"></i> گەڕاندنەوە
            </button>
        </form>`;
        html += `<form method="POST" style="display:inline;" data-doctor-id="${data.doctorId || 0}">
            <input type="hidden" name="action" value="call">
            <input type="hidden" name="waiting_id" value="${data.waitingId}">
            <input type="hidden" name="appointment_id" value="${data.appointmentId}">
            <button type="submit" class="wr-detail-btn wr-detail-btn-complete">
                <i class="fas fa-bullhorn"></i> بانگکردن
            </button>
        </form>`;
        html += editButton;
        html += `<button type="button" class="wr-detail-btn wr-detail-btn-reject" onclick="closeDetailPanel(); openRejectDialog(${data.waitingId}, ${data.appointmentId || 'null'}, decodeInlineArg('${patientNameArg}'))">
            <i class="fas fa-ban"></i> ڕەتکردنەوە
        </button>`;
    }

    <?php if ($hasFullAccess): ?>
    html += `<form method="POST" style="display:inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم تۆمارە بە یەکجاری؟');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="waiting_id" value="${data.waitingId}">
        <button type="submit" class="wr-detail-btn wr-detail-btn-delete">
            <i class="fas fa-trash"></i> سڕینەوە
        </button>
    </form>`;
    <?php endif; ?>

    actionsContainer.innerHTML = html;
    hideDoctorDisallowed(actionsContainer);
}

// Doctor waiting-room lock (UI side): a doctor may call in, complete, and send
// to accounting. Every other queue action is hidden. The server enforces the
// same allow-list ($doctorWaitingActions) plus the per-entry ownership check,
// so this only keeps the doctor from seeing buttons that would 403 — it is not
// the security boundary.
//
// Keep this list in step with $doctorWaitingActions at the top of this file.
// 'edit_waiting' and 'add_walk_in' are absent on purpose: they are not row
// buttons, they are reached through their own dialogs.
const IS_DOCTOR_ROLE = <?= $isDoctorRole ? 'true' : 'false' ?>;
// 'call' drops out when the admin limited calling to other doctors ($doctorMayCall).
const WR_ALLOWED_DOCTOR_ACTIONS = <?= json_encode(array_values(array_intersect(['call', 'complete', 'send_accounting'], $doctorWaitingActions))) ?>;
function hideDoctorDisallowed(root) {
    if (!IS_DOCTOR_ROLE || !root) return;
    // Forms carry a hidden action input — hide any that isn't allowed.
    root.querySelectorAll('form').forEach(function (f) {
        const a = f.querySelector('input[name="action"]');
        if (a && WR_ALLOWED_DOCTOR_ACTIONS.indexOf(a.value) === -1) {
            f.style.display = 'none';
        }
    });
    // Priority / reject are never allowed for a doctor.
    root.querySelectorAll('.wr-btn-priority, .wr-btn-reject, .wr-detail-btn-reject')
        .forEach(function (b) { b.style.display = 'none'; });
    // Edit: KEEP the "edit waiting entry" dialog button (a <button>), but hide any
    // "edit visit" LINK (an <a> — reception only). Doctors are rendered the button
    // form anyway, so this is just belt-and-suspenders.
    root.querySelectorAll('a.wr-btn-edit, a.wr-detail-btn-edit')
        .forEach(function (b) { b.style.display = 'none'; });
}
document.addEventListener('DOMContentLoaded', function () {
    if (IS_DOCTOR_ROLE) {
        document.querySelectorAll('.wr-patient-actions').forEach(hideDoctorDisallowed);
    }
});

// Helper functions
function getStatusLabel(status) {
    const labels = {
        'waiting': 'چاوەڕوان',
        'in_progress': 'لە چارەسەردا',
        'on_hold': 'وەستێنراو',
        'at_accounting': 'لە ژمێریاری',
        'at_xray': 'لە ئێکسڕەی',
        'completed': 'تەواوبوو',
        'rejected': 'ڕەتکراوە',
        'cancelled': 'هەڵوەشاوە'
    };
    return labels[status] || status;
}

function getPriorityLabel(priority) {
    const p = parseInt(priority);
    if (p >= 2) return 'فوری';
    if (p == 1) return 'گرنگ';
    return 'ئاسایی';
}

function getGenderLabel(gender) {
    if (gender === 'male') return 'نێر';
    if (gender === 'female') return 'مێ';
    return '-';
}

function getAppointmentTypeLabel(type) {
    const labels = {
        'checkup': 'پشکنین',
        'treatment': 'ئیش',
        'xray': 'ئێکسڕەی',
        'followup': 'بەدوادادینەوە',
        'consultation': 'ڕاوێژکاری',
        'emergency': 'فوری'
    };
    return labels[type] || type || '-';
}

function formatTime(datetime) {
    if (!datetime) return '-';
    // Extract time portion if full datetime, then show 12-hour time.
    let rawTime = datetime;
    if (datetime.includes(' ')) {
        const parts = datetime.split(' ');
        rawTime = parts[1];
    }

    const timeParts = rawTime.split(':');
    let hours = parseInt(timeParts[0], 10);
    const minutes = (timeParts[1] || '00').padStart(2, '0');
    if (Number.isNaN(hours)) return '-';

    const suffix = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    if (hours === 0) hours = 12;

    return String(hours).padStart(2, '0') + ':' + minutes + ' ' + suffix;
}

// Open reject dialog
function openRejectDialog(waitingId, appointmentId, patientName) {
    document.getElementById('rejectWaitingId').value = waitingId;
    document.getElementById('rejectAppointmentId').value = appointmentId || '';
    document.getElementById('rejectPatientName').textContent = patientName;
    const rejectReasonSelect = document.getElementById('rejectReasonSelect');
    if (rejectReasonSelect) {
        rejectReasonSelect.value = '';
    }
    loadRejectReasonOptions();
    openDialog('rejectDialog');
}

// Open priority dialog
function openPriorityDialog(waitingId, patientName) {
    document.getElementById('priorityWaitingId').value = waitingId;
    document.getElementById('priorityPatientName').textContent = patientName;
    openDialog('priorityDialog');
}

function escapeJsString(value) {
    return String(value ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/\r/g, '\\r')
        .replace(/\n/g, '\\n');
}

function decodeInlineArg(value) {
    return decodeURIComponent(value || '');
}

function openEditDialogFromEncoded(waitingId, patientName, doctorId, visitType, workTypeId, priority, notes) {
    openEditDialog(
        waitingId,
        decodeInlineArg(patientName),
        doctorId,
        visitType,
        workTypeId,
        priority,
        decodeInlineArg(notes)
    );
}

function openEditDialog(waitingId, patientName, doctorId, visitType, workTypeId, priority, notes) {
    document.getElementById('editWaitingId').value = waitingId;
    document.getElementById('editWaitingPatientName').textContent = patientName;
    const doctorEl = document.getElementById('editDoctorId');
    if (doctorEl) {
        doctorEl.value = doctorId ? String(doctorId) : doctorEl.value;
    }
    document.getElementById('editVisitType').value = visitType || 'treatment';
    document.getElementById('editWorkTypeId').value = workTypeId ? String(workTypeId) : '';
    document.getElementById('editPriority').value = String(priority ?? 0);
    document.getElementById('editNotes').value = notes || '';
    openDialog('editWaitingDialog');
}

function openAddRejectReasonDialog() {
    const input = document.getElementById('newRejectReasonText');
    const err = document.getElementById('addRejectReasonError');

    if (input) {
        input.value = '';
    }

    if (err) {
        err.textContent = '';
        err.style.display = 'none';
    }

    openDialog('addRejectReasonDialog');

    setTimeout(() => {
        if (input) {
            input.focus();
        }
    }, 50);
}

function openAddWorkTypeDialog(target = 'walkin') {
    activeWorkTypeTarget = target;
    const input = document.getElementById('newWorkTypeName');
    const err = document.getElementById('addWorkTypeError');

    if (input) {
        input.value = '';
    }

    if (err) {
        err.textContent = '';
        err.style.display = 'none';
    }

    openDialog('addWorkTypeDialog');

    setTimeout(() => {
        if (input) {
            input.focus();
        }
    }, 50);
}

function submitNewWorkType() {
    const input = document.getElementById('newWorkTypeName');
    const err = document.getElementById('addWorkTypeError');
    const name = (input.value || '').trim();

    if (err) {
        err.textContent = '';
        err.style.display = 'none';
    }

    if (!name) {
        if (err) {
            err.textContent = 'ناوی جۆری ئیش پێویستە';
            err.style.display = 'block';
        }
        return;
    }

    fetch('<?= APP_URL ?>/api/work-types.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', work_name: name, scope: 'waiting_room' })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            if (err) {
                err.textContent = data.message || 'هەڵەیەک ڕوویدا';
                err.style.display = 'block';
            }
            return;
        }

        ['walkInWorkTypeId', 'editWorkTypeId'].forEach(selectId => {
            const sel = document.getElementById(selectId);
            if (!sel) {
                return;
            }
            const existingOption = Array.from(sel.options).find(option => option.value === String(data.id));
            if (!existingOption) {
                const opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.work_name;
                sel.appendChild(opt);
            }
        });

        const targetSelectId = activeWorkTypeTarget === 'edit' ? 'editWorkTypeId' : 'walkInWorkTypeId';
        const targetSelect = document.getElementById(targetSelectId);
        if (targetSelect) {
            targetSelect.value = String(data.id);
        }

        closeDialog('addWorkTypeDialog');
    })
    .catch(e => {
        if (err) {
            err.textContent = 'هەڵەی پەیوەندی: ' + e.message;
            err.style.display = 'block';
        }
    });
}

function submitNewRejectReason() {
    const input = document.getElementById('newRejectReasonText');
    const err = document.getElementById('addRejectReasonError');
    const reasonText = (input?.value || '').trim();

    if (err) {
        err.textContent = '';
        err.style.display = 'none';
    }

    if (!reasonText) {
        if (err) {
            err.textContent = 'Reason name is required';
            err.style.display = 'block';
        }
        return;
    }

    fetch('<?= APP_URL ?>/api/waiting-room-rejection-reasons.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', reason_text: reasonText })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            if (err) {
                err.textContent = data.message || 'Failed to add reason';
                err.style.display = 'block';
            }
            return;
        }

        loadRejectReasonOptions(data.reason_key);

        closeDialog('addRejectReasonDialog');
    })
    .catch(e => {
        if (err) {
            err.textContent = 'Connection error: ' + e.message;
            err.style.display = 'block';
        }
    });
}

function loadRejectReasonOptions(selectedKey = '') {
    const rejectReasonSelect = document.getElementById('rejectReasonSelect');
    if (!rejectReasonSelect) {
        return;
    }

    fetch('<?= APP_URL ?>/api/waiting-room-rejection-reasons.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list' })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !Array.isArray(data.reasons)) {
            return;
        }

        rejectReasonSelect.innerHTML = '<option value="">ظ‡غ•عµط¨عکغژط±غ•...</option>';
        data.reasons.forEach(reason => {
            const opt = document.createElement('option');
            opt.value = reason.reason_key;
            opt.textContent = reason.reason_text;
            if (selectedKey && selectedKey === reason.reason_key) {
                opt.selected = true;
            }
            rejectReasonSelect.appendChild(opt);
        });
    })
    .catch(() => {
        // Keep current options if loading reasons fails.
    });
}

/*
function refreshWaitingRoomPanels() {
    const waitingPanel = document.getElementById('wrWaitingPanel');
    const inProgressPanel = document.getElementById('wrInProgressPanel');

    if (!waitingPanel || !inProgressPanel) {
        return;
    }

    fetch(window.location.href, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const nextWaitingPanel = doc.getElementById('wrWaitingPanel');
            const nextInProgressPanel = doc.getElementById('wrInProgressPanel');

            if (!nextWaitingPanel || !nextInProgressPanel) {
                return;
            }

            waitingPanel.outerHTML = nextWaitingPanel.outerHTML;
            inProgressPanel.outerHTML = nextInProgressPanel.outerHTML;

            if (typeof filterCardsLocally === 'function') {
                filterCardsLocally();
            }
        })
        .catch(() => {
            // Keep current UI state if the background refresh fails.
        });
}

// ===== Walk-In Patient Search =====
let walkInSearchTimer = null;
let walkInCurrentQuery = '';

function walkInSearchPatient(val) {
    const query = val.trim();
    walkInCurrentQuery = query;

    const resultsBox = document.getElementById('walkInPatientResults');
    const listEl    = document.getElementById('walkInPatientList');
    const clearBtn  = document.getElementById('walkInClearBtn');
    const patientId = document.getElementById('walkInPatientId').value;

    // Show clear button whenever there is text
    clearBtn.style.display = query.length > 0 ? 'block' : 'none';

    // If a patient is already selected and user types again → clear selection
    if (patientId) {
        walkInClearPatient(false);
    }

    // Hide dropdown if query too short
    if (query.length < 1) {
        walkInHideResults();
        return;
    }

    // Show loading
    listEl.innerHTML = '<div class="wi-searching"><i class="fas fa-circle-notch fa-spin"></i> گەڕان...</div>';
    resultsBox.style.display = 'block';

    clearTimeout(walkInSearchTimer);
    walkInSearchTimer = setTimeout(() => {
        if (query.length < 1) { walkInHideResults(); return; }

        fetch(`<?= APP_URL ?>/api/search.php?type=patients&q=` + encodeURIComponent(query))
            .then(r => r.json())
            .then(json => {
                if (walkInCurrentQuery !== query) return; // stale response
                if (!json.success || !json.data || json.data.length === 0) {
                    listEl.innerHTML = '<div class="wi-no-results"><i class="fas fa-search"></i> هیچ نەخۆشێک نەدۆزرایەوە</div>';
                    resultsBox.style.display = 'block';
                    return;
                }
                let html = '';
                json.data.forEach(p => {
                    const initials = p.full_name.trim().split(' ').map(w => w[0]).slice(0,2).join('');
                    const phone    = p.phone    ? `<span><i class="fas fa-phone" style="font-size:10px;"></i> ${p.phone}</span>` : '';
                    const code     = p.patient_code ? `<span class="wi-result-code">${p.patient_code}</span>` : '';
                    html += `<div class="wi-result-item" tabindex="0"
                                  data-id="${p.id}"
                                  data-name="${p.full_name.replace(/"/g,'&quot;')}"
                                  data-phone="${(p.phone||'').replace(/"/g,'&quot;')}"
                                  onclick="walkInSelectPatient(this)"
                                  onkeydown="if(event.key==='Enter') walkInSelectPatient(this)">
                               <div class="wi-result-avatar">${initials}</div>
                               <div class="wi-result-info">
                                   <div class="wi-result-name">${p.full_name}</div>
                                   <div class="wi-result-meta">${phone}${code}</div>
                               </div>
                             </div>`;
                });
                listEl.innerHTML = html;
                resultsBox.style.display = 'block';
            })
            .catch(() => {
                listEl.innerHTML = '<div class="wi-no-results">هەڵەیەک ڕوویدا</div>';
            });
    }, 280);
}

function walkInSelectPatient(el) {
    const id    = el.dataset.id;
    const name  = el.dataset.name;
    const phone = el.dataset.phone;

    document.getElementById('walkInPatientId').value = id;
    document.getElementById('walkInPatientSearch').value = name;
    document.getElementById('walkInSelectedName').textContent  = name;
    document.getElementById('walkInSelectedPhone').textContent = phone ? 'ًں“‍ ' + phone : '';
    document.getElementById('walkInSelectedPatient').style.display = 'flex';
    document.getElementById('walkInClearBtn').style.display = 'block';

    walkInHideResults();
}

function walkInClearPatient(clearInput = true) {
    document.getElementById('walkInPatientId').value = '';
    if (clearInput) document.getElementById('walkInPatientSearch').value = '';
    document.getElementById('walkInSelectedPatient').style.display = 'none';
    document.getElementById('walkInClearBtn').style.display = 'none';
    walkInHideResults();
}

function walkInHideResults() {
    document.getElementById('walkInPatientResults').style.display = 'none';
}

// Reset search when dialog opens
const _origOpenDialog = window.openDialog;
window.openDialog = function(id) {
    if (id === 'walkInDialog') {
        walkInClearPatient(true);
    }
    _origOpenDialog(id);
};

// Hide results when clicking outside
document.addEventListener('click', function(e) {
    const wrapper  = document.getElementById('walkInPatientSearch');
    const results  = document.getElementById('walkInPatientResults');
    if (wrapper && results && !wrapper.contains(e.target) && !results.contains(e.target)) {
        walkInHideResults();
    }
});

// Validate patient selected before submit
document.getElementById('walkInForm').addEventListener('submit', function(e) {
    const pid = document.getElementById('walkInPatientId').value;
    if (!pid) {
        e.preventDefault();
        document.getElementById('walkInPatientSearch').focus();
        document.getElementById('walkInPatientSearch').style.borderColor = '#ef4444';
        document.getElementById('walkInPatientSearch').style.boxShadow = '0 0 0 2px rgba(239,68,68,0.2)';
        setTimeout(() => {
            document.getElementById('walkInPatientSearch').style.borderColor = '';
            document.getElementById('walkInPatientSearch').style.boxShadow = '';
        }, 2000);
    }
});

// Position results dropdown relative to the field wrapper
document.addEventListener('DOMContentLoaded', function() {
    const searchInput   = document.getElementById('walkInPatientSearch');
    const resultsDropdown = document.getElementById('walkInPatientResults');
    if (searchInput && resultsDropdown) {
        // Make the parent wr-form-field relatively positioned
        searchInput.closest('.wr-form-field').style.position = 'relative';
    }
});
*/

function refreshWaitingRoomPanels() {
    const waitingPanel = document.getElementById('wrWaitingPanel');
    const inProgressPanel = document.getElementById('wrInProgressPanel');

    if (!waitingPanel || !inProgressPanel) {
        return;
    }

    fetch(window.location.href, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const nextWaitingPanel = doc.getElementById('wrWaitingPanel');
            const nextInProgressPanel = doc.getElementById('wrInProgressPanel');

            if (!nextWaitingPanel || !nextInProgressPanel) {
                return;
            }

            waitingPanel.outerHTML = nextWaitingPanel.outerHTML;
            inProgressPanel.outerHTML = nextInProgressPanel.outerHTML;

            if (typeof filterCardsLocally === 'function') {
                filterCardsLocally();
            }
        })
        .catch(() => {
            // Keep current UI state if the background refresh fails.
        });
}

let waitingRoomRefreshTimer = null;
const waitingRoomRefreshDelay = 30000;
const waitingRoomRefreshResumeDelay = 20000;

function hasOpenWaitingRoomDialog() {
    return !!document.querySelector('.dialog-overlay.open');
}

function stopWaitingRoomRefresh() {
    if (waitingRoomRefreshTimer) {
        clearTimeout(waitingRoomRefreshTimer);
        waitingRoomRefreshTimer = null;
    }
}

function scheduleWaitingRoomRefresh(delay = waitingRoomRefreshDelay) {
    stopWaitingRoomRefresh();

    <?php if (date('Y-m-d') === $selectedDateFrom && $selectedDateFrom === $selectedDateTo): ?>
    waitingRoomRefreshTimer = setTimeout(() => {
        if (hasOpenWaitingRoomDialog()) {
            scheduleWaitingRoomRefresh(waitingRoomRefreshResumeDelay);
            return;
        }

        refreshWaitingRoomPanels();
        scheduleWaitingRoomRefresh(waitingRoomRefreshDelay);
    }, delay);
    <?php endif; ?>
}

/* ===== Walk-In Patient Search ===== */
let walkInSearchTimer = null;
let walkInCurrentQuery = '';

function walkInEscapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function walkInGetInitials(name) {
    return (name || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .map(part => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function walkInSetAddButtonVisible(visible) {
    const addBtn = document.getElementById('walkInAddPatientBtn');
    if (addBtn) {
        addBtn.style.display = visible ? 'inline-flex' : 'none';
    }
}

function renderWalkInPatientResults(patients) {
    const listEl = document.getElementById('walkInPatientList');
    const resultsBox = document.getElementById('walkInPatientResults');

    if (!listEl || !resultsBox) {
        return;
    }

    const html = patients.map(p => {
        const initials = walkInGetInitials(p.full_name);
        const phone = p.phone ? `<span><i class="fas fa-phone" style="font-size:10px;"></i> ${walkInEscapeHtml(p.phone)}</span>` : '';
        const code = p.patient_code ? `<span class="wi-result-code">${walkInEscapeHtml(p.patient_code)}</span>` : '';

        return `<div class="wi-result-item" tabindex="0"
                    data-id="${walkInEscapeHtml(p.id)}"
                    data-name="${walkInEscapeHtml(p.full_name)}"
                    data-phone="${walkInEscapeHtml(p.phone || '')}"
                    data-patient-code="${walkInEscapeHtml(p.patient_code || '')}"
                    onclick="walkInSelectPatient(this)"
                    onkeydown="if(event.key==='Enter') walkInSelectPatient(this)">
                <div class="wi-result-avatar">${walkInEscapeHtml(initials)}</div>
                <div class="wi-result-info">
                    <div class="wi-result-name">${walkInEscapeHtml(p.full_name)}</div>
                    <div class="wi-result-meta">${phone}${code}</div>
                </div>
            </div>`;
    }).join('');

    listEl.innerHTML = html;
    resultsBox.style.display = 'block';
}

function walkInSelectPatientData(patient) {
    const id = String(patient.id || '');
    const name = patient.full_name || patient.name || '';
    const phone = patient.phone || '';

    document.getElementById('walkInPatientId').value = id;
    document.getElementById('walkInPatientSearch').value = name;
    document.getElementById('walkInSelectedName').textContent = name;
    document.getElementById('walkInSelectedPhone').textContent = phone ? ('مۆبایل: ' + phone) : '';
    document.getElementById('walkInSelectedPatient').style.display = 'flex';
    document.getElementById('walkInClearBtn').style.display = name ? 'block' : 'none';
    walkInSetAddButtonVisible(false);
    walkInHideResults();
}

function walkInSearchPatient(val) {
    const query = val.trim();
    walkInCurrentQuery = query;

    const resultsBox = document.getElementById('walkInPatientResults');
    const listEl = document.getElementById('walkInPatientList');
    const clearBtn = document.getElementById('walkInClearBtn');
    const patientId = document.getElementById('walkInPatientId').value;

    clearBtn.style.display = query.length > 0 ? 'block' : 'none';
    walkInSetAddButtonVisible(false);

    if (patientId) {
        walkInClearPatient(false);
    }

    if (query.length < 2) {
        walkInHideResults();
        return;
    }

    listEl.innerHTML = '<div class="wi-searching"><i class="fas fa-circle-notch fa-spin"></i> گەڕان...</div>';
    resultsBox.style.display = 'block';

    clearTimeout(walkInSearchTimer);
    walkInSearchTimer = setTimeout(() => {
        if (query.length < 2) {
            walkInHideResults();
            return;
        }

        fetch(`<?= APP_URL ?>/api/search.php?type=patients&q=` + encodeURIComponent(query))
            .then(r => r.json())
            .then(json => {
                if (walkInCurrentQuery !== query) {
                    return;
                }

                if (!json.success || !Array.isArray(json.data) || json.data.length === 0) {
                    listEl.innerHTML = '<div class="wi-no-results"><i class="fas fa-search"></i> هیچ نەخۆشێک نەدۆزرایەوە</div>';
                    resultsBox.style.display = 'block';
                    walkInSetAddButtonVisible(true);
                    return;
                }

                walkInSetAddButtonVisible(false);
                renderWalkInPatientResults(json.data);
            })
            .catch(() => {
                if (walkInCurrentQuery !== query) {
                    return;
                }
                listEl.innerHTML = '<div class="wi-no-results">هەڵەیەک ڕوویدا</div>';
                resultsBox.style.display = 'block';
                walkInSetAddButtonVisible(false);
            });
    }, 280);
}

function walkInSelectPatient(el) {
    walkInSelectPatientData({
        id: el.dataset.id,
        full_name: el.dataset.name,
        phone: el.dataset.phone,
        patient_code: el.dataset.patientCode
    });
}

function walkInClearPatient(clearInput = true) {
    document.getElementById('walkInPatientId').value = '';
    if (clearInput) {
        document.getElementById('walkInPatientSearch').value = '';
    }
    document.getElementById('walkInSelectedPatient').style.display = 'none';
    document.getElementById('walkInSelectedName').textContent = '';
    document.getElementById('walkInSelectedPhone').textContent = '';
    document.getElementById('walkInClearBtn').style.display = clearInput ? 'none' : 'block';
    walkInSetAddButtonVisible(false);
    walkInHideResults();
}

function walkInHideResults() {
    const results = document.getElementById('walkInPatientResults');
    if (results) {
        results.style.display = 'none';
    }
}

function openAddPatientDialog() {
    const frame = document.getElementById('addPatientFrame');
    const query = (document.getElementById('walkInPatientSearch').value || '').trim();
    const seed = query ? `&seed=${encodeURIComponent(query)}` : '';

    walkInHideResults();

    if (frame) {
        frame.src = `<?= APP_URL ?>/patients/add.php?embedded=1${seed}&_=${Date.now()}`;
    }

    openDialog('addPatientDialog');
}

function handleEmbeddedPatientCreated(payload) {
    if (!payload || !payload.patient) {
        return;
    }

    closeDialog('addPatientDialog');

    const frame = document.getElementById('addPatientFrame');
    if (frame) {
        frame.src = 'about:blank';
    }

    walkInSelectPatientData(payload.patient);
}

document.addEventListener('click', function(e) {
    const input = document.getElementById('walkInPatientSearch');
    const addBtn = document.getElementById('walkInAddPatientBtn');
    const results = document.getElementById('walkInPatientResults');

    if (input && results && !input.contains(e.target) && !results.contains(e.target) && (!addBtn || !addBtn.contains(e.target))) {
        walkInHideResults();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('walkInPatientSearch');
    const walkInForm = document.getElementById('walkInForm');
    const overlays = document.querySelectorAll('.dialog-overlay');
    const originalOpenDialog = window.openDialog;
    const originalCloseDialog = window.closeDialog;

    overlays.forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, true);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.querySelector('.dialog-overlay.open')) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    if (typeof originalOpenDialog === 'function') {
        window.openDialog = function(id) {
            if (id === 'walkInDialog') {
                walkInClearPatient(true);
                walkInSetAddButtonVisible(false);
            }
            originalOpenDialog(id);
            stopWaitingRoomRefresh();
        };
    }

    if (typeof originalCloseDialog === 'function') {
        window.closeDialog = function(id) {
            originalCloseDialog(id);
            if (!hasOpenWaitingRoomDialog()) {
                scheduleWaitingRoomRefresh(waitingRoomRefreshResumeDelay);
            }
        };
    }

    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin) {
            return;
        }

        if (event.data && event.data.type === 'patient-created') {
            handleEmbeddedPatientCreated(event.data);
        }
    });

    if (searchInput) {
        const field = searchInput.closest('.wr-form-field');
        if (field) {
            field.style.position = 'relative';
        }
    }

    filterCardsLocally();

    if (walkInForm) {
        walkInForm.addEventListener('submit', function(e) {
            const pid = document.getElementById('walkInPatientId').value;
            if (!pid) {
                e.preventDefault();
                document.getElementById('walkInPatientSearch').focus();
                document.getElementById('walkInPatientSearch').style.borderColor = '#ef4444';
                document.getElementById('walkInPatientSearch').style.boxShadow = '0 0 0 2px rgba(239,68,68,0.2)';
                setTimeout(() => {
                    document.getElementById('walkInPatientSearch').style.borderColor = '';
                    document.getElementById('walkInPatientSearch').style.boxShadow = '';
                }, 2000);
            }
        });
    }

    scheduleWaitingRoomRefresh(waitingRoomRefreshDelay);
});

// ── Doctor rooms: pick the room when calling a patient ──────────────
// Doctors can have two rooms (users.room_number / room_number_2).
// 0 or 1 room → submit as-is, the server auto-assigns the single room.
// 2 rooms → intercept the call form and ask which room the patient goes to.
window.WR_DOCTOR_ROOMS = <?php
    $doctorRoomsMap = [];
    foreach ($db->fetchAll("SELECT id, room_number, room_number_2 FROM users WHERE role = 'doctor' AND is_active = 1") as $drRow) {
        $drRooms = array_values(array_filter([
            trim((string)($drRow['room_number'] ?? '')),
            trim((string)($drRow['room_number_2'] ?? '')),
        ], static fn($r) => $r !== ''));
        if ($drRooms) $doctorRoomsMap[(int)$drRow['id']] = $drRooms;
    }
    echo json_encode($doctorRoomsMap, JSON_UNESCAPED_UNICODE);
?>;

document.addEventListener('submit', function(e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    const actionInput = form.querySelector('input[name="action"]');
    if (!actionInput || actionInput.value !== 'call') return;
    if (form.dataset.roomChosen === '1') return; // room already picked

    const docId = parseInt(form.dataset.doctorId || '0', 10);
    const rooms = (window.WR_DOCTOR_ROOMS && window.WR_DOCTOR_ROOMS[docId]) || [];
    if (rooms.length < 2) return; // server auto-assigns the single room

    e.preventDefault();
    Swal.fire({
        title: 'هەڵبژاردنی ژوور',
        text: 'نەخۆش بۆ کام ژوور بانگ دەکرێت؟',
        icon: 'question',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-door-open"></i> ژووری ' + rooms[0],
        denyButtonText: '<i class="fas fa-door-open"></i> ژووری ' + rooms[1],
        cancelButtonText: 'هەڵوەشاندنەوە',
        confirmButtonColor: 'var(--win-accent, #0078D4)',
        denyButtonColor: '#107C10'
    }).then(function(res) {
        let chosen = null;
        if (res.isConfirmed) chosen = rooms[0];
        else if (res.isDenied) chosen = rooms[1];
        if (chosen === null) return;
        let inp = form.querySelector('input[name="room_name"]');
        if (!inp) {
            inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'room_name';
            form.appendChild(inp);
        }
        inp.value = chosen;
        form.dataset.roomChosen = '1';
        form.submit(); // patched prototype re-adds the CSRF token if missing
    });
}, true);
</script>

<?php if ($permission->can('page.new_visit')) require __DIR__ . '/includes/new_visit_dialog.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
