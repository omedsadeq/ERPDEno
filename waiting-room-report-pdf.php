<?php
/**
 * Waiting Room Report - PDF
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/services/InvoicePdfService.php';

$db = Database::getInstance();
$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$hasFullAccess = $isAdmin || $isReceptionist;
$enforcedDoctorId = $hasFullAccess ? null : (int)$currentUser['id'];

$hasVisitTypeColumn = !empty($db->fetch("
    SELECT COUNT(*) AS count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'waiting_room'
      AND COLUMN_NAME = 'visit_type'
")['count']);

$mode = $_GET['mode'] ?? 'month';
$doctorFilterId = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '' ? (int)$_GET['doctor_id'] : null;
$fromInput = $_GET['from'] ?? '';
$toInput = $_GET['to'] ?? '';

$activeDoctorId = $enforcedDoctorId ?: $doctorFilterId;
$todayDate = date('Y-m-d');
$monthFrom = date('Y-m-01');
$monthTo = date('Y-m-t');

$rangeFrom = $monthFrom;
$rangeTo = $monthTo;
$rangeLabel = 'ئەم مانگە';

if ($mode === 'today') {
    $rangeFrom = $todayDate;
    $rangeTo = $todayDate;
    $rangeLabel = 'ئەمڕۆ';
} elseif ($mode === 'range' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput)) {
    $rangeFrom = min($fromInput, $toInput);
    $rangeTo = max($fromInput, $toInput);
    $rangeLabel = "بازنەی {$rangeFrom} بۆ {$rangeTo}";
}

$reportVisitTypeExpr = $hasVisitTypeColumn
    ? "COALESCE(wr.visit_type, a.type, 'treatment')"
    : "COALESCE(a.type, 'treatment')";

$normalizeSummary = function(array $row): array {
    $row['total_entries'] = (int)($row['total_entries'] ?? 0);
    $row['completed_entries'] = (int)($row['completed_entries'] ?? 0);
    $row['waiting_entries'] = (int)($row['waiting_entries'] ?? 0);
    $row['in_progress_entries'] = (int)($row['in_progress_entries'] ?? 0);
    $row['on_hold_entries'] = (int)($row['on_hold_entries'] ?? 0);
    $row['rejected_entries'] = (int)($row['rejected_entries'] ?? 0);
    $row['checkup_entries'] = (int)($row['checkup_entries'] ?? 0);
    $row['treatment_entries'] = (int)($row['treatment_entries'] ?? 0);
    $row['avg_completion_minutes'] = (int)round((float)($row['avg_completion_minutes'] ?? 0));
    $row['active_entries'] = $row['waiting_entries'] + $row['in_progress_entries'] + $row['on_hold_entries'];
    return $row;
};

$fetchSummary = function(string $from, string $to) use ($db, $activeDoctorId, $reportVisitTypeExpr, $normalizeSummary): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($activeDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $activeDoctorId;
    }

    $row = $db->fetch("
        SELECT
            COUNT(*) AS total_entries,
            SUM(CASE WHEN wr.status = 'completed' THEN 1 ELSE 0 END) AS completed_entries,
            SUM(CASE WHEN wr.status = 'waiting' THEN 1 ELSE 0 END) AS waiting_entries,
            SUM(CASE WHEN wr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_entries,
            SUM(CASE WHEN wr.status = 'on_hold' THEN 1 ELSE 0 END) AS on_hold_entries,
            SUM(CASE WHEN wr.status IN ('rejected', 'cancelled') THEN 1 ELSE 0 END) AS rejected_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'checkup' THEN 1 ELSE 0 END) AS checkup_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'treatment' THEN 1 ELSE 0 END) AS treatment_entries,
            AVG(CASE
                WHEN wr.status = 'completed' AND wr.check_out_time IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, wr.check_in_time, wr.check_out_time)
                ELSE NULL
            END) AS avg_completion_minutes
        FROM waiting_room wr
        LEFT JOIN appointments a ON wr.appointment_id = a.id
        WHERE {$where}
    ", $params) ?: [];

    return $normalizeSummary($row);
};

$fetchDoctorRows = function(string $from, string $to) use ($db, $activeDoctorId, $reportVisitTypeExpr): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($activeDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $activeDoctorId;
    }

    $rows = $db->fetchAll("
        SELECT
            wr.doctor_id,
            u.full_name AS doctor_name,
            u.room_number AS doctor_room,
            COUNT(*) AS total_entries,
            SUM(CASE WHEN wr.status = 'completed' THEN 1 ELSE 0 END) AS completed_entries,
            SUM(CASE WHEN wr.status = 'waiting' THEN 1 ELSE 0 END) AS waiting_entries,
            SUM(CASE WHEN wr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_entries,
            SUM(CASE WHEN wr.status = 'on_hold' THEN 1 ELSE 0 END) AS on_hold_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'checkup' THEN 1 ELSE 0 END) AS checkup_entries,
            SUM(CASE WHEN {$reportVisitTypeExpr} = 'treatment' THEN 1 ELSE 0 END) AS treatment_entries,
            AVG(CASE
                WHEN wr.status = 'completed' AND wr.check_out_time IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, wr.check_in_time, wr.check_out_time)
                ELSE NULL
            END) AS avg_completion_minutes
        FROM waiting_room wr
        LEFT JOIN users u ON wr.doctor_id = u.id
        LEFT JOIN appointments a ON wr.appointment_id = a.id
        WHERE {$where}
        GROUP BY wr.doctor_id, u.full_name, u.room_number
        ORDER BY completed_entries DESC, total_entries DESC, doctor_name ASC
    ", $params);

    foreach ($rows as &$row) {
        $row['total_entries'] = (int)($row['total_entries'] ?? 0);
        $row['completed_entries'] = (int)($row['completed_entries'] ?? 0);
        $row['waiting_entries'] = (int)($row['waiting_entries'] ?? 0);
        $row['in_progress_entries'] = (int)($row['in_progress_entries'] ?? 0);
        $row['on_hold_entries'] = (int)($row['on_hold_entries'] ?? 0);
        $row['checkup_entries'] = (int)($row['checkup_entries'] ?? 0);
        $row['treatment_entries'] = (int)($row['treatment_entries'] ?? 0);
        $row['avg_completion_minutes'] = (int)round((float)($row['avg_completion_minutes'] ?? 0));
    }
    unset($row);

    return $rows;
};

$fetchDetailRows = function(string $from, string $to) use ($db, $activeDoctorId, $reportVisitTypeExpr): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($activeDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $activeDoctorId;
    }

    return $db->fetchAll("
        SELECT
            wr.id,
            p.full_name AS patient_name,
            u.full_name AS doctor_name,
            u.room_number AS doctor_room,
            wr.status,
            {$reportVisitTypeExpr} AS visit_type_label_key,
            wt.work_name,
            wr.check_in_time,
            wr.called_time,
            wr.check_out_time,
            TIMESTAMPDIFF(
                MINUTE,
                wr.check_in_time,
                COALESCE(
                    wr.called_time,
                    CASE
                        WHEN wr.status IN ('waiting', 'on_hold', 'in_progress') THEN NOW()
                        ELSE wr.check_out_time
                    END,
                    NOW()
                )
            ) AS waiting_room_minutes
        FROM waiting_room wr
        LEFT JOIN patients p ON wr.patient_id = p.id
        LEFT JOIN users u ON wr.doctor_id = u.id
        LEFT JOIN appointments a ON wr.appointment_id = a.id
        LEFT JOIN work_types wt ON wt.id = COALESCE(wr.work_type_id, a.work_type_id)
        WHERE {$where}
        ORDER BY wr.check_in_time DESC
        LIMIT 160
    ", $params);
};

$todaySummary = $fetchSummary($todayDate, $todayDate);
$monthSummary = $fetchSummary($monthFrom, $monthTo);
$rangeSummary = $fetchSummary($rangeFrom, $rangeTo);
$doctorRows = $fetchDoctorRows($rangeFrom, $rangeTo);
$detailRows = $fetchDetailRows($rangeFrom, $rangeTo);

$doctorNameFilter = '';
if ($activeDoctorId) {
    $doctorNameFilter = $db->fetch("SELECT full_name FROM users WHERE id = ?", [$activeDoctorId])['full_name'] ?? '';
}

$statusLabels = [
    'waiting' => 'چاوەڕوان',
    'in_progress' => 'لە ژووری دکتۆردا',
    'on_hold' => 'وەستێنراو',
    'completed' => 'تەواوبوو',
    'rejected' => 'ڕەتکراوە',
    'cancelled' => 'هەڵوەشاوە'
];

$visitTypeLabels = [
    'checkup' => 'پشکنین',
    'treatment' => 'ئیش'
];

$formatReportDateTime = function($value): string {
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime((string)$value);
    return $timestamp ? date('Y-m-d h:i A', $timestamp) : '-';
};

$formatReportMinutes = function($value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    return (int)$value . ' خولەک';
};

$html = '
<style>
body { font-family: nrt, sans-serif; direction: rtl; margin: 0; padding: 0; }
h1 { text-align: center; font-size: 15px; color: #0f172a; margin: 0 0 4px; }
.meta { text-align: center; color: #64748b; font-size: 9px; margin-bottom: 8px; }
.summary { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 10px; }
.summary td { width: 33.33%; vertical-align: top; }
.card { border: 1px solid #dbe5f0; border-radius: 10px; padding: 8px; background: #fff; }
.card h3 { margin: 0 0 6px; font-size: 11px; color: #0f172a; }
.grid { width: 100%; border-collapse: collapse; }
.grid td { border: 1px solid #edf2f7; padding: 6px; text-align: center; }
.grid .v { font-size: 13px; font-weight: 800; color: #111827; }
.grid .l { font-size: 8px; color: #64748b; }
.section-title { margin: 10px 0 5px; font-size: 11px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
table.t { width: 100%; border-collapse: collapse; font-size: 8px; margin-bottom: 8px; }
table.t th { background: #f8fafc; color: #334155; padding: 4px 3px; border: 1px solid #dbe5e9; font-weight: 700; }
table.t td { padding: 4px 3px; border: 1px solid #edf2f7; text-align: center; }
table.t td:first-child, table.t th:first-child { text-align: right; }
table.t tr:nth-child(even) td { background: #fafcff; }
.pill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 8px; font-weight: 700; }
.pill.checkup { background: #e0f2fe; color: #0369a1; }
.pill.treatment { background: #dcfce7; color: #15803d; }
.footer-note { text-align: center; color: #94a3b8; font-size: 8px; margin-top: 8px; }
</style>

<h1>ڕاپۆرتی ژووری چاوەڕوانی</h1>
<div class="meta">
'. htmlspecialchars($rangeLabel) .' | '. htmlspecialchars($rangeFrom) .' - '. htmlspecialchars($rangeTo) .'
'. ($doctorNameFilter !== '' ? ' | دکتۆر: د. ' . htmlspecialchars($doctorNameFilter) : '') .'
 | کاتی چاپ: '. date('Y-m-d H:i') .'
 | ئامادەکراوە لەلایەن: '. htmlspecialchars($currentUser['full_name']) .'
</div>

<table class="summary">
<tr>
<td>
    <div class="card">
        <h3>ئەمڕۆ</h3>
        <table class="grid">
            <tr><td><div class="v">'. $todaySummary['total_entries'] .'</div><div class="l">هاتن</div></td><td><div class="v">'. $todaySummary['completed_entries'] .'</div><div class="l">تەواوبوو</div></td></tr>
            <tr><td><div class="v">'. $todaySummary['checkup_entries'] .'</div><div class="l">پشکنین</div></td><td><div class="v">'. $todaySummary['treatment_entries'] .'</div><div class="l">ئیش</div></td></tr>
        </table>
    </div>
</td>
<td>
    <div class="card">
        <h3>ئەم مانگە</h3>
        <table class="grid">
            <tr><td><div class="v">'. $monthSummary['total_entries'] .'</div><div class="l">هاتن</div></td><td><div class="v">'. $monthSummary['completed_entries'] .'</div><div class="l">تەواوبوو</div></td></tr>
            <tr><td><div class="v">'. $monthSummary['checkup_entries'] .'</div><div class="l">پشکنین</div></td><td><div class="v">'. $monthSummary['treatment_entries'] .'</div><div class="l">ئیش</div></td></tr>
        </table>
    </div>
</td>
<td>
    <div class="card">
        <h3>بازنەی هەڵبژێردراو</h3>
        <table class="grid">
            <tr><td><div class="v">'. $rangeSummary['active_entries'] .'</div><div class="l">چالاک</div></td><td><div class="v">'. $rangeSummary['rejected_entries'] .'</div><div class="l">ڕەتکراوە</div></td></tr>
            <tr><td><div class="v">'. $rangeSummary['in_progress_entries'] .'</div><div class="l">لە ژوور</div></td><td><div class="v">'. $rangeSummary['avg_completion_minutes'] .'</div><div class="l">تێکڕای خولەک</div></td></tr>
        </table>
    </div>
</td>
</tr>
</table>

<div class="section-title">ڕاپۆرتی ژووری دکتۆر</div>
<table class="t">
    <thead>
        <tr>
            <th>دکتۆر</th>
            <th>ژوور</th>
            <th>کۆی هاتن</th>
            <th>تەواوبوو</th>
            <th>چاوەڕوان</th>
            <th>لە ژووری دکتۆردا</th>
            <th>پشکنین</th>
            <th>ئیش</th>
            <th>تێکڕای خولەک</th>
        </tr>
    </thead>
    <tbody>';

if (empty($doctorRows)) {
    $html .= '<tr><td colspan="9">هیچ زانیارییەک نییە</td></tr>';
} else {
    foreach ($doctorRows as $row) {
        $html .= '<tr>
            <td>د. '. htmlspecialchars($row['doctor_name'] ?: 'نادیار') .'</td>
            <td>'. htmlspecialchars($row['doctor_room'] ?: '-') .'</td>
            <td>'. $row['total_entries'] .'</td>
            <td>'. $row['completed_entries'] .'</td>
            <td>'. $row['waiting_entries'] .'</td>
            <td>'. $row['in_progress_entries'] .'</td>
            <td><span class="pill checkup">'. $row['checkup_entries'] .'</span></td>
            <td><span class="pill treatment">'. $row['treatment_entries'] .'</span></td>
            <td>'. $row['avg_completion_minutes'] .'</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<div class="section-title">لیستی وردەکاری</div>
<table class="t">
    <thead>
        <tr>
            <th>نەخۆش</th>
            <th>دکتۆر</th>
            <th>جۆر</th>
            <th>ئیش</th>
            <th>کاتی هاتن</th>
            <th>کاتی مانەوە لە ژووری چاوەڕوانی</th>
            <th>کاتی چوونە ژوورەوە</th>
            <th>کاتی تەواوبوون</th>
            <th>دۆخ</th>
        </tr>
    </thead>
    <tbody>';

if (empty($detailRows)) {
    $html .= '<tr><td colspan="9">هیچ تۆمارێک نەدۆزرایەوە</td></tr>';
} else {
    foreach ($detailRows as $row) {
        $visitTypeKey = $row['visit_type_label_key'] ?: 'treatment';
        $visitTypeLabel = $visitTypeLabels[$visitTypeKey] ?? $visitTypeKey;
        $statusLabel = $statusLabels[$row['status']] ?? $row['status'];
        $html .= '<tr>
            <td>'. htmlspecialchars($row['patient_name'] ?: '-') .'</td>
            <td>د. '. htmlspecialchars($row['doctor_name'] ?: '-') .'</td>
            <td><span class="pill '. ($visitTypeKey === 'checkup' ? 'checkup' : 'treatment') .'">'. htmlspecialchars($visitTypeLabel) .'</span></td>
            <td>'. htmlspecialchars($row['work_name'] ?: '-') .'</td>
            <td>'. htmlspecialchars($formatReportDateTime($row['check_in_time'])) .'</td>
            <td>'. htmlspecialchars($formatReportMinutes($row['waiting_room_minutes'])) .'</td>
            <td>'. htmlspecialchars($formatReportDateTime($row['called_time'])) .'</td>
            <td>'. htmlspecialchars($formatReportDateTime($row['check_out_time'])) .'</td>
            <td>'. htmlspecialchars($statusLabel) .'</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی لە '. htmlspecialchars(APP_NAME) .' دروستکراوە</div>';

$pdf = new InvoicePdfService([
    'orientation' => 'L',
    'format' => 'A4',
    'show_header' => 0,
    'show_footer' => 0,
    'margin_top_mm' => 8,
    'margin_bottom_mm' => 10,
    'margin_left_mm' => 10,
    'margin_right_mm' => 10,
]);
$pdf->setContent($html);

$filename = 'waiting-room-report-' . $rangeFrom . ($rangeFrom !== $rangeTo ? '_' . $rangeTo : '') . '.pdf';
$output = $_GET['output'] ?? 'I';
$pdf->output($filename, $output === 'D' ? 'D' : 'I');
exit;
