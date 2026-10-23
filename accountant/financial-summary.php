<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

$authGate = new Auth();
$authGate->requireLogin();
if (!$authGate->isAccountant() && !$authGate->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$pageTitle = 'تۆماری دارایی ڕۆژانە';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'],
    ['title' => 'تۆماری دارایی ڕۆژانە'],
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/XrayManager.php';

$xrayManager = new XrayManager();

function fsValidDate($value)
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $value);
    return $parsed && $parsed->format('Y-m-d') === $value;
}

function fsMoney($value)
{
    return number_format((float)$value, 0);
}

function fsEsc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fsExpenseTotal($db, $dateFrom, $dateTo)
{
    $params = ['df' => $dateFrom, 'dt' => $dateTo];
    $total = 0.0;
    if ($db->tableExists('expenses')) {
        $row = $db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM expenses
             WHERE expense_date BETWEEN :df AND :dt
               AND (status IS NULL OR status <> 'rejected')",
            $params
        );
        $total += (float)($row['total'] ?? 0);
    }
    foreach (['expenses_general', 'expenses_lab', 'expenses_store'] as $table) {
        if (!$db->tableExists($table)) {
            continue;
        }
        $row = $db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM {$table}
             WHERE expense_date BETWEEN :df AND :dt",
            $params
        );
        $total += (float)($row['total'] ?? 0);
    }
    return $total;
}

/**
 * One expense table's total for the range. Kept separate from
 * fsExpenseTotal() above so the sheet can show تاقیگە and کۆگا on their own
 * lines the way the doctor's daily sheet does, without either one being able
 * to drift from the grand total (the third line is the remainder).
 */
function fsExpenseByTable($db, $table, $dateFrom, $dateTo)
{
    if (!$db->tableExists($table)) {
        return 0.0;
    }
    $row = $db->fetch(
        "SELECT COALESCE(SUM(amount), 0) AS total
         FROM {$table}
         WHERE expense_date BETWEEN :df AND :dt",
        ['df' => $dateFrom, 'dt' => $dateTo]
    );
    return (float)($row['total'] ?? 0);
}

/** Minutes as H:MM — the same clock format the doctor's daily sheet prints. */
function fsHours($minutes)
{
    if ($minutes === null) {
        return '—';
    }
    $minutes = (int)round($minutes);
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function fsMethodLabel($method)
{
    $labels = [
        'cash' => 'نەقد',
        'card' => 'کارت',
        'credit_card' => 'کارت',
        'transfer' => 'گواستنەوە',
        'bank_transfer' => 'گواستنەوە',
        'advance' => 'لە ئامانەت',
        'other' => 'هی تر',
        'check' => 'چەک',
    ];
    return $labels[$method] ?? 'نەقد';
}

$period = isset($_GET['period']) ? (string)$_GET['period'] : 'today';
$dateFromInput = isset($_GET['date_from']) ? (string)$_GET['date_from'] : '';
$dateToInput = isset($_GET['date_to']) ? (string)$_GET['date_to'] : '';
switch ($period) {
    case 'yesterday':
        $dateFrom = $dateTo = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'week':
        $dateFrom = date('N') == 6 ? date('Y-m-d') : date('Y-m-d', strtotime('last saturday'));
        $dateTo = date('Y-m-d');
        break;
    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-d');
        break;
    case 'custom':
        if (fsValidDate($dateFromInput) && fsValidDate($dateToInput)) {
            $dateFrom = $dateFromInput;
            $dateTo = $dateToInput;
            if ($dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }
            break;
        }
        $period = 'today';
        $dateFrom = $dateTo = date('Y-m-d');
        break;
    case 'today':
    default:
        $period = 'today';
        $dateFrom = $dateTo = date('Y-m-d');
        break;
}

$rangeStart = $dateFrom . ' 00:00:00';
$rangeEnd = date('Y-m-d 00:00:00', strtotime($dateTo . ' +1 day'));
$dateParams = ['df' => $dateFrom, 'dt' => $dateTo];
$timeParams = ['range_start' => $rangeStart, 'range_end' => $rangeEnd];

// Work uses work_date, including sent invoices that still await approval.
$workSummary = $db->fetch(
    "SELECT
        COUNT(*) AS submitted_count,
        COALESCE(SUM(GREATEST(total_amount - discount_amount, 0)), 0) AS submitted_net,
        SUM(CASE WHEN accounting_status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN accounting_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        COALESCE(SUM(CASE WHEN accounting_status = 'approved'
            THEN GREATEST(total_amount - discount_amount, 0) ELSE 0 END), 0) AS approved_net
     FROM case_invoices
     WHERE work_date BETWEEN :df AND :dt
       AND accounting_status IN ('sent', 'approved')",
    $dateParams
) ?: [];

// Chair-side money becomes valid only when the accountant approves the bundle.
// دراوی پێشوو (prior_paid_amount, historical cases) lowers new_debt but is never
// part of chair_received: it is not cash collected in this period.
$approvedInvoiceSummary = $db->fetch(
    "SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(total_amount), 0) AS gross,
        COALESCE(SUM(discount_amount), 0) AS discount,
        COALESCE(SUM(GREATEST(total_amount - discount_amount, 0)), 0) AS net,
        COALESCE(SUM(LEAST(
            GREATEST(total_amount - discount_amount, 0),
            GREATEST(draft_received_amount, 0)
        )), 0) AS chair_received,
        COALESCE(SUM(GREATEST(
            GREATEST(total_amount - discount_amount, 0) -
            LEAST(GREATEST(total_amount - discount_amount, 0), GREATEST(draft_received_amount, 0))
            - GREATEST(COALESCE(prior_paid_amount, 0), 0),
            0
        )), 0) AS new_debt,
        SUM(CASE WHEN payment_intent = 'cash' THEN 1 ELSE 0 END) AS cash_count,
        COALESCE(SUM(CASE WHEN payment_intent = 'cash'
            THEN GREATEST(total_amount - discount_amount, 0) ELSE 0 END), 0) AS cash_net,
        SUM(CASE WHEN payment_intent = 'debt' THEN 1 ELSE 0 END) AS debt_count,
        COALESCE(SUM(CASE WHEN payment_intent = 'debt'
            THEN GREATEST(total_amount - discount_amount, 0) ELSE 0 END), 0) AS debt_net,
        SUM(CASE WHEN payment_intent = 'installment' THEN 1 ELSE 0 END) AS installment_count,
        COALESCE(SUM(CASE WHEN payment_intent = 'installment'
            THEN GREATEST(total_amount - discount_amount, 0) ELSE 0 END), 0) AS installment_net,
        SUM(CASE WHEN payment_intent IS NULL OR payment_intent = '' THEN 1 ELSE 0 END) AS unspecified_count,
        COALESCE(SUM(CASE WHEN payment_intent IS NULL OR payment_intent = ''
            THEN GREATEST(total_amount - discount_amount, 0) ELSE 0 END), 0) AS unspecified_net
     FROM case_invoices
     WHERE accounting_status = 'approved'
       AND approved_at >= :range_start
       AND approved_at < :range_end",
    $timeParams
) ?: [];

$paymentSummary = $db->fetch(
    "SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(amount), 0) AS total,
        SUM(CASE WHEN payment_type <> 'installment' THEN 1 ELSE 0 END) AS debt_count,
        COALESCE(SUM(CASE WHEN payment_type <> 'installment' THEN amount ELSE 0 END), 0) AS debt_total,
        SUM(CASE WHEN payment_type = 'installment' THEN 1 ELSE 0 END) AS installment_count,
        COALESCE(SUM(CASE WHEN payment_type = 'installment' THEN amount ELSE 0 END), 0) AS installment_total
     FROM payments
     WHERE status = 'approved'
       AND payment_date >= :range_start
       AND payment_date < :range_end",
    $timeParams
) ?: [];

$paymentMethods = [
    'debt_payment' => ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'other' => 0.0, 'count' => 0],
    'installment' => ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'other' => 0.0, 'count' => 0],
];
$advanceApplied = 0.0;
$paymentMethodRows = $db->fetchAll(
    "SELECT
        CASE WHEN payment_type = 'installment' THEN 'installment' ELSE 'debt_payment' END AS source_key,
        payment_method,
        COUNT(*) AS row_count,
        COALESCE(SUM(amount), 0) AS total
     FROM payments
     WHERE status = 'approved'
       AND payment_date >= :range_start
       AND payment_date < :range_end
     GROUP BY source_key, payment_method",
    $timeParams
);
foreach ($paymentMethodRows as $row) {
    $sourceKey = $row['source_key'];
    if ($row['payment_method'] === 'advance') {
        $advanceApplied += (float)$row['total'];
        $paymentMethods[$sourceKey]['count'] += (int)$row['row_count'];
        continue;
    }
    $method = in_array($row['payment_method'], ['cash', 'card', 'transfer', 'other'], true)
        ? $row['payment_method']
        : 'other';
    $paymentMethods[$sourceKey][$method] += (float)$row['total'];
    $paymentMethods[$sourceKey]['count'] += (int)$row['row_count'];
}

$xrayCashWhere = '';
if ($db->tableExists('patient_xray_debts')) {
    $xrayCashWhere = ' AND NOT EXISTS (
        SELECT 1 FROM patient_xray_debts pxd WHERE pxd.request_id = xr.id
    )';
}
$xrayCashSummary = $db->fetch(
    "SELECT COUNT(*) AS row_count, COALESCE(SUM(xr.final_amount), 0) AS total
     FROM xray_requests xr
     WHERE xr.payment_status = 'paid'
       {$xrayCashWhere}
       AND COALESCE(xr.paid_at, xr.created_at) >= :range_start
       AND COALESCE(xr.paid_at, xr.created_at) < :range_end",
    $timeParams
) ?: [];

$xrayDebtPaymentSummary = ['row_count' => 0, 'total' => 0];
$xrayDebtMethods = ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'other' => 0.0, 'count' => 0];
if ($db->tableExists('xray_debt_payments')) {
    $xrayDebtPaymentSummary = $db->fetch(
        "SELECT COUNT(*) AS row_count, COALESCE(SUM(amount), 0) AS total
         FROM xray_debt_payments
         WHERE created_at >= :range_start AND created_at < :range_end",
        $timeParams
    ) ?: $xrayDebtPaymentSummary;
    $rows = $db->fetchAll(
        "SELECT payment_method, COUNT(*) AS row_count, COALESCE(SUM(amount), 0) AS total
         FROM xray_debt_payments
         WHERE created_at >= :range_start AND created_at < :range_end
         GROUP BY payment_method",
        $timeParams
    );
    foreach ($rows as $row) {
        $method = in_array($row['payment_method'], ['cash', 'card', 'transfer', 'other'], true)
            ? $row['payment_method']
            : 'other';
        $xrayDebtMethods[$method] += (float)$row['total'];
        $xrayDebtMethods['count'] += (int)$row['row_count'];
    }
}

