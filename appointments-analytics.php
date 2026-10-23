<?php
/**
 * Appointments Analytics & Reports (read-only)
 * ئامار و ڕاپۆرتی مەوعیدەکان
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/XrayManager.php';

$db   = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser    = $auth->getCurrentUser();
$isAdmin        = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$hasFullAccess  = $isAdmin || $isReceptionist;
$enforcedDoctor = $hasFullAccess ? null : $currentUser['id'];

$today     = date('Y-m-d');
$doctors   = $auth->getDoctors();

// ───── Filters ─────
$mode           = $_GET['mode']      ?? 'month';
$fromIn         = $_GET['from']      ?? '';
$toIn           = $_GET['to']        ?? '';
$doctorIdFilter = $_GET['doctor_id'] ?? '';
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today;
$rangeTo   = $today;
$rangeLabel= '';

switch ($mode) {
    case 'today':
        $rangeFrom = $rangeTo = $today;
        $rangeLabel = 'ئەمڕۆ';
        break;
    case 'week':
        $dow = (int)date('w');
        $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە';
        break;
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
        $rangeFrom = date('Y-01-01');
        $rangeTo   = date('Y-12-31');
        $rangeLabel= 'ئەمساڵ';
        break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        } else {
            $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel = 'ئەم مانگە';
        }
        break;
    case 'month':
    default:
        $rangeFrom = date('Y-m-01');
        $rangeTo   = date('Y-m-t');
        $rangeLabel= 'ئەم مانگە';
}

$activeDoctorId = $enforcedDoctor ?: (is_numeric($doctorIdFilter) ? intval($doctorIdFilter) : null);

// ───── Stats queries ─────
$baseWhere  = "a.appointment_date BETWEEN :from AND :to";
$baseParams = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($activeDoctorId) { $baseWhere .= " AND a.doctor_id = :doc"; $baseParams['doc'] = $activeDoctorId; }

// Status counts
$statusSql = "SELECT a.status, COUNT(*) c FROM appointments a WHERE {$baseWhere} GROUP BY a.status";
$statusRows = $db->fetchAll($statusSql, $baseParams);
$statusCount = ['scheduled'=>0,'confirmed'=>0,'waiting'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0,'no_show'=>0,'rescheduled'=>0];
foreach ($statusRows as $r) { $statusCount[$r['status']] = (int)$r['c']; }

$totalCount     = array_sum($statusCount);
$completedCount = $statusCount['completed'];
$cancelledCount = $statusCount['cancelled'];
$rescheduledCount = $statusCount['rescheduled'];
$noShowCount    = $statusCount['no_show'];
$activeCount    = $statusCount['scheduled'] + $statusCount['confirmed'] + $statusCount['waiting'] + $statusCount['in_progress'];

$pct = fn($n) => $totalCount > 0 ? round(($n / $totalCount) * 100, 1) : 0;

// Per-weekday breakdown (0=Sun … 6=Sat)
$weekdaySql = "SELECT DAYOFWEEK(a.appointment_date) dow, COUNT(*) c FROM appointments a WHERE {$baseWhere} GROUP BY DAYOFWEEK(a.appointment_date)";
$weekdayRows = $db->fetchAll($weekdaySql, $baseParams);
// MySQL DAYOFWEEK: 1=Sun..7=Sat
$weekdayCounts = [1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,7=>0];
foreach ($weekdayRows as $r) { $weekdayCounts[(int)$r['dow']] = (int)$r['c']; }
// Order: شەممە, یەکشەممە, دووشەممە, سێشەممە, چوارشەممە, پێنجشەممە, هەینی  → (7,1,2,3,4,5,6)
$weekdayOrder = [7,1,2,3,4,5,6];
$weekdayLabels= ['شەممە','یەکشەممە','دووشەممە','سێشەممە','چوارشەممە','پێنجشەممە','هەینی'];
$weekdaySeries= array_map(fn($i) => $weekdayCounts[$i], $weekdayOrder);

// Rows for the table (joins patient + doctor + debt)
$sql = "SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.notes, a.promised_amount,
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
        WHERE {$baseWhere}
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 500";
$rows = $db->fetchAll($sql, $baseParams);

$pageTitle = 'ئامار و ڕاپۆرتەکان';
$breadcrumb = [
    ['title' => 'مەوعیدەکان', 'url' => APP_URL . '/appointments.php'],
    ['title' => 'ئامار و ڕاپۆرتەکان']
];
require_once __DIR__ . '/includes/header.php';
?>

<style>
html { overflow-y: scroll; scrollbar-gutter: stable; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
html::-webkit-scrollbar { width: 10px; }
html::-webkit-scrollbar-track { background: #f1f5f9; }
html::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; border: 2px solid #f1f5f9; }

.an-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
.an-header h3 { margin:0; font-size:20px; color:#0f172a; }
.an-header .sub { color:#607d8b; font-size:13px; }

.an-filter { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:14px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.an-chip { padding:6px 14px; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; border-radius:20px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.an-chip:hover { background:#e2e8f0; color:#0f172a; }
.an-chip.active { background:#0078d4; border-color:#0078d4; color:#fff; }
.an-sep { width:1px; height:24px; background:#e2e8f0; }
.an-filter input[type=date], .an-filter select { padding:6px 8px; border:1px solid #cfd8dc; border-radius:6px; font-size:13px; }
.an-filter label { font-size:12px; color:#607d8b; }
.an-apply { padding:6px 14px; background:#22c55e; color:#fff; border:1px solid #16a34a; border-radius:6px; cursor:pointer; font-size:13px; }
.an-apply:hover { background:#16a34a; }

.an-stats { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:16px; }
.an-card { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:14px; position:relative; overflow:hidden; }
.an-card::before { content:''; position:absolute; inset-block:0; inset-inline-end:0; width:4px; background:var(--accent, #0078d4); }
.an-card.blue   { --accent:#0078d4; }
.an-card.green  { --accent:#16a34a; }
.an-card.orange { --accent:#ea580c; }
.an-card.red    { --accent:#dc2626; }
.an-card .label { font-size:12px; color:#607d8b; margin-bottom:4px; }
.an-card .value { font-size:24px; font-weight:800; color:#0f172a; line-height:1.1; }
.an-card .pct   { font-size:12px; color:#607d8b; margin-top:6px; }
.an-card .pct .arrow { color:var(--accent); font-weight:700; }

.an-charts { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
.an-chart-box { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:14px; }
.an-chart-box h4 { margin:0 0 10px; font-size:15px; color:#0f172a; }
.an-chart-box canvas { max-height:280px; }

.an-table-wrap { background:#fff; border:1px solid #e3e8ee; border-radius:10px; overflow:hidden; }
.an-table-wrap .thead-title { padding:10px 14px; border-bottom:1px solid #eef2f5; font-weight:700; color:#0f172a; font-size:15px; display:flex; justify-content:space-between; align-items:center; }
.an-table-scroll { max-height:520px; overflow-y:auto; }
.an-table { width:100%; border-collapse:collapse; font-size:13px; }
.an-table th { background:#f7f9fc; color:#37474f; font-weight:600; padding:10px 8px; text-align:center; border-bottom:1px solid #e3e8ee; position:sticky; top:0; }
.an-table td { padding:9px 8px; border-bottom:1px solid #eef2f5; text-align:center; vertical-align:middle; }
.an-table tr:hover td { background:#fafcff; }
.an-table .pname { font-weight:600; color:#0f172a; }
.an-table .phone { direction:ltr; font-family:var(--app-font-family); font-size:12px; color:#546e7a; }
.an-table .debt-val { color:#c62828; font-weight:600; }
.an-table .nodebt { color:#9aa7b3; font-size:11px; }
.status-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.st-scheduled   { background:#fff4e5; color:#e65100; }
.st-confirmed   { background:#e3f2fd; color:#0078d4; }
.st-waiting     { background:#fff3e0; color:#f57c00; }
.st-in_progress { background:#e3f2fd; color:#0078d4; }
.st-completed   { background:#e8f5e9; color:#2e7d32; }
.st-cancelled   { background:#ffebee; color:#c62828; }
.st-no_show     { background:#fce4ec; color:#ad1457; }
.st-rescheduled { background:#fff3e0; color:#ef6c00; }

@media (max-width: 900px) {
    .an-stats { grid-template-columns:repeat(2,1fr); }
    .an-charts { grid-template-columns:1fr; }
}
@media (max-width: 640px) {
    .an-filter { padding:8px; gap:4px; }
    .an-chip { padding:5px 10px; font-size:12px; }
    .an-card .value { font-size:18px; }
    .an-card .label { font-size:11px; }
    .an-table-wrap { border-radius:8px; }
    .an-table-scroll { max-height:420px; }
    .an-table th, .an-table td { padding:7px 4px; font-size:11px; }
}
</style>

<?php
$pdfQuery = ['mode' => $mode];
if ($mode === 'range') { $pdfQuery['from'] = $rangeFrom; $pdfQuery['to'] = $rangeTo; }
if ($activeDoctorId && !$enforcedDoctor) { $pdfQuery['doctor_id'] = $activeDoctorId; }
$pdfUrl = APP_URL . '/appointments-analytics-pdf.php?' . http_build_query($pdfQuery);
?>
<div class="an-header">
    <div>
        <h3><i class="fas fa-chart-pie"></i> ئامار و ڕاپۆرتەکان</h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?> بۆ <?= htmlspecialchars($rangeTo) ?></div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn" style="background:#c62828;border-color:#c62828;color:#fff;">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= htmlspecialchars($pdfUrl . '&output=D') ?>" class="btn btn-outline-secondary" title="داگرتنی PDF">
            <i class="fas fa-download"></i>
        </a>
        <a href="<?= APP_URL ?>/appointments.php" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i> گەڕانەوە
        </a>
    </div>
</div>

<?php $chips = [
    ['today',     'ئەمڕۆ',            'fa-calendar-day'],
    ['week',      'ئەم هەفتەیە',       'fa-calendar-week'],
    ['month',     'ئەم مانگە',         'fa-calendar-alt'],
    ['quarter',   'سێ مانگی ڕابردوو',  'fa-calendar-minus'],
    ['half_year', 'شەش مانگی ڕابردوو', 'fa-calendar-plus'],
    ['year',      'ئەمساڵ',            'fa-calendar'],
]; ?>
<form method="GET" action="" class="an-filter">
    <?php foreach ($chips as [$k,$lbl,$ic]): ?>
    <a href="?mode=<?= $k ?><?= $activeDoctorId && !$enforcedDoctor ? '&doctor_id=' . intval($activeDoctorId) : '' ?>"
       class="an-chip <?= $mode === $k ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>

    <span class="an-sep"></span>

    <input type="hidden" name="mode" value="range">
    <label>لە</label>
    <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
    <label>بۆ</label>
    <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">

    <?php if ($hasFullAccess): ?>
    <span class="an-sep"></span>
    <label><i class="fas fa-user-md"></i> دکتۆر</label>
    <select name="doctor_id">
        <option value="">— هەموو دکتۆرەکان —</option>
        <?php foreach ($doctors as $d): ?>
        <option value="<?= $d['id'] ?>" <?= (string)$activeDoctorId === (string)$d['id'] ? 'selected' : '' ?>>
            د. <?= htmlspecialchars($d['full_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <button type="submit" class="an-apply"><i class="fas fa-search"></i> گەڕان</button>
</form>

<div class="an-stats">
    <div class="an-card blue">
        <div class="label">کۆی گشتی مەوعیدەکان</div>
        <div class="value"><?= number_format($totalCount) ?></div>
        <div class="pct"><span class="arrow"><i class="fas fa-calendar-check"></i></span> چالاک: <?= $activeCount ?></div>
    </div>
    <div class="an-card green">
        <div class="label">تەواوبووەکان</div>
        <div class="value"><?= number_format($completedCount) ?></div>
        <div class="pct"><span class="arrow"><?= $pct($completedCount) ?>%</span> لە کۆی گشتی</div>
    </div>
    <div class="an-card orange">
        <div class="label">دواخراوەکان</div>
        <div class="value"><?= number_format($rescheduledCount) ?></div>
        <div class="pct"><span class="arrow"><?= $pct($rescheduledCount) ?>%</span> لە کۆی گشتی</div>
    </div>
    <div class="an-card red">
        <div class="label">هەڵوەشێندراوەکان</div>
        <div class="value"><?= number_format($cancelledCount) ?></div>
        <div class="pct"><span class="arrow"><?= $pct($cancelledCount) ?>%</span> لە کۆی گشتی · نەهاتن: <?= $noShowCount ?></div>
    </div>
</div>

<div class="an-charts">
    <div class="an-chart-box">
        <h4><i class="fas fa-chart-pie"></i> دابەشبوونی دۆخی مەوعیدەکان</h4>
        <canvas id="statusChart"></canvas>
    </div>
    <div class="an-chart-box">
        <h4><i class="fas fa-chart-bar"></i> مەوعیدەکان بەپێی ڕۆژەکانی هەفتە</h4>
        <canvas id="weekdayChart"></canvas>
    </div>
</div>

<div class="an-table-wrap">
    <div class="thead-title">
        <span><i class="fas fa-list"></i> خشتەی مەوعیدەکان</span>
        <span style="font-size:12px; color:#607d8b; font-weight:400;">
            نیشاندانی <?= min(count($rows), 500) ?> تۆمار
            <?= count($rows) >= 500 ? ' (سنوور: ٥٠٠)' : '' ?>
        </span>
    </div>
    <div class="an-table-scroll">
        <table class="an-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>ناوی نەخۆش</th>
                    <th>ژ. مۆبایل</th>
                    <th>دکتۆر</th>
                    <th>بەروار</th>
                    <th>کات</th>
                    <th>دۆخ</th>
                    <th>قەرز</th>
                    <th>پارەی هێنراو</th>
                    <th>تێبینی</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="10" style="padding:40px;color:#9aa7b3;">هیچ تۆمارێک نەدۆزرایەوە</td></tr>
                <?php else: foreach ($rows as $i => $r):
                    $debt  = max(0, (float)$r['total_debt']);
                    $prom  = (float)($r['promised_amount'] ?? 0);
                    $phone = preg_replace('/\s+/', '', (string)$r['patient_phone']);
                    $statusLabels = [
                        'scheduled'=>'داڕێژراو','confirmed'=>'پشتڕاستکراو','waiting'=>'چاوەڕوان','in_progress'=>'لە چارەسەردا',
                        'completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە','no_show'=>'نەهات','rescheduled'=>'دواخراو'
                    ];
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <div class="pname"><?= htmlspecialchars($r['patient_name'] ?? '—') ?></div>
                    </td>
                    <td>
                        <?php if (!empty($phone)): ?>
                        <a href="tel:<?= htmlspecialchars($phone) ?>" class="phone"><?= htmlspecialchars($phone) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>د. <?= htmlspecialchars($r['doctor_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['appointment_date']) ?></td>
                    <td><?= date('H:i', strtotime($r['appointment_time'])) ?></td>
                    <td>
                        <span class="status-badge st-<?= htmlspecialchars($r['status']) ?>">
                            <?= $statusLabels[$r['status']] ?? htmlspecialchars($r['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($debt > 0): ?>
                        <span class="debt-val"><?= number_format($debt) ?></span>
                        <?php else: ?>
                        <span class="nodebt">بێ قەرز</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($prom > 0): ?>
                        <span style="color:#15803d; font-weight:600;"><?= number_format($prom) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#607d8b; font-size:11px;">
                        <?= !empty($r['notes']) ? htmlspecialchars($r['notes']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
(function() {
    // Chart labels/tooltips follow the global app font (NRT — see assets/css/fonts.css)
    Chart.defaults.font.family = getComputedStyle(document.documentElement)
        .getPropertyValue('--app-font-family') || "'NRT', 'Segoe UI', Tahoma, sans-serif";
    const statusLabels = {
        scheduled:'داڕێژراو', confirmed:'پشتڕاستکراو', waiting:'چاوەڕوان', in_progress:'لە چارەسەردا',
        completed:'تەواوبوو', cancelled:'هەڵوەشاوە', no_show:'نەهات', rescheduled:'دواخراو'
    };
    const statusColors = {
        scheduled:'#ff8c00', confirmed:'#0078d4', waiting:'#ff9800', in_progress:'#2196f3',
        completed:'#4caf50', cancelled:'#f44336', no_show:'#ad1457', rescheduled:'#ef6c00'
    };

    const statusData = <?= json_encode($statusCount) ?>;
    const filteredKeys = Object.keys(statusData).filter(k => statusData[k] > 0);

    // Pie chart
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: filteredKeys.map(k => statusLabels[k] || k),
            datasets: [{
                data: filteredKeys.map(k => statusData[k]),
                backgroundColor: filteredKeys.map(k => statusColors[k]),
                borderWidth: 2, borderColor: '#fff'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', rtl: true, labels: { font: { size: 12 } } } }
        }
    });

    // Bar chart per weekday
    new Chart(document.getElementById('weekdayChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($weekdayLabels) ?>,
            datasets: [{
                label: 'ژمارەی مەوعیدەکان',
                data: <?= json_encode($weekdaySeries) ?>,
                backgroundColor: '#0078d4',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
