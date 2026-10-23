<?php // tests/cases/EndToEndTest.php
// Task 16: final integration pass — the spec's headline flow end to end, one
// continuous narrative, following task-16-brief.md's 10 numbered points
// verbatim. Reuses the exact services/fixtures exercised individually by
// ApprovalQueueTest.php, DoctorFlowTest.php, InstallmentPendingTest.php and
// PatientFinanceTest.php — nothing new is invented here.
//
// Adaptations from the brief's shorthand to real signatures/state (documented
// per task-16-brief.md's own instruction to adapt-and-document):
//  - "queue invoices=1, badge=1" etc. are read as DELTAS against a baseline
//    ApprovalQueue::counts(true) snapshot taken before this narrative starts.
//    This file runs after ApprovalQueueTest/BugfixTest/DoctorFlowTest in the
//    same CLI process (alphabetical file order, tests/run.php), and those
//    leave their own still-pending rows in the global queues on purpose
//    (ApprovalQueueTest.php's own header documents this ordering dependency)
//    — so an absolute "invoices===1" assertion would be wrong from the start.
//  - Per-patient queue membership IS asserted as an absolute count, scoped
//    with list()'s `q` filter against this test's own patient's phone number
//    (not full_name — fx_patient()'s "Test Patient {n}" names are a global
//    counter and a small n like "3" is a LIKE-substring of a later "30", so
//    phone — a random 7-digit string — is the collision-free filter key).
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/Installment.php';
require_once __DIR__ . '/../../classes/XrayManager.php';
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/PatientFinance.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';

$db = Database::getInstance();

$doc  = fx_user('doctor');
$acct = fx_user('accountant');
$pat  = fx_patient();
$patientRow = $db->fetch('SELECT full_name, phone FROM patients WHERE id = ?', [$pat]);
$patientPhone = $patientRow['phone'];
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

$ci = new CaseInvoice();
$payment = new Payment();
$installment = new Installment($db);
$xrayManager = new XrayManager();
$ledger = new AccountingLedger();
$q = new ApprovalQueue();
$pf = new PatientFinance();

$accountingReportsExists = $db->tableExists('accounting_reports');
$reportsBefore = $accountingReportsExists
    ? (int)$db->fetch('SELECT COUNT(*) c FROM accounting_reports')['c']
    : null;

$baseCounts = $q->counts(true);

// ================================================================
// 1: doctor drafts an invoice, 2 treatments totalling 120000, sends with
// intent=debt + a 30-day promise
// ================================================================
$inv = fx_invoice($case, $doc, 'draft', 'draft', 0);
fx_treatment($case, 70000, $inv);
fx_treatment($case, 50000, $inv);
$promisedDate = date('Y-m-d', strtotime('+30 days'));
$sendResult = $ci->send($case, $inv, $doc, '', [
    'payment_intent'  => 'debt',
    'promised_date'   => $promisedDate,
    'promised_amount' => 120000,
]);
$t->assertTrue(!empty($sendResult['success']), '1: doctor sends the 120000 invoice with a debt intent');
$invRow = $db->fetch('SELECT * FROM case_invoices WHERE id = ?', [$inv]);
$t->assertEqualsMoney(120000, $invRow['total_amount'], '1: invoice total is the sum of both treatments');
$t->assertSame('debt', $invRow['payment_intent'], '1: intent persisted at send');
$t->assertSame($promisedDate, $invRow['promised_date'], '1: 30-day promise persisted');

// ================================================================
// 2: queue picks the invoice up; balance is still 0 (not approved)
// ================================================================
$countsAfterSend = $q->counts(true);
$t->assertSame($baseCounts['invoices'] + 1, $countsAfterSend['invoices'], '2: invoices queue +1');
$t->assertSame($baseCounts['badge'] + 1, $countsAfterSend['badge'], '2: badge +1');
$t->assertSame(1, count($q->list('invoices', ['q' => $patientPhone])), "2: exactly this patient's invoice in the queue");
$t->assertEqualsMoney(0, $ledger->getSummary($case)['balance'], '2: sending books nothing — balance still 0');

// ================================================================
// 3: accountant discounts 20000, approves -> balance 100000, queue empty,
// history=1
// ================================================================
$discountResult = $ci->setDiscount($case, $inv, 'amount', 20000, $acct);
$t->assertTrue(!empty($discountResult['success']), '3: discount applied');
$approveResult = $ci->approve($case, $inv, $acct);
$t->assertTrue(!empty($approveResult['success']), '3: invoice approved');

$summaryAfterApproval = $ledger->getSummary($case);
$t->assertEqualsMoney(100000, $summaryAfterApproval['approved'], '3: approved net = 120000 - 20000');
$t->assertEqualsMoney(100000, $summaryAfterApproval['balance'], '3: balance = approved (nothing paid yet)');

$countsAfterApprove = $q->counts(true);
$t->assertSame($baseCounts['invoices'], $countsAfterApprove['invoices'], '3: invoices queue back to baseline');
$t->assertSame($baseCounts['badge'], $countsAfterApprove['badge'], '3: badge back to baseline');
$t->assertSame(0, count($q->list('invoices', ['q' => $patientPhone])), '3: this patient no longer in the invoices queue');