$xrayNewDebt = ['row_count' => 0, 'total' => 0];
if ($db->tableExists('patient_xray_debts')) {
    $xrayNewDebt = $db->fetch(
        "SELECT COUNT(DISTINCT request_id) AS row_count, COALESCE(SUM(amount), 0) AS total
         FROM patient_xray_debts
         WHERE created_at >= :range_start AND created_at < :range_end",
        $timeParams
    ) ?: $xrayNewDebt;
}

// Custody is informational, outside income/expense/method totals. When applied,
// the canonical approved payment above recognizes it once on its approval day.
$advanceSummary = ['row_count' => 0, 'total' => 0, 'refunded' => 0];
$advanceMethods = ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'other' => 0.0];
$advanceUnallocated = 0.0;
if ($db->tableExists('patient_advances')) {
    $advanceSummary = $db->fetch(
        "SELECT COUNT(*) AS row_count, COALESCE(SUM(amount),0) AS total
         FROM patient_advances
         WHERE received_at >= :range_start AND received_at < :range_end",
        $timeParams
    ) ?: $advanceSummary;
    $advanceSummary['refunded'] = (float)($db->fetch(
        "SELECT COALESCE(SUM(refunded_amount),0) AS total
         FROM patient_advances
         WHERE refunded_at >= :range_start AND refunded_at < :range_end",
        $timeParams
    )['total'] ?? 0);
    foreach ($db->fetchAll(
        "SELECT payment_method, COALESCE(SUM(amount),0) AS total
         FROM patient_advances
         WHERE received_at >= :range_start AND received_at < :range_end
         GROUP BY payment_method",
        $timeParams
    ) as $row) {
        $method = in_array($row['payment_method'], ['cash', 'card', 'transfer', 'other'], true)
            ? $row['payment_method'] : 'other';
        $advanceMethods[$method] += (float)$row['total'];
    }
    // What the clinic is still holding for patients across all time — money
    // already in the drawer that has not been earned yet. Not period-bound on
    // purpose: it is a liability position, not a period's takings.
    $advanceUnallocated = (float)($db->fetch(
        "SELECT GREATEST(0,
                  COALESCE((SELECT SUM(amount - refunded_amount) FROM patient_advances),0)
                - COALESCE((SELECT SUM(amount) FROM patient_advance_allocations WHERE reversed_at IS NULL),0)
                ) AS total"
    )['total'] ?? 0);
}
$advanceReceived = (float)($advanceSummary['total'] ?? 0) - (float)($advanceSummary['refunded'] ?? 0);

$totalExpenses = fsExpenseTotal($db, $dateFrom, $dateTo);
$chairReceived = (float)($approvedInvoiceSummary['chair_received'] ?? 0);
$regularPayments = (float)($paymentSummary['debt_total'] ?? 0);
$installmentPayments = (float)($paymentSummary['installment_total'] ?? 0);
$xrayCash = (float)($xrayCashSummary['total'] ?? 0);
$xrayDebtCollected = (float)($xrayDebtPaymentSummary['total'] ?? 0);
$totalCollected = $chairReceived + $regularPayments + $installmentPayments + $xrayCash + $xrayDebtCollected;
$totalNewDebt = (float)($approvedInvoiceSummary['new_debt'] ?? 0) + (float)($xrayNewDebt['total'] ?? 0);
$netCashFlow = $totalCollected - $totalExpenses;

$cashCollected = $chairReceived
    + $paymentMethods['debt_payment']['cash']
    + $paymentMethods['installment']['cash']
    + $xrayCash
    + $xrayDebtMethods['cash'];
$cardCollected = $paymentMethods['debt_payment']['card']
    + $paymentMethods['installment']['card']
    + $xrayDebtMethods['card'];
$transferCollected = $paymentMethods['debt_payment']['transfer']
    + $paymentMethods['installment']['transfer']
    + $xrayDebtMethods['transfer'];
$otherCollected = $paymentMethods['debt_payment']['other']
    + $paymentMethods['installment']['other']
    + $xrayDebtMethods['other'];

$outstandingTreatmentDebt = 0.0;
if ($db->tableExists('v_case_financial_summary')) {
    $row = $db->fetch(
        "SELECT COALESCE(SUM(fs.balance), 0) AS total
         FROM v_case_financial_summary fs
         JOIN cases c ON c.id = fs.case_id
         WHERE fs.balance > 0 AND c.status <> 'closed'"
    );
    $outstandingTreatmentDebt = (float)($row['total'] ?? 0);
}
$outstandingXrayDebt = $xrayManager->getOutstandingDebt();
$totalOutstandingDebt = $outstandingTreatmentDebt + $outstandingXrayDebt;

// Statistics by doctor: work uses work_date, collections use transaction dates.
$doctorStats = [];
// $externalDoctorId is for x-ray referrals from OUTSIDE doctors: those rows
// carry the staff member who typed the request in requested_by, so keying them
// by that id would credit reception with the referral (and merge every outside
// doctor the same receptionist entered into one row). They get their own key.
$ensureDoctor = static function ($doctorId, $doctorName, $externalDoctorId = 0) use (&$doctorStats) {
    $key = (int)$externalDoctorId > 0 ? 'x' . (int)$externalDoctorId : (string)(int)$doctorId;
    if (!isset($doctorStats[$key])) {
        $doctorStats[$key] = [
            'doctor_name' => $doctorName ?: 'نەدیاریکراو',
            'work_invoices' => 0,
            'approved_work_invoices' => 0,
            'treatment_quantity' => 0.0,
            'xray_count' => 0,
            'work_net' => 0.0,
            'approved_work_net' => 0.0,
            'chair_collected' => 0.0,
            'debt_payments' => 0.0,
            'installments' => 0.0,
            'total_collected' => 0.0,
        ];
    }
    return $key;
};

$doctorWorkRows = $db->fetchAll(
    "SELECT
        COALESCE(ci.doctor_id, pf.doctor_id, 0) AS doctor_id,
        COALESCE(u.full_name, 'نەدیاریکراو') AS doctor_name,
        COUNT(*) AS work_invoices,
        SUM(CASE WHEN ci.accounting_status = 'approved' THEN 1 ELSE 0 END) AS approved_work_invoices,
        COALESCE(SUM(GREATEST(ci.total_amount - ci.discount_amount, 0)), 0) AS work_net,
        COALESCE(SUM(CASE WHEN ci.accounting_status = 'approved'
            THEN GREATEST(ci.total_amount - ci.discount_amount, 0) ELSE 0 END), 0) AS approved_work_net
     FROM case_invoices ci
     JOIN cases c ON c.id = ci.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     LEFT JOIN users u ON u.id = COALESCE(ci.doctor_id, pf.doctor_id)
     WHERE ci.work_date BETWEEN :df AND :dt
       AND ci.accounting_status IN ('sent', 'approved')
     GROUP BY COALESCE(ci.doctor_id, pf.doctor_id, 0), u.full_name",
    $dateParams
);
foreach ($doctorWorkRows as $row) {
    $key = $ensureDoctor($row['doctor_id'], $row['doctor_name']);
    $doctorStats[$key]['work_invoices'] = (int)$row['work_invoices'];
    $doctorStats[$key]['approved_work_invoices'] = (int)$row['approved_work_invoices'];
    $doctorStats[$key]['work_net'] = (float)$row['work_net'];
    $doctorStats[$key]['approved_work_net'] = (float)$row['approved_work_net'];
}

$doctorTreatmentRows = $db->fetchAll(
    "SELECT
        COALESCE(ci.doctor_id, pf.doctor_id, 0) AS doctor_id,
        COALESCE(u.full_name, 'نەدیاریکراو') AS doctor_name,
        COALESCE(SUM(ct.quantity), 0) AS treatment_quantity
     FROM case_treatments ct
     JOIN case_invoices ci ON ci.id = ct.invoice_id
     JOIN cases c ON c.id = ci.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     LEFT JOIN users u ON u.id = COALESCE(ci.doctor_id, pf.doctor_id)
     WHERE ci.work_date BETWEEN :df AND :dt
       AND ci.accounting_status IN ('sent', 'approved')
       AND ct.status <> 'cancelled'
     GROUP BY COALESCE(ci.doctor_id, pf.doctor_id, 0), u.full_name",
    $dateParams
);
foreach ($doctorTreatmentRows as $row) {
    $key = $ensureDoctor($row['doctor_id'], $row['doctor_name']);
    $doctorStats[$key]['treatment_quantity'] = (float)$row['treatment_quantity'];
}

$doctorChairRows = $db->fetchAll(
    "SELECT
        COALESCE(ci.doctor_id, pf.doctor_id, 0) AS doctor_id,
        COALESCE(u.full_name, 'نەدیاریکراو') AS doctor_name,
        COALESCE(SUM(LEAST(
            GREATEST(ci.total_amount - ci.discount_amount, 0),
            GREATEST(ci.draft_received_amount, 0)
        )), 0) AS total
     FROM case_invoices ci
     JOIN cases c ON c.id = ci.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     LEFT JOIN users u ON u.id = COALESCE(ci.doctor_id, pf.doctor_id)
     WHERE ci.accounting_status = 'approved'
       AND ci.approved_at >= :range_start AND ci.approved_at < :range_end
     GROUP BY COALESCE(ci.doctor_id, pf.doctor_id, 0), u.full_name",
    $timeParams
);
foreach ($doctorChairRows as $row) {
    $key = $ensureDoctor($row['doctor_id'], $row['doctor_name']);
    $doctorStats[$key]['chair_collected'] = (float)$row['total'];
}

