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
 * whose barcode, پۆل, جۆر, unit and prices are typed in place. New products
 * (and any پۆل/جۆر names that do not exist yet) are created when the invoice
 * is saved, immediately before the invoice itself.
 *
 * پۆل is a top-level erp_categories row; جۆر is a child category under it
 * (parent_id). A product is filed under the جۆر when one is given, otherwise
 * under the پۆل.
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

// A delivery often contains items the catalogue has never seen. Letting the
// operator define them in the grid keeps the invoice open instead of forcing a
// trip to the product screen and back with a half-typed grid.
$canNewProd = $canCreate && erp_can('erp.products.create');
$units = $categories = $brands = [];
if ($canNewProd) {
    $db         = Database::getInstance();
    $brands = $db->fetchAll("SELECT id, name FROM erp_brands WHERE is_active = 1 ORDER BY name");
    $units      = $db->fetchAll("SELECT id, code, name FROM erp_units WHERE is_active = 1 ORDER BY id");
    $categories = $db->fetchAll("SELECT id, name, parent_id FROM erp_categories WHERE is_active = 1 ORDER BY name");
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
table.tg{width:100%;min-width:1540px;border-collapse:collapse;font-size:13px}
table.tg thead th{position:sticky;top:0;background:#eef2f5;border-bottom:2px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:600;text-align:right;z-index:1;white-space:nowrap}
table.tg td{border-bottom:1px solid #f0f0f0;border-left:1px solid #f5f5f5;padding:0;position:relative}
table.tg tr.sel td{background:#e8f4fd}
table.tg tr.row-new td{background:#fffbea}
table.tg tr.row-new.sel td{background:#fff3c4}
table.tg input,table.tg select{width:100%;border:0;background:transparent;padding:7px 8px;font-size:13px;font-family:inherit}
table.tg select{padding:6px 4px}
table.tg input:focus,table.tg select:focus{background:#fff8dc;outline:2px solid #0078D4;outline-offset:-2px}
table.tg input[readonly]{color:#78909c}
table.tg input[data-f="name"]{font-weight:600;color:#263238}
table.tg tr.row-blank input[data-f="name"]{font-weight:400}
table.tg input.tg-newval{color:#e65100;font-weight:600}
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
?>

<div class="till">
    <div class="till-head">
        <div style="position:relative">
            <label>دابینکەر <span style="opacity:.6">(F4)</span></label>
            <div class="quick-field"><input class="form-control" id="supBox" autocomplete="off" placeholder="ناوی دابینکەر...">
<?php if ($canCreate): ?><button type="button" class="quick-add" onclick="quickSupplier(this)" title="دابینکەری نوێ" aria-label="دابینکەری نوێ">+</button><?php endif; ?></div>
            <input type="hidden" id="supId" value="0">
        </div>
        <div>
            <label>کۆگا</label>
            <select class="form-control" id="whBox">
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWh ? 'selected' : '' ?>>
                    <?= erp_esc($w['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>ژمارەی وەسڵ</label>
            <input class="form-control" id="invNo" value="<?= erp_esc($nextNumber) ?>" readonly dir="ltr">
        </div>
        <div>
            <label>وەسڵی دابینکەر</label>
            <input class="form-control" id="supInvNo" dir="ltr" placeholder="ئارەزوومەندانە">
        </div>
        <div>
            <label>بەروار</label>
            <input class="form-control" type="date" id="invDate" value="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <div class="till-scan">
        <i class="fas fa-barcode" style="color:#fff;font-size:19px"></i>
        <input id="scanBox" placeholder="سکانی بارکۆد..." autocomplete="off">
        <span class="hint"><kbd>F2</kbd> سکان &nbsp; <kbd>F7</kbd> ڕیزی نوێ &nbsp; <kbd>Ctrl+S</kbd> پاشەکەوت</span>
    </div>

    <div class="till-grid">
        <table class="tg">
            <thead>
                <tr>
                    <th class="tg-idx">#</th>
                    <th style="min-width:220px">کاڵا</th>
                    <th style="width:125px">بارکۆد</th>
                    <th style="width:120px">پۆل</th>
                    <th style="width:120px">جۆر</th>
                    <th style="min-width:140px">براند</th><th style="width:90px">یەکە</th>
                    <th style="width:72px">بڕ</th>
                    <th style="width:98px">نرخی کڕین</th>
                    <th style="width:98px">نرخی فرۆشتن</th>
                    <th style="width:82px">داشکاندن</th>
                    <th style="width:100px">کۆ</th>
                    <th style="width:90px">بەچ</th>
                    <th style="width:122px">بەسەرچوون</th>
                    <th class="tg-del"></th>
                </tr>
            </thead>
            <tbody id="tgBody"></tbody>
        </table>
    </div>

    <div class="till-foot">
        <div class="till-keys">
            لە خانەی <b>کاڵا</b> ناو یان بارکۆد بنووسە و <kbd>Enter</kbd> لێبدە.
            <?php if ($canNewProd): ?>
            ئەگەر کاڵاکە نەبوو، ڕیزەکە دەبێتە <b style="color:#f9a825">نوێ</b> و خانەکانی (بارکۆد، پۆل، جۆر، یەکە، نرخ) پڕدەکەیتەوە —
            لە کاتی پاشەکەوتدا کاڵاکە دروست دەکرێت و دەچێتە کۆگا.
            پۆل/جۆرێک کە <b style="color:#e65100">پرتەقاڵی</b> بێت نوێیە و دروست دەکرێت.
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
                <input id="tDisc" type="number" min="0" step="1" value="0"></div>
            <div class="row grand"><span>کۆی کۆتایی</span><span class="v" id="tGrand">0</span></div>
            <div class="row"><span>پارەی دراو</span>
                <input id="tPaid" type="number" min="0" step="1" value="0"></div>
            <div class="row"><span>ماوە بۆ دابینکەر</span><span class="v remain-zero" id="tRemain">0</span></div>
            <div class="till-actions">
                <?php if ($canCreate): ?>
                <button class="btn btn-primary" id="btnSave" onclick="purSave()">
                    <i class="fas fa-save"></i> پاشەکەوت
                </button>
                <?php endif; ?>
                <button class="btn btn-subtle" onclick="purReset()"><i class="fas fa-eraser"></i></button>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiP = APP_URL . '/erp/api/products.php';
$apiC = APP_URL . '/erp/api/parties.php';
$apiU = APP_URL . '/erp/api/purchases.php';
$jsFlags      = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$jsCanCost    = $canCost ? 'true' : 'false';
$jsCanNewProd = $canNewProd ? 'true' : 'false';
$jsUnits      = json_encode(
    array_map(fn($u) => ['id' => (int)$u['id'], 'code' => $u['code'], 'name' => $u['name']], $units),
    $jsFlags
);
$jsCats       = json_encode(
    array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'parent_id' => (int)$c['parent_id']], $categories),
    $jsFlags
);

$jsBrands = json_encode($brands, $jsFlags);
$jsCanCreate = $canCreate ? 'true' : 'false';
$customJS = <<<JS
const API_P = '{$apiP}', API_C = '{$apiC}', API_U = '{$apiU}';
const CAN_COST = {$jsCanCost};
const CAN_NEW_PROD = {$jsCanNewProd};
const UNITS = {$jsUnits};
const CATS = {$jsCats};
const BRANDS = {$jsBrands}, CATEGORIES = CATS;
const CAN_CREATE = {$jsCanCreate};
const U = window.ErpUI;
let rows = [], selRow = -1, supTimer = null, acTimer = null, acReq = 0, saving = false;

const msg = t => { document.getElementById('tillMsg').textContent = t || ''; };

/* ---------- پۆل / جۆر lookups (client side: the category table is small) ---------- */
const norm = s => String(s || '').trim().toLowerCase();
const classList = () => CATS.filter(c => !c.parent_id);
const findClass = name => classList().find(c => norm(c.name) === norm(name));
const childrenOf = pid => CATS.filter(c => c.parent_id === Number(pid));

function catOptions(i, f) {
    if (f === 'class_name') return classList();
    const cls = findClass(rows[i].class_name);
    return cls ? childrenOf(cls.id) : [];
}
/** True when the typed پۆل/جۆر does not exist yet and will be created on save. */
function catIsNewVal(i, f) {
    const q = norm(rows[i][f]);
    return q !== '' && !catOptions(i, f).some(c => norm(c.name) === q);
}

/* ---------- row model ---------- */
// Three kinds of row: 'old' is linked to a catalogue product, 'new' is a
// product that will be created on save, 'blank' is not resolved yet (the
// trailing entry row, or one whose name is still being typed).
const blankRow = () => ({
    product_id: 0, is_new: false, name: '', barcode: '', class_name: '', category_name: '',
    brand_id: 0, unit_id: 0, unit_code: '', quantity: 1, unit_price: 0, sale_price: 0, discount_amount: 0,
    batch_number: '', expiry_date: ''
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
    r.unit_price = Number(p.purchase_price || 0);
    r.sale_price = Number(p.sale_price || 0);
    // A product filed under a sub-category shows parent as پۆل and itself as
    // جۆر; one filed directly under a top-level category has only a پۆل.
    if (p.category_parent_id) { r.class_name = p.category_parent_name || ''; r.category_name = p.category_name || ''; }
    else { r.class_name = p.category_name || ''; r.category_name = ''; }
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

function markNew(i, seed) {
    seed = String(seed || '').trim();
    if (!CAN_NEW_PROD) { msg('کاڵا نەدۆزرایەوە: ' + seed); return; }
    const r = rows[i];
    r.product_id = 0; r.is_new = true;
    // A scanner produces a digit run; anything else was typed as a name.
    if (/^\d{4,}\$/.test(seed)) { r.barcode = seed; r.name = ''; }
    else { r.name = seed; }
    if (!r.unit_id && UNITS.length) r.unit_id = UNITS[0].id;
    render(); selectRow(i); msg('');
    focusCell(i, r.name ? 'barcode' : 'name');
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
function catCell(i, f, v, editable) {
    if (!editable) return '<td><input value="' + U.esc(v) + '"' + RO + '></td>';
    const label = f === 'class_name' ? 'پۆلی نوێ' : 'جۆری نوێ';
    return '<td><div class="quick-field"><input value="' + U.esc(v) + '" data-i="' + i + '" data-f="' + f + '" autocomplete="off"'
         + (catIsNewVal(i, f) ? ' class="tg-newval"' : '')
         + ' placeholder="' + (f === 'class_name' ? 'پۆل...' : 'جۆر...') + '"'
         + ' oninput="catSuggest(this, false)" onclick="catClick(this)">'
         + '<button type="button" class="quick-add" title="' + label + '" aria-label="' + label + '" onclick="event.stopPropagation();quickCategory(' + i + ',\'' + f + '\',this)">+</button></div></td>';
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
    if (!editable) return '<td></td>';
    const label = field === 'brand_id' ? 'براندی نوێ' : 'جۆری نوێ';
    return '<td><div class="quick-field"><select data-i="' + i + '" data-f="' + field + '" onchange="cellSel(this)">'
        + '<option value="0">—</option>'
        + list.map(x => '<option value="' + Number(x.id) + '"' + (Number(r[field]) === Number(x.id) ? ' selected' : '') + '>' + U.esc(x.name) + '</option>').join('')
        + '</select><button type="button" class="quick-add" title="' + label + '" aria-label="' + label + '" onclick="event.stopPropagation();quickClassification(' + i + ',\'' + field + '\',this)">+</button></div></td>';
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
    const isBrand = field === 'brand_id';
    const name = prompt(isBrand ? 'ناوی براندی نوێ:' : 'ناوی جۆری نوێ:');
    if (!name || !name.trim()) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', isBrand ? 'quick_brand' : 'quick_category'); fd.set('name', name.trim());
        const d = await postForm(API_P, fd);
        if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
        const list = isBrand ? BRANDS : CATEGORIES;
        if (!list.some(x => Number(x.id) === Number(d.id))) list.push({ id: Number(d.id), name: d.name });
        if (rows.includes(r) && kind(r) === 'new') r[field] = Number(d.id);
        render();
        const index = rows.indexOf(r);
        if (index >= 0) focusCell(index, field);
        msg('');
    } finally { btn.disabled = false; }
}
async function quickSupplier(btn) {
    if (saving || !CAN_CREATE) return;
    clearTimeout(supTimer); U.hideAC();
    const name = prompt('ناوی دابینکەری نوێ:', document.getElementById('supBox').value.trim());
    if (!name || !name.trim()) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', 'save'); fd.set('kind', 'supplier'); fd.set('name', name.trim()); fd.set('is_active', '1');
        const d = await postForm(API_C, fd);
        if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
        document.getElementById('supId').value = d.id;
        document.getElementById('supBox').value = d.name;
        msg(''); focusCell(rows.length - 1, 'name');
    } finally { btn.disabled = false; }
}
async function quickCategory(i, field, btn) {
    const r = rows[i];
    if (saving || !CAN_NEW_PROD || !r || kind(r) !== 'new') return;
    if (field === 'category_name' && !r.class_name.trim()) {
        stopAt(i, 'class_name', 'بۆ دانانی جۆر، پۆل پێویستە'); return;
    }
    U.hideAC();
    const name = prompt(field === 'class_name' ? 'ناوی پۆلی نوێ:' : 'ناوی جۆری نوێ:');
    if (!name || !name.trim()) return;
    btn.disabled = true;
    try {
        const parentId = field === 'category_name' ? await ensureCategory(r.class_name, 0) : 0;
        await ensureCategory(name.trim(), parentId);
        if (rows.includes(r) && kind(r) === 'new') r[field] = name.trim();
        render();
        const index = rows.indexOf(r);
        if (index >= 0) focusCell(index, field);
        msg('');
    } catch (e) { msg(e.message); }
    finally { btn.disabled = false; }
}
function render() {
    ensureBlank();
    let h = '';
    rows.forEach((r, i) => {
        const k = kind(r), isNew = k === 'new';
        h += '<tr class="row-' + k + (i === selRow ? ' sel' : '') + '" onclick="selectRow(' + i + ')">'
           + '<td class="tg-idx">' + (k === 'blank' ? '<i class="fas fa-plus" style="opacity:.35"></i>' : (i + 1)) + '</td>'
           + '<td><input value="' + U.esc(r.name) + '" data-i="' + i + '" data-f="name" autocomplete="off"'
           +   ' placeholder="' + (k === 'blank' ? 'ناو یان بارکۆدی کاڵا بنووسە...' : (isNew ? 'ناوی کاڵای نوێ...' : '')) + '"'
           +   ' oninput="nameIn(this)">'
           +   (isNew ? '<span class="tg-new">نوێ</span>' : '') + '</td>';
        if (k === 'blank') { h += '<td colspan="13"></td></tr>'; return; }
        h += txtCell(i, 'barcode', r.barcode, !isNew)
           + catCell(i, 'class_name', r.class_name, isNew)
           + catCell(i, 'category_name', r.category_name, isNew)
           + classificationCell(i, r, 'brand_id', BRANDS, isNew)
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
function cellSel(el) { rows[Number(el.dataset.i)][el.dataset.f] = Number(el.value); }

/**
 * پۆل / جۆر cell: typing filters the list, a click shows all of it. Picking
 * fills the cell and moves on. A name not in the list is kept as typed and
 * created on save.
 */
function catSuggest(el, all) {
    const i = Number(el.dataset.i), f = el.dataset.f;
    rows[i][f] = el.value;
    el.classList.toggle('tg-newval', catIsNewVal(i, f));
    if (f === 'class_name') {
        const c = cellEl(i, 'category_name');
        if (c) c.classList.toggle('tg-newval', catIsNewVal(i, 'category_name'));
    }
    const q = norm(el.value);
    const opts = catOptions(i, f);
    const hits = (all ? opts : opts.filter(c => norm(c.name).includes(q))).slice(0, 50);
    if ((!all && !q) || !hits.length) { U.hideAC(); return; }
    U.showAC(el, hits.map(c => ({ label: c.name, data: c })), c => {
        rows[i][f] = c.name;
        const cur = cellEl(i, f);
        if (cur) { cur.value = c.name; cur.classList.remove('tg-newval'); }
        if (f === 'class_name') {
            const sub = cellEl(i, 'category_name');
            if (sub) sub.classList.toggle('tg-newval', catIsNewVal(i, 'category_name'));
        }
        nextCell(i, f);
    });
}

// Deferred: erp-common.js closes any open list on document click, and this
// same click is still bubbling towards that handler.
function catClick(el) { setTimeout(() => catSuggest(el, true), 0); }

function nameIn(el) {
    const i = Number(el.dataset.i), r = rows[i];
    r.name = el.value;
    if (r.product_id > 0) {
        // Retyping a picked product's name detaches it: the row must be
        // resolved again, or the invoice would receive the old product.
        Object.assign(r, blankRow(), { name: el.value, quantity: r.quantity });
        render(); selectRow(i); focusCell(i, 'name', true);
    }
    if (r.is_new) return;   // just naming the new product, nothing to look up
    clearTimeout(acTimer);
    if (!el.value.trim()) { U.hideAC(); return; }
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

    const items = res.map(p => ({
        label: p.name,
        meta: 'کۆد ' + p.product_code + (p.barcode ? ' · ' + p.barcode : '')
            + (p.category_name ? ' · ' + (p.category_parent_name ? p.category_parent_name + ' / ' : '') + p.category_name : '')
            + ' · بەردەست ' + U.qf(p.stock) + ' · کڕین ' + U.nf(p.purchase_price),
        data: p
    }));
    if (CAN_NEW_PROD) {
        items.push({ label: '+ کاڵای نوێ: ' + q, meta: 'دروستکردنی کاڵایەکی نوێ لەم ڕیزەدا', data: { isNewSeed: q } });
    }
    if (!items.length) { msg('کاڵا نەدۆزرایەوە: ' + q); return; }
    U.showAC(cur, items, p => (p.isNewSeed !== undefined ? markNew(i, p.isNewSeed) : pick(i, p)));
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
    const k = kind(r), o = ['name'];
    if (k === 'new') o.push('barcode', 'class_name', 'category_name', 'brand_id', 'unit_id');
    o.push('quantity');
    if (CAN_COST || k === 'new') o.push('unit_price');
    if (k === 'new') o.push('sale_price');
    o.push('discount_amount');
    return o;
}
function nextCell(i, f) {
    const o = fieldOrder(rows[i]), at = o.indexOf(f);
    if (at >= 0 && at < o.length - 1) { focusCell(i, o[at + 1]); return; }
    const j = Math.min(i + 1, rows.length - 1);
    selectRow(j); focusCell(j, 'name');
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
    U.showAC(box, s.results.map(p => ({
        label: p.name,
        meta: 'کۆد ' + p.product_code + ' · بەردەست ' + U.qf(p.stock) + ' · کڕین ' + U.nf(p.purchase_price),
        data: p
    })), p => { addRow(p); box.value = ''; });
}

async function openSupplierSearch() {
    const box = document.getElementById('supBox');
    const d = await U.getJson(API_C + '?action=search&kind=supplier&q=' + encodeURIComponent(box.value.trim()));
    if (!d || !d.ok || !d.results.length) { msg('دابینکەر نەدۆزرایەوە'); return; }
    msg('');
    U.showAC(box, d.results.map(x => ({
        label: x.name, meta: (x.phone || '') + ' · ' + x.code, data: x
    })), x => {
        document.getElementById('supId').value = x.id;
        document.getElementById('supBox').value = x.name;
        focusCell(rows.length - 1, 'name');
    });
}

/* ---------- save ---------- */
function stopAt(i, f, t) { msg(t); selectRow(i); focusCell(i, f); }

/**
 * Id of the category with this name under parentId (0 = a پۆل), creating it
 * when it does not exist. Created rows are added to CATS so a second line
 * using the same new name reuses it instead of asking again.
 */
async function ensureCategory(name, parentId) {
    name = String(name || '').trim();
    if (!name) return 0;
    const hit = CATS.find(c => norm(c.name) === norm(name) && c.parent_id === Number(parentId || 0));
    if (hit) return hit.id;
    const fd = new FormData();
    fd.set('action', 'quick_category');
    fd.set('name', name);
    if (parentId) fd.set('parent_id', parentId);
    let d = null;
    try {
        const res = await fetch(API_P, { method: 'POST', body: fd });
        d = await res.json();
    } catch (e) { d = null; }
    if (!d || !d.ok) throw new Error((d && d.error) || 'هەڵە لە دروستکردنی پۆل/جۆر');
    CATS.push({ id: Number(d.id), name: name, parent_id: Number(parentId || 0) });
    return Number(d.id);
}

async function purSave() {
    if (saving) return;
    msg('');

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
            if (String(r.category_name).trim() && !String(r.class_name).trim()) {
                return stopAt(i, 'class_name', n + 'بۆ دانانی جۆر، پۆل پێویستە');
            }
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

            let categoryId = 0;
            try {
                const classId = await ensureCategory(r.class_name, 0);
                const subId   = await ensureCategory(r.category_name, classId);
                categoryId = subId || classId;
            } catch (e) {
                render();
                return stopAt(i, 'class_name', 'ڕیزی ' + (i + 1) + ': ' + e.message);
            }

            const fd = new FormData();
            fd.set('action', 'save');
            fd.set('name', r.name.trim());
            fd.set('barcode', String(r.barcode || '').trim());
            fd.set('category_id', categoryId);
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
        }
        render();
        msg('');

        const lines = filled();
        const sub   = lines.reduce((s, r) => s + lineTotal(r), 0);
        const disc  = Number(document.getElementById('tDisc').value || 0);
        const grand = Math.max(0, sub - disc);
        const paid  = Number(document.getElementById('tPaid').value || 0);

        const d = await U.postJson(API_U + '?action=save', {
            supplier_id: supId,
            warehouse_id: Number(document.getElementById('whBox').value),
            invoice_date: document.getElementById('invDate').value,
            supplier_invoice_no: document.getElementById('supInvNo').value,
            discount_amount: disc,
            paid_amount: paid,
            payment_type: paid <= 0 ? 'credit' : (paid >= grand ? 'cash' : 'partial'),
            items: lines.map(r => ({
                product_id: r.product_id, quantity: r.quantity, unit_price: r.unit_price,
                unit_id: r.unit_id || null,
                discount_amount: r.discount_amount, batch_number: r.batch_number,
                expiry_date: r.expiry_date || null
            }))
        });

        if (!d || !d.ok) { msg((d && d.error) || 'هەڵە لە پاشەکەوتکردن'); return; }
        showToast('success', 'وەسڵی کڕین ' + d.invoice_number + ' پاشەکەوتکرا');
        purReset();
    } finally {
        saving = false;
        if (btn) btn.disabled = false;
    }
}

function purReset() {
    rows = []; selRow = -1;
    document.getElementById('tDisc').value = 0;
    document.getElementById('tPaid').value = 0;
    document.getElementById('supInvNo').value = '';
    msg('');
    render();
    focusCell(rows.length - 1, 'name');
}

/* ---------- keyboard ---------- */
const AC_FIELDS = ['name', 'class_name', 'category_name'];

document.getElementById('tgBody').addEventListener('keydown', e => {
    const el = e.target;
    if (!el.dataset || el.dataset.i === undefined) return;
    const i = Number(el.dataset.i), f = el.dataset.f;

    if (AC_FIELDS.includes(f) && U.acHandleKey(e)) return;

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

    if (f === 'name' && kind(rows[i]) === 'blank') {
        clearTimeout(acTimer);
        if (el.value.trim()) lookup(i, true);
        else if (filled().length) document.getElementById('tPaid').focus();
        return;
    }
    nextCell(i, f);
});
document.getElementById('tgBody').addEventListener('focusout', e => {
    if (e.target.dataset && AC_FIELDS.includes(e.target.dataset.f)) setTimeout(U.hideAC, 150);
});
document.getElementById('tgBody').addEventListener('focusin', e => {
    if (e.target.dataset && e.target.dataset.i !== undefined) selectRow(Number(e.target.dataset.i));
});

document.getElementById('scanBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); onScan(); }
    if (e.key === 'Escape') { e.target.value = ''; msg(''); }
});
document.getElementById('supBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); openSupplierSearch(); }
});
document.getElementById('supBox').addEventListener('input', () => {
    clearTimeout(supTimer);
    document.getElementById('supId').value = 0;
    supTimer = setTimeout(openSupplierSearch, 250);
});
document.getElementById('tPaid').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); purSave(); }
});

document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); document.getElementById('scanBox').focus(); return; }
    if (e.key === 'F4') { e.preventDefault(); document.getElementById('supBox').focus(); openSupplierSearch(); return; }
    if (e.key === 'F7') { e.preventDefault(); selectRow(rows.length - 1); focusCell(rows.length - 1, 'name'); return; }
    if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); purSave(); return; }
    if (e.key === 'Delete' && selRow >= 0 && !/INPUT|SELECT/.test(document.activeElement.tagName)) {
        e.preventDefault(); delRow(selRow);
    }
});

document.getElementById('tDisc').addEventListener('input', totals);
document.getElementById('tPaid').addEventListener('input', totals);

render();
focusCell(rows.length - 1, 'name');
JS;

require_once __DIR__ . '/../includes/footer.php';
