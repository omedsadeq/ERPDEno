<?php
/**
 * Protected backup download (Admin only)
 * داگرتنی فایلی باکئەپ
 *
 * Files are addressed only by their backup_history id — the path on disk is
 * resolved server-side and confined to storage/backups/, so no user input
 * ever becomes a path. Every download is written to the audit log.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/BackupManager.php';

$auth = new Auth();
$auth->requireAdmin();

$id  = (int)($_GET['id'] ?? 0);
$backupManager = new BackupManager();
$row = $backupManager->getById($id);

if (!$row || !in_array($row['status'], ['completed'], true)) {
    http_response_code(404);
    exit('باکئەپ نەدۆزرایەوە');
}

$path = $backupManager->filePathFor($row);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit('فایلەکە لەسەر دیسک نەدۆزرایەوە');
}

AuditLog::log('backup_download', 'backup_history', $id, null, ['file' => $row['file_name']]);

$mime = 'application/octet-stream';
if (substr($path, -7) === '.sql.gz') {
    $mime = 'application/gzip';
} elseif (substr($path, -4) === '.zip') {
    $mime = 'application/zip';
} elseif (substr($path, -4) === '.sql') {
    $mime = 'application/sql';
}

// A download can outlive the request defaults on big databases.
set_time_limit(0);
session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');

$fp = fopen($path, 'rb');
while (!feof($fp)) {
    echo fread($fp, 262144);
    flush();
}
fclose($fp);
exit;
