<?php
/**
 * Visits Report
 * ڕاپۆرتی سەردانەکان — ئامار و شیکاری
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/VisitReportStats.php';
require_once __DIR__ . '/includes/VisitReportFilters.php';
require_once __DIR__ . '/includes/VisitReferralReport.php';
require_once __DIR__ . '/includes/VisitReportLetterhead.php';

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
$filters = dh_visit_report_filters($_GET);
$mode = $filters['mode'];
$reportMonth = $filters['month'];
$rangeFrom = $filters['from'];
$rangeTo = $filters['to'];
$rangeLabel = $filters['label'];
$dateFilter = $filters['date_filter'];
$dateFilterLabel = $dateFilter === 'appointment_date' ? 'بەرواری مەوعید' : 'بەرواری سەردان';
$visitType = $filters['visit_type'];
$visitTypeLabel = $visitType === 'treatment' ? 'ئیش' : 'پشکنین';
$reportTab = $filters['tab'];
$referralReport = $reportTab === 'referrals' ? dh_visit_referral_report($db, $filters) : null;
$examinerId = $referralReport['examiner_id'] ?? $filters['examiner_id'];
$reportPeriod = $mode === 'month' ? $reportMonth : ($rangeFrom === $rangeTo ? $rangeFrom : $rangeFrom . ' — ' . $rangeTo);
$p = ['from' => $rangeFrom, 'to' => $rangeTo, 'visit_type' => $visitType];
$matrixStats = dh_visit_report_stats($db, $rangeFrom, $rangeTo, $dateFilter, $visitType);
$reportDoctorSql = dh_visit_report_doctor_sql($visitType);

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
         ) THEN 1 ELSE 0 END) AS with_appointment
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
     LEFT JOIN doctors d ON {$reportDoctorSql} = d.id
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

<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/visit-report.css?v=20261020d">
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
.vr-small-note { font-size:11px; color:#64748b; font-weight:400; }

.empty { padding:40px; text-align:center; color:#9aa7b3; font-size:13px; }

@media (max-width: 1200px) { .vr-grid { grid-template-columns:1fr; } }
@media (max-width: 640px) {
    .vr-header h3 { font-size:16px; }
    .vr-chip { padding:5px 10px; font-size:12px; }
}
</style>

<?php
$reportQuery = ['mode' => $mode, 'from' => $rangeFrom, 'to' => $rangeTo, 'month' => $reportMonth,
    'date_filter' => $dateFilter, 'visit_type' => $visitType, 'tab' => $reportTab, 'examiner_id' => $examinerId];
$pdfQ = $reportQuery;
$pdfUrl = APP_URL . '/visits-report-pdf.php?' . http_build_query($pdfQ);
$summaryPdfUrl = $pdfUrl . '&layout=summary';
?>
<div class="vr-header">
    <div>
        <h3><i class="fas fa-chart-line"></i> <?= $reportTab === 'referrals' ? 'ڕاپۆرتی ناردن بۆ ئیش' : 'ڕاپۆرتی سەردانەکان' ?> <span style="font-size:13px; color:#7c3aed; font-weight:600;">(<?= $reportTab === 'referrals' ? 'لە پشکنینەوە بۆ ئیش' : htmlspecialchars($visitTypeLabel) ?>)</span></h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?><?= $rangeFrom !== $rangeTo ? ' بۆ ' . htmlspecialchars($rangeTo) : '' ?></div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($summaryPdfUrl) ?>" target="_blank" rel="noopener" class="btn" style="background:#3768a1;border-color:#3768a1;color:#fff;">
            <i class="fas fa-file-pdf"></i> پوختەی PDF
        </a>
        <?php if ($reportTab === 'visits'): ?>
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" rel="noopener" class="btn" style="background:#c62828;border-color:#c62828;color:#fff;">
            <i class="fas fa-file-pdf"></i> PDFی تەواو
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/visits.php" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i> گەڕانەوە
        </a>
    </div>
</div>

<nav class="vr-report-tabs" aria-label="جۆری ڕاپۆرت">
    <a href="?<?= htmlspecialchars(http_build_query(array_merge($reportQuery, ['tab' => 'visits']))) ?>" <?= $reportTab === 'visits' ? 'aria-current="page"' : '' ?>><i class="fas fa-chart-bar"></i> ئاماری سەردانەکان</a>
    <a href="?<?= htmlspecialchars(http_build_query(array_merge($reportQuery, ['tab' => 'referrals']))) ?>" <?= $reportTab === 'referrals' ? 'aria-current="page"' : '' ?>><i class="fas fa-share-alt"></i> لە پشکنینەوە بۆ ئیش</a>
</nav>

<form method="GET" class="vr-month-filter">
    <input type="hidden" name="mode" value="month">
    <input type="hidden" name="visit_type" value="<?= htmlspecialchars($visitType) ?>">
    <input type="hidden" name="date_filter" value="<?= htmlspecialchars($dateFilter) ?>">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($reportTab) ?>">
    <input type="hidden" name="examiner_id" value="<?= (int) $examinerId ?>">
    <label for="report-month">ڕاپۆرتی مانگانە</label>
    <input type="month" id="report-month" name="month" value="<?= htmlspecialchars($reportMonth) ?>" required>
    <button type="submit" class="vr-apply"><i class="fas fa-calendar-alt"></i> پیشاندانی مانگ</button>
</form>

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
    <a href="?<?= htmlspecialchars(http_build_query(array_merge($reportQuery, ['mode' => $k, 'month' => $k === 'month' ? date('Y-m') : $reportMonth]))) ?>" class="vr-chip <?= $mode === $k && ($k !== 'month' || $reportMonth === date('Y-m')) ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>

    <?php if ($reportTab === 'visits'): ?>
    <span class="vr-sep"></span>
    <label>جۆری نەخۆش</label>
    <a href="?<?= htmlspecialchars(http_build_query(array_merge($reportQuery, ['visit_type' => 'checkup']))) ?>"
       class="vr-chip <?= $visitType === 'checkup' ? 'active' : '' ?>" style="<?= $visitType === 'checkup' ? 'background:#0ea5e9;border-color:#0ea5e9;' : '' ?>">
        <i class="fas fa-stethoscope"></i> پشکنین
    </a>
    <a href="?<?= htmlspecialchars(http_build_query(array_merge($reportQuery, ['visit_type' => 'treatment']))) ?>"
       class="vr-chip <?= $visitType === 'treatment' ? 'active' : '' ?>" style="<?= $visitType === 'treatment' ? 'background:#16a34a;border-color:#16a34a;' : '' ?>">
        <i class="fas fa-tooth"></i> ئیش
    </a>
    <?php endif; ?>

    <span class="vr-sep"></span>

    <input type="hidden" name="mode" value="range">
    <input type="hidden" name="visit_type" value="<?= htmlspecialchars($visitType) ?>">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($reportTab) ?>">
    <input type="hidden" name="examiner_id" value="<?= (int) $examinerId ?>">
    <label><?= $reportTab === 'referrals' ? 'بەرواری ڕاپۆرتی ناردن' : 'بەرواری خشتەی مەوعیدەکان' ?></label>
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

<?php if ($reportTab === 'referrals'): ?>
<form method="GET" class="vr-examiner-filter">
    <?php foreach ($reportQuery as $key => $value): if ($key === 'examiner_id') { continue; } ?>
        <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string) $value) ?>">
    <?php endforeach; ?>
    <label for="referral-examiner">دکتۆری پشکنەر</label>
    <select id="referral-examiner" name="examiner_id" onchange="this.form.requestSubmit()">
        <?php foreach ($referralReport['examiners'] as $id => $name): ?>
            <option value="<?= (int) $id ?>" <?= $id === $examinerId ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="vr-apply">پیشاندانی ڕاپۆرت</button>
    <span class="vr-small-note"><?= htmlspecialchars($dateFilterLabel) ?></span>
</form>
<section class="vr-photo-sheet" aria-label="لە پشکنینەوە بۆ ئیش">
    <?= dh_visit_report_letterhead($db) ?>
    <div class="vr-photo-scroll"><?= dh_visit_referral_render($referralReport, $reportPeriod, $currentUser['full_name'] ?? '') ?></div>
</section>
<?php else: ?>
<section class="vr-photo-sheet" aria-label="ئاماری سەردانەکان">
    <?= dh_visit_report_letterhead($db) ?>
    <div class="vr-photo-scroll">
        <?= dh_visit_report_render_matrix($matrixStats, $reportPeriod, $visitTypeLabel) ?>
    </div>
    <?= dh_visit_report_matrix_note($matrixStats) ?>
</section>
<div class="vr-wide-card">
    <div class="vr-card">
        <div class="hd"><span><i class="fas fa-user-tie"></i> سەردانە تۆمارکراوەکان بەپێی کارمەندی پێشوازی</span></div>
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

</div>

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

<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
