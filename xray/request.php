<?php
/**
 * Create X-Ray Request Page
 * Radiographic Image Request Form - A4 Horizontal Style
 */

// Include config and classes BEFORE any output for redirect handling
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../classes/DentalChart.php';

$auth = new Auth();
require_once __DIR__ . '/../classes/Permission.php';
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.xray_request')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$xrayManager = new XrayManager();
$patientManager = new Patient();

$errors = [];
$success = '';

// Who may name the doctor on the request.
//
// A doctor files under their own name, always: doctor isolation (AccessGuard,
// and every "my patients / my x-rays" query) is keyed on requested_by, so a
// picker here would be a way to file work under a colleague. Everyone else —
// reception, the accountant, the x-ray desk, admin — is filing on someone
// else's behalf and MUST say whose, including for the outside doctors who
// refer patients here just for imaging.
$canPickDoctor = $auth->isAdmin() || !$auth->isDoctor();

// The only reasons an x-ray is given free of charge (کاش نییە / بەخۆڕایی).
// Keyed by a stable ascii token so the posted value never depends on how the
// browser encoded the Kurdish option text, but STORED as the label: nothing
// downstream translates payment_reason — xray/view.php and the accountant's
// screens print the column verbatim — so the column has to hold readable text.
// 'other' is not in this list; it means "the doctor typed their own reason".
$dontCashReasons = [
    'in_work' => 'لەناو ئێش',
    'family'  => 'پلەیەکی خێزان',
];

// Handle form submission BEFORE including header (to allow redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $patientId = intval($_POST['patient_id'] ?? 0);
    $caseId = intval($_POST['case_id'] ?? 0) ?: null;
    // Resolve the doctor. Two kinds, one field on screen:
    //   internal → requested_by IS that doctor's user account.
    //   external → requested_by stays the staff member typing this (the FK
    //              needs a real account and it is the audit trail), and the
    //              referring doctor is named by external_doctor_id.
    $externalDoctorId = null;
    if ($canPickDoctor) {
        $doctorKind = $_POST['doctor_kind'] ?? '';
        $doctorId = 0;
        if ($doctorKind === 'internal') {
            $postedDoctorId = intval($_POST['doctor_id'] ?? 0);
            // Never trust the posted id to be a doctor: it lands in requested_by,
            // which is what grants that account sight of the request.
            $isDoctorUser = $postedDoctorId > 0 && $db->fetch(
                "SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1",
                ['id' => $postedDoctorId]
            );
            if ($isDoctorUser) {
                $doctorId = $postedDoctorId;
            } else {
                $errors['doctor'] = 'دکتۆری ناوەخۆ دروست نییە';
            }
        } elseif ($doctorKind === 'external') {
            $postedExternalId = intval($_POST['external_doctor_id'] ?? 0);
            $externalRow = $postedExternalId > 0 ? $db->fetch(
                "SELECT id FROM xray_external_doctors WHERE id = :id AND is_active = 1",
                ['id' => $postedExternalId]
            ) : null;
            if ($externalRow) {
                $externalDoctorId = (int)$externalRow['id'];
                $doctorId = intval($currentUser['id']);
            } else {
                $errors['doctor'] = 'دکتۆری دەرەوە دروست نییە';
            }
        } else {
            $errors['doctor'] = 'تکایە دکتۆر هەڵبژێرە';
        }
    } else {
        $doctorId = intval($currentUser['id']);
    }

    // Doctor isolation: a doctor may only request an X-ray for a patient they own
    // or were referred, and only against one of their OWN cases. Never trust the
    // posted patient_id / case_id to belong to this doctor.
    if ($auth->isDoctor() && !$auth->isAdmin() && $patientId > 0) {
        AccessGuard::requirePatientGeneral($patientId, false);
        if ($caseId) {
            AccessGuard::requireCaseWork($caseId, false);
        }
    }
    $xrayType = $_POST['xray_type'] ?? '';
    $xrayReason = $_POST['xray_reason'] ?? '';
    $reasonDetails = trim($_POST['reason_details'] ?? '');
    $paymentType = $_POST['payment_type'] ?? 'cash'; // 'cash', 'dont_cash', 'debt'
    // Free-of-charge reason = the chosen label, plus whatever the doctor wrote
    // in the note box. With "هی تر" the note IS the reason (there is no label),
    // so it is the required part; with a fixed choice it is an optional comment
    // appended to the label. An unrecognised key resolves to '' and the
    // dont_cash validation below rejects it — no unvetted string is stored.
    $paymentReasonKey = $_POST['payment_reason_key'] ?? '';
    $paymentReasonNote = trim($_POST['payment_reason_other'] ?? '');
    if ($paymentReasonKey === 'other') {
        $paymentReason = $paymentReasonNote;
    } else {
        $paymentReasonLabel = $dontCashReasons[$paymentReasonKey] ?? '';
        $paymentReason = ($paymentReasonLabel !== '' && $paymentReasonNote !== '')
            ? $paymentReasonLabel . ' — ' . $paymentReasonNote
            : $paymentReasonLabel;
    }
    $amount = floatval($_POST['amount'] ?? 0);
    $priority = $_POST['priority'] ?? 'normal';
    $notes = trim($_POST['notes'] ?? '');
    $fullMouth = isset($_POST['full_mouth']) ? 1 : 0;
    $determineDetails = trim($_POST['determine_details'] ?? '');
    $selectedTeeth = trim($_POST['selected_teeth'] ?? '');
    $determineTeeth = trim($_POST['determine_teeth'] ?? '');

    // Validation
    $allowedPaymentTypes = ['cash', 'dont_cash', 'debt'];
    $allowedXrayTypes = ['panoramic', 'cephalometric', 'periapical', 'cbct'];
    $allowedXrayReasons = ['implant', 'impaction', 'pathology', 'trauma', 'endo', 'other'];
    $allowedPriorities = ['normal', 'urgent'];
    if (!in_array($paymentType, $allowedPaymentTypes, true)) {
        $paymentType = 'cash';
    }
    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'normal';
    }

    if (!$patientId) $errors['patient_id'] = 'Patient is required';
    if (!in_array($xrayType, $allowedXrayTypes, true)) $errors['xray_type'] = 'X-Ray type is required';
    // CBCT needs one of its options (Full Mouth / 2 Segments / 1 Segment × report); other types store none.
    $cbctOption = null;
    if ($xrayType === 'cbct') {
        $cbctOption = (string)($_POST['cbct_option'] ?? '');
        if (!isset(XRAY_CBCT_OPTIONS[$cbctOption])) {
            $errors['cbct_option'] = 'Please choose a CBCT option (Full Mouth / 2 Segments / 1 Segment — with or without report)';
            $cbctOption = null;
        }
    }
    if (!in_array($xrayReason, $allowedXrayReasons, true)) $errors['xray_reason'] = 'Reason is required';
    if ($paymentType === 'dont_cash' && $paymentReason === '') $errors['payment_reason'] = 'Reason is required for Don\'t Cash';

    $initialRequestStatus = 'pending_accountant';
    $initialPaymentStatus = 'pending_payment';
    if ($paymentType === 'dont_cash') {
        $initialRequestStatus = 'pending_xray';
        $initialPaymentStatus = 'not_required';
    } elseif ($paymentType === 'debt') {
        $initialPaymentStatus = 'debt_pending';
    }

    if (empty($errors)) {
        $data = [
            'patient_id' => $patientId,
            'case_id' => $caseId,
            'requested_by' => $doctorId,
            'external_doctor_id' => $externalDoctorId,
            'xray_type' => $xrayType,
            'cbct_option' => $cbctOption,
            'xray_reason' => $xrayReason,
            'reason_details' => $reasonDetails,
            'payment_type' => $paymentType,
            'payment_reason' => $paymentReason,
            'amount' => $amount,
            'original_amount' => $amount,
            'final_amount' => $amount,
            'request_status' => $initialRequestStatus,
            'payment_status' => $initialPaymentStatus,
            'status' => 'pending',
            'priority' => $priority,
            'notes' => $notes,
            'full_mouth' => $fullMouth,
            'determine_details' => $determineDetails,
            'selected_teeth' => $selectedTeeth,
            'determine_teeth' => $determineTeeth
        ];

        $result = $xrayManager->createRequest($data);

        if ($result['success']) {
            $newRequestId = $result['request_id'];

            $xrayManager->logHistory($newRequestId, null, $initialRequestStatus, null, $initialPaymentStatus, 'request_created', "X-Ray request created. Payment type: {$paymentType}", $currentUser['id'], $_SESSION['role'] ?? 'doctor');
            $routeAction = $initialRequestStatus === 'pending_accountant' ? 'sent_to_accountant' : 'sent_to_xray';
            $xrayManager->logHistory($newRequestId, $initialRequestStatus, $initialRequestStatus, $initialPaymentStatus, $initialPaymentStatus, $routeAction, "Initial route: {$initialRequestStatus}", $currentUser['id'], $_SESSION['role'] ?? 'doctor');

            // Redirect based on payment type workflow. The accountant queue is
            // only reachable by accountant/admin — sending a doctor there after a
            // cash/debt submit landed them on 403 with the request silently created
            // (which read as "submit failed" and invited duplicate resubmits).
            if ($paymentType !== 'dont_cash' && ($auth->isAccountant() || $auth->isAdmin())) {
                // Cash and Debt wait for accountant approval first
                header('Location: ' . APP_URL . '/accountant/approval-center.php?tab=xray&q=' . urlencode('X-' . $newRequestId));
            } else {
                // Doctors (and Don't Cash) land on the request itself
                header('Location: ' . APP_URL . '/xray/view.php?id=' . $newRequestId . '&created=1');
            }
            exit;
        } else {
            $errors['general'] = $result['message'];
        }
    }
}

// Now include header (after potential redirects)
$pageTitle = 'X-Ray Request';
$currentPage = 'xray-request';
require_once __DIR__ . '/../includes/header.php';

// The two doctor groups behind the picker. Seeded server-side so the list is
// already usable before anyone types (and still works if the search request
// fails); ajax/xray_doctors.php serves the searches after that.
$internalDoctors = [];
$externalDoctors = [];
if ($canPickDoctor) {
    $internalDoctors = $db->fetchAll(
        "SELECT id, full_name AS name FROM users
         WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name"
    );
    $externalDoctors = $db->fetchAll(
        "SELECT id, name, phone, clinic, specialization FROM xray_external_doctors
         WHERE is_active = 1 ORDER BY name"
    );
}

// Keep the chosen doctor across a failed submit, so a validation error
// elsewhere on the form does not silently blank it.
$postedDoctorKind = $_POST['doctor_kind'] ?? '';
$postedDoctorId = intval($_POST['doctor_id'] ?? 0);
$postedExternalDoctorId = intval($_POST['external_doctor_id'] ?? 0);
$postedDoctorLabel = '';
if ($postedDoctorKind === 'internal' && $postedDoctorId > 0) {
    foreach ($internalDoctors as $d) {
        if ((int)$d['id'] === $postedDoctorId) { $postedDoctorLabel = 'د. ' . $d['name']; break; }
    }
} elseif ($postedDoctorKind === 'external' && $postedExternalDoctorId > 0) {
    foreach ($externalDoctors as $d) {
        if ((int)$d['id'] === $postedExternalDoctorId) { $postedDoctorLabel = $d['name']; break; }
    }
}

// Get patient if provided in URL
$selectedPatient = null;
$patientCases = [];
if (isset($_GET['patient_id'])) {
    $selectedPatient = $patientManager->getById(intval($_GET['patient_id']));
    if ($selectedPatient) {
        // Calculate age
        if (!empty($selectedPatient['date_of_birth'])) {
            $birthDate = new DateTime($selectedPatient['date_of_birth']);
            $today = new DateTime();
            $selectedPatient['age'] = $today->diff($birthDate)->y;
        } else {
            $selectedPatient['age'] = null;
        }
        // Get patient's open cases
        $sql = "SELECT c.id, c.case_number, c.case_name
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.patient_id = :patient_id AND c.status != 'closed'
                ORDER BY c.created_at DESC";
        $patientCases = $db->fetchAll($sql, ['patient_id' => $selectedPatient['id']]);
    }
}

// Pre-select case if provided
$selectedCaseId = intval($_GET['case_id'] ?? 0);

