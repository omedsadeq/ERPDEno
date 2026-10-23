<?php
/**
 * X-Ray Pricing Management
 * بەڕێوەبردنی نرخەکانی ئێکسڕەی
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();
if (!$auth->isLoggedIn()) { header('Location: ' . APP_URL . '/login.php'); exit; }
if (!$auth->isAdmin()) { header('Location: ' . APP_URL . '/403.php'); exit; }

$rows = $db->fetchAll("SELECT * FROM xray_pricing WHERE is_active = 1 ORDER BY xray_type, sort_order, pricing_mode");
$byType = [];
foreach ($rows as $r) { $byType[$r['xray_type']][] = $r; }

$typeLabels = [
    'periapical'=>'Periapical','cbct'=>'CBCT (3D)','cephalometric'=>'Cephalometric','panoramic'=>'Panoramic',
];
$typeIcons  = [
    'periapical'=>'fa-tooth','cbct'=>'fa-cube','cephalometric'=>'fa-ruler','panoramic'=>'fa-image',
];
$modeLabels = [
    'full_mouth'=>'هەموو دەم', 'left_side'=>'چەپ', 'right_side'=>'ڕاست',
    'upper_arch'=>'سەرەوە', 'lower_arch'=>'خوارەوە',
    'per_tooth'=>'بۆ هەر ددانێک', 'single'=>'نرخی جۆر (بنەڕەت)',
];

foreach (XRAY_CBCT_OPTIONS as $cbctKey => $cbctOpt) {
    $modeLabels['cbct_' . $cbctKey] = 'CBCT: ' . $cbctOpt['ku'];
}

// CBCT option prices, keyed by option key.
$cbctPrice = [];
foreach ($byType['cbct'] ?? [] as $r) {
    if (strpos($r['pricing_mode'], 'cbct_') === 0) { $cbctPrice[substr($r['pricing_mode'], 5)] = (float)$r['price']; }
}

// The type's own price = its 'single' row. The request form falls back to it
// whenever no more specific mode price applies (api/xray-pricing.php compute).
$typePrice = [];
foreach ($typeLabels as $k => $_) {
    foreach ($byType[$k] ?? [] as $r) {
        if ($r['pricing_mode'] === 'single') { $typePrice[$k] = (float)$r['price']; }
    }
}

$pageTitle  = 'نرخەکانی ئێکسڕەی';
$breadcrumb = [
    ['title' => 'بەڕێوەبردن', 'url' => APP_URL . '/admin/index.php'],
    ['title' => 'نرخەکانی ئێکسڕەی']
];
$currentPage = 'xray-pricing';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.xp-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px; }
.xp-header h3 { margin:0; font-size:20px; color:#0f172a; }
.xp-header .sub { color:#607d8b; font-size:13px; }
.xp-add-btn { padding:8px 16px; background:linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e)); color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.xp-add-btn:hover { background:linear-gradient(135deg, var(--win-accent-dark, #005a9e), #004a85); }
.xp-section { background:#fff; border:1px solid #e3e8ee; border-radius:10px; margin-bottom:14px; overflow:hidden; }
.xp-section-head { padding:10px 14px; background:linear-gradient(90deg, #f8fafc, #fff); border-bottom:1px solid #eef2f5; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; font-size:15px; }
.xp-section-head .count { background:#eef2ff; color:#4338ca; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:700; }
.xp-table { width:100%; border-collapse:collapse; font-size:13px; }
.xp-table th { background:#f7f9fc; color:#37474f; font-weight:600; padding:10px; text-align:center; border-bottom:1px solid #e3e8ee; }
.xp-table td { padding:10px; border-bottom:1px solid #eef2f5; text-align:center; vertical-align:middle; }
.xp-table .mode-name { font-weight:700; color:#0f172a; }
.xp-table .price { color:#16a34a; font-weight:700; font-family:var(--app-font-family); }
.xp-table .actions { display:flex; gap:6px; justify-content:center; }
.xp-btn { padding:5px 10px; border-radius:6px; border:none; cursor:pointer; font-size:11px; display:inline-flex; align-items:center; gap:4px; }
.xp-btn-edit { background:#dbeafe; color:#1e40af; }
.xp-btn-edit:hover { background:#bfdbfe; }
.xp-btn-delete { background:#fee2e2; color:#dc2626; }
.xp-btn-delete:hover { background:#fecaca; }
.empty-type { padding:20px; text-align:center; color:#9aa7b3; font-size:13px; }

.tp-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; padding:14px; }
.tp-card { border:1.5px solid #e3e8ee; border-radius:10px; padding:12px; background:#fafcff; display:flex; flex-direction:column; gap:8px; }
.tp-card.is-unset { border-style:dashed; border-color:#f0b37a; background:#fffaf4; }
.tp-name { font-weight:700; color:#1a237e; display:flex; align-items:center; gap:6px; direction:ltr; justify-content:flex-end; }
.tp-row { display:flex; gap:6px; }
.tp-row input { flex:1; min-width:0; padding:8px 10px; border:1.5px solid #e0e0e0; border-radius:6px; font-size:14px; font-weight:700; direction:ltr; text-align:left; font-family:inherit; }
.tp-row input:focus { border-color:var(--win-accent, #0078d4); outline:none; }
.tp-save { padding:8px 12px; background:#16a34a; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; white-space:nowrap; }
.tp-save:hover { background:#15803d; }
.tp-save:disabled { opacity:.6; cursor:default; }
.tp-status { font-size:11px; color:#94a3b8; min-height:14px; }
.tp-status.ok { color:#16a34a; }
.tp-status.err { color:#dc2626; }
.tp-help { padding:0 14px 12px; color:#607d8b; font-size:12px; }

.cb-table { width:100%; border-collapse:collapse; font-size:13px; }
.cb-table th { background:#f7f9fc; color:#37474f; font-weight:700; padding:10px; text-align:center; border-bottom:1px solid #e3e8ee; }
.cb-table td { padding:10px; border-bottom:1px solid #eef2f5; text-align:center; vertical-align:middle; }
.cb-table .scope { font-weight:700; color:#1a237e; white-space:nowrap; }
.cb-table .scope small { display:block; color:#94a3b8; font-weight:600; direction:ltr; }
.cb-table input { width:100%; max-width:180px; padding:8px 10px; border:1.5px solid #e0e0e0; border-radius:6px; font-size:14px; font-weight:700; direction:ltr; text-align:left; font-family:inherit; }
.cb-table input:focus { border-color:var(--win-accent, #0078d4); outline:none; }
.cb-table input.is-unset { border-style:dashed; border-color:#f0b37a; background:#fffaf4; }
.cb-foot { padding:12px 14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

.xp-dialog-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:9999; padding:16px; }
.xp-dialog-overlay.open { display:flex; }
.xp-dialog { background:#fff; border-radius:12px; max-width:440px; width:100%; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.3); }
.xp-dialog-head { background:linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e)); color:#fff; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; }
.xp-dialog-head h4 { margin:0; font-size:15px; }
.xp-dialog-head .x { background:rgba(255,255,255,.15); border:none; color:#fff; width:28px; height:28px; border-radius:6px; cursor:pointer; }
.xp-dialog-body { padding:18px; }
.xp-field { margin-bottom:12px; }
.xp-field label { display:block; margin-bottom:4px; font-size:12px; font-weight:600; color:#546e7a; }
.xp-field select, .xp-field input { width:100%; padding:8px 10px; border:1.5px solid #e0e0e0; border-radius:6px; font-size:13px; background:#fafafa; font-family:inherit; }
.xp-field input:focus, .xp-field select:focus { border-color:var(--win-accent, #0078d4); outline:none; background:#fff; }
.xp-dialog-foot { padding:12px 18px; background:#f5f7f9; border-top:1px solid #e0e0e0; display:flex; gap:8px; flex-direction:row-reverse; }
.xp-err { color:#dc2626; font-size:12px; margin-top:6px; display:none; }
</style>

<div class="xp-header">
    <div>
        <h3><i class="fas fa-x-ray"></i> نرخەکانی ئێکسڕەی</h3>
        <div class="sub">بەڕێوەبردنی نرخ بۆ هەر جۆرێک و هەر دۆخێک</div>
    </div>
    <button type="button" class="xp-add-btn" onclick="openPriceDialog()"><i class="fas fa-plus"></i> زیادکردنی نرخی نوێ</button>
</div>

<!-- One price per radiographic image type -->
<div class="xp-section">
    <div class="xp-section-head">
        <i class="fas fa-tags" style="color:#16a34a;"></i> نرخی هەر جۆرێک
    </div>
    <div class="tp-grid">
        <?php foreach ($typeLabels as $typeKey => $typeLabel):
            $isSet = array_key_exists($typeKey, $typePrice); ?>
        <div class="tp-card<?= $isSet ? '' : ' is-unset' ?>" data-type-card="<?= $typeKey ?>">
            <div class="tp-name"><?= htmlspecialchars($typeLabel) ?> <i class="fas <?= $typeIcons[$typeKey] ?>"></i></div>
            <div class="tp-row">
                <input type="number" min="0" step="1000" placeholder="0" id="tp-<?= $typeKey ?>"
                       value="<?= $isSet ? (float)$typePrice[$typeKey] : '' ?>"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();saveTypePrice('<?= $typeKey ?>');}">
                <button type="button" class="tp-save" onclick="saveTypePrice('<?= $typeKey ?>')"><i class="fas fa-check"></i> پاشەکەوت</button>
            </div>
            <div class="tp-status" id="tp-status-<?= $typeKey ?>"><?= $isSet ? '' : 'هێشتا نرخ دانەنراوە' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="tp-help">
        <i class="fas fa-info-circle"></i>
        لە فۆرمی داواکاری ئێکسڕەی، کاتێک جۆرێک هەڵدەبژێردرێت ئەم نرخە خۆکارانە دادەنرێت.
        ئەگەر بۆ دۆخێکی دیاریکراو (وەک هەموو دەم، چەپ، یان بۆ هەر ددانێک) نرخی جیات لە خوارەوە داناوە، ئەو نرخە لە جیاتی ئەمە بەکاردێت.
    </div>
</div>

<!-- CBCT (3D) options: scope × with / without report -->
<div class="xp-section">
    <div class="xp-section-head">
        <i class="fas fa-cube" style="color:#7b1fa2;"></i> CBCT (3D) — نرخی بژاردەکان
    </div>
    <div style="overflow-x:auto;">
        <table class="cb-table">
            <thead>
                <tr>
                    <th>بەش</th>
                    <th><i class="fas fa-file-medical"></i> بە ڕاپۆرت <small style="color:#94a3b8;">With Report</small></th>
                    <th><i class="fas fa-file"></i> بێ ڕاپۆرت <small style="color:#94a3b8;">No Report</small></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (XRAY_CBCT_SCOPES as $scopeKey => $scope): ?>
                <tr>
                    <td class="scope"><?= htmlspecialchars($scope['ku']) ?><small><?= htmlspecialchars($scope['en']) ?></small></td>
                    <?php foreach (['report', 'no_report'] as $rep):
                        $optKey = $scopeKey . '_' . $rep;
                        $isSet = array_key_exists($optKey, $cbctPrice); ?>
                    <td>
                        <input type="number" min="0" step="1000" placeholder="0"
                               class="cb-price<?= $isSet ? '' : ' is-unset' ?>" data-cbct-option="<?= $optKey ?>"
                               value="<?= $isSet ? (float)$cbctPrice[$optKey] : '' ?>">
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="cb-foot">
        <button type="button" class="tp-save" id="cbSaveBtn" onclick="saveCbctPrices()"><i class="fas fa-check"></i> پاشەکەوتی نرخەکانی CBCT</button>
        <span class="tp-status" id="cbStatus"></span>
    </div>
    <div class="tp-help">
        <i class="fas fa-info-circle"></i>
        کاتێک لە فۆرمی داواکاری CBCT هەڵدەبژێردرێت، ئەم ٣ بژاردەیە (هەموو دەم / ٢ بەش / ١ بەش — بە ڕاپۆرت یان بێ ڕاپۆرت) دەردەکەون و نرخەکەی خۆکارانە دادەنرێت.
    </div>
</div>

<?php foreach ($typeLabels as $typeKey => $typeLabel):
    $items = $byType[$typeKey] ?? []; ?>
<div class="xp-section">
    <div class="xp-section-head">
        <i class="fas <?= $typeIcons[$typeKey] ?>" style="color:var(--win-accent, #0078d4);"></i>
        <?= htmlspecialchars($typeLabel) ?>
        <span class="count"><?= count($items) ?></span>
    </div>
    <?php if (empty($items)): ?>
    <div class="empty-type">هیچ نرخێک تۆمار نەکراوە بۆ ئەم جۆرە</div>
    <?php else: ?>
    <table class="xp-table">
        <thead>
            <tr>
                <th style="width:50%;">دۆخ</th>
                <th>نرخ</th>
                <th style="width:180px;">کردار</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
            <tr>
                <td class="mode-name"><?= htmlspecialchars($modeLabels[$it['pricing_mode']] ?? $it['pricing_mode']) ?> <small style="color:#94a3b8;">(<?= $it['pricing_mode'] ?>)</small></td>
                <td class="price"><?= number_format($it['price']) ?></td>
                <td>
                    <div class="actions">
                        <button class="xp-btn xp-btn-edit" onclick='editPrice(<?= json_encode($it, JSON_UNESCAPED_UNICODE) ?>)'><i class="fas fa-edit"></i> دەستکاری</button>
                        <button class="xp-btn xp-btn-delete" onclick="deletePrice(<?= $it['id'] ?>)"><i class="fas fa-trash"></i> سڕینەوە</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- Dialog -->
<div class="xp-dialog-overlay" id="xpDialog">
    <div class="xp-dialog">
        <div class="xp-dialog-head">
            <h4 id="xpDialogTitle"><i class="fas fa-plus-circle"></i> زیادکردنی نرخی نوێ</h4>
            <button type="button" class="x" onclick="closePriceDialog()"><i class="fas fa-times"></i></button>
        </div>
        <div class="xp-dialog-body">
            <input type="hidden" id="xpId" value="">
            <div class="xp-field">
                <label><i class="fas fa-x-ray"></i> جۆری ئێکسڕەی</label>
                <select id="xpType">
                    <?php foreach ($typeLabels as $k => $lbl): ?>
                    <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="xp-field">
                <label><i class="fas fa-layer-group"></i> دۆخی بژاردە</label>
                <select id="xpMode">
                    <?php foreach ($modeLabels as $k => $lbl): ?>
                    <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?> <small>(<?= $k ?>)</small></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="xp-field">
                <label><i class="fas fa-coins"></i> نرخ</label>
                <input type="number" id="xpPrice" min="0" step="1000" placeholder="0" style="direction:ltr; text-align:left;">
            </div>
            <div class="xp-err" id="xpErr"></div>
        </div>
        <div class="xp-dialog-foot">
            <button type="button" class="xp-add-btn" onclick="savePrice()"><i class="fas fa-check"></i> پاشەکەوت</button>
            <button type="button" onclick="closePriceDialog()" style="padding:8px 16px; background:#fff; color:#546e7a; border:1.5px solid #e0e0e0; border-radius:8px; font-weight:600; cursor:pointer;">
                <i class="fas fa-times"></i> هەڵوەشاندنەوە
            </button>
        </div>
    </div>
</div>

<script>
const API = '<?= APP_URL ?>/api/xray-pricing.php';

function openPriceDialog() {
    document.getElementById('xpId').value = '';
    document.getElementById('xpType').value = 'periapical';
    document.getElementById('xpMode').value = 'full_mouth';
    document.getElementById('xpPrice').value = '';
    document.getElementById('xpErr').style.display = 'none';
    document.getElementById('xpDialogTitle').innerHTML = '<i class="fas fa-plus-circle"></i> زیادکردنی نرخی نوێ';
    document.getElementById('xpDialog').classList.add('open');
}

function closePriceDialog() {
    document.getElementById('xpDialog').classList.remove('open');
}

function editPrice(row) {
    document.getElementById('xpId').value = row.id;
    document.getElementById('xpType').value = row.xray_type;
    document.getElementById('xpMode').value = row.pricing_mode;
    document.getElementById('xpPrice').value = row.price;
    document.getElementById('xpErr').style.display = 'none';
    document.getElementById('xpDialogTitle').innerHTML = '<i class="fas fa-edit"></i> دەستکاری نرخ';
    document.getElementById('xpDialog').classList.add('open');
}

function savePrice() {
    const id = document.getElementById('xpId').value;
    const xray_type = document.getElementById('xpType').value;
    const pricing_mode = document.getElementById('xpMode').value;
    const price = parseFloat(document.getElementById('xpPrice').value || 0);
    const err = document.getElementById('xpErr');
    err.style.display = 'none';

    if (price < 0) { err.textContent = 'نرخ نابێت بچووکتر بێت لە ٠'; err.style.display = 'block'; return; }

    fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', id, xray_type, pricing_mode, price })
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) { err.textContent = d.message || 'هەڵە'; err.style.display = 'block'; return; }
        window.location.reload();
    })
    .catch(e => { err.textContent = 'کێشەی پەیوەندی: ' + e.message; err.style.display = 'block'; });
}

function saveTypePrice(type) {
    const input = document.getElementById('tp-' + type);
    const status = document.getElementById('tp-status-' + type);
    const btn = input.parentElement.querySelector('.tp-save');
    const price = parseFloat(input.value);
    status.className = 'tp-status';
    if (input.value === '' || isNaN(price) || price < 0) {
        status.textContent = 'نرخێکی دروست بنووسە';
        status.classList.add('err');
        return;
    }
    btn.disabled = true;
    fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', xray_type: type, pricing_mode: 'single', price })
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if (!d.success) { status.textContent = d.message || 'هەڵە'; status.classList.add('err'); return; }
        status.textContent = 'پاشەکەوت کرا ✓';
        status.classList.add('ok');
        document.querySelector('[data-type-card="' + type + '"]').classList.remove('is-unset');
    })
    .catch(e => { btn.disabled = false; status.textContent = 'کێشەی پەیوەندی: ' + e.message; status.classList.add('err'); });
}

async function saveCbctPrices() {
    const btn = document.getElementById('cbSaveBtn');
    const status = document.getElementById('cbStatus');
    status.className = 'tp-status';
    const inputs = Array.from(document.querySelectorAll('.cb-price')).filter(i => i.value !== '');
    if (!inputs.length) { status.textContent = 'هیچ نرخێک نەنووسراوە'; status.classList.add('err'); return; }
    for (const input of inputs) {
        const price = parseFloat(input.value);
        if (isNaN(price) || price < 0) {
            input.focus();
            status.textContent = 'نرخێکی دروست بنووسە'; status.classList.add('err');
            return;
        }
    }
    btn.disabled = true;
    try {
        for (const input of inputs) {
            const r = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save', xray_type: 'cbct', pricing_mode: 'cbct_' + input.dataset.cbctOption, price: parseFloat(input.value) })
            });
            const d = await r.json();
            if (!d.success) throw new Error(d.message || 'هەڵە');
            input.classList.remove('is-unset');
        }
        status.textContent = 'پاشەکەوت کرا ✓';
        status.classList.add('ok');
    } catch (e) {
        status.textContent = e.message;
        status.classList.add('err');
    } finally {
        btn.disabled = false;
    }
}

function deletePrice(id) {
    if (!confirm('ئایا دڵنیایت لە سڕینەوەی ئەم نرخە؟')) return;
    fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id })
    })
    .then(r => r.json())
    .then(d => { if (d.success) window.location.reload(); else alert(d.message || 'هەڵە'); });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
