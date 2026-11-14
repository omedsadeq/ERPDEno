<?php
/**
 * Print Comprehensive Expense Report (PDF) using InvoicePdfService
 * ڕاپۆرتی تەواوی خەرجییەکان
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../includes/expense-subgroup.php';
require_once __DIR__ . '/../includes/expense-report-filter.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn() || !Permission::getInstance()->can('page.expense_reports')) {
    die('Unauthorized access');
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// The report's own filters (includes/expense-report-filter.php, as on the page)
$f = exp_report_filters($_GET);
$dateFrom = $f['from'];
$dateTo = $f['to'];
$expenseType = $f['type'];
$labels = exp_report_labels($f);
$reportTitle = $f['type'] === 'all' ? 'ڕاپۆرتی خەرجییەکان' : EXP_REPORT_KINDS[$f['type']];
$doctorLabel = $labels['doctor'];

// oldest first unless the page sorted it; the columns as arranged on the page
$allExpenses = exp_report_list($f, false);
$pdfCols = exp_report_visible_columns(exp_report_layout());
$pdfKeys = array_column($pdfCols, 'key');
$pdfWidths = ['num' => 25, 'date' => 60, 'type' => 70, 'group' => 80, 'sub' => 60, 'doctor' => 70, 'vendor' => 70, 'receipt' => 55,
              'patient' => 70, 'kind' => 50, 'method' => 50, 'by' => 60, 'created' => 70, 'amount' => 65];
$totals = exp_report_totals($f);
$totalGeneral = $totals['general']['total'];
$totalLab = $totals['lab']['total'];
$totalStore = $totals['store']['total'];
$grandTotal = $totalGeneral + $totalLab + $totalStore;
$countGeneral = $totals['general']['count'];
$countLab = $totals['lab']['count'];
$countStore = $totals['store']['count'];
$totalCount = count($allExpenses);
$subgroupTotals = [];
foreach (array_keys(EXP_REPORT_KINDS) as $kind) {
    $subgroupTotals[$kind] = exp_report_subgroup_totals($kind, $f);
}

// A group's sub-groups under it in the tables below
$subgroupRows = function (array $parts) {
    $rows = '';
    foreach ($parts as $part) {
        $rows .= '<tr style="color: #666;">
            <td style="padding-right: 16px;">' . htmlspecialchars(expense_subgroup_name($part)) . '</td>
            <td style="text-align: center;">' . $part['count'] . '</td>
            <td>' . number_format((float)$part['total']) . '</td>
        </tr>';
    }
    return $rows;
};
$statsGeneral = exp_report_stats('general', $f);
$statsLab = exp_report_stats('lab', $f);
$statsStore = exp_report_stats('store', $f);

// Calculate displayed total
$displayedTotal = array_sum(array_column($allExpenses, 'amount'));

// Initialize InvoicePdfService
$pdfService = new InvoicePdfService(['format' => 'A4']);
$pdfService->setTitle($reportTitle);
$pdfService->setAuthor($currentUser['full_name']);

// Build HTML content
$html = '
<!DOCTYPE html>
<html dir="rtl">
<head>
    <style>
        body {
            font-family: nrt, sans-serif;
            direction: rtl;
            font-size: 9pt;
        }
        h2 {
            color: ' . Branding::primaryColor() . ';
            border-bottom: 2px solid ' . Branding::primaryColor() . ';
            padding-bottom: 4px;
            margin-top: 12px;
            margin-bottom: 8px;
            font-size: 12pt;
        }
        h3 {
            color: #333;
            margin-top: 10px;
            margin-bottom: 6px;
            font-size: 10pt;
        }

        /* Info Boxes */
        .info-section {
            margin-bottom: 10px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-box {
            border: 1px solid ' . Branding::primaryColor() . ';
            background: #f0f8ff;
        }
        .info-box td {
            padding: 3px 6px;
            font-size: 9pt;
            border-bottom: 1px solid #ccc;
        }
        .info-box tr:last-child td {
            border-bottom: none;
        }
        .info-box td:first-child {
            font-weight: bold;
            text-align: right;
            width: 40%;
        }
        .info-box td:last-child {
            background: white;
        }

        /* Summary Cards */
        .summary-cards {
            margin-bottom: 10px;
        }
        .summary-card {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
            background: #fafafa;
        }
        .summary-card.general { border-top: 3px solid #4caf50; }
        .summary-card.lab { border-top: 3px solid #9c27b0; }
        .summary-card.store { border-top: 3px solid #ff9800; }
        .summary-card.total { border-top: 3px solid #2196f3; background: #e3f2fd; }
        .summary-value {
            font-size: 14pt;
            font-weight: bold;
            color: #1a1a1a;
        }
        .summary-label {
            font-size: 8pt;
            color: #666;
        }

        /* Main Table */
        .expenses-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .expenses-table th {
            background: ' . Branding::primaryColor() . ';
            color: white;
            padding: 5px 4px;
            text-align: center;
            font-weight: bold;
            font-size: 9pt;
        }
        .expenses-table td {
            border: 1px solid #ddd;
            padding: 4px 5px;
            text-align: center;
            font-size: 9pt;
        }
        .expenses-table tr:nth-child(even) {
            background: #f9f9f9;
        }
        .expenses-table tfoot td {
            background: #e8f5e9;
            font-weight: bold;
            border-top: 2px solid #4caf50;
        }

        /* Type Badge */
        .type-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
        }
        .type-general { background: #e8f5e9; color: #2e7d32; }
        .type-lab { background: #f3e5f5; color: #7b1fa2; }
        .type-store { background: #fff3e0; color: #ef6c00; }

        /* Category Summary */
        .category-summary {
            margin-top: 10px;
        }
        .category-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .category-table th {
            padding: 5px 8px;
            text-align: right;
            font-weight: bold;
            color: white;
            font-size: 9pt;
        }
        .category-table.general th { background: #4caf50; }
        .category-table.lab th { background: #9c27b0; }
        .category-table.store th { background: #ff9800; }
        .category-table td {
            padding: 4px 8px;
            border: 1px solid #ddd;
            font-size: 9pt;
        }
        .category-table td:first-child {
            text-align: right;
        }
        .category-table td:last-child {
            text-align: left;
            font-weight: bold;
        }

        /* Totals Section */
        .totals-section {
            margin-top: 10px;
            background: #f5f5f5;
            padding: 8px;
            border: 1px solid #ddd;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px 8px;
        }
        .totals-table td:first-child {
            font-weight: bold;
            text-align: right;
            width: 70%;
        }
        .totals-table td:last-child {
            text-align: left;
            font-weight: bold;
        }
        .totals-table .grand-total {
            background: ' . Branding::primaryColor() . ';
            color: white;
            font-size: 11pt;
        }
    </style>
</head>
<body>

<!-- Report Header Info -->
<table class="info-table" style="margin-bottom: 10px;">
    <tr>
        <td width="50%" style="padding: 2px;">
            <table width="100%" class="info-box">
                <tr>
                    <td>جۆری ڕاپۆرت :</td>
                    <td style="font-weight: bold; color: ' . Branding::primaryColor() . ';">' . $reportTitle . '</td>
                </tr>
                <tr>
                    <td>لە بەروار :</td>
                    <td>' . $dateFrom . '</td>
                </tr>
                <tr>
                    <td>بۆ بەروار :</td>
                    <td>' . $dateTo . '</td>
                </tr>
                <tr>
                    <td>گرووپ :</td>
                    <td>' . htmlspecialchars($labels['group']) . '</td>
                </tr>
                <tr>
                    <td>دکتۆر :</td>
                    <td>' . htmlspecialchars($doctorLabel) . '</td>
                </tr>
            </table>
        </td>
        <td width="50%" style="padding: 2px;">
            <table width="100%" class="info-box">
                <tr>
                    <td>بەرواری چاپکردن :</td>
                    <td>' . date('Y-m-d H:i') . '</td>
                </tr>
                <tr>
                    <td>چاپکراوە لەلایەن :</td>
                    <td>' . htmlspecialchars($currentUser['full_name']) . '</td>
                </tr>
                <tr>
                    <td>ژمارەی تۆماکان :</td>
                    <td style="font-weight: bold; color: ' . Branding::primaryColor() . ';">' . $totalCount . '</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!-- Summary Cards -->
<table width="100%" class="summary-cards" style="border-collapse: collapse;">
    <tr>
        <td width="25%" style="padding: 2px;">
            <div class="summary-card general">
                <div class="summary-value">' . number_format($totalGeneral) . '</div>
                <div class="summary-label">خەرجی گشتی (' . $countGeneral . ' تۆمار)</div>
            </div>
        </td>
        <td width="25%" style="padding: 2px;">
            <div class="summary-card lab">
                <div class="summary-value">' . number_format($totalLab) . '</div>
                <div class="summary-label">خەرجی تاقیگە (' . $countLab . ' تۆمار)</div>
            </div>
        </td>
        <td width="25%" style="padding: 2px;">
            <div class="summary-card store">
                <div class="summary-value">' . number_format($totalStore) . '</div>
                <div class="summary-label">خەرجی کۆگا (' . $countStore . ' تۆمار)</div>
            </div>
        </td>
        <td width="25%" style="padding: 2px;">
            <div class="summary-card total">
                <div class="summary-value">' . number_format($grandTotal) . '</div>
                <div class="summary-label">کۆی گشتی</div>
            </div>
        </td>
    </tr>
</table>';

// Main Expenses Table
if (!empty($allExpenses)) {
    $html .= '
<h2>لیستی خەرجییەکان</h2>
<table class="expenses-table">
    <thead>
        <tr>' . implode('', array_map(fn($c) => '<th' . (isset($pdfWidths[$c['key']]) ? ' style="width: ' . $pdfWidths[$c['key']] . 'px;"' : '') . '>'
            . htmlspecialchars($c['label']) . '</th>', $pdfCols)) . '</tr>
    </thead>
    <tbody>';

    $counter = 1;
    foreach ($allExpenses as $exp) {
        $typeClass = 'type-' . $exp['expense_type'];
        $html .= '<tr>';
        foreach ($pdfKeys as $k) {
            $html .= match ($k) {
                'num' => '<td>' . $counter++ . '</td>',
                'type' => '<td><span class="type-badge ' . $typeClass . '">' . $exp['type_label'] . '</span></td>',
                'group' => '<td>' . htmlspecialchars(in_array('sub', $pdfKeys, true) ? ($exp['category_name'] ?? '-') : expense_group_label($exp)) . '</td>',
                'desc', 'notes' => '<td style="text-align: right;">' . htmlspecialchars((string)exp_report_value($k, $exp)) . '</td>',
                'doctor' => '<td>' . (!empty($exp['doctor_name']) ? htmlspecialchars($exp['doctor_name'])
                    : (($exp['dept'] ?? null) === 'xray' ? '<span style="color:#0369a1;">X-ray</span>' : '<span style="color:#0f766e;">خەرجی سەنتەر</span>')) . '</td>',
                'amount' => '<td style="text-align: right; font-weight: bold;">' . number_format($exp['amount']) . '</td>',
                default => '<td>' . htmlspecialchars((string)exp_report_value($k, $exp)) . '</td>',
            };
        }
        $html .= '</tr>';
    }

    $foot = '';
    $labelAt = array_key_first(array_filter($pdfKeys, fn($k) => $k !== 'amount'));
    foreach ($pdfKeys as $n => $k) {
        $foot .= $k === 'amount' ? '<td style="text-align: right;">' . number_format($displayedTotal) . '</td>'
            : ($n === $labelAt ? '<td style="text-align: right; white-space: nowrap;">کۆی گشتی:</td>' : '<td></td>');
    }
    $html .= '
    </tbody>
    <tfoot>
        <tr>' . $foot . '</tr>
    </tfoot>
</table>';
}

// Category Summaries
$html .= '<div class="category-summary">';

// General Categories
if (!empty($statsGeneral) && ($expenseType === 'all' || $expenseType === 'general')) {
    $html .= '<h3>پوختەی خەرجی گشتی بەپێی گرووپ</h3>
    <table class="category-table general">
        <thead><tr><th>گرووپ</th><th style="width: 60px;">ژمارە</th><th style="width: 100px;">کۆ</th></tr></thead>
        <tbody>';
    foreach ($statsGeneral as $st) {
        $percent = $totalGeneral > 0 ? round(($st['total'] / $totalGeneral) * 100, 1) : 0;
        $html .= '<tr>
            <td>' . htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') . ' (' . $percent . '%)</td>
            <td style="text-align: center;">' . $st['count'] . '</td>
            <td>' . number_format($st['total']) . '</td>
        </tr>';
        $html .= $subgroupRows($subgroupTotals['general'][(int)$st['category_id']] ?? []);
    }
    $html .= '</tbody></table>';
}

// Lab Categories
if (!empty($statsLab) && ($expenseType === 'all' || $expenseType === 'lab')) {
    $html .= '<h3>پوختەی خەرجی تاقیگە بەپێی گرووپ</h3>
    <table class="category-table lab">
        <thead><tr><th>گرووپ</th><th style="width: 60px;">ژمارە</th><th style="width: 100px;">کۆ</th></tr></thead>
        <tbody>';
    foreach ($statsLab as $st) {
        $percent = $totalLab > 0 ? round(($st['total'] / $totalLab) * 100, 1) : 0;
        $html .= '<tr>
            <td>' . htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') . ' (' . $percent . '%)</td>
            <td style="text-align: center;">' . $st['count'] . '</td>
            <td>' . number_format($st['total']) . '</td>
        </tr>';
        $html .= $subgroupRows($subgroupTotals['lab'][(int)$st['category_id']] ?? []);
    }
    $html .= '</tbody></table>';
}

// Store Categories
if (!empty($statsStore) && ($expenseType === 'all' || $expenseType === 'store')) {
    $html .= '<h3>پوختەی خەرجی کۆگا بەپێی گرووپ</h3>
    <table class="category-table store">
        <thead><tr><th>گرووپ</th><th style="width: 60px;">ژمارە</th><th style="width: 100px;">کۆ</th></tr></thead>
        <tbody>';
    foreach ($statsStore as $st) {
        $percent = $totalStore > 0 ? round(($st['total'] / $totalStore) * 100, 1) : 0;
        $html .= '<tr>
            <td>' . htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') . ' (' . $percent . '%)</td>
            <td style="text-align: center;">' . $st['count'] . '</td>
            <td>' . number_format($st['total']) . '</td>
        </tr>';
        $html .= $subgroupRows($subgroupTotals['store'][(int)$st['category_id']] ?? []);
    }
    $html .= '</tbody></table>';
}

$html .= '</div>';

// Grand Total Section
$html .= '
<div class="totals-section">
    <table class="totals-table">
        <tr>
            <td>کۆی خەرجی گشتی:</td>
            <td>' . number_format($totalGeneral) . '</td>
        </tr>
        <tr>
            <td>کۆی خەرجی تاقیگە:</td>
            <td>' . number_format($totalLab) . '</td>
        </tr>
        <tr>
            <td>کۆی خەرجی کۆگا:</td>
            <td>' . number_format($totalStore) . '</td>
        </tr>
        <tr class="grand-total">
            <td>کۆی گشتی هەموو خەرجییەکان:</td>
            <td>' . number_format($grandTotal) . '</td>
        </tr>
    </table>
</div>

</body>
</html>';

// Set content and output PDF
$pdfService->setContent($html);
$filename = 'Expense_Report_' . $dateFrom . '_to_' . $dateTo . '.pdf';
$pdfService->output($filename, 'I'); // 'I' for inline display
