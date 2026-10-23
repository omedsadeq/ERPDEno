<?php
/**
 * ERP — warehouse management.
 *
 * Small on purpose: a clinic has a handful of warehouses, so this loads them
 * all in one query with their stock totals rather than paging. Every stock
 * movement in the ERP carries a warehouse_id, so this is the list that every
 * other screen's picker is built from.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.products.view');

$pageTitle  = 'کۆگاکان';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'کۆگاکان'],
];

$canEdit = erp2_can('erp2.settings');
$canCost = erp2_can('erp2.profit.view');
$db      = Database::getInstance();
$managers = $db->fetchAll(
    "SELECT id, full_name FROM users WHERE is_active = 1 AND role IN ('admin','receptionist','accountant') ORDER BY full_name"
);

if ($canEdit) {
    $pageActions = '<button class="btn btn-primary" onclick="whNew()"><i class="fas fa-plus"></i> کۆگای نوێ</button>';
}

$customCSS = <<<CSS
.wh-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.wh-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:14px;position:relative}
.wh-card.inactive{opacity:.55}
.wh-card .code{font-size:11px;color:#888;direction:ltr;letter-spacing:.5px}
.wh-card .name{font-size:16px;font-weight:700;margin:2px 0 6px}
.wh-card .loc{font-size:12px;color:#777;margin-bottom:8px}
.wh-card .stats{display:flex;gap:14px;border-top:1px solid #f0f0f0;padding-top:8px;font-size:12px}
.wh-card .stats div{flex:1}
.wh-card .stats .v{font-size:16px;font-weight:700;direction:ltr;text-align:left;font-variant-numeric:tabular-nums}
.wh-card .stats .l{color:#888;font-size:11px}
.wh-badge{position:absolute;top:10px;left:10px;background:#0f766e;color:#fff;font-size:10.5px;padding:2px 8px;border-radius:10px}
.wh-edit{position:absolute;bottom:12px;left:12px;cursor:pointer;color:#0078D4}
.erp-formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="wh-cards" id="whCards">
    <div class="erp-empty" style="padding:30px;color:#999"><i class="fas fa-spinner fa-spin"></i> بارکردن...</div>
</div>

<div class="dialog-overlay" id="whDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h3 class="dialog-title" id="whDialogTitle">کۆگای نوێ</h3>
            <button class="dialog-close" onclick="closeDialog('whDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <form id="whForm" onsubmit="return false">
                <input type="hidden" name="id" id="wf_id">
                <div class="erp-formgrid">
                    <div class="form-group">
                        <label class="form-label required">کۆد</label>
                        <input class="form-control" name="code" id="wf_code" dir="ltr" required
                               placeholder="MAIN" style="text-transform:uppercase">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">ناو</label>
                        <input class="form-control" name="name" id="wf_name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">شوێن</label>
                        <input class="form-control" name="location" id="wf_location">
                    </div>
                    <div class="form-group">
                        <label class="form-label">بەڕێوەبەر</label>
                        <select class="form-control" name="manager_id" id="wf_manager">
                            <option value="0">—</option>
                            <?php foreach ($managers as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"><?= erp2_esc($m['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label class="form-check"><input type="checkbox" name="is_active" id="wf_active" value="1" checked> چالاک</label>
                <label class="form-check"><input type="checkbox" name="is_default" id="wf_default" value="1"> کۆگای بنەڕەت</label>
                <div class="form-hint" style="font-size:11.5px;color:#888">
                    کۆگای بنەڕەت ئەوەیە کە پەڕەکانی فرۆشتن و کڕین پێی دەکرێنەوە
                </div>
                <div id="wf_error" class="form-error" style="display:none;margin-top:8px"></div>
            </form>
        </div>
        <div class="dialog-footer">
            <button class="btn btn-secondary" onclick="closeDialog('whDialog')">پاشگەزبوونەوە</button>
            <button class="btn btn-primary" id="wf_save" onclick="whSave()">پاشەکەوتکردن</button>
        </div>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiW    = APP_URL . '/erp2/api/warehouse.php';
$jsEdit  = $canEdit ? 'true' : 'false';
$jsCost  = $canCost ? 'true' : 'false';

$customJS = <<<JS
const API_W = '{$apiW}';
const CAN_EDIT = {$jsEdit}, CAN_COST = {$jsCost};
const U = window.ErpUI;
let rows = [];

async function load() {
    const d = await U.getJson(API_W + '?action=list');
    if (!d || !d.ok) {
        document.getElementById('whCards').innerHTML = '<div style="padding:24px;color:#c62828">هەڵە لە بارکردن</div>';
        return;
    }
    rows = d.results || [];
    render();
}

function render() {
    if (!rows.length) {
        document.getElementById('whCards').innerHTML = '<div style="padding:24px;color:#999">هیچ کۆگایەک نییە</div>';
        return;
    }
    document.getElementById('whCards').innerHTML = rows.map(w =>
        '<div class="wh-card' + (Number(w.is_active) ? '' : ' inactive') + '">'
        + (Number(w.is_default) ? '<span class="wh-badge">بنەڕەت</span>' : '')
        + '<div class="code">' + U.esc(w.code) + '</div>'
        + '<div class="name">' + U.esc(w.name) + '</div>'
        + '<div class="loc"><i class="fas fa-location-dot"></i> ' + U.esc(w.location || '—')
        + (w.manager_name ? ' · <i class="fas fa-user"></i> ' + U.esc(w.manager_name) : '') + '</div>'
        + '<div class="stats">'
        +   '<div><div class="v">' + U.nf(w.product_lines) + '</div><div class="l">جۆری کاڵا</div></div>'
        +   '<div><div class="v">' + U.qf(w.total_qty) + '</div><div class="l">کۆی بڕ</div></div>'
        +   (CAN_COST ? '<div><div class="v">' + U.nf(w.total_value) + '</div><div class="l">نرخ</div></div>' : '')
        + '</div>'
        + (CAN_EDIT ? '<span class="wh-edit" onclick="whEdit(' + Number(w.id) + ')"><i class="fas fa-pen"></i></span>' : '')
        + '</div>').join('');
}

function whNew() {
    document.getElementById('whForm').reset();
    document.getElementById('wf_id').value = '';
    document.getElementById('wf_active').checked = true;
    document.getElementById('wf_default').checked = false;
    document.getElementById('wf_error').style.display = 'none';
    document.getElementById('whDialogTitle').textContent = 'کۆگای نوێ';
    openDialog('whDialog');
    setTimeout(() => document.getElementById('wf_code').focus(), 60);
}

function whEdit(id) {
    const w = rows.find(x => Number(x.id) === Number(id));
    if (!w) return;
    document.getElementById('wf_id').value       = w.id;
    document.getElementById('wf_code').value     = w.code || '';
    document.getElementById('wf_name').value     = w.name || '';
    document.getElementById('wf_location').value = w.location || '';
    document.getElementById('wf_manager').value  = w.manager_id || '0';
    document.getElementById('wf_active').checked  = Number(w.is_active) === 1;
    document.getElementById('wf_default').checked = Number(w.is_default) === 1;
    document.getElementById('wf_error').style.display = 'none';
    document.getElementById('whDialogTitle').textContent = 'دەستکاری کۆگا';
    openDialog('whDialog');
}

async function whSave() {
    const btn = document.getElementById('wf_save');
    const err = document.getElementById('wf_error');
    btn.disabled = true;
    try {
        const fd = new FormData(document.getElementById('whForm'));
        fd.set('action', 'save_warehouse');
        if (!document.getElementById('wf_active').checked)  fd.delete('is_active');
        if (!document.getElementById('wf_default').checked) fd.delete('is_default');
        const res  = await fetch(API_W, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { err.textContent = data.error || 'هەڵە'; err.style.display = 'block'; return; }
        closeDialog('whDialog');
        showToast('success', 'پاشەکەوتکرا');
        load();
    } catch (e) {
        err.textContent = 'کێشەی پەیوەندی ڕوویدا';
        err.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}

load();
JS;

require_once __DIR__ . '/../includes/footer.php';
