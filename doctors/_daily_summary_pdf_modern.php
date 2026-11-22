<?php
/**
 * پوختەی ڕۆژانە — the «مۆدێرن» PDF (mPDF): the page's modern look on paper.
 * Every figure as a card with its icon and colour (dsm_card_list, the page's
 * own), the work types as chips with what came in for each. Made to go on ONE
 * A4 page: eight small cards to a row, and no patients' list (that is on the
 * page, behind a click, and on the classic PDF).
 *
 * Included by daily-summary-pdf.php (?style=modern) with its variables in
 * scope; it sets $html. mPDF does no grid or flex, so the cards sit in table
 * cells; the icons are Font Awesome drawn from its TTF (the PDF service's
 * icon_font). Expects $css (the classic sheet's), $patientsHtml, $logoPath,
 * $clinicName and the daily-summary figures.
 */

require_once __DIR__ . '/_daily_summary_cards.php';

/** A Font Awesome glyph in the PDF's icon font. */
$dsmGlyph = static fn(string $icon): string => '<span style="font-family:fa;">&#x' . dechex(DSM_GLYPHS[$icon] ?? DSM_GLYPHS['fa-circle']) . ';</span>';

/** Cards, $per to a row (RTL: the first one on the right). */
$dsmGrid = static function (array $rows, int $per = 8) use ($dsmGlyph): string {
    $cards = dsm_card_list($rows);
    if (!$cards) {
        return '';
    }
    $w = round(100 / $per, 2);
    $html = '<table class="mg" width="100%">';
    foreach (array_chunk($cards, $per) as $line) {
        $html .= '<tr>';
        foreach ($line as $c) {
            [$line1, $tint] = DSM_COLOURS[$c['colour']] ?? DSM_COLOURS['slate'];
            $html .= '<td class="mc" width="' . $w . '%" style="border-top:1.3mm solid ' . $line1 . ';' . ($c['total'] ? 'border-left:0.45mm solid ' . $line1 . ';border-right:0.45mm solid ' . $line1 . ';border-bottom:0.45mm solid ' . $line1 . ';' : '') . '">'
                . '<span class="mi" style="background:' . $tint . ';color:' . $line1 . ';">' . $dsmGlyph($c['icon']) . '</span>'
                . '<div class="mv' . ($c['neg'] ? ' neg' : '') . '"><bdo dir="ltr">' . dsEsc($c['value']) . '</bdo></div>'
                . '<div class="ml">' . dsEsc($c['label']) . '</div>'
                . ($c['note'] !== '' ? '<div class="mn">' . dsEsc($c['note']) . '</div>' : '');
            foreach ($c['notes'] as $n) {
                $html .= '<div class="mn">' . dsEsc($n) . '</div>';
            }
            $html .= '</td>';
        }
        // an unfinished row keeps the cards their width
        for ($i = count($line); $i < $per; $i++) {
            $html .= '<td class="mx" width="' . $w . '%"></td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
};

$dsmSection = static fn(string $icon, string $title, string $small = ''): string =>
    '<div class="ms"><span class="msi">' . $dsmGlyph($icon) . '</span> ' . dsEsc($title)
    . ($small !== '' ? ' <span class="mss">' . dsEsc($small) . '</span>' : '') . '</div>';

$dsmCss = '
<style>
.mh { background: #0078d4; background-image: linear-gradient(120deg, #0078d4, #0e9f9a); color: #ffffff; border-radius: 3mm; padding: 2.5mm 4mm; margin-bottom: 1mm; }
.mh table { width: 100%; border-collapse: collapse; }
.mh td { color: #ffffff; vertical-align: middle; }
.mhi { font-size: 15pt; color: #ffffff; }
.mht { font-size: 13pt; font-weight: bold; }
.mhd { font-size: 8.5pt; color: #dff4f7; }
.mhc { font-size: 8pt; color: #dff4f7; text-align: left; }
.ms { background: #eef4fc; border-radius: 2mm; padding: 1mm 3mm; margin: 2mm 0 0.5mm; font-size: 9.5pt; font-weight: bold; color: #1e293b; }
.msi { color: #3b82f6; }
.mss { font-size: 7.5pt; font-weight: normal; color: #64748b; }
.mg { border-collapse: separate; border-spacing: 1.4mm; margin: 0 -1.4mm; }
.mc { background: #ffffff; border: 0.25mm solid #e2e8f0; text-align: center; vertical-align: top; padding: 1.2mm 0.6mm 1mm; }
.mx { border: 0; }
.mi { font-size: 8pt; padding: 0.6mm 1.3mm; }
.mv { font-size: 9.5pt; font-weight: bold; color: #0f172a; margin-top: 0.8mm; }
.mv.neg { color: #dc2626; }
.ml { font-size: 6.6pt; color: #334155; font-weight: bold; }
.mn { font-size: 5.9pt; color: #64748b; }
.mt { border-collapse: separate; border-spacing: 1.4mm; margin: 0 -1.4mm; }
.mt td.chip { border: 0.25mm solid #e2e8f0; background: #ffffff; padding: 0.8mm 1.4mm; vertical-align: middle; }
.mt .cb { font-size: 8.5pt; font-weight: bold; color: #0f172a; }
.mt .cn { font-size: 6.8pt; color: #334155; }
.mt .ca { font-size: 5.8pt; color: #64748b; }
.mt .cr { font-size: 5.8pt; color: #15803d; font-weight: bold; }
.mt .ci { font-size: 8pt; padding: 0.6mm 1.1mm; }
.sec-h { background: #eef4fc; color: #1e293b; border-radius: 2.5mm; }
.tbl { border-color: #e2e8f0; }
.tbl th { background: #f1f5f9; }
</style>';

// ── header: the system's blue into a medical teal ──
$period = $isSingleDay
    ? dsEsc(DS_DAYS_KU[((int)date('w', strtotime($from)) + 1) % 7]) . ' — <bdo dir="ltr">' . dsEsc($from) . '</bdo>'
    : (ds_is_whole_month($from, $to) ? dsEsc($rangeLabel) . ' — ' : '')
        . '<bdo dir="ltr">' . dsEsc($from) . '</bdo> بۆ <bdo dir="ltr">' . dsEsc($to) . '</bdo>';
$html = $css . $dsmCss . '
<div class="mh"><table><tr>
    <td style="width:10mm;"><span class="mhi">' . $dsmGlyph('fa-user-md') . '</span></td>
    <td>
        <div class="mht">پوختەی دکتۆر · ' . dsEsc($docName) . '</div>
        <div class="mhd">' . $dsmGlyph('fa-calendar-alt') . ' بەروار: ' . $period . '</div>
    </td>
    <td style="width:52mm;" class="mhc">'
        . (is_file($logoPath) ? '<img src="' . dsEsc($logoPath) . '" style="width:9mm;"><br>' : '')
        . dsEsc($clinicName) . '</td>
</tr></table></div>';

if ($visitsLinkBroken) {
    $html .= '<div class="warn">ئەم هەژمارە بە تۆماری دکتۆرەوە نەبەستراوە، بۆیە سەردانەکان سفرن.</div>';
}

$html .= $dsmSection('fa-coins', 'پارە') . $dsmGrid($dsMoneyRows)
    . $dsmSection('fa-user-injured', 'سەردان و پشکنین') . $dsmGrid($dsVisitRows)
    . $dsmSection('fa-stopwatch', 'کات') . $dsmGrid($dsTimeRows);
$html .= $dsmSection('fa-x-ray', 'ناردن بۆ ئێکسڕەی', 'داهاتەکەی هی سەنتەرە') . $dsmGrid($dsXrayRows)
    . $dsmSection('fa-pills', 'ناردن بۆ دەرمانخانە', 'داهاتەکەی هی سەنتەرە') . $dsmGrid($dsPharmacyRows);

// ── work types as chips, five to a row ──
$html .= $dsmSection('fa-folder-open', 'جۆری ئیشەکان', dsMoney($treatmentCount) . ' ئیش · ' . dsMoney($treatmentAmount) . ' · وەرگیراو ' . dsMoney($treatmentReceived ?? 0));
if (!$topTreatments) {
    $html .= '<div class="mn" style="text-align:center;padding:3mm;">هیچ ئیشێک تۆمار نەکراوە</div>';
} else {
    $chipColours = ['green', 'blue', 'purple', 'orange', 'teal', 'pink', 'indigo', 'red'];
    $html .= '<table class="mt" width="100%">';
    foreach (array_chunk($topTreatments, 5, true) as $line) {
        $html .= '<tr>';
        foreach ($line as $i => $t) {
            [$c1, $tint] = DSM_COLOURS[$chipColours[$i % count($chipColours)]];
            // flat (no table inside): the tooth and the count, the name, then the money
            // inline styles: mPDF does not carry the classes into a cell's divs
            $html .= '<td class="chip" width="20%" style="border-right:1.2mm solid ' . $c1 . ';padding:0.8mm 1.4mm;">'
                . '<div style="font-size:7pt;color:#334155;"><span style="font-family:fa;color:' . $c1 . ';">&#x' . dechex(DSM_GLYPHS['fa-tooth']) . ';</span> '
                . '<b style="font-size:8pt;color:#0f172a;">' . dsMoney($t['cnt']) . '</b> ' . dsEsc($t['treatment_name']) . '</div>'
                . '<div style="font-size:6pt;color:#64748b;">بڕ <bdo dir="ltr">' . dsMoney($t['amt']) . '</bdo></div>'
                . '<div style="font-size:6pt;color:#15803d;font-weight:bold;">وەرگیراو <bdo dir="ltr">' . dsMoney($t['received'] ?? 0) . '</bdo></div>'
                . '</td>';
        }
        for ($k = count($line); $k < 5; $k++) {
            $html .= '<td width="20%"></td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
}

// The patients are not printed here: the page lists them behind a click,
// and the classic PDF prints them in full.
