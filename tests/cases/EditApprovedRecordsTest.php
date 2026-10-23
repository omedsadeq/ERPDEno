<?php // tests/cases/EditApprovedRecordsTest.php
// «تەعدیل» on the other approved records — the rule the clinic set for
// invoices, extended to receipts, instalment plans and X-rays: corrected IN
// PLACE, still approved, approval date/approver untouched, every edit logged,
// and the پەسەندکراو tab's reopen refused for them. Money is the point, so
// each section checks where the corrected figure lands, not only that the
// call said yes.
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/Installment.php';
require_once __DIR__ . '/../../classes/XrayManager.php';
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/ApprovalReversal.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Settings.php';

$db    = Database::getInstance();
$doc   = fx_user('doctor');
$acc   = fx_user('accountant');
$queue = new ApprovalQueue();
$rev   = new ApprovalReversal();
$pm    = new Payment();
$logCount = function ($table, $id, $action) use ($db) {
    return (int)$db->fetch(
        "SELECT COUNT(*) c FROM activity_log WHERE table_name = ? AND record_id = ? AND action = ?",
        [$table, $id, $action]
    )['c'];
};

// ═══════════════════════════ 1. RECEIPTS ═══════════════════════════════
$pat  = fx_patient();
$case = fx_case(fx_folder($pat, $doc));
$invA = fx_invoice($case, $doc, 'sent', 'approved', 100000); fx_treatment($case, 100000, $invA);
$invB = fx_invoice($case, $doc, 'sent', 'approved', 50000);  fx_treatment($case, 50000, $invB);
(new CaseManager())->updateCaseTotals($case);

$made = $pm->directPayment([
    'case_id' => $case, 'payment_type' => 'cash', 'amount' => 60000, 'payment_method' => 'cash',
    'payment_date' => '2026-09-10', 'received_by' => $acc,
], $acc);
$t->assertTrue(!empty($made['success']), 'receipt: fixture receipt booked');
$payId = (int)$made['payment_id'];
$payRow = function () use ($db, $payId) { return $db->fetch('SELECT * FROM payments WHERE id = ?', [$payId]); };
$alloc = function () use ($db, $payId) {
    $out = [];
    foreach ($db->fetchAll('SELECT invoice_id, allocated_amount FROM payment_allocations WHERE payment_id = ?', [$payId]) as $a) {
        $out[(int)$a['invoice_id']] = (float)$a['allocated_amount'];
    }
    return $out;
};
$before = $payRow();
$t->assertEqualsMoney(60000, $alloc()[$invA] ?? 0, 'receipt: booked onto the oldest invoice');

Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
$t->assertTrue(empty($pm->editApproved($payId, 40000, $acc)['success']), 'receipt: switch OFF → refused');
$t->assertSame(false, $queue->detail('payment', $payId)['can_edit_approved'], 'receipt: switch OFF → no تەعدیل');
Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');
$t->assertSame(true, $queue->detail('payment', $payId)['can_edit_approved'], 'receipt: switch ON → تەعدیل offered');

$t->assertTrue(!empty($pm->editApproved($payId, 40000, $acc, 'هەڵە بوو')['success']), 'receipt: smaller amount saved');
$t->assertEqualsMoney(40000, $payRow()['amount'], 'receipt: amount corrected');
$t->assertEqualsMoney(40000, $alloc()[$invA] ?? 0, 'receipt: its allocation follows');
$t->assertSame('approved', $payRow()['status'], 'receipt: still approved');
$t->assertSame($before['payment_date'], $payRow()['payment_date'], 'receipt: date unchanged');
$t->assertSame((string)$before['approved_by'], (string)$payRow()['approved_by'], 'receipt: approver unchanged');
$t->assertSame($before['payment_number'], $payRow()['payment_number'], 'receipt: number unchanged');

$t->assertTrue(!empty($pm->editApproved($payId, 130000, $acc)['success']), 'receipt: larger amount saved');
$a = $alloc();
$t->assertEqualsMoney(100000, $a[$invA] ?? 0, 'receipt: its own invoice topped up first');
$t->assertEqualsMoney(30000, $a[$invB] ?? 0, 'receipt: the rest goes onto the next invoice');

