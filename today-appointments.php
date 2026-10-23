<?php
/**
 * Today's Appointments Page
 * لیستی مەوعیدەکانی ئەمڕۆ لەگەڵ زانیاری قەرز و پارەی بەڵێندراو
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Appointment.php';
require_once __DIR__ . '/classes/XrayManager.php';

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
$doctorId = $hasFullAccess ? null : $currentUser['id'];

$today = date('Y-m-d');

// Resolve date range from filter params
$mode    = $_GET['mode']    ?? 'today';
$dateIn  = $_GET['date']    ?? '';
$fromIn  = $_GET['from']    ?? '';
$toIn    = $_GET['to']      ?? '';

$validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

$rangeFrom = $today;
$rangeTo   = $today;
$rangeLabel= 'ئەمڕۆ';

switch ($mode) {
    case 'yesterday':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('-1 day'));
        $rangeLabel = 'دوێنێ';
        break;
    case 'tomorrow':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('+1 day'));
        $rangeLabel = 'بەیانی';
        break;
    case 'day_after':
        $rangeFrom = $rangeTo = date('Y-m-d', strtotime('+2 day'));
        $rangeLabel = 'دووبەیانی';
        break;
    case 'week':
        $dow = (int)date('w'); // 0=Sun..6=Sat
        $daysToSat = ($dow + 1) % 7;
        $rangeFrom = date('Y-m-d', strtotime("-{$daysToSat} day"));
        $rangeTo   = date('Y-m-d', strtotime("{$rangeFrom} +6 day"));
        $rangeLabel= 'ئەم هەفتەیە';
        break;
    case 'month':
        $rangeFrom = date('Y-m-01');
        $rangeTo   = date('Y-m-t');
        $rangeLabel= 'ئەم مانگە';
        break;
    case 'range':
        if ($validDate($fromIn) && $validDate($toIn)) {
            $rangeFrom = $fromIn; $rangeTo = $toIn;
            if ($rangeFrom > $rangeTo) [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
            $rangeLabel = "لە {$rangeFrom} — {$rangeTo}";
        }
        break;
    case 'date':
        if ($validDate($dateIn)) {
            $rangeFrom = $rangeTo = $dateIn;
            $rangeLabel = $dateIn === $today ? 'ئەمڕۆ' : $dateIn;
        }
        break;
    case 'today':
    default:
        $rangeFrom = $rangeTo = $today;
        $rangeLabel = 'ئەمڕۆ';
}

// Pull appointments in range (excluding cancelled/no-show)
$sql = "SELECT a.id, a.appointment_date, a.appointment_time, a.duration, a.status, a.promised_amount,
               p.id AS patient_id, p.full_name AS patient_name, p.phone AS patient_phone,
               u.full_name AS doctor_name,
               (
                   COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount)
                             FROM patient_folders pf
                             WHERE pf.patient_id = p.id), 0)
                 + COALESCE((SELECT SUM(xr.final_amount)
                             FROM xray_requests xr
                             WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")), 0)
               ) AS total_debt
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users    u ON a.doctor_id  = u.id
        WHERE a.appointment_date BETWEEN :from AND :to
          AND a.status NOT IN ('cancelled','no_show')";
$params = ['from' => $rangeFrom, 'to' => $rangeTo];
if ($doctorId) {
    $sql .= " AND a.doctor_id = :doc";
    $params['doc'] = $doctorId;
}
$sql .= " ORDER BY a.appointment_date, a.appointment_time";
$rows = $db->fetchAll($sql, $params);

// Totals — debt counted once per unique patient (not per row)
$totalDebtSum   = 0;
$totalPromised  = 0;
$debtorCount    = 0;
$seenDebtors    = [];
foreach ($rows as $r) {
    $d = max(0, (float)$r['total_debt']);
    $pid = $r['patient_id'];
    if ($d > 0 && $pid && !isset($seenDebtors[$pid])) {
        $seenDebtors[$pid] = true;
        $debtorCount++;
        $totalDebtSum += $d;
    }
    $totalPromised += (float)($r['promised_amount'] ?? 0);
}

$pageTitle  = 'مەوعیدەکانی ' . $rangeLabel;
$breadcrumb = [
    ['title' => 'مەوعیدەکان', 'url' => APP_URL . '/appointments.php'],
    ['title' => 'مەوعیدەکانی ' . $rangeLabel]
];
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Always-visible page scrollbar */
html { overflow-y: scroll; scrollbar-gutter: stable; }
html, body { -webkit-overflow-scrolling: touch; }
html::-webkit-scrollbar, body::-webkit-scrollbar { width: 10px; }
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track { background: #f1f5f9; }
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; border: 2px solid #f1f5f9; }
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
/* Firefox */
html { scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }

.today-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
.today-stat { background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; padding: 12px; }
.today-stat .label { font-size: 12px; color: #607d8b; margin-bottom: 4px; }
.today-stat .value { font-size: 20px; font-weight: 700; color: #0f172a; }
.today-stat.debt .value   { color: #c62828; }
.today-stat.promise .value{ color: #2e7d32; }
.today-table-wrap { width: 100%; overflow-x: auto; border-radius: 8px; -webkit-overflow-scrolling: touch; }
.today-table-wrap::-webkit-scrollbar { height: 8px; width: 8px; }
.today-table-wrap::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
.today-table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.today-table-wrap::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.today-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; overflow: hidden; min-width: 900px; }
.today-table th, .today-table td { padding: 10px 12px; border-bottom: 1px solid #eef2f5; vertical-align: middle; text-align: center; font-size: 14px; }
.today-table th { background: #f7f9fc; color: #37474f; font-weight: 600; }
.today-table tr:last-child td { border-bottom: none; }
.today-table tr:hover td { background: #fafcff; }
.today-table .debt-cell   { color: #c62828; font-weight: 600; }
.today-table .no-debt     { color: #9aa7b3; font-size: 12px; }
.phone-link { display: inline-flex; align-items: center; gap: 6px; color: #0078d4; text-decoration: none; direction: ltr; font-size: 13px; }
.phone-link:hover { color: #005a9e; text-decoration: underline; }
.phone-link i { font-size: 11px; }
.call-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; background: #22c55e; color: #fff; border: 1px solid #16a34a; border-radius: 6px; text-decoration: none; font-size: 12px; margin-right: 6px; }
.call-btn:hover { background: #16a34a; color: #fff; }
.promise-input { width: 120px; padding: 6px 8px; border: 1px solid #cfd8dc; border-radius: 6px; text-align: left; direction: ltr; font-size: 13px; }
.promise-input.saved { border-color: #22c55e; background: #f0fdf4; }
.promise-input.error { border-color: #f44336; background: #fef2f2; }
.today-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px; }
.today-header h3 { margin: 0; color: #0f172a; font-size: 20px; }
.today-header .today-date { color: #607d8b; font-size: 13px; }
.empty-box { padding: 40px; text-align: center; color: #9aa7b3; background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; }
.filter-bar { background: #fff; border: 1px solid #e3e8ee; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.filter-bar .filter-group { display: flex; gap: 4px; flex-wrap: wrap; }
.filter-chip { padding: 6px 12px; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; border-radius: 20px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: all .15s; }
.filter-chip:hover { background: #e2e8f0; color: #0f172a; }
.filter-chip.active { background: #0078d4; border-color: #0078d4; color: #fff; }
.filter-chip i { font-size: 11px; }
.filter-sep { width: 1px; height: 22px; background: #e2e8f0; margin: 0 6px; }
.filter-date-wrap { display: flex; gap: 4px; align-items: center; }
.filter-date-wrap input[type=date] { padding: 6px 8px; border: 1px solid #cfd8dc; border-radius: 6px; font-size: 13px; }
.filter-date-wrap label { font-size: 12px; color: #607d8b; }
.filter-apply { padding: 6px 14px; background: #22c55e; color: #fff; border: 1px solid #16a34a; border-radius: 6px; cursor: pointer; font-size: 13px; }
.filter-apply:hover { background: #16a34a; }

/* === Responsive / Mobile-card layout === */
@media (max-width: 900px) {
    .today-stats { grid-template-columns: repeat(2, 1fr); gap: 6px; }
    .filter-sep { display: none; }
    .filter-date-wrap { width: 100%; justify-content: flex-start; margin-top: 4px; }
    .filter-date-wrap input[type=date] { flex: 1; min-width: 0; }
}

@media (max-width: 640px) {
    /* Compact page header */
    .today-header { margin-bottom: 8px; gap: 6px; }
    .today-header h3 { font-size: 15px; }
    .today-header .today-date { font-size: 11px; }
    .today-header .btn { padding: 5px 10px !important; font-size: 12px; }

    /* Compact filter bar */
    .filter-bar { padding: 6px 8px; gap: 4px; margin-bottom: 8px; border-radius: 6px; }
    .filter-bar .filter-group { gap: 3px; }
    .filter-chip { padding: 4px 8px; font-size: 11px; border-radius: 14px; gap: 3px; }
    .filter-chip i { font-size: 9px; }
    .filter-date-wrap { margin-top: 2px; gap: 3px; flex-wrap: wrap; }
    .filter-date-wrap label { font-size: 11px; }
    .filter-date-wrap input[type=date] { padding: 4px 6px; font-size: 11px; }
    .filter-apply { padding: 4px 10px; font-size: 11px; }

    /* Compact stat cards */
    .today-stats { gap: 4px; margin-bottom: 8px; }
    .today-stat { padding: 6px 8px; border-radius: 6px; }
    .today-stat .label { font-size: 10px; margin-bottom: 2px; }
    .today-stat .value { font-size: 13px; font-weight: 700; }

    /* Card list — natural page scroll (no inner scroll on mobile) */
    .today-table-wrap {
        max-height: none;
        overflow: visible;
        padding: 0;
        background: transparent;
        border: none;
        border-radius: 0;
    }

    /* Table → stacked cards */
    .today-table { border: none; background: transparent; display: block; min-width: 0; }
    .today-table thead { display: none; }
    .today-table tbody { display: block; }
    .today-table tr {
        display: block;
        background: #fff;
        border: 1px solid #e3e8ee;
        border-radius: 8px;
        padding: 7px 9px;
        margin-bottom: 6px;
        box-shadow: 0 1px 2px rgba(15,23,42,.04);
    }
    .today-table tr:hover td { background: transparent; }
    .today-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 3px 0;
        border: none;
        text-align: right;
        font-size: 12px;
    }
    .today-table td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #607d8b;
        font-size: 11px;
        flex-shrink: 0;
    }
    .today-table td.card-title {
        display: block;
        padding: 0 0 5px 0;
        margin-bottom: 4px;
        border-bottom: 1px solid #eef2f5;
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        text-align: start;
    }
    .today-table td.card-title::before { display: none; }
    .today-table td.card-index { display: none; }
    .today-table td.card-actions {
        display: block;
        padding-top: 6px;
        margin-top: 4px;
        border-top: 1px solid #eef2f5;
    }
    .today-table td.card-actions::before { display: none; }
    .today-table td.card-actions .call-btn {
        display: flex;
        justify-content: center;
        width: 100%;
        padding: 7px;
        font-size: 12px;
        margin: 0;
        border-radius: 6px;
    }
    .promise-input { width: 100px; padding: 4px 6px; font-size: 12px; }
    .phone-link { font-size: 12px; gap: 4px; }
    .debt-cell { font-size: 12px; }
}
</style>

<?php
// Build the PDF URL with the same filter params
$pdfParams = ['mode' => $mode];
if ($mode === 'range') { $pdfParams['from'] = $rangeFrom; $pdfParams['to'] = $rangeTo; }
elseif ($mode === 'date') { $pdfParams['date'] = $rangeFrom; }
$pdfUrl = APP_URL . '/today-appointments-pdf.php?' . http_build_query($pdfParams);
?>
<div class="today-header">
    <div>
        <h3><i class="fas fa-calendar-day"></i> مەوعیدەکانی <?= htmlspecialchars($rangeLabel) ?></h3>
        <div class="today-date">
            <?= $rangeFrom === $rangeTo ? htmlspecialchars($rangeFrom) : htmlspecialchars($rangeFrom) . ' — ' . htmlspecialchars($rangeTo) ?>
        </div>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" class="btn btn-danger" style="background:#c62828;border-color:#c62828;">
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

<?php
$chips = [
    ['yesterday', 'دوێنێ',        'fa-arrow-right'],
    ['today',     'ئەمڕۆ',         'fa-calendar-day'],
    ['tomorrow',  'بەیانی',        'fa-arrow-left'],
    ['day_after', 'دووبەیانی',     'fa-angle-double-left'],
    ['week',      'ئەم هەفتەیە',    'fa-calendar-week'],
    ['month',     'ئەم مانگە',      'fa-calendar-alt'],
];
?>
<form method="GET" action="" class="filter-bar">
    <div class="filter-group">
        <?php foreach ($chips as [$k, $label, $icon]): ?>
        <a href="?mode=<?= $k ?>" class="filter-chip <?= $mode === $k ? 'active' : '' ?>">
            <i class="fas <?= $icon ?>"></i> <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="filter-sep"></div>

    <input type="hidden" name="mode" value="range">
    <div class="filter-date-wrap">
        <label>لە</label>
        <input type="date" name="from" value="<?= htmlspecialchars($rangeFrom) ?>">
        <label>بۆ</label>
        <input type="date" name="to" value="<?= htmlspecialchars($rangeTo) ?>">
        <button type="submit" class="filter-apply"><i class="fas fa-search"></i> گەڕان</button>
    </div>
</form>

<div class="today-stats">
    <div class="today-stat">
        <div class="label">کۆی مەوعیدەکان</div>
        <div class="value"><?= count($rows) ?></div>
    </div>
    <div class="today-stat">
        <div class="label">ژمارەی قەرزارەکان</div>
        <div class="value"><?= $debtorCount ?></div>
    </div>
    <div class="today-stat debt">
        <div class="label">کۆی گشتی قەرز</div>
        <div class="value"><?= number_format($totalDebtSum) ?></div>
    </div>
    <div class="today-stat promise">
        <div class="label">کۆی پارەی بەڵێندراو</div>
        <div class="value"><?= number_format($totalPromised) ?></div>
    </div>
</div>

<?php if (empty($rows)): ?>
<div class="empty-box">
    <i class="fas fa-calendar-times fa-3x" style="color:#cfd8dc; margin-bottom:10px;"></i>
    <div>هیچ مەوعیدێک بۆ ئەمڕۆ نەدۆزرایەوە</div>
</div>
<?php else: ?>
<div class="today-table-wrap">
<table class="today-table">
    <thead>
        <tr>
            <th>#</th>
            <th>ناوی نەخۆش</th>
            <th>بەروار</th>
            <th>کات</th>
            <th>ناوی دکتۆر</th>
            <th>ماوە</th>
            <th>ژمارە مۆبایل</th>
            <th>کۆی گشتی قەرز</th>
            <th>بڕی پارەی هێنراو</th>
            <th>کردار</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $i => $r):
            $debt = max(0, (float)$r['total_debt']);
            $phone = preg_replace('/\s+/', '', (string)$r['patient_phone']);
        ?>
        <tr>
            <td class="card-index" data-label="#"><?= $i + 1 ?></td>
            <td class="card-title" data-label="ناوی نەخۆش">
                <i class="fas fa-user" style="color:#0078d4;"></i> <?= htmlspecialchars($r['patient_name'] ?? '—') ?>
            </td>
            <td data-label="بەروار"><?= htmlspecialchars($r['appointment_date']) ?></td>
            <td data-label="کات"><?= date('H:i', strtotime($r['appointment_time'])) ?></td>
            <td data-label="ناوی دکتۆر"><?= htmlspecialchars($r['doctor_name'] ?? '—') ?></td>
            <td data-label="ماوە"><?= intval($r['duration']) ?> خولەک</td>
            <td data-label="ژمارە مۆبایل">
                <?php if (!empty($phone)): ?>
                <a href="tel:<?= htmlspecialchars($phone) ?>" class="phone-link">
                    <i class="fas fa-phone-alt"></i> <?= htmlspecialchars($phone) ?>
                </a>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td data-label="کۆی گشتی قەرز">
                <?php if ($debt > 0): ?>
                <span class="debt-cell"><?= number_format($debt) ?></span>
                <?php else: ?>
                <span class="no-debt">بێ قەرز</span>
                <?php endif; ?>
            </td>
            <td data-label="بڕی پارەی هێنراو">
                <input type="number" min="0" step="500"
                       class="promise-input"
                       value="<?= $r['promised_amount'] !== null ? htmlspecialchars($r['promised_amount']) : '' ?>"
                       data-appointment-id="<?= $r['id'] ?>"
                       placeholder="0"
                       onchange="savePromisedAmount(this)"
                       <?= $debt <= 0 ? 'disabled title="نەخۆشی بێ قەرز"' : '' ?>>
            </td>
            <td class="card-actions" data-label="کردار">
                <?php if (!empty($phone)): ?>
                <a href="tel:<?= htmlspecialchars($phone) ?>" class="call-btn" title="پەیوەندیکردنی ڕاستەوخۆ">
                    <i class="fas fa-phone"></i> پەیوەندی
                </a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<script>
function savePromisedAmount(input) {
    const id = input.dataset.appointmentId;
    const amount = input.value.trim();
    input.classList.remove('saved', 'error');
    fetch('<?= APP_URL ?>/api/save-promised-amount.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ appointment_id: id, amount: amount })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            input.classList.add('saved');
            setTimeout(() => input.classList.remove('saved'), 1500);
        } else {
            input.classList.add('error');
            alert('هەڵە: ' + (d.message || 'ناتوانرێت پاشەکەوت بکرێت'));
        }
    })
    .catch(e => {
        input.classList.add('error');
        alert('کێشەی پەیوەندی: ' + e.message);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
