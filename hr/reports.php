<?php
/**
 * ڕاپۆرتەکانی سەرچاوە مرۆییەکان.
 *
 *   ?r=monthly   ڕاپۆرتی مانگانە — Panjamor's main sheet: one column per
 *                employee (in hr/sort-order.php's order), one row per day, then
 *                the totals and the salary rows. This IS the payroll. Opens
 *                full screen, without the clinic layout (hr/_report_monthly.php).
 *   ?r=employee  one employee's month, day by day, with every IN/OUT.
 *   ?r=inout     هاتن و ڕۆیشتن — every IN/OUT pair over a date range.
 *   ?r=missing   پەنجەمۆری ناتەواو — days with an IN that has no OUT, or the
 *                reverse, so they can be fixed before the month is paid.
 *
 * Every figure comes from HrAttendance, as do the CSV (&export=csv) and the
 * PDF (hr/report-pdf.php), so screen, Excel and PDF always agree. Money rows
 * appear only with hr.payroll.view.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.reports.view');

$db = Database::getInstance();
$hr = new HrAttendance();
$canPay = hr_can('hr.payroll.view');
$canPunch = hr_can('hr.punches.manage');

$tabs = [
    'monthly'  => ['ڕاپۆرتی مانگانە', 'fa-table-cells'],
    'employee' => ['ڕاپۆرتی کارمەند', 'fa-user-clock'],
    'inout'    => ['هاتن و ڕۆیشتن', 'fa-right-left'],
    'missing'  => ['پەنجەمۆری ناتەواو', 'fa-triangle-exclamation'],
    'late'     => ['دواکەوتن و زوو ڕۆیشتن', 'fa-hourglass-half'],
];
$tab = isset($tabs[$_GET['r'] ?? '']) ? $_GET['r'] : 'monthly';
$export = ($_GET['export'] ?? '') === 'csv';

$month = hr_month($_GET['month'] ?? null);
$monthFrom = $month . '-01';
$monthTo = date('Y-m-t', strtotime($monthFrom));
$today = HrAttendance::currentWorkDate();
$from = hr_date($_GET['from'] ?? null) ?? substr($today, 0, 8) . '01';
$to = hr_date($_GET['to'] ?? null) ?? $today;
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
// A range is capped at 92 days so a typo cannot ask for years of punches.
if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
    $to = date('Y-m-d', strtotime($from . ' +92 days'));
}
$fType = in_array($_GET['type'] ?? '', ['staff', 'doctor'], true) ? $_GET['type'] : '';
$fDept = (int)($_GET['department_id'] ?? 0);
$fEmp = (int)($_GET['employee_id'] ?? 0);

$allEmployees = $hr->employees(['status' => '']);
$activeEmployees = array_values(array_filter($allEmployees, fn($e) => $e['status'] === 'active'));
$departments = $db->fetchAll('SELECT id, name FROM hr_departments WHERE is_active = 1 ORDER BY name');
$filters = ['type' => $fType, 'department_id' => $fDept];

/** Send rows as a UTF-8 CSV that Excel opens with Kurdish intact. */
function hr_csv(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';', '"', '\\');
    }
    fclose($out);
    exit;
}

/** "08:01 ← 17:03" pairs of a day, for CSV and print. */
function hr_pairs_text(array $pairs): string
{
    $parts = [];
    foreach ($pairs as $p) {
        $parts[] = ($p['in'] ? HrAttendance::clock($p['in']['time']) : '؟') . ' - ' . ($p['out'] ? HrAttendance::clock($p['out']['time']) : '؟');
    }
    return implode(' | ', $parts);
}

// ── Build the selected report ──────────────────────────────────────────

