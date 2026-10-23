<?php // tests/cases/DoctorFlowTest.php
// Task 12: doctor-side flow — CaseInvoice::send()'s 5th-param payment intent
// (payment_intent/promised_date/promised_amount, persisted at send time,
// before any accountant approval). See classes/CaseInvoice.php:send().
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';

$db = Database::getInstance();
$doc = fx_user('doctor');
$pat = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

$ci = new CaseInvoice();

// send() with a debt intent + a payment promise persists all three columns
// on the invoice row, in the same optimistic-locked UPDATE that flips it to
// 'sent'.
$inv = fx_invoice($case, $doc, 'draft', 'draft', 0);
fx_treatment($case, 50000, $inv);
$promisedDate = date('Y-m-d', strtotime('+14 days'));
$res = $ci->send($case, $inv, $doc, '', [
    'payment_intent'   => 'debt',
    'promised_date'    => $promisedDate,
    'promised_amount'  => 50000,
]);
$t->assertTrue(!empty($res['success']), 'send with debt intent succeeds');
$row = $db->fetch('SELECT * FROM case_invoices WHERE id = ?', [$inv]);
$t->assertSame('debt', $row['payment_intent'], 'intent persisted at send');
$t->assertSame('sent', $row['accounting_status'], 'sent');
$t->assertSame($promisedDate, $row['promised_date'], 'promised date persisted');
$t->assertEqualsMoney(50000, $row['promised_amount'], 'promised amount persisted');

// An invalid intent is rejected outright — thrown before any row is touched.
$inv2 = fx_invoice($case, $doc, 'draft', 'draft', 0);
fx_treatment($case, 30000, $inv2);
$t->assertThrows(fn() => $ci->send($case, $inv2, $doc, '', ['payment_intent' => 'bitcoin']), 'intent whitelist');
$row2 = $db->fetch('SELECT accounting_status, payment_intent FROM case_invoices WHERE id = ?', [$inv2]);
$t->assertSame('draft', $row2['accounting_status'], 'rejected intent leaves the invoice a draft');
$t->assertSame(null, $row2['payment_intent'], 'rejected intent stores nothing');

// Existing callers (no 5th arg / an empty array) keep working exactly as
// before — no intent is stored.
$inv3 = fx_invoice($case, $doc, 'draft', 'draft', 0);
fx_treatment($case, 20000, $inv3);
$res3 = $ci->send($case, $inv3, $doc);
$t->assertTrue(!empty($res3['success']), 'send() with the default 5th-arg [] still works');
$row3 = $db->fetch('SELECT payment_intent, accounting_status FROM case_invoices WHERE id = ?', [$inv3]);
$t->assertSame(null, $row3['payment_intent'], 'no intent stored when the caller omits it');
$t->assertSame('sent', $row3['accounting_status'], 'still sends normally');

// Sending (whatever the intent) books nothing in the ledger — only an
// accountant's approval does. The three invoices above are all 'sent', none
// 'approved', so the case's approved total must still be zero.
$summary = (new AccountingLedger())->getSummary($case);
$t->assertEqualsMoney(0, $summary['approved'], 'sent-not-approved books nothing in the ledger summary');

// ── وەرگیراو single-pending-request semantics (review fix round 2) ─────
// cases/view.php's set_invoice_paid doctor branch: single pending request
// per invoice, adjustable. On each POST it looks up the doctor's own
// still-pending request against this invoice via
// find_doctor_pending_request(); an identical resubmit is a no-op ('unchanged'
// flash), a genuinely different target REPLACES that one row's amount via
// Payment::updatePendingAmount() ('updated' flash — planPaidTarget()'s delta
// is computed against APPROVED coverage only, so it already IS the new total
// for that one payment, never an add-on), and only when nothing is pending
// does it fall through to Payment::createRequest() (idempotency_key from
// doctor_request_idempotency_key(), now amount-aware too — defense in depth
// on the create path itself; classes/Payment.php:131-136's findIdempotent()
// has no status filter, so a bare key would keep matching a rejected row
// forever).
//
// cases/view.php can't be require()'d directly here (it's a full HTTP page:
// auth redirect, $_GET-driven bootstrap, header()+exit calls throughout), so
// test_find_doctor_pending_request() / test_doctor_idempotency_key() /
// test_doctor_submit_paid() below reproduce cases/view.php's
// find_doctor_pending_request() / doctor_request_idempotency_key() / the
// set_invoice_paid doctor branch's decision tree verbatim, and drive the
// real Payment class with what they produce — the part that actually has to
// behave correctly. KEEP THESE THREE IN SYNC with cases/view.php any time
// that decision tree changes (cases/view.php's doctor_request_idempotency_key()
// docblock carries the matching reminder).
require_once __DIR__ . '/../../classes/Payment.php';

