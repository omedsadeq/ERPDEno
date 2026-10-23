<?php
/**
 * دکتۆرەکان — the doctors' cards as a PDF report (mPDF).
 *
 * Same doctors, same period, same figures and same colours as
 * doctors/index.php: both read _doctors_data.php, and each card's money lines
 * come from the same dcMoneyList(). Same access rule too (page.doctors).
 *
 * mPDF has no CSS grid or flex, so the cards are laid out with tables: two
 * cards per row, and every row is its own table marked page-break-inside:avoid
 * so a card is never cut in half across two pages.
 */

require_once __DIR__ . '/_doctors_data.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

require __DIR__ . '/../includes/clinic_branding.php';


// Plain-text version of the period for the PDF: mPDF lays dates out
// correctly on its own with <bdo>, and does not need the screen's LRM marks.
$bdo = fn(string $d) => '<bdo dir="ltr">' . dsEsc($d) . '</bdo>';
if ($from === $to) {
    $periodHtml = dsEsc(DS_DAYS_KU[((int)date('w', strtotime($from)) + 1) % 7]) . ' — ' . $bdo($from);
} elseif (ds_is_whole_month($from, $to)) {
    $periodHtml = dsEsc(DS_MONTHS_KU[substr($from, 5, 2)] . ' ' . substr($from, 0, 4))
        . ' — ' . $bdo($from) . ' بۆ ' . $bdo($to);
} else {
    $periodHtml = $bdo($from) . ' بۆ ' . $bdo($to);
}

