<?php
/**
 * AJAX Installments Search Endpoint
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Installment.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$installmentManager = new Installment();
$isAdmin = $auth->isAdmin();
$isDoctor = $auth->isDoctor();
$currentUser = $auth->getCurrentUser();

// Doctor filter: Doctors only see their own installments, Admins see all
$doctorFilter = null;
if (!$isAdmin && $isDoctor) {
    $doctorFilter = $currentUser['id'];
}

// Get search parameters
$page = max(1, intval($_POST['page'] ?? 1));
$status = $_POST['status'] ?? '';
$search = trim($_POST['search'] ?? '');

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

// Get statistics
$stats = $installmentManager->getStatistics();

// Build HTML based on view type
ob_start();
if (empty($installments)):
?>
<div class="empty-state-installments">
    <i class="fas fa-inbox"></i>
    <h3>هیچ قیستێک نەدۆزرایەوە</h3>
    <p>هیچ قیستێک بۆ ئەم فلتەرە نییە</p>
</div>
<?php
elseif ($status === 'overdue' || $status === 'upcoming' || $status === 'this_week'):
    foreach ($installments as $inst):
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
<?php
    endforeach;
else:
    foreach ($installments as $plan):
        $paidCount = $plan['paid_count'] ?? 0;
        // total_paid = installment_schedule paid only (excludes down payment)
        $schedulePaid = (float)($plan['total_paid'] ?? 0);
        $downPayment  = (float)($plan['down_payment'] ?? 0);
        $totalPaid    = $downPayment + $schedulePaid;
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
<?php
    endforeach;
endif;
$html = ob_get_clean();

// Return JSON response
echo json_encode([
    'success' => true,
    'html' => $html,
    'viewType' => $status,
    'statistics' => [
        'activePlans' => intval($stats['active_plans']),
        'totalRemaining' => floatval($stats['total_remaining']),
        'overdueAmount' => floatval($stats['overdue_amount']),
        'dueThisMonth' => floatval($stats['due_this_month'])
    ]
]);
