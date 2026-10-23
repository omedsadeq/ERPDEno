<?php
/**
 * قاڵبەکانی نوسراو — reusable letters. A template is a letter without a
 * number or date: picking it on letters/edit.php fills the form, and the
 * writer only changes what differs. Text in [ ] marks a blank to fill in;
 * the preview highlights any left behind.
 *
 * Also answers «وەک قاڵب» from the letter form (AJAX, action=save).
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.create');

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$error = '';
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = (string)($_POST['action'] ?? '');
    // Not 'id': the letter form posts its own letter id under that name.
    $tplId = (int)($_POST['template_edit_id'] ?? 0);
    try {
        if ($action === 'save') {
            $data = OfficialLetter::fromInput($_POST, true);
            $savedId = $letters->saveTemplate($data, $letterUserId, $tplId);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'id' => $savedId], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: ' . APP_URL . '/letters/templates.php?saved=1');
            exit;
        }
        if ($action === 'delete' && $tplId > 0) {
            $letters->deleteTemplate($tplId);
            header('Location: ' . APP_URL . '/letters/templates.php?deleted=1');
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = $ex->getMessage();
        $editId = $tplId;
    }
}

$templates = $letters->templates();
$cfg = $letters->settings();
$editing = $editId > 0 ? $letters->findTemplate($editId) : null;
$form = ['name' => ''];
foreach (array_keys(OfficialLetter::TEXT_FIELDS) as $f) {
    $form[$f] = '';
}
if ($editing) {
    foreach ($form as $f => $_) {
        $form[$f] = (string)($editing[$f] ?? '');
    }
}
if ($error !== '') {
    foreach ($form as $f => $_) {
        $form[$f] = (string)($_POST[$f] ?? '');
    }
}
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$pageTitle = 'قاڵبەکانی نوسراو';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> قاڵبەکە هەڵگیرا.</div>
<?php elseif (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> قاڵبەکە سڕایەوە.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle alert-icon"></i> <?= $h($error) ?></div>
<?php endif; ?>

<div class="ltt-layout">
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title" style="margin:0;"><i class="fas fa-copy"></i> قاڵبەکان <span class="badge badge-purple"><?= count($templates) ?></span></h3>
        </div>
        <div class="panel-body" style="padding:0;">
            <?php if (!$templates): ?>
            <div class="ltt-empty"><i class="fas fa-copy"></i><p>هیچ قاڵبێک نییە. یەکەم قاڵب لە فۆرمەکەی تەنیشتەوە دروست بکە، یان لە کاتی نووسینی نوسراودا «وەک قاڵب» دابگرە.</p></div>
            <?php else: ?>
            <div class="ltt-list">
                <?php foreach ($templates as $t): ?>
                <div class="ltt-item <?= $editing && (int)$editing['id'] === (int)$t['id'] ? 'is-editing' : '' ?>">
                    <div class="ltt-main">
                        <b><?= $h($t['name']) ?></b>
                        <div class="ltt-sub">
                            <?php if ($t['recipient']): ?><span><i class="fas fa-building"></i> <?= $h($t['recipient']) ?></span><?php endif; ?>
                            <?php if ($t['subject']): ?><span><i class="fas fa-tag"></i> <?= $h($t['subject']) ?></span><?php endif; ?>
                            <span><i class="fas fa-file-alt"></i> <?= (int)$t['use_count'] ?> جار بەکارهاتووە</span>
                        </div>
                        <?php if ($t['body']): ?><div class="ltt-body"><?= $h(mb_substr($t['body'], 0, 180)) ?><?= mb_strlen($t['body']) > 180 ? '…' : '' ?></div><?php endif; ?>
                    </div>
                    <div class="ltt-actions">
                        <a href="<?= APP_URL ?>/letters/edit.php?template=<?= (int)$t['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-file-signature"></i> نوسراوی نوێ</a>
                        <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-secondary" title="دەستکاری"><i class="fas fa-pen"></i></a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('قاڵبی «<?= $h(addslashes($t['name'])) ?>» بسڕدرێتەوە؟ ئەو نوسراوانەی لێی دروستکراون دەمێننەوە.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="template_edit_id" value="<?= (int)$t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="سڕینەوە"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title" style="margin:0;">
                <i class="fas <?= $editing ? 'fa-pen' : 'fa-plus-circle' ?>"></i>
                <?= $editing ? 'دەستکاریکردنی قاڵب' : 'قاڵبی نوێ' ?>
            </h3>
        </div>
        <div class="panel-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="template_edit_id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
                <div class="form-group">
                    <label>ناوی قاڵب <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="150" value="<?= $h($form['name']) ?>"
                           placeholder="نموونە: پشتگیری کارکردن">
                </div>
                <div class="form-group">
                    <label>بۆ (لایەنی پەیوەندیدار)</label>
                    <input type="text" name="recipient" class="form-control" maxlength="255" value="<?= $h($form['recipient']) ?>">
                </div>
                <div class="form-group">
                    <label>بابەت</label>
                    <input type="text" name="subject" class="form-control" maxlength="255" value="<?= $h($form['subject']) ?>">
                </div>
                <div class="form-group">
                    <label>دەق</label>
                    <textarea name="body" class="form-control" rows="9" style="line-height:1.9;"><?= $h($form['body']) ?></textarea>
                    <div class="form-hint">بۆ خانەیەک کە هەموو جارێک دەگۆڕێت [ ] بەکاربهێنە، نموونە: [ناوی نەخۆش].</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>واژۆکەر</label>
                        <input type="text" name="signer_name" class="form-control" maxlength="150" value="<?= $h($form['signer_name']) ?>"
                               placeholder="<?= $h($cfg['letter_default_signer_name'] ?: 'بنەڕەتی سەردێڕ') ?>">
                    </div>
                    <div class="form-group">
                        <label>ناونیشانی وەزیفی</label>
                        <textarea name="signer_title" class="form-control" rows="2" maxlength="255"
                                  placeholder="<?= $h($cfg['letter_default_signer_title'] ?: 'بنەڕەتی سەردێڕ') ?>"><?= $h($form['signer_title']) ?></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label>وێنەیەک بۆ</label>
                    <textarea name="cc_list" class="form-control" rows="2" placeholder="هەر لایەنێک لە دێڕێکدا"><?= $h($form['cc_list']) ?></textarea>
                </div>
                <details class="ltf-more" <?= ($form['reference_text'] . $form['greeting'] . $form['closing'] . $form['attachments']) !== '' ? 'open' : '' ?>>
                    <summary>زیاتر: ئاماژە، سڵاو، کۆتایی، هاوپێچ</summary>
                    <div class="form-group">
                        <label>ئاماژە بە</label>
                        <input type="text" name="reference_text" class="form-control" maxlength="255" value="<?= $h($form['reference_text']) ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>دەستپێکی فەرمی</label>
                            <input type="text" name="greeting" class="form-control" maxlength="255" value="<?= $h($form['greeting']) ?>"
                                   placeholder="<?= $h($cfg['letter_default_greeting']) ?>">
                        </div>
                        <div class="form-group">
                            <label>کۆتایی</label>
                            <input type="text" name="closing" class="form-control" maxlength="255" value="<?= $h($form['closing']) ?>"
                                   placeholder="<?= $h($cfg['letter_default_closing']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>هاوپێچ</label>
                        <textarea name="attachments" class="form-control" rows="2"><?= $h($form['attachments']) ?></textarea>
                    </div>
                </details>
                <div class="form-hint" style="margin-top:8px;">خانە بەتاڵەکان لە کاتی بەکارهێنانی قاڵبدا بە بنەڕەتەکانی سەردێڕ پڕدەکرێنەوە.</div>
                <div style="display:flex; gap:8px; margin-top:16px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;"><i class="fas fa-save"></i> هەڵگرتن</button>
                    <?php if ($editing): ?><a href="<?= APP_URL ?>/letters/templates.php" class="btn btn-subtle">پاشگەزبوونەوە</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.ltt-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(340px, 460px); gap: 18px; align-items: start; }
.ltt-list { display: flex; flex-direction: column; }
.ltt-item { display: flex; gap: 12px; justify-content: space-between; align-items: flex-start; padding: 14px 16px; border-bottom: 1px solid #f0f2f5; }
.ltt-item:last-child { border-bottom: 0; }
.ltt-item.is-editing { background: #f5f3ff; }
.ltt-main { min-width: 0; }
.ltt-main b { font-size: 14.5px; color: #111827; }
.ltt-sub { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 4px; font-size: 12px; color: #6b7280; }
.ltt-sub i { color: #a78bfa; }
.ltt-body { margin-top: 6px; font-size: 12.5px; color: #4b5563; line-height: 1.7; white-space: pre-line; }
.ltt-actions { display: flex; gap: 4px; flex-shrink: 0; }
.ltt-empty { text-align: center; padding: 48px 16px; color: #6b7280; }
.ltt-empty i { font-size: 36px; color: #c4b5fd; margin-bottom: 10px; display: block; }
.ltf-more { margin-top: 6px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 0 12px; }
.ltf-more summary { cursor: pointer; padding: 10px 0; font-weight: 600; font-size: 13.5px; color: #374151; }
@media (max-width: 1000px) { .ltt-layout { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
