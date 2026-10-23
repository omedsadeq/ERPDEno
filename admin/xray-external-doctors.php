<?php
/**
 * Outside (referring) doctors for X-ray requests — Admin
 * دکتۆرانی دەرەوە بۆ داواکاری ئێکسڕەی
 *
 * These are doctors with no account here who send patients in for imaging
 * only. Staff add them on the fly from the request form (ajax/xray_doctors.php);
 * this page is where typos get fixed and stale entries get retired.
 */

$pageTitle = 'دکتۆرانی دەرەوە';
$breadcrumb = [['title' => 'دکتۆرانی دەرەوە']];

// Admin gate BEFORE any output so the 403 redirect works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$authGate = new Auth();
$authGate->requireAdmin();

require_once __DIR__ . '/../includes/header.php';

$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];
    $docId  = intval($_POST['doctor_id'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        $name = trim((string)($_POST['name'] ?? ''));
        $fields = [
            'name'           => $name,
            'phone'          => trim((string)($_POST['phone'] ?? '')) ?: null,
            'clinic'         => trim((string)($_POST['clinic'] ?? '')) ?: null,
            'specialization' => trim((string)($_POST['specialization'] ?? '')) ?: null,
            'notes'          => trim((string)($_POST['notes'] ?? '')) ?: null,
        ];

        if ($name === '') {
            $formError = 'ناوی دکتۆر پێویستە';
        } else {
            // The name is UNIQUE — catch the clash here so the page explains it
            // instead of surfacing a raw SQL error.
            $clash = $db->fetch(
                "SELECT id FROM xray_external_doctors WHERE name = :name" . ($action === 'edit' ? " AND id <> :id" : ""),
                $action === 'edit' ? ['name' => $name, 'id' => $docId] : ['name' => $name]
            );
            if ($clash) {
                $formError = 'ئەم ناوە پێشتر تۆمارکراوە';
            } elseif ($action === 'add') {
                $fields['is_active'] = 1;
                $fields['created_by'] = $authGate->getCurrentUserId();
                $db->insert('xray_external_doctors', $fields);
                header('Location: ' . APP_URL . '/admin/xray-external-doctors.php?added=1');
                exit;
            } else {
                $db->update('xray_external_doctors', $fields, 'id = :id', ['id' => $docId]);
                header('Location: ' . APP_URL . '/admin/xray-external-doctors.php?updated=1');
                exit;
            }
        }
    } elseif ($action === 'toggle_status') {
        $current = $db->fetch("SELECT is_active FROM xray_external_doctors WHERE id = :id", ['id' => $docId]);
        if ($current) {
            $db->update('xray_external_doctors', ['is_active' => $current['is_active'] ? 0 : 1], 'id = :id', ['id' => $docId]);
        }
        header('Location: ' . APP_URL . '/admin/xray-external-doctors.php');
        exit;
    } elseif ($action === 'delete') {
        // The FK is ON DELETE SET NULL, so deleting a doctor who already has
        // imaging on file would quietly strip the referring name off those
        // requests. Retire them instead — the history has to stay readable.
        $used = $db->fetch(
            "SELECT COUNT(*) AS c FROM xray_requests WHERE external_doctor_id = :id",
            ['id' => $docId]
        );
        if ((int)($used['c'] ?? 0) > 0) {
            header('Location: ' . APP_URL . '/admin/xray-external-doctors.php?inuse=1');
            exit;
        }
        $db->delete('xray_external_doctors', 'id = :id', ['id' => $docId]);
        header('Location: ' . APP_URL . '/admin/xray-external-doctors.php?deleted=1');
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($q !== '') {
    $where = " WHERE (d.name LIKE :q OR COALESCE(d.clinic,'') LIKE :q2
                      OR COALESCE(d.phone,'') LIKE :q3 OR COALESCE(d.specialization,'') LIKE :q4)";
    $like = '%' . $q . '%';
    $params = ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
}

$doctors = $db->fetchAll(
    "SELECT d.*,
            (SELECT COUNT(*) FROM xray_requests xr WHERE xr.external_doctor_id = d.id) AS request_count,
            u.full_name AS created_by_name
     FROM xray_external_doctors d
     LEFT JOIN users u ON u.id = d.created_by
     {$where}
     ORDER BY d.is_active DESC, d.name",
    $params
);
$activeCount = 0;
foreach ($doctors as $d) {
    if ((int)$d['is_active']) { $activeCount++; }
}
?>

<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> دکتۆر زیادکرا</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> زانیاری نوێکرایەوە</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> دکتۆر سڕایەوە</div>
<?php endif; ?>
<?php if (isset($_GET['inuse'])): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle alert-icon"></i>
    ناتوانرێت بسڕدرێتەوە — داواکاری ئێکسڕەی لەسەر ناوی ئەم دکتۆرە هەیە. لەبری ئەوە ناچالاکی بکە.
</div>
<?php endif; ?>
<?php if ($formError !== ''): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle alert-icon"></i> <?= htmlspecialchars($formError) ?></div>
<?php endif; ?>

<div class="page-grid" style="display: grid; grid-template-columns: 1fr 360px; gap: 20px;">
    <div class="panel">
        <div class="panel-header" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <h3 class="panel-title" style="margin: 0;">
                <i class="fas fa-user-md"></i> دکتۆرانی دەرەوە
                <span class="badge badge-blue" style="margin-inline-start: 6px;"><?= $activeCount ?> چالاک</span>
            </h3>
            <form method="GET" style="margin-inline-start: auto; display: flex; gap: 6px;">
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control"
                       placeholder="گەڕان بە ناو، کلینیک، مۆبایل..." style="width: 230px;">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
                <?php if ($q !== ''): ?>
                <a href="<?= APP_URL ?>/admin/xray-external-doctors.php" class="btn btn-sm btn-secondary">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>
        <div class="panel-body" style="padding: 0;">
            <table class="data-grid">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>ناوی دکتۆر</th>
                        <th>کلینیک / پسپۆڕی</th>
                        <th style="width: 120px;">مۆبایل</th>
                        <th style="width: 90px;">ئێکسڕەی</th>
                        <th style="width: 80px;">دۆخ</th>
                        <th style="width: 100px;">کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($doctors)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: #888;">
                            <i class="fas fa-user-slash" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                            <?= $q !== '' ? 'هیچ دکتۆرێک نەدۆزرایەوە' : 'هێشتا هیچ دکتۆری دەرەوە تۆمار نەکراوە' ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($doctors as $doc): ?>
                    <tr<?= (int)$doc['is_active'] ? '' : ' style="opacity:.55;"' ?>>
                        <td><?= (int)$doc['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($doc['name']) ?></strong>
                            <?php if (!empty($doc['created_by_name'])): ?>
                            <small style="display: block; color: #999; font-size: 11px;">
                                زیادکراوە لەلایەن: <?= htmlspecialchars($doc['created_by_name']) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($doc['clinic'] ?? '') ?>
                            <?php if (!empty($doc['specialization'])): ?>
                            <small style="display: block; color: #888; font-size: 11.5px;">
                                <?= htmlspecialchars($doc['specialization']) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td style="direction: ltr; text-align: right;"><?= htmlspecialchars($doc['phone'] ?? '') ?></td>
                        <td style="text-align: center;">
                            <?php if ((int)$doc['request_count'] > 0): ?>
                            <span class="badge badge-blue"><?= (int)$doc['request_count'] ?></span>
                            <?php else: ?>
                            <span style="color: #bbb;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="doctor_id" value="<?= (int)$doc['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $doc['is_active'] ? 'btn-success' : 'btn-secondary' ?>" style="padding: 4px 10px; font-size: 11px;">
                                    <?= $doc['is_active'] ? 'چالاک' : 'ناچالاک' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <button type="button" class="btn btn-sm btn-primary" style="padding: 4px 8px;"
                                        onclick='editDoctor(<?= json_encode([
                                            "id" => (int)$doc["id"],
                                            "name" => $doc["name"],
                                            "phone" => $doc["phone"] ?? "",
                                            "clinic" => $doc["clinic"] ?? "",
                                            "specialization" => $doc["specialization"] ?? "",
                                            "notes" => $doc["notes"] ?? "",
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ((int)$doc['request_count'] === 0): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوە؟')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="doctor_id" value="<?= (int)$doc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 4px 8px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title" id="formTitle"><i class="fas fa-plus-circle"></i> زیادکردنی دکتۆر</h3>
            </div>
            <div class="panel-body">
                <form method="POST" id="doctorForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="doctor_id" id="doctorId">

                    <div class="form-group">
                        <label><i class="fas fa-user-md"></i> ناوی دکتۆر <span style="color: #f44336;">*</span></label>
                        <input type="text" name="name" id="docName" class="form-control" required
                               placeholder="نمونە: د. ئاسۆ ڕەحیم">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> مۆبایل</label>
                        <input type="text" name="phone" id="docPhone" class="form-control" style="direction: ltr;"
                               placeholder="0750 000 0000">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-clinic-medical"></i> کلینیک / شوێن</label>
                        <input type="text" name="clinic" id="docClinic" class="form-control"
                               placeholder="نمونە: کلینیکی ...">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-stethoscope"></i> پسپۆڕی</label>
                        <input type="text" name="specialization" id="docSpec" class="form-control"
                               placeholder="نمونە: جەڕاحی ددان">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> تێبینی</label>
                        <textarea name="notes" id="docNotes" class="form-control" rows="2"></textarea>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 16px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> <span id="submitText">زیادکردن</span>
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetForm()" id="cancelBtn" style="display: none;">
                            <i class="fas fa-times"></i> پاشگەزبوونەوە
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel mt-3" style="border-left: 4px solid #ff9800;">
            <div class="panel-body" style="background: #fff8f0;">
                <h5 style="margin: 0 0 10px 0; color: #b25e09;"><i class="fas fa-info-circle"></i> زانیاری</h5>
                <ul style="margin: 0; padding-right: 20px; font-size: 13px; color: #555; line-height: 1.8;">
                    <li>ئەم دکتۆرانە ئەکاونتیان لێرە نییە — تەنها نەخۆش بۆ ئێکسڕەی دەنێرن</li>
                    <li>هیچ پەیوەندییەکیان بە دکتۆرانی ناوەخۆ نییە</li>
                    <li>ستاف دەتوانن ڕاستەوخۆ لە فۆڕمی داواکاری ئێکسڕەی زیادیان بکەن</li>
                    <li>دکتۆری ناچالاک لە لیستی هەڵبژاردن نیشان نادرێت</li>
                    <li>دکتۆرێک کە داواکاری لەسەر ناوی هەیە ناسڕدرێتەوە — ناچالاکی بکە</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function editDoctor(d) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('doctorId').value = d.id;
    document.getElementById('docName').value = d.name || '';
    document.getElementById('docPhone').value = d.phone || '';
    document.getElementById('docClinic').value = d.clinic || '';
    document.getElementById('docSpec').value = d.specialization || '';
    document.getElementById('docNotes').value = d.notes || '';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> دەستکاریکردن';
    document.getElementById('submitText').textContent = 'نوێکردنەوە';
    document.getElementById('cancelBtn').style.display = 'block';
    document.getElementById('docName').focus();
}

function resetForm() {
    document.getElementById('doctorForm').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('doctorId').value = '';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> زیادکردنی دکتۆر';
    document.getElementById('submitText').textContent = 'زیادکردن';
    document.getElementById('cancelBtn').style.display = 'none';
}
</script>

<style>
.page-grid { max-width: 1250px; }
@media (max-width: 950px) {
    .page-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
