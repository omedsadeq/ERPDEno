<?php // tests/cases/ApprovalQueueTest.php
// Task 9: ApprovalQueue — pure derived views over the real approval-state
// columns (case_invoices.accounting_status, payments.status,
// installment_plans.status, xray_requests.request_status, waiting_room).
//
// Filename sorts first alphabetically among tests/cases/*Test.php, so it runs
// against a database no other test file has touched yet — the counts()
// assertions below rely on that (they count the WHOLE table, not a filtered
// slice), matching the brief's own RED-test skeleton.
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Installment.php';
require_once __DIR__ . '/../../classes/XrayManager.php';
require_once __DIR__ . '/../../classes/Payment.php';

$db = Database::getInstance();

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();
$patientName = $db->fetch("SELECT full_name FROM patients WHERE id = ?", [$pat])['full_name'];
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

// An already-approved invoice with real allocation capacity, so a payment
// request has somewhere to reserve against (mirrors PaymentGuardTest.php).
$capacityInv = fx_invoice($case, $doc, 'sent', 'approved', 500000);
fx_treatment($case, 500000, $capacityInv);
(new CaseManager())->updateCaseTotals($case);

// The one row the invoices queue should see.
$inv = fx_invoice($case, $doc, 'sent', 'sent', 100000);
fx_treatment($case, 100000, $inv);

// One pending payment request against the capacity invoice.
$payment = new Payment();
$payReq = $payment->createRequest([
    'case_id' => $case, 'amount' => 50000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
]);
$t->assertTrue(!empty($payReq['success']), 'fixture: payment request created');

// One pending installment plan on its own case (proposePlan blocks a second
// active-or-pending plan per case, so this needs a fresh one).
$fold2 = fx_folder($pat, $doc);
$case2 = fx_case($fold2);
$inv2 = fx_invoice($case2, $doc, 'sent', 'approved', 300000);
fx_treatment($case2, 300000, $inv2);
(new CaseManager())->updateCaseTotals($case2);
$installment = new Installment($db);
$planId = $installment->proposePlan([
    'case_id' => $case2, 'invoice_id' => $inv2, 'total_amount' => 300000,
    'number_of_installments' => 6, 'start_date' => date('Y-m-d'), 'down_payment' => 0,
], $doc);

// One x-ray request pending the accountant.
$xrayManager = new XrayManager();
$xrayResult = $xrayManager->createRequest([
    'patient_id' => $pat, 'requested_by' => $doc,
    'xray_type' => 'panoramic', 'xray_reason' => 'pathology',
    'request_status' => 'pending_accountant', 'payment_type' => 'cash',
]);
$t->assertTrue(!empty($xrayResult['success']), 'fixture: xray request created');

$q = new ApprovalQueue();

// ---- counts() across all five queues + badge math ----
$c = $q->counts();
$t->assertSame(1, $c['invoices'], 'sent invoice counted');
$t->assertSame(1, $c['payments'], 'pending payment counted');
$t->assertSame(1, $c['installments'], 'pending plan counted');
$t->assertSame(1, $c['xray'], 'pending_accountant counted');
// The x-ray request auto-registers the patient at the accounting station
// (PatientFlow::syncFromXray), but that row is deduped against the open
// invoice AND the open x-ray request for the same patient — station stays 0.
$t->assertSame(0, $c['station'], 'station row deduped against open invoice/xray');
$t->assertSame(4, $c['all'], 'all count = every live request including station');
$t->assertSame(4, $c['badge'], 'badge = 4 approval queues (station excluded)');

// ---- list() row shape + net amount ----
$rows = $q->list('invoices');
$t->assertSame(1, count($rows), 'one row in the invoices queue');
$t->assertSame('invoice', $rows[0]['kind']);
$t->assertSame('invoice:' . $inv, $rows[0]['uid'], 'uid = kind:id');
$t->assertSame($pat, $rows[0]['patient_id'], 'row carries patient_id');
$t->assertTrue($rows[0]['amount'] > 0, 'net amount present');
$t->assertEqualsMoney(100000, $rows[0]['amount'], 'net amount = total - discount');

// ---- search filter ----
$t->assertSame(1, count($q->list('invoices', ['q' => $patientName])), 'search filter');
$t->assertSame(0, count($q->list('invoices', ['q' => 'no-such-patient-xyz'])), 'search excludes');

