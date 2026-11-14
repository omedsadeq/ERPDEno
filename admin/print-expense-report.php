<?php
/**
 * ڕاپۆرتی خەرجی — the report printed through the browser's own print dialog
 * (cleaner type than mPDF), with the page's filters, columns and order
 * (includes/expense-report-filter.php, includes/expense-report-layout.php):
 * the filters, each kind's total, every expense, then each kind by group.
 *
 * A long list runs over as many pages as it needs: the column heads repeat on
 * each page, a line never splits, and every page carries the report's name,
 * who printed it and «لاپەڕە n / m».
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../includes/expense-subgroup.php';
require_once __DIR__ . '/../includes/expense-report-filter.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || !Permission::getInstance()->can('page.expense_reports')) {
    die('Unauthorized access');
}
$currentUser = $auth->getCurrentUser();
require __DIR__ . '/../includes/clinic_branding.php';

// The report's own filters, as on the page
$f = exp_report_filters($_GET);
$labels = exp_report_labels($f);
$reportTitle = $f['type'] === 'all' ? 'ڕاپۆرتی خەرجییەکان' : EXP_REPORT_KINDS[$f['type']];

// oldest first unless the page sorted it; the columns as arranged on the page
$allExpenses = exp_report_list($f, false);
$cols = exp_report_visible_columns(exp_report_layout());
$keys = array_column($cols, 'key');
$totals = exp_report_totals($f);
$grandTotal = array_sum(array_column($totals, 'total'));
$listTotal = array_sum(array_map(fn($x) => (float)$x['amount'], $allExpenses));
$kindColor = ['general' => '#15803d', 'lab' => '#7e22ce', 'store' => '#c2410c'];
$kindSoft = ['general' => '#e8f7ee', 'lab' => '#f3e8ff', 'store' => '#fff1e6'];

// Each kind by group (with its sub-groups), for the kinds the filters keep
$byGroup = [];
foreach (array_keys(EXP_REPORT_KINDS) as $kind) {
    if ($f['type'] !== 'all' && $f['type'] !== $kind) {
        continue;
    }
    $stats = exp_report_stats($kind, $f);
    if ($stats) {
        $byGroup[$kind] = ['stats' => $stats, 'subs' => exp_report_subgroup_totals($kind, $f),
                           'total' => array_sum(array_map(fn($s) => (float)$s['total'], $stats))];
    }
}

// Wide columns in use turn the page landscape.
$weight = ['num' => .5, 'date' => 1, 'type' => 1, 'group' => 1.2, 'sub' => 1, 'desc' => 2.4, 'doctor' => 1.2, 'vendor' => 1.2, 'receipt' => .9,
           'patient' => 1.2, 'kind' => .9, 'method' => .9, 'notes' => 1.8, 'by' => 1, 'created' => 1.2, 'amount' => 1];
$landscape = array_sum(array_map(fn($k) => $weight[$k] ?? 1, $keys)) > 11;

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$iso = fn(string $d) => "\u{2066}" . $d . "\u{2069}";
$periodText = $f['from'] === $f['to'] ? $iso($f['from']) : $iso($f['from']) . ' بۆ ' . $iso($f['to']);
$printedBy = 'چاپکراوە لەلایەن ' . ($currentUser['full_name'] ?? '') . ' — ' . date('Y-m-d H:i');
$cssStr = fn(string $s) => '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $s) . '"';
$accent = Branding::primaryColor();
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($reportTitle . ' — ' . $f['from'] . ($f['from'] === $f['to'] ? '' : ' / ' . $f['to'])) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print-report.css">
<style>
@page {
    size: A4 <?= $landscape ? 'landscape' : 'portrait' ?>;
    margin: 8mm 8mm 11mm;
    @top-right { content: <?= $cssStr($reportTitle . ' · ' . $periodText) ?>; font-family: 'NRT', Tahoma, sans-serif; font-size: 7.5pt; color: #94a3b8; direction: rtl; }
    @bottom-right { content: <?= $cssStr($printedBy) ?>; font-family: 'NRT', Tahoma, sans-serif; font-size: 7pt; color: #64748b; direction: rtl; }
    @bottom-left { content: "لاپەڕە " counter(page) " / " counter(pages); font-family: 'NRT', Tahoma, sans-serif; font-size: 7.5pt; color: #64748b; }
}
/* the first page has the title itself */
@page :first { @top-right { content: none; } }

:root { --acc: <?= $e($accent) ?>; }
</style>
</head>
<body>

<div class="bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a href="<?= $e(APP_URL . '/admin/expense-reports.php?' . exp_report_query($f)) ?>">گەڕانەوە بۆ ڕاپۆرت</a>
    <span><?= $landscape ? 'A4 ئاسۆیی' : 'A4 ستوونی' ?></span>
</div>

