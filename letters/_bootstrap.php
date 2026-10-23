<?php
/**
 * نوسراوی فەرمی — shared start of every letters/ page: login, the model, the
 * renderer, and the section bar (letters/_nav.php) that header.php draws in
 * place of a breadcrumb.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/OfficialLetter.php';
require_once __DIR__ . '/_render.php';

$auth = new Auth();
$auth->requireLogin();
$perm = Permission::getInstance();
$letters = new OfficialLetter();
$letterUserId = (int)$auth->getCurrentUserId();
$pageModuleNav = __DIR__ . '/_nav.php';

/** Stop here unless the user holds one of $keys (403 page, or JSON for AJAX). */
function letters_require(string ...$keys): void
{
    $perm = Permission::getInstance();
    foreach ($keys as $key) {
        if ($perm->can($key)) {
            return;
        }
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'ڕێگەپێدراو نیت'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

/** What the renderer needs besides the settings: the footer image's shape. */
function letters_render_opts(array $cfg): array
{
    return ['footer_ratio' => lt_image_ratio(OfficialLetter::assetPath('footer', $cfg)) ?: 0.12];
}
