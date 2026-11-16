<?php
// دراوی پێشوو on a sent / approved receipt (Settings::priorPaidOnLockedInvoices,
// CaseInvoice::setPriorPaid with $allowLocked). Runs on the scratch DB.
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';

(function () use ($t) {
    $db = fx_db();
    $ci = new CaseInvoice();
    $doctor = fx_user('doctor');

    $make = function (string $status, float $total, bool $historical = true) use ($db, $doctor) {
        $case = fx_case(fx_folder(fx_patient(), $doctor));
        $inv = fx_invoice($case, $doctor, $status === 'approved' ? 'sent' : $status, $status, $total);
        fx_treatment($case, $total, $inv);
        $db->update('cases', ['is_historical' => $historical ? 1 : 0], 'id=?', [$case]);
        return [$case, $inv];
    };

    // Option OFF: a sent receipt keeps its دراوی پێشوو, as before.
    [$case, $inv] = $make('sent', 100000);
    $r = $ci->setPriorPaid($case, $inv, 30000, $doctor);
    $t->assertTrue(empty($r['success']), 'option off: a sent receipt refuses دراوی پێشوو');
    $t->assertEqualsMoney(0, $db->fetch('SELECT prior_paid_amount p FROM case_invoices WHERE id=?', [$inv])['p'], 'option off: nothing saved');

    // Option ON: sent — saved, still sent, intent follows what is left.
    $db->update('case_invoices', ['payment_intent' => 'debt'], 'id=?', [$inv]);
    $r = $ci->setPriorPaid($case, $inv, 30000, $doctor, true);
    $t->assertTrue(!empty($r['success']), 'option on: a sent receipt takes دراوی پێشوو');
    $row = $db->fetch('SELECT prior_paid_amount p, accounting_status s, payment_intent i FROM case_invoices WHERE id=?', [$inv]);
    $t->assertEqualsMoney(30000, $row['p'], 'sent: saved');
    $t->assertSame('sent', $row['s'], 'sent: stays sent');
    $t->assertSame('debt', $row['i'], 'sent: 70,000 left stays debt');
    $r = $ci->setPriorPaid($case, $inv, 100000, $doctor, true);
    $t->assertTrue(!empty($r['success']), 'sent: the whole amount may be دراوی پێشوو');
    $t->assertSame('cash', $db->fetch('SELECT payment_intent i FROM case_invoices WHERE id=?', [$inv])['i'], 'sent: nothing left becomes cash');
    $t->assertTrue(empty($ci->setPriorPaid($case, $inv, 100001, $doctor, true)['success']), 'sent: never above the receipt');

    // Approved — with a receipt already allocated to it.
    [$case, $inv] = $make('approved', 100000);
    $db->update('case_invoices', ['draft_received_amount' => 20000, 'payment_intent' => 'debt'], 'id=?', [$inv]);
    $pay = (int)$db->insert('payments', ['case_id' => $case, 'amount' => 30000, 'payment_number' => 'TPP' . uniqid(),
        'payment_type' => 'cash', 'payment_date' => date('Y-m-d'), 'status' => 'approved']);
    $db->insert('payment_allocations', ['payment_id' => $pay, 'invoice_id' => $inv, 'allocated_amount' => 30000]);
    $t->assertTrue(empty($ci->setPriorPaid($case, $inv, 50001, $doctor, true)['success']), 'approved: chair 20,000 + receipt 30,000 leave room for 50,000 only');
    $r = $ci->setPriorPaid($case, $inv, 50000, $doctor, true);
    $t->assertTrue(!empty($r['success']), 'approved: 50,000 fits');
    $row = $db->fetch('SELECT prior_paid_amount p, accounting_status s, payment_intent i FROM case_invoices WHERE id=?', [$inv]);
    $t->assertSame('approved', $row['s'], 'approved: stays approved');
    $t->assertSame('cash', $row['i'], 'approved: settled now');
    $ledgerRow = (new AccountingLedger())->getInvoice($inv);
    $t->assertEqualsMoney(0, $ledgerRow['remaining_amount'] ?? -1, 'approved: the ledger sees nothing left');
    $t->assertTrue((bool)$db->fetch("SELECT id FROM activity_log WHERE action='invoice_prior_paid_changed_after_approved' AND record_id=? LIMIT 1", [$inv]), 'approved: the change is logged');

    // An instalment plan keeps its money.
    [$case, $inv] = $make('approved', 100000);
    $db->insert('installment_plans', ['invoice_id' => $inv, 'case_id' => $case, 'total_amount' => 100000, 'installment_amount' => 50000,
        'number_of_installments' => 2, 'remaining_amount' => 100000, 'start_date' => date('Y-m-d'), 'status' => 'active']);
    $t->assertTrue(empty($ci->setPriorPaid($case, $inv, 10000, $doctor, true)['success']), 'instalment plan: refused');

    // Only a historical case.
    [$case, $inv] = $make('approved', 100000, false);
    $t->assertTrue(empty($ci->setPriorPaid($case, $inv, 10000, $doctor, true)['success']), 'new case: refused');
})();