// Review fix (2026-08-26): cases/view.php's find_doctor_pending_request() is
// now CASE-scoped, not invoice-scoped (delegates to its case-level twin,
// find_doctor_case_pending_request()) — a per-invoice
// `pa.invoice_id = :inv` join was invisible to a pending request
// FIFO-allocated onto a DIFFERENT invoice of the same case (the normal
// shape: one invoice per work day), so a doctor's second entry point
// created a SECOND pending row instead of adjusting the first one. $invoiceId
// is kept only for call-site compatibility with the real function's
// signature. With only ONE invoice per case (this file's fixtures), the
// whole pending row IS that invoice's allocation, so the caller's
// "newTotal = plan.amount" shortcut in test_doctor_submit_paid() below
// remains exactly correct here — see tests/cases/DoctorCasePaymentRequestTest.php
// for the multi-invoice convergence regression test and the delta-based
// adjust math a real multi-invoice case needs (both mirror
// cases/view.php's set_invoice_paid handler; KEEP ALL OF THESE IN SYNC).
function test_find_doctor_pending_request($db, $caseId, $invoiceId, $doctorId) {
    $row = $db->fetch(
        "SELECT id, amount FROM payments
         WHERE case_id = :cid AND status = 'pending' AND received_by = :doc
         ORDER BY id DESC LIMIT 1",
        ['cid' => (int)$caseId, 'doc' => (int)$doctorId]
    );
    return $row ? ['id' => (int)$row['id'], 'amount' => (float)$row['amount']] : null;
}

function test_doctor_idempotency_key($db, array &$session, $invoiceId, $amount) {
    $sessionKey = 'invoice_paid_request_token_' . (int)$invoiceId;
    $token = $session[$sessionKey] ?? '';
    if ($token !== '') {
        $prior = $db->fetch('SELECT status, amount FROM payments WHERE idempotency_key = :k', ['k' => $token]);
        if (!$prior || $prior['status'] !== 'pending' || abs((float)$prior['amount'] - (float)$amount) > 0.009) {
            $token = '';
        }
    }
    if ($token === '') {
        $token = 'ivp-' . (int)$invoiceId . '-' . (int)round((float)$amount * 100) . '-' . bin2hex(random_bytes(12));
        $session[$sessionKey] = $token;
    }
    return $token;
}

// Mirrors set_invoice_paid's doctor branch, downstream of
// CaseInvoice::planPaidTarget()'s $plan['amount'] (already the correct
// target-vs-approved delta — see this function's own docblock above).
function test_doctor_submit_paid($p, $db, array &$session, $caseId, $invoiceId, $doctorId, $amount) {
    $pending = test_find_doctor_pending_request($db, $caseId, $invoiceId, $doctorId);
    if ($pending && abs($amount - $pending['amount']) <= 0.009) {
        return ['success' => true, 'outcome' => 'unchanged', 'payment_id' => $pending['id']];
    }
    if ($pending) {
        $p->updatePendingAmount($pending['id'], $amount, $doctorId, 'گۆڕینی بڕ لەلایەن دکتۆر'); // throws on failure
        return ['success' => true, 'outcome' => 'updated', 'payment_id' => $pending['id']];
    }
    $res = $p->createRequest([
        'case_id' => $caseId, 'invoice_id' => $invoiceId, 'amount' => $amount, 'payment_type' => 'cash',
        'payment_method' => 'cash', 'received_by' => $doctorId,
        'idempotency_key' => test_doctor_idempotency_key($db, $session, $invoiceId, $amount),
    ]);
    $res['outcome'] = 'created';
    return $res;
}

