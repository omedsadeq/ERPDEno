<?php
/**
 * خەرجی گشتی / تاقیگە / کۆگا — one kind's list, printed through the browser's
 * own print dialog (cleaner type than mPDF), with the filters of the page it
 * was opened from (admin/expenses-general.php, -lab.php, -inventory.php).
 *
 * A long list runs over as many pages as it needs: the column heads repeat on
 * each page, a line never splits, and every page carries the report's name,
 * who printed it and «لاپەڕە n / m». Shares assets/css/print-report.css with
 * admin/print-expense-report.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../includes/expense-subgroup.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn() || !Permission::getInstance()->can('page.expense_reports')) {
    die('Unauthorized access');
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Get parameters
$type = $_GET['type'] ?? 'general';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$categoryId = $_GET['category'] ?? '';

// Type labels
$typeLabels = [
    'general' => 'خەرجی گشتی',
    'lab' => 'خەرجی تاقیگە',
    'inventory' => 'خەرجی کۆگا'
];

$typeIcons = [
    'general' => '📄',
    'lab' => '🧪',
    'inventory' => '📦'
];

$reportTitle = $typeLabels[$type] ?? 'ڕاپۆرتی خەرجییەکان';
$reportIcon = $typeIcons[$type] ?? '📊';

// Determine table names based on type
$expenseTable = 'expenses_general';
$categoryTable = 'expense_categories_general';

if ($type === 'lab') {
    $expenseTable = 'expenses_lab';
    $categoryTable = 'expense_categories_lab';
} elseif ($type === 'inventory' || $type === 'store') {
    $expenseTable = 'expenses_store';
    $categoryTable = 'expense_categories_store';
    $type = 'inventory'; // Normalize to inventory for labels
}

// Get expenses
$sql = "SELECT e.*, u.full_name as created_by_name, ec.category_name, s.name AS subgroup_name";

if ($type === 'lab') {
    $sql .= ", p.full_name as patient_name, p.patient_code";
}

$sql .= " FROM {$expenseTable} e
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN {$categoryTable} ec ON e.category_id = ec.id
        LEFT JOIN expense_subgroups s ON s.id = e.subgroup_id";

if ($type === 'lab') {
    $sql .= " LEFT JOIN patients p ON e.patient_id = p.id";
}

$sql .= " WHERE e.expense_date BETWEEN :from AND :to";

$params = ['from' => $dateFrom, 'to' => $dateTo];

if ($categoryId) {
    $sql .= " AND e.category_id = :category";
    $params['category'] = $categoryId;
}

$subgroupId = (int)($_GET['subgroup'] ?? 0);
if ($subgroupId) {
    $sql .= " AND e.subgroup_id = :subgroup";
    $params['subgroup'] = $subgroupId;
    $subgroupRow = $db->fetch("SELECT name FROM expense_subgroups WHERE id = :id", ['id' => $subgroupId]);
    if ($subgroupRow) {
        $reportTitle .= ' — ' . $subgroupRow['name'];
    }
}

// خەرجی کۆگا only: ئاسایی / ئیمپلانت and the doctor, as filtered on screen.
$storeKind = '';
if ($type === 'inventory') {
    $storeKind = in_array($_GET['kind'] ?? '', ['normal', 'implant'], true) ? $_GET['kind'] : '';
    if ($storeKind !== '') {
        $sql .= " AND e.expense_kind = :kind";
        $params['kind'] = $storeKind;
        $reportTitle .= ' — ' . ($storeKind === 'implant' ? 'ئیمپلانت' : 'ئاسایی');
    }
    if ((int)($_GET['doctor'] ?? 0) > 0) {
        $sql .= " AND e.doctor_id = :doctor";
        $params['doctor'] = (int)$_GET['doctor'];
        $docRow = $db->fetch("SELECT full_name FROM users WHERE id = :id", ['id' => (int)$_GET['doctor']]);
        if ($docRow) {
            $reportTitle .= ' — ' . $docRow['full_name'];
        }
    }
}

$sql .= " ORDER BY e.expense_date ASC, e.id ASC";
$expenses = $db->fetchAll($sql, $params);

// Statistics by category, from the rows actually printed — so the summary
// answers the same filter (category, kind, doctor) as the list above it.
$statsMap = [];
foreach ($expenses as $row) {
    $name = $row['category_name']
        ?? ((($row['source_type'] ?? '') === 'erp_doctor_issue') ? 'مەواد بۆ دکتۆر' : null);
    $key = $name ?? '';
    if (!isset($statsMap[$key])) {
        $statsMap[$key] = ['category_name' => $name, 'total' => 0.0, 'count' => 0, 'subs' => []];
    }
    $statsMap[$key]['total'] += (float)$row['amount'];
    $statsMap[$key]['count']++;
    $subName = expense_subgroup_name(['name' => $row['subgroup_name']]);
    $statsMap[$key]['subs'][$subName] = ($statsMap[$key]['subs'][$subName] ?? 0) + (float)$row['amount'];
}
$stats = array_values($statsMap);
usort($stats, fn($a, $b) => $b['total'] <=> $a['total']);

$totalExpenses = array_sum(array_column($expenses, 'amount'));
$totalCount = count($expenses);

// Print-page bits
require __DIR__ . '/../includes/clinic_branding.php';
$kindColor = ['general' => '#15803d', 'lab' => '#7e22ce', 'inventory' => '#c2410c'][$type] ?? '#0369a1';
$isLab = $type === 'lab';
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$iso = fn(string $d) => "\u{2066}" . $d . "\u{2069}";
$periodText = $dateFrom === $dateTo ? $iso($dateFrom) : $iso($dateFrom) . ' بۆ ' . $iso($dateTo);
$printedBy = 'چاپکراوە لەلایەن ' . ($currentUser['full_name'] ?? '') . ' — ' . date('Y-m-d H:i');
$cssStr = fn(string $s) => '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $s) . '"';
$colCount = $isLab ? 7 : 6;
$backPage = ['general' => 'expenses-general', 'lab' => 'expenses-lab', 'inventory' => 'expenses-inventory'][$type] ?? 'expenses-general';
$backQs = $_GET;
unset($backQs['type'], $backQs['autoprint']);
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($reportTitle . ' — ' . $dateFrom . ($dateFrom === $dateTo ? '' : ' / ' . $dateTo)) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print-report.css">
<style>
@page {
    size: A4 portrait;
    margin: 8mm 8mm 11mm;
    @top-right { content: <?= $cssStr($reportTitle . ' · ' . $periodText) ?>; font-family: 'NRT', Tahoma, sans-serif; font-size: 7.5pt; color: #94a3b8; direction: rtl; }
    @bottom-right { content: <?= $cssStr($printedBy) ?>; font-family: 'NRT', Tahoma, sans-serif; font-size: 7pt; color: #64748b; direction: rtl; }
    @bottom-left { content: "لاپەڕە " counter(page) " / " counter(pages); font-family: 'NRT', Tahoma, sans-serif; font-size: 7.5pt; color: #64748b; }
}
/* the first page has the title itself */
@page :first { @top-right { content: none; } }
:root { --acc: <?= $e($kindColor) ?>; }
.lt td.desc { min-width: 40mm; }
</style>
</head>
<body>

