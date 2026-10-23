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

// Handle form submission BEFORE including header (to allow redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $patientId = intval($_POST['patient_id'] ?? 0);
    $caseId = intval($_POST['case_id'] ?? 0) ?: null;
    $doctorId = $auth->isAdmin() ? intval($_POST['doctor_id'] ?? $currentUser['id']) : intval($currentUser['id']);

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
    $paymentReason = trim($_POST['payment_reason'] ?? '');
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
    $allowedXrayReasons = ['implant', 'impaction', 'pathology', 'trauma', 'other'];
    $allowedPriorities = ['normal', 'urgent'];
    if (!in_array($paymentType, $allowedPaymentTypes, true)) {
        $paymentType = 'cash';
    }
    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'normal';
    }

    if (!$patientId) $errors['patient_id'] = 'Patient is required';
    if (!in_array($xrayType, $allowedXrayTypes, true)) $errors['xray_type'] = 'X-Ray type is required';
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
            'xray_type' => $xrayType,
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

            // Redirect based on payment type workflow
            if ($paymentType === 'dont_cash') {
                // Don't Cash goes directly to Xray department
                header('Location: ' . APP_URL . '/xray/view.php?id=' . $newRequestId . '&created=1');
            } elseif ($paymentType === 'cash' || $paymentType === 'debt') {
                // Both Cash and Debt go to Accountant for approval first
                header('Location: ' . APP_URL . '/accountant/pending-reports.php?xray_created=1&xray_id=' . $newRequestId);
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

// Get doctors for admin selection
$doctors = [];
if ($auth->isAdmin()) {
    $sql = "SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name";
    $doctors = $db->fetchAll($sql);
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

// X-Ray Types in English
$xrayTypes = [
    'panoramic' => 'Panoramic',
    'cephalometric' => 'Cephalometric',
    'periapical' => 'Periapical',
    'cbct' => 'CBCT (3D)'
];

// X-Ray Reasons in English
$xrayReasons = [
    'implant' => 'Dental Implant',
    'impaction' => 'Impactions',
    'pathology' => 'Pathology',
    'trauma' => 'Maxillofacial Trauma',
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

.chart-title {
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: #1a237e;
    margin-bottom: 12px;
}

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
                    <div class="field-label">Doctor</div>
                    <?php if ($auth->isAdmin() && !empty($doctors)): ?>
                    <select name="doctor_id" id="doctor_id" class="form-input">
                        <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= $doctor['id'] ?>" <?= $doctor['id'] == $currentUser['id'] ? 'selected' : '' ?>>
                            Dr. <?= htmlspecialchars($doctor['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <div class="field-value">
                        <i class="fas fa-user-md"></i>
                        <span>Dr. <?= htmlspecialchars($currentUser['full_name']) ?></span>
                    </div>
                    <input type="hidden" name="doctor_id" value="<?= $currentUser['id'] ?>">
                    <?php endif; ?>
                </div>
            </div>

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
                </label>
                <?php endforeach; ?>
            </div>

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

            <!-- Periapical Dental Chart -->
            <div class="dental-chart-box" id="dentalChartContainer">
                <div class="chart-title"><i class="fas fa-tooth"></i> Select Teeth (Periapical)</div>

                <!-- Upper Jaw -->
                <div class="jaw-row">
                    <span class="jaw-label">Upper L</span>
                    <div class="teeth-group">
                        <?php for($i = 28; $i >= 21; $i--): ?>
                        <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                            <span class="num"><?= $i % 10 ?></span>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <div class="center-line"></div>
                    <div class="teeth-group">
                        <?php for($i = 11; $i <= 18; $i++): ?>
                        <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                            <span class="num"><?= $i % 10 ?></span>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label">Upper R</span>
                </div>

                <!-- Lower Jaw -->
                <div class="jaw-row">
                    <span class="jaw-label">Lower L</span>
                    <div class="teeth-group">
                        <?php for($i = 38; $i >= 31; $i--): ?>
                        <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                            <span class="num"><?= $i % 10 ?></span>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <div class="center-line"></div>
                    <div class="teeth-group">
                        <?php for($i = 41; $i <= 48; $i++): ?>
                        <button type="button" class="tooth-btn" data-tooth="<?= $i ?>">
                            <span class="num"><?= $i % 10 ?></span>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <span class="jaw-label">Lower R</span>
                </div>

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
                        <span>کاش نییە</span>
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
                    <button type="button" id="recalcPriceBtn" title="بژماردنی خۆکار"
                            style="padding: 4px 12px; background: linear-gradient(135deg,#16a34a,#15803d); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="fas fa-calculator"></i> بژماردن
                    </button>
                    <?php if ($auth->isAdmin()): ?>
                    <a href="<?= APP_URL ?>/admin/xray-pricing.php" target="_blank" title="بەڕێوەبردنی نرخەکان"
                       style="padding: 4px 10px; background: #0078d4; color: #fff; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="fas fa-cog"></i> نرخەکان
                    </a>
                    <?php endif; ?>
                    <span id="priceHint" style="display:none; padding:4px 10px; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:6px; font-size:12px; font-weight:700;">
                        <i class="fas fa-info-circle"></i> <span id="priceHintText"></span>
                    </span>
                </div>

                <div class="amount-field">
                    <label>Priority:</label>
                    <select name="priority" class="form-input" style="width: 120px;">
                        <option value="normal" <?= ($_POST['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="urgent" <?= ($_POST['priority'] ?? '') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>
            </div>

            <div class="form-row" id="payment_reason_group" style="margin-top: 12px; <?= ($_POST['payment_type'] ?? 'cash') === 'dont_cash' ? '' : 'display: none;' ?>">
                <div class="col">
                    <div class="field-label">هۆکاری کاش نەکردن</div>
                    <input type="text" name="payment_reason" class="form-input"
                           placeholder="هۆکارەکە بنووسە" value="<?= htmlspecialchars($_POST['payment_reason'] ?? '') ?>">
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

    // Ensure Periapical type is selected + chart visible
    const periapical = document.querySelector('.option-btn input[name="xray_type"][value="periapical"]');
    if (periapical && !periapical.checked) {
        periapical.checked = true;
        document.querySelectorAll('.option-btn input[name="xray_type"]').forEach(i => i.closest('.option-btn').classList.remove('selected'));
        periapical.closest('.option-btn').classList.add('selected');
    }
    const chart = document.getElementById('dentalChartContainer');
    if (chart) chart.classList.add('visible');

    // Toggle active chip state
    const wasActive = chip.classList.contains('active');
    document.querySelectorAll('.area-chip.active').forEach(c => c.classList.remove('active'));

    // Clear ALL teeth first
    document.querySelectorAll('.tooth-btn.selected').forEach(t => t.classList.remove('selected'));
    if (window.selectedTeeth) window.selectedTeeth.length = 0;

    if (!wasActive) {
        chip.classList.add('active');
        // Select teeth in the chosen quadrant directly (no dependency on later code)
        const sideRanges = {
            left:  [[21,28], [31,38]],
            right: [[11,18], [41,48]],
            upper: [[11,18], [21,28]],
            lower: [[31,38], [41,48]],
        };
        const ranges = sideRanges[side] || [];
        const teethList = [];
        ranges.forEach(function(r) {
            for (let i = r[0]; i <= r[1]; i++) {
                const btn = document.querySelector('.tooth-btn[data-tooth="' + i + '"]');
                if (btn) {
                    btn.classList.add('selected');
                    teethList.push(String(i));
                }
            }
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
                            return `<div class="patient-result-item" data-id="${p.id}" data-name="${p.full_name}" data-age="${p.age || ''}">
                                <div class="name">${p.full_name}</div>
                                <div class="meta">${age} ${p.patient_code ? '• ' + p.patient_code : ''}</div>
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
    44: {p: 4, q: 'LR'}, 43: {p: 3, q: 'LR'}, 42: {p: 2, q: 'LR'}, 41: {p: 1, q: 'LR'}
};

if (selectedTeethInput.value) {
    selectedTeeth = selectedTeethInput.value.split(',').filter(t => t);
    updateSelectedTeethDisplay();
    selectedTeeth.forEach(tooth => {
        document.querySelector(`.tooth-btn[data-tooth="${tooth}"]`)?.classList.add('selected');
    });
}

function toggleDentalChart() {
    const type = document.querySelector('.option-btn[data-type] input:checked')?.value;
    dentalChartContainer.classList.toggle('visible', type === 'periapical');
}

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
                grouped[q].sort((a, b) => a - b);
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

// Ensure Periapical is selected and the chart is visible (Full Mouth / Determine
// only make sense for Periapical — so auto-switch if needed)
function ensurePeriapicalChart() {
    const periapicalRadio = document.querySelector('.option-btn input[name="xray_type"][value="periapical"]');
    if (periapicalRadio && !periapicalRadio.checked) {
        periapicalRadio.checked = true;
        // Update the option-btn .selected styling + fire toggleDentalChart
        document.querySelectorAll('.option-btn input[name="xray_type"]').forEach(i => i.closest('.option-btn').classList.remove('selected'));
        periapicalRadio.closest('.option-btn').classList.add('selected');
    }
    if (dentalChartContainer) dentalChartContainer.classList.add('visible');
}

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

// Manual recalc button
const recalcBtn = document.getElementById('recalcPriceBtn');
if (recalcBtn) recalcBtn.addEventListener('click', function() { computeAmount(); });

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

// ── Live price compute ──
const modeLabelsKu = {
    'full_mouth':'هەموو دەم', 'left_side':'چەپ', 'right_side':'ڕاست',
    'upper_arch':'سەرەوە', 'lower_arch':'خوارەوە',
    'per_tooth':'بۆ هەر ددانێک', 'single':'تاکە'
};

let priceComputeTimer = null;
function schedulePriceCompute() {
    if (priceComputeTimer) clearTimeout(priceComputeTimer);
    priceComputeTimer = setTimeout(computeAmount, 250);
}

async function computeAmount() {
    const typeEl = document.querySelector('.option-btn input[name="xray_type"]:checked');
    if (!typeEl) return;
    const type = typeEl.value;

    const payload = {
        action: 'compute',
        type: type,
        full_mouth: fullMouthCheckbox && fullMouthCheckbox.checked ? 1 : 0,
        determine_details: determineDetailsInput ? determineDetailsInput.value : '',
        teeth: selectedTeeth.join(','),
    };

    try {
        const r = await fetch('<?= APP_URL ?>/api/xray-pricing.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (!d.success) return;

        const amountInput = document.getElementById('amountInput');
        const hint = document.getElementById('priceHint');
        const hintText = document.getElementById('priceHintText');

        if (amountInput && d.price >= 0) amountInput.value = d.price;
        if (hint && hintText) {
            if (d.mode) {
                hintText.textContent = 'خۆکار لە: ' + (modeLabelsKu[d.mode] || d.mode);
                hint.style.display = 'inline-flex';
            } else {
                hint.style.display = 'none';
            }
        }
    } catch (e) { /* ignore */ }
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
            if (name === 'xray_type') toggleDentalChart();
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
        document.getElementById('payment_reason_group').style.display =
            this.value === 'dont_cash' ? 'flex' : 'none';
    });
    if (input.checked) input.closest('.payment-btn').classList.add('selected');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
