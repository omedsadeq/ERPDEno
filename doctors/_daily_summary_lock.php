<?php
/**
 * قفڵی پوختەی ڕۆژانە — the sheet opens only after the viewer re-types their
 * own password.
 *
 * Doctors often leave their account logged in and an assistant opens this
 * page, so a live session alone is not enough (the same rule patients/index.php
 * applies before a delete). Once unlocked the sheet stays open while it is in
 * use; DS_LOCK_IDLE seconds without opening it locks it again, and the
 * «قفڵکردن» button locks it at once.
 *
 * Included at file scope by _daily_summary_data.php after the login and
 * permission checks, BEFORE any output — so the PDF is locked the same way.
 * Expects $db and $viewerUserId.
 */

require_once __DIR__ . '/../classes/Csrf.php';
require_once __DIR__ . '/../classes/AuditLog.php';

const DS_LOCK_IDLE = 300;          // 5 minutes without opening the sheet
const DS_LOCK_MAX_FAILS = 5;       // wrong passwords per 15 minutes, then wait

// Back to the very sheet that was asked for. Rebuilt from APP_URL and the
// query, never from REQUEST_URI, so it can only ever point at this page.
$dsLockSelf = APP_URL . '/doctors/' . basename($_SERVER['SCRIPT_NAME'])
    . ($_GET ? '?' . http_build_query($_GET) : '');
$dsLockIsPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($dsLockIsPost && isset($_POST['ds_lock'])) {
    Csrf::require();
    unset($_SESSION['ds_unlock']);
    header('Location: ' . $dsLockSelf);
    exit;
}

$dsUnlock = $_SESSION['ds_unlock'] ?? null;
if (is_array($dsUnlock)
    && (int)($dsUnlock['user'] ?? 0) === $viewerUserId
    && time() - (int)($dsUnlock['seen'] ?? 0) <= DS_LOCK_IDLE) {
    $_SESSION['ds_unlock']['seen'] = time();
    return; // unlocked — the sheet carries on
}
unset($_SESSION['ds_unlock']);

// Wrong passwords go to login_attempts under their own identifier, so
// guessing here never blocks the real login form and vice versa.
$dsLockIdent = 'ds_unlock|' . $viewerUserId;
$dsLockHasLog = $db->tableExists('login_attempts');
$dsLockError = '';

if ($dsLockIsPost && isset($_POST['ds_password'])) {
    Csrf::require();
    $fails = $dsLockHasLog ? (int)($db->fetch(
        "SELECT COUNT(*) AS c FROM login_attempts
         WHERE identifier = :i AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
        ['i' => $dsLockIdent]
    )['c'] ?? 0) : 0;

    if ($fails >= DS_LOCK_MAX_FAILS) {
        $dsLockError = 'هەوڵی هەڵەی زۆر. تکایە چەند خولەکێک چاوەڕێ بکە و دووبارە هەوڵ بدەرەوە.';
    } else {
        $hash = $db->fetch("SELECT password FROM users WHERE id = :id AND is_active = 1", ['id' => $viewerUserId]);
        if ($hash && password_verify((string)$_POST['ds_password'], $hash['password'])) {
            if ($dsLockHasLog) {
                $db->delete('login_attempts', 'identifier = :i', ['i' => $dsLockIdent]);
            }
            $_SESSION['ds_unlock'] = ['user' => $viewerUserId, 'seen' => time()];
            header('Location: ' . $dsLockSelf);
            exit;
        }
        if ($dsLockHasLog) {
            $db->insert('login_attempts', ['identifier' => $dsLockIdent, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null]);
        }
        AuditLog::log('daily_summary_unlock_denied', 'users', $viewerUserId, null, ['reason' => 'bad_password']);
        $dsLockError = 'وشەی نهێنی هەڵەیە.';
    }
}

$pageTitle = 'پوختەی ڕۆژانەم';
$breadcrumb = [
    ['title' => 'دکتۆرەکان'],
    ['title' => 'پوختەی ڕۆژانەم'],
];
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.ds-lock{max-width:380px;margin:60px auto;border:1px solid #b9c6cf;background:#fff;color:#172b3a;direction:rtl}
.ds-lock h1{margin:0;padding:10px 14px;background:#244b68;color:#fff;font-size:15px;font-weight:700}
.ds-lock form{padding:16px 14px;display:flex;flex-direction:column;gap:10px}
.ds-lock p{margin:0;font-size:12.5px;color:#445b6c;line-height:1.7}
.ds-lock .who{font-weight:700;color:#18364c}
.ds-lock input[type=password]{height:36px;border:1px solid #9cafbd;padding:4px 9px;font:inherit;font-size:14px;direction:ltr}
.ds-lock input[type=password]:focus{outline:2px solid #244b68;outline-offset:-1px}
.ds-lock button{height:36px;border:1px solid #244b68;background:#244b68;color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.ds-lock .err{border:1px solid #d98a8a;background:#fdeeee;color:#9b1c1c;padding:7px 10px;font-size:12px}
</style>

<div class="ds-lock">
    <h1><i class="fas fa-lock"></i> پوختەی ڕۆژانە قفڵە</h1>
    <form method="post" action="<?= dsEsc($dsLockSelf) ?>" autocomplete="off">
        <?= Csrf::field() ?>
        <p>بۆ کردنەوەی ئەم پەڕەیە وشەی نهێنی هەژمارەکەت بنووسە.</p>
        <p>هەژمار: <span class="who"><?= dsEsc($currentUser['full_name'] ?? '') ?></span></p>
        <?php if ($dsLockError !== ''): ?>
        <div class="err"><i class="fas fa-exclamation-circle"></i> <?= dsEsc($dsLockError) ?></div>
        <?php endif; ?>
        <input type="password" name="ds_password" autocomplete="new-password" required autofocus aria-label="وشەی نهێنی">
        <button type="submit"><i class="fas fa-unlock"></i> کردنەوە</button>
    </form>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
exit;
