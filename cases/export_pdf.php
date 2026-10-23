<?php
/**
 * Cases PDF Export using InvoicePdfService
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$db = Database::getInstance();
$caseManager = new CaseManager();
$isAdmin = $auth->isAdmin();
$currentUserId = $auth->getCurrentUser()['id'];
$doctorId = $isAdmin ? null : $currentUserId;

// Get filter parameters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filterDoctorId = $isAdmin ? trim($_GET['doctor_id'] ?? '') : $currentUserId;
$paymentStatus = trim($_GET['payment_status'] ?? '');
$caseIds = isset($_GET['case_ids']) ? array_filter(explode(',', $_GET['case_ids'])) : [];

// Get cases based on filters or specific IDs
if (!empty($caseIds)) {
    // Get specific cases by IDs
    $cases = getCasesByIds($db, $caseIds, $doctorId);
} else {
    // Get cases by filters (all pages)
    $filters = [
        'status' => $status,
        'search' => $search,
        'doctor_id' => $filterDoctorId ?: $doctorId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo
    ];

    if ($paymentStatus) {
        $cases = getCasesWithPaymentFilterAll($db, $filters, $paymentStatus);
    } else {
        $cases = getAllCasesForExport($db, $filters);
    }
}

// Calculate totals
$totalAmount = 0;
$totalPaid = 0;
$totalDiscount = 0;
$totalRemaining = 0;

// Invoice ledger, exactly what cases/index.php prints on screen.
foreach ($cases as $case) {
    $totalAmount += (float)$case['ledger_total'];
    $totalPaid += (float)$case['ledger_paid'];
    $totalDiscount += (float)$case['ledger_discount'];
    $totalRemaining += (float)$case['ledger_balance'];
}

// Build date range string
$dateRangeStr = '';
if ($dateFrom || $dateTo) {
    $dateRangeStr = 'لە ' . ($dateFrom ?: 'سەرەتا') . ' بۆ ' . ($dateTo ?: 'ئێستا');
}

// Get doctor name if filtered by doctor
$doctorName = '';
if ($filterDoctorId) {
    $doc = $db->fetch("SELECT full_name FROM users WHERE id = :id", ['id' => $filterDoctorId]);
    $doctorName = $doc ? $doc['full_name'] : '';
}

// Initialize InvoicePdfService (uses global PDF settings for header/footer)
$pdfService = new InvoicePdfService();
$pdfService->setTitle('ڕاپۆرتی کەیسەکان');
$pdfService->setAuthor(CLINIC_NAME);

// Build HTML content
$html = '
<!DOCTYPE html>
<html dir="rtl" lang="ku">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: nrt, sans-serif;
            direction: rtl;
            text-align: right;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #0078d4;
        }
        .clinic-name {
            font-size: 24px;
            font-weight: bold;
            color: #0078d4;
            margin-bottom: 5px;
        }
        .report-title {
            font-size: 16px;
            color: #333;
            margin-bottom: 5px;
        }
        .report-meta {
            font-size: 11px;
            color: #666;
        }
        .filters-info {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 11px;
        }
        .filters-info span {
            display: inline-block;
            margin-left: 15px;
            background: #e3f2fd;
            padding: 3px 8px;
            border-radius: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th {
            background: linear-gradient(to bottom, #0078d4, #005a9e);
            color: white;
            padding: 10px 6px;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            border: 1px solid #0078d4;
        }
        td {
            padding: 8px 6px;
            font-size: 10px;
            text-align: center;
            border: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f0f7ff;
        }
        .amount-total { color: #0078d4; font-weight: bold; }
        .amount-paid { color: #4caf50; font-weight: bold; }
        .amount-remaining { color: #f44336; font-weight: bold; }
        .amount-remaining.zero { color: #4caf50; }
        .status-open { background: #e8f5e9; color: #2e7d32; padding: 2px 6px; border-radius: 8px; }
        .status-closed { background: #e3f2fd; color: #1565c0; padding: 2px 6px; border-radius: 8px; }
        .status-pending { background: #fff3e0; color: #e65100; padding: 2px 6px; border-radius: 8px; }
        .summary-box {
            background: linear-gradient(to bottom, #fafafa, #f0f0f0);
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        .summary-title {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
            text-align: center;
        }
        .summary-grid {
            display: table;
            width: 100%;
        }
        .summary-item {
            display: table-cell;
            text-align: center;
            padding: 10px;
            width: 25%;
        }
        .summary-value {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .summary-value.total { color: #0078d4; }
        .summary-value.paid { color: #4caf50; }
        .summary-value.discount { color: #ff9800; }
        .summary-value.remaining { color: #f44336; }
        .summary-label {
            font-size: 10px;
            color: #666;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            font-size: 9px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="clinic-name">' . htmlspecialchars(CLINIC_NAME) . '</div>
        <div class="report-title">ڕاپۆرتی کەیسەکان</div>
        <div class="report-meta">
            بەروار: ' . date('Y-m-d H:i') . '
            ' . ($dateRangeStr ? ' | ' . $dateRangeStr : '') . '
        </div>
    </div>';

// Add active filters info
$hasFilters = $status || $doctorName || $paymentStatus;
if ($hasFilters) {
    $html .= '<div class="filters-info">';
    $html .= '<strong>فلتەرەکان:</strong> ';

    if ($status) {
        $statusLabels = ['open' => 'کراوە', 'closed' => 'داخراوە', 'pending' => 'چاوەڕوان'];
        $html .= '<span>دۆخ: ' . ($statusLabels[$status] ?? $status) . '</span>';
    }
    if ($doctorName) {
        $html .= '<span>دکتۆر: د. ' . htmlspecialchars($doctorName) . '</span>';
    }
    if ($paymentStatus) {
        $paymentLabels = ['paid' => 'پارەی دراوە', 'partial' => 'بەشێکی دراوە', 'unpaid' => 'پارەی نەدراوە'];
        $html .= '<span>پارەدان: ' . ($paymentLabels[$paymentStatus] ?? $paymentStatus) . '</span>';
    }

    $html .= '</div>';
}

// Cases table
$html .= '
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 12%;">ژمارەی کەیس</th>
                <th style="width: 18%;">نەخۆش</th>
                <th style="width: 15%;">دکتۆر</th>
                <th style="width: 10%;">کۆی گشتی</th>
                <th style="width: 10%;">پارەدراو</th>
                <th style="width: 8%;">داشکاندن</th>
                <th style="width: 10%;">ماوە</th>
                <th style="width: 7%;">دۆخ</th>
                <th style="width: 10%;">بەروار</th>
            </tr>
        </thead>
        <tbody>';

$counter = 1;
foreach ($cases as $case) {
    $caseTotal    = (float)$case['ledger_total'];
    $casePaid     = (float)$case['ledger_paid'];
    $caseDiscount = (float)$case['ledger_discount'];
    $remaining    = (float)$case['ledger_balance'];

    $statusClass = 'status-' . $case['status'];
    $statusLabels = [
        'open' => 'کراوە',
        'closed' => 'داخراوە',
        'pending' => 'چاوەڕوان',
        'cancelled' => 'هەڵوەشاوە'
    ];
    $statusLabel = $statusLabels[$case['status']] ?? $case['status'];

    $html .= '
            <tr>
                <td>' . $counter++ . '</td>
                <td style="font-family: nrt;">' . htmlspecialchars($case['case_number']) . '</td>
                <td>' . htmlspecialchars($case['patient_name']) . '</td>
                <td>د. ' . htmlspecialchars($case['doctor_name']) . '</td>
                <td class="amount-total">' . number_format($caseTotal) . '</td>
                <td class="amount-paid">' . number_format($casePaid) . '</td>
                <td>' . ($caseDiscount > 0 ? number_format($caseDiscount) : '-') . '</td>
                <td class="amount-remaining' . ($remaining <= 0 ? ' zero' : '') . '">' . number_format($remaining) . '</td>
                <td><span class="' . $statusClass . '">' . $statusLabel . '</span></td>
                <td>' . date('Y-m-d', strtotime($case['created_at'])) . '</td>
            </tr>';
}

$html .= '
        </tbody>
    </table>';

// Summary box
$html .= '
    <div class="summary-box">
        <div class="summary-title">کورتەی داراییەکان</div>
        <table style="border: none;">
            <tr>
                <td style="border: none; text-align: center;">
                    <div class="summary-value" style="color: #6366f1;">' . count($cases) . '</div>
                    <div class="summary-label">ژمارەی کەیس</div>
                </td>
                <td style="border: none; text-align: center;">
                    <div class="summary-value total">' . number_format($totalAmount) . '</div>
                    <div class="summary-label">کۆی گشتی</div>
                </td>
                <td style="border: none; text-align: center;">
                    <div class="summary-value paid">' . number_format($totalPaid) . '</div>
                    <div class="summary-label">پارەی دراو</div>
                </td>
                <td style="border: none; text-align: center;">
                    <div class="summary-value discount">' . number_format($totalDiscount) . '</div>
                    <div class="summary-label">داشکان</div>
                </td>
                <td style="border: none; text-align: center;">
                    <div class="summary-value remaining">' . number_format($totalRemaining) . '</div>
                    <div class="summary-label">کۆی ماوە</div>
                </td>
            </tr>
        </table>
    </div>';

// Footer
$html .= '
    <div class="footer">
        ' . APP_NAME . ' - ' . APP_NAME_EN . '<br>
        ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی دروستکراوە
    </div>
</body>
</html>';

// Set content and output PDF
$pdfService->setContent($html);
$filename = 'cases_report_' . date('Y-m-d_His') . '.pdf';
$pdfService->output($filename, 'I'); // I = inline (display in browser), D = download

/**
 * Get cases by specific IDs
 */
