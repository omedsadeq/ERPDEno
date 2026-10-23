<?php
/**
 * X-Ray Payment History Page
 * مێژووی پارەدانەکانی ئێکسڕەی - بەشی ئێکسڕەی
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$auth = new Auth();
require_once __DIR__ . '/../classes/Permission.php';
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.xray_history')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$xrayManager = new XrayManager();

$isAdmin = $auth->isAdmin();
$isXrayDepartment = $auth->isXrayDepartment();

// Filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$paymentStatus = $_GET['payment_status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query for paid X-Ray requests
$sql = "SELECT xr.*,
        COALESCE(xr.settled_at, xr.paid_at, xr.accountant_approved_at, xr.created_at) AS payment_time,
        p.full_name as patient_name, p.phone as patient_phone, p.patient_code,
        COALESCE(xed.name, u.full_name) as doctor_name,
        xed.name as external_doctor_name,
        cu.full_name as completed_by_name,
        pu.full_name as paid_by_name
        FROM xray_requests xr
        LEFT JOIN patients p ON xr.patient_id = p.id
        LEFT JOIN users u ON xr.requested_by = u.id
        LEFT JOIN xray_external_doctors xed ON xed.id = xr.external_doctor_id
        LEFT JOIN users cu ON xr.completed_by = cu.id
        LEFT JOIN users pu ON COALESCE(xr.paid_by, xr.settled_by, xr.accountant_id) = pu.id
        WHERE xr.payment_status IN ('paid', 'debt', 'debt_approved')
        AND COALESCE(xr.request_status, '') <> 'cancelled'
        AND DATE(COALESCE(xr.settled_at, xr.paid_at, xr.accountant_approved_at, xr.created_at)) BETWEEN :date_from AND :date_to";

$params = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];

if ($paymentStatus === 'debt') {
    $sql .= " AND xr.payment_status IN ('debt', 'debt_approved')";
} elseif ($paymentStatus) {
    $sql .= " AND xr.payment_status = :payment_status";
    $params['payment_status'] = $paymentStatus;
}

if ($search) {
    $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR xr.request_number LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}

$sql .= " ORDER BY payment_time DESC, xr.created_at DESC";

$paidRequests = $db->fetchAll($sql, $params);

// Calculate totals
$totalPaid = 0;
$totalDebt = 0;
$totalDiscount = 0;
$totalOriginal = 0;
$countPaid = 0;
$countDebt = 0;

foreach ($paidRequests as $req) {
    $totalOriginal += ($req['original_amount'] ?? $req['amount']);
    if ($req['payment_status'] === 'paid') {
        $totalPaid += ($req['final_amount'] ?? $req['amount']);
        $countPaid++;
    } else {
        $totalDebt += ($req['final_amount'] ?? $req['amount']);
        $countDebt++;
    }
    $totalDiscount += ($req['discount_amount'] ?? 0);
}

// X-Ray Types
$xrayTypes = [
    'panoramic' => 'پانۆرامیک',
    'cephalometric' => 'سێفالۆمەتریک',
    'periapical' => 'پەریئەپیکاڵ',
    'cbct' => 'CBCT (3D)'
];

$pageTitle = 'مێژووی پارەدانەکان';
$currentPage = 'xray-payment-history';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Payment History Styles - Compact */
.ph-container {
    max-width: 1400px;
    margin: 0 auto;
}

/* Stats Grid */
.ph-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}

.ph-stat-card {
    background: linear-gradient(135deg, var(--bg-start) 0%, var(--bg-end) 100%);
    border-radius: 8px;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    position: relative;
    overflow: hidden;
}

.ph-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--accent-color);
}

.ph-stat-card.stat-paid {
    --bg-start: #e8f5e9;
    --bg-end: #c8e6c9;
    --border-color: #a5d6a7;
    --accent-color: #4caf50;
}

.ph-stat-card.stat-debt {
    --bg-start: #fce4ec;
    --bg-end: #f8bbd0;
    --border-color: #f48fb1;
    --accent-color: #e91e63;
}

.ph-stat-card.stat-discount {
    --bg-start: #fff3e0;
    --bg-end: #ffe0b2;
    --border-color: #ffcc80;
    --accent-color: #ff9800;
}

.ph-stat-card.stat-total {
    --bg-start: #e3f2fd;
    --bg-end: #bbdefb;
    --border-color: #90caf9;
    --accent-color: #2196f3;
}

.ph-stat-icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: var(--accent-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    margin-bottom: 6px;
}

.ph-stat-value {
    font-size: 20px;
    font-weight: 800;
    color: var(--accent-color);
    margin-bottom: 2px;
    line-height: 1.1;
}

