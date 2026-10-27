<?php
/**
 * One employee's profile: the photo and the essentials at the top, then
 *   زانیاری        everything on record (pay only with hr.payroll.view)
 *   بەڵگەنامەکان   photo, ID cards, licences, contract … (hr.documents.view;
 *                  uploading and deleting need hr.documents.manage)
 *
 * The list's «پیشاندان» button opens it; «دەستکاری» goes to
 * hr/employee-edit.php.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_employee_ui.php';
hr_require('hr.employees.view');
$hrNavPage = 'employees';

$db = Database::getInstance();
$canManage = hr_can('hr.employees.manage');
$canPay = hr_can('hr.payroll.view');
$canReports = hr_can('hr.reports.view');
$canDocsView = hr_can('hr.documents.view');
$canDocsManage = hr_can('hr.documents.manage');

$id = (int)($_GET['id'] ?? 0);
$found = $id > 0 ? (new HrAttendance())->employees(['status' => '', 'ids' => [$id]]) : [];
$emp = $found[0] ?? null;
if (!$emp) {
    hr_flash('error', 'کارمەند نەدۆزرایەوە');
    hr_redirect(APP_URL . '/hr/employees.php');
}

$docModel = new HrDocument();
$docs = $canDocsView ? $docModel->forEmployee($id) : [];
$photoId = $canDocsView ? ($docModel->photoIds()[$id] ?? null) : null;
$expiredDocs = count(array_filter($docs, fn($d) => HrDocument::expiryState($d) === 'expired'));
$soonDocs = count(array_filter($docs, fn($d) => HrDocument::expiryState($d) === 'soon'));
$pay = HrAttendance::pay($emp, 0);
$isDoctor = $emp['employee_type'] === 'doctor';

/** One line of a definition list; empty values show a faint dash. */
$row = function (string $label, $value, bool $ltr = false): string {
    $value = trim((string)$value);
    return '<div><dt>' . hr_esc($label) . '</dt><dd class="' . ($value === '' ? 'empty' : ($ltr ? 'ltr' : '')) . '">'
        . ($value === '' ? '—' : hr_esc($value)) . '</dd></div>';
};
$genders = ['male' => 'نێر', 'female' => 'مێ'];
$age = $emp['birth_date'] ? (new DateTime($emp['birth_date']))->diff(new DateTime('today'))->y : null;
$tenure = hr_emp_tenure($emp['hire_date'], $emp['end_date']);

