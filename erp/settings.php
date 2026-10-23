<?php
/**
 * ERP — settings: who works in which warehouse, and staff as customers.
 *
 * Two jobs, both about keeping the shop and the main store apart:
 *
 *   1. Assign a user to warehouses. A shop cashier assigned to the shop can
 *      no longer pick the main store — the dropdown does not offer it and the
 *      server refuses it. Assigning nothing leaves a user unrestricted, which
 *      is the state every account starts in.
 *
 *   2. Turn a doctor into an ERP customer in one click, so selling materials
 *      to them is a normal sale with an invoice, a balance and a statement.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpUserWarehouse.php';
require_once __DIR__ . '/../classes/Csrf.php';

erp_require('erp.settings');

$pageTitle  = 'ڕێکخستنەکانی کۆگا';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'ڕێکخستنەکان'],
];

$db = Database::getInstance();

// All warehouses, not erp_warehouses(): an admin configuring the shop cashier
// must see every warehouse, including ones they are not themselves assigned.
$allWarehouses = $db->fetchAll(
    "SELECT id, code, name FROM erp_warehouses WHERE is_active = 1 ORDER BY is_default DESC, name"
);

$staff = $db->fetchAll(
    "SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY role, full_name"
);

require_once __DIR__ . '/../classes/ErpDoctorIssue.php';

// مەواد بۆ دکتۆر: the one saved choice of which price a doctor is charged.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_doctor_issue_price') {
    Csrf::require();
    ErpDoctorIssue::setPriceMode((string)($_POST['price_mode'] ?? ''));
    header('Location: ' . APP_URL . '/erp/settings.php?saved=1');
    exit;
}
$doctorIssuePrice = ErpDoctorIssue::priceMode();

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user_warehouses') {
    Csrf::require();
    $userId = (int)($_POST['user_id'] ?? 0);
    $ids    = array_map('intval', (array)($_POST['warehouse_ids'] ?? []));
    if ($userId > 0) {
        ErpUserWarehouse::save($userId, $ids, (int)($currentUser['id'] ?? 0));
        $flash = $ids
            ? 'بەندکرا بە ' . count($ids) . ' کۆگا'
            : 'بەندەکە لابرا — ئێستا هەموو کۆگاکان بەکاردێنێت';
    }
    header('Location: ' . APP_URL . '/erp/settings.php?saved=1');
    exit;
}

$assignments = ErpUserWarehouse::assignments();
$assignedMap = [];
foreach ($assignments as $a) {
    $assignedMap[(int)$a['user_id']] = $a['warehouse_names'];
}

$roleLabels = [
    'admin' => 'بەڕێوەبەر', 'doctor' => 'دکتۆر', 'receptionist' => 'پێشوازی',
    'accountant' => 'ژمێریار', 'xray_department' => 'ئێکسڕەی',
];

$customCSS = <<<CSS
.set-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.set-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:14px}
.set-card h4{margin:0 0 4px;font-size:15px}
.set-card .sub{font-size:11.5px;color:#888;margin-bottom:12px;line-height:1.7}
table.st{width:100%;border-collapse:collapse;font-size:12.5px}
table.st th{background:#eef2f5;padding:6px 8px;text-align:right;font-size:11.5px;border-bottom:2px solid #c8d3da}
table.st td{border-bottom:1px solid #f2f2f2;padding:6px 8px}
.muted{color:#9e9e9e}
.open-tag{display:inline-block;background:#e3f2fd;color:#1565c0;border-radius:10px;padding:1px 8px;font-size:10.5px}
.lock-tag{display:inline-block;background:#fff3e0;color:#ef6c00;border-radius:10px;padding:1px 8px;font-size:10.5px}
.wh-picks{display:flex;flex-wrap:wrap;gap:10px;margin:8px 0}
.wh-picks label{display:flex;align-items:center;gap:5px;font-size:12.5px;background:#f5f7f9;
                border:1px solid #e0e0e0;border-radius:6px;padding:5px 10px;cursor:pointer}
.wh-picks input:checked + span{font-weight:700;color:#0f766e}
.price-mode-form{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap}
.price-mode-opt input{position:absolute;opacity:0;pointer-events:none}
.price-mode-opt span{display:flex;flex-direction:column;gap:2px;min-width:200px;padding:10px 14px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer}
.price-mode-opt span i{color:#94a3b8}
.price-mode-opt span b{font-size:14px}
.price-mode-opt span small{font-size:11px;color:#94a3b8}
.price-mode-opt input:checked + span{border-color:#0369a1;background:#f0f9ff}
.price-mode-opt input:checked + span i,.price-mode-opt input:checked + span b{color:#0369a1}
.price-mode-opt input:focus-visible + span{outline:2px solid #0369a1;outline-offset:2px}
.price-mode-form .btn{align-self:center}
@media(max-width:900px){.set-grid{grid-template-columns:1fr}}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" data-auto-hide>
    <i class="fas fa-check-circle alert-icon"></i>
    <div class="alert-content">پاشەکەوتکرا</div>
</div>
<?php endif; ?>

<div class="set-grid">

    <div class="set-card" style="grid-column: 1 / -1;">
        <h4><i class="fas fa-hand-holding-medical"></i> نرخی مەواد بۆ دکتۆر</h4>
        <div class="sub">
            کاتێک مەواد دەدرێت بە دکتۆر، بەم نرخە حساب دەکرێت و هەمان بڕ دەچێتە خەرجی کۆگا لەسەر ناوی دکتۆر.
            مەوادەکە هەمیشە بە تێچووی ڕاستەقینەی خۆی لە کۆگا دەردەچێت، بۆیە نرخی کۆگا ڕاست دەمێنێتەوە.
            ئەم ڕێکخستنە یەکجار پاشەکەوت دەکرێت و بۆ هەموو وەسڵە نوێیەکان کار دەکات.
        </div>
        <form method="POST" class="price-mode-form">
            <input type="hidden" name="csrf_token" value="<?= erp_esc(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save_doctor_issue_price">
            <label class="price-mode-opt">
                <input type="radio" name="price_mode" value="sale" <?= $doctorIssuePrice === 'sale' ? 'checked' : '' ?>>
                <span><i class="fas fa-tag"></i><b>نرخی فرۆشتن</b><small>نرخی فرۆشتنی تۆمارکراوی هەر مەوادێک</small></span>
            </label>
            <label class="price-mode-opt">
                <input type="radio" name="price_mode" value="cost" <?= $doctorIssuePrice === 'cost' ? 'checked' : '' ?>>
                <span><i class="fas fa-truck"></i><b>نرخی کڕین</b><small>تێچووی کڕینی مەوادەکە لە کۆگا</small></span>
            </label>
            <button class="btn btn-primary btn-sm"><i class="fas fa-save"></i> پاشەکەوت</button>
        </form>
    </div>

    <div class="set-card">
        <h4><i class="fas fa-warehouse"></i> بەکارهێنەر و کۆگا</h4>
        <div class="sub">
            بەکارهێنەرێک کە بە کۆگایەکەوە بەند کرا، تەنها لەو کۆگایانەدا دەتوانێت بفرۆشێت،
            بکڕێت و بگوێزێتەوە — پەڕەکان تەنها ئەوان پیشان دەدەن و سێرڤەریش ڕێگری دەکات.
            <br><b>هیچ هەڵنەبژێریت = هەموو کۆگاکان</b> (دۆخی بنەڕەتی هەموو هەژمارەکان).
            بەڕێوەبەر هەرگیز بەند ناکرێت.
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= erp_esc(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save_user_warehouses">

            <label class="form-label">بەکارهێنەر</label>
            <select class="form-control" name="user_id" id="userSel" onchange="loadAssign()">
                <option value="0">— هەڵبژێرە —</option>
                <?php foreach ($staff as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                    <?= erp_esc($u['full_name']) ?> — <?= erp_esc($roleLabels[$u['role']] ?? $u['role']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <div class="wh-picks" id="whPicks">
                <?php foreach ($allWarehouses as $w): ?>
                <label>
                    <input type="checkbox" name="warehouse_ids[]" value="<?= (int)$w['id'] ?>">
                    <span><?= erp_esc($w['name']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <button class="btn btn-primary btn-sm"><i class="fas fa-save"></i> پاشەکەوت</button>
        </form>

        <h4 style="margin-top:18px;font-size:13px">بەندکراوەکان</h4>
        <table class="st">
            <thead><tr><th>بەکارهێنەر</th><th>کۆگاکان</th></tr></thead>
            <tbody>
            <?php foreach ($assignments as $a): ?>
                <tr>
                    <td><?= erp_esc($a['full_name']) ?>
                        <span class="muted">· <?= erp_esc($roleLabels[$a['role']] ?? $a['role']) ?></span></td>
                    <td><span class="lock-tag"><?= erp_esc($a['warehouse_names']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$assignments): ?>
                <tr><td colspan="2" class="muted" style="padding:14px">
                    هیچ بەکارهێنەرێک بەند نەکراوە — هەموویان هەموو کۆگاکان بەکاردێنن
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="set-card">
        <h4><i class="fas fa-user-doctor"></i> دکتۆرەکان وەک کڕیار</h4>
        <div class="sub">
            بۆ ئەوەی مەواد بە دکتۆرێک بفرۆشیت، پێویستە وەک کڕیار هەبێت.
            بە کلیکێک دروست دەبێت — دواتر وەسڵی بۆ دەدرێت، قەرزی لەسەر دەمێنێتەوە
            و کشف حسابی تایبەتی هەیە.
        </div>
        <table class="st">
            <thead><tr><th>ناو</th><th style="width:110px">دۆخ</th><th style="width:130px"></th></tr></thead>
            <tbody id="staffRows">
                <tr><td colspan="3" class="muted" style="padding:14px">بارکردن...</td></tr>
            </tbody>
        </table>
    </div>

</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiC        = APP_URL . '/erp/api/parties.php';
$appUrl      = APP_URL;
$jsAssigned  = json_encode(
    array_map(
        fn($a) => ['user_id' => (int)$a['user_id']],
        $assignments
    ),
    JSON_UNESCAPED_UNICODE
);
// Current assignment per user, so ticking the boxes reflects reality.
$rawMap = [];
foreach ($db->fetchAll("SELECT user_id, warehouse_id FROM erp_user_warehouses") as $r) {
    $rawMap[(int)$r['user_id']][] = (int)$r['warehouse_id'];
}
$jsMap = json_encode($rawMap, JSON_UNESCAPED_UNICODE);

$customJS = <<<JS
const API_C = '{$apiC}', APP = '{$appUrl}';
const ASSIGN_MAP = {$jsMap};
const U = window.ErpUI;

/** Tick the boxes for whoever is selected, so the form shows current state. */
function loadAssign() {
    const uid = Number(document.getElementById('userSel').value || 0);
    const mine = ASSIGN_MAP[uid] || [];
    document.querySelectorAll('#whPicks input[type=checkbox]').forEach(cb => {
        cb.checked = mine.indexOf(Number(cb.value)) !== -1;
    });
}

