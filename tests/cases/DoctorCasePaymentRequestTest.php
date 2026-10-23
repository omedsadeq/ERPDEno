<?php // tests/cases/DoctorCasePaymentRequestTest.php
// Doctor case-level payment REQUEST (cases/view.php's `request_payment`
// action) — the case-page twin of DoctorFlowTest's وەرگیراو-per-invoice
// semantics (Task 12), minus a target invoice: the doctor and patient agree
// an amount at the chair, the doctor sends it, Payment::createRequest()
// reserves it FIFO across the case's own invoices and it lands PENDING in
// the accountant's Approval Center — never booking money directly.
//
// cases/view.php can't be require()'d directly here (full HTTP page: auth
// redirect, $_GET bootstrap, header()+exit calls throughout), so
// test_find_doctor_case_pending_request() / test_case_doctor_idempotency_key()
// / test_doctor_submit_case_payment() below reproduce cases/view.php's
// find_doctor_case_pending_request() / case_doctor_request_idempotency_key()
// / the request_payment handler's decision tree verbatim, and drive the real
// Payment class with what they produce. KEEP THESE THREE IN SYNC with
// cases/view.php any time that decision tree changes — same rule
// DoctorFlowTest's own twin helpers carry.
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';

$db = Database::getInstance();

// Mirrors cases/view.php's find_doctor_case_pending_request(): unlike the
// invoice-scoped find_doctor_pending_request() (no join needed — every
// payment row already carries its own case_id, whichever entry point wrote
// it), so this ALSO finds a still-pending row the invoice-level
// find_doctor_pending_request() would have created — there is only ever one
// adjustable pending request per doctor per case, shared between both entry
// points.
function test_find_doctor_case_pending_request($db, $caseId, $doctorId) {
    $row = $db->fetch(
        "SELECT id, amount FROM payments
         WHERE case_id = :cid AND status = 'pending' AND received_by = :doc
         ORDER BY id DESC LIMIT 1",
        ['cid' => (int)$caseId, 'doc' => (int)$doctorId]
    );
    return $row ? ['id' => (int)$row['id'], 'amount' => (float)$row['amount']] : null;
}

// Mirrors cases/view.php's case_doctor_request_idempotency_key() — same
// amount-aware rotation rules as doctor_request_idempotency_key(), keyed per
// case instead of per invoice.
function test_case_doctor_idempotency_key($db, array &$session, $caseId, $amount) {
    $sessionKey = 'case_payment_request_token_' . (int)$caseId;
    $token = $session[$sessionKey] ?? '';
    if ($token !== '') {
        $prior = $db->fetch('SELECT status, amount FROM payments WHERE idempotency_key = :k', ['k' => $token]);
        if (!$prior || $prior['status'] !== 'pending' || abs((float)$prior['amount'] - (float)$amount) > 0.009) {
            $token = '';
        }
    }
    if ($token === '') {
        $token = 'cvp-' . (int)$caseId . '-' . (int)round((float)$amount * 100) . '-' . bin2hex(random_bytes(12));
        $session[$sessionKey] = $token;
    }
    return $token;
}

// Mirrors cases/view.php's request_payment handler's decision tree
// (post amount-validation — this helper assumes the caller already checked
// amount > 0 and amount <= the exclusion-aware unrequested cap, exactly as
// the real handler does via Payment::getCaseBalance($caseId, $pendingId, true)).
function test_doctor_submit_case_payment($p, $db, array &$session, $caseId, $doctorId, $amount) {
    $pending = test_find_doctor_case_pending_request($db, $caseId, $doctorId);
    if ($pending && abs($amount - $pending['amount']) <= 0.009) {
        return ['success' => true, 'outcome' => 'unchanged', 'payment_id' => $pending['id']];
    }
    if ($pending) {
        $p->updatePendingAmount($pending['id'], $amount, $doctorId, 'گۆڕینی بڕ لەلایەن دکتۆر'); // throws on failure
        return ['success' => true, 'outcome' => 'updated', 'payment_id' => $pending['id']];
    }
    $res = $p->createRequest([
        'case_id' => $caseId, 'amount' => $amount, 'payment_type' => 'cash',
        'payment_method' => 'cash', 'received_by' => $doctorId,
        'idempotency_key' => test_case_doctor_idempotency_key($db, $session, $caseId, $amount),
    ]);
    $res['outcome'] = 'created';
    return $res;
}

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);
// One APPROVED invoice, net 100000 — this is the case's whole billable total.
$inv = fx_invoice($case, $doc, 'sent', 'approved', 100000);
fx_treatment($case, 100000, $inv);
(new CaseManager())->updateCaseTotals($case);

