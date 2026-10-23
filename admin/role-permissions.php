<?php
/**
 * Role Permissions Management Page
 * بەڕێوەبردنی مۆڵەتەکانی ڕۆڵەکان
 */

$pageTitle = 'مۆڵەتی ڕۆڵەکان';
$currentPage = 'role-permissions';

// Require admin access BEFORE any output — after header.php has printed HTML
// the redirect in requireAdmin() can no longer take effect.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$auth = new Auth();
$auth->requireAdmin();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Permission.php';

$permissionManager = Permission::getInstance();

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    if ($_POST['action'] === 'update_permissions') {
        $role = $_POST['role'] ?? '';
        $permissions = $_POST['permissions'] ?? [];

        if ($role && $role !== ROLE_ADMIN) {
            // Get all permission keys
            $allPermissions = $permissionManager->getAllPermissionDefinitions();
            $allKeys = [];
            foreach ($allPermissions as $category => $perms) {
                foreach ($perms as $perm) {
                    $allKeys[] = $perm['permission_key'];
                }
            }

            // Build permission array (checked = 1, unchecked = 0)
            $permissionUpdates = [];
            foreach ($allKeys as $key) {
                $permissionUpdates[$key] = isset($permissions[$key]) ? 1 : 0;
            }

            $result = $permissionManager->bulkUpdatePermissions($role, $permissionUpdates);
            if ($result['success']) {
                AuditLog::log('update_role_permissions', 'role_permissions', null, null,
                    ['role' => $role, 'granted' => array_keys(array_filter($permissionUpdates))]);
                $message = 'مۆڵەتەکان بە سەرکەوتوویی نوێکرانەوە';
            } else {
                $error = $result['message'] ?? 'هەڵەیەک ڕوویدا';
            }
        }
    }
}

// Get selected role (default to receptionist)
$selectedRole = $_GET['role'] ?? ROLE_RECEPTIONIST;
if ($selectedRole === ROLE_ADMIN) {
    $selectedRole = ROLE_RECEPTIONIST;
}

// Get all manageable roles
$roles = $permissionManager->getManageableRoles();

// Get all permission definitions grouped by category
$permissionDefinitions = $permissionManager->getAllPermissionDefinitions();

// Get current permissions for selected role
$rolePermissions = $permissionManager->getRolePermissions($selectedRole);
$rolePermissionMap = [];
foreach ($rolePermissions as $perm) {
    $rolePermissionMap[$perm['permission_key']] = (bool)$perm['is_allowed'];
}
?>

<style>
.permissions-container {
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-title h2 {
    margin: 0;
    font-size: 22px;
    color: #1a1a1a;
}

.page-title .icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
}

/* Role Tabs */
.role-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.role-tab {
    padding: 10px 20px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    color: #666;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.role-tab:hover {
    border-color: #9c27b0;
    color: #9c27b0;
}

.role-tab.active {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    border-color: #9c27b0;
    color: white;
}

.role-tab .role-icon {
    font-size: 16px;
}

