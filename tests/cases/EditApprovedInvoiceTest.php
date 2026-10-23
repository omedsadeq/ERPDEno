<?php // tests/cases/EditApprovedInvoiceTest.php
// CaseInvoice::editApproved() — an APPROVED invoice is corrected in place.
// The clinic rejected the earlier reopen flow (back to «چاوەڕوانی پەسەند»,
// then approve again with a new date); the rule now is: the invoice stays
// approved, and its approval date and approver never change. Pinned here:
//   1. the admin switch gates it (OFF → refused, row untouched)
//   2. with the switch ON, discount / received / intent / note are edited in
//      place and accounting_status, approved_by, approved_at stay as they were
//   3. every real edit is logged as invoice_approved_edited; a no-op is not
//   4. the caps count later receipts: received cannot push paid above net, a
//      discount cannot push net below what was paid, and the intent sees them
//   5. an ACTIVE instalment plan freezes the money (the note still saves)
//   6. ApprovalQueue::detailInvoice exposes can_edit_approved + the edit trail
//   7. ApprovalReversal no longer sends an invoice back to awaiting review
//   8. accountantAdjust() now records received-amount changes in the log
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/ApprovalReversal.php';
require_once __DIR__ . '/../../classes/Settings.php';

$db = Database::getInstance();

$doc  = fx_user('doctor');
$acc  = fx_user('accountant');
$pat  = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

$ci    = new CaseInvoice();
$queue = new ApprovalQueue();
$inv   = fx_invoice($case, $doc, 'draft', 'draft', 0);
fx_treatment($case, 100000, $inv);
$ci->send($case, $inv, $doc, '', ['payment_intent' => 'debt', 'promised_date' => null, 'promised_amount' => null]);
$t->assertTrue(!empty($ci->approve($case, $inv, $acc, 'yekem')['success']), 'invoice approved');

// Pin the approval to a known past moment so "unchanged" means something.
$approvedAt = '2026-09-01 10:00:00';
$db->update('case_invoices', ['approved_at' => $approvedAt], 'id = :id', ['id' => $inv]);

$row = function () use ($db, $inv) {
    return $db->fetch('SELECT * FROM case_invoices WHERE id = ?', [$inv]);
};
$edits = function () use ($db, $inv) {
    return (int)$db->fetch(
        "SELECT COUNT(*) c FROM activity_log
         WHERE action = 'invoice_approved_edited' AND table_name = 'case_invoices' AND record_id = ?",
        [$inv]
    )['c'];
};
$approvalIntact = function ($label) use ($t, $row, $acc, $approvedAt) {
    $r = $row();
    $t->assertSame('approved', $r['accounting_status'], $label . ': still approved');
    $t->assertSame($approvedAt, $r['approved_at'], $label . ': approval date unchanged');
    $t->assertSame((string)$acc, (string)$r['approved_by'], $label . ': approver unchanged');
};

// ── 1. switch OFF ─────────────────────────────────────────────────────────
Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
$t->assertSame(false, $queue->detail('invoice', $inv)['can_edit_approved'], 'OFF: drawer boxes stay locked');
$t->assertTrue(empty($ci->editApproved($inv, ['discount_mode' => 'amount', 'discount_value' => 5000], $acc)['success']),
    'OFF: in-place edit refused');
$t->assertEqualsMoney(0, $row()['discount_amount'], 'OFF: refused edit changed nothing');

// ── 2. switch ON — edits land, the approval does not move ─────────────────
Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');
$t->assertSame(true, $queue->detail('invoice', $inv)['can_edit_approved'], 'ON: drawer boxes live');

$t->assertTrue(!empty($ci->editApproved($inv, [
    'discount_mode' => 'amount', 'discount_value' => 10000, 'accounting_notes' => 'چاککرا',
], $acc)['success']), 'discount + note saved in place');
$t->assertEqualsMoney(10000, $row()['discount_amount'], 'discount stored');
$t->assertSame('چاککرا', $row()['accounting_notes'], 'note stored');
$approvalIntact('after discount');

$t->assertTrue(!empty($ci->editApproved($inv, ['received_amount' => 30000], $acc)['success']), 'received saved in place');
$t->assertSame('debt', $row()['payment_intent'], 'money still owed → debt');
$approvalIntact('after received');

$t->assertTrue(!empty($ci->editApproved($inv, ['received_amount' => 90000], $acc)['success']), 'fully received');
$t->assertSame('cash', $row()['payment_intent'], 'nothing owed → cash');
$t->assertSame('processed', $row()['status'], 'settled invoice is marked paid');
$approvalIntact('after settling');

$t->assertTrue(!empty($ci->editApproved($inv, ['received_amount' => 50000, 'payment_intent' => 'cash'], $acc)['success']),
    'received lowered again');
