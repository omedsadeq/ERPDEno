<?php
/**
 * ئەرشیفی نوسراوە فەرمییەکان — every letter sent, newest first, findable by
 * number, recipient, subject or text, and by date/year/status.
 *
 * A letter is never renumbered: «هەڵوەشاندنەوە» keeps it (and its number) in
 * the archive, struck out, with the reason. Only an admin may delete one
 * outright — for a letter saved by mistake that was never sent.
 */

require_once __DIR__ . '/_bootstrap.php';
letters_require('letters.view');

$canEdit = $perm->can('letters.create');
$isAdminUser = $auth->isAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = (string)($_POST['action'] ?? '');
    $lid = (int)($_POST['letter_id'] ?? 0);
    $back = APP_URL . '/letters/index.php' . (!empty($_POST['return']) && $_POST['return'][0] === '?' ? $_POST['return'] : '');
    if ($lid > 0 && $canEdit && $action === 'cancel') {
        $letters->cancel($lid, (string)($_POST['reason'] ?? ''), $letterUserId);
        $back .= (strpos($back, '?') === false ? '?' : '&') . 'cancelled=1';
    } elseif ($lid > 0 && $canEdit && $action === 'restore') {
        $letters->restore($lid, $letterUserId);
        $back .= (strpos($back, '?') === false ? '?' : '&') . 'restored=1';
    } elseif ($lid > 0 && $isAdminUser && $action === 'delete') {
        $letters->delete($lid);
        $back .= (strpos($back, '?') === false ? '?' : '&') . 'deleted=1';
    }
    header('Location: ' . $back);
    exit;
}

