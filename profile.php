<?php
/**
 * User Profile Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'پڕۆفایل';
$breadcrumb = [['title' => 'پڕۆفایل']];

require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($fullName)) {
        $errors['full_name'] = 'ناو پێویستە';
    }

    // Password change validation
    if (!empty($newPassword)) {
        if (empty($currentPassword)) {
            $errors['current_password'] = 'وشەی نهێنی ئێستا پێویستە';
        } elseif (!password_verify($currentPassword, $currentUser['password'])) {
            $errors['current_password'] = 'وشەی نهێنی ئێستا هەڵەیە';
        }

        if (strlen($newPassword) < 6) {
            $errors['new_password'] = 'وشەی نهێنی نوێ دەبێت لانی کەم ٦ پیت بێت';
        } elseif ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'وشەی نهێنی نوێ و دووبارەکردنەوەی یەک ناگرنەوە';
        }
    }

    if (empty($errors)) {
        $data = [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone
        ];

        if (!empty($newPassword)) {
            $data['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $db->update('users', $data, 'id = :id', ['id' => $currentUser['id']]);
        AuditLog::logChange('update_profile', 'users', $currentUser['id'], $currentUser, $data);
        // A renamed doctor account must also rename its doctors-directory entry
        DoctorSync::syncFromUser($currentUser['id']);

        // Refresh user data
        $_SESSION['user'] = $db->fetch("SELECT * FROM users WHERE id = :id", ['id' => $currentUser['id']]);
        $currentUser = $_SESSION['user'];

        $success = 'پڕۆفایل بە سەرکەوتوویی نوێکرایەوە';
    }
}
?>

<?php if ($success): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content"><?= $success ?></div>
</div>
<?php endif; ?>

<div class="collapsible-sidebar-grid" style="display: grid; grid-template-columns: 300px 1fr; gap: 24px;">
    <!-- Profile Card -->
    <div class="panel">
        <div class="panel-body text-center">
            <div class="user-avatar" style="width: 100px; height: 100px; font-size: 40px; margin: 0 auto 20px;">
                <?= mb_substr($currentUser['full_name'], 0, 1) ?>
            </div>
            <h3 style="margin: 0 0 4px;"><?= htmlspecialchars($currentUser['full_name']) ?></h3>
            <p class="text-secondary" style="margin: 0 0 16px;">@<?= htmlspecialchars($currentUser['username']) ?></p>

            <span class="badge <?= $currentUser['role'] === 'admin' ? 'badge-blue' : ($currentUser['role'] === 'doctor' ? 'badge-green' : '') ?>" style="font-size: 14px; padding: 8px 16px;">
                <?= $currentUser['role'] === 'admin' ? 'بەڕێوەبەر' : ($currentUser['role'] === 'doctor' ? 'دکتۆر' : 'کارمەند') ?>
            </span>
        </div>

        <div class="panel-body" style="border-top: 1px solid var(--border-light);">
            <table style="width: 100%;">
                <tr>
                    <td class="text-secondary" style="padding: 8px 0;"><i class="fas fa-envelope"></i> ئیمەیڵ</td>
                    <td style="text-align: left;"><?= htmlspecialchars($currentUser['email'] ?: '-') ?></td>
                </tr>
                <tr>
                    <td class="text-secondary" style="padding: 8px 0;"><i class="fas fa-phone"></i> مۆبایل</td>
                    <td style="text-align: left;"><?= htmlspecialchars($currentUser['phone'] ?: '-') ?></td>
                </tr>
                <tr>
                    <td class="text-secondary" style="padding: 8px 0;"><i class="fas fa-calendar"></i> تۆماربوون</td>
                    <td style="text-align: left;"><?= date('Y-m-d', strtotime($currentUser['created_at'])) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Edit Form -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-user-edit"></i> دەستکاری پڕۆفایل</h3>
        </div>
        <div class="panel-body">
            <form method="POST" action="">
                <h4 class="mb-2">زانیاری گشتی</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">ناوی تەواو</label>
                        <input type="text" name="full_name" class="form-control <?= isset($errors['full_name']) ? 'error' : '' ?>"
                               value="<?= htmlspecialchars($_POST['full_name'] ?? $currentUser['full_name']) ?>" required>
                        <?php if (isset($errors['full_name'])): ?>
                        <div class="form-error"><?= $errors['full_name'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ناوی بەکارهێنەر</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($currentUser['username']) ?>" disabled>
                        <small class="text-secondary">ناوی بەکارهێنەر ناگۆڕدرێت</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ئیمەیڵ</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($_POST['email'] ?? $currentUser['email']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">مۆبایل</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= htmlspecialchars($_POST['phone'] ?? $currentUser['phone']) ?>">
                    </div>
                </div>

                <h4 class="mb-2 mt-4">گۆڕینی وشەی نهێنی</h4>
                <div class="alert alert-info mb-2">
                    <i class="fas fa-info-circle alert-icon"></i>
                    <div class="alert-content">تەنها پڕ بکەرەوە ئەگەر دەتەوێت وشەی نهێنی بگۆڕیت</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">وشەی نهێنی ئێستا</label>
                        <input type="password" name="current_password" class="form-control <?= isset($errors['current_password']) ? 'error' : '' ?>">
                        <?php if (isset($errors['current_password'])): ?>
                        <div class="form-error"><?= $errors['current_password'] ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">وشەی نهێنی نوێ</label>
                        <input type="password" name="new_password" class="form-control <?= isset($errors['new_password']) ? 'error' : '' ?>" minlength="6">
                        <?php if (isset($errors['new_password'])): ?>
                        <div class="form-error"><?= $errors['new_password'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">دووبارەکردنەوە</label>
                        <input type="password" name="confirm_password" class="form-control <?= isset($errors['confirm_password']) ? 'error' : '' ?>" minlength="6">
                        <?php if (isset($errors['confirm_password'])): ?>
                        <div class="form-error"><?= $errors['confirm_password'] ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> پاشەکەوتکردن
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
