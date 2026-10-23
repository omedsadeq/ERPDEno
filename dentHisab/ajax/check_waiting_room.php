<?php
/**
 * Check if Patient is in Waiting Room AJAX Endpoint
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

// Check if user is logged in
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();

// Get patient_id from query string
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ناسنامەی نەخۆش پێویستە']);
    exit;
}

// Doctor isolation: only reveal waiting-room presence for patients a doctor
// owns or was referred (prevents probing other doctors' patients by id).
if ($auth->isDoctor() && !$auth->isAdmin()) {
    AccessGuard::requirePatientGeneral($patient_id, true);
}

// Check if patient is already in waiting room (any active stage)
$existingEntry = $db->fetch(
    "SELECT id, status, check_in_time FROM waiting_room WHERE patient_id = :patient_id AND status IN ('waiting', 'in_progress', 'at_accounting', 'at_xray')",
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
        'success' => true,
        'in_waiting_room' => true,
        'status' => $existingEntry['status'],
        'status_text' => $statusText,
        'check_in_time' => $existingEntry['check_in_time']
    ]);
} else {
    echo json_encode([
        'success' => true,
        'in_waiting_room' => false
    ]);
}
