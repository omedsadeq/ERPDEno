<?php
/**
 * Installments Management Page - Modern Enhanced Version
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'بەڕێوەبردنی قیستەکان';
$breadcrumb = [['title' => 'قیستەکان']];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/classes/Installment.php';

$installmentManager = new Installment();
$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();

// Doctor filter: Doctors only see their own installments, Admins see all
$doctorFilter = null;
if (!$isAdmin && $isDoctor) {
    $doctorFilter = $currentUser['id'];
}

// Filters
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'pay_installment' && $isAdmin) {
        $scheduleId = intval($_POST['schedule_id']);
        $amount = floatval($_POST['amount']);
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');

        $result = $installmentManager->payInstallment($scheduleId, $amount, $currentUser['id']);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/installments.php?paid=1');
            exit;
        }
    }
}

// Get statistics
$stats = $installmentManager->getStatistics();

// Get installments based on filter (pass doctor filter for non-admins)
if ($status === 'overdue') {
    $installments = $installmentManager->getOverdue($doctorFilter);
} elseif ($status === 'upcoming') {
    $installments = $installmentManager->getUpcoming(30, $doctorFilter);
} elseif ($status === 'this_week') {
    $installments = $installmentManager->getUpcoming(7, $doctorFilter);
} else {
    $installments = $installmentManager->getActivePlans($page, ITEMS_PER_PAGE, $doctorFilter);
}

// Apply search filter
if (!empty($search)) {
    $installments = array_filter($installments, function($item) use ($search) {
        return stripos($item['patient_name'], $search) !== false ||
               stripos($item['patient_phone'], $search) !== false ||
               stripos($item['case_number'], $search) !== false;
    });
}

// Get overdue count (also filtered by doctor for non-admins)
$overdueCount = count($installmentManager->getOverdue($doctorFilter));
$upcomingWeekCount = count($installmentManager->getUpcoming(7, $doctorFilter));
?>

<style>
/* Installments Statistics Cards */
.stats-grid-installments {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 6px;
    margin-bottom: 10px;
}

.stat-card-installment {
    background: linear-gradient(135deg, var(--bg-color), var(--hover-color));
    border-radius: 6px;
    padding: 8px 10px;
    text-align: center;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-installment::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1.5px;
    background: var(--accent-color);
}

.stat-card-installment:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: var(--accent-color);
}

.stat-card-installment.stat-blue {
    --bg-color: #f0f7ff;
    --hover-color: #e3f2fd;
    --border-color: #bbdefb;
    --accent-color: #2196f3;
}

.stat-card-installment.stat-orange {
    --bg-color: #fff8f0;
    --hover-color: #fff3e0;
    --border-color: #ffe0b2;
    --accent-color: #ff9800;
}

.stat-card-installment.stat-red {
    --bg-color: #fff5f5;
    --hover-color: #ffebee;
    --border-color: #ffcdd2;
    --accent-color: #f44336;
}

.stat-card-installment.stat-green {
    --bg-color: #f1f8f4;
    --hover-color: #e8f5e9;
    --border-color: #c8e6c9;
    --accent-color: #4caf50;
}

.stat-card-installment.stat-purple {
    --bg-color: #faf5fc;
    --hover-color: #f3e5f5;
    --border-color: #e1bee7;
    --accent-color: #9c27b0;
}

