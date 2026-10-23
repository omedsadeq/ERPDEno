<?php
/**
 * Search API Endpoint
 * سیستەمی زیرەکی کلینیکی ددان
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$query = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all';

// Doctor isolation: a doctor may only find patients/cases they own or that
// reception referred to them. 0 = no restriction (admin/receptionist/etc.).
$restrictDoctorId = ($auth->isDoctor() && !$auth->isAdmin()) ? (int)($_SESSION['user_id'] ?? 0) : 0;

// patient_cases doesn't require a search query
if ($type !== 'patient_cases' && strlen($query) < 2) {
    echo json_encode(['success' => false, 'message' => 'Query too short']);
    exit;
}

$results = [];

if ($type === 'patients' || $type === 'all') {
    $sql = "SELECT id, patient_code, full_name, phone, date_of_birth,
                   TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) as age
            FROM patients
            WHERE (full_name LIKE :q1 OR phone LIKE :q2 OR patient_code LIKE :q3)";
    $pParams = ['q1' => "%{$query}%", 'q2' => "%{$query}%", 'q3' => "%{$query}%"];
    if ($restrictDoctorId) {
        $sql .= " AND (EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = patients.id AND pf.doctor_id = :pd1)
                    OR EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = patients.id AND a.doctor_id = :pd2)
                    OR EXISTS (SELECT 1 FROM waiting_room wr WHERE wr.patient_id = patients.id AND wr.doctor_id = :pd3))";
        $pParams['pd1'] = $restrictDoctorId;
        $pParams['pd2'] = $restrictDoctorId;
        $pParams['pd3'] = $restrictDoctorId;
    }
    $sql .= " ORDER BY full_name LIMIT 20";
    $patients = $db->fetchAll($sql, $pParams);

    if ($type === 'patients') {
        echo json_encode(['success' => true, 'data' => $patients]);
        exit;
    }

    $results['patients'] = $patients;
}

if ($type === 'cases' || $type === 'all') {
    $sql = "SELECT c.id, c.case_number, c.case_name, p.full_name as patient_name
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            INNER JOIN patients p ON pf.patient_id = p.id
            WHERE (c.case_number LIKE :q1 OR c.case_name LIKE :q2)";
    $cParams = ['q1' => "%{$query}%", 'q2' => "%{$query}%"];
    if ($restrictDoctorId) {
        $sql .= " AND pf.doctor_id = :cd";
        $cParams['cd'] = $restrictDoctorId;
    }
    $sql .= " ORDER BY c.created_at DESC LIMIT 10";
    $cases = $db->fetchAll($sql, $cParams);

    if ($type === 'cases') {
        echo json_encode(['success' => true, 'data' => $cases]);
        exit;
    }

    $results['cases'] = $cases;
}

// Get cases by patient ID (for X-Ray request form)
if ($type === 'patient_cases') {
    $patientId = intval($_GET['patient_id'] ?? 0);
    if ($patientId > 0) {
        $sql = "SELECT c.id, c.case_number, c.case_name
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.patient_id = :patient_id AND c.status != 'closed'";
        $pcParams = ['patient_id' => $patientId];
        if ($restrictDoctorId) {
            // A doctor may only list their OWN cases for the patient.
            $sql .= " AND pf.doctor_id = :pcd";
            $pcParams['pcd'] = $restrictDoctorId;
        }
        $sql .= " ORDER BY c.created_at DESC LIMIT 20";
        $cases = $db->fetchAll($sql, $pcParams);
        echo json_encode(['success' => true, 'data' => $cases]);
        exit;
    } else {
        echo json_encode(['success' => false, 'data' => [], 'message' => 'Patient ID required']);
        exit;
    }
}

if ($type === 'treatments' || $type === 'all') {
    $sql = "SELECT id, treatment_code, treatment_name, default_price
            FROM treatments
            WHERE is_active = 1 AND (treatment_name LIKE :q1 OR treatment_name_en LIKE :q2)
            ORDER BY treatment_name
            LIMIT 10";
    $treatments = $db->fetchAll($sql, [
        'q1' => "%{$query}%",
        'q2' => "%{$query}%"
    ]);

    if ($type === 'treatments') {
        echo json_encode(['success' => true, 'data' => $treatments]);
        exit;
    }

    $results['treatments'] = $treatments;
}

echo json_encode(['success' => true, 'data' => $results]);
