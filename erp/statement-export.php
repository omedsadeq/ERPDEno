<?php
/**
 * ERP — customer / supplier statement as a spreadsheet.
 *
 * CSV rather than a real .xlsx: Excel opens it natively, it needs no library,
 * and it streams without holding the statement in memory. The two details that
 * make it actually work here:
 *
 *   1. A UTF-8 BOM. Without it Excel on Windows reads the file in the system
 *      codepage and every Kurdish and Arabic name arrives as mojibake.
 *   2. sep=; on the first line. Excel picks its delimiter from the locale, and
 *      on a comma-decimal locale a comma-separated file lands entirely in
 *      column A. The hint makes it deterministic.
 *
 * Amounts are written unformatted (no thousands separators) so the cells are
 * numbers Excel can sum, not text.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpLedger.php';

$kind = $_GET['kind'] ?? 'customer';
if (!in_array($kind, ['customer', 'supplier'], true)) {
    $kind = 'customer';
}
$isCustomer = $kind === 'customer';
erp_require($isCustomer ? 'erp.customers.view' : 'erp.suppliers.view');

$partyId = (int)($_GET['id'] ?? 0);
$from    = erp_date($_GET['from'] ?? null);
$to      = erp_date($_GET['to'] ?? null);

$data = $isCustomer
    ? ErpLedger::customerStatement($partyId, $from, $to)
    : ErpLedger::supplierStatement($partyId, $from, $to);

if (!$data['party']) {
    http_response_code(404);
    exit('نەدۆزرایەوە');
}

$docLabels = [
    'OPENING'         => 'باڵانسی سەرەتایی',
    'SALE'            => 'فرۆشتن',
    'SALE_RETURN'     => 'گەڕاندنەوەی فرۆشتن',
    'PURCHASE'        => 'کڕین',
    'PURCHASE_RETURN' => 'گەڕاندنەوەی کڕین',
    'PAYMENT'         => 'پارەدان',
    'ADJUSTMENT'      => 'ڕێکخستن',
];

/* A filename with the party name in it is far more useful in a downloads
   folder than statement(3).csv, but non-ASCII in a Content-Disposition header
   is unreliable across browsers, so the readable part is transliterated down
   to a safe subset and the id keeps it unique. */
$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $data['party']['name']);
$safeName = trim((string)$safeName, '-');
if ($safeName === '') {
    $safeName = $kind;
}
$filename = sprintf('statement-%s-%d-%s.csv', $safeName, $partyId, date('Ymd'));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');

echo "\xEF\xBB\xBF";   // BOM: Excel reads UTF-8 only when this is present
echo "sep=;\n";        // delimiter hint, so locale settings cannot scatter the columns

$w = static function (array $row) use ($out) {
    fputcsv($out, $row, ';');
};

$w([$isCustomer ? 'کشف حسابی کڕیار' : 'کشف حسابی دابینکەر']);
$w(['ناو', $data['party']['name']]);
$w(['ماوە', $from ?: 'سەرەتا', 'بۆ', $to ?: 'ئێستا']);
$w(['بەرواری چاپ', date('Y-m-d H:i')]);
$w([]);

$w(['بەروار', 'جۆر', 'ژمارەی بەڵگە', 'قەرز', 'دانەوە', 'باڵانس', 'تێبینی']);

// Opening line, so the running balance in the sheet starts where the printed
// statement does rather than at zero.
$w(['', 'باڵانسی سەرەتایی', '', '', '', round((float)$data['opening'], 2), '']);

foreach ($data['rows'] as $r) {
    $w([
        $r['entry_date'],
        $docLabels[$r['doc_type']] ?? $r['doc_type'],
        $r['doc_number'] ?? '',
        (float)$r['debit']  != 0.0 ? round((float)$r['debit'], 2)  : '',
        (float)$r['credit'] != 0.0 ? round((float)$r['credit'], 2) : '',
        round((float)$r['balance'], 2),
        $r['notes'] ?? '',
    ]);
}

$w([]);
$w(['', 'کۆی گشتی', '',
    round((float)($data['totals']['debit'] ?? 0), 2),
    round((float)($data['totals']['credit'] ?? 0), 2),
    round((float)$data['closing'], 2), '']);
$w(['', $isCustomer ? 'ماوە لەسەر کڕیار' : 'ماوە لەسەر ئێمە', '', '', '',
    round((float)$data['closing'], 2), '']);

fclose($out);
