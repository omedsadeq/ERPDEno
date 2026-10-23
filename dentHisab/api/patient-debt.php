<?php
/**
 * Patient Debt API
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

$patientId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($patientId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient']);
    exit;
}

// Access control: debt is financial WORK data. Staff (admin/receptionist/
// accountant) may read any patient's total. A doctor may read it ONLY for a
// patient they OWN — being referred (general access) is not enough — and only
// sees the debt from THEIR OWN folders, never other doctors' work.
$restrictDoctorId = 0;
if ($auth->isDoctor() && !$auth->isAdmin()) {
    $restrictDoctorId = (int)($_SESSION['user_id'] ?? 0);
    AccessGuard::requirePatientWork($patientId, true);
}

if ($restrictDoctorId) {
    // Doctor: scope folder debt to their folders; X-ray debt to their requests.
    $sql = "SELECT
            COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                      FROM patient_folders pf WHERE pf.patient_id = :id AND pf.doctor_id = :doc), 0)
          + COALESCE((SELECT SUM(xr.final_amount)
                      FROM xray_requests xr WHERE xr.patient_id = :id2
                      AND xr.requested_by = :doc2
                      AND xr.status='completed' AND xr.payment_status='debt'), 0)
            AS total_debt";
    $row = $db->fetch($sql, ['id' => $patientId, 'id2' => $patientId, 'doc' => $restrictDoctorId, 'doc2' => $restrictDoctorId]);
} else {
    $sql = "SELECT
            COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                      FROM patient_folders pf WHERE pf.patient_id = :id), 0)
          + COALESCE((SELECT SUM(xr.final_amount)
                      FROM xray_requests xr WHERE xr.patient_id = :id2
                      AND xr.status='completed' AND xr.payment_status='debt'), 0)
            AS total_debt";
    $row = $db->fetch($sql, ['id' => $patientId, 'id2' => $patientId]);
}
$debt = max(0, (float)($row['total_debt'] ?? 0));

echo json_encode(['success' => true, 'total_debt' => $debt]);
