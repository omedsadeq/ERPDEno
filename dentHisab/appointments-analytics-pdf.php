<?php
/**
 * Appointments Analytics — PDF Report
 * ڕاپۆرتی ئامار و شیکاری مەوعیدەکان (PDF)
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

// ── Same filter resolution as the page ──
$mode           = $_GET['mode']      ?? 'month';
$fromIn         = $_GET['from']      ?? '';
$toIn           = $_GET['to']        ?? '';
$doctorIdFilter = $_GET['doctor_id'] ?? '';
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = '';
switch ($mode) {
    case 'today':
        $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'quarter':
        $rangeFrom = date('Y-m-d', strtotime('-3 months +1 day'));
        $rangeTo   = $today;
        $rangeLabel= 'سێ مانگی ڕابردوو';
        break;
    case 'half_year':
        $rangeFrom = date('Y-m-d', strtotime('-6 months +1 day'));
        $rangeTo   = $today;
        $rangeLabel= 'شەش مانگی ڕابردوو';
        break;
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
$activeDoctorId = $enforcedDoctor ?: (is_numeric($doctorIdFilter) ? intval($doctorIdFilter) : null);

$baseWhere  = "a.appointment_date BETWEEN :from AND :to";
$baseParams = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($activeDoctorId) { $baseWhere .= " AND a.doctor_id = :doc"; $baseParams['doc'] = $activeDoctorId; }

// Status counts
$statusRows = $db->fetchAll("SELECT a.status, COUNT(*) c FROM appointments a WHERE {$baseWhere} GROUP BY a.status", $baseParams);
$statusCount = ['scheduled'=>0,'confirmed'=>0,'waiting'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0,'no_show'=>0,'rescheduled'=>0];
foreach ($statusRows as $r) { $statusCount[$r['status']] = (int)$r['c']; }

$totalCount     = array_sum($statusCount);
$completedCount = $statusCount['completed'];
$cancelledCount = $statusCount['cancelled'];
$rescheduledCount = $statusCount['rescheduled'];
$noShowCount    = $statusCount['no_show'];
$activeCount    = $statusCount['scheduled'] + $statusCount['confirmed'] + $statusCount['waiting'] + $statusCount['in_progress'];
$pct = fn($n) => $totalCount > 0 ? round(($n / $totalCount) * 100, 1) : 0;

// Weekday breakdown
$weekdayRows = $db->fetchAll("SELECT DAYOFWEEK(a.appointment_date) dow, COUNT(*) c FROM appointments a WHERE {$baseWhere} GROUP BY DAYOFWEEK(a.appointment_date)", $baseParams);
$weekdayCounts = [1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,7=>0];
foreach ($weekdayRows as $r) { $weekdayCounts[(int)$r['dow']] = (int)$r['c']; }
$weekdayOrder = [7,1,2,3,4,5,6];
$weekdayLabels= ['شەممە','یەکشەممە','دووشەممە','سێشەممە','چوارشەممە','پێنجشەممە','هەینی'];

// ── Date trend: choose granularity from range length ──
$daysInRange = (strtotime($rangeTo) - strtotime($rangeFrom)) / 86400 + 1;
if ($daysInRange <= 1) {
    $granularity   = 'hour';
    $granLabel     = 'بەپێی کاتژمێر';
    $bucketExpr    = "DATE_FORMAT(a.appointment_time, '%H:00')";
    $bucketOrder   = "ORDER BY bucket";
} elseif ($daysInRange <= 45) {
    $granularity   = 'day';
    $granLabel     = 'بەپێی ڕۆژ';
    $bucketExpr    = "DATE_FORMAT(a.appointment_date, '%Y-%m-%d')";
    $bucketOrder   = "ORDER BY bucket";
} elseif ($daysInRange <= 180) {
    $granularity   = 'week';
    $granLabel     = 'بەپێی هەفتە';
    $bucketExpr    = "DATE_FORMAT(a.appointment_date, '%x-W%v')";
    $bucketOrder   = "ORDER BY bucket";
} else {
    $granularity   = 'month';
    $granLabel     = 'بەپێی مانگ';
    $bucketExpr    = "DATE_FORMAT(a.appointment_date, '%Y-%m')";
    $bucketOrder   = "ORDER BY bucket";
}

$trendSql = "SELECT {$bucketExpr} AS bucket,
                    COUNT(*) AS total,
                    SUM(a.status='completed')   AS completed,
                    SUM(a.status='rescheduled') AS rescheduled,
                    SUM(a.status='cancelled')   AS cancelled,
                    SUM(a.status='no_show')     AS no_show
             FROM appointments a
             WHERE {$baseWhere}
             GROUP BY bucket
             {$bucketOrder}";
$trendRows = $db->fetchAll($trendSql, $baseParams);

$kurdishDayNames = [
    'Sun'=>'یەکشەممە','Mon'=>'دووشەممە','Tue'=>'سێشەممە','Wed'=>'چوارشەممە',
    'Thu'=>'پێنجشەممە','Fri'=>'هەینی','Sat'=>'شەممە'
];
$kurdishMonthNames = [
    '01'=>'کانوونی دووەم','02'=>'شوبات','03'=>'ئازار','04'=>'نیسان','05'=>'ئایار','06'=>'حوزەیران',
    '07'=>'تەممووز','08'=>'ئاب','09'=>'ئەیلوول','10'=>'تشرینی یەکەم','11'=>'تشرینی دووەم','12'=>'کانوونی یەکەم'
];

$fmtBucket = function($bucket) use ($granularity, $kurdishDayNames, $kurdishMonthNames) {
    switch ($granularity) {
        case 'hour':
            return htmlspecialchars($bucket);
        case 'day':
            $ts = strtotime($bucket);
            return $bucket . '<br><span style="font-size:8px;color:#666;">' . ($kurdishDayNames[date('D', $ts)] ?? '') . '</span>';
        case 'week':
            [$yr, $wkRaw] = explode('-W', $bucket);
            return 'هەفتەی ' . intval($wkRaw) . ' · ' . $yr;
        case 'month':
            [$yr, $mo] = explode('-', $bucket);
            return ($kurdishMonthNames[$mo] ?? $mo) . ' ' . $yr;
    }
    return $bucket;
};

// Find max for the mini-bar visual
$trendMax = 0;
foreach ($trendRows as $tr) { if ((int)$tr['total'] > $trendMax) $trendMax = (int)$tr['total']; }

// Doctor breakdown (if no specific doctor filter)
$doctorRows = [];
if (!$activeDoctorId) {
    $doctorRows = $db->fetchAll(
        "SELECT u.id, u.full_name,
                SUM(a.status='completed') completed,
                SUM(a.status='cancelled') cancelled,
                SUM(a.status='rescheduled') rescheduled,
                COUNT(*) total
         FROM appointments a
         LEFT JOIN users u ON a.doctor_id = u.id
         WHERE {$baseWhere}
         GROUP BY u.id, u.full_name
         ORDER BY total DESC",
        $baseParams
    );
}

// Detail rows
$sql = "SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.notes, a.promised_amount,
               p.full_name AS patient_name, p.phone AS patient_phone,
               u.full_name AS doctor_name,
               (
                   COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                             FROM patient_folders pf WHERE pf.patient_id = p.id), 0)
                 + COALESCE((SELECT SUM(xr.final_amount)
                             FROM xray_requests xr WHERE xr.patient_id = p.id
                             AND xr.status='completed' AND xr.payment_status='debt'), 0)
               ) AS total_debt
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users    u ON a.doctor_id  = u.id
        WHERE {$baseWhere}
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 500";
$rows = $db->fetchAll($sql, $baseParams);

$reportDate = date('Y/m/d'); $reportTime = date('H:i');

$statusLabels = [
    'scheduled'=>'داڕێژراو','confirmed'=>'پشتڕاستکراو','waiting'=>'چاوەڕوان','in_progress'=>'لە چارەسەردا',
    'completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە','no_show'=>'نەهات','rescheduled'=>'دواخراو'
];
$statusColors = [
    'scheduled'=>'#ff8c00','confirmed'=>'#0078d4','waiting'=>'#ff9800','in_progress'=>'#2196f3',
    'completed'=>'#4caf50','cancelled'=>'#f44336','no_show'=>'#ad1457','rescheduled'=>'#ef6c00'
];

$doctorFilterName = '';
if ($activeDoctorId) {
    $d = $db->fetch("SELECT full_name FROM users WHERE id = ?", [$activeDoctorId]);
    $doctorFilterName = $d['full_name'] ?? '';
}

// ── Build HTML ──
$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 2px 0; font-size: 15px; }
    h3 { color: #0f172a; margin: 10px 0 6px; font-size: 12px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
    .meta { text-align: center; font-size: 9px; color: #555; margin-bottom: 8px; }
    .stats-box { margin: 0 auto 10px; border-collapse: collapse; width: 92%; }
    .stats-box td { border: 1px solid #d4d4d4; text-align: center; padding: 4px 5px; vertical-align: top; }
    .stat-v { font-size: 13px; font-weight: bold; color: #1a1a2e; }
    .stat-l { font-size: 9px; color: #666; margin-top: 2px; }
    .stat-p { font-size: 9px; color: #0078d4; font-weight: 600; margin-top: 2px; }
    table.t { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 8px; }
    table.t th { background: #eef2f7; padding: 5px 4px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; color: #0f172a; font-size: 9px; }
    table.t td { padding: 4px; border: 1px solid #e5e7eb; vertical-align: middle; text-align: center; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .sb { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; color: #fff; }
    .debt   { color: #c62828; font-weight: bold; }
    .nodebt { color: #9aa7b3; font-size: 9px; }
    .prom   { color: #15803d; font-weight: bold; }
    .phone  { direction: ltr; font-family: nrt; }
    .two-col { width: 100%; border-collapse: collapse; }
    .two-col > tbody > tr > td { padding: 0 4px; vertical-align: top; width: 50%; }
    .totals td { background: #eef2f7 !important; font-weight: bold; }
    .footer-note { text-align: center; font-size: 8px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px; }
</style>

<h2>ڕاپۆرتی ئامار و شیکاری مەوعیدەکان — '. htmlspecialchars($rangeLabel) .'</h2>
<div class="meta">
    ماوە: '. htmlspecialchars($rangeFrom) .' بۆ '. htmlspecialchars($rangeTo) .' &nbsp;|&nbsp;
    '. ($doctorFilterName ? 'دکتۆر: د. ' . htmlspecialchars($doctorFilterName) . ' &nbsp;|&nbsp;' : '') .'
    بەرواری چاپ: '. $reportDate .' '. $reportTime .' &nbsp;|&nbsp;
    ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="stats-box">
    <tr>
        <td>
            <div class="stat-v">'. number_format($totalCount) .'</div>
            <div class="stat-l">کۆی گشتی مەوعیدەکان</div>
            <div class="stat-p">چالاک: '. $activeCount .'</div>
        </td>
        <td>
            <div class="stat-v" style="color:#15803d;">'. number_format($completedCount) .'</div>
            <div class="stat-l">تەواوبووەکان</div>
            <div class="stat-p">'. $pct($completedCount) .'%</div>
        </td>
        <td>
            <div class="stat-v" style="color:#ea580c;">'. number_format($rescheduledCount) .'</div>
            <div class="stat-l">دواخراوەکان</div>
            <div class="stat-p">'. $pct($rescheduledCount) .'%</div>
        </td>
        <td>
            <div class="stat-v" style="color:#c62828;">'. number_format($cancelledCount) .'</div>
            <div class="stat-l">هەڵوەشێندراوەکان</div>
            <div class="stat-p">'. $pct($cancelledCount) .'% · نەهاتن: '. $noShowCount .'</div>
        </td>
    </tr>
</table>

<table class="two-col"><tr><td>';

// Status breakdown
$html .= '<h3>دابەشبوونی دۆخی مەوعیدەکان</h3>
<table class="t">
    <thead><tr><th>دۆخ</th><th>ژمارە</th><th>ڕێژە</th></tr></thead>
    <tbody>';
foreach ($statusCount as $k => $v) {
    if ($v === 0) continue;
    $color = $statusColors[$k];
    $html .= '<tr>
        <td style="text-align:start;"><span class="sb" style="background:'.$color.'">&nbsp;</span> '. ($statusLabels[$k] ?? $k) .'</td>
        <td>'. number_format($v) .'</td>
        <td>'. $pct($v) .'%</td>
    </tr>';
}
$html .= '<tr class="totals"><td>کۆی گشتی</td><td>'. number_format($totalCount) .'</td><td>100%</td></tr>';
$html .= '</tbody></table>';

$html .= '</td><td>';

// Weekday breakdown
$html .= '<h3>مەوعیدەکان بەپێی ڕۆژ</h3>
<table class="t">
    <thead><tr><th>ڕۆژ</th><th>ژمارە</th><th>ڕێژە</th></tr></thead>
    <tbody>';
foreach ($weekdayOrder as $i => $dow) {
    $cnt = $weekdayCounts[$dow];
    $html .= '<tr>
        <td>'. $weekdayLabels[$i] .'</td>
        <td>'. $cnt .'</td>
        <td>'. $pct($cnt) .'%</td>
    </tr>';
}
$html .= '</tbody></table>';

$html .= '</td></tr></table>';

// ── Date Trend table ──
if (!empty($trendRows)) {
    $html .= '<h3>شیکاری مەوعیدەکان ' . htmlspecialchars($granLabel) . '</h3>
    <table class="t">
        <thead>
            <tr>
                <th style="width:14%">ماوە</th>
                <th style="width:10%">کۆی گشتی</th>
                <th style="width:9%">تەواوبوو</th>
                <th style="width:9%">دواخراو</th>
                <th style="width:9%">هەڵوەشاوە</th>
                <th style="width:9%">نەهات</th>
                <th style="width:9%">ڕێژەی تەواوبوون</th>
                <th style="width:31%">بینراو</th>
            </tr>
        </thead>
        <tbody>';

    $sumT = 0; $sumC = 0; $sumR = 0; $sumX = 0; $sumN = 0;
    foreach ($trendRows as $tr) {
        $t = (int)$tr['total']; $c = (int)$tr['completed']; $rc = (int)$tr['rescheduled']; $x = (int)$tr['cancelled']; $n = (int)$tr['no_show'];
        $sumT += $t; $sumC += $c; $sumR += $rc; $sumX += $x; $sumN += $n;
        $rate = $t > 0 ? round($c / $t * 100) : 0;
        $barWidth = $trendMax > 0 ? round($t / $trendMax * 100) : 0;

        $html .= '<tr>
            <td style="text-align:start;">' . $fmtBucket($tr['bucket']) . '</td>
            <td><strong>' . $t . '</strong></td>
            <td style="color:#15803d;font-weight:bold;">' . $c . '</td>
            <td style="color:#ea580c;">' . $rc . '</td>
            <td style="color:#c62828;">' . $x . '</td>
            <td style="color:#ad1457;">' . $n . '</td>
            <td>' . $rate . '%</td>
            <td style="padding:0 4px;">
                <div style="background:#f1f5f9;border-radius:3px;height:9px;position:relative;overflow:hidden;">
                    <div style="background:linear-gradient(90deg,#4caf50 0%,#4caf50 ' . ($t > 0 ? round($c/$t*100) : 0) . '%,#0078d4 ' . ($t > 0 ? round($c/$t*100) : 0) . '%,#0078d4 100%);height:100%;width:' . $barWidth . '%;"></div>
                </div>
            </td>
        </tr>';
    }
    $html .= '<tr class="totals">
        <td>کۆی گشتی</td>
        <td><strong>' . $sumT . '</strong></td>
        <td style="color:#15803d;">' . $sumC . '</td>
        <td style="color:#ea580c;">' . $sumR . '</td>
        <td style="color:#c62828;">' . $sumX . '</td>
        <td style="color:#ad1457;">' . $sumN . '</td>
        <td>' . ($sumT > 0 ? round($sumC/$sumT*100) : 0) . '%</td>
        <td></td>
    </tr>';
    $html .= '</tbody></table>';
}

// Doctor breakdown (only when no specific doctor filter)
if (!empty($doctorRows)) {
    $html .= '<h3>ئاماری دکتۆرەکان</h3>
    <table class="t">
        <thead>
            <tr>
                <th>#</th><th>ناوی دکتۆر</th>
                <th>کۆی گشتی</th>
                <th>تەواوبوو</th>
                <th>دواخراو</th>
                <th>هەڵوەشاوە</th>
                <th>ڕێژەی تەواوبوون</th>
            </tr>
        </thead>
        <tbody>';
    foreach ($doctorRows as $i => $dr) {
        $drTotal = (int)$dr['total'];
        $drDone  = (int)$dr['completed'];
        $drRate  = $drTotal > 0 ? round($drDone / $drTotal * 100, 1) : 0;
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>د. '. htmlspecialchars($dr['full_name'] ?? '—') .'</strong></td>
            <td>'. $drTotal .'</td>
            <td class="prom">'. (int)$dr['completed'] .'</td>
            <td>'. (int)$dr['rescheduled'] .'</td>
            <td class="debt">'. (int)$dr['cancelled'] .'</td>
            <td>'. $drRate .'%</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}

// Detail rows
$html .= '<h3>خشتەی تۆماری مەوعیدەکان ('. count($rows) .' تۆمار'. (count($rows) >= 500 ? ' — سنوور: ٥٠٠' : '') .')</h3>';
if (empty($rows)) {
    $html .= '<div style="text-align:center;padding:20px;color:#999;">هیچ تۆمارێک نەدۆزرایەوە</div>';
} else {
    $html .= '<table class="t">
        <thead>
            <tr>
                <th style="width:3%">#</th>
                <th style="width:16%">نەخۆش</th>
                <th style="width:11%">مۆبایل</th>
                <th style="width:13%">دکتۆر</th>
                <th style="width:9%">بەروار</th>
                <th style="width:6%">کات</th>
                <th style="width:9%">دۆخ</th>
                <th style="width:11%">قەرز</th>
                <th style="width:10%">هێنراو</th>
                <th style="width:12%">تێبینی</th>
            </tr>
        </thead>
        <tbody>';
    foreach ($rows as $i => $r) {
        $debt  = max(0, (float)$r['total_debt']);
        $prom  = (float)($r['promised_amount'] ?? 0);
        $phone = preg_replace('/\s+/', '', (string)$r['patient_phone']);
        $stKey = $r['status'];
        $stColor = $statusColors[$stKey] ?? '#666';
        $stLbl   = $statusLabels[$stKey] ?? $stKey;
        $note = !empty($r['notes']) ? mb_substr($r['notes'], 0, 30) . (mb_strlen($r['notes']) > 30 ? '…' : '') : '—';

        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($r['patient_name'] ?? '—') .'</strong></td>
            <td class="phone">'. htmlspecialchars($phone ?: '—') .'</td>
            <td>د. '. htmlspecialchars($r['doctor_name'] ?? '—') .'</td>
            <td>'. htmlspecialchars($r['appointment_date']) .'</td>
            <td>'. date('H:i', strtotime($r['appointment_time'])) .'</td>
            <td><span class="sb" style="background:'. $stColor .'">'. $stLbl .'</span></td>
            <td>'. ($debt > 0 ? '<span class="debt">'. number_format($debt) .'</span>' : '<span class="nodebt">بێ قەرز</span>') .'</td>
            <td>'. ($prom > 0 ? '<span class="prom">'. number_format($prom) .'</span>' : '—') .'</td>
            <td style="font-size:9px;text-align:start;">'. htmlspecialchars($note) .'</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی لە سیستەمی '. APP_NAME .' دروستکراوە</div>';

// ── Generate PDF ──
$pdfService = new InvoicePdfService([
    'orientation'      => 'L',
    'format'           => 'A4',
    'show_header'      => 0,
    'show_footer'      => 0,
    'margin_top_mm'    => 8,
    'margin_bottom_mm' => 10,
    'margin_left_mm'   => 10,
    'margin_right_mm'  => 10,
]);
$pdfService->setContent($html);

$output = $_GET['output'] ?? 'I';
$filename = 'appointments-analytics-' . $rangeFrom . '_to_' . $rangeTo . '.pdf';
$pdfService->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
