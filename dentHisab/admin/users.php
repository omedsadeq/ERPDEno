<?php
/**
 * User Management Page (Admin Only)
 * سیستەمی زیرەکی کلینیکی ددان
 */

// Include config and required classes first for processing before output
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

// Check admin access before any output
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$errors = [];
$success = '';

$VALID_ROLES = ['admin', 'doctor', 'receptionist', 'xray_department', 'resident_receptionist', 'accountant'];

// Handle actions BEFORE including header (so header() redirects work)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'receptionist';
        if (!in_array($role, $VALID_ROLES, true)) $errors['role'] = 'ڕۆڵی نادروست';

        if (empty($username)) $errors['username'] = 'ناوی بەکارهێنەر پێویستە';
        if (empty($password)) $errors['password'] = 'وشەی نهێنی پێویستە';
        if (strlen($password) < 6) $errors['password'] = 'وشەی نهێنی دەبێت ٦ پیت بێت';
        if (empty($fullName)) $errors['full_name'] = 'ناوی تەواو پێویستە';

        // Check if username exists
        $existing = $db->fetch("SELECT id FROM users WHERE username = :username", ['username' => $username]);
        if ($existing) $errors['username'] = 'ئەم ناوی بەکارهێنەرە پێشتر بەکارهاتووە';

        if (empty($errors)) {
            $newUserId = $db->insert('users', [
                'username'    => $username,
                'password'    => password_hash($password, PASSWORD_DEFAULT),
                'full_name'   => $fullName,
                'email'       => $email,
                'phone'       => $phone,
                'role'        => $role,
                'room_number' => $role === 'doctor' ? (trim($_POST['room_number'] ?? '') ?: null) : null,
                'room_number_2' => $role === 'doctor' ? (trim($_POST['room_number_2'] ?? '') ?: null) : null,
                'is_active'   => 1
            ]);
            AuditLog::log('create_user', 'users', $newUserId, null,
                ['username' => $username, 'full_name' => $fullName, 'role' => $role]);
            // Keep the clinical doctors directory in sync (new-visit dropdown)
            DoctorSync::syncFromUser($newUserId);
            header('Location: ' . APP_URL . '/admin/users.php?created=1');
            exit;
        }
    } elseif ($action === 'update') {
        $userId = intval($_POST['user_id']);
        $role = $_POST['role'];
        if (!in_array($role, $VALID_ROLES, true)) {
            header('Location: ' . APP_URL . '/admin/users.php?error=role');
            exit;
        }
        $oldUser = $db->fetch("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
        $data = [
            'full_name'      => trim($_POST['full_name']),
            'email'          => trim($_POST['email']),
            'phone'          => trim($_POST['phone']),
            'role'           => $role,
            'specialization' => trim($_POST['specialty'] ?? ''),
            'room_number'    => $role === 'doctor' ? (trim($_POST['room_number'] ?? '') ?: null) : null,
            'room_number_2'  => $role === 'doctor' ? (trim($_POST['room_number_2'] ?? '') ?: null) : null,
            'is_active'      => isset($_POST['is_active']) ? 1 : 0
        ];
        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 6) {
                header('Location: ' . APP_URL . '/admin/users.php?error=password');
                exit;
            }
            $data['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        $db->update('users', $data, 'id = :id', ['id' => $userId]);
        AuditLog::logChange('update_user', 'users', $userId, $oldUser ?: [], $data);
        // Sync name / role / active flag to the doctors directory
        DoctorSync::syncFromUser($userId);
        header('Location: ' . APP_URL . '/admin/users.php?updated=1');
        exit;
    } elseif ($action === 'delete') {
        $userId = intval($_POST['user_id']);
        if ($userId != $currentUser['id']) {
            $db->update('users', ['is_active' => 0], 'id = :id', ['id' => $userId]);
            AuditLog::log('deactivate_user', 'users', $userId);
            // Deactivated doctor accounts disappear from the new-visit dropdown too
            DoctorSync::syncFromUser($userId);
            header('Location: ' . APP_URL . '/admin/users.php?deleted=1');
            exit;
        }
    }
}

// Now include header for page output
$pageTitle = 'بەڕێوەبردنی بەکارهێنەران';
$breadcrumb = [['title' => 'بەڕێوەبردنی بەکارهێنەران']];

require_once __DIR__ . '/../includes/header.php';

