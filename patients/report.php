<?php
/**
 * Daily Patient Report
 * ڕاپۆرتی ڕۆژانەی نەخۆشەکان — نوێکان، کەیس و چارەسەر و پارەدان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

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

$today = date('Y-m-d');

// ── Filters ──
$mode     = $_GET['mode']      ?? 'today';
$fromIn   = $_GET['from']      ?? '';
$toIn     = $_GET['to']        ?? '';
$doctorIn = $_GET['doctor_id'] ?? '';
$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = 'ئەمڕۆ';
switch ($mode) {
    case 'today':
        $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; break;
    case 'yesterday':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('-1 day')); $rangeLabel = 'دوێنێ'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'month':
        $rangeFrom = date('Y-m-01'); $rangeTo = date('Y-m-t'); $rangeLabel = 'ئەم مانگە'; break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} بۆ {$rangeTo}";
        } else { $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; }
        break;
}

$doctorFilter = is_numeric($doctorIn) ? intval($doctorIn) : null;
if ($isDoctor && !$hasFullAccess) { $doctorFilter = $currentUser['id']; }

$start = $rangeFrom . ' 00:00:00';
$end   = $rangeTo   . ' 23:59:59';

$baseP = ['start' => $start, 'end' => $end];
$doctorWhere = '';
if ($doctorFilter) {
    $doctorWhere = " AND pf.doctor_id = :doc";
    $baseP['doc'] = $doctorFilter;
}

// ── New patients (not doctor-scoped; if doctor filter is set, restrict to patients who have a folder with this doctor) ──
if ($doctorFilter) {
    $newPatients = $db->fetchAll(
        "SELECT DISTINCT p.id, p.patient_code, p.full_name, p.phone, p.gender, p.created_at
         FROM patients p
         INNER JOIN patient_folders pf ON p.id = pf.patient_id
         WHERE p.created_at BETWEEN :start AND :end AND pf.doctor_id = :doc
         ORDER BY p.created_at DESC",
        $baseP
    );
} else {
    $newPatients = $db->fetchAll(
        "SELECT id, patient_code, full_name, phone, gender, created_at
         FROM patients
         WHERE created_at BETWEEN :start AND :end
         ORDER BY created_at DESC",
        ['start' => $start, 'end' => $end]
    );
}

// Money on this report comes from the invoice ledger (v_case_financial_summary),
// not from the cached pf./c. total_amount-paid_amount-discount_amount columns.
// Those carry only the legacy case-level discount, so a discount given on a
// receipt never reached them and "ماوە" came out too high by that discount.
// Same source as cases/view.php, the approval center and the financial summary.

// ── New folders ── (ledger rolled up over the folder's cases)
$newFolders = $db->fetchAll(
    "SELECT pf.id, pf.folder_name, pf.folder_type, pf.created_at,
            COALESCE(fl.total_invoiced, 0) AS total_amount,
            COALESCE(fl.paid_amount, 0)    AS paid_amount,
            COALESCE(fl.total_discount, 0) AS discount_amount,
            COALESCE(fl.balance, 0)        AS remaining_amount,
            p.full_name AS patient_name, p.patient_code,
            u.full_name AS doctor_name, u.room_number
     FROM patient_folders pf
     INNER JOIN patients p ON pf.patient_id = p.id
     LEFT JOIN users u    ON pf.doctor_id   = u.id
     LEFT JOIN (
        SELECT c.folder_id,
               SUM(fs.total_invoiced) AS total_invoiced,
               SUM(fs.total_discount) AS total_discount,
               SUM(fs.paid_amount)    AS paid_amount,
               SUM(fs.balance)        AS balance
        FROM cases c
        JOIN v_case_financial_summary fs ON fs.case_id = c.id
        GROUP BY c.folder_id
     ) fl ON fl.folder_id = pf.id
     WHERE pf.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY pf.created_at DESC",
    $baseP
);

// ── New cases ──
$newCases = $db->fetchAll(
    "SELECT c.id, c.case_number, c.case_name, c.scope_type, c.status, c.created_at,
            COALESCE(fs.total_invoiced, 0) AS total_amount,
            COALESCE(fs.paid_amount, 0)    AS paid_amount,
            COALESCE(fs.total_discount, 0) AS discount_amount,
            COALESCE(fs.balance, 0)        AS remaining_amount,
            p.full_name AS patient_name, p.patient_code,
            u.full_name AS doctor_name
     FROM cases c
     INNER JOIN patient_folders pf ON c.folder_id  = pf.id
     INNER JOIN patients p         ON pf.patient_id = p.id
     LEFT JOIN users u             ON pf.doctor_id  = u.id
     LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
     WHERE c.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY c.created_at DESC",
    $baseP
);

// ── Treatments ──
$treatments = $db->fetchAll(
    "SELECT ct.id, ct.treatment_name, ct.tooth_number, ct.quantity, ct.unit_price,
            ct.final_price, ct.discount, ct.status, ct.created_at,
            p.full_name AS patient_name, p.patient_code,
            u.full_name AS doctor_name,
            c.case_number
     FROM case_treatments ct
     INNER JOIN cases c              ON ct.case_id    = c.id
     INNER JOIN patient_folders pf   ON c.folder_id   = pf.id
     INNER JOIN patients p           ON pf.patient_id = p.id
     LEFT JOIN users u               ON pf.doctor_id  = u.id
     WHERE ct.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY ct.created_at DESC",
    $baseP
);

// ── Payments ──
$payments = $db->fetchAll(
    "SELECT pay.id, pay.amount, pay.payment_method, pay.payment_type, pay.status, pay.created_at,
            c.case_number,
            p.full_name AS patient_name, p.patient_code,
            u.full_name AS doctor_name
     FROM payments pay
     INNER JOIN cases c              ON pay.case_id   = c.id
     INNER JOIN patient_folders pf   ON c.folder_id   = pf.id
     INNER JOIN patients p           ON pf.patient_id = p.id
     LEFT JOIN users u               ON pf.doctor_id  = u.id
     WHERE pay.created_at BETWEEN :start AND :end {$doctorWhere}
     ORDER BY pay.created_at DESC",
    $baseP
);

// Aggregates
$sumFolders    = ['total'=>0,'paid'=>0,'disc'=>0,'rem'=>0];
foreach ($newFolders as $f) { $sumFolders['total'] += (float)$f['total_amount']; $sumFolders['paid'] += (float)$f['paid_amount']; $sumFolders['disc'] += (float)$f['discount_amount']; $sumFolders['rem'] += (float)$f['remaining_amount']; }

$sumCases      = ['total'=>0,'paid'=>0,'disc'=>0,'rem'=>0];
foreach ($newCases as $c) { $sumCases['total'] += (float)$c['total_amount']; $sumCases['paid'] += (float)$c['paid_amount']; $sumCases['disc'] += (float)$c['discount_amount']; $sumCases['rem'] += (float)$c['remaining_amount']; }

$sumTreatments = ['count'=>count($treatments), 'final'=>0, 'discount'=>0];
foreach ($treatments as $t) { $sumTreatments['final'] += (float)$t['final_price']; $sumTreatments['discount'] += (float)$t['discount']; }

$sumPayments   = ['count'=>count($payments), 'total'=>0, 'approved'=>0];
foreach ($payments as $pay) {
    $sumPayments['total'] += (float)$pay['amount'];
    if (in_array($pay['status'], ['approved','completed'], true)) $sumPayments['approved'] += (float)$pay['amount'];
}

// Overall remaining = sum of each folder's own ledger balance. Never netted:
// an overpaid folder must not cancel out another patient's debt.
$remaining = $sumFolders['rem'];

// Doctors for filter
$doctors = $hasFullAccess
    ? $db->fetchAll("SELECT id, full_name FROM users WHERE role='doctor' AND is_active=1 ORDER BY full_name")
    : [];

$pageTitle  = 'ڕاپۆرتی ڕۆژانەی نەخۆش';
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => 'ڕاپۆرتی ڕۆژانە']
];
$currentPage = 'report';
require_once __DIR__ . '/../includes/header.php';

// Build PDF URL with current filters
$pdfParams = ['mode' => $mode];
if ($mode === 'range') { $pdfParams['from'] = $rangeFrom; $pdfParams['to'] = $rangeTo; }
if ($doctorFilter && $hasFullAccess) { $pdfParams['doctor_id'] = $doctorFilter; }
$pdfUrl = APP_URL . '/patients/report-pdf.php?' . http_build_query($pdfParams);
?>

<style>
html { overflow-y: scroll; scrollbar-gutter: stable; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
html::-webkit-scrollbar { width: 10px; }
html::-webkit-scrollbar-track { background: #f1f5f9; }
html::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; border: 2px solid #f1f5f9; }

.pr-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
.pr-header h3 { margin:0; font-size:20px; color:#0f172a; }
.pr-header .sub { color:#607d8b; font-size:13px; }

.pr-filter { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.pr-chip { padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; border-radius:20px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.pr-chip:hover { background:#e2e8f0; color:#0f172a; }
.pr-chip.active { background:#16a34a; border-color:#16a34a; color:#fff; }
.pr-sep { width:1px; height:24px; background:#e2e8f0; }
.pr-filter input[type=date], .pr-filter select { padding:6px 8px; border:1px solid #cfd8dc; border-radius:6px; font-size:13px; }
.pr-filter label { font-size:12px; color:#607d8b; }
.pr-apply { padding:6px 14px; background:#22c55e; color:#fff; border:1px solid #16a34a; border-radius:6px; cursor:pointer; font-size:13px; }

.pr-stats { display:grid; grid-template-columns:repeat(6, 1fr); gap:8px; margin-bottom:14px; }
.pr-stat { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px; position:relative; overflow:hidden; }
.pr-stat::before { content:''; position:absolute; inset-block:0; inset-inline-end:0; width:4px; background:var(--accent, #0078d4); }
.pr-stat.patients  { --accent:#0ea5e9; }
.pr-stat.folders   { --accent:#f59e0b; }
.pr-stat.cases     { --accent:#7c3aed; }
.pr-stat.treat     { --accent:#0891b2; }
.pr-stat.paid      { --accent:#16a34a; }
.pr-stat.remaining { --accent:#dc2626; }
.pr-stat .label { font-size:11px; color:#607d8b; margin-bottom:4px; }
.pr-stat .value { font-size:16px; font-weight:800; color:#0f172a; line-height:1.15; }
.pr-stat.paid .value      { color:#16a34a; }
.pr-stat.remaining .value { color:#dc2626; }
.pr-stat .sub   { font-size:10px; color:#607d8b; margin-top:4px; }

.section-card { background:#fff; border:1px solid #e3e8ee; border-radius:10px; margin-bottom:14px; overflow:hidden; transition: border-color .2s; }
.section-card.collapsed { border-color:#cbd5e1; }
.section-card .sec-head {
    padding:10px 14px; border-bottom:1px solid #eef2f5;
    font-weight:700; color:#0f172a; font-size:14px;
    display:flex; justify-content:space-between; align-items:center; gap:10px;
    background:linear-gradient(90deg, #f8fafc 0%, #fff 100%);
    cursor:pointer; user-select:none; transition: background .15s;
}
.section-card .sec-head:hover { background: #f1f5f9; }
.section-card .sec-head .toggle-chevron {
    display:inline-flex; align-items:center; justify-content:center;
    width:24px; height:24px; border-radius:50%;
    background:#e2e8f0; color:#475569; font-size:10px;
    transition: transform .25s, background .15s;
    flex-shrink:0;
}
.section-card .sec-head:hover .toggle-chevron { background:#cbd5e1; }
.section-card.collapsed .sec-head { border-bottom:none; }
.section-card.collapsed .sec-head .toggle-chevron { transform: rotate(-90deg); }
.section-card .sec-body { max-height: 5000px; transition: max-height .35s ease, opacity .25s ease; overflow:hidden; }
.section-card.collapsed .sec-body { max-height:0; opacity:0; }
.section-card .sec-head .sub-info { font-size:12px; color:#607d8b; font-weight:400; flex:1; }
.section-card .sec-head .sub-info .amt { color:#15803d; font-weight:700; }
.section-card .sec-head .sub-info .disc { color:#f59e0b; font-weight:700; }
.section-card .sec-head .sub-info .rem { color:#c62828; font-weight:700; }
.section-card .sec-head .title-span { display:flex; align-items:center; gap:6px; white-space:nowrap; }

.pr-toolbar { display:flex; gap:6px; margin-bottom:10px; flex-wrap:wrap; }
.pr-toolbar button { padding:5px 12px; background:#fff; color:#334155; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.pr-toolbar button:hover { background:#f1f5f9; }
.pr-toolbar button.all-open  { border-color:#16a34a; color:#16a34a; }
.pr-toolbar button.all-close { border-color:#dc2626; color:#dc2626; }
.pr-table-scroll { overflow-x:auto; }
.pr-table { width:100%; border-collapse:collapse; font-size:13px; min-width:800px; }
.pr-table th { background:#f7f9fc; color:#37474f; font-weight:600; padding:10px 8px; text-align:center; border-bottom:1px solid #e3e8ee; }
.pr-table td { padding:8px; border-bottom:1px solid #eef2f5; text-align:center; vertical-align:middle; }
.pr-table tr:hover td { background:#fafcff; }
.pr-table .pname { font-weight:700; color:#0f172a; text-align:start; }
.pr-table .pcode { font-size:11px; color:#64748b; display:block; direction:ltr; }
.pr-table .phone { direction:ltr; font-family:var(--app-font-family); font-size:12px; color:#0078d4; text-decoration:none; }
.pr-table .v-total    { color:#0f172a; font-weight:700; }
.pr-table .v-discount { color:#f59e0b; font-weight:700; }
.pr-table .v-paid     { color:#15803d; font-weight:700; }
.pr-table .v-remain   { color:#c62828; font-weight:700; }
.pr-table tfoot td { background:#f7f9fc; font-weight:700; }
.pr-table .status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; }
.pr-table .st-completed { background:#e8f5e9; color:#2e7d32; }
.pr-table .st-pending   { background:#fff3e0; color:#f57c00; }
.pr-table .st-approved  { background:#e3f2fd; color:#0078d4; }
.pr-table .st-cancelled { background:#ffebee; color:#c62828; }
.pr-table .st-rejected  { background:#ffebee; color:#c62828; }

.empty { padding:30px; text-align:center; color:#9aa7b3; font-size:13px; }

@media (max-width: 1100px) { .pr-stats { grid-template-columns:repeat(3,1fr); } }
@media (max-width: 640px) {
    .pr-stats { grid-template-columns:repeat(2,1fr); }
    .pr-header h3 { font-size:16px; }
    .pr-chip { padding:5px 10px; font-size:12px; }
    .pr-stat .value { font-size:13px; }
    .pr-stat .label { font-size:10px; }
    .pr-table th, .pr-table td { padding:6px 4px; font-size:11px; }
}
</style>

<div class="pr-header">
    <div>
        <h3><i class="fas fa-chart-line"></i> ڕاپۆرتی ڕۆژانەی نەخۆش</h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?><?= $rangeFrom !== $rangeTo ? ' بۆ ' . htmlspecialchars($rangeTo) : '' ?></div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" id="pdfExportBtn"
           data-base-url="<?= htmlspecialchars($pdfUrl) ?>"
           class="btn" style="background:#c62828;border-color:#c62828;color:#fff;">
            <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= APP_URL ?>/patients/index.php" class="btn btn-secondary">
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
];
$carry = [];
if ($doctorFilter && $hasFullAccess) $carry['doctor_id'] = $doctorFilter;
?>
<form method="GET" action="" class="pr-filter">
    <?php foreach ($chips as [$k, $lbl, $ic]): ?>
    <a href="?<?= http_build_query(array_merge($carry, ['mode' => $k])) ?>"
       class="pr-chip <?= $mode === $k ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>

    <span class="pr-sep"></span>

    <input type="hidden" name="mode" value="range">
    <label>لە</label>
    <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
    <label>بۆ</label>
    <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">

    <?php if ($hasFullAccess): ?>
    <span class="pr-sep"></span>
    <label><i class="fas fa-user-md"></i> دکتۆر</label>
    <select name="doctor_id">
        <option value="">— هەموو —</option>
        <?php foreach ($doctors as $d): ?>
        <option value="<?= $d['id'] ?>" <?= (string)$doctorFilter === (string)$d['id'] ? 'selected' : '' ?>>د. <?= htmlspecialchars($d['full_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <button type="submit" class="pr-apply"><i class="fas fa-search"></i> گەڕان</button>
</form>

<div class="pr-stats">
    <div class="pr-stat patients">
        <div class="label">نەخۆشی نوێ</div>
        <div class="value"><?= count($newPatients) ?></div>
        <div class="sub">تۆمارکراو</div>
    </div>
    <div class="pr-stat folders">
        <div class="label">فایلی نوێ</div>
        <div class="value"><?= count($newFolders) ?></div>
        <div class="sub"><?= number_format($sumFolders['total']) ?></div>
    </div>
    <div class="pr-stat cases">
        <div class="label">کەیسی نوێ</div>
        <div class="value"><?= count($newCases) ?></div>
        <div class="sub"><?= number_format($sumCases['total']) ?></div>
    </div>
    <div class="pr-stat treat">
        <div class="label">چارەسەرەکان</div>
        <div class="value"><?= $sumTreatments['count'] ?></div>
        <div class="sub"><?= number_format($sumTreatments['final']) ?></div>
    </div>
    <div class="pr-stat paid">
        <div class="label">پارەی وەرگیراو</div>
        <div class="value"><?= number_format($sumPayments['approved']) ?></div>
        <div class="sub"><?= $sumPayments['count'] ?> پارەدان</div>
    </div>
    <div class="pr-stat remaining">
        <div class="label">ماوە (قەرز)</div>
        <div class="value"><?= number_format($remaining) ?></div>
        <div class="sub">لە فایلە نوێیەکان</div>
    </div>
</div>

<div class="pr-toolbar">
    <button type="button" class="all-open"  onclick="toggleAllSections(true)"><i class="fas fa-eye"></i> نیشاندانی هەموو</button>
    <button type="button" class="all-close" onclick="toggleAllSections(false)"><i class="fas fa-eye-slash"></i> شاردنەوەی هەموو</button>
</div>

<!-- New Patients -->
<div class="section-card" data-section="patients">
    <div class="sec-head" onclick="toggleSection(this)">
        <span class="title-span"><span class="toggle-chevron"><i class="fas fa-chevron-down"></i></span><i class="fas fa-user-plus"></i> نەخۆشی نوێ تۆمارکراو (<?= count($newPatients) ?>)</span>
    </div>
    <div class="sec-body">
    <?php if (empty($newPatients)): ?>
    <div class="empty">هیچ نەخۆشێکی نوێ تۆمار نەکراوە</div>
    <?php else: ?>
    <div class="pr-table-scroll">
        <table class="pr-table">
            <thead><tr>
                <th>#</th>
                <th>کۆدی نەخۆش</th>
                <th>ناوی نەخۆش</th>
                <th>ڕەگەز</th>
                <th>ژ. مۆبایل</th>
                <th>کاتی تۆمارکردن</th>
            </tr></thead>
            <tbody>
                <?php foreach ($newPatients as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><span class="pcode"><?= htmlspecialchars($p['patient_code']) ?></span></td>
                    <td class="pname"><?= htmlspecialchars($p['full_name']) ?></td>
                    <td><?= $p['gender'] === 'male' ? 'نێر' : ($p['gender'] === 'female' ? 'مێ' : '—') ?></td>
                    <td>
                        <?php if (!empty($p['phone'])): ?>
                        <a href="tel:<?= htmlspecialchars($p['phone']) ?>" class="phone"><?= htmlspecialchars($p['phone']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= date('H:i', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- New Folders -->
<div class="section-card" data-section="folders">
    <div class="sec-head" onclick="toggleSection(this)">
        <span class="title-span"><span class="toggle-chevron"><i class="fas fa-chevron-down"></i></span><i class="fas fa-folder-plus"></i> فایلی نوێ (<?= count($newFolders) ?>)</span>
        <?php if (!empty($newFolders)): ?>
        <span class="sub-info">
            کۆی: <span class="amt"><?= number_format($sumFolders['total']) ?></span> ·
            وەرگیراو: <span class="amt"><?= number_format($sumFolders['paid']) ?></span> ·
            داشکاندن: <span class="disc"><?= number_format($sumFolders['disc']) ?></span> ·
            ماوە: <span class="rem"><?= number_format($sumFolders['total'] - $sumFolders['paid'] - $sumFolders['disc']) ?></span>
        </span>
        <?php endif; ?>
    </div>
    <div class="sec-body">
    <?php if (empty($newFolders)): ?>
    <div class="empty">هیچ فایلێکی نوێ دروست نەکراوە</div>
    <?php else: ?>
    <div class="pr-table-scroll">
        <table class="pr-table">
            <thead><tr>
                <th>#</th>
                <th>نەخۆش</th>
                <th>ناوی فایل</th>
                <th>دکتۆر</th>
                <th>کۆی گشتی</th>
                <th>داشکاندن</th>
                <th>وەرگیراو</th>
                <th>ماوە</th>
                <th>کات</th>
            </tr></thead>
            <tbody>
                <?php foreach ($newFolders as $i => $f):
                    $rem = (float)$f['remaining_amount']; ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="pname"><?= htmlspecialchars($f['patient_name']) ?><span class="pcode"><?= htmlspecialchars($f['patient_code']) ?></span></td>
                    <td><?= htmlspecialchars($f['folder_name']) ?></td>
                    <td><?= !empty($f['doctor_name']) ? 'د. ' . htmlspecialchars($f['doctor_name']) : '—' ?></td>
                    <td class="v-total"><?= number_format($f['total_amount']) ?></td>
                    <td class="v-discount"><?= number_format($f['discount_amount']) ?></td>
                    <td class="v-paid"><?= number_format($f['paid_amount']) ?></td>
                    <td class="v-remain"><?= number_format($rem) ?></td>
                    <td><?= date('H:i', strtotime($f['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:start; padding-inline-start:12px;">کۆی گشتی</td>
                    <td class="v-total"><?= number_format($sumFolders['total']) ?></td>
                    <td class="v-discount"><?= number_format($sumFolders['disc']) ?></td>
                    <td class="v-paid"><?= number_format($sumFolders['paid']) ?></td>
                    <td class="v-remain"><?= number_format($sumFolders['rem']) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- New Cases -->
<div class="section-card" data-section="cases">
    <div class="sec-head" onclick="toggleSection(this)">
        <span class="title-span"><span class="toggle-chevron"><i class="fas fa-chevron-down"></i></span><i class="fas fa-briefcase-medical"></i> کەیسی نوێ (<?= count($newCases) ?>)</span>
        <?php if (!empty($newCases)): ?>
        <span class="sub-info">
            کۆی: <span class="amt"><?= number_format($sumCases['total']) ?></span> ·
            وەرگیراو: <span class="amt"><?= number_format($sumCases['paid']) ?></span> ·
            داشکاندن: <span class="disc"><?= number_format($sumCases['disc']) ?></span>
        </span>
        <?php endif; ?>
    </div>
    <div class="sec-body">
    <?php if (empty($newCases)): ?>
    <div class="empty">هیچ کەیسێکی نوێ دروست نەکراوە</div>
    <?php else: ?>
    <div class="pr-table-scroll">
        <table class="pr-table">
            <thead><tr>
                <th>#</th>
                <th>نەخۆش</th>
                <th>ژمارە کەیس</th>
                <th>ناوی کەیس</th>
                <th>دکتۆر</th>
                <th>کۆی گشتی</th>
                <th>داشکاندن</th>
                <th>وەرگیراو</th>
                <th>ماوە</th>
            </tr></thead>
            <tbody>
                <?php foreach ($newCases as $i => $c):
                    $rem = (float)$c['remaining_amount']; ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="pname"><?= htmlspecialchars($c['patient_name']) ?><span class="pcode"><?= htmlspecialchars($c['patient_code']) ?></span></td>
                    <td><?= htmlspecialchars($c['case_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($c['case_name'] ?? '—') ?></td>
                    <td><?= !empty($c['doctor_name']) ? 'د. ' . htmlspecialchars($c['doctor_name']) : '—' ?></td>
                    <td class="v-total"><?= number_format($c['total_amount']) ?></td>
                    <td class="v-discount"><?= number_format($c['discount_amount']) ?></td>
                    <td class="v-paid"><?= number_format($c['paid_amount']) ?></td>
                    <td class="v-remain"><?= number_format($rem) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:start; padding-inline-start:12px;">کۆی گشتی</td>
                    <td class="v-total"><?= number_format($sumCases['total']) ?></td>
                    <td class="v-discount"><?= number_format($sumCases['disc']) ?></td>
                    <td class="v-paid"><?= number_format($sumCases['paid']) ?></td>
                    <td class="v-remain"><?= number_format($sumCases['rem']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- Treatments -->
<div class="section-card" data-section="treatments">
    <div class="sec-head" onclick="toggleSection(this)">
        <span class="title-span"><span class="toggle-chevron"><i class="fas fa-chevron-down"></i></span><i class="fas fa-tooth"></i> چارەسەرەکان (<?= count($treatments) ?>)</span>
        <?php if (!empty($treatments)): ?>
        <span class="sub-info">
            کۆی نرخ: <span class="amt"><?= number_format($sumTreatments['final']) ?></span> ·
            داشکاندن: <span class="disc"><?= number_format($sumTreatments['discount']) ?></span>
        </span>
        <?php endif; ?>
    </div>
    <div class="sec-body">
    <?php if (empty($treatments)): ?>
    <div class="empty">هیچ چارەسەرێک تۆمار نەکراوە</div>
    <?php else: ?>
    <div class="pr-table-scroll">
        <table class="pr-table">
            <thead><tr>
                <th>#</th>
                <th>نەخۆش</th>
                <th>دکتۆر</th>
                <th>کەیس</th>
                <th>چارەسەر</th>
                <th>ددان</th>
                <th>ژمارە</th>
                <th>نرخ</th>
                <th>دۆخ</th>
                <th>کات</th>
            </tr></thead>
            <tbody>
                <?php
                $stLabels = ['pending'=>'چاوەڕوان','approved'=>'پەسەند','completed'=>'تەواوبوو','cancelled'=>'هەڵوەشاوە'];
                foreach ($treatments as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="pname"><?= htmlspecialchars($t['patient_name']) ?><span class="pcode"><?= htmlspecialchars($t['patient_code']) ?></span></td>
                    <td><?= !empty($t['doctor_name']) ? 'د. ' . htmlspecialchars($t['doctor_name']) : '—' ?></td>
                    <td><?= htmlspecialchars($t['case_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($t['treatment_name']) ?></td>
                    <td><?= $t['tooth_number'] ? htmlspecialchars($t['tooth_number']) : '—' ?></td>
                    <td><?= (int)$t['quantity'] ?></td>
                    <td class="v-paid"><?= number_format($t['final_price']) ?></td>
                    <td><span class="status-badge st-<?= htmlspecialchars($t['status']) ?>"><?= $stLabels[$t['status']] ?? htmlspecialchars($t['status']) ?></span></td>
                    <td><?= date('H:i', strtotime($t['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" style="text-align:start; padding-inline-start:12px;">کۆی گشتی</td>
                    <td class="v-paid"><?= number_format($sumTreatments['final']) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- Payments -->
<div class="section-card" data-section="payments">
    <div class="sec-head" onclick="toggleSection(this)">
        <span class="title-span"><span class="toggle-chevron"><i class="fas fa-chevron-down"></i></span><i class="fas fa-money-bill-wave"></i> پارەدانەکان (<?= $sumPayments['count'] ?>)</span>
        <?php if (!empty($payments)): ?>
        <span class="sub-info">
            کۆی وەرگیراو: <span class="amt"><?= number_format($sumPayments['approved']) ?></span> ·
            کۆی ثبت کراو: <span class="amt"><?= number_format($sumPayments['total']) ?></span>
        </span>
        <?php endif; ?>
    </div>
    <div class="sec-body">
    <?php if (empty($payments)): ?>
    <div class="empty">هیچ پارەیەک وەرنەگیراوە</div>
    <?php else: ?>
    <div class="pr-table-scroll">
        <table class="pr-table">
            <thead><tr>
                <th>#</th>
                <th>نەخۆش</th>
                <th>دکتۆر</th>
                <th>کەیس</th>
                <th>جۆر</th>
                <th>ڕێگای پارەدان</th>
                <th>دۆخ</th>
                <th>بڕ</th>
                <th>کات</th>
            </tr></thead>
            <tbody>
                <?php
                $pmLabels = ['cash'=>'نەقد','card'=>'کارت','transfer'=>'گواستنەوە','other'=>'تر','advance'=>'لە ئامانەت'];
                $ptLabels = ['cash'=>'نەقد','credit'=>'قەرز','installment'=>'قیست'];
                $psLabels = ['pending'=>'چاوەڕوان','approved'=>'پەسەند','completed'=>'تەواوبوو','rejected'=>'ڕەتکراو','refunded'=>'گەڕاوە'];
                foreach ($payments as $i => $pay): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="pname"><?= htmlspecialchars($pay['patient_name']) ?><span class="pcode"><?= htmlspecialchars($pay['patient_code']) ?></span></td>
                    <td><?= !empty($pay['doctor_name']) ? 'د. ' . htmlspecialchars($pay['doctor_name']) : '—' ?></td>
                    <td><?= htmlspecialchars($pay['case_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($ptLabels[$pay['payment_type']] ?? $pay['payment_type']) ?></td>
                    <td><?= htmlspecialchars($pmLabels[$pay['payment_method']] ?? $pay['payment_method']) ?></td>
                    <td><span class="status-badge st-<?= htmlspecialchars($pay['status']) ?>"><?= $psLabels[$pay['status']] ?? htmlspecialchars($pay['status']) ?></span></td>
                    <td class="v-paid"><?= number_format($pay['amount']) ?></td>
                    <td><?= date('H:i', strtotime($pay['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" style="text-align:start; padding-inline-start:12px;">کۆی گشتی (پەسەندکراو)</td>
                    <td class="v-paid"><?= number_format($sumPayments['approved']) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const KEY = 'pr_collapsed_sections_v1';
    const getSaved = () => {
        try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; }
        catch(e) { return {}; }
    };
    const saveState = (map) => {
        try { localStorage.setItem(KEY, JSON.stringify(map)); } catch(e) {}
    };

    window.toggleSection = function(headerEl) {
        const card = headerEl.closest('.section-card');
        if (!card) return;
        card.classList.toggle('collapsed');
        const map = getSaved();
        map[card.dataset.section] = card.classList.contains('collapsed');
        saveState(map);
    };

    window.toggleAllSections = function(open) {
        const map = {};
        document.querySelectorAll('.section-card[data-section]').forEach(card => {
            card.classList.toggle('collapsed', !open);
            map[card.dataset.section] = !open;
        });
        saveState(map);
    };

    // Restore saved state on load
    const saved = getSaved();
    document.querySelectorAll('.section-card[data-section]').forEach(card => {
        if (saved[card.dataset.section]) card.classList.add('collapsed');
    });

    // Keep PDF URL in sync with the current collapsed sections
    function syncPdfUrl() {
        const btn = document.getElementById('pdfExportBtn');
        if (!btn) return;
        const base = btn.dataset.baseUrl || btn.href;
        const hidden = Array.from(document.querySelectorAll('.section-card.collapsed[data-section]'))
            .map(c => c.dataset.section);
        const joiner = base.includes('?') ? '&' : '?';
        btn.href = hidden.length ? base + joiner + 'hide=' + encodeURIComponent(hidden.join(',')) : base;
    }

    // Patch toggle functions to also update the PDF URL
    const origToggle = window.toggleSection;
    window.toggleSection = function(h) { origToggle(h); syncPdfUrl(); };
    const origAll = window.toggleAllSections;
    window.toggleAllSections = function(o) { origAll(o); syncPdfUrl(); };

    // Initial sync
    syncPdfUrl();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
