<?php
/**
 * سەردێڕ و واژۆ — the letterhead every letter is printed on (the modern
 * sheet; the classic one was taken away).
 *
 * The header (Kurdish right, English left, the logo between), the colour, the
 * faded logo, the wave footer with address, QR and phones; the centre's
 * signer, signature and seal; and «واژۆی دکتۆرەکان»: every doctor's own
 * block — the name, the lines under it (specialty, degree, post) and their
 * own signature image — for the letters written in that doctor's name.
 *
 * Settings live in `settings` (letter_* keys), the doctors' blocks in
 * official_letter_signers; images go to uploads/letters/ and are served only
 * through letters/asset.php. The sheet on the side redraws a sample letter
 * with the unsaved values as you edit (a doctor's block while it is open).
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.settings');

$cfg = $letters->settings();
$signers = $letters->signers();
$error = '';

$textKeys = [
    'letter_number_format', 'letter_default_greeting', 'letter_default_closing',
    'letter_default_signer_name', 'letter_default_signer_title',
    'letter_modern_header_right', 'letter_modern_header_left', 'letter_modern_contacts', 'letter_modern_address',
];
$choiceKeys = ['letter_font_size', 'letter_modern_color'];
$flagKeys = ['letter_number_yearly', 'letter_modern_watermark'];
// The images this page offers (the classic header/footer images are no longer used).
$assetKinds = ['logo', 'qr', 'signature', 'seal'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $in = is_array($_POST['cfg'] ?? null) ? $_POST['cfg'] : [];
    $next = $cfg;
    foreach ($textKeys as $k) {
        $next[$k] = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string)($in[$k] ?? ''))), 0, 2000);
    }
    foreach ($choiceKeys as $k) {
        $next[$k] = (string)($in[$k] ?? '');
    }
    foreach ($flagKeys as $k) {
        $next[$k] = !empty($in[$k]) ? '1' : '0';
    }
    $next = OfficialLetter::sanitizeSettings($next);

    // Old image files are deleted only once the new names are saved, and new
    // ones are deleted again if anything fails — a setting never points at a
    // file that is gone.
    $stored = [];
    $replaced = [];
    try {
        foreach ($assetKinds as $kind) {
            $key = OfficialLetter::ASSETS[$kind];
            $file = $_FILES['file_' . $kind] ?? null;
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $next[$key] = $stored[] = $letters->storeAsset($kind, $file);
                $replaced[] = $kind;
            } elseif (!empty($_POST['remove_' . $kind])) {
                $next[$key] = '';
                $replaced[] = $kind;
            }
        }
        $save = [];
        foreach (array_merge($textKeys, $choiceKeys, $flagKeys, array_values(OfficialLetter::ASSETS), ['letter_default_design']) as $k) {
            $save[$k] = $next[$k];
        }
        $letters->saveSettings($save);
        foreach ($replaced as $kind) {
            $letters->removeAssetFile($kind, $cfg);
        }
        if (class_exists('AuditLog')) {
            AuditLog::log('letter_settings_update', 'settings', null);
        }

        // Every doctor's own block.
        $docs = is_array($_POST['doc'] ?? null) ? $_POST['doc'] : [];
        $removeSig = is_array($_POST['docsig_remove'] ?? null) ? $_POST['docsig_remove'] : [];
        foreach ($signers as $s) {
            $id = $s['doctor_id'];
            if (!isset($docs[$id]) || !is_array($docs[$id])) {
                continue;
            }
            $letters->saveSigner($id, (string)($docs[$id]['name'] ?? ''), (string)($docs[$id]['title'] ?? ''),
                $_FILES['docsig_' . $id] ?? null, !empty($removeSig[$id]), $letterUserId);
        }
        header('Location: ' . APP_URL . '/letters/settings.php?saved=1');
        exit;
    } catch (InvalidArgumentException | RuntimeException $ex) {
        foreach ($stored as $name) {
            @unlink(OfficialLetter::assetDir() . '/' . $name);
        }
        $error = $ex->getMessage();
        $cfg = array_merge($cfg, array_diff_key($next, array_flip(OfficialLetter::ASSETS)));
        $signers = $letters->signers();
    }
}

$urls = OfficialLetter::assetUrls($cfg);
$has = [];
foreach (array_keys(OfficialLetter::ASSETS) as $kind) {
    $has[$kind] = OfficialLetter::hasUpload($kind, $cfg);
}
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$checked = function ($key) use ($cfg) { return $cfg[$key] === '1' ? 'checked' : ''; };
$selected = function ($key, $value) use ($cfg) { return $cfg[$key] === $value ? 'selected' : ''; };

/** One image slot: current thumbnail, file picker, remove box. */
$imageSlot = function (string $field, ?string $url, bool $hasFile, string $removeName, string $label, string $hint) use ($h) {
    ?>
    <div class="lts-img">
        <div class="lts-img-thumb">
            <?php if ($url): ?>
            <img src="<?= $h($url) ?>" alt="">
            <?php else: ?>
            <span><i class="fas fa-image"></i></span>
            <?php endif; ?>
        </div>
        <div class="lts-img-body">
            <b><?= $h($label) ?></b>
            <input type="file" name="<?= $h($field) ?>" accept="image/png,image/jpeg" class="form-control">
            <div class="form-hint"><?= $h($hint) ?></div>
            <?php if ($hasFile): ?>
            <label class="lts-remove"><input type="checkbox" name="<?= $h($removeName) ?>" value="1"> لابردنی ئەم وێنەیە</label>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
$slot = function (string $kind, string $label, string $hint) use ($imageSlot, $urls, $has) {
    $imageSlot('file_' . $kind, $urls[$kind], $has[$kind], 'remove_' . $kind, $label, $hint);
};

$pageTitle = 'سەردێڕ و واژۆی نوسراو';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> ڕێکخستنەکان هەڵگیران — هەموو نوسراوەکان (کۆنەکانیش) بەم سەردێڕە چاپ دەکرێن.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle alert-icon"></i> <?= $h($error) ?></div>
<?php endif; ?>

<div class="lts-layout">
    <form method="POST" enctype="multipart/form-data" id="ltsForm" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="sample_doctor" id="ltsSampleDoctor" value="">

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-heading"></i> سەردێڕ و لۆگۆ</h3></div>
            <div class="panel-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>سەردێڕ — لای ڕاست (کوردی)</label>
                        <textarea name="cfg[letter_modern_header_right]" class="form-control" rows="2"><?= $h($cfg['letter_modern_header_right']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>سەردێڕ — لای چەپ (ئینگلیزی)</label>
                        <textarea name="cfg[letter_modern_header_left]" class="form-control" rows="2" dir="ltr" style="text-align:left;"><?= $h($cfg['letter_modern_header_left']) ?></textarea>
                    </div>
                </div>
                <div class="form-hint" style="margin-top:-6px;">دێڕی یەکەم گەورە، دێڕی دووەم بچووکتر.</div>
                <?php $slot('logo', 'لۆگۆ', $has['logo'] ? 'لۆگۆی تایبەتی نوسراو. لابردنی دەیگەڕێنێتەوە بۆ لۆگۆی سیستەم.' : 'ئێستا لۆگۆی سیستەم بەکاردێت. لۆگۆیەکی PNG بە باکگراوندی ڕوون باشترە، چونکە لە پشتی نووسینەکەش کاڵ دەکرێتەوە.'); ?>
                <div class="form-row" style="margin-top:12px;">
                    <div class="form-group">
                        <label>ڕەنگ</label>
                        <input type="color" name="cfg[letter_modern_color]" class="form-control lts-color" value="<?= $h($cfg['letter_modern_color']) ?>">
                        <div class="form-hint">هێڵی ژێر سەردێڕ و ژێرپەڕەکە.</div>
                    </div>
                    <div class="form-group lts-checks" style="justify-content:center;">
                        <label><input type="hidden" name="cfg[letter_modern_watermark]" value="0"><input type="checkbox" name="cfg[letter_modern_watermark]" value="1" <?= $checked('letter_modern_watermark') ?>> لۆگۆ بە کاڵی لە پشتی نووسینەکە</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-shoe-prints"></i> ژێرپەڕە</h3></div>
            <div class="panel-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>ناونیشان (لای ڕاست)</label>
                        <textarea name="cfg[letter_modern_address]" class="form-control" rows="2"><?= $h($cfg['letter_modern_address']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>ژمارەی تەلەفۆن (لای چەپ)</label>
                        <textarea name="cfg[letter_modern_contacts]" class="form-control" rows="2" dir="ltr" style="text-align:left;"><?= $h($cfg['letter_modern_contacts']) ?></textarea>
                    </div>
                </div>
                <?php $slot('qr', 'QR کۆد (ناوەڕاستی ژێرپەڕە)', 'وێنەی QR کۆدەکەت (PNG یان JPG). بێ ئەوە، ژێرپەڕەکە بێ QR دەردەکەوێت.'); ?>
            </div>
        </div>

        <div class="panel" id="ltsSign">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-signature"></i> واژۆ و مۆری سەنتەر</h3></div>
            <div class="panel-body">
                <div class="lts-note"><i class="fas fa-lock"></i> ئەم وێنانە تەنها لە ناو سیستەمەکەدا پیشان دەدرێن و لینکی گشتییان نییە. لە هەر نوسراوێکدا بە جیا هەڵدەبژێردرێن.</div>
                <div class="form-row" style="margin-top:12px;">
                    <div class="form-group">
                        <label>ناوی واژۆکەری بنەڕەت</label>
                        <input type="text" name="cfg[letter_default_signer_name]" class="form-control" maxlength="150" value="<?= $h($cfg['letter_default_signer_name']) ?>"
                               placeholder="نموونە: Signer Name">
                    </div>
                    <div class="form-group">
                        <label>دێڕەکانی ژێر ناو</label>
                        <textarea name="cfg[letter_default_signer_title]" class="form-control" rows="3" maxlength="255"
                                  placeholder="Owner and Authorized Representative&#10;Lezan Dental Clinics"><?= $h($cfg['letter_default_signer_title']) ?></textarea>
                    </div>
                </div>
                <div class="form-hint" style="margin-top:-6px;">بۆ ئەو نوسراوانەی بە ناوی سەنتەرەوە دەنووسرێن. نوسراوی دکتۆرێک واژۆی خۆی دەبات (خوارەوە).</div>
                <?php $slot('signature', 'واژۆی سەنتەر', 'PNG بە باکگراوندی ڕوون باشترە.'); ?>
                <?php $slot('seal', 'مۆر', 'PNG بە باکگراوندی ڕوون باشترە؛ کەمێک دەکەوێتە سەر واژۆکە. هەموو نوسراوەکان هەمان مۆر دەبەن.'); ?>
            </div>
        </div>

        <div class="panel" id="ltsDoctors">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-user-doctor"></i> واژۆی دکتۆرەکان</h3></div>
            <div class="panel-body">
                <div class="lts-note"><i class="fas fa-circle-info"></i> هەر دکتۆرێک بەشی واژۆی خۆی: ناو، و ئەو دێڕانەی لە ژێریدا دەنووسرێن (پسپۆڕی، بڕوانامە، پۆست)، و وێنەی واژۆکەی. لە نووسینی نوسراودا «بە ناوی کێ» هەڵدەبژێردرێت و ئەمانە خۆیان دادەنرێن. دکتۆرێک کە بچێتە ژوورەوە، نوسراوی نوێی بە ناوی خۆیەوە دەبێت.</div>
                <?php if (!$signers): ?>
                <p class="form-hint" style="margin-top:10px;">هیچ دکتۆرێکی چالاک نییە.</p>
                <?php endif; ?>
                <?php foreach ($signers as $s):
                    $id = $s['doctor_id'];
                    $written = $s['signer_name'] || $s['signer_title'];
                    $sigUrl = $s['has_signature'] ? APP_URL . '/letters/asset.php?k=signature&d=' . $id . '&v=' . (int)@filemtime(OfficialLetter::signerSignaturePath($s['signature_file'])) : null;
                ?>
                <details class="lts-doc" data-doctor="<?= $id ?>">
                    <summary>
                        <span class="lts-doc-name"><?= $h($s['doctor_name']) ?><?= (int)$s['is_active'] === 1 ? '' : ' <small>(ناچالاک)</small>' ?></span>
                        <span class="lts-doc-chips">
                            <span class="lts-chip <?= $written ? 'is-ok' : '' ?>"><?= $written ? 'نووسراوە' : 'هێشتا نەنووسراوە' ?></span>
                            <?php if ($s['has_signature']): ?><span class="lts-chip is-ok"><i class="fas fa-signature"></i> واژۆ</span><?php endif; ?>
                        </span>
                    </summary>
                    <div class="lts-doc-body">
                        <input type="hidden" name="doc[<?= $id ?>][doctor]" value="<?= $h($s['doctor_name']) ?>">
                        <div class="form-group">
                            <label>ناو لە نوسراودا</label>
                            <input type="text" name="doc[<?= $id ?>][name]" class="form-control" maxlength="150" value="<?= $h($s['signer_name']) ?>"
                                   placeholder="<?= $h($s['doctor_name']) ?>">
                            <div class="form-hint">بەتاڵ = ناوی دکتۆرەکە وەک لە سیستەمدایە. بۆ نوسراوی ئینگلیزی/عەرەبی ناوەکە بەو زمانە بنووسە.</div>
                        </div>
                        <div class="form-group">
                            <label>دێڕەکانی ژێر ناو <small>(هەر دێڕێک لە دێڕێکدا)</small></label>
                            <textarea name="doc[<?= $id ?>][title]" class="form-control" rows="3" maxlength="255"
                                      placeholder="Specialist Orthodontist&#10;BDS, MSc in Orthodontics&#10;Lezan Dental Clinics"><?= $h($s['signer_title']) ?></textarea>
                        </div>
                        <?php $imageSlot('docsig_' . $id, $sigUrl, $s['has_signature'], 'docsig_remove[' . $id . ']', 'واژۆی ' . $s['doctor_name'], 'PNG بە باکگراوندی ڕوون باشترە. تەنها لەسەر نوسراوەکانی ئەم دکتۆرە دادەنرێت.'); ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-hashtag"></i> ژمارەی نوسراو</h3></div>
            <div class="panel-body">
                <div class="form-group">
                    <label>شێوەی ژمارە</label>
                    <input type="text" name="cfg[letter_number_format]" id="ltsFormat" class="form-control" maxlength="40" value="<?= $h($cfg['letter_number_format']) ?>">
                    <div class="form-hint">{n} = ژمارەی زنجیرە، {y} = ساڵ، {m} = مانگ. نموونە: <code>{n}</code> یان <code>ل/{n}/{y}</code><br>نوسراوی داهاتوو: <b id="ltsFormatOut"></b></div>
                </div>
                <div class="lts-checks">
                    <label><input type="hidden" name="cfg[letter_number_yearly]" value="0"><input type="checkbox" name="cfg[letter_number_yearly]" value="1" <?= $checked('letter_number_yearly') ?>> ژمارەکان هەموو ساڵێک لە ١ەوە دەست پێبکەنەوە</label>
                </div>
                <div class="form-hint">بۆ دەستپێکردن لە ژمارەیەکی دیاریکراو (نموونە ١٥٠)، لە نوسراوی داهاتوودا ژمارەکە بە دەست بنووسە؛ دواتر خۆکار لەوێوە بەردەوام دەبێت.</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-pen-nib"></i> بنەڕەتەکانی نوسراوی نوێ</h3></div>
            <div class="panel-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>دەستپێکی فەرمی</label>
                        <input type="text" name="cfg[letter_default_greeting]" class="form-control" maxlength="255" value="<?= $h($cfg['letter_default_greeting']) ?>">
                    </div>
                    <div class="form-group">
                        <label>کۆتایی</label>
                        <input type="text" name="cfg[letter_default_closing]" class="form-control" maxlength="255" value="<?= $h($cfg['letter_default_closing']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>قەبارەی نووسین</label>
                    <select name="cfg[letter_font_size]" class="form-control">
                        <?php foreach ([14 => 'بچووک', 15 => '', 16 => 'ئاسایی', 17 => '', 18 => 'گەورە', 19 => ''] as $size => $label): ?>
                        <option value="<?= $size ?>" <?= $selected('letter_font_size', (string)$size) ?>><?= $size ?><?= $label ? ' — ' . $label : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="lts-save">
            <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> هەڵگرتنی ڕێکخستنەکان</button>
            <span class="form-hint">وێنە نوێیەکان دوای هەڵگرتن لە پێشبینیندا دەردەکەون.</span>
        </div>
    </form>

    <?php
    $previewFormId = 'ltsForm';
    $previewExtra = ['sample' => '1'];
    $previewTitle = 'نموونەی نوسراو بەم سەردێڕە';
    require __DIR__ . '/_preview_pane.php';
    ?>
</div>

<style>
.lts-layout { display: grid; grid-template-columns: minmax(400px, 540px) minmax(0, 1fr); gap: 18px; align-items: start; }
.lts-layout .panel { margin-bottom: 16px; }
.lts-checks { display: flex; flex-direction: column; gap: 8px; margin: 6px 0 12px; font-size: 13px; }
.lts-checks label { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
.lts-img { display: flex; gap: 12px; align-items: flex-start; padding: 12px; margin-top: 10px; border: 1px dashed #d1d5db; border-radius: 10px; background: #fafbfc; }
.lts-img-thumb { width: 110px; height: 72px; flex-shrink: 0; display: grid; place-items: center; border-radius: 8px; background: #fff; border: 1px solid #e5e7eb; overflow: hidden;
    background-image: linear-gradient(45deg, #f3f4f6 25%, transparent 25%, transparent 75%, #f3f4f6 75%), linear-gradient(45deg, #f3f4f6 25%, transparent 25%, transparent 75%, #f3f4f6 75%);
    background-size: 12px 12px; background-position: 0 0, 6px 6px; }
.lts-img-thumb img { max-width: 100%; max-height: 100%; object-fit: contain; }
.lts-img-thumb span { color: #cbd5e1; font-size: 24px; }
.lts-img-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.lts-img-body b { font-size: 13.5px; }
.lts-remove { font-size: 12.5px; color: #b91c1c; display: inline-flex; gap: 6px; align-items: center; cursor: pointer; }
.lts-note { font-size: 12.5px; color: #475569; background: #f1f5f9; border-radius: 8px; padding: 8px 10px; line-height: 1.7; }
.lts-color { height: 40px; padding: 4px; max-width: 120px; }
.lts-save { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; position: sticky; bottom: 0; padding: 12px 0; background: linear-gradient(to top, var(--bg-primary, #f3f4f6) 70%, transparent); z-index: 2; }
/* «واژۆی دکتۆرەکان»: one card per doctor */
.lts-doc { margin-top: 10px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
.lts-doc[open] { border-color: #93c5fd; box-shadow: 0 0 0 3px #eff6ff; }
.lts-doc summary { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; cursor: pointer; list-style: none; }
.lts-doc summary::-webkit-details-marker { display: none; }
.lts-doc summary::before { content: '\f053'; font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free'; font-weight: 900; font-size: 11px; color: #94a3b8; transition: transform .15s; }
.lts-doc[open] summary::before { transform: rotate(-90deg); }
.lts-doc-name { flex: 1; font-weight: 700; font-size: 13.5px; color: #1f2937; }
.lts-doc-name small { font-weight: 400; color: #94a3b8; }
.lts-doc-chips { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.lts-chip { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; background: #fef3c7; color: #92400e; }
.lts-chip.is-ok { background: #dcfce7; color: #166534; }
.lts-doc-body { padding: 4px 12px 12px; border-top: 1px solid #f1f5f9; }
@media (max-width: 1100px) { .lts-layout { grid-template-columns: 1fr; } }
</style>

<script>
(function () {
    var form = document.getElementById('ltsForm');

    // «نوسراوی داهاتوو» example under the number pattern.
    var format = document.getElementById('ltsFormat');
    var out = document.getElementById('ltsFormatOut');
    function syncFormat() {
        var f = format.value.trim();
        if (f.indexOf('{n}') === -1) { out.textContent = 'دەبێت {n} ی تێدا بێت'; return; }
        var d = new Date();
        out.textContent = f.split('{n}').join('12').split('{y}').join(String(d.getFullYear())).split('{m}').join(String(d.getMonth() + 1));
    }
    format.addEventListener('input', syncFormat);
    syncFormat();

    // The sample letter is signed by the doctor whose card is open (else by the centre).
    var sample = document.getElementById('ltsSampleDoctor');
    function showDoctor(id) {
        if (sample.value === id) return;
        sample.value = id;
        if (window.ltpRefresh) window.ltpRefresh();
    }
    form.querySelectorAll('.lts-doc').forEach(function (card) {
        card.addEventListener('toggle', function () {
            if (card.open) {
                form.querySelectorAll('.lts-doc[open]').forEach(function (other) { if (other !== card) other.open = false; });
                showDoctor(card.dataset.doctor);
            } else if (sample.value === card.dataset.doctor) {
                showDoctor('');
            }
        });
        card.addEventListener('focusin', function () { showDoctor(card.dataset.doctor); });
    });
    // Opened from a letter's «نووسینی واژۆی دکتۆرەکان» link.
    if (location.hash === '#ltsDoctors') {
        document.getElementById('ltsDoctors').scrollIntoView({ block: 'start' });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
