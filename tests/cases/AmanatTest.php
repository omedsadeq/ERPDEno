<?php
require_once __DIR__ . '/../../classes/PatientAdvance.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/ApprovalReversal.php';
require_once __DIR__ . '/../../classes/PatientLedger.php';
require_once __DIR__ . '/../../classes/InvoiceDeletion.php';
require_once __DIR__ . '/../../classes/CaseDeletion.php';
require_once __DIR__ . '/../../doctors/_daily_summary_lib.php';

(function () use ($t) {
    $savedSession = $_SESSION;
    $db = fx_db();
    $admin = fx_user('admin'); $accountant = fx_user('accountant'); $doctor = fx_user('doctor');
    $patient = fx_patient();
    $db->update('users', ['commission_rate'=>80], 'id=?', [$doctor]);
    Settings::set(CashboxLedger::SPLIT_SETTING, '1', 'boolean');
    $manager = new PatientAdvance(); $payments = new Payment(); $boxes = new CashboxLedger();
    $balance = static function ($key) use ($db) { return (float)($db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=?', [$key])['balance'] ?? 0); };
    $centerBefore = $balance('center'); $custodyBefore = $balance('amanat');
    $received = $manager->receive($patient, 300000, 'cash', 'Custody before any case', $accountant, $doctor);
    $t->assertTrue($received['success'], 'receive before opening any case');
    $receipt = (int)$received['advance_id'];
    $t->assertEqualsMoney(300000, $manager->balanceOf($patient), 'patient custody is visible');
    $t->assertEqualsMoney(0, $balance('doctor:'.$doctor), 'receive does not credit doctor');
    $t->assertEqualsMoney($centerBefore, $balance('center'), 'receive does not credit center');
    $t->assertEqualsMoney($custodyBefore+300000, $balance('amanat'), 'cash held separately');
    $today = date('Y-m-d');
    $daily = ds_compute_range($db, $doctor, $today, $today, false);
    $t->assertEqualsMoney(0, $daily['cashIn'], 'receipt is not daily income');
    $t->assertEqualsMoney(300000, $daily['advanceCollected'], 'receipt remains informational');
    $statement = (new PatientLedger())->statement($patient);
    $t->assertEqualsMoney(0, $statement['totals']['credit'], 'unapplied custody does not settle patient debt');

    $case = fx_case(fx_folder($patient, $doctor));
    $invoice = fx_invoice($case, $doctor, 'sent', 'sent', 250000);
    fx_treatment($case, 250000, $invoice);
    $request = $payments->createRequest(['case_id'=>$case,'amount'=>250000,'allocations'=>[$invoice=>250000],'received_by'=>$doctor]);
    $t->assertTrue($request['success'], 'doctor requests collection');
    $payment = (int)$request['payment_id'];
    // A previous-day request must recognize custody on approval day.
    $db->update('payments', ['payment_date'=>date('Y-m-d H:i:s', strtotime('-1 day'))], 'id=?', [$payment]);
    $t->assertEqualsMoney(300000, (new ApprovalQueue())->detail('payment', $payment)['advance_available'], 'approval drawer sees patient custody');
    $t->assertTrue(!$manager->applyToPendingPayment($payment, 250000, $doctor)['success'], 'doctor cannot apply custody');
    $t->assertEqualsMoney(300000, $manager->balanceOf($patient), 'unauthorized approval rolls back');
    $applied = $manager->applyToPendingPayment($payment, 250000, $accountant);
    $t->assertTrue($applied['success'], 'accountant funds and approves request');
    $stored = $payments->getById($payment);
    $t->assertSame('advance', $stored['payment_method'], 'payment identifies its source');
    $t->assertSame($today, substr($stored['payment_date'],0,10), 'income dated on approval day');
    $t->assertEqualsMoney(50000, $manager->balanceOf($patient), 'unused custody remains on patient');
    $t->assertEqualsMoney(200000, $balance('doctor:'.$doctor), 'doctor receives configured 80 percent');
    $t->assertEqualsMoney($centerBefore+50000, $balance('center'), 'center receives remaining 20 percent');
    $t->assertEqualsMoney($custodyBefore+50000, $balance('amanat'), 'custody moved instead of duplicated');
    $t->assertTrue(!$manager->applyToPendingPayment($payment,250000,$accountant)['success'], 'double approval rejected');
    $boxes->reconcile();
    $t->assertEqualsMoney(200000, $balance('doctor:'.$doctor), 'reconciliation preserves advance-funded payment');
    $daily = ds_compute_range($db,$doctor,$today,$today,false);
    $t->assertEqualsMoney(250000,$daily['cashIn'],'applied custody counted as income once');
    $t->assertEqualsMoney(200000,$daily['doctorShare'],'daily share uses configured rate');
    $t->assertEqualsMoney(250000,(new PatientLedger())->statement($patient)['totals']['credit'],'patient statement credits only approved payment');
    $t->assertTrue(!empty($payments->approvedEditBlockReason($stored)), 'funded payment cannot be edited out of balance');

    $t->assertTrue($payments->refund($payment,'Restore patient custody',$accountant)['success'],'payment reversal succeeds');
    $t->assertEqualsMoney(300000,$manager->balanceOf($patient),'reversal restores patient custody');
    $t->assertEqualsMoney(0,$balance('doctor:'.$doctor),'reversal removes doctor share');
    $t->assertEqualsMoney($centerBefore,$balance('center'),'reversal removes center share');
    $t->assertEqualsMoney($custodyBefore+300000,$balance('amanat'),'reversal restores cash custody');
    $t->assertEqualsMoney(250000,(new PatientLedger())->statement($patient)['closing'],'reversal leaves the invoice unpaid and custody separate');
    $t->assertTrue(!$manager->refund($receipt,300001,'Too much',$accountant)['success'],'cannot over-refund custody');
    $t->assertTrue($manager->refund($receipt,100000,'Return unused custody',$accountant)['success'],'partial unused custody refund');
    $t->assertEqualsMoney(200000,$manager->balanceOf($patient),'partial refund updates available custody');
    $t->assertEqualsMoney(0,ds_compute_range($db,$doctor,$today,$today,false)['cashIn'],'custody refund is not negative income');

    $failed = $manager->applyToInvoice($invoice,250000,$accountant,'too-much-custody');
    $t->assertTrue(!$failed['success'],'insufficient custody fails atomically');
    $t->assertEqualsMoney(200000,$manager->balanceOf($patient),'failed funding consumes nothing');
    $t->assertEqualsMoney(0,$balance('doctor:'.$doctor),'failed funding credits no share');
    $success = $manager->applyToInvoice($invoice,150000,$accountant,'custody-retry-key');
    $t->assertTrue($success['success'],'partial invoice funding succeeds');
    $duplicate = $manager->applyToInvoice($invoice,150000,$accountant,'custody-retry-key');
    $t->assertTrue(!empty($duplicate['duplicate']),'retry reuses the same approved payment');
    $t->assertEqualsMoney(50000,$manager->balanceOf($patient),'retry does not consume more custody');
    $t->assertTrue(!$manager->delete($receipt,'Allocated receipt',$accountant)['success'],'allocated receipt cannot be deleted');

    // Noncash custody never creates physical cash when recognized as income.
    $otherPatient = fx_patient(); $otherDoctor=fx_user('doctor');
    $db->update('users',['commission_rate'=>50],'id=?',[$otherDoctor]);
    $otherCase=fx_case(fx_folder($otherPatient,$otherDoctor));
    $otherInvoice=fx_invoice($otherCase,$otherDoctor,'sent','sent',1000); fx_treatment($otherCase,1000,$otherInvoice);
    $manager->receive($otherPatient,1000,'card',null,$accountant,$otherDoctor);
    $card=$manager->applyToInvoice($otherInvoice,1000,$accountant,'card-custody');
    $t->assertTrue($card['success'],'noncash custody can pay an invoice');
    $t->assertEqualsMoney(0,$balance('doctor:'.$otherDoctor),'card custody does not create physical cash');
    $t->assertEqualsMoney(1000,ds_compute_range($db,$otherDoctor,$today,$today,false)['cashIn'],'noncash applied custody is income');
    $boxes->reconcile();
    $t->assertEqualsMoney(0,$balance('doctor:'.$otherDoctor),'reconciliation preserves noncash distinction');

    // Existing policy blocks reopening payments; removing one restores custody
    // and a replacement request can use those same receipts again.
    $reopened = (new ApprovalReversal())->reopen('payment',(int)$success['payment_id'],$admin,'Review funded payment');
    $t->assertTrue(!$reopened['success'],'existing payment reopen policy remains enforced');
    $removed=(new ApprovalReversal())->remove('payment',(int)$success['payment_id'],$admin,'Review funded payment');
    $t->assertTrue($removed['success'],'removing funded payment restores custody');
    $t->assertEqualsMoney(200000,$manager->balanceOf($patient),'removal releases its custody');
    $replacement=$payments->createRequest(['case_id'=>$case,'amount'=>150000,'allocations'=>[$invoice=>150000],'received_by'=>$doctor]);
    $t->assertTrue($manager->applyToPendingPayment((int)$replacement['payment_id'],150000,$accountant)['success'],'replacement request can use the same receipts');
    $t->assertEqualsMoney(50000,$manager->balanceOf($patient),'reapproval consumes custody once');
    $boxes->reconcile();
    $t->assertEqualsMoney(120000,$balance('doctor:'.$doctor),'reapproval does not double historical cash allocations');

    // Import an old receipt whose doctor and center shares were already booked.
    $legacyDoctor=fx_user('doctor'); $legacyPatient=fx_patient();
    $boxes->move('doctor:'.$legacyDoctor,'deposit',6000,'Legacy doctor share fixture',bin2hex(random_bytes(16)),$admin);
    $boxes->move('center','deposit',4000,'Legacy center share fixture',bin2hex(random_bytes(16)),$admin);
    $legacyReceipt=(int)$db->insert('patient_advances',['advance_no'=>uniqid('LEGACY-'),'patient_id'=>$legacyPatient,'doctor_id'=>$legacyDoctor,'amount'=>10000,'payment_method'=>'cash','received_at'=>date('Y-m-d H:i:s')]);
    $db->insert('cashbox_sources',['source_type'=>'advance','source_id'=>$legacyReceipt,'account_key'=>'doctor:'.$legacyDoctor,'booked_amount'=>10000,'booked_doctor'=>6000,'booked_center'=>4000,'split_rate'=>60,'reference_no'=>'Legacy','source_date'=>date('Y-m-d H:i:s')]);
    $legacyCenter=$balance('center'); $legacyCustody=$balance('amanat');
    $boxes->reconcile('amanat');
    $t->assertEqualsMoney(0,$balance('doctor:'.$legacyDoctor),'migration removes frozen doctor share');
    $t->assertEqualsMoney($legacyCenter-4000,$balance('center'),'migration removes frozen center share');
    $t->assertEqualsMoney($legacyCustody+10000,$balance('amanat'),'migration moves entire receipt to custody');
    $entryCount=(int)$db->count('cashbox_entries'); $boxes->reconcile('amanat');
    $t->assertSame($entryCount,(int)$db->count('cashbox_entries'),'legacy migration is idempotent');

    // Independent invoices cannot spend the same patient's last receipt twice.
    $racePatient=fx_patient(); $raceDoctor=fx_user('doctor');
    $raceCase=fx_case(fx_folder($racePatient,$raceDoctor));
    $manager->receive($racePatient,1000,'cash',null,$accountant,$raceDoctor);
    $workers=[];
    foreach ([1,2] as $n) {
        $raceInvoice=fx_invoice($raceCase,$raceDoctor,'sent','sent',1000); fx_treatment($raceCase,1000,$raceInvoice);
        $pipes=[];
        $process=proc_open([PHP_BINARY,__DIR__.'/../amanat-worker.php',(string)$raceInvoice,'1000',(string)$accountant,'race-'.uniqid()], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $workers[]=[$process,$pipes];
    }
    $wins=0;
    foreach ($workers as [$process,$pipes]) {
        $answer=json_decode(stream_get_contents($pipes[1]),true); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $t->assertSame(0,proc_close($process),'concurrent funder exits normally: '.$error);
        if (!empty($answer['success'])) $wins++;
    }
    $t->assertSame(1,$wins,'only one concurrent request can spend the receipt');
    $t->assertEqualsMoney(0,$manager->balanceOf($racePatient),'concurrent consumption leaves exact zero');
    $db->query("UPDATE case_invoices SET accounting_status='cancelled' WHERE case_id=? AND accounting_status='sent'",[$raceCase]);

    // Hard deletion also returns custody and reverses both cashbox shares.
    $_SESSION=['user_id'=>$admin,'role'=>'admin','logged_in'=>true];
    foreach (['invoice','case'] as $mode) {
        $deletePatient=fx_patient(); $deleteDoctor=fx_user('doctor');
        $db->update('users',['commission_rate'=>70],'id=?',[$deleteDoctor]);
        $deleteCase=fx_case(fx_folder($deletePatient,$deleteDoctor));
        $deleteInvoice=fx_invoice($deleteCase,$deleteDoctor,'sent','sent',12000); fx_treatment($deleteCase,12000,$deleteInvoice);
        $manager->receive($deletePatient,12000,'cash',null,$accountant,$deleteDoctor);
        $t->assertTrue($manager->applyToInvoice($deleteInvoice,7000,$accountant)['success'],'deletion fixture funded');
        $t->assertEqualsMoney(4900,$balance('doctor:'.$deleteDoctor),'deletion fixture split');
        $result=$mode==='invoice' ? (new InvoiceDeletion())->delete($deleteCase,$deleteInvoice,'Delete funded invoice')
            : (new CaseDeletion())->delete($deleteCase,$deletePatient,'Delete funded case');
        $t->assertTrue($result['success'],$mode.' deletion succeeds');
        $t->assertEqualsMoney(12000,$manager->balanceOf($deletePatient),$mode.' deletion restores patient custody');
        $t->assertEqualsMoney(0,$balance('doctor:'.$deleteDoctor),$mode.' deletion removes doctor cash');
    }
    $_SESSION=$savedSession;

    $sharedPatient=fx_patient(); $sharedCase=fx_case(fx_folder($sharedPatient,$doctor));
    $sharedInvoices=[];
    foreach ([600,400] as $part) { $iid=fx_invoice($sharedCase,$doctor,'sent','sent',$part); fx_treatment($sharedCase,$part,$iid); $sharedInvoices[$iid]=$part; }
    $manager->receive($sharedPatient,1000,'cash',null,$accountant,$doctor);
    $sharedRequest=$payments->createRequest(['case_id'=>$sharedCase,'amount'=>1000,'allocations'=>$sharedInvoices,'received_by'=>$doctor]);
    $t->assertTrue($manager->applyToPendingPayment((int)$sharedRequest['payment_id'],1000,$accountant)['success'],'one custody payment can fund multiple invoices');
    foreach ($sharedInvoices as $sharedId=>$part) {
        $t->assertEqualsMoney($part,(new AccountingLedger())->getInvoice($sharedId)['advance_received_amount'],'each invoice displays only its own share of a shared custody receipt');
    }
    $_SESSION=['user_id'=>$admin,'role'=>'admin','logged_in'=>true];
    $sharedInvoice=(int)array_key_first($sharedInvoices);
    $t->assertTrue(!(new InvoiceDeletion())->delete($sharedCase,$sharedInvoice,'Delete one shared funded invoice')['success'],'partial deletion cannot strand shared custody funding');
    $t->assertTrue(!(new ApprovalReversal())->remove('invoice',$sharedInvoice,$admin,'Cancel one shared funded invoice')['success'],'partial cancellation cannot strand shared custody funding');
    $t->assertEqualsMoney(0,$manager->balanceOf($sharedPatient),'refused deletion preserves consumed custody');
    $t->assertTrue($payments->refund((int)$sharedRequest['payment_id'],'Reverse shared custody payment',$accountant)['success'],'shared funding can be reversed as one payment');
    $t->assertEqualsMoney(1000,$manager->balanceOf($sharedPatient),'shared reversal restores all custody');
    $_SESSION=$savedSession;

    // Mixed receipt methods keep FIFO custody and physical cash distinct, also
    // when funding a canonical installment collection.
    $mixedPatient=fx_patient(); $mixedDoctor=fx_user('doctor');
    $db->update('users',['commission_rate'=>60],'id=?',[$mixedDoctor]);
    $mixedCase=fx_case(fx_folder($mixedPatient,$mixedDoctor));
    $mixedInvoice=fx_invoice($mixedCase,$mixedDoctor,'sent','approved',1000); fx_treatment($mixedCase,1000,$mixedInvoice);
    $manager->receive($mixedPatient,500,'cash',null,$accountant,$mixedDoctor);
    $manager->receive($mixedPatient,700,'card',null,$accountant,$mixedDoctor);
    $installments=new Installment();
    $plan=$installments->proposePlan(['case_id'=>$mixedCase,'invoice_id'=>$mixedInvoice,'total_amount'=>1000,'number_of_installments'=>2,'start_date'=>$today,'down_payment'=>0],$mixedDoctor);
    $installments->activatePlan($plan,[],$accountant);
    $t->assertSame('active',$db->fetch('SELECT status FROM installment_plans WHERE id=?',[$plan])['status'],'mixed installment fixture active');
    $mixedRequest=$payments->createRequest(['case_id'=>$mixedCase,'payment_type'=>'installment','amount'=>1000,'allocations'=>[$mixedInvoice=>1000],'received_by'=>$mixedDoctor]);
    $t->assertTrue($manager->applyToPendingPayment((int)$mixedRequest['payment_id'],1000,$accountant)['success'],'installment funded from mixed custody');
    $t->assertEqualsMoney(200,$manager->balanceOf($mixedPatient),'FIFO leaves unspent card custody');
    $t->assertEqualsMoney(500,$payments->getById($mixedRequest['payment_id'])['advance_cash_amount'],'payment freezes the cash part');
    $t->assertEqualsMoney(300,$balance('doctor:'.$mixedDoctor),'cash part split at configured 60 percent');
    $t->assertSame('advance',$db->fetch('SELECT payment_method FROM installment_payments WHERE payment_id=?',[$mixedRequest['payment_id']])['payment_method'],'installment identifies custody funding');
    $t->assertTrue($payments->refund((int)$mixedRequest['payment_id'],'Restore installment custody',$accountant)['success'],'installment custody reversal succeeds');
    $t->assertSame(0,(int)$db->count('installment_payments','payment_id=?',[$mixedRequest['payment_id']]),'reversal removes installment collection');
    $t->assertEqualsMoney(1200,$manager->balanceOf($mixedPatient),'reversal restores both receipt methods');
    $t->assertEqualsMoney(0,$balance('doctor:'.$mixedDoctor),'mixed cash split reversed exactly');
    Settings::set(CashboxLedger::SPLIT_SETTING,'0','boolean');
})();
