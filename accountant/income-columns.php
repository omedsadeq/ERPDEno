<?php
/**
 * کۆڵۆمی تایبەت — saves the income sheet's own columns and the amounts under
 * them, and goes back to the same sheet. Rules: classes/IncomeColumn.php.
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
$back = function (string $flag, string $value) use ($return) {
    $sep = $return === '' ? '?' : '&';
    header('Location: ' . APP_URL . '/accountant/income.php' . $return . $sep . $flag . '=' . rawurlencode($value) . '#incCols');
    exit;
};

$columns = new IncomeColumn();
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
        case 'entry_delete':
            $columns->deleteEntry($entryId, $userId);
            $back('col_done', 'deleted');
        case 'entry_save':
            $data = $columns->entryFromInput($_POST);
            if ($entryId > 0) {
                $columns->updateEntry($entryId, $data, $userId);
                $back('col_done', 'updated');
            }
            $columns->createEntry($data, $userId);
            $back('col_done', 'added');
    }
    $back('col_error', 'کردارەکە نەناسرایەوە');
} catch (InvalidArgumentException | DomainException $e) {
    $back('col_error', $e->getMessage());
}
