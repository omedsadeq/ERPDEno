<?php // tests/cases/PatientFinanceTest.php
// Task 10: PatientFinance — read-only aggregation over AccountingLedger,
// Installment, XrayManager and DebtPayment for one patient's whole financial
// picture. Spec §9: this class computes no money the underlying services
// don't already compute — it only resolves scoped case ids, loops/merges
// their outputs, and sums.
require_once __DIR__ . '/../../classes/PatientFinance.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/XrayManager.php';

$db = Database::getInstance();

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();

// ---- Case A: an already-approved invoice with a partial approved payment
// PLUS one pending doctor request against the remaining capacity. ----
$foldA = fx_folder($pat, $doc);
$caseA = fx_case($foldA);
$invA = fx_invoice($caseA, $doc, 'sent', 'approved', 100000);
fx_treatment($caseA, 100000, $invA);
(new CaseManager())->updateCaseTotals($caseA);

$payment = new Payment();
$direct = $payment->directPayment([
    'case_id' => $caseA, 'amount' => 60000, 'payment_type' => 'cash', 'payment_method' => 'cash',
], $acct);
$t->assertTrue(!empty($direct['success']), 'fixture: approved payment of 60000 on case A');

$payReq = $payment->createRequest([
    'case_id' => $caseA, 'amount' => 10000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
]);
$t->assertTrue(!empty($payReq['success']), 'fixture: pending payment request of 10000 on case A');

// ---- Case B: sent (not yet approved) invoice — no money moved against it. ----
$foldB = fx_folder($pat, $doc);
$caseB = fx_case($foldB);
$invB = fx_invoice($caseB, $doc, 'sent', 'sent', 50000);
fx_treatment($caseB, 50000, $invB);

// ---- X-ray: one real (approved) debt and one still-pending-approval request.
// debt_pending must NOT count toward outstanding debt (Task 5 canon). ----
function pft_xray_request($db, $pat, $doc, $status, $amount) {
    return (int) $db->insert('xray_requests', [
        'request_number'  => 'TPF' . substr(uniqid(), -8) . rand(10, 99),
        'patient_id'      => $pat,
        'requested_by'    => $doc,
        'xray_type'       => 'panoramic',
        'xray_reason'     => 'pathology',
        'status'          => 'completed',
        'request_status'  => 'completed',
        'payment_status'  => $status,
        'original_amount' => $amount,
        'final_amount'    => $amount,
    ]);
}
$xrayApprovedId = pft_xray_request($db, $pat, $doc, 'debt_approved', 20000);
pft_xray_request($db, $pat, $doc, 'debt_pending', 99999);
// A real patient_xray_debts row, as XrayManager::settleAsDebt() would create
// for the debt_approved request — exercises statement()'s 'debts' section.
$db->insert('patient_xray_debts', [
    'patient_id' => $pat, 'request_id' => $xrayApprovedId,
    'amount' => 20000, 'remaining_amount' => 20000,
]);

// ---- Patient credit balance. ----
$db->insert('patient_credits', ['patient_id' => $pat, 'balance' => 5000]);

// ================================================================
// summary()
// ================================================================
$pf = new PatientFinance();
$s = $pf->summary($pat);
$t->assertEqualsMoney(100000, $s['approved'], 'only approved invoices in receivable');
$t->assertEqualsMoney(60000,  $s['paid'], 'approved allocations only');
$t->assertEqualsMoney(40000,  $s['case_balance'], 'balance = approved - paid');
$t->assertEqualsMoney(10000,  $s['pending_requests'], 'pending request visible but not booked');
$t->assertEqualsMoney(20000,  $s['xray_outstanding'], 'debt_pending excluded');
$t->assertEqualsMoney(5000,   $s['credit'], 'credit read');
$t->assertEqualsMoney(55000,  $s['net_debt'], '40000 + 20000 - 5000');
$t->assertTrue(
    array_key_exists('total_invoiced', $s) && array_key_exists('total_discount', $s)
        && array_key_exists('installment_remaining', $s),
    'full key set present (total_invoiced/total_discount/installment_remaining)'
);
$t->assertEqualsMoney(0, $s['installment_remaining'], 'no active installment plan in this fixture');

