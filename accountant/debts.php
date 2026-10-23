<?php
require_once __DIR__ . '/../config/config.php';
/**
 * Accountant - Debts Management
 * بەڕێوەبردنی قەرزەکان
 */

$pageTitle = 'قەرزەکان';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'],
    ['title' => 'قەرزەکان']
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

if (!$isAccountant && !$isAdmin) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$search   = trim($_GET['search'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$perPage  = ITEMS_PER_PAGE;
$offset   = ($page - 1) * $perPage;

$where  = "fs.balance > 0 AND c.status != 'closed'";
$params = [];

if ($search !== '') {
    $where .= " AND (p.full_name LIKE :search1 OR p.phone LIKE :search2 OR c.case_number LIKE :search3)";
    $params['search1'] = $params['search2'] = $params['search3'] = "%{$search}%";
}

$baseQuery = "
    FROM cases c
    JOIN v_case_financial_summary fs ON fs.case_id=c.id
    JOIN patient_folders pf ON c.folder_id = pf.id
    JOIN patients p ON pf.patient_id = p.id
    JOIN users u ON pf.doctor_id = u.id
    WHERE {$where}
";

$totalCount  = $db->fetch("SELECT COUNT(*) as cnt {$baseQuery}", $params)['cnt'] ?? 0;
$totalPages  = max(1, ceil($totalCount / $perPage));
$totalDebt   = $db->fetch("SELECT COALESCE(SUM(fs.balance),0) as t {$baseQuery}", $params)['t'] ?? 0;

$debtCases = $db->fetchAll("
    SELECT c.*, fs.total_invoiced AS total_amount,fs.total_discount AS discount_amount,
           fs.paid_amount,fs.approved_amount, p.full_name AS patient_name, p.phone AS patient_phone,
           u.full_name AS doctor_name,
           fs.balance AS remaining
    {$baseQuery}
    ORDER BY remaining DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);
?>

<style>
.report-container { max-width:1400px; margin:0 auto; }
.report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
.report-title { display:flex; align-items:center; gap:12px; }
.report-title h2 { margin:0; font-size:20px; color:#1a1a1a; }
.report-title .icon { width:45px; height:45px; background:linear-gradient(135deg,#c50f1f,#e53935); border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-size:20px; box-shadow:0 4px 12px rgba(197,15,31,0.3); }
.filters-card { background:white; border-radius:12px; padding:18px; margin-bottom:20px; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
.filters-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:5px; }
.filter-group label { font-size:11px; color:#666; font-weight:600; }
.filter-group input, .filter-group select { padding:9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; min-width:150px; }
.filter-group input:focus, .filter-group select:focus { border-color:#0078d4; outline:none; box-shadow:0 0 0 3px rgba(0,120,212,0.1); }
.btn-filter { padding:9px 18px; background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; display:flex; align-items:center; gap:6px; }
.btn-reset { padding:9px 18px; background:#f5f5f5; color:#666; border:1.5px solid #e0e0e0; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; }
.stats-row { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.stat-card { background:white; border-radius:8px; padding:10px 16px; box-shadow:0 1px 4px rgba(0,0,0,0.06); display:flex; flex-direction:column; gap:2px; border-right:3px solid transparent; min-width:140px; }
.stat-value { font-size:18px; font-weight:700; }
.stat-label { font-size:11px; color:#888; }
.table-card { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; }
.table-header { padding:16px 18px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg,#fafafa,#f5f5f5); }
.table-header h3 { margin:0; font-size:15px; color:#333; display:flex; align-items:center; gap:8px; }
.table-count { background:linear-gradient(135deg,#c50f1f,#e53935); color:white; padding:5px 12px; border-radius:14px; font-size:12px; font-weight:600; }
.report-table { width:100%; border-collapse:collapse; font-size:13px; }
.report-table th { background:#f8f9fa; padding:13px 14px; text-align:right; font-weight:600; color:#333; border-bottom:2px solid #e0e0e0; }
.report-table td { padding:13px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.report-table tr:hover { background:#f9f9f9; }
.btn-icon { width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:8px; border:none; cursor:pointer; transition:all 0.2s; text-decoration:none; font-size:13px; }
.btn-primary-icon { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; }
.btn-icon:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
.btn-collect { padding:7px 16px; background:linear-gradient(135deg,#c50f1f,#e53935); color:white; border:none; border-radius:8px; cursor:pointer; font-size:12px; font-family:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition:all 0.2s; }
.btn-collect:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(197,15,31,0.3); }
.no-data { text-align:center; padding:50px; color:#888; }
.no-data i { font-size:50px; margin-bottom:15px; display:block; color:#ddd; }
.pagination-wrapper { padding:16px 18px; border-top:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fafafa; }
.pagination-info { font-size:13px; color:#666; }
.pagination { display:flex; gap:6px; }
.pagination a, .pagination span { padding:8px 14px; border-radius:8px; font-size:13px; text-decoration:none; }
.pagination a { background:white; color:#0078d4; border:1.5px solid #e0e0e0; }
.pagination a:hover { background:#0078d4; color:white; }
.pagination .active { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border:none; }
.pagination .disabled { background:#f5f5f5; color:#bdbdbd; border:1.5px solid #e0e0e0; cursor:not-allowed; }
</style>

<div class="report-container">

    <!-- Header -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
            <h2>قەرزەکان</h2>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card" style="border-right-color:#c50f1f;">
            <div class="stat-value" style="color:#c50f1f;"><?= number_format($totalDebt) ?> <small style="font-size:12px;font-weight:400;"></small></div>
            <div class="stat-label">کۆی قەرزی ماوە</div>
        </div>
        <div class="stat-card" style="border-right-color:#e67e00;">
            <div class="stat-value" style="color:#e67e00;"><?= number_format($totalCount) ?></div>
            <div class="stat-label">ژمارەی کەیسەکان</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <form method="GET">
            <div class="filters-row">
                <div class="filter-group">
                    <label>گەڕان</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="گەڕان بە ناو، تێلیفۆن، کەیس..." style="min-width:260px;">
                </div>
                <div class="filter-group" style="justify-content:flex-end;">
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> گەڕان</button>
                </div>
                <div class="filter-group" style="justify-content:flex-end;">
                    <a href="<?= APP_URL ?>/accountant/debts.php" class="btn-reset"><i class="fas fa-times"></i> پاکردنەوە</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> لیستی قەرزەکان</h3>
            <span class="table-count"><?= number_format($totalCount) ?> کەیس</span>
        </div>

        <?php if (empty($debtCases)): ?>
        <div class="no-data">
            <i class="fas fa-check-circle" style="color:#107c10;"></i>
            هیچ قەرزێک نییە
        </div>
        <?php else: ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نەخۆش</th>
                    <th>تێلیفۆن</th>
                    <th>دکتۆر</th>
                    <th style="text-align:center;">کۆی گشتی</th>
                    <th style="text-align:center;">وەرگیراو</th>
                    <th style="text-align:center;">ماوە</th>
                    <th style="text-align:center;">کردار</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowNum = ($page - 1) * $perPage + 1; ?>
            <?php foreach ($debtCases as $dc): ?>
            <tr>
                <td style="color:#999;font-size:12px;"><?= $rowNum++ ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($dc['patient_name']) ?></td>
                <td style="font-size:12px;color:#555;"><?= htmlspecialchars($dc['patient_phone'] ?? '—') ?></td>
                <td style="font-size:12px;color:#666;"><?= htmlspecialchars($dc['doctor_name']) ?></td>
                <td style="text-align:center;font-weight:600;"><?= number_format($dc['total_amount']) ?></td>
                <td style="text-align:center;font-weight:600;color:#107c10;"><?= number_format($dc['paid_amount']) ?></td>
                <td style="text-align:center;font-weight:700;color:#c50f1f;"><?= number_format($dc['remaining']) ?></td>
                <td style="text-align:center;">
                    <a href="<?= APP_URL ?>/accountant/report-detail.php?case_id=<?= $dc['id'] ?>" class="btn-collect">
                        <i class="fas fa-money-bill-wave"></i> وەرگرتن
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="pagination-wrapper">
            <div class="pagination-info">
                کۆی <?= number_format($totalCount) ?> کەیس — لاپەڕە <?= $page ?> لە <?= $totalPages ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == $page): ?>
                <span class="active"><?= $i ?></span>
                <?php else: ?>
                <a href="?search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <a href="?search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
