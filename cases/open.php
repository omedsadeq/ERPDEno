<?php
/**
 * «کەیس» from any page (includes/case-button.php, assets/js/open-case.js):
 * straight into the patient's case (CaseManager::quickOpen).
 *
 * A doctor opens their own. The admin and reception say whose: action=options
 * lists the patient's open cases and the doctors who could take a new one, and
 * action=open then goes to the case picked (case_id) or the doctor's (doctor_id).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../includes/case-button.php';

$auth = new Auth();
$auth->requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}
Csrf::require();

$action = (string)($_POST['action'] ?? 'open');
$patientId = intval($_POST['patient_id'] ?? 0);
AccessGuard::requirePatientGeneral($patientId, $action === 'options');
$db = Database::getInstance();
$patientRow = $db->fetch("SELECT id FROM patients WHERE id = :id AND deleted_at IS NULL", ['id' => $patientId]);
if (!$patientRow || !case_button_allowed($auth, $patientId)) {
    if ($action === 'options') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false]);
    } else {
        header('Location: ' . APP_URL . '/403.php');
    }
    exit;
}

$fullAccess = $auth->isAdmin() || $auth->isReceptionist();
$openCases = $fullAccess ? $db->fetchAll(
    "SELECT c.id, c.case_name, c.case_number, pf.doctor_id, u.full_name AS doctor_name
     FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id
     LEFT JOIN users u ON u.id = pf.doctor_id
     WHERE pf.patient_id = :pid AND c.status = 'open'
     ORDER BY c.created_at DESC, c.id DESC",
    ['pid' => $patientId]
) : [];
$isActiveDoctor = function ($id) use ($db) {
    return $id > 0 && (bool)$db->fetch("SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1", ['id' => $id]);
};

if ($action === 'options') {
    header('Content-Type: application/json; charset=utf-8');
    $doctors = $fullAccess ? $db->fetchAll(
        "SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name"
    ) : [];
    // the doctor offered first: today's waiting-room entry, else the patient's last folder
    $lead = $fullAccess ? $db->fetch(
        "SELECT doctor_id FROM waiting_room WHERE patient_id = :pid AND DATE(check_in_time) = CURDATE()
           AND doctor_id IS NOT NULL AND status NOT IN ('cancelled', 'rejected')
         ORDER BY check_in_time DESC LIMIT 1",
        ['pid' => $patientId]
    ) : null;
    if (!$lead && $fullAccess) {
        $lead = $db->fetch(
            "SELECT doctor_id FROM patient_folders WHERE patient_id = :pid ORDER BY created_at DESC, id DESC LIMIT 1",
            ['pid' => $patientId]
        );
    }
    echo json_encode([
        'success' => true,
        'cases'   => array_map(fn($c) => [
            'id' => (int)$c['id'],
            'label' => ($c['doctor_name'] ?: '—') . ' — ' . ($c['case_name'] ?: $c['case_number']),
        ], $openCases),
        'doctors' => array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['full_name']], $doctors),
        'doctor_id' => (int)($lead['doctor_id'] ?? 0),
        // the doctor the page named (an appointment's, a plan's): still a doctor here?
        'doctor_ok' => $fullAccess && $isActiveDoctor(intval($_POST['doctor_id'] ?? 0)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$caseId = 0;
if ($fullAccess) {
    $pickedCase = intval($_POST['case_id'] ?? 0);
    $pickedDoctor = intval($_POST['doctor_id'] ?? 0);
    if ($pickedCase > 0) {
        $caseId = in_array($pickedCase, array_map('intval', array_column($openCases, 'id')), true) ? $pickedCase : 0;
    } elseif ($pickedDoctor > 0) {
        $caseId = $isActiveDoctor($pickedDoctor)
            ? (int)(new CaseManager())->quickOpen($patientId, $pickedDoctor, (int)$auth->getCurrentUser()['id']) : 0;
    } elseif (count($openCases) === 1) {
        $caseId = (int)$openCases[0]['id'];
    }
} else {
    $meId = (int)$auth->getCurrentUser()['id'];
    $caseId = (int)(new CaseManager())->quickOpen($patientId, $meId, $meId);
}

if ($caseId <= 0) {
    // back where the click came from, saying so (open-case.js shows the toast)
    $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $sameHost = $back !== '' && parse_url($back, PHP_URL_HOST) === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $back = $sameHost ? preg_replace('/([?&])case_err=1(&|$)/', '$1', $back) : APP_URL . '/patients/index.php';
    header('Location: ' . rtrim($back, '?&') . (strpos($back, '?') === false ? '?' : '&') . 'case_err=1');
    exit;
}
header('Location: ' . APP_URL . '/cases/view.php?id=' . $caseId . '&tab=chart');
exit;