// ---- other tabs have their one row too ----
$t->assertSame(1, count($q->list('payments')), 'one row in the payments queue');
$t->assertSame(1, count($q->list('installments')), 'one row in the installments queue');
$t->assertSame(1, count($q->list('xray')), 'one row in the xray queue');
$t->assertSame(0, count($q->list('station')), 'station queue empty (deduped)');

// ---- all tab: one chronological feed, grouped client-side by patient_id ----
$allRows = $q->list('all');
$t->assertSame(4, count($allRows), 'all tab merges every live queue row');
$allKinds = array_column($allRows, 'kind');
sort($allKinds);
$t->assertSame(['installment', 'invoice', 'payment', 'xray'], $allKinds, 'all tab carries every open request kind');
$previousTs = '';
foreach ($allRows as $allRow) {
    $t->assertSame($pat, $allRow['patient_id'], 'same patient requests share the grouping key');
    $t->assertTrue(array_key_exists('patient_phone', $allRow), 'all row carries patient phone for the group header');
    $currentTs = (string)$allRow['created_at'];
    $t->assertTrue($previousTs === '' || strcmp($previousTs, $currentTs) <= 0, 'all rows are oldest-to-newest');
    $previousTs = $currentTs;
}
$t->assertSame(4, count($q->list('all', ['q' => $patientName])), 'all-tab search applies to every source');
$t->assertSame(0, count($q->list('all', ['q' => 'no-such-patient-xyz'])), 'all-tab search excludes every source');

// ---- approve the invoice -> queue empties, history shows it ----
// counts(true) forces a live recompute past the per-request memo added
// alongside this test (classes/ApprovalQueue.php) — this process reuses the
// same $q instance (and the memo is static/process-wide besides) across the
// approve() mutation above, which a plain counts() would not see.
$approveResult = (new CaseInvoice())->approve($case, $inv, $acct);
$t->assertTrue(!empty($approveResult['success']), 'invoice approved');
$t->assertSame(0, $q->counts(true)['invoices'], 'approved leaves the queue');
$t->assertSame(3, $q->counts(true)['all'], 'all count drops when one request is decided');
$t->assertSame(3, $q->counts(true)['badge'], 'badge drops by one after the approval');

$historyRows = $q->list('history');
$t->assertTrue(count($historyRows) >= 1, 'history shows acted items');
$historyHit = null;
foreach ($historyRows as $hr) {
    if ($hr['kind'] === 'invoice' && $hr['id'] === $inv) { $historyHit = $hr; break; }
}
$t->assertTrue($historyHit !== null, 'the approved invoice itself shows up in history');
$t->assertSame('approved', $historyHit['status'] ?? null, 'history row carries the approved status');

// ---- history: payments.reversed_at (a real decision-time column, set by
// Payment::refund()) must drive the 30-day window for refunds, not the
// creation-time payment_date fallback — a payment refunded today but
// created over 30 days ago must still surface. An old APPROVED (never
// refunded/rejected) payment, by contrast, has no decision timestamp at all
// and documents the known payment_date-fallback gap by staying invisible. ----
$backdated = date('Y-m-d H:i:s', strtotime('-35 days'));

$fold3 = fx_folder($pat, $doc);
$case3 = fx_case($fold3);
$inv3 = fx_invoice($case3, $doc, 'sent', 'approved', 200000);
fx_treatment($case3, 200000, $inv3);
(new CaseManager())->updateCaseTotals($case3);
$oldRefunded = $payment->directPayment([
    'case_id' => $case3, 'amount' => 40000, 'payment_type' => 'cash', 'payment_method' => 'cash',
], $acct);
$t->assertTrue(!empty($oldRefunded['success']), 'fixture: direct (approved) payment created for the refund case');
$oldRefundedId = (int)$oldRefunded['payment_id'];
$db->update('payments', ['payment_date' => $backdated, 'created_at' => $backdated], 'id = ?', [$oldRefundedId]);
$refundResult = $payment->refund($oldRefundedId, 'تاقیکردنەوەی مێژوو', $acct);
$t->assertTrue(!empty($refundResult['success']), 'refund succeeds (stamps reversed_at = now)');

