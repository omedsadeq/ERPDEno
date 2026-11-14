<?php
/**
 * بەراورد tab of visits-report.php: two periods of the visit statistics side
 * by side (includes/VisitReportCompare.php). Expects $db, $visitType, $visitTypeLabel.
 */
require_once __DIR__ . '/VisitReportCompare.php';

$vcToday = date('Y-m-d');
$vc = dh_vc_input($_GET, $vcToday);
$A = dh_vc_period($db, $vc['a_from'], $vc['a_to'], $visitType, $vcToday);
$B = dh_vc_period($db, $vc['b_from'], $vc['b_to'], $visitType, $vcToday);
$vcE = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$vcBase = ['tab' => 'compare', 'visit_type' => $visitType,
           'a_from' => $vc['a_from'], 'a_to' => $vc['a_to'], 'b_from' => $vc['b_from'], 'b_to' => $vc['b_to']];
$vcUrl = fn(array $over) => '?' . http_build_query(array_merge($vcBase, $over));
$monthsTxt = fn(array $p) => dh_vc_num($p['months_gone'], 2) . ' مانگ';
$isBoth = $visitType === 'both';

// what the reader must know before trusting the comparison
$notes = [];
$unequal = abs($A['months_gone'] - $B['months_gone']) > 0.01;
if ($unequal) {
    $notes[] = ['info', 'درێژی دوو ماوەکە یەکسان نییە (' . $monthsTxt($A) . ' بەرامبەر ' . $monthsTxt($B) . ')، بۆیە ڕێژەی گۆڕان لەسەر <b>تێکڕای مانگانە</b> حساب کراوە نەک لەسەر کۆی گشتی.'];
}
foreach (['A' => $A, 'B' => $B] as $k => $p) {
    $last = end($p['months']);
    if ($last['gone'] > 0 && $last['gone'] < $last['days']) {
        $notes[] = ['info', dh_vc_period_label(array_key_last($p['months']), array_key_last($p['months'])) . ' هێشتا تەواو نەبووە (' . $last['gone'] . ' ڕۆژ لە ' . $last['days']
            . ')؛ تەنها ڕۆژە تێپەڕیوەکانی هەژمار کراون، بۆیە تێکڕاکان دادپەروەرانەن بەڵام کۆی گشتییەکەی کەمترە.'];
    }
    $future = array_filter($p['months'], fn($m) => $m['gone'] === 0);
    if ($future) {
        $notes[] = ['warn', ($k === 'A' ? 'ماوەی یەکەم' : 'ماوەی دووەم') . ' ' . count($future) . ' مانگی داهاتووی تێدایە کە هێشتا نەهاتووە؛ لە تێکڕادا هەژمار ناکرێن.'];
    }
    if ($p['days_gone'] > 0 && (!$p['last_visit'] || (strtotime($p['last_gone']) - strtotime($p['last_visit'])) / 86400 > 3)) {
        $notes[] = ['warn', ($k === 'A' ? 'ماوەی یەکەم' : 'ماوەی دووەم') . ': ' . ($p['last_visit'] ? 'دوایین سەردانی تۆمارکراو لە <b dir="ltr">' . $vcE($p['last_visit']) . '</b>ـە' : 'هیچ سەردانێک تۆمار نەکراوە')
            . '، بەڵام ماوەکە تا <b dir="ltr">' . $vcE($p['last_gone']) . '</b> هەژمار دەکرێت؛ ئەگەر سەردانەکان هێشتا تۆمار نەکراون تێکڕاکان کەمتر دەردەکەون.'];
    }
    foreach ($p['no_type'] as $t => $list) {
        if (!$list || count($list) === count($p['no_data'])) continue;
        $notes[] = ['warn', ($k === 'A' ? 'ماوەی یەکەم' : 'ماوەی دووەم') . ': لە ' . implode('، ', array_map(fn($m) => dh_vc_period_label($m, $m), $list))
            . ' هیچ سەردانێکی <b>' . ($t === 'treatment' ? 'ئیش' : 'پشکنین') . '</b> تۆمار نەکراوە. ئەگەر ئەو کاتە ' . ($t === 'treatment' ? 'ئیش' : 'پشکنین')
            . ' لە سیستەمدا تۆمار نەدەکرا، بەراوردی «هەردووکیان» ڕاست نییە؛ «' . ($t === 'treatment' ? 'پشکنین' : 'ئیش') . '» بە تەنها هەڵبژێرە.'];
    }
    if ($p['no_data']) {
        $notes[] = ['warn', 'لە ' . implode('، ', array_map(fn($m) => dh_vc_period_label($m, $m), $p['no_data'])) . ' هیچ سەردانێکی «' . $vcE($visitTypeLabel)
            . '» تۆمار نەکراوە، هەرچەندە سەردانی تر هەیە. ئەگەر ئەو کاتە ئەم جۆرە لە سیستەمدا تۆمار نەدەکرا، بەراوردی ئەو مانگانە ڕاست نییە.'];
    }
}
if ($A['from'] <= $B['to'] && $B['from'] <= $A['to']) {
    $notes[] = ['warn', 'دوو ماوەکە بەسەر یەکدا دەکەون؛ هەندێک سەردان لە هەردووکیاندا هەژمار دەکرێن.'];
}

