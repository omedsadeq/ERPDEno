<?php
/**
 * ERP — inventory adjustment / stock take.
 *
 * The operator types the COUNTED quantity, not a difference. That is how a
 * stock take is actually done ("there are 47 on the shelf"), and it keeps the
 * arithmetic on the server: ErpAdjustment re-reads the recorded figure inside
 * the transaction and derives the delta itself, so a sale landing between
 * counting and saving cannot turn into a phantom correction.
 *
 * The grid shows the recorded figure beside the counted one, with the
 * difference computed live, so the operator sees what they are about to change
 * before saving.
 */

require_once __DIR__ . '/_bootstrap.php';
erp_require('erp.stock.adjust');

$pageTitle  = 'ڕێکخستنی کۆگا';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'ڕێکخستن'],
];

$warehouses = erp_warehouses();
$defaultWh  = erp_default_warehouse_id();
$nextNumber = ErpSequence::preview('ADJUSTMENT', 0);

require_once __DIR__ . '/../classes/ErpAdjustment.php';
$reasons = [];
foreach (ErpAdjustment::REASONS as $r) {
    $reasons[$r] = ErpAdjustment::reasonLabel($r);
}
$decreaseOnly = ErpAdjustment::DECREASE_ONLY;

$customCSS = <<<CSS
.till{display:flex;flex-direction:column;gap:10px}
.till-head{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-head label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.till-head .form-control{height:32px;padding:3px 8px;font-size:13px}
.till-scan{display:flex;gap:8px;align-items:center;background:#6a1b9a;padding:10px;border-radius:8px}
.till-scan input{flex:1;height:42px;font-size:17px;padding:6px 12px;border:0;border-radius:6px;direction:ltr;font-weight:600}
.till-scan .hint{color:#e1bee7;font-size:12px;white-space:nowrap}
.till-grid{background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:auto;min-height:220px;max-height:46vh}
table.tg{width:100%;border-collapse:collapse;font-size:13px}
table.tg thead th{position:sticky;top:0;background:#eef2f5;border-bottom:2px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:600;text-align:right;z-index:1}
table.tg td{border-bottom:1px solid #f0f0f0;padding:0}
table.tg input{width:100%;border:0;background:transparent;padding:7px 8px;font-size:13px;font-family:inherit}
table.tg input:focus{background:#fff8dc;outline:2px solid var(--win-accent, #0078D4);outline-offset:-2px}
.tg-n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums}
.tg-del{width:34px;text-align:center;color:#c62828;cursor:pointer}
.tg-idx{width:34px;text-align:center;color:#999;font-size:12px}
.diff-up{color:#2e7d32;font-weight:700}
.diff-down{color:#c62828;font-weight:700}
.diff-none{color:#bbb}
.till-bar{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px;gap:10px;flex-wrap:wrap}
.reason-hint{font-size:11.5px;color:#8d6e00}
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
            <label>کۆگا</label>
            <select class="form-control" id="whBox">
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWh ? 'selected' : '' ?>>
                    <?= erp_esc($w['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>هۆکار</label>
            <select class="form-control" id="reasonBox">
                <?php foreach ($reasons as $key => $label): ?>
                <option value="<?= erp_esc($key) ?>" <?= $key === 'COUNT' ? 'selected' : '' ?>>
                    <?= erp_esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>ژمارەی ڕێکخستن</label>
            <input class="form-control" value="<?= erp_esc($nextNumber) ?>" readonly dir="ltr">
        </div>
        <div>
            <label>بەروار</label>
            <input class="form-control" type="date" id="adjDate" value="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <div class="till-scan">
        <i class="fas fa-clipboard-check" style="color:#fff;font-size:18px"></i>
        <input id="scanBox" placeholder="بارکۆد یان ناوی کاڵا..." autocomplete="off">
        <span class="hint"><kbd>F2</kbd> گەڕان &nbsp; <kbd>Ctrl+S</kbd> پاشەکەوت</span>
    </div>

    <div class="till-grid">
        <table class="tg">
            <thead>
                <tr>
                    <th class="tg-idx">#</th>
                    <th>کاڵا</th>
                    <th style="width:110px">لە سیستەم</th>
                    <th style="width:110px">ژماردن</th>
                    <th style="width:100px">جیاوازی</th>
                    <th class="tg-del"></th>
                </tr>
            </thead>
            <tbody id="tgBody"></tbody>
        </table>
    </div>

    <div class="till-bar">
        <div>
            <div id="adjMsg" style="color:#c62828;font-weight:600;font-size:13px"></div>
            <div class="reason-hint" id="reasonHint"></div>
        </div>
        <div style="display:flex;gap:8px">
            <button class="btn btn-subtle" onclick="adjReset()"><i class="fas fa-eraser"></i> پاککردنەوە</button>
            <button class="btn btn-primary" id="btnSave" onclick="adjSave()">
                <i class="fas fa-clipboard-check"></i> تۆمارکردن
            </button>
        </div>
    </div>

    <div class="hist">
        <h4 style="font-size:14px;margin:0 0 6px">ڕێکخستنی دواییەکان</h4>
        <table>
            <thead><tr><th style="width:150px">ژمارە</th><th style="width:100px">بەروار</th><th>کۆگا</th><th>هۆکار</th><th style="width:70px">ڕیز</th></tr></thead>
            <tbody id="histBody"><tr><td colspan="5" style="padding:14px;color:#999">بارکردن...</td></tr></tbody>
        </table>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiP = APP_URL . '/erp/api/products.php';
$apiW = APP_URL . '/erp/api/warehouse.php';
$jsDecreaseOnly = json_encode($decreaseOnly);

$customJS = <<<JS
const API_P = '{$apiP}', API_W = '{$apiW}';
const DECREASE_ONLY = {$jsDecreaseOnly};
const U = window.ErpUI;
let rows = [];

const msg = t => { document.getElementById('adjMsg').textContent = t || ''; };
const wh  = () => Number(document.getElementById('whBox').value);
const reason = () => document.getElementById('reasonBox').value;

function reasonHint() {
    const el = document.getElementById('reasonHint');
    el.textContent = DECREASE_ONLY.indexOf(reason()) !== -1
        ? 'ئەم هۆکارە تەنها دەتوانێت بڕ کەم بکاتەوە'
        : '';
}

async function addRow(p) {
    if (rows.some(r => r.product_id === Number(p.id))) { return; }
    const lvl = await U.getJson(API_W + '?action=level&product_id=' + Number(p.id) + '&warehouse_id=' + wh());
    const before = lvl && lvl.ok ? Number(lvl.quantity) : 0;
    rows.push({ product_id: Number(p.id), name: p.name, before: before, counted: '' });
    render();
    const el = document.querySelector('#tgBody input[data-i="' + (rows.length - 1) + '"]');
    if (el) { el.focus(); el.select(); }
}

function diffOf(r) {
    if (r.counted === '' || r.counted === null) return null;
    return Number(r.counted) - Number(r.before);
}

function render() {
    const b = document.getElementById('tgBody');
    if (!rows.length) {
        b.innerHTML = '<tr><td colspan="6" style="padding:24px;text-align:center;color:#9e9e9e">کاڵا زیاد بکە بۆ ژماردن</td></tr>';
        return;
    }
    b.innerHTML = rows.map((r, i) => {
        const d = diffOf(r);
        let cls = 'diff-none', txt = '—';
        if (d !== null && Math.abs(d) > 0.0005) {
            cls = d > 0 ? 'diff-up' : 'diff-down';
            txt = (d > 0 ? '+' : '') + U.qf(d);
        } else if (d !== null) { txt = '0'; }
        return '<tr>'
          + '<td class="tg-idx">' + (i + 1) + '</td>'
          + '<td><input value="' + U.esc(r.name) + '" readonly></td>'
          + '<td><input class="tg-n" value="' + U.qf(r.before) + '" readonly></td>'
          + '<td><input class="tg-n" type="number" min="0" step="any" value="' + r.counted + '" data-i="' + i + '" oninput="countIn(this)" placeholder="ژماردن"></td>'
          + '<td class="tg-n ' + cls + '" id="d' + i + '">' + txt + '</td>'
          + '<td class="tg-del" onclick="delRow(' + i + ')"><i class="fas fa-times"></i></td></tr>';
    }).join('');
}

function countIn(el) {
    const i = Number(el.dataset.i);
    rows[i].counted = el.value === '' ? '' : Math.max(0, parseFloat(el.value) || 0);
    // Patch only this row's difference cell so typing never rebuilds the grid.
    const d = diffOf(rows[i]);
    const cell = document.getElementById('d' + i);
    if (!cell) return;
    if (d === null) { cell.textContent = '—'; cell.className = 'tg-n diff-none'; return; }
    if (Math.abs(d) < 0.0005) { cell.textContent = '0'; cell.className = 'tg-n diff-none'; return; }
    cell.textContent = (d > 0 ? '+' : '') + U.qf(d);
    cell.className = 'tg-n ' + (d > 0 ? 'diff-up' : 'diff-down');
}
function delRow(i) { rows.splice(i, 1); render(); }

async function onScan() {
    const box = document.getElementById('scanBox');
    const code = box.value.trim();
    if (!code) return;
    const d = await U.getJson(API_P + '?action=barcode&code=' + encodeURIComponent(code) + '&warehouse_id=' + wh());
    if (d && d.ok && d.product) { await addRow(d.product); box.value = ''; msg(''); return; }
    const s = await U.getJson(API_P + '?action=search&q=' + encodeURIComponent(code) + '&warehouse_id=' + wh());
    if (!s || !s.ok || !s.results.length) { msg('کاڵا نەدۆزرایەوە: ' + code); return; }
    msg('');
    U.showAC(box, s.results.map(p => ({
        label: p.name, meta: 'کۆد ' + p.product_code + ' · لە سیستەم ' + U.qf(p.stock), data: p
    })), async p => { await addRow(p); box.value = ''; });
}

async function adjSave() {
    const counted = rows.filter(r => r.counted !== '' && r.counted !== null);
    if (!counted.length) { msg('هیچ ژماردنێک نەنووسراوە'); return; }
    const changed = counted.filter(r => Math.abs(diffOf(r)) > 0.0005);
    if (!changed.length) { msg('هیچ جیاوازییەک نییە'); return; }

    if (DECREASE_ONLY.indexOf(reason()) !== -1 && changed.some(r => diffOf(r) > 0)) {
        msg('ئەم هۆکارە تەنها دەتوانێت بڕ کەم بکاتەوە');
        return;
    }

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    msg('');
    const d = await U.postJson(API_W + '?action=adjust', {
        warehouse_id: wh(),
        reason: reason(),
        adjustment_date: document.getElementById('adjDate').value,
        items: changed.map(r => ({ product_id: r.product_id, qty_after: r.counted }))
    });
    btn.disabled = false;
    if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
    showToast('success', 'ڕێکخستن ' + d.adjustment_number + ' تۆمارکرا');
    adjReset();
    loadHistory();
}

function adjReset() {
    rows = [];
    render();
    document.getElementById('scanBox').focus();
}

async function refreshBefore() {
    for (const r of rows) {
        const lvl = await U.getJson(API_W + '?action=level&product_id=' + r.product_id + '&warehouse_id=' + wh());
        r.before = lvl && lvl.ok ? Number(lvl.quantity) : 0;
    }
    render();
}

async function loadHistory() {
    const d = await U.getJson(API_W + '?action=recent_adjustments');
    const b = document.getElementById('histBody');
    if (!d || !d.ok || !d.results.length) {
        b.innerHTML = '<tr><td colspan="5" style="padding:14px;color:#999">هیچ ڕێکخستنێک نییە</td></tr>';
        return;
    }
    b.innerHTML = d.results.map(a =>
        '<tr><td dir="ltr">' + U.esc(a.adjustment_number) + '</td>'
        + '<td dir="ltr">' + U.esc(a.adjustment_date) + '</td>'
        + '<td>' + U.esc(a.warehouse_name) + '</td>'
        + '<td>' + U.esc(a.reason_label) + '</td>'
        + '<td class="tg-n">' + U.nf(a.line_count) + '</td></tr>').join('');
}

document.getElementById('scanBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); onScan(); }
    if (e.key === 'Escape') { e.target.value = ''; msg(''); }
});
document.getElementById('whBox').addEventListener('change', refreshBefore);
document.getElementById('reasonBox').addEventListener('change', reasonHint);
document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('scanBox').focus(); }
    if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); adjSave(); }
});

reasonHint();
render();
loadHistory();
document.getElementById('scanBox').focus();
JS;

require_once __DIR__ . '/../includes/footer.php';
