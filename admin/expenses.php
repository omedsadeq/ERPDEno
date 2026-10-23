<?php
/**
 * Expense Management Page (Admin Only)
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
$auth ??= new Auth();
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.expenses')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$pageTitle = 'بەڕێوەبردنی خەرجییەکان';
$breadcrumb = [['title' => 'خەرجییەکان']];

require_once __DIR__ . '/../includes/header.php';

// Date filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$category = $_GET['category'] ?? '';

// Load expense categories from database
$categoriesFromDb = $db->fetchAll("SELECT id, category_name, category_type FROM expense_categories WHERE is_active = 1");
$categories = [];
foreach ($categoriesFromDb as $cat) {
    $categories[$cat['id']] = $cat['category_name'];
}
$categoryTypes = [];
foreach ($categoriesFromDb as $cat) {
    $categoryTypes[$cat['id']] = $cat['category_type'];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'add') {
        // Combine description and notes into description field
        $description = trim($_POST['description']);
        if (!empty($_POST['notes'])) {
            $description .= "\n" . trim($_POST['notes']);
        }

        $db->insert('expenses', [
            'category_id' => intval($_POST['category']),
            'description' => $description,
            'amount' => floatval($_POST['amount']),
            'expense_date' => $_POST['date'],
            'vendor' => trim($_POST['vendor'] ?? ''),
            'created_by' => $currentUser['id']
        ]);
        header('Location: ' . APP_URL . '/admin/expenses.php?added=1');
        exit;
    } elseif ($action === 'delete') {
        $db->delete('expenses', 'id = :id', ['id' => intval($_POST['expense_id'])]);
        header('Location: ' . APP_URL . '/admin/expenses.php?deleted=1');
        exit;
    }
}

// Get expenses
$sql = "SELECT e.*, u.full_name as created_by_name, ec.category_name, ec.category_type
        FROM expenses e
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.expense_date BETWEEN :from AND :to";
$params = ['from' => $dateFrom, 'to' => $dateTo];

if ($category) {
    $sql .= " AND e.category_id = :category";
    $params['category'] = $category;
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";
$expenses = $db->fetchAll($sql, $params);

// Get statistics
$stats = $db->fetchAll(
    "SELECT ec.category_name, ec.id as category_id, SUM(e.amount) as total
     FROM expenses e
     LEFT JOIN expense_categories ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
    ['from' => $dateFrom, 'to' => $dateTo]
);

$totalExpenses = array_sum(array_column($expenses, 'amount'));
?>

<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> خەرجی زیادکرا</div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-row mb-3">
    <div class="stat-panel">
        <div class="stat-icon red"><i class="fas fa-receipt"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= number_format($totalExpenses) ?></div>
            <div class="stat-label">کۆی خەرجی</div>
        </div>
    </div>
    <div class="stat-panel">
        <div class="stat-icon blue"><i class="fas fa-list"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= count($expenses) ?></div>
            <div class="stat-label">ژمارەی تۆماکان</div>
        </div>
    </div>
</div>

<!-- Filter & Add -->
<div class="panel mb-3">
    <div class="panel-body">
        <div class="d-flex justify-between align-center flex-wrap gap-2">
            <form method="GET" class="d-flex gap-1">
                <select name="category" class="form-control" style="width: 140px;">
                    <option value="">هەموو پۆلەکان</option>
                    <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $category === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>" style="width: 140px;">
                <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>" style="width: 140px;">
                <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i></button>
            </form>
            <button class="btn btn-primary" onclick="openDialog('addExpenseDialog')">
                <i class="fas fa-plus"></i> خەرجی نوێ
            </button>
        </div>
    </div>
</div>

<div class="collapsible-sidebar-grid" style="display: grid; grid-template-columns: 1fr 300px; gap: 24px;">
    <!-- Expenses List -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-receipt"></i> خەرجییەکان</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($expenses)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt empty-state-icon"></i>
                <p class="empty-state-title">هیچ خەرجییەک نییە</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>بەروار</th>
                            <th>پۆل</th>
                            <th>وەسف</th>
                            <th>بڕ</th>
                            <th>کردار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                        <tr>
                            <td><?= $exp['expense_date'] ?></td>
                            <td><span class="badge"><?= htmlspecialchars($exp['category_name']) ?></span></td>
                            <td>
                                <?= htmlspecialchars($exp['description']) ?>
                                <?php if (!empty($exp['vendor'])): ?>
                                <br><small class="text-secondary"><?= htmlspecialchars($exp['vendor']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><strong class="text-error"><?= number_format($exp['amount']) ?></strong></td>
                            <td>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('ئایا دڵنیایت؟')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 700; background: var(--fill-subtle);">
                            <td colspan="3">کۆ</td>
                            <td colspan="2"><?= number_format($totalExpenses) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary by Category -->
    <div class="panel">
        <div class="panel-header">
            <h4 class="panel-title"><i class="fas fa-chart-pie"></i> بەپێی پۆل</h4>
        </div>
        <div class="panel-body">
            <?php if (empty($stats)): ?>
            <p class="text-secondary text-center">هیچ داتایەک نییە</p>
            <?php else: ?>
            <?php foreach ($stats as $st): ?>
            <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-light);">
                <span><?= htmlspecialchars($st['category_name']) ?></span>
                <strong><?= number_format($st['total']) ?></strong>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Expense Dialog -->
<div class="dialog-overlay" id="addExpenseDialog">
    <div class="dialog exp-dialog-modern">
        <div class="exp-dialog-header">
            <h4><i class="fas fa-receipt"></i> زیادکردنی خەرجی</h4>
            <button type="button" class="exp-dialog-close" onclick="closeDialog('addExpenseDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="exp-dialog-body">
                <div class="exp-form-grid">
                    <div class="exp-form-field">
                        <label class="exp-form-label"><span class="exp-required">*</span> پۆل</label>
                        <select name="category" class="exp-form-control" required>
                            <?php foreach ($categories as $key => $label): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="exp-form-field">
                        <label class="exp-form-label"><span class="exp-required">*</span> بەروار</label>
                        <input type="date" name="date" class="exp-form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="exp-form-field exp-field-full">
                        <label class="exp-form-label"><span class="exp-required">*</span> وەسف</label>
                        <input type="text" name="description" class="exp-form-control" required placeholder="بۆ چی خەرج کرا؟">
                    </div>
                    <div class="exp-form-field">
                        <label class="exp-form-label"><span class="exp-required">*</span> بڕ</label>
                        <input type="number" name="amount" class="exp-form-control" required min="0" step="0.01">
                    </div>
                    <div class="exp-form-field">
                        <label class="exp-form-label">فرۆشیار/شوێن</label>
                        <input type="text" name="vendor" class="exp-form-control" placeholder="ناوی فرۆشیار یان شوێن">
                    </div>
                    <div class="exp-form-field exp-field-full">
                        <label class="exp-form-label">تێبینی</label>
                        <textarea name="notes" class="exp-form-control" rows="2" placeholder="تێبینی زیاتر..."></textarea>
                    </div>
                </div>
            </div>
            <div class="exp-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('addExpenseDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-plus"></i> زیادکردن</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modern Expense Dialog Styles */
.exp-dialog-modern {
    max-width: 480px;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
}
.exp-dialog-header {
    background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.exp-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}
.exp-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}
.exp-dialog-close:hover { background: rgba(255,255,255,0.25); }
.exp-dialog-body { padding: 20px; }
.exp-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}
.exp-form-field { display: flex; flex-direction: column; gap: 6px; }
.exp-field-full { grid-column: 1 / -1; }
.exp-form-label {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}
.exp-required { color: #ef4444; margin-left: 2px; }
.exp-form-control {
    padding: 10px 14px;
    border: 1.5px solid #e0e0e0;
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fafafa;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.exp-form-control:focus {
    border-color: #f44336;
    background: white;
    box-shadow: 0 0 0 3px rgba(244, 67, 54, 0.1);
    outline: none;
}
textarea.exp-form-control {
    white-space: pre-wrap;
    resize: vertical;
    min-height: 60px;
}
.exp-dialog-footer {
    padding: 14px 20px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
@media (max-width: 480px) {
    .exp-form-grid { grid-template-columns: 1fr; }
    .exp-field-full { grid-column: 1; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
