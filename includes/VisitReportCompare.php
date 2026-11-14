<?php
/**
 * بەراورد — two periods of the visit statistics side by side (visits-report.php?tab=compare).
 *
 * The figures are the matrix's own (ئاماری سەردانەکان): the same visit-type
 * choice, the same doctor of a visit (dh_visit_report_doctor_sql) and the same
 * classification (dh_visit_report_classify), so a period here shows exactly
 * what the stats tab shows for it. Two things are added so the two periods can
 * be set fairly against each other:
 *
 *  - Periods of different length are compared on their monthly average (and
 *    per working day), not on the raw total. A month that is not over yet
 *    counts for the days gone (14 of 30 days = 0.47 month), never for days
 *    still to come.
 *  - A month in which the chosen kind of visit was not recorded at all (ئیش
 *    before it was added, July 2026) is named, so it is not taken for a drop.
 */

require_once __DIR__ . '/VisitReportStats.php';

const DH_VC_COLOR_A = '#3768a1';
const DH_VC_COLOR_B = '#e07b2e';
/** Below this many visits in the first period a change is shown as counts, not a percent. */
const DH_VC_MIN_BASE = 10;

/** A YYYY-MM month, or null. */
function dh_vc_month($v): ?string
{
    return (is_string($v) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $v) && (int) substr($v, 0, 4) >= 2000) ? $v : null;
}

/** The ready-made pairs of periods, worked out from today. */
function dh_vc_presets(string $today): array
{
    $t = new DateTimeImmutable($today);
    $y = (int) $t->format('Y');
    $thisM = $t->format('Y-m');
    $m = fn(string $mod) => $t->modify('first day of this month')->modify($mod)->format('Y-m');
    $presets = [];
    if ((int) $t->format('n') >= 7) {
        $presets['halves'] = ['label' => 'مانگی 1–6 بەرامبەر 7–' . (int) $t->format('n'),
            'a' => ["$y-01", "$y-06"], 'b' => ["$y-07", $thisM]];
    } else {
        $presets['halves'] = ['label' => 'نیوەی دووەمی پار بەرامبەر ئەمساڵ',
            'a' => [($y - 1) . '-07', ($y - 1) . '-12'], 'b' => ["$y-01", $thisM]];
    }
    $presets['month'] = ['label' => 'ئەم مانگە بەرامبەر مانگی پێشوو', 'a' => [$m('-1 month'), $m('-1 month')], 'b' => [$thisM, $thisM]];
    $presets['quarter'] = ['label' => '3 مانگی تەواوی کۆتایی بەرامبەر 3 مانگی پێشتر',
        'a' => [$m('-6 months'), $m('-4 months')], 'b' => [$m('-3 months'), $m('-1 month')]];
    $presets['year'] = ['label' => 'ئەمساڵ بەرامبەر هەمان مانگەکانی پار',
        'a' => [($y - 1) . '-01', ($y - 1) . '-' . $t->format('m')], 'b' => ["$y-01", $thisM]];
    return $presets;
}

/** The two periods asked for (a_from…a_to, b_from…b_to as months), the halves of the year when none. */
function dh_vc_input(array $in, string $today): array
{
    $presets = dh_vc_presets($today);
    $preset = isset($in['preset']) && is_string($in['preset']) && isset($presets[$in['preset']]) ? $in['preset'] : null;
    $pick = function (string $key, string $fallback) use ($in) {
        return dh_vc_month($in[$key] ?? null) ?? $fallback;
    };
    $def = $presets[$preset ?? 'halves'];
    if ($preset) {
        [$aFrom, $aTo, $bFrom, $bTo] = [$def['a'][0], $def['a'][1], $def['b'][0], $def['b'][1]];
    } else {
        $aFrom = $pick('a_from', $def['a'][0]); $aTo = $pick('a_to', $def['a'][1]);
        $bFrom = $pick('b_from', $def['b'][0]); $bTo = $pick('b_to', $def['b'][1]);
    }
    if ($aFrom > $aTo) { [$aFrom, $aTo] = [$aTo, $aFrom]; }
    if ($bFrom > $bTo) { [$bFrom, $bTo] = [$bTo, $bFrom]; }
    $activePreset = null;
    foreach ($presets as $k => $p) {
        if ($p['a'] === [$aFrom, $aTo] && $p['b'] === [$bFrom, $bTo]) { $activePreset = $k; break; }
    }
    return [
        'a_from' => $aFrom, 'a_to' => $aTo, 'b_from' => $bFrom, 'b_to' => $bTo,
        'presets' => $presets, 'preset' => $activePreset,
    ];
}

