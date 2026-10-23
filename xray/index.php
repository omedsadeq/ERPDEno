<?php
/**
 * X-Ray requests dashboard.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$auth = new Auth();
$auth->requireLogin();
$permission = Permission::getInstance();

if (!$permission->can('page.xray')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isXrayDept = $auth->isXrayDepartment();
$isAccountant = $auth->isAccountant();
$xrayManager = new XrayManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return_to_accountant') {
    Csrf::require();
    if (!$isAdmin && !$isXrayDept) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }

    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) ?: 0;
    $reason = trim($_POST['return_reason'] ?? '');
    if ($requestId > 0 && $reason !== '') {
        $xrayManager->returnToAccounting($requestId, $reason, $currentUser['id'], $_SESSION['role'] ?? ROLE_XRAY_DEPARTMENT);
    }

    header('Location: ' . APP_URL . '/xray/index.php?returned=1');
    exit;
}

$pageTitle = 'داواکارییەکانی ئێکسڕەی';
$currentPage = 'xray';

$status = $_GET['status'] ?? '';
$reqStatus = $_GET['request_status'] ?? '';
$payStatus = $_GET['payment_status'] ?? '';
$xrayType = $_GET['xray_type'] ?? '';
$priority = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$validRequestStatuses = ['pending_accountant', 'pending_xray', 'in_xray', 'in_progress', 'completed', 'returned_to_accountant', 'cancelled'];
$validPaymentStatuses = ['pending_payment', 'paid', 'not_required', 'debt_pending', 'debt_approved', 'refunded'];
$validPriorities = ['normal', 'urgent'];

$filters = [];
if (!$isAdmin && !$isXrayDept && !$isAccountant) {
    $filters['requested_by'] = $currentUser['id'];
}

if ($isXrayDept && $status === '' && $reqStatus === '') {
    $filters['request_status_in'] = ['pending_xray', 'in_xray'];
}

if ($status !== '') {
    if (in_array($status, $validRequestStatuses, true)) {
        $filters['request_status'] = $status === 'in_progress' ? 'in_xray' : $status;
    } else {
        $filters['status'] = $status;
    }
}
if (in_array($reqStatus, $validRequestStatuses, true)) {
    $filters['request_status'] = $reqStatus === 'in_progress' ? 'in_xray' : $reqStatus;
}
if (in_array($payStatus, $validPaymentStatuses, true)) {
    $filters['payment_status'] = $payStatus;
}
if ($xrayType !== '') {
    $filters['xray_type'] = $xrayType;
}
if (in_array($priority, $validPriorities, true)) {
    $filters['priority'] = $priority;
}
if ($search !== '') {
    $filters['search'] = $search;
}
if ($dateFrom !== '') {
    $filters['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $filters['date_to'] = $dateTo;
}

// The active x-ray queue is turn-based (نۆرە): urgent first, then oldest
// first — matching the waiting-room "called in turn" workflow. History and
// mixed lists keep newest-first.
$activeQueueStatuses = ['pending_xray', 'in_xray'];
if (!empty($filters['request_status_in'])
    || in_array($filters['request_status'] ?? '', $activeQueueStatuses, true)) {
    $filters['order'] = 'queue';
}

$page = max(1, intval($_GET['page'] ?? 1));
$usePagination = $status === '' || $status === 'completed';
$itemsPerPage = $usePagination ? 15 : 1000;
$requests = $xrayManager->getAll($page, $itemsPerPage, $filters);
$totalCount = $xrayManager->count($filters);
$totalPages = $usePagination ? max(1, (int)ceil($totalCount / $itemsPerPage)) : 1;

$stats = $xrayManager->getStatistics(($isAdmin || $isXrayDept || $isAccountant) ? null : $currentUser['id']);
$pendingCount = $stats['by_status']['pending_xray'] ?? 0;
$inProgressCount = ($stats['by_status']['in_xray'] ?? 0) + ($stats['by_status']['in_progress'] ?? 0);
$completedCount = $stats['by_status']['completed'] ?? 0;
$cancelledCount = $stats['by_status']['cancelled'] ?? 0;

// ── On-page notifications (action needed) ──────────────────────────
$pendingAccountantCount = $stats['by_status']['pending_accountant'] ?? 0;
$returnedCount = $stats['by_status']['returned_to_accountant'] ?? 0;
$urgentScope = "COALESCE(NULLIF(request_status,''), status) NOT IN ('completed','cancelled') AND priority = 'urgent'";
if (!$isAdmin && !$isXrayDept && !$isAccountant) {
    $urgentScope .= " AND requested_by = " . (int)$currentUser['id'];
}
$urgentActiveCount = $db->count('xray_requests', $urgentScope);

global $XRAY_TYPES, $XRAY_REASONS, $XRAY_STATUS;

$requestStatusLabels = [
    'pending' => 'چاوەڕوان',
    'pending_accountant' => 'چاوەڕوانی ژمێریار',
    'pending_xray' => 'چاوەڕوانی ئێکسڕەی',
    'in_xray' => 'لە ئێکسڕەی',
    'in_progress' => 'لە ئێکسڕەی',
    'completed' => 'تەواوبوو',
    'returned_to_accountant' => 'گەڕاوەتەوە بۆ ژمێریار',
    'cancelled' => 'هەڵوەشاوە'
];
$paymentStatusLabels = [
    'pending_payment' => 'چاوەڕوانی پارەدان',
    'paid' => 'پارەدراوە',
    'not_required' => 'پێویست ناکات',
    'debt_pending' => 'قەرز چاوەڕوانە',
    'debt_approved' => 'قەرز پەسەندکرا',
    'refunded' => 'پارە گەڕاوەتەوە'
];
$statusBadgeClass = [
    'pending' => 'badge-yellow',
    'pending_accountant' => 'badge-orange',
    'pending_xray' => 'badge-yellow',
    'in_xray' => 'badge-blue',
    'in_progress' => 'badge-blue',
    'completed' => 'badge-green',
    'returned_to_accountant' => 'badge-red',
    'cancelled' => 'badge-red'
];
$paymentBadgeClass = [
    'pending_payment' => 'badge-yellow',
    'paid' => 'badge-green',
    'not_required' => 'badge-blue',
    'debt_pending' => 'badge-orange',
    'debt_approved' => 'badge-green',
    'refunded' => 'badge-red'
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* === X-Ray dashboard - unified compact card (Fluent-style) === */
.xray-card { padding:0; overflow:hidden; }

