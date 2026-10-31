<?php
/**
 * ERP — sales screen.
 *
 * Built as a till, not a web form. The whole sale happens on one screen with
 * no page reloads: scan, quantity, scan, Ctrl+S. The barcode box keeps focus
 * so a scanner (which types and presses Enter) drives the grid on its own.
 *
 * Keyboard map, shown in the footer bar so it is discoverable:
 *   F2      focus the barcode box
 *   F4      customer search
 *   Enter   in barcode = add product; in the grid = next field
 *   Delete  remove the current row
 *   Ctrl+S  save
 *   Ctrl+P  save and print
 *   Esc     clear the barcode box
 *
 * Loads NO products on open. Everything comes from erp/api/*.php on demand.
 */

require_once __DIR__ . '/_bootstrap.php';
// Needed by edit mode below (ErpSales::load). _bootstrap.php deliberately
// loads only the always-needed classes, so document services are pulled in by
// the pages that actually use them.
require_once __DIR__ . '/../classes/ErpSales.php';
erp_require('erp.sales.view');

$pageTitle  = 'فرۆشتن';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'فرۆشتن'],
];

$db          = Database::getInstance();
$warehouses  = erp_warehouses();
$defaultWh   = erp_default_warehouse_id();
$canCreate   = erp_can('erp.sales.create');
$canPrice    = erp_can('erp.price.sale');
$canProfit   = erp_can('erp.profit.view');
$nextNumber  = ErpSequence::preview('SALE', 0);

/*
 * Edit mode (?edit=<id>). The till doubles as the edit screen rather than
 * growing a second grid to maintain: the fields are identical, and two grids
 * that drift apart is how "the edit screen totals differently from the sale
 * screen" bugs start.
 *
 * ErpSales::update() reverses the original lines and posts the new ones inside
 * one transaction, so the net stock effect is the difference — the brief's
 * "10 becomes 7 returns 3" case. The invoice NUMBER never changes.
 */
$editId  = (int)($_GET['edit'] ?? 0);
$editInv = null;
if ($editId > 0) {
    erp_require('erp.sales.edit');
    $editInv = ErpSales::load($editId);
    if (!$editInv) {
        $_SESSION['erp_flash'] = 'وەسڵ نەدۆزرایەوە';
        header('Location: ' . APP_URL . '/erp/invoices.php');
        exit;
    }
    // A cancelled invoice has already been reversed; editing it would post
    // stock against a document the books consider closed.
    if ($editInv['status'] !== 'confirmed') {
        $_SESSION['erp_flash'] = 'تەنها وەسڵی پەسەندکراو دەکرێت دەستکاری بکرێت';
        header('Location: ' . APP_URL . '/erp/invoices.php');
        exit;
    }
}

$walkIn = $db->fetch("SELECT id, name FROM erp_customers WHERE is_walk_in = 1 AND is_active = 1 LIMIT 1")
       ?: $db->fetch("SELECT id, name FROM erp_customers WHERE is_active = 1 ORDER BY id LIMIT 1");

/*
 * The till opens as a large dialog over the store page, so the sale has the
 * whole screen: the grid takes the height, the totals and buttons sit beside
 * it, and nothing needs scrolling. Closing goes back to where it was opened.
 */
