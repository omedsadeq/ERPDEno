<?php
/**
 * Backup Module API Endpoint (Admin only)
 * ئایپیئای باکئەپ و گەڕاندنەوەی داتابەیس
 *
 * Actions:
 *   GET  history            history rows + stats + server capabilities
 *   GET  tables             tables/views of the DB (for selected-table backups)
 *   GET  progress&token=..  progress of a running create/restore
 *   POST create             start a backup            (CSRF)
 *   POST verify             verify a backup           (CSRF)
 *   POST delete             delete a backup           (CSRF)
 *   POST upload             upload a dump for restore (CSRF)
 *   POST restore            restore a backup          (CSRF + typed confirmation)
 *   POST save_settings      save schedule/retention   (CSRF)
 *   POST apply_retention    run retention now         (CSRF)
 *   POST regen_cron_token   rotate the web-cron token (CSRF)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Notification.php';
require_once __DIR__ . '/../classes/BackupManager.php';
require_once __DIR__ . '/../classes/RestoreManager.php';

header('Content-Type: application/json; charset=utf-8');

function backup_json($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    backup_json(['success' => false, 'message' => 'Unauthorized']);
}
if (!$auth->isAdmin()) {
    http_response_code(403);
    backup_json(['success' => false, 'message' => 'تەنها بەڕێوەبەر دەستی بەم بەشە دەگات']);
}

$userId = (int)$auth->getCurrentUserId();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$mutating = ['create', 'verify', 'delete', 'upload', 'restore', 'save_settings', 'apply_retention', 'regen_cron_token'];
if (in_array($action, $mutating, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        backup_json(['success' => false, 'message' => 'POST required']);
    }
    Csrf::require();
}

$backupManager = new BackupManager();

try {
    switch ($action) {

        case 'history': {
            $rows = $backupManager->getHistory(200);
            foreach ($rows as &$r) {
                $r['file_size_human'] = BackupManager::humanSize($r['file_size']);
                $r['error_message']   = $r['error_message'] !== null
                    ? mb_substr($r['error_message'], 0, 400) : null;
            }
            unset($r);
            backup_json([
                'success'      => true,
                'history'      => $rows,
                'stats'        => $backupManager->stats(),
                'capabilities' => $backupManager->capabilities(),
                'settings'     => $backupManager->getSettings(),
                'maintenance'  => RestoreManager::isMaintenanceActive(),
            ]);
        }

        case 'tables': {
            backup_json(['success' => true, 'tables' => $backupManager->listTables()]);
        }

        case 'progress': {
            $p = BackupManager::readProgress((string)($_GET['token'] ?? ''));
            backup_json(['success' => true, 'progress' => $p]);
        }

        case 'create': {
            // Long-running: release the session lock so progress polling works.
            session_write_close();
            set_time_limit(0);
            ignore_user_abort(true);

            $tables = [];
            if (!empty($_POST['tables']) && is_array($_POST['tables'])) {
                $tables = array_map('strval', $_POST['tables']);
            }
            $result = $backupManager->create([
                'type'        => (string)($_POST['type'] ?? 'full'),
                'tables'      => $tables,
                'compression' => (string)($_POST['compression'] ?? 'gzip'),
                'method'      => (string)($_POST['method'] ?? 'auto'),
                'trigger'     => 'manual',
                'user_id'     => $userId,
                'progress'    => (string)($_POST['token'] ?? ''),
            ]);

            if (empty($result['success'])) {
                // Failures matter even for manual runs — other admins should know.
                $notification = new Notification();
                $notification->notifyRole(ROLE_ADMIN, Notification::TYPE_SYSTEM,
                    'باکئەپی داتابەیس شکستی هێنا',
                    'باکئەپی دەستی شکستی هێنا: ' . mb_substr((string)($result['message'] ?? ''), 0, 200),
                    null, '/admin/backups.php', $userId);
            }
            backup_json($result);
        }

        case 'verify': {
            backup_json($backupManager->verify((int)($_POST['id'] ?? 0)));
        }

        case 'delete': {
            backup_json($backupManager->delete((int)($_POST['id'] ?? 0), $userId));
        }

        case 'upload': {
            if (empty($_FILES['backup_file'])) {
                backup_json(['success' => false, 'message' => 'هیچ فایلێک نەنێردراوە']);
            }
            backup_json($backupManager->registerUpload($_FILES['backup_file'], $userId));
        }

        case 'restore': {
            // Typed confirmation is the last line of defense against misclicks.
            if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'RESTORE') {
                backup_json(['success' => false, 'message' => 'بۆ دڵنیابوونەوە دەبێت وشەی RESTORE بنووسیت']);
            }
            session_write_close();
            set_time_limit(0);
            ignore_user_abort(true);

            $restoreManager = new RestoreManager();
            $result = $restoreManager->restore([
                'id'          => (int)($_POST['id'] ?? 0),
                'pre_backup'  => ($_POST['pre_backup'] ?? '1') === '1',
                'maintenance' => ($_POST['maintenance'] ?? '1') === '1',
                'user_id'     => $userId,
                'progress'    => (string)($_POST['token'] ?? ''),
            ]);

            $notification = new Notification();
            if (!empty($result['success'])) {
                $notification->notifyRole(ROLE_ADMIN, Notification::TYPE_SYSTEM,
                    'داتابەیس گەڕێندرایەوە',
                    'داتابەیس بە سەرکەوتوویی گەڕێندرایەوە لە فایلی: ' . ($result['file'] ?? ''),
                    null, '/admin/backups.php', $userId);
            } else {
                $notification->notifyRole(ROLE_ADMIN, Notification::TYPE_SYSTEM,
                    'گەڕاندنەوەی داتابەیس شکستی هێنا',
                    mb_substr((string)($result['message'] ?? ''), 0, 200),
                    null, '/admin/backups.php', $userId);
            }
            backup_json($result);
        }

        case 'save_settings': {
            $s = $_POST['settings'] ?? [];
            if (!is_array($s)) {
                backup_json(['success' => false, 'message' => 'داتای نادروست']);
            }
            $clean = [
                'backup_auto_enabled'     => ($s['backup_auto_enabled'] ?? '0') === '1' ? '1' : '0',
                'backup_auto_frequency'   => in_array($s['backup_auto_frequency'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $s['backup_auto_frequency'] : 'daily',
                'backup_auto_time'        => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($s['backup_auto_time'] ?? '')) ? $s['backup_auto_time'] : '02:00',
                'backup_auto_weekday'     => (string)min(6, max(0, (int)($s['backup_auto_weekday'] ?? 5))),
                'backup_auto_monthday'    => (string)min(28, max(1, (int)($s['backup_auto_monthday'] ?? 1))),
                'backup_auto_type'        => in_array($s['backup_auto_type'] ?? '', ['full', 'structure', 'data'], true) ? $s['backup_auto_type'] : 'full',
                'backup_auto_compression' => in_array($s['backup_auto_compression'] ?? '', ['gzip', 'zip', 'none'], true) ? $s['backup_auto_compression'] : 'gzip',
                'backup_retention_count'  => (string)min(500, max(0, (int)($s['backup_retention_count'] ?? 10))),
                'backup_retention_days'   => (string)min(3650, max(0, (int)($s['backup_retention_days'] ?? 30))),
                'backup_notify'           => ($s['backup_notify'] ?? '0') === '1' ? '1' : '0',
            ];
            foreach ($clean as $k => $v) {
                $backupManager->saveSetting($k, $v);
            }
            AuditLog::log('backup_settings', 'settings', null, null, $clean, $userId);
            backup_json(['success' => true, 'settings' => $backupManager->getSettings()]);
        }

        case 'apply_retention': {
            backup_json($backupManager->applyRetention());
        }

        case 'regen_cron_token': {
            $token = $backupManager->saveSetting('backup_cron_token', bin2hex(random_bytes(32)));
            AuditLog::log('backup_cron_token', 'settings', null, null, null, $userId);
            backup_json(['success' => true, 'token' => $token]);
        }

        default:
            http_response_code(400);
            backup_json(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('[backup api] ' . $e->getMessage());
    http_response_code(500);
    backup_json(['success' => false, 'message' => 'هەڵەیەکی ناوخۆیی ڕوویدا: ' . $e->getMessage()]);
}
