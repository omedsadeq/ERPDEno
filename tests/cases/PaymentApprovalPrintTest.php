<?php
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';

(function () use ($t) {
    $db = Database::getInstance();
    $payments = new Payment();
    $ledger = new AccountingLedger();
    $doctor = fx_user('doctor');
    $accountant = fx_user('accountant');
    $folder = fx_folder(fx_patient(), $doctor);
    $makeRequest = function ($invoiceStatus = 'approved') use ($folder, $doctor, $payments) {
        $caseId = fx_case($folder);
        $invoice = fx_invoice($caseId, $doctor, 'sent', $invoiceStatus, 100000);
        fx_treatment($caseId, 100000, $invoice);
        $result = $payments->createRequest([
            'case_id' => $caseId, 'amount' => 40000, 'received_by' => $doctor,
            'allocations' => [$invoice => 40000], 'notes' => 'Doctor note',
        ]);
        return [(int)$result['payment_id'], $invoice];
    };

    [$id, $invoice] = $makeRequest('sent');
    $before = $payments->getById($id);
    $result = $payments->approve($id, $accountant, 55000, 'Final amount');
    $t->assertTrue($result['success'], 'amount saved and approved in one action');
    $after = $payments->getById($id);
    $t->assertSame('approved', $after['status'], 'receipt approved');
    $t->assertEqualsMoney(55000, $after['amount'], 'final entered amount stored');
    $t->assertEqualsMoney(55000, $after['allocations'][0]['allocated_amount'], 'allocation follows saved amount');
    $t->assertEqualsMoney(55000, $ledger->getInvoice($invoice)['paid_amount'], 'saved amount booked once');
    $t->assertSame('approved', $ledger->getInvoice($invoice)['accounting_status'], 'sent invoice approved with payment');
    $t->assertSame($before['payment_number'], $after['payment_number'], 'original receipt number retained');
    $t->assertSame($before['payment_date'], $after['payment_date'], 'original receipt date retained');
    $audit = $db->fetch("SELECT new_values FROM activity_log WHERE action='payment_approved' AND record_id=? ORDER BY id DESC LIMIT 1", [$id]);
    $t->assertSame('Final amount', json_decode($audit['new_values'], true)['note'], 'approval reason recorded');
    $t->assertSame(false, $payments->approve($id, $accountant, 60000)['success'], 'repeat cannot change or approve receipt again');
    $t->assertEqualsMoney(55000, $ledger->getInvoice($invoice)['paid_amount'], 'repeat does not book duplicate money');

    [$invalid, $invalidInvoice] = $makeRequest();
    foreach ([0, -10, 150000, INF, NAN] as $amount) {
        $t->assertSame(false, $payments->approve($invalid, $accountant, $amount)['success'], 'invalid amount cannot be approved');
        $row = $payments->getById($invalid);
        $t->assertSame('pending', $row['status'], 'invalid amount stays pending');
        $t->assertEqualsMoney(40000, $row['amount'], 'failed save preserves amount');
        $t->assertEqualsMoney(40000, $row['allocations'][0]['allocated_amount'], 'failed save preserves reservation');
    }
    $t->assertEqualsMoney(0, $ledger->getInvoice($invalidInvoice)['paid_amount'], 'failed saves book no money');

    // Force a failure after the nested amount save and invoice approval.
    // The outer transaction must roll back both, including their audit entries.
    [$rollbackId, $rollbackInvoice] = $makeRequest('sent');
    $auditCount = (int)$db->count('activity_log', 'table_name=? AND record_id=?', ['payments', $rollbackId]);
    $db->getConnection()->exec("CREATE TRIGGER test_payment_approve_failure BEFORE UPDATE ON payments FOR EACH ROW
        BEGIN IF NEW.id=$rollbackId AND NEW.status='approved' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test approval failure';
        END IF; END");
    try {
        $t->assertSame(false, $payments->approve($rollbackId, $accountant, 65000, 'Must roll back')['success'], 'approval failure returned');
    } finally {
        $db->getConnection()->exec('DROP TRIGGER test_payment_approve_failure');
    }
    $row = $payments->getById($rollbackId);
    $t->assertSame('pending', $row['status'], 'failed approval stays pending');
    $t->assertEqualsMoney(40000, $row['amount'], 'approval failure rolls back saved amount');
    $t->assertEqualsMoney(40000, $row['allocations'][0]['allocated_amount'], 'approval failure rolls back allocation');
    $t->assertSame('sent', $ledger->getInvoice($rollbackInvoice)['accounting_status'], 'invoice approval rolled back');
    $t->assertSame($auditCount, (int)$db->count('activity_log', 'table_name=? AND record_id=?', ['payments', $rollbackId]), 'failed action leaves no success audit');
    $t->assertSame(false, $db->inTransaction(), 'transaction closed after failure');
    $t->assertTrue($payments->approve($rollbackId, $accountant, 65000)['success'], 'retry succeeds after temporary failure');

    // Render the real printable page, checking identical sheets and one toolbar.
    $savedSession = $_SESSION;
    $savedGet = $_GET;
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $accountant;
    $_SESSION['role'] = 'accountant';
    try {
        $render = function ($copies) use ($id) {
            $_GET = ['payment' => $id, 'copies' => $copies, 'embed' => '1'];
            ob_start();
            require __DIR__ . '/../../cases/voucher.php';
            return ob_get_clean();
        };
        $html = $render(2);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $sheets = $xpath->query('//div[@class="sheet"]');
        $t->assertSame(2, $sheets->length, 'two actual copies in print document');
        $t->assertSame($dom->saveHTML($sheets->item(0)), $dom->saveHTML($sheets->item(1)), 'copies have identical amount and receipt number');
        $t->assertSame(1, $xpath->query('//div[@class="rc-bar"]')->length, 'toolbar appears only once');
        $t->assertTrue(strpos($html, 'break-before: page') !== false, 'each copy starts a print page');
        $t->assertSame(false, strpos($html, "window.addEventListener('load'"), 'embedded document delegates printing to dialog');
        $t->assertSame(1, substr_count($render(0), '<div class="sheet">'), 'at least one copy');
        $t->assertSame(4, substr_count($render(999), '<div class="sheet">'), 'copy count bounded');
    } finally {
        $_SESSION = $savedSession;
        $_GET = $savedGet;
    }
})();