$p = new Payment();
$session = [];

// RED → GREEN: a fresh case-level request for 50000 against a 100000 case
// creates exactly one pending payment row with allocations reserved 50000.
$sub1 = test_doctor_submit_case_payment($p, $db, $session, $case, $doc, 50000);
$t->assertTrue(!empty($sub1['success']), 'case-level request for 50000 succeeds');
$t->assertSame('created', $sub1['outcome'], 'first submission is a fresh create');
$pendingId = (int)$sub1['payment_id'];
$rowCount1 = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case, $doc])['c'];
$t->assertSame(1, $rowCount1, 'exactly one payment row after the first request');
$row1 = $db->fetch('SELECT status, amount, case_id FROM payments WHERE id = ?', [$pendingId]);
$t->assertSame('pending', $row1['status'], 'the request is pending, never booked directly');
$t->assertEqualsMoney(50000, $row1['amount'], 'pending amount matches the request');
$t->assertSame((int)$case, (int)$row1['case_id'], 'the pending row is scoped to the case (case-level entry point wrote no invoice_id)');
$allocSum1 = (float)$db->fetch('SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$pendingId])['s'];
$t->assertEqualsMoney(50000, $allocSum1, 'FIFO allocation reserved against the case invoice matches the requested amount');

// Same amount again with the same session key → the SAME payment id, still
// exactly one row (idempotent resubmit, reported 'unchanged').
$sub2 = test_doctor_submit_case_payment($p, $db, $session, $case, $doc, 50000);
$t->assertTrue(!empty($sub2['success']), 'identical resubmit does not error');
$t->assertSame('unchanged', $sub2['outcome'], 'identical resubmit is reported as unchanged');
$t->assertSame($pendingId, (int)$sub2['payment_id'], 'identical resubmit points at the SAME pending payment');
$rowCount2 = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case, $doc])['c'];
$t->assertSame(1, $rowCount2, 'still exactly one payment row after the identical resubmit');

// A different amount while still pending → the SAME row id, new amount via
// Payment::updatePendingAmount (not a second stacked row).
$sub3 = test_doctor_submit_case_payment($p, $db, $session, $case, $doc, 75000);
$t->assertTrue(!empty($sub3['success']), 'a different target while pending succeeds');
$t->assertSame('updated', $sub3['outcome'], 'a different target while pending is reported as updated');
$t->assertSame($pendingId, (int)$sub3['payment_id'], 'the update lands on the SAME pending payment, not a new one');
$rowCount3 = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case, $doc])['c'];
$t->assertSame(1, $rowCount3, 'still exactly one payment row — the new target did not stack a second one');
$updatedAmount = (float)$db->fetch('SELECT amount FROM payments WHERE id = ?', [$pendingId])['amount'];
$t->assertEqualsMoney(75000, $updatedAmount, 'the pending payment amount changed to the new target');
$allocSum3 = (float)$db->fetch('SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$pendingId])['s'];
$t->assertEqualsMoney(75000, $allocSum3, 'allocations were re-reserved to match the new amount');

// After the accountant rejects it, a fresh request succeeds — the
// single-pending-request rule must not outlive a decision.
$rejectRes = $p->reject($pendingId, 'نادروستە', $acct);
$t->assertTrue(!empty($rejectRes['success']), 'reject succeeds');

$sub4 = test_doctor_submit_case_payment($p, $db, $session, $case, $doc, 60000);
$t->assertTrue(!empty($sub4['success']), 're-request after rejection succeeds');
$t->assertSame('created', $sub4['outcome'], 're-request after rejection is a fresh create, not folded into the rejected row');
$t->assertTrue((int)$sub4['payment_id'] !== $pendingId, 're-request after rejection creates a genuinely new payment');
$t->assertSame('pending', $db->fetch('SELECT status FROM payments WHERE id = ?', [(int)$sub4['payment_id']])['status'], 'the new request is pending');
$rowCount4 = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case, $doc])['c'];
$t->assertSame(2, $rowCount4, 'exactly two payment rows total: the rejected one and the new pending one — no phantom duplicate');

// Reject the second one too so the case is clean for the next assertion.
$p->reject((int)$sub4['payment_id'], 'تاقیکردنەوە', $acct);

// Amount greater than the case's unrequested balance fails outright — the
// page handler wraps this failure in a Kurdish case_view_fail() message;
// here we assert the service-level contract the page relies on: createRequest
// refuses to over-allocate against the case's invoices.
$sub5 = test_doctor_submit_case_payment($p, $db, $session, $case, $doc, 150000);
$t->assertSame(false, $sub5['success'] ?? true, 'a request beyond the case remaining (100000) fails');
$t->assertTrue(!empty($sub5['message']), 'the failure carries a message the page can surface');
$rowCount5 = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$case, $doc])['c'];
$t->assertSame(2, $rowCount5, 'the over-cap attempt created no new payment row');

