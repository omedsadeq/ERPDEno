<?php
/**
 * Letterhead images — logo, header, footer, signature, seal.
 *
 * uploads/letters/ denies direct HTTP access (OfficialLetter::assetDir writes
 * its .htaccess), so the manager's signature and the clinic seal are only
 * handed to logged-in users who work with letters.
 *
 *   asset.php?k=signature&v=<mtime>
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/OfficialLetter.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}
$perm = Permission::getInstance();
if (!$perm->can('letters.view') && !$perm->can('letters.create') && !$perm->can('letters.settings')) {
    http_response_code(403);
    exit('Access denied');
}

$kind = (string)($_GET['k'] ?? '');
if (!isset(OfficialLetter::ASSETS[$kind])) {
    http_response_code(404);
    exit('Not found');
}
$path = OfficialLetter::assetPath($kind, (new OfficialLetter())->settings());
$info = $path ? @getimagesize($path) : false;
if (!$info) {
    http_response_code(404);
    exit('Not found');
}

// The URL carries the file's mtime, so a private cache is safe; a new upload
// gets a new URL.
header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header_remove('Pragma');
header_remove('Expires');
readfile($path);