// Periapical chart tooth sets. An x-ray request is not always tied to a case,
// so the adult/child choice lives on the form rather than in the database; it
// starts from the patient's age when we know it.
$xrayChart = new DentalChart();
$xrayToothSets = [
    // Ordered to match the existing chart layout: outer-to-midline on the left
    // group, midline-to-outer on the right group.
    'permanent' => [
        'upper_left'  => range(28, 21), 'upper_right' => range(11, 18),
        'lower_left'  => range(38, 31), 'lower_right' => range(41, 48),
    ],
    'primary' => [
        'upper_left'  => range(65, 61), 'upper_right' => range(51, 55),
        'lower_left'  => range(75, 71), 'lower_right' => range(81, 85),
    ],
];
$xrayPatientAge = $selectedPatient['age'] ?? null;
$xrayDefaultDentition = ($xrayPatientAge !== null && $xrayPatientAge < 13) ? 'primary' : 'permanent';

// X-Ray Types in English
$xrayTypes = [
    'panoramic' => 'Panoramic',
    'cephalometric' => 'Cephalometric',
    'periapical' => 'Periapical',
    'cbct' => 'CBCT (3D)'
];

// Each type's own price (its 'single' row, set on admin/xray-pricing.php),
// shown on the type buttons below.
$xrayTypePrices = [];
$cbctOptionPrices = []; // option key => price, for the CBCT choices
$xrayPriceMap = [];     // type => [mode => price] — the whole table, so the amount box fills instantly client-side
try {
    foreach ($db->fetchAll("SELECT xray_type, pricing_mode, price FROM xray_pricing WHERE is_active = 1") as $r) {
        $xrayPriceMap[$r['xray_type']][$r['pricing_mode']] = (float)$r['price'];
        if ($r['pricing_mode'] === 'single') {
            $xrayTypePrices[$r['xray_type']] = (float)$r['price'];
        } elseif (strpos($r['pricing_mode'], 'cbct_') === 0) {
            $cbctOptionPrices[substr($r['pricing_mode'], 5)] = (float)$r['price'];
        }
    }
} catch (Exception $e) {
    error_log('xray type prices load failed: ' . $e->getMessage());
}

// X-Ray Reasons in English
$xrayReasons = [
    'implant' => 'Dental Implant',
    'impaction' => 'Impactions',
    'pathology' => 'Pathology',
    'trauma' => 'Maxillofacial Trauma',
    'endo' => 'Endo',
    'other' => 'Other'
];
?>

<style>
/* A4 Horizontal Paper Style Form */
.a4-paper {
    background: white;
    max-width: 1100px;
    margin: 0 auto;
    padding: 30px 40px;
    border-radius: 8px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.1);
    border: 1px solid #e0e0e0;
}

/* Form Header */
.form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 20px;
    border-bottom: 3px solid #1a237e;
    margin-bottom: 25px;
}

.form-header-left h1 {
    font-size: 22px;
    font-weight: 700;
    color: #1a237e;
    margin: 0 0 5px 0;
}

.form-header-left .subtitle {
    font-size: 13px;
    color: #666;
}

.form-header-right {
    text-align: right;
}

.form-header-right .date-box {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #e8eaf6, #c5cae9);
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #1a237e;
}

/* Section Styles */
.form-section {
    margin-bottom: 25px;
}

.section-title {
    font-size: 14px;
    font-weight: 700;
    color: #1a237e;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    font-size: 16px;
}

/* Row Layout */
.form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}

.form-row .col {
    flex: 1;
}

.form-row .col-2 {
    flex: 2;
}

.form-row .col-3 {
    flex: 3;
}

.form-row .col-auto {
    flex: 0 0 auto;
}

/* Field Styles */
.field-group {
    margin-bottom: 12px;
}

.field-label {
    font-size: 11px;
    font-weight: 600;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    display: block;
}

.field-value {
    padding: 10px 14px;
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 14px;
    color: #333;
    min-height: 42px;
    display: flex;
    align-items: center;
}

.field-value i {
    margin-right: 10px;
    color: #1a237e;
}

/* Input Fields */
.form-input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-input:focus {
    outline: none;
    border-color: #1a237e;
    box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
}

/* Patient Search */
.patient-search-wrapper {
    position: relative;
}

.patient-search-wrapper .search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
}

.patient-search-wrapper input {
    padding-left: 38px;
}

.patient-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    z-index: 100;
    max-height: 250px;
    overflow-y: auto;
    display: none;
}

.patient-result-item {
    padding: 12px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}

.patient-result-item:hover {
    background: #f5f7ff;
}

.patient-result-item .name {
    font-weight: 600;
    font-size: 14px;
    color: #333;
}

.patient-result-item .meta {
    font-size: 12px;
    color: #888;
}

/* Selected Patient Badge */
.selected-patient {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    border: 2px solid #4caf50;
    border-radius: 8px;
}

.selected-patient .avatar {
    width: 40px;
    height: 40px;
    background: #4caf50;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
}

.selected-patient .info .name {
    font-weight: 700;
    font-size: 15px;
    color: #2e7d32;
}

.selected-patient .info .age {
    font-size: 13px;
    color: #ff9800;
    font-weight: 600;
}

.selected-patient .change-btn {
    margin-left: auto;
    padding: 6px 12px;
    font-size: 12px;
    background: white;
    border: 1px solid #4caf50;
    border-radius: 6px;
    color: #2e7d32;
    cursor: pointer;
    transition: all 0.2s;
}

.selected-patient .change-btn:hover {
    background: #4caf50;
    color: white;
}

/* ── Doctor picker (in-house + outside referring doctors) ───────────────── */
.doc-pick { position: relative; }

.doc-search-wrap { position: relative; }
.doc-search-wrap .doc-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    pointer-events: none;
}
.doc-search-wrap input { padding-left: 38px; }

.doc-panel {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    z-index: 120;
    max-height: 300px;
    overflow-y: auto;
    display: none;
}
.doc-panel.open { display: block; }

