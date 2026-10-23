<?php
/**
 * X-Ray Payments Page
 * پارەدانی ئێکسڕەی - ئامادەی پارەدان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$auth = new Auth();
require_once __DIR__ . '/../classes/Permission.php';
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.xray_payments')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$xrayManager = new XrayManager();

$isAdmin = $auth->isAdmin();
$isAccountant = $auth->isAccountant();
$canHandlePayments = $auth->canHandleXrayPayments(); // Admin or Accountant

// Handle AJAX actions.
// process_cash / process_debt / apply_discount / set_price were retired
// 2026-08-26: settlement now happens only via the Approval Center
// (XrayManager::transitionWorkflow / approvePaymentAndSendToXray /
// approveDebtAndSendToXray) and pricing stays in admin/xray-pricing.php.
// This page keeps only the read-only awaiting-payment list and history.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    Csrf::require();

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

$pageTitle = 'پارەدانی ئێکسڕەی';
$currentPage = 'xray-payments';
require_once __DIR__ . '/../includes/header.php';

// Get awaiting payment requests
$awaitingPayment = $xrayManager->getAwaitingPayment(1, 100);

// For doctors, filter to only their requests (accountants and admins see all)
if (!$canHandlePayments) {
    $awaitingPayment = array_filter($awaitingPayment, function($req) use ($currentUser) {
        return $req['requested_by'] == $currentUser['id'];
    });
}

// Get daily report for admin/accountant
$dailyReport = $canHandlePayments ? $xrayManager->getDailyReport() : null;

// X-Ray Types
$xrayTypes = [
    'panoramic' => 'پانۆرامیک',
    'cephalometric' => 'سێفالۆمەتریک',
    'periapical' => 'پەریئەپیکاڵ',
    'cbct' => 'CBCT (3D)'
];

function formatNullableDateTime($value, $format = 'Y-m-d H:i') {
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return '-';
    }

    return date($format, $timestamp);
}
?>

<style>
.payments-container {
    max-width: 1400px;
    margin: 0 auto;
}

/* Stats Cards - Compact */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}

.stat-card {
    background: white;
    border-radius: 6px;
    padding: 10px 12px;
    border: 1px solid #e0e0e0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.stat-card .stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.stat-card .stat-value {
    font-size: 20px;
    font-weight: 700;
    color: #1a237e;
    line-height: 1.1;
}

.stat-card .stat-label {
    font-size: 11px;
    color: #666;
}