$doctorPaymentRows = $db->fetchAll(
    "SELECT
        COALESCE(ci.doctor_id, pf.doctor_id, 0) AS doctor_id,
        COALESCE(u.full_name, 'نەدیاریکراو') AS doctor_name,
        COALESCE(SUM(CASE WHEN py.payment_type <> 'installment'
            THEN pa.allocated_amount ELSE 0 END), 0) AS debt_payments,
        COALESCE(SUM(CASE WHEN py.payment_type = 'installment'
            THEN pa.allocated_amount ELSE 0 END), 0) AS installments
     FROM payments py
     JOIN payment_allocations pa ON pa.payment_id = py.id
     JOIN case_invoices ci ON ci.id = pa.invoice_id
     JOIN cases c ON c.id = ci.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     LEFT JOIN users u ON u.id = COALESCE(ci.doctor_id, pf.doctor_id)
     WHERE py.status = 'approved'
       AND py.payment_date >= :range_start AND py.payment_date < :range_end
     GROUP BY COALESCE(ci.doctor_id, pf.doctor_id, 0), u.full_name",
    $timeParams
);
foreach ($doctorPaymentRows as $row) {
    $key = $ensureDoctor($row['doctor_id'], $row['doctor_name']);
    $doctorStats[$key]['debt_payments'] = (float)$row['debt_payments'];
    $doctorStats[$key]['installments'] = (float)$row['installments'];
}

// ژمارەی ئێکسڕەی — HOW MANY x-rays the doctor sent in the period, next to the
// other work counters. A count, never money: x-ray is the centre's own
// department, so its fees are the ئیکسرەی row at the foot of the table and
// never part of a doctor's کۆی وەرگیراو (the daily summary follows the same
// rule). Cash, debt or free makes no difference to the count. Counted on
// created_at, the request's own work date, the same basis the invoice /
// treatment work columns use — not on when the money moved.
$doctorXrayCountRows = $db->fetchAll(
    "SELECT
        COALESCE(xr.requested_by, 0) AS doctor_id,
        COALESCE(xr.external_doctor_id, 0) AS external_doctor_id,
        COALESCE(xed.name, u.full_name, 'نەدیاریکراو') AS doctor_name,
        COUNT(*) AS xray_count
     FROM xray_requests xr
     LEFT JOIN users u ON u.id = xr.requested_by
     LEFT JOIN xray_external_doctors xed ON xed.id = xr.external_doctor_id
     WHERE xr.created_at >= :range_start AND xr.created_at < :range_end
       AND COALESCE(xr.request_status, '') <> 'cancelled'
       AND COALESCE(xr.status, '') <> 'cancelled'
     GROUP BY COALESCE(xr.requested_by, 0), COALESCE(xr.external_doctor_id, 0), doctor_name",
    $timeParams
);
foreach ($doctorXrayCountRows as $row) {
    $key = $ensureDoctor($row['doctor_id'], $row['doctor_name'], $row['external_doctor_id']);
    $doctorStats[$key]['xray_count'] = (int)$row['xray_count'];
}

// A doctor's کۆی وەرگیراو is his treatment money only — x-ray fees are the
// centre's and are counted on the ئیکسرەی row, not here.
foreach ($doctorStats as &$doctorRow) {
    $doctorRow['total_collected'] = $doctorRow['chair_collected']
        + $doctorRow['debt_payments']
        + $doctorRow['installments'];
}
unset($doctorRow);
uasort($doctorStats, static function ($a, $b) {
    if ($a['work_net'] == $b['work_net']) {
        return $b['total_collected'] <=> $a['total_collected'];
    }
    return $b['work_net'] <=> $a['work_net'];
});

$treatmentStats = $db->fetchAll(
    "SELECT
        COALESCE(NULLIF(ct.treatment_name, ''), 'چارەسەری نەدیاریکراو') AS treatment_name,
        COUNT(DISTINCT ci.id) AS invoice_count,
        COUNT(ct.id) AS line_count,
        COALESCE(SUM(ct.quantity), 0) AS quantity,
        COALESCE(SUM(CASE WHEN ci.total_amount > 0
            THEN ct.final_price * GREATEST(ci.total_amount - ci.discount_amount, 0) / ci.total_amount
            ELSE ct.final_price END), 0) AS submitted_net,
        COALESCE(SUM(CASE WHEN ci.accounting_status = 'approved' THEN
            CASE WHEN ci.total_amount > 0
                THEN ct.final_price * GREATEST(ci.total_amount - ci.discount_amount, 0) / ci.total_amount
                ELSE ct.final_price END ELSE 0 END), 0) AS approved_net,
        COALESCE(SUM(CASE WHEN ci.accounting_status = 'sent' THEN
            CASE WHEN ci.total_amount > 0
                THEN ct.final_price * GREATEST(ci.total_amount - ci.discount_amount, 0) / ci.total_amount
                ELSE ct.final_price END ELSE 0 END), 0) AS awaiting_net
     FROM case_treatments ct
     JOIN case_invoices ci ON ci.id = ct.invoice_id
     WHERE ci.work_date BETWEEN :df AND :dt
       AND ci.accounting_status IN ('sent', 'approved')
       AND ct.status <> 'cancelled'
     GROUP BY COALESCE(NULLIF(ct.treatment_name, ''), 'چارەسەری نەدیاریکراو')
     ORDER BY submitted_net DESC, treatment_name ASC",
    $dateParams
);

// Detail cashbook contains validated money only.
$cashbookRows = [];
$invoiceReceiptRows = $db->fetchAll(
    "SELECT ci.approved_at AS transaction_date, ci.invoice_no AS reference_no,
            pt.full_name AS patient_name,
            COALESCE(u.full_name, 'نەدیاریکراو') AS doctor_name,
            LEAST(GREATEST(ci.total_amount - ci.discount_amount, 0),
                  GREATEST(ci.draft_received_amount, 0)) AS amount
     FROM case_invoices ci
     JOIN cases c ON c.id = ci.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     JOIN patients pt ON pt.id = pf.patient_id
     LEFT JOIN users u ON u.id = COALESCE(ci.doctor_id, pf.doctor_id)
     WHERE ci.accounting_status = 'approved'
       AND ci.draft_received_amount > 0
       AND ci.approved_at >= :range_start AND ci.approved_at < :range_end",
    $timeParams
);
foreach ($invoiceReceiptRows as $row) {
    $cashbookRows[] = [
        'transaction_date' => $row['transaction_date'],
        'source' => 'پارەی ناو وەسڵ',
        'reference_no' => $row['reference_no'],
        'patient_name' => $row['patient_name'],
        'doctor_name' => $row['doctor_name'],
        'method' => 'نەقد',
        'amount' => (float)$row['amount'],
    ];
}

$paymentDetailRows = $db->fetchAll(
    "SELECT py.payment_date AS transaction_date, py.payment_number AS reference_no,
            py.payment_type, py.payment_method, py.amount,
            pt.full_name AS patient_name,
            COALESCE(du.full_name, 'نەدیاریکراو') AS doctor_name
     FROM payments py
     JOIN cases c ON c.id = py.case_id
     JOIN patient_folders pf ON pf.id = c.folder_id
     JOIN patients pt ON pt.id = pf.patient_id
     LEFT JOIN users du ON du.id = pf.doctor_id
     WHERE py.status = 'approved'
       AND py.payment_date >= :range_start AND py.payment_date < :range_end",
    $timeParams
);
foreach ($paymentDetailRows as $row) {
    $cashbookRows[] = [
        'transaction_date' => $row['transaction_date'],
        'source' => $row['payment_type'] === 'installment' ? 'پارەی قیست' : 'دانەوەی قەرز',
        'reference_no' => $row['reference_no'],
        'patient_name' => $row['patient_name'],
        'doctor_name' => $row['doctor_name'],
        'method' => fsMethodLabel($row['payment_method']),
        'amount' => (float)$row['amount'],
    ];
}

$xrayCashDetailRows = $db->fetchAll(
    "SELECT COALESCE(xr.paid_at, xr.created_at) AS transaction_date,
            xr.request_number AS reference_no, xr.final_amount AS amount,
            pt.full_name AS patient_name,
            COALESCE(xed.name, u.full_name, 'نەدیاریکراو') AS doctor_name
     FROM xray_requests xr
     JOIN patients pt ON pt.id = xr.patient_id
     LEFT JOIN users u ON u.id = xr.requested_by
     LEFT JOIN xray_external_doctors xed ON xed.id = xr.external_doctor_id
     WHERE xr.payment_status = 'paid'
       {$xrayCashWhere}
       AND COALESCE(xr.paid_at, xr.created_at) >= :range_start
       AND COALESCE(xr.paid_at, xr.created_at) < :range_end",
    $timeParams
);
// X-ray money is the centre's: the cash book names the centre, and the doctor
// who sent the patient only as information.
foreach ($xrayCashDetailRows as $row) {
    $cashbookRows[] = [
        'transaction_date' => $row['transaction_date'],
        'source' => 'ئیکسرەی نەقد',
        'reference_no' => $row['reference_no'],
        'patient_name' => $row['patient_name'],
        'doctor_name' => 'سەنتەر',
        'sent_by' => $row['doctor_name'],
        'method' => 'نەقد',
        'amount' => (float)$row['amount'],
    ];
}

if ($db->tableExists('xray_debt_payments') && $db->tableExists('patient_xray_debts')) {
    $xrayDebtDetailRows = $db->fetchAll(
        "SELECT xdp.created_at AS transaction_date, xr.request_number AS reference_no,
                xdp.amount, xdp.payment_method, pt.full_name AS patient_name,
                COALESCE(xed.name, u.full_name, 'نەدیاریکراو') AS doctor_name
         FROM xray_debt_payments xdp
         JOIN patient_xray_debts pxd ON pxd.id = xdp.debt_id
         JOIN xray_requests xr ON xr.id = pxd.request_id
         JOIN patients pt ON pt.id = xr.patient_id
         LEFT JOIN users u ON u.id = xr.requested_by
         LEFT JOIN xray_external_doctors xed ON xed.id = xr.external_doctor_id
         WHERE xdp.created_at >= :range_start AND xdp.created_at < :range_end",
        $timeParams
    );
    foreach ($xrayDebtDetailRows as $row) {
        $cashbookRows[] = [
            'transaction_date' => $row['transaction_date'],
            'source' => 'دانەوەی قەرزی ئیکسرە',
            'reference_no' => $row['reference_no'],
            'patient_name' => $row['patient_name'],
            'doctor_name' => 'سەنتەر',
            'sent_by' => $row['doctor_name'],
            'method' => fsMethodLabel($row['payment_method']),
            'amount' => (float)$row['amount'],
        ];
    }
}
usort($cashbookRows, static function ($a, $b) {
    return strcmp($b['transaction_date'], $a['transaction_date']);
});