// the month-by-month chart: one scale for both periods
$chartMax = 1;
foreach ([$A, $B] as $p) {
    foreach ($p['months'] as $m) $chartMax = max($chartMax, $m['count']);
    if ($p['per_month'] !== null) $chartMax = max($chartMax, $p['per_month']);
}
$chartMax = $chartMax * 1.12;
$pctH = fn($v) => round(max(0, (float) $v) / $chartMax * 100, 2);

// the matrix's rows, as in ئاماری سەردانەکان
$rowDefs = [];
if ($isBoth) {
    $rowDefs['treatment'] = ['ئیش', $A['split']['treatment'], $B['split']['treatment'], 'total'];
    $rowDefs['checkup'] = ['پشکنین', $A['split']['checkup'], $B['split']['checkup'], 'total'];
}
$rowDefs['requested'] = ['داواکراو', $A['rows']['requested'], $B['rows']['requested'], 'base'];
$rowDefs['reception'] = ['گواستنەوەی پێشوازی', $A['rows']['reception'], $B['rows']['reception'], 'base'];
if ($A['rows']['other'] + $B['rows']['other'] > 0) $rowDefs['other'] = ['دیاریکردنی تر', $A['rows']['other'], $B['rows']['other'], 'base'];
if ($A['rows']['unassigned'] + $B['rows']['unassigned'] > 0) $rowDefs['unassigned'] = ['دکتۆر دیاری نەکراوە', $A['rows']['unassigned'], $B['rows']['unassigned'], 'base'];
$rowDefs['new'] = ['نەخۆشی نوێ', $A['rows']['new'], $B['rows']['new'], 'base'];
$rowDefs['old'] = ['نەخۆشی کۆن', $A['rows']['old'], $B['rows']['old'], 'base'];
if ($A['rows']['unknown'] + $B['rows']['unknown'] > 0) $rowDefs['unknown'] = ['دۆخی نەخۆش نادیارە', $A['rows']['unknown'], $B['rows']['unknown'], 'base'];

// doctors and staff: everyone in either period, the bigger in the second period first
$joinRows = function (array $a, array $b) use ($A, $B) {
    $out = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $id) {
        $ca = $a[$id]['count'] ?? 0;
        $cb = $b[$id]['count'] ?? 0;
        $out[] = ['id' => $id, 'name' => $a[$id]['name'] ?? $b[$id]['name'], 'a' => $ca, 'b' => $cb,
                  'pa' => dh_vc_pm($A, $ca), 'pb' => dh_vc_pm($B, $cb)];
    }
    usort($out, fn($x, $y) => [$y['pb'] ?? 0, $y['pa'] ?? 0] <=> [$x['pb'] ?? 0, $x['pa'] ?? 0]);
    return $out;
};
$docRows = $joinRows($A['doctors'], $B['doctors']);
$staffRows = $joinRows($A['staff'], $B['staff']);
$staffA = array_sum(array_column($A['staff'], 'count'));
$staffB = array_sum(array_column($B['staff'], 'count'));
$insights = dh_vc_insights($A, $B, $visitType);