$filters = [
    'q'      => trim((string)($_GET['q'] ?? '')),
    'year'   => (int)($_GET['year'] ?? 0),
    'from'   => (string)($_GET['from'] ?? ''),
    'to'     => (string)($_GET['to'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
];
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
[$rows, $total] = $letters->search($filters, $page, $perPage);
$pages = max(1, (int)ceil($total / $perPage));
$stats = $letters->stats();
$years = $letters->years();
$cfg = $letters->settings();
$digits = $cfg['letter_digits'];
$filtered = $filters['q'] !== '' || $filters['year'] || $filters['from'] !== '' || $filters['to'] !== '' || $filters['status'] !== '';

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$qs = function (array $over) use ($filters, $page) {
    $p = array_filter($filters + ['page' => $page], function ($v) { return $v !== '' && $v !== 0; });
    foreach ($over as $k => $v) {
        $p[$k] = $v;
    }
    return '?' . http_build_query($p);
};
$returnQs = $qs([]);

$pageTitle = 'نوسراوی فەرمی';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['cancelled'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> نوسراوەکە هەڵوەشێنرایەوە (لە ئەرشیفدا دەمێنێتەوە).</div>
<?php elseif (isset($_GET['restored'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> نوسراوەکە گەڕێنرایەوە.</div>
<?php elseif (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> نوسراوەکە سڕایەوە.</div>
<?php elseif (isset($_GET['missing'])): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle alert-icon"></i> نوسراوەکە نەدۆزرایەوە.</div>
<?php endif; ?>

<div class="lta-stats">
    <div class="lta-stat"><span><?= number_format($stats['total'] ?? 0) ?></span><small>کۆی نوسراوەکان</small></div>
    <div class="lta-stat"><span><?= number_format($stats['this_year'] ?? 0) ?></span><small>ئەمساڵ</small></div>
    <div class="lta-stat"><span><?= number_format($stats['this_month'] ?? 0) ?></span><small>ئەم مانگە</small></div>
    <div class="lta-stat is-muted"><span><?= number_format($stats['cancelled'] ?? 0) ?></span><small>هەڵوەشێنراوە</small></div>
</div>

<div class="panel">
    <div class="panel-header lta-head">
        <h3 class="panel-title" style="margin:0;"><i class="fas fa-archive"></i> ئەرشیفی نوسراوەکان</h3>
        <?php if ($canEdit): ?>
        <a href="<?= APP_URL ?>/letters/edit.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> نوسراوی نوێ</a>
        <?php endif; ?>
    </div>
    <form method="GET" class="lta-filters">
        <input type="search" name="q" value="<?= $h($filters['q']) ?>" class="form-control lta-q"
               placeholder="گەڕان بە ژمارە، لایەنی پەیوەندیدار، بابەت یان دەق...">
        <select name="year" class="form-control" aria-label="ساڵ">
            <option value="">هەموو ساڵەکان</option>
            <?php foreach ($years as $y): ?>
            <option value="<?= $y ?>" <?= $filters['year'] === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
        <label class="lta-date">لە <input type="date" name="from" value="<?= $h($filters['from']) ?>" class="form-control"></label>
        <label class="lta-date">بۆ <input type="date" name="to" value="<?= $h($filters['to']) ?>" class="form-control"></label>
        <select name="status" class="form-control" aria-label="دۆخ">
            <option value="">هەموو دۆخەکان</option>
            <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>چالاک</option>
            <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>هەڵوەشێنراوە</option>
        </select>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> گەڕان</button>
        <?php if ($filtered): ?><a href="<?= APP_URL ?>/letters/index.php" class="btn btn-subtle">پاککردنەوە</a><?php endif; ?>
    </form>

    <div class="panel-body" style="padding:0;">
        <?php if (!$rows): ?>
        <div class="lta-empty">
            <i class="fas fa-envelope-open-text"></i>
            <p><?= $filtered ? 'هیچ نوسراوێک بەم گەڕانە نەدۆزرایەوە.' : 'هێشتا هیچ نوسراوێکی فەرمی نەنووسراوە.' ?></p>
            <?php if (!$filtered && $canEdit): ?>
            <a href="<?= APP_URL ?>/letters/edit.php" class="btn btn-primary"><i class="fas fa-plus"></i> یەکەم نوسراو بنووسە</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="lta-scroll">
            <table class="data-grid lta-table">
                <thead>
                    <tr>
                        <th>ژمارە</th>
                        <th>ڕێکەوت</th>
                        <th>بۆ</th>
                        <th>بابەت</th>
                        <th>واژۆکەر</th>
                        <th>نووسەر</th>
                        <th style="width:1%;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $view = APP_URL . '/letters/print.php?id=' . (int)$r['id'];
                        $isCancelled = $r['status'] === 'cancelled';
                    ?>
                    <tr class="<?= $isCancelled ? 'is-cancelled' : '' ?>">
                        <td><a href="<?= $view ?>" class="lta-num"><bdi><?= $h(lt_digits($r['letter_number'], $digits)) ?></bdi></a></td>
                        <td><bdi><?= $h(lt_digits(date('Y/m/d', strtotime($r['letter_date'])), $digits)) ?></bdi></td>
                        <td class="lta-wrap"><?= $h($r['recipient']) ?></td>
                        <td class="lta-wrap">
                            <a href="<?= $view ?>" class="lta-subject"><?= $h($r['subject']) ?></a>
                            <?php if ($isCancelled): ?>
                            <div><span class="badge badge-red">هەڵوەشێنراوە</span>
                                <?php if ($r['cancel_reason']): ?><small class="lta-sub"><?= $h($r['cancel_reason']) ?></small><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $h($r['signer_name'] ?? '') ?></td>
                        <td><span class="lta-sub"><?= $h($r['created_by_name'] ?? '—') ?></span></td>
                        <td>
                            <div class="lta-actions">
                                <a href="<?= $view ?>" class="btn btn-sm btn-primary" title="بینین و چاپکردن"><i class="fas fa-print"></i></a>
                                <a href="<?= APP_URL ?>/letters/pdf.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-danger" title="PDF"><i class="fas fa-file-pdf"></i></a>
                                <?php if ($canEdit): ?>
                                <a href="<?= APP_URL ?>/letters/edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-secondary" title="دەستکاری"><i class="fas fa-pen"></i></a>
                                <a href="<?= APP_URL ?>/letters/edit.php?copy=<?= (int)$r['id'] ?>" class="btn btn-sm btn-secondary" title="کۆپی بۆ نوسراوی نوێ"><i class="fas fa-clone"></i></a>
                                <form method="POST" class="lta-inline" <?= $isCancelled ? '' : 'onsubmit="return ltaCancel(this)"' ?>>
                                    <input type="hidden" name="action" value="<?= $isCancelled ? 'restore' : 'cancel' ?>">
                                    <input type="hidden" name="letter_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="reason" value="">
                                    <input type="hidden" name="return" value="<?= $h($returnQs) ?>">
                                    <?php if ($isCancelled): ?>
                                    <button type="submit" class="btn btn-sm btn-success" title="گەڕاندنەوە"><i class="fas fa-undo"></i></button>
                                    <?php else: ?>
                                    <button type="submit" class="btn btn-sm btn-outline" title="هەڵوەشاندنەوە"><i class="fas fa-ban"></i></button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>
                                <?php if ($isAdminUser): ?>
                                <form method="POST" class="lta-inline" onsubmit="return confirm('نوسراوەکە بە یەکجاری بسڕدرێتەوە؟ ئەم کارە ناگەڕێتەوە.\nئەگەر نوسراوەکە نێردراوە، باشترە هەڵیبوەشێنیتەوە.')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="letter_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="return" value="<?= $h($returnQs) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="سڕینەوە"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="lta-foot">
            <span class="lta-sub"><?= number_format($total) ?> نوسراو</span>
            <?php if ($pages > 1): ?>
            <nav class="lta-pages" aria-label="پەڕەکان">
                <?php if ($page > 1): ?><a href="<?= $h($qs(['page' => $page - 1])) ?>" class="btn btn-sm btn-secondary">پێشوو</a><?php endif; ?>
                <span><?= $page ?> / <?= $pages ?></span>
                <?php if ($page < $pages): ?><a href="<?= $h($qs(['page' => $page + 1])) ?>" class="btn btn-sm btn-secondary">دواتر</a><?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.lta-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
.lta-stat { background: #fff; border: 1px solid #e6ebf1; border-radius: 12px; padding: 14px 16px; display: flex; flex-direction: column; gap: 2px; }
.lta-stat span { font-size: 24px; font-weight: 800; color: #1f3b63; }
.lta-stat small { color: #6b7280; font-size: 12.5px; }
.lta-stat.is-muted span { color: #9ca3af; }
.lta-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.lta-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; border-bottom: 1px solid #eef1f4; background: #fafbfc; }
.lta-filters .form-control { width: auto; }
.lta-filters .lta-q { flex: 1 1 260px; min-width: 200px; }
.lta-date { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #4b5563; margin: 0; }
.lta-scroll { overflow-x: auto; }
.lta-table td { vertical-align: top; font-size: 13.5px; }
.lta-wrap { min-width: 160px; max-width: 320px; }
.lta-num { font-weight: 800; color: #1f3b63; }
.lta-subject { font-weight: 600; color: #111827; }
.lta-sub { font-size: 12px; color: #6b7280; }
.lta-actions { display: flex; gap: 4px; flex-wrap: nowrap; }
.lta-actions .btn { padding: 4px 8px; }
.lta-inline { display: inline; margin: 0; }
tr.is-cancelled td { color: #9ca3af; }
tr.is-cancelled .lta-subject, tr.is-cancelled .lta-num { text-decoration: line-through; color: #9ca3af; }
.lta-empty { text-align: center; padding: 56px 16px; color: #6b7280; }
.lta-empty i { font-size: 40px; color: #c7d2fe; margin-bottom: 12px; display: block; }
.lta-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 16px; border-top: 1px solid #eef1f4; }
.lta-pages { display: flex; align-items: center; gap: 10px; font-size: 13px; }
</style>

<script>
function ltaCancel(form) {
    var reason = prompt('هۆکاری هەڵوەشاندنەوەی نوسراو (ئارەزوومەندانە):', '');
    if (reason === null) return false;
    form.elements['reason'].value = reason;
    return true;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