.ph-stat-label {
    font-size: 11px;
    color: #546e7a;
    font-weight: 600;
}

.ph-stat-count {
    position: absolute;
    top: 10px;
    left: 10px;
    background: var(--accent-color);
    color: white;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
}

/* Filters Panel */
.ph-filters {
    background: white;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 12px;
    border: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.ph-filter-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.ph-filter-label {
    font-size: 10px;
    font-weight: 600;
    color: #546e7a;
}

.ph-filter-control {
    padding: 5px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fafafa;
}

.ph-filter-control:focus {
    border-color: #0078d4;
    background: white;
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.1);
}

/* Table Styles */
.ph-table-container {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.ph-table-header {
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    color: white;
    padding: 8px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ph-table-title {
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.ph-table {
    width: 100%;
    border-collapse: collapse;
}

.ph-table thead {
    background: #f8f9fa;
}

.ph-table th {
    padding: 8px 10px;
    text-align: right;
    font-size: 11px;
    font-weight: 700;
    color: #37474f;
    border-bottom: 2px solid #e0e0e0;
}

.ph-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}

.ph-table tbody tr:hover {
    background: #f8f9fa;
}

.ph-table td {
    padding: 6px 10px;
    font-size: 13px;
    color: #2c3e50;
}

/* Payment Status Badge */
.ph-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.ph-status-badge.paid {
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    color: #2e7d32;
}

.ph-status-badge.debt,
.ph-status-badge.debt_approved {
    background: linear-gradient(135deg, #fce4ec, #f8bbd0);
    color: #c2185b;
}

/* Amount Display */
.ph-amount {
    font-weight: 700;
    font-size: 13px;
}

.ph-amount.positive { color: #2e7d32; }
.ph-amount.negative { color: #c2185b; }
.ph-amount.discount { color: #e65100; }

/* Patient Info */
.ph-patient-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ph-patient-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0078d4, #005a9e);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12px;
    flex-shrink: 0;
}

.ph-patient-name {
    font-weight: 600;
    color: #0078d4;
}

.ph-patient-phone {
    font-size: 10px;
    color: #757575;
    direction: ltr;
    text-align: right;
}

/* Request Number */
.ph-request-number {
    font-family: var(--app-font-family);
    font-weight: 600;
    color: #546e7a;
}

/* Empty State */
.ph-empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #9e9e9e;
}

.ph-empty-state i {
    font-size: 36px;
    margin-bottom: 12px;
    color: #e0e0e0;
}

.ph-empty-state h4 {
    margin: 0 0 6px;
    color: #757575;
}

/* Print Button */
.ph-print-btn {
    background: white;
    color: #0078d4;
    border: 1px solid #0078d4;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
}

.ph-print-btn:hover {
    background: #0078d4;
    color: white;
}

@media (max-width: 1024px) {
    .ph-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .ph-stats-grid {
        grid-template-columns: 1fr;
    }
    .ph-filters {
        flex-direction: column;
        align-items: stretch;
    }
}

@media print {
    .ph-filters, .ph-print-btn, .nav-pane, header, footer {
        display: none !important;
    }
    .ph-table-container {
        border: none;
    }
}
</style>

<div class="ph-container">
    <?php if (isset($_GET['success'])): ?>
    <div style="background:#dff6dd;color:#107c10;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;">
        <i class="fas fa-check-circle"></i> پارەدان/قەرز بە سەرکەوتوویی تۆمارکرا.
    </div>
    <?php endif; ?>
    <!-- Filters -->
    <form method="GET" class="ph-filters">
        <div class="ph-filter-group">
            <label class="ph-filter-label"><i class="fas fa-calendar"></i> لە بەروار</label>
            <input type="date" name="date_from" class="ph-filter-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="ph-filter-group">
            <label class="ph-filter-label"><i class="fas fa-calendar"></i> بۆ بەروار</label>
            <input type="date" name="date_to" class="ph-filter-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="ph-filter-group">
            <label class="ph-filter-label"><i class="fas fa-filter"></i> دۆخی پارەدان</label>
            <select name="payment_status" class="ph-filter-control">
                <option value="">هەموو</option>
                <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>پارەدراو</option>
                <option value="debt" <?= $paymentStatus === 'debt' ? 'selected' : '' ?>>قەرز</option>
            </select>
        </div>
        <div class="ph-filter-group" style="flex: 1; min-width: 200px;">
            <label class="ph-filter-label"><i class="fas fa-search"></i> گەڕان</label>
            <input type="text" name="search" class="ph-filter-control" placeholder="ناو، ژمارە، کۆد..." value="<?= htmlspecialchars($search) ?>" style="width: 100%;">
        </div>
        <button type="submit" class="btn btn-primary" style="height: 30px; padding: 0 12px; font-size: 12px;"><i class="fas fa-search"></i> گەڕان</button>
        <a href="?" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 12px;"><i class="fas fa-redo"></i></a>
    </form>

    <!-- Table -->
    <div class="ph-table-container">
        <div class="ph-table-header">
            <h3 class="ph-table-title">
                <i class="fas fa-history"></i>
                مێژووی پارەدانەکان
            </h3>
            <button onclick="window.print()" class="ph-print-btn">
                <i class="fas fa-print"></i> چاپکردن
            </button>
        </div>

        <?php if (empty($paidRequests)): ?>
        <div class="ph-empty-state">
            <i class="fas fa-inbox"></i>
            <h4>هیچ تۆمارێک نەدۆزرایەوە</h4>
            <p>هیچ پارەدانێکی ئێکسڕەی لەم ماوەیەدا نییە</p>
        </div>
        <?php else: ?>
        <table class="ph-table">
            <thead>
                <tr>
                    <th>ژمارەی داواکاری</th>
                    <th>نەخۆش</th>
                    <th>جۆری ئێکسڕەی</th>
                    <th>نرخی ئەسڵی</th>
                    <th>داشکاندن</th>
                    <th>نرخی کۆتایی</th>
                    <th>دۆخ</th>
                    <th>کاتی پارەدان</th>
                    <th>پارەوەرگر</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paidRequests as $req): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/xray/view.php?id=<?= $req['id'] ?>" class="ph-request-number">
                            <?= htmlspecialchars($req['request_number']) ?>
                        </a>
                    </td>
                    <td>
                        <div class="ph-patient-info">
                            <div class="ph-patient-avatar"><?= mb_substr($req['patient_name'], 0, 1) ?></div>
                            <div>
                                <div class="ph-patient-name"><?= htmlspecialchars($req['patient_name']) ?></div>
                                <div class="ph-patient-phone"><?= htmlspecialchars($req['patient_phone'] ?? '') ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= $xrayTypes[$req['xray_type']] ?? $req['xray_type'] ?></td>
                    <td>
                        <span class="ph-amount"><?= number_format($req['original_amount'] ?? $req['amount']) ?></span>
                    </td>
                    <td>
                        <?php if (($req['discount_amount'] ?? 0) > 0): ?>
                        <span class="ph-amount discount">- <?= number_format($req['discount_amount']) ?></span>
                        <?php if (!empty($req['discount_reason'])): ?>
                        <br><small style="color: #9e9e9e;"><?= htmlspecialchars($req['discount_reason']) ?></small>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color: #bdbdbd;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="ph-amount <?= $req['payment_status'] === 'paid' ? 'positive' : 'negative' ?>">
                            <?= number_format($req['final_amount'] ?? $req['amount']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="ph-status-badge <?= $req['payment_status'] ?>">
                            <?php if ($req['payment_status'] === 'paid'): ?>
                            <i class="fas fa-check-circle"></i> پارەدراو
                            <?php else: ?>
                            <i class="fas fa-hand-holding-usd"></i> قەرز
                            <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($req['payment_time']): ?>
                        <span style="direction: ltr; display: inline-block;"><?= date('Y-m-d H:i', strtotime($req['payment_time'])) ?></span>
                        <?php else: ?>
                        <span style="color: #bdbdbd;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($req['paid_by_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#1a1a2e;font-weight:800;">
                    <td colspan="3" style="padding:12px 14px;color:#fff;">
                        <i class="fas fa-calculator"></i>
                        کۆی گشتی — <?= count($paidRequests) ?> داواکاری
                        <span style="color:#2ecc71;margin-right:10px;"><i class="fas fa-check-circle"></i> پارەدراو: <?= number_format($totalPaid) ?> (<?= $countPaid ?>)</span>
                        <span style="color:#f39c12;margin-right:10px;"><i class="fas fa-hand-holding-usd"></i> قەرز: <?= number_format($totalDebt) ?> (<?= $countDebt ?>)</span>
                    </td>
                    <td style="padding:12px 14px;color:#fff;direction:ltr;text-align:right;"><?= number_format($totalOriginal) ?></td>
                    <td style="padding:12px 14px;color:#ff6b6b;direction:ltr;text-align:right;">- <?= number_format($totalDiscount) ?></td>
                    <td style="padding:12px 14px;color:#2ecc71;direction:ltr;text-align:right;font-size:15px;"><?= number_format($totalPaid + $totalDebt) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
