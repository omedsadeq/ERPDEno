<?php
/**
 * پوختەی ڕۆژانەی دکتۆر — PDF (mPDF).
 *
 * Renders the same figures as doctors/daily-summary.php from the same shared
 * file, so the report always matches the screen it was opened from. Same
 * access rules too: a doctor gets only their own sheet.
 *
 * Laid out with tables rather than CSS grid — mPDF does not do grid/flex.
 */

require_once __DIR__ . '/_daily_summary_data.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

require __DIR__ . '/../includes/clinic_branding.php';



/** A key/value block. Values are LTR so "(1,000)" never comes out mirrored. */
function dsPdfRows(array $rows)
{
    $html = '';
    foreach ($rows as [$label, $value, $style]) {
        $cls = $style === 'total' ? ' class="tot"' : '';
        $vcls = $style === 'pos' ? 'v pos' : ($style === 'neg' ? 'v neg' : 'v');
        $html .= '<tr' . $cls . '><td class="k">' . dsEsc($label) . '</td>'
            . '<td class="' . $vcls . '">' . dsEsc($value) . '</td></tr>';
    }
    return $html;
}

$css = '
<style>
body { font-family: nrt; direction: rtl; font-size: 9.5pt; color: #172b3a; }
.head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #244b68; margin-bottom: 4mm; }
.head td { vertical-align: middle; padding-bottom: 2mm; }
.clinic { font-size: 12pt; font-weight: bold; color: #244b68; }
.title { font-size: 15pt; font-weight: bold; color: #172b3a; }
.meta { border-collapse: collapse; }
.meta td { border: 1px solid #b9c6cf; background: #f2f6f9; padding: 1.5mm 3mm; font-size: 9pt; }
.meta td.k { color: #244b68; font-weight: bold; }
.warn { border: 1px solid #d9a441; background: #fdf6e6; color: #7a5200; padding: 2mm 3mm; font-size: 8.5pt; margin-bottom: 3mm; }

/* mPDF ignores child selectors, so these stay single-class. */
.cols { width: 100%; border-collapse: collapse; }
td.col { width: 33.33%; vertical-align: top; padding: 0 1.5mm; }

.kv { width: 100%; border-collapse: collapse; border: 1px solid #b9c6cf; }
.kv td { padding: 1.6mm 2.5mm; border-bottom: 1px solid #e3e9ee; font-size: 9pt; }
.kv td.card-h { background: #244b68; color: #fff; font-weight: bold; font-size: 10pt; padding: 1.8mm 2.5mm; border-bottom: 0; }
.kv td.v { text-align: left; direction: ltr; font-weight: bold; white-space: nowrap; }
.kv tr.tot td { background: #e6edf2; font-weight: bold; border-top: 1px solid #9cafbd; }
.kv tr.sub td { background: #f2f6f9; color: #526b7d; font-weight: bold; font-size: 8.5pt; }
.pos { color: #087b35; }
.neg { color: #a25c00; }

.sec-h { background: #244b68; color: #fff; font-weight: bold; font-size: 10pt; padding: 1.8mm 2.5mm; margin-top: 5mm; }
.tbl { width: 100%; border-collapse: collapse; border: 1px solid #b9c6cf; border-top: 0; }
.tbl th { background: #e6edf2; color: #18364c; font-size: 8.5pt; padding: 1.6mm 2mm; border-bottom: 1px solid #b9c6cf; text-align: right; }
.tbl th.n { text-align: left; }
.tbl td { padding: 1.4mm 2mm; border-bottom: 1px solid #e3e9ee; font-size: 9pt; }
.tbl td.n { text-align: left; direction: ltr; white-space: nowrap; }
.tbl td.i { text-align: center; color: #7a8b98; width: 7mm; }
.tbl tr.tot td { background: #e6edf2; font-weight: bold; border-top: 1px solid #9cafbd; }
.tbl td.empty { text-align: center; color: #7a8b98; padding: 4mm; }
</style>';

// ── header ──
$html = $css . '
<table class="head"><tr>
    <td style="width:16mm;">' . (is_file($logoPath) ? '<img src="' . dsEsc($logoPath) . '" style="width:14mm;">' : '') . '</td>
    <td>
        <div class="clinic">' . dsEsc($clinicName) . '</div>
        <div class="title">پوختەی ڕۆژانەی دکتۆر</div>
    </td>
    <td style="width:75mm;">
        <table class="meta" width="100%">
            <tr><td class="k" width="16mm">دکتۆر</td><td>' . dsEsc($docName) . '</td></tr>
            <tr><td class="k">' . ($isSingleDay ? 'ڕۆژ' : 'ماوە') . '</td><td>' . ($isSingleDay
                ? dsEsc(DS_DAYS_KU[((int)date('w', strtotime($from)) + 1) % 7]) . ' — <bdo dir="ltr">' . dsEsc($from) . '</bdo>'
                : (ds_is_whole_month($from, $to) ? dsEsc($rangeLabel) . ' — ' : '')
                    . '<bdo dir="ltr">' . dsEsc($from) . '</bdo> بۆ <bdo dir="ltr">' . dsEsc($to) . '</bdo>') . '</td></tr>
        </table>
    </td>
</tr></table>';

if ($visitsLinkBroken) {
    $html .= '<div class="warn">ئەم هەژمارە بە تۆماری دکتۆرەوە نەبەستراوە، بۆیە سەردانەکان سفرن.</div>';
}

// ── the three summary blocks, side by side (RTL: money ends up on the right) ──
$html .= '
<table class="cols"><tr>
    <td class="col" valign="top">
        <table class="kv">
            <tr><td colspan="2" class="card-h">پارە</td></tr>'
            . dsPdfRows($dsMoneyRows) . '
        </table>
    </td>
    <td class="col" valign="top">
        <table class="kv">
            <tr><td colspan="2" class="card-h">سەردان و پشکنین</td></tr>'
            . dsPdfRows($dsVisitRows)
            . '<tr class="sub"><td colspan="2">ناردن بۆ ئێکسڕەی — داهاتەکەی هی سەنتەرە</td></tr>'
            . dsPdfRows($dsXrayRows) . '
        </table>
    </td>
    <td class="col" valign="top">
        <table class="kv">
            <tr><td colspan="2" class="card-h">کات</td></tr>' . dsPdfRows($dsTimeRows);
if (!empty($attendance['check_in_time'])) {
    $html .= '<tr class="sub"><td>هاتن / ڕۆیشتن</td><td class="v">'
        . dsEsc(date('H:i', strtotime($attendance['check_in_time'])))
        . ' → '
        . (!empty($attendance['check_out_time']) ? dsEsc(date('H:i', strtotime($attendance['check_out_time']))) : '—')
        . '</td></tr>';
}
$html .= '</table>
    </td>
</tr></table>';

// ── work types ──
$html .= '<div class="sec-h">جۆری ئیشەکان (' . dsMoney($treatmentCount) . ')</div>
<table class="tbl">
    <thead><tr><th style="width:7mm;">#</th><th>جۆر</th><th class="n" style="width:22mm;">ژمارە</th><th class="n" style="width:35mm;">بڕ</th></tr></thead>
    <tbody>';
if (!$topTreatments) {
    $html .= '<tr><td colspan="4" class="empty">هیچ ئیشێک تۆمار نەکراوە</td></tr>';
} else {
    foreach ($topTreatments as $i => $t) {
        $html .= '<tr><td class="i">' . ($i + 1) . '</td><td>' . dsEsc($t['treatment_name']) . '</td>'
            . '<td class="n">' . dsMoney($t['cnt']) . '</td><td class="n">' . dsMoney($t['amt']) . '</td></tr>';
    }
    $html .= '<tr class="tot"><td></td><td>کۆ</td><td class="n">' . dsMoney($treatmentCount)
        . '</td><td class="n">' . dsMoney($treatmentAmount) . '</td></tr>';
}
$html .= '</tbody></table>';

// ── patients ──
$html .= '<div class="sec-h">نەخۆشەکان (' . dsMoney(count($patientRows)) . ' کەیس)</div>
<table class="tbl">
    <thead><tr>
        <th style="width:7mm;">#</th><th>نەخۆش</th><th class="n" style="width:21mm;">بەروار</th><th class="n" style="width:11mm;">کات</th><th class="n" style="width:11mm;">ئیش</th>
        <th class="n" style="width:26mm;">حسابکراو</th><th class="n" style="width:26mm;">وەرگیراو</th><th class="n" style="width:26mm;">ماوە</th>
    </tr></thead>
    <tbody>';
if (!$patientRows) {
    $html .= '<tr><td colspan="8" class="empty">هیچ نەخۆشێک نییە</td></tr>';
} else {
    foreach ($patientRows as $i => $r) {
        $atTs = !empty($r['last_at']) ? strtotime($r['last_at']) : null;
        $html .= '<tr><td class="i">' . ($i + 1) . '</td><td>' . dsEsc($r['full_name']) . '</td>'
            . '<td class="n">' . ($atTs ? dsEsc(date('Y-m-d', $atTs)) : '') . '</td>'
            . '<td class="n">' . ($atTs ? dsEsc(date('H:i', $atTs)) : '') . '</td>'
            . '<td class="n">' . dsMoney($r['treatments']) . '</td>'
            . '<td class="n">' . dsMoney($r['billed']) . '</td>'
            . '<td class="n pos">' . dsMoney($r['received']) . '</td>'
            . '<td class="n' . ($r['left'] > 0 ? ' neg' : '') . '">' . dsMoney($r['left']) . '</td></tr>';
    }
    $html .= '<tr class="tot"><td></td><td>کۆ</td><td></td><td></td><td></td>'
        . '<td class="n">' . dsMoney($patientTotals['billed']) . '</td>'
        . '<td class="n pos">' . dsMoney($patientTotals['paid']) . '</td>'
        . '<td class="n neg">' . dsMoney($patientTotals['left']) . '</td></tr>';
}
$html .= '</tbody></table>';

$pdf = new InvoicePdfService([
    'orientation'      => 'P',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 10,
    'margin_bottom_mm' => 20,
    'margin_left_mm'   => 10,
    'margin_right_mm'  => 10,
]);
$pdf->setTitle('پوختەی ڕۆژانە — ' . $docName . ' — ' . $from . ($isSingleDay ? '' : ' / ' . $to));
$pdf->getMpdf()->SetHTMLFooter(
    '<table width="100%" style="font-family:nrt;font-size:7.5pt;color:#7a8b98;border-top:1px solid #d5dde3;">'
    . '<tr><td style="text-align:right;">چاپکراوە لەلایەن ' . dsEsc($dsViewer['full_name'] ?? '') . ' — '
    . '<span style="direction:ltr;">' . date('Y-m-d H:i') . '</span></td>'
    . '<td style="text-align:left;direction:ltr;">{PAGENO} / {nbpg}</td></tr></table>'
);
$pdf->setContent($html);
$pdf->output('daily-summary-' . $docUserId . '-' . $from . ($isSingleDay ? '' : '_' . $to) . '.pdf', 'I');
