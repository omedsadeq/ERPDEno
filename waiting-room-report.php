<?php
/**
 * Waiting Room Report
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$hasFullAccess = $isAdmin || $isReceptionist;
$doctors = $auth->getDoctors();

$hasVisitTypeColumn = !empty($db->fetch("
    SELECT COUNT(*) AS count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'waiting_room'
      AND COLUMN_NAME = 'visit_type'
")['count']);

$todayDate = date('Y-m-d');
$mode = $_GET['mode'] ?? 'month';
$fromInput = $_GET['from'] ?? '';
$toInput = $_GET['to'] ?? '';
$doctorInput = $_GET['doctor_id'] ?? '';
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = date('Y-m-01');
$rangeTo = date('Y-m-t');
$rangeLabel = 'ئەم مانگە';
if ($mode === 'today') {
    $rangeFrom = $todayDate;
    $rangeTo = $todayDate;
    $rangeLabel = 'ئەمڕۆ';
} elseif ($mode === 'range' && $validDate($fromInput) && $validDate($toInput)) {
    $rangeFrom = min($fromInput, $toInput);
    $rangeTo = max($fromInput, $toInput);
    $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
}

$selectedDoctorId = null;
if (!$hasFullAccess) {
    $selectedDoctorId = (int)$currentUser['id'];
} elseif ($doctorInput !== '' && ctype_digit((string)$doctorInput)) {
    $selectedDoctorId = (int)$doctorInput;
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

$fetchSummary = function(string $from, string $to) use ($db, $selectedDoctorId, $reportVisitTypeExpr, $normalizeSummary): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($selectedDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $selectedDoctorId;
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

$fetchDoctorRows = function(string $from, string $to) use ($db, $selectedDoctorId, $reportVisitTypeExpr): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($selectedDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $selectedDoctorId;
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

$fetchDetailRows = function(string $from, string $to) use ($db, $selectedDoctorId, $reportVisitTypeExpr): array {
    $where = "DATE(wr.check_in_time) BETWEEN :from AND :to";
    $params = ['from' => $from, 'to' => $to];
    if ($selectedDoctorId) {
        $where .= " AND wr.doctor_id = :doctor_id";
        $params['doctor_id'] = $selectedDoctorId;
    }

    return $db->fetchAll("
        SELECT
            wr.id,
            p.full_name AS patient_name,
            u.full_name AS doctor_name,
            u.room_number AS doctor_room,
            wr.status,
            {$reportVisitTypeExpr} AS visit_type_key,
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
        LIMIT 200
    ", $params);
};

$todaySummary = $fetchSummary($todayDate, $todayDate);
$monthSummary = $fetchSummary(date('Y-m-01'), date('Y-m-t'));
$rangeSummary = $fetchSummary($rangeFrom, $rangeTo);
$doctorRows = $fetchDoctorRows($rangeFrom, $rangeTo);
$detailRows = $fetchDetailRows($rangeFrom, $rangeTo);

$doctorNameFilter = '';
if ($selectedDoctorId) {
    $doctorNameFilter = $db->fetch("SELECT full_name FROM users WHERE id = ?", [$selectedDoctorId])['full_name'] ?? '';
}

$visitTypeLabels = ['checkup' => 'پشکنین', 'treatment' => 'ئیش'];
$statusLabels = [
    'waiting' => 'چاوەڕوان',
    'in_progress' => 'لە ژووری دکتۆردا',
    'on_hold' => 'وەستێنراو',
    'completed' => 'تەواوبوو',
    'rejected' => 'ڕەتکراوە',
    'cancelled' => 'هەڵوەشاوە'
];

$pageTitle = 'ڕاپۆرتی ژووری چاوەڕوانی';
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

$breadcrumb = [
    ['title' => 'ژووری چاوەڕوانی', 'url' => APP_URL . '/waiting-room.php'],
    ['title' => 'ڕاپۆرت']
];
require_once __DIR__ . '/includes/header.php';

$pdfParams = ['mode' => $mode];
if ($mode === 'range') {
    $pdfParams['from'] = $rangeFrom;
    $pdfParams['to'] = $rangeTo;
}
if ($selectedDoctorId) {
    $pdfParams['doctor_id'] = $selectedDoctorId;
}
$pdfUrl = APP_URL . '/waiting-room-report-pdf.php?' . http_build_query($pdfParams);
?>

<style>
.wrr-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
.wrr-header h3 { margin:0; font-size:20px; color:#0f172a; }
.wrr-header .sub { color:#607d8b; font-size:13px; }

.wrr-filter { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.wrr-chip { padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; border-radius:20px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.wrr-chip:hover { background:#e2e8f0; color:#0f172a; }
.wrr-chip.active { background:var(--win-accent, #0078d4); border-color:var(--win-accent, #0078d4); color:#fff; }
.wrr-sep { width:1px; height:24px; background:#e2e8f0; }
.wrr-filter input[type=date], .wrr-filter select { padding:6px 8px; border:1px solid #cfd8dc; border-radius:6px; font-size:13px; min-width:140px; }
.wrr-filter label { font-size:12px; color:#607d8b; }
.wrr-apply { padding:6px 14px; background:#22c55e; color:#fff; border:1px solid #16a34a; border-radius:6px; cursor:pointer; font-size:13px; font-family:inherit; }

.wrr-stats { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:14px; }
.wrr-stat { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px; position:relative; overflow:hidden; }
.wrr-stat::before { content:''; position:absolute; inset-block:0; inset-inline-end:0; width:4px; background:var(--accent, #0078d4); }
.wrr-stat.total { --accent:#7c3aed; }
.wrr-stat.complete { --accent:#16a34a; }
.wrr-stat.checkup { --accent:#0ea5e9; }
.wrr-stat.treatment { --accent:#f59e0b; }
.wrr-stat .label { font-size:12px; color:#607d8b; margin-bottom:4px; }
.wrr-stat .value { font-size:20px; font-weight:800; color:#0f172a; line-height:1.15; }
.wrr-stat .sub { font-size:11px; color:#607d8b; margin-top:4px; }

.wrr-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:14px; }
.wrr-card { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px; }
.wrr-card-title { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:10px; display:flex; justify-content:space-between; gap:8px; }
.wrr-card-sub { font-size:11px; color:#64748b; }
.wrr-metrics { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
.wrr-metric { background:#f8fafc; border:1px solid #eef2f7; border-radius:8px; padding:10px; }
.wrr-metric-value { font-size:18px; font-weight:800; color:#0f172a; line-height:1; margin-bottom:4px; }
.wrr-metric-label { font-size:11px; color:#64748b; font-weight:600; }

.wrr-card.full { padding:0; overflow:hidden; }
.wrr-card .hd { padding:10px 14px; border-bottom:1px solid #eef2f5; font-weight:700; color:#0f172a; font-size:14px; background:linear-gradient(90deg,#f8fafc 0%,#fff 100%); }
.wrr-table-wrap { overflow:auto; }
.wrr-table { width:100%; border-collapse:collapse; font-size:12px; }
.wrr-table th { background:#f7f9fc; color:#37474f; font-weight:600; padding:8px; text-align:center; border-bottom:1px solid #e3e8ee; white-space:nowrap; }
.wrr-table td { padding:8px; border-bottom:1px solid #eef2f5; text-align:center; }
.wrr-table td:first-child, .wrr-table th:first-child { text-align:right; }
.wrr-table tr:hover td { background:#fafcff; }
.wrr-pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.wrr-pill.checkup { background:#e0f2fe; color:#0369a1; }
.wrr-pill.treatment { background:#dcfce7; color:#15803d; }

@media (max-width: 1200px) { .wrr-grid { grid-template-columns:1fr; } }
@media (max-width: 900px) { .wrr-stats { grid-template-columns:repeat(2, 1fr); } }
@media (max-width: 640px) { .wrr-stats { grid-template-columns:1fr; } }
</style>

<?php
$chips = [
    ['today', 'ئەمڕۆ', 'fa-calendar-day'],
    ['month', 'ئەم مانگە', 'fa-calendar-alt'],
];
?>

<div class="wrr-header">
    <div>
        <h3><i class="fas fa-chart-line"></i> ڕاپۆرتی ژووری چاوەڕوانی</h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?><?= $rangeFrom !== $rangeTo ? ' بۆ ' . htmlspecialchars($rangeTo) : '' ?><?= $doctorNameFilter !== '' ? ' | د. ' . htmlspecialchars($doctorNameFilter) : '' ?></div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn" style="background:#c62828;border-color:#c62828;color:#fff;">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= APP_URL ?>/waiting-room.php" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i> گەڕانەوە
        </a>
    </div>
</div>

<form method="GET" action="" class="wrr-filter">
    <?php foreach ($chips as [$key, $label, $icon]): ?>
    <a href="?mode=<?= $key ?><?= $selectedDoctorId ? '&doctor_id=' . $selectedDoctorId : '' ?>" class="wrr-chip <?= $mode === $key ? 'active' : '' ?>">
        <i class="fas <?= $icon ?>"></i> <?= $label ?>
    </a>
    <?php endforeach; ?>

    <span class="wrr-sep"></span>
    <input type="hidden" name="mode" value="range">
    <label>لە</label>
    <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
    <label>بۆ</label>
    <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">

    <?php if ($hasFullAccess): ?>
    <label>دکتۆر</label>
    <select name="doctor_id">
        <option value="">هەموو دکتۆرەکان</option>
        <?php foreach ($doctors as $doctor): ?>
        <option value="<?= $doctor['id'] ?>" <?= $selectedDoctorId === (int)$doctor['id'] ? 'selected' : '' ?>>
            د. <?= htmlspecialchars($doctor['full_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <button type="submit" class="wrr-apply"><i class="fas fa-search"></i> پیشاندان</button>
</form>

<div class="wrr-stats">
    <div class="wrr-stat total">
        <div class="label">هاتنە ژووری چاوەڕوانی</div>
        <div class="value"><?= $rangeSummary['total_entries'] ?></div>
        <div class="sub">لە بازنەی هەڵبژێردراودا</div>
    </div>
    <div class="wrr-stat complete">
        <div class="label">تەواوبوو لە ژووری دکتۆر</div>
        <div class="value"><?= $rangeSummary['completed_entries'] ?></div>
        <div class="sub"><?= $rangeSummary['avg_completion_minutes'] ?> خولەک تێکڕا</div>
    </div>
    <div class="wrr-stat checkup">
        <div class="label">پشکنین</div>
        <div class="value"><?= $rangeSummary['checkup_entries'] ?></div>
        <div class="sub">جۆری یەکەم</div>
    </div>
    <div class="wrr-stat treatment">
        <div class="label">ئیش</div>
        <div class="value"><?= $rangeSummary['treatment_entries'] ?></div>
        <div class="sub">جۆری دووەم</div>
    </div>
</div>

<div class="wrr-grid">
    <div class="wrr-card">
        <div class="wrr-card-title">
            <span><i class="fas fa-sun"></i> ئەمڕۆ</span>
            <span class="wrr-card-sub"><?= htmlspecialchars($todayDate) ?></span>
        </div>
        <div class="wrr-metrics">
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $todaySummary['total_entries'] ?></div><div class="wrr-metric-label">هاتن</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $todaySummary['completed_entries'] ?></div><div class="wrr-metric-label">تەواوبوو</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $todaySummary['checkup_entries'] ?></div><div class="wrr-metric-label">پشکنین</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $todaySummary['treatment_entries'] ?></div><div class="wrr-metric-label">ئیش</div></div>
        </div>
    </div>

    <div class="wrr-card">
        <div class="wrr-card-title">
            <span><i class="fas fa-calendar-alt"></i> ئەم مانگە</span>
            <span class="wrr-card-sub"><?= date('Y-m-01') ?> - <?= date('Y-m-t') ?></span>
        </div>
        <div class="wrr-metrics">
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $monthSummary['total_entries'] ?></div><div class="wrr-metric-label">کۆی هاتن</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $monthSummary['completed_entries'] ?></div><div class="wrr-metric-label">تەواوبوو</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $monthSummary['checkup_entries'] ?></div><div class="wrr-metric-label">پشکنین</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $monthSummary['treatment_entries'] ?></div><div class="wrr-metric-label">ئیش</div></div>
        </div>
    </div>

    <div class="wrr-card">
        <div class="wrr-card-title">
            <span><i class="fas fa-layer-group"></i> بازنەی هەڵبژێردراو</span>
            <span class="wrr-card-sub"><?= htmlspecialchars($rangeLabel) ?></span>
        </div>
        <div class="wrr-metrics">
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $rangeSummary['waiting_entries'] ?></div><div class="wrr-metric-label">چاوەڕوان</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $rangeSummary['in_progress_entries'] ?></div><div class="wrr-metric-label">لە ژووری دکتۆردا</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $rangeSummary['on_hold_entries'] ?></div><div class="wrr-metric-label">وەستێنراو</div></div>
            <div class="wrr-metric"><div class="wrr-metric-value"><?= $rangeSummary['rejected_entries'] ?></div><div class="wrr-metric-label">ڕەتکراوە/هەڵوەشاوە</div></div>
        </div>
    </div>
</div>

<div class="wrr-card full" style="margin-bottom:14px;">
    <div class="hd">ڕاپۆرتی ژووری دکتۆر</div>
    <div class="wrr-table-wrap">
        <table class="wrr-table">
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
            <tbody>
                <?php if (empty($doctorRows)): ?>
                <tr><td colspan="9">هیچ زانیارییەک نییە</td></tr>
                <?php else: ?>
                <?php foreach ($doctorRows as $row): ?>
                <tr>
                    <td>د. <?= htmlspecialchars($row['doctor_name'] ?: 'نادیار') ?></td>
                    <td><?= htmlspecialchars($row['doctor_room'] ?: '-') ?></td>
                    <td><?= $row['total_entries'] ?></td>
                    <td><?= $row['completed_entries'] ?></td>
                    <td><?= $row['waiting_entries'] ?></td>
                    <td><?= $row['in_progress_entries'] ?></td>
                    <td><span class="wrr-pill checkup"><?= $row['checkup_entries'] ?></span></td>
                    <td><span class="wrr-pill treatment"><?= $row['treatment_entries'] ?></span></td>
                    <td><?= $row['avg_completion_minutes'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="wrr-card full">
    <div class="hd">لیستی وردەکاری</div>
    <div class="wrr-table-wrap">
        <table class="wrr-table">
            <thead>
                <tr>
                    <th>نەخۆش</th>
                    <th>دکتۆر</th>
                    <th>ژوور</th>
                    <th>جۆر</th>
                    <th>ئیش</th>
                    <th>کاتی هاتن</th>
                    <th>کاتی مانەوە لە ژووری چاوەڕوانی</th>
                    <th>کاتی چوونە ژوورەوە</th>
                    <th>کاتی تەواوبوون</th>
                    <th>دۆخ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detailRows)): ?>
                <tr><td colspan="10">هیچ تۆمارێک نەدۆزرایەوە</td></tr>
                <?php else: ?>
                <?php foreach ($detailRows as $row): ?>
                <?php $visitTypeKey = $row['visit_type_key'] ?: 'treatment'; ?>
                <tr>
                    <td><?= htmlspecialchars($row['patient_name'] ?: '-') ?></td>
                    <td>د. <?= htmlspecialchars($row['doctor_name'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($row['doctor_room'] ?: '-') ?></td>
                    <td><span class="wrr-pill <?= $visitTypeKey === 'checkup' ? 'checkup' : 'treatment' ?>"><?= htmlspecialchars($visitTypeLabels[$visitTypeKey] ?? $visitTypeKey) ?></span></td>
                    <td><?= htmlspecialchars($row['work_name'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($formatReportDateTime($row['check_in_time'])) ?></td>
                    <td><?= htmlspecialchars($formatReportMinutes($row['waiting_room_minutes'])) ?></td>
                    <td><?= htmlspecialchars($formatReportDateTime($row['called_time'])) ?></td>
                    <td><?= htmlspecialchars($formatReportDateTime($row['check_out_time'])) ?></td>
                    <td><?= htmlspecialchars($statusLabels[$row['status']] ?? $row['status']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
