<?php
/**
 * Lab Expenses Management Page (Admin Only)
 * خەرجی تاقیگە - تاج، بریج، ددانی دەکراو، هتد
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

if (!$permission->can('page.expenses_lab')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Date filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Handle POST actions BEFORE including header (which outputs HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'add') {
        $db->insert('expenses_lab', [
            'category_id' => intval($_POST['category_id']) ?: null,
            'doctor_id' => !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null,
            'description' => trim($_POST['description']),
            'amount' => floatval($_POST['amount']),
            'expense_date' => $_POST['expense_date'],
            'lab_name' => trim($_POST['lab_name'] ?? ''),
            'receipt_number' => trim($_POST['receipt_number'] ?? ''),
            'patient_id' => !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : null,
            'work_type' => trim($_POST['work_type'] ?? ''),
            'tooth_number' => trim($_POST['tooth_number'] ?? ''),
            'payment_status' => $_POST['payment_status'] ?? 'paid',
            'notes' => trim($_POST['notes'] ?? ''),
            'created_by' => $currentUser['id']
        ]);
        header('Location: ' . APP_URL . '/admin/expenses-lab.php?added=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    } elseif ($action === 'edit') {
        $db->update('expenses_lab', [
            'category_id' => intval($_POST['category_id']) ?: null,
            'doctor_id' => !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null,
            'description' => trim($_POST['description']),
            'amount' => floatval($_POST['amount']),
            'expense_date' => $_POST['expense_date'],
            'lab_name' => trim($_POST['lab_name'] ?? ''),
            'receipt_number' => trim($_POST['receipt_number'] ?? ''),
            'patient_id' => !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : null,
            'work_type' => trim($_POST['work_type'] ?? ''),
            'tooth_number' => trim($_POST['tooth_number'] ?? ''),
            'payment_status' => $_POST['payment_status'] ?? 'paid',
            'notes' => trim($_POST['notes'] ?? '')
        ], 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/admin/expenses-lab.php?updated=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    } elseif ($action === 'delete') {
        $db->delete('expenses_lab', 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/admin/expenses-lab.php?deleted=1&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        exit;
    }
}

// Now include header (safe to output HTML)
$currentPage = 'expenses-lab';
$pageTitle = 'خەرجی تاقیگە';
$breadcrumb = [['title' => 'خەرجیەکان'], ['title' => 'خەرجی تاقیگە']];

require_once __DIR__ . '/../includes/header.php';

$categoryId = $_GET['category'] ?? '';
$paymentStatus = $_GET['payment_status'] ?? '';

// Load expense categories
$categories = $db->fetchAll("SELECT * FROM expense_categories_lab WHERE is_active = 1 ORDER BY sort_order, category_name");

// Load doctors list
$doctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('doctor', 'admin') AND is_active = 1 ORDER BY full_name");

// Get patients for linking
$patients = $db->fetchAll("SELECT id, full_name, patient_code FROM patients ORDER BY full_name LIMIT 1000");

// Build query
$sql = "SELECT e.*, u.full_name as created_by_name, ec.category_name, ec.icon, ec.color, p.full_name as patient_name, p.patient_code
        FROM expenses_lab e
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN expense_categories_lab ec ON e.category_id = ec.id
        LEFT JOIN patients p ON e.patient_id = p.id
        WHERE e.expense_date BETWEEN :from AND :to";
$params = ['from' => $dateFrom, 'to' => $dateTo];

if ($categoryId) {
    $sql .= " AND e.category_id = :category";
    $params['category'] = $categoryId;
}

if ($paymentStatus) {
    $sql .= " AND e.payment_status = :payment_status";
    $params['payment_status'] = $paymentStatus;
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";
$expenses = $db->fetchAll($sql, $params);

// Get statistics by category
$stats = $db->fetchAll(
    "SELECT ec.category_name, ec.icon, ec.color, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_lab e
     LEFT JOIN expense_categories_lab ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name, ec.icon, ec.color
     ORDER BY total DESC",
    ['from' => $dateFrom, 'to' => $dateTo]
);

$totalExpenses = array_sum(array_column($expenses, 'amount'));
$expenseCount = count($expenses);

// Payment status labels
$paymentStatuses = [
    'paid' => ['label' => 'دراوە', 'color' => '#4caf50'],
    'pending' => ['label' => 'چاوەڕوان', 'color' => '#ff9800'],
    'partial' => ['label' => 'بەشێک', 'color' => '#2196f3']
];
?>

<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی تاقیگە زیادکرا</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی تاقیگە نوێکرایەوە</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی تاقیگە سڕایەوە</div>
<?php endif; ?>

<style>
.expense-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 12px; }
.expense-stat-card { background: white; border-radius: 8px; padding: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; }
.expense-stat-card::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 3px; background: var(--card-color, #9c27b0); }
.expense-stat-icon { width: 36px; height: 36px; border-radius: 8px; background: var(--card-color, #9c27b0); color: white; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.expense-stat-content { flex: 1; min-width: 0; }
.expense-stat-value { font-size: 18px; font-weight: 700; color: #1a1a1a; line-height: 1.2; }
.expense-stat-label { font-size: 11px; color: #666; font-weight: 500; }
.expense-filters { background: white; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.expense-filters-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.expense-filter-group { flex: 1; min-width: 120px; }
.expense-filter-group label { display: block; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; }
.expense-filter-group input, .expense-filter-group select { width: 100%; padding: 6px 10px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 12px; }
.expense-filter-group input:focus, .expense-filter-group select:focus { border-color: #9c27b0; box-shadow: 0 0 0 2px rgba(156, 39, 176, 0.1); outline: none; }
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
.expense-summary-header { padding: 10px 14px; background: linear-gradient(135deg, #9c27b0, #7b1fa2); color: white; }
.expense-summary-header h4 { margin: 0; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.expense-summary-body { padding: 10px 14px; }
.expense-summary-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
.expense-summary-item:last-child { border-bottom: none; }
.expense-summary-item-label { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #555; }
.expense-summary-item-label i { width: 20px; height: 20px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: white; }
.expense-summary-item-value { font-size: 12px; font-weight: 700; color: #1a1a1a; }
/* Dialog - Appointments Style */
.exp-dialog-modern { max-width: 520px; width: 96%; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); background: #fff; max-height: 92vh; display: flex; flex-direction: column; }
.exp-dialog-header { background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%); color: white; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
.exp-dialog-header h4 { margin: 0; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.exp-dialog-close { background: rgba(255,255,255,0.15); border: none; color: white; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; font-size: 12px; }
.exp-dialog-close:hover { background: rgba(255,255,255,0.25); }
.exp-dialog-body { padding: 14px 16px; overflow-y: auto; flex: 1; }
.exp-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.exp-form-field { display: flex; flex-direction: column; gap: 4px; }
.exp-form-field.exp-field-full { grid-column: 1 / -1; }
.exp-form-field label { font-size: 11px; font-weight: 600; color: #546e7a; display: flex; align-items: center; gap: 5px; }
.exp-form-field label i { color: #9c27b0; font-size: 10px; }
.exp-form-field label .required { color: #f44336; font-size: 10px; }
.exp-form-field input, .exp-form-field select, .exp-form-field textarea { padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 12px; font-family: inherit; transition: all 0.2s; background: #fafafa; }
.exp-form-field input:focus, .exp-form-field select:focus, .exp-form-field textarea:focus { border-color: #9c27b0; box-shadow: 0 0 0 2px rgba(156, 39, 176, 0.1); outline: none; background: #fff; }
.exp-form-field textarea { resize: vertical; min-height: 50px; }
.exp-dialog-footer { padding: 10px 16px; background: #f5f7f9; border-top: 1px solid #e0e0e0; display: flex; gap: 8px; flex-direction: row-reverse; flex-shrink: 0; }
.exp-btn-primary, .exp-btn-secondary { padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: all 0.2s; border: none; font-family: inherit; }
.exp-btn-primary { background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%); color: white; }
.exp-btn-primary:hover { background: linear-gradient(135deg, #7b1fa2 0%, #6a1b9a 100%); }
.exp-btn-secondary { background: white; color: #546e7a; border: 1.5px solid #e0e0e0; }
.exp-btn-secondary:hover { background: #f5f5f5; border-color: #bdbdbd; }
@media (max-width: 768px) { .expense-stats-grid { grid-template-columns: repeat(2, 1fr); } .exp-form-grid { grid-template-columns: 1fr; } .exp-form-field.exp-field-full { grid-column: 1; } .expense-filter-group { min-width: 100%; } }
</style>

<!-- Statistics Cards -->
<div class="expense-stats-grid">
    <div class="expense-stat-card" style="--card-color: #9c27b0;">
        <div class="expense-stat-icon" style="background: #9c27b0;"><i class="fas fa-flask"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= number_format($totalExpenses) ?></div><div class="expense-stat-label">کۆی خەرجی</div></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #2196f3;">
        <div class="expense-stat-icon" style="background: #2196f3;"><i class="fas fa-list"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= $expenseCount ?></div><div class="expense-stat-label">تۆمار</div></div>
    </div>
    <div class="expense-stat-card" style="--card-color: #e91e63;">
        <div class="expense-stat-icon" style="background: #e91e63;"><i class="fas fa-tooth"></i></div>
        <div class="expense-stat-content"><div class="expense-stat-value"><?= count($stats) ?></div><div class="expense-stat-label">جۆری کار</div></div>
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
            <a href="<?= APP_URL ?>/admin/print-expenses.php?type=lab&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" target="_blank" class="btn btn-info"><i class="fas fa-print"></i></a>
            <button type="button" class="btn btn-success" onclick="openDialog('addExpenseDialog')"><i class="fas fa-plus"></i> خەرجی نوێ</button>
        </div>
    </form>
</div>

<div class="collapsible-sidebar-grid" style="display: grid; grid-template-columns: 1fr 280px; gap: 12px;">
    <!-- Expenses Table -->
    <div class="expense-table-container">
        <div style="padding: 10px 14px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px;"><i class="fas fa-flask" style="color: #9c27b0;"></i> خەرجییەکانی تاقیگە</h3>
            <span class="badge"><?= $expenseCount ?></span>
        </div>
        <?php if (empty($expenses)): ?>
        <div style="padding: 60px 20px; text-align: center;"><i class="fas fa-flask" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i><p style="color: #888; margin: 0;">هیچ خەرجییەک نییە</p></div>
        <?php else: ?>
        <div class="table-container">
            <table class="expense-table">
                <thead><tr><th>بەروار</th><th>جۆر</th><th>وەسف</th><th>نەخۆش</th><th>دۆخ</th><th>بڕ</th><th>کردار</th></tr></thead>
                <tbody>
                    <?php foreach ($expenses as $exp): ?>
                    <tr>
                        <td style="white-space: nowrap;"><?= $exp['expense_date'] ?></td>
                        <td>
                            <?php if ($exp['category_name']): ?>
                            <span class="category-badge" style="background: <?= $exp['color'] ?? '#9c27b0' ?>15; color: <?= $exp['color'] ?? '#9c27b0' ?>;"><i class="<?= $exp['icon'] ?? 'fas fa-tooth' ?>"></i> <?= htmlspecialchars($exp['category_name']) ?></span>
                            <?php else: ?><span class="text-secondary">-</span><?php endif; ?>
                        </td>
                        <td style="text-align: right; max-width: 180px;">
                            <div style="font-weight: 500;"><?= htmlspecialchars($exp['description']) ?></div>
                            <?php if (!empty($exp['lab_name'])): ?><small style="color: #888;"><i class="fas fa-building"></i> <?= htmlspecialchars($exp['lab_name']) ?></small><?php endif; ?>
                            <?php if (!empty($exp['tooth_number'])): ?><small style="color: #888; display: block;"><i class="fas fa-tooth"></i> ددان: <?= htmlspecialchars($exp['tooth_number']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($exp['patient_name'])): ?>
                            <small><?= htmlspecialchars($exp['patient_name']) ?><br><span class="text-secondary">(<?= $exp['patient_code'] ?>)</span></small>
                            <?php else: ?><span class="text-secondary">-</span><?php endif; ?>
                        </td>
                        <td><?php $ps = $paymentStatuses[$exp['payment_status']] ?? $paymentStatuses['paid']; ?><span class="status-badge" style="background: <?= $ps['color'] ?>15; color: <?= $ps['color'] ?>;"><?= $ps['label'] ?></span></td>
                        <td><strong style="color: #c62828;"><?= number_format($exp['amount']) ?></strong></td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick='editExpense(<?= json_encode($exp) ?>)'><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display: inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="expense_id" value="<?= $exp['id'] ?>"><button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('ئایا دڵنیایت؟')"><i class="fas fa-trash"></i></button></form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5" style="text-align: right; font-weight: 700;">کۆی گشتی:</td><td colspan="2" style="color: #c62828;"><?= number_format($totalExpenses) ?></td></tr></tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary Panel -->
    <div>
        <div class="expense-summary">
            <div class="expense-summary-header"><h4><i class="fas fa-chart-pie"></i> پوختەی جۆرەکان</h4></div>
            <div class="expense-summary-body">
                <?php if (empty($stats)): ?><p style="text-align: center; color: #888; margin: 20px 0;">هیچ داتایەک نییە</p>
                <?php else: foreach ($stats as $st): ?>
                <div class="expense-summary-item">
                    <div class="expense-summary-item-label"><i style="background: <?= $st['color'] ?? '#9c27b0' ?>;" class="<?= $st['icon'] ?? 'fas fa-tooth' ?>"></i><span><?= htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') ?></span></div>
                    <div class="expense-summary-item-value"><?= number_format($st['total']) ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="expense-summary" style="margin-top: 16px;">
            <div class="expense-summary-header" style="background: linear-gradient(135deg, #607d8b, #455a64);"><h4><i class="fas fa-link"></i> بەستەرەکان</h4></div>
            <div class="expense-summary-body" style="padding: 12px;">
                <a href="<?= APP_URL ?>/admin/expense-groups.php?type=lab" class="btn btn-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-folder"></i> بەڕێوەبردنی گرووپەکان</a>
                <a href="<?= APP_URL ?>/admin/expenses-general.php" class="btn btn-outline-secondary btn-block" style="margin-bottom: 8px;"><i class="fas fa-coins"></i> خەرجی گشتی</a>
                <a href="<?= APP_URL ?>/admin/expenses-inventory.php" class="btn btn-outline-secondary btn-block"><i class="fas fa-warehouse"></i> خەرجی کۆگا</a>
            </div>
        </div>
    </div>
</div>

<!-- Add Expense Dialog -->
<div class="dialog-overlay" id="addExpenseDialog">
    <div class="dialog exp-dialog-modern">
        <div class="exp-dialog-header">
            <h4><i class="fas fa-plus-circle"></i> زیادکردنی خەرجی تاقیگە</h4>
            <button type="button" class="exp-dialog-close" onclick="closeDialog('addExpenseDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="exp-dialog-body">
                <div class="exp-form-grid">
                    <div class="exp-form-field">
                        <label><i class="fas fa-tooth"></i> جۆری کار <span class="required">*</span></label>
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
                        <input type="text" name="description" required placeholder="وەسفی کار">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-money-bill"></i> بڕ <span class="required">*</span></label>
                        <input type="number" name="amount" required min="0" step="0.01">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-check-circle"></i> دۆخی پارەدان</label>
                        <select name="payment_status"><?php foreach ($paymentStatuses as $key => $status): ?><option value="<?= $key ?>"><?= $status['label'] ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-flask"></i> ناوی تاقیگە</label>
                        <input type="text" name="lab_name">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-receipt"></i> ژمارەی وەسڵ</label>
                        <input type="text" name="receipt_number">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-user"></i> نەخۆش</label>
                        <select name="patient_id"><option value="">هیچ کام</option><?php foreach ($patients as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['full_name']) ?> (<?= $p['patient_code'] ?>)</option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-tooth"></i> ژمارەی ددان</label>
                        <input type="text" name="tooth_number" placeholder="نم: 11, 12, 21">
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
                        <label><i class="fas fa-tooth"></i> جۆری کار <span class="required">*</span></label>
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
                        <label><i class="fas fa-check-circle"></i> دۆخی پارەدان</label>
                        <select name="payment_status" id="edit_payment_status"><?php foreach ($paymentStatuses as $key => $status): ?><option value="<?= $key ?>"><?= $status['label'] ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-flask"></i> ناوی تاقیگە</label>
                        <input type="text" name="lab_name" id="edit_lab_name">
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-receipt"></i> ژمارەی وەسڵ</label>
                        <input type="text" name="receipt_number" id="edit_receipt_number">
                    </div>
                </div>
                <div class="exp-form-grid" style="margin-top: 10px;">
                    <div class="exp-form-field">
                        <label><i class="fas fa-user"></i> نەخۆش</label>
                        <select name="patient_id" id="edit_patient_id"><option value="">هیچ کام</option><?php foreach ($patients as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['full_name']) ?> (<?= $p['patient_code'] ?>)</option><?php endforeach; ?></select>
                    </div>
                    <div class="exp-form-field">
                        <label><i class="fas fa-tooth"></i> ژمارەی ددان</label>
                        <input type="text" name="tooth_number" id="edit_tooth_number">
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
    document.getElementById('edit_payment_status').value = expense.payment_status || 'paid';
    document.getElementById('edit_lab_name').value = expense.lab_name || '';
    document.getElementById('edit_receipt_number').value = expense.receipt_number || '';
    document.getElementById('edit_patient_id').value = expense.patient_id || '';
    document.getElementById('edit_tooth_number').value = expense.tooth_number || '';
    document.getElementById('edit_notes').value = expense.notes || '';
    openDialog('editExpenseDialog');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
