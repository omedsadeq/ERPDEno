<?php
/**
 * Store/Inventory Expenses Management Page (Admin Only)
 * خەرجی کۆگا - مەواد، ئامێر، دەرمان، هتد
 * سیستەمی زیرەکی کلینیکی ددان
 */

// Load config and classes first for POST handling
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();
require_once __DIR__ . '/../classes/Permission.php';
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.expenses_inventory')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Date filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// ئاسایی / ئیمپلانت — every store expense has a kind. Rows written by a
// «مەواد بۆ دکتۆر» voucher (source_type erp_doctor_issue) belong to that
// voucher: they change only by cancelling it in the store, never here, or the
// expense and the stock that left would stop agreeing.
$expenseKinds = ['normal' => 'ئاسایی', 'implant' => 'ئیمپلانت'];
$postedKind = in_array($_POST['expense_kind'] ?? '', ['normal', 'implant'], true) ? $_POST['expense_kind'] : 'normal';
$lockedRow = function ($id) use ($db) {
    $row = $db->fetch("SELECT source_type FROM expenses_store WHERE id = :id", ['id' => (int)$id]);
    return $row && !empty($row['source_type']);
};

// Handle POST actions BEFORE including header (which outputs HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if (in_array($action, ['edit', 'delete'], true) && $lockedRow($_POST['expense_id'] ?? 0)) {
        header('Location: ' . APP_URL . '/admin/expenses-inventory.php?locked=1&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo));
        exit;
    }

    if ($action === 'add') {
        $quantity = intval($_POST['quantity']) ?: 1;
        $unitPrice = floatval($_POST['unit_price']) ?: 0;
        $amount = floatval($_POST['amount']) ?: ($quantity * $unitPrice);

        $db->insert('expenses_store', [
            'category_id' => intval($_POST['category_id']) ?: null,
            'expense_kind' => $postedKind,
            'doctor_id' => !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null,
            'description' => trim($_POST['description']),
            'amount' => $amount,
            'expense_date' => $_POST['expense_date'],
            'supplier' => trim($_POST['supplier'] ?? ''),
            'invoice_number' => trim($_POST['invoice_number'] ?? ''),
            'quantity' => $quantity,
            'unit' => trim($_POST['unit'] ?? ''),
            'unit_price' => $unitPrice,
            'payment_status' => $_POST['payment_status'] ?? 'paid',
            'notes' => trim($_POST['notes'] ?? ''),
            'created_by' => $currentUser['id']
        ]);
        header('Location: ' . APP_URL . '/admin/expenses-inventory.php?added=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    } elseif ($action === 'edit') {
        $quantity = intval($_POST['quantity']) ?: 1;
        $unitPrice = floatval($_POST['unit_price']) ?: 0;
        $amount = floatval($_POST['amount']) ?: ($quantity * $unitPrice);

        $db->update('expenses_store', [
            'category_id' => intval($_POST['category_id']) ?: null,
            'expense_kind' => $postedKind,
            'doctor_id' => !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null,
            'description' => trim($_POST['description']),
            'amount' => $amount,
            'expense_date' => $_POST['expense_date'],
            'supplier' => trim($_POST['supplier'] ?? ''),
            'invoice_number' => trim($_POST['invoice_number'] ?? ''),
            'quantity' => $quantity,
            'unit' => trim($_POST['unit'] ?? ''),
            'unit_price' => $unitPrice,
            'payment_status' => $_POST['payment_status'] ?? 'paid',
            'notes' => trim($_POST['notes'] ?? '')
        ], 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/admin/expenses-inventory.php?updated=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    } elseif ($action === 'delete') {
        $db->delete('expenses_store', 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/admin/expenses-inventory.php?deleted=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    }
}

// Now include header (safe to output HTML)
$currentPage = 'expenses-inventory';
$pageTitle = 'خەرجی کۆگا';
$breadcrumb = [['title' => 'خەرجیەکان'], ['title' => 'خەرجی کۆگا']];

require_once __DIR__ . '/../includes/header.php';

$categoryId = $_GET['category'] ?? '';
$paymentStatus = $_GET['payment_status'] ?? '';
$kindFilter = isset($expenseKinds[$_GET['kind'] ?? '']) ? $_GET['kind'] : '';
$doctorFilter = (int)($_GET['doctor'] ?? 0);

// Load expense categories
$categories = $db->fetchAll("SELECT * FROM expense_categories_store WHERE is_active = 1 ORDER BY sort_order, category_name");

// Load doctors list
$doctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('doctor', 'admin') AND is_active = 1 ORDER BY full_name");

// Build query — one WHERE for the list, the kind totals and the group summary,
// so every figure on the page answers the same filter.
$where = "e.expense_date BETWEEN :from AND :to";
$params = ['from' => $dateFrom, 'to' => $dateTo];

if ($categoryId) {
    $where .= " AND e.category_id = :category";
    $params['category'] = $categoryId;
}

if ($paymentStatus) {
    $where .= " AND e.payment_status = :payment_status";
    $params['payment_status'] = $paymentStatus;
}

if ($kindFilter !== '') {
    $where .= " AND e.expense_kind = :kind";
    $params['kind'] = $kindFilter;
}

if ($doctorFilter > 0) {
    $where .= " AND e.doctor_id = :doctor";
    $params['doctor'] = $doctorFilter;
}

$expenses = $db->fetchAll(
    "SELECT e.*, u.full_name as created_by_name, d.full_name as doctor_name, ec.category_name, ec.icon, ec.color
     FROM expenses_store e
     LEFT JOIN users u ON e.created_by = u.id
     LEFT JOIN users d ON e.doctor_id = d.id
     LEFT JOIN expense_categories_store ec ON e.category_id = ec.id
     WHERE {$where}
     ORDER BY e.expense_date DESC, e.id DESC",
    $params
);

// Get statistics by category. Voucher rows have no group of their own; they
// are summed under «مەواد بۆ دکتۆر» rather than «بێ گرووپ».
$stats = $db->fetchAll(
    "SELECT COALESCE(ec.category_name, CASE WHEN e.source_type = 'erp_doctor_issue' THEN 'مەواد بۆ دکتۆر' END) AS category_name,
            COALESCE(ec.icon, CASE WHEN e.source_type = 'erp_doctor_issue' THEN 'fas fa-hand-holding-medical' END) AS icon,
            COALESCE(ec.color, CASE WHEN e.source_type = 'erp_doctor_issue' THEN '#0369a1' END) AS color,
            SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_store e
     LEFT JOIN expense_categories_store ec ON e.category_id = ec.id
     WHERE {$where}
     GROUP BY e.category_id, ec.category_name, ec.icon, ec.color, (e.source_type = 'erp_doctor_issue')
     ORDER BY total DESC",
    $params
);

$totalExpenses = array_sum(array_column($expenses, 'amount'));
$expenseCount = count($expenses);
$kindTotals = ['normal' => 0.0, 'implant' => 0.0];
foreach ($expenses as $exp) {
    $kindTotals[$exp['expense_kind'] ?? 'normal'] = ($kindTotals[$exp['expense_kind'] ?? 'normal'] ?? 0) + (float)$exp['amount'];
}
$printUrl = APP_URL . '/admin/print-expenses.php?type=store&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo)
    . ($categoryId ? '&category=' . (int)$categoryId : '')
    . ($kindFilter !== '' ? '&kind=' . $kindFilter : '')
    . ($doctorFilter > 0 ? '&doctor=' . $doctorFilter : '');

// Payment status labels
$paymentStatuses = [
    'paid' => ['label' => 'دراوە', 'color' => '#4caf50'],
    'pending' => ['label' => 'چاوەڕوان', 'color' => '#ff9800'],
    'partial' => ['label' => 'بەشێک', 'color' => '#2196f3']
];
?>

<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی کۆگا زیادکرا</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی کۆگا نوێکرایەوە</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی کۆگا سڕایەوە</div>
<?php endif; ?>

<?php if (isset($_GET['locked'])): ?>
<div class="alert alert-warning"><i class="fas fa-lock alert-icon"></i> ئەم خەرجییە لە وەسڵی «مەواد بۆ دکتۆر» هاتووە. بۆ گۆڕین یان سڕینەوە، وەسڵەکە لە کۆگا هەڵبوەشێنەوە.</div>
<?php endif; ?>

<style>
.expense-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 12px; }
.expense-stat-card { background: white; border-radius: 8px; padding: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; }
.expense-stat-card::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 3px; background: var(--card-color, #ff9800); }
.expense-stat-icon { width: 36px; height: 36px; border-radius: 8px; background: var(--card-color, #ff9800); color: white; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.expense-stat-content { flex: 1; min-width: 0; }
.expense-stat-value { font-size: 18px; font-weight: 700; color: #1a1a1a; line-height: 1.2; }
.expense-stat-label { font-size: 11px; color: #666; font-weight: 500; }
.expense-filters { background: white; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.expense-filters-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.expense-filter-group { flex: 1; min-width: 120px; }
.expense-filter-group label { display: block; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; }
.expense-filter-group input, .expense-filter-group select { width: 100%; padding: 6px 10px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 12px; }
.expense-filter-group input:focus, .expense-filter-group select:focus { border-color: #ff9800; box-shadow: 0 0 0 2px rgba(255, 152, 0, 0.1); outline: none; }
.expense-filter-actions { display: flex; gap: 6px; }
.expense-table-container { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.expense-table { width: 100%; border-collapse: collapse; }
.expense-table thead { background: linear-gradient(to bottom, #f8f9fa, #f1f3f5); }
.expense-table th { padding: 10px 8px; text-align: center; font-size: 12px; font-weight: 700; color: #333; border-bottom: 2px solid #e0e0e0; }
.expense-table td { padding: 8px; text-align: center; font-size: 12px; color: #444; border-bottom: 1px solid #f0f0f0; }
.expense-table tbody tr:hover { background: #f8f9fa; }
.expense-table tfoot { background: linear-gradient(to bottom, #f1f3f5, #e8e8e8); }
.expense-table tfoot td { padding: 10px 8px; font-weight: 700; font-size: 13px; border-top: 2px solid #d0d0d0; }
.category-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.status-badge { display: inline-flex; align-items: center; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; }
.expense-summary { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.expense-summary-header { padding: 10px 14px; background: linear-gradient(135deg, #ff9800, #f57c00); color: white; }
.expense-summary-header h4 { margin: 0; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.expense-summary-body { padding: 10px 14px; }
.expense-summary-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
.expense-summary-item:last-child { border-bottom: none; }
.expense-summary-item-label { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #555; }
.expense-summary-item-label i { width: 20px; height: 20px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: white; }
.expense-summary-item-value { font-size: 12px; font-weight: 700; color: #1a1a1a; }
/* Dialog - Appointments Style */
.exp-dialog-modern { max-width: 520px; width: 96%; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); background: #fff; max-height: 92vh; display: flex; flex-direction: column; }
.exp-dialog-header { background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.exp-dialog-header h4 { margin: 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.exp-dialog-close { background: rgba(255,255,255,0.15); border: none; color: white; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; font-size: 12px; }
.exp-dialog-close:hover { background: rgba(255,255,255,0.25); }
.exp-dialog-body { padding: 14px 16px; overflow-y: auto; flex: 1; }
.exp-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.exp-form-field { display: flex; flex-direction: column; gap: 4px; }
.exp-form-field.exp-field-full { grid-column: 1 / -1; }
.exp-form-field label { font-size: 11px; font-weight: 600; color: #546e7a; display: flex; align-items: center; gap: 5px; }
.exp-form-field label i { color: #ff9800; font-size: 10px; }
.exp-form-field label .required { color: #f44336; font-size: 10px; }
.exp-form-field input, .exp-form-field select, .exp-form-field textarea { padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 12px; font-family: inherit; transition: all 0.2s; background: #fafafa; }
.exp-form-field input:focus, .exp-form-field select:focus, .exp-form-field textarea:focus { border-color: #ff9800; box-shadow: 0 0 0 2px rgba(255, 152, 0, 0.1); outline: none; background: #fff; }
.exp-form-field textarea { resize: vertical; min-height: 50px; }
.exp-dialog-footer { padding: 10px 16px; background: #f5f7f9; border-top: 1px solid #e0e0e0; display: flex; gap: 8px; flex-direction: row-reverse; flex-shrink: 0; }
.exp-btn-primary, .exp-btn-secondary { padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: all 0.2s; border: none; font-family: inherit; }
.exp-btn-primary { background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white; }
.exp-btn-primary:hover { background: linear-gradient(135deg, #f57c00 0%, #ef6c00 100%); }
.exp-btn-secondary { background: white; color: #546e7a; border: 1.5px solid #e0e0e0; }
.exp-btn-secondary:hover { background: #f5f5f5; border-color: #bdbdbd; }
/* ئاسایی / ئیمپلانت */
.exp-kind-seg { display: inline-flex; padding: 3px; background: #f1f5f9; border-radius: 8px; }
.exp-kind-opt input, .exp-kind-pick input { position: absolute; opacity: 0; pointer-events: none; }
.exp-kind-opt span { display: inline-block; padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; color: #64748b; cursor: pointer; white-space: nowrap; }
.exp-kind-opt input:checked + span { background: #fff; color: #0f172a; box-shadow: 0 1px 2px rgba(0,0,0,.1); }
.exp-kind-opt.is-normal input:checked + span { color: #0d9488; }
.exp-kind-opt.is-implant input:checked + span { color: #7c3aed; }
.exp-kind-opt input:focus-visible + span, .exp-kind-pick input:focus-visible + span { outline: 2px solid #ff9800; }
.exp-kind-badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 800; white-space: nowrap; }
.exp-kind-badge.is-normal { background: #ccfbf1; color: #0f766e; }
.exp-kind-badge.is-implant { background: #ede9fe; color: #6d28d9; }
.exp-kind-pick { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.exp-kind-pick span { display: flex; align-items: center; justify-content: center; gap: 7px; padding: 9px; border: 1.5px solid #e0e0e0;
    border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 700; color: #546e7a; background: #fafafa; }
.exp-kind-pick .is-normal input:checked + span { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
.exp-kind-pick .is-implant input:checked + span { border-color: #7c3aed; color: #7c3aed; background: #f5f3ff; }
.exp-locked { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #0369a1; background: #e0f2fe; padding: 3px 8px; border-radius: 6px; }
@media (max-width: 768px) { .expense-stats-grid { grid-template-columns: repeat(2, 1fr); } .exp-form-grid { grid-template-columns: 1fr; } .exp-form-field.exp-field-full { grid-column: 1; } .expense-filter-group { min-width: 100%; } }
</style>

<!-- Statistics Cards -->
<div class="expense-stats-grid">
    <div class="expense-stat-card" style="--card-color: #ff9800;">
        <div class="expense-stat-icon" style="background: #ff9800;"><i class="fas fa-warehouse"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= number_format($totalExpenses) ?></div><div class="expense-stat-label">کۆی خەرجی</div></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #2196f3;">
        <div class="expense-stat-icon" style="background: #2196f3;"><i class="fas fa-list"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= $expenseCount ?></div><div class="expense-stat-label">تۆمار</div></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #0d9488;">
        <div class="expense-stat-icon" style="background: #0d9488;"><i class="fas fa-box-open"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= number_format($kindTotals['normal']) ?></div><div class="expense-stat-label">ئاسایی</div></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #7c3aed;">
        <div class="expense-stat-icon" style="background: #7c3aed;"><i class="fas fa-tooth"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= number_format($kindTotals['implant']) ?></div><div class="expense-stat-label">ئیمپلانت</div></div>
    </div>
</div>

<!-- Filters -->
<div class="expense-filters">
    <form method="GET" class="expense-filters-row">
        <div class="expense-filter-group">
            <label>گرووپ</label>
            <select name="category">
                <option value="">هەموو</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="expense-filter-group" style="flex: 0 0 auto;">
            <label>جۆری خەرجی</label>
            <div class="exp-kind-seg" role="radiogroup" aria-label="جۆری خەرجی">
                <?php foreach (['' => 'هەموو'] + $expenseKinds as $k => $label): ?>
                <label class="exp-kind-opt is-<?= $k ?: 'all' ?>"><input type="radio" name="kind" value="<?= $k ?>" <?= $kindFilter === $k ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= $label ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="expense-filter-group">
            <label>دکتۆر</label>
            <select name="doctor">
                <option value="">هەموو</option>
                <?php foreach ($doctors as $doc): ?>
                <option value="<?= (int)$doc['id'] ?>" <?= $doctorFilter === (int)$doc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="expense-filter-group">
            <label>دۆخی پارەدان</label>
            <select name="payment_status">
                <option value="">هەموو</option>
                <?php foreach ($paymentStatuses as $key => $status): ?>
                <option value="<?= $key ?>" <?= $paymentStatus === $key ? 'selected' : '' ?>><?= $status['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="expense-filter-group">
            <label>لە بەروار</label>
            <input type="date" name="date_from" value="<?= $dateFrom ?>">
        </div>
        <div class="expense-filter-group">
            <label>بۆ بەروار</label>
            <input type="date" name="date_to" value="<?= $dateTo ?>">
        </div>
        <div class="expense-filter-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> پاڵاوتن</button>
            <a href="<?= htmlspecialchars($printUrl) ?>" target="_blank" class="btn btn-info" title="چاپ"><i class="fas fa-print"></i></a>
            <button type="button" class="btn btn-success" onclick="openDialog('addExpenseDialog')"><i class="fas fa-plus"></i> خەرجی نوێ</button>
        </div>
    </form>
</div>

<div class="collapsible-sidebar-grid" style="display: grid; grid-template-columns: 1fr 280px; gap: 12px;">
    <!-- Expenses Table -->
    <div class="expense-table-container">
        <div style="padding: 10px 14px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px;"><i class="fas fa-warehouse" style="color: #ff9800;"></i> خەرجییەکانی کۆگا</h3>
            <span class="badge"><?= $expenseCount ?></span>
        </div>
        <?php if (empty($expenses)): ?>
        <div style="padding: 60px 20px; text-align: center;"><i class="fas fa-warehouse" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i><p style="color: #888; margin: 0;">هیچ خەرجییەک نییە</p></div>
        <?php else: ?>
        <div class="table-container">
            <table class="expense-table">
                <thead><tr><th>بەروار</th><th>جۆر</th><th>گرووپ</th><th>دکتۆر</th><th>وەسف</th><th>بڕ/یەکە</th><th>دۆخ</th><th>کۆ</th><th>کردار</th></tr></thead>
                <tbody>
                    <?php foreach ($expenses as $exp):
                        $fromVoucher = ($exp['source_type'] ?? '') === 'erp_doctor_issue';
                        $kindKey = $exp['expense_kind'] ?? 'normal'; ?>
                    <tr>
                        <td style="white-space: nowrap;"><?= $exp['expense_date'] ?></td>
                        <td><span class="exp-kind-badge is-<?= htmlspecialchars($kindKey) ?>"><?= $expenseKinds[$kindKey] ?? $kindKey ?></span></td>
                        <td>
                            <?php if ($exp['category_name']): ?>
                            <span class="category-badge" style="background: <?= $exp['color'] ?? '#ff9800' ?>15; color: <?= $exp['color'] ?? '#ff9800' ?>;"><i class="<?= $exp['icon'] ?? 'fas fa-box' ?>"></i> <?= htmlspecialchars($exp['category_name']) ?></span>
                            <?php elseif ($fromVoucher): ?>
                            <span class="category-badge" style="background: #0369a115; color: #0369a1;"><i class="fas fa-hand-holding-medical"></i> مەواد بۆ دکتۆر</span>
                            <?php else: ?><span class="text-secondary">-</span><?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;"><?= !empty($exp['doctor_name']) ? htmlspecialchars($exp['doctor_name']) : '<span class="text-secondary">-</span>' ?></td>
                        <td style="text-align: right; max-width: 220px;">
                            <div style="font-weight: 500;"><?= htmlspecialchars($exp['description']) ?></div>
                            <?php if ($fromVoucher && !empty($exp['notes'])): ?><small style="color: #888; display: block;"><?= htmlspecialchars(mb_strimwidth($exp['notes'], 0, 120, '…')) ?></small><?php endif; ?>
                            <?php if (!empty($exp['supplier'])): ?><small style="color: #888;"><i class="fas fa-truck"></i> <?= htmlspecialchars($exp['supplier']) ?></small><?php endif; ?>
                            <?php if ($fromVoucher): ?>
                            <a href="<?= APP_URL ?>/erp/print-doctor-issue.php?id=<?= (int)$exp['source_id'] ?>&autoprint=0" target="_blank" style="font-size: 11px; display: block;"><i class="fas fa-file-medical"></i> <span dir="ltr"><?= htmlspecialchars($exp['invoice_number']) ?></span></a>
                            <?php elseif (!empty($exp['invoice_number'])): ?><small style="color: #888; display: block;"><i class="fas fa-file-invoice"></i> #<?= htmlspecialchars($exp['invoice_number']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$fromVoucher && $exp['quantity'] > 0): ?>
                            <small><?= $exp['quantity'] ?> <?= $exp['unit'] ?: 'دانە' ?><?php if ($exp['unit_price'] > 0): ?><br>@ <?= number_format($exp['unit_price']) ?><?php endif; ?></small>
                            <?php else: ?><span class="text-secondary">-</span><?php endif; ?>
                        </td>
                        <td><?php $ps = $paymentStatuses[$exp['payment_status']] ?? $paymentStatuses['paid']; ?><span class="status-badge" style="background: <?= $ps['color'] ?>15; color: <?= $ps['color'] ?>;"><?= $ps['label'] ?></span></td>
                        <td><strong style="color: #c62828;"><?= number_format($exp['amount']) ?></strong></td>
                        <td style="white-space: nowrap;">
                            <?php if ($fromVoucher): ?>
                            <span class="exp-locked" title="لە وەسڵی مەواد بۆ دکتۆر هاتووە — لە کۆگا هەڵیبوەشێنەوە"><i class="fas fa-lock"></i> کۆگا</span>
                            <?php else: ?>
                            <button class="btn btn-sm btn-secondary" onclick='editExpense(<?= json_encode($exp, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display: inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="expense_id" value="<?= $exp['id'] ?>"><button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('ئایا دڵنیایت؟')"><i class="fas fa-trash"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7" style="text-align: right; font-weight: 700;">کۆی گشتی:</td><td colspan="2" style="color: #c62828;"><?= number_format($totalExpenses) ?></td></tr></tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary Panel -->
    <div>
        <div class="expense-summary">
            <div class="expense-summary-header"><h4><i class="fas fa-chart-pie"></i> پوختەی گرووپەکان</h4></div>
            <div class="expense-summary-body">
                <?php if (empty($stats)): ?><p style="text-align: center; color: #888; margin: 20px 0;">هیچ داتایەک نییە</p>
                <?php else: foreach ($stats as $st): ?>
                <div class="expense-summary-item">
                    <div class="expense-summary-item-label"><i style="background: <?= $st['color'] ?? '#ff9800' ?>;" class="<?= $st['icon'] ?? 'fas fa-box' ?>"></i><span><?= htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') ?></span></div>
                    <div class="expense-summary-item-value"><?= number_format($st['total']) ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="expense-summary" style="margin-top: 16px;">
            <div class="expense-summary-header" style="background: linear-gradient(135deg, #607d8b, #455a64);"><h4><i class="fas fa-link"></i> بەستەرەکان</h4></div>
            <div class="expense-summary-body" style="padding: 12px;">
                <a href="<?= APP_URL ?>/admin/expense-groups.php?type=store" class="btn btn-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-folder"></i> بەڕێوەبردنی گرووپەکان</a>
                <a href="<?= APP_URL ?>/admin/expenses-general.php" class="btn btn-outline-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-coins"></i> خەرجی گشتی</a>
                <a href="<?= APP_URL ?>/admin/expenses-lab.php" class="btn btn-outline-secondary btn-block"><i class="fas fa-flask"></i> خەرجی تاقیگە</a>
            </div>
        </div>
    </div>
</div>

<!-- Add Expense Dialog -->
<div class="dialog-overlay" id="addExpenseDialog">
    <div class="dialog exp-dialog-modern">
        <div class="exp-dialog-header">
            <h4><i class="fas fa-plus-circle"></i> زیادکردنی خەرجی کۆگا</h4>
            <button type="button" class="exp-dialog-close" onclick="closeDialog('addExpenseDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="exp-dialog-body">
                <div class="exp-form-field exp-field-full" style="margin-bottom: 10px;">
                    <label><i class="fas fa-layer-group"></i> جۆری خەرجی <span class="required">*</span></label>
                    <div class="exp-kind-pick">
                        <label class="is-normal"><input type="radio" name="expense_kind" value="normal" checked><span><i class="fas fa-box-open"></i> ئاسایی</span></label>
                        <label class="is-implant"><input type="radio" name="expense_kind" value="implant"><span><i class="fas fa-tooth"></i> ئیمپلانت</span></label>
                    </div>
                </div>
                <div class="exp-form-grid">
                    <div class="exp-form-field">
                        <label><i class="fas fa-folder"></i> گرووپ <span class="required">*</span></label>
                        <select name="category_id" required><option value="">هەڵبژێرە...</option><?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-user-md"></i> دکتۆر</label>
                        <select name="doctor_id"><option value="">هەڵبژێرە...</option><?php foreach ($doctors as $doc): ?><option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['full_name']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-file-alt"></i> وەسف <span class="required">*</span></label>
                        <input type="text" name="description" required placeholder="ناوی مەواد/شت">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-cubes"></i> بڕ (ژمارە)</label>
                        <input type="number" name="quantity" min="1" value="1" onchange="calcTotal()">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-balance-scale"></i> یەکە</label>
                        <input type="text" name="unit" placeholder="دانە، پاکەت...">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-tag"></i> نرخی یەکە</label>
                        <input type="number" name="unit_price" id="add_unit_price" min="0" step="0.01" onchange="calcTotal()">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-money-bill"></i> کۆی گشتی <span class="required">*</span></label>
                        <input type="number" name="amount" id="add_amount" required min="0" step="0.01">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-check-circle"></i> دۆخی پارەدان</label>
                        <select name="payment_status"><?php foreach ($paymentStatuses as $key => $status): ?><option value="<?= $key ?>"><?= $status['label'] ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-truck"></i> فرۆشیار/کۆمپانیا</label>
                        <input type="text" name="supplier">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-file-invoice"></i> ژمارەی پسوولە</label>
                        <input type="text" name="invoice_number">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-sticky-note"></i> تێبینی</label>
                        <textarea name="notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="exp-dialog-footer">
                <button type="submit" class="exp-btn-primary"><i class="fas fa-check"></i> زیادکردن</button>
                <button type="button" class="exp-btn-secondary" onclick="closeDialog('addExpenseDialog')"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Expense Dialog -->
<div class="dialog-overlay" id="editExpenseDialog">
    <div class="dialog exp-dialog-modern">
        <div class="exp-dialog-header">
            <h4><i class="fas fa-edit"></i> دەستکاریکردنی خەرجی</h4>
            <button type="button" class="exp-dialog-close" onclick="closeDialog('editExpenseDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="expense_id" id="edit_expense_id">
            <div class="exp-dialog-body">
                <div class="exp-form-field exp-field-full" style="margin-bottom: 10px;">
                    <label><i class="fas fa-layer-group"></i> جۆری خەرجی <span class="required">*</span></label>
                    <div class="exp-kind-pick">
                        <label class="is-normal"><input type="radio" name="expense_kind" value="normal" id="edit_kind_normal"><span><i class="fas fa-box-open"></i> ئاسایی</span></label>
                        <label class="is-implant"><input type="radio" name="expense_kind" value="implant" id="edit_kind_implant"><span><i class="fas fa-tooth"></i> ئیمپلانت</span></label>
                    </div>
                </div>
                <div class="exp-form-grid">
                    <div class="exp-form-field">
                        <label><i class="fas fa-folder"></i> گرووپ <span class="required">*</span></label>
                        <select name="category_id" id="edit_category_id" required><option value="">هەڵبژێرە...</option><?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                        <input type="date" name="expense_date" id="edit_expense_date" required>
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-user-md"></i> دکتۆر</label>
                        <select name="doctor_id" id="edit_doctor_id"><option value="">هەڵبژێرە...</option><?php foreach ($doctors as $doc): ?><option value="<?= $doc['id'] ?>"><?= htmlspecialchars($doc['full_name']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-file-alt"></i> وەسف <span class="required">*</span></label>
                        <input type="text" name="description" id="edit_description" required>
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-cubes"></i> بڕ (ژمارە)</label>
                        <input type="number" name="quantity" id="edit_quantity" min="1">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-balance-scale"></i> یەکە</label>
                        <input type="text" name="unit" id="edit_unit">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-tag"></i> نرخی یەکە</label>
                        <input type="number" name="unit_price" id="edit_unit_price" min="0" step="0.01">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-money-bill"></i> کۆی گشتی <span class="required">*</span></label>
                        <input type="number" name="amount" id="edit_amount" required min="0" step="0.01">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-check-circle"></i> دۆخی پارەدان</label>
                        <select name="payment_status" id="edit_payment_status"><?php foreach ($paymentStatuses as $key => $status): ?><option value="<?= $key ?>"><?= $status['label'] ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-truck"></i> فرۆشیار/کۆمپانیا</label>
                        <input type="text" name="supplier" id="edit_supplier">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-file-invoice"></i> ژمارەی پسوولە</label>
                        <input type="text" name="invoice_number" id="edit_invoice_number">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-sticky-note"></i> تێبینی</label>
                        <textarea name="notes" id="edit_notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="exp-dialog-footer">
                <button type="submit" class="exp-btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
                <button type="button" class="exp-btn-secondary" onclick="closeDialog('editExpenseDialog')"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
            </div>
        </form>
    </div>
</div>

<script>
function calcTotal() {
    var qty = document.querySelector('input[name="quantity"]').value || 1;
    var price = document.getElementById('add_unit_price').value || 0;
    if (price > 0) document.getElementById('add_amount').value = qty * price;
}

function editExpense(expense) {
    document.getElementById('edit_expense_id').value = expense.id;
    document.getElementById('edit_category_id').value = expense.category_id || '';
    document.getElementById(expense.expense_kind === 'implant' ? 'edit_kind_implant' : 'edit_kind_normal').checked = true;
    document.getElementById('edit_doctor_id').value = expense.doctor_id || '';
    document.getElementById('edit_expense_date').value = expense.expense_date;
    document.getElementById('edit_description').value = expense.description;
    document.getElementById('edit_quantity').value = expense.quantity || 1;
    document.getElementById('edit_unit').value = expense.unit || '';
    document.getElementById('edit_unit_price').value = expense.unit_price || '';
    document.getElementById('edit_amount').value = expense.amount;
    document.getElementById('edit_payment_status').value = expense.payment_status || 'paid';
    document.getElementById('edit_supplier').value = expense.supplier || '';
    document.getElementById('edit_invoice_number').value = expense.invoice_number || '';
    document.getElementById('edit_notes').value = expense.notes || '';
    openDialog('editExpenseDialog');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
