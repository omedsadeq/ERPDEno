<?php
/**
 * Shared customer / supplier management screen.
 *
 * customers.php and suppliers.php are thin wrappers that set $erp2_PARTY_KIND
 * and include this. The two differ only in labels, one extra field and which
 * direction the balance points, so keeping them as one file means a fix to
 * the list, the search or the balance maths lands on both at once.
 *
 * Expects $erp2_PARTY_KIND = 'customer' | 'supplier' before inclusion.
 */

if (!isset($erp2_PARTY_KIND) || !in_array($erp2_PARTY_KIND, ['customer', 'supplier'], true)) {
    http_response_code(500);
    exit('bad party kind');
}

require_once __DIR__ . '/_bootstrap.php';

$isCustomer = $erp2_PARTY_KIND === 'customer';
erp2_require($isCustomer ? 'erp2.customers.view' : 'erp2.suppliers.view');

$pageTitle  = $isCustomer ? 'کڕیارەکان' : 'دابینکەرەکان';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => $pageTitle],
];

// Creating a party is part of making an invoice, so the right follows the
// document permission — mirrors the same rule in erp/api/parties.php.
$canWrite = $isCustomer
    ? (erp2_can('erp2.sales.create') || erp2_can('erp2.customers.view'))
    : (erp2_can('erp2.purchase.create') || erp2_can('erp2.suppliers.view'));

$balanceLabel = $isCustomer ? 'قەرزی لەسەر' : 'قەرزمان بۆی';
$canDelete    = erp2_can('erp2.delete');

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
                        <th style="width:130px" class="erp-num"><?= erp2_esc($balanceLabel) ?></th>
                        <th style="width:120px" class="erp-num">سنووری قەرز</th>
                        <th style="width:130px"></th>
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

<div class="dialog-overlay" id="partyDelDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h3 class="dialog-title"><i class="fas fa-trash-can" style="color:#c62828"></i> سڕینەوەی «<span id="pdName"></span>»</h3>
            <button class="dialog-close" onclick="closeDialog('partyDelDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <p style="margin:0 0 6px;font-weight:700">ئەم مامەڵانەی هەیە:</p>
            <ul id="pdList" style="margin:0 0 12px;padding-inline-start:20px;line-height:1.9"></ul>
            <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:9px 11px;font-size:12.5px;line-height:1.8">
                <b>ناچالاککردن:</b> حسابەکەی دەمێنێت، بەڵام لە لیستەکان دەرناکەوێت.<br>
                <b>سڕینەوە لەگەڵ هەموو مامەڵەکانی:</b> هەموو وەسڵ و گەڕاندنەوە و پارەدانەکانی دەسڕدرێنەوە،
                کاڵاکان بۆ کۆگا دەگەڕێنەوە / دەردەچنەوە و حسابەکان ڕاست دەکرێنەوە. ئەگەر شتێک نەکرا (بۆ نموونە کاڵاکان فرۆشرابن)، هیچ نەسڕدرێتەوە.
            </div>
            <div id="pdMsg" style="color:#c62828;font-weight:600;font-size:12.5px;margin-top:10px"></div>
        </div>
        <div class="dialog-footer">
            <button class="btn btn-secondary" onclick="closeDialog('partyDelDialog')">پاشگەزبوونەوە</button>
            <button class="btn btn-secondary" id="pdDeactivate" onclick="partyDeactivate()"><i class="fas fa-eye-slash"></i> ناچالاککردن</button>
            <button class="btn btn-danger" id="pdCascade" onclick="partyCascade()" style="background:#c62828;border-color:#c62828;color:#fff"><i class="fas fa-trash-can"></i> سڕینەوە لەگەڵ هەموو مامەڵەکانی</button>
        </div>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiC     = APP_URL . '/erp2/api/parties.php';
$kind     = $erp2_PARTY_KIND;
$newLabel = $isCustomer ? 'کڕیاری نوێ' : 'دابینکەری نوێ';
$jsWrite  = $canWrite ? 'true' : 'false';
$jsDelete = $canDelete ? 'true' : 'false';
$apiDel   = APP_URL . '/erp2/api/delete.php';

$customJS = <<<JS
const API_C = '{$apiC}', KIND = '{$kind}', NEW_LABEL = '{$newLabel}';
const CAN_WRITE = {$jsWrite}, CAN_DELETE = {$jsDelete}, API_DEL = '{$apiDel}';
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
          + (CAN_DELETE ? '<button class="btn btn-icon btn-sm" title="سڕینەوە" style="color:#c62828" onclick="partyDelete(' + Number(r.id) + ')"><i class="fas fa-trash-can"></i></button>' : '')
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

/** POST to the delete API; resolves to its JSON reply. */
async function delPost(data) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    try {
        const res = await fetch(API_DEL, { method: 'POST', body: fd });
        return await res.json();
    } catch (e) { return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' }; }
}

/**
 * Delete. One with no documents goes at once. One with documents opens the
 * choice: deactivate (keeps the account, hides it), or delete it WITH every
 * transaction — its invoices, returns and payments are reversed and removed
 * first, all or nothing.
 */
let delTarget = null;
async function partyDelete(id) {
    const r = lastRows.find(x => Number(x.id) === Number(id));
    if (!r) return;
    if (!confirm('«' + r.name + '» بسڕدرێتەوە؟')) return;
    const d = await delPost({ action: 'party', kind: KIND, id: id });
    if (d && d.ok) { showToast('success', 'سڕایەوە'); load(); return; }
    if (!d || !d.blocked) { showToast('error', (d && (d.error || d.message)) || 'هەڵە'); return; }
    delTarget = r;
    document.getElementById('pdName').textContent = r.name;
    document.getElementById('pdList').innerHTML = (d.blockers || []).map(b => '<li>' + U.esc(b) + '</li>').join('');
    document.getElementById('pdDeactivate').hidden = !d.can_deactivate || Number(r.is_active) !== 1;
    document.getElementById('pdCascade').hidden = !d.can_cascade;
    document.getElementById('pdMsg').textContent = '';
    openDialog('partyDelDialog');
}
async function partyDeactivate() {
    if (!delTarget) return;
    const x = await delPost({ action: 'deactivate', kind: KIND, id: delTarget.id });
    if (x && x.ok) { closeDialog('partyDelDialog'); showToast('success', 'ناچالاک کرا'); load(); }
    else document.getElementById('pdMsg').textContent = (x && x.error) || 'هەڵە';
}
async function partyCascade() {
    if (!delTarget) return;
    if (!confirm('دڵنیایت؟ «' + delTarget.name + '» و هەموو وەسڵ و گەڕاندنەوە و پارەدانەکانی بە یەکجاری دەسڕدرێنەوە.\\nکۆگا و حسابەکان ڕاست دەکرێنەوە. ئەم کارە ناگەڕێتەوە.')) return;
    const btn = document.getElementById('pdCascade');
    btn.disabled = true;
    const x = await delPost({ action: 'party', kind: KIND, id: delTarget.id, cascade: 1 });
    btn.disabled = false;
    if (x && x.ok) {
        const rm = x.removed || {};
        closeDialog('partyDelDialog');
        showToast('success', 'سڕایەوە لەگەڵ ' + (rm.invoices || 0) + ' وەسڵ، ' + (rm.returns || 0) + ' گەڕاندنەوە، ' + (rm.payments || 0) + ' پارەدان');
        load();
    } else {
        document.getElementById('pdMsg').textContent = (x && (x.error || x.message)) || 'هەڵە';
    }
}
document.getElementById('pSearch').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { page = 1; load(); }, 250);
});
load();
JS;

require_once __DIR__ . '/../includes/footer.php';