$data = null;
if ($tab === 'monthly') {
    // Everyone the filters select, hidden or not, for the «ستوونەکان» list;
    // the sheet itself leaves the hidden ones out.
    $columnsAll = $hr->employees($filters);
    $emps = array_values(array_filter($columnsAll, fn($e) => !(int)($e['monthly_hidden'] ?? 0)));
    $data = $hr->period($emps, $monthFrom, $monthTo);
    $pay = [];
    foreach ($data as $id => $p) {
        $pay[$id] = HrAttendance::pay($p['employee'], $p['totals']['net']);
    }
    if ($export) {
        $head = ['بەروار'];
        foreach ($data as $p) {
            $head[] = $p['employee']['full_name'];
        }
        $rows = [$head];
        foreach (HrAttendance::dateRange($monthFrom, $monthTo) as $date) {
            $row = [$date];
            foreach ($data as $p) {
                $d = $p['days'][$date];
                $row[] = $d['day_status'] === 'worked' ? HrAttendance::hm($d['hours'])
                    : ($d['day_status'] === 'holiday' ? 'پشوو' : ($d['day_status'] === 'absent' ? 'غیاب' : ''));
            }
            $rows[] = $row;
        }
        $lines = [
            'کۆی گشتی پەنجەمۆر' => fn($p, $id) => HrAttendance::hm($p['totals']['hours']),
            'کۆی گشتی زیادە'    => fn($p, $id) => HrAttendance::hm($p['totals']['add']),
            'کۆی گشتی لێبڕین'   => fn($p, $id) => HrAttendance::hm($p['totals']['sub']),
            'کۆی گشتی سەعات'    => fn($p, $id) => HrAttendance::hm($p['totals']['net']),
        ];
        if ($canPay) {
            $lines += [
                'کرێی کاتژمێر (IQD)'          => fn($p, $id) => round($pay[$id]['rate']),
                'کۆی گشتی (IQD)'              => fn($p, $id) => round($pay[$id]['base']),
                'ڕێژە (%)'                    => fn($p, $id) => $pay[$id]['pct'],
                'کۆی گشتی + ڕێژە (IQD)'       => fn($p, $id) => round($pay[$id]['total']),
                'دوای ڕێکخستنی دینار (IQD)'   => fn($p, $id) => $pay[$id]['rounded'],
            ];
        }
        foreach ($lines as $label => $fn) {
            $row = [$label];
            foreach ($data as $id => $p) {
                $row[] = $fn($p, $id);
            }
            $rows[] = $row;
        }
        hr_csv('hr-monthly-' . $month . '.csv', $rows);
    }
} elseif ($tab === 'employee') {
    $emp = null;
    foreach ($allEmployees as $e) {
        if ((int)$e['id'] === $fEmp) {
            $emp = $e;
        }
    }
    $emp = $emp ?? ($activeEmployees[0] ?? null);
    if ($emp) {
        $data = $hr->period([$emp], $monthFrom, $monthTo)[(int)$emp['id']];
        $data['pay'] = HrAttendance::pay($emp, $data['totals']['net']);
        if ($export) {
            $rows = [['ڕێکەوت', 'ڕۆژ', 'دۆخ', 'هاتن و ڕۆیشتن', 'پەنجەمۆر', 'زیادە', 'لێبڕین', 'کۆ']];
            foreach ($data['days'] as $d) {
                $rows[] = [$d['date'], HrAttendance::WEEKDAYS[$d['weekday']], $d['day_status'], hr_pairs_text($d['pairs']),
                    HrAttendance::hm($d['hours']), HrAttendance::hm($d['add']), HrAttendance::hm($d['sub']), HrAttendance::hm($d['net'])];
            }
            $t = $data['totals'];
            $rows[] = ['کۆ', '', '', '', HrAttendance::hm($t['hours']), HrAttendance::hm($t['add']), HrAttendance::hm($t['sub']), HrAttendance::hm($t['net'])];
            if ($canPay) {
                $rows[] = ['مووچە دوای ڕێکخستنی دینار', '', '', '', '', '', '', $data['pay']['rounded']];
            }
            hr_csv('hr-employee-' . (int)$emp['id'] . '-' . $month . '.csv', $rows);
        }
    }
} elseif ($tab === 'inout' || $tab === 'missing') {
    $emps = $fEmp > 0 ? $hr->employees(['ids' => [$fEmp], 'status' => '']) : $hr->employees($filters);
    $data = $hr->period($emps, $from, $to);
    if ($export) {
        $rows = $tab === 'inout'
            ? [['PIN', 'کارمەند', 'ڕێکەوت', 'ڕۆژ', 'هاتن و ڕۆیشتن', 'کاتژمێر', 'دۆخ']]
            : [['PIN', 'کارمەند', 'ڕێکەوت', 'ڕۆژ', 'پەنجەمۆرەکان', 'کێشە']];
        foreach ($data as $id => $p) {
            foreach ($p['days'] as $d) {
                if ($tab === 'inout' && ($d['punches'] || $d['day_status'] === 'absent')) {
                    $rows[] = [hr_pin($p['employee']), $p['employee']['full_name'], $d['date'], HrAttendance::WEEKDAYS[$d['weekday']],
                        hr_pairs_text($d['pairs']), HrAttendance::hm($d['hours']), $d['day_status']];
                } elseif ($tab === 'missing' && $d['unpaired'] > 0) {
                    $rows[] = [hr_pin($p['employee']), $p['employee']['full_name'], $d['date'], HrAttendance::WEEKDAYS[$d['weekday']],
                        hr_pairs_text($d['pairs']), $d['in'] > $d['out'] ? 'ڕۆیشتن نییە' : ($d['out'] > $d['in'] ? 'هاتن نییە' : 'ڕیز تێکچووە')];
                }
            }
        }
        hr_csv('hr-' . $tab . '-' . $from . '_' . $to . '.csv', $rows);
    }
} elseif ($tab === 'late') {
    // Arriving after the shift's start (beyond its grace minutes) and leaving
    // before its end, per day and per person. Shown only: pay follows hours.
    $emps = $fEmp > 0 ? $hr->employees(['ids' => [$fEmp], 'status' => '']) : $hr->employees($filters);
    $data = [];
    foreach ($hr->period($emps, $from, $to) as $id => $p) {
        $sum = ['late_days' => 0, 'late' => 0, 'early_days' => 0, 'early' => 0];
        $days = [];
        foreach ($p['days'] as $d) {
            $l = HrAttendance::lateness($d, $p['employee']);
            if (!$l || ($l['late'] === 0 && $l['early'] === 0)) {
                continue;
            }
            $days[] = $d + ['lt' => $l];
            if ($l['late'] > 0) {
                $sum['late_days']++;
                $sum['late'] += $l['late'];
            }
            if ($l['early'] > 0) {
                $sum['early_days']++;
                $sum['early'] += $l['early'];
            }
        }
        if ($days) {
            $data[$id] = ['employee' => $p['employee'], 'days' => $days, 'sum' => $sum];
        }
    }
    if ($export) {
        $rows = [['PIN', 'کارمەند', 'ڕێکەوت', 'ڕۆژ', 'دەستپێکی شەفت', 'یەکەم هاتن', 'دواکەوتن (خولەک)', 'کۆتایی شەفت', 'دوایین ڕۆیشتن', 'زوو ڕۆیشتن (خولەک)']];
        foreach ($data as $id => $p) {
            foreach ($p['days'] as $d) {
                $rows[] = [hr_pin($p['employee']), $p['employee']['full_name'], $d['date'], HrAttendance::WEEKDAYS[$d['weekday']],
                    substr($d['lt']['start'], 11, 5), HrAttendance::clock($d['first_in']), $d['lt']['late'] ?: '',
                    substr($d['lt']['end'], 11, 5), HrAttendance::clock($d['last_out']), $d['lt']['early'] ?: ''];
            }
        }
        hr_csv('hr-late-' . $from . '_' . $to . '.csv', $rows);
    }
}