$css = '
<style>
body { font-family: nrt; direction: rtl; font-size: 9pt; color: #0f172a; }
.head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1e3a8a; margin-bottom: 4mm; }
.head td { vertical-align: middle; padding-bottom: 2mm; }
.clinic { font-size: 12pt; font-weight: bold; color: #1e3a8a; }
.title { font-size: 16pt; font-weight: bold; }
.meta { border-collapse: collapse; }
.meta td { border: 1px solid #cbd5e1; background: #f1f5f9; padding: 1.4mm 3mm; font-size: 8.5pt; }
.meta td.k { color: #1e3a8a; font-weight: bold; }

/* clinic totals: two rows of five */
.kpis { width: 100%; border-collapse: separate; border-spacing: 1.5mm; margin: 0 -1.5mm 3mm; }
.kpis td { background: #f1f5f9; padding: 2mm 2.5mm; width: 20%; }
.kpis .k { font-size: 7.5pt; color: #64748b; }
.kpis .v { font-size: 11.5pt; font-weight: bold; direction: ltr; text-align: right; }
.kpis td.hl { background: #4f46e5; }
.kpis td.hl .k { color: #e0e7ff; }
.kpis td.hl .v { color: #ffffff; }

/* card rows */
.row { width: 100%; border-collapse: separate; border-spacing: 2mm 0; margin: 0 -2mm 3.5mm; page-break-inside: avoid; }
.row td.slot { width: 50%; vertical-align: top; padding: 0; }

.card { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; }
.card td { font-size: 9pt; }
.card td.hero { color: #ffffff; padding: 2.5mm 3mm; }
.card td.hero .nm { font-size: 11pt; font-weight: bold; }
.card td.hero .sb { font-size: 7.5pt; }
.card td.pct { color: #ffffff; text-align: center; padding: 2mm; }
.card td.pct .p { font-size: 12pt; font-weight: bold; }
.card td.pct .l { font-size: 7pt; }
.card td.st { text-align: center; padding: 1.8mm 1mm; background: #f8fafc; border-bottom: 1px solid #e2e8f0; width: 25%; }
.card td.st .n { font-size: 11pt; font-weight: bold; color: #0f172a; }
.card td.st .l { font-size: 7pt; color: #64748b; }
.card td.lb { padding: 1.6mm 3mm; color: #475569; border-bottom: 1px solid #f1f5f9; }
.card td.v { padding: 1.6mm 3mm; text-align: left; direction: ltr; font-weight: bold; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
.card tr.pos td.v { color: #047857; }
.card tr.neg td.v { color: #c2410c; }
.card tr.debt td.v, .card tr.debt td.lb { color: #dc2626; }
.card tr.net td { font-weight: bold; font-size: 10pt; }
.empty { text-align: center; color: #64748b; padding: 10mm; }
</style>';

// ── header ──
$html = $css . '
<table class="head"><tr>
    <td style="width:16mm;">' . (is_file($logoPath) ? '<img src="' . dsEsc($logoPath) . '" style="width:14mm;">' : '') . '</td>
    <td>
        <div class="clinic">' . dsEsc($clinicName) . '</div>
        <div class="title">ڕاپۆرتی دکتۆرەکان</div>
    </td>
    <td style="width:100mm;">
        <table class="meta" width="100%">
            <tr><td class="k" width="22mm">ماوە</td><td>' . $periodHtml . '</td></tr>
            <tr><td class="k">دکتۆر</td><td>' . count($cards) . ' دکتۆر — ڕێژەی وەرگیراو <bdo dir="ltr">' . $clinicPct . '%</bdo></td></tr>
        </table>
    </td>
</tr></table>';

if (!$cards) {
    $html .= '<div class="empty">هیچ دکتۆرێک نەدۆزرایەوە</div>';
} else {
    // ── clinic totals ──
    $kpis = [
        ['سەردان',            dsMoney($totals['visits']),   '#0f172a'],
        ['ئیش',               dsMoney($totals['work']),     '#0f172a'],
        ['پشکنین',            dsMoney($totals['exam']),     '#0f172a'],
        ['کۆی گشتی پارە',     dsMoney($totals['gross']),    '#0f172a'],
        ['داشکێنراو',         dsMoney($totals['disc']),     '#c2410c'],
        ['دوای داشکاندن',     dsMoney($totals['net']),      ''],
        ['وەرگیراو',          dsMoney($totals['received']), '#047857'],
        ['ماوە',              dsMoney($totals['left']),     '#b45309'],
        ['گەڕاوەی قەرزی کۆن', dsMoney($totals['oldDebt']),  '#047857'],
        ['قەرزی ماوەی گشتی',  dsMoney($totals['debt']),     '#dc2626'],
    ];
    // Styles inline: mPDF does not apply ".kpis .v"-style descendant rules to
    // divs inside cells, which left the highlighted tile dark-on-indigo.
    $html .= '<table class="kpis">';
    foreach (array_chunk($kpis, 5, true) as $rowKpis) {
        $html .= '<tr>';
        foreach ($rowKpis as $i => [$label, $value, $color]) {
            $hl = $i === 5;
            $html .= '<td style="background:' . ($hl ? '#4f46e5' : '#f1f5f9') . ';">'
                . '<div style="font-size:7.5pt;color:' . ($hl ? '#e0e7ff' : '#64748b') . ';">' . dsEsc($label) . '</div>'
                . '<div style="font-size:11.5pt;font-weight:bold;direction:ltr;text-align:right;color:'
                . ($hl ? '#ffffff' : ($color ?: '#0f172a')) . ';">' . dsEsc($value) . '</div></td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    // ── cards, two per row ──
    $cardHtml = function (array $c) use ($dcPalette, $dcSlot): string {
        [$c1, , $soft] = dcPaletteFor($c, $dcPalette, $dcSlot);
        $m = $c['m'];
        $sub = [$c['is_active'] ? 'چالاک' : 'ناچالاک'];
        if (!empty($c['specialization'])) {
            $sub[] = $c['specialization'];
        }
        if ($c['today_appointments'] > 0) {
            $sub[] = (int)$c['today_appointments'] . ' مەوعیدی ئەمڕۆ';
        }

        $h = '<table class="card">';
        // header band in the doctor's colour, with the collected percentage
        $h .= '<tr>'
            . '<td class="hero" colspan="3" style="background:' . $c1 . ';">'
            . '<div class="nm">' . dsEsc($c['full_name']) . '</div>'
            . '<div class="sb">' . dsEsc(implode(' · ', $sub)) . '</div></td>'
            . '<td class="pct" style="background:' . $c1 . ';">'
            . '<div class="p"><bdo dir="ltr">' . (int)$c['paid_pct'] . '%</bdo></div><div class="l">وەرگیراو</div></td>'
            . '</tr>';
        // the four counters
        $h .= '<tr>';
        foreach ([['سەردان', $m['work']['total']], ['ئیش', $m['work']['treatment_visits']],
                  ['پشکنین', $m['work']['checkup_visits']], ['ئیکسرە', $m['xrayCount']]] as [$l, $n]) {
            $h .= '<td class="st"><div class="n">' . dsMoney($n) . '</div><div class="l">' . $l . '</div></td>';
        }
        $h .= '</tr>';
        // the money list, exactly as on the page
        foreach (dcMoneyList($c) as [$label, $value, , $style]) {
            $isNet = $style === 'net';
            $bg = $isNet ? ' style="background:' . $soft . ';"' : '';
            $vColor = $isNet ? ' style="background:' . $soft . ';color:' . $c1 . ';"' : '';
            $h .= '<tr class="' . $style . '">'
                . '<td class="lb" colspan="2"' . $bg . '>' . dsEsc($label) . '</td>'
                . '<td class="v" colspan="2"' . $vColor . '>' . dsEsc($value) . '</td>'
                . '</tr>';
        }
        return $h . '</table>';
    };

    foreach (array_chunk($cards, 2) as $pair) {
        $html .= '<table class="row"><tr>';
        foreach ($pair as $c) {
            $html .= '<td class="slot">' . $cardHtml($c) . '</td>';
        }
        if (count($pair) === 1) {
            $html .= '<td class="slot"></td>';
        }
        $html .= '</tr></table>';
    }
}

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
$pdf->setTitle('ڕاپۆرتی دکتۆرەکان — ' . $from . ($from === $to ? '' : ' / ' . $to));
$pdf->getMpdf()->SetHTMLFooter(
    '<table width="100%" style="font-family:nrt;font-size:7.5pt;color:#64748b;border-top:1px solid #e2e8f0;">'
    . '<tr><td style="text-align:right;">چاپکراوە لەلایەن ' . dsEsc($currentUser['full_name'] ?? '') . ' — '
    . '<span style="direction:ltr;">' . date('Y-m-d H:i') . '</span></td>'
    . '<td style="text-align:left;direction:ltr;">{PAGENO} / {nbpg}</td></tr></table>'
);
$pdf->setContent($html);
$pdf->output('doctors-report-' . $from . ($from === $to ? '' : '_' . $to) . '.pdf', 'I');
