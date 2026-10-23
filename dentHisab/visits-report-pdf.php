<?php
/**
 * Visits Report — PDF
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/services/InvoicePdfService.php';
require_once __DIR__ . '/includes/VisitReportStats.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();

$today = date('Y-m-d');
$mode   = $_GET['mode'] ?? 'today';
$fromIn = $_GET['from'] ?? '';
$toIn   = $_GET['to']   ?? '';
$dateFilter = $_GET['date_filter'] ?? 'visit_date';
$dateFilter = $dateFilter === 'appointment_date' ? 'appointment_date' : 'visit_date';
$dateFilterLabel = $dateFilter === 'appointment_date' ? 'بەرواری مەوعید' : 'بەرواری سەردان';
$visitType = $_GET['visit_type'] ?? 'checkup';
$visitType = in_array($visitType, ['checkup', 'treatment'], true) ? $visitType : 'checkup';
$visitTypeLabel = $visitType === 'treatment' ? 'ئیش' : 'پشکنین';
$valid  = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

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
        if ($valid($fromIn) && $valid($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        }
        break;
}
$p = ['from' => $rangeFrom, 'to' => $rangeTo, 'visit_type' => $visitType];
$matrixStats = dh_visit_report_stats($db, $rangeFrom, $rangeTo, $dateFilter, $visitType);

$summary = $db->fetch(
    "SELECT COUNT(*) AS total_visits,
            SUM(CASE WHEN v.patient_status='new' THEN 1 ELSE 0 END) AS new_patients,
            SUM(CASE WHEN v.patient_status='old' THEN 1 ELSE 0 END) AS old_patients,
            SUM(CASE WHEN v.patient_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM appointments a WHERE a.patient_id=v.patient_id
                  AND a.appointment_date >= CURDATE()
                  AND a.status NOT IN ('cancelled','no_show','completed')
            ) THEN 1 ELSE 0 END) AS with_appointment,
            COUNT(DISTINCT v.patient_id) AS unique_patients,
            COUNT(DISTINCT v.assigned_doctor_id) AS active_doctors
     FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type", $p);
$total  = (int)$summary['total_visits'];
$newPts = (int)$summary['new_patients'];
$oldPts = (int)$summary['old_patients'];
$withApt = (int)$summary['with_appointment'];
$withoutApt = max(0, $total - $withApt);
$pct = fn($n) => $total > 0 ? round($n / $total * 100, 1) : 0;

$byDoctor = $db->fetchAll(
    "SELECT d.name, COUNT(v.id) AS visits,
            SUM(CASE WHEN v.patient_status='new' THEN 1 ELSE 0 END) AS new_patients,
            SUM(CASE WHEN v.patient_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM appointments a WHERE a.patient_id=v.patient_id
                  AND a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelled','no_show','completed')
            ) THEN 1 ELSE 0 END) AS with_apt
     FROM visits v LEFT JOIN doctors d ON v.assigned_doctor_id=d.id
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
     GROUP BY d.id, d.name ORDER BY visits DESC", $p);

$bySource = $db->fetchAll(
    "SELECT COALESCE(NULLIF(TRIM(referral_source), ''), 'نادیار') AS source, COUNT(*) c
     FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
     GROUP BY source ORDER BY c DESC", $p);

$byProcedure = $db->fetchAll(
    "SELECT COALESCE(NULLIF(TRIM(procedure_treatment), ''), 'نادیار') AS proc, COUNT(*) c
     FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
     GROUP BY proc ORDER BY c DESC LIMIT 10", $p);

$byHour = $db->fetchAll(
    "SELECT HOUR(arrival_time) h, COUNT(*) c FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
       AND arrival_time IS NOT NULL
     GROUP BY HOUR(arrival_time) ORDER BY h", $p);
$hourCounts = [];
foreach ($byHour as $r) { $hourCounts[(int)$r['h']] = (int)$r['c']; }

$reportGenDate = date('Y/m/d'); $reportGenTime = date('H:i');

$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
    h2 { text-align: center; color: #1a1a2e; margin: 0 0 3px; font-size: 15px; }
    h3 { color: #0f172a; margin: 10px 0 4px; font-size: 12px; border-bottom: 2px solid #7c3aed; padding-bottom: 3px; }
    .meta { text-align: center; font-size: 10px; color: #555; margin-bottom: 8px; }
    .stats { margin: 0 auto 8px; border-collapse: collapse; width: 100%; }
    .stats td { border: 1px solid #ddd; text-align: center; padding: 5px; }
    .stats .v { font-size: 13px; font-weight: bold; }
    .stats .l { font-size: 9px; color: #666; margin-top: 2px; }
    .two-col { width: 100%; border-collapse: collapse; }
    .two-col > tbody > tr > td { padding: 0 4px; vertical-align: top; width: 50%; }
    table.t { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 5px; }
    table.t th { background: #eef2f7; padding: 4px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; font-size: 9px; }
    table.t td { padding: 3px; border: 1px solid #e5e7eb; text-align: center; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .totals-row td { background: #eef2f7 !important; font-weight: bold; }
    table.matrix { width: 100%; border-collapse: collapse; font-size: 8.5px; margin-bottom: 6px; }
    table.matrix th { background: #eef2f7; padding: 3px; text-align: center; border: 1px solid #c9d1db; font-weight: bold; }
    table.matrix td { padding: 3px; border: 1px solid #e5e7eb; text-align: center; }
    table.matrix .row-title { text-align: right; font-weight: bold; background: #fbfdff; }
    table.matrix .total-cell { background: #f5f3ff; color: #5b21b6; font-weight: bold; }
    table.matrix .grand td { background: #eef7ff; font-weight: bold; }
    table.matrix .highlight td { background: #fff8e6; }
    .small-muted { color: #666; font-size: 8px; }
    .empty { text-align: center; color: #999; font-size: 9px; font-style: italic; padding: 6px; }
    .v-green { color: #15803d; font-weight: bold; }
    .v-red   { color: #c62828; font-weight: bold; }
    .v-blue  { color: #0078d4; font-weight: bold; }
    .v-orange{ color: #ea580c; font-weight: bold; }
    .hour-table { width: 100%; border-collapse: collapse; }
    .hour-table td { padding: 2px; text-align: center; font-size: 9px; }
    .hour-table .h-count { background: #ede9fe; color: #5b21b6; font-weight: bold; font-size: 10px; }
    .footer-note { text-align: center; font-size: 8px; color: #999; margin-top: 8px; border-top: 1px solid #eee; padding-top: 4px; }
</style>

<h2>ڕاپۆرتی سەردانەکان ('. htmlspecialchars($visitTypeLabel) .') — '. htmlspecialchars($rangeLabel) .'</h2>
<div class="meta">
    ماوە: '. htmlspecialchars($rangeFrom) .' بۆ '. htmlspecialchars($rangeTo) .'
    &nbsp;|&nbsp; بەرواری چاپ: '. $reportGenDate .' '. $reportGenTime .'
    &nbsp;|&nbsp; ئامادەکار: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="stats">
    <tr>
        <td><div class="v">'. number_format($total) .'</div><div class="l">کۆی سەردانەکان · '. (int)$summary['unique_patients'] .' نەخۆش</div></td>
        <td><div class="v v-green">'. number_format($withApt) .'</div><div class="l">مەوعیدیان هەیە · '. $pct($withApt) .'%</div></td>
        <td><div class="v v-red">'. number_format($withoutApt) .'</div><div class="l">بێ مەوعید · '. $pct($withoutApt) .'%</div></td>
        <td><div class="v v-blue">'. number_format($newPts) .'</div><div class="l">نەخۆشی نوێ · '. $pct($newPts) .'%</div></td>
        <td><div class="v v-orange">'. number_format($oldPts) .'</div><div class="l">نەخۆشی کۆن · '. $pct($oldPts) .'%</div></td>
        <td><div class="v">'. number_format((int)$summary['active_doctors']) .'</div><div class="l">دکتۆری چالاک</div></td>
    </tr>
</table>';

$matrixDoctors = $matrixStats['doctors'];
$matrixCell = function($value) {
    $value = (int) $value;
    return $value > 0 ? number_format($value) : '-';
};

if (!empty($matrixDoctors)) {
    $html .= '<h3>ئاماری دکتۆرەکان</h3>';
    $html .= '<table class="matrix"><thead><tr><th>تێبینی</th>';
    foreach ($matrixDoctors as $doctorName) {
        $html .= '<th>' . htmlspecialchars($doctorName) . '</th>';
    }
    $html .= '<th>کۆی گشتی</th></tr></thead><tbody>';

    $doctorMatrixRows = [
        ['label' => 'دکتۆری داواکراو', 'key' => 'requested', 'class' => ''],
        ['label' => 'دکتۆری بینراو', 'key' => 'assigned', 'class' => 'highlight'],
        ['label' => 'نوێ', 'key' => 'new', 'class' => ''],
        ['label' => 'کۆن', 'key' => 'old', 'class' => ''],
    ];

    foreach ($doctorMatrixRows as $rowInfo) {
        $html .= '<tr class="' . $rowInfo['class'] . '"><td class="row-title">' . $rowInfo['label'] . '</td>';
        foreach ($matrixDoctors as $doctorId => $_doctorName) {
            $html .= '<td>' . $matrixCell($matrixStats['doctor_stats'][$doctorId][$rowInfo['key']] ?? 0) . '</td>';
        }
        $totalValue = in_array($rowInfo['key'], ['new', 'old'], true)
            ? ($matrixStats['overall'][$rowInfo['key'] . '_total'] ?? 0)
            : ($matrixStats['doctor_totals'][$rowInfo['key']] ?? 0);
        $html .= '<td class="total-cell">' . number_format((int) $totalValue) . '</td></tr>';
    }

    $html .= '<tr class="grand"><td class="row-title">گواستنەوەی پێشوازی</td>';
    foreach ($matrixDoctors as $doctorId => $_doctorName) {
        $html .= '<td>' . $matrixCell($matrixStats['receptionist_redirection_column_totals'][$doctorId] ?? 0) . '</td>';
    }
    $html .= '<td class="total-cell">' . number_format($matrixStats['receptionist_redirection_total']) . '</td></tr>';
    $html .= '<tr class="grand"><td class="row-title">کۆی گشتی</td>';
    foreach ($matrixDoctors as $doctorId => $_doctorName) {
        $html .= '<td>' . $matrixCell($matrixStats['doctor_stats'][$doctorId]['assigned'] ?? 0) . '</td>';
    }
    $html .= '<td class="total-cell">' . number_format($matrixStats['overall']['total']) . '</td></tr>';
    $html .= '</tbody></table>';
}

if (!empty($matrixStats['receptionist_stats'])) {
    $html .= '<h3>ئاماری کارمەندانی پێشوازی</h3>';
    $html .= '<table class="t"><thead><tr><th>#</th><th>کارمەند</th><th>ژمارەی سەردان</th></tr></thead><tbody>';
    foreach ($matrixStats['receptionist_stats'] as $i => $stat) {
        $html .= '<tr><td>' . ($i + 1) . '</td><td style="text-align:right;">' . htmlspecialchars($stat['name']) . '</td><td class="v-blue">' . number_format((int) $stat['count']) . '</td></tr>';
    }
    $html .= '<tr class="totals-row"><td colspan="2">کۆی گشتی</td><td>' . number_format($matrixStats['receptionist_total']) . '</td></tr>';
    $html .= '</tbody></table>';
}

if (!empty($matrixDoctors) && !empty($matrixStats['appointment_redirections'])) {
    $html .= '<h3>ئاماری گواستنەوەی مەوعیدەکان <span class="small-muted">(' . htmlspecialchars($dateFilterLabel) . ')</span></h3>';
    $html .= '<table class="matrix"><thead><tr><th>دیاریکەر</th>';
    foreach ($matrixDoctors as $doctorName) {
        $html .= '<th>' . htmlspecialchars($doctorName) . '</th>';
    }
    $html .= '<th>کۆی گشتی</th></tr></thead><tbody>';
    foreach ($matrixStats['appointment_redirections'] as $row) {
        $type = $row['type'] === 'doctor' ? 'دکتۆر' : 'کارمەند';
        $html .= '<tr><td class="row-title">' . htmlspecialchars($row['name']) . '<br><span class="small-muted">' . $type . '</span></td>';
        foreach ($matrixDoctors as $doctorId => $_doctorName) {
            $html .= '<td>' . $matrixCell($row['doctors'][$doctorId] ?? 0) . '</td>';
        }
        $html .= '<td class="total-cell">' . number_format((int) $row['total']) . '</td></tr>';
    }
    $html .= '<tr class="grand"><td class="row-title">کۆی گشتی</td>';
    foreach ($matrixDoctors as $doctorId => $_doctorName) {
        $html .= '<td>' . $matrixCell($matrixStats['appointment_redirection_column_totals'][$doctorId] ?? 0) . '</td>';
    }
    $html .= '<td class="total-cell">' . number_format($matrixStats['appointment_redirection_total']) . '</td></tr>';
    $html .= '</tbody></table>';
}

if (!empty($matrixDoctors) && !empty($matrixStats['receptionist_redirections'])) {
    $html .= '<h3>گواستنەوەی نەخۆشان لەلایەن کارمەندی پێشوازییەوە</h3>';
    $html .= '<table class="matrix"><thead><tr><th>کارمەند</th>';
    foreach ($matrixDoctors as $doctorName) {
        $html .= '<th>' . htmlspecialchars($doctorName) . '</th>';
    }
    $html .= '<th>کۆی گشتی</th></tr></thead><tbody>';
    foreach ($matrixStats['receptionist_redirections'] as $row) {
        $html .= '<tr><td class="row-title">' . htmlspecialchars($row['name']) . '</td>';
        foreach ($matrixDoctors as $doctorId => $_doctorName) {
            $html .= '<td>' . $matrixCell($row['doctors'][$doctorId] ?? 0) . '</td>';
        }
        $html .= '<td class="total-cell">' . number_format((int) $row['total']) . '</td></tr>';
    }
    $html .= '<tr class="grand"><td class="row-title">کۆی گشتی</td>';
    foreach ($matrixDoctors as $doctorId => $_doctorName) {
        $html .= '<td>' . $matrixCell($matrixStats['receptionist_redirection_column_totals'][$doctorId] ?? 0) . '</td>';
    }
    $html .= '<td class="total-cell">' . number_format($matrixStats['receptionist_redirection_total']) . '</td></tr>';
    $html .= '</tbody></table>';
}

// Two-column: doctors + source
$html .= '<table class="two-col"><tr><td>';
$html .= '<h3>دکتۆرەکان ('. count($byDoctor) .')</h3>';
if (empty($byDoctor)) {
    $html .= '<div class="empty">هیچ تۆمارێک نییە</div>';
} else {
    $html .= '<table class="t"><thead><tr>
        <th>#</th><th>دکتۆر</th><th>سەردان</th><th>نوێ</th><th>مەوعید</th>
    </tr></thead><tbody>';
    foreach ($byDoctor as $i => $d) {
        $rate = $d['visits'] > 0 ? round($d['with_apt']/$d['visits']*100) : 0;
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;"><strong>'. htmlspecialchars($d['name'] ?? '—') .'</strong></td>
            <td class="v-blue">'. $d['visits'] .'</td>
            <td>'. $d['new_patients'] .'</td>
            <td>'. $d['with_apt'] .' <small>('.$rate.'%)</small></td>
        </tr>';
    }
    $html .= '</tbody></table>';
}
$html .= '</td><td>';
$html .= '<h3>سەرچاوەی هاتن - هەموو ('. count($bySource) .')</h3>';
if (empty($bySource)) {
    $html .= '<div class="empty">هیچ تۆمارێک نییە</div>';
} else {
    $html .= '<table class="t"><thead><tr><th>#</th><th>سەرچاوەی هاتن</th><th>ژمارە</th><th>ڕێژە</th></tr></thead><tbody>';
    foreach ($bySource as $i => $s) {
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;">'. htmlspecialchars($s['source']) .'</td>
            <td><strong>'. $s['c'] .'</strong></td>
            <td>'. $pct($s['c']) .'%</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}
$html .= '</td></tr></table>';

// Procedures
$html .= '<h3>ئیشە باوەکان (Top 10)</h3>';
if (empty($byProcedure)) {
    $html .= '<div class="empty">هیچ تۆمارێک نییە</div>';
} else {
    $html .= '<table class="t"><thead><tr>
        <th style="width:4%">#</th>
        <th style="width:70%">چارەسەر/ئیش</th>
        <th style="width:13%">ژمارە</th>
        <th style="width:13%">ڕێژە</th>
    </tr></thead><tbody>';
    foreach ($byProcedure as $i => $pr) {
        $html .= '<tr>
            <td>'. ($i+1) .'</td>
            <td style="text-align:start;">'. htmlspecialchars($pr['proc']) .'</td>
            <td class="v-blue">'. $pr['c'] .'</td>
            <td>'. $pct($pr['c']) .'%</td>
        </tr>';
    }
    $html .= '</tbody></table>';
}

// Hourly distribution — simple table
if (!empty($hourCounts)) {
    $html .= '<h3>دابەشبوونی کاتژمێر</h3>';
    $html .= '<table class="hour-table"><tr>';
    for ($h = 8; $h <= 21; $h++) { $html .= '<td>'. sprintf('%02d:00', $h) .'</td>'; }
    $html .= '</tr><tr>';
    $maxCount = max($hourCounts ?: [0]);
    for ($h = 8; $h <= 21; $h++) {
        $c = $hourCounts[$h] ?? 0;
        $peak = ($c === $maxCount && $maxCount > 0) ? ' style="background:#bbf7d0;color:#15803d;"' : '';
        $html .= '<td class="h-count"'. $peak .'>'. ($c > 0 ? $c : '-') .'</td>';
    }
    $html .= '</tr></table>';
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
$filename = 'visits-report-' . $rangeFrom . ($rangeFrom !== $rangeTo ? '_' . $rangeTo : '') . '.pdf';
$pdf->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