<div class="sheet<?= $landscape ? ' land' : '' ?>">

    <div class="top">
        <div class="t"><?= $e($reportTitle) ?></div>
        <div class="d"><?php if ($f['from'] === $f['to']): ?><bdi><?= $e($f['from']) ?></bdi><?php else: ?><bdi><?= $e($f['from']) ?></bdi> بۆ <bdi><?= $e($f['to']) ?></bdi><?php endif; ?></div>
        <div class="x"><?= $e($clinicNameEn ?: $clinicName) ?></div>
    </div>
    <div class="filters">
        <span>جۆر: <b><?= $e($labels['type']) ?></b></span>
        <span>گرووپ: <b><?= $e($labels['group']) ?></b></span>
        <span>دکتۆر: <b><?= $e($labels['doctor']) ?></b></span>
        <span>ژمارەی تۆمار: <b><?= number_format(count($allExpenses)) ?></b></span>
    </div>

    <h2>لیستی خەرجییەکان <small><?= number_format(count($allExpenses)) ?> تۆمار · <?= number_format($listTotal) ?></small></h2>
    <?php if (!$allExpenses): ?>
    <div class="empty">هیچ خەرجییەک بەم فلتەرانە نییە</div>
    <?php else: ?>
    <table class="lt">
        <thead><tr><?php foreach ($cols as $c): ?><th<?= $c['key'] === 'amount' ? ' class="n"' : '' ?>><?= $e($c['label']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($allExpenses as $x): $i++; $kind = $x['expense_type']; ?>
        <tr>
            <?php foreach ($keys as $k): ?>
            <?php if ($k === 'num'): ?><td class="num"><?= $i ?></td>
            <?php elseif ($k === 'type'): ?><td><span class="tag" style="--c:<?= $kindColor[$kind] ?>;--s:<?= $kindSoft[$kind] ?>"><?= $e($x['type_label']) ?></span></td>
            <?php elseif ($k === 'group'): ?><td><?= $e(in_array('sub', $keys, true) ? ($x['category_name'] ?? '-') : expense_group_label($x)) ?></td>
            <?php elseif ($k === 'doctor'): ?><td><?php if (!empty($x['doctor_name'])): ?><?= $e($x['doctor_name']) ?><?php elseif (($x['dept'] ?? null) === 'xray'): ?><span class="who-x">X-ray</span><?php else: ?><span class="who-c">خەرجی سەنتەر</span><?php endif; ?></td>
            <?php elseif ($k === 'amount'): ?><td class="n amount"><?= number_format((float)$x['amount']) ?></td>
            <?php else: ?><td class="<?= $e($k) ?>"><?= $e((string)exp_report_value($k, $x)) ?></td>
            <?php endif; ?>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <!-- the total as the last row (a tfoot would repeat on every page) -->
        <tr class="tot">
            <td colspan="<?= max(1, count($keys) - 1) ?>">کۆی گشتی</td>
            <td class="n"><?= number_format($listTotal) ?></td>
        </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- the totals, as plain tables under the list -->
    <h3 class="ct-h">کۆی خەرجییەکان</h3>
    <table class="ct">
        <thead><tr><th>جۆر</th><th class="c">ژمارە</th><th class="n">کۆ</th></tr></thead>
        <tbody>
        <?php foreach (EXP_REPORT_KINDS as $kind => $label): ?>
        <tr><td><?= $e($label) ?></td><td class="c"><?= number_format($totals[$kind]['count']) ?></td><td class="n"><?= number_format($totals[$kind]['total']) ?></td></tr>
        <?php endforeach; ?>
        <tr class="tot"><td>کۆی گشتی</td><td class="c"><?= number_format(array_sum(array_column($totals, 'count'))) ?></td><td class="n"><?= number_format($grandTotal) ?></td></tr>
        </tbody>
    </table>

    <?php if ($byGroup): ?>
    <h3 class="ct-h">پوختە بەپێی گرووپ</h3>
    <table class="ct">
        <thead><tr><th>گرووپ</th><th class="c">ژمارە</th><th class="c">ڕێژە</th><th class="n">کۆ</th></tr></thead>
        <tbody>
        <?php foreach ($byGroup as $kind => $g): ?>
        <?php if (count($byGroup) > 1): ?>
        <tr class="kh"><td><?= $e(EXP_REPORT_KINDS[$kind]) ?></td><td class="c"><?= number_format(array_sum(array_map(fn($s) => (int)$s['count'], $g['stats']))) ?></td><td class="c"></td><td class="n"><?= number_format($g['total']) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($g['stats'] as $s): ?>
        <tr>
            <td><?= $e($s['category_name'] ?? 'بێ گرووپ') ?></td>
            <td class="c"><?= (int)$s['count'] ?></td>
            <td class="c"><?= $g['total'] > 0 ? round((float)$s['total'] / $g['total'] * 100, 1) : 0 ?>%</td>
            <td class="n"><?= number_format((float)$s['total']) ?></td>
        </tr>
        <?php foreach ($g['subs'][(int)$s['category_id']] ?? [] as $part): ?>
        <tr class="sub"><td><?= $e(expense_subgroup_name($part)) ?></td><td class="c"><?= (int)$part['count'] ?></td><td class="c"></td><td class="n"><?= number_format((float)$part['total']) ?></td></tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>
<?php if (($_GET['autoprint'] ?? '') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
</body>
</html>
