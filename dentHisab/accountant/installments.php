<?php
require_once __DIR__ . '/../config/config.php';
/**
 * Accountant - Installments Management
 * بەڕێوەبردنی قیستەکان
 */

$pageTitle = 'قیستەکان';
$breadcrumb = [
    ['title' => 'داشبۆردی ژمێریار', 'url' => APP_URL . '/accountant/dashboard.php'],
    ['title' => 'قیستەکان']
];

// Role gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$__gate = new Auth();
$__gate->requireLogin();
if (!$__gate->isAccountant() && !$__gate->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Installment.php';

if (!$isAccountant && !$isAdmin) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$installmentManager = new Installment();

$filter  = $_GET['filter'] ?? 'active';
$search  = trim($_GET['search'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset  = ($page - 1) * $perPage;

// Build query based on filter
$extraWhere = '';
if ($filter === 'overdue') {
    $extraWhere = "AND EXISTS (SELECT 1 FROM installment_schedule sch WHERE sch.plan_id = ip.id AND sch.status IN ('pending','partial') AND sch.due_date < CURDATE())";
} elseif ($filter === 'completed') {
    $extraWhere = "AND ip.status = 'completed'";
} elseif ($filter === 'active') {
    $extraWhere = "AND ip.status = 'active'";
}

$searchWhere = '';
$params      = [];
if ($search !== '') {
    $searchWhere = "AND (p.full_name LIKE :search OR p.phone LIKE :search)";
    $params['search'] = "%{$search}%";
}

$baseQuery = "
    FROM installment_plans ip
    JOIN cases c ON ip.case_id = c.id
    JOIN patient_folders pf ON c.folder_id = pf.id
    JOIN patients p ON pf.patient_id = p.id
    JOIN users u ON pf.doctor_id = u.id
    WHERE 1=1 {$extraWhere} {$searchWhere}
";

$totalCount = $db->fetch("SELECT COUNT(*) as cnt {$baseQuery}", $params)['cnt'] ?? 0;
$totalPages = max(1, ceil($totalCount / $perPage));

$plans = $db->fetchAll("
    SELECT ip.*, c.case_number, c.id as case_id,
           p.full_name AS patient_name, p.phone AS patient_phone,
           u.full_name AS doctor_name
    {$baseQuery}
    ORDER BY ip.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// Stats
$activePlans  = $db->count('installment_plans', "status = 'active'");
$overduePlans = $db->fetch("SELECT COUNT(DISTINCT plan_id) as cnt FROM installment_schedule WHERE status IN ('pending','partial') AND due_date < CURDATE()")['cnt'] ?? 0;
$totalOutstanding = $db->fetch("SELECT COALESCE(SUM(remaining_amount),0) as t FROM installment_plans WHERE status='active'")['t'] ?? 0;
?>

<style>
.report-container { max-width:1400px; margin:0 auto; }
.report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
.report-title { display:flex; align-items:center; gap:12px; }
.report-title h2 { margin:0; font-size:20px; color:#1a1a1a; }
.report-title .icon { width:45px; height:45px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-size:20px; box-shadow:0 4px 12px rgba(0,0,0,0.2); }
.filters-card { background:white; border-radius:12px; padding:18px; margin-bottom:20px; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
.filters-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:5px; }
.filter-group label { font-size:11px; color:#666; font-weight:600; }
.filter-group input, .filter-group select { padding:9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; min-width:150px; }
.filter-group input:focus, .filter-group select:focus { border-color:#0078d4; outline:none; box-shadow:0 0 0 3px rgba(0,120,212,0.1); }
.btn-filter { padding:9px 18px; background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; display:flex; align-items:center; gap:6px; }
.btn-reset { padding:9px 18px; background:#f5f5f5; color:#666; border:1.5px solid #e0e0e0; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; }
.stats-row { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
.stat-card { background:white; border-radius:10px; padding:12px 18px; box-shadow:0 1px 6px rgba(0,0,0,0.07); display:flex; flex-direction:column; gap:3px; border-right:3px solid transparent; min-width:150px; }
.stat-card.blue { border-right-color:#0078d4; }
.stat-card.red { border-right-color:#c50f1f; }
.stat-card.purple { border-right-color:#8764b8; }
.stat-value { font-size:20px; font-weight:700; color:#1a1a1a; }
.stat-label { font-size:11px; color:#888; }
.tab-group { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.tab-btn { padding:8px 18px; border-radius:20px; border:1.5px solid #e0e0e0; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; color:#666; background:white; transition:all 0.2s; display:inline-flex; align-items:center; gap:5px; }
.tab-btn.active { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border-color:#0078d4; }
.tab-btn:hover:not(.active) { border-color:#0078d4; color:#0078d4; }
.table-card { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; margin-bottom:16px; }
.table-header { padding:16px 18px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg,#fafafa,#f5f5f5); }
.table-header h3 { margin:0; font-size:15px; color:#333; display:flex; align-items:center; gap:8px; }
.table-count { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; padding:5px 12px; border-radius:14px; font-size:12px; font-weight:600; }
.report-table { width:100%; border-collapse:collapse; font-size:13px; }
.report-table th { background:#f8f9fa; padding:13px 14px; text-align:right; font-weight:600; color:#333; border-bottom:2px solid #e0e0e0; }
.report-table td { padding:13px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.report-table tr:hover { background:#f9f9f9; }
.report-table tr.overdue-row { background:#fff9f9; }
.status-badge { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:14px; font-size:11px; font-weight:600; }
.badge-active { background:#e8f5e9; color:#2e7d32; }
.badge-completed { background:#e3f2fd; color:#1565c0; }
.overdue-tag { background:#c50f1f; color:white; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; margin-right:6px; }
.btn-icon { width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:none; cursor:pointer; transition:all 0.2s; text-decoration:none; font-size:13px; }
.btn-primary-icon { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; }
.btn-icon:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
.actions-cell { display:flex; gap:6px; }
.no-data { text-align:center; padding:50px; color:#888; }
.no-data i { font-size:50px; margin-bottom:15px; display:block; color:#ddd; }
.pagination-wrapper { padding:16px 18px; border-top:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fafafa; }
.pagination-info { font-size:13px; color:#666; }
.pagination { display:flex; gap:6px; }
.pagination a, .pagination span { padding:8px 14px; border-radius:8px; font-size:13px; text-decoration:none; }
.pagination a { background:white; color:#0078d4; border:1.5px solid #e0e0e0; }
.pagination a:hover { background:#0078d4; color:white; }
.pagination .active { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border:none; padding:8px 14px; }
.pagination .disabled { background:#f5f5f5; color:#bdbdbd; border:1.5px solid #e0e0e0; cursor:not-allowed; }
</style>

<div class="report-container">

    <!-- Header -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon" style="background:linear-gradient(135deg,#0078d4,#0063b1);">
                <i class="fas fa-credit-card"></i>
            </div>
            <h2>قیستەکان</h2>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card blue">
            <div class="stat-value"><?= number_format($activePlans) ?></div>
            <div class="stat-label">پلانی چالاک</div>
        </div>
        <div class="stat-card red">
            <div class="stat-value"><?= number_format($overduePlans) ?></div>
            <div class="stat-label">پلانی دواکەوتوو</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-value"><?= number_format($totalOutstanding) ?> <small style="font-size:13px;font-weight:500;"></small></div>
            <div class="stat-label">ماوەی کۆی گشتی</div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="tab-group">
        <a href="?filter=active&search=<?= urlencode($search) ?>"   class="tab-btn <?= $filter==='active'?'active':'' ?>"><i class="fas fa-check-circle"></i> چالاک</a>
        <a href="?filter=overdue&search=<?= urlencode($search) ?>"  class="tab-btn <?= $filter==='overdue'?'active':'' ?>"><i class="fas fa-exclamation-circle"></i> دواکەوتوو</a>
        <a href="?filter=completed&search=<?= urlencode($search) ?>" class="tab-btn <?= $filter==='completed'?'active':'' ?>"><i class="fas fa-flag-checkered"></i> تەواوبوو</a>
        <a href="?filter=&search=<?= urlencode($search) ?>"          class="tab-btn <?= $filter===''?'active':'' ?>"><i class="fas fa-list"></i> هەموو</a>
    </div>

    <!-- Search Filter -->
    <div class="filters-card">
        <form method="GET">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <div class="filters-row">
                <div class="filter-group" style="flex:1;min-width:220px;">
                    <label>گەڕان</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="گەڕان بە ناو یان تێلیفۆن...">
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> گەڕان
                </button>
                <?php if ($search !== ''): ?>
                <a href="?filter=<?= urlencode($filter) ?>" class="btn-reset">
                    <i class="fas fa-times"></i> پاککردنەوە
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Plans Table -->
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-credit-card" style="color:#0078d4;"></i> لیستی قیستەکان</h3>
            <span class="table-count"><?= number_format($totalCount) ?> پلان</span>
        </div>

        <?php if (empty($plans)): ?>
        <div class="no-data">
            <i class="fas fa-credit-card"></i>
            هیچ پلانێک نییە
        </div>
        <?php else: ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نەخۆش</th>
                    <th>دکتۆر</th>
                    <th style="text-align:center;">کۆی مەبەلەغ</th>
                    <th style="text-align:center;">دراوە</th>
                    <th style="text-align:center;">ماوە</th>
                    <th style="text-align:center;">دۆخ</th>
                    <th style="text-align:center;">کردار</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plans as $i => $plan): ?>
            <?php
            $remaining = $plan['total_amount'] - $plan['paid_amount'];
            $isOverdue = $db->count('installment_schedule', "plan_id={$plan['id']} AND status IN ('pending','partial') AND due_date < CURDATE()") > 0;
            ?>
            <tr <?= $isOverdue ? 'class="overdue-row"' : '' ?>>
                <td style="color:#888;font-size:12px;"><?= $offset + $i + 1 ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <?php if ($isOverdue): ?>
                        <span class="overdue-tag">دواکەوتوو</span>
                        <?php endif; ?>
                        <span style="font-weight:600;"><?= htmlspecialchars($plan['patient_name']) ?></span>
                    </div>
                    <?php if (!empty($plan['patient_phone'])): ?>
                    <div style="font-size:11px;color:#888;margin-top:2px;"><?= htmlspecialchars($plan['patient_phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="color:#555;font-size:12px;"><?= htmlspecialchars($plan['doctor_name']) ?></td>
                <td style="text-align:center;font-weight:600;"><?= number_format($plan['total_amount']) ?> <small style="color:#888;"></small></td>
                <td style="text-align:center;font-weight:600;color:#107c10;"><?= number_format($plan['paid_amount']) ?> <small style="color:#888;"></small></td>
                <td style="text-align:center;font-weight:700;color:<?= $remaining > 0 ? '#c50f1f' : '#107c10' ?>;"><?= number_format($remaining) ?> <small style="font-weight:400;"></small></td>
                <td style="text-align:center;">
                    <?php if ($plan['status'] === 'active'): ?>
                    <span class="status-badge badge-active"><i class="fas fa-circle" style="font-size:7px;"></i> چالاک</span>
                    <?php elseif ($plan['status'] === 'completed'): ?>
                    <span class="status-badge badge-completed"><i class="fas fa-check-circle" style="font-size:10px;"></i> تەواوبوو</span>
                    <?php else: ?>
                    <span style="color:#888;">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <div class="actions-cell" style="justify-content:center;">
                        <a href="<?= APP_URL ?>/accountant/report-detail.php?case_id=<?= $plan['case_id'] ?>" class="btn-icon btn-primary-icon" title="وەرگرتن">
                            <i class="fas fa-money-bill-wave"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <div class="pagination-info">
                <?= number_format(($page - 1) * $perPage + 1) ?> – <?= number_format(min($page * $perPage, $totalCount)) ?> لە <?= number_format($totalCount) ?>
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-right"></i></a>
                <?php else: ?>
                <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <?php if ($i === $page): ?>
                <span class="active"><?= $i ?></span>
                <?php else: ?>
                <a href="?filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <a href="?filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-left"></i></a>
                <?php else: ?>
                <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
