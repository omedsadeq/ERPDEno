<?php
/**
 * Print Comprehensive Expense Report (PDF) using InvoicePdfService
 * ڕاپۆرتی تەواوی خەرجییەکان
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    die('Unauthorized access');
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$expenseType = $_GET['expense_type'] ?? 'all';
$categoryId = $_GET['category'] ?? '';
$doctorId = $_GET['doctor'] ?? '';

// Type labels
$typeLabels = [
    'all' => 'هەموو خەرجییەکان',
    'general' => 'خەرجی گشتی',
    'lab' => 'خەرجی تاقیگە',
    'store' => 'خەرجی کۆگا'
];

$reportTitle = $typeLabels[$expenseType] ?? 'ڕاپۆرتی خەرجییەکان';

// Build queries based on expense type
$allExpenses = [];
$params = ['from' => $dateFrom, 'to' => $dateTo];

// Get General Expenses
if ($expenseType === 'all' || $expenseType === 'general') {
    $whereGeneral = "e.expense_date BETWEEN :from AND :to";
    $paramsGeneral = ['from' => $dateFrom, 'to' => $dateTo];

    if ($categoryId && $expenseType === 'general') {
        $whereGeneral .= " AND e.category_id = :cat";
        $paramsGeneral['cat'] = $categoryId;
    }
    if ($doctorId) {
        $whereGeneral .= " AND e.doctor_id = :doc";
        $paramsGeneral['doc'] = $doctorId;
    }

    $sqlGeneral = "SELECT e.*, 'general' as expense_type, 'خەرجی گشتی' as type_label,
                   ec.category_name, u.full_name as created_by_name, d.full_name as doctor_name
                   FROM expenses_general e
                   LEFT JOIN expense_categories_general ec ON e.category_id = ec.id
                   LEFT JOIN users u ON e.created_by = u.id
                   LEFT JOIN users d ON e.doctor_id = d.id
                   WHERE $whereGeneral
                   ORDER BY e.expense_date ASC, e.id ASC";
    $generalExpenses = $db->fetchAll($sqlGeneral, $paramsGeneral);
    $allExpenses = array_merge($allExpenses, $generalExpenses);
}

// Get Lab Expenses
if ($expenseType === 'all' || $expenseType === 'lab') {
    $whereLab = "e.expense_date BETWEEN :from AND :to";
    $paramsLab = ['from' => $dateFrom, 'to' => $dateTo];

    if ($categoryId && $expenseType === 'lab') {
        $whereLab .= " AND e.category_id = :cat";
        $paramsLab['cat'] = $categoryId;
    }
    if ($doctorId) {
        $whereLab .= " AND e.doctor_id = :doc";
        $paramsLab['doc'] = $doctorId;
    }

    $sqlLab = "SELECT e.*, 'lab' as expense_type, 'خەرجی تاقیگە' as type_label,
               ec.category_name, u.full_name as created_by_name, d.full_name as doctor_name,
               p.full_name as patient_name
               FROM expenses_lab e
               LEFT JOIN expense_categories_lab ec ON e.category_id = ec.id
               LEFT JOIN users u ON e.created_by = u.id
               LEFT JOIN users d ON e.doctor_id = d.id
               LEFT JOIN patients p ON e.patient_id = p.id
               WHERE $whereLab
               ORDER BY e.expense_date ASC, e.id ASC";
    $labExpenses = $db->fetchAll($sqlLab, $paramsLab);
    $allExpenses = array_merge($allExpenses, $labExpenses);
}

// Get Store/Inventory Expenses
if ($expenseType === 'all' || $expenseType === 'store') {
    $whereStore = "e.expense_date BETWEEN :from AND :to";
    $paramsStore = ['from' => $dateFrom, 'to' => $dateTo];

    if ($categoryId && $expenseType === 'store') {
        $whereStore .= " AND e.category_id = :cat";
        $paramsStore['cat'] = $categoryId;
    }
    if ($doctorId) {
        $whereStore .= " AND e.doctor_id = :doc";
        $paramsStore['doc'] = $doctorId;
    }

    $sqlStore = "SELECT e.*, 'store' as expense_type, 'خەرجی کۆگا' as type_label,
                 ec.category_name, u.full_name as created_by_name, d.full_name as doctor_name
                 FROM expenses_store e
                 LEFT JOIN expense_categories_store ec ON e.category_id = ec.id
                 LEFT JOIN users u ON e.created_by = u.id
                 LEFT JOIN users d ON e.doctor_id = d.id
                 WHERE $whereStore
                 ORDER BY e.expense_date ASC, e.id ASC";
    $storeExpenses = $db->fetchAll($sqlStore, $paramsStore);
    $allExpenses = array_merge($allExpenses, $storeExpenses);
}

// Sort all expenses by date
usort($allExpenses, function($a, $b) {
    return strcmp($a['expense_date'], $b['expense_date']);
});

// Get totals by type
$baseParams = ['from' => $dateFrom, 'to' => $dateTo];
$totalGeneral = $db->fetch("SELECT COALESCE(SUM(amount), 0) as total FROM expenses_general WHERE expense_date BETWEEN :from AND :to", $baseParams)['total'];
$totalLab = $db->fetch("SELECT COALESCE(SUM(amount), 0) as total FROM expenses_lab WHERE expense_date BETWEEN :from AND :to", $baseParams)['total'];
$totalStore = $db->fetch("SELECT COALESCE(SUM(amount), 0) as total FROM expenses_store WHERE expense_date BETWEEN :from AND :to", $baseParams)['total'];
$grandTotal = $totalGeneral + $totalLab + $totalStore;

// Get counts by type (bound params — never interpolate request values into SQL)
$countGeneral = $db->count('expenses_general', "expense_date BETWEEN :from AND :to", $baseParams);
$countLab = $db->count('expenses_lab', "expense_date BETWEEN :from AND :to", $baseParams);
$countStore = $db->count('expenses_store', "expense_date BETWEEN :from AND :to", $baseParams);
$totalCount = count($allExpenses);

// Get statistics by category for each type
$statsGeneral = $db->fetchAll(
    "SELECT ec.category_name, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_general e
     LEFT JOIN expense_categories_general ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
    $baseParams
);

$statsLab = $db->fetchAll(
    "SELECT ec.category_name, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_lab e
     LEFT JOIN expense_categories_lab ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
    $baseParams
);

$statsStore = $db->fetchAll(
    "SELECT ec.category_name, SUM(e.amount) as total, COUNT(*) as count
     FROM expenses_store e
     LEFT JOIN expense_categories_store ec ON e.category_id = ec.id
     WHERE e.expense_date BETWEEN :from AND :to
     GROUP BY e.category_id, ec.category_name ORDER BY total DESC",
    $baseParams
);

// Calculate displayed total
$displayedTotal = array_sum(array_column($allExpenses, 'amount'));

// Initialize InvoicePdfService
$pdfService = new InvoicePdfService();
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
            color: #0078D4;
            border-bottom: 2px solid #0078D4;
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
            border: 1px solid #0078D4;
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
            background: #0078D4;
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
            background: #0078D4;
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
                    <td style="font-weight: bold; color: #0078D4;">' . $reportTitle . '</td>
                </tr>
                <tr>
                    <td>لە بەروار :</td>
                    <td>' . $dateFrom . '</td>
                </tr>
                <tr>
                    <td>بۆ بەروار :</td>
                    <td>' . $dateTo . '</td>
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
                    <td style="font-weight: bold; color: #0078D4;">' . $totalCount . '</td>
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
        <tr>
            <th style="width: 25px;">#</th>
            <th style="width: 60px;">بەروار</th>
            <th style="width: 70px;">جۆر</th>
            <th style="width: 80px;">گرووپ</th>
            <th>وەسف</th>
            <th style="width: 70px;">دکتۆر</th>
            <th style="width: 65px;">بڕ</th>
        </tr>
    </thead>
    <tbody>';

    $counter = 1;
    foreach ($allExpenses as $exp) {
        $typeClass = 'type-' . $exp['expense_type'];
        $html .= '<tr>
            <td>' . $counter++ . '</td>
            <td>' . $exp['expense_date'] . '</td>
            <td><span class="type-badge ' . $typeClass . '">' . $exp['type_label'] . '</span></td>
            <td>' . htmlspecialchars($exp['category_name'] ?? '-') . '</td>
            <td style="text-align: right;">' . htmlspecialchars($exp['description']) . '</td>
            <td>' . htmlspecialchars($exp['doctor_name'] ?? '-') . '</td>
            <td style="text-align: right; font-weight: bold;">' . number_format($exp['amount']) . '</td>
        </tr>';
    }

    $html .= '
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" style="text-align: right;">کۆی گشتی:</td>
            <td style="text-align: right;">' . number_format($displayedTotal) . '</td>
        </tr>
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