// each kind of visit in both periods, so the choice above shows where each went;
// and in how many of the months the clinic had visits it was recorded at all
$typeCounts = ['checkup' => ['a' => 0, 'b' => 0, 'ma' => 0, 'mb' => 0], 'treatment' => ['a' => 0, 'b' => 0, 'ma' => 0, 'mb' => 0]];
foreach ($db->fetchAll(
    "SELECT visit_type AS t,
            SUM(visit_date BETWEEN ? AND ?) AS a,
            SUM(visit_date BETWEEN ? AND ?) AS b,
            COUNT(DISTINCT CASE WHEN visit_date BETWEEN ? AND ? THEN DATE_FORMAT(visit_date, '%Y-%m') END) AS ma,
            COUNT(DISTINCT CASE WHEN visit_date BETWEEN ? AND ? THEN DATE_FORMAT(visit_date, '%Y-%m') END) AS mb
     FROM visits
     WHERE visit_type IN ('checkup', 'treatment')
       AND (visit_date BETWEEN ? AND ? OR visit_date BETWEEN ? AND ?)
     GROUP BY visit_type",
    [$A['from'], $A['to'], $B['from'], $B['to'], $A['from'], $A['to'], $B['from'], $B['to'],
     $A['from'], $A['to'], $B['from'], $B['to']]) as $r) {
    $typeCounts[$r['t']] = ['a' => (int) $r['a'], 'b' => (int) $r['b'], 'ma' => (int) $r['ma'], 'mb' => (int) $r['mb']];
}
$openMonths = fn(array $p) => count(array_filter($p['months'], fn($m) => $m['all'] > 0));
foreach (['checkup', 'treatment'] as $t) {
    $typeCounts[$t]['gaps'] = $typeCounts[$t]['ma'] < $openMonths($A) || $typeCounts[$t]['mb'] < $openMonths($B);
}
$typeCounts['both'] = ['a' => $typeCounts['checkup']['a'] + $typeCounts['treatment']['a'],
                       'b' => $typeCounts['checkup']['b'] + $typeCounts['treatment']['b'],
                       'gaps' => $typeCounts['checkup']['gaps'] || $typeCounts['treatment']['gaps']];
$typeTabs = ['checkup' => ['پشکنین', 'fa-stethoscope'], 'treatment' => ['ئیش', 'fa-tooth'], 'both' => ['هەردووکیان', 'fa-users']];

$newA = dh_vc_share($A['rows']['new'], $A['base']);
$newB = dh_vc_share($B['rows']['new'], $B['base']);
$kpis = [
    ['کۆی سەردان', 'fa-users', number_format($A['total']), number_format($B['total']),
        $unequal ? '<span class="vc-badge vc-flat" title="ماوەکان یەکسان نین">جیاوازی <bdi dir="ltr">' . ($B['total'] - $A['total'] >= 0 ? '+' : '−') . number_format(abs($B['total'] - $A['total'])) . '</bdi></span>' : dh_vc_badge((float) $A['total'], (float) $B['total']),
        $unequal ? 'ماوەکان یەکسان نین؛ بڕوانە تێکڕاکان' : 'لە هەموو ماوەکەدا'],
    ['تێکڕای مانگانە', 'fa-calendar-alt', dh_vc_num($A['per_month']), dh_vc_num($B['per_month']),
        dh_vc_badge($A['per_month'], $B['per_month']), 'کۆ ÷ مانگە تێپەڕیوەکان'],
    ['تێکڕای ڕۆژی کارکردن', 'fa-calendar-day', dh_vc_num($A['per_day']), dh_vc_num($B['per_day']),
        dh_vc_badge($A['per_day'], $B['per_day']), $A['open_days'] . ' ڕۆژ بەرامبەر ' . $B['open_days'] . ' ڕۆژی کراوە'],
    ['نەخۆشی نوێ' . ($isBoth ? ' (پشکنین)' : ''), 'fa-user-plus', number_format($A['rows']['new']), number_format($B['rows']['new']),
        dh_vc_badge(dh_vc_pm($A, $A['rows']['new']), dh_vc_pm($B, $B['rows']['new'])),
        'ڕێژە: ' . dh_vc_num($newA) . '% → ' . dh_vc_num($newB) . '%'],
];
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/visit-report-compare.css?v=20261114a">

