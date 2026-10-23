<?php
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/PatientFinance.php';
require_once __DIR__ . '/../../classes/InvoiceDeletion.php';
require_once __DIR__ . '/../../classes/CaseDeletion.php';

(function () use ($t) {
    $db = Database::getInstance();
    $service = new Payment(); $ledger = new AccountingLedger();
    $doctor = fx_user('doctor'); $accountant = fx_user('accountant'); $admin = fx_user('admin');
    $patient = fx_patient(); $folder = fx_folder($patient, $doctor);
    $make = function ($total = 100000) use ($folder, $doctor) {
        $case = fx_case($folder); $invoice = fx_invoice($case, $doctor, 'sent', 'approved', $total);
        fx_treatment($case, $total, $invoice);
        return [$case, $invoice];
    };
    $data = function ($case, $cash = 80000, $discount = 20000) {
        return ['case_id' => $case, 'amount' => $cash, 'discount_mode' => 'amount',
            'discount_value' => $discount, 'discount_reason' => 'Returning patient settlement', 'idempotency_key' => uniqid('discount-', true)];
    };
    [$case, $invoice] = $make();
    $request = $data($case);
    $sent = $service->saveAndSendDraft($request, $doctor);
    $t->assertTrue($sent['success'], 'doctor submits cash and discount together');
    if (empty($sent['success'])) { return; }
    $id = (int)$sent['payment_id']; $payment = $service->getById($id);
    $t->assertSame('pending', $payment['status'], 'discount awaits accounting');
    $t->assertEqualsMoney(80000, $payment['amount'], 'cash amount excludes discount');
    $t->assertEqualsMoney(20000, $payment['discount_amount'], 'discount stored separately');
    $t->assertEqualsMoney(0, $ledger->getSummary($case)['paid'], 'request books no cash');
    $t->assertEqualsMoney(0, $ledger->getSummary($case)['total_discount'], 'request applies no discount yet');
    $t->assertEqualsMoney(100000, $ledger->getSummary($case)['balance'], 'pending discount leaves debt intact');
    $t->assertEqualsMoney(0, $service->getCaseBalance($case)['unrequested'], 'both parts reserve debt');
    $t->assertEqualsMoney(80000, $payment['allocations'][0]['allocated_amount'], 'cash allocation is cash only');
    $t->assertEqualsMoney(20000, $payment['discount_allocations'][0]['amount'], 'discount has its own allocation');
    $t->assertSame(false, $service->saveAndSendDraft($data($case, 1, 0), $doctor)['success'], 'another request cannot over-reserve debt');
    $repeat = $service->saveAndSendDraft($request, $doctor);
    $t->assertTrue(!empty($repeat['duplicate']), 'duplicate send is idempotent');
    $t->assertSame($id, (int)$repeat['payment_id'], 'duplicate keeps receipt identity');
    $t->assertSame(1, (int)$db->count('payments', 'case_id=?', [$case]), 'duplicate creates no receipt');
    $t->assertSame(false, $service->approve($id, $doctor)['success'], 'doctor cannot approve a discount');
    $t->assertThrows(function () use ($service, $id, $doctor) { $service->updatePendingAmount($id, 50000, $doctor); }, 'generic amount editor cannot detach discount');
    $t->assertTrue(strpos((new ApprovalQueue())->detail('payment', $id)['payment']['discount_reason'], 'Returning') !== false, 'accountant drawer includes discount reason');
    $notices = $db->fetchAll('SELECT u.role,n.data FROM notifications n JOIN users u ON u.id=n.user_id WHERE n.created_by=? AND n.type=?', [$doctor, Notification::TYPE_PAYMENT_PENDING]);
    $roles = [];
    foreach ($notices as $notice) {
        $n = json_decode($notice['data'], true);
        if ((int)($n['payment_id'] ?? 0) === $id) {
            $roles[] = $notice['role'];
            $t->assertEqualsMoney(20000, $n['discount_amount'], 'notification exposes requested discount');
        }
    }
    $t->assertTrue(in_array('admin', $roles, true) && in_array('accountant', $roles, true), 'admin and accounting receive request');
    $approved = $service->approve($id, $accountant);
    $t->assertTrue($approved['success'], 'accountant approves bundled settlement');
    $summary = $ledger->getSummary($case);
    $t->assertEqualsMoney(80000, $summary['paid'], 'only cash counts as paid');
    $t->assertEqualsMoney(20000, $summary['total_discount'], 'discount applied to original invoice');
    $t->assertEqualsMoney(0, $summary['balance'], 'cash and discount settle the case');
    $t->assertSame('processed', $ledger->getInvoice($invoice)['status'], 'settled invoice is processed');
    $t->assertEqualsMoney(0, $db->fetch('SELECT balance FROM v_case_financial_summary WHERE case_id=?', [$case])['balance'], 'reporting view agrees');
    $cashbox = $db->fetch("SELECT booked_amount FROM cashbox_sources WHERE source_type='payment' AND source_id=?", [$id]);
    $t->assertEqualsMoney(80000, $cashbox['booked_amount'], 'cashbox receives actual cash only');
    $t->assertSame(false, $service->approve($id, $admin)['success'], 'repeat approval cannot apply discount twice');
    $t->assertTrue($service->approvedEditBlockReason($service->getById($id)) !== null, 'approved settlement cannot be split by generic correction');
    $t->assertTrue($service->refund($id, 'Undo agreement', $admin)['success'], 'reversal undoes both parts');
    $summary = $ledger->getSummary($case);
    $t->assertEqualsMoney(0, $summary['paid'], 'reversal removes paid cash');
    $t->assertEqualsMoney(0, $summary['total_discount'], 'reversal restores discount');
    $t->assertEqualsMoney(100000, $summary['balance'], 'reversal restores debt');
    $t->assertSame('sent', $ledger->getInvoice($invoice)['status'], 'reversed invoice reopens');
    $t->assertEqualsMoney(0, $db->fetch("SELECT booked_amount FROM cashbox_sources WHERE source_type='payment' AND source_id=?", [$id])['booked_amount'], 'cashbox reversal removes only cash');

    [$rejectCase] = $make(); $pending = $service->saveAndSendDraft($data($rejectCase), $doctor);
    $t->assertTrue($service->reject($pending['payment_id'], 'No discount agreed', $accountant)['success'], 'accountant rejects entire agreement');
    $t->assertEqualsMoney(100000, $service->getCaseBalance($rejectCase)['unrequested'], 'rejection releases cash and discount reservations');
    $t->assertEqualsMoney(100000, $ledger->getSummary($rejectCase)['balance'], 'rejection preserves original debt');

    [$invalidCase] = $make();
    foreach ([['discount_value' => -1], ['discount_value' => '1abc'], ['discount_value' => 'NaN'],
        ['discount_value' => '1.234'], ['discount_value' => 20001], ['discount_mode' => 'bad'],
        ['discount_reason' => ''], ['discount_mode' => 'percent', 'discount_value' => 101]] as $invalid) {
        $t->assertSame(false, $service->saveAndSendDraft(array_merge($data($invalidCase), $invalid), $doctor)['success'], 'invalid discount rejected');
    }
    $t->assertSame(0, (int)$db->count('payments', 'case_id=?', [$invalidCase]), 'invalid data leaves no receipt');
    $saved = $service->saveDraft($data($invalidCase, 50000, 10000), $doctor);
    $t->assertTrue($saved['success'], 'optional draft stores discount');
    $t->assertEqualsMoney(100000, $service->getCaseBalance($invalidCase)['unrequested'], 'draft reserves nothing');
    $t->assertSame(0, (int)$db->count('payment_discount_allocations', 'payment_id=?', [$saved['payment_id']]), 'draft has no discount reservations');
    $t->assertEqualsMoney(10000, $service->getById($saved['payment_id'])['discount_amount'], 'draft keeps requested discount');

    [$percentCase, $percentInvoice] = $make(100001);
    $db->update('case_invoices', ['discount_mode' => 'percent', 'discount_value' => 10, 'discount_amount' => 10000.10,
        'draft_received_amount' => 10000], 'id=?', [$percentInvoice]);
    $percentData = array_merge($data($percentCase, 70000.79), ['discount_mode' => 'percent', 'discount_value' => '12.50']);
    $percentSent = $service->saveAndSendDraft($percentData, $doctor);
    $t->assertTrue($percentSent['success'], 'percentage uses outstanding debt after existing discount and cash');
    $t->assertEqualsMoney(10000.11, $service->getById($percentSent['payment_id'])['discount_amount'], 'percentage rounded to cents');
    $t->assertTrue($service->approve($percentSent['payment_id'], $accountant, '69000.50')['success'], 'accountant can correct cash while approving');
    $t->assertEqualsMoney(1000.29, $ledger->getSummary($percentCase)['balance'], 'partial settlement leaves correct remainder');
    $t->assertTrue($service->refund($percentSent['payment_id'], 'Undo', $admin)['success'], 'percentage settlement reverses');
    $old = $ledger->getInvoice($percentInvoice);
    $t->assertSame('percent', $old['discount_mode'], 'reversal restores original percentage mode');
    $t->assertEqualsMoney(10, $old['discount_value'], 'reversal restores original percentage value');
    $t->assertEqualsMoney(10000.10, $old['discount_amount'], 'reversal restores previous discount exactly');

    [$multiCase, $first] = $make(60000); $second = fx_invoice($multiCase, $doctor, 'sent', 'approved', 40000); fx_treatment($multiCase, 40000, $second);
    $multi = $service->directPayment($data($multiCase, 50000, 30000), $accountant);
    $t->assertTrue($multi['success'], 'accountant directly collects discounted payment');
    $multiRow = $service->getById($multi['payment_id']);
    $t->assertEqualsMoney(50000, array_sum(array_column($multiRow['allocations'], 'allocated_amount')), 'multi-invoice cash adds up');
    $t->assertEqualsMoney(30000, array_sum(array_column($multiRow['discount_allocations'], 'amount')), 'discount can span multiple invoices');
    $t->assertEqualsMoney(10000, $ledger->getInvoice($first)['discount_amount'], 'FIFO first invoice settled');
    $t->assertEqualsMoney(20000, $ledger->getInvoice($second)['discount_amount'], 'remaining discount on second invoice');
    $t->assertEqualsMoney(20000, $ledger->getSummary($multiCase)['balance'], 'multi-invoice remainder correct');
    $t->assertTrue($service->refund($multi['payment_id'], 'Undo', $admin)['success'], 'reversal includes discount-only invoice');
    $t->assertEqualsMoney(100000, $ledger->getSummary($multiCase)['balance'], 'multi-invoice debt restored');
    $t->assertSame(false, $service->directPayment($data($multiCase), $doctor)['success'], 'doctor cannot directly book discounted collection');

    [$rollbackCase, $rollbackInvoice] = $make();
    $rollback = $service->saveAndSendDraft($data($rollbackCase), $doctor); $rid = (int)$rollback['payment_id'];
    $db->getConnection()->exec("CREATE TRIGGER test_discount_failure BEFORE UPDATE ON payments FOR EACH ROW
        BEGIN IF NEW.id=$rid AND NEW.status='approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'; END IF; END");
    try { $t->assertSame(false, $service->approve($rid, $accountant, 70000)['success'], 'failure after discount application rolls back'); }
    finally { $db->getConnection()->exec('DROP TRIGGER test_discount_failure'); }
    $t->assertSame('pending', $service->getById($rid)['status'], 'failed approval stays pending');
    $t->assertEqualsMoney(80000, $service->getById($rid)['amount'], 'failed approval restores cash amount');
    $t->assertEqualsMoney(0, $ledger->getInvoice($rollbackInvoice)['discount_amount'], 'failed approval restores invoice discount');
    $t->assertEqualsMoney(0, $ledger->getSummary($rollbackCase)['paid'], 'failed approval books no cash');
    $t->assertSame(false, $db->inTransaction(), 'failure closes transaction');
    $t->assertTrue($service->approve($rid, $accountant)['success'], 'retry succeeds after failure');
    $db->update('case_invoices', ['discount_amount' => 10000, 'discount_value' => 10000], 'id=?', [$rollbackInvoice]);
    $t->assertSame(false, $service->refund($rid, 'Unsafe old reversal', $admin)['success'], 'changed invoice discount prevents unsafe reversal');
    $t->assertSame('approved', $service->getById($rid)['status'], 'blocked reversal keeps receipt approved');

    [$staleCase, $staleInvoice] = $make(); $stale = $service->saveAndSendDraft($data($staleCase), $doctor);
    $db->update('case_invoices', ['total_amount' => 90000], 'id=?', [$staleInvoice]);
    $t->assertSame(false, $service->approve($stale['payment_id'], $accountant)['success'], 'changed invoice balance is revalidated');
    $t->assertEqualsMoney(0, $ledger->getInvoice($staleInvoice)['discount_amount'], 'stale approval applies no discount');
    $t->assertTrue($service->approve($stale['payment_id'], $accountant, 70000)['success'], 'accountant can lower cash to match current balance');

    [$directCase] = $make(); $directData = $data($directCase);
    $direct = $service->directPayment($directData, $admin); $same = $service->directPayment($directData, $admin);
    $t->assertTrue($direct['success'] && !empty($same['duplicate']), 'direct discounted collection deduplicates');
    $t->assertSame((int)$direct['payment_id'], (int)$same['payment_id'], 'direct retry keeps same receipt');

    // The existing approval drawer may fund the cash part from patient custody.
    [$advanceCase, $advanceInvoice] = $make();
    $advanceRequest = $service->saveAndSendDraft($data($advanceCase), $doctor);
    $t->assertSame(false, $service->approve($advanceRequest['payment_id'], $accountant, null, '', true)['success'], 'insufficient custody rolls back discounted approval');
    $t->assertEqualsMoney(0, $ledger->getInvoice($advanceInvoice)['discount_amount'], 'failed custody funding leaves original discount');
    $custody = new PatientAdvance();
    $t->assertTrue($custody->receive($patient, 80000, 'cash', 'Settlement funding', $accountant, $doctor)['success'], 'patient custody received');
    $t->assertTrue($service->approve($advanceRequest['payment_id'], $accountant, null, '', true)['success'], 'custody funds cash part of discounted agreement');
    $t->assertEqualsMoney(0, $custody->balanceOf($patient), 'only cash portion consumed from custody');
    $t->assertSame('advance', $service->getById($advanceRequest['payment_id'])['payment_method'], 'funded agreement retains custody method');
    $t->assertEqualsMoney(0, $ledger->getSummary($advanceCase)['balance'], 'custody and discount settle invoice');
    $t->assertTrue($service->refund($advanceRequest['payment_id'], 'Restore custody', $admin)['success'], 'funded discount reverses atomically');
    $t->assertEqualsMoney(80000, $custody->balanceOf($patient), 'reversal restores custody');
    $t->assertEqualsMoney(100000, $ledger->getSummary($advanceCase)['balance'], 'funded reversal restores full invoice debt');
    $directAdvance = $service->directPayment(array_merge($data($advanceCase), ['payment_method'=>'advance', 'from_advance'=>true]), $accountant);
    $t->assertTrue($directAdvance['success'], 'direct discounted collection can use existing custody');
    $t->assertEqualsMoney(0, $custody->balanceOf($patient), 'direct funded agreement consumes custody once');

    // The actual patient voucher shows the discount separately from cash.
    $savedSession = $_SESSION; $savedGet = $_GET;
    $_SESSION = ['logged_in' => true, 'user_id' => $accountant, 'role' => 'accountant'];
    $_GET = ['payment' => $direct['payment_id'], 'copies' => 1, 'embed' => 1];
    ob_start(); require __DIR__ . '/../../cases/voucher.php'; $html = ob_get_clean();
    $_SESSION = $savedSession; $_GET = $savedGet;
    $t->assertTrue(strpos($html, '20,000.00') !== false && strpos($html, 'Returning patient settlement') !== false, 'voucher prints separate discount and reason');

    $t->assertEqualsMoney(123.45, PaymentDiscount::number('١٢٣.٤٥'), 'localized digits accepted');
    [$deleteCase, $deleteInvoice] = $make();
    $toDelete = $service->saveAndSendDraft($data($deleteCase), $doctor);
    $savedSession = $_SESSION;
    $_SESSION = ['logged_in'=>true, 'user_id'=>$admin, 'role'=>'admin'];
    try {
        $deleter = new InvoiceDeletion();
        $t->assertSame(false, $deleter->delete($deleteCase, $deleteInvoice, 'Must reject first')['success'], 'invoice deletion cannot orphan pending settlement');
        $t->assertTrue($service->reject($toDelete['payment_id'], 'Reject before deleting', $admin)['success'], 'reject releases deletion guard');
        $t->assertTrue($deleter->delete($deleteCase, $deleteInvoice, 'Delete rejected agreement')['success'], 'invoice deletion archives rejected discount allocations');
        $t->assertSame(0, (int)$db->count('payment_discount_allocations', 'payment_id=?', [$toDelete['payment_id']]), 'deleted invoice leaves no orphan discount');
        [$deleteCase2, $deleteInvoice2] = $make();
        $booked = $service->directPayment($data($deleteCase2), $admin);
        $t->assertSame(false, $deleter->delete($deleteCase2, $deleteInvoice2, 'Must reverse first')['success'], 'approved agreement must be reversed before deleting one invoice');
        $t->assertTrue((new CaseDeletion())->delete($deleteCase2, $patient, 'Delete full case with agreement')['success'], 'whole-case deletion archives approved agreement and its discount');
        $t->assertSame(0, (int)$db->count('payment_discount_allocations', 'payment_id=?', [$booked['payment_id']]), 'whole-case deletion removes discount links');
        $t->assertEqualsMoney(0, $db->fetch("SELECT booked_amount FROM cashbox_sources WHERE source_type='payment' AND source_id=?", [$booked['payment_id']])['booked_amount'], 'case deletion reverses cashbox cash');
    } finally { $_SESSION = $savedSession; }
})();
