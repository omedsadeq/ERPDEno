<?php
/**
 * Debt Collection & Call Management Page
 * کۆکردنەوەی قەرز و بەڕێوەبردنی پەیوەندیکردن
 */

$pageTitle = 'کۆکردنەوەی قەرز';
$breadcrumb = [['title' => 'کۆکردنەوەی قەرز']];

// Role gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$__gate = new Auth();
$__gate->requireLogin();
if (!$__gate->isAccountant() && !$__gate->isAdmin() && !$__gate->isDoctor()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/DebtCollection.php';

$permission = Permission::getInstance();
if (!$isAccountant && !$isAdmin && !$isDoctor) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$debtCollection = new DebtCollection();

// Filters
$search = trim($_GET['search'] ?? '');
$callFilter = $_GET['call_filter'] ?? '';
$sortBy = $_GET['sort'] ?? 'total_debt';
$sortDir = $_GET['dir'] ?? ($sortBy === 'next_installment_due_date' ? 'ASC' : 'DESC');
$debtTypeFilter = $_GET['debt_type'] ?? '';
$installmentDueFilter = $_GET['installment_due'] ?? '';
$installmentDate = $_GET['installment_date'] ?? '';
$dueFilter = $_GET['due_filter'] ?? '';
$dueDate = $_GET['due_date'] ?? '';
$dueFrom = $_GET['due_from'] ?? '';
$dueTo = $_GET['due_to'] ?? '';

$isValidDate = function($date) {
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
};

$selectedDueFrom = '';
$selectedDueTo = '';
if ($dueFilter === 'today') {
    $selectedDueFrom = date('Y-m-d');
    $selectedDueTo = date('Y-m-d');
} elseif ($dueFilter === 'tomorrow') {
    $selectedDueFrom = date('Y-m-d', strtotime('+1 day'));
    $selectedDueTo = $selectedDueFrom;
} elseif ($dueFilter === 'week') {
    $selectedDueFrom = date('Y-m-d', strtotime('monday this week'));
    $selectedDueTo = date('Y-m-d', strtotime('sunday this week'));
} elseif ($dueFilter === 'custom_date' && $isValidDate($dueDate)) {
    $selectedDueFrom = $dueDate;
    $selectedDueTo = $dueDate;
} elseif ($dueFilter === 'range') {
    $selectedDueFrom = $isValidDate($dueFrom) ? $dueFrom : '';
    $selectedDueTo = $isValidDate($dueTo) ? $dueTo : '';
    if ($selectedDueFrom !== '' && $selectedDueTo === '') $selectedDueTo = $selectedDueFrom;
    if ($selectedDueTo !== '' && $selectedDueFrom === '') $selectedDueFrom = $selectedDueTo;
    if ($selectedDueFrom !== '' && $selectedDueTo !== '' && $selectedDueFrom > $selectedDueTo) {
        [$selectedDueFrom, $selectedDueTo] = [$selectedDueTo, $selectedDueFrom];
    }
}

if (!isset($_GET['sort']) && $selectedDueFrom !== '' && $selectedDueTo !== '') {
    $sortBy = 'next_due_date';
    $sortDir = 'ASC';
}

$buildDueUrl = function($filter) {
    $params = $_GET;
    $params['due_filter'] = $filter;
    unset($params['due_date'], $params['due_from'], $params['due_to']);
    if (!isset($params['sort'])) $params['sort'] = 'next_due_date';
    if (!isset($params['dir'])) $params['dir'] = 'ASC';
    return APP_URL . '/accountant/debt-collection.php?' . http_build_query($params);
};

// Get data — a doctor sees ONLY their own patients; admin/accountant see all.
$dcDoctorId = ($isDoctor && !$isAdmin) ? (int)$currentUser['id'] : null;
$debtors = $debtCollection->getDebtors($search, $callFilter, $sortBy, $sortDir, $debtTypeFilter, $installmentDueFilter, $installmentDate, $selectedDueFrom, $selectedDueTo, $dcDoctorId);
$callStatusLabels = DebtCollection::getCallStatusLabels();
$callStatusColors = DebtCollection::getCallStatusColors();

// Follow-ups due today or earlier — the same patients counted by the sidebar
// notification badge, so the user can see exactly WHO needs a call
$todayStr = date('Y-m-d');
$followUpDueList = array_values(array_filter($debtors, function ($d) use ($todayStr) {
    return !empty($d['next_follow_up']) && substr($d['next_follow_up'], 0, 10) <= $todayStr;
}));
$followUpDueIds = array_column($followUpDueList, 'patient_id');

// Broken promises + active promises (waiting list) — shared logic in DebtCollection
$brokenPromiseIds = [];
$activePromiseIds = [];
foreach ($debtors as $d) {
    if (DebtCollection::isBrokenPromise($d)) {
        $brokenPromiseIds[] = $d['patient_id'];
    } elseif (DebtCollection::isActivePromise($d)) {
        $activePromiseIds[] = $d['patient_id'];
    }
}

// Daily call queue priority — same rules as the "Start Calling" assignment
$queuePriority = fn($d) => DebtCollection::callPriority($d);
$queueMode = ($_GET['queue'] ?? '') === 'today';

// Blue waiting list: patients with an active promise (no calls until the date)
$promisedMode = ($_GET['promised'] ?? '') === '1';
if ($promisedMode) {
    $debtors = array_values(array_filter($debtors, fn($d) => in_array($d['patient_id'], $activePromiseIds)));
    usort($debtors, fn($a, $b) => strcmp((string)$a['promised_date'], (string)$b['promised_date']));
}
$queueSize = count(array_filter($debtors, fn($d) => $queuePriority($d) > 0));
if ($queueMode) {
    $debtors = array_values(array_filter($debtors, fn($d) => $queuePriority($d) > 0));
    usort($debtors, function ($a, $b) use ($queuePriority) {
        $pa = $queuePriority($a);
        $pb = $queuePriority($b);
        return $pa === $pb ? ($b['total_debt'] <=> $a['total_debt']) : ($pa <=> $pb);
    });
}
$queueRemaining = count(array_filter($debtors, function ($d) use ($todayStr, $queuePriority) {
    return $queuePriority($d) > 0
        && (empty($d['last_call_date']) || substr($d['last_call_date'], 0, 10) !== $todayStr);
}));

// WhatsApp helper: 07xx… -> 9647xx…
$waNumber = function ($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') return '';
    if (strpos($digits, '964') === 0) return $digits;
    if ($digits[0] === '0') return '964' . substr($digits, 1);
    return '964' . $digits;
};
?>

<style>
.dc-container { max-width: 1500px; margin: 0 auto; }

/* Toolbar */
.dc-toolbar {
    display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; align-items: center;
    background: #fff; padding: 8px 12px; border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.dc-toolbar .search-box {
    flex: 1; min-width: 200px; position: relative;
}
.dc-toolbar .search-box input {
    width: 100%; padding: 9px 14px 9px 36px; border: 1px solid #e0e0e0;
    border-radius: 8px; font-size: 14px; direction: rtl;
}
.dc-toolbar .search-box i {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999;
}
.dc-toolbar select,
.dc-toolbar input[type="date"] {
    padding: 7px 10px; border: 1px solid #e0e0e0; border-radius: 8px;
    font-size: 12.5px; background: #fff; min-width: 130px;
}
.dc-toolbar .btn { padding: 9px 18px; border-radius: 8px; font-size: 13px;
    border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.dc-toolbar .btn-pdf { background: #e74c3c; color: #fff; }
.dc-toolbar .btn-pdf:hover { background: #c0392b; }
.dc-toolbar .btn-reset { background: #f0f0f0; color: #333; }
.due-shortcuts {
    display: flex; gap: 6px; flex-wrap: wrap; align-items: center;
    background: #fff; padding: 7px 12px; border-radius: 12px;
    margin-bottom: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.due-shortcuts .due-label { font-size: 12px; color: #666; font-weight: 700; }
.due-sep { width: 1px; height: 22px; background: #e0e0e0; margin: 0 6px; }
.due-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 10px; border-radius: 999px; text-decoration: none;
    font-size: 12px; font-weight: 700; border: 1px solid #e0e0e0;
    color: #444; background: #f8f9fa;
}
.due-chip.active { background: #e3f2fd; border-color: #0078d4; color: #005a9e; }
.due-range-note { font-size: 12px; color: #777; }

/* Table */
.dc-card {
    background: #fff; border-radius: 12px; overflow-x: auto;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.dc-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.dc-table thead { background: #f8f9fa; }
.dc-table th {
    padding: 8px 9px; font-weight: 600; color: #444; text-align: right;
    border-bottom: 2px solid #e9ecef; white-space: nowrap; font-size: 11.5px;
}
.dc-table td {
    padding: 7px 9px; border-bottom: 1px solid #f0f0f0; vertical-align: middle;
}
.dc-table tbody tr { transition: background 0.15s; }
.dc-table tbody tr:hover { background: #f8faff; }

/* Actions column always visible: pinned to the left edge while the rest scrolls */
.dc-table th:last-child,
.dc-table td:last-child {
    position: sticky; left: 0; z-index: 2;
    background: #fff;
    box-shadow: 2px 0 6px rgba(0,0,0,0.07);
}
.dc-table thead th:last-child { background: #f8f9fa; z-index: 3; }
.dc-table tbody tr:hover td:last-child { background: #f8faff; }
.dc-table tbody tr.row-follow-up-due td:last-child { background: #fff5f5; }
.dc-table tbody tr.row-follow-up-due:hover td:last-child { background: #ffecec; }
.dc-table tbody tr.row-promised td:last-child { background: #f2f9ff; }
.dc-table tbody tr.row-promised:hover td:last-child { background: #e8f4ff; }

/* Hide secondary columns as the screen narrows (actions stay) */
@media (max-width: 1350px) {
    .dc-table th:nth-child(9), .dc-table td:nth-child(9),   /* دوایین پارەدان */
    .dc-table th:nth-child(11), .dc-table td:nth-child(11)  /* دوایین تێبینی */
    { display: none; }
}
@media (max-width: 1050px) {
    .dc-table th:nth-child(5), .dc-table td:nth-child(5),   /* کۆی گشتی */
    .dc-table th:nth-child(6), .dc-table td:nth-child(6)    /* دراوە */
    { display: none; }
}
@media (max-width: 850px) {
    .dc-table th:nth-child(1), .dc-table td:nth-child(1),   /* # */
    .dc-table th:nth-child(8), .dc-table td:nth-child(8)    /* جۆری قەرز */
    { display: none; }
}

/* Row color coding */
.dc-table tbody tr.row-called-today { border-right: 4px solid #27ae60; }
.dc-table tbody tr.row-overdue-call { border-right: 4px solid #e74c3c; }
.dc-table tbody tr.row-never-called { border-right: 4px solid #f39c12; }
.dc-table tbody tr.row-normal { border-right: 4px solid transparent; }
.dc-table tbody tr.row-follow-up-due {
    border-right: 4px solid #e74c3c;
    background: #fff5f5;
}
.dc-table tbody tr.row-follow-up-due:hover { background: #ffecec; }

/* Follow-up due banner + badge */
.fu-banner {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    background: #fff5f5; border: 1px solid #f5b7b1; border-radius: 12px;
    padding: 12px 18px; margin-bottom: 12px;
}
.fu-banner .fu-title {
    font-size: 13px; font-weight: 700; color: #c0392b;
    display: inline-flex; align-items: center; gap: 7px;
}
.fu-banner .fu-title i { animation: fu-ring 2s infinite; }
@keyframes fu-ring {
    0%, 100% { transform: rotate(0); }
    5%, 15% { transform: rotate(-15deg); }
    10%, 20% { transform: rotate(15deg); }
}
.fu-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: 999px; text-decoration: none;
    font-size: 12px; font-weight: 700;
    background: #fff; border: 1px solid #e74c3c; color: #c0392b;
}
.fu-chip:hover { background: #e74c3c; color: #fff; }
.fu-chip .fu-date { font-weight: 400; font-size: 11px; opacity: 0.8; direction: ltr; }
.fu-name-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: #e74c3c; color: #fff; margin-right: 4px; white-space: nowrap;
}
.bp-name-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: #f39c12; color: #fff; margin-right: 4px; white-space: nowrap;
}

/* Today's queue */
.queue-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 13px; border-radius: 999px; text-decoration: none;
    font-size: 12.5px; font-weight: 800;
    background: linear-gradient(135deg, #0078d4, #005a9e); color: #fff;
    border: none;
}
.queue-chip:hover { opacity: 0.92; color: #fff; }
.queue-chip.active { background: linear-gradient(135deg, #27ae60, #1e8e4e); }
.queue-progress {
    flex: 1; min-width: 160px; display: flex; align-items: center; gap: 10px;
    font-size: 12px; font-weight: 700; color: #444;
}
.queue-progress .bar { flex: 1; height: 8px; background: #eee; border-radius: 999px; overflow: hidden; }
.queue-progress .bar > div { height: 100%; background: linear-gradient(90deg, #27ae60, #2ecc71); border-radius: 999px; }

/* Row action buttons */
.dc-btn-wa { background: #25d366; color: #fff; }
.dc-btn-wa:hover { background: #1ebe5b; color: #fff; }
.dc-btn-pay { background: #8e44ad; color: #fff; }
.dc-btn-pay:hover { background: #7d3c98; color: #fff; }
.dc-btn-mute { background: #f0f0f0; color: #888; }
.dc-btn-mute:hover { background: #e0e0e0; }
.dc-table tbody tr.row-optout { opacity: 0.55; }
.optout-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: #95a5a6; color: #fff; margin-right: 4px; white-space: nowrap;
}

/* Start-work button */
.queue-chip.start-work { background: linear-gradient(135deg, #27ae60, #1e8e4e); cursor: pointer; }

/* Active promise (blue waiting list) */
.dc-table tbody tr.row-promised {
    border-right: 4px solid #3498db;
    background: #f2f9ff;
}
.dc-table tbody tr.row-promised:hover { background: #e8f4ff; }
.ap-name-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: #3498db; color: #fff; margin-right: 4px; white-space: nowrap; direction: ltr;
}

/* Locked rows: a colleague is working on this patient right now */
.dc-table tbody tr.row-locked { opacity: 0.45; }
.dc-table tbody tr.row-locked .dc-actions { pointer-events: none; opacity: 0.4; }
.lock-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: #34495e; color: #fff; margin-right: 4px; white-space: nowrap;
}

/* Admin live monitoring panel (compact) */
.dc-live {
    background: #1a1a2e; color: #fff; border-radius: 12px;
    padding: 8px 14px; margin-bottom: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.15);
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px;
}
.dc-live-title {
    font-size: 12.5px; font-weight: 800; display: inline-flex; align-items: center; gap: 7px;
    margin: 0; white-space: nowrap;
}
.dc-live-title .live-dot { color: #2ecc71; animation: pulse-dot 1.6s infinite; font-size: 9px; }
@keyframes pulse-dot { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
.dc-live-row { display: inline-flex; flex-wrap: wrap; gap: 6px; margin: 0; }
.dc-live-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,0.09); border-radius: 999px;
    padding: 3px 10px; font-size: 11.5px;
}
.dc-live-chip.now { background: rgba(46, 204, 113, 0.18); border: 1px solid rgba(46,204,113,0.4); }
.dc-live-chip b { color: #2ecc71; }
.dc-live-empty { font-size: 12px; color: #aaa; }

/* Patient cell */
.patient-info { display: flex; flex-direction: column; gap: 2px; }
.patient-name { font-weight: 600; color: #1a1a2e; font-size: 13.5px; }
.patient-code { font-size: 11px; color: #888; }

/* Phone links */
.phone-link {
    display: inline-flex; align-items: center; gap: 5px;
    color: #0078d4; text-decoration: none; direction: ltr; font-size: 13px;
}
.phone-link:hover { color: #005a9e; text-decoration: underline; }
.phone-link i { font-size: 11px; }

/* Amount cells */
.amount-debt { color: #e74c3c; font-weight: 700; font-size: 13.5px; }
.amount-paid { color: #27ae60; font-weight: 600; }
.amount-total { color: #444; }
.debt-type-cell { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.debt-type-badge {
    display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px;
    border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap;
}
.debt-type-badge.installment { background: #e8f3ff; color: #005a9e; }
.debt-type-badge.normal { background: #fff4e5; color: #9a4d00; }
.debt-type-badge.mixed { background: #f3e8ff; color: #6d28d9; }
.debt-breakdown { font-size: 11px; color: #666; line-height: 1.7; }
.debt-breakdown span { display: block; white-space: nowrap; }

/* Call status badge */
.call-badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; color: #fff; white-space: nowrap;
}

/* Last call note tooltip */
.call-note-preview {
    max-width: 160px; font-size: 11.5px; color: #666;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    cursor: help;
}

/* Action buttons */
.dc-actions { display: flex; gap: 4px; flex-wrap: nowrap; }
.dc-btn {
    padding: 6px 8px; border-radius: 8px; font-size: 12px;
    border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
    transition: all 0.15s; white-space: nowrap; text-decoration: none;
}
.dc-btn-call { background: #e8f5e9; color: #2e7d32; }
.dc-btn-call:hover { background: #c8e6c9; }
.dc-btn-history { background: #e3f2fd; color: #1565c0; }
.dc-btn-history:hover { background: #bbdefb; }

/* Overlay & Modal */
.dc-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.45); display: none; z-index: 1050;
    align-items: center; justify-content: center;
}
.dc-overlay.show { display: flex; }
.dc-modal {
    background: #fff; border-radius: 14px; width: 90%; max-width: 520px;
    max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    animation: modalIn 0.2s ease;
}
@keyframes modalIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
.dc-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 22px; border-bottom: 1px solid #eee;
}
.dc-modal-header h3 { margin: 0; font-size: 16px; color: #1a1a2e; display: flex; align-items: center; gap: 8px; }
.dc-modal-close { background: none; border: none; font-size: 20px; color: #999; cursor: pointer; padding: 4px; }
.dc-modal-close:hover { color: #333; }
.dc-modal-body { padding: 22px; }
.dc-modal-footer { padding: 14px 22px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }

/* Form */
.dc-form-group { margin-bottom: 16px; }
.dc-form-group label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 6px; }
.dc-form-group select, .dc-form-group textarea, .dc-form-group input {
    width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px;
    font-size: 13px; transition: border 0.2s;
}
.dc-form-group select:focus, .dc-form-group textarea:focus, .dc-form-group input:focus {
    border-color: #0078d4; outline: none; box-shadow: 0 0 0 3px rgba(0,120,212,0.08);
}
.dc-btn-submit { background: #0078d4; color: #fff; padding: 10px 22px; border-radius: 8px;
    border: none; cursor: pointer; font-size: 14px; font-weight: 600; }
.dc-btn-submit:hover { background: #005a9e; }
.dc-btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
.dc-btn-cancel { background: #f0f0f0; color: #333; padding: 10px 22px; border-radius: 8px;
    border: none; cursor: pointer; font-size: 14px; }

/* History modal */
.history-list { max-height: 400px; overflow-y: auto; }
.history-item {
    padding: 12px 14px; border-bottom: 1px solid #f0f0f0;
    display: flex; flex-direction: column; gap: 6px;
}
.history-item:last-child { border-bottom: none; }
.history-meta { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.history-date { font-size: 12px; color: #888; direction: ltr; }
.history-caller { font-size: 12px; color: #666; }
.history-note { font-size: 13px; color: #333; background: #f8f9fa; padding: 8px 12px; border-radius: 8px; margin-top: 4px; }

/* Empty state */
.dc-empty { text-align: center; padding: 50px 20px; color: #999; }
.dc-empty i { font-size: 48px; margin-bottom: 16px; display: block; opacity: 0.3; }

/* Counter */
.dc-count { font-size: 13px; color: #666; padding: 10px 18px; }

/* Responsive */
@media (max-width: 768px) {
    .dc-toolbar { flex-direction: column; }
    .dc-table { font-size: 12px; }
    .dc-table th, .dc-table td { padding: 6px 7px; }
    .call-note-preview { max-width: 100px; }
}
</style>

<div class="dc-container">
    <?php if ($isAdmin): ?>
    <!-- Live monitoring: who is calling whom right now + today's numbers per employee -->
    <div class="dc-live" id="liveStatusPanel">
        <div class="dc-live-title">
            <i class="fas fa-satellite-dish"></i> چاودێری ژووری پەیوەندی
            <span class="live-dot"><i class="fas fa-circle"></i></span>
            <span id="liveTotals" style="margin-right:auto;font-weight:400;font-size:12px;color:#ccc;"></span>
        </div>
        <div class="dc-live-row" id="liveNow"><span class="dc-live-empty">چاوەڕوانی زانیاری...</span></div>
        <div class="dc-live-row" id="liveToday"></div>
    </div>
    <?php endif; ?>

    <!-- Call queue + due-payment shortcuts (one compact bar) -->
    <div class="due-shortcuts">
        <button type="button" class="queue-chip start-work" onclick="startCalling()" title="سیستەمەکە خۆی گرنگترین نەخۆشت پێ دەدات و بۆ هاوکارەکانت قفڵی دەکات">
            <i class="fas fa-play"></i> دەستپێکردنی کار
        </button>
        <?php if ($queueMode): ?>
        <a class="queue-chip active" href="<?= APP_URL ?>/accountant/debt-collection.php">
            <i class="fas fa-times"></i> دەرچوون لە لیستی ئەمڕۆ
        </a>
        <div class="queue-progress">
            <?php $queueDone = max(0, count($debtors) - $queueRemaining); ?>
            <span><?= $queueDone ?> لە <?= count($debtors) ?></span>
            <div class="bar"><div style="width: <?= count($debtors) > 0 ? round($queueDone / count($debtors) * 100) : 0 ?>%;"></div></div>
            <span><?= $queueRemaining ?> ماوە</span>
        </div>
        <?php else: ?>
        <a class="queue-chip" href="<?= APP_URL ?>/accountant/debt-collection.php?queue=today">
            <i class="fas fa-phone-volume"></i> لیستی ئەمڕۆ (<?= $queueSize ?>)
        </a>
        <?php endif; ?>
        <a class="due-chip <?= $promisedMode ? 'active' : '' ?>" style="border-color:#3498db;color:#1565c0;"
           href="<?= APP_URL ?>/accountant/debt-collection.php<?= $promisedMode ? '' : '?promised=1' ?>">
            <i class="fas fa-handshake"></i> بەڵێنەکان (<?= count($activePromiseIds) ?>)
        </a>
        <a class="due-chip" href="<?= APP_URL ?>/accountant/call-reports.php">
            <i class="fas fa-chart-bar"></i> ڕاپۆرتی پەیوەندییەکان
        </a>
        <span class="due-sep"></span>
        <span class="due-label"><i class="fas fa-calendar-day"></i> پارەدانەکان</span>
        <a class="due-chip <?= $dueFilter === 'today' ? 'active' : '' ?>" href="<?= $buildDueUrl('today') ?>">ئەمڕۆ</a>
        <a class="due-chip <?= $dueFilter === 'tomorrow' ? 'active' : '' ?>" href="<?= $buildDueUrl('tomorrow') ?>">سبەی</a>
        <a class="due-chip <?= $dueFilter === 'week' ? 'active' : '' ?>" href="<?= $buildDueUrl('week') ?>">ئەم هەفتەیە</a>
        <?php if ($selectedDueFrom && $selectedDueTo): ?>
        <span class="due-range-note">
            <?= htmlspecialchars($selectedDueFrom) ?><?= $selectedDueFrom !== $selectedDueTo ? ' - ' . htmlspecialchars($selectedDueTo) : '' ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if (!empty($followUpDueList)): ?>
    <!-- Who the sidebar notification badge is about: follow-up calls due today -->
    <div class="fu-banner">
        <span class="fu-title">
            <i class="fas fa-bell"></i>
            <?= count($followUpDueList) ?> نەخۆش کاتی بەدواداچوونیان هاتووە — پەیوەندیان پێوە بکە:
        </span>
        <?php foreach ($followUpDueList as $fu): ?>
        <a class="fu-chip" href="#row-<?= (int)$fu['patient_id'] ?>" onclick="flashRow(<?= (int)$fu['patient_id'] ?>)">
            <i class="fas fa-phone"></i>
            <?= htmlspecialchars($fu['patient_name']) ?>
            <span class="fu-date"><?= htmlspecialchars(substr($fu['next_follow_up'], 0, 10)) ?></span>
        </a>
        <?php endforeach; ?>
        <?php if ($callFilter !== 'follow_up_today'): ?>
        <a class="due-chip" href="<?= APP_URL ?>/accountant/debt-collection.php?call_filter=follow_up_today" style="border-color:#e74c3c;color:#c0392b;">
            <i class="fas fa-filter"></i> تەنها ئەمانە پیشان بدە
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <!-- Toolbar -->
    <form method="GET" class="dc-toolbar">
        <div class="search-box">
            <input type="text" name="search" placeholder="گەڕان بە ناو، ژمارە، کۆد..." value="<?= htmlspecialchars($search) ?>">
            <i class="fas fa-search"></i>
        </div>
        <select name="due_filter" id="dueFilterSelect" onchange="toggleDueInputs()">
            <option value="" <?= $dueFilter === '' ? 'selected' : '' ?>>هەموو پارەدانە کاتدیاریکراوەکان</option>
            <option value="today" <?= $dueFilter === 'today' ? 'selected' : '' ?>>ئەمڕۆ</option>
            <option value="tomorrow" <?= $dueFilter === 'tomorrow' ? 'selected' : '' ?>>سبەی</option>
            <option value="week" <?= $dueFilter === 'week' ? 'selected' : '' ?>>ئەم هەفتەیە</option>
            <option value="custom_date" <?= $dueFilter === 'custom_date' ? 'selected' : '' ?>>بەرواری دیاریکراو</option>
            <option value="range" <?= $dueFilter === 'range' ? 'selected' : '' ?>>مەودای بەروار</option>
        </select>
        <input type="date" name="due_date" id="dueDateInput" value="<?= htmlspecialchars($dueDate) ?>" title="بەرواری دیاریکراو">
        <input type="date" name="due_from" id="dueFromInput" value="<?= htmlspecialchars($dueFrom) ?>" title="لە بەروار">
        <input type="date" name="due_to" id="dueToInput" value="<?= htmlspecialchars($dueTo) ?>" title="بۆ بەروار">
        <select name="call_filter">
            <option value="">هەموو نەخۆشەکان</option>
            <option value="not_called" <?= $callFilter === 'not_called' ? 'selected' : '' ?>>هەرگیز پەیوەندی نەکراو</option>
            <option value="called_today" <?= $callFilter === 'called_today' ? 'selected' : '' ?>>ئەمڕۆ پەیوەندی کراو</option>
            <option value="overdue_call" <?= $callFilter === 'overdue_call' ? 'selected' : '' ?>>زیاتر لە مانگ پەیوەندی نەکراو</option>
            <option value="follow_up_today" <?= $callFilter === 'follow_up_today' ? 'selected' : '' ?>>پەیگیری ئەمڕۆ</option>
        </select>
        <select name="debt_type">
            <option value="">هەموو جۆری قەرز</option>
            <option value="has_installment" <?= $debtTypeFilter === 'has_installment' ? 'selected' : '' ?>>هەموو قیستەکان</option>
            <option value="installment" <?= $debtTypeFilter === 'installment' ? 'selected' : '' ?>>تەنها قیست</option>
            <option value="normal" <?= $debtTypeFilter === 'normal' ? 'selected' : '' ?>>تەنها قەرزی ئاسایی</option>
            <option value="mixed" <?= $debtTypeFilter === 'mixed' ? 'selected' : '' ?>>تێکەڵ</option>
        </select>
        <select name="installment_due">
            <option value="">هەموو کاتی قیست</option>
            <option value="overdue" <?= $installmentDueFilter === 'overdue' ? 'selected' : '' ?>>قیستی دواکەوتوو</option>
            <option value="today" <?= $installmentDueFilter === 'today' ? 'selected' : '' ?>>قیستی ئەمڕۆ</option>
            <option value="upcoming" <?= $installmentDueFilter === 'upcoming' ? 'selected' : '' ?>>قیستی داهاتوو</option>
        </select>
        <input type="date" name="installment_date" value="<?= htmlspecialchars($installmentDate) ?>" title="فلتەر بە بەرواری قیست">
        <select name="sort">
            <option value="total_debt" <?= $sortBy === 'total_debt' ? 'selected' : '' ?>>ڕیزکردن: قەرز</option>
            <option value="patient_name" <?= $sortBy === 'patient_name' ? 'selected' : '' ?>>ڕیزکردن: ناو</option>
            <option value="last_call_date" <?= $sortBy === 'last_call_date' ? 'selected' : '' ?>>ڕیزکردن: دوایین پەیوەندی</option>
            <option value="next_due_date" <?= $sortBy === 'next_due_date' ? 'selected' : '' ?>>ڕیزکردن: بەرواری پارەدان</option>
            <option value="next_installment_due_date" <?= $sortBy === 'next_installment_due_date' ? 'selected' : '' ?>>ڕیزکردن: کاتی قیست</option>
        </select>
        <button type="submit" class="btn" style="background:#0078d4;color:#fff;"><i class="fas fa-filter"></i> فلتەرکردن</button>
        <a href="<?= APP_URL ?>/accountant/debt-collection.php" class="btn btn-reset"><i class="fas fa-redo"></i> ڕیسێت</a>
        <a href="<?= APP_URL ?>/accountant/debt-collection-pdf.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-pdf">
            <i class="fas fa-file-pdf"></i> PDF ڕاپۆرت
        </a>
    </form>

    <!-- Table -->
    <div class="dc-count"><?= count($debtors) ?> نەخۆش</div>
    <div class="dc-card">
        <?php if (empty($debtors)): ?>
        <div class="dc-empty">
            <i class="fas fa-check-circle"></i>
            <h3>هیچ قەرزدارێک نەدۆزرایەوە</h3>
        </div>
        <?php else: ?>
        <table class="dc-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نەخۆش</th>
                    <th>کاتی پارەدان</th>
                    <th>ژمارەی مۆبایل</th>
                    <th>کۆی گشتی</th>
                    <th>دراوە</th>
                    <th>ماوەی قەرز</th>
                    <th>جۆری قەرز</th>
                    <th>دوایین پارەدان</th>
                    <th>دۆخی پەیوەندی</th>
                    <th>دوایین تێبینی</th>
                    <th>کردارەکان</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($debtors as $i => $debtor):
                    $installmentDebt = max(0, (float) ($debtor['installment_debt'] ?? 0));
                    $normalDebt = max(0, (float) ($debtor['normal_debt'] ?? 0));
                    $nextInstallmentDate = $debtor['next_installment_due_date'] ?? null;
                    $overdueInstallments = intval($debtor['overdue_installments'] ?? 0);
                    $dueTodayInstallments = intval($debtor['due_today_installments'] ?? 0);
                    $installmentTimeClass = 'installment';
                    $installmentTimeIcon = 'fa-calendar-alt';
                    $installmentTimeLabel = 'قیستی داهاتوو';
                    if ($installmentDebt > 0 && $normalDebt > 0) {
                        $debtTypeClass = 'mixed';
                        $debtTypeIcon = 'fa-layer-group';
                        $debtTypeLabel = 'تێکەڵ';
                    } elseif ($installmentDebt > 0) {
                        $debtTypeClass = 'installment';
                        $debtTypeIcon = 'fa-calendar-alt';
                        $debtTypeLabel = 'قیست';
                    } else {
                        $debtTypeClass = 'normal';
                        $debtTypeIcon = 'fa-file-invoice-dollar';
                        $debtTypeLabel = 'قەرزی ئاسایی';
                    }
                    if ($installmentDebt > 0) {
                        if ($overdueInstallments > 0) {
                            $installmentTimeClass = 'normal';
                            $installmentTimeIcon = 'fa-exclamation-circle';
                            $installmentTimeLabel = 'قیستی دواکەوتوو';
                        } elseif ($dueTodayInstallments > 0) {
                            $installmentTimeClass = 'mixed';
                            $installmentTimeIcon = 'fa-calendar-day';
                            $installmentTimeLabel = 'قیستی ئەمڕۆ';
                        } else {
                            $installmentTimeClass = 'installment';
                            $installmentTimeIcon = 'fa-calendar-alt';
                            $installmentTimeLabel = 'قیستی داهاتوو';
                        }
                    }

                    $normalDueDate = $debtor['next_normal_due_date'] ?? null;
                    $normalDueAmount = max(0, (float) ($debtor['normal_due_amount'] ?? $normalDebt));
                    $installmentDueAmount = max(0, (float) ($debtor['overdue_installment_amount'] ?? 0));
                    if ($installmentDueAmount <= 0) {
                        $installmentDueAmount = max(0, (float) ($debtor['next_installment_amount'] ?? 0));
                    }

                    $nextDueDate = $debtor['next_due_date'] ?? null;
                    $useInstallmentDue = $installmentDebt > 0
                        && $nextInstallmentDate
                        && (!$normalDueDate || $nextInstallmentDate <= $normalDueDate);

                    if ($useInstallmentDue) {
                        $dueSourceClass = $installmentTimeClass;
                        $dueSourceIcon = $installmentTimeIcon;
                        $dueSourceLabel = $installmentTimeLabel;
                        $dueAmount = $installmentDueAmount;
                        $nextDueDate = $nextDueDate ?: $nextInstallmentDate;
                    } elseif ($normalDueDate) {
                        $dueSourceClass = 'normal';
                        $dueSourceIcon = 'fa-calendar-check';
                        $dueSourceLabel = 'قەرزی ئاسایی';
                        $dueAmount = $normalDueAmount;
                        $nextDueDate = $nextDueDate ?: $normalDueDate;
                    } elseif ($nextInstallmentDate) {
                        $dueSourceClass = $installmentTimeClass;
                        $dueSourceIcon = $installmentTimeIcon;
                        $dueSourceLabel = $installmentTimeLabel;
                        $dueAmount = $installmentDueAmount;
                        $nextDueDate = $nextInstallmentDate;
                    } else {
                        $dueSourceClass = 'normal';
                        $dueSourceIcon = 'fa-calendar-check';
                        $dueSourceLabel = 'قەرزی ئاسایی';
                        $dueAmount = 0;
                    }
                    // Determine row color class
                    $rowClass = 'row-normal';
                    if (!empty($debtor['last_call_date'])) {
                        $lastCallDate = new DateTime($debtor['last_call_date']);
                        $today = new DateTime();
                        $daysSinceCall = $today->diff($lastCallDate)->days;
                        if ($daysSinceCall === 0) {
                            $rowClass = 'row-called-today';
                        } elseif ($daysSinceCall > 30) {
                            $rowClass = 'row-overdue-call';
                        }
                    } else {
                        $rowClass = 'row-never-called';
                    }
                    // Follow-up due wins over every other color — this row IS the notification
                    $isFollowUpDue = in_array($debtor['patient_id'], $followUpDueIds);
                    if ($isFollowUpDue) {
                        $rowClass = 'row-follow-up-due';
                    }
                    $isBrokenPromise = in_array($debtor['patient_id'], $brokenPromiseIds);
                    // Blue waiting list: promised a future date — leave them alone until then
                    $isActivePromise = in_array($debtor['patient_id'], $activePromiseIds);
                    if ($isActivePromise) {
                        $rowClass = 'row-promised';
                    }
                    $isOptOut = (int)($debtor['call_optout'] ?? 0) === 1;
                    if ($isOptOut) {
                        $rowClass .= ' row-optout';
                    }
                    $waPhone = $waNumber($debtor['phone'] ?? '');
                    $waText = 'بەڕێز ' . $debtor['patient_name'] . '، لە ' . CLINIC_NAME
                        . '. بڕی قەرزی ماوەت: ' . number_format($debtor['total_debt'])
                        . ' دینار. تکایە بۆ پارەدان سەردانمان بکە یان پەیوەندیمان پێوە بکە. سوپاس.';
                ?>
                <tr class="<?= $rowClass ?>" id="row-<?= $debtor['patient_id'] ?>">
                    <td><?= $i + 1 ?></td>
                    <td>
                        <div class="patient-info">
                            <span class="patient-name">
                                <?= htmlspecialchars($debtor['patient_name']) ?>
                                <?php if ($isFollowUpDue): ?>
                                <span class="fu-name-badge"><i class="fas fa-bell"></i> بەدواداچوون</span>
                                <?php endif; ?>
                                <?php if ($isBrokenPromise): ?>
                                <span class="bp-name-badge" title="بەڵێنی <?= number_format((float)($debtor['promised_amount'] ?? 0)) ?> لە <?= htmlspecialchars(substr($debtor['promised_date'], 0, 10)) ?> جێبەجێ نەکرا">
                                    <i class="fas fa-handshake-slash"></i> بەڵێنی شکاو
                                </span>
                                <?php endif; ?>
                                <?php if ($isActivePromise): ?>
                                <span class="ap-name-badge" title="بەڵێنی <?= number_format((float)($debtor['promised_amount'] ?? 0)) ?> — چاوەڕوانە تا ئەو بەروارە">
                                    <i class="fas fa-handshake"></i> بەڵێن: <?= htmlspecialchars(substr($debtor['promised_date'], 0, 10)) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($isOptOut): ?>
                                <span class="optout-badge"><i class="fas fa-bell-slash"></i> پەیوەندی ناکرێت</span>
                                <?php endif; ?>
                            </span>
                            <span class="patient-code"><?= htmlspecialchars($debtor['patient_code']) ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($nextDueDate): ?>
                        <div class="debt-type-cell">
                            <span class="debt-type-badge <?= $dueSourceClass ?>">
                                <i class="fas <?= $dueSourceIcon ?>"></i> <?= $dueSourceLabel ?>
                            </span>
                            <div class="debt-breakdown">
                                <span>بەروار: <?= date('Y/m/d', strtotime($nextDueDate)) ?></span>
                                <?php if ($dueAmount > 0): ?>
                                <span>بڕ: <?= number_format($dueAmount) ?></span>
                                <?php endif; ?>
                                <?php if ($useInstallmentDue && !empty($debtor['next_installment_number'])): ?>
                                <span>ژمارەی قیست: <?= intval($debtor['next_installment_number']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                            <span style="font-size:12px;color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($debtor['phone'])): ?>
                        <a href="tel:<?= htmlspecialchars($debtor['phone']) ?>" class="phone-link">
                            <i class="fas fa-phone-alt"></i> <?= htmlspecialchars($debtor['phone']) ?>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($debtor['phone2'])): ?>
                        <br>
                        <a href="tel:<?= htmlspecialchars($debtor['phone2']) ?>" class="phone-link" style="margin-top:4px;">
                            <i class="fas fa-phone"></i> <?= htmlspecialchars($debtor['phone2']) ?>
                        </a>
                        <?php endif; ?>
                    </td>
                    <td><span class="amount-total"><?= number_format($debtor['total_amount']) ?></span></td>
                    <td><span class="amount-paid"><?= number_format($debtor['total_paid']) ?></span></td>
                    <td><span class="amount-debt"><?= number_format($debtor['total_debt']) ?></span></td>
                    <td>
                        <div class="debt-type-cell">
                            <span class="debt-type-badge <?= $debtTypeClass ?>">
                                <i class="fas <?= $debtTypeIcon ?>"></i> <?= $debtTypeLabel ?>
                            </span>
                            <div class="debt-breakdown">
                                <?php if ($installmentDebt > 0): ?>
                                <span>قیست: <?= number_format($installmentDebt) ?></span>
                                <?php endif; ?>
                                <?php if ($normalDebt > 0): ?>
                                <span>ئاسایی: <?= number_format($normalDebt) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($debtor['overdue_installments'])): ?>
                                <span style="color:#e74c3c;">دواکەوتوو: <?= intval($debtor['overdue_installments']) ?> قیست</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($debtor['last_payment_date']): ?>
                            <span style="font-size:12px;color:#666;"><?= date('Y/m/d', strtotime($debtor['last_payment_date'])) ?></span>
                        <?php else: ?>
                            <span style="font-size:12px;color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-last-call">
                        <?php if ($debtor['last_call_status']): ?>
                            <span class="call-badge" style="background:<?= $callStatusColors[$debtor['last_call_status']] ?? '#999' ?>;">
                                <?= $callStatusLabels[$debtor['last_call_status']] ?? $debtor['last_call_status'] ?>
                            </span>
                            <?php if ($debtor['last_call_date']): ?>
                            <div style="font-size:11px;color:#999;margin-top:3px;"><?= date('Y/m/d', strtotime($debtor['last_call_date'])) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:12px;color:#ccc;">پەیوەندی نەکراوە</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-note">
                        <?php if ($debtor['last_call_note']): ?>
                        <div class="call-note-preview" title="<?= htmlspecialchars($debtor['last_call_note']) ?>">
                            <?= htmlspecialchars($debtor['last_call_note']) ?>
                        </div>
                        <?php else: ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dc-actions">
                            <button type="button" class="dc-btn dc-btn-call" onclick="openCallModal(<?= $debtor['patient_id'] ?>, '<?= htmlspecialchars(addslashes($debtor['patient_name'])) ?>', <?= $debtor['total_debt'] ?>, '<?= htmlspecialchars(addslashes($debtor['phone'] ?? '')) ?>')">
                                <i class="fas fa-phone-alt"></i> تۆمار
                            </button>
                            <?php if ($waPhone !== ''): ?>
                            <a class="dc-btn dc-btn-wa" target="_blank" rel="noopener"
                               href="https://wa.me/<?= htmlspecialchars($waPhone) ?>?text=<?= rawurlencode($waText) ?>"
                               title="ناردنی نامەی واتسئاپ">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <?php endif; ?>
                            <a class="dc-btn dc-btn-pay" href="<?= APP_URL ?>/patients/view.php?id=<?= $debtor['patient_id'] ?>" title="پڕۆفایل و پارەدان">
                                <i class="fas fa-money-bill-wave"></i>
                            </a>
                            <button type="button" class="dc-btn dc-btn-history" onclick="openHistoryModal(<?= $debtor['patient_id'] ?>, '<?= htmlspecialchars(addslashes($debtor['patient_name'])) ?>')">
                                <i class="fas fa-history"></i>
                            </button>
                            <button type="button" class="dc-btn dc-btn-mute" title="<?= $isOptOut ? 'گەڕاندنەوە بۆ لیستی پەیوەندی' : 'لابردن لە لیستی پەیوەندی' ?>"
                                    onclick="toggleOptOut(<?= $debtor['patient_id'] ?>, <?= $isOptOut ? 0 : 1 ?>, '<?= htmlspecialchars(addslashes($debtor['patient_name'])) ?>')">
                                <i class="fas <?= $isOptOut ? 'fa-bell' : 'fa-bell-slash' ?>"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Log Call Modal -->
<div class="dc-overlay" id="callModal">
    <div class="dc-modal">
        <div class="dc-modal-header">
            <h3><i class="fas fa-phone-alt" style="color:#27ae60;"></i> تۆمارکردنی پەیوەندی</h3>
            <button class="dc-modal-close" onclick="closeCallModal()">&times;</button>
        </div>
        <div class="dc-modal-body">
            <div style="background:#f8f9fa;padding:12px 16px;border-radius:10px;margin-bottom:18px;">
                <strong id="callModalPatientName" style="color:#1a1a2e;"></strong>
                <div style="font-size:13px;color:#e74c3c;margin-top:4px;">
                    ماوەی قەرز: <strong id="callModalDebtAmount"></strong>
                </div>
                <div style="font-size:14px;margin-top:6px;" id="callModalPhoneWrap">
                    <a id="callModalPhoneLink" href="#" class="phone-link" style="font-size:15px;font-weight:700;">
                        <i class="fas fa-phone"></i> <span id="callModalPhone"></span>
                    </a>
                </div>
            </div>
            <input type="hidden" id="callModalPatientId">

            <div class="dc-form-group">
                <label><i class="fas fa-info-circle"></i> دۆخی پەیوەندی *</label>
                <select id="callStatus" onchange="togglePromiseFields()">
                    <?php foreach ($callStatusLabels as $key => $label): ?>
                    <option value="<?= $key ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Promise details: only for "promised to pay" outcome -->
            <div id="promiseFields" style="display:none; background:#fff8ec; border:1px solid #f5d9a8; border-radius:10px; padding:12px 14px; margin-bottom:16px;">
                <div class="dc-form-group" style="margin-bottom:10px;">
                    <label><i class="fas fa-hand-holding-usd"></i> بڕی بەڵێندراو</label>
                    <input type="number" id="callPromisedAmount" min="0" step="250" placeholder="بڕی پارە...">
                </div>
                <div class="dc-form-group" style="margin-bottom:0;">
                    <label><i class="fas fa-calendar-check"></i> بەرواری بەڵێن</label>
                    <input type="date" id="callPromisedDate" min="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="dc-form-group">
                <label><i class="fas fa-sticky-note"></i> ڕاپۆرتی پەیوەندی</label>
                <textarea id="callReportNote" rows="4" placeholder="نەخۆش چی گوتی؟ بەڵێنی چی دا؟..."></textarea>
            </div>

            <div class="dc-form-group">
                <label><i class="fas fa-calendar-alt"></i> بەرواری پەیگیری داهاتوو</label>
                <input type="date" id="callNextFollowUp" min="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="dc-modal-footer">
            <button type="button" class="dc-btn-cancel" onclick="closeCallModal()">پاشگەزبوونەوە</button>
            <button type="button" class="dc-btn-submit" id="submitCallBtn" onclick="submitCallLog()">
                <i class="fas fa-save"></i> تۆمارکردن
            </button>
        </div>
    </div>
</div>

<!-- Call History Modal -->
<div class="dc-overlay" id="historyModal">
    <div class="dc-modal" style="max-width: 600px;">
        <div class="dc-modal-header">
            <h3><i class="fas fa-history" style="color:#1565c0;"></i> مێژووی پەیوەندیکردن</h3>
            <button class="dc-modal-close" onclick="closeHistoryModal()">&times;</button>
        </div>
        <div class="dc-modal-body">
            <h4 id="historyModalPatientName" style="margin: 0 0 16px; color:#1a1a2e;"></h4>
            <div id="historyContent" class="history-list">
                <div style="text-align:center;padding:30px;color:#999;">
                    <i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const API_URL = '<?= APP_URL ?>/api/call-logs.php';

// Scroll to a follow-up-due patient's row and flash it
function flashRow(patientId) {
    const row = document.getElementById('row-' + patientId);
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.style.transition = 'background 0.3s';
    let on = false, count = 0;
    const timer = setInterval(() => {
        row.style.background = on ? '#fff5f5' : '#ffd6d6';
        on = !on;
        if (++count >= 6) { clearInterval(timer); row.style.background = ''; }
    }, 300);
}

function toggleDueInputs() {
    const filter = document.getElementById('dueFilterSelect')?.value || '';
    const dateInput = document.getElementById('dueDateInput');
    const fromInput = document.getElementById('dueFromInput');
    const toInput = document.getElementById('dueToInput');

    if (dateInput) dateInput.style.display = filter === 'custom_date' ? '' : 'none';
    if (fromInput) fromInput.style.display = filter === 'range' ? '' : 'none';
    if (toInput) toInput.style.display = filter === 'range' ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', toggleDueInputs);

// ── Call Modal ──────────────────────────────────────────
const CALL_STATUS_LABELS = <?= json_encode($callStatusLabels, JSON_UNESCAPED_UNICODE) ?>;
const CALL_STATUS_COLORS = <?= json_encode($callStatusColors) ?>;
let currentCallPatient = 0;
let callSubmitted = false;
let startMode = false;

// ── "Start Calling": the system assigns me the most urgent patient ──
async function startCalling() {
    const fd = new FormData();
    fd.append('action', 'start_call');
    try {
        const r = await fetch(API_URL, { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) { alert(d.message || 'هەڵەیەک ڕوویدا'); return; }
        if (!d.patient) {
            alert(d.message || 'هیچ نەخۆشێک لە لیستدا نەماوە');
            startMode = false;
            return;
        }
        startMode = true;
        openCallModal(d.patient.id, d.patient.name, d.patient.debt, d.patient.phone, true);
        if (document.getElementById('row-' + d.patient.id)) flashRow(d.patient.id);
        refreshLocks();
    } catch (e) {
        alert('هەڵەی تەکنیکی ڕوویدا');
    }
}

// ── Locks: blur patients a colleague is working on right now ──
async function refreshLocks() {
    try {
        const r = await fetch(API_URL + '?action=get_locks');
        const d = await r.json();
        if (!d.success) return;

        document.querySelectorAll('tr.row-locked').forEach(tr => {
            tr.classList.remove('row-locked');
            const b = tr.querySelector('.lock-badge');
            if (b) b.remove();
        });

        (d.locks || []).forEach(l => {
            if (parseInt(l.locked_by) === parseInt(d.me)) return;
            const row = document.getElementById('row-' + l.patient_id);
            if (!row) return;
            row.classList.add('row-locked');
            const nameCell = row.querySelector('.patient-name');
            if (nameCell && !nameCell.querySelector('.lock-badge')) {
                const badge = document.createElement('span');
                badge.className = 'lock-badge';
                badge.innerHTML = '<i class="fas fa-lock"></i> ' + (l.locked_by_name || '') + ' ئێستا خەریکی ئەم نەخۆشەیە';
                nameCell.appendChild(badge);
            }
        });
    } catch (e) { /* silent */ }
}
setInterval(refreshLocks, 15000);
document.addEventListener('DOMContentLoaded', refreshLocks);

// ── Instant row update after saving a call (no page refresh) ──
function updateRowAfterCall(patientId, status, note, promisedDate) {
    const row = document.getElementById('row-' + patientId);
    if (!row) return;

    row.classList.remove('row-follow-up-due', 'row-overdue-call', 'row-never-called', 'row-normal', 'row-promised', 'row-locked');
    row.classList.add(status === 'promised_to_pay' ? 'row-promised' : 'row-called-today');

    const nameCell = row.querySelector('.patient-name');
    if (nameCell) {
        nameCell.querySelectorAll('.fu-name-badge, .bp-name-badge, .ap-name-badge, .lock-badge').forEach(b => b.remove());
        if (status === 'promised_to_pay' && promisedDate) {
            const badge = document.createElement('span');
            badge.className = 'ap-name-badge';
            badge.innerHTML = '<i class="fas fa-handshake"></i> بەڵێن: ' + promisedDate;
            nameCell.appendChild(badge);
        }
    }

    const lastCallCell = row.querySelector('.cell-last-call');
    if (lastCallCell) {
        const label = CALL_STATUS_LABELS[status] || status;
        const color = CALL_STATUS_COLORS[status] || '#999';
        const now = new Date();
        const dateStr = now.getFullYear() + '/' + String(now.getMonth() + 1).padStart(2, '0') + '/' + String(now.getDate()).padStart(2, '0');
        lastCallCell.innerHTML = '<span class="call-badge" style="background:' + color + ';">' + label + '</span>'
            + '<div style="font-size:11px;color:#999;margin-top:3px;">' + dateStr + '</div>';
    }

    const noteCell = row.querySelector('.cell-note');
    if (noteCell && note) {
        const div = document.createElement('div');
        div.className = 'call-note-preview';
        div.title = note;
        div.textContent = note;
        noteCell.innerHTML = '';
        noteCell.appendChild(div);
    }
}

<?php if ($isAdmin): ?>
// ── Admin live monitoring panel ──
async function refreshLiveStatus() {
    try {
        const r = await fetch(API_URL + '?action=live_status');
        const d = await r.json();
        if (!d.success) return;

        const now = document.getElementById('liveNow');
        if ((d.working_now || []).length === 0) {
            now.innerHTML = '<span class="dc-live-empty"><i class="fas fa-mug-hot"></i> ئێستا کەس خەریکی پەیوەندی نییە</span>';
        } else {
            now.innerHTML = d.working_now.map(w =>
                '<span class="dc-live-chip now"><i class="fas fa-headset"></i> <b>' + (w.locked_by_name || '?') + '</b>'
                + ' خەریکی (' + (w.patient_name || '?') + ')ـە'
                + ' <span style="color:#aaa;">— ' + w.minutes + ' خولەک</span></span>'
            ).join('');
        }

        const today = document.getElementById('liveToday');
        if ((d.per_user || []).length === 0) {
            today.innerHTML = '<span class="dc-live-empty">ئەمڕۆ هێشتا هیچ پەیوەندییەک نەکراوە</span>';
        } else {
            today.innerHTML = d.per_user.map(u =>
                '<span class="dc-live-chip"><i class="fas fa-user"></i> ' + (u.full_name || '?')
                + ' — ' + u.calls + ' پەیوەندی · ' + u.answered + ' وەڵام · ' + u.promises + ' بەڵێن · ' + u.paid + ' پارەدان</span>'
            ).join('');
        }

        const t = d.totals || {};
        document.getElementById('liveTotals').textContent =
            'ئەمڕۆ: ' + (t.calls || 0) + ' پەیوەندی · ' + (t.answered || 0) + ' وەڵام · ' + (t.promises || 0) + ' بەڵێن';
    } catch (e) { /* silent */ }
}
setInterval(refreshLiveStatus, 20000);
document.addEventListener('DOMContentLoaded', refreshLiveStatus);
<?php endif; ?>

function openCallModal(patientId, patientName, debtAmount, phone, fromQueue) {
    currentCallPatient = patientId;
    callSubmitted = false;
    if (!fromQueue) {
        startMode = false;
        // Try to claim the patient; back off if a colleague already has them
        const fd = new FormData();
        fd.append('action', 'lock_patient');
        fd.append('patient_id', patientId);
        fetch(API_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.success) {
                    closeCallModal();
                    alert(d.message || 'هاوکارێکت ئێستا خەریکی ئەم نەخۆشەیە');
                    refreshLocks();
                }
            })
            .catch(() => {});
    }
    const phoneWrap = document.getElementById('callModalPhoneWrap');
    if (phone) {
        phoneWrap.style.display = '';
        document.getElementById('callModalPhone').textContent = phone;
        document.getElementById('callModalPhoneLink').href = 'tel:' + phone;
    } else {
        phoneWrap.style.display = 'none';
    }
    document.getElementById('callModalPatientId').value = patientId;
    document.getElementById('callModalPatientName').textContent = patientName;
    document.getElementById('callModalDebtAmount').textContent = Number(debtAmount).toLocaleString('en');
    document.getElementById('callStatus').value = 'no_answer';
    document.getElementById('callReportNote').value = '';
    document.getElementById('callNextFollowUp').value = '';
    document.getElementById('callPromisedAmount').value = '';
    document.getElementById('callPromisedDate').value = '';
    togglePromiseFields();
    document.getElementById('callModal').classList.add('show');
}

// Promise inputs appear only when the outcome is "promised to pay"
function togglePromiseFields() {
    const isPromise = document.getElementById('callStatus').value === 'promised_to_pay';
    document.getElementById('promiseFields').style.display = isPromise ? '' : 'none';
}

// Remove/restore a patient from the daily call list
async function toggleOptOut(patientId, optOut, patientName) {
    const msg = optOut
        ? 'دڵنیایت لە لابردنی "' + patientName + '" لە لیستی پەیوەندی؟'
        : 'گەڕاندنەوەی "' + patientName + '" بۆ لیستی پەیوەندی؟';
    if (!confirm(msg)) return;

    const fd = new FormData();
    fd.append('action', 'toggle_optout');
    fd.append('patient_id', patientId);
    fd.append('opt_out', optOut);
    try {
        const r = await fetch(API_URL, { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'هەڵەیەک ڕوویدا');
        }
    } catch (e) {
        alert('هەڵەی تەکنیکی ڕوویدا');
    }
}

function closeCallModal() {
    document.getElementById('callModal').classList.remove('show');
    // Cancelled without saving: free the patient for colleagues
    if (currentCallPatient && !callSubmitted) {
        const fd = new FormData();
        fd.append('action', 'release_lock');
        fd.append('patient_id', currentCallPatient);
        fetch(API_URL, { method: 'POST', body: fd }).catch(() => {});
    }
    currentCallPatient = 0;
    startMode = false;
}

async function submitCallLog() {
    const btn = document.getElementById('submitCallBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> تۆمارکردن...';

    const formData = new FormData();
    formData.append('action', 'log_call');
    formData.append('patient_id', document.getElementById('callModalPatientId').value);
    formData.append('call_status', document.getElementById('callStatus').value);
    formData.append('report_note', document.getElementById('callReportNote').value);
    formData.append('next_follow_up', document.getElementById('callNextFollowUp').value
        || (document.getElementById('callStatus').value === 'promised_to_pay'
            ? document.getElementById('callPromisedDate').value : ''));
    formData.append('promised_amount', document.getElementById('callPromisedAmount').value);
    formData.append('promised_date', document.getElementById('callPromisedDate').value);

    try {
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            callSubmitted = true;
            const savedPatient = currentCallPatient;
            const savedStatus = document.getElementById('callStatus').value;
            const savedNote = document.getElementById('callReportNote').value;
            const savedPromiseDate = document.getElementById('callPromisedDate').value;
            const continueQueue = startMode;

            if (typeof showToast === 'function') {
                showToast('success', data.message);
            }

            // Update the row instantly — no page refresh (colleagues' pages
            // pick it up via the 15s lock poll / their own refresh)
            updateRowAfterCall(savedPatient, savedStatus, savedNote, savedPromiseDate);
            closeCallModal();

            // Start-work mode: go straight to the next patient in the queue
            if (continueQueue) {
                setTimeout(startCalling, 600);
            }
        } else {
            if (typeof showAlert === 'function') {
                showAlert('error', data.message);
            } else {
                alert(data.message);
            }
        }
    } catch (error) {
        console.error(error);
        alert('هەڵەی تەکنیکی ڕوویدا');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> تۆمارکردن';
    }
}

// ── History Modal ───────────────────────────────────────
function openHistoryModal(patientId, patientName) {
    document.getElementById('historyModalPatientName').textContent = patientName;
    document.getElementById('historyContent').innerHTML = '<div style="text-align:center;padding:30px;color:#999;"><i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...</div>';
    document.getElementById('historyModal').classList.add('show');
    loadHistory(patientId);
}

function closeHistoryModal() {
    document.getElementById('historyModal').classList.remove('show');
}

async function loadHistory(patientId) {
    try {
        const response = await fetch(`${API_URL}?action=get_history&patient_id=${patientId}`);
        const data = await response.json();

        if (!data.success) {
            document.getElementById('historyContent').innerHTML = '<div style="text-align:center;padding:30px;color:#e74c3c;">' + data.message + '</div>';
            return;
        }

        if (data.data.length === 0) {
            document.getElementById('historyContent').innerHTML = '<div style="text-align:center;padding:30px;color:#999;"><i class="fas fa-phone-slash" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3;"></i>هیچ پەیوەندییەک تۆمار نەکراوە</div>';
            return;
        }

        let html = '';
        data.data.forEach(function(log) {
            const color = data.colors[log.call_status] || '#999';
            const label = data.labels[log.call_status] || log.call_status;
            const date = new Date(log.call_date);
            const dateStr = date.toLocaleDateString('en-GB') + ' ' + date.toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit'});

            html += '<div class="history-item">';
            html += '<div class="history-meta">';
            html += '<span class="call-badge" style="background:' + color + ';">' + label + '</span>';
            html += '<span class="history-date">' + dateStr + '</span>';
            html += '</div>';
            if (log.called_by_name) {
                html += '<div class="history-caller"><i class="fas fa-user"></i> ' + log.called_by_name + '</div>';
            }
            if (log.report_note) {
                html += '<div class="history-note">' + escapeHtml(log.report_note) + '</div>';
            }
            if (log.next_follow_up) {
                html += '<div style="font-size:12px;color:#3498db;margin-top:4px;"><i class="fas fa-calendar-check"></i> پەیگیری: ' + log.next_follow_up + '</div>';
            }
            html += '</div>';
        });

        document.getElementById('historyContent').innerHTML = html;
    } catch (error) {
        console.error(error);
        document.getElementById('historyContent').innerHTML = '<div style="text-align:center;padding:30px;color:#e74c3c;">هەڵەی تەکنیکی</div>';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals on overlay click
document.getElementById('callModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCallModal();
});
document.getElementById('historyModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeHistoryModal();
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCallModal();
        closeHistoryModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
