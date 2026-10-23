<?php
/**
 * Generate PDF Receipt for Dental Case
 * Uses centralized InvoicePdfService for consistent branding
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/DentalChart.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

// Initialize database
$db = Database::getInstance();

// Check authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

// Get case ID
if (!isset($_GET['id'])) {
    die('Case ID is required');
}

$caseId = intval($_GET['id']);

// Doctor isolation: a doctor may only print receipts for cases they own.
AccessGuard::requireCaseWork($caseId, false);

// Initialize managers
$caseManager = new CaseManager();
$paymentManager = new Payment();
$dentalChart = new DentalChart();

// Get case details (includes patient info from JOIN)
$case = $caseManager->getById($caseId);
if (!$case) {
    die('Case not found');
}

// Get treatments
$caseTreatments = $caseManager->getTreatments($caseId);

// Palmer numbers for display
$palmerNumbers = [];
for ($i = 11; $i <= 48; $i++) {
    $palmerNumbers[$i] = $dentalChart->getPalmerNumber($i);
}

// Group treatments by treatment name
$groupedTreatments = [];
foreach ($caseTreatments as $ct) {
    $key = $ct['treatment_name'] . '_' . $ct['unit_price'];
    if (!isset($groupedTreatments[$key])) {
        $groupedTreatments[$key] = [
            'treatment_name' => $ct['treatment_name'],
            'unit_price' => $ct['unit_price'],
            'upper_left' => [],
            'upper_right' => [],
            'lower_left' => [],
            'lower_right' => [],
            'total_quantity' => 0,
            'total_price' => 0
        ];
    }

    if ($ct['tooth_number']) {
        $quadrant = $dentalChart->getQuadrant($ct['tooth_number']);
        $palmerNum = $palmerNumbers[$ct['tooth_number']];

        if ($quadrant === 'UL') {
            $groupedTreatments[$key]['upper_left'][] = $palmerNum;
        } elseif ($quadrant === 'UR') {
            $groupedTreatments[$key]['upper_right'][] = $palmerNum;
        } elseif ($quadrant === 'LL') {
            $groupedTreatments[$key]['lower_left'][] = $palmerNum;
        } elseif ($quadrant === 'LR') {
            $groupedTreatments[$key]['lower_right'][] = $palmerNum;
        }
    }

    $groupedTreatments[$key]['total_quantity'] += $ct['quantity'];
    $groupedTreatments[$key]['total_price'] += $ct['final_price'];
}

// Calculate totals
$remaining = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount'];
$discountPercent = $case['total_amount'] > 0 ? round(($case['discount_amount'] / $case['total_amount']) * 100, 1) : 0;
$afterDiscount = $case['total_amount'] - $case['discount_amount'];

// Initialize InvoicePdfService (automatically loads global settings)
$pdfService = new InvoicePdfService();
$pdfService->setTitle('وەسڵی کەیس #' . $caseId);
$pdfService->setAuthor($currentUser['full_name']);

// Build HTML content
$html = '
<style>
    .info-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
    }
    .info-box {
        border: 1px solid #0099cc;
    }
    .info-box td {
        padding: 3px 6px;
        font-size: 9pt;
        border-bottom: 1px solid #ddd;
    }
    .info-box tr:last-child td {
        border-bottom: none;
    }
    .info-box td:first-child {
        background: #e0f0ff;
        font-weight: bold;
        text-align: right;
        width: 35%;
    }
    .info-box td:last-child {
        background: white;
        text-align: center;
    }

    .treatments-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #0099cc;
        margin-bottom: 8px;
    }
    .treatments-table th {
        background: #0099cc;
        color: white;
        padding: 5px 3px;
        font-size: 9pt;
        font-weight: bold;
        text-align: center;
        border: 1px solid #0099cc;
    }
    .treatments-table td {
        padding: 4px 3px;
        text-align: center;
        border: 1px solid #ccc;
        font-size: 9pt;
        background: white;
    }
    .treatment-name {
        text-align: right !important;
        font-weight: bold;
    }

    .tooth-box {
        width: 60px;
        margin: 0 auto;
        border: 1px solid #666;
    }
    .tooth-box td {
        width: 30px;
        height: 14px;
        font-size: 7pt;
        text-align: center !important;
        padding: 1px !important;
        background: white !important;
        border: none !important;
    }
    .tooth-box .q-top {
        border-bottom: 1px solid #333 !important;
    }
    .tooth-box .q-right {
        border-left: 1px solid #333 !important;
    }
    .tooth-box .q-left {
        border-right: 1px solid #333 !important;
    }

    .summary-wrapper {
        width: 100%;
    }
    .notes-cell {
        width: 38%;
        vertical-align: top;
        padding-left: 8px;
    }
    .totals-cell {
        width: 62%;
        vertical-align: top;
    }
    .notes-box {
        border: 1px solid #0099cc;
        padding: 5px;
        min-height: 50px;
        background: #fafafa;
        font-size: 8pt;
    }
    .notes-label {
        font-weight: bold;
        color: #006699;
    }

    .totals-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #0099cc;
    }
    .totals-table td {
        padding: 2px 6px;
        border-bottom: 1px solid #ddd;
        font-size: 8pt;
    }
    .totals-table tr:last-child td {
        border-bottom: none;
    }
    .totals-table td:first-child {
        background: #e0f0ff;
        font-weight: bold;
        text-align: right;
        width: 55%;
    }
    .totals-table td:last-child {
        background: white;
        text-align: center;
        font-weight: bold;
        color: #006699;
    }
    .row-purple td {
        background: white !important;
        color: #cc0000 !important;
    }
    .row-blue td {
        background: #0099cc !important;
        color: white !important;
    }
    .row-green td {
        background: #28a745 !important;
        color: white !important;
    }
</style>

<!-- Info Boxes Section -->
<table class="info-table">
    <tr>
        <td width="50%" style="padding-left: 4px;">
            <table width="100%" class="info-box">
                <tr>
                    <td>دکتۆر :</td>
                    <td>' . htmlspecialchars($currentUser['full_name']) . '</td>
                </tr>
                <tr>
                    <td>ناوی نەخۆش :</td>
                    <td>' . htmlspecialchars($case['patient_name']) . '</td>
                </tr>
                <tr>
                    <td>ژمارە مۆبایل :</td>
                    <td>' . htmlspecialchars($case['patient_phone'] ?? '') . '</td>
                </tr>
                <tr>
                    <td>ژمارە پسوڵە :</td>
                    <td style="font-weight: bold; color: #006699;">' . $caseId . '</td>
                </tr>
            </table>
        </td>
        <td width="50%" style="padding-right: 4px;">
            <table width="100%" class="info-box">
                <tr>
                    <td>بەرواری :</td>
                    <td>' . date('Y-m-d', strtotime($case['created_at'])) . '</td>
                </tr>
                <tr>
                    <td>ژمارەی سجل :</td>
                    <td>' . htmlspecialchars($case['case_number']) . '</td>
                </tr>
                <tr>
                    <td colspan="2" style="height: 40px; background: #f5f5f5;"></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!-- Treatment Table -->
<table class="treatments-table">
    <thead>
        <tr>
            <th style="width: 20px;">#</th>
            <th>ناوەڕۆک</th>
            <th style="width: 70px;">ددان</th>
            <th style="width: 40px;">ژمارە</th>
            <th style="width: 55px;">نرخ</th>
            <th style="width: 65px;">کۆی گشتی</th>
        </tr>
    </thead>
    <tbody>';

$rowNum = 1;
foreach ($groupedTreatments as $gt) {
    $toothDisplay = '
        <table class="tooth-box">
            <tr>
                <td class="q-top q-right">' . (!empty($gt['upper_right']) ? implode(',', $gt['upper_right']) : '') . '</td>
                <td class="q-top q-left">' . (!empty($gt['upper_left']) ? implode(',', $gt['upper_left']) : '') . '</td>
            </tr>
            <tr>
                <td class="q-right">' . (!empty($gt['lower_right']) ? implode(',', $gt['lower_right']) : '') . '</td>
                <td class="q-left">' . (!empty($gt['lower_left']) ? implode(',', $gt['lower_left']) : '') . '</td>
            </tr>
        </table>';

    $html .= '
        <tr>
            <td>' . $rowNum++ . '</td>
            <td class="treatment-name">' . htmlspecialchars($gt['treatment_name']) . '</td>
            <td>' . $toothDisplay . '</td>
            <td>' . number_format($gt['total_quantity']) . '</td>
            <td>' . number_format($gt['unit_price'], 0) . '</td>
            <td style="font-weight: bold;">' . number_format($gt['total_price'], 0) . '</td>
        </tr>';
}

$html .= '
    </tbody>
</table>

<!-- Summary Section -->
<table class="summary-wrapper">
    <tr>
        <td class="notes-cell">
            <div class="notes-box">
                <div class="notes-label">تێبینی :</div>
                <div>' . htmlspecialchars($case['notes'] ?? '') . '</div>
            </div>
        </td>
        <td class="totals-cell">
            <table class="totals-table">
                <tr>
                    <td>کۆی گشتی</td>
                    <td>' . number_format($case['total_amount'], 0) . '</td>
                </tr>
                <tr>
                    <td>% داشکاندن</td>
                    <td>' . $discountPercent . '</td>
                </tr>
                <tr>
                    <td>داشکاندن بە پارە</td>
                    <td>' . number_format($case['discount_amount'], 3) . '</td>
                </tr>
                <tr>
                    <td>دوای داشکاندن</td>
                    <td>' . number_format($afterDiscount, 0) . '</td>
                </tr>
                <tr class="row-blue">
                    <td>وەرگیراو</td>
                    <td>' . number_format($case['paid_amount'], 0) . '</td>
                </tr>';

if ($remaining > 0) {
    $html .= '
                <tr class="row-purple">
                    <td>ماوە</td>
                    <td>' . number_format($remaining, 0) . '</td>
                </tr>';
} else {
    $html .= '
                <tr class="row-green">
                    <td>ماوە</td>
                    <td>0</td>
                </tr>';
}

$html .= '
            </table>
        </td>
    </tr>
</table>';

// Set content and output PDF
$pdfService->setContent($html);
$filename = 'Receipt_Case_' . $caseId . '_' . date('Ymd_His') . '.pdf';
$pdfService->output($filename, 'I');
