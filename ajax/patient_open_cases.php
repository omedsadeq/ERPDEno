<?php
/**
 * A patient's open cases + their billable invoices, for the accountant's
 * "receive money" picker (accountant/payments.php).
 *
 * Deliberately includes DRAFT/RETURNED/SENT invoices, not just approved ones
 * — AccountingLedger::getInvoices()'s default already does this. The whole
 * point of this tool is collecting cash the doctor hasn't had time to send
 * an invoice for yet, so an un-sent draft's billed amount must be visible
 * and pickable here, not hidden until someone clicks "send".
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/AccountingLedger.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!$auth->isAccountant() && !$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$patientId = (int)($_GET['patient_id'] ?? 0);
if ($patientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'نەخۆش دیاری نەکراوە']);
    exit;
}

$db = Database::getInstance();
$ledger = new AccountingLedger();

$caseRows = $db->fetchAll(
    "SELECT c.id, c.case_number, c.case_name, c.status, pf.folder_name,
            u.full_name AS doctor_name
     FROM cases c
     JOIN patient_folders pf ON pf.id = c.folder_id
     LEFT JOIN users u ON u.id = pf.doctor_id
     WHERE pf.patient_id = :pid AND c.status = 'open'
     ORDER BY c.created_at DESC",
    ['pid' => $patientId]
);

$cases = [];
foreach ($caseRows as $c) {
    $invoices = $ledger->getInvoices((int)$c['id']); // include_drafts defaults true
    $billable = array_values(array_filter($invoices, function ($inv) {
        return $inv['status'] !== 'cancelled' && (float)$inv['remaining_amount'] > 0.009;
    }));
    if (!$billable) {
        continue; // nothing to collect on this case right now
    }
    $statusLabels = ['draft' => 'ڕەشنووس', 'sent' => 'نێردراو', 'approved' => 'پەسەندکراو', 'returned' => 'گەڕێندراوەتەوە'];
    $cases[] = [
        'id' => (int)$c['id'],
        'case_number' => $c['case_number'],
        'label' => $c['folder_name'] . ' — ' . ($c['case_name'] ?: $c['case_number']) . ' (' . ($c['doctor_name'] ?: '—') . ')',
        'invoices' => array_map(function ($inv) use ($statusLabels) {
            return [
                'id' => (int)$inv['id'],
                'invoice_no' => $inv['invoice_no'],
                'work_date' => $inv['work_date'],
                'net_amount' => (float)$inv['net_amount'],
                'paid_amount' => (float)$inv['paid_amount'],
                'remaining_amount' => (float)$inv['remaining_amount'],
                'accounting_status' => $inv['accounting_status'],
                'status_label' => $statusLabels[$inv['accounting_status']] ?? $inv['accounting_status'],
                // 'sent' already works with the plain receive-payment flow (it
                // always allows sent); only draft/returned need allow_draft=1.
                'needs_draft' => in_array($inv['accounting_status'], ['draft', 'returned'], true),
            ];
        }, $billable),
    ];
}

echo json_encode(['success' => true, 'cases' => $cases]);