<form method="GET" class="vc-picker" id="vcPicker">
    <input type="hidden" name="tab" value="compare">
    <input type="hidden" name="visit_type" value="<?= $vcE($visitType) ?>">
    <div class="vc-types" role="group" aria-label="جۆری سەردان بۆ بەراورد">
        <span class="vc-types-label">بەراوردی</span>
        <?php foreach ($typeTabs as $t => [$lbl, $ic]): $tc = $typeCounts[$t]; ?>
        <a class="vc-type vc-type-<?= $t ?><?= $visitType === $t ? ' is-on' : '' ?>" href="<?= $vcE($vcUrl(['visit_type' => $t])) ?>"<?= $visitType === $t ? ' aria-current="true"' : '' ?>>
            <span class="vc-type-name"><i class="fas <?= $ic ?>"></i> <?= $lbl ?></span>
            <span class="vc-type-nums"><bdi dir="ltr"><?= number_format($tc['a']) ?> → <?= number_format($tc['b']) ?></bdi>
                <?php if ($tc['gaps']): ?><span class="vc-badge vc-warn" title="لە هەندێک مانگی ئەم ماوانەدا ئەم جۆرە تۆمار نەکراوە، بۆیە ڕێژەی گۆڕان ڕاست نییە"><i class="fas fa-exclamation-triangle"></i> تۆماری ناتەواو</span>
                <?php elseif ($tc['a'] < DH_VC_MIN_BASE && $tc['b'] > 0): ?><span class="vc-badge vc-flat">ژمارە کەمە</span>
                <?php else: ?><?= dh_vc_badge(dh_vc_pm($A, $tc['a']), dh_vc_pm($B, $tc['b'])) ?><?php endif; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="vc-presets">
        <span class="vc-presets-label"><i class="fas fa-bolt"></i> خێرا:</span>
        <?php foreach ($vc['presets'] as $key => $p): ?>
        <a class="vr-chip <?= $vc['preset'] === $key ? 'active' : '' ?>" href="<?= $vcE($vcUrl(['a_from' => $p['a'][0], 'a_to' => $p['a'][1], 'b_from' => $p['b'][0], 'b_to' => $p['b'][1]])) ?>"><?= $vcE($p['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="vc-ranges">
        <fieldset class="vc-range vc-range-a">
            <legend><span class="vc-dot"></span> ماوەی یەکەم</legend>
            <label>لە <input type="month" name="a_from" value="<?= $vcE($vc['a_from']) ?>" required></label>
            <label>بۆ <input type="month" name="a_to" value="<?= $vcE($vc['a_to']) ?>" required></label>
        </fieldset>
        <span class="vc-vs">بەرامبەر</span>
        <fieldset class="vc-range vc-range-b">
            <legend><span class="vc-dot"></span> ماوەی دووەم</legend>
            <label>لە <input type="month" name="b_from" value="<?= $vcE($vc['b_from']) ?>" required></label>
            <label>بۆ <input type="month" name="b_to" value="<?= $vcE($vc['b_to']) ?>" required></label>
        </fieldset>
        <button type="submit" class="vr-apply"><i class="fas fa-balance-scale"></i> بەراوردکردن</button>
    </div>
</form>

<?php if ($notes): ?>
<div class="vc-notes">
    <?php foreach ($notes as [$kind, $html]): ?>
    <div class="vc-note vc-note-<?= $kind ?>"><i class="fas <?= $kind === 'warn' ? 'fa-exclamation-triangle' : 'fa-info-circle' ?>"></i><span><?= $html ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="vc-sheet" aria-label="بەراوردی سەردانەکان">
    <div class="vc-periods">
        <?php foreach (['a' => $A, 'b' => $B] as $k => $p): ?>
        <div class="vc-period vc-period-<?= $k ?>">
            <div class="vc-period-tag"><?= $k === 'a' ? 'ماوەی یەکەم' : 'ماوەی دووەم' ?></div>
            <div class="vc-period-name"><?= $vcE($p['label']) ?></div>
            <div class="vc-period-meta">
                <span dir="ltr"><?= $vcE($p['from']) ?> → <?= $vcE($p['to']) ?></span>
                <span><?= $monthsTxt($p) ?></span>
                <span><?= $p['open_days'] ?> ڕۆژی کارکردن</span>
            </div>
            <div class="vc-period-total"><?= number_format($p['total']) ?> <small>سەردانی <?= $vcE($visitTypeLabel) ?></small></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="vc-kpis">
        <?php foreach ($kpis as [$label, $icon, $va, $vb, $badge, $sub]): ?>
        <div class="vc-kpi">
            <div class="vc-kpi-head"><i class="fas <?= $icon ?>"></i> <?= $label ?></div>
            <div class="vc-kpi-vals">
                <div class="vc-kpi-v vc-a"><small>یەکەم</small><b><?= $va ?></b></div>
                <div class="vc-kpi-arrow"><i class="fas fa-arrow-left"></i></div>
                <div class="vc-kpi-v vc-b"><small>دووەم</small><b><?= $vb ?></b></div>
            </div>
            <div class="vc-kpi-foot"><?= $badge ?><span><?= $vcE($sub) ?></span></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($insights): ?>
    <div class="vc-insights">
        <div class="vc-h"><i class="fas fa-lightbulb"></i> خاڵە سەرەکییەکان</div>
        <ul><?php foreach ($insights as $line): ?><li><?= $line ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <div class="vc-card">
        <div class="vc-h">
            <span><i class="fas fa-chart-column"></i> مانگ بە مانگ</span>
            <span class="vc-legend">
                <span><i class="vc-sw vc-sw-a"></i> <?= $vcE($A['label']) ?></span>
                <span><i class="vc-sw vc-sw-b"></i> <?= $vcE($B['label']) ?></span>
                <span><i class="vc-sw vc-sw-avg"></i> تێکڕای مانگانە</span>
                <span><i class="vc-sw vc-sw-part"></i> مانگی تەواو نەبوو</span>
            </span>
        </div>
        <div class="vc-chart" role="img" aria-label="ژمارەی سەردان مانگ بە مانگ بۆ هەردوو ماوەکە">
            <?php foreach (['a' => $A, 'b' => $B] as $k => $p): ?>
            <div class="vc-group vc-group-<?= $k ?>" style="flex-grow: <?= count($p['months']) ?>;">
                <div class="vc-group-cap"><i class="vc-sw vc-sw-<?= $k ?>"></i> <?= $k === 'a' ? 'ماوەی یەکەم' : 'ماوەی دووەم' ?>
                    <?php if ($p['per_month'] !== null): ?><span>تێکڕای مانگانە <b><?= dh_vc_num($p['per_month']) ?></b></span><?php endif; ?></div>
                <div class="vc-plot">
                    <?php if ($p['per_month'] !== null): ?>
                    <div class="vc-avg" style="bottom: <?= $pctH($p['per_month']) ?>%;" title="تێکڕای مانگانە: <?= dh_vc_num($p['per_month']) ?>"></div>
                    <?php endif; ?>
                    <?php foreach ($p['months'] as $ym => $m):
                        $partial = $m['gone'] > 0 && $m['gone'] < $m['days'];
                        $future = $m['gone'] === 0;
                        $tip = dh_vc_period_label($ym, $ym) . ': ' . number_format($m['count']) . ' سەردان'
                            . ($m['open_days'] ? ' · ' . $m['open_days'] . ' ڕۆژی کراوە' : '')
                            . ($partial ? ' · تا ئێستا (' . $m['gone'] . ' ڕۆژ لە ' . $m['days'] . ')' : '')
                            . ($future ? ' · هێشتا نەهاتووە' : '');
                    ?>
                    <div class="vc-col<?= $partial ? ' is-partial' : '' ?><?= $future ? ' is-future' : '' ?>" data-tip="<?= $vcE($tip) ?>" tabindex="0">
                        <div class="vc-bar" style="height: <?= $pctH($m['count']) ?>%;">
                            <span class="vc-val"><?= $m['count'] ? number_format($m['count']) : '' ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="vc-xaxis">
                    <?php foreach ($p['months'] as $ym => $m): ?>
                    <span><?= (int) substr($ym, 5) ?><?= ($ym === array_key_first($p['months']) || substr($ym, 5) === '01') ? '<small>' . substr($ym, 0, 4) . '</small>' : '' ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="vc-card">
        <div class="vc-h"><span><i class="fas fa-table"></i> ڕیزەکانی ئاماری سەردان</span>
            <span class="vc-hint"><?= $isBoth ? 'ڕیزەکانی داواکراو و نوێ/کۆن تەنها پشکنینەکان دابەش دەکەن، وەک ئاماری سەردانەکان' : 'ڕێژە: بەشی ڕیزەکە لە کۆی سەردانی ماوەکە. ئەگەر ماوەی یەکەم کەمتر لە ' . DH_VC_MIN_BASE . ' سەردانی هەبێت، ژمارەکان پیشان دەدرێن نەک ڕێژەی سەدی' ?></span></div>
        <div class="vc-scroll">
        <table class="vc-table">
            <thead>
                <tr><th rowspan="2" class="vc-name">بابەت</th><th colspan="2" class="vc-th-a">ماوەی یەکەم</th><th colspan="2" class="vc-th-b">ماوەی دووەم</th><th colspan="2">تێکڕای مانگانە</th><th rowspan="2">گۆڕان</th><th rowspan="2">گۆڕانی ڕێژە</th></tr>
                <tr><th class="vc-th-a">ژمارە</th><th class="vc-th-a">ڕێژە</th><th class="vc-th-b">ژمارە</th><th class="vc-th-b">ڕێژە</th><th>یەکەم</th><th>دووەم</th></tr>
            </thead>
            <tbody>
                <tr class="vc-total-row">
                    <th class="vc-name">کۆی سەردان<?= $isBoth ? ' (ئیش + پشکنین)' : '' ?></th>
                    <td><?= number_format($A['total']) ?></td><td class="vc-muted">100%</td>
                    <td><?= number_format($B['total']) ?></td><td class="vc-muted">100%</td>
                    <td><?= dh_vc_num($A['per_month']) ?></td><td><?= dh_vc_num($B['per_month']) ?></td>
                    <td><?= dh_vc_badge($A['per_month'], $B['per_month']) ?></td><td></td>
                </tr>
                <?php foreach ($rowDefs as $key => [$label, $ca, $cb, $of]):
                    $sa = dh_vc_share($ca, $of === 'total' ? $A['total'] : $A['base']);
                    $sb = dh_vc_share($cb, $of === 'total' ? $B['total'] : $B['base']);
                    if ($isBoth && $key === 'requested'): ?>
                <tr class="vc-sub-head"><th colspan="9">پشکنینەکان (<?= number_format($A['base']) ?> بەرامبەر <?= number_format($B['base']) ?>)</th></tr>
                <?php endif; ?>
                <tr>
                    <th class="vc-name"><?= $label ?></th>
                    <td><?= number_format($ca) ?></td><td class="vc-muted"><?= dh_vc_num($sa) ?>%</td>
                    <td><?= number_format($cb) ?></td><td class="vc-muted"><?= dh_vc_num($sb) ?>%</td>
                    <td><?= dh_vc_num(dh_vc_pm($A, $ca)) ?></td><td><?= dh_vc_num(dh_vc_pm($B, $cb)) ?></td>
                    <td><?= dh_vc_count_badge($ca, $cb, dh_vc_pm($A, $ca), dh_vc_pm($B, $cb)) ?></td>
                    <td><?= dh_vc_badge($sa, $sb, true) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php foreach ([
        ['دکتۆرەکان', 'fa-user-md', 'دکتۆر', $docRows, $A['total'], $B['total'], 'هەر سەردانێک بۆ دکتۆری بینراو، وەک ئاماری سەردانەکان'],
        ['کارمەندانی پێشوازی — سەردانی تۆمارکراو', 'fa-user-tie', 'کارمەند', $staffRows, $staffA, $staffB, 'ئەو سەردانانەی هەر کارمەندێک تۆماری کردوون'],
    ] as [$title, $icon, $col, $list, $sumA, $sumB, $hint]): if (!$list) continue; ?>
    <div class="vc-card">
        <div class="vc-h"><span><i class="fas <?= $icon ?>"></i> <?= $title ?> (<?= count($list) ?>)</span><span class="vc-hint"><?= $hint ?></span></div>
        <div class="vc-scroll">
        <table class="vc-table">
            <thead>
                <tr><th rowspan="2" class="vc-name"><?= $col ?></th><th colspan="2" class="vc-th-a">ماوەی یەکەم</th><th colspan="2" class="vc-th-b">ماوەی دووەم</th><th colspan="2">تێکڕای مانگانە</th><th rowspan="2" colspan="2">گۆڕانی تێکڕای مانگانە</th></tr>
                <tr><th class="vc-th-a">ژمارە</th><th class="vc-th-a">بەش</th><th class="vc-th-b">ژمارە</th><th class="vc-th-b">بەش</th><th>یەکەم</th><th>دووەم</th></tr>
            </thead>
            <tbody>
                <?php foreach ($list as $r): ?>
                <tr>
                    <th class="vc-name"><?= $vcE($r['name']) ?></th>
                    <td><?= number_format($r['a']) ?></td><td class="vc-muted"><?= dh_vc_num(dh_vc_share($r['a'], $sumA)) ?>%</td>
                    <td><?= number_format($r['b']) ?></td><td class="vc-muted"><?= dh_vc_num(dh_vc_share($r['b'], $sumB)) ?>%</td>
                    <td><?= dh_vc_num($r['pa']) ?></td><td><?= dh_vc_num($r['pb']) ?></td>
                    <td class="vc-cbar-cell"><?= $r['a'] >= DH_VC_MIN_BASE ? dh_vc_change_bar($r['pa'], $r['pb']) : '<span class="vc-cbar"></span>' ?></td>
                    <td><?= dh_vc_count_badge($r['a'], $r['b'], $r['pa'], $r['pb']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="vc-total-row">
                    <th class="vc-name">کۆی گشتی</th>
                    <td><?= number_format($sumA) ?></td><td class="vc-muted">100%</td>
                    <td><?= number_format($sumB) ?></td><td class="vc-muted">100%</td>
                    <td><?= dh_vc_num(dh_vc_pm($A, $sumA)) ?></td><td><?= dh_vc_num(dh_vc_pm($B, $sumB)) ?></td>
                    <td class="vc-cbar-cell"><?= dh_vc_change_bar(dh_vc_pm($A, $sumA), dh_vc_pm($B, $sumB)) ?></td>
                    <td><?= dh_vc_badge(dh_vc_pm($A, $sumA), dh_vc_pm($B, $sumB)) ?></td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="vc-method">
        <b>چۆن حساب کراوە:</b>
        ژمارەکان هەمان ژمارەکانی ئاماری سەردانەکانن (بەپێی بەرواری سەردان). <b>تێکڕای مانگانە</b> = کۆ ÷ ژمارەی مانگە تێپەڕیوەکان؛ مانگی تەواو نەبوو بەپێی ڕۆژە تێپەڕیوەکانی هەژمار دەکرێت (بۆ نموونە 14 ڕۆژ لە 30 = 0.47 مانگ).
        <b>تێکڕای ڕۆژی کارکردن</b> = کۆ ÷ ئەو ڕۆژانەی سەنتەر سەردانی تێدا بووە. <b>گۆڕان</b> = (دووەم − یەکەم) ÷ یەکەم، لەسەر تێکڕای مانگانە، بۆیە دوو ماوەی جیاواز لە درێژیدا دادپەروەرانە بەراورد دەکرێن.
        <b>گۆڕانی ڕێژە</b> بە خاڵی سەدییە (70% → 65% = −5 خاڵ).
    </div>
</section>