$t->assertTrue(empty($pm->editApproved($payId, 200000, $acc)['success']), 'receipt: more than the case owes refused');
$t->assertEqualsMoney(130000, $payRow()['amount'], 'receipt: refused edit changed nothing');

$t->assertTrue(!empty($pm->editApproved($payId, 110000, $acc)['success']), 'receipt: smaller again');
$a = $alloc();
$t->assertEqualsMoney(100000, $a[$invA] ?? 0, 'receipt: the older invoice stays settled');
$t->assertEqualsMoney(10000, $a[$invB] ?? 0, 'receipt: the newest allocation shrank first');
$t->assertSame(3, $logCount('payments', $payId, 'payment_approved_edited'), 'receipt: every edit logged');
$t->assertSame(3, (int)$queue->detail('payment', $payId)['approved_edits']['count'], 'receipt: the drawer shows the trail');
$t->assertTrue(empty($rev->reopen('payment', $payId, $acc, 'x')['success']), 'receipt: the tab\'s reopen is refused');
$t->assertSame('approved', $payRow()['status'], 'receipt: still approved after the refused reopen');
$t->assertSame(false, $rev->preview('payment', $payId)['reopen']['allowed'], 'receipt: reversal dialog never offers reopen');

$arch = $pm->directPayment([
    'case_id' => $case, 'payment_type' => 'cash', 'amount' => 5000, 'payment_method' => 'cash',
    'notes' => 'وەسڵی کارە ئەنجامدراوەکان AW-1', 'received_by' => $acc,
], $acc);
$archId = (int)$arch['payment_id'];
$t->assertTrue(empty($pm->editApproved($archId, 6000, $acc)['success']), 'receipt: an arch-work receipt is refused');
$archDetail = $queue->detail('payment', $archId);
$t->assertSame(false, $archDetail['can_edit_approved'], 'receipt: an arch-work receipt offers no تەعدیل');
$t->assertTrue(!empty($archDetail['edit_block_reason']), 'receipt: … and says why');

// ═══════════════════════ 2. INSTALMENT PLANS ═══════════════════════════
$pat2  = fx_patient();
$case2 = fx_case(fx_folder($pat2, $doc));
$invC  = fx_invoice($case2, $doc, 'sent', 'approved', 90000); fx_treatment($case2, 90000, $invC);
(new CaseManager())->updateCaseTotals($case2);
$inst = new Installment();
$planId = $inst->proposePlan([
    'case_id' => $case2, 'invoice_id' => $invC, 'total_amount' => 90000, 'down_payment' => 0,
    'number_of_installments' => 3, 'start_date' => '2026-09-01',
], $doc);
$inst->activatePlan($planId, [], $acc);
$db->update('installment_plans', ['approved_at' => '2026-09-02 09:00:00'], 'id = :id', ['id' => $planId]);
$collection = $inst->makeFlexiblePayment($planId, ['amount' => 20000, 'payment_method' => 'cash'], $acc);
$t->assertTrue(!empty($collection['success']), 'plan: fixture collection booked');
$planRow = function () use ($db, $planId) { return $db->fetch('SELECT * FROM installment_plans WHERE id = ?', [$planId]); };
$sched = function () use ($db, $planId) {
    return $db->fetchAll('SELECT amount, paid_amount, status FROM installment_schedule WHERE plan_id = ? ORDER BY installment_number', [$planId]);
};
$t->assertSame(3, count($sched()), 'plan: activated with 3 instalments');

Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
$t->assertThrows(function () use ($inst, $planId, $acc) { $inst->editApprovedPlan($planId, ['number_of_installments' => 6], $acc); },
    'plan: switch OFF → refused');
Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');
$planDetail = $queue->detail('installment', $planId);
$t->assertSame(true, $planDetail['can_edit_approved'], 'plan: switch ON → تەعدیل offered');
$t->assertEqualsMoney(20000, $planDetail['edit_limits']['paid_so_far'], 'plan: the drawer knows the lowest possible total');

