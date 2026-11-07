<?php
/**
 * The body of the dialog behind one income figure (includes/income-detail.php):
 * ?kind=doctor|xray|pharmacy|exam &id=<doctor's users.id> &from=&to=. HTML,
 * fetched by the button beside the figure on accountant/income.php and
 * accountant/financial-summary.php — the same permission as those pages.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../includes/income-detail.php';

$auth = new Auth();
$auth->requireLogin();
// income.php's permission, or financial-summary.php's rule (accountant or admin)
if (!Permission::getInstance()->can('page.accountant_financial') && !$auth->isAccountant() && !$auth->isAdmin()) {
    http_response_code(403);
    exit('ڕێگەت پێنەدراوە');
}

$day = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : date('Y-m-d');
$from = $day($_GET['from'] ?? null);
$to = $day($_GET['to'] ?? null);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$kind = (string)($_GET['kind'] ?? '');
$data = in_array($kind, INCOME_DETAIL_KINDS, true)
    ? income_detail_data(Database::getInstance(), $kind, (int)($_GET['id'] ?? 0), $from, $to)
    : null;
if ($data === null) {
    http_response_code(404);
    exit('نەدۆزرایەوە');
}
header('Content-Type: text/html; charset=utf-8');
income_detail_body($data, $from, $to);
