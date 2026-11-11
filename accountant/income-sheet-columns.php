<?php
/**
 * داهات — saves the names and «پیشاندان / شاردنەوە» of the sheet's columns
 * (classes/IncomeSheetColumns.php) and goes back to the same sheet.
 * Who may: whoever may set the sheet's own columns (income.columns).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/IncomeColumn.php';
require_once __DIR__ . '/../classes/IncomeSheetColumns.php';

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

$before = IncomeSheetColumns::all();
if (($_POST['action'] ?? '') === 'reset') {
    IncomeSheetColumns::reset();
} else {
    IncomeSheetColumns::save((array)($_POST['label'] ?? []), (array)($_POST['shown'] ?? []));
}
$user = $auth->getCurrentUser();
AuditLog::log('income_sheet_columns', 'settings', null,
    array_map(fn($c) => ['label' => $c['label'], 'hidden' => $c['hidden']], $before),
    array_map(fn($c) => ['label' => $c['label'], 'hidden' => $c['hidden']], IncomeSheetColumns::all()),
    (int)($user['id'] ?? 0));

header('Location: ' . APP_URL . '/accountant/income.php' . $return . ($return === '' ? '?' : '&') . 'sheet_cols=1');
exit;