$customCSS = <<<CSS
.sd-overlay{position:fixed;inset:0;z-index:1040;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:1.2vh 1vw}
.sd-box{width:min(1760px,100%);height:100%;background:var(--bg-solid-base,#f3f3f3);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(15,23,42,.35)}
.sd-head{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fff;border-bottom:1px solid #e5e7eb}
.sd-ico{width:34px;height:34px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,#14b8a6,#0f766e)}
.sd-head h3{margin:0;font-size:16px;font-weight:800;color:#1f2937}
.sd-tools{margin-inline-start:auto;display:flex;gap:6px}
.sd-btn{width:34px;height:34px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:#475569;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none}
.sd-btn:hover{background:#f1f5f9;color:#0f172a}
.sd-body{flex:1;min-height:0;padding:12px;display:flex;flex-direction:column;gap:10px}
body.sd-open{overflow:hidden}
.till{flex:1;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:10px}
.till-main,.till-side{display:flex;flex-direction:column;gap:10px;min-height:0}
.till-head{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:8px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-head label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.till-head .form-control{height:32px;padding:3px 8px;font-size:13px}
.till-scan{display:flex;gap:8px;align-items:center;background:#0f766e;padding:10px;border-radius:8px}
.till-scan input{flex:1;height:42px;font-size:17px;padding:6px 12px;border:0;border-radius:6px;direction:ltr;font-weight:600}
.till-scan .hint{color:#c9f0eb;font-size:12px;white-space:nowrap}
.till-grid{flex:1;background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:auto;min-height:230px}
table.tg{width:100%;border-collapse:collapse;font-size:13px}
table.tg thead th{position:sticky;top:0;background:#eef2f5;border-bottom:2px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:600;text-align:right;z-index:1}
table.tg td{border-bottom:1px solid #f0f0f0;padding:0}
table.tg tr.sel td{background:#e8f4fd}
table.tg input{width:100%;border:0;background:transparent;padding:7px 8px;font-size:13px;font-family:inherit}
table.tg input:focus{background:#fff8dc;outline:2px solid var(--win-accent, #0078D4);outline-offset:-2px}
table.tg input[readonly]{color:#555}
.tg-n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums}
.tg-del{width:34px;text-align:center;color:#c62828;cursor:pointer}
.tg-idx{width:34px;text-align:center;color:#999;font-size:12px}
.till-keys{margin-top:auto;display:grid;gap:2px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px;font-size:12px;color:#555;line-height:2}
.till-keys kbd{background:#eceff1;border:1px solid #cfd8dc;border-radius:4px;padding:1px 6px;font-family:inherit;font-size:11px}
#tillMsg{color:#c62828;font-weight:600;font-size:13px}
#tillMsg:empty{display:none}
.till-tot{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px}
.till-tot .row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px}
.till-tot .row.grand{border-top:2px solid #333;margin-top:6px;padding-top:8px;font-size:22px;font-weight:700}
.till-tot .row .v{direction:ltr;font-variant-numeric:tabular-nums;font-weight:600}
.till-tot input{width:120px;height:30px;text-align:left;direction:ltr;border:1px solid #d5d5d5;border-radius:5px;padding:2px 8px;font-size:13px}
.till-actions{display:flex;gap:8px;margin-top:12px}
.till-actions .btn{flex:1;justify-content:center;min-height:42px}
.till-actions .btn-subtle{flex:0 0 46px}
.remain-pos{color:#d32f2f}.remain-zero{color:#2e7d32}
.ac-box{position:absolute;z-index:60;background:#fff;border:1px solid #ccc;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.16);max-height:260px;overflow:auto;min-width:280px}
.ac-item{padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f2f2f2}
.ac-item:hover,.ac-item.on{background:#e8f4fd}
.ac-item .meta{font-size:11px;color:#888}
@media(max-width:900px){
.sd-overlay{padding:0}.sd-box{border-radius:0}.sd-body{overflow:auto}
.till{grid-template-columns:minmax(0,1fr);flex:none}.till-scan .hint{display:none}.till-head{grid-template-columns:1fr 1fr}.till-grid{flex:none;min-height:240px}
.till-keys{display:none}}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="sd-overlay" id="salesDialog">
<div class="sd-box" role="dialog" aria-modal="true" aria-labelledby="sdTitle">
    <div class="sd-head">
        <span class="sd-ico"><i class="fas fa-cash-register"></i></span>
        <h3 id="sdTitle"><?= $editInv ? 'دەستکاری وەسڵ' : 'فرۆشتنی نوێ' ?></h3>
        <div class="sd-tools">
            <a class="sd-btn" href="<?= APP_URL ?>/erp/invoices.php" title="وەسڵەکان"><i class="fas fa-file-invoice"></i></a>
            <button type="button" class="sd-btn" onclick="tillClose()" title="داخستن"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="sd-body">

<?php if ($editInv): ?>
<div class="alert alert-warning" style="margin:0;display:flex;align-items:center;gap:10px">
    <i class="fas fa-pen-to-square"></i>
    <div class="alert-content" style="flex:1">
        دەستکاری وەسڵی <b dir="ltr"><?= erp_esc($editInv['invoice_number']) ?></b> —
        کاڵا کۆنەکان دەگەڕێندرێنەوە بۆ کۆگا و نوێیەکان دادەبەزرێن. ژمارەی وەسڵ ناگۆڕێت.
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= APP_URL ?>/erp/invoices.php">پاشگەزبوونەوە</a>
</div>
<?php endif; ?>

<div class="till">
  <div class="till-main">
    <div class="till-head">
        <div style="position:relative">
            <label>کڕیار <span style="opacity:.6">(F4)</span></label>
            <input class="form-control" id="custBox" autocomplete="off"
                   value="<?= erp_esc($editInv['customer_name'] ?? ($walkIn['name'] ?? '')) ?>">
            <input type="hidden" id="custId"
                   value="<?= (int)($editInv['customer_id'] ?? ($walkIn['id'] ?? 0)) ?>">
        </div>
        <div>
            <label>کۆگا</label>
            <select class="form-control" id="whBox">
                <?php $selWh = (int)($editInv['warehouse_id'] ?? $defaultWh); ?>
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $selWh ? 'selected' : '' ?>>
                    <?= erp_esc($w['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>ژمارەی وەسڵ</label>
            <input class="form-control" id="invNo" readonly dir="ltr"
                   value="<?= erp_esc($editInv['invoice_number'] ?? $nextNumber) ?>">
        </div>
        <div>
            <label>بەروار</label>
            <input class="form-control" type="date" id="invDate"
                   value="<?= erp_esc($editInv['invoice_date'] ?? date('Y-m-d')) ?>">
        </div>
    </div>

    <div class="till-scan">
        <i class="fas fa-barcode" style="color:#fff;font-size:19px"></i>
        <input id="scanBox" placeholder="بارکۆد بخوێنەوە یان ناوی کاڵا بنووسە..." autocomplete="off">
        <span class="hint"><kbd>F2</kbd> گەڕان &nbsp; <kbd>Ctrl+S</kbd> پاشەکەوت</span>
    </div>

    <div class="till-grid">
        <table class="tg">
            <thead>
                <tr>
                    <th class="tg-idx">#</th>
                    <th style="width:34%">کاڵا</th>
                    <th style="width:90px">بڕ</th>
                    <th style="width:110px">نرخ</th>
                    <th style="width:100px">داشکاندن</th>
                    <th style="width:120px">کۆ</th>
                    <th style="width:70px">بەردەست</th>
                    <th class="tg-del"></th>
                </tr>
            </thead>
            <tbody id="tgBody"></tbody>
        </table>
    </div>
  </div>

    <div class="till-side">
        <div class="till-tot">
            <div class="row"><span>کۆی گشتی</span><span class="v" id="tSub">0</span></div>
            <div class="row"><span>داشکاندن</span>
                <input id="tDisc" type="number" min="0" step="1"
                       value="<?= (float)($editInv['discount_amount'] ?? 0) ?>"></div>
            <div class="row grand"><span>کۆی کۆتایی</span><span class="v" id="tGrand">0</span></div>
            <div class="row"><span>پارەی دراو</span>
                <input id="tPaid" type="number" min="0" step="1"
                       value="<?= (float)($editInv['paid_amount'] ?? 0) ?>"></div>
            <div class="row"><span>ماوە</span><span class="v remain-zero" id="tRemain">0</span></div>
            <div class="till-actions">
                <?php if ($editInv): ?>
                <button class="btn btn-primary" id="btnSave" onclick="tillSave(false)">
                    <i class="fas fa-save"></i> نوێکردنەوەی وەسڵ
                </button>
                <?php elseif ($canCreate): ?>
                <button class="btn btn-primary" id="btnSave" onclick="tillSave(false)">
                    <i class="fas fa-save"></i> پاشەکەوت
                </button>
                <button class="btn btn-secondary" onclick="tillSave(true)">
                    <i class="fas fa-print"></i> چاپ
                </button>
                <?php endif; ?>
                <?php if (!$editInv): ?>
                <button class="btn btn-subtle" onclick="tillReset()" title="پاککردنەوە"><i class="fas fa-eraser"></i></button>
                <?php endif; ?>
            </div>
        </div>
        <div id="tillMsg"></div>
        <div class="till-keys">
            <span><kbd>F2</kbd> گەڕانی کاڵا</span>
            <span><kbd>F4</kbd> گەڕانی کڕیار</span>
            <span><kbd>Enter</kbd> خانەی داهاتوو</span>
            <span><kbd>Delete</kbd> سڕینەوەی ڕیز</span>
            <span><kbd>Ctrl+S</kbd> پاشەکەوت</span>
            <span><kbd>Ctrl+P</kbd> پاشەکەوت و چاپ</span>
            <span><kbd>Esc</kbd> پاککردنەوە</span>
        </div>
    </div>
</div>

    </div>
</div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiP = APP_URL . '/erp/api/products.php';
$apiC = APP_URL . '/erp/api/parties.php';
$apiS = APP_URL . '/erp/api/sales.php';
$appUrl = APP_URL;
$jsCanPrice = $canPrice ? 'true' : 'false';
$jsEditId = (int)$editId;

/*
 * Preloaded rows for edit mode. Built server-side from the stored lines so the
 * grid opens on exactly what was invoiced; `stock` is the CURRENT level, which
 * is what the operator needs to see when deciding whether an increase is even
 * possible. JSON_HEX_* keeps product names safe inside the <script> block.
 */
$jsEditRows = '[]';
if ($editInv) {
    $pre = [];
    foreach ($editInv['items'] as $it) {
        $lvl = ErpStock::levelOf((int)$it['product_id'], (int)$editInv['warehouse_id']);
        $pre[] = [
            'product_id'      => (int)$it['product_id'],
            'name'            => $it['product_name'],
            'quantity'        => (float)$it['quantity'],
            'unit_price'      => (float)$it['unit_price'],
            'min_price'       => 0,
            'discount_amount' => (float)$it['discount_amount'],
            // Current stock PLUS what this invoice already took: editing
            // reverses the original lines first, so that quantity is available
            // again. Showing only the live figure would paint every line red.
            'stock'           => (float)$lvl['quantity'] + ((float)$it['quantity'] * (float)($it['unit_factor'] ?: 1)),
            'unit_factor'     => (float)($it['unit_factor'] ?: 1),
            'unit_id'         => $it['unit_id'] !== null ? (int)$it['unit_id'] : null,
        ];
    }
    $jsEditRows = json_encode($pre,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

$customJS = <<<JS
const API_P = '{$apiP}', API_C = '{$apiC}', API_S = '{$apiS}', APP = '{$appUrl}';
const CAN_PRICE = {$jsCanPrice};
const EDIT_ID = {$jsEditId};
const EDIT_ROWS = {$jsEditRows};
// Formatting and the lookup popup come from assets/js/erp-common.js, shared
// with the purchase screen so the two behave identically.
const U = window.ErpUI;
let rows = [];            // grid model; the DOM is only a view of this
let selRow = -1;
let scanTimer = null;

const nf  = U.nf;
const esc = U.esc;
const msg = t => { document.getElementById('tillMsg').textContent = t || ''; };

/* ---------- the dialog ---------- */
// On <body>, so no ancestor of the page layout can clip or offset it.
document.body.appendChild(document.getElementById('salesDialog'));
document.body.classList.add('sd-open');

// Back to the page it was opened from (the store page when opened directly);
// a new sale with lines on it asks first, since closing drops them.
function tillClose() {
    if (EDIT_ID === 0 && rows.length && !confirm('ئەم فرۆشتنە پاشەکەوت نەکراوە. داخرێت و کاڵاکان لاببرێن؟')) return;
    let back = null;
    try {
        const ref = new URL(document.referrer);
        if (ref.origin === location.origin && !/\/erp\/sales\.php$/.test(ref.pathname)) back = ref.href;
    } catch (e) {}
    location.href = back || (EDIT_ID > 0 ? APP + '/erp/invoices.php' : APP + '/erp/index.php');
}

/* ---------- grid ---------- */
function addRow(p, qty) {
    // Same product scanned twice bumps the existing line instead of adding a
    // duplicate, which is what a till is expected to do.
    const at = rows.findIndex(r => r.product_id === Number(p.id));
    if (at >= 0) {
        rows[at].quantity += (qty || 1);
        render(); selectRow(at); return;
    }
    rows.push({
        product_id: Number(p.id),
        name: p.name,
        quantity: qty || 1,
        unit_price: Number(p.sale_price || 0),
        min_price: Number(p.min_sale_price || 0),
        discount_amount: 0,
        stock: Number(p.stock || 0),
        unit_factor: Number(p.unit_factor || 1),
        unit_id: p.unit_id ? Number(p.unit_id) : null
    });
    render();
    selectRow(rows.length - 1);
    focusCell(rows.length - 1, 'quantity');
}

function lineTotal(r) {
    return Math.max(0, (Number(r.quantity) * Number(r.unit_price)) - Number(r.discount_amount || 0));
}

function render() {
    const b = document.getElementById('tgBody');
    if (!rows.length) {
        b.innerHTML = '<tr><td colspan="8" style="padding:26px;text-align:center;color:#9e9e9e">'
                    + 'بارکۆد بخوێنەوە بۆ دەستپێکردن</td></tr>';
        totals(); return;
    }
    let h = '';
    rows.forEach((r, i) => {
        const low = r.quantity > r.stock;
        h += '<tr class="' + (i === selRow ? 'sel' : '') + '" onclick="selectRow(' + i + ')">'
          + '<td class="tg-idx">' + (i + 1) + '</td>'
          + '<td><input value="' + esc(r.name) + '" readonly></td>'
          + '<td><input class="tg-n" type="number" min="0" step="any" value="' + r.quantity + '" '
          +   'data-i="' + i + '" data-f="quantity" oninput="cellIn(this)"></td>'
          + '<td><input class="tg-n" type="number" min="0" step="any" value="' + r.unit_price + '" '
          +   'data-i="' + i + '" data-f="unit_price" oninput="cellIn(this)" '
          +   (CAN_PRICE ? '' : 'readonly title="ڕێگەت پێنەدراوە نرخ بگۆڕیت"') + '></td>'
          + '<td><input class="tg-n" type="number" min="0" step="any" value="' + r.discount_amount + '" '
          +   'data-i="' + i + '" data-f="discount_amount" oninput="cellIn(this)"></td>'
          + '<td><input class="tg-n" value="' + nf(lineTotal(r)) + '" readonly></td>'
          + '<td><input class="tg-n" value="' + nf(r.stock) + '" readonly '
          +   (low ? 'style="color:#c62828;font-weight:700"' : '') + '></td>'
          + '<td class="tg-del" onclick="delRow(' + i + ');event.stopPropagation()">'
          +   '<i class="fas fa-times"></i></td></tr>';
    });
    b.innerHTML = h;
    totals();
}

function cellIn(el) {
    const i = Number(el.dataset.i), f = el.dataset.f;
    let v = parseFloat(el.value);
    if (isNaN(v) || v < 0) v = 0;
    rows[i][f] = v;
    // Update only the two derived cells, so typing never rebuilds the table
    // and never steals focus from the box being typed in.
    const tr = el.closest('tr');
    tr.children[5].querySelector('input').value = nf(lineTotal(rows[i]));
    const stockCell = tr.children[6].querySelector('input');
    stockCell.style.color = rows[i].quantity > rows[i].stock ? '#c62828' : '';
    stockCell.style.fontWeight = rows[i].quantity > rows[i].stock ? '700' : '';
    totals();
}

function selectRow(i) {
    selRow = i;
    document.querySelectorAll('#tgBody tr').forEach((tr, k) => tr.classList.toggle('sel', k === i));
}
function delRow(i) { rows.splice(i, 1); if (selRow >= rows.length) selRow = rows.length - 1; render(); }
function focusCell(i, f) {
    const el = document.querySelector('#tgBody input[data-i="' + i + '"][data-f="' + f + '"]');
    if (el) { el.focus(); el.select(); }
}

function totals() {
    const sub   = rows.reduce((s, r) => s + lineTotal(r), 0);
    const disc  = Number(document.getElementById('tDisc').value || 0);
    const grand = Math.max(0, sub - disc);
    const paid  = Number(document.getElementById('tPaid').value || 0);
    const rem   = grand - paid;
    document.getElementById('tSub').textContent   = nf(sub);
    document.getElementById('tGrand').textContent = nf(grand);
    const rEl = document.getElementById('tRemain');
    rEl.textContent = nf(rem);
    rEl.className = 'v ' + (rem > 0 ? 'remain-pos' : 'remain-zero');
}

/* ---------- barcode / product lookup ---------- */
async function onScan() {
    const box = document.getElementById('scanBox');
    const code = box.value.trim();
    if (!code) return;
    const wh = document.getElementById('whBox').value;
    try {
        const r = await fetch(API_P + '?action=barcode&code=' + encodeURIComponent(code) + '&warehouse_id=' + wh);
        const d = await r.json();
        if (d.ok && d.product) { addRow(d.product, 1); box.value = ''; msg(''); return; }
        openProductSearch(code);      // not a barcode: treat it as a name search
    } catch (e) { msg('کێشەی پەیوەندی'); }
}

async function openProductSearch(q) {
    const wh = document.getElementById('whBox').value;
    const r  = await fetch(API_P + '?action=search&q=' + encodeURIComponent(q) + '&warehouse_id=' + wh);
    const d  = await r.json();
    if (!d.ok || !d.results.length) { msg('کاڵا نەدۆزرایەوە: ' + q); return; }
    msg('');
    U.showAC(document.getElementById('scanBox'), d.results.map(p => ({
        label: p.name,
        meta: 'کۆد ' + p.product_code + ' · بەردەست ' + nf(p.stock) + ' · ' + nf(p.sale_price),
        data: p
    })), p => { addRow(p, 1); document.getElementById('scanBox').value = ''; });
}

/* ---------- customer ---------- */
async function openCustomerSearch() {
    const box = document.getElementById('custBox');
    const r = await fetch(API_C + '?action=search&kind=customer&q=' + encodeURIComponent(box.value.trim()));
    const d = await r.json();
    if (!d.ok || !d.results.length) { msg('کڕیار نەدۆزرایەوە'); return; }
    msg('');
    U.showAC(box, d.results.map(c => ({
        label: c.name, meta: (c.phone || '') + ' · ' + c.code, data: c
    })), c => {
        document.getElementById('custId').value = c.id;
        document.getElementById('custBox').value = c.name;
        document.getElementById('scanBox').focus();
    });
}

/* ---------- save ---------- */
async function tillSave(print) {
    if (!rows.length) { msg('هیچ کاڵایەک زیاد نەکراوە'); return; }
    const btn = document.getElementById('btnSave');
    if (btn) btn.disabled = true;
    msg('');
    const sub   = rows.reduce((s, r) => s + lineTotal(r), 0);
    const disc  = Number(document.getElementById('tDisc').value || 0);
    const grand = Math.max(0, sub - disc);
    const paid  = Number(document.getElementById('tPaid').value || 0);

    const payload = {
        customer_id: Number(document.getElementById('custId').value),
        warehouse_id: Number(document.getElementById('whBox').value),
        invoice_date: document.getElementById('invDate').value,
        discount_amount: disc,
        paid_amount: paid,
        payment_type: paid <= 0 ? 'credit' : (paid >= grand ? 'cash' : 'partial'),
        items: rows.map(r => ({
            product_id: r.product_id, quantity: r.quantity, unit_price: r.unit_price,
            discount_amount: r.discount_amount, unit_factor: r.unit_factor, unit_id: r.unit_id
        }))
    };

    // Editing posts the SAME payload to 'update' with the invoice id. The
    // service reverses the original lines and applies these, so the net stock
    // effect is the difference between the two.
    const action = EDIT_ID > 0 ? 'update' : 'save';
    if (EDIT_ID > 0) { payload.id = EDIT_ID; }

    try {
        const res = await fetch(API_S + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
            body: JSON.stringify(payload)
        });
        const d = await res.json();
        if (!d.ok) { msg(d.error || 'هەڵە لە پاشەکەوتکردن'); return; }

        if (EDIT_ID > 0) {
            showToast('success', 'وەسڵ ' + d.invoice_number + ' نوێکرایەوە');
            // Back to the list: staying here would leave a grid whose stock
            // figures are now stale against what was just posted.
            setTimeout(() => { window.location = APP + '/erp/invoices.php'; }, 700);
            return;
        }

        showToast('success', 'وەسڵ ' + d.invoice_number + ' پاشەکەوتکرا');
        if (print) window.open(APP + '/erp/print-invoice.php?id=' + d.id, '_blank');
        tillReset();
    } catch (e) {
        msg('کێشەی پەیوەندی ڕوویدا');
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function tillReset() {
    rows = []; selRow = -1;
    document.getElementById('tDisc').value = 0;
    document.getElementById('tPaid').value = 0;
    render();
    // Refresh the previewed number so the header matches what the next save
    // will actually consume.
    try {
        const r = await fetch(API_S + '?action=recent&limit=1');
        if (r.ok) { /* number is re-previewed on reload; keep the field as a hint */ }
    } catch (e) {}
    document.getElementById('scanBox').focus();
}

/* ---------- keyboard ---------- */
document.getElementById('scanBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;   // arrows/Enter/Esc while the list is open
    if (e.key === 'Enter') { e.preventDefault(); onScan(); }
    if (e.key === 'Escape') { e.target.value = ''; msg(''); }
});

document.getElementById('custBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); openCustomerSearch(); }
});
document.getElementById('custBox').addEventListener('input', () => {
    clearTimeout(scanTimer);
    scanTimer = setTimeout(openCustomerSearch, 250);
});

document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('scanBox').focus(); return; }
    if (e.key === 'F4') { e.preventDefault(); document.getElementById('custBox').focus(); openCustomerSearch(); return; }
    if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); tillSave(false); return; }
    if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); tillSave(true); return; }
    if (e.key === 'Delete' && selRow >= 0 && !/INPUT|SELECT/.test(document.activeElement.tagName)) {
        e.preventDefault(); delRow(selRow);
    }
});

// Enter inside the grid moves to the next field rather than submitting.
document.getElementById('tgBody').addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const el = e.target;
    if (!el.dataset) return;
    const i = Number(el.dataset.i), f = el.dataset.f;
    const order = ['quantity', 'unit_price', 'discount_amount'];
    const at = order.indexOf(f);
    if (at >= 0 && at < order.length - 1 && CAN_PRICE) { focusCell(i, order[at + 1]); }
    else { document.getElementById('scanBox').focus(); }
});

document.getElementById('tDisc').addEventListener('input', totals);
document.getElementById('tPaid').addEventListener('input', totals);

// Edit mode opens on the stored lines; a new sale opens empty.
if (EDIT_ID > 0 && EDIT_ROWS.length) {
    rows = EDIT_ROWS.map(r => ({
        product_id: Number(r.product_id),
        name: r.name,
        quantity: Number(r.quantity),
        unit_price: Number(r.unit_price),
        min_price: Number(r.min_price || 0),
        discount_amount: Number(r.discount_amount || 0),
        stock: Number(r.stock || 0),
        unit_factor: Number(r.unit_factor || 1),
        unit_id: r.unit_id
    }));
}

render();
document.getElementById('scanBox').focus();
JS;

require_once __DIR__ . '/../includes/footer.php';
