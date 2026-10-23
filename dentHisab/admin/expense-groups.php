<?php
/**
 * Expense Groups Management Page (Admin Only)
 * بەڕێوەبردنی گرووپەکانی خەرجی - سێ خشتەی جیاواز
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
$auth ??= new Auth();
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.expense_groups')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentPage = 'expense-groups';
$pageTitle = 'بەڕێوەبردنی گرووپەکانی خەرجی';
$breadcrumb = [['title' => 'خەرجیەکان'], ['title' => 'گرووپەکانی خەرجی']];

require_once __DIR__ . '/../includes/header.php';

// Type filter
$typeFilter = $_GET['type'] ?? 'general';

// Table mapping
$tables = [
    'general' => 'expense_categories_general',
    'lab' => 'expense_categories_lab',
    'store' => 'expense_categories_store'
];

// Type labels
$typeLabels = [
    'general' => ['name' => 'گشتی', 'icon' => 'fas fa-coins', 'color' => '#4caf50'],
    'lab' => ['name' => 'تاقیگە', 'icon' => 'fas fa-flask', 'color' => '#9c27b0'],
    'store' => ['name' => 'کۆگا', 'icon' => 'fas fa-warehouse', 'color' => '#ff9800']
];

$currentTable = $tables[$typeFilter] ?? $tables['general'];
$currentType = $typeLabels[$typeFilter] ?? $typeLabels['general'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];
    $table = $tables[$_POST['category_type']] ?? $currentTable;

    if ($action === 'add') {
        $db->insert($table, [
            'category_name' => trim($_POST['category_name']),
            'description' => trim($_POST['description'] ?? ''),
            'icon' => trim($_POST['icon'] ?? 'fas fa-tag'),
            'color' => trim($_POST['color'] ?? '#6c757d'),
            'is_active' => 1,
            'sort_order' => intval($_POST['sort_order'] ?? 0)
        ]);
        header('Location: ' . APP_URL . '/admin/expense-groups.php?type=' . $_POST['category_type'] . '&added=1');
        exit;
    } elseif ($action === 'edit') {
        $editTable = $tables[$_POST['edit_type']] ?? $currentTable;
        $db->update($editTable, [
            'category_name' => trim($_POST['category_name']),
            'description' => trim($_POST['description'] ?? ''),
            'icon' => trim($_POST['icon'] ?? 'fas fa-tag'),
            'color' => trim($_POST['color'] ?? '#6c757d'),
            'sort_order' => intval($_POST['sort_order'] ?? 0)
        ], 'id = :id', ['id' => intval($_POST['group_id'])]);
        header('Location: ' . APP_URL . '/admin/expense-groups.php?type=' . $_POST['edit_type'] . '&updated=1');
        exit;
    } elseif ($action === 'delete') {
        $delTable = $tables[$_POST['del_type']] ?? $currentTable;
        $db->delete($delTable, 'id = :id', ['id' => intval($_POST['group_id'])]);
        header('Location: ' . APP_URL . '/admin/expense-groups.php?type=' . $_POST['del_type'] . '&deleted=1');
        exit;
    } elseif ($action === 'toggle_status') {
        $togTable = $tables[$_POST['tog_type']] ?? $currentTable;
        $groupId = intval($_POST['group_id']);
        $currentStatus = $db->fetch("SELECT is_active FROM {$togTable} WHERE id = :id", ['id' => $groupId]);
        $newStatus = $currentStatus['is_active'] ? 0 : 1;
        $db->update($togTable, ['is_active' => $newStatus], 'id = :id', ['id' => $groupId]);
        header('Location: ' . APP_URL . '/admin/expense-groups.php?type=' . $_POST['tog_type']);
        exit;
    }
}

// Get groups for current type
$groups = $db->fetchAll("SELECT * FROM {$currentTable} ORDER BY sort_order, category_name");

// Get counts for each type
$counts = [
    'general' => $db->fetch("SELECT COUNT(*) as cnt FROM expense_categories_general")['cnt'] ?? 0,
    'lab' => $db->fetch("SELECT COUNT(*) as cnt FROM expense_categories_lab")['cnt'] ?? 0,
    'store' => $db->fetch("SELECT COUNT(*) as cnt FROM expense_categories_store")['cnt'] ?? 0
];
?>

<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> گرووپ زیادکرا</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> گرووپ نوێکرایەوە</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> گرووپ سڕایەوە</div>
<?php endif; ?>

<style>
.type-tabs { display: flex; gap: 12px; margin-bottom: 20px; }
.type-tab { flex: 1; padding: 16px 20px; border-radius: 12px; text-decoration: none; display: flex; align-items: center; gap: 12px; transition: all 0.3s; border: 2px solid #e0e0e0; background: white; }
.type-tab:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.type-tab.active { border-color: var(--tab-color); background: linear-gradient(135deg, var(--tab-color), var(--tab-color-dark)); color: white; }
.type-tab-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; background: rgba(0,0,0,0.08); }
.type-tab.active .type-tab-icon { background: rgba(255,255,255,0.2); }
.type-tab-content { flex: 1; }
.type-tab-name { font-size: 15px; font-weight: 700; margin-bottom: 2px; }
.type-tab-count { font-size: 12px; opacity: 0.8; }
.groups-panel { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.groups-header { padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; }
.groups-header h3 { margin: 0; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.groups-table { width: 100%; border-collapse: collapse; }
.groups-table th { padding: 12px 16px; text-align: right; font-size: 13px; font-weight: 700; color: #333; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; }
.groups-table td { padding: 12px 16px; font-size: 13px; color: #444; border-bottom: 1px solid #f0f0f0; }
.groups-table tbody tr:hover { background: #f8f9fa; }
.group-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: white; }
.group-name { font-weight: 600; }
.group-desc { font-size: 12px; color: #888; margin-top: 2px; }
.badge-active { background: #e8f5e9; color: #2e7d32; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.badge-inactive { background: #f5f5f5; color: #9e9e9e; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.icon-picker { display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px; max-height: 200px; overflow-y: auto; padding: 8px; background: #f5f5f5; border-radius: 8px; margin-top: 8px; }
.icon-option { width: 36px; height: 36px; border-radius: 6px; border: 2px solid transparent; background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.icon-option:hover { border-color: #2196f3; transform: scale(1.1); }
.icon-option.selected { border-color: #2196f3; background: #e3f2fd; }
.color-picker { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.color-option { width: 32px; height: 32px; border-radius: 6px; border: 3px solid transparent; cursor: pointer; transition: all 0.2s; }
.color-option:hover { transform: scale(1.1); }
.color-option.selected { border-color: #333; }
@media (max-width: 768px) { .type-tabs { flex-direction: column; } }
</style>

<!-- Type Tabs -->
<div class="type-tabs">
    <a href="?type=general" class="type-tab <?= $typeFilter === 'general' ? 'active' : '' ?>" style="--tab-color: #4caf50; --tab-color-dark: #388e3c;">
        <div class="type-tab-icon"><i class="fas fa-coins"></i></div>
        <div class="type-tab-content">
            <div class="type-tab-name">خەرجی گشتی</div>
            <div class="type-tab-count"><?= $counts['general'] ?> گرووپ</div>
        </div>
    </a>
    <a href="?type=lab" class="type-tab <?= $typeFilter === 'lab' ? 'active' : '' ?>" style="--tab-color: #9c27b0; --tab-color-dark: #7b1fa2;">
        <div class="type-tab-icon"><i class="fas fa-flask"></i></div>
        <div class="type-tab-content">
            <div class="type-tab-name">خەرجی تاقیگە</div>
            <div class="type-tab-count"><?= $counts['lab'] ?> گرووپ</div>
        </div>
    </a>
    <a href="?type=store" class="type-tab <?= $typeFilter === 'store' ? 'active' : '' ?>" style="--tab-color: #ff9800; --tab-color-dark: #f57c00;">
        <div class="type-tab-icon"><i class="fas fa-warehouse"></i></div>
        <div class="type-tab-content">
            <div class="type-tab-name">خەرجی کۆگا</div>
            <div class="type-tab-count"><?= $counts['store'] ?> گرووپ</div>
        </div>
    </a>
</div>

<!-- Groups Panel -->
<div class="groups-panel">
    <div class="groups-header">
        <h3><i class="<?= $currentType['icon'] ?>" style="color: <?= $currentType['color'] ?>;"></i> گرووپەکانی خەرجی <?= $currentType['name'] ?></h3>
        <button class="btn btn-primary" onclick="openDialog('addGroupDialog')"><i class="fas fa-plus"></i> گرووپی نوێ</button>
    </div>
    <?php if (empty($groups)): ?>
    <div style="padding: 60px 20px; text-align: center;">
        <i class="fas fa-folder-open" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
        <p style="color: #888; margin: 0;">هیچ گرووپێک نییە</p>
    </div>
    <?php else: ?>
    <table class="groups-table">
        <thead>
            <tr>
                <th style="width: 50px;">ئایکۆن</th>
                <th>ناوی گرووپ</th>
                <th>وەسف</th>
                <th style="width: 80px;">ڕیزبەندی</th>
                <th style="width: 80px;">دۆخ</th>
                <th style="width: 140px;">کردار</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $group): ?>
            <tr>
                <td><div class="group-icon" style="background: <?= $group['color'] ?? '#6c757d' ?>;"><i class="<?= $group['icon'] ?? 'fas fa-tag' ?>"></i></div></td>
                <td>
                    <div class="group-name"><?= htmlspecialchars($group['category_name']) ?></div>
                </td>
                <td><div class="group-desc"><?= htmlspecialchars($group['description'] ?? '') ?: '-' ?></div></td>
                <td style="text-align: center;"><?= $group['sort_order'] ?? 0 ?></td>
                <td style="text-align: center;">
                    <?php if ($group['is_active']): ?>
                        <span class="badge-active">چالاک</span>
                    <?php else: ?>
                        <span class="badge-inactive">ناچالاک</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-sm btn-secondary" onclick='editGroup(<?= json_encode($group) ?>, "<?= $typeFilter ?>")'><i class="fas fa-edit"></i></button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                        <input type="hidden" name="tog_type" value="<?= $typeFilter ?>">
                        <button type="submit" class="btn btn-sm <?= $group['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                            <i class="fas fa-<?= $group['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                        <input type="hidden" name="del_type" value="<?= $typeFilter ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('ئایا دڵنیایت؟')"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Quick Links -->
<div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
    <a href="<?= APP_URL ?>/admin/expenses-general.php" class="btn btn-outline-secondary"><i class="fas fa-coins"></i> خەرجی گشتی</a>
    <a href="<?= APP_URL ?>/admin/expenses-lab.php" class="btn btn-outline-secondary"><i class="fas fa-flask"></i> خەرجی تاقیگە</a>
    <a href="<?= APP_URL ?>/admin/expenses-inventory.php" class="btn btn-outline-secondary"><i class="fas fa-warehouse"></i> خەرجی کۆگا</a>
</div>

<!-- Add Group Dialog -->
<div class="dialog-overlay" id="addGroupDialog">
    <div class="dialog" style="max-width: 500px;">
        <div class="dialog-header" style="background: linear-gradient(135deg, <?= $currentType['color'] ?>, <?= $currentType['color'] ?>cc);"><h4><i class="fas fa-plus-circle"></i> زیادکردنی گرووپ</h4><button class="dialog-close" onclick="closeDialog('addGroupDialog')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="category_type" value="<?= $typeFilter ?>">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">ناوی گرووپ</label>
                    <input type="text" name="category_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">وەسف</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ئایکۆن</label>
                        <input type="hidden" name="icon" id="add_icon" value="fas fa-tag">
                        <div class="icon-picker">
                            <?php
                            $icons = ['fas fa-tag', 'fas fa-bolt', 'fas fa-tint', 'fas fa-wifi', 'fas fa-users', 'fas fa-broom', 'fas fa-truck', 'fas fa-tools', 'fas fa-building', 'fas fa-home', 'fas fa-tooth', 'fas fa-teeth', 'fas fa-smile', 'fas fa-box', 'fas fa-boxes', 'fas fa-pills', 'fas fa-syringe', 'fas fa-fill-drip', 'fas fa-spray-can', 'fas fa-wrench', 'fas fa-desktop', 'fas fa-mortar-pestle', 'fas fa-hand-paper', 'fas fa-file-invoice'];
                            foreach ($icons as $icon): ?>
                            <div class="icon-option <?= $icon === 'fas fa-tag' ? 'selected' : '' ?>" onclick="selectIcon(this, '<?= $icon ?>', 'add')"><i class="<?= $icon ?>"></i></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ڕەنگ</label>
                        <input type="hidden" name="color" id="add_color" value="#6c757d">
                        <div class="color-picker">
                            <?php
                            $colors = ['#f44336', '#e91e63', '#9c27b0', '#673ab7', '#3f51b5', '#2196f3', '#00bcd4', '#009688', '#4caf50', '#8bc34a', '#ff9800', '#795548', '#607d8b', '#6c757d'];
                            foreach ($colors as $color): ?>
                            <div class="color-option <?= $color === '#6c757d' ? 'selected' : '' ?>" style="background: <?= $color ?>;" onclick="selectColor(this, '<?= $color ?>', 'add')"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">ڕیزبەندی</label>
                    <input type="number" name="sort_order" class="form-control" value="0" min="0">
                </div>
            </div>
            <div class="dialog-footer"><button type="button" class="btn btn-secondary" onclick="closeDialog('addGroupDialog')">هەڵوەشاندنەوە</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> زیادکردن</button></div>
        </form>
    </div>
</div>

<!-- Edit Group Dialog -->
<div class="dialog-overlay" id="editGroupDialog">
    <div class="dialog" style="max-width: 500px;">
        <div class="dialog-header"><h4><i class="fas fa-edit"></i> دەستکاریکردنی گرووپ</h4><button class="dialog-close" onclick="closeDialog('editGroupDialog')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="group_id" id="edit_group_id">
            <input type="hidden" name="edit_type" id="edit_type">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">ناوی گرووپ</label>
                    <input type="text" name="category_name" id="edit_category_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">وەسف</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ئایکۆن</label>
                        <input type="hidden" name="icon" id="edit_icon" value="fas fa-tag">
                        <div class="icon-picker" id="edit_icon_picker">
                            <?php foreach ($icons as $icon): ?>
                            <div class="icon-option" data-icon="<?= $icon ?>" onclick="selectIcon(this, '<?= $icon ?>', 'edit')"><i class="<?= $icon ?>"></i></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ڕەنگ</label>
                        <input type="hidden" name="color" id="edit_color" value="#6c757d">
                        <div class="color-picker" id="edit_color_picker">
                            <?php foreach ($colors as $color): ?>
                            <div class="color-option" data-color="<?= $color ?>" style="background: <?= $color ?>;" onclick="selectColor(this, '<?= $color ?>', 'edit')"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">ڕیزبەندی</label>
                    <input type="number" name="sort_order" id="edit_sort_order" class="form-control" min="0">
                </div>
            </div>
            <div class="dialog-footer"><button type="button" class="btn btn-secondary" onclick="closeDialog('editGroupDialog')">هەڵوەشاندنەوە</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button></div>
        </form>
    </div>
</div>

<script>
function selectIcon(el, icon, prefix) {
    document.querySelectorAll('#' + prefix + '_icon_picker .icon-option, #addGroupDialog .icon-option').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById(prefix + '_icon').value = icon;
}

function selectColor(el, color, prefix) {
    document.querySelectorAll('#' + prefix + '_color_picker .color-option, #addGroupDialog .color-option').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById(prefix + '_color').value = color;
}

function editGroup(group, type) {
    document.getElementById('edit_group_id').value = group.id;
    document.getElementById('edit_type').value = type;
    document.getElementById('edit_category_name').value = group.category_name;
    document.getElementById('edit_description').value = group.description || '';
    document.getElementById('edit_icon').value = group.icon || 'fas fa-tag';
    document.getElementById('edit_color').value = group.color || '#6c757d';
    document.getElementById('edit_sort_order').value = group.sort_order || 0;

    // Select correct icon
    document.querySelectorAll('#edit_icon_picker .icon-option').forEach(e => {
        e.classList.remove('selected');
        if (e.dataset.icon === (group.icon || 'fas fa-tag')) e.classList.add('selected');
    });

    // Select correct color
    document.querySelectorAll('#edit_color_picker .color-option').forEach(e => {
        e.classList.remove('selected');
        if (e.dataset.color === (group.color || '#6c757d')) e.classList.add('selected');
    });

    openDialog('editGroupDialog');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
