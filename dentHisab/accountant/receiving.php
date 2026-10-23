<?php
/**
 * Accountant Receiving Dashboard — live feed of everything doctors send.
 * پەڕەی وەرگرتنی نێردراوەکانی دکتۆر (کەیس، تێبینی، پارەدان، پشکنین)
 *
 * Read-only receiving screen: rows arrive in real time (polling — shared
 * hosting has no WebSockets), classified by type, newest on top, with an
 * eye button opening the full detail modal. Processing/settling stays on
 * report-detail.php / pending-reports.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$isAccountant = $auth->isAccountant();
$isAdmin = $auth->isAdmin();

if (!$isAccountant && !$isAdmin) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$pageTitle = 'وەرگرتنی نێردراوەکان';
$breadcrumb = [
    ['title' => 'داشبۆردی ژمێریار', 'url' => APP_URL . '/accountant/dashboard.php'],
    ['title' => 'وەرگرتنی نێردراوەکان']
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.recv-container { max-width:1400px; margin:0 auto; }
.recv-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
.recv-title { display:flex; align-items:center; gap:12px; }
.recv-title h2 { margin:0; font-size:20px; color:#1a1a1a; display:flex; align-items:center; gap:10px; }
.recv-title .icon { width:45px; height:45px; background:linear-gradient(135deg,#107c10,#0b5c0b); border-radius:12px; display:flex; align-items:center; justify-content:center; color:white; font-size:20px; box-shadow:0 4px 12px rgba(16,124,16,0.3); }
.live-dot { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:600; color:#107c10; background:#dff6dd; padding:4px 10px; border-radius:12px; }
.live-dot::before { content:''; width:8px; height:8px; border-radius:50%; background:#107c10; animation:livePulse 1.6s infinite; }
@keyframes livePulse { 0%,100% { opacity:1; } 50% { opacity:0.25; } }
.recv-actions { display:flex; align-items:center; gap:10px; }
.btn-sound { width:38px; height:38px; border-radius:10px; border:1.5px solid #e0e0e0; background:white; cursor:pointer; font-size:15px; color:#0078d4; display:flex; align-items:center; justify-content:center; transition:all 0.2s; }
.btn-sound.muted { color:#999; opacity:0.7; }
.recv-new-badge { background:linear-gradient(135deg,#c50f1f,#a00d19); color:white; border-radius:14px; padding:5px 12px; font-size:12px; font-weight:700; display:none; animation:badgeBlink 1s ease 3; }
@keyframes badgeBlink { 0%,100% { transform:scale(1); } 50% { transform:scale(1.15); } }

.stat-tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:18px; }
.stat-tile { background:white; border-radius:12px; padding:14px 16px; box-shadow:0 2px 12px rgba(0,0,0,0.06); display:flex; align-items:center; gap:12px; border-right:4px solid transparent; }
.stat-tile .st-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; }
.stat-tile .st-num { font-size:22px; font-weight:700; color:#1a1a1a; line-height:1; }
.stat-tile .st-label { font-size:11px; color:#888; margin-top:4px; }

.filters-card { background:white; border-radius:12px; padding:14px 16px; margin-bottom:16px; box-shadow:0 2px 12px rgba(0,0,0,0.08); display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.kind-tabs { display:flex; gap:6px; flex-wrap:wrap; }
.kind-tab { padding:8px 16px; border-radius:20px; border:1.5px solid #e0e0e0; font-size:12px; font-weight:600; cursor:pointer; color:#666; background:white; transition:all 0.2s; display:flex; align-items:center; gap:6px; font-family:inherit; }
.kind-tab .kt-count { background:rgba(0,0,0,0.1); border-radius:10px; padding:1px 7px; font-size:11px; }
.kind-tab.active { background:linear-gradient(135deg,#0078d4,#0063b1); color:white; border-color:#0078d4; }
.kind-tab.active .kt-count { background:rgba(255,255,255,0.25); }
.recv-search { flex:1; min-width:200px; position:relative; }
.recv-search input { width:100%; padding:9px 36px 9px 12px; border:1.5px solid #e0e0e0; border-radius:8px; font-size:13px; font-family:inherit; transition:all 0.2s; }
.recv-search input:focus { border-color:#0078d4; outline:none; box-shadow:0 0 0 3px rgba(0,120,212,0.1); }
.recv-search i { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#aaa; font-size:13px; }
.scope-toggle { display:flex; border:1.5px solid #e0e0e0; border-radius:8px; overflow:hidden; }
.scope-toggle button { padding:8px 14px; border:none; background:white; cursor:pointer; font-size:12px; font-weight:600; color:#666; font-family:inherit; }
.scope-toggle button.active { background:#0078d4; color:white; }

.table-card { background:white; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.06); overflow:hidden; }
.table-scroll { overflow-x:auto; }
.recv-table { width:100%; border-collapse:collapse; font-size:13px; }
.recv-table th { background:#f8f9fa; padding:12px 14px; text-align:right; font-weight:600; color:#333; border-bottom:2px solid #e0e0e0; white-space:nowrap; }
.recv-table td { padding:11px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.recv-table tr:hover td { background:#f9fbff; }
.recv-table tr.row-new td { animation:rowFlash 2.5s ease; }
@keyframes rowFlash { 0% { background:#fff4ce; } 100% { background:transparent; } }
.kind-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:14px; font-size:11px; font-weight:600; white-space:nowrap; }
.status-chip { display:inline-flex; align-items:center; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.chip-open { background:#fff4ce; color:#8a5a00; }
.chip-seen { background:#deecf9; color:#0063b1; }
.chip-done { background:#dff6dd; color:#107c10; }
.purpose-cell { max-width:280px; }
.purpose-wrap { display:flex; align-items:flex-start; gap:7px; }
.purpose-label { font-size:12px; font-weight:700; }
.purpose-note { font-size:11px; color:#888; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px; }
.btn-eye-sm { min-width:28px; height:28px; padding:0; display:inline-flex; align-items:center; justify-content:center; border-radius:7px; border:1.5px solid #0078d4; background:white; color:#0078d4; cursor:pointer; font-size:12px; flex-shrink:0; transition:all 0.2s; }
.btn-eye-sm:hover { background:#0078d4; color:white; }
.status-tabs { display:flex; gap:6px; }
.status-tab { padding:8px 14px; border-radius:20px; border:1.5px solid #e0e0e0; font-size:12px; font-weight:600; cursor:pointer; color:#666; background:white; transition:all 0.2s; display:flex; align-items:center; gap:6px; font-family:inherit; }
.status-tab .st-count { background:rgba(0,0,0,0.1); border-radius:10px; padding:1px 7px; font-size:11px; }
.status-tab.active[data-status="pending"] { background:#ff8c00; border-color:#ff8c00; color:white; }
.status-tab.active[data-status="seen"] { background:#0078d4; border-color:#0078d4; color:white; }
.status-tab.active[data-status="all"] { background:#5c5c5c; border-color:#5c5c5c; color:white; }
.status-tab.active .st-count { background:rgba(255,255,255,0.25); }
/* purpose-only modal */
#purposeDialog .dialog { max-width:460px; width:95%; }
.pp-purpose { display:flex; align-items:center; gap:10px; background:#f3f6f9; border-radius:10px; padding:12px; margin-bottom:12px; font-size:14px; font-weight:700; }
.pp-text { background:#fffbe6; border:1px solid #ffe58f; border-radius:10px; padding:12px; font-size:13px; color:#333; white-space:pre-wrap; max-height:45vh; overflow-y:auto; }
.pp-meta { display:flex; gap:14px; flex-wrap:wrap; margin-top:10px; font-size:11px; color:#888; }
.note-cell { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#666; }
.btn-eye { min-width:34px; height:34px; padding:0 10px; display:inline-flex; align-items:center; justify-content:center; gap:5px; border-radius:8px; border:none; cursor:pointer; background:linear-gradient(135deg,#0078d4,#0063b1); color:white; font-size:13px; transition:all 0.2s; text-decoration:none; }
.btn-eye:hover { transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,120,212,0.35); }
.btn-hide { min-width:34px; height:34px; padding:0 10px; margin-right:5px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; border:1.5px solid #ddd; cursor:pointer; background:#f3f3f3; color:#888; font-size:13px; transition:all 0.2s; }
.btn-hide:hover { background:#e9e9e9; color:#555; }
.recv-empty { text-align:center; padding:50px 20px; color:#aaa; }
.recv-empty i { font-size:34px; margin-bottom:10px; display:block; }
.recv-loading { text-align:center; padding:40px; color:#888; }

/* detail modal */
#feedDetailDialog .dialog { max-width:640px; width:95%; max-height:88vh; overflow-y:auto; }
.fd-head-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:14px; }
.fd-head-item { background:#f8f9fa; border-radius:10px; padding:9px 12px; }
.fd-head-item .lbl { font-size:10px; color:#888; margin-bottom:3px; }
.fd-head-item .val { font-size:13px; font-weight:600; color:#222; }
.fd-section-title { font-size:13px; font-weight:700; color:#0063b1; margin:16px 0 8px; display:flex; align-items:center; gap:7px; }
.fd-collapse { border:1px solid #e6e6e6; border-radius:10px; margin-top:14px; overflow:hidden; }
.fd-collapse-head { width:100%; display:flex; align-items:center; gap:8px; padding:10px 12px; background:#f3f6f9; border:none; cursor:pointer; font-family:inherit; font-size:13px; font-weight:700; color:#0063b1; text-align:right; }
.fd-collapse-head:hover { background:#e9f0f7; }
.fd-collapse-head .fc-count { background:#0078d4; color:white; border-radius:10px; padding:1px 8px; font-size:11px; font-weight:600; }
.fd-collapse-head .fc-total { font-size:11px; color:#555; font-weight:600; }
.fd-collapse-head .fc-arrow { margin-right:auto; color:#888; font-size:12px; transition:transform 0.25s; }
.fd-collapse-body { display:none; }
.fd-collapse.open .fd-collapse-body { display:block; }
.fd-collapse.open .fc-arrow { transform:rotate(180deg); }
.fd-note { background:#fffbe6; border:1px solid #ffe58f; border-radius:10px; padding:10px 12px; margin-bottom:8px; }
.fd-note .fd-note-meta { font-size:10px; color:#999; margin-top:6px; display:flex; gap:10px; }
.fd-note .fd-note-text { font-size:13px; color:#333; white-space:pre-wrap; }
.fd-table { width:100%; border-collapse:collapse; font-size:12px; }
.fd-table th { background:#f3f6f9; padding:8px 10px; text-align:right; color:#555; font-weight:600; border-bottom:1.5px solid #e6e6e6; }
.fd-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; }
.fd-fin { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:8px; }
.fd-fin-box { border-radius:10px; padding:10px; text-align:center; }
.fd-fin-box .n { font-size:16px; font-weight:700; }
.fd-fin-box .l { font-size:10px; margin-top:3px; }

@media (max-width:767px) {
    .note-cell { max-width:140px; }
    .recv-header { flex-direction:column; align-items:flex-start; }
}
</style>

<div class="recv-container">

    <div class="recv-header">
        <div class="recv-title">
            <div class="icon"><i class="fas fa-inbox"></i></div>
            <div>
                <h2>وەرگرتنی نێردراوەکان <span class="live-dot">ڕاستەوخۆ</span></h2>
                <div style="font-size:12px; color:#888; margin-top:3px;">هەموو ئەوەی دکتۆرەکان دەینێرن — کەیس، تێبینی، پارەدان و پشکنین</div>
            </div>
        </div>
        <div class="recv-actions">
            <span class="recv-new-badge" id="newBadge"></span>
            <button type="button" class="btn-sound" id="soundToggle" title="دەنگی ئاگادارکردنەوە"><i class="fas fa-volume-up"></i></button>
        </div>
    </div>

    <div class="stat-tiles">
        <div class="stat-tile" style="border-right-color:#c50f1f;">
            <div class="st-icon" style="background:#fde7e9; color:#c50f1f;"><i class="fas fa-inbox"></i></div>
            <div><div class="st-num" id="countTotal">—</div><div class="st-label">هەموو چاوەڕوانەکان</div></div>
        </div>
        <div class="stat-tile" style="border-right-color:#0078d4;">
            <div class="st-icon" style="background:#deecf9; color:#0078d4;"><i class="fas fa-briefcase-medical"></i></div>
            <div><div class="st-num" id="countReport">—</div><div class="st-label">کەیس / چارەسەر</div></div>
        </div>
        <div class="stat-tile" style="border-right-color:#107c10;">
            <div class="st-icon" style="background:#dff6dd; color:#107c10;"><i class="fas fa-money-bill-wave"></i></div>
            <div><div class="st-num" id="countPayment">—</div><div class="st-label">داواکاری پارەدان</div></div>
        </div>
        <div class="stat-tile" style="border-right-color:#ff8c00;">
            <div class="st-icon" style="background:#fff4ce; color:#ff8c00;"><i class="fas fa-x-ray"></i></div>
            <div><div class="st-num" id="countXray">—</div><div class="st-label">پشکنین / ئێکسڕەی</div></div>
        </div>
        <div class="stat-tile" style="border-right-color:#00695c;">
            <div class="st-icon" style="background:#e0f2f1; color:#00695c;"><i class="fas fa-cash-register"></i></div>
            <div><div class="st-num" id="countStation">—</div><div class="st-label">نەخۆش لە وێستگە</div></div>
        </div>
    </div>

    <div class="filters-card">
        <div class="kind-tabs" id="kindTabs">
            <button type="button" class="kind-tab active" data-kind="all">هەموو <span class="kt-count" id="tabCountAll">0</span></button>
            <button type="button" class="kind-tab" data-kind="report">کەیس / چارەسەر <span class="kt-count" id="tabCountReport">0</span></button>
            <button type="button" class="kind-tab" data-kind="payment">پارەدان <span class="kt-count" id="tabCountPayment">0</span></button>
            <button type="button" class="kind-tab" data-kind="xray">پشکنین <span class="kt-count" id="tabCountXray">0</span></button>
            <button type="button" class="kind-tab" data-kind="station">وێستگە <span class="kt-count" id="tabCountStation">0</span></button>
        </div>
        <div class="recv-search">
            <input type="text" id="feedSearch" placeholder="گەڕان: ناوی نەخۆش، کۆدی نەخۆش، ژمارەی کەیس، دکتۆر...">
            <i class="fas fa-search"></i>
        </div>
        <div class="status-tabs" id="statusTabs">
            <button type="button" class="status-tab active" data-status="all">هەموو</button>
            <button type="button" class="status-tab" data-status="pending">چاوەڕوان <span class="st-count" id="stCountPending">0</span></button>
            <button type="button" class="status-tab" data-status="seen">بینراوەکان <span class="st-count" id="stCountSeen">0</span></button>
        </div>
        <div class="scope-toggle">
            <button type="button" data-scope="open" class="active">کراوەکان</button>
            <button type="button" data-scope="all">مێژوو (٣٠ ڕۆژ)</button>
        </div>
    </div>

    <div class="table-card">
        <div class="table-scroll">
            <table class="recv-table no-responsive" data-no-responsive="true">
                <thead>
                    <tr>
                        <th style="width:120px;">جۆر</th>
                        <th>نەخۆش</th>
                        <th>دکتۆر</th>
                        <th>کەیس / ژمارە</th>
                        <th>بڕ</th>
                        <th>مەبەست</th>
                        <th style="width:110px;">کات</th>
                        <th style="width:120px;">دۆخ</th>
                        <th style="width:70px;">بینین</th>
                    </tr>
                </thead>
                <tbody id="feedBody">
                    <tr><td colspan="9" class="recv-loading"><i class="fas fa-spinner fa-spin"></i> بارکردنی داتاکان...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Eye / detail modal -->
<div class="dialog-overlay" id="feedDetailDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h3 class="dialog-title" id="fdTitle"><i class="fas fa-eye" style="color:#0078d4;"></i> وردەکاری</h3>
            <button class="dialog-close" type="button" onclick="closeDialog('feedDetailDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body" id="fdBody">
            <div class="recv-loading"><i class="fas fa-spinner fa-spin"></i> بارکردن...</div>
        </div>
        <div class="dialog-footer" style="display:flex; gap:8px; justify-content:flex-start;">
            <a class="btn btn-primary" id="fdOpenPage" style="display:none;">
                <i class="fas fa-external-link-alt"></i> کردنەوەی پەڕەی جێبەجێکردن
            </a>
            <button class="btn" type="button" onclick="closeDialog('feedDetailDialog')">داخستن</button>
        </div>
    </div>
</div>

<!-- X-ray settle dialog — collect payment / approve debt & send to x-ray, in place -->
<div class="dialog-overlay" id="xraySettleDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h3 class="dialog-title"><i class="fas fa-x-ray" style="color:#d83b01;"></i> وەرگرتنی پارەی ئێکسڕەی</h3>
            <button class="dialog-close" type="button" onclick="closeXraySettle()"><i class="fas fa-times"></i></button>
        </div>
        <form id="xraySettleForm">
            <div class="dialog-body">
                <div id="xsHeader" style="background:#f7f8fa;border:1px solid #eef0f3;border-radius:8px;padding:8px 10px;margin-bottom:12px;font-size:13px;"></div>
                <input type="hidden" id="xs_id">
                <div class="xs-fld">
                    <label>کردار</label>
                    <select id="xs_action" onchange="xsActionChanged()" required>
                        <option value="">کردارێک هەڵبژێرە</option>
                        <option value="approve_payment">پەسەندکردنی پارەدان و ناردن بۆ ئێکسڕەی</option>
                        <option value="approve_debt">پەسەندکردنی قەرز و ناردن بۆ ئێکسڕەی</option>
                    </select>
                </div>
                <div class="xs-fld" id="xs_amount_group" style="display:none;">
                    <label>بڕی ڕاستکراوە</label>
                    <input type="number" id="xs_amount" step="1000" min="0">
                    <small id="xs_amount_ctx" style="display:block;color:#b35c00;margin-top:3px;"></small>
                </div>
                <div class="xs-fld" id="xs_discount_group" style="display:none;">
                    <label>داشکاندن</label>
                    <input type="number" id="xs_discount" step="1000" min="0" placeholder="0">
                </div>
                <div class="xs-fld" id="xs_reason_group" style="display:none;">
                    <label>هۆکاری داشکاندن</label>
                    <input type="text" id="xs_reason" placeholder="هۆکار بنووسە ئەگەر داشکاندن هەیە">
                </div>
                <div class="xs-fld">
                    <label>تێبینی / هۆکار</label>
                    <textarea id="xs_note" rows="2" placeholder="ئارەزوومەندانە..."></textarea>
                </div>
                <div id="xs_error" style="color:#b42318;font-size:12.5px;display:none;background:#fef3f2;border-radius:8px;padding:7px 10px;"></div>
            </div>
            <div class="dialog-footer" style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn" onclick="closeXraySettle()">داخستن</button>
                <button type="submit" class="btn btn-primary" id="xsSubmit"><i class="fas fa-check"></i> جێبەجێکردن</button>
            </div>
        </form>
    </div>
</div>
<style>
.xs-fld { margin-bottom:11px; }
.xs-fld label { display:block; font-size:12px; font-weight:600; color:#475467; margin-bottom:4px; }
.xs-fld input, .xs-fld select, .xs-fld textarea { width:100%; padding:8px 11px; border:1px solid #e4e7ec; border-radius:8px; font-family:inherit; font-size:13px; box-sizing:border-box; background:#fcfcfd; }
.xs-fld input:focus, .xs-fld select:focus, .xs-fld textarea:focus { border-color:#0078d4; outline:none; background:#fff; }
</style>

<!-- Purpose-only modal (the small eye in the مەبەست column) -->
<div class="dialog-overlay" id="purposeDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h3 class="dialog-title"><i class="fas fa-bullseye" style="color:#ff8c00;"></i> مەبەستی ناردن</h3>
            <button class="dialog-close" type="button" onclick="closeDialog('purposeDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body" id="ppBody"></div>
        <div class="dialog-footer" style="display:flex; justify-content:flex-start;">
            <button class="btn" type="button" onclick="closeDialog('purposeDialog')">داخستن</button>
        </div>
    </div>
</div>

<script>
/**
 * Live receiving feed — polls api/accountant-feed.php every 8 seconds.
 * New rows since the previous poll flash, bump the badge and play a sound.
 */
(function () {
    const API = (window.APP_URL || '<?= APP_URL ?>') + '/api/accountant-feed.php';
    const POLL_MS = 8000;

    const KIND_STYLES = {
        report:  { icon: 'fa-briefcase-medical', bg: '#deecf9', color: '#0063b1' },
        note:    { icon: 'fa-sticky-note',       bg: '#f0e6fa', color: '#8764b8' },
        payment: { icon: 'fa-money-bill-wave',   bg: '#dff6dd', color: '#107c10' },
        xray:    { icon: 'fa-x-ray',             bg: '#fff4ce', color: '#b56a00' },
        station: { icon: 'fa-cash-register',     bg: '#e0f2f1', color: '#00695c' }
    };

    const state = {
        kind: 'all',
        scope: 'open',
        status: 'all',        // all | pending | seen (client-side bucket filter)
        search: '',
        items: [],            // last fetched rows (unfiltered)
        since: null,          // server_time of the last completed poll
        timer: null,
        newTotal: 0,          // unseen arrivals since page focus
        baseTitle: document.title,
        soundEnabled: localStorage.getItem('acctFeedSound') !== '0',
        audioUnlocked: false
    };

    // Which bucket a row belongs to: چاوەڕوان (untouched), بینراوە (opened
    // by the accountant), or already settled/processed.
    function seenState(it) {
        if (it.status === 'reviewed') return 'seen';
        if (['pending', 'pending_accountant', 'returned_to_accountant', 'at_accounting'].includes(it.status)) return 'pending';
        return 'done';
    }

    const sound = new Audio((window.APP_URL || '<?= APP_URL ?>') + '/assets/sounds/notification.wav');
    sound.preload = 'auto';

    const $ = (id) => document.getElementById(id);

    function esc(v) {
        const d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }

    function fmtNum(n) {
        return new Intl.NumberFormat('en-US').format(Math.round(n));
    }

    // ---------- sound ----------

    function renderSoundToggle() {
        const btn = $('soundToggle');
        btn.classList.toggle('muted', !state.soundEnabled);
        btn.innerHTML = state.soundEnabled ? '<i class="fas fa-volume-up"></i>' : '<i class="fas fa-volume-mute"></i>';
        btn.title = state.soundEnabled ? 'دەنگ کوژاندنەوە' : 'دەنگ هەڵکردن';
    }

    function playSound() {
        if (!state.soundEnabled) return;
        try { sound.currentTime = 0; sound.play().catch(() => {}); } catch (e) { /* ignore */ }
    }

    $('soundToggle').addEventListener('click', () => {
        state.soundEnabled = !state.soundEnabled;
        localStorage.setItem('acctFeedSound', state.soundEnabled ? '1' : '0');
        renderSoundToggle();
        if (state.soundEnabled) playSound();
    });

    // Browsers block audio before the first user gesture — unlock silently
    const unlock = () => {
        if (state.audioUnlocked) return;
        state.audioUnlocked = true;
        sound.muted = true;
        sound.play().then(() => { sound.pause(); sound.currentTime = 0; sound.muted = false; })
                    .catch(() => { sound.muted = false; });
        document.removeEventListener('click', unlock);
        document.removeEventListener('keydown', unlock);
    };
    document.addEventListener('click', unlock);
    document.addEventListener('keydown', unlock);

    // ---------- fetching + rendering ----------

    function buildUrl() {
        const p = new URLSearchParams({ action: 'list', kind: state.kind, scope: state.scope });
        if (state.search) p.set('search', state.search);
        if (state.since) p.set('since', state.since);
        return API + '?' + p.toString();
    }

    async function load(isPoll) {
        try {
            const res = await fetch(buildUrl());
            const data = await res.json();
            if (!data.success) return;

            renderCounts(data.counts);

            const newUids = isPoll && state.since ? (data.new_uids || []) : [];
            renderRows(data.items, new Set(newUids));

            if (isPoll && state.since && data.new_count > 0) {
                state.newTotal += data.new_count;
                announceNew();
            }
            state.since = data.server_time;
        } catch (e) {
            console.error('feed load error:', e);
        }
    }

    function renderCounts(c) {
        $('countTotal').textContent = fmtNum(c.total);
        $('countReport').textContent = fmtNum(c.report);
        $('countPayment').textContent = fmtNum(c.payment);
        $('countXray').textContent = fmtNum(c.xray);
        $('countStation').textContent = fmtNum(c.station || 0);
        $('tabCountAll').textContent = fmtNum(c.total);
        $('tabCountReport').textContent = fmtNum(c.report);
        $('tabCountPayment').textContent = fmtNum(c.payment);
        $('tabCountXray').textContent = fmtNum(c.xray);
        $('tabCountStation').textContent = fmtNum(c.station || 0);
    }

    function renderRows(items, newUids) {
        state.items = items;

        // Bucket counts for the status tabs (over everything fetched)
        let nPending = 0, nSeen = 0;
        items.forEach((it) => {
            const st = seenState(it);
            if (st === 'pending') nPending++;
            if (st === 'seen') nSeen++;
        });
        $('stCountPending').textContent = fmtNum(nPending);
        $('stCountSeen').textContent = fmtNum(nSeen);

        const visible = state.status === 'all'
            ? items
            : items.filter((it) => seenState(it) === state.status);

        const body = $('feedBody');
        if (!visible.length) {
            body.innerHTML = `<tr><td colspan="9" class="recv-empty">
                <i class="fas fa-inbox"></i> هیچ نێردراوێک نییە
            </td></tr>`;
            return;
        }

        body.innerHTML = visible.map((it) => {
            const ks = KIND_STYLES[it.kind] || KIND_STYLES.report;
            const st = seenState(it);
            const statusCls = st === 'pending' ? 'chip-open' : (st === 'seen' ? 'chip-seen' : 'chip-done');
            const amount = it.amount !== null && it.amount !== undefined && it.amount !== ''
                ? fmtNum(it.amount) : '—';
            return `
            <tr class="${newUids.has(it.uid) ? 'row-new' : ''}" data-uid="${esc(it.uid)}">
                <td><span class="kind-badge" style="background:${ks.bg}; color:${ks.color};">
                    <i class="fas ${ks.icon}"></i> ${esc(it.kind_label)}</span></td>
                <td><strong>${esc(it.patient_name)}</strong>
                    <div style="font-size:11px; color:#999;">${esc(it.patient_code || '')}</div></td>
                <td>${esc(it.doctor_name || '—')}</td>
                <td style="font-size:12px; color:#555;">${esc(it.case_number || '—')}</td>
                <td style="font-weight:600; color:#1a1a1a;">${amount}</td>
                <td class="purpose-cell">
                    <div class="purpose-wrap">
                        <button type="button" class="btn-eye-sm" title="بینینی مەبەستی ناردن" data-uid="${esc(it.uid)}">
                            <i class="fas fa-eye"></i></button>
                        <div>
                            <div class="purpose-label" style="color:${ks.color};">${esc(it.purpose || it.kind_label)}</div>
                            ${it.note ? `<div class="purpose-note" title="${esc(it.note)}">${esc(it.note)}</div>` : ''}
                        </div>
                    </div></td>
                <td style="font-size:11px; color:#888; white-space:nowrap;">${esc(it.time_ago)}</td>
                <td><span class="status-chip ${statusCls}">${esc(it.status_label)}</span></td>
                <td style="white-space:nowrap;">
                    <a class="btn-eye" title="کردنەوەی پەڕەی جێبەجێکردن"
                        href="${esc(it.page_url || '#')}" data-uid="${esc(it.uid)}">
                        <i class="fas fa-eye"></i></a>
                    ${['report','note'].includes(it.kind) ? `<button type="button" class="btn-hide" title="لابردن لە لیست" data-hide-uid="${esc(it.uid)}"><i class="fas fa-eye-slash"></i></button>` : ''}
                </td>
            </tr>`;
        }).join('');
    }

    // New-arrival alert: badge + blinking tab title + one sound per batch
    function announceNew() {
        const badge = $('newBadge');
        badge.textContent = state.newTotal + ' نێردراوی نوێ';
        badge.style.display = 'inline-flex';
        badge.style.animation = 'none';
        requestAnimationFrame(() => { badge.style.animation = ''; });
        document.title = '(' + state.newTotal + ') ' + state.baseTitle;
        playSound();
    }

    function clearNewFlag() {
        state.newTotal = 0;
        $('newBadge').style.display = 'none';
        document.title = state.baseTitle;
    }
    document.addEventListener('click', clearNewFlag);

    // ---------- polling ----------

    function startPolling() {
        if (state.timer) return;
        state.timer = setInterval(() => load(true), POLL_MS);
    }
    function stopPolling() {
        clearInterval(state.timer);
        state.timer = null;
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { stopPolling(); }
        else { load(true); startPolling(); }
    });

    // ---------- filters ----------

    document.querySelectorAll('#kindTabs .kind-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('#kindTabs .kind-tab').forEach((t) => t.classList.remove('active'));
            tab.classList.add('active');
            state.kind = tab.dataset.kind;
            load(false);
        });
    });

    document.querySelectorAll('.scope-toggle button').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.scope-toggle button').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            state.scope = btn.dataset.scope;
            load(false);
        });
    });

    // Status buckets (چاوەڕوان / بینراوەکان) filter locally — no refetch needed
    document.querySelectorAll('#statusTabs .status-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('#statusTabs .status-tab').forEach((t) => t.classList.remove('active'));
            tab.classList.add('active');
            state.status = tab.dataset.status;
            renderRows(state.items, new Set());
        });
    });

    let searchTimer = null;
    $('feedSearch').addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.search = this.value.trim();
            load(false);
        }, 400);
    });

    // ---------- mark as seen (بینراوە) ----------

    // Opening either eye on an untouched report turns it 'reviewed': it moves
    // to the بینراوەکان bucket and its chip goes blue. Payment / x-ray rows
    // keep their own workflow statuses until they are actually settled.
    async function markSeen(it) {
        if (!it || !['report', 'note'].includes(it.kind) || it.status !== 'pending') return;
        try {
            const res = await fetch(API + '?action=mark_seen', {
                method: 'POST',
                keepalive: true,   // finish even if we navigate to the detail page
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ kind: it.kind, id: it.id }).toString()
            });
            const data = await res.json();
            if (data.success && data.status) {
                it.status = data.status;
                it.status_label = data.status_label || it.status_label;
                it.is_open = ['pending', 'reviewed'].includes(data.status) ? 1 : 0;
                renderRows(state.items, new Set());
            }
        } catch (e) { /* seen-marking is best-effort */ }
    }

    function findItem(uid) {
        return state.items.find((it) => it.uid === uid);
    }

    // ---------- purpose modal (the small eye in the مەبەست column) ----------

    function openPurpose(uid) {
        const it = findItem(uid);
        if (!it) return;
        const ks = KIND_STYLES[it.kind] || KIND_STYLES.report;
        $('ppBody').innerHTML = `
            <div class="pp-purpose" style="color:${ks.color};">
                <i class="fas ${ks.icon}"></i> ${esc(it.purpose || it.kind_label)}
            </div>
            <div class="pp-text">${it.note ? esc(it.note) : '<span style="color:#aaa;">هیچ دەقێک نەنووسراوە</span>'}</div>
            <div class="pp-meta">
                <span><i class="fas fa-user-injured"></i> ${esc(it.patient_name)}</span>
                <span><i class="fas fa-user-md"></i> ${esc(it.doctor_name || '—')}</span>
                <span><i class="far fa-clock"></i> ${esc(it.time_ago)}</span>
            </div>`;
        openDialog('purposeDialog');
        markSeen(it);
    }

    // ---------- detail modal (eye) ----------

    // Hide (archive) a report so it drops off the feed — reversible from
    // pending-reports "لابراوەکان" / report-detail restore.
    async function hideItem(it) {
        if (!it || !['report', 'note'].includes(it.kind)) return;
        if (!confirm('ئەم ڕاپۆرتە لاببرێت لە لیستەکان؟ (دەتوانیت پاشان بیگەڕێنیتەوە)')) return;
        try {
            const res = await fetch(API + '?action=hide', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ kind: it.kind, id: it.id }).toString()
            });
            const data = await res.json();
            if (data.success) {
                state.items = state.items.filter((x) => x.uid !== it.uid);
                renderRows(state.items, new Set());
            }
        } catch (e) { /* best-effort */ }
    }

    $('feedBody').addEventListener('click', (e) => {
        const hideBtn = e.target.closest('.btn-hide');
        if (hideBtn) {
            e.stopPropagation();
            hideItem(findItem(hideBtn.dataset.hideUid));
            return;
        }
        const smallEye = e.target.closest('.btn-eye-sm');
        if (smallEye) {
            openPurpose(smallEye.dataset.uid);
            return;
        }
        const eye = e.target.closest('.btn-eye');
        if (eye) {
            const it = findItem(eye.dataset.uid);
            // X-ray → open the collect-money dialog right here (no page change).
            if (it && it.kind === 'xray') {
                e.preventDefault();
                openXraySettle(it);
                return;
            }
            // Everything else → go straight to the item's own page. Mark it seen
            // on the way out (keepalive lets the request finish during navigation).
            markSeen(it);
            // (no preventDefault — the anchor navigates normally)
        }
    });

    // ---------- x-ray settle (collect / debt + send to x-ray) ----------
    function openXraySettle(it) {
        document.getElementById('xs_id').value = it.id;
        const disc = Number(it.discount || 0), fin = Number(it.final_amount || it.amount || 0);
        document.getElementById('xsHeader').innerHTML =
            '<strong>' + esc(it.patient_name || '') + '</strong> · ' + esc(it.case_number || '') +
            '<br><span style="color:#98a2b3;font-size:12px;">' + esc(it.note || it.purpose || '') + '</span>';
        document.getElementById('xs_amount').value = it.amount || 0;
        document.getElementById('xs_amount_ctx').textContent = disc > 0
            ? ('نرخی بنەڕەت: ' + Number(it.amount).toLocaleString() + ' — داشکاندن: ' + disc.toLocaleString() + ' — کۆتایی: ' + fin.toLocaleString())
            : '';
        document.getElementById('xs_discount').value = '';
        document.getElementById('xs_reason').value = '';
        document.getElementById('xs_action').value = '';
        document.getElementById('xs_note').value = '';
        document.getElementById('xs_error').style.display = 'none';
        document.getElementById('xsSubmit').disabled = false;
        xsActionChanged();
        openDialog('xraySettleDialog');
    }
    window.closeXraySettle = function () { closeDialog('xraySettleDialog'); };
    window.xsActionChanged = function () {
        const on = ['approve_payment', 'approve_debt'].includes(document.getElementById('xs_action').value);
        document.getElementById('xs_amount_group').style.display = on ? 'block' : 'none';
        document.getElementById('xs_discount_group').style.display = on ? 'block' : 'none';
        document.getElementById('xs_reason_group').style.display = on ? 'block' : 'none';
    };
    function xsError(msg) {
        const el = document.getElementById('xs_error');
        el.textContent = msg; el.style.display = 'block';
    }
    document.getElementById('xraySettleForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const act = document.getElementById('xs_action').value;
        if (!act) { xsError('تکایە کردارێک هەڵبژێرە.'); return; }
        const disc = Number(document.getElementById('xs_discount').value || 0);
        const reason = document.getElementById('xs_reason').value.trim();
        if (disc > 0 && reason === '') { xsError('تکایە هۆکاری داشکاندن بنووسە.'); return; }
        const label = document.getElementById('xs_action').selectedOptions[0].text;
        if (!confirm('دڵنیایت لە جێبەجێکردنی: ' + label + '؟')) return;
        const btn = document.getElementById('xsSubmit');
        btn.disabled = true;
        try {
            const body = new URLSearchParams({
                xray_id: document.getElementById('xs_id').value,
                accountant_action: act,
                new_amount: document.getElementById('xs_amount').value,
                discount_amount: document.getElementById('xs_discount').value,
                discount_reason: reason,
                action_note: document.getElementById('xs_note').value
            });
            const res = await fetch(API + '?action=xray_settle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const data = await res.json();
            if (data.success) {
                const id = document.getElementById('xs_id').value;
                state.items = state.items.filter((x) => !(x.kind === 'xray' && String(x.id) === String(id)));
                renderRows(state.items, new Set());
                closeXraySettle();
            } else {
                xsError(data.error || 'کردارەکە جێبەجێ نەکرا');
                btn.disabled = false;
            }
        } catch (err) {
            xsError('کێشەی پەیوەندی ڕوویدا');
            btn.disabled = false;
        }
    });

    // Collapsible sections inside the modal (services list starts closed)
    $('fdBody').addEventListener('click', (e) => {
        const head = e.target.closest('.fd-collapse-head');
        if (head) head.parentElement.classList.toggle('open');
    });

    async function openDetail(kind, id) {
        $('fdBody').innerHTML = '<div class="recv-loading"><i class="fas fa-spinner fa-spin"></i> بارکردن...</div>';
        $('fdOpenPage').style.display = 'none';
        openDialog('feedDetailDialog');

        try {
            const res = await fetch(API + '?action=detail&kind=' + encodeURIComponent(kind) + '&id=' + encodeURIComponent(id));
            const data = await res.json();
            if (!data.success) {
                $('fdBody').innerHTML = '<div class="recv-empty">هەڵە: نەدۆزرایەوە</div>';
                return;
            }
            renderDetail(data);
        } catch (e) {
            $('fdBody').innerHTML = '<div class="recv-empty">هەڵەی پەیوەندی ڕوویدا</div>';
        }
    }

    function renderDetail(d) {
        const h = d.header;
        $('fdTitle').innerHTML = '<i class="fas fa-eye" style="color:#0078d4;"></i> ' + esc(h.title);

        let html = `<div class="fd-head-grid">
            <div class="fd-head-item"><div class="lbl">نەخۆش</div>
                <div class="val">${esc(h.patient_name)} <span style="color:#999; font-weight:400; font-size:11px;">${esc(h.patient_code || '')}</span></div></div>
            <div class="fd-head-item"><div class="lbl">ژمارەی مۆبایل</div><div class="val">${esc(h.patient_phone || '—')}</div></div>
            <div class="fd-head-item"><div class="lbl">دکتۆر</div><div class="val">${esc(h.doctor_name || '—')}</div></div>
            <div class="fd-head-item"><div class="lbl">کاتی ناردن</div><div class="val">${esc(h.sent_at || '—')}</div></div>
            <div class="fd-head-item"><div class="lbl">دۆخ</div><div class="val">${esc(h.status_label || '—')}</div></div>
        </div>`;

        (d.rows || []).forEach((r) => {
            html += `<div style="display:flex; justify-content:space-between; padding:7px 4px; border-bottom:1px dashed #eee; font-size:13px;">
                <span style="color:#777;">${esc(r.label)}</span><strong>${esc(r.value)}</strong></div>`;
        });

        if ((d.notes_history || []).length) {
            html += '<div class="fd-section-title"><i class="fas fa-comment-medical"></i> تێبینی و ڕاسپاردەکانی دکتۆر</div>';
            d.notes_history.forEach((n) => {
                if (!String(n.notes || '').trim()) return;
                html += `<div class="fd-note">
                    <div class="fd-note-text">${esc(n.notes)}</div>
                    <div class="fd-note-meta">
                        <span><i class="fas fa-user-md"></i> ${esc(n.doctor_name)}</span>
                        <span><i class="far fa-clock"></i> ${esc(n.time_ago)} (${esc(n.sent_at)})</span>
                    </div></div>`;
            });
        }

        if ((d.services || []).length) {
            const svcTotal = d.services.reduce((sum, s) => sum + (Number(s.price) || 0), 0);
            html += `<div class="fd-collapse">
                <button type="button" class="fd-collapse-head">
                    <i class="fas fa-tooth"></i> خزمەتگوزاری و چارەسەرەکان
                    <span class="fc-count">${d.services.length}</span>
                    <span class="fc-total">کۆ: ${fmtNum(svcTotal)}</span>
                    <i class="fas fa-chevron-down fc-arrow"></i>
                </button>
                <div class="fd-collapse-body">
                <table class="fd-table"><thead><tr><th>خزمەتگوزاری</th><th>نرخ</th><th>بەروار</th></tr></thead><tbody>`;
            d.services.forEach((s) => {
                html += `<tr><td>${esc(s.name)}${s.notes ? '<div style="font-size:10px;color:#999;">' + esc(s.notes) + '</div>' : ''}</td>
                    <td>${fmtNum(s.price)}</td><td style="font-size:11px;color:#888;">${esc(s.done_at || '—')}</td></tr>`;
            });
            html += '</tbody></table></div></div>';
        }

        if ((d.payments || []).length) {
            html += `<div class="fd-section-title"><i class="fas fa-money-check-alt"></i> پارەدانەکان</div>
            <table class="fd-table"><thead><tr><th>ژمارە</th><th>بڕ</th><th>جۆر</th><th>دۆخ</th><th>بەروار</th></tr></thead><tbody>`;
            d.payments.forEach((p) => {
                html += `<tr><td style="font-size:11px;">${esc(p.payment_number)}</td>
                    <td style="font-weight:600;">${fmtNum(p.amount)}</td>
                    <td>${esc(p.type_label)} / ${esc(p.method_label)}</td>
                    <td>${esc(p.status_label)}</td>
                    <td style="font-size:11px;color:#888;">${esc(p.payment_date)}</td></tr>`;
            });
            html += '</tbody></table>';
        }

        if (d.financial) {
            const f = d.financial;
            html += `<div class="fd-section-title"><i class="fas fa-chart-pie"></i> پوختەی دارایی کەیس</div>
            <div class="fd-fin">
                <div class="fd-fin-box" style="background:#deecf9;"><div class="n" style="color:#0063b1;">${fmtNum(f.total)}</div><div class="l" style="color:#0063b1;">کۆی گشتی</div></div>
                <div class="fd-fin-box" style="background:#dff6dd;"><div class="n" style="color:#107c10;">${fmtNum(f.paid)}</div><div class="l" style="color:#107c10;">دراوە</div></div>
                <div class="fd-fin-box" style="background:#f0e6fa;"><div class="n" style="color:#8764b8;">${fmtNum(f.discount)}</div><div class="l" style="color:#8764b8;">داشکاندن</div></div>
                <div class="fd-fin-box" style="background:#fde7e9;"><div class="n" style="color:#c50f1f;">${fmtNum(f.remaining)}</div><div class="l" style="color:#c50f1f;">ماوە</div></div>
            </div>`;
        }

        $('fdBody').innerHTML = html;

        const openBtn = $('fdOpenPage');
        if (d.page_url) {
            openBtn.href = d.page_url;
            openBtn.style.display = '';
        }
    }

    // ---------- boot ----------

    renderSoundToggle();
    load(false).then(() => { state.newTotal = 0; });
    startPolling();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
