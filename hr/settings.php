<?php
/**
 * ڕێکخستنەکانی سەرچاوە مرۆییەکان — shifts, holidays and departments.
 *
 * A shift's days decide which days count as work days: a day outside them, or
 * an active holiday, shows as پشوو in the reports instead of غیاب. Neither
 * changes pay — pay follows the hours.
 *
 * Nothing here is deleted while something points at it: a shift or
 * department in use is switched off instead.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.settings');

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
    try {
        switch ($action) {
            case 'save_shift':
                $start = preg_match('/^\d{2}:\d{2}$/', $_POST['start_time'] ?? '') ? $_POST['start_time'] . ':00' : null;
                $end = preg_match('/^\d{2}:\d{2}$/', $_POST['end_time'] ?? '') ? $_POST['end_time'] . ':00' : null;
                $days = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['work_days'] ?? [])), fn($d) => $d >= 0 && $d <= 6)));
                if ($name === '' || !$start || !$end) {
                    throw new InvalidArgumentException('ناو و کاتی دەستپێک و کۆتایی پێویستن');
                }
                if (!$days) {
                    throw new InvalidArgumentException('لانیکەم ڕۆژێکی دەوام هەڵبژێرە');
                }
                $row = [
                    'name' => mb_substr($name, 0, 100), 'start_time' => $start, 'end_time' => $end,
                    'break_minutes' => max(0, min(600, (int)($_POST['break_minutes'] ?? 0))),
                    'grace_minutes' => max(0, min(240, (int)($_POST['grace_minutes'] ?? 15))),
                    'work_days' => implode(',', $days),
                    'is_active' => empty($_POST['is_active']) ? 0 : 1,
                ];
                if ($id > 0) {
                    $old = $db->fetch('SELECT * FROM hr_shifts WHERE id = ?', [$id]);
                    $db->update('hr_shifts', $row, 'id = ?', [$id]);
                    AuditLog::logChange('hr_shift_update', 'hr_shifts', $id, $old ?: [], $row);
                } else {
                    $id = $db->insert('hr_shifts', $row);
                    AuditLog::log('hr_shift_create', 'hr_shifts', $id, null, $row);
                }
                hr_flash('success', 'شەفتی «' . $row['name'] . '» پاشەکەوت کرا');
                break;

            case 'save_holiday':
                $date = hr_date($_POST['holiday_date'] ?? null);
                if ($name === '' || !$date) {
                    throw new InvalidArgumentException('ناو و ڕێکەوتی پشوو پێویستن');
                }
                $row = ['name' => $name, 'holiday_date' => $date,
                        'is_recurring' => empty($_POST['is_recurring']) ? 0 : 1, 'is_active' => 1];
                if ($id > 0) {
                    $db->update('hr_holidays', $row, 'id = ?', [$id]);
                } else {
                    $id = $db->insert('hr_holidays', $row);
                }
                AuditLog::log('hr_holiday_save', 'hr_holidays', $id, null, $row);
                hr_flash('success', 'پشووی «' . $name . '» پاشەکەوت کرا');
                break;

            case 'delete_holiday':
                $old = $db->fetch('SELECT * FROM hr_holidays WHERE id = ?', [$id]);
                $db->delete('hr_holidays', 'id = ?', [$id]);
                AuditLog::log('hr_holiday_delete', 'hr_holidays', $id, $old ?: null, null);
                hr_flash('success', 'پشووەکە سڕایەوە');
                break;

            case 'save_department':
                if ($name === '') {
                    throw new InvalidArgumentException('ناوی بەش پێویستە');
                }
                $row = ['name' => mb_substr($name, 0, 100), 'is_active' => empty($_POST['is_active']) ? 0 : 1];
                if ($id > 0) {
                    $db->update('hr_departments', $row, 'id = ?', [$id]);
                } else {
                    $row['is_active'] = 1;
                    $id = $db->insert('hr_departments', $row);
                }
                AuditLog::log('hr_department_save', 'hr_departments', $id, null, $row);
                hr_flash('success', 'بەشی «' . $row['name'] . '» پاشەکەوت کرا');
                break;
        }
    } catch (InvalidArgumentException $ex) {
        hr_flash('error', $ex->getMessage());
    }
    hr_redirect();
}

$shifts = $db->fetchAll(
    'SELECT s.*, (SELECT COUNT(*) FROM hr_employees e WHERE e.shift_id = s.id) AS used FROM hr_shifts s ORDER BY s.is_active DESC, s.name');
$holidays = $db->fetchAll('SELECT * FROM hr_holidays ORDER BY is_recurring DESC, SUBSTRING(holiday_date, 6), holiday_date');
$departments = $db->fetchAll(
    'SELECT d.*, (SELECT COUNT(*) FROM hr_employees e WHERE e.department_id = d.id) AS used FROM hr_departments d ORDER BY d.is_active DESC, d.name');

$pageTitle = 'ڕێکخستنەکانی سەرچاوە مرۆییەکان';
$customCSS = $hrBaseCss . <<<CSS
.hr-set{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;align-items:start}
.hr-days{display:flex;flex-wrap:wrap;gap:6px}
.hr-days label{display:inline-flex;align-items:center;gap:5px;padding:6px 10px;border:1px solid #e5e7eb;border-radius:8px;font-size:12.5px;cursor:pointer}
.hr-days input:checked + span{color:#0f766e;font-weight:800}
.hr-inline{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;padding:12px;border-top:1px solid #f1f5f9;background:#fafbfc}
.hr-inline .form-group{margin:0;flex:1;min-width:120px}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();

$dayChips = function (array $on) {
    $html = '<div class="hr-days">';
    foreach (HrAttendance::WEEK_ORDER as $d) {
        $html .= '<label><input type="checkbox" name="work_days[]" value="' . $d . '"' . (in_array($d, $on, true) ? ' checked' : '') . '>'
            . '<span>' . hr_esc(HrAttendance::WEEKDAYS[$d]) . '</span></label>';
    }
    return $html . '</div>';
};
?>

<div class="hr-set">
    <!-- Shifts -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title" style="margin:0"><i class="fas fa-business-time"></i> شەفتەکان</h3>
            <button type="button" class="btn btn-sm btn-primary" onclick="hrShiftEdit(null)"><i class="fas fa-plus"></i> شەفتی نوێ</button>
        </div>
        <div class="panel-body" style="padding:0">
            <table class="hr-table">
                <thead><tr><th>ناو</th><th class="num">کات</th><th>ڕۆژەکان</th><th>کارمەند</th><th></th></tr></thead>
                <tbody>
                <?php if (!$shifts): ?><tr><td colspan="5" class="hr-empty">هیچ شەفتێک نییە</td></tr><?php endif; ?>
                <?php foreach ($shifts as $s): $on = HrAttendance::workDays($s['work_days']); ?>
                    <tr class="<?= (int)$s['is_active'] ? '' : 'is-off' ?>">
                        <td><b><?= hr_esc($s['name']) ?></b><?= (int)$s['is_active'] ? '' : ' <span class="hr-badge off">ناچالاک</span>' ?></td>
                        <td class="num"><?= hr_esc(substr($s['start_time'], 0, 5)) ?> – <?= hr_esc(substr($s['end_time'], 0, 5)) ?></td>
                        <td style="font-size:12px"><?= hr_esc(implode('، ', array_map(fn($d) => HrAttendance::WEEKDAYS[$d], array_values(array_filter(HrAttendance::WEEK_ORDER, fn($d) => in_array($d, $on, true)))))) ?></td>
                        <td class="num"><?= (int)$s['used'] ?></td>
                        <td><button type="button" class="btn btn-sm btn-secondary" onclick='hrShiftEdit(<?= json_encode([
                            'id' => (int)$s['id'], 'name' => $s['name'], 'start' => substr($s['start_time'], 0, 5), 'end' => substr($s['end_time'], 0, 5),
                            'break' => (int)$s['break_minutes'], 'grace' => (int)$s['grace_minutes'], 'days' => $on, 'active' => (int)$s['is_active']], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'><i class="fas fa-pen"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Holidays -->
    <div class="panel">
        <div class="panel-header"><h3 class="panel-title" style="margin:0"><i class="fas fa-umbrella-beach"></i> پشووە فەرمییەکان</h3></div>
        <div class="panel-body" style="padding:0">
            <table class="hr-table">
                <thead><tr><th>ناو</th><th class="num">ڕێکەوت</th><th>دووبارە</th><th></th></tr></thead>
                <tbody>
                <?php if (!$holidays): ?><tr><td colspan="4" class="hr-empty">هیچ پشوویەک نییە</td></tr><?php endif; ?>
                <?php foreach ($holidays as $h): ?>
                    <tr>
                        <td><?= hr_esc($h['name']) ?></td>
                        <td class="num"><?= hr_esc((int)$h['is_recurring'] ? substr($h['holiday_date'], 5) : $h['holiday_date']) ?></td>
                        <td><?= (int)$h['is_recurring'] ? '<span class="hr-badge in">هەموو ساڵێک</span>' : '<span class="hr-badge off">یەک جار</span>' ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('ئەم پشووە بسڕدرێتەوە؟')">
                                <input type="hidden" name="action" value="delete_holiday">
                                <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="POST" class="hr-inline" autocomplete="off">
                <input type="hidden" name="action" value="save_holiday">
                <div class="form-group"><label class="form-label">ناو</label><input type="text" name="name" class="form-control" required maxlength="150"></div>
                <div class="form-group"><label class="form-label">ڕێکەوت</label><input type="date" name="holiday_date" class="form-control" required></div>
                <label class="form-check" style="padding-bottom:8px"><input type="checkbox" name="is_recurring" value="1" checked> هەموو ساڵێک</label>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i></button>
            </form>
        </div>
    </div>

    <!-- Departments -->
    <div class="panel">
        <div class="panel-header"><h3 class="panel-title" style="margin:0"><i class="fas fa-sitemap"></i> بەشەکان</h3></div>
        <div class="panel-body" style="padding:0">
            <table class="hr-table">
                <thead><tr><th>ناو</th><th>کارمەند</th><th>دۆخ</th><th></th></tr></thead>
                <tbody>
                <?php if (!$departments): ?><tr><td colspan="4" class="hr-empty">هیچ بەشێک نییە — نموونە: پێشوازی، یاریدەدەر، پاککردنەوە</td></tr><?php endif; ?>
                <?php foreach ($departments as $d): ?>
                    <tr class="<?= (int)$d['is_active'] ? '' : 'is-off' ?>">
                        <td><?= hr_esc($d['name']) ?></td>
                        <td class="num"><?= (int)$d['used'] ?></td>
                        <td><?= (int)$d['is_active'] ? '<span class="hr-badge in">چالاک</span>' : '<span class="hr-badge off">ناچالاک</span>' ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_department">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                <input type="hidden" name="name" value="<?= hr_esc($d['name']) ?>">
                                <?php if (!(int)$d['is_active']): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-secondary" title="<?= (int)$d['is_active'] ? 'ناچالاککردن' : 'چالاککردن' ?>">
                                    <i class="fas <?= (int)$d['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="POST" class="hr-inline" autocomplete="off">
                <input type="hidden" name="action" value="save_department">
                <div class="form-group"><label class="form-label">بەشی نوێ</label><input type="text" name="name" class="form-control" required maxlength="100"></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i></button>
            </form>
        </div>
    </div>
</div>

<div class="dialog-overlay" id="hrShiftDialog">
    <div class="dialog">
        <div class="dialog-header">
            <h3 class="dialog-title" id="hrShiftTitle">شەفتی نوێ</h3>
            <button type="button" class="dialog-close" onclick="closeDialog('hrShiftDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="hrShiftForm" autocomplete="off">
            <div class="dialog-body">
                <input type="hidden" name="action" value="save_shift">
                <input type="hidden" name="id" id="sf_id" value="0">
                <div class="form-group">
                    <label class="form-label required">ناو</label>
                    <input type="text" name="name" id="sf_name" class="form-control" required maxlength="100">
                </div>
                <div class="hr-formgrid">
                    <div class="form-group"><label class="form-label required">دەستپێک</label><input type="time" name="start_time" id="sf_start" class="form-control" required></div>
                    <div class="form-group"><label class="form-label required">کۆتایی</label><input type="time" name="end_time" id="sf_end" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">پشوو (خولەک)</label><input type="number" name="break_minutes" id="sf_break" class="form-control" min="0" max="600"></div>
                    <div class="form-group"><label class="form-label">ماوەی ڕێگەپێدراو بۆ دواکەوتن (خولەک)</label><input type="number" name="grace_minutes" id="sf_grace" class="form-control" min="0" max="240"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">ڕۆژەکانی دەوام</label>
                    <div id="sf_days"><?= $dayChips(HrAttendance::DEFAULT_WORK_DAYS) ?></div>
                </div>
                <label class="form-check"><input type="checkbox" name="is_active" id="sf_active" value="1" checked> چالاک</label>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('hrShiftDialog')">پاشگەزبوونەوە</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
            </div>
        </form>
    </div>
</div>

<?php
$defaultDays = json_encode(HrAttendance::DEFAULT_WORK_DAYS);
$customJS = <<<JS
function hrShiftEdit(s) {
    s = s || {id: 0, name: '', start: '08:00', end: '17:00', break: 60, grace: 15, days: {$defaultDays}, active: 1};
    document.getElementById('sf_id').value = s.id;
    document.getElementById('sf_name').value = s.name;
    document.getElementById('sf_start').value = s.start;
    document.getElementById('sf_end').value = s.end;
    document.getElementById('sf_break').value = s.break;
    document.getElementById('sf_grace').value = s.grace;
    document.getElementById('sf_active').checked = Number(s.active) === 1;
    document.querySelectorAll('#sf_days input').forEach(cb => { cb.checked = s.days.includes(Number(cb.value)); });
    document.getElementById('hrShiftTitle').textContent = s.id ? 'دەستکاری شەفت' : 'شەفتی نوێ';
    openDialog('hrShiftDialog');
}
JS;
require_once __DIR__ . '/../includes/footer.php';
