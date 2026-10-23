<?php
/**
 * Accountant pending reports and X-Ray accounting queue.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$isAccountant = $auth->isAccountant();
$isAdmin = $auth->isAdmin();

if (!$isAccountant && !$isAdmin) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$xrayManager = new XrayManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accountant_action'])) {
    Csrf::require();
    $xrayId = filter_input(INPUT_POST, 'xray_id', FILTER_VALIDATE_INT) ?: 0;
    $action = $_POST['accountant_action'] ?? '';
    $note = trim($_POST['action_note'] ?? '');
    $role = $_SESSION['role'] ?? ROLE_ACCOUNTANT;
    $result = ['success' => false, 'message' => 'Invalid request'];

    $request = $xrayId > 0
        ? $db->fetch("SELECT id, request_number, request_status, payment_type, amount, original_amount, discount_amount, final_amount FROM xray_requests WHERE id = :id", ['id' => $xrayId])
        : null;

    if ($request && in_array($request['request_status'], ['pending_accountant', 'returned_to_accountant'], true)) {
        $allowedModalActions = ['approve_payment', 'approve_debt'];
        if (!in_array($action, $allowedModalActions, true)) {
            $result = ['success' => false, 'message' => 'Invalid accountant action'];
            $adjustmentFailed = true;
        } else {
            $adjustmentFailed = false;
        }
        $settlementActions = ['approve_payment', 'approve_debt'];
        $canAdjustBeforeAction = in_array($action, $settlementActions, true);

        if (!$adjustmentFailed && $canAdjustBeforeAction) {
            $newAmountRaw = trim($_POST['new_amount'] ?? '');
            if ($newAmountRaw !== '') {
                $newAmount = filter_var($newAmountRaw, FILTER_VALIDATE_FLOAT);
                if ($newAmount === false || $newAmount < 0) {
                    $result = ['success' => false, 'message' => 'Invalid amount'];
                    $adjustmentFailed = true;
                } elseif (abs($newAmount - (float)$request['amount']) > 0.0001) {
                    $result = $xrayManager->correctAmount($xrayId, $newAmount, $currentUser['id'], $role, $note);
                    $adjustmentFailed = empty($result['success']);
                }
            }

            $discountRaw = trim($_POST['discount_amount'] ?? '');
            if (!$adjustmentFailed && $discountRaw !== '') {
                $discountAmount = filter_var($discountRaw, FILTER_VALIDATE_FLOAT);
                if ($discountAmount === false || $discountAmount < 0) {
                    $result = ['success' => false, 'message' => 'Invalid discount'];
                    $adjustmentFailed = true;
                } elseif ($discountAmount > 0) {
                    $discountReason = trim($_POST['discount_reason'] ?? '');
                    $result = $xrayManager->applyDiscount($xrayId, $discountAmount, $discountReason, $currentUser['id']);
                    $adjustmentFailed = empty($result['success']);
                }
            }
        }

        if (!$adjustmentFailed) {
            if ($action === 'approve_payment') {
                $result = $xrayManager->approvePaymentAndSendToXray($xrayId, $currentUser['id'], $role, $note);
            } elseif ($action === 'approve_debt') {
                $result = $xrayManager->approveDebtAndSendToXray($xrayId, $currentUser['id'], $role, $note);
            }
        }
    }

    // Stay in the queue after approving so the accountant can process the next
    // request immediately (payment history stays one click away per row)
    header('Location: ' . APP_URL . '/accountant/pending-reports.php?' . (!empty($result['success']) ? 'success=1' : 'error=1') . '&tab=xray');
    exit;
}

$pageTitle = 'ڕاپۆرتە چاوەڕوانەکان';
$breadcrumb = [
    ['title' => 'داشبۆردی ژمێریار', 'url' => APP_URL . '/accountant/dashboard.php'],
    ['title' => 'ڕاپۆرتە چاوەڕوانەکان']
];

require_once __DIR__ . '/../includes/header.php';

// GET params can arrive as arrays (?search[]=) — coerce to strings so trim()
// and htmlspecialchars() don't fatal on PHP 8
$getStr = function ($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? $v : $default;
};
$validDate = function ($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
};

$status = $getStr('status', 'pending');
$search = trim($getStr('search'));
// No default date window: the sidebar badge counts ALL pending reports, so the
// default list must show them all too — a month-to-date default silently hid
// older pending reports while the badge kept counting them.
$dateFrom = $getStr('date_from');
$dateTo = $getStr('date_to');
if ($dateFrom !== '' && !$validDate($dateFrom)) { $dateFrom = ''; }
if ($dateTo !== '' && !$validDate($dateTo)) { $dateTo = ''; }
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$validStatuses = ['pending', 'reviewed', 'processed', 'archived', ''];
if (!in_array($status, $validStatuses, true)) {
    $status = 'pending';
}

$where = "1=1";
$params = [];
if ($status !== '') {
    $where .= " AND ar.status = :status";
    $params['status'] = $status;
} else {
    // "All" means all ACTIVE reports — archived (hidden) ones only show
    // under their own tab, never in the active lists.
    $where .= " AND ar.status <> 'archived'";
}
if ($search !== '') {
    // Distinct placeholder names: PDO with emulation off rejects re-using one
    // named placeholder ("Invalid parameter number" — fatal on every search)
    $where .= " AND (p.full_name LIKE :search1 OR c.case_number LIKE :search2 OR u.full_name LIKE :search3)";
    $params['search1'] = $params['search2'] = $params['search3'] = "%{$search}%";
}
if ($dateFrom) {
    $where .= " AND DATE(ar.sent_at) >= :date_from";
    $params['date_from'] = $dateFrom;
}
if ($dateTo) {
    $where .= " AND DATE(ar.sent_at) <= :date_to";
    $params['date_to'] = $dateTo;
}

$baseQuery = "
    FROM accounting_reports ar
    JOIN cases c ON ar.case_id = c.id
    JOIN patient_folders pf ON c.folder_id = pf.id
    JOIN patients p ON pf.patient_id = p.id
    JOIN users u ON ar.sent_by = u.id
    LEFT JOIN users pu ON ar.processed_by = pu.id
    WHERE {$where}
";

$totalCount = $db->fetch("SELECT COUNT(*) as cnt {$baseQuery}", $params)['cnt'] ?? 0;
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$reports = $db->fetchAll("
    SELECT ar.*, c.case_number, c.total_amount, c.paid_amount, c.discount_amount,
           c.status as case_status, p.full_name AS patient_name, p.phone AS patient_phone,
           u.full_name AS doctor_name, pu.full_name AS processor_name
    {$baseQuery}
    ORDER BY ar.sent_at DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);

// Doctor payment requests awaiting the accountant (payments.status='pending').
// Every payment a doctor records is a REQUEST — it only becomes real money once
// the accountant confirms it here. This is the payment's own section.
$pendingPaymentRequests = $db->fetchAll("
    SELECT pay.id, pay.payment_number, pay.amount, pay.payment_type, pay.payment_method,
           pay.notes, pay.payment_date, pay.case_id,
           c.case_number, c.total_amount, c.paid_amount, c.discount_amount,
           p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
           doc.full_name AS doctor_name
    FROM payments pay
    JOIN cases c ON pay.case_id = c.id
    JOIN patient_folders pf ON c.folder_id = pf.id
    JOIN patients p ON pf.patient_id = p.id
    LEFT JOIN users doc ON pay.received_by = doc.id
    WHERE pay.status = 'pending'
    ORDER BY pay.payment_date DESC
");
$pendingPaymentTotal = 0.0;
foreach ($pendingPaymentRequests as $pr) { $pendingPaymentTotal += (float)$pr['amount']; }

$xraySearch = trim($getStr('xray_search'));
$xrayPaymentType = $getStr('xray_payment_type');
$xrayPaymentStatus = $getStr('xray_payment_status');
$xrayRequestStatus = $getStr('xray_request_status');
$xrayDateFrom = $getStr('xray_date_from');
$xrayDateTo = $getStr('xray_date_to');
if ($xrayDateFrom !== '' && !$validDate($xrayDateFrom)) { $xrayDateFrom = ''; }
if ($xrayDateTo !== '' && !$validDate($xrayDateTo)) { $xrayDateTo = ''; }

$xrayWhere = "xr.request_status IN ('pending_accountant', 'returned_to_accountant')";
$xrayParams = [];
if ($xraySearch !== '') {
    $xrayWhere .= " AND (p.full_name LIKE :xray_search1 OR xr.request_number LIKE :xray_search2 OR CAST(xr.id AS CHAR) LIKE :xray_search3)";
    $xrayParams['xray_search1'] = $xrayParams['xray_search2'] = $xrayParams['xray_search3'] = "%{$xraySearch}%";
}
if (in_array($xrayPaymentType, ['cash', 'dont_cash', 'debt'], true)) {
    $xrayWhere .= " AND xr.payment_type = :payment_type";
    $xrayParams['payment_type'] = $xrayPaymentType;
}
if (in_array($xrayPaymentStatus, ['pending_payment', 'paid', 'not_required', 'debt_pending', 'debt_approved', 'refunded'], true)) {
    $xrayWhere .= " AND xr.payment_status = :payment_status";
    $xrayParams['payment_status'] = $xrayPaymentStatus;
}
if (in_array($xrayRequestStatus, ['pending_accountant', 'returned_to_accountant'], true)) {
    $xrayWhere .= " AND xr.request_status = :request_status";
    $xrayParams['request_status'] = $xrayRequestStatus;
}
if ($xrayDateFrom !== '') {
    $xrayWhere .= " AND DATE(xr.created_at) >= :xray_date_from";
    $xrayParams['xray_date_from'] = $xrayDateFrom;
}
if ($xrayDateTo !== '') {
    $xrayWhere .= " AND DATE(xr.created_at) <= :xray_date_to";
    $xrayParams['xray_date_to'] = $xrayDateTo;
}

$pendingXrayRequests = $db->fetchAll("
    SELECT xr.*, p.full_name AS patient_name, p.patient_code, u.full_name AS doctor_name
    FROM xray_requests xr
    JOIN patients p ON xr.patient_id = p.id
    LEFT JOIN users u ON xr.requested_by = u.id
    WHERE {$xrayWhere}
    ORDER BY xr.created_at DESC
", $xrayParams);

// One GROUP BY pass instead of separate COUNT queries
$statusCounts = ['pending' => 0, 'reviewed' => 0, 'processed' => 0, 'archived' => 0];
foreach ($db->fetchAll("SELECT status, COUNT(*) as cnt FROM accounting_reports GROUP BY status") as $sc) {
    if (isset($statusCounts[$sc['status']])) { $statusCounts[$sc['status']] = (int)$sc['cnt']; }
}
$pendingCount = $statusCounts['pending'];
$reviewedCount = $statusCounts['reviewed'];
$processedCount = $statusCounts['processed'];
$archivedCount = $statusCounts['archived'];

$statusLabels = ['pending' => 'چاوەڕوان', 'reviewed' => 'بینراوە', 'processed' => 'جێبەجێکراوە', 'archived' => 'لابراو'];
$statusColors = ['pending' => '#ff8c00', 'reviewed' => '#0078d4', 'processed' => '#107c10', 'archived' => '#888'];
$statusBg = ['pending' => '#fff4ce', 'reviewed' => '#deecf9', 'processed' => '#dff6dd', 'archived' => '#f0f0f0'];

$requestStatusLabels = [
    'pending_accountant' => 'چاوەڕوانی ژمێریار',
    'returned_to_accountant' => 'گەڕاوەتەوە بۆ ژمێریار',
    'pending_xray' => 'چاوەڕوانی ئێکسڕەی',
    'in_xray' => 'لە ئێکسڕەی',
    'completed' => 'تەواوبوو',
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
$paymentTypeLabels = ['cash' => 'کاش', 'dont_cash' => 'کاش نییە', 'debt' => 'قەرز'];
?>

<style>
/* ═══ Modern-minimal accountant list — few lines, soft, easy on the eyes ═══ */
.report-container { max-width:none; margin:0; font-size:13px; color:#2b3440; }
.report-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px; }
.report-title { display:flex; align-items:center; gap:10px; }
.report-title h2 { margin:0; font-size:17px; color:#1f2937; font-weight:700; letter-spacing:-0.2px; }
.report-title .icon { width:34px; height:34px; background:#eaf2fd; border-radius:9px; display:flex; align-items:center; justify-content:center; color:#0078d4; font-size:15px; }
/* Filters — soft rounded, borderless */
.filters-card { background:#fff; border:1px solid #eef0f3; border-radius:10px; padding:10px 12px; margin-bottom:12px; box-shadow:0 1px 2px rgba(16,24,40,0.04); }
.filters-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-group label { font-size:10.5px; color:#98a2b3; font-weight:600; }
.filter-group input, .filter-group select { padding:7px 11px; border:1px solid #e4e7ec; border-radius:8px; font-size:12.5px; font-family:inherit; min-width:140px; background:#fcfcfd; color:#333; transition:border-color .15s; }
.filter-group input:focus, .filter-group select:focus { border-color:#0078d4; outline:none; background:#fff; }
.btn-filter { padding:7px 15px; background:#0078d4; color:white; border:none; border-radius:8px; cursor:pointer; font-size:12.5px; font-family:inherit; display:flex; align-items:center; gap:6px; text-decoration:none; }
.btn-reset { padding:7px 15px; background:#fff; color:#667085; border:1px solid #e4e7ec; border-radius:8px; cursor:pointer; font-size:12.5px; font-family:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; }
/* Tabs — soft rounded pills */
.tab-group { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
.tab-btn { padding:7px 15px; border:none; border-radius:9px; font-size:12.5px; font-weight:600; cursor:pointer; text-decoration:none; color:#667085; background:#f3f5f8; transition:all .15s; display:inline-flex; align-items:center; gap:6px; }
.tab-btn:hover { background:#e8ecf2; color:#344054; }
.tab-btn.active { background:#0078d4; color:white; box-shadow:0 2px 6px rgba(0,120,212,0.25); }
.tab-count { background:rgba(0,0,0,0.08); border-radius:20px; padding:0 7px; font-size:11px; }
.tab-btn.active .tab-count { background:rgba(255,255,255,0.28); }
/* Cards — soft rounded, subtle shadow (no hard frames) */
.table-card { background:#fff; border:1px solid #eef0f3; border-radius:12px; overflow:hidden; margin-bottom:14px; box-shadow:0 1px 3px rgba(16,24,40,0.05); }
.table-header { padding:12px 16px; border-bottom:1px solid #f0f2f5; display:flex; justify-content:space-between; align-items:center; background:#fff; }
.table-header h3 { margin:0; font-size:14px; color:#1f2937; display:flex; align-items:center; gap:8px; font-weight:700; }
.table-count { background:#f2f4f7; color:#667085; padding:3px 11px; border-radius:20px; font-size:11.5px; font-weight:600; }
/* Modern table — horizontal separators only, no vertical grid, no zebra */
.report-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.report-table th { background:#fff; padding:9px 14px; text-align:right; font-weight:600; color:#98a2b3; border-bottom:1px solid #eef0f3; white-space:nowrap; font-size:11.5px; }
.report-table td { padding:10px 14px; border-bottom:1px solid #f4f5f7; vertical-align:middle; color:#344054; }
.report-table tbody tr:last-child td { border-bottom:none; }
.report-table tr:hover td { background:#f9fbfe; }
.status-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap; background:#eff4ff; color:#175cd3; }
.badge-paid { background:#ecfdf3; color:#067647; }
.badge-warn { background:#fffaeb; color:#b54708; }
.badge-red { background:#fef3f2; color:#b42318; }
.btn-icon { min-width:30px; height:30px; padding:0 10px; display:inline-flex; align-items:center; justify-content:center; gap:5px; border-radius:8px; border:none; cursor:pointer; transition:filter .15s; text-decoration:none; font-size:12px; }
.btn-icon:hover { filter:brightness(0.95); }
.btn-view { background:#eaf2fd; color:#0078d4; }
.btn-manage { background:#067647; color:white; }
.no-data { text-align:center; padding:34px 20px; color:#b6bdc9; font-size:13px; }
.pagination-wrapper { padding:10px 16px; border-top:1px solid #f0f2f5; display:flex; justify-content:space-between; align-items:center; background:#fff; }
.pagination { display:flex; gap:5px; flex-wrap:wrap; }
.pagination a, .pagination span { padding:5px 11px; border-radius:8px; font-size:12.5px; text-decoration:none; font-weight:600; }
.pagination a { background:#fff; color:#0078d4; border:1px solid #e4e7ec; }
.pagination .active { background:#0078d4; color:white; border:1px solid #0078d4; }
.amount-neg { color:#b42318; font-weight:700; direction:ltr; unicode-bidi:embed; }
.amount-pos { color:#067647; font-weight:700; direction:ltr; unicode-bidi:embed; }
.acc-modal-overlay { display:none; position:fixed; inset:0; background:rgba(16,24,40,0.45); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.acc-modal-overlay.active { display:flex; }
.acc-modal-box { width:100%; max-width:500px; background:white; border-radius:14px; overflow:hidden; box-shadow:0 16px 48px rgba(16,24,40,0.24); }
.acc-modal-header { padding:14px 16px; background:#fff; border-bottom:1px solid #f0f2f5; display:flex; justify-content:space-between; align-items:center; }
.acc-modal-header h3 { margin:0; font-size:15px; color:#1f2937; font-weight:700; }
.acc-modal-close { background:none; border:none; cursor:pointer; font-size:16px; color:#98a2b3; }
.acc-modal-body { padding:14px 16px; }
.acc-form-group { margin-bottom:12px; }
.acc-form-group label { display:block; font-size:12px; font-weight:600; color:#475467; margin-bottom:5px; }
.acc-form-group input, .acc-form-group select, .acc-form-group textarea { width:100%; padding:8px 11px; border:1px solid #e4e7ec; border-radius:8px; font-family:inherit; font-size:12.5px; box-sizing:border-box; background:#fcfcfd; }
.acc-modal-footer { padding:12px 16px; background:#fcfcfd; border-top:1px solid #f0f2f5; display:flex; justify-content:flex-end; gap:8px; }
.acc-btn { border:none; border-radius:8px; padding:8px 16px; cursor:pointer; font-family:inherit; font-weight:600; font-size:12.5px; }
.acc-btn-primary { background:#0078d4; color:white; }
.acc-btn-secondary { background:#f2f4f7; color:#475467; }
.alert { margin-bottom:12px; padding:10px 14px; border-radius:10px; font-size:12.5px; }
.alert-success { background:#ecfdf3; color:#067647; }
.alert-error { background:#fef3f2; color:#b42318; }
</style>

<div class="report-container">
    <div class="report-header">
        <div class="report-title">
            <div class="icon"><i class="fas fa-file-invoice"></i></div>
            <div>
                <h2>ڕاپۆرتە چاوەڕوانەکانی ژمێریار</h2>
                <div style="font-size:12px;color:#888;margin-top:2px;">ڕاپۆرتەکانی ژمێریاری و پێداچوونەوەی پارەدانی ئێکسڕەی</div>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> کردارەکە بە سەرکەوتوویی جێبەجێکرا.</div>
    <?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> کردارەکە جێبەجێ نەکرا.</div>
    <?php elseif (isset($_GET['xray_created'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> داواکاری ئێکسڕەی نێردرا بۆ ژمێریار.</div>
    <?php endif; ?>

    <div class="tab-group">
        <a href="?status=pending&date_from=<?= htmlspecialchars($dateFrom) ?>&date_to=<?= htmlspecialchars($dateTo) ?>" class="tab-btn <?= $status === 'pending' ? 'active' : '' ?>">
            <span class="tab-count"><?= $pendingCount ?></span> چاوەڕوان
        </a>
        <a href="?status=reviewed&date_from=<?= htmlspecialchars($dateFrom) ?>&date_to=<?= htmlspecialchars($dateTo) ?>" class="tab-btn <?= $status === 'reviewed' ? 'active' : '' ?>">
            <span class="tab-count"><?= $reviewedCount ?></span> بینراوە
        </a>
        <a href="?status=processed&date_from=<?= htmlspecialchars($dateFrom) ?>&date_to=<?= htmlspecialchars($dateTo) ?>" class="tab-btn <?= $status === 'processed' ? 'active' : '' ?>">
            <span class="tab-count"><?= $processedCount ?></span> جێبەجێکراوە
        </a>
        <a href="?status=&date_from=<?= htmlspecialchars($dateFrom) ?>&date_to=<?= htmlspecialchars($dateTo) ?>" class="tab-btn <?= $status === '' ? 'active' : '' ?>">هەموو</a>
        <a href="?status=archived&date_from=<?= htmlspecialchars($dateFrom) ?>&date_to=<?= htmlspecialchars($dateTo) ?>" class="tab-btn <?= $status === 'archived' ? 'active' : '' ?>" title="ڕاپۆرتە لابراوەکان">
            <span class="tab-count"><?= $archivedCount ?></span> <i class="fas fa-eye-slash"></i> لابراوەکان
        </a>
    </div>

    <div class="filters-card">
        <form method="GET" class="filters-row">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <!-- keep the X-ray queue filters when re-filtering the reports list -->
            <?php foreach (['xray_search' => $xraySearch, 'xray_payment_type' => $xrayPaymentType, 'xray_payment_status' => $xrayPaymentStatus, 'xray_request_status' => $xrayRequestStatus, 'xray_date_from' => $xrayDateFrom, 'xray_date_to' => $xrayDateTo] as $hk => $hv): if ($hv !== ''): ?>
            <input type="hidden" name="<?= $hk ?>" value="<?= htmlspecialchars($hv) ?>">
            <?php endif; endforeach; ?>
            <div class="filter-group" style="flex:1;min-width:200px;">
                <label>گەڕانی ڕاپۆرت</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="نەخۆش، کەیس، دکتۆر">
            </div>
            <div class="filter-group">
                <label>لە بەروار</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="filter-group">
                <label>بۆ بەروار</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-search"></i> گەڕان</button>
            <a href="?status=<?= htmlspecialchars($status) ?>" class="btn-reset"><i class="fas fa-times"></i> پاککردنەوە</a>
        </form>
    </div>

    <!-- Doctor payment requests — their own dedicated queue -->
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-hand-holding-usd" style="color:#067647;"></i> داواکارییەکانی پارەدان لە دکتۆرەکان</h3>
            <span class="table-count" style="background:#ecfdf3;color:#067647;">
                <?= count($pendingPaymentRequests) ?> داواکاری · <?= number_format($pendingPaymentTotal) ?>
            </span>
        </div>
        <?php if (empty($pendingPaymentRequests)): ?>
        <div class="no-data"><i class="fas fa-hand-holding-usd"></i><p>هیچ داواکارییەکی پارەدان لە دکتۆرەکانەوە نییە کە چاوەڕوانی پەسەندکردن بێت.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>ژمارەی پارەدان</th>
                        <th>نەخۆش</th>
                        <th>کەیس</th>
                        <th>دکتۆر</th>
                        <th style="text-align:center;">بڕی داواکراو</th>
                        <th style="text-align:center;">ماوەی کەیس</th>
                        <th>تێبینی</th>
                        <th style="text-align:center;">بەروار</th>
                        <th style="text-align:center;">کردار</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingPaymentRequests as $pr):
                    $prRemaining = (float)$pr['total_amount'] - (float)$pr['paid_amount'] - (float)$pr['discount_amount'];
                    $prTypeLabels = ['cash' => 'نەقد', 'credit' => 'قەرز', 'installment' => 'قیست'];
                ?>
                <tr>
                    <td style="font-family:var(--app-font-family);font-weight:700;"><?= htmlspecialchars($pr['payment_number']) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($pr['patient_name']) ?></strong>
                        <br><small style="color:#999;"><?= htmlspecialchars($pr['patient_phone'] ?? '') ?></small>
                    </td>
                    <td style="font-family:var(--app-font-family);color:#666;"><?= htmlspecialchars($pr['case_number']) ?></td>
                    <td><?= htmlspecialchars($pr['doctor_name'] ?? '') ?></td>
                    <td style="text-align:center;font-weight:700;color:#107c10;">
                        <?= number_format((float)$pr['amount']) ?>
                        <br><small style="color:#888;"><?= htmlspecialchars($prTypeLabels[$pr['payment_type']] ?? $pr['payment_type']) ?></small>
                    </td>
                    <td style="text-align:center;" class="<?= $prRemaining > 0 ? 'amount-neg' : 'amount-pos' ?>"><?= number_format($prRemaining) ?></td>
                    <td style="max-width:200px;color:#666;font-size:12px;"><?= htmlspecialchars(mb_strimwidth($pr['notes'] ?? '', 0, 60, '…')) ?></td>
                    <td style="text-align:center;color:#888;"><?= date('Y-m-d H:i', strtotime($pr['payment_date'])) ?></td>
                    <td style="text-align:center;white-space:nowrap;">
                        <a href="<?= APP_URL ?>/accountant/report-detail.php?case_id=<?= (int)$pr['case_id'] ?>" class="btn-icon btn-manage">
                            <i class="fas fa-cog"></i> بەڕێوەبردن
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-x-ray" style="color:#d83b01;"></i> داواکارییەکانی ئێکسڕەی بۆ ژمێریار</h3>
            <span class="table-count" style="background:#fff4ed;color:#d83b01;"><?= count($pendingXrayRequests) ?> داواکاری</span>
        </div>
        <div class="filters-card" style="border:none;border-bottom:1px solid #f0f2f5;margin:0;border-radius:0;box-shadow:none;background:#fcfcfd;">
            <form method="GET" class="filters-row">
                <input type="hidden" name="tab" value="xray">
                <!-- keep the reports-list filters when re-filtering the X-ray queue -->
                <?php foreach (['status' => $status, 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo] as $hk => $hv): if ($hv !== ''): ?>
                <input type="hidden" name="<?= $hk ?>" value="<?= htmlspecialchars($hv) ?>">
                <?php endif; endforeach; ?>
                <div class="filter-group" style="flex:1;min-width:210px;">
                    <label>گەڕانی ئێکسڕەی</label>
                    <input type="text" name="xray_search" value="<?= htmlspecialchars($xraySearch) ?>" placeholder="ناوی نەخۆش یان ژمارەی داواکاری">
                </div>
                <div class="filter-group">
                    <label>جۆری پارەدان</label>
                    <select name="xray_payment_type">
                        <option value="">هەموو</option>
                        <?php foreach ($paymentTypeLabels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $xrayPaymentType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>دۆخی پارەدان</label>
                    <select name="xray_payment_status">
                        <option value="">هەموو</option>
                        <?php foreach ($paymentStatusLabels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $xrayPaymentStatus === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>دۆخی داواکاری</label>
                    <select name="xray_request_status">
                        <option value="">هەموو</option>
                        <option value="pending_accountant" <?= $xrayRequestStatus === 'pending_accountant' ? 'selected' : '' ?>>چاوەڕوانی ژمێریار</option>
                        <option value="returned_to_accountant" <?= $xrayRequestStatus === 'returned_to_accountant' ? 'selected' : '' ?>>گەڕاوەتەوە بۆ ژمێریار</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>لە بەروار</label>
                    <input type="date" name="xray_date_from" value="<?= htmlspecialchars($xrayDateFrom) ?>">
                </div>
                <div class="filter-group">
                    <label>بۆ بەروار</label>
                    <input type="date" name="xray_date_to" value="<?= htmlspecialchars($xrayDateTo) ?>">
                </div>
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> فلتەر</button>
                <a href="<?= APP_URL ?>/accountant/pending-reports.php?tab=xray" class="btn-reset"><i class="fas fa-times"></i> پاککردنەوە</a>
            </form>
        </div>
        <?php if (empty($pendingXrayRequests)): ?>
        <div class="no-data"><i class="fas fa-x-ray"></i><p>هیچ داواکارییەکی ئێکسڕەی نییە کە پێویستی بە پێداچوونەوەی ژمێریار هەبێت.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>داواکاری</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>خزمەتگوزاری</th>
                        <th>جۆری پارەدان</th>
                        <th>دۆخی پارەدان</th>
                        <th>دۆخی داواکاری</th>
                        <th style="text-align:center;">بڕی پارە</th>
                        <th style="text-align:center;">بەروار</th>
                        <th style="text-align:center;">کردار</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingXrayRequests as $xray): ?>
                <?php
                $paymentBadgeClass = in_array($xray['payment_status'], ['paid', 'debt_approved', 'not_required'], true) ? 'badge-paid' : ($xray['payment_status'] === 'refunded' ? 'badge-red' : 'badge-warn');
                $requestBadgeClass = $xray['request_status'] === 'returned_to_accountant' ? 'badge-red' : 'badge-warn';
                ?>
                <tr>
                    <td style="font-family:var(--app-font-family);font-weight:700;"><?= htmlspecialchars($xray['request_number'] ?? ('#' . $xray['id'])) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($xray['patient_name']) ?></strong>
                        <br><small style="color:#888;"><?= htmlspecialchars($xray['patient_code'] ?? '') ?></small>
                    </td>
                    <td><?= htmlspecialchars($xray['doctor_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($xray['xray_type']) ?></td>
                    <td><span class="status-badge"><?= htmlspecialchars($paymentTypeLabels[$xray['payment_type']] ?? $xray['payment_type']) ?></span></td>
                    <td><span class="status-badge <?= $paymentBadgeClass ?>"><?= htmlspecialchars($paymentStatusLabels[$xray['payment_status']] ?? $xray['payment_status']) ?></span></td>
                    <td>
                        <span class="status-badge <?= $requestBadgeClass ?>"><?= htmlspecialchars($requestStatusLabels[$xray['request_status']] ?? $xray['request_status']) ?></span>
                        <?php if (!empty($xray['return_reason'])): ?>
                        <br><small style="color:#c50f1f;"><?= htmlspecialchars($xray['return_reason']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;">
                        <?= number_format((float)($xray['final_amount'] ?: $xray['amount'])) ?>
                        <?php if ((float)($xray['discount_amount'] ?? 0) > 0): ?>
                        <br><small style="color:#e65100;">داشکاندن: <?= number_format((float)$xray['discount_amount']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;color:#888;"><?= date('Y-m-d H:i', strtotime($xray['created_at'])) ?></td>
                    <td style="text-align:center;white-space:nowrap;">
                        <button type="button" class="btn-icon btn-manage" onclick="openAccManageModal(<?= (int)$xray['id'] ?>, '<?= htmlspecialchars($xray['payment_type'], ENT_QUOTES) ?>', <?= (float)$xray['amount'] ?>, <?= (float)($xray['discount_amount'] ?? 0) ?>, <?= (float)($xray['final_amount'] ?: $xray['amount']) ?>)">
                            <i class="fas fa-cog"></i> بەڕێوەبردن
                        </button>
                        <a href="<?= APP_URL ?>/xray/view.php?id=<?= (int)$xray['id'] ?>" class="btn-icon btn-view">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-list-alt" style="color:#0078d4;"></i> <?= $status !== '' ? ($statusLabels[$status] ?? 'ڕاپۆرتەکان') : 'هەموو ڕاپۆرتەکان' ?></h3>
            <span class="table-count"><?= number_format($totalCount) ?> ڕاپۆرت</span>
        </div>
        <?php if (empty($reports)): ?>
        <div class="no-data"><i class="fas fa-file-invoice"></i><p>هیچ ڕاپۆرتێک نەدۆزرایەوە.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>دۆخ</th>
                        <th>نەخۆش</th>
                        <th>کەیس</th>
                        <th>دکتۆر</th>
                        <th style="text-align:center;">کۆی گشتی</th>
                        <th style="text-align:center;">دراوە</th>
                        <th style="text-align:center;">ماوە</th>
                        <th style="text-align:center;">بەروار</th>
                        <th style="text-align:center;">کردار</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reports as $rpt): ?>
                <?php
                $remaining = $rpt['total_amount'] - $rpt['paid_amount'] - $rpt['discount_amount'];
                $stColor = $statusColors[$rpt['status']] ?? '#666';
                $stBg = $statusBg[$rpt['status']] ?? '#f2f2f2';
                ?>
                <tr>
                    <td><span class="status-badge" style="background:<?= $stBg ?>;color:<?= $stColor ?>;"><?= htmlspecialchars($statusLabels[$rpt['status']] ?? $rpt['status']) ?></span></td>
                    <td>
                        <strong><?= htmlspecialchars($rpt['patient_name']) ?></strong>
                        <br><small style="color:#999;"><?= htmlspecialchars($rpt['patient_phone'] ?? '') ?></small>
                    </td>
                    <td style="font-family:var(--app-font-family);color:#666;"><?= htmlspecialchars($rpt['case_number']) ?></td>
                    <td><?= htmlspecialchars($rpt['doctor_name']) ?></td>
                    <td style="text-align:center;font-weight:600;"><?= number_format((float)$rpt['total_amount']) ?></td>
                    <td style="text-align:center;" class="amount-pos"><?= number_format((float)$rpt['paid_amount']) ?></td>
                    <td style="text-align:center;" class="<?= $remaining > 0 ? 'amount-neg' : 'amount-pos' ?>"><?= number_format((float)$remaining) ?></td>
                    <td style="text-align:center;color:#888;"><?= date('Y-m-d H:i', strtotime($rpt['sent_at'])) ?></td>
                    <td style="text-align:center;"><a href="<?= APP_URL ?>/accountant/report-detail.php?case_id=<?= (int)$rpt['case_id'] ?>" class="btn-icon btn-view"><i class="fas fa-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <div style="font-size:12px;color:#888;">لاپەڕە <?= $page ?> لە <?= $totalPages ?></div>
            <div class="pagination">
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <?= $i === $page
                    ? '<span class="active">' . $i . '</span>'
                    : '<a href="?status=' . urlencode($status) . '&search=' . urlencode($search) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&page=' . $i . '">' . $i . '</a>' ?>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="acc-modal-overlay" id="accManageModal">
    <div class="acc-modal-box">
        <div class="acc-modal-header">
            <h3><i class="fas fa-cog"></i> بەڕێوەبردنی ژمێریاری ئێکسڕەی</h3>
            <button type="button" class="acc-modal-close" onclick="closeAccManageModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="accManageForm" onsubmit="return confirmAccountantAction()">
            <div class="acc-modal-body">
                <input type="hidden" name="xray_id" id="acc_modal_xray_id">
                <div class="acc-form-group">
                <label>کردار</label>
                    <select name="accountant_action" id="acc_action_select" required onchange="accActionChanged()">
                        <option value="">کردارێک هەڵبژێرە</option>
                        <option value="approve_payment">پەسەندکردنی پارەدان و ناردن بۆ ئێکسڕەی</option>
                        <option value="approve_debt">پەسەندکردنی قەرز و ناردن بۆ ئێکسڕەی</option>
                    </select>
                </div>
                <div class="acc-form-group" id="acc_amount_group" style="display:none;">
                    <label>بڕی ڕاستکراوە</label>
                    <input type="number" name="new_amount" id="acc_new_amount" step="1000" min="0">
                    <small style="display:block;color:#777;margin-top:5px;">ئەگەر نرخ نەگۆڕدرا، هەمان نرخ بەکار دێت.</small>
                    <small id="acc_amount_context" style="display:block;color:#b35c00;margin-top:3px;"></small>
                </div>
                <div class="acc-form-group" id="acc_discount_group" style="display:none;">
                    <label>داشکاندن</label>
                    <input type="number" name="discount_amount" id="acc_discount_amount" step="1000" min="0" placeholder="0">
                </div>
                <div class="acc-form-group" id="acc_discount_reason_group" style="display:none;">
                    <label>هۆکاری داشکاندن</label>
                    <input type="text" name="discount_reason" id="acc_discount_reason" placeholder="هۆکار بنووسە ئەگەر داشکاندن هەیە">
                </div>
                <div class="acc-form-group">
                    <label>تێبینی / هۆکار</label>
                    <textarea name="action_note" id="acc_action_note" rows="3" placeholder="بۆ گەڕاندنەوەی پارە، ڕاستکردنەوەی بڕ، هەڵوەشاندنەوە یان پێداچوونەوەی دارایی پێویستە."></textarea>
                </div>
            </div>
            <div class="acc-modal-footer">
                <button type="button" class="acc-btn acc-btn-secondary" onclick="closeAccManageModal()">داخستن</button>
                <button type="submit" class="acc-btn acc-btn-primary"><i class="fas fa-check"></i> جێبەجێکردن</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAccManageModal(id, paymentType, amount, discount, finalAmount) {
    document.getElementById('acc_modal_xray_id').value = id;
    // The field edits the BASE price; when a discount already exists the table
    // shows the final amount, so spell out base/discount/final to avoid the
    // accountant "correcting" the base down to the final and wiping the discount
    document.getElementById('acc_new_amount').value = amount;
    document.getElementById('acc_amount_context').textContent =
        (discount > 0)
            ? 'نرخی بنەڕەت: ' + Number(amount).toLocaleString()
              + ' — داشکاندنی ئێستا: ' + Number(discount).toLocaleString()
              + ' — کۆتایی: ' + Number(finalAmount).toLocaleString()
            : '';
    document.getElementById('acc_discount_amount').value = '';
    document.getElementById('acc_discount_reason').value = '';
    document.getElementById('acc_action_select').value = '';
    document.getElementById('acc_action_note').value = '';
    document.getElementById('acc_amount_group').style.display = 'none';
    document.getElementById('acc_discount_group').style.display = 'none';
    document.getElementById('acc_discount_reason_group').style.display = 'none';
    const submitBtn = document.querySelector('#accManageForm button[type=submit]');
    if (submitBtn) submitBtn.disabled = false;
    document.getElementById('accManageModal').classList.add('active');
}

function closeAccManageModal() {
    document.getElementById('accManageModal').classList.remove('active');
}

function accActionChanged() {
    const val = document.getElementById('acc_action_select').value;
    const priceActions = ['approve_payment', 'approve_debt'];
    const discountActions = ['approve_payment', 'approve_debt'];
    document.getElementById('acc_amount_group').style.display = priceActions.includes(val) ? 'block' : 'none';
    document.getElementById('acc_discount_group').style.display = discountActions.includes(val) ? 'block' : 'none';
    document.getElementById('acc_discount_reason_group').style.display = discountActions.includes(val) ? 'block' : 'none';
}

function confirmAccountantAction() {
    const select = document.getElementById('acc_action_select');
    const label = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : 'ئەم کردارە';
    const discount = Number(document.getElementById('acc_discount_amount').value || 0);
    const discountReason = document.getElementById('acc_discount_reason').value.trim();
    if (discount > 0 && discountReason === '') {
        alert('تکایە هۆکاری داشکاندن بنووسە.');
        return false;
    }
    const ok = confirm('دڵنیایت لە جێبەجێکردنی: ' + label + '؟');
    if (ok) {
        // block double-submit — two approvals of the same request both succeed server-side
        const submitBtn = document.querySelector('#accManageForm button[type=submit]');
        if (submitBtn) submitBtn.disabled = true;
    }
    return ok;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
