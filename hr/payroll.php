<?php
/**
 * پێدانی مووچە — the month's salaries. The figures are the monthly report's,
 * from HrAttendance; the rules are in classes/HrPayroll.php.
 *
 * One row per person, with a bonus, a deduction and a note.
 *   «پێدان» on a row opens the salary receipt (hr/salary-receipt.php) in a
 *   dialog: it is paid there, one person at a time, signed by the person on
 *   the screen, and — if «تۆمارکردن وەک خەرجی» stays ticked — written as a
 *   general expense. A paid row opens its saved receipt.
 *   «پەسەندکردن» at the bottom fixes the figures of the ticked rows without
 *   paying. Ticked by default: everyone with a salary who is not paid yet,
 *   except doctors paid through «دابەشکردنی داهات» and people hidden from
 *   the monthly report.
 * After a payment only the cards and the table are fetched again and swapped
 * in place ([data-hr-refresh]), never the whole page.
 *
 * Viewing needs hr.payroll.view; approving and paying need hr.payroll.pay.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_receipt_dialog.php';
require_once __DIR__ . '/../classes/HrPayroll.php';
hr_require('hr.payroll.view');

$canPay = hr_can('hr.payroll.pay');
$payroll = new HrPayroll();
$month = hr_month($_GET['month'] ?? ($_POST['month'] ?? null));
$self = APP_URL . '/hr/payroll.php?month=' . $month;

/** "1,250,000" or "1250000" → 1250000.0 */
function hr_pay_number($v): float
{
    return max(0.0, (float)str_replace([',', ' ', '٬'], '', (string)$v));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    hr_require('hr.payroll.pay');
    try {
        $rowAction = (string)($_POST['row_action'] ?? '');
        if (preg_match('/^unapprove:(\d+)$/', $rowAction, $m)) {
            $emp = Database::getInstance()->fetch('SELECT full_name FROM hr_employees WHERE id = ?', [(int)$m[1]]);
            $payroll->unapprove($month, (int)$m[1], $hrUserId);
            hr_flash('success', 'پەسەندکردنی مووچەی «' . ($emp['full_name'] ?? '') . '» هەڵوەشایەوە');
        } elseif (preg_match('/^delete_pay:(\d+)$/', $rowAction, $m)) {
            $rc = $payroll->deletePayment((int)$m[1], $hrUserId);
            hr_flash('success', 'وەسڵی مووچەدانی ' . HrPayroll::receiptNo($m[1]) . ' — «' . $rc['employee_name'] . '» سڕایەوە'
                . ($rc['expense_id'] ? '، خەرجییەکەی سڕایەوە' : '') . ($rc['cashbox_entry_id'] ? '، و پارەکە گەڕایەوە ناو قاسەی لێزان' : '') . '.');
        } elseif (($_POST['action'] ?? '') === 'approve') {
            $items = [];
            foreach ((array)($_POST['sel'] ?? []) as $id) {
                $id = (int)$id;
                $items[$id] = [
                    'bonus'     => hr_pay_number($_POST['bonus'][$id] ?? 0),
                    'deduction' => hr_pay_number($_POST['deduction'][$id] ?? 0),
                    'note'      => (string)($_POST['note'][$id] ?? ''),
                ];
            }
            if (!$items) {
                throw new InvalidArgumentException('هیچ کەسێک دیاری نەکراوە');
            }
            [$n, $messages] = $payroll->approve($month, $items, $hrUserId);
            hr_flash($n ? 'success' : 'error', ($n ? 'مووچەی ' . $n . ' کەس پەسەند کرا' : 'هیچ مووچەیەک پەسەند نەکرا')
                . ($messages ? ' — ' . implode(' · ', $messages) : ''));
        }
    } catch (InvalidArgumentException $ex) {
        hr_flash('error', $ex->getMessage());
    }
    hr_redirect($self);
}

$rows = $payroll->month($month);
$thisMonth = substr(HrAttendance::currentWorkDate(), 0, 7);

