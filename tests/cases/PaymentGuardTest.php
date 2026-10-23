<?php // tests/cases/PaymentGuardTest.php
// Task 7: Payment::updatePendingAmount + duplicate-approval / reject-reason guards.
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/CaseManager.php';

$db = Database::getInstance();

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

// Approved invoice with treatment capacity, net 100000, so createRequest has
// somewhere to reserve an allocation.
$inv = fx_invoice($case, $doc, 'sent', 'approved', 100000);
fx_treatment($case, 100000, $inv);
(new CaseManager())->updateCaseTotals($case);

$p = new Payment();

$req = $p->createRequest([
    'case_id' => $case, 'amount' => 80000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
]);
$t->assertTrue(!empty($req['success']), 'doctor request created');
$reqId = (int)$req['payment_id'];
$t->assertEqualsMoney(80000, $db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$reqId])['s'], 'initial reservation matches request');

// Amount can be adjusted while pending; reservations follow the new amount.
$t->assertTrue($p->updatePendingAmount($reqId, 60000, $acct, 'گۆڕینی بڕ'), 'amount adjusted while pending');
$t->assertEqualsMoney(60000, $db->fetch("SELECT amount FROM payments WHERE id = ?", [$reqId])['amount'], 'amount stored');
$t->assertEqualsMoney(60000, $db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$reqId])['s'], 'reservations follow the new amount');

// Cannot exceed the case's remaining balance (only 100000 total invoiced).
$t->assertThrows(fn() => $p->updatePendingAmount($reqId, 150000, $acct), 'cannot exceed remaining balance');
// A failed attempt must not have mutated the stored amount or allocations.
$t->assertEqualsMoney(60000, $db->fetch("SELECT amount FROM payments WHERE id = ?", [$reqId])['amount'], 'amount unchanged after rejected over-limit edit');
$t->assertEqualsMoney(60000, $db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$reqId])['s'], 'allocations unchanged after rejected over-limit edit');

$approved = $p->approve($reqId, $acct);
$t->assertTrue(!empty($approved['success']), 'approve succeeds');

// Cannot edit an already-approved request.
$t->assertThrows(fn() => $p->updatePendingAmount($reqId, 50000, $acct), 'cannot edit after approval');

// Double approval is a no-op: no second allocation write, allocation sum unchanged.
$allocSumBefore = (float)$db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$reqId])['s'];
$second = $p->approve($reqId, $acct);
$t->assertSame(false, $second['success'], 'double approve blocked');
$allocSumAfter = (float)$db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$reqId])['s'];
$t->assertEqualsMoney($allocSumBefore, $allocSumAfter, 'no second allocation write on double approve');

// reject() requires a non-empty reason (spec §12: server-enforced reasons).
$req2 = $p->createRequest([
    'case_id' => $case, 'amount' => 20000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
]);
$t->assertTrue(!empty($req2['success']), 'second request created');
$req2Id = (int)$req2['payment_id'];

$rejectEmpty = $p->reject($req2Id, '');
$t->assertSame(false, $rejectEmpty['success'], 'reject requires reason');
$stillPending = $db->fetch("SELECT status FROM payments WHERE id = ?", [$req2Id]);
$t->assertSame('pending', $stillPending['status'], 'payment stays pending when reason missing');

$rejectedOk = $p->reject($req2Id, 'داواکارییەکە هەڵەیە', $acct);
$t->assertTrue(!empty($rejectedOk['success']), 'reject with a real reason succeeds');
$rejectedRow = $db->fetch("SELECT status FROM payments WHERE id = ?", [$req2Id]);
$t->assertSame('rejected', $rejectedRow['status'], 'payment rejected');
