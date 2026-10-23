<?php
/**
 * Debts and Installments Management Page
 * بەشی بەڕێوەبردنی قەرز و قیستەکان
 */

$pageTitle = 'قەرز و قیستەکان';
$currentPage = 'debts';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Patient.php';
require_once __DIR__ . '/classes/Installment.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();

// Doctors may use this page, but scoped to THEIR OWN patients only (patients
// they own a folder for, or whose X-ray debt they requested). Admin/accountant/
// reception see every patient. $debtorWhere / $debtorParams drive the main query.
$doctorFilter = ($isDoctor && !$isAdmin) ? (int)$currentUser['id'] : null;
if ($doctorFilter) {
    $debtorWhere = "(EXISTS (SELECT 1 FROM patient_folders pfo WHERE pfo.patient_id = p.id AND pfo.doctor_id = :df)
                  OR EXISTS (SELECT 1 FROM xray_requests xro WHERE xro.patient_id = p.id AND xro.requested_by = :df2 AND xro.status = 'completed' AND xro.payment_status = 'debt'))";
    $debtorParams = ['df' => $doctorFilter, 'df2' => $doctorFilter];
} else {
    $debtorWhere = "EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id)
                 OR EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt')";
    $debtorParams = [];
}

// Get overview statistics
$patient = new Patient();
$installment = new Installment();

// Total debts across all patients (folder debt + X-ray debt)
$folderDebt = $patient->getTotalDebtAmount();

// Get X-ray debt total
$xrayDebtSql = "SELECT COALESCE(SUM(final_amount), 0) as total FROM xray_requests WHERE status = 'completed' AND payment_status = 'debt'";
$xrayDebtResult = $db->fetch($xrayDebtSql);
$xrayDebt = $xrayDebtResult['total'] ?? 0;

$totalDebt = $folderDebt + $xrayDebt;

// Installment statistics
$installmentStats = $installment->getStatistics();

// Overdue installments.
// NOTE: named *List because includes/header.php later overwrites
// $overdueInstallments with the nav-badge COUNT (an int).
$overdueInstallmentsList = $installment->getOverdue($doctorFilter);
$overdueInstallmentsList = is_array($overdueInstallmentsList) ? $overdueInstallmentsList : [];
$overdueAmount = 0;
foreach ($overdueInstallmentsList as $overdue) {
    $overdueAmount += $overdue['amount'] - $overdue['paid_amount'];
}

// Expected revenue this month
$expectedRevenue = $installmentStats['due_this_month'] ?? 0;

