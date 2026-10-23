<?php
/**
 * Daily Patient Report — PDF
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

$db   = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$isAccountant = $auth->isAccountant();
$isDoctor = $auth->isDoctor();
$hasFullAccess = $isAdmin || $isReceptionist || $isAccountant;

$today = date('Y-m-d');
$mode     = $_GET['mode']      ?? 'today';
$fromIn   = $_GET['from']      ?? '';
$toIn     = $_GET['to']        ?? '';
$doctorIn = $_GET['doctor_id'] ?? '';
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = 'ئەمڕۆ';
switch ($mode) {
    case 'today':     $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; break;
    case 'yesterday': $rangeFrom = $rangeTo = date('Y-m-d', strtotime('-1 day')); $rangeLabel = 'دوێنێ'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'month':     $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel = 'ئەم مانگە'; break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        }
        break;
}
$doctorFilter = is_numeric($doctorIn) ? intval($doctorIn) : null;
if ($isDoctor && !$hasFullAccess) { $doctorFilter = $currentUser['id']; }

// Sections to hide (from the page's collapsed state)
$hideIn = $_GET['hide'] ?? '';
$validSections = ['patients', 'folders', 'cases', 'treatments', 'payments'];
$hidden = array_values(array_intersect(
    $validSections,
    array_map('trim', explode(',', (string)$hideIn))
));
$show = fn($key) => !in_array($key, $hidden, true);

$start = $rangeFrom . ' 00:00:00'; $end = $rangeTo . ' 23:59:59';
$baseP = ['start' => $start, 'end' => $end];
$doctorWhere = '';
if ($doctorFilter) { $doctorWhere = " AND pf.doctor_id = :doc"; $baseP['doc'] = $doctorFilter; }

// Queries (identical to page)
if ($doctorFilter) {
    $newPatients = $db->fetchAll(
        "SELECT DISTINCT p.id, p.patient_code, p.full_name, p.phone, p.gender, p.created_at
         FROM patients p INNER JOIN patient_folders pf ON p.id = pf.patient_id
         WHERE p.created_at BETWEEN :start AND :end AND pf.doctor_id = :doc
         ORDER BY p.created_at DESC", $baseP);
} else {
    $newPatients = $db->fetchAll(
        "SELECT id, patient_code, full_name, phone, gender, created_at
         FROM patients WHERE created_at BETWEEN :start AND :end ORDER BY created_at DESC",
        ['start' => $start, 'end' => $end]);
}

$newFolders = $db->fetchAll(
    "SELECT pf.id, pf.folder_name, pf.total_amount, pf.paid_amount, pf.discount_amount, pf.created_at,
            p.full_name AS patient_name, p.patient_code, u.full_name AS doctor_name
     FROM patient_folders pf
     INNER JOIN patients p ON pf.patient_id = p.id
     LEFT JOIN users u    ON pf.doctor_id   = u.id
     WHERE pf.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY pf.created_at DESC", $baseP);

$newCases = $db->fetchAll(
    "SELECT c.id, c.case_number, c.case_name, c.total_amount, c.paid_amount, c.discount_amount, c.created_at,
            p.full_name AS patient_name, p.patient_code, u.full_name AS doctor_name
     FROM cases c
     INNER JOIN patient_folders pf ON c.folder_id = pf.id
     INNER JOIN patients p         ON pf.patient_id = p.id
     LEFT JOIN users u             ON pf.doctor_id  = u.id
     WHERE c.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY c.created_at DESC", $baseP);

$treatments = $db->fetchAll(
    "SELECT ct.id, ct.treatment_name, ct.tooth_number, ct.quantity, ct.final_price, ct.status, ct.created_at,
            p.full_name AS patient_name, p.patient_code, u.full_name AS doctor_name, c.case_number
     FROM case_treatments ct
     INNER JOIN cases c              ON ct.case_id    = c.id
     INNER JOIN patient_folders pf   ON c.folder_id   = pf.id
     INNER JOIN patients p           ON pf.patient_id = p.id
     LEFT JOIN users u               ON pf.doctor_id  = u.id
     WHERE ct.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY ct.created_at DESC", $baseP);

$payments = $db->fetchAll(
    "SELECT pay.id, pay.amount, pay.payment_method, pay.payment_type, pay.status, pay.created_at,
            c.case_number, p.full_name AS patient_name, p.patient_code, u.full_name AS doctor_name
     FROM payments pay
     INNER JOIN cases c              ON pay.case_id   = c.id
     INNER JOIN patient_folders pf   ON c.folder_id   = pf.id
     INNER JOIN patients p           ON pf.patient_id = p.id
     LEFT JOIN users u               ON pf.doctor_id  = u.id
     WHERE pay.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY pay.created_at DESC", $baseP);

// Totals
$sumFolders = ['total'=>0,'paid'=>0,'disc'=>0];
foreach ($newFolders as $f) { $sumFolders['total'] += (float)$f['total_amount']; $sumFolders['paid'] += (float)$f['paid_amount']; $sumFolders['disc'] += (float)$f['discount_amount']; }
$sumCases = ['total'=>0,'paid'=>0,'disc'=>0];
foreach ($newCases as $c) { $sumCases['total'] += (float)$c['total_amount']; $sumCases['paid'] += (float)$c['paid_amount']; $sumCases['disc'] += (float)$c['discount_amount']; }
$sumTreatments = ['final'=>0];
foreach ($treatments as $t) $sumTreatments['final'] += (float)$t['final_price'];
$sumPayments = ['total'=>0,'approved'=>0];
foreach ($payments as $pay) {
    $sumPayments['total'] += (float)$pay['amount'];
    if (in_array($pay['status'], ['approved','completed'], true)) $sumPayments['approved'] += (float)$pay['amount'];
}
$remaining = $sumFolders['total'] - $sumFolders['paid'] - $sumFolders['disc'];

$doctorName = '';
if ($doctorFilter) { $dd = $db->fetch("SELECT full_name FROM users WHERE id=?", [$doctorFilter]); $doctorName = $dd['full_name'] ?? ''; }

$reportGenDate = date('Y/m/d'); $reportGenTime = date('H:i');
$currency = '';

$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 3px; font-size: 15px; }
    h3 { color: #0f172a; margin: 8px 0 4px; font-size: 12px; border-bottom: 2px solid #16a34a; padding-bottom: 3px; }
    h3 .sum { float: left; font-size: 9px; color:#64748b; font-weight: 400; }
    .meta { text-align: center; font-size: 10px; color: #555; margin-bottom: 8px; }
    .stats { margin: 0 auto 8px; border-collapse: collapse; width: 100%; }
    .stats td { border: 1px solid #ddd; text-align: center; padding: 4px 5px; }
    .stats .v { font-size: 12px; font-weight: bold; }
    .stats .l { font-size: 8px; color: #666; margin-top: 2px; }
    table.t { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 5px; }
    table.t th { background: #eef2f7; padding: 4px 3px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; font-size: 9px; }
    table.t td { padding: 3px; border: 1px solid #e5e7eb; text-align: center; vertical-align: middle; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .amt-green { color: #15803d; font-weight: bold; }
    .amt-red   { color: #c62828; font-weight: bold; }
    .amt-orange{ color: #ea580c; font-weight: bold; }
    .totals-row td { background: #eef2f7 !important; font-weight: bold; }
    .empty { text-align: center; color: #999; font-size: 9px; font-style: italic; padding: 6px; }
    .footer-note { text-align: center; font-size: 8px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px; }
</style>

<h2>ڕاپۆرتی ڕۆژانەی نەخۆشەکان — '. htmlspecialchars($rangeLabel) .'</h2>
<div class="meta">
    ماوە: '. htmlspecialchars($rangeFrom) .' بۆ '. htmlspecialchars($rangeTo) .'
    '. ($doctorName ? ' &nbsp;|&nbsp; دکتۆر: د. ' . htmlspecialchars($doctorName) : '') .'
    &nbsp;|&nbsp; بەرواری چاپ: '. $reportGenDate .' '. $reportGenTime .'
    &nbsp;|&nbsp; ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="stats">
    <tr>
        <td><div class="v">'. count($newPatients) .'</div><div class="l">نەخۆشی نوێ</div></td>
        <td><div class="v">'. count($newFolders) .'</div><div class="l">فایلی نوێ · '. number_format($sumFolders['total']) .'</div></td>
        <td><div class="v">'. count($newCases) .'</div><div class="l">کەیسی نوێ · '. number_format($sumCases['total']) .'</div></td>
        <td><div class="v">'. count($treatments) .'</div><div class="l">چارەسەر · '. number_format($sumTreatments['final']) .'</div></td>
        <td><div class="v amt-green">'. number_format($sumPayments['approved']) .' '. $currency .'</div><div class="l">وەرگیراو</div></td>
        <td><div class="v amt-red">'. number_format($remaining) .' '. $currency .'</div><div class="l">ماوە</div></td>
    </tr>
</table>';

// New patients
if ($show('patients')) {
$html .= '<h3>نەخۆشی نوێ ('. count($newPatients) .')</h3>';
if (empty($newPatients)) {
    $html .= '<div class="empty">هیچ نەخۆشێکی نوێ تۆمار نەکراوە</div>';
} else {
    $html .= '<table class="t"><thead><tr>
        <th style="width:3%">#</th>
        <th style="width:10%">کۆد</th>
        <th style="width:35%">ناو</th>
        <th style="width:10%">ڕەگەز</th>
        <th style="width:20%">مۆبایل</th>
        <th style="width:22%">کاتی تۆمارکردن</th>
    </tr></thead><tbody>';
    foreach ($newPatients as $i => $p) {
        $gender = $p['gender'] === 'male' ? 'نێر' : ($p['gender'] === 'female' ? 'مێ' : '—');
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="direction:ltr;font-size:9px;">'. htmlspecialchars($p['patient_code']) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($p['full_name']) .'</strong></td>
            <td>'. $gender .'</td>
            <td style="direction:ltr;font-family:nrt;">'. htmlspecialchars($p['phone'] ?? '—') .'</td>
            <td>'. date('H:i', strtotime($p['created_at'])) .'</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}
} // end patients section

// New folders
if ($show('folders')) {
$html .= '<h3>فایلی نوێ ('. count($newFolders) .') <span class="sum">کۆی: '. number_format($sumFolders['total']) .' · وەرگیراو: '. number_format($sumFolders['paid']) .' · داشکاندن: '. number_format($sumFolders['disc']) .' · ماوە: '. number_format($sumFolders['total']-$sumFolders['paid']-$sumFolders['disc']) .'</span></h3>';
if (empty($newFolders)) {
    $html .= '<div class="empty">هیچ فایلێکی نوێ دروست نەکراوە</div>';
} else {
    $html .= '<table class="t"><thead><tr>
        <th style="width:3%">#</th>
        <th style="width:20%">نەخۆش</th>
        <th style="width:17%">ناوی فایل</th>
        <th style="width:15%">دکتۆر</th>
        <th style="width:10%">کۆی گشتی</th>
        <th style="width:10%">داشکاندن</th>
        <th style="width:10%">وەرگیراو</th>
        <th style="width:10%">ماوە</th>
        <th style="width:5%">کات</th>
    </tr></thead><tbody>';
    foreach ($newFolders as $i => $f) {
        $rem = (float)$f['total_amount'] - (float)$f['paid_amount'] - (float)$f['discount_amount'];
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($f['patient_name']) .'</strong></td>
            <td>'. htmlspecialchars($f['folder_name']) .'</td>
            <td>'. ($f['doctor_name'] ? 'د. '.htmlspecialchars($f['doctor_name']) : '—') .'</td>
            <td>'. number_format($f['total_amount']) .'</td>
            <td class="amt-orange">'. number_format($f['discount_amount']) .'</td>
            <td class="amt-green">'. number_format($f['paid_amount']) .'</td>
            <td class="amt-red">'. number_format($rem) .'</td>
            <td>'. date('H:i', strtotime($f['created_at'])) .'</td>
        </tr>';
    }
    $html .= '<tr class="totals-row">
        <td colspan="4" style="text-align:start; padding-inline-start:8px;">کۆی گشتی</td>
        <td>'. number_format($sumFolders['total']) .'</td>
        <td class="amt-orange">'. number_format($sumFolders['disc']) .'</td>
        <td class="amt-green">'. number_format($sumFolders['paid']) .'</td>
        <td class="amt-red">'. number_format($sumFolders['total']-$sumFolders['paid']-$sumFolders['disc']) .'</td>
        <td></td>
    </tr>';
    $html .= '</tbody></table>';
}
} // end folders section

// New cases
if ($show('cases')) {
$html .= '<h3>کەیسی نوێ ('. count($newCases) .') <span class="sum">کۆی: '. number_format($sumCases['total']) .' · وەرگیراو: '. number_format($sumCases['paid']) .' · داشکاندن: '. number_format($sumCases['disc']) .'</span></h3>';
if (empty($newCases)) {
    $html .= '<div class="empty">هیچ کەیسێکی نوێ دروست نەکراوە</div>';
} else {
    $html .= '<table class="t"><thead><tr>
        <th style="width:3%">#</th>
        <th style="width:20%">نەخۆش</th>
        <th style="width:10%">ژ. کەیس</th>
        <th style="width:17%">ناوی کەیس</th>
        <th style="width:15%">دکتۆر</th>
        <th style="width:10%">کۆی گشتی</th>
        <th style="width:9%">داشکاندن</th>
        <th style="width:8%">وەرگیراو</th>
        <th style="width:8%">ماوە</th>
    </tr></thead><tbody>';
    foreach ($newCases as $i => $c) {
        $rem = (float)$c['total_amount'] - (float)$c['paid_amount'] - (float)$c['discount_amount'];
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($c['patient_name']) .'</strong></td>
            <td>'. htmlspecialchars($c['case_number'] ?? '—') .'</td>
            <td>'. htmlspecialchars($c['case_name'] ?? '—') .'</td>
            <td>'. ($c['doctor_name'] ? 'د. '.htmlspecialchars($c['doctor_name']) : '—') .'</td>
            <td>'. number_format($c['total_amount']) .'</td>
            <td class="amt-orange">'. number_format($c['discount_amount']) .'</td>
            <td class="amt-green">'. number_format($c['paid_amount']) .'</td>
            <td class="amt-red">'. number_format($rem) .'</td>
        </tr>';
    }
    $html .= '<tr class="totals-row">
        <td colspan="5" style="text-align:start; padding-inline-start:8px;">کۆی گشتی</td>
        <td>'. number_format($sumCases['total']) .'</td>
        <td class="amt-orange">'. number_format($sumCases['disc']) .'</td>
        <td class="amt-green">'. number_format($sumCases['paid']) .'</td>
        <td class="amt-red">'. number_format($sumCases['total']-$sumCases['paid']-$sumCases['disc']) .'</td>
    </tr>';
    $html .= '</tbody></table>';
}
} // end cases section

// Treatments
if ($show('treatments')) {
$html .= '<h3>چارەسەرەکان ('. count($treatments) .') <span class="sum">کۆی نرخ: '. number_format($sumTreatments['final']) .'</span></h3>';
if (empty($treatments)) {
    $html .= '<div class="empty">هیچ چارەسەرێک تۆمار نەکراوە</div>';
} else {
    $stLabels = ['pending'=>'چاوەڕوان','approved'=>'پەسەند','completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە'];
    $html .= '<table class="t"><thead><tr>
        <th style="width:3%">#</th>
        <th style="width:20%">نەخۆش</th>
        <th style="width:15%">دکتۆر</th>
        <th style="width:8%">کەیس</th>
        <th style="width:25%">چارەسەر</th>
        <th style="width:5%">ددان</th>
        <th style="width:5%">ژ.</th>
        <th style="width:10%">نرخ</th>
        <th style="width:9%">دۆخ</th>
    </tr></thead><tbody>';
    foreach ($treatments as $i => $t) {
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($t['patient_name']) .'</strong></td>
            <td>'. ($t['doctor_name'] ? 'د. '.htmlspecialchars($t['doctor_name']) : '—') .'</td>
            <td>'. htmlspecialchars($t['case_number'] ?? '—') .'</td>
            <td style="text-align:start;">'. htmlspecialchars($t['treatment_name']) .'</td>
            <td>'. ($t['tooth_number'] ?: '—') .'</td>
            <td>'. (int)$t['quantity'] .'</td>
            <td class="amt-green">'. number_format($t['final_price']) .'</td>
            <td>'. ($stLabels[$t['status']] ?? $t['status']) .'</td>
        </tr>';
    }
    $html .= '<tr class="totals-row">
        <td colspan="7" style="text-align:start; padding-inline-start:8px;">کۆی گشتی</td>
        <td class="amt-green">'. number_format($sumTreatments['final']) .'</td>
        <td></td>
    </tr>';
    $html .= '</tbody></table>';
}
} // end treatments section

// Payments
if ($show('payments')) {
$html .= '<h3>پارەدانەکان ('. count($payments) .') <span class="sum">وەرگیراو: '. number_format($sumPayments['approved']) .'</span></h3>';
if (empty($payments)) {
    $html .= '<div class="empty">هیچ پارەیەک وەرنەگیراوە</div>';
} else {
    $pmLabels = ['cash'=>'نەقد','card'=>'کارت','transfer'=>'گواستنەوە','other'=>'تر','advance'=>'لە ئامانەت'];
    $ptLabels = ['cash'=>'نەقد','credit'=>'قەرز','installment'=>'قیست'];
    $psLabels = ['pending'=>'چاوەڕوان','approved'=>'پەسەند','completed'=>'تەواوبوو','rejected'=>'ڕەتکراو','refunded'=>'گەڕاوە'];
    $html .= '<table class="t"><thead><tr>
        <th style="width:3%">#</th>
        <th style="width:22%">نەخۆش</th>
        <th style="width:15%">دکتۆر</th>
        <th style="width:10%">کەیس</th>
        <th style="width:10%">جۆر</th>
        <th style="width:12%">ڕێگا</th>
        <th style="width:10%">دۆخ</th>
        <th style="width:10%">بڕ</th>
        <th style="width:8%">کات</th>
    </tr></thead><tbody>';
    foreach ($payments as $i => $pay) {
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($pay['patient_name']) .'</strong></td>
            <td>'. ($pay['doctor_name'] ? 'د. '.htmlspecialchars($pay['doctor_name']) : '—') .'</td>
            <td>'. htmlspecialchars($pay['case_number'] ?? '—') .'</td>
            <td>'. ($ptLabels[$pay['payment_type']] ?? $pay['payment_type']) .'</td>
            <td>'. ($pmLabels[$pay['payment_method']] ?? $pay['payment_method']) .'</td>
            <td>'. ($psLabels[$pay['status']] ?? $pay['status']) .'</td>
            <td class="amt-green">'. number_format($pay['amount']) .'</td>
            <td>'. date('H:i', strtotime($pay['created_at'])) .'</td>
        </tr>';
    }
    $html .= '<tr class="totals-row">
        <td colspan="7" style="text-align:start; padding-inline-start:8px;">کۆی گشتی (پەسەندکراو)</td>
        <td class="amt-green">'. number_format($sumPayments['approved']) .'</td>
        <td></td>
    </tr>';
    $html .= '</tbody></table>';
}
} // end payments section

// Note when some sections were hidden
if (!empty($hidden)) {
    $hiddenLabels = ['patients'=>'نەخۆشی نوێ','folders'=>'فایلی نوێ','cases'=>'کەیسی نوێ','treatments'=>'چارەسەرەکان','payments'=>'پارەدانەکان'];
    $shown = array_map(fn($k) => $hiddenLabels[$k] ?? $k, $hidden);
    $html .= '<div style="font-size:9px;color:#94a3b8;text-align:center;margin-top:8px;font-style:italic;">بەشی شاراوە لە ڕاپۆرت: ' . htmlspecialchars(implode(' · ', $shown)) . '</div>';
}

$html .= '<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی لە سیستەمی '. APP_NAME .' دروستکراوە</div>';

$pdf = new InvoicePdfService([
    'orientation'      => 'L',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 8,
    'margin_bottom_mm' => 10,
    'margin_left_mm'   => 8,
    'margin_right_mm'  => 8,
]);
$pdf->setContent($html);

$output = $_GET['output'] ?? 'I';
$filename = 'patients-daily-report-' . $rangeFrom . ($rangeFrom !== $rangeTo ? '_' . $rangeTo : '') . '.pdf';
$pdf->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
