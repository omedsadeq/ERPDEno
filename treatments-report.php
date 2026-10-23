<?php
/**
 * Treatments Analytics Report
 * ڕاپۆرتی شیکاری چارەسەرەکان — بەپێی جۆر، دکتۆر، نەخۆش
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
$isAccountant   = $auth->isAccountant();
$isDoctor       = $auth->isDoctor();
$hasFullAccess  = $isAdmin || $isReceptionist || $isAccountant;
$enforcedDoctor = $hasFullAccess ? null : ($isDoctor ? $currentUser['id'] : 0);

$today = date('Y-m-d');

// ── Filters ──
$mode        = $_GET['mode']         ?? 'today';
$fromIn      = $_GET['from']         ?? '';
$toIn        = $_GET['to']           ?? '';
$doctorIn    = $_GET['doctor_id']    ?? '';
$patientIn   = $_GET['patient_id']   ?? '';
$patientQIn  = $_GET['patient_q']    ?? '';
$categoryIn  = $_GET['category']     ?? 'all';
$treatmentIn = $_GET['treatment_id'] ?? '';
$statusIn    = $_GET['status']       ?? 'all';

$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

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
    case 'year':      $rangeFrom = date('Y-01-01'); $rangeTo = date('Y-12-31'); $rangeLabel = 'ئەمساڵ'; break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        }
        break;
}

$validCategories = ['all','examination','cleaning','filling','extraction','root_canal','crown','bridge','implant','orthodontic','whitening','cosmetic','surgery','other'];
$validStatuses   = ['all','pending','approved','completed','cancelled'];
$category = in_array($categoryIn, $validCategories, true) ? $categoryIn : 'all';
$status   = in_array($statusIn,   $validStatuses,   true) ? $statusIn   : 'all';

$activeDoctor    = $enforcedDoctor ?: (is_numeric($doctorIn) ? intval($doctorIn) : null);
$activePatient   = is_numeric($patientIn) ? intval($patientIn) : null;
$activeTreatment = is_numeric($treatmentIn) ? intval($treatmentIn) : null;

$start = $rangeFrom . ' 00:00:00';
$end   = $rangeTo   . ' 23:59:59';

// Build WHERE once
$where  = "ct.created_at BETWEEN :start AND :end";
$params = ['start' => $start, 'end' => $end];
if ($activeDoctor)    { $where .= " AND pf.doctor_id = :doc";      $params['doc']   = $activeDoctor; }
if ($activePatient)   { $where .= " AND p.id = :pat";               $params['pat']   = $activePatient; }
if ($activeTreatment) { $where .= " AND ct.treatment_id = :tid";    $params['tid']   = $activeTreatment; }
if ($category !== 'all')   { $where .= " AND t.category = :cat";    $params['cat']   = $category; }
if ($status !== 'all')     { $where .= " AND ct.status = :st";      $params['st']    = $status; }

$joins = "
    FROM case_treatments ct
    INNER JOIN cases c              ON ct.case_id    = c.id
    INNER JOIN patient_folders pf   ON c.folder_id   = pf.id
    INNER JOIN patients p           ON pf.patient_id = p.id
    LEFT  JOIN users u              ON pf.doctor_id  = u.id
    LEFT  JOIN treatments t         ON ct.treatment_id = t.id
";

// ── Summary ──
$summary = $db->fetch(
    "SELECT COUNT(*) total,
            COALESCE(SUM(ct.final_price),0) revenue,
            COALESCE(SUM(ct.discount),0)    discount,
            COUNT(DISTINCT p.id) patients,
            COUNT(DISTINCT pf.doctor_id) doctors,
            COUNT(DISTINCT ct.treatment_id) distinct_tx
     $joins WHERE $where", $params);

$total    = (int)$summary['total'];
$revenue  = (float)$summary['revenue'];
$discount = (float)$summary['discount'];
$avgPrice = $total > 0 ? $revenue / $total : 0;
$pct      = fn($n) => $total > 0 ? round($n / $total * 100, 1) : 0;

// ── Per treatment ──
$byTreatment = $db->fetchAll(
    "SELECT ct.treatment_id, COALESCE(t.treatment_name, ct.treatment_name) AS treatment_name,
            t.category,
            COUNT(*) cnt,
            COALESCE(SUM(ct.final_price),0) total_price,
            COALESCE(AVG(ct.final_price),0) avg_price
     $joins WHERE $where
     GROUP BY ct.treatment_id, treatment_name, t.category
     ORDER BY cnt DESC, total_price DESC", $params);

// ── Per category ──
$byCategory = $db->fetchAll(
    "SELECT COALESCE(t.category, 'other') cat,
            COUNT(*) cnt,
            COALESCE(SUM(ct.final_price),0) total_price
     $joins WHERE $where
     GROUP BY cat ORDER BY cnt DESC", $params);

// ── Per doctor ──
$byDoctor = $db->fetchAll(
    "SELECT pf.doctor_id, u.full_name AS doctor_name,
            COUNT(*) cnt,
            COUNT(DISTINCT p.id) patients,
            COALESCE(SUM(ct.final_price),0) total_price
     $joins WHERE $where
     GROUP BY pf.doctor_id, u.full_name ORDER BY cnt DESC", $params);

// ── Top patients ──
$byPatient = $db->fetchAll(
    "SELECT p.id, p.full_name, p.patient_code,
            COUNT(*) cnt,
            COALESCE(SUM(ct.final_price),0) total_price
     $joins WHERE $where
     GROUP BY p.id, p.full_name, p.patient_code
     ORDER BY cnt DESC, total_price DESC LIMIT 15", $params);

// ── Status breakdown ──
$byStatus = $db->fetchAll(
    "SELECT ct.status, COUNT(*) cnt, COALESCE(SUM(ct.final_price),0) total_price
     $joins WHERE $where
     GROUP BY ct.status", $params);
$statusMap = ['pending'=>0,'approved'=>0,'completed'=>0,'cancelled'=>0];
$statusRev = ['pending'=>0,'approved'=>0,'completed'=>0,'cancelled'=>0];
foreach ($byStatus as $r) { $statusMap[$r['status']] = (int)$r['cnt']; $statusRev[$r['status']] = (float)$r['total_price']; }

// ── Detail rows (limited) ──
$detail = $db->fetchAll(
    "SELECT ct.id, ct.treatment_name, ct.tooth_number, ct.quantity, ct.unit_price,
            ct.final_price, ct.discount, ct.status, ct.created_at,
            COALESCE(t.treatment_name, ct.treatment_name) AS norm_name,
            t.category,
            p.full_name AS patient_name, p.patient_code,
            u.full_name AS doctor_name,
            c.case_number
     $joins WHERE $where
     ORDER BY ct.created_at DESC LIMIT 500", $params);

// Lookups
$doctors    = $db->fetchAll("SELECT id, full_name FROM users WHERE role='doctor' AND is_active=1 ORDER BY full_name");
$treatments = $db->fetchAll("SELECT id, treatment_name, category, default_price FROM treatments WHERE is_active=1 ORDER BY treatment_name");

// Patient name for display (if filter set)
$activePatientName = '';
if ($activePatient) {
    $pr = $db->fetch("SELECT full_name FROM patients WHERE id=?", [$activePatient]);
    $activePatientName = $pr['full_name'] ?? '';
}

// Labels
$categoryLabels = [
    'all'=>'هەموو','examination'=>'پشکنین','cleaning'=>'پاککردنەوە','filling'=>'پڕکردنەوە',
    'extraction'=>'دەرهێنان','root_canal'=>'گەڕی ڕەگ','crown'=>'تاج','bridge'=>'پرد',
    'implant'=>'چاندن','orthodontic'=>'پەلکەزێرینە','whitening'=>'سپیکردنەوە',
    'cosmetic'=>'جوانکاری','surgery'=>'نەشتەرگەری','other'=>'تر',
];
$categoryColors = [
    'examination'=>'#0ea5e9','cleaning'=>'#06b6d4','filling'=>'#16a34a',
    'extraction'=>'#dc2626','root_canal'=>'#f59e0b','crown'=>'#7c3aed',
    'bridge'=>'#0891b2','implant'=>'#ea580c','orthodontic'=>'#be185d',
    'whitening'=>'#84cc16','cosmetic'=>'#e11d48','surgery'=>'#991b1b','other'=>'#64748b',
];
$statusLabels = ['pending'=>'چاوەڕوان','approved'=>'پەسەند','completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە'];

$pageTitle = 'ڕاپۆرتی چارەسەرەکان';
$breadcrumb = [
    ['title' => 'ڕاپۆرتەکان', 'url' => APP_URL . '/reports.php'],
    ['title' => 'چارەسەرەکان']
];
require_once __DIR__ . '/includes/header.php';

// PDF URL carrying all filters
$pdfQ = array_filter([
    'mode' => $mode,
    'from' => ($mode === 'range' ? $rangeFrom : null),
    'to'   => ($mode === 'range' ? $rangeTo   : null),
    'doctor_id'    => $activeDoctor,
    'patient_id'   => $activePatient,
    'treatment_id' => $activeTreatment,
    'category'     => $category !== 'all' ? $category : null,
    'status'       => $status   !== 'all' ? $status   : null,
], fn($v) => $v !== null && $v !== '');
$pdfUrl = APP_URL . '/treatments-report-pdf.php?' . http_build_query($pdfQ);
?>

<style>
html { overflow-y: scroll; scrollbar-gutter: stable; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
html::-webkit-scrollbar { width: 10px; }
html::-webkit-scrollbar-track { background: #f1f5f9; }
html::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; border: 2px solid #f1f5f9; }

.tr-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
.tr-header h3 { margin:0; font-size:20px; color:#0f172a; }
.tr-header .sub { color:#607d8b; font-size:13px; }

.tr-filter { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.tr-chip { padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; border-radius:20px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.tr-chip:hover { background:#e2e8f0; color:#0f172a; }
.tr-chip.active { background:#16a34a; border-color:#16a34a; color:#fff; }
.tr-sep { width:1px; height:24px; background:#e2e8f0; }
.tr-filter input[type=date], .tr-filter input[type=text], .tr-filter select { padding:6px 8px; border:1px solid #cfd8dc; border-radius:6px; font-size:13px; font-family: inherit; }
.tr-filter label { font-size:12px; color:#607d8b; }
.tr-apply { padding:6px 14px; background:#22c55e; color:#fff; border:1px solid #16a34a; border-radius:6px; cursor:pointer; font-size:13px; }
.tr-reset { padding:6px 14px; background:#fff; color:#64748b; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; font-size:13px; text-decoration:none; }

.tr-patient-search { position: relative; min-width: 180px; }
.tr-patient-search input { width: 100%; padding: 6px 30px 6px 10px; border: 1px solid #cfd8dc; border-radius: 6px; font-size: 13px; }
.tr-patient-search .clear-x { position:absolute; inset-inline-start:6px; top:50%; transform:translateY(-50%); color:#94a3b8; cursor:pointer; }
.tr-patient-results { position: absolute; top: 100%; inset-inline: 0; background: #fff; border: 1px solid #cfd8dc; border-radius: 6px; max-height: 240px; overflow-y: auto; z-index: 50; display: none; box-shadow: 0 6px 20px rgba(0,0,0,.1); }
.tr-patient-results.open { display: block; }
.tr-patient-results .res-item { padding: 8px 10px; cursor: pointer; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.tr-patient-results .res-item:hover { background: #e3f2fd; }
.tr-patient-results .res-item small { color: #757575; }

.tr-stats { display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; margin-bottom:14px; }
.tr-stat { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px; position:relative; overflow:hidden; }
.tr-stat::before { content:''; position:absolute; inset-block:0; inset-inline-end:0; width:4px; background:var(--accent, #0078d4); }
.tr-stat.count    { --accent:#7c3aed; }
.tr-stat.revenue  { --accent:#16a34a; }
.tr-stat.avg      { --accent:#0ea5e9; }
.tr-stat.patients { --accent:#f59e0b; }
.tr-stat.doctors  { --accent:#0891b2; }
.tr-stat.discount { --accent:#dc2626; }
.tr-stat .label { font-size:12px; color:#607d8b; margin-bottom:4px; }
.tr-stat .value { font-size:18px; font-weight:800; color:#0f172a; line-height:1.15; }
.tr-stat.revenue .value  { color:#16a34a; }
.tr-stat.discount .value { color:#dc2626; }
.tr-stat .sub { font-size:10px; color:#607d8b; margin-top:4px; }

.tr-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
.tr-card { background:#fff; border:1px solid #e3e8ee; border-radius:10px; overflow:hidden; }
.tr-card .hd { padding:10px 14px; border-bottom:1px solid #eef2f5; font-weight:700; color:#0f172a; font-size:14px; background:linear-gradient(90deg,#f8fafc 0%,#fff 100%); display:flex; justify-content:space-between; align-items:center; }
.tr-list { max-height: 400px; overflow-y: auto; }
.tr-list table { width: 100%; border-collapse: collapse; font-size: 13px; }
.tr-list th { background: #f7f9fc; color: #37474f; font-weight: 600; padding: 8px 10px; text-align: center; border-bottom: 1px solid #e3e8ee; font-size: 12px; position: sticky; top: 0; }
.tr-list td { padding: 8px 10px; border-bottom: 1px solid #eef2f5; text-align: center; vertical-align: middle; }
.tr-list tr:hover td { background: #fafcff; }
.tr-list .name { text-align: start; font-weight: 700; color: #0f172a; }
.tr-list .pcode { font-size: 10px; color: #94a3b8; direction: ltr; font-family: var(--app-font-family); margin-top: 2px; display: block; }
.tr-list .count-pill { background:#e0e7ff; color:#4338ca; padding:2px 10px; border-radius:12px; font-weight:700; font-size:12px; }
.tr-list .cat-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; color:#fff; }
.tr-list .v-revenue { color:#15803d; font-weight:700; }
.tr-list .mini-bar { display:inline-block; width:60px; height:6px; background:#e5e7eb; border-radius:3px; vertical-align:middle; margin-inline-start:4px; overflow:hidden; }
.tr-list .mini-bar div { height:100%; background:linear-gradient(90deg,#7c3aed,#5b21b6); }

.status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; }
.st-completed { background:#e8f5e9; color:#2e7d32; }
.st-approved  { background:#e3f2fd; color:var(--win-accent, #0078d4); }
.st-pending   { background:#fff3e0; color:#f57c00; }
.st-cancelled { background:#ffebee; color:#c62828; }

.empty { padding:30px; text-align:center; color:#9aa7b3; font-size:13px; }
.active-filter-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.active-filter-pills .pill { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 3px 10px; border-radius: 20px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }
.active-filter-pills .pill a { color: #1d4ed8; text-decoration: none; }

@media (max-width: 1200px) { .tr-stats { grid-template-columns:repeat(3,1fr); } .tr-grid { grid-template-columns:1fr; } }
@media (max-width: 640px) {
    .tr-stats { grid-template-columns:repeat(2,1fr); }
    .tr-stat .value { font-size:14px; }
    .tr-header h3 { font-size:16px; }
    .tr-chip { padding:5px 10px; font-size:12px; }
    .tr-filter input[type=date], .tr-filter input[type=text], .tr-filter select { font-size:12px; padding:5px 7px; }
}
</style>

<div class="tr-header">
    <div>
        <h3><i class="fas fa-tooth"></i> ڕاپۆرتی شیکاری چارەسەرەکان</h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?><?= $rangeFrom !== $rangeTo ? ' بۆ ' . htmlspecialchars($rangeTo) : '' ?></div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn" style="background:#c62828;border-color:#c62828;color:#fff;">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= APP_URL ?>/reports.php" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i> گەڕانەوە
        </a>
    </div>
</div>

<?php
$chips = [
    ['today',     'ئەمڕۆ',         'fa-calendar-day'],
    ['yesterday', 'دوێنێ',         'fa-arrow-right'],
    ['week',      'ئەم هەفتەیە',    'fa-calendar-week'],
    ['month',     'ئەم مانگە',      'fa-calendar-alt'],
    ['year',      'ئەمساڵ',         'fa-calendar'],
];
?>
<form method="GET" action="" class="tr-filter" id="trFilterForm">
    <?php foreach ($chips as [$k, $lbl, $ic]): ?>
    <button type="submit" name="mode" value="<?= $k ?>" class="tr-chip <?= $mode === $k ? 'active' : '' ?>" style="border:none;font-family:inherit;">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
    </button>
    <?php endforeach; ?>

    <span class="tr-sep"></span>

    <input type="hidden" name="mode" value="range" id="modeRange">
    <label>لە</label>
    <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
    <label>بۆ</label>
    <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">

    <span class="tr-sep"></span>

    <?php if ($hasFullAccess): ?>
    <label><i class="fas fa-user-md"></i> دکتۆر</label>
    <select name="doctor_id">
        <option value="">هەموو</option>
        <?php foreach ($doctors as $d): ?>
        <option value="<?= $d['id'] ?>" <?= (string)$activeDoctor === (string)$d['id'] ? 'selected' : '' ?>>د. <?= htmlspecialchars($d['full_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <label><i class="fas fa-layer-group"></i> جۆر</label>
    <select name="category">
        <?php foreach ($categoryLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $category === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
    </select>

    <label><i class="fas fa-briefcase-medical"></i> چارەسەر</label>
    <select name="treatment_id">
        <option value="">هەموو</option>
        <?php foreach ($treatments as $t): ?>
        <option value="<?= $t['id'] ?>" <?= (string)$activeTreatment === (string)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['treatment_name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label><i class="fas fa-info-circle"></i> دۆخ</label>
    <select name="status">
        <option value="all"       <?= $status === 'all'       ? 'selected' : '' ?>>هەموو</option>
        <option value="pending"   <?= $status === 'pending'   ? 'selected' : '' ?>>چاوەڕوان</option>
        <option value="approved"  <?= $status === 'approved'  ? 'selected' : '' ?>>پەسەند</option>
        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>تەواوبوو</option>
        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>هەڵوەشاوە</option>
    </select>

    <span class="tr-sep"></span>

    <label><i class="fas fa-user-injured"></i> نەخۆش</label>
    <div class="tr-patient-search">
        <input type="hidden" name="patient_id" id="patientIdHidden" value="<?= htmlspecialchars($activePatient ?? '') ?>">
        <input type="text" id="patientSearchInput" placeholder="ناو یان ژمارەی مۆبایل..." autocomplete="off"
               value="<?= htmlspecialchars($activePatientName) ?>">
        <span class="clear-x" id="patientClear" title="لابردن" <?= !$activePatient ? 'style="display:none;"' : '' ?>>
            <i class="fas fa-times"></i>
        </span>
        <div id="patientResults" class="tr-patient-results"></div>
    </div>

    <button type="submit" class="tr-apply"><i class="fas fa-search"></i> گەڕان</button>
    <a href="?mode=today" class="tr-reset"><i class="fas fa-times"></i> پاککردنەوە</a>
</form>

<?php
// Active filter pills
$activeFilterList = [];
if ($activeDoctor) {
    $dn = $db->fetch("SELECT full_name FROM users WHERE id=?", [$activeDoctor]);
    $activeFilterList[] = ['د. ' . ($dn['full_name'] ?? ''), 'doctor_id'];
}
if ($activePatient && $activePatientName) $activeFilterList[] = [$activePatientName, 'patient_id'];
if ($activeTreatment) {
    $tn = $db->fetch("SELECT treatment_name FROM treatments WHERE id=?", [$activeTreatment]);
    $activeFilterList[] = [$tn['treatment_name'] ?? '', 'treatment_id'];
}
if ($category !== 'all') $activeFilterList[] = [$categoryLabels[$category], 'category'];
if ($status !== 'all')   $activeFilterList[] = [$statusLabels[$status] ?? $status, 'status'];

if (!empty($activeFilterList)): ?>
<div class="active-filter-pills">
    <?php foreach ($activeFilterList as [$label, $key]):
        $qs = $_GET; unset($qs[$key]);
        $clearUrl = '?' . http_build_query($qs);
    ?>
    <span class="pill">
        <?= htmlspecialchars($label) ?>
        <a href="<?= htmlspecialchars($clearUrl) ?>" title="لابردنی فلتەر"><i class="fas fa-times"></i></a>
    </span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tr-stats">
    <div class="tr-stat count">
        <div class="label">کۆی چارەسەرەکان</div>
        <div class="value"><?= number_format($total) ?></div>
        <div class="sub"><?= (int)$summary['distinct_tx'] ?> جۆری جیاواز</div>
    </div>
    <div class="tr-stat revenue">
        <div class="label">کۆی داهات</div>
        <div class="value"><?= number_format($revenue) ?></div>
        <div class="sub">دوای داشکاندن</div>
    </div>
    <div class="tr-stat avg">
        <div class="label">ناوەندی نرخ</div>
        <div class="value"><?= number_format($avgPrice) ?></div>
        <div class="sub">بۆ هەر چارەسەرێک</div>
    </div>
    <div class="tr-stat patients">
        <div class="label">نەخۆش</div>
        <div class="value"><?= number_format((int)$summary['patients']) ?></div>
        <div class="sub">جیاواز</div>
    </div>
    <div class="tr-stat doctors">
        <div class="label">دکتۆر</div>
        <div class="value"><?= number_format((int)$summary['doctors']) ?></div>
        <div class="sub">چالاک</div>
    </div>
    <div class="tr-stat discount">
        <div class="label">کۆی داشکاندن</div>
        <div class="value"><?= number_format($discount) ?></div>
        <div class="sub"><?= $revenue + $discount > 0 ? round($discount/($revenue+$discount)*100, 1) : 0 ?>%</div>
    </div>
</div>

<!-- Status strip -->
<div class="tr-card" style="margin-bottom:14px;">
    <div class="hd"><span><i class="fas fa-info-circle"></i> دۆخی چارەسەرەکان</span></div>
    <div style="padding:12px; display:grid; grid-template-columns:repeat(4,1fr); gap:10px;">
        <?php foreach ($statusMap as $st => $cnt): ?>
        <div style="background:#f8fafc; border:1px solid #eef2f5; padding:10px; border-radius:8px; text-align:center;">
            <div style="font-size:11px; color:#64748b;"><?= $statusLabels[$st] ?? $st ?></div>
            <div style="font-size:18px; font-weight:800; color:#0f172a; margin:3px 0;"><?= $cnt ?></div>
            <div style="font-size:10px; color:#15803d; font-weight:600;"><?= number_format($statusRev[$st]) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="tr-grid">
    <!-- Per category -->
    <div class="tr-card">
        <div class="hd"><span><i class="fas fa-layer-group"></i> دابەشبوون بەپێی جۆری چارەسەر</span></div>
        <?php if (empty($byCategory)): ?>
        <div class="empty">هیچ تۆمارێک نییە</div>
        <?php else: ?>
        <div class="tr-list">
            <table>
                <thead><tr><th>#</th><th>جۆر</th><th>ژمارە</th><th>داهات</th><th>ڕێژە</th></tr></thead>
                <tbody>
                <?php foreach ($byCategory as $i => $r):
                    $catKey = $r['cat'] ?: 'other';
                    $color = $categoryColors[$catKey] ?? '#64748b';
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="name">
                        <span class="cat-badge" style="background:<?= $color ?>;"><?= $categoryLabels[$catKey] ?? $catKey ?></span>
                    </td>
                    <td><span class="count-pill"><?= $r['cnt'] ?></span></td>
                    <td class="v-revenue"><?= number_format($r['total_price']) ?></td>
                    <td><?= $pct($r['cnt']) ?>%<div class="mini-bar"><div style="width:<?= $pct($r['cnt']) ?>%;"></div></div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Per treatment -->
    <div class="tr-card">
        <div class="hd"><span><i class="fas fa-briefcase-medical"></i> دابەشبوون بەپێی ناوی چارەسەر</span></div>
        <?php if (empty($byTreatment)): ?>
        <div class="empty">هیچ تۆمارێک نییە</div>
        <?php else: ?>
        <div class="tr-list">
            <table>
                <thead><tr><th>#</th><th>چارەسەر</th><th>ژمارە</th><th>کۆی داهات</th><th>ناوەند</th></tr></thead>
                <tbody>
                <?php foreach ($byTreatment as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="name">
                        <?= htmlspecialchars($r['treatment_name']) ?>
                        <?php if ($r['category']): ?>
                        <span class="cat-badge" style="background:<?= $categoryColors[$r['category']] ?? '#64748b' ?>; font-size:9px;"><?= $categoryLabels[$r['category']] ?? $r['category'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="count-pill"><?= $r['cnt'] ?></span></td>
                    <td class="v-revenue"><?= number_format($r['total_price']) ?></td>
                    <td><?= number_format($r['avg_price']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="tr-grid">
    <!-- Per doctor -->
    <div class="tr-card">
        <div class="hd"><span><i class="fas fa-user-md"></i> دکتۆرەکان</span></div>
        <?php if (empty($byDoctor)): ?>
        <div class="empty">هیچ تۆمارێک نییە</div>
        <?php else: ?>
        <div class="tr-list">
            <table>
                <thead><tr><th>#</th><th>دکتۆر</th><th>چارەسەر</th><th>نەخۆش</th><th>داهات</th></tr></thead>
                <tbody>
                <?php foreach ($byDoctor as $i => $d): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="name">د. <?= htmlspecialchars($d['doctor_name'] ?? '—') ?></td>
                    <td><span class="count-pill"><?= $d['cnt'] ?></span></td>
                    <td><?= $d['patients'] ?></td>
                    <td class="v-revenue"><?= number_format($d['total_price']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Top patients -->
    <div class="tr-card">
        <div class="hd"><span><i class="fas fa-user-injured"></i> نەخۆشە بەرزەکان (زۆرترین چارەسەر)</span></div>
        <?php if (empty($byPatient)): ?>
        <div class="empty">هیچ تۆمارێک نییە</div>
        <?php else: ?>
        <div class="tr-list">
            <table>
                <thead><tr><th>#</th><th>نەخۆش</th><th>چارەسەر</th><th>داهات</th></tr></thead>
                <tbody>
                <?php foreach ($byPatient as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="name">
                        <?= htmlspecialchars($p['full_name']) ?>
                        <span class="pcode"><?= htmlspecialchars($p['patient_code']) ?></span>
                    </td>
                    <td><span class="count-pill"><?= $p['cnt'] ?></span></td>
                    <td class="v-revenue"><?= number_format($p['total_price']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Detail rows -->
<div class="tr-card">
    <div class="hd">
        <span><i class="fas fa-list"></i> خشتەی چارەسەرەکان</span>
        <span style="font-size:12px; color:#607d8b; font-weight:400;">نیشاندانی <?= min(count($detail), 500) ?> تۆمار<?= count($detail) >= 500 ? ' (سنوور: ٥٠٠)' : '' ?></span>
    </div>
    <?php if (empty($detail)): ?>
    <div class="empty">هیچ چارەسەرێک نەدۆزرایەوە</div>
    <?php else: ?>
    <div class="tr-list" style="max-height: 520px;">
        <table>
            <thead><tr>
                <th>#</th>
                <th>بەروار/کات</th>
                <th>نەخۆش</th>
                <th>دکتۆر</th>
                <th>چارەسەر</th>
                <th>ددان</th>
                <th>ژ.</th>
                <th>نرخ</th>
                <th>داشکاندن</th>
                <th>کۆتایی</th>
                <th>دۆخ</th>
            </tr></thead>
            <tbody>
                <?php foreach ($detail as $i => $d): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= date('Y-m-d', strtotime($d['created_at'])) ?><br><small style="color:#94a3b8;"><?= date('H:i', strtotime($d['created_at'])) ?></small></td>
                    <td class="name"><?= htmlspecialchars($d['patient_name']) ?><span class="pcode"><?= htmlspecialchars($d['patient_code']) ?></span></td>
                    <td>د. <?= htmlspecialchars($d['doctor_name'] ?? '—') ?></td>
                    <td style="text-align:start;">
                        <?= htmlspecialchars($d['norm_name']) ?>
                        <?php if ($d['category']): ?>
                        <br><span class="cat-badge" style="background:<?= $categoryColors[$d['category']] ?? '#64748b' ?>; font-size:9px; margin-top:2px;"><?= $categoryLabels[$d['category']] ?? $d['category'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $d['tooth_number'] ?: '—' ?></td>
                    <td><?= (int)$d['quantity'] ?></td>
                    <td><?= number_format($d['unit_price']) ?></td>
                    <td style="color:#f59e0b; font-weight:700;"><?= number_format($d['discount']) ?></td>
                    <td class="v-revenue"><?= number_format($d['final_price']) ?></td>
                    <td><span class="status-badge st-<?= htmlspecialchars($d['status']) ?>"><?= $statusLabels[$d['status']] ?? $d['status'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
(function() {
    const input = document.getElementById('patientSearchInput');
    const hidden = document.getElementById('patientIdHidden');
    const box = document.getElementById('patientResults');
    const clearBtn = document.getElementById('patientClear');
    let timer = null;

    function showClear(show) { clearBtn.style.display = show ? '' : 'none'; }

    input.addEventListener('input', function() {
        const q = this.value.trim();
        hidden.value = '';
        showClear(false);
        if (timer) clearTimeout(timer);
        if (q.length < 2) { box.classList.remove('open'); return; }
        timer = setTimeout(async () => {
            try {
                const r = await fetch('<?= APP_URL ?>/api/search.php?type=patients&q=' + encodeURIComponent(q));
                const data = await r.json();
                if (!data || !data.length) { box.innerHTML = '<div class="res-item" style="color:#94a3b8;">هیچ نەخۆشێک نەدۆزرایەوە</div>'; }
                else {
                    box.innerHTML = data.slice(0, 10).map(p =>
                        `<div class="res-item" data-id="${p.id}" data-name="${p.full_name.replace(/"/g,'&quot;')}">
                            <strong>${p.full_name}</strong> <small>${p.phone || ''} · ${p.patient_code || ''}</small>
                        </div>`).join('');
                }
                box.classList.add('open');
            } catch (e) {
                box.classList.remove('open');
            }
        }, 250);
    });

    box.addEventListener('click', function(e) {
        const item = e.target.closest('.res-item');
        if (!item || !item.dataset.id) return;
        hidden.value = item.dataset.id;
        input.value = item.dataset.name;
        box.classList.remove('open');
        showClear(true);
    });

    clearBtn.addEventListener('click', function() {
        hidden.value = ''; input.value = ''; showClear(false);
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.tr-patient-search')) box.classList.remove('open');
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
