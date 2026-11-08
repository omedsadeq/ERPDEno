<?php
/**
 * وەسڵەکانی مووچە — every salary receipt (hr_salary_receipts), newest first,
 * by month, person and signature. A row opens the receipt in the dialog
 * (hr/salary-receipt.php), where it can be signed or deleted; «دەستکاری» opens
 * it straight on its form, with the month, the amounts and the doctor.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_receipt_dialog.php';
require_once __DIR__ . '/../classes/HrPayroll.php';
hr_require('hr.payroll.view');

$payroll = new HrPayroll();
$fMonth = isset($_GET['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$_GET['month']) ? $_GET['month'] : '';
$fEmp = (int)($_GET['employee_id'] ?? 0);
$fSigned = in_array($_GET['signed'] ?? '', ['yes', 'no'], true) ? $_GET['signed'] : '';

$list = $payroll->receipts(['month' => $fMonth, 'employee_id' => $fEmp]);
if ($fSigned !== '') {
    $list = array_values(array_filter($list, fn($r) => (bool)$r['signed'] === ($fSigned === 'yes')));
}
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $list));
$fromCash = array_sum(array_map(fn($r) => (int)$r['from_cashbox'] ? (float)$r['amount'] : 0, $list));
$unsigned = count(array_filter($list, fn($r) => !$r['signed']));

if (($_GET['export'] ?? '') === 'csv') {
    $out = [['ژمارە', 'ڕێکەوت', 'کارمەند', 'PIN', 'مانگ', 'بڕ', 'شێواز', 'خەرجی', 'لەسەر دکتۆر', 'قاسەی لێزان', 'واژۆ', 'پێدەر']];
    foreach ($list as $r) {
        $out[] = [HrPayroll::receiptNo($r['id']), $r['paid_date'], $r['employee_name'], $r['employee_pin'], $r['pay_month'],
            round((float)$r['amount']), HrPayroll::METHODS[$r['payment_method']] ?? '', $r['as_expense'] ? 'بەڵێ' : 'نەخێر', trim(($r['doctor_name'] ?? '') . (!empty($r['doctor2_name']) ? ' + ' . $r['doctor2_name'] : '')),
            $r['from_cashbox'] ? 'بەڵێ' : 'نەخێر', $r['signed'] ? 'بەڵێ' : 'نەخێر', $r['created_by_name']];
    }
    hr_csv('hr-salary-receipts' . ($fMonth ? '-' . $fMonth : '') . '.csv', $out);
}

$employees = (new HrAttendance())->employees(['status' => '']);
$receiptUrl = APP_URL . '/hr/salary-receipt.php?embed=1&id=';
$canEdit = hr_can('hr.payroll.pay');
$hrNavPage = 'salary-receipts';
$pageTitle = 'وەسڵەکانی مووچە';
$qs = $_GET;
unset($qs['export']);
$pageActions = '<a class="btn btn-secondary" href="?' . hr_esc(http_build_query($qs + ['export' => 'csv'])) . '"><i class="fas fa-file-excel"></i> Excel</a>';
$customCSS = $hrBaseCss . <<<CSS
.sr-no{font-weight:800;color:#0f172a;direction:ltr;letter-spacing:.5px}
.sr-table tr[data-hr-receipt]{cursor:pointer}
.sr-doctor{display:block;margin-top:2px;font-size:11.5px;font-weight:600;color:#0f766e}
.sr-actions{white-space:nowrap}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<form class="hr-filter" method="GET">
    <div class="form-group"><label>مانگ</label>
        <input type="month" name="month" class="form-control" value="<?= hr_esc($fMonth) ?>" onchange="this.form.submit()"></div>
    <div class="form-group" style="flex:1"><label>کارمەند</label>
        <select name="employee_id" class="form-control" onchange="this.form.submit()">
            <option value="0">هەموو کارمەندان</option>
            <?= hr_employee_options($employees, $fEmp) ?>
        </select></div>
    <div class="form-group"><label>واژۆ</label>
        <select name="signed" class="form-control" onchange="this.form.submit()">
            <option value="">هەمووی</option>
            <option value="yes" <?= $fSigned === 'yes' ? 'selected' : '' ?>>واژۆ کراو</option>
            <option value="no" <?= $fSigned === 'no' ? 'selected' : '' ?>>واژۆ نەکراو</option>
        </select></div>
    <?php if ($fMonth || $fEmp || $fSigned): ?><a class="btn btn-secondary" href="?">پاککردنەوە</a><?php endif; ?>
</form>

<div data-hr-refresh="sr">
<div class="hr-cards">
    <div class="hr-card" style="--c:#0f766e"><div class="lbl">وەسڵ</div><div class="val"><?= count($list) ?></div><div class="sub">کۆی <?= hr_money($total) ?> دینار</div></div>
    <div class="hr-card" style="--c:#0369a1"><div class="lbl">لە قاسەی لێزان</div><div class="val"><?= hr_money($fromCash) ?></div><div class="sub">دینار</div></div>
    <div class="hr-card" style="--c:<?= $unsigned ? '#b91c1c' : '#64748b' ?>"><div class="lbl">واژۆ نەکراو</div><div class="val"><?= $unsigned ?></div><div class="sub">وەسڵ</div></div>
</div>

<div class="hr-table-wrap">
    <table class="hr-table sr-table">
        <thead><tr><th>ژمارە</th><th>ڕێکەوت</th><th>کارمەند</th><th>مانگ</th><th class="num">بڕ</th><th>شێواز</th><th>خەرجی</th><th>قاسە</th><th>واژۆ</th><th></th></tr></thead>
        <tbody>
        <?php if (!$list): ?>
            <tr><td colspan="10" class="hr-empty">هیچ وەسڵێک نییە</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $r): ?>
            <tr data-hr-receipt="<?= hr_esc($receiptUrl . (int)$r['id']) ?>">
                <td class="sr-no"><?= hr_esc(HrPayroll::receiptNo($r['id'])) ?></td>
                <td class="num"><?= hr_esc($r['paid_date']) ?></td>
                <td><b><?= hr_esc($r['employee_name']) ?></b> <span class="hr-muted"><?= hr_esc($r['employee_pin']) ?></span></td>
                <td class="num"><?= hr_esc(HrPayroll::monthLabel($r['pay_month'])) ?></td>
                <td class="num"><b><?= hr_money($r['amount']) ?></b></td>
                <td><?= hr_esc(HrPayroll::METHODS[$r['payment_method']] ?? '') ?></td>
                <td><?= $r['as_expense'] ? '<span class="hr-badge in">خەرجی</span>' : '<span class="hr-badge off">نەخێر</span>' ?><?php if (!empty($r['doctor_name'])): ?><span class="sr-doctor"><i class="fas fa-user-md"></i> <?= hr_esc($r['doctor_name']) ?></span><?php endif; ?><?php if (!empty($r['doctor2_name'])): ?><span class="sr-doctor"><i class="fas fa-user-md"></i> <?= hr_esc($r['doctor2_name']) ?></span><?php endif; ?></td>
                <td><?= $r['from_cashbox'] ? '<span class="hr-badge staff"><i class="fas fa-vault"></i> قاسە</span>' : '<span class="hr-badge off">نەخێر</span>' ?></td>
                <td><?= $r['signed'] ? '<span class="hr-badge in"><i class="fas fa-signature"></i> واژۆ کراو</span>' : '<span class="hr-badge out">واژۆ نەکراو</span>' ?></td>
                <td class="sr-actions">
                    <button type="button" class="btn btn-sm btn-secondary" data-hr-receipt="<?= hr_esc($receiptUrl . (int)$r['id']) ?>"><i class="fas fa-receipt"></i> بینین</button>
                    <?php if ($canEdit && $r['status'] === 'valid'): ?><button type="button" class="btn btn-sm btn-primary" data-hr-receipt="<?= hr_esc($receiptUrl . (int)$r['id'] . '&edit=1') ?>"><i class="fas fa-pen"></i> دەستکاری</button><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>

<?php hr_receipt_dialog(); ?>
<?php require_once __DIR__ . '/../includes/footer.php';