$doc4 = fx_user('doctor');
$acct4 = fx_user('accountant');
$pat4 = fx_patient();
$fold4 = fx_folder($pat4, $doc4);
$case4 = fx_case($fold4);
$inv4 = fx_invoice($case4, $doc4, 'sent', 'sent', 200000);
fx_treatment($case4, 200000, $inv4);
(new CaseManager())->updateCaseTotals($case4);

$p4 = new Payment();
$session4 = [];

// (a) Same target twice → one pending row; the second call is a no-op
// reported as 'unchanged', not a fresh "sent" flash.
$sub1 = test_doctor_submit_paid($p4, $db, $session4, $case4, $inv4, $doc4, 40000);
$t->assertTrue(!empty($sub1['success']), 'first submission creates a pending request');
$t->assertSame('created', $sub1['outcome'], 'first submission is a fresh create');
$pendingId4 = (int)$sub1['payment_id'];

$sub2 = test_doctor_submit_paid($p4, $db, $session4, $case4, $inv4, $doc4, 40000);
$t->assertTrue(!empty($sub2['success']), 'second identical submission does not error');
$t->assertSame('unchanged', $sub2['outcome'], 'second identical submission is reported as unchanged');
$t->assertSame($pendingId4, (int)$sub2['payment_id'], 'second submission points at the SAME pending payment');
$rowCountA = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case4, $doc4])['c'];
$t->assertSame(1, $rowCountA, 'exactly one payment row exists after the identical resubmit');

// (b) A different target while the first is still pending REPLACES it —
// same row id, new amount, allocations re-reserved to match (not stacked).
$allocSumBefore = (float)$db->fetch('SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$pendingId4])['s'];
$t->assertEqualsMoney(40000, $allocSumBefore, 'initial reservation matches the initial amount');

$sub3 = test_doctor_submit_paid($p4, $db, $session4, $case4, $inv4, $doc4, 90000);
$t->assertTrue(!empty($sub3['success']), 'a different target while pending succeeds');
$t->assertSame('updated', $sub3['outcome'], 'a different target while pending is reported as updated');
$t->assertSame($pendingId4, (int)$sub3['payment_id'], 'the update lands on the SAME pending payment, not a new one');
$rowCountB = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case4, $doc4])['c'];
$t->assertSame(1, $rowCountB, 'still exactly one payment row — the new target did not stack a second one');
$updatedAmount = (float)$db->fetch('SELECT amount FROM payments WHERE id = ?', [$pendingId4])['amount'];
$t->assertEqualsMoney(90000, $updatedAmount, 'the pending payment amount changed to the new target');
$allocSumAfter = (float)$db->fetch('SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$pendingId4])['s'];
$t->assertEqualsMoney(90000, $allocSumAfter, 'allocations were re-reserved to match the new amount');

// (c) After the accountant rejects it, a fresh request succeeds — the
// single-pending-request rule must not outlive a decision (must stay green).
$rejectRes4 = $p4->reject($pendingId4, 'نادروستە', $acct4);
$t->assertTrue(!empty($rejectRes4['success']), 'reject succeeds');

$sub4 = test_doctor_submit_paid($p4, $db, $session4, $case4, $inv4, $doc4, 90000);
$t->assertTrue(!empty($sub4['success']), 're-request after rejection succeeds');
$t->assertSame('created', $sub4['outcome'], 're-request after rejection is a fresh create, not folded into the rejected row');
$t->assertTrue((int)$sub4['payment_id'] !== $pendingId4, 're-request after rejection creates a genuinely new payment');
$t->assertSame('pending', $db->fetch('SELECT status FROM payments WHERE id = ?', [(int)$sub4['payment_id']])['status'], 'the new request is pending');
$rowCountC = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case4, $doc4])['c'];
$t->assertSame(2, $rowCountC, 'exactly two payment rows total: the rejected one and the new pending one — no phantom duplicate');
