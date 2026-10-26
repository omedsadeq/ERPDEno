<?php
/**
 * قاڵبی ڕەچەتە — the prescription template, in one place.
 *
 *   the template  everything on the A5 sheet that is the clinic's: logo and
 *                 the two pictures (original / uploaded / hidden), the two
 *                 clinic lines, the colours, the default title, address,
 *                 phones, QR links and caption, the notice and signature
 *                 label (classes/RxTemplate.php). «گەڕاندنەوە» brings back
 *                 the original design.
 *   the doctors   every prescriber (doctors and admins) with whether their
 *                 own part is filled in; «دەستکاری» opens admin/doctor-rx.php.
 *   the preview   the real sheet (includes/rx_sheet.php) with the unsaved
 *                 template and the chosen doctor, updated while typing.
 *
 * Reached from the sidebar (بەڕێوەبردن), the settings tabs and the users page.
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
$prescribers = RxProfile::prescribers();
$self = APP_URL . '/admin/rx-templates.php';

/** The template as posted (unsaved), for the preview. */
function rx_tpl_from_post(): array
{
    $tpl = RxTemplate::get();
    foreach (RxTemplate::TEXTS as $k => $max) {
        if (array_key_exists($k, $_POST)) {
            $tpl[$k] = RxTemplate::textValue(mb_substr(str_replace(['<', '>'], '', (string)$_POST[$k]), 0, $max), $k);
        }
    }
    $tpl['rx_warning'] = str_replace('{phone}', Branding::phone(1), $tpl['rx_warning']);
    foreach (RxTemplate::COLORS as $k => $_) {
        $v = strtolower(trim((string)($_POST[$k] ?? '')));
        if (preg_match('/^#[0-9a-f]{6}$/', $v)) {
            $tpl[$k] = $v;
        }
    }
    foreach (RxTemplate::IMAGES as $k => $orig) {
        $act = $_POST['img'][$k] ?? 'keep';
        if ($act === 'hide') {
            $tpl[$k] = '';
        } elseif ($act === 'default') {
            $tpl[$k] = APP_URL . '/' . $orig;
        } elseif ($act === 'upload') {
            $tpl[$k] = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';   // the page swaps in the chosen file
        }
    }
    return $tpl;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'preview') {
        require_once __DIR__ . '/../includes/rx_sheet.php';
        $tpl = rx_tpl_from_post();
        $docId = (int)($_POST['doctor_id'] ?? 0);
        $user = $docId ? ($db->fetch("SELECT id, full_name, phone FROM users WHERE id = :id", ['id' => $docId]) ?: []) : [];
        $doctor = RxProfile::resolve($user ? RxProfile::stored($docId) : [], $user ?: ['full_name' => 'ناوی دکتۆر'], $tpl);
        rx_sheet_page(['doctor' => $doctor, 'template' => $tpl] + rx_sample_ctx());
        exit;
    }
    try {
        if ($action === 'reset') {
            RxTemplate::reset();
            header('Location: ' . $self . '?reset=1');
            exit;
        }
        RxTemplate::save($_POST, (array)($_POST['img'] ?? []), $_FILES, (int)($_SESSION['user_id'] ?? 0));
        header('Location: ' . $self . '?saved=1');
        exit;
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$stored = RxTemplate::stored();
$current = RxTemplate::get();
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$imgLabels = ['rx_logo' => 'لۆگۆ (ناوەڕاست)', 'rx_image_left' => 'وێنەی لای چەپ', 'rx_image_right' => 'وێنەی لای ڕاست'];

$pageTitle  = 'قاڵبی ڕەچەتە';
$breadcrumb = [['title' => 'بەڕێوەبردن'], ['title' => 'قاڵبی ڕەچەتە']];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.rxt { display: grid; grid-template-columns: minmax(340px, 440px) minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 1150px) { .rxt { grid-template-columns: 1fr; } }
.rxt-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
.rxt-card h3 { margin: 0 0 4px; font-size: 15px; display: flex; align-items: center; gap: 8px; }
.rxt-card h3 i { color: #296fa0; }
.rxt-card h4 { margin: 16px 0 2px; font-size: 13px; color: #296fa0; border-top: 1px dashed #e2e8f0; padding-top: 12px; }
.rxt-card h4:first-of-type { border-top: 0; padding-top: 4px; }
.rxt label.f { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin: 9px 0 4px; }
.rxt label.f small { font-weight: 400; color: #94a3b8; }
.rxt input[type=text], .rxt textarea, .rxt select { width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; font-size: 13px; background: #fff; }
.rxt textarea { resize: vertical; line-height: 1.6; }
.rxt .ltr { direction: ltr; text-align: left; }
.rxt .two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.rxt-img { display: grid; grid-template-columns: 64px 1fr; gap: 10px; align-items: center; padding: 8px; border: 1px solid #eef2f6; border-radius: 10px; margin-top: 6px; }
.rxt-img .th { width: 64px; height: 48px; border-radius: 8px; background: #f3f4f4 center/contain no-repeat; border: 1px solid #e2e8f0; display: grid; place-items: center; color: #94a3b8; font-size: 11px; }
.rxt-img b { font-size: 12.5px; display: block; margin-bottom: 4px; }
.rxt-img .row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.rxt-img select { width: auto; flex: 0 0 auto; padding: 4px 8px; font-size: 12.5px; }
.rxt-img input[type=file] { font-size: 12px; max-width: 210px; }
.rxt-color { display: flex; align-items: center; gap: 8px; }
.rxt-color input[type=color] { width: 44px; height: 32px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 2px; background: #fff; }
.rxt-acts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
.rxt-sticky { position: sticky; top: 12px; }
.rxt-prev { width: 100%; height: 600px; border: 1px solid #e2e8f0; border-radius: 12px; background: #e9eef2; display: block; }
.rxt-prevbar { display: flex; align-items: center; gap: 10px; margin: 0 4px 10px; flex-wrap: wrap; }
.rxt-prevbar select { width: auto; min-width: 220px; }
.rxt-docs { width: 100%; border-collapse: collapse; font-size: 13px; }
.rxt-docs th { text-align: start; font-size: 12px; color: #64748b; font-weight: 700; padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
.rxt-docs td { padding: 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.rxt-docs tr.off td { opacity: .5; }
.rxt-st { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.rxt-st.img { background: #ede9fe; color: #5b21b6; }
.rxt-st.ok { background: #dcfce7; color: #166534; }
.rxt-st.empty { background: #fef3c7; color: #92400e; }
.rxt-docs .acts { display: flex; gap: 6px; justify-content: flex-end; }
</style>

<?php if (!empty($_GET['saved'])): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> قاڵبی ڕەچەتە پاشەکەوت کرا.</div><?php endif; ?>
<?php if (!empty($_GET['reset'])): ?><div class="alert alert-success"><i class="fas fa-rotate-left"></i> قاڵبەکە گەڕایەوە بۆ دیزاینی بنەڕەت.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $e($error) ?></div><?php endif; ?>

<div class="rxt">
<form method="post" enctype="multipart/form-data" id="rxtForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save" id="rxtAction">
    <div class="rxt-card">
        <h3><i class="fas fa-prescription"></i> قاڵبی ڕەچەتە</h3>
        <p style="margin:0;font-size:12.5px;color:#64748b">ئەمە بەشی کلینیکە لەسەر ڕەچەتەی هەموو دکتۆرەکان. خانەی بەتاڵ = دیزاینی بنەڕەت.</p>

        <h4>ناوی کلینیک</h4>
        <div class="two">
            <div><label class="f">دێڕی یەکەم</label>
                <input type="text" name="rx_line1" value="<?= $e($stored['rx_line1']) ?>" placeholder="<?= $e(RxTemplate::defaultText('rx_line1')) ?>" maxlength="60"></div>
            <div><label class="f">دێڕی دووەم</label>
                <input type="text" name="rx_line2" value="<?= $e($stored['rx_line2']) ?>" placeholder="<?= $e(RxTemplate::defaultText('rx_line2')) ?>" maxlength="80"></div>
        </div>
        <label class="f">نازناوی دکتۆر <small>(سەر ناوی دکتۆر)</small></label>
        <input type="text" name="rx_title" value="<?= $e($stored['rx_title']) ?>" placeholder="دکتۆر" maxlength="60">

        <h4>وێنەکان</h4>
        <?php foreach (RxTemplate::IMAGES as $k => $orig): $v = $stored[$k]; ?>
        <div class="rxt-img" data-img="<?= $k ?>">
            <div class="th" style="<?= $current[$k] !== '' ? 'background-image:url(\'' . $e($current[$k]) . '\')' : '' ?>"><?= $current[$k] === '' ? 'شاردراوە' : '' ?></div>
            <div>
                <b><?= $e($imgLabels[$k]) ?></b>
                <div class="row">
                    <select name="img[<?= $k ?>]">
                        <option value="keep">وەک خۆی بێت</option>
                        <option value="upload">وێنەی نوێ…</option>
                        <?php if ($v !== ''): ?><option value="default">وێنەی بنەڕەت</option><?php endif; ?>
                        <?php if ($v !== RxTemplate::HIDDEN): ?><option value="hide">نیشان مەدە</option><?php endif; ?>
                    </select>
                    <input type="file" name="<?= $k ?>" accept="image/png,image/jpeg" hidden>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <h4>ڕەنگەکان</h4>
        <div class="two">
            <div><label class="f">چوارچێوە و QR</label>
                <div class="rxt-color"><input type="color" name="rx_color_frame" value="<?= $e($current['rx_color_frame']) ?>"><span class="ltr" style="font-size:12px;color:#64748b"><?= $e($current['rx_color_frame']) ?></span></div></div>
            <div><label class="f">نووسینەکان</label>
                <div class="rxt-color"><input type="color" name="rx_color_text" value="<?= $e($current['rx_color_text']) ?>"><span class="ltr" style="font-size:12px;color:#64748b"><?= $e($current['rx_color_text']) ?></span></div></div>
        </div>

        <h4>پەیوەندی <small style="font-weight:400;color:#94a3b8">— هەر دکتۆرێک دەتوانێت هی خۆی دابنێت</small></h4>
        <label class="f">ناونیشان <small>(هەتا دوو دێڕ)</small></label>
        <textarea name="rx_address" rows="2" maxlength="255" placeholder="<?= $e(RxTemplate::defaultText('rx_address')) ?>"><?= $e($stored['rx_address']) ?></textarea>
        <label class="f">مۆبایل</label>
        <input type="text" name="rx_phones" class="ltr" value="<?= $e($stored['rx_phones']) ?>" placeholder="<?= $e(RxTemplate::defaultText('rx_phones')) ?>" maxlength="150">

        <h4>QR</h4>
        <label class="f">لینکی یەکەم <small>(لای چەپ)</small></label>
        <input type="text" name="rx_qr1" class="ltr" value="<?= $e($stored['rx_qr1']) ?>" placeholder="<?= $e(RxTemplate::DEFAULT_QR1) ?>" maxlength="500">
        <label class="f">لینکی دووەم <small>(لای ڕاست)</small></label>
        <input type="text" name="rx_qr2" class="ltr" value="<?= $e($stored['rx_qr2']) ?>" placeholder="<?= $e(RxTemplate::DEFAULT_QR2) ?>" maxlength="500">
        <label class="f">نووسینی نێوانیان <small>(هەر دێڕێک بە جیا)</small></label>
        <textarea name="rx_qr_caption" rows="3" maxlength="150" placeholder="<?= $e(RxTemplate::DEFAULT_CAPTION) ?>"><?= $e($stored['rx_qr_caption']) ?></textarea>

        <h4>نیوەی ڕاست</h4>
        <label class="f">ئاگاداری خوارەوە <small>({phone} = ژمارەی یەکەمی کلینیک؛ بۆ لابردن: -)</small></label>
        <input type="text" name="rx_warning" value="<?= $e($stored['rx_warning']) ?>" placeholder="<?= $e(RxTemplate::DEFAULT_WARNING) ?>" maxlength="300">
        <label class="f">ژێر هێڵی واژۆ</label>
        <input type="text" name="rx_sign" value="<?= $e($stored['rx_sign']) ?>" placeholder="واژۆی دکتۆر" maxlength="60">

        <div class="rxt-acts">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
            <button type="button" class="btn btn-secondary" id="rxtReset" title="هەموو شتێک دەگەڕێتەوە بۆ وەک ڕەچەتەی ئەسڵی (lezan a5)"><i class="fas fa-rotate-left"></i> گەڕاندنەوە بۆ دیزاینی بنەڕەت</button>
        </div>
    </div>
</form>

<div class="rxt-sticky">
    <div class="rxt-card" style="padding:10px">
        <div class="rxt-prevbar">
            <h3 style="margin:0"><i class="fas fa-eye"></i> پێشبینین</h3>
            <select id="rxtDoc" aria-label="پێشبینین بە ڕەچەتەی">
                <?php foreach ($prescribers as $p): if (!$p['is_active']) continue; ?>
                <option value="<?= (int)$p['id'] ?>"><?= $e($p['full_name']) ?></option>
                <?php endforeach; ?>
                <option value="0">— دکتۆرێکی نموونە —</option>
            </select>
        </div>
        <iframe class="rxt-prev" id="rxtPrev" title="پێشبینینی ڕەچەتە"></iframe>
    </div>

    <div class="rxt-card">
        <h3><i class="fas fa-user-doctor"></i> ڕەچەتەی دکتۆرەکان</h3>
        <p style="margin:0 0 8px;font-size:12.5px;color:#64748b">ناو، بڕوانامە، مۆبایل و QR ـی هەر دکتۆرێک — یان وێنەی ئامادەی خۆی.</p>
        <table class="rxt-docs">
            <thead><tr><th>ناو</th><th>دۆخ</th><th style="text-align:center">ڕەچەتە</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($prescribers as $p):
                $st = $p['panel_mode'] === 'image' && !empty($p['panel_image']) ? ['img', 'وێنەی ئامادە']
                    : ($p['has_details'] ? ['ok', 'پڕکراوەتەوە'] : ['empty', 'هێشتا بەتاڵە']); ?>
            <tr class="<?= $p['is_active'] ? '' : 'off' ?>">
                <td><b><?= $e($p['full_name']) ?></b><?= $p['role'] === 'admin' ? ' <small style="color:#94a3b8">(بەڕێوەبەر)</small>' : '' ?></td>
                <td><span class="rxt-st <?= $st[0] ?>"><?= $st[1] ?></span></td>
                <td style="text-align:center;color:#64748b"><?= (int)$p['rx_count'] ?></td>
                <td><div class="acts">
                    <button type="button" class="btn btn-sm btn-secondary" data-prev="<?= (int)$p['id'] ?>" title="پێشبینین"><i class="fas fa-eye"></i></button>
                    <a class="btn btn-sm btn-primary" href="<?= APP_URL ?>/admin/doctor-rx.php?id=<?= (int)$p['id'] ?>"><i class="fas fa-pen"></i> دەستکاری</a>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<script>
(function () {
    const form = document.getElementById('rxtForm'), prev = document.getElementById('rxtPrev'), doc = document.getElementById('rxtDoc');
    const SELF = <?= json_encode($self) ?>;
    const local = {};   // key → object URL of a picture chosen but not saved
    let timer = null, req = 0;

    async function refresh() {
        const my = ++req;
        const fd = new FormData(form);
        ['rx_logo', 'rx_image_left', 'rx_image_right'].forEach(k => fd.delete(k));
        fd.set('action', 'preview');
        fd.set('doctor_id', doc.value);
        try {
            const html = await (await fetch(SELF, { method: 'POST', body: fd })).text();
            if (my === req) prev.srcdoc = html;
        } catch (e) { /* the preview is a convenience */ }
    }
    // pictures chosen but not saved yet are shown in the preview from here
    prev.addEventListener('load', () => {
        const d = prev.contentDocument; if (!d) return;
        const map = { rx_logo: '.rx-top .logo', rx_image_left: '.rx-top .deco.l', rx_image_right: '.rx-top .deco.r' };
        Object.keys(local).forEach(k => { const img = d.querySelector(map[k]); if (img && local[k]) img.src = local[k]; });
    });
    const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };

    form.addEventListener('input', later);
    form.addEventListener('change', e => {
        const box = e.target.closest('[data-img]');
        if (box) {
            const key = box.dataset.img, sel = box.querySelector('select'), file = box.querySelector('input[type=file]');
            file.hidden = sel.value !== 'upload';
            if (e.target === sel && sel.value === 'upload') file.click();
            if (local[key]) { URL.revokeObjectURL(local[key]); delete local[key]; }
            if (sel.value === 'upload' && file.files[0]) local[key] = URL.createObjectURL(file.files[0]);
            const th = box.querySelector('.th');
            if (local[key]) { th.style.backgroundImage = 'url(' + local[key] + ')'; th.textContent = ''; }
        }
        if (e.target.type === 'color') e.target.nextElementSibling.textContent = e.target.value;
        later();
    });
    doc.addEventListener('change', refresh);
    document.querySelectorAll('[data-prev]').forEach(b => b.addEventListener('click', () => {
        doc.value = b.dataset.prev;
        refresh();
        prev.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));
    document.getElementById('rxtReset').addEventListener('click', () => {
        if (!confirm('هەموو قاڵبەکە (ناو، وێنەکان، ڕەنگ، QR و دەقەکان) دەگەڕێتەوە بۆ دیزاینی بنەڕەت. بەردەوام دەبیت؟\nزانیاری دکتۆرەکان دەستیان لێ نادرێت.')) return;
        document.getElementById('rxtAction').value = 'reset';
        form.submit();
    });
    refresh();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
