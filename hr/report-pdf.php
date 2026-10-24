<?php
/**
 * ڕاپۆرتەکانی سەرچاوە مرۆییەکان — PDF (mPDF), for the monthly sheet and one
 * employee's month. Same parameters as hr/reports.php and the same numbers,
 * because both ask HrAttendance.
 *
 * Laid out with tables only: mPDF does not do grid, flex or sticky. Employee
 * names in the monthly sheet are turned with text-rotate, as on the screen.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.reports.view');
require_once __DIR__ . '/../services/InvoicePdfService.php';
require __DIR__ . '/../includes/clinic_branding.php';

$hr = new HrAttendance();
$canPay = hr_can('hr.payroll.view');
$tab = ($_GET['r'] ?? '') === 'employee' ? 'employee' : 'monthly';
$month = hr_month($_GET['month'] ?? null);
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));
$e = 'hr_esc';
$hm = fn($h) => HrAttendance::hm((float)$h);

$css = '<style>
body { font-family: nrt; direction: rtl; font-size: 8pt; color: #172b3a; }
.head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0f766e; margin-bottom: 3mm; }
.head td { vertical-align: middle; padding-bottom: 2mm; }
.clinic { font-size: 11pt; font-weight: bold; color: #0f766e; }
.title { font-size: 14pt; font-weight: bold; }
.grid { border-collapse: collapse; width: 100%; }
.grid th, .grid td { border: 0.6px solid #c8d3dc; padding: 1mm 0.8mm; text-align: center; font-size: 7pt; }
.grid th { background: #e6f2f0; font-weight: bold; }
.grid td.d { text-align: right; font-weight: bold; background: #f4f7f9; white-space: nowrap; }
.w { background: #e8f7ef; color: #065f46; font-weight: bold; }
.z { background: #fff7e0; }
.o { background: #fff1e6; color: #c2410c; }
.a { background: #fdeaea; color: #b91c1c; }
.tot td { background: #eef2f6; font-weight: bold; }
.money td { background: #eaf7ee; }
.final td { background: #e0f7fa; color: #0e7490; font-weight: bold; font-size: 7.5pt; }
.pct { background: #ff9800; color: #fff; }
.tbl { border-collapse: collapse; width: 100%; margin-top: 4mm; }
.tbl th { background: #0f766e; color: #fff; font-size: 8.5pt; padding: 1.5mm 2mm; text-align: right; }
.tbl td { border-bottom: 0.6px solid #dfe6eb; padding: 1.4mm 2mm; font-size: 8.5pt; }
.tbl td.n { text-align: center; direction: ltr; white-space: nowrap; }
.tbl th.n { text-align: center; }
.tbl tr.tf td { background: #eef2f6; font-weight: bold; border-top: 1px solid #9cafbd; }
.kv { border-collapse: collapse; }
.kv td { border: 0.6px solid #c8d3dc; padding: 1.5mm 3mm; font-size: 9pt; }
.kv td.k { background: #e6f2f0; color: #0f766e; font-weight: bold; }
</style>';

$header = function (string $title, string $sub) use ($e, $clinicName, $logoPath) {
    return '<table class="head"><tr>'
        . '<td style="width:16mm;">' . (is_file($logoPath) ? '<img src="' . $e($logoPath) . '" style="width:14mm;">' : '') . '</td>'
        . '<td><div class="clinic">' . $e($clinicName) . '</div><div class="title">' . $e($title) . '</div></td>'
        . '<td style="text-align:left;font-size:10pt;font-weight:bold;">' . $sub . '</td>'
        . '</tr></table>';
};

if ($tab === 'monthly') {
    $type = in_array($_GET['type'] ?? '', ['staff', 'doctor'], true) ? $_GET['type'] : '';
    $data = $hr->period($hr->employees(['type' => $type, 'department_id' => (int)($_GET['department_id'] ?? 0), 'monthly' => true]), $from, $to);
    $pay = [];
    foreach ($data as $id => $p) {
        $pay[$id] = HrAttendance::pay($p['employee'], $p['totals']['net']);
    }

    $html = $css . $header('ڕاپۆرتی مانگانە', '<bdo dir="ltr">' . $e(hr_month_label($month)) . '</bdo>');
    $html .= '<table class="grid"><thead><tr><th>بەروار</th>';
    foreach ($data as $p) {
        $html .= '<th text-rotate="90" style="height:28mm;vertical-align:bottom;">' . $e($p['employee']['full_name']) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach (HrAttendance::dateRange($from, $to) as $date) {
        $wd = (int)date('w', strtotime($date));
        $html .= '<tr><td class="d">' . $e(substr($date, 8)) . ' ' . $e(HrAttendance::WEEKDAYS[$wd]) . '</td>';
        foreach ($data as $p) {
            $d = $p['days'][$date];
            if ($d['day_status'] === 'worked') {
                $html .= '<td class="' . ($d['hours'] > 0 ? 'w' : 'z') . '">' . $e($hm($d['hours'])) . '</td>';
            } elseif ($d['day_status'] === 'holiday') {
                $html .= '<td class="o">پشوو</td>';
            } elseif ($d['day_status'] === 'absent') {
                $html .= '<td class="a">غیاب</td>';
            } else {
                $html .= '<td></td>';
            }
        }
        $html .= '</tr>';
    }
    $rows = [
        ['کۆی گشتی پەنجەمۆر', 'tot', fn($p, $id) => $hm($p['totals']['hours'])],
        ['کۆی گشتی زیادە', 'tot', fn($p, $id) => $hm($p['totals']['add'])],
        ['کۆی گشتی لێبڕین', 'tot', fn($p, $id) => $hm($p['totals']['sub'])],
        ['کۆی گشتی سەعات', 'tot', fn($p, $id) => $hm($p['totals']['net'])],
    ];
    if ($canPay) {
        $rows[] = ['کرێی کاتژمێر', 'tot', fn($p, $id) => $pay[$id]['rate'] > 0 ? hr_money($pay[$id]['rate']) : ''];
        $rows[] = ['کۆی گشتی', 'tot money', fn($p, $id) => hr_money($pay[$id]['base'])];
        $rows[] = ['ڕێژە %', 'tot', fn($p, $id) => $pay[$id]['pct'] > 0 ? (float)$pay[$id]['pct'] . '%' : ''];
        $rows[] = ['کۆ + ڕێژە', 'tot money', fn($p, $id) => hr_money($pay[$id]['total'])];
        $rows[] = ['دوای ڕێکخستنی دینار', 'final', fn($p, $id) => hr_money($pay[$id]['rounded'])];
    }
    foreach ($rows as [$label, $cls, $fn]) {
        $html .= '<tr class="' . $cls . '"><td class="d">' . $e($label) . '</td>';
        foreach ($data as $id => $p) {
            $html .= '<td style="direction:ltr;">' . $e($fn($p, $id)) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    if ($canPay && $data) {
        $html .= '<pagebreak />' . $header('پوختەی مووچە', '<bdo dir="ltr">' . $e(hr_month_label($month)) . '</bdo>');
        $html .= '<table class="tbl"><thead><tr><th>#</th><th>کارمەند</th><th class="n">کاتژمێر</th><th class="n">کرێی کاتژمێر</th><th class="n">کۆ</th><th class="n">ڕێژە</th><th class="n">کۆ + ڕێژە</th><th class="n">دوای ڕێکخستنی دینار</th><th style="width:30mm;">واژۆ</th></tr></thead><tbody>';
        $n = 0;
        $sum = ['base' => 0, 'total' => 0, 'rounded' => 0];
        foreach ($data as $id => $p) {
            $pp = $pay[$id];
            $sum['base'] += $pp['base'];
            $sum['total'] += $pp['total'];
            $sum['rounded'] += $pp['rounded'];
            $html .= '<tr><td>' . (++$n) . '</td><td>' . $e($p['employee']['full_name']) . '</td>'
                . '<td class="n">' . $e(HrAttendance::hm($p['totals']['net'], false)) . '</td>'
                . '<td class="n">' . ($pp['fixed'] ? 'جێگیر' : hr_money($pp['rate'])) . '</td>'
                . '<td class="n">' . hr_money($pp['base']) . '</td>'
                . '<td class="n">' . ($pp['pct'] > 0 ? (float)$pp['pct'] . '%' : '') . '</td>'
                . '<td class="n">' . hr_money($pp['total']) . '</td>'
                . '<td class="n"><b>' . hr_money($pp['rounded']) . '</b></td><td></td></tr>';
        }
        $html .= '<tr class="tf"><td></td><td>کۆی گشتی</td><td></td><td></td><td class="n">' . hr_money($sum['base']) . '</td><td></td>'
            . '<td class="n">' . hr_money($sum['total']) . '</td><td class="n">' . hr_money($sum['rounded']) . '</td><td></td></tr>';
        $html .= '</tbody></table>';
    }
    $format = 'A3';
    $title = 'ڕاپۆرتی مانگانە ' . $month;
    $file = 'hr-monthly-' . $month . '.pdf';
} else {
    $emp = $hr->employees(['ids' => [(int)($_GET['employee_id'] ?? 0)], 'status' => ''])[0] ?? ($hr->employees()[0] ?? null);
    if (!$emp) {
        http_response_code(404);
        exit('کارمەند نەدۆزرایەوە');
    }
    $p = $hr->period([$emp], $from, $to)[(int)$emp['id']];
    $t = $p['totals'];
    $pp = HrAttendance::pay($emp, $t['net']);

    $html = $css . $header('ڕاپۆرتی کارمەند', '<bdo dir="ltr">' . $e(hr_month_label($month)) . '</bdo>');
    $html .= '<table class="kv" width="100%"><tr>'
        . '<td class="k">کارمەند</td><td>' . $e($emp['full_name']) . '</td><td class="k">PIN</td><td>' . $e(hr_pin($emp)) . '</td>'
        . '<td class="k">کاتژمێری پەنجەمۆر</td><td style="direction:ltr;">' . $e(HrAttendance::hm($t['hours'], false)) . '</td>'
        . '<td class="k">زیادە / لێبڕین</td><td style="direction:ltr;">+' . $e(HrAttendance::hm($t['add'], false)) . ' / -' . $e(HrAttendance::hm($t['sub'], false)) . '</td>'
        . '<td class="k">کۆی کاتژمێر</td><td style="direction:ltr;"><b>' . $e(HrAttendance::hm($t['net'], false)) . '</b></td>'
        . ($canPay ? '<td class="k">مووچە</td><td style="direction:ltr;"><b>' . hr_money($pp['rounded']) . '</b></td>' : '')
        . '</tr></table>';
    $html .= '<table class="tbl"><thead><tr><th class="n">ڕێکەوت</th><th>ڕۆژ</th><th>دۆخ</th><th class="n">هاتن - ڕۆیشتن</th><th class="n">پەنجەمۆر</th><th class="n">زیادە</th><th class="n">لێبڕین</th><th class="n">کۆ</th></tr></thead><tbody>';
    $labels = ['worked' => 'هاتووە', 'holiday' => 'پشوو', 'absent' => 'غیاب', 'future' => ''];
    foreach ($p['days'] as $d) {
        $pairs = [];
        foreach ($d['pairs'] as $pr) {
            $pairs[] = ($pr['in'] ? HrAttendance::clock($pr['in']['time']) : '?') . ' - ' . ($pr['out'] ? HrAttendance::clock($pr['out']['time']) : '?');
        }
        $html .= '<tr><td class="n">' . $e($d['date']) . '</td><td>' . $e(HrAttendance::WEEKDAYS[$d['weekday']]) . '</td>'
            . '<td>' . $e($labels[$d['day_status']]) . '</td>'
            . '<td class="n">' . $e(implode('   ', $pairs)) . '</td>'
            . '<td class="n">' . $e($hm($d['hours'])) . '</td>'
            . '<td class="n">' . ($d['add'] > 0 ? '+' . $e($hm($d['add'])) : '') . '</td>'
            . '<td class="n">' . ($d['sub'] > 0 ? '-' . $e($hm($d['sub'])) : '') . '</td>'
            . '<td class="n"><b>' . $e($hm($d['net'])) . '</b></td></tr>';
    }
    $html .= '<tr class="tf"><td colspan="4">کۆ</td><td class="n">' . $e(HrAttendance::hm($t['hours'], false)) . '</td>'
        . '<td class="n">+' . $e(HrAttendance::hm($t['add'], false)) . '</td><td class="n">-' . $e(HrAttendance::hm($t['sub'], false)) . '</td>'
        . '<td class="n">' . $e(HrAttendance::hm($t['net'], false)) . '</td></tr></tbody></table>';
    $format = 'A4';
    $title = 'ڕاپۆرتی کارمەند — ' . $emp['full_name'] . ' — ' . $month;
    $file = 'hr-employee-' . (int)$emp['id'] . '-' . $month . '.pdf';
}

$pdf = new InvoicePdfService([
    'orientation'      => $tab === 'monthly' ? 'L' : 'P',
    'format'           => $format,
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 8,
    'margin_bottom_mm' => 14,
    'margin_left_mm'   => 8,
    'margin_right_mm'  => 8,
]);
$viewer = $hrAuth->getCurrentUser();
$pdf->setTitle($title);
$pdf->getMpdf()->SetHTMLFooter(
    '<table width="100%" style="font-family:nrt;font-size:7pt;color:#7a8b98;border-top:1px solid #d5dde3;">'
    . '<tr><td style="text-align:right;">چاپکراوە لەلایەن ' . $e($viewer['full_name'] ?? '') . ' — '
    . '<span style="direction:ltr;">' . date('Y-m-d H:i') . '</span></td>'
    . '<td style="text-align:left;direction:ltr;">{PAGENO} / {nbpg}</td></tr></table>'
);
$pdf->setContent($html);
$pdf->output($file, 'I');
