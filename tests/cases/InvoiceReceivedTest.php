<?php
require_once __DIR__ . '/../../classes/PatientAdvance.php';
require_once __DIR__ . '/../../classes/Payment.php';

(function () use ($t) {
    $db = fx_db(); $manager = new PatientAdvance(); $payments = new Payment(); $ledger = new AccountingLedger();
    $doctor = fx_user('doctor'); $accountant = fx_user('accountant');
    $patient = fx_patient(); $caseId = fx_case(fx_folder($patient, $doctor));
    $invoiceId = fx_invoice($caseId, $doctor, 'sent', 'sent', 1650000);
    fx_treatment($caseId, 1650000, $invoiceId);
    $manager->receive($patient, 150000, 'cash', null, $accountant, $doctor);
    $pending = $payments->createRequest(['case_id'=>$caseId,'amount'=>150000,'allocations'=>[$invoiceId=>150000],'received_by'=>$doctor]);
    $t->assertEqualsMoney(0, $ledger->getInvoice($invoiceId)['advance_received_amount'], 'pending custody is not displayed as received');
    $t->assertTrue($manager->applyToPendingPayment((int)$pending['payment_id'],150000,$accountant)['success'], 'request paid from custody');
    $invoice = $ledger->getInvoice($invoiceId);
    $t->assertEqualsMoney(150000, $invoice['paid_amount'], 'custody pays the invoice once');
    $t->assertEqualsMoney(150000, $invoice['advance_received_amount'], 'received includes its custody source');
    $t->assertEqualsMoney(0, $invoice['draft_received_amount'], 'displaying received does not create new cash');
    $t->assertEqualsMoney(1500000, $invoice['remaining_amount'], 'remaining matches the patient example');

    $savedSession = $_SESSION; $savedGet = $_GET;
    $_SESSION = ['logged_in'=>true,'user_id'=>$accountant,'role'=>'accountant'];
    try {
        $render = static function ($caseId, $invoiceId) {
            $_GET = ['id'=>$caseId,'invoice'=>$invoiceId,'embed'=>'1','copies'=>1];
            ob_start(); require __DIR__ . '/../../cases/receipt.php'; return ob_get_clean();
        };
        $html = $render($caseId, $invoiceId);
        $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
        $t->assertSame('150,000', trim($xpath->query('//span[@class="rc-total-box is-paid"]')->item(0)->textContent), 'print shows the same single received total');
        $source = $xpath->query('//div[@class="rc-received-source"]');
        $t->assertSame(1, $source->length, 'print shows one custody source note');
        $t->assertTrue(strpos($source->item(0)->textContent,'150,000') !== false, 'source note shows the transferred amount');
        $t->assertTrue(strpos($html,'قەبزی پارەدانی دواتر') === false, 'print has no second received row');
        $renderPane = static function ($caseId, $invoiceId) {
            $manager = new CaseManager();
            $case = $manager->getById($caseId);
            $tab = (new AccountingLedger())->getInvoice($invoiceId);
            $rows = array_values(array_filter($manager->getTreatments($caseId),static function ($row) use ($invoiceId) { return (int)$row['invoice_id'] === $invoiceId; }));
            $isAdmin = false; $isDoctor = true; $canReceiveMoney = false; $showInvoiceActions = false;
            $activeInvoiceId = $invoiceId; $paneAlwaysVisible = true;
            $planFor = []; $planEligibility = []; $palmerNumbers = []; $toothTreatments = [];
            $dentalChart = new DentalChart();
            ob_start(); require __DIR__.'/../../cases/partials/invoice_pane.php'; return ob_get_clean();
        };
        $paneHtml = $renderPane($caseId,$invoiceId);
        @$dom->loadHTML('<meta charset="utf-8">'.$paneHtml); $xpath = new DOMXPath($dom);
        $t->assertSame('150,000',trim($xpath->query('//span[@data-paid-for]')->item(0)->textContent),'case pane shows the same received total');
        $t->assertSame(1,$xpath->query('//div[@class="vt-received-source"]')->length,'case pane names the custody transfer');
        $t->assertSame('1,500,000',trim($xpath->query('//span[@data-disc-rem]')->item(0)->textContent),'case pane retains the correct debt');

        $legacyInvoice = fx_invoice($caseId,$doctor,'sent','approved',100000);
        fx_treatment($caseId,100000,$legacyInvoice);
        $db->update('case_invoices',['advance_paid_amount'=>10000,'draft_received_amount'=>5000],'id=?',[$legacyInvoice]);
        $legacyHtml = $renderPane($caseId,$legacyInvoice);
        @$dom->loadHTML('<meta charset="utf-8">'.$legacyHtml); $xpath = new DOMXPath($dom);
        $t->assertSame('15,000',trim($xpath->query('//span[@data-paid-for]')->item(0)->textContent),'legacy custody is also included in the received total');
        $t->assertSame(0,$xpath->query('//span[@data-advance-for]')->length,'legacy custody is not displayed as a second amount');
        $t->assertSame('85,000',trim($xpath->query('//span[@data-disc-rem]')->item(0)->textContent),'legacy custody settles the invoice once');
    } finally { $_SESSION = $savedSession; $_GET = $savedGet; }

    $mixedPatient = fx_patient(); $mixedCase = fx_case(fx_folder($mixedPatient,$doctor));
    $mixedInvoice = fx_invoice($mixedCase,$doctor,'sent','sent',100000); fx_treatment($mixedCase,100000,$mixedInvoice);
    $db->update('cases',['is_historical'=>1],'id=?',[$mixedCase]);
    $db->update('case_invoices',['prior_paid_amount'=>10000,'draft_received_amount'=>5000],'id=?',[$mixedInvoice]);
    $manager->receive($mixedPatient,20000,'cash',null,$accountant,$doctor);
    $funded = $manager->applyToInvoice($mixedInvoice,20000,$accountant);
    $t->assertTrue($funded['success'], 'mixed invoice custody funded');
    $t->assertTrue($payments->directPayment(['case_id'=>$mixedCase,'amount'=>15000,'allocations'=>[$mixedInvoice=>15000]],$accountant)['success'], 'mixed invoice also has a fresh receipt');
    $mixed = $ledger->getInvoice($mixedInvoice);
    $t->assertEqualsMoney(50000, $mixed['paid_amount'], 'historical, invoice cash, later cash and custody counted once');
    $t->assertEqualsMoney(20000, $mixed['advance_received_amount'], 'only custody is attributed to the transfer');
    $t->assertEqualsMoney(5000, $mixed['draft_received_amount'], 'original invoice cash remains separate in storage');
    file_put_contents(__DIR__.'/../storage/invoice-received-fixtures.json',json_encode(['custody'=>$invoice,'mixed'=>$mixed],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $t->assertTrue($payments->refund((int)$funded['payment_id'],'Restore custody',$accountant)['success'], 'custody can be reversed');
    $t->assertEqualsMoney(0, $ledger->getInvoice($mixedInvoice)['advance_received_amount'], 'refunded custody no longer appears received');
    $t->assertEqualsMoney(30000, $ledger->getInvoice($mixedInvoice)['paid_amount'], 'remaining real payments survive reversal');
})();
