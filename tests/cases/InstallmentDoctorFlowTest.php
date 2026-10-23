<?php // tests/cases/InstallmentDoctorFlowTest.php
// Doctor monthly installment collection REQUEST (cases/view.php's
// `flexible_installment_payment` action, doctor branch) — the installment
// twin of DoctorCasePaymentRequestTest.php's case-level داواکاری پارەدان
// semantics (Task 12): every month when the patient returns, the doctor
// sends a collection request against the plan's own monthly amount.
// Installment::requestFlexiblePayment() caps it at the plan's remaining
// balance minus already-pending installment requests and files it via
// Payment::createRequest(payment_type 'installment') — pending in the
// accountant's Approval Center, never booking money directly until
// approved. requestFlexiblePayment() itself has no DEDUPE (only the cap
// check), so a double-submit of the same amount would stack two pending
// rows — cases/view.php's handler must find-and-adjust the doctor's own
// still-pending installment-type request instead, exactly like Task 12's
// case-level request_payment handler does for payment_type 'cash'.
//
// cases/view.php can't be require()'d directly here (full HTTP page: auth
// redirect, $_GET bootstrap, header()+exit calls throughout), so
// idft_find_doctor_installment_pending_request() /
// idft_submit_installment_request() below reproduce cases/view.php's
// find_doctor_installment_pending_request() / the flexible_installment_payment
// handler's doctor-branch decision tree verbatim, and drive the real
// Installment/Payment classes with what they produce. KEEP THESE IN SYNC
// with cases/view.php any time that decision tree changes — same rule
// DoctorCasePaymentRequestTest.php's own twin helpers carry.
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/Installment.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/CaseManager.php';

$db = Database::getInstance();

// Mirrors cases/view.php's NEW find_doctor_installment_pending_request() —
// the installment-type-filtered twin of find_doctor_case_pending_request().
// The type filter is the whole point: it is what keeps this dedupe lookup
// from cross-matching the doctor's SEPARATE case-level (payment_type 'cash')
// داواکاری پارەدان request — see the cross-type independence assertions below.
function idft_find_doctor_installment_pending_request($db, $caseId, $doctorId) {
    $row = $db->fetch(
        "SELECT id, amount FROM payments
         WHERE case_id = :cid AND status = 'pending' AND received_by = :doc AND payment_type = 'installment'
         ORDER BY id DESC LIMIT 1",
        ['cid' => (int)$caseId, 'doc' => (int)$doctorId]
    );
    return $row ? ['id' => (int)$row['id'], 'amount' => (float)$row['amount']] : null;
}

// Mirrors cases/view.php's flexible_installment_payment handler's doctor
// branch decision tree (Task-12 semantic, installment twin): equal amount →
// 'unchanged' (no new row); a different amount while one is pending →
// Payment::updatePendingAmount on the SAME row; nothing pending → the real
// Installment::requestFlexiblePayment() (fresh create, cap-checked internally).
function idft_submit_installment_request($inst, $p, $db, $planId, $caseId, $doctorId, $amount) {
    $pending = idft_find_doctor_installment_pending_request($db, $caseId, $doctorId);
    if ($pending && abs($amount - $pending['amount']) <= 0.009) {
        return ['success' => true, 'outcome' => 'unchanged', 'payment_id' => $pending['id']];
    }
    if ($pending) {
        try {
            $p->updatePendingAmount($pending['id'], $amount, $doctorId, 'گۆڕینی بڕی داواکاری قیست لەلایەن دکتۆر');
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        return ['success' => true, 'outcome' => 'updated', 'payment_id' => $pending['id']];
    }
    $res = $inst->requestFlexiblePayment($planId, ['amount' => $amount, 'payment_method' => 'cash'], $doctorId);
    $res['outcome'] = 'created';
    return $res;
}

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);
$inv = fx_invoice($case, $doc, 'sent', 'approved', 120000);
fx_treatment($case, 120000, $inv);
(new CaseManager())->updateCaseTotals($case);

$inst = new Installment();
$planRes = $inst->createPlan([
    'case_id' => $case, 'invoice_id' => $inv, 'total_amount' => 120000,
    'down_payment' => 0, 'number_of_installments' => 4, 'start_date' => date('Y-m-d'),
    'created_by' => $acct,
]);
$t->assertTrue(!empty($planRes['success']), 'active plan created for the flow test');
$planId = (int)$planRes['plan_id'];
$plan = $db->fetch('SELECT * FROM installment_plans WHERE id = ?', [$planId]);
$t->assertSame('active', $plan['status'], 'plan is active (createPlan path)');
$t->assertEqualsMoney(30000, $plan['installment_amount'], 'monthly installment amount is 120000/4');

$p = new Payment();

