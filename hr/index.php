<?php
/**
 * سەرچاوە مرۆییەکان — dashboard: today in four numbers, what needs fixing,
 * and the fingerprint device.
 *
 * Kept to what is not elsewhere: the screens are on the HR bar above,
 * today's full attendance is the live board (hr/live.php), and «تۆماری
 * خێرا» is on the punches page. Every 20 seconds the numbers, the list and
 * the device card are refreshed in place ([data-hr-refresh]), never the page.
 *
 * "Today" is the current WORK day (08:00 → 08:00), so at 01:00 the evening
 * shift still counts as today.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.employees.view', 'hr.reports.view');

// Remove a device card the service no longer reports: an old IP, or a device
// that is not used any more. A device still in use comes back within a minute.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'device_remove') {
    Csrf::require();
    hr_require('hr.settings');
    $db = Database::getInstance();
    $dv = $db->fetch('SELECT * FROM hr_devices WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    if ($dv && hr_device_state($dv)['cls'] === 'down') {
        $db->query('DELETE FROM hr_devices WHERE id = ?', [$dv['id']]);
        if (!$db->fetch('SELECT id FROM hr_devices WHERE ip = ?', [$dv['ip']])) {
            $db->query('DELETE FROM hr_device_users WHERE device_ip = ?', [$dv['ip']]);
        }
        AuditLog::log('hr_device_remove', 'hr_devices', (int)$dv['id'], ['ip' => $dv['ip'], 'serial' => $dv['serial']], null);
        hr_flash('success', 'ئامێری ' . $dv['ip'] . ' لابرا');
    } else {
        hr_flash('error', 'تەنها ئامێرێک لادەبرێت کە خزمەتگوزارییەکە ئێستا ناینێرێت');
    }
    hr_redirect();
}

$hr = new HrAttendance();
$today = HrAttendance::currentWorkDate();
$monthStart = substr($today, 0, 8) . '01';
$canReports = hr_can('hr.reports.view');
$canPay = hr_can('hr.payroll.view');
$canOpenDay = $canReports || hr_can('hr.punches.manage');
$canRemoveDevice = hr_can('hr.settings');

$employees = $hr->employees();
$month = $hr->period($employees, $monthStart, $today);

// Today
$inNow = $came = $notYet = 0;
foreach ($month as $p) {
    $d = $p['days'][$today];
    $last = $d['punches'] ? end($d['punches']) : null;
    if ($last) {
        $came++;
        if ($last['type'] === 'in') {
            $inNow++;
        }
    } elseif ($d['day_status'] !== 'holiday') {
        $notYet++;
    }
}

// Days before today with a punch that has no pair: the month's count, and
// the last week's days to fix right here. Today is left out — an open IN
// today is just someone at work.
$unpairedMonth = 0;
$toFix = [];
$weekAgo = date('Y-m-d', strtotime($today . ' -7 days'));
foreach ($month as $id => $p) {
    foreach ($p['days'] as $date => $d) {
        if ($date >= $today || $d['unpaired'] === 0) {
            continue;
        }
        $unpairedMonth++;
        if ($date >= $weekAgo) {
            $toFix[] = ['employee' => $p['employee'], 'day' => $d];
        }
    }
}
usort($toFix, fn($a, $b) => [$b['day']['date'], $a['employee']['full_name']] <=> [$a['day']['date'], $b['employee']['full_name']]);
$toFixShown = array_slice($toFix, 0, 8);

// This month so far
$monthHours = 0.0;
$monthPay = 0.0;
foreach ($month as $p) {
    $monthHours += $p['totals']['net'];
    $monthPay += HrAttendance::pay($p['employee'], $p['totals']['net'])['rounded'];
}

$devices = hr_devices();
$unknownCount = 0;
foreach ($devices as $dv) {
    $unknownCount += count(array_filter(explode(',', (string)$dv['unknown_pins'])));
}
try {
    // People on the device whose PIN no employee has (hr/device-users.php).
    $unknownCount = max($unknownCount, (int)Database::getInstance()->fetch(
        "SELECT COUNT(*) AS n FROM hr_device_users du
         WHERE NOT EXISTS (SELECT 1 FROM hr_employees e
                           WHERE e.device_pin = du.pin OR ((e.device_pin IS NULL OR e.device_pin = '') AND CAST(e.id AS CHAR) = du.pin))"
    )['n']);
} catch (Throwable $e) {
    // hr_device_users arrives with migration hr_06.
}

/** "3 خولەک لەمەوبەر" for a date-time, or '—'. */
function hr_ago(?string $when): string
{
    if (!$when) {
        return '—';
    }
    $s = time() - strtotime($when);
    if ($s < 60) {
        return 'ئێستا';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . ' خولەک لەمەوبەر';
    }
    if ($s < 86400) {
        return intdiv($s, 3600) . ' کاتژمێر لەمەوبەر';
    }
    return date('Y-m-d H:i', strtotime($when));
}