// Get users — never select the password hash (this list is JSON-encoded into the page)
$users = $db->fetchAll(
    "SELECT id, username, full_name, role, specialization, room_number, room_number_2, email, phone,
            profile_image, commission_rate, is_active, created_at, updated_at
     FROM users ORDER BY role, full_name"
);
$roleLabels = [
    'admin' => 'بەڕێوەبەر',
    'doctor' => 'دکتۆر',
    'receptionist' => 'کارمەند',
    'xray_department' => 'بەشی ئێکسڕەی',
    'accountant' => 'ژمێریار'
];
?>

<style>
/* Modern Compact Users Page */
.stats-grid-users {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 12px;
}

.stat-card-user {
    background: linear-gradient(135deg, var(--bg-color), var(--hover-color));
    border-radius: 8px;
    padding: 10px 14px;
    text-align: center;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-user::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--accent-color);
}

.stat-card-user:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: var(--accent-color);
}

.stat-card-user.stat-admin {
    --bg-color: #e3f2fd;
    --hover-color: #bbdefb;
    --border-color: #90caf9;
    --accent-color: #2196f3;
}

.stat-card-user.stat-doctor {
    --bg-color: #e8f5e9;
    --hover-color: #c8e6c9;
    --border-color: #a5d6a7;
    --accent-color: #4caf50;
}

.stat-card-user.stat-staff {
    --bg-color: #fff3e0;
    --hover-color: #ffe0b2;
    --border-color: #ffcc80;
    --accent-color: #ff9800;
}