// ── Part 1: doctor collection-request dedupe ───────────────────────────
// A fresh request for the monthly amount creates exactly ONE pending
// installment-type payment row.
$sub1 = idft_submit_installment_request($inst, $p, $db, $planId, $case, $doc, 30000);
$t->assertTrue(!empty($sub1['success']), 'first installment collection request succeeds');
$t->assertSame('created', $sub1['outcome'], 'first submission is a fresh create');
$pendingId = (int)$sub1['payment_id'];
$rowCount1 = (int)$db->fetch(
    "SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ? AND payment_type = 'installment'",
    [$case, $doc]
)['c'];
$t->assertSame(1, $rowCount1, 'exactly one installment-type pending row after the first request');
$row1 = $db->fetch('SELECT status, amount, payment_type FROM payments WHERE id = ?', [$pendingId]);
$t->assertSame('pending', $row1['status'], 'the request is pending, never booked directly');
$t->assertSame('installment', $row1['payment_type'], 'the request is payment_type installment');
$t->assertEqualsMoney(30000, $row1['amount'], 'pending amount matches the monthly installment');

// Same amount again (double-click, or the doctor resending the same figure)
// → the SAME row, still exactly one row, reported 'unchanged'.
$sub2 = idft_submit_installment_request($inst, $p, $db, $planId, $case, $doc, 30000);
$t->assertTrue(!empty($sub2['success']), 'identical resubmit does not error');
$t->assertSame('unchanged', $sub2['outcome'], 'identical resubmit is reported as unchanged');
$t->assertSame($pendingId, (int)$sub2['payment_id'], 'identical resubmit points at the SAME pending payment');
$rowCount2 = (int)$db->fetch(
    "SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ? AND payment_type = 'installment'",
    [$case, $doc]
)['c'];
$t->assertSame(1, $rowCount2, 'still exactly one installment pending row after the identical resubmit');

// A different amount while still pending → the SAME row id, new amount via
// Payment::updatePendingAmount (not a second stacked row).
$sub3 = idft_submit_installment_request($inst, $p, $db, $planId, $case, $doc, 25000);
$t->assertTrue(!empty($sub3['success']), 'a different target while pending succeeds');
$t->assertSame('updated', $sub3['outcome'], 'a different target while pending is reported as updated');
$t->assertSame($pendingId, (int)$sub3['payment_id'], 'the update lands on the SAME pending payment, not a new one');
$rowCount3 = (int)$db->fetch(
    "SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ? AND payment_type = 'installment'",
    [$case, $doc]
)['c'];
$t->assertSame(1, $rowCount3, 'still exactly one installment pending row — the new target did not stack a second one');
$updatedAmount = (float)$db->fetch('SELECT amount FROM payments WHERE id = ?', [$pendingId])['amount'];
$t->assertEqualsMoney(25000, $updatedAmount, 'the pending payment amount changed to the new target');

// After the accountant rejects it, a fresh request succeeds — the
// single-pending-request rule must not outlive a decision.
$rejectRes = $p->reject($pendingId, 'تاقیکردنەوە', $acct);
$t->assertTrue(!empty($rejectRes['success']), 'reject succeeds');

$sub4 = idft_submit_installment_request($inst, $p, $db, $planId, $case, $doc, 30000);
$t->assertTrue(!empty($sub4['success']), 're-request after rejection succeeds');
$t->assertSame('created', $sub4['outcome'], 're-request after rejection is a fresh create, not folded into the rejected row');
$t->assertTrue((int)$sub4['payment_id'] !== $pendingId, 're-request after rejection creates a genuinely new payment');
$rowCount4 = (int)$db->fetch(
    "SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ? AND payment_type = 'installment'",
    [$case, $doc]
)['c'];
$t->assertSame(2, $rowCount4, 'exactly two installment payment rows total: the rejected one and the new pending one — no phantom duplicate');
$secondPendingId = (int)$sub4['payment_id'];

// ── Part 2: cross-type independence ────────────────────────────────────
// A pending CASE-LEVEL 'cash' request (request_payment, a separate action)
// for the SAME doctor+case must coexist with the pending 'installment'
// request above without either dedupe lookup touching the other's row.
$cashReq = $p->createRequest([
    'case_id' => $case, 'amount' => 15000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
]);
$t->assertTrue(!empty($cashReq['success']), 'a cash-type case-level request also succeeds on this case');
$cashPendingId = (int)$cashReq['payment_id'];
$t->assertTrue($cashPendingId !== $secondPendingId, 'the cash request is a genuinely different row from the installment request');

// The installment-type lookup must NOT see the cash row as "its own" pending request.
$instLookup = idft_find_doctor_installment_pending_request($db, $case, $doc);
$t->assertTrue($instLookup !== null, 'installment lookup still finds the installment-type row');
$t->assertSame($secondPendingId, (int)$instLookup['id'], 'installment lookup points at the installment row, not the cash row');

$totalPendingRows = (int)$db->fetch(
    "SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ? AND status = 'pending'",
    [$case, $doc]
)['c'];
$t->assertSame(2, $totalPendingRows, 'two independent pending rows coexist: one cash, one installment');

