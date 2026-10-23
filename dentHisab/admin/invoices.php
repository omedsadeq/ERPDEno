<?php
/**
 * Invoices Management Page (Admin Only)
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
$auth = $auth ?? new Auth();
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.invoices')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$pageTitle = 'بەڕێوەبردنی وەسڵ';
$breadcrumb = [['title' => 'بەڕێوەبردنی وەسڵ']];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/CaseManager.php';

$caseManager = new CaseManager();

// Filters
$status = $_GET['status'] ?? '';
$paymentStatus = $_GET['payment_status'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$searchTerm = $_GET['search'] ?? '';
$doctorId = $_GET['doctor_id'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));

// Build SQL query
$sql = "SELECT c.*,
        pf.patient_id, pf.folder_name, pf.doctor_id,
        p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
        u.full_name as doctor_name,
        (c.total_amount - c.paid_amount - c.discount_amount) as remaining_amount,
        (SELECT COUNT(*) FROM case_treatments ct WHERE ct.case_id = c.id) as treatment_count,
        (SELECT COUNT(*) FROM payments py WHERE py.case_id = c.id) as payment_count
        FROM cases c
        INNER JOIN patient_folders pf ON c.folder_id = pf.id
        LEFT JOIN patients p ON pf.patient_id = p.id
        LEFT JOIN users u ON pf.doctor_id = u.id
        WHERE 1=1";

$params = [];

// Apply filters
if ($status) {
    $sql .= " AND c.status = :status";
    $params['status'] = $status;
}

if ($dateFrom) {
    $sql .= " AND DATE(c.created_at) >= :date_from";
    $params['date_from'] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(c.created_at) <= :date_to";
    $params['date_to'] = $dateTo;
}

if ($searchTerm) {
    $sql .= " AND (p.full_name LIKE :search OR p.patient_code LIKE :search OR c.case_number LIKE :search OR p.phone LIKE :search)";
    $params['search'] = "%{$searchTerm}%";
}

if ($doctorId) {
    $sql .= " AND pf.doctor_id = :doctor_id";
    $params['doctor_id'] = $doctorId;
}

// Payment status filter
if ($paymentStatus === 'paid') {
    $sql .= " AND (c.total_amount - c.paid_amount - c.discount_amount) <= 0";
} elseif ($paymentStatus === 'partial') {
    $sql .= " AND c.paid_amount > 0 AND (c.total_amount - c.paid_amount - c.discount_amount) > 0";
} elseif ($paymentStatus === 'unpaid') {
    $sql .= " AND c.paid_amount = 0";
}

$sql .= " ORDER BY c.created_at DESC";

// Pagination
$offset = ($page - 1) * ITEMS_PER_PAGE;
$sql .= " LIMIT " . ITEMS_PER_PAGE . " OFFSET " . $offset;

$invoices = $db->fetchAll($sql, $params);

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total
             FROM cases c
             INNER JOIN patient_folders pf ON c.folder_id = pf.id
             LEFT JOIN patients p ON pf.patient_id = p.id
             WHERE 1=1";

if ($status) {
    $countSql .= " AND c.status = :status";
}
if ($dateFrom) {
    $countSql .= " AND DATE(c.created_at) >= :date_from";
}
if ($dateTo) {
    $countSql .= " AND DATE(c.created_at) <= :date_to";
}
if ($searchTerm) {
    $countSql .= " AND (p.full_name LIKE :search OR p.patient_code LIKE :search OR c.case_number LIKE :search OR p.phone LIKE :search)";
}
if ($doctorId) {
    $countSql .= " AND pf.doctor_id = :doctor_id";
}
if ($paymentStatus === 'paid') {
    $countSql .= " AND (c.total_amount - c.paid_amount - c.discount_amount) <= 0";
} elseif ($paymentStatus === 'partial') {
    $countSql .= " AND c.paid_amount > 0 AND (c.total_amount - c.paid_amount - c.discount_amount) > 0";
} elseif ($paymentStatus === 'unpaid') {
    $countSql .= " AND c.paid_amount = 0";
}

$totalResult = $db->fetch($countSql, $params);
$totalRecords = $totalResult['total'] ?? 0;
$totalPages = ceil($totalRecords / ITEMS_PER_PAGE);

// Get statistics
$statsSql = "SELECT
             COUNT(*) as total_invoices,
             SUM(c.total_amount) as total_revenue,
             SUM(c.paid_amount) as total_paid,
             SUM(c.total_amount - c.paid_amount - c.discount_amount) as total_remaining
             FROM cases c
             INNER JOIN patient_folders pf ON c.folder_id = pf.id
             LEFT JOIN patients p ON pf.patient_id = p.id
             WHERE 1=1";

$statsParams = [];
if ($dateFrom) {
    $statsSql .= " AND DATE(c.created_at) >= :date_from";
    $statsParams['date_from'] = $dateFrom;
}
if ($dateTo) {
    $statsSql .= " AND DATE(c.created_at) <= :date_to";
    $statsParams['date_to'] = $dateTo;
}
if ($doctorId) {
    $statsSql .= " AND pf.doctor_id = :doctor_id";
    $statsParams['doctor_id'] = $doctorId;
}

$stats = $db->fetch($statsSql, $statsParams);

// Get doctors list for filter
$doctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name");
?>

<style>
/* Invoice Cards Styling */
.stats-grid-invoices {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    margin-bottom: 8px;
}

