<?php
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/PatientAdvance.php';

(function () use ($t) {
    $db = fx_db(); $invoices = new CaseInvoice(); $custody = new PatientAdvance(); $ledger = new AccountingLedger();
    $doctor = fx_user('doctor'); $accountant = fx_user('accountant');
    $make = static function ($held=150000,$total=550000,$cash=150000) use ($db,$doctor,$accountant,$custody) {
        $patient = fx_patient(); $case = fx_case(fx_folder($patient,$doctor));
        $invoice = fx_invoice($case,$doctor,'sent','sent',$total); fx_treatment($case,$total,$invoice);
        $db->update('case_invoices',['draft_received_amount'=>$cash],'id=?',[$invoice]);
        $receipt = $custody->receive($patient,$held,'cash',null,$accountant,$doctor);
        return [$patient,$case,$invoice,(int)$receipt['advance_id']];
    };
    [$patient,$case,$invoice,$receipt] = $make();
    $approved = $invoices->approveWithAdvance($case,$invoice,$accountant,'150000','0','Inline custody');
    $t->assertTrue($approved['success'],'invoice and selected custody approved in one operation: '.($approved['message'] ?? ''));
    $row = $ledger->getInvoice($invoice);
    $t->assertSame('approved',$row['accounting_status'],'invoice approved');
    $t->assertEqualsMoney(0,$row['draft_received_amount'],'doctor-entered custody does not become new cash');
    $t->assertEqualsMoney(150000,$row['paid_amount'],'one received amount');
    $t->assertEqualsMoney(150000,$row['advance_received_amount'],'received source is custody');
    $t->assertEqualsMoney(400000,$row['remaining_amount'],'example invoice has correct remainder');
    $t->assertEqualsMoney(0,$custody->balanceOf($patient),'selected custody consumed');
    $t->assertSame('advance',$db->fetch('SELECT payment_method FROM payments WHERE id=?',[$approved['payment_id']])['payment_method'],'canonical payment identifies custody');
    $t->assertSame(1,(int)$db->count('payments','case_id=?',[$case]),'exactly one canonical receipt');
    $t->assertSame(false,$invoices->approveWithAdvance($case,$invoice,$accountant,150000,0)['success'],'double approval refused');
    $t->assertSame(1,(int)$db->count('patient_advance_allocations','advance_id=? AND reversed_at IS NULL',[$receipt]),'retry cannot spend custody again');

    [$mixedPatient,$mixedCase,$mixedInvoice] = $make(150000,550000,200000);
    $t->assertTrue($invoices->approveWithAdvance($mixedCase,$mixedInvoice,$accountant,'150000','50000')['success'],'custody and fresh cash can be combined');
    $mixed = $ledger->getInvoice($mixedInvoice);
    $t->assertEqualsMoney(200000,$mixed['paid_amount'],'mixed sources counted once');
    $t->assertEqualsMoney(50000,$mixed['draft_received_amount'],'only fresh cash stored on invoice');
    $t->assertEqualsMoney(350000,$mixed['remaining_amount'],'mixed-source remainder correct');

    [$failedPatient,$failedCase,$failedInvoice,$failedReceipt] = $make(100000);
    $beforeCash = $db->fetchAll('SELECT account_key,balance FROM cashbox_accounts ORDER BY account_key');
    $beforeAudit = (int)$db->count('activity_log');
    $beforeEntries = (int)$db->count('cashbox_entries');
    $t->assertSame(false,$invoices->approveWithAdvance($failedCase,$failedInvoice,$accountant,150000,0)['success'],'insufficient custody fails after provisional approval');
    $failed = $ledger->getInvoice($failedInvoice);
    $t->assertSame('sent',$failed['accounting_status'],'failed funding rolls approval back');
    $t->assertEqualsMoney(150000,$failed['draft_received_amount'],'failed funding restores original invoice cash');
    $t->assertSame(null,$failed['approved_at'],'failed funding has no approval date');
    $t->assertEqualsMoney(100000,$custody->balanceOf($failedPatient),'failed funding restores all custody');
    $t->assertSame(0,(int)$db->count('payments','case_id=?',[$failedCase]),'failed funding leaves no receipt');
    $t->assertSame(0,(int)$db->count('patient_advance_allocations','advance_id=?',[$failedReceipt]),'partial funding allocations rolled back');
    $t->assertSame($beforeCash,$db->fetchAll('SELECT account_key,balance FROM cashbox_accounts ORDER BY account_key'),'failed funding restores cashbox balances');
    $t->assertSame($beforeAudit,(int)$db->count('activity_log'),'failed funding leaves no success audit');
    $t->assertSame($beforeEntries,(int)$db->count('cashbox_entries'),'failed funding leaves no cash journal movement');
    $t->assertSame(false,$db->inTransaction(),'failure closes transaction');

    [$overPatient,$overCase,$overInvoice] = $make(150000,100000,0);
    $t->assertSame(false,$invoices->approveWithAdvance($overCase,$overInvoice,$accountant,150000,0)['success'],'custody cannot overpay invoice');
    $t->assertSame('sent',$ledger->getInvoice($overInvoice)['accounting_status'],'overpayment does not approve');
    $t->assertEqualsMoney(150000,$custody->balanceOf($overPatient),'overpayment does not consume custody');
    foreach ([[-1,0],[0,-1],['NaN',0],['1.001',0]] as [$advance,$cash]) {
        $t->assertSame(false,$invoices->approveWithAdvance($overCase,$overInvoice,$accountant,$advance,$cash)['success'],'invalid amounts refused');
    }
    $t->assertSame(false,$invoices->approveWithAdvance($overCase,$overInvoice,$doctor,50000,0)['success'],'doctor cannot choose custody on approval');
    $t->assertSame(false,$invoices->approveWithAdvance($case,$overInvoice,$accountant,50000,0)['success'],'invoice cannot be approved under another case');

    [$cashPatient,$cashCase,$cashInvoice] = $make(150000,100000,0);
    $t->assertTrue($invoices->approveWithAdvance($cashCase,$cashInvoice,$accountant,0,50000)['success'],'cash-only approval also avoids a choice dialog');
    $t->assertEqualsMoney(50000,$ledger->getInvoice($cashInvoice)['paid_amount'],'cash-only receipt counted once');
    $t->assertEqualsMoney(150000,$custody->balanceOf($cashPatient),'cash-only approval preserves unselected custody');
    $t->assertSame(0,(int)$db->count('payments','case_id=?',[$cashCase]),'cash-only approval creates no duplicate canonical receipt');
    [$fullPatient,$fullCase,$fullInvoice] = $make(150000,150000,0);
    $t->assertTrue($invoices->approveWithAdvance($fullCase,$fullInvoice,$accountant,150000,0)['success'],'custody can settle the whole invoice');
    $t->assertSame('processed',$ledger->getInvoice($fullInvoice)['status'],'fully covered invoice marked settled');
    $t->assertSame('cash',$ledger->getInvoice($fullInvoice)['payment_intent'],'intent follows the final funded balance');
})();