$liveUrl = APP_URL . '/hr/live.php';
$missingUrl = APP_URL . '/hr/reports.php?r=missing&from=' . $monthStart . '&to=' . max($monthStart, date('Y-m-d', strtotime($today . ' -1 day')));

$pageTitle = 'سەرچاوە مرۆییەکان';
$customCSS = $hrBaseCss . <<<CSS
.db-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.db-head h2{margin:0;font-size:18px;font-weight:800;color:#0f172a}
.db-head h2 small{display:block;font-size:12.5px;color:#64748b;font-weight:600}
.db-head .btn{margin-inline-start:auto}
.db-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.db-kpi{display:block;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;border-inline-start:5px solid var(--c);
    color:inherit;text-decoration:none;transition:transform .15s,box-shadow .15s}
.db-kpi:hover{transform:translateY(-2px);box-shadow:0 8px 18px -10px rgba(15,23,42,.35);text-decoration:none;color:inherit}
.db-kpi .l{font-size:12.5px;font-weight:700;color:#64748b}
.db-kpi .v{font-size:32px;font-weight:800;color:var(--c);line-height:1.2;font-variant-numeric:tabular-nums}
.db-kpi .v small{font-size:15px;color:#94a3b8;font-weight:700}
.db-kpi .s{font-size:11.5px;color:#94a3b8}
.db-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;align-items:start}
.db-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.db-panel+.db-panel{margin-top:16px}
.db-panel h3{display:flex;align-items:center;gap:8px;margin:0;padding:12px 14px;font-size:14px;font-weight:800;color:#334155;border-bottom:1px solid #f1f5f9}
.db-panel h3 a{margin-inline-start:auto;font-size:12.5px;font-weight:700;color:#0f766e;text-decoration:none}
.db-fix{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f8fafc}
.db-fix:last-child{border-bottom:0}
.db-fix .who{flex:1;min-width:0}
.db-fix .who b{display:block;font-size:13.5px;color:#0f172a}
.db-fix .who small{color:#94a3b8}
.db-fix .chips{display:flex;gap:4px;flex-wrap:wrap}
.db-ok{padding:28px 14px;text-align:center;color:#16a34a;font-weight:700}
.db-ok i{display:block;font-size:26px;margin-bottom:6px}
.db-dev{padding:12px 14px;border-inline-start:4px solid var(--c)}
.db-dev.ok{--c:#16a34a}.db-dev.warn{--c:#f59e0b}.db-dev.down{--c:#dc2626}
.db-dev .st{display:flex;align-items:center;gap:8px;font-weight:800;color:var(--c)}
.db-dev .st .dot{width:9px;height:9px;border-radius:50%;background:var(--c)}
.db-dev.ok .st .dot{animation:hrpulse 1.6s infinite}
@keyframes hrpulse{50%{opacity:.25}}
.db-dev .meta{margin-top:6px;font-size:12.5px;color:#475569;line-height:1.9}
.db-dev .meta b{color:#0f172a}
.db-warn{display:block;margin:8px 14px 12px;font-size:12.5px;color:#b45309;background:#fffbeb;border-radius:8px;padding:7px 10px;text-decoration:none}
a.db-warn:hover{background:#fef3c7;text-decoration:none;color:#92400e}
.db-month{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#f1f5f9}
.db-month div{background:#fff;padding:12px 14px}
.db-month span{display:block;font-size:12px;color:#64748b;font-weight:700}
.db-month b{font-size:20px;font-weight:800;color:#0f172a;font-variant-numeric:tabular-nums}
.db-month small{display:block;font-size:11px;color:#94a3b8}
@media (max-width:1100px){.db-grid{grid-template-columns:1fr}.db-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<div class="db-head">
    <h2>ئەمڕۆ <small><?= hr_esc(HrAttendance::WEEKDAYS[(int)date('w', strtotime($today))]) ?> <bdo dir="ltr"><?= hr_esc($today) ?></bdo></small></h2>
    <a class="btn btn-primary" href="<?= $liveUrl ?>"><i class="fas fa-tower-broadcast"></i> ئامادەبوونی ئەمڕۆ — ڕاستەوخۆ</a>
</div>

<div class="db-kpis" data-hr-refresh="kpis">
    <a class="db-kpi" style="--c:#15803d" href="<?= $liveUrl ?>">
        <div class="l stat-label">ئێستا لە دەوامن</div>
        <div class="v stat-value"><?= $inNow ?></div>
        <div class="s text-muted">دوایین پەنجەمۆریان هاتنە</div>
    </a>
    <a class="db-kpi" style="--c:#0f766e" href="<?= $liveUrl ?>">
        <div class="l stat-label">ئەمڕۆ هاتوون</div>
        <div class="v stat-value"><span dir="ltr" class="stat-value" style="color:inherit"><?= $came ?> <small>/ <?= count($employees) ?></small></span></div>
        <div class="s text-muted">لە کۆی کارمەندە چالاکەکان</div>
    </a>
    <a class="db-kpi" style="--c:#d97706" href="<?= $liveUrl ?>">
        <div class="l stat-label">نەهاتوون</div>
        <div class="v stat-value"><?= $notYet ?></div>
        <div class="s text-muted">ڕۆژی دەوامیانە و پەنجەمۆریان نییە</div>
    </a>
    <a class="db-kpi" style="--c:#dc2626" href="<?= $canReports ? hr_esc($missingUrl) : $liveUrl ?>">
        <div class="l stat-label">پەنجەمۆری ناتەواو — ئەم مانگە</div>
        <div class="v stat-value"><?= $unpairedMonth ?></div>
        <div class="s text-muted">ڕۆژ کە هاتن یان ڕۆیشتنی نییە</div>
    </a>
</div>

<div class="db-grid">
    <!-- What needs fixing -->
    <div class="db-panel" data-hr-refresh="fix">
        <h3><i class="fas fa-wrench" style="color:#dc2626"></i> پێویستی بە چاککردنە — حەوت ڕۆژی ڕابردوو
            <?php if ($canReports && count($toFix) > count($toFixShown)): ?><a href="<?= hr_esc($missingUrl) ?>">هەمووی (<?= count($toFix) ?>)</a><?php endif; ?></h3>
        <?php if (!$toFix): ?>
        <div class="db-ok"><i class="fas fa-circle-check"></i> هیچ پەنجەمۆرێکی ناتەواو نییە</div>
        <?php endif; ?>
        <?php foreach ($toFixShown as $f): $e = $f['employee']; $d = $f['day']; ?>
        <div class="db-fix">
            <div class="who">
                <b><?= hr_esc($e['full_name']) ?></b>
                <small><?= hr_esc(HrAttendance::WEEKDAYS[$d['weekday']]) ?> <bdo dir="ltr"><?= hr_esc($d['date']) ?></bdo> ·
                    <?= $d['in'] > $d['out'] ? 'ڕۆیشتنی تۆمار نەکراوە' : ($d['out'] > $d['in'] ? 'هاتنی تۆمار نەکراوە' : 'ڕیزی هاتن و ڕۆیشتن تێکچووە') ?></small>
            </div>
            <div class="chips">
                <?php foreach ($d['punches'] as $pu): ?>
                <span class="hr-chip <?= $pu['type'] ?>"><?= hr_esc(HrAttendance::clock($pu['time'])) ?></span>
                <?php endforeach; ?>
            </div>
            <?php if ($canOpenDay): ?>
            <button type="button" class="btn btn-sm btn-primary" data-hrd-e="<?= (int)$e['id'] ?>" data-hrd-d="<?= $d['date'] ?>"><i class="fas fa-wrench"></i> چاککردن</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div>
        <!-- The fingerprint device -->
        <div class="db-panel" data-hr-refresh="device">
            <h3><i class="fas fa-fingerprint" style="color:#0f766e"></i> ئامێری پەنجەمۆر
                <?php if ($devices): ?><a href="<?= APP_URL ?>/hr/device-users.php">بەکارهێنەران</a><?php endif; ?></h3>
            <?php if (!$devices): ?>
            <div class="db-warn" style="margin:12px 14px">هێشتا بە لێزانەوە نەبەستراوە: لەسەر کۆمپیوتەری سەنتەر <b>fingerprint-sync\install.bat</b> بە «Run as administrator» بکەرەوە.</div>
            <?php endif; ?>
            <?php foreach ($devices as $dv):
                ['cls' => $cls, 'label' => $state] = hr_device_state($dv);
                $offset = (int)$dv['time_offset_seconds']; ?>
            <div class="db-dev <?= $cls ?>">
                <div class="st"><span class="dot"></span><?= $state ?></div>
                <div class="meta">
                    <?= hr_esc($dv['name'] ?: 'ئامێری پەنجەمۆر') ?> · <bdo dir="ltr"><?= hr_esc($dv['ip']) ?></bdo><br>
                    دوایین پەنجەمۆر: <b><?= hr_esc(hr_ago($dv['last_punch_at'])) ?></b>
                    <?php if ($dv['device_records'] !== null): ?> · <b><?= (int)$dv['device_users'] ?></b> کەس لەسەر ئامێر<?php endif; ?>
                    <?php if ((int)$dv['pending'] > 0): ?><br><span style="color:#b45309"><b><?= (int)$dv['pending'] ?></b> پەنجەمۆر چاوەڕێی ناردنن</span><?php endif; ?>
                </div>
            </div>
            <?php if ($cls === 'warn' && $dv['last_error']): ?>
            <div class="db-warn"><i class="fas fa-plug-circle-xmark"></i> ئامێرەکە وەڵام ناداتەوە — تەل و کارەبای بپشکنە. پەنجەمۆرەکان لەسەری دەمێننەوە و دوای پەیوەندی دێن.</div>
            <?php elseif ($cls === 'down'): ?>
            <div class="db-warn"><i class="fas fa-power-off"></i> خزمەتگوزارییەکە دوایین جار <?= hr_esc(hr_ago($dv['last_seen_at'])) ?> ئەم ئامێرەی ناردووە. لەسەر کۆمپیوتەری سەنتەر <b>fingerprint-sync\status.bat</b> بکەرەوە.
                <?php if ($canRemoveDevice && count($devices) > 1): ?>
                <br>ئەگەر ئەمە IP ـی کۆنی ئامێرەکەیە یان ئیتر بەکارنایەت:
                <form method="POST" style="display:inline" onsubmit="return confirm('ئامێری <?= hr_esc($dv['ip']) ?> لە لێزان لاببرێت؟ پەنجەمۆرەکان دەمێننەوە.')">
                    <input type="hidden" name="action" value="device_remove">
                    <input type="hidden" name="id" value="<?= (int)$dv['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger" style="margin-top:6px"><i class="fas fa-trash"></i> لابردنی ئەم کارتە</button>
                </form>
                <?php endif; ?></div>
            <?php endif; ?>
            <?php if (abs($offset) > 120): ?>
            <div class="db-warn"><i class="fas fa-clock"></i> کاتژمێری ئامێرەکە <bdo dir="ltr"><?= round($offset / 60) ?></bdo> خولەک جیاوازە — لە config.ini دا sync_time = yes بکە.</div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($unknownCount > 0): ?>
            <a class="db-warn" href="<?= APP_URL ?>/hr/device-users.php"><i class="fas fa-user-plus"></i>
                <b><?= $unknownCount ?></b> کەس لەسەر ئامێرەکەن و لە لێزان نین — بە کرتەیەک زیادیان بکە یان بیانبەستەوە</a>
            <?php endif; ?>
        </div>

        <!-- This month -->
        <div class="db-panel" data-hr-refresh="month">
            <h3><i class="fas fa-calendar-days" style="color:#c2410c"></i> ئەم مانگە
                <?php if ($canReports): ?><a href="<?= APP_URL ?>/hr/reports.php?r=monthly">ڕاپۆرتی مانگانە</a><?php endif; ?></h3>
            <div class="db-month">
                <div><span class="stat-label">کۆی کاتژمێری کار</span><b class="stat-value" dir="ltr"><?= hr_esc(HrAttendance::hm($monthHours, false)) ?></b><small>هەموو کارمەندان تا ئەمڕۆ</small></div>
                <?php if ($canPay): ?>
                <div><span class="stat-label">خەمڵاندنی مووچە</span><b class="stat-value"><?= hr_money($monthPay) ?></b><small>تا ئەمڕۆ · مووچەی جێگیر بە تەواوی</small></div>
                <?php else: ?>
                <div><span class="stat-label">کارمەندی چالاک</span><b class="stat-value"><?= count($employees) ?></b><small>دکتۆر و کارمەند</small></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php hr_day_editor(); ?>
<?php
// Every 20 seconds the numbers, the list and the device card are brought up to
// date in place (HrDay.refresh swaps the [data-hr-refresh] parts).
$customJS = <<<JS
setInterval(function () {
    if (document.visibilityState !== 'visible') return;
    if (window.HrDay && HrDay.isOpen()) return;
    HrDay.refresh();
}, 20000);
JS;
require_once __DIR__ . '/../includes/footer.php';
