<?php // tests/cases/XrayFreeRouteTest.php
// A "don't cash" x-ray (پارە لە نەخۆش وەرناگیرێت) has no accountant stage at
// all — there is nothing to collect and nothing to approve — so it must reach
// the x-ray department directly, and the patient must be queued at the x-ray
// station rather than at accounting.
//
// This file locks that rule at every point it can be broken:
//   1. the route XrayManager::createRequest() writes,
//   2. the waiting-room position PatientFlow mirrors from it,
//   3. the accountant queue never seeing it,
//   4. the x-ray department's own list seeing it,
//   5. "return to accountant" being refused on it,
// plus the cash counterpart at each step, so a fix for the free path cannot
// quietly break the paid one.
require_once __DIR__ . '/../../classes/XrayManager.php';
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/PatientFlow.php';

$db = Database::getInstance();
$xm = new XrayManager();
$queue = new ApprovalQueue();

$doc  = fx_user('doctor');
$acct = fx_user('accountant');

/** The payload xray/request.php:88-109 builds, minus the routing fields. */
function xfr_payload($patientId, $doctorId, $paymentType) {
    return [
        'patient_id'      => $patientId,
        'requested_by'    => $doctorId,
        'xray_type'       => 'periapical',
        'xray_reason'     => 'endo',
        'payment_type'    => $paymentType,
        'payment_reason'  => $paymentType === 'dont_cash' ? 'Test: free for this patient' : '',
        'amount'          => $paymentType === 'dont_cash' ? 0 : 25000,
        'original_amount' => $paymentType === 'dont_cash' ? 0 : 25000,
        'final_amount'    => $paymentType === 'dont_cash' ? 0 : 25000,
        'priority'        => 'normal',
    ];
}

function xfr_row($db, $id) {
    return $db->fetch(
        "SELECT request_status, payment_status, status FROM xray_requests WHERE id = :id",
        ['id' => $id]
    );
}

// ================================================================
// 1. The route createRequest() writes
// ================================================================

// 1a. Free request, exactly as xray/request.php sends it.
$patFree = fx_patient();
$free = $xm->createRequest(xfr_payload($patFree, $doc, 'dont_cash') + [
    'request_status' => 'pending_xray',
    'payment_status' => 'not_required',
]);
$t->assertTrue(!empty($free['success']), 'free request created');
$freeId = (int)($free['request_id'] ?? 0);
$freeRow = xfr_row($db, $freeId);
$t->assertSame('pending_xray', $freeRow['request_status'], 'dont_cash goes straight to the x-ray department');
$t->assertSame('not_required', $freeRow['payment_status'], 'dont_cash owes nothing');

// 1b. The same payload with the routing fields OMITTED. xray_requests
// .request_status has DEFAULT 'pending_accountant' (accountant/
// update_xray_full_workflow.sql:3), so before the rule moved into
// createRequest() any caller that forgot them silently parked a free request
// in the accountant queue — and left PatientFlow::syncFromXray() unfired,
// because that call is gated on the field being present.
$patFree2 = fx_patient();
$free2 = $xm->createRequest(xfr_payload($patFree2, $doc, 'dont_cash'));
$t->assertTrue(!empty($free2['success']), 'free request created without explicit routing fields');
$free2Row = xfr_row($db, (int)$free2['request_id']);
$t->assertSame('pending_xray', $free2Row['request_status'], 'dont_cash is routed from payment_type, not from what the caller passed');
$t->assertSame('not_required', $free2Row['payment_status'], 'dont_cash payment status is derived too');

// 1c. A caller actively passing the WRONG route must not win either.
$patFree3 = fx_patient();
$free3 = $xm->createRequest(xfr_payload($patFree3, $doc, 'dont_cash') + [
    'request_status' => 'pending_accountant',
    'payment_status' => 'pending_payment',
]);
$free3Row = xfr_row($db, (int)$free3['request_id']);
$t->assertSame('pending_xray', $free3Row['request_status'], 'a wrong request_status from the caller is overridden for dont_cash');
$t->assertSame('not_required', $free3Row['payment_status'], 'a wrong payment_status from the caller is overridden for dont_cash');

// 1d. Cash still stops at the accountant — the rule above must not leak.
$patCash = fx_patient();
$cash = $xm->createRequest(xfr_payload($patCash, $doc, 'cash') + [
    'request_status' => 'pending_accountant',
    'payment_status' => 'pending_payment',
]);
$t->assertTrue(!empty($cash['success']), 'cash request created');
$cashId = (int)$cash['request_id'];
$cashRow = xfr_row($db, $cashId);
$t->assertSame('pending_accountant', $cashRow['request_status'], 'cash still goes to the accountant first');
$t->assertSame('pending_payment', $cashRow['payment_status'], 'cash still owes payment');