$historyRows = $q->list('history', ['q' => $patientPhone]);
$t->assertSame(1, count($historyRows), '3: exactly one history row for this patient so far (the approved invoice)');
$t->assertSame('invoice', $historyRows[0]['kind'] ?? null, '3: history row is the invoice');
$t->assertSame('approved', $historyRows[0]['status'] ?? null, '3: history row shows approved');

// ================================================================
// 4: doctor requests 40000 وەرگیراو -> payments queue+1, balance still
// 100000, pending_requests = 40000
// ================================================================
$requestResult = $payment->createRequest([
    'case_id' => $case, 'amount' => 40000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
]);
$t->assertTrue(!empty($requestResult['success']), '4: doctor payment request created');
$paymentId = (int)$requestResult['payment_id'];

$countsAfterRequest = $q->counts(true);
$t->assertSame($baseCounts['payments'] + 1, $countsAfterRequest['payments'], '4: payments queue +1');
$t->assertSame($baseCounts['badge'] + 1, $countsAfterRequest['badge'], '4: badge +1');
$t->assertSame(1, count($q->list('payments', ['q' => $patientPhone])), "4: exactly this patient's payment request in the queue");
$t->assertEqualsMoney(100000, $ledger->getSummary($case)['balance'], '4: a pending request books nothing — balance unchanged');
$t->assertEqualsMoney(40000, $payment->getCaseBalance($case)['pending'], '4: pending_requests = 40000');

// ================================================================
// 5: accountant adjusts 40000 -> 35000, approves -> paid 35000, balance
// 65000, allocations sum 35000
// ================================================================
$adjusted = $payment->updatePendingAmount($paymentId, 35000, $acct, 'گۆڕینی بڕ لەلایەن ژمێریار');
$t->assertTrue($adjusted === true, '5: pending amount adjusted 40000 -> 35000');
$approvePayment = $payment->approve($paymentId, $acct);
$t->assertTrue(!empty($approvePayment['success']), '5: payment approved');

$summaryAfterPayment = $ledger->getSummary($case);
$t->assertEqualsMoney(35000, $summaryAfterPayment['paid'], '5: paid = 35000');
$t->assertEqualsMoney(65000, $summaryAfterPayment['balance'], '5: balance = 100000 - 35000');
$allocSum = (float)$db->fetch(
    'SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$paymentId]
)['s'];
$t->assertEqualsMoney(35000, $allocSum, '5: allocations sum to 35000');

// ================================================================
// 6: doctor proposes an installment plan on the 65000 remainder; accountant
// edits the term count and activates -> schedule rows exist, plan active
// ================================================================
$planId = $installment->proposePlan([
    'case_id' => $case, 'invoice_id' => $inv, 'total_amount' => 65000,
    'number_of_installments' => 5, 'start_date' => date('Y-m-d'), 'down_payment' => 0,
], $doc);
$t->assertTrue($planId > 0, '6: installment plan proposed');
$planRow = $db->fetch('SELECT * FROM installment_plans WHERE id = ?', [$planId]);
$t->assertSame('pending', $planRow['status'], '6: proposal is pending, no balance effect yet');

$countsAfterPropose = $q->counts(true);
$t->assertSame($baseCounts['installments'] + 1, $countsAfterPropose['installments'], '6: installments queue +1');
$t->assertSame(1, count($q->list('installments', ['q' => $patientPhone])), "6: exactly this patient's proposal in the queue");

$activated = $installment->activatePlan($planId, ['number_of_installments' => 4], $acct);
$t->assertTrue(is_array($activated), '6: plan activated');
$planRow = $db->fetch('SELECT * FROM installment_plans WHERE id = ?', [$planId]);
$t->assertSame('active', $planRow['status'], '6: plan active');
$scheduleCount = (int)$db->fetch('SELECT COUNT(*) c FROM installment_schedule WHERE plan_id = ?', [$planId])['c'];
$t->assertSame(4, $scheduleCount, '6: schedule rebuilt to the accountant-edited 4-installment term');

$countsAfterActivate = $q->counts(true);
$t->assertSame($baseCounts['installments'], $countsAfterActivate['installments'], '6: installments queue back to baseline');

// ================================================================
// 7: doctor cash x-ray request -> xray queue+1; accountant approves with a
// 5000 discount -> request_status pending_xray, payment_status paid
// ================================================================
// original_amount/final_amount are set alongside amount, mirroring the real
// caller (xray/request.php:95-99) — applyDiscount() below reads
// original_amount (falling back to amount only when original_amount is
// unset), so a fixture that omits it would not exercise the real path.
$xrayResult = $xrayManager->createRequest([
    'patient_id' => $pat, 'requested_by' => $doc,
    'xray_type' => 'panoramic', 'xray_reason' => 'pathology',
    'request_status' => 'pending_accountant', 'payment_type' => 'cash',
    'amount' => 20000, 'original_amount' => 20000, 'final_amount' => 20000,
]);
$t->assertTrue(!empty($xrayResult['success']), '7: xray request created');
$xrayId = (int)$xrayResult['request_id'];