<div class="bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a href="<?= $e(APP_URL . '/admin/' . $backPage . '.php' . ($backQs ? '?' . http_build_query($backQs) : '')) ?>">گەڕانەوە</a>
</div>

<div class="sheet">

    <div class="top">
        <div class="t"><?= $e($reportTitle) ?></div>
        <div class="d"><?php if ($dateFrom === $dateTo): ?><bdi><?= $e($dateFrom) ?></bdi><?php else: ?><bdi><?= $e($dateFrom) ?></bdi> بۆ <bdi><?= $e($dateTo) ?></bdi><?php endif; ?></div>
        <div class="x"><?= $e($clinicNameEn ?: $clinicName) ?></div>
    </div>

    <h2>لیستی خەرجییەکان <small><?= number_format($totalCount) ?> تۆمار · <?= number_format($totalExpenses) ?></small></h2>
    <?php if (!$expenses): ?>
    <div class="empty">هیچ خەرجییەک لەم ماوەیەدا نییە</div>
    <?php else: ?>
    <table class="lt">
        <thead><tr><th>#</th><th>بەروار</th><th>گرووپ</th><th>وەسف</th><?php if ($isLab): ?><th>نەخۆش</th><?php endif; ?><th>فرۆشیار</th><th class="n">بڕ</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ($expenses as $x): $i++; ?>
        <tr>
            <td class="num"><?= $i ?></td>
            <td class="date"><?= $e($x['expense_date']) ?></td>
            <td><?= $e(expense_group_label($x)) ?></td>
            <td class="desc"><?= $e($x['description'] ?? '') ?></td>
            <?php if ($isLab): ?><td><?= $e(!empty($x['patient_name']) ? $x['patient_name'] : '-') ?></td><?php endif; ?>
            <td class="vendor"><?= $e($x['vendor'] ?? $x['supplier'] ?? $x['lab_name'] ?? '-') ?></td>
            <td class="n amount"><?= number_format((float)$x['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <!-- the total as the last row (a tfoot would repeat on every page) -->
        <tr class="tot"><td colspan="<?= $colCount - 1 ?>">کۆی گشتی</td><td class="n"><?= number_format($totalExpenses) ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($stats): ?>
    <!-- the totals, as a plain table under the list -->
    <h3 class="ct-h">پوختە بەپێی گرووپ</h3>
    <table class="ct">
        <thead><tr><th>گرووپ</th><th class="c">ژمارە</th><th class="c">ڕێژە</th><th class="n">کۆ</th></tr></thead>
        <tbody>
        <?php foreach ($stats as $st): ?>
        <tr>
            <td><?= $e($st['category_name'] ?? 'بێ گرووپ') ?></td>
            <td class="c"><?= (int)$st['count'] ?></td>
            <td class="c"><?= $totalExpenses > 0 ? round($st['total'] / $totalExpenses * 100, 1) : 0 ?>%</td>
            <td class="n"><?= number_format($st['total']) ?></td>
        </tr>
        <?php if (array_keys($st['subs']) !== ['بێ لق']): arsort($st['subs']); foreach ($st['subs'] as $subName => $subTotal): ?>
        <tr class="sub"><td><?= $e($subName) ?></td><td class="c"></td><td class="c"></td><td class="n"><?= number_format($subTotal) ?></td></tr>
        <?php endforeach; endif; ?>
        <?php endforeach; ?>
        <tr class="tot"><td>کۆی گشتی</td><td class="c"><?= number_format($totalCount) ?></td><td class="c">100%</td><td class="n"><?= number_format($totalExpenses) ?></td></tr>
        </tbody>
    </table>
    <?php endif; ?>

</div>
<?php if (($_GET['autoprint'] ?? '') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
</body>
</html>
