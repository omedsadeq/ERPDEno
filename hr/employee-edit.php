<?php
/**
 * Add or edit an employee — its own page, in tabs:
 *   ناسنامە و کار · زانیاری کەسی · مووچە (hr.payroll.view) · بەڵگەنامەکان
 *
 * The first three are one form with one save button, so nothing typed on
 * another tab is lost. Documents are uploaded and deleted on their own
 * (hr/employee-doc.php) and only once the employee exists.
 *
 *   employee-edit.php         new employee
 *   employee-edit.php?id=N    edit employee N
 *
 * The PIN is the person's number on the fingerprint device (device_pin). For
 * a new employee it is also their id when it is a free whole number. It can be
 * changed later — someone re-enrolled on the device under another number —
 * without renumbering the employee: past punches stay theirs, new ones follow
 * the new PIN. hr/device-users.php does the same from the device's side.
 *
 * Pay fields are shown and saved only with hr.payroll.view; anyone else
 * editing a record leaves them as they were. Deleting is only for a record
 * added by mistake, with no punch, adjustment or payroll behind it; anyone
 * else is retired by setting them inactive.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_employee_ui.php';
hr_require('hr.employees.manage');
$hrNavPage = 'employees';

$db = Database::getInstance();
$canPay = hr_can('hr.payroll.view');
$canDocsView = hr_can('hr.documents.view');
$canDocsManage = hr_can('hr.documents.manage');

const HR_EMPLOYEE_FIELDS = [
    'full_name', 'employee_type', 'position', 'department_id', 'shift_id', 'user_id', 'phone',
    'national_id', 'gender', 'birth_date', 'address', 'emergency_contact', 'emergency_phone',
    'hire_date', 'end_date', 'status', 'notes',
];
const HR_PAY_FIELDS = ['hourly_rate', 'basic_salary', 'salary_percentage'];

/** Validate the posted form into a row. Throws InvalidArgumentException with a Kurdish message. */
function hr_employee_from_post(array $in, bool $withPay, int $editId): array
{
    $db = Database::getInstance();
    $text = fn($k, $max) => ($v = trim((string)($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    $row = [
        'full_name'         => $text('full_name', 150),
        'employee_type'     => ($in['employee_type'] ?? '') === 'doctor' ? 'doctor' : 'staff',
        'position'          => $text('position', 100),
        'department_id'     => (int)($in['department_id'] ?? 0) ?: null,
        'shift_id'          => (int)($in['shift_id'] ?? 0) ?: null,
        'user_id'           => (int)($in['user_id'] ?? 0) ?: null,
        'phone'             => $text('phone', 30),
        'national_id'       => $text('national_id', 50),
        'gender'            => in_array($in['gender'] ?? '', ['male', 'female'], true) ? $in['gender'] : null,
        'birth_date'        => hr_date($in['birth_date'] ?? null),
        'address'           => $text('address', 255),
        'emergency_contact' => $text('emergency_contact', 150),
        'emergency_phone'   => $text('emergency_phone', 30),
        'hire_date'         => hr_date($in['hire_date'] ?? null),
        'end_date'          => hr_date($in['end_date'] ?? null),
        'status'            => ($in['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'notes'             => $text('notes', 2000),
    ];
    if ($row['full_name'] === null) {
        throw new InvalidArgumentException('ناوی کارمەند پێویستە');
    }
    if ($row['department_id'] && !$db->fetch('SELECT id FROM hr_departments WHERE id = ?', [$row['department_id']])) {
        $row['department_id'] = null;
    }
    if ($row['shift_id'] && !$db->fetch('SELECT id FROM hr_shifts WHERE id = ?', [$row['shift_id']])) {
        $row['shift_id'] = null;
    }
    if ($row['user_id']) {
        if (!$db->fetch('SELECT id FROM users WHERE id = ?', [$row['user_id']])) {
            throw new InvalidArgumentException('ئەکاونتی هەڵبژێردراو نەدۆزرایەوە');
        }
        $taken = $db->fetch('SELECT id, full_name FROM hr_employees WHERE user_id = ? AND id <> ?', [$row['user_id'], $editId]);
        if ($taken) {
            throw new InvalidArgumentException('ئەم ئەکاونتە پێشتر بە «' . $taken['full_name'] . '» ـەوە بەستراوە');
        }
    }
    if ($withPay) {
        foreach (['hourly_rate', 'basic_salary'] as $k) {
            $v = str_replace(',', '', trim((string)($in[$k] ?? '')));
            if ($v !== '' && (!is_numeric($v) || (float)$v < 0)) {
                throw new InvalidArgumentException('بڕی پارە دەبێت ژمارەیەکی دروست بێت');
            }
            $row[$k] = $v === '' ? null : round((float)$v, 2);
        }
        $pct = trim((string)($in['salary_percentage'] ?? ''));
        if ($pct !== '' && (!is_numeric($pct) || (float)$pct < 0 || (float)$pct > 100)) {
            throw new InvalidArgumentException('ڕێژە دەبێت لە نێوان 0 و 100 بێت');
        }
        $row['salary_percentage'] = $pct === '' ? 0 : round((float)$pct, 2);
    }
    return $row;
}

$editId = (int)($_GET['id'] ?? $_POST['edit_id'] ?? 0);
$emp = $editId > 0 ? $db->fetch('SELECT * FROM hr_employees WHERE id = ?', [$editId]) : null;
if ($editId > 0 && !$emp) {
    hr_flash('error', 'کارمەند نەدۆزرایەوە');
    hr_redirect(APP_URL . '/hr/employees.php');
}

$error = '';
$activeTab = 'main';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = (string)($_POST['action'] ?? '');
    $activeTab = preg_replace('/[^a-z]/', '', (string)($_POST['tab'] ?? 'main')) ?: 'main';
    try {
        if ($action === 'save') {
            $row = hr_employee_from_post($_POST, $canPay, $editId);
            if ($emp) {
                $pin = hr_clean_pin($_POST['pin'] ?? '');
                if ($pin !== hr_pin($emp)) {
                    $taken = $db->fetch('SELECT full_name FROM hr_employees WHERE device_pin = ? AND id <> ?', [$pin, $editId]);
                    if ($taken) {
                        throw new InvalidArgumentException('PIN ی ' . $pin . ' هی «' . $taken['full_name'] . '» ـە. بۆ گواستنەوەی، لە «بەکارهێنەرانی ئامێر» بیبەستەوە.');
                    }
                }
                $db->update('hr_employees', $row, 'id = ?', [$editId]);
                AuditLog::logChange('hr_employee_update', 'hr_employees', $editId, $emp, $row);
                hr_employee_link_pin($editId, $pin);
                hr_flash('success', 'زانیارییەکانی «' . $row['full_name'] . '» نوێکرانەوە');
                hr_redirect(APP_URL . '/hr/employee.php?id=' . $editId);
            }
            $newId = hr_employee_create($row, (string)($_POST['pin'] ?? ''), $hrUserId);
            hr_flash('success', '«' . $row['full_name'] . '» زیادکرا' . ($canDocsManage ? '. ئێستا دەتوانیت بەڵگەنامەکانی بار بکەیت.' : ''));
            hr_redirect(APP_URL . '/hr/employee.php?id=' . $newId . ($canDocsManage ? '#docs' : ''));
        } elseif ($action === 'delete' && $emp) {
            // Anything recorded against the person keeps the record.
            $used = 0;
            foreach (['hr_punches', 'hr_adjustments', 'hr_payroll', 'hr_salary_receipts'] as $table) {
                if ($db->tableExists($table)) {
                    $used += (int)$db->fetch("SELECT COUNT(*) AS n FROM {$table} WHERE employee_id = ?", [$editId])['n'];
                }
            }
            if ($used > 0) {
                throw new InvalidArgumentException('«' . $emp['full_name'] . '» پەنجەمۆر، کاتژمێر یان مووچەی تۆمارکراوی هەیە، بۆیە ناسڕدرێتەوە. لە جیاتی ئەوە ناچالاکی بکە.');
            }
            // The document rows go with the employee (FK cascade); their files go here.
            (new HrDocument())->deleteFilesOf($editId);
            $db->delete('hr_employees', 'id = ?', [$editId]);
            AuditLog::log('hr_employee_delete', 'hr_employees', $editId, $emp, null);
            hr_flash('success', '«' . $emp['full_name'] . '» سڕایەوە');
            hr_redirect(APP_URL . '/hr/employees.php');
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

// What the form shows: the record, or what was just typed after an error.
$form = [];
foreach (array_merge(HR_EMPLOYEE_FIELDS, HR_PAY_FIELDS) as $k) {
    $form[$k] = $emp[$k] ?? null;
}
$form['pin'] = $emp ? hr_pin($emp) : '';
if (!$emp) {
    $form['employee_type'] = 'staff';
    $form['status'] = 'active';
}
if ($error !== '') {
    foreach (array_merge(HR_EMPLOYEE_FIELDS, $canPay ? HR_PAY_FIELDS : [], ['pin']) as $k) {
        $form[$k] = isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : $form[$k];
    }
}
foreach (['hourly_rate', 'basic_salary', 'salary_percentage'] as $k) {
    if ($form[$k] !== null && $form[$k] !== '' && is_numeric($form[$k])) {
        $form[$k] = rtrim(rtrim(number_format((float)$form[$k], 2, '.', ''), '0'), '.');
    }
}
$v = fn($k) => hr_esc($form[$k] ?? '');
$sel = fn($k, $val) => (string)($form[$k] ?? '') === (string)$val ? 'selected' : '';

$shifts = $db->fetchAll('SELECT id, name FROM hr_shifts ORDER BY is_active DESC, name');
$departments = $db->fetchAll('SELECT id, name FROM hr_departments WHERE is_active = 1 ORDER BY name');
$users = $db->fetchAll(
    "SELECT u.id, u.username, u.full_name, u.role, u.is_active, e.id AS linked_to
     FROM users u LEFT JOIN hr_employees e ON e.user_id = u.id
     ORDER BY u.is_active DESC, u.full_name"
);
$roleLabels = ['admin' => 'بەڕێوەبەر', 'doctor' => 'دکتۆر', 'receptionist' => 'کارمەند', 'xray_department' => 'ئێکسڕەی', 'accountant' => 'ژمێریار'];

$docModel = new HrDocument();
$docs = ($emp && $canDocsView) ? $docModel->forEmployee($editId) : [];
$photoId = ($emp && $canDocsView) ? ($docModel->photoIds()[$editId] ?? null) : null;
$expiredDocs = count(array_filter($docs, fn($d) => HrDocument::expiryState($d) === 'expired'));

$pageTitle = $emp ? 'دەستکاری — ' . $emp['full_name'] : 'کارمەندی نوێ';
$pageActions = ($emp ? '<a class="btn btn-secondary" href="' . APP_URL . '/hr/employee.php?id=' . $editId . '"><i class="fas fa-eye"></i> پیشاندان</a> ' : '')
    . '<a class="btn btn-subtle" href="' . APP_URL . '/hr/employees.php"><i class="fas fa-arrow-right"></i> لیستی کارمەندان</a>';
$customCSS = $hrBaseCss . $hrEmpCss;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<?php if ($error !== ''): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle alert-icon"></i> <?= hr_esc($error) ?></div>
<?php endif; ?>

<div class="hr-ehead">
    <?= $emp ? hr_emp_avatar($emp, $photoId, 'lg') : '<span class="hr-avatar is-initials lg"><i class="fas fa-user-plus"></i></span>' ?>
    <div class="hr-ehead-main">
        <h2><?= $emp ? hr_esc($emp['full_name']) : 'کارمەندی نوێ' ?>
            <?php if ($emp): ?>
            <span class="hr-badge <?= $emp['employee_type'] ?>"><?= $emp['employee_type'] === 'doctor' ? 'دکتۆر' : 'کارمەند' ?></span>
            <?php if ($emp['status'] === 'inactive'): ?><span class="hr-badge off">ناچالاک</span><?php endif; ?>
            <?php endif; ?>
        </h2>
        <div class="pos"><?= $emp ? 'دەستکاریکردنی زانیارییەکان' : 'زانیارییەکان پڕ بکەرەوە؛ دوای پاشەکەوتکردن دەتوانیت بەڵگەنامەکانی بار بکەیت' ?></div>
        <?php if ($emp): ?>
        <div class="facts"><span><i class="fas fa-fingerprint"></i>PIN <b><bdi><?= hr_esc(hr_pin($emp)) ?></bdi></b></span></div>
        <?php endif; ?>
    </div>
</div>

<div class="hr-tabs" role="tablist">
    <button type="button" class="hr-tab" data-tab="main"><i class="fas fa-id-badge"></i> ناسنامە و کار</button>
    <button type="button" class="hr-tab" data-tab="personal"><i class="fas fa-user"></i> زانیاری کەسی</button>
    <?php if ($canPay): ?>
    <button type="button" class="hr-tab" data-tab="pay"><i class="fas fa-money-bill-wave"></i> مووچە</button>
    <?php endif; ?>
    <?php if ($canDocsView): ?>
    <button type="button" class="hr-tab" data-tab="docs" <?= $emp ? '' : 'disabled title="دوای پاشەکەوتکردنی کارمەندەکە"' ?>>
        <i class="fas fa-folder-open"></i> بەڵگەنامەکان
        <?php if ($docs): ?><span class="cnt <?= $expiredDocs ? 'warn' : '' ?>"><?= count($docs) ?></span><?php endif; ?>
    </button>
    <?php endif; ?>
</div>

<form method="POST" id="hrEmpForm" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="edit_id" value="<?= (int)$editId ?>">
    <input type="hidden" name="tab" id="hrActiveTab" value="<?= hr_esc($activeTab) ?>">

    <div class="hr-tabpanel" id="tab-main">
        <div class="hr-sheet">
            <div class="hr-sheet-h"><i class="fas fa-id-badge"></i> ناسنامە</div>
            <div class="hr-formgrid">
                <div class="form-group">
                    <label class="form-label required">PIN ی ئامێری پەنجەمۆر</label>
                    <input type="text" name="pin" class="form-control" dir="ltr" required maxlength="20" inputmode="numeric" value="<?= $v('pin') ?>" <?= $emp ? '' : 'autofocus' ?>>
                    <div class="form-hint"><?= $emp
                        ? 'ئەگەر لەسەر ئامێر بە ژمارەیەکی تر تۆمارکراوە، بیگۆڕە. پەنجەمۆرە کۆنەکانی لای خۆی دەمێننەوە.'
                        : 'ئەو ژمارەیەی کارمەندەکە لەسەر ئامێرەکە پێی تۆمارکراوە' ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label required">ناوی تەواو</label>
                    <input type="text" name="full_name" class="form-control" maxlength="150" required value="<?= $v('full_name') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">جۆر</label>
                    <select name="employee_type" class="form-control">
                        <option value="staff" <?= $sel('employee_type', 'staff') ?>>کارمەند</option>
                        <option value="doctor" <?= $sel('employee_type', 'doctor') ?>>دکتۆر</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">دۆخ</label>
                    <select name="status" class="form-control">
                        <option value="active" <?= $sel('status', 'active') ?>>چالاک</option>
                        <option value="inactive" <?= $sel('status', 'inactive') ?>>ناچالاک</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="hr-sheet">
            <div class="hr-sheet-h"><i class="fas fa-briefcase"></i> کار</div>
            <div class="hr-formgrid">
                <div class="form-group">
                    <label class="form-label">پۆست / کار</label>
                    <input type="text" name="position" class="form-control" maxlength="100" placeholder="نموونە: یاریدەدەری دکتۆر" value="<?= $v('position') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">بەش</label>
                    <select name="department_id" class="form-control">
                        <option value="0">—</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $sel('department_id', $d['id']) ?>><?= hr_esc($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">شەفت</label>
                    <select name="shift_id" class="form-control">
                        <option value="0">بێ شەفت (هەموو ڕۆژێک جگە لە هەینی)</option>
                        <?php foreach ($shifts as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $sel('shift_id', $s['id']) ?>><?= hr_esc($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">ئەکاونت لە سیستەم</label>
                    <select name="user_id" class="form-control">
                        <option value="0">— بێ ئەکاونت —</option>
                        <?php foreach ($users as $u):
                            // A login linked to someone else cannot be picked twice.
                            $linkedElsewhere = (int)$u['linked_to'] > 0 && (int)$u['linked_to'] !== $editId; ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $sel('user_id', $u['id']) ?> <?= $linkedElsewhere ? 'disabled' : '' ?>>
                            <?= hr_esc($u['full_name'] . ' (' . $u['username'] . ' — ' . ($roleLabels[$u['role']] ?? $u['role']) . ')' . ((int)$u['is_active'] ? '' : ' — ناچالاک')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">ڕێکەوتی دەستپێکی کار</label>
                    <input type="date" name="hire_date" class="form-control" value="<?= $v('hire_date') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">ڕێکەوتی کۆتایی کار</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $v('end_date') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="hr-tabpanel" id="tab-personal">
        <div class="hr-sheet">
            <div class="hr-sheet-h"><i class="fas fa-user"></i> زانیاری کەسی</div>
            <div class="hr-formgrid">
                <div class="form-group">
                    <label class="form-label">مۆبایل</label>
                    <input type="text" name="phone" class="form-control" dir="ltr" maxlength="30" value="<?= $v('phone') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">ژمارەی ناسنامە</label>
                    <input type="text" name="national_id" class="form-control" dir="ltr" maxlength="50" value="<?= $v('national_id') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">ڕەگەز</label>
                    <select name="gender" class="form-control">
                        <option value="">—</option>
                        <option value="male" <?= $sel('gender', 'male') ?>>نێر</option>
                        <option value="female" <?= $sel('gender', 'female') ?>>مێ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">ڕێکەوتی لەدایکبوون</label>
                    <input type="date" name="birth_date" class="form-control" value="<?= $v('birth_date') ?>">
                </div>
                <div class="form-group hr-span">
                    <label class="form-label">ناونیشان</label>
                    <input type="text" name="address" class="form-control" maxlength="255" value="<?= $v('address') ?>">
                </div>
            </div>
        </div>
        <div class="hr-sheet">
            <div class="hr-sheet-h"><i class="fas fa-phone-volume"></i> لە کاتی پێویستدا</div>
            <div class="hr-formgrid">
                <div class="form-group">
                    <label class="form-label">کەسی پەیوەندی</label>
                    <input type="text" name="emergency_contact" class="form-control" maxlength="150" value="<?= $v('emergency_contact') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">مۆبایلی کەسی پەیوەندی</label>
                    <input type="text" name="emergency_phone" class="form-control" dir="ltr" maxlength="30" value="<?= $v('emergency_phone') ?>">
                </div>
            </div>
        </div>
        <div class="hr-sheet">
            <div class="hr-sheet-h"><i class="fas fa-note-sticky"></i> تێبینی</div>
            <textarea name="notes" class="form-control" rows="4"><?= $v('notes') ?></textarea>
        </div>
    </div>

    <?php if ($canPay): ?>
    <div class="hr-tabpanel" id="tab-pay">
        <div class="hr-sheet">
            <div class="hr-sheet-h"><i class="fas fa-money-bill-wave"></i> مووچە</div>
            <div class="hr-formgrid">
                <div class="form-group">
                    <label class="form-label">کرێی کاتژمێر (IQD)</label>
                    <input type="text" inputmode="decimal" name="hourly_rate" class="form-control" dir="ltr" value="<?= $v('hourly_rate') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">مووچەی جێگیری مانگانە (IQD)</label>
                    <input type="text" inputmode="decimal" name="basic_salary" class="form-control" dir="ltr" value="<?= $v('basic_salary') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">ڕێژەی زیادە (%)</label>
                    <input type="text" inputmode="decimal" name="salary_percentage" class="form-control" dir="ltr" value="<?= $v('salary_percentage') ?>">
                </div>
            </div>
            <div class="form-hint" style="margin-top:10px">ئەگەر کرێی کاتژمێر هەبێت: کاتژمێر × کرێ. ئەگەر نەبێت: مووچەی جێگیر. دواتر ڕێژەکە زیاد دەکرێت و بۆ سەرەوە دەکرێتە نزیکترین <?= number_format(HrAttendance::ROUND_TO) ?> دینار.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="hr-savebar" data-for-tabs="main personal pay">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
        <a class="btn btn-secondary" href="<?= APP_URL ?>/hr/<?= $emp ? 'employee.php?id=' . $editId : 'employees.php' ?>">پاشگەزبوونەوە</a>
        <span class="spacer"></span>
        <?php if ($emp): ?>
        <button type="submit" form="hrEmpDeleteForm" class="btn btn-danger"
                onclick="return confirm('ئەم کارمەندە بسڕدرێتەوە؟ تەنها کارمەندێک دەسڕدرێتەوە کە هیچ پەنجەمۆر، کاتژمێر یان مووچەیەکی نەبێت. بەڵگەنامەکانیشی دەسڕدرێنەوە.')">
            <i class="fas fa-trash"></i> سڕینەوە
        </button>
        <?php endif; ?>
    </div>
</form>

<?php if ($emp): ?>
<form method="POST" id="hrEmpDeleteForm" style="display:none">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="edit_id" value="<?= (int)$editId ?>">
</form>
<?php endif; ?>

<?php if ($emp && $canDocsView): ?>
<div class="hr-tabpanel" id="tab-docs">
    <?php hr_emp_docs_section($emp, $docs, $canDocsManage, 'edit'); ?>
</div>
<?php endif; ?>

<?php
$customJS = $hrEmpJs;
require_once __DIR__ . '/../includes/footer.php';
