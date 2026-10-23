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
require_once __DIR__ . '/../classes/Erp2UserWarehouse.php';
require_once __DIR__ . '/../classes/Csrf.php';

erp2_require('erp2.settings');

$pageTitle  = 'ڕێکخستنەکانی کۆگا';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'ڕێکخستنەکان'],
];

$db = Database::getInstance();

// All warehouses, not erp2_warehouses(): an admin configuring the shop cashier
// must see every warehouse, including ones they are not themselves assigned.
$allWarehouses = $db->fetchAll(
    "SELECT id, code, name FROM erp2_warehouses WHERE is_active = 1 ORDER BY is_default DESC, name"
);

$staff = $db->fetchAll(
    "SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY role, full_name"
);

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user_warehouses') {
    Csrf::require();
    $userId = (int)($_POST['user_id'] ?? 0);
    $ids    = array_map('intval', (array)($_POST['warehouse_ids'] ?? []));
    if ($userId > 0) {
        Erp2UserWarehouse::save($userId, $ids, (int)($currentUser['id'] ?? 0));
        $flash = $ids
            ? 'بەندکرا بە ' . count($ids) . ' کۆگا'
            : 'بەندەکە لابرا — ئێستا هەموو کۆگاکان بەکاردێنێت';
    }
    header('Location: ' . APP_URL . '/erp2/settings.php?saved=1');
    exit;
}

$assignments = Erp2UserWarehouse::assignments();
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

    <div class="set-card">
        <h4><i class="fas fa-warehouse"></i> بەکارهێنەر و کۆگا</h4>
        <div class="sub">
            بەکارهێنەرێک کە بە کۆگایەکەوە بەند کرا، تەنها لەو کۆگایانەدا دەتوانێت بفرۆشێت،
            بکڕێت و بگوێزێتەوە — پەڕەکان تەنها ئەوان پیشان دەدەن و سێرڤەریش ڕێگری دەکات.
            <br><b>هیچ هەڵنەبژێریت = هەموو کۆگاکان</b> (دۆخی بنەڕەتی هەموو هەژمارەکان).
            بەڕێوەبەر هەرگیز بەند ناکرێت.
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= erp2_esc(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save_user_warehouses">

            <label class="form-label">بەکارهێنەر</label>
            <select class="form-control" name="user_id" id="userSel" onchange="loadAssign()">
                <option value="0">— هەڵبژێرە —</option>
                <?php foreach ($staff as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                    <?= erp2_esc($u['full_name']) ?> — <?= erp2_esc($roleLabels[$u['role']] ?? $u['role']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <div class="wh-picks" id="whPicks">
                <?php foreach ($allWarehouses as $w): ?>
                <label>
                    <input type="checkbox" name="warehouse_ids[]" value="<?= (int)$w['id'] ?>">
                    <span><?= erp2_esc($w['name']) ?></span>
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
                    <td><?= erp2_esc($a['full_name']) ?>
                        <span class="muted">· <?= erp2_esc($roleLabels[$a['role']] ?? $a['role']) ?></span></td>
                    <td><span class="lock-tag"><?= erp2_esc($a['warehouse_names']) ?></span></td>
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
$apiC        = APP_URL . '/erp2/api/parties.php';
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
foreach ($db->fetchAll("SELECT user_id, warehouse_id FROM erp2_user_warehouses") as $r) {
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
                ? '<a class="btn btn-sm btn-secondary" href="' + APP + '/erp2/statement.php?kind=customer&id='
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
