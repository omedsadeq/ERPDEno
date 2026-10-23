<?php
/**
 * ERP — sales invoice browser.
 *
 * The missing way in: until now a saved invoice could only be reached if you
 * still had its number. This lists them with their settlement state and the
 * actions each one allows — print, edit, cancel.
 *
 * Server-side paged and filtered; nothing loads the whole history.
 */

require_once __DIR__ . '/_bootstrap.php';
erp_require('erp.sales.view');

$pageTitle  = 'وەسڵەکانی فرۆشتن';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'وەسڵەکان'],
];

$canEdit   = erp_can('erp.sales.edit');
$canCancel = erp_can('erp.invoice.cancel');
$canProfit = erp_can('erp.profit.view');

$customCSS = <<<CSS
.erp-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
.erp-toolbar label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.erp-toolbar .form-control{height:34px;padding:4px 10px;font-size:13px}
.erp-search{min-width:220px}
.erp-grid{font-size:13px}
.erp-grid td,.erp-grid th{padding:6px 10px}
.erp-num{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.erp-muted{color:#9e9e9e}
.erp-empty{text-align:center;padding:34px;color:#8a8a8a}
.st{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600}
.st-confirmed{background:#e8f5e9;color:#2e7d32}
.st-cancelled{background:#ffebee;color:#c62828}
.st-draft{background:#eceff1;color:#607d8b}
.due{color:#c62828;font-weight:700}
.paid{color:#2e7d32}
.erp-pager{display:flex;gap:6px;justify-content:center;margin-top:12px;flex-wrap:wrap}
tr.is-cancelled td{opacity:.55}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-body">
        <div class="erp-toolbar">
            <div>
                <label>گەڕان</label>
                <input type="search" id="q" class="form-control erp-search"
                       placeholder="ژمارەی وەسڵ یان ناوی کڕیار..." autocomplete="off">
            </div>
            <div><label>لە</label><input type="date" id="from" class="form-control"></div>
            <div><label>بۆ</label><input type="date" id="to" class="form-control"></div>
            <div>
                <label>دۆخ</label>
                <select id="status" class="form-control" style="max-width:150px">
                    <option value="">هەموو</option>
                    <option value="confirmed" selected>پەسەندکراو</option>
                    <option value="cancelled">هەڵوەشاو</option>
                </select>
            </div>
            <span id="count" class="erp-muted" style="margin-inline-start:auto;font-size:12px"></span>
        </div>

        <div class="table-container">
            <table class="data-grid erp-grid no-responsive">
                <thead>
                    <tr>
                        <th style="width:150px">ژمارە</th>
                        <th style="width:100px">بەروار</th>
                        <th>کڕیار</th>
                        <th style="width:110px" class="erp-num">کۆ</th>
                        <th style="width:110px" class="erp-num">دراو</th>
                        <th style="width:110px" class="erp-num">ماوە</th>
                        <?php if ($canProfit): ?><th style="width:100px" class="erp-num">قازانج</th><?php endif; ?>
                        <th style="width:100px">دۆخ</th>
                        <th style="width:120px"></th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <tr><td colspan="<?= $canProfit ? 9 : 8 ?>" class="erp-empty">
                        <i class="fas fa-spinner fa-spin"></i> بارکردن...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="erp-pager" id="pager"></div>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiS      = APP_URL . '/erp/api/sales.php';
$appUrl    = APP_URL;
$cols      = $canProfit ? 9 : 8;
$jsEdit    = $canEdit ? 'true' : 'false';
$jsCancel  = $canCancel ? 'true' : 'false';
$jsProfit  = $canProfit ? 'true' : 'false';

$customJS = <<<JS
const API_S = '{$apiS}', APP = '{$appUrl}';
const COLS = {$cols}, CAN_EDIT = {$jsEdit}, CAN_CANCEL = {$jsCancel}, CAN_PROFIT = {$jsProfit};
const U = window.ErpUI;
let page = 1, timer = null, reqId = 0;

function params() {
    const p = new URLSearchParams({ action: 'browse', page: page });
    const q = document.getElementById('q').value.trim();
    if (q) p.set('q', q);
    const f = document.getElementById('from').value;
    const t = document.getElementById('to').value;
    if (f) p.set('from', f);
    if (t) p.set('to', t);
    const s = document.getElementById('status').value;
    if (s) p.set('status', s);
    return p;
}

async function load() {
    const mine = ++reqId;
    const d = await U.getJson(API_S + '?' + params().toString());
    if (mine !== reqId) return;             // a newer keystroke already fired
    if (!d || !d.ok) { message('هەڵە'); return; }
    render(d);
}

function message(t) {
    document.getElementById('rows').innerHTML =
        '<tr><td colspan="' + COLS + '" class="erp-empty">' + U.esc(t) + '</td></tr>';
    document.getElementById('pager').innerHTML = '';
}

const ST = { confirmed: 'پەسەندکراو', cancelled: 'هەڵوەشاو', draft: 'ڕەشنووس' };

function render(d) {
    const rows = d.results || [];
    document.getElementById('count').textContent = d.total ? U.nf(d.total) + ' وەسڵ' : '';
    if (!rows.length) { message('هیچ وەسڵێک نەدۆزرایەوە'); return; }

    document.getElementById('rows').innerHTML = rows.map(r => {
        const remaining = Number(r.grand_total) - Number(r.paid_amount);
        const cancelled = r.status === 'cancelled';
        return '<tr class="' + (cancelled ? 'is-cancelled' : '') + '">'
          + '<td dir="ltr" class="erp-num">' + U.esc(r.invoice_number) + '</td>'
          + '<td dir="ltr">' + U.esc(r.invoice_date) + '</td>'
          + '<td>' + U.esc(r.customer_name) + '</td>'
          + '<td class="erp-num">' + U.nf(r.grand_total) + '</td>'
          + '<td class="erp-num paid">' + U.nf(r.paid_amount) + '</td>'
          + '<td class="erp-num ' + (remaining > 0.009 ? 'due' : '') + '">' + U.nf(remaining) + '</td>'
          + (CAN_PROFIT ? '<td class="erp-num">' + U.nf(Number(r.grand_total) - Number(r.total_cost)) + '</td>' : '')
          + '<td><span class="st st-' + U.esc(r.status) + '">' + U.esc(ST[r.status] || r.status) + '</span></td>'
          + '<td style="white-space:nowrap">'
          +   '<a class="btn btn-icon btn-sm" title="چاپ" target="_blank" '
          +     'href="' + APP + '/erp/print-invoice.php?id=' + Number(r.id) + '&autoprint=0">'
          +     '<i class="fas fa-print"></i></a>'
          // Edit and cancel are meaningless on an already-cancelled invoice,
          // so the buttons are omitted rather than shown and then refused.
          +   ((CAN_EDIT && !cancelled)
                ? '<a class="btn btn-icon btn-sm" title="دەستکاری" '
                  + 'href="' + APP + '/erp/sales.php?edit=' + Number(r.id) + '">'
                  + '<i class="fas fa-pen"></i></a>' : '')
          +   ((CAN_CANCEL && !cancelled)
                ? '<button class="btn btn-icon btn-sm" title="هەڵوەشاندنەوە" '
                  + 'onclick="cancelInvoice(' + Number(r.id) + ',\\'' + U.esc(r.invoice_number) + '\\')">'
                  + '<i class="fas fa-ban"></i></button>' : '')
          + '</td></tr>';
    }).join('');
    pager(d.page, d.totalPages);
}

function pager(p, total) {
    const el = document.getElementById('pager');
    if (!total || total < 2) { el.innerHTML = ''; return; }
    let h = '';
    const from = Math.max(1, p - 2), to = Math.min(total, p + 2);
    if (p > 1) h += '<button class="btn btn-sm" onclick="go(' + (p-1) + ')">‹</button>';
    for (let i = from; i <= to; i++) {
        h += '<button class="btn btn-sm' + (i === p ? ' btn-primary' : '') + '" onclick="go(' + i + ')">' + i + '</button>';
    }
    if (p < total) h += '<button class="btn btn-sm" onclick="go(' + (p+1) + ')">›</button>';
    el.innerHTML = h;
}
function go(p) { page = p; load(); }

async function cancelInvoice(id, number) {
    const reason = prompt('هۆکاری هەڵوەشاندنەوەی وەسڵی ' + number + ':');
    if (reason === null) return;
    const fd = new FormData();
    fd.set('action', 'cancel');
    fd.set('id', id);
    fd.set('reason', reason);
    try {
        const res = await fetch(API_S + '?action=cancel', { method: 'POST', body: fd });
        const d = await res.json();
        if (!d.ok) { showToast('error', d.error || 'هەڵە'); return; }
        showToast('success', 'وەسڵ هەڵوەشێنرایەوە — کاڵاکان گەڕێندرانەوە');
        load();
    } catch (e) {
        showToast('error', 'کێشەی پەیوەندی ڕوویدا');
    }
}

document.getElementById('q').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { page = 1; load(); }, 250);
});
['from', 'to', 'status'].forEach(id =>
    document.getElementById(id).addEventListener('change', () => { page = 1; load(); }));

load();
JS;

require_once __DIR__ . '/../includes/footer.php';