/** «مانگی ١–٦ی ٢٠٢٦», «مانگی ٨ی ٢٠٢٦», «مانگی ١١ی ٢٠٢٥ – ٢ی ٢٠٢٦». */
function dh_vc_period_label(string $from, string $to): string
{
    [$fy, $fm] = array_map('intval', explode('-', $from));
    [$ty, $tm] = array_map('intval', explode('-', $to));
    if ($from === $to) return "مانگی {$fm}ی {$fy}";
    if ($fy === $ty) return "مانگی {$fm}–{$tm}ی {$fy}";
    return "مانگی {$fm}ی {$fy} – {$tm}ی {$ty}";
}

/**
 * One period's figures. Its total, the matrix's rows (in «هەردووکیان» those
 * split the checkups only, as the matrix does), each doctor, each reception
 * staff member, and month by month; with how long it really ran.
 */
function dh_vc_period(Database $db, string $fromM, string $toM, string $visitType, string $today): array
{
    $from = $fromM . '-01';
    $to = (new DateTimeImmutable($toM . '-01'))->format('Y-m-t');
    [$typeSql, $typeParams] = dh_visit_report_type_where($visitType);
    $doctorSql = dh_visit_report_doctor_sql($visitType);
    $params = ['from' => $from, 'to' => $to];
    $groupParams = $params + $typeParams;

    $groups = $db->fetchAll(
        "SELECT {$doctorSql} AS doctor_id, v.requested_doctor_id, v.assigned_doctor_id, v.doctor_assignment_source,
                v.patient_status, v.receptionist_id, v.visit_type, DATE_FORMAT(v.visit_date, '%Y-%m') AS ym, COUNT(*) AS count
         FROM visits v
         WHERE v.visit_date BETWEEN :from AND :to AND {$typeSql}
         GROUP BY doctor_id, v.requested_doctor_id, v.assigned_doctor_id, v.doctor_assignment_source,
                  v.patient_status, v.receptionist_id, v.visit_type, ym",
        $groupParams
    );

    // the months of the period, and how much of each has gone by
    $months = [];
    $todayD = new DateTimeImmutable($today);
    for ($d = new DateTimeImmutable($from); $d->format('Y-m') <= $toM; $d = $d->modify('+1 month')) {
        $dim = (int) $d->format('t');
        $ym = $d->format('Y-m');
        if ($ym < $todayD->format('Y-m')) $gone = $dim;
        elseif ($ym === $todayD->format('Y-m')) $gone = (int) $todayD->format('j');
        else $gone = 0;
        $months[$ym] = ['count' => 0, 'days' => $dim, 'gone' => $gone, 'all' => 0, 'open_days' => 0, 'treatment' => 0, 'checkup' => 0];
    }
    // every visit of the month whatever its type: the days the clinic was open, and whether anything was recorded
    foreach ($db->fetchAll(
        "SELECT DATE_FORMAT(visit_date, '%Y-%m') AS ym, COUNT(*) AS n, COUNT(DISTINCT visit_date) AS days
         FROM visits WHERE visit_date BETWEEN :from AND :to GROUP BY ym", $params) as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']]['all'] = (int) $r['n'];
            $months[$r['ym']]['open_days'] = (int) $r['days'];
        }
    }

    $names = [];
    foreach ($db->fetchAll('SELECT id, name FROM doctors') as $d) $names[(int) $d['id']] = $d['name'];
    $users = [];
    foreach ($db->fetchAll('SELECT id, full_name FROM users') as $u) $users[(int) $u['id']] = $u['full_name'];

    $rows = ['requested' => 0, 'reception' => 0, 'other' => 0, 'unassigned' => 0, 'new' => 0, 'old' => 0, 'unknown' => 0];
    $split = ['checkup' => 0, 'treatment' => 0];
    $total = 0;
    $base = 0; // what the matrix rows split: every visit, or in «هەردووکیان» the checkups
    $doctors = [];
    $staff = [];
    foreach ($groups as $g) {
        $n = (int) $g['count'];
        $c = dh_visit_report_classify($g);
        $type = $g['visit_type'] ?? null;
        $total += $n;
        $split[$type === 'treatment' ? 'treatment' : 'checkup'] += $n;
        if (isset($months[$g['ym']])) {
            $months[$g['ym']]['count'] += $n;
            $months[$g['ym']][$type === 'treatment' ? 'treatment' : 'checkup'] += $n;
        }
        $id = $c['doctor'];
        if (!isset($doctors[$id])) {
            $doctors[$id] = ['name' => $names[$id] ?? ($id > 0 ? 'دکتۆر #' . $id : 'دکتۆر دیاری نەکراوە'), 'count' => 0];
        }
        $doctors[$id]['count'] += $n;
        if ($visitType !== 'both' || $type !== 'treatment') {
            $base += $n;
            $rows[$c['allocation']] += $n;
            $rows[$c['status']] += $n;
        }
        if ($c['staff'] > 0) {
            if (!isset($staff[$c['staff']])) {
                $staff[$c['staff']] = ['name' => $users[$c['staff']] ?? 'کارمەند #' . $c['staff'], 'count' => 0];
            }
            $staff[$c['staff']]['count'] += $n;
        }
    }

    $monthsGone = 0.0;
    $daysGone = 0;
    $openDays = 0;
    $noData = [];
    $noType = ['treatment' => [], 'checkup' => []]; // «هەردووکیان»: months one of the two was not recorded
    foreach ($months as $ym => $mo) {
        $monthsGone += $mo['gone'] / $mo['days'];
        $daysGone += $mo['gone'];
        $openDays += $mo['open_days'];
        if ($mo['count'] === 0 && $mo['all'] > 0) $noData[] = $ym;
        if ($visitType === 'both' && $mo['all'] > 0) {
            foreach ($noType as $t => $_) {
                if ($mo[$t] === 0) $noType[$t][] = $ym;
            }
        }
    }

    return [
        'from_m' => $fromM, 'to_m' => $toM, 'from' => $from, 'to' => $to,
        'label' => dh_vc_period_label($fromM, $toM),
        'total' => $total, 'base' => $base, 'rows' => $rows, 'split' => $split,
        'doctors' => $doctors, 'staff' => $staff, 'months' => $months,
        'months_gone' => $monthsGone, 'months_count' => count($months), 'days_gone' => $daysGone,
        'open_days' => $openDays, 'no_data' => $noData, 'no_type' => $noType,
        // the last day anything was recorded, to tell a quiet end from records that stop
        'last_visit' => $db->fetch("SELECT MAX(visit_date) d FROM visits WHERE visit_date BETWEEN :from AND :to", $params)['d'] ?? null,
        'last_gone' => min($to, $today),
        'per_month' => $monthsGone > 0 ? $total / $monthsGone : null,
        'per_day' => $openDays > 0 ? $total / $openDays : null,
    ];
}