// ── Multi-invoice convergence (review fix round 2, 2026-08-26) ─────────
// CRITICAL finding: on a case with MORE THAN ONE invoice (the normal shape —
// one invoice per work day), the case-level entry point (above) and the
// per-invoice وەرگیراو entry point (set_invoice_paid) must still land on the
// SAME single pending row, even when a case-level request FIFO-allocates
// onto one invoice and the doctor then works from a DIFFERENT invoice's box.
// Before this fix, find_doctor_pending_request() (invoice-scoped) was blind
// to a pending allocation sitting on any OTHER invoice, so it created a
// SECOND parallel row instead of adjusting the first — this is exactly what
// the single-invoice tests above could never catch.
//
// pending_request_invoice_allocation() mirrors cases/view.php's helper of
// the same name; test_doctor_submit_invoice_payment() mirrors
// set_invoice_paid's doctor branch's REVISED decision tree (delta against
// THIS invoice's own slice of the shared row, applied to the row's
// case-wide total — see cases/view.php's own comment there for the full
// reasoning). $planAmount/$planTarget come from the REAL
// CaseInvoice::planPaidTarget(), not a re-implementation.
function test_pending_invoice_allocation($db, $paymentId, $invoiceId) {
    return (float)($db->fetch(
        'SELECT COALESCE(SUM(allocated_amount),0) AS amt FROM payment_allocations WHERE payment_id = ? AND invoice_id = ?',
        [$paymentId, $invoiceId]
    )['amt'] ?? 0);
}

function test_doctor_submit_invoice_payment($p, $db, $caseId, $invoiceId, $doctorId, $planAmount) {
    $pending = test_find_doctor_case_pending_request($db, $caseId, $doctorId);
    if (!$pending) {
        $res = $p->createRequest([
            'case_id' => $caseId, 'invoice_id' => $invoiceId, 'amount' => $planAmount, 'payment_type' => 'cash',
            'payment_method' => 'cash', 'received_by' => $doctorId,
            'idempotency_key' => 'test-conv-' . bin2hex(random_bytes(8)),
        ]);
        $res['outcome'] = 'created';
        return $res;
    }
    $pendingOnThisInvoice = test_pending_invoice_allocation($db, $pending['id'], $invoiceId);
    $delta = round($planAmount - $pendingOnThisInvoice, 2);
    $otherInvoicesPending = round($pending['amount'] - $pendingOnThisInvoice, 2);
    $newTotal = max(round($pending['amount'] + $delta, 2), $otherInvoicesPending);
    if (abs($delta) <= 0.009 || abs($newTotal - $pending['amount']) <= 0.009) {
        return ['success' => true, 'outcome' => 'unchanged', 'payment_id' => $pending['id']];
    }
    $p->updatePendingAmount($pending['id'], $newTotal, $doctorId, 'گۆڕینی بڕ لەلایەن دکتۆر — تاقیکردنەوە');
    return ['success' => true, 'outcome' => 'updated', 'payment_id' => $pending['id'], 'new_total' => $newTotal];
}

$docM = fx_user('doctor');
$acctM = fx_user('accountant');
$patM = fx_patient();
$foldM = fx_folder($patM, $docM);
$caseM = fx_case($foldM);
// Two APPROVED invoices on the SAME case — "older" (net 50000, created
// first, so it sorts first: AccountingLedger orders by work_date then
// invoice_seq, and fx_invoice assigns increasing seq per case) and "newer"
// (net 60000).
$invOlder = fx_invoice($caseM, $docM, 'sent', 'approved', 50000);
fx_treatment($caseM, 50000, $invOlder);
$invNewer = fx_invoice($caseM, $docM, 'sent', 'approved', 60000);
fx_treatment($caseM, 60000, $invNewer);
(new CaseManager())->updateCaseTotals($caseM);

$pM = new Payment();
$ciM = new CaseInvoice();
$sessionM = [];

// Direction A: case-level FIRST (FIFO → the older invoice), invoice-path
// SECOND (targets the newer invoice) → must converge on ONE row.
$stepA1 = test_doctor_submit_case_payment($pM, $db, $sessionM, $caseM, $docM, 50000);
$t->assertTrue(!empty($stepA1['success']), 'multi-invoice A: case-level request for 50000 succeeds');
$rowIdA = (int)$stepA1['payment_id'];
$t->assertEqualsMoney(50000, test_pending_invoice_allocation($db, $rowIdA, $invOlder), 'multi-invoice A: FIFO put the whole case-level request on the older invoice');
$t->assertEqualsMoney(0, test_pending_invoice_allocation($db, $rowIdA, $invNewer), 'multi-invoice A: nothing yet on the newer invoice');

