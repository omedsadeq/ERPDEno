<?php
require_once __DIR__ . '/../../classes/CaseDeletion.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
(function () use ($t) {
    $db = Database::getInstance(); $pdo = $db->getConnection();
    // The legacy migration adds this after a column missing from the scratch base schema.
    if (!$db->fetch("SHOW COLUMNS FROM payments LIKE 'master_payment_id'")) {
        $pdo->exec('ALTER TABLE payments ADD master_payment_id INT NULL');
    }
    $savedSession = $_SESSION;
    $doc = fx_user('doctor'); $otherDoc = fx_user('doctor'); $admin = fx_user('admin');
    $pat = fx_patient(); $folder = fx_folder($pat, $doc); $caseId = fx_case($folder); $otherCase = fx_case($folder);
    $invoice = fx_invoice($caseId, $doc, 'sent', 'approved', 100000);
    $otherInvoice = fx_invoice($otherCase, $doc, 'sent', 'approved', 50000);
    fx_treatment($caseId, 100000, $invoice); fx_treatment($otherCase, 50000, $otherInvoice);
    (new CaseManager())->updateCaseTotals($caseId); (new CaseManager())->updateCaseTotals($otherCase);
    $alloc = [['item_type' => 'case', 'item_id' => $caseId, 'amount' => 30000],
        ['item_type' => 'case', 'item_id' => $otherCase, 'amount' => 20000]];
    $master = $db->insert('patient_debt_payments', ['patient_id' => $pat, 'amount' => 50000, 'allocations' => json_encode($alloc)]);
    $payment = $db->insert('payments', ['case_id' => $caseId, 'invoice_id' => $invoice, 'amount' => 30000,
        'payment_number' => uniqid('DEL'), 'payment_type' => 'cash', 'status' => 'approved', 'received_by' => $doc, 'master_payment_id' => $master]);
    $otherPayment = $db->insert('payments', ['case_id' => $otherCase, 'invoice_id' => $otherInvoice, 'amount' => 20000,
        'payment_number' => uniqid('DEL'), 'payment_type' => 'cash', 'status' => 'approved', 'received_by' => $doc, 'master_payment_id' => $master]);
    $db->insert('payment_allocations', ['payment_id' => $payment, 'invoice_id' => $invoice, 'allocated_amount' => 30000]);
    $plan = $db->insert('installment_plans', ['case_id' => $caseId, 'invoice_id' => $invoice,
        'total_amount' => 100000, 'down_payment' => 30000, 'remaining_amount' => 70000,
        'number_of_installments' => 2, 'installment_amount' => 35000, 'start_date' => '2026-10-03', 'created_by' => $doc]);
    $db->insert('installment_schedule', ['plan_id' => $plan, 'installment_number' => 1, 'due_date' => '2026-10-03', 'amount' => 35000, 'payment_id' => $payment]);
    $db->insert('installment_payments', ['plan_id' => $plan, 'amount' => 30000, 'payment_id' => $payment]);
    $rx = $db->insert('prescriptions', ['case_id' => $caseId, 'patient_id' => $pat, 'doctor_id' => $doc, 'prescription_date' => '2026-10-03']);
    $db->insert('prescription_items', ['prescription_id' => $rx, 'medicine_name' => 'Test drug']);
    $xray = $db->insert('xray_requests', ['request_number' => uniqid('DEL'), 'case_id' => $caseId, 'patient_id' => $pat, 'requested_by' => $doc, 'xray_type' => 'panoramic', 'xray_reason' => 'other']);
    $debt = $db->insert('patient_xray_debts', ['patient_id' => $pat, 'request_id' => $xray, 'amount' => 10000, 'remaining_amount' => 5000]);
    $db->insert('xray_debt_payments', ['debt_id' => $debt, 'amount' => 5000, 'received_by' => $doc]);
    $db->insert('expenses_lab', ['case_id' => $caseId, 'amount' => 1000, 'expense_date' => '2026-10-03', 'description' => 'Case lab expense']);
    $item = $db->insert('inventory_items', ['item_code' => uniqid('DEL'), 'item_name' => 'Test stock', 'current_stock' => 8]);
    $db->insert('inventory_transactions', ['case_id' => $caseId, 'item_id' => $item, 'transaction_type' => 'use', 'quantity' => 2]);
    $uploads = APP_ROOT . '/uploads/cases';
    if (!is_dir($uploads)) { mkdir($uploads, 0775, true); }
    $path = 'uploads/cases/' . uniqid('deletion-test-') . '.txt';
    file_put_contents(APP_ROOT . '/' . $path, 'test attachment');
    $db->insert('case_images', ['case_id' => $caseId, 'slot_number' => 1, 'image_path' => $path]);
    $service = new CaseDeletion();
    $setActor = function ($id, $role) { $_SESSION['user_id'] = $id; $_SESSION['role'] = $role; $_SESSION['logged_in'] = true; };
    $setActor($otherDoc, 'doctor');
    $t->assertSame(false, $service->delete($caseId, $pat, 'Unauthorized')['success'], 'other doctor cannot delete');
    $setActor($doc, 'doctor');
    $auditBefore = (int)$db->count('activity_log');
    $t->assertSame(false, $service->delete($caseId, $pat, 'Owner deletion attempt')['success'], 'case-owning doctor cannot delete');
    $t->assertSame(1, (int)$db->count('cases', 'id=?', [$caseId]), 'doctor denial preserves case');
    $t->assertSame(1, (int)$db->count('payments', 'id=?', [$payment]), 'doctor denial preserves money');
    $t->assertSame(0, (int)$db->count('deleted_cases', 'case_id=?', [$caseId]), 'doctor denial creates no archive');
    $t->assertSame($auditBefore, (int)$db->count('activity_log'), 'doctor denial writes no audit');
    $t->assertTrue(is_file(APP_ROOT . '/' . $path), 'doctor denial preserves attachment');
    $emptyCase = fx_case($folder);
    $caseManager = new CaseManager();
    $t->assertSame(false, $caseManager->delete($caseId)['success'], 'legacy deletion also blocks doctors');
    $t->assertSame(false, $caseManager->deleteIfEmpty($emptyCase)['success'], 'doctor cannot delete even an empty case');
    $t->assertSame(1, (int)$db->count('cases', 'id=?', [$emptyCase]), 'empty case preserved for doctor');
    foreach (['accountant', 'receptionist'] as $role) {
        $setActor(fx_user($role), $role);
        $t->assertSame(false, $service->delete($caseId, $pat, 'Unauthorized role')['success'], "$role cannot delete");
    }
    $setActor($doc, 'admin');
    $t->assertSame(false, $service->delete($caseId, $pat, 'Forged session role')['success'], 'database role must also be administrator');
    $setActor($admin, 'admin');
    $_SESSION['logged_in'] = false;
    $t->assertSame(false, $service->delete($caseId, $pat, 'Logged out')['success'], 'logged-out admin cannot delete');
    $_SESSION['logged_in'] = true;
    $db->update('users', ['is_active' => 0], 'id=?', [$admin]);
    $t->assertSame(false, $service->delete($caseId, $pat, 'Inactive admin')['success'], 'inactive admin cannot delete');
    $db->update('users', ['is_active' => 1], 'id=?', [$admin]);
    $t->assertSame(false, $service->delete($caseId, $pat, '   ')['success'], 'reason required');
    $t->assertSame(false, $service->delete($caseId, $pat, "\u{2003}\u{00A0}")['success'], 'Unicode blank reason rejected');
    $t->assertSame(false, $service->delete($caseId, $pat, str_repeat('x', 2001))['success'], 'oversized reason rejected');
    $t->assertSame(false, $service->delete($caseId, $pat + 99999, 'Wrong patient')['success'], 'patient scope enforced');
    // An archive failure must roll back money, stock and every live record.
    $pdo->exec("CREATE TRIGGER test_block_case_archive BEFORE INSERT ON deleted_cases FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test archive failure'");
    $result = $service->delete($caseId, $pat, 'Must roll back');
    $pdo->exec('DROP TRIGGER test_block_case_archive');
    $t->assertSame(false, $result['success'], 'archive failure blocks deletion');
    $t->assertSame(1, (int)$db->count('cases', 'id=?', [$caseId]), 'case restored');
    $t->assertSame(1, (int)$db->count('payments', 'id=?', [$payment]), 'payment restored');
    $t->assertEqualsMoney(50000, $db->fetch('SELECT amount FROM patient_debt_payments WHERE id=?', [$master])['amount'], 'shared receipt restored');
    $t->assertSame(8, (int)$db->fetch('SELECT current_stock FROM inventory_items WHERE id=?', [$item])['current_stock'], 'stock restored');
    $t->assertTrue(is_file(APP_ROOT . '/' . $path), 'file preserved on rollback');
    $result = $service->delete($caseId, $pat, 'Duplicate case created in error');
    $t->assertTrue($result['success'], 'admin deletes populated case');
    if (!empty($result['success'])) {
        foreach (['cases' => 'id', 'case_treatments' => 'case_id', 'case_invoices' => 'case_id', 'payments' => 'case_id',
            'case_images' => 'case_id', 'expenses_lab' => 'case_id', 'inventory_transactions' => 'case_id',
            'installment_plans' => 'case_id', 'xray_requests' => 'case_id'] as $table => $column) {
            $t->assertSame(0, (int)$db->count($table, "$column=?", [$caseId]), "$table permanently removed");
        }
        $t->assertSame(0, (int)$db->count('installment_schedule', 'plan_id=?', [$plan]), 'schedule removed');
        $t->assertSame(0, (int)$db->count('installment_payments', 'plan_id=?', [$plan]), 'installment transactions removed');
        $t->assertSame(0, (int)$db->count('payment_allocations', 'payment_id=?', [$payment]), 'payment allocations removed');
        $t->assertSame(0, (int)$db->count('prescription_items', 'prescription_id=?', [$rx]), 'prescription items removed');
        $t->assertSame(0, (int)$db->count('xray_debt_payments', 'debt_id=?', [$debt]), 'X-ray payments removed');
        $t->assertSame(1, (int)$db->count('cases', 'id=?', [$otherCase]), 'other case preserved');
        $t->assertSame(1, (int)$db->count('payments', 'id=?', [$otherPayment]), 'other payment preserved');
        $t->assertEqualsMoney(20000, $db->fetch('SELECT amount FROM patient_debt_payments WHERE id=?', [$master])['amount'], 'shared receipt keeps other case amount');
        $t->assertEqualsMoney(50000, $db->fetch('SELECT total_amount FROM patient_folders WHERE id=?', [$folder])['total_amount'], 'folder totals refreshed');
        $t->assertSame(10, (int)$db->fetch('SELECT current_stock FROM inventory_items WHERE id=?', [$item])['current_stock'], 'inventory use reversed');
        $archive = $db->fetch('SELECT * FROM deleted_cases WHERE id=?', [$result['deletion_id']]);
        $snapshot = json_decode($archive['snapshot'], true);
        $t->assertSame('Duplicate case created in error', $archive['deletion_reason'], 'reason archived');
        $t->assertSame($admin, (int)$archive['deleted_by'], 'administrator actor archived');
        $t->assertEqualsMoney(30000, $snapshot['records']['payments'][0]['amount'], 'deleted transaction details archived');
        $t->assertSame(0, (int)$archive['cleanup_pending'], 'attachment cleanup completed');
        $t->assertSame(false, is_file(APP_ROOT . '/' . $path), 'physical attachment deleted');
        $t->assertSame(false, $service->delete($caseId, $pat, 'Repeated')['success'], 'duplicate submit rejected');
        $t->assertSame(1, (int)$db->count('deleted_cases', 'case_id=?', [$caseId]), 'exactly one archive');
    } else { @unlink(APP_ROOT . '/' . $path); }
    $setActor($admin, 'admin');
    $t->assertTrue($caseManager->deleteIfEmpty($emptyCase)['success'], 'admin can use legacy empty-case deletion');
    $adminCase = fx_case($folder);
    $t->assertTrue($service->delete($adminCase, $pat, 'Administrator removal')['success'], 'admin may delete');
    $outsideCase = fx_case($folder);
    $outsidePath = 'tests/storage/' . uniqid('deletion-safety-') . '.txt';
    file_put_contents(APP_ROOT . '/' . $outsidePath, 'path safety fixture');
    $db->insert('case_images', ['case_id' => $outsideCase, 'slot_number' => 1, 'image_path' => $outsidePath]);
    $outsideResult = $service->delete($outsideCase, $pat, 'Attachment safety test');
    $t->assertTrue($outsideResult['success'], 'database deletion completes independently of file cleanup');
    $t->assertTrue(is_file(APP_ROOT . '/' . $outsidePath), 'file outside uploads is never deleted');
    $t->assertSame(1, (int)$db->fetch('SELECT cleanup_pending FROM deleted_cases WHERE id=?', [$outsideResult['deletion_id']])['cleanup_pending'], 'failed cleanup durably recorded');
    $setActor($otherDoc, 'doctor');
    $t->assertThrows(fn() => $service->cleanupFiles((int)$outsideResult['deletion_id']), 'other doctor cannot retry cleanup');
    $setActor($doc, 'doctor');
    $t->assertThrows(fn() => $service->cleanupFiles((int)$outsideResult['deletion_id']), 'case-owning doctor cannot delete archived files');
    $setActor($admin, 'admin');
    unlink(APP_ROOT . '/' . $outsidePath);
    $service->cleanupFiles((int)$outsideResult['deletion_id']);
    $t->assertSame(0, (int)$db->fetch('SELECT cleanup_pending FROM deleted_cases WHERE id=?', [$outsideResult['deletion_id']])['cleanup_pending'], 'retry recognizes already removed files');
    $db->delete('patients', 'id=?', [$pat]);
    $t->assertSame(1, (int)$db->count('deleted_cases', 'case_id=?', [$caseId]), 'archive survives patient removal');
    $_SESSION = $savedSession;
})();