// ── Period navigation ────────────────────────────────────────────────
// Shifts the WHOLE range by its own length, so one day steps a day and a
// month steps a month's worth of days — the accountant's "what did the
// previous period look like" without re-picking two dates.
$rangeDays = max(1, (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1);
$prevFrom = date('Y-m-d', strtotime($dateFrom . ' -' . $rangeDays . ' day'));
$prevTo   = date('Y-m-d', strtotime($dateTo . ' -' . $rangeDays . ' day'));
$nextFrom = date('Y-m-d', strtotime($dateFrom . ' +' . $rangeDays . ' day'));
$nextTo   = date('Y-m-d', strtotime($dateTo . ' +' . $rangeDays . ' day'));
$rangeUrl = static function ($from, $to) {
    return '?period=custom&date_from=' . rawurlencode($from) . '&date_to=' . rawurlencode($to);
};

// The running money statement — the same shape as the doctor's daily sheet:
// one column, top to bottom, every line adding up to the one below it.
$approvedGross = (float)($approvedInvoiceSummary['gross'] ?? 0);
$approvedDiscount = (float)($approvedInvoiceSummary['discount'] ?? 0);
$approvedNet = (float)($approvedInvoiceSummary['net'] ?? 0);
$xrayCollected = $xrayCash + $xrayDebtCollected;
$oldMoneyBack = $regularPayments + $installmentPayments + $xrayDebtCollected;

// Treatment money only — x-ray is settled apart and gets its own three lines,
// exactly as it does on doctors/daily-summary.php.
$treatmentCash = $chairReceived + $regularPayments + $installmentPayments;
$treatmentNewDebt = (float)($approvedInvoiceSummary['new_debt'] ?? 0);

// خەرجی split the way the doctor's sheet splits it. The two named tables are
// read directly; whatever else fsExpenseTotal() counted (the generic
// `expenses`/`expenses_general` tables) becomes the third line, so the three
// lines always add back up to $totalExpenses and can never disagree with the
// totals elsewhere on this page.
$labExpense = fsExpenseByTable($db, 'expenses_lab', $dateFrom, $dateTo);
$storeExpense = fsExpenseByTable($db, 'expenses_store', $dateFrom, $dateTo);
$otherExpenses = max(0.0, $totalExpenses - $labExpense - $storeExpense);
$netAfterExpenses = $treatmentCash - $totalExpenses;

// ── ئیکسرە — the period's requests as one cohort: کۆی = دراو + ماوە ──
// Same rule as doctors/daily-summary.php (a paid request counts in full; a
// request with a debt row counts what is no longer open on it, which covers a
// part-cash settlement); free requests carry no money and are excluded.
$xrayFeeSql = 'COALESCE(NULLIF(xr.final_amount, 0), xr.amount)';
$hasXrayDebtTable = $db->tableExists('patient_xray_debts');
$xrayPaidExpr = $hasXrayDebtTable
    ? "CASE
            WHEN xr.payment_status = 'paid' THEN {$xrayFeeSql}
            WHEN pxd.request_id IS NOT NULL THEN GREATEST({$xrayFeeSql} - pxd.rem, 0)
            ELSE 0
       END"
    : "CASE WHEN xr.payment_status = 'paid' THEN {$xrayFeeSql} ELSE 0 END";
$xrayCohort = $db->fetch(
    "SELECT
        COUNT(*) AS cnt,
        COALESCE(SUM(COALESCE(NULLIF(xr.original_amount, 0), xr.amount)), 0) AS gross,
        COALESCE(SUM(COALESCE(xr.discount_amount, 0)), 0) AS discount,
        COALESCE(SUM({$xrayFeeSql}), 0) AS total,
        COALESCE(SUM({$xrayPaidExpr}), 0) AS paid,
        SUM(CASE WHEN xr.accountant_approved_at IS NOT NULL THEN 1 ELSE 0 END) AS settled_cnt
     FROM xray_requests xr
     " . ($hasXrayDebtTable ? XrayManager::remainingAmountJoinSql('xr', 'pxd') : '') . "
     WHERE xr.created_at >= :range_start AND xr.created_at < :range_end
       AND COALESCE(xr.request_status, '') <> 'cancelled'
       AND COALESCE(xr.status, '') <> 'cancelled'
       AND COALESCE(xr.payment_status, '') <> 'not_required'
       AND COALESCE(xr.payment_type, '') <> 'dont_cash'",
    $timeParams
) ?: [];
$xrayCount = (int)($xrayCohort['cnt'] ?? 0);
$xraySettledCount = (int)($xrayCohort['settled_cnt'] ?? 0);
$xrayGrossFees = (float)($xrayCohort['gross'] ?? 0);
$xrayDiscountFees = (float)($xrayCohort['discount'] ?? 0);
$xrayTotalFees = (float)($xrayCohort['total'] ?? 0);
$xrayPaidFees = min($xrayTotalFees, max(0.0, (float)($xrayCohort['paid'] ?? 0)));
$xrayRemainingFees = max(0.0, $xrayTotalFees - $xrayPaidFees);

// ── کاری ئەم ماوەیە — the clinic-wide form of the doctor sheet's visit rows ──
// No doctor filter and no doctors.id bridge: this is the whole clinic, so the
// id-space trap that daily-summary.php has to navigate does not exist here.
$visitStats = ['total' => 0, 'patients' => 0, 'new_patients' => 0, 'old_patients' => 0,
               'treatment_visits' => 0, 'checkup_visits' => 0];
$checkupStats = ['given' => 0, 'pending' => 0, 'new_exam' => 0, 'old_exam' => 0];
$hasVisitsTable = $db->tableExists('visits');
if ($hasVisitsTable) {
    // visit_type is NULL on most rows and the visits page renders those as
    // پشکنین, so they are counted the same way here — that is what makes
    // ئیش + پشکنین add up to کۆی سەردان.
    $row = $db->fetch(
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT v.patient_id) AS patients,
                SUM(CASE WHEN v.patient_status = 'new' THEN 1 ELSE 0 END) AS new_patients,
                SUM(CASE WHEN v.patient_status = 'old' THEN 1 ELSE 0 END) AS old_patients,
                SUM(CASE WHEN v.visit_type = 'treatment' THEN 1 ELSE 0 END) AS treatment_visits,
                SUM(CASE WHEN v.visit_type = 'treatment' THEN 0 ELSE 1 END) AS checkup_visits
         FROM visits v
         WHERE v.visit_date BETWEEN :df AND :dt",
        $dateParams
    ) ?: [];
    foreach ($visitStats as $k => $_) {
        $visitStats[$k] = (int)($row[$k] ?? 0);
    }

    $row = $db->fetch(
        "SELECT
            SUM(CASE WHEN v.treated = 'yes' THEN 1 ELSE 0 END) AS given,
            SUM(CASE WHEN v.treated = 'yes' THEN 0 ELSE 1 END) AS pending,
            SUM(CASE WHEN v.patient_status = 'new' THEN 1 ELSE 0 END) AS new_exam,
            SUM(CASE WHEN v.patient_status = 'old' THEN 1 ELSE 0 END) AS old_exam
         FROM visits v
         WHERE v.visit_date BETWEEN :df AND :dt
           AND (v.visit_type <> 'treatment' OR v.visit_type IS NULL)",
        $dateParams
    ) ?: [];
    foreach ($checkupStats as $k => $_) {
        $checkupStats[$k] = (int)($row[$k] ?? 0);
    }
}

// ── کات — busy time and attendance, same definitions as the doctor sheet ──
// Busy time is measured from called_time (when the patient was actually taken
// in), never from check_in_time: waiting is not the doctor's working time, and
// it also drops the stale rows an overnight cleanup closed hours later, all of
// which have called_time NULL.
$workMinutes = 0;
$examMinutes = 0;
if ($db->tableExists('waiting_room')) {
    $row = $db->fetch(
        "SELECT
            COALESCE(SUM(CASE WHEN wr.visit_type = 'treatment'
                THEN TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) ELSE 0 END), 0) AS work_minutes,
            COALESCE(SUM(CASE WHEN wr.visit_type <> 'treatment'
                THEN TIMESTAMPDIFF(MINUTE, wr.called_time, wr.check_out_time) ELSE 0 END), 0) AS exam_minutes
         FROM waiting_room wr
         WHERE DATE(wr.check_in_time) BETWEEN :df AND :dt
           AND wr.called_time IS NOT NULL
           AND wr.check_out_time IS NOT NULL
           AND wr.check_out_time >= wr.called_time",
        $dateParams
    ) ?: [];
    $workMinutes = max(0, (int)($row['work_minutes'] ?? 0));
    $examMinutes = max(0, (int)($row['exam_minutes'] ?? 0));
}

// ڕۆژی هاتن here is doctor-DAYS: 4 doctors present on 3 days = 12, which is
// what makes کاتژمێری هاتن readable for a whole month across the clinic.
$attendanceMinutes = 0;
$attendanceDays = 0;
if ($db->tableExists('doctor_attendance')) {
    $row = $db->fetch(
        "SELECT COUNT(*) AS days,
                COALESCE(SUM(CASE WHEN check_out_time IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time) ELSE 0 END), 0) AS minutes
         FROM doctor_attendance
         WHERE attendance_date BETWEEN :df AND :dt
           AND check_in_time IS NOT NULL",
        $dateParams
    ) ?: [];
    $attendanceDays = (int)($row['days'] ?? 0);
    $attendanceMinutes = max(0, (int)($row['minutes'] ?? 0));
}
$idleMinutes = $attendanceMinutes > 0 ? max(0, $attendanceMinutes - $workMinutes - $examMinutes) : 0;

// زۆرترین ٣ جۆری کار — taken from the SAME array the چارەسەرەکان table below
// renders, so the two can never disagree; ordered by quantity, not money.
$topWorkTypes = $treatmentStats;
usort($topWorkTypes, static function ($a, $b) {
    return ((float)$b['quantity']) <=> ((float)$a['quantity']);
});
$topWorkTypes = array_slice($topWorkTypes, 0, 3);