/* Group headers are what makes "in-house vs outside" readable at a glance */
.doc-group-title {
    position: sticky;
    top: 0;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 12px;
    background: #f4f6fb;
    border-bottom: 1px solid #e4e8f3;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .3px;
    color: #3949ab;
}
.doc-group-title.is-external { background: #fff5e9; border-bottom-color: #ffe0b8; color: #b25e09; }
.doc-group-title .doc-group-count {
    margin-inline-start: auto;
    padding: 1px 7px;
    border-radius: 999px;
    background: #fff;
    font-size: 10px;
    font-weight: 700;
}

.doc-item {
    padding: 9px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f2f2f2;
    transition: background .15s;
}
.doc-item:hover, .doc-item.is-active { background: #f5f7ff; }
.doc-item .doc-item-name { font-weight: 600; font-size: 13.5px; color: #333; }
.doc-item .doc-item-meta { font-size: 11.5px; color: #8a8a8a; margin-top: 2px; }
.doc-item.is-external:hover, .doc-item.is-external.is-active { background: #fff8f0; }

.doc-empty { padding: 14px 12px; font-size: 12.5px; color: #999; text-align: center; }

.doc-panel-add {
    position: sticky;
    bottom: 0;
    padding: 9px 12px;
    background: #fff;
    border-top: 1px solid #e8e8e8;
    color: #1a237e;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}
.doc-panel-add:hover { background: #eef1fb; }

/* The chosen doctor, replacing the search box */
.doc-chip {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    border: 2px solid #3949ab;
    background: linear-gradient(135deg, #e8eaf6, #c5cae9);
}
.doc-chip[data-kind="external"] {
    border-color: #ff9800;
    background: linear-gradient(135deg, #fff3e0, #ffe0b2);
}
.doc-chip > i { color: #1a237e; }
.doc-chip[data-kind="external"] > i { color: #e65100; }
.doc-chip .doc-chip-name { font-weight: 700; font-size: 14px; color: #1a237e; }
.doc-chip[data-kind="external"] .doc-chip-name { color: #b25e09; }
.doc-chip .doc-chip-kind {
    padding: 2px 9px;
    border-radius: 999px;
    background: rgba(255,255,255,.75);
    font-size: 10.5px;
    font-weight: 700;
    color: #3949ab;
}
.doc-chip[data-kind="external"] .doc-chip-kind { color: #b25e09; }
.doc-chip .doc-chip-clear {
    margin-inline-start: auto;
    padding: 5px 11px;
    background: #fff;
    border: 1px solid #c5cae9;
    border-radius: 6px;
    color: #3949ab;
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
}
.doc-chip .doc-chip-clear:hover { background: #3949ab; color: #fff; border-color: #3949ab; }
.doc-chip[data-kind="external"] .doc-chip-clear { border-color: #ffcc80; color: #b25e09; }
.doc-chip[data-kind="external"] .doc-chip-clear:hover { background: #e65100; color: #fff; border-color: #e65100; }

/* Inline "add an outside doctor" */
.doc-add {
    margin-top: 10px;
    padding: 14px;
    background: linear-gradient(135deg, #fff8f0, #ffefdb);
    border: 1px solid #ffd9a8;
    border-radius: 10px;
}
.doc-add-title { font-size: 12.5px; font-weight: 700; color: #b25e09; margin-bottom: 10px; }
.doc-add-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.doc-add-grid input { font-size: 13px; }
.doc-add-actions { display: flex; align-items: center; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.doc-btn {
    padding: 8px 14px;
    border-radius: 7px;
    border: none;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}
.doc-btn-save { background: linear-gradient(135deg, #f57c00, #e65100); color: #fff; }
.doc-btn-save:hover { box-shadow: 0 3px 10px rgba(230, 81, 0, .3); }
.doc-btn-save:disabled { opacity: .6; cursor: default; box-shadow: none; }
.doc-btn-cancel { background: #fff; border: 1px solid #e0cdb4; color: #8a6a44; }
.doc-add-msg { font-size: 12px; font-weight: 600; }
.doc-add-msg.is-error { color: #c62828; }
.doc-add-msg.is-ok { color: #2e7d32; }

@media (max-width: 700px) {
    .doc-add-grid { grid-template-columns: 1fr; }
}

/* Option Buttons (Radio/Checkbox) */
.options-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.option-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: #f8f9fa;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 13px;
    font-weight: 500;
}

.option-btn:hover {
    border-color: #90caf9;
    background: #f0f4ff;
}

.option-btn.selected {
    border-color: #1a237e;
    background: linear-gradient(135deg, #e8eaf6, #c5cae9);
    color: #1a237e;
}

.option-btn input {
    width: 16px;
    height: 16px;
    accent-color: #1a237e;
}

.cbct-options {
    margin: 0 0 12px;
    padding: 12px;
    background: linear-gradient(135deg, #f7f0fb, #efe4f7);
    border: 1px solid #d9c2e8;
    border-radius: 10px;
}
.cbct-options-title { font-weight: 700; color: #6a1b9a; margin-bottom: 8px; direction: ltr; text-align: left; }
.cbct-options-title span { font-weight: 600; color: #8e6aa8; }
.cbct-row {
    display: grid;
    grid-template-columns: 130px 1fr 1fr;
    gap: 8px;
    align-items: stretch;
    margin-bottom: 6px;
}
.cbct-row:last-child { margin-bottom: 0; }
.cbct-scope {
    display: flex; flex-direction: column; justify-content: center;
    font-weight: 700; color: #4a148c; font-size: 13px; direction: ltr; text-align: left;
}
.cbct-scope small { font-weight: 600; color: #8e6aa8; direction: rtl; text-align: left; }
.cbct-choice {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    background: #fff;
    border: 2px solid #e0d4ea;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    direction: ltr;
}
.cbct-choice:hover { border-color: #b388d6; }
.cbct-choice:has(input:checked) { border-color: #6a1b9a; background: #f3e5f5; }
.cbct-choice input { width: 16px; height: 16px; accent-color: #6a1b9a; }
.cbct-choice-label small { color: #8e6aa8; margin-left: 4px; }
.cbct-choice-price {
    margin-left: auto;
    padding: 2px 8px; border-radius: 999px;
    background: #e6f6ea; color: #137a37;
    font-size: 11px; font-weight: 700; white-space: nowrap;
}
@media (max-width: 700px) {
    .cbct-row { grid-template-columns: 1fr 1fr; }
    .cbct-scope { grid-column: 1 / -1; }
}

.option-btn .type-price {
    margin-inline-start: auto;
    padding: 2px 8px;
    border-radius: 999px;
    background: #e6f6ea;
    color: #137a37;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

/* Type Options - 4 in a row */
.type-options {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

@media (max-width: 700px) {
    .type-options {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Reason Options - 5 in a row */
.reason-options {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
}

@media (max-width: 800px) {
    .reason-options {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Payment Options */
.payment-box {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 15px 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.payment-type-btns {
    display: flex;
    gap: 10px;
}

.payment-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 13px;
    font-weight: 600;
}

.payment-btn.cash:hover, .payment-btn.cash.selected {
    border-color: #4caf50;
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    color: #2e7d32;
}

.payment-btn.no-cash:hover, .payment-btn.no-cash.selected {
    border-color: #ff9800;
    background: linear-gradient(135deg, #fff3e0, #ffe0b2);
    color: #e65100;
}

.payment-btn.debt:hover, .payment-btn.debt.selected {
    border-color: #c62828;
    background: linear-gradient(135deg, #ffebee, #ffcdd2);
    color: #c62828;
}

.payment-btn input {
    width: 16px;
    height: 16px;
}

.payment-btn.cash input { accent-color: #4caf50; }
.payment-btn.no-cash input { accent-color: #ff9800; }
.payment-btn.debt input { accent-color: #c62828; }

/* ── Payment choice: driven by the checked radio, not by a script ──────────
   The selected-payment highlight and the "reason" block below used to depend
   on a JS change handler attached near the END of this page's single large
   <script>. Any earlier runtime error in that script stops the handler from
   ever being attached — and then the doctor can pick "کاش نییە" (the radio
   still checks, natively, with its orange accent-color above) while the button
   stays unhighlighted and the required reason field never appears. That is the
   reported symptom. Both are CSS now, so they hold with the script broken.

   The reason block fails OPEN: a browser without :has() support shows it for
   every payment type, which is merely redundant. It can never end up hidden
   while it is the thing the server requires. */
.payment-btn.cash:has(input:checked) {
    border-color: #4caf50;
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    color: #2e7d32;
}
.payment-btn.no-cash:has(input:checked) {
    border-color: #ff9800;
    background: linear-gradient(135deg, #fff3e0, #ffe0b2);
    color: #e65100;
}
.payment-btn.debt:has(input:checked) {
    border-color: #c62828;
    background: linear-gradient(135deg, #ffebee, #ffcdd2);
    color: #c62828;
}

#payment_reason_group { display: flex; }
.payment-box:has(input[name="payment_type"][value="cash"]:checked) ~ #payment_reason_group,
.payment-box:has(input[name="payment_type"][value="debt"]:checked) ~ #payment_reason_group {
    display: none;
}

.amount-field {
    display: flex;
    align-items: center;
    gap: 10px;
}

.amount-field label {
    font-size: 13px;
    font-weight: 600;
    color: #666;
}

.amount-field input {
    width: 140px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    text-align: right;
}

.amount-field .currency {
    font-size: 13px;
    color: #888;
}

/* Determine Section */
.determine-box {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 12px 16px;
    background: #f5f5f5;
    border-radius: 8px;
    margin-top: 15px;
}

.determine-box label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.determine-box input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #1a237e;
}

.determine-box input[type="text"] {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 13px;
}

.or-divider {
    color: #999;
    font-weight: 500;
    font-size: 12px;
}

/* Dental Chart */
.dental-chart-box {
    display: none;
    margin-top: 15px;
    padding: 16px;
    background: linear-gradient(135deg, #f0f4ff, #e8eaf6);
    border-radius: 10px;
    border: 1px solid #c5cae9;
}

.dental-chart-box.visible {
    display: block;
}

.chart-head {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.chart-title {
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: #1a237e;
}

/* Adult / child tooth set switch */
.dentition-switch {
    display: inline-flex;
    padding: 2px;
    gap: 2px;
    background: #dfe4f5;
    border: 1px solid #c5cae9;
    border-radius: 999px;
}
.dentition-opt {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 11px;
    border: none;
    border-radius: 999px;
    background: transparent;
    color: #4a5390;
    font-family: inherit;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s, color .15s, box-shadow .15s;
}
.dentition-opt i { font-size: 10px; }
.dentition-opt:hover:not(.is-active) { background: #ccd3ec; }
.dentition-opt.is-active {
    background: #fff;
    color: #1a237e;
    box-shadow: 0 1px 3px rgba(26, 35, 126, 0.18);
}
.dentition-pane[hidden] { display: none; }

.jaw-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 4px;
    margin: 8px 0;
}

.jaw-label {
    font-size: 10px;
    font-weight: 600;
    color: #666;
    width: 45px;
    text-align: center;
}

.teeth-group {
    display: flex;
    gap: 3px;
    padding: 6px 8px;
    background: white;
    border-radius: 6px;
}

.center-line {
    width: 3px;
    height: 36px;
    background: linear-gradient(to bottom, #1a237e, #3949ab);
    border-radius: 2px;
}

.tooth-btn {
    width: 28px;
    height: 36px;
    border: 2px solid #ccc;
    border-radius: 4px;
    background: white;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    color: #555;
    transition: all 0.15s;
}

.tooth-btn:hover {
    border-color: #1a237e;
    background: #f0f4ff;
    transform: scale(1.1);
}

.tooth-btn.selected {
    background: linear-gradient(135deg, #1a237e, #3949ab);
    border-color: #1a237e;
    color: white;
}

.tooth-btn .num {
    font-size: 11px;
}

.selected-teeth-box {
    margin-top: 12px;
    padding: 10px 14px;
    background: white;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.selected-teeth-box .label {
    font-size: 12px;
    font-weight: 600;
    color: #666;
}

.tooth-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: #e8eaf6;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    color: #1a237e;
}

.tooth-tag .remove {
    cursor: pointer;
    color: #f44336;
    font-weight: bold;
}

.clear-btn {
    margin-left: auto;
    padding: 6px 12px;
    background: #ffebee;
    border: 1px solid #ffcdd2;
    border-radius: 6px;
    color: #c62828;
    font-size: 12px;
    cursor: pointer;
}

/* Determine Chart */
.determine-chart-box {
    display: none;
    margin-top: 15px;
    padding: 16px;
    background: linear-gradient(135deg, #fff8e1, #ffecb3);
    border-radius: 10px;
    border: 2px solid #ffc107;
}

.determine-chart-box.visible {
    display: block;
}

.det-chart-title {
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: #f57c00;
    margin-bottom: 12px;
}

.det-tooth-btn {
    width: 28px;
    height: 28px;
    border: 2px solid #bdbdbd;
    border-radius: 50%;
    background: white;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    color: #555;
    transition: all 0.15s;
}

.det-tooth-btn:hover {
    border-color: #ff9800;
    background: #fff8e1;
    transform: scale(1.15);
}

.det-tooth-btn.selected {
    background: linear-gradient(135deg, #ff9800, #f57c00);
    border-color: #e65100;
    color: white;
}

.det-center-line {
    width: 3px;
    height: 32px;
    background: linear-gradient(to bottom, #ff9800, #f57c00);
    border-radius: 2px;
}

/* Notes Field */
.notes-field textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    resize: vertical;
    min-height: 60px;
}

/* Form Footer */
.form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px solid #e0e0e0;
}

.form-footer .btn {
    padding: 12px 28px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.form-footer .btn-cancel {
    background: #f5f5f5;
    border: 1px solid #ddd;
    color: #666;
}

.form-footer .btn-cancel:hover {
    background: #eee;
}

.form-footer .btn-submit {
    background: linear-gradient(135deg, #1a237e, #3949ab);
    border: none;
    color: white;
}

.form-footer .btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(26, 35, 126, 0.3);
}

/* Responsive */
@media (max-width: 768px) {
    .a4-paper {
        padding: 20px;
        margin: 10px;
    }

    .form-row {
        flex-direction: column;
        gap: 10px;
    }

    .form-header {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }

    .form-header-right {
        text-align: center;
    }

    .payment-box {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
}
</style>

<div class="a4-paper">
    <?php if (!empty($errors['general'])): ?>
    <div class="alert alert-error" style="margin-bottom: 20px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>

    <form method="POST" action="" id="xrayRequestForm">
        <!-- Form Header -->
        <div class="form-header">
            <div class="form-header-left">
                <h1><i class="fas fa-x-ray"></i> Radiographic Image Request</h1>
                <div class="subtitle">X-Ray Department Form</div>
            </div>
            <div class="form-header-right">
                <div class="date-box">
                    <i class="fas fa-calendar-alt"></i>
                    <span><?= date('Y-m-d') ?></span>
                </div>
            </div>
        </div>

        <!-- Patient & Doctor Section -->
        <div class="form-section">
            <div class="section-title"><i class="fas fa-user-injured"></i> Patient Information</div>

            <div class="form-row">
                <div class="col-2">
                    <div class="field-label">Patient Name *</div>
                    <?php if ($selectedPatient): ?>
                    <div class="selected-patient">
                        <div class="avatar"><?= mb_substr($selectedPatient['full_name'], 0, 1) ?></div>
                        <div class="info">
                            <div class="name"><?= htmlspecialchars($selectedPatient['full_name']) ?></div>
                            <div class="age"><?= $selectedPatient['age'] ? $selectedPatient['age'] . ' years old' : '' ?></div>
                        </div>
                        <button type="button" class="change-btn" onclick="clearPatient()">
                            <i class="fas fa-exchange-alt"></i> Change
                        </button>
                    </div>
                    <input type="hidden" name="patient_id" id="patient_id" value="<?= $selectedPatient['id'] ?>">
                    <div id="patient_search_wrapper" style="display: none;">
                    <?php else: ?>
                    <input type="hidden" name="patient_id" id="patient_id" value="">
                    <div id="patient_search_wrapper">
                    <?php endif; ?>
                        <div class="patient-search-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="patient_search" class="form-input"
                                   placeholder="Search by name or phone..." autocomplete="off">
                            <div id="patient_results" class="patient-results"></div>
                        </div>
                    </div>
                    <?php if (isset($errors['patient_id'])): ?>
                    <span class="form-error" style="color: #f44336; font-size: 12px;"><?= $errors['patient_id'] ?></span>
                    <?php endif; ?>
                </div>

                <div class="col">
                    <div class="field-label">Age</div>
                    <div class="field-value">
                        <i class="fas fa-birthday-cake"></i>
                        <span id="age_text"><?= $selectedPatient && $selectedPatient['age'] ? $selectedPatient['age'] . ' years' : '--' ?></span>
                    </div>
                </div>

                <div class="col">
                    <div class="field-label">Phone</div>
                    <div class="field-value">
                        <i class="fas fa-phone"></i>
                        <span id="phone_text" style="direction: ltr;"><?= $selectedPatient && trim((string)($selectedPatient['phone'] ?? '')) !== '' ? htmlspecialchars($selectedPatient['phone']) : '--' ?></span>
                    </div>
                </div>

                <?php if (!$canPickDoctor): ?>
                <div class="col">
                    <div class="field-label">Doctor</div>
                    <div class="field-value">
                        <i class="fas fa-user-md"></i>
                        <span>Dr. <?= htmlspecialchars($currentUser['full_name']) ?></span>
                    </div>
                    <input type="hidden" name="doctor_id" value="<?= $currentUser['id'] ?>">
                </div>
                <?php endif; ?>
            </div>

            <?php if ($canPickDoctor): ?>
            <!-- Doctor: in-house (has an account here) or an outside referring doctor.
                 Starts EMPTY and is required — see $canPickDoctor above. -->
            <div class="form-row">
                <div class="col-2">
                    <div class="field-label">
                        Doctor — دکتۆر <span style="color: #f44336;">*</span>
                    </div>
                    <div class="doc-pick" id="docPick">
                        <div class="doc-chip" id="docChip" hidden>
                            <i class="fas fa-user-md"></i>
                            <span class="doc-chip-name" id="docChipName"></span>
                            <span class="doc-chip-kind" id="docChipKind"></span>
                            <button type="button" class="doc-chip-clear" id="docChipClear" title="گۆڕینی دکتۆر">
                                <i class="fas fa-times"></i> گۆڕین
                            </button>
                        </div>

                        <div class="doc-search-wrap" id="docSearchWrap">
                            <i class="fas fa-search doc-search-icon"></i>
                            <input type="text" id="docSearch" class="form-input" autocomplete="off"
                                   placeholder="ناوی دکتۆر بنووسە — ناوەخۆ یان دەرەوە...">
                            <div class="doc-panel" id="docPanel"></div>
                        </div>

                        <!-- Register an outside doctor without leaving the form -->
                        <div class="doc-add" id="docAdd" hidden>
                            <div class="doc-add-title">
                                <i class="fas fa-user-plus"></i> زیادکردنی دکتۆری دەرەوە
                            </div>
                            <div class="doc-add-grid">
                                <input type="text" id="docNewName" class="form-input" placeholder="ناوی دکتۆر *">
                                <input type="text" id="docNewPhone" class="form-input" placeholder="مۆبایل" style="direction: ltr;">
                                <input type="text" id="docNewClinic" class="form-input" placeholder="کلینیک / شوێن">
                                <input type="text" id="docNewSpec" class="form-input" placeholder="پسپۆڕی">
                            </div>
                            <div class="doc-add-actions">
                                <button type="button" class="doc-btn doc-btn-save" id="docNewSave">
                                    <i class="fas fa-save"></i> زیادکردن و هەڵبژاردن
                                </button>
                                <button type="button" class="doc-btn doc-btn-cancel" id="docNewCancel">
                                    پاشگەزبوونەوە
                                </button>
                                <span class="doc-add-msg" id="docNewMsg"></span>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="doctor_kind" id="doctor_kind" value="<?= htmlspecialchars($postedDoctorLabel !== '' ? $postedDoctorKind : '') ?>">
                    <input type="hidden" name="doctor_id" id="doctor_id" value="<?= $postedDoctorKind === 'internal' && $postedDoctorLabel !== '' ? $postedDoctorId : '' ?>">
                    <input type="hidden" name="external_doctor_id" id="external_doctor_id" value="<?= $postedDoctorKind === 'external' && $postedDoctorLabel !== '' ? $postedExternalDoctorId : '' ?>">

                    <?php if (isset($errors['doctor'])): ?>
                    <span class="form-error" style="color: #f44336; font-size: 12px;"><?= htmlspecialchars($errors['doctor']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="col"></div>
            </div>
            <?php endif; ?>

            <!-- Case Selection (Optional) -->
            <div class="form-row" id="case_group" style="<?= $selectedPatient ? '' : 'display: none;' ?>">
                <div class="col">
                    <div class="field-label">Case (Optional)</div>
                    <select name="case_id" id="case_id" class="form-input">
                        <option value="">-- No Case --</option>
                        <?php foreach ($patientCases as $case): ?>
                        <option value="<?= $case['id'] ?>" <?= $selectedCaseId == $case['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($case['case_number'] . ($case['case_name'] ? ' - ' . $case['case_name'] : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- X-Ray Type Section -->
        <div class="form-section">
            <div class="section-title"><i class="fas fa-x-ray"></i> Radiographic Image Type</div>

            <?php if (isset($errors['xray_type'])): ?>
            <div class="alert alert-error" style="margin-bottom: 12px;"><i class="fas fa-exclamation-circle"></i> <?= $errors['xray_type'] ?></div>
            <?php endif; ?>

            <div class="type-options">
                <?php foreach ($xrayTypes as $key => $label): ?>
                <label class="option-btn" data-type="<?= $key ?>">
                    <input type="radio" name="xray_type" value="<?= $key ?>"
                           <?= ($_POST['xray_type'] ?? '') === $key ? 'checked' : '' ?> required>
                    <span><?= $label ?></span>
                    <?php if (isset($xrayTypePrices[$key])): ?>
                    <span class="type-price"><?= number_format($xrayTypePrices[$key]) ?></span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>
            <!-- CBCT (3D) options — shown only when CBCT is chosen -->
            <?php $postedCbct = $_POST['cbct_option'] ?? ''; ?>
            <div class="cbct-options" id="cbctOptionsBox" hidden>
                <div class="cbct-options-title"><i class="fas fa-cube"></i> CBCT (3D) Option <span>— بژاردەی CBCT</span></div>
                <?php if (isset($errors['cbct_option'])): ?>
                <div class="alert alert-error" style="margin-bottom:8px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errors['cbct_option']) ?></div>
                <?php endif; ?>
                <?php foreach (XRAY_CBCT_SCOPES as $scopeKey => $scope): ?>
                <div class="cbct-row">
                    <div class="cbct-scope"><?= htmlspecialchars($scope['en']) ?><small><?= htmlspecialchars($scope['ku']) ?></small></div>
                    <?php foreach (['report' => ['With Report', 'بە ڕاپۆرت', 'fa-file-medical'], 'no_report' => ['No Report', 'بێ ڕاپۆرت', 'fa-file']] as $rep => $repLabel):
                        $optKey = $scopeKey . '_' . $rep; ?>
                    <label class="cbct-choice">
                        <input type="radio" name="cbct_option" value="<?= $optKey ?>" <?= $postedCbct === $optKey ? 'checked' : '' ?> disabled>
                        <span class="cbct-choice-label"><i class="fas <?= $repLabel[2] ?>"></i> <?= $repLabel[0] ?> <small><?= $repLabel[1] ?></small></span>
                        <span class="cbct-choice-price"><?= isset($cbctOptionPrices[$optKey]) ? number_format($cbctOptionPrices[$optKey]) : '—' ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($auth->isAdmin() && (count($xrayTypePrices) + (!isset($xrayTypePrices['cbct']) && $cbctOptionPrices ? 1 : 0)) < count($xrayTypes)): ?>
            <div style="margin:-4px 0 10px; font-size:12px; color:#b25e09;">
                <i class="fas fa-exclamation-triangle"></i>
                هەندێک جۆر نرخیان بۆ دانەنراوە —
                <a href="<?= APP_URL ?>/admin/xray-pricing.php" target="_blank">دانانی نرخی هەر جۆرێک</a>
            </div>
            <?php endif; ?>

            <!-- Determine Options -->
            <div class="determine-box">
                <label>
                    <input type="checkbox" id="fullMouthCheckbox" name="full_mouth" value="1" <?= !empty($_POST['full_mouth']) ? 'checked' : '' ?>>
                    <span>Full Mouth</span>
                </label>
                <span class="or-divider">OR</span>
                <label>
                    <input type="checkbox" id="determine_checkbox" <?= !empty($_POST['determine_details']) ? 'checked' : '' ?>>
                    <span>Determine:</span>
                </label>
                <input type="text" name="determine_details" id="determineDetailsInput" placeholder="Specify area (e.g. Left side, Right side, Upper arch)..."
                       value="<?= htmlspecialchars($_POST['determine_details'] ?? '') ?>">
                <div class="quick-areas" style="display:flex;gap:4px;flex-wrap:wrap;margin-inline-start:8px;">
                    <button type="button" class="area-chip" data-area="Left side"        data-teeth-side="left">  چەپ</button>
                    <button type="button" class="area-chip" data-area="Right side"       data-teeth-side="right"> ڕاست</button>
                    <button type="button" class="area-chip" data-area="Upper arch"       data-teeth-side="upper"> سەرەوە</button>
                    <button type="button" class="area-chip" data-area="Lower arch"       data-teeth-side="lower"> خوارەوە</button>
                </div>
            </div>
            <style>
                .area-chip { padding:4px 10px; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:14px; cursor:pointer; font-size:11px; font-weight:600; transition:all .15s; }
                .area-chip:hover { background:#c7d2fe; }
                .area-chip.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }
            </style>

            <!-- Dental Chart — shared by all four image types; each type keeps its own selection -->
            <div class="dental-chart-box" id="dentalChartContainer">
                <div class="chart-head">
                    <div class="chart-title"><i class="fas fa-tooth"></i> Select Teeth (<span id="dentalChartTypeLabel">Periapical</span>)</div>
                    <div class="dentition-switch" id="xrayDentitionSwitch">
                        <button type="button" class="dentition-opt<?= $xrayDefaultDentition === 'permanent' ? ' is-active' : '' ?>"
                                data-dentition="permanent" title="Permanent teeth — 32, numbered 1-8">
                            <i class="fas fa-tooth"></i> Adult
                        </button>
                        <button type="button" class="dentition-opt<?= $xrayDefaultDentition === 'primary' ? ' is-active' : '' ?>"
                                data-dentition="primary" title="Primary teeth — 20, lettered A-E">
                            <i class="fas fa-child"></i> Child
                        </button>
                    </div>
                </div>

                <?php foreach ($xrayToothSets as $setName => $set): ?>
                <div class="dentition-pane" data-dentition="<?= $setName ?>"
                     <?= $setName === $xrayDefaultDentition ? '' : 'hidden' ?>>
                    <!-- Upper Jaw -->
                    <div class="jaw-row">
                        <span class="jaw-label">Upper L</span>
                        <div class="teeth-group">
                            <?php foreach ($set['upper_left'] as $i): ?>
                            <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                                <span class="num"><?= $xrayChart->getPalmerNumber($i) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="center-line"></div>
                        <div class="teeth-group">
                            <?php foreach ($set['upper_right'] as $i): ?>
                            <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                                <span class="num"><?= $xrayChart->getPalmerNumber($i) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <span class="jaw-label">Upper R</span>
                    </div>

                    <!-- Lower Jaw -->
                    <div class="jaw-row">
                        <span class="jaw-label">Lower L</span>
                        <div class="teeth-group">
                            <?php foreach ($set['lower_left'] as $i): ?>
                            <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                                <span class="num"><?= $xrayChart->getPalmerNumber($i) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="center-line"></div>
                        <div class="teeth-group">
                            <?php foreach ($set['lower_right'] as $i): ?>
                            <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                                <span class="num"><?= $xrayChart->getPalmerNumber($i) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <span class="jaw-label">Lower R</span>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="selected-teeth-box">
                    <span class="label">Selected:</span>
                    <div id="selectedTeethList"><span style="color: #999;">Click teeth to select</span></div>
                    <button type="button" class="clear-btn" id="clearTeethBtn" style="display: none;">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
                <input type="hidden" name="selected_teeth" id="selectedTeethInput" value="<?= htmlspecialchars($_POST['selected_teeth'] ?? '') ?>">
            </div>

            <!-- Determine Dental Chart — hidden: we reuse the first Periapical chart for Determine too -->
            <div class="determine-chart-box" id="determineChartContainer" style="display:none !important;">
                <div class="det-chart-title"><i class="fas fa-th"></i> Select Specific Teeth</div>

                <!-- Upper Jaw -->
                <div class="jaw-row">
                    <span class="jaw-label">Upper L</span>
                    <div class="teeth-group">
                        <?php for($i = 28; $i >= 21; $i--): ?>
                        <button type="button" class="det-tooth-btn" data-tooth="<?= $i ?>"><?= $i % 10 ?></button>
                        <?php endfor; ?>
                    </div>
                    <div class="det-center-line"></div>
                    <div class="teeth-group">
                        <?php for($i = 11; $i <= 18; $i++): ?>
                        <button type="button" class="det-tooth-btn" data-tooth="<?= $i ?>"><?= $i % 10 ?></button>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label">Upper R</span>
                </div>

                <!-- Lower Jaw -->
                <div class="jaw-row">
                    <span class="jaw-label">Lower L</span>
                    <div class="teeth-group">
                        <?php for($i = 38; $i >= 31; $i--): ?>
                        <button type="button" class="det-tooth-btn" data-tooth="<?= $i ?>"><?= $i % 10 ?></button>
                        <?php endfor; ?>
                    </div>
                    <div class="det-center-line"></div>
                    <div class="teeth-group">
                        <?php for($i = 41; $i <= 48; $i++): ?>
                        <button type="button" class="det-tooth-btn" data-tooth="<?= $i ?>"><?= $i % 10 ?></button>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label">Lower R</span>
                </div>

                <div class="selected-teeth-box">
                    <span class="label">Selected:</span>
                    <span id="detSelectedTeeth" style="color: #f57c00; font-weight: 600;">None</span>
                    <button type="button" class="clear-btn" id="detClearBtn" style="display: none;">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
                <input type="hidden" name="determine_teeth" id="determineTeethInput" value="<?= htmlspecialchars($_POST['determine_teeth'] ?? '') ?>">
            </div>
        </div>

        <!-- Reason Section -->
        <div class="form-section">
            <div class="section-title"><i class="fas fa-question-circle"></i> Reason for Imaging</div>

            <?php if (isset($errors['xray_reason'])): ?>
            <div class="alert alert-error" style="margin-bottom: 12px;"><i class="fas fa-exclamation-circle"></i> <?= $errors['xray_reason'] ?></div>
            <?php endif; ?>

            <div class="reason-options">
                <?php foreach ($xrayReasons as $key => $label): ?>
                <label class="option-btn" data-reason="<?= $key ?>">
                    <input type="radio" name="xray_reason" value="<?= $key ?>"
                           <?= ($_POST['xray_reason'] ?? '') === $key ? 'checked' : '' ?> required>
                    <span><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="form-row" style="margin-top: 12px;">
                <div class="col">
                    <div class="field-label">Additional Details</div>
                    <input type="text" name="reason_details" class="form-input"
                           placeholder="More details..." value="<?= htmlspecialchars($_POST['reason_details'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Payment Section -->
        <div class="form-section">
            <div class="section-title"><i class="fas fa-money-bill-wave"></i> Payment</div>

            <div class="payment-box">
                <div class="payment-type-btns">
                    <label class="payment-btn cash">
                        <input type="radio" name="payment_type" value="cash"
                               <?= ($_POST['payment_type'] ?? 'cash') === 'cash' ? 'checked' : '' ?>>
                        <i class="fas fa-money-bill-wave"></i>
                        <span>کاش</span>
                    </label>
                    <label class="payment-btn no-cash">
                        <input type="radio" name="payment_type" value="dont_cash"
                               <?= ($_POST['payment_type'] ?? '') === 'dont_cash' ? 'checked' : '' ?>>
                        <i class="fas fa-ban"></i>
                        <span>کاش نییە (بەخۆڕایی)</span>
                    </label>
                    <label class="payment-btn debt" style="border-color:#c62828;">
                        <input type="radio" name="payment_type" value="debt"
                               <?= ($_POST['payment_type'] ?? '') === 'debt' ? 'checked' : '' ?>>
                        <i class="fas fa-hand-holding-usd" style="color:#c62828;"></i>
                        <span style="color:#c62828;">قەرز</span>
                    </label>
                </div>

                <div class="amount-field" style="flex-wrap:wrap; gap:6px;">
                    <label>بڕی پارە:</label>
                    <input type="number" name="amount" id="amountInput" value="<?= $_POST['amount'] ?? '0' ?>" min="0" step="1000">
                    <span class="currency"></span>
                    <?php if ($auth->isAdmin()): ?>
                    <a href="<?= APP_URL ?>/admin/xray-pricing.php" target="_blank" title="بەڕێوەبردنی نرخەکان"
                       style="padding: 4px 10px; background: #0078d4; color: #fff; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="fas fa-cog"></i> نرخەکان
                    </a>
                    <?php endif; ?>
                    <span id="priceHint" class="xray-price-tag">
                        <i class="fas fa-tag"></i> <span id="priceHintText">جۆری ئێکسڕەی هەڵبژێرە بۆ پیشاندانی نرخ</span>
                    </span>
                </div>
                <style>
                .xray-price-tag {
                    display: inline-flex; align-items: center; gap: 6px;
                    padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 700;
                    background: #f1f3f6; color: #5a6672; border: 1px solid #dde3ea;
                }
                .xray-price-tag.is-priced { background: #e6f6ea; color: #137a37; border-color: #b7e2c3; }
                .xray-price-tag.is-missing { background: #fff4e5; color: #b25e09; border-color: #f0d4a8; }
                </style>
                <script>
                /* Price display — deliberately its own small script, independent of the
                   page's large script, so a runtime error there can never leave the
                   amount box at 0. Listens by delegation, so markup order is irrelevant.
                   Same rules as the 'compute' action in api/xray-pricing.php. */
                (function () {
                    var PRICES = <?= json_encode((object)$xrayPriceMap) ?>;
                    var MODE_LABELS = {
                        full_mouth: 'هەموو دەم', left_side: 'چەپ', right_side: 'ڕاست',
                        upper_arch: 'سەرەوە', lower_arch: 'خوارەوە', per_tooth: 'بۆ هەر ددانێک', single: 'نرخی جۆر'
<?php foreach (XRAY_CBCT_OPTIONS as $cbctKey => $cbctOpt): ?>
                        , <?= json_encode('cbct_' . $cbctKey) ?>: <?= json_encode('CBCT: ' . $cbctOpt['ku'], JSON_UNESCAPED_UNICODE) ?>

<?php endforeach; ?>
                    };

                    function has(map, m) { return Object.prototype.hasOwnProperty.call(map, m); }

                    function compute(type) {
                        var map = PRICES[type] || {};
                        if (type === 'cbct') {
                            var opt = document.querySelector('input[name="cbct_option"]:checked');
                            if (!opt) return {mode: null, price: 0, missing: 'بژاردەی CBCT هەڵبژێرە'};
                            var m = 'cbct_' + opt.value;
                            return has(map, m) ? {mode: m, price: map[m]} : {mode: null, price: 0, missing: 'نرخ بۆ ئەم بژاردەیە دانەنراوە'};
                        }
                        var mode = null, price = 0;
                        var full = document.getElementById('fullMouthCheckbox');
                        if (full && full.checked) {
                            if (has(map, 'full_mouth')) { mode = 'full_mouth'; price = map.full_mouth; }
                        } else {
                            var det = document.getElementById('determineDetailsInput');
                            var hint = (det ? det.value : '').trim().toLowerCase();
                            var side = hint.indexOf('left') > -1 ? 'left_side'
                                : hint.indexOf('right') > -1 ? 'right_side'
                                : hint.indexOf('upper') > -1 ? 'upper_arch'
                                : hint.indexOf('lower') > -1 ? 'lower_arch' : null;
                            var teethInput = document.getElementById('selectedTeethInput');
                            var count = (teethInput ? teethInput.value : '').split(',').filter(function (t) { return t !== ''; }).length;
                            if (side && has(map, side)) { mode = side; price = map[side]; }
                            else if (count > 0 && has(map, 'per_tooth')) { mode = 'per_tooth'; price = map.per_tooth * count; }
                        }
                        if (mode === null && has(map, 'single')) { mode = 'single'; price = map.single; }
                        return mode === null ? {mode: null, price: 0, missing: 'نرخ بۆ ئەم جۆرە دانەنراوە'} : {mode: mode, price: Math.round(price)};
                    }

                    function update() {
                        var tag = document.getElementById('priceHint');
                        var text = document.getElementById('priceHintText');
                        var amount = document.getElementById('amountInput');
                        var typeEl = document.querySelector('input[name="xray_type"]:checked');
                        if (!tag || !text) return;
                        tag.classList.remove('is-priced', 'is-missing');
                        if (!typeEl) {
                            text.textContent = 'جۆری ئێکسڕەی هەڵبژێرە بۆ پیشاندانی نرخ';
                            return;
                        }
                        var label = typeEl.parentElement.querySelector('span');
                        var typeName = label ? label.textContent.trim() : typeEl.value;
                        var r = compute(typeEl.value);
                        // Cash, debt or free — the x-ray has a value, so the box always shows it.
                        if (amount) amount.value = r.price;
                        if (r.mode) {
                            text.textContent = typeName + ' — ' + Number(r.price).toLocaleString('en-US') + ' (' + (MODE_LABELS[r.mode] || r.mode) + ')';
                            tag.classList.add('is-priced');
                        } else {
                            text.textContent = typeName + ' — ' + r.missing;
                            tag.classList.add('is-missing');
                        }
                    }

                    var timer = null;
                    function schedule() { clearTimeout(timer); timer = setTimeout(update, 60); }
                    window.updateXrayPrice = update;

                    document.addEventListener('change', function (ev) {
                        var t = ev.target;
                        if (!t || !t.name && !t.id) return;
                        if (t.name === 'xray_type' || t.name === 'cbct_option' || t.id === 'fullMouthCheckbox' || t.id === 'determine_checkbox') {
                            schedule();
                        } else if (t.name === 'payment_type') {
                            // Only refill an empty box, so a hand-typed amount survives a payment switch.
                            var amount = document.getElementById('amountInput');
                            if (amount && !(parseFloat(amount.value) > 0)) schedule();
                        }
                    });
                    document.addEventListener('input', function (ev) {
                        if (ev.target && ev.target.id === 'determineDetailsInput') schedule();
                    });
                    // Teeth and area chips update the hidden teeth field in their own handlers; read it after them.
                    document.addEventListener('click', function (ev) {
                        if (ev.target.closest && ev.target.closest('.tooth-btn, .area-chip, #clearTeethBtn')) setTimeout(update, 120);
                    });

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', update);
                    } else {
                        update();
                    }
                })();
                </script>

                <div class="amount-field">
                    <label>Priority:</label>
                    <select name="priority" class="form-input" style="width: 120px;">
                        <option value="normal" <?= ($_POST['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="urgent" <?= ($_POST['priority'] ?? '') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
            </div>

            <?php // No inline display here on purpose: an inline style would beat the
                  // :has() rules in the stylesheet, putting visibility back under script
                  // control. The CSS above owns it. ?>
            <div class="form-row" id="payment_reason_group" style="margin-top: 12px;">
                <?php $reasonKey = $_POST['payment_reason_key'] ?? ''; ?>
                <div class="col">
                    <div class="field-label">هۆکاری کاش نەکردن</div>
                    <select name="payment_reason_key" id="paymentReasonKey" class="form-input">
                        <option value="">— هۆکارەکە هەڵبژێرە —</option>
                        <?php foreach ($dontCashReasons as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $reasonKey === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                        <option value="other" <?= $reasonKey === 'other' ? 'selected' : '' ?>>هی تر (بەخۆم دەنووسم)</option>
                    </select>
                    <?php // Always rendered and always visible. It was previously revealed by JS
                          // only for "هی تر", which meant that if the script did not run the doctor
                          // was left with a required reason and nowhere to type it. Its LABEL and
                          // required flag change with the choice (setPaymentReasonState below); its
                          // visibility never does, so there is no state where it can go missing. ?>
                    <input type="text" name="payment_reason_other" id="paymentReasonOther" class="form-input"
                           style="margin-top: 8px;"
                           placeholder="<?= $reasonKey === 'other' ? 'هۆکارەکە بنووسە' : 'تێبینی (ئارەزوومەندانە)' ?>"
                           value="<?= htmlspecialchars($_POST['payment_reason_other'] ?? '') ?>">
                    <?php if (isset($errors['payment_reason'])): ?>
                    <span class="form-error" style="color: #f44336; font-size: 12px;"><?= $errors['payment_reason'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Notes Section -->
        <div class="form-section">
            <div class="section-title"><i class="fas fa-sticky-note"></i> Notes</div>
            <div class="notes-field">
                <textarea name="notes" placeholder="Additional notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Form Footer -->
        <div class="form-footer">
            <a href="<?= APP_URL ?>/xray/index.php" class="btn btn-cancel">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="submit" class="btn btn-submit">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </div>
    </form>
</div>

<script>
// Which tooth set the periapical chart is showing, and the teeth of each
// quadrant per set. Declared first so the priority chip handler below never
// depends on later code, same as the rest of that block.
let xrayDentition = <?= json_encode($xrayDefaultDentition) ?>;
const XRAY_QUADRANT_TEETH = {
    permanent: {
        UR: [11, 12, 13, 14, 15, 16, 17, 18], UL: [21, 22, 23, 24, 25, 26, 27, 28],
        LL: [31, 32, 33, 34, 35, 36, 37, 38], LR: [41, 42, 43, 44, 45, 46, 47, 48]
    },
    primary: {
        UR: [51, 52, 53, 54, 55], UL: [61, 62, 63, 64, 65],
        LL: [71, 72, 73, 74, 75], LR: [81, 82, 83, 84, 85]
    }
};

// ══════════════════════════════════════════════════════════════════
// PRIORITY: Quick-area chip handler (attached IMMEDIATELY via capture-phase
// delegation so it runs even if later code throws an error)
// ══════════════════════════════════════════════════════════════════
document.addEventListener('click', function(ev) {
    const chip = ev.target.closest('.area-chip');
    if (!chip) return;
    ev.preventDefault();
    ev.stopPropagation();

    const side = chip.dataset.teethSide || chip.getAttribute('data-teeth-side');
    const area = chip.dataset.area || chip.getAttribute('data-area') || '';

    // Force Determine mode (uncheck Full Mouth, check Determine)
    const fullCb  = document.getElementById('fullMouthCheckbox');
    const detCb   = document.getElementById('determine_checkbox');
    const detText = document.getElementById('determineDetailsInput');
    if (fullCb && fullCb.checked) fullCb.checked = false;
    if (detCb && !detCb.checked)  detCb.checked  = true;
    if (detText) { detText.disabled = false; detText.value = area; }

    // Keep the chosen image type; the chart works for all of them
    if (typeof ensureDentalChart === 'function') ensureDentalChart();

    // Toggle active chip state
    const wasActive = chip.classList.contains('active');
    document.querySelectorAll('.area-chip.active').forEach(c => c.classList.remove('active'));

    // Clear ALL teeth first
    document.querySelectorAll('.tooth-btn.selected').forEach(t => t.classList.remove('selected'));
    if (window.selectedTeeth) window.selectedTeeth.length = 0;

    if (!wasActive) {
        chip.classList.add('active');
        // Select the quadrants for this side, in whichever tooth set is showing
        const sideQuadrants = {
            left:  ['UL', 'LL'],
            right: ['UR', 'LR'],
            upper: ['UR', 'UL'],
            lower: ['LL', 'LR'],
        };
        const quadrantMap = XRAY_QUADRANT_TEETH[xrayDentition] || XRAY_QUADRANT_TEETH.permanent;
        const teethList = [];
        (sideQuadrants[side] || []).forEach(function(q) {
            (quadrantMap[q] || []).forEach(function(i) {
                const btn = document.querySelector('.dentition-pane:not([hidden]) .tooth-btn[data-tooth="' + i + '"]');
                if (btn) {
                    btn.classList.add('selected');
                    teethList.push(String(i));
                }
            });
        });
        // Sync with global selectedTeeth array if it exists
        if (window.selectedTeeth) {
            window.selectedTeeth.length = 0;
            teethList.forEach(t => window.selectedTeeth.push(t));
        }
        // Sync hidden inputs
        const selInp = document.getElementById('selectedTeethInput');
        const detInp = document.getElementById('determineTeethInput');
        if (selInp) selInp.value = teethList.join(',');
        if (detInp) detInp.value = teethList.join(',');
        // Update summary if function exists
        if (typeof updateSelectedTeethDisplay === 'function') {
            try { updateSelectedTeethDisplay(); } catch(e) {}
        }
    }
    // Trigger price recompute if available
    if (typeof schedulePriceCompute === 'function') {
        try { schedulePriceCompute(); } catch(e) {}
    }
}, true); // capture phase

// Patient search
const patientSearch = document.getElementById('patient_search');
const patientResults = document.getElementById('patient_results');
const patientIdInput = document.getElementById('patient_id');
const caseGroup = document.getElementById('case_group');
const caseSelect = document.getElementById('case_id');
const patientSearchWrapper = document.getElementById('patient_search_wrapper');
const ageText = document.getElementById('age_text');
const phoneText = document.getElementById('phone_text');

let searchTimeout;

if (patientSearch) {
    patientSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();

        if (query.length < 2) {
            patientResults.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(() => {
            fetch('<?= APP_URL ?>/api/search.php?type=patients&q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(response => {
                    const patients = response.data || [];
                    if (patients.length > 0) {
                        patientResults.innerHTML = patients.map(p => {
                            const age = p.age ? `${p.age} years` : '';
                            const phone = p.phone || '';
                            return `<div class="patient-result-item" data-id="${p.id}" data-name="${p.full_name}" data-age="${p.age || ''}" data-phone="${phone}">
                                <div class="name">${p.full_name}</div>
                                <div class="meta">${age} ${p.patient_code ? '• ' + p.patient_code : ''} ${phone ? '• ' + phone : ''}</div>
                            </div>`;
                        }).join('');
                        patientResults.style.display = 'block';
                    } else {
                        patientResults.innerHTML = '<div class="patient-result-item" style="color: #999;"><i class="fas fa-info-circle"></i> No patients found</div>';
                        patientResults.style.display = 'block';
                    }
                });
        }, 300);
    });

    patientResults.addEventListener('click', function(e) {
        const item = e.target.closest('.patient-result-item');
        if (item && item.dataset.id) {
            patientIdInput.value = item.dataset.id;
            patientSearch.value = item.dataset.name;
            patientResults.style.display = 'none';
            ageText.textContent = item.dataset.age ? item.dataset.age + ' years' : '--';
            if (phoneText) phoneText.textContent = item.dataset.phone ? item.dataset.phone : '--';
            loadPatientCases(item.dataset.id);
        }
    });

    document.addEventListener('click', function(e) {
        if (!patientSearch.contains(e.target) && !patientResults.contains(e.target)) {
            patientResults.style.display = 'none';
        }
    });
}

function clearPatient() {
    document.querySelector('.selected-patient')?.remove();
    patientSearchWrapper.style.display = 'block';
    patientIdInput.value = '';
    caseGroup.style.display = 'none';
    caseSelect.innerHTML = '<option value="">-- No Case --</option>';
    ageText.textContent = '--';
    if (phoneText) phoneText.textContent = '--';
}

function loadPatientCases(patientId) {
    fetch('<?= APP_URL ?>/api/search.php?type=patient_cases&patient_id=' + patientId)
        .then(response => response.json())
        .then(response => {
            const cases = response.data || [];
            caseSelect.innerHTML = '<option value="">-- No Case --</option>';
            cases.forEach(c => {
                caseSelect.innerHTML += `<option value="${c.id}">${c.case_number}${c.case_name ? ' - ' + c.case_name : ''}</option>`;
            });
            caseGroup.style.display = 'flex';
        });
}

/* ── Doctor picker ─────────────────────────────────────────────────────────
   Two groups from two unrelated sources: in-house doctors (user accounts) and
   outside referring doctors (xray_external_doctors). Seeded server-side so the
   list works before any typing, and before/without a successful search call.
   Only rendered for staff filing on someone else's behalf — a doctor's own
   name is fixed on the form. */
(function () {
    const pick = document.getElementById('docPick');
    if (!pick) return;

    const search     = document.getElementById('docSearch');
    const panel      = document.getElementById('docPanel');
    const wrap       = document.getElementById('docSearchWrap');
    const chip       = document.getElementById('docChip');
    const chipName   = document.getElementById('docChipName');
    const chipKind   = document.getElementById('docChipKind');
    const chipClear  = document.getElementById('docChipClear');
    const kindInput  = document.getElementById('doctor_kind');
    const idInput    = document.getElementById('doctor_id');
    const extInput   = document.getElementById('external_doctor_id');
    const addBox     = document.getElementById('docAdd');
    const newName    = document.getElementById('docNewName');
    const newPhone   = document.getElementById('docNewPhone');
    const newClinic  = document.getElementById('docNewClinic');
    const newSpec    = document.getElementById('docNewSpec');
    const newSave    = document.getElementById('docNewSave');
    const newCancel  = document.getElementById('docNewCancel');
    const newMsg     = document.getElementById('docNewMsg');

    const API = '<?= APP_URL ?>/ajax/xray_doctors.php';
    const SEEDED = <?= json_encode([
        'internal' => array_map(function ($d) {
            return ['id' => (int)$d['id'], 'name' => $d['name']];
        }, $internalDoctors),
        'external' => array_map(function ($d) {
            return [
                'id' => (int)$d['id'], 'name' => $d['name'],
                'phone' => $d['phone'] ?? '', 'clinic' => $d['clinic'] ?? '',
                'specialization' => $d['specialization'] ?? '',
            ];
        }, $externalDoctors),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    let current = { internal: SEEDED.internal.slice(), external: SEEDED.external.slice() };
    let activeIdx = -1;      // keyboard highlight across the flattened list
    let flat = [];           // [{kind, id, name, ...}] in rendered order
    let timer = null;

    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g,
        c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function render() {
        flat = [];
        let html = '';

        if (current.internal.length) {
            html += `<div class="doc-group-title">
                        <i class="fas fa-hospital-user"></i> دکتۆری ناوەخۆ
                        <span class="doc-group-count">${current.internal.length}</span>
                     </div>`;
            current.internal.forEach(d => {
                const i = flat.push({ kind: 'internal', id: d.id, name: d.name }) - 1;
                html += `<div class="doc-item" data-idx="${i}">
                            <div class="doc-item-name">د. ${esc(d.name)}</div>
                         </div>`;
            });
        }

        if (current.external.length) {
            html += `<div class="doc-group-title is-external">
                        <i class="fas fa-user-md"></i> دکتۆری دەرەوە
                        <span class="doc-group-count">${current.external.length}</span>
                     </div>`;
            current.external.forEach(d => {
                const i = flat.push({ kind: 'external', id: d.id, name: d.name }) - 1;
                const meta = [d.clinic, d.specialization, d.phone].filter(Boolean).join(' • ');
                html += `<div class="doc-item is-external" data-idx="${i}">
                            <div class="doc-item-name">${esc(d.name)}</div>
                            ${meta ? `<div class="doc-item-meta">${esc(meta)}</div>` : ''}
                         </div>`;
            });
        }

        if (!flat.length) {
            html += '<div class="doc-empty"><i class="fas fa-user-slash"></i> هیچ دکتۆرێک نەدۆزرایەوە</div>';
        }

        html += `<div class="doc-panel-add" id="docPanelAdd">
                    <i class="fas fa-user-plus"></i> زیادکردنی دکتۆری دەرەوە
                 </div>`;

        panel.innerHTML = html;
        activeIdx = -1;
    }

    function open()  { render(); panel.classList.add('open'); }
    function close() { panel.classList.remove('open'); }

    function doSearch(q) {
        fetch(API + '?action=search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(res => {
                if (!res || !res.success) return;
                current = { internal: res.internal || [], external: res.external || [] };
                if (panel.classList.contains('open')) render();
            })
            .catch(() => { /* keep whatever list is already on screen */ });
    }

    function select(kind, id, name) {
        kindInput.value = kind;
        idInput.value = kind === 'internal' ? id : '';
        extInput.value = kind === 'external' ? id : '';

        chip.dataset.kind = kind;
        chipName.textContent = (kind === 'internal' ? 'د. ' : '') + name;
        chipKind.textContent = kind === 'internal' ? 'ناوەخۆ' : 'دەرەوە';
        chip.hidden = false;
        wrap.style.display = 'none';
        addBox.hidden = true;
        close();
    }

    function clearChoice() {
        kindInput.value = '';
        idInput.value = '';
        extInput.value = '';
        chip.hidden = true;
        wrap.style.display = '';
        search.value = '';
        current = { internal: SEEDED.internal.slice(), external: SEEDED.external.slice() };
        search.focus();
        open();
    }

    function showAdd(prefill) {
        addBox.hidden = false;
        close();
        newMsg.textContent = '';
        newMsg.className = 'doc-add-msg';
        if (prefill) newName.value = prefill;
        newName.focus();
    }

    function saveNew() {
        const name = newName.value.trim();
        newMsg.className = 'doc-add-msg';
        if (!name) {
            newMsg.textContent = 'ناوی دکتۆر پێویستە';
            newMsg.classList.add('is-error');
            newName.focus();
            return;
        }
        const body = new FormData();
        body.append('action', 'create');
        body.append('name', name);
        body.append('phone', newPhone.value.trim());
        body.append('clinic', newClinic.value.trim());
        body.append('specialization', newSpec.value.trim());

        newSave.disabled = true;
        newMsg.textContent = 'تۆمار دەکرێت...';
        fetch(API, { method: 'POST', body: body })
            .then(r => r.json())
            .then(res => {
                newSave.disabled = false;
                if (!res || !res.success) {
                    newMsg.textContent = (res && res.error) ? res.error : 'تۆمارکردن سەرکەوتوو نەبوو';
                    newMsg.classList.add('is-error');
                    return;
                }
                const d = res.doctor;
                // Keep the seed in sync so a later "گۆڕین" still lists the new one.
                if (!SEEDED.external.some(x => Number(x.id) === Number(d.id))) {
                    SEEDED.external.push(d);
                    SEEDED.external.sort((a, b) => String(a.name).localeCompare(String(b.name)));
                }
                newName.value = newPhone.value = newClinic.value = newSpec.value = '';
                newMsg.textContent = '';
                select('external', d.id, d.name);
            })
            .catch(() => {
                newSave.disabled = false;
                newMsg.textContent = 'هەڵە لە پەیوەندی';
                newMsg.classList.add('is-error');
            });
    }

    search.addEventListener('focus', open);
    search.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        panel.classList.add('open');
        timer = setTimeout(() => doSearch(q), 250);
    });

    // Enter must never submit the whole x-ray form from inside this picker.
    search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!panel.classList.contains('open')) open();
            if (!flat.length) return;
            activeIdx += (e.key === 'ArrowDown' ? 1 : -1);
            if (activeIdx < 0) activeIdx = flat.length - 1;
            if (activeIdx >= flat.length) activeIdx = 0;
            panel.querySelectorAll('.doc-item').forEach(el => el.classList.remove('is-active'));
            const el = panel.querySelector(`.doc-item[data-idx="${activeIdx}"]`);
            if (el) { el.classList.add('is-active'); el.scrollIntoView({ block: 'nearest' }); }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0 && flat[activeIdx]) {
                const d = flat[activeIdx];
                select(d.kind, d.id, d.name);
            } else if (flat.length === 1) {
                select(flat[0].kind, flat[0].id, flat[0].name);
            }
        } else if (e.key === 'Escape') {
            close();
        }
    });

    panel.addEventListener('click', function (e) {
        if (e.target.closest('#docPanelAdd')) {
            showAdd(search.value.trim());
            return;
        }
        const item = e.target.closest('.doc-item');
        if (item && item.dataset.idx !== undefined) {
            const d = flat[Number(item.dataset.idx)];
            if (d) select(d.kind, d.id, d.name);
        }
    });

    document.addEventListener('click', function (e) {
        if (!pick.contains(e.target)) close();
    });

    chipClear.addEventListener('click', clearChoice);
    newSave.addEventListener('click', saveNew);
    newCancel.addEventListener('click', function () {
        addBox.hidden = true;
        newMsg.textContent = '';
    });

    [newName, newPhone, newClinic, newSpec].forEach(el => {
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); saveNew(); }
        });
    });

    // Restore the choice after a failed submit (the server re-renders the form).
    const postedKind = <?= json_encode($postedDoctorLabel !== '' ? $postedDoctorKind : '') ?>;
    if (postedKind) {
        select(
            postedKind,
            <?= json_encode($postedDoctorKind === 'internal' ? $postedDoctorId : $postedExternalDoctorId) ?>,
            <?= json_encode($postedDoctorKind === 'internal'
                ? preg_replace('/^د\. /u', '', $postedDoctorLabel)
                : $postedDoctorLabel, JSON_UNESCAPED_UNICODE) ?>
        );
    }

    // Don't let the form go without a doctor. stopImmediatePropagation matters:
    // the double-submit guard further down this file is another 'submit' listener
    // on the same form, and it disables the submit button. preventDefault alone
    // would still let it run, leaving a dead button on a form that never posted.
    const xrayForm = document.getElementById('xrayRequestForm');
    if (xrayForm) {
        xrayForm.addEventListener('submit', function (e) {
            if (!kindInput.value) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert('تکایە دکتۆر هەڵبژێرە');
                search.focus();
                open();
            }
        });
    }
})();

// Dental Chart
const dentalChartContainer = document.getElementById('dentalChartContainer');
const selectedTeethList = document.getElementById('selectedTeethList');
const selectedTeethInput = document.getElementById('selectedTeethInput');
const clearTeethBtn = document.getElementById('clearTeethBtn');
let selectedTeeth = [];
window.selectedTeeth = selectedTeeth; // expose for early-bound chip handler

const fdiToPalmer = {
    18: {p: 8, q: 'UR'}, 17: {p: 7, q: 'UR'}, 16: {p: 6, q: 'UR'}, 15: {p: 5, q: 'UR'},
    14: {p: 4, q: 'UR'}, 13: {p: 3, q: 'UR'}, 12: {p: 2, q: 'UR'}, 11: {p: 1, q: 'UR'},
    21: {p: 1, q: 'UL'}, 22: {p: 2, q: 'UL'}, 23: {p: 3, q: 'UL'}, 24: {p: 4, q: 'UL'},
    25: {p: 5, q: 'UL'}, 26: {p: 6, q: 'UL'}, 27: {p: 7, q: 'UL'}, 28: {p: 8, q: 'UL'},
    31: {p: 1, q: 'LL'}, 32: {p: 2, q: 'LL'}, 33: {p: 3, q: 'LL'}, 34: {p: 4, q: 'LL'},
    35: {p: 5, q: 'LL'}, 36: {p: 6, q: 'LL'}, 37: {p: 7, q: 'LL'}, 38: {p: 8, q: 'LL'},
    48: {p: 8, q: 'LR'}, 47: {p: 7, q: 'LR'}, 46: {p: 6, q: 'LR'}, 45: {p: 5, q: 'LR'},
    44: {p: 4, q: 'LR'}, 43: {p: 3, q: 'LR'}, 42: {p: 2, q: 'LR'}, 41: {p: 1, q: 'LR'},
    // Primary (baby) teeth — quadrants 5-8, lettered A-E
    55: {p: 'E', q: 'UR'}, 54: {p: 'D', q: 'UR'}, 53: {p: 'C', q: 'UR'},
    52: {p: 'B', q: 'UR'}, 51: {p: 'A', q: 'UR'},
    61: {p: 'A', q: 'UL'}, 62: {p: 'B', q: 'UL'}, 63: {p: 'C', q: 'UL'},
    64: {p: 'D', q: 'UL'}, 65: {p: 'E', q: 'UL'},
    71: {p: 'A', q: 'LL'}, 72: {p: 'B', q: 'LL'}, 73: {p: 'C', q: 'LL'},
    74: {p: 'D', q: 'LL'}, 75: {p: 'E', q: 'LL'},
    85: {p: 'E', q: 'LR'}, 84: {p: 'D', q: 'LR'}, 83: {p: 'C', q: 'LR'},
    82: {p: 'B', q: 'LR'}, 81: {p: 'A', q: 'LR'}
};


if (selectedTeethInput.value) {
    selectedTeeth = selectedTeethInput.value.split(',').filter(t => t);
    updateSelectedTeethDisplay();
    selectedTeeth.forEach(tooth => {
        document.querySelector(`.tooth-btn[data-tooth="${tooth}"]`)?.classList.add('selected');
    });
}

// The tooth chart belongs to whichever image type is chosen — not only Periapical.
function toggleDentalChart() {
    const input = document.querySelector('.option-btn[data-type] input:checked');
    dentalChartContainer.classList.toggle('visible', !!input);
    const label = document.getElementById('dentalChartTypeLabel');
    if (label && input) label.textContent = input.closest('.option-btn').querySelector('span').textContent.trim();
    toggleCbctOptions();
}

// CBCT options appear only for CBCT; hidden radios are disabled so they are
// neither required nor submitted for the other types.
function toggleCbctOptions() {
    const box = document.getElementById('cbctOptionsBox');
    if (!box) return;
    const isCbct = document.querySelector('.option-btn input[name="xray_type"]:checked')?.value === 'cbct';
    box.hidden = !isCbct;
    box.querySelectorAll('input[name="cbct_option"]').forEach((r, i) => {
        r.disabled = !isCbct;
        r.required = isCbct && i === 0; // one required radio makes the whole group required
    });
}

document.querySelectorAll('input[name="cbct_option"]').forEach(r => {
    r.addEventListener('change', () => schedulePriceCompute());
});

// ── Per-type selection memory ──────────────────────────────────────
// Every type has its own teeth / Full Mouth / Determine choice. Switching type
// stores the current one and brings back what was picked for the new type.
const xrayTypeSelections = {};
let currentXrayType = document.querySelector('.option-btn input[name="xray_type"]:checked')?.value || null;

function captureXraySelection() {
    return {
        teeth: selectedTeeth.slice(),
        dentition: xrayDentition,
        fullMouth: !!(fullMouthCheckbox && fullMouthCheckbox.checked),
        determine: !!(_determineCb && _determineCb.checked),
        determineText: determineDetailsInput ? determineDetailsInput.value : '',
        chip: document.querySelector('.area-chip.active')?.dataset.area || null,
    };
}

function applyXraySelection(sel) {
    sel = sel || {teeth: [], dentition: xrayDentition, fullMouth: false, determine: false, determineText: '', chip: null};
    setXrayDentition(sel.dentition); // no-op when unchanged; clears teeth when it switches
    document.querySelectorAll('.tooth-btn.selected').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('.area-chip.active').forEach(c => c.classList.remove('active'));
    selectedTeeth.length = 0; // mutate in place — other handlers hold this reference
    sel.teeth.forEach(t => {
        selectedTeeth.push(t);
        document.querySelector(`.tooth-btn[data-tooth="${t}"]`)?.classList.add('selected');
    });
    if (fullMouthCheckbox) fullMouthCheckbox.checked = sel.fullMouth;
    if (_determineCb) _determineCb.checked = sel.determine;
    if (determineDetailsInput) {
        determineDetailsInput.value = sel.determineText;
        determineDetailsInput.disabled = sel.fullMouth;
    }
    if (sel.chip) {
        document.querySelector(`.area-chip[data-area="${sel.chip}"]`)?.classList.add('active');
    }
    updateSelectedTeethDisplay();
}

function switchXrayTypeSelection(newType) {
    if (newType === currentXrayType) return;
    if (currentXrayType) xrayTypeSelections[currentXrayType] = captureXraySelection();
    currentXrayType = newType;
    applyXraySelection(xrayTypeSelections[newType]);
}

// ── Adult / child tooth set ────────────────────────────────────────
// Swapping the set clears the selection: a tooth from the other dentition is
// not a valid pick on the new chart, and a mixed list would be meaningless.
function setXrayDentition(mode) {
    if (mode !== 'primary' && mode !== 'permanent') return;
    if (mode === xrayDentition) return;
    xrayDentition = mode;

    document.querySelectorAll('#dentalChartContainer .dentition-pane').forEach(pane => {
        pane.hidden = (pane.dataset.dentition !== mode);
    });
    document.querySelectorAll('#xrayDentitionSwitch .dentition-opt').forEach(btn => {
        btn.classList.toggle('is-active', btn.dataset.dentition === mode);
    });

    document.querySelectorAll('.tooth-btn.selected').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('.area-chip.active').forEach(c => c.classList.remove('active'));
    selectedTeeth.length = 0; // mutate in place — other handlers hold this reference
    updateSelectedTeethDisplay();
}

document.getElementById('xrayDentitionSwitch')?.addEventListener('click', function(ev) {
    const btn = ev.target.closest('.dentition-opt');
    if (btn) setXrayDentition(btn.dataset.dentition);
});

// Event delegation for tooth clicks — survives any DOM timing issues and works
// even when inside the hidden legacy determineChartContainer.
document.addEventListener('click', function(ev) {
    const btn = ev.target.closest('.tooth-btn');
    if (!btn) return;
    ev.preventDefault();

    // If user is in Full Mouth mode, switch to Determine mode first so they can refine selection.
    if (fullMouthCheckbox && fullMouthCheckbox.checked) {
        fullMouthCheckbox.checked = false;
        if (_determineCb) _determineCb.checked = true;
        if (determineDetailsInput) determineDetailsInput.disabled = false;
    }

    const tooth = btn.dataset.tooth;
    if (btn.classList.contains('selected')) {
        btn.classList.remove('selected');
        selectedTeeth = selectedTeeth.filter(t => t !== tooth);
    } else {
        btn.classList.add('selected');
        selectedTeeth.push(tooth);
    }
    updateSelectedTeethDisplay();
    schedulePriceCompute();
});

function updateSelectedTeethDisplay() {
    if (selectedTeeth.length === 0) {
        selectedTeethList.innerHTML = '<span style="color: #999;">Click teeth to select</span>';
        clearTeethBtn.style.display = 'none';
    } else {
        const grouped = {UR: [], UL: [], LR: [], LL: []};
        selectedTeeth.forEach(t => {
            const info = fdiToPalmer[t];
            if (info) grouped[info.q].push(info.p);
        });

        let html = '';
        ['UR', 'UL', 'LR', 'LL'].forEach(q => {
            if (grouped[q].length > 0) {
                // Permanent teeth sort numerically (1-8), primary alphabetically (A-E)
                grouped[q].sort((a, b) => String(a).localeCompare(String(b), 'en', {numeric: true}));
                html += `<span class="tooth-tag">${q}: ${grouped[q].join(',')}</span>`;
            }
        });

        selectedTeethList.innerHTML = html;
        clearTeethBtn.style.display = 'block';
    }
    selectedTeethInput.value = selectedTeeth.join(',');
    // Sync to determineTeethInput so backend receives selection when Determine is checked
    const detInput = document.getElementById('determineTeethInput');
    const detCb    = document.getElementById('determine_checkbox');
    if (detInput) detInput.value = (detCb && detCb.checked) ? selectedTeeth.join(',') : '';
}

clearTeethBtn.addEventListener('click', function() {
    document.querySelectorAll('.tooth-btn.selected').forEach(t => t.classList.remove('selected'));
    selectedTeeth = [];
    updateSelectedTeethDisplay();
    // Uncheck Full Mouth if user manually clears
    if (fullMouthCheckbox) fullMouthCheckbox.checked = false;
});

// ── Full Mouth / Determine mutual-exclusive + auto-select all teeth ──
const fullMouthCheckbox = document.getElementById('fullMouthCheckbox');
const determineDetailsInput = document.getElementById('determineDetailsInput');

// Make sure the chart is visible for Full Mouth / Determine / area chips.
// The chosen image type is kept — every type has its own chart. Only when no
// type is picked yet does it fall back to Periapical, so the price can compute.
function ensureDentalChart() {
    if (!document.querySelector('.option-btn input[name="xray_type"]:checked')) {
        const periapicalRadio = document.querySelector('.option-btn input[name="xray_type"][value="periapical"]');
        if (periapicalRadio) {
            periapicalRadio.checked = true;
            periapicalRadio.closest('.option-btn').classList.add('selected');
            currentXrayType = 'periapical';
        }
    }
    toggleDentalChart();
}
// Old name, still called from several handlers below.
const ensurePeriapicalChart = ensureDentalChart;

function selectAllTeeth() {
    ensurePeriapicalChart();
    selectedTeeth = [];
    document.querySelectorAll('.tooth-btn').forEach(btn => {
        btn.classList.add('selected');
        selectedTeeth.push(btn.dataset.tooth);
    });
    updateSelectedTeethDisplay();
}

function clearAllTeeth() {
    document.querySelectorAll('.tooth-btn.selected').forEach(t => t.classList.remove('selected'));
    selectedTeeth = [];
    updateSelectedTeethDisplay();
}

// Simple mutual exclusion: tick one → the other unticks.
// User is free to uncheck both.
const _determineCb = document.getElementById('determine_checkbox');

if (fullMouthCheckbox) {
    fullMouthCheckbox.addEventListener('change', function() {
        if (this.checked) {
            if (_determineCb) _determineCb.checked = false;
            selectAllTeeth();
            if (determineDetailsInput) { determineDetailsInput.value = ''; determineDetailsInput.disabled = true; }
            document.querySelectorAll('.area-chip.active').forEach(c => c.classList.remove('active'));
        } else {
            clearAllTeeth();
            if (determineDetailsInput) determineDetailsInput.disabled = false;
        }
        schedulePriceCompute();
    });

    if (fullMouthCheckbox.checked) {
        if (_determineCb) _determineCb.checked = false;
        selectAllTeeth();
        if (determineDetailsInput) determineDetailsInput.disabled = true;
    }
}

if (_determineCb) {
    _determineCb.addEventListener('change', function() {
        if (this.checked) {
            if (fullMouthCheckbox) fullMouthCheckbox.checked = false;
            clearAllTeeth();
            ensurePeriapicalChart();
            if (determineDetailsInput) { determineDetailsInput.disabled = false; determineDetailsInput.focus(); }
        } else {
            if (determineDetailsInput) determineDetailsInput.value = '';
            document.querySelectorAll('.area-chip.active').forEach(c => c.classList.remove('active'));
            clearAllTeeth();
        }
        schedulePriceCompute();
    });

    if (_determineCb.checked) {
        if (fullMouthCheckbox) fullMouthCheckbox.checked = false;
        ensurePeriapicalChart();
    }
}

// Also compute when xray type changes, determine-details text is typed, or individual teeth are clicked
document.querySelectorAll('.option-btn input[name="xray_type"]').forEach(el => {
    el.addEventListener('change', schedulePriceCompute);
});
if (determineDetailsInput) determineDetailsInput.addEventListener('input', schedulePriceCompute);

// Initial compute on load
schedulePriceCompute();

// ── Quick-area chips: prefill the text box + select the relevant quadrant teeth ──
// FDI tooth numbering:
//   Upper Right 11-18 · Upper Left 21-28 · Lower Left 31-38 · Lower Right 41-48
function selectTeethInSide(side) {
    // Clear first
    clearAllTeeth();
    const sideRanges = {
        left:  [ [21,28], [31,38] ],   // upper-left + lower-left
        right: [ [11,18], [41,48] ],   // upper-right + lower-right
        upper: [ [11,18], [21,28] ],
        lower: [ [31,38], [41,48] ],
    };
    const ranges = sideRanges[side] || [];
    ranges.forEach(([lo, hi]) => {
        for (let i = lo; i <= hi; i++) {
            const btn = document.querySelector(`.tooth-btn[data-tooth="${i}"]`);
            if (btn) {
                btn.classList.add('selected');
                selectedTeeth.push(String(i));
            }
        }
    });
    updateSelectedTeethDisplay();
}

// ── Live price ──
// The price display itself lives in its own small script beside the amount
// box (window.updateXrayPrice). These wrappers keep the many existing callers
// in this script working.
//
// `var`, not `let`: schedulePriceCompute() is first called further UP this
// script during page load. With `let` that call hit the temporal dead zone,
// threw, and silently killed every statement after it — the amount box stayed
// at 0 and the CBCT options, chart and payment handlers were never attached.
var priceComputeTimer = null;
function schedulePriceCompute() {
    if (priceComputeTimer) clearTimeout(priceComputeTimer);
    priceComputeTimer = setTimeout(computeAmount, 150);
}

function computeAmount() {
    if (typeof window.updateXrayPrice === 'function') window.updateXrayPrice();
}

// Chip click handler — extracted so we can attach it multiple ways for max robustness
function handleChipClick(chip) {
    if (!chip) return;

    try { ensurePeriapicalChart(); } catch (e) {}

    // Force Determine mode
    if (fullMouthCheckbox && fullMouthCheckbox.checked) { fullMouthCheckbox.checked = false; }
    if (_determineCb && !_determineCb.checked) { _determineCb.checked = true; }
    if (determineDetailsInput) {
        determineDetailsInput.disabled = false;
        determineDetailsInput.value = chip.dataset.area || chip.getAttribute('data-area') || '';
    }

    const side = chip.dataset.teethSide || chip.getAttribute('data-teeth-side');
    const wasActive = chip.classList.contains('active');

    document.querySelectorAll('.area-chip.active').forEach(c => c.classList.remove('active'));

    if (wasActive) {
        clearAllTeeth();
    } else {
        chip.classList.add('active');
        if (side) selectTeethInSide(side);
    }
    schedulePriceCompute();
}

// (1) Event delegation — works regardless of DOM timing
document.addEventListener('click', function(ev) {
    const chip = ev.target.closest('.area-chip');
    if (!chip) return;
    ev.preventDefault();
    ev.stopPropagation();
    handleChipClick(chip);
}, true); // capture phase — intercepts before any other listener

// (2) Direct per-chip listeners (backup for quirky browsers / overridden handlers)
document.querySelectorAll('.area-chip').forEach(chip => {
    chip.addEventListener('click', function(ev) {
        ev.preventDefault();
        ev.stopPropagation();
        handleChipClick(this);
    });
});

// Determine Chart
const determineCheckbox = document.getElementById('determine_checkbox');
const determineChartContainer = document.getElementById('determineChartContainer');
const determineTeethInput = document.getElementById('determineTeethInput');
const detSelectedTeeth = document.getElementById('detSelectedTeeth');
const detClearBtn = document.getElementById('detClearBtn');
let determineTeeth = [];

function toggleDetermineChart() {
    determineChartContainer.classList.toggle('visible', determineCheckbox?.checked);
}

if (determineCheckbox) {
    determineCheckbox.addEventListener('change', toggleDetermineChart);
}

if (determineTeethInput?.value) {
    determineTeeth = determineTeethInput.value.split(',').filter(t => t);
    // Re-hydrate the MAIN chart (first Periapical chart) with the saved teeth
    if (determineTeeth.length) {
        selectedTeeth = determineTeeth.slice();
        determineTeeth.forEach(tooth => {
            document.querySelector(`.tooth-btn[data-tooth="${tooth}"]`)?.classList.add('selected');
        });
        ensurePeriapicalChart();
        updateSelectedTeethDisplay();
    }
}

document.querySelectorAll('.det-tooth-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tooth = this.dataset.tooth;
        if (this.classList.contains('selected')) {
            this.classList.remove('selected');
            determineTeeth = determineTeeth.filter(t => t !== tooth);
        } else {
            this.classList.add('selected');
            determineTeeth.push(tooth);
        }
        updateDetermineDisplay();
    });
});

function updateDetermineDisplay() {
    if (determineTeeth.length === 0) {
        detSelectedTeeth.textContent = 'None';
        detClearBtn.style.display = 'none';
    } else {
        const grouped = {UR: [], UL: [], LR: [], LL: []};
        determineTeeth.forEach(t => {
            const info = fdiToPalmer[t];
            if (info) grouped[info.q].push(info.p);
        });

        let display = [];
        ['UR', 'UL', 'LR', 'LL'].forEach(q => {
            if (grouped[q].length > 0) {
                grouped[q].sort((a, b) => a - b);
                display.push(`${q}: ${grouped[q].join(',')}`);
            }
        });

        detSelectedTeeth.textContent = display.join(' | ');
        detClearBtn.style.display = 'block';
    }
    determineTeethInput.value = determineTeeth.join(',');
}

detClearBtn?.addEventListener('click', function() {
    document.querySelectorAll('.det-tooth-btn.selected').forEach(t => t.classList.remove('selected'));
    determineTeeth = [];
    updateDetermineDisplay();
});

// Option buttons styling
document.querySelectorAll('.option-btn input').forEach(input => {
    input.addEventListener('change', function() {
        const name = this.name;
        document.querySelectorAll(`.option-btn input[name="${name}"]`).forEach(i => {
            i.closest('.option-btn').classList.remove('selected');
        });
        if (this.checked) {
            this.closest('.option-btn').classList.add('selected');
            if (name === 'xray_type') {
                switchXrayTypeSelection(this.value);
                toggleDentalChart();
            }
        }
    });
    if (input.checked) input.closest('.option-btn').classList.add('selected');
});

toggleDentalChart();

// Payment buttons
document.querySelectorAll('.payment-btn input').forEach(input => {
    input.addEventListener('change', function() {
        document.querySelectorAll('.payment-btn').forEach(b => b.classList.remove('selected'));
        if (this.checked) {
            this.closest('.payment-btn').classList.add('selected');
        }
        setPaymentReasonState(this.value);
    });
    if (input.checked) input.closest('.payment-btn').classList.add('selected');
});

// "dont_cash" needs a reason server-side. Without the required flag the browser
// submitted an empty reason, the server rejected it, and the doctor got a silent
// reload with nothing created and nothing sent to x-ray.
//
// The reason is a fixed choice (لەناو ئێش / پلەیەکی خێزان) or "هی تر". The note
// box below the dropdown stays visible either way — with "هی تر" it IS the
// reason and is required, otherwise it is an optional comment appended to the
// chosen label. Only the required flag and the placeholder move; nothing here
// hides the box, so a script failure can never leave the doctor with a required
// reason and no field to type it in.
function setPaymentReasonState(type) {
    var isDontCash = type === 'dont_cash';
    // Visibility is CSS's job (see the :has() rules on #payment_reason_group).
    // This function only sets the required flags, so if it never runs the field
    // is still on screen and the server-side check still catches an empty one.
    var keySelect = document.getElementById('paymentReasonKey');
    var otherInput = document.getElementById('paymentReasonOther');
    if (!keySelect || !otherInput) return;

    var isOther = keySelect.value === 'other';
    keySelect.required = isDontCash;
    otherInput.required = isDontCash && isOther;
    otherInput.placeholder = isOther ? 'هۆکارەکە بنووسە' : 'تێبینی (ئارەزوومەندانە)';
}

var paymentReasonKeyEl = document.getElementById('paymentReasonKey');
if (paymentReasonKeyEl) {
    paymentReasonKeyEl.addEventListener('change', function() {
        var checked = document.querySelector('.payment-btn input:checked');
        setPaymentReasonState(checked ? checked.value : '');
        if (this.value === 'other') document.getElementById('paymentReasonOther').focus();
    });
}
setPaymentReasonState((document.querySelector('.payment-btn input:checked') || {}).value);

// Double-submit guard: the 'submit' event only fires after native validation
// passes, so disabling here never blocks the browser's required-field prompts.
// Without this, a second click while the request is being created inserts a
// duplicate xray_requests row.
document.getElementById('xrayRequestForm').addEventListener('submit', function() {
    const btn = this.querySelector('.btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