.stat-icon-user {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--accent-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    margin: 0 auto 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

.stat-value-user {
    font-size: 24px;
    font-weight: 800;
    color: var(--accent-color);
    line-height: 1.2;
    margin-bottom: 2px;
}

.stat-label-user {
    font-size: 11px;
    color: #546e7a;
    font-weight: 600;
}

/* Enhanced Panel Header */
.panel-header-users {
    background: linear-gradient(135deg, #0078d4, #0063b1);
    padding: 12px 16px;
    border-radius: 10px 10px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.panel-title-users {
    color: white;
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.panel-title-users i {
    font-size: 18px;
}

/* Compact Users Table */
.users-table {
    width: 100%;
    border-collapse: collapse;
}

.users-table thead {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    border-bottom: 2px solid #e0e0e0;
}

.users-table th {
    padding: 9px 10px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}

.users-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.users-table tbody tr:hover {
    background: linear-gradient(to left, #f8f9fa, #ffffff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.users-table td {
    padding: 10px;
    font-size: 13px;
    color: #2c3e50;
    text-align: center;
}

@media (max-width: 1024px) {
    .stats-grid-users {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> بەکارهێنەر دروستکرا</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> بەکارهێنەر نوێکرایەوە</div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid-users">
    <div class="stat-card-user stat-admin">
        <div class="stat-icon-user">
            <i class="fas fa-user-shield"></i>
        </div>
        <div class="stat-value-user"><?= count(array_filter($users, fn($u) => $u['role'] === 'admin')) ?></div>
        <div class="stat-label-user">بەڕێوەبەران</div>
    </div>
    <div class="stat-card-user stat-doctor">
        <div class="stat-icon-user">
            <i class="fas fa-user-md"></i>
        </div>
        <div class="stat-value-user"><?= count(array_filter($users, fn($u) => $u['role'] === 'doctor')) ?></div>
        <div class="stat-label-user">دکتۆرەکان</div>
    </div>
    <div class="stat-card-user stat-staff">
        <div class="stat-icon-user">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-value-user"><?= count(array_filter($users, fn($u) => $u['role'] === 'receptionist')) ?></div>
        <div class="stat-label-user">کارمەندان</div>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <h3 class="panel-title">
            <i class="fas fa-users-cog"></i>
            بەکارهێنەران
            <span class="badge"><?= count($users) ?></span>
        </h3>
        <button class="btn btn-primary" onclick="openDialog('newUserDialog')">
            <i class="fas fa-plus"></i>
            بەکارهێنەری نوێ
        </button>
    </div>

    <div class="panel-body">
        <div class="table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ناو</th>
                        <th>ناوی بەکارهێنەر</th>
                        <th>ڕۆڵ</th>
                        <th>ئیمەیڵ</th>
                        <th>مۆبایل</th>
                        <th>دۆخ</th>
                        <th>کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr class="<?= !$user['is_active'] ? 'opacity-50' : '' ?>">
                        <td>
                            <div class="d-flex align-center gap-2">
                                <div class="user-avatar" style="width: 36px; height: 36px; font-size: 14px;">
                                    <?= mb_substr($user['full_name'], 0, 1) ?>
                                </div>
                                <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td>
                            <?php
                            $roleBadgeClasses = [
                                'admin' => 'badge-blue',
                                'doctor' => 'badge-green',
                                'xray_department' => 'badge-yellow',
                                'accountant' => 'badge-purple',
                                'receptionist' => ''
                            ];
                            $roleBadgeClass = $roleBadgeClasses[$user['role']] ?? '';
                            ?>
                            <span class="badge <?= $roleBadgeClass ?>">
                                <?= $roleLabels[$user['role']] ?? $user['role'] ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
                        <td><?= htmlspecialchars($user['phone'] ?? '') ?></td>
                        <td>
                            <span class="badge <?= $user['is_active'] ? 'badge-green' : 'badge-red' ?>">
                                <?= $user['is_active'] ? 'چالاک' : 'ناچالاک' ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)"
                                        class="btn btn-icon btn-sm btn-primary" title="دەستکاری">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($user['id'] != $currentUser['id']): ?>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-icon btn-sm btn-danger" title="ناچالاککردن"
                                            onclick="return confirm('ئایا دڵنیایت؟')">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- New User Dialog -->
<div class="dialog-overlay" id="newUserDialog">
    <div class="dialog usr-dialog-modern">
        <div class="usr-dialog-header">
            <h4><i class="fas fa-user-plus"></i> بەکارهێنەری نوێ</h4>
            <button type="button" class="usr-dialog-close" onclick="closeDialog('newUserDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="newUserForm" onsubmit="return validateNewUserForm(event)">
            <input type="hidden" name="action" value="create">
            <div class="usr-dialog-body">
                <div id="newUserErrors" class="alert alert-error mb-2" style="display: none;"></div>
                <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-2">
                    <?= implode('<br>', $errors) ?>
                </div>
                <?php endif; ?>
                <div class="usr-form-grid">
                    <div class="usr-form-field">
                        <label class="usr-form-label"><span class="usr-required">*</span> ناوی تەواو</label>
                        <input type="text" name="full_name" id="new_full_name" class="usr-form-control" required>
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label"><span class="usr-required">*</span> ناوی بەکارهێنەر</label>
                        <input type="text" name="username" id="new_username" class="usr-form-control" required pattern="[a-zA-Z0-9_]+" placeholder="تەنها پیت و ژمارە">
                        <small id="usernameStatus" style="display: none; margin-top: 4px;"></small>
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label"><span class="usr-required">*</span> وشەی نهێنی</label>
                        <input type="password" name="password" id="new_password" class="usr-form-control" required minlength="6">
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label"><span class="usr-required">*</span> ڕۆڵ</label>
                        <select name="role" class="usr-form-control" required>
                            <option value="receptionist">کارمەند</option>
                            <option value="doctor">دکتۆر</option>
                            <option value="xray_department">بەشی ئێکسڕەی</option>
                            <option value="accountant">ژمێریار</option>
                            <option value="admin">بەڕێوەبەر</option>
                        </select>
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label">ئیمەیڵ</label>
                        <input type="email" name="email" class="usr-form-control">
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label">مۆبایل</label>
                        <input type="tel" name="phone" class="usr-form-control">
                    </div>
                    <div class="usr-form-field" id="new_room_field" style="display: none;">
                        <label class="usr-form-label"><i class="fas fa-door-open"></i> ژمارەی ژوور ١</label>
                        <input type="text" name="room_number" id="new_room_number" class="usr-form-control" placeholder="نموونە: ١، ٢، ژووری A">
                    </div>
                    <div class="usr-form-field" id="new_room_field_2" style="display: none;">
                        <label class="usr-form-label"><i class="fas fa-door-open"></i> ژمارەی ژوور ٢ <small class="text-secondary">(ئارەزوومەندانە)</small></label>
                        <input type="text" name="room_number_2" id="new_room_number_2" class="usr-form-control" placeholder="ئەگەر دکتۆر دوو ژووری هەیە">
                    </div>
                </div>
            </div>
            <div class="usr-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('newUserDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" id="btnCreateUser" class="btn btn-success"><i class="fas fa-plus"></i> دروستکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Dialog -->
<div class="dialog-overlay" id="editUserDialog">
    <div class="dialog usr-dialog-modern">
        <div class="usr-dialog-header usr-dialog-header-edit">
            <h4><i class="fas fa-user-edit"></i> دەستکاری بەکارهێنەر</h4>
            <button type="button" class="usr-dialog-close" onclick="closeDialog('editUserDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="usr-dialog-body">
                <div class="usr-form-grid">
                    <div class="usr-form-field">
                        <label class="usr-form-label"><span class="usr-required">*</span> ناوی تەواو</label>
                        <input type="text" name="full_name" id="edit_full_name" class="usr-form-control" required>
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label"><span class="usr-required">*</span> ڕۆڵ</label>
                        <select name="role" id="edit_role" class="usr-form-control" required>
                            <option value="receptionist">کارمەند</option>
                            <option value="doctor">دکتۆر</option>
                            <option value="xray_department">بەشی ئێکسڕەی</option>
                            <option value="accountant">ژمێریار</option>
                            <option value="admin">بەڕێوەبەر</option>
                        </select>
                    </div>
                    <div class="usr-form-field usr-field-full">
                        <label class="usr-form-label">وشەی نهێنی <small class="text-secondary">(بەتاڵ بهێڵەرەوە ئەگەر ناتەوێت بیگۆڕیت)</small></label>
                        <input type="password" name="password" class="usr-form-control" minlength="6">
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label">ئیمەیڵ</label>
                        <input type="email" name="email" id="edit_email" class="usr-form-control">
                    </div>
                    <div class="usr-form-field">
                        <label class="usr-form-label">مۆبایل</label>
                        <input type="tel" name="phone" id="edit_phone" class="usr-form-control">
                    </div>
                    <div class="usr-form-field usr-field-full" id="edit_doctor_fields">
                        <label class="usr-form-label">پسپۆڕی</label>
                        <input type="text" name="specialty" id="edit_specialty" class="usr-form-control" placeholder="نموونە: چارەسەری ڕەگ، ڕەگەزی...">
                    </div>
                    <div class="usr-form-field" id="edit_room_field">
                        <label class="usr-form-label"><i class="fas fa-door-open"></i> ژمارەی ژوور ١</label>
                        <input type="text" name="room_number" id="edit_room_number" class="usr-form-control" placeholder="نموونە: ١، ٢، ژووری A">
                    </div>
                    <div class="usr-form-field" id="edit_room_field_2">
                        <label class="usr-form-label"><i class="fas fa-door-open"></i> ژمارەی ژوور ٢ <small class="text-secondary">(ئارەزوومەندانە)</small></label>
                        <input type="text" name="room_number_2" id="edit_room_number_2" class="usr-form-control" placeholder="ئەگەر دکتۆر دوو ژووری هەیە">
                    </div>
                    <div class="usr-form-field usr-field-full">
                        <label class="usr-checkbox-wrapper">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                            <span class="usr-checkbox-label">چالاک</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="usr-dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('editUserDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modern User Dialog Styles */
.usr-dialog-modern {
    max-width: 480px;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
}
.usr-dialog-header {
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.usr-dialog-header-edit {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
}
.usr-dialog-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}
.usr-dialog-close {
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
.usr-dialog-close:hover { background: rgba(255,255,255,0.25); }
.usr-dialog-body { padding: 20px; }
.usr-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}
.usr-form-field { display: flex; flex-direction: column; gap: 6px; }
.usr-field-full { grid-column: 1 / -1; }
.usr-form-label {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}
.usr-required { color: #ef4444; margin-left: 2px; }
.usr-form-control {
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
.usr-form-control:focus {
    border-color: #0078d4;
    background: white;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}
.usr-checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 10px 14px;
    background: #f5f5f5;
    border-radius: 10px;
    transition: background 0.2s;
}
.usr-checkbox-wrapper:hover { background: #eeeeee; }
.usr-checkbox-wrapper input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #0078d4;
}
.usr-checkbox-label {
    font-size: 14px;
    font-weight: 500;
    color: #374151;
}
.usr-dialog-footer {
    padding: 14px 20px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
@media (max-width: 480px) {
    .usr-form-grid { grid-template-columns: 1fr; }
    .usr-field-full { grid-column: 1; }
}
</style>

<script>
function editUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_phone').value = user.phone || '';
    document.getElementById('edit_specialty').value = user.specialization || '';
    document.getElementById('edit_room_number').value = user.room_number || '';
    document.getElementById('edit_room_number_2').value = user.room_number_2 || '';
    document.getElementById('edit_is_active').checked = user.is_active == 1;
    toggleEditDoctorFields();
    openDialog('editUserDialog');
}

function toggleEditDoctorFields() {
    var role = document.getElementById('edit_role').value;
    var doctorOnly = role === 'doctor';
    var roomField = document.getElementById('edit_room_field');
    if (roomField) roomField.style.display = doctorOnly ? '' : 'none';
    var roomField2 = document.getElementById('edit_room_field_2');
    if (roomField2) roomField2.style.display = doctorOnly ? '' : 'none';
}

document.getElementById('edit_role').addEventListener('change', toggleEditDoctorFields);

// New-user dialog: show room field only when role=doctor
(function() {
    var newRoleSel = document.querySelector('#newUserDialog select[name="role"]');
    var newRoomField = document.getElementById('new_room_field');
    var newRoomField2 = document.getElementById('new_room_field_2');
    function sync() {
        var show = (newRoleSel.value === 'doctor') ? '' : 'none';
        if (newRoomField) newRoomField.style.display = show;
        if (newRoomField2) newRoomField2.style.display = show;
    }
    if (newRoleSel) { newRoleSel.addEventListener('change', sync); sync(); }
})();

// Username validation
var usernameCheckTimeout = null;
var usernameExists = false;

document.getElementById('new_username').addEventListener('input', function() {
    var username = this.value.trim();
    var statusEl = document.getElementById('usernameStatus');
    var inputEl = this;

    // Clear previous timeout
    if (usernameCheckTimeout) {
        clearTimeout(usernameCheckTimeout);
    }

    // Hide status if empty
    if (username.length < 2) {
        statusEl.style.display = 'none';
        inputEl.style.borderColor = '#e0e0e0';
        usernameExists = false;
        return;
    }

    // Debounce - wait 500ms after user stops typing
    usernameCheckTimeout = setTimeout(function() {
        statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> گەڕان...';
        statusEl.style.display = 'block';
        statusEl.style.color = '#666';

        fetch('<?= APP_URL ?>/ajax/check_username.php?username=' + encodeURIComponent(username))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.exists) {
                        usernameExists = true;
                        statusEl.innerHTML = '<i class="fas fa-times-circle"></i> ' + data.message;
                        statusEl.style.color = '#f44336';
                        inputEl.style.borderColor = '#f44336';
                    } else {
                        usernameExists = false;
                        statusEl.innerHTML = '<i class="fas fa-check-circle"></i> ناوی بەکارهێنەر بەردەستە';
                        statusEl.style.color = '#4caf50';
                        inputEl.style.borderColor = '#4caf50';
                    }
                }
            })
            .catch(error => {
                statusEl.style.display = 'none';
                usernameExists = false;
            });
    }, 500);
});

// Form validation before submit
function validateNewUserForm(event) {
    var errorsEl = document.getElementById('newUserErrors');
    var errors = [];

    var fullName = document.getElementById('new_full_name').value.trim();
    var username = document.getElementById('new_username').value.trim();
    var password = document.getElementById('new_password').value;

    // Basic validation
    if (!fullName) {
        errors.push('ناوی تەواو پێویستە');
    }
    if (!username) {
        errors.push('ناوی بەکارهێنەر پێویستە');
    }
    if (!/^[a-zA-Z0-9_]+$/.test(username)) {
        errors.push('ناوی بەکارهێنەر تەنها پیت و ژمارە و _ دەگرێتەوە');
    }
    if (!password) {
        errors.push('وشەی نهێنی پێویستە');
    }
    if (password.length < 6) {
        errors.push('وشەی نهێنی دەبێت لانیکەم ٦ پیت بێت');
    }

    // Check if username exists
    if (usernameExists) {
        errors.push('ئەم ناوی بەکارهێنەرە پێشتر بەکارهاتووە');
    }

    if (errors.length > 0) {
        event.preventDefault();
        errorsEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + errors.join('<br><i class="fas fa-exclamation-circle"></i> ');
        errorsEl.style.display = 'block';
        return false;
    }

    // If username check is still pending, do a synchronous check
    if (username && !usernameExists) {
        event.preventDefault();

        var btn = document.getElementById('btnCreateUser');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاڕی...';

        fetch('<?= APP_URL ?>/ajax/check_username.php?username=' + encodeURIComponent(username))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.exists) {
                    errorsEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                    errorsEl.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-plus"></i> دروستکردن';
                } else {
                    // Username is available, submit form
                    errorsEl.style.display = 'none';
                    document.getElementById('newUserForm').submit();
                }
            })
            .catch(error => {
                // Submit anyway if check fails
                document.getElementById('newUserForm').submit();
            });

        return false;
    }

    errorsEl.style.display = 'none';
    return true;
}

// Clear form when dialog opens
var originalOpenDialog = window.openDialog;
window.openDialog = function(id) {
    if (id === 'newUserDialog') {
        document.getElementById('newUserForm').reset();
        document.getElementById('newUserErrors').style.display = 'none';
        document.getElementById('usernameStatus').style.display = 'none';
        document.getElementById('new_username').style.borderColor = '#e0e0e0';
        usernameExists = false;
    }
    if (typeof originalOpenDialog === 'function') {
        originalOpenDialog(id);
    } else {
        document.getElementById(id).classList.add('active');
    }
};
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