.stat-icon-installment {
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

.stat-value-installment {
    font-size: 16px;
    font-weight: 800;
    color: var(--accent-color);
    line-height: 1.1;
    margin-bottom: 1px;
}

.stat-label-installment {
    font-size: 11px;
    color: #546e7a;
    font-weight: 600;
    line-height: 1.2;
}

/* Filters Panel */
.filters-panel-installments {
    background: linear-gradient(to bottom, #ffffff, #fafafa);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e0e0e0;
}

.filter-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 16px;
    background: #f5f5f5;
    color: #37474f;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border: 1.5px solid transparent;
    transition: all 0.2s ease;
}

.filter-chip:hover {
    background: #e8e8e8;
    border-color: #bdbdbd;
}

.filter-chip.active {
    background: var(--win-accent, #0078d4);
    color: white;
    border-color: var(--win-accent, #0078d4);
}

.chip-badge {
    background: rgba(255, 255, 255, 0.3);
    padding: 1px 6px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
}

.filter-chip:not(.active) .chip-badge {
    background: #e0e0e0;
    color: #37474f;
}

.filters-grid-installments {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 12px;
    align-items: end;
}

.filter-group-installments {
    position: relative;
}

.filter-label-installments {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #37474f;
}

.filter-label-installments i {
    color: var(--win-accent, #0078d4);
    font-size: 12px;
}

.filter-control-installments {
    width: 100%;
    padding: 8px 12px;
    font-size: 13px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    transition: all 0.2s ease;
    font-family: inherit;
}

.filter-control-installments:hover {
    border-color: #bdbdbd;
}

.filter-control-installments:focus {
    border-color: var(--win-accent, #0078d4);
    box-shadow: 0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1);
    outline: none;
}

/* Installments Table */
.installments-table {
    width: 100%;
    border-collapse: collapse;
}

.installments-table thead {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    border-bottom: 2px solid #e0e0e0;
}

.installments-table th {
    padding: 10px 8px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}

.installments-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.installments-table tbody tr:hover {
    background: linear-gradient(to left, #f8f9fa, #ffffff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.installments-table td {
    padding: 10px 8px;
    font-size: 12px;
    color: #2c3e50;
    text-align: center;
}

.patient-name-cell {
    font-weight: 700;
    color: var(--win-accent, #0078d4);
    text-decoration: none;
    font-size: 13px;
}

.patient-name-cell:hover {
    text-decoration: underline;
}

.case-link-cell {
    font-family: var(--app-font-family);
    color: #546e7a;
    text-decoration: none;
    font-weight: 600;
}

.case-link-cell:hover {
    color: var(--win-accent, #0078d4);
}

.amount-cell-installment {
    font-weight: 700;
    font-size: 13px;
}

.amount-cell-installment.total {
    color: var(--win-accent, #0078d4);
}

.amount-cell-installment.paid {
    color: #4caf50;
}

.amount-cell-installment.remaining {
    color: #f44336;
}

.progress-cell {
    padding: 4px 8px !important;
}

.progress-mini {
    height: 4px;
    background: #e0e0e0;
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 2px;
}

.progress-mini-fill {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #66bb6a);
    border-radius: 2px;
}

.progress-text {
    font-size: 10px;
    color: #757575;
    font-weight: 600;
}

.empty-state-installments {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 14px;
    border: 2px dashed #e0e0e0;
}

.empty-state-installments i {
    font-size: 64px;
    color: #e0e0e0;
    margin-bottom: 16px;
}

.empty-state-installments h3 {
    font-size: 18px;
    color: #757575;
    margin-bottom: 8px;
}

.empty-state-installments p {
    font-size: 14px;
    color: #9e9e9e;
}

@media (max-width: 1200px) {
    .stats-grid-installments {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid-installments {
        grid-template-columns: repeat(2, 1fr);
    }
    .filters-grid-installments {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if (isset($_GET['paid'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> پارەدان بە سەرکەوتوویی تۆمارکرا!</div>
<?php endif; ?>

<?php
// Totals row shown under both tables (replaces the old top statistics cards)
$installmentTotalsRow = '
    <tr style="background:#1a1a2e;font-weight:700;">
        <td colspan="%d" style="padding:12px 14px;font-size:13px;color:#fff;">
            <i class="fas fa-file-invoice-dollar"></i>
            پلانی چالاک: <strong style="color:#fff;">' . number_format((int)$stats['active_plans']) . '</strong>
            <span style="margin:0 8px;color:#8a8fa3;">|</span>
            کۆی ماوە: <strong style="color:#f39c12;">' . number_format($stats['total_remaining']) . '</strong>
            <span style="margin:0 8px;color:#8a8fa3;">|</span>
            دواکەوتوو: <strong style="color:#ff6b6b;">' . number_format($stats['overdue_amount']) . '</strong>
            <span style="margin:0 8px;color:#8a8fa3;">|</span>
            ئەم مانگە: <strong style="color:#2ecc71;">' . number_format($stats['due_this_month']) . '</strong>
            <span style="margin:0 8px;color:#8a8fa3;">|</span>
            ئەم هەفتەیە: <strong style="color:#fff;">' . number_format((int)$upcomingWeekCount) . '</strong>
        </td>
    </tr>';
?>

<!-- Filters Panel -->
<div class="filters-panel-installments">
    <div class="filter-chips">
        <a href="?" class="filter-chip <?= empty($status) ? 'active' : '' ?>">
            <i class="fas fa-list"></i>
            هەموو پلانەکان
        </a>
        <a href="?status=this_week" class="filter-chip <?= $status === 'this_week' ? 'active' : '' ?>">
            <i class="fas fa-calendar-week"></i>
            ئەم هەفتەیە
            <?php if ($upcomingWeekCount > 0): ?>
            <span class="chip-badge"><?= $upcomingWeekCount ?></span>
            <?php endif; ?>
        </a>
        <a href="?status=upcoming" class="filter-chip <?= $status === 'upcoming' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i>
            داهاتوو (٣٠ ڕۆژ)
        </a>
        <a href="?status=overdue" class="filter-chip <?= $status === 'overdue' ? 'active' : '' ?>">
            <i class="fas fa-exclamation-circle"></i>
            دواکەوتوو
            <?php if ($overdueCount > 0): ?>
            <span class="chip-badge"><?= $overdueCount ?></span>
            <?php endif; ?>
        </a>
    </div>

    <form method="GET" action="">
        <div class="filters-grid-installments">
            <div class="filter-group-installments">
                <label class="filter-label-installments">
                    <i class="fas fa-search"></i>
                    گەڕان
                </label>
                <input type="text"
                       name="search"
                       class="filter-control-installments"
                       placeholder="گەڕان بە ناو، ژمارە مۆبایل، کەیس..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-size: 13px; border-radius: 8px; font-weight: 600;">
                <i class="fas fa-search"></i> گەڕان
            </button>
        </div>
    </form>
</div>

<!-- Installments List -->
<?php if (empty($installments)): ?>
    <div class="empty-state-installments">
        <i class="fas fa-inbox"></i>
        <h3>هیچ قیستێک نەدۆزرایەوە</h3>
        <p>هیچ قیستێک بۆ ئەم فلتەرە نییە</p>
    </div>
<?php elseif ($status === 'overdue' || $status === 'upcoming' || $status === 'this_week'): ?>
    <!-- Individual Installments Table -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title">
                <i class="fas fa-calendar-alt"></i>
                <?php if ($status === 'overdue'): ?>قیستەکانی دواکەوتوو
                <?php elseif ($status === 'this_week'): ?>قیستەکانی ئەم هەفتەیە
                <?php else: ?>قیستەکانی داهاتوو<?php endif; ?>
            </h3>
        </div>
        <div class="panel-body">
            <div class="table-container">
                <table class="installments-table">
                    <thead>
                        <tr>
                            <th>نەخۆش</th>
                            <th>دکتۆر</th>
                            <th>کەیس</th>
                            <th>قیست #</th>
                            <th>بڕ</th>
                            <th>دراوە</th>
                            <th>ماوە</th>
                            <th>بەرواری دوا</th>
                            <th>دۆخ</th>
                            <th>کردار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($installments as $inst):
                            $remaining = $inst['amount'] - $inst['paid_amount'];
                            $dueDate = strtotime($inst['due_date']);
                            $today = strtotime(date('Y-m-d'));
                            $isOverdue = $dueDate < $today;
                            $daysUntil = ceil(($dueDate - $today) / 86400);
                        ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $inst['patient_id'] ?? '' ?>" class="patient-name-cell">
                                    <?= htmlspecialchars($inst['patient_name']) ?>
                                </a>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: #546e7a;">
                                    <?php if (!empty($inst['doctor_name'])): ?>
                                        <i class="fas fa-user-md" style="font-size: 10px; margin-left: 4px;"></i>
                                        <?= htmlspecialchars($inst['doctor_name']) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/cases/view.php?id=<?= $inst['case_id'] ?? '' ?>" class="case-link-cell">
                                    <?= htmlspecialchars($inst['case_number']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge badge-blue">#<?= $inst['installment_number'] ?></span>
                            </td>
                            <td>
                                <span class="amount-cell-installment total"><?= number_format($inst['amount']) ?></span>
                            </td>
                            <td>
                                <span class="amount-cell-installment paid"><?= number_format($inst['paid_amount']) ?></span>
                            </td>
                            <td>
                                <span class="amount-cell-installment remaining"><?= number_format($remaining) ?></span>
                            </td>
                            <td>
                                <span style="color: <?= $isOverdue ? '#f44336' : '#757575' ?>; font-weight: 600;">
                                    <?= date('Y-m-d', $dueDate) ?>
                                    <?php if ($daysUntil > 0): ?>
                                        <small style="display: block; font-size: 10px; color: #4caf50;"><?= $daysUntil ?> ڕۆژ ماوە</small>
                                    <?php elseif ($daysUntil < 0): ?>
                                        <small style="display: block; font-size: 10px; color: #f44336;"><?= abs($daysUntil) ?> ڕۆژ دواکەوتوو</small>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $isOverdue ? 'badge-red' : 'badge-green' ?>">
                                    <?= $isOverdue ? 'دواکەوتوو' : 'چاوەڕوان' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; justify-content: center;">
                                    <?php if ($isAdmin && $remaining > 0): ?>
                                    <button class="btn btn-sm btn-success" onclick="payInstallment(<?= $inst['id'] ?>, <?= $remaining ?>, '<?= htmlspecialchars($inst['patient_name']) ?>', <?= $inst['installment_number'] ?>)" title="دانی پارە">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-info" onclick="viewPlanDetails(<?= $inst['plan_id'] ?? 0 ?>)" title="وردەکاری پلان">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><?= sprintf($installmentTotalsRow, 10) ?></tfoot>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Plans Table -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title">
                <i class="fas fa-file-invoice-dollar"></i>
                پلانەکانی قیستبەندی
            </h3>
        </div>
        <div class="panel-body">
            <div class="table-container">
                <table class="installments-table">
                    <thead>
                        <tr>
                            <th>نەخۆش</th>
                            <th>دکتۆر</th>
                            <th>کەیس</th>
                            <th>کۆی گشتی</th>
                            <th>پێشەکی</th>
                            <th>ژمارەی قیست</th>
                            <th>کۆی دراو</th>
                            <th>ماوە</th>
                            <th>پێشکەوتن</th>
                            <th>دۆخ</th>
                            <th>کردار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($installments as $plan):
                            $paidCount = $plan['paid_count'] ?? 0;
                            // total_paid from getActivePlans = installment_schedule paid only (excludes down payment)
                            $schedulePaid = (float)($plan['total_paid'] ?? 0);
                            $downPayment  = (float)($plan['down_payment'] ?? 0);
                            $totalPaid    = $downPayment + $schedulePaid;                 // actual money collected
                            $remaining    = max(0, (float)$plan['total_amount'] - $totalPaid);
                            $progress     = $plan['total_amount'] > 0 ? min(100, ($totalPaid / $plan['total_amount']) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $plan['patient_id'] ?? '' ?>" class="patient-name-cell">
                                    <?= htmlspecialchars($plan['patient_name']) ?>
                                </a>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: #546e7a;">
                                    <?php if (!empty($plan['doctor_name'])): ?>
                                        <i class="fas fa-user-md" style="font-size: 10px; margin-left: 4px;"></i>
                                        <?= htmlspecialchars($plan['doctor_name']) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/cases/view.php?id=<?= $plan['case_id'] ?>" class="case-link-cell">
                                    <?= htmlspecialchars($plan['case_number']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="amount-cell-installment total"><?= number_format($plan['total_amount']) ?></span>
                            </td>
                            <td>
                                <span class="amount-cell-installment"><?= number_format($plan['down_payment']) ?></span>
                            </td>
                            <td>
                                <span style="font-weight: 600;"><?= $plan['number_of_installments'] ?> مانگ</span>
                            </td>
                            <td>
                                <span class="amount-cell-installment paid"><?= number_format($totalPaid) ?></span>
                            </td>
                            <td>
                                <span class="amount-cell-installment remaining"><?= number_format(max(0, $remaining)) ?></span>
                            </td>
                            <td class="progress-cell">
                                <div class="progress-mini">
                                    <div class="progress-mini-fill" style="width: <?= min(100, $progress) ?>%"></div>
                                </div>
                                <div class="progress-text"><?= round($progress) ?>% (<?= $paidCount ?>/<?= $plan['number_of_installments'] ?>)</div>
                            </td>
                            <td>
                                <span class="badge <?= $plan['status'] === 'active' ? 'badge-green' : 'badge-blue' ?>">
                                    <?= $plan['status'] === 'active' ? 'چالاک' : 'تەواوبوو' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; justify-content: center;">
                                    <a href="<?= APP_URL ?>/cases/view.php?id=<?= $plan['case_id'] ?>" class="btn btn-sm btn-primary" title="بینینی کەیس">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button class="btn btn-sm btn-info" onclick="viewPlanDetails(<?= $plan['id'] ?>)" title="وردەکاری قیستەکان">
                                        <i class="fas fa-list-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><?= sprintf($installmentTotalsRow, 11) ?></tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Enhanced Pay Dialog -->
<div class="dialog-overlay" id="payDialog">
    <div class="dialog dialog-modern">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-money-bill-wave"></i> دانی قیست</h4>
            <button class="dialog-close" onclick="closeDialog('payDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="pay_installment">
            <input type="hidden" name="schedule_id" id="pay_schedule_id">
            <div class="dialog-body">
                <div class="alert alert-info" style="margin-bottom: 16px;">
                    <strong id="pay_patient_name"></strong> - قیست #<span id="pay_installment_number"></span>
                </div>

                <div class="form-row-modern">
                    <div class="form-group">
                        <label class="form-label required">بڕی پارەدان</label>
                        <input type="number" name="amount" id="pay_amount" class="form-control" required min="1" step="0.01">
                        <small class="text-secondary">ماوە: <strong><span id="pay_remaining"></span></strong></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">شێوازی پارەدان</label>
                        <select name="payment_method" class="form-control">
                            <option value="cash">نەقد</option>
                            <option value="card">کارت</option>
                            <option value="transfer">گواستنەوە</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">تێبینی</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="تێبینی سەبارەت بە پارەدانەکە..."></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('payDialog')">
                    <i class="fas fa-times"></i> هەڵوەشاندنەوە
                </button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> دانی پارە
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Plan Details Dialog -->
<div class="dialog-overlay" id="planDetailsDialog">
    <div class="dialog" style="max-width: 800px;">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-list-alt"></i> وردەکاری پلانی قیست</h4>
            <button class="dialog-close" onclick="closeDialog('planDetailsDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body" id="planDetailsContent">
            <div style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--text-tertiary);"></i>
            </div>
        </div>
    </div>
</div>

<script>
function payInstallment(id, remaining, patientName, installmentNumber) {
    document.getElementById('pay_schedule_id').value = id;
    document.getElementById('pay_amount').value = remaining;
    document.getElementById('pay_amount').max = remaining;
    document.getElementById('pay_remaining').textContent = remaining.toLocaleString() + '';
    document.getElementById('pay_patient_name').textContent = patientName;
    document.getElementById('pay_installment_number').textContent = installmentNumber;
    openDialog('payDialog');
}

function viewPlanDetails(planId) {
    openDialog('planDetailsDialog');

    // Fetch plan details via AJAX
    fetch('<?= APP_URL ?>/api/installment-details.php?plan_id=' + planId, {
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayPlanDetails(data);
            } else {
                document.getElementById('planDetailsContent').innerHTML =
                    '<div class="alert alert-error">هەڵەیەک ڕوویدا: ' + (data.message || '') + '</div>';
            }
        })
        .catch(error => {
            console.error('API Error:', error);
            document.getElementById('planDetailsContent').innerHTML =
                '<div class="alert alert-error">هەڵەیەک ڕوویدا لە بارکردنی زانیاریەکان</div>';
        });
}

function displayPlanDetails(data) {
    const content = document.getElementById('planDetailsContent');
    const progress = data.total_amount > 0 ? ((data.total_paid / data.total_amount) * 100).toFixed(1) : 0;

    let html = `
        <!-- Patient Info Header -->
        <div style="background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%); color: white; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px;">
                    ${data.patient_name.charAt(0)}
                </div>
                <div>
                    <h3 style="margin: 0 0 2px; font-size: 17px; font-weight: 600;">${data.patient_name}</h3>
                    <p style="margin: 0; opacity: 0.9; font-size: 13px;">${data.case_number}</p>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px;">
            <div style="background: var(--surface-card); border: 1px solid var(--stroke-subtle); border-radius: 6px; padding: 10px; text-align: center;">
                <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px; font-weight: 500;">کۆی گشتی</div>
                <div style="font-size: 20px; font-weight: 700; color: var(--text-primary); font-family: var(--app-font-family);">${formatNumber(data.total_amount)}</div>
                <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;"></div>
            </div>
            <div style="background: var(--surface-card); border: 1px solid #107c10; border-radius: 6px; padding: 10px; text-align: center;">
                <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px; font-weight: 500;">دراوە</div>
                <div style="font-size: 20px; font-weight: 700; color: #107c10; font-family: var(--app-font-family);">${formatNumber(data.total_paid)}</div>
                <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;"></div>
            </div>
            <div style="background: var(--surface-card); border: 1px solid #d13438; border-radius: 6px; padding: 10px; text-align: center;">
                <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px; font-weight: 500;">ماوە</div>
                <div style="font-size: 20px; font-weight: 700; color: #d13438; font-family: var(--app-font-family);">${formatNumber(data.remaining)}</div>
                <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;"></div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div style="background: var(--surface-card); border: 1px solid var(--stroke-subtle); border-radius: 6px; padding: 10px; margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <span style="font-size: 13px; font-weight: 600; color: var(--text-primary);">پێشکەوتن</span>
                <span style="font-size: 15px; font-weight: 700; color: #107c10; font-family: var(--app-font-family); direction: ltr;">${String(progress).replace(/[٠-٩]/g, d => '0123456789'['٠١٢٣٤٥٦٧٨٩'.indexOf(d)])}%</span>
            </div>
            <div style="height: 8px; background: var(--fill-subtle); border-radius: 4px; overflow: hidden;">
                <div style="height: 100%; width: ${progress}%; background: linear-gradient(90deg, #107c10, #10893e); border-radius: 4px; transition: width 0.3s ease;"></div>
            </div>
        </div>

        <!-- Schedule Header -->
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
            <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: var(--text-primary);">
                <i class="fas fa-calendar-alt" style="font-size: 13px; margin-left: 4px; color: var(--text-secondary);"></i>
                هەموو قیستەکان
            </h4>
            <span style="font-size: 12px; color: var(--text-secondary); font-family: var(--app-font-family);">${String(data.number_of_installments).replace(/[٠-٩]/g, d => '0123456789'['٠١٢٣٤٥٦٧٨٩'.indexOf(d)])} قیست</span>
        </div>

        <!-- Compact Schedule Table -->
        <div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--stroke-subtle); border-radius: 6px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead style="position: sticky; top: 0; background: var(--fill-subtle); z-index: 1;">
                    <tr>
                        <th style="padding: 8px 10px; text-align: center; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--stroke-subtle); font-size: 13px;">#</th>
                        <th style="padding: 8px 10px; text-align: right; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--stroke-subtle); font-size: 13px;">بەروار</th>
                        <th style="padding: 8px 10px; text-align: right; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--stroke-subtle); font-size: 13px;">بڕ</th>
                        <th style="padding: 8px 10px; text-align: right; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--stroke-subtle); font-size: 13px;">دراو</th>
                        <th style="padding: 8px 10px; text-align: right; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--stroke-subtle); font-size: 13px;">ماوە</th>
                        <th style="padding: 8px 10px; text-align: center; font-weight: 600; color: var(--text-primary); border-bottom: 1px solid var(--stroke-subtle); font-size: 13px;">دۆخ</th>
                    </tr>
                </thead>
                <tbody>
    `;

    data.schedule.forEach((sch, index) => {
        const remaining = sch.amount - sch.paid_amount;
        const statusConfig = {
            'pending': { label: 'چاوەڕوان', color: '#ff8c00', bg: 'rgba(255, 140, 0, 0.1)' },
            'paid': { label: 'دراوە', color: '#107c10', bg: 'rgba(16, 124, 16, 0.1)' },
            'partial': { label: 'بەشێک', color: '#0078d4', bg: 'rgba(0, 120, 212, 0.1)' },
            'overdue': { label: 'دواکەوتوو', color: '#d13438', bg: 'rgba(209, 52, 56, 0.1)' }
        };
        const status = statusConfig[sch.status] || { label: sch.status, color: '#666', bg: '#f0f0f0' };
        const rowBg = index % 2 === 0 ? 'var(--surface-card)' : 'var(--fill-subtle)';

        html += `
            <tr style="background: ${rowBg}; border-bottom: 1px solid var(--stroke-subtle);">
                <td style="padding: 8px 10px; text-align: center; font-weight: 700; color: var(--text-primary); font-family: var(--app-font-family);">${String(sch.installment_number).replace(/[٠-٩]/g, d => '0123456789'['٠١٢٣٤٥٦٧٨٩'.indexOf(d)])}</td>
                <td style="padding: 8px 10px; text-align: right; color: var(--text-secondary); direction: ltr; font-family: var(--app-font-family);">${sch.due_date}</td>
                <td style="padding: 8px 10px; text-align: right; font-weight: 600; color: var(--text-primary); font-family: var(--app-font-family);">${formatNumber(sch.amount)}</td>
                <td style="padding: 8px 10px; text-align: right; font-weight: 700; color: #107c10; font-family: var(--app-font-family);">${formatNumber(sch.paid_amount)}</td>
                <td style="padding: 8px 10px; text-align: right; font-weight: 700; color: #d13438; font-family: var(--app-font-family);">${formatNumber(remaining)}</td>
                <td style="padding: 8px 10px; text-align: center;">
                    <span style="display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; color: ${status.color}; background: ${status.bg};">
                        ${status.label}
                    </span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    content.innerHTML = html;
}

function formatNumber(num) {
    return parseFloat(num).toLocaleString('en-US');
}

// Advanced Live Search Implementation
(function() {
    const searchInput = document.querySelector('input[name="search"]');
    if (!searchInput) return;

    let searchTimeout;
    let searchCache = {};
    const CACHE_DURATION = 60000; // 60 seconds
    const DEBOUNCE_DELAY = 300; // 300ms

    // Get current filter status from URL
    function getCurrentStatus() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('status') || '';
    }

    // Perform search
    function performSearch(searchTerm) {
        const status = getCurrentStatus();
        const page = 1; // Reset to page 1 on search
        const cacheKey = `${status}_${searchTerm}_${page}`;

        // Check cache first
        if (searchCache[cacheKey] && Date.now() - searchCache[cacheKey].timestamp < CACHE_DURATION) {
            updateUI(searchCache[cacheKey].data);
            return;
        }

        // Show loading state
        showLoadingState();

        // Make AJAX request
        const formData = new FormData();
        formData.append('page', page);
        formData.append('status', status);
        formData.append('search', searchTerm);

        fetch('<?= APP_URL ?>/ajax_installments_search.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Cache the results
                searchCache[cacheKey] = {
                    data: data,
                    timestamp: Date.now()
                };
                updateUI(data);
            } else {
                showError(data.error || 'هەڵەیەک ڕوویدا');
            }
        })
        .catch(error => {
            console.error('Search Error:', error);
            showError('هەڵەیەک ڕوویدا لە گەڕان');
        });
    }

    // Update UI with search results
    function updateUI(data) {
        // Update table body
        const tableBody = document.querySelector('.installments-table tbody');
        if (tableBody) {
            tableBody.innerHTML = data.html;
        } else {
            // Handle empty state - replace entire panel/empty-state
            const panel = document.querySelector('.panel, .empty-state-installments');
            if (panel) {
                if (data.html.includes('empty-state-installments')) {
                    panel.outerHTML = data.html;
                } else {
                    // Rebuild the panel structure
                    const status = getCurrentStatus();
                    let title = 'پلانەکانی قیستبەندی';
                    if (status === 'overdue') title = 'قیستەکانی دواکەوتوو';
                    else if (status === 'this_week') title = 'قیستەکانی ئەم هەفتەیە';
                    else if (status === 'upcoming') title = 'قیستەکانی داهاتوو';

                    panel.outerHTML = `
                        <div class="panel">
                            <div class="panel-header">
                                <h3 class="panel-title">
                                    <i class="fas fa-calendar-alt"></i>
                                    ${title}
                                </h3>
                            </div>
                            <div class="panel-body">
                                <div class="table-container">
                                    <table class="installments-table">
                                        <thead>${document.querySelector('.installments-table thead').innerHTML}</thead>
                                        <tbody>${data.html}</tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    `;
                }
            }
        }

        // Update statistics
        if (data.statistics) {
            updateStatistics(data.statistics);
        }

        // Remove loading state
        removeLoadingState();
    }

    // Update statistics cards
    function updateStatistics(stats) {
        const statsMap = [
            { selector: '.stat-card-installment.stat-blue .stat-value-installment', value: stats.activePlans },
            { selector: '.stat-card-installment.stat-orange .stat-value-installment', value: formatNumber(stats.totalRemaining) },
            { selector: '.stat-card-installment.stat-red .stat-value-installment', value: formatNumber(stats.overdueAmount) },
            { selector: '.stat-card-installment.stat-green .stat-value-installment', value: formatNumber(stats.dueThisMonth) }
        ];

        statsMap.forEach(stat => {
            const element = document.querySelector(stat.selector);
            if (element) {
                element.textContent = stat.value;
            }
        });
    }

    // Show loading state
    function showLoadingState() {
        const tableBody = document.querySelector('.installments-table tbody');
        if (tableBody) {
            tableBody.style.opacity = '0.5';
            tableBody.style.pointerEvents = 'none';
        }
        searchInput.style.borderColor = 'var(--win-accent, #0078d4)';
    }

    // Remove loading state
    function removeLoadingState() {
        const tableBody = document.querySelector('.installments-table tbody');
        if (tableBody) {
            tableBody.style.opacity = '1';
            tableBody.style.pointerEvents = 'auto';
        }
        searchInput.style.borderColor = '#e0e0e0';
    }

    // Show error message
    function showError(message) {
        const tableBody = document.querySelector('.installments-table tbody');
        if (tableBody) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px; color: #d13438;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 10px; display: block;"></i>
                        ${message}
                    </td>
                </tr>
            `;
        }
        removeLoadingState();
    }

    // Debounced search handler
    function handleSearch(e) {
        const searchTerm = e.target.value.trim();

        clearTimeout(searchTimeout);

        if (searchTerm.length === 0) {
            // Reload page to show all results
            const status = getCurrentStatus();
            window.location.href = status ? `?status=${status}` : '?';
            return;
        }

        searchTimeout = setTimeout(() => {
            performSearch(searchTerm);
        }, DEBOUNCE_DELAY);
    }

    // Attach event listener
    searchInput.addEventListener('input', handleSearch);

    // Handle filter chip clicks to preserve search
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', function(e) {
            const searchTerm = searchInput.value.trim();
            if (searchTerm.length > 0) {
                e.preventDefault();
                const href = this.getAttribute('href');
                const newStatus = href.includes('status=') ? href.split('status=')[1] : '';

                // Update URL without reload
                const newUrl = newStatus ? `?status=${newStatus}` : '?';
                window.history.pushState({}, '', newUrl);

                // Perform search with new status
                performSearch(searchTerm);
            }
        });
    });

    // Prevent form submission (we're using live search)
    const searchForm = searchInput.closest('form');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            performSearch(searchInput.value.trim());
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
