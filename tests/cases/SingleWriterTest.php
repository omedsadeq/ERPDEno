<?php // tests/cases/SingleWriterTest.php
$db = Database::getInstance();
require_once __DIR__ . '/../../classes/AuditLog.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/DebtPayment.php';
require_once __DIR__ . '/../../classes/XrayManager.php';

$cm = new CaseManager();
$dp = new DebtPayment();

// --- rerouted case debt payment produces an approved Payment-class payment,
// with a payment_number, allocations summing to the paid amount, and a
// payment_type within the enum ---
$doc = fx_user('doctor'); $pat = fx_patient(); $fold = fx_folder($pat, $doc); $case = fx_case($fold);
$inv = fx_invoice($case, $doc, 'sent', 'approved', 100000);
fx_treatment($case, 100000, $inv);
$cm->updateCaseTotals($case); // populate cases.total_amount/paid_amount so getPatientDebtItems sees the debt

$result = $dp->distributePayment($pat, 60000, 'cash', $doc, 'test debt payment');
$t->assertTrue(!empty($result['success']), 'distributePayment succeeded: ' . ($result['message'] ?? ''));

$pay = $db->fetch("SELECT * FROM payments WHERE case_id = ? ORDER BY id DESC LIMIT 1", [$case]);
$t->assertSame('approved', $pay['status'], 'debt payment booked approved');
$t->assertTrue(!empty($pay['payment_number']), 'payment_number generated (Payment class path)');
$alloc = $db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$pay['id']]);
$t->assertEqualsMoney(60000, $alloc['s'], 'allocations written');
$t->assertTrue(in_array($pay['payment_type'], ['cash', 'credit', 'installment'], true), 'payment_type within enum');

// --- closeWithCredit removed: close a case with remaining balance fabricates
// no payment row, and the case still closes ---
$case2 = fx_case($fold);
$inv2 = fx_invoice($case2, $doc, 'sent', 'approved', 50000);
fx_treatment($case2, 50000, $inv2);
$cm->updateCaseTotals($case2); // paid_amount stays 0 -> remaining balance on close

$before = (int)$db->fetch("SELECT COUNT(*) c FROM payments WHERE case_id = ?", [$case2])['c'];
$closeResult = $cm->close($case2, 'test close');
$after = (int)$db->fetch("SELECT COUNT(*) c FROM payments WHERE case_id = ?", [$case2])['c'];
$t->assertTrue(!empty($closeResult['success']), 'close() succeeded: ' . ($closeResult['message'] ?? ''));
$t->assertSame($before, $after, 'close() fabricates no payment');
$t->assertSame('closed', $db->fetch("SELECT status FROM cases WHERE id = ?", [$case2])['status'], 'case closed');

// --- closeWithCredit no longer exists ---
$t->assertTrue(!method_exists('CaseManager', 'closeWithCredit'), 'closeWithCredit removed');

// --- rerouted X-ray debt payment: final_amount is never mutated, and the
// collection is tracked via patient_xray_debts + xray_debt_payments ---
$pat2 = fx_patient();
$requestId = (int) $db->insert('xray_requests', [
    'request_number'  => 'TXR' . substr(uniqid(), -8),
    'patient_id'      => $pat2,
    'requested_by'    => $doc,
    'xray_type'       => 'panoramic',
    'xray_reason'     => 'pathology',
    'status'          => 'completed',
    'payment_status'  => 'debt',
    'original_amount' => 40000,
    'final_amount'    => 40000,
]);

$xrResult = $dp->distributePayment($pat2, 15000, 'cash', $doc, 'test xray debt payment');
$t->assertTrue(!empty($xrResult['success']), 'xray distributePayment succeeded: ' . ($xrResult['message'] ?? ''));

$xrRow = $db->fetch("SELECT final_amount, payment_status FROM xray_requests WHERE id = ?", [$requestId]);
$t->assertEqualsMoney(40000, $xrRow['final_amount'], 'xray_requests.final_amount unchanged by partial debt payment');
$t->assertSame('debt', $xrRow['payment_status'], 'payment_status stays debt until fully settled');

$debtRow = $db->fetch("SELECT * FROM patient_xray_debts WHERE request_id = ?", [$requestId]);
$t->assertTrue(!empty($debtRow), 'patient_xray_debts row exists');
$t->assertEqualsMoney(25000, $debtRow['remaining_amount'], 'debt remaining reduced by the payment');
$t->assertSame('partial', $debtRow['status'], 'debt status partial');

$debtPaymentRow = $db->fetch("SELECT * FROM xray_debt_payments WHERE debt_id = ? ORDER BY id DESC LIMIT 1", [$debtRow['id']]);
$t->assertTrue(!empty($debtPaymentRow), 'xray_debt_payments row exists');
$t->assertEqualsMoney(15000, $debtPaymentRow['amount'], 'xray_debt_payments amount matches the collected payment');

