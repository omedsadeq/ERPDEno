<?php
/**
 * Visits Report — PDF
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/services/InvoicePdfService.php';
require_once __DIR__ . '/includes/VisitReportStats.php';
require_once __DIR__ . '/includes/VisitReportFilters.php';
require_once __DIR__ . '/includes/VisitReferralReport.php';
require_once __DIR__ . '/includes/VisitReportLetterhead.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();
if ($auth->isDoctor() && !$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$currentUser = $auth->getCurrentUser();

$filters = dh_visit_report_filters($_GET);
$mode = $filters['mode'];
$reportMonth = $filters['month'];
$rangeFrom = $filters['from'];
$rangeTo = $filters['to'];
$rangeLabel = $filters['label'];
$dateFilter = $filters['date_filter'];
$visitType = $filters['visit_type'];
$visitTypeLabel = $visitType === 'treatment' ? 'ئیش' : 'پشکنین';
$reportPeriod = $mode === 'month' ? $reportMonth : ($rangeFrom === $rangeTo ? $rangeFrom : $rangeFrom . ' — ' . $rangeTo);
$reportLetterhead = dh_visit_report_letterhead($db, true);
if ($filters['tab'] === 'referrals') {
    $referralReport = dh_visit_referral_report($db, $filters);
    $html = '<style>body { font-family: nrt, sans-serif; direction: rtl; }'
        . file_get_contents(__DIR__ . '/assets/css/visit-report.css')
        . '.vr-photo-table { font-size: 11px; } .vr-photo-table th, .vr-photo-table td { padding: 9px 5px; }'
        . '.vr-photo-table .vr-photo-title { font-size: 14px; } .vr-photo-table .vr-photo-total { font-size: 22px; }'
        . '.vr-referral-report .vr-referral-overview .vr-photo-title { padding: 12px 6px; }'
        . '.vr-referral-report .vr-referral-overview .vr-referral-summary td { padding: 12px 4px 24px; font-size: 16px; }'
        . '.vr-referral-report .vr-referral-overview .vr-referral-big-total { font-size: 46px; padding: 12px; }'
        . '.vr-referral-report .vr-referral-breakdown .vr-photo-total { font-size: 24px; }'
        . '.vr-referral-report .vr-referral-breakdown thead .vr-photo-total { font-size: 11px; }'
        . '.vr-photo-note { font-size: 9px; } .vr-referral-prepared { font-size: 11px; }</style>'
        . (count($referralReport['doctors']) > 6
            ? '<style>.vr-referral-report .vr-referral-breakdown th, .vr-referral-report .vr-referral-breakdown td { padding: 5px 4px; }'
                . '.vr-referral-report .vr-referral-breakdown .vr-photo-total { font-size: 20px; }'
                . '.vr-referral-report .vr-referral-breakdown thead .vr-photo-total { font-size: 11px; }'
                . '.vr-referral-report .vr-referral-overview .vr-referral-summary td { padding: 10px 4px 14px; }'
                . '.vr-referral-report .vr-referral-overview .vr-referral-big-total { font-size: 36px; padding: 10px; }</style>'
            : '')
        . dh_visit_referral_render($referralReport, $reportPeriod, $currentUser['full_name'] ?? '');
    $pdf = new InvoicePdfService([
        'orientation' => count($referralReport['doctors']) <= 6 ? 'P' : 'L', 'format' => 'A4',
        'show_header' => 1, 'header_html' => $reportLetterhead, 'show_footer' => 0,
        'margin_top_mm' => 40, 'margin_bottom_mm' => 10,
        'margin_left_mm' => 10, 'margin_right_mm' => 10,
    ]);
    $pdf->setContent($html);
    $output = $_GET['output'] ?? 'I';
    $filename = 'visit-referrals-' . $referralReport['examiner_id'] . '-' . $rangeFrom . '_' . $rangeTo . '.pdf';
    $pdf->output($filename, in_array($output, ['I', 'D', 'F'], true) ? $output : 'I');
    exit;
}
$p = ['from' => $rangeFrom, 'to' => $rangeTo, 'visit_type' => $visitType];
$matrixStats = dh_visit_report_stats($db, $rangeFrom, $rangeTo, $dateFilter, $visitType);
$reportDoctorSql = dh_visit_report_doctor_sql($visitType);

$summary = $db->fetch(
    "SELECT COUNT(*) AS total_visits
     FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type", $p);
$total  = (int)$summary['total_visits'];
$pct = fn($n) => $total > 0 ? round($n / $total * 100, 1) : 0;

$byDoctor = $db->fetchAll(
    "SELECT d.name, COUNT(v.id) AS visits,
            SUM(CASE WHEN v.patient_status='new' THEN 1 ELSE 0 END) AS new_patients,
            SUM(CASE WHEN v.patient_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM appointments a WHERE a.patient_id=v.patient_id
                  AND a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelled','no_show','completed')
            ) THEN 1 ELSE 0 END) AS with_apt
     FROM visits v LEFT JOIN doctors d ON {$reportDoctorSql}=d.id
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
    .two-col { width: 100%; border-collapse: collapse; }
    .two-col > tbody > tr > td { padding: 0 4px; vertical-align: top; width: 50%; }
    table.t { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 5px; }
    table.t th { background: #eef2f7; padding: 4px; text-align: center; border: 1px solid #c9d1db; font-weight: 600; font-size: 9px; }
    table.t td { padding: 3px; border: 1px solid #e5e7eb; text-align: center; }
    table.t tr:nth-child(even) td { background: #fafafa; }
    .totals-row td { background: #eef2f7 !important; font-weight: bold; }
    .empty { text-align: center; color: #999; font-size: 9px; font-style: italic; padding: 6px; }
    .v-blue  { color: ' . Branding::primaryColor() . '; font-weight: bold; }
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
</div>';

$matrixDoctors = dh_visit_report_visible_doctors($matrixStats['doctors']);

$photoHtml = '<style>' . file_get_contents(__DIR__ . '/assets/css/visit-report.css') . '
.vr-photo-table { font-size: 10px; }
.vr-photo-table th, .vr-photo-table td { padding: 7px 4px; }
.vr-photo-table .vr-photo-title { font-size: 15px; padding: 10px; }
.vr-photo-table .vr-photo-total { font-size: 18px; }
.vr-photo-table .vr-photo-section { font-size: 12px; padding: 9px; }
.vr-photo-note { font-size: 9px; }
</style>';
$photoHtml .= dh_visit_report_render_matrix($matrixStats, $reportPeriod, $visitTypeLabel);
$photoHtml .= dh_visit_report_matrix_note($matrixStats);
$html .= $photoHtml;
if (!empty($matrixStats['receptionist_stats'])) {
    $html .= '<h3>سەردانە تۆمارکراوەکان بەپێی کارمەندی پێشوازی</h3>';
    $html .= '<table class="t"><thead><tr><th>#</th><th>کارمەند</th><th>ژمارەی سەردان</th></tr></thead><tbody>';
    foreach ($matrixStats['receptionist_stats'] as $i => $stat) {
        $html .= '<tr><td>' . ($i + 1) . '</td><td style="text-align:right;">' . htmlspecialchars($stat['name']) . '</td><td class="v-blue">' . number_format((int) $stat['count']) . '</td></tr>';
    }
    $html .= '<tr class="totals-row"><td colspan="2">کۆی گشتی</td><td>' . number_format($matrixStats['receptionist_total']) . '</td></tr>';
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

$summaryOnly = ($_GET['layout'] ?? '') === 'summary';
if ($summaryOnly) {
    $html = '<style>body { font-family: nrt, sans-serif; direction: rtl; }</style>' . $photoHtml;
}

$pdf = new InvoicePdfService([
    'orientation'      => $summaryOnly && count($matrixDoctors) <= 6 ? 'P' : 'L',
    'format'           => 'A4',
    'show_header'      => 1,
    'header_html'      => $reportLetterhead,
    'show_footer'      => 0,
    'margin_top_mm'    => 40,
    'margin_bottom_mm' => 10,
    'margin_left_mm'   => 10,
    'margin_right_mm'  => 10,
]);
$pdf->setContent($html);

$output = $_GET['output'] ?? 'I';
$filename = 'visits-report-' . $rangeFrom . ($rangeFrom !== $rangeTo ? '_' . $rangeTo : '') . '.pdf';
$pdf->output($filename, in_array($output, ['I','D','F'], true) ? $output : 'I');