/* Card head: segmented status tabs + new-request action */
.xray-card-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:10px 14px; border-bottom:1px solid var(--stroke-divider,#e8ebee); }
.xray-filter-bar { display:inline-flex; gap:2px; padding:3px; background:var(--fill-subtle,#f3f4f6); border-radius:10px; flex-wrap:wrap; }
.filter-chip { display:inline-flex; align-items:center; gap:7px; padding:6px 13px; border-radius:8px; color:var(--text-secondary,#5c6b7a); text-decoration:none; font-size:12.5px; font-weight:600; transition:all .12s ease; }
.filter-chip i { font-size:11.5px; opacity:.75; }
.filter-chip:hover { color:var(--text-primary,#1a1a1a); background:rgba(255,255,255,.7); }
.filter-chip.active { background:#fff; color:var(--win-accent, #0078d4); box-shadow:0 1px 3px rgba(0,0,0,.12), 0 0 0 1px rgba(0,0,0,.03); }
.filter-chip .chip-count { min-width:20px; text-align:center; background:rgba(0,0,0,.06); color:inherit; padding:1px 7px; border-radius:9px; font-size:10.5px; font-weight:700; }
.filter-chip.active .chip-count { background:rgba(var(--win-accent-rgb, 0, 120, 212), .12); }
.btn-new-request { white-space:nowrap; }

/* Toolbar strip - .form-control is width:100% globally, restore auto */
.search-box { display:flex; gap:6px; flex-wrap:wrap; align-items:center; padding:9px 14px; margin:0; background:#fafbfc; border-bottom:1px solid var(--stroke-divider,#e8ebee); }
.search-box .form-control { width:auto; flex:0 0 auto; padding:0 10px; height:30px; font-size:12.5px; background:#fff; }
.search-wrap { position:relative; flex:1 1 210px; min-width:170px; display:inline-flex; }
.search-wrap i { position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#9aa5b1; font-size:12px; pointer-events:none; }
.search-wrap .search-input { width:100%; flex:1 1 auto; padding-right:30px; }
.type-filter { max-width:170px; }
.date-range { display:inline-flex; align-items:center; gap:4px; color:var(--text-secondary,#5c6b7a); font-size:12px; }
.search-box input[type="date"] { width:126px; font-family:inherit; }
.search-box .btn-sm { height:30px; padding:0 12px; display:inline-flex; align-items:center; gap:5px; }

/* Table - full-bleed, zebra, identity columns start-aligned */
.xray-card .table-container { border:0; margin:0; border-radius:0; }
.xray-table { width:100%; border-collapse:collapse; }
.xray-table th { padding:8px 10px; text-align:center; font-size:11px; font-weight:700; letter-spacing:.3px; color:#8895a3; white-space:nowrap; background:#fff; border-bottom:1px solid #e8ebee; }
.xray-table td { padding:7px 10px; font-size:12.5px; color:#2c3e50; text-align:center; vertical-align:middle; border-bottom:1px solid #f1f3f5; }
.xray-table tbody tr:nth-child(even) { background:#fbfcfd; }
.xray-table tbody tr:hover { background:#f0f7fd; }
.xray-table tbody tr:last-child td { border-bottom:0; }
.xray-table th:nth-child(2), .xray-table td:nth-child(2),
.xray-table th:nth-child(3), .xray-table td:nth-child(3) { text-align:start; }
.xray-table td:nth-child(5) { color:#6b7885; font-size:12px; }
.request-number-link { font-family:var(--app-font-family); font-weight:600; font-size:11.5px; color:#0269c8; background:rgba(2,105,200,.07); padding:2px 8px; border-radius:6px; text-decoration:none; white-space:nowrap; }
.request-number-link:hover { background:rgba(2,105,200,.15); }
.patient-name-cell { font-weight:600; color:#1f2c38; text-decoration:none; display:block; line-height:1.35; }
.patient-name-cell:hover { color:var(--win-accent, #0078d4); }
.patient-code { display:block; font-family:var(--app-font-family); font-size:10.5px; color:#98a2ad; direction:ltr; text-align:inherit; }
.doctor-name-cell { color:#546e7a; font-size:12px; }
.date-value { color:#8895a3; font-size:11.5px; white-space:nowrap; font-variant-numeric:tabular-nums; }
.amount-cell { font-weight:700; color:#0269c8; font-variant-numeric:tabular-nums; }
.xray-table .badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; padding:2px 9px; border-radius:999px; white-space:nowrap; }
.xray-table .badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; opacity:.7; }
.urgent-badge { background:#fde7e9; color:#c50f1f; border:1px solid #f5c4c9; padding:1px 7px; border-radius:999px; font-size:10px; font-weight:700; margin-right:4px; }

/* Row action buttons - quiet icon buttons */
.xray-table .btn-sm { width:26px; height:26px; min-width:26px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:11.5px; border-radius:6px; border:0; }
.xray-table .btn-sm.btn-primary { background:rgba(var(--win-accent-rgb, 0, 120, 212), .1); color:#0269c8; }
.xray-table .btn-sm.btn-primary:hover { background:var(--win-accent, #0078d4); color:#fff; }
.xray-table .btn-sm.btn-secondary { background:rgba(0,0,0,.05); color:#5c6b7a; }
.xray-table .btn-sm.btn-secondary:hover { background:rgba(0,0,0,.14); color:#2c3e50; }
.xray-table .btn-sm.btn-danger { background:rgba(197,15,31,.08); color:#c50f1f; }
.xray-table .btn-sm.btn-danger:hover { background:#c50f1f; color:#fff; }

/* Card foot: pagination + inline mini-stats */
.xray-card-foot { padding:9px 14px; border-top:1px solid var(--stroke-divider,#e8ebee); background:#fafbfc; }
.pagination-modern { display:flex; justify-content:center; align-items:center; gap:6px; margin:0 0 8px; }
.pagination-modern .page-btn { min-width:30px; height:30px; display:flex; align-items:center; justify-content:center; border-radius:8px; background:#fff; color:var(--text-primary,#1a1a1a); text-decoration:none; font-size:12.5px; font-weight:500; border:1px solid #e4e7ea; }
.pagination-modern .page-btn.active { background:var(--win-accent, #0078d4); color:#fff; border-color:transparent; }
.xray-summary { display:flex; flex-wrap:wrap; gap:4px 20px; align-items:baseline; margin:0; padding:0; background:none; border:0; }
.summary-item { display:inline-flex; align-items:baseline; gap:6px; background:none; border:0; padding:0; }
.summary-value { font-size:14px; font-weight:800; margin:0; }
.summary-value.total { color:var(--win-accent, #0078d4); }
.summary-value.pending { color:#c77700; }
.summary-value.progress { color:#1976d2; }
.summary-value.completed { color:#107c10; }
.summary-value.today { color:#8a3ab9; }
.summary-label { font-size:11px; color:#8895a3; font-weight:600; }
.xray-card .empty-state { padding:40px 20px; }

/* On-page notification banners */
.xray-alerts { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
.xray-alert { display:inline-flex; align-items:center; gap:8px; padding:7px 12px; border-radius:10px; font-size:12.5px; font-weight:600; text-decoration:none; border:1px solid transparent; }
.xray-alert .alert-count { min-width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center; border-radius:11px; background:rgba(255,255,255,.85); font-size:12px; font-weight:800; padding:0 6px; }
.xray-alert.warn { background:#fff4ce; color:#8a6300; border-color:#f2dfa0; }
.xray-alert.warn .alert-count { color:#8a6300; }
.xray-alert.danger { background:#fde7e9; color:#c50f1f; border-color:#f5c4c9; }
.xray-alert.danger .alert-count { color:#c50f1f; }
.xray-alert.info { background:#e5f1fb; color:var(--win-accent-dark, #005a9e); border-color:#c3ddf3; }
.xray-alert.info .alert-count { color:var(--win-accent-dark, #005a9e); }
.xray-alert:hover { filter:brightness(.97); }

/* Return-to-accountant modal (unchanged look, kept compact) */
.return-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.return-modal.active { display:flex; }
.return-box { background:#fff; width:100%; max-width:430px; border-radius:12px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.25); }
.return-header { background:#c50f1f; color:#fff; padding:15px; display:flex; justify-content:space-between; align-items:center; }
.return-header h3 { margin:0; font-size:16px; }
.return-body { padding:20px; }
.return-footer { padding:15px; background:#f9f9f9; border-top:1px solid #eee; display:flex; justify-content:flex-end; gap:10px; }
.alert-workflow { margin-bottom:15px; padding:12px 14px; border-radius:8px; font-size:13px; }
.alert-workflow.success { background:#dff6dd; color:#107c10; }

/* Mobile */
@media (max-width:767px) {
    .xray-alert { flex:1 1 100%; justify-content:space-between; }
    .xray-card-head { padding:8px 10px; }
    .xray-filter-bar { width:100%; }
    .filter-chip { flex:1 1 auto; justify-content:center; padding:6px 8px; }
    .btn-new-request { flex:1 1 100%; justify-content:center; }
    .search-box { display:grid; grid-template-columns:1fr 1fr; padding:8px 10px; }
    .search-wrap, .search-box .date-range { grid-column:1 / -1; }
    .search-box .form-control, .search-box .type-filter { width:100%; max-width:none; }
    .date-range input { flex:1; }
    .xray-card-foot { padding:8px 10px; }
}
</style>

<?php if (isset($_GET['returned'])): ?>
<div class="alert-workflow success"><i class="fas fa-check-circle"></i> داواکارییەکە گەڕێندرایەوە بۆ ژمێریار.</div>
<?php endif; ?>

<?php if ($urgentActiveCount > 0 || $pendingCount > 0 || $pendingAccountantCount > 0 || $returnedCount > 0): ?>
<div class="xray-alerts">
    <?php if ($urgentActiveCount > 0): ?>
    <a href="?priority=urgent" class="xray-alert danger">
        <i class="fas fa-triangle-exclamation"></i> داواکاری پەلەیی چالاک
        <span class="alert-count"><?= $urgentActiveCount ?></span>
    </a>
    <?php endif; ?>
    <?php if ($pendingCount > 0): ?>
    <a href="?status=pending_xray" class="xray-alert warn">
        <i class="fas fa-clock"></i> چاوەڕوانی ئێکسڕەی
        <span class="alert-count"><?= $pendingCount ?></span>
    </a>
    <?php endif; ?>
    <?php if ($pendingAccountantCount > 0): ?>
    <a href="<?= ($isAccountant || $isAdmin) ? APP_URL . '/accountant/approval-center.php?tab=xray' : '?status=pending_accountant' ?>" class="xray-alert info">
        <i class="fas fa-cash-register"></i> چاوەڕوانی پارەدان لای ژمێریار
        <span class="alert-count"><?= $pendingAccountantCount ?></span>
    </a>
    <?php endif; ?>
    <?php if ($returnedCount > 0): ?>
    <a href="?status=returned_to_accountant" class="xray-alert danger">
        <i class="fas fa-undo"></i> گەڕاوەتەوە بۆ ژمێریار
        <span class="alert-count"><?= $returnedCount ?></span>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel xray-card">
    <div class="xray-card-head">
        <div class="xray-filter-bar">
                <a href="?status=" class="filter-chip <?= $status === '' ? 'active' : '' ?>">
                    <i class="fas fa-layer-group"></i> هەموو
                    <span class="chip-count"><?= number_format($stats['total'] ?? 0) ?></span>
                </a>
                <a href="?status=pending_xray" class="filter-chip <?= $status === 'pending_xray' ? 'active' : '' ?>">
                    <i class="fas fa-clock"></i> چاوەڕوانی ئێکسڕەی
                    <span class="chip-count"><?= $pendingCount ?></span>
                </a>
                <a href="?status=in_xray" class="filter-chip <?= in_array($status, ['in_xray', 'in_progress'], true) ? 'active' : '' ?>">
                    <i class="fas fa-spinner"></i> لە ئێکسڕەی
                    <span class="chip-count"><?= $inProgressCount ?></span>
                </a>
                <a href="?status=completed" class="filter-chip <?= $status === 'completed' ? 'active' : '' ?>">
                    <i class="fas fa-check-circle"></i> تەواوبوو
                    <span class="chip-count"><?= $completedCount ?></span>
                </a>
                <a href="?status=cancelled" class="filter-chip <?= $status === 'cancelled' ? 'active' : '' ?>">
                    <i class="fas fa-ban"></i> هەڵوەشاوە
                    <span class="chip-count"><?= $cancelledCount ?></span>
                </a>
            </div>
        <?php if ($auth->canRequestXray()): ?>
        <a href="<?= APP_URL ?>/xray/request.php" class="btn btn-primary btn-sm btn-new-request"><i class="fas fa-plus"></i> داواکاری نوێ</a>
        <?php endif; ?>
    </div>
        <form method="GET" class="search-box">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <span class="search-wrap"><i class="fas fa-search"></i><input type="text" name="search" class="form-control search-input" placeholder="ناوی نەخۆش یان ژمارەی داواکاری..." value="<?= htmlspecialchars($search) ?>"></span>
            <select name="request_status" class="form-control type-filter" onchange="this.form.submit()">
                <option value="">دۆخ: هەموو</option>
                <?php foreach ($validRequestStatuses as $value): ?>
                <option value="<?= $value ?>" <?= $reqStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($requestStatusLabels[$value] ?? $value) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="payment_status" class="form-control type-filter" onchange="this.form.submit()">
                <option value="">پارەدان: هەموو</option>
                <?php foreach ($validPaymentStatuses as $value): ?>
                <option value="<?= $value ?>" <?= $payStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($paymentStatusLabels[$value] ?? $value) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="form-control type-filter" onchange="this.form.submit()">
                <option value="">پەلە: هەموو</option>
                <option value="normal" <?= $priority === 'normal' ? 'selected' : '' ?>>ئاسایی</option>
                <option value="urgent" <?= $priority === 'urgent' ? 'selected' : '' ?>>پەلەیی</option>
            </select>
            <select name="xray_type" class="form-control type-filter" onchange="this.form.submit()">
                <option value="">جۆر: هەموو</option>
                <?php foreach ($XRAY_TYPES as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $xrayType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="date-range">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>" onchange="this.form.submit()" title="لە بەرواری">
                <span>—</span>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>" onchange="this.form.submit()" title="بۆ بەرواری">
            </span>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> گەڕان</button>
            <?php if ($search !== '' || $reqStatus !== '' || $payStatus !== '' || $priority !== '' || $xrayType !== '' || $dateFrom !== '' || $dateTo !== '' || $status !== ''): ?>
            <a href="<?= APP_URL ?>/xray/index.php" class="btn btn-secondary btn-sm" title="پاککردنەوەی فلتەرەکان"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>

        <?php if (empty($requests)): ?>
        <div class="empty-state">
            <i class="fas fa-x-ray empty-state-icon"></i>
            <p class="empty-state-title">هیچ داواکارییەکی ئێکسڕەی نەدۆزرایەوە</p>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table class="xray-table">
                <thead>
                    <tr>
                        <th>داواکاری</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>جۆر</th>
                        <th>هۆکار</th>
                        <th>بڕی پارە</th>
                        <th>پارەدان</th>
                        <th>دۆخ</th>
                        <th>بەروار</th>
                        <th>کردار</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $request): ?>
                <?php
                $displayStatus = $request['request_status'] ?: $request['status'];
                $statusClass = $statusBadgeClass[$displayStatus] ?? 'badge-blue';
                $paymentClass = $paymentBadgeClass[$request['payment_status'] ?? ''] ?? 'badge-blue';
                ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/xray/view.php?id=<?= (int)$request['id'] ?>" class="request-number-link">
                            <?= htmlspecialchars($request['request_number']) ?>
                        </a>
                        <?php if (($request['priority'] ?? '') === 'urgent'): ?>
                        <span class="urgent-badge">پەلەیی</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$request['patient_id'] ?>" class="patient-name-cell">
                            <?= htmlspecialchars($request['patient_name']) ?>
                        </a>
                        <small class="patient-code"><?= htmlspecialchars($request['patient_code'] ?? '') ?></small>
                    </td>
                    <td class="doctor-name-cell">
                        Dr. <?= htmlspecialchars($request['requested_by_name'] ?? '') ?>
                        <?php if (!empty($request['external_doctor_name'])): ?>
                        <span title="دکتۆری دەرەوە" style="display:inline-block;margin-inline-start:4px;padding:1px 7px;border-radius:999px;background:#fff3e0;color:#b25e09;border:1px solid #ffd9a8;font-size:10px;font-weight:700;">دەرەوە</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-blue"><?= htmlspecialchars($XRAY_TYPES[$request['xray_type']] ?? $request['xray_type']) ?></span></td>
                    <td><?= htmlspecialchars($XRAY_REASONS[$request['xray_reason']] ?? $request['xray_reason']) ?></td>
                    <td class="amount-cell"><?= number_format((float)$request['amount']) ?></td>
                    <td><span class="badge <?= $paymentClass ?>"><?= htmlspecialchars($paymentStatusLabels[$request['payment_status']] ?? ($request['payment_status'] ?? '')) ?></span></td>
                    <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($requestStatusLabels[$displayStatus] ?? $displayStatus) ?></span></td>
                    <td class="date-value"><?= date('Y-m-d', strtotime($request['created_at'])) ?></td>
                    <td>
                        <div class="d-flex gap-1 justify-center">
                            <a href="<?= APP_URL ?>/xray/view.php?id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-primary" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php // A free request has no accountant stage to go back to — XrayManager::returnToAccounting() refuses it too. ?>
                            <?php if (($isXrayDept || $isAdmin)
                                && in_array($displayStatus, ['pending_xray', 'in_xray', 'in_progress'], true)
                                && ($request['payment_type'] ?? '') !== 'dont_cash'
                                && ($request['payment_status'] ?? '') !== 'not_required'): ?>
                            <button type="button" class="btn btn-sm btn-danger" onclick="openReturnModal(<?= (int)$request['id'] ?>)" title="ناردن بۆ ژمێریار">
                                <i class="fas fa-undo"></i>
                            </button>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/xray/receipt.php?id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-secondary" title="Print" target="_blank">
                                <i class="fas fa-print"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="xray-card-foot">
        <?php if ($totalPages > 1 && $usePagination): ?>
        <div class="pagination-modern">
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?= $i ?>&status=<?= urlencode($status) ?>&request_status=<?= urlencode($reqStatus) ?>&payment_status=<?= urlencode($payStatus) ?>&priority=<?= urlencode($priority) ?>&xray_type=<?= urlencode($xrayType) ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <div class="xray-summary">
            <div class="summary-item"><div class="summary-value total"><?= number_format($stats['total'] ?? 0) ?></div><div class="summary-label">هەموو</div></div>
            <div class="summary-item"><div class="summary-value pending"><?= $pendingCount ?></div><div class="summary-label">چاوەڕوان</div></div>
            <div class="summary-item"><div class="summary-value progress"><?= $inProgressCount ?></div><div class="summary-label">لە ئێکسڕەی</div></div>
            <div class="summary-item"><div class="summary-value completed"><?= $completedCount ?></div><div class="summary-label">تەواوبوو</div></div>
            <div class="summary-item"><div class="summary-value today"><?= $stats['today'] ?? 0 ?></div><div class="summary-label">ئەمڕۆ</div></div>
            <div class="summary-item"><div class="summary-value" style="color:var(--win-accent);"><?= number_format($stats['total_amount'] ?? 0) ?> <small></small></div><div class="summary-label">کۆی پارە</div></div>
        </div>
        </div><!-- /.xray-card-foot -->
        <?php endif; ?>
</div>

<div id="returnToAccountingModal" class="return-modal">
    <div class="return-box">
        <div class="return-header">
            <h3><i class="fas fa-undo"></i> ناردن بۆ ژمێریار</h3>
            <button type="button" onclick="closeReturnModal()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:16px;">&times;</button>
        </div>
        <form method="POST" onsubmit="return confirm('دڵنیایت دەتەوێت ئەم داواکارییە بنێریتەوە بۆ ژمێریار؟')">
            <input type="hidden" name="action" value="return_to_accountant">
            <input type="hidden" name="request_id" id="return_modal_request_id">
            <div class="return-body">
                <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px;">هۆکاری گەڕاندنەوە</label>
                <textarea name="return_reason" required rows="4" class="form-control" placeholder="ڕاستکردنەوەی پارەدان، گەڕاندنەوەی پارە، پارەدان کەمە، بڕی پارە هەڵەیە، هەڵوەشاندنەوە دوای پارەدان..."></textarea>
            </div>
            <div class="return-footer">
                <button type="button" onclick="closeReturnModal()" class="btn btn-secondary btn-sm">پاشگەزبوونەوە</button>
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-undo"></i> ناردن بۆ ژمێریار</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReturnModal(id) {
    document.getElementById('return_modal_request_id').value = id;
    document.getElementById('returnToAccountingModal').classList.add('active');
}
function closeReturnModal() {
    document.getElementById('returnToAccountingModal').classList.remove('active');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