// What each row stands at: approved rows are approved again with today's
// figures when paid, so they show today's; paid rows keep theirs.
$sum = ['amount' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'open' => 0.0, 'n_approved' => 0, 'n_paid' => 0, 'n_open' => 0, 'changed' => 0];
foreach ($rows as $id => &$r) {
    $s = $r['saved'];
    $paid = $r['status'] === 'paid';
    $r['calc'] = $paid ? (float)$s['calculated'] : (float)$r['pay']['rounded'];
    $r['bonus'] = $s ? (float)$s['bonus'] : 0.0;
    $r['deduction'] = $s ? (float)$s['deduction'] : 0.0;
    $r['final'] = $r['calc'] + $r['bonus'] - $r['deduction'];
    $r['default'] = !$paid && $r['final'] > 0 && $r['commission'] <= 0 && !(int)$r['employee']['monthly_hidden']
        && $r['employee']['status'] === 'active';
    $sum['amount'] += max(0, $r['final']);
    if ($paid) {
        $sum['paid'] += (float)$s['amount'];
        $sum['n_paid']++;
    } elseif ($r['status'] === 'approved') {
        $sum['approved'] += $r['final'];
        $sum['n_approved']++;
    } elseif ($r['final'] > 0) {
        $sum['open'] += $r['final'];
        $sum['n_open']++;
    }
    $sum['changed'] += $r['changed'] ? 1 : 0;
}
unset($r);

if (($_GET['export'] ?? '') === 'csv') {
    $out = [['PIN', 'ناو', 'جۆر', 'کاتژمێر', 'کرێی کاتژمێر', 'مووچەی جێگیر', 'ڕێژە %', 'حیسابکراو', 'زیادە', 'کەمکردنەوە', 'کۆتایی', 'دۆخ', 'ڕێکەوتی پێدان', 'شێواز', 'ژمارەی وەسڵ', 'تێبینی']];
    $labels = ['none' => 'پەسەند نەکراوە', 'approved' => 'پەسەندکراو', 'paid' => 'دراوە'];
    foreach ($rows as $r) {
        $e = $r['employee'];
        $s = $r['saved'];
        $out[] = [
            hr_pin($e), $e['full_name'], $e['employee_type'] === 'doctor' ? 'دکتۆر' : 'کارمەند',
            HrAttendance::hm($s ? (float)$s['net_hours'] : $r['hours'], false),
            round((float)($s['hourly_rate'] ?? $e['hourly_rate'])), round((float)($s['basic_salary'] ?? $e['basic_salary'])),
            (float)($s['salary_percentage'] ?? $e['salary_percentage']),
            round($r['calc']), round($r['bonus']), round($r['deduction']), round($r['final']),
            $labels[$r['status']], $s['paid_date'] ?? '', HrPayroll::METHODS[$s['payment_method'] ?? ''] ?? '',
            !empty($s['receipt_id']) ? HrPayroll::receiptNo($s['receipt_id']) : '', $s['note'] ?? '',
        ];
    }
    hr_csv('hr-payroll-' . $month . '.csv', $out);
}

$hrNavPage = 'payroll';
$pageTitle = 'پێدانی مووچە';
$pageActions = '<a class="btn btn-secondary" href="' . hr_esc($self . '&export=csv') . '"><i class="fas fa-file-excel"></i> Excel</a>'
    . ' <a class="btn btn-primary" target="_blank" href="' . APP_URL . '/hr/payroll-pdf.php?month=' . $month . '"><i class="fas fa-file-pdf"></i> PDF</a>';
$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));
$receiptUrl = APP_URL . '/hr/salary-receipt.php?embed=1';

