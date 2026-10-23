<?php
/**
 * AJAX Cases Search Endpoint - Enhanced with Advanced Filters
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Installment.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$caseManager = new CaseManager();
$installmentManager = new Installment();
$isAdmin = $auth->isAdmin();
$currentUserId = $auth->getCurrentUser()['id'];
$doctorId = $isAdmin ? null : $currentUserId;

// Get search parameters
$page = max(1, intval($_POST['page'] ?? 1));
$status = trim($_POST['status'] ?? '');
$search = trim($_POST['search'] ?? '');
$dateFrom = trim($_POST['date_from'] ?? '');
$dateTo = trim($_POST['date_to'] ?? '');
$filterDoctorId = $isAdmin ? trim($_POST['doctor_id'] ?? '') : $currentUserId;
$paymentStatus = trim($_POST['payment_status'] ?? '');

// Build filters
$filters = [
    'status' => $status,
    'search' => $search,
    'doctor_id' => $filterDoctorId ?: $doctorId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];

// Get cases with payment status filter (needs custom query)
if ($paymentStatus) {
    $cases = getCasesWithPaymentFilter($db, $page, ITEMS_PER_PAGE, $filters, $paymentStatus);
    $totalCases = countCasesWithPaymentFilter($db, $filters, $paymentStatus);
} else {
    $cases = $caseManager->getAll($page, ITEMS_PER_PAGE, $filters);
    $totalCases = $caseManager->count($filters);
}

$totalPages = ceil($totalCases / ITEMS_PER_PAGE);

// Get totals for all cases
$totals = $caseManager->getTotals($filters);
$totalAmount = $totals['total_amount'];
$totalPaid = $totals['total_paid'];
$totalDiscount = $totals['total_discount'];
$totalRemaining = $totals['total_remaining'];

// Get installment statistics
$installmentStats = $installmentManager->getStatistics();

// Get status counts for filter chips (without date/payment filters)
$baseFilters = ['doctor_id' => $doctorId];
$openCount = $caseManager->count(array_merge($baseFilters, ['status' => 'open']));
$pendingCount = $caseManager->count(array_merge($baseFilters, ['status' => 'pending']));
$closedCount = $caseManager->count(array_merge($baseFilters, ['status' => 'closed']));
$allCount = $caseManager->count($baseFilters);

// Build HTML for cases table
ob_start();
if (empty($cases)):
?>
<tr>
    <td colspan="11">
        <div class="empty-state">
            <i class="fas fa-folder-open empty-state-icon"></i>
            <p class="empty-state-title">هیچ کەیسێک نەدۆزرایەوە</p>
            <p class="empty-state-text">
                <?= !empty($search) ? 'گەڕانەکەت ئەنجامی نەبوو، تکایە دووبارە هەوڵ بدەرەوە' : 'هێشتا هیچ کەیسێک تۆمار نەکراوە' ?>
            </p>
        </div>
    </td>
</tr>
<?php else: ?>
<?php foreach ($cases as $case): ?>
<?php
$remaining = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount'];

$statusBadges = [
    'open' => ['badge-green', 'کراوە'],
    'closed' => ['badge-blue', 'داخراوە'],
    'pending' => ['badge-orange', 'چاوەڕوان'],
    'cancelled' => ['badge-red', 'هەڵوەشاوە']
];
$badge = $statusBadges[$case['status']] ?? ['', $case['status']];
?>
<tr data-case-id="<?= $case['id'] ?>">
    <td class="no-print">
        <input type="checkbox" class="row-checkbox" value="<?= $case['id'] ?>">
    </td>
    <td>
        <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>" class="case-number-link">
            <?= htmlspecialchars($case['case_number']) ?>
        </a>
    </td>
    <td>
        <a href="<?= APP_URL ?>/patients/view.php?id=<?= $case['patient_id'] ?? '' ?>" class="patient-name-cell">
            <?= htmlspecialchars($case['patient_name']) ?>
        </a>
    </td>
    <td class="doctor-name-cell">
        د. <?= htmlspecialchars($case['doctor_name']) ?>
    </td>
    <td>
        <span class="amount-value total">
            <?= number_format($case['total_amount']) ?>
        </span>
    </td>
    <td>
        <span class="amount-value paid">
            <?= number_format($case['paid_amount']) ?>
        </span>
    </td>
    <td>
        <span class="amount-value">
            <?= $case['discount_amount'] > 0 ? number_format($case['discount_amount']) : '-' ?>
        </span>
    </td>
    <td>
        <span class="amount-value remaining <?= $remaining <= 0 ? 'zero' : '' ?>">
            <?= number_format($remaining) ?>
        </span>
    </td>
    <td class="status-cell">
        <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
    </td>
    <td class="date-value">
        <?= date('Y-m-d', strtotime($case['created_at'])) ?>
    </td>
    <td class="no-print">
        <a href="<?= APP_URL ?>/cases/view.php?id=<?= $case['id'] ?>" class="btn btn-sm btn-primary" title="بینین">
            <i class="fas fa-eye"></i>
        </a>
    </td>
</tr>
<?php endforeach; ?>
<?php endif;
$html = ob_get_clean();

// Build pagination HTML
ob_start();
if ($totalPages > 1):
?>
<div class="pagination-modern">
    <?php if ($page > 1): ?>
    <a href="#" class="page-btn" data-page="<?= $page - 1 ?>">
        <i class="fas fa-chevron-right"></i>
    </a>
    <?php endif; ?>

    <?php
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);

    if ($startPage > 1): ?>
    <a href="#" class="page-btn" data-page="1">1</a>
    <?php if ($startPage > 2): ?>
    <span class="page-btn disabled">...</span>
    <?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
    <a href="#" class="page-btn <?= $i === $page ? 'active' : '' ?>" data-page="<?= $i ?>">
        <?= $i ?>
    </a>
    <?php endfor; ?>

    <?php if ($endPage < $totalPages): ?>
    <?php if ($endPage < $totalPages - 1): ?>
    <span class="page-btn disabled">...</span>
    <?php endif; ?>
    <a href="#" class="page-btn" data-page="<?= $totalPages ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
    <a href="#" class="page-btn" data-page="<?= $page + 1 ?>">
        <i class="fas fa-chevron-left"></i>
    </a>
    <?php endif; ?>
</div>
<?php
endif;
$pagination = ob_get_clean();

// Return JSON response
echo json_encode([
    'success' => true,
    'html' => $html,
    'pagination' => $pagination,
    'page' => $page,
    'totalPages' => $totalPages,
    'totalCases' => $totalCases,
    'statistics' => [
        'totalCases' => intval($totalCases),
        'totalAmount' => floatval($totalAmount),
        'totalPaid' => floatval($totalPaid),
        'totalDiscount' => floatval($totalDiscount),
        'totalRemaining' => floatval($totalRemaining),
        'activePlans' => intval($installmentStats['active_plans']),
        'installmentRemaining' => floatval($installmentStats['total_remaining'])
    ],
    'statusCounts' => [
        'total' => $allCount,
        'open' => $openCount,
        'pending' => $pendingCount,
        'closed' => $closedCount
    ]
]);

/**
 * Get cases with payment status filter
 */
