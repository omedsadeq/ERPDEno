<?php
/**
 * Payment Management Page (Admin Only)
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/Payment.php';
$auth ??= new Auth();
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.payments')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$paymentManager = new Payment();

// Filters
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $paymentId = intval($_POST['payment_id'] ?? 0);
    $action = $_POST['action'];
    // BUGFIX: $currentUser used to be undefined here (it was only set inside
    // header.php, included AFTER this block) — every approval stored approved_by = NULL.
    $actorId = (int)$auth->getCurrentUserId();

    if ($action === 'approve' && $paymentId) {
        $paymentManager->approve($paymentId, $actorId);
        AuditLog::log('approve_payment', 'payments', $paymentId, null, ['status' => 'approved']);
        header('Location: ' . APP_URL . '/admin/payments.php?status=' . urlencode($status) . '&approved=1');
        exit;
    } elseif ($action === 'reject' && $paymentId) {
        $paymentManager->reject($paymentId, $_POST['reason'] ?? '', $actorId);
        AuditLog::log('reject_payment', 'payments', $paymentId, null,
            ['status' => 'rejected', 'reason' => $_POST['reason'] ?? '']);
        header('Location: ' . APP_URL . '/admin/payments.php?status=' . urlencode($status) . '&rejected=1');
        exit;
    }
}

$pageTitle = 'بەڕێوەبردنی پارەدان';
$breadcrumb = [['title' => 'بەڕێوەبردنی پارەدان']];

require_once __DIR__ . '/../includes/header.php';

// Get payments
$filters = [
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];
$payments = $paymentManager->getAll($page, ITEMS_PER_PAGE, $filters);
$pendingPayments = $paymentManager->getPending();

// Get statistics
$stats = $paymentManager->getStatistics(['date_from' => $dateFrom, 'date_to' => $dateTo]);
?>

<style>
/* Payment Statistics Cards */
.stats-grid-payments {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-bottom: 10px;
}

.stat-card-payment {
    background: linear-gradient(135deg, var(--bg-color), var(--hover-color));
    border-radius: 6px;
    padding: 8px 10px;
    text-align: center;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-payment::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1.5px;
    background: var(--accent-color);
}

.stat-card-payment:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: var(--accent-color);
}

.stat-card-payment.stat-revenue {
    --bg-color: #e8f5e9;
    --hover-color: #c8e6c9;
    --border-color: #a5d6a7;
    --accent-color: #4caf50;
}

.stat-card-payment.stat-count {
    --bg-color: #e3f2fd;
    --hover-color: #bbdefb;
    --border-color: #90caf9;
    --accent-color: #2196f3;
}

.stat-card-payment.stat-pending {
    --bg-color: #fff3e0;
    --hover-color: #ffe0b2;
    --border-color: #ffcc80;
    --accent-color: #ff9800;
}

