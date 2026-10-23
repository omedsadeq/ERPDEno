<?php
/**
 * Doctors General Financial Report
 * ڕاپۆرتی گشتی دکتۆرەکان — کۆی گشتی، داشکاندن، پارەی وەرگیراو، ماوە
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
$hasFullAccess  = $isAdmin || $isReceptionist || $isAccountant;
$enforcedDoctor = $hasFullAccess ? null : ($auth->isDoctor() ? $currentUser['id'] : 0);

$today = date('Y-m-d');

// ── Filters ──
$mode       = $_GET['mode']      ?? 'month';
$fromIn     = $_GET['from']      ?? '';
$toIn       = $_GET['to']        ?? '';
$doctorIn   = $_GET['doctor_id'] ?? '';
$folderType = $_GET['folder_type'] ?? 'all';
$folderStat = $_GET['folder_status'] ?? 'all';

$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today; $rangeTo = $today; $rangeLabel = '';
switch ($mode) {
    case 'today':
        $rangeFrom = $rangeTo = $today; $rangeLabel = 'ئەمڕۆ'; break;
    case 'week':
        $dow = (int)date('w'); $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە'; break;
    case 'quarter':
        $rangeFrom = date('Y-m-d', strtotime('-3 months +1 day'));
        $rangeTo   = $today; $rangeLabel = 'سێ مانگی ڕابردوو'; break;
    case 'half_year':
        $rangeFrom = date('Y-m-d', strtotime('-6 months +1 day'));
        $rangeTo   = $today; $rangeLabel = 'شەش مانگی ڕابردوو'; break;
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

$activeDoctor = $enforcedDoctor !== null && $enforcedDoctor > 0 ? $enforcedDoctor : (is_numeric($doctorIn) ? intval($doctorIn) : null);
if (!$hasFullAccess && $activeDoctor && $activeDoctor != $currentUser['id']) {
    $activeDoctor = $currentUser['id'];
}

$validFolderTypes  = ['all','general','cosmetic','orthodontic','surgery','other'];
$validFolderStatus = ['all','active','archived'];
$folderType = in_array($folderType, $validFolderTypes, true) ? $folderType : 'all';
$folderStat = in_array($folderStat, $validFolderStatus, true) ? $folderStat : 'all';

// ── Per-doctor aggregate query ──
// prior_paid = دراوی پێشوو of historical cases (money from before the system):
// it counts as paid so remaining stays right, but earns no commission.
$where  = "DATE(pf.created_at) BETWEEN :from AND :to";
$params = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($activeDoctor) { $where .= " AND pf.doctor_id = :doc"; $params['doc'] = $activeDoctor; }
if ($folderType !== 'all') { $where .= " AND pf.folder_type = :ftype"; $params['ftype'] = $folderType; }
if ($folderStat !== 'all') { $where .= " AND pf.status = :fstat"; $params['fstat'] = $folderStat; }

$sql = "SELECT u.id AS doctor_id,
               u.full_name AS doctor_name,
               u.room_number,
               COALESCE(u.commission_rate, 0) AS commission_rate,
               COUNT(DISTINCT pf.id) AS folder_count,
               COALESCE(SUM(pf.total_amount), 0)    AS total_amount,
               COALESCE(SUM(pf.discount_amount), 0) AS discount_amount,
               COALESCE(SUM(pf.paid_amount), 0)     AS paid_amount,
               COALESCE(SUM(pp.prior_paid), 0)      AS prior_paid,
               COALESCE(SUM(pf.total_amount - pf.paid_amount - pf.discount_amount), 0) AS remaining
        FROM users u
        LEFT JOIN patient_folders pf
            ON u.id = pf.doctor_id AND {$where}
        LEFT JOIN (
            SELECT c.folder_id,
                   SUM(LEAST(GREATEST(0, ci.total_amount - ci.discount_amount), GREATEST(0, COALESCE(ci.prior_paid_amount, 0)))) AS prior_paid
            FROM case_invoices ci
            JOIN cases c ON c.id = ci.case_id
            WHERE ci.accounting_status = 'approved' AND ci.prior_paid_amount > 0
            GROUP BY c.folder_id
        ) pp ON pp.folder_id = pf.id
        WHERE u.role = 'doctor' AND u.is_active = 1";
if ($activeDoctor) $sql .= " AND u.id = :doc2";
$sql .= " GROUP BY u.id, u.full_name, u.room_number, u.commission_rate
          ORDER BY total_amount DESC, u.full_name";
$docParams = $params;
if ($activeDoctor) $docParams['doc2'] = $activeDoctor;
$rows = $db->fetchAll($sql, $docParams);

// Doctors dropdown
$allDoctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role='doctor' AND is_active=1 ORDER BY full_name");

// ── Totals ──
$totals = [
    'folders'    => 0,
    'total'      => 0.0,
    'discount'   => 0.0,
    'paid'       => 0.0,
    'remaining'  => 0.0,
    'commission' => 0.0,
];
foreach ($rows as $r) {
    $totals['folders']    += (int)$r['folder_count'];
    $totals['total']      += (float)$r['total_amount'];
    $totals['discount']   += (float)$r['discount_amount'];
    $totals['paid']       += (float)$r['paid_amount'];
    $totals['remaining']  += (float)$r['remaining'];
    $totals['commission'] += max(0, (float)$r['paid_amount'] - (float)$r['prior_paid']) * ((float)$r['commission_rate'] / 100);
}

// Collection rate
$collectionRate = $totals['total'] > 0 ? round($totals['paid'] / $totals['total'] * 100, 1) : 0;

$pageTitle = 'ڕاپۆرتی گشتی دکتۆرەکان';
$breadcrumb = [
    ['title' => 'دکتۆرەکان', 'url' => APP_URL . '/doctors/index.php'],
    ['title' => 'ڕاپۆرتی گشتی']
];
require_once __DIR__ . '/../includes/header.php';
?>

<style>
html { overflow-y: scroll; scrollbar-gutter: stable; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
html::-webkit-scrollbar { width: 10px; }
html::-webkit-scrollbar-track { background: #f1f5f9; }
html::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; border: 2px solid #f1f5f9; }

.gr-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px; }
.gr-header h3 { margin:0; font-size:20px; color:#0f172a; }
.gr-header .sub { color:#607d8b; font-size:13px; }

.gr-filter { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:10px 12px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.gr-chip { padding:6px 12px; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; border-radius:20px; cursor:pointer; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.gr-chip:hover { background:#e2e8f0; color:#0f172a; }
.gr-chip.active { background:var(--win-accent, #0078d4); border-color:var(--win-accent, #0078d4); color:#fff; }
.gr-sep { width:1px; height:24px; background:#e2e8f0; }
.gr-filter input[type=date], .gr-filter select { padding:6px 8px; border:1px solid #cfd8dc; border-radius:6px; font-size:13px; }
.gr-filter label { font-size:12px; color:#607d8b; }
.gr-apply { padding:6px 14px; background:#22c55e; color:#fff; border:1px solid #16a34a; border-radius:6px; cursor:pointer; font-size:13px; }
.gr-apply:hover { background:#16a34a; }

.gr-stats { display:grid; grid-template-columns:repeat(5, 1fr); gap:10px; margin-bottom:14px; }
.gr-stat { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:14px; position:relative; overflow:hidden; }
.gr-stat::before { content:''; position:absolute; inset-block:0; inset-inline-end:0; width:4px; background:var(--accent, #0078d4); }
.gr-stat.total     { --accent:var(--win-accent, #0078d4); }
.gr-stat.paid      { --accent:#16a34a; }
.gr-stat.remaining { --accent:#dc2626; }
.gr-stat.discount  { --accent:#f59e0b; }
.gr-stat.rate      { --accent:#7c3aed; }
.gr-stat .label { font-size:12px; color:#607d8b; margin-bottom:4px; }
.gr-stat .value { font-size:18px; font-weight:800; color:#0f172a; line-height:1.15; }
.gr-stat.paid .value      { color:#16a34a; }
.gr-stat.remaining .value { color:#dc2626; }
.gr-stat.discount .value  { color:#f59e0b; }
.gr-stat .sub   { font-size:11px; color:#607d8b; margin-top:4px; }

.gr-table-wrap { background:#fff; border:1px solid #e3e8ee; border-radius:10px; overflow:hidden; }
.gr-table-wrap .head-title { padding:10px 14px; border-bottom:1px solid #eef2f5; font-weight:700; color:#0f172a; font-size:15px; display:flex; justify-content:space-between; align-items:center; }
.gr-table-scroll { overflow-x:auto; }
.gr-table { width:100%; border-collapse:collapse; font-size:13px; min-width:900px; }
.gr-table th { background:#f7f9fc; color:#37474f; font-weight:600; padding:10px 8px; text-align:center; border-bottom:1px solid #e3e8ee; position:sticky; top:0; }
.gr-table td { padding:10px 8px; border-bottom:1px solid #eef2f5; text-align:center; vertical-align:middle; }
.gr-table tr:hover td { background:#fafcff; }
.gr-table tfoot td { background:#f7f9fc; font-weight:700; }
.gr-table .dname { font-weight:700; color:#0f172a; text-align:start; display:flex; align-items:center; gap:6px; }
.gr-table .room-badge { background:rgba(56,189,248,.14); color:#0ea5e9; padding:1px 7px; border-radius:10px; font-size:10px; font-weight:700; }
.gr-table .v-total    { color:#0f172a; font-weight:700; }
.gr-table .v-discount { color:#f59e0b; font-weight:700; }
.gr-table .v-paid     { color:#15803d; font-weight:700; }
.gr-table .v-remain   { color:#c62828; font-weight:700; }
.gr-table .v-rate     { font-weight:600; }
.gr-table .rate-bar   { display:block; width:80px; height:6px; background:#e5e7eb; border-radius:3px; margin:4px auto 0; overflow:hidden; }
.gr-table .rate-bar div { height:100%; background:linear-gradient(90deg, #16a34a 0%, #22c55e 100%); }

.no-data { padding:40px; text-align:center; color:#9aa7b3; }

@media (max-width: 900px) { .gr-stats { grid-template-columns:repeat(2,1fr); } }
@media (max-width: 640px) {
    .gr-header h3 { font-size:16px; }
    .gr-chip { padding:5px 10px; font-size:12px; }
    .gr-stat .value { font-size:14px; }
    .gr-table th, .gr-table td { padding:7px 4px; font-size:11px; }
}
</style>

<?php
// Build PDF URL with current filters
$pdfParams = ['mode' => $mode, 'folder_type' => $folderType, 'folder_status' => $folderStat];
if ($mode === 'range') { $pdfParams['from'] = $rangeFrom; $pdfParams['to'] = $rangeTo; }
if ($activeDoctor)     { $pdfParams['doctor_id'] = $activeDoctor; }
$pdfUrl = APP_URL . '/doctors/general-report-pdf.php?' . http_build_query($pdfParams);
?>

<div class="gr-header">
    <div>
        <h3><i class="fas fa-file-invoice-dollar"></i> ڕاپۆرتی گشتی دکتۆرەکان</h3>
        <div class="sub"><?= htmlspecialchars($rangeLabel) ?> — <?= htmlspecialchars($rangeFrom) ?> بۆ <?= htmlspecialchars($rangeTo) ?></div>
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
    ['today',     'ئەمڕۆ',           'fa-calendar-day'],
    ['week',      'ئەم هەفتەیە',      'fa-calendar-week'],
    ['month',     'ئەم مانگە',        'fa-calendar-alt'],
    ['quarter',   'سێ مانگی ڕابردوو', 'fa-calendar-minus'],
    ['half_year', 'شەش مانگی ڕابردوو','fa-calendar-plus'],
    ['year',      'ئەمساڵ',           'fa-calendar'],
];
$carryNonDate = [
    'folder_type'   => $folderType,
    'folder_status' => $folderStat,
];
if ($activeDoctor && !$enforcedDoctor) $carryNonDate['doctor_id'] = $activeDoctor;
?>

<form method="GET" action="" class="gr-filter">
    <?php foreach ($chips as [$k, $lbl, $ic]): ?>
    <a href="?<?= http_build_query(array_merge($carryNonDate, ['mode' => $k])) ?>"
       class="gr-chip <?= $mode === $k ? 'active' : '' ?>">
        <i class="fas <?= $ic ?>"></i> <?= $lbl ?>
    </a>
    <?php endforeach; ?>

    <span class="gr-sep"></span>

    <input type="hidden" name="mode" value="range">
    <label>لە</label>
    <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
    <label>بۆ</label>
    <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">

    <span class="gr-sep"></span>

    <?php if ($hasFullAccess): ?>
    <label><i class="fas fa-user-md"></i> دکتۆر</label>
    <select name="doctor_id">
        <option value="">— هەموو دکتۆرەکان —</option>
        <?php foreach ($allDoctors as $d): ?>
        <option value="<?= $d['id'] ?>" <?= (string)$activeDoctor === (string)$d['id'] ? 'selected' : '' ?>>
            د. <?= htmlspecialchars($d['full_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <label><i class="fas fa-folder"></i> جۆری فایل</label>
    <select name="folder_type">
        <option value="all"        <?= $folderType === 'all'        ? 'selected' : '' ?>>هەموو</option>
        <option value="general"    <?= $folderType === 'general'    ? 'selected' : '' ?>>گشتی</option>
        <option value="cosmetic"   <?= $folderType === 'cosmetic'   ? 'selected' : '' ?>>جوانکاری</option>
        <option value="orthodontic"<?= $folderType === 'orthodontic'? 'selected' : '' ?>>پەلکەزێرینە</option>
        <option value="surgery"    <?= $folderType === 'surgery'    ? 'selected' : '' ?>>نەشتەرگەری</option>
        <option value="other"      <?= $folderType === 'other'      ? 'selected' : '' ?>>تر</option>
    </select>

    <label><i class="fas fa-info-circle"></i> دۆخ</label>
    <select name="folder_status">
        <option value="all"      <?= $folderStat === 'all'      ? 'selected' : '' ?>>هەموو</option>
        <option value="active"   <?= $folderStat === 'active'   ? 'selected' : '' ?>>کراوە</option>
        <option value="archived" <?= $folderStat === 'archived' ? 'selected' : '' ?>>داخراو</option>
    </select>

    <button type="submit" class="gr-apply"><i class="fas fa-search"></i> گەڕان</button>
</form>

<div class="gr-stats">
    <div class="gr-stat total">
        <div class="label">کۆی گشتی</div>
        <div class="value"><?= number_format($totals['total']) ?></div>
        <div class="sub"><?= $totals['folders'] ?> فایل</div>
    </div>
    <div class="gr-stat discount">
        <div class="label">کۆی داشکاندن</div>
        <div class="value"><?= number_format($totals['discount']) ?></div>
        <div class="sub"><?= $totals['total'] > 0 ? round($totals['discount']/$totals['total']*100, 1) : 0 ?>% لە کۆی گشتی</div>
    </div>
    <div class="gr-stat paid">
        <div class="label">پارەی وەرگیراو</div>
        <div class="value"><?= number_format($totals['paid']) ?></div>
        <div class="sub">ڕێژەی کۆکردنەوە: <?= $collectionRate ?>%</div>
    </div>
    <div class="gr-stat remaining">
        <div class="label">ماوە (قەرز)</div>
        <div class="value"><?= number_format($totals['remaining']) ?></div>
        <div class="sub"><?= $totals['total'] > 0 ? round($totals['remaining']/$totals['total']*100, 1) : 0 ?>% لە کۆی گشتی</div>
    </div>
    <div class="gr-stat rate">
        <div class="label">کۆی کۆمیسیۆن</div>
        <div class="value"><?= number_format($totals['commission']) ?></div>
        <div class="sub">لەسەر پارەی وەرگیراو</div>
    </div>
</div>

<div class="gr-table-wrap">
    <div class="head-title">
        <span><i class="fas fa-users"></i> خشتەی دکتۆرەکان</span>
        <span style="font-size:12px; color:#607d8b; font-weight:400;"><?= count($rows) ?> دکتۆر</span>
    </div>
    <div class="gr-table-scroll">
        <table class="gr-table">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th style="text-align:start;">ناوی دکتۆر</th>
                    <th>فایلەکان</th>
                    <th>کۆی گشتی</th>
                    <th>داشکاندن</th>
                    <th>پارەی وەرگیراو</th>
                    <th>ماوە</th>
                    <th>ڕێژەی کۆکردنەوە</th>
                    <th>کۆمیسیۆن</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="no-data">هیچ تۆمارێک نەدۆزرایەوە</td></tr>
                <?php else: foreach ($rows as $i => $r):
                    $rate = (float)$r['total_amount'] > 0 ? round((float)$r['paid_amount'] / (float)$r['total_amount'] * 100, 1) : 0;
                    $commission = max(0, (float)$r['paid_amount'] - (float)$r['prior_paid']) * ((float)$r['commission_rate'] / 100);
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="dname">
                        د. <?= htmlspecialchars($r['doctor_name']) ?>
                        <?php if (!empty($r['room_number'])): ?>
                        <span class="room-badge"><i class="fas fa-door-open"></i> ژوور <?= htmlspecialchars($r['room_number']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$r['folder_count'] ?></td>
                    <td class="v-total"><?= number_format($r['total_amount']) ?></td>
                    <td class="v-discount"><?= number_format($r['discount_amount']) ?></td>
                    <td class="v-paid"><?= number_format($r['paid_amount']) ?></td>
                    <td class="v-remain"><?= number_format($r['remaining']) ?></td>
                    <td class="v-rate">
                        <?= $rate ?>%
                        <div class="rate-bar"><div style="width:<?= min(100, $rate) ?>%;"></div></div>
                    </td>
                    <td><?= number_format($commission) ?><?= $r['commission_rate'] > 0 ? ' <small style="color:#607d8b;">('. $r['commission_rate'] .'%)</small>' : '' ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="2" style="text-align:start; padding-inline-start:12px;">کۆی گشتی</td>
                    <td><?= $totals['folders'] ?></td>
                    <td class="v-total"><?= number_format($totals['total']) ?></td>
                    <td class="v-discount"><?= number_format($totals['discount']) ?></td>
                    <td class="v-paid"><?= number_format($totals['paid']) ?></td>
                    <td class="v-remain"><?= number_format($totals['remaining']) ?></td>
                    <td><?= $collectionRate ?>%</td>
                    <td><?= number_format($totals['commission']) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
