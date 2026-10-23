<?php
/**
 * ERP — stock movement ledger / product history.
 *
 * Every quantity in the system is the sum of these rows. Until now they were
 * written and never read, which made the audit trail real but invisible: you
 * could see that a product had 47 units, not how it got there.
 *
 * Read-only by design. A movement is never edited or deleted — a correction is
 * a new opposing movement — so there is nothing to click here but filters.
 *
 * Answers the two questions actually asked: "what happened to this product"
 * (filter by product) and "what moved in this warehouse today" (warehouse +
 * date). Reached from the stock screen's per-product row as well as the menu.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.products.view');

$pageTitle  = 'جوڵەی کۆگا';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'جوڵەی کۆگا'],
];

$warehouses = erp2_warehouses();
$canCost    = erp2_can('erp2.profit.view');

// Arriving from a product row: pre-filter and name it in the heading.
$focusProduct = (int)($_GET['product_id'] ?? 0);
$focusName    = '';
if ($focusProduct > 0) {
    $row = Database::getInstance()->fetch(
        "SELECT name FROM erp2_products WHERE id = :id", ['id' => $focusProduct]
    );
    $focusName = $row['name'] ?? '';
}

$TYPES = [
    'PURCHASE'        => 'کڕین',
    'PURCHASE_RETURN' => 'گەڕاندنەوەی کڕین',
    'SALE'            => 'فرۆشتن',
    'SALE_RETURN'     => 'گەڕاندنەوەی فرۆشتن',
    'TRANSFER_IN'     => 'هاتنی گواستنەوە',
    'TRANSFER_OUT'    => 'ڕۆیشتنی گواستنەوە',
    'ADJUSTMENT'      => 'ڕێکخستن',
    'DAMAGE'          => 'زیان',
    'OPENING'         => 'بڕی سەرەتایی',
];

$customCSS = <<<CSS
.mv-tool{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px;margin-bottom:12px}
.mv-tool label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.mv-tool .form-control{height:32px;font-size:13px;padding:3px 8px}
.mv-sum{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:12px}
.mv-card{background:#fff;border:1px solid #e3e3e3;border-radius:8px;padding:8px 10px}
.mv-card .l{font-size:11px;color:#777}
.mv-card .v{font-size:15px;font-weight:700;direction:ltr;text-align:left;font-variant-numeric:tabular-nums}
.mv-card .in{color:#2e7d32}.mv-card .out{color:#c62828}
table.mv{width:100%;border-collapse:collapse;font-size:12.5px;background:#fff}
table.mv th{background:#eef2f5;padding:6px 8px;text-align:right;font-size:11.5px;border-bottom:2px solid #c8d3da;position:sticky;top:0}
table.mv td{border-bottom:1px solid #f2f2f2;padding:5px 8px}
.n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.in{color:#2e7d32;font-weight:600}
.out{color:#c62828;font-weight:600}
.muted{color:#9e9e9e}
.tp{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10.5px;font-weight:600;white-space:nowrap}
.tp-PURCHASE,.tp-OPENING{background:#e3f2fd;color:#1565c0}
.tp-SALE{background:#e8f5e9;color:#2e7d32}
.tp-SALE_RETURN,.tp-PURCHASE_RETURN{background:#fff3e0;color:#ef6c00}
.tp-TRANSFER_IN,.tp-TRANSFER_OUT{background:#ede7f6;color:#5e35b1}
.tp-ADJUSTMENT{background:#eceff1;color:#546e7a}
.tp-DAMAGE{background:#ffebee;color:#c62828}
.mv-empty{text-align:center;padding:30px;color:#8a8a8a}
.mv-pager{display:flex;gap:6px;justify-content:center;margin-top:12px;flex-wrap:wrap}
.mv-wrap{max-height:58vh;overflow:auto;border:1px solid #e0e0e0;border-radius:8px}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($focusName !== ''): ?>
<div class="alert alert-info" style="margin-bottom:10px">
    <i class="fas fa-clock-rotate-left alert-icon"></i>
    <div class="alert-content">مێژووی <b><?= erp2_esc($focusName) ?></b></div>
</div>
<?php endif; ?>

<div class="mv-tool">
    <div style="flex:1;min-width:200px">
        <label>گەڕان (کاڵا یان ژمارەی بەڵگە)</label>
        <input type="search" id="q" class="form-control" autocomplete="off"
               placeholder="ناوی کاڵا، کۆد، یان SAL-2026-...">
    </div>
    <div>
        <label>کۆگا</label>
        <select id="wh" class="form-control" style="max-width:170px">
            <option value="0">هەموو</option>
            <?php foreach ($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>"><?= erp2_esc($w['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>جۆری جوڵە</label>
        <select id="type" class="form-control" style="max-width:170px">
            <option value="">هەموو</option>
            <?php foreach ($TYPES as $k => $v): ?>
            <option value="<?= erp2_esc($k) ?>"><?= erp2_esc($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div><label>لە</label><input type="date" id="from" class="form-control"></div>
    <div><label>بۆ</label><input type="date" id="to" class="form-control"></div>
    <span id="count" class="muted" style="margin-inline-start:auto;font-size:12px"></span>
</div>

<div class="mv-sum" id="summary"></div>

<div class="mv-wrap">
    <table class="mv">
        <thead>
            <tr>
                <th style="width:130px">کات</th>
                <th style="width:130px">جۆر</th>
                <th>کاڵا</th>
                <th style="width:120px">کۆگا</th>
                <th style="width:140px">بەڵگە</th>
                <th style="width:80px" class="n">هاتوو</th>
                <th style="width:80px" class="n">ڕۆیشتوو</th>
                <?php if ($canCost): ?><th style="width:90px" class="n">تێچوو</th><?php endif; ?>
                <th style="width:90px" class="n">باڵانس</th>
                <th style="width:110px">بەکارهێنەر</th>
            </tr>
        </thead>
        <tbody id="rows">
            <tr><td colspan="<?= $canCost ? 10 : 9 ?>" class="mv-empty">
                <i class="fas fa-spinner fa-spin"></i> بارکردن...</td></tr>
        </tbody>
    </table>
</div>
<div class="mv-pager" id="pager"></div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiM     = APP_URL . '/erp2/api/movements.php';
$cols     = $canCost ? 10 : 9;
$jsCost   = $canCost ? 'true' : 'false';
$jsFocus  = (int)$focusProduct;

$customJS = <<<JS
const API_M = '{$apiM}';
const COLS = {$cols}, CAN_COST = {$jsCost}, FOCUS_PRODUCT = {$jsFocus};
const U = window.ErpUI;
let page = 1, timer = null, reqId = 0;

function params(action) {
    const p = new URLSearchParams({ action: action });
    if (action === 'list') p.set('page', page);
    if (FOCUS_PRODUCT > 0) p.set('product_id', FOCUS_PRODUCT);
    const q = document.getElementById('q').value.trim();
    if (q) p.set('q', q);
    const wh = document.getElementById('wh').value;
    if (wh && wh !== '0') p.set('warehouse_id', wh);
    const tp = document.getElementById('type').value;
    if (tp) p.set('type', tp);
    const f = document.getElementById('from').value;
    const t = document.getElementById('to').value;
    if (f) p.set('from', f);
    if (t) p.set('to', t);
    return p;
}

async function load() {
    const mine = ++reqId;
    const [list, sum] = await Promise.all([
        U.getJson(API_M + '?' + params('list').toString()),
        U.getJson(API_M + '?' + params('summary').toString())
    ]);
    if (mine !== reqId) return;            // a newer filter change already fired
    if (!list || !list.ok) { message('هەڵە'); return; }
    render(list);
    renderSummary(sum);
}

function message(t) {
    document.getElementById('rows').innerHTML =
        '<tr><td colspan="' + COLS + '" class="mv-empty">' + U.esc(t) + '</td></tr>';
    document.getElementById('pager').innerHTML = '';
    document.getElementById('summary').innerHTML = '';
}

function renderSummary(d) {
    const el = document.getElementById('summary');
    if (!d || !d.ok || !d.results.length) { el.innerHTML = ''; return; }
    el.innerHTML = d.results.map(r => {
        const inQ = Number(r.qty_in), outQ = Number(r.qty_out);
        const net = inQ - outQ;
        return '<div class="mv-card">'
          + '<div class="l">' + U.esc(r.type_label) + ' · ' + U.nf(r.moves) + '</div>'
          + '<div class="v ' + (net >= 0 ? 'in' : 'out') + '">'
          +   (net >= 0 ? '+' : '') + U.qf(net) + '</div></div>';
    }).join('');
}

function render(d) {
    const rows = d.results || [];
    document.getElementById('count').textContent = d.total ? U.nf(d.total) + ' جوڵە' : '';
    if (!rows.length) { message('هیچ جوڵەیەک نییە'); return; }

    document.getElementById('rows').innerHTML = rows.map(r => {
        const inQ  = Number(r.quantity_in);
        const outQ = Number(r.quantity_out);
        return '<tr>'
          + '<td dir="ltr" class="muted" style="font-size:11.5px">' + U.esc(r.created_at) + '</td>'
          + '<td><span class="tp tp-' + U.esc(r.transaction_type) + '">' + U.esc(r.type_label) + '</span></td>'
          + '<td>' + U.esc(r.product_name)
          +   '<span class="muted" style="font-size:11px"> · ' + U.esc(r.product_code) + '</span></td>'
          + '<td>' + U.esc(r.warehouse_name) + '</td>'
          + '<td dir="ltr" class="muted" style="font-size:11.5px">' + U.esc(r.document_number || '—') + '</td>'
          + '<td class="n in">' + (inQ > 0 ? U.qf(inQ) : '') + '</td>'
          + '<td class="n out">' + (outQ > 0 ? U.qf(outQ) : '') + '</td>'
          + (CAN_COST ? '<td class="n muted">' + U.nf(r.unit_cost) + '</td>' : '')
          + '<td class="n"><b>' + U.qf(r.balance_after) + '</b></td>'
          + '<td class="muted" style="font-size:11.5px">' + U.esc(r.user_name || '—') + '</td>'
          + '</tr>';
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

document.getElementById('q').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { page = 1; load(); }, 250);
});
['wh', 'type', 'from', 'to'].forEach(id =>
    document.getElementById(id).addEventListener('change', () => { page = 1; load(); }));

load();
JS;

require_once __DIR__ . '/../includes/footer.php';
