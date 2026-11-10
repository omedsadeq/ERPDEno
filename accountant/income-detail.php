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
// income.php's and financial-summary.php's permission (an accountant kept to
// approvals and debts does not have it)
if (!Permission::getInstance()->can('page.accountant_financial') && !$auth->isAdmin()) {
    http_response_code(403);
    exit('ڕێگەت پێنەدراوە');
}

$day = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : date('Y-m-d');
$from = $day($_GET['from'] ?? null);
$to = $day($_GET['to'] ?? null);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
// A change to an expense line (edit or delete), from the dialog: JSON back.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../classes/Csrf.php';
    header('Content-Type: application/json; charset=utf-8');
    try {
        Csrf::require();
        $msg = income_detail_expense_write(Database::getInstance(), $_POST, (int)($_SESSION['user_id'] ?? 0));
        echo json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException | PDOException $ex) {
        echo json_encode(['ok' => false, 'error' => $ex instanceof PeriodClosedException || $ex instanceof InvalidArgumentException
            ? $ex->getMessage() : 'هەڵەیەک لە داتابەیس ڕوویدا'], JSON_UNESCAPED_UNICODE);
    }
    exit;
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