$inst->editApprovedPlan($planId, ['number_of_installments' => 6], $acc);
$s = $sched();
$t->assertSame(6, count($s), 'plan: schedule rebuilt for 6 instalments');
$t->assertEqualsMoney(15000, $s[0]['amount'], 'plan: new instalment amount');
$t->assertEqualsMoney(15000, $s[0]['paid_amount'], 'plan: the collection re-applied to the first row');
$t->assertEqualsMoney(5000, $s[1]['paid_amount'], 'plan: … and the rest of it to the second');
$t->assertSame('active', $planRow()['status'], 'plan: still active');
$t->assertSame('2026-09-02 09:00:00', $planRow()['approved_at'], 'plan: approval date unchanged');
$t->assertEqualsMoney(20000, $planRow()['total_paid'], 'plan: collections untouched');
$t->assertThrows(function () use ($inst, $planId, $acc) { $inst->editApprovedPlan($planId, ['total_amount' => 10000], $acc); },
    'plan: a total below what was paid is refused');
$t->assertThrows(function () use ($inst, $planId, $acc) { $inst->editApprovedPlan($planId, ['down_payment' => 5000], $acc); },
    'plan: a down payment with no chair-side cash to back it is refused');
$t->assertSame(1, $logCount('installment_plans', $planId, 'installment_plan_approved_edited'), 'plan: the edit is logged');
$t->assertTrue(empty($rev->reopen('installment', $planId, $acc, 'x')['success']), 'plan: the tab\'s reopen is refused');
$t->assertSame('active', $planRow()['status'], 'plan: still active after the refused reopen');

$collectionPay = (int)$collection['payment_id'];
$t->assertTrue(!empty($pm->editApproved($collectionPay, 25000, $acc)['success']), 'plan: its collection receipt edited');
$t->assertEqualsMoney(25000,
    $db->fetch('SELECT amount FROM installment_payments WHERE payment_id = ?', [$collectionPay])['amount'],
    'plan: the schedule entry follows its receipt');
$t->assertEqualsMoney(25000, $planRow()['total_paid'], 'plan: the plan is recomputed');

// ═════════════════════════════ 3. X-RAYS ═══════════════════════════════
$xm   = new XrayManager();
$pat3 = fx_patient();
$mk = function ($final, $type = 'cash') use ($xm, $pat3, $doc) {
    $r = $xm->createRequest([
        'patient_id' => $pat3, 'requested_by' => $doc, 'xray_type' => 'panoramic', 'xray_reason' => 'pathology',
        'payment_type' => $type, 'amount' => $final, 'original_amount' => $final, 'final_amount' => $final,
        'discount_amount' => 0,
    ]);
    return (int)$r['request_id'];
};
$xr = function ($id) use ($db) { return $db->fetch('SELECT * FROM xray_requests WHERE id = ?', [$id]); };
$debtOf = function ($id) use ($db) { return $db->fetch('SELECT * FROM patient_xray_debts WHERE request_id = ?', [$id]); };

// 3a — paid in full at the counter
$x1 = $mk(50000);
$xm->approvePaymentAndSendToXray($x1, $acc, 'accountant');
$db->update('xray_requests', ['accountant_approved_at' => '2026-09-03 10:00:00', 'paid_at' => '2026-09-03 10:00:00'],
    'id = :id', ['id' => $x1]);
$statusBefore = $xr($x1)['request_status'];

Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
$t->assertTrue(empty($xm->editApprovedSettlement($x1, ['discount_amount' => 10000], $acc, 'accountant')['success']),
    'xray: switch OFF → refused');
Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');
$t->assertSame(true, $queue->detail('xray', $x1)['can_edit_approved'], 'xray: switch ON → تەعدیل offered');

$t->assertTrue(!empty($xm->editApprovedSettlement($x1, ['discount_amount' => 10000, 'discount_reason' => 'خزم'], $acc, 'accountant')['success']),
    'xray: discount added in place');
$t->assertEqualsMoney(40000, $xr($x1)['final_amount'], 'xray: final corrected');
$t->assertSame('paid', $xr($x1)['payment_status'], 'xray: fully paid stays fully paid');
$t->assertSame('2026-09-03 10:00:00', $xr($x1)['paid_at'], 'xray: paid day unchanged');
$t->assertEqualsMoney(40000, $xm->getCashIncome('2026-09-03', '2026-09-03'), 'xray: cash income corrected on its original day');