$pageTitle = $emp['full_name'];
$pageActions = '<a class="btn btn-subtle" href="' . APP_URL . '/hr/employees.php"><i class="fas fa-arrow-right"></i> لیستی کارمەندان</a>';
$customCSS = $hrBaseCss . $hrEmpCss;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<div class="hr-ehead">
    <?php if ($photoId): ?>
        <a href="<?= hr_esc(hr_doc_url($photoId)) ?>" target="_blank" rel="noopener"><?= hr_emp_avatar($emp, $photoId, 'lg') ?></a>
    <?php else: ?>
        <?= hr_emp_avatar($emp, null, 'lg') ?>
    <?php endif; ?>
    <div class="hr-ehead-main">
        <h2><?= hr_esc($emp['full_name']) ?>
            <span class="hr-badge <?= $isDoctor ? 'doctor' : 'staff' ?>"><?= $isDoctor ? 'دکتۆر' : 'کارمەند' ?></span>
            <?php if ($emp['status'] === 'inactive'): ?><span class="hr-badge off">ناچالاک</span><?php endif; ?>
        </h2>
        <div class="pos"><?= hr_esc($emp['position'] ?: ($isDoctor ? 'دکتۆر' : 'کارمەند')) ?><?= $emp['department_name'] ? ' · ' . hr_esc($emp['department_name']) : '' ?></div>
        <div class="facts">
            <span><i class="fas fa-fingerprint"></i>PIN <b><bdi><?= hr_esc(hr_pin($emp)) ?></bdi></b></span>
            <?php if ($emp['phone']): ?><span><i class="fas fa-phone"></i><b><bdi><?= hr_esc($emp['phone']) ?></bdi></b></span><?php endif; ?>
            <span><i class="fas fa-clock"></i><?= hr_esc($emp['shift_name'] ?: 'بێ شەفت') ?></span>
            <?php if ($emp['hire_date']): ?><span><i class="fas fa-calendar-check"></i>لە <bdi><?= hr_esc($emp['hire_date']) ?></bdi><?= $tenure ? ' · ' . hr_esc($tenure) : '' ?></span><?php endif; ?>
            <?php if ($emp['user_id']): ?><span><i class="fas fa-link"></i><?= hr_esc($emp['username']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="hr-ehead-actions">
        <?php if ($canManage): ?>
        <a class="btn btn-primary" href="<?= APP_URL ?>/hr/employee-edit.php?id=<?= $id ?>"><i class="fas fa-pen"></i> دەستکاری</a>
        <?php endif; ?>
        <?php if ($canReports): ?>
        <a class="btn btn-secondary" href="<?= APP_URL ?>/hr/reports.php?r=employee&amp;employee_id=<?= $id ?>"><i class="fas fa-user-clock"></i> ڕاپۆرتی ئامادەبوون</a>
        <?php endif; ?>
    </div>
</div>

<div class="hr-tabs" role="tablist">
    <button type="button" class="hr-tab" data-tab="info"><i class="fas fa-circle-info"></i> زانیاری</button>
    <?php if ($canDocsView): ?>
    <button type="button" class="hr-tab" data-tab="docs">
        <i class="fas fa-folder-open"></i> بەڵگەنامەکان
        <span class="cnt <?= $expiredDocs ? 'warn' : '' ?>" title="<?= $expiredDocs ? $expiredDocs . ' بەسەرچووە' : '' ?>"><?= count($docs) ?></span>
    </button>
    <?php endif; ?>
</div>

<div class="hr-tabpanel" id="tab-info">
    <?php if ($expiredDocs || $soonDocs): ?>
    <div class="alert alert-warning" style="margin-bottom:14px">
        <i class="fas fa-triangle-exclamation alert-icon"></i>
        <?= $expiredDocs ? $expiredDocs . ' بەڵگەنامە بەسەرچووە' : '' ?><?= $expiredDocs && $soonDocs ? ' و ' : '' ?><?= $soonDocs ? $soonDocs . ' بەڵگەنامە لە ' . HrDocument::EXPIRY_WARN_DAYS . ' ڕۆژی داهاتوودا بەسەردەچێت' : '' ?>
        — <a href="#docs" onclick="hrShowTab('docs', true); return false;">بینین</a>
    </div>
    <?php endif; ?>

    <div class="hr-sheet">
        <div class="hr-sheet-h"><i class="fas fa-briefcase"></i> کار</div>
        <dl class="hr-dl">
            <?= $row('پۆست / کار', $emp['position']) ?>
            <?= $row('بەش', $emp['department_name']) ?>
            <?= $row('شەفت', $emp['shift_name'] ? $emp['shift_name'] . ' (' . substr((string)$emp['shift_start'], 0, 5) . ' – ' . substr((string)$emp['shift_end'], 0, 5) . ')' : 'بێ شەفت') ?>
            <?= $row('دۆخ', $emp['status'] === 'inactive' ? 'ناچالاک' : 'چالاک') ?>
            <?= $row('ڕێکەوتی دەستپێکی کار', $emp['hire_date'], true) ?>
            <?= $row('ڕێکەوتی کۆتایی کار', $emp['end_date'], true) ?>
            <?= $row('ماوەی کارکردن', $tenure) ?>
            <?= $row('ئەکاونت لە سیستەم', $emp['user_id'] ? $emp['user_full_name'] . ' (' . $emp['username'] . ')' : '') ?>
        </dl>
    </div>

    <div class="hr-sheet">
        <div class="hr-sheet-h"><i class="fas fa-user"></i> زانیاری کەسی</div>
        <dl class="hr-dl">
            <?= $row('مۆبایل', $emp['phone'], true) ?>
            <?= $row('ژمارەی ناسنامە', $emp['national_id'], true) ?>
            <?= $row('ڕەگەز', $genders[$emp['gender']] ?? '') ?>
            <?= $row('ڕێکەوتی لەدایکبوون', $emp['birth_date'], true) ?>
            <?= $row('تەمەن', $age !== null ? $age . ' ساڵ' : '') ?>
            <?= $row('ناونیشان', $emp['address']) ?>
            <?= $row('کەسی پەیوەندی لە کاتی پێویستدا', $emp['emergency_contact']) ?>
            <?= $row('مۆبایلی کەسی پەیوەندی', $emp['emergency_phone'], true) ?>
        </dl>
    </div>

    <?php if ($canPay): ?>
    <div class="hr-sheet">
        <div class="hr-sheet-h"><i class="fas fa-money-bill-wave"></i> مووچە</div>
        <dl class="hr-dl">
            <?= $row('شێوەی مووچە', $pay['rate'] > 0 ? 'بەپێی کاتژمێر' : ($pay['fixed'] ? 'مووچەی جێگیری مانگانە' : 'دیاری نەکراوە')) ?>
            <?= $row('کرێی کاتژمێر', $emp['hourly_rate'] !== null ? hr_money($emp['hourly_rate']) . ' IQD' : '', true) ?>
            <?= $row('مووچەی جێگیری مانگانە', $emp['basic_salary'] !== null ? hr_money($emp['basic_salary']) . ' IQD' : '', true) ?>
            <?= $row('ڕێژەی زیادە', (float)$emp['salary_percentage'] > 0 ? rtrim(rtrim(number_format((float)$emp['salary_percentage'], 2), '0'), '.') . '%' : '', true) ?>
        </dl>
    </div>
    <?php endif; ?>

    <?php if ($emp['notes']): ?>
    <div class="hr-sheet">
        <div class="hr-sheet-h"><i class="fas fa-note-sticky"></i> تێبینی</div>
        <div class="hr-note"><?= hr_esc($emp['notes']) ?></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($canDocsView): ?>
<div class="hr-tabpanel" id="tab-docs">
    <?php hr_emp_docs_section($emp, $docs, $canDocsManage, 'view'); ?>
</div>
<?php endif; ?>

<?php
$customJS = $hrEmpJs;
require_once __DIR__ . '/../includes/footer.php';