.stat-icon-payment {
    width: 28px;
    height: 28px;
    border-radius: 5px;
    background: var(--accent-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    margin: 0 auto 4px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.stat-value-payment {
    font-size: 16px;
    font-weight: 800;
    color: var(--accent-color);
    line-height: 1.1;
    margin-bottom: 1px;
}

.stat-label-payment {
    font-size: 9px;
    color: #546e7a;
    font-weight: 600;
    line-height: 1.2;
}

/* Filters Panel */
.filters-panel-payments {
    background: linear-gradient(to bottom, #ffffff, #fafafa);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e0e0e0;
}

.filters-grid-payments {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    align-items: end;
}

.filter-group-payments {
    position: relative;
}

.filter-label-payments {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #37474f;
}

.filter-label-payments i {
    color: #0078d4;
    font-size: 12px;
}

.filter-control-payments {
    width: 100%;
    padding: 8px 12px;
    font-size: 13px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    transition: all 0.2s ease;
    font-family: inherit;
}

.filter-control-payments:hover {
    border-color: #bdbdbd;
}

.filter-control-payments:focus {
    border-color: #0078d4;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}

/* Payments Table */
.payments-table {
    width: 100%;
    border-collapse: collapse;
}

.payments-table thead {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    border-bottom: 2px solid #e0e0e0;
}

.payments-table th {
    padding: 10px 8px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}

.payments-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.payments-table tbody tr:hover {
    background: linear-gradient(to left, #f8f9fa, #ffffff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.payments-table td {
    padding: 10px 8px;
    font-size: 12px;
    color: #2c3e50;
    text-align: center;
}

.payment-number-cell {
    font-family: var(--app-font-family);
    font-weight: 700;
    color: #0078d4;
    font-size: 13px;
}

.patient-link-payment {
    font-weight: 600;
    color: #2c3e50;
    text-decoration: none;
}

.patient-link-payment:hover {
    color: #0078d4;
}

.amount-cell-payment {
    font-weight: 700;
    color: #4caf50;
    font-size: 13px;
}

@media (max-width: 1200px) {
    .stats-grid-payments {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid-payments {
        grid-template-columns: 1fr;
    }
    .filters-grid-payments {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if (isset($_GET['approved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> پارەدان پەسەندکرا</div>
<?php endif; ?>
<?php if (isset($_GET['rejected'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> پارەدان ڕەتکرایەوە</div>
<?php endif; ?>

<!-- Pending Payments Alert -->
<?php if (!empty($pendingPayments)): ?>
<div class="panel mb-3">
    <div class="panel-header">
        <h3 class="panel-title text-warning">
            <i class="fas fa-clock"></i>
            داواکارییەکانی پارەدان
            <span class="badge badge-orange"><?= count($pendingPayments) ?></span>
        </h3>
    </div>
    <div class="panel-body">
        <div class="table-container">
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>ژمارە</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>بڕ</th>
                        <th>جۆر</th>
                        <th>بەروار</th>
                        <th>کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingPayments as $pay): ?>
                    <tr>
                        <td><span class="payment-number-cell"><?= $pay['payment_number'] ?></span></td>
                        <td><?= htmlspecialchars($pay['patient_name']) ?></td>
                        <td>د. <?= htmlspecialchars($pay['doctor_name']) ?></td>
                        <td><span class="amount-cell-payment"><?= number_format($pay['amount']) ?></span></td>
                        <td>
                            <?php
                            $types = ['cash' => 'نەقد', 'credit' => 'قەرز', 'installment' => 'قیست'];
                            echo $types[$pay['payment_type']] ?? $pay['payment_type'];
                            ?>
                        </td>
                        <td><?= date('Y-m-d H:i', strtotime($pay['payment_date'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="payment_id" value="<?= $pay['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="پەسەندکردن">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <button class="btn btn-sm btn-danger" onclick="rejectPayment(<?= $pay['id'] ?>)" title="ڕەتکردنەوە">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters Panel -->
<div class="filters-panel-payments">
    <form method="GET">
        <div class="filters-grid-payments">
            <div class="filter-group-payments">
                <label class="filter-label-payments">
                    <i class="fas fa-toggle-on"></i>
                    دۆخ
                </label>
                <select name="status" class="filter-control-payments">
                    <option value="">هەموو دۆخەکان</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>چاوەڕوان</option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>پەسەندکراو</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>ڕەتکراو</option>
                </select>
            </div>
            <div class="filter-group-payments">
                <label class="filter-label-payments">
                    <i class="fas fa-calendar-day"></i>
                    لە بەروار
                </label>
                <input type="date" name="date_from" class="filter-control-payments" value="<?= $dateFrom ?>">
            </div>
            <div class="filter-group-payments">
                <label class="filter-label-payments">
                    <i class="fas fa-calendar-check"></i>
                    بۆ بەروار
                </label>
                <input type="date" name="date_to" class="filter-control-payments" value="<?= $dateTo ?>">
            </div>
            <div class="filter-group-payments">
                <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-size: 13px; border-radius: 8px; font-weight: 600; margin-top: 25px;">
                    <i class="fas fa-filter"></i> پاڵاوتن
                </button>
            </div>
        </div>
    </form>
</div>

<!-- All Payments -->
<div class="panel">
    <div class="panel-header">
        <h3 class="panel-title">
            <i class="fas fa-money-bill-wave"></i>
            هەموو پارەدانەکان
        </h3>
    </div>

    <div class="panel-body">
        <?php if (empty($payments)): ?>
        <div class="empty-state">
            <i class="fas fa-money-bill empty-state-icon"></i>
            <p class="empty-state-title">هیچ پارەدانێک نییە</p>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>ژمارە</th>
                        <th>نەخۆش</th>
                        <th>کەیس</th>
                        <th>بڕ</th>
                        <th>جۆر</th>
                        <th>شێواز</th>
                        <th>دۆخ</th>
                        <th>بەروار</th>
                        <th>کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td><span class="payment-number-cell"><?= $pay['payment_number'] ?></span></td>
                        <td>
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= $pay['patient_id'] ?? '' ?>" class="patient-link-payment">
                                <?= htmlspecialchars($pay['patient_name'] ?? '') ?>
                            </a>
                        </td>
                        <td><?= $pay['case_number'] ?></td>
                        <td><span class="amount-cell-payment"><?= number_format($pay['amount']) ?></span></td>
                        <td>
                            <?php
                            $types = ['cash' => 'نەقد', 'credit' => 'قەرز', 'installment' => 'قیست'];
                            echo $types[$pay['payment_type']] ?? $pay['payment_type'];
                            ?>
                        </td>
                        <td><?= $pay['payment_method'] === 'cash' ? 'نەقد' : 'کارت' ?></td>
                        <td>
                            <?php
                            $statuses = [
                                'pending' => ['badge-orange', 'چاوەڕوان'],
                                'approved' => ['badge-green', 'پەسەندکراو'],
                                'rejected' => ['badge-red', 'ڕەتکراو'],
                                'refunded' => ['', 'گەڕاوەتەوە']
                            ];
                            $st = $statuses[$pay['status']] ?? ['', $pay['status']];
                            ?>
                            <span class="badge <?= $st[0] ?>"><?= $st[1] ?></span>
                        </td>
                        <td><?= date('Y-m-d H:i', strtotime($pay['payment_date'])) ?></td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick="viewPayment(<?= $pay['id'] ?>)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#1a1a2e;font-weight:800;">
                        <td colspan="3" style="padding:12px 14px;color:#fff;">
                            <i class="fas fa-money-bill-wave"></i>
                            پارەی هاتوو — <?= (int)$stats['count'] ?> پارەدان
                            <?php if (count($pendingPayments) > 0): ?>
                            <span style="color:#f39c12;font-weight:700;margin-right:10px;">
                                <i class="fas fa-clock"></i> <?= count($pendingPayments) ?> چاوەڕوانی پەسەند
                            </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px 14px;direction:ltr;text-align:right;color:#2ecc71;font-size:15px;"><?= number_format($stats['total']) ?></td>
                        <td colspan="5"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reject Dialog -->
<div class="dialog-overlay" id="rejectDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h4 class="dialog-title">ڕەتکردنەوەی پارەدان</h4>
            <button class="dialog-close" onclick="closeDialog('rejectDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="payment_id" id="reject_payment_id">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label">هۆکاری ڕەتکردنەوە</label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="هۆکار بنووسە..."></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('rejectDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> ڕەتکردنەوە</button>
            </div>
        </form>
    </div>
</div>

<script>
function rejectPayment(id) {
    document.getElementById('reject_payment_id').value = id;
    openDialog('rejectDialog');
}

function viewPayment(id) {
    // Could implement payment details view
    alert('بینینی وردەکاری پارەدان: ' + id);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
