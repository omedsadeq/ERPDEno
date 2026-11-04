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
$hidePageHeader = true;   // the finance tabs name the page
$breadcrumb = [['title' => 'خەرجیەکان'], ['title' => 'گرووپەکانی خەرجی']];

require_once __DIR__ . '/../includes/expense-subgroup.php';
require_once __DIR__ . '/../includes/header.php';

// Table mapping: the groups of each kind, and the expenses that use them
$tables = [
    'general' => 'expense_categories_general',
    'lab' => 'expense_categories_lab',
    'store' => 'expense_categories_store'
];
$expenseTables = [
    'general' => 'expenses_general',
    'lab' => 'expenses_lab',
    'store' => 'expenses_store'
];

// Type filter
$typeFilter = isset($tables[$_GET['type'] ?? '']) ? $_GET['type'] : 'general';

// Type labels
$typeLabels = [
    'general' => ['name' => 'گشتی', 'icon' => 'fas fa-coins', 'color' => '#4caf50'],
    'lab' => ['name' => 'تاقیگە', 'icon' => 'fas fa-flask', 'color' => '#9c27b0'],
    'store' => ['name' => 'کۆگا', 'icon' => 'fas fa-warehouse', 'color' => '#ff9800']
];

$currentTable = $tables[$typeFilter];
$currentType = $typeLabels[$typeFilter];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];
    $postType = $_POST['category_type'] ?? $_POST['edit_type'] ?? $_POST['del_type'] ?? $_POST['tog_type'] ?? '';
    if (!isset($tables[$postType])) {
        $postType = $typeFilter;
    }
    $table = $tables[$postType];
    $back = APP_URL . '/admin/expense-groups.php?type=' . $postType;
    $groupId = intval($_POST['group_id'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        // One name once per kind: the dropdowns list groups by name alone.
        $name = trim($_POST['category_name'] ?? '');
        if ($name === '') {
            header('Location: ' . $back . '&error=name');
            exit;
        }
        if ($db->fetch("SELECT id FROM {$table} WHERE category_name = :n AND id <> :id", ['n' => $name, 'id' => $groupId])) {
            header('Location: ' . $back . '&error=duplicate');
            exit;
        }
        $fields = [
            'category_name' => $name,
            'description' => trim($_POST['description'] ?? ''),
            'icon' => trim($_POST['icon'] ?? 'fas fa-tag'),
            'color' => trim($_POST['color'] ?? '#6c757d'),
            'sort_order' => intval($_POST['sort_order'] ?? 0)
        ];
        if ($action === 'add') {
            $db->insert($table, $fields + ['is_active' => 1]);
            header('Location: ' . $back . '&added=1');
        } else {
            $db->update($table, $fields, 'id = :id', ['id' => $groupId]);
            header('Location: ' . $back . '&updated=1');
        }
        exit;
    } elseif ($action === 'delete') {
        // A group in use stays: deleting it would leave its expenses with no
        // group. It can be hidden from the dropdowns instead.
        if ($db->count($expenseTables[$postType], 'category_id = :id', ['id' => $groupId]) > 0) {
            header('Location: ' . $back . '&error=used');
            exit;
        }
        $db->delete('expense_subgroups', 'kind = :kind AND category_id = :id', ['kind' => $postType, 'id' => $groupId]);
        $db->delete($table, 'id = :id', ['id' => $groupId]);
        header('Location: ' . $back . '&deleted=1');
        exit;
    } elseif ($action === 'toggle_status') {
        $currentStatus = $db->fetch("SELECT is_active FROM {$table} WHERE id = :id", ['id' => $groupId]);
        $newStatus = $currentStatus['is_active'] ? 0 : 1;
        $db->update($table, ['is_active' => $newStatus], 'id = :id', ['id' => $groupId]);
        header('Location: ' . $back);
        exit;
    } elseif ($action === 'subgroup') {
        // The sub-groups («لق») of a group, kept by the same rules as the groups.
        $subId = intval($_POST['subgroup_id'] ?? 0);
        $sub = $db->fetch("SELECT * FROM expense_subgroups WHERE id = :id AND kind = :kind", ['id' => $subId, 'kind' => $postType]);
        $do = $_POST['sub_do'] ?? 'save';

        if ($sub && $do === 'delete') {
            if ($db->count($expenseTables[$postType], 'subgroup_id = :id', ['id' => $subId]) > 0) {
                header('Location: ' . $back . '&error=sub_used');
                exit;
            }
            $db->delete('expense_subgroups', 'id = :id', ['id' => $subId]);
        } elseif ($sub && $do === 'toggle') {
            $db->update('expense_subgroups', ['is_active' => $sub['is_active'] ? 0 : 1], 'id = :id', ['id' => $subId]);
        } else {
            $name = trim($_POST['sub_name'] ?? '');
            $parent = $sub ? (int)$sub['category_id'] : $groupId;
            if ($name === '' || $db->count($table, 'id = :id', ['id' => $parent]) === 0) {
                header('Location: ' . $back . '&error=sub_name');
                exit;
            }
            $same = $db->fetch(
                "SELECT id FROM expense_subgroups WHERE kind = :kind AND category_id = :category AND name = :name AND id <> :id",
                ['kind' => $postType, 'category' => $parent, 'name' => $name, 'id' => $subId]
            );
            if ($same) {
                header('Location: ' . $back . '&error=sub_duplicate');
                exit;
            }
            if ($sub) {
                $db->update('expense_subgroups', ['name' => $name], 'id = :id', ['id' => $subId]);
            } else {
                $db->insert('expense_subgroups', ['kind' => $postType, 'category_id' => $parent, 'name' => $name]);
            }
        }
        header('Location: ' . $back . '&sub_saved=1');
        exit;
    }
}