async function loadStaff() {
    const d = await U.getJson(API_C + '?action=staff&kind=customer');
    const b = document.getElementById('staffRows');
    if (!d || !d.ok || !d.results.length) {
        b.innerHTML = '<tr><td colspan="3" class="muted" style="padding:14px">هیچ نییە</td></tr>';
        return;
    }
    b.innerHTML = d.results.map(s => {
        const linked = Number(s.customer_id) > 0;
        return '<tr>'
          + '<td>' + U.esc(s.full_name) + '</td>'
          + '<td>' + (linked
                ? '<span class="open-tag">کڕیارە</span>'
                : '<span class="muted">نا</span>') + '</td>'
          + '<td>' + (linked
                ? '<a class="btn btn-sm btn-secondary" href="' + APP + '/erp/statement.php?kind=customer&id='
                  + Number(s.customer_id) + '">کشف حساب</a>'
                : '<button class="btn btn-sm btn-primary" onclick="linkStaff(' + Number(s.user_id) + ')">'
                  + 'بیکە بە کڕیار</button>') + '</td>'
          + '</tr>';
    }).join('');
}

async function linkStaff(userId) {
    const fd = new FormData();
    fd.set('action', 'link_staff');
    fd.set('kind', 'customer');
    fd.set('user_id', userId);
    try {
        const res = await fetch(API_C, { method: 'POST', body: fd });
        const d = await res.json();
        if (!d.ok) { showToast('error', d.error || 'هەڵە'); return; }
        showToast('success', d.existed ? 'پێشتر کڕیار بووە' : 'کڕیار دروستکرا: ' + d.name);
        loadStaff();
    } catch (e) {
        showToast('error', 'کێشەی پەیوەندی ڕوویدا');
    }
}

loadStaff();
JS;

require_once __DIR__ . '/../includes/footer.php';
