<?php
/**
 * AJAX Invoice Search Endpoint
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

// Get search parameters
$page = max(1, intval($_POST['page'] ?? 1));
$searchTerm = trim($_POST['search'] ?? '');
$status = $_POST['status'] ?? '';
$paymentStatus = $_POST['payment_status'] ?? '';
$doctorId = intval($_POST['doctor_id'] ?? 0);
$dateFrom = $_POST['date_from'] ?? '';
$dateTo = $_POST['date_to'] ?? '';

// Build query
$sql = "SELECT
    c.id,
    c.case_number,
    c.status,
    c.total_amount,
    c.discount_amount,
    c.paid_amount,
    (c.total_amount - c.discount_amount - c.paid_amount) as remaining_amount,
    c.created_at,
    p.id as patient_id,
    p.full_name as patient_name,
    p.phone as patient_phone,
    pf.id as folder_id,
    u.full_name as doctor_name
FROM cases c
INNER JOIN patient_folders pf ON c.folder_id = pf.id
INNER JOIN patients p ON pf.patient_id = p.id
INNER JOIN users u ON pf.doctor_id = u.id
WHERE 1=1";

$params = [];

// Search filter
if ($searchTerm) {
    $sql .= " AND (p.full_name LIKE :search
              OR p.patient_code LIKE :search
              OR p.phone LIKE :search
              OR c.case_number LIKE :search)";
    $params['search'] = "%$searchTerm%";
}

// Status filter
if ($status) {
    $sql .= " AND c.status = :status";
    $params['status'] = $status;
}

// Payment status filter
if ($paymentStatus) {
    if ($paymentStatus === 'paid') {
        $sql .= " AND (c.total_amount - c.discount_amount - c.paid_amount) <= 0";
    } elseif ($paymentStatus === 'partial') {
        $sql .= " AND c.paid_amount > 0 AND (c.total_amount - c.discount_amount - c.paid_amount) > 0";
    } elseif ($paymentStatus === 'unpaid') {
        $sql .= " AND c.paid_amount = 0";
    }
}

// Doctor filter
if ($doctorId) {
    $sql .= " AND pf.doctor_id = :doctor_id";
    $params['doctor_id'] = $doctorId;
}

// Date range filter
if ($dateFrom) {
    $sql .= " AND DATE(c.created_at) >= :date_from";
    $params['date_from'] = $dateFrom;
}
if ($dateTo) {
    $sql .= " AND DATE(c.created_at) <= :date_to";
    $params['date_to'] = $dateTo;
}

// Get total count
$countSql = "SELECT COUNT(*) as total FROM ($sql) as counted";
$totalResult = $db->fetch($countSql, $params);
$totalInvoices = $totalResult['total'] ?? 0;
$totalPages = ceil($totalInvoices / ITEMS_PER_PAGE);

// Add pagination
$offset = ($page - 1) * ITEMS_PER_PAGE;
$sql .= " ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";
$params['limit'] = ITEMS_PER_PAGE;
$params['offset'] = $offset;

// Fetch invoices
$invoices = $db->fetchAll($sql, $params);

// Build HTML for invoices table
ob_start();
if (empty($invoices)):
?>
<div class="empty-state-invoices">
    <i class="fas fa-file-invoice"></i>
    <h3>هیچ وەسڵێک نەدۆزرایەوە</h3>
    <p>پاڵاوتنی جیاواز بەکاربهێنە</p>
</div>
<?php else: ?>
<?php foreach ($invoices as $inv): ?>
<?php
$remaining = $inv['remaining_amount'];

// Determine payment status
if ($remaining <= 0) {
    $paymentStatusClass = 'paid';
} elseif ($inv['paid_amount'] > 0) {
    $paymentStatusClass = 'partial';
} else {
    $paymentStatusClass = 'unpaid';
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
<?php endif;
$html = ob_get_clean();

// Build pagination HTML
ob_start();
if ($totalPages > 1):
?>
<div class="pagination" style="margin-top: 12px;">
    <?php if ($page > 1): ?>
    <a href="#" class="pagination-btn" data-page="<?= $page - 1 ?>">
        <i class="fas fa-chevron-right"></i>
    </a>
    <?php endif; ?>

    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
    <a href="#" class="pagination-btn <?= $i === $page ? 'active' : '' ?>" data-page="<?= $i ?>">
        <?= $i ?>
    </a>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
    <a href="#" class="pagination-btn" data-page="<?= $page + 1 ?>">
        <i class="fas fa-chevron-left"></i>
    </a>
    <?php endif; ?>
</div>
<?php
endif;
$pagination = ob_get_clean();

// Get statistics
$statsSql = "SELECT
    COUNT(*) as total_invoices,
    COALESCE(SUM(c.total_amount - c.discount_amount), 0) as total_revenue,
    COALESCE(SUM(c.paid_amount), 0) as total_paid,
    COALESCE(SUM(c.total_amount - c.discount_amount - c.paid_amount), 0) as total_remaining
FROM cases c
INNER JOIN patient_folders pf ON c.folder_id = pf.id
INNER JOIN patients p ON pf.patient_id = p.id
WHERE 1=1";

$statsParams = [];

if ($searchTerm) {
    $statsSql .= " AND (p.full_name LIKE :search
                   OR p.patient_code LIKE :search
                   OR p.phone LIKE :search
                   OR c.case_number LIKE :search)";
    $statsParams['search'] = "%$searchTerm%";
}

if ($status) {
    $statsSql .= " AND c.status = :status";
    $statsParams['status'] = $status;
}

if ($paymentStatus) {
    if ($paymentStatus === 'paid') {
        $statsSql .= " AND (c.total_amount - c.discount_amount - c.paid_amount) <= 0";
    } elseif ($paymentStatus === 'partial') {
        $statsSql .= " AND c.paid_amount > 0 AND (c.total_amount - c.discount_amount - c.paid_amount) > 0";
    } elseif ($paymentStatus === 'unpaid') {
        $statsSql .= " AND c.paid_amount = 0";
    }
}

if ($doctorId) {
    $statsSql .= " AND pf.doctor_id = :doctor_id";
    $statsParams['doctor_id'] = $doctorId;
}

if ($dateFrom) {
    $statsSql .= " AND DATE(c.created_at) >= :date_from";
    $statsParams['date_from'] = $dateFrom;
}

if ($dateTo) {
    $statsSql .= " AND DATE(c.created_at) <= :date_to";
    $statsParams['date_to'] = $dateTo;
}

$statistics = $db->fetch($statsSql, $statsParams);

// Return JSON response
echo json_encode([
    'success' => true,
    'html' => $html,
    'pagination' => $pagination,
    'page' => $page,
    'totalPages' => $totalPages,
    'totalInvoices' => $totalInvoices,
    'statistics' => [
        'totalInvoices' => intval($statistics['total_invoices']),
        'totalRevenue' => floatval($statistics['total_revenue']),
        'totalPaid' => floatval($statistics['total_paid']),
        'totalRemaining' => floatval($statistics['total_remaining'])
    ]
]);
