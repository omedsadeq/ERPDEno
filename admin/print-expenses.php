<?php
/**
 * Print Expenses Report (PDF) using InvoicePdfService
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
$sql = "SELECT e.*, u.full_name as created_by_name, ec.category_name";

if ($type === 'lab') {
    $sql .= ", p.full_name as patient_name, p.patient_code";
}

$sql .= " FROM {$expenseTable} e
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN {$categoryTable} ec ON e.category_id = ec.id";

if ($type === 'lab') {
    $sql .= " LEFT JOIN patients p ON e.patient_id = p.id";
}

$sql .= " WHERE e.expense_date BETWEEN :from AND :to";

$params = ['from' => $dateFrom, 'to' => $dateTo];

if ($categoryId) {
    $sql .= " AND e.category_id = :category";
    $params['category'] = $categoryId;
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
        $statsMap[$key] = ['category_name' => $name, 'total' => 0.0, 'count' => 0];
    }
    $statsMap[$key]['total'] += (float)$row['amount'];
    $statsMap[$key]['count']++;
}
$stats = array_values($statsMap);
usort($stats, fn($a, $b) => $b['total'] <=> $a['total']);

$totalExpenses = array_sum(array_column($expenses, 'amount'));
$totalCount = count($expenses);

// Initialize InvoicePdfService (uses global PDF settings for header/footer)
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
            font-size: 10pt;
        }
        h2 {
            color: ' . Branding::primaryColor() . ';
            border-bottom: 1px solid ' . Branding::primaryColor() . ';
            padding-bottom: 4px;
            margin-top: 10px;
            margin-bottom: 6px;
        }
        .expenses-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .expenses-table th {
            background: ' . Branding::primaryColor() . ';
            color: white;
            padding: 4px 3px;
            text-align: center;
            font-weight: bold;
        }
        .expenses-table td {
            border: 1px solid #ddd;
            padding: 3px 4px;
            text-align: center;
        }
        .expenses-table tr:nth-child(even) {
            background: #f9f9f9;
        }
        .totals-section {
            margin-top: 8px;
            background: #f5f5f5;
            padding: 6px;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 4px 6px;
        }
        .totals-table td:first-child {
            font-weight: bold;
            width: 70%;
            text-align: right;
        }
        .totals-table td:last-child {
            text-align: left;
            font-weight: bold;
        }
        .totals-table .grand-total {
            background: ' . Branding::primaryColor() . ';
            color: white;
        }
        .summary-box {
            background: #f0f8ff;
            border: 1px solid ' . Branding::primaryColor() . ';
            padding: 4px;
            margin-bottom: 6px;
        }
        .summary-item {
            padding: 3px 4px;
            border-bottom: 1px solid #ddd;
            display: table;
            width: 100%;
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .summary-label {
            display: table-cell;
            width: 60%;
            font-weight: bold;
            color: #333;
        }
        .summary-value {
            display: table-cell;
            width: 40%;
            text-align: left;
            color: ' . Branding::primaryColor() . ';
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Info Boxes Section -->
    <table width="100%" style="margin-bottom: 6px; border-collapse: collapse;">
        <tr>
            <td width="50%" style="padding: 1px;">
                <table width="100%" style="border: 1px solid ' . Branding::primaryColor() . '; background: #f0f8ff;">
                    <tr>
                        <td style="padding: 2px 4px; font-weight: bold; text-align: right; border-bottom: 1px solid #ccc;">جۆری ڕاپۆرت :</td>
                        <td style="padding: 2px 4px; background: white; border-bottom: 1px solid #ccc; font-weight: bold; color: ' . Branding::primaryColor() . ';">' . $reportTitle . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 4px; font-weight: bold; text-align: right; border-bottom: 1px solid #ccc;">لە بەروار :</td>
                        <td style="padding: 2px 4px; background: white; border-bottom: 1px solid #ccc;">' . $dateFrom . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 4px; font-weight: bold; text-align: right;">بۆ بەروار :</td>
                        <td style="padding: 2px 4px; background: white;">' . $dateTo . '</td>
                    </tr>
                </table>
            </td>
            <td width="50%" style="padding: 1px;">
                <table width="100%" style="border: 1px solid ' . Branding::primaryColor() . '; background: #f0f8ff;">
                    <tr>
                        <td style="padding: 2px 4px; font-weight: bold; text-align: right; border-bottom: 1px solid #ccc;">بەرواری چاپکردن :</td>
                        <td style="padding: 2px 4px; background: white; border-bottom: 1px solid #ccc;">' . date('Y-m-d H:i') . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 4px; font-weight: bold; text-align: right; border-bottom: 1px solid #ccc;">چاپکراوە لەلایەن :</td>
                        <td style="padding: 2px 4px; background: white; border-bottom: 1px solid #ccc;">' . htmlspecialchars($currentUser['full_name']) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 2px 4px; font-weight: bold; text-align: right;">ژمارەی تۆماکان :</td>
                        <td style="padding: 2px 4px; background: white; font-weight: bold; color: ' . Branding::primaryColor() . ';">' . $totalCount . '</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="expenses-table">
        <thead>
            <tr>
                <th style="width: 20px;">#</th>
                <th style="width: 60px;">بەروار</th>
                <th style="width: 80px;">گرووپ</th>
                <th>وەسف</th>';

if ($type === 'lab') {
    $html .= '<th style="width: 80px;">نەخۆش</th>';
}

$html .= '
                <th style="width: 70px;">فرۆشیار</th>
                <th style="width: 60px;">بڕ</th>
            </tr>
        </thead>
        <tbody>';

$counter = 1;
foreach ($expenses as $exp) {
    $html .= '<tr>
                <td>' . $counter++ . '</td>
                <td>' . $exp['expense_date'] . '</td>
                <td>' . htmlspecialchars($exp['category_name'] ?? '-') . '</td>
                <td style="text-align: right;">' . htmlspecialchars($exp['description']) . '</td>';

    if ($type === 'lab') {
        $patientInfo = !empty($exp['patient_name']) ? htmlspecialchars($exp['patient_name']) : '-';
        $html .= '<td>' . $patientInfo . '</td>';
    }

    $html .= '<td>' . htmlspecialchars($exp['vendor'] ?? $exp['supplier'] ?? '-') . '</td>
                <td style="text-align: right; font-weight: bold;">' . number_format($exp['amount']) . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>';

// Totals section
$html .= '
    <div class="totals-section">
        <table class="totals-table">
            <tr class="grand-total">
                <td>کۆی گشتی:</td>
                <td>' . number_format($totalExpenses) . '</td>
            </tr>
        </table>
    </div>';

// Add summary section by category
if (!empty($stats)) {
    $html .= '<h2>پوختە بەپێی گرووپ</h2><div class="summary-box">';
    foreach ($stats as $st) {
        $percentage = $totalExpenses > 0 ? round(($st['total'] / $totalExpenses) * 100, 1) : 0;
        $html .= '<div class="summary-item"><div class="summary-label">' . htmlspecialchars($st['category_name'] ?? 'بێ گرووپ') . ' (' . $st['count'] . ' تۆمار - ' . $percentage . '%)</div><div class="summary-value">' . number_format($st['total']) . '</div></div>';
    }
    $html .= '</div>';
}

$html .= '
</body>
</html>';

// Set content and output PDF
$pdfService->setContent($html);
$filename = $reportTitle . '_' . $dateFrom . '_to_' . $dateTo . '.pdf';
$pdfService->output($filename, 'I'); // 'I' for inline display, 'D' for download