// Get groups for current type
$groups = $db->fetchAll("SELECT * FROM {$currentTable} ORDER BY sort_order, category_name");

// How much each group is used: its expenses and their total
$usage = [];
foreach ($db->fetchAll("SELECT category_id, COUNT(*) AS cnt, SUM(amount) AS total FROM {$expenseTables[$typeFilter]} GROUP BY category_id") as $u) {
    $usage[(int)$u['category_id']] = $u;
}

// The sub-groups of each group, and how much each is used
$subgroups = expense_subgroups_by_group($typeFilter);
$subUsage = [];
foreach ($db->fetchAll("SELECT subgroup_id, COUNT(*) AS cnt, SUM(amount) AS total FROM {$expenseTables[$typeFilter]} WHERE subgroup_id IS NOT NULL GROUP BY subgroup_id") as $u) {
    $subUsage[(int)$u['subgroup_id']] = $u;
}

// Get counts for each type
$counts = [];
foreach ($tables as $type => $table) {
    $counts[$type] = $db->count($table);
}
$groupErrors = [
    'name' => 'ناوی گرووپ پێویستە',
    'duplicate' => 'گرووپێک بەم ناوە هەیە — ناوێکی تر بنووسە',
    'used' => 'ئەم گرووپە خەرجی لەسەر تۆمارکراوە و ناسڕدرێتەوە — دەتوانیت بیشاریتەوە',
    'sub_name' => 'ناوی لق پێویستە',
    'sub_duplicate' => 'ئەم گرووپە لقێکی بەم ناوەی هەیە — ناوێکی تر بنووسە',
    'sub_used' => 'ئەم لقە خەرجی لەسەر تۆمارکراوە و ناسڕدرێتەوە — دەتوانیت بیشاریتەوە',
];
?>

<?php if (isset($groupErrors[$_GET['error'] ?? ''])): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle alert-icon"></i> <?= $groupErrors[$_GET['error']] ?></div>
<?php endif; ?>

