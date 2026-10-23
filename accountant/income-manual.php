<?php
/**
 * داهاتی دەستی — saves the manual pharmacy/examination amounts typed on the
 * income sheet (add / edit / delete) and goes back to the same sheet.
 * Rules and validation: classes/IncomeManualEntry.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/IncomeManualEntry.php';

$auth = new Auth();
$auth->requireLogin();
$perm = Permission::getInstance();
if (!$perm->can('page.accountant_financial') || !$perm->can(IncomeManualEntry::PERMISSION)) {
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
$back = function (string $flag, string $value = '1') use ($return) {
    $sep = $return === '' ? '?' : '&';
    header('Location: ' . APP_URL . '/accountant/income.php' . $return . $sep . $flag . '=' . rawurlencode($value) . '#incManual');
    exit;
};

$entries = new IncomeManualEntry();
$userId = (int)$auth->getCurrentUserId();
$action = (string)($_POST['action'] ?? '');
$id = (int)($_POST['entry_id'] ?? 0);

try {
    if ($action === 'delete' && $id > 0) {
        $entries->delete($id);
        $back('man_deleted');
    }
    $data = IncomeManualEntry::fromInput($_POST);
    if ($action === 'update' && $id > 0) {
        $entries->update($id, $data, $userId);
        $back('man_updated');
    }
    $entries->create($data, $userId);
    $back('man_added');
} catch (InvalidArgumentException $e) {
    $back('man_error', $e->getMessage());
}
