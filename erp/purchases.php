<?php
/**
 * ERP — purchase screen.
 *
 * The sales till's twin, pointed the other way: stock comes IN and the
 * supplier is credited. Same keyboard-first shape so staff learn one screen.
 *
 * The important difference is the cost column. What is typed here becomes the
 * unit cost rolled into the warehouse's weighted average, so it drives every
 * future profit figure — which is why editing it is gated behind
 * erp.price.purchase and enforced server-side, not just disabled here.
 *
 * The grid is entered row by row like a spreadsheet: each row's product cell
 * searches the catalogue, and a product that is not found becomes a NEW row
 * whose barcode, unit and prices are typed in place. New products are created
 * when the invoice is saved, immediately before the invoice itself.
 *
 *   F2 scan · F4 supplier · F7 new row · Enter next cell · ↑↓ rows
 *   Ctrl+Delete delete row · Ctrl+S save
 */

require_once __DIR__ . '/_bootstrap.php';
erp_require('erp.purchase.view');

$pageTitle  = 'کڕین';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'کڕین'],
];

$warehouses = erp_warehouses();
$defaultWh  = erp_default_warehouse_id();
$canCreate  = erp_can('erp.purchase.create');
$canCost    = erp_can('erp.price.purchase');
$nextNumber = ErpSequence::preview('PURCHASE', 0);

/*
 * Edit mode (?edit=<id>) — opened from erp/purchase-invoices.php. The same
 * grid, preloaded with the stored lines; saving posts to action=update, where
 * ErpPurchase::update() restates stock and the supplier account in one
 * transaction. The warehouse and the invoice number stay as they were.
 */
$editId  = (int)($_GET['edit'] ?? 0);
$editInv = null;
if ($editId > 0) {
    require_once __DIR__ . '/../classes/ErpPurchase.php';
    erp_require('erp.purchase.edit');
    $editInv = ErpPurchase::load($editId);
    $refuse  = !$editInv ? 'وەسڵ نەدۆزرایەوە'
        : ($editInv['status'] !== 'confirmed' ? 'تەنها وەسڵی پەسەندکراو دەکرێت دەستکاری بکرێت' : null);
    if ($refuse) {
        $_SESSION['erp_flash'] = $refuse;
        header('Location: ' . APP_URL . '/erp/purchase-invoices.php');
        exit;
    }
    $canCreate = true;   // the save button, here meaning «update»
    $pageTitle = 'دەستکاری وەسڵی کڕین';
    $breadcrumb[1] = ['title' => 'وەسڵەکانی کڕین', 'url' => APP_URL . '/erp/purchase-invoices.php'];
    $breadcrumb[] = ['title' => $editInv['invoice_number']];
}

// A delivery often contains items the catalogue has never seen. Letting the
// operator define them in the grid keeps the invoice open instead of forcing a
// trip to the product screen and back with a half-typed grid.
$canNewProd = $canCreate && erp_can('erp.products.create');
$categories = $types = [];
if ($canNewProd) {
    $db = Database::getInstance();
    // Groups in their set order, and each group's types.
    $categories = $db->fetchAll("SELECT id, name FROM erp_categories WHERE is_active = 1 ORDER BY sort_order, name");
    $types = $db->fetchAll("SELECT id, category_id, name FROM erp_product_types WHERE is_active = 1 ORDER BY sort_order, name");
}
$units = $brands = [];
if ($canNewProd) {
    $units = Database::getInstance()->fetchAll(
        "SELECT id, code, name FROM erp_units WHERE is_active = 1 ORDER BY id"
    );
    $brands = Database::getInstance()->fetchAll(
        "SELECT id, name FROM erp_brands WHERE is_active = 1 ORDER BY name"
    );
}

