<?php
/**
 * نوسراوی نوێ / دەستکاریکردنی نوسراو.
 *
 * The form keeps to the six things a letter needs — number + date, بۆ,
 * بابەت, the text, who signs, «وێنەیەک بۆ» — with the rest (reference,
 * greeting, closing, attachments) folded under «زیاتر». The A4 sheet beside
 * it redraws as you type (letters/preview.php), from the same renderer the
 * printer and the PDF use.
 *
 *   edit.php               new letter
 *   edit.php?template=ID   new letter prefilled from a template
 *   edit.php?copy=ID       new letter prefilled from an earlier one
 *   edit.php?id=ID         edit a saved letter (keeps its number)
 *
 * A new letter's number is suggested, not reserved: left as suggested it is
 * allocated at save time (OfficialLetter::create), so two people writing at
 * once never get the same number. Typed over, it is used as typed.
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.create');

$cfg = $letters->settings();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = $id > 0 ? $letters->find($id) : null;
if ($id > 0 && !$existing) {
    header('Location: ' . APP_URL . '/letters/index.php?missing=1');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    try {
        $data = OfficialLetter::fromInput($_POST);
        if ($existing) {
            $letters->update($id, $data, $cfg, $letterUserId);
            $savedId = $id;
        } else {
            $suggested = OfficialLetter::normalizeDigits(trim((string)($_POST['auto_number'] ?? '')));
            $auto = $data['letter_number'] === '' || $data['letter_number'] === $suggested;
            $savedId = $letters->create($data, $cfg, $letterUserId, $auto);
        }
        $print = ($_POST['after'] ?? '') === 'print' ? '&print=1' : '';
        header('Location: ' . APP_URL . '/letters/print.php?id=' . $savedId . '&saved=1' . $print);
        exit;
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

// ── What the form starts with ──
$blank = [
    'letter_number' => '', 'letter_date' => date('Y-m-d'), 'recipient' => '', 'subject' => '',
    'reference_text' => '', 'greeting' => $cfg['letter_default_greeting'], 'body' => '',
    'closing' => $cfg['letter_default_closing'], 'signer_name' => $cfg['letter_default_signer_name'],
    'signer_title' => $cfg['letter_default_signer_title'], 'cc_list' => '', 'attachments' => '',
    'show_signature' => 0, 'show_seal' => 0, 'template_id' => null,
];
$source = '';
if ($existing) {
    $form = array_intersect_key($existing, $blank) + $blank;
} else {
    $form = $blank;
    $tplId = (int)($_GET['template'] ?? 0);
    $copyId = (int)($_GET['copy'] ?? 0);
    if ($tplId > 0 && ($tpl = $letters->findTemplate($tplId))) {
        foreach (array_keys(OfficialLetter::TEXT_FIELDS) as $f) {
            if ((string)($tpl[$f] ?? '') !== '') {
                $form[$f] = $tpl[$f];
            }
        }
        $form['template_id'] = $tplId;
        $source = 'لە قاڵبی «' . $tpl['name'] . '»';
    } elseif ($copyId > 0 && ($copy = $letters->find($copyId))) {
        foreach (array_keys(OfficialLetter::TEXT_FIELDS) as $f) {
            $form[$f] = (string)($copy[$f] ?? '');
        }
        $form['show_signature'] = (int)$copy['show_signature'];
        $form['show_seal'] = (int)$copy['show_seal'];
        $form['template_id'] = $copy['template_id'];
        $source = 'کۆپی لە نوسراوی ژمارە ' . $copy['letter_number'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-show what was typed after a validation error.
    foreach ($blank as $f => $_) {
        if (isset($_POST[$f])) {
            $form[$f] = is_string($_POST[$f]) ? $_POST[$f] : $form[$f];
        }
    }
    $form['show_signature'] = !empty($_POST['show_signature']) ? 1 : 0;
    $form['show_seal'] = !empty($_POST['show_seal']) ? 1 : 0;
}
foreach (array_keys(OfficialLetter::TEXT_FIELDS) as $f) {
    $form[$f] = (string)($form[$f] ?? '');
}
// After a failed save keep the original suggestion: if the number field still
// shows it, the save after this one must still treat it as automatic.
$suggestion = $existing ? '' : (string)($_POST['auto_number'] ?? $letters->suggestNumber(date('Y-m-d'), $cfg));
if (!$existing && trim((string)$form['letter_number']) === '') {
    $form['letter_number'] = $suggestion;
}

$templates = $existing ? [] : $letters->templates();
$recipientOptions = $letters->suggestions('recipient');
$signerOptions = $letters->suggestions('signer_name', 50);
$hasSignature = OfficialLetter::hasUpload('signature', $cfg);
$hasSeal = OfficialLetter::hasUpload('seal', $cfg);
$canSettings = $perm->can('letters.settings');
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$pageTitle = $existing ? 'دەستکاریکردنی نوسراوی ژمارە ' . $existing['letter_number'] : 'نوسراوی نوێ';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($error !== ''): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle alert-icon"></i> <?= $h($error) ?></div>
<?php endif; ?>

<div class="ltf-layout">
    <div class="panel ltf-form-panel">
        <div class="panel-header ltf-head">
            <h3 class="panel-title" style="margin:0;">
                <i class="fas <?= $existing ? 'fa-pen' : 'fa-file-signature' ?>"></i>
                <?= $existing ? 'دەستکاریکردنی نوسراو' : 'نوسراوی نوێ' ?>
            </h3>
            <?php if ($source !== ''): ?><span class="badge badge-purple"><?= $h($source) ?></span><?php endif; ?>
            <?php if ($existing && $existing['status'] === 'cancelled'): ?><span class="badge badge-red">هەڵوەشێنراوە</span><?php endif; ?>
        </div>
        <div class="panel-body">
            <?php if ($templates): ?>
            <div class="ltf-tpl">
                <label for="ltfTpl"><i class="fas fa-copy"></i> دەستپێکردن لە قاڵب</label>
                <div class="ltf-tpl-row">
                    <select id="ltfTpl" class="form-control">
                        <option value="">— نوسراوی بەتاڵ —</option>
                        <?php foreach ($templates as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (int)$form['template_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= $h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-secondary" onclick="ltfUseTemplate()"><i class="fas fa-check"></i> بەکارهێنان</button>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" id="ltfForm" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="auto_number" value="<?= $h($suggestion) ?>">
                <input type="hidden" name="template_id" value="<?= (int)$form['template_id'] ?: '' ?>">
                <input type="hidden" name="after" id="ltfAfterField" value="view">

                <div class="ltf-step"><span>١</span> ژمارە و ڕێکەوت</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ltfNumber">ژمارەی نوسراو</label>
                        <input type="text" id="ltfNumber" name="letter_number" class="form-control" maxlength="60" required
                               value="<?= $h($form['letter_number']) ?>">
                        <?php if (!$existing): ?>
                        <div class="form-hint">ژمارەی خۆکارە؛ ئەگەر پێویست بوو بیگۆڕە.</div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="ltfDate">ڕێکەوت</label>
                        <input type="date" id="ltfDate" name="letter_date" class="form-control" required value="<?= $h($form['letter_date']) ?>">
                    </div>
                </div>

                <div class="ltf-step"><span>٢</span> بۆ کێیە؟ (لایەنی پەیوەندیدار)</div>
                <div class="form-group">
                    <input type="text" name="recipient" class="form-control" maxlength="255" required list="ltfRecipients"
                           placeholder="نموونە: بەڕێوەبەرایەتی باجی دەرامەت" value="<?= $h($form['recipient']) ?>">
                    <datalist id="ltfRecipients">
                        <?php foreach ($recipientOptions as $r): ?><option value="<?= $h($r) ?>"><?php endforeach; ?>
                    </datalist>
                    <div class="form-hint">لەسەر پەڕەکە دەنووسرێت: «بۆ / ...»</div>
                </div>

                <div class="ltf-step"><span>٣</span> بابەت</div>
                <div class="form-group">
                    <input type="text" name="subject" class="form-control" maxlength="255" required
                           placeholder="نموونە: داواکاری لێخۆشبوون لە غەرامە" value="<?= $h($form['subject']) ?>">
                </div>

                <div class="ltf-step"><span>٤</span> دەقی نوسراوەکە</div>
                <div class="form-group">
                    <textarea name="body" id="ltfBody" class="form-control ltf-body" rows="11" required
                              placeholder="بەڕێزتان ئاگادار دەکەینەوە کە..."><?= $h($form['body']) ?></textarea>
                    <div class="form-hint">دێڕێکی بەتاڵ = بڕگەیەکی نوێ. ئەو بەشانەی لە نێوان [ ] دان، لە پێشبینیندا زەرد دەبن تا پڕیان بکەیتەوە.</div>
                </div>

                <div class="ltf-step"><span>٥</span> واژۆکەر</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="ltfSigner">ناوی سیانی</label>
                        <input type="text" id="ltfSigner" name="signer_name" class="form-control" maxlength="150" list="ltfSigners"
                               placeholder="نموونە: ئارام عەلی محەمەد" value="<?= $h($form['signer_name']) ?>">
                        <datalist id="ltfSigners">
                            <?php foreach ($signerOptions as $s): ?><option value="<?= $h($s) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="ltfTitle">ناونیشانی وەزیفی</label>
                        <textarea id="ltfTitle" name="signer_title" class="form-control" rows="2" maxlength="255"
                                  placeholder="نموونە: بەڕێوەبەری گشتی"><?= $h($form['signer_title']) ?></textarea>
                    </div>
                </div>
                <div class="ltf-checks">
                    <label class="<?= $hasSignature ? '' : 'is-off' ?>">
                        <input type="checkbox" name="show_signature" value="1" <?= $form['show_signature'] ? 'checked' : '' ?> <?= $hasSignature ? '' : 'disabled' ?>>
                        <i class="fas fa-signature"></i> واژۆی ئەلیکترۆنی دابنێ
                    </label>
                    <label class="<?= $hasSeal ? '' : 'is-off' ?>">
                        <input type="checkbox" name="show_seal" value="1" <?= $form['show_seal'] ? 'checked' : '' ?> <?= $hasSeal ? '' : 'disabled' ?>>
                        <i class="fas fa-stamp"></i> مۆر دابنێ
                    </label>
                    <?php if ((!$hasSignature || !$hasSeal) && $canSettings): ?>
                    <a href="<?= APP_URL ?>/letters/settings.php#ltsSign" class="ltf-link">بارکردنی واژۆ و مۆر</a>
                    <?php endif; ?>
                </div>
                <div class="form-hint" style="margin-top:-4px;">ئەگەر هیچیان دانەنێیت، شوێنی واژۆ و مۆر بەتاڵ دەمێنێتەوە بۆ واژۆکردن بە دەست.</div>

                <div class="ltf-step"><span>٦</span> وێنەیەک بۆ <small>(ئەگەر هەبێت)</small></div>
                <div class="form-group">
                    <textarea name="cc_list" class="form-control" rows="3"
                              placeholder="هەر لایەنێک لە دێڕێکدا، نموونە:&#10;بەشی یاسایی / بۆ ئاگاداری&#10;دۆسیەی تایبەت"><?= $h($form['cc_list']) ?></textarea>
                </div>

                <details class="ltf-more" <?= ($form['reference_text'] !== '' || $form['attachments'] !== '') ? 'open' : '' ?>>
                    <summary><i class="fas fa-sliders-h"></i> زیاتر: ئاماژە، سڵاو، کۆتایی، هاوپێچ</summary>
                    <div class="form-group">
                        <label>ئاماژە بە <small>(ئەگەر وەڵامی نوسراوێکە)</small></label>
                        <input type="text" name="reference_text" class="form-control" maxlength="255"
                               placeholder="نموونە: نوسراوتان ژمارە ٤٥ لە ٢٠٢٦/١٠/١" value="<?= $h($form['reference_text']) ?>">
                        <div class="form-hint">دەقەکە بەم شێوەیە دەست پێدەکات: «ئاماژە بە ...، »</div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>دەستپێکی فەرمی</label>
                            <input type="text" name="greeting" class="form-control" maxlength="255" value="<?= $h($form['greeting']) ?>">
                        </div>
                        <div class="form-group">
                            <label>کۆتایی</label>
                            <input type="text" name="closing" class="form-control" maxlength="255" value="<?= $h($form['closing']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>هاوپێچ</label>
                        <textarea name="attachments" class="form-control" rows="2"
                                  placeholder="هەر هاوپێچێک لە دێڕێکدا"><?= $h($form['attachments']) ?></textarea>
                    </div>
                </details>

                <div class="ltf-actions">
                    <button type="submit" class="btn btn-primary" onclick="ltfAfter('view')">
                        <i class="fas fa-save"></i> پاشەکەوتکردن
                    </button>
                    <button type="submit" class="btn btn-success" onclick="ltfAfter('print')">
                        <i class="fas fa-print"></i> پاشەکەوتکردن و چاپکردن
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="ltfSaveTemplate()" title="ئەم نوسراوە وەک قاڵبێک هەڵبگرە بۆ جاری داهاتوو">
                        <i class="fas fa-copy"></i> وەک قاڵب
                    </button>
                    <a href="<?= APP_URL ?>/letters/<?= $existing ? 'print.php?id=' . (int)$id : 'index.php' ?>" class="btn btn-subtle">پاشگەزبوونەوە</a>
                </div>
            </form>
        </div>
    </div>

    <?php
    $previewFormId = 'ltfForm';
    require __DIR__ . '/_preview_pane.php';
    ?>
</div>

<style>
.ltf-layout { display: grid; grid-template-columns: minmax(380px, 470px) minmax(0, 1fr); gap: 18px; align-items: start; }
.ltf-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ltf-tpl { margin-bottom: 14px; padding: 10px 12px; border-radius: 10px; background: #f5f3ff; border: 1px solid #e9e3ff; }
.ltf-tpl label { display: block; font-size: 13px; font-weight: 600; color: #5b21b6; margin-bottom: 6px; }
.ltf-tpl-row { display: flex; gap: 8px; }
.ltf-tpl-row select { flex: 1; min-width: 0; }
.ltf-step { display: flex; align-items: center; gap: 8px; margin: 16px 0 8px; font-weight: 700; font-size: 14px; color: #1f3b63; }
.ltf-step:first-of-type { margin-top: 4px; }
.ltf-step span { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; background: #1f3b63; color: #fff; font-size: 12px; flex-shrink: 0; }
.ltf-step small { font-weight: 400; color: #6b7280; }
.ltf-body { min-height: 220px; line-height: 1.9; resize: vertical; }
.ltf-checks { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; margin-bottom: 8px; font-size: 13px; }
.ltf-checks label { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.ltf-checks label.is-off { color: #9ca3af; cursor: not-allowed; }
.ltf-link { font-size: 12.5px; }
.ltf-more { margin-top: 14px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 0 12px; }
.ltf-more summary { cursor: pointer; padding: 10px 0; font-weight: 600; font-size: 13.5px; color: #374151; }
.ltf-more[open] summary { border-bottom: 1px solid #f0f2f5; margin-bottom: 10px; }
.ltf-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #eef1f4; }
@media (max-width: 1100px) { .ltf-layout { grid-template-columns: 1fr; } }
</style>

<script>
(function () {
    var form = document.getElementById('ltfForm');
    var dirty = false, submitting = false;
    form.addEventListener('input', function () { dirty = true; });

    form.addEventListener('submit', function (ev) {
        var blanks = /\[[^\]\n]{1,80}\]/;
        var fields = ['recipient', 'subject', 'body', 'reference_text'];
        for (var i = 0; i < fields.length; i++) {
            var el = form.elements[fields[i]];
            if (el && blanks.test(el.value)) {
                if (!confirm('هێشتا خانەی [ ... ] ی پڕنەکراوە لە نوسراوەکەدا هەیە. هەر پاشەکەوتی بکەم؟')) {
                    ev.preventDefault();
                    return;
                }
                break;
            }
        }
        submitting = true;
    });
    window.addEventListener('beforeunload', function (ev) {
        if (dirty && !submitting) { ev.preventDefault(); ev.returnValue = ''; }
    });

    window.ltfAfter = function (mode) { document.getElementById('ltfAfterField').value = mode; };

    window.ltfUseTemplate = function () {
        var id = document.getElementById('ltfTpl').value;
        if (dirty && !confirm('ئەو گۆڕانکارییانەی کردووتە لادەچن. بەردەوام بم؟')) return;
        dirty = false;
        window.location.href = '<?= APP_URL ?>/letters/edit.php' + (id ? '?template=' + encodeURIComponent(id) : '');
    };

    window.ltfSaveTemplate = function () {
        var name = prompt('ناوی قاڵبەکە:', form.elements['subject'].value || '');
        if (name === null) return;
        name = name.trim();
        if (!name) { alert('ناوی قاڵب پێویستە'); return; }
        var fd = new FormData(form);
        fd.set('action', 'save');
        fd.set('name', name);
        fetch('<?= APP_URL ?>/letters/templates.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    alert('قاڵبی «' + name + '» هەڵگیرا. لە نوسراوی داهاتوودا لە لیستی قاڵبەکان هەڵیبژێرە.');
                } else {
                    alert((res && res.message) || 'هەڵە ڕوویدا');
                }
            })
            .catch(function () { alert('هەڵە لە پەیوەندی'); });
    };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
