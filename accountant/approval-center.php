<?php
/**
 * Approval Center — the one accounting queue.
 * ناوەندی پەسەندکردن — تاقە شوێنی چاوەڕوانی و پەسەندکردنی دارایی
 *
 * Replaces accountant/receiving.php + accountant/pending-reports.php (both
 * now 302 stubs). Server-side renders the shell + ApprovalQueue::counts()
 * for instant tab badges; everything else (row lists, drawer detail, the
 * write actions) is fetched client-side from api/approval-center.php +
 * api/finance-actions.php by assets/js/approval-center.js.
 *
 * The page carries TWO tabs: «هەموو داواکاریەکان» (every live request from
 * every source, grouped per patient) and «پەسەندکراو» (what has already been
 * approved). The old per-kind tabs are gone.
 *
 * A پەسەندکراو row can be taken back: «کردنەوە بۆ دەستکاری» returns it to the
 * live queue and «سڕینەوە» closes it out, both after reversing its money. The
 * confirm dialog lists the transactions first (?action=reversal_preview) and
 * classes/ApprovalReversal.php does the reversing.
 *
 * ?q= is still honored on load so Notification links
 * (/accountant/approval-center.php?tab=xray&q=...) land pre-filtered; their
 * now-retired ?tab= values fall back to the one unified queue with the جۆر
 * combobox pre-set to that kind. ?kind= sets it directly.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/ApprovalQueue.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$isAccountant = $auth->isAccountant();
$isAdmin = $auth->isAdmin();

if (!$isAccountant && !$isAdmin) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();

// پەسەندکردنی خۆکاری شەوانە — fallback for a night the scheduled task did not
// run (cron/auto-approve.php). One settings read when nothing is due.
require_once __DIR__ . '/../classes/AutoApproval.php';
try {
    AutoApproval::runIfDue();
} catch (Throwable $e) {
    error_log('AutoApproval fallback: ' . $e->getMessage());
}

$queue = new ApprovalQueue();
$initialCounts = $queue->counts();

// Doctor filter <select> — population copied from admin/invoices.php:157
// (the accounting-adjacent page that already does this exact query).
$doctors = $db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name");

// Two tabs only: everything still waiting for a decision, and everything
// already approved. The old per-kind tabs (invoices/payments/installments/
// xray/station) are gone — every one of those requests now lands in the one
// "هەموو داواکاری" feed — so the ?tab= links still out there in
// Notification.php and the accountant dashboard fall back to it, keeping
// their ?q= pre-filter.
$validTabs = ['all', 'approved'];
$rawTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
$initialTab = in_array($rawTab, $validTabs, true) ? $rawTab : 'all';
$initialQ = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
// The morning notification links straight to «🤖 خۆکار» on پەسەندکراو.
$initialApproval = in_array($_GET['approval'] ?? '', ['auto', 'manual'], true) ? $_GET['approval'] : '';

// جۆر combobox — narrows the one feed to a single source. Labels match the
// جۆر badges the table draws (KIND_META in assets/js/approval-center.js);
// the values are the ones ApprovalQueue::KINDS accepts.
$kindOptions = [
    'invoice'     => 'پسووڵە',
    'archwork'    => 'کارە ئەنجامدراوەکان',
    'payment'     => 'پارەدان',
    'installment' => 'قیست',
    'xray'        => 'ئێکسڕەی',
    'station'     => 'ژووری چاوەڕوانی',
];

// A retired per-kind ?tab= (the links still living in Notification.php and the
// accountant dashboard) now lands on the unified feed pre-filtered to that
// kind, instead of dropping the caller into an unfiltered list. ?kind= wins
// when both are given.
$retiredTabKinds = [
    'invoices'     => 'invoice',
    'payments'     => 'payment',
    'installments' => 'installment',
    'xray'         => 'xray',
    'station'      => 'station',
];
$rawKind = is_string($_GET['kind'] ?? null) ? $_GET['kind'] : '';
$initialKind = isset($kindOptions[$rawKind]) ? $rawKind : ($retiredTabKinds[$rawTab] ?? '');

$pageTitle = 'ناوەندی پەسەندکردن';
$breadcrumb = [
    ['title' => 'دارایی'],
    ['title' => 'ناوەندی پەسەندکردن'],
];

require_once __DIR__ . '/../includes/header.php';
?>
<!-- Invoice and payment drawers reuse their canonical receipt layouts so the
     documents read the same on the doctor's and accountant's screens. -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/invoice-pane.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/invoice-pane.css') ?>">
<style>
.ac-container { max-width:1500px; margin:0 auto; }
.ac-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px; }
.ac-title { display:flex; align-items:center; gap:12px; }
.ac-title h2 { margin:0; font-size:20px; color:#1a1a1a; display:flex; align-items:center; gap:10px; }
.ac-title .icon { width:44px; height:44px; background:linear-gradient(135deg,#0078d4,#0063b1); border-radius:12px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:19px; box-shadow:0 4px 12px rgba(0,120,212,0.3); }
.live-dot { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:600; color:#107c10; background:#dff6dd; padding:4px 10px; border-radius:12px; }
.live-dot::before { content:''; width:8px; height:8px; border-radius:50%; background:#107c10; animation:acPulse 1.6s infinite; }
@keyframes acPulse { 0%,100% { opacity:1; } 50% { opacity:0.25; } }
.ac-actions { display:flex; align-items:center; gap:10px; }
.btn-sound { width:38px; height:38px; border-radius:10px; border:1.5px solid #e0e0e0; background:#fff; cursor:pointer; font-size:15px; color:#0078d4; display:flex; align-items:center; justify-content:center; transition:all .2s; }
.btn-sound.muted { color:#999; opacity:.7; }
.ac-new-badge { background:linear-gradient(135deg,#c50f1f,#a00d19); color:#fff; border-radius:14px; padding:5px 12px; font-size:12px; font-weight:700; display:none; }

.kind-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.kind-tab { padding:9px 18px; border-radius:20px; border:1.5px solid #e0e0e0; font-size:12.5px; font-weight:600; cursor:pointer; color:#666; background:#fff; transition:all .2s; display:flex; align-items:center; gap:7px; font-family:inherit; }
.kind-tab .kt-count { background:rgba(0,0,0,0.1); border-radius:10px; padding:1px 8px; font-size:11px; }
.kind-tab.active { background:linear-gradient(135deg,#0078d4,#0063b1); color:#fff; border-color:#0078d4; }
.kind-tab.active .kt-count { background:rgba(255,255,255,0.25); }

.filters-card { background:#fff; border-radius:12px; padding:14px 16px; margin-bottom:16px; box-shadow:0 2px 12px rgba(0,0,0,0.08); display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.ac-search { flex:1; min-width:220px; position:relative; }
.ac-search input { width:100%; padding:9px 36px 9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; }
.ac-search input:focus { border-color:#0078d4; outline:none; box-shadow:0 0 0 3px rgba(0,120,212,0.1); }
.ac-search i { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#aaa; font-size:13px; }
.filters-card select, .filters-card input[type="date"] { padding:8px 11px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:12.5px; font-family:inherit; background:#fcfcfd; }
.scope-toggle { display:flex; border:1.5px solid #e0e0e0; border-radius:8px; overflow:hidden; }
.scope-toggle button { padding:8px 14px; border:none; background:#fff; cursor:pointer; font-size:12px; font-weight:600; color:#666; font-family:inherit; }
.scope-toggle button.active { background:#0078d4; color:#fff; }

/* ڕیزبەندی — one button that flips between نوێ←کۆن and کۆن←نوێ. Sized like the
   other filter controls next to it rather than like a primary action. */
