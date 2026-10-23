<?php // tests/cases/InstallmentPendingTest.php
// Task 6: installment lifecycle — pending plans (proposePlan/activatePlan/rejectPlan).
require_once __DIR__ . '/../../classes/Installment.php';
require_once __DIR__ . '/../../classes/CaseManager.php';

$db = Database::getInstance();

// Local helper: propose a minimal pending plan (no down payment, so it never
// needs an invoice) on a given case, doctor-authored.
function fx_pending_plan($caseId, $doctorUserId) {
    $inst = new Installment();
    return $inst->proposePlan([
        'case_id' => $caseId,
        'total_amount' => 120000,
        'number_of_installments' => 4,
        'start_date' => date('Y-m-d'),
        'down_payment' => 0,
    ], $doctorUserId);
}

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

// Approved invoice with treatment capacity, so activatePlan's down-payment
// booking (via Payment::directPayment) has somewhere to allocate.
$inv = fx_invoice($case, $doc, 'sent', 'approved', 300000);
fx_treatment($case, 300000, $inv);
(new CaseManager())->updateCaseTotals($case);

$inst = new Installment($db);   // real constructor

$planId = $inst->proposePlan(['case_id' => $case, 'invoice_id' => $inv, 'total_amount' => 300000,
    'number_of_installments' => 6, 'start_date' => date('Y-m-d'), 'down_payment' => 50000], $doc);
$plan = $db->fetch("SELECT * FROM installment_plans WHERE id = ?", [$planId]);
$t->assertSame('pending', $plan['status'], 'proposal is pending');
$t->assertEqualsMoney(50000, $plan['proposed_down_payment'], 'down payment stored as proposal only');
$t->assertEqualsMoney(0, $plan['down_payment'], 'real down_payment stays zero until activation');
$t->assertSame(0, (int)$db->fetch("SELECT COUNT(*) c FROM installment_schedule WHERE plan_id = ?", [$planId])['c'], 'no schedule yet');
$t->assertSame(0, (int)$db->fetch("SELECT COUNT(*) c FROM payments WHERE case_id = ?", [$case])['c'], 'no money moved');

// pending plans count nowhere
$t->assertSame(0, count(array_filter($inst->getActivePlans(), fn($p) => (int)$p['id'] === $planId)), 'pending plan not in active list');

// A second proposal (or direct createPlan) on the SAME case is blocked while one is pending
$t->assertThrows(fn() => $inst->proposePlan(['case_id' => $case, 'total_amount' => 100000,
    'number_of_installments' => 2, 'start_date' => date('Y-m-d'), 'down_payment' => 0], $doc), 'second proposal on same case blocked while pending');

// activate with an edit — also books the proposed down payment now that the
// case has an approved invoice with capacity for it
$activated = $inst->activatePlan($planId, ['number_of_installments' => 5], $acct);
$t->assertTrue(is_array($activated), 'activatePlan returns an array');
$plan = $db->fetch("SELECT * FROM installment_plans WHERE id = ?", [$planId]);
$t->assertSame('active', $plan['status'], 'activated');
$t->assertSame(5, (int)$db->fetch("SELECT COUNT(*) c FROM installment_schedule WHERE plan_id = ?", [$planId])['c'], 'schedule built on activation');
$t->assertTrue((int)$plan['approved_by'] === $acct, 'approved_by stamped');
$t->assertTrue(!empty($plan['approved_at']), 'approved_at stamped');
$t->assertEqualsMoney(50000, $plan['down_payment'], 'plan down_payment set to the proposed amount on activation');

$payRow = $db->fetch("SELECT * FROM payments WHERE case_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", [$case]);
$t->assertTrue(!empty($payRow), 'down payment payment row exists after activation');
$t->assertEqualsMoney(50000, $payRow['amount'] ?? -1, 'down payment amount booked on activation');

// double-activate blocked; reject requires reason
$t->assertThrows(fn() => $inst->activatePlan($planId, [], $acct), 'double activation blocked');

// Explicit edits[down_payment] equal to the proposed amount books identically
// to OMITTING the key entirely (the case asserted above at line 60). Task 14
// review fix: the Approval Center drawer (assets/js/approval-center.js) was
// found always posting edits[down_payment] using plan.down_payment (always 0
// for a still-pending plan) instead of proposed_down_payment — silently
// zeroing every doctor-proposed down payment on activation. The JS fix now
// always sends the field, but pre-filled from proposed_down_payment; this
// pins that activatePlan() books the SAME result whether the caller omits
// down_payment (falls back internally) or sends it explicitly matching the
// proposal, so a future caller choosing "always send" over "omit" can never
// silently regress this again.
$case3 = fx_case($fold);
$inv3 = fx_invoice($case3, $doc, 'sent', 'approved', 200000);
fx_treatment($case3, 200000, $inv3);
(new CaseManager())->updateCaseTotals($case3);
$plan3Id = $inst->proposePlan(['case_id' => $case3, 'invoice_id' => $inv3, 'total_amount' => 200000,
    'number_of_installments' => 4, 'start_date' => date('Y-m-d'), 'down_payment' => 40000], $doc);
$plan3 = $db->fetch("SELECT * FROM installment_plans WHERE id = ?", [$plan3Id]);
$t->assertEqualsMoney(40000, $plan3['proposed_down_payment'], 'plan3 proposed down payment stored');
$t->assertEqualsMoney(0, $plan3['down_payment'], 'plan3 real down_payment still zero before activation');

$activated3 = $inst->activatePlan($plan3Id, ['down_payment' => 40000, 'number_of_installments' => 4], $acct);
$t->assertTrue(is_array($activated3), 'activatePlan with explicit matching down_payment returns an array');
$plan3 = $db->fetch("SELECT * FROM installment_plans WHERE id = ?", [$plan3Id]);
$t->assertSame('active', $plan3['status'], 'plan3 activated');
$t->assertEqualsMoney(40000, $plan3['down_payment'], 'explicit edits[down_payment]=proposed value books identically to omitting the key');
$pay3 = $db->fetch("SELECT * FROM payments WHERE case_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1", [$case3]);
$t->assertTrue(!empty($pay3), 'down payment payment row exists for plan3');
$t->assertEqualsMoney(40000, $pay3['amount'] ?? -1, 'down payment amount booked for plan3');

$case2 = fx_case($fold);
$t->assertThrows(fn() => $inst->rejectPlan(fx_pending_plan($case2, $doc), '', $acct), 'empty reason rejected');

// reject with a real reason succeeds, and frees the case up for a new proposal
$case2Plan = $db->fetch("SELECT id FROM installment_plans WHERE case_id = ? AND status = 'pending'", [$case2]);
$t->assertTrue($inst->rejectPlan((int)$case2Plan['id'], 'نەخۆش ڕازی نەبوو', $acct), 'reject with reason succeeds');
$rejected = $db->fetch("SELECT * FROM installment_plans WHERE id = ?", [$case2Plan['id']]);
$t->assertSame('rejected', $rejected['status'], 'plan rejected');
$t->assertSame('نەخۆش ڕازی نەبوو', $rejected['reject_reason'], 'reject_reason stored');

// a fresh proposal on case2 is now allowed again (no more blocking pending/active plan)
$newPlanId = fx_pending_plan($case2, $doc);
$t->assertTrue($newPlanId > 0, 'new proposal allowed once the old one is rejected');