/** Per month of a count over a period: null when none of the period has gone by. */
function dh_vc_pm(array $p, $count): ?float
{
    return $p['months_gone'] > 0 ? (float) $count / $p['months_gone'] : null;
}

/** Change from A to B in percent, null when A has nothing to compare against. */
function dh_vc_change(?float $a, ?float $b): ?float
{
    if ($a === null || $b === null || abs($a) < 1e-9) return null;
    return ($b - $a) / $a * 100;
}

function dh_vc_share($n, $of): ?float
{
    return $of > 0 ? $n / $of * 100 : null;
}

/** 1,234 · 312.5 · 4.47 — at most $dec decimals, none when whole. */
function dh_vc_num(?float $v, int $dec = 1): string
{
    if ($v === null) return '—';
    $s = number_format($v, $dec);
    return $dec > 0 ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** The change badge: ▲ +12.4% / ▼ −8% / «نوێ» / —. */
function dh_vc_badge(?float $a, ?float $b, bool $points = false): string
{
    if ($points) {
        if ($a === null || $b === null) return '<span class="vc-badge vc-flat">—</span>';
        $d = $b - $a;
        $cls = abs($d) < 0.05 ? 'vc-flat' : ($d > 0 ? 'vc-up' : 'vc-down');
        $arrow = abs($d) < 0.05 ? '' : ($d > 0 ? '▲ ' : '▼ ');
        $sign = abs($d) < 0.05 ? '' : ($d > 0 ? '+' : '−');
        return '<span class="vc-badge ' . $cls . '" title="خاڵی سەدی">' . $arrow . '<bdi dir="ltr">' . $sign . (abs($d) < 0.05 ? '0' : dh_vc_num(abs($d))) . '</bdi> خاڵ</span>';
    }
    if (($a === null || abs($a) < 1e-9) && $b !== null && $b > 0) return '<span class="vc-badge vc-new">نوێ</span>';
    $c = dh_vc_change($a, $b);
    if ($c === null) return '<span class="vc-badge vc-flat">—</span>';
    $cls = abs($c) < 0.05 ? 'vc-flat' : ($c > 0 ? 'vc-up' : 'vc-down');
    $arrow = abs($c) < 0.05 ? '' : ($c > 0 ? '▲ ' : '▼ ');
    $sign = abs($c) < 0.05 ? '' : ($c > 0 ? '+' : '−');
    return '<span class="vc-badge ' . $cls . '">' . $arrow . '<bdi dir="ltr">' . $sign . (abs($c) < 0.05 ? '0' : dh_vc_num(abs($c))) . '%</bdi></span>';
}

/**
 * The change of a count, unless its first period is too small for a percent
 * to mean anything (1 → 12 is not «+1,100%»): then the two counts themselves.
 */
function dh_vc_count_badge(int $countA, int $countB, ?float $pa, ?float $pb): string
{
    if ($countA === 0 && $countB > 0) return '<span class="vc-badge vc-new">تەنها لە دووەم</span>';
    if ($countB === 0 && $countA > 0) return '<span class="vc-badge vc-down">تەنها لە یەکەم</span>';
    if ($countA < DH_VC_MIN_BASE) {
        return '<span class="vc-badge vc-flat" title="ژمارەی ماوەی یەکەم کەمترە لە ' . DH_VC_MIN_BASE . '، ڕێژەی سەدی واتای نییە">ژمارە کەمە: '
            . '<bdi dir="ltr">' . number_format($countA) . ' → ' . number_format($countB) . '</bdi></span>';
    }
    return dh_vc_badge($pa, $pb);
}

/** A small bar of the change, around a middle line; ±100% and beyond fill the side. */
function dh_vc_change_bar(?float $a, ?float $b): string
{
    $c = dh_vc_change($a, $b);
    if ($c === null) return '<span class="vc-cbar"></span>';
    $w = min(50, abs($c) / 2);
    $side = $c >= 0 ? 'up' : 'down';
    return '<span class="vc-cbar"><i class="vc-cbar-' . $side . '" style="width:' . round($w, 1) . '%"></i></span>';
}

/**
 * The lines of «خاڵە سەرەکییەکان»: what moved most, in plain words. Doctors
 * and staff whose first period is too small to say anything (under 5 a month)
 * are left out of «زۆرترین گەشە», so 1 → 6 is not called +500%.
 */
function dh_vc_insights(array $a, array $b, string $visitType): array
{
    $out = [];
    $c = dh_vc_change($a['per_month'], $b['per_month']);
    if ($c !== null) {
        $out[] = 'تێکڕای مانگانەی سەردان لە <b>' . dh_vc_num($a['per_month']) . '</b> بۆ <b>' . dh_vc_num($b['per_month']) . '</b> '
            . ($c >= 0 ? 'زیادی کردووە' : 'کەمی کردووە') . ' (' . ($c >= 0 ? '+' : '−') . dh_vc_num(abs($c)) . '%).';
    }
    foreach (['a' => $a, 'b' => $b] as $k => $p) {
        $best = null;
        foreach ($p['months'] as $ym => $mo) {
            if ($mo['gone'] === $mo['days'] && ($best === null || $mo['count'] > $p['months'][$best]['count'])) $best = $ym;
        }
        if ($best !== null && $p['months'][$best]['count'] > 0 && count($p['months']) > 1) {
            $out[] = 'باشترین مانگی ' . ($k === 'a' ? 'ماوەی یەکەم' : 'ماوەی دووەم') . ': <b>' . dh_vc_period_label($best, $best)
                . '</b> بە ' . number_format($p['months'][$best]['count']) . ' سەردان.';
        }
    }
    $newA = dh_vc_share($a['rows']['new'], $a['base']);
    $newB = dh_vc_share($b['rows']['new'], $b['base']);
    if ($newA !== null && $newB !== null) {
        $d = $newB - $newA;
        $out[] = 'ڕێژەی نەخۆشی نوێ' . ($visitType === 'both' ? ' (لە پشکنینەکان)' : '') . ' لە <b>' . dh_vc_num($newA) . '%</b> بۆ <b>' . dh_vc_num($newB) . '%</b> '
            . (abs($d) < 0.05 ? 'وەک خۆی ماوە.' : 'گۆڕاوە (' . ($d > 0 ? '+' : '−') . dh_vc_num(abs($d)) . ' خاڵ).');
    }
    $moves = [];
    foreach (array_unique(array_merge(array_keys($a['doctors']), array_keys($b['doctors']))) as $id) {
        $pa = dh_vc_pm($a, $a['doctors'][$id]['count'] ?? 0);
        $pb = dh_vc_pm($b, $b['doctors'][$id]['count'] ?? 0);
        if ($pa === null || $pb === null || $pa < 5 || ($a['doctors'][$id]['count'] ?? 0) < DH_VC_MIN_BASE || $id === 0) continue;
        $moves[$id] = ['name' => $a['doctors'][$id]['name'] ?? $b['doctors'][$id]['name'], 'c' => dh_vc_change($pa, $pb)];
    }
    if ($moves) {
        uasort($moves, fn($x, $y) => $y['c'] <=> $x['c']);
        $top = reset($moves);
        $low = end($moves);
        if ($top['c'] > 0) $out[] = 'زۆرترین گەشە: <b>' . htmlspecialchars($top['name']) . '</b> (+' . dh_vc_num($top['c']) . '% لە تێکڕای مانگانەدا).';
        if ($low['c'] < 0) $out[] = 'زۆرترین دابەزین: <b>' . htmlspecialchars($low['name']) . '</b> (−' . dh_vc_num(abs($low['c'])) . '%).';
    }
    return $out;
}