$planA = $ciM->planPaidTarget($caseM, $invNewer, 20000);
$t->assertTrue(!empty($planA['success']), 'multi-invoice A: planPaidTarget succeeds for the newer invoice target');
$stepA2 = test_doctor_submit_invoice_payment($pM, $db, $caseM, $invNewer, $docM, $planA['amount']);
$t->assertTrue(!empty($stepA2['success']), 'multi-invoice A: invoice-path submit against the newer invoice succeeds');
$t->assertSame('updated', $stepA2['outcome'], 'multi-invoice A: invoice-path submit ADJUSTS the same unified row, not a fresh create');
$t->assertSame($rowIdA, (int)$stepA2['payment_id'], 'multi-invoice A: still the SAME payment row across both entry points');

$rowCountA = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$caseM, $docM])['c'];
$t->assertSame(1, $rowCountA, 'multi-invoice A: exactly ONE pending row after using both entry points on different invoices');
$t->assertEqualsMoney(70000, (float)$db->fetch('SELECT amount FROM payments WHERE id = ?', [$rowIdA])['amount'], 'multi-invoice A: unified row total is 50000 (older) + 20000 (newer) = 70000');
$t->assertEqualsMoney(70000, (float)$db->fetch('SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?', [$rowIdA])['s'], 'multi-invoice A: allocations re-FIFO across both invoices sum to the new total');
$t->assertEqualsMoney(50000, test_pending_invoice_allocation($db, $rowIdA, $invOlder), 'multi-invoice A: older invoice keeps its full 50000 allocation');
$t->assertEqualsMoney(20000, test_pending_invoice_allocation($db, $rowIdA, $invNewer), 'multi-invoice A: newer invoice gets exactly the 20000 targeted');

// Clean the row so direction B starts from a fresh, unreserved case.
$rejA = $pM->reject($rowIdA, 'تاقیکردنەوە', $acctM);
$t->assertTrue(!empty($rejA['success']), 'multi-invoice A: cleanup reject succeeds');

// Direction B: invoice-path FIRST (targets the newer invoice), case-level
// SECOND (types the case-wide total) → must ALSO converge on ONE row, with
// the same final split.
$planB = $ciM->planPaidTarget($caseM, $invNewer, 20000);
$t->assertTrue(!empty($planB['success']), 'multi-invoice B: planPaidTarget succeeds (fresh state after the reject above)');
$stepB1 = test_doctor_submit_invoice_payment($pM, $db, $caseM, $invNewer, $docM, $planB['amount']);
$t->assertTrue(!empty($stepB1['success']), 'multi-invoice B: invoice-path submit succeeds');
$t->assertSame('created', $stepB1['outcome'], 'multi-invoice B: invoice-path submit creates the row when nothing was pending yet');
$rowIdB = (int)$stepB1['payment_id'];
$t->assertTrue($rowIdB !== $rowIdA, 'multi-invoice B: a genuinely new row (direction A\'s row was rejected)');

$stepB2 = test_doctor_submit_case_payment($pM, $db, $sessionM, $caseM, $docM, 70000);
$t->assertTrue(!empty($stepB2['success']), 'multi-invoice B: case-level submit succeeds');
$t->assertSame('updated', $stepB2['outcome'], 'multi-invoice B: case-level submit ADJUSTS the row the invoice-path just created');
$t->assertSame($rowIdB, (int)$stepB2['payment_id'], 'multi-invoice B: still the SAME payment row across both entry points');

$rowCountB = (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id = ? AND received_by = ?', [$caseM, $docM])['c'];
$t->assertSame(2, $rowCountB, 'multi-invoice B: two rows total for this doctor — the rejected direction-A row plus the one active direction-B row');
$t->assertEqualsMoney(70000, (float)$db->fetch('SELECT amount FROM payments WHERE id = ?', [$rowIdB])['amount'], 'multi-invoice B: row total also converges on 70000');
$t->assertEqualsMoney(50000, test_pending_invoice_allocation($db, $rowIdB, $invOlder), 'multi-invoice B: FIFO re-spread also fills the older invoice fully');
$t->assertEqualsMoney(20000, test_pending_invoice_allocation($db, $rowIdB, $invNewer), 'multi-invoice B: newer invoice keeps its targeted 20000');
