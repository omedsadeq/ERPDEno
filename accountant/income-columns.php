<?php
/**
 * کۆڵۆمی تایبەت — saves the income sheet's own columns and the amounts under
 * them, and goes back to the same sheet. GET ?patient_cases=N gives the voucher
 * dialog a patient's cases with money that can go back. Rules: classes/IncomeColumn.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/IncomeColumn.php';

$auth = new Auth();
$auth->requireLogin();
$perm = Permission::getInstance();
if (!$perm->can('page.accountant_financial') || !$perm->can(IncomeColumn::PERMISSION)) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$columns = new IncomeColumn();
if (isset($_GET['patient_cases'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['cases' => $columns->refundableCases((int)$_GET['patient_cases'])], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/accountant/income.php');
    exit;
}
Csrf::require();

// Back to the sheet the form was on (its own query string only).
$return = (string)($_POST['return'] ?? '');
if ($return === '' || $return[0] !== '?' || preg_match('/[\r\n]/', $return)) {
    $return = '';
}
$back = function (string $flag, string $value, string $more = '') use ($return) {
    $sep = $return === '' ? '?' : '&';
    header('Location: ' . APP_URL . '/accountant/income.php' . $return . $sep . $flag . '=' . rawurlencode($value) . $more . '#incCols');
    exit;
};

$userId = (int)$auth->getCurrentUserId();
$action = (string)($_POST['action'] ?? '');
$columnId = (int)($_POST['column_id'] ?? 0);
$entryId = (int)($_POST['entry_id'] ?? 0);

try {
    switch ($action) {
        case 'column_save':
            $columns->saveColumn($columnId, $_POST, $userId);
            $back('col_done', 'col_saved');
        case 'column_remove':
            $columns->removeColumn($columnId, $userId);
            $back('col_done', 'col_removed');
        case 'entry_cancel':
            $columns->cancelEntry($entryId, (string)($_POST['reason'] ?? ''), $userId);
            $back('col_done', 'cancelled');
        case 'entry_save':
            $data = $columns->entryFromInput($_POST);
            if ($entryId > 0) {
                $columns->updateEntry($entryId, $data, $userId);
                $back('col_done', 'updated');
            }
            $id = $columns->createEntry($data, $userId);
            // A voucher's paper is one click away from the message.
            $back('col_done', 'added', isset($data['case_id']) ? '&voucher=' . $id : '');
    }
    $back('col_error', 'کردارەکە نەناسرایەوە');
} catch (InvalidArgumentException | DomainException $e) {
    $back('col_error', $e->getMessage());
}
