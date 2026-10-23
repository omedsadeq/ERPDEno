<?php
/**
 * Export Debts to Excel/PDF
 * هەناردەکردنی قەرزەکان بۆ Excel/PDF
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Doctor isolation: this is a clinic-wide debtor export (an accounting function).
// A doctor must never see other doctors' patients' finances — deny outright.
// Doctors still see their own patients' debt in the patient/case views.
if ($auth->isDoctor() && !$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$exportType = $_GET['type'] ?? 'excel'; // excel or pdf

// Get all debtors.
// Correlated subqueries (not JOIN+GROUP BY) so a patient's folder totals are
// NOT multiplied by (#cases × #payments) — the old query inflated every total.
// X-ray debt is included so the export matches the on-screen debt figure.
$sql = "SELECT p.id, p.patient_code, p.full_name, p.phone, p.phone2,
        (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0)
         + COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.payment_status IN ('debt','debt_pending','debt_approved')), 0)) as total_debt,
        COALESCE((SELECT SUM(pf.total_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as total_amount,
        COALESCE((SELECT SUM(pf.paid_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as total_paid,
        COALESCE((SELECT SUM(pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as total_discount,
        (SELECT MAX(pay.payment_date) FROM payments pay
         INNER JOIN cases c ON pay.case_id = c.id
         INNER JOIN patient_folders pf ON c.folder_id = pf.id
         WHERE pf.patient_id = p.id AND pay.status = 'approved') as last_payment_date,
        (SELECT COUNT(*) FROM installment_plans ip
         INNER JOIN cases c ON ip.case_id = c.id
         INNER JOIN patient_folders pf2 ON c.folder_id = pf2.id
         WHERE pf2.patient_id = p.id AND ip.status = 'active') as has_installment_plan,
        (SELECT COUNT(*) FROM installment_schedule isch
         INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
         INNER JOIN cases c2 ON ip2.case_id = c2.id
         INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
         WHERE pf3.patient_id = p.id
         AND isch.status IN ('pending', 'partial')
         AND isch.due_date < CURDATE()) as overdue_count
        FROM patients p
        WHERE p.deleted_at IS NULL
        HAVING total_debt > 0
        ORDER BY total_debt DESC";

$debtors = $db->fetchAll($sql);

if ($exportType === 'excel') {
    // Export to CSV (Excel compatible)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="debts_report_' . date('Y-m-d') . '.csv"');

    // Add UTF-8 BOM for proper encoding
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Headers
    fputcsv($output, [
        'کۆد',
        'ناوی نەخۆش',
        'ژمارەی مۆبایل',
        'کۆی گشتی',
        'بڕی دراو',
        'بڕی ماوە (قەرز)',
        'جۆری قەرز',
        'دوایین پارەدان',
        'دۆخ'
    ]);

    // Data rows
    foreach ($debtors as $debtor) {
        $hasOverdue = $debtor['overdue_count'] > 0;
        $hasInstallment = $debtor['has_installment_plan'] > 0;
        $status = $hasOverdue ? 'دواکەوتوو' : 'چالاک';
        $debtType = $hasInstallment ? 'قیستی مانگانە' : 'قەرزی ئاسایی';
        $lastPayment = $debtor['last_payment_date'] ? date('Y/m/d', strtotime($debtor['last_payment_date'])) : '-';

        fputcsv($output, [
            $debtor['patient_code'],
            $debtor['full_name'],
            $debtor['phone'],
            number_format($debtor['total_amount']),
            number_format($debtor['total_paid']),
            number_format($debtor['total_debt']),
            $debtType,
            $lastPayment,
            $status
        ]);
    }

    fclose($output);

} else {
    // Export to PDF using InvoicePdfService
    require_once __DIR__ . '/../services/InvoicePdfService.php';

    // Use landscape orientation for debts report
    $pdfService = new InvoicePdfService(['orientation' => 'L']);
    $pdfService->setTitle('ڕاپۆرتی قەرز و قیستەکان');
    $pdfService->setAuthor($currentUser['full_name']);

    $totalDebt = array_sum(array_column($debtors, 'total_debt'));
    $totalAmount = array_sum(array_column($debtors, 'total_amount'));
    $totalPaid = array_sum(array_column($debtors, 'total_paid'));

    $html = '
    <!DOCTYPE html>
    <html dir="rtl">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: nrt, sans-serif; direction: rtl; }
            h1 { text-align: center; color: #f44336; margin-bottom: 5px; }
            .date { text-align: center; color: #666; font-size: 12px; margin-bottom: 20px; }
            .summary { margin: 20px 0; padding: 15px; background: #f5f5f5; border-radius: 5px; }
            .summary-row { display: flex; justify-content: space-between; margin: 5px 0; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th { background: #f44336; color: white; padding: 10px 5px; text-align: right; }
            td { padding: 8px 5px; border-bottom: 1px solid #ddd; text-align: right; }
            tr:hover { background: #fafafa; }
            .debt { color: #f44336; font-weight: bold; }
            .paid { color: #4caf50; font-weight: bold; }
            .overdue { background: #ffebee; }
        </style>
    </head>
    <body>
        <h1>ڕاپۆرتی قەرز و قیستەکان</h1>
        <div class="date">' . date('Y/m/d - H:i') . '</div>

        <div class="summary">
            <div class="summary-row">
                <strong>کۆی گشتی قەرزەکان:</strong>
                <strong style="color: #f44336;">' . number_format($totalDebt) . ' دینار</strong>
            </div>
            <div class="summary-row">
                <strong>کۆی بڕی دراو:</strong>
                <strong style="color: #4caf50;">' . number_format($totalPaid) . ' دینار</strong>
            </div>
            <div class="summary-row">
                <strong>ژمارەی قەرزداران:</strong>
                <strong>' . count($debtors) . ' نەخۆش</strong>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>کۆد</th>
                    <th>ناوی نەخۆش</th>
                    <th>مۆبایل</th>
                    <th>کۆی گشتی</th>
                    <th>بڕی دراو</th>
                    <th>بڕی ماوە</th>
                    <th>جۆر</th>
                    <th>دۆخ</th>
                </tr>
            </thead>
            <tbody>';

    $index = 1;
    foreach ($debtors as $debtor) {
        $hasOverdue = $debtor['overdue_count'] > 0;
        $hasInstallment = $debtor['has_installment_plan'] > 0;
        $status = $hasOverdue ? 'دواکەوتوو' : 'چالاک';
        $debtType = $hasInstallment ? 'قیست' : 'ئاسایی';
        $rowClass = $hasOverdue ? 'overdue' : '';

        $html .= '<tr class="' . $rowClass . '">
            <td>' . $index++ . '</td>
            <td>' . htmlspecialchars($debtor['patient_code']) . '</td>
            <td>' . htmlspecialchars($debtor['full_name']) . '</td>
            <td>' . htmlspecialchars($debtor['phone']) . '</td>
            <td>' . number_format($debtor['total_amount']) . '</td>
            <td class="paid">' . number_format($debtor['total_paid']) . '</td>
            <td class="debt">' . number_format($debtor['total_debt']) . '</td>
            <td>' . $debtType . '</td>
            <td>' . $status . '</td>
        </tr>';
    }

    $html .= '
            </tbody>
        </table>
    </body>
    </html>';

    // Set content and output PDF
    $pdfService->setContent($html);
    $pdfService->output('debts_report_' . date('Y-m-d') . '.pdf', 'D');
}
