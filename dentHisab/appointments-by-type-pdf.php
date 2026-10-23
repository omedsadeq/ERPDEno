<?php
/**
 * Appointments by Type — PDF Report
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/services/InvoicePdfService.php';

$db   = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();

$currentUser    = $auth->getCurrentUser();
$isAdmin        = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$hasFullAccess  = $isAdmin || $isReceptionist;
$enforcedDoctor = $hasFullAccess ? null : $currentUser['id'];

$today = date('Y-m-d');

// Filters
$dateMode = $_GET['mode']      ?? 'month';
$fromIn   = $_GET['from']      ?? '';
$toIn     = $_GET['to']        ?? '';
$typeIn   = $_GET['type']      ?? 'all';
$docIn    = $_GET['doctor_id'] ?? '';
$statusIn = $_GET['status'] ?? 'all';

$validDate  = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$validTypes = ['all','general','checkup','treatment'];
$typeFilter = in_array($typeIn, $validTypes, true) ? $typeIn : 'all';
$validStatuses = ['all','active','scheduled','confirmed','waiting','in_progress','completed','cancelled','no_show','rescheduled'];
$statusFilter  = in_array($statusIn, $validStatuses, true) ? $statusIn : 'all';

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = '';
switch ($dateMode) {
    case 'today':
        $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'year':
        $rangeFrom = date('Y-01-01'); $rangeTo = date('Y-12-31'); $rangeLabel = 'ئەمساڵ'; break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        } else { $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel = 'ئەم مانگە'; }
        break;
    case 'month':
    default:
        $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel = 'ئەم مانگە';
}
$activeDoctorId = $enforcedDoctor ?: (is_numeric($docIn) ? intval($docIn) : null);

$typeLabels = ['all'=>'هەموو جۆرەکان','general'=>'گشتی','checkup'=>'پشکنین','treatment'=>'چارەسەر','followup'=>'بەدواداچوون','emergency'=>'فریاکەوتن','consultation'=>'ڕاوێژکاری'];
$typeColors = ['general'=>'#7c3aed','checkup'=>'#0ea5e9','treatment'=>'#16a34a','followup'=>'#ef6c00','emergency'=>'#dc2626','consultation'=>'#0891b2'];
$statusLabels = ['scheduled'=>'داڕێژراو','confirmed'=>'پشتڕاستکراو','waiting'=>'چاوەڕوان','in_progress'=>'لە چارەسەردا','completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە','no_show'=>'نەهات','rescheduled'=>'دواخراو'];
$statusColors = ['scheduled'=>'#ff8c00','confirmed'=>'#0078d4','waiting'=>'#ff9800','in_progress'=>'#2196f3','completed'=>'#4caf50','cancelled'=>'#f44336','no_show'=>'#ad1457','rescheduled'=>'#ef6c00'];

$where  = "a.appointment_date BETWEEN :from AND :to";
$params = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($typeFilter !== 'all') { $where .= " AND a.type = :type"; $params['type'] = $typeFilter; }
if ($activeDoctorId)       { $where .= " AND a.doctor_id = :doc"; $params['doc'] = $activeDoctorId; }
if ($statusFilter === 'active') {
    $where .= " AND a.status IN ('scheduled','confirmed','waiting','in_progress')";
} elseif ($statusFilter !== 'all') {
    $where .= " AND a.status = :status"; $params['status'] = $statusFilter;
}

$sql = "SELECT a.id, a.appointment_date, a.appointment_time, a.duration, a.type, a.status, a.notes, a.promised_amount,
               p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
               u.full_name AS doctor_name,
               wt.work_name,
               (
                   COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0)
                 + COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status='completed' AND xr.payment_status='debt'), 0)
               ) AS total_debt
        FROM appointments a
        LEFT JOIN patients   p  ON a.patient_id   = p.id
        LEFT JOIN users      u  ON a.doctor_id    = u.id
        LEFT JOIN work_types wt ON a.work_type_id = wt.id
        WHERE {$where}
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 500";
$rows = $db->fetchAll($sql, $params);

// Type breakdown for summary
$breakdownWhere  = "a.appointment_date BETWEEN :from AND :to";
$bParams = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($activeDoctorId) { $breakdownWhere .= " AND a.doctor_id = :doc"; $bParams['doc'] = $activeDoctorId; }
$typeCounts = ['general' => 0, 'checkup' => 0, 'treatment' => 0, 'other' => 0];
$grandTotal = 0;
foreach ($db->fetchAll("SELECT a.type, COUNT(*) c FROM appointments a WHERE {$breakdownWhere} GROUP BY a.type", $bParams) as $r) {
    if (isset($typeCounts[$r['type']])) { $typeCounts[$r['type']] = (int)$r['c']; }
    else { $typeCounts['other'] += (int)$r['c']; }
    $grandTotal += (int)$r['c'];
}

// Current-filter totals
$totalCount    = count($rows);
$totalDuration = 0;
$totalDebt     = 0;
$totalPromised = 0;
$seen = [];
foreach ($rows as $r) {
    $totalDuration += (int)$r['duration'];
    $d = max(0, (float)$r['total_debt']);
    if ($d > 0 && $r['patient_id'] && !isset($seen[$r['patient_id']])) { $seen[$r['patient_id']] = true; $totalDebt += $d; }
    $totalPromised += (float)($r['promised_amount'] ?? 0);
}

$doctorFilterName = '';
if ($activeDoctorId) {
    $dd = $db->fetch("SELECT full_name FROM users WHERE id = ?", [$activeDoctorId]);
    $doctorFilterName = $dd['full_name'] ?? '';
}

$reportDate = date('Y/m/d'); $reportTime = date('H:i');
$pct = fn($n) => $grandTotal > 0 ? round($n / $grandTotal * 100, 1) : 0;

$statusLabelsForMeta = ['all' => 'هەموو دۆخەکان', 'active' => 'چالاک'] + $statusLabels;
$statusLabelText     = $statusLabelsForMeta[$statusFilter] ?? $statusFilter;

$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 2px; font-size: 14px; }
    h3 { color: #0f172a; margin: 8px 0 4px; font-size: 11px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
    .meta { text-align: center; font-size: 9px; color: #555; margin-bottom: 6px; }
    .stats { margin: 0 auto 8px; border-collapse: collapse; width: 75%; }
    .stats td { border: 1px solid #ddd; text-align: center; padding: 4px 5px; }
    .stats .v { font-size: 12px; font-weight: bold; }
    .stats .l { font-size: 8px; color: #666; }
    table.t { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 8px; }
    table.t th { background: #eef2f7; padding: 5px 4px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; font-size: 9px; }
    table.t td { padding: 4px; border: 1px solid #e5e7eb; text-align: center; vertical-align: middle; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .sb { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; color: #fff; }
    .debt { color: #c62828; font-weight: bold; }
    .prom { color: #15803d; font-weight: bold; }
    .phone { direction: ltr; font-family: nrt; }
    .nd { color: #9aa7b3; font-size: 8px; }
    .footer-note { text-align: center; font-size: 8px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px; }
</style>

<h2>ڕاپۆرتی مەوعیدەکان بەپێی جۆر — '. htmlspecialchars($typeLabels[$typeFilter] ?? $typeFilter) .'</h2>
<div class="meta">
    '. htmlspecialchars($rangeLabel) .' &nbsp;|&nbsp; ماوە: '. htmlspecialchars($rangeFrom) .' بۆ '. htmlspecialchars($rangeTo) .'
    '. ($statusFilter !== 'all' ? ' &nbsp;|&nbsp; دۆخ: ' . htmlspecialchars($statusLabelText) : '') .'
    '. ($doctorFilterName ? ' &nbsp;|&nbsp; دکتۆر: د. ' . htmlspecialchars($doctorFilterName) : '') .'
    &nbsp;|&nbsp; بەرواری چاپ: '. $reportDate .' '. $reportTime .'
    &nbsp;|&nbsp; ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="stats">
    <tr>
        <td><div class="v">'. number_format($totalCount) .'</div><div class="l">کۆی مەوعیدەکان</div></td>
        <td><div class="v">'. number_format($totalDuration) .' خولەک</div><div class="l">کۆی ماوە</div></td>
        <td><div class="v" style="color:#c62828;">'. number_format($totalDebt) .'</div><div class="l">کۆی قەرز</div></td>
        <td><div class="v" style="color:#15803d;">'. number_format($totalPromised) .'</div><div class="l">پارەی بەڵێندراو</div></td>
    </tr>
</table>

<h3>دابەشبوونی جۆر (بەبێ فلتەری جۆر)</h3>
<table class="t">
    <thead><tr><th>جۆر</th><th>ژمارە</th><th>ڕێژە</th></tr></thead>
    <tbody>';
foreach (['general','checkup','treatment'] as $k) {
    $c = $typeCounts[$k];
    if ($c === 0) continue;
    $html .= '<tr>
        <td style="text-align:start;"><span class="sb" style="background:'. $typeColors[$k] .'">&nbsp;</span> '. $typeLabels[$k] .'</td>
        <td>'. number_format($c) .'</td>
        <td>'. $pct($c) .'%</td>
    </tr>';
}
if ($typeCounts['other'] > 0) {
    $html .= '<tr>
        <td style="text-align:start;">تر</td>
        <td>'. $typeCounts['other'] .'</td>
        <td>'. $pct($typeCounts['other']) .'%</td>
    </tr>';
}
$html .= '<tr style="background:#eef2f7;font-weight:bold;">
    <td>کۆی گشتی</td>
    <td>'. number_format($grandTotal) .'</td>
    <td>100%</td>
</tr>
</tbody></table>

<h3>خشتەی مەوعیدەکان ('. count($rows) .' تۆمار'. (count($rows) >= 500 ? ' — سنوور: ٥٠٠' : '') .')</h3>';

if (empty($rows)) {
    $html .= '<div style="text-align:center;padding:20px;color:#999;">هیچ تۆمارێک نەدۆزرایەوە</div>';
} else {
    $html .= '<table class="t">
        <thead>
            <tr>
                <th style="width:3%">#</th>
                <th style="width:9%">بەروار</th>
                <th style="width:5%">کات</th>
                <th style="width:16%">نەخۆش</th>
                <th style="width:12%">دکتۆر</th>
                <th style="width:7%">جۆر</th>
                <th style="width:12%">ئیش</th>
                <th style="width:5%">ماوە</th>
                <th style="width:10%">مۆبایل</th>
                <th style="width:10%">قەرز</th>
                <th style="width:9%">هێنراو</th>
                <th style="width:8%">دۆخ</th>
            </tr>
        </thead>
        <tbody>';
    foreach ($rows as $i => $r) {
        $debt  = max(0, (float)$r['total_debt']);
        $prom  = (float)($r['promised_amount'] ?? 0);
        $phone = preg_replace('/\s+/', '', (string)$r['patient_phone']);
        $tk = $r['type']; $tColor = $typeColors[$tk] ?? '#666'; $tLbl = $typeLabels[$tk] ?? $tk;
        $sk = $r['status']; $sColor = $statusColors[$sk] ?? '#666'; $sLbl = $statusLabels[$sk] ?? $sk;

        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td>'. htmlspecialchars($r['appointment_date']) .'</td>
            <td>'. date('H:i', strtotime($r['appointment_time'])) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($r['patient_name'] ?? '—') .'</strong></td>
            <td>د. '. htmlspecialchars($r['doctor_name'] ?? '—') .'</td>
            <td><span class="sb" style="background:'. $tColor .'">'. $tLbl .'</span></td>
            <td style="font-size:9px;">'. (!empty($r['work_name']) ? htmlspecialchars($r['work_name']) : '—') .'</td>
            <td>'. intval($r['duration']) .'</td>
            <td class="phone">'. htmlspecialchars($phone ?: '—') .'</td>
            <td>'. ($debt > 0 ? '<span class="debt">'. number_format($debt) .'</span>' : '<span class="nd">بێ قەرز</span>') .'</td>
            <td>'. ($prom > 0 ? '<span class="prom">'. number_format($prom) .'</span>' : '—') .'</td>
            <td><span class="sb" style="background:'. $sColor .'">'. $sLbl .'</span></td>
        </tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی لە سیستەمی '. APP_NAME .' دروستکراوە</div>';

$pdf = new InvoicePdfService([
    'orientation'      => 'L',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 8,
    'margin_bottom_mm' => 10,
    'margin_left_mm'   => 10,
    'margin_right_mm'  => 10,
]);
$pdf->setContent($html);

$output = $_GET['output'] ?? 'I';
$filename = 'appointments-by-type-' . $typeFilter . '-' . $statusFilter . '-' . $rangeFrom . '_' . $rangeTo . '.pdf';
$pdf->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
