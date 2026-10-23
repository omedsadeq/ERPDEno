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

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    Csrf::require();

    $action = $_POST['action'];
    $requestId = intval($_POST['request_id'] ?? 0);

    if ($action === 'apply_discount' && $canHandlePayments) {
        $discountAmount = floatval($_POST['discount_amount'] ?? 0);
        $discountReason = trim($_POST['discount_reason'] ?? '');
        $result = $xrayManager->applyDiscount($requestId, $discountAmount, $discountReason, $currentUser['id']);
        echo json_encode($result);
        exit;
    }

    if ($action === 'process_cash' && $canHandlePayments) {
        $notes = trim($_POST['notes'] ?? '');
        $result = $xrayManager->processCashPayment($requestId, $currentUser['id'], $notes);
        echo json_encode($result);
        exit;
    }

    if ($action === 'process_debt' && $canHandlePayments) {
        $dueDate = $_POST['due_date'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        $result = $xrayManager->processDebtPayment($requestId, $currentUser['id'], $dueDate, $notes);
        echo json_encode($result);
        exit;
    }

    if ($action === 'set_price' && $canHandlePayments) {
        $amount = floatval($_POST['amount'] ?? 0);
        $result = $xrayManager->setPrice($requestId, $amount, $currentUser['id']);
        echo json_encode($result);
        exit;
    }

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

.btn-discount {
    background: #fff3e0;
    color: #ff9800;
}

.btn-discount:hover {
    background: #ffe0b2;
}

.btn-cash {
    background: #e8f5e9;
    color: #2e7d32;
}

.btn-cash:hover {
    background: #c8e6c9;
}

.btn-debt {
    background: #ffebee;
    color: #c62828;
}

.btn-debt:hover {
    background: #ffcdd2;
}

.btn-view {
    background: #e3f2fd;
    color: #1976d2;
}

.btn-view:hover {
    background: #bbdefb;
}

.btn-set-price {
    background: #fff8e1;
    color: #f57c00;
    border: 1px dashed #ffa726;
}

.btn-set-price:hover {
    background: #ffecb3;
}

.no-price-warning {
    background: #fff8e1;
    color: #f57c00;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    display: inline-block;
}

/* Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal-overlay.active {
    display: flex;
}

.modal-box {
    background: white;
    border-radius: 12px;
    width: 100%;
    max-width: 500px;
    margin: 20px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #999;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #555;
    margin-bottom: 6px;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #1a237e;
    box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
}

.amount-summary {
    background: #f5f5f5;
    border-radius: 8px;
    padding: 14px;
    margin-bottom: 16px;
}

.amount-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 14px;
}

.amount-row.total {
    border-top: 2px solid #ddd;
    margin-top: 8px;
    padding-top: 12px;
    font-weight: 700;
    font-size: 16px;
    color: #4caf50;
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
                    <td><?= htmlspecialchars($request['requested_by_name']) ?></td>
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
                            <?php if ($hasNoPrice): ?>
                            <!-- Show Set Price button when no price -->
                            <button type="button" class="action-btn btn-set-price"
                                    onclick="openSetPriceModal(<?= $request['id'] ?>, '<?= htmlspecialchars($request['patient_name']) ?>')">
                                <i class="fas fa-tag"></i> دانانی نرخ
                            </button>
                            <?php else: ?>
                            <!-- Normal payment buttons when price exists -->
                            <button type="button" class="action-btn btn-discount"
                                    onclick="openDiscountModal(<?= $request['id'] ?>, <?= $original ?>, <?= $final ?>)">
                                <i class="fas fa-percent"></i> داشکاندن
                            </button>
                            <button type="button" class="action-btn btn-cash"
                                    onclick="openCashModal(<?= $request['id'] ?>, <?= $final ?>)">
                                <i class="fas fa-money-bill-wave"></i> کاش
                            </button>
                            <button type="button" class="action-btn btn-debt"
                                    onclick="openDebtModal(<?= $request['id'] ?>, <?= $final ?>, '<?= htmlspecialchars($request['patient_name']) ?>')">
                                <i class="fas fa-file-invoice"></i> قەرز
                            </button>
                            <?php endif; ?>
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

<?php if ($canHandlePayments): ?>
<!-- Discount Modal -->
<div class="modal-overlay" id="discountModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-percent"></i> داشکاندن</h3>
            <button class="modal-close" onclick="closeModal('discountModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="discount_request_id">

            <div class="amount-summary">
                <div class="amount-row">
                    <span>نرخی سەرەتایی:</span>
                    <span id="discount_original_amount">0</span>
                </div>
                <div class="amount-row">
                    <span>داشکاندن:</span>
                    <span id="discount_preview">0</span>
                </div>
                <div class="amount-row total">
                    <span>نرخی کۆتایی:</span>
                    <span id="discount_final_preview">0</span>
                </div>
            </div>

            <div class="form-group">
                <label>بڕی داشکاندن</label>
                <input type="number" id="discount_amount" min="0" step="1000" oninput="updateDiscountPreview()">
            </div>

            <div class="form-group">
                <label>هۆکاری داشکاندن</label>
                <textarea id="discount_reason" rows="2" placeholder="بۆ چی داشکاندن دەکرێت..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('discountModal')">پاشگەزبوونەوە</button>
            <button class="btn btn-primary" onclick="applyDiscount()">
                <i class="fas fa-check"></i> جێبەجێکردن
            </button>
        </div>
    </div>
</div>

<!-- Cash Payment Modal -->
<div class="modal-overlay" id="cashModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-money-bill-wave"></i> پارەدانی کاش</h3>
            <button class="modal-close" onclick="closeModal('cashModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="cash_request_id">

            <div class="amount-summary">
                <div class="amount-row total">
                    <span>بڕی پارەدان:</span>
                    <span id="cash_amount">0</span>
                </div>
            </div>

            <div class="form-group">
                <label>تێبینی (ئارەزوومەندانە)</label>
                <textarea id="cash_notes" rows="2" placeholder="تێبینی پارەدان..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('cashModal')">پاشگەزبوونەوە</button>
            <button class="btn btn-success" onclick="processCashPayment()">
                <i class="fas fa-check"></i> پارەی وەرگیرا
            </button>
        </div>
    </div>
</div>

<!-- Debt Modal -->
<div class="modal-overlay" id="debtModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-file-invoice"></i> تۆمارکردن وەک قەرز</h3>
            <button class="modal-close" onclick="closeModal('debtModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="debt_request_id">

            <div class="amount-summary" style="background: #ffebee;">
                <div class="amount-row">
                    <span>نەخۆش:</span>
                    <span id="debt_patient_name">-</span>
                </div>
                <div class="amount-row total" style="color: #c62828;">
                    <span>بڕی قەرز:</span>
                    <span id="debt_amount">0</span>
                </div>
            </div>

            <div class="form-group">
                <label>بەرواری دانەوە (ئارەزوومەندانە)</label>
                <input type="date" id="debt_due_date">
            </div>

            <div class="form-group">
                <label>تێبینی</label>
                <textarea id="debt_notes" rows="2" placeholder="تێبینی قەرز..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('debtModal')">پاشگەزبوونەوە</button>
            <button class="btn btn-danger" onclick="processDebtPayment()">
                <i class="fas fa-check"></i> تۆمارکردن وەک قەرز
            </button>
        </div>
    </div>
</div>

<!-- Set Price Modal -->
<div class="modal-overlay" id="setPriceModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-tag"></i> دانانی نرخ</h3>
            <button class="modal-close" onclick="closeModal('setPriceModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="setprice_request_id">

            <div class="amount-summary" style="background: #fff8e1;">
                <div class="amount-row">
                    <span>نەخۆش:</span>
                    <span id="setprice_patient_name">-</span>
                </div>
                <div class="amount-row" style="color: #f57c00;">
                    <span><i class="fas fa-exclamation-triangle"></i> نرخی ئێستا:</span>
                    <span>0</span>
                </div>
            </div>

            <div class="form-group">
                <label>نرخی ئێکسڕەی</label>
                <input type="number" id="setprice_amount" min="1000" step="1000" placeholder="نرخ بنووسە...">
            </div>

            <p style="color: #666; font-size: 12px; margin-top: 10px;">
                <i class="fas fa-info-circle"></i>
                دکتۆر نرخی نەنووسیوە، تکایە نرخی ڕاست دابنێ بۆ وەرگرتنی پارە.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('setPriceModal')">پاشگەزبوونەوە</button>
            <button class="btn btn-primary" onclick="submitSetPrice()">
                <i class="fas fa-check"></i> دانانی نرخ
            </button>
        </div>
    </div>
</div>

<script>
let currentOriginalAmount = 0;

function openDiscountModal(requestId, original, current) {
    document.getElementById('discount_request_id').value = requestId;
    document.getElementById('discount_original_amount').textContent = numberFormat(original);
    document.getElementById('discount_amount').value = original - current;
    document.getElementById('discount_amount').max = original;
    currentOriginalAmount = original;
    updateDiscountPreview();
    document.getElementById('discountModal').classList.add('active');
}

function updateDiscountPreview() {
    const discount = parseFloat(document.getElementById('discount_amount').value) || 0;
    const final = currentOriginalAmount - discount;
    document.getElementById('discount_preview').textContent = numberFormat(discount);
    document.getElementById('discount_final_preview').textContent = numberFormat(final);
}

function openCashModal(requestId, amount) {
    document.getElementById('cash_request_id').value = requestId;
    document.getElementById('cash_amount').textContent = numberFormat(amount);
    document.getElementById('cash_notes').value = '';
    document.getElementById('cashModal').classList.add('active');
}

function openDebtModal(requestId, amount, patientName) {
    document.getElementById('debt_request_id').value = requestId;
    document.getElementById('debt_amount').textContent = numberFormat(amount);
    document.getElementById('debt_patient_name').textContent = patientName;
    document.getElementById('debt_due_date').value = '';
    document.getElementById('debt_notes').value = '';
    document.getElementById('debtModal').classList.add('active');
}

function openSetPriceModal(requestId, patientName) {
    document.getElementById('setprice_request_id').value = requestId;
    document.getElementById('setprice_patient_name').textContent = patientName;
    document.getElementById('setprice_amount').value = '';
    document.getElementById('setPriceModal').classList.add('active');
}

async function submitSetPrice() {
    const requestId = document.getElementById('setprice_request_id').value;
    const amount = document.getElementById('setprice_amount').value;

    if (!amount || parseFloat(amount) <= 0) {
        alert('تکایە نرخێکی گونجاو بنووسە');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'set_price');
    formData.append('request_id', requestId);
    formData.append('amount', amount);

    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            closeModal('setPriceModal');
            alert('نرخ بە سەرکەوتوویی دانرا!');
            location.reload();
        } else {
            alert(result.message || 'هەڵەیەک ڕوویدا');
        }
    } catch (error) {
        alert('هەڵەیەک ڕوویدا');
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function numberFormat(num) {
    return new Intl.NumberFormat().format(num);
}

async function applyDiscount() {
    const requestId = document.getElementById('discount_request_id').value;
    const discountAmount = document.getElementById('discount_amount').value;
    const discountReason = document.getElementById('discount_reason').value;

    const formData = new FormData();
    formData.append('action', 'apply_discount');
    formData.append('request_id', requestId);
    formData.append('discount_amount', discountAmount);
    formData.append('discount_reason', discountReason);

    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            closeModal('discountModal');
            location.reload();
        } else {
            alert(result.message || 'هەڵەیەک ڕوویدا');
        }
    } catch (error) {
        alert('هەڵەیەک ڕوویدا');
    }
}

async function processCashPayment() {
    const requestId = document.getElementById('cash_request_id').value;
    const notes = document.getElementById('cash_notes').value;

    if (!confirm('دڵنیایت کە پارەی کاش وەرگیراوە؟')) return;

    const formData = new FormData();
    formData.append('action', 'process_cash');
    formData.append('request_id', requestId);
    formData.append('notes', notes);

    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            closeModal('cashModal');
            document.getElementById('request-row-' + requestId).remove();
            // Show success message
            alert('پارەدان سەرکەوتوو بوو!');
            location.reload();
        } else {
            alert(result.message || 'هەڵەیەک ڕوویدا');
        }
    } catch (error) {
        alert('هەڵەیەک ڕوویدا');
    }
}

async function processDebtPayment() {
    const requestId = document.getElementById('debt_request_id').value;
    const dueDate = document.getElementById('debt_due_date').value;
    const notes = document.getElementById('debt_notes').value;

    if (!confirm('دڵنیایت کە دەتەوێت وەک قەرز تۆمار بکەیت؟')) return;

    const formData = new FormData();
    formData.append('action', 'process_debt');
    formData.append('request_id', requestId);
    formData.append('due_date', dueDate);
    formData.append('notes', notes);

    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            closeModal('debtModal');
            document.getElementById('request-row-' + requestId).remove();
            alert('وەک قەرز تۆمارکرا!');
            location.reload();
        } else {
            alert(result.message || 'هەڵەیەک ڕوویدا');
        }
    } catch (error) {
        alert('هەڵەیەک ڕوویدا');
    }
}

// Close modal on outside click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
