<?php
/**
 * Scheduled backup runner
 * باکئەپی خۆکاری داتابەیس
 *
 * Run it either way:
 *   CLI  : php /path/to/dentHisab/cron/backup-cron.php [--force]
 *   Web  : https://your-site/dentHisab/cron/backup-cron.php?token=SECRET[&force=1]
 *          (SECRET = the backup_cron_token shown on admin/backups.php)
 *
 * The schedule itself (enabled / frequency / time / retention / notify)
 * lives in the settings table and is edited on the admin page. Call this
 * script every 15-30 minutes; it exits instantly when nothing is due.
 */

define('BACKUP_CRON', true); // lets config.php skip the maintenance-mode gate

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Notification.php';
require_once __DIR__ . '/../classes/BackupManager.php';

$isCli = (PHP_SAPI === 'cli');
header('Content-Type: text/plain; charset=utf-8');

$backupManager = new BackupManager();
$settings      = $backupManager->getSettings();

// ── Access control ────────────────────────────────────────────────────
if (!$isCli) {
    $token = (string)($_GET['token'] ?? '');
    if ($settings['backup_cron_token'] === '' || !hash_equals($settings['backup_cron_token'], $token)) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}
$force = $isCli
    ? in_array('--force', $argv ?? [], true)
    : (($_GET['force'] ?? '') === '1');

// ── Is a run due? ─────────────────────────────────────────────────────
function backup_cron_due(array $s) {
    if ($s['backup_auto_enabled'] !== '1') {
        return [false, 'automatic backups are disabled'];
    }
    $now = time();
    [$h, $m] = array_map('intval', explode(':', $s['backup_auto_time'] ?: '02:00'));

    switch ($s['backup_auto_frequency']) {
        case 'weekly':
            if ((int)date('w', $now) !== (int)$s['backup_auto_weekday']) {
                return [false, 'not the scheduled weekday'];
            }
            break;
        case 'monthly':
            if ((int)date('j', $now) !== (int)$s['backup_auto_monthday']) {
                return [false, 'not the scheduled day of month'];
            }
            break;
    }

    $scheduledToday = mktime($h, $m, 0);
    if ($now < $scheduledToday) {
        return [false, 'scheduled time not reached yet'];
    }
    $lastRun = $s['backup_last_auto_run'] !== '' ? strtotime($s['backup_last_auto_run']) : 0;
    if ($lastRun >= $scheduledToday) {
        return [false, 'already ran for this slot'];
    }
    return [true, 'due'];
}

[$due, $why] = backup_cron_due($settings);
if (!$due && !$force) {
    echo "Nothing to do ({$why}).\n";
    exit;
}

// ── Run ───────────────────────────────────────────────────────────────
set_time_limit(0);
echo "Starting scheduled backup of " . DB_NAME . "...\n";
$backupManager->saveSetting('backup_last_auto_run', date('Y-m-d H:i:s'));

$result = $backupManager->create([
    'type'        => $settings['backup_auto_type'],
    'compression' => $settings['backup_auto_compression'],
    'method'      => 'auto',
    'trigger'     => 'scheduled',
    'user_id'     => null,
]);

$notify = ($settings['backup_notify'] === '1');
$notification = new Notification();

if (!empty($result['success'])) {
    echo "OK: {$result['file_name']} (" . BackupManager::humanSize($result['size']) . ", {$result['method']})\n";
    if ($notify) {
        $notification->notifyRole(ROLE_ADMIN, Notification::TYPE_SYSTEM,
            'باکئەپی خۆکار سەرکەوتوو بوو',
            'باکئەپی خۆکاری داتابەیس دروستکرا: ' . $result['file_name']
                . ' (' . BackupManager::humanSize($result['size']) . ')',
            null, '/admin/backups.php');
    }
    $retention = $backupManager->applyRetention();
    echo "Retention: removed " . ($retention['deleted'] ?? 0) . " old backup(s).\n";
} else {
    echo "FAILED: " . ($result['message'] ?? 'unknown error') . "\n";
    // Failure is always worth a notification, whatever the setting.
    $notification->notifyRole(ROLE_ADMIN, Notification::TYPE_SYSTEM,
        'باکئەپی خۆکار شکستی هێنا',
        mb_substr((string)($result['message'] ?? 'هەڵەی نەناسراو'), 0, 200),
        null, '/admin/backups.php');
    if (!$isCli) {
        http_response_code(500);
    }
    exit(1);
}
