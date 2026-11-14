<?php
/**
 * داهات — PDF of the daily income sheet, printed to look like the paper
 * تۆماری ڕۆژی it replaces: the date line, the table, and the totals beside
 * the تێبینی / وەرگر boxes, with room for the cashier's stamp. No letterhead,
 * and set close, so a day fits on as little paper as it can.
 *
 * Same figures as accountant/income.php (both read _income_data.php).
 */

require_once __DIR__ . '/_income_data.php';
require_once __DIR__ . '/../classes/IncomeSheetColumns.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

$money = function (float $v): string {
    if (abs($v) < 0.5) {
        return '';
    }
    return $v < 0 ? '(' . dsMoney(abs($v)) . ')' : dsMoney($v);
};
$bdo = fn(string $d) => '<bdo dir="ltr">' . dsEsc($d) . '</bdo>';
$dateHtml = $from === $to
    ? dsEsc(DS_DAYS_KU[((int)date('w', strtotime($from)) + 1) % 7]) . ' — ' . $bdo($from)
    : $bdo($from) . ' بۆ ' . $bdo($to);
// Money columns: بڕی + the expense columns + کۆی گشتی + the two shares, in the
// ~125mm an A4 page leaves beside #, ناوەڕۆک and ژمارە.
// The sheet's own columns are printed when they have money in the period.
$pdfColumns = array_values(array_filter($incColumns, fn($c) => $c['period_total'] > 0));
// The store takes seven columns (کۆ · ئاسایی: دکتۆر · لێزان · Implant: دکتۆر ·
// لێزان), as on the owner's sheet: the page then turns landscape.
// Each column's name and whether it shows, as set on the screen (classes/IncomeSheetColumns.php).
$colName = fn(string $k) => IncomeSheetColumns::label($k);
$showCol = fn(string $k) => IncomeSheetColumns::shown($k);
$expCols = IncomeSheetColumns::expenseKeys($colSel);
$storeParts = IncomeSheetColumns::storeParts();
$storeCols = in_array('store', $expCols, true);
$numCols = (int)$showCol('amount') + count(array_diff($expCols, ['store'])) + count($storeParts) + count($pdfColumns)
    + (int)$showCol('pool') + (int)$showCol('doctorShare') + (int)$showCol('centerShare');
$landscape = $numCols >= 8;
$numWidth = min(30, (int)floor(($landscape ? 225 : 125) / $numCols));
$rs = $storeCols ? ' rowspan="2"' : '';
$tblFont = $numCols >= 6 ? '8.5pt' : ($numCols === 5 ? '9pt' : '10pt');
$rateText = fn(?float $r) => $r === null ? '؟' : rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.') . '%';

$html = '
<style>
body { font-family: nrt; direction: rtl; font-size: 10pt; color: #0f172a; }
.datebar { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
.datebar td.k { font-weight: bold; width: 22mm; font-size: 9.5pt; }
.datebar td.v { background: #cfe6f3; padding: 1.1mm 2.5mm; font-weight: bold; font-size: 9.5pt; }
.tbl { width: 100%; border-collapse: collapse; }
.tbl th { background: #cfe6f3; color: #0c4a6e; font-size: 9pt; padding: 1.3mm 1.5mm; border: 1px solid #7fb3d0; }
.tbl td { padding: 1.3mm 1.4mm; border: 1px solid #a9c9dc; font-size: ' . $tblFont . '; }
.tbl td.i { text-align: center; width: 7mm; color: #475569; }
.tbl td.c { text-align: center; width: 13mm; }
.tbl td.n { text-align: left; direction: ltr; white-space: nowrap; width: ' . $numWidth . 'mm; }
.tbl th.doc { background: #ede9fe; color: #5b21b6; }
.tbl th.ctr { background: #ccfbf1; color: #115e59; }
.tbl th.sto { background: #fde68a; color: #78350f; }
.tbl th.nrm { background: #ccfbf1; color: #0f766e; font-size: 8pt; }
.tbl th.imp { background: #ede9fe; color: #6d28d9; font-size: 8pt; }
.tbl td.sm { font-size: 7.5pt; color: #475569; }
.tbl td.doc { color: #5b21b6; }
.tbl td.ctr { color: #0f766e; font-weight: bold; }
.rt { font-size: 7pt; color: #6d28d9; }
.note { margin: 0 0 2mm; padding: 1.3mm 2.5mm; border: 1px solid #fde68a; background: #fffbeb; color: #92400e; font-size: 8.5pt; }
.tbl td.en { direction: ltr; text-align: center; }
.man { direction: rtl; font-size: 7pt; color: #b45309; }
.tbl td.lb { text-align: center; }
.tbl tr.tot td { background: #e0f2fe; font-weight: bold; }
.foot { width: 100%; border-collapse: collapse; margin-top: 3mm; }
.foot td { vertical-align: top; }
.fbox { width: 100%; border-collapse: collapse; }
.fbox td.k { font-weight: bold; width: 27mm; padding: 1mm 1mm; font-size: 9.5pt; white-space: nowrap; }
.fbox td.v { border: 1px solid #7fb3d0; padding: 1.1mm 2.5mm; height: 6.5mm; text-align: left; direction: ltr; font-weight: bold; font-size: 10.5pt; }
.fbox td.gap { height: 1mm; }
</style>

<table class="datebar"><tr>
    <td class="k">تۆماری ڕۆژی :</td>
    <td class="v" style="width:60mm;">داهات</td>
    <td style="width:10mm;"></td>
    <td class="k">بەرواری :</td>
    <td class="v">' . $dateHtml . '</td>
</tr></table>

' . ($doctorsWithoutRate
    ? '<div class="note">ڕێژەی ئەم دکتۆرانە دیاری نەکراوە، هەموو پارەکەیان لە پشکی سەنتەردا هەژمار کراوە: <b>'
      . dsEsc(implode('، ', $doctorsWithoutRate)) . '</b></div>'
    : '') . '
<table class="tbl">
    <thead><tr>
        <th' . $rs . '>#</th><th' . $rs . '>ناوەڕۆک</th><th' . $rs . '>ژمارە</th>'
        . ($showCol('amount') ? '<th' . $rs . '>' . dsEsc($colName('amount')) . '</th>' : '')
        . implode('', array_map(fn($k) => $k === 'store' ? '<th colspan="' . count($storeParts) . '" class="sto">' . dsEsc($colName('store')) . '</th>' : '<th' . $rs . '>' . dsEsc($colName($k)) . '</th>', $expCols))
        . implode('', array_map(fn($c) => '<th' . $rs . '>' . dsEsc($c['name']) . '</th>', $pdfColumns)) . '
        ' . ($showCol('pool') ? '<th' . $rs . '>' . dsEsc($colName('pool')) . '</th>' : '') . '
        ' . ($showCol('doctorShare') ? '<th class="doc"' . $rs . '>' . dsEsc($colName('doctorShare')) . '</th>' : '') . '
        ' . ($showCol('centerShare') ? '<th class="ctr"' . $rs . '>' . dsEsc($colName('centerShare')) . '</th>' : '') . '
    </tr>' . ($storeCols
        ? '<tr>' . implode('', array_map(fn($part) => '<th' . ($storeParts[$part][1] ? ' class="' . $storeParts[$part][1] . '"' : '') . '>'
            . dsEsc($colName($part)) . '</th>', array_keys($storeParts))) . '</tr>'
        : '') . '</thead>
    <tbody>';

// The store's cells under its heading, the shown ones only (its own total first)
$storeCells = function (array $src, string $grp, callable $fmt) use ($storeParts): string {
    $out = '';
    foreach ($storeParts as $part => [$field]) {
        $out .= '<td class="n' . ($part === 'storeTotal' ? '' : ' sm') . '"' . $grp . '>' . $fmt($src[$field]) . '</td>';
    }
    return $out;
};

$n = 0;
foreach ($rows as $i => $r) {
    $n++;
    $grp = $i === count($doctorRows) ? ' style="border-top:2px solid #0284c7;"' : '';
    $html .= '<tr>'
        . '<td class="i"' . $grp . '>' . $n . '</td>'
        . '<td class="' . ($r['kind'] === 'doctor' ? 'lb' : 'en') . '"' . $grp . '>' . dsEsc($r['label'])
        // داهاتی دەستی added on top of the pharmacy / examinations row
        . (abs($r['manualAmount'] ?? 0) >= 0.5
            ? '<div class="man">دەستی ' . dsMoney($r['manualAmount']) . '</div>'
            : '')
        . '</td>'
        . '<td class="c"' . $grp . '>' . ($r['count'] ? dsMoney($r['count']) : '') . '</td>'
        . ($showCol('amount') ? '<td class="n"' . $grp . '>' . $money($r['amount']) . '</td>' : '')
        . implode('', array_map(fn($k) => $k === 'store' ? $storeCells($r, $grp, $money)
            : '<td class="n"' . $grp . '>' . $money($r[$k]) . '</td>', $expCols))
        . implode('', array_map(fn($c) => '<td class="n"' . $grp . '>' . $money($r['cols'][$c['id']] ?? 0.0) . '</td>', $pdfColumns))
        . ($showCol('pool') ? '<td class="n"' . $grp . '>' . $money($r['pool']) . '</td>' : '')
        . ($showCol('doctorShare') ? '<td class="n doc"' . $grp . '>' . ($r['kind'] === 'doctor'
            ? $money($r['doctorShare']) . ' <span class="rt">' . $rateText($r['rate']) . '</span>' : '') . '</td>' : '')
        . ($showCol('centerShare') ? '<td class="n ctr"' . $grp . '>' . $money($r['centerShare']) . '</td>' : '')
        . '</tr>';
}
$html .= '<tr class="tot">
        <td></td><td class="lb">کۆ</td>
        <td class="c">' . dsMoney($totals['count']) . '</td>'
        . ($showCol('amount') ? '<td class="n">' . dsMoney($totals['amount']) . '</td>' : '')
        . implode('', array_map(fn($k) => $k === 'store' ? $storeCells($totals, '', 'dsMoney')
            : '<td class="n">' . dsMoney($totals[$k]) . '</td>', $expCols))
        . implode('', array_map(fn($c) => '<td class="n">' . dsMoney($totals['cols'][$c['id']]) . '</td>', $pdfColumns))
        . ($showCol('pool') ? '<td class="n">' . ($totals['pool'] < 0 ? '(' . dsMoney(abs($totals['pool'])) . ')' : dsMoney($totals['pool'])) . '</td>' : '')
        . ($showCol('doctorShare') ? '<td class="n doc">' . ($money($totals['doctorShare']) ?: '0') . '</td>' : '')
        . ($showCol('centerShare') ? '<td class="n ctr">' . ($money($totals['centerShare']) ?: '0') . '</td>' : '') . '
    </tr>
    </tbody>
</table>

<table class="foot"><tr>
    <td style="width:48%;">
        <table class="fbox">
            <tr><td class="k">کۆی گشتی :</td><td class="v">' . ($sheetTotal < 0 ? '(' . dsMoney(abs($sheetTotal)) . ')' : dsMoney($sheetTotal)) . '</td></tr>
            <tr><td colspan="2" class="gap"></td></tr>
            <tr><td class="k">ماوە :</td><td class="v">' . ($remaining < 0 ? '(' . dsMoney(abs($remaining)) . ')' : dsMoney($remaining)) . '</td></tr>
            <tr><td colspan="2" style="height:2mm;"></td></tr>
            <tr><td class="k">پشکی دکتۆران :</td><td class="v" style="color:#5b21b6;">' . ($money($totals['doctorShare']) ?: '0') . '</td></tr>
            <tr><td colspan="2" class="gap"></td></tr>
            <tr><td class="k">پشکی سەنتەر :</td><td class="v" style="color:#0f766e;">' . ($money($totals['centerShare']) ?: '0') . '</td></tr>
            <tr><td colspan="2" class="gap"></td></tr>
            <tr><td class="k">خەرجیات :</td><td class="v" style="color:#c2410c;">' . dsMoney($expensesAll) . '</td></tr>
            <tr><td colspan="2" class="gap"></td></tr>
            <tr><td class="k">سافی سەنتەر :</td><td class="v" style="color:' . ($centerAfterExpenses < 0 ? '#dc2626' : '#047857') . ';">'
                . ($centerAfterExpenses < 0 ? '(' . dsMoney(abs($centerAfterExpenses)) . ')' : dsMoney($centerAfterExpenses)) . '</td></tr>
        </table>
    </td>
    <td style="width:4%;"></td>
    <td style="width:48%;">
        <!-- Table cells, not divs: mPDF drops the height of an empty div, which
             left these two write-in boxes as bare labels. -->
        <table class="fbox">
            <tr><td class="k">تێبینی :</td><td class="v" style="height:13mm;"></td></tr>
            <tr><td colspan="2" class="gap"></td></tr>
            <tr><td class="k">وەرگر :</td><td class="v" style="height:13mm;"></td></tr>
        </table>
    </td>
</tr></table>';

$pdf = new InvoicePdfService([
    'orientation'      => $landscape ? 'L' : 'P',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 6,
    'margin_bottom_mm' => 12,
    'margin_left_mm'   => 8,
    'margin_right_mm'  => 8,
]);
$pdf->setTitle('داهات — ' . $from . ($from === $to ? '' : ' / ' . $to));
$pdf->getMpdf()->SetHTMLFooter(
    '<table width="100%" style="font-family:nrt;font-size:7.5pt;color:#64748b;border-top:1px solid #e2e8f0;">'
    . '<tr><td style="text-align:right;">چاپکراوە لەلایەن ' . dsEsc($incViewer['full_name'] ?? '') . ' — '
    . '<span style="direction:ltr;">' . date('Y-m-d H:i') . '</span></td>'
    . '<td style="text-align:left;direction:ltr;">{PAGENO} / {nbpg}</td></tr></table>'
);
$pdf->setContent($html);
$pdf->output('income-' . $from . ($from === $to ? '' : '_' . $to) . '.pdf', 'I');