$customCSS = $hrBaseCss . <<<CSS
.pr-month{display:flex;align-items:center;gap:6px}
.pr-month input{width:170px}
.pr-note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:9px 12px;margin-bottom:12px;font-size:13px}
.pr-table td{white-space:nowrap}
.pr-table th,.pr-table td{padding-left:6px;padding-right:6px}
.pr-table td.who{white-space:normal;min-width:130px}
.pr-table td.who b{display:block;color:#0f172a}
.pr-table td.who .hr-badge{margin:2px 0 0 2px}
.pr-table input.form-control{height:32px;padding:3px 8px;font-size:13px}
.pr-table input.pr-num{width:92px;direction:ltr;text-align:left}
.pr-table input.pr-txt{width:120px}
.pr-final{font-weight:800;color:#0f766e;font-size:14px}
.pr-final.neg{color:#b91c1c}
.pr-was{display:block;font-size:11px;color:#b45309;font-weight:700}
.pr-paid{display:block;font-size:11px;color:#64748b}
.pr-table tr.is-paid td{background:#f6fef9}
.pr-table tr.is-sel td{background:#f0fdfa}
.pr-act{display:flex;gap:4px;justify-content:flex-end;align-items:center}
.pr-act .pr-pay{background:#15803d;border-color:#15803d;color:#fff;font-weight:800}
.pr-act .pr-pay:hover{background:#166534;color:#fff}
.pr-act .pr-rc{background:#ecfdf5;border-color:#a7f3d0;color:#047857;font-weight:700}
.pr-bar{position:sticky;bottom:0;z-index:5;display:flex;flex-wrap:wrap;align-items:center;gap:10px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;
    padding:10px 14px;margin-top:12px;box-shadow:0 -6px 18px -12px rgba(15,23,42,.35)}
.pr-bar .sel{font-weight:700;color:#334155}
.pr-bar .sel b{color:#0f766e;font-size:16px}
.pr-bar .hint{color:#64748b;font-size:12.5px}
.pr-bar .grow{flex:1}
@media (max-width:700px){.pr-bar .grow,.pr-bar .hint{display:none}}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<form class="hr-filter" method="GET">
    <div class="pr-month">
        <a class="btn btn-secondary" href="?month=<?= $prev ?>" title="مانگی پێشوو"><i class="fas fa-chevron-right"></i></a>
        <input type="month" name="month" class="form-control" value="<?= hr_esc($month) ?>" onchange="this.form.submit()" aria-label="مانگ">
        <a class="btn btn-secondary" href="?month=<?= $next ?>" title="مانگی دواتر"><i class="fas fa-chevron-left"></i></a>
    </div>
    <a class="btn btn-secondary" href="<?= APP_URL ?>/hr/reports.php?r=monthly&month=<?= $month ?>"><i class="fas fa-table-cells"></i> ڕاپۆرتی مانگانە</a>
    <a class="btn btn-secondary" href="<?= APP_URL ?>/hr/salary-receipts.php?month=<?= $month ?>"><i class="fas fa-receipt"></i> وەسڵەکانی ئەم مانگە</a>
</form>

<div class="hr-cards" data-hr-refresh="cards">
    <div class="hr-card" style="--c:#0f766e"><div class="lbl">کۆی مووچەی مانگ</div><div class="val"><?= hr_money($sum['amount']) ?></div><div class="sub"><?= count($rows) ?> کەس</div></div>
    <div class="hr-card" style="--c:#64748b"><div class="lbl">پەسەند نەکراوە</div><div class="val"><?= hr_money($sum['open']) ?></div><div class="sub"><?= $sum['n_open'] ?> کەس</div></div>
    <div class="hr-card" style="--c:#d97706"><div class="lbl">پەسەندکراو، نەدراوە</div><div class="val"><?= hr_money($sum['approved']) ?></div><div class="sub"><?= $sum['n_approved'] ?> کەس</div></div>
    <div class="hr-card" style="--c:#15803d"><div class="lbl">دراوە</div><div class="val"><?= hr_money($sum['paid']) ?></div><div class="sub"><?= $sum['n_paid'] ?> کەس</div></div>
</div>

<div data-hr-refresh="notes">
<?php if ($month >= $thisMonth): ?>
<div class="pr-note"><i class="fas fa-hourglass-half"></i> مانگی <?= hr_esc(HrPayroll::monthLabel($month)) ?> هێشتا تەواو نەبووە: ژمارەکان تا ئەمڕۆن.</div>
<?php endif; ?>
<?php if ($sum['changed']): ?>
<div class="pr-note"><i class="fas fa-triangle-exclamation"></i> ژمارەکانی <b><?= $sum['changed'] ?></b> کەس دوای پەسەندکردن گۆڕاون، بۆ نموونە پەنجەمۆرێک چاک کراوە. پەسەندکراوەکان بە ژمارەی ئێستا پیشان دەدرێن و کاتێک دەدرێن یان دیسان پەسەند دەکرێن نوێ دەبنەوە. دراوەکان ناگۆڕێن.</div>
<?php endif; ?>
</div>

<form method="POST" id="prForm">
    <input type="hidden" name="month" value="<?= hr_esc($month) ?>">
    <?php /* Enter in a field must not press a row's button: the first submit button is a disabled one. */ ?>
    <button type="submit" disabled hidden aria-hidden="true"></button>
    <div class="hr-table-wrap">
        <table class="hr-table pr-table">
            <thead><tr>
                <?php if ($canPay): ?><th><input type="checkbox" id="prAll" aria-label="هەموو"></th><?php endif; ?>
                <th>PIN</th><th>ناو</th><th class="num">کاتژمێر</th><th class="num">حیسابکراو</th>
                <th>+ زیادە</th><th>− کەمکردنەوە</th><th>تێبینی</th><th class="num">کۆتایی</th><th>دۆخ</th><th></th>
            </tr></thead>
            <tbody data-hr-refresh="rows">
            <?php if (!$rows): ?>
                <tr><td colspan="11" class="hr-empty">هیچ کارمەندێک نییە</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $id => $r):
                $e = $r['employee'];
                $s = $r['saved'];
                $paid = $r['status'] === 'paid';
                $editable = $canPay && !$paid;
                $hours = $paid ? (float)$s['net_hours'] : $r['hours']; ?>
            <tr data-id="<?= $id ?>" data-calc="<?= $r['calc'] ?>" data-commission="<?= $r['commission'] ?>" class="<?= $paid ? 'is-paid' : '' ?>">
                <?php if ($canPay): ?>
                <td><input type="checkbox" name="sel[]" value="<?= $id ?>" class="pr-sel" <?= $paid ? 'disabled' : '' ?> <?= $r['default'] ? 'checked' : '' ?> aria-label="<?= hr_esc($e['full_name']) ?>"></td>
                <?php endif; ?>
                <td class="num hr-muted"><?= hr_esc(hr_pin($e)) ?></td>
                <td class="who"><b><?= hr_esc($e['full_name']) ?></b>
                    <span class="hr-badge <?= $e['employee_type'] === 'doctor' ? 'doctor' : 'staff' ?>"><?= $e['employee_type'] === 'doctor' ? 'دکتۆر' : 'کارمەند' ?></span>
                    <?php if ($r['commission'] > 0): ?><span class="hr-badge warn" title="بەشی داهات لە «دابەشکردنی داهات» وەردەگرێت — ئەگەر مووچەشی پێبدرێت دوو جار پارە وەردەگرێت">دابەشکردنی داهات <?= rtrim(rtrim(number_format($r['commission'], 2), '0'), '.') ?>%</span><?php endif; ?>
                    <?php if ((int)$e['monthly_hidden']): ?><span class="hr-badge off">شاراوە لە ڕاپۆرت</span><?php endif; ?>
                    <?php if ($e['status'] !== 'active'): ?><span class="hr-badge off">ناچالاک</span><?php endif; ?>
                </td>
                <td class="num"><?= hr_esc(HrAttendance::hm($hours, false)) ?></td>
                <td class="num"><?= hr_money($r['calc']) ?>
                    <?php if ($r['changed']): ?><span class="pr-was"><?= $paid ? 'ئێستا: ' . hr_money($r['pay']['rounded']) : 'پەسەندکراو بوو: ' . hr_money($s['calculated']) ?></span><?php endif; ?>
                </td>
                <?php if ($editable): ?>
                <td><input type="number" min="0" step="1" name="bonus[<?= $id ?>]" class="form-control pr-num pr-bonus" value="<?= $r['bonus'] ? (int)$r['bonus'] : '' ?>" placeholder="0"></td>
                <td><input type="number" min="0" step="1" name="deduction[<?= $id ?>]" class="form-control pr-num pr-ded" value="<?= $r['deduction'] ? (int)$r['deduction'] : '' ?>" placeholder="0"></td>
                <td><input type="text" maxlength="255" name="note[<?= $id ?>]" class="form-control pr-txt pr-note-in" value="<?= hr_esc($s['note'] ?? '') ?>" placeholder="تێبینی"></td>
                <?php else: ?>
                <td class="num"><?= $r['bonus'] > 0 ? '+' . hr_money($r['bonus']) : '' ?></td>
                <td class="num"><?= $r['deduction'] > 0 ? '−' . hr_money($r['deduction']) : '' ?></td>
                <td style="white-space:normal"><?= hr_esc($s['note'] ?? '') ?></td>
                <?php endif; ?>
                <td class="num"><span class="pr-final"><?= hr_money($r['final']) ?></span></td>
                <td>
                    <?php if ($paid): ?>
                    <span class="hr-badge in"><i class="fas fa-check"></i> دراوە</span>
                    <span class="pr-paid"><bdo dir="ltr"><?= hr_esc($s['paid_date']) ?></bdo> · <?= hr_esc(HrPayroll::METHODS[$s['payment_method']] ?? '') ?></span>
                    <?php elseif ($r['status'] === 'approved'): ?>
                    <span class="hr-badge warn">پەسەندکراو</span>
                    <?php else: ?>
                    <span class="hr-badge off">پەسەند نەکراوە</span>
                    <?php endif; ?>
                </td>
                <td><div class="pr-act">
                    <?php if ($paid && !empty($s['receipt_id'])): ?>
                    <button type="button" class="btn btn-sm pr-rc" data-hr-receipt="<?= hr_esc($receiptUrl . '&id=' . (int)$s['receipt_id']) ?>"><i class="fas fa-receipt"></i> وەسڵ <?= hr_esc(HrPayroll::receiptNo($s['receipt_id'])) ?></button>
                    <?php elseif ($canPay && !$paid): ?>
                    <button type="button" class="btn btn-sm pr-pay" data-pay="<?= $id ?>"><i class="fas fa-money-bill-wave"></i> پێدان</button>
                    <?php endif; ?>
                    <?php if ($canPay && $r['status'] === 'approved'): ?>
                    <button type="submit" class="btn btn-sm btn-secondary" name="row_action" value="unapprove:<?= $id ?>" title="هەڵوەشاندنەوەی پەسەندکردن"
                            onclick="return confirm(<?= hr_esc(json_encode('پەسەندکردنی مووچەی ' . $e['full_name'] . ' هەڵبوەشێتەوە؟', JSON_UNESCAPED_UNICODE)) ?>)"><i class="fas fa-rotate-left"></i></button>
                    <?php elseif ($canPay && $paid && !empty($s['receipt_id'])): ?>
                    <button type="submit" class="btn btn-sm btn-danger" name="row_action" value="delete_pay:<?= (int)$s['receipt_id'] ?>" title="سڕینەوەی پێدان و وەسڵەکەی"
                            onclick="return confirm(<?= hr_esc(json_encode('وەسڵی مووچەدانی ' . HrPayroll::receiptNo($s['receipt_id']) . ' — ' . $e['full_name'] . ' بسڕدرێتەوە؟ خەرجییەکەی دەسڕێتەوە و ئەگەر لە قاسە دەرهێنرابێت دەگەڕێتەوە. مووچەکە دەبێتەوە «پەسەندکراو».', JSON_UNESCAPED_UNICODE)) ?>)"><i class="fas fa-trash"></i></button>
                    <?php endif; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot data-hr-refresh="foot"><tr>
                <?php if ($canPay): ?><td></td><?php endif; ?>
                <td colspan="3">کۆ</td>
                <td class="num"><?= hr_money(array_sum(array_column($rows, 'calc'))) ?></td>
                <td class="num"><?= hr_money(array_sum(array_column($rows, 'bonus'))) ?></td>
                <td class="num"><?= hr_money(array_sum(array_column($rows, 'deduction'))) ?></td>
                <td></td>
                <td class="num" id="prSumFinal"><?= hr_money($sum['amount']) ?></td>
                <td colspan="2"></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($canPay && $rows): ?>
    <div class="pr-bar">
        <span class="sel"><b id="prSelN">0</b> کەس دیاریکراون · کۆی <b id="prSelSum">0</b> دینار</span>
        <span class="grow"></span>
        <span class="hint"><i class="fas fa-circle-info"></i> بۆ پێدان، لە ڕیزی هەر کەسێک «پێدان» لێبدە.</span>
        <button type="submit" class="btn btn-secondary" name="action" value="approve"><i class="fas fa-check-double"></i> پەسەندکردنی دیاریکراوەکان</button>
    </div>
    <?php endif; ?>
</form>

<?php hr_receipt_dialog(); ?>
<?php
$monthJs = json_encode($month);
$receiptJs = json_encode($receiptUrl);
$customJS = <<<JS
(function () {
    var form = document.getElementById('prForm');
    if (!form) return;
    var MONTH = {$monthJs}, RECEIPT = {$receiptJs};
    var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
    var num = function (el) { return el ? Math.max(0, parseFloat(String(el.value).replace(/[,\\s]/g, '')) || 0) : 0; };
    var rows = function () { return Array.prototype.slice.call(form.querySelectorAll('tbody tr[data-id]')); };

    function rowFinal(tr) {
        return parseFloat(tr.dataset.calc) + num(tr.querySelector('.pr-bonus')) - num(tr.querySelector('.pr-ded'));
    }
    function selected() {
        return rows().filter(function (tr) { var c = tr.querySelector('.pr-sel'); return c && c.checked && !c.disabled; });
    }
    function update() {
        var total = 0;
        rows().forEach(function (tr) {
            var f = tr.querySelector('.pr-final');
            if (tr.querySelector('.pr-bonus')) {
                var v = rowFinal(tr);
                f.textContent = fmt(v);
                f.classList.toggle('neg', v <= 0);
            }
            total += Math.max(0, parseFloat(f.textContent.replace(/,/g, '')) || 0);
            var c = tr.querySelector('.pr-sel');
            tr.classList.toggle('is-sel', !!(c && c.checked && !c.disabled));
        });
        var sel = selected(), sum = 0;
        sel.forEach(function (tr) { sum += Math.max(0, rowFinal(tr)); });
        var n = document.getElementById('prSelN'); if (n) n.textContent = sel.length;
        var s = document.getElementById('prSelSum'); if (s) s.textContent = fmt(sum);
        var tf = document.getElementById('prSumFinal'); if (tf) tf.textContent = fmt(total);
        var all = document.getElementById('prAll');
        if (all) {
            var boxes = form.querySelectorAll('.pr-sel:not([disabled])'), on = form.querySelectorAll('.pr-sel:not([disabled]):checked');
            all.checked = boxes.length > 0 && on.length === boxes.length;
            all.indeterminate = on.length > 0 && on.length < boxes.length;
        }
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    document.addEventListener('hr:refreshed', update);
    var all = document.getElementById('prAll');
    if (all) all.addEventListener('change', function () {
        form.querySelectorAll('.pr-sel:not([disabled])').forEach(function (c) { c.checked = all.checked; });
        update();
    });

    // «پێدان» on a row: the receipt, with what was typed on the row.
    form.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-pay]');
        if (!b) return;
        var tr = b.closest('tr'), q = new URLSearchParams({month: MONTH, employee_id: b.getAttribute('data-pay')});
        q.set('bonus', num(tr.querySelector('.pr-bonus')));
        q.set('deduction', num(tr.querySelector('.pr-ded')));
        var note = tr.querySelector('.pr-note-in');
        if (note && note.value.trim()) q.set('note', note.value.trim());
        HrReceipt.open(RECEIPT + '&' + q.toString());
    });

    form.addEventListener('submit', function (ev) {
        var btn = ev.submitter;
        if (!btn || btn.name !== 'action') return;
        var sel = selected();
        if (!sel.length) { ev.preventDefault(); alert('هیچ کەسێک دیاری نەکراوە'); return; }
        var sum = 0, doctors = [], zero = 0;
        sel.forEach(function (tr) {
            var v = rowFinal(tr);
            if (v <= 0) zero++; else sum += v;
            if (parseFloat(tr.dataset.commission) > 0) doctors.push(tr.querySelector('.who b').textContent);
        });
        var msg = 'مووچەی ' + sel.length + ' کەس، کۆی ' + fmt(sum) + ' دینار پەسەند دەکرێت. هێشتا پارە نادرێت.';
        if (zero) msg += '\\n' + zero + ' کەس بڕەکەیان سفرە و تێدەپەڕێنرێن.';
        if (doctors.length) msg += '\\n\\nئاگاداری: ' + doctors.join('، ') + ' بەشی داهات وەردەگرن. ئەگەر مووچەشیان پێبدرێت، دوو جار پارە وەردەگرن.';
        if (!confirm(msg + '\\n\\nدڵنیایت؟')) ev.preventDefault();
    });
    update();
})();
JS;
require_once __DIR__ . '/../includes/footer.php';
