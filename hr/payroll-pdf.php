<?php
/**
 * مووچە — PDF (mPDF):
 *   ?month=Y-m       the month's payroll list, with a column to sign
 *   ?receipt=N       one salary receipt (وەسڵی مووچەدان) from
 *                    hr_salary_receipts, with the person's electronic
 *                    signature when there is one
 * Same figures as hr/payroll.php and hr/salary-receipt.php
 * (classes/HrPayroll.php).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/HrPayroll.php';
hr_require('hr.payroll.view');
require_once __DIR__ . '/../services/InvoicePdfService.php';
require __DIR__ . '/../includes/clinic_branding.php';

$payroll = new HrPayroll();
$e = 'hr_esc';

$css = '<style>
body { font-family: nrt; direction: rtl; font-size: 9pt; color: #172b3a; }
.head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0f766e; margin-bottom: 4mm; }
.head td { vertical-align: middle; padding-bottom: 2mm; }
.clinic { font-size: 11pt; font-weight: bold; color: #0f766e; }
.title { font-size: 15pt; font-weight: bold; }
.tbl { border-collapse: collapse; width: 100%; }
.tbl th { background: #0f766e; color: #fff; font-size: 8.5pt; padding: 1.6mm 1.5mm; text-align: right; }
.tbl th.n { text-align: center; }
.tbl td { border-bottom: 0.6px solid #dfe6eb; padding: 2.2mm 1.5mm; font-size: 9pt; }
.tbl td.n { text-align: center; direction: ltr; white-space: nowrap; }
.tbl td.sign { width: 30mm; border-bottom: 0.6px solid #9cafbd; }
.tbl tr.tf td { background: #eef2f6; font-weight: bold; border-top: 1px solid #9cafbd; }
.kv { border-collapse: collapse; width: 100%; margin-bottom: 3mm; }
.kv td { border: 0.6px solid #c8d3dc; padding: 1.8mm 3mm; font-size: 9.5pt; }
.kv td.k { background: #e6f2f0; color: #0f766e; font-weight: bold; width: 38%; }
.kv td.v { text-align: left; direction: ltr; }
.big td { font-size: 14pt; font-weight: bold; background: #0f766e; color: #fff; border-color: #0f766e; }
.meta { font-size: 8.5pt; color: #475569; margin-bottom: 3mm; }
.sigbox { border: 0.8px solid #c8d3dc; height: 26mm; text-align: center; vertical-align: middle; }
.sigcap { text-align: center; font-size: 9pt; font-weight: bold; padding-top: 1.5mm; }
.stamp { color: #dc2626; border: 1.2mm solid #dc2626; font-size: 20pt; font-weight: bold; padding: 2mm 6mm; text-align: center; }
.muted { color: #7a8b98; font-size: 8pt; }
</style>';

$header = function (string $title, string $right) use ($e, $clinicName, $logoPath) {
    return '<table class="head"><tr>'
        . '<td style="width:16mm;">' . (is_file($logoPath) ? '<img src="' . $e($logoPath) . '" style="width:14mm;">' : '') . '</td>'
        . '<td><div class="clinic">' . $e($clinicName) . '</div><div class="title">' . $e($title) . '</div></td>'
        . '<td style="text-align:left;font-size:10pt;font-weight:bold;">' . $right . '</td>'
        . '</tr></table>';
};

$receiptId = (int)($_GET['receipt'] ?? 0);
if ($receiptId > 0) {
    $r = $payroll->receipt($receiptId);
    if (!$r) {
        http_response_code(404);
        exit('وەسڵەکە نەدۆزرایەوە');
    }
    $p = HrAttendance::pay(['hourly_rate' => $r['hourly_rate'], 'basic_salary' => $r['basic_salary'], 'salary_percentage' => $r['salary_percentage']], (float)$r['net_hours']);
    $kv = fn($k, $v) => '<tr><td class="k">' . $e($k) . '</td><td class="v">' . $v . '</td></tr>';
    $no = HrPayroll::receiptNo($r['id']);

    $html = $css . $header('وەسڵی مووچەدان', 'ژمارە <bdo dir="ltr">' . $e($no) . '</bdo><br><span style="font-size:8.5pt;font-weight:normal;"><bdo dir="ltr">' . $e($r['paid_date']) . '</bdo></span>');
    if ($r['status'] === 'cancelled') {
        $html .= '<table width="100%" style="margin-bottom:3mm;"><tr><td class="stamp">هەڵوەشاوەتەوە</td></tr></table>';
    }
    $html .= '<table class="kv">'
        . '<tr><td class="k">وەرگر</td><td><b>' . $e($r['employee_name']) . '</b></td></tr>'
        . '<tr><td class="k">PIN · جۆر · بەش</td><td>' . $e($r['employee_pin']) . ' · ' . ($r['employee_type'] === 'doctor' ? 'دکتۆر' : 'کارمەند')
        . ($r['department_name'] ? ' · ' . $e($r['department_name']) : '') . '</td></tr>'
        . '<tr><td class="k">مووچەی مانگی</td><td style="direction:ltr;text-align:right;">' . $e(HrPayroll::monthLabel($r['pay_month'])) . '</td></tr>'
        . '</table>';
    $html .= '<table class="kv">' . $kv('کاتژمێری کار', $e(HrAttendance::hm((float)$r['net_hours'], false)));
    $html .= $p['fixed'] ? $kv('مووچەی جێگیر', hr_money($p['basic']))
        : $kv('کرێی کاتژمێر × کاتژمێر', hr_money($p['rate']) . ' × ' . $e(HrAttendance::hm((float)$r['net_hours'], false)) . ' = ' . hr_money($p['base']));
    if ($p['pct'] > 0) {
        $html .= $kv('ڕێژە ' . rtrim(rtrim(number_format($p['pct'], 2), '0'), '.') . '%', '+' . hr_money($p['pct_amount']));
    }
    $html .= $kv('مووچەی حیسابکراو', '<b>' . hr_money($r['calculated']) . '</b>');
    if ((float)$r['bonus'] > 0) {
        $html .= $kv('زیادە', '+' . hr_money($r['bonus']));
    }
    if ((float)$r['deduction'] > 0) {
        $html .= $kv('کەمکردنەوە', '−' . hr_money($r['deduction']));
    }
    $html .= '</table><table class="kv big"><tr><td class="k" style="background:#0f766e;color:#fff;">بڕی دراو</td><td class="v">' . hr_money($r['amount']) . ' IQD</td></tr></table>';
    if ($r['note']) {
        $html .= '<div class="meta"><b>تێبینی:</b> ' . $e($r['note']) . '</div>';
    }
    $html .= '<div class="meta">شێواز: <b>' . $e(HrPayroll::METHODS[$r['payment_method']] ?? '') . '</b> · پێدەر: <b>' . $e($r['created_by_name'] ?? '') . '</b> · '
        . ((int)$r['as_expense'] ? 'وەک خەرجی تۆمار کراوە' : 'وەک خەرجی تۆمار نەکراوە') . ' · '
        . ((int)($r['from_cashbox'] ?? 0) ? 'لە قاسەی لێزان دەرهێنراوە' : 'لە قاسەی لێزان نەدەرهێنراوە') . '</div>';
    $sig = $r['signature']
        ? '<img src="' . $e($r['signature']) . '" style="height:22mm;">'
        : '<span class="muted">واژۆ نەکراوە</span>';
    $html .= '<table width="100%" style="margin-top:4mm;border-collapse:separate;border-spacing:3mm 0;"><tr>'
        . '<td class="sigbox" style="width:50%;">' . $sig . '</td><td class="sigbox" style="width:50%;"></td></tr>'
        . '<tr><td class="sigcap">واژۆی وەرگر' . ($r['signed_at'] ? '<br><span class="muted"><bdo dir="ltr">' . $e($r['signed_at']) . '</bdo></span>' : '') . '</td>'
        . '<td class="sigcap">واژۆی بەڕێوەبەر</td></tr></table>';
    $format = 'A5';
    $title = 'وەسڵی مووچەدان ' . $no . ' — ' . $r['employee_name'];
    $file = 'salary-receipt-' . $no . '.pdf';
} else {
    $month = hr_month($_GET['month'] ?? null);
    $rows = $payroll->month($month);
    $statusText = ['none' => 'پەسەند نەکراوە', 'approved' => 'پەسەندکراو', 'paid' => 'دراوە'];
    $html = $css . $header('مووچەی مانگ', '<span style="direction:ltr;">' . $e(HrPayroll::monthLabel($month)) . '</span>');
    $html .= '<table class="tbl"><thead><tr><th class="n">#</th><th>ناو</th><th class="n">کاتژمێر</th><th class="n">حیسابکراو</th>'
        . '<th class="n">زیادە</th><th class="n">کەمکردنەوە</th><th class="n">کۆتایی</th><th>دۆخ</th><th class="n">وەسڵ</th><th>واژۆ</th></tr></thead><tbody>';
    $n = 0;
    $tot = ['calc' => 0.0, 'bonus' => 0.0, 'deduction' => 0.0, 'amount' => 0.0];
    foreach ($rows as $r) {
        $s = $r['saved'];
        $paid = $r['status'] === 'paid';
        $f = [
            'hours'     => $paid ? (float)$s['net_hours'] : $r['hours'],
            'calc'      => $paid ? (float)$s['calculated'] : (float)$r['pay']['rounded'],
            'bonus'     => $s ? (float)$s['bonus'] : 0.0,
            'deduction' => $s ? (float)$s['deduction'] : 0.0,
        ];
        $f['amount'] = $f['calc'] + $f['bonus'] - $f['deduction'];
        if ($f['amount'] <= 0 && !$s) {
            continue;
        }
        foreach ($tot as $k => $v) {
            $tot[$k] += $f[$k];
        }
        $html .= '<tr><td class="n">' . ++$n . '</td><td>' . $e($r['employee']['full_name']) . '</td>'
            . '<td class="n">' . $e(HrAttendance::hm($f['hours'], false)) . '</td>'
            . '<td class="n">' . hr_money($f['calc']) . '</td>'
            . '<td class="n">' . ($f['bonus'] > 0 ? '+' . hr_money($f['bonus']) : '') . '</td>'
            . '<td class="n">' . ($f['deduction'] > 0 ? '−' . hr_money($f['deduction']) : '') . '</td>'
            . '<td class="n"><b>' . hr_money($f['amount']) . '</b></td>'
            . '<td>' . $e($statusText[$r['status']]) . '</td>'
            . '<td class="n">' . (!empty($s['receipt_id']) ? $e(HrPayroll::receiptNo($s['receipt_id'])) : '') . '</td>'
            . '<td class="sign"></td></tr>';
    }
    $html .= '<tr class="tf"><td colspan="3">کۆ</td><td class="n">' . hr_money($tot['calc']) . '</td>'
        . '<td class="n">' . hr_money($tot['bonus']) . '</td><td class="n">' . hr_money($tot['deduction']) . '</td>'
        . '<td class="n">' . hr_money($tot['amount']) . '</td><td colspan="3"></td></tr></tbody></table>';
    $format = 'A4';
    $title = 'مووچەی مانگ — ' . $month;
    $file = 'hr-payroll-' . $month . '.pdf';
}

$pdf = new InvoicePdfService([
    'orientation'      => 'P',
    'format'           => $format,
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 8,
    'margin_bottom_mm' => 14,
    'margin_left_mm'   => 10,
    'margin_right_mm'  => 10,
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