/** 135 → "2:15". */
function hr_minutes(int $m): string
{
    return intdiv($m, 60) . ':' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT);
}

// ── Page ───────────────────────────────────────────────────────────────

$qs = fn(array $extra) => '?' . http_build_query(array_merge($_GET, $extra));

// The monthly sheet opens full screen, without the clinic's layout.
if ($tab === 'monthly') {
    require __DIR__ . '/_report_monthly.php';
    exit;
}

// The other reports sit in the clinic's layout; the HR bar's «ڕاپۆرتەکان»
// row switches between them (hr/_nav_map.php), so it needs to know which.
$hrNavPage = 'reports-' . $tab;
$pageTitle = $tabs[$tab][0];
$pageActions = '<button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> چاپ</button>'
    . ' <a class="btn btn-secondary" href="' . hr_esc($qs(['export' => 'csv'])) . '"><i class="fas fa-file-excel"></i> Excel</a>';
if ($tab === 'employee') {
    $pageActions .= ' <a class="btn btn-primary" target="_blank" href="' . APP_URL . '/hr/report-pdf.php' . hr_esc($qs([])) . '"><i class="fas fa-file-pdf"></i> PDF</a>';
}

$customCSS = $hrBaseCss . <<<CSS
.hr-pairs{display:flex;flex-wrap:wrap;gap:4px}
.hr-pair{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:7px;background:#f1f5f9;direction:ltr;font-size:12px;font-variant-numeric:tabular-nums}
.hr-pair.bad{background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.hr-pair small{color:#64748b}
.hr-emp-sum{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px}
tr.grp td{background:#f8fafc;font-weight:800;color:#0f766e}
.hr-fix-hint{display:block;margin-top:4px;color:#92400e;font-size:11.5px}
@media print{
  .hr-print-title{display:block!important}
}
.hr-print-title{display:none;text-align:center;font-weight:800;font-size:16px;margin-bottom:8px}
CSS;
require_once __DIR__ . '/../includes/header.php';
?>

<form class="hr-filter" method="GET">
    <input type="hidden" name="r" value="<?= $tab ?>">
    <?php if ($tab === 'employee'): ?>
    <div class="form-group">
        <label>مانگ</label>
        <input type="month" name="month" class="form-control" value="<?= hr_esc($month) ?>" onchange="this.form.submit()">
    </div>
    <?php else: ?>
    <div class="form-group">
        <label>لە</label>
        <input type="date" name="from" class="form-control" value="<?= hr_esc($from) ?>">
    </div>
    <div class="form-group">
        <label>بۆ</label>
        <input type="date" name="to" class="form-control" value="<?= hr_esc($to) ?>">
    </div>
    <?php endif; ?>
    <div class="form-group" style="min-width:220px">
        <label>کارمەند</label>
        <select name="employee_id" class="form-control" <?= $tab === 'employee' ? 'onchange="this.form.submit()"' : '' ?>>
            <?php if ($tab !== 'employee'): ?><option value="0">هەموو کارمەندان</option><?php endif; ?>
            <?= hr_employee_options($allEmployees, $tab === 'employee' ? ($emp['id'] ?? null) : $fEmp) ?>
        </select>
    </div>
    <?php if ($tab !== 'employee'): ?>
    <div class="form-group">
        <label>جۆر</label>
        <select name="type" class="form-control">
            <option value="">هەموو</option>
            <option value="staff" <?= $fType === 'staff' ? 'selected' : '' ?>>کارمەند</option>
            <option value="doctor" <?= $fType === 'doctor' ? 'selected' : '' ?>>دکتۆر</option>
        </select>
    </div>
    <?php if ($departments): ?>
    <div class="form-group">
        <label>بەش</label>
        <select name="department_id" class="form-control">
            <option value="0">هەموو</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= hr_esc($d['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> پیشاندان</button>
</form>

<div data-hr-refresh="report">
<?php if ($tab === 'employee'): ?>
<!-- ═══════════════ One employee's month ═══════════════ -->
<?php if (!$data): ?>
    <div class="hr-table-wrap"><div class="hr-empty">هیچ کارمەندێک نییە</div></div>
<?php else: $t = $data['totals']; $pp = $data['pay']; ?>
<div class="hr-print-title"><?= hr_esc($emp['full_name']) ?> — <?= hr_esc(hr_month_label($month)) ?></div>
<div class="hr-emp-sum">
    <div class="hr-card" style="--c:#0f766e"><div class="lbl stat-label">کاتژمێری پەنجەمۆر</div><div class="val stat-value"><?= hr_esc(HrAttendance::hm($t['hours'], false)) ?></div></div>
    <div class="hr-card" style="--c:#15803d"><div class="lbl stat-label">زیادە</div><div class="val stat-value">+<?= hr_esc(HrAttendance::hm($t['add'], false)) ?></div></div>
    <div class="hr-card" style="--c:#b91c1c"><div class="lbl stat-label">لێبڕین</div><div class="val stat-value">−<?= hr_esc(HrAttendance::hm($t['sub'], false)) ?></div></div>
    <div class="hr-card" style="--c:#0369a1"><div class="lbl stat-label">کۆی کاتژمێر</div><div class="val stat-value"><?= hr_esc(HrAttendance::hm($t['net'], false)) ?></div><div class="sub text-muted"><?= (int)$t['worked_days'] ?> ڕۆژ کار · <?= (int)$t['absent_days'] ?> غیاب</div></div>
    <?php if ($canPay): ?>
    <div class="hr-card" style="--c:#0e7490"><div class="lbl stat-label">مووچە</div><div class="val stat-value"><?= hr_money($pp['rounded']) ?></div>
        <div class="sub text-muted"><?= $pp['fixed'] ? 'مووچەی جێگیر' : '<bdo dir="ltr">' . hr_money($pp['rate']) . ' × ' . hr_esc(HrAttendance::hm($t['net'], false)) . '</bdo>' ?><?= $pp['pct'] > 0 ? ' <bdo dir="ltr">+ ' . (float)$pp['pct'] . '%</bdo>' : '' ?></div></div>
    <?php endif; ?>
</div>
<div class="hr-table-wrap">
    <table class="hr-table">
        <thead>
            <tr><th>ڕێکەوت</th><th>دۆخ</th><th>هاتن و ڕۆیشتن</th><th class="num">پەنجەمۆر</th><th class="num">زیادە</th><th class="num">لێبڕین</th><th class="num">کۆ</th></tr>
        </thead>
        <tbody>
        <?php foreach ($data['days'] as $d): ?>
            <tr class="<?= $d['day_status'] === 'holiday' ? 'is-off' : '' ?>" data-hrd-e="<?= (int)$emp['id'] ?>" data-hrd-d="<?= $d['date'] ?>" title="کرتە بکە بۆ بینین و چاککردن">
                <td class="num" style="text-align:right"><b><?= hr_esc(substr($d['date'], 5)) ?></b> <small class="hr-muted"><?= hr_esc(HrAttendance::WEEKDAYS[$d['weekday']]) ?></small></td>
                <td>
                    <?php if ($d['day_status'] === 'worked'): ?><span class="hr-badge in">هاتووە</span>
                    <?php elseif ($d['day_status'] === 'holiday'): ?><span class="hr-badge off">پشوو</span> <small class="hr-muted"><?= hr_esc($d['holiday']) ?></small>
                    <?php elseif ($d['day_status'] === 'absent'): ?><span class="hr-badge out">غیاب</span><?php endif; ?>
                </td>
                <td><div class="hr-pairs">
                    <?php foreach ($d['pairs'] as $pr): ?>
                    <span class="hr-pair <?= $pr['status'] !== 'complete' ? 'bad' : '' ?>">
                        <?= $pr['in'] ? hr_esc(HrAttendance::clock($pr['in']['time'])) : '؟' ?> → <?= $pr['out'] ? hr_esc(HrAttendance::clock($pr['out']['time'])) : '؟' ?>
                        <?php if ($pr['status'] === 'complete'): ?><small>(<?= hr_esc(HrAttendance::hm($pr['hours'], false)) ?>)</small><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div></td>
                <td class="num"><?= hr_esc(HrAttendance::hm($d['hours'])) ?></td>
                <td class="num" style="color:#15803d"><?= $d['add'] > 0 ? '+' . hr_esc(HrAttendance::hm($d['add'])) : '' ?></td>
                <td class="num" style="color:#b91c1c"><?= $d['sub'] > 0 ? '−' . hr_esc(HrAttendance::hm($d['sub'])) : '' ?></td>
                <td class="num"><b><?= hr_esc(HrAttendance::hm($d['net'])) ?></b></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="3">کۆ</td>
                <td class="num"><?= hr_esc(HrAttendance::hm($t['hours'], false)) ?></td>
                <td class="num">+<?= hr_esc(HrAttendance::hm($t['add'], false)) ?></td>
                <td class="num">−<?= hr_esc(HrAttendance::hm($t['sub'], false)) ?></td>
                <td class="num"><?= hr_esc(HrAttendance::hm($t['net'], false)) ?></td></tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<?php elseif ($tab === 'inout'): ?>
<!-- ═══════════════ In / out pairs ═══════════════ -->
<div class="hr-print-title">هاتن و ڕۆیشتن — <bdo dir="ltr"><?= hr_esc($from) ?> / <?= hr_esc($to) ?></bdo></div>
<div class="hr-table-wrap">
    <table class="hr-table">
        <thead>
            <tr><th>ڕێکەوت</th><th>دۆخ</th><th>هاتن و ڕۆیشتن</th><th class="num">کاتژمێر</th></tr>
        </thead>
        <tbody>
        <?php $any = false; foreach ($data as $id => $p):
            $days = array_filter($p['days'], fn($d) => $d['punches'] || $d['day_status'] === 'absent');
            if (!$days) { continue; }
            $any = true; ?>
            <tr class="grp"><td colspan="3"><?= hr_esc($p['employee']['full_name']) ?> <small class="hr-muted">PIN <?= hr_esc(hr_pin($p['employee'])) ?></small></td>
                <td class="num"><?= hr_esc(HrAttendance::hm($p['totals']['hours'], false)) ?></td></tr>
            <?php foreach ($days as $d): ?>
            <tr data-hrd-e="<?= (int)$id ?>" data-hrd-d="<?= $d['date'] ?>" title="کرتە بکە بۆ بینین و چاککردن">
                <td class="num" style="text-align:right"><?= hr_esc($d['date']) ?> <small class="hr-muted"><?= hr_esc(HrAttendance::WEEKDAYS[$d['weekday']]) ?></small></td>
                <td><?= $d['day_status'] === 'absent' ? '<span class="hr-badge out">غیاب</span>' : ($d['unpaired'] ? '<span class="hr-badge warn">ناتەواو</span>' : '<span class="hr-badge in">تەواو</span>') ?></td>
                <td><div class="hr-pairs">
                    <?php foreach ($d['pairs'] as $pr): ?>
                    <span class="hr-pair <?= $pr['status'] !== 'complete' ? 'bad' : '' ?>">
                        <?= $pr['in'] ? hr_esc(HrAttendance::clock($pr['in']['time'])) : '؟' ?> → <?= $pr['out'] ? hr_esc(HrAttendance::clock($pr['out']['time'])) : '؟' ?>
                        <?php if ($pr['status'] === 'complete'): ?><small>(<?= hr_esc(HrAttendance::hm($pr['hours'], false)) ?>)</small><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div></td>
                <td class="num"><?= hr_esc(HrAttendance::hm($d['hours'])) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$any): ?><tr><td colspan="4" class="hr-empty">لەم ماوەیەدا هیچ پەنجەمۆرێک نییە</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($tab === 'missing'): ?>
<!-- ═══════════════ Missing punches ═══════════════ -->
<div class="hr-print-title">پەنجەمۆری ناتەواو — <bdo dir="ltr"><?= hr_esc($from) ?> / <?= hr_esc($to) ?></bdo></div>
<div class="hr-table-wrap">
    <table class="hr-table">
        <thead>
            <tr><th>کارمەند</th><th>ڕێکەوت</th><th>پەنجەمۆرەکان</th><th>کێشە</th><?php if ($canPunch): ?><th></th><?php endif; ?></tr>
        </thead>
        <tbody>
        <?php $count = 0; foreach ($data as $id => $p): foreach ($p['days'] as $d): if ($d['unpaired'] === 0) { continue; } $count++;
            $fixes = $canPunch ? HrAttendance::suggestions($d, $p['employee'], $hr->usualTimes((int)$id, $d['date'])) : []; ?>
            <tr data-hrd-e="<?= (int)$id ?>" data-hrd-d="<?= $d['date'] ?>">
                <td><?= hr_esc($p['employee']['full_name']) ?> <small class="hr-muted"><?= hr_esc(hr_pin($p['employee'])) ?></small></td>
                <td class="num" style="text-align:right"><?= hr_esc($d['date']) ?> <small class="hr-muted"><?= hr_esc(HrAttendance::WEEKDAYS[$d['weekday']]) ?></small></td>
                <td><div class="hr-pairs">
                    <?php foreach ($d['pairs'] as $pr): ?>
                    <span class="hr-pair <?= $pr['status'] !== 'complete' ? 'bad' : '' ?>">
                        <?= $pr['in'] ? hr_esc(HrAttendance::clock($pr['in']['time'])) : '؟' ?> → <?= $pr['out'] ? hr_esc(HrAttendance::clock($pr['out']['time'])) : '؟' ?>
                    </span>
                    <?php endforeach; ?>
                </div></td>
                <td><span class="hr-badge warn"><?= $d['in'] > $d['out'] ? 'ڕۆیشتنی تۆمار نەکراوە' : ($d['out'] > $d['in'] ? 'هاتنی تۆمار نەکراوە' : 'ڕیزی هاتن و ڕۆیشتن تێکچووە') ?></span>
                    <?php foreach ($fixes as $fx): ?><small class="hr-fix-hint"><i class="fas fa-wand-magic-sparkles"></i> <?= hr_esc($fx['label']) ?></small><?php endforeach; ?></td>
                <?php if ($canPunch): ?>
                <td><button type="button" class="btn btn-sm btn-primary" data-hrd-e="<?= (int)$id ?>" data-hrd-d="<?= $d['date'] ?>"><i class="fas fa-wrench"></i> چاککردن</button></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; endforeach; ?>
        <?php if ($count === 0): ?><tr><td colspan="5" class="hr-empty"><i class="fas fa-check-circle" style="color:#16a34a"></i> هیچ پەنجەمۆرێکی ناتەواو نییە</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($count > 0): ?>
<div class="hr-muted" style="font-size:12px;margin-top:8px"><?= $count ?> ڕۆژ. کرتە لەسەر ڕیزێک بکە: پەنجەمۆرەکانی ئەو ڕۆژە دەکرێنەوە و چاککردنە پێشنیارکراوەکە بە یەک کرتە جێبەجێ دەبێت.</div>
<?php endif; ?>

<?php else: ?>
<!-- ═══════════════ Late arrival / early leave ═══════════════ -->
<div class="hr-print-title">دواکەوتن و زوو ڕۆیشتن — <bdo dir="ltr"><?= hr_esc($from) ?> / <?= hr_esc($to) ?></bdo></div>
<div class="hr-table-wrap">
    <table class="hr-table">
        <thead>
            <tr><th>ڕێکەوت</th><th class="num">دەستپێکی شەفت</th><th class="num">یەکەم هاتن</th><th class="num">دواکەوتن</th>
                <th class="num">کۆتایی شەفت</th><th class="num">دوایین ڕۆیشتن</th><th class="num">زوو ڕۆیشتن</th></tr>
        </thead>
        <tbody>
        <?php foreach ($data as $id => $p): $s = $p['sum']; ?>
            <tr class="grp">
                <td><?= hr_esc($p['employee']['full_name']) ?> <small class="hr-muted">PIN <?= hr_esc(hr_pin($p['employee'])) ?></small></td>
                <td colspan="3" style="text-align:right"><?php if ($s['late_days']): ?><span class="hr-badge warn"><?= $s['late_days'] ?> جار دواکەوتن · کۆ <bdo dir="ltr"><?= hr_minutes($s['late']) ?></bdo></span><?php endif; ?></td>
                <td colspan="3" style="text-align:right"><?php if ($s['early_days']): ?><span class="hr-badge out"><?= $s['early_days'] ?> جار زوو ڕۆیشتن · کۆ <bdo dir="ltr"><?= hr_minutes($s['early']) ?></bdo></span><?php endif; ?></td>
            </tr>
            <?php foreach ($p['days'] as $d): $l = $d['lt']; ?>
            <tr data-hrd-e="<?= (int)$id ?>" data-hrd-d="<?= $d['date'] ?>" title="کرتە بکە بۆ بینین و چاککردن">
                <td class="num" style="text-align:right"><?= hr_esc($d['date']) ?> <small class="hr-muted"><?= hr_esc(HrAttendance::WEEKDAYS[$d['weekday']]) ?></small></td>
                <td class="num hr-muted"><?= hr_esc(substr($l['start'], 11, 5)) ?></td>
                <td class="num"><?= hr_esc(HrAttendance::clock($d['first_in'])) ?></td>
                <td class="num"><?php if ($l['late']): ?><b style="color:#b45309"><?= hr_minutes($l['late']) ?></b><?php endif; ?></td>
                <td class="num hr-muted"><?= hr_esc(substr($l['end'], 11, 5)) ?></td>
                <td class="num"><?= $l['early'] ? hr_esc(HrAttendance::clock($d['last_out'])) : '' ?></td>
                <td class="num"><?php if ($l['early']): ?><b style="color:#b91c1c"><?= hr_minutes($l['early']) ?></b><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$data): ?><tr><td colspan="7" class="hr-empty"><i class="fas fa-check-circle" style="color:#16a34a"></i> لەم ماوەیەدا کەس دوانەکەوتووە و زوو نەڕۆیشتووە</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<div class="hr-muted" style="font-size:12px;margin-top:8px">
    دواکەوتن: یەکەم هاتن دوای دەستپێکی شەفت، کاتێک لە ماوەی ڕێگەپێدراو (بە پێی شەفت، بنەڕەت 15 خولەک) زیاترە. زوو ڕۆیشتن: دوایین ڕۆیشتن پێش کۆتایی شەفت.
    ئەمانە تەنها بۆ زانیارین و مووچە ناگۆڕن. کاتی شەفت لە <a href="<?= APP_URL ?>/hr/settings.php">ڕێکخستنەکان</a> دەگۆڕدرێت.
</div>
<?php endif; ?>

</div>
<?php hr_day_editor(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
