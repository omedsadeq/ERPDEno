<?php
/**
 * داخستنی مانگ — close a month of the accounts once they are done
 * (classes/PeriodClose.php).
 *
 *   ?month=Y-m   the month (the last finished one by default)
 *
 * An open, finished month: what is still waiting in it, the sheet as it is now,
 * and «داخستنی مانگ» (income.month_close). A closed month: who closed it and
 * when, the frozen copy of its sheet (printable), whether the sheet still
 * agrees with it, and «کردنەوە» with a reason (the admin). Below: every
 * closing and reopening.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/Csrf.php';
require_once __DIR__ . '/../classes/PeriodClose.php';
require_once __DIR__ . '/../doctors/_daily_summary_lib.php';
require_once __DIR__ . '/_month_close_lib.php';

$auth = new Auth();
$auth->requireLogin();
$perm = Permission::getInstance();
if (!$perm->can('page.accountant_financial')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$userId = (int)$auth->getCurrentUserId();
$canClose = $perm->can(PeriodClose::PERMISSION) && PeriodClose::available();
$canReopen = $auth->isAdmin() && PeriodClose::available();

$month = (string)($_REQUEST['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m', strtotime('first day of last month'));
}
$here = fn(array $q = []) => APP_URL . '/accountant/month-close.php?' . http_build_query(['month' => $month] + $q, '', '&');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'close') {
            if (!$canClose) {
                throw new InvalidArgumentException('دەسەڵاتی داخستنی مانگت نییە');
            }
            $r = PeriodClose::close($month, month_close_sheet($month), (string)($_POST['note'] ?? ''), $userId);
            header('Location: ' . $here(['done' => $r['guard'] === null ? 'closed' : 'closed_noguard']));
            exit;
        }
        if ($action === 'reopen') {
            if (!$canReopen) {
                throw new InvalidArgumentException('تەنها بەڕێوەبەر مانگ دەکاتەوە');
            }
            PeriodClose::reopen($month, (string)($_POST['reason'] ?? ''), $userId);
            header('Location: ' . $here(['done' => 'reopened']));
            exit;
        }
        if ($action === 'guards' && $canReopen) {
            $err = PeriodClose::installGuards();
            header('Location: ' . $here(['done' => $err === null ? 'guards' : 'closed_noguard']));
            exit;
        }
    } catch (InvalidArgumentException | DomainException $e) {
        $error = $e->getMessage();
    }
}

$cur = PeriodClose::current($month);
$isClosed = $cur && $cur['status'] === 'closed';
$started = $month <= date('Y-m');
$over = PeriodClose::isOver($month);
$live = $started ? month_close_sheet($month) : null;
$copy = $isClosed ? json_decode((string)$cur['snapshot'], true) : null;
$diff = $copy && $live ? month_close_diff($copy, $live) : [];
$pending = !$isClosed && $started ? PeriodClose::pending($month) : [];
$anyClosed = (bool)PeriodClose::closedMonths();
$guardsOk = !$anyClosed || PeriodClose::guardsInstalled();
$history = PeriodClose::history();
$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));
$done = (string)($_GET['done'] ?? '');
$m = fn($v) => number_format((float)$v);

$pageTitle = 'داخستنی مانگ';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'],
    ['title' => 'داخستنی مانگ'],
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.mc-card{background:#fff;border-radius:16px;padding:16px 18px;box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22);margin-bottom:12px}
.mc-nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.mc-nav a,.mc-nav button{display:inline-flex;align-items:center;justify-content:center;height:38px;min-width:38px;padding:0 12px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;text-decoration:none;font:inherit;cursor:pointer}
.mc-nav input{height:38px;border:1px solid #cbd5e1;border-radius:10px;padding:0 10px;font:inherit}
.mc-nav h2{margin:0 0 0 auto;font-size:20px;font-weight:800;color:#0f172a}
.mc-state{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:14px;margin-bottom:12px}
.mc-state i{font-size:26px}
.mc-state b{display:block;font-size:16px}
.mc-state small{color:inherit;opacity:.85}
.mc-state.closed{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b}
.mc-state.open{background:#f0fdf4;border:1.5px solid #bbf7d0;color:#166534}
.mc-state.wait{background:#f8fafc;border:1.5px solid #e2e8f0;color:#475569}
.mc-msg{padding:10px 14px;border-radius:12px;margin-bottom:12px;font-weight:600}
.mc-msg.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.mc-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.mc-msg.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.mc-pend{margin:0 0 12px;padding:10px 14px;border-radius:12px;background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.mc-pend ul{margin:6px 0 0;padding-inline-start:20px}
.mc-form{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:4px}
.mc-form input[type=text]{flex:1 1 260px;height:42px;border:1px solid #cbd5e1;border-radius:10px;padding:0 12px;font:inherit}
.mc-btn{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 18px;border:0;border-radius:10px;font:inherit;font-weight:800;cursor:pointer;color:#fff}
.mc-btn.close{background:#b91c1c}.mc-btn.close:hover{background:#991b1b}
.mc-btn.open{background:#0f766e}.mc-btn.open:hover{background:#115e59}
.mc-btn.print{background:#334155}
.mc-diff{margin:0 0 12px;padding:10px 14px;border-radius:12px}
.mc-diff.same{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.mc-diff.changed{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.mc-diff table{margin-top:6px;border-collapse:collapse;font-size:13px}
.mc-diff td{padding:3px 10px;border-bottom:1px solid #fecaca}
.mc-scroll{overflow-x:auto}
.mc-tbl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px}
.mc-tbl th{padding:8px 6px;background:#e0f2fe;color:#0c4a6e;border:1px solid #bae6fd;white-space:nowrap}
.mc-tbl th.cus{background:#ffe4e6;color:#9f1239;border-color:#fda4af}
.mc-tbl td{padding:6px;border:1px solid #e2e8f0;text-align:center;font-variant-numeric:tabular-nums;white-space:nowrap}
.mc-tbl td.nm{text-align:start;font-weight:800;color:#0f172a}
.mc-tbl td.nm small{color:#6d28d9;font-weight:700}
.mc-tbl td.b{font-weight:800}
.mc-tbl tr.dept td{background:#f8fafc}
.mc-tbl tfoot td{background:#e0f2fe;font-weight:800}
.mc-tbl .z{color:#cbd5e1}
.mc-bottom{display:grid;gap:4px;max-width:420px;margin:12px 0 0 auto;font-size:14px}
.mc-bottom div{display:flex;justify-content:space-between;gap:12px;padding:5px 10px;border-radius:8px;background:#f8fafc}
.mc-bottom .ex{color:#b91c1c}
.mc-bottom .end{background:#0f766e;color:#fff;font-size:16px}
.mc-cap{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.mc-cap h3{margin:0;font-size:15px}
.mc-cap small{color:#64748b}
.mc-hist{width:100%;border-collapse:collapse;font-size:13px}
.mc-hist th,.mc-hist td{padding:7px 8px;border-bottom:1px solid #eef2f6;text-align:start}
.mc-hist .tag{padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800}
.mc-hist .tag.closed{background:#fee2e2;color:#991b1b}
.mc-hist .tag.reopened{background:#e0f2fe;color:#075985}
@media print{
    body *{visibility:hidden}
    .mc-print,.mc-print *{visibility:visible}
    .mc-print{position:absolute;inset:0 auto auto 0;width:100%;box-shadow:none}
    .mc-noprint{display:none!important}
    .mc-tbl{min-width:0;font-size:10px}
}
</style>

<div class="mc-card mc-nav">
    <a href="<?= dsEsc(APP_URL . '/accountant/month-close.php?month=' . $prev) ?>" title="مانگی پێشوو"><i class="fas fa-chevron-right"></i></a>
    <form method="get" style="display:flex;gap:6px"><input type="month" name="month" value="<?= dsEsc($month) ?>" max="<?= dsEsc(date('Y-m')) ?>" onchange="this.form.submit()"></form>
    <?php if ($next <= date('Y-m')): ?><a href="<?= dsEsc(APP_URL . '/accountant/month-close.php?month=' . $next) ?>" title="مانگی دواتر"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
    <a href="<?= dsEsc(APP_URL . '/accountant/income.php?from=' . $month . '-01&to=' . date('Y-m-t', strtotime($month . '-01'))) ?>"><i class="fas fa-cash-register"></i>&nbsp; داهاتی ئەم مانگە</a>
    <h2><i class="fas fa-lock"></i> داخستنی مانگی <bdi><?= dsEsc($month) ?></bdi></h2>
</div>

<?php
$flash = ['closed' => 'مانگەکە داخرا و کۆپییەکەی پاشەکەوت کرا. ئیتر هیچ پارە و خەرجییەکی ئەو مانگە ناگۆڕدرێت.',
          'reopened' => 'مانگەکە کرایەوە. کۆپییە کۆنەکە لە مێژوودا دەمێنێت.',
          'guards' => 'پاراستنی داتابەیس دانرا.'][$done] ?? '';
?>
<?php if ($flash): ?><div class="mc-msg ok"><i class="fas fa-check-circle"></i> <?= dsEsc($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="mc-msg err"><i class="fas fa-exclamation-circle"></i> <?= dsEsc($error) ?></div><?php endif; ?>
<?php if ($done === 'closed_noguard' || ($anyClosed && !$guardsOk)): ?>
<div class="mc-msg warn"><i class="fas fa-triangle-exclamation"></i>
    پاراستنی داتابەیس (trigger) دانەنرا — لەوانەیە بەکارهێنەری داتابەیس مافی دروستکردنی trigger ـی نەبێت. پەڕەی داهات و مووچە هێشتا ڕێگری دەکەن،
    بەڵام شاشەکانی تر بە تەواوی نا. ئەگەر شتێک گۆڕا، خوارەوە نیشان دەدرێت.
    <?php if ($canReopen): ?><form method="post" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="action" value="guards"><input type="hidden" name="month" value="<?= dsEsc($month) ?>">
        <button type="submit" class="mc-btn open" style="height:32px;margin-inline-start:8px"><i class="fas fa-rotate"></i> دووبارە هەوڵدان</button></form><?php endif; ?>
</div>
<?php endif; ?>

<div class="mc-card">
<?php if ($isClosed): ?>
    <div class="mc-state closed"><i class="fas fa-lock"></i><div>
        <b>مانگی <bdi><?= dsEsc($month) ?></bdi> داخراوە</b>
        <small>لە <bdo dir="ltr"><?= dsEsc($cur['closed_at']) ?></bdo> لەلایەن <?= dsEsc($cur['closed_by_name'] ?? '—') ?><?= $cur['note'] ? ' · ' . dsEsc($cur['note']) : '' ?>
        — هیچ پارە، وەسڵ، پارەدان، فرۆشتنی دەرمانخانە یان خەرجییەک بە ڕێکەوتی ئەم مانگە زیاد، دەستکاری یان سڕینەوە ناکرێت.</small>
    </div></div>

    <?php if ($diff): ?>
    <div class="mc-diff changed"><b><i class="fas fa-triangle-exclamation"></i> ژمارەکانی ئێستا جیاوازن لە کۆپیی داخستن:</b>
        <table><tr><td></td><td>کۆپی</td><td>ئێستا</td></tr>
        <?php foreach ($diff as $label => [$a, $b]): ?><tr><td><?= dsEsc($label) ?></td><td><?= $m($a) ?></td><td><?= $m($b) ?></td></tr><?php endforeach; ?>
        </table></div>
    <?php elseif ($live): ?>
    <div class="mc-diff same"><i class="fas fa-circle-check"></i> ژمارەکانی ئێستا هەمان ژمارەی کۆپیی داخستنن.</div>
    <?php endif; ?>

    <?php if ($canReopen): ?>
    <form method="post" class="mc-form mc-noprint" onsubmit="return confirm('مانگی <?= dsEsc($month) ?> دەکرێتەوە و گۆڕانکاری تێیدا دەکرێت. دڵنیایت؟')">
        <?= Csrf::field() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="month" value="<?= dsEsc($month) ?>">
        <input type="text" name="reason" maxlength="255" minlength="3" required placeholder="هۆکاری کردنەوە (پێویستە)">
        <button type="submit" class="mc-btn open"><i class="fas fa-lock-open"></i> کردنەوەی مانگ</button>
    </form>
    <?php endif; ?>
<?php elseif (!$started): ?>
    <div class="mc-state wait"><i class="fas fa-hourglass-start"></i><div><b>ئەم مانگە هێشتا دەستی پێنەکردووە</b></div></div>
<?php else: ?>
    <div class="mc-state <?= $over ? 'open' : 'wait' ?>"><i class="fas fa-lock-open"></i><div>
        <b>مانگی <bdi><?= dsEsc($month) ?></bdi> کراوەیە</b>
        <small><?= $over ? 'دوای ئەوەی حیساباتەکەت تەواو کرد، دایبخە: ژمارەکانی خوارەوە وەک کۆپی پاشەکەوت دەکرێن و ئیتر ناگۆڕدرێن.' : 'ئەم مانگە هێشتا تەواو نەبووە — تەنها مانگێکی تەواوبوو دادەخرێت.' ?></small>
    </div></div>
    <?php if ($pending): ?>
    <div class="mc-pend"><b><i class="fas fa-list-check"></i> هێشتا ئەمانە چاوەڕوانن لەم مانگەدا — باشترە پێش داخستن تەواو بکرێن:</b>
        <ul><?php foreach ($pending as $label => $n): ?><li><?= dsEsc($label) ?>: <b><?= (int)$n ?></b></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($over && $canClose): ?>
    <form method="post" class="mc-form mc-noprint" onsubmit="return confirm('مانگی <?= dsEsc($month) ?> دادەخرێت. دوای ئەوە هیچ پارە و خەرجییەکی ئەو مانگە زیاد، دەستکاری یان سڕینەوە ناکرێت تا بەڕێوەبەر بیکاتەوە. دڵنیایت؟')">
        <?= Csrf::field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="month" value="<?= dsEsc($month) ?>">
        <input type="text" name="note" maxlength="255" placeholder="تێبینی (ئارەزوومەندانە)">
        <button type="submit" class="mc-btn close"><i class="fas fa-lock"></i> داخستنی مانگی <bdi><?= dsEsc($month) ?></bdi></button>
    </form>
    <?php elseif ($over && !$canClose): ?>
    <div class="mc-msg warn" style="margin:0">داخستنی مانگ تەنها لەلایەن بەڕێوەبەرەوە دەکرێت (یان ڕۆڵێک کە مافی «داخستنی مانگی حیسابات»ی هەبێت).</div>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php $sheet = $copy ?: $live; if ($sheet): ?>
<div class="mc-card mc-print">
    <div class="mc-cap">
        <div><h3><?= $copy ? '<i class="fas fa-lock"></i> کۆپیی داخستن — مانگی ' : '<i class="fas fa-cash-register"></i> خشتەی داهاتی مانگی ' ?><bdi><?= dsEsc($month) ?></bdi></h3>
            <small><?= $copy ? 'وەک لە ' . dsEsc($copy['made_at']) . ' بوو' : 'وەک ئێستایە — هەر سێ جۆری خەرجی (تاقیگە، کۆگا، گشتی) دەبڕدرێن' ?></small></div>
        <button type="button" class="mc-btn print mc-noprint" onclick="window.print()"><i class="fas fa-print"></i> چاپکردن</button>
    </div>
    <div class="mc-scroll"><?= month_close_table($sheet) ?></div>
</div>
<?php endif; ?>

<?php if ($history): ?>
<div class="mc-card mc-noprint">
    <div class="mc-cap"><h3><i class="fas fa-clock-rotate-left"></i> مێژووی داخستن و کردنەوە</h3></div>
    <div class="mc-scroll"><table class="mc-hist">
        <thead><tr><th>مانگ</th><th>دۆخ</th><th>داخرا</th><th>کرایەوە</th><th>هۆکار / تێبینی</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
            <td><a href="<?= dsEsc(APP_URL . '/accountant/month-close.php?month=' . $h['month']) ?>"><bdi><?= dsEsc($h['month']) ?></bdi></a></td>
            <td><span class="tag <?= dsEsc($h['status']) ?>"><?= $h['status'] === 'closed' ? 'داخراوە' : 'کرایەوە' ?></span></td>
            <td><bdo dir="ltr"><?= dsEsc($h['closed_at']) ?></bdo> · <?= dsEsc($h['closed_by_name'] ?? '—') ?></td>
            <td><?= $h['reopened_at'] ? '<bdo dir="ltr">' . dsEsc($h['reopened_at']) . '</bdo> · ' . dsEsc($h['reopened_by_name'] ?? '—') : '—' ?></td>
            <td><?= dsEsc($h['reopen_reason'] ?: ($h['note'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