.stat-card-invoice {
    background: linear-gradient(135deg, var(--bg-color), var(--hover-color));
    border-radius: 6px;
    padding: 8px 10px;
    text-align: center;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-invoice::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1.5px;
    background: var(--accent-color);
}

.stat-card-invoice:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: var(--accent-color);
}

.stat-card-invoice.stat-total {
    --bg-color: #e3f2fd;
    --hover-color: #bbdefb;
    --border-color: #90caf9;
    --accent-color: #2196f3;
}

.stat-card-invoice.stat-revenue {
    --bg-color: #e8f5e9;
    --hover-color: #c8e6c9;
    --border-color: #a5d6a7;
    --accent-color: #4caf50;
}

.stat-card-invoice.stat-paid {
    --bg-color: #e0f2f1;
    --hover-color: #b2dfdb;
    --border-color: #80cbc4;
    --accent-color: #009688;
}

.stat-card-invoice.stat-remaining {
    --bg-color: #fff3e0;
    --hover-color: #ffe0b2;
    --border-color: #ffcc80;
    --accent-color: #ff9800;
}

.stat-icon-invoice {
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

.stat-value-invoice {
    font-size: 16px;
    font-weight: 800;
    color: var(--accent-color);
    line-height: 1.1;
    margin-bottom: 1px;
}

.stat-label-invoice {
    font-size: 9px;
    color: #546e7a;
    font-weight: 600;
    line-height: 1.2;
}

/* Filters Panel */
.filters-panel {
    background: linear-gradient(to bottom, #ffffff, #fafafa);
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border: 1px solid #e0e0e0;
}

.filters-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 2px solid #f5f5f5;
}

.filters-title {
    font-size: 14px;
    font-weight: 700;
    color: #263238;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filters-title i {
    color: var(--primary-color);
    font-size: 14px;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    align-items: end;
}

.filter-group {
    position: relative;
}

.filter-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #37474f;
}

.filter-label i {
    color: var(--primary-color);
    font-size: 13px;
}

.filter-control {
    width: 100%;
    padding: 9px 12px;
    font-size: 13px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    transition: all 0.2s ease;
    font-family: inherit;
}

.filter-control:hover {
    border-color: #bdbdbd;
}

.filter-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}

.filter-control::placeholder {
    color: #9e9e9e;
    font-size: 12px;
}

.filters-actions {
    display: flex;
    gap: 8px;
}

/* Invoices Table Panel */
.invoices-panel {
    background: white;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
    border: 1px solid #e8e8e8;
}

.invoices-panel-header {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    padding: 8px 12px;
    color: white;
}