// 1e. Debt likewise, and a debt caller omitting the route falls back to the
// accountant rather than to the DB default by accident.
$patDebt = fx_patient();
$debt = $xm->createRequest(xfr_payload($patDebt, $doc, 'debt'));
$debtRow = xfr_row($db, (int)$debt['request_id']);
$t->assertSame('pending_accountant', $debtRow['request_status'], 'debt goes to the accountant first');

// ================================================================
// 2. Where the patient physically stands (waiting_room)
// ================================================================
// PatientFlow::syncFromXray() mirrors the route onto the queue; with no
// check-in today it creates the entry directly at that station
// (classes/PatientFlow.php:216-223).

$freeEntry = PatientFlow::findActiveEntry($patFree);
$t->assertTrue($freeEntry !== null, 'a free request queues the patient in the waiting room');
$t->assertSame('at_xray', $freeEntry['status'] ?? '', 'a free request puts the patient at the x-ray station');

$cashEntry = PatientFlow::findActiveEntry($patCash);
$t->assertTrue($cashEntry !== null, 'a cash request queues the patient in the waiting room');
$t->assertSame('at_accounting', $cashEntry['status'] ?? '', 'a cash request puts the patient at the accounting station');

// The omitted-routing-fields case must queue the patient too — that call is
// gated on request_status being set, which 1b now guarantees.
$free2Entry = PatientFlow::findActiveEntry($patFree2);
$t->assertSame('at_xray', $free2Entry['status'] ?? '', 'the derived route still reaches the waiting room');

// ================================================================
// 3. The accountant queue must never show a free request
// ================================================================
$xrayQueueIds = array_map(
    static function (array $r) { return (int)$r['id']; },
    $queue->list('xray')
);
$t->assertTrue(!in_array($freeId, $xrayQueueIds, true), 'a free request is absent from the accountant queue');
$t->assertTrue(in_array($cashId, $xrayQueueIds, true), 'a cash request IS in the accountant queue');

// ================================================================
// 4. The x-ray department's own list must show it
// ================================================================
// The default filter xray/index.php:65-66 applies for the x-ray department.
$deptIds = array_map(
    static function (array $r) { return (int)$r['id']; },
    $xm->getAll(1, 1000, ['request_status_in' => ['pending_xray', 'in_xray']])
);
$t->assertTrue(in_array($freeId, $deptIds, true), 'a free request is in the x-ray department queue immediately');
$t->assertTrue(!in_array($cashId, $deptIds, true), 'a cash request is not, until the accountant releases it');

// ================================================================
// 5. "Return to accountant" is refused on a free request
// ================================================================
// There is no accountant stage to return to: the accountant would find a
// 0-amount row with nothing to approve, and their only way out is
// sendAgainToXray(), which leaves payment_status at not_required — the round
// trip that makes a free request look like it passed through accounting.
$returned = $xm->returnToAccounting($freeId, 'test: should be refused', $acct, 'xray_department');
$t->assertTrue(empty($returned['success']), 'returning a free request to the accountant is refused');
$t->assertSame('pending_xray', xfr_row($db, $freeId)['request_status'], 'the refused return leaves the free request at the x-ray department');
$t->assertTrue(!in_array($freeId, array_map(
    static function (array $r) { return (int)$r['id']; },
    $queue->list('xray')
), true), 'the refused return did not leak the free request into the accountant queue');

// The paid path keeps its return: that one has a real accountant stage.
$xm->approvePaymentAndSendToXray($cashId, $acct, 'accountant', 'test: collected');
$t->assertSame('pending_xray', xfr_row($db, $cashId)['request_status'], 'the accountant releases the cash request to x-ray');
$cashReturn = $xm->returnToAccounting($cashId, 'test: amount was wrong', $acct, 'xray_department');
$t->assertTrue(!empty($cashReturn['success']), 'a paid request can still be returned to the accountant');
$t->assertSame('returned_to_accountant', xfr_row($db, $cashId)['request_status'], 'the returned cash request is back with the accountant');

// ================================================================
// 6. The free request completes without ever touching accounting
// ================================================================
$t->assertTrue(!empty($xm->startXray($freeId, $doc, 'xray_department')['success']), 'the free request can be started');
$t->assertSame('in_xray', xfr_row($db, $freeId)['request_status'], 'started');
$t->assertTrue(!empty($xm->completeXray($freeId, $doc, 'xray_department')['success']), 'the free request can be completed');
$freeFinal = xfr_row($db, $freeId);
$t->assertSame('completed', $freeFinal['request_status'], 'the free request finishes at completed');
$t->assertSame('not_required', $freeFinal['payment_status'], 'and still owes nothing at the end — no accountant ever touched it');
