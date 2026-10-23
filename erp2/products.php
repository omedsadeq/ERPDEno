<?php
/**
 * ERP — product master.
 *
 * Deliberately loads NO products server-side. The first render paints an empty
 * grid and the rows arrive by AJAX, so opening this page costs the same
 * whether the catalogue holds 50 products or 100,000. Search, filtering and
 * paging are all server-side (erp/api/products.php).
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.products.view');

$pageTitle  = 'کاڵاکان';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'کاڵاکان'],
];

$db         = Database::getInstance();
// Groups and their types in the order of the clinic's prescription sheet.
$categories = $db->fetchAll("SELECT id, name FROM erp2_categories WHERE is_active = 1 ORDER BY sort_order, name");
$types      = $db->fetchAll("SELECT id, category_id, name FROM erp2_product_types WHERE is_active = 1 ORDER BY sort_order, name");
$units      = $db->fetchAll("SELECT id, code, name FROM erp2_units WHERE is_active = 1 ORDER BY id");
$brands     = $db->fetchAll("SELECT id, name FROM erp2_brands WHERE is_active = 1 ORDER BY name");
$canCreate  = erp2_can('erp2.products.create');
$canEdit    = erp2_can('erp2.products.edit');
$canDelete  = erp2_can('erp2.products.delete');
$canCost    = erp2_can('erp2.profit.view');

if ($canCreate) {
    $pageActions = '<button class="btn btn-primary" onclick="erpNewProduct()">'
                 . '<i class="fas fa-plus"></i> کاڵای نوێ</button>';
}

$customCSS = <<<CSS
.erp-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.erp-toolbar .form-control{height:34px;padding:4px 10px;font-size:13px}
.erp-search{flex:1;min-width:200px;max-width:340px}
.erp-grid{font-size:13px}
.erp-grid td,.erp-grid th{padding:6px 10px}
.erp-num{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.erp-low{color:#d32f2f;font-weight:600}
.erp-muted{color:#9e9e9e}
.erp-pager{display:flex;gap:6px;align-items:center;justify-content:center;margin-top:12px;flex-wrap:wrap}
.erp-pager button{min-width:34px;height:30px;border:1px solid #d0d0d0;background:#fff;border-radius:6px;cursor:pointer;font-size:13px}
.erp-pager button[disabled]{opacity:.45;cursor:default}
.erp-pager button.current{background:var(--win-accent, #0078D4);color:#fff;border-color:var(--win-accent, #0078D4)}
.erp-inactive td{opacity:.55}
.erp-formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
.erp-empty{text-align:center;padding:34px;color:#8a8a8a}
.units-box{margin-top:14px;border:1px solid #e0e0e0;border-radius:8px;padding:10px;background:#fafafa}
.units-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px;font-weight:700;color:#37474f}
table.units-t{width:100%;border-collapse:collapse;font-size:12.5px;background:#fff}
table.units-t th{background:#eef2f5;padding:5px 7px;text-align:right;font-size:11.5px;border-bottom:1px solid #cfd8dc}
table.units-t td{border-bottom:1px solid #f0f0f0;padding:2px}
table.units-t input,table.units-t select{width:100%;border:1px solid transparent;background:transparent;padding:5px 6px;font-size:12.5px;font-family:inherit;border-radius:4px}
table.units-t input:focus,table.units-t select:focus{background:#fff8dc;border-color:var(--win-accent, #0078D4);outline:none}
.u-n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums}
.u-del{text-align:center;color:#c62828;cursor:pointer}
.u-base td{background:#eef7ee}
.u-base input{color:#2e7d32;font-weight:600}
.erp-tag{display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:999px;background:#e0f2fe;color:#0369a1;white-space:nowrap}
.erp-type-sel{width:100%;min-width:90px;height:28px;padding:2px 6px;font-size:12px;font-family:inherit;border:1px solid #cfd8dc;border-radius:5px;background:#fff;color:#0369a1;font-weight:600;cursor:pointer;transition:border-color .2s,background .2s}
.erp-type-sel:focus{outline:none;border-color:var(--win-accent, #0078D4)}
.erp-type-sel.saving{opacity:.6;cursor:wait}
.erp-type-sel.saved{border-color:#2e7d32;background:#e8f5e9}
.erp-type-sel.failed{border-color:#d32f2f;background:#fdecea}
.erp-type-cell{display:flex;align-items:center;gap:4px}
.erp-type-cell .erp-type-sel{flex:1;min-width:0}
.erp-type-add{flex:0 0 26px;height:28px;border:1px solid #c8d3da;border-radius:5px;background:#eef6ff;color:#1565c0;font-size:18px;line-height:1;cursor:pointer;padding:0}
.erp-type-add:disabled{opacity:.5;cursor:wait}
.erp-tag.grp{background:#eef2f5;color:#37474f}
/* group / type picker — laid out like the header of the paper sheet */
.gt{border:1px solid #b9d9ec;border-radius:10px;overflow:hidden;margin-bottom:14px}
.gt-groups{display:flex;flex-wrap:wrap;background:#bfe0f2}
.gt-g{flex:1 1 0;min-width:84px;border:0;border-inline-end:1px solid #a5cde4;background:transparent;padding:10px 6px;font-family:inherit;font-size:15px;font-weight:800;color:#0f2a3a;cursor:pointer;transition:background .15s;white-space:nowrap}
.gt-g:last-child{border-inline-end:0}
.gt-g:hover{background:#d6ecf8}
.gt-g.on{background:#0369a1;color:#fff}
.gt-g.add{flex:0 0 auto;min-width:0;padding:10px 14px;font-size:13px;color:#0369a1}
.gt-types{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;padding:10px 12px;background:#e6f3fa;min-height:44px}
.gt-types .lbl{font-size:12px;font-weight:700;color:#37474f;margin-inline-end:4px}
.gt-t{display:inline-flex;align-items:center;gap:6px;border:0;background:transparent;font-family:inherit;font-size:14px;font-weight:600;color:#0f2a3a;cursor:pointer;padding:3px 2px}
.gt-t::before{content:"";width:15px;height:15px;border-radius:50%;border:2px solid #2e7d32;background:#fff;box-sizing:border-box}
.gt-t.on::before{background:#2e7d32;box-shadow:inset 0 0 0 2.5px #fff}
.gt-t.add{color:#0369a1;font-size:12.5px}
.gt-t.add::before{display:none}
.gt-none{font-size:12px;color:#78909c}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-body">
        <div class="erp-toolbar">
            <input type="search" id="erpSearch" class="form-control erp-search"
                   placeholder="گەڕان بە ناو، کۆد یان بارکۆد..." autocomplete="off">

            <select id="erpCategory" class="form-control" style="max-width:170px">
                <option value="0">هەموو گرووپەکان</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= erp2_esc($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="erpType" class="form-control" style="max-width:150px" hidden>
                <option value="0">هەموو جۆرەکان</option>
            </select>

            <select id="erpStatus" class="form-control" style="max-width:150px">
                <option value="active">چالاک</option>
                <option value="inactive">ناچالاک</option>
                <option value="all">هەموو</option>
            </select>

            <label class="form-check" style="margin:0;white-space:nowrap">
                <input type="checkbox" id="erpLowStock"> کەمترین بڕ
            </label>

            <span id="erpCount" class="erp-muted" style="margin-inline-start:auto;font-size:12px"></span>
        </div>

        <div class="table-container">
            <table class="data-grid erp-grid no-responsive">
                <thead>
                    <tr>
                        <th style="width:110px">کۆد</th>
                        <th>ناو</th>
                        <th style="width:110px">گرووپ</th>
                        <th style="width:150px">جۆر</th>
                        <th style="width:100px">براند</th>
                        <th style="width:90px" class="erp-num">بڕ</th>
                        <?php if ($canCost): ?><th style="width:100px" class="erp-num">نرخی کڕین</th><?php endif; ?>
                        <th style="width:100px" class="erp-num">نرخی فرۆشتن</th>
                        <th style="width:96px"></th>
                    </tr>
                </thead>
                <tbody id="erpRows">
                    <tr><td colspan="<?= $canCost ? 9 : 8 ?>" class="erp-empty">
                        <i class="fas fa-spinner fa-spin"></i> بارکردن...
                    </td></tr>
                </tbody>
            </table>
        </div>

        <div class="erp-pager" id="erpPager"></div>
    </div>
</div>

<div class="dialog-overlay" id="erpProductDialog">
    <div class="dialog dialog-lg">
        <div class="dialog-header">
            <h3 class="dialog-title" id="erpDialogTitle">کاڵای نوێ</h3>
            <button class="dialog-close" onclick="closeDialog('erpProductDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <form id="erpProductForm" onsubmit="return false">
                <input type="hidden" name="id" id="pf_id">
                <input type="hidden" name="category_id" id="pf_category" value="0">
                <input type="hidden" name="type_id" id="pf_type" value="0">
                <!-- Group, then the group's type — the header row of the paper sheet. -->
                <div class="gt" role="group" aria-label="گرووپ و جۆر">
                    <div class="gt-groups" id="gtGroups"></div>
                    <div class="gt-types" id="gtTypes"></div>
                </div>
                <div class="erp-formgrid">
                    <div class="form-group">
                        <label class="form-label required">ناوی کاڵا</label>
                        <input class="form-control" name="name" id="pf_name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ناوی عەرەبی</label>
                        <input class="form-control" name="name_ar" id="pf_name_ar" dir="rtl">
                    </div>
                    <div class="form-group">
                        <label class="form-label">بارکۆد</label>
                        <input class="form-control" name="barcode" id="pf_barcode" dir="ltr" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">کۆدی کاڵا</label>
                        <input class="form-control" name="product_code" id="pf_code" dir="ltr"
                               placeholder="خۆکار">
                    </div>
                    <div class="form-group">
                        <label class="form-label">یەکەی بنەڕەت</label>
                        <select class="form-control" name="unit_id" id="pf_unit" onchange="unitAuto = false; unitsRender()">
                            <option value="0">—</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= erp2_esc($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint" style="font-size:11px">کۆگا بەم یەکەیە هەژمار دەکرێت</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">براند</label>
                        <div style="display:flex;gap:6px">
                            <select class="form-control" name="brand_id" id="pf_brand">
                                <option value="0">—</option>
                                <?php foreach ($brands as $b): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= erp2_esc($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($canCreate): ?>
                            <button type="button" class="btn btn-secondary btn-icon"
                                    title="براندی نوێ" onclick="erpQuickBrand()"><i class="fas fa-plus"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">نرخی کڕین</label>
                        <input class="form-control erp-num" type="number" step="0.01" min="0"
                               name="purchase_price" id="pf_purchase" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">نرخی فرۆشتن</label>
                        <input class="form-control erp-num" type="number" step="0.01" min="0"
                               name="sale_price" id="pf_sale" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">کەمترین نرخی فرۆشتن</label>
                        <input class="form-control erp-num" type="number" step="0.01" min="0"
                               name="min_sale_price" id="pf_minsale" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">نرخی کۆمەڵ</label>
                        <input class="form-control erp-num" type="number" step="0.01" min="0"
                               name="wholesale_price" id="pf_wholesale" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">کەمترین بڕ</label>
                        <input class="form-control erp-num" type="number" step="0.001" min="0"
                               name="min_stock" id="pf_minstock" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">زۆرترین بڕ</label>
                        <input class="form-control erp-num" type="number" step="0.001" min="0"
                               name="max_stock" id="pf_maxstock" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">بەرواری بەسەرچوون</label>
                        <input class="form-control" type="date" name="expiry_date" id="pf_expiry">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ژمارەی بەچ</label>
                        <input class="form-control" name="batch_number" id="pf_batch" dir="ltr">
                    </div>
                </div>
                <div class="units-box">
                    <div class="units-head">
                        <span><i class="fas fa-layer-group"></i> یەکەکانی فرۆشتن</span>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="unitAdd()">
                            <i class="fas fa-plus"></i> یەکە زیاد بکە
                        </button>
                    </div>
                    <table class="units-t">
                        <thead>
                            <tr>
                                <th>یەکە</th>
                                <th style="width:110px">فاکتەر</th>
                                <th style="width:120px">نرخی فرۆشتن</th>
                                <th style="width:130px">بارکۆد</th>
                                <th style="width:70px">سەرەکی</th>
                                <th style="width:34px"></th>
                            </tr>
                        </thead>
                        <tbody id="unitsBody"></tbody>
                    </table>
                    <div class="form-hint" style="font-size:11px;margin-top:6px">
                        فاکتەر = ئەم یەکەیە چەند یەکەی بنەڕەتی تێدایە. نموونە: کارتۆن = ١٠ دانە ← فاکتەر ١٠.
                        <br>«سەرەکی» ئەو یەکەیەیە کە پەڕەی فرۆشتن پێی دەکرێتەوە.
                    </div>
                </div>

                <label class="form-check" style="margin-top:6px">
                    <input type="checkbox" name="is_active" id="pf_active" value="1" checked> چالاک
                </label>
                <div id="pf_error" class="form-error" style="display:none;margin-top:8px"></div>
            </form>
        </div>
        <div class="dialog-footer">
            <button class="btn btn-secondary" onclick="closeDialog('erpProductDialog')">پاشگەزبوونەوە</button>
            <button class="btn btn-primary" id="pf_save" onclick="erpSaveProduct()">پاشەکەوتکردن</button>
        </div>
    </div>
</div>

<?php
$apiUrl    = APP_URL . '/erp2/api/products.php';
$colspan   = $canCost ? 9 : 8;
$jsCanCreate = $canCreate ? 'true' : 'false';
$jsJson = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$jsGroups = json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $categories), $jsJson);
$jsTypes  = json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'cat' => (int)$t['category_id'], 'name' => $t['name']], $types), $jsJson);
$jsUnitIds = json_encode(array_column(array_map(fn($u) => [$u['code'], (int)$u['id']], $units), 1, 0));
$jsCanEdit = $canEdit ? 'true' : 'false';
$jsCanDel  = $canDelete ? 'true' : 'false';
$jsCanCost = $canCost ? 'true' : 'false';

/*
 * Built here rather than inside the heredoc below, because a short-echo tag is
 * not evaluated inside a heredoc and a bare $units there would interpolate as
 * a PHP variable.
 *
 * NOTE: do not write a closing PHP tag inside a comment here. PHP's lexer ends
 * the PHP block on that sequence even in a // comment, which silently dumps
 * the rest of this file — the whole $customJS heredoc included — to the
 * browser as plain text. It stays valid PHP, so php -l reports nothing.
 */
$jsUnitOptions = json_encode(
    array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['name']], $units),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$customJS = <<<JS
const erp2_API   = '{$apiUrl}';
const erp2_COLS  = {$colspan};
const CAN_EDIT  = {$jsCanEdit};
const CAN_DEL   = {$jsCanDel};
const CAN_COST  = {$jsCanCost};
const CAN_CREATE = {$jsCanCreate};
const GROUPS = {$jsGroups};
const TYPES  = {$jsTypes};
const UNIT_IDS = {$jsUnitIds};
let erpPage = 1, erpTimer = null, erpReqId = 0;

const nf = n => Number(n || 0).toLocaleString('en-US');
const qf = n => {
    const v = Number(n || 0);
    return Number.isInteger(v) ? v.toLocaleString('en-US') : v.toFixed(3).replace(/0+\$/, '').replace(/\.\$/, '');
};
const esc = s => String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');

function erpParams() {
    const p = new URLSearchParams({ action: 'list', page: erpPage });
    const q = document.getElementById('erpSearch').value.trim();
    if (q) p.set('q', q);
    const c = document.getElementById('erpCategory').value;
    if (c && c !== '0') p.set('category_id', c);
    const t = document.getElementById('erpType').value;
    if (c && c !== '0' && t && t !== '0') p.set('type_id', t);
    p.set('status', document.getElementById('erpStatus').value);
    if (document.getElementById('erpLowStock').checked) p.set('low_stock', '1');
    return p;
}

async function erpLoad() {
    // Ignore a response that arrives after a newer request was already sent,
    // otherwise fast typing can leave stale rows on screen.
    const myReq = ++erpReqId;
    try {
        const res = await fetch(erp2_API + '?' + erpParams().toString());
        const data = await res.json();
        if (myReq !== erpReqId) return;
        if (!data.ok) { erpRowsMessage(data.error || 'هەڵە'); return; }
        erpRender(data);
    } catch (e) {
        if (myReq === erpReqId) erpRowsMessage('کێشەی پەیوەندی ڕوویدا');
    }
}

function erpRowsMessage(msg) {
    document.getElementById('erpRows').innerHTML =
        '<tr><td colspan="' + erp2_COLS + '" class="erp-empty">' + esc(msg) + '</td></tr>';
    document.getElementById('erpPager').innerHTML = '';
}

function erpRender(data) {
    const rows = data.results || [];
    document.getElementById('erpCount').textContent = data.total ? (nf(data.total) + ' کاڵا') : '';
    if (!rows.length) { erpRowsMessage('هیچ کاڵایەک نەدۆزرایەوە'); return; }

    let html = '';
    for (const r of rows) {
        const low = Number(r.min_stock) > 0 && Number(r.stock) <= Number(r.min_stock);
        html += '<tr class="' + (Number(r.is_active) ? '' : 'erp-inactive') + '">'
            + '<td dir="ltr" class="erp-num">' + esc(r.product_code) + '</td>'
            + '<td>' + esc(r.name) + (r.name_ar ? ' <span class="erp-muted">/ ' + esc(r.name_ar) + '</span>' : '') + '</td>'
            + '<td>' + (r.category_name ? '<span class="erp-tag grp">' + esc(r.category_name) + '</span>' : '<span class="erp-muted">—</span>') + '</td>'
            + '<td>' + erpTypeCell(r) + '</td>'
            + '<td>' + (r.brand_name ? esc(r.brand_name) : '<span class="erp-muted">—</span>') + '</td>'
            + '<td class="erp-num ' + (low ? 'erp-low' : '') + '">' + qf(r.stock)
            + (r.unit_name ? ' <span class="erp-muted">' + esc(r.unit_name) + '</span>' : '') + '</td>'
            + (CAN_COST ? '<td class="erp-num">' + nf(r.purchase_price) + '</td>' : '')
            + '<td class="erp-num">' + nf(r.sale_price) + '</td>'
            + '<td style="white-space:nowrap">'
            + (CAN_EDIT ? '<button class="btn btn-icon btn-sm" title="دەستکاری" onclick="erpEdit(' + Number(r.id) + ')"><i class="fas fa-pen"></i></button>' : '')
            + (CAN_DEL ? '<button class="btn btn-icon btn-sm" title="چالاک/ناچالاک" onclick="erpToggle(' + Number(r.id) + ')"><i class="fas fa-power-off"></i></button>' : '')
            + '</td></tr>';
    }
    document.getElementById('erpRows').innerHTML = html;
    erpPager(data.page, data.totalPages);
}

function erpPager(page, totalPages) {
    const el = document.getElementById('erpPager');
    if (!totalPages || totalPages < 2) { el.innerHTML = ''; return; }
    let h = '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="erpGo(' + (page - 1) + ')">‹</button>';
    const from = Math.max(1, page - 2), to = Math.min(totalPages, page + 2);
    if (from > 1) h += '<button onclick="erpGo(1)">1</button><span class="erp-muted">…</span>';
    for (let i = from; i <= to; i++) {
        h += '<button class="' + (i === page ? 'current' : '') + '" onclick="erpGo(' + i + ')">' + i + '</button>';
    }
    if (to < totalPages) h += '<span class="erp-muted">…</span><button onclick="erpGo(' + totalPages + ')">' + totalPages + '</button>';
    h += '<button ' + (page >= totalPages ? 'disabled' : '') + ' onclick="erpGo(' + (page + 1) + ')">›</button>';
    el.innerHTML = h;
}

function erpGo(p) { erpPage = p; erpLoad(); }

function erpDebounced() {
    clearTimeout(erpTimer);
    erpTimer = setTimeout(() => { erpPage = 1; erpLoad(); }, 250);
}

/* ---------- alternate units ---------- */
// Model only; the table below is a view of it. Never read quantities back out
// of the DOM, or a half-typed factor becomes the saved value.
let unitRows = [];

const UNIT_OPTIONS = {$jsUnitOptions};

function baseUnitId() { return Number(document.getElementById('pf_unit').value || 0); }
function baseUnitName() {
    const s = document.getElementById('pf_unit');
    return s.selectedIndex >= 0 ? s.options[s.selectedIndex].text : '—';
}

function unitAdd() {
    unitRows.push({ unit_id: 0, factor: '', sale_price: '', barcode: '', is_default: 0 });
    unitsRender();
}
function unitDel(i) { unitRows.splice(i, 1); unitsRender(); }

function unitSet(i, field, value) {
    if (field === 'is_default') {
        // Exactly one main unit: selecting one clears the rest, mirroring the
        // server rule so the screen can never show two.
        unitRows.forEach((r, k) => { r.is_default = (k === i && value) ? 1 : 0; });
        unitsRender();
        return;
    }
    unitRows[i][field] = value;
    if (field === 'factor') {
        const cell = document.getElementById('uconv' + i);
        if (cell) cell.textContent = unitHint(unitRows[i]);
    }
}

function unitHint(r) {
    const f = Number(r.factor);
    if (!f || f <= 0) return '';
    return '= ' + U_FMT(f) + ' ' + baseUnitName();
}
const U_FMT = n => Number(n || 0).toLocaleString('en-US');

function unitsRender() {
    const b = document.getElementById('unitsBody');
    const baseName = baseUnitName();
    const basePrice = document.getElementById('pf_sale').value || 0;
    const anyDefault = unitRows.some(r => Number(r.is_default) === 1);

    // The base unit is always present and always factor 1. It is rendered as a
    // fixed row rather than an editable one so it cannot be deleted or given a
    // factor, which would corrupt every stored quantity.
    let html = '<tr class="u-base">'
        + '<td><input value="' + esc(baseName) + ' (بنەڕەت)" readonly></td>'
        + '<td><input class="u-n" value="1" readonly></td>'
        + '<td><input class="u-n" value="' + U_FMT(basePrice) + '" readonly></td>'
        + '<td><input value="بارکۆدی سەرەکی" readonly></td>'
        + '<td style="text-align:center"><input type="radio" name="udef" '
        +   (anyDefault ? '' : 'checked') + ' onclick="unitClearDefault()"></td>'
        + '<td></td></tr>';

    unitRows.forEach((r, i) => {
        html += '<tr>'
          + '<td><select onchange="unitSet(' + i + ',\'unit_id\',this.value)">'
          +   '<option value="0">—</option>'
          +   UNIT_OPTIONS.map(o => '<option value="' + o.id + '"'
          +       (Number(r.unit_id) === o.id ? ' selected' : '') + '>' + esc(o.name) + '</option>').join('')
          + '</select></td>'
          + '<td><input class="u-n" type="number" min="0" step="any" value="' + esc(r.factor) + '" '
          +   'placeholder="10" oninput="unitSet(' + i + ',\'factor\',this.value)">'
          +   '<div id="uconv' + i + '" style="font-size:10px;color:#888;padding:0 6px">' + unitHint(r) + '</div></td>'
          + '<td><input class="u-n" type="number" min="0" step="any" value="' + esc(r.sale_price) + '" '
          +   'placeholder="خۆکار" oninput="unitSet(' + i + ',\'sale_price\',this.value)"></td>'
          + '<td><input dir="ltr" value="' + esc(r.barcode) + '" placeholder="ئارەزوومەندانە" '
          +   'oninput="unitSet(' + i + ',\'barcode\',this.value)"></td>'
          + '<td style="text-align:center"><input type="radio" name="udef" '
          +   (Number(r.is_default) === 1 ? 'checked' : '') + ' onclick="unitSet(' + i + ',\'is_default\',true)"></td>'
          + '<td class="u-del" onclick="unitDel(' + i + ')"><i class="fas fa-times"></i></td></tr>';
    });

    b.innerHTML = html;
}

function unitClearDefault() {
    unitRows.forEach(r => { r.is_default = 0; });
    unitsRender();
}

/* ---------- group / type picker (the header row of the paper sheet) ---------- */
// The base unit follows the chosen type until the operator picks one by hand.
let unitAuto = true;
const TYPE_UNIT  = { 'Tab': 'pack', 'Capsule': 'pack', 'Sachet': 'pack', 'Powder': 'pack',
                     'Syrup': 'bottle', 'Vial': 'pcs', 'Ampule': 'pcs' };
const GROUP_UNIT = { 'Mouth Wash': 'bottle', 'Toothpaste': 'pcs', 'Brush': 'pcs', 'Other': 'pcs' };

function gtGroup() { return Number(document.getElementById('pf_category').value || 0); }
function gtType()  { return Number(document.getElementById('pf_type').value || 0); }

function gtRender() {
    const g = gtGroup(), t = gtType();
    document.getElementById('gtGroups').innerHTML = GROUPS.map(x =>
        '<button type="button" class="gt-g' + (x.id === g ? ' on' : '') + '" onclick="gtSetGroup(' + x.id + ')">'
        + esc(x.name) + '</button>'
    ).join('') + (CAN_CREATE ? '<button type="button" class="gt-g add" title="گرووپی نوێ" aria-label="گرووپی نوێ" onclick="erpQuickCategory()"><i class="fas fa-plus"></i></button>' : '');
    const box = document.getElementById('gtTypes');
    if (!g) { box.innerHTML = '<span class="gt-none">سەرەتا گرووپێک هەڵبژێرە</span>'; return; }
    const list = TYPES.filter(x => x.cat === g);
    box.innerHTML = '<span class="lbl">جۆر:</span>'
        + (list.length
            ? list.map(x => '<button type="button" class="gt-t' + (x.id === t ? ' on' : '') + '" onclick="gtSetType(' + x.id + ')">'
                + esc(x.name) + '</button>').join('')
            : '<span class="gt-none">ئەم گرووپە جۆری نییە</span>')
        + (CAN_CREATE ? '<button type="button" class="gt-t add" onclick="erpQuickType()"><i class="fas fa-plus"></i> جۆری نوێ</button>' : '');
}
function gtSetGroup(id) {
    if (gtGroup() !== id) document.getElementById('pf_type').value = 0;
    document.getElementById('pf_category').value = id;
    gtAutoUnit();
    gtRender();
}
function gtSetType(id) {
    // Clicking the chosen type again clears it.
    document.getElementById('pf_type').value = gtType() === id ? 0 : id;
    gtAutoUnit();
    gtRender();
}
function gtAutoUnit() {
    if (!unitAuto) return;
    const g = GROUPS.find(x => x.id === gtGroup()), t = TYPES.find(x => x.id === gtType());
    const code = (t && TYPE_UNIT[t.name]) || (g && GROUP_UNIT[g.name]) || '';
    if (code && UNIT_IDS[code]) {
        document.getElementById('pf_unit').value = UNIT_IDS[code];
        unitsRender();
    }
}

/** The toolbar's type filter lists only the chosen group's types. */
function erpFillTypeFilter() {
    const c = Number(document.getElementById('erpCategory').value || 0);
    const sel = document.getElementById('erpType');
    const keep = sel.value;
    const list = TYPES.filter(x => x.cat === c);
    sel.innerHTML = '<option value="0">هەموو جۆرەکان</option>'
        + list.map(x => '<option value="' + x.id + '">' + esc(x.name) + '</option>').join('');
    if (list.some(x => String(x.id) === keep)) sel.value = keep;
    sel.hidden = !list.length;
}

async function erpQuickType() {
    const g = gtGroup();
    if (!g) return;
    const name = prompt('ناوی جۆری نوێ (نموونە: Drops):');
    if (!name || !name.trim()) return;
    const fd = new FormData();
    fd.set('action', 'quick_type');
    fd.set('category_id', g);
    fd.set('name', name.trim());
    const res  = await fetch(erp2_API, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { showToast('error', data.error || 'هەڵە'); return; }
    if (!TYPES.some(x => x.id === Number(data.id))) TYPES.push({ id: Number(data.id), cat: g, name: data.name });
    document.getElementById('pf_type').value = data.id;
    gtAutoUnit();
    gtRender();
    erpFillTypeFilter();
}

function erpNewProduct() {
    document.getElementById('erpProductForm').reset();
    // A new item starts in the group (and type) the list is filtered to.
    document.getElementById('pf_category').value = document.getElementById('erpCategory').value || 0;
    document.getElementById('pf_type').value = document.getElementById('erpType').hidden ? 0 : (document.getElementById('erpType').value || 0);
    unitAuto = true;
    gtAutoUnit();
    gtRender();
    document.getElementById('pf_id').value = '';
    document.getElementById('pf_active').checked = true;
    document.getElementById('pf_error').style.display = 'none';
    document.getElementById('erpDialogTitle').textContent = 'کاڵای نوێ';
    unitRows = [];
    unitsRender();
    openDialog('erpProductDialog');
    setTimeout(() => document.getElementById('pf_name').focus(), 60);
}

/** Load the full record, including its unit table, rather than the list row. */
async function erpEdit(id) {
    const res  = await fetch(erp2_API + '?action=detail&id=' + Number(id));
    const data = await res.json();
    if (!data.ok) { showToast('error', data.error || 'کاڵا نەدۆزرایەوە'); return; }
    const row = data.product;

    document.getElementById('pf_id').value        = row.id;
    document.getElementById('pf_name').value      = row.name || '';
    document.getElementById('pf_name_ar').value   = row.name_ar || '';
    document.getElementById('pf_name_ku') && (document.getElementById('pf_name_ku').value = row.name_ku || '');
    document.getElementById('pf_barcode').value   = row.barcode || '';
    document.getElementById('pf_code').value      = row.product_code || '';
    document.getElementById('pf_category').value  = row.category_id || '0';
    document.getElementById('pf_type').value      = row.type_id || '0';
    unitAuto = false;   // an existing item keeps its unit
    gtRender();
    document.getElementById('pf_brand').value     = row.brand_id || '0';
    document.getElementById('pf_unit').value      = row.unit_id || '0';
    document.getElementById('pf_purchase').value  = row.purchase_price || 0;
    document.getElementById('pf_sale').value      = row.sale_price || 0;
    document.getElementById('pf_minsale').value   = row.min_sale_price || 0;
    document.getElementById('pf_wholesale').value = row.wholesale_price || 0;
    document.getElementById('pf_minstock').value  = row.min_stock || 0;
    document.getElementById('pf_maxstock').value  = row.max_stock || 0;
    document.getElementById('pf_expiry').value    = row.expiry_date || '';
    document.getElementById('pf_batch').value     = row.batch_number || '';
    document.getElementById('pf_active').checked  = Number(row.is_active) === 1;
    document.getElementById('pf_error').style.display = 'none';

    unitRows = (data.units || []).map(u => ({
        unit_id: Number(u.unit_id),
        factor: u.factor,
        sale_price: u.sale_price === null ? '' : u.sale_price,
        barcode: u.barcode || '',
        is_default: Number(u.is_default)
    }));
    unitsRender();

    document.getElementById('erpDialogTitle').textContent = 'دەستکاری کاڵا';
    openDialog('erpProductDialog');
}

async function erpSaveProduct() {
    const btn = document.getElementById('pf_save');
    const err = document.getElementById('pf_error');
    btn.disabled = true;
    try {
        const fd = new FormData(document.getElementById('erpProductForm'));
        fd.set('action', 'save');
        if (!document.getElementById('pf_active').checked) fd.delete('is_active');

        // Drop blank rows the operator added but never filled in, so an empty
        // row cannot be saved as a zero-factor unit.
        const usable = unitRows.filter(r => Number(r.unit_id) > 0 && Number(r.factor) > 0);
        const dupe = usable.map(r => Number(r.unit_id))
                           .find((v, i, a) => a.indexOf(v) !== i);
        if (dupe) { err.textContent = 'یەکەیەک دووبارە بووەتەوە'; err.style.display = 'block'; return; }
        if (usable.some(r => Math.abs(Number(r.factor) - 1) < 0.00005)) {
            err.textContent = 'یەکەی جێگرەوە ناتوانێت فاکتەری ١ بێت';
            err.style.display = 'block';
            return;
        }
        fd.set('units_json', JSON.stringify(usable));
        const res  = await fetch(erp2_API, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
            err.textContent = data.error || 'هەڵە';
            err.style.display = 'block';
            return;
        }
        closeDialog('erpProductDialog');
        showToast('success', 'پاشەکەوتکرا');
        erpLoad();
    } catch (e) {
        err.textContent = 'کێشەی پەیوەندی ڕوویدا';
        err.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}

/** The type cell: a picker of the product's group types when editable, else a tag. */
function erpTypeCell(r) {
    const tag = r.type_name ? '<span class="erp-tag">' + esc(r.type_name) + '</span>' : '<span class="erp-muted">—</span>';
    const cat = Number(r.category_id) || 0;
    const list = TYPES.filter(x => x.cat === cat);
    if (!CAN_EDIT || !cat || (!list.length && !CAN_CREATE)) return tag;
    const cur = Number(r.type_id) || 0;
    let opts = '<option value="0">—</option>';
    // an inactive type is not in TYPES; keep it visible so the cell tells the truth
    if (cur && !list.some(x => x.id === cur)) opts += '<option value="' + cur + '" selected>' + esc(r.type_name) + '</option>';
    opts += list.map(x => '<option value="' + x.id + '"' + (x.id === cur ? ' selected' : '') + '>' + esc(x.name) + '</option>').join('');
    const add = CAN_CREATE
        ? '<button type="button" class="erp-type-add" title="جۆری نوێ" aria-label="جۆری نوێ" onclick="erpRowNewType(' + Number(r.id) + ', ' + cat + ', this)">+</button>'
        : '';
    return '<div class="erp-type-cell"><select class="erp-type-sel" data-cat="' + cat + '" data-prev="' + cur + '" onchange="erpSetType(' + Number(r.id) + ', this)">' + opts + '</select>' + add + '</div>';
}

/** «+» on a row: create a type in the product's group and give it to the product. */
async function erpRowNewType(id, cat, btn) {
    const name = prompt('ناوی جۆری نوێ (نموونە: Drops):');
    if (!name || !name.trim()) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', 'quick_type');
        fd.set('category_id', cat);
        fd.set('name', name.trim());
        const data = await (await fetch(erp2_API, { method: 'POST', body: fd })).json();
        if (!data.ok) { showToast('error', data.error || 'هەڵە'); return; }
        if (!TYPES.some(x => x.id === Number(data.id))) TYPES.push({ id: Number(data.id), cat: cat, name: data.name });
        erpFillTypeFilter();
        const sel = btn.parentElement.querySelector('select');
        if (!sel.querySelector('option[value="' + Number(data.id) + '"]')) {
            const o = document.createElement('option');
            o.value = data.id; o.textContent = data.name;
            sel.appendChild(o);
        }
        sel.value = data.id;
        await erpSetType(id, sel);
        // other rows of the same group get the new type in their pickers too
        document.querySelectorAll('.erp-type-sel[data-cat="' + cat + '"]').forEach(s => {
            if (s !== sel && !s.querySelector('option[value="' + Number(data.id) + '"]')) {
                const o = document.createElement('option');
                o.value = data.id; o.textContent = data.name;
                s.appendChild(o);
            }
        });
    } catch (e) {
        showToast('error', 'کێشەی پەیوەندی ڕوویدا');
    } finally {
        btn.disabled = false;
    }
}

async function erpSetType(id, sel) {
    const prev = sel.dataset.prev;
    sel.classList.remove('saved', 'failed');
    sel.classList.add('saving');
    sel.disabled = true;
    let data;
    try {
        const fd = new FormData();
        fd.set('action', 'set_type');
        fd.set('id', id);
        fd.set('type_id', sel.value);
        const res = await fetch(erp2_API, { method: 'POST', body: fd });
        data = await res.json();
    } catch (e) {
        data = { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' };
    }
    sel.disabled = false;
    sel.classList.remove('saving');
    if (data && data.ok) {
        sel.dataset.prev = sel.value;
        sel.classList.add('saved');
        setTimeout(() => sel.classList.remove('saved'), 1200);
    } else {
        sel.value = prev;
        sel.classList.add('failed');
        setTimeout(() => sel.classList.remove('failed'), 1500);
        showToast('error', (data && data.error) || 'هەڵە');
    }
}

async function erpToggle(id) {
    const fd = new FormData();
    fd.set('action', 'toggle_active');
    fd.set('id', id);
    const res  = await fetch(erp2_API, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) { erpLoad(); } else { showToast('error', data.error || 'هەڵە'); }
}

async function erpQuickBrand() {
    const name = prompt('ناوی براندی نوێ:');
    if (!name || !name.trim()) return;
    const fd = new FormData();
    fd.set('action', 'quick_brand');
    fd.set('name', name.trim());
    const res  = await fetch(erp2_API, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { showToast('error', data.error || 'هەڵە'); return; }
    const sel = document.getElementById('pf_brand');
    if (!sel.querySelector('option[value="' + data.id + '"]')) {
        const o = document.createElement('option');
        o.value = data.id; o.textContent = data.name;
        sel.appendChild(o);
    }
    sel.value = data.id;
}

async function erpQuickCategory() {
    const name = prompt('ناوی گرووپی نوێ:');
    if (!name || !name.trim()) return;
    const fd = new FormData();
    fd.set('action', 'quick_category');
    fd.set('name', name.trim());
    const res  = await fetch(erp2_API, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { showToast('error', data.error || 'هەڵە'); return; }
    if (!GROUPS.some(x => x.id === Number(data.id))) GROUPS.push({ id: Number(data.id), name: data.name });
    const sel = document.getElementById('erpCategory');
    if (!sel.querySelector('option[value="' + data.id + '"]')) {
        const o = document.createElement('option');
        o.value = data.id; o.textContent = data.name;
        sel.appendChild(o);
    }
    gtSetGroup(Number(data.id));
}

document.getElementById('erpSearch').addEventListener('input', erpDebounced);
document.getElementById('erpCategory').addEventListener('change', () => { erpFillTypeFilter(); erpPage = 1; erpLoad(); });
document.getElementById('erpType').addEventListener('change', () => { erpPage = 1; erpLoad(); });
document.getElementById('erpStatus').addEventListener('change', () => { erpPage = 1; erpLoad(); });
document.getElementById('erpLowStock').addEventListener('change', () => { erpPage = 1; erpLoad(); });
erpLoad();
JS;

require_once __DIR__ . '/../includes/footer.php';
