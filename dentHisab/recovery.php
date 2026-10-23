<?php
/**
 * recovery.php — Standalone database recovery / restore page
 * پەڕەی گەڕاندنەوەی داتابەیس (کاتی نەبوونی داتابەیس)
 *
 * Database.php sends the browser here automatically when the database, or
 * its core tables, are missing (dropped DB, crashed restore, fresh install)
 * — see redirectToRecovery(). The page therefore must work WITHOUT a
 * functioning database:
 *
 *   - probes the MySQL server over a raw PDO connection (no Database class)
 *   - unlock: a logged-in admin session (healthy DB) or the database
 *     password from config.php/.env — the one credential the site owner
 *     always still has when the database itself is gone
 *   - lists every backup file in storage/backups/, accepts uploads,
 *     creates the database when missing, then runs RestoreManager
 *   - RECOVERY_MODE keeps config.php (migrations) and Database.php
 *     (redirect loop) out of the way
 */

define('RECOVERY_MODE', true);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/BackupManager.php';
require_once __DIR__ . '/classes/RestoreManager.php';
require_once __DIR__ . '/classes/SqlCompat.php';
require_once __DIR__ . '/classes/SchemaVerifier.php';

// ── Helpers (raw PDO — never the Database singleton) ──────────────────

function recovery_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Connect to the SERVER only (no dbname) — works while the DB is missing. */
function recovery_pdo_server() {
    [$host, $port] = BackupManager::hostAndPort();
    return new PDO("mysql:host={$host};port={$port};charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function recovery_status() {
    $s = [
        'server_ok'    => false,
        'db_exists'    => false,
        'tables_count' => 0,
        'core_ok'      => false,
        'db_name'      => DB_NAME,
        'server'       => null,  // flavor / version of the server we are about to restore into
        'schema'       => null,  // SchemaVerifier result, when the database exists
        'error'        => null,
    ];
    try {
        $pdo = recovery_pdo_server();
        $s['server_ok'] = true;
        $s['server']    = SqlCompat::serverInfo($pdo);

        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $q->execute([DB_NAME]);
        $s['db_exists'] = (int)$q->fetchColumn() > 0;
        if ($s['db_exists']) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?");
            $q->execute([DB_NAME]);
            $s['tables_count'] = (int)$q->fetchColumn();

            // Re-connect with the database selected so SchemaVerifier's
            // DATABASE()-scoped queries resolve.
            try {
                [$host, $port] = BackupManager::hostAndPort();
                $dbPdo = new PDO("mysql:host={$host};port={$port};dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $s['core_ok'] = SchemaVerifier::coreOk($dbPdo);
                $s['schema']  = SchemaVerifier::verify($dbPdo);
            } catch (PDOException $e) {
                $s['core_ok'] = false;
            }
        }
    } catch (PDOException $e) {
        $s['error'] = $e->getMessage();
    }
    return $s;
}

/** The installation SQL shipped inside the project, when present. */
function recovery_install_file() {
    $path = realpath(APP_ROOT . '/database/schema.sql');
    return ($path !== false && is_file($path)) ? $path : null;
}

/**
 * Marker written after a fresh install from the bundled schema.
 *
 * It disables the one-click *install* button only. Disaster recovery from a
 * backup stays available (behind the database password) — locking that away
 * would defeat the purpose of this page.
 */
function recovery_install_lock() {
    return APP_ROOT . '/storage/install.lock';
}

function recovery_install_locked() {
    return file_exists(recovery_install_lock());
}

/** Backup files on disk — no backup_history table needed. */
function recovery_backups() {
    BackupManager::ensureDirs();
    $dir   = BackupManager::backupDir();
    $paths = array_unique(array_merge(
        glob($dir . '/*.sql') ?: [],
        glob($dir . '/*.sql.gz') ?: [],
        glob($dir . '/*.zip') ?: []
    ));
    $files = [];
    foreach ($paths as $p) {
        $n = basename($p);
        if ($n === '' || $n[0] === '.') {
            continue;
        }
        $files[] = ['name' => $n, 'size' => (int)filesize($p), 'mtime' => (int)filemtime($p)];
    }
    usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $files;
}

/** Resolve a posted file name to a real path inside the backup dir, or null. */
function recovery_resolve_file($name) {
    $name = basename((string)$name);
    if ($name === '' || $name[0] === '.') {
        return null;
    }
    $real = realpath(BackupManager::backupDir() . '/' . $name);
    $dir  = realpath(BackupManager::backupDir());
    if ($real === false || $dir === false || strpos($real, $dir) !== 0) {
        return null;
    }
    return $real;
}

/**
 * Save an uploaded dump without touching the database (mirrors the
 * validation of BackupManager::registerUpload — extension + magic bytes).
 */
function recovery_save_upload(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'بارکردنی فایل سەرکەوتوو نەبوو (کۆدی ' . ($file['error'] ?? '?') . ')'];
    }
    $orig = (string)$file['name'];
    $ext  = null;
    foreach (['.sql.gz', '.sql', '.zip'] as $e) {
        if (strtolower(substr($orig, -strlen($e))) === $e) {
            $ext = $e;
            break;
        }
    }
    if ($ext === null) {
        return ['success' => false, 'message' => 'تەنها فایلی .sql و .sql.gz و .zip ڕێگەپێدراوە'];
    }
    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 4096);
    if ($ext === '.sql.gz' && substr($head, 0, 2) !== "\x1f\x8b") {
        return ['success' => false, 'message' => 'فایلەکە gzip ی دروست نییە'];
    }
    if ($ext === '.zip' && substr($head, 0, 4) !== "PK\x03\x04") {
        return ['success' => false, 'message' => 'فایلەکە zip ی دروست نییە'];
    }
    if ($ext === '.sql' && strpos($head, "\0") !== false) {
        return ['success' => false, 'message' => 'فایلی .sql دەبێت دەقی سادە بێت'];
    }
    $fileName = sprintf('upload_%s_%s%s', date('Y-m-d_His'), substr(bin2hex(random_bytes(4)), 0, 8), $ext);
    $dest     = BackupManager::backupDir() . '/' . $fileName;
    $moved    = is_uploaded_file($file['tmp_name'])
        ? move_uploaded_file($file['tmp_name'], $dest)
        : rename($file['tmp_name'], $dest);
    if (!$moved) {
        return ['success' => false, 'message' => 'نەتوانرا فایلەکە پاشەکەوت بکرێت'];
    }
    return ['success' => true, 'file_name' => $fileName];
}

