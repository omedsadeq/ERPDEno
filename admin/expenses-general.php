<?php
/**
 * General Expenses Management Page (Admin Only)
 * خەرجی گشتی - کرێ، کارەبا، ئاو، مووچە، هتد
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

if (!$permission->can('page.expenses_general')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Date filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Rows written by another screen (source_type, e.g. 'hr_payroll' for a salary
// paid on hr/payroll.php) belong to it: they change only by cancelling them
// there, or the expense and the record it pays for would stop agreeing.
$lockedRow = function ($id) use ($db) {
    $row = $db->fetch("SELECT source_type FROM expenses_general WHERE id = :id", ['id' => (int)$id]);
    return $row && !empty($row['source_type']);
};

// Handle POST actions BEFORE including header (which outputs HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if (in_array($action, ['edit', 'delete'], true) && $lockedRow($_POST['expense_id'] ?? 0)) {
        header('Location: ' . APP_URL . '/admin/expenses-general.php?locked=1&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo));
        exit;
    }

    if ($action === 'add') {
        $db->insert('expenses_general', [
            'category_id' => intval($_POST['category_id']) ?: null,
            'doctor_id' => !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null,
            'description' => trim($_POST['description']),
            'amount' => floatval($_POST['amount']),
            'expense_date' => $_POST['expense_date'],
            'vendor' => trim($_POST['vendor'] ?? ''),
            'receipt_number' => trim($_POST['receipt_number'] ?? ''),
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'notes' => trim($_POST['notes'] ?? ''),
            'created_by' => $currentUser['id']
        ]);
        header('Location: ' . APP_URL . '/admin/expenses-general.php?added=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    } elseif ($action === 'edit') {
        $db->update('expenses_general', [
            'category_id' => intval($_POST['category_id']) ?: null,
            'doctor_id' => !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null,
            'description' => trim($_POST['description']),
            'amount' => floatval($_POST['amount']),
            'expense_date' => $_POST['expense_date'],
            'vendor' => trim($_POST['vendor'] ?? ''),
            'receipt_number' => trim($_POST['receipt_number'] ?? ''),
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'notes' => trim($_POST['notes'] ?? '')
        ], 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/admin/expenses-general.php?updated=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    } elseif ($action === 'delete') {
        $db->delete('expenses_general', 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/admin/expenses-general.php?deleted=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    }
}

// Now include header (safe to output HTML)
$currentPage = 'expenses-general';
$pageTitle = 'خەرجی گشتی';
$breadcrumb = [['title' => 'خەرجیەکان'], ['title' => 'خەرجی گشتی']];

require_once __DIR__ . '/../includes/header.php';

$categoryId = $_GET['category'] ?? '';
$paymentMethod = $_GET['payment_method'] ?? '';

// Load expense categories
$categories = $db->fetchAll("SELECT * FROM expense_categories_general WHERE is_active = 1 ORDER BY sort_order, category_name");

// Load doctors list
$doctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('doctor', 'admin') AND is_active = 1 ORDER BY full_name");

// Build query
$sql = "SELECT e.*, u.full_name as created_by_name, ec.category_name, ec.icon, ec.color
        FROM expenses_general e
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN expense_categories_general ec ON e.category_id = ec.id
        WHERE e.expense_date BETWEEN :from AND :to";
$params = ['from' => $dateFrom, 'to' => $dateTo];

if ($categoryId) {
    $sql .= " AND e.category_id = :category";
    $params['category'] = $categoryId;
}

if ($paymentMethod) {
    $sql .= " AND e.payment_method = :payment_method";
    $params['payment_method'] = $paymentMethod;
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";
$expenses = $db->fetchAll($sql, $params);

// Get statistics by category
$stats = $db->fetchAll(
    "SELECT ec.category_name, ec.icon, ec.color, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_general e
     LEFT JOIN expense_categories_general ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name, ec.icon, ec.color
     ORDER BY total DESC",
    ['from' => $dateFrom, 'to' => $dateTo]
);

// Get monthly comparison
$thisMonth = $db->fetch(
    "SELECT SUM(amount) as total FROM expenses_general WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())"
)['total'] ?? 0;

$lastMonth = $db->fetch(
    "SELECT SUM(amount) as total FROM expenses_general WHERE MONTH(expense_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(expense_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))"
)['total'] ?? 0;

$totalExpenses = array_sum(array_column($expenses, 'amount'));
$expenseCount = count($expenses);

// Payment method labels
$paymentMethods = [
    'cash' => ['label' => 'کاش', 'icon' => 'fas fa-money-bill-wave', 'color' => '#4caf50'],
    'card' => ['label' => 'کارت', 'icon' => 'fas fa-credit-card', 'color' => '#2196f3'],
    'transfer' => ['label' => 'گواستنەوە', 'icon' => 'fas fa-exchange-alt', 'color' => '#9c27b0'],
    'check' => ['label' => 'چەک', 'icon' => 'fas fa-money-check', 'color' => '#ff9800']
];
?>

<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی گشتی زیادکرا</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی گشتی نوێکرایەوە</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی گشتی سڕایەوە</div>
<?php endif; ?>

<?php if (isset($_GET['locked'])): ?>
<div class="alert alert-warning"><i class="fas fa-lock alert-icon"></i> ئەم خەرجییە مووچەیەکە کە لە «سەرچاوە مرۆییەکان ← پێدانی مووچە» دراوە. بۆ گۆڕین یان سڕینەوە، لە وەسڵی مووچەدانەکەیدا «دەستکاری» یان «سڕینەوە» بکە.</div>
<?php endif; ?>

<style>
.expense-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 12px; }
.expense-stat-card { background: white; border-radius: 8px; padding: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; }
.expense-stat-card::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 3px; background: var(--card-color, #4caf50); }
.expense-stat-icon { width: 36px; height: 36px; border-radius: 8px; background: var(--card-color, #4caf50); color: white; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.expense-stat-content { flex: 1; min-width: 0; }
.expense-stat-value { font-size: 18px; font-weight: 700; color: #1a1a1a; line-height: 1.2; }
.expense-stat-label { font-size: 11px; color: #666; font-weight: 500; }
.expense-stat-change { display: inline-flex; align-items: center; gap: 2px; font-size: 10px; padding: 1px 5px; border-radius: 8px; margin-top: 2px; }
.expense-stat-change.up { background: #ffebee; color: #c62828; }
.expense-stat-change.down { background: #e8f5e9; color: #2e7d32; }
.expense-filters { background: white; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.expense-filters-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.expense-filter-group { flex: 1; min-width: 120px; }
.expense-filter-group label { display: block; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; }
.expense-filter-group input, .expense-filter-group select { width: 100%; padding: 6px 10px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 12px; }
.expense-filter-group input:focus, .expense-filter-group select:focus { border-color: #4caf50; box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1); outline: none; }
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
.payment-badge { display: inline-flex; align-items: center; gap: 3px; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; }
.expense-summary { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.expense-summary-header { padding: 10px 14px; background: linear-gradient(135deg, #4caf50, #388e3c); color: white; }
.expense-summary-header h4 { margin: 0; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.expense-summary-body { padding: 10px 14px; }
.expense-summary-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
.expense-summary-item:last-child { border-bottom: none; }
.expense-summary-item-label { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #555; }
.expense-summary-item-label i { width: 20px; height: 20px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: white; }
.expense-summary-item-value { font-size: 12px; font-weight: 700; color: #1a1a1a; }
/* Dialog - Appointments Style */
.exp-dialog-modern { max-width: 520px; width: 96%; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); background: #fff; max-height: 92vh; display: flex; flex-direction: column; }
.exp-dialog-header { background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%); color: white; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.exp-dialog-header h4 { margin: 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.exp-dialog-close { background: rgba(255,255,255,0.15); border: none; color: white; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; font-size: 12px; }
.exp-dialog-close:hover { background: rgba(255,255,255,0.25); }
.exp-dialog-body { padding: 14px 16px; overflow-y: auto; flex: 1; }
.exp-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.exp-form-field { display: flex; flex-direction: column; gap: 4px; }
.exp-form-field.exp-field-full { grid-column: 1 / -1; }
.exp-form-field label { font-size: 11px; font-weight: 600; color: #546e7a; display: flex; align-items: center; gap: 5px; }
.exp-form-field label i { color: #4caf50; font-size: 10px; }
.exp-form-field label .required { color: #f44336; font-size: 10px; }
.exp-form-field input, .exp-form-field select, .exp-form-field textarea { padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 12px; font-family: inherit; transition: all 0.2s; background: #fafafa; }
.exp-form-field input:focus, .exp-form-field select:focus, .exp-form-field textarea:focus { border-color: #4caf50; box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1); outline: none; background: #fff; }
.exp-form-field textarea { resize: vertical; min-height: 50px; }
.exp-dialog-footer { padding: 10px 16px; background: #f5f7f9; border-top: 1px solid #e0e0e0; display: flex; gap: 8px; flex-direction: row-reverse; flex-shrink: 0; }
.exp-btn-primary, .exp-btn-secondary { padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: all 0.2s; border: none; font-family: inherit; }
.exp-btn-primary { background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%); color: white; }
.exp-btn-primary:hover { background: linear-gradient(135deg, #388e3c 0%, #2e7d32 100%); }
.exp-btn-secondary { background: white; color: #546e7a; border: 1.5px solid #e0e0e0; }
.exp-btn-secondary:hover { background: #f5f5f5; border-color: #bdbdbd; }
@media (max-width: 768px) { .expense-stats-grid { grid-template-columns: repeat(2, 1fr); } .exp-form-grid { grid-template-columns: 1fr; } .exp-form-field.exp-field-full { grid-column: 1; } .expense-filter-group { min-width: 100%; } }
</style>

<!-- Statistics Cards -->
<div class="expense-stats-grid">
    <div class="expense-stat-card" style="--card-color: #f44336;">
        <div class="expense-stat-icon" style="background: #f44336;"><i class="fas fa-receipt"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= number_format($totalExpenses) ?></div><div class="expense-stat-label">کۆی خەرجی</div></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #2196f3;">
        <div class="expense-stat-icon" style="background: #2196f3;"><i class="fas fa-list"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= $expenseCount ?></div><div class="expense-stat-label">تۆمار</div></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #9c27b0;">
        <div class="expense-stat-icon" style="background: #9c27b0;"><i class="fas fa-calendar-alt"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= number_format($thisMonth) ?></div><div class="expense-stat-label">ئەم مانگە</div><?php if ($lastMonth > 0): $change = (($thisMonth - $lastMonth) / $lastMonth) * 100; ?><div class="expense-stat-change <?= $change > 0 ? 'up' : 'down' ?>"><i class="fas fa-arrow-<?= $change > 0 ? 'up' : 'down' ?>"></i> <?= abs(round($change, 1)) ?>%</div><?php endif; ?></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #ff9800;">
        <div class="expense-stat-icon" style="background: #ff9800;"><i class="fas fa-tags"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= count($stats) ?></div><div class="expense-stat-label">گرووپ</div></div>
    </div>
</div>

<!-- Filters -->
<div class="expense-filters">
    <form method="GET" class="expense-filters-row">
        <div class="expense-filter-group">
            <label>گرووپ</label>
            <select name="category">
                <option value="">هەموو گرووپەکان</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="expense-filter-group">
            <label>شێوازی پارەدان</label>
            <select name="payment_method">
                <option value="">هەموو</option>
                <?php foreach ($paymentMethods as $key => $method): ?>
                <option value="<?= $key ?>" <?= $paymentMethod === $key ? 'selected' : '' ?>><?= $method['label'] ?></option>
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
            <a href="<?= APP_URL ?>/admin/print-expenses.php?type=general&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&category=<?= $categoryId ?>" target="_blank" class="btn btn-info"><i class="fas fa-print"></i></a>
            <button type="button" class="btn btn-success" onclick="openDialog('addExpenseDialog')"><i class="fas fa-plus"></i> خەرجی نوێ</button>
        </div>
    </form>
</div>

<div class="collapsible-sidebar-grid" style="display: grid; grid-template-columns: 1fr 280px; gap: 12px;">
    <!-- Expenses Table -->
    <div class="expense-table-container">
        <div style="padding: 10px 14px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px;"><i class="fas fa-coins" style="color: #4caf50;"></i> خەرجییەکانی گشتی</h3>
            <span class="badge"><?= $expenseCount ?></span>
        </div>
        <?php if (empty($expenses)): ?>
        <div style="padding: 60px 20px; text-align: center;"><i class="fas fa-receipt" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i><p style="color: #888; margin: 0;">هیچ خەرجییەک نییە</p></div>
        <?php else: ?>
        <div class="table-container">
            <table class="expense-table">
                <thead><tr><th>بەروار</th><th>گرووپ</th><th>وەسف</th><th>شێواز</th><th>بڕ</th><th>کردار</th></tr></thead>
                <tbody>
                    <?php foreach ($expenses as $exp): ?>
                    <tr>
                        <td style="white-space: nowrap;"><?= $exp['expense_date'] ?></td>
                        <td>
                            <?php if ($exp['category_name']): ?>
                            <span class="category-badge" style="background: <?= $exp['color'] ?? '#6c757d' ?>15; color: <?= $exp['color'] ?? '#6c757d' ?>;"><i class="<?= $exp['icon'] ?? 'fas fa-tag' ?>"></i> <?= htmlspecialchars($exp['category_name']) ?></span>
                            <?php else: ?><span class="text-secondary">-</span><?php endif; ?>
                        </td>
                        <td style="text-align: right; max-width: 200px;">
                            <div style="font-weight: 500;"><?= htmlspecialchars($exp['description']) ?></div>
                            <?php if (!empty($exp['vendor'])): ?><small style="color: #888;"><i class="fas fa-store"></i> <?= htmlspecialchars($exp['vendor']) ?></small><?php endif; ?>
                            <?php if (!empty($exp['receipt_number'])): ?><small style="color: #888; display: block;"><i class="fas fa-receipt"></i> #<?= htmlspecialchars($exp['receipt_number']) ?></small><?php endif; ?>
                        </td>
                        <td><?php $pm = $paymentMethods[$exp['payment_method']] ?? $paymentMethods['cash']; ?><span class="payment-badge" style="background: <?= $pm['color'] ?>15; color: <?= $pm['color'] ?>;"><i class="<?= $pm['icon'] ?>"></i> <?= $pm['label'] ?></span></td>
                        <td><strong style="color: #c62828;"><?= number_format($exp['amount']) ?></strong></td>
                        <td>
                            <?php if (!empty($exp['source_type'])): ?>
                            <span title="لە «پێدانی مووچە» دراوە — لە وەسڵەکەیدا دەستکاری بکە یان بیسڕەوە" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: #0f766e; background: #ccfbf1; padding: 3px 8px; border-radius: 6px;"><i class="fas fa-lock"></i> مووچە</span>
                            <?php else: ?>
                            <button class="btn btn-sm btn-secondary" onclick='editExpense(<?= json_encode($exp) ?>)'><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display: inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="expense_id" value="<?= $exp['id'] ?>"><button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('ئایا دڵنیایت؟')"><i class="fas fa-trash"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4" style="text-align: right; font-weight: 700;">کۆی گشتی:</td><td colspan="2" style="color: #c62828;"><?= number_format($totalExpenses) ?></td></tr></tfoot>
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
                    <div class="expense-summary-item-label"><i style="background: <?= $st['color'] ?? '#6c757d' ?>;" class="<?= $st['icon'] ?? 'fas fa-tag' ?>"></i><span><?= htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') ?></span></div>
                    <div class="expense-summary-item-value"><?= number_format($st['total']) ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="expense-summary" style="margin-top: 16px;">
            <div class="expense-summary-header" style="background: linear-gradient(135deg, #607d8b, #455a64);"><h4><i class="fas fa-link"></i> بەستەرە خێراکان</h4></div>
            <div class="expense-summary-body" style="padding: 12px;">
                <a href="<?= APP_URL ?>/admin/expense-groups.php?type=general" class="btn btn-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-folder"></i> بەڕێوەبردنی گرووپەکان</a>
                <a href="<?= APP_URL ?>/admin/expenses-lab.php" class="btn btn-outline-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-flask"></i> خەرجی تاقیگە</a>
                <a href="<?= APP_URL ?>/admin/expenses-inventory.php" class="btn btn-outline-secondary btn-block"><i class="fas fa-warehouse"></i> خەرجی کۆگا</a>
            </div>
        </div>
    </div>
</div>

<!-- Add Expense Dialog -->
<div class="dialog-overlay" id="addExpenseDialog">
    <div class="dialog exp-dialog-modern">
        <div class="exp-dialog-header">
            <h4><i class="fas fa-plus-circle"></i> زیادکردنی خەرجی گشتی</h4>
            <button type="button" class="exp-dialog-close" onclick="closeDialog('addExpenseDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="exp-dialog-body">
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
                        <input type="text" name="description" required placeholder="بۆ چی خەرج کرا؟">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-money-bill"></i> بڕ <span class="required">*</span></label>
                        <input type="number" name="amount" required min="0" step="0.01">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-credit-card"></i> شێوازی پارەدان</label>
                        <select name="payment_method"><?php foreach ($paymentMethods as $key => $method): ?><option value="<?= $key ?>"><?= $method['label'] ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-receipt"></i> ژمارەی وەسڵ</label>
                        <input type="text" name="receipt_number">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-store"></i> شوێن/فرۆشیار</label>
                        <input type="text" name="vendor">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field exp-field-full">
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
                        <label><i class="fas fa-money-bill"></i> بڕ <span class="required">*</span></label>
                        <input type="number" name="amount" id="edit_amount" required min="0" step="0.01">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-credit-card"></i> شێوازی پارەدان</label>
                        <select name="payment_method" id="edit_payment_method"><?php foreach ($paymentMethods as $key => $method): ?><option value="<?= $key ?>"><?= $method['label'] ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-receipt"></i> ژمارەی وەسڵ</label>
                        <input type="text" name="receipt_number" id="edit_receipt_number">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-store"></i> شوێن/فرۆشیار</label>
                        <input type="text" name="vendor" id="edit_vendor">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field exp-field-full">
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
function editExpense(expense) {
    document.getElementById('edit_expense_id').value = expense.id;
    document.getElementById('edit_category_id').value = expense.category_id || '';
    document.getElementById('edit_doctor_id').value = expense.doctor_id || '';
    document.getElementById('edit_expense_date').value = expense.expense_date;
    document.getElementById('edit_description').value = expense.description;
    document.getElementById('edit_amount').value = expense.amount;
    document.getElementById('edit_payment_method').value = expense.payment_method || 'cash';
    document.getElementById('edit_receipt_number').value = expense.receipt_number || '';
    document.getElementById('edit_vendor').value = expense.vendor || '';
    document.getElementById('edit_notes').value = expense.notes || '';
    openDialog('editExpenseDialog');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
