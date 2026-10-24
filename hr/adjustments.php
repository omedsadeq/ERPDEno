<?php
/**
 * زیادە و لێبڕینی کاتژمێر — hours added to or taken from one work day.
 *
 * They change HOURS, not money: the monthly report adds them to the punched
 * hours and the pay follows. Limits as in Panjamor: up to 12 hours of overtime
 * and 8 hours of deduction per entry, and a reason is required.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.adjustments.manage');

$db = Database::getInstance();
const HR_ADJ_TYPES = ['overtime' => 'زیادە', 'deduction' => 'لێبڕین'];
const HR_ADJ_MAX = ['overtime' => 12, 'deduction' => 8];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $type = (string)($_POST['adj_type'] ?? '');
            $date = hr_date($_POST['adj_date'] ?? null);
            $hours = round((float)str_replace(',', '.', (string)($_POST['hours'] ?? '0')), 2);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $emp = $db->fetch("SELECT id, full_name FROM hr_employees WHERE id = ? AND status = 'active'", [$empId]);
            if (!$emp) {
                throw new InvalidArgumentException('کارمەند هەڵبژێرە');
            }
            if (!isset(HR_ADJ_TYPES[$type])) {
                throw new InvalidArgumentException('جۆر هەڵبژێرە: زیادە یان لێبڕین');
            }
            if (!$date) {
                throw new InvalidArgumentException('ڕێکەوت دروست نییە');
            }
            if ($hours <= 0 || $hours > HR_ADJ_MAX[$type]) {
                throw new InvalidArgumentException('کاتژمێری ' . HR_ADJ_TYPES[$type] . ' دەبێت لە نێوان 0 و ' . HR_ADJ_MAX[$type] . ' بێت');
            }
            if ($reason === '') {
                throw new InvalidArgumentException('هۆکار پێویستە');
            }
            $row = [
                'employee_id' => $empId, 'adj_date' => $date, 'adj_type' => $type,
                'hours' => $hours, 'reason' => mb_substr($reason, 0, 2000), 'created_by' => $hrUserId ?: null,
            ];
            $id = $db->insert('hr_adjustments', $row);
            AuditLog::log('hr_adjustment_add', 'hr_adjustments', $id, null, $row + ['employee_name' => $emp['full_name']]);
            hr_flash('success', HR_ADJ_TYPES[$type] . 'ی ' . $hours . ' کاتژمێر بۆ «' . $emp['full_name'] . '» تۆمارکرا');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $old = $db->fetch('SELECT * FROM hr_adjustments WHERE id = ?', [$id]);
            if (!$old) {
                throw new InvalidArgumentException('تۆمارەکە نەدۆزرایەوە');
            }
            $db->delete('hr_adjustments', 'id = ?', [$id]);
            AuditLog::log('hr_adjustment_delete', 'hr_adjustments', $id, $old, null);
            hr_flash('success', 'تۆمارەکە سڕایەوە');
        }
    } catch (InvalidArgumentException $ex) {
        hr_flash('error', $ex->getMessage());
    }
    hr_redirect();
}

$month = hr_month($_GET['month'] ?? null);
$fEmp = (int)($_GET['employee_id'] ?? 0);
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));

$employees = (new HrAttendance())->employees();
$params = [$from, $to];
$sql = "SELECT a.*, e.full_name, u.full_name AS created_by_name
        FROM hr_adjustments a
        JOIN hr_employees e ON e.id = a.employee_id
        LEFT JOIN users u ON u.id = a.created_by
        WHERE a.adj_date BETWEEN ? AND ?";
if ($fEmp > 0) {
    $sql .= ' AND a.employee_id = ?';
    $params[] = $fEmp;
}
$rows = $db->fetchAll($sql . ' ORDER BY a.adj_date DESC, a.id DESC', $params);

$sumAdd = 0.0;
$sumSub = 0.0;
foreach ($rows as $r) {
    if ($r['adj_type'] === 'overtime') {
        $sumAdd += (float)$r['hours'];
    } else {
        $sumSub += (float)$r['hours'];
    }
}

$pageTitle = 'زیادە و لێبڕینی کاتژمێر';
$customCSS = $hrBaseCss . <<<CSS
.hr-adj-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start}
.hr-adj-type{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.hr-adj-type input{display:none}
.hr-adj-type label{display:flex;align-items:center;justify-content:center;gap:6px;padding:9px;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;font-weight:700;color:#64748b}
.hr-adj-type input:checked + label.add{border-color:#16a34a;background:#f0fdf4;color:#15803d}
.hr-adj-type input:checked + label.sub{border-color:#dc2626;background:#fef2f2;color:#b91c1c}
.hr-adj-plus{color:#15803d;font-weight:800}
.hr-adj-minus{color:#b91c1c;font-weight:800}
@media (max-width:1000px){.hr-adj-layout{grid-template-columns:1fr}}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<div class="hr-adj-layout">
    <div>
        <form class="hr-filter" method="GET">
            <div class="form-group">
                <label>مانگ</label>
                <input type="month" name="month" class="form-control" value="<?= hr_esc($month) ?>" onchange="this.form.submit()">
            </div>
            <div class="form-group" style="flex:1">
                <label>کارمەند</label>
                <select name="employee_id" class="form-control" onchange="this.form.submit()">
                    <option value="0">هەموو کارمەندان</option>
                    <?= hr_employee_options($employees, $fEmp) ?>
                </select>
            </div>
            <div style="font-size:12.5px;padding-bottom:8px">
                <span class="hr-adj-plus">+<?= hr_esc(HrAttendance::hm($sumAdd, false)) ?></span>
                &nbsp;/&nbsp;
                <span class="hr-adj-minus">−<?= hr_esc(HrAttendance::hm($sumSub, false)) ?></span>
            </div>
        </form>

        <div class="hr-table-wrap">
            <table class="hr-table">
                <thead>
                    <tr>
                        <th>ڕێکەوت</th>
                        <th>کارمەند</th>
                        <th>جۆر</th>
                        <th class="num">کاتژمێر</th>
                        <th>هۆکار</th>
                        <th>تۆمارکەر</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="hr-empty">لەم مانگەدا هیچ زیادە یان لێبڕینێک نییە</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): $isAdd = $r['adj_type'] === 'overtime'; ?>
                    <tr>
                        <td class="num"><?= hr_esc($r['adj_date']) ?><br><small class="hr-muted"><?= hr_esc(HrAttendance::WEEKDAYS[(int)date('w', strtotime($r['adj_date']))]) ?></small></td>
                        <td><?= hr_esc($r['full_name']) ?> <small class="hr-muted"><?= (int)$r['employee_id'] ?></small></td>
                        <td><span class="hr-badge <?= $isAdd ? 'in' : 'out' ?>"><?= HR_ADJ_TYPES[$r['adj_type']] ?></span></td>
                        <td class="num <?= $isAdd ? 'hr-adj-plus' : 'hr-adj-minus' ?>"><?= $isAdd ? '+' : '−' ?><?= hr_esc(HrAttendance::hm((float)$r['hours'], false)) ?></td>
                        <td style="max-width:320px"><?= nl2br(hr_esc($r['reason'])) ?></td>
                        <td><small class="hr-muted"><?= hr_esc($r['created_by_name'] ?: 'Panjamor') ?></small></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('ئەم تۆمارە بسڕدرێتەوە؟')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="سڕینەوە"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header"><h3 class="panel-title" style="margin:0"><i class="fas fa-plus-circle"></i> تۆماری نوێ</h3></div>
        <div class="panel-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <div class="hr-adj-type">
                        <input type="radio" name="adj_type" id="adjAdd" value="overtime" checked>
                        <label for="adjAdd" class="add"><i class="fas fa-plus"></i> زیادە</label>
                        <input type="radio" name="adj_type" id="adjSub" value="deduction">
                        <label for="adjSub" class="sub"><i class="fas fa-minus"></i> لێبڕین</label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label required">کارمەند</label>
                    <select name="employee_id" class="form-control" required>
                        <option value="">— هەڵبژێرە —</option>
                        <?= hr_employee_options($employees, $fEmp) ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">ڕێکەوت</label>
                        <input type="date" name="adj_date" class="form-control" required value="<?= hr_esc(HrAttendance::currentWorkDate()) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label required">کاتژمێر</label>
                        <input type="number" name="hours" class="form-control" required min="0.25" max="12" step="0.25" dir="ltr" placeholder="8">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label required">هۆکار</label>
                    <textarea name="reason" class="form-control" rows="3" required></textarea>
                </div>
                <div class="form-hint" style="margin-bottom:12px">زیادە تا 12 کاتژمێر، لێبڕین تا 8 کاتژمێر. کاتژمێرەکان دەچنە سەر کۆی کاتژمێری ئەو ڕۆژە.</div>
                <button type="submit" class="btn btn-primary" style="width:100%"><i class="fas fa-save"></i> تۆمارکردن</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