$t->assertSame('debt', $row()['payment_intent'], 'cash with a balance is saved as debt');
$t->assertSame('sent', $row()['status'], 'balance again → no longer marked paid');
$approvalIntact('after lowering');

// ── 3. audit trail ────────────────────────────────────────────────────────
$t->assertSame(4, $edits(), 'each real edit logged once');
$t->assertTrue(!empty($ci->editApproved($inv, ['received_amount' => 50000], $acc)['success']), 'no-op edit accepted');
$t->assertSame(4, $edits(), 'no-op edit not logged');
$lastNew = json_decode($db->fetch(
    "SELECT new_values FROM activity_log WHERE action = 'invoice_approved_edited' AND record_id = ? ORDER BY id DESC LIMIT 1",
    [$inv]
)['new_values'], true);
$t->assertTrue(array_key_exists('draft_received_amount', $lastNew), 'log names the received figure');
$t->assertTrue(!array_key_exists('approved_at', $lastNew) && !array_key_exists('accounting_status', $lastNew),
    'log shows the approval itself was never written');

// ── 4. caps count later receipts ──────────────────────────────────────────
$t->assertTrue(empty($ci->editApproved($inv, ['received_amount' => 95000], $acc)['success']),
    'received above net refused');
$t->assertTrue(empty($ci->editApproved($inv, ['discount_mode' => 'amount', 'discount_value' => 60000], $acc)['success']),
    'discount below money already received refused');
$pay = (int)$db->insert('payments', [
    'case_id' => $case, 'payment_number' => 'TPAY' . $case . 'E_' . substr(uniqid(), -6),
    'payment_type' => 'cash', 'amount' => 20000, 'payment_method' => 'cash',
    'status' => 'approved', 'received_by' => $acc, 'payment_date' => date('Y-m-d'),
]);
$db->insert('payment_allocations', ['payment_id' => $pay, 'invoice_id' => $inv, 'allocated_amount' => 20000]);
$t->assertTrue(empty($ci->editApproved($inv, ['received_amount' => 75000], $acc)['success']),
    'received + later receipt above net refused');
$t->assertTrue(!empty($ci->editApproved($inv, ['received_amount' => 70000], $acc)['success']),
    'received + later receipt exactly net accepted');
$t->assertSame('cash', $row()['payment_intent'], 'later receipt counted: fully paid → cash');
$approvalIntact('with a later receipt');

// ── 5. an active plan freezes the money, not the note ─────────────────────
$plan = (int)$db->insert('installment_plans', [
    'case_id' => $case, 'invoice_id' => $inv, 'total_amount' => 90000, 'remaining_amount' => 20000,
    'number_of_installments' => 2, 'installment_amount' => 10000, 'start_date' => date('Y-m-d'),
    'status' => 'active', 'created_by' => $acc,
]);
$t->assertTrue(empty($ci->editApproved($inv, ['discount_mode' => 'amount', 'discount_value' => 0], $acc)['success']),
    'active plan: money frozen');
$t->assertTrue(!empty($ci->editApproved($inv, ['accounting_notes' => 'تەنها تێبینی'], $acc)['success']),
    'active plan: note still editable');
$db->delete('installment_plans', 'id = :id', ['id' => $plan]);

// ── 6. the drawer shows the edit trail ────────────────────────────────────
$trail = $queue->detail('invoice', $inv)['approved_edits'];
$t->assertSame($edits(), (int)$trail['count'], 'drawer trail counts every in-place edit');
$t->assertTrue(!empty($trail['last_at']), 'drawer trail carries the latest edit time');

// ── 7. the پەسەندکراو tab's reopen no longer applies to invoices ─────────
$rev = new ApprovalReversal();
$t->assertTrue(empty($rev->reopen('invoice', $inv, $acc, 'x')['success']), 'ApprovalReversal refuses to reopen an invoice');
$t->assertSame(false, $rev->preview('invoice', $inv)['reopen']['allowed'], 'reversal preview never offers invoice reopen');
$approvalIntact('after a refused reopen');

// ── 8. accountantAdjust logs received-amount changes (were dropped) ───────
$inv2 = fx_invoice($case, $doc, 'draft', 'draft', 0);
fx_treatment($case, 40000, $inv2);
$ci->send($case, $inv2, $doc, '', ['payment_intent' => 'debt', 'promised_date' => null, 'promised_amount' => null]);
$t->assertTrue(!empty($ci->accountantAdjust($inv2, ['received_amount' => 15000], $acc)['success']), 'sent: received adjusted');
$adj = $db->fetch(
    "SELECT new_values FROM activity_log WHERE action = 'invoice_accountant_adjusted' AND record_id = ? ORDER BY id DESC LIMIT 1",
    [$inv2]
);
$t->assertTrue($adj && array_key_exists('draft_received_amount', (array)json_decode($adj['new_values'], true)),
    'accountantAdjust audit entry now names the received figure');

// Leave the switch as shipped so later files see the default.
Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
