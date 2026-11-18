<?php
/**
 * پوختەی ڕۆژانە — the «مۆدێرن» PDF (mPDF): the page's modern look on paper.
 * Every figure as a card with its icon and colour (dsm_card_list, the page's
 * own), the work types as chips, the patients' table as on the classic PDF.
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
$dsmGrid = static function (array $rows, int $per = 4) use ($dsmGlyph): string {
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
.mh { background: #0078d4; background-image: linear-gradient(120deg, #0078d4, #0e9f9a); color: #ffffff; border-radius: 4mm; padding: 4mm 5mm; margin-bottom: 3mm; }
.mh table { width: 100%; border-collapse: collapse; }
.mh td { color: #ffffff; vertical-align: middle; }
.mhi { font-size: 20pt; color: #ffffff; }
.mht { font-size: 16pt; font-weight: bold; }
.mhd { font-size: 9.5pt; color: #dff4f7; }
.mhc { font-size: 9pt; color: #dff4f7; text-align: left; }
.ms { background: #eef4fc; border-radius: 2.5mm; padding: 2mm 3.5mm; margin: 4mm 0 1.5mm; font-size: 11pt; font-weight: bold; color: #1e293b; }
.msi { color: #3b82f6; }
.mss { font-size: 8.5pt; font-weight: normal; color: #64748b; }
.mg { border-collapse: separate; border-spacing: 2mm; margin: 0 -2mm; }
.mc { background: #ffffff; border: 0.25mm solid #e2e8f0; text-align: center; vertical-align: top; padding: 2.5mm 1.5mm 2mm; }
.mx { border: 0; }
.mi { font-size: 12pt; padding: 1.4mm 2.2mm; }
.mv { font-size: 13pt; font-weight: bold; color: #0f172a; margin-top: 2mm; }
.mv.neg { color: #dc2626; }
.ml { font-size: 8.5pt; color: #334155; font-weight: bold; margin-top: 0.5mm; }
.mn { font-size: 7pt; color: #64748b; }
.mt { border-collapse: separate; border-spacing: 2mm; margin: 0 -2mm; }
.mt td.chip { border: 0.35mm solid #cbd5e1; background: #ffffff; padding: 2mm 2.5mm; vertical-align: middle; }
.mt .cb { font-size: 12pt; font-weight: bold; color: #0f172a; }
.mt .cn { font-size: 8.5pt; color: #334155; }
.mt .ca { font-size: 7.5pt; color: #64748b; }
.mt .ci { font-size: 13pt; padding: 1.2mm 2mm; }
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
    <td style="width:13mm;"><span class="mhi">' . $dsmGlyph('fa-user-md') . '</span></td>
    <td>
        <div class="mht">پوختەی دکتۆر · ' . dsEsc($docName) . '</div>
        <div class="mhd">' . $dsmGlyph('fa-calendar-alt') . ' بەروار: ' . $period . '</div>
    </td>
    <td style="width:52mm;" class="mhc">'
        . (is_file($logoPath) ? '<img src="' . dsEsc($logoPath) . '" style="width:12mm;"><br>' : '')
        . dsEsc($clinicName) . '</td>
</tr></table></div>';

if ($visitsLinkBroken) {
    $html .= '<div class="warn">ئەم هەژمارە بە تۆماری دکتۆرەوە نەبەستراوە، بۆیە سەردانەکان سفرن.</div>';
}

$html .= $dsmSection('fa-coins', 'پارە') . $dsmGrid($dsMoneyRows)
    . $dsmSection('fa-user-injured', 'سەردان و پشکنین') . $dsmGrid($dsVisitRows)
    . $dsmSection('fa-stopwatch', 'کات') . $dsmGrid($dsTimeRows);
if (!empty($attendance['check_in_time'])) {
    $html .= '<div class="mn" style="margin:0 1mm 1mm;">هاتن / ڕۆیشتن: <bdo dir="ltr">'
        . dsEsc(date('H:i', strtotime($attendance['check_in_time']))) . ' → '
        . (!empty($attendance['check_out_time']) ? dsEsc(date('H:i', strtotime($attendance['check_out_time']))) : '—')
        . '</bdo></div>';
}
$html .= $dsmSection('fa-x-ray', 'ناردن بۆ ئێکسڕەی', 'داهاتەکەی هی سەنتەرە') . $dsmGrid($dsXrayRows);

// ── work types as chips, three to a row ──
$html .= $dsmSection('fa-folder-open', 'جۆری ئیشەکان', dsMoney($treatmentCount) . ' ئیش · ' . dsMoney($treatmentAmount));
if (!$topTreatments) {
    $html .= '<div class="mn" style="text-align:center;padding:3mm;">هیچ ئیشێک تۆمار نەکراوە</div>';
} else {
    $chipColours = ['green', 'blue', 'purple', 'orange', 'teal', 'pink', 'indigo', 'red'];
    $html .= '<table class="mt" width="100%">';
    foreach (array_chunk($topTreatments, 3, true) as $line) {
        $html .= '<tr>';
        foreach ($line as $i => $t) {
            [$c1, $tint] = DSM_COLOURS[$chipColours[$i % count($chipColours)]];
            $html .= '<td class="chip" width="33.33%" style="border-color:' . $c1 . ';">'
                . '<table width="100%" style="border-collapse:collapse;"><tr>'
                . '<td style="width:10mm;vertical-align:middle;"><span class="ci" style="background:' . $tint . ';color:' . $c1 . ';">' . $dsmGlyph('fa-tooth') . '</span></td>'
                . '<td style="vertical-align:middle;"><div class="cb">' . dsMoney($t['cnt']) . '</div>'
                . '<div class="cn">' . dsEsc($t['treatment_name']) . '</div>'
                . '<div class="ca"><bdo dir="ltr">' . dsMoney($t['amt']) . '</bdo></div></td>'
                . '</tr></table></td>';
        }
        for ($k = count($line); $k < 3; $k++) {
            $html .= '<td width="33.33%"></td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
}

// ── the patients, as on the classic PDF ──
$html .= $patientsHtml;
