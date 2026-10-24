<?php
/**
 * پەنجەمۆرەکان — see and fix punches.
 *
 * Two views over the same rows:
 *   - one employee, one month (default): every work day of the month
 *   - one day, everyone: ?view=day&date=Y-m-d
 *
 * A click on a day opens the day editor (hr/_day_editor.php): change a time,
 * switch IN/OUT, delete, add, add or remove hours, or take a suggested fix.
 * «تۆماری خێرا» at the top records a punch at this moment by PIN, for when
 * the device is down. Every change goes through classes/HrPunch.php, which
 * writes it to the activity log — Panjamor's punch editor kept no trail.
 *
 * A punch's work day is DATE(time − 8h): a 01:30 OUT still closes the
 * evening before, and shows here with «+1».
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.punches.manage');

$hr = new HrAttendance();
$employees = $hr->employees(['status' => '']);
$active = array_values(array_filter($employees, fn($e) => $e['status'] === 'active'));
$view = ($_GET['view'] ?? '') === 'day' ? 'day' : 'employee';
$today = HrAttendance::currentWorkDate();
$totals = null;

if ($view === 'day') {
    $date = hr_date($_GET['date'] ?? null) ?? $today;
    $rows = [];
    foreach ($hr->period($active, $date, $date) as $p) {
        $rows[] = ['employee' => $p['employee'], 'day' => $p['days'][$date]];
    }
} else {
    $month = hr_month($_GET['month'] ?? null);
    $empId = (int)($_GET['employee_id'] ?? 0);
    $emp = null;
    foreach ($employees as $e) {
        if ((int)$e['id'] === $empId) {
            $emp = $e;
        }
    }
    $emp = $emp ?? ($active[0] ?? null);
    $rows = [];
    if ($emp) {
        $from = $month . '-01';
        $to = min(date('Y-m-t', strtotime($from)), max($from, $today));
        $period = $hr->period([$emp], $from, $to)[(int)$emp['id']];
        foreach (array_reverse($period['days']) as $d) {
            $rows[] = ['employee' => $emp, 'day' => $d];
        }
        $totals = $period['totals'];
    }
}

$statusLabels = [
    'worked' => ['in', 'هاتووە'], 'holiday' => ['off', 'پشوو'], 'absent' => ['out', 'غیاب'], 'future' => ['off', ''],
];

$pageTitle = 'پەنجەمۆرەکان';
$customCSS = $hrBaseCss . <<<CSS
.hr-views{display:inline-flex;background:#f1f5f9;border-radius:9px;padding:3px;gap:2px}
.hr-views a{padding:7px 14px;border-radius:7px;font-size:13px;font-weight:700;color:#64748b;text-decoration:none}
.hr-views a.on{background:#fff;color:#0f766e;box-shadow:0 1px 2px rgba(0,0,0,.08)}
.hr-punch-cell{display:flex;flex-wrap:wrap;gap:5px;align-items:center}
tr.is-off td{background:#fafafa}
tr[data-hrd-e]:hover td{background:#f0fdfa}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
hr_quick_punch($active);
?>

<form class="hr-filter" method="GET">
    <div class="form-group" style="min-width:auto">
        <label>پیشاندان</label>
        <div class="hr-views">
            <a href="?view=employee" class="<?= $view === 'employee' ? 'on' : '' ?>"><i class="fas fa-user"></i> کارمەندێک</a>
            <a href="?view=day" class="<?= $view === 'day' ? 'on' : '' ?>"><i class="fas fa-calendar-day"></i> ڕۆژێک</a>
        </div>
    </div>
    <input type="hidden" name="view" value="<?= $view ?>">
    <?php if ($view === 'day'): ?>
    <div class="form-group">
        <label>ڕۆژ</label>
        <input type="date" name="date" class="form-control" value="<?= hr_esc($date) ?>" onchange="this.form.submit()">
    </div>
    <div style="display:flex;gap:6px;padding-bottom:2px">
        <a class="btn btn-secondary" href="?view=day&date=<?= hr_esc(date('Y-m-d', strtotime($date . ' -1 day'))) ?>" title="ڕۆژی پێشوو"><i class="fas fa-chevron-right"></i></a>
        <a class="btn btn-secondary" href="?view=day&date=<?= hr_esc(date('Y-m-d', strtotime($date . ' +1 day'))) ?>" title="ڕۆژی دواتر"><i class="fas fa-chevron-left"></i></a>
    </div>
    <div class="hr-muted" style="font-size:12.5px;padding-bottom:8px"><?= hr_esc(HrAttendance::WEEKDAYS[(int)date('w', strtotime($date))]) ?></div>
    <?php else: ?>
    <div class="form-group" style="flex:1;min-width:220px">
        <label>کارمەند</label>
        <select name="employee_id" class="form-control" onchange="this.form.submit()">
            <?= hr_employee_options($employees, $emp['id'] ?? null) ?>
        </select>
    </div>
    <div class="form-group">
        <label>مانگ</label>
        <input type="month" name="month" class="form-control" value="<?= hr_esc($month) ?>" onchange="this.form.submit()">
    </div>
    <?php if ($totals): ?>
    <div style="font-size:12.5px;padding-bottom:8px" data-hr-refresh="totals">
        <b><?= hr_esc(HrAttendance::hm($totals['hours'], false)) ?></b> کاتژمێر ·
        <?= (int)$totals['worked_days'] ?> ڕۆژ
        <?php if ($totals['unpaired'] > 0): ?> · <span class="hr-badge warn"><?= (int)$totals['unpaired'] ?> ناتەواو</span><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</form>

<div class="hr-table-wrap" data-hr-refresh="punches">
    <table class="hr-table">
        <thead>
            <tr>
                <?php if ($view === 'day'): ?><th>کارمەند</th><?php else: ?><th>ڕۆژ</th><?php endif; ?>
                <th>دۆخ</th>
                <th>پەنجەمۆرەکان</th>
                <th class="num">کاتژمێر</th>
                <th class="num">زیادە / لێبڕین</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="hr-empty">هیچ زانیارییەک نییە</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
            $e = $row['employee'];
            $d = $row['day'];
            [$cls, $label] = $statusLabels[$d['day_status']];
            $loose = [];
            foreach ($d['pairs'] as $pair) {
                if ($pair['status'] !== 'complete') {
                    $loose[] = ($pair['in'] ?? $pair['out'])['id'];
                }
            }
        ?>
            <tr class="<?= $d['day_status'] === 'holiday' ? 'is-off' : '' ?>" data-hrd-e="<?= (int)$e['id'] ?>" data-hrd-d="<?= $d['date'] ?>">
                <?php if ($view === 'day'): ?>
                <td><?= hr_esc($e['full_name']) ?> <small class="hr-muted"><?= hr_esc(hr_pin($e)) ?></small></td>
                <?php else: ?>
                <td class="num" style="text-align:right"><b><?= hr_esc(substr($d['date'], 8)) ?></b> <small class="hr-muted"><?= hr_esc(HrAttendance::WEEKDAYS[$d['weekday']]) ?></small></td>
                <?php endif; ?>
                <td>
                    <?php if ($label !== ''): ?><span class="hr-badge <?= $cls ?>"><?= $label ?></span><?php endif; ?>
                    <?php if ($d['day_status'] === 'holiday' && $d['holiday']): ?><small class="hr-muted"><?= hr_esc($d['holiday']) ?></small><?php endif; ?>
                    <?php if ($d['unpaired'] > 0): ?><span class="hr-badge warn">ناتەواو</span><?php endif; ?>
                </td>
                <td>
                    <div class="hr-punch-cell">
                        <?php foreach ($d['punches'] as $p): ?>
                        <span class="hr-chip <?= $p['type'] ?><?= $p['source'] === 'manual' ? ' manual' : '' ?><?= in_array($p['id'], $loose, true) ? ' miss' : '' ?>"
                              title="<?= ($p['type'] === 'in' ? 'هاتن' : 'ڕۆیشتن') . ($p['source'] === 'manual' ? ' — دەستی' : ' — ئامێر') . ($p['notes'] ? ' — ' . hr_esc($p['notes']) : '') ?>">
                            <i class="fas <?= $p['type'] === 'in' ? 'fa-arrow-right-to-bracket' : 'fa-arrow-right-from-bracket' ?>"></i><?= hr_esc(substr($p['time'], 11, 5)) ?>
                            <?php if (substr($p['time'], 0, 10) !== $d['date']): ?><small>+1</small><?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td class="num"><?= hr_esc(HrAttendance::hm($d['hours'])) ?></td>
                <td class="num">
                    <?php if ($d['add'] > 0): ?><span style="color:#15803d">+<?= hr_esc(HrAttendance::hm($d['add'])) ?></span><?php endif; ?>
                    <?php if ($d['sub'] > 0): ?><span style="color:#b91c1c">−<?= hr_esc(HrAttendance::hm($d['sub'])) ?></span><?php endif; ?>
                </td>
                <td><span class="btn btn-sm btn-secondary" title="بینین و چاککردن"><i class="fas fa-pen"></i></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="hr-muted" style="font-size:12px;margin-top:8px">
    کرتە لەسەر ڕۆژێک بکە بۆ گۆڕینی کات، هاتن/ڕۆیشتن، سڕینەوە، زیادکردنی پەنجەمۆر یان کاتژمێر ·
    <span class="hr-chip in manual">چوارچێوەی پچڕپچڕ</span> = دەستی ·
    <span class="hr-chip miss">زەرد</span> = بێ جووت ·
    <small>+1</small> = دوای نیوەشەو، هێشتا سەر بە هەمان ڕۆژی کارە
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
