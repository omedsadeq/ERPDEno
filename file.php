<?php
/**
 * Authenticated file gateway for patient X-ray / case images.
 *
 * The uploads/xray and uploads/cases folders are blocked from direct HTTP
 * access (see their .htaccess). Images are served ONLY through this endpoint,
 * which requires login and enforces the same doctor-isolation ownership rules
 * as the rest of the system. S3-backed files are handed out as short-lived
 * presigned URLs; local files are streamed from disk after the access check.
 *
 *   file.php?type=xray&id=<xray_images.id>
 *   file.php?type=case&id=<case_images.id>
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Bad request');
}

$db = Database::getInstance();
$imagePath = null;
$s3Key = null;

if ($type === 'xray') {
    $row = $db->fetch(
        "SELECT xi.image_path, xi.s3_key, xr.requested_by
         FROM xray_images xi
         JOIN xray_requests xr ON xi.request_id = xr.id
         WHERE xi.id = :id",
        ['id' => $id]
    );
    if (!$row) { http_response_code(404); exit('Not found'); }

    // Staff who process X-rays may view any image; a doctor only their own requests.
    $allowed = $auth->isAdmin() || $auth->isXrayDepartment() || $auth->isAccountant();
    if (!$allowed && $auth->isDoctor()) {
        $allowed = ((int)$row['requested_by'] === AccessGuard::doctorId());
    }
    if (!$allowed) { http_response_code(403); exit('Access denied'); }

    $imagePath = $row['image_path'];
    $s3Key     = $row['s3_key'];
} elseif ($type === 'case') {
    $row = $db->fetch(
        "SELECT ci.image_path, ci.s3_key, pf.doctor_id
         FROM case_images ci
         JOIN cases c ON ci.case_id = c.id
         JOIN patient_folders pf ON c.folder_id = pf.id
         WHERE ci.id = :id",
        ['id' => $id]
    );
    if (!$row) { http_response_code(404); exit('Not found'); }

    // Admin/reception/accountant may view; a doctor only their own cases.
    if (AccessGuard::isRestrictedDoctor() && (int)$row['doctor_id'] !== AccessGuard::doctorId()) {
        http_response_code(403);
        exit('Access denied');
    }

    $imagePath = $row['image_path'];
    $s3Key     = $row['s3_key'];
} else {
    http_response_code(400);
    exit('Bad request');
}

// S3-backed file → redirect to a short-lived presigned URL.
$useS3 = !empty($_ENV['S3_ACCESS_KEY'] ?? getenv('S3_ACCESS_KEY'));
if (!empty($s3Key) && $useS3) {
    require_once __DIR__ . '/classes/S3Uploader.php';
    header('Location: ' . S3Uploader::getInstance()->getPresignedUrl($s3Key, 60));
    exit;
}
// Already an absolute URL (legacy S3 full-url rows).
if (strpos((string)$imagePath, 'http') === 0) {
    header('Location: ' . $imagePath);
    exit;
}

// Local file → stream from disk, but only from inside uploads/ (no traversal).
$full = APP_ROOT . '/' . ltrim((string)$imagePath, '/');
$real = realpath($full);
$base = realpath(APP_ROOT . '/uploads');
if ($real === false || $base === false || strpos($real, $base) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('Not found');
}

$ext   = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$mime  = $mimes[$ext] ?? 'application/octet-stream';

// Stream straight to the client — don't let the page output buffer started in
// config.php hold a whole x-ray image in memory before sending it.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!headers_sent()) {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . basename($real) . '"');
    header('Cache-Control: private, no-store');
}
readfile($real);
