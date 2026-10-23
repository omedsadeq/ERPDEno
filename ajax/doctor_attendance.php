<?php
/**
 * Doctor attendance clock — چوونە ژوورەوە / چوونە دەرەوە.
 *
 * Only ever writes today's server time; it never accepts a caller-supplied
 * time or lets a past day be clocked (see the same restriction in
 * doctors/daily-summary.php, which only renders the buttons for today).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
Csrf::require();

$permission = Permission::getInstance();
if (!$permission->can('page.doctor_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'ڕێگەپێدراو نیت']);
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$currentUserId = (int)($currentUser['id'] ?? 0);
$isAdminView = $auth->isAdmin() || $auth->isAccountant();

$action = trim((string)($_POST['action'] ?? ''));
if (!in_array($action, ['check_in', 'check_out'], true)) {
    echo json_encode(['success' => false, 'error' => 'کردارێکی نادروست']);
    exit;
}

// A doctor may only clock themselves in/out; admin/accountant may act for
// whichever doctor they are viewing (e.g. the doctor forgot).
$doctorId = $isAdminView ? (int)($_POST['doctor_id'] ?? 0) : $currentUserId;
if ($doctorId <= 0) {
    $doctorId = $currentUserId;
}
if (!$isAdminView && $doctorId !== $currentUserId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'تۆ تەنها دەتوانیت کاتی خۆت تۆمار بکەیت']);
    exit;
}

$doctorRow = $db->fetch("SELECT id FROM users WHERE id = :id AND role = 'doctor'", ['id' => $doctorId]);
if (!$doctorRow) {
    echo json_encode(['success' => false, 'error' => 'پزیشک نەدۆزرایەوە']);
    exit;
}

// Attendance can only ever be marked for today — never backdated through
// this endpoint, so the timestamp is always the server clock, not client input.
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    if ($action === 'check_in') {
        $existing = $db->fetch(
            "SELECT id, check_in_time FROM doctor_attendance WHERE doctor_id = :d AND attendance_date = :dt",
            ['d' => $doctorId, 'dt' => $today]
        );
        if ($existing && $existing['check_in_time']) {
            echo json_encode(['success' => false, 'error' => 'ئەمڕۆ پێشتر چوویتە ژوورەوە']);
            exit;
        }
        if ($existing) {
            $db->update(
                'doctor_attendance',
                ['check_in_time' => $now, 'checked_in_by' => $currentUserId],
                'id = :id',
                ['id' => $existing['id']]
            );
        } else {
            $db->insert('doctor_attendance', [
                'doctor_id' => $doctorId,
                'attendance_date' => $today,
                'check_in_time' => $now,
                'checked_in_by' => $currentUserId,
            ]);
        }
        AuditLog::log('doctor_check_in', 'doctor_attendance', $doctorId, null, ['at' => $now]);
        echo json_encode(['success' => true, 'time' => date('H:i', strtotime($now))]);
        exit;
    }

    // check_out
    $existing = $db->fetch(
        "SELECT id, check_in_time FROM doctor_attendance WHERE doctor_id = :d AND attendance_date = :dt",
        ['d' => $doctorId, 'dt' => $today]
    );
    if (!$existing || !$existing['check_in_time']) {
        echo json_encode(['success' => false, 'error' => 'پێویستە یەکەم جار بچیتە ژوورەوە']);
        exit;
    }
    $db->update(
        'doctor_attendance',
        ['check_out_time' => $now, 'checked_out_by' => $currentUserId],
        'id = :id',
        ['id' => $existing['id']]
    );
    AuditLog::log('doctor_check_out', 'doctor_attendance', $doctorId, null, ['at' => $now]);
    echo json_encode(['success' => true, 'time' => date('H:i', strtotime($now))]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'هەڵە: ' . $e->getMessage()]);
}
