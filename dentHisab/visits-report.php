<?php
/**
 * Visits Report
 * ڕاپۆرتی سەردانەکان — ئامار و شیکاری
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/VisitReportStats.php';

$db   = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Doctor isolation: this report aggregates every doctor's visit activity
// (a per-doctor matrix). A doctor must not see other doctors' work — deny.
// Doctors see their own visits, already scoped, on visits.php.
if ($auth->isDoctor() && !$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$today = date('Y-m-d');

// Filters
$mode   = $_GET['mode']  ?? 'today';
$fromIn = $_GET['from']  ?? '';
$toIn   = $_GET['to']    ?? '';
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

// Core aggregate query — totals + breakdowns
$p = ['from' => $rangeFrom, 'to' => $rangeTo, 'visit_type' => $visitType];
$matrixStats = dh_visit_report_stats($db, $rangeFrom, $rangeTo, $dateFilter, $visitType);

// Summary + with/without appointment
$summary = $db->fetch(
    "SELECT
         COUNT(*) AS total_visits,
         SUM(CASE WHEN v.patient_status='new' THEN 1 ELSE 0 END) AS new_patients,
         SUM(CASE WHEN v.patient_status='old' THEN 1 ELSE 0 END) AS old_patients,
         SUM(CASE WHEN v.patient_id IS NOT NULL AND EXISTS (
             SELECT 1 FROM appointments a
             WHERE a.patient_id = v.patient_id
               AND a.appointment_date >= CURDATE()
               AND a.status NOT IN ('cancelled','no_show','completed')
         ) THEN 1 ELSE 0 END) AS with_appointment,
         COUNT(DISTINCT v.patient_id) AS unique_patients,
         COUNT(DISTINCT v.assigned_doctor_id) AS active_doctors
     FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type",
    $p
);
$total  = (int)$summary['total_visits'];
$newPts = (int)$summary['new_patients'];
$oldPts = (int)$summary['old_patients'];
$withApt = (int)$summary['with_appointment'];
$withoutApt = max(0, $total - $withApt);
$uniquePatients = (int)$summary['unique_patients'];
$activeDoctors  = (int)$summary['active_doctors'];

$pct = fn($n) => $total > 0 ? round($n / $total * 100, 1) : 0;

// Per-doctor breakdown
$byDoctor = $db->fetchAll(
    "SELECT d.id, d.name,
            COUNT(v.id) AS visits,
            SUM(CASE WHEN v.patient_status='new' THEN 1 ELSE 0 END) AS new_patients,
            SUM(CASE WHEN v.patient_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM appointments a
                WHERE a.patient_id = v.patient_id
                  AND a.appointment_date >= CURDATE()
                  AND a.status NOT IN ('cancelled','no_show','completed')
            ) THEN 1 ELSE 0 END) AS with_apt
     FROM visits v
     LEFT JOIN doctors d ON v.assigned_doctor_id = d.id
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
     GROUP BY d.id, d.name
     ORDER BY visits DESC", $p);

// Referral source breakdown
$bySource = $db->fetchAll(
    "SELECT COALESCE(NULLIF(TRIM(referral_source), ''), 'نادیار') AS source, COUNT(*) c
     FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
     GROUP BY source ORDER BY c DESC LIMIT 10", $p);

// Hourly distribution (peak hours)
$byHour = $db->fetchAll(
    "SELECT HOUR(arrival_time) h, COUNT(*) c FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
       AND arrival_time IS NOT NULL
     GROUP BY HOUR(arrival_time) ORDER BY h", $p);
$hourCounts = array_fill(8, 14, 0); // cover 8 AM → 21 PM (14 hours)
foreach ($byHour as $r) { $hourCounts[(int)$r['h']] = (int)$r['c']; }
$hourMax = max($hourCounts ?: [0]);

// Top procedures (from procedure_treatment text, simple group)
$byProcedure = $db->fetchAll(
    "SELECT COALESCE(NULLIF(TRIM(procedure_treatment), ''), 'نادیار') AS proc, COUNT(*) c
     FROM visits v
     WHERE v.visit_date BETWEEN :from AND :to
       AND v.visit_type = :visit_type
     GROUP BY proc ORDER BY c DESC LIMIT 10", $p);

$pageTitle = 'ڕاپۆرتی سەردانەکان';
$breadcrumb = [
    ['title' => 'سەردانەکان', 'url' => APP_URL . '/visits.php'],
    ['title' => 'ڕاپۆرت']
];
require_once __DIR__ . '/includes/header.php';
?>

<style>
html { overflow-y: scroll; scrollbar-gutter: stable; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
html::-webkit-scrollbar { width: 10px; }
html::-webkit-scrollbar-track { background: #f1f5f9; }
html::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; border: 2px solid #f1f5f9; }

.vr-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
.vr-header h3 { margin:0; font-size:20px; color:#0f172a; }
.vr-header .sub { color:#607d8b; font-size:13px; }

.vr-filter { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.vr-chip { padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; border-radius:20px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.vr-chip:hover { background:#e2e8f0; color:#0f172a; }
.vr-chip.active { background:#7c3aed; border-color:#7c3aed; color:#fff; }
.vr-sep { width:1px; height:24px; background:#e2e8f0; }
.vr-filter input[type=date] { padding:6px 8px; border:1px solid #cfd8dc; border-radius:6px; font-size:13px; }
.vr-filter label { font-size:12px; color:#607d8b; }
.vr-apply { padding:6px 14px; background:#22c55e; color:#fff; border:1px solid #16a34a; border-radius:6px; cursor:pointer; font-size:13px; }

.vr-stats { display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; margin-bottom:14px; }
.vr-stat { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px; position:relative; overflow:hidden; }
.vr-stat::before { content:''; position:absolute; inset-block:0; inset-inline-end:0; width:4px; background:var(--accent,#0078d4); }
.vr-stat.total  { --accent:#7c3aed; }
.vr-stat.with   { --accent:#16a34a; }
.vr-stat.without{ --accent:#dc2626; }
.vr-stat.new    { --accent:#0ea5e9; }
.vr-stat.old    { --accent:#f59e0b; }
.vr-stat.uniq   { --accent:#0891b2; }
.vr-stat .label { font-size:12px; color:#607d8b; margin-bottom:4px; }
.vr-stat .value { font-size:20px; font-weight:800; color:#0f172a; line-height:1.15; }
.vr-stat.with .value { color:#16a34a; }
.vr-stat.without .value { color:#dc2626; }
.vr-stat .sub { font-size:11px; color:#607d8b; margin-top:4px; }

.vr-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
.vr-card { background:#fff; border:1px solid #e3e8ee; border-radius:10px; overflow:hidden; }
.vr-card .hd { padding:10px 14px; border-bottom:1px solid #eef2f5; font-weight:700; color:#0f172a; font-size:14px; background:linear-gradient(90deg,#f8fafc 0%,#fff 100%); display:flex; justify-content:space-between; align-items:center; }

.ratio-bars { padding: 14px; display:flex; flex-direction:column; gap:10px; }
.ratio-bars .r-row { display:flex; align-items:center; gap:10px; font-size:13px; }
.ratio-bars .r-row .label { width:90px; color:#334155; font-weight:600; }
.ratio-bars .r-row .bar-wrap { flex:1; background:#f1f5f9; border-radius:6px; height:20px; overflow:hidden; }
.ratio-bars .r-row .bar { height:100%; display:flex; align-items:center; padding:0 8px; color:#fff; font-weight:700; font-size:11px; transition:width .4s; }
.ratio-bars .r-row.with    .bar { background:linear-gradient(90deg,#16a34a,#22c55e); }
.ratio-bars .r-row.without .bar { background:linear-gradient(90deg,#dc2626,#ef4444); }
.ratio-bars .r-row.new     .bar { background:linear-gradient(90deg,#0ea5e9,#38bdf8); }
.ratio-bars .r-row.old     .bar { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
.ratio-bars .r-row .value { width:70px; text-align:start; font-weight:700; color:#0f172a; }

.hour-chart { padding:14px; display:flex; align-items:flex-end; gap:3px; height:160px; }
.hour-chart .bar { flex:1; background:linear-gradient(180deg,#7c3aed,#5b21b6); border-radius:4px 4px 0 0; position:relative; min-height:2px; transition:height .4s; }
.hour-chart .bar .h-label { position:absolute; bottom:-18px; inset-inline:0; text-align:center; font-size:9px; color:#94a3b8; }
.hour-chart .bar .c-label { position:absolute; top:-16px; inset-inline:0; text-align:center; font-size:10px; font-weight:700; color:#1e293b; }
.hour-chart .bar.peak { background:linear-gradient(180deg,#16a34a,#15803d); }

.vr-list { max-height:360px; overflow-y:auto; }
.vr-list table { width:100%; border-collapse:collapse; font-size:13px; }
.vr-list th { background:#f7f9fc; color:#37474f; font-weight:600; padding:8px; text-align:center; border-bottom:1px solid #e3e8ee; font-size:12px; position:sticky; top:0; }
.vr-list td { padding:7px 8px; border-bottom:1px solid #eef2f5; text-align:center; }
.vr-list tr:hover td { background:#fafcff; }
.vr-list .name { text-align:start; font-weight:700; color:#0f172a; }
.vr-list .num  { background:rgba(124,58,237,.12); color:#7c3aed; padding:2px 8px; border-radius:10px; font-weight:700; font-size:11px; }
.vr-list .mini-pct { font-size:10px; color:#607d8b; margin-top:1px; }

.vr-wide-card { margin-bottom:14px; }
.vr-matrix-wrap { overflow-x:auto; }
.vr-matrix { width:100%; min-width:760px; border-collapse:collapse; font-size:12px; }
.vr-matrix th { background:#f7f9fc; color:#37474f; font-weight:700; padding:9px 8px; text-align:center; border-bottom:1px solid #e3e8ee; white-space:nowrap; }
.vr-matrix td { padding:8px; border-bottom:1px solid #eef2f5; text-align:center; border-inline-start:1px solid #f1f5f9; }
.vr-matrix tr:hover td { background:#fafcff; }
.vr-matrix .row-title { text-align:start; font-weight:800; color:#0f172a; background:#fbfdff; position:sticky; inset-inline-start:0; z-index:1; }
.vr-matrix .total-cell { font-weight:800; color:#7c3aed; background:#f5f3ff; }
.vr-matrix .highlight td { background:#fff8e6; }
.vr-matrix .grand td { background:#eef7ff; font-weight:800; }
.vr-matrix .muted-zero { color:#cbd5e1; }
.vr-small-note { font-size:11px; color:#64748b; font-weight:400; }

.empty { padding:40px; text-align:center; color:#9aa7b3; font-size:13px; }

@media (max-width: 1200px) { .vr-stats { grid-template-columns:repeat(3,1fr); } .vr-grid { grid-template-columns:1fr; } }
@media (max-width: 640px) {
    .vr-header h3 { font-size:16px; }
    .vr-chip { padding:5px 10px; font-size:12px; }
    .vr-stats { grid-template-columns:repeat(2,1fr); }
    .vr-stat .value { font-size:15px; }
    .vr-stat .label { font-size:11px; }
}
</style>

<?php
$pdfQ = ['mode' => $mode, 'date_filter' => $dateFilter, 'visit_type' => $visitType];
if ($mode === 'range') { $pdfQ['from'] = $rangeFrom; $pdfQ['to'] = $rangeTo; }
$pdfUrl = APP_URL . '/visits-report-pdf.php?' . http_build_query($pdfQ);
?>
<div class="vr-header">
    <div>
        <h3><i class="fas fa-chart-line"></i> ڕاپۆرتی سەردانەکان <span style="font-size:13px; color:#7c3aed; font-weight:600;">(<?= htmlspecialchars($visitTypeLabel) ?>)</span></h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?><?= $rangeFrom !== $rangeTo ? ' بۆ ' . htmlspecialchars($rangeTo) : '' ?></div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn" style="background:#c62828;border-color:#c62828;color:#fff;">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= APP_URL ?>/visits.php" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i> گەڕانەوە
        </a>
    </div>
</div>

<?php
$chips = [
    ['today',     'ئەمڕۆ',        'fa-calendar-day'],
    ['yesterday', 'دوێنێ',        'fa-arrow-right'],
    ['week',      'ئەم هەفتەیە',   'fa-calendar-week'],
    ['month',     'ئەم مانگە',     'fa-calendar-alt'],
];
?>
<form method="GET" action="" class="vr-filter">
    <?php foreach ($chips as [$k, $lbl, $ic]): ?>
    <a href="?<?= http_build_query(['mode' => $k, 'date_filter' => $dateFilter, 'visit_type' => $visitType]) ?>" class="vr-chip <?= $mode === $k ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>

    <span class="vr-sep"></span>

    <label>جۆری نەخۆش</label>
    <a href="?<?= http_build_query(['mode' => $mode, 'date_filter' => $dateFilter, 'from' => $rangeFrom, 'to' => $rangeTo, 'visit_type' => 'checkup']) ?>"
       class="vr-chip <?= $visitType === 'checkup' ? 'active' : '' ?>" style="<?= $visitType === 'checkup' ? 'background:#0ea5e9;border-color:#0ea5e9;' : '' ?>">
        <i class="fas fa-stethoscope"></i> پشکنین
    </a>
    <a href="?<?= http_build_query(['mode' => $mode, 'date_filter' => $dateFilter, 'from' => $rangeFrom, 'to' => $rangeTo, 'visit_type' => 'treatment']) ?>"
       class="vr-chip <?= $visitType === 'treatment' ? 'active' : '' ?>" style="<?= $visitType === 'treatment' ? 'background:#16a34a;border-color:#16a34a;' : '' ?>">
        <i class="fas fa-tooth"></i> ئیش
    </a>

    <span class="vr-sep"></span>

    <input type="hidden" name="mode" value="range">
    <input type="hidden" name="visit_type" value="<?= htmlspecialchars($visitType) ?>">
    <label>ئاماری مەوعید</label>
    <select name="date_filter" style="padding:6px 8px;border:1px solid #cfd8dc;border-radius:6px;font-size:13px;">
        <option value="visit_date" <?= $dateFilter === 'visit_date' ? 'selected' : '' ?>>بەپێی بەرواری سەردان</option>
        <option value="appointment_date" <?= $dateFilter === 'appointment_date' ? 'selected' : '' ?>>بەپێی بەرواری مەوعید</option>
    </select>
    <label>لە</label>
    <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
    <label>بۆ</label>
    <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">

    <button type="submit" class="vr-apply"><i class="fas fa-search"></i> گەڕان</button>
</form>

<div class="vr-stats">
    <div class="vr-stat total">
        <div class="label">کۆی سەردانەکان</div>
        <div class="value"><?= number_format($total) ?></div>
        <div class="sub"><?= $uniquePatients ?> نەخۆشی جیاواز</div>
    </div>
    <div class="vr-stat with">
        <div class="label">مەوعیدیان هەیە</div>
        <div class="value"><?= number_format($withApt) ?></div>
        <div class="sub"><?= $pct($withApt) ?>%</div>
    </div>
    <div class="vr-stat without">
        <div class="label">بێ مەوعید</div>
        <div class="value"><?= number_format($withoutApt) ?></div>
        <div class="sub"><?= $pct($withoutApt) ?>%</div>
    </div>
    <div class="vr-stat new">
        <div class="label">نەخۆشی نوێ</div>
        <div class="value"><?= number_format($newPts) ?></div>
        <div class="sub"><?= $pct($newPts) ?>%</div>
    </div>
    <div class="vr-stat old">
        <div class="label">نەخۆشی کۆن</div>
        <div class="value"><?= number_format($oldPts) ?></div>
        <div class="sub"><?= $pct($oldPts) ?>%</div>
    </div>
    <div class="vr-stat uniq">
        <div class="label">دکتۆری چالاک</div>
        <div class="value"><?= number_format($activeDoctors) ?></div>
        <div class="sub">کارپێکراون</div>
    </div>
</div>

<?php
$matrixDoctors = $matrixStats['doctors'];
$matrixValue = function($value) {
    $value = (int) $value;
    return $value > 0 ? number_format($value) : '<span class="muted-zero">-</span>';
};
?>

<div class="vr-card vr-wide-card">
    <div class="hd">
        <span><i class="fas fa-user-md"></i> ئاماری دکتۆرەکان</span>
        <span class="vr-small-note">داواکراو، بینراو، گواستنەوە، نوێ و کۆن</span>
    </div>
    <?php if (empty($matrixDoctors)): ?>
        <div class="empty">هیچ دکتۆرێک نەدۆزرایەوە</div>
    <?php else: ?>
    <div class="vr-matrix-wrap">
        <table class="vr-matrix">
            <thead>
                <tr>
                    <th>تێبینی</th>
                    <?php foreach ($matrixDoctors as $doctorName): ?>
                        <th><?= htmlspecialchars($doctorName) ?></th>
                    <?php endforeach; ?>
                    <th>کۆی گشتی</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="row-title">دکتۆری داواکراو</td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($matrixStats['doctor_stats'][$doctorId]['requested'] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($matrixStats['doctor_totals']['requested']) ?></td>
                </tr>
                <tr class="highlight">
                    <td class="row-title">دکتۆری بینراو</td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($matrixStats['doctor_stats'][$doctorId]['assigned'] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($matrixStats['doctor_totals']['assigned']) ?></td>
                </tr>
                <tr>
                    <td class="row-title">گواستنەوەی پێشوازی</td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($matrixStats['receptionist_redirection_column_totals'][$doctorId] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($matrixStats['receptionist_redirection_total']) ?></td>
                </tr>
                <tr class="grand">
                    <td class="row-title">کۆی گشتی</td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($matrixStats['doctor_stats'][$doctorId]['assigned'] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($matrixStats['overall']['total']) ?></td>
                </tr>
                <tr>
                    <td class="row-title">نوێ</td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($matrixStats['doctor_stats'][$doctorId]['new'] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($matrixStats['overall']['new_total']) ?></td>
                </tr>
                <tr>
                    <td class="row-title">کۆن</td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($matrixStats['doctor_stats'][$doctorId]['old'] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($matrixStats['overall']['old_total']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="vr-grid">
    <div class="vr-card">
        <div class="hd"><span><i class="fas fa-user-tie"></i> ئاماری کارمەندانی پێشوازی</span></div>
        <?php if (empty($matrixStats['receptionist_stats'])): ?>
            <div class="empty">هیچ تۆمارێک نییە</div>
        <?php else: ?>
        <div class="vr-list">
            <table>
                <thead><tr><th>#</th><th>کارمەند</th><th>ژمارەی سەردان</th></tr></thead>
                <tbody>
                    <?php foreach ($matrixStats['receptionist_stats'] as $i => $stat): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="name"><?= htmlspecialchars($stat['name']) ?></td>
                        <td><span class="num"><?= number_format($stat['count']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td colspan="2" class="name">کۆی گشتی</td>
                        <td><span class="num"><?= number_format($matrixStats['receptionist_total']) ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="vr-card">
        <div class="hd">
            <span><i class="fas fa-calendar-plus"></i> ئاماری گواستنەوەی مەوعیدەکان</span>
            <span class="vr-small-note"><?= htmlspecialchars($dateFilterLabel) ?></span>
        </div>
        <?php if (empty($matrixStats['appointment_redirections'])): ?>
            <div class="empty">هیچ گواستنەوەیەکی مەوعید نییە</div>
        <?php else: ?>
        <div class="vr-matrix-wrap">
            <table class="vr-matrix">
                <thead>
                    <tr>
                        <th>دیاریکەر</th>
                        <?php foreach ($matrixDoctors as $doctorName): ?>
                            <th><?= htmlspecialchars($doctorName) ?></th>
                        <?php endforeach; ?>
                        <th>کۆی گشتی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matrixStats['appointment_redirections'] as $row): ?>
                    <tr>
                        <td class="row-title">
                            <?= htmlspecialchars($row['name']) ?>
                            <div class="mini-pct"><?= $row['type'] === 'doctor' ? 'دکتۆر' : 'کارمەند' ?></div>
                        </td>
                        <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                            <td><?= $matrixValue($row['doctors'][$doctorId] ?? 0) ?></td>
                        <?php endforeach; ?>
                        <td class="total-cell"><?= number_format($row['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="grand">
                        <td class="row-title">کۆی گشتی</td>
                        <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                            <td><?= $matrixValue($matrixStats['appointment_redirection_column_totals'][$doctorId] ?? 0) ?></td>
                        <?php endforeach; ?>
                        <td class="total-cell"><?= number_format($matrixStats['appointment_redirection_total']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($matrixStats['receptionist_redirections'])): ?>
<div class="vr-card vr-wide-card">
    <div class="hd">
        <span><i class="fas fa-random"></i> گواستنەوەی نەخۆشان لەلایەن کارمەندی پێشوازییەوە</span>
    </div>
    <div class="vr-matrix-wrap">
        <table class="vr-matrix">
            <thead>
                <tr>
                    <th>کارمەند</th>
                    <?php foreach ($matrixDoctors as $doctorName): ?>
                        <th><?= htmlspecialchars($doctorName) ?></th>
                    <?php endforeach; ?>
                    <th>کۆی گشتی</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matrixStats['receptionist_redirections'] as $row): ?>
                <tr>
                    <td class="row-title"><?= htmlspecialchars($row['name']) ?></td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($row['doctors'][$doctorId] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($row['total']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="grand">
                    <td class="row-title">کۆی گشتی</td>
                    <?php foreach ($matrixDoctors as $doctorId => $_doctorName): ?>
                        <td><?= $matrixValue($matrixStats['receptionist_redirection_column_totals'][$doctorId] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="total-cell"><?= number_format($matrixStats['receptionist_redirection_total']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="vr-grid">
    <!-- Appointment ratio -->
    <div class="vr-card">
        <div class="hd"><span><i class="fas fa-calendar-check"></i> دابەشبوونی مەوعید</span><span style="font-size:12px; color:#607d8b; font-weight:400;">ڕێژە</span></div>
        <div class="ratio-bars">
            <div class="r-row with">
                <div class="label">مەوعیدیان هەیە</div>
                <div class="bar-wrap"><div class="bar" style="width:<?= max(1, $pct($withApt)) ?>%;"><?= $withApt ?></div></div>
                <div class="value"><?= $pct($withApt) ?>%</div>
            </div>
            <div class="r-row without">
                <div class="label">بێ مەوعید</div>
                <div class="bar-wrap"><div class="bar" style="width:<?= max(1, $pct($withoutApt)) ?>%;"><?= $withoutApt ?></div></div>
                <div class="value"><?= $pct($withoutApt) ?>%</div>
            </div>
            <div class="r-row new">
                <div class="label">نەخۆشی نوێ</div>
                <div class="bar-wrap"><div class="bar" style="width:<?= max(1, $pct($newPts)) ?>%;"><?= $newPts ?></div></div>
                <div class="value"><?= $pct($newPts) ?>%</div>
            </div>
            <div class="r-row old">
                <div class="label">نەخۆشی کۆن</div>
                <div class="bar-wrap"><div class="bar" style="width:<?= max(1, $pct($oldPts)) ?>%;"><?= $oldPts ?></div></div>
                <div class="value"><?= $pct($oldPts) ?>%</div>
            </div>
        </div>
    </div>

    <!-- Hourly distribution -->
    <div class="vr-card">
        <div class="hd"><span><i class="fas fa-clock"></i> دابەشبوونی کاتژمێر — کاتی هاتن</span><span style="font-size:12px; color:#607d8b; font-weight:400;">پەیتای زۆرترین: <?= $hourMax ?></span></div>
        <div class="hour-chart">
            <?php foreach ($hourCounts as $h => $c):
                $pctH = $hourMax > 0 ? round($c / $hourMax * 100) : 0;
                $isPeak = ($c === $hourMax && $hourMax > 0);
            ?>
            <div class="bar <?= $isPeak ? 'peak' : '' ?>" style="height:<?= $pctH ?>%;" title="<?= sprintf('%02d:00', $h) ?> — <?= $c ?>">
                <?php if ($c > 0): ?><span class="c-label"><?= $c ?></span><?php endif; ?>
                <span class="h-label"><?= sprintf('%02d', $h) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="vr-grid">
    <!-- Per doctor -->
    <div class="vr-card">
        <div class="hd"><span><i class="fas fa-user-md"></i> دکتۆرەکان (<?= count($byDoctor) ?>)</span></div>
        <?php if (empty($byDoctor)): ?>
        <div class="empty">هیچ تۆمارێک نییە</div>
        <?php else: ?>
        <div class="vr-list">
            <table>
                <thead><tr><th>#</th><th>دکتۆر</th><th>سەردان</th><th>نوێ</th><th>مەوعید</th></tr></thead>
                <tbody>
                <?php foreach ($byDoctor as $i => $d): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="name"><?= htmlspecialchars($d['name'] ?? '—') ?></td>
                    <td><span class="num"><?= $d['visits'] ?></span></td>
                    <td><?= $d['new_patients'] ?></td>
                    <td><?= $d['with_apt'] ?><?php if ($d['visits'] > 0): ?><div class="mini-pct"><?= round($d['with_apt']/$d['visits']*100) ?>%</div><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Referral source -->
    <div class="vr-card">
        <div class="hd"><span><i class="fas fa-share-alt"></i> سەرچاوەی هاتن (Top 10)</span></div>
        <?php if (empty($bySource)): ?>
        <div class="empty">هیچ تۆمارێک نییە</div>
        <?php else: ?>
        <div class="vr-list">
            <table>
                <thead><tr><th>#</th><th>سەرچاوە</th><th>ژمارە</th><th>ڕێژە</th></tr></thead>
                <tbody>
                <?php foreach ($bySource as $i => $s): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="name"><?= htmlspecialchars($s['source']) ?></td>
                    <td><span class="num"><?= $s['c'] ?></span></td>
                    <td><?= $pct($s['c']) ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Procedures -->
<div class="vr-card" style="margin-bottom:14px;">
    <div class="hd"><span><i class="fas fa-tooth"></i> ئیشە باوەکان (Top 10)</span></div>
    <?php if (empty($byProcedure)): ?>
    <div class="empty">هیچ تۆمارێک نییە</div>
    <?php else: ?>
    <div class="vr-list">
        <table>
            <thead><tr><th style="width:40px;">#</th><th>ئیش/چارەسەر</th><th style="width:120px;">ژمارە</th><th style="width:100px;">ڕێژە</th></tr></thead>
            <tbody>
            <?php foreach ($byProcedure as $i => $pr): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="name"><?= htmlspecialchars($pr['proc']) ?></td>
                <td><span class="num"><?= $pr['c'] ?></span></td>
                <td><?= $pct($pr['c']) ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