/* Permission Categories */
.permission-category {
    background: white;
    border-radius: 12px;
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.category-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, #f5f5f5, #eeeeee);
    border-bottom: 2px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.category-title {
    font-size: 16px;
    font-weight: 700;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.category-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
}

.category-icon.main { background: linear-gradient(135deg, #2196f3, #1976d2); }
.category-icon.patients { background: linear-gradient(135deg, #4caf50, #388e3c); }
.category-icon.doctors { background: linear-gradient(135deg, #9c27b0, #7b1fa2); }
.category-icon.xray { background: linear-gradient(135deg, #ff9800, #f57c00); }
.category-icon.financial { background: linear-gradient(135deg, #00bcd4, #0097a7); }
.category-icon.admin { background: linear-gradient(135deg, #f44336, #d32f2f); }
.category-icon.actions { background: linear-gradient(135deg, #607d8b, #455a64); }
.category-icon.letters { background: linear-gradient(135deg, #3b6ea8, #1f3b63); }

.category-toggle-all {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #666;
}

.category-body {
    padding: 16px 20px;
}

/* Permission Items */
.permission-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.permission-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    background: #fafafa;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    transition: all 0.2s;
    cursor: pointer;
}

.permission-item:hover {
    border-color: #9c27b0;
    background: #f5f0f7;
}

.permission-item.allowed {
    background: #e8f5e9;
    border-color: #4caf50;
}

.permission-checkbox {
    width: 20px;
    height: 20px;
    accent-color: #9c27b0;
    cursor: pointer;
}

.permission-info {
    flex: 1;
    margin-right: 12px;
}

.permission-name {
    font-weight: 600;
    color: #333;
    font-size: 14px;
}

.permission-key {
    font-size: 11px;
    color: #888;
    font-family: var(--app-font-family);
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    padding: 20px;
    background: linear-gradient(135deg, #f5f0f7, #ede7f0);
    border-radius: 12px;
    margin-top: 20px;
}

.btn {
    padding: 12px 28px;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #9c27b0, #7b1fa2);
    color: white;
    box-shadow: 0 3px 10px rgba(156, 39, 176, 0.3);
}

.btn-primary:hover {
    background: linear-gradient(135deg, #7b1fa2, #6a1b9a);
}

.btn-secondary {
    background: #f5f5f5;
    color: #666;
    border: 1.5px solid #e0e0e0;
}

.btn-secondary:hover {
    background: #e0e0e0;
}

/* Alerts */
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #c8e6c9;
}

.alert-error {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ffcdd2;
}

/* Admin Notice */
.admin-notice {
    background: linear-gradient(135deg, #fff3e0, #ffe0b2);
    border: 1.5px solid #ffcc80;
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.admin-notice .notice-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #ff9800, #f57c00);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
}

.admin-notice .notice-text {
    color: #e65100;
    font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .role-tabs {
        flex-direction: column;
        width: 100%;
    }

    .role-tab {
        justify-content: center;
    }

    .permission-grid {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn {
        justify-content: center;
    }
}
</style>

<?php if ($message): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="permissions-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title">
            <div class="icon"><i class="fas fa-shield-alt"></i></div>
            <div>
                <h2>بەڕێوەبردنی مۆڵەتەکان</h2>
                <small style="color: #888;">دیاریکردنی مۆڵەتی پەڕەکان بۆ هەر ڕۆڵێک</small>
            </div>
        </div>
    </div>

    <!-- Admin Notice -->
    <div class="admin-notice">
        <div class="notice-icon"><i class="fas fa-info"></i></div>
        <div class="notice-text">
            <strong>تێبینی:</strong> بەڕێوەبەر هەمیشە دەستگەیشتن بە هەموو پەڕەکانی هەیە و ناتوانرێت بگۆڕدرێت.
        </div>
    </div>

    <!-- Role Tabs -->
    <div class="role-tabs">
        <?php foreach ($roles as $roleKey => $roleLabel): ?>
        <a href="?role=<?= $roleKey ?>" class="role-tab <?= $selectedRole === $roleKey ? 'active' : '' ?>">
            <i class="role-icon fas <?php
                echo match($roleKey) {
                    ROLE_DOCTOR => 'fa-user-md',
                    ROLE_RECEPTIONIST => 'fa-user-tie',
                    ROLE_XRAY_DEPARTMENT => 'fa-x-ray',
                    default => 'fa-user'
                };
            ?>"></i>
            <?= $roleLabel ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Permissions Form -->
    <form method="POST" action="">
        <input type="hidden" name="action" value="update_permissions">
        <input type="hidden" name="role" value="<?= htmlspecialchars($selectedRole) ?>">

        <?php
        $categoryIcons = [
            'main' => 'fa-home',
            'patients' => 'fa-user-injured',
            'doctors' => 'fa-user-md',
            'xray' => 'fa-x-ray',
            'financial' => 'fa-money-bill-wave',
            'admin' => 'fa-cog',
            'actions' => 'fa-bolt',
            'letters' => 'fa-envelope-open-text'
        ];

        foreach ($permissionDefinitions as $category => $permissions):
            $categoryLabel = $permissionManager->getCategoryLabel($category);
        ?>
        <div class="permission-category">
            <div class="category-header">
                <div class="category-title">
                    <div class="category-icon <?= $category ?>">
                        <i class="fas <?= $categoryIcons[$category] ?? 'fa-folder' ?>"></i>
                    </div>
                    <?= $categoryLabel ?>
                    <span style="font-weight: 400; color: #888; font-size: 13px;">(<?= count($permissions) ?>)</span>
                </div>
                <div class="category-toggle-all">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <input type="checkbox" class="category-toggle" data-category="<?= $category ?>" style="width: 16px; height: 16px;">
                        هەموو
                    </label>
                </div>
            </div>
            <div class="category-body">
                <div class="permission-grid">
                    <?php foreach ($permissions as $perm):
                        $isAllowed = $rolePermissionMap[$perm['permission_key']] ?? false;
                    ?>
                    <label class="permission-item <?= $isAllowed ? 'allowed' : '' ?>" data-category="<?= $category ?>">
                        <input type="checkbox"
                               class="permission-checkbox"
                               name="permissions[<?= $perm['permission_key'] ?>]"
                               value="1"
                               <?= $isAllowed ? 'checked' : '' ?>
                               onchange="this.closest('.permission-item').classList.toggle('allowed', this.checked)">
                        <div class="permission-info">
                            <div class="permission-name"><?= htmlspecialchars($perm['permission_name_ku']) ?></div>
                            <div class="permission-key"><?= htmlspecialchars($perm['permission_key']) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Form Actions -->
        <div class="form-actions">
            <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-secondary">
                <i class="fas fa-arrow-right"></i>
                گەڕانەوە
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                پاشەکەوتکردن
            </button>
        </div>
    </form>
</div>

<script>
// Toggle all permissions in a category
document.querySelectorAll('.category-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const category = this.dataset.category;
        const isChecked = this.checked;
        document.querySelectorAll(`.permission-item[data-category="${category}"] .permission-checkbox`).forEach(cb => {
            cb.checked = isChecked;
            cb.closest('.permission-item').classList.toggle('allowed', isChecked);
        });
    });

    // Initialize toggle state based on checkboxes
    const category = toggle.dataset.category;
    const checkboxes = document.querySelectorAll(`.permission-item[data-category="${category}"] .permission-checkbox`);
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    toggle.checked = allChecked;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
