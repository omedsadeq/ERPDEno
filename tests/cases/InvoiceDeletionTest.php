<?php
require_once __DIR__ . '/../../classes/InvoiceDeletion.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/PatientAdvance.php';
(function () use ($t) {
    $db = Database::getInstance(); $pdo = $db->getConnection(); $savedSession = $_SESSION;
    if (!$db->fetch("SHOW COLUMNS FROM payments LIKE 'master_payment_id'")) { $pdo->exec('ALTER TABLE payments ADD master_payment_id INT NULL'); }
    $doc = fx_user('doctor'); $admin = fx_user('admin'); $accountant = fx_user('accountant');
    $pat = fx_patient(); $folder = fx_folder($pat, $doc); $case = fx_case($folder); $otherCase = fx_case($folder);
    $target = fx_invoice($case, $doc, 'processed', 'approved', 100000);
    $other = fx_invoice($case, $doc, 'sent', 'approved', 50000);
    $external = fx_invoice($otherCase, $doc, 'sent', 'approved', 10000);
    $treatment = fx_treatment($case, 100000, $target);
    fx_treatment($case, 50000, $other); fx_treatment($otherCase, 10000, $external);
    $master = $db->insert('patient_debt_payments', ['patient_id' => $pat, 'amount' => 70000, 'allocations' => json_encode([
        ['item_type' => 'case', 'item_id' => $case, 'amount' => 60000], ['item_type' => 'case', 'item_id' => $otherCase, 'amount' => 10000]])]);
    $payment = function ($invoice, $amount, $status = 'approved', $masterId = null, $caseId = null) use ($db, $case, $doc) {
        return (int)$db->insert('payments', ['case_id' => $caseId ?? $case, 'invoice_id' => $invoice, 'payment_number' => uniqid('IDEL'),
            'amount' => $amount, 'payment_type' => 'cash', 'status' => $status, 'received_by' => $doc, 'master_payment_id' => $masterId]);
    };
    $allocate = function ($pid, $iid, $amount) use ($db) {
        return (int)$db->insert('payment_allocations', ['payment_id' => $pid, 'invoice_id' => $iid, 'allocated_amount' => $amount]);
    };
    $exclusive = $payment($target, 20000, 'approved', $master); $allocate($exclusive, $target, 20000);
    $shared = $payment($target, 40000, 'approved', $master); $allocate($shared, $target, 15000); $allocate($shared, $other, 25000);
    $originOther = $payment($other, 10000); $allocate($originOther, $target, 4000); $allocate($originOther, $other, 6000);
    $outsidePayment = $payment($external, 10000, 'approved', $master, $otherCase); $allocate($outsidePayment, $external, 10000);
    $draftPayment = $payment($target, 5000, 'draft');
    $pendingPayment = $payment($target, 3000, 'pending'); $allocate($pendingPayment, $target, 3000);
    $rejectedPayment = $payment($target, 4000, 'rejected');
    $refundedPayment = $payment($target, 1000, 'refunded');
    $plan = $db->insert('installment_plans', ['case_id' => $case, 'invoice_id' => $target, 'total_amount' => 100000,
        'remaining_amount' => 80000, 'number_of_installments' => 2, 'installment_amount' => 40000, 'start_date' => '2026-10-03', 'created_by' => $doc]);
    $db->insert('installment_schedule', ['plan_id' => $plan, 'installment_number' => 1, 'due_date' => '2026-10-03', 'amount' => 40000, 'payment_id' => $exclusive]);
    $db->insert('installment_payments', ['plan_id' => $plan, 'amount' => 20000, 'payment_id' => $exclusive]);
    $advance = $db->insert('patient_advances', ['patient_id' => $pat, 'advance_no' => uniqid('IDEL'), 'amount' => 12000, 'received_at' => '2026-10-03 08:00:00']);
    $db->insert('patient_advance_allocations', ['advance_id' => $advance, 'patient_id' => $pat, 'case_id' => $case,
        'invoice_id' => $target, 'amount' => 7000, 'allocated_at' => '2026-10-03 09:00:00']);
    $db->insert('patient_advance_allocations', ['advance_id' => $advance, 'patient_id' => $pat, 'case_id' => $case,
        'invoice_id' => $other, 'amount' => 5000, 'allocated_at' => '2026-10-03 09:00:00']);
    $db->update('case_invoices', ['advance_paid_amount' => 7000], 'id=?', [$target]);
    $db->update('case_invoices', ['advance_paid_amount' => 5000], 'id=?', [$other]);
    (new CaseManager())->updateCaseTotals($case);
    $actor = function ($id, $role) { $_SESSION = ['logged_in' => true, 'user_id' => $id, 'role' => $role, 'login_time' => time()]; };
    $service = new InvoiceDeletion();
    $actor($doc, 'doctor');
    $t->assertSame(false, $service->delete($case, $target, 'Doctor attempt')['success'], 'doctor cannot delete populated invoice');
    $empty = fx_invoice($case, $doc, 'draft', 'draft', 0);
    $t->assertSame(false, (new CaseInvoice())->deleteDraft($case, $empty)['success'], 'doctor cannot use old empty-draft delete');
    $actor($accountant, 'accountant');
    $t->assertSame(false, $service->delete($case, $target, 'Accountant attempt')['success'], 'accountant cannot delete invoice');
    $actor($admin, 'admin');
    $t->assertSame(false, $service->delete($otherCase, $target, 'Wrong case')['success'], 'invoice must belong to supplied case');
    $t->assertSame(false, $service->delete($case, $target, " \u{2003} ")['success'], 'reason required');
    $pdo->exec("CREATE TRIGGER test_block_invoice_log BEFORE INSERT ON activity_log FOR EACH ROW BEGIN IF NEW.action='delete_invoice' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit failure'; END IF; END");
    $failed = $service->delete($case, $target, 'Must roll back');
    $pdo->exec('DROP TRIGGER test_block_invoice_log');
    $t->assertSame(false, $failed['success'], 'mandatory archive failure blocks deletion');
    $t->assertSame(1, (int)$db->count('case_invoices', 'id=?', [$target]), 'invoice survives failure');
    $t->assertSame(1, (int)$db->count('payments', 'id=?', [$exclusive]), 'transactions survive failure');
    $t->assertEqualsMoney(40000, $db->fetch('SELECT amount FROM payments WHERE id=?', [$shared])['amount'], 'shared payment survives failure');
    $pdo->exec("CREATE TRIGGER test_block_invoice_delete BEFORE DELETE ON case_invoices FOR EACH ROW BEGIN IF OLD.id=$target THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test late deletion failure'; END IF; END");
    $lateFailure = $service->delete($case, $target, 'Late rollback');
    $pdo->exec('DROP TRIGGER test_block_invoice_delete');
    $t->assertSame(false, $lateFailure['success'], 'late deletion failure rolls back');
    $t->assertEqualsMoney(40000, $db->fetch('SELECT amount FROM payments WHERE id=?', [$shared])['amount'], 'shared payment update rolled back');
    $t->assertSame(1, (int)$db->count('payments', 'id=?', [$exclusive]), 'deleted payment restored on rollback');
    $t->assertSame(1, (int)$db->count('installment_plans', 'id=?', [$plan]), 'deleted plan restored on rollback');
    $t->assertSame(0, (int)$db->count('activity_log', "action='delete_invoice' AND record_id=?", [$target]), 'uncommitted deletion audit rolled back');
    $result = $service->delete($case, $target, 'Incorrect invoice entered twice');
    $t->assertTrue($result['success'], 'admin deletes approved populated invoice');
    if ($result['success']) {
        foreach (['case_invoices' => 'id', 'case_treatments' => 'invoice_id', 'payment_allocations' => 'invoice_id',
            'installment_plans' => 'invoice_id', 'patient_advance_allocations' => 'invoice_id'] as $table => $column) {
            $t->assertSame(0, (int)$db->count($table, "$column=?", [$target]), "$table invoice data removed");
        }
        foreach ([$exclusive, $draftPayment, $pendingPayment, $rejectedPayment, $refundedPayment] as $pid) {
            $t->assertSame(0, (int)$db->count('payments', 'id=?', [$pid]), 'exclusive payment removed for every status');
        }
        $t->assertSame(0, (int)$db->count('installment_schedule', 'plan_id=?', [$plan]), 'schedule removed');
        $t->assertSame(0, (int)$db->count('installment_payments', 'plan_id=?', [$plan]), 'installment transactions removed');
        $t->assertSame(1, (int)$db->count('cases', 'id=?', [$case]), 'case retained');
        $t->assertSame(1, (int)$db->count('case_invoices', 'id=?', [$other]), 'other invoice retained');
        $t->assertSame(1, (int)$db->count('case_treatments', 'invoice_id=?', [$other]), 'other treatments retained');
        $sharedAfter = $db->fetch('SELECT * FROM payments WHERE id=?', [$shared]);
        $t->assertEqualsMoney(25000, $sharedAfter['amount'], 'shared payment loses only target share');
        $t->assertSame($other, (int)$sharedAfter['invoice_id'], 'shared payment origin moved to remaining invoice');
        $t->assertEqualsMoney(6000, $db->fetch('SELECT amount FROM payments WHERE id=?', [$originOther])['amount'], 'other-origin payment retains its share');
        $t->assertEqualsMoney(35000, $db->fetch('SELECT amount FROM patient_debt_payments WHERE id=?', [$master])['amount'], 'shared master amount updated');
        $masterAfter = json_decode($db->fetch('SELECT allocations FROM patient_debt_payments WHERE id=?', [$master])['allocations'], true);
        $t->assertEqualsMoney(25000, $masterAfter[0]['amount'], 'master keeps remaining invoice amount');
        $t->assertEqualsMoney(10000, $db->fetch('SELECT amount FROM payments WHERE id=?', [$outsidePayment])['amount'], 'other case payment unaffected');
        $t->assertEqualsMoney(7000, (new PatientAdvance())->balanceOf($pat), 'deleted invoice advance released to patient');
        $t->assertEqualsMoney(50000, $db->fetch('SELECT total_amount FROM cases WHERE id=?', [$case])['total_amount'], 'case totals refreshed');
        $t->assertEqualsMoney(36000, $db->fetch('SELECT paid_amount FROM cases WHERE id=?', [$case])['paid_amount'], 'case payment total refreshed');
        $log = $db->fetch('SELECT * FROM activity_log WHERE id=?', [$result['log_id']]);
        $t->assertSame($admin, (int)$log['user_id'], 'admin identity archived');
        $t->assertSame('Incorrect invoice entered twice', json_decode($log['new_values'], true)['reason'], 'reason archived');
        $snapshot = json_decode($log['old_values'], true);
        $t->assertEqualsMoney(100000, $snapshot['invoice']['total_amount'], 'invoice snapshot archived');
        $t->assertSame(5, count($snapshot['records']['payments']), 'deleted transactions archived');
        $timeline = (new AccountingLedger())->getActivity($case, 100);
        $t->assertTrue(in_array((int)$result['log_id'], array_map('intval', array_column($timeline, 'id')), true), 'deleted invoice remains in case activity');
        $t->assertSame(false, $service->delete($case, $target, 'Duplicate submit')['success'], 'duplicate submission refused');
    }
    // Admin removal also works for a closed case and for the final remaining invoice.
    $db->update('cases', ['status' => 'closed'], 'id=?', [$otherCase]);
    $t->assertTrue($service->delete($otherCase, $external, 'Closed case correction')['success'], 'admin deletes invoice of closed case');
    $t->assertSame(1, (int)$db->count('cases', 'id=?', [$otherCase]), 'closed case retained after last invoice');
    $t->assertSame(0, (int)$db->count('case_invoices', 'case_id=?', [$otherCase]), 'last invoice removed');
    $_SESSION = $savedSession;
})();
