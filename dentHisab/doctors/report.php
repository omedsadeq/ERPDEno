<?php
/**
 * Daily Doctor Report Page
 * ڕاپۆرتی ڕۆژانەی دکتۆر
 */

$pageTitle = 'ڕاپۆرتی ڕۆژانەی دکتۆر';
$currentPage = 'doctor-report';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

require_once __DIR__ . '/../classes/Permission.php';
$auth->requireLogin();
$permission = Permission::getInstance();

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();

if (!$permission->can('page.doctor_report')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// Filters
$reportDate = $_GET['date'] ?? date('Y-m-d');
$doctorFilter = $_GET['doctor'] ?? '';

// If doctor, only show their own report
if ($isDoctor && !$isAdmin) {
    $doctorFilter = $currentUser['id'];
}

// Get all doctors for filter
$doctorsResult = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name");
$doctors = is_array($doctorsResult) ? $doctorsResult : [];

// Build date condition
$dateStart = $reportDate . ' 00:00:00';
$dateEnd = $reportDate . ' 23:59:59';

// Helper function to get tooth quadrant
function getToothQuadrant($toothNum) {
    if ($toothNum >= 11 && $toothNum <= 18) return 'UR';
    if ($toothNum >= 21 && $toothNum <= 28) return 'UL';
    if ($toothNum >= 31 && $toothNum <= 38) return 'LL';
    if ($toothNum >= 41 && $toothNum <= 48) return 'LR';
    return null;
}

// Helper function to get Palmer notation
function getPalmerNumber($toothNum) {
    return $toothNum % 10;
}

// Get report data
$reportData = [];

if ($doctorFilter) {
    // Single doctor report
    $doctorIds = [$doctorFilter];
} else {
    // All doctors
    $doctorIds = array_column($doctors, 'id');
}

foreach ($doctorIds as $docId) {
    $doctor = $db->fetch("SELECT id, full_name, specialization FROM users WHERE id = :id", ['id' => $docId]);
    if (!$doctor) continue;

    // Get folders created today
    $foldersCreated = $db->fetch("SELECT COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as total_amount,
        COALESCE(SUM(discount_amount), 0) as discount_amount
        FROM patient_folders
        WHERE doctor_id = :doctor_id
        AND created_at BETWEEN :start AND :end",
        ['doctor_id' => $docId, 'start' => $dateStart, 'end' => $dateEnd]);

    // Get cases created today
    $casesCreated = $db->fetch("SELECT COUNT(*) as count,
        COALESCE(SUM(c.total_amount), 0) as total_amount,
        COALESCE(SUM(c.discount_amount), 0) as discount_amount
        FROM cases c
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        WHERE pf.doctor_id = :doctor_id
        AND c.created_at BETWEEN :start AND :end",
        ['doctor_id' => $docId, 'start' => $dateStart, 'end' => $dateEnd]);

    // Get cases closed today
    $casesClosed = $db->fetch("SELECT COUNT(*) as count
        FROM cases c
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        WHERE pf.doctor_id = :doctor_id
        AND c.closed_at BETWEEN :start AND :end",
        ['doctor_id' => $docId, 'start' => $dateStart, 'end' => $dateEnd]);

    // Get payments received today (from folders belonging to this doctor)
    $paymentsReceived = $db->fetch("SELECT COUNT(*) as count,
        COALESCE(SUM(p.amount), 0) as total_amount
        FROM payments p
        INNER JOIN cases c ON p.case_id = c.id
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        WHERE pf.doctor_id = :doctor_id
        AND p.created_at BETWEEN :start AND :end
        AND p.status = 'completed'",
        ['doctor_id' => $docId, 'start' => $dateStart, 'end' => $dateEnd]);

    // Get unique patients seen today (folders updated or cases worked on)
    $patientsSeen = $db->fetch("SELECT COUNT(DISTINCT pf.patient_id) as count
        FROM patient_folders pf
        WHERE pf.doctor_id = :doctor_id
        AND (pf.updated_at BETWEEN :start AND :end
             OR EXISTS (SELECT 1 FROM cases c WHERE c.folder_id = pf.id AND c.updated_at BETWEEN :start2 AND :end2))",
        ['doctor_id' => $docId, 'start' => $dateStart, 'end' => $dateEnd, 'start2' => $dateStart, 'end2' => $dateEnd]);

    // Get treatments/services done today
    $treatmentsDone = $db->fetch("SELECT COUNT(*) as count,
        COALESCE(SUM(ct.final_price), 0) as total_amount
        FROM case_treatments ct
        INNER JOIN cases c ON ct.case_id = c.id
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        WHERE pf.doctor_id = :doctor_id
        AND ct.created_at BETWEEN :start AND :end",
        ['doctor_id' => $docId, 'start' => $dateStart, 'end' => $dateEnd]);

    // Get treatments done today grouped by patient (with tooth positions)
    $treatmentsResult = $db->fetchAll("SELECT
        ct.id as treatment_id,
        ct.final_price as treatment_price,
        ct.unit_price,
        ct.quantity,
        ct.created_at as treatment_time,
        ct.tooth_number,
        t.treatment_name,
        c.id as case_id,
        c.case_number,
        c.case_name,
        c.scope_type,
        c.total_amount as case_total,
        c.paid_amount as case_paid,
        c.discount_amount as case_discount,
        p.id as patient_id,
        p.full_name as patient_name,
        p.phone as patient_phone,
        pf.id as folder_id,
        pf.folder_name
        FROM case_treatments ct
        INNER JOIN treatments t ON ct.treatment_id = t.id
        INNER JOIN cases c ON ct.case_id = c.id
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        INNER JOIN patients p ON pf.patient_id = p.id
        WHERE pf.doctor_id = :doctor_id
        AND ct.created_at BETWEEN :start AND :end
        ORDER BY p.full_name, c.id, ct.treatment_id, ct.created_at DESC",
        [
            'doctor_id' => $docId,
            'start' => $dateStart,
            'end' => $dateEnd
        ]);
    $treatmentsList = is_array($treatmentsResult) ? $treatmentsResult : [];

    // Get payments received today for this doctor's patients
    $dailyPaymentsResult = $db->fetchAll("SELECT
        pay.id as payment_id,
        pay.amount,
        pay.payment_method,
        pay.status as payment_status,
        pay.created_at as payment_time,
        c.id as case_id,
        c.case_number,
        p.id as patient_id,
        p.full_name as patient_name
        FROM payments pay
        INNER JOIN cases c ON pay.case_id = c.id
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        INNER JOIN patients p ON pf.patient_id = p.id
        WHERE pf.doctor_id = :doctor_id
        AND pay.created_at BETWEEN :start AND :end
        ORDER BY pay.created_at DESC",
        [
            'doctor_id' => $docId,
            'start' => $dateStart,
            'end' => $dateEnd
        ]);
    $dailyPayments = is_array($dailyPaymentsResult) ? $dailyPaymentsResult : [];

    // Get procedure logs for arch cases done today
    $procedureLogsResult = $db->fetchAll("SELECT
        apl.id as log_id,
        apl.case_id,
        apl.custom_procedure_name,
        apl.arch_affected,
        apl.procedure_date,
        apl.procedure_time,
        apl.notes,
        apl.price,
        apt.type_code,
        apt.type_name,
        COALESCE(apl.custom_procedure_name, apt.type_name) as display_name,
        c.case_number
        FROM arch_procedure_logs apl
        LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id = apt.id
        INNER JOIN cases c ON apl.case_id = c.id
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        WHERE pf.doctor_id = :doctor_id
        AND apl.procedure_date = :target_date
        ORDER BY apl.procedure_time DESC, apl.id DESC",
        [
            'doctor_id' => $docId,
            'target_date' => $reportDate
        ]);
    $procedureLogs = is_array($procedureLogsResult) ? $procedureLogsResult : [];

    // Group payments by patient
    $patientPayments = [];
    foreach ($dailyPayments as $pay) {
        $patientId = $pay['patient_id'];
        if (!isset($patientPayments[$patientId])) {
            $patientPayments[$patientId] = [];
        }
        $patientPayments[$patientId][] = $pay;
    }

    // Group payments by case_id for arch cases
    $casePayments = [];
    foreach ($dailyPayments as $pay) {
        $caseId = $pay['case_id'];
        if (!isset($casePayments[$caseId])) {
            $casePayments[$caseId] = [];
        }
        $casePayments[$caseId][] = $pay;
    }

    // Group procedure logs by case_id
    $caseProcedureLogs = [];
    foreach ($procedureLogs as $log) {
        $caseId = $log['case_id'];
        if (!isset($caseProcedureLogs[$caseId])) {
            $caseProcedureLogs[$caseId] = [];
        }
        $caseProcedureLogs[$caseId][] = $log;
    }

    // Group treatments by patient then by case/treatment
    $patientDetails = [];
    foreach ($treatmentsList as $treatment) {
        $patientId = $treatment['patient_id'];
        $caseId = $treatment['case_id'];
        $treatmentKey = $treatment['treatment_name'] . '_' . $treatment['unit_price'] . '_' . $caseId;

        if (!isset($patientDetails[$patientId])) {
            $patientDetails[$patientId] = [
                'patient_id' => $patientId,
                'patient_name' => $treatment['patient_name'],
                'patient_phone' => $treatment['patient_phone'],
                'grouped_treatments' => [],
                'total_treatments' => 0,
                'case_total' => 0,
                'case_paid' => 0,
                'case_discount' => 0,
                'cases_seen' => [],
                'daily_payments' => $patientPayments[$patientId] ?? []
            ];
        }

        // Initialize grouped treatment if not exists
        if (!isset($patientDetails[$patientId]['grouped_treatments'][$treatmentKey])) {
            $patientDetails[$patientId]['grouped_treatments'][$treatmentKey] = [
                'treatment_name' => $treatment['treatment_name'],
                'unit_price' => $treatment['unit_price'],
                'case_id' => $caseId,
                'case_number' => $treatment['case_number'],
                'case_name' => $treatment['case_name'],
                'scope_type' => $treatment['scope_type'],
                'case_total' => $treatment['case_total'],
                'case_paid' => $treatment['case_paid'],
                'case_discount' => $treatment['case_discount'],
                'folder_id' => $treatment['folder_id'],
                'folder_name' => $treatment['folder_name'],
                'treatment_time' => $treatment['treatment_time'],
                'upper_left' => [],
                'upper_right' => [],
                'lower_left' => [],
                'lower_right' => [],
                'total_quantity' => 0,
                'total_price' => 0,
                'case_payments' => $casePayments[$caseId] ?? [],
                'case_procedure_logs' => $caseProcedureLogs[$caseId] ?? []
            ];
        }

        // Add tooth position to quadrant
        if ($treatment['tooth_number']) {
            $quadrant = getToothQuadrant($treatment['tooth_number']);
            $palmerNum = getPalmerNumber($treatment['tooth_number']);
            if ($quadrant === 'UL') {
                $patientDetails[$patientId]['grouped_treatments'][$treatmentKey]['upper_left'][] = $palmerNum;
            } elseif ($quadrant === 'UR') {
                $patientDetails[$patientId]['grouped_treatments'][$treatmentKey]['upper_right'][] = $palmerNum;
            } elseif ($quadrant === 'LL') {
                $patientDetails[$patientId]['grouped_treatments'][$treatmentKey]['lower_left'][] = $palmerNum;
            } elseif ($quadrant === 'LR') {
                $patientDetails[$patientId]['grouped_treatments'][$treatmentKey]['lower_right'][] = $palmerNum;
            }
        }

        $patientDetails[$patientId]['grouped_treatments'][$treatmentKey]['total_quantity'] += $treatment['quantity'];
        $patientDetails[$patientId]['grouped_treatments'][$treatmentKey]['total_price'] += $treatment['treatment_price'];
        $patientDetails[$patientId]['total_treatments'] += $treatment['treatment_price'];

        // Track unique cases to sum their totals
        if (!in_array($caseId, $patientDetails[$patientId]['cases_seen'])) {
            $patientDetails[$patientId]['cases_seen'][] = $caseId;
            $patientDetails[$patientId]['case_total'] += $treatment['case_total'];
            $patientDetails[$patientId]['case_paid'] += $treatment['case_paid'];
            $patientDetails[$patientId]['case_discount'] += $treatment['case_discount'];
        }
    }

    // Add arch cases with payments but no treatments on this date
    foreach ($casePayments as $caseId => $payments) {
        // Check if this case is already in patientDetails
        $caseExists = false;
        foreach ($patientDetails as $patient) {
            foreach ($patient['grouped_treatments'] as $treatment) {
                if ($treatment['case_id'] == $caseId) {
                    $caseExists = true;
                    break 2;
                }
            }
        }

        // If case not found, check if it's an arch case
        if (!$caseExists) {
            $caseInfo = $db->fetch("SELECT
                c.id as case_id,
                c.case_number,
                c.scope_type,
                c.case_name,
                c.total_amount as case_total,
                c.discount_amount as case_discount,
                c.paid_amount as case_paid,
                pf.id as folder_id,
                pf.folder_name,
                p.id as patient_id,
                p.full_name as patient_name,
                p.phone as patient_phone
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                INNER JOIN patients p ON pf.patient_id = p.id
                WHERE c.id = :case_id",
                ['case_id' => $caseId]
            );

            // Only add if it's an arch case
            if ($caseInfo && strtolower($caseInfo['scope_type']) === 'arch') {
                $patientId = $caseInfo['patient_id'];

                // Create patient entry if doesn't exist
                if (!isset($patientDetails[$patientId])) {
                    $patientDetails[$patientId] = [
                        'patient_id' => $patientId,
                        'patient_name' => $caseInfo['patient_name'],
                        'patient_phone' => $caseInfo['patient_phone'],
                        'grouped_treatments' => [],
                        'total_treatments' => 0,
                        'case_total' => 0,
                        'case_paid' => 0,
                        'case_discount' => 0,
                        'cases_seen' => [],
                        'daily_payments' => $patientPayments[$patientId] ?? []
                    ];
                }

                // Add the arch case with no treatments (only payments)
                $patientDetails[$patientId]['grouped_treatments'][] = [
                    'treatment_name' => '',
                    'unit_price' => 0,
                    'case_id' => $caseInfo['case_id'],
                    'case_number' => $caseInfo['case_number'],
                    'case_name' => $caseInfo['case_name'],
                    'scope_type' => $caseInfo['scope_type'],
                    'case_total' => $caseInfo['case_total'],
                    'case_paid' => $caseInfo['case_paid'],
                    'case_discount' => $caseInfo['case_discount'],
                    'folder_id' => $caseInfo['folder_id'],
                    'folder_name' => $caseInfo['folder_name'],
                    'treatment_time' => '',
                    'upper_left' => [],
                    'upper_right' => [],
                    'lower_left' => [],
                    'lower_right' => [],
                    'total_quantity' => 0,
                    'total_price' => 0,
                    'case_payments' => $payments,
                    'case_procedure_logs' => $caseProcedureLogs[$caseId] ?? []
                ];

                // Track case totals
                if (!in_array($caseId, $patientDetails[$patientId]['cases_seen'])) {
                    $patientDetails[$patientId]['cases_seen'][] = $caseId;
                    $patientDetails[$patientId]['case_total'] += $caseInfo['case_total'];
                    $patientDetails[$patientId]['case_paid'] += $caseInfo['case_paid'];
                    $patientDetails[$patientId]['case_discount'] += $caseInfo['case_discount'];
                }
            }
        }
    }

    // Add arch cases with procedure logs but no treatments or payments on this date
    foreach ($caseProcedureLogs as $caseId => $logs) {
        // Check if this case is already in patientDetails
        $caseExists = false;
        foreach ($patientDetails as $patient) {
            foreach ($patient['grouped_treatments'] as $treatment) {
                if ($treatment['case_id'] == $caseId) {
                    $caseExists = true;
                    break 2;
                }
            }
        }

        // If case not found, check if it's an arch case
        if (!$caseExists) {
            $caseInfo = $db->fetch("SELECT
                c.id as case_id,
                c.case_number,
                c.scope_type,
                c.case_name,
                c.total_amount as case_total,
                c.discount_amount as case_discount,
                c.paid_amount as case_paid,
                pf.id as folder_id,
                pf.folder_name,
                p.id as patient_id,
                p.full_name as patient_name,
                p.phone as patient_phone
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                INNER JOIN patients p ON pf.patient_id = p.id
                WHERE c.id = :case_id",
                ['case_id' => $caseId]
            );

            // Only add if it's an arch case
            if ($caseInfo && strtolower($caseInfo['scope_type']) === 'arch') {
                $patientId = $caseInfo['patient_id'];

                // Create patient entry if doesn't exist
                if (!isset($patientDetails[$patientId])) {
                    $patientDetails[$patientId] = [
                        'patient_id' => $patientId,
                        'patient_name' => $caseInfo['patient_name'],
                        'patient_phone' => $caseInfo['patient_phone'],
                        'grouped_treatments' => [],
                        'total_treatments' => 0,
                        'case_total' => 0,
                        'case_paid' => 0,
                        'case_discount' => 0,
                        'cases_seen' => [],
                        'daily_payments' => $patientPayments[$patientId] ?? []
                    ];
                }

                // Add the arch case with no treatments (only procedure logs)
                $patientDetails[$patientId]['grouped_treatments'][] = [
                    'treatment_name' => '',
                    'unit_price' => 0,
                    'case_id' => $caseInfo['case_id'],
                    'case_number' => $caseInfo['case_number'],
                    'case_name' => $caseInfo['case_name'],
                    'scope_type' => $caseInfo['scope_type'],
                    'case_total' => $caseInfo['case_total'],
                    'case_paid' => $caseInfo['case_paid'],
                    'case_discount' => $caseInfo['case_discount'],
                    'folder_id' => $caseInfo['folder_id'],
                    'folder_name' => $caseInfo['folder_name'],
                    'treatment_time' => '',
                    'upper_left' => [],
                    'upper_right' => [],
                    'lower_left' => [],
                    'lower_right' => [],
                    'total_quantity' => 0,
                    'total_price' => 0,
                    'case_payments' => $casePayments[$caseId] ?? [],
                    'case_procedure_logs' => $logs
                ];

                // Track case totals
                if (!in_array($caseId, $patientDetails[$patientId]['cases_seen'])) {
                    $patientDetails[$patientId]['cases_seen'][] = $caseId;
                    $patientDetails[$patientId]['case_total'] += $caseInfo['case_total'];
                    $patientDetails[$patientId]['case_paid'] += $caseInfo['case_paid'];
                    $patientDetails[$patientId]['case_discount'] += $caseInfo['case_discount'];
                }
            }
        }
    }

    // Convert grouped_treatments to array
    foreach ($patientDetails as &$patient) {
        $patient['grouped_treatments'] = array_values($patient['grouped_treatments']);
    }
    unset($patient);
    $patientDetails = array_values($patientDetails);

    $reportData[] = [
        'doctor' => $doctor,
        'folders_created' => $foldersCreated['count'] ?? 0,
        'folders_amount' => $foldersCreated['total_amount'] ?? 0,
        'folders_discount' => $foldersCreated['discount_amount'] ?? 0,
        'cases_created' => $casesCreated['count'] ?? 0,
        'cases_amount' => $casesCreated['total_amount'] ?? 0,
        'cases_closed' => $casesClosed['count'] ?? 0,
        'payments_count' => $paymentsReceived['count'] ?? 0,
        'payments_amount' => $paymentsReceived['total_amount'] ?? 0,
        'patients_seen' => $patientsSeen['count'] ?? 0,
        'treatments_count' => $treatmentsDone['count'] ?? 0,
        'treatments_amount' => $treatmentsDone['total_amount'] ?? 0,
        'patient_details' => $patientDetails
    ];
}

// Calculate totals
$totals = [
    'folders_created' => 0,
    'folders_amount' => 0,
    'cases_created' => 0,
    'cases_closed' => 0,
    'payments_count' => 0,
    'payments_amount' => 0,
    'patients_seen' => 0,
    'treatments_count' => 0,
    'treatments_amount' => 0
];

foreach ($reportData as $data) {
    $totals['folders_created'] += $data['folders_created'];
    $totals['folders_amount'] += $data['folders_amount'];
    $totals['cases_created'] += $data['cases_created'];
    $totals['cases_closed'] += $data['cases_closed'];
    $totals['payments_count'] += $data['payments_count'];
    $totals['payments_amount'] += $data['payments_amount'];
    $totals['patients_seen'] += $data['patients_seen'];
    $totals['treatments_count'] += $data['treatments_count'];
    $totals['treatments_amount'] += $data['treatments_amount'];
}

// Format date for display
$displayDate = date('Y/m/d', strtotime($reportDate));
$dayName = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'][date('w', strtotime($reportDate))];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.report-container {
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title h2 {
    margin: 0;
    font-size: 20px;
    color: #1a1a1a;
}

.page-title .icon {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
}

.date-badge {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.page-actions {
    display: flex;
    gap: 8px;
}

.btn-action {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    font-family: inherit;
    text-decoration: none;
}

.btn-print {
    background: #6c757d;
    color: white;
}

.btn-print:hover {
    background: #5a6268;
}

/* Filters */
.filters-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filters-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 11px;
    color: #666;
    font-weight: 600;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    min-width: 150px;
}

.filter-group input:focus,
.filter-group select:focus {
    border-color: #00bcd4;
    outline: none;
}

.btn-filter {
    padding: 8px 16px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-filter:hover {
    background: linear-gradient(135deg, #0097a7, #00838f);
}

.btn-today {
    padding: 8px 16px;
    background: #4caf50;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-today:hover {
    background: #43a047;
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    text-align: center;
    border-top: 4px solid transparent;
}

.summary-card.patients { border-top-color: #2196f3; }
.summary-card.folders { border-top-color: #9c27b0; }
.summary-card.cases { border-top-color: #ff9800; }
.summary-card.treatments { border-top-color: #4caf50; }
.summary-card.payments { border-top-color: #00bcd4; }

.summary-card .value {
    font-size: 28px;
    font-weight: 700;
    color: #1a1a1a;
}

.summary-card .label {
    font-size: 12px;
    color: #888;
    margin-top: 4px;
}

.summary-card .sub-value {
    font-size: 14px;
    font-weight: 600;
    color: #4caf50;
    margin-top: 4px;
}

/* Doctor Report Card */
.doctor-report {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    margin-bottom: 20px;
    overflow: hidden;
}

.doctor-report-header {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: white;
}

.doctor-info h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.doctor-info .specialization {
    font-size: 12px;
    opacity: 0.8;
    margin-top: 2px;
}

.doctor-stats {
    display: flex;
    gap: 20px;
}

.doctor-stat {
    text-align: center;
}

.doctor-stat .value {
    font-size: 20px;
    font-weight: 700;
}

.doctor-stat .label {
    font-size: 10px;
    opacity: 0.8;
}

.doctor-report-body {
    padding: 20px;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.stat-box {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 14px;
    text-align: center;
}

.stat-box .icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    font-size: 16px;
}

.stat-box.folders .icon { background: #f3e5f5; color: #7b1fa2; }
.stat-box.cases .icon { background: #fff3e0; color: #f57c00; }
.stat-box.closed .icon { background: #e8f5e9; color: #388e3c; }
.stat-box.treatments .icon { background: #e3f2fd; color: #1976d2; }
.stat-box.payments .icon { background: #e0f7fa; color: #0097a7; }

.stat-box .value {
    font-size: 18px;
    font-weight: 700;
    color: #333;
}

.stat-box .label {
    font-size: 11px;
    color: #888;
    margin-top: 2px;
}

.stat-box .amount {
    font-size: 12px;
    font-weight: 600;
    color: #4caf50;
    margin-top: 4px;
}

/* Patient Details Table */
.patient-table {
    width: 100%;
    font-size: 12px;
    border-collapse: collapse;
}

.patient-table th {
    text-align: right;
    padding: 12px 10px;
    background: #f8f9fa;
    color: #666;
    font-weight: 600;
    border-bottom: 2px solid #e0e0e0;
}

.patient-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #f0f0f0;
}

.patient-table tr:last-child td {
    border-bottom: none;
}

.patient-table tr:hover {
    background: #fafafa;
}

.patient-table a {
    color: #9c27b0;
    text-decoration: none;
    font-weight: 600;
}

.patient-table a:hover {
    text-decoration: underline;
}

.patient-table .amount {
    font-weight: 600;
}

.patient-table .amount.paid { color: #4caf50; }
.patient-table .amount.remaining { color: #f44336; }
.patient-table .amount.discount { color: #ff9800; }

.patient-table .date {
    color: #666;
    font-size: 11px;
    white-space: nowrap;
}

.patient-table .case-status {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 9px;
    font-weight: 600;
    margin-right: 4px;
}

.patient-table .case-status.open {
    background: #e3f2fd;
    color: #1976d2;
}

.patient-table .case-status.closed {
    background: #f5f5f5;
    color: #9e9e9e;
}

/* Patient Section Styles */
.patient-section {
    background: white;
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.patient-section-header {
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    border-bottom: 1px solid #e0e0e0;
}

.patient-info-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.patient-num {
    width: 28px;
    height: 28px;
    background: #9c27b0;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

.patient-name {
    font-size: 14px;
    color: #333;
}

.patient-phone {
    font-size: 11px;
    color: #888;
    background: white;
    padding: 3px 8px;
    border-radius: 4px;
}

.patient-phone i {
    margin-left: 4px;
    color: #4caf50;
}

.patient-summary {
    display: flex;
    gap: 12px;
    font-size: 12px;
}

.summary-item {
    color: #666;
}

.summary-item strong {
    color: #333;
}

.summary-item.paid {
    color: #4caf50;
}

.summary-item.paid strong {
    color: #2e7d32;
}

/* Case Block */
.case-block {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
}

.case-block:last-child {
    border-bottom: none;
}

.case-block-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.case-id-link {
    color: #1976d2;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.case-id-link:hover {
    text-decoration: underline;
}

.case-id-link i {
    color: #9c27b0;
}

.scope-badge {
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
}

.scope-badge.tooth {
    background: #e3f2fd;
    color: #1976d2;
}

.scope-badge.arch {
    background: #fff3e0;
    color: #f57c00;
}

/* Treatment Work Table */
.treatment-work-table {
    overflow-x: auto;
}

.work-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.work-table thead th {
    background: linear-gradient(135deg, #1976d2, #1565c0);
    color: white;
    padding: 10px 8px;
    text-align: center;
    font-weight: 600;
    font-size: 11px;
    white-space: nowrap;
}

.work-table thead th:first-child {
    border-radius: 8px 0 0 0;
}

.work-table thead th:last-child {
    border-radius: 0 8px 0 0;
}

.work-table tbody td {
    padding: 10px 8px;
    border-bottom: 1px solid #f0f0f0;
    text-align: center;
}

.work-table tbody tr:hover {
    background: #fafafa;
}

.work-table .row-num {
    color: #999;
    font-weight: 600;
    font-size: 11px;
}

.work-table .treatment-name-cell {
    text-align: right;
    font-weight: 600;
    color: #333;
}

/* Quadrant cells with colors like case view */
.work-table .quadrant-cell {
    font-weight: 600;
    font-size: 11px;
}

.work-table .quadrant-cell.ul {
    background: #E8F5E9;
    color: #2e7d32;
}

.work-table .quadrant-cell.ur {
    background: #E3F2FD;
    color: #1565c0;
}

.work-table .quadrant-cell.ll {
    background: #F3E5F5;
    color: #7b1fa2;
}

.work-table .quadrant-cell.lr {
    background: #FFF3E0;
    color: #e65100;
}

.work-table .quadrant-col {
    width: 70px;
}

.work-table .qty-cell {
    font-weight: 600;
    color: #555;
}

.work-table .price-cell {
    text-align: left;
    color: #666;
}

.work-table .total-cell {
    text-align: left;
    font-weight: 700;
    color: #d32f2f;
}

.work-table tfoot td {
    background: #f5f5f5;
    padding: 10px 8px;
    border-top: 2px solid #e0e0e0;
}

.work-table .footer-label {
    text-align: right;
    font-weight: 600;
    color: #555;
}

.work-table .footer-total {
    text-align: left;
    font-weight: 700;
    font-size: 14px;
    color: #d32f2f;
}

/* Case Financial Summary - Horizontal Layout */
.case-financial-summary {
    background: linear-gradient(135deg, #e3f2fd, #f5f5f5);
    border: 1px solid #bbdefb;
    border-radius: 6px;
    padding: 5px 10px;
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.case-financial-summary .financial-header {
    font-size: 10px;
    font-weight: 600;
    color: #1565c0;
    display: flex;
    align-items: center;
    gap: 4px;
    padding-left: 8px;
    border-left: 1px solid #bbdefb;
}

.case-financial-summary .financial-grid {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    flex: 1;
}

.case-financial-summary .financial-row {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
}

.case-financial-summary .financial-row.highlight {
    background: #fff8e1;
    padding: 3px 8px;
    border-radius: 5px;
    margin: -2px 0;
}

.case-financial-summary .fin-label {
    font-size: 9px;
    color: #666;
}

.case-financial-summary .fin-value {
    font-size: 11px;
    font-weight: 700;
    color: #333;
}

.case-financial-summary .fin-value.discount {
    color: #ff9800;
}

.case-financial-summary .fin-value.paid {
    color: #4caf50;
}

.case-financial-summary .fin-value.remaining {
    color: #f44336;
    font-size: 12px;
}

.case-financial-summary .fin-value.progress {
    color: #1976d2;
}

@media (max-width: 600px) {
    .case-financial-summary {
        flex-direction: column;
        align-items: flex-start;
        padding: 4px 8px;
    }

    .case-financial-summary .financial-header {
        border-left: none;
        padding-left: 0;
        padding-bottom: 4px;
        border-bottom: 1px solid #bbdefb;
        width: 100%;
    }

    .case-financial-summary .financial-grid {
        justify-content: space-around;
        width: 100%;
        gap: 8px;
    }
}

/* Case Payments Table (for Arch cases) */
.case-payments-section {
    margin-top: 8px;
    background: #fafafa;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

.case-payments-header {
    background: linear-gradient(135deg, #4caf50, #43a047);
    color: white;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
}

.case-payments-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}

.case-payments-table thead th {
    background: #f5f5f5;
    color: #555;
    padding: 5px 8px;
    text-align: center;
    font-weight: 600;
    border-bottom: 1px solid #e0e0e0;
}

.case-payments-table tbody td {
    padding: 5px 8px;
    text-align: center;
    border-bottom: 1px solid #f0f0f0;
}

.case-payments-table tbody tr:last-child td {
    border-bottom: none;
}

.case-payments-table tbody tr:hover {
    background: #f8f8f8;
}

.case-payments-table .pay-id {
    font-family: var(--app-font-family);
    font-size: 10px;
    color: #666;
}

.case-payments-table .pay-type {
    color: #1976d2;
    font-size: 10px;
}

.case-payments-table .pay-amount {
    font-weight: 700;
    color: #4caf50;
}

.case-payments-table .pay-method {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 600;
}

.case-payments-table .pay-method.cash {
    background: #e8f5e9;
    color: #2e7d32;
}

.case-payments-table .pay-method.card {
    background: #e3f2fd;
    color: #1565c0;
}

.case-payments-table .pay-status {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 600;
    background: #e8f5e9;
    color: #2e7d32;
}

.case-payments-table .pay-date {
    color: #666;
    font-size: 10px;
}

/* Case Procedure Logs (for Arch cases) */
.case-procedure-logs-section {
    margin-top: 8px;
    background: #fafafa;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

.case-procedure-logs-header {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
}

.procedure-logs-list-compact {
    padding: 8px;
}

.procedure-log-item {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    padding: 6px 8px;
    margin-bottom: 6px;
    font-size: 10px;
}

.procedure-log-item:last-child {
    margin-bottom: 0;
}

.log-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}

.log-type-badge-compact {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 9px;
    font-weight: 600;
    background: #e1bee7;
    color: #6a1b9a;
}

.log-type-badge-compact.monthly_adjustment {
    background: #e3f2fd;
    color: #1565c0;
}

.log-type-badge-compact.wire_change {
    background: #e8f5e9;
    color: #2e7d32;
}

.log-type-badge-compact.bracket_repair {
    background: #fff3e0;
    color: #e65100;
}

.log-type-badge-compact.emergency_visit {
    background: #ffebee;
    color: #c62828;
}

.log-type-badge-compact.elastic_change {
    background: #f3e5f5;
    color: #7b1fa2;
}

.log-type-badge-compact.retainer_check {
    background: #e0f2f1;
    color: #00695c;
}

.log-type-badge-compact.other {
    background: #f5f5f5;
    color: #616161;
}

.log-arch-badge-compact {
    padding: 2px 5px;
    border-radius: 3px;
    font-size: 9px;
    font-weight: 600;
}

.log-arch-badge-compact.upper {
    background: #e3f2fd;
    color: #1565c0;
}

.log-arch-badge-compact.lower {
    background: #fff3e0;
    color: #e65100;
}

.log-arch-badge-compact.both {
    background: #e8f5e9;
    color: #2e7d32;
}

.log-item-date {
    font-size: 9px;
    color: #666;
}

.log-item-notes {
    margin-top: 4px;
    padding: 4px 6px;
    background: #f9f9f9;
    border-radius: 3px;
    font-size: 9px;
    color: #555;
    line-height: 1.4;
}

.log-item-footer {
    margin-top: 4px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 9px;
}

.log-item-price {
    color: #4caf50;
    font-weight: 700;
}

/* Daily Payments Block */
.daily-payments-block {
    background: #f8fff8;
    border-top: 1px solid #c8e6c9;
    padding: 12px 16px;
}

.payments-header {
    font-size: 12px;
    font-weight: 600;
    color: #2e7d32;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.payments-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.payment-item {
    background: white;
    border: 1px solid #c8e6c9;
    border-radius: 8px;
    padding: 6px 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11px;
}

.payment-amount {
    font-weight: 700;
    color: #2e7d32;
}

.payment-method {
    background: #e8f5e9;
    color: #388e3c;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
}

.payment-time {
    color: #888;
}

.payment-case {
    color: #1976d2;
    font-size: 10px;
}

/* Grand Totals */
.grand-totals {
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    padding: 16px;
    border-radius: 10px;
    margin-top: 16px;
    display: flex;
    justify-content: center;
    gap: 30px;
}

.total-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.total-label {
    font-size: 13px;
    color: #666;
}

.total-value {
    font-size: 16px;
    font-weight: 700;
    color: #333;
}

.total-item.paid .total-value {
    color: #2e7d32;
}

@media (max-width: 768px) {
    .patient-section-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .work-table {
        font-size: 10px;
    }

    .work-table thead th,
    .work-table tbody td {
        padding: 6px 4px;
    }

    .work-table .quadrant-col {
        width: 50px;
    }

    .grand-totals {
        flex-direction: column;
        gap: 10px;
    }
}

/* Patient Group Styles */
.patient-group {
    background: #fafafa;
    border-radius: 10px;
    margin-bottom: 12px;
    overflow: hidden;
    border: 1px solid #e8e8e8;
}

.patient-group:last-child {
    margin-bottom: 0;
}

.patient-header {
    background: white;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    border-bottom: 1px solid #f0f0f0;
}

.patient-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.patient-info strong {
    font-size: 14px;
    color: #333;
}

.patient-info .phone {
    font-size: 11px;
    color: #888;
    background: #f5f5f5;
    padding: 2px 8px;
    border-radius: 4px;
}

.patient-totals {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
}

.patient-totals .total-label {
    color: #888;
}

.patient-totals .total-value {
    font-weight: 700;
    color: #333;
}

.patient-totals .total-value.paid {
    color: #4caf50;
}

.patient-totals .total-value.remaining {
    color: #f44336;
}

.patient-totals .divider {
    color: #ddd;
}

.treatments-list {
    padding: 8px 12px;
}

.treatment-item {
    display: grid;
    grid-template-columns: 2fr 1.5fr auto auto;
    gap: 12px;
    align-items: center;
    padding: 8px 12px;
    background: white;
    border-radius: 6px;
    margin-bottom: 6px;
    font-size: 12px;
}

.treatment-item:last-child {
    margin-bottom: 0;
}

.treatment-name {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #333;
}

.treatment-name i {
    color: #9c27b0;
    font-size: 11px;
}

.treatment-case {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.treatment-case a {
    color: #1976d2;
    text-decoration: none;
    font-size: 11px;
}

.treatment-case a:hover {
    text-decoration: underline;
}

.treatment-case .folder-name {
    font-size: 10px;
    color: #999;
}

.treatment-price {
    font-weight: 700;
    color: #333;
    text-align: left;
}

.treatment-time {
    color: #888;
    font-size: 11px;
}

@media (max-width: 768px) {
    .patient-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .patient-totals {
        flex-wrap: wrap;
    }

    .treatment-item {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
}

/* No Data */
.no-data {
    text-align: center;
    padding: 40px 20px;
    color: #888;
}

.no-data i {
    font-size: 40px;
    color: #ddd;
    margin-bottom: 12px;
    display: block;
}

/* Collapsible */
.collapse-toggle {
    background: none;
    border: none;
    color: #9c27b0;
    cursor: pointer;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0;
    font-family: inherit;
}

.collapse-toggle:hover {
    text-decoration: underline;
}

.collapse-toggle i {
    transition: transform 0.2s;
}

.collapse-toggle.expanded i {
    transform: rotate(180deg);
}

.collapse-content {
    display: none;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #f0f0f0;
}

.collapse-content.show {
    display: block;
}

/* Print Styles */
@media print {
    .no-print { display: none !important; }
    .doctor-report { break-inside: avoid; }
    .filters-card { display: none; }
    .page-actions { display: none; }
    .collapse-content { display: block !important; }
    body { font-size: 11px; }
}

/* Responsive */
@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }

    .doctor-report-header {
        flex-direction: column;
        gap: 12px;
        text-align: center;
    }

    .filters-row {
        flex-direction: column;
    }

    .filter-group {
        width: 100%;
    }
}
</style>

<div class="report-container">
    <!-- Header -->
    <div class="page-header no-print">
        <div class="page-title">
            <div class="icon"><i class="fas fa-chart-line"></i></div>
            <div>
                <h2>ڕاپۆرتی ڕۆژانەی دکتۆر</h2>
                <small style="color: #888;">ئەنجامەکانی کارکردن</small>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <div class="date-badge">
                <i class="fas fa-calendar-alt"></i>
                <?= $dayName ?> - <?= $displayDate ?>
            </div>
            <div class="page-actions">
                <?php
                $pdfQuery = ['date' => $reportDate];
                if ($doctorFilter) $pdfQuery['doctor'] = $doctorFilter;
                $pdfUrl = APP_URL . '/doctors/report-pdf.php?' . http_build_query($pdfQuery);
                ?>
                <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn-action" style="background:#c62828;color:#fff;border:1px solid #c62828;">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
                <a href="<?= APP_URL ?>/doctors/general-report.php" class="btn-action" style="background:#7c3aed;color:#fff;border:1px solid #7c3aed;">
                    <i class="fas fa-file-invoice-dollar"></i> ڕاپۆرتی گشتی
                </a>
                <button onclick="window.print()" class="btn-action btn-print">
                    <i class="fas fa-print"></i> چاپکردن
                </button>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card no-print">
        <form method="GET" action="">
            <div class="filters-row">
                <div class="filter-group">
                    <label>بەروار</label>
                    <input type="date" name="date" value="<?= $reportDate ?>">
                </div>
                <?php if ($isAdmin): ?>
                <div class="filter-group">
                    <label>دکتۆر</label>
                    <select name="doctor">
                        <option value="">هەموو دکتۆرەکان</option>
                        <?php foreach ($doctors as $doc): ?>
                        <option value="<?= $doc['id'] ?>" <?= $doctorFilter == $doc['id'] ? 'selected' : '' ?>>
                            د. <?= htmlspecialchars($doc['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> پیشاندان
                </button>
                <a href="<?= APP_URL ?>/doctors/report.php" class="btn-today">
                    <i class="fas fa-calendar-day"></i> ئەمڕۆ
                </a>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <?php if (count($reportData) > 1 || empty($doctorFilter)): ?>
    <div class="summary-cards">
        <div class="summary-card patients">
            <div class="value"><?= $totals['patients_seen'] ?></div>
            <div class="label">نەخۆشی سەردانکراو</div>
        </div>
        <div class="summary-card folders">
            <div class="value"><?= $totals['folders_created'] ?></div>
            <div class="label">دۆسیەی نوێ</div>
            <div class="sub-value"><?= number_format($totals['folders_amount']) ?></div>
        </div>
        <div class="summary-card cases">
            <div class="value"><?= $totals['cases_created'] ?></div>
            <div class="label">کەیسی نوێ</div>
            <div class="sub-value"><?= $totals['cases_closed'] ?> داخراو</div>
        </div>
        <div class="summary-card treatments">
            <div class="value"><?= $totals['treatments_count'] ?></div>
            <div class="label">چارەسەر/خزمەتگوزاری</div>
            <div class="sub-value"><?= number_format($totals['treatments_amount']) ?></div>
        </div>
        <div class="summary-card payments">
            <div class="value"><?= number_format($totals['payments_amount']) ?></div>
            <div class="label">پارەی وەرگیراو</div>
            <div class="sub-value"><?= $totals['payments_count'] ?> پارەدان</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Doctor Reports -->
    <?php if (empty($reportData)): ?>
    <div class="no-data">
        <i class="fas fa-chart-line"></i>
        <p>هیچ داتایەک نەدۆزرایەوە بۆ ئەم ڕۆژە</p>
    </div>
    <?php else: ?>
    <?php foreach ($reportData as $report): ?>
    <div class="doctor-report">
        <div class="doctor-report-header">
            <div class="doctor-info">
                <h3>د. <?= htmlspecialchars($report['doctor']['full_name']) ?></h3>
                <?php if ($report['doctor']['specialization']): ?>
                <div class="specialization"><?= htmlspecialchars($report['doctor']['specialization']) ?></div>
                <?php endif; ?>
            </div>
            <div class="doctor-stats">
                <div class="doctor-stat">
                    <div class="value"><?= $report['patients_seen'] ?></div>
                    <div class="label">نەخۆش</div>
                </div>
                <div class="doctor-stat">
                    <div class="value"><?= number_format($report['payments_amount']) ?></div>
                    <div class="label"> وەرگیراو</div>
                </div>
            </div>
        </div>

        <div class="doctor-report-body">
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-box folders">
                    <div class="icon"><i class="fas fa-folder-plus"></i></div>
                    <div class="value"><?= $report['folders_created'] ?></div>
                    <div class="label">دۆسیەی نوێ</div>
                    <?php if ($report['folders_amount'] > 0): ?>
                    <div class="amount"><?= number_format($report['folders_amount']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="stat-box cases">
                    <div class="icon"><i class="fas fa-briefcase-medical"></i></div>
                    <div class="value"><?= $report['cases_created'] ?></div>
                    <div class="label">کەیسی نوێ</div>
                    <?php if ($report['cases_amount'] > 0): ?>
                    <div class="amount"><?= number_format($report['cases_amount']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="stat-box closed">
                    <div class="icon"><i class="fas fa-check-circle"></i></div>
                    <div class="value"><?= $report['cases_closed'] ?></div>
                    <div class="label">کەیسی داخراو</div>
                </div>
                <div class="stat-box treatments">
                    <div class="icon"><i class="fas fa-tooth"></i></div>
                    <div class="value"><?= $report['treatments_count'] ?></div>
                    <div class="label">چارەسەر</div>
                    <?php if ($report['treatments_amount'] > 0): ?>
                    <div class="amount"><?= number_format($report['treatments_amount']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="stat-box payments">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="value"><?= $report['payments_count'] ?></div>
                    <div class="label">پارەدان</div>
                    <?php if ($report['payments_amount'] > 0): ?>
                    <div class="amount"><?= number_format($report['payments_amount']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Treatments Table -->
            <?php if (!empty($report['patient_details'])): ?>
            <?php
                // Calculate totals
                $totalTreatmentsSum = 0;
                $totalDailyPayments = 0;
                foreach ($report['patient_details'] as $patient) {
                    $totalTreatmentsSum += $patient['total_treatments'];
                    foreach ($patient['daily_payments'] as $pay) {
                        $totalDailyPayments += $pay['amount'];
                    }
                }
            ?>
            <button class="collapse-toggle expanded" onclick="toggleCollapse(this)">
                <i class="fas fa-chevron-down"></i>
                <span>چارەسەرەکانی نەخۆشان (<?= count($report['patient_details']) ?> نەخۆش)</span>
            </button>
            <div class="collapse-content show">
                <?php foreach ($report['patient_details'] as $patientIdx => $patient): ?>
                <div class="patient-section">
                    <!-- Patient Header -->
                    <div class="patient-section-header">
                        <div class="patient-info-row">
                            <span class="patient-num"><?= $patientIdx + 1 ?></span>
                            <strong class="patient-name"><?= htmlspecialchars($patient['patient_name']) ?></strong>
                            <?php if ($patient['patient_phone']): ?>
                            <span class="patient-phone"><i class="fas fa-phone"></i> <?= htmlspecialchars($patient['patient_phone']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="patient-summary">
                            <span class="summary-item">کۆی ڕۆژانە: <strong><?= number_format($patient['total_treatments']) ?></strong></span>
                            <?php
                            $dailyPaid = 0;
                            foreach ($patient['daily_payments'] as $pay) {
                                $dailyPaid += $pay['amount'];
                            }
                            if ($dailyPaid > 0): ?>
                            <span class="summary-item paid">دراو ئەمڕۆ: <strong><?= number_format($dailyPaid) ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Treatment Work Table -->
                    <?php if (!empty($patient['grouped_treatments'])): ?>
                    <?php
                    // Group treatments by case
                    $caseGroups = [];
                    foreach ($patient['grouped_treatments'] as $gt) {
                        $caseId = $gt['case_id'];
                        if (!isset($caseGroups[$caseId])) {
                            $caseGroups[$caseId] = [
                                'case_id' => $caseId,
                                'case_number' => $gt['case_number'],
                                'case_name' => $gt['case_name'],
                                'scope_type' => $gt['scope_type'],
                                'treatments' => []
                            ];
                        }
                        $caseGroups[$caseId]['treatments'][] = $gt;
                    }
                    ?>
                    <?php foreach ($caseGroups as $caseGroup):
                        // Get case financial data from first treatment
                        $firstTreat = $caseGroup['treatments'][0];
                        $caseTotalAmount = $firstTreat['case_total'] ?? 0;
                        $casePaidAmount = $firstTreat['case_paid'] ?? 0;
                        $caseDiscountAmount = $firstTreat['case_discount'] ?? 0;
                        $caseRemaining = $caseTotalAmount - $casePaidAmount - $caseDiscountAmount;
                        $caseProgress = $caseTotalAmount > 0 ? round(($casePaidAmount / $caseTotalAmount) * 100) : 0;
                    ?>
                    <div class="case-block">
                        <div class="case-block-header">
                            <a href="<?= APP_URL ?>/cases/view.php?id=<?= $caseGroup['case_id'] ?>" class="case-id-link">
                                <i class="fas fa-briefcase-medical"></i>
                                <?= htmlspecialchars($caseGroup['case_number']) ?>
                            </a>
                            <span class="scope-badge <?= $caseGroup['scope_type'] ?>">
                                <?= $caseGroup['scope_type'] === 'arch' ? 'Arch' : 'Tooth' ?>
                            </span>
                        </div>
                        <?php
                        // Check if there are any treatments with actual data
                        $hasActualTreatments = false;
                        foreach ($caseGroup['treatments'] as $gt) {
                            if (!empty($gt['treatment_name'])) {
                                $hasActualTreatments = true;
                                break;
                            }
                        }
                        ?>
                        <?php if ($hasActualTreatments): ?>
                        <div class="treatment-work-table">
                            <table class="work-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>ناوی چارەسەر</th>
                                        <th class="quadrant-col ul">Upper Left</th>
                                        <th class="quadrant-col ur">Upper Right</th>
                                        <th class="quadrant-col ll">Lower Left</th>
                                        <th class="quadrant-col lr">Lower Right</th>
                                        <th>ژمارە</th>
                                        <th>نرخی یەکە</th>
                                        <th>کۆی نرخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $treatNum = 1; ?>
                                    <?php foreach ($caseGroup['treatments'] as $gt): ?>
                                    <?php if (!empty($gt['treatment_name'])): ?>
                                    <tr>
                                        <td class="row-num"><?= $treatNum++ ?></td>
                                        <td class="treatment-name-cell"><?= htmlspecialchars($gt['treatment_name']) ?></td>
                                        <td class="quadrant-cell ul"><?= !empty($gt['upper_left']) ? implode(',', $gt['upper_left']) : '' ?></td>
                                        <td class="quadrant-cell ur"><?= !empty($gt['upper_right']) ? implode(',', $gt['upper_right']) : '' ?></td>
                                        <td class="quadrant-cell ll"><?= !empty($gt['lower_left']) ? implode(',', $gt['lower_left']) : '' ?></td>
                                        <td class="quadrant-cell lr"><?= !empty($gt['lower_right']) ? implode(',', $gt['lower_right']) : '' ?></td>
                                        <td class="qty-cell"><?= number_format($gt['total_quantity'], 2) ?></td>
                                        <td class="price-cell"><?= number_format($gt['unit_price'], 2) ?></td>
                                        <td class="total-cell"><?= number_format($gt['total_price'], 2) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div style="padding: 12px; text-align: center; color: #999; font-size: 12px; background: #f9f9f9; border-radius: 6px; margin: 8px 0;">
                            <i class="fas fa-info-circle"></i> هیچ چارەسەرێک لەم ڕۆژەدا نییە
                        </div>
                        <?php endif; ?>
                        <!-- Case Financial Summary -->
                        <div class="case-financial-summary">
                            <div class="financial-header">
                                <i class="fas fa-file-invoice-dollar"></i> پوختەی دارایی
                            </div>
                            <div class="financial-grid">
                                <div class="financial-row">
                                    <span class="fin-label">کۆی گشتی</span>
                                    <span class="fin-value"><?= number_format($caseTotalAmount) ?></span>
                                </div>
                                <div class="financial-row">
                                    <span class="fin-label">داشکاندن</span>
                                    <span class="fin-value discount"><?= number_format($caseDiscountAmount) ?></span>
                                </div>
                                <div class="financial-row">
                                    <span class="fin-label">پارەی دراو</span>
                                    <span class="fin-value paid"><?= number_format($casePaidAmount) ?></span>
                                </div>
                                <div class="financial-row highlight">
                                    <span class="fin-label">ماوە</span>
                                    <span class="fin-value remaining"><?= number_format($caseRemaining) ?></span>
                                </div>
                                <div class="financial-row">
                                    <span class="fin-label">پێشکەوتن</span>
                                    <span class="fin-value progress"><?= $caseProgress ?>%</span>
                                </div>
                            </div>
                        </div>
                        <?php
                        // Show payments table for arch cases
                        $casePaymentsList = $firstTreat['case_payments'] ?? [];
                        if ($caseGroup['scope_type'] === 'arch' && !empty($casePaymentsList)):
                        ?>
                        <div class="case-payments-section">
                            <div class="case-payments-header">
                                <i class="fas fa-money-bill-wave"></i>
                                پارەدانەکان
                            </div>
                            <table class="case-payments-table">
                                <thead>
                                    <tr>
                                        <th>ژمارە</th>
                                        <th>جۆڕ</th>
                                        <th>پڕ</th>
                                        <th>شێواز</th>
                                        <th>دۆخ</th>
                                        <th>بەروار</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $statusLabels = [
                                        'completed' => 'پەسەندکراو',
                                        'approved' => 'پەسەندکراو',
                                        'confirmed' => 'پەسەندکراو',
                                        'pending' => 'چاوەڕوان',
                                        'cancelled' => 'هەڵوەشاوەتەوە'
                                    ];
                                    foreach ($casePaymentsList as $casePay):
                                        $statusText = $statusLabels[$casePay['payment_status'] ?? 'completed'] ?? 'پەسەندکراو';
                                    ?>
                                    <tr>
                                        <td class="pay-id">PAY<?= $casePay['payment_id'] ?></td>
                                        <td class="pay-type">نەقد</td>
                                        <td class="pay-amount"><?= number_format($casePay['amount']) ?></td>
                                        <td>
                                            <span class="pay-method <?= $casePay['payment_method'] ?>">
                                                <?= $casePay['payment_method'] === 'cash' ? 'نەقد' : 'کارت' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="pay-status"><?= $statusText ?></span>
                                        </td>
                                        <td class="pay-date"><?= date('Y-m-d H:i', strtotime($casePay['payment_time'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        <?php
                        // Show procedure logs for arch cases
                        $caseProcedureLogsList = $firstTreat['case_procedure_logs'] ?? [];
                        if ($caseGroup['scope_type'] === 'arch' && !empty($caseProcedureLogsList)):
                        ?>
                        <div class="case-procedure-logs-section">
                            <div class="case-procedure-logs-header">
                                <i class="fas fa-clipboard-list"></i>
                                کارە ئەنجامدراوەکان
                            </div>
                            <div class="procedure-logs-list-compact">
                                <?php
                                $typeIcons = [
                                    'monthly_adjustment' => 'fa-sync-alt',
                                    'wire_change' => 'fa-exchange-alt',
                                    'bracket_repair' => 'fa-wrench',
                                    'emergency_visit' => 'fa-exclamation-triangle',
                                    'elastic_change' => 'fa-circle-notch',
                                    'retainer_check' => 'fa-search',
                                    'other' => 'fa-ellipsis-h'
                                ];
                                $archLabels = [
                                    'upper' => 'سەرەوە',
                                    'lower' => 'خوارەوە',
                                    'both' => 'هەردووک'
                                ];
                                foreach ($caseProcedureLogsList as $log):
                                    $icon = $typeIcons[$log['type_code'] ?? 'other'] ?? 'fa-ellipsis-h';
                                    $archLabel = $archLabels[$log['arch_affected']] ?? 'هەردووک';
                                ?>
                                <div class="procedure-log-item">
                                    <div class="log-item-header">
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            <span class="log-type-badge-compact <?= $log['type_code'] ?? 'other' ?>">
                                                <i class="fas <?= $icon ?>"></i>
                                                <?= htmlspecialchars($log['display_name']) ?>
                                            </span>
                                            <span class="log-arch-badge-compact <?= $log['arch_affected'] ?>">
                                                <i class="fas fa-teeth"></i> <?= $archLabel ?>
                                            </span>
                                        </div>
                                        <div class="log-item-date">
                                            <i class="fas fa-calendar"></i> <?= date('Y/m/d', strtotime($log['procedure_date'])) ?>
                                            <?php if ($log['procedure_time']): ?>
                                            <i class="fas fa-clock" style="margin-left: 4px;"></i> <?= date('H:i', strtotime($log['procedure_time'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($log['notes']): ?>
                                    <div class="log-item-notes">
                                        <i class="fas fa-comment"></i> <?= nl2br(htmlspecialchars($log['notes'])) ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($log['price'] > 0): ?>
                                    <div class="log-item-footer">
                                        <span class="log-item-price">
                                            <i class="fas fa-coins"></i> <?= number_format($log['price']) ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Daily Payments for this Patient -->
                    <?php if (!empty($patient['daily_payments'])): ?>
                    <div class="daily-payments-block">
                        <div class="payments-header">
                            <i class="fas fa-money-bill-wave"></i> پارەدانەکانی ئەمڕۆ
                        </div>
                        <div class="payments-list">
                            <?php foreach ($patient['daily_payments'] as $pay): ?>
                            <div class="payment-item">
                                <span class="payment-amount"><?= number_format($pay['amount']) ?></span>
                                <span class="payment-method"><?= $pay['payment_method'] === 'cash' ? 'نەقد' : 'کارت' ?></span>
                                <span class="payment-time"><?= date('H:i', strtotime($pay['payment_time'])) ?></span>
                                <span class="payment-case"><?= htmlspecialchars($pay['case_number']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <!-- Grand Totals -->
                <div class="grand-totals">
                    <div class="total-item">
                        <span class="total-label">کۆی چارەسەرەکان:</span>
                        <span class="total-value"><?= number_format($totalTreatmentsSum) ?></span>
                    </div>
                    <div class="total-item paid">
                        <span class="total-label">کۆی پارەدانی ئەمڕۆ:</span>
                        <span class="total-value"><?= number_format($totalDailyPayments) ?></span>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="no-data" style="padding: 20px;">
                <i class="fas fa-tooth" style="font-size: 30px;"></i>
                <p style="margin: 8px 0 0;">هیچ چارەسەرێک ئەنجام نەدراوە لەم ڕۆژەدا</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function toggleCollapse(btn) {
    const content = btn.nextElementSibling;

    if (content.classList.contains('show')) {
        content.classList.remove('show');
        btn.classList.remove('expanded');
    } else {
        content.classList.add('show');
        btn.classList.add('expanded');
    }
}

// Expand all for print
window.addEventListener('beforeprint', function() {
    document.querySelectorAll('.collapse-content').forEach(el => el.classList.add('show'));
    document.querySelectorAll('.collapse-toggle').forEach(el => el.classList.add('expanded'));
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