$logRow = $db->fetch("SELECT action FROM xray_payment_logs WHERE request_id = ? ORDER BY id DESC LIMIT 1", [$requestId]);
$t->assertSame('debt_paid', $logRow['action'], "log action is 'debt_paid', not the invalid 'debt_payment'");

// --- paying off the remainder settles the request via XrayManager::markDebtSettled ---
$xrResult2 = $dp->distributePayment($pat2, 25000, 'cash', $doc, 'test xray debt payoff');
$t->assertTrue(!empty($xrResult2['success']), 'xray payoff distributePayment succeeded: ' . ($xrResult2['message'] ?? ''));

$xrRow2 = $db->fetch("SELECT final_amount, payment_status, request_status FROM xray_requests WHERE id = ?", [$requestId]);
$t->assertEqualsMoney(40000, $xrRow2['final_amount'], 'xray_requests.final_amount still unchanged after full payoff');
$t->assertSame('paid', $xrRow2['payment_status'], 'payment_status settled to paid via markDebtSettled');

$debtRow2 = $db->fetch("SELECT * FROM patient_xray_debts WHERE request_id = ?", [$requestId]);
$t->assertEqualsMoney(0, $debtRow2['remaining_amount'], 'debt fully paid off');
$t->assertSame('paid', $debtRow2['status'], 'debt status paid');

// --- regression: getPatientDebtItems must read patient_xray_debts.remaining_amount,
// not the frozen xr.final_amount, when a debt has already had a partial
// payment. Repro: debt 40000, pay 15000 (true remaining 25000, but
// final_amount stays 40000 since it is never mutated). A second payment whose
// pool (30000) exceeds the TRUE remaining but is still <= the STALE
// final_amount must only book the true 25000 against the debt and land the
// 5000 surplus in patient credit — not silently vanish it from the ledger. ---
$pat3 = fx_patient();
$requestId3 = (int) $db->insert('xray_requests', [
    'request_number'  => 'TXR' . substr(uniqid(), -8),
    'patient_id'      => $pat3,
    'requested_by'    => $doc,
    'xray_type'       => 'panoramic',
    'xray_reason'     => 'pathology',
    'status'          => 'completed',
    'payment_status'  => 'debt',
    'original_amount' => 40000,
    'final_amount'    => 40000,
]);

$firstResult = $dp->distributePayment($pat3, 15000, 'cash', $doc, 'partial 1');
$t->assertTrue(!empty($firstResult['success']), 'first partial xray payment succeeded: ' . ($firstResult['message'] ?? ''));

$xrResult3 = $dp->distributePayment($pat3, 30000, 'cash', $doc, 'overpay past true remaining');
$t->assertTrue(!empty($xrResult3['success']), 'overpayment distributePayment succeeded: ' . ($xrResult3['message'] ?? ''));
$t->assertEqualsMoney(25000, $xrResult3['total_paid'] ?? -1, 'pool deduction equals the truly-applied amount, not the stale remaining');
$t->assertEqualsMoney(5000, $xrResult3['credit'] ?? -1, 'surplus beyond the true remaining lands in patient credit');

$xrAlloc = null;
foreach (($xrResult3['allocations'] ?? []) as $a) {
    if ($a['item_type'] === 'xray' && (int)$a['item_id'] === $requestId3) { $xrAlloc = $a; }
}
$t->assertTrue($xrAlloc !== null, 'allocation entry recorded for the xray item');
$t->assertEqualsMoney(25000, $xrAlloc['amount'] ?? -1, 'allocation records the true applied amount, not the pool amount');

$xrRow3 = $db->fetch("SELECT final_amount, payment_status FROM xray_requests WHERE id = ?", [$requestId3]);
$t->assertEqualsMoney(40000, $xrRow3['final_amount'], 'final_amount still untouched after the overpayment');
$t->assertSame('paid', $xrRow3['payment_status'], 'debt settled to paid, not left partial by the overpayment');

$debtRow3 = $db->fetch("SELECT * FROM patient_xray_debts WHERE request_id = ?", [$requestId3]);
$t->assertEqualsMoney(0, $debtRow3['remaining_amount'], 'debt remaining is zero, not negative, after the overpayment');
$t->assertSame('paid', $debtRow3['status'], 'debt status paid');

$lastDebtPayment = $db->fetch("SELECT * FROM xray_debt_payments WHERE debt_id = ? ORDER BY id DESC LIMIT 1", [$debtRow3['id']]);
$t->assertEqualsMoney(25000, $lastDebtPayment['amount'], 'xray_debt_payments records the true applied amount, not the pool amount');

$creditRow3 = $db->fetch("SELECT balance FROM patient_credits WHERE patient_id = ?", [$pat3]);
$t->assertTrue(!empty($creditRow3), 'patient credit row created for the surplus');
$t->assertEqualsMoney(5000, $creditRow3['balance'] ?? -1, 'patient credit balance equals the surplus');