$customCSS = <<<CSS
.quick-field{display:flex;align-items:center;gap:4px}
.quick-field input,.quick-field select{flex:1;min-width:0}
.quick-add{flex:0 0 30px;height:30px;border:1px solid #c8d3da;border-radius:5px;background:#eef6ff;color:#1565c0;font-size:20px;cursor:pointer}
.quick-add:disabled{opacity:.5;cursor:wait}
.till{display:flex;flex-direction:column;gap:10px}
.till-head{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1fr;gap:8px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-head label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.till-head .form-control{height:32px;padding:3px 8px;font-size:13px}
.till-scan{display:flex;gap:8px;align-items:center;background:#1565c0;padding:10px;border-radius:8px}
.till-scan input{flex:1;height:42px;font-size:17px;padding:6px 12px;border:0;border-radius:6px;direction:ltr;font-weight:600}
.till-scan .hint{color:#cfe3f7;font-size:12px;white-space:nowrap}
.till-grid{background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:auto;min-height:260px;max-height:52vh}
table.tg{width:100%;min-width:1520px;border-collapse:collapse;font-size:13px}
table.tg input[data-f="barcode"]{font-variant-numeric:tabular-nums;letter-spacing:.3px}
table.tg tr.row-blank input[data-f="barcode"]{background:#f3f8fd}
table.tg thead th{position:sticky;top:0;background:#eef2f5;border-bottom:2px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:600;text-align:right;z-index:1;white-space:nowrap}
table.tg td{border-bottom:1px solid #f0f0f0;border-left:1px solid #f5f5f5;padding:0;position:relative}
table.tg tr.sel td{background:#e8f4fd}
table.tg tr.row-new td{background:#fffbea}
table.tg tr.row-new.sel td{background:#fff3c4}
table.tg input,table.tg select{width:100%;border:0;background:transparent;padding:7px 8px;font-size:13px;font-family:inherit}
table.tg select{padding:6px 4px}
table.tg input:focus,table.tg select:focus{background:#fff8dc;outline:2px solid var(--win-accent, #0078D4);outline-offset:-2px}
table.tg input[readonly]{color:#78909c}
table.tg input[data-f="name"]{font-weight:600;color:#263238}
table.tg tr.row-blank input[data-f="name"]{font-weight:400}
.tg-gt{display:inline-block;margin:0 8px;padding:1px 8px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:11.5px;font-weight:700;white-space:nowrap}
.tg-gt.grp{background:#eef2f5;color:#37474f}
.tg-new{position:absolute;top:50%;left:6px;transform:translateY(-50%);background:#f9a825;color:#fff;font-size:10px;font-weight:700;border-radius:8px;padding:1px 7px;pointer-events:none}
.tg-n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums}
.tg-del{width:34px;text-align:center;color:#c62828;cursor:pointer}
.tg-idx{width:34px;text-align:center;color:#999;font-size:12px}
.till-foot{display:grid;grid-template-columns:1fr 320px;gap:10px}
.till-keys{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px;font-size:12px;color:#555;line-height:2}
.till-keys kbd{background:#eceff1;border:1px solid #cfd8dc;border-radius:4px;padding:1px 6px;font-family:inherit;font-size:11px}
.till-tot{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-tot .row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:13px}
.till-tot .row.grand{border-top:2px solid #333;margin-top:6px;padding-top:8px;font-size:19px;font-weight:700}
.till-tot .row .v{direction:ltr;font-variant-numeric:tabular-nums;font-weight:600}
.till-tot input{width:120px;height:30px;text-align:left;direction:ltr;border:1px solid #d5d5d5;border-radius:5px;padding:2px 8px;font-size:13px}
.till-actions{display:flex;gap:8px;margin-top:10px}
.till-actions .btn{flex:1;justify-content:center}
.remain-pos{color:#d32f2f}.remain-zero{color:#2e7d32}
.ac-box{position:absolute;z-index:60;background:#fff;border:1px solid #ccc;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.16);max-height:260px;overflow:auto;min-width:280px}
.ac-item{padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f2f2f2}
.ac-item:hover,.ac-item.on{background:#e8f4fd}
.ac-item .meta{font-size:11px;color:#888}
@media(max-width:1000px){.till-head{grid-template-columns:1fr 1fr}.till-foot{grid-template-columns:1fr}}
CSS;

require_once __DIR__ . '/../includes/header.php';
$selWh = (int)($editInv['warehouse_id'] ?? $defaultWh);
?>

<?php if ($editInv): ?>
<div class="alert alert-warning" style="margin-bottom:10px;display:flex;align-items:center;gap:10px">
    <i class="fas fa-pen-to-square"></i>
    <div class="alert-content" style="flex:1">
        دەستکاری وەسڵی کڕینی <b dir="ltr"><?= erp_esc($editInv['invoice_number']) ?></b> —
        کاڵا کۆنەکان لە کۆگا دەردەهێنرێنەوە و نوێیەکان دادەبەزرێن، حسابی دابینکەریش ڕاست دەکرێتەوە. ژمارەی وەسڵ و کۆگا ناگۆڕێن.
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= APP_URL ?>/erp/purchase-invoices.php">پاشگەزبوونەوە</a>
</div>
<?php endif; ?>

<div class="till">
    <div class="till-head">
        <div style="position:relative">
            <label>دابینکەر <span style="opacity:.6">(F4)</span></label>
            <div class="ds-head-pick">
                <input class="form-control" id="supBox" readonly placeholder="دابینکەر هەڵنەبژێردراوە" title="بۆ گۆڕین کلیک بکە"
                       value="<?= erp_esc($editInv['supplier_name'] ?? '') ?>">
                <button type="button" class="ds-head-btn" onclick="DocStart.open()"><i class="fas fa-pen"></i> گۆڕین</button>
            </div>
            <input type="hidden" id="supId" value="<?= (int)($editInv['supplier_id'] ?? 0) ?>">
        </div>
        <div>
            <label>کۆگا</label>
            <select class="form-control" id="whBox" disabled title="کۆگا لە هەنگاوی دەستپێکردن دیاری دەکرێت">
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $selWh ? 'selected' : '' ?>>
                    <?= erp_esc($w['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>ژمارەی وەسڵ</label>
            <input class="form-control" id="invNo" value="<?= erp_esc($editInv['invoice_number'] ?? $nextNumber) ?>" readonly dir="ltr">
        </div>
        <div>
            <label>وەسڵی دابینکەر</label>
            <input class="form-control" id="supInvNo" dir="ltr" placeholder="ئارەزوومەندانە"
                   value="<?= erp_esc($editInv['supplier_invoice_no'] ?? '') ?>">
        </div>
        <div>
            <label>بەروار</label>
            <input class="form-control" type="date" id="invDate" value="<?= erp_esc($editInv['invoice_date'] ?? date('Y-m-d')) ?>">
        </div>
    </div>

    <div class="till-scan">
        <i class="fas fa-barcode" style="color:#fff;font-size:19px"></i>
        <input id="scanBox" placeholder="بارکۆد سکان بکە یان ناوی کاڵا بنووسە..." autocomplete="off">
        <span class="hint"><kbd>F2</kbd> سکان &nbsp; <kbd>F7</kbd> ڕیزی نوێ &nbsp; <kbd>Ctrl+S</kbd> پاشەکەوت</span>
    </div>

    <div class="till-grid">
        <table class="tg">
            <thead>
                <tr>
                    <th class="tg-idx">#</th>
                    <th style="width:140px">بارکۆد</th>
                    <th style="min-width:230px">کاڵا</th>
                    <th style="min-width:130px">گرووپ</th><th style="min-width:120px">جۆر</th><th style="min-width:120px">براند</th><th style="width:95px">یەکە</th>
                    <th style="width:75px">بڕ</th>
                    <th style="width:100px">نرخی کڕین</th>
                    <th style="width:100px">نرخی فرۆشتن</th>
                    <th style="width:85px">داشکاندن</th>
                    <th style="width:105px">کۆ</th>
                    <th style="width:95px">بەچ</th>
                    <th style="width:125px">بەسەرچوون</th>
                    <th class="tg-del"></th>
                </tr>
            </thead>
            <tbody id="tgBody"></tbody>
        </table>
    </div>

    <div class="till-foot">
        <div class="till-keys">
            لە خانەی <b>بارکۆد</b> سکان بکە و <kbd>Enter</kbd> لێبدە — یان بە بەتاڵی <kbd>Enter</kbd> لێبدە و لە خانەی <b>کاڵا</b> ناوەکەی بنووسە.
            <?php if ($canNewProd): ?>
            ئەگەر کاڵاکە نەبوو، ڕیزەکە دەبێتە <b style="color:#f9a825">نوێ</b>: ناو، گرووپ، جۆر، براند و <b>یەکە</b> پڕبکەرەوە — لە کاتی پاشەکەوتدا کاڵاکە دروست دەکرێت و دەچێتە کۆگا.
            <?php endif; ?>
            <br>
            <kbd>Enter</kbd> خانەی داهاتوو &nbsp;·&nbsp;
            <kbd>↑</kbd><kbd>↓</kbd> ڕیزی سەرەوە/خوارەوە &nbsp;·&nbsp;
            <kbd>F7</kbd> ڕیزی نوێ &nbsp;·&nbsp;
            <kbd>F4</kbd> دابینکەر &nbsp;·&nbsp;
            <kbd>Ctrl+Delete</kbd> سڕینەوەی ڕیز &nbsp;·&nbsp;
            <kbd>Ctrl+S</kbd> پاشەکەوت
            <?php if (!$canCost): ?>
            <div style="margin-top:6px;color:#8d6e00;font-size:11.5px">
                <i class="fas fa-lock"></i> ڕێگەت پێنەدراوە نرخی کڕین بگۆڕیت — نرخی تۆمارکراوی کاڵا بەکاردێت
            </div>
            <?php endif; ?>
            <div id="tillMsg" style="margin-top:6px;color:#c62828;font-weight:600"></div>
        </div>
        <div class="till-tot">
            <div class="row"><span>کۆی گشتی</span><span class="v" id="tSub">0</span></div>
            <div class="row"><span>داشکاندن</span>
                <input id="tDisc" type="number" min="0" step="1" value="<?= (float)($editInv['discount_amount'] ?? 0) ?>"></div>
            <div class="row grand"><span>کۆی کۆتایی</span><span class="v" id="tGrand">0</span></div>
            <div class="row"><span>پارەی دراو</span>
                <input id="tPaid" type="number" min="0" step="1" value="<?= (float)($editInv['paid_amount'] ?? 0) ?>"></div>
            <div class="row"><span>ماوە بۆ دابینکەر</span><span class="v remain-zero" id="tRemain">0</span></div>
            <div class="till-actions">
                <?php if ($canCreate): ?>
                <button class="btn btn-primary" id="btnSave" onclick="purSave()">
                    <i class="fas fa-save"></i> <?= $editInv ? 'نوێکردنەوەی وەسڵ' : 'پاشەکەوت' ?>
                </button>
                <?php endif; ?>
                <?php if (!$editInv): ?>
                <button class="btn btn-subtle" onclick="purReset()"><i class="fas fa-eraser"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$docStart = [
    'kind'        => 'supplier',
    'api'         => APP_URL . '/erp/api/parties.php',
    'title'       => $editInv ? 'دەستکاری وەسڵی کڕین' : 'کڕینی نوێ',
    'icon'        => 'fa-truck',
    'accent'      => '#1565c0',
    'warehouses'  => $warehouses,
    'defaultWh'   => $defaultWh,
    'walkIn'      => null,
    'canQuickAdd' => $canCreate,
    'autoOpen'    => !$editInv,
    'party'       => $editInv ? ['id' => (int)$editInv['supplier_id'], 'name' => $editInv['supplier_name']] : null,
    'warehouseId' => $selWh,
    'lockRoot'    => '.till',
];
require __DIR__ . '/_doc_start.php';
?>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiP = APP_URL . '/erp/api/products.php';
$apiC = APP_URL . '/erp/api/parties.php';
$apiU = APP_URL . '/erp/api/purchases.php';
$jsCanCost    = $canCost ? 'true' : 'false';
$jsCanNewProd = $canNewProd ? 'true' : 'false';
$jsUnits      = json_encode(
    array_map(fn($u) => ['id' => (int)$u['id'], 'code' => $u['code'], 'name' => $u['name']], $units),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$jsCategories = json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsTypes = json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'cat' => (int)$x['category_id'], 'name' => $x['name']], $types), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsBrands = json_encode(array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $brands), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsCanCreate = $canCreate ? 'true' : 'false';
$jsEditId    = (int)($editInv['id'] ?? 0);
$appUrl      = APP_URL;

// Edit mode: the stored lines in the grid's own row shape, with each product's
// group, type, brand and unit for the read-only cells.
$jsEditRows = '[]';
if ($editInv) {
    $meta = [];
    $ids  = array_unique(array_map(fn($it) => (int)$it['product_id'], $editInv['items']));
    if ($ids) {
        foreach (Database::getInstance()->fetchAll(
            "SELECT p.id, p.unit_id, p.category_id, p.type_id, p.brand_id, p.sale_price,
                    u.code AS unit_code, c.name AS category_name, t.name AS type_name, b.name AS brand_name
             FROM erp_products p
             LEFT JOIN erp_units u ON u.id = p.unit_id
             LEFT JOIN erp_categories c ON c.id = p.category_id
             LEFT JOIN erp_product_types t ON t.id = p.type_id
             LEFT JOIN erp_brands b ON b.id = p.brand_id
             WHERE p.id IN (" . implode(',', $ids) . ")"
        ) as $m) {
            $meta[(int)$m['id']] = $m;
        }
    }
    $pre = [];
    foreach ($editInv['items'] as $it) {
        $m = $meta[(int)$it['product_id']] ?? [];
        $pre[] = [
            'product_id'      => (int)$it['product_id'],
            'is_new'          => false,
            'name'            => $it['product_name'],
            'barcode'         => (string)($it['barcode'] ?? ''),
            'category_id'     => (int)($m['category_id'] ?? 0),
            'type_id'         => (int)($m['type_id'] ?? 0),
            'brand_id'        => (int)($m['brand_id'] ?? 0),
            'unit_id'         => (int)($it['unit_id'] ?? $m['unit_id'] ?? 0),
            'unit_code'       => (string)($m['unit_code'] ?? ''),
            'unit_factor'     => (float)($it['unit_factor'] ?: 1),
            'category_name'   => (string)($m['category_name'] ?? ''),
            'type_name'       => (string)($m['type_name'] ?? ''),
            'brand_name'      => (string)($m['brand_name'] ?? ''),
            'quantity'        => (float)$it['quantity'],
            'unit_price'      => (float)$it['unit_price'],
            'sale_price'      => (float)($m['sale_price'] ?? 0),
            'discount_amount' => (float)$it['discount_amount'],
            'batch_number'    => (string)($it['batch_number'] ?? ''),
            'expiry_date'     => (string)($it['expiry_date'] ?? ''),
        ];
    }
    $jsEditRows = json_encode($pre, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

$customJS = <<<JS
const API_P = '{$apiP}', API_C = '{$apiC}', API_U = '{$apiU}', APP = '{$appUrl}';
const EDIT_ID = {$jsEditId};
const EDIT_ROWS = {$jsEditRows};
const CAN_COST = {$jsCanCost};
const CAN_NEW_PROD = {$jsCanNewProd};
const UNITS = {$jsUnits};
const CATEGORIES = {$jsCategories};
const TYPES = {$jsTypes};
const BRANDS = {$jsBrands};
// A new item's unit starts empty: the operator picks it, it is never guessed.
const CAN_CREATE = {$jsCanCreate};
const U = window.ErpUI;
let rows = [], selRow = -1, supTimer = null, acTimer = null, acReq = 0, saving = false;

const msg = t => { document.getElementById('tillMsg').textContent = t || ''; };

/* ---------- row model ---------- */
// Three kinds of row: 'old' is linked to a catalogue product, 'new' is a
// product that will be created on save, 'blank' is not resolved yet (the
// trailing entry row, or one whose name is still being typed).
const blankRow = () => ({
    product_id: 0, is_new: false, name: '', barcode: '', category_id: 0, type_id: 0, brand_id: 0, unit_id: 0, unit_code: '',
    category_name: '', type_name: '', brand_name: '',
    quantity: 1, unit_price: 0, sale_price: 0, discount_amount: 0, batch_number: '', expiry_date: '', unit_factor: 1
});
const kind   = r => r.product_id > 0 ? 'old' : (r.is_new ? 'new' : 'blank');
const filled = () => rows.filter(r => kind(r) !== 'blank');
const lineTotal = r => Math.max(0, (Number(r.quantity) * Number(r.unit_price)) - Number(r.discount_amount || 0));

/** There is always exactly one empty row at the bottom to type into. */
function ensureBlank() {
    const last = rows[rows.length - 1];
    if (!last || kind(last) !== 'blank') rows.push(blankRow());
}
/** Where a scanned line goes: just above the trailing entry row. */
function insertPos() {
    const l = rows.length - 1;
    return (l >= 0 && kind(rows[l]) === 'blank') ? l : rows.length;
}

function fillFromProduct(r, p) {
    r.product_id = Number(p.id);
    r.is_new     = false;
    r.name       = p.name;
    r.barcode    = p.barcode || '';
    r.unit_id    = Number(p.unit_id || 0);
    r.unit_code  = p.unit_code || '';
    r.category_id   = Number(p.category_id || 0);
    r.type_id       = Number(p.type_id || 0);
    r.category_name = p.category_name || '';
    r.type_name     = p.type_name || '';
    r.brand_id      = Number(p.brand_id || 0);
    r.brand_name    = p.brand_name || '';
    r.unit_price = Number(p.purchase_price || 0);
    r.sale_price = Number(p.sale_price || 0);
    if (!(Number(r.quantity) > 0)) r.quantity = 1;
}

/** Scanner path: an existing line of the same product just counts up. */
function addRow(p) {
    const at = rows.findIndex(r => r.product_id === Number(p.id));
    if (at >= 0) { rows[at].quantity = Number(rows[at].quantity) + 1; render(); selectRow(at); return; }
    const r = blankRow();
    fillFromProduct(r, p);
    const pos = insertPos();
    rows.splice(pos, 0, r);
    render(); selectRow(pos); focusCell(pos, 'quantity');
}

function pick(i, p) {
    fillFromProduct(rows[i], p);
    render(); selectRow(i); focusCell(i, 'quantity'); msg('');
}

/**
 * Turn row i into a product to create. asBarcode: the seed came from the
 * barcode cell; otherwise a digit run is still read as a scanned barcode and
 * anything else as the name. The unit is left empty for the operator to pick.
 */
function markNew(i, seed, asBarcode) {
    seed = String(seed || '').trim();
    if (!CAN_NEW_PROD) { msg('کاڵا نەدۆزرایەوە: ' + seed); return; }
    const r = rows[i];
    r.product_id = 0; r.is_new = true;
    if (asBarcode || /^\d{4,}\$/.test(seed)) { r.barcode = seed; r.name = ''; }
    else { r.name = seed; }
    render(); selectRow(i); msg('');
    focusCell(i, r.name ? 'barcode' : 'name');
}

/** Enter in the entry row's barcode cell: an existing product, or a new one with this barcode. */
async function barcodeIn(i, code) {
    if (needStart()) return;
    const d = await U.getJson(API_P + '?action=barcode&code=' + encodeURIComponent(code) + '&warehouse_id=' + document.getElementById('whBox').value);
    const r = rows[i];
    if (!r || kind(r) !== 'blank' || String(r.barcode || '').trim() !== code) return;
    if (d && d.ok && d.product) {
        // Already on the invoice: count that line up instead of adding a second one.
        const at = rows.findIndex((x, k) => k !== i && x.product_id === Number(d.product.id));
        if (at >= 0) {
            rows[at].quantity = Number(rows[at].quantity) + 1;
            r.barcode = '';
            render(); selectRow(at); focusCell(at, 'quantity');
            return;
        }
        pick(i, d.product);
        return;
    }
    markNew(i, code, true);
}

/* ---------- grid view ---------- */
const RO = ' readonly tabindex="-1"';

function numCell(i, f, v, ro) {
    return '<td><input class="tg-n" type="number" min="0" step="any" value="' + U.esc(v) + '" data-i="' + i
         + '" data-f="' + f + '"' + (ro ? RO : ' oninput="cellIn(this)"') + '></td>';
}
function txtCell(i, f, v, ro) {
    return '<td><input dir="ltr" value="' + U.esc(v) + '" data-i="' + i + '" data-f="' + f + '"'
         + (ro ? RO : ' oninput="cellTxt(this)"') + ' autocomplete="off"></td>';
}
function unitCell(i, r, editable) {
    if (!editable) return '<td><input value="' + U.esc(r.unit_code) + '"' + RO + '></td>';
    return '<td><select data-i="' + i + '" data-f="unit_id" onchange="cellSel(this)">'
         + '<option value="0">—</option>'
         + UNITS.map(u => '<option value="' + u.id + '"' + (Number(r.unit_id) === u.id ? ' selected' : '') + '>'
                        + U.esc(u.name) + '</option>').join('')
         + '</select></td>';
}

function classificationCell(i, r, field, list, editable) {
    if (!editable) {
        // an existing item: show its group, read-only
        return field === 'category_id' && r.category_name
            ? '<td><span class="tg-gt grp">' + U.esc(r.category_name) + '</span></td>' : '<td></td>';
    }
    const label = 'گرووپی نوێ';
    return '<td><div class="quick-field"><select data-i="' + i + '" data-f="' + field + '" onchange="cellSel(this)">'
        + '<option value="0">—</option>'
        + list.map(x => '<option value="' + Number(x.id) + '"' + (Number(r[field]) === Number(x.id) ? ' selected' : '') + '>' + U.esc(x.name) + '</option>').join('')
        + '</select><button type="button" class="quick-add" title="' + label + '" aria-label="' + label + '" onclick="event.stopPropagation();quickClassification(' + i + ',\'' + field + '\',this)">+</button></div></td>';
}
/** The type of a row: read-only for an existing item, the group's types for a new one. */
function typeCell(i, r, editable) {
    if (!editable) return '<td>' + (r.type_name ? '<span class="tg-gt">' + U.esc(r.type_name) + '</span>' : '') + '</td>';
    const list = TYPES.filter(x => x.cat === Number(r.category_id));
    const label = 'جۆری نوێ';
    const add = '<button type="button" class="quick-add" title="' + label + '" aria-label="' + label + '" onclick="event.stopPropagation();quickType(' + i + ',this)">+</button>';
    if (!list.length) return '<td><div class="quick-field"><input value="" placeholder="—"' + RO + '>' + add + '</div></td>';
    return '<td><div class="quick-field"><select data-i="' + i + '" data-f="type_id" onchange="cellSel(this)">'
        + '<option value="0">—</option>'
        + list.map(x => '<option value="' + x.id + '"' + (Number(r.type_id) === x.id ? ' selected' : '') + '>' + U.esc(x.name) + '</option>').join('')
        + '</select>' + add + '</div></td>';
}
/** A new type inside the row's group — a type always belongs to one group. */
async function quickType(i, btn) {
    const r = rows[i];
    if (saving || !CAN_NEW_PROD || !r || kind(r) !== 'new') return;
    const catId = Number(r.category_id) || 0;
    if (!catId) {
        // the message line sits under the grid and is easy to miss — say it here
        const t = 'سەرەتا گرووپ هەڵبژێرە، پاشان جۆری نوێ زیاد بکە';
        if (typeof showToast === 'function') showToast('warning', t); else alert(t);
        msg(t);
        const g = cellEl(i, 'category_id');
        if (g) { g.focus(); try { g.showPicker(); } catch (e) {} }
        return;
    }
    const name = prompt('ناوی جۆری نوێ (نموونە: Composite):');
    if (!name || !name.trim()) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', 'quick_type'); fd.set('category_id', catId); fd.set('name', name.trim());
        const d = await postForm(API_P, fd);
        if (!d || !d.ok) {
            const t = (d && d.error) || 'هەڵە';
            if (typeof showToast === 'function') showToast('error', t); else alert(t);
            msg(t);
            return;
        }
        if (!TYPES.some(x => x.id === Number(d.id))) TYPES.push({ id: Number(d.id), cat: catId, name: d.name });
        if (rows.includes(r) && kind(r) === 'new' && Number(r.category_id) === catId) r.type_id = Number(d.id);
        render();
        const index = rows.indexOf(r);
        if (index >= 0) focusCell(index, 'type_id');
        msg('');
    } finally { btn.disabled = false; }
}
/** The brand: read-only for an existing item, a pick (or a new one) for a new item. */
function brandCell(i, r, editable) {
    if (!editable) return '<td>' + (r.brand_name ? '<span class="tg-gt grp">' + U.esc(r.brand_name) + '</span>' : '') + '</td>';
    const label = 'براندی نوێ';
    return '<td><div class="quick-field"><select data-i="' + i + '" data-f="brand_id" onchange="cellSel(this)">'
        + '<option value="0">—</option>'
        + BRANDS.map(b => '<option value="' + b.id + '"' + (Number(r.brand_id) === b.id ? ' selected' : '') + '>' + U.esc(b.name) + '</option>').join('')
        + '</select><button type="button" class="quick-add" title="' + label + '" aria-label="' + label + '" onclick="event.stopPropagation();quickBrand(' + i + ',this)">+</button></div></td>';
}
async function quickBrand(i, btn) {
    const r = rows[i];
    if (saving || !CAN_NEW_PROD || !r || kind(r) !== 'new') return;
    const name = prompt('ناوی براندی نوێ:');
    if (!name || !name.trim()) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', 'quick_brand'); fd.set('name', name.trim());
        const d = await postForm(API_P, fd);
        if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
        if (!BRANDS.some(b => b.id === Number(d.id))) BRANDS.push({ id: Number(d.id), name: d.name });
        if (rows.includes(r) && kind(r) === 'new') r.brand_id = Number(d.id);
        render();
        const index = rows.indexOf(r);
        if (index >= 0) focusCell(index, 'brand_id');
        msg('');
    } finally { btn.disabled = false; }
}
async function postForm(url, fd) {
    try {
        const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' }, body: fd });
        return await res.json();
    } catch (e) { return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' }; }
}
async function quickClassification(i, field, btn) {
    const r = rows[i];
    if (saving || !CAN_NEW_PROD || !r || kind(r) !== 'new') return;
    const name = prompt('ناوی گرووپی نوێ:');
    if (!name || !name.trim()) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', 'quick_category'); fd.set('name', name.trim());
        const d = await postForm(API_P, fd);
        if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
        if (!CATEGORIES.some(x => Number(x.id) === Number(d.id))) CATEGORIES.push({ id: Number(d.id), name: d.name });
        if (rows.includes(r) && kind(r) === 'new') r[field] = Number(d.id);
        render();
        const index = rows.indexOf(r);
        if (index >= 0) focusCell(index, field);
        msg('');
    } finally { btn.disabled = false; }
}
/* ---------- start step (erp/_doc_start.php) ---------- */
// Supplier and warehouse are chosen before the first line (a new supplier is
// created right there too). The grid carries no stock figures, so the
// warehouse may still be changed later from the same dialog.
const DS = window.DocStart;
// Editing keeps the invoice in the warehouse its goods went into.
if (EDIT_ID > 0) DS.canChangeWarehouse = () => false;
DS.onApply(({ party, warehouseId }) => {
    document.getElementById('supId').value = party.id;
    document.getElementById('supBox').value = party.name;
    document.getElementById('whBox').value = warehouseId;
    msg('');
    selectRow(rows.length - 1);
    // Deferred, so the start button's click has finished before the grid takes
    // focus. The entry row starts at its barcode cell.
    setTimeout(() => focusCell(rows.length - 1, 'barcode'), 0);
});
function needStart() {
    if (DS.ready && Number(document.getElementById('supId').value) > 0) return false;
    DS.open();
    return true;
}
document.getElementById('supBox').addEventListener('click', () => DS.open());

function render() {
    ensureBlank();
    let h = '';
    rows.forEach((r, i) => {
        const k = kind(r), isNew = k === 'new';
        // The barcode comes first: the entry row is scanned into, and a new
        // product's barcode is its first field.
        const barcode = k === 'old' ? txtCell(i, 'barcode', r.barcode, true)
            : '<td><input dir="ltr" value="' + U.esc(r.barcode) + '" data-i="' + i + '" data-f="barcode" autocomplete="off"'
              + (k === 'blank' ? ' placeholder="بارکۆد سکان بکە..."' : '') + ' oninput="cellTxt(this)"></td>';
        h += '<tr class="row-' + k + (i === selRow ? ' sel' : '') + '" onclick="selectRow(' + i + ')">'
           + '<td class="tg-idx">' + (k === 'blank' ? '<i class="fas fa-plus" style="opacity:.35"></i>' : (i + 1)) + '</td>'
           + barcode
           + '<td><input value="' + U.esc(r.name) + '" data-i="' + i + '" data-f="name" autocomplete="off"'
           +   ' placeholder="' + (k === 'blank' ? 'یان ناوی کاڵا بنووسە...' : (isNew ? 'ناوی کاڵای نوێ...' : '')) + '"'
           +   ' oninput="nameIn(this)">'
           +   (isNew ? '<span class="tg-new">نوێ</span>' : '') + '</td>';
        if (k === 'blank') { h += '<td colspan="12"></td></tr>'; return; }
        h += classificationCell(i, r, 'category_id', CATEGORIES, isNew)
           + typeCell(i, r, isNew)
           + brandCell(i, r, isNew)
           + unitCell(i, r, isNew)
           + numCell(i, 'quantity', r.quantity, false)
           + numCell(i, 'unit_price', r.unit_price, !(CAN_COST || isNew))
           + numCell(i, 'sale_price', r.sale_price, !isNew)
           + numCell(i, 'discount_amount', r.discount_amount, false)
           + '<td><input class="tg-n" data-i="' + i + '" data-f="total" value="' + U.nf(lineTotal(r)) + '"' + RO + '></td>'
           + txtCell(i, 'batch_number', r.batch_number, false)
           + '<td><input type="date" value="' + U.esc(r.expiry_date) + '" data-i="' + i + '" data-f="expiry_date" oninput="cellTxt(this)"></td>'
           + '<td class="tg-del" onclick="delRow(' + i + ');event.stopPropagation()" title="سڕینەوە"><i class="fas fa-times"></i></td></tr>';
    });
    document.getElementById('tgBody').innerHTML = h;
    totals();
}

function cellIn(el) {
    const i = Number(el.dataset.i), f = el.dataset.f;
    let v = parseFloat(el.value);
    if (isNaN(v) || v < 0) v = 0;
    rows[i][f] = v;
    // Patch only the derived cell so typing never rebuilds the grid.
    const t = cellEl(i, 'total');
    if (t) t.value = U.nf(lineTotal(rows[i]));
    totals();
}
function cellTxt(el) { rows[Number(el.dataset.i)][el.dataset.f] = el.value; }
function cellSel(el) {
    const i = Number(el.dataset.i), f = el.dataset.f, r = rows[i];
    r[f] = Number(el.value);
    if (f === 'category_id') {
        // a new group: its own list of types, so redraw the row's type cell
        if (!TYPES.some(x => x.id === Number(r.type_id) && x.cat === r.category_id)) r.type_id = 0;
        render(); selectRow(i); focusCell(i, 'category_id');
    }
}

function nameIn(el) {
    const i = Number(el.dataset.i), r = rows[i];
    if (needStart()) { el.value = r.name; return; }
    r.name = el.value;
    if (r.product_id > 0) {
        // Retyping a picked product's name detaches it: the row must be
        // resolved again, or the invoice would receive the old product.
        Object.assign(r, blankRow(), { name: el.value, quantity: r.quantity });
        render(); selectRow(i); focusCell(i, 'name', true);
    }
    if (r.is_new) return;   // just naming the new product, nothing to look up
    clearTimeout(acTimer);
    if (!el.value.trim()) { browse(i); return; }
    acTimer = setTimeout(() => lookup(i, false), 180);
}

/**
 * Search for the text in row i's product cell.
 * commit=false is the type-ahead list; commit=true is Enter, which also
 * resolves on its own: one exact barcode/code hit is picked, no hit at all
 * turns the row into a new product.
 */
async function lookup(i, commit) {
    const r = rows[i], el = cellEl(i, 'name');
    if (!r || !el) return;
    const q = el.value.trim();
    if (!q) return;
    const my = ++acReq;
    const wh = document.getElementById('whBox').value;
    const d  = await U.getJson(API_P + '?action=search&q=' + encodeURIComponent(q) + '&warehouse_id=' + wh);
    // Drop an answer that arrives after the row was changed or a newer search started.
    if (my !== acReq || rows[i] !== r || kind(r) !== 'blank' || r.name.trim() !== q) return;

    const res = (d && d.ok) ? d.results : [];
    if (commit) {
        const exact = res.filter(p => p.barcode === q || p.product_code === q);
        if (exact.length === 1) { pick(i, exact[0]); return; }
        if (!res.length) { markNew(i, q); return; }
    }
    const cur = cellEl(i, 'name');
    if (!commit && document.activeElement !== cur) return;

    const items = res.map(acItem);
    if (CAN_NEW_PROD) {
        items.push({ label: '+ کاڵای نوێ: ' + q, meta: 'دروستکردنی کاڵایەکی نوێ لەم ڕیزەدا', data: { isNewSeed: q }, add: true });
    }
    if (!items.length) { msg('کاڵا نەدۆزرایەوە: ' + q); return; }
    U.showAC(cur, items, p => (p.isNewSeed !== undefined ? markNew(i, p.isNewSeed) : pick(i, p)));
}

/** One product as a row of the lookup lists: name, its group · type, code, stock, cost. */
function acItem(p) {
    return {
        label: p.name,
        tag: U.groupType(p),
        meta: 'کۆد ' + p.product_code + (p.barcode ? ' · ' + p.barcode : '') + ' · بەردەست ' + U.qf(p.stock)
            + ' · کڕین ' + U.nf(p.purchase_price),
        data: p
    };
}

/**
 * Nothing typed yet in a row's product cell: the whole list, grouped as on the
 * products page, to pick from without knowing the name. Nothing is
 * highlighted, so Enter on an empty row still moves on to the payment.
 */
let browseCache = null;
async function browse(i) {
    const r = rows[i];
    if (!r || kind(r) !== 'blank' || !cellEl(i, 'name') || cellEl(i, 'name').value.trim()) return;
    const my = ++acReq;
    if (!browseCache) {
        const d = await U.getJson(API_P + '?action=search&browse=1&warehouse_id=' + document.getElementById('whBox').value);
        browseCache = (d && d.ok) ? d.results : [];
    }
    const cur = cellEl(i, 'name');
    if (my !== acReq || !cur || document.activeElement !== cur || cur.value.trim()) return;
    const items = [];
    let grp = null;
    browseCache.forEach(p => {
        const g = p.category_name || 'بێ گرووپ';
        if (g !== grp) { items.push({ header: g }); grp = g; }
        items.push(acItem(p));
    });
    if (!items.length) items.push({ empty: 'هێشتا هیچ کاڵایەک نییە — ناوی کاڵای نوێ بنووسە' });
    U.showAC(cur, items, p => pick(i, p), { select: false });
}

function cellEl(i, f) { return document.querySelector('#tgBody [data-i="' + i + '"][data-f="' + f + '"]'); }
function focusCell(i, f, caretEnd) {
    const el = cellEl(i, f);
    if (!el) return;
    el.focus();
    if (caretEnd) { try { const n = el.value.length; el.setSelectionRange(n, n); } catch (e) {} }
    else if (el.select) el.select();
}
function selectRow(i) {
    selRow = i;
    document.querySelectorAll('#tgBody tr').forEach((tr, k) => tr.classList.toggle('sel', k === i));
}
function delRow(i) {
    if (!rows[i]) return;
    rows.splice(i, 1);
    render();
    selRow = Math.min(i, rows.length - 1);
    selectRow(selRow);
}

/** Enter walks only the cells that matter for the row, then drops to the next row. */
function fieldOrder(r) {
    const k = kind(r), o = [];
    // a new product: barcode first, then name, group, type, brand and unit
    if (k === 'new') o.push('barcode', 'name', 'category_id', 'type_id', 'brand_id', 'unit_id');
    else o.push('name');
    o.push('quantity');
    if (CAN_COST || k === 'new') o.push('unit_price');
    if (k === 'new') o.push('sale_price');
    o.push('discount_amount');
    return o;
}
function nextCell(i, f) {
    const o = fieldOrder(rows[i]), at = o.indexOf(f);
    // the next cell that can take input (a group with no types has none)
    for (let n = at + 1; at >= 0 && n < o.length; n++) {
        const el = cellEl(i, o[n]);
        if (el && !el.readOnly) { focusCell(i, o[n]); return; }
    }
    const j = Math.min(i + 1, rows.length - 1);
    selectRow(j); focusCell(j, kind(rows[j]) === 'blank' ? 'barcode' : 'name');
}

function totals() {
    const sub   = rows.reduce((s, r) => s + (kind(r) === 'blank' ? 0 : lineTotal(r)), 0);
    const disc  = Number(document.getElementById('tDisc').value || 0);
    const grand = Math.max(0, sub - disc);
    const paid  = Number(document.getElementById('tPaid').value || 0);
    const rem   = grand - paid;
    document.getElementById('tSub').textContent   = U.nf(sub);
    document.getElementById('tGrand').textContent = U.nf(grand);
    const r = document.getElementById('tRemain');
    r.textContent = U.nf(rem);
    r.className = 'v ' + (rem > 0 ? 'remain-pos' : 'remain-zero');
}

/* ---------- scanner bar ---------- */
async function onScan() {
    const box = document.getElementById('scanBox');
    const code = box.value.trim();
    if (!code) return;
    if (needStart()) return;
    const wh = document.getElementById('whBox').value;
    const d = await U.getJson(API_P + '?action=barcode&code=' + encodeURIComponent(code) + '&warehouse_id=' + wh);
    if (d && d.ok && d.product) { addRow(d.product); box.value = ''; msg(''); return; }
    const s = await U.getJson(API_P + '?action=search&q=' + encodeURIComponent(code) + '&warehouse_id=' + wh);
    if (!s || !s.ok || !s.results.length) {
        if (!CAN_NEW_PROD) { msg('کاڵا نەدۆزرایەوە: ' + code); return; }
        box.value = '';
        const pos = insertPos();
        rows.splice(pos, 0, blankRow());
        markNew(pos, code);
        return;
    }
    msg('');
    U.showAC(box, s.results.map(acItem), p => { addRow(p); box.value = ''; });
}

/** The scan bar searches as you type a name; a scanner's digit run waits for its Enter. */
let scanTimer = null, scanReq = 0;
async function scanSuggest(q) {
    const box = document.getElementById('scanBox');
    if (needStart()) return;
    const my = ++scanReq;
    const d = await U.getJson(API_P + '?action=search&q=' + encodeURIComponent(q) + '&warehouse_id=' + document.getElementById('whBox').value);
    if (my !== scanReq || box.value.trim() !== q || document.activeElement !== box) return;
    const items = ((d && d.ok) ? d.results : []).map(acItem);
    if (!items.length) {
        items.push({ empty: CAN_NEW_PROD ? 'نەدۆزرایەوە — Enter لێبدە بۆ زیادکردنی وەک کاڵای نوێ' : 'هیچ کاڵایەک نەدۆزرایەوە' });
    }
    U.showAC(box, items, p => { addRow(p); box.value = ''; });
}

/* ---------- save ---------- */
function stopAt(i, f, t) { msg(t); selectRow(i); focusCell(i, f); }

async function purSave() {
    if (saving) return;
    msg('');
    if (needStart()) { msg('سەرەتا دابینکەر و کۆگا دیاری بکە'); return; }

    // Validate every line before anything is written.
    const seenBarcode = {};
    for (let i = 0; i < rows.length; i++) {
        const r = rows[i], k = kind(r), n = 'ڕیزی ' + (i + 1) + ': ';
        if (k === 'blank') {
            if (r.name.trim()) return stopAt(i, 'name', n + 'کاڵا دیاری نەکراوە — Enter لێبدە بۆ هەڵبژاردن یان دروستکردن');
            continue;
        }
        if (k === 'new') {
            if (!r.name.trim()) return stopAt(i, 'name', n + 'ناوی کاڵای نوێ پێویستە');
            if (!Number(r.unit_id)) return stopAt(i, 'unit_id', n + 'یەکەی کاڵای نوێ هەڵبژێرە');
            const bc = String(r.barcode || '').trim();
            if (bc) {
                if (seenBarcode[bc]) return stopAt(i, 'barcode', n + 'ئەم بارکۆدە لە ڕیزێکی تردا دووبارەیە');
                seenBarcode[bc] = true;
            }
        }
        if (!(Number(r.quantity) > 0)) return stopAt(i, 'quantity', n + 'بڕ پێویستە');
    }
    if (!filled().length) { msg('هیچ کاڵایەک زیاد نەکراوە'); focusCell(rows.length - 1, 'name'); return; }

    const supId = Number(document.getElementById('supId').value);
    if (!supId) { msg('دابینکەر هەڵبژێرە (F4)'); document.getElementById('supBox').focus(); return; }

    const btn = document.getElementById('btnSave');
    saving = true;
    if (btn) btn.disabled = true;
    try {
        // New products first. Each created id is written back onto its row at
        // once, so if the invoice save fails it can simply be retried without
        // creating the same product a second time.
        for (let i = 0; i < rows.length; i++) {
            const r = rows[i];
            if (kind(r) !== 'new') continue;
            msg('دروستکردنی کاڵای نوێ: ' + r.name + ' ...');
            const fd = new FormData();
            fd.set('action', 'save');
            fd.set('name', r.name.trim());
            fd.set('barcode', String(r.barcode || '').trim());
            fd.set('category_id', r.category_id || 0);
            fd.set('type_id', r.type_id || 0);
            fd.set('brand_id', r.brand_id || 0);
            fd.set('unit_id', r.unit_id || 0);
            fd.set('purchase_price', r.unit_price || 0);
            fd.set('sale_price', r.sale_price || 0);
            fd.set('supplier_id', supId);
            fd.set('is_active', '1');
            let d = null;
            try {
                const res = await fetch(API_P, { method: 'POST', body: fd });
                d = await res.json();
            } catch (e) { d = null; }
            if (!d || !d.ok) {
                const err = (d && d.error) || 'کێشەی پەیوەندی ڕوویدا';
                render();
                return stopAt(i, /بارکۆد/.test(err) ? 'barcode' : 'name', 'ڕیزی ' + (i + 1) + ': ' + err);
            }
            r.product_id = Number(d.id);
            r.is_new     = false;
            r.unit_code  = (UNITS.find(u => u.id === Number(r.unit_id)) || {}).code || '';
            // now an existing item: its read-only cells show the chosen names
            r.category_name = (CATEGORIES.find(c => Number(c.id) === Number(r.category_id)) || {}).name || '';
            r.type_name     = (TYPES.find(t => t.id === Number(r.type_id)) || {}).name || '';
            r.brand_name    = (BRANDS.find(b => b.id === Number(r.brand_id)) || {}).name || '';
        }
        render();
        msg('');

        const lines = filled();
        const sub   = lines.reduce((s, r) => s + lineTotal(r), 0);
        const disc  = Number(document.getElementById('tDisc').value || 0);
        const grand = Math.max(0, sub - disc);
        const paid  = Number(document.getElementById('tPaid').value || 0);

        // Editing posts the same payload to 'update' with the invoice id.
        const d = await U.postJson(API_U + '?action=' + (EDIT_ID > 0 ? 'update' : 'save'), {
            id: EDIT_ID || undefined,
            supplier_id: supId,
            warehouse_id: Number(document.getElementById('whBox').value),
            invoice_date: document.getElementById('invDate').value,
            supplier_invoice_no: document.getElementById('supInvNo').value,
            discount_amount: disc,
            paid_amount: paid,
            payment_type: paid <= 0 ? 'credit' : (paid >= grand ? 'cash' : 'partial'),
            items: lines.map(r => ({
                product_id: r.product_id, quantity: r.quantity, unit_price: r.unit_price,
                unit_id: r.unit_id || null, unit_factor: r.unit_factor || 1,
                discount_amount: r.discount_amount, batch_number: r.batch_number,
                expiry_date: r.expiry_date || null
            }))
        });

        if (!d || !d.ok) { msg((d && d.error) || 'هەڵە لە پاشەکەوتکردن'); return; }
        if (EDIT_ID > 0) {
            showToast('success', 'وەسڵی کڕین ' + d.invoice_number + ' نوێکرایەوە');
            setTimeout(() => { location.href = APP + '/erp/purchase-invoices.php'; }, 600);
            return;
        }
        showToast('success', 'وەسڵی کڕین ' + d.invoice_number + ' پاشەکەوتکرا');
        purReset();
        // The next invoice may be another supplier: ask again, never carry over.
        document.getElementById('supId').value = 0;
        document.getElementById('supBox').value = '';
        DS.open({ fresh: true });
    } finally {
        saving = false;
        if (btn) btn.disabled = false;
    }
}

function purReset() {
    rows = []; selRow = -1;
    browseCache = null;   // stock and the catalogue moved with the saved invoice
    document.getElementById('tDisc').value = 0;
    document.getElementById('tPaid').value = 0;
    document.getElementById('supInvNo').value = '';
    msg('');
    render();
    focusCell(rows.length - 1, 'barcode');
}

/* ---------- keyboard ---------- */
document.getElementById('tgBody').addEventListener('keydown', e => {
    const el = e.target;
    if (!el.dataset || el.dataset.i === undefined) return;
    const i = Number(el.dataset.i), f = el.dataset.f;

    if (f === 'name' && U.acHandleKey(e)) return;

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (el.tagName === 'SELECT') return;
        e.preventDefault();
        const j = i + (e.key === 'ArrowDown' ? 1 : -1);
        if (j >= 0 && j < rows.length) { selectRow(j); focusCell(j, cellEl(j, f) ? f : 'name'); }
        return;
    }
    if (e.key === 'Delete' && e.ctrlKey) {
        e.preventDefault();
        delRow(i);
        focusCell(Math.min(i, rows.length - 1), 'name');
        return;
    }
    if (e.key !== 'Enter') return;
    e.preventDefault();

    // Entry row, barcode cell: a scan resolves at once; empty moves on to the name search.
    if (f === 'barcode' && kind(rows[i]) === 'blank') {
        const code = el.value.trim();
        if (code) barcodeIn(i, code);
        else focusCell(i, 'name');
        return;
    }
    if (f === 'name' && kind(rows[i]) === 'blank') {
        clearTimeout(acTimer);
        if (el.value.trim()) lookup(i, true);
        else if (filled().length) document.getElementById('tPaid').focus();
        return;
    }
    nextCell(i, f);
});
document.getElementById('tgBody').addEventListener('focusout', e => {
    if (e.target.dataset && e.target.dataset.f === 'name') setTimeout(U.hideAC, 150);
});
document.getElementById('tgBody').addEventListener('focusin', e => {
    if (e.target.dataset && e.target.dataset.i !== undefined) selectRow(Number(e.target.dataset.i));
    if (e.target.dataset && e.target.dataset.f === 'name') browse(Number(e.target.dataset.i));
});
// clicking back into an empty product cell (after Esc) opens the list again
document.getElementById('tgBody').addEventListener('click', e => {
    if (e.target.dataset && e.target.dataset.f === 'name' && !U.acOpen()) browse(Number(e.target.dataset.i));
});

document.getElementById('scanBox').addEventListener('input', e => {
    const q = e.target.value.trim();
    clearTimeout(scanTimer);
    if (!q || /^\d{6,}\$/.test(q)) { U.hideAC(); return; }
    scanTimer = setTimeout(() => scanSuggest(q), 180);
});
document.getElementById('scanBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); onScan(); }
    if (e.key === 'Escape') { e.target.value = ''; msg(''); }
});
document.getElementById('supBox').addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); DS.open(); }
});
document.getElementById('tPaid').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); purSave(); }
});

document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('scanBox').focus(); return; }
    if (e.key === 'F4') { e.preventDefault(); DS.open(); return; }
    if (e.key === 'F7') { e.preventDefault(); selectRow(rows.length - 1); focusCell(rows.length - 1, 'barcode'); return; }
    if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); purSave(); return; }
    if (e.key === 'Delete' && selRow >= 0 && !/INPUT|SELECT/.test(document.activeElement.tagName)) {
        e.preventDefault(); delRow(selRow);
    }
});

document.getElementById('tDisc').addEventListener('input', totals);
document.getElementById('tPaid').addEventListener('input', totals);

if (EDIT_ID > 0) rows = EDIT_ROWS.map(r => Object.assign(blankRow(), r));
render();
focusCell(rows.length - 1, 'barcode');
JS;

require_once __DIR__ . '/../includes/footer.php';
