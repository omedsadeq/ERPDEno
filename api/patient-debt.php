<?php
/**
 * Patient Debt API
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';
require_once __DIR__ . '/../classes/PatientFinance.php';

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

// Task 15: repointed to PatientFinance::summary()['net_debt'] — the same
// canonical figure (case_balance + xray_outstanding - credit, floored at 0)
// every other approval-center reader now shows, instead of hand-rolled SQL
// over the legacy patient_folders mirror.
$doctorScope = $restrictDoctorId ?: null;
$summary = (new PatientFinance())->summary($patientId, $doctorScope);
$debt = max(0, (float)$summary['net_debt']);

echo json_encode(['success' => true, 'total_debt' => $debt]);
