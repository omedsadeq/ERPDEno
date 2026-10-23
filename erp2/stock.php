<?php
/**
 * ERP — stock by warehouse.
 *
 * Answers the two questions actually asked at the counter: "how much of this
 * do we have, and where is it", and "what is about to run out".
 *
 * Loads no rows server-side; the grid is filled by AJAX so the page opens at
 * the same speed regardless of catalogue size. Expanding a product fetches its
 * per-warehouse split on demand rather than joining every warehouse for every
 * row up front.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.products.view');

$pageTitle  = 'کۆگا';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'کۆگا'],
];

$warehouses = erp2_warehouses();
$canCost    = erp2_can('erp2.profit.view');

$customCSS = <<<CSS
.erp-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.erp-toolbar .form-control{height:34px;padding:4px 10px;font-size:13px}
.erp-search{flex:1;min-width:200px;max-width:340px}
.erp-grid{font-size:13px}
.erp-grid td,.erp-grid th{padding:6px 10px}
.erp-num{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.erp-low{color:#d32f2f;font-weight:700}
.erp-zero{color:#9e9e9e}
.erp-muted{color:#9e9e9e}
.erp-empty{text-align:center;padding:34px;color:#8a8a8a}
.wh-row td{background:#fafafa;font-size:12px}
.wh-chip{display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;border-radius:12px;background:#eceff1;font-size:11.5px}
.wh-chip b{direction:ltr;display:inline-block}
.exp-btn{cursor:pointer;color:#0078D4}
.erp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:14px}
.erp-card{background:#fff;border:1px solid #e3e3e3;border-radius:8px;padding:10px 12px}
.erp-card .lbl{font-size:11.5px;color:#6b6b6b}
.erp-card .val{font-size:20px;font-weight:700;direction:ltr;text-align:left;font-variant-numeric:tabular-nums}
CSS;

require_once __DIR__ . '/../includes/header.php';

$db = Database::getInstance();
$totalValue = Erp2Stock::totalStockValue();
$lowCount = (int)($db->fetch(
    "SELECT COUNT(*) c FROM erp2_products p
     LEFT JOIN (SELECT product_id, SUM(quantity) qty FROM erp2_warehouse_product_stock GROUP BY product_id) s
            ON s.product_id = p.id
     WHERE p.is_active = 1 AND p.min_stock > 0 AND COALESCE(s.qty,0) <= p.min_stock"
)['c'] ?? 0);
$skuCount = (int)($db->fetch("SELECT COUNT(*) c FROM erp2_products WHERE is_active = 1")['c'] ?? 0);
?>

<div class="erp-cards">
    <?php if ($canCost): ?>
    <div class="erp-card"><div class="lbl">نرخی کۆگا</div><div class="val"><?= erp2_money($totalValue) ?></div></div>
    <?php endif; ?>
    <div class="erp-card"><div class="lbl">کاڵای چالاک</div><div class="val"><?= erp2_money($skuCount) ?></div></div>
    <div class="erp-card"><div class="lbl">کاڵای کەم</div><div class="val" style="color:<?= $lowCount ? '#d32f2f' : 'inherit' ?>"><?= erp2_money($lowCount) ?></div></div>
    <div class="erp-card"><div class="lbl">کۆگاکان</div><div class="val"><?= erp2_money(count($warehouses)) ?></div></div>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="erp-toolbar">
            <input type="search" id="stSearch" class="form-control erp-search"
                   placeholder="گەڕان بە ناو، کۆد یان بارکۆد..." autocomplete="off">
            <select id="stWarehouse" class="form-control" style="max-width:190px">
                <option value="0">هەموو کۆگاکان</option>
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>"><?= erp2_esc($w['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-check" style="margin:0;white-space:nowrap">
                <input type="checkbox" id="stLow"> تەنها کەمەکان
            </label>
            <span id="stCount" class="erp-muted" style="margin-inline-start:auto;font-size:12px"></span>
        </div>

        <div class="table-container">
            <table class="data-grid erp-grid no-responsive">
                <thead>
                    <tr>
                        <th style="width:60px"></th>
                        <th style="width:110px">کۆد</th>
                        <th>ناو</th>
                        <th style="width:100px" class="erp-num">بڕ</th>
                        <th style="width:100px" class="erp-num">کەمترین</th>
                        <?php if ($canCost): ?><th style="width:110px" class="erp-num">تێچوو</th><?php endif; ?>
                        <?php if ($canCost): ?><th style="width:120px" class="erp-num">نرخی کۆ</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="stRows">
                    <tr><td colspan="<?= $canCost ? 7 : 5 ?>" class="erp-empty">
                        <i class="fas fa-spinner fa-spin"></i> بارکردن...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="erp-pager" id="stPager" style="display:flex;gap:6px;justify-content:center;margin-top:12px"></div>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiP    = APP_URL . '/erp2/api/products.php';
$cols    = $canCost ? 7 : 5;
$jsCost  = $canCost ? 'true' : 'false';

$customJS = <<<JS
const API_P = '{$apiP}';
const COLS = {$cols}, CAN_COST = {$jsCost};
const U = window.ErpUI;
let page = 1, timer = null, reqId = 0;

function params() {
    const p = new URLSearchParams({ action: 'list', page: page, status: 'active' });
    const q = document.getElementById('stSearch').value.trim();
    if (q) p.set('q', q);
    if (document.getElementById('stLow').checked) p.set('low_stock', '1');
    return p;
}

async function load() {
    const mine = ++reqId;
    const d = await U.getJson(API_P + '?' + params().toString());
    if (mine !== reqId) return;                 // a newer keystroke already fired
    if (!d || !d.ok) { message('هەڵە'); return; }
    render(d);
}

function message(t) {
    document.getElementById('stRows').innerHTML =
        '<tr><td colspan="' + COLS + '" class="erp-empty">' + U.esc(t) + '</td></tr>';
    document.getElementById('stPager').innerHTML = '';
}

function render(d) {
    const rows = d.results || [];
    document.getElementById('stCount').textContent = d.total ? U.nf(d.total) + ' کاڵا' : '';
    if (!rows.length) { message('هیچ نەدۆزرایەوە'); return; }
    let h = '';
    for (const r of rows) {
        const low  = Number(r.min_stock) > 0 && Number(r.stock) <= Number(r.min_stock);
        const zero = Number(r.stock) <= 0;
        h += '<tr>'
          + '<td style="white-space:nowrap">'
          +   '<span class="exp-btn" onclick="toggleWh(' + Number(r.id) + ',this)"><i class="fas fa-plus-square"></i></span>'
          +   ' <a class="exp-btn" title="مێژوو" href="movements.php?product_id=' + Number(r.id) + '">'
          +   '<i class="fas fa-clock-rotate-left"></i></a></td>'
          + '<td dir="ltr" class="erp-num">' + U.esc(r.product_code) + '</td>'
          + '<td>' + U.esc(r.name) + '</td>'
          + '<td class="erp-num ' + (low ? 'erp-low' : (zero ? 'erp-zero' : '')) + '">' + U.qf(r.stock)
          + (r.unit_code ? ' <span class="erp-muted">' + U.esc(r.unit_code) + '</span>' : '') + '</td>'
          + '<td class="erp-num erp-muted">' + U.qf(r.min_stock) + '</td>'
          + (CAN_COST ? '<td class="erp-num">' + U.nf(r.purchase_price) + '</td>' : '')
          + (CAN_COST ? '<td class="erp-num">' + U.nf(r.stock_value) + '</td>' : '')
          + '</tr>'
          + '<tr class="wh-row" id="wh' + Number(r.id) + '" style="display:none"><td></td>'
          + '<td colspan="' + (COLS - 1) + '"><span class="erp-muted">بارکردن...</span></td></tr>';
    }
    document.getElementById('stRows').innerHTML = h;
    pager(d.page, d.totalPages);
}

/** Per-warehouse split, fetched only when the row is opened. */
async function toggleWh(id, el) {
    const tr = document.getElementById('wh' + id);
    if (!tr) return;
    const open = tr.style.display !== 'none';
    tr.style.display = open ? 'none' : '';
    el.innerHTML = open ? '<i class="fas fa-plus-square"></i>' : '<i class="fas fa-minus-square"></i>';
    if (open || tr.dataset.loaded) return;

    const d = await U.getJson(API_P + '?action=stock&product_id=' + id);
    const cell = tr.querySelector('td:last-child');
    if (!d || !d.ok) { cell.innerHTML = '<span class="erp-muted">نەتوانرا باربکرێت</span>'; return; }
    const chips = (d.warehouses || []).map(w =>
        '<span class="wh-chip">' + U.esc(w.warehouse_name) + ': <b>' + U.qf(w.quantity) + '</b></span>').join('');
    cell.innerHTML = chips || '<span class="erp-muted">لە هیچ کۆگایەک نییە</span>';
    tr.dataset.loaded = '1';
}

function pager(p, total) {
    const el = document.getElementById('stPager');
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

document.getElementById('stSearch').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { page = 1; load(); }, 250);
});
document.getElementById('stLow').addEventListener('change', () => { page = 1; load(); });
document.getElementById('stWarehouse').addEventListener('change', () => { page = 1; load(); });
load();
JS;

require_once __DIR__ . '/../includes/footer.php';
