<?php
/**
 * پوختەی ڕۆژانەی دکتۆر — access + the figures for one doctor's day.
 *
 * Included by doctors/daily-summary.php (screen) and daily-summary-pdf.php
 * (mPDF), BEFORE any output: it does the login and permission checks and
 * redirects on failure. The numbers themselves come from ds_compute() in
 * _daily_summary_lib.php, which the all-doctors report uses too.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/_daily_summary_lib.php';

$authGate = new Auth();
$authGate->requireLogin();

if (!Permission::getInstance()->can('page.doctor_report')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();

$dsViewer = $authGate->getCurrentUser();
$viewerUserId = (int)($dsViewer['id'] ?? 0);
$isAdminView = $authGate->isAdmin() || $authGate->isAccountant();

// A doctor is pinned to themselves and may never widen the scope; only an
// admin/accountant gets the picker.
$docUserId = $viewerUserId;
if ($isAdminView && (int)($_GET['doctor'] ?? 0) > 0) {
    $docUserId = (int)$_GET['doctor'];
}

$doctorList = $isAdminView
    ? $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name")
    : [];

// The period: لە (from) … بۆ (to), today by default. Old ?date= links still
// open that one day.
$today = date('Y-m-d');
[$from, $to] = ds_range_from_request($_GET, $today, $today);
[$prevFrom, $prevTo] = ds_shift_range($from, $to, -1);
[$nextFrom, $nextTo] = ds_shift_range($from, $to, +1);
$isSingleDay = ($from === $to);
// The clock-in/out buttons only make sense on today's own sheet.
$isToday = $isSingleDay && $from === $today;
$rangeLabel = ds_range_label($from, $to);
$rangePresets = ds_range_presets();

// Every figure as a plain variable ($grossBilled, $work, $patientRows, …) —
// the key list is the return array of ds_compute_range().
extract(ds_compute_range($db, $docUserId, $from, $to));

$dsLists = ds_row_lists(compact(
    'grossBilled', 'invoiceDisc', 'invoiceNet', 'treatmentCash', 'chairPending', 'newDebtToday',
    'labExpense', 'storeExpense', 'netAfterExpenses', 'oldDebtCollected', 'advanceCollected',
    'cashIn', 'doctorRate', 'doctorShare', 'centerShare',
    'xrayCount', 'xrayPatients', 'xrayByType', 'work', 'checkupBreakdown',
    'attendanceMinutes', 'attendedDay', 'workMinutes', 'examMinutes', 'idleMinutes'
));
$dsMoneyRows = $dsLists['money'];
$dsXrayRows  = $dsLists['xray'];
$dsVisitRows = $dsLists['visit'];
$dsTimeRows  = $dsLists['time'];