$t->assertTrue(!empty($xm->editApprovedSettlement($x1, ['received_amount' => 15000], $acc, 'accountant')['success']),
    'xray: counter cash lowered → part cash');
$d = $debtOf($x1);
$t->assertTrue((bool)$d, 'xray: the rest becomes a debt row');
$t->assertEqualsMoney(25000, $d['remaining_amount'] ?? 0, 'xray: … owing the difference');
$t->assertSame('debt_approved', $xr($x1)['payment_status'], 'xray: payment status follows');
$t->assertEqualsMoney(15000, $xm->getDebtCollected('2026-09-03', '2026-09-03'), 'xray: the counter cash stays on the approval day');
$t->assertEqualsMoney(0, $xm->getCashIncome('2026-09-03', '2026-09-03'), 'xray: … and is not counted twice');

$t->assertTrue(!empty($xm->editApprovedSettlement($x1, ['received_amount' => 40000], $acc, 'accountant')['success']),
    'xray: back to paid in full');
$t->assertSame(false, (bool)$debtOf($x1), 'xray: the debt row is gone again');
$t->assertSame('paid', $xr($x1)['payment_status'], 'xray: paid again');
$t->assertSame('2026-09-03 10:00:00', $xr($x1)['paid_at'], 'xray: paid on the approval day');
$t->assertSame('2026-09-03 10:00:00', $xr($x1)['accountant_approved_at'], 'xray: approval date never moved');
$t->assertSame($statusBefore, $xr($x1)['request_status'], 'xray: the X-ray room\'s workflow status untouched');
$t->assertSame(3, $logCount('xray_requests', $x1, 'xray_approved_edited'), 'xray: every edit logged');
$t->assertTrue(empty($rev->reopen('xray', $x1, $acc, 'x')['success']), 'xray: the tab\'s reopen is refused');

// 3b — debt, with money already collected on it later
$x2 = $mk(30000, 'debt');
$xm->approveDebtAndSendToXray($x2, $acc, 'accountant');
$dId = (int)$db->insert('patient_xray_debts', [
    'patient_id' => $pat3, 'request_id' => $x2, 'amount' => 30000, 'remaining_amount' => 20000, 'status' => 'partial',
]);
$db->insert('xray_debt_payments', [
    'debt_id' => $dId, 'amount' => 10000, 'payment_method' => 'cash', 'received_by' => $acc,
    'notes' => 'پارەدانی قەرز', 'created_at' => '2026-09-20 12:00:00',
]);
$money = $queue->detail('xray', $x2)['approved_money'];
$t->assertEqualsMoney(10000, $money['later'], 'xray: the drawer sees the later collection');
$t->assertTrue(empty($xm->editApprovedSettlement($x2, ['discount_amount' => 25000], $acc, 'accountant')['success']),
    'xray: a price below what was already collected is refused');
$t->assertTrue(!empty($xm->editApprovedSettlement($x2, ['discount_amount' => 5000], $acc, 'accountant')['success']),
    'xray: discount on a part-collected debt');
$d = $debtOf($x2);
$t->assertEqualsMoney(25000, $d['amount'], 'xray: debt row follows the new price');
$t->assertEqualsMoney(15000, $d['remaining_amount'], 'xray: … minus what was collected');
$t->assertTrue(!empty($xm->editApprovedSettlement($x2, ['received_amount' => 15000], $acc, 'accountant')['success']),
    'xray: counter cash added');
$t->assertSame('paid', $xr($x2)['payment_status'], 'xray: fully covered → paid');
$t->assertSame(2, (int)$db->fetch('SELECT COUNT(*) c FROM xray_debt_payments WHERE debt_id = ?', [$dId])['c'],
    'xray: the later collection is kept beside the counter cash');
$t->assertEqualsMoney(10000,
    $db->fetch("SELECT amount FROM xray_debt_payments WHERE debt_id = ? AND notes = 'پارەدانی قەرز'", [$dId])['amount'],
    'xray: … untouched');

// 3c — free X-ray: nothing to correct
$x3 = $mk(0, 'dont_cash');
$t->assertTrue(empty($xm->editApprovedSettlement($x3, ['new_amount' => 5000], $acc, 'accountant')['success']),
    'xray: a free request is refused');

// Leave the switch as shipped so later files see the default.
Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