// Approving BOTH books correctly: the installment one increments
// installment_payments / the plan totals (Payment::approve, classes/Payment.php:369-377
// applies a payment_type 'installment' approval to the case's active plan via
// Installment::applyFlexibleToPlan()); the cash one settles the case ledger
// and must NOT touch the plan.
$beforePlanSummary = $inst->getPlanSummary($planId);
$approveInst = $p->approve($secondPendingId, $acct);
$t->assertTrue(!empty($approveInst['success']), 'approving the installment request succeeds: ' . ($approveInst['message'] ?? ''));
$afterPlanSummary = $inst->getPlanSummary($planId);
$t->assertEqualsMoney(
    (float)$beforePlanSummary['total_paid'] + 30000,
    (float)$afterPlanSummary['total_paid'],
    'approving the installment request increments the plan total_paid by the approved amount'
);
$ipCount = (int)$db->fetch(
    "SELECT COUNT(*) c FROM installment_payments WHERE plan_id = ? AND payment_id = ?",
    [$planId, $secondPendingId]
)['c'];
$t->assertSame(1, $ipCount, 'approving the installment request created exactly one installment_payments row linked to the canonical payment');

$approveCash = $p->approve($cashPendingId, $acct);
$t->assertTrue(!empty($approveCash['success']), 'approving the separate cash request also succeeds: ' . ($approveCash['message'] ?? ''));
$afterCashPlanSummary = $inst->getPlanSummary($planId);
$t->assertEqualsMoney(
    (float)$afterPlanSummary['total_paid'], (float)$afterCashPlanSummary['total_paid'],
    'approving the cash-type request does NOT change the plan totals — it is not an installment payment'
);

// ── Part 3: pending-plan visibility — data layer only ──────────────────
// UI markup isn't unit-testable here; this covers the DATA the page must
// fetch to show a doctor-proposed plan while it awaits accountant approval.
// A proposed plan is invisible to getPlanByCase() (active-only by design —
// classes/Installment.php:414-424), so cases/view.php must fetch it via a
// SEPARATE query, mirroring PatientFinance::currentPlanSummary()
// (classes/PatientFinance.php:224-236).
$case2 = fx_case($fold);
$inv2 = fx_invoice($case2, $doc, 'sent', 'approved', 60000);
fx_treatment($case2, 60000, $inv2);
(new CaseManager())->updateCaseTotals($case2);

$proposedPlanId = $inst->proposePlan([
    'case_id' => $case2, 'invoice_id' => $inv2, 'total_amount' => 60000,
    'number_of_installments' => 3, 'start_date' => date('Y-m-d'), 'down_payment' => 10000,
], $doc);
$t->assertTrue($proposedPlanId > 0, 'doctor proposal on case2 succeeds');

$t->assertSame(false, $inst->getPlanByCase($case2), 'getPlanByCase() stays active-only — a pending proposal is invisible to it');

$pendingFetch = $db->fetch(
    "SELECT * FROM installment_plans WHERE case_id = :case_id AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
    ['case_id' => $case2]
);
$t->assertTrue(!empty($pendingFetch), 'the separate pending-plan fetch (mirrors cases/view.php) DOES find the proposal');
$t->assertSame($proposedPlanId, (int)$pendingFetch['id'], 'the fetched pending plan is the one the doctor proposed');
$t->assertEqualsMoney(60000, $pendingFetch['total_amount'], 'proposed total_amount is visible');
$t->assertEqualsMoney(10000, $pendingFetch['proposed_down_payment'], 'proposed_down_payment is visible');
$t->assertEqualsMoney(0, $pendingFetch['down_payment'], 'real down_payment column stays 0 for a still-pending proposal');
$t->assertSame(3, (int)$pendingFetch['number_of_installments'], 'proposed number_of_installments is visible');

// A rejected plan (newest for its case) is also readable by the same shape
// of query the page uses for the dismissible reject-reason note.
$case3 = fx_case($fold);
$rejectedPlanId = $inst->proposePlan([
    'case_id' => $case3, 'total_amount' => 40000,
    'number_of_installments' => 2, 'start_date' => date('Y-m-d'), 'down_payment' => 0,
], $doc);
$inst->rejectPlan($rejectedPlanId, 'نەخۆش قبووڵی نەکرد', $acct);
$rejectedFetch = $db->fetch(
    "SELECT * FROM installment_plans WHERE case_id = :case_id AND status IN ('pending','rejected') ORDER BY created_at DESC LIMIT 1",
    ['case_id' => $case3]
);
$t->assertTrue(!empty($rejectedFetch), 'the pending-or-rejected fetch finds the rejected plan');
$t->assertSame('rejected', $rejectedFetch['status'], 'status is rejected');
$t->assertSame('نەخۆش قبووڵی نەکرد', $rejectedFetch['reject_reason'], 'reject_reason is readable for the dismissible note');
