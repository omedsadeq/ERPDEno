<?php
/**
 * Accountant - Report Detail & Payment Processing
 * تەفسیلی ڕاپۆرت و جێبەجێکردنی پارەدان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/Installment.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$isAdmin     = $auth->isAdmin();
$isAccountant = $auth->isAccountant();
$db = Database::getInstance();

if (!$isAccountant && !$isAdmin) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$caseId = intval($_GET['case_id'] ?? 0);
if (!$caseId) {
    header('Location: ' . APP_URL . '/accountant/pending-reports.php');
    exit;
}

$caseManager      = new CaseManager();
$paymentManager   = new Payment();
$installmentManager = new Installment();

$case = $caseManager->getById($caseId);
if (!$case) {
    header('Location: ' . APP_URL . '/accountant/pending-reports.php');
    exit;
}

// Get accounting report for this case
$acctReport = $db->fetch("
    SELECT ar.*, u.full_name AS doctor_name
    FROM accounting_reports ar
    JOIN users u ON ar.sent_by = u.id
    WHERE ar.case_id = :cid ORDER BY ar.id DESC LIMIT 1
", ['cid' => $caseId]);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'approve_payment_request') {
        // Confirm a doctor's pending payment request → real collected money.
        // Ownership: the payment must belong to THIS case and still be pending.
        $paymentId = intval($_POST['payment_id'] ?? 0);
        $pay = $db->fetch("SELECT id, case_id, status FROM payments WHERE id = :id", ['id' => $paymentId]);
        if (!$pay || (int)$pay['case_id'] !== (int)$caseId || $pay['status'] !== 'pending') {
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=payment_failed');
            exit;
        }
        $result = $paymentManager->approve($paymentId, $currentUser['id']);
        if (!empty($result['success'])) {
            if ($acctReport) {
                $db->update('payments', ['accounting_report_id' => $acctReport['id']], 'id = :id AND accounting_report_id IS NULL', ['id' => $paymentId]);
                $after = $caseManager->getById($caseId);
                $newRemaining = (float)$after['total_amount'] - (float)$after['paid_amount'] - (float)$after['discount_amount'];
                $fullyPaid = $newRemaining <= 0;
                $db->update('accounting_reports', [
                    'status'       => $fullyPaid ? 'processed' : 'reviewed',
                    'processed_at' => $fullyPaid ? date('Y-m-d H:i:s') : null,
                    'processed_by' => $fullyPaid ? $currentUser['id'] : null
                ], 'id = :id', ['id' => $acctReport['id']]);
            }
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&payment_collected=1');
            exit;
        }
        // approve() may auto-reject a stale/over-balance request — surface that
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=payment_failed');
        exit;

    } elseif ($action === 'reject_payment_request') {
        $paymentId = intval($_POST['payment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $pay = $db->fetch("SELECT id, case_id, status FROM payments WHERE id = :id", ['id' => $paymentId]);
        if (!$pay || (int)$pay['case_id'] !== (int)$caseId || $pay['status'] !== 'pending') {
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=payment_failed');
            exit;
        }
        $paymentManager->reject($paymentId, $reason !== '' ? $reason : 'ڕەتکرایەوە لەلایەن ژمێریار', $currentUser['id']);
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&payment_rejected=1');
        exit;

    } elseif ($action === 'mark_reviewed' && $acctReport) {
        // Only from 'pending' — a stale tab must not downgrade a processed report
        $db->update('accounting_reports', ['status' => 'reviewed'], "id = :id AND status = 'pending'", ['id' => $acctReport['id']]);
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&reviewed=1');
        exit;

    } elseif ($action === 'archive_report' && $acctReport) {
        // Hide this report from the receiving feed + pending lists (soft, reversible).
        $db->update('accounting_reports', ['status' => 'archived'], "id = :id", ['id' => $acctReport['id']]);
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&archived=1');
        exit;

    } elseif ($action === 'restore_report' && $acctReport) {
        // Bring an archived report back into the active list (as "seen").
        $db->update('accounting_reports', ['status' => 'reviewed'], "id = :id AND status = 'archived'", ['id' => $acctReport['id']]);
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&restored=1');
        exit;

    } elseif ($action === 'apply_discount') {
        $discountType  = $_POST['discount_type'];
        $discountValue = floatval($_POST['discount_value']);
        $totalAmount   = (float)$case['total_amount'];
        // The discount may never exceed what is still UNPAID — otherwise
        // remaining goes negative while money already collected stays collected
        $maxDiscount   = max(0, $totalAmount - (float)$case['paid_amount']);
        $discountAmt   = $discountType === 'percentage' ? $totalAmount * $discountValue / 100 : $discountValue;
        if ($discountValue < 0
            || ($discountType === 'percentage' && $discountValue > 100)
            || $discountAmt > $maxDiscount + 0.01) {
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=invalid_discount');
            exit;
        }
        $caseManager->applyDiscount($caseId, $discountType, $discountValue);
        // Mark report as reviewed if still pending
        if ($acctReport && $acctReport['status'] === 'pending') {
            $db->update('accounting_reports', ['status' => 'reviewed'], 'id = :id', ['id' => $acctReport['id']]);
        }
        // If an active installment plan exists, recalculate pending monthly amounts
        $activePlan = $installmentManager->getPlanByCase($caseId);
        if ($activePlan) {
            $updatedCase  = $caseManager->getById($caseId);
            $newRemaining = max(0, $updatedCase['total_amount'] - $updatedCase['paid_amount'] - $updatedCase['discount_amount']);
            $installmentManager->recalculateAfterDiscount($activePlan['id'], $newRemaining);
        }
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&discount_applied=1');
        exit;

    } elseif ($action === 'collect_payment') {
        // Guard on the case's live balance (updated atomically by directPayment),
        // NOT on the report status — this prevents a double-collect even if a
        // later status write fails. Re-read the case first: the page may have
        // been open a while (or double-submitted) and $case is stale.
        $case = $caseManager->getById($caseId);
        $remaining = (float)$case['total_amount'] - (float)$case['paid_amount'] - (float)$case['discount_amount'];

        if ($remaining <= 0) {
            // Nothing left to collect. Finalize the report once, never create a
            // duplicate payment (fixes double-settle + unblocks a fully-paid case
            // that was sent to accounting).
            if ($acctReport && $acctReport['status'] !== 'processed') {
                $db->update('accounting_reports', [
                    'status' => 'processed', 'processed_at' => date('Y-m-d H:i:s'), 'processed_by' => $currentUser['id']
                ], 'id = :id', ['id' => $acctReport['id']]);
            }
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&payment_collected=1');
            exit;
        }

        $amount = floatval($_POST['amount']);
        if ($amount <= 0) {
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=invalid_amount');
            exit;
        }
        if ($amount > $remaining) {
            $amount = $remaining; // no overpayment — cap at the outstanding balance
        }

        $postedType = $_POST['payment_type'] ?? 'cash';
        $data = [
            'case_id'        => $caseId,
            'payment_type'   => in_array($postedType, ['cash', 'credit', 'installment'], true) ? $postedType : 'cash',
            'amount'         => $amount,
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'notes'          => trim($_POST['notes'] ?? ''),
            'received_by'    => $currentUser['id']
        ];
        // Accountant does direct payment (auto-approved)
        $result = $paymentManager->directPayment($data, $currentUser['id']);
        if ($result['success']) {
            // Link the payment to the report it settled (traceability)
            if (!empty($result['payment_id']) && $acctReport) {
                $db->update('payments', ['accounting_report_id' => $acctReport['id']], 'id = :id', ['id' => $result['payment_id']]);
            }
            // Mark processed only when this payment cleared the balance; a partial
            // payment keeps the report open (reviewed) so the rest can be collected.
            if ($acctReport) {
                $after = $caseManager->getById($caseId);
                $newRemaining = (float)$after['total_amount'] - (float)$after['paid_amount'] - (float)$after['discount_amount'];
                $fullyPaid = $newRemaining <= 0;
                $db->update('accounting_reports', [
                    'status'       => $fullyPaid ? 'processed' : 'reviewed',
                    'processed_at' => $fullyPaid ? date('Y-m-d H:i:s') : null,
                    'processed_by' => $fullyPaid ? $currentUser['id'] : null
                ], 'id = :id', ['id' => $acctReport['id']]);
            }
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&payment_collected=1');
            exit;
        }
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=payment_failed');
        exit;

    } elseif ($action === 'quick_settle') {
        $discountType  = $_POST['discount_type'] ?? 'fixed';
        $discountValue = floatval($_POST['discount_value'] ?? 0);
        $paymentAmount = floatval($_POST['amount'] ?? 0);
        $paymentType   = $_POST['payment_type'] ?? 'cash';
        if (!in_array($paymentType, ['cash', 'credit', 'installment'], true)) { $paymentType = 'cash'; }
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $notes         = trim($_POST['notes'] ?? '');

        // Fresh read — the form may have been open a while / double-submitted
        $case = $caseManager->getById($caseId);

        // Step 1: Apply discount first (if provided) — capped at the UNPAID part
        if ($discountValue > 0) {
            $maxDiscount = max(0, (float)$case['total_amount'] - (float)$case['paid_amount']);
            $discountAmt = $discountType === 'percentage' ? (float)$case['total_amount'] * $discountValue / 100 : $discountValue;
            $valid = true;
            if ($discountType === 'percentage' && ($discountValue < 0 || $discountValue > 100)) $valid = false;
            if ($discountValue < 0 || $discountAmt > $maxDiscount + 0.01) $valid = false;
            if ($valid) {
                $caseManager->applyDiscount($caseId, $discountType, $discountValue);
                // Re-fetch case with updated totals
                $case = $caseManager->getById($caseId);
            }
        }

        // Step 2: Process payment on updated remaining
        $newRemaining = (float)$case['total_amount'] - (float)$case['paid_amount'] - (float)$case['discount_amount'];
        if ($paymentAmount > 0 && $newRemaining > 0) {
            if ($paymentAmount > $newRemaining) $paymentAmount = $newRemaining; // no overpayment
            $data = [
                'case_id'        => $caseId,
                'payment_type'   => $paymentType,
                'amount'         => $paymentAmount,
                'payment_method' => $paymentMethod,
                'notes'          => $notes,
                'received_by'    => $currentUser['id']
            ];
            $result = $paymentManager->directPayment($data, $currentUser['id']);
            if ($result['success'] && $acctReport) {
                // Link the payment to the report it settled (traceability)
                if (!empty($result['payment_id'])) {
                    $db->update('payments', ['accounting_report_id' => $acctReport['id']], 'id = :id', ['id' => $result['payment_id']]);
                }
                $after = $caseManager->getById($caseId);
                $afterRemaining = (float)$after['total_amount'] - (float)$after['paid_amount'] - (float)$after['discount_amount'];
                $fullyPaid = $afterRemaining <= 0;
                $db->update('accounting_reports', [
                    'status'       => $fullyPaid ? 'processed' : 'reviewed',
                    'processed_at' => $fullyPaid ? date('Y-m-d H:i:s') : null,
                    'processed_by' => $fullyPaid ? $currentUser['id'] : null
                ], 'id = :id', ['id' => $acctReport['id']]);
            }
        } elseif ($newRemaining <= 0 && $acctReport && $acctReport['status'] !== 'processed') {
            // Case already fully paid (e.g. discount cleared it) — finalize the report,
            // never create a zero/duplicate payment.
            $db->update('accounting_reports', [
                'status' => 'processed', 'processed_at' => date('Y-m-d H:i:s'), 'processed_by' => $currentUser['id']
            ], 'id = :id', ['id' => $acctReport['id']]);
        }
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&settled=1');
        exit;

    } elseif ($action === 'update_payment_due_date') {
        $activePlanForDueDate = $installmentManager->getPlanByCase($caseId);
        $currentRemaining = max(0, (float)$case['total_amount'] - (float)$case['paid_amount'] - (float)$case['discount_amount']);

        if ($activePlanForDueDate || $currentRemaining <= 0) {
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=invalid_due_date');
            exit;
        }

        $paymentDueDate = trim($_POST['payment_due_date'] ?? '');
        if ($paymentDueDate !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $paymentDueDate);
            if (!$dt || $dt->format('Y-m-d') !== $paymentDueDate) {
                header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=invalid_due_date');
                exit;
            }
        } else {
            $paymentDueDate = null;
        }

        $db->update('cases', ['payment_due_date' => $paymentDueDate], 'id = :id', ['id' => $caseId]);
        if ($acctReport && $acctReport['status'] === 'pending') {
            $db->update('accounting_reports', ['status' => 'reviewed'], 'id = :id', ['id' => $acctReport['id']]);
        }
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&due_date_saved=1');
        exit;

    } elseif ($action === 'create_installment') {
        $data = [
            'case_id'                => $caseId,
            'total_amount'           => floatval($_POST['total_amount']),
            'down_payment'           => floatval($_POST['down_payment']),
            'number_of_installments' => intval($_POST['number_of_installments']),
            'start_date'             => $_POST['start_date'],
            'notes'                  => trim($_POST['notes'] ?? ''),
            'created_by'             => $currentUser['id']
        ];
        $result = $installmentManager->createPlan($data);
        if ($result['success']) {
            if ($acctReport) {
                $db->update('accounting_reports', ['status' => 'reviewed'], "id = :id AND status = 'pending'", ['id' => $acctReport['id']]);
            }
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&installment_created=1');
            exit;
        }
        // Failure must redirect too (PRG) — falling through re-rendered the page
        // with no message and a refresh re-submitted the POST
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=installment_failed');
        exit;

    } elseif ($action === 'pay_installment_flexible') {
        $planId = intval($_POST['plan_id']);
        // The plan must belong to THIS case — a crafted plan_id could otherwise
        // pay another patient's installments while auditing against this case
        $casePlan = $installmentManager->getPlanByCase($caseId);
        if (!$casePlan || (int)$casePlan['id'] !== $planId) {
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=installment_failed');
            exit;
        }
        $data   = [
            'amount'         => floatval($_POST['amount']),
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'notes'          => trim($_POST['notes'] ?? '')
        ];
        $result = $installmentManager->makeFlexiblePayment($planId, $data, $currentUser['id']);
        if ($result['success']) {
            // An installment payment that clears the balance must close the
            // report like collect_payment does, or it sits open forever
            if ($acctReport) {
                $after = $caseManager->getById($caseId);
                $afterRemaining = (float)$after['total_amount'] - (float)$after['paid_amount'] - (float)$after['discount_amount'];
                if ($afterRemaining <= 0) {
                    $db->update('accounting_reports', [
                        'status' => 'processed', 'processed_at' => date('Y-m-d H:i:s'), 'processed_by' => $currentUser['id']
                    ], "id = :id AND status != 'processed'", ['id' => $acctReport['id']]);
                }
            }
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&installment_paid=1');
            exit;
        }
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=installment_failed');
        exit;

    } elseif ($action === 'skip_installment_month') {
        $scheduleId = intval($_POST['schedule_id']);
        // The schedule row must belong to this case's plan
        $casePlan = $installmentManager->getPlanByCase($caseId);
        $schedRow = $scheduleId > 0 ? $db->fetch("SELECT plan_id FROM installment_schedule WHERE id = :id", ['id' => $scheduleId]) : null;
        if (!$casePlan || !$schedRow || (int)$schedRow['plan_id'] !== (int)$casePlan['id']) {
            header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . '&error=installment_failed');
            exit;
        }
        $result = $installmentManager->skipMonth($scheduleId);
        header('Location: ' . APP_URL . '/accountant/report-detail.php?case_id=' . $caseId . ($result['success'] ? '&installment_skipped=1' : '&error=installment_failed'));
        exit;
    }
}

// Refresh case after possible updates
$case = $caseManager->getById($caseId);
$caseTreatments  = $caseManager->getTreatments($caseId);
$payments        = $paymentManager->getByCase($caseId);
$installmentPlan = $installmentManager->getPlanByCase($caseId);

$remaining       = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount'];
$afterDiscount   = $case['total_amount'] - $case['discount_amount'];

// Get patient info
$patientSql = "SELECT p.*, pf.folder_name FROM patient_folders pf JOIN patients p ON pf.patient_id = p.id WHERE pf.id = :fid";
$patientInfo = $db->fetch($patientSql, ['fid' => $case['folder_id']]);

$installmentSummary  = $installmentPlan ? $installmentManager->getPlanSummary($installmentPlan['id']) : null;
$installmentPayments = $installmentPlan ? $installmentManager->getPlanPayments($installmentPlan['id']) : [];

$pageTitle   = 'تەفسیلی ڕاپۆرت - ' . $case['case_number'];
$breadcrumb  = [
    ['title' => 'داشبۆردی ژمێریار', 'url' => APP_URL . '/accountant/dashboard.php'],
    ['title' => 'ڕاپۆرتی چاوەڕوان',  'url' => APP_URL . '/accountant/pending-reports.php'],
    ['title' => $case['case_number']]
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.report-container { max-width:1400px; margin:0 auto; }
.report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
.report-title { display:flex; align-items:center; gap:12px; }
.report-title h2 { margin:0; font-size:20px; color:#1a1a1a; }
.report-title .icon { width:45px; height:45px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-size:20px; }
.action-bar { display:flex; gap:8px; flex-wrap:wrap; }
.btn-action { padding:9px 16px; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-weight:600; transition:all 0.2s; }
.btn-action:hover { transform:translateY(-1px); }
.btn-action.primary { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; }
.btn-action.success { background:linear-gradient(135deg,#107c10,#0e6b0e); color:white; }
.btn-action.warning { background:linear-gradient(135deg,#ff8c00,#e67700); color:white; }
.btn-action.danger  { background:linear-gradient(135deg,#c50f1f,#e53935); color:white; }
.btn-action.secondary { background:#f0f0f0; color:#333; border:1.5px solid #e0e0e0; }
.alert-banner { display:flex; align-items:center; gap:10px; border-radius:10px; padding:11px 16px; margin-bottom:16px; font-size:13px; font-weight:500; }
.alert-banner.success { background:#dff6dd; border:1.5px solid #107c10; color:#0a5e0a; }
.alert-banner.danger  { background:#fde7e9; border:1.5px solid #c50f1f; color:#8b0000; }
.alert-banner.warning { background:#fff4ce; border:1.5px solid #ff8c00; color:#7a5b00; }
.report-status-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; border-radius:10px; padding:12px 18px; margin-bottom:18px; font-size:13px; }
.report-status-bar.pending  { background:#fff4ce; border:1.5px solid #ff8c00; }
.report-status-bar.processed{ background:#dff6dd; border:1.5px solid #107c10; }
.report-status-bar.reviewed { background:#deecf9; border:1.5px solid #0078d4; }
.detail-panel { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:16px; overflow:hidden; }
.detail-panel-header { padding:14px 18px; background:linear-gradient(135deg,#fafafa,#f5f5f5); border-bottom:1px solid #f0f0f0; font-weight:600; display:flex; align-items:center; gap:8px; justify-content:space-between; font-size:14px; }
.detail-panel-body { padding:18px; }
.info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }
.info-item { display:flex; flex-direction:column; gap:4px; }
.info-label { font-size:11px; color:#888; font-weight:600; text-transform:uppercase; }
.info-value { font-size:14px; font-weight:600; color:#1a1a1a; }
.progress-bar { height:8px; background:#f0f0f0; border-radius:6px; overflow:hidden; margin-top:4px; }
.progress-fill { height:100%; border-radius:6px; }
.status-badge { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:14px; font-size:11px; font-weight:600; }
.report-table { width:100%; border-collapse:collapse; font-size:13px; }
.report-table th { background:#f8f9fa; padding:12px 14px; text-align:right; font-weight:600; color:#333; border-bottom:2px solid #e0e0e0; }
.report-table td { padding:12px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.report-table tr:hover td { background:#f9f9f9; }
.report-table tr:last-child td { border-bottom:none; }
.installment-summary-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:18px; }
.installment-summary-card { border-radius:10px; padding:14px; text-align:center; }
.installment-summary-card .is-label { font-size:11px; color:#888; margin-bottom:6px; }
.installment-summary-card .is-value { font-size:20px; font-weight:700; }
.pay-inst-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; background:#f8f9fa; border-radius:10px; padding:14px; margin-top:4px; }
.pay-inst-form .pif-group { display:flex; flex-direction:column; gap:4px; }
.pay-inst-form label { font-size:12px; font-weight:600; color:#555; }
.pay-inst-form input, .pay-inst-form select { padding:8px 10px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; }
.pay-inst-form input:focus, .pay-inst-form select:focus { border-color:#0078d4; outline:none; }
/* Dialogs */
.dialog-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; }
.dialog-overlay.open { display:flex; }
.dialog-box { background:white; border-radius:16px; padding:28px; width:90%; max-width:520px; box-shadow:0 20px 60px rgba(0,0,0,0.3); max-height:90vh; overflow-y:auto; }
.dialog-title { font-size:17px; font-weight:700; margin-bottom:20px; display:flex; align-items:center; gap:10px; color:#1a1a1a; justify-content:space-between; }
.dialog-title .dialog-close-btn { background:none; border:none; cursor:pointer; font-size:18px; color:#aaa; line-height:1; }
.dialog-title .dialog-close-btn:hover { color:#333; }
.form-field { margin-bottom:14px; }
.form-field label { display:block; font-size:12px; font-weight:600; color:#555; margin-bottom:5px; }
.form-field input, .form-field select, .form-field textarea { width:100%; padding:9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; box-sizing:border-box; }
.form-field input:focus, .form-field select:focus, .form-field textarea:focus { border-color:#0078d4; outline:none; box-shadow:0 0 0 3px rgba(0,120,212,0.1); }
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.dialog-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:16px; border-top:1px solid #f0f0f0; }
.btn-dialog-primary { padding:9px 20px; background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; font-weight:600; }
.btn-dialog-cancel  { padding:9px 20px; background:#f5f5f5; color:#666; border:1.5px solid #e0e0e0; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; font-weight:600; }
.btn-dialog-success { padding:9px 20px; background:linear-gradient(135deg,#107c10,#0e6b0e); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; font-weight:600; }
.btn-dialog-warning { padding:9px 20px; background:linear-gradient(135deg,#ff8c00,#e67700); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; font-weight:600; }
.remaining-info-box { background:#f8f9fa; border:1.5px solid #e0e0e0; border-radius:10px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; font-size:13px; }
@media(max-width:768px) {
    .info-grid, .installment-summary-grid, .form-grid-2 { grid-template-columns:1fr; }
    .action-bar { flex-direction:column; }
}

/* ═══════════ Traditional compact invoice layout ═══════════ */
/* Full-width wrap (not A4-narrow) */
.report-container { max-width:none; }
.inv { background:#fff; border:1px solid #cfcfcf; border-radius:6px; overflow:hidden; margin-bottom:14px; }
.inv-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; padding:12px 16px; border-bottom:2px solid #333; flex-wrap:wrap; }
.inv-head-brand { font-size:17px; font-weight:800; color:#1a1a1a; display:flex; align-items:center; gap:8px; }
.inv-head-brand small { display:block; font-weight:500; font-size:11px; color:#888; margin-top:2px; }
/* Meta: two label:value pairs per row */
.inv-meta { display:grid; grid-template-columns:auto auto auto auto; gap:5px 10px; font-size:12.5px; align-content:start; }
.inv-meta .m-l { color:#777; white-space:nowrap; }
.inv-meta .m-v { font-weight:700; color:#1a1a1a; white-space:nowrap; padding-left:8px; }
@media(max-width:560px){ .inv-meta { grid-template-columns:auto 1fr; } }
/* Compact installment block styled like the receipt — white, very tight */
.inv-mini { border:1px solid #d5d5d5; border-radius:5px; overflow:hidden; margin-bottom:10px; background:#fff; }
.inv-mini-head { background:#fff; border-bottom:1.5px solid #333; padding:6px 10px; font-weight:700; font-size:12.5px; display:flex; justify-content:space-between; align-items:center; }
.inv-mini-body { padding:8px 10px; background:#fff; }
.inv-mini .inv-table { font-size:12px; }
.inv-mini .inv-table td { padding:4px 7px; }
.inv-mini .inv-table th { padding:5px 7px; }
.inv-mini .inv-table tbody tr:nth-child(even) td { background:#fff; }   /* white rows */
.inv-mini h6 { margin:8px 0 4px !important; font-size:12px !important; color:#555; }
/* Compact flexible-payment form (white) */
.inv-mini .flex-pay { background:#fff; border:1px solid #e2e2e2; border-radius:5px; padding:8px 10px; margin-bottom:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
.inv-mini .flex-pay label { display:block; font-size:11px; margin-bottom:2px; color:#666; }
.inv-mini .flex-pay input, .inv-mini .flex-pay select { padding:5px 8px; border:1px solid #ccc; border-radius:5px; font-size:12px; font-family:inherit; width:100%; }
.inv-mini .flex-pay .btn-action { padding:6px 12px; font-size:12px; }
.inv-mini .alert-banner { padding:6px 10px; margin-bottom:8px; font-size:12px; }
.inv-mini .status-badge { padding:2px 8px; font-size:10.5px; }
/* Two columns: works right (~68%), summary+actions left (~32%). RTL → first child = right. */
.inv-grid { display:grid; grid-template-columns:1fr 320px; gap:0; }
.inv-main { padding:0; border-left:1px solid #e2e2e2; min-width:0; }
.inv-side { padding:0; background:#fafafa; }
@media(max-width:820px){ .inv-grid { grid-template-columns:1fr; } .inv-main { border-left:none; border-bottom:1px solid #e2e2e2; } }
/* Excel-like invoice table */
.inv-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.inv-table thead th { background:#f2f2f2; color:#333; font-weight:700; padding:7px 8px; border:1px solid #d5d5d5; text-align:center; white-space:nowrap; }
.inv-table tbody td { padding:6px 8px; border:1px solid #e2e2e2; vertical-align:middle; }
.inv-table tbody tr:nth-child(even) td { background:#fbfbfb; }
.inv-table tfoot td { padding:8px; border:1px solid #d5d5d5; background:#f2f2f2; font-weight:800; }
.inv-table .tnum { text-align:center; color:#999; width:30px; }
.inv-table .tteeth { text-align:center; font-weight:700; }
.inv-table .tright { text-align:left; direction:ltr; unicode-bidi:embed; white-space:nowrap; }
.inv-empty { padding:26px; text-align:center; color:#aaa; font-size:13px; }
/* Financial summary (prints as invoice totals) */
.inv-sum { padding:10px 14px; }
.inv-sum-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px dashed #e0e0e0; font-size:13px; }
.inv-sum-row:last-child { border-bottom:none; }
.inv-sum-row .s-l { color:#555; }
.inv-sum-row .s-v { font-weight:700; direction:ltr; unicode-bidi:embed; }
.inv-sum-row.grand { margin-top:4px; border-top:2px solid #333; border-bottom:none; padding-top:8px; }
.inv-sum-row.grand .s-l { font-size:14px; font-weight:800; color:#1a1a1a; }
.inv-sum-row.grand .s-v { font-size:19px; font-weight:800; }
/* Action buttons column */
.inv-actions { padding:10px 14px 14px; border-top:1px solid #ececec; display:flex; flex-direction:column; gap:7px; }
.inv-actions .btn-action { justify-content:center; width:100%; padding:9px 12px; }
.inv-actions form { display:block; }
.inv-actions form .btn-action { width:100%; }
/* Below (management tools) tighter panels */
.inv-below .detail-panel { margin-bottom:12px; box-shadow:none; border:1px solid #e2e2e2; }
.inv-below .detail-panel-header { padding:9px 14px; background:#f7f7f7; font-size:13px; }
.inv-below .report-table th { padding:8px 10px; }
.inv-below .report-table td { padding:8px 10px; }

/* ═══════════ Print: official receipt only ═══════════ */
@media print {
    @page { margin:12mm; }
    body * { visibility:hidden !important; }
    .inv-page, .inv-page * { visibility:visible !important; }
    .inv-page { position:absolute; inset:0; margin:0; padding:0; max-width:none; width:100%; }
    .no-print, .no-print * { display:none !important; }
    .inv { border:1px solid #000; box-shadow:none; }
    .inv-grid { grid-template-columns:1fr 300px; }
    .inv-main { border-left:1px solid #000; }
    .inv-side { background:#fff; }
    .inv-table thead th, .inv-table tbody td, .inv-table tfoot td { border:1px solid #000; }
    .report-status-bar, .alert-banner { display:none !important; }
    a[href]:after { content:none !important; }
}
</style>

<div class="report-container inv-page">

    <!-- Flash messages -->
    <?php if (isset($_GET['settled'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> داشکاندن و پارەدانەکە بەسەرکەوتوویی جێبەجێ کرا.</div>
    <?php elseif (isset($_GET['payment_collected'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> پارەکە بەسەرکەوتوویی وەرگیرا و تۆمار کرا.</div>
    <?php elseif (isset($_GET['payment_rejected'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> داواکاری پارەدانەکە ڕەتکرایەوە.</div>
    <?php elseif (isset($_GET['discount_applied'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> داشکاندنەکە جێبەجێ کرا.</div>
    <?php elseif (isset($_GET['installment_created'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> پلانی قیستەکان دروست کرا.</div>
    <?php elseif (isset($_GET['installment_paid'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> قیستەکە وەرگیرا.</div>
    <?php elseif (isset($_GET['installment_skipped'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> قیستەکە ڕاپەڕێندرا و ماوەکەی گوازرایەوە بۆ مانگی داهاتوو.</div>
    <?php elseif (isset($_GET['due_date_saved'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> بەرواری کۆکردنەوەی قەرز پاشەکەوتکرا.</div>
    <?php elseif (isset($_GET['archived'])): ?>
    <div class="alert-banner success"><i class="fas fa-eye-slash"></i> ڕاپۆرتەکە لابرا و لە لیستەکان نامێنێت. دەتوانیت لە تابی «لابراوەکان» بیگەڕێنیتەوە.</div>
    <?php elseif (isset($_GET['restored'])): ?>
    <div class="alert-banner success"><i class="fas fa-check-circle"></i> ڕاپۆرتەکە گەڕێندرایەوە بۆ لیستەکان.</div>
    <?php elseif (isset($_GET['error'])): ?>
    <?php
    $errMessages = [
        'invalid_discount'   => 'داشکاندنەکە دروست نییە — ناتوانێت لە بڕی نەدراو زیاتر بێت.',
        'invalid_amount'     => 'بڕی پارەکە دروست نییە.',
        'payment_failed'     => 'تۆمارکردنی پارەدانەکە سەرکەوتوو نەبوو. تکایە دووبارە هەوڵبدەرەوە.',
        'installment_failed' => 'کرداری قیستەکە جێبەجێ نەکرا. تکایە دووبارە هەوڵبدەرەوە.',
        'invalid_due_date'   => 'بەرواری کۆکردنەوە دروست نییە.',
    ];
    $errKey = is_string($_GET['error']) ? $_GET['error'] : '';
    ?>
    <div class="alert-banner danger"><i class="fas fa-exclamation-triangle"></i> <?= $errMessages[$errKey] ?? 'هەڵەیەک ڕوویدا. تکایە دووبارە هەوڵبدەرەوە.' ?></div>
    <?php endif; ?>

    <!-- Report Status Banner -->
    <?php if ($acctReport): ?>
    <?php
    $rStatus = $acctReport['status'];
    $rStatusClass = $rStatus === 'pending' ? 'pending' : ($rStatus === 'processed' ? 'processed' : 'reviewed');
    $rStatusIcon  = $rStatus === 'pending' ? 'hourglass-half' : ($rStatus === 'processed' ? 'check-circle' : ($rStatus === 'archived' ? 'eye-slash' : 'eye'));
    $rStatusColor = $rStatus === 'pending' ? '#ff8c00' : ($rStatus === 'processed' ? '#107c10' : ($rStatus === 'archived' ? '#888' : '#0078d4'));
    $rStatusLabel = $rStatus === 'pending' ? 'چاوەڕوان' : ($rStatus === 'processed' ? 'جێبەجێکراوە' : ($rStatus === 'archived' ? 'لابراو' : 'بینراوە'));
    ?>
    <div class="report-status-bar <?= $rStatusClass ?>">
        <span>
            <i class="fas fa-<?= $rStatusIcon ?>" style="color:<?= $rStatusColor ?>;margin-left:6px;"></i>
            ناردراوە لەلایەن <strong><?= htmlspecialchars($acctReport['doctor_name'] ?? '') ?></strong>
            — <?= date('Y-m-d H:i', strtotime($acctReport['sent_at'])) ?>
            <?php if ($acctReport['notes']): ?> — <?= htmlspecialchars($acctReport['notes']) ?><?php endif; ?>
        </span>
        <span style="font-weight:700;color:<?= $rStatusColor ?>;"><?= $rStatusLabel ?></span>
    </div>
    <?php endif; ?>

    <?php
    $paidPct     = $afterDiscount > 0 ? min(100, round(($case['paid_amount'] / $afterDiscount) * 100)) : 100;
    $statusLabel = $case['status'] === 'open' ? 'کراوە' : ($case['status'] === 'pending' ? 'چاوەڕوان' : 'داخراو');
    $statusColor = $case['status'] === 'open' ? '#107c10' : ($case['status'] === 'pending' ? '#ff8c00' : '#888');
    $statusBg    = $case['status'] === 'open' ? '#dff6dd' : ($case['status'] === 'pending' ? '#fff4ce' : '#f0f0f0');
    ?>

    <!-- ═══════════ INVOICE ═══════════ -->
    <div class="inv">
        <div class="inv-head">
            <div class="inv-head-brand">
                <i class="fas fa-file-invoice-dollar" style="color:#0078d4;"></i>
                <span>پسوڵەی چارەسەر<small><?= htmlspecialchars(APP_NAME) ?></small></span>
            </div>
            <div class="inv-meta">
                <span class="m-l">نەخۆش:</span>       <span class="m-v"><?= htmlspecialchars($case['patient_name']) ?></span>
                <span class="m-l">ژمارەی کەیس:</span>  <span class="m-v"><?= htmlspecialchars($case['case_number']) ?></span>
                <span class="m-l">دکتۆر:</span>        <span class="m-v"><?= htmlspecialchars($acctReport['doctor_name'] ?? '—') ?></span>
                <span class="m-l">تێلیفۆن:</span>      <span class="m-v"><?= htmlspecialchars($case['patient_phone'] ?? '—') ?></span>
                <span class="m-l">بەروار:</span>       <span class="m-v"><?= date('Y-m-d', strtotime($case['created_at'])) ?></span>
                <span class="m-l">دۆخ:</span>          <span class="m-v" style="color:<?= $statusColor ?>;"><?= $statusLabel ?></span>
            </div>
        </div>

        <div class="inv-grid">
            <!-- RIGHT: works table -->
            <div class="inv-main">

    <!-- Treatments Panel -->
    <?php
    // FDI → Palmer mapping (same as DentalChart class)
    $fdiToPalmer = [
        18=>'UR-8',17=>'UR-7',16=>'UR-6',15=>'UR-5',14=>'UR-4',13=>'UR-3',12=>'UR-2',11=>'UR-1',
        21=>'UL-1',22=>'UL-2',23=>'UL-3',24=>'UL-4',25=>'UL-5',26=>'UL-6',27=>'UL-7',28=>'UL-8',
        31=>'LL-1',32=>'LL-2',33=>'LL-3',34=>'LL-4',35=>'LL-5',36=>'LL-6',37=>'LL-7',38=>'LL-8',
        48=>'LR-8',47=>'LR-7',46=>'LR-6',45=>'LR-5',44=>'LR-4',43=>'LR-3',42=>'LR-2',41=>'LR-1',
        55=>'UR-E',54=>'UR-D',53=>'UR-C',52=>'UR-B',51=>'UR-A',
        61=>'UL-A',62=>'UL-B',63=>'UL-C',64=>'UL-D',65=>'UL-E',
        71=>'LL-A',72=>'LL-B',73=>'LL-C',74=>'LL-D',75=>'LL-E',
        85=>'LR-E',84=>'LR-D',83=>'LR-C',82=>'LR-B',81=>'LR-A',
    ];
    // Group treatments by name + unit_price (same logic as cases/view.php)
    $groupedTreatments = [];
    foreach ($caseTreatments as $ct) {
        $key = $ct['treatment_name'] . '_' . $ct['unit_price'];
        if (!isset($groupedTreatments[$key])) {
            $groupedTreatments[$key] = [
                'treatment_name' => $ct['treatment_name'],
                'unit_price'     => $ct['unit_price'],
                'upper_left'     => [], 'upper_right'  => [],
                'lower_left'     => [], 'lower_right'  => [],
                'total_quantity' => 0,  'total_price'  => 0,
                'treatment_ids'  => []
            ];
        }
        if ($ct['tooth_number']) {
            $palmerNotation = $fdiToPalmer[$ct['tooth_number']] ?? null;
            if ($palmerNotation) {
                [$quad, $num] = explode('-', $palmerNotation);
                if ($quad === 'UL') $groupedTreatments[$key]['upper_left'][]  = $num;
                elseif ($quad === 'UR') $groupedTreatments[$key]['upper_right'][] = $num;
                elseif ($quad === 'LL') $groupedTreatments[$key]['lower_left'][]  = $num;
                elseif ($quad === 'LR') $groupedTreatments[$key]['lower_right'][] = $num;
            }
        }
        $groupedTreatments[$key]['total_quantity'] += $ct['quantity'];
        $groupedTreatments[$key]['total_price']    += $ct['final_price'];
        $groupedTreatments[$key]['treatment_ids'][] = $ct['id'];
    }
    ?>
    <div style="overflow-x:auto;">
        <?php if (empty($caseTreatments)): ?>
        <div class="inv-empty"><i class="fas fa-inbox" style="font-size:22px;display:block;margin-bottom:6px;color:#ddd;"></i>هیچ چارەسەرێک تۆمار نەکراوە</div>
        <?php else: ?>
        <table class="inv-table" style="min-width:560px;">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>ناوی چارەسەر</th>
                    <th style="width:64px;">UL</th>
                    <th style="width:64px;">UR</th>
                    <th style="width:64px;">LL</th>
                    <th style="width:64px;">LR</th>
                    <th style="width:50px;">ژمارە</th>
                    <th style="width:84px;">نرخی یەکە</th>
                    <th style="width:94px;">کۆی نرخ</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowNum = 1; foreach ($groupedTreatments as $gt): ?>
            <tr>
                <td class="tnum"><?= $rowNum++ ?></td>
                <td style="font-weight:700;"><?= htmlspecialchars($gt['treatment_name']) ?></td>
                <td class="tteeth"><?= !empty($gt['upper_left'])  ? implode(', ', $gt['upper_left'])  : '·' ?></td>
                <td class="tteeth"><?= !empty($gt['upper_right']) ? implode(', ', $gt['upper_right']) : '·' ?></td>
                <td class="tteeth"><?= !empty($gt['lower_left'])  ? implode(', ', $gt['lower_left'])  : '·' ?></td>
                <td class="tteeth"><?= !empty($gt['lower_right']) ? implode(', ', $gt['lower_right']) : '·' ?></td>
                <td class="tteeth"><?= number_format($gt['total_quantity'], fmod((float)$gt['total_quantity'], 1) > 0 ? 2 : 0) ?></td>
                <td class="tright"><?= number_format($gt['unit_price']) ?></td>
                <td class="tright" style="font-weight:800;color:#c50f1f;"><?= number_format($gt['total_price']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="8" style="text-align:left;">کۆی گشتی</td>
                    <td class="tright" style="font-size:14px;color:#c50f1f;"><?= number_format($case['total_amount']) ?></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>

            </div><!-- /.inv-main -->

            <!-- LEFT: financial summary + actions -->
            <div class="inv-side">
                <div class="inv-sum">
                    <div class="inv-sum-row"><span class="s-l">کۆی گشتی</span><span class="s-v"><?= number_format($case['total_amount']) ?></span></div>
                    <div class="inv-sum-row">
                        <span class="s-l">داشکاندن
                            <?php if ($remaining > 0): ?>
                            <button onclick="openDialog('discountDialog')" class="no-print" style="background:#fff4ce;border:none;color:#e67700;border-radius:5px;cursor:pointer;font-size:10px;padding:1px 5px;" title="گۆڕینی داشکاندن"><i class="fas fa-pen"></i></button>
                            <?php endif; ?>
                        </span>
                        <span class="s-v" style="color:#ff8c00;">- <?= number_format($case['discount_amount']) ?></span>
                    </div>
                    <div class="inv-sum-row"><span class="s-l">پارەی دراو</span><span class="s-v" style="color:#107c10;"><?= number_format($case['paid_amount']) ?></span></div>
                    <div class="inv-sum-row grand"><span class="s-l">ماوە</span><span class="s-v" style="color:<?= $remaining > 0 ? '#c50f1f' : '#107c10' ?>;"><?= number_format($remaining) ?></span></div>
                </div>

                <div class="inv-actions no-print">
                    <button class="btn-action primary" onclick="window.print()"><i class="fas fa-print"></i> چاپکردنی پسوڵە</button>
                    <?php if ($acctReport && $acctReport['status'] === 'pending'): ?>
                    <form method="POST"><input type="hidden" name="action" value="mark_reviewed"><button type="submit" class="btn-action secondary"><i class="fas fa-eye"></i> بینراو مارک بکە</button></form>
                    <?php endif; ?>
                    <?php if ($remaining > 0): ?>
                        <?php if (!$installmentPlan): ?>
                        <button class="btn-action success" onclick="openDialog('collectPaymentDialog')"><i class="fas fa-money-bill-wave"></i> وەرگرتنی پارە</button>
                        <button class="btn-action warning" onclick="openDialog('quickSettleDialog')"><i class="fas fa-bolt"></i> داشکاندن + پارەدان</button>
                        <?php endif; ?>
                        <button class="btn-action secondary" onclick="openDialog('discountDialog')"><i class="fas fa-percent"></i> داشکاندن</button>
                        <?php if (!$installmentPlan): ?>
                        <button class="btn-action secondary" onclick="openDialog('installmentDialog')"><i class="fas fa-credit-card"></i> پلانی قیست</button>
                        <?php endif; ?>
                    <?php elseif ($acctReport && $acctReport['status'] !== 'processed'): ?>
                        <form method="POST" onsubmit="return confirm('ئەم کەیسە پارەی تەواو دراوە. ڕاپۆرتەکە وەک جێبەجێکراو دابخرێت؟');">
                            <input type="hidden" name="action" value="collect_payment">
                            <input type="hidden" name="payment_type" value="cash">
                            <input type="hidden" name="amount" value="0">
                            <button type="submit" class="btn-action success"><i class="fas fa-check-circle"></i> کۆتاییهێنان بە ڕاپۆرت</button>
                        </form>
                    <?php endif; ?>
                    <a href="<?= APP_URL ?>/cases/receipt.php?id=<?= $caseId ?>" target="_blank" class="btn-action secondary"><i class="fas fa-file-alt"></i> وەسڵی کەیس</a>
                    <?php if ($acctReport && $acctReport['status'] === 'archived'): ?>
                    <form method="POST"><input type="hidden" name="action" value="restore_report"><button type="submit" class="btn-action" style="background:#eef; color:#0078d4; border:1px solid #cdd; width:100%;"><i class="fas fa-rotate-left"></i> گەڕاندنەوە بۆ لیست</button></form>
                    <?php elseif ($acctReport): ?>
                    <form method="POST" onsubmit="return confirm('ئەم ڕاپۆرتە لاببرێت لە لیستەکان؟ (دەتوانیت پاشان بیگەڕێنیتەوە)');">
                        <input type="hidden" name="action" value="archive_report">
                        <button type="submit" class="btn-action" style="background:#f3f3f3; color:#777; border:1px solid #ddd; width:100%;"><i class="fas fa-eye-slash"></i> لابردن لە لیست</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div><!-- /.inv-side -->
        </div><!-- /.inv-grid -->
    </div><!-- /.inv -->

    <div class="inv-below">

    <?php if ($remaining > 0 && !$installmentPlan): ?>
    <div class="detail-panel">
        <div class="detail-panel-header">
            <span><i class="fas fa-calendar-check" style="color:#0078d4;"></i> بەرواری کۆکردنەوەی قەرزی ئاسایی</span>
            <?php if (!empty($case['payment_due_date'])): ?>
            <a href="<?= APP_URL ?>/accountant/debt-collection.php?due_filter=custom_date&due_date=<?= urlencode($case['payment_due_date']) ?>&sort=next_due_date&dir=ASC"
               style="font-size:12px;color:#0078d4;text-decoration:none;">
                بینین لە کۆکردنەوەی قەرز
            </a>
            <?php endif; ?>
        </div>
        <div class="detail-panel-body">
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="action" value="update_payment_due_date">
                <div class="form-field" style="min-width:220px;margin-bottom:0;">
                    <label>بەرواری پارەدان / کۆکردنەوە</label>
                    <input type="date" name="payment_due_date" value="<?= htmlspecialchars($case['payment_due_date'] ?? '') ?>">
                </div>
                <button type="submit" class="btn-dialog-primary">
                    <i class="fas fa-save"></i> پاشەکەوتکردنی بەروار
                </button>
                <?php if (!empty($case['payment_due_date'])): ?>
                <button type="submit" name="payment_due_date" value="" class="btn-dialog-cancel">
                    سڕینەوەی بەروار
                </button>
                <?php endif; ?>
            </form>
            <div style="margin-top:10px;font-size:12px;color:#666;">
                ئەم بەروارە تەنها بۆ قەرزی ئاسایی بەکاردێت. کەیسە قیستییەکان لە کۆکردنەوەی قەرزدا بە پێی خشتەی قیست دەردەکەون.
            </div>
        </div>
    </div>
    <?php endif; ?>


    <!-- Installment Plan Panel -->
    <?php if ($installmentPlan && $installmentSummary): ?>
    <div class="inv-mini">
        <div class="inv-mini-head">
            <span><i class="fas fa-credit-card" style="color:#8764b8;"></i> پلانی قیستەکان</span>
            <span style="font-weight:400;color:#666;font-size:12px;"><?= htmlspecialchars($installmentPlan['number_of_installments'] ?? '') ?> قیست · پێشکەوتن <?= $installmentSummary['progress_percent'] ?>%</span>
        </div>
        <div class="inv-mini-body">

            <!-- Compact summary (receipt style) -->
            <table class="inv-table" style="max-width:520px;margin-bottom:10px;">
                <tbody>
                    <tr>
                        <td style="width:110px;">کۆی گشتی</td>
                        <td class="tright"><?= number_format($installmentSummary['total_amount']) ?></td>
                        <td style="width:120px;">دراوە (+پێشەکی)</td>
                        <td class="tright" style="color:#107c10;"><?= number_format($installmentSummary['total_paid']) ?></td>
                    </tr>
                    <tr>
                        <td>ماوە</td>
                        <td class="tright" style="color:<?= $installmentSummary['remaining_balance'] > 0 ? '#c50f1f' : '#107c10' ?>;font-weight:800;"><?= number_format($installmentSummary['remaining_balance']) ?></td>
                        <td>پێشکەوتن</td>
                        <td class="tright"><?= $installmentSummary['progress_percent'] ?>%</td>
                    </tr>
                </tbody>
            </table>

            <!-- Progress Bar (slim) -->
            <div style="background:#e5e7eb;border-radius:4px;height:6px;margin-bottom:12px;overflow:hidden;max-width:520px;">
                <div style="background:#107c10;height:100%;width:<?= $installmentSummary['progress_percent'] ?>%;"></div>
            </div>

            <?php if ($installmentSummary['overdue_amount'] > 0): ?>
            <div class="alert-banner warning" style="margin-bottom:12px;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>دواکەوتوو:</strong> <bdi><?= number_format($installmentSummary['overdue_amount']) ?></bdi>
            </div>
            <?php endif; ?>

            <?php if ($installmentSummary['credit_balance'] > 0): ?>
            <div class="alert-banner success" style="margin-bottom:12px;">
                <i class="fas fa-plus-circle"></i>
                <strong>باڵانسی زیادە:</strong> <bdi><?= number_format($installmentSummary['credit_balance']) ?></bdi>
            </div>
            <?php endif; ?>

            <!-- Flexible Payment Form -->
            <?php if ($case['status'] === 'open' && $installmentSummary['remaining_balance'] > 0): ?>
            <form method="POST" class="flex-pay">
                <input type="hidden" name="action" value="pay_installment_flexible">
                <input type="hidden" name="plan_id" value="<?= $installmentPlan['id'] ?>">
                <div style="flex:1;min-width:110px;">
                    <label><i class="fas fa-money-bill-wave"></i> بڕی پارەدان</label>
                    <input type="number" name="amount" required min="1" placeholder="هەر بڕێک">
                </div>
                <div style="flex:1;min-width:90px;">
                    <label>شێواز</label>
                    <select name="payment_method">
                        <option value="cash">نەقد</option>
                        <option value="card">کارت</option>
                        <option value="transfer">گواستنەوە</option>
                    </select>
                </div>
                <div style="flex:2;min-width:130px;">
                    <label>تێبینی</label>
                    <input type="text" name="notes" placeholder="ئارەزوومەندانە">
                </div>
                <button type="submit" class="btn-action success"><i class="fas fa-check"></i> پارەدان</button>
            </form>
            <?php endif; ?>

            <!-- Schedule Table -->
            <?php if (!empty($installmentSummary['schedule'])): ?>
            <h6 style="margin:12px 0 6px;font-size:13px;"><i class="fas fa-calendar-alt"></i> خشتەی قیستەکان</h6>
            <div style="overflow-x:auto;">
                <table class="inv-table" style="min-width:520px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>بەروار</th>
                            <th>پلان</th>
                            <th>دابەشکراو</th>
                            <th>ماوە</th>
                            <th>دۆخ</th>
                            <?php if ($case['status'] === 'open'): ?>
                            <th>کردار</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($installmentSummary['schedule'] as $sch): ?>
                        <tr style="<?= $sch['is_overdue'] ? 'background:#fff8e1;' : '' ?>">
                            <td><strong><bdi><?= $sch['installment_number'] ?></bdi></strong></td>
                            <td>
                                <span style="<?= $sch['is_overdue'] ? 'color:#c50f1f;' : '' ?>">
                                    <?= date('Y-m-d', strtotime($sch['due_date'])) ?>
                                </span>
                                <?php if ($sch['is_overdue']): ?>
                                    <i class="fas fa-exclamation-circle" style="color:#c50f1f;font-size:10px;"></i>
                                <?php endif; ?>
                            </td>
                            <td><bdi><?= number_format($sch['amount']) ?></bdi></td>
                            <td style="color:#107c10;"><strong><bdi><?= number_format($sch['dynamic_paid']) ?></bdi></strong></td>
                            <td style="color:<?= $sch['dynamic_remaining'] > 0 ? '#c50f1f' : '#107c10' ?>;">
                                <strong><bdi><?= number_format($sch['dynamic_remaining']) ?></bdi></strong>
                            </td>
                            <td>
                                <?php
                                $iStatuses = [
                                    'pending'  => ['badge-orange',    'چاوەڕوان'],
                                    'paid'     => ['badge-green',     'دراوە'],
                                    'partial'  => ['badge-blue',      'بەشێک'],
                                    'overdue'  => ['badge-red',       'دواکەوتوو'],
                                    'skipped'  => ['badge-secondary', 'ڕاپەڕێندراو']
                                ];
                                $ist = $iStatuses[$sch['dynamic_status']] ?? ['', $sch['dynamic_status']];
                                ?>
                                <span class="status-badge <?= $ist[0] ?>"><?= $ist[1] ?></span>
                            </td>
                            <?php if ($case['status'] === 'open'): ?>
                            <td>
                                <?php if ($sch['dynamic_remaining'] > 0 && $sch['dynamic_status'] !== 'paid'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('دڵنیایت دەتەوێت <?= number_format($sch['dynamic_remaining']) ?> بگوازیتەوە بۆ مانگی داهاتوو؟');">
                                    <input type="hidden" name="action" value="skip_installment_month">
                                    <input type="hidden" name="schedule_id" value="<?= $sch['id'] ?>">
                                    <button type="submit" class="btn-action" style="padding:4px 8px;font-size:12px;background:#f59e0b;color:#fff;" title="ڕاپەڕاندن - گواستنەوەی ماوە بۆ مانگی داهاتوو">
                                        <i class="fas fa-forward"></i>
                                    </button>
                                </form>
                                <?php elseif ($sch['dynamic_status'] === 'paid'): ?>
                                <span style="color:#107c10;"><i class="fas fa-check"></i></span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Payment History -->
            <?php if (!empty($installmentPayments)): ?>
            <h6 style="margin:14px 0 6px;font-size:13px;"><i class="fas fa-history"></i> مێژووی پارەدانەکان</h6>
            <div style="overflow-x:auto;">
                <table class="inv-table" style="min-width:420px;">
                    <thead>
                        <tr>
                            <th>بەروار</th>
                            <th>بڕ</th>
                            <th>شێواز</th>
                            <th>تێبینی</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($installmentPayments as $ipay):
                            $methods = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'تر'];
                        ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($ipay['paid_at'])) ?></td>
                            <td style="color:#107c10;"><strong><bdi><?= number_format($ipay['amount']) ?></bdi></strong></td>
                            <td><?= $methods[$ipay['payment_method']] ?? $ipay['payment_method'] ?></td>
                            <td style="color:#888;"><?= htmlspecialchars($ipay['notes'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif ($installmentSummary['down_payment'] > 0): ?>
            <div style="text-align:center;color:#888;padding:16px;">
                <i class="fas fa-info-circle"></i> تەنها پارەی پێشەکی: <bdi><?= number_format($installmentSummary['down_payment']) ?></bdi>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

    <!-- Payment History Panel -->
    <div class="detail-panel">
        <div class="detail-panel-header">
            <span><i class="fas fa-history" style="color:#107c10;"></i> مێژووی پارەدان</span>
            <span style="font-size:12px;color:#888;font-weight:400;"><?= count($payments) ?> جار</span>
        </div>
        <div class="detail-panel-body" style="padding:0;">
            <?php if (empty($payments)): ?>
            <div style="padding:28px;text-align:center;color:#aaa;font-size:13px;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>هیچ پارەدانێک نییە</div>
            <?php else: ?>
            <?php
            $statusColors = ['pending' => '#ff8c00', 'approved' => '#107c10', 'rejected' => '#c50f1f'];
            $statusBgs    = ['pending' => '#fff4ce', 'approved' => '#dff6dd', 'rejected' => '#fde7e9'];
            $statusLabels = ['pending' => 'چاوەڕوان', 'approved' => 'پەسەندکراو', 'rejected' => 'ڕەتکراوە'];
            $typeLabels   = ['cash' => 'کاش', 'credit' => 'قەرز', 'installment' => 'قیست'];
            ?>
            <?php $hasPendingPay = false; foreach ($payments as $py) { if (($py['status'] ?? '') === 'pending') { $hasPendingPay = true; break; } } ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>بەروار</th>
                        <th style="text-align:center;">بڕ</th>
                        <th style="text-align:center;">جۆر</th>
                        <th style="text-align:center;">دۆخ</th>
                        <th style="text-align:right;">تێبینی</th>
                        <?php if ($hasPendingPay): ?><th style="text-align:center;">کردار</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $py): ?>
                <tr>
                    <td><?= date('Y-m-d', strtotime($py['created_at'])) ?></td>
                    <td style="text-align:center;font-weight:700;color:#107c10;"><?= number_format($py['amount']) ?></td>
                    <td style="text-align:center;"><?= $typeLabels[$py['payment_type']] ?? $py['payment_type'] ?></td>
                    <td style="text-align:center;">
                        <span class="status-badge" style="background:<?= $statusBgs[$py['status']] ?? '#f0f0f0' ?>;color:<?= $statusColors[$py['status']] ?? '#666' ?>;">
                            <?= $statusLabels[$py['status']] ?? $py['status'] ?>
                        </span>
                    </td>
                    <td style="text-align:right;color:#888;font-size:12px;"><?= htmlspecialchars($py['notes'] ?? '—') ?></td>
                    <?php if ($hasPendingPay): ?>
                    <td style="text-align:center;white-space:nowrap;">
                        <?php if (($py['status'] ?? '') === 'pending'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('پەسەندکردنی داواکاری پارەدانی <?= number_format($py['amount']) ?>؟')">
                            <input type="hidden" name="action" value="approve_payment_request">
                            <input type="hidden" name="payment_id" value="<?= (int)$py['id'] ?>">
                            <button type="submit" class="btn-icon btn-manage" title="پەسەندکردنی داواکاری پارەدان"><i class="fas fa-check"></i> پەسەند</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirmRejectPayment(this)">
                            <input type="hidden" name="action" value="reject_payment_request">
                            <input type="hidden" name="payment_id" value="<?= (int)$py['id'] ?>">
                            <input type="hidden" name="reason" value="">
                            <button type="submit" class="btn-icon" style="background:#fde7e9;color:#c50f1f;" title="ڕەتکردنەوە"><i class="fas fa-times"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <script>
            function confirmRejectPayment(form) {
                var reason = prompt('هۆکاری ڕەتکردنەوە (ئارەزوومەندانە):', '');
                if (reason === null) return false;          // cancelled
                form.querySelector('input[name=reason]').value = reason;
                return true;
            }
            </script>
            <?php endif; ?>
        </div>
    </div>

    </div><!-- /.inv-below -->
</div><!-- /.report-container -->

<!-- ══════════════════════════════════════════════════
     DIALOGS
══════════════════════════════════════════════════ -->

<!-- Collect Payment Dialog -->
<div class="dialog-overlay" id="collectPaymentDialog">
    <div class="dialog payment-dialog-modern">
        <div class="payment-dialog-header">
            <h4><i class="fas fa-money-bill-wave"></i> وەرگرتنی پارە</h4>
            <button type="button" class="payment-dialog-close" onclick="closeDialog('collectPaymentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="paymentForm">
            <input type="hidden" name="action" value="collect_payment">
            <div class="payment-dialog-body">
                <!-- Remaining Balance Display -->
                <div class="payment-remaining-box">
                    <div class="remaining-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="remaining-info">
                        <span class="remaining-label">بڕی ماوە</span>
                        <span class="remaining-value"><?= number_format($remaining) ?> <small></small></span>
                    </div>
                </div>

                <div class="payment-form-grid">
                    <div class="payment-form-field">
                        <label><i class="fas fa-tag"></i> جۆر <span class="required">*</span></label>
                        <select name="payment_type" required>
                            <option value="cash">نەقد</option>
                            <option value="credit">قەرز</option>
                        </select>
                    </div>
                    <div class="payment-form-field">
                        <label><i class="fas fa-coins"></i> بڕ <span class="required">*</span></label>
                        <input type="number" name="amount" required max="<?= $remaining ?>" value="<?= $remaining ?>" placeholder="بڕی پارە..." min="1" step="1">
                    </div>
                    <div class="payment-form-field">
                        <label><i class="fas fa-credit-card"></i> شێواز</label>
                        <select name="payment_method">
                            <option value="cash">نەقد</option>
                            <option value="card">کارت</option>
                            <option value="transfer">گواستنەوە</option>
                            <option value="other">تر</option>
                        </select>
                    </div>
                </div>

                <div class="payment-form-field">
                    <label><i class="fas fa-comment"></i> تێبینی</label>
                    <input type="text" name="notes" placeholder="تێبینی ئارەزوومەندانە...">
                </div>
            </div>
            <div class="payment-dialog-footer">
                <button type="submit" class="payment-btn-primary"><i class="fas fa-check-circle"></i> داواکاری</button>
                <button type="button" class="payment-btn-secondary" onclick="closeDialog('collectPaymentDialog')"><i class="fas fa-times"></i> داخستن</button>
            </div>
        </form>
    </div>
</div>

<style>
.payment-dialog-modern {
    max-width: 450px;
    width: 95%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0,0,0,0.35);
    background: #fff;
}
.payment-dialog-header {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.payment-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.payment-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.payment-dialog-close:hover { background: rgba(255,255,255,0.25); }
.payment-dialog-body { padding: 20px; }
.payment-remaining-box {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border: 1px solid #90caf9;
    border-radius: 12px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
}
.payment-remaining-box .remaining-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    flex-shrink: 0;
}
.payment-remaining-box .remaining-info { display: flex; flex-direction: column; gap: 2px; }
.payment-remaining-box .remaining-label { font-size: 12px; color: #1565c0; font-weight: 500; }
.payment-remaining-box .remaining-value { font-size: 22px; font-weight: 700; color: #0d47a1; }
.payment-remaining-box .remaining-value small { font-size: 14px; font-weight: 500; }
.payment-form-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 12px;
}
.payment-form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 12px;
}
.payment-form-field label {
    font-size: 12px;
    font-weight: 600;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 6px;
}
.payment-form-field label i { color: #2e7d32; font-size: 11px; }
.payment-form-field label .required { color: #f44336; }
.payment-form-field input,
.payment-form-field select {
    padding: 10px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fff;
}
.payment-form-field input:focus,
.payment-form-field select:focus {
    border-color: #2e7d32;
    box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
    outline: none;
}
.payment-dialog-footer {
    padding: 14px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    flex-direction: row-reverse;
}
.payment-btn-primary,
.payment-btn-secondary {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}
.payment-btn-primary {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: white;
}
.payment-btn-primary:hover { background: linear-gradient(135deg, #1b5e20 0%, #0d3d12 100%); transform: translateY(-1px); }
.payment-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}
.payment-btn-secondary:hover { background: #f5f5f5; border-color: #bdbdbd; }
@media (max-width: 480px) {
    .payment-form-grid { grid-template-columns: 1fr; }
    .payment-dialog-footer { flex-direction: column; }
}
</style>

<!-- Quick Settle Dialog (Discount + Payment Combined) -->
<div class="dialog-overlay" id="quickSettleDialog">
    <div class="dialog qs-dialog">
        <div class="qs-header">
            <div class="qs-header-left">
                <div class="qs-header-icon"><i class="fas fa-bolt"></i></div>
                <div>
                    <div class="qs-header-title">داشکاندن + پارەدان</div>
                    <div class="qs-header-sub">یەک مامەڵەی خێرا</div>
                </div>
            </div>
            <button type="button" class="qs-close" onclick="closeDialog('quickSettleDialog')"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" id="quickSettleForm">
            <input type="hidden" name="action" value="quick_settle">
            <input type="hidden" name="discount_type" id="qs_disc_type" value="percentage">
            <input type="hidden" name="payment_method" id="qs_pay_method" value="cash">
            <input type="hidden" name="payment_type" value="cash">

            <div class="qs-body">

                <!-- Price tag at top -->
                <div class="qs-price-tag">
                    <div class="qs-price-tag-label">کۆی گشتی چارەسەرەکان</div>
                    <div class="qs-price-tag-amount"><bdi><?= number_format($case['total_amount']) ?></bdi> <span></span></div>
                    <?php if ($case['paid_amount'] > 0 || $case['discount_amount'] > 0): ?>
                    <div class="qs-price-tag-sub">
                        <?php if ($case['paid_amount'] > 0): ?>
                        <span>دراو: <bdi><?= number_format($case['paid_amount']) ?></bdi></span>
                        <?php endif; ?>
                        <?php if ($case['discount_amount'] > 0): ?>
                        <span>داشکانراو: <bdi><?= number_format($case['discount_amount']) ?></bdi></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ① Discount -->
                <div class="qs-section">
                    <div class="qs-section-hd">
                        <span class="qs-badge">١</span>
                        داشکاندن
                        <span class="qs-muted">· ئارەزوومەندانە</span>
                    </div>

                    <div class="qs-seg">
                        <button type="button" class="qs-seg-btn active" id="qs_seg_pct" onclick="qsSetDiscType('percentage')">
                            <i class="fas fa-percent"></i> سەدی
                        </button>
                        <button type="button" class="qs-seg-btn" id="qs_seg_fix" onclick="qsSetDiscType('fixed')">
                            <i class="fas fa-money-bill-wave"></i> بڕ
                        </button>
                    </div>

                    <div class="qs-input-row">
                        <input type="number" name="discount_value" id="qs_disc_val"
                               class="qs-num-input" value="0" min="0" step="0.01"
                               placeholder="0" oninput="qsUpdate()">
                        <span class="qs-input-unit" id="qs_disc_unit">%</span>
                    </div>
                </div>

                <!-- ② Payment -->
                <div class="qs-section">
                    <div class="qs-section-hd">
                        <span class="qs-badge qs-badge-green">٢</span>
                        پارەدان
                    </div>

                    <div class="qs-methods">
                        <button type="button" class="qs-method active" id="qs_chip_cash" onclick="qsSetMethod('cash')">
                            <i class="fas fa-money-bill-wave"></i><span>نەقد</span>
                        </button>
                        <button type="button" class="qs-method" id="qs_chip_card" onclick="qsSetMethod('card')">
                            <i class="fas fa-credit-card"></i><span>کارت</span>
                        </button>
                        <button type="button" class="qs-method" id="qs_chip_transfer" onclick="qsSetMethod('transfer')">
                            <i class="fas fa-exchange-alt"></i><span>گواستنەوە</span>
                        </button>
                        <button type="button" class="qs-method" id="qs_chip_other" onclick="qsSetMethod('other')">
                            <i class="fas fa-ellipsis-h"></i><span>تر</span>
                        </button>
                    </div>

                    <div class="qs-input-row">
                        <input type="number" name="amount" id="qs_pay_val"
                               class="qs-num-input qs-pay-input" value="<?= $remaining ?>"
                               min="0" step="1" required oninput="qsUpdate()">
                        <span class="qs-input-unit"></span>
                    </div>

                    <input type="text" name="notes" class="qs-notes-input" placeholder="تێبینی ئارەزوومەندانە...">
                </div>

                <!-- Live summary card -->
                <div class="qs-summary-card">
                    <div class="qs-sum-row">
                        <span class="qs-sum-label">نرخی بنەڕەت</span>
                        <span class="qs-sum-val"><bdi><?= number_format($case['total_amount']) ?></bdi> <em></em></span>
                    </div>
                    <div class="qs-sum-row qs-sum-disc" id="qs_disc_row" style="display:none;">
                        <span class="qs-sum-label"><i class="fas fa-tag"></i> داشکاندن</span>
                        <span class="qs-sum-val" id="qs_disc_display">−0</span>
                    </div>
                    <?php if ($case['paid_amount'] > 0): ?>
                    <div class="qs-sum-row qs-sum-paid">
                        <span class="qs-sum-label"><i class="fas fa-check"></i> پارەی دراو</span>
                        <span class="qs-sum-val">−<bdi><?= number_format($case['paid_amount']) ?></bdi> <em></em></span>
                    </div>
                    <?php endif; ?>
                    <div class="qs-sum-divider"></div>
                    <div class="qs-final-row">
                        <span class="qs-final-label">پارەی دەکرێت</span>
                        <span class="qs-final-amount" id="qs_due_display"><bdi><?= number_format($remaining) ?></bdi> <em></em></span>
                    </div>
                    <div class="qs-after-pay-row" id="qs_after_pay_row" style="display:none;">
                        <span class="qs-after-pay-label"><i class="fas fa-hourglass-half"></i> ماوە پاش پارەدان</span>
                        <span class="qs-after-pay-val" id="qs_after_pay_val">0 <em></em></span>
                    </div>
                    <div class="qs-fullpay-badge" id="qs_fullpay_badge" style="display:none;">
                        <i class="fas fa-check-circle"></i> تەواوی پارە دەدرێت
                    </div>
                </div>

                <div class="qs-error" id="qs_validation" style="display:none;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="qs_validation_text"></span>
                </div>

            </div>

            <div class="qs-footer">
                <button type="submit" class="qs-submit-btn" id="qs_submit">
                    <i class="fas fa-check-circle"></i> جێبەجێکردن
                </button>
                <button type="button" class="qs-cancel-link" onclick="closeDialog('quickSettleDialog')">داخستن</button>
            </div>
        </form>
    </div>
</div>

<style>
/* ── Trigger button ─────────────────────────────── */
.settle-btn {
    background:linear-gradient(135deg,#5c2d91,#7b3fce) !important;
    color:#fff !important; border:none !important; font-weight:700 !important;
    box-shadow:0 2px 10px rgba(92,45,145,.28); transition:all .2s !important;
}
.settle-btn:hover { opacity:.9; transform:translateY(-1px); }

/* ── Shell ──────────────────────────────────────── */
.qs-dialog {
    width:560px; max-width:96vw;
    border-radius:16px; overflow:hidden;
    box-shadow:0 20px 56px rgba(0,0,0,.26);
    background:#fff;
}

/* ── Header ─────────────────────────────────────── */
.qs-header {
    background:linear-gradient(135deg,#4527a0 0%,#7b1fa2 100%);
    padding:11px 14px;
    display:flex; align-items:center; justify-content:space-between;
}
.qs-header-left { display:flex; align-items:center; gap:9px; }
.qs-header-icon {
    width:32px; height:32px; border-radius:9px;
    background:rgba(255,255,255,.2);
    display:flex; align-items:center; justify-content:center;
    font-size:14px; color:#fff; flex-shrink:0;
}
.qs-header-title { font-size:13px; font-weight:700; color:#fff; line-height:1.2; }
.qs-header-sub   { font-size:10px; color:rgba(255,255,255,.65); }
.qs-close {
    width:26px; height:26px; border-radius:7px; border:none;
    background:rgba(255,255,255,.15); color:#fff; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    font-size:11px; transition:background .18s;
}
.qs-close:hover { background:rgba(255,255,255,.28); }

/* ── Body ───────────────────────────────────────── */
.qs-body {
    padding:12px 14px;
    display:grid;
    grid-template-columns:1fr 1fr;
    grid-template-rows:auto auto auto;
    gap:10px;
    max-height:80vh; overflow-y:auto;
}

/* ── Price Tag — full width ─────────────────────── */
.qs-price-tag {
    grid-column:1 / -1;
    background:linear-gradient(135deg,#f3e5f5,#ede7f6);
    border:1px solid #ce93d8; border-radius:10px;
    padding:10px 14px;
    display:flex; align-items:center; justify-content:space-between;
}
.qs-price-tag-label { font-size:11px; color:#7b1fa2; font-weight:600; }
.qs-price-tag-amount {
    font-size:22px; font-weight:800; color:#4a148c; line-height:1;
    direction:ltr; unicode-bidi:embed;
}
.qs-price-tag-amount span { font-size:13px; font-weight:600; color:#7b1fa2; }
.qs-price-tag-sub {
    display:flex; flex-direction:column; align-items:flex-start; gap:2px;
    font-size:10px; color:#9c27b0; opacity:.8;
}

/* ── Section ────────────────────────────────────── */
.qs-section { display:flex; flex-direction:column; gap:6px; }
.qs-section-hd {
    display:flex; align-items:center; gap:6px;
    font-size:11px; font-weight:700; color:#444;
}
.qs-badge {
    width:16px; height:16px; border-radius:50%;
    background:#7b1fa2; color:#fff;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:9px; font-weight:700; flex-shrink:0;
}
.qs-badge-green { background:#2e7d32; }
.qs-muted { font-size:10px; color:#bbb; font-weight:400; }

/* ── Segmented control ──────────────────────────── */
.qs-seg {
    display:flex; background:#eeeeee; border-radius:8px; padding:2px; gap:2px;
}
.qs-seg-btn {
    flex:1; padding:5px 6px; border:none; border-radius:6px;
    font-size:11px; font-weight:600; cursor:pointer; font-family:inherit;
    background:transparent; color:#888;
    display:flex; align-items:center; justify-content:center; gap:4px;
    transition:all .16s;
}
.qs-seg-btn.active {
    background:#fff; color:#7b1fa2;
    box-shadow:0 1px 4px rgba(0,0,0,.12);
}

/* ── Number input row ───────────────────────────── */
.qs-input-row { position:relative; display:flex; align-items:center; }
.qs-num-input {
    width:100%; padding:8px 38px 8px 12px;
    border:1.5px solid #e0e0e0; border-radius:8px;
    font-size:17px; font-weight:700; text-align:center; font-family:inherit;
    background:#fafafa; color:#1a1a1a; transition:all .18s;
    box-sizing:border-box;
}
.qs-num-input:focus {
    outline:none; border-color:#7b1fa2; background:#fff;
    box-shadow:0 0 0 3px rgba(123,31,162,.1);
}
.qs-pay-input:focus { border-color:#2e7d32; box-shadow:0 0 0 3px rgba(46,125,50,.1); }
.qs-input-unit {
    position:absolute; left:11px;
    font-size:11px; font-weight:700; color:#bbb; pointer-events:none; user-select:none;
}

/* ── Summary card — full width ──────────────────── */
.qs-summary-card {
    grid-column:1 / -1;
    background:#f9f9f9; border:1px solid #e8e8e8;
    border-radius:10px; padding:10px 12px;
    display:flex; flex-direction:column; gap:4px;
}
.qs-sum-row {
    display:flex; justify-content:space-between; align-items:center;
    font-size:12px; color:#777; padding:1px 0;
}
.qs-sum-label { display:flex; align-items:center; gap:4px; }
.qs-sum-label i { font-size:10px; }
.qs-sum-val { font-weight:600; color:#555; }
.qs-sum-val em { font-size:10px; font-style:normal; color:#aaa; }
.qs-sum-disc .qs-sum-val { color:#e65100; }
.qs-sum-paid .qs-sum-val { color:#1565c0; }
.qs-sum-divider { border-top:1.5px dashed #e0e0e0; margin:3px 0; }
.qs-final-row {
    display:flex; justify-content:space-between; align-items:center;
    background:linear-gradient(135deg,#1b5e20,#2e7d32);
    border-radius:8px; padding:8px 12px; margin-top:2px;
}
.qs-final-label { font-size:11px; color:rgba(255,255,255,.85); font-weight:600; }
.qs-final-amount {
    font-size:20px; font-weight:800; color:#fff;
    display:flex; align-items:baseline; gap:3px;
    direction:ltr; unicode-bidi:embed;
}
.qs-final-amount em { font-size:11px; font-weight:500; opacity:.8; font-style:normal; }

/* ── Payment methods ────────────────────────────── */
.qs-methods { display:flex; gap:5px; }
.qs-method {
    flex:1; padding:6px 3px; border-radius:8px;
    border:1.5px solid #e8e8e8; background:#fff;
    font-size:10px; font-weight:600; cursor:pointer; font-family:inherit;
    display:flex; flex-direction:column; align-items:center; gap:3px;
    color:#888; transition:all .16s;
}
.qs-method i { font-size:13px; }
.qs-method:hover { border-color:#aaa; }
.qs-method.active {
    background:#e8f5e9; border-color:#2e7d32; color:#1b5e20;
}

/* ── Notes ──────────────────────────────────────── */
.qs-notes-input {
    width:100%; padding:7px 10px;
    border:1.5px solid #e8e8e8; border-radius:8px;
    font-size:12px; font-family:inherit; color:#555;
    background:#fafafa; transition:border-color .18s; box-sizing:border-box;
}
.qs-notes-input:focus { outline:none; border-color:#7b1fa2; background:#fff; }
.qs-notes-input::placeholder { color:#c0c0c0; }

/* ── Validation error ───────────────────────────── */
.qs-error {
    grid-column:1 / -1;
    background:#fff0f0; border:1px solid #ffcdd2; border-radius:7px;
    padding:7px 11px; display:flex; align-items:center; gap:7px;
    color:#c62828; font-size:11.5px;
}

/* ── Footer ─────────────────────────────────────── */
.qs-footer {
    padding:10px 14px 14px;
    display:flex; flex-direction:column; gap:6px; align-items:stretch;
}
.qs-submit-btn {
    width:100%; padding:11px;
    background:linear-gradient(135deg,#2e7d32,#1b5e20);
    color:#fff; border:none; border-radius:10px;
    font-size:14px; font-weight:700; cursor:pointer; font-family:inherit;
    display:flex; align-items:center; justify-content:center; gap:8px;
    transition:all .2s; letter-spacing:.2px;
}
.qs-submit-btn:hover { opacity:.93; transform:translateY(-1px); box-shadow:0 4px 14px rgba(46,125,50,.35); }
.qs-submit-btn:disabled { background:#c8c8c8; cursor:not-allowed; transform:none; box-shadow:none; }
.qs-cancel-link {
    width:100%; padding:8px; border:none; background:none;
    font-size:12.5px; color:#aaa; cursor:pointer; font-family:inherit;
    transition:color .18s;
}
.qs-cancel-link:hover { color:#555; }

/* ── After-payment remaining ────────────────────── */
.qs-after-pay-row {
    display:flex; justify-content:space-between; align-items:center;
    background:#fff8e1; border:1px solid #ffe082;
    border-radius:8px; padding:8px 12px; margin-top:6px;
    font-size:12.5px;
}
.qs-after-pay-label {
    color:#e65100; font-weight:600;
    display:flex; align-items:center; gap:5px;
}
.qs-after-pay-label i { font-size:11px; }
.qs-after-pay-val {
    font-size:16px; font-weight:800; color:#bf360c;
    direction:ltr; unicode-bidi:embed;
}
.qs-after-pay-val em { font-size:10px; font-style:normal; opacity:.8; }
.qs-fullpay-badge {
    display:flex; align-items:center; justify-content:center; gap:6px;
    background:#e8f5e9; border:1px solid #a5d6a7;
    border-radius:8px; padding:8px 12px; margin-top:6px;
    font-size:12.5px; font-weight:700; color:#1b5e20;
}
.qs-fullpay-badge i { color:#2e7d32; }

/* ── Force English (ASCII) digit rendering ─────── */
.qs-dialog input[type="number"],
.qs-price-tag-amount,
.qs-final-amount,
.qs-sum-val,
.qs-price-tag-sub,
.qs-dialog bdi {
    direction: ltr;
    unicode-bidi: embed;
    font-variant-numeric: tabular-nums;
}
/* Keep RTL layout for rows, numbers LTR inside */
.qs-sum-row   { direction: rtl; }
.qs-final-row { direction: rtl; }
.qs-dialog bdi { display: inline; }
</style>

<!-- Discount Dialog -->
<div class="dialog-overlay" id="discountDialog">
    <div class="dialog discount-dialog-modern">
        <div class="discount-dialog-header">
            <h4><i class="fas fa-percent"></i> داشکاندن لەسەر کۆی گشتی</h4>
            <button type="button" class="discount-dialog-close" onclick="closeDialog('discountDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="discountForm">
            <input type="hidden" name="action" value="apply_discount">
            <div class="discount-dialog-body">
                <div class="discount-summary-box">
                    <div class="discount-summary-row">
                        <span>کۆی گشتی چارەسەرەکان</span>
                        <span class="discount-amount"><?= number_format($case['total_amount']) ?></span>
                    </div>
                </div>

                <div class="discount-type-selector">
                    <label class="discount-type-option">
                        <input type="radio" name="discount_type" value="percentage" <?= ($case['discount_type'] ?? 'fixed') === 'percentage' ? 'checked' : '' ?> onchange="updateDiscountPreview()">
                        <span class="discount-type-label">
                            <i class="fas fa-percent"></i>
                            <span>ڕێژەی سەدی</span>
                        </span>
                    </label>
                    <label class="discount-type-option">
                        <input type="radio" name="discount_type" value="fixed" <?= ($case['discount_type'] ?? 'fixed') === 'fixed' ? 'checked' : '' ?> onchange="updateDiscountPreview()">
                        <span class="discount-type-label">
                            <i class="fas fa-money-bill"></i>
                            <span>بڕی دیاریکراو</span>
                        </span>
                    </label>
                </div>

                <div class="discount-input-group">
                    <label class="discount-input-label">
                        <span id="discountInputLabel">بڕی داشکاندن</span>
                        <span class="discount-input-hint" id="discountInputHint"></span>
                    </label>
                    <div class="discount-input-wrapper">
                        <input type="number"
                               name="discount_value"
                               id="discountValueInput"
                               class="discount-input"
                               value="<?= $case['discount_value'] ?? 0 ?>"
                               min="0"
                               step="0.01"
                               oninput="updateDiscountPreview()"
                               placeholder="0">
                        <span class="discount-input-suffix" id="discountSuffix"></span>
                    </div>
                </div>

                <div class="discount-preview-box">
                    <div class="discount-preview-row">
                        <span>بڕی داشکاندن</span>
                        <span class="discount-preview-value" id="discountAmountPreview">0</span>
                    </div>
                    <div class="discount-preview-row discount-preview-total">
                        <span>بڕی دوای داشکاندن</span>
                        <span class="discount-preview-value" id="discountRemainingPreview"><?= number_format($case['total_amount']) ?></span>
                    </div>
                </div>

                <div class="discount-validation-message" id="discountValidationMsg" style="display:none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="discountValidationText"></span>
                </div>
            </div>
            <div class="discount-dialog-footer">
                <button type="submit" class="discount-btn-primary" id="discountSubmitBtn"><i class="fas fa-check"></i> جێبەجێکردن</button>
                <button type="button" class="discount-btn-secondary" onclick="closeDialog('discountDialog')"><i class="fas fa-times"></i> پاشگەزبوونەوە</button>
            </div>
        </form>
    </div>
</div>

<style>
.discount-dialog-modern {
    max-width: 420px;
    width: 95%;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 25px 80px rgba(0,0,0,0.35);
    background: #fff;
}
.discount-dialog-header {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.discount-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.discount-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.discount-dialog-close:hover { background: rgba(255,255,255,0.25); }
.discount-dialog-body { padding: 20px; }
.discount-summary-box {
    background: #f5f5f5;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.discount-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
}
.discount-summary-row span:first-child { color: #666; }
.discount-amount { font-weight: 600; color: #333; font-size: 16px; }
.discount-type-selector { display: flex; gap: 12px; margin-bottom: 16px; }
.discount-type-option { flex: 1; cursor: pointer; }
.discount-type-option input { display: none; }
.discount-type-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 14px 12px;
    background: #f9f9f9;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    transition: all 0.2s;
}
.discount-type-label i { font-size: 20px; color: #666; }
.discount-type-label span { font-size: 13px; color: #666; }
.discount-type-option input:checked + .discount-type-label {
    background: #fff3e0;
    border-color: #ff9800;
}
.discount-type-option input:checked + .discount-type-label i,
.discount-type-option input:checked + .discount-type-label span { color: #f57c00; }
.discount-input-group { margin-bottom: 16px; }
.discount-input-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: #666;
    margin-bottom: 8px;
}
.discount-input-hint { font-size: 11px; color: #999; }
.discount-input-wrapper { position: relative; display: flex; align-items: center; }
.discount-input {
    width: 100%;
    padding: 12px 50px 12px 16px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 18px;
    font-weight: 600;
    text-align: center;
    transition: all 0.2s;
}
.discount-input:focus {
    outline: none;
    border-color: #ff9800;
    box-shadow: 0 0 0 3px rgba(255,152,0,0.15);
}
.discount-input-suffix {
    position: absolute;
    left: 16px;
    color: #999;
    font-size: 14px;
    font-weight: 500;
}
.discount-preview-box {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 12px;
}
.discount-preview-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    color: #2e7d32;
    margin-bottom: 8px;
}
.discount-preview-row:last-child { margin-bottom: 0; }
.discount-preview-total {
    padding-top: 10px;
    border-top: 1px dashed #81c784;
    font-weight: 600;
    font-size: 15px;
}
.discount-preview-value { font-weight: 600; }
.discount-validation-message {
    background: #ffebee;
    border: 1px solid #ffcdd2;
    border-radius: 8px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #c62828;
    font-size: 13px;
}
.discount-dialog-footer {
    padding: 16px 20px;
    background: #fafafa;
    display: flex;
    gap: 12px;
    flex-direction: row-reverse;
}
.discount-btn-primary {
    flex: 1;
    padding: 12px 20px;
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}
.discount-btn-primary:hover { background: linear-gradient(135deg, #ffa726 0%, #fb8c00 100%); transform: translateY(-1px); }
.discount-btn-primary:disabled { background: #ccc; cursor: not-allowed; transform: none; }
.discount-btn-secondary {
    padding: 12px 20px;
    background: white;
    color: #666;
    border: 1.5px solid #e0e0e0;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}
.discount-btn-secondary:hover { background: #f5f5f5; border-color: #bdbdbd; }
@media (max-width: 480px) {
    .discount-type-selector { flex-direction: column; }
    .discount-dialog-footer { flex-direction: column; }
}
</style>

<!-- Installment Plan Dialog -->
<?php if (!$installmentPlan): ?>
<div class="dialog-overlay" id="installmentDialog">
    <div class="dialog-box">
        <div class="dialog-title">
            <span><i class="fas fa-credit-card" style="color:#8764b8;"></i> دروستکردنی پلانی قیست</span>
            <button class="dialog-close-btn" onclick="closeDialog('installmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_installment">
            <div class="form-grid-2">
                <div class="form-field">
                    <label>کۆی مەبەلەغ</label>
                    <input type="number" name="total_amount" value="<?= $remaining ?>" min="1" step="1">
                </div>
                <div class="form-field">
                    <label>پارەی پێشەکی</label>
                    <input type="number" name="down_payment" value="0" min="0" step="1">
                </div>
                <div class="form-field">
                    <label>ژمارەی قیستەکان</label>
                    <input type="number" name="number_of_installments" value="3" min="1" max="60">
                </div>
                <div class="form-field">
                    <label>بەرواری دەستپێکردن</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-field">
                <label>تێبینی</label>
                <textarea name="notes" rows="2"></textarea>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn-dialog-cancel" onclick="closeDialog('installmentDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn-dialog-primary"><i class="fas fa-check"></i> دروستکردن</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const caseTotalAmount = <?= $case['total_amount'] ?? 0 ?>;
const currencySymbol  = '';

function formatNumber(num) {
    // toString() always produces ASCII digits (0-9), never Arabic-Indic
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

window.updateDiscountPreview = function() {
    const discountType  = document.querySelector('input[name="discount_type"]:checked')?.value || 'fixed';
    const discountValue = parseFloat(document.getElementById('discountValueInput')?.value) || 0;

    const inputHint       = document.getElementById('discountInputHint');
    const inputSuffix     = document.getElementById('discountSuffix');
    const amountPreview   = document.getElementById('discountAmountPreview');
    const remainingPreview= document.getElementById('discountRemainingPreview');
    const validationMsg   = document.getElementById('discountValidationMsg');
    const validationText  = document.getElementById('discountValidationText');
    const submitBtn       = document.getElementById('discountSubmitBtn');

    let discountAmount = 0;
    let isValid = true;
    let validationMessage = '';

    if (discountType === 'percentage') {
        if (inputHint)   inputHint.textContent   = '(%)';
        if (inputSuffix) inputSuffix.textContent = '%';
        if (discountValue < 0 || discountValue > 100) {
            isValid = false;
            validationMessage = 'ڕێژەی سەدی دەبێت لە نێوان 0 و 100 دا بێت';
        } else {
            discountAmount = caseTotalAmount * (discountValue / 100);
        }
    } else {
        if (inputHint)   inputHint.textContent   = '(' + currencySymbol + ')';
        if (inputSuffix) inputSuffix.textContent = currencySymbol;
        if (discountValue < 0) {
            isValid = false;
            validationMessage = 'بڕی داشکاندن ناتوانێت کەمتر لە سفر بێت';
        } else if (discountValue > caseTotalAmount) {
            isValid = false;
            validationMessage = 'بڕی داشکاندن ناتوانێت لە کۆی گشتی (' + formatNumber(caseTotalAmount) + ') زیاتر بێت';
        } else {
            discountAmount = discountValue;
        }
    }

    const remainingAmount = caseTotalAmount - discountAmount;
    if (amountPreview)    amountPreview.textContent    = formatNumber(discountAmount) + ' ' + currencySymbol;
    if (remainingPreview) remainingPreview.textContent = formatNumber(remainingAmount) + ' ' + currencySymbol;
    if (validationMsg)    validationMsg.style.display  = isValid ? 'none' : 'flex';
    if (validationText)   validationText.textContent   = validationMessage;
    if (submitBtn)        submitBtn.disabled            = !isValid;
};

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('discountDialog')) {
        updateDiscountPreview();
    }
    if (document.getElementById('quickSettleDialog')) {
        qsUpdate();
    }

    // Double-submit guard: a second click while the first POST is in flight
    // creates a duplicate payment — disable the button as soon as one submits
    document.querySelectorAll('form').forEach(function(f) {
        f.addEventListener('submit', function() {
            var btn = f.querySelector('button[type=submit]');
            if (btn) setTimeout(function() { btn.disabled = true; }, 0);
        });
    });
});

// ── Quick Settle Calculator ────────────────────────────────────
const QS_TOTAL = <?= $case['total_amount'] ?? 0 ?>;
const QS_PAID  = <?= $case['paid_amount']  ?? 0 ?>;
const QS_DISC  = <?= $case['discount_amount'] ?? 0 ?>; // existing discount — kept when the field is 0, replaced when > 0
const QS_CURR  = '';

window.qsSetDiscType = function(type) {
    document.getElementById('qs_disc_type').value = type;
    document.getElementById('qs_seg_pct').classList.toggle('active', type === 'percentage');
    document.getElementById('qs_seg_fix').classList.toggle('active', type === 'fixed');
    document.getElementById('qs_disc_unit').textContent = type === 'percentage' ? '%' : QS_CURR;
    qsUpdate();
};

window.qsSetMethod = function(method) {
    document.getElementById('qs_pay_method').value = method;
    ['cash','card','transfer','other'].forEach(function(m) {
        const el = document.getElementById('qs_chip_' + m);
        if (el) el.classList.toggle('active', m === method);
    });
};

window.qsUpdate = function() {
    const discType  = document.getElementById('qs_disc_type').value;
    const discVal   = parseFloat(document.getElementById('qs_disc_val')?.value) || 0;
    const payInput  = document.getElementById('qs_pay_val');
    const valBox    = document.getElementById('qs_validation');
    const valText   = document.getElementById('qs_validation_text');
    const submitBtn = document.getElementById('qs_submit');

    let discountAmt = 0, isValid = true, msg = '';

    if (discType === 'percentage') {
        if (discVal < 0 || discVal > 100) { isValid = false; msg = 'ڕێژەی سەدی دەبێت لە نێوان 0 و 100 دا بێت'; }
        else discountAmt = QS_TOTAL * (discVal / 100);
    } else {
        discountAmt = discVal;
    }
    // A new discount REPLACES the existing one server-side; 0 keeps the existing
    const effectiveDisc = discVal > 0 ? discountAmt : QS_DISC;
    if (isValid && effectiveDisc > QS_TOTAL - QS_PAID) {
        isValid = false; msg = 'داشکاندن ناتوانێت لە بڕی نەدراو زیاتر بێت';
    }

    const newDue = Math.max(0, QS_TOTAL - effectiveDisc - QS_PAID);

    // Auto-fill pay amount when discount changes
    if (document.activeElement !== payInput && isValid) {
        if (payInput) payInput.value = Math.round(newDue);
    }

    const finalPay = parseFloat(payInput?.value) || 0;
    if (finalPay < 0)      { isValid = false; msg = 'بڕی پارە ناتوانێت منفی بێت'; }
    if (finalPay > newDue && isValid) { isValid = false; msg = 'بڕی پارە لە ماوە زیاترە (' + formatNumber(newDue) + ' ' + QS_CURR + ')'; }

    // Discount row in totals (shows the effective discount, incl. a pre-existing one)
    const discRow  = document.getElementById('qs_disc_row');
    const discDisp = document.getElementById('qs_disc_display');
    if (effectiveDisc > 0) {
        if (discRow)  discRow.style.display = '';
        if (discDisp) discDisp.textContent  = '−' + formatNumber(Math.round(effectiveDisc)) + ' ' + QS_CURR;
    } else {
        if (discRow) discRow.style.display = 'none';
    }

    // Due card
    const dueDisp = document.getElementById('qs_due_display');
    if (dueDisp) dueDisp.innerHTML = formatNumber(Math.round(newDue)) + ' <small>' + QS_CURR + '</small>';

    // After-payment remaining (reuse finalPay already declared above)
    const afterPayRow  = document.getElementById('qs_after_pay_row');
    const afterPayVal  = document.getElementById('qs_after_pay_val');
    const fullPayBadge = document.getElementById('qs_fullpay_badge');
    const afterRemain  = Math.max(0, Math.round(newDue - finalPay));

    if (isValid && finalPay > 0) {
        if (afterRemain === 0) {
            if (afterPayRow)  afterPayRow.style.display  = 'none';
            if (fullPayBadge) fullPayBadge.style.display = 'flex';
        } else {
            if (afterPayRow)  afterPayRow.style.display  = 'flex';
            if (afterPayVal)  afterPayVal.innerHTML = formatNumber(afterRemain) + ' <em>' + QS_CURR + '</em>';
            if (fullPayBadge) fullPayBadge.style.display = 'none';
        }
    } else {
        if (afterPayRow)  afterPayRow.style.display  = 'none';
        if (fullPayBadge) fullPayBadge.style.display = 'none';
    }

    if (valBox)    valBox.style.display = isValid ? 'none' : 'flex';
    if (valText)   valText.textContent  = msg;
    if (submitBtn) submitBtn.disabled   = !isValid;
};
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