.stat-card.waiting .stat-icon { background: #fff3e0; color: #ff9800; }
.stat-card.paid .stat-icon { background: #e8f5e9; color: #4caf50; }
.stat-card.revenue .stat-icon { background: #e3f2fd; color: #1976d2; }
.stat-card.discount .stat-icon { background: #fce4ec; color: #e91e63; }

/* Requests Table */
.requests-card {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.requests-header {
    padding: 10px 14px;
    background: linear-gradient(135deg, #1a237e 0%, #3949ab 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.requests-header h2 {
    font-size: 14px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.requests-count {
    background: rgba(255,255,255,0.2);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
}

.requests-table {
    width: 100%;
    border-collapse: collapse;
}

.requests-table th {
    background: #f5f5f5;
    padding: 8px 12px;
    text-align: right;
    font-size: 11px;
    font-weight: 600;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e0e0e0;
}

.requests-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
}

.requests-table tr:hover {
    background: #f9f9f9;
}

.patient-info .name {
    font-weight: 600;
    color: #333;
}

.patient-info .phone {
    font-size: 12px;
    color: #888;
}

.request-number {
    font-family: var(--app-font-family);
    font-size: 13px;
    color: #1a237e;
    font-weight: 600;
}

.xray-type-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    background: #e8eaf6;
    color: #3949ab;
}

.amount-display {
    text-align: left;
}

.amount-original {
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.amount-discount {
    font-size: 12px;
    color: #e91e63;
    text-decoration: line-through;
}

.amount-final {
    font-size: 16px;
    font-weight: 700;
    color: #4caf50;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-view {
    background: #e3f2fd;
    color: #1976d2;
}

.btn-view:hover {
    background: #bbdefb;
}

.no-price-warning {
    background: #fff8e1;
    color: #f57c00;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    display: inline-block;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #999;
}

.empty-state i {
    font-size: 36px;
    margin-bottom: 12px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 15px;
    color: #666;
    margin-bottom: 6px;
}

/* Doctor View */
.doctor-notice {
    background: #e3f2fd;
    border: 1px solid #90caf9;
    border-radius: 6px;
    padding: 10px 14px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.doctor-notice i {
    font-size: 18px;
    color: #1976d2;
}

.doctor-notice .text {
    font-size: 13px;
    color: #1565c0;
}

/* Payment Status Badges */
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.badge-yellow {
    background: #fff3e0;
    color: #e65100;
    border: 1px solid #ffcc80;
}

.badge-red {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ef9a9a;
}

.badge-secondary {
    background: #f5f5f5;
    color: #616161;
    border: 1px solid #e0e0e0;
}

@media (max-width: 900px) {
    .stats-row {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 500px) {
    .stats-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="payments-container">
    <?php if (!$canHandlePayments): ?>
    <div class="doctor-notice">
        <i class="fas fa-info-circle"></i>
        <div class="text">
            ئەم لیستە تەنها ئێکسڕەیەکانی تۆ نیشان دەدات کە تەواوبوون و ئامادەن بۆ پارەدان.
            ژمێریار دەبێت پارەی وەربگرێت و پسوڵەکە بدات.
        </div>
    </div>
    <?php endif; ?>

    <!-- Requests Awaiting Payment -->
    <div class="requests-card">
        <div class="requests-header">
            <h2><i class="fas fa-cash-register"></i> داواکارییەکانی ئامادەی پارەدان</h2>
            <span class="requests-count"><?= count($awaitingPayment) ?> داواکاری</span>
        </div>

        <?php if (empty($awaitingPayment)): ?>
        <div class="empty-state">
            <i class="fas fa-check-circle"></i>
            <h3>هیچ داواکاریێک چاوەڕوانی پارەدان نییە</h3>
            <p>هەموو ئێکسڕەیەکان پارەیان دراوە</p>
        </div>
        <?php else: ?>
        <table class="requests-table">
            <thead>
                <tr>
                    <th>ژمارەی داواکاری</th>
                    <th>نەخۆش</th>
                    <th>جۆری ئێکسڕەی</th>
                    <th>دکتۆر</th>
                    <th>دۆخی پارەدان</th>
                    <th>نرخ</th>
                    <?php if ($canHandlePayments): ?>
                    <th>کردارەکان</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($awaitingPayment as $request): ?>
                <tr id="request-row-<?= $request['id'] ?>">
                    <td>
                        <span class="request-number"><?= $request['request_number'] ?></span>
                        <br>
                        <small style="color: #888;"><?= formatNullableDateTime($request['completed_at']) ?></small>
                    </td>
                    <td>
                        <div class="patient-info">
                            <div class="name"><?= htmlspecialchars($request['patient_name']) ?></div>
                            <div class="phone"><?= $request['patient_phone'] ?></div>
                        </div>
                    </td>
                    <td>
                        <span class="xray-type-badge"><?= $xrayTypes[$request['xray_type']] ?? $request['xray_type'] ?></span>
                    </td>
                    <td>
                        <?= htmlspecialchars($request['requested_by_name']) ?>
                        <?php if (!empty($request['external_doctor_name'])): ?>
                        <span title="دکتۆری دەرەوە" style="display:inline-block;margin-inline-start:4px;padding:1px 7px;border-radius:999px;background:#fff3e0;color:#b25e09;border:1px solid #ffd9a8;font-size:10px;font-weight:700;">دەرەوە</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $paymentStatusBadges = [
                            'awaiting_payment' => ['badge-yellow', 'چاوەڕوانی پارەدان'],
                            'debt' => ['badge-red', 'قەرز']
                        ];
                        $psBadge = $paymentStatusBadges[$request['payment_status']] ?? ['badge-secondary', $request['payment_status']];
                        ?>
                        <span class="badge <?= $psBadge[0] ?>"><?= $psBadge[1] ?></span>
                    </td>
                    <td class="amount-display">
                        <?php
                        $original = floatval($request['original_amount'] ?: $request['amount']);
                        $discount = floatval($request['discount_amount'] ?? 0);
                        $final = floatval($request['final_amount'] ?: $original);
                        $hasNoPrice = ($original <= 0);
                        ?>
                        <?php if ($hasNoPrice): ?>
                        <div class="no-price-warning"><i class="fas fa-exclamation-triangle"></i> نرخ نییە</div>
                        <?php elseif ($discount > 0): ?>
                        <div class="amount-discount"><?= number_format($original) ?></div>
                        <div class="amount-final"><?= number_format($final) ?></div>
                        <?php else: ?>
                        <div class="amount-original"><?= number_format($original) ?></div>
                        <?php endif; ?>
                    </td>
                    <?php if ($canHandlePayments): ?>
                    <td>
                        <div class="action-buttons">
                            <!-- Settlement (cash/debt/discount/pricing) now happens only in the
                                 Approval Center; this page is view-only for awaiting-payment requests. -->
                            <a href="<?= APP_URL ?>/xray/view.php?id=<?= $request['id'] ?>" class="action-btn btn-view">
                                <i class="fas fa-eye"></i>
                            </a>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($canHandlePayments && $dailyReport): ?>
            <tfoot>
                <tr style="background:#1a1a2e;font-weight:800;">
                    <td colspan="5" style="padding:12px 14px;color:#fff;">
                        <i class="fas fa-money-bill-wave"></i>
                        داهاتی ئەمڕۆ
                        <span style="color:#f39c12;margin-right:10px;"><i class="fas fa-clock"></i> چاوەڕوانی پارەدان: <?= count($awaitingPayment) ?></span>
                        <span style="color:#2ecc71;margin-right:10px;"><i class="fas fa-check-circle"></i> پارەدراوی ئەمڕۆ: <?= (int)($dailyReport['paid_count'] ?? 0) ?></span>
                        <span style="color:#ff9eb5;margin-right:10px;"><i class="fas fa-percent"></i> داشکاندن: <?= number_format($dailyReport['total_discount'] ?? 0) ?></span>
                    </td>
                    <td style="padding:12px 14px;color:#2ecc71;direction:ltr;text-align:right;font-size:15px;"><?= number_format($dailyReport['total_revenue'] ?? 0) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
