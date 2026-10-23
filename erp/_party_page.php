<?php
/**
 * Shared customer / supplier management screen.
 *
 * customers.php and suppliers.php are thin wrappers that set $ERP_PARTY_KIND
 * and include this. The two differ only in labels, one extra field and which
 * direction the balance points, so keeping them as one file means a fix to
 * the list, the search or the balance maths lands on both at once.
 *
 * Expects $ERP_PARTY_KIND = 'customer' | 'supplier' before inclusion.
 */

if (!isset($ERP_PARTY_KIND) || !in_array($ERP_PARTY_KIND, ['customer', 'supplier'], true)) {
    http_response_code(500);
    exit('bad party kind');
}

require_once __DIR__ . '/_bootstrap.php';

$isCustomer = $ERP_PARTY_KIND === 'customer';
erp_require($isCustomer ? 'erp.customers.view' : 'erp.suppliers.view');

$pageTitle  = $isCustomer ? 'کڕیارەکان' : 'دابینکەرەکان';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => $pageTitle],
];

// Creating a party is part of making an invoice, so the right follows the
// document permission — mirrors the same rule in erp/api/parties.php.
$canWrite = $isCustomer
    ? (erp_can('erp.sales.create') || erp_can('erp.customers.view'))
    : (erp_can('erp.purchase.create') || erp_can('erp.suppliers.view'));

$balanceLabel = $isCustomer ? 'قەرزی لەسەر' : 'قەرزمان بۆی';

if ($canWrite) {
    $pageActions = '<button class="btn btn-primary" onclick="partyNew()">'
                 . '<i class="fas fa-plus"></i> ' . ($isCustomer ? 'کڕیاری نوێ' : 'دابینکەری نوێ') . '</button>';
}