// ================================================================
// statement()
// ================================================================
$st = $pf->statement($pat);
$t->assertSame(2, count($st['cases']), 'both cases present');
$t->assertTrue(count($st['activity']) > 0, 'activity timeline populated');
$t->assertTrue(
    isset($st['xray']['requests'], $st['xray']['debts'], $st['xray']['payments']),
    'xray section shape complete'
);
$t->assertSame(2, count($st['xray']['requests']), 'both xray requests listed (all payment types)');
$t->assertSame(1, count($st['xray']['debts']), 'one real debt row (debt_approved)');
$t->assertEqualsMoney(5000, $st['credit']['balance'], 'statement carries the same credit balance as summary()');

$caseAHit = null;
foreach ($st['cases'] as $c) {
    if ((int)$c['case']['id'] === $caseA) { $caseAHit = $c; break; }
}
$t->assertTrue($caseAHit !== null, 'case A present in statement');
$t->assertSame(2, count($caseAHit['payments']), 'case A statement shows both payments (approved + pending)');
$t->assertSame(null, $caseAHit['installment'], 'no installment plan attached to case A');

// ---- doctor scoping: a second doctor owns none of this patient's folders ----
$foreignDoc = fx_user('doctor');
$t->assertEqualsMoney(0, $pf->summary($pat, $foreignDoc)['case_balance'], 'foreign doctor scoped out');

// ================================================================
// Doctor-scoped x-ray / statement coverage (Task 13 builds the
// doctor-facing statement page directly on these code paths). $doc is the
// sole doctor on this patient so far, so the owning-doctor scope must
// reproduce the unscoped totals exactly; then a SECOND real doctor gets
// their own x-ray on the same patient to prove exclusion/inclusion both
// ways, not just the "owns nothing" case already covered above.
// ================================================================
$sOwner = $pf->summary($pat, $doc);
$t->assertEqualsMoney($s['case_balance'], $sOwner['case_balance'], 'owning doctor case_balance matches unscoped (sole doctor on this patient)');
$t->assertEqualsMoney($s['paid'], $sOwner['paid'], 'owning doctor paid matches unscoped');
$t->assertEqualsMoney($s['pending_requests'], $sOwner['pending_requests'], 'owning doctor pending_requests matches unscoped');
$t->assertEqualsMoney($s['xray_outstanding'], $sOwner['xray_outstanding'], 'owning doctor xray_outstanding matches unscoped before a second doctor has any xrays');

// A second doctor's own x-ray request on the same patient.
$secondDoc = fx_user('doctor');
pft_xray_request($db, $pat, $secondDoc, 'debt_approved', 15000);

$t->assertEqualsMoney(20000, $pf->summary($pat, $doc)['xray_outstanding'], "owning doctor xray_outstanding excludes the second doctor's xray");
$sSecond = $pf->summary($pat, $secondDoc);
$t->assertEqualsMoney(15000, $sSecond['xray_outstanding'], 'second doctor xray_outstanding includes only their own xray');
$t->assertEqualsMoney(0, $sSecond['case_balance'], 'second doctor owns no cases on this patient');
$t->assertEqualsMoney(35000, $pf->summary($pat)['xray_outstanding'], "unscoped xray_outstanding includes both doctors' xrays");

// ---- statement() doctor scoping ----
$stOwner = $pf->statement($pat, $doc);
$t->assertTrue(
    isset($stOwner['summary'], $stOwner['cases'], $stOwner['xray'], $stOwner['credit'], $stOwner['activity']),
    'owning doctor statement shape complete'
);
$t->assertSame(2, count($stOwner['cases']), 'owning doctor statement shows both of their cases');
$t->assertSame(2, count($stOwner['xray']['requests']), "owning doctor statement xray requests exclude the second doctor's");
$ownerRequestsAllBelongToDoc = true;
foreach ($stOwner['xray']['requests'] as $r) {
    if ((int)$r['requested_by'] !== $doc) { $ownerRequestsAllBelongToDoc = false; break; }
}
$t->assertTrue($ownerRequestsAllBelongToDoc, 'every request in the owning doctor statement belongs to them');

$stForeign = $pf->statement($pat, $foreignDoc);
$t->assertSame(0, count($stForeign['cases']), 'foreign doctor statement has no cases');
$t->assertSame(0, count($stForeign['xray']['requests']), 'foreign doctor statement has no xray requests');

// ---- view-vs-service consistency: v_patient_financial_summary.case_balance
// (Task 2) must agree with PatientFinance::summary()'s case_balance for the
// same patient — both sum the same per-case AccountingLedger formula. ----
$viewRow = $db->fetch('SELECT case_balance FROM v_patient_financial_summary WHERE patient_id = ?', [$pat]);
$t->assertEqualsMoney((float)$viewRow['case_balance'], $s['case_balance'], 'view and service agree on case_balance');