$fold4 = fx_folder($pat, $doc);
$case4 = fx_case($fold4);
$inv4 = fx_invoice($case4, $doc, 'sent', 'approved', 200000);
fx_treatment($case4, 200000, $inv4);
(new CaseManager())->updateCaseTotals($case4);
$oldApproved = $payment->directPayment([
    'case_id' => $case4, 'amount' => 30000, 'payment_type' => 'cash', 'payment_method' => 'cash',
], $acct);
$t->assertTrue(!empty($oldApproved['success']), 'fixture: direct (approved) payment created for the never-refunded case');
$oldApprovedId = (int)$oldApproved['payment_id'];
$db->update('payments', ['payment_date' => $backdated, 'created_at' => $backdated], 'id = ?', [$oldApprovedId]);

$historyAfterRefund = $q->list('history');
$refundedHit = null;
$oldApprovedHit = null;
foreach ($historyAfterRefund as $hr) {
    if ($hr['kind'] === 'payment' && $hr['id'] === $oldRefundedId) { $refundedHit = $hr; }
    if ($hr['kind'] === 'payment' && $hr['id'] === $oldApprovedId) { $oldApprovedHit = $hr; }
}
$t->assertTrue($refundedHit !== null, 'a payment refunded today surfaces in history despite being created 35 days ago (reversed_at drives the window)');
$t->assertSame('refunded', $refundedHit['status'] ?? null, 'history row carries the refunded status');
$t->assertSame(null, $oldApprovedHit, 'an old approved, never-refunded/rejected payment created 35 days ago does not surface (documents the payment_date fallback gap)');

// ---- detail() payloads ----
$d = $q->detail('invoice', $inv);
$t->assertTrue(isset($d['invoice']) && isset($d['summary']) && isset($d['treatments']), 'invoice drawer payload complete');
$t->assertSame($pat, $d['patient_id'], 'invoice detail carries patient_id');

// ---- detail('invoice') treatment rows carry the wide-drawer columns
// (invoice-drawer-redesign: ApprovalQueue::detailInvoice()'s SELECT was
// extended with tooth_surface/total_price on top of what it already had) ----
$t->assertSame(1, count($d['treatments']), 'invoice detail carries its one treatment row');
$firstTreatment = $d['treatments'][0];
foreach (['tooth_number', 'tooth_surface', 'quantity', 'unit_price', 'total_price', 'final_price'] as $key) {
    $t->assertTrue(array_key_exists($key, $firstTreatment), "treatment row carries {$key}");
}
$t->assertSame(null, $firstTreatment['tooth_number'], 'fx_treatment leaves tooth_number unset (NULL)');
$t->assertSame(null, $firstTreatment['tooth_surface'], 'fx_treatment leaves tooth_surface unset (NULL)');
$t->assertSame(1, (int)$firstTreatment['quantity'], 'case_treatments.quantity defaults to 1');
$t->assertEqualsMoney(100000, $firstTreatment['unit_price'], 'unit_price carried through');
$t->assertEqualsMoney(100000, $firstTreatment['total_price'], 'total_price carried through');
$t->assertEqualsMoney(100000, $firstTreatment['final_price'], 'final_price carried through');

$dp = $q->detail('payment', (int)$payReq['payment_id']);
$t->assertTrue(isset($dp['payment']) && isset($dp['summary']), 'payment drawer payload complete');
$t->assertSame($pat, $dp['patient_id'], 'payment detail carries patient_id');

$di = $q->detail('installment', $planId);
$t->assertTrue(isset($di['plan']) && isset($di['summary']) && isset($di['proposed_terms']), 'installment drawer payload complete');
$t->assertSame($pat, $di['patient_id'], 'installment detail carries patient_id');

$dx = $q->detail('xray', (int)$xrayResult['request_id']);
$t->assertTrue(isset($dx['request']) && isset($dx['history']), 'xray drawer payload complete');
$t->assertSame($pat, $dx['patient_id'], 'xray detail carries patient_id');

// ---- newSince() ----
$since = $q->newSince(date('Y-m-d H:i:s', strtotime('-1 minute')));
$t->assertTrue($since['count'] >= 3, 'newSince picks up the still-open payment/installment/xray rows');
$t->assertTrue(in_array('payment:' . $payReq['payment_id'], $since['uids'], true), 'newSince uids include the pending payment');
