<?php
/**
 * Today's Appointments — PDF Report
 * ڕاپۆرتی لیستی مەوعیدەکان (PDF)
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/XrayManager.php';
require_once __DIR__ . '/services/InvoicePdfService.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();

$currentUser   = $auth->getCurrentUser();
$isAdmin       = $auth->isAdmin();
$isReceptionist= $auth->isReceptionist();
$hasFullAccess = $isAdmin || $isReceptionist;
$doctorId      = $hasFullAccess ? null : $currentUser['id'];

$today = date('Y-m-d');

// --- Resolve same filters as the page ---
$mode    = $_GET['mode']    ?? 'today';
$dateIn  = $_GET['date']    ?? '';
$fromIn  = $_GET['from']    ?? '';
$toIn    = $_GET['to']      ?? '';
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = 'ئەمڕۆ';
switch ($mode) {
    case 'yesterday':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('-1 day')); $rangeLabel = 'دوێنێ'; break;
    case 'tomorrow':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('+1 day')); $rangeLabel = 'بەیانی'; break;
    case 'day_after':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('+2 day')); $rangeLabel = 'دووبەیانی'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'month':
        $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel= 'ئەم مانگە'; break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} — {$rangeTo}";
        }
        break;
    case 'date':
        if ($validDate($dateIn)) { $rangeFrom = $rangeTo = $dateIn; $rangeLabel = $dateIn; }
        break;
}

// --- Fetch rows (same query as page) ---
$sql = "SELECT a.id, a.appointment_date, a.appointment_time, a.duration, a.promised_amount,
               p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
               u.full_name AS doctor_name,
               (
                   COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                             FROM patient_folders pf WHERE pf.patient_id = p.id), 0)
                 + COALESCE((SELECT SUM(xr.final_amount)
                             FROM xray_requests xr WHERE xr.patient_id = p.id
                             AND xr.status='completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")), 0)
               ) AS total_debt
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users    u ON a.doctor_id  = u.id
        WHERE a.appointment_date BETWEEN :from AND :to
          AND a.status NOT IN ('cancelled','no_show')";
$params = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($doctorId) { $sql .= " AND a.doctor_id = :doc"; $params['doc'] = $doctorId; }
$sql .= " ORDER BY a.appointment_date, a.appointment_time";
$rows = $db->fetchAll($sql, $params);

// --- Totals (deduplicated debt) ---
$totalDebtSum = 0; $totalPromised = 0; $debtorCount = 0; $seen = [];
foreach ($rows as $r) {
    $d = max(0, (float)$r['total_debt']);
    $pid = $r['patient_id'];
    if ($d > 0 && $pid && !isset($seen[$pid])) { $seen[$pid] = true; $debtorCount++; $totalDebtSum += $d; }
    $totalPromised += (float)($r['promised_amount'] ?? 0);
}

$reportDate = date('Y/m/d');
$reportTime = date('H:i');
$rangeDisplay = $rangeFrom === $rangeTo ? $rangeFrom : ($rangeFrom . ' — ' . $rangeTo);

// --- Build HTML ---
$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 2px 0; font-size: 14px; }
    .report-meta { text-align: center; font-size: 9px; color: #555; margin-bottom: 6px; }
    .stats-box { margin: 0 auto 8px; border-collapse: collapse; width: 70%; }
    .stats-box td { border: 1px solid #ddd; text-align: center; padding: 3px 4px; }
    .stat-val { font-size: 11px; font-weight: bold; color: #1a1a2e; line-height: 1.1; }
    .stat-lbl { font-size: 8px; color: #666; line-height: 1.1; }
    table.list { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 5px; }
    table.list th { background: #eef2f7; padding: 7px 5px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; color: #0f172a; font-size: 10px; }
    table.list td { padding: 6px 5px; border: 1px solid #e5e7eb; vertical-align: middle; text-align: center; }
    table.list tr:nth-child(even) td { background: #fafafa; }
    .debt    { color: #c62828; font-weight: bold; }
    .nodebt  { color: #9aa7b3; font-size: 10px; }
    .promise { color: #2e7d32; font-weight: bold; }
    .phone   { direction: ltr; font-family: nrt; font-size: 11px; }
    .no-data { text-align: center; padding: 40px; color: #999; font-size: 14px; }
    .footer-note { text-align: center; font-size: 9px; color: #999; margin-top: 12px; border-top: 1px solid #eee; padding-top: 6px; }
    .totals-row td { background: #eef2f7 !important; font-weight: bold; }
</style>

<h2>ڕاپۆرتی مەوعیدەکانی '. htmlspecialchars($rangeLabel) .'</h2>
<div class="report-meta">
    ماوە: '. htmlspecialchars($rangeDisplay) .' &nbsp;|&nbsp;
    بەرواری چاپ: '. $reportDate .' '. $reportTime .' &nbsp;|&nbsp;
    ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="stats-box">
    <tr>
        <td><div class="stat-val">'. count($rows) .'</div><div class="stat-lbl">کۆی مەوعیدەکان</div></td>
        <td><div class="stat-val">'. $debtorCount .'</div><div class="stat-lbl">ژمارەی قەرزارەکان</div></td>
        <td><div class="stat-val">'. number_format($totalDebtSum) .'</div><div class="stat-lbl">کۆی گشتی قەرز</div></td>
        <td><div class="stat-val">'. number_format($totalPromised) .'</div><div class="stat-lbl">کۆی پارەی بەڵێندراو</div></td>
    </tr>
</table>';

if (empty($rows)) {
    $html .= '<div class="no-data">هیچ مەوعیدێک نەدۆزرایەوە</div>';
} else {
    $html .= '<table class="list">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th style="width:9%">بەروار</th>
                <th style="width:6%">کات</th>
                <th style="width:18%">ناوی نەخۆش</th>
                <th style="width:14%">ناوی دکتۆر</th>
                <th style="width:7%">ماوە</th>
                <th style="width:12%">ژمارە مۆبایل</th>
                <th style="width:15%">کۆی قەرز</th>
                <th style="width:15%">پارەی هێنراو</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($rows as $i => $r) {
        $debt  = max(0, (float)$r['total_debt']);
        $phone = preg_replace('/\s+/', '', (string)$r['patient_phone']);
        $prom  = (float)($r['promised_amount'] ?? 0);

        $html .= '<tr>
            <td>'. ($i + 1) .'</td>
            <td>'. htmlspecialchars($r['appointment_date']) .'</td>
            <td>'. date('H:i', strtotime($r['appointment_time'])) .'</td>
            <td><strong>'. htmlspecialchars($r['patient_name'] ?? '—') .'</strong></td>
            <td>'. htmlspecialchars($r['doctor_name'] ?? '—') .'</td>
            <td>'. intval($r['duration']) .' خولەک</td>
            <td class="phone">'. htmlspecialchars($phone ?: '—') .'</td>
            <td>'. ($debt > 0
                ? '<span class="debt">'. number_format($debt) .'</span>'
                : '<span class="nodebt">بێ قەرز</span>') .'</td>
            <td>'. ($prom > 0
                ? '<span class="promise">'. number_format($prom) .'</span>'
                : '—') .'</td>
        </tr>';
    }

    $html .= '<tr class="totals-row">
        <td colspan="7" style="text-align:center;">کۆی گشتی</td>
        <td><span class="debt">'. number_format($totalDebtSum) .'</span></td>
        <td><span class="promise">'. number_format($totalPromised) .'</span></td>
    </tr>
    </tbody></table>';
}

$html .= '<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی لە سیستەمی '. APP_NAME .' دروستکراوە</div>';

// --- Generate PDF (no header image, tight margins) ---
$pdfService = new InvoicePdfService([
    'orientation'    => 'L',
    'format'         => 'A4',
    'show_header'    => 0,
    'show_footer'    => 0,
    'margin_top_mm'  => 8,
    'margin_bottom_mm' => 10,
    'margin_left_mm' => 10,
    'margin_right_mm'=> 10,
]);
$pdfService->setContent($html);

$mode = $_GET['output'] ?? 'I'; // I=inline, D=download
$filename = 'today-appointments-' . $rangeFrom . ($rangeFrom !== $rangeTo ? '_to_' . $rangeTo : '') . '.pdf';
$pdfService->output($filename, in_array($mode, ['I','D','F'], true) ? $mode : 'I');
