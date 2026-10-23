<?php
/**
 * Appointments by Type
 * مەوعیدەکان بەپێی جۆر (گشتی، پشکنین، چارەسەر)
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

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

$today   = date('Y-m-d');
$doctors = $auth->getDoctors();

// ── Filters ──
$dateMode = $_GET['mode']      ?? 'month';
$fromIn   = $_GET['from']      ?? '';
$toIn     = $_GET['to']        ?? '';
$typeIn   = $_GET['type']      ?? 'all';
$docIn    = $_GET['doctor_id'] ?? '';

$statusIn = $_GET['status']    ?? 'all';

$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$validTypes = ['all', 'general', 'checkup', 'treatment'];
$typeFilter = in_array($typeIn, $validTypes, true) ? $typeIn : 'all';

$validStatuses = ['all','active','scheduled','confirmed','waiting','in_progress','completed','cancelled','no_show','rescheduled'];
$statusFilter = in_array($statusIn, $validStatuses, true) ? $statusIn : 'all';

// Resolve date range
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

// ── Query ──
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
                   COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                             FROM patient_folders pf WHERE pf.patient_id = p.id), 0)
                 + COALESCE((SELECT SUM(xr.final_amount)
                             FROM xray_requests xr WHERE xr.patient_id = p.id
                             AND xr.status='completed' AND xr.payment_status='debt'), 0)
               ) AS total_debt
        FROM appointments a
        LEFT JOIN patients   p  ON a.patient_id   = p.id
        LEFT JOIN users      u  ON a.doctor_id    = u.id
        LEFT JOIN work_types wt ON a.work_type_id = wt.id
        WHERE {$where}
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 500";
$rows = $db->fetchAll($sql, $params);

// Breakdown base — respects range + doctor + type (so status badges reflect the type filter too)
$breakdownWhere = "a.appointment_date BETWEEN :from AND :to";
$bParams = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($activeDoctorId)       { $breakdownWhere .= " AND a.doctor_id = :doc"; $bParams['doc'] = $activeDoctorId; }
// Type chip counts respect the date + doctor (but NOT type, so the chip badges represent each type)
$typeBdWhere = $breakdownWhere; $typeBdParams = $bParams;
$typeCounts = ['all' => 0, 'general' => 0, 'checkup' => 0, 'treatment' => 0];
foreach ($db->fetchAll("SELECT a.type, COUNT(*) c FROM appointments a WHERE {$typeBdWhere} GROUP BY a.type", $typeBdParams) as $r) {
    if (isset($typeCounts[$r['type']])) { $typeCounts[$r['type']] = (int)$r['c']; }
    $typeCounts['all'] += (int)$r['c'];
}

// Status chip counts — respects date + doctor + type
$statusBdWhere = $breakdownWhere; $statusBdParams = $bParams;
if ($typeFilter !== 'all') { $statusBdWhere .= " AND a.type = :type"; $statusBdParams['type'] = $typeFilter; }
$statusCounts = ['all'=>0,'active'=>0,'scheduled'=>0,'confirmed'=>0,'waiting'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0,'no_show'=>0,'rescheduled'=>0];
foreach ($db->fetchAll("SELECT a.status, COUNT(*) c FROM appointments a WHERE {$statusBdWhere} GROUP BY a.status", $statusBdParams) as $r) {
    $st = $r['status'];
    if (isset($statusCounts[$st])) $statusCounts[$st] = (int)$r['c'];
    $statusCounts['all'] += (int)$r['c'];
    if (in_array($st, ['scheduled','confirmed','waiting','in_progress'], true)) $statusCounts['active'] += (int)$r['c'];
}

// Totals of the current filter — computed over the FULL filtered set (not the
// 500-row display cap), so the summary cards don't under-count on big ranges.
$agg = $db->fetch("SELECT COUNT(*) cnt, COALESCE(SUM(a.duration),0) dur, COALESCE(SUM(a.promised_amount),0) promised
                   FROM appointments a WHERE {$where}", $params);
$totalCount    = (int)($agg['cnt'] ?? 0);
$totalDuration = (int)($agg['dur'] ?? 0);
$totalPromised = (float)($agg['promised'] ?? 0);

// Distinct-patient outstanding debt across the filtered appointments
$debtRow = $db->fetch("
    SELECT COALESCE(SUM(t.debt),0) AS total FROM (
        SELECT p.id,
          (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0)
           + COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0)) AS debt
        FROM patients p
        WHERE p.id IN (SELECT DISTINCT a.patient_id FROM appointments a WHERE {$where} AND a.patient_id IS NOT NULL)
    ) t WHERE t.debt > 0
", $params);
$totalDebt = (float)($debtRow['total'] ?? 0);

$pageTitle = 'مەوعیدەکان بەپێی جۆر';
$breadcrumb = [
    ['title' => 'مەوعیدەکان', 'url' => APP_URL . '/appointments.php'],
    ['title' => 'بەپێی جۆر']
];
require_once __DIR__ . '/includes/header.php';
?>

<style>
html { overflow-y: scroll; scrollbar-gutter: stable; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
html::-webkit-scrollbar { width: 10px; }
html::-webkit-scrollbar-track { background: #f1f5f9; }
html::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; border: 2px solid #f1f5f9; }

.bt-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
.bt-header h3 { margin:0; font-size:20px; color:#0f172a; }
.bt-header .sub { color:#607d8b; font-size:13px; }

.bt-filter { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.bt-chip { padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; border-radius:20px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.bt-chip:hover { background:#e2e8f0; color:#0f172a; }
.bt-chip.active { background:#0078d4; border-color:#0078d4; color:#fff; }
.bt-chip .badge { background:#fff; color:#0078d4; border-radius:10px; padding:1px 7px; font-size:11px; font-weight:700; }
.bt-chip.active .badge { background:rgba(255,255,255,.25); color:#fff; }
.bt-sep { width:1px; height:24px; background:#e2e8f0; }

.bt-filter.types .bt-chip.active.t-general   { background:#7c3aed; border-color:#7c3aed; }
.bt-filter.types .bt-chip.active.t-checkup   { background:#0ea5e9; border-color:#0ea5e9; }
.bt-filter.types .bt-chip.active.t-treatment { background:#16a34a; border-color:#16a34a; }

.bt-filter.statuses .bt-chip.active.s-active      { background:#0891b2; border-color:#0891b2; }
.bt-filter.statuses .bt-chip.active.s-scheduled   { background:#ea580c; border-color:#ea580c; }
.bt-filter.statuses .bt-chip.active.s-confirmed   { background:#0078d4; border-color:#0078d4; }
.bt-filter.statuses .bt-chip.active.s-completed   { background:#16a34a; border-color:#16a34a; }
.bt-filter.statuses .bt-chip.active.s-cancelled   { background:#dc2626; border-color:#dc2626; }
.bt-filter.statuses .bt-chip.active.s-no_show     { background:#be185d; border-color:#be185d; }
.bt-filter.statuses .bt-chip.active.s-rescheduled { background:#f59e0b; border-color:#f59e0b; }

.bt-filter input[type=date], .bt-filter select { padding:6px 8px; border:1px solid #cfd8dc; border-radius:6px; font-size:13px; }
.bt-filter label { font-size:12px; color:#607d8b; }
.bt-apply { padding:6px 14px; background:#22c55e; color:#fff; border:1px solid #16a34a; border-radius:6px; cursor:pointer; font-size:13px; }
.bt-apply:hover { background:#16a34a; }

.bt-stats { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:14px; }
.bt-stat { background:#fff; border:1px solid #e3e8ee; border-radius:8px; padding:12px 14px; }
.bt-stat .label { font-size:12px; color:#607d8b; margin-bottom:4px; }
.bt-stat .value { font-size:20px; font-weight:800; color:#0f172a; line-height:1.1; }
.bt-stat.debt .value    { color:#c62828; }
.bt-stat.promise .value { color:#16a34a; }

.bt-table-wrap { background:#fff; border:1px solid #e3e8ee; border-radius:10px; overflow:hidden; }
.bt-table-wrap .head-title { padding:10px 14px; border-bottom:1px solid #eef2f5; font-weight:700; color:#0f172a; font-size:15px; display:flex; justify-content:space-between; align-items:center; }
.bt-table-scroll { overflow-x:auto; }
.bt-table { width:100%; border-collapse:collapse; font-size:13px; min-width: 980px; }
.bt-table th { background:#f7f9fc; color:#37474f; font-weight:600; padding:10px 8px; text-align:center; border-bottom:1px solid #e3e8ee; }
.bt-table td { padding:9px 8px; border-bottom:1px solid #eef2f5; text-align:center; vertical-align:middle; }
.bt-table tr:hover td { background:#fafcff; }
.bt-table .pname { font-weight:600; color:#0f172a; }
.bt-table .phone { direction:ltr; font-family:var(--app-font-family); font-size:12px; color:#0078d4; text-decoration:none; }
.bt-table .debt-val { color:#c62828; font-weight:600; }
.bt-table .nodebt   { color:#9aa7b3; font-size:11px; }
.bt-table .prom-val { color:#15803d; font-weight:600; }
.type-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; color:#fff; }
.type-general   { background:#7c3aed; }
.type-checkup   { background:#0ea5e9; }
.type-treatment { background:#16a34a; }
.status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; }
.st-scheduled { background:#fff4e5; color:#e65100; }
.st-confirmed { background:#e3f2fd; color:#0078d4; }
.st-waiting   { background:#fff3e0; color:#f57c00; }
.st-in_progress { background:#e3f2fd; color:#0078d4; }
.st-completed { background:#e8f5e9; color:#2e7d32; }
.st-cancelled { background:#ffebee; color:#c62828; }
.st-no_show   { background:#fce4ec; color:#ad1457; }
.st-rescheduled { background:#fff3e0; color:#ef6c00; }

.empty { padding:40px; text-align:center; color:#9aa7b3; }

@media (max-width: 900px) {
    .bt-stats { grid-template-columns:repeat(2,1fr); }
}
@media (max-width: 640px) {
    .bt-header h3 { font-size:16px; }
    .bt-chip { padding:5px 10px; font-size:12px; }
    .bt-stat .value { font-size:16px; }
    .bt-stat .label { font-size:11px; }
}
</style>

<?php
// Build PDF URL with current filters
$pdfParams = ['mode' => $dateMode, 'type' => $typeFilter, 'status' => $statusFilter];
if ($dateMode === 'range') { $pdfParams['from'] = $rangeFrom; $pdfParams['to'] = $rangeTo; }
if ($activeDoctorId && !$enforcedDoctor) { $pdfParams['doctor_id'] = $activeDoctorId; }
$pdfUrl = APP_URL . '/appointments-by-type-pdf.php?' . http_build_query($pdfParams);
?>

<div class="bt-header">
    <div>
        <h3><i class="fas fa-filter"></i> مەوعیدەکان بەپێی جۆر</h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?> بۆ <?= htmlspecialchars($rangeTo) ?></div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn" style="background:#c62828;border-color:#c62828;color:#fff;">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= APP_URL ?>/appointments.php" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i> گەڕانەوە
        </a>
    </div>
</div>

<!-- Type chips (top filter row) -->
<?php
$typeChips = [
    ['all',       'هەموو',     'fa-list',       ''],
    ['general',   'گشتی',       'fa-tag',         't-general'],
    ['checkup',   'پشکنین',     'fa-stethoscope', 't-checkup'],
    ['treatment', 'چارەسەر',    'fa-tooth',       't-treatment'],
];
// Preserve all params except type when switching chips
$carryBase = ['mode' => $dateMode, 'status' => $statusFilter];
if ($dateMode === 'range') { $carryBase['from'] = $rangeFrom; $carryBase['to'] = $rangeTo; }
if ($activeDoctorId && !$enforcedDoctor) { $carryBase['doctor_id'] = $activeDoctorId; }
?>
<div class="bt-filter types">
    <?php foreach ($typeChips as [$k, $lbl, $ic, $cls]): ?>
    <?php $url = '?' . http_build_query(array_merge($carryBase, ['type' => $k])); ?>
    <a href="<?= htmlspecialchars($url) ?>" class="bt-chip <?= $cls ?> <?= $typeFilter === $k ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
        <span class="badge"><?= $typeCounts[$k] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Status chips row -->
<?php
$statusChips = [
    ['all',         'هەموو',         'fa-list',          ''],
    ['active',      'چالاک',          'fa-play-circle',   's-active'],
    ['scheduled',   'داڕێژراو',       'fa-clock',         's-scheduled'],
    ['confirmed',   'پشتڕاستکراو',    'fa-check',         's-confirmed'],
    ['completed',   'تەواوبوو',        'fa-check-double',  's-completed'],
    ['cancelled',   'هەڵوەشاوە',       'fa-times',         's-cancelled'],
    ['no_show',     'نەهات',          'fa-user-slash',    's-no_show'],
    ['rescheduled', 'دواخراو',         'fa-calendar-alt',  's-rescheduled'],
];
$carryStatus = ['mode' => $dateMode, 'type' => $typeFilter];
if ($dateMode === 'range') { $carryStatus['from'] = $rangeFrom; $carryStatus['to'] = $rangeTo; }
if ($activeDoctorId && !$enforcedDoctor) { $carryStatus['doctor_id'] = $activeDoctorId; }
?>
<div class="bt-filter statuses">
    <?php foreach ($statusChips as [$k, $lbl, $ic, $cls]): ?>
    <?php $url = '?' . http_build_query(array_merge($carryStatus, ['status' => $k])); ?>
    <a href="<?= htmlspecialchars($url) ?>" class="bt-chip <?= $cls ?> <?= $statusFilter === $k ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
        <span class="badge"><?= $statusCounts[$k] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Date + doctor filter row -->
<?php
$dateChips = [
    ['today','ئەمڕۆ','fa-calendar-day'],
    ['week', 'ئەم هەفتەیە','fa-calendar-week'],
    ['month','ئەم مانگە','fa-calendar-alt'],
    ['year', 'ئەمساڵ','fa-calendar'],
];
$carryType = ['type' => $typeFilter, 'status' => $statusFilter];
if ($activeDoctorId && !$enforcedDoctor) { $carryType['doctor_id'] = $activeDoctorId; }
?>
<form method="GET" action="" class="bt-filter">
    <?php foreach ($dateChips as [$k, $lbl, $ic]): ?>
    <a href="?<?= http_build_query(array_merge($carryType, ['mode' => $k])) ?>" class="bt-chip <?= $dateMode === $k ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>

    <span class="bt-sep"></span>

    <input type="hidden" name="mode" value="range">
    <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
    <label>لە</label>
    <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
    <label>بۆ</label>
    <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">

    <?php if ($hasFullAccess): ?>
    <span class="bt-sep"></span>
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

    <button type="submit" class="bt-apply"><i class="fas fa-search"></i> گەڕان</button>
</form>

<div class="bt-stats">
    <div class="bt-stat">
        <div class="label">کۆی مەوعیدەکان</div>
        <div class="value"><?= number_format($totalCount) ?></div>
    </div>
    <div class="bt-stat">
        <div class="label">کۆی ماوە</div>
        <div class="value"><?= number_format($totalDuration) ?> خولەک</div>
    </div>
    <div class="bt-stat debt">
        <div class="label">کۆی قەرز</div>
        <div class="value"><?= number_format($totalDebt) ?></div>
    </div>
    <div class="bt-stat promise">
        <div class="label">کۆی پارەی بەڵێندراو</div>
        <div class="value"><?= number_format($totalPromised) ?></div>
    </div>
</div>

<div class="bt-table-wrap">
    <div class="head-title">
        <span><i class="fas fa-list"></i> لیستی مەوعیدەکان</span>
        <span style="font-size:12px; color:#607d8b; font-weight:400;">
            <?= min(count($rows), 500) ?> تۆمار<?= count($rows) >= 500 ? ' (سنوور: ٥٠٠)' : '' ?>
        </span>
    </div>
    <div class="bt-table-scroll">
        <table class="bt-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>بەروار</th>
                    <th>کات</th>
                    <th>ناوی نەخۆش</th>
                    <th>دکتۆر</th>
                    <th>جۆر</th>
                    <th>ئیش</th>
                    <th>ماوە</th>
                    <th>مۆبایل</th>
                    <th>قەرز</th>
                    <th>هێنراو</th>
                    <th>دۆخ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="12" class="empty">هیچ تۆمارێک نەدۆزرایەوە</td></tr>
                <?php else: foreach ($rows as $i => $r):
                    $debt  = max(0, (float)$r['total_debt']);
                    $prom  = (float)($r['promised_amount'] ?? 0);
                    $phone = preg_replace('/\s+/', '', (string)$r['patient_phone']);
                    $typeLabels = ['general' => 'گشتی', 'checkup' => 'پشکنین', 'treatment' => 'چارەسەر', 'followup' => 'بەدواداچوون', 'emergency' => 'فریاکەوتن', 'consultation' => 'ڕاوێژکاری'];
                    $stLabels = ['scheduled'=>'داڕێژراو','confirmed'=>'پشتڕاستکراو','waiting'=>'چاوەڕوان','in_progress'=>'لە چارەسەردا','completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە','no_show'=>'نەهات','rescheduled'=>'دواخراو'];
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($r['appointment_date']) ?></td>
                    <td><?= date('H:i', strtotime($r['appointment_time'])) ?></td>
                    <td><span class="pname"><?= htmlspecialchars($r['patient_name'] ?? '—') ?></span></td>
                    <td>د. <?= htmlspecialchars($r['doctor_name'] ?? '—') ?></td>
                    <td>
                        <?php $tk = $r['type']; if (in_array($tk, ['general','checkup','treatment'], true)): ?>
                        <span class="type-badge type-<?= $tk ?>"><?= $typeLabels[$tk] ?? $tk ?></span>
                        <?php else: ?>
                        <span style="color:#9aa7b3; font-size:11px;"><?= $typeLabels[$tk] ?? htmlspecialchars($tk) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= !empty($r['work_name']) ? htmlspecialchars($r['work_name']) : '—' ?></td>
                    <td><?= intval($r['duration']) ?> خولەک</td>
                    <td>
                        <?php if (!empty($phone)): ?>
                        <a href="tel:<?= htmlspecialchars($phone) ?>" class="phone"><?= htmlspecialchars($phone) ?></a>
                        <?php else: ?>—<?php endif; ?>
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
                        <span class="prom-val"><?= number_format($prom) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge st-<?= htmlspecialchars($r['status']) ?>">
                            <?= $stLabels[$r['status']] ?? htmlspecialchars($r['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
