<?php
/**
 * داتای پێشوو — a month's income paper from the clinic's other system (2025),
 * typed or pasted in (classes/IncomeArchive.php), so «بەراورد» can set this
 * year against it, month by month or against the year's monthly average.
 *
 *   ?month=Y-m   the month (January 2025 by default)
 *
 * The grid is the paper: a line per doctor (this system's doctors, and lines
 * for doctors who are not in it), Pharmacy, X-ray, Examinations, Other; the
 * expenses and other deductions under it. ماوە, the shares and the totals
 * work themselves out as the figures go in; any cell can still be typed over
 * to match the paper. A block copied from Excel pastes into the grid.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/Csrf.php';
require_once __DIR__ . '/../classes/IncomeArchive.php';
require_once __DIR__ . '/../doctors/_daily_summary_lib.php';

$auth = new Auth();
$auth->requireLogin();
$perm = Permission::getInstance();
if (!$perm->can('page.accountant_financial') && !$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$canEdit = ($auth->isAdmin() || $perm->can(IncomeArchive::PERMISSION)) && IncomeArchive::available();
$userId = (int)$auth->getCurrentUserId();
$db = Database::getInstance();

$month = (string)($_REQUEST['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = '2025-01';
}
$year = substr($month, 0, 4);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    try {
        if (!$canEdit) {
            throw new InvalidArgumentException('دەسەڵاتی تۆمارکردنی داتای پێشووت نییە');
        }
        // تێمپلێتی ٢٠٢٥: the owner's 2025 paper into every month of 2025 (or this one), one click
        if (($_POST['action'] ?? '') === 'template') {
            $months = ($_POST['scope'] ?? '') === 'year'
                ? array_map(fn($i) => '2025-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), range(1, 12))
                : [$month];
            [$filled, $skipped] = IncomeArchive::applyTemplate($months, !empty($_POST['overwrite']), $userId);
            header('Location: ' . APP_URL . '/accountant/income-archive.php?month=' . $month . '&done=template&filled=' . count($filled) . '&kept=' . count($skipped));
            exit;
        }
        if (($_POST['action'] ?? '') === 'delete') {
            IncomeArchive::delete($month, $userId);
            header('Location: ' . APP_URL . '/accountant/income-archive.php?month=' . $month . '&done=deleted');
            exit;
        }
        IncomeArchive::save($month, $_POST['head'] ?? [], $_POST['rows'] ?? [], $userId);
        header('Location: ' . APP_URL . '/accountant/income-archive.php?month=' . $month . '&done=saved');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$data = IncomeArchive::month($month);
$kept = IncomeArchive::months();
// The paper's lines: every doctor of this system (in the sheet's order), lines
// for doctors who are not in it, then the departments.
$doctorList = $db->fetchAll(
    "SELECT u.id, u.full_name, u.is_active, u.commission_rate
     FROM users u LEFT JOIN doctors d ON d.user_id = u.id
     WHERE u.role = 'doctor' ORDER BY u.is_active DESC, d.id IS NULL, d.id, u.full_name"
);
$lines = [];
foreach ($doctorList as $u) {
    $lines['d' . $u['id']] = ['kind' => 'doctor', 'label' => $u['full_name'], 'fixed' => true,
                              'rate' => (float)$u['commission_rate'] > 0 ? (float)$u['commission_rate'] : null, 'off' => !$u['is_active']];
}
foreach ($data['rows'] as $key => $r) {
    if ($key[0] === 'x') {
        $lines[$key] = ['kind' => 'doctor', 'label' => $r['label'], 'fixed' => false, 'rate' => null, 'off' => false];
    }
}
for ($i = 1; $i <= 2; $i++) {
    $lines['new' . $i] = ['kind' => 'doctor', 'label' => '', 'fixed' => false, 'rate' => null, 'off' => false];
}
foreach (IncomeArchive::DEPTS as $k => $label) {
    $lines[$k] = ['kind' => 'dept', 'label' => $label, 'fixed' => true, 'rate' => null, 'off' => false];
}
$cols = ['amount', 'lab', 'store_normal', 'store_normal_doc', 'store_implant', 'store_implant_doc', 'net', 'rate', 'doctor_share', 'center_share',
         'minutes', 'days', 'pending', 'given', 'old_exam', 'new_exam', 'cases_given'];
$heads = IncomeArchive::MONEY + ['rate' => 'دابەشکردن %', 'minutes' => 'سەعات'] + IncomeArchive::COUNTS;
$deptCols = ['amount', 'net', 'center_share'];
$val = function (?array $r, string $c) {
    if (!$r) {
        return '';
    }
    if ($c === 'minutes') {
        return $r['minutes'] === null ? '' : intdiv((int)$r['minutes'], 60) . ':' . str_pad((string)((int)$r['minutes'] % 60), 2, '0', STR_PAD_LEFT);
    }
    if ($c === 'rate') {
        return $r['rate'] === null ? '' : rtrim(rtrim(number_format((float)$r['rate'], 2, '.', ''), '0'), '.');
    }
    $v = (float)$r[$c];
    return abs($v) < 0.005 ? '' : number_format($v, in_array($c, array_keys(IncomeArchive::COUNTS), true) ? 0 : 0);
};
$monthNames = ['کانوونی دووەم', 'شوبات', 'ئازار', 'نیسان', 'ئایار', 'حوزەیران', 'تەممووز', 'ئاب', 'ئەیلوول', 'تشرینی یەکەم', 'تشرینی دووەم', 'کانوونی یەکەم'];
$done = (string)($_GET['done'] ?? '');

$pageTitle = 'داتای پێشوو';
$breadcrumb = [['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'], ['title' => 'داتای پێشوو']];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ar-card{background:#fff;border-radius:14px;padding:12px 14px;margin-bottom:10px;box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22)}
.ar-top{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.ar-top h2{margin:0;font-size:18px;font-weight:800;color:#0f172a;margin-inline-end:auto}
.ar-top a.y{padding:6px 10px;border-radius:9px;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;text-decoration:none}
.ar-months{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:6px;margin-top:10px}
@media (max-width:900px){.ar-months{grid-template-columns:repeat(6,minmax(0,1fr))}}
.ar-m{display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 4px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;color:#475569;text-decoration:none;font-size:11.5px}
.ar-m b{font-size:13px;color:#0f172a}
.ar-m.kept{border-color:#86efac;background:#f0fdf4}
.ar-m.kept small{color:#15803d;font-weight:700}
.ar-m.on{box-shadow:0 0 0 2px #0284c7;border-color:#0284c7}
.ar-msg{padding:9px 12px;border-radius:10px;margin-bottom:10px;font-weight:600}
.ar-msg.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.ar-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.ar-hint{font-size:12px;color:#64748b;margin:0 0 8px;line-height:1.7}
.ar-scroll{overflow-x:auto}
.ar-tbl{border-collapse:collapse;font-size:12px;min-width:1900px;width:100%}
.ar-tbl th{position:sticky;top:0;padding:6px 4px;background:#fde68a;color:#422006;border:1px solid #e5d3a0;font-size:11px;white-space:nowrap}
.ar-tbl td{padding:2px;border:1px solid #e2e8f0}
.ar-tbl td.nm{padding:2px 6px;font-weight:800;color:#0f172a;white-space:nowrap;min-width:140px}
.ar-tbl td.nm small{color:#94a3b8;font-weight:600}
.ar-tbl tr.dept td{background:#f8fafc}
.ar-tbl input{width:100%;min-width:100px;height:28px;border:1px solid transparent;border-radius:5px;padding:0 5px;font:inherit;font-size:12px;
    direction:ltr;text-align:right;background:transparent;font-variant-numeric:tabular-nums}
.ar-tbl input:hover{border-color:#cbd5e1}
.ar-tbl input:focus{outline:none;border-color:#0284c7;background:#fff;box-shadow:0 0 0 2px rgba(2,132,199,.15)}
.ar-tbl input.auto{color:#0369a1}
.ar-tbl input.lbl{direction:rtl;text-align:right;font-weight:700;min-width:130px}
.ar-tbl td.na{background:#f1f5f9}
.ar-tbl tfoot td{background:#fde68a;font-weight:800;padding:5px 6px;text-align:right;direction:ltr;white-space:nowrap}
.ar-tbl tfoot td.nm{direction:rtl}
.ar-foot{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;margin-top:10px}
.ar-foot label{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:700;color:#475569}
.ar-foot input{height:36px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;font:inherit;font-size:13px}
.ar-foot input.n{direction:ltr;text-align:right;width:160px}
.ar-foot .w{flex:1 1 240px}.ar-foot .w input{width:100%}
.ar-left{display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:10px;background:#0f766e;color:#fff;font-weight:800}
.ar-left b{font-size:17px;direction:ltr}
.ar-btn{height:38px;padding:0 18px;border:0;border-radius:9px;font:inherit;font-weight:800;cursor:pointer;color:#fff;display:inline-flex;align-items:center;gap:7px;text-decoration:none}
.ar-btn.save{background:#0284c7}
.ar-tpl{display:flex;flex-wrap:wrap;align-items:center;gap:10px;border:1.5px dashed #fcd34d;background:#fffbeb}
.ar-tpl > i{font-size:24px;color:#b45309}
.ar-tpl .t{flex:1;min-width:260px;display:flex;flex-direction:column;gap:2px;color:#78350f}
.ar-tpl .t small{color:#92400e;line-height:1.6}
.ar-tpl .ow{display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#92400e}.ar-btn.del{background:#fff;color:#b91c1c;border:1px solid #fecaca}.ar-btn.cmp{background:#7c3aed}
</style>

<div class="ar-card">
    <div class="ar-top">
        <h2><i class="fas fa-box-archive"></i> داتای پێشوو — <?= dsEsc($monthNames[(int)substr($month, 5) - 1]) ?> <bdi><?= dsEsc($year) ?></bdi></h2>
        <a class="y" href="?month=<?= ((int)$year - 1) ?>-<?= substr($month, 5) ?>"><i class="fas fa-chevron-right"></i> <?= (int)$year - 1 ?></a>
        <a class="y" href="?month=<?= ((int)$year + 1) ?>-<?= substr($month, 5) ?>"><?= (int)$year + 1 ?> <i class="fas fa-chevron-left"></i></a>
        <a class="ar-btn cmp" href="<?= APP_URL ?>/accountant/income-compare.php?<?= dsEsc(http_build_query(['a_from' => $month . '-01', 'a_to' => date('Y-m-t', strtotime($month . '-01')),
            'b_from' => ((int)$year + 1) . substr($month, 4) . '-01', 'b_to' => date('Y-m-t', strtotime(((int)$year + 1) . substr($month, 4) . '-01'))])) ?>"><i class="fas fa-scale-unbalanced"></i> بەراورد لەگەڵ <?= (int)$year + 1 ?></a>
    </div>
    <div class="ar-months">
        <?php for ($i = 1; $i <= 12; $i++): $m = $year . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT); $k = $kept[$m] ?? null; ?>
        <a class="ar-m<?= $k ? ' kept' : '' ?><?= $m === $month ? ' on' : '' ?>" href="?month=<?= $m ?>">
            <b><?= $i ?></b><span><?= dsEsc($monthNames[$i - 1]) ?></span><small><?= $k ? '✓ ' . number_format($k['amount']) : '—' ?></small></a>
        <?php endfor; ?>
    </div>
</div>

<?php if ($done === 'saved'): ?><div class="ar-msg ok"><i class="fas fa-check-circle"></i> داتای مانگەکە پاشەکەوت کرا. لە «بەراورد» بەکار دێت.</div><?php endif; ?>
<?php if ($done === 'template'): ?><div class="ar-msg ok"><i class="fas fa-check-circle"></i>
    داتای پەڕەی ٢٠٢٥ دانرا بۆ <b><?= (int)($_GET['filled'] ?? 0) ?></b> مانگ<?= (int)($_GET['kept'] ?? 0) ? ' — ' . (int)$_GET['kept'] . ' مانگ پێشتر داتای هەبوو و وەک خۆی ما' : '' ?>.
    ئێستا لە «بەراورد» بەکار دێت؛ هەر مانگێک دەتوانیت دەستکاری بکەیت.</div><?php endif; ?>
<?php if ($done === 'deleted'): ?><div class="ar-msg ok"><i class="fas fa-check-circle"></i> داتای مانگەکە سڕایەوە.</div><?php endif; ?>
<?php if ($error): ?><div class="ar-msg err"><i class="fas fa-exclamation-circle"></i> <?= dsEsc($error) ?></div><?php endif; ?>

<?php if ($canEdit): ?>
<!-- The owner's 2025 paper as a template: every month of 2025 in one click -->
<form method="post" class="ar-card ar-tpl" onsubmit="return confirm(this.querySelector('[name=overwrite]').checked
        ? 'داتای پەڕەی ٢٠٢٥ دادەنرێت و ئەو مانگانەی داتایان هەیە دەگۆڕدرێن. دڵنیایت؟'
        : 'داتای پەڕەی ٢٠٢٥ بۆ هەموو مانگە بەتاڵەکانی ٢٠٢٥ دادەنرێت. دڵنیایت؟')">
    <?= Csrf::field() ?>
    <input type="hidden" name="month" value="<?= dsEsc($month) ?>">
    <input type="hidden" name="action" value="template">
    <i class="fas fa-file-import"></i>
    <div class="t"><b>تێمپلێتی پەڕەی ساڵی ٢٠٢٥</b>
        <small>داتای «لیستی داهات و سەردانیکەران و کاتی هاتنی دکتۆرەکان بۆ ساڵی 2025» — هەر مانگێکی ٢٠٢٥ هەمان ژمارەی ئەو پەڕەیە وەردەگرێت
        (داهاتی گشتی <?= number_format(array_sum(array_column(IncomeArchive::TEMPLATE_2025['rows'], 'amount'))) ?>، خەرجی <?= number_format(IncomeArchive::TEMPLATE_2025['head']['expenses']) ?>).
        دواتر هەر مانگێک دەستکاری دەکرێت.</small></div>
    <label class="ow"><input type="checkbox" name="overwrite" value="1"> ئەوانەی داتایان هەیەش بگۆڕە</label>
    <button type="submit" name="scope" value="year" class="ar-btn save"><i class="fas fa-wand-magic-sparkles"></i> هەموو ١٢ مانگی ٢٠٢٥ دابنێ</button>
    <?php if (str_starts_with($month, '2025-')): ?>
    <button type="submit" name="scope" value="month" class="ar-btn cmp" style="background:#0f766e"><i class="fas fa-calendar-day"></i> تەنها ئەم مانگە</button>
    <?php endif; ?>
</form>
<?php endif; ?>

<form method="post" class="ar-card" id="arForm" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="month" value="<?= dsEsc($month) ?>">
    <p class="ar-hint">
        ژمارەکانی پەڕەی ئەو مانگە لە سیستەمە کۆنەکە بنووسە، یان <b>لە Excel کۆپی بکە و لە خانەیەکدا Paste بکە</b> — لەو خانەیەوە بە ڕاست و خوارەوە پڕ دەبێتەوە.
        <b style="color:#0369a1">ماوە</b>، <b style="color:#0369a1">ماوەی دکتۆر و لێزان</b> و کۆکان خۆیان دەژمێردرێن (ماوە = داهات − تاقیگە − کۆگا − ئیمپلانت؛ دکتۆر = ماوە × ڕێژە)،
        بەڵام دەتوانیت بیانگۆڕیت بۆ ئەوەی وەک پەڕەکە بن. سەعات وەک <bdi>175:38</bdi>. خانەی بەتاڵ = نەبوو.
    </p>
    <div class="ar-scroll">
        <table class="ar-tbl" id="arGrid">
            <thead><tr><th>دکتۆر / بەش</th><?php foreach ($cols as $c): ?><th><?= dsEsc($heads[$c]) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($lines as $key => $L): $r = $data['rows'][$key] ?? null; $dept = $L['kind'] === 'dept'; ?>
                <tr class="<?= $dept ? 'dept' : '' ?>" data-row="<?= dsEsc($key) ?>" data-default-rate="<?= $L['rate'] === null ? '' : dsEsc($L['rate']) ?>">
                    <td class="nm">
                        <?php if ($L['fixed']): ?><?= dsEsc($L['label']) ?><?= $L['off'] ? ' <small>(ناچالاک)</small>' : '' ?>
                        <?php else: ?><input type="text" class="lbl" name="rows[<?= dsEsc($key) ?>][label]" value="<?= dsEsc($L['label']) ?>" placeholder="ناوی دکتۆرێکی تر…" <?= $canEdit ? '' : 'readonly' ?>><?php endif; ?>
                    </td>
                    <?php foreach ($cols as $c): ?>
                    <?php if ($dept && !in_array($c, $deptCols, true)): ?><td class="na"></td>
                    <?php else: ?><td><input type="text" inputmode="decimal" name="rows[<?= dsEsc($key) ?>][<?= $c ?>]" data-col="<?= $c ?>" value="<?= dsEsc($val($r, $c)) ?>" <?= $canEdit ? '' : 'readonly' ?>></td><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td class="nm">کۆی گشتی</td><?php foreach ($cols as $c): ?><td data-sum="<?= $c ?>"></td><?php endforeach; ?></tr></tfoot>
        </table>
    </div>
    <div class="ar-foot">
        <label>خەرجی<input type="text" class="n" inputmode="decimal" name="head[expenses]" id="arExp" value="<?= dsEsc($data['head'] && (float)$data['head']['expenses'] ? number_format((float)$data['head']['expenses']) : '') ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
        <label>لێبڕینی تر (مووچە…)<input type="text" class="n" inputmode="decimal" name="head[deductions]" id="arDed" value="<?= dsEsc($data['head'] && (float)$data['head']['deductions'] ? number_format((float)$data['head']['deductions']) : '') ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
        <label class="w">تێبینی<input type="text" name="head[note]" maxlength="255" value="<?= dsEsc($data['head']['note'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
        <div class="ar-left">ماوە (لێزان − خەرجی − لێبڕین) <b id="arLeft">0</b></div>
    </div>
    <?php if ($canEdit): ?>
    <div class="ar-foot">
        <button type="submit" class="ar-btn save"><i class="fas fa-floppy-disk"></i> پاشەکەوتکردنی مانگەکە</button>
        <?php if ($data['head']): ?>
        <button type="submit" name="action" value="delete" class="ar-btn del" formnovalidate onclick="return confirm('داتای ئەم مانگە بسڕدرێتەوە؟')"><i class="fas fa-trash"></i> سڕینەوە</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</form>

<script>
(function () {
    var grid = document.getElementById('arGrid');
    if (!grid) return;
    var num = function (v) {
        v = String(v || '').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/[,\s،%]/g, '');
        if (/^(نەبوو|نهبوو|نیه|نییە|-|—)$/.test(v)) return 0;
        return parseFloat(v) || 0;
    };
    var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
    var mins = function (v) { v = String(v || '').trim(); var m = v.match(/^(\d+):(\d{1,2})/); return m ? +m[1] * 60 + +m[2] : (v ? num(v) * 60 : 0); };
    // A figure the page worked out is shown blue and kept up to date until someone types over it.
    function cell(tr, c) { return tr.querySelector('input[data-col="' + c + '"]'); }
    function auto(input, value) {
        if (!input || (input.value !== '' && !input.classList.contains('auto'))) return;
        input.value = value ? fmt(value) : '';
        input.classList.add('auto');
    }
    function workOut(tr) {
        var a = num((cell(tr, 'amount') || {}).value), dept = tr.classList.contains('dept');
        var net = dept ? a : a - num((cell(tr, 'lab') || {}).value) - num((cell(tr, 'store_normal') || {}).value) - num((cell(tr, 'store_implant') || {}).value);
        auto(cell(tr, 'net'), net);
        var n = num(cell(tr, 'net').value);
        if (dept) { auto(cell(tr, 'center_share'), n); return; }
        var rateIn = cell(tr, 'rate');
        if (rateIn.value === '' && tr.dataset.defaultRate && a) { rateIn.value = tr.dataset.defaultRate; rateIn.classList.add('auto'); }
        var rate = num(rateIn.value);
        auto(cell(tr, 'doctor_share'), n * rate / 100);
        auto(cell(tr, 'center_share'), n - num(cell(tr, 'doctor_share').value));
    }
    function totals() {
        var sums = {};
        grid.querySelectorAll('tbody input[data-col]').forEach(function (i) {
            var c = i.dataset.col; if (c === 'rate') return;
            sums[c] = (sums[c] || 0) + (c === 'minutes' ? mins(i.value) : num(i.value));
        });
        grid.querySelectorAll('tfoot [data-sum]').forEach(function (td) {
            var c = td.dataset.sum, v = sums[c] || 0;
            td.textContent = c === 'rate' ? '' : (c === 'minutes' ? (v ? Math.floor(v / 60) + ':' + String(Math.round(v % 60)).padStart(2, '0') : '') : (v ? fmt(v) : ''));
        });
        document.getElementById('arLeft').textContent = fmt((sums.center_share || 0) - num(document.getElementById('arExp').value) - num(document.getElementById('arDed').value));
    }
    document.getElementById('arForm').addEventListener('input', function (ev) {
        var t = ev.target;
        if (t.dataset && t.dataset.col) {
            t.classList.remove('auto');
            if (t.value === '' && ['net', 'doctor_share', 'center_share'].indexOf(t.dataset.col) !== -1) t.classList.add('auto');   // cleared: worked out again
            workOut(t.closest('tr'));
        }
        totals();
    });
    // A block copied from Excel: from this cell to the right and down.
    grid.addEventListener('paste', function (ev) {
        var start = ev.target.closest('input[data-col]');
        var text = (ev.clipboardData || window.clipboardData).getData('text');
        if (!start || !/[\t\n]/.test(text)) return;
        ev.preventDefault();
        var rows = [].slice.call(grid.querySelectorAll('tbody tr'));
        var r0 = rows.indexOf(start.closest('tr'));
        var colsOf = function (tr) { return [].slice.call(tr.querySelectorAll('td')).slice(1); };
        var c0 = colsOf(rows[r0]).indexOf(start.closest('td'));
        text.replace(/\r/g, '').replace(/\n$/, '').split('\n').forEach(function (line, i) {
            var tr = rows[r0 + i]; if (!tr) return;
            var tds = colsOf(tr);
            line.split('\t').forEach(function (v, j) {
                var td = tds[c0 + j], input = td && td.querySelector('input');
                if (input) { input.value = v.trim(); input.classList.remove('auto'); }
            });
            workOut(tr);
        });
        totals();
    });
    grid.querySelectorAll('tbody tr').forEach(function (tr) {
        // what was saved is the paper's own; an empty cell of a filled line is worked out
        ['net', 'doctor_share', 'center_share'].forEach(function (c) { var i = cell(tr, c); if (i && i.value === '') i.classList.add('auto'); });
        if (num((cell(tr, 'amount') || {}).value)) workOut(tr);
    });
    totals();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
