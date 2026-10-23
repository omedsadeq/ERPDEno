<?php
require_once __DIR__ . '/../config/config.php';
/**
 * Accountant - Expenses Management
 * بەڕێوەبردنی خەرجییەکان
 */

$pageTitle = 'خەرجییەکان';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'],
    ['title' => 'خەرجییەکان']
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

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');
$catId    = intval($_GET['category'] ?? 0);
$page     = max(1, intval($_GET['page'] ?? 1));
$perPage  = ITEMS_PER_PAGE;
$offset   = ($page - 1) * $perPage;

// Load categories
$categoriesFromDb = $db->fetchAll("SELECT id, category_name, category_type FROM expense_categories WHERE is_active=1 ORDER BY category_name");
$categories = [];
foreach ($categoriesFromDb as $cat) {
    $categories[$cat['id']] = $cat['category_name'];
}

// Handle add/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    if ($_POST['action'] === 'add') {
        $desc = trim($_POST['description']);
        if (!empty($_POST['notes'])) $desc .= "\n" . trim($_POST['notes']);
        $db->insert('expenses', [
            'category_id'  => intval($_POST['category']),
            'description'  => $desc,
            'amount'       => floatval($_POST['amount']),
            'expense_date' => $_POST['date'],
            'vendor'       => trim($_POST['vendor'] ?? ''),
            'created_by'   => $currentUser['id']
        ]);
        header('Location: ' . APP_URL . '/accountant/expenses.php?added=1');
        exit;
    } elseif ($_POST['action'] === 'delete') {
        $db->delete('expenses', 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/accountant/expenses.php?deleted=1');
        exit;
    }
}

$where  = "DATE(e.expense_date) BETWEEN :df AND :dt";
$params = ['df' => $dateFrom, 'dt' => $dateTo];
if ($catId) {
    $where .= " AND e.category_id = :cat";
    $params['cat'] = $catId;
}

$baseQ      = "FROM expenses e LEFT JOIN expense_categories ec ON e.category_id=ec.id LEFT JOIN users u ON e.created_by=u.id WHERE {$where}";
$totalCount = $db->fetch("SELECT COUNT(*) as cnt {$baseQ}", $params)['cnt'] ?? 0;
$totalAmt   = $db->fetch("SELECT COALESCE(SUM(e.amount),0) as t {$baseQ}", $params)['t'] ?? 0;
$totalPages = max(1, ceil($totalCount / $perPage));

$expenses = $db->fetchAll("SELECT e.*, ec.category_name, u.full_name AS added_by {$baseQ} ORDER BY e.expense_date DESC, e.id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

// Category totals for this period
$catTotals = $db->fetchAll("SELECT ec.category_name, COALESCE(SUM(e.amount),0) as total FROM expenses e JOIN expense_categories ec ON e.category_id=ec.id WHERE DATE(e.expense_date) BETWEEN :df AND :dt GROUP BY ec.id, ec.category_name ORDER BY total DESC", ['df'=>$dateFrom,'dt'=>$dateTo]);

// Today and this month stats
$todayAmt = $db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE DATE(expense_date) = CURDATE()")['t'] ?? 0;
$monthAmt = $db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE())")['t'] ?? 0;