.ac-sort-btn {
    display:flex; align-items:center; gap:7px;
    padding:8px 13px; border:1.5px solid #e0e0e0; border-radius:8px;
    background:#fcfcfd; color:#555; cursor:pointer;
    font-family:inherit; font-size:12.5px; font-weight:600; white-space:nowrap;
    transition:all .2s;
}
.ac-sort-btn:hover { border-color:#0078d4; color:#0078d4; }
.ac-sort-btn i { color:#0078d4; font-size:12px; }

/* Pager under the table. Hidden entirely while there is nothing to page. */
.ac-pager {
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:11px 14px; border-top:1px solid #edf0f3; background:#fbfcfd;
}
.ac-pager[hidden] { display:none; }
.ac-pager-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 14px; border:1.5px solid #e0e0e0; border-radius:8px;
    background:#fff; color:#0063b1; cursor:pointer;
    font-family:inherit; font-size:12.5px; font-weight:600; white-space:nowrap;
    transition:all .2s;
}
.ac-pager-btn:hover:not(:disabled) { border-color:#0078d4; background:#f2f8fd; }
.ac-pager-btn:disabled { color:#b6bfc8; cursor:default; opacity:.65; }
.ac-pager-info { color:#647487; font-size:12px; font-weight:600; }

/* Custody balance and the inline source control beside received. */
.ac-amanat { border:1.5px solid #9fd3b4; background:#f1faf4; border-radius:10px; padding:11px 13px; margin-bottom:12px; }
.ac-amanat.is-warn { border-color:#f4b46a; background:#fffaeb; }
.ac-amanat-head { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:700; color:#1b5e20; }
.ac-amanat.is-warn .ac-amanat-head { color:#93370d; }
.ac-amanat-head i { font-size:17px; }
.ac-amanat-head strong { margin-inline-start:auto; font-size:19px; direction:ltr; }
.ac-amanat-list { list-style:none; margin:8px 0 0; padding:0; display:flex; flex-direction:column; gap:4px; }
.ac-amanat-list li { display:flex; flex-wrap:wrap; align-items:baseline; gap:3px 12px; padding:5px 9px; border-radius:6px; background:rgba(255,255,255,.75); font-size:12.5px; color:#344054; }
.ac-amanat-list b { font-size:13.5px; color:#1b5e20; direction:ltr; }
.ac-amanat-list small { color:#667085; font-size:11.5px; }
.ac-amanat-warn { margin-top:8px; padding:7px 10px; border-radius:7px; background:#fef3f2; color:#b42318; font-size:13px; font-weight:700; line-height:1.7; }
.ac-amanat-note { margin-top:7px; font-size:12px; color:#475467; line-height:1.7; }
.ac-inline-advance { margin: 3px 0 8px; padding: 8px; border: 1px solid #a9d4b9; border-radius: 7px; background: #f1faf4; }
.ac-inline-advance label { display: block; margin-bottom: 5px; font-size: 12px; font-weight: 700; color: #1b5e20; }
.ac-inline-advance label small { font-weight: 400; }
.ac-inline-advance-controls { display: flex; gap: 5px; align-items: center; }
.ac-inline-advance-controls output { display: block; flex: 1; min-width: 0; padding: 5px 7px; border: 1px solid #9fc7ae; border-radius: 4px; background: #fff; color: #14713b; text-align: center; font: inherit; font-weight: 700; direction: ltr; }
.ac-inline-advance-controls .btn { padding: 5px 8px; white-space: nowrap; font-size: 12px; }
.ac-inline-advance-controls .is-selected { color: #fff; background: #14713b; border-color: #14713b; }
.ac-inline-advance-controls [hidden] { display: none; }
.ac-inline-advance-note { margin-top: 4px; font-size: 11px; color: #14713b; line-height: 1.6; }
#acRecvInput { color: #14713b; }
.ac-amanat-act { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-top:9px; }
.ac-amanat-input { width:150px; padding:7px 10px; border:1.5px solid #c4d0dc; border-radius:8px; font:inherit; font-size:14px; font-weight:700; direction:ltr; text-align:center; }
/* وەرگرتنی ئامانەت — the patient picker inside the advance dialog. A short
   scrolling list rather than a datalist, so each row can carry the patient's
   phone and the balance they already have sitting with the clinic. */
.ac-advance-results { max-height:190px; overflow-y:auto; margin:-4px 0 10px; }
.ac-advance-results:empty { margin:0; }
.ac-advance-hit {
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    width:100%; padding:8px 10px; border:1px solid #e6e9ee; border-radius:8px;
    background:#fff; cursor:pointer; font-family:inherit; font-size:12.5px;
    text-align:right; margin-bottom:5px;
}
.ac-advance-hit:hover { border-color:#0078d4; background:#f5faff; }
.ac-advance-hit .hit-main { display:flex; flex-direction:column; gap:2px; min-width:0; }
.ac-advance-hit .hit-name { font-weight:700; color:#1a1a1a; }
.ac-advance-hit .hit-meta { color:#7b8997; font-size:10.5px; direction:ltr; text-align:right; }
.ac-advance-hit .hit-bal { color:#1b5e20; font-weight:700; white-space:nowrap; font-size:11.5px; }
.ac-advance-note { color:#888; font-size:11.5px; padding:6px 2px; }

.table-card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; }
.table-scroll { overflow-x:auto; }
.ac-table { width:100%; border-collapse:collapse; font-size:13px; }
.ac-table th { background:#f8f9fa; padding:12px 14px; text-align:right; font-weight:600; color:#333; border-bottom:2px solid #e0e0e0; white-space:nowrap; }
.ac-table td { padding:11px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.ac-table tr:hover td { background:#f9fbff; }
.ac-table tr.row-new td { animation:acRowFlash 2.5s ease; }
.ac-table tr.ac-row-clickable { cursor:pointer; }
@keyframes acRowFlash { 0% { background:#fff4ce; } 100% { background:transparent; } }
.ac-table tr.ac-patient-group-row td {
    padding: 9px 13px;
    border-top: 2px solid #b9d8f2;
    border-bottom: 1px solid #d7e7f5;
    background: #edf6fd;
}
.ac-table tr.ac-patient-group-row:hover td { background: #edf6fd; }
.ac-patient-group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 7px 14px;
}
.ac-patient-group-person { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; color: #123b5d; }
.ac-patient-group-person i { color: #0078d4; }
.ac-patient-group-phone { direction: ltr; color: #647487; font-size: 11px; font-weight: 500; }
.ac-patient-group-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; color: #5e7184; font-size: 10.5px; }
.ac-patient-group-count { padding: 2px 8px; border-radius: 999px; background: #d7ebfb; color: #075f9d; font-weight: 800; }
.ac-table tr.ac-group-child td:first-child { box-shadow: inset -3px 0 0 #d7eafa; }
.kind-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:14px; font-size:11px; font-weight:600; white-space:nowrap; }
.status-chip { display:inline-flex; align-items:center; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.chip-open { background:#fff4ce; color:#8a5a00; }
.chip-seen { background:#deecf9; color:#0063b1; }
.chip-done { background:#dff6dd; color:#107c10; }
.chip-bad { background:#fde7e9; color:#c50f1f; }
.chip-neutral { background:#f0f0f0; color:#666; }
.ac-promise { font-size:11px; color:#888; margin-top:3px; }
.btn-eye-sm, .btn-eye { min-width:30px; height:30px; padding:0 10px; display:inline-flex; align-items:center; justify-content:center; gap:5px; border-radius:8px; border:none; cursor:pointer; background:linear-gradient(135deg,#0078d4,#0063b1); color:#fff; font-size:12px; transition:all .2s; }
.btn-eye-sm:hover, .btn-eye:hover { transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,120,212,0.35); }

/* ── Row actions on the پەسەندکراو tab ──
   دەستکاری / سڕینەوە sit next to the eye button on an already-approved row.
   Tinted outlines rather than solid fills: they must read as the secondary
   actions they are next to «بینین», while still being told apart at a glance
   (amber = takes it back for editing, red = reverses and closes it). */
.ac-row-actions { display:flex; align-items:center; gap:5px; }
.ac-edit-case { text-decoration:none; white-space:nowrap; }
.btn-row-act {
    width:30px; height:30px; padding:0;
    display:inline-flex; align-items:center; justify-content:center;
    border-radius:8px; cursor:pointer; font-size:11.5px; font-family:inherit;
    transition:all .2s;
}
.btn-row-act.is-edit { border:1px solid #f0d8ae; background:#fff7ea; color:#a86500; }
.btn-row-act.is-edit:hover { background:#ffeed3; border-color:#d9a445; }
.btn-row-act.is-del { border:1px solid #f4c7cc; background:#fdeff1; color:#b3101e; }
/* 🤖 خۆکار / ✋ دەستی under an approved invoice's status chip. */
.ac-approval-tag { display:inline-flex; align-items:center; gap:3px; margin-top:4px; padding:1px 7px; border-radius:999px; font-size:10.5px; font-weight:700; white-space:nowrap; }
.ac-approval-tag.is-auto { background:#ede9fe; color:#5b21b6; border:1px solid #ddd6fe; }
.ac-approval-tag.is-manual { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
/* «لەلایەن …» under the doctor: an invoice someone else filed for him. */
.ac-made-by { margin-top:3px; font-size:10.5px; font-weight:700; color:#9a3412; white-space:nowrap; }
.ac-made-by i { opacity:.8; }
.btn-row-act.is-del:hover { background:#fbdfe3; border-color:#dc6a76; }

/* ── «چی هەڵدەوەشێتەوە؟» — the reversal confirm.
   Same fw-modal shell as the وەرگرتنی پارە confirm above, one step wider
   because its job is to LIST the transactions that are about to be reversed
   (api/approval-center.php?action=reversal_preview) before anything is
   written. */
.fw-modal.is-wide { width:min(600px,calc(100vw - 28px)); }
.ac-rev-head { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin-bottom:12px; }
.ac-rev-head > div { padding:7px 9px; border:1px solid #e3e8ee; border-radius:8px; background:#f8fafc; min-width:0; }
.ac-rev-head span { display:block; color:#7b8997; font-size:9.5px; }
.ac-rev-head strong { display:block; margin-top:2px; color:#1b2b3a; font-size:12.5px; overflow-wrap:anywhere; }
.ac-rev-note {
    margin-bottom:10px; padding:8px 10px; border-radius:6px;
    background:#f3f8fd; border-right:3px solid #7eb1dc;
    color:#2b4a68; font-size:11.5px; line-height:1.7;
}
.ac-rev-warn {
    margin-bottom:10px; padding:8px 10px; border-radius:6px;
    background:#fff8ec; border-right:3px solid #e0a53b;
    color:#7a5a12; font-size:11.5px; line-height:1.7;
}
.ac-rev-note:empty, .ac-rev-warn:empty { display:none; }
.ac-rev-lines { margin-bottom:10px; }
.ac-rev-line {
    display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
    padding:8px 10px; margin-bottom:6px;
    border:1px solid #e6eaef; border-radius:8px; background:#fbfcfd;
}
.ac-rev-line .rl-main { min-width:0; }
.ac-rev-line .rl-label { font-weight:700; font-size:12.5px; color:#1b2b3a; }
.ac-rev-line .rl-note { margin-top:2px; color:#7b8997; font-size:10.5px; line-height:1.6; }
.ac-rev-line .rl-amt { direction:ltr; white-space:nowrap; font-weight:800; font-size:13px; color:#33475b; }
.ac-rev-line.is-cash_out { border-right:3px solid #c50f1f; }
.ac-rev-line.is-cash_out .rl-amt { color:#c50f1f; }
.ac-rev-line.is-debt_up { border-right:3px solid #b56a00; }
.ac-rev-line.is-debt_up .rl-amt { color:#b56a00; }
.ac-rev-line.is-debt_down { border-right:3px solid #107c10; }
.ac-rev-line.is-debt_down .rl-amt { color:#107c10; }
.ac-rev-line.is-doc { border-right:3px solid #b9c9d8; }
.ac-rev-total {
    display:flex; justify-content:space-between; align-items:center; gap:10px;
    padding:9px 11px; margin-bottom:12px; border-radius:8px;
    background:#fdeff1; border:1px solid #f4c7cc; color:#8a0f1a;
    font-size:12px; font-weight:700;
}
.ac-rev-total strong { direction:ltr; font-size:16px; }
.ac-rev-total:empty { display:none; }
.ac-rev-loading { padding:22px; text-align:center; color:#8a97a5; font-size:12.5px; }
.ac-empty { text-align:center; padding:50px 20px; color:#aaa; }
.ac-empty i { font-size:34px; margin-bottom:10px; display:block; }
.ac-loading { text-align:center; padding:40px; color:#888; }

/* ── Drawer (tp-overlay convention — NOT .dialog-overlay) ──
   Explicit right:0 absolute positioning (not justify-content:flex-end) —
   flexbox "end" in a dir="rtl" container resolves to the LEFT edge, which
   is not what a right-anchored RTL side panel wants. */
.ac-drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:3200; display:none; }
.ac-drawer-overlay.open { display:block; }
.ac-drawer { position:absolute; top:0; right:0; bottom:0; background:#fff; width:560px; max-width:100%; box-shadow:-16px 0 48px rgba(0,0,0,0.3); display:flex; flex-direction:column; overflow:hidden; }
@media (max-width:640px) { .ac-drawer { width:100%; } }

/* ── Centered modal shell. Invoices use the full width, while payment and
   X-ray reviews add their own compact width class below. Installment and
   station details keep the original right-side drawer. */
.ac-drawer-overlay.open.is-modal { display:flex; align-items:center; justify-content:center; padding:20px; box-sizing:border-box; overflow:auto; }
.ac-drawer.is-modal {
    position:relative; top:auto; right:auto; bottom:auto;
    width:min(1100px,95vw); max-width:min(1100px,95vw);
    max-height:90vh; border-radius:14px;
    box-shadow:0 24px 70px rgba(0,0,0,.32);
    margin:auto;
}
@media (max-width:640px) {
    .ac-drawer.is-modal { width:100%; max-width:100%; max-height:100%; height:100%; border-radius:0; }
    .ac-drawer-overlay.open.is-modal { padding:0; }
}
/* Treatments table inside the modal keeps its own horizontal scroll rather
   than ever forcing the modal itself wider than viewport — unconditional
   (not gated to a breakpoint) so it also covers in-between widths like the
   ~800px case named in the layout spec, not just sub-900px. */
.ac-drawer.is-modal .table-container { overflow-x:auto; }

.ac-drawer-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #e3e6ea; flex-shrink:0; }
.ac-drawer-head h3 { margin:0; font-size:15px; display:flex; align-items:center; gap:8px; }
.ac-drawer-close { background:#f2f4f6; border:none; width:32px; height:32px; border-radius:50%; cursor:pointer; color:#555; font-size:14px; flex-shrink:0; }
.ac-drawer-body { padding:16px 18px; overflow-y:auto; flex:1; min-width:0; }
.ac-head-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:14px; }
.ac-head-item { background:#f8f9fa; border-radius:10px; padding:9px 12px; }
.ac-head-item .lbl { font-size:10px; color:#888; margin-bottom:3px; }
.ac-head-item .val { font-size:13px; font-weight:600; color:#222; }
.ac-statement-link { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#0078d4; text-decoration:none; font-weight:600; white-space:nowrap; }
.ac-statement-link:hover { text-decoration:underline; }
.ac-section-title { font-size:13px; font-weight:700; color:#0063b1; margin:16px 0 8px; display:flex; align-items:center; gap:7px; }
/* Case financial summary — original grid baseline, UNSCOPED, restored
   verbatim (this is what every other drawer kind's finBlock() still
   renders in the untouched 560px side drawer; changing it unscoped was
   review finding #1 — it silently restyled the payment/installment/xray/
   station drawers too, which the spec requires left alone). */
.ac-fin { display:grid; grid-template-columns:repeat(auto-fit,minmax(105px,1fr)); gap:8px; margin-bottom:8px; }
.ac-fin-box { border-radius:10px; padding:10px; text-align:center; }
.ac-fin-box .n { font-size:15px; font-weight:700; direction:ltr; }
.ac-fin-box .l { font-size:10px; margin-top:3px; }
/* Invoice modal ONLY — compact pill chips instead of the grid above. At
   this modal's width (up to 1100px) that grid resolves to a handful of
   very wide equal 1fr tracks — with 5 boxes that reads as full-width
   stacked colour bars, the reported defect. A wrapping flex row of
   auto-sized chips can't do that: each chip is only ever as wide as its
   own label+number, extras simply wrap to a new line. Scoped to
   .ac-drawer.is-modal so the narrow side-drawer (where the grid above
   reads fine) is untouched. */
.ac-drawer.is-modal .ac-fin { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
.ac-drawer.is-modal .ac-fin-box { display:inline-flex; align-items:center; gap:7px; flex:0 0 auto; border-radius:999px; padding:6px 13px; white-space:nowrap; }
.ac-drawer.is-modal .ac-fin-box .n { font-size:13px; font-weight:800; direction:ltr; }
.ac-drawer.is-modal .ac-fin-box .l { font-size:10.5px; font-weight:600; opacity:.85; margin-top:0; }

/* ── Invoice modal header: title row (.ac-drawer-head, unchanged markup)
   plus a persistent, non-scrolling subheader row (#acDrawerSub) for patient
   identity + کشف حساب + مەبەست. Populated only for the invoice kind
   (assets/js/approval-center.js); every other drawer kind leaves it empty,
   and :empty collapses it so their narrow side-drawer is pixel-identical to
   before. Living OUTSIDE #acDrawerBody (the scrolling region) is what fixes
   the reported collision — it used to be the first line of scrolling body
   content, indistinguishable from the receipt below it. */
.ac-drawer-sub {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 8px 16px;
    padding: 10px 18px;
    border-bottom: 1px solid #e3e6ea;
    background: #fafbfc;
    flex-shrink: 0;
}
.ac-drawer-sub:empty { display: none; padding: 0; border-bottom: 0; }
/* One border between the two header rows and the body, not one under each. */
.ac-drawer.is-modal .ac-drawer-head { border-bottom: 0; padding-bottom: 8px; }
.ac-dsub-patient { min-width: 0; }
.ac-dsub-patient strong { font-size: 14px; color: #1a1a1a; }
.ac-dsub-code { color: #999; font-size: 11px; }
.ac-dsub-phone { font-size: 11.5px; color: #888; margin-top: 2px; }
.ac-dsub-side { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; min-width: 0; }
.ac-dsub-intent { font-size: 11.5px; color: #888; }

/* ── Invoice modal footer: RTL two columns under the treatments table —
   right column is تێبینی/تێبینی ژمێریاری (label above a full-width
   textarea), left column is the totals stack constrained to a sane width
   instead of stretching to half the (up to 1100px) modal the way the plain
   invoice-pane.css .vt-foot flex-basis math produces at this container
   width. Scoped under .is-modal + .vt-pane, page-scoped CSS layered on top
   of the shared invoice-pane.css rather than edits to that shared file. */
.ac-drawer.is-modal .vt-pane .vt-foot { flex-wrap: wrap; align-items: flex-start; gap: 22px; }
.ac-drawer.is-modal .vt-pane .vt-foot-side { flex: 1 1 300px; min-width: 240px; }
.ac-drawer.is-modal .vt-pane .vt-foot-totals { flex: 0 1 380px; width: 380px; max-width: 100%; min-width: 260px; }
.ac-inv-note { margin-bottom: 12px; font-size: 12.5px; line-height: 1.6; }
.ac-inv-note-label { font-weight: 700; color: #1b2b3a; }
.ac-inv-note-val { color: #3f4a56; }
/* Private doctor -> accounting remark. Tinted so it reads as an internal
   message rather than part of the invoice document above it. */
.ac-inv-docnote {
    margin-bottom: 12px; padding: 8px 10px;
    background: #fff8e1; border: 1px solid #ffe082; border-right: 3px solid #f9a825;
    border-radius: 6px; font-size: 12.5px; line-height: 1.6;
}
.ac-inv-docnote-label { display: block; font-weight: 700; color: #8d6e00; margin-bottom: 2px; font-size: 11.5px; }
.ac-inv-docnote-val { color: #4a3c00; white-space: pre-wrap; }
/* Shown under the intent selector when a balance blocks the نەقد option. */
.ac-intent-hint { font-size: 11px; color: #c62828; font-weight: 600; }
/* Under پوختەی دارایی کەیس in the invoice review modal only — says out loud
   that دراو/ماوە there include the invoice being reviewed, which the stored
   ledger does not count until it is approved. */
.ac-fin-hint {
    margin: -2px 0 10px; padding: 6px 9px;
    background: #fbfbfd; border-right: 3px solid #cdd8e3; border-radius: 6px;
    color: #6a7787; font-size: 10.5px; line-height: 1.6;
}
.ac-fin-hint i { color: #97a5b4; }
/* Approved invoice opened in the review modal. It always opens is-locked
   (read-only); «تەعدیل» switches it to is-editing, where its money boxes are
   live and it is corrected in place — it stays approved, same approval date. */
.ac-approved-edit-hint {
    margin: 8px 0; padding: 8px 11px;
    background: #eef6ff; border-right: 3px solid #4a90d9; border-radius: 6px;
    color: #24466b; font-size: 11.5px; line-height: 1.75;
}
.ac-approved-edit-hint b { color: #173654; }
.ac-approved-edit-hint .ae-trail { display: block; margin-top: 3px; color: #5b6f86; font-size: 11px; }
.ac-approved-edit-hint.is-locked { background: #f6f7f9; border-right-color: #b7c0cb; color: #5b6573; }
.ac-approved-edit-hint.is-editing { background: #fff8e8; border-right-color: #e0a53b; color: #6b4e10; }
.ac-approved-edit-hint.is-editing b { color: #563d08; }
.vt-head-badge.is-edit-open { background: rgba(176,117,5,.14); color: #8a5a00; }
/* The «تەعدیل» strip of an approved receipt / plan / X-ray window: the state
   note beside its one button (تەعدیل ↔ پاشگەزبوونەوە). */
.ac-approved-bar { display: flex; align-items: flex-start; gap: 10px; margin: 8px 0; }
.ac-approved-bar .ac-approved-edit-hint { flex: 1 1 auto; margin: 0; }
.ac-approved-bar .btn { flex: 0 0 auto; white-space: nowrap; }
@media (max-width: 560px) { .ac-approved-bar { flex-direction: column; align-items: stretch; } }
/* «تەعدیل» / «تەواو» beside چاپ in the invoice header. */
.vt-btn.vt-btn-edit { background: #0f6cbd; border-color: #0f6cbd; color: #fff; }
.vt-btn.vt-btn-edit:hover { background: #0c5ba0; }
.vt-btn.vt-btn-done { background: #137a37; border-color: #137a37; color: #fff; }
.vt-btn.vt-btn-done:hover { background: #0f652d; }
.vt-total-box select.vt-disc-input { width: 100%; border: 0; background: transparent; font: inherit; cursor: pointer; }
.ac-inv-acc-notes label { display: block; font-size: 12px; font-weight: 700; color: #475467; margin-bottom: 5px; }
.ac-inv-acc-notes-hint { font-weight: 500; color: #888; font-size: 10.5px; }
.ac-inv-acc-notes textarea {
    width: 100%; min-height: 108px; padding: 9px 10px;
    border: 1px solid #cbd8e5; border-radius: 8px;
    font-family: inherit; font-size: 12.5px; color: #23405c;
    box-sizing: border-box; resize: vertical; background: #fff;
}

/* ── Payment review: a compact working form. The printable A5 voucher stays
   behind the Print button instead of being embedded into the dialog. */
.ac-drawer.is-modal.is-payment-modal {
    width: min(780px, 95vw);
    max-width: min(780px, 95vw);
}
.ac-payment-card {
    border: 1px solid #dbe3ec;
    border-radius: 10px;
    padding: 13px;
    background: #fff;
}
.ac-payment-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}
.ac-payment-toolbar .btn { text-decoration: none; }
.ac-payment-form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 9px;
}
.ac-payment-field { min-width: 0; }
.ac-payment-field.is-full { grid-column: 1 / -1; }
.ac-payment-field label {
    display: block;
    margin-bottom: 4px;
    color: #526273;
    font-size: 11px;
    font-weight: 700;
}
.ac-payment-field input,
.ac-payment-field textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #d5dee8;
    border-radius: 7px;
    padding: 7px 9px;
    background: #f8fafc;
    color: #1b2b3a;
    font-family: inherit;
    font-size: 12.5px;
}
.ac-payment-field textarea { resize: vertical; min-height: 52px; }
.ac-payment-field input:not([readonly]),
.ac-payment-field textarea:not([readonly]) { background: #fff; border-color: #9fc3e6; }
.ac-payment-field input:focus,
.ac-payment-field textarea:focus { outline: none; border-color: #0078d4; box-shadow: 0 0 0 3px rgba(0,120,212,.1); }
.ac-payment-field.is-amount input {
    color: #14713b;
    font-size: 17px;
    font-weight: 800;
    direction: ltr;
    text-align: center;
}
.ac-payment-section { margin-top: 12px; }
.ac-payment-section-title {
    margin-bottom: 6px;
    color: #23405c;
    font-size: 12px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ac-payment-summary {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 7px;
    margin-top: 12px;
}
.ac-payment-summary > div {
    padding: 7px 6px;
    border: 1px solid #dbe3ec;
    border-radius: 8px;
    background: #f8fafc;
    text-align: center;
}
.ac-payment-summary span { display: block; color: #647487; font-size: 9.5px; }
.ac-payment-summary strong { display: block; margin-top: 2px; direction: ltr; color: #1b2b3a; font-size: 12.5px; }
.ac-payment-summary .is-paid strong { color: #14713b; }
.ac-payment-summary .is-due strong { color: #c62026; }
.ac-payment-edit {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e3e8ee;
}
.ac-payment-edit .ac-form-actions { justify-content: flex-end; }
.ac-payment-reject {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: end;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f0d5d8;
}

/* ── X-ray review dialog: intentionally a compact working form rather than
   a long side drawer. The list-row preview is painted immediately, then the
   complete detail replaces it as soon as the prefetched/cached request is
   available (assets/js/approval-center.js). */
.ac-drawer.is-modal.is-xray-modal {
    width:min(700px,calc(100vw - 28px));
    max-width:min(700px,calc(100vw - 28px));
    max-height:min(820px,92vh);
    border-radius:14px;
}
.ac-drawer.is-modal.is-xray-modal .ac-drawer-head {
    padding:12px 16px;
    background:#fffdf7;
    border-bottom:1px solid #eee6d5;
}
.ac-drawer.is-modal.is-xray-modal .ac-drawer-head h3 { font-size:14px; }
.ac-xray-title-no {
    direction:ltr;
    color:#7b6640;
    font-size:11px;
    font-weight:700;
}
.ac-drawer.is-modal.is-xray-modal .ac-drawer-body { padding:13px 15px 15px; }
.ac-xray-patient {
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 11px;
    border:1px solid #e2e8ef;
    border-radius:10px;
    background:#fff;
}
.ac-xray-avatar {
    width:34px;
    height:34px;
    border-radius:9px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:0 0 auto;
    background:#eef6fd;
    color:#0875bd;
}
.ac-xray-patient-main { min-width:0; flex:1; }
.ac-xray-patient-main strong { display:block; color:#1b2b3a; font-size:13.5px; }
.ac-xray-patient-meta { margin-top:2px; color:#788798; font-size:10.5px; direction:ltr; text-align:right; }
.ac-xray-patient-side { display:flex; align-items:center; justify-content:flex-end; gap:7px; flex-wrap:wrap; }
/* "چاپ" in the X-ray modal — the same affordance the invoice pane and the
   payment voucher already offer, sized for this modal's compact header row.
   Opens xray/receipt.php in the page's print dialog, not a new tab
   (assets/js/approval-center.js). */
.ac-xray-print {
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:5px 11px;
    border:1px solid #cddcea;
    border-radius:7px;
    background:#fff;
    color:#0a6ebd;
    font-family:inherit;
    font-size:11px;
    font-weight:700;
    text-decoration:none;
    white-space:nowrap;
    cursor:pointer;
}
.ac-xray-print:hover { border-color:#0a6ebd; background:#f2f8fd; text-decoration:none; }

/* ── Print dialog ──
   The one shell every printable document in this page opens into (markup near
   the detail drawer below). z-index sits above .ac-drawer-overlay (3200) so it
   stacks on top of the detail it was printed from, which stays open behind it
   until the accountant closes this. */
.ac-print-overlay {
    position:fixed;
    inset:0;
    z-index:3400;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    box-sizing:border-box;
    background:rgba(0,0,0,0.55);
}
.ac-print-overlay.open { display:flex; }
.ac-print-dialog {
    display:flex;
    flex-direction:column;
    width:min(780px,100%);
    max-height:calc(100vh - 40px);
    border-radius:12px;
    background:#fff;
    box-shadow:0 20px 60px rgba(0,0,0,0.4);
    overflow:hidden;
}
.ac-print-head {
    display:flex;
    align-items:center;
    gap:9px;
    padding:11px 14px;
    background:linear-gradient(135deg,#1b2b3a,#2c4257);
    color:#fff;
}
.ac-print-head h4 { margin:0; flex:1 1 auto; font-size:14px; font-weight:700; }
.ac-print-btn {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 14px;
    border:0;
    border-radius:7px;
    background:#29a3dc;
    color:#fff;
    font-family:inherit;
    font-size:12.5px;
    font-weight:700;
    text-decoration:none;
    white-space:nowrap;
    cursor:pointer;
}
.ac-print-btn:hover { background:#1e8fc4; color:#fff; text-decoration:none; }
.ac-print-btn.is-ghost { background:rgba(255,255,255,.14); }
.ac-print-btn.is-ghost:hover { background:rgba(255,255,255,.26); }
.ac-print-btn.is-close { width:32px; height:32px; padding:0; justify-content:center; border-radius:8px; }
.ac-print-body {
    position:relative;
    flex:1 1 auto;
    min-height:0;
    background:#eceff3;
    overflow:auto;
    -webkit-overflow-scrolling:touch;
}
#acPrintFrame {
    display:block;
    width:100%;
    height:72vh;
    min-height:320px;
    border:0;
    background:#eceff3;
}
.ac-print-loading {
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    background:#eceff3;
    color:#5a6672;
    font-size:12.5px;
}
.ac-print-loading[hidden] { display:none; }
@media (max-width: 640px) {
    .ac-print-overlay { padding:6px; }
    .ac-print-dialog { max-height:calc(100vh - 12px); }
    .ac-print-head { flex-wrap:wrap; padding:9px 10px; }
    .ac-print-head h4 { flex:1 0 100%; font-size:13px; }
    #acPrintFrame { height:64vh; }
}
.ac-xray-info {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:7px;
    margin-top:9px;
}
.ac-xray-info-item {
    min-width:0;
    padding:8px 9px;
    border:1px solid #e5eaf0;
    border-radius:8px;
    background:#f8fafc;
}
.ac-xray-info-item span { display:block; color:#778596; font-size:9.5px; margin-bottom:2px; }
.ac-xray-info-item strong { display:block; color:#25384a; font-size:11.5px; overflow-wrap:anywhere; }
.ac-xray-money {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:7px;
    margin-top:9px;
}
.ac-xray-money > div {
    padding:8px 9px;
    border-radius:8px;
    border:1px solid #dce5ee;
    background:#f8fafc;
}
.ac-xray-money span { display:block; color:#6e7d8d; font-size:9.5px; }
.ac-xray-money strong { display:block; margin-top:2px; font-size:14px; direction:ltr; }
.ac-xray-money .is-discount strong { color:#b16a00; }
.ac-xray-money .is-final { background:#effaf2; border-color:#ccebd3; }
.ac-xray-money .is-final strong { color:#14713b; }
/* Only rendered for a request settled through the debt channel (part cash /
   part debt, or a debt being paid off) — see detailXray()'s `settlement`. */
.ac-xray-money .is-received strong { color:#126a32; }
.ac-xray-money .is-remaining { background:#fdf4ec; border-color:#f0dcc6; }
.ac-xray-money .is-remaining strong { color:#9a4d00; }
.ac-xray-note {
    margin-top:8px;
    padding:7px 9px;
    border-right:3px solid #b9c9d8;
    border-radius:6px;
    background:#f7f9fb;
    color:#58697b;
    font-size:10.5px;
    line-height:1.55;
}
.ac-xray-action {
    margin-top:10px;
    padding:11px;
    border:1px solid #d9e4ee;
    border-radius:10px;
    background:#fbfcfd;
}
.ac-xray-action-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:8px;
    color:#24384b;
    font-size:12px;
    font-weight:800;
}
.ac-xray-action-head small { color:#7b8997; font-size:9.5px; font-weight:500; }
.ac-xray-choice { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:7px; }
.ac-xray-choice-btn {
    border:1px solid #cfd9e3;
    border-radius:8px;
    padding:8px 10px;
    background:#fff;
    color:#46576a;
    font-family:inherit;
    font-size:11.5px;
    font-weight:700;
    cursor:pointer;
}
.ac-xray-choice-btn:hover { border-color:#7eb1dc; }
.ac-xray-choice-btn.is-selected {
    border-color:#16823d;
    background:#eaf7ee;
    color:#126a32;
    box-shadow:0 0 0 2px rgba(22,130,61,.08);
}
.ac-xray-edit-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:7px;
    margin-top:8px;
}
.ac-xray-edit-grid .ac-fld { margin:0; }
.ac-xray-edit-grid .is-wide { grid-column:1 / -1; }
.ac-xray-action .ac-fld input,
.ac-xray-action .ac-fld textarea {
    padding:7px 9px;
    border-color:#cfdae5;
    border-radius:7px;
    font-size:11.5px;
}
.ac-xray-action .ac-fld textarea { min-height:48px; resize:vertical; }
.ac-xray-action .ac-form-actions { justify-content:flex-end; margin-top:8px; }
.ac-xray-action .ac-form-actions .btn { min-width:190px; justify-content:center; }
/* ماوە is computed from کۆی گشتی − داشکاندن − بڕی وەرگیراوە, never typed —
   it has to read as a figure, not as another box waiting for input. */
.ac-xray-action .ac-fld input[readonly] {
    background:#eef2f6; border-color:#dbe3ec; color:#33475b;
    font-weight:800; cursor:default;
}
/* Says out loud what the وەرگیراو figure is about to do: all cash, all debt,
   or part of each. */
.ac-xray-settle-hint {
    display:flex; align-items:center; gap:6px;
    margin-top:8px; padding:7px 10px; border-radius:8px;
    background:#f3f6f9; color:#4b5c6b;
    font-size:11.5px; font-weight:700;
}
.ac-xray-settle-hint.is-cash { background:#eaf7ee; color:#126a32; }
.ac-xray-settle-hint.is-debt { background:#fdf1e7; color:#92500a; }
.ac-xray-settle-hint.is-partial { background:#eaf1fc; color:#17508f; }
.ac-xray-history {
    margin-top:9px;
    border:1px solid #e3e8ee;
    border-radius:8px;
    overflow:hidden;
}
.ac-xray-history summary {
    padding:8px 10px;
    background:#f8fafc;
    color:#596a7b;
    font-size:10.5px;
    font-weight:700;
    cursor:pointer;
}
.ac-xray-history .ac-table-mini { margin:0; }
.ac-xray-dialog-loading { padding:2px; }
.ac-xray-dialog-loading .ac-xray-patient { margin-bottom:9px; }
.ac-xray-loading-line {
    height:12px;
    border-radius:6px;
    background:linear-gradient(90deg,#eef1f4 25%,#f8fafc 50%,#eef1f4 75%);
    background-size:200% 100%;
    animation:acXrayShimmer 1.1s linear infinite;
}
.ac-xray-loading-line + .ac-xray-loading-line { margin-top:7px; }
.ac-xray-loading-line.is-short { width:58%; }
@keyframes acXrayShimmer { to { background-position:-200% 0; } }
@media (max-width: 760px) {
    .ac-payment-form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ac-payment-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
    .ac-drawer.is-modal.is-payment-modal { width: 100%; max-width: 100%; }
    .ac-payment-form-grid { grid-template-columns: 1fr; }
    .ac-payment-field.is-full { grid-column: auto; }
    .ac-payment-reject { grid-template-columns: 1fr; }
    .ac-drawer.is-modal.is-xray-modal { width:100%; max-width:100%; height:100%; max-height:100%; border-radius:0; }
    .ac-xray-info { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .ac-xray-patient { align-items:flex-start; }
    .ac-xray-patient-side { flex-direction:column; align-items:flex-end; }
}
.ac-inv-acc-notes textarea:focus { border-color: #0078d4; outline: none; box-shadow: 0 0 0 3px rgba(0,120,212,.1); }
/* وەرگرتنی پارە — same column/width as the totals stack above it, not a
   detached bar; classes are unchanged (btn btn-primary, same as approve). */
.ac-recv-actions { margin-top: 10px; }
.ac-recv-actions #acRecvBtn { width: 100%; justify-content: center; }
.ac-table-mini { width:100%; border-collapse:collapse; font-size:12px; margin-bottom:6px; }
.ac-table-mini th { background:#f3f6f9; padding:7px 9px; text-align:right; color:#555; font-weight:600; border-bottom:1.5px solid #e6e6e6; white-space:nowrap; }
.ac-table-mini td { padding:7px 9px; border-bottom:1px solid #f0f0f0; }
.ac-num { direction:ltr; text-align:right; white-space:nowrap; }
.ac-form { border:1px solid #e6e6e6; border-radius:10px; padding:12px; margin-top:12px; background:#fafbfc; }
.ac-form-title { font-size:12.5px; font-weight:700; color:#333; margin-bottom:9px; display:flex; align-items:center; gap:6px; }
.ac-fld { margin-bottom:10px; }
.ac-fld label { display:block; font-size:11.5px; font-weight:600; color:#475467; margin-bottom:4px; }
.ac-fld input, .ac-fld select, .ac-fld textarea { width:100%; padding:8px 10px; border:1px solid #e4e7ec; border-radius:8px; font-family:inherit; font-size:12.5px; box-sizing:border-box; background:#fff; }
.ac-fld input:focus, .ac-fld select:focus, .ac-fld textarea:focus { border-color:#0078d4; outline:none; }
.ac-fld-hint { font-size:11px; color:#888; margin-top:3px; }
.ac-form-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:6px; }
.ac-err { color:#b42318; font-size:12px; background:#fef3f2; border-radius:8px; padding:7px 10px; margin-top:8px; display:none; }
.ac-empty-drawer { color:#aaa; text-align:center; padding:14px; font-size:12.5px; }
/* وەسڵی کارە ئەنجامدراوەکان — the works list inside the arch drawer. No price
   column on purpose: an arch visit adds no charge, so the only money on that
   drawer is the patient's existing debt and what is collected against it. */
.ac-aw-table { width:100%; border-collapse:collapse; margin-top:8px; }
.ac-aw-table th, .ac-aw-table td {
    border:1px solid #e3e8ee; padding:5px 7px;
    font-size:12px; text-align:center; vertical-align:middle;
}
.ac-aw-table th { background:#f4f8fc; font-weight:700; color:#1b2b3a; font-size:11.5px; }
.ac-aw-table tbody tr:nth-child(even) { background:#fafbfc; }
/* Settlement stack under that table. Same treatment the invoice pane gets in
   this modal: a sane fixed width instead of stretching across the full 1100px
   the shared .vt-foot-totals flex-basis would give it here. */
.ac-drawer.is-modal .ac-aw-foot { justify-content:flex-start; }
.ac-drawer.is-modal .ac-aw-foot .vt-foot-totals { flex:0 1 380px; width:380px; max-width:100%; min-width:260px; }

/* ── fw-modal shell — copied from patients/statement.php:169/171 (the
   codebase's real convention for a money-action confirm, e.g. its
   reverse-payment dialog at statement.php:540-549), used here for the
   وەرگرتنی پارە confirm instead of a native window.confirm(). Only the
   rules this one dialog needs are copied over. */
/* inset:0 + margin:auto centres it explicitly. A <dialog> opened with
   showModal() is meant to centre itself from the UA stylesheet, but this page
   ships enough global CSS that the UA's margin can be overridden — in which
   case the dialog sticks to the top of the viewport. */
.fw-modal { border:0; padding:0; border-radius:11px; width:min(460px,calc(100vw - 28px)); max-height:90vh; box-shadow:0 24px 70px rgba(16,38,58,0.27); direction:rtl; position:fixed; inset:0; margin:auto; }
.fw-modal::backdrop { background:rgba(13,34,54,0.4); }
.fw-modal-head { display:flex; justify-content:space-between; align-items:center; padding:13px 16px; border-bottom:1px solid #dbe3ec; }
.fw-modal-head h3 { margin:0; font-size:15px; display:flex; align-items:center; gap:8px; }
.fw-modal-body { padding:15px; overflow:auto; max-height:calc(90vh - 116px); }
.fw-modal-foot { display:flex; justify-content:flex-start; gap:7px; padding:11px 15px; border-top:1px solid #dbe3ec; background:#f8fafc; }
.fw-modal-close { border:0; background:transparent; font-size:20px; cursor:pointer; color:#555; }
.fw-pay-balance { background:#eff7ff; border:1px solid #c9e3fa; border-radius:8px; padding:10px 12px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; }
.fw-pay-balance strong { font-size:18px; color:#0a68ad; direction:ltr; }

@media (max-width:767px) {
    .ac-header { flex-direction:column; align-items:flex-start; }
}
</style>

<div class="ac-container">

    <div class="ac-header">
        <div class="ac-title">
            <div class="icon"><i class="fas fa-clipboard-check"></i></div>
            <div>
                <h2>ناوەندی پەسەندکردن <span class="live-dot">ڕاستەوخۆ</span></h2>
                <div style="font-size:12px; color:#888; margin-top:3px;">پسووڵە، پارەدان، قیست، ئێکسڕەی و نەخۆشانی ژووری چاوەڕوانی — هەموو لە یەک شوێن</div>
            </div>
        </div>
        <div class="ac-actions">
            <span class="ac-new-badge" id="acNewBadge"></span>
            <!-- Money from a patient who has no request in any queue yet — the
                 morning case this whole page otherwise has no answer for. -->
            <button type="button" class="btn btn-primary btn-sm" id="acAdvanceOpen" title="وەرگرتنی پارە پێش ئەوەی کەیس هەبێت">
                <i class="fas fa-hand-holding-usd"></i> وەرگرتنی قەرزی کۆن
            </button>
            <button type="button" class="btn-sound" id="acSoundToggle" title="دەنگی ئاگادارکردنەوە"><i class="fas fa-volume-up"></i></button>
        </div>
    </div>

    <!-- پەسەندکراو carries no server-rendered count: it is a 30-day look-back,
         not a live queue, so counting it on every 8s poll would cost five more
         COUNT queries for a number nobody is waiting on. The client fills it
         with the row count of the list it just loaded. -->
    <div class="kind-tabs" id="acTabs">
        <button type="button" class="kind-tab" data-tab="all"><i class="fas fa-inbox"></i> هەموو داواکاریەکان <span class="kt-count" id="acCountAll"><?= (int)$initialCounts['all'] ?></span></button>
        <button type="button" class="kind-tab" data-tab="approved"><i class="fas fa-check-circle"></i> پەسەندکراو <span class="kt-count" id="acCountApproved">—</span></button>
        <!-- Counter money taken before a case existed. Not a queue waiting for
             a decision — it is money already taken — so it gets its own tab
             instead of rows in the main feed. -->
        <button type="button" class="kind-tab" data-tab="advances"><i class="fas fa-hand-holding-usd"></i> قەرزی کۆن <span class="kt-count" id="acCountAdvances">—</span></button>
    </div>

    <div class="filters-card">
        <div class="ac-search">
            <input type="text" id="acSearch" placeholder="گەڕان: ناوی نەخۆش، مۆبایل، ژمارە...">
            <i class="fas fa-search"></i>
        </div>
        <!-- جۆر — the one feed carries six sources at once, so this narrows it
             to a single one. Values and labels mirror KIND_META in
             assets/js/approval-center.js (the جۆر badge in the table) and
             ApprovalQueue::KINDS (what the server will accept). -->
        <select id="acKindFilter" title="جۆری داواکاری">
            <option value="">هەموو جۆرەکان</option>
            <?php foreach ($kindOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>"<?= $initialKind === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="acDoctorFilter">
            <option value="">هەموو دکتۆرەکان</option>
            <?php foreach ($doctors as $doc): ?>
            <option value="<?= (int)$doc['id'] ?>"><?= htmlspecialchars($doc['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" id="acDateFrom" title="لە بەروار">
        <input type="date" id="acDateTo" title="بۆ بەروار">
        <!-- پەسەندکراو tab only: who approved — the nightly run or a person. -->
        <select id="acApprovalFilter" title="شێوەی پەسەندکردن" style="display:none;">
            <option value="">هەموو پەسەندکردنەکان</option>
            <option value="auto"<?= $initialApproval === 'auto' ? ' selected' : '' ?>>🤖 خۆکار</option>
            <option value="manual"<?= $initialApproval === 'manual' ? ' selected' : '' ?>>✋ دەستی</option>
        </select>
        <!-- Open-vs-decided only applies to the داواکاریەکان feed; the
             پەسەندکراو tab IS a decided view, so the client hides this there
             (assets/js/approval-center.js). -->
        <div class="scope-toggle" id="acScopeToggle">
            <button type="button" data-scope="open" class="active">کراوەکان</button>
            <button type="button" data-scope="history">مێژوو (٣٠ ڕۆژ)</button>
        </div>
        <!-- ڕیزبەندی بەپێی بەروار — applies to every tab and is sent to the
             server (?sort=), never applied in the browser: the decided views
             keep only 200 rows, so reversing them here would show the oldest of
             the NEWEST 200 instead of the oldest rows there are. -->
        <button type="button" class="ac-sort-btn" id="acSortToggle" title="ڕیزبەندی بەپێی بەروار">
            <i class="fas fa-sort-amount-down" id="acSortIcon"></i>
            <span id="acSortLabel">نوێ ← کۆن</span>
        </button>
    </div>

    <div class="table-card">
        <div class="table-scroll">
            <table class="ac-table no-responsive" data-no-responsive="true">
                <thead>
                    <tr>
                        <th style="width:110px;">جۆر</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>ژمارە</th>
                        <th>بڕ</th>
                        <th>جۆری پارەدان</th>
                        <th style="width:120px;">کات</th>
                        <th style="width:130px;">دۆخ</th>
                        <!-- بینین always; دەستکاری + سڕینەوە are added on the
                             پەسەندکراو tab (assets/js/approval-center.js). -->
                        <th style="width:118px;"></th>
                    </tr>
                </thead>
                <tbody id="acBody">
                    <tr><td colspan="9" class="ac-loading"><i class="fas fa-spinner fa-spin"></i> بارکردنی داتاکان...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- Pages of 25 rows. On the grouped داواکاریەکان feed the unit is a
             PATIENT, not a row, so one patient's requests are never split
             across two pages (assets/js/approval-center.js). -->
        <div class="ac-pager" id="acPager" hidden>
            <button type="button" class="ac-pager-btn" id="acPagerPrev"><i class="fas fa-chevron-right"></i> پێشوو</button>
            <span class="ac-pager-info" id="acPagerInfo"></span>
            <button type="button" class="ac-pager-btn" id="acPagerNext">دواتر <i class="fas fa-chevron-left"></i></button>
        </div>
    </div>

</div>

<!-- Detail drawer -->
<div class="ac-drawer-overlay" id="acDrawerOverlay" aria-hidden="true">
    <div class="ac-drawer" id="acDrawer" role="dialog" aria-modal="true" aria-labelledby="acDrawerTitle" tabindex="-1">
        <div class="ac-drawer-head">
            <h3 id="acDrawerTitle"><i class="fas fa-eye" style="color:#0078d4;"></i> وردەکاری</h3>
            <button type="button" class="ac-drawer-close" id="acDrawerClose"><i class="fas fa-times"></i></button>
        </div>
        <!-- Invoice-only persistent subheader (patient identity + کشف حساب +
             مەبەست) — populated by assets/js/approval-center.js's
             invoiceSubheaderHtml() only for the invoice kind; every other
             detail kind leaves it empty and .ac-drawer-sub:empty hides it. -->
        <div class="ac-drawer-sub" id="acDrawerSub"></div>
        <div class="ac-drawer-body" id="acDrawerBody">
            <div class="ac-loading"><i class="fas fa-spinner fa-spin"></i> بارکردن...</div>
        </div>
    </div>
</div>

<!-- Print dialog — ONE shell reused by every printable document in this page
     (invoice پسوولە, payment قەبز, arch work وەسڵ, X-ray فۆڕم). The sheet is
     shown inline in an iframe instead of a new tab, so the accountant never
     leaves the queue; ?embed=1 tells the receipt page to drop its own toolbar.
     Printing calls print() on the iframe's own window, which prints ONLY the
     sheet (its @page rule sets the paper size), not the page around it.
     Populated and opened by assets/js/approval-center.js (openPrintDialog). -->
<div class="ac-print-overlay" id="acPrintOverlay" aria-hidden="true">
    <div class="ac-print-dialog" role="dialog" aria-modal="true" aria-labelledby="acPrintTitle">
        <div class="ac-print-head">
            <h4 id="acPrintTitle"><i class="fas fa-print"></i> چاپکردن</h4>
            <button type="button" class="ac-print-btn" id="acPrintDo"><i class="fas fa-print"></i> چاپکردن</button>
            <!-- Escape hatch only: the standalone page, for a browser that
                 refuses to print a frame. -->
            <a class="ac-print-btn is-ghost" id="acPrintNewTab" href="#" target="_blank" rel="noopener" title="کردنەوە لە پەڕەیەکی نوێ"><i class="fas fa-external-link-alt"></i></a>
            <button type="button" class="ac-print-btn is-ghost is-close" id="acPrintClose" title="داخستن"><i class="fas fa-times"></i></button>
        </div>
        <div class="ac-print-body">
            <div class="ac-print-loading" id="acPrintLoading"><i class="fas fa-spinner fa-spin"></i> بەڵگەنامە ئامادە دەکرێت...</div>
            <!-- src is set on open, so nothing is fetched with the page load.
                 No inline onload: a src-less iframe fires load for about:blank
                 before this page's script has run. -->
            <iframe id="acPrintFrame" title="چاپکردن"></iframe>
        </div>
    </div>
</div>

<!-- وەرگرتنی پارە confirm — fw-modal shell/markup, the codebase's actual
     convention for a money-action confirm (see patients/statement.php's
     reverse-payment dialog). One static dialog, reused for every invoice
     drawer's وەرگیراو trigger; assets/js/approval-center.js populates and
     opens/closes it (openReceiveConfirm/closeReceiveConfirm), wired once. -->
<dialog class="fw-modal" id="acReceiveConfirmDialog">
    <div class="fw-modal-head">
        <h3><i class="fas fa-cash-register" style="color:#107c10;"></i> وەرگرتنی پارە</h3>
        <button type="button" class="fw-modal-close" id="acReceiveConfirmClose">&times;</button>
    </div>
    <div class="fw-modal-body">
        <div class="fw-pay-balance"><span>بڕی وەردەگیرێت</span><strong id="acReceiveConfirmAmount">0</strong></div>
        <div class="ac-fld"><label>نەخۆش</label><div id="acReceiveConfirmPatient" style="font-weight:700;font-size:13px;">—</div></div>
        <div class="ac-fld"><label>پسووڵە</label><div id="acReceiveConfirmInvoice" dir="ltr" style="font-weight:700;font-size:13px;"></div></div>
        <div class="ac-fld">
            <label>ڕێگای وەرگرتن</label>
            <select id="acReceiveConfirmMethod">
                <option value="cash">نەقد</option>
                <option value="card">کارت</option>
                <option value="transfer">گواستنەوە</option>
                <option value="other">هی تر</option>
            </select>
        </div>
        <div class="ac-fld"><label>تێبینی (ئارەزوومەندانە)</label><input type="text" id="acReceiveConfirmNotes" placeholder="ژمارەی بەڵگە یان تێبینی…"></div>
        <div class="ac-err" id="acReceiveConfirmErr"></div>
    </div>
    <div class="fw-modal-foot">
        <button type="button" class="btn btn-primary" id="acReceiveConfirmOk"><i class="fas fa-check"></i> بەڵێ</button>
        <button type="button" class="btn" id="acReceiveConfirmCancel">نەخێر</button>
    </div>
</dialog>

<!-- قەرزی کۆن — money taken at the counter before any case exists to put it on.
     The clinic opens at 08:00 and the doctors arrive at 12:00, so a patient can
     come in the morning to settle an old balance while the doctor has not yet
     opened their case. This dialog searches PATIENTS (not queue rows — a
     patient with no request has no row to find), takes the money against the
     patient, and prints them a numbered receipt. The doctor puts it on a case
     later, from the invoice's «قەرزی کۆن» line in cases/view.php. -->
<dialog class="fw-modal" id="acAdvanceDialog">
    <div class="fw-modal-head">
        <h3><i class="fas fa-hand-holding-usd" style="color:#107c10;"></i> وەرگرتنی قەرزی کۆن</h3>
        <button type="button" class="fw-modal-close" id="acAdvanceClose">&times;</button>
    </div>
    <div class="fw-modal-body">
        <div class="ac-fld">
            <label>گەڕان بەدوای نەخۆش (ناو، مۆبایل، یان کۆد)</label>
            <input type="text" id="acAdvanceSearch" placeholder="لانیکەم دوو پیت بنووسە…" autocomplete="off">
        </div>
        <div id="acAdvanceResults" class="ac-advance-results"></div>
        <div id="acAdvancePicked" style="display:none;">
            <div class="ac-fld">
                <label>نەخۆشی هەڵبژێردراو</label>
                <div id="acAdvancePatientName" style="font-weight:700;font-size:13px;">—</div>
                <div id="acAdvancePatientBal" class="ac-fld-hint"></div>
                <div class="ac-fld-hint">ئەم پارەیە وەک ئامانەت تۆمار دەکرێت؛ تا لەلایەن ژمێریارەوە بۆ پارەدان بەکارنەهێنرێت، ناچێتە داهات.</div>
            </div>
            <!-- Whose patient this old balance is. The counter takes the money
                 before any case exists, so nothing else in the row can say it —
                 it is what the printed receipt names and what lets the دکتۆر
                 filter find these rows. Same list as the filter above. -->
            <div class="ac-fld">
                <label>دکتۆر <span style="color:#c50f1f;">*</span></label>
                <select id="acAdvanceDoctor">
                    <option value="">— دکتۆر هەڵبژێرە —</option>
                    <?php foreach ($doctors as $doc): ?>
                    <option value="<?= (int)$doc['id'] ?>"><?= htmlspecialchars($doc['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ac-fld"><label>بڕی وەرگیراو</label><input type="number" id="acAdvanceAmount" min="1" step="0.01" placeholder="0"></div>
            <div class="ac-fld">
                <label>ڕێگای وەرگرتن</label>
                <select id="acAdvanceMethod">
                    <option value="cash">نەقد</option>
                    <option value="card">کارت</option>
                    <option value="transfer">گواستنەوە</option>
                    <option value="other">هی تر</option>
                </select>
            </div>
            <div class="ac-fld"><label>تێبینی (ئارەزوومەندانە)</label><input type="text" id="acAdvanceNotes" placeholder="بۆ چی وەرگیراوە…"></div>
        </div>
        <div class="ac-err" id="acAdvanceErr"></div>
    </div>
    <div class="fw-modal-foot">
        <button type="button" class="btn btn-primary" id="acAdvanceOk" disabled><i class="fas fa-print"></i> وەرگرتن و چاپی وەسڵ</button>
        <button type="button" class="btn" id="acAdvanceCancel">داخستن</button>
    </div>
</dialog>

<!-- کردنەوە بۆ دەستکاری / سڕینەوەی تۆماری پەسەندکراو.
     One dialog for both buttons of the پەسەندکراو tab, in the same fw-modal
     shell as the وەرگرتنی پارە confirm above. Its body is filled from
     api/approval-center.php?action=reversal_preview, so what it lists IS what
     the server is about to reverse — the money, the documents and the statuses.
     The write itself goes to api/finance-actions.php
     (reopen_approved / delete_approved → classes/ApprovalReversal.php). -->
<dialog class="fw-modal is-wide" id="acReversalDialog">
    <div class="fw-modal-head">
        <h3 id="acRevTitle"><i class="fas fa-undo"></i> هەڵوەشاندنەوە</h3>
        <button type="button" class="fw-modal-close" id="acRevClose">&times;</button>
    </div>
    <div class="fw-modal-body">
        <div id="acRevLoading" class="ac-rev-loading"><i class="fas fa-spinner fa-spin"></i> پشکنینی تۆمارەکان...</div>
        <div id="acRevContent" style="display:none;">
            <div class="ac-rev-head">
                <div><span>جۆر</span><strong id="acRevKind">—</strong></div>
                <div><span>ژمارە</span><strong id="acRevNumber" dir="ltr">—</strong></div>
                <div><span>نەخۆش</span><strong id="acRevPatient">—</strong></div>
            </div>
            <div class="ac-rev-note" id="acRevNote"></div>
            <div class="ac-rev-warn" id="acRevWarn"></div>
            <div class="ac-section-title"><i class="fas fa-exchange-alt"></i> ئەو تۆمارانەی دەگەڕێنەوە</div>
            <div class="ac-rev-lines" id="acRevLines"></div>
            <div class="ac-rev-total" id="acRevTotal"></div>
            <div class="ac-fld">
                <label id="acRevReasonLabel">هۆکار (پێویستە — لە تۆماری چالاکی هەڵدەگیرێت)</label>
                <textarea id="acRevReason" rows="2" placeholder="بۆچی هەڵدەوەشێتەوە..."></textarea>
            </div>
        </div>
        <div class="ac-err" id="acRevErr"></div>
    </div>
    <div class="fw-modal-foot">
        <button type="button" class="btn btn-danger" id="acRevOk" disabled><i class="fas fa-check"></i> بەڵێ</button>
        <button type="button" class="btn" id="acRevCancel">نەخێر</button>
    </div>
</dialog>

<script>
window.AC_INIT = {
    tab: <?= json_encode($initialTab, JSON_HEX_TAG) ?>,
    q: <?= json_encode($initialQ, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    kind: <?= json_encode($initialKind, JSON_HEX_TAG) ?>,
    approval: <?= json_encode($initialApproval, JSON_HEX_TAG) ?>,
    counts: <?= json_encode($initialCounts) ?>,
    appUrl: <?= json_encode(APP_URL, JSON_HEX_TAG) ?>
};
</script>
<?php
$approvalCenterJsPath = __DIR__ . '/../assets/js/approval-center.js';
$approvalCenterJsVersion = is_file($approvalCenterJsPath)
    ? (string) filemtime($approvalCenterJsPath)
    : '1';
?>
<script src="<?= APP_URL ?>/assets/js/approval-center.js?v=<?= rawurlencode($approvalCenterJsVersion) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
