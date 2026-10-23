<?php
/**
 * ERP — stock transfer between warehouses.
 *
 * Same grid shape as the till screens. The "available" column reads the SOURCE
 * warehouse, refreshed whenever the source changes, because a quantity that is
 * fine in the main store may not exist in the clinic cupboard — showing a
 * global figure here would invite transfers the server then refuses.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.stock.transfer');

$pageTitle  = 'گواستنەوەی کۆگا';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'گواستنەوە'],
];

$warehouses = erp2_warehouses();
$defaultWh  = erp2_default_warehouse_id();
$nextNumber = Erp2Sequence::preview('TRANSFER', 0);

$customCSS = <<<CSS
.till{display:flex;flex-direction:column;gap:10px}
.till-head{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-head label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.till-head .form-control{height:32px;padding:3px 8px;font-size:13px}
.till-scan{display:flex;gap:8px;align-items:center;background:#ef6c00;padding:10px;border-radius:8px}
.till-scan input{flex:1;height:42px;font-size:17px;padding:6px 12px;border:0;border-radius:6px;direction:ltr;font-weight:600}
.till-scan .hint{color:#ffe0b2;font-size:12px;white-space:nowrap}
.till-grid{background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:auto;min-height:220px;max-height:46vh}
table.tg{width:100%;border-collapse:collapse;font-size:13px}
table.tg thead th{position:sticky;top:0;background:#eef2f5;border-bottom:2px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:600;text-align:right;z-index:1}
table.tg td{border-bottom:1px solid #f0f0f0;padding:0}
table.tg input{width:100%;border:0;background:transparent;padding:7px 8px;font-size:13px;font-family:inherit}
table.tg input:focus{background:#fff8dc;outline:2px solid #0078D4;outline-offset:-2px}
.tg-n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums}
.tg-del{width:34px;text-align:center;color:#c62828;cursor:pointer}
.tg-idx{width:34px;text-align:center;color:#999;font-size:12px}
.over{color:#c62828;font-weight:700}
.till-actions{display:flex;gap:8px}
.till-bar{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.ac-box{position:absolute;z-index:60;background:#fff;border:1px solid #ccc;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.16);max-height:260px;overflow:auto;min-width:280px}
.ac-item{padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f2f2f2}
.ac-item:hover,.ac-item.on{background:#e8f4fd}
.ac-item .meta{font-size:11px;color:#888}
.hist{margin-top:14px}
.hist table{width:100%;border-collapse:collapse;font-size:12.5px;background:#fff}
.hist th{background:#eef2f5;padding:6px 8px;text-align:right;font-size:11.5px}
.hist td{border-bottom:1px solid #f0f0f0;padding:5px 8px}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="till">
    <div class="till-head">
        <div>
            <label>لە کۆگای</label>
            <select class="form-control" id="fromWh">
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWh ? 'selected' : '' ?>>
                    <?= erp2_esc($w['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>بۆ کۆگای</label>
            <select class="form-control" id="toWh">
                <?php foreach ($warehouses as $i => $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= ((int)$w['id'] !== $defaultWh && $i <= 1) ? 'selected' : '' ?>>
                    <?= erp2_esc($w['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>ژمارەی گواستنەوە</label>
            <input class="form-control" value="<?= erp2_esc($nextNumber) ?>" readonly dir="ltr">
        </div>
        <div>
            <label>بەروار</label>
            <input class="form-control" type="date" id="trDate" value="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <div class="till-scan">
        <i class="fas fa-right-left" style="color:#fff;font-size:18px"></i>
        <input id="scanBox" placeholder="بارکۆد یان ناوی کاڵا..." autocomplete="off">
        <span class="hint"><kbd>F2</kbd> گەڕان &nbsp; <kbd>Ctrl+S</kbd> پاشەکەوت</span>
    </div>

    <div class="till-grid">
        <table class="tg">
            <thead>
                <tr>
                    <th class="tg-idx">#</th>
                    <th>کاڵا</th>
                    <th style="width:110px">بڕی گواستنەوە</th>
                    <th style="width:110px">بەردەست لە سەرچاوە</th>
                    <th class="tg-del"></th>
                </tr>
            </thead>
            <tbody id="tgBody"></tbody>
        </table>
    </div>

    <div class="till-bar">
        <div id="trMsg" style="color:#c62828;font-weight:600;font-size:13px"></div>
        <div class="till-actions">
            <button class="btn btn-subtle" onclick="trReset()"><i class="fas fa-eraser"></i> پاککردنەوە</button>
            <button class="btn btn-primary" id="btnSave" onclick="trSave()">
                <i class="fas fa-right-left"></i> گواستنەوە
            </button>
        </div>
    </div>

    <div class="hist">
        <h4 style="font-size:14px;margin:0 0 6px">گواستنەوەی دواییەکان</h4>
        <table>
            <thead><tr><th style="width:150px">ژمارە</th><th style="width:100px">بەروار</th><th>لە</th><th>بۆ</th><th style="width:70px">ڕیز</th><?php if (erp2_can('erp2.delete')): ?><th style="width:40px"></th><?php endif; ?></tr></thead>
            <tbody id="histBody"><tr><td colspan="5" style="padding:14px;color:#999">بارکردن...</td></tr></tbody>
        </table>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiP = APP_URL . '/erp2/api/products.php';
$apiW = APP_URL . '/erp2/api/warehouse.php';

$jsDelete = erp2_can('erp2.delete') ? 'true' : 'false';
$apiDel = APP_URL . '/erp2/api/delete.php';
$customJS = <<<JS
const API_P = '{$apiP}', API_W = '{$apiW}';
const CAN_DELETE = {$jsDelete}, API_DEL = '{$apiDel}';
const U = window.ErpUI;
let rows = [];

const msg = t => { document.getElementById('trMsg').textContent = t || ''; };
const fromWh = () => Number(document.getElementById('fromWh').value);

async function addRow(p) {
    const at = rows.findIndex(r => r.product_id === Number(p.id));
    if (at >= 0) { rows[at].quantity += 1; render(); return; }
    // Availability is read for the SOURCE warehouse specifically.
    const lvl = await U.getJson(API_W + '?action=level&product_id=' + Number(p.id) + '&warehouse_id=' + fromWh());
    rows.push({
        product_id: Number(p.id),
        name: p.name,
        quantity: 1,
        available: lvl && lvl.ok ? Number(lvl.quantity) : 0
    });
    render();
    const el = document.querySelector('#tgBody input[data-i="' + (rows.length - 1) + '"]');
    if (el) { el.focus(); el.select(); }
}

function render() {
    const b = document.getElementById('tgBody');
    if (!rows.length) {
        b.innerHTML = '<tr><td colspan="5" style="padding:24px;text-align:center;color:#9e9e9e">کاڵا زیاد بکە</td></tr>';
        return;
    }
    b.innerHTML = rows.map((r, i) => {
        const over = Number(r.quantity) > Number(r.available);
        return '<tr>'
          + '<td class="tg-idx">' + (i + 1) + '</td>'
          + '<td><input value="' + U.esc(r.name) + '" readonly></td>'
          + '<td><input class="tg-n' + (over ? ' over' : '') + '" type="number" min="0" step="any" value="' + r.quantity + '" data-i="' + i + '" oninput="qtyIn(this)"></td>'
          + '<td><input class="tg-n" value="' + U.qf(r.available) + '" readonly></td>'
          + '<td class="tg-del" onclick="delRow(' + i + ')"><i class="fas fa-times"></i></td></tr>';
    }).join('');
}

function qtyIn(el) {
    const i = Number(el.dataset.i);
    let v = parseFloat(el.value);
    if (isNaN(v) || v < 0) v = 0;
    rows[i].quantity = v;
    el.classList.toggle('over', v > Number(rows[i].available));
}
function delRow(i) { rows.splice(i, 1); render(); }

async function onScan() {
    const box = document.getElementById('scanBox');
    const code = box.value.trim();
    if (!code) return;
    const d = await U.getJson(API_P + '?action=barcode&code=' + encodeURIComponent(code) + '&warehouse_id=' + fromWh());
    if (d && d.ok && d.product) { await addRow(d.product); box.value = ''; msg(''); return; }
    const s = await U.getJson(API_P + '?action=search&q=' + encodeURIComponent(code) + '&warehouse_id=' + fromWh());
    if (!s || !s.ok || !s.results.length) { msg('کاڵا نەدۆزرایەوە: ' + code); return; }
    msg('');
    U.showAC(box, s.results.map(p => ({
        label: p.name,
        meta: 'کۆد ' + p.product_code + ' · لە سەرچاوە ' + U.qf(p.stock),
        data: p
    })), async p => { await addRow(p); box.value = ''; });
}

async function trSave() {
    if (!rows.length) { msg('هیچ کاڵایەک زیاد نەکراوە'); return; }
    const from = fromWh(), to = Number(document.getElementById('toWh').value);
    if (from === to) { msg('ناتوانرێت بۆ هەمان کۆگا بگوازرێتەوە'); return; }
    const over = rows.find(r => Number(r.quantity) > Number(r.available));
    if (over) { msg('بڕی «' + over.name + '» لە بەردەست زیاترە'); return; }

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    msg('');
    const d = await U.postJson(API_W + '?action=transfer', {
        from_warehouse_id: from,
        to_warehouse_id: to,
        transfer_date: document.getElementById('trDate').value,
        items: rows.map(r => ({ product_id: r.product_id, quantity: r.quantity }))
    });
    btn.disabled = false;
    if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
    showToast('success', 'گواستنەوە ' + d.transfer_number + ' تۆمارکرا');
    trReset();
    loadHistory();
}

function trReset() {
    rows = [];
    render();
    document.getElementById('scanBox').focus();
}

async function refreshAvailability() {
    // Source changed: every row's availability now refers to a different place.
    for (const r of rows) {
        const lvl = await U.getJson(API_W + '?action=level&product_id=' + r.product_id + '&warehouse_id=' + fromWh());
        r.available = lvl && lvl.ok ? Number(lvl.quantity) : 0;
    }
    render();
}

/** The delete button of a recent document (erp2.delete holders only). */
function delCell(action, id, number) {
    return CAN_DELETE
        ? '<td><button type="button" class="btn btn-icon btn-sm" title="سڕینەوە" style="color:#c62828" '
          + 'onclick="delDoc(\'' + action + '\',' + Number(id) + ',\'' + U.esc(number) + '\')"><i class="fas fa-trash-can"></i></button></td>'
        : '';
}
/** Delete for good: its effect on stock (and on the account, for a return) is reversed first. */
async function delDoc(action, id, number) {
    if (!confirm(number + ' بە یەکجاری بسڕدرێتەوە؟\nکاریگەرییەکەی لەسەر کۆگا و حساب دەگەڕێنرێتەوە. ئەم کارە ناگەڕێتەوە.')) return;
    const fd = new FormData();
    fd.set('action', action);
    fd.set('id', id);
    try {
        const res = await fetch(API_DEL, { method: 'POST', body: fd });
        const d = await res.json();
        if (!d.ok) { showToast('error', d.error || d.message || 'هەڵە'); return; }
        showToast('success', number + ' سڕایەوە');
        loadHistory();
    } catch (e) { showToast('error', 'کێشەی پەیوەندی ڕوویدا'); }
}

async function loadHistory() {
    const d = await U.getJson(API_W + '?action=recent_transfers');
    const b = document.getElementById('histBody');
    if (!d || !d.ok || !d.results.length) {
        b.innerHTML = '<tr><td colspan="5" style="padding:14px;color:#999">هیچ گواستنەوەیەک نییە</td></tr>';
        return;
    }
    b.innerHTML = d.results.map(t =>
        '<tr><td dir="ltr">' + U.esc(t.transfer_number) + '</td>'
        + '<td dir="ltr">' + U.esc(t.transfer_date) + '</td>'
        + '<td>' + U.esc(t.from_name) + '</td>'
        + '<td>' + U.esc(t.to_name) + '</td>'
        + '<td class="tg-n">' + U.nf(t.line_count) + '</td>' + delCell('transfer', t.id, t.transfer_number) + '</tr>').join('');
}

document.getElementById('scanBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); onScan(); }
    if (e.key === 'Escape') { e.target.value = ''; msg(''); }
});
document.getElementById('fromWh').addEventListener('change', refreshAvailability);
document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('scanBox').focus(); }
    if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); trSave(); }
});

render();
loadHistory();
document.getElementById('scanBox').focus();
JS;

require_once __DIR__ . '/../includes/footer.php';
