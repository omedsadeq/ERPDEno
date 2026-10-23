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
// Needed by edit mode below (Erp2Sales::load). _bootstrap.php deliberately
// loads only the always-needed classes, so document services are pulled in by
// the pages that actually use them.
require_once __DIR__ . '/../classes/Erp2Sales.php';
erp2_require('erp2.sales.view');

$pageTitle  = 'فرۆشتن';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'فرۆشتن'],
];

$db          = Database::getInstance();
$warehouses  = erp2_warehouses();
$defaultWh   = erp2_default_warehouse_id();
$canCreate   = erp2_can('erp2.sales.create');
$canPrice    = erp2_can('erp2.price.sale');
$canProfit   = erp2_can('erp2.profit.view');
$nextNumber  = Erp2Sequence::preview('SALE', 0);

/*
 * Edit mode (?edit=<id>). The till doubles as the edit screen rather than
 * growing a second grid to maintain: the fields are identical, and two grids
 * that drift apart is how "the edit screen totals differently from the sale
 * screen" bugs start.
 *
 * Erp2Sales::update() reverses the original lines and posts the new ones inside
 * one transaction, so the net stock effect is the difference — the brief's
 * "10 becomes 7 returns 3" case. The invoice NUMBER never changes.
 */
$editId  = (int)($_GET['edit'] ?? 0);
$editInv = null;
if ($editId > 0) {
    erp2_require('erp2.sales.edit');
    $editInv = Erp2Sales::load($editId);
    if (!$editInv) {
        $_SESSION['erp2_flash'] = 'وەسڵ نەدۆزرایەوە';
        header('Location: ' . APP_URL . '/erp2/invoices.php');
        exit;
    }
    // A cancelled invoice has already been reversed; editing it would post
    // stock against a document the books consider closed.
    if ($editInv['status'] !== 'confirmed') {
        $_SESSION['erp2_flash'] = 'تەنها وەسڵی پەسەندکراو دەکرێت دەستکاری بکرێت';
        header('Location: ' . APP_URL . '/erp2/invoices.php');
        exit;
    }
}

$walkIn = $db->fetch("SELECT id, name FROM erp2_customers WHERE is_walk_in = 1 AND is_active = 1 LIMIT 1")
       ?: $db->fetch("SELECT id, name FROM erp2_customers WHERE is_active = 1 ORDER BY id LIMIT 1");

/*
 * Prescription mode (?prescription=<id>) — the counter clicked a row in the
 * دەرمان queue. This is NOT edit mode: it opens a brand new sale that merely
 * starts with the doctor's lines and the patient as the customer. The
 * prescription is only flagged 'sold' after the sale actually saves, so
 * abandoning this screen leaves it in the queue for the next attempt.
 */
$prescId  = (int)($_GET['prescription'] ?? 0);
$presc    = null;
$prescRxs = [];
if ($prescId > 0 && !$editInv) {
    erp2_require('erp2.prescriptions.view');
    $presc = $db->fetch(
        "SELECT pr.*, pa.full_name AS patient_name
         FROM erp2_prescriptions pr
         JOIN patients pa ON pa.id = pr.patient_id
         WHERE pr.id = :id",
        ['id' => $prescId]
    );
    if (!$presc) {
        $_SESSION['erp2_flash'] = 'داواکاری دەرمان نەدۆزرایەوە';
        header('Location: ' . APP_URL . '/erp2/prescriptions.php');
        exit;
    }
    if ($presc['status'] !== 'pending') {
        $_SESSION['erp2_flash'] = 'ئەم داواکارییە پێشتر جێبەجێکراوە';
        header('Location: ' . APP_URL . '/erp2/prescriptions.php');
        exit;
    }
    $prescRxs = $db->fetchAll(
        "SELECT i.product_id, i.product_name, i.quantity, i.dosage,
                p.sale_price, p.min_sale_price, p.unit_id,
                COALESCE(s.quantity, 0) AS stock
         FROM erp2_prescription_items i
         LEFT JOIN erp2_products p ON p.id = i.product_id
         LEFT JOIN erp2_warehouse_product_stock s
                ON s.product_id = i.product_id AND s.warehouse_id = :w
         WHERE i.prescription_id = :id
         ORDER BY i.id",
        ['id' => $prescId, 'w' => $defaultWh]
    );
}

