<?php
/**
 * Save Promised Amount API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
Csrf::require();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$appointmentId = intval($input['appointment_id'] ?? $_POST['appointment_id'] ?? 0);
$amountRaw = $input['amount'] ?? $_POST['amount'] ?? '';
$amountRaw = is_string($amountRaw) ? trim($amountRaw) : $amountRaw;
$amount = ($amountRaw === '' || $amountRaw === null) ? null : floatval($amountRaw);

if ($appointmentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment']);
    exit;
}

// Doctor isolation: a doctor may only edit their OWN appointments.
if ($auth->isDoctor() && !$auth->isAdmin()) {
    $appt = $db->fetch("SELECT doctor_id FROM appointments WHERE id = ?", [$appointmentId]);
    if (!$appt || (int)$appt['doctor_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
}

try {
    $db->update('appointments', ['promised_amount' => $amount], 'id = ?', [$appointmentId]);
    echo json_encode(['success' => true, 'amount' => $amount]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