.invoices-panel-title {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.invoices-panel-title i {
    font-size: 15px;
}

.invoices-table-container {
    overflow-x: auto;
}

.invoices-table {
    width: 100%;
    border-collapse: collapse;
}

.invoices-table thead {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    border-bottom: 2px solid #e0e0e0;
}

.invoices-table th {
    padding: 6px 4px;
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}

.invoices-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.invoices-table tbody tr:hover {
    background: linear-gradient(to left, #f8f9fa, #ffffff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.invoices-table td {
    padding: 6px 4px;
    font-size: 10px;
    color: #2c3e50;
    text-align: center;
}

.case-number-cell {
    font-family: var(--app-font-family);
    font-weight: 700;
    color: #0078d4;
    font-size: 11px;
}

.patient-info-cell {
    min-width: 160px;
}

.patient-name-link {
    font-weight: 700;
    color: #2c3e50;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 3px;
    margin-bottom: 1px;
}

.patient-name-link:hover {
    color: #0078d4;
}

.patient-name-link i {
    color: #0078d4;
    font-size: 8px;
}

.patient-phone-text {
    font-size: 9px;
    color: #757575;
    display: flex;
    align-items: center;
    gap: 2px;
}

.patient-phone-text i {
    color: #4caf50;
    font-size: 8px;
}

.doctor-cell {
    color: #546e7a;
    display: flex;
    align-items: center;
    gap: 3px;
}

.doctor-cell i {
    color: #9c27b0;
    font-size: 8px;
}

.amount-cell {
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
}

.amount-cell.total {
    color: #0078d4;
    font-size: 11px;
}

.amount-cell.paid {
    color: #4caf50;
}

.amount-cell.remaining {
    color: #f44336;
}

.amount-cell.paid-full {
    color: #4caf50;
}

.status-badge-cell {
    text-align: center;
}

.date-cell {
    color: #757575;
    font-size: 10px;
}

.date-time {
    display: block;
    margin-top: 1px;
    font-size: 8px;
    color: #9e9e9e;
}

.actions-cell {
    text-align: center;
}

.action-buttons {
    display: flex;
    gap: 3px;
    justify-content: center;
}

.payment-status-indicator {
    display: inline-block;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    margin-left: 3px;
}

.payment-status-indicator.paid {
    background: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
}

.payment-status-indicator.partial {
    background: #ff9800;
    box-shadow: 0 0 0 2px rgba(255, 152, 0, 0.2);
}

.payment-status-indicator.unpaid {
    background: #f44336;
    box-shadow: 0 0 0 2px rgba(244, 67, 54, 0.2);
}

.empty-state-invoices {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 14px;
    border: 2px dashed #e0e0e0;
}

.empty-state-invoices i {
    font-size: 64px;
    color: #e0e0e0;
    margin-bottom: 16px;
}

.empty-state-invoices h3 {
    font-size: 18px;
    color: #757575;
    margin-bottom: 8px;
}

.empty-state-invoices p {
    font-size: 14px;
    color: #9e9e9e;
}

@media (max-width: 1400px) {
    .stats-grid-invoices {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid-invoices {
        grid-template-columns: 1fr;
    }
    .filters-grid {
        grid-template-columns: 1fr;
    }
    .invoices-table-container {
        overflow-x: auto;
    }
}
</style>

<?php if (isset($_GET['printed'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> وەسڵ چاپکرا</div>
<?php endif; ?>

<!-- Filters Panel -->
<div class="filters-panel">
    <div class="filters-header">
        <div class="filters-title">
            <i class="fas fa-sliders-h"></i>
            پاڵاوتنەکان
        </div>
    </div>
    <form method="GET">
        <div class="filters-grid">
            <div class="filter-group">
                <label class="filter-label">
                    <i class="fas fa-search"></i>
                    گەڕان
                </label>
                <input type="text" name="search" class="filter-control" placeholder="گەڕان بە ناو، کۆد، کەیس، مۆبایل..." value="<?= htmlspecialchars($searchTerm) ?>">
            </div>

            <div class="filter-group">
                <label class="filter-label">
                    <i class="fas fa-toggle-on"></i>
                    دۆخی کەیس
                </label>
                <select name="status" class="filter-control">
                    <option value="">هەموو دۆخەکان</option>
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>کراوە</option>
                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>داخراوە</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>چاوەڕوان</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">
                    <i class="fas fa-money-bill-wave"></i>
                    دۆخی پارەدان
                </label>
                <select name="payment_status" class="filter-control">
                    <option value="">هەموو دۆخەکان</option>
                    <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>تەواو دراوە</option>
                    <option value="partial" <?= $paymentStatus === 'partial' ? 'selected' : '' ?>>بەشێک دراوە</option>
                    <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>پارە نەدراوە</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">
                    <i class="fas fa-user-md"></i>
                    دکتۆر
                </label>
                <select name="doctor_id" class="filter-control">
                    <option value="">هەموو دکتۆرەکان</option>
                    <?php foreach ($doctors as $doc): ?>
                    <option value="<?= $doc['id'] ?>" <?= $doctorId == $doc['id'] ? 'selected' : '' ?>>
                        د. <?= htmlspecialchars($doc['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">
                    <i class="fas fa-calendar-day"></i>
                    لە بەروار
                </label>
                <input type="date" name="date_from" class="filter-control" value="<?= $dateFrom ?>">
            </div>

            <div class="filter-group">
                <label class="filter-label">
                    <i class="fas fa-calendar-check"></i>
                    بۆ بەروار
                </label>
                <input type="date" name="date_to" class="filter-control" value="<?= $dateTo ?>">
            </div>

            <div class="filter-group filters-actions">
                <button type="submit" class="btn btn-primary" style="padding: 9px 18px; font-size: 13px; border-radius: 8px; font-weight: 600; margin-top: 26px;">
                    <i class="fas fa-filter"></i> پاڵاوتن
                </button>
                <a href="<?= APP_URL ?>/admin/invoices.php" class="btn btn-secondary" style="padding: 9px 18px; font-size: 13px; border-radius: 8px; font-weight: 600; margin-top: 26px;">
                    <i class="fas fa-redo"></i> پاککردنەوە
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Invoices Table -->
<div id="invoicesTableWrapper">
<?php if (empty($invoices)): ?>
<div class="empty-state-invoices">
    <i class="fas fa-file-invoice"></i>
    <h3>هیچ وەسڵێک نەدۆزرایەوە</h3>
    <p>پاڵاوتنی جیاواز بەکاربهێنە</p>
</div>
<?php else: ?>
<div class="invoices-panel">
    <div class="invoices-panel-header">
        <h3 class="invoices-panel-title">
            <i class="fas fa-file-invoice"></i>
            هەموو وەسڵەکان
        </h3>
    </div>
    <div class="invoices-table-container">
        <table class="invoices-table">
            <thead>
                <tr>
                    <th>ژمارەی کەیس</th>
                    <th>نەخۆش</th>
                    <th>دکتۆر</th>
                    <th>کۆی گشتی</th>
                    <th>داشکاندن</th>
                    <th>پارەدراو</th>
                    <th>ماوە</th>
                    <th>دۆخ</th>
                    <th>بەروار</th>
                    <th>کردار</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                <?php
                $remaining = $inv['remaining_amount'];

                // Determine payment status
                if ($remaining <= 0) {
                    $paymentStatusClass = 'paid';
                    $paymentStatusText = 'تەواو دراوە';
                } elseif ($inv['paid_amount'] > 0) {
                    $paymentStatusClass = 'partial';
                    $paymentStatusText = 'بەشێک دراوە';
                } else {
                    $paymentStatusClass = 'unpaid';
                    $paymentStatusText = 'پارە نەدراوە';
                }

                $statusBadges = [
                    'open' => ['badge-green', 'کراوە'],
                    'closed' => ['badge-blue', 'داخراوە'],
                    'pending' => ['badge-orange', 'چاوەڕوان'],
                    'cancelled' => ['badge-red', 'هەڵوەشاوە']
                ];
                $badge = $statusBadges[$inv['status']] ?? ['', $inv['status']];
                ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/cases/view.php?id=<?= $inv['id'] ?>" class="case-number-cell">
                            <?= htmlspecialchars($inv['case_number']) ?>
                        </a>
                    </td>
                    <td class="patient-info-cell">
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $inv['patient_id'] ?>" class="patient-name-link">
                            <i class="fas fa-user-injured"></i>
                            <?= htmlspecialchars($inv['patient_name']) ?>
                        </a>
                        <div class="patient-phone-text">
                            <i class="fas fa-phone"></i>
                            <?= htmlspecialchars($inv['patient_phone']) ?>
                        </div>
                    </td>
                    <td>
                        <div class="doctor-cell">
                            <i class="fas fa-user-md"></i>
                            د. <?= htmlspecialchars($inv['doctor_name']) ?>
                        </div>
                    </td>
                    <td class="amount-cell total">
                        <?= number_format($inv['total_amount']) ?>
                    </td>
                    <td class="amount-cell">
                        <?php if ($inv['discount_amount'] > 0): ?>
                            <?= number_format($inv['discount_amount']) ?>
                        <?php else: ?>
                            <span style="color: #bdbdbd;">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="amount-cell paid">
                        <?= number_format($inv['paid_amount']) ?>
                    </td>
                    <td>
                        <?php if ($remaining > 0): ?>
                            <span class="amount-cell remaining">
                                <?= number_format($remaining) ?>
                                <span class="payment-status-indicator <?= $paymentStatusClass ?>"></span>
                            </span>
                        <?php else: ?>
                            <span class="amount-cell paid-full">
                                ✓ تەواو
                                <span class="payment-status-indicator <?= $paymentStatusClass ?>"></span>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="status-badge-cell">
                        <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
                    </td>
                    <td class="date-cell">
                        <?= date('Y-m-d', strtotime($inv['created_at'])) ?>
                        <span class="date-time"><?= date('H:i', strtotime($inv['created_at'])) ?></span>
                    </td>
                    <td class="actions-cell">
                        <div class="action-buttons">
                            <a href="<?= APP_URL ?>/cases/receipt.php?id=<?= $inv['id'] ?>"
                               class="btn btn-sm btn-primary"
                               title="چاپکردنی وەسڵ"
                               target="_blank">
                                <i class="fas fa-print"></i>
                            </a>
                            <a href="<?= APP_URL ?>/cases/view.php?id=<?= $inv['id'] ?>"
                               class="btn btn-sm btn-secondary"
                               title="بینینی وردەکاری">
                                <i class="fas fa-eye"></i>
                            </a>
                            <button class="btn btn-sm btn-info"
                                    onclick="viewInvoiceDetails(<?= $inv['id'] ?>)"
                                    title="پوختە">
                                <i class="fas fa-info-circle"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#1a1a2e;font-weight:800;">
                    <td colspan="3" style="padding:12px 14px;color:#fff;">
                        <i class="fas fa-file-invoice"></i>
                        کۆی گشتی — <?= number_format($stats['total_invoices'] ?? 0) ?> وەسڵ
                    </td>
                    <td style="padding:12px 14px;direction:ltr;text-align:right;color:#fff;"><?= number_format($stats['total_revenue'] ?? 0) ?></td>
                    <td></td>
                    <td style="padding:12px 14px;direction:ltr;text-align:right;color:#2ecc71;"><?= number_format($stats['total_paid'] ?? 0) ?></td>
                    <td style="padding:12px 14px;direction:ltr;text-align:right;color:#ff6b6b;font-size:15px;"><?= number_format($stats['total_remaining'] ?? 0) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Pagination -->
<div id="paginationWrapper">
<?php if ($totalPages > 1): ?>
<div class="pagination" style="margin-top: 12px;">
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page - 1 ?>&status=<?= $status ?>&payment_status=<?= $paymentStatus ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&search=<?= urlencode($searchTerm) ?>&doctor_id=<?= $doctorId ?>" class="pagination-btn" data-page="<?= $page - 1 ?>">
        <i class="fas fa-chevron-right"></i>
    </a>
    <?php endif; ?>

    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
    <a href="?page=<?= $i ?>&status=<?= $status ?>&payment_status=<?= $paymentStatus ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&search=<?= urlencode($searchTerm) ?>&doctor_id=<?= $doctorId ?>"
       class="pagination-btn <?= $i === $page ? 'active' : '' ?>" data-page="<?= $i ?>">
        <?= $i ?>
    </a>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page + 1 ?>&status=<?= $status ?>&payment_status=<?= $paymentStatus ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&search=<?= urlencode($searchTerm) ?>&doctor_id=<?= $doctorId ?>" class="pagination-btn" data-page="<?= $page + 1 ?>">
        <i class="fas fa-chevron-left"></i>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
</div><!-- End invoicesTableWrapper -->

<!-- Invoice Details Dialog -->
<div class="dialog-overlay" id="invoiceDetailsDialog">
    <div class="dialog" style="max-width: 600px;">
        <div class="dialog-header">
            <h4 class="dialog-title">وردەکاری وەسڵ</h4>
            <button class="dialog-close" onclick="closeDialog('invoiceDetailsDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body" id="invoiceDetailsContent">
            <div class="text-center"><i class="fas fa-spinner fa-spin"></i> چاوەڕێ بە...</div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('invoiceDetailsDialog')">داخستن</button>
        </div>
    </div>
</div>

<script>
function viewInvoiceDetails(caseId) {
    openDialog('invoiceDetailsDialog');

    // Load invoice details via AJAX
    fetch('<?= APP_URL ?>/api/invoice-details.php?case_id=' + caseId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayInvoiceDetails(data.invoice);
            } else {
                document.getElementById('invoiceDetailsContent').innerHTML =
                    '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> هەڵە: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            document.getElementById('invoiceDetailsContent').innerHTML =
                '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> هەڵەیەک ڕوویدا</div>';
        });
}

function displayInvoiceDetails(invoice) {
    const remaining = invoice.total_amount - invoice.paid_amount - invoice.discount_amount;

    let html = `
        <div class="receipt-info">
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #ddd;">ژمارەی کەیس:</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">${invoice.case_number}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #ddd;">نەخۆش:</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">${invoice.patient_name}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #ddd;">دکتۆر:</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">د. ${invoice.doctor_name}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #ddd;">بەروار:</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">${invoice.created_at}</td>
                </tr>
                <tr style="background: #f5f5f5;">
                    <td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #ddd;">کۆی گشتی:</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">${numberFormat(invoice.total_amount)}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #ddd;">داشکاندن:</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">${numberFormat(invoice.discount_amount)}</td>
                </tr>
                <tr style="background: #e8f5e9;">
                    <td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #ddd;">پارەدراو:</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; color: green; font-weight: bold;">${numberFormat(invoice.paid_amount)}</td>
                </tr>
                <tr style="background: ${remaining > 0 ? '#ffebee' : '#e8f5e9'};">
                    <td style="padding: 8px; font-weight: bold;">ماوە:</td>
                    <td style="padding: 8px; color: ${remaining > 0 ? 'red' : 'green'}; font-weight: bold; font-size: 14pt;">${numberFormat(remaining)}</td>
                </tr>
            </table>
        </div>

        <div style="margin-top: 20px;">
            <h5 style="margin-bottom: 10px;"><i class="fas fa-notes-medical"></i> چارەسەرەکان (${invoice.treatment_count})</h5>
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; padding: 10px;">
                ${invoice.treatments.map(t => `
                    <div style="padding: 5px; border-bottom: 1px solid #eee;">
                        <strong>${t.treatment_name}</strong>
                        ${t.tooth_number ? ` - ددان ${t.tooth_number}` : ''}
                        <span style="float: left; color: #0078D4;">${numberFormat(t.final_price)}</span>
                    </div>
                `).join('')}
            </div>
        </div>

        ${invoice.payments.length > 0 ? `
        <div style="margin-top: 20px;">
            <h5 style="margin-bottom: 10px;"><i class="fas fa-money-bill"></i> پارەدانەکان (${invoice.payment_count})</h5>
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; padding: 10px;">
                ${invoice.payments.map(p => `
                    <div style="padding: 5px; border-bottom: 1px solid #eee;">
                        ${p.payment_date}
                        <span style="float: left; color: green; font-weight: bold;">${numberFormat(p.amount)}</span>
                    </div>
                `).join('')}
            </div>
        </div>
        ` : ''}
    `;

    document.getElementById('invoiceDetailsContent').innerHTML = html;
}

function numberFormat(num) {
    return parseFloat(num).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 2});
}

// Real-time search functionality
let searchTimeout = null;
let searchCache = new Map();
const CACHE_DURATION = 60000; // 60 seconds

// Clean expired cache entries
function cleanExpiredCache() {
    const now = Date.now();
    for (let [key, value] of searchCache.entries()) {
        if (now - value.timestamp > CACHE_DURATION) {
            searchCache.delete(key);
        }
    }
}

// Generate cache key
function getCacheKey(page, search, status, paymentStatus, doctorId, dateFrom, dateTo) {
    return `${page}|${search}|${status}|${paymentStatus}|${doctorId}|${dateFrom}|${dateTo}`;
}

// Perform search
function performInvoiceSearch(page = 1) {
    const searchInput = document.querySelector('input[name="search"]');
    const statusSelect = document.querySelector('select[name="status"]');
    const paymentStatusSelect = document.querySelector('select[name="payment_status"]');
    const doctorSelect = document.querySelector('select[name="doctor_id"]');
    const dateFromInput = document.querySelector('input[name="date_from"]');
    const dateToInput = document.querySelector('input[name="date_to"]');

    const search = searchInput.value.trim();
    const status = statusSelect.value;
    const paymentStatus = paymentStatusSelect.value;
    const doctorId = doctorSelect.value;
    const dateFrom = dateFromInput.value;
    const dateTo = dateToInput.value;

    // Create cache key
    const cacheKey = getCacheKey(page, search, status, paymentStatus, doctorId, dateFrom, dateTo);

    // Check cache first
    cleanExpiredCache();
    if (searchCache.has(cacheKey)) {
        const cachedData = searchCache.get(cacheKey);
        updateUIWithResults(cachedData.data);
        return;
    }

    // Add visual feedback
    if (searchInput) {
        searchInput.style.background = 'linear-gradient(to right, white 0%, #f0f7ff 50%, white 100%)';
        searchInput.style.backgroundSize = '200% 100%';
        searchInput.style.animation = 'searchPulse 1.5s ease-in-out infinite';
    }

    // Show loading
    const tableBody = document.querySelector('.invoices-table tbody');
    if (tableBody) {
        tableBody.style.opacity = '0.6';
    }

    // Prepare form data
    const formData = new FormData();
    formData.append('page', page);
    formData.append('search', search);
    formData.append('status', status);
    formData.append('payment_status', paymentStatus);
    formData.append('doctor_id', doctorId);
    formData.append('date_from', dateFrom);
    formData.append('date_to', dateTo);

    // Perform AJAX request
    fetch('<?= APP_URL ?>/admin/ajax_invoices_search.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Cache the results
            searchCache.set(cacheKey, {
                data: data,
                timestamp: Date.now()
            });

            // Update UI
            updateUIWithResults(data);
        } else {
            console.error('Search error:', data.error);
        }

        // Remove visual feedback
        if (searchInput) {
            searchInput.style.background = '';
            searchInput.style.animation = '';
        }
        if (tableBody) {
            tableBody.style.opacity = '1';
        }
    })
    .catch(error => {
        console.error('AJAX error:', error);
        if (searchInput) {
            searchInput.style.background = '';
            searchInput.style.animation = '';
        }
        if (tableBody) {
            tableBody.style.opacity = '1';
        }
    });
}

// Update UI with results
function updateUIWithResults(data) {
    const tableBody = document.querySelector('.invoices-table tbody');
    const paginationWrapper = document.getElementById('paginationWrapper');
    const invoicesTableWrapper = document.getElementById('invoicesTableWrapper');

    // Update table or show empty state
    if (data.html.includes('empty-state-invoices')) {
        if (invoicesTableWrapper) {
            invoicesTableWrapper.innerHTML = data.html;
        }
    } else {
        if (tableBody) {
            tableBody.innerHTML = data.html;
        }
    }

    // Update pagination
    if (paginationWrapper) {
        paginationWrapper.innerHTML = data.pagination;
        attachPaginationHandlers();
    }

    // Update statistics
    if (data.statistics) {
        const stats = data.statistics;
        const statValues = document.querySelectorAll('.stat-value-invoice');
        if (statValues[0]) statValues[0].textContent = numberFormat(stats.totalInvoices);
        if (statValues[1]) statValues[1].textContent = numberFormat(stats.totalRevenue);
        if (statValues[2]) statValues[2].textContent = numberFormat(stats.totalPaid);
        if (statValues[3]) statValues[3].textContent = numberFormat(stats.totalRemaining);
    }
}

// Attach pagination handlers
function attachPaginationHandlers() {
    document.querySelectorAll('.pagination-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const page = parseInt(this.getAttribute('data-page'));
            if (page && !this.classList.contains('active')) {
                performInvoiceSearch(page);
            }
        });
    });
}

// Real-time search on input (debounced)
document.querySelector('input[name="search"]')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();

    // Instant search when empty
    if (query.length === 0) {
        performInvoiceSearch(1);
        return;
    }

    // Debounce for better performance
    searchTimeout = setTimeout(() => {
        if (query.length >= 2) {
            performInvoiceSearch(1);
        }
    }, 300);
});

// Filter change triggers search
['status', 'payment_status', 'doctor_id', 'date_from', 'date_to'].forEach(name => {
    const element = document.querySelector(`[name="${name}"]`);
    if (element) {
        element.addEventListener('change', function() {
            performInvoiceSearch(1);
        });
    }
});

// Prevent form submission, use AJAX instead
const filterForm = document.querySelector('.filters-panel form');
if (filterForm) {
    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        performInvoiceSearch(1);
    });
}

// Initial pagination handlers
attachPaginationHandlers();

// Add CSS animation for search pulse
const style = document.createElement('style');
style.textContent = `
    @keyframes searchPulse {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
`;
document.head.appendChild(style);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>