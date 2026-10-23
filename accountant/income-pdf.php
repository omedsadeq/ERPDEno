<?php
/**
 * داهات — PDF of the daily income sheet, printed to look like the paper
 * تۆماری ڕۆژی it replaces: letterhead, the table, and the totals beside the
 * تێبینی / وەرگر boxes, with room for the cashier's stamp.
 *
 * Same figures as accountant/income.php (both read _income_data.php).
 */

require_once __DIR__ . '/_income_data.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

require __DIR__ . '/../includes/clinic_branding.php';

$logoPath = __DIR__ . '/../assets/images/logo.png';
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
$numCols = 4 + count($colSel);
$numWidth = min(30, (int)floor(125 / $numCols));
$tblFont = $numCols >= 6 ? '8.5pt' : ($numCols === 5 ? '9pt' : '10pt');
$rateText = fn(?float $r) => $r === null ? '؟' : rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.') . '%';

$html = '
<style>
body { font-family: nrt; direction: rtl; font-size: 10pt; color: #0f172a; }
.head { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
.head td { vertical-align: middle; }
.ku { font-size: 15pt; font-weight: bold; color: #0369a1; }
.addr { font-size: 8.5pt; color: #475569; }
.en { font-size: 14pt; font-weight: bold; color: #0369a1; text-align: left; direction: ltr; }
.ph { font-size: 9pt; color: #475569; text-align: left; direction: ltr; }
.line { border-bottom: 2px solid #0284c7; height: 1mm; margin-bottom: 3mm; }
.datebar { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
.datebar td.k { font-weight: bold; width: 24mm; font-size: 10pt; }
.datebar td.v { background: #cfe6f3; padding: 1.8mm 3mm; font-weight: bold; font-size: 10pt; }
.tbl { width: 100%; border-collapse: collapse; }
.tbl th { background: #cfe6f3; color: #0c4a6e; font-size: 9.5pt; padding: 2.2mm 2mm; border: 1px solid #7fb3d0; }
.tbl td { padding: 2.4mm 1.6mm; border: 1px solid #a9c9dc; font-size: ' . $tblFont . '; }
.tbl td.i { text-align: center; width: 7mm; color: #475569; }
.tbl td.c { text-align: center; width: 13mm; }
.tbl td.n { text-align: left; direction: ltr; white-space: nowrap; width: ' . $numWidth . 'mm; }
.tbl th.doc { background: #ede9fe; color: #5b21b6; }
.tbl th.ctr { background: #ccfbf1; color: #115e59; }
.tbl td.doc { color: #5b21b6; }
.tbl td.ctr { color: #0f766e; font-weight: bold; }
.rt { font-size: 7pt; color: #6d28d9; }
.note { margin: 0 0 3mm; padding: 2mm 3mm; border: 1px solid #fde68a; background: #fffbeb; color: #92400e; font-size: 8.5pt; }
.tbl td.en { direction: ltr; text-align: center; }
.man { direction: rtl; font-size: 7pt; color: #b45309; }
.tbl td.lb { text-align: center; }
.tbl tr.tot td { background: #e0f2fe; font-weight: bold; }
.foot { width: 100%; border-collapse: collapse; margin-top: 5mm; }
.foot td { vertical-align: top; }
.fbox { width: 100%; border-collapse: collapse; }
.fbox td.k { font-weight: bold; width: 20mm; padding: 2mm 1mm; font-size: 10pt; }
.fbox td.v { border: 1px solid #7fb3d0; padding: 2mm 3mm; height: 9mm; text-align: left; direction: ltr; font-weight: bold; font-size: 11pt; }
</style>

<table class="head"><tr>
    <td style="width:38%;">
        <div class="ku">' . dsEsc($clinicName) . '</div>
        <div class="addr">' . dsEsc($clinicAddress) . '</div>
    </td>
    <td style="width:24%;text-align:center;">' . (is_file($logoPath) ? '<img src="' . dsEsc($logoPath) . '" style="width:20mm;">' : '') . '</td>
    <td style="width:38%;">
        <div class="en">' . dsEsc($clinicNameEn) . '</div>
        <div class="ph">' . dsEsc($clinicPhone1) . ' · ' . dsEsc($clinicPhone2) . '</div>
    </td>
</tr></table>
<div class="line"></div>

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
        <th>#</th><th>ناوەڕۆک</th><th>ژمارە</th><th>بڕی</th>'
        . implode('', array_map(fn($k) => '<th>' . dsEsc($expenseKinds[$k]) . '</th>', $colSel)) . '
        <th>کۆی گشتی</th>
        <th class="doc">پشکی دکتۆر</th>
        <th class="ctr">پشکی سەنتەر</th>
    </tr></thead>
    <tbody>';

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
        . '<td class="n"' . $grp . '>' . $money($r['amount']) . '</td>'
        . implode('', array_map(fn($k) => '<td class="n"' . $grp . '>' . $money($r[$k]) . '</td>', $colSel))
        . '<td class="n"' . $grp . '>' . $money($r['net']) . '</td>'
        . '<td class="n doc"' . $grp . '>' . ($r['kind'] === 'doctor'
            ? $money($r['doctorShare']) . ' <span class="rt">' . $rateText($r['rate']) . '</span>' : '') . '</td>'
        . '<td class="n ctr"' . $grp . '>' . $money($r['centerShare']) . '</td>'
        . '</tr>';
}
$html .= '<tr class="tot">
        <td></td><td class="lb">کۆ</td>
        <td class="c">' . dsMoney($totals['count']) . '</td>
        <td class="n">' . dsMoney($totals['amount']) . '</td>'
        . implode('', array_map(fn($k) => '<td class="n">' . dsMoney($totals[$k]) . '</td>', $colSel)) . '
        <td class="n">' . ($totals['net'] < 0 ? '(' . dsMoney(abs($totals['net'])) . ')' : dsMoney($totals['net'])) . '</td>
        <td class="n doc">' . ($money($totals['doctorShare']) ?: '0') . '</td>
        <td class="n ctr">' . ($money($totals['centerShare']) ?: '0') . '</td>
    </tr>
    </tbody>
</table>

<table class="foot"><tr>
    <td style="width:48%;">
        <table class="fbox">
            <tr><td class="k">کۆی گشتی :</td><td class="v">' . ($sheetTotal < 0 ? '(' . dsMoney(abs($sheetTotal)) . ')' : dsMoney($sheetTotal)) . '</td></tr>
            <tr><td colspan="2" style="height:2mm;"></td></tr>
            <tr><td class="k">خەرجیات :</td><td class="v">' . dsMoney($expensesAll) . '</td></tr>
            <tr><td colspan="2" style="height:2mm;"></td></tr>
            <tr><td class="k">ماوە :</td><td class="v">' . ($remaining < 0 ? '(' . dsMoney(abs($remaining)) . ')' : dsMoney($remaining)) . '</td></tr>
            <tr><td colspan="2" style="height:4mm;"></td></tr>
            <tr><td class="k">پشکی دکتۆران :</td><td class="v" style="color:#5b21b6;">' . ($money($totals['doctorShare']) ?: '0') . '</td></tr>
            <tr><td colspan="2" style="height:2mm;"></td></tr>
            <tr><td class="k">پشکی سەنتەر :</td><td class="v" style="color:#0f766e;">' . ($money($totals['centerShare']) ?: '0') . '</td></tr>
            <tr><td colspan="2" style="height:2mm;"></td></tr>
            <tr><td class="k">سافی سەنتەر :</td><td class="v" style="color:' . ($centerAfterExpenses < 0 ? '#dc2626' : '#047857') . ';">'
                . ($centerAfterExpenses < 0 ? '(' . dsMoney(abs($centerAfterExpenses)) . ')' : dsMoney($centerAfterExpenses)) . '</td></tr>
        </table>
    </td>
    <td style="width:4%;"></td>
    <td style="width:48%;">
        <!-- Table cells, not divs: mPDF drops the height of an empty div, which
             left these two write-in boxes as bare labels. -->
        <table class="fbox">
            <tr><td class="k">تێبینی :</td><td class="v" style="height:17mm;"></td></tr>
            <tr><td colspan="2" style="height:2mm;"></td></tr>
            <tr><td class="k">وەرگر :</td><td class="v" style="height:17mm;"></td></tr>
        </table>
    </td>
</tr></table>';

$pdf = new InvoicePdfService([
    'orientation'      => 'P',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 10,
    'margin_bottom_mm' => 18,
    'margin_left_mm'   => 12,
    'margin_right_mm'  => 12,
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