// Get all debtors (patients with debt)
// Fixed: Removed LEFT JOIN on payments to avoid cartesian product causing incorrect SUM values
// Last payment date is now retrieved via subquery
// Added: X-ray debt is now included in total debt calculation
$sql = "SELECT p.id, p.patient_code, p.full_name, p.phone, p.phone2,
        COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as folder_debt,
        COALESCE((SELECT SUM(pf.total_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as folder_total,
        COALESCE((SELECT SUM(pf.paid_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as folder_paid,
        COALESCE((SELECT SUM(pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as folder_discount,
        COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0) as xray_debt,
        COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed'), 0) as xray_total,
        COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'paid'), 0) as xray_paid,
        (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
         COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0)) as total_debt,
        (COALESCE((SELECT SUM(pf.total_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
         COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed'), 0)) as total_amount,
        (COALESCE((SELECT SUM(pf.paid_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
         COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'paid'), 0)) as total_paid,
        COALESCE((SELECT SUM(pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as total_discount,
        (SELECT MAX(pay.payment_date) FROM payments pay
         INNER JOIN cases c ON pay.case_id = c.id
         INNER JOIN patient_folders pf_pay ON c.folder_id = pf_pay.id
         WHERE pf_pay.patient_id = p.id AND pay.status = 'approved') as last_payment_date,
        (SELECT COUNT(*) FROM installment_plans ip
         INNER JOIN cases c ON ip.case_id = c.id
         INNER JOIN patient_folders pf2 ON c.folder_id = pf2.id
         WHERE pf2.patient_id = p.id AND ip.status = 'active') as has_installment_plan,
        (SELECT COUNT(*) FROM installment_schedule isch
         INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
         INNER JOIN cases c2 ON ip2.case_id = c2.id
         INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
         WHERE pf3.patient_id = p.id
         AND isch.status IN ('pending', 'partial')
         AND isch.due_date < CURDATE()) as overdue_count
        FROM patients p
        WHERE {$debtorWhere}
        HAVING total_debt > 0
        ORDER BY total_debt DESC";

$debtors = $db->fetchAll($sql, $debtorParams);
$debtors = is_array($debtors) ? $debtors : [];

// For a doctor, the headline totals reflect ONLY their own patients' debts
// (derive them from the already-scoped debtor list).
if ($doctorFilter) {
    $folderDebt = array_sum(array_map(static fn($d) => (float)($d['folder_debt'] ?? 0), $debtors));
    $xrayDebt   = array_sum(array_map(static fn($d) => (float)($d['xray_debt'] ?? 0), $debtors));
    $totalDebt  = $folderDebt + $xrayDebt;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.debts-container {
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title h2 {
    margin: 0;
    font-size: 22px;
    color: #1a1a1a;
}

.page-title .icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #f44336, #d32f2f);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
}

/* Statistics Cards */
.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100px;
    height: 100px;
    opacity: 0.1;
    border-radius: 50%;
}

.stat-card.total-debt::before {
    background: #f44336;
    right: -30px;
    top: -30px;
}

.stat-card.upcoming::before {
    background: #ff9800;
    right: -30px;
    top: -30px;
}

.stat-card.overdue::before {
    background: #9c27b0;
    right: -30px;
    top: -30px;
}

.stat-card.expected::before {
    background: #4caf50;
    right: -30px;
    top: -30px;
}

.stat-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
}

.stat-card.total-debt .stat-icon {
    background: linear-gradient(135deg, #f44336, #d32f2f);
}

.stat-card.upcoming .stat-icon {
    background: linear-gradient(135deg, #ff9800, #f57c00);
}

.stat-card.overdue .stat-icon {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
}

.stat-card.expected .stat-icon {
    background: linear-gradient(135deg, #4caf50, #388e3c);
}

.stat-label {
    font-size: 13px;
    color: #666;
    font-weight: 600;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #1a1a1a;
    margin-bottom: 4px;
}

.stat-subtext {
    font-size: 12px;
    color: #999;
}

.stat-subtext i {
    margin-left: 4px;
}

/* Filters */
.filters-section {
    background: white;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filters-row {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-size: 13px;
    color: #666;
    font-weight: 600;
    white-space: nowrap;
}

.filter-group select,
.filter-group input {
    padding: 8px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    min-width: 180px;
}

.filter-group select:focus,
.filter-group input:focus {
    border-color: #f44336;
    outline: none;
}

.btn-filter {
    padding: 8px 16px;
    background: linear-gradient(135deg, #f44336, #d32f2f);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-filter:hover {
    background: linear-gradient(135deg, #d32f2f, #c62828);
}

.btn-reset {
    padding: 8px 16px;
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-family: inherit;
}

.btn-reset:hover {
    background: #5a6268;
}

/* Debtors Table */
.debtors-table-section {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
}

.table-header {
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    padding: 16px 20px;
    border-bottom: 2px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-title {
    font-size: 16px;
    font-weight: 700;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.table-count {
    background: #f44336;
    color: white;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.table-actions {
    display: flex;
    gap: 8px;
}

.btn-export {
    padding: 8px 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-excel {
    background: #4caf50;
    color: white;
}

.btn-excel:hover {
    background: #43a047;
}

.btn-pdf {
    background: #f44336;
    color: white;
}

.btn-pdf:hover {
    background: #e53935;
}

.debtors-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.debtors-table thead {
    background: #fafafa;
}

.debtors-table th {
    padding: 14px 12px;
    text-align: right;
    font-weight: 600;
    color: #555;
    border-bottom: 2px solid #e0e0e0;
    white-space: nowrap;
}

.debtors-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.debtors-table tbody tr {
    transition: background 0.2s;
}

.debtors-table tbody tr:hover {
    background: #fafafa;
}

.patient-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.patient-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}

.patient-details {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.patient-name {
    font-weight: 600;
    color: #333;
}

.patient-code {
    font-size: 11px;
    color: #999;
    font-family: var(--app-font-family);
}

.patient-phone {
    font-size: 12px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 4px;
}

.patient-phone i {
    color: #4caf50;
    font-size: 11px;
}

.amount {
    font-weight: 700;
    font-family: var(--app-font-family);
}

.amount.total {
    color: #333;
}

.amount.paid {
    color: #4caf50;
}

.amount.debt {
    color: #f44336;
    font-size: 15px;
}

.debt-type-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.debt-type-badge.installment {
    background: #e3f2fd;
    color: #1976d2;
}

.debt-type-badge.regular {
    background: #fff3e0;
    color: #f57c00;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-badge.active {
    background: #e8f5e9;
    color: #2e7d32;
}

.status-badge.overdue {
    background: #ffebee;
    color: #c62828;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.status-badge.paid {
    background: #f5f5f5;
    color: #9e9e9e;
}

.last-payment {
    font-size: 12px;
    color: #666;
}

.action-buttons {
    display: flex;
    gap: 6px;
}

.btn-action {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}

.btn-view {
    background: #2196f3;
    color: white;
}

.btn-view:hover {
    background: #1976d2;
}

.btn-pay {
    background: #4caf50;
    color: white;
}

.btn-pay:hover {
    background: #43a047;
}

/* Payment Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    animation: slideDown 0.3s;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-50px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 12px 12px 0 0;
}

.modal-title {
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.2s;
}

.btn-close:hover {
    background: rgba(255,255,255,0.3);
}

.modal-body {
    padding: 24px;
}

.patient-summary {
    background: #f8f9fa;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e0e0e0;
}

.summary-row:last-child {
    border-bottom: none;
    margin-top: 8px;
    padding-top: 12px;
    border-top: 2px solid #e0e0e0;
}

.summary-label {
    font-size: 13px;
    color: #666;
}

.summary-value {
    font-size: 14px;
    font-weight: 700;
    color: #333;
}

.summary-value.debt {
    color: #f44336;
    font-size: 18px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 13px;
    color: #555;
    font-weight: 600;
    margin-bottom: 6px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #4caf50;
    outline: none;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn-submit {
    padding: 10px 24px;
    background: linear-gradient(135deg, #4caf50, #388e3c);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-family: inherit;
    font-weight: 600;
}

.btn-submit:hover {
    background: linear-gradient(135deg, #388e3c, #2e7d32);
}

.btn-cancel {
    padding: 10px 24px;
    background: #f5f5f5;
    color: #666;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-family: inherit;
}

.btn-cancel:hover {
    background: #e0e0e0;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}

.empty-state i {
    font-size: 64px;
    color: #ddd;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 18px;
    color: #666;
    margin: 0 0 8px;
}

.empty-state p {
    font-size: 14px;
    color: #999;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-overview {
        grid-template-columns: 1fr;
    }

    .filters-row {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group select,
    .filter-group input {
        width: 100%;
    }

    .debtors-table {
        font-size: 11px;
    }

    .debtors-table th,
    .debtors-table td {
        padding: 8px 6px;
    }
}
</style>

<div class="debts-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title">
            <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
            <div>
                <h2>قەرز و قیستی نەخۆشەکان</h2>
                <small style="color: #888;">بەڕێوەبردنی داهات و قەرزەکان</small>
            </div>
        </div>
    </div>

    <!-- Overdue Notifications -->
    <?php if (!empty($overdueInstallmentsList)): ?>
    <div style="background: linear-gradient(135deg, #ffebee, #ffcdd2); border-right: 4px solid #f44336; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(244, 67, 54, 0.15);">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
            <div style="width: 40px; height: 40px; background: #f44336; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div>
                <div style="font-size: 16px; font-weight: 700; color: #c62828;">ئاگاداری! قیستی دواکەوتوو هەیە</div>
                <div style="font-size: 13px; color: #d32f2f;">
                    <?= count($overdueInstallmentsList) ?> نەخۆش قیستی دواکەوتوویان هەیە بە کۆی <?= number_format($overdueAmount) ?> دینار
                </div>
            </div>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php
            $displayLimit = 5;
            $overdueCount = 0;
            foreach ($overdueInstallmentsList as $overdue):
                if ($overdueCount >= $displayLimit) break;
                $overdueCount++;
                $daysLate = (time() - strtotime($overdue['due_date'])) / (60 * 60 * 24);
            ?>
            <div style="background: white; padding: 8px 12px; border-radius: 8px; font-size: 12px; border: 1px solid #ef5350;">
                <strong><?= htmlspecialchars($overdue['patient_name']) ?></strong>
                <span style="color: #666;"> - </span>
                <span style="color: #f44336; font-weight: 600;"><?= number_format($overdue['amount'] - $overdue['paid_amount']) ?> د</span>
                <span style="color: #999;"> (<?= floor($daysLate) ?> ڕۆژ دواکەوتوو)</span>
            </div>
            <?php endforeach; ?>
            <?php if (count($overdueInstallmentsList) > $displayLimit): ?>
            <div style="background: #f44336; color: white; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;" onclick="document.getElementById('statusFilter').value='overdue'; applyFilters();">
                +<?= count($overdueInstallmentsList) - $displayLimit ?> زیاتر - کلیک بکە بۆ بینین
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filters-section">
        <div class="filters-row">
            <div class="filter-group">
                <label>گەڕان:</label>
                <input type="text" id="searchInput" placeholder="ناو، کۆد، یان ژمارەی مۆبایل...">
            </div>
            <div class="filter-group">
                <label>جۆری قەرز:</label>
                <select id="debtTypeFilter">
                    <option value="">هەموو</option>
                    <option value="installment">قیستی مانگانە</option>
                    <option value="regular">قەرزی ئاسایی</option>
                </select>
            </div>
            <div class="filter-group">
                <label>دۆخ:</label>
                <select id="statusFilter">
                    <option value="">هەموو</option>
                    <option value="active">چالاک</option>
                    <option value="overdue">دواکەوتوو</option>
                </select>
            </div>
            <button class="btn-filter" onclick="applyFilters()">
                <i class="fas fa-search"></i>
                گەڕان
            </button>
            <button class="btn-reset" onclick="resetFilters()">
                <i class="fas fa-undo"></i>
                ڕێکخستنەوە
            </button>
        </div>
    </div>

    <!-- Debtors Table -->
    <div class="debtors-table-section">
        <div class="table-header">
            <div class="table-title">
                <i class="fas fa-list"></i>
                لیستی قەرزداران
                <span class="table-count" id="debtorCount"><?= is_array($debtors) ? count($debtors) : 0 ?></span>
            </div>
            <div class="table-actions">
                <button class="btn-export btn-excel" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i>
                    Excel
                </button>
                <button class="btn-export btn-pdf" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf"></i>
                    PDF
                </button>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="debtors-table" id="debtorsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نەخۆش</th>
                        <th>پەیوەندی</th>
                        <th>کۆی گشتی</th>
                        <th>بڕی دراو</th>
                        <th>بڕی ماوە</th>
                        <th>جۆری قەرز</th>
                        <th>دوایین پارەدان</th>
                        <th>دۆخ</th>
                        <th>کردارەکان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($debtors)): ?>
                    <tr>
                        <td colspan="10">
                            <div class="empty-state">
                                <i class="fas fa-smile"></i>
                                <h3>هیچ قەرزێک نییە!</h3>
                                <p>هەموو نەخۆشەکان پارەی خۆیان داوە</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php $index = 1; foreach ($debtors as $debtor):
                        $hasOverdue = $debtor['overdue_count'] > 0;
                        $hasInstallment = $debtor['has_installment_plan'] > 0;
                        $status = $hasOverdue ? 'overdue' : 'active';
                    ?>
                    <tr data-patient-id="<?= $debtor['id'] ?>"
                        data-debt-type="<?= $hasInstallment ? 'installment' : 'regular' ?>"
                        data-status="<?= $status ?>">
                        <td><?= $index++ ?></td>
                        <td>
                            <div class="patient-info">
                                <div class="patient-avatar">
                                    <?= mb_substr($debtor['full_name'], 0, 1, 'UTF-8') ?>
                                </div>
                                <div class="patient-details">
                                    <div class="patient-name"><?= htmlspecialchars($debtor['full_name']) ?></div>
                                    <div class="patient-code"><?= htmlspecialchars($debtor['patient_code']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="patient-phone">
                                <i class="fas fa-phone"></i>
                                <?= htmlspecialchars($debtor['phone']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="amount total"><?= number_format($debtor['total_amount']) ?></span>
                        </td>
                        <td>
                            <span class="amount paid"><?= number_format($debtor['total_paid']) ?></span>
                        </td>
                        <td>
                            <span class="amount debt"><?= number_format($debtor['total_debt']) ?> د</span>
                        </td>
                        <td>
                            <span class="debt-type-badge <?= $hasInstallment ? 'installment' : 'regular' ?>">
                                <?= $hasInstallment ? 'قیستی مانگانە' : 'قەرزی ئاسایی' ?>
                            </span>
                        </td>
                        <td>
                            <div class="last-payment">
                                <?php if ($debtor['last_payment_date']): ?>
                                    <i class="fas fa-calendar"></i>
                                    <?= date('Y/m/d', strtotime($debtor['last_payment_date'])) ?>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?= $status ?>">
                                <?php if ($hasOverdue): ?>
                                    <i class="fas fa-exclamation-circle"></i>
                                    دواکەوتوو (<?= $debtor['overdue_count'] ?>)
                                <?php else: ?>
                                    <i class="fas fa-check-circle"></i>
                                    چالاک
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-action btn-view"
                                        onclick="window.location.href='<?= APP_URL ?>/patients/view.php?id=<?= $debtor['id'] ?>'">
                                    <i class="fas fa-eye"></i>
                                    بینین
                                </button>
                                <button class="btn-action btn-pay"
                                        onclick="openPaymentModal(<?= $debtor['id'] ?>, '<?= htmlspecialchars($debtor['full_name']) ?>', <?= $debtor['total_debt'] ?>, '<?= htmlspecialchars($debtor['patient_code']) ?>')">
                                    <i class="fas fa-dollar-sign"></i>
                                    پارەدان
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($debtors)): ?>
                <?php
                    $sumTotal = array_sum(array_column($debtors, 'total_amount'));
                    $sumPaid = array_sum(array_column($debtors, 'total_paid'));
                ?>
                <tfoot>
                    <tr style="background:#1a1a2e;font-weight:800;">
                        <td colspan="3" style="padding:12px 14px;color:#fff;">
                            <i class="fas fa-coins"></i>
                            کۆی گشتی قەرزەکان — <?= count($debtors) ?> نەخۆش
                        </td>
                        <td style="padding:12px 14px;direction:ltr;text-align:right;color:#fff;"><?= number_format($sumTotal) ?></td>
                        <td style="padding:12px 14px;direction:ltr;text-align:right;color:#2ecc71;"><?= number_format($sumPaid) ?></td>
                        <td style="padding:12px 14px;direction:ltr;text-align:right;color:#ff6b6b;font-size:15px;"><?= number_format($totalDebt) ?></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Payment Modal - Compact Modern Design -->
<style>
/* Compact Payment Dialog Styles */
.pay-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}

.pay-overlay.show {
    display: flex;
}

.pay-dialog {
    max-width: 420px;
    width: 95%;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    background: #fff;
    animation: paySlideIn 0.25s ease-out;
}

@keyframes paySlideIn {
    from { opacity: 0; transform: translateY(-20px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.pay-header {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: white;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pay-header h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pay-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.pay-close:hover {
    background: rgba(255,255,255,0.25);
}

.pay-body {
    padding: 14px 16px;
}

/* Compact Patient + Debt Info Row */
.pay-info-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 12px;
}

.pay-patient-card {
    background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
    border: 1px solid #ce93d8;
    border-radius: 8px;
    padding: 10px 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.pay-patient-card .avatar {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
    font-weight: 700;
    flex-shrink: 0;
}

.pay-patient-card .info {
    min-width: 0;
}

.pay-patient-card .name {
    font-size: 13px;
    font-weight: 600;
    color: #4a148c;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pay-patient-card .code {
    font-size: 10px;
    color: #7b1fa2;
    font-family: var(--app-font-family);
}

.pay-debt-card {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    border: 1px solid #ef9a9a;
    border-radius: 8px;
    padding: 10px 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.pay-debt-card .icon {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
    flex-shrink: 0;
}

.pay-debt-card .info .label {
    font-size: 10px;
    color: #c62828;
    font-weight: 500;
}

.pay-debt-card .info .value {
    font-size: 16px;
    font-weight: 700;
    color: #b71c1c;
    font-family: var(--app-font-family);
    direction: ltr;
    text-align: right;
}

/* Credit Balance (inline) */
.pay-credit-row {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border: 1px solid #a5d6a7;
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}

.pay-credit-row .icon {
    width: 28px;
    height: 28px;
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
}

.pay-credit-row .label {
    font-size: 11px;
    color: #2e7d32;
    font-weight: 500;
}

.pay-credit-row .value {
    font-size: 14px;
    font-weight: 700;
    color: #1b5e20;
    font-family: var(--app-font-family);
    direction: ltr;
    margin-right: auto;
}

/* Compact Form Grid */
.pay-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 10px;
}

.pay-form-grid.full {
    grid-template-columns: 1fr;
}

.pay-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.pay-field label {
    font-size: 11px;
    font-weight: 600;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 4px;
}

.pay-field label i {
    color: #2e7d32;
    font-size: 10px;
}

.pay-field label .req {
    color: #f44336;
}

.pay-field input,
.pay-field select,
.pay-field textarea {
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: var(--app-font-family);
    transition: all 0.2s;
    background: #fff;
}

.pay-field input {
    direction: ltr;
    text-align: left;
}

.pay-field select {
    direction: rtl;
}

.pay-field textarea {
    direction: rtl;
    resize: none;
}

.pay-field input:focus,
.pay-field select:focus,
.pay-field textarea:focus {
    border-color: #2e7d32;
    box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.1);
    outline: none;
}

/* Compact Quick Amount Buttons */
.pay-quick-btns {
    display: flex;
    gap: 6px;
    margin-top: 6px;
    flex-wrap: wrap;
}

.pay-quick-btn {
    padding: 5px 10px;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    background: #fff;
    font-size: 11px;
    font-family: var(--app-font-family);
    cursor: pointer;
    transition: all 0.2s;
    color: #546e7a;
}

.pay-quick-btn:hover {
    border-color: #2e7d32;
    background: #e8f5e9;
    color: #2e7d32;
}

.pay-quick-btn.full {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: white;
    border-color: #2e7d32;
}

/* Compact Distribution Preview */
.pay-preview {
    margin: 10px 0;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.pay-preview h5 {
    font-size: 11px;
    margin: 0 0 8px;
    color: #37474f;
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
}

.pay-preview h5 i {
    color: #2e7d32;
    font-size: 10px;
}

.pay-preview-items {
    max-height: 120px;
    overflow-y: auto;
}

.pay-preview-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    margin-bottom: 4px;
    border-radius: 5px;
    font-size: 11px;
}

.pay-preview-summary {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #e0e0e0;
    font-size: 11px;
    font-family: var(--app-font-family);
}

.pay-preview-summary .row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 3px;
}

.pay-preview-summary .highlight {
    padding: 5px 8px;
    border-radius: 4px;
    margin-top: 4px;
}

.pay-preview-summary .highlight.credit {
    background: #e3f2fd;
}

.pay-preview-summary .highlight.remaining {
    background: #fff3e0;
}

/* Compact Footer */
.pay-footer {
    padding: 10px 16px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 8px;
    flex-direction: row-reverse;
}

.pay-btn {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.pay-btn-primary {
    background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
    color: white;
}

.pay-btn-primary:hover {
    background: linear-gradient(135deg, #1b5e20 0%, #0d3d12 100%);
}

.pay-btn-primary:disabled {
    background: #bdbdbd;
    cursor: not-allowed;
}

.pay-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1px solid #e0e0e0;
}

.pay-btn-secondary:hover {
    background: #f5f5f5;
}

@media (max-width: 480px) {
    .pay-dialog {
        width: 98%;
        margin: 8px;
    }
    .pay-info-row {
        grid-template-columns: 1fr;
    }
    .pay-form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="pay-overlay" id="paymentModal">
    <div class="pay-dialog">
        <div class="pay-header">
            <h4><i class="fas fa-money-bill-wave"></i> وەرگرتنی پارە</h4>
            <button type="button" class="pay-close" onclick="closePaymentModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="paymentForm" onsubmit="submitPayment(event)">
            <input type="hidden" id="modalPatientId" name="patient_id">
            <div class="pay-body">
                <!-- Patient + Debt Info Row -->
                <div class="pay-info-row">
                    <div class="pay-patient-card">
                        <div class="avatar" id="modalPatientInitial">-</div>
                        <div class="info">
                            <div class="name" id="modalPatientName">-</div>
                            <div class="code" id="modalPatientCode">-</div>
                        </div>
                    </div>
                    <div class="pay-debt-card">
                        <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="info">
                            <div class="label">کۆی قەرز</div>
                            <div class="value" id="modalDebtAmount">0</div>
                        </div>
                    </div>
                </div>

                <!-- Credit Balance (if exists) -->
                <div class="pay-credit-row" id="creditRow" style="display: none;">
                    <div class="icon"><i class="fas fa-wallet"></i></div>
                    <span class="label">باڵانس:</span>
                    <span class="value" id="modalCreditAmount">0</span>
                </div>

                <!-- Amount + Method Row -->
                <div class="pay-form-grid">
                    <div class="pay-field">
                        <label><i class="fas fa-coins"></i> بڕی پارە <span class="req">*</span></label>
                        <input type="text" inputmode="numeric" pattern="[0-9,\.]*"
                               name="amount" id="paymentAmount" required placeholder="0"
                               oninput="previewPaymentDistribution()">
                        <div class="pay-quick-btns">
                            <button type="button" class="pay-quick-btn" onclick="setQuickAmount(50000)">50,000</button>
                            <button type="button" class="pay-quick-btn" onclick="setQuickAmount(100000)">100,000</button>
                            <button type="button" class="pay-quick-btn" onclick="setQuickAmount(200000)">200,000</button>
                            <button type="button" class="pay-quick-btn full" onclick="setFullAmount()">تەواو</button>
                        </div>
                    </div>
                    <div class="pay-field">
                        <label><i class="fas fa-credit-card"></i> شێواز <span class="req">*</span></label>
                        <select name="payment_method" required>
                            <option value="cash">نەقد</option>
                            <option value="card">کارتی بانکی</option>
                            <option value="transfer">گواستنەوە</option>
                            <option value="other">هی تر</option>
                        </select>
                    </div>
                </div>

                <!-- Distribution Preview -->
                <div class="pay-preview" id="distributionPreview" style="display: none;">
                    <h5><i class="fas fa-chart-pie"></i> پێشبینی دابەشکردن</h5>
                    <div class="pay-preview-items" id="distributionItems"></div>
                    <div class="pay-preview-summary" id="distributionSummary"></div>
                </div>

                <!-- Notes -->
                <div class="pay-form-grid full">
                    <div class="pay-field">
                        <label><i class="fas fa-comment"></i> تێبینی</label>
                        <textarea name="notes" rows="2" placeholder="تێبینی ئارەزوومەندانە..."></textarea>
                    </div>
                </div>
            </div>
            <div class="pay-footer">
                <button type="submit" class="pay-btn pay-btn-primary" id="submitPaymentBtn">
                    <i class="fas fa-check-circle"></i> وەرگرتن
                </button>
                <button type="button" class="pay-btn pay-btn-secondary" onclick="closePaymentModal()">
                    <i class="fas fa-times"></i> داخستن
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter and Search Functions
function applyFilters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const debtType = document.getElementById('debtTypeFilter').value;
    const status = document.getElementById('statusFilter').value;

    const rows = document.querySelectorAll('#debtorsTable tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        const patientInfo = row.querySelector('.patient-name, .patient-code, .patient-phone');
        const rowDebtType = row.dataset.debtType;
        const rowStatus = row.dataset.status;

        let showRow = true;

        // Search filter
        if (searchTerm && patientInfo) {
            const text = patientInfo.textContent.toLowerCase();
            showRow = text.includes(searchTerm);
        }

        // Debt type filter
        if (debtType && rowDebtType !== debtType) {
            showRow = false;
        }

        // Status filter
        if (status && rowStatus !== status) {
            showRow = false;
        }

        row.style.display = showRow ? '' : 'none';
        if (showRow) visibleCount++;
    });

    document.getElementById('debtorCount').textContent = visibleCount;
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('debtTypeFilter').value = '';
    document.getElementById('statusFilter').value = '';
    applyFilters();
}

// Payment Modal Functions - Flexible Distribution System
let currentPatientData = {};
let debtItems = [];
let previewTimeout = null;

function openPaymentModal(patientId, patientName, debtAmount, patientCode = '') {
    currentPatientData = {
        id: patientId,
        name: patientName,
        debt: debtAmount,
        code: patientCode
    };

    document.getElementById('modalPatientId').value = patientId;
    document.getElementById('modalPatientName').textContent = patientName;
    document.getElementById('modalPatientInitial').textContent = patientName.charAt(0);
    document.getElementById('modalPatientCode').textContent = patientCode || 'P-' + patientId;
    document.getElementById('modalDebtAmount').innerHTML = formatNumber(debtAmount) + ' <small>د</small>';

    // Reset form
    document.getElementById('paymentForm').reset();
    document.getElementById('distributionPreview').style.display = 'none';
    document.getElementById('distributionItems').innerHTML = '';
    document.getElementById('distributionSummary').innerHTML = '';

    // Load debt items for this patient
    loadDebtItems(patientId);

    document.getElementById('paymentModal').classList.add('show');
    document.getElementById('paymentAmount').focus();
}

function loadDebtItems(patientId) {
    fetch(`<?= APP_URL ?>/api/debt_payment.php?action=get_debt_items&patient_id=${patientId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                debtItems = data.data.items || [];
                currentPatientData.credit = data.data.credit_balance || 0;

                // Show credit if exists
                const creditRow = document.getElementById('creditRow');
                if (currentPatientData.credit > 0) {
                    creditRow.style.display = 'flex';
                    document.getElementById('modalCreditAmount').textContent = formatNumber(currentPatientData.credit) + ' د';
                } else {
                    creditRow.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error loading debt items:', error);
        });
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('show');
    document.getElementById('paymentForm').reset();
    document.getElementById('distributionPreview').style.display = 'none';
    debtItems = [];
}

function setQuickAmount(amount) {
    document.getElementById('paymentAmount').value = amount;
    previewPaymentDistribution();
}

function setFullAmount() {
    document.getElementById('paymentAmount').value = currentPatientData.debt;
    previewPaymentDistribution();
}

// Force Latin (English) digits in number formatting
function formatNumber(num) {
    return new Intl.NumberFormat('en-US').format(Number(num || 0));
}

// Convert Arabic-Indic and Eastern Arabic digits to English digits
function toEnglishDigits(str) {
    return String(str)
        .replace(/[٠-٩]/g, d => "٠١٢٣٤٥٦٧٨٩".indexOf(d))
        .replace(/[۰-۹]/g, d => "۰۱۲۳۴۵۶۷۸۹".indexOf(d))
        .replace(/[^\d.,]/g, ''); // Remove any non-numeric characters except . and ,
}

function previewPaymentDistribution() {
    clearTimeout(previewTimeout);

    const inputEl = document.getElementById('paymentAmount');
    // Convert to English digits and clean
    inputEl.value = toEnglishDigits(inputEl.value);

    const amount = parseFloat(inputEl.value.replace(/,/g, '')) || 0;
    const previewDiv = document.getElementById('distributionPreview');
    const itemsDiv = document.getElementById('distributionItems');
    const summaryDiv = document.getElementById('distributionSummary');

    if (amount <= 0) {
        previewDiv.style.display = 'none';
        return;
    }

    // Debounce the API call
    previewTimeout = setTimeout(() => {
        fetch(`<?= APP_URL ?>/api/debt_payment.php?action=preview_payment&patient_id=${currentPatientData.id}&amount=${amount}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    previewDiv.style.display = 'block';
                    renderDistributionPreview(data.data, itemsDiv, summaryDiv);
                }
            })
            .catch(error => {
                console.error('Error previewing distribution:', error);
            });
    }, 300);
}

function renderDistributionPreview(preview, itemsDiv, summaryDiv) {
    let itemsHtml = '';

    preview.allocations.forEach((alloc, index) => {
        const item = alloc.item;
        const statusIcon = alloc.status === 'fully_paid' ? 'check-circle' :
                          alloc.status === 'partial' ? 'adjust' : 'circle';
        const statusColor = alloc.status === 'fully_paid' ? '#4caf50' :
                           alloc.status === 'partial' ? '#ff9800' : '#9e9e9e';
        const bgColor = alloc.status === 'fully_paid' ? '#e8f5e9' :
                       alloc.status === 'partial' ? '#fff3e0' : '#fafafa';

        itemsHtml += `
            <div style="display: flex; align-items: center; gap: 10px; padding: 8px; margin-bottom: 6px; background: ${bgColor}; border-radius: 6px; font-size: 12px;">
                <i class="fas fa-${statusIcon}" style="color: ${statusColor}; font-size: 14px;"></i>
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 600; color: #2c3e50; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${index + 1}. ${item.item_name}
                    </div>
                    <div style="color: #757575; font-size: 11px;">
                        ${item.item_type === 'case' ? 'کەیس' : 'ئێکسڕەی'} • قەرز: ${formatNumber(item.remaining)} د
                    </div>
                </div>
                <div style="text-align: left; flex-shrink: 0;">
                    <div style="font-weight: 700; color: ${statusColor};">
                        ${alloc.allocated > 0 ? formatNumber(alloc.allocated) + ' د' : '-'}
                    </div>
                    ${alloc.remaining_after > 0 ? `<div style="font-size: 10px; color: #f44336;">ماوە: ${formatNumber(alloc.remaining_after)}</div>` : ''}
                </div>
            </div>
        `;
    });

    itemsDiv.innerHTML = itemsHtml;

    // Summary
    let summaryHtml = `
        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
            <span>کۆی قەرز:</span>
            <span style="font-weight: 600;">${formatNumber(preview.total_debt)} د</span>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
            <span>پارەی دراو:</span>
            <span style="font-weight: 600; color: #4caf50;">${formatNumber(preview.total_allocated)} د</span>
        </div>
    `;

    if (preview.credit > 0) {
        summaryHtml += `
            <div style="display: flex; justify-content: space-between; background: #e3f2fd; padding: 6px 8px; border-radius: 4px; margin-top: 6px;">
                <span><i class="fas fa-plus-circle" style="color: #2196f3;"></i> زیادە بۆ باڵانس:</span>
                <span style="font-weight: 700; color: #2196f3;">${formatNumber(preview.credit)} د</span>
            </div>
        `;
    }

    const remaining = preview.total_debt - preview.total_allocated;
    if (remaining > 0) {
        summaryHtml += `
            <div style="display: flex; justify-content: space-between; background: #fff3e0; padding: 6px 8px; border-radius: 4px; margin-top: 6px;">
                <span><i class="fas fa-exclamation-circle" style="color: #ff9800;"></i> قەرزی ماوە:</span>
                <span style="font-weight: 700; color: #ff9800;">${formatNumber(remaining)} د</span>
            </div>
        `;
    }

    summaryDiv.innerHTML = summaryHtml;
}

function submitPayment(event) {
    event.preventDefault();

    const formData = new FormData(event.target);
    formData.append('action', 'make_payment');

    // Convert Arabic-Indic digits to English and clean
    const amountStr = toEnglishDigits(formData.get('amount')).replace(/,/g, '');
    formData.set('amount', amountStr);
    const amount = parseFloat(amountStr) || 0;

    if (amount <= 0) {
        showToast('error', 'تکایە بڕێکی دروست داخل بکە');
        return;
    }

    if (amount > currentPatientData.debt) {
        const extra = amount - currentPatientData.debt;
        if (!confirm(`بڕەکە ${formatNumber(extra)} زیاترە لە قەرزەکە.\nزیادەکە دەخرێتە باڵانسی نەخۆش.\n\nدەتەوێت بەردەوام بیت؟`)) {
            return;
        }
    }

    // Disable submit button
    const submitBtn = document.getElementById('submitPaymentBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> پرۆسێس دەکرێت...';

    // Send to new flexible payment API
    fetch('<?= APP_URL ?>/api/debt_payment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message with allocation details
            let message = 'پارەکە بە سەرکەوتوویی وەرگیرا!\n\n';
            message += `کۆی پارە: ${formatNumber(data.total_paid)} د\n`;

            if (data.credit > 0) {
                message += `زیادکرا بە باڵانس: ${formatNumber(data.credit)} د\n`;
            }

            if (data.allocations && data.allocations.length > 0) {
                message += '\nدابەشکردن:\n';
                data.allocations.forEach(alloc => {
                    message += `• ${alloc.item_name}: ${formatNumber(alloc.amount)} د\n`;
                });
            }

            alert(message);

            // Open receipt if available
            if (data.receipt_url) {
                window.open(data.receipt_url, '_blank');
            }

            // Reload page to update data
            location.reload();
        } else {
            showToast('error', 'هەڵە: ' + (data.message || 'Unknown error'));
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        showToast('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن بە سێرڤەر');
        console.error('Error:', error);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

// Export Functions
function exportToExcel() {
    window.location.href = '<?= APP_URL ?>/api/export-debts.php?type=excel';
}

function exportToPDF() {
    window.location.href = '<?= APP_URL ?>/api/export-debts.php?type=pdf';
}

// Close modal on outside click
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePaymentModal();
    }
});

// Real-time search
document.getElementById('searchInput').addEventListener('input', function() {
    applyFilters();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
