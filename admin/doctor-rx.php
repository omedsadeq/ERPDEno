<?php
/**
 * ڕەچەتەی دکتۆر — edit one doctor's prescription sheet (the left half of
 * erp2/prescription-print.php), with the sheet itself as a live preview.
 *
 * Opened from admin/rx-templates.php (the doctors' list) or admin/users.php
 * (the «ڕەچەتە» button on a doctor's row). «گەڕاندنەوە بۆ بنەڕەت» removes the
 * doctor's own fields and picture: the sheet is the clinic template again.
 * Two kinds of panel: built from the fields below (title, name, credentials,
 * degrees, phones, address, two QR links), or a ready-made picture uploaded
 * as it is. Empty fields take the clinic's own (settings → clinic).
 *
 *   ?id=           the doctor (users.id)
 *   POST           save (CSRF)
 *   POST preview=1 the sheet with these (unsaved) values and a sample
 *                  patient — the preview frame's content
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Csrf.php';
require_once __DIR__ . '/../classes/RxProfile.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
// a doctor, or an admin who writes prescriptions (RxProfile::ROLES)
$doctor = $db->fetch(
    "SELECT id, full_name, phone, is_active, role FROM users WHERE id = :id AND role IN ('" . implode("','", RxProfile::ROLES) . "')",
    ['id' => $id]
);
if (!$doctor) {
    header('Location: ' . APP_URL . '/admin/rx-templates.php');
    exit;
}

/* ── the preview: the real sheet, these values, a sample patient ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['preview'])) {
    Csrf::require();
    require_once __DIR__ . '/../includes/rx_sheet.php';
    $row = ['user_id' => $id] + array_intersect_key($_POST, RxProfile::FIELDS)
         + ['panel_mode' => $_POST['panel_mode'] ?? 'fields', 'panel_image' => RxProfile::stored($id)['panel_image'] ?? ''];
    $p = RxProfile::resolve($row, $doctor);
    // a picture chosen but not saved yet: the page shows it (data-local), so
    // render the picture panel even before the file is on the server
    if (($_POST['panel_mode'] ?? '') === 'image' && !empty($_POST['local_image'])) {
        $p['mode'] = 'image';
        $p['image_url'] = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
    }
    rx_sheet_page(['doctor' => $p] + rx_sample_ctx());
    exit;
}

/* ── save / back to the template ── */
$errors = [];
$saved  = !empty($_GET['saved']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    if (($_POST['action'] ?? '') === 'reset') {
        RxProfile::reset($id);
        header('Location: ' . APP_URL . '/admin/doctor-rx.php?id=' . $id . '&reset=1');
        exit;
    }
    try {
        RxProfile::save($id, $_POST, $_FILES['panel_image'] ?? null, !empty($_POST['drop_image']), (int)($_SESSION['user_id'] ?? 0));
        header('Location: ' . APP_URL . '/admin/doctor-rx.php?id=' . $id . '&saved=1');
        exit;
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

$row = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_merge(RxProfile::stored($id), array_intersect_key($_POST, RxProfile::FIELDS), ['panel_mode' => $_POST['panel_mode'] ?? 'fields'])
    : RxProfile::stored($id);
$defaults = RxProfile::resolve([], $doctor);   // what an empty field prints
$hasImage = !empty($row['panel_image']) && RxProfile::isSafePath((string)$row['panel_image']) && is_file(APP_ROOT . '/' . $row['panel_image']);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$pageTitle  = 'ڕەچەتەی ' . $doctor['full_name'];
$breadcrumb = [
    ['title' => 'قاڵبی ڕەچەتە', 'url' => APP_URL . '/admin/rx-templates.php'],
    ['title' => $doctor['full_name']],
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.drx { display: grid; grid-template-columns: minmax(330px, 420px) minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 1100px) { .drx { grid-template-columns: 1fr; } }
.drx-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; }
.drx-card h3 { margin: 0 0 10px; font-size: 15px; display: flex; align-items: center; gap: 8px; }
.drx-card h3 i { color: #296fa0; }
.drx label.f { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin: 10px 0 4px; }
.drx label.f small { font-weight: 400; color: #94a3b8; }
.drx input[type=text], .drx textarea { width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; font-size: 13px; }
.drx textarea { resize: vertical; line-height: 1.6; }
.drx .ltr { direction: ltr; text-align: left; }
.drx-mode { display: flex; gap: 8px; }
.drx-mode label { flex: 1; display: flex; gap: 8px; align-items: center; padding: 9px 11px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; }
.drx-mode label:has(input:checked) { border-color: #296fa0; background: #f0f7fc; }
.drx-img { display: flex; gap: 10px; align-items: center; margin-top: 6px; }
.drx-img img { width: 70px; height: 110px; object-fit: cover; border: 1px solid #e2e8f0; border-radius: 6px; }
.drx-sticky { position: sticky; top: 12px; }
.drx-prev { width: 100%; height: 620px; border: 1px solid #e2e8f0; border-radius: 12px; background: #e9eef2; display: block; }
.drx-acts { display: flex; gap: 8px; margin-top: 14px; }
[data-only] { transition: opacity .15s; }
.drx[data-mode="image"] [data-only="fields"], .drx[data-mode="fields"] [data-only="image"] { display: none; }
</style>

<?php if ($saved): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> ڕەچەتەی دکتۆر پاشەکەوت کرا.</div><?php endif; ?>
<?php if (!empty($_GET['reset'])): ?><div class="alert alert-success"><i class="fas fa-rotate-left"></i> ڕەچەتەی ئەم دکتۆرە گەڕایەوە بۆ قاڵبی کلینیک.</div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" id="drxForm">
<?= Csrf::field() ?>
<div class="drx" id="drx" data-mode="<?= $e($row['panel_mode'] === 'image' ? 'image' : 'fields') ?>">
    <div class="drx-card">
        <h3><i class="fas fa-prescription"></i> <?= $e($doctor['full_name']) ?></h3>
        <div class="drx-mode">
            <label><input type="radio" name="panel_mode" value="fields" <?= $row['panel_mode'] !== 'image' ? 'checked' : '' ?>> لە زانیارییەکانەوە</label>
            <label><input type="radio" name="panel_mode" value="image" <?= $row['panel_mode'] === 'image' ? 'checked' : '' ?>> وێنەی ئامادە</label>
        </div>

        <div data-only="image">
            <label class="f">وێنەی بەشی دکتۆر <small>(PNG/JPG، نیوەی چەپی ڕەچەتەکە، پانی ٩٤ × بەرزی ١٤٨ مم)</small></label>
            <input type="file" name="panel_image" id="drxFile" accept="image/png,image/jpeg">
            <?php if ($hasImage): ?>
            <div class="drx-img">
                <img src="<?= APP_URL . '/' . $e($row['panel_image']) ?>" alt="">
                <label style="font-size:12.5px"><input type="checkbox" name="drop_image" value="1"> لابردنی ئەم وێنەیە</label>
            </div>
            <?php endif; ?>
            <p style="font-size:12px;color:#64748b;margin:8px 0 0">ناو، تەمەن، ڕێکەوت و دەرمانەکان هەر لە نیوەی ڕاستدا دەنووسرێن. زانیارییەکانی خوارەوەش پاشەکەوت دەکرێن ئەگەر جارێکی تر بگەڕێیتەوە بۆ «لە زانیارییەکانەوە».</p>
        </div>

        <label class="f">نازناو <small>(بەتاڵ = <?= $e($defaults['title']) ?>)</small></label>
        <input type="text" name="title" value="<?= $e($row['title'] ?? '') ?>" placeholder="<?= $e($defaults['title']) ?>" maxlength="60">
        <label class="f">ناوی دکتۆر لەسەر ڕەچەتە</label>
        <input type="text" name="display_name" value="<?= $e($row['display_name'] ?? '') ?>" placeholder="<?= $e($defaults['display_name']) ?>" maxlength="150">
        <label class="f">بڕوانامە و شارەزایی <small>(هەر دێڕێک بە جیا)</small></label>
        <textarea name="credentials" rows="7" maxlength="2000"><?= $e($row['credentials'] ?? '') ?></textarea>
        <label class="f">بڕوانامە بە ئینگلیزی</label>
        <input type="text" name="degrees" class="ltr" value="<?= $e($row['degrees'] ?? '') ?>" placeholder="B.D.S., M. Sc (...)" maxlength="300">
        <label class="f">مۆبایل <small>(بەتاڵ = ژمارەکانی کلینیک)</small></label>
        <input type="text" name="phones" class="ltr" value="<?= $e($row['phones'] ?? '') ?>" placeholder="<?= $e($defaults['phones']) ?>" maxlength="150">
        <label class="f">ناونیشان <small>(بەتاڵ = ناونیشانی کلینیک؛ هەتا دوو دێڕ)</small></label>
        <textarea name="address" rows="2" maxlength="255" placeholder="<?= $e(implode("\n", $defaults['address'])) ?>"><?= $e($row['address'] ?? '') ?></textarea>
        <label class="f">لینکی QR ـی یەکەم <small>(لای چەپ)</small></label>
        <input type="text" name="qr1_url" class="ltr" value="<?= $e($row['qr1_url'] ?? '') ?>" placeholder="<?= $e(RxProfile::DEFAULT_QR1) ?>" maxlength="500">
        <label class="f">لینکی QR ـی دووەم <small>(لای ڕاست)</small></label>
        <input type="text" name="qr2_url" class="ltr" value="<?= $e($row['qr2_url'] ?? '') ?>" placeholder="<?= $e(RxProfile::DEFAULT_QR2) ?>" maxlength="500">
        <label class="f">نووسینی نێوان QR ـەکان <small>(هەر دێڕێک بە جیا)</small></label>
        <textarea name="qr_caption" rows="3" maxlength="150" placeholder="<?= $e(RxProfile::DEFAULT_CAPTION) ?>"><?= $e($row['qr_caption'] ?? '') ?></textarea>

        <div class="drx-acts">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
            <a class="btn btn-secondary" href="<?= APP_URL ?>/admin/rx-templates.php"><i class="fas fa-arrow-right"></i> قاڵبی ڕەچەتە</a>
            <button type="button" class="btn btn-secondary" id="drxReset" style="margin-inline-start:auto"
                    title="هەموو زانیارییەکانی ئەم دکتۆرە و وێنەکەی لادەبرێن؛ ڕەچەتەکەی دەبێتەوە بە قاڵبی کلینیک">
                <i class="fas fa-rotate-left"></i> گەڕاندنەوە بۆ بنەڕەت</button>
        </div>
    </div>

    <div class="drx-sticky">
        <div class="drx-card" style="padding:10px">
            <h3 style="margin:2px 4px 10px"><i class="fas fa-eye"></i> پێشبینین <small style="font-weight:400;color:#94a3b8;font-size:12px">— ڕەچەتەیەکی نموونە، هەر وەک چاپ دەکرێت</small></h3>
            <iframe class="drx-prev" id="drxPrev" title="پێشبینینی ڕەچەتە"></iframe>
        </div>
    </div>
</div>
</form>

<script>
(function () {
    const form = document.getElementById('drxForm'), box = document.getElementById('drx'), prev = document.getElementById('drxPrev');
    const file = document.getElementById('drxFile');
    const URL_SELF = <?= json_encode(APP_URL . '/admin/doctor-rx.php?id=' . $id) ?>;
    let timer = null, req = 0, localUrl = null;

    async function refresh() {
        const my = ++req;
        const fd = new FormData(form);
        fd.delete('panel_image');
        fd.set('preview', '1');
        if (localUrl) fd.set('local_image', '1');
        try {
            const res = await fetch(URL_SELF, { method: 'POST', body: fd });
            const html = await res.text();
            if (my !== req) return;
            prev.srcdoc = html;
        } catch (e) { /* the preview is a convenience */ }
    }
    // a newly chosen picture is shown before it is saved
    prev.addEventListener('load', () => {
        if (!localUrl) return;
        const img = prev.contentDocument && prev.contentDocument.querySelector('.rx-panel.is-image img');
        if (img) img.src = localUrl;
    });
    const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };
    form.addEventListener('input', later);
    form.addEventListener('change', e => {
        if (e.target.name === 'panel_mode') box.dataset.mode = e.target.value;
        if (e.target === file) {
            if (localUrl) URL.revokeObjectURL(localUrl);
            localUrl = file.files[0] ? URL.createObjectURL(file.files[0]) : null;
        }
        later();
    });
    document.getElementById('drxReset').addEventListener('click', () => {
        if (!confirm('زانیارییەکانی ئەم دکتۆرە (بڕوانامە، مۆبایل، QR و وێنە) لادەبرێن و ڕەچەتەکەی دەبێتەوە بە قاڵبی کلینیک. بەردەوام دەبیت؟')) return;
        const a = document.createElement('input');
        a.type = 'hidden'; a.name = 'action'; a.value = 'reset';
        form.appendChild(a);
        form.submit();
    });
    refresh();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