function getCasesByIds($db, $ids, $doctorId) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_map('intval', $ids);

    $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
            p.full_name as patient_name, p.patient_code,
            u.full_name as doctor_name,
            COALESCE(fs.total_invoiced, 0) AS ledger_total,
            COALESCE(fs.total_discount, 0) AS ledger_discount,
            COALESCE(fs.paid_amount, 0)    AS ledger_paid,
            COALESCE(fs.balance, 0)        AS ledger_balance
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN users u ON pf.doctor_id = u.id
            LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
            WHERE c.id IN ($placeholders)";

    if ($doctorId) {
        $sql .= " AND pf.doctor_id = ?";
        $params[] = $doctorId;
    }

    $sql .= " ORDER BY c.created_at DESC";

    $stmt = $db->getConnection()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all cases for export (no pagination)
 */
function getAllCasesForExport($db, $filters) {
    $params = [];

    $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
            p.full_name as patient_name, p.patient_code,
            u.full_name as doctor_name,
            COALESCE(fs.total_invoiced, 0) AS ledger_total,
            COALESCE(fs.total_discount, 0) AS ledger_discount,
            COALESCE(fs.paid_amount, 0)    AS ledger_paid,
            COALESCE(fs.balance, 0)        AS ledger_balance
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN users u ON pf.doctor_id = u.id
            LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
            WHERE 1=1";

    if (!empty($filters['status'])) {
        $sql .= " AND c.status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['doctor_id'])) {
        $sql .= " AND pf.doctor_id = :doctor_id";
        $params['doctor_id'] = $filters['doctor_id'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
        $params['search'] = "%{$filters['search']}%";
        $params['search2'] = "%{$filters['search']}%";
        $params['search3'] = "%{$filters['search']}%";
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(c.created_at) >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(c.created_at) <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    $sql .= " ORDER BY c.updated_at DESC";

    return $db->fetchAll($sql, $params);
}

/**
 * Get cases with payment status filter (no pagination)
 */
function getCasesWithPaymentFilterAll($db, $filters, $paymentStatus) {
    $params = [];

    $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
            p.full_name as patient_name, p.patient_code,
            u.full_name as doctor_name,
            COALESCE(fs.total_invoiced, 0) AS ledger_total,
            COALESCE(fs.total_discount, 0) AS ledger_discount,
            COALESCE(fs.paid_amount, 0)    AS ledger_paid,
            COALESCE(fs.balance, 0)        AS ledger_balance
            FROM cases c
            INNER JOIN patient_folders pf ON c.folder_id = pf.id
            LEFT JOIN patients p ON pf.patient_id = p.id
            LEFT JOIN users u ON pf.doctor_id = u.id
            LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
            WHERE 1=1";

    if (!empty($filters['status'])) {
        $sql .= " AND c.status = :status";
        $params['status'] = $filters['status'];
    }

    if (!empty($filters['doctor_id'])) {
        $sql .= " AND pf.doctor_id = :doctor_id";
        $params['doctor_id'] = $filters['doctor_id'];
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
        $params['search'] = "%{$filters['search']}%";
        $params['search2'] = "%{$filters['search']}%";
        $params['search3'] = "%{$filters['search']}%";
    }

    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(c.created_at) >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(c.created_at) <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    // Payment status filter
    switch ($paymentStatus) {
        case 'paid':
            $sql .= " AND COALESCE(fs.approved_amount, 0) > 0 AND COALESCE(fs.balance, 0) <= 0.009";
            break;
        case 'partial':
            $sql .= " AND COALESCE(fs.paid_amount, 0) > 0 AND COALESCE(fs.balance, 0) > 0.009";
            break;
        case 'unpaid':
            $sql .= " AND COALESCE(fs.paid_amount, 0) <= 0 AND COALESCE(fs.balance, 0) > 0.009";
            break;
    }

    $sql .= " ORDER BY c.updated_at DESC";

    return $db->fetchAll($sql, $params);
}
