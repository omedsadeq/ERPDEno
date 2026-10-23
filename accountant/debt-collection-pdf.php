<?php
/**
 * Debt Collection PDF Report
 * ڕاپۆرتی PDF ی کۆکردنەوەی قەرز
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/DebtCollection.php';
require_once __DIR__ . '/../services/InvoicePdfService.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->isAdmin() && !$auth->isAccountant() && !$auth->isDoctor()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$debtCollection = new DebtCollection();

// Apply same filters as the page
$search = trim($_GET['search'] ?? '');
$callFilter = $_GET['call_filter'] ?? '';
$sortBy = $_GET['sort'] ?? 'total_debt';
$sortDir = $_GET['dir'] ?? 'DESC';
$debtTypeFilter = $_GET['debt_type'] ?? '';
$installmentDueFilter = $_GET['installment_due'] ?? '';
$installmentDate = $_GET['installment_date'] ?? '';
$dueFilter = $_GET['due_filter'] ?? '';
$dueDate = $_GET['due_date'] ?? '';
$dueFrom = $_GET['due_from'] ?? '';
$dueTo = $_GET['due_to'] ?? '';

$isValidDate = function($date) {
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
};

$selectedDueFrom = '';
$selectedDueTo = '';
if ($dueFilter === 'today') {
    $selectedDueFrom = date('Y-m-d');
    $selectedDueTo = date('Y-m-d');
} elseif ($dueFilter === 'tomorrow') {
    $selectedDueFrom = date('Y-m-d', strtotime('+1 day'));
    $selectedDueTo = $selectedDueFrom;
} elseif ($dueFilter === 'week') {
    $selectedDueFrom = date('Y-m-d', strtotime('monday this week'));
    $selectedDueTo = date('Y-m-d', strtotime('sunday this week'));
} elseif ($dueFilter === 'custom_date' && $isValidDate($dueDate)) {
    $selectedDueFrom = $dueDate;
    $selectedDueTo = $dueDate;
} elseif ($dueFilter === 'range') {
    $selectedDueFrom = $isValidDate($dueFrom) ? $dueFrom : '';
    $selectedDueTo = $isValidDate($dueTo) ? $dueTo : '';
    if ($selectedDueFrom !== '' && $selectedDueTo === '') $selectedDueTo = $selectedDueFrom;
    if ($selectedDueTo !== '' && $selectedDueFrom === '') $selectedDueFrom = $selectedDueTo;
    if ($selectedDueFrom !== '' && $selectedDueTo !== '' && $selectedDueFrom > $selectedDueTo) {
        [$selectedDueFrom, $selectedDueTo] = [$selectedDueTo, $selectedDueFrom];
    }
}

$debtors = $debtCollection->getDebtors($search, $callFilter, $sortBy, $sortDir, $debtTypeFilter, $installmentDueFilter, $installmentDate, $selectedDueFrom, $selectedDueTo);
$stats = $debtCollection->getStatistics();
$callStatusLabels = DebtCollection::getCallStatusLabels();

$reportDate = date('Y/m/d');
$reportTime = date('H:i');

// Build HTML
$html = '
<style>
    body { font-family: nrt, sans-serif; direction: rtl; }
    h2 { text-align: center; color: #1a1a2e; margin-bottom: 5px; font-size: 18px; }
    .report-meta { text-align: center; font-size: 11px; color: #666; margin-bottom: 15px; }
    .stats-row { display: flex; justify-content: space-around; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 8px; padding: 10px; }
    .stat-item { text-align: center; }
    .stat-val { font-size: 16px; font-weight: bold; color: #1a1a2e; }
    .stat-lbl { font-size: 10px; color: #666; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 10px; }
    th { background: #f0f4f8; padding: 8px 6px; text-align: right; border: 1px solid #ddd; font-weight: 600; color: #333; font-size: 10px; }
    td { padding: 7px 6px; border: 1px solid #eee; vertical-align: middle; }
    tr:nth-child(even) { background: #fafafa; }
    .amount-red { color: #e74c3c; font-weight: bold; }
    .amount-green { color: #27ae60; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; color: #fff; }
    .no-data { text-align: center; padding: 40px; color: #999; font-size: 14px; }
    .footer-note { text-align: center; font-size: 9px; color: #999; margin-top: 15px; border-top: 1px solid #eee; padding-top: 8px; }
</style>

<h2>ڕاپۆرتی کۆکردنەوەی قەرز و پەیوەندیکردن</h2>
<div class="report-meta">
    بەروار: ' . $reportDate . ' &nbsp;|&nbsp; کات: ' . $reportTime . ' &nbsp;|&nbsp; ئامادەکار: ' . htmlspecialchars($currentUser['full_name']) . '
</div>

<table style="width:80%;margin:0 auto 15px;border:none;">
    <tr>
        <td style="border:1px solid #ddd;text-align:center;padding:8px;border-radius:4px;">
            <div class="stat-val">' . $stats['total_debtors'] . '</div>
            <div class="stat-lbl">کۆی قەرزداران</div>
        </td>
        <td style="border:1px solid #ddd;text-align:center;padding:8px;border-radius:4px;">
            <div class="stat-val">' . number_format($stats['total_debt']) . '</div>
            <div class="stat-lbl">کۆی قەرز</div>
        </td>
        <td style="border:1px solid #ddd;text-align:center;padding:8px;border-radius:4px;">
            <div class="stat-val">' . $stats['called_today'] . '</div>
            <div class="stat-lbl">پەیوەندی ئەمڕۆ</div>
        </td>
        <td style="border:1px solid #ddd;text-align:center;padding:8px;border-radius:4px;">
            <div class="stat-val">' . $stats['never_called'] . '</div>
            <div class="stat-lbl">هەرگیز پەیوەندی نەکراو</div>
        </td>
    </tr>
</table>';

if (empty($debtors)) {
    $html .= '<div class="no-data">هیچ قەرزدارێک نەدۆزرایەوە</div>';
} else {
    $html .= '
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>نەخۆش</th>
                <th>بەرواری پارەدان</th>
                <th>ژمارەی مۆبایل</th>
                <th>کۆی گشتی</th>
                <th>دراوە</th>
                <th>ماوەی قەرز</th>
                <th>دوایین پارەدان</th>
                <th>دۆخی پەیوەندی</th>
                <th>تێبینی</th>
            </tr>
        </thead>
        <tbody>';

    $totalDebtSum = 0;
    $totalPaidSum = 0;
    $totalAmountSum = 0;

    foreach ($debtors as $i => $debtor) {
        $totalDebtSum += $debtor['total_debt'];
        $totalPaidSum += $debtor['total_paid'];
        $totalAmountSum += $debtor['total_amount'];

        $callStatusLabel = '';
        if ($debtor['last_call_status']) {
            $callStatusLabel = $callStatusLabels[$debtor['last_call_status']] ?? $debtor['last_call_status'];
            if ($debtor['last_call_date']) {
                $callStatusLabel .= ' (' . date('m/d', strtotime($debtor['last_call_date'])) . ')';
            }
        } else {
            $callStatusLabel = 'پەیوەندی نەکراوە';
        }

        $lastPayment = $debtor['last_payment_date'] ? date('Y/m/d', strtotime($debtor['last_payment_date'])) : '—';
        $note = $debtor['last_call_note'] ? mb_substr($debtor['last_call_note'], 0, 40) . (mb_strlen($debtor['last_call_note']) > 40 ? '...' : '') : '—';
        $normalDueDate = $debtor['next_normal_due_date'] ?? null;
        $nextInstallmentDate = $debtor['next_installment_due_date'] ?? null;
        $nextDueDate = $debtor['next_due_date'] ?? ($normalDueDate ?: $nextInstallmentDate);
        $dueSource = 'قەرزی ئاسایی';
        if ($nextInstallmentDate && (!$normalDueDate || $nextInstallmentDate <= $normalDueDate)) {
            $dueSource = 'قیست';
        }
        $dueLabel = $nextDueDate
            ? date('Y/m/d', strtotime($nextDueDate)) . '<br><span style="font-size:9px;color:#666;">' . $dueSource . '</span>'
            : '—';

        $html .= '
            <tr>
                <td>' . ($i + 1) . '</td>
                <td><strong>' . htmlspecialchars($debtor['patient_name']) . '</strong><br><span style="font-size:9px;color:#888;">' . htmlspecialchars($debtor['patient_code']) . '</span></td>
                <td>' . $dueLabel . '</td>
                <td style="direction:ltr;text-align:right;">' . htmlspecialchars($debtor['phone'] ?? '') . '</td>
                <td>' . number_format($debtor['total_amount']) . '</td>
                <td class="amount-green">' . number_format($debtor['total_paid']) . '</td>
                <td class="amount-red">' . number_format($debtor['total_debt']) . '</td>
                <td>' . $lastPayment . '</td>
                <td style="font-size:10px;">' . $callStatusLabel . '</td>
                <td style="font-size:10px;">' . htmlspecialchars($note) . '</td>
            </tr>';
    }

    $html .= '
            <tr style="background:#f0f4f8;font-weight:bold;">
                <td colspan="4" style="text-align:center;">کۆی گشتی</td>
                <td>' . number_format($totalAmountSum) . '</td>
                <td class="amount-green">' . number_format($totalPaidSum) . '</td>
                <td class="amount-red">' . number_format($totalDebtSum) . '</td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>';
}

$html .= '<div class="footer-note">ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیکی دروستکراوە لە سیستەمی ' . APP_NAME . '</div>';

// Generate PDF
$pdfService = new InvoicePdfService(['orientation' => 'L', 'format' => 'A4']);
$pdfService->setContent($html);

$filename = 'debt-collection-report-' . date('Y-m-d') . '.pdf';
$pdfService->output($filename, 'I');