if (isset($_GET['added'])): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ if(typeof showToast==='function') showToast('success', 'خەرجی زیادکرا'); });</script>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ if(typeof showToast==='function') showToast('success', 'خەرجی سڕایەوە'); });</script>
<?php endif; ?>

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
.filter-group input, .filter-group select { padding:9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; min-width:140px; }
.filter-group input:focus, .filter-group select:focus { border-color:var(--win-accent, #0078d4); outline:none; box-shadow:0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1); }
.btn-filter { padding:9px 18px; background:linear-gradient(135deg,var(--win-accent, #0078d4),var(--win-accent-deep, #0063b1)); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; display:flex; align-items:center; gap:6px; }
.btn-reset { padding:9px 18px; background:#f5f5f5; color:#666; border:1.5px solid #e0e0e0; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; }
.btn-success-action { padding:9px 18px; background:linear-gradient(135deg,#107c10,#0e6b0e); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; display:flex; align-items:center; gap:6px; text-decoration:none; }
.stats-row { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
.stat-card { background:white; border-radius:10px; padding:12px 18px; box-shadow:0 1px 6px rgba(0,0,0,0.07); display:flex; flex-direction:column; gap:3px; border-right:3px solid transparent; min-width:150px; flex:1; }
.stat-card.red { border-right-color:#c50f1f; }
.stat-card.orange { border-right-color:#ff8c00; }
.stat-card.blue { border-right-color:var(--win-accent, #0078d4); }
.stat-card.purple { border-right-color:#8764b8; }
.stat-value { font-size:20px; font-weight:700; color:#1a1a1a; }
.stat-label { font-size:11px; color:#888; }
.two-col-layout { display:grid; grid-template-columns:1fr 300px; gap:16px; align-items:start; }
@media (max-width:900px) { .two-col-layout { grid-template-columns:1fr; } }
.table-card { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; margin-bottom:16px; }
.table-header { padding:16px 18px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg,#fafafa,#f5f5f5); }
.table-header h3 { margin:0; font-size:15px; color:#333; display:flex; align-items:center; gap:8px; }
.table-count { background:linear-gradient(135deg,var(--win-accent, #0078d4),var(--win-accent-deep, #0063b1)); color:white; padding:5px 12px; border-radius:14px; font-size:12px; font-weight:600; }
.report-table { width:100%; border-collapse:collapse; font-size:13px; }
.report-table th { background:#f8f9fa; padding:13px 14px; text-align:right; font-weight:600; color:#333; border-bottom:2px solid #e0e0e0; }
.report-table td { padding:13px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.report-table tr:hover { background:#f9f9f9; }
.cat-badge { background:#f0f4ff; color:var(--win-accent, #0078d4); padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; display:inline-block; }
.btn-icon { width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:none; cursor:pointer; transition:all 0.2s; text-decoration:none; font-size:13px; }
.btn-danger-icon { background:linear-gradient(135deg,#c50f1f,#e53935); color:white; }
.btn-icon:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
.actions-cell { display:flex; gap:6px; }
.no-data { text-align:center; padding:50px; color:#888; }
.no-data i { font-size:50px; margin-bottom:15px; display:block; color:#ddd; }
.pagination-wrapper { padding:16px 18px; border-top:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fafafa; }
.pagination-info { font-size:13px; color:#666; }
.pagination { display:flex; gap:6px; }
.pagination a, .pagination span { padding:8px 14px; border-radius:8px; font-size:13px; text-decoration:none; }
.pagination a { background:white; color:var(--win-accent, #0078d4); border:1.5px solid #e0e0e0; }
.pagination a:hover { background:var(--win-accent, #0078d4); color:white; }
.pagination .active { background:linear-gradient(135deg,var(--win-accent, #0078d4),var(--win-accent-deep, #0063b1)); color:white; border:none; padding:8px 14px; }
.pagination .disabled { background:#f5f5f5; color:#bdbdbd; border:1.5px solid #e0e0e0; cursor:not-allowed; }
/* Dialog styles */
.dialog-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; }
.dialog-overlay.open { display:flex; }
.dialog-box { background:white; border-radius:16px; padding:28px; width:90%; max-width:520px; box-shadow:0 20px 60px rgba(0,0,0,0.3); max-height:90vh; overflow-y:auto; }
.dialog-title { font-size:17px; font-weight:700; margin-bottom:20px; display:flex; align-items:center; gap:10px; color:#1a1a1a; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-grid .full { grid-column:1/-1; }
.form-field { display:flex; flex-direction:column; gap:5px; }
.form-field label { font-size:12px; font-weight:600; color:#555; }
.form-field input, .form-field select, .form-field textarea { width:100%; padding:9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; box-sizing:border-box; }
.form-field input:focus, .form-field select:focus, .form-field textarea:focus { border-color:var(--win-accent, #0078d4); outline:none; box-shadow:0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1); }
.dialog-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:16px; border-top:1px solid #f0f0f0; }
.btn-dialog-cancel { padding:9px 18px; background:#f5f5f5; color:#666; border:1.5px solid #e0e0e0; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; }
.btn-dialog-submit { padding:9px 18px; background:linear-gradient(135deg,#107c10,#0e6b0e); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; display:flex; align-items:center; gap:6px; }
/* Category breakdown card */
.cat-breakdown { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; }
.cat-breakdown-header { padding:14px 16px; border-bottom:1px solid #f0f0f0; background:linear-gradient(135deg,#fafafa,#f5f5f5); font-size:14px; font-weight:600; color:#333; display:flex; align-items:center; gap:8px; }
.cat-breakdown-body { padding:8px 0; max-height:420px; overflow-y:auto; }
.cat-row { display:flex; justify-content:space-between; align-items:center; padding:10px 16px; border-bottom:1px solid #f8f8f8; font-size:13px; }
.cat-row:last-child { border-bottom:none; }
.cat-row .name { color:#444; }
.cat-row .amount { font-weight:700; color:#c50f1f; }
</style>

<div class="report-container">

    <!-- Header -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon" style="background:linear-gradient(135deg,#ff8c00,#e67700);">
                <i class="fas fa-receipt"></i>
            </div>
            <h2>خەرجییەکان</h2>
        </div>
        <button class="btn-success-action" onclick="openDialog('expenseDialog')">
            <i class="fas fa-plus"></i> خەرجی نوێ
        </button>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card red">
            <div class="stat-value"><?= number_format($totalAmt) ?> <small style="font-size:12px;font-weight:500;"></small></div>
            <div class="stat-label">کۆی خەرجییەکان (فلتەرکراو)</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?= number_format($todayAmt) ?> <small style="font-size:12px;font-weight:500;"></small></div>
            <div class="stat-label">خەرجی ئەمڕۆ</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?= number_format($monthAmt) ?> <small style="font-size:12px;font-weight:500;"></small></div>
            <div class="stat-label">خەرجی ئەم مانگە</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-value"><?= number_format($totalCount) ?></div>
            <div class="stat-label">ژمارەی خەرجییەکان</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <form method="GET">
            <div class="filters-row">
                <div class="filter-group">
                    <label>لە بەروار</label>
                    <input type="date" name="date_from" value="<?= $dateFrom ?>">
                </div>
                <div class="filter-group">
                    <label>بۆ بەروار</label>
                    <input type="date" name="date_to" value="<?= $dateTo ?>">
                </div>
                <div class="filter-group">
                    <label>کاتەگۆری</label>
                    <select name="category">
                        <option value="">هەموو جۆرەکان</option>
                        <?php foreach ($categories as $cid => $cname): ?>
                        <option value="<?= $cid ?>" <?= $catId===$cid?'selected':'' ?>><?= htmlspecialchars($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> گەڕان
                </button>
                <a href="expenses.php" class="btn-reset">
                    <i class="fas fa-redo"></i> ڕیسێت
                </a>
            </div>
        </form>
    </div>

    <!-- Main content: table + category breakdown -->
    <div class="two-col-layout">

        <!-- Expenses Table -->
        <div>
            <div class="table-card">
                <div class="table-header">
                    <h3><i class="fas fa-receipt" style="color:#ff8c00;"></i> لیستی خەرجییەکان</h3>
                    <span class="table-count"><?= number_format($totalCount) ?> خەرجی</span>
                </div>

                <?php if (empty($expenses)): ?>
                <div class="no-data">
                    <i class="fas fa-receipt"></i>
                    هیچ خەرجییەک نییە
                </div>
                <?php else: ?>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>بەروار</th>
                            <th>بەش</th>
                            <th>کاتەگۆری</th>
                            <th style="text-align:center;">بڕ</th>
                            <th>تێبینی</th>
                            <th style="text-align:center;">کردار</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($expenses as $i => $exp): ?>
                    <tr>
                        <td style="color:#888;font-size:12px;"><?= $offset + $i + 1 ?></td>
                        <td style="font-size:12px;color:#555;white-space:nowrap;"><?= $exp['expense_date'] ?></td>
                        <td style="font-size:12px;color:#555;"><?= htmlspecialchars($exp['added_by'] ?? '—') ?></td>
                        <td><span class="cat-badge"><?= htmlspecialchars($exp['category_name'] ?? '—') ?></span></td>
                        <td style="text-align:center;font-weight:700;color:#c50f1f;"><?= number_format($exp['amount']) ?> <small style="font-weight:400;color:#888;"></small></td>
                        <td style="font-size:12px;color:#555;max-width:180px;">
                            <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($exp['description']) ?>">
                                <?= htmlspecialchars($exp['description']) ?>
                            </div>
                            <?php if (!empty($exp['vendor'])): ?>
                            <div style="font-size:11px;color:#aaa;margin-top:1px;"><?= htmlspecialchars($exp['vendor']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <form method="POST" onsubmit="return confirm('دڵنیایت دەتەوێت بیسڕیتەوە؟');" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                <button type="submit" class="btn-icon btn-danger-icon" title="سڕینەوە">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
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
                        <a href="?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&category=<?= $catId ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-right"></i></a>
                        <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                        <span class="active"><?= $i ?></span>
                        <?php else: ?>
                        <a href="?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&category=<?= $catId ?>&page=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <a href="?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&category=<?= $catId ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-left"></i></a>
                        <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>

        <!-- Category Breakdown -->
        <div class="cat-breakdown">
            <div class="cat-breakdown-header">
                <i class="fas fa-chart-pie" style="color:#8764b8;"></i>
                خەرجی بە جۆر
            </div>
            <div class="cat-breakdown-body">
                <?php if (empty($catTotals)): ?>
                <div style="padding:30px;text-align:center;color:#bbb;font-size:13px;">
                    <i class="fas fa-chart-pie" style="font-size:30px;margin-bottom:8px;display:block;"></i>
                    داتا نییە
                </div>
                <?php else: ?>
                <?php foreach ($catTotals as $ct): ?>
                <div class="cat-row">
                    <span class="name"><?= htmlspecialchars($ct['category_name']) ?></span>
                    <span class="amount"><?= number_format($ct['total']) ?> <small style="font-weight:400;color:#aaa;font-size:11px;"></small></span>
                </div>
                <?php endforeach; ?>
                <div class="cat-row" style="background:#fafafa;border-top:2px solid #e0e0e0;">
                    <span style="font-weight:700;color:#333;">کۆی گشتی</span>
                    <span style="font-weight:700;color:#c50f1f;">
                        <?= number_format(array_sum(array_column($catTotals, 'total'))) ?>
                        <small style="font-weight:400;color:#aaa;font-size:11px;"></small>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- end two-col-layout -->

</div><!-- end report-container -->

<!-- Add Expense Dialog -->
<div class="dialog-overlay" id="expenseDialog">
    <div class="dialog-box">
        <div class="dialog-title">
            <i class="fas fa-plus-circle" style="color:#107c10;"></i>
            خەرجی نوێ زیادبکە
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="form-field">
                    <label>جۆری خەرجی</label>
                    <select name="category" required>
                        <?php foreach ($categories as $cid => $cname): ?>
                        <option value="<?= $cid ?>"><?= htmlspecialchars($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label>بەروار</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-field full">
                    <label>وەسف</label>
                    <input type="text" name="description" required placeholder="وەسفی خەرجی...">
                </div>
                <div class="form-field">
                    <label>بڕ</label>
                    <input type="number" name="amount" min="0" step="1" required placeholder="0">
                </div>
                <div class="form-field">
                    <label>فرۆشگا / سەرچاوە</label>
                    <input type="text" name="vendor" placeholder="ناوی فرۆشگا...">
                </div>
                <div class="form-field full">
                    <label>تێبینی</label>
                    <textarea name="notes" rows="2" placeholder="تێبینییەکان..."></textarea>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn-dialog-cancel" onclick="closeDialog('expenseDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn-dialog-submit"><i class="fas fa-plus"></i> زیادکردن</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