$customCSS = <<<CSS
.till{display:flex;flex-direction:column;gap:10px}
.till-head{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:8px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px}
.till-head label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.till-head .form-control{height:32px;padding:3px 8px;font-size:13px}
.till-scan{display:flex;gap:8px;align-items:center;background:#0f766e;padding:10px;border-radius:8px}
.till-scan input{flex:1;height:42px;font-size:17px;padding:6px 12px;border:0;border-radius:6px;direction:ltr;font-weight:600}
.till-scan .hint{color:#c9f0eb;font-size:12px;white-space:nowrap}
.till-grid{background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:auto;min-height:230px;max-height:44vh}
table.tg{width:100%;border-collapse:collapse;font-size:13px}
table.tg thead th{position:sticky;top:0;background:#eef2f5;border-bottom:2px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:600;text-align:right;z-index:1}
table.tg td{border-bottom:1px solid #f0f0f0;padding:0}
table.tg tr.sel td{background:#e8f4fd}
table.tg input{width:100%;border:0;background:transparent;padding:7px 8px;font-size:13px;font-family:inherit}
table.tg input:focus{background:#fff8dc;outline:2px solid #0078D4;outline-offset:-2px}
table.tg input[readonly]{color:#555}
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
@media(max-width:900px){.till-head{grid-template-columns:1fr 1fr}.till-foot{grid-template-columns:1fr}}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($editInv): ?>
<div class="alert alert-warning" style="margin-bottom:10px;display:flex;align-items:center;gap:10px">
    <i class="fas fa-pen-to-square"></i>
    <div class="alert-content" style="flex:1">
        دەستکاری وەسڵی <b dir="ltr"><?= erp2_esc($editInv['invoice_number']) ?></b> —
        کاڵا کۆنەکان دەگەڕێندرێنەوە بۆ کۆگا و نوێیەکان دادەبەزرێن. ژمارەی وەسڵ ناگۆڕێت.
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= APP_URL ?>/erp2/invoices.php">پاشگەزبوونەوە</a>
</div>
<?php endif; ?>

<?php if ($presc): ?>
<div class="alert alert-info" style="margin-bottom:10px;display:flex;align-items:center;gap:10px">
    <i class="fas fa-pills"></i>
    <div class="alert-content" style="flex:1">
        داواکاری دەرمانی <b><?= erp2_esc($presc['patient_name']) ?></b>
        (#<?= (int)$presc['id'] ?>) — لاینەکان پڕکراونەتەوە. دوای پاشەکەوتکردن وەک «فرۆشراو» نیشانە دەکرێت.
        <?php if (!empty($presc['notes'])): ?>
        <div style="font-size:12px;color:#555;margin-top:4px">تێبینی دکتۆر: <?= erp2_esc($presc['notes']) ?></div>
        <?php endif; ?>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= APP_URL ?>/erp2/prescriptions.php">گەڕانەوە بۆ لیست</a>
</div>
<?php endif; ?>

<div class="till">
    <?php
    // Who and where come from the start step (erp2/_doc_start.php). A new sale
    // starts with NO customer: it is chosen on purpose, walk-in included.
    // Edit and prescription modes already know both.
    $startParty = null;
    if ($editInv) {
        $startParty = ['id' => (int)$editInv['customer_id'], 'name' => $editInv['customer_name']];
    } elseif ($presc) {
        $startParty = ['id' => (int)($presc['customer_id'] ?? ($walkIn['id'] ?? 0)), 'name' => $presc['patient_name']];
    }
    $selWh = (int)($editInv['warehouse_id'] ?? $defaultWh);
    ?>
    <div class="till-head">
        <div style="position:relative">
            <label>کڕیار <span style="opacity:.6">(F4)</span></label>
            <div class="ds-head-pick">
                <input class="form-control" id="custBox" readonly placeholder="کڕیار هەڵنەبژێردراوە"
                       value="<?= erp2_esc($startParty['name'] ?? '') ?>" title="بۆ گۆڕین کلیک بکە">
                <button type="button" class="ds-head-btn" onclick="DocStart.open()"><i class="fas fa-pen"></i> گۆڕین</button>
            </div>
            <input type="hidden" id="custId" value="<?= (int)($startParty['id'] ?? 0) ?>">
        </div>
        <div>
            <label>کۆگا</label>
            <select class="form-control" id="whBox" disabled title="کۆگا لە هەنگاوی دەستپێکردن دیاری دەکرێت">
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $selWh ? 'selected' : '' ?>>
                    <?= erp2_esc($w['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>ژمارەی وەسڵ</label>
            <input class="form-control" id="invNo" readonly dir="ltr"
                   value="<?= erp2_esc($editInv['invoice_number'] ?? $nextNumber) ?>">
        </div>
        <div>
            <label>بەروار</label>
            <input class="form-control" type="date" id="invDate"
                   value="<?= erp2_esc($editInv['invoice_date'] ?? date('Y-m-d')) ?>">
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

    <div class="till-foot">
        <div class="till-keys">
            <kbd>F2</kbd> گەڕانی کاڵا &nbsp;·&nbsp;
            <kbd>F4</kbd> گەڕانی کڕیار &nbsp;·&nbsp;
            <kbd>Enter</kbd> خانەی داهاتوو &nbsp;·&nbsp;
            <kbd>Delete</kbd> سڕینەوەی ڕیز &nbsp;·&nbsp;
            <kbd>Ctrl+S</kbd> پاشەکەوت &nbsp;·&nbsp;
            <kbd>Ctrl+P</kbd> پاشەکەوت و چاپ &nbsp;·&nbsp;
            <kbd>Esc</kbd> پاککردنەوە
            <div id="tillMsg" style="margin-top:6px;color:#c62828;font-weight:600"></div>
        </div>
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
                <button class="btn btn-subtle" onclick="tillReset()"><i class="fas fa-eraser"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$docStart = [
    'kind'        => 'customer',
    'api'         => APP_URL . '/erp2/api/parties.php',
    'title'       => $editInv ? 'دەستکاری وەسڵ' : 'فرۆشتنی نوێ',
    'icon'        => 'fa-cash-register',
    'accent'      => '#0f766e',
    'warehouses'  => $warehouses,
    'defaultWh'   => $defaultWh,
    // Only a customer actually marked walk-in: $walkIn falls back to the first
    // customer on file, which must never be offered as «کڕیاری ڕاستەوخۆ».
    'walkIn'      => $db->fetch("SELECT id, name FROM erp2_customers WHERE is_walk_in = 1 AND is_active = 1 LIMIT 1") ?: null,
    'canQuickAdd' => $canCreate,
    'autoOpen'    => !$editInv && !$presc,
    'party'       => $startParty,
    'warehouseId' => $selWh,
    'lockRoot'    => '.till',
];
require __DIR__ . '/_doc_start.php';
?>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiP = APP_URL . '/erp2/api/products.php';
$apiC = APP_URL . '/erp2/api/parties.php';
$apiS = APP_URL . '/erp2/api/sales.php';
$appUrl = APP_URL;
$jsCanPrice = $canPrice ? 'true' : 'false';
$jsEditId = (int)$editId;
// Fully paid when opened: the paid box then follows the edited total.
$jsEditPaidFull = ($editInv && (float)$editInv['paid_amount'] >= (float)$editInv['grand_total'] - 0.5) ? 'true' : 'false';

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
        $lvl = Erp2Stock::levelOf((int)$it['product_id'], (int)$editInv['warehouse_id']);
        $pre[] = [
            'product_id'      => (int)$it['product_id'],
            'name'            => $it['product_name'] . (($it['type_name'] ?? $it['category_name'] ?? '') !== '' ? '  ·  ' . ($it['type_name'] ?? $it['category_name']) : ''),
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

/*
 * The prescription's lines, in the same shape the grid uses. Quantity is
 * clamped to what the warehouse actually holds: the doctor prescribed against
 * a stock figure that may be minutes old, and the till must not open already
 * over-committed.
 */
$jsPrescRows = '[]';
$jsPrescId   = (int)($presc['id'] ?? 0);
if ($presc && $prescRxs) {
    $pre = [];
    foreach ($prescRxs as $it) {
        $stock = (float)$it['stock'];
        $want  = (float)$it['quantity'];
        $pre[] = [
            'product_id'      => (int)$it['product_id'],
            'name'            => $it['product_name'],
            'quantity'        => $stock > 0 ? min($want, $stock) : $want,
            'unit_price'      => (float)($it['sale_price'] ?? 0),
            'min_price'       => (float)($it['min_sale_price'] ?? 0),
            'discount_amount' => 0,
            'stock'           => $stock,
            'unit_factor'     => 1,
            'unit_id'         => $it['unit_id'] !== null ? (int)$it['unit_id'] : null,
            'dosage'          => $it['dosage'],
        ];
    }
    $jsPrescRows = json_encode($pre,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

$customJS = <<<JS
const API_P = '{$apiP}', API_C = '{$apiC}', API_S = '{$apiS}', APP = '{$appUrl}';
const CAN_PRICE = {$jsCanPrice};
const EDIT_ID = {$jsEditId};
const EDIT_ROWS = {$jsEditRows};
// A paid-in-full invoice stays paid in full while its discount or lines are
// edited (the difference is handed back or collected at the desk). Typing in
// the paid box takes over.
let paidFollows = EDIT_ID > 0 && {$jsEditPaidFull};
const PRESC_ID = {$jsPrescId};
const PRESC_ROWS = {$jsPrescRows};
const API_RX = APP + '/erp2/api/prescriptions.php';
// Formatting and the lookup popup come from assets/js/erp-common.js, shared
// with the purchase screen so the two behave identically.
const U = window.ErpUI;
let rows = [];            // grid model; the DOM is only a view of this
let selRow = -1;
let scanTimer = null;

const nf  = U.nf;
const esc = U.esc;
const msg = t => { document.getElementById('tillMsg').textContent = t || ''; };

/* ---------- start step (erp2/_doc_start.php) ---------- */
const DS = window.DocStart;
// The stock column shows the chosen warehouse; once lines exist, moving to
// another warehouse would leave every one of them showing the wrong figure.
DS.canChangeWarehouse = () => rows.length === 0;
DS.onApply(({ party, warehouseId }) => {
    document.getElementById('custId').value = party.id;
    document.getElementById('custBox').value = party.name;
    document.getElementById('whBox').value = warehouseId;
    msg('');
    document.getElementById('scanBox').focus();
});
/** Nothing is added to a sale before its customer and warehouse are set. */
function needStart() {
    if (DS.ready && Number(document.getElementById('custId').value) > 0) return false;
    DS.open();
    return true;
}
document.getElementById('custBox').addEventListener('click', () => DS.open());

/* ---------- grid ---------- */
function addRow(p, qty) {
    if (needStart()) return;
    // Same product scanned twice bumps the existing line instead of adding a
    // duplicate, which is what a till is expected to do.
    const at = rows.findIndex(r => r.product_id === Number(p.id));
    if (at >= 0) {
        rows[at].quantity += (qty || 1);
        render(); selectRow(at); return;
    }
    rows.push({
        product_id: Number(p.id),
        // shown with its group · type, so «Whitening» toothpaste and mouthwash differ
        name: p.name + (U.groupType(p) ? '  ·  ' + U.groupType(p) : ''),
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
    if (paidFollows) document.getElementById('tPaid').value = Math.round(grand * 100) / 100;
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
    if (needStart()) return;
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
        tag: U.groupType(p),
        meta: 'کۆد ' + p.product_code + ' · بەردەست ' + nf(p.stock) + ' · ' + nf(p.sale_price),
        data: p
    })), p => { addRow(p, 1); document.getElementById('scanBox').value = ''; });
}

/* ---------- save ---------- */
async function tillSave(print) {
    if (needStart()) { msg('سەرەتا کڕیار و کۆگا دیاری بکە'); return; }
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
            setTimeout(() => { window.location = APP + '/erp2/invoices.php'; }, 700);
            return;
        }

        showToast('success', 'وەسڵ ' + d.invoice_number + ' پاشەکەوتکرا');
        if (print) window.open(APP + '/erp2/print-invoice.php?id=' + d.id, '_blank');

        // Prescription mode: the sale is already committed, so flag the queue
        // row and go back to the list. A failure here is deliberately quiet —
        // it must never read as "the sale failed", because it did not.
        if (PRESC_ID > 0) {
            try {
                const fd = new FormData();
                fd.append('action', 'mark_sold');
                fd.append('id', PRESC_ID);
                fd.append('invoice_id', d.id);
                await fetch(API_RX, { method: 'POST', body: fd });
            } catch (e) { /* sale stands regardless */ }
            setTimeout(() => { window.location = APP + '/erp2/prescriptions.php'; }, 700);
            return;
        }

        tillReset();
        // The next sale is a new customer: ask again, never carry this one over.
        document.getElementById('custId').value = 0;
        document.getElementById('custBox').value = '';
        DS.open({ fresh: true });
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
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); DS.open(); }
});

document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('scanBox').focus(); return; }
    if (e.key === 'F4') { e.preventDefault(); DS.open(); return; }
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
document.getElementById('tPaid').addEventListener('input', () => { paidFollows = false; totals(); });

// Edit mode opens on the stored lines; a prescription opens on the doctor's
// lines; a plain new sale opens empty.
const bootRows = (EDIT_ID > 0 && EDIT_ROWS.length) ? EDIT_ROWS
               : (PRESC_ID > 0 && PRESC_ROWS.length) ? PRESC_ROWS
               : [];
if (bootRows.length) {
    rows = bootRows.map(r => ({
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
