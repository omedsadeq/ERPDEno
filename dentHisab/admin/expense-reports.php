<?php
/**
 * Expense Reports Page - Comprehensive Reports for All Expense Types
 * ڕاپۆرتی خەرجییەکان - گشتی، تاقیگە، کۆگا
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

// Check admin access
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
if (!$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Page setup
$currentPage = 'expense-reports';
$pageTitle = 'ڕاپۆرتی خەرجییەکان';
$breadcrumb = [['title' => 'خەرجیەکان'], ['title' => 'ڕاپۆرت']];

require_once __DIR__ . '/../includes/header.php';

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$expenseType = $_GET['expense_type'] ?? 'all';
$categoryId = $_GET['category'] ?? '';
$doctorId = $_GET['doctor'] ?? '';
$groupBy = $_GET['group_by'] ?? 'date';

// Load doctors
$doctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('doctor', 'admin') AND is_active = 1 ORDER BY full_name");

// Load categories for all types
$categoriesGeneral = $db->fetchAll("SELECT id, category_name, 'general' as type FROM expense_categories_general WHERE is_active = 1 ORDER BY category_name");
$categoriesLab = $db->fetchAll("SELECT id, category_name, 'lab' as type FROM expense_categories_lab WHERE is_active = 1 ORDER BY category_name");
$categoriesStore = $db->fetchAll("SELECT id, category_name, 'store' as type FROM expense_categories_store WHERE is_active = 1 ORDER BY category_name");

// Build queries based on expense type
$allExpenses = [];
$params = ['from' => $dateFrom, 'to' => $dateTo];

// Helper function to build WHERE clause
function buildWhereClause($baseWhere, $categoryId, $doctorId, &$params, $type) {
    $where = $baseWhere;
    if ($categoryId) {
        $catParam = 'cat_' . $type;
        $where .= " AND e.category_id = :$catParam";
        $params[$catParam] = $categoryId;
    }
    if ($doctorId) {
        $docParam = 'doc_' . $type;
        $where .= " AND e.doctor_id = :$docParam";
        $params[$docParam] = $doctorId;
    }
    return $where;
}

// Get General Expenses
if ($expenseType === 'all' || $expenseType === 'general') {
    $whereGeneral = "e.expense_date BETWEEN :from AND :to";
    if ($categoryId && $expenseType === 'general') {
        $whereGeneral .= " AND e.category_id = :cat_general";
        $params['cat_general'] = $categoryId;
    }
    if ($doctorId) {
        $whereGeneral .= " AND e.doctor_id = :doc_general";
        $params['doc_general'] = $doctorId;
    }

    $sqlGeneral = "SELECT e.*, 'general' as expense_type, 'خەرجی گشتی' as type_label,
                   ec.category_name, u.full_name as created_by_name, d.full_name as doctor_name
                   FROM expenses_general e
                   LEFT JOIN expense_categories_general ec ON e.category_id = ec.id
                   LEFT JOIN users u ON e.created_by = u.id
                   LEFT JOIN users d ON e.doctor_id = d.id
                   WHERE $whereGeneral
                   ORDER BY e.expense_date DESC, e.id DESC";
    $generalExpenses = $db->fetchAll($sqlGeneral, $params);
    $allExpenses = array_merge($allExpenses, $generalExpenses);
}

// Reset params for lab
$params = ['from' => $dateFrom, 'to' => $dateTo];

// Get Lab Expenses
if ($expenseType === 'all' || $expenseType === 'lab') {
    $whereLab = "e.expense_date BETWEEN :from AND :to";
    if ($categoryId && $expenseType === 'lab') {
        $whereLab .= " AND e.category_id = :cat_lab";
        $params['cat_lab'] = $categoryId;
    }
    if ($doctorId) {
        $whereLab .= " AND e.doctor_id = :doc_lab";
        $params['doc_lab'] = $doctorId;
    }

    $sqlLab = "SELECT e.*, 'lab' as expense_type, 'خەرجی تاقیگە' as type_label,
               ec.category_name, u.full_name as created_by_name, d.full_name as doctor_name,
               p.full_name as patient_name
               FROM expenses_lab e
               LEFT JOIN expense_categories_lab ec ON e.category_id = ec.id
               LEFT JOIN users u ON e.created_by = u.id
               LEFT JOIN users d ON e.doctor_id = d.id
               LEFT JOIN patients p ON e.patient_id = p.id
               WHERE $whereLab
               ORDER BY e.expense_date DESC, e.id DESC";
    $labExpenses = $db->fetchAll($sqlLab, $params);
    $allExpenses = array_merge($allExpenses, $labExpenses);
}

// Reset params for store
$params = ['from' => $dateFrom, 'to' => $dateTo];

// Get Store/Inventory Expenses
if ($expenseType === 'all' || $expenseType === 'store') {
    $whereStore = "e.expense_date BETWEEN :from AND :to";
    if ($categoryId && $expenseType === 'store') {
        $whereStore .= " AND e.category_id = :cat_store";
        $params['cat_store'] = $categoryId;
    }
    if ($doctorId) {
        $whereStore .= " AND e.doctor_id = :doc_store";
        $params['doc_store'] = $doctorId;
    }

    $sqlStore = "SELECT e.*, 'store' as expense_type, 'خەرجی کۆگا' as type_label,
                 ec.category_name, u.full_name as created_by_name, d.full_name as doctor_name
                 FROM expenses_store e
                 LEFT JOIN expense_categories_store ec ON e.category_id = ec.id
                 LEFT JOIN users u ON e.created_by = u.id
                 LEFT JOIN users d ON e.doctor_id = d.id
                 WHERE $whereStore
                 ORDER BY e.expense_date DESC, e.id DESC";
    $storeExpenses = $db->fetchAll($sqlStore, $params);
    $allExpenses = array_merge($allExpenses, $storeExpenses);
}

// Sort all expenses by date
usort($allExpenses, function($a, $b) {
    return strcmp($b['expense_date'], $a['expense_date']);
});

// Calculate statistics
$totalAll = array_sum(array_column($allExpenses, 'amount'));
$countAll = count($allExpenses);

// Get totals by type
$params = ['from' => $dateFrom, 'to' => $dateTo];
$totalGeneral = $db->fetch("SELECT COALESCE(SUM(amount), 0) as total FROM expenses_general WHERE expense_date BETWEEN :from AND :to", $params)['total'];
$totalLab = $db->fetch("SELECT COALESCE(SUM(amount), 0) as total FROM expenses_lab WHERE expense_date BETWEEN :from AND :to", $params)['total'];
$totalStore = $db->fetch("SELECT COALESCE(SUM(amount), 0) as total FROM expenses_store WHERE expense_date BETWEEN :from AND :to", $params)['total'];
$grandTotal = $totalGeneral + $totalLab + $totalStore;

// Get counts by type (bound params — never interpolate request values into SQL)
$countGeneral = $db->count('expenses_general', "expense_date BETWEEN :from AND :to", $params);
$countLab = $db->count('expenses_lab', "expense_date BETWEEN :from AND :to", $params);
$countStore = $db->count('expenses_store', "expense_date BETWEEN :from AND :to", $params);

// Get statistics by category for each type
$statsByTypeAndCategory = [];

// General stats
$statsGeneral = $db->fetchAll(
    "SELECT ec.category_name, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_general e
     LEFT JOIN expense_categories_general ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
    $params
);

// Lab stats
$statsLab = $db->fetchAll(
    "SELECT ec.category_name, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_lab e
     LEFT JOIN expense_categories_lab ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
    $params
);

// Store stats
$statsStore = $db->fetchAll(
    "SELECT ec.category_name, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_store e
     LEFT JOIN expense_categories_store ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
    $params
);

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

@media (max-width: 768px) {
    .report-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .type-cards-grid { grid-template-columns: 1fr; }
    .report-filter-group { min-width: 100%; }
}
</style>

<!-- Statistics Cards -->
<div class="report-stats-grid">
    <div class="report-stat-card" style="--card-color: #2196f3;">
        <div class="report-stat-header">
            <div class="report-stat-icon" style="background: #2196f3;"><i class="fas fa-chart-line"></i></div>
            <div class="report-stat-title">کۆی گشتی خەرجییەکان</div>
        </div>
        <div class="report-stat-value"><?= number_format($grandTotal) ?></div>
        <div class="report-stat-count"> - <?= $countGeneral + $countLab + $countStore ?> تۆمار</div>
    </div>

    <div class="report-stat-card" style="--card-color: #4caf50;">
        <div class="report-stat-header">
            <div class="report-stat-icon" style="background: #4caf50;"><i class="fas fa-coins"></i></div>
            <div class="report-stat-title">خەرجی گشتی</div>
        </div>
        <div class="report-stat-value"><?= number_format($totalGeneral) ?></div>
        <div class="report-stat-count"><?= $countGeneral ?> تۆمار</div>
    </div>

    <div class="report-stat-card" style="--card-color: #9c27b0;">
        <div class="report-stat-header">
            <div class="report-stat-icon" style="background: #9c27b0;"><i class="fas fa-flask"></i></div>
            <div class="report-stat-title">خەرجی تاقیگە</div>
        </div>
        <div class="report-stat-value"><?= number_format($totalLab) ?></div>
        <div class="report-stat-count"><?= $countLab ?> تۆمار</div>
    </div>

    <div class="report-stat-card" style="--card-color: #ff9800;">
        <div class="report-stat-header">
            <div class="report-stat-icon" style="background: #ff9800;"><i class="fas fa-warehouse"></i></div>
            <div class="report-stat-title">خەرجی کۆگا</div>
        </div>
        <div class="report-stat-value"><?= number_format($totalStore) ?></div>
        <div class="report-stat-count"><?= $countStore ?> تۆمار</div>
    </div>

    <div class="report-stat-card" style="--card-color: #e91e63;">
        <div class="report-stat-header">
            <div class="report-stat-icon" style="background: #e91e63;"><i class="fas fa-calendar-alt"></i></div>
            <div class="report-stat-title">ئەم مانگە</div>
        </div>
        <div class="report-stat-value"><?= number_format($thisMonth) ?></div>
        <?php if ($lastMonth > 0): ?>
        <div class="report-stat-change <?= $monthlyChange > 0 ? 'up' : 'down' ?>">
            <i class="fas fa-arrow-<?= $monthlyChange > 0 ? 'up' : 'down' ?>"></i>
            <?= abs(round($monthlyChange, 1)) ?>% لە مانگی پێشوو
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="report-filters">
    <div class="report-filters-header">
        <h3><i class="fas fa-filter" style="color: #2196f3;"></i> فلتەرەکان</h3>
        <a href="<?= APP_URL ?>/admin/print-expense-report.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-info">
            <i class="fas fa-print"></i> چاپکردن
        </a>
    </div>
    <form method="GET" class="report-filters-row">
        <div class="report-filter-group">
            <label>لە بەروار</label>
            <input type="date" name="date_from" value="<?= $dateFrom ?>">
        </div>
        <div class="report-filter-group">
            <label>بۆ بەروار</label>
            <input type="date" name="date_to" value="<?= $dateTo ?>">
        </div>
        <div class="report-filter-group">
            <label>جۆری خەرجی</label>
            <select name="expense_type" onchange="updateCategoryList(this.value)">
                <option value="all" <?= $expenseType === 'all' ? 'selected' : '' ?>>هەموو جۆرەکان</option>
                <option value="general" <?= $expenseType === 'general' ? 'selected' : '' ?>>خەرجی گشتی</option>
                <option value="lab" <?= $expenseType === 'lab' ? 'selected' : '' ?>>خەرجی تاقیگە</option>
                <option value="store" <?= $expenseType === 'store' ? 'selected' : '' ?>>خەرجی کۆگا</option>
            </select>
        </div>
        <div class="report-filter-group">
            <label>گرووپ</label>
            <select name="category" id="categorySelect">
                <option value="">هەموو گرووپەکان</option>
                <?php if ($expenseType === 'all' || $expenseType === 'general'): ?>
                <optgroup label="گشتی">
                    <?php foreach ($categoriesGeneral as $cat): ?>
                    <option value="<?= $cat['id'] ?>" data-type="general" <?= ($categoryId == $cat['id'] && $expenseType === 'general') ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if ($expenseType === 'all' || $expenseType === 'lab'): ?>
                <optgroup label="تاقیگە">
                    <?php foreach ($categoriesLab as $cat): ?>
                    <option value="<?= $cat['id'] ?>" data-type="lab" <?= ($categoryId == $cat['id'] && $expenseType === 'lab') ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if ($expenseType === 'all' || $expenseType === 'store'): ?>
                <optgroup label="کۆگا">
                    <?php foreach ($categoriesStore as $cat): ?>
                    <option value="<?= $cat['id'] ?>" data-type="store" <?= ($categoryId == $cat['id'] && $expenseType === 'store') ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
            </select>
        </div>
        <div class="report-filter-group">
            <label>دکتۆر</label>
            <select name="doctor">
                <option value="">هەموو دکتۆرەکان</option>
                <?php foreach ($doctors as $doc): ?>
                <option value="<?= $doc['id'] ?>" <?= $doctorId == $doc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="report-filter-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> گەڕان</button>
            <a href="<?= APP_URL ?>/admin/expense-reports.php" class="btn btn-secondary"><i class="fas fa-redo"></i></a>
        </div>
    </form>
</div>

<!-- Type Summary Cards -->
<div class="type-cards-grid">
    <a href="?expense_type=general&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="type-card <?= $expenseType === 'general' ? 'active' : '' ?>" style="--type-color: #4caf50;">
        <div class="type-card-header">
            <div class="type-card-icon" style="background: #4caf50;"><i class="fas fa-coins"></i></div>
            <div class="type-card-info">
                <div class="type-card-title">خەرجی گشتی</div>
                <div class="type-card-count"><?= $countGeneral ?> تۆمار</div>
            </div>
        </div>
        <div class="type-card-value"><?= number_format($totalGeneral) ?> <small></small></div>
        <div class="type-card-percent"><?= $grandTotal > 0 ? round(($totalGeneral / $grandTotal) * 100, 1) : 0 ?>% لە کۆی گشتی</div>
        <div class="progress-bar-container">
            <div class="progress-bar"><div class="progress-bar-fill" style="width: <?= $grandTotal > 0 ? ($totalGeneral / $grandTotal) * 100 : 0 ?>%; background: #4caf50;"></div></div>
        </div>
    </a>

    <a href="?expense_type=lab&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="type-card <?= $expenseType === 'lab' ? 'active' : '' ?>" style="--type-color: #9c27b0;">
        <div class="type-card-header">
            <div class="type-card-icon" style="background: #9c27b0;"><i class="fas fa-flask"></i></div>
            <div class="type-card-info">
                <div class="type-card-title">خەرجی تاقیگە</div>
                <div class="type-card-count"><?= $countLab ?> تۆمار</div>
            </div>
        </div>
        <div class="type-card-value"><?= number_format($totalLab) ?> <small></small></div>
        <div class="type-card-percent"><?= $grandTotal > 0 ? round(($totalLab / $grandTotal) * 100, 1) : 0 ?>% لە کۆی گشتی</div>
        <div class="progress-bar-container">
            <div class="progress-bar"><div class="progress-bar-fill" style="width: <?= $grandTotal > 0 ? ($totalLab / $grandTotal) * 100 : 0 ?>%; background: #9c27b0;"></div></div>
        </div>
    </a>

    <a href="?expense_type=store&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="type-card <?= $expenseType === 'store' ? 'active' : '' ?>" style="--type-color: #ff9800;">
        <div class="type-card-header">
            <div class="type-card-icon" style="background: #ff9800;"><i class="fas fa-warehouse"></i></div>
            <div class="type-card-info">
                <div class="type-card-title">خەرجی کۆگا</div>
                <div class="type-card-count"><?= $countStore ?> تۆمار</div>
            </div>
        </div>
        <div class="type-card-value"><?= number_format($totalStore) ?> <small></small></div>
        <div class="type-card-percent"><?= $grandTotal > 0 ? round(($totalStore / $grandTotal) * 100, 1) : 0 ?>% لە کۆی گشتی</div>
        <div class="progress-bar-container">
            <div class="progress-bar"><div class="progress-bar-fill" style="width: <?= $grandTotal > 0 ? ($totalStore / $grandTotal) * 100 : 0 ?>%; background: #ff9800;"></div></div>
        </div>
    </a>
</div>

<!-- Main Content Grid -->
<div class="report-content-grid">
    <!-- Table -->
    <div class="report-table-container">
        <div class="report-table-header">
            <h3><i class="fas fa-list" style="color: #2196f3;"></i> لیستی خەرجییەکان</h3>
            <span class="badge"><?= count($allExpenses) ?></span>
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
                        <th>#</th>
                        <th>بەروار</th>
                        <th>جۆر</th>
                        <th>گرووپ</th>
                        <th>وەسف</th>
                        <th>دکتۆر</th>
                        <th>بڕ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; $displayedTotal = 0; ?>
                    <?php foreach ($allExpenses as $exp): $displayedTotal += $exp['amount']; ?>
                    <tr>
                        <td><?= $counter++ ?></td>
                        <td style="white-space: nowrap;"><?= $exp['expense_date'] ?></td>
                        <td><span class="type-badge <?= $exp['expense_type'] ?>"><?= $exp['type_label'] ?></span></td>
                        <td><?= htmlspecialchars($exp['category_name'] ?? '-') ?></td>
                        <td style="text-align: right; max-width: 200px;"><?= htmlspecialchars($exp['description']) ?></td>
                        <td><?= htmlspecialchars($exp['doctor_name'] ?? '-') ?></td>
                        <td><strong style="color: #c62828;"><?= number_format($exp['amount']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" style="text-align: right;">کۆی گشتی:</td>
                        <td><strong><?= number_format($displayedTotal) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary Panel -->
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
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Links -->
        <div class="summary-card">
            <div class="summary-card-header" style="background: linear-gradient(135deg, #607d8b, #455a64);">
                <i class="fas fa-link"></i>
                <h4>بەستەرە خێراکان</h4>
            </div>
            <div class="summary-card-body">
                <a href="<?= APP_URL ?>/admin/expenses-general.php" class="btn btn-outline-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-coins"></i> خەرجی گشتی</a>
                <a href="<?= APP_URL ?>/admin/expenses-lab.php" class="btn btn-outline-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-flask"></i> خەرجی تاقیگە</a>
                <a href="<?= APP_URL ?>/admin/expenses-inventory.php" class="btn btn-outline-secondary btn-block"><i class="fas fa-warehouse"></i> خەرجی کۆگا</a>
            </div>
        </div>
    </div>
</div>

<script>
// Chart data
const dailyData = <?= json_encode($dailyTotals) ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