<?php if (isset($_GET['sub_saved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> لقەکان نوێکرانەوە</div>
<?php endif; ?>

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
.type-tabs { display: flex; gap: 10px; margin-bottom: 12px; }
.type-tab { flex: 1; padding: 8px 14px; border-radius: 12px; text-decoration: none; display: flex; align-items: center; gap: 12px; transition: all 0.3s; border: 2px solid #e0e0e0; background: white; }
.type-tab:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.type-tab.active { border-color: var(--tab-color); background: linear-gradient(135deg, var(--tab-color), var(--tab-color-dark)); color: white; }
.type-tab-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; background: rgba(0,0,0,0.08); }
.type-tab.active .type-tab-icon { background: rgba(255,255,255,0.2); }
.type-tab-content { flex: 1; }
.type-tab-name { font-size: 15px; font-weight: 700; margin-bottom: 2px; }
.type-tab-count { font-size: 12px; opacity: 0.8; }
.groups-panel { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #e0e0e0; }
.groups-header { padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; }
.groups-header h3 { margin: 0; font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.groups-table { width: 100%; border-collapse: collapse; }
.groups-table th { padding: 8px 12px; text-align: right; font-size: 13px; font-weight: 700; color: #333; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; }
.groups-table td { padding: 6px 12px; font-size: 13px; color: #444; border-bottom: 1px solid #f0f0f0; }
.groups-table tbody tr:hover { background: #f8f9fa; }
.group-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: white; }
.group-name { font-weight: 600; }
.group-desc { font-size: 12px; color: #888; margin-top: 2px; }
.group-use { font-size: 12px; color: #888; }
.group-use b { color: #c62828; }
.group-actions { display: flex; gap: 4px; justify-content: flex-end; white-space: nowrap; }
.group-actions form { margin: 0; }
.sub-list { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.sub-chip, .sub-add { padding: 2px 9px; border-radius: 12px; font: inherit; font-size: 12px; cursor: pointer; }
.sub-chip { border: 1px solid; font-weight: 600; }
.sub-chip small { margin-right: 3px; opacity: .75; }
.sub-chip.is-off { opacity: .5; text-decoration: line-through; }
.sub-add { border: 1px dashed #b0bec5; background: none; color: #607d8b; }
.sub-add:hover { border-color: #2196f3; color: #2196f3; }
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
                <th>لقەکان</th>
                <th>وەسف</th>
                <th style="width: 170px;">بەکارهێنان</th>
                <th style="width: 80px;">دۆخ</th>
                <th style="width: 120px;">کردار</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $group): ?>
            <tr>
                <td><div class="group-icon" style="background: <?= $group['color'] ?? '#6c757d' ?>;"><i class="<?= $group['icon'] ?? 'fas fa-tag' ?>"></i></div></td>
                <td>
                    <div class="group-name"><?= htmlspecialchars($group['category_name']) ?></div>
                </td>
                <td><div class="sub-list">
                    <?php $groupColor = $group['color'] ?? '#6c757d'; ?>
                    <?php foreach ($subgroups[(int)$group['id']] ?? [] as $sub): $subUsed = $subUsage[(int)$sub['id']] ?? null; ?>
                    <button type="button" class="sub-chip<?= $sub['is_active'] ? '' : ' is-off' ?>" style="background: <?= $groupColor ?>15; color: <?= $groupColor ?>; border-color: <?= $groupColor ?>55;" title="<?= $subUsed ? (int)$subUsed['cnt'] . ' خەرجی · ' . number_format((float)$subUsed['total']) : 'هێشتا خەرجی لەسەر نییە' ?>" onclick='openSubgroup(<?= json_encode(['id' => (int)$group['id'], 'name' => $group['category_name']], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG) ?>, <?= json_encode(['id' => (int)$sub['id'], 'name' => $sub['name'], 'active' => (bool)$sub['is_active'], 'used' => (bool)$subUsed], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'><?= htmlspecialchars($sub['name']) ?><?php if ($subUsed): ?><small><?= (int)$subUsed['cnt'] ?></small><?php endif; ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="sub-add" onclick='openSubgroup(<?= json_encode(['id' => (int)$group['id'], 'name' => $group['category_name']], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'><i class="fas fa-plus"></i> لق</button>
                </div></td>
                <td><div class="group-desc"><?= htmlspecialchars($group['description'] ?? '') ?: '-' ?></div></td>
                <?php $used = $usage[(int)$group['id']] ?? null; ?>
                <td class="group-use"><?= $used ? (int)$used['cnt'] . ' خەرجی · <b>' . number_format((float)$used['total']) . '</b>' : '—' ?></td>
                <td style="text-align: center;">
                    <?php if ($group['is_active']): ?>
                        <span class="badge-active">چالاک</span>
                    <?php else: ?>
                        <span class="badge-inactive">ناچالاک</span>
                    <?php endif; ?>
                </td>
                <td><div class="group-actions">
                    <button class="btn btn-sm btn-secondary" onclick='editGroup(<?= json_encode($group, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG) ?>, "<?= $typeFilter ?>")'><i class="fas fa-edit"></i></button>
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                        <input type="hidden" name="tog_type" value="<?= $typeFilter ?>">
                        <button type="submit" class="btn btn-sm <?= $group['is_active'] ? 'btn-warning' : 'btn-success' ?>" title="<?= $group['is_active'] ? 'شاردنەوە لە لیستەکان' : 'چالاککردنەوە' ?>">
                            <i class="fas fa-<?= $group['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                        </button>
                    </form>
                    <?php if (!$used): // in use, it can only be hidden ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                        <input type="hidden" name="del_type" value="<?= $typeFilter ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('ئایا دڵنیایت؟')"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
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

<!-- Sub-group Dialog -->
<div class="dialog-overlay" id="subgroupDialog">
    <div class="dialog" style="max-width: 420px;">
        <div class="dialog-header" style="background: linear-gradient(135deg, <?= $currentType['color'] ?>, <?= $currentType['color'] ?>cc);"><h4><i class="fas fa-code-branch"></i> <span id="sub_title"></span></h4><button class="dialog-close" onclick="closeDialog('subgroupDialog')"><i class="fas fa-times"></i></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="subgroup">
            <input type="hidden" name="category_type" value="<?= $typeFilter ?>">
            <input type="hidden" name="group_id" id="sub_group_id">
            <input type="hidden" name="subgroup_id" id="sub_id">
            <input type="hidden" name="sub_do" id="sub_do" value="save">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label required">ناوی لق</label>
                    <input type="text" name="sub_name" id="sub_name" class="form-control" maxlength="100" required>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('subgroupDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
                <button type="submit" class="btn btn-warning" id="sub_toggle" onclick="return subAction('toggle')"></button>
                <button type="submit" class="btn btn-danger" id="sub_delete" onclick="return subAction('delete')"><i class="fas fa-trash"></i></button>
            </div>
        </form>
    </div>
</div>

<script>
// sub is left out for a new one. A sub-group in use can only be hidden.
function openSubgroup(group, sub) {
    document.getElementById('sub_title').textContent = (sub ? 'دەستکاریکردنی لق' : 'لقی نوێ') + ' — ' + group.name;
    document.getElementById('sub_group_id').value = group.id;
    document.getElementById('sub_id').value = sub ? sub.id : '';
    document.getElementById('sub_name').value = sub ? sub.name : '';
    document.getElementById('sub_do').value = 'save';
    var toggle = document.getElementById('sub_toggle');
    toggle.style.display = sub ? '' : 'none';
    toggle.innerHTML = '<i class="fas fa-' + (sub && sub.active ? 'eye-slash' : 'eye') + '"></i>';
    toggle.title = sub && sub.active ? 'شاردنەوە لە لیستەکان' : 'چالاککردنەوە';
    document.getElementById('sub_delete').style.display = sub && !sub.used ? '' : 'none';
    openDialog('subgroupDialog');
    document.getElementById('sub_name').focus();
}

function subAction(action) {
    var go = action !== 'delete' || confirm('ئایا دڵنیایت؟');
    document.getElementById('sub_do').value = go ? action : 'save';
    return go;
}

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
