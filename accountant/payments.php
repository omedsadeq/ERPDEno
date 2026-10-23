<?php
require_once __DIR__ . '/../config/config.php';
/**
 * Accountant - Payments Management
 * بەڕێوەبردنی پارەدانەکان
 */

$pageTitle = 'وەرگرتنی پارە';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'],
    ['title' => 'وەرگرتنی پارە']
];

// Role gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$__gate = new Auth();
$__gate->requireLogin();
if (!$__gate->isAccountant() && !$__gate->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$paymentManager = new Payment();
$xrayManager = new XrayManager();

// GET params can arrive as arrays (?status[]=) — coerce to strings
$getStr = function ($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? $v : $default;
};
$validDate = function ($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
};

$status   = $getStr('status');
if (!in_array($status, ['', 'approved', 'pending', 'rejected', 'refunded'], true)) { $status = ''; }
$type     = $getStr('type');
if (!in_array($type, ['', 'cash', 'credit', 'installment'], true)) { $type = ''; }
$search   = trim($getStr('search'));
$dateFrom = $getStr('date_from', date('Y-m-01'));
$dateTo   = $getStr('date_to', date('Y-m-d'));
if (!$validDate($dateFrom)) { $dateFrom = date('Y-m-01'); }
if (!$validDate($dateTo)) { $dateTo = date('Y-m-d'); }
$page     = max(1, intval($_GET['page'] ?? 1));

$filters  = ['status' => $status, 'payment_type' => $type, 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo];

// Pagination (count first so an out-of-range ?page= clamps instead of showing an empty table)
$totalCount  = $paymentManager->getCount($filters);
$totalPages  = max(1, (int)ceil($totalCount / ITEMS_PER_PAGE));
$page        = min($page, $totalPages);
$payments    = $paymentManager->getAll($page, ITEMS_PER_PAGE, $filters);

// Stats — keyed to payment_date (collection date, same as the table filter and
// the Payment-class reports), with half-open ranges so the index is usable.
$todayIncomeSql = "SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='approved'
                   AND payment_date >= CURDATE() AND payment_date < CURDATE() + INTERVAL 1 DAY";
$monthIncomeSql = "SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='approved'
                   AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                   AND payment_date < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH";
// Include X-ray income (kept outside the `payments` table) so the totals match
// the dashboard and financial summary.
$todayIncome  = ($db->fetch($todayIncomeSql)['t'] ?? 0) + $xrayManager->getIncome(date('Y-m-d'), date('Y-m-d'));
$monthIncome  = ($db->fetch($monthIncomeSql)['t'] ?? 0) + $xrayManager->getIncome(date('Y-m-01'), date('Y-m-t'));
$totalIncome  = ($db->fetch("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='approved'")['t'] ?? 0) + $xrayManager->getIncome();

$statusLabels = ['pending'=>'چاوەڕوان','approved'=>'پەسەندکراو','rejected'=>'ڕەتکراوە','refunded'=>'گەڕێنراوەتەوە'];
$statusColors = ['pending'=>'#ff8c00','approved'=>'#107c10','rejected'=>'#c50f1f','refunded'=>'#8764b8'];
$statusBg     = ['pending'=>'#fff4e5','approved'=>'#e8f5e9','rejected'=>'#fce8e8','refunded'=>'#f4f0fa'];
$typeLabels   = ['cash'=>'کاش','credit'=>'قەرز','installment'=>'قیست'];

// Shared query string for pagination links
$qs = http_build_query(['status' => $status, 'type' => $type, 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
?>

<style>
.report-container { max-width:1400px; margin:0 auto; }
.report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
.report-title { display:flex; align-items:center; gap:12px; }
.report-title h2 { margin:0; font-size:20px; color:#1a1a1a; }
.report-title .icon { width:45px; height:45px; background:linear-gradient(135deg,#107c10,#0e6b0e); border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-size:20px; box-shadow:0 4px 12px rgba(16,124,16,0.3); }
.filters-card { background:white; border-radius:12px; padding:18px; margin-bottom:20px; box-shadow:0 2px 12px rgba(0,0,0,0.08); }
.filters-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:5px; }
.filter-group label { font-size:11px; color:#666; font-weight:600; }
.filter-group input, .filter-group select { padding:9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; min-width:150px; }
.filter-group input:focus, .filter-group select:focus { border-color:var(--win-accent, #0078d4); outline:none; box-shadow:0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1); }
.btn-filter { padding:9px 18px; background:linear-gradient(135deg,var(--win-accent, #0078d4),var(--win-accent-deep, #0063b1)); color:white; border:none; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; display:flex; align-items:center; gap:6px; }
.btn-reset { padding:9px 18px; background:#f5f5f5; color:#666; border:1.5px solid #e0e0e0; border-radius:8px; cursor:pointer; font-size:13px; font-family:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; }
.stats-row { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.stat-card { background:white; border-radius:8px; padding:10px 16px; box-shadow:0 1px 4px rgba(0,0,0,0.06); display:flex; flex-direction:column; gap:2px; border-right:3px solid transparent; min-width:140px; }
.stat-value { font-size:18px; font-weight:700; }
.stat-label { font-size:11px; color:#888; }
.table-card { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; }
.table-header { padding:16px 18px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg,#fafafa,#f5f5f5); }
.table-header h3 { margin:0; font-size:15px; color:#333; display:flex; align-items:center; gap:8px; }
.table-count { background:linear-gradient(135deg,var(--win-accent, #0078d4),var(--win-accent-deep, #0063b1)); color:white; padding:5px 12px; border-radius:14px; font-size:12px; font-weight:600; }
.report-table { width:100%; border-collapse:collapse; font-size:13px; }
.report-table th { background:#f8f9fa; padding:13px 14px; text-align:right; font-weight:600; color:#333; border-bottom:2px solid #e0e0e0; }
.report-table td { padding:13px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.report-table tr:hover { background:#f9f9f9; }
.status-badge { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:14px; font-size:11px; font-weight:600; }
.btn-icon { width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:8px; border:none; cursor:pointer; transition:all 0.2s; text-decoration:none; font-size:13px; }
.btn-primary-icon { background:linear-gradient(135deg,var(--win-accent, #0078d4),var(--win-accent-deep, #0063b1)); color:white; }
.btn-success-icon { background:linear-gradient(135deg,#107c10,#0e6b0e); color:white; }
.btn-secondary-icon { background:#f0f0f0; color:#333; }
.btn-icon:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
.actions-cell { display:flex; gap:6px; align-items:center; }
.no-data { text-align:center; padding:50px; color:#888; }
.no-data i { font-size:50px; margin-bottom:15px; display:block; color:#ddd; }
.pagination-wrapper { padding:16px 18px; border-top:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fafafa; }
.pagination-info { font-size:13px; color:#666; }
.pagination { display:flex; gap:6px; }
.pagination a, .pagination span { padding:8px 14px; border-radius:8px; font-size:13px; text-decoration:none; }
.pagination a { background:white; color:var(--win-accent, #0078d4); border:1.5px solid #e0e0e0; }
.pagination a:hover { background:var(--win-accent, #0078d4); color:white; }
.pagination .active { background:linear-gradient(135deg,var(--win-accent, #0078d4),var(--win-accent-deep, #0063b1)); color:white; border:none; }
.pagination .disabled { background:#f5f5f5; color:#bdbdbd; border:1.5px solid #e0e0e0; cursor:not-allowed; }
</style>

<div class="report-container">

    <!-- Header -->
    <div class="report-header">
        <div class="report-title">
            <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
            <h2>پارەدانەکان</h2>
        </div>
        <button type="button" class="btn-filter" style="background:linear-gradient(135deg,#107c10,#0e6b0e)" onclick="rpOpen()">
            <i class="fas fa-hand-holding-usd"></i> وەرگرتنی پارەی نوێ
        </button>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card" style="border-right-color:#107c10;">
            <div class="stat-value" style="color:#107c10;"><?= number_format($todayIncome) ?></div>
            <div class="stat-label">داهاتی ئەمرۆ <span style="color:#bbb;">(لەگەڵ ئێکسڕەی)</span></div>
        </div>
        <div class="stat-card" style="border-right-color:var(--win-accent, #0078d4);">
            <div class="stat-value" style="color:var(--win-accent, #0078d4);"><?= number_format($monthIncome) ?></div>
            <div class="stat-label">داهاتی مانگی ئێستا <span style="color:#bbb;">(لەگەڵ ئێکسڕەی)</span></div>
        </div>
        <div class="stat-card" style="border-right-color:#8764b8;">
            <div class="stat-value" style="color:#8764b8;"><?= number_format($totalIncome) ?></div>
            <div class="stat-label">کۆی هەموو داهات <span style="color:#bbb;">(لەگەڵ ئێکسڕەی)</span></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <form method="GET">
            <div class="filters-row">
                <div class="filter-group" style="flex:1;min-width:200px;">
                    <label>گەڕان</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ناوی نەخۆش، ژمارەی کەیس، تەلەفۆن" autofocus>
                </div>
                <div class="filter-group">
                    <label>لە بەروار</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="filter-group">
                    <label>بۆ بەروار</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="filter-group">
                    <label>دۆخ</label>
                    <select name="status">
                        <option value="">هەموو دۆخەکان</option>
                        <option value="approved" <?= $status==='approved'?'selected':'' ?>>پەسەندکراو</option>
                        <option value="pending"  <?= $status==='pending'?'selected':'' ?>>چاوەڕوان</option>
                        <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>ڕەتکراوە</option>
                        <option value="refunded" <?= $status==='refunded'?'selected':'' ?>>گەڕێنراوەتەوە</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>جۆر</label>
                    <select name="type">
                        <option value="">هەموو جۆرەکان</option>
                        <option value="cash" <?= $type==='cash'?'selected':'' ?>>کاش</option>
                        <option value="credit" <?= $type==='credit'?'selected':'' ?>>قەرز</option>
                        <option value="installment" <?= $type==='installment'?'selected':'' ?>>قیست</option>
                    </select>
                </div>
                <div class="filter-group" style="justify-content:flex-end;">
                    <button type="submit" class="btn-filter"><i class="fas fa-search"></i> گەڕان</button>
                </div>
                <div class="filter-group" style="justify-content:flex-end;">
                    <a href="<?= APP_URL ?>/accountant/payments.php" class="btn-reset"><i class="fas fa-times"></i> پاکردنەوە</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> لیستی پارەدانەکان</h3>
            <span class="table-count"><?= number_format($totalCount) ?> پارەدان</span>
        </div>

        <?php if (empty($payments)): ?>
        <div class="no-data">
            <i class="fas fa-money-bill-wave"></i>
            <?php if ($search !== '' || $status !== '' || $type !== ''): ?>
            هیچ پارەدانێک بەم فلتەرە نەدۆزرایەوە — <a href="<?= APP_URL ?>/accountant/payments.php">پاککردنەوەی فلتەر</a>
            <?php else: ?>
            هیچ پارەدانێک نییە
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نەخۆش</th>
                    <th>کەیس</th>
                    <th>دکتۆر</th>
                    <th style="text-align:center;">بڕ</th>
                    <th style="text-align:center;">جۆر</th>
                    <th style="text-align:center;">بەروار</th>
                    <th style="text-align:center;">دۆخ</th>
                    <th style="text-align:center;">کردار</th>
                </tr>
            </thead>
            <tbody>
            <?php $rowNum = ($page - 1) * ITEMS_PER_PAGE + 1; ?>
            <?php foreach ($payments as $py): ?>
            <tr>
                <td style="color:#999;font-size:12px;"><?= $rowNum++ ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($py['patient_name'] ?? '—') ?></td>
                <td style="font-size:12px;color:#666;"><?= htmlspecialchars($py['case_number'] ?? '—') ?></td>
                <td style="font-size:12px;color:#666;"><?= htmlspecialchars($py['doctor_name'] ?? '—') ?></td>
                <?php // only approved money is green — rejected/refunded rows must not read as income ?>
                <td style="text-align:center;font-weight:700;color:<?= $py['status'] === 'approved' ? '#107c10' : ($statusColors[$py['status']] ?? '#999') ?>;<?= in_array($py['status'], ['rejected','refunded'], true) ? 'text-decoration:line-through;' : '' ?>"><?= number_format($py['amount']) ?></td>
                <td style="text-align:center;"><?= $typeLabels[$py['payment_type']] ?? htmlspecialchars($py['payment_type']) ?></td>
                <td style="text-align:center;font-size:12px;color:#666;"><?= date('Y-m-d', strtotime($py['payment_date'] ?? $py['created_at'])) ?></td>
                <td style="text-align:center;">
                    <span class="status-badge" style="color:<?= $statusColors[$py['status']] ?? '#666' ?>;background:<?= $statusBg[$py['status']] ?? '#f5f5f5' ?>;">
                        <?= $statusLabels[$py['status']] ?? $py['status'] ?>
                    </span>
                </td>
                <td style="text-align:center;">
                    <div class="actions-cell" style="justify-content:center;">
                        <a href="<?= APP_URL ?>/accountant/report-detail.php?case_id=<?= $py['case_id'] ?>" class="btn-icon btn-secondary-icon" title="بینین">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="<?= APP_URL ?>/cases/receipt.php?id=<?= $py['case_id'] ?>" target="_blank" class="btn-icon btn-success-icon" title="چاپکردنی وەسڵ">
                            <i class="fas fa-print"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /overflow-x -->

        <!-- Pagination (windowed: « 1 … n-2 n-1 n n+1 n+2 … N ») -->
        <div class="pagination-wrapper">
            <div class="pagination-info">
                کۆی <?= number_format($totalCount) ?> پارەدان — لاپەڕە <?= $page ?> لە <?= $totalPages ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?= $qs ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>

                <?php
                $window = range(max(1, $page - 2), min($totalPages, $page + 2));
                if ($window[0] > 1) {
                    echo '<a href="?' . $qs . '&page=1">1</a>';
                    if ($window[0] > 2) { echo '<span class="disabled">…</span>'; }
                }
                foreach ($window as $i) {
                    echo $i === $page
                        ? '<span class="active">' . $i . '</span>'
                        : '<a href="?' . $qs . '&page=' . $i . '">' . $i . '</a>';
                }
                if (end($window) < $totalPages) {
                    if (end($window) < $totalPages - 1) { echo '<span class="disabled">…</span>'; }
                    echo '<a href="?' . $qs . '&page=' . $totalPages . '">' . $totalPages . '</a>';
                }
                ?>

                <?php if ($page < $totalPages): ?>
                <a href="?<?= $qs ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-left"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- وەرگرتنی پارەی نوێ — search any patient and collect cash on the spot,
     even against a draft invoice the doctor hasn't had time to send yet
     (Payment::directPayment's allow_draft). This is what the "وەرگرتنی
     پارە" nav item always claimed to be; this page previously only listed
     payment history. -->
<dialog id="rpDialog" class="rp-modal">
    <div class="rp-modal-head">
        <h3><i class="fas fa-hand-holding-usd"></i> وەرگرتنی پارەی نوێ</h3>
        <button type="button" class="rp-close" onclick="rpClose()">×</button>
    </div>
    <div class="rp-modal-body">

        <div id="rpStepPatient">
            <label class="rp-label">گەڕان بۆ نەخۆش (ناو یان ژمارەی مۆبایل)</label>
            <input type="text" id="rpPatientSearch" class="rp-input" placeholder="ناوی نەخۆش..." autocomplete="off">
            <div id="rpPatientResults" class="rp-results"></div>
        </div>

        <div id="rpStepCase" hidden>
            <div class="rp-patient-bar">
                <span><i class="fas fa-user"></i> <strong id="rpPatientName"></strong></span>
                <button type="button" class="rp-link" onclick="rpResetPatient()">گۆڕینی نەخۆش</button>
            </div>
            <div id="rpNoCase" class="rp-empty" hidden>هیچ کەیسێکی کراوەی خاوەن قەرز نییە بۆ ئەم نەخۆشە.</div>
            <div id="rpCaseList"></div>
        </div>

        <div id="rpStepPay" hidden>
            <div class="rp-patient-bar">
                <span><i class="fas fa-folder"></i> <strong id="rpCaseLabel"></strong></span>
                <button type="button" class="rp-link" onclick="rpBackToCases()">گۆڕینی کەیس</button>
            </div>
            <form id="rpForm" onsubmit="event.preventDefault(); rpSubmit();">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="receive_payment">
                <input type="hidden" name="case_id" id="rpCaseId">
                <input type="hidden" name="idempotency_key" id="rpIdemKey">
                <input type="hidden" name="allow_draft" value="1">
                <div class="rp-form-grid">
                    <div class="rp-field"><label>بڕی پارەدان</label><input class="rp-input" id="rpAmount" name="amount" type="number" min="0.01" step="0.01" required></div>
                    <div class="rp-field"><label>بەروار</label><input class="rp-input" name="payment_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="rp-field"><label>ڕێگای پارەدان</label>
                        <select class="rp-input" name="payment_method">
                            <option value="cash">نەقد</option>
                            <option value="card">کارت</option>
                            <option value="transfer">گواستنەوە</option>
                            <option value="other">هی تر</option>
                        </select>
                    </div>
                    <div class="rp-field"><label>تێبینی</label><input class="rp-input" name="notes" placeholder="ژمارەی بەڵگە یان تێبینی..."></div>
                </div>

                <div class="rp-table-head">
                    <span>پسووڵە ماوەدارەکان</span>
                    <div>
                        <button type="button" class="rp-mini-btn" onclick="rpAutoAllocate()">دابەشکردنی خۆکار</button>
                        <button type="button" class="rp-mini-btn" onclick="rpClearAllocations()">پاککردنەوە</button>
                    </div>
                </div>
                <div style="overflow-x:auto">
                <table class="rp-table"><thead><tr><th></th><th>پسووڵە</th><th>دۆخ</th><th>پوختە</th><th>دراو</th><th>ماوە</th><th>دابەش</th></tr></thead>
                <tbody id="rpAllocationRows"></tbody>
                <tfoot><tr><th colspan="6">کۆی دابەشکراو</th><th class="rp-num"><span id="rpAllocatedTotal">0</span></th></tr></tfoot>
                </table>
                </div>
                <div id="rpAllocationMessage" style="margin-top:8px;font-size:11px"></div>
            </form>
        </div>

    </div>
    <div class="rp-modal-foot">
        <button type="button" class="btn-filter" id="rpSubmitBtn" onclick="rpSubmit()" disabled><i class="fas fa-check"></i> وەرگرتنی پارە</button>
        <button type="button" class="btn-reset" onclick="rpClose()">پاشگەزبوونەوە</button>
    </div>
</dialog>

<style>
.rp-modal{border:0;padding:0;border-radius:12px;width:min(760px,calc(100vw - 28px));max-height:88vh;box-shadow:0 24px 70px rgba(16,38,58,0.3);direction:rtl}
.rp-modal::backdrop{background:rgba(13,34,54,0.4)}
.rp-modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eee;background:#fafafa}
.rp-modal-head h3{margin:0;font-size:15px;display:flex;align-items:center;gap:8px}
.rp-close{border:0;background:transparent;font-size:22px;cursor:pointer;color:#666}
.rp-modal-body{padding:16px 18px;overflow:auto;max-height:calc(88vh - 130px)}
.rp-modal-foot{display:flex;gap:8px;padding:12px 18px;border-top:1px solid #eee;background:#fafafa}
.rp-label{display:block;font-size:12px;color:#666;margin-bottom:6px;font-weight:600}
.rp-input{width:100%;border:1.5px solid #e0e0e0;border-radius:8px;padding:9px 10px;font:13px inherit;background:#fff;color:#222}
.rp-input:focus{outline:none;border-color:var(--win-accent, #0078d4);box-shadow:0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1)}
.rp-results{margin-top:8px;max-height:260px;overflow:auto}
.rp-result-item{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border:1px solid #eee;border-radius:8px;margin-bottom:6px;cursor:pointer}
.rp-result-item:hover{background:#f5f9ff;border-color:var(--win-accent, #0078d4)}
.rp-result-item .n{font-weight:700;font-size:13px}
.rp-result-item .p{font-size:11px;color:#888}
.rp-patient-bar{display:flex;justify-content:space-between;align-items:center;background:#eff7ff;border:1px solid #c9e3fa;border-radius:8px;padding:9px 12px;margin-bottom:12px}
.rp-link{border:0;background:transparent;color:var(--win-accent, #0078d4);font-size:12px;cursor:pointer;text-decoration:underline}
.rp-case-item{display:flex;justify-content:space-between;align-items:center;padding:11px 12px;border:1px solid #eee;border-radius:8px;margin-bottom:8px;cursor:pointer}
.rp-case-item:hover{background:#f5f9ff;border-color:var(--win-accent, #0078d4)}
.rp-case-item .bal{font-weight:700;color:#c50f1f}
.rp-empty{text-align:center;color:#888;padding:24px}
.rp-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}
.rp-field label{display:block;font-size:10px;color:#666;margin-bottom:4px}
.rp-table-head{display:flex;justify-content:space-between;align-items:center;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px 8px 0 0;padding:9px 12px;font-size:12px;font-weight:700}
.rp-mini-btn{border:1px solid #cfd9e5;background:#fff;border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer;margin-inline-start:6px}
.rp-table{width:100%;border-collapse:collapse;font-size:12px;min-width:560px}
.rp-table th{background:#f8f9fa;border:1px solid #e0e0e0;border-top:0;padding:7px 8px;font-size:11px}
.rp-table td{border:1px solid #eee;padding:7px 8px}
.rp-num{direction:ltr;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.rp-apply{width:100px;direction:ltr;text-align:left}
.rp-invalid{color:#c50f1f}
.rp-valid{color:#107c10}
.rp-draft-badge{background:#fff4e5;color:#8a5a00;border-radius:8px;padding:1px 7px;font-size:10px;font-weight:700}
@media(max-width:600px){.rp-form-grid{grid-template-columns:1fr 1fr}}
</style>

<script>
const RP_APP_URL = '<?= APP_URL ?>';
let rpSelectedPatient = null;
let rpSelectedCase = null;

function rpOpen() {
    document.getElementById('rpDialog').showModal();
    rpResetPatient();
}
function rpClose() { document.getElementById('rpDialog').close(); }

function rpMoney(n) { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(Number(n) || 0); }
function rpEsc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function rpToken(prefix) { return prefix + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10); }

function rpResetPatient() {
    rpSelectedPatient = null;
    rpSelectedCase = null;
    document.getElementById('rpStepPatient').hidden = false;
    document.getElementById('rpStepCase').hidden = true;
    document.getElementById('rpStepPay').hidden = true;
    document.getElementById('rpSubmitBtn').hidden = false;
    document.getElementById('rpSubmitBtn').disabled = true;
    document.getElementById('rpPatientSearch').value = '';
    document.getElementById('rpPatientResults').innerHTML = '';
    document.getElementById('rpPatientSearch').focus();
}

let rpSearchTimer = null;
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('rpPatientSearch');
    if (!input) return;
    input.addEventListener('input', function () {
        clearTimeout(rpSearchTimer);
        const q = input.value.trim();
        if (q.length < 2) { document.getElementById('rpPatientResults').innerHTML = ''; return; }
        rpSearchTimer = setTimeout(function () { rpSearchPatients(q); }, 300);
    });
});

async function rpSearchPatients(q) {
    const box = document.getElementById('rpPatientResults');
    box.innerHTML = '<div class="rp-empty">گەڕان...</div>';
    try {
        const res = await fetch(RP_APP_URL + '/ajax/patient_search.php?slim=1&limit=8&q=' + encodeURIComponent(q));
        const data = await res.json();
        const results = data.results || [];
        if (!results.length) { box.innerHTML = '<div class="rp-empty">هیچ نەخۆشێک نەدۆزرایەوە</div>'; return; }
        box.innerHTML = '';
        results.forEach(function (p) {
            const row = document.createElement('div');
            row.className = 'rp-result-item';
            row.innerHTML = '<div><div class="n">' + rpEsc(p.full_name) + '</div><div class="p">' + rpEsc(p.patient_code || '') + ' · ' + rpEsc(p.phone || '') + '</div></div><i class="fas fa-chevron-left"></i>';
            row.onclick = function () { rpSelectPatient(p); };
            box.appendChild(row);
        });
    } catch (e) {
        box.innerHTML = '<div class="rp-empty">هەڵەیەک ڕوویدا</div>';
    }
}

async function rpSelectPatient(p) {
    rpSelectedPatient = p;
    document.getElementById('rpPatientName').textContent = p.full_name;
    document.getElementById('rpStepPatient').hidden = true;
    document.getElementById('rpStepCase').hidden = false;
    document.getElementById('rpCaseList').innerHTML = '<div class="rp-empty">بارکردن...</div>';
    document.getElementById('rpNoCase').hidden = true;
    try {
        const res = await fetch(RP_APP_URL + '/ajax/patient_open_cases.php?patient_id=' + p.id);
        const data = await res.json();
        const list = document.getElementById('rpCaseList');
        list.innerHTML = '';
        if (!data.success || !data.cases || !data.cases.length) {
            document.getElementById('rpNoCase').hidden = false;
            return;
        }
        data.cases.forEach(function (c) {
            const remaining = c.invoices.reduce(function (sum, inv) { return sum + inv.remaining_amount; }, 0);
            const row = document.createElement('div');
            row.className = 'rp-case-item';
            row.innerHTML = '<span>' + rpEsc(c.label) + '</span><span class="bal">' + rpMoney(remaining) + '</span>';
            row.onclick = function () { rpSelectCase(c); };
            list.appendChild(row);
        });
    } catch (e) {
        document.getElementById('rpCaseList').innerHTML = '<div class="rp-empty">هەڵەیەک ڕوویدا</div>';
    }
}

function rpBackToCases() {
    document.getElementById('rpStepPay').hidden = true;
    document.getElementById('rpStepCase').hidden = false;
}

function rpSelectCase(c) {
    rpSelectedCase = c;
    document.getElementById('rpCaseLabel').textContent = c.label;
    document.getElementById('rpStepCase').hidden = true;
    document.getElementById('rpStepPay').hidden = false;
    document.getElementById('rpCaseId').value = c.id;
    document.getElementById('rpIdemKey').value = rpToken('acpay-' + c.id);
    document.getElementById('rpAmount').value = '';

    const tbody = document.getElementById('rpAllocationRows');
    tbody.innerHTML = '';
    c.invoices.forEach(function (inv) {
        const tr = document.createElement('tr');
        tr.dataset.invoice = inv.id;
        tr.dataset.remaining = inv.remaining_amount;
        tr.innerHTML =
            '<td><input class="rp-check" type="checkbox" checked></td>' +
            '<td><b dir="ltr">' + rpEsc(inv.invoice_no) + '</b><div style="color:#888;font-size:10px">' + rpEsc(inv.work_date) + '</div></td>' +
            '<td>' + (inv.needs_draft ? '<span class="rp-draft-badge">' + rpEsc(inv.status_label) + '</span>' : rpEsc(inv.status_label)) + '</td>' +
            '<td class="rp-num">' + rpMoney(inv.net_amount) + '</td>' +
            '<td class="rp-num">' + rpMoney(inv.paid_amount) + '</td>' +
            '<td class="rp-num">' + rpMoney(inv.remaining_amount) + '</td>' +
            '<td><input class="rp-input rp-apply rp-alloc-input" type="number" min="0" max="' + inv.remaining_amount + '" step="0.01" value="0"></td>';
        tbody.appendChild(tr);
    });
    document.querySelectorAll('#rpAllocationRows .rp-alloc-input').forEach(function (i) { i.oninput = rpUpdateTotal; });
    document.querySelectorAll('#rpAllocationRows .rp-check').forEach(function (i) { i.onchange = rpAutoAllocate; });
    document.getElementById('rpAmount').oninput = rpAutoAllocate;
    rpUpdateTotal();
}

function rpAllocationRows() { return Array.from(document.querySelectorAll('#rpAllocationRows tr')); }
function rpClearAllocations() { rpAllocationRows().forEach(function (r) { r.querySelector('.rp-alloc-input').value = '0'; }); rpUpdateTotal(); }
function rpAutoAllocate() {
    let left = Math.max(0, Number(document.getElementById('rpAmount').value) || 0);
    rpAllocationRows().forEach(function (r) {
        const checked = r.querySelector('.rp-check').checked;
        const cap = Number(r.dataset.remaining) || 0;
        const apply = checked ? Math.min(cap, left) : 0;
        r.querySelector('.rp-alloc-input').value = apply.toFixed(2);
        left = Math.max(0, left - apply);
    });
    rpUpdateTotal();
}
function rpUpdateTotal() {
    const amount = Math.max(0, Number(document.getElementById('rpAmount').value) || 0);
    let total = 0;
    rpAllocationRows().forEach(function (r) {
        const input = r.querySelector('.rp-alloc-input');
        const cap = Number(r.dataset.remaining) || 0;
        let value = Math.max(0, Number(input.value) || 0);
        if (value > cap) { value = cap; input.value = cap.toFixed(2); }
        total += value;
    });
    total = Math.round(total * 100) / 100;
    document.getElementById('rpAllocatedTotal').textContent = rpMoney(total);
    const valid = amount > 0 && Math.abs(total - amount) < 0.009;
    const box = document.getElementById('rpAllocationMessage');
    box.className = valid ? 'rp-valid' : 'rp-invalid';
    box.textContent = valid ? 'دابەشکردن هاوسەنگە.' : ('پێویستە ' + rpMoney(Math.abs(amount - total)) + ' زیاتر دابەش بکرێت یان کەم بکرێتەوە.');
    document.getElementById('rpSubmitBtn').disabled = !valid;
}

async function rpSubmit() {
    const form = document.getElementById('rpForm');
    if (!form || document.getElementById('rpSubmitBtn').disabled) return;
    const fd = new FormData(form);
    rpAllocationRows().forEach(function (r) {
        const amt = Number(r.querySelector('.rp-alloc-input').value) || 0;
        if (amt > 0) fd.append('allocations[' + r.dataset.invoice + ']', amt.toFixed(2));
    });
    const btn = document.getElementById('rpSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...';
    try {
        const res = await fetch(RP_APP_URL + '/api/finance-actions.php', { method: 'POST', body: fd });
        const out = await res.json();
        if (out.ok) {
            window.location.reload();
            return;
        }
        alert(out.error || 'هەڵەیەک ڕوویدا');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> وەرگرتنی پارە';
    } catch (e) {
        alert('هەڵەی تەکنیکی ڕوویدا');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> وەرگرتنی پارە';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