$intentRows = [
    ['label' => 'نەقد', 'count' => $approvedInvoiceSummary['cash_count'] ?? 0, 'amount' => $approvedInvoiceSummary['cash_net'] ?? 0],
    ['label' => 'قەرز', 'count' => $approvedInvoiceSummary['debt_count'] ?? 0, 'amount' => $approvedInvoiceSummary['debt_net'] ?? 0],
    ['label' => 'قیست', 'count' => $approvedInvoiceSummary['installment_count'] ?? 0, 'amount' => $approvedInvoiceSummary['installment_net'] ?? 0],
    ['label' => 'دیاری‌نەکراو', 'count' => $approvedInvoiceSummary['unspecified_count'] ?? 0, 'amount' => $approvedInvoiceSummary['unspecified_net'] ?? 0],
];
$methodRows = [
    ['label' => 'پارەی ناو وەسڵ', 'count' => count($invoiceReceiptRows), 'cash' => $chairReceived, 'card' => 0, 'transfer' => 0, 'other' => 0, 'total' => $chairReceived],
    ['label' => 'دانەوەی قەرز', 'count' => $paymentMethods['debt_payment']['count'], 'cash' => $paymentMethods['debt_payment']['cash'], 'card' => $paymentMethods['debt_payment']['card'], 'transfer' => $paymentMethods['debt_payment']['transfer'], 'other' => $paymentMethods['debt_payment']['other'], 'total' => $regularPayments],
    ['label' => 'قیست', 'count' => $paymentMethods['installment']['count'], 'cash' => $paymentMethods['installment']['cash'], 'card' => $paymentMethods['installment']['card'], 'transfer' => $paymentMethods['installment']['transfer'], 'other' => $paymentMethods['installment']['other'], 'total' => $installmentPayments],
    ['label' => 'ئیکسرەی نەقد', 'count' => (int)($xrayCashSummary['row_count'] ?? 0), 'cash' => $xrayCash, 'card' => 0, 'transfer' => 0, 'other' => 0, 'total' => $xrayCash],
    ['label' => 'دانەوەی قەرزی ئیکسرە', 'count' => $xrayDebtMethods['count'], 'cash' => $xrayDebtMethods['cash'], 'card' => $xrayDebtMethods['card'], 'transfer' => $xrayDebtMethods['transfer'], 'other' => $xrayDebtMethods['other'], 'total' => $xrayDebtCollected],
];
?>

