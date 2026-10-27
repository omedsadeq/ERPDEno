<?php
/**
 * وەسڵی مووچەدان — one salary receipt. Opens in a dialog from hr/payroll.php
 * and hr/salary-receipts.php (&embed=1), or on its own page.
 *
 *   ?month=Y-m&employee_id=N   before paying (hr.payroll.pay): the receipt as
 *                              it will be, with the bonus, deduction and note,
 *                              the date and the way of paying, «تۆمارکردن وەک
 *                              خەرجی» (on by default), «دەرهێنان لە قاسەی
 *                              لێزان» (off by default; admins) and a pad for
 *                              the person's signature. «پێدان» pays it
 *                              (HrPayroll::payOne) and shows the saved receipt.
 *   ?id=N                      a saved receipt (hr.payroll.view). Not signed
 *                              yet: it can be signed now. «دەستکاری» and
 *                              «سڕینەوە» (hr.payroll.pay).
 *   ?id=N&edit=1               the same form as before paying, filled in.
 *   ?deleted=0012              what is left after «سڕینەوە».
 *
 * After a payment, a signature, a change or a deletion the page tells the
 * page that opened it (postMessage {type: 'hr-receipt'}), which brings its
 * table up to date. PDF: hr/payroll-pdf.php?receipt=N.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/HrPayroll.php';
require __DIR__ . '/../includes/clinic_branding.php';
hr_require('hr.payroll.view');

$canPay = hr_can('hr.payroll.pay');
$canCash = $canPay && HrPayroll::canUseCashbox($hrUserId);
$payroll = new HrPayroll();
$embed = !empty($_REQUEST['embed']);
$id = (int)($_GET['id'] ?? 0);
$error = null;
$here = fn(array $q) => APP_URL . '/hr/salary-receipt.php?' . http_build_query($q + ($embed ? ['embed' => 1] : []));
$posted = fn(string $k) => max(0.0, (float)str_replace([',', ' '], '', (string)($_POST[$k] ?? '0')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    hr_require('hr.payroll.pay');
    $action = (string)($_POST['action'] ?? '');
    try {
        if (in_array($action, ['pay', 'edit'], true)) {
            $date = hr_date($_POST['paid_date'] ?? null);
            if (!$date) {
                throw new InvalidArgumentException('ڕێکەوتی پێدان دروست نییە');
            }
            $item = ['bonus' => $posted('bonus'), 'deduction' => $posted('deduction'), 'note' => (string)($_POST['note'] ?? '')];
            $method = (string)($_POST['method'] ?? '');
            $asExpense = !empty($_POST['as_expense']);
            $fromCashbox = !empty($_POST['from_cashbox']);
            if ($action === 'pay') {
                $rid = $payroll->payOne(hr_month($_POST['month'] ?? null), (int)($_POST['employee_id'] ?? 0), $item, $date, $method,
                    $asExpense, $fromCashbox, (string)($_POST['signature'] ?? ''), $hrUserId);
                header('Location: ' . $here(['id' => $rid, 'done' => 'paid']));
            } else {
                $cleared = $payroll->editPayment($id, $item, $date, $method, $asExpense, $fromCashbox, $hrUserId);
                header('Location: ' . $here(['id' => $id, 'done' => $cleared ? 'edited_unsigned' : 'edited']));
            }
            exit;
        }
        if ($action === 'sign') {
            $payroll->sign($id, (string)($_POST['signature'] ?? ''), $hrUserId);
            header('Location: ' . $here(['id' => $id, 'done' => 'signed']));
            exit;
        }
        if ($action === 'delete') {
            $rc = $payroll->deletePayment($id, $hrUserId);
            header('Location: ' . $here(['deleted' => HrPayroll::receiptNo($id), 'month' => $rc['pay_month'], 'name' => $rc['employee_name']]));
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

// ── After «سڕینەوە»: the receipt is gone, say so ─────────────────────────
if (isset($_GET['deleted'])) {
    $no = preg_replace('/\D/', '', (string)$_GET['deleted']);
    $back = APP_URL . '/hr/payroll.php?month=' . hr_month($_GET['month'] ?? null);
    ?>
<!DOCTYPE html>
<html lang="ku" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>وەسڵ سڕایەوە</title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css"><link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<style>body{background:#f4f6f8;margin:0}.box{max-width:520px;margin:40px auto;padding:28px 22px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;text-align:center}
.box i{font-size:40px;color:#b91c1c}.box h2{margin:12px 0 6px;font-size:19px;color:#0f172a}.box p{color:#64748b;margin:0 0 16px}</style></head>
<body><div class="box"><i class="fas fa-trash-can"></i>
    <h2>وەسڵی مووچەدانی ژمارە <bdo dir="ltr"><?= hr_esc($no) ?></bdo> سڕایەوە</h2>
    <p><?= hr_esc((string)($_GET['name'] ?? '')) ?> — مووچەکەی ئێستا «پەسەندکراو» ـە و دەتوانرێت دووبارە بدرێت.</p>
    <?php if (!$embed): ?><a class="btn btn-primary" href="<?= hr_esc($back) ?>"><i class="fas fa-arrow-right"></i> گەڕانەوە بۆ مووچە</a><?php endif; ?>
</div>
<script>try { window.parent.postMessage({type: 'hr-receipt', done: 'deleted'}, window.location.origin); } catch (e) {}</script>
</body></html>
    <?php
    exit;
}

// ── What to show: a saved receipt (or its form), or the one about to be paid
if ($id > 0) {
    $rc = $payroll->receipt($id);
    if (!$rc) {
        http_response_code(404);
        exit('وەسڵەکە نەدۆزرایەوە');
    }
    $mode = !empty($_GET['edit']) && $canPay && $rc['status'] === 'valid' ? 'edit' : 'saved';
    $v = $rc;
    $v['type'] = $rc['employee_type'];
    if ($mode === 'edit' && $error !== null) {
        // Show what was typed, not what is saved.
        $v = ['bonus' => $posted('bonus'), 'deduction' => $posted('deduction'), 'note' => (string)($_POST['note'] ?? ''),
              'paid_date' => hr_date($_POST['paid_date'] ?? null) ?? $rc['paid_date'],
              'payment_method' => isset(HrPayroll::METHODS[$_POST['method'] ?? '']) ? $_POST['method'] : $rc['payment_method'],
              'as_expense' => !empty($_POST['as_expense']), 'from_cashbox' => !empty($_POST['from_cashbox'])] + $v;
        $v['amount'] = (float)$v['calculated'] + $v['bonus'] - $v['deduction'];
    }
} else {
    if (!$canPay) {
        header('Location: ' . APP_URL . '/403.php');
        exit;
    }
    $month = hr_month($_REQUEST['month'] ?? null);
    $empId = (int)($_REQUEST['employee_id'] ?? 0);
    $row = $payroll->month($month)[$empId] ?? null;
    if (!$row) {
        http_response_code(404);
        exit('کارمەند نەدۆزرایەوە');
    }
    if ($row['status'] === 'paid' && !empty($row['saved']['receipt_id'])) {
        // Already paid (perhaps from another screen meanwhile): show its receipt.
        header('Location: ' . $here(['id' => (int)$row['saved']['receipt_id']] + ($error !== null ? ['done' => 'already'] : [])));
        exit;
    }
    $mode = 'draft';
    $e = $row['employee'];
    $s = $row['saved'];
    $num = fn($k, $fallback) => isset($_REQUEST[$k]) && $_REQUEST[$k] !== '' ? max(0.0, (float)str_replace([',', ' '], '', (string)$_REQUEST[$k])) : $fallback;
    $v = [
        'id' => null, 'pay_month' => $month, 'employee_id' => $empId,
        'employee_name' => $e['full_name'], 'employee_pin' => hr_pin($e), 'type' => $e['employee_type'],
        'department_name' => $e['department_name'] ?? null,
        'net_hours' => $row['hours'], 'hourly_rate' => (float)$e['hourly_rate'], 'basic_salary' => (float)$e['basic_salary'],
        'salary_percentage' => (float)$e['salary_percentage'], 'calculated' => (float)$row['pay']['rounded'],
        'bonus' => $num('bonus', $s ? (float)$s['bonus'] : 0.0),
        'deduction' => $num('deduction', $s ? (float)$s['deduction'] : 0.0),
        'note' => (string)($_REQUEST['note'] ?? ($s['note'] ?? '')),
        'paid_date' => hr_date($_POST['paid_date'] ?? null) ?? date('Y-m-d'),
        'payment_method' => isset(HrPayroll::METHODS[$_POST['method'] ?? '']) ? $_POST['method'] : 'cash',
        'as_expense' => $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['as_expense']) : true,
        'from_cashbox' => $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['from_cashbox']),
        'signature' => null, 'status' => 'valid', 'created_by_name' => $hrAuth->getCurrentUser()['full_name'] ?? '',
        'commission' => $row['commission'],
    ];
    $v['amount'] = $v['calculated'] + $v['bonus'] - $v['deduction'];
}

$pay = HrAttendance::pay(['hourly_rate' => $v['hourly_rate'], 'basic_salary' => $v['basic_salary'], 'salary_percentage' => $v['salary_percentage']], (float)$v['net_hours']);
$cancelled = $v['status'] === 'cancelled';
$form = $mode === 'draft' || $mode === 'edit';
$canSign = $mode === 'draft' || ($mode === 'saved' && $canPay && !$cancelled && empty($v['signature']));
$done = $mode === 'saved' ? ($_GET['done'] ?? '') : '';
$balance = $form && $canCash ? HrPayroll::cashboxBalance() : null;
$e = 'hr_esc';
$pct = rtrim(rtrim(number_format((float)$v['salary_percentage'], 2), '0'), '.');
$typeLabel = $v['type'] === 'doctor' ? 'دکتۆر' : 'کارمەند';
$backUrl = APP_URL . '/hr/payroll.php?month=' . $v['pay_month'];
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>وەسڵی مووچەدان<?= $mode === 'saved' ? ' ' . $e(HrPayroll::receiptNo($v['id'])) : '' ?> — <?= $e($v['employee_name']) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
body.rcp { background: #eef2f5; margin: 0; min-height: 100vh; }
body.rcp.embed { background: #f4f6f8; }
.rcp-bar { display: flex; align-items: center; gap: 10px; padding: 8px 14px; background: #fff; border-bottom: 1px solid #e2e8f0; }
.rcp-bar a { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 9px; background: #0f766e; color: #fff; font-weight: 700; text-decoration: none; }
.rcp-main { max-width: 620px; margin: 0 auto; padding: 16px 12px 28px; }
.embed .rcp-main { padding-top: 12px; }
.rcp-msg { border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-weight: 700; font-size: 13.5px; }
.rcp-msg.ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.rcp-msg.err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.rcp-msg.warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; font-weight: 600; }

.rc { position: relative; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px -18px rgba(15, 23, 42, .45); overflow: hidden; border: 1px solid #e2e8f0; }
.rc::before { content: ''; display: block; height: 6px; background: linear-gradient(90deg, #0f766e, #14b8a6, #0ea5e9); }
.rc-head { display: flex; align-items: center; gap: 12px; padding: 16px 20px 12px; border-bottom: 1px dashed #cbd5e1; }
.rc-head img { width: 52px; height: 52px; object-fit: contain; }
.rc-head .who { flex: 1; min-width: 0; }
.rc-clinic { font-size: 13px; font-weight: 700; color: #0f766e; }
.rc-title { font-size: 21px; font-weight: 800; color: #0f172a; line-height: 1.3; }
.rc-no { text-align: left; font-size: 12px; color: #64748b; line-height: 1.7; white-space: nowrap; }
.rc-no b { display: block; font-size: 18px; color: #0f172a; direction: ltr; letter-spacing: 1px; }
.rc-info { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: #f1f5f9; border-bottom: 1px solid #f1f5f9; }
.rc-info div { background: #fff; padding: 9px 20px; }
.rc-info span { display: block; font-size: 11.5px; color: #94a3b8; font-weight: 700; }
.rc-info b { font-size: 14.5px; color: #0f172a; }
.rc-lines { width: 100%; border-collapse: collapse; margin: 6px 0; }
.rc-lines td { padding: 8px 20px; font-size: 14px; border-bottom: 1px solid #f8fafc; }
.rc-lines td:last-child { text-align: left; direction: ltr; font-variant-numeric: tabular-nums; font-weight: 700; color: #334155; white-space: nowrap; }
.rc-lines tr.sub td { color: #64748b; font-size: 13px; }
.rc-lines tr.calc td { border-top: 1px solid #e2e8f0; font-weight: 800; color: #0f172a; }
.rc-lines .plus { color: #15803d !important; }
.rc-lines .minus { color: #b91c1c !important; }
.rc-lines input.form-control { width: 140px; height: 34px; direction: ltr; text-align: left; display: inline-block; font-weight: 700; }
.rc-total { margin: 4px 16px 14px; padding: 14px 18px; border-radius: 12px; background: linear-gradient(135deg, #0f766e, #0d9488); color: #fff;
    display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.rc-total .lbl { font-size: 15px !important; font-weight: 700; opacity: .92; }
.rc-total .amt { direction: ltr; white-space: nowrap; line-height: 1.2; }
.rc-total .amt #rcAmount { font-size: 30px !important; font-weight: 800; font-variant-numeric: tabular-nums; }
.rc-total .amt small { font-size: 14px !important; font-weight: 700; opacity: .85; margin-inline-start: 6px; }
.rc-note { margin: 0 20px 12px; font-size: 13px; color: #475569; }
.rc-note input { width: 100%; }
.rc-pay { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 0 20px 12px; }
.rc-pay label { font-size: 12px; color: #64748b; font-weight: 700; display: block; margin-bottom: 3px; }
.rc-pay .form-control { width: 100%; height: 38px; }
.rc-exp { display: flex; align-items: center; gap: 10px; margin: 0 20px 14px; padding: 10px 12px; border: 1.5px solid #99f6e4; background: #f0fdfa;
    border-radius: 10px; cursor: pointer; font-weight: 700; color: #0f766e; font-size: 13.5px; }
.rc-exp input { width: 20px; height: 20px; accent-color: #0f766e; }
.rc-exp small { display: block; font-weight: 600; color: #64748b; font-size: 11.5px; }
.rc-exp.off { border-color: #e2e8f0; background: #f8fafc; color: #64748b; }
.rc-exp.rc-cash { border-color: #bae6fd; background: #f0f9ff; color: #0369a1; }
.rc-exp.rc-cash input { accent-color: #0369a1; }
.rc-exp.rc-cash.off { border-color: #e2e8f0; background: #f8fafc; color: #64748b; }
.rc-exp.is-locked { cursor: not-allowed; opacity: .75; }
.rc-exp small b { color: #0f172a; }
.rc-meta { display: flex; flex-wrap: wrap; gap: 6px 16px; padding: 10px 20px; font-size: 12.5px; color: #475569; background: #f8fafc; border-top: 1px solid #f1f5f9; }
.rc-meta b { color: #0f172a; }
.rc-sign { padding: 12px 20px 18px; }
.rc-sign .lbl { display: flex; align-items: center; justify-content: space-between; font-size: 13px; font-weight: 800; color: #334155; margin-bottom: 6px; }
.rc-sign .lbl button { border: 0; background: none; color: #b91c1c; font-weight: 700; cursor: pointer; font-size: 12.5px; }
.rc-pad { position: relative; height: 170px; border: 2px dashed #94a3b8; border-radius: 12px; background: #fff; touch-action: none; }
.rc-pad canvas { position: absolute; inset: 0; width: 100%; height: 100%; border-radius: 10px; cursor: crosshair; touch-action: none; }
.rc-pad .hint { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 15px; font-weight: 700; pointer-events: none; }
.rc-pad.has-ink .hint { display: none; }
.rc-pad::after { content: ''; position: absolute; left: 24px; right: 24px; bottom: 34px; border-bottom: 1px solid #e2e8f0; pointer-events: none; }
.rc-signed { height: 130px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
.rc-signed img { max-height: 120px; max-width: 100%; }
.rc-signed-at { font-size: 11.5px; color: #94a3b8; margin-top: 4px; text-align: center; }
.rc-unsigned { border: 1px dashed #fca5a5; background: #fff7f7; color: #b91c1c; border-radius: 12px; padding: 12px; text-align: center; font-weight: 700; font-size: 13px; }
.rc-stamp { position: absolute; top: 38%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); border: 4px solid #dc2626; color: #dc2626;
    font-size: 30px; font-weight: 900; padding: 6px 22px; border-radius: 12px; opacity: .8; background: rgba(255, 255, 255, .75); pointer-events: none; white-space: nowrap; }
.rcp-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.rcp-actions .btn { height: 44px; padding: 0 18px; font-size: 15px; font-weight: 800; border-radius: 10px; }
.rcp-actions .grow { flex: 1; }
.btn-pay { background: #15803d; border-color: #15803d; color: #fff; }
.btn-pay:hover { background: #166534; color: #fff; }
@media (max-width: 520px) {
    .rc-head { padding: 14px 14px 10px; }
    .rc-title { font-size: 18px; }
    .rc-info { grid-template-columns: 1fr 1fr; }
    .rc-info div, .rc-lines td, .rc-meta, .rc-sign { padding-left: 14px; padding-right: 14px; }
    .rc-pay { padding: 0 14px 12px; }
    .rc-exp, .rc-note { margin-left: 14px; margin-right: 14px; }
    .rc-total .amt #rcAmount { font-size: 25px !important; }
    .rc-lines input.form-control { width: 110px; }
    .rcp-actions .grow { display: none; }
    .rcp-actions .btn { flex: 1 1 100%; justify-content: center; white-space: nowrap; }
}
    </style>
</head>
<body class="rcp<?= $embed ? ' embed' : '' ?>">
<?php if (!$embed): ?>
<div class="rcp-bar"><a href="<?= $e($backUrl) ?>"><i class="fas fa-arrow-right"></i> گەڕانەوە بۆ مووچە</a></div>
<?php endif; ?>
<main class="rcp-main">
    <?php if ($error): ?><div class="rcp-msg err"><i class="fas fa-circle-exclamation"></i> <?= $e($error) ?></div><?php endif; ?>
    <?php if ($done === 'paid'): ?><div class="rcp-msg ok"><i class="fas fa-circle-check"></i> مووچەکە درا و وەسڵەکە پاشەکەوت کرا<?= $v['as_expense'] ? '، و وەک خەرجی گشتی تۆمار کرا' : '' ?>.</div><?php endif; ?>
    <?php if ($done === 'signed'): ?><div class="rcp-msg ok"><i class="fas fa-circle-check"></i> واژۆکە پاشەکەوت کرا.</div><?php endif; ?>
    <?php if ($done === 'already'): ?><div class="rcp-msg err"><i class="fas fa-circle-exclamation"></i> مووچەی ئەم مانگەی پێشتر دراوە. ئەمە وەسڵەکەیەتی.</div><?php endif; ?>
    <?php if ($done === 'edited'): ?><div class="rcp-msg ok"><i class="fas fa-circle-check"></i> گۆڕانکارییەکان پاشەکەوت کران.</div><?php endif; ?>
    <?php if ($done === 'edited_unsigned'): ?><div class="rcp-msg warn"><i class="fas fa-signature"></i> گۆڕانکارییەکان پاشەکەوت کران. بڕەکە گۆڕا، بۆیە واژۆ کۆنەکە لابرا: با کارمەندەکە دووبارە واژۆ بکات.</div><?php endif; ?>
    <?php if ($mode === 'edit'): ?><div class="rcp-msg warn"><i class="fas fa-pen"></i> دەستکاریکردنی وەسڵی <bdo dir="ltr"><?= $e(HrPayroll::receiptNo($v['id'])) ?></bdo>. خەرجی و قاسەکەش لەگەڵیدا ڕاست دەکرێنەوە.<?= !empty($v['signature']) ? ' ئەگەر بڕەکە بگۆڕیت، واژۆکە لادەچێت.' : '' ?></div><?php endif; ?>
    <?php if ($mode === 'draft' && $v['commission'] > 0): ?>
    <div class="rcp-msg warn"><i class="fas fa-triangle-exclamation"></i> <?= $e($v['employee_name']) ?> لە «دابەشکردنی داهات» <?= $e(rtrim(rtrim(number_format($v['commission'], 2), '0'), '.')) ?>% وەردەگرێت. ئەگەر مووچەشی پێبدرێت، دوو جار پارە وەردەگرێت.</div>
    <?php endif; ?>

    <form method="POST" id="rcForm" autocomplete="off">
        <?= Csrf::field() ?>
        <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <input type="hidden" name="action" value="<?= ['draft' => 'pay', 'edit' => 'edit', 'saved' => 'sign'][$mode] ?>">
        <input type="hidden" name="signature" id="rcSignature" value="">
        <?php if ($mode === 'draft'): ?>
        <input type="hidden" name="month" value="<?= $e($v['pay_month']) ?>">
        <input type="hidden" name="employee_id" value="<?= (int)$v['employee_id'] ?>">
        <?php endif; ?>

        <div class="rc">
            <?php if ($cancelled): ?><div class="rc-stamp">هەڵوەشاوەتەوە</div><?php endif; ?>
            <div class="rc-head">
                <?php if ($logoUrl): ?><img src="<?= $e($logoUrl) ?>" alt=""><?php endif; ?>
                <div class="who">
                    <div class="rc-clinic"><?= $e($clinicName) ?></div>
                    <div class="rc-title">وەسڵی مووچەدان</div>
                </div>
                <div class="rc-no">ژمارە<b><?= $mode !== 'draft' ? $e(HrPayroll::receiptNo($v['id'])) : '— — — —' ?></b></div>
            </div>
            <div class="rc-info">
                <div><span>وەرگر</span><b><?= $e($v['employee_name']) ?></b></div>
                <div><span>مووچەی مانگی</span><b><bdo dir="ltr"><?= $e(HrPayroll::monthLabel($v['pay_month'])) ?></bdo></b></div>
                <div><span>PIN · جۆر</span><b><?= $e($v['employee_pin']) ?> · <?= $typeLabel ?></b></div>
                <div><span>بەش</span><b><?= $e($v['department_name'] ?: '—') ?></b></div>
            </div>

            <table class="rc-lines">
                <tr class="sub"><td>کاتژمێری کار</td><td><?= $e(HrAttendance::hm((float)$v['net_hours'], false)) ?></td></tr>
                <?php if ($pay['fixed']): ?>
                <tr class="sub"><td>مووچەی جێگیر</td><td><?= hr_money($pay['basic']) ?></td></tr>
                <?php else: ?>
                <tr class="sub"><td>کرێی کاتژمێر × کاتژمێر</td><td><?= hr_money($pay['rate']) ?> × <?= $e(HrAttendance::hm((float)$v['net_hours'], false)) ?> = <?= hr_money($pay['base']) ?></td></tr>
                <?php endif; ?>
                <?php if ($pay['pct'] > 0): ?>
                <tr class="sub"><td>ڕێژە <?= $e($pct) ?>%</td><td class="plus">+<?= hr_money($pay['pct_amount']) ?></td></tr>
                <?php endif; ?>
                <tr class="calc"><td>مووچەی حیسابکراو</td><td id="rcCalc" data-v="<?= (float)$v['calculated'] ?>"><?= hr_money($v['calculated']) ?></td></tr>
                <?php if ($form): ?>
                <tr><td>+ زیادە</td><td><input type="number" min="0" step="1" name="bonus" id="rcBonus" class="form-control plus" value="<?= (float)$v['bonus'] ? (int)$v['bonus'] : '' ?>" placeholder="0" inputmode="numeric"></td></tr>
                <tr><td>− کەمکردنەوە <small class="hr-muted">(سولفە، بڕین…)</small></td><td><input type="number" min="0" step="1" name="deduction" id="rcDed" class="form-control minus" value="<?= (float)$v['deduction'] ? (int)$v['deduction'] : '' ?>" placeholder="0" inputmode="numeric"></td></tr>
                <?php else: ?>
                <?php if ((float)$v['bonus'] > 0): ?><tr><td>+ زیادە</td><td class="plus">+<?= hr_money($v['bonus']) ?></td></tr><?php endif; ?>
                <?php if ((float)$v['deduction'] > 0): ?><tr><td>− کەمکردنەوە</td><td class="minus">−<?= hr_money($v['deduction']) ?></td></tr><?php endif; ?>
                <?php endif; ?>
            </table>

            <div class="rc-total"><span class="lbl">بڕی دراو</span><span class="amt"><span id="rcAmount"><?= hr_money($v['amount']) ?></span><small>دینار</small></span></div>

            <?php if ($form): ?>
            <div class="rc-note"><input type="text" name="note" maxlength="255" class="form-control" value="<?= $e($v['note']) ?>" placeholder="تێبینی (ئارەزوومەندانە)"></div>
            <div class="rc-pay">
                <div><label for="rcDate">ڕێکەوتی پێدان</label><input type="date" id="rcDate" name="paid_date" class="form-control" value="<?= $e($v['paid_date']) ?>" required></div>
                <div><label for="rcMethod">شێوازی پێدان</label>
                    <select id="rcMethod" name="method" class="form-control">
                        <?php foreach (HrPayroll::METHODS as $k => $label): ?><option value="<?= $k ?>" <?= $v['payment_method'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <label class="rc-exp<?= $v['as_expense'] ? '' : ' off' ?>" id="rcExpLbl">
                <input type="checkbox" name="as_expense" value="1" id="rcExp" <?= $v['as_expense'] ? 'checked' : '' ?>>
                <span>تۆمارکردن وەک خەرجی لە سیستەم<small>لە «خەرجی گشتی»، پۆلی «مووچە». ئەگەر لای ببەیت، وەک خەرجی تۆمار ناکرێت.</small></span>
            </label>
            <label class="rc-exp rc-cash<?= $v['from_cashbox'] && $canCash ? '' : ' off' ?><?= $canCash ? '' : ' is-locked' ?>" id="rcCashLbl">
                <input type="checkbox" name="from_cashbox" value="1" id="rcCash" <?= $v['from_cashbox'] && $canCash ? 'checked' : '' ?> <?= $canCash ? '' : 'disabled' ?>>
                <span>دەرهێنانی پارەکە لە قاسەی لێزان
                    <small><?php if (!$canCash): ?>تەنها بەڕێوەبەر دەتوانێت پارە لە قاسەی لێزان دەربهێنێت.<?php else: ?>بڕەکە لە «قاسەی لێزان» کەم دەکرێتەوە.<?php if ($balance !== null): ?> پارەی ئێستای قاسە: <b><bdo dir="ltr"><?= hr_money($balance) ?></bdo></b> دینار<?php endif; ?><?php endif; ?></small></span>
            </label>
            <?php else: ?>
            <?php if ($v['note']): ?><div class="rc-note"><b>تێبینی:</b> <?= $e($v['note']) ?></div><?php endif; ?>
            <div class="rc-meta">
                <span>ڕێکەوت: <b><bdo dir="ltr"><?= $e($v['paid_date']) ?></bdo></b></span>
                <span>شێواز: <b><?= $e(HrPayroll::METHODS[$v['payment_method']] ?? '') ?></b></span>
                <span>پێدەر: <b><?= $e($v['created_by_name'] ?? '—') ?></b></span>
                <span><?= (int)$v['as_expense'] ? '<i class="fas fa-check" style="color:#15803d"></i> وەک خەرجی تۆمار کراوە' : '<i class="fas fa-minus" style="color:#94a3b8"></i> وەک خەرجی تۆمار نەکراوە' ?></span>
                <span><?= (int)($v['from_cashbox'] ?? 0) ? '<i class="fas fa-vault" style="color:#0f766e"></i> لە قاسەی لێزان دەرهێنراوە' : '<i class="fas fa-minus" style="color:#94a3b8"></i> لە قاسەی لێزان نەدەرهێنراوە' ?></span>
                <?php if ($cancelled): ?><span style="color:#b91c1c">هەڵوەشێنراوەتەوە لەلایەن <b style="color:#b91c1c"><?= $e($v['cancelled_by_name'] ?? '') ?></b> · <bdo dir="ltr"><?= $e($v['cancelled_at']) ?></bdo></span><?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="rc-sign">
                <?php if (!empty($v['signature'])): ?>
                <div class="lbl">واژۆی وەرگر</div>
                <div class="rc-signed"><img src="<?= $e($v['signature']) ?>" alt="واژۆ"></div>
                <div class="rc-signed-at">واژۆ کرا لە <bdo dir="ltr"><?= $e($v['signed_at']) ?></bdo></div>
                <?php elseif ($canSign): ?>
                <div class="lbl"><span><i class="fas fa-signature"></i> واژۆی وەرگر</span><button type="button" id="rcClear"><i class="fas fa-eraser"></i> سڕینەوە</button></div>
                <div class="rc-pad" id="rcPad"><canvas></canvas><div class="hint">لێرە بە پەنجە یان ماوس واژۆ بکە</div></div>
                <?php else: ?>
                <div class="lbl">واژۆی وەرگر</div>
                <div class="rc-unsigned"><i class="fas fa-signature"></i> واژۆ نەکراوە</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rcp-actions">
            <?php if ($mode === 'draft'): ?>
            <button type="submit" class="btn btn-pay"><i class="fas fa-money-bill-wave"></i> پێدان و پاشەکەوتکردنی وەسڵ</button>
            <?php elseif ($mode === 'edit'): ?>
            <button type="submit" class="btn btn-pay"><i class="fas fa-floppy-disk"></i> پاشەکەوتکردنی گۆڕانکارییەکان</button>
            <a class="btn btn-secondary" href="<?= $e($here(['id' => (int)$v['id']])) ?>"><i class="fas fa-xmark"></i> پاشگەزبوونەوە</a>
            <?php elseif ($canSign): ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-signature"></i> پاشەکەوتکردنی واژۆ</button>
            <?php endif; ?>
            <span class="grow"></span>
            <?php if ($mode === 'saved'): ?>
            <?php if ($canPay && !$cancelled): ?>
            <a class="btn btn-secondary" href="<?= $e($here(['id' => (int)$v['id'], 'edit' => 1])) ?>"><i class="fas fa-pen"></i> دەستکاری</a>
            <?php endif; ?>
            <a class="btn btn-secondary" target="_blank" href="<?= APP_URL ?>/hr/payroll-pdf.php?receipt=<?= (int)$v['id'] ?>"><i class="fas fa-file-pdf"></i> PDF</a>
            <?php if ($canPay): ?>
            <button type="submit" form="rcDelForm" class="btn btn-danger"><i class="fas fa-trash"></i> سڕینەوە</button>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($mode === 'saved' && $canPay): ?>
    <form method="POST" id="rcDelForm" onsubmit="return confirm(<?= $e(json_encode(
        'وەسڵی مووچەدانی ' . HrPayroll::receiptNo($v['id']) . ' — ' . $v['employee_name'] . ' بسڕدرێتەوە؟'
        . ((int)$v['as_expense'] ? "\n• خەرجییەکەی دەسڕێتەوە." : '')
        . ((int)($v['from_cashbox'] ?? 0) ? "\n• " . hr_money($v['amount']) . ' دینار دەگەڕێتەوە ناو قاسەی لێزان.' : '')
        . "\n• مووچەکە دەبێتەوە «پەسەندکراو» و دەتوانرێت دووبارە بدرێت.", JSON_UNESCAPED_UNICODE)) ?>)">
        <?= Csrf::field() ?>
        <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <input type="hidden" name="action" value="delete">
    </form>
    <?php endif; ?>
</main>

<script>
(function () {
    var form = document.getElementById('rcForm');
    var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
    var num = function (el) { return el ? Math.max(0, parseFloat(String(el.value).replace(/[,\s]/g, '')) || 0) : 0; };

    // Live total while the bonus or deduction is typed.
    var calc = document.getElementById('rcCalc'), amount = document.getElementById('rcAmount');
    var bonus = document.getElementById('rcBonus'), ded = document.getElementById('rcDed');
    function total() { return parseFloat(calc.dataset.v) + num(bonus) - num(ded); }
    if (bonus) {
        form.addEventListener('input', function () { amount.textContent = fmt(total()); });
    }
    var exp = document.getElementById('rcExp'), cash = document.getElementById('rcCash');
    if (exp) exp.addEventListener('change', function () { document.getElementById('rcExpLbl').classList.toggle('off', !exp.checked); });
    if (cash) cash.addEventListener('change', function () { document.getElementById('rcCashLbl').classList.toggle('off', !cash.checked); });

    // Signature pad: pointer events cover mouse, finger and pen.
    var padBox = document.getElementById('rcPad'), canvas = padBox && padBox.querySelector('canvas'), ctx, ink = false, last = null, drawing = false;
    function fit() {
        var r = canvas.getBoundingClientRect(), d = window.devicePixelRatio || 1;
        canvas.width = Math.round(r.width * d);
        canvas.height = Math.round(r.height * d);
        ctx = canvas.getContext('2d');
        ctx.setTransform(d, 0, 0, d, 0, 0);
        ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = ctx.fillStyle = '#0f172a';
    }
    function at(ev) { var r = canvas.getBoundingClientRect(); return {x: ev.clientX - r.left, y: ev.clientY - r.top}; }
    function clear() { ctx.clearRect(0, 0, canvas.width, canvas.height); ink = false; padBox.classList.remove('has-ink'); }
    if (canvas) {
        fit();
        window.addEventListener('resize', function () { if (!ink) fit(); });
        canvas.addEventListener('pointerdown', function (ev) {
            ev.preventDefault();
            drawing = true; last = at(ev);
            canvas.setPointerCapture(ev.pointerId);
            ctx.beginPath(); ctx.arc(last.x, last.y, 1.2, 0, Math.PI * 2); ctx.fill();
            ink = true; padBox.classList.add('has-ink');
        });
        canvas.addEventListener('pointermove', function (ev) {
            if (!drawing) return;
            var p = at(ev);
            ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
            last = p;
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { canvas.addEventListener(t, function () { drawing = false; }); });
        document.getElementById('rcClear').addEventListener('click', clear);
    }
    /**
     * The drawing at a fixed size (600 px wide), as a JPEG on white: a
     * browser's PNG always has transparency, which the PDF (mPDF) can only
     * draw with PHP's GD extension — a JPEG it draws everywhere.
     */
    function signature() {
        if (!ink) return '';
        var out = document.createElement('canvas'), w = 600, h = Math.round(600 * canvas.height / canvas.width), o = out.getContext('2d');
        out.width = w; out.height = h;
        o.fillStyle = '#fff';
        o.fillRect(0, 0, w, h);
        o.drawImage(canvas, 0, 0, w, h);
        return out.toDataURL('image/jpeg', 0.92);
    }

    form.addEventListener('submit', function (ev) {
        var action = form.querySelector('input[name="action"]').value;
        if (canvas) document.getElementById('rcSignature').value = signature();
        if (action === 'sign') {
            if (!ink) { ev.preventDefault(); alert('سەرەتا لە چوارچێوەکەدا واژۆ بکە'); }
            return;
        }
        if (total() <= 0) { ev.preventDefault(); alert('بڕی مووچە دەبێت لە سفر زیاتر بێت'); return; }
        var name = <?= json_encode($v['employee_name'], JSON_UNESCAPED_UNICODE) ?>;
        var msg = action === 'edit'
            ? 'گۆڕانکارییەکانی وەسڵی ' + name + ' پاشەکەوت بکرێن؟ بڕی نوێ: ' + fmt(total()) + ' دینار.'
            : name + ' — ' + fmt(total()) + ' دینار دەدرێت.';
        msg += exp && exp.checked ? '\n• وەک خەرجی گشتی تۆمار دەکرێت.' : '\n• وەک خەرجی تۆمار ناکرێت.';
        msg += cash && cash.checked ? '\n• لە قاسەی لێزان دەردەهێنرێت.' : '\n• لە قاسەی لێزان دەرناهێنرێت.';
        <?php if ($mode === 'edit' && !empty($v['signature'])): ?>
        if (Math.abs(total() - <?= (float)$v['amount'] ?>) >= 0.5) msg += '\n• بڕەکە گۆڕاوە، بۆیە واژۆکە لادەچێت.';
        <?php endif; ?>
        if (canvas && !ink) msg += '\n\nواژۆ نەکراوە — دواتر دەتوانرێت لە وەسڵەکەدا واژۆ بکرێت.';
        if (!confirm(msg + '\n\nدڵنیایت؟')) { ev.preventDefault(); return; }
        form.querySelector('button[type="submit"]').disabled = true;
    });

    <?php if ($done): ?>
    // Tell the page that opened this receipt to bring its table up to date.
    try { window.parent.postMessage({type: 'hr-receipt', id: <?= (int)$v['id'] ?>, done: <?= json_encode($done) ?>}, window.location.origin); } catch (e) {}
    <?php endif; ?>
})();
</script>
</body>
</html>
