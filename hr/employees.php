<?php
/**
 * کارمەندان — everyone who punches the fingerprint device, doctors and staff.
 *
 * The list only. Each row opens the employee's profile (hr/employee.php:
 * details and documents) with «پیشاندان», or the form (hr/employee-edit.php,
 * which also adds and deletes) with «دەستکاری».
 *
 * Pay is shown only with hr.payroll.view; the photo and the documents column
 * only with hr.documents.view.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_employee_ui.php';
hr_require('hr.employees.view');

$canManage = hr_can('hr.employees.manage');
$canPay = hr_can('hr.payroll.view');
$canDocs = hr_can('hr.documents.view');

$fType = in_array($_GET['type'] ?? '', ['staff', 'doctor'], true) ? $_GET['type'] : '';
$fStatus = in_array($_GET['status'] ?? 'active', ['active', 'inactive', ''], true) ? ($_GET['status'] ?? 'active') : 'active';
$hr = new HrAttendance();
$employees = $hr->employees(['status' => $fStatus, 'type' => $fType]);
$docModel = new HrDocument();
$photos = $canDocs ? $docModel->photoIds() : [];
$docCounts = $canDocs ? $docModel->counts() : [];

$pageTitle = 'کارمەندان';
if ($canManage) {
    $pageActions = '<a class="btn btn-primary" href="' . APP_URL . '/hr/employee-edit.php"><i class="fas fa-user-plus"></i> کارمەندی نوێ</a>';
}
$customCSS = $hrBaseCss . $hrEmpCss . <<<CSS
.hr-emp-name{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit}
.hr-emp-name b{font-size:13.5px;color:#111827;white-space:nowrap}
.hr-emp-name:hover b{color:#0f766e;text-decoration:underline}
.hr-emp-name small{display:block;color:#9ca3af;font-size:11.5px}
.hr-pay small{display:block;color:#9ca3af;font-size:11px}
.hr-docs-n{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:#475569;text-decoration:none}
.hr-docs-n.is-none{color:#cbd5e1}
.hr-docs-n .warn{color:#b91c1c}
.hr-docs-n .soon{color:#b45309}
.hr-row-actions{white-space:nowrap;display:flex;gap:6px}
tr.is-inactive td{opacity:.55}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<form class="hr-filter" method="GET">
    <div class="form-group" style="flex:1;min-width:200px">
        <label>گەڕان</label>
        <input type="search" class="form-control" id="hrEmpSearch" placeholder="ناو، PIN، ژمارەی مۆبایل…" autocomplete="off">
    </div>
    <div class="form-group">
        <label>جۆر</label>
        <select name="type" class="form-control" onchange="this.form.submit()">
            <option value="">هەموو</option>
            <option value="staff" <?= $fType === 'staff' ? 'selected' : '' ?>>کارمەند</option>
            <option value="doctor" <?= $fType === 'doctor' ? 'selected' : '' ?>>دکتۆر</option>
        </select>
    </div>
    <div class="form-group">
        <label>دۆخ</label>
        <select name="status" class="form-control" onchange="this.form.submit()">
            <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>چالاک</option>
            <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>ناچالاک</option>
            <option value="" <?= $fStatus === '' ? 'selected' : '' ?>>هەموو</option>
        </select>
    </div>
    <div class="hr-muted" style="font-size:12.5px;padding-bottom:8px"><?= count($employees) ?> کەس</div>
</form>

<div class="hr-table-wrap">
    <table class="hr-table" id="hrEmpTable">
        <thead>
            <tr>
                <th>PIN</th>
                <th>ناو</th>
                <th>جۆر</th>
                <th>بەش / شەفت</th>
                <th>مۆبایل</th>
                <th>دەستپێکی کار</th>
                <?php if ($canPay): ?><th>مووچە</th><?php endif; ?>
                <?php if ($canDocs): ?><th>بەڵگەنامە</th><?php endif; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$employees): ?>
            <tr><td colspan="9" class="hr-empty">هیچ کارمەندێک نییە</td></tr>
        <?php endif; ?>
        <?php foreach ($employees as $e):
            $id = (int)$e['id'];
            $pay = HrAttendance::pay($e, 0);
            $search = mb_strtolower(hr_pin($e) . ' ' . $e['full_name'] . ' ' . $e['phone'] . ' ' . $e['position'] . ' ' . $e['username']);
            $view = APP_URL . '/hr/employee.php?id=' . $id;
            $dc = $docCounts[$id] ?? ['n' => 0, 'expired' => 0, 'soon' => 0];
        ?>
            <tr class="<?= $e['status'] === 'inactive' ? 'is-inactive' : '' ?>" data-search="<?= hr_esc($search) ?>">
                <td class="num"><b><?= hr_esc(hr_pin($e)) ?></b></td>
                <td>
                    <a class="hr-emp-name" href="<?= hr_esc($view) ?>">
                        <?= hr_emp_avatar($e, $photos[$id] ?? null) ?>
                        <span><b><?= hr_esc($e['full_name']) ?></b><?php if ($e['position']): ?><small><?= hr_esc($e['position']) ?></small><?php endif; ?>
                            <?php if ($e['user_id']): ?><small title="ئەکاونت لە سیستەم"><i class="fas fa-link"></i> <bdi><?= hr_esc($e['username']) ?></bdi></small><?php endif; ?></span>
                    </a>
                </td>
                <td><span class="hr-badge <?= $e['employee_type'] ?>"><?= $e['employee_type'] === 'doctor' ? 'دکتۆر' : 'کارمەند' ?></span>
                    <?php if ($e['status'] === 'inactive'): ?><span class="hr-badge off">ناچالاک</span><?php endif; ?></td>
                <td><?= hr_esc($e['department_name'] ?: '—') ?><br><small class="hr-muted"><?= hr_esc($e['shift_name'] ?: 'بێ شەفت') ?></small></td>
                <td class="num"><?= hr_esc($e['phone'] ?: '') ?></td>
                <td class="num"><?= hr_esc($e['hire_date'] ?: '') ?></td>
                <?php if ($canPay): ?>
                <td class="hr-pay">
                    <?php if ($pay['rate'] > 0): ?>
                        <?= hr_money($pay['rate']) ?> <small>بۆ هەر کاتژمێرێک</small>
                    <?php elseif ($pay['fixed']): ?>
                        <?= hr_money($pay['basic']) ?> <small>مووچەی جێگیر</small>
                    <?php else: ?>
                        <span class="hr-muted">—</span>
                    <?php endif; ?>
                    <?php if ($pay['pct'] > 0): ?><small>+ <?= hr_esc(rtrim(rtrim(number_format($pay['pct'], 2), '0'), '.')) ?>%</small><?php endif; ?>
                </td>
                <?php endif; ?>
                <?php if ($canDocs): ?>
                <td>
                    <a class="hr-docs-n <?= $dc['n'] ? '' : 'is-none' ?>" href="<?= hr_esc($view) ?>#docs" title="بەڵگەنامەکان">
                        <i class="fas fa-folder<?= $dc['n'] ? '-open' : '' ?>"></i> <?= $dc['n'] ?: '—' ?>
                        <?php if ($dc['expired']): ?><i class="fas fa-circle-exclamation warn" title="<?= $dc['expired'] ?> بەسەرچووە"></i><?php elseif ($dc['soon']): ?><i class="fas fa-clock soon" title="<?= $dc['soon'] ?> بەم زووانە بەسەردەچێت"></i><?php endif; ?>
                    </a>
                </td>
                <?php endif; ?>
                <td>
                    <div class="hr-row-actions">
                        <a class="btn btn-sm btn-primary" href="<?= hr_esc($view) ?>" title="پیشاندانی کارمەند"><i class="fas fa-eye"></i> پیشاندان</a>
                        <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-secondary" href="<?= APP_URL ?>/hr/employee-edit.php?id=<?= $id ?>" title="دەستکاری"><i class="fas fa-pen"></i></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$customJS = <<<JS
(function () {
    const box = document.getElementById('hrEmpSearch');
    if (!box) return;
    box.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('#hrEmpTable tbody tr[data-search]').forEach(tr => {
            tr.style.display = !q || tr.dataset.search.includes(q) ? '' : 'none';
        });
    });
})();
JS;
require_once __DIR__ . '/../includes/footer.php';
