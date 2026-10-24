<?php
/**
 * کۆگا — purchase invoice browser (the pharmacy's erp2/purchase-invoices.php).
 *
 * Lists the saved purchase invoices and opens one to see its lines. Whoever
 * holds erp.purchase.edit can open a confirmed one for editing
 * (erp/purchases.php?edit=<id>, ErpPurchase::update), or tick several
 * invoices of one supplier and merge them into the earliest
 * (ErpPurchase::merge): the lines, payments and returns move over and the
 * supplier balance stays the same.
 *
 * Server-side paged and filtered (api/purchases.php?action=browse).
 */

require_once __DIR__ . '/_bootstrap.php';
erp_require('erp.purchase.view');

$pageTitle  = 'وەسڵەکانی کڕین';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'وەسڵەکانی کڕین'],
];

$canEdit   = erp_can('erp.purchase.edit');

$customCSS = <<<CSS
.erp-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
.erp-toolbar label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.erp-toolbar .form-control{height:34px;padding:4px 10px;font-size:13px}
.erp-search{min-width:240px}
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
.pv-head{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:12px}
.pv-head div{background:#f5f7f9;border-radius:8px;padding:7px 10px}
.pv-head small{display:block;font-size:11px;color:#78909c}
.pv-head b{font-size:13.5px}
table.pv{width:100%;border-collapse:collapse;font-size:12.5px}
table.pv th{background:#eef2f5;padding:6px 8px;text-align:right;font-size:11.5px;border-bottom:2px solid #c8d3da}
table.pv td{border-bottom:1px solid #f0f0f0;padding:6px 8px}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-body">
        <div class="erp-toolbar">
            <div>
                <label>گەڕان</label>
                <input type="search" id="q" class="form-control erp-search"
                       placeholder="ژمارەی وەسڵ، وەسڵی دابینکەر یان ناوی دابینکەر..." autocomplete="off">
            </div>
            <div><label>لە</label><input type="date" id="from" class="form-control"></div>
            <div><label>بۆ</label><input type="date" id="to" class="form-control"></div>
            <span id="count" class="erp-muted" style="margin-inline-start:auto;font-size:12px"></span>
            <?php if ($canEdit): ?>
            <button class="btn btn-primary" id="mergeBtn" hidden disabled onclick="mergeSelected()">
                <i class="fas fa-object-group"></i> یەکخستن (<span id="mergeN">0</span>)
            </button>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <table class="data-grid erp-grid no-responsive">
                <thead>
                    <tr>
                        <?php if ($canEdit): ?><th style="width:32px"></th><?php endif; ?>
                        <th style="width:150px">ژمارە</th>
                        <th style="width:120px">وەسڵی دابینکەر</th>
                        <th style="width:100px">بەروار</th>
                        <th>دابینکەر</th>
                        <th style="width:60px" class="erp-num">کاڵا</th>
                        <th style="width:110px" class="erp-num">کۆ</th>
                        <th style="width:110px" class="erp-num">دراو</th>
                        <th style="width:110px" class="erp-num">ماوە</th>
                        <th style="width:90px">دۆخ</th>
                        <th style="width:120px"></th>
                    </tr>
                </thead>
                <tbody id="rows">
                    <tr><td colspan="<?= $canEdit ? 11 : 10 ?>" class="erp-empty"><i class="fas fa-spinner fa-spin"></i> بارکردن...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="erp-pager" id="pager"></div>
    </div>
</div>

<div class="dialog-overlay" id="pvDialog">
    <div class="dialog dialog-lg">
        <div class="dialog-header">
            <h3 class="dialog-title" id="pvTitle">وەسڵی کڕین</h3>
            <button class="dialog-close" onclick="closeDialog('pvDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body" id="pvBody"></div>
        <div class="dialog-footer">
            <a class="btn btn-primary" id="pvEdit" hidden><i class="fas fa-pen-to-square"></i> دەستکاری</a>
            <button class="btn btn-secondary" onclick="closeDialog('pvDialog')">داخستن</button>
        </div>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiU     = APP_URL . '/erp/api/purchases.php';
$jsEdit   = $canEdit ? 'true' : 'false';
$editUrl  = APP_URL . '/erp/purchases.php?edit=';

$customJS = <<<JS
const API_U = '{$apiU}';
const CAN_EDIT = {$jsEdit}, EDIT_URL = '{$editUrl}';
const U = window.ErpUI;
const ST = { confirmed: 'پەسەندکراو', cancelled: 'هەڵوەشاو', draft: 'ڕەشنووس' };
let page = 1, timer = null, reqId = 0, lastRows = [];
const COLS = CAN_EDIT ? 11 : 10;
const picked = new Map();   // ticked for merging, id → row; kept across pages and searches

function params() {
    const p = new URLSearchParams({ action: 'browse', page: page });
    const q = document.getElementById('q').value.trim();
    if (q) p.set('q', q);
    const f = document.getElementById('from').value, t = document.getElementById('to').value;
    if (f) p.set('from', f);
    if (t) p.set('to', t);
    return p;
}

async function load() {
    const mine = ++reqId;
    const d = await U.getJson(API_U + '?' + params().toString());
    if (mine !== reqId) return;
    if (!d || !d.ok) { message('هەڵە'); return; }
    render(d);
}

function message(t) {
    document.getElementById('rows').innerHTML = '<tr><td colspan="' + COLS + '" class="erp-empty">' + U.esc(t) + '</td></tr>';
    document.getElementById('pager').innerHTML = '';
}

function render(d) {
    lastRows = d.results || [];
    document.getElementById('count').textContent = d.total ? U.nf(d.total) + ' وەسڵ' : '';
    if (!lastRows.length) { message('هیچ وەسڵێکی کڕین نەدۆزرایەوە'); return; }
    document.getElementById('rows').innerHTML = lastRows.map(r => {
        const remaining = Number(r.grand_total) - Number(r.paid_amount);
        const cancelled = r.status === 'cancelled';
        return '<tr class="' + (cancelled ? 'is-cancelled' : '') + '">'
          + (CAN_EDIT ? '<td>' + (r.status === 'confirmed'
                ? '<input type="checkbox" title="هەڵبژاردن بۆ یەکخستن"' + (picked.has(Number(r.id)) ? ' checked' : '')
                  + ' onchange="pick(' + Number(r.id) + ', this.checked)">'
                : '') + '</td>' : '')
          + '<td dir="ltr" class="erp-num">' + U.esc(r.invoice_number) + '</td>'
          + '<td dir="ltr" class="erp-muted">' + U.esc(r.supplier_invoice_no || '—') + '</td>'
          + '<td dir="ltr">' + U.esc(r.invoice_date) + '</td>'
          + '<td>' + U.esc(r.supplier_name) + '</td>'
          + '<td class="erp-num">' + U.nf(r.line_count) + '</td>'
          + '<td class="erp-num">' + U.nf(r.grand_total) + '</td>'
          + '<td class="erp-num paid">' + U.nf(r.paid_amount) + '</td>'
          + '<td class="erp-num ' + (remaining > 0.009 ? 'due' : '') + '">' + U.nf(remaining) + '</td>'
          + '<td><span class="st st-' + U.esc(r.status) + '">' + U.esc(ST[r.status] || r.status) + '</span></td>'
          + '<td style="white-space:nowrap">'
          +   '<button class="btn btn-icon btn-sm" title="بینین" onclick="viewInvoice(' + Number(r.id) + ')"><i class="fas fa-eye"></i></button>'
          +   (CAN_EDIT && r.status === 'confirmed' ? '<a class="btn btn-icon btn-sm" title="دەستکاری" style="color:#1565c0" href="' + EDIT_URL + Number(r.id) + '"><i class="fas fa-pen-to-square"></i></a>' : '')
          + '</td></tr>';
    }).join('');
    pager(d.page, d.totalPages);
}

function pager(p, total) {
    const el = document.getElementById('pager');
    if (!total || total < 2) { el.innerHTML = ''; return; }
    let h = '';
    const from = Math.max(1, p - 2), to = Math.min(total, p + 2);
    if (p > 1) h += '<button class="btn btn-sm" onclick="go(' + (p - 1) + ')">‹</button>';
    for (let i = from; i <= to; i++) {
        h += '<button class="btn btn-sm' + (i === p ? ' btn-primary' : '') + '" onclick="go(' + i + ')">' + i + '</button>';
    }
    if (p < total) h += '<button class="btn btn-sm" onclick="go(' + (p + 1) + ')">›</button>';
    el.innerHTML = h;
}
function go(p) { page = p; load(); }

async function viewInvoice(id) {
    const d = await U.getJson(API_U + '?action=load&id=' + Number(id));
    if (!d || !d.ok) { showToast('error', (d && d.error) || 'هەڵە'); return; }
    const v = d.invoice;
    document.getElementById('pvTitle').textContent = 'وەسڵی کڕین ' + v.invoice_number;
    document.getElementById('pvBody').innerHTML =
        '<div class="pv-head">'
        + '<div><small>دابینکەر</small><b>' + U.esc(v.supplier_name || '') + '</b></div>'
        + '<div><small>بەروار</small><b dir="ltr">' + U.esc(v.invoice_date) + '</b></div>'
        + '<div><small>وەسڵی دابینکەر</small><b dir="ltr">' + U.esc(v.supplier_invoice_no || '—') + '</b></div>'
        + '<div><small>کۆی کۆتایی</small><b dir="ltr">' + U.nf(v.grand_total) + '</b></div>'
        + '<div><small>دراو</small><b dir="ltr">' + U.nf(v.paid_amount) + '</b></div>'
        + '</div>'
        + '<table class="pv"><thead><tr><th>#</th><th>کاڵا</th><th class="erp-num">بڕ</th><th class="erp-num">نرخ</th><th class="erp-num">کۆ</th><th>بەسەرچوون</th></tr></thead><tbody>'
        + (v.items || []).map((it, i) => '<tr><td class="erp-muted">' + (i + 1) + '</td>'
            + '<td>' + U.esc(it.product_name) + ' <span class="erp-muted" dir="ltr">' + U.esc(it.product_code || '') + '</span></td>'
            + '<td class="erp-num">' + U.qf(it.quantity) + '</td>'
            + '<td class="erp-num">' + U.nf(it.unit_price) + '</td>'
            + '<td class="erp-num">' + U.nf(it.line_total) + '</td>'
            + '<td dir="ltr">' + U.esc(it.expiry_date || '—') + '</td></tr>').join('')
        + '</tbody></table>'
        + (v.notes ? '<div style="margin-top:10px;font-size:12.5px"><b>تێبینی:</b> ' + U.esc(v.notes) + '</div>' : '');
    const ed = document.getElementById('pvEdit');
    ed.hidden = !(CAN_EDIT && v.status === 'confirmed');
    ed.href = EDIT_URL + Number(v.id);
    openDialog('pvDialog');
}

function pick(id, on) {
    const r = lastRows.find(x => Number(x.id) === Number(id));
    if (on && r) { picked.set(Number(id), r); } else { picked.delete(Number(id)); }
    const b = document.getElementById('mergeBtn');
    b.hidden = picked.size === 0;
    b.disabled = picked.size < 2;
    document.getElementById('mergeN').textContent = picked.size;
}

/**
 * Merge the ticked invoices into the earliest one (ErpPurchase::merge).
 * Refused server-side unless they are all confirmed, for one supplier and
 * one warehouse.
 */
async function mergeSelected() {
    const rows = Array.from(picked.values()).sort((a, b) =>
        String(a.invoice_date).localeCompare(String(b.invoice_date)) || Number(a.id) - Number(b.id));
    if (rows.length < 2) return;
    const total = rows.reduce((s, r) => s + Number(r.grand_total), 0);
    const list = rows.map(r => '• ' + r.invoice_number + '   ' + r.invoice_date + '   ' + r.supplier_name + '   ' + U.nf(r.grand_total)).join('\\n');
    if (!confirm('ئەم ' + rows.length + ' وەسڵە دەکرێنە یەک وەسڵ:\\n' + list
        + '\\n\\nهەموو کاڵاکان، پارەدانەکان و گەڕاندنەوەکان دەچنە سەر وەسڵی ' + rows[0].invoice_number
        + ' و وەسڵەکانی تر لادەبرێن.\\nکۆی گشتی: ' + U.nf(total) + '\\nبەردەوام دەبیت؟')) return;
    const d = await U.postJson(API_U + '?action=merge', { ids: rows.map(r => Number(r.id)) });
    if (!d || !d.ok) { showToast('error', (d && d.error) || 'هەڵە'); return; }
    picked.clear();
    pick(0, false);
    showToast('success', 'وەسڵەکان یەکخران لە وەسڵی ' + d.invoice_number);
    await load();
    viewInvoice(d.id);
}

document.getElementById('q').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { page = 1; load(); }, 250);
});
['from', 'to'].forEach(id => document.getElementById(id).addEventListener('change', () => { page = 1; load(); }));
load();
JS;

require_once __DIR__ . '/../includes/footer.php';
