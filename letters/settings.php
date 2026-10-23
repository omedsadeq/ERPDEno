<?php
/**
 * سەردێڕ و واژۆ — the letterhead every letter is printed on.
 *
 * Header: logo + text on both sides (Kurdish right, English left, a line
 * under the logo), or one designed letterhead image. Footer: text lines, an
 * image, or nothing. Plus the signature and seal images, how letters are
 * numbered, the default greeting/closing/signer, the colour and text size.
 *
 * Everything is stored in `settings` (letter_* keys); images go to
 * uploads/letters/ and are served only through letters/asset.php. The sheet
 * on the side redraws a sample letter with the unsaved values as you edit.
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.settings');

$cfg = $letters->settings();
$error = '';

$textKeys = [
    'letter_header_right', 'letter_header_center', 'letter_header_left', 'letter_footer_text',
    'letter_number_format', 'letter_default_greeting', 'letter_default_closing',
    'letter_default_signer_name', 'letter_default_signer_title',
];
$choiceKeys = [
    'letter_header_mode', 'letter_footer_mode', 'letter_digits', 'letter_signature_side',
    'letter_accent_color', 'letter_font_size',
];
$flagKeys = ['letter_show_logo', 'letter_header_line', 'letter_number_yearly', 'letter_kurdish_year'];

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
        foreach (OfficialLetter::ASSETS as $kind => $key) {
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
        foreach (array_merge($textKeys, $choiceKeys, $flagKeys, array_values(OfficialLetter::ASSETS)) as $k) {
            $save[$k] = $next[$k];
        }
        $letters->saveSettings($save);
        foreach ($replaced as $kind) {
            $letters->removeAssetFile($kind, $cfg);
        }
        if (class_exists('AuditLog')) {
            AuditLog::log('letter_settings_update', 'settings', null);
        }
        header('Location: ' . APP_URL . '/letters/settings.php?saved=1');
        exit;
    } catch (InvalidArgumentException | RuntimeException $ex) {
        foreach ($stored as $name) {
            @unlink(OfficialLetter::assetDir() . '/' . $name);
        }
        $error = $ex->getMessage();
        $cfg = array_merge($cfg, array_diff_key($next, array_flip(OfficialLetter::ASSETS)));
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
$radio = function ($key, $value) use ($cfg) { return $cfg[$key] === $value ? 'checked' : ''; };

/** One image slot: current thumbnail, file picker, remove box. */
$imageSlot = function (string $kind, string $label, string $hint) use ($urls, $has, $h) {
    ?>
    <div class="lts-img">
        <div class="lts-img-thumb">
            <?php if ($urls[$kind]): ?>
            <img src="<?= $h($urls[$kind]) ?>" alt="">
            <?php else: ?>
            <span><i class="fas fa-image"></i></span>
            <?php endif; ?>
        </div>
        <div class="lts-img-body">
            <b><?= $h($label) ?></b>
            <input type="file" name="file_<?= $kind ?>" accept="image/png,image/jpeg" class="form-control">
            <div class="form-hint"><?= $h($hint) ?></div>
            <?php if ($has[$kind]): ?>
            <label class="lts-remove"><input type="checkbox" name="remove_<?= $kind ?>" value="1"> لابردنی ئەم وێنەیە</label>
            <?php endif; ?>
        </div>
    </div>
    <?php
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

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-heading"></i> سەردێڕ (سەرەوەی پەڕە)</h3></div>
            <div class="panel-body">
                <div class="lts-modes">
                    <label><input type="radio" name="cfg[letter_header_mode]" value="text" <?= $radio('letter_header_mode', 'text') ?>> لۆگۆ و نووسین</label>
                    <label><input type="radio" name="cfg[letter_header_mode]" value="image" <?= $radio('letter_header_mode', 'image') ?>> وێنەی ئامادەکراوی سەردێڕ</label>
                </div>
                <div class="lts-when" data-when="letter_header_mode=text">
                    <div class="form-row">
                        <div class="form-group">
                            <label>لای ڕاست (کوردی/عەرەبی)</label>
                            <textarea name="cfg[letter_header_right]" class="form-control" rows="4"><?= $h($cfg['letter_header_right']) ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>لای چەپ (ئینگلیزی)</label>
                            <textarea name="cfg[letter_header_left]" class="form-control" rows="4" dir="ltr" style="text-align:left;"><?= $h($cfg['letter_header_left']) ?></textarea>
                        </div>
                    </div>
                    <div class="form-hint" style="margin-top:-6px;">هەر دێڕێک لە دێڕێکی جیادا. دێڕی یەکەم گەورەتر و تۆختر دەنووسرێت.</div>
                    <div class="form-group" style="margin-top:12px;">
                        <label>ژێر لۆگۆ (ئارەزوومەندانە)</label>
                        <input type="text" name="cfg[letter_header_center]" class="form-control" value="<?= $h($cfg['letter_header_center']) ?>">
                    </div>
                    <div class="lts-checks">
                        <label><input type="hidden" name="cfg[letter_show_logo]" value="0"><input type="checkbox" name="cfg[letter_show_logo]" value="1" <?= $checked('letter_show_logo') ?>> لۆگۆ پیشان بدە</label>
                        <label><input type="hidden" name="cfg[letter_header_line]" value="0"><input type="checkbox" name="cfg[letter_header_line]" value="1" <?= $checked('letter_header_line') ?>> هێڵ لەژێر سەردێڕ</label>
                    </div>
                    <?php $imageSlot('logo', 'لۆگۆ', $has['logo'] ? 'لۆگۆی تایبەتی نوسراو. لابردنی دەیگەڕێنێتەوە بۆ لۆگۆی سیستەم.' : 'ئێستا لۆگۆی سیستەم بەکاردێت. دەتوانیت لۆگۆیەکی تر بۆ نوسراوەکان دابنێیت.'); ?>
                </div>
                <div class="lts-when" data-when="letter_header_mode=image">
                    <?php $imageSlot('header', 'وێنەی سەردێڕ', 'وێنەیەکی پان (نموونە ٢٠٠٠×٣٠٠ پیکسڵ) بە هەموو پانی پەڕەکە دادەنرێت.'); ?>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-shoe-prints"></i> ژێرپەڕە (خوارەوەی پەڕە)</h3></div>
            <div class="panel-body">
                <div class="lts-modes">
                    <label><input type="radio" name="cfg[letter_footer_mode]" value="text" <?= $radio('letter_footer_mode', 'text') ?>> نووسین</label>
                    <label><input type="radio" name="cfg[letter_footer_mode]" value="image" <?= $radio('letter_footer_mode', 'image') ?>> وێنە</label>
                    <label><input type="radio" name="cfg[letter_footer_mode]" value="none" <?= $radio('letter_footer_mode', 'none') ?>> هیچ</label>
                </div>
                <div class="lts-when" data-when="letter_footer_mode=text">
                    <div class="form-group">
                        <textarea name="cfg[letter_footer_text]" class="form-control" rows="3"
                                  placeholder="ناونیشان، ژمارەی تەلەفۆن، ئیمەیڵ..."><?= $h($cfg['letter_footer_text']) ?></textarea>
                        <div class="form-hint">لە خوارەوەی هەموو پەڕەکان دەردەکەوێت.</div>
                    </div>
                </div>
                <div class="lts-when" data-when="letter_footer_mode=image">
                    <?php $imageSlot('footer', 'وێنەی ژێرپەڕە', 'وێنەیەکی پان؛ بەرزییەکەی تا ٣ سانتیمەتر دەبێت.'); ?>
                </div>
            </div>
        </div>

        <div class="panel" id="ltsSign">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-signature"></i> واژۆ و مۆر</h3></div>
            <div class="panel-body">
                <div class="lts-note"><i class="fas fa-lock"></i> ئەم وێنانە تەنها لە ناو سیستەمەکەدا پیشان دەدرێن و لینکی گشتییان نییە. لە هەر نوسراوێکدا بە جیا هەڵدەبژێردرێن.</div>
                <?php $imageSlot('signature', 'واژۆ', 'PNG بە باکگراوندی ڕوون باشترە.'); ?>
                <?php $imageSlot('seal', 'مۆر', 'PNG بە باکگراوندی ڕوون باشترە؛ کەمێک دەکەوێتە سەر واژۆکە.'); ?>
                <div class="form-group" style="margin-top:12px;">
                    <label>شوێنی واژۆ لە پەڕەکەدا</label>
                    <select name="cfg[letter_signature_side]" class="form-control">
                        <option value="left" <?= $selected('letter_signature_side', 'left') ?>>لای چەپ</option>
                        <option value="center" <?= $selected('letter_signature_side', 'center') ?>>ناوەڕاست</option>
                        <option value="right" <?= $selected('letter_signature_side', 'right') ?>>لای ڕاست</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-hashtag"></i> ژمارە و ڕێکەوت</h3></div>
            <div class="panel-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>شێوەی ژمارە</label>
                        <input type="text" name="cfg[letter_number_format]" id="ltsFormat" class="form-control" maxlength="40" value="<?= $h($cfg['letter_number_format']) ?>">
                        <div class="form-hint">{n} = ژمارەی زنجیرە، {y} = ساڵ، {m} = مانگ. نموونە: <code>{n}</code> یان <code>ل/{n}/{y}</code><br>نوسراوی داهاتوو: <b id="ltsFormatOut"></b></div>
                    </div>
                    <div class="form-group">
                        <label>ژمارەکان</label>
                        <select name="cfg[letter_digits]" class="form-control">
                            <option value="ku" <?= $selected('letter_digits', 'ku') ?>>کوردی (١٢٣)</option>
                            <option value="latin" <?= $selected('letter_digits', 'latin') ?>>ئینگلیزی (123)</option>
                        </select>
                    </div>
                </div>
                <div class="lts-checks">
                    <label><input type="hidden" name="cfg[letter_number_yearly]" value="0"><input type="checkbox" name="cfg[letter_number_yearly]" value="1" <?= $checked('letter_number_yearly') ?>> ژمارەکان هەموو ساڵێک لە ١ەوە دەست پێبکەنەوە</label>
                    <label><input type="hidden" name="cfg[letter_kurdish_year]" value="0"><input type="checkbox" name="cfg[letter_kurdish_year]" value="1" <?= $checked('letter_kurdish_year') ?>> ساڵی کوردیش بنووسە (نموونە ٢٧٢٦ی کوردی)</label>
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
                <div class="form-row">
                    <div class="form-group">
                        <label>ناوی واژۆکەر</label>
                        <input type="text" name="cfg[letter_default_signer_name]" class="form-control" maxlength="150" value="<?= $h($cfg['letter_default_signer_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label>ناونیشانی وەزیفی</label>
                        <textarea name="cfg[letter_default_signer_title]" class="form-control" rows="2" maxlength="255"><?= $h($cfg['letter_default_signer_title']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h3 class="panel-title" style="margin:0;"><i class="fas fa-palette"></i> ڕووکار</h3></div>
            <div class="panel-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>ڕەنگی سەرەکی</label>
                        <input type="color" name="cfg[letter_accent_color]" class="form-control lts-color" value="<?= $h($cfg['letter_accent_color']) ?>">
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
.lts-modes { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.lts-modes label { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; font-size: 13px; }
.lts-modes label:has(input:checked) { border-color: #1f3b63; background: #eef3fa; color: #1f3b63; font-weight: 600; }
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
.lts-note { font-size: 12.5px; color: #475569; background: #f1f5f9; border-radius: 8px; padding: 8px 10px; }
.lts-color { height: 40px; padding: 4px; max-width: 120px; }
.lts-save { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; position: sticky; bottom: 0; padding: 12px 0; background: linear-gradient(to top, var(--bg-primary, #f3f4f6) 70%, transparent); z-index: 2; }
.lts-when[hidden] { display: none; }
@media (max-width: 1100px) { .lts-layout { grid-template-columns: 1fr; } }
</style>

<script>
(function () {
    var form = document.getElementById('ltsForm');

    // Show only the fields of the chosen header/footer mode.
    function syncModes() {
        form.querySelectorAll('.lts-when').forEach(function (box) {
            var parts = box.dataset.when.split('=');
            var picked = form.querySelector('input[name="cfg[' + parts[0] + ']"]:checked');
            box.hidden = !picked || picked.value !== parts[1];
        });
    }

    // «نوسراوی داهاتوو» example under the number pattern.
    var format = document.getElementById('ltsFormat');
    var out = document.getElementById('ltsFormatOut');
    function syncFormat() {
        var f = format.value.trim();
        if (f.indexOf('{n}') === -1) { out.textContent = 'دەبێت {n} ی تێدا بێت'; return; }
        var d = new Date();
        out.textContent = f.split('{n}').join('12').split('{y}').join(String(d.getFullYear())).split('{m}').join(String(d.getMonth() + 1));
    }

    form.addEventListener('change', syncModes);
    format.addEventListener('input', syncFormat);
    syncModes();
    syncFormat();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