$customCSS = <<<CSS
.erp-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.erp-toolbar .form-control{height:34px;padding:4px 10px;font-size:13px}
.erp-search{flex:1;min-width:200px;max-width:340px}
.erp-grid{font-size:13px}
.erp-grid td,.erp-grid th{padding:6px 10px}
.erp-num{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.erp-muted{color:#9e9e9e}
.erp-empty{text-align:center;padding:34px;color:#8a8a8a}
.bal-due{color:#d32f2f;font-weight:700}
.bal-zero{color:#2e7d32}
.erp-formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
.erp-pager{display:flex;gap:6px;justify-content:center;margin-top:12px;flex-wrap:wrap}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-body">
        <div class="erp-toolbar">
            <input type="search" id="pSearch" class="form-control erp-search"
                   placeholder="گەڕان بە ناو، مۆبایل یان کۆد..." autocomplete="off">
            <span id="pCount" class="erp-muted" style="margin-inline-start:auto;font-size:12px"></span>
        </div>

        <div class="table-container">
            <table class="data-grid erp-grid no-responsive">
                <thead>
                    <tr>
                        <th style="width:110px">کۆد</th>
                        <th>ناو</th>
                        <th style="width:130px">مۆبایل</th>
                        <th style="width:130px" class="erp-num"><?= erp_esc($balanceLabel) ?></th>
                        <th style="width:120px" class="erp-num">سنووری قەرز</th>
                        <th style="width:96px"></th>
                    </tr>
                </thead>
                <tbody id="pRows">
                    <tr><td colspan="6" class="erp-empty"><i class="fas fa-spinner fa-spin"></i> بارکردن...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="erp-pager" id="pPager"></div>
    </div>
</div>

<div class="dialog-overlay" id="partyDialog">
    <div class="dialog dialog-lg">
        <div class="dialog-header">
            <h3 class="dialog-title" id="partyDialogTitle"></h3>
            <button class="dialog-close" onclick="closeDialog('partyDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <form id="partyForm" onsubmit="return false">
                <input type="hidden" name="id" id="pf_id">
                <div class="erp-formgrid">
                    <div class="form-group">
                        <label class="form-label required">ناو</label>
                        <input class="form-control" name="name" id="pf_name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">مۆبایل</label>
                        <input class="form-control" name="phone" id="pf_phone" dir="ltr">
                    </div>
                    <?php if (!$isCustomer): ?>
                    <div class="form-group">
                        <label class="form-label">بەرپرسی پەیوەندی</label>
                        <input class="form-control" name="contact_person" id="pf_contact">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">ناونیشان</label>
                        <input class="form-control" name="address" id="pf_address">
                    </div>
                    <div class="form-group" id="pf_openingWrap">
                        <label class="form-label">باڵانسی سەرەتایی</label>
                        <input class="form-control erp-num" type="number" step="0.01"
                               name="opening_balance" id="pf_opening" value="0">
                        <div class="form-hint" style="font-size:11px">
                            تەنها لە کاتی دروستکردن دادەنرێت
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">سنووری قەرز</label>
                        <input class="form-control erp-num" type="number" step="0.01"
                               name="credit_limit" id="pf_limit" value="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">تێبینی</label>
                    <textarea class="form-control" name="notes" id="pf_notes" rows="2"></textarea>
                </div>
                <label class="form-check">
                    <input type="checkbox" name="is_active" id="pf_active" value="1" checked> چالاک
                </label>
                <div id="pf_error" class="form-error" style="display:none;margin-top:8px"></div>
            </form>
        </div>
        <div class="dialog-footer">
            <button class="btn btn-secondary" onclick="closeDialog('partyDialog')">پاشگەزبوونەوە</button>
            <button class="btn btn-primary" id="pf_save" onclick="partySave()">پاشەکەوتکردن</button>
        </div>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiC     = APP_URL . '/erp/api/parties.php';
$kind     = $ERP_PARTY_KIND;
$newLabel = $isCustomer ? 'کڕیاری نوێ' : 'دابینکەری نوێ';
$jsWrite  = $canWrite ? 'true' : 'false';

$customJS = <<<JS
const API_C = '{$apiC}', KIND = '{$kind}', NEW_LABEL = '{$newLabel}';
const CAN_WRITE = {$jsWrite};
const U = window.ErpUI;
let page = 1, timer = null, reqId = 0, lastRows = [];

function params() {
    const p = new URLSearchParams({ action: 'list', kind: KIND, page: page });
    const q = document.getElementById('pSearch').value.trim();
    if (q) p.set('q', q);
    return p;
}

async function load() {
    const mine = ++reqId;
    const d = await U.getJson(API_C + '?' + params().toString());
    if (mine !== reqId) return;
    if (!d || !d.ok) { message('هەڵە'); return; }
    lastRows = d.results || [];
    render(d);
}

function message(t) {
    document.getElementById('pRows').innerHTML =
        '<tr><td colspan="6" class="erp-empty">' + U.esc(t) + '</td></tr>';
    document.getElementById('pPager').innerHTML = '';
}

function render(d) {
    const rows = d.results || [];
    document.getElementById('pCount').textContent = d.total ? U.nf(d.total) : '';
    if (!rows.length) { message('هیچ نەدۆزرایەوە'); return; }
    let h = '';
    for (const r of rows) {
        const bal = Number(r.balance || 0);
        h += '<tr' + (Number(r.is_active) ? '' : ' style="opacity:.55"') + '>'
          + '<td dir="ltr" class="erp-num">' + U.esc(r.code) + '</td>'
          + '<td>' + U.esc(r.name) + '</td>'
          + '<td dir="ltr" class="erp-num">' + U.esc(r.phone || '—') + '</td>'
          + '<td class="erp-num ' + (bal > 0.009 ? 'bal-due' : 'bal-zero') + '">' + U.nf(bal) + '</td>'
          + '<td class="erp-num erp-muted">' + U.nf(r.credit_limit) + '</td>'
          + '<td style="white-space:nowrap">'
          + '<a class="btn btn-icon btn-sm" title="کشف حساب" href="statement.php?kind=' + KIND + '&id=' + Number(r.id) + '"><i class="fas fa-file-invoice"></i></a>'
          + (CAN_WRITE ? '<button class="btn btn-icon btn-sm" title="دەستکاری" onclick="partyEdit(' + Number(r.id) + ')"><i class="fas fa-pen"></i></button>' : '')
          + '</td></tr>';
    }
    document.getElementById('pRows').innerHTML = h;
    pager(d.page, d.totalPages);
}

function pager(p, total) {
    const el = document.getElementById('pPager');
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

function partyNew() {
    document.getElementById('partyForm').reset();
    document.getElementById('pf_id').value = '';
    document.getElementById('pf_active').checked = true;
    document.getElementById('pf_error').style.display = 'none';
    document.getElementById('pf_openingWrap').style.display = '';
    document.getElementById('partyDialogTitle').textContent = NEW_LABEL;
    openDialog('partyDialog');
    setTimeout(() => document.getElementById('pf_name').focus(), 60);
}

function partyEdit(id) {
    const r = lastRows.find(x => Number(x.id) === Number(id));
    if (!r) { showToast('error', 'نەدۆزرایەوە'); return; }
    document.getElementById('pf_id').value      = r.id;
    document.getElementById('pf_name').value    = r.name || '';
    document.getElementById('pf_phone').value   = r.phone || '';
    document.getElementById('pf_address').value = r.address || '';
    document.getElementById('pf_limit').value   = r.credit_limit || 0;
    document.getElementById('pf_active').checked = Number(r.is_active) === 1;
    document.getElementById('pf_error').style.display = 'none';
    // The opening balance is already baked into every statement, so it is
    // create-only; showing an editable box would invite silently restating
    // history.
    document.getElementById('pf_openingWrap').style.display = 'none';
    document.getElementById('partyDialogTitle').textContent = 'دەستکاری';
    openDialog('partyDialog');
}

async function partySave() {
    const btn = document.getElementById('pf_save');
    const err = document.getElementById('pf_error');
    btn.disabled = true;
    try {
        const fd = new FormData(document.getElementById('partyForm'));
        fd.set('action', 'save');
        fd.set('kind', KIND);
        if (!document.getElementById('pf_active').checked) fd.delete('is_active');
        const res  = await fetch(API_C, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { err.textContent = data.error || 'هەڵە'; err.style.display = 'block'; return; }
        closeDialog('partyDialog');
        showToast('success', 'پاشەکەوتکرا');
        load();
    } catch (e) {
        err.textContent = 'کێشەی پەیوەندی ڕوویدا';
        err.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}

document.getElementById('pSearch').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { page = 1; load(); }, 250);
});
load();
JS;

require_once __DIR__ . '/../includes/footer.php';