$countsAfterXray = $q->counts(true);
$t->assertSame($baseCounts['xray'] + 1, $countsAfterXray['xray'], '7: xray queue +1');
$t->assertSame(1, count($q->list('xray', ['q' => $patientPhone])), "7: exactly this patient's xray request in the queue");

// Mirrors api/finance-actions.php's xray_settle sequence: optional discount,
// then the decision call (payment_type='cash' -> approvePaymentAndSendToXray).
$discountXray = $xrayManager->applyDiscount($xrayId, 5000, 'داشکاندنی تایبەت', $acct);
$t->assertTrue(!empty($discountXray['success']), '7: xray discount applied');
$approveXray = $xrayManager->approvePaymentAndSendToXray($xrayId, $acct, ROLE_ACCOUNTANT, '');
$t->assertTrue(!empty($approveXray['success']), '7: xray payment approved');

$xrayRow = $db->fetch('SELECT request_status, payment_status, final_amount FROM xray_requests WHERE id = ?', [$xrayId]);
$t->assertSame('pending_xray', $xrayRow['request_status'], '7: request forwarded to x-ray');
$t->assertSame('paid', $xrayRow['payment_status'], '7: payment status paid');
$t->assertEqualsMoney(15000, $xrayRow['final_amount'], '7: final amount = 20000 - 5000 discount');

$countsAfterXrayApprove = $q->counts(true);
$t->assertSame($baseCounts['xray'], $countsAfterXrayApprove['xray'], '7: xray queue back to baseline');

// ================================================================
// 8: PatientFinance::summary matches every number above; activity timeline
// has at least 8 events
// ================================================================
$summary = $pf->summary($pat);
$t->assertEqualsMoney(120000, $summary['total_invoiced'], '8: total_invoiced');
$t->assertEqualsMoney(20000, $summary['total_discount'], '8: total_discount');
$t->assertEqualsMoney(100000, $summary['approved'], '8: approved');
$t->assertEqualsMoney(35000, $summary['paid'], '8: paid');
$t->assertEqualsMoney(0, $summary['pending_requests'], '8: no pending requests left (the one request was approved)');
$t->assertEqualsMoney(65000, $summary['case_balance'], '8: case_balance');
$t->assertEqualsMoney(0, $summary['xray_outstanding'], '8: xray fully paid — nothing outstanding');
$t->assertEqualsMoney(0, $summary['credit'], '8: no credit issued in this narrative');
$t->assertEqualsMoney(65000, $summary['installment_remaining'], '8: installment remaining = the untouched 65000 plan');
$t->assertEqualsMoney(65000, $summary['net_debt'], '8: net_debt = case_balance + xray_outstanding - credit');

$statement = $pf->statement($pat);
$t->assertTrue(count($statement['activity']) >= 8, '8: activity timeline has at least 8 events');

// ================================================================
// 9: duplicate safety — re-approve invoice / re-approve payment /
// re-activate plan all throw or no-op, with zero row changes
// ================================================================
$invBefore = $db->fetch('SELECT approved_at FROM case_invoices WHERE id = ?', [$inv]);
$reApproveInvoice = $ci->approve($case, $inv, $acct);
$t->assertSame(false, $reApproveInvoice['success'] ?? true, '9: re-approving an approved invoice is a no-op');
$invAfter = $db->fetch('SELECT approved_at FROM case_invoices WHERE id = ?', [$inv]);
$t->assertSame($invBefore['approved_at'], $invAfter['approved_at'], '9: re-approve leaves the invoice row untouched');

$allocBeforeReapprove = (float)$db->fetch(
    'SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$paymentId]
)['s'];
$reApprovePayment = $payment->approve($paymentId, $acct);
$t->assertSame(false, $reApprovePayment['success'] ?? true, '9: re-approving an approved payment is a no-op');
$allocAfterReapprove = (float)$db->fetch(
    'SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$paymentId]
)['s'];
$t->assertEqualsMoney($allocBeforeReapprove, $allocAfterReapprove, '9: re-approve does not change allocation sums');

$scheduleBeforeReactivate = (int)$db->fetch('SELECT COUNT(*) c FROM installment_schedule WHERE plan_id = ?', [$planId])['c'];
$t->assertThrows(fn() => $installment->activatePlan($planId, [], $acct), '9: re-activating an already-active plan throws');
$scheduleAfterReactivate = (int)$db->fetch('SELECT COUNT(*) c FROM installment_schedule WHERE plan_id = ?', [$planId])['c'];
$t->assertSame($scheduleBeforeReactivate, $scheduleAfterReactivate, '9: re-activate attempt leaves the schedule untouched');

// ================================================================
// 10: accounting_reports row count unchanged across the whole narrative
// ================================================================
if ($accountingReportsExists) {
    $reportsAfter = (int)$db->fetch('SELECT COUNT(*) c FROM accounting_reports')['c'];
    $t->assertSame($reportsBefore, $reportsAfter, '10: accounting_reports untouched across the whole narrative');
}
