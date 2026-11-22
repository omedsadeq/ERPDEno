<?php
/**
 * ڕاپۆرتی ئێکسڕەی — for the accountant and the admin.
 *
 * How many patients had an X-ray in a period (today by default), who they
 * are, and each request's money: what was paid, what is still owed, what is
 * waiting for the accountant, and what was free. Filters: the period (by the
 * request's date or the money's date), the doctor (the clinic's or an outside
 * one), the type, the money state, the X-ray room's state and a search.
 * Prints, and exports to CSV (Excel).
 *
 * The money reading is the income reports' (XrayManager::getCashIncome /
 * remainingAmountSql): a request in the debt channel (patient_xray_debts)
 * has received = final − what is still owed; a cash one is received in full
 * once paid; a free one owes nothing.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$auth = new Auth();
$auth->requireLogin();
if (!Permission::getInstance()->can('page.xray_report')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$db = Database::getInstance();

// ── the filters ──
$validDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
$today = date('Y-m-d');
$from = $validDate($_GET['from'] ?? '') ?? $today;
$to = $validDate($_GET['to'] ?? '') ?? $from;
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$basis = ($_GET['basis'] ?? '') === 'money' ? 'money' : 'request';
$doctor = (string)($_GET['doctor'] ?? '');
$type = isset(XrayManager::TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$money = (string)($_GET['money'] ?? '');
$room = (string)($_GET['room'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));
$withCancelled = !empty($_GET['cancelled']);

const XR_MONEY_STATES = [
    'paid'     => ['دراوە (نەقد)', '#15803d', '#dcfce7'],
    'partial'  => ['بەشێکی دراوە', '#b45309', '#fef3c7'],
    'debt'     => ['قەرز (نەدراوە)', '#be123c', '#ffe4e6'],
    'pending'  => ['چاوەڕوانی ژمێریار', '#1d4ed8', '#dbeafe'],
    'free'     => ['بێ بەرامبەر', '#6d28d9', '#ede9fe'],
    'refunded' => ['گەڕێنراوەتەوە', '#475569', '#f1f5f9'],
];
const XR_ROOM_STATES = [
    'pending_accountant'     => 'لای ژمێریار',
    'returned_to_accountant' => 'گەڕێنراوە بۆ ژمێریار',
    'pending_xray'           => 'لە ڕیزی ئێکسڕەی',
    'in_xray'                => 'لە ژووری ئێکسڕەی',
    'completed'              => 'تەواوبوو',
    'cancelled'              => 'هەڵوەشاوە',
];

// ── the money of each request, in SQL ──
$hasDebts = $db->tableExists('patient_xray_debts');
$debtJoin = $hasDebts ? XrayManager::remainingAmountJoinSql('xr', 'pxd') : '';
$final = 'COALESCE(NULLIF(xr.final_amount, 0), xr.amount, 0)';
$received = $hasDebts
    ? "CASE WHEN pxd.request_id IS NOT NULL THEN GREATEST(0, {$final} - pxd.rem) WHEN xr.payment_status = 'paid' THEN {$final} ELSE 0 END"
    : "CASE WHEN xr.payment_status = 'paid' THEN {$final} ELSE 0 END";
$remaining = $hasDebts
    ? "CASE WHEN pxd.request_id IS NOT NULL THEN pxd.rem WHEN xr.payment_status IN ('debt', 'debt_approved') THEN {$final} ELSE 0 END"
    : "CASE WHEN xr.payment_status IN ('debt', 'debt_approved') THEN {$final} ELSE 0 END";
$state = "CASE
    WHEN xr.payment_status = 'refunded' THEN 'refunded'
    WHEN xr.payment_status = 'not_required' OR {$final} <= 0 THEN 'free'
    WHEN xr.request_status IN ('pending_accountant', 'returned_to_accountant')
         OR xr.payment_status IN ('pending', 'pending_payment', 'awaiting_payment', 'debt_pending') THEN 'pending'
    WHEN ({$remaining}) <= 0.009 THEN 'paid'
    WHEN ({$received}) > 0.009 THEN 'partial'
    ELSE 'debt' END";
$dateCol = $basis === 'money' ? 'COALESCE(xr.paid_at, xr.accountant_approved_at, xr.created_at)' : 'xr.created_at';

$where = ["DATE({$dateCol}) BETWEEN :f AND :t"];
$params = ['f' => $from, 't' => $to];
if (!$withCancelled) {
    $where[] = "COALESCE(xr.request_status, '') <> 'cancelled' AND COALESCE(xr.status, '') <> 'cancelled'";
}
if (preg_match('/^u:(\d+)$/', $doctor, $m)) {
    $where[] = 'xr.requested_by = :doc AND xr.external_doctor_id IS NULL';
    $params['doc'] = (int)$m[1];
} elseif (preg_match('/^e:(\d+)$/', $doctor, $m)) {
    $where[] = 'xr.external_doctor_id = :ext';
    $params['ext'] = (int)$m[1];
} elseif ($doctor === 'external') {
    $where[] = 'xr.external_doctor_id IS NOT NULL';
}
if ($type !== '') {
    $where[] = 'xr.xray_type = :type';
    $params['type'] = $type;
}
if (isset(XR_ROOM_STATES[$room])) {
    $where[] = 'xr.request_status = :room';
    $params['room'] = $room;
}
if ($search !== '') {
    $where[] = '(p.full_name LIKE :q1 OR p.phone LIKE :q2 OR p.patient_code LIKE :q3 OR xr.request_number LIKE :q4)';
    foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
        $params[$k] = '%' . $search . '%';
    }
}
$having = isset(XR_MONEY_STATES[$money]) ? ' HAVING money_state = :ms' : '';
if ($having) {
    $params['ms'] = $money;
}

$rows = $db->fetchAll(
    "SELECT xr.id, xr.request_number, xr.created_at, xr.paid_at, xr.accountant_approved_at, xr.xray_type, xr.request_status,
            xr.payment_status, xr.patient_id, xr.case_id, xr.notes,
            COALESCE(NULLIF(xr.original_amount, 0), xr.amount, 0) AS price, COALESCE(xr.discount_amount, 0) AS discount,
            {$final} AS final, {$received} AS received, {$remaining} AS remaining, {$state} AS money_state,
            p.full_name AS patient_name, p.phone AS patient_phone, p.patient_code,
            COALESCE(xed.name, u.full_name) AS doctor_name, xr.external_doctor_id
     FROM xray_requests xr {$debtJoin}
     LEFT JOIN patients p ON p.id = xr.patient_id
     LEFT JOIN users u ON u.id = xr.requested_by
     LEFT JOIN xray_external_doctors xed ON xed.id = xr.external_doctor_id
     WHERE " . implode(' AND ', $where) . $having . "
     ORDER BY {$dateCol} DESC, xr.id DESC
     LIMIT 3001",
    $params
);
$capped = count($rows) > 3000;
if ($capped) {
    array_pop($rows);
}

// ── the totals ──
$tot = ['requests' => count($rows), 'final' => 0.0, 'received' => 0.0, 'remaining' => 0.0, 'pending' => 0.0, 'discount' => 0.0, 'completed' => 0];
$patients = [];
$byState = array_fill_keys(array_keys(XR_MONEY_STATES), ['n' => 0, 'amount' => 0.0]);
$byType = array_fill_keys(array_keys(XrayManager::TYPES), ['n' => 0, 'received' => 0.0]);
foreach ($rows as $r) {
    $patients[(int)$r['patient_id']] = true;
    $tot['final'] += (float)$r['final'];
    $tot['received'] += (float)$r['received'];
    $tot['remaining'] += (float)$r['remaining'];
    $tot['discount'] += (float)$r['discount'];
    if ($r['money_state'] === 'pending') {
        $tot['pending'] += (float)$r['final'];
    }
    if ($r['request_status'] === 'completed') {
        $tot['completed']++;
    }
    $s = $r['money_state'];
    $byState[$s]['n']++;
    $byState[$s]['amount'] += $s === 'paid' || $s === 'partial' ? (float)$r['received'] : ($s === 'debt' ? (float)$r['remaining'] : (float)$r['final']);
    if (isset($byType[$r['xray_type']])) {
        $byType[$r['xray_type']]['n']++;
        $byType[$r['xray_type']]['received'] += (float)$r['received'];
    }
}
$tot['patients'] = count($patients);

// ── CSV (opens in Excel) ──
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="xray-report-' . $from . ($from !== $to ? '_' . $to : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['#', 'بەروار', 'ژمارە', 'نەخۆش', 'کۆد', 'مۆبایل', 'دکتۆر', 'جۆر', 'نرخ', 'داشکاندن', 'کۆی کۆتایی', 'وەرگیراو', 'ماوە', 'دۆخی پارە', 'دۆخی ئێکسڕەی'], ',', '"', '');
    foreach ($rows as $i => $r) {
        fputcsv($out, [$i + 1, substr($r['created_at'], 0, 16), $r['request_number'], $r['patient_name'], $r['patient_code'], $r['patient_phone'],
            $r['doctor_name'], XrayManager::TYPES[$r['xray_type']] ?? $r['xray_type'], (float)$r['price'], (float)$r['discount'], (float)$r['final'],
            (float)$r['received'], (float)$r['remaining'], XR_MONEY_STATES[$r['money_state']][0] ?? $r['money_state'],
            XR_ROOM_STATES[$r['request_status']] ?? $r['request_status']], ',', '"', '');
    }
    fputcsv($out, ['', 'کۆ', '', $tot['patients'] . ' نەخۆش', '', '', '', $tot['requests'] . ' داواکاری', '', $tot['discount'], $tot['final'], $tot['received'], $tot['remaining'], '', ''], ',', '"', '');
    fclose($out);
    exit;
}

// ── the pickers ──
$doctors = $db->fetchAll(
    "SELECT DISTINCT u.id, u.full_name FROM xray_requests xr JOIN users u ON u.id = xr.requested_by
     WHERE xr.external_doctor_id IS NULL ORDER BY u.full_name"
);
$externals = $db->tableExists('xray_external_doctors')
    ? $db->fetchAll("SELECT id, name FROM xray_external_doctors ORDER BY name")
    : [];

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(
    ['from' => $from, 'to' => $to, 'basis' => $basis === 'money' ? 'money' : null, 'doctor' => $doctor, 'type' => $type,
     'money' => $money, 'room' => $room, 'q' => $search, 'cancelled' => $withCancelled ? 1 : null],
    $over
), fn($v) => $v !== null && $v !== ''));
$presets = [
    ['ئەمڕۆ', $today, $today],
    ['دوێنێ', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    // the week starts on Saturday
    ['ئەم هەفتەیە', date('Y-m-d', strtotime('-' . (((int)date('w') + 1) % 7) . ' days')), $today],
    ['ئەم مانگە', date('Y-m-01'), $today],
    ['مانگی پێشوو', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];
$m = fn($v) => number_format((float)$v);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$filtersOn = $doctor !== '' || $type !== '' || $money !== '' || $room !== '' || $search !== '' || $withCancelled || $basis === 'money';

$pageTitle = 'ڕاپۆرتی ئێکسڕەی';
$breadcrumb = [['title' => 'ئێکسڕەی'], ['title' => 'ڕاپۆرتی ئێکسڕەی']];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.xr{max-width:1500px;margin:0 auto 28px;direction:rtl;color:#1e293b}
.xr-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:linear-gradient(120deg,var(--win-accent,#0078d4),#0e9f9a);
    color:#fff;border-radius:16px;padding:14px 18px;margin-bottom:12px}
.xr-head .ic{width:46px;height:46px;border-radius:13px;border:2px solid rgba(255,255,255,.75);display:flex;align-items:center;justify-content:center;font-size:21px}
.xr-head h1{margin:0;font-size:20px;font-weight:800}
.xr-head small{display:block;color:#dff4f7;font-size:13px;margin-top:2px}
.xr-head .acts{margin-inline-start:auto;display:flex;gap:8px;flex-wrap:wrap}
.xr-head .acts a,.xr-head .acts button{display:inline-flex;align-items:center;gap:6px;background:#fff;color:#0b5f8a;border:0;border-radius:999px;
    padding:7px 14px;font:inherit;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}
.xr-head .acts a:hover,.xr-head .acts button:hover{background:#e0f2fe}

.xr-filter{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px 14px;margin-bottom:12px;display:grid;gap:10px}
.xr-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.xr-seg{display:inline-flex;background:#eef2f7;border-radius:11px;padding:3px;gap:2px;flex-wrap:wrap}
.xr-seg a{padding:6px 12px;border-radius:8px;color:#475569;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap}
.xr-seg a:hover{background:#fff}
.xr-seg a.on{background:var(--win-accent,#0078d4);color:#fff}
.xr-f{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 10px;border:1.5px solid #e2e8f0;border-radius:10px;background:#fff;font-size:13px;color:#475569}
.xr-f:focus-within{border-color:var(--win-accent,#0078d4)}
.xr-f i{color:#94a3b8}
.xr-f input,.xr-f select{border:0;outline:0;background:transparent;font:inherit;font-size:13px;font-weight:600;color:#1e293b;min-width:0}
.xr-f.on{background:#eef6ff;border-color:#93c5fd}
.xr-f.grow{flex:1 1 220px}
.xr-f.grow input{width:100%}
.xr-chk{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#475569;cursor:pointer}
.xr-go{height:38px;border:0;border-radius:10px;background:var(--win-accent,#0078d4);color:#fff;font:inherit;font-weight:700;padding:0 18px;cursor:pointer}
.xr-clear{color:#be123c;font-size:13px;font-weight:700;text-decoration:none}

.xr-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:10px;margin-bottom:12px}
.xr-card{--c:#3b82f6;--t:#dbeafe;background:#fff;border-radius:14px;border-top:4px solid var(--c);box-shadow:0 1px 3px rgba(15,23,42,.08);
    padding:12px 10px;display:flex;flex-direction:column;align-items:center;gap:4px;text-align:center;text-decoration:none;color:inherit}
a.xr-card:hover{box-shadow:0 6px 18px rgba(15,23,42,.12)}
.xr-card.on{box-shadow:0 0 0 2px var(--c) inset}
.xr-card .i{width:38px;height:38px;border-radius:11px;background:var(--t);color:var(--c);display:flex;align-items:center;justify-content:center;font-size:17px}
.xr-card b{font-size:20px;font-weight:800;direction:ltr;font-variant-numeric:tabular-nums}
.xr-card span{font-size:12.5px;font-weight:700;color:#334155}
.xr-card small{font-size:11.5px;color:#64748b;direction:ltr}

.xr-types{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.xr-type{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:5px 12px;font-size:13px;text-decoration:none;color:#334155}
.xr-type b{color:#0f172a}
.xr-type small{color:#15803d;font-weight:700}
.xr-type.on{border-color:var(--win-accent,#0078d4);background:#eef6ff}

.xr-box{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
.xr-scroll{overflow-x:auto}
.xr-tbl{width:100%;border-collapse:collapse;font-size:13px}
.xr-tbl th{background:#f1f5f9;color:#334155;font-size:12px;padding:8px;text-align:right;white-space:nowrap;position:sticky;top:0}
.xr-tbl td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.xr-tbl tr:hover td{background:#f8fbff}
.xr-tbl .n{text-align:left;direction:ltr;white-space:nowrap;font-variant-numeric:tabular-nums}
.xr-tbl .i{color:#94a3b8;width:34px;text-align:center}
.xr-tbl a{color:var(--win-accent,#0067c0);font-weight:700;text-decoration:none;white-space:nowrap}
.xr-tbl td.nw{white-space:nowrap}
.xr-tbl small{color:#64748b;display:block;direction:ltr;text-align:right}
.xr-tbl tfoot td{background:#f1f5f9;font-weight:800;border-top:2px solid #cbd5e1}
.xr-st{display:inline-block;border-radius:999px;padding:2px 10px;font-size:11.5px;font-weight:800;white-space:nowrap}
.xr-room{font-size:12px;color:#475569;white-space:nowrap}
.xr-pos{color:#15803d;font-weight:700}.xr-neg{color:#be123c;font-weight:700}
.xr-empty{padding:40px;text-align:center;color:#64748b}
.xr-note{padding:8px 14px;font-size:12px;color:#92400e;background:#fffbeb}

@media(max-width:640px){.xr-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.xr-head h1{font-size:17px}.xr-f{flex:1 1 100%}}
@media print{
    .xr-filter,.xr-head .acts,.xr-types{display:none!important}
    .xr-head{background:none;color:#000;border:1px solid #999;padding:6px 10px}
    .xr-head small{color:#333}
    .xr-card{box-shadow:none;border:1px solid #ccc;border-top-width:3px;padding:6px}
    .xr-cards{grid-template-columns:repeat(6,1fr)}
    .xr-tbl{font-size:10.5px}.xr-tbl th,.xr-tbl td{padding:3px 4px}
}
</style>

<div class="xr">
    <div class="xr-head">
        <span class="ic"><i class="fas fa-x-ray"></i></span>
        <div>
            <h1>ڕاپۆرتی ئێکسڕەی</h1>
            <small><i class="far fa-calendar-alt"></i>
                <?= $from === $to ? ($from === $today ? 'ئەمڕۆ · ' : '') . $e($from) : $e($from) . ' بۆ ' . $e($to) ?>
                · بەپێی <?= $basis === 'money' ? 'بەرواری پارەدان' : 'بەرواری داواکاری' ?></small>
        </div>
        <div class="acts">
            <button type="button" onclick="window.print()"><i class="fas fa-print"></i> چاپکردن</button>
            <a href="<?= $e($qs(['export' => 'csv'])) ?>"><i class="fas fa-file-excel"></i> Excel</a>
        </div>
    </div>

    <form method="get" class="xr-filter">
        <div class="xr-row">
            <nav class="xr-seg">
                <?php foreach ($presets as [$label, $pf, $pt]): ?>
                <a href="<?= $e($qs(['from' => $pf, 'to' => $pt])) ?>" class="<?= $pf === $from && $pt === $to ? 'on' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
            <label class="xr-f"><i class="far fa-calendar"></i> لە <input type="date" name="from" value="<?= $e($from) ?>"></label>
            <label class="xr-f"><i class="far fa-calendar"></i> بۆ <input type="date" name="to" value="<?= $e($to) ?>"></label>
            <label class="xr-f<?= $basis === 'money' ? ' on' : '' ?>" title="کام بەروار">
                <i class="fas fa-clock"></i>
                <select name="basis">
                    <option value="">بەرواری داواکاری</option>
                    <option value="money" <?= $basis === 'money' ? 'selected' : '' ?>>بەرواری پارەدان</option>
                </select>
            </label>
        </div>
        <div class="xr-row">
            <label class="xr-f<?= $doctor !== '' ? ' on' : '' ?>"><i class="fas fa-user-md"></i>
                <select name="doctor">
                    <option value="">هەموو دکتۆرەکان</option>
                    <optgroup label="دکتۆرەکانی سەنتەر">
                        <?php foreach ($doctors as $d): ?>
                        <option value="u:<?= (int)$d['id'] ?>" <?= $doctor === 'u:' . $d['id'] ? 'selected' : '' ?>><?= $e($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php if ($externals): ?>
                    <optgroup label="دکتۆرانی دەرەوە">
                        <option value="external" <?= $doctor === 'external' ? 'selected' : '' ?>>هەموو دکتۆرانی دەرەوە</option>
                        <?php foreach ($externals as $d): ?>
                        <option value="e:<?= (int)$d['id'] ?>" <?= $doctor === 'e:' . $d['id'] ? 'selected' : '' ?>><?= $e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </label>
            <label class="xr-f<?= $type !== '' ? ' on' : '' ?>"><i class="fas fa-x-ray"></i>
                <select name="type">
                    <option value="">هەموو جۆرەکان</option>
                    <?php foreach (XrayManager::TYPES as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="xr-f<?= $money !== '' ? ' on' : '' ?>"><i class="fas fa-money-bill-wave"></i>
                <select name="money">
                    <option value="">هەموو دۆخەکانی پارە</option>
                    <?php foreach (XR_MONEY_STATES as $k => [$label]): ?>
                    <option value="<?= $k ?>" <?= $money === $k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="xr-f<?= $room !== '' ? ' on' : '' ?>"><i class="fas fa-door-open"></i>
                <select name="room">
                    <option value="">هەموو دۆخەکانی ئێکسڕەی</option>
                    <?php foreach (XR_ROOM_STATES as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $room === $k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="xr-f grow<?= $search !== '' ? ' on' : '' ?>"><i class="fas fa-search"></i>
                <input type="search" name="q" value="<?= $e($search) ?>" placeholder="ناوی نەخۆش، مۆبایل، کۆد یان ژمارەی داواکاری…">
            </label>
            <label class="xr-chk"><input type="checkbox" name="cancelled" value="1" <?= $withCancelled ? 'checked' : '' ?>> هەڵوەشاوەکانیش</label>
            <button type="submit" class="xr-go"><i class="fas fa-filter"></i> پیشاندان</button>
            <?php if ($filtersOn): ?>
            <a class="xr-clear" href="<?= $e('?' . http_build_query(['from' => $from, 'to' => $to])) ?>"><i class="fas fa-times"></i> لابردنی پاڵاوتن</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="xr-cards">
        <div class="xr-card" style="--c:#6366f1;--t:#e0e7ff"><span class="i"><i class="fas fa-users"></i></span><b><?= $m($tot['patients']) ?></b><span>نەخۆش</span><small><?= $m($tot['requests']) ?> داواکاری</small></div>
        <div class="xr-card" style="--c:#0e9f9a;--t:#ccfbf1"><span class="i"><i class="fas fa-check-double"></i></span><b><?= $m($tot['completed']) ?></b><span>ئێکسڕەی کراو</span><small>تەواوبوو</small></div>
        <div class="xr-card" style="--c:#3b82f6;--t:#dbeafe"><span class="i"><i class="fas fa-coins"></i></span><b><?= $m($tot['final']) ?></b><span>کۆی گشتی</span><small>داشکاندن <?= $m($tot['discount']) ?></small></div>
        <?php foreach (['paid' => 'fa-money-bill-wave', 'partial' => 'fa-coins', 'debt' => 'fa-hand-holding-usd', 'pending' => 'fa-hourglass-half', 'free' => 'fa-gift'] as $k => $icon):
            [$label, $c, $tint] = XR_MONEY_STATES[$k]; ?>
        <a class="xr-card<?= $money === $k ? ' on' : '' ?>" style="--c:<?= $c ?>;--t:<?= $tint ?>" href="<?= $e($qs(['money' => $money === $k ? null : $k])) ?>" title="تەنها ئەمانە پیشان بدە">
            <span class="i"><i class="fas <?= $icon ?>"></i></span><b><?= $m($byState[$k]['n']) ?></b><span><?= $label ?></span>
            <small><?= $k === 'free' ? 'بێ پارە' : $m($byState[$k]['amount']) ?></small>
        </a>
        <?php endforeach; ?>
        <div class="xr-card" style="--c:#15803d;--t:#dcfce7"><span class="i"><i class="fas fa-cash-register"></i></span><b><?= $m($tot['received']) ?></b><span>کۆی وەرگیراو</span><small>پارەی هاتوو</small></div>
        <div class="xr-card" style="--c:#be123c;--t:#ffe4e6"><span class="i"><i class="fas fa-file-invoice-dollar"></i></span><b><?= $m($tot['remaining']) ?></b><span>کۆی ماوە</span><small>قەرزی نەدراو</small></div>
    </div>

    <div class="xr-types">
        <?php foreach (XrayManager::TYPES as $k => $label): if (!$byType[$k]['n'] && $type !== $k) continue; ?>
        <a class="xr-type<?= $type === $k ? ' on' : '' ?>" href="<?= $e($qs(['type' => $type === $k ? null : $k])) ?>">
            <i class="fas fa-x-ray" style="color:#0e9f9a"></i> <?= $e($label) ?> <b><?= $m($byType[$k]['n']) ?></b>
            <small><?= $m($byType[$k]['received']) ?></small>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="xr-box">
        <?php if ($capped): ?><div class="xr-note"><i class="fas fa-info-circle"></i> تەنها ٣٬٠٠٠ داواکاری پیشان دەدرێت — ماوەکە کورتتر بکەرەوە یان پاڵاوتن بەکاربهێنە.</div><?php endif; ?>
        <?php if (!$rows): ?>
        <div class="xr-empty"><i class="fas fa-x-ray" style="font-size:30px;opacity:.4"></i><br>هیچ ئێکسڕەیەک لەم ماوەیەدا نییە</div>
        <?php else: ?>
        <div class="xr-scroll">
            <table class="xr-tbl">
                <thead><tr>
                    <th class="i">#</th><th>بەروار</th><th>نەخۆش</th><th>دکتۆر</th><th>جۆر</th>
                    <th class="n">نرخ</th><th class="n">داشکاندن</th><th class="n">کۆی کۆتایی</th><th class="n">وەرگیراو</th><th class="n">ماوە</th>
                    <th>پارە</th><th>ئێکسڕەی</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $r): [$sl, $sc, $st] = XR_MONEY_STATES[$r['money_state']] ?? [$r['money_state'], '#475569', '#f1f5f9']; ?>
                    <tr>
                        <td class="i"><?= $i + 1 ?></td>
                        <td class="n"><?= $e(substr($basis === 'money' ? ($r['paid_at'] ?: $r['accountant_approved_at'] ?: $r['created_at']) : $r['created_at'], 0, 16)) ?>
                            <small><?= $e($r['request_number']) ?></small></td>
                        <td><a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$r['patient_id'] ?>"><?= $e($r['patient_name']) ?></a>
                            <small><?= $e($r['patient_code']) ?><?= $r['patient_phone'] ? ' · ' . $e($r['patient_phone']) : '' ?></small></td>
                        <td class="nw"><?= $e($r['doctor_name']) ?><?= $r['external_doctor_id'] ? ' <small style="display:inline;color:#b45309">(دەرەوە)</small>' : '' ?></td>
                        <td class="nw"><?= $e(XrayManager::TYPES[$r['xray_type']] ?? $r['xray_type']) ?></td>
                        <td class="n"><?= $m($r['price']) ?></td>
                        <td class="n"><?= (float)$r['discount'] > 0 ? $m($r['discount']) : '—' ?></td>
                        <td class="n"><?= $m($r['final']) ?></td>
                        <td class="n<?= (float)$r['received'] > 0 ? ' xr-pos' : '' ?>"><?= $m($r['received']) ?></td>
                        <td class="n<?= (float)$r['remaining'] > 0 ? ' xr-neg' : '' ?>"><?= $m($r['remaining']) ?></td>
                        <td><span class="xr-st" style="color:<?= $sc ?>;background:<?= $st ?>"><?= $sl ?></span></td>
                        <td class="xr-room"><?= $e(XR_ROOM_STATES[$r['request_status']] ?? $r['request_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td></td><td>کۆ</td><td><?= $m($tot['patients']) ?> نەخۆش</td><td></td><td><?= $m($tot['requests']) ?> داواکاری</td>
                    <td></td><td class="n"><?= $m($tot['discount']) ?></td><td class="n"><?= $m($tot['final']) ?></td>
                    <td class="n xr-pos"><?= $m($tot['received']) ?></td><td class="n xr-neg"><?= $m($tot['remaining']) ?></td><td colspan="2"></td>
                </tr></tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
