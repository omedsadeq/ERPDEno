<?php
/**
 * Expense Reports Page - Comprehensive Reports for All Expense Types
 * ڕاپۆرتی خەرجییەکان - گشتی، تاقیگە، کۆگا
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../includes/expense-subgroup.php';
require_once __DIR__ . '/../includes/expense-report-filter.php';

$db = Database::getInstance();
$auth = new Auth();

// Check admin access
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
if (!Permission::getInstance()->can('page.expense_reports')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Changing the page from itself (JSON): a row edited or deleted — the same
// rules as the income sheet's dialog (includes/income-detail.php: permission,
// a line owned by another screen, the month close, the audit log) — or the
// list arranged («ڕێکخستنی پەڕە»).
require_once __DIR__ . '/../includes/income-detail.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!Csrf::validate()) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'کاتی پەڕەکە بەسەرچووە، پەڕەکە نوێ بکەرەوە'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid = (int)($currentUser['id'] ?? 0);
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'layout_save') {
            exp_report_layout_save(json_decode((string)($_POST['layout'] ?? ''), true) ?: [], $uid);
            $message = 'ڕێکخستنی پەڕە پاشەکەوت کرا';
        } elseif ($action === 'layout_reset') {
            exp_report_layout_reset($uid);
            $message = 'پەڕەکە گەڕایەوە بۆ شێوەی بنەڕەت';
        } elseif ($action === 'expense_save' || $action === 'expense_delete') {
            $message = income_detail_expense_write($db, ['full' => 1] + $_POST, $uid);
        } else {
            throw new InvalidArgumentException('کردارەکە نەناسرایەوە');
        }
        echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $known = $e instanceof InvalidArgumentException || $e instanceof DomainException || $e instanceof PeriodClosedException;
        if (!$known) {
            error_log('expense-reports: ' . $e->getMessage());
        }
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => $known ? $e->getMessage() : 'هەڵەیەک ڕوویدا، دووبارە هەوڵ بدەرەوە'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Page setup
$currentPage = 'expense-reports';
$pageTitle = 'ڕاپۆرتی خەرجییەکان';
$hidePageHeader = true;   // the finance tabs name the page
$breadcrumb = [['title' => 'خەرجیەکان'], ['title' => 'ڕاپۆرت']];

require_once __DIR__ . '/../includes/header.php';

// The filters (includes/expense-report-filter.php, shared with the PDF): the
// period, the type, a group or sub-group («لق») of any kind, the doctor.
$f = exp_report_filters($_GET);
$dateFrom = $f['from'];
$dateTo = $f['to'];
$expenseType = $f['type'];
$doctorId = $f['doctor'];

$doctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('doctor', 'admin') AND is_active = 1 ORDER BY full_name");
$categoriesByKind = [];
foreach (EXP_REPORT_CATEGORY_TABLES as $kind => $table) {
    $categoriesByKind[$kind] = $db->fetchAll("SELECT id, category_name FROM $table WHERE is_active = 1 ORDER BY category_name");
}
$subgroups = $db->fetchAll('SELECT id, kind, category_id, name FROM expense_subgroups WHERE is_active = 1 ORDER BY kind, category_id, name');
// The edit dialog: every group of each kind (a hidden one too, or editing such
// an expense would lose it), the sub-groups, and the doctors an expense can be on.
$editCats = [];
foreach (EXP_REPORT_CATEGORY_TABLES as $kind => $table) {
    $editCats[$kind] = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['category_name'] . (!$c['is_active'] ? ' (ناچالاک)' : '')],
        $db->fetchAll("SELECT id, category_name, is_active FROM $table ORDER BY is_active DESC, category_name"));
}
$editSubs = array_map(fn($s) => ['id' => (int)$s['id'], 'kind' => $s['kind'], 'cat' => (int)$s['category_id'], 'name' => $s['name']],
    $db->fetchAll('SELECT id, kind, category_id, name FROM expense_subgroups ORDER BY is_active DESC, name'));
$editDoctors = array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['full_name']],
    $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' ORDER BY is_active DESC, full_name"));
// How the list is laid out (includes/expense-report-layout.php)
$layout = exp_report_layout();
$shownCols = exp_report_visible_columns($layout);
$shownKeys = array_column($shownCols, 'key');
$canEditKind = ['general' => income_detail_can_edit_expense('general'), 'lab' => income_detail_can_edit_expense('lab'), 'store' => income_detail_can_edit_expense('store')];

// What the filters keep
$allExpenses = exp_report_list($f);
$totals = exp_report_totals($f);   // each kind, under every filter but the type
$totalGeneral = $totals['general']['total'];
$totalLab = $totals['lab']['total'];
$totalStore = $totals['store']['total'];
$grandTotal = $totalGeneral + $totalLab + $totalStore;
$countGeneral = $totals['general']['count'];
$countLab = $totals['lab']['count'];
$countStore = $totals['store']['count'];
$totalAll = array_sum(array_column($allExpenses, 'amount'));
$countAll = count($allExpenses);
$statsGeneral = exp_report_stats('general', $f);
$statsLab = exp_report_stats('lab', $f);
$statsStore = exp_report_stats('store', $f);
$subgroupTotals = [];
foreach (array_keys(EXP_REPORT_KINDS) as $kind) {
    $subgroupTotals[$kind] = exp_report_subgroup_totals($kind, $f);
}
$params = ['from' => $dateFrom, 'to' => $dateTo];

// Get daily totals for chart
$dailyTotals = [];
$dailyGeneral = $db->fetchAll(
    "SELECT expense_date, SUM(amount) as total FROM expenses_general
     WHERE expense_date BETWEEN :from AND :to GROUP BY expense_date",
    $params
);
$dailyLab = $db->fetchAll(
    "SELECT expense_date, SUM(amount) as total FROM expenses_lab
     WHERE expense_date BETWEEN :from AND :to GROUP BY expense_date",
    $params
);
$dailyStore = $db->fetchAll(
    "SELECT expense_date, SUM(amount) as total FROM expenses_store
     WHERE expense_date BETWEEN :from AND :to GROUP BY expense_date",
    $params
);

// Combine daily totals
foreach ($dailyGeneral as $d) { $dailyTotals[$d['expense_date']]['general'] = $d['total']; }
foreach ($dailyLab as $d) { $dailyTotals[$d['expense_date']]['lab'] = $d['total']; }
foreach ($dailyStore as $d) { $dailyTotals[$d['expense_date']]['store'] = $d['total']; }
ksort($dailyTotals);

// Get monthly comparison
$thisMonth = $db->fetch("SELECT
    (SELECT COALESCE(SUM(amount),0) FROM expenses_general WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())) +
    (SELECT COALESCE(SUM(amount),0) FROM expenses_lab WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())) +
    (SELECT COALESCE(SUM(amount),0) FROM expenses_store WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())) as total
")['total'];

$lastMonth = $db->fetch("SELECT
    (SELECT COALESCE(SUM(amount),0) FROM expenses_general WHERE MONTH(expense_date)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(expense_date)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) +
    (SELECT COALESCE(SUM(amount),0) FROM expenses_lab WHERE MONTH(expense_date)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(expense_date)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) +
    (SELECT COALESCE(SUM(amount),0) FROM expenses_store WHERE MONTH(expense_date)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(expense_date)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) as total
")['total'];

$monthlyChange = $lastMonth > 0 ? (($thisMonth - $lastMonth) / $lastMonth) * 100 : 0;

// Type labels and colors
$typeConfig = [
    'general' => ['label' => 'خەرجی گشتی', 'color' => '#4caf50', 'icon' => 'fas fa-coins'],
    'lab' => ['label' => 'خەرجی تاقیگە', 'color' => '#9c27b0', 'icon' => 'fas fa-flask'],
    'store' => ['label' => 'خەرجی کۆگا', 'color' => '#ff9800', 'icon' => 'fas fa-warehouse']
];
?>

<style>
/* Statistics Grid */
.report-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
.report-stat-card { background: white; border-radius: 10px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e0e0e0; position: relative; overflow: hidden; }
.report-stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--card-color, #4caf50); }
.report-stat-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.report-stat-icon { width: 40px; height: 40px; border-radius: 10px; background: var(--card-color, #4caf50); color: white; display: flex; align-items: center; justify-content: center; font-size: 16px; }
.report-stat-title { font-size: 12px; color: #666; font-weight: 600; }
.report-stat-value { font-size: 24px; font-weight: 700; color: #1a1a1a; margin-bottom: 4px; }
.report-stat-change { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 6px; border-radius: 10px; }
.report-stat-change.up { background: #ffebee; color: #c62828; }
.report-stat-change.down { background: #e8f5e9; color: #2e7d32; }
.report-stat-count { font-size: 11px; color: #888; }

/* Filters */
.report-filters { background: white; border-radius: 10px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e0e0e0; }
.report-filters-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
.report-filters-header h3 { margin: 0; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; color: #333; }
.report-filters-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.report-filter-group { flex: 1; min-width: 140px; }
.report-filter-group label { display: block; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; }
.report-filter-group input, .report-filter-group select { width: 100%; padding: 8px 10px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 12px; }
.report-filter-group input:focus, .report-filter-group select:focus { border-color: #2196f3; box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.1); outline: none; }
.report-filter-actions { display: flex; gap: 8px; }

/* Type Cards */
.type-cards-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
.type-card { background: white; border-radius: 10px; padding: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e0e0e0; cursor: pointer; transition: all 0.2s; }
.type-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
.type-card.active { border-color: var(--type-color); box-shadow: 0 0 0 2px var(--type-color); }
.type-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.type-card-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; }
.type-card-info { flex: 1; }
.type-card-title { font-size: 12px; font-weight: 600; color: #333; }
.type-card-count { font-size: 10px; color: #888; }
.type-card-value { font-size: 20px; font-weight: 700; color: #1a1a1a; }
.type-card-percent { font-size: 11px; color: #888; margin-top: 2px; }

/* Content Grid */
.report-content-grid { display: grid; grid-template-columns: 1fr 320px; gap: 16px; }
@media (max-width: 1024px) { .report-content-grid { grid-template-columns: 1fr; } }

/* Table Container */
.report-table-container { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e0e0e0; }
.report-table-header { padding: 12px 16px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; }
.report-table-header h3 { margin: 0; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.report-table { width: 100%; border-collapse: collapse; }
.report-table thead { background: linear-gradient(to bottom, #f8f9fa, #f1f3f5); }
.report-table th { padding: 10px 8px; text-align: center; font-size: 11px; font-weight: 700; color: #333; border-bottom: 2px solid #e0e0e0; }
.report-table td { padding: 8px; text-align: center; font-size: 11px; color: #444; border-bottom: 1px solid #f0f0f0; }
.report-table tbody tr:hover { background: #f8f9fa; }
.report-table tfoot { background: linear-gradient(to bottom, #e8f5e9, #c8e6c9); }
.report-table tfoot td { padding: 10px 8px; font-weight: 700; font-size: 12px; border-top: 2px solid #4caf50; }

/* Type Badge */
.type-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; }
.type-badge.general { background: #e8f5e9; color: #2e7d32; }
.type-badge.lab { background: #f3e5f5; color: #7b1fa2; }
.type-badge.store { background: #fff3e0; color: #ef6c00; }

/* Summary Panel */
.summary-panel { display: flex; flex-direction: column; gap: 12px; }
.summary-card { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e0e0e0; }
.summary-card-header { padding: 10px 14px; color: white; display: flex; align-items: center; gap: 8px; }
.summary-card-header h4 { margin: 0; font-size: 12px; font-weight: 600; }
.summary-card-body { padding: 12px 14px; }
.summary-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
.summary-item:last-child { border-bottom: none; }
.summary-item-label { font-size: 11px; color: #555; display: flex; align-items: center; gap: 6px; }
.summary-item-value { font-size: 12px; font-weight: 700; color: #1a1a1a; }

/* Chart Container */
.chart-container { padding: 16px; min-height: 200px; }

/* Progress Bar */
.progress-bar-container { margin-top: 8px; }
.progress-bar { height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; margin-bottom: 6px; }
.progress-bar-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
.progress-labels { display: flex; justify-content: space-between; font-size: 10px; color: #888; }

/* compact: one line of figures, one line of filters */
.er-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.er-chip { position: relative; flex: 1 1 170px; display: grid; grid-template-columns: auto 1fr; grid-template-rows: auto auto; column-gap: 8px;
    align-items: center; padding: 8px 12px 10px; border-radius: 10px; background: #fff; border: 1px solid #e2e8f0; border-top: 3px solid var(--c);
    color: inherit; text-decoration: none; overflow: hidden; }
.er-chip > i:first-child { grid-row: span 2; width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; background: var(--c); color: #fff; font-size: 13px; }
.er-chip span { font-size: 11.5px; color: #64748b; font-weight: 700; }
.er-chip b { grid-column: 2; font-size: 17px; color: #0f172a; line-height: 1.2; }
.er-chip small { grid-column: 2; font-size: 10.5px; color: #94a3b8; }
.er-chip small.up { color: #c62828; } .er-chip small.down { color: #2e7d32; }
.er-chip .er-bar { position: absolute; inset: auto auto 0 0; height: 3px; background: var(--c); opacity: .55; }
a.er-chip:hover { border-color: var(--c); }
.er-chip.on { box-shadow: 0 0 0 2px var(--c); }
.er-chip > span, .er-chip > b, .er-chip > small { grid-column: 2; }
.er-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px; margin-bottom: 10px; padding: 10px 12px; background: #fff;
    border: 1px solid #e2e8f0; border-radius: 10px; }
.er-filters label { flex: 1 1 130px; min-width: 0; margin: 0; display: flex; flex-direction: column; gap: 3px; }
.er-filters label span { font-size: 11px; font-weight: 700; color: #64748b; }
.er-filters input, .er-filters select { width: 100%; height: 34px; padding: 0 8px; border: 1px solid #dbe3ea; border-radius: 7px; font-size: 12.5px; background: #fff; }
.er-acts { display: flex; gap: 6px; }
.er-acts .btn { height: 34px; padding: 0 12px; display: inline-flex; align-items: center; gap: 6px; }
.report-content-grid { gap: 10px; }
.summary-panel { gap: 8px; }
.summary-card-header { padding: 7px 12px; }
.summary-card-body { padding: 6px 12px; }
.summary-item { padding: 4px 0; }
.report-table-header { padding: 8px 12px; }
.report-table th { padding: 7px 6px; }
.report-table td { padding: 5px 6px; }

/* sorting, the row buttons, «ڕێکخستنی پەڕە» */
.report-content-grid.no-summary { grid-template-columns: 1fr; }
.report-table-header h3 .badge { margin-inline-start: 6px; }
.er-tools { display: flex; gap: 6px; flex-wrap: wrap; }
.er-tool { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 11px; border-radius: 8px; border: 1px solid #bae6fd; background: #f0f9ff;
    color: #0369a1; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; }
.er-tool:hover { background: #0284c7; border-color: #0284c7; color: #fff; }
.report-table th a.er-sort { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
.report-table th a.er-sort i { color: #cbd5e1; font-size: 10px; }
.report-table th.er-sorted { background: #e0f2fe; }
.report-table th.er-sorted a.er-sort i { color: #0284c7; }
.report-table th a.er-sort:hover { color: #0284c7; }
.report-table td.er-act, .report-table th.er-act-h { width: 66px; white-space: nowrap; }
.er-btn { width: 26px; height: 26px; padding: 0; border-radius: 7px; border: 1px solid #bae6fd; background: #f0f9ff; color: #0369a1; cursor: pointer; font-size: 11px; display: inline-grid; place-items: center; }
.er-btn:hover { background: #0284c7; border-color: #0284c7; color: #fff; }
.er-btn.del { border-color: #fecaca; background: #fef2f2; color: #dc2626; }
.er-btn.del:hover { background: #dc2626; border-color: #dc2626; color: #fff; }
.er-lock { color: #94a3b8; cursor: help; }

@media (max-width: 768px) {
    .report-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .type-cards-grid { grid-template-columns: 1fr; }
    .report-filter-group { min-width: 100%; }
}
</style>

<!-- One line of figures: each kind under the filters (a chip filters by it), and this month -->
<?php $chipQ = fn(string $type) => exp_report_query($f, ['expense_type' => $type] + ($f['cat_kind'] !== null && $f['cat_kind'] !== $type ? ['category' => '', 'subgroup' => ''] : [])); ?>
<?php if ($layout['panels']['chips']): ?>
<div class="er-chips">
    <a class="er-chip<?= $expenseType === 'all' ? ' on' : '' ?>" style="--c:#2196f3" href="?<?= htmlspecialchars($chipQ('all')) ?>">
        <i class="fas fa-chart-line"></i><span>کۆی گشتی</span><b><?= number_format($grandTotal) ?></b><small><?= $countGeneral + $countLab + $countStore ?> تۆمار</small></a>
    <?php foreach (['general' => ['#4caf50', 'fa-coins', $totalGeneral, $countGeneral], 'lab' => ['#9c27b0', 'fa-flask', $totalLab, $countLab], 'store' => ['#ff9800', 'fa-warehouse', $totalStore, $countStore]] as $k => [$color, $icon, $sum, $n]): ?>
    <a class="er-chip<?= $expenseType === $k ? ' on' : '' ?>" style="--c:<?= $color ?>" href="?<?= htmlspecialchars($chipQ($k)) ?>">
        <i class="fas <?= $icon ?>"></i><span><?= EXP_REPORT_KINDS[$k] ?></span><b><?= number_format($sum) ?></b>
        <small><?= $n ?> تۆمار · <?= $grandTotal > 0 ? round($sum / $grandTotal * 100, 1) : 0 ?>%</small>
        <i class="er-bar" style="width:<?= $grandTotal > 0 ? round($sum / $grandTotal * 100, 1) : 0 ?>%"></i></a>
    <?php endforeach; ?>
    <span class="er-chip month" style="--c:#e91e63"><i class="fas fa-calendar-alt"></i><span>ئەم مانگە</span><b><?= number_format($thisMonth) ?></b>
        <?php if ($lastMonth > 0): ?><small class="<?= $monthlyChange > 0 ? 'up' : 'down' ?>"><i class="fas fa-arrow-<?= $monthlyChange > 0 ? 'up' : 'down' ?>"></i> <?= abs(round($monthlyChange, 1)) ?>% لە مانگی پێشوو</small><?php endif; ?></span>
</div>
<?php endif; ?>

<!-- Filters, on one line -->
<form method="GET" class="er-filters" id="erFilters">
    <?php if ($f['sort'] !== ''): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($f['sort']) ?>"><input type="hidden" name="dir" value="<?= htmlspecialchars($f['dir']) ?>"><?php endif; ?>
    <label><span>لە</span><input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
    <label><span>بۆ</span><input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
    <label><span>جۆر</span>
        <select name="expense_type" id="erType">
            <option value="all">هەموو جۆرەکان</option>
            <?php foreach (EXP_REPORT_KINDS as $k => $label): ?><option value="<?= $k ?>" <?= $expenseType === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select></label>
    <label><span>گرووپ</span>
        <select name="category" id="erCat">
            <option value="">هەموو گرووپەکان</option>
            <?php foreach (['general' => 'گشتی', 'lab' => 'تاقیگە', 'store' => 'کۆگا'] as $k => $label): ?>
            <optgroup label="<?= $label ?>" data-kind="<?= $k ?>">
                <?php foreach ($categoriesByKind[$k] as $cat): $v = $k . ':' . $cat['id']; ?>
                <option value="<?= $v ?>" <?= $f['cat_kind'] === $k && $f['cat'] === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
        </select></label>
    <label><span>لق</span>
        <select name="subgroup" id="erSub">
            <option value="">هەموو لقەکان</option>
            <?php foreach ($subgroups as $sg): ?>
            <option value="<?= (int)$sg['id'] ?>" data-cat="<?= htmlspecialchars($sg['kind'] . ':' . $sg['category_id']) ?>" <?= $f['sub'] === (int)$sg['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sg['name']) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label><span>دکتۆر</span>
        <select name="doctor">
            <option value="">هەموو دکتۆرەکان</option>
            <option value="none" <?= $doctorId === 'none' ? 'selected' : '' ?>>خەرجی سەنتەر (بێ دکتۆر)</option>
            <option value="xray" <?= $doctorId === 'xray' ? 'selected' : '' ?>>X-ray (بەشی ئێکسڕەی)</option>
            <?php foreach ($doctors as $doc): ?>
            <option value="<?= $doc['id'] ?>" <?= $doctorId === (string)$doc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
            <?php endforeach; ?>
        </select></label>
    <div class="er-acts">
        <button type="submit" class="btn btn-primary" title="گەڕان"><i class="fas fa-search"></i></button>
        <a href="<?= APP_URL ?>/admin/expense-reports.php" class="btn btn-secondary" title="پاککردنەوەی فلتەرەکان"><i class="fas fa-redo"></i></a>
        <a href="<?= APP_URL ?>/admin/print-expense-report.php?<?= htmlspecialchars(exp_report_query($f)) ?>" target="_blank" class="btn btn-info" title="ڕاپۆرتەکە بە هەمان فلتەرەکان، وەک PDF"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
</form>

<!-- Main Content Grid -->
<div class="report-content-grid<?= $layout['panels']['summary'] ? '' : ' no-summary' ?>">
    <!-- Table -->
    <div class="report-table-container">
        <div class="report-table-header">
            <h3><i class="fas fa-list" style="color: #2196f3;"></i> لیستی خەرجییەکان <span class="badge"><?= count($allExpenses) ?></span></h3>
            <div class="er-tools">
                <?php if ($f['sort'] !== ''): ?>
                <a class="er-tool" href="?<?= htmlspecialchars(exp_report_query($f, ['sort' => '', 'dir' => ''])) ?>" title="ڕیزبەندی بنەڕەت: نوێترین سەرەتا"><i class="fas fa-arrow-down-wide-short"></i> ڕیزبەندی بنەڕەت</a>
                <?php endif; ?>
                <button type="button" class="er-tool" onclick="erArrangeOpen()" title="کۆڵۆمەکان: ڕیزکردن، ناوگۆڕین، شاردنەوە"><i class="fas fa-sliders"></i> ڕێکخستنی پەڕە</button>
            </div>
        </div>
        <?php if (empty($allExpenses)): ?>
        <div style="padding: 60px 20px; text-align: center;">
            <i class="fas fa-search" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
            <p style="color: #888; margin: 0;">هیچ خەرجییەک نەدۆزرایەوە</p>
        </div>
        <?php else: ?>
        <div class="table-container" style="max-height: 500px; overflow-y: auto;">
            <table class="report-table">
                <thead>
                    <tr>
                        <?php foreach ($shownCols as $c):
                            $k = $c['key'];
                            $sortable = EXP_REPORT_COLUMNS[$k][2];
                            $on = $f['sort'] === $k || ($f['sort'] === '' && $k === 'date');
                            $dir = $f['sort'] === $k ? $f['dir'] : 'desc';
                        ?>
                        <th<?= $on ? ' class="er-sorted"' : '' ?>><?php if ($sortable): ?><a class="er-sort" href="?<?= htmlspecialchars(exp_report_query($f, ['sort' => $k, 'dir' => $on && $dir === 'desc' ? 'asc' : 'desc'])) ?>" title="ڕیزکردن بەپێی <?= htmlspecialchars($c['label']) ?>"><?= htmlspecialchars($c['label']) ?> <i class="fas fa-<?= $on ? ($dir === 'asc' ? 'sort-up' : 'sort-down') : 'sort' ?>"></i></a><?php else: ?><?= htmlspecialchars($c['label']) ?><?php endif; ?></th>
                        <?php endforeach; ?>
                        <th class="er-act-h"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; $displayedTotal = 0; ?>
                    <?php foreach ($allExpenses as $exp):
                        $displayedTotal += $exp['amount'];
                        $kind = $exp['expense_type'];
                        $lock = income_detail_expense_lock($exp['source_type'] ?? null);
                        $ref = ['kind' => $kind, 'id' => (int)$exp['id'], 'type' => $exp['type_label'], 'date' => $exp['expense_date'], 'amount' => (float)$exp['amount'],
                                'desc' => (string)($exp['description'] ?? ''), 'cat' => (int)($exp['category_id'] ?? 0), 'sub' => (int)($exp['subgroup_id'] ?? 0),
                                'doctor' => !empty($exp['doctor_id']) ? (string)(int)$exp['doctor_id'] : (($exp['dept'] ?? null) === 'xray' ? 'xray' : ''),
                                'vendor' => exp_report_value('vendor', $exp), 'receipt' => exp_report_value('receipt', $exp), 'notes' => (string)($exp['notes'] ?? ''),
                                'method' => (string)($exp['payment_method'] ?? ''), 'xkind' => (string)($exp['expense_kind'] ?? '')];
                    ?>
                    <tr data-exp="<?= htmlspecialchars(json_encode($ref, JSON_UNESCAPED_UNICODE)) ?>">
                        <?php foreach ($shownKeys as $k): ?>
                        <?php if ($k === 'num'): ?><td><?= $counter++ ?></td>
                        <?php elseif ($k === 'date'): ?><td style="white-space: nowrap;"><?= htmlspecialchars($exp['expense_date']) ?></td>
                        <?php elseif ($k === 'type'): ?><td><span class="type-badge <?= $kind ?>"><?= $exp['type_label'] ?></span></td>
                        <?php elseif ($k === 'group'): ?><td><?= htmlspecialchars(in_array('sub', $shownKeys, true) ? ($exp['category_name'] ?? '-') : expense_group_label($exp)) ?></td>
                        <?php elseif ($k === 'desc'): ?><td style="text-align: right; max-width: 240px;"><?= htmlspecialchars($exp['description']) ?></td>
                        <?php elseif ($k === 'doctor'): ?><td><?php if (!empty($exp['doctor_name'])): ?><?= htmlspecialchars($exp['doctor_name']) ?><?php elseif (($exp['dept'] ?? null) === 'xray'): ?><span style="color:#0369a1;font-weight:600">X-ray</span><?php else: ?><span style="color:#0f766e;font-weight:600">خەرجی سەنتەر</span><?php endif; ?></td>
                        <?php elseif ($k === 'amount'): ?><td><strong style="color: #c62828;"><?= number_format($exp['amount']) ?></strong></td>
                        <?php elseif ($k === 'notes'): ?><td style="text-align: right; max-width: 200px; white-space: pre-line;"><?= htmlspecialchars(exp_report_value($k, $exp)) ?></td>
                        <?php else: ?><td><?= htmlspecialchars((string)exp_report_value($k, $exp)) ?></td>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <td class="er-act">
                            <?php if ($lock !== null): ?><span class="er-lock" title="<?= htmlspecialchars($lock) ?>"><i class="fas fa-lock"></i></span>
                            <?php elseif ($canEditKind[$kind] ?? false): ?>
                            <button type="button" class="er-btn" onclick="erEdit(this)" title="دەستکاریکردن"><i class="fas fa-pen"></i></button>
                            <button type="button" class="er-btn del" onclick="erDelete(this)" title="سڕینەوە"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <?php $labelAt = array_key_first(array_filter($shownKeys, fn($k) => $k !== 'amount')); ?>
                        <?php foreach ($shownKeys as $n => $k): ?>
                        <?php if ($k === 'amount'): ?><td><strong><?= number_format($displayedTotal) ?></strong></td>
                        <?php elseif ($n === $labelAt): ?><td style="text-align: right; white-space: nowrap;">کۆی گشتی:</td>
                        <?php else: ?><td></td><?php endif; ?>
                        <?php endforeach; ?>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary Panel -->
    <?php expense_subgroup_style(); ?>
    <?php if ($layout['panels']['summary']): ?>
    <div class="summary-panel">
        <!-- General Summary -->
        <?php if (!empty($statsGeneral)): ?>
        <div class="summary-card">
            <div class="summary-card-header" style="background: linear-gradient(135deg, #4caf50, #388e3c);">
                <i class="fas fa-coins"></i>
                <h4>پوختەی گشتی</h4>
            </div>
            <div class="summary-card-body">
                <?php foreach (array_slice($statsGeneral, 0, 5) as $st): ?>
                <div class="summary-item">
                    <span class="summary-item-label"><?= htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') ?></span>
                    <span class="summary-item-value"><?= number_format($st['total']) ?></span>
                </div>
                <?= expense_subgroup_summary($subgroupTotals['general'][(int)$st['category_id']] ?? []) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lab Summary -->
        <?php if (!empty($statsLab)): ?>
        <div class="summary-card">
            <div class="summary-card-header" style="background: linear-gradient(135deg, #9c27b0, #7b1fa2);">
                <i class="fas fa-flask"></i>
                <h4>پوختەی تاقیگە</h4>
            </div>
            <div class="summary-card-body">
                <?php foreach (array_slice($statsLab, 0, 5) as $st): ?>
                <div class="summary-item">
                    <span class="summary-item-label"><?= htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') ?></span>
                    <span class="summary-item-value"><?= number_format($st['total']) ?></span>
                </div>
                <?= expense_subgroup_summary($subgroupTotals['lab'][(int)$st['category_id']] ?? []) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Store Summary -->
        <?php if (!empty($statsStore)): ?>
        <div class="summary-card">
            <div class="summary-card-header" style="background: linear-gradient(135deg, #ff9800, #f57c00);">
                <i class="fas fa-warehouse"></i>
                <h4>پوختەی کۆگا</h4>
            </div>
            <div class="summary-card-body">
                <?php foreach (array_slice($statsStore, 0, 5) as $st): ?>
                <div class="summary-item">
                    <span class="summary-item-label"><?= htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') ?></span>
                    <span class="summary-item-value"><?= number_format($st['total']) ?></span>
                </div>
                <?= expense_subgroup_summary($subgroupTotals['store'][(int)$st['category_id']] ?? []) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_expense_report_dialogs.php'; ?>

<script>
(function () {
    var type = document.getElementById('erType'), cat = document.getElementById('erCat'), sub = document.getElementById('erSub');
    if (!type || !cat || !sub) return;
    function sync() {
        var t = type.value, c = cat.value;
        // groups of the chosen type only; a group of another type is let go
        [].forEach.call(cat.querySelectorAll('optgroup'), function (g) { g.hidden = t !== 'all' && g.dataset.kind !== t; });
        if (c && t !== 'all' && c.indexOf(t + ':') !== 0) { cat.value = ''; c = ''; }
        // sub-groups of the chosen group only; with no group, all of the type
        var any = false;
        [].forEach.call(sub.options, function (o) {
            if (!o.value) return;
            var show = c ? o.dataset.cat === c : (t === 'all' || o.dataset.cat.indexOf(t + ':') === 0);
            o.hidden = !show;
            if (show) any = true;
            if (!show && o.selected) sub.value = '';
        });
        sub.disabled = !any;
    }
    type.addEventListener('change', sync);
    cat.addEventListener('change', sync);
    sync();
})();
// Chart data
const dailyData = <?= json_encode($dailyTotals) ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