// ── Unlock / access control ───────────────────────────────────────────

$status = recovery_status();

function recovery_is_unlocked(array $status) {
    if (!empty($_SESSION['recovery_unlocked'])) {
        return true;
    }
    // A logged-in admin on a healthy DB doesn't need to re-enter the key.
    return $status['core_ok'] && (($_SESSION['role'] ?? '') === ROLE_ADMIN);
}

$unlocked = recovery_is_unlocked($status);

// ── JSON actions ──────────────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action !== '') {
    try {
        switch ($action) {

            case 'status': {
                recovery_json(['success' => true, 'status' => $status, 'unlocked' => $unlocked]);
            }

            case 'unlock': {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    recovery_json(['success' => false, 'message' => 'POST required']);
                }
                Csrf::require();
                // Throttle: max 6 tries, then a 60-second lockout.
                $t = $_SESSION['recovery_attempts'] ?? ['n' => 0, 'last' => 0];
                if ($t['n'] >= 6 && (time() - $t['last']) < 60) {
                    recovery_json(['success' => false, 'message' => 'زۆر هەوڵی هەڵە — خولەکێک چاوەڕێ بکە']);
                }
                if ((time() - $t['last']) >= 300) {
                    $t['n'] = 0;
                }
                $key = (string)($_POST['key'] ?? '');
                if ($key !== '' && hash_equals(DB_PASS, $key)) {
                    $_SESSION['recovery_unlocked'] = true;
                    unset($_SESSION['recovery_attempts']);
                    recovery_json(['success' => true]);
                }
                usleep(500000);
                $_SESSION['recovery_attempts'] = ['n' => $t['n'] + 1, 'last' => time()];
                recovery_json(['success' => false, 'message' => 'وشەی نهێنی داتابەیس هەڵەیە']);
            }

            case 'test_connection': {
                if (!$unlocked) {
                    http_response_code(403);
                    recovery_json(['success' => false, 'message' => 'داخراوە']);
                }
                try {
                    $pdo    = recovery_pdo_server();
                    $server = SqlCompat::serverInfo($pdo);
                    $grants = [];
                    // Report what we may do, so failures surface before the restore.
                    foreach (['CREATE DATABASE' => "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote(DB_NAME)] as $label => $probe) {
                        try { $pdo->query($probe); $grants[$label] = true; }
                        catch (PDOException $e) { $grants[$label] = false; }
                    }
                    recovery_json([
                        'success' => true,
                        'message' => 'پەیوەندی سەرکەوتوو بوو — ' . $server['flavor_label'] . ' ' . $server['version'],
                        'server'  => $server,
                        'db_exists' => $grants['CREATE DATABASE'] ?? false,
                    ]);
                } catch (PDOException $e) {
                    recovery_json([
                        'success' => false,
                        'message' => 'پەیوەندی سەرکەوتوو نەبوو: ' . $e->getMessage(),
                    ]);
                }
            }

            case 'progress': {
                if (!$unlocked) {
                    http_response_code(403);
                    recovery_json(['success' => false, 'message' => 'داخراوە']);
                }
                recovery_json(['success' => true, 'progress' => BackupManager::readProgress((string)($_GET['token'] ?? ''))]);
            }

            case 'upload': {
                if (!$unlocked) {
                    http_response_code(403);
                    recovery_json(['success' => false, 'message' => 'داخراوە']);
                }
                Csrf::require();
                if (empty($_FILES['backup_file'])) {
                    recovery_json(['success' => false, 'message' => 'هیچ فایلێک نەنێردراوە']);
                }
                recovery_json(recovery_save_upload($_FILES['backup_file']));
            }

            case 'restore': {
                if (!$unlocked) {
                    http_response_code(403);
                    recovery_json(['success' => false, 'message' => 'داخراوە']);
                }
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    recovery_json(['success' => false, 'message' => 'POST required']);
                }
                Csrf::require();
                if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'RESTORE') {
                    recovery_json(['success' => false, 'message' => 'بۆ دڵنیابوونەوە دەبێت وشەی RESTORE بنووسیت']);
                }
                // Two sources: an uploaded/stored backup, or the installation
                // SQL shipped with the project (fresh install).
                $isInstall = (($_POST['source'] ?? 'backup') === 'install');
                if ($isInstall) {
                    if (recovery_install_locked()) {
                        recovery_json(['success' => false, 'message' => 'دامەزراندن پێشتر تەواو بووە و داخراوە']);
                    }
                    $path = recovery_install_file();
                    if ($path === null) {
                        recovery_json(['success' => false, 'message' => 'فایلی دامەزراندن (database/schema.sql) نەدۆزرایەوە']);
                    }
                } else {
                    $path = recovery_resolve_file($_POST['file'] ?? '');
                    if ($path === null) {
                        recovery_json(['success' => false, 'message' => 'فایلی باکئەپ نەدۆزرایەوە']);
                    }
                }
                if (!$status['server_ok']) {
                    recovery_json(['success' => false, 'message' => 'سێرڤەری MySQL بەردەست نییە — سەرەتا MySQL دەستپێبکە']);
                }

                // A database with tables in it is never overwritten unless the
                // administrator ticked the overwrite box for this specific run.
                $overwrite = (($_POST['overwrite'] ?? '') === '1');
                if ($status['db_exists'] && $status['tables_count'] > 0 && !$overwrite) {
                    recovery_json([
                        'success' => false,
                        'message' => 'داتابەیسەکە ' . (int)$status['tables_count'] . ' خشتەی تێدایە. بۆ بەسەرداکردنەوەی، '
                                   . 'دەبێت «بەسەرداکردنەوەی خشتە هەنووکەییەکان» هەڵبژێریت.',
                        'needs_overwrite' => true,
                    ]);
                }
                // The database itself may be gone — create it first.
                if (!$status['db_exists']) {
                    try {
                        recovery_pdo_server()->exec(
                            "CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '``', DB_NAME) . "`
                             CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                        );
                    } catch (PDOException $e) {
                        recovery_json([
                            'success' => false,
                            'message' => 'نەتوانرا داتابەیس دروست بکرێت (لە هۆستی هاوبەش لە پانێڵی هۆستەکە دروستی بکە): ' . $e->getMessage(),
                        ]);
                    }
                }

                $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                session_write_close();
                set_time_limit(0);
                ignore_user_abort(true);

                $restoreManager = new RestoreManager();
                $result = $restoreManager->restore([
                    'path'        => $path,
                    // Pre-backup only makes sense while the DB is still healthy;
                    // on a missing/empty DB it would just fail the restore.
                    'pre_backup'  => $status['core_ok'] && (($_POST['pre_backup'] ?? '') === '1'),
                    'maintenance' => true,
                    'user_id'     => $userId,
                    'progress'    => (string)($_POST['token'] ?? ''),
                ]);

                // A fresh install may proceed once only; recovery from a backup stays open.
                if (!empty($result['success']) && $isInstall) {
                    @file_put_contents(recovery_install_lock(), json_encode([
                        'installed_at' => date('Y-m-d H:i:s'),
                        'source'       => basename($path),
                    ]), LOCK_EX);
                }
                recovery_json($result);
            }

            default:
                http_response_code(400);
                recovery_json(['success' => false, 'message' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        error_log('[recovery] ' . $e->getMessage());
        http_response_code(500);
        recovery_json(['success' => false, 'message' => 'هەڵەیەکی ناوخۆیی: ' . $e->getMessage()]);
    }
}

// ── Page ──────────────────────────────────────────────────────────────

$reason  = (string)($_GET['reason'] ?? '');
$reasons = [
    'missing_db'     => 'داتابەیسی «' . DB_NAME . '» نەدۆزرایەوە. بۆ کارکردنی سیستەم پێویستە باکئەپێک بگەڕێنرێتەوە.',
    'missing_tables' => 'خشتە سەرەکییەکانی داتابەیس نەدۆزرانەوە (داتابەیس بەتاڵە یان ناتەواوە). باکئەپێک بگەڕێنەرەوە.',
    'server_down'    => 'ناتوانرێت پەیوەندی بە سێرڤەری MySQL بکرێت. دڵنیابە MySQL کارە (لە XAMPP دەستیپێبکە) و ئینجا ئەم پەڕەیە نوێ بکەرەوە.',
];
$reasonText    = $reasons[$reason] ?? '';
$backups       = $unlocked ? recovery_backups() : [];
$csrfToken     = Csrf::token();
$installFile   = recovery_install_file();
$installLocked = recovery_install_locked();
$hasTables     = $status['db_exists'] && $status['tables_count'] > 0;

function rh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>گەڕاندنەوەی داتابەیس - <?= rh(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/fonts.css">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: var(--app-font-family, Tahoma, Arial, sans-serif);
        background: #f3f3f3; color: #1b1b1b; min-height: 100vh;
        display: flex; align-items: flex-start; justify-content: center; padding: 24px 12px;
    }
    .wrap { width: 100%; max-width: 720px; }
    .card {
        background: #fff; border: 1px solid #e5e5e5; border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 20px; margin-bottom: 16px;
    }
    h1 { font-size: 20px; margin-bottom: 4px; }
    .sub { color: #666; font-size: 13px; margin-bottom: 12px; }
    .banner {
        border-radius: 6px; padding: 12px 14px; margin-bottom: 16px; font-size: 14px;
        background: #fdecea; border: 1px solid #f5c6c2; color: #8a1f16;
    }
    .banner.info { background: #e8f1fb; border-color: #bcd7f5; color: #174e87; }
    .status { list-style: none; }
    .status li { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 14px; }
    .ok  { color: #107c10; font-weight: bold; }
    .bad { color: #c42b1c; font-weight: bold; }
    .muted { color: #888; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { padding: 8px 6px; border-bottom: 1px solid #eee; text-align: right; }
    th { color: #555; font-weight: 600; background: #fafafa; }
    input[type=password], input[type=text], input[type=file] {
        width: 100%; padding: 9px 10px; border: 1px solid #ccc; border-radius: 6px;
        font-family: inherit; font-size: 14px; margin-bottom: 10px; background: #fff;
    }
    .btn {
        display: inline-block; border: none; border-radius: 6px; cursor: pointer;
        padding: 9px 18px; font-family: inherit; font-size: 14px;
        background: #0067c0; color: #fff;
    }
    .btn:hover { background: #005ba1; }
    .btn.small { padding: 6px 12px; font-size: 13px; }
    .btn.danger { background: #c42b1c; }
    .btn.danger:hover { background: #a52315; }
    .btn.ghost { background: #f0f0f0; color: #1b1b1b; border: 1px solid #ccc; }
    .btn[disabled] { opacity: .5; cursor: not-allowed; }
    #progressWrap { display: none; margin-top: 14px; }
    .bar { background: #e5e5e5; border-radius: 6px; height: 14px; overflow: hidden; }
    .bar > div { height: 100%; width: 0; background: #0067c0; transition: width .4s; }
    #progressMsg { font-size: 13px; color: #444; margin-top: 6px; }
    #result { margin-top: 12px; font-size: 14px; display: none; padding: 12px; border-radius: 6px; }
    #result.good { background: #e6f4e6; border: 1px solid #b7dfb7; color: #0f5c0f; display: block; }
    #result.fail { background: #fdecea; border: 1px solid #f5c6c2; color: #8a1f16; display: block; }
    label.chk { display: flex; align-items: center; gap: 8px; font-size: 13px; margin: 10px 0; }
    .actions { margin-top: 8px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>🛠️ گەڕاندنەوەی داتابەیس</h1>
        <div class="sub"><?= rh(APP_NAME) ?> — <?= rh(DB_NAME) ?>@<?= rh(DB_HOST) ?></div>

        <?php if ($reasonText !== ''): ?>
            <div class="banner"><?= rh($reasonText) ?></div>
        <?php elseif ($status['core_ok']): ?>
            <div class="banner info">داتابەیس ساغە. ئەم پەڕەیە تەنها بۆ حاڵەتی لەدەستدانی داتابەیسە —
                <a href="login.php">گەڕانەوە بۆ سیستەم</a></div>
        <?php endif; ?>

        <ul class="status">
            <li><span class="<?= $status['server_ok'] ? 'ok' : 'bad' ?>"><?= $status['server_ok'] ? '✓' : '✗' ?></span>
                سێرڤەری داتابەیس
                <?php if ($status['server_ok'] && $status['server']): ?>
                    <span class="muted" style="direction:ltr"><?= rh($status['server']['flavor_label'] . ' ' . $status['server']['version']) ?></span>
                <?php elseif (!$status['server_ok'] && $status['error']): ?>
                    <span class="muted">(<?= rh(mb_substr($status['error'], 0, 120)) ?>)</span>
                <?php endif; ?>
            </li>
            <li><span class="<?= $status['db_exists'] ? 'ok' : 'bad' ?>"><?= $status['db_exists'] ? '✓' : '✗' ?></span>
                داتابەیسی <?= rh(DB_NAME) ?>
            </li>
            <li><span class="<?= $status['core_ok'] ? 'ok' : 'bad' ?>"><?= $status['core_ok'] ? '✓' : '✗' ?></span>
                خشتە سەرەکییەکان
                <span class="muted">(<?= (int)$status['tables_count'] ?> خشتە دۆزرایەوە)</span>
            </li>
            <?php if ($status['schema']): ?>
            <li><span class="<?= $status['schema']['ok'] ? 'ok' : 'bad' ?>"><?= $status['schema']['ok'] ? '✓' : '✗' ?></span>
                تەواوی سکیما
                <span class="muted"><?= rh($status['schema']['summary']) ?></span>
            </li>
            <?php endif; ?>
        </ul>

        <?php if ($status['server_ok'] && $status['server'] && $status['server']['supports_0900']): ?>
            <div class="banner info" style="margin-top:12px;margin-bottom:0">
                ئەم سێرڤەرە MySQL 8+ ە. باکئەپەکان بە شێوەیەکی گونجاو تۆمار دەکرێن تا لەسەر
                MariaDB و MySQL کۆنتریش بگەڕێنرێنەوە.
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$unlocked): ?>
    <div class="card">
        <h1 style="font-size:16px">🔐 کردنەوەی بەشی گەڕاندنەوە</h1>
        <div class="sub">وشەی نهێنی داتابەیس بنووسە (هەمان ئەوەی لە config.php / .env دانراوە)</div>
        <form id="unlockForm">
            <input type="password" id="unlockKey" placeholder="وشەی نهێنی داتابەیس" autocomplete="off" required>
            <button class="btn" type="submit">کردنەوە</button>
        </form>
        <div id="unlockMsg" class="muted" style="margin-top:8px"></div>
    </div>
    <?php else: ?>

    <div class="card">
        <h1 style="font-size:16px">🔌 تاقیکردنەوەی پەیوەندی</h1>
        <div class="sub">پێش گەڕاندنەوە دڵنیابە لە پەیوەندی و جۆری سێرڤەر</div>
        <button class="btn ghost" type="button" onclick="testConnection()">تاقیکردنەوەی پەیوەندی</button>
        <div id="testMsg" class="muted" style="margin-top:8px"></div>
    </div>

    <?php if ($installFile !== null && !$status['core_ok']): ?>
    <div class="card">
        <h1 style="font-size:16px">📦 دامەزراندنی نوێ</h1>
        <div class="sub">فایلی دامەزراندنی ناو پڕۆژە: <span style="direction:ltr">database/schema.sql</span>
            (<?= rh(BackupManager::humanSize((int)filesize($installFile))) ?>)</div>
        <?php if ($installLocked): ?>
            <div class="banner info">دامەزراندن پێشتر ئەنجامدراوە و داخراوە. بۆ کردنەوەی دووبارە،
                فایلی <span style="direction:ltr">storage/install.lock</span> بسڕەوە.</div>
        <?php else: ?>
            <div class="banner info">ئەگەر داتابەیسەکە بەتاڵە، دەتوانیت بە کلیکێک سکیمای بنەڕەتی دابمەزرێنیت.
                ئەمە داتای کۆن ناگەڕێنێتەوە — بۆ ئەوە باکئەپێک بەکاربهێنە.</div>
            <button class="btn" type="button" onclick="restoreFile(null, 'install')">دامەزراندنی سکیما</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h1 style="font-size:16px">💾 باکئەپە بەردەستەکان</h1>
        <div class="sub">فایلەکانی ناو storage/backups — نوێترین لە سەرەوە</div>
        <?php if (empty($backups)): ?>
            <div class="banner info">هیچ فایلێکی باکئەپ نەدۆزرایەوە. لە خوارەوە فایلێک باربکە.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <tr><th>فایل</th><th>قەبارە</th><th>بەروار</th><th></th></tr>
            <?php foreach ($backups as $b): ?>
            <tr>
                <td style="direction:ltr;text-align:left"><?= rh($b['name']) ?></td>
                <td><?= rh(BackupManager::humanSize($b['size'])) ?></td>
                <td><?= rh(date('Y-m-d H:i', $b['mtime'])) ?></td>
                <td><button class="btn small danger" onclick="restoreFile(<?= rh(json_encode($b['name'])) ?>)">گەڕاندنەوە</button></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>

        <?php if ($status['core_ok']): ?>
        <label class="chk"><input type="checkbox" id="preBackup" checked>
            پێش گەڕاندنەوە باکئەپی پاراستن لە داتای ئێستا بگرە</label>
        <?php endif; ?>

        <?php if ($hasTables): ?>
        <label class="chk" style="color:#8a1f16">
            <input type="checkbox" id="overwrite">
            بەسەرداکردنەوەی خشتە هەنووکەییەکان (<?= (int)$status['tables_count'] ?> خشتە لە ئێستادا هەیە)
        </label>
        <?php endif; ?>

        <div id="progressWrap">
            <div class="bar"><div id="progressBar"></div></div>
            <div id="progressMsg">...</div>
        </div>
        <div id="result"></div>
    </div>

    <div class="card">
        <h1 style="font-size:16px">⬆️ بارکردنی فایلی باکئەپ</h1>
        <div class="sub">.sql یان .sql.gz یان .zip</div>
        <form id="uploadForm">
            <input type="file" id="uploadFile" accept=".sql,.gz,.zip" required>
            <button class="btn" type="submit">بارکردن</button>
        </form>
        <div id="uploadMsg" class="muted" style="margin-top:8px"></div>
    </div>
    <?php endif; ?>
</div>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;
let restoring = false;

function post(action, formData) {
    formData.append('action', action);
    formData.append('csrf_token', CSRF);
    return fetch('recovery.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json());
}

const unlockForm = document.getElementById('unlockForm');
if (unlockForm) {
    unlockForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('key', document.getElementById('unlockKey').value);
        post('unlock', fd).then(res => {
            if (res.success) { location.reload(); }
            else { document.getElementById('unlockMsg').textContent = res.message || 'هەڵە'; }
        }).catch(() => { document.getElementById('unlockMsg').textContent = 'هەڵەی پەیوەندی'; });
    });
}

const uploadForm = document.getElementById('uploadForm');
if (uploadForm) {
    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const input = document.getElementById('uploadFile');
        if (!input.files.length) return;
        const fd = new FormData();
        fd.append('backup_file', input.files[0]);
        document.getElementById('uploadMsg').textContent = 'بارکردن...';
        post('upload', fd).then(res => {
            if (res.success) { location.reload(); }
            else { document.getElementById('uploadMsg').textContent = res.message || 'هەڵە'; }
        }).catch(() => { document.getElementById('uploadMsg').textContent = 'هەڵەی پەیوەندی (فایلەکە زۆر گەورەیە؟)'; });
    });
}

function randToken() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
}

function testConnection() {
    const el = document.getElementById('testMsg');
    el.textContent = 'تاقیکردنەوە...';
    post('test_connection', new FormData()).then(res => {
        el.textContent = (res.success ? '✓ ' : '✗ ') + (res.message || '');
    }).catch(() => { el.textContent = '✗ هەڵەی پەیوەندی'; });
}

function restoreFile(name, source) {
    if (restoring) return;
    const isInstall = source === 'install';
    const what = isInstall ? 'سکیمای دامەزراندن' : '«' + name + '»';
    const word = prompt('ئاگاداری: هەموو داتای ئێستا دەگۆڕدرێت بە ناوەڕۆکی ' + what + '.\nبۆ دڵنیابوونەوە وشەی RESTORE بنووسە:');
    if (word === null || word.trim().toUpperCase() !== 'RESTORE') return;

    const ow = document.getElementById('overwrite');
    if (ow && !ow.checked) {
        alert('داتابەیسەکە خشتەی تێدایە. سەرەتا «بەسەرداکردنەوەی خشتە هەنووکەییەکان» هەڵبژێرە.');
        return;
    }

    restoring = true;
    const token = randToken();
    const fd = new FormData();
    if (name) fd.append('file', name);
    fd.append('source', isInstall ? 'install' : 'backup');
    fd.append('confirm', 'RESTORE');
    fd.append('token', token);
    fd.append('overwrite', (ow && ow.checked) ? '1' : '0');
    const pre = document.getElementById('preBackup');
    fd.append('pre_backup', (pre && pre.checked) ? '1' : '0');

    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('result').className = '';
    setProgress(2, 'دەستپێکردن...');
    const poll = setInterval(() => pollProgress(token), 1200);

    post('restore', fd).then(res => {
        clearInterval(poll);
        restoring = false;
        const el = document.getElementById('result');
        if (res.success) {
            setProgress(100, 'تەواو بوو');
            const schemaOk = !res.verify || res.verify.ok;
            el.className = schemaOk ? 'good' : 'fail';

            let html = (schemaOk ? '✓' : '⚠️') + ' داتابەیس گەڕێندرایەوە (' + (res.statements || 0) + ' فەرمان)';
            if (res.skipped) {
                html += '<div class="muted" style="margin-top:6px">' + res.skipped +
                        ' فەرمان تێپەڕێندرا (پێشتر جێبەجێکرابوون)</div>';
            }
            const fixes = res.compat_fixes ? Object.keys(res.compat_fixes) : [];
            if (fixes.length) {
                html += '<div class="muted" style="margin-top:6px;direction:ltr;text-align:left">' +
                        fixes.map(k => k + ' → ' + res.compat_fixes[k]).join('<br>') + '</div>';
            }
            if (res.verify) {
                html += '<div class="muted" style="margin-top:6px">' + res.verify.summary + '</div>';
            }

            if (schemaOk) {
                html += '<div style="margin-top:8px"><a href="login.php">چوونەژوورەوە</a>' +
                        ' — دوای ٥ چرکە خۆکار دەگوازرێتەوە.</div>';
                el.innerHTML = html;
                setTimeout(() => { location.href = 'login.php'; }, 5000);
            } else {
                // Schema incomplete: do not send anyone into a half-restored system.
                html += '<div style="margin-top:8px">سکیما تەواو نییە — باکئەپێکی تەواوتر بگەڕێنەرەوە' +
                        ' پێش ئەوەی سیستەم بەکاربهێنیت.</div>';
                el.innerHTML = html;
            }
        } else {
            el.className = 'fail';
            el.textContent = '✗ ' + (res.message || 'گەڕاندنەوە شکستی هێنا');
            if (res.needs_overwrite) {
                const ow2 = document.getElementById('overwrite');
                if (ow2) ow2.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }).catch(() => {
        clearInterval(poll);
        restoring = false;
        // The request may have finished server-side even if the response was
        // lost (long restore + proxy timeout) — check the real state.
        fetch('recovery.php?action=status').then(r => r.json()).then(s => {
            const el = document.getElementById('result');
            if (s.status && s.status.core_ok) {
                el.className = 'good';
                el.innerHTML = '✓ گەڕاندنەوە تەواو بوو. <a href="login.php">چوونەژوورەوە</a>';
            } else {
                el.className = 'fail';
                el.textContent = '✗ پەیوەندی پچڕا — پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە';
            }
        });
    });
}

function setProgress(pct, msg) {
    document.getElementById('progressBar').style.width = pct + '%';
    document.getElementById('progressMsg').textContent = msg;
}

function pollProgress(token) {
    fetch('recovery.php?action=progress&token=' + token)
        .then(r => r.json())
        .then(res => {
            if (res.progress && !res.progress.done) {
                setProgress(res.progress.percent || 0, res.progress.message || '...');
            }
        })
        .catch(() => {});
}
</script>
</body>
</html>