function getCasesWithPaymentFilter($db, $page, $limit, $filters, $paymentStatus) {
    $offset = ($page - 1) * $limit;
    $params = [];

    $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
            p.full_name as patient_name, p.patient_code,
            u.full_name as doctor_name
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN users u ON pf.doctor_id = u.id
            WHERE 1=1";

    if (!empty($filters['status'])) {
        $sql .= " AND c.status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['doctor_id'])) {
        $sql .= " AND pf.doctor_id = :doctor_id";
        $params['doctor_id'] = $filters['doctor_id'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
        $params['search'] = "%{$filters['search']}%";
        $params['search2'] = "%{$filters['search']}%";
        $params['search3'] = "%{$filters['search']}%";
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(c.created_at) >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(c.created_at) <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    // Payment status filter
    switch ($paymentStatus) {
        case 'paid':
            $sql .= " AND (c.total_amount - c.discount_amount) <= c.paid_amount";
            break;
        case 'partial':
            $sql .= " AND c.paid_amount > 0 AND (c.total_amount - c.discount_amount) > c.paid_amount";
            break;
        case 'unpaid':
            $sql .= " AND c.paid_amount = 0 AND c.total_amount > 0";
            break;
    }

    $sql .= " ORDER BY c.updated_at DESC LIMIT {$limit} OFFSET {$offset}";

    return $db->fetchAll($sql, $params);
}

/**
 * Count cases with payment status filter
 */
function countCasesWithPaymentFilter($db, $filters, $paymentStatus) {
    $params = [];
    $sql = "SELECT COUNT(*) as count FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            WHERE 1=1";

    if (!empty($filters['status'])) {
        $sql .= " AND c.status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['doctor_id'])) {
        $sql .= " AND pf.doctor_id = :doctor_id";
        $params['doctor_id'] = $filters['doctor_id'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
        $params['search'] = "%{$filters['search']}%";
        $params['search2'] = "%{$filters['search']}%";
        $params['search3'] = "%{$filters['search']}%";
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(c.created_at) >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(c.created_at) <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    // Payment status filter
    switch ($paymentStatus) {
        case 'paid':
            $sql .= " AND (c.total_amount - c.discount_amount) <= c.paid_amount";
            break;
        case 'partial':
            $sql .= " AND c.paid_amount > 0 AND (c.total_amount - c.discount_amount) > c.paid_amount";
            break;
        case 'unpaid':
            $sql .= " AND c.paid_amount = 0 AND c.total_amount > 0";
            break;
    }

    return $db->fetch($sql, $params)['count'];
}