<style>
.financial-ledger{max-width:1500px;margin:0 auto 32px;color:#172b3a;direction:rtl}
.ledger-titlebar{background:#244b68;color:#fff;border:1px solid #18364c;padding:13px 16px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.ledger-titlebar h1{margin:0;font-size:20px;font-weight:700}
.ledger-titlebar .subtitle{color:#dce8f0;font-size:12px;margin-top:4px}
.ledger-print{border:1px solid #fff;background:#fff;color:#244b68;padding:7px 14px;cursor:pointer;font:inherit;font-size:12px;font-weight:700}
.ledger-filter{border:1px solid #9cafbd;border-top:0;background:#f5f7f9;padding:10px 12px;display:flex;flex-wrap:wrap;align-items:end;gap:9px}
.ledger-filter label{display:flex;flex-direction:column;gap:4px;font-size:11px;color:#445b6c}
.ledger-filter select,.ledger-filter input{min-width:145px;height:34px;border:1px solid #9cafbd;background:#fff;padding:4px 8px;font:inherit;font-size:12px;color:#172b3a}
.ledger-filter button{height:34px;border:1px solid #244b68;background:#244b68;color:#fff;padding:0 18px;font:inherit;font-size:12px;cursor:pointer}
.preset-links{display:flex;gap:4px;margin-inline-start:auto;flex-wrap:wrap}
.preset-links a{border:1px solid #9cafbd;background:#fff;color:#244b68;text-decoration:none;padding:8px 11px;font-size:11px}
.preset-links a.active{background:#dbe8f1;border-color:#244b68;font-weight:700}
.ledger-meta{width:100%;border-collapse:collapse;background:#fff;margin:12px 0}
.ledger-meta td{border:1px solid #9cafbd;padding:7px 10px;font-size:12px}
.ledger-meta .meta-label{width:140px;background:#eef2f5;font-weight:700}
.ledger-section{margin-top:16px}
.ledger-section-title{margin:0;background:#dbe5ec;border:1px solid #8fa4b4;border-bottom:0;padding:9px 12px;font-size:14px;color:#18364c;display:flex;align-items:center;justify-content:space-between;gap:10px}
.ledger-section-title small{color:#526b7d;font-weight:400}
.table-scroll{width:100%;overflow-x:auto}
.classic-table{width:100%;min-width:760px;border-collapse:collapse;background:#fff}
.classic-table th,.classic-table td{border:1px solid #9cafbd;padding:8px 9px;font-size:12px;vertical-align:middle}
.classic-table thead th{background:#eef2f5;color:#18364c;font-weight:700;text-align:center;white-space:nowrap}
.classic-table tbody tr:nth-child(even) td{background:#fafbfc}
.classic-table .row-number{width:42px;text-align:center;color:#5d7180}
.classic-table .text-cell{text-align:right}
.classic-table .number-cell{text-align:left;direction:ltr;white-space:nowrap;font-variant-numeric:tabular-nums}
.classic-table .total-row td{background:#e6edf2!important;font-weight:800;border-top:2px solid #244b68}
.classic-table .positive{color:#087b35;font-weight:700}
.classic-table .negative{color:#bf1e2e;font-weight:700}
.classic-table .debt{color:#a25c00;font-weight:700}
.classic-table .muted{color:#718391}
.ledger-two-column{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(340px,.75fr);gap:14px;align-items:start}
.ledger-note{border:1px solid #b9c6cf;border-top:0;padding:8px 10px;background:#f9fafb;color:#5a6e7d;font-size:11px;line-height:1.8}
.empty-ledger{text-align:center!important;color:#718391;padding:22px!important}
.source-tag{display:inline-block;border:1px solid #9cafbd;background:#f3f6f8;padding:2px 7px;white-space:nowrap}
/* KPI strip — same classes and metrics as doctors/daily-summary.php so the
   accountant's sheet and the doctor's sheet read as one system. */
.ds-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:12px 0}
.ds-kpi{border:1px solid #9cafbd;background:#fff;padding:10px 12px}
.ds-kpi .k{font-size:11px;color:#526b7d}
.ds-kpi .v{font-size:20px;font-weight:800;color:#18364c;direction:ltr;text-align:right;font-variant-numeric:tabular-nums}
.ds-kpi .s{font-size:10px;color:#8295a3;direction:ltr;text-align:right;margin-top:2px}
.ds-kpi.is-money .v{color:#087b35}
.ds-kpi.is-debt .v{color:#a25c00}
.ds-kpi.is-negative .v{color:#bf1e2e}
@media(max-width:980px){.ledger-two-column{grid-template-columns:1fr}.preset-links{width:100%;margin-inline-start:0;overflow-x:auto}.ledger-filter select,.ledger-filter input{min-width:125px}}
@media print{
 .no-print,.sidebar,.top-ribbon,.status-bar,.statusbar{display:none!important}
 body,.main-content,.content-area{background:#fff!important;margin:0!important;padding:0!important}
 .financial-ledger{max-width:none;margin:0}.ledger-titlebar{background:#fff!important;color:#000!important;border:1px solid #555}
 .ledger-titlebar .subtitle{color:#333}.classic-table th,.classic-table td,.ledger-meta td,.ledger-section-title{border-color:#555!important}
 .ledger-section{break-inside:avoid}
 .ds-kpi{border-color:#555}
}
</style>

<div class="financial-ledger">
    <header class="ledger-titlebar">
        <div>
            <h1><i class="fas fa-book-open"></i> تۆماری دارایی ڕۆژانە</h1>
            <div class="subtitle">پوختەی کار، پارەی وەرگیراو، قەرز، قیست و ئیکسرە بەبێ دووبارەژماردن</div>
        </div>
        <button type="button" class="ledger-print no-print" onclick="window.print()"><i class="fas fa-print"></i> چاپکردن</button>
    </header>

    <form method="get" class="ledger-filter no-print">
        <label>ماوە
            <select name="period" id="financialPeriod">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>ئەمڕۆ</option>
                <option value="yesterday" <?= $period === 'yesterday' ? 'selected' : '' ?>>دوێنێ</option>
                <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>ئەم هەفتەیە</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>ئەم مانگە</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>ماوەی دیاریکراو</option>
            </select>
        </label>
        <label>لە بەرواری <input type="date" name="date_from" value="<?= fsEsc($dateFrom) ?>"></label>
        <label>تا بەرواری <input type="date" name="date_to" value="<?= fsEsc($dateTo) ?>"></label>
        <button type="submit"><i class="fas fa-search"></i> پیشاندان</button>
        <div class="preset-links">
            <a href="<?= fsEsc($rangeUrl($prevFrom, $prevTo)) ?>" title="<?= fsEsc($prevFrom . ' تا ' . $prevTo) ?>">← ماوەی پێشوو</a>
            <a href="?period=today" class="<?= $period === 'today' ? 'active' : '' ?>">ئەمڕۆ</a>
            <a href="?period=yesterday" class="<?= $period === 'yesterday' ? 'active' : '' ?>">دوێنێ</a>
            <a href="?period=week" class="<?= $period === 'week' ? 'active' : '' ?>">هەفتە</a>
            <a href="?period=month" class="<?= $period === 'month' ? 'active' : '' ?>">مانگ</a>
            <a href="<?= fsEsc($rangeUrl($nextFrom, $nextTo)) ?>" title="<?= fsEsc($nextFrom . ' تا ' . $nextTo) ?>">ماوەی داهاتوو →</a>
        </div>
    </form>

    <table class="ledger-meta">
        <tr>
            <td class="meta-label">ماوەی ڕاپۆرت</td><td><?= fsEsc($dateFrom) ?> تا <?= fsEsc($dateTo) ?></td>
            <td class="meta-label">کاتی دروستکردن</td><td><?= fsEsc(date('Y-m-d H:i')) ?></td>
        </tr>
        <tr>
            <td class="meta-label">وەسڵی نێردراو</td><td><?= fsMoney($workSummary['submitted_count'] ?? 0) ?></td>
            <td class="meta-label">چاوەڕوانی پەسەند</td><td><?= fsMoney($workSummary['sent_count'] ?? 0) ?></td>
        </tr>
    </table>

    <!-- KPI strip: the eight numbers the accountant is asked for out loud,
         before anyone opens a single table. Same component as the doctor's
         daily sheet. -->
    <div class="ds-kpis">
        <div class="ds-kpi is-money">
            <div class="k">کۆی وەرگیراو</div>
            <div class="v"><?= fsMoney($totalCollected) ?></div>
            <div class="s">نەقد <?= fsMoney($cashCollected) ?> · لە ئامانەت <?= fsMoney($advanceApplied) ?></div>
        </div>
        <div class="ds-kpi">
            <div class="k">پارەی ناو وەسڵ</div>
            <div class="v"><?= fsMoney($chairReceived) ?></div>
            <div class="s"><?= fsMoney($approvedInvoiceSummary['invoice_count'] ?? 0) ?> وەسڵ</div>
        </div>
        <div class="ds-kpi">
            <div class="k">پارەی گەڕاوەی کۆن</div>
            <div class="v"><?= fsMoney($oldMoneyBack) ?></div>
            <div class="s">قەرز + قیست + ئیکسرە</div>
        </div>
        <!-- ئامانەت is cash in the drawer that is NOT yet earned: until the
             doctor puts it on a case, the clinic owes it back to the patient.
             The left figure is this period's takings, the right one is the
             whole standing liability, which is the number the owner needs. -->
        <div class="ds-kpi">
            <div class="k">ئامانەتی وەرگیراو</div>
            <div class="v"><?= fsMoney($advanceReceived) ?></div>
            <div class="s">تەنها زانیاری؛ ناچێتە کۆی داهات · بەردەست: <?= fsMoney($advanceUnallocated) ?></div>
        </div>
        <div class="ds-kpi">
            <div class="k">ئیکسرە</div>
            <div class="v"><?= fsMoney($xrayCollected) ?></div>
            <div class="s">نەقد <?= fsMoney($xrayCash) ?> · قەرز <?= fsMoney($xrayDebtCollected) ?></div>
        </div>
        <div class="ds-kpi is-debt">
            <div class="k">قەرزی نوێ</div>
            <div class="v"><?= fsMoney($totalNewDebt) ?></div>
            <div class="s">چارەسەر + ئیکسرە</div>
        </div>
        <div class="ds-kpi is-debt">
            <div class="k">خەرجی</div>
            <div class="v"><?= fsMoney($totalExpenses) ?></div>
            <div class="s">هەموو تۆمارەکانی خەرجی</div>
        </div>
        <div class="ds-kpi <?= $netCashFlow < 0 ? 'is-negative' : 'is-money' ?>">
            <div class="k">خالصی جوڵەی پارە</div>
            <div class="v"><?= $netCashFlow < 0 ? '(' . fsMoney(abs($netCashFlow)) . ')' : fsMoney($netCashFlow) ?></div>
            <div class="s">وەرگیراو − خەرجی</div>
        </div>
        <div class="ds-kpi is-debt">
            <div class="k">قەرزی کراوەی کلینیک</div>
            <div class="v"><?= fsMoney($totalOutstandingDebt) ?></div>
            <div class="s">ئێستا، نەک ئەم ماوەیە</div>
        </div>
    </div>

    <!-- ── پوختەی پارەی ماوەکە — one running column, top to bottom ──
         Deliberately the same shape as doctors/daily-summary.php's
         «پوختەی پارەی ڕۆژانە»: every line either adds to or subtracts from
         the line below it, so the sheet can be read without cross-checking
         the wide tables underneath. -->
    <section class="ledger-section">
        <h2 class="ledger-section-title">
            <span><i class="fas fa-coins"></i> پوختەی پارەی ماوەکە</span>
            <small>کۆی گشتی → داشکاندن → وەرگیراو → خەرجی → ئیکسرە</small>
        </h2>
        <div class="table-scroll">
            <table class="classic-table" style="min-width:0">
                <thead><tr><th>ژ</th><th>بابەت</th><th>بڕ</th><th>تێبینی</th></tr></thead>
                <tbody>
                    <tr><td class="row-number">1</td><td class="text-cell">کۆی گشتی پارە</td>
                        <td class="number-cell"><?= fsMoney($approvedGross) ?></td>
                        <td class="text-cell muted">وەسڵە پەسەندکراوەکان، پێش داشکاندن</td></tr>
                    <tr><td class="row-number">2</td><td class="text-cell">بڕی داشکێنراو</td>
                        <td class="number-cell debt">(<?= fsMoney($approvedDiscount) ?>)</td>
                        <td class="text-cell muted">داشکاندنی ژمێریار و دکتۆر</td></tr>
                    <tr class="total-row"><td class="row-number">—</td><td class="text-cell">بڕی ماوەی دوای داشکێنراو</td>
                        <td class="number-cell"><?= fsMoney($approvedNet) ?></td>
                        <td class="text-cell">بڕی ڕاستەقینەی کارەکە</td></tr>
                    <tr><td class="row-number">3</td><td class="text-cell">بڕی وەرگیراو</td>
                        <td class="number-cell positive"><?= fsMoney($treatmentCash) ?></td>
                        <td class="text-cell muted">ناو وەسڵ <?= fsMoney($chairReceived) ?> · قەرز <?= fsMoney($regularPayments) ?> · قیست <?= fsMoney($installmentPayments) ?></td></tr>
                    <tr><td class="row-number">4</td><td class="text-cell">بڕی ماوە</td>
                        <td class="number-cell debt"><?= fsMoney($treatmentNewDebt) ?></td>
                        <td class="text-cell muted">قەرزی نوێی چارەسەر</td></tr>
                    <tr><td class="row-number">5</td><td class="text-cell">خەرجی تاقیگە</td>
                        <td class="number-cell debt">(<?= fsMoney($labExpense) ?>)</td>
                        <td class="text-cell muted">expenses_lab</td></tr>
                    <tr><td class="row-number">6</td><td class="text-cell">خەرجی کۆگا</td>
                        <td class="number-cell debt">(<?= fsMoney($storeExpense) ?>)</td>
                        <td class="text-cell muted">expenses_store</td></tr>
                    <tr><td class="row-number">7</td><td class="text-cell">خەرجی گشتی</td>
                        <td class="number-cell debt">(<?= fsMoney($otherExpenses) ?>)</td>
                        <td class="text-cell muted">خەرجییەکانی تر</td></tr>
                    <tr class="total-row"><td class="row-number">—</td><td class="text-cell">ماوەی دوای خەرجی</td>
                        <td class="number-cell <?= $netAfterExpenses < 0 ? 'negative' : 'positive' ?>">
                            <?= $netAfterExpenses < 0 ? '(' . fsMoney(abs($netAfterExpenses)) . ')' : fsMoney($netAfterExpenses) ?>
                        </td>
                        <td class="text-cell">پارەی چارەسەر − خەرجی</td></tr>
                    <!-- ئیکسرە: settled apart from the treatment invoice, so it
                         gets its own three lines instead of being folded into
                         the totals above — کۆی = دراو + ماوە. -->
                    <tr><td class="row-number">8</td><td class="text-cell">کۆی پارەی ئیکسرەی</td>
                        <td class="number-cell"><?= fsMoney($xrayTotalFees) ?></td>
                        <td class="text-cell muted"><?= fsMoney($xrayCount) ?> داواکاری</td></tr>
                    <tr><td class="row-number">9</td><td class="text-cell">ئیکسرەی دراو</td>
                        <td class="number-cell positive"><?= fsMoney($xrayPaidFees) ?></td>
                        <td class="text-cell muted">نەقد و بەشەکیش</td></tr>
                    <tr><td class="row-number">10</td><td class="text-cell">ئیکسرەی ماوە</td>
                        <td class="number-cell debt"><?= fsMoney($xrayRemainingFees) ?></td>
                        <td class="text-cell muted">هێشتا وەرنەگیراوە</td></tr>
                    <tr class="total-row"><td class="row-number">—</td><td class="text-cell">کۆی وەرگیراوی سندوق</td>
                        <td class="number-cell positive"><?= fsMoney($totalCollected) ?></td>
                        <td class="text-cell">نەقد <?= fsMoney($cashCollected) ?> · کارت <?= fsMoney($cardCollected) ?> · گواستنەوە <?= fsMoney($transferCollected) ?></td></tr>
                    <tr class="total-row"><td class="row-number">—</td><td class="text-cell">خالصی جوڵەی پارە</td>
                        <td class="number-cell <?= $netCashFlow < 0 ? 'negative' : 'positive' ?>">
                            <?= $netCashFlow < 0 ? '(' . fsMoney(abs($netCashFlow)) . ')' : fsMoney($netCashFlow) ?>
                        </td>
                        <td class="text-cell">کۆی وەرگیراوی سندوق − خەرجی</td></tr>
                </tbody>
            </table>
        </div>
        <div class="ledger-note">
            ڕیزی ١–٤ کاری چارەسەرن؛ ڕیزی ٥–٧ خەرجی؛ ڕیزی ٨–١٠ ئیکسرەن کە جیا حساب دەکرێن (کۆی = دراو + ماوە).
            «کۆی وەرگیراوی سندوق» هەموو پارەیەکە کە لەم ماوەیەدا بە ڕاستی هاتووەتە ژوورەوە — چارەسەر و ئیکسرە پێکەوە —
            بۆیە دەکرێت لە جیاتی ڕیزی ٣ لەگەڵ تۆماری وردی خوارەوە بەراورد بکرێت.
            «بڕی ماوە» پارە نییە و نابێت وەک داهات بژمێردرێت. «دراوی پێشوو»ی کەیسە کۆنەکان قەرز کەم دەکاتەوە
            بەڵام هەرگیز ناچێتە ناو پارەی ئەم ماوەیەوە.
        </div>
    </section>

    <?php if ($hasVisitsTable): ?>
    <!-- ── کاری ئەم ماوەیە — the clinic's version of the doctor sheet's
         «کاری ئەمڕۆ» and «ئاماری وردی پشکنین و کات» sections. Same rows, same
         definitions, every doctor together. -->
    <div class="ledger-two-column">
        <section class="ledger-section">
            <h2 class="ledger-section-title">
                <span><i class="fas fa-user-injured"></i> کاری ئەم ماوەیە</span>
                <small>لە تۆماری سەردانەکان</small>
            </h2>
            <div class="table-scroll">
                <table class="classic-table" style="min-width:0">
                    <thead><tr><th>ژ</th><th>بابەت</th><th>ژمارە</th><th>تێبینی</th></tr></thead>
                    <tbody>
                        <tr><td class="row-number">1</td><td class="text-cell">کۆی گشتی سەردانیکەران</td>
                            <td class="number-cell"><?= fsMoney($visitStats['total']) ?></td>
                            <td class="text-cell muted"><?= fsMoney($visitStats['patients']) ?> نەخۆشی جیاواز</td></tr>
                        <tr><td class="row-number">2</td><td class="text-cell">کۆی گشتی ئیش</td>
                            <td class="number-cell"><?= fsMoney($visitStats['treatment_visits']) ?></td>
                            <td class="text-cell muted">سەردانی چارەسەر</td></tr>
                        <tr><td class="row-number">3</td><td class="text-cell">کۆی گشتی پشکنین</td>
                            <td class="number-cell"><?= fsMoney($visitStats['checkup_visits']) ?></td>
                            <td class="text-cell muted">پشکنین و ئەوانەی جۆریان تۆمار نەکراوە</td></tr>
                        <tr><td class="row-number">4</td><td class="text-cell">کۆی گشتی پشکنینی داواکراو</td>
                            <td class="number-cell debt"><?= fsMoney($checkupStats['pending']) ?></td>
                            <td class="text-cell muted">هێشتا تیمار نەکراون</td></tr>
                        <tr><td class="row-number">5</td><td class="text-cell">کۆی گشتی پشکنینی پێدراو</td>
                            <td class="number-cell positive"><?= fsMoney($checkupStats['given']) ?></td>
                            <td class="text-cell muted">تیمار کراون</td></tr>
                        <tr><td class="row-number">6</td><td class="text-cell">پشکنینی نوێ</td>
                            <td class="number-cell"><?= fsMoney($checkupStats['new_exam']) ?></td>
                            <td class="text-cell muted">نەخۆشی نوێ</td></tr>
                        <tr><td class="row-number">7</td><td class="text-cell">پشکنینی کۆن</td>
                            <td class="number-cell"><?= fsMoney($checkupStats['old_exam']) ?></td>
                            <td class="text-cell muted">نەخۆشی کۆن</td></tr>
                        <tr class="total-row"><td class="row-number">—</td><td class="text-cell">نەخۆشی نوێ / کۆن</td>
                            <td class="number-cell"><?= fsMoney($visitStats['new_patients']) ?> / <?= fsMoney($visitStats['old_patients']) ?></td>
                            <td class="text-cell">لە هەموو سەردانەکان</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="ledger-note">
                «پشکنین» ئەو سەردانانەش لەخۆدەگرێت کە جۆریان تۆمار نەکراوە — هەروەک لە لاپەڕەی سەردانەکاندا،
                بۆ ئەوەی ئیش و پشکنین کۆی سەردان تەواو بکەن. «داواکراو/پێدراو» بەپێی نیشانەی «تیمار کراوە»یە،
                و هەردوو جووتەکە کۆی پشکنین (<?= fsMoney($visitStats['checkup_visits']) ?>) تەواو دەکەن.
            </div>
        </section>

        <section class="ledger-section">
            <h2 class="ledger-section-title">
                <span><i class="fas fa-stopwatch"></i> کات و جۆری ئیشەکان</span>
                <small>هەموو دکتۆرەکان پێکەوە</small>
            </h2>
            <div class="table-scroll">
                <table class="classic-table" style="min-width:0">
                    <thead><tr><th>ژ</th><th>بابەت</th><th>کات / ژمارە</th></tr></thead>
                    <tbody>
                        <tr><td class="row-number">1</td><td class="text-cell">کاتژمێری هاتن</td>
                            <td class="number-cell"><?= fsHours($attendanceMinutes) ?></td></tr>
                        <tr><td class="row-number">2</td><td class="text-cell">ڕۆژی هاتن (دکتۆر/ڕۆژ)</td>
                            <td class="number-cell"><?= fsMoney($attendanceDays) ?></td></tr>
                        <tr><td class="row-number">3</td><td class="text-cell">کۆی کاتی ئیشکردن</td>
                            <td class="number-cell"><?= fsHours($workMinutes) ?></td></tr>
                        <tr><td class="row-number">4</td><td class="text-cell">کۆی کاتی پشکنین</td>
                            <td class="number-cell"><?= fsHours($examMinutes) ?></td></tr>
                        <tr><td class="row-number">5</td><td class="text-cell">کۆی کاتی بەتال</td>
                            <td class="number-cell"><?= fsHours($idleMinutes) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="ledger-note">
                کاتی ئیشکردن و پشکنین لە کاتی «بانگکردن»ی نەخۆشەوە دەژمێردرێت، نەک لە دانیشتنی لە ژووری چاوەڕوانی.
                «ڕۆژی هاتن» بە دکتۆر/ڕۆژ دەژمێردرێت: ٤ دکتۆر لە ٣ ڕۆژدا = ١٢.
            </div>

            <h3 style="margin:16px 0 8px;font-size:13px;color:#18364c">جۆری ئیشەکان</h3>
            <div class="table-scroll">
                <table class="classic-table" style="min-width:0">
                    <thead><tr><th>ژ</th><th>جۆر</th><th>ژمارە</th><th>بڕی گشتی</th></tr></thead>
                    <tbody>
                    <?php if (empty($topWorkTypes)): ?>
                        <tr><td colspan="4" class="empty-ledger">لەم ماوەیەدا چارەسەر تۆمار نەکراوە</td></tr>
                    <?php else: $workTypeIndex = 1; foreach ($topWorkTypes as $row): ?>
                        <tr><td class="row-number"><?= $workTypeIndex++ ?></td>
                            <td class="text-cell"><?= fsEsc($row['treatment_name']) ?></td>
                            <td class="number-cell"><?= fsMoney($row['quantity']) ?></td>
                            <td class="number-cell"><?= fsMoney($row['submitted_net']) ?></td></tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="ledger-note">
                زۆرترین ٣ جۆری کار بەپێی ژمارە، لە هەمان لیستی «ئامار بەپێی چارەسەر»ی خوارەوە وەرگیراوە.
            </div>
        </section>
    </div>
    <?php endif; ?>

    <section class="ledger-section">
        <h2 class="ledger-section-title">
            <span><i class="fas fa-balance-scale"></i> ڕاپۆرتی داخستنی ڕۆژ</span>
            <small>کار بەپێی بەرواری چارەسەرە؛ پارە بەپێی کاتی پەسەندکردنە</small>
        </h2>
        <div class="table-scroll">
            <table class="classic-table">
                <thead><tr><th>ژ</th><th>بابەت</th><th>ژمارە</th><th>بڕی کار / وەسڵ</th><th>پارەی وەرگیراو</th><th>قەرزی نوێ</th><th>تێبینی</th></tr></thead>
                <tbody>
                    <tr>
                        <td class="row-number">1</td><td class="text-cell">وەسڵە نێردراوەکانی چارەسەر</td>
                        <td class="number-cell"><?= fsMoney($workSummary['submitted_count'] ?? 0) ?></td>
                        <td class="number-cell"><?= fsMoney($workSummary['submitted_net'] ?? 0) ?></td>
                        <td class="number-cell muted">—</td><td class="number-cell muted">—</td>
                        <td class="text-cell">پەسەندکراو: <?= fsMoney($workSummary['approved_count'] ?? 0) ?>، چاوەڕوان: <?= fsMoney($workSummary['sent_count'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td class="row-number">2</td><td class="text-cell">وەسڵە دارایییە پەسەندکراوەکان</td>
                        <td class="number-cell"><?= fsMoney($approvedInvoiceSummary['invoice_count'] ?? 0) ?></td>
                        <td class="number-cell"><?= fsMoney($approvedInvoiceSummary['net'] ?? 0) ?></td>
                        <td class="number-cell positive"><?= fsMoney($chairReceived) ?></td>
                        <td class="number-cell debt"><?= fsMoney($approvedInvoiceSummary['new_debt'] ?? 0) ?></td>
                        <td class="text-cell">داشکاندن: <?= fsMoney($approvedInvoiceSummary['discount'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td class="row-number">3</td><td class="text-cell">دانەوەی قەرز لە بەشی پارەدانەکان</td>
                        <td class="number-cell"><?= fsMoney($paymentSummary['debt_count'] ?? 0) ?></td><td class="number-cell muted">—</td>
                        <td class="number-cell positive"><?= fsMoney($regularPayments) ?></td><td class="number-cell muted">—</td>
                        <td class="text-cell">تەنها پارەدانی پەسەندکراو</td>
                    </tr>
                    <tr>
                        <td class="row-number">4</td><td class="text-cell">پارەی قیست</td>
                        <td class="number-cell"><?= fsMoney($paymentSummary['installment_count'] ?? 0) ?></td><td class="number-cell muted">—</td>
                        <td class="number-cell positive"><?= fsMoney($installmentPayments) ?></td><td class="number-cell muted">—</td>
                        <td class="text-cell">لە تۆماری پارەدان تەنها یەکجار ژمێردراوە</td>
                    </tr>
                    <tr>
                        <td class="row-number">5</td><td class="text-cell">ئیکسرەی نەقد</td>
                        <td class="number-cell"><?= fsMoney($xrayCashSummary['row_count'] ?? 0) ?></td><td class="number-cell muted">—</td>
                        <td class="number-cell positive"><?= fsMoney($xrayCash) ?></td><td class="number-cell muted">—</td><td class="text-cell">ئیکسرەی پارەدراو</td>
                    </tr>
                    <tr>
                        <td class="row-number">6</td><td class="text-cell">دانەوەی قەرزی ئیکسرە</td>
                        <td class="number-cell"><?= fsMoney($xrayDebtPaymentSummary['row_count'] ?? 0) ?></td><td class="number-cell muted">—</td>
                        <td class="number-cell positive"><?= fsMoney($xrayDebtCollected) ?></td><td class="number-cell muted">—</td><td class="text-cell">پارەی وەرگیراو لە قەرزی پێشوو</td>
                    </tr>
                    <tr>
                        <td class="row-number">7</td><td class="text-cell">قەرزی نوێی ئیکسرە</td>
                        <td class="number-cell"><?= fsMoney($xrayNewDebt['row_count'] ?? 0) ?></td><td class="number-cell"><?= fsMoney($xrayNewDebt['total'] ?? 0) ?></td>
                        <td class="number-cell muted">—</td><td class="number-cell debt"><?= fsMoney($xrayNewDebt['total'] ?? 0) ?></td><td class="text-cell">قەرزی دروستکراوی ئەم ماوەیە</td>
                    </tr>
                    <tr class="total-row">
                        <td class="row-number">8</td><td class="text-cell">کۆی گشتی</td>
                        <td class="number-cell"><?= fsMoney((int)($approvedInvoiceSummary['invoice_count'] ?? 0)+(int)($paymentSummary['total_count'] ?? 0)+(int)($xrayCashSummary['row_count'] ?? 0)+(int)($xrayDebtPaymentSummary['row_count'] ?? 0)) ?></td>
                        <td class="number-cell"><?= fsMoney($approvedInvoiceSummary['net'] ?? 0) ?></td>
                        <td class="number-cell positive"><?= fsMoney($totalCollected) ?></td><td class="number-cell debt"><?= fsMoney($totalNewDebt) ?></td>
                        <td class="text-cell">خەرجی: <?= fsMoney($totalExpenses) ?> — خالصی جوڵەی پارە: <?= fsMoney($netCashFlow) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="ledger-note">«کاری ئەنجامدراو» بەپێی بەرواری وەسڵە. پارەی ناو وەسڵ تەنها دوای پەسەندکردنی تەواوی وەسڵ بە داهات ژمێردراوە؛ دانەوەی قەرز و قیست تەنها لە تۆماری پەسەندکراوی پارەدانەکان وەرگیراون.</div>
    </section>

    <section class="ledger-section">
        <h2 class="ledger-section-title"><span><i class="fas fa-file-invoice-dollar"></i> جۆری ڕێککەوتنی وەسڵ</span><small>وەسڵی پەسەندکراو</small></h2>
        <div class="table-scroll"><table class="classic-table" style="min-width:420px">
            <thead><tr><th>جۆر</th><th>ژمارەی وەسڵ</th><th>کۆی وەسڵ</th></tr></thead>
            <tbody>
            <?php foreach ($intentRows as $row): ?>
                <tr><td class="text-cell"><?= fsEsc($row['label']) ?></td><td class="number-cell"><?= fsMoney($row['count']) ?></td><td class="number-cell"><?= fsMoney($row['amount']) ?></td></tr>
            <?php endforeach; ?>
                <tr class="total-row"><td class="text-cell">قەرزی نوێ دوای پارەی ناو وەسڵ</td><td class="number-cell"><?= fsMoney($approvedInvoiceSummary['invoice_count'] ?? 0) ?></td><td class="number-cell debt"><?= fsMoney($approvedInvoiceSummary['new_debt'] ?? 0) ?></td></tr>
            </tbody>
        </table></div>
    </section>

    <section class="ledger-section">
        <h2 class="ledger-section-title"><span><i class="fas fa-user-md"></i> ئامار بەپێی دکتۆر</span><small>کار بەپێی بەرواری کار؛ وەرگیراو بەپێی بەرواری مامەڵە</small></h2>
        <div class="table-scroll"><table class="classic-table">
            <thead><tr>
                <th>ژ</th><th>دکتۆر</th><th>وەسڵی کار</th><th>پەسەندکراو</th><th>ژمارەی کار</th>
                <th>ژمارەی ئێکسڕەی</th>
                <th>کۆی کاری نێردراو</th><th>کاری پەسەندکراو</th><th>پارەی ناو وەسڵ</th>
                <th>دانەوەی قەرز</th><th>قیست</th><th>کۆی وەرگیراو</th>
            </tr></thead>
            <tbody>
            <?php if (!$doctorStats): ?>
                <tr><td colspan="12" class="empty-ledger">لە ماوەی دیاریکراودا تۆمار نییە.</td></tr>
            <?php else: ?>
                <?php $doctorIndex = 1; foreach ($doctorStats as $row): ?>
                <tr>
                    <td class="row-number"><?= $doctorIndex++ ?></td><td class="text-cell"><?= fsEsc($row['doctor_name']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['work_invoices']) ?></td><td class="number-cell"><?= fsMoney($row['approved_work_invoices']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['treatment_quantity']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['xray_count']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['work_net']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['approved_work_net']) ?></td><td class="number-cell"><?= fsMoney($row['chair_collected']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['debt_payments']) ?></td><td class="number-cell"><?= fsMoney($row['installments']) ?></td>
                    <td class="number-cell positive"><?= fsMoney($row['total_collected']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
                <!-- ئیکسرەی — the last row of the table: the centre's own x-ray
                     department, every x-ray in the period. Its money is the
                     centre's, never a doctor's; it sits in the columns whose
                     meaning already matches (ژمارە / نێردراو / پەسەندکراو /
                     وەرگیراو); داشکاندن and ماوە have no column of their own,
                     so the note under the table names them out loud. -->
                <tr class="total-row">
                    <td class="row-number"><?= $doctorStats ? count($doctorStats) + 1 : 1 ?></td>
                    <td class="text-cell"><i class="fas fa-x-ray"></i> ئیکسرەی <small class="muted">— بەشی سەنتەر</small></td>
                    <td class="number-cell"><?= fsMoney($xrayCount) ?></td>
                    <td class="number-cell"><?= fsMoney($xraySettledCount) ?></td>
                    <td class="number-cell muted">—</td>
                    <td class="number-cell"><?= fsMoney($xrayCount) ?></td>
                    <td class="number-cell"><?= fsMoney($xrayGrossFees) ?></td>
                    <td class="number-cell"><?= fsMoney($xrayTotalFees) ?></td>
                    <td class="number-cell muted">—</td>
                    <td class="number-cell muted">—</td>
                    <td class="number-cell muted">—</td>
                    <td class="number-cell positive"><?= fsMoney($xrayPaidFees) ?></td>
                </tr>
            </tbody>
        </table></div>
        <div class="ledger-note">
            <strong>ئیکسرەی:</strong>
            ئیشکراو <?= fsMoney($xrayCount) ?> ·
            کۆی گشتی <?= fsMoney($xrayGrossFees) ?> ·
            داشکاندن <?= fsMoney($xrayDiscountFees) ?> ·
            دوای داشکاندن <?= fsMoney($xrayTotalFees) ?> ·
            دراو <?= fsMoney($xrayPaidFees) ?> ·
            ماوە <?= fsMoney($xrayRemainingFees) ?>
            — ئێکسڕەی بەشێکی سەربەخۆی سەنتەرە و داهاتەکەی هی سەنتەرە: لە ڕیزی ئیکسرەیدا «کۆی کاری نێردراو» = کۆی گشتی پێش داشکاندن،
            «کاری پەسەندکراو» = دوای داشکاندن، و «کۆی وەرگیراو» = ئەوەی دراوە. پارەی ئێکسڕەی ناچێتە ناو «کۆی وەرگیراو»ی هیچ دکتۆرێک؛
            «ژمارەی ئێکسڕەی»ی دکتۆرەکان تەنها ئامارە — چەند داواکارییان ناردووە.
        </div>
    </section>

    <section class="ledger-section">
        <h2 class="ledger-section-title"><span><i class="fas fa-tooth"></i> ئامار بەپێی چارەسەر</span><small>چارەسەری ددان‌دار و چارەسەری بێ ژمارەی ددان</small></h2>
        <div class="table-scroll"><table class="classic-table">
            <thead><tr><th>ژ</th><th>ناوی چارەسەر</th><th>ژمارەی وەسڵ</th><th>ڕیزی کار</th><th>کۆی ژمارە</th><th>بڕی نێردراو</th><th>بڕی پەسەندکراو</th><th>چاوەڕوانی پەسەند</th></tr></thead>
            <tbody>
            <?php if (!$treatmentStats): ?>
                <tr><td colspan="8" class="empty-ledger">لە ماوەی دیاریکراودا چارەسەری نێردراو نییە.</td></tr>
            <?php else: ?>
                <?php foreach ($treatmentStats as $index => $row): ?>
                <tr>
                    <td class="row-number"><?= $index + 1 ?></td><td class="text-cell"><?= fsEsc($row['treatment_name']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['invoice_count']) ?></td><td class="number-cell"><?= fsMoney($row['line_count']) ?></td>
                    <td class="number-cell"><?= fsMoney($row['quantity']) ?></td><td class="number-cell"><?= fsMoney($row['submitted_net']) ?></td>
                    <td class="number-cell positive"><?= fsMoney($row['approved_net']) ?></td><td class="number-cell debt"><?= fsMoney($row['awaiting_net']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <section class="ledger-section">
        <h2 class="ledger-section-title"><span><i class="fas fa-list-alt"></i> وردەکاری تۆماری پارەی وەرگیراو</span><small><?= fsMoney(count($cashbookRows)) ?> جوڵە</small></h2>
        <div class="table-scroll"><table class="classic-table">
            <thead><tr><th>ژ</th><th>بەروار</th><th>کات</th><th>سەرچاوە</th><th>نەخۆش</th><th>دکتۆر</th><th>شێوازی پارەدان</th><th>بڕ</th></tr></thead>
            <tbody>
            <?php if (!$cashbookRows): ?>
                <tr><td colspan="8" class="empty-ledger">لە ماوەی دیاریکراودا پارەی پەسەندکراو نییە.</td></tr>
            <?php else: ?>
                <?php foreach ($cashbookRows as $index => $row): ?>
                <tr>
                    <td class="row-number"><?= $index + 1 ?></td>
                    <?php $txTs = strtotime($row['transaction_date']); ?>
                    <td class="number-cell"><?= fsEsc(date('Y-m-d', $txTs)) ?></td>
                    <td class="number-cell"><?= fsEsc(date('H:i', $txTs)) ?></td>
                    <td class="text-cell"><span class="source-tag"><?= fsEsc($row['source']) ?></span></td>
                    <td class="text-cell"><?= fsEsc($row['patient_name']) ?></td>
                    <td class="text-cell"><?= fsEsc($row['doctor_name']) ?><?php if (!empty($row['sent_by'])): ?>
                        <small class="muted" style="display:block;font-size:11px;">نێردراو لەلایەن <?= fsEsc($row['sent_by']) ?></small><?php endif; ?></td>
                    <td class="text-cell"><?= fsEsc($row['method']) ?></td>
                    <td class="number-cell positive"><?= fsMoney($row['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row"><td colspan="7" class="text-cell">کۆی پارەی وەرگیراو</td><td class="number-cell positive"><?= fsMoney($totalCollected) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <section class="ledger-section">
        <h2 class="ledger-section-title"><span><i class="fas fa-archive"></i> پوختەی قەرزی ماوەی کلینیک</span><small>تا کاتی دروستکردنی ڕاپۆرت</small></h2>
        <div class="table-scroll"><table class="classic-table">
            <thead><tr><th>قەرزی چارەسەر</th><th>قەرزی ئیکسرە</th><th>کۆی قەرزی ماوە</th></tr></thead>
            <tbody><tr class="total-row">
                <td class="number-cell debt"><?= fsMoney($outstandingTreatmentDebt) ?></td>
                <td class="number-cell debt"><?= fsMoney($outstandingXrayDebt) ?></td>
                <td class="number-cell negative"><?= fsMoney($totalOutstandingDebt) ?></td>
            </tr></tbody>
        </table></div>
    </section>
</div>

<script>
document.getElementById('financialPeriod')?.addEventListener('change', function () {
    if (this.value !== 'custom') {
        this.form.submit();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
