<?php
/**
 * Patient account statement as a spreadsheet — CSV, mirroring
 * erp/statement-export.php exactly (BOM + sep=; so Excel opens Kurdish text
 * correctly, no thousands separators so amounts stay summable numbers).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../classes/PatientLedger.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isAccountant = $auth->isAccountant();
$isDoctor = $auth->isDoctor();

if (!($isAdmin || $isAccountant) && !($isDoctor && !$isAdmin)) {
    http_response_code(403);
    exit('Forbidden');
}

$patientId = (int)($_GET['patient_id'] ?? $_GET['id'] ?? 0);
if (!$patientId) {
    http_response_code(404);
    exit('نەدۆزرایەوە');
}

$doctorScopeUserId = null;
if ($isDoctor && !$isAdmin) {
    AccessGuard::requirePatientGeneral($patientId);
    $doctorScopeUserId = (int)$currentUser['id'];
}

$patient = (new Patient())->getById($patientId);
if (!$patient) {
    http_response_code(404);
    exit('نەدۆزرایەوە');
}

function pse_valid_date($v)
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
}
$from = pse_valid_date($_GET['ledger_from'] ?? '') ? $_GET['ledger_from'] : null;
$to = pse_valid_date($_GET['ledger_to'] ?? '') ? $_GET['ledger_to'] : null;

$data = (new PatientLedger())->statement($patientId, $doctorScopeUserId, $from, $to);

$docLabels = [
    'INVOICE'       => 'وەسڵی چارەسەر',
    'DISCOUNT'      => 'داشکاندن',
    'CHAIR_PAYMENT' => 'نەقدی لای دکتۆر',
    'PRIOR_PAYMENT' => 'دراوی پێشوو (کۆن)',
    'PAYMENT'       => 'پارەدان',
    'REFUND'        => 'گەڕاندنەوەی پارەدان',
    'XRAY'          => 'قەرزی ئیکسڕەی',
    'XRAY_PAYMENT'  => 'پارەدانی قەرزی ئیکسڕەی',
];

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $patient['full_name']);
$safeName = trim((string)$safeName, '-');
if ($safeName === '') {
    $safeName = 'patient';
}
$filename = sprintf('statement-%s-%d-%s.csv', $safeName, $patientId, date('Ymd'));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
echo "\xEF\xBB\xBF";
echo "sep=;\n";

$w = static function (array $row) use ($out) {
    fputcsv($out, $row, ';');
};

$w(['کشف حسابی نەخۆش']);
$w(['ناو', $patient['full_name']]);
$w(['کۆدی نەخۆش', $patient['patient_code'] ?? '']);
$w(['ماوە', $from ?: 'سەرەتا', 'بۆ', $to ?: 'ئێستا']);
$w(['بەرواری چاپ', date('Y-m-d H:i')]);
$w([]);

$w(['بەروار', 'جۆر', 'ژمارە', 'وردەکاری', 'قەرز', 'دانەوە', 'باڵانس']);
$w(['', 'باڵانسی سەرەتایی', '', '', '', '', round((float)$data['opening'], 2)]);

foreach ($data['rows'] as $r) {
    $w([
        substr($r['entry_date'], 0, 10),
        $docLabels[$r['doc_type']] ?? $r['doc_type'],
        $r['doc_number'],
        $r['description'],
        (float)$r['debit'] != 0.0 ? round((float)$r['debit'], 2) : '',
        (float)$r['credit'] != 0.0 ? round((float)$r['credit'], 2) : '',
        round((float)$r['balance'], 2),
    ]);
}

$w([]);
$w(['', 'کۆی گشتی', '', '',
    round((float)$data['totals']['debit'], 2),
    round((float)$data['totals']['credit'], 2),
    round((float)$data['closing'], 2)]);
$w(['', 'قەرزی ماوە', '', '', '', '', round((float)$data['closing'], 2)]);

fclose($out);
