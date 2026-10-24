<?php
/**
 * کارمەندان — everyone who punches the fingerprint device, doctors and staff.
 *
 * The PIN is the person's number on the fingerprint device (device_pin). For
 * a new employee it is also their id when it is a free whole number. It can be
 * changed later — someone re-enrolled on the device under another number —
 * without renumbering the employee: past punches stay theirs, new ones follow
 * the new PIN. hr/device-users.php does the same from the device's side.
 *
 * Pay fields (hourly rate, fixed salary, percentage) are shown and saved only
 * for users with hr.payroll.view; anyone else editing a record leaves them as
 * they were.
 *
 * An employee is retired by setting them inactive. Deleting is only for a
 * record added by mistake, with no punch or adjustment behind it.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.employees.view');

$db = Database::getInstance();
$canManage = hr_can('hr.employees.manage');
$canPay = hr_can('hr.payroll.view');

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    hr_require('hr.employees.manage');
    $action = (string)($_POST['action'] ?? '');
    $editId = (int)($_POST['edit_id'] ?? 0);
    try {
        if ($action === 'save') {
            $row = hr_employee_from_post($_POST, $canPay, $editId);
            if ($editId > 0) {
                $old = $db->fetch('SELECT * FROM hr_employees WHERE id = ?', [$editId]);
                if (!$old) {
                    throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
                }
                $pin = hr_clean_pin($_POST['pin'] ?? '');
                if ($pin !== hr_pin($old)) {
                    $taken = $db->fetch('SELECT full_name FROM hr_employees WHERE device_pin = ? AND id <> ?', [$pin, $editId]);
                    if ($taken) {
                        throw new InvalidArgumentException('PIN ی ' . $pin . ' هی «' . $taken['full_name'] . '» ـە. بۆ گواستنەوەی، لە «بەکارهێنەرانی ئامێر» بیبەستەوە.');
                    }
                }
                $db->update('hr_employees', $row, 'id = ?', [$editId]);
                AuditLog::logChange('hr_employee_update', 'hr_employees', $editId, $old, $row);
                hr_employee_link_pin($editId, $pin);
                hr_flash('success', 'زانیارییەکانی «' . $row['full_name'] . '» نوێکرانەوە');
            } else {
                hr_employee_create($row, (string)($_POST['pin'] ?? ''), $hrUserId);
                hr_flash('success', '«' . $row['full_name'] . '» زیادکرا');
            }
        } elseif ($action === 'delete' && $editId > 0) {
            $old = $db->fetch('SELECT * FROM hr_employees WHERE id = ?', [$editId]);
            $used = (int)$db->fetch('SELECT COUNT(*) AS n FROM hr_punches WHERE employee_id = ?', [$editId])['n']
                  + (int)$db->fetch('SELECT COUNT(*) AS n FROM hr_adjustments WHERE employee_id = ?', [$editId])['n'];
            if (!$old) {
                throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
            }
            if ($used > 0) {
                throw new InvalidArgumentException('«' . $old['full_name'] . '» پەنجەمۆر یان کاتژمێری تۆمارکراوی هەیە، بۆیە ناسڕدرێتەوە. لە جیاتی ئەوە ناچالاکی بکە.');
            }
            $db->delete('hr_employees', 'id = ?', [$editId]);
            AuditLog::log('hr_employee_delete', 'hr_employees', $editId, $old, null);
            hr_flash('success', '«' . $old['full_name'] . '» سڕایەوە');
        }
    } catch (InvalidArgumentException $ex) {
        hr_flash('error', $ex->getMessage());
    }
    hr_redirect();
}

$fType = in_array($_GET['type'] ?? '', ['staff', 'doctor'], true) ? $_GET['type'] : '';
$fStatus = in_array($_GET['status'] ?? 'active', ['active', 'inactive', ''], true) ? ($_GET['status'] ?? 'active') : 'active';
$hr = new HrAttendance();
$employees = $hr->employees(['status' => $fStatus, 'type' => $fType]);
$shifts = $db->fetchAll('SELECT id, name FROM hr_shifts ORDER BY is_active DESC, name');
$departments = $db->fetchAll('SELECT id, name FROM hr_departments WHERE is_active = 1 ORDER BY name');
$users = $db->fetchAll(
    "SELECT u.id, u.username, u.full_name, u.role, u.is_active, e.id AS linked_to
     FROM users u LEFT JOIN hr_employees e ON e.user_id = u.id
     ORDER BY u.is_active DESC, u.full_name"
);
$roleLabels = ['admin' => 'بەڕێوەبەر', 'doctor' => 'دکتۆر', 'receptionist' => 'کارمەند', 'xray_department' => 'ئێکسڕەی', 'accountant' => 'ژمێریار'];

$pageTitle = 'کارمەندان';
if ($canManage) {
    $pageActions = '<button class="btn btn-primary" onclick="hrEmpNew()"><i class="fas fa-user-plus"></i> کارمەندی نوێ</button>';
}
$customCSS = $hrBaseCss . <<<CSS
.hr-emp-name b{font-size:13.5px;color:#111827}
.hr-emp-name small{display:block;color:#9ca3af;font-size:11.5px}
.hr-pay small{display:block;color:#9ca3af;font-size:11px}
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
                <th>ئەکاونت لە سیستەم</th>
                <?php if ($canManage): ?><th></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (!$employees): ?>
            <tr><td colspan="9" class="hr-empty">هیچ کارمەندێک نییە</td></tr>
        <?php endif; ?>
        <?php foreach ($employees as $e):
            $pay = HrAttendance::pay($e, 0);
            $search = mb_strtolower(hr_pin($e) . ' ' . $e['full_name'] . ' ' . $e['phone'] . ' ' . $e['position'] . ' ' . $e['username']);
            $json = [];
            foreach (array_merge(['id'], HR_EMPLOYEE_FIELDS, $canPay ? HR_PAY_FIELDS : []) as $k) {
                $json[$k] = $e[$k];
            }
            $json['pin'] = hr_pin($e);
        ?>
            <tr class="<?= $e['status'] === 'inactive' ? 'is-inactive' : '' ?>" data-search="<?= hr_esc($search) ?>">
                <td class="num"><b><?= hr_esc(hr_pin($e)) ?></b></td>
                <td class="hr-emp-name"><b><?= hr_esc($e['full_name']) ?></b><?php if ($e['position']): ?><small><?= hr_esc($e['position']) ?></small><?php endif; ?></td>
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
                <td><?php if ($e['user_id']): ?><i class="fas fa-link hr-muted"></i> <?= hr_esc($e['username']) ?><?php else: ?><span class="hr-muted">—</span><?php endif; ?></td>
                <?php if ($canManage): ?>
                <td style="white-space:nowrap">
                    <button type="button" class="btn btn-sm btn-secondary" title="دەستکاری"
                            data-emp="<?= hr_esc(json_encode($json, JSON_UNESCAPED_UNICODE)) ?>" onclick="hrEmpEdit(this)"><i class="fas fa-pen"></i></button>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($canManage): ?>
<div class="dialog-overlay" id="hrEmpDialog">
    <div class="dialog dialog-lg">
        <div class="dialog-header">
            <h3 class="dialog-title" id="hrEmpTitle">کارمەندی نوێ</h3>
            <button type="button" class="dialog-close" onclick="closeDialog('hrEmpDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="hrEmpForm" autocomplete="off">
            <div class="dialog-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="edit_id" id="ef_edit_id" value="0">

                <div class="hr-formsec" style="margin-top:0">ناسنامە</div>
                <div class="hr-formgrid">
                    <div class="form-group">
                        <label class="form-label required">PIN ی ئامێری پەنجەمۆر</label>
                        <input type="text" name="pin" id="ef_pin" class="form-control" dir="ltr" required maxlength="20" inputmode="numeric">
                        <div class="form-hint" id="ef_pin_hint">ئەو ژمارەیەی کارمەندەکە لەسەر ئامێرەکە پێی تۆمارکراوە</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">ناوی تەواو</label>
                        <input type="text" name="full_name" id="ef_full_name" class="form-control" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">جۆر</label>
                        <select name="employee_type" id="ef_employee_type" class="form-control">
                            <option value="staff">کارمەند</option>
                            <option value="doctor">دکتۆر</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">پۆست / کار</label>
                        <input type="text" name="position" id="ef_position" class="form-control" maxlength="100" placeholder="نموونە: یاریدەدەری دکتۆر">
                    </div>
                    <div class="form-group">
                        <label class="form-label">بەش</label>
                        <select name="department_id" id="ef_department_id" class="form-control">
                            <option value="0">—</option>
                            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= hr_esc($d['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">شەفت</label>
                        <select name="shift_id" id="ef_shift_id" class="form-control">
                            <option value="0">بێ شەفت (هەموو ڕۆژێک جگە لە هەینی)</option>
                            <?php foreach ($shifts as $s): ?><option value="<?= (int)$s['id'] ?>"><?= hr_esc($s['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ئەکاونت لە سیستەم</label>
                        <select name="user_id" id="ef_user_id" class="form-control">
                            <option value="0">— بێ ئەکاونت —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" data-linked="<?= (int)$u['linked_to'] ?>">
                                <?= hr_esc($u['full_name'] . ' (' . $u['username'] . ' — ' . ($roleLabels[$u['role']] ?? $u['role']) . ')' . ((int)$u['is_active'] ? '' : ' — ناچالاک')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">دۆخ</label>
                        <select name="status" id="ef_status" class="form-control">
                            <option value="active">چالاک</option>
                            <option value="inactive">ناچالاک</option>
                        </select>
                    </div>
                </div>

                <?php if ($canPay): ?>
                <div class="hr-formsec">مووچە</div>
                <div class="hr-formgrid">
                    <div class="form-group">
                        <label class="form-label">کرێی کاتژمێر (IQD)</label>
                        <input type="text" inputmode="decimal" name="hourly_rate" id="ef_hourly_rate" class="form-control" dir="ltr">
                    </div>
                    <div class="form-group">
                        <label class="form-label">مووچەی جێگیری مانگانە (IQD)</label>
                        <input type="text" inputmode="decimal" name="basic_salary" id="ef_basic_salary" class="form-control" dir="ltr">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ڕێژەی زیادە (%)</label>
                        <input type="text" inputmode="decimal" name="salary_percentage" id="ef_salary_percentage" class="form-control" dir="ltr">
                    </div>
                </div>
                <div class="form-hint">ئەگەر کرێی کاتژمێر هەبێت: کاتژمێر × کرێ. ئەگەر نەبێت: مووچەی جێگیر. دواتر ڕێژەکە زیاد دەکرێت و بۆ سەرەوە دەکرێتە نزیکترین <?= number_format(HrAttendance::ROUND_TO) ?> دینار.</div>
                <?php endif; ?>

                <div class="hr-formsec">زانیاری کەسی</div>
                <div class="hr-formgrid">
                    <div class="form-group">
                        <label class="form-label">مۆبایل</label>
                        <input type="text" name="phone" id="ef_phone" class="form-control" dir="ltr" maxlength="30">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ژمارەی ناسنامە</label>
                        <input type="text" name="national_id" id="ef_national_id" class="form-control" dir="ltr" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ڕەگەز</label>
                        <select name="gender" id="ef_gender" class="form-control">
                            <option value="">—</option>
                            <option value="male">نێر</option>
                            <option value="female">مێ</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ڕێکەوتی لەدایکبوون</label>
                        <input type="date" name="birth_date" id="ef_birth_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ڕێکەوتی دەستپێکی کار</label>
                        <input type="date" name="hire_date" id="ef_hire_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ڕێکەوتی کۆتایی کار</label>
                        <input type="date" name="end_date" id="ef_end_date" class="form-control">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">ناونیشان</label>
                        <input type="text" name="address" id="ef_address" class="form-control" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label class="form-label">کەسی پەیوەندی لە کاتی پێویستدا</label>
                        <input type="text" name="emergency_contact" id="ef_emergency_contact" class="form-control" maxlength="150">
                    </div>
                    <div class="form-group">
                        <label class="form-label">مۆبایلی کەسی پەیوەندی</label>
                        <input type="text" name="emergency_phone" id="ef_emergency_phone" class="form-control" dir="ltr" maxlength="30">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">تێبینی</label>
                        <textarea name="notes" id="ef_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-danger" id="ef_delete" style="margin-inline-end:auto;display:none" onclick="hrEmpDelete()"><i class="fas fa-trash"></i> سڕینەوە</button>
                <button type="button" class="btn btn-secondary" onclick="closeDialog('hrEmpDialog')">پاشگەزبوونەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
            </div>
        </form>
        <form method="POST" id="hrEmpDeleteForm" style="display:none">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="edit_id" id="ef_delete_id" value="0">
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$fieldsJs = json_encode(array_merge(HR_EMPLOYEE_FIELDS, $canPay ? HR_PAY_FIELDS : []));
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

const HR_EMP_FIELDS = {$fieldsJs};

function hrEmpFill(data) {
    HR_EMP_FIELDS.forEach(k => {
        const el = document.getElementById('ef_' + k);
        if (!el) return;
        let v = data[k];
        if (v === null || v === undefined) v = (el.tagName === 'SELECT' && el.querySelector('option[value="0"]')) ? '0' : '';
        if (k === 'hourly_rate' || k === 'basic_salary' || k === 'salary_percentage') {
            v = v === '' ? '' : String(parseFloat(v));
        }
        el.value = v;
    });
    // A login linked to someone else cannot be picked twice.
    document.querySelectorAll('#ef_user_id option[data-linked]').forEach(o => {
        const linked = Number(o.dataset.linked);
        o.disabled = linked > 0 && linked !== Number(data.id || 0);
    });
}

function hrEmpNew() {
    document.getElementById('hrEmpForm').reset();
    hrEmpFill({employee_type: 'staff', status: 'active', salary_percentage: '0'});
    document.getElementById('ef_edit_id').value = '0';
    const pin = document.getElementById('ef_pin');
    pin.value = ''; pin.readOnly = false; pin.required = true;
    document.getElementById('ef_pin_hint').textContent = 'ئەو ژمارەیەی کارمەندەکە لەسەر ئامێرەکە پێی تۆمارکراوە';
    document.getElementById('ef_delete').style.display = 'none';
    document.getElementById('hrEmpTitle').textContent = 'کارمەندی نوێ';
    openDialog('hrEmpDialog');
    setTimeout(() => pin.focus(), 60);
}

function hrEmpEdit(btn) {
    const data = JSON.parse(btn.dataset.emp);
    document.getElementById('hrEmpForm').reset();
    hrEmpFill(data);
    document.getElementById('ef_edit_id').value = data.id;
    const pin = document.getElementById('ef_pin');
    pin.value = data.pin; pin.readOnly = false; pin.required = true;
    document.getElementById('ef_pin_hint').textContent = 'ئەگەر لەسەر ئامێر بە ژمارەیەکی تر تۆمارکراوە، بیگۆڕە. پەنجەمۆرە کۆنەکانی لای خۆی دەمێننەوە.';
    document.getElementById('ef_delete').style.display = '';
    document.getElementById('ef_delete_id').value = data.id;
    document.getElementById('hrEmpTitle').textContent = 'دەستکاری — ' + data.full_name;
    openDialog('hrEmpDialog');
}

function hrEmpDelete() {
    if (!confirm('ئەم کارمەندە بسڕدرێتەوە؟ تەنها کارمەندێک دەسڕدرێتەوە کە هیچ پەنجەمۆرێکی نەبێت.')) return;
    document.getElementById('hrEmpDeleteForm').submit();
}
JS;
require_once __DIR__ . '/../includes/footer.php';
