<?php
/**
 * ERP — sales return.
 *
 * Invoice-first by design: the operator finds the original sale, and the grid
 * is filled from its lines with the still-returnable quantity already capped.
 * That is the shape of the real conversation at the counter ("this is the
 * invoice, these two are coming back"), and it means price and cost come from
 * the sale itself rather than being retyped — a returned item must refund what
 * was actually charged, not today's list price.
 *
 * A goodwill return with no invoice is still possible via the customer box,
 * for goods sold before the ERP or with a lost receipt.
 */

require_once __DIR__ . '/_bootstrap.php';
erp_require('erp.sales.return');

$pageTitle  = 'گەڕاندنەوەی فرۆشتن';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'گەڕاندنەوە'],
];

$warehouses = erp_warehouses();
$defaultWh  = erp_default_warehouse_id();
$nextNumber = ErpSequence::preview('SALE_RETURN', 0);

$customCSS = <<<CSS
.till{display:flex;flex-direction:column;gap:10px}
.till-head{display:grid;grid-template-columns:1.3fr 1.3fr 1fr 1fr;gap:8px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-head label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.till-head .form-control{height:32px;padding:3px 8px;font-size:13px}
.till-scan{display:flex;gap:8px;align-items:center;background:#c62828;padding:10px;border-radius:8px}
.till-scan input{flex:1;height:42px;font-size:17px;padding:6px 12px;border:0;border-radius:6px;direction:ltr;font-weight:600}
.till-scan .hint{color:#ffcdd2;font-size:12px;white-space:nowrap}
.till-grid{background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:auto;min-height:220px;max-height:44vh}
table.tg{width:100%;border-collapse:collapse;font-size:13px}
table.tg thead th{position:sticky;top:0;background:#eef2f5;border-bottom:2px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:600;text-align:right;z-index:1}
table.tg td{border-bottom:1px solid #f0f0f0;padding:0}
table.tg input{width:100%;border:0;background:transparent;padding:7px 8px;font-size:13px;font-family:inherit}
table.tg input:focus{background:#fff8dc;outline:2px solid #0078D4;outline-offset:-2px}
.tg-n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums}
.tg-del{width:34px;text-align:center;color:#c62828;cursor:pointer}
.tg-idx{width:34px;text-align:center;color:#999;font-size:12px}
.over{color:#c62828;font-weight:700}
.none{opacity:.45}
.till-foot{display:grid;grid-template-columns:1fr 300px;gap:10px}
.till-keys{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px;font-size:12px;color:#555}
.till-tot{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-tot .row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px}
.till-tot .row.grand{border-top:2px solid #333;margin-top:6px;padding-top:8px;font-size:19px;font-weight:700;color:#c62828}
.till-tot .v{direction:ltr;font-variant-numeric:tabular-nums;font-weight:600}
.ac-box{position:absolute;z-index:60;background:#fff;border:1px solid #ccc;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.16);max-height:260px;overflow:auto;min-width:300px}
.ac-item{padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f2f2f2}
.ac-item:hover,.ac-item.on{background:#e8f4fd}
.ac-item .meta{font-size:11px;color:#888}
.hist{margin-top:14px}
.hist table{width:100%;border-collapse:collapse;font-size:12.5px;background:#fff}
.hist th{background:#eef2f5;padding:6px 8px;text-align:right;font-size:11.5px}
.hist td{border-bottom:1px solid #f0f0f0;padding:5px 8px}
@media(max-width:900px){.till-head{grid-template-columns:1fr 1fr}.till-foot{grid-template-columns:1fr}}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="till">
    <div class="till-head">
        <div style="position:relative">
            <label>وەسڵی فرۆشتن <span style="opacity:.6">(F2)</span></label>
            <input class="form-control" id="invBox" autocomplete="off" placeholder="ژمارەی وەسڵ یان ناوی کڕیار..." dir="ltr">
            <input type="hidden" id="invId" value="0">
        </div>
        <div style="position:relative">
            <label>کڕیار <span style="opacity:.6">(F4)</span></label>
            <input class="form-control" id="custBox" autocomplete="off" placeholder="بۆ گەڕاندنەوەی بێ وەسڵ">
            <input type="hidden" id="custId" value="0">
        </div>
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
            <label>بەروار</label>
            <input class="form-control" type="date" id="retDate" value="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <div class="till-scan">
        <i class="fas fa-rotate-left" style="color:#fff;font-size:18px"></i>
        <input id="scanBox" placeholder="کاڵای زیادە زیاد بکە (بێ وەسڵ)..." autocomplete="off">
        <span class="hint">ژمارەی وەسڵ بنووسە بۆ پڕکردنەوەی خۆکار &nbsp; <kbd>Ctrl+S</kbd></span>
    </div>

    <div class="till-grid">
        <table class="tg">
            <thead>
                <tr>
                    <th class="tg-idx">#</th>
                    <th>کاڵا</th>
                    <th style="width:95px">فرۆشراو</th>
                    <th style="width:95px">گەڕاوە</th>
                    <th style="width:105px">دەگەڕێتەوە</th>
                    <th style="width:100px">نرخ</th>
                    <th style="width:110px">کۆ</th>
                    <th class="tg-del"></th>
                </tr>
            </thead>
            <tbody id="tgBody"></tbody>
        </table>
    </div>

    <div class="till-foot">
        <div class="till-keys">
            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px">هۆکاری گەڕاندنەوە</label>
            <input class="form-control" id="reasonBox" style="height:32px;font-size:13px"
                   placeholder="نموونە: زیانلێکەوتوو / هەڵە لە فرۆشتن">
            <div id="retMsg" style="margin-top:8px;color:#c62828;font-weight:600"></div>
            <div style="margin-top:6px;font-size:11.5px;color:#888">
                نرخ و تێچوو لە وەسڵە ڕەسەنەکەوە دەیەت — بڕی گەڕاوە هەرگیز لە فرۆشراو زیاتر نابێت.
            </div>
        </div>
        <div class="till-tot">
            <div class="row"><span>ژمارەی پسووڵە</span><span class="v" style="font-size:12px"><?= erp_esc($nextNumber) ?></span></div>
            <div class="row"><span>ڕیزەکان</span><span class="v" id="tLines">0</span></div>
            <div class="row grand"><span>کۆی گەڕاندنەوە</span><span class="v" id="tGrand">0</span></div>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button class="btn btn-subtle" onclick="retReset()"><i class="fas fa-eraser"></i></button>
                <button class="btn btn-danger" id="btnSave" onclick="retSave()" style="flex:1;justify-content:center">
                    <i class="fas fa-rotate-left"></i> گەڕاندنەوە
                </button>
            </div>
        </div>
    </div>

    <div class="hist">
        <h4 style="font-size:14px;margin:0 0 6px">گەڕاندنەوەی دواییەکان</h4>
        <table>
            <thead><tr><th style="width:160px">ژمارە</th><th style="width:100px">بەروار</th><th>کڕیار</th><th style="width:150px">وەسڵی ڕەسەن</th><th style="width:110px" class="tg-n">بڕ</th></tr></thead>
            <tbody id="histBody"><tr><td colspan="5" style="padding:14px;color:#999">بارکردن...</td></tr></tbody>
        </table>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiS = APP_URL . '/erp/api/sales.php';
$apiC = APP_URL . '/erp/api/parties.php';
$apiP = APP_URL . '/erp/api/products.php';

$customJS = <<<JS
const API_S = '{$apiS}', API_C = '{$apiC}', API_P = '{$apiP}';
const U = window.ErpUI;
let rows = [], invTimer = null, custTimer = null;

const msg = t => { document.getElementById('retMsg').textContent = t || ''; };

function lineTotal(r) { return Math.max(0, Number(r.qty) * Number(r.unit_price)); }

function render() {
    const b = document.getElementById('tgBody');
    if (!rows.length) {
        b.innerHTML = '<tr><td colspan="8" style="padding:26px;text-align:center;color:#9e9e9e">'
                    + 'ژمارەی وەسڵ بنووسە بۆ هێنانی کاڵاکان</td></tr>';
        totals(); return;
    }
    b.innerHTML = rows.map((r, i) => {
        const cap = Number(r.returnable);
        const over = Number(r.qty) > cap;
        return '<tr class="' + (cap <= 0 ? 'none' : '') + '">'
          + '<td class="tg-idx">' + (i + 1) + '</td>'
          + '<td><input value="' + U.esc(r.name) + '" readonly></td>'
          + '<td><input class="tg-n" value="' + U.qf(r.sold) + '" readonly></td>'
          + '<td><input class="tg-n" value="' + U.qf(r.already) + '" readonly></td>'
          + '<td><input class="tg-n' + (over ? ' over' : '') + '" type="number" min="0" step="any" '
          +   'max="' + cap + '" value="' + r.qty + '" data-i="' + i + '" data-f="qty" oninput="cellIn(this)"'
          +   (cap <= 0 ? ' readonly' : '') + '></td>'
          + '<td><input class="tg-n" value="' + U.nf(r.unit_price) + '" readonly></td>'
          + '<td><input class="tg-n" value="' + U.nf(lineTotal(r)) + '" readonly></td>'
          + '<td class="tg-del" onclick="delRow(' + i + ')"><i class="fas fa-times"></i></td></tr>';
    }).join('');
    totals();
}

function cellIn(el) {
    const i = Number(el.dataset.i);
    let v = parseFloat(el.value);
    if (isNaN(v) || v < 0) v = 0;
    rows[i].qty = v;
    el.classList.toggle('over', v > Number(rows[i].returnable));
    el.closest('tr').children[6].querySelector('input').value = U.nf(lineTotal(rows[i]));
    totals();
}
function delRow(i) { rows.splice(i, 1); render(); }

function totals() {
    const active = rows.filter(r => Number(r.qty) > 0);
    document.getElementById('tLines').textContent = U.nf(active.length);
    document.getElementById('tGrand').textContent = U.nf(active.reduce((s, r) => s + lineTotal(r), 0));
}

/** Pull an invoice's lines, pre-capped at what is still returnable. */
async function loadInvoice(id) {
    const d = await U.getJson(API_S + '?action=returnable&id=' + Number(id));
    if (!d || !d.ok) { msg('وەسڵ نەدۆزرایەوە'); return; }
    const inv = d.invoice;
    document.getElementById('invId').value  = inv.id;
    document.getElementById('invBox').value = inv.invoice_number;
    document.getElementById('custId').value = inv.customer_id;
    document.getElementById('custBox').value = inv.customer_name;
    const whSel = document.getElementById('whBox');
    if (whSel.querySelector('option[value="' + inv.warehouse_id + '"]')) whSel.value = inv.warehouse_id;

    rows = (inv.items || []).map(it => ({
        item_id: Number(it.item_id),
        product_id: Number(it.product_id),
        name: it.product_name,
        sold: Number(it.quantity),
        already: Number(it.returned_qty),
        returnable: Number(it.returnable),
        // Default to zero, not the full amount: a return is usually partial,
        // and pre-filling the whole line makes over-returning the default.
        qty: 0,
        unit_price: Number(it.unit_price)
    }));
    msg(rows.every(r => r.returnable <= 0) ? 'هەموو کاڵاکانی ئەم وەسڵە پێشتر گەڕێنراونەتەوە' : '');
    render();
}

async function openInvoiceSearch() {
    const box = document.getElementById('invBox');
    const q = box.value.trim();
    const d = await U.getJson(API_S + '?action=recent&limit=20' + (q ? '&q=' + encodeURIComponent(q) : ''));
    if (!d || !d.ok || !d.results.length) { msg('وەسڵ نەدۆزرایەوە'); return; }
    msg('');
    U.showAC(box, d.results.map(i => ({
        label: i.invoice_number + ' — ' + i.customer_name,
        meta: i.invoice_date + ' · ' + U.nf(i.grand_total) + (i.status !== 'confirmed' ? ' · ' + i.status : ''),
        data: i
    })), i => loadInvoice(i.id));
}

async function openCustomerSearch() {
    const box = document.getElementById('custBox');
    const d = await U.getJson(API_C + '?action=search&kind=customer&q=' + encodeURIComponent(box.value.trim()));
    if (!d || !d.ok || !d.results.length) { msg('کڕیار نەدۆزرایەوە'); return; }
    msg('');
    U.showAC(box, d.results.map(c => ({
        label: c.name, meta: (c.phone || '') + ' · ' + c.code, data: c
    })), c => {
        document.getElementById('custId').value = c.id;
        document.getElementById('custBox').value = c.name;
    });
}

/** Goodwill line: no invoice, so price is editable and nothing caps it. */
async function onScan() {
    const box = document.getElementById('scanBox');
    const code = box.value.trim();
    if (!code) return;
    const wh = document.getElementById('whBox').value;
    const d = await U.getJson(API_P + '?action=search&q=' + encodeURIComponent(code) + '&warehouse_id=' + wh);
    if (!d || !d.ok || !d.results.length) { msg('کاڵا نەدۆزرایەوە: ' + code); return; }
    msg('');
    U.showAC(box, d.results.map(p => ({
        label: p.name, meta: 'کۆد ' + p.product_code + ' · ' + U.nf(p.sale_price), data: p
    })), p => {
        rows.push({
            item_id: null, product_id: Number(p.id), name: p.name,
            sold: 0, already: 0, returnable: Number.MAX_SAFE_INTEGER,
            qty: 1, unit_price: Number(p.sale_price || 0)
        });
        box.value = '';
        render();
    });
}

async function retSave() {
    const active = rows.filter(r => Number(r.qty) > 0);
    if (!active.length) { msg('هیچ بڕێکی گەڕاندنەوە نەنووسراوە'); return; }
    if (!Number(document.getElementById('custId').value)) { msg('کڕیار هەڵبژێرە'); return; }
    const over = active.find(r => Number(r.qty) > Number(r.returnable));
    if (over) { msg('بڕی «' + over.name + '» لە بەردەست زیاترە'); return; }

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    msg('');
    const d = await U.postJson(API_S + '?action=return', {
        customer_id: Number(document.getElementById('custId').value),
        warehouse_id: Number(document.getElementById('whBox').value),
        original_invoice_id: Number(document.getElementById('invId').value) || null,
        return_date: document.getElementById('retDate').value,
        reason: document.getElementById('reasonBox').value,
        items: active.map(r => ({
            item_id: r.item_id, product_id: r.product_id,
            quantity: r.qty, unit_price: r.unit_price
        }))
    });
    btn.disabled = false;
    if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
    showToast('success', 'گەڕاندنەوە ' + d.return_number + ' تۆمارکرا — ' + U.nf(d.grand_total));
    retReset();
    loadHistory();
}

function retReset() {
    rows = [];
    document.getElementById('invId').value = 0;
    document.getElementById('invBox').value = '';
    document.getElementById('custId').value = 0;
    document.getElementById('custBox').value = '';
    document.getElementById('reasonBox').value = '';
    msg('');
    render();
    document.getElementById('invBox').focus();
}

async function loadHistory() {
    const d = await U.getJson(API_S + '?action=recent_returns');
    const b = document.getElementById('histBody');
    if (!d || !d.ok || !d.results.length) {
        b.innerHTML = '<tr><td colspan="5" style="padding:14px;color:#999">هیچ گەڕاندنەوەیەک نییە</td></tr>';
        return;
    }
    b.innerHTML = d.results.map(r =>
        '<tr><td dir="ltr">' + U.esc(r.return_number) + '</td>'
        + '<td dir="ltr">' + U.esc(r.return_date) + '</td>'
        + '<td>' + U.esc(r.customer_name) + '</td>'
        + '<td dir="ltr">' + U.esc(r.original_number || '—') + '</td>'
        + '<td class="tg-n">' + U.nf(r.grand_total) + '</td></tr>').join('');
}

document.getElementById('invBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); openInvoiceSearch(); }
});
document.getElementById('invBox').addEventListener('input', () => {
    clearTimeout(invTimer);
    invTimer = setTimeout(openInvoiceSearch, 300);
});
document.getElementById('custBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); openCustomerSearch(); }
});
document.getElementById('custBox').addEventListener('input', () => {
    clearTimeout(custTimer);
    custTimer = setTimeout(openCustomerSearch, 300);
});
document.getElementById('scanBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); onScan(); }
    if (e.key === 'Escape') { e.target.value = ''; msg(''); }
});
document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('invBox').focus(); }
    if (e.key === 'F4') { e.preventDefault(); document.getElementById('custBox').focus(); }
    if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); retSave(); }
});

render();
loadHistory();
document.getElementById('invBox').focus();
JS;

require_once __DIR__ . '/../includes/footer.php';
