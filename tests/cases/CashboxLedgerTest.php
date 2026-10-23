<?php
require_once __DIR__ . '/../../classes/CashboxLedger.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/PatientAdvance.php';
require_once __DIR__ . '/../../classes/XrayManager.php';
require_once __DIR__ . '/../../classes/ApprovalReversal.php';
(function () use ($t) {
    $savedSession=$_SESSION;
    $db=fx_db(); $ledger=new CashboxLedger();
    $doc=fx_user('doctor'); $doc2=fx_user('doctor'); $admin=fx_user('admin'); $acct=fx_user('accountant'); $xrUser=fx_user('xray_department');
    $pat=fx_patient(); $folder=fx_folder($pat,$doc); $case=fx_case($folder);
    $key='doctor:' . $doc;
    $_SESSION=['user_id'=>$admin,'role'=>'admin','logged_in'=>true];
    $balance=function ($k) use ($db) { return $db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=?',[$k])['balance'] ?? '0.00'; };
    $assertInvariant=function ($k) use ($db,$t,$balance) {
        $sum=$db->fetch('SELECT COALESCE(SUM(amount),0) total FROM cashbox_entries WHERE account_key=?',[$k])['total'];
        $t->assertSame(CashboxLedger::cents($sum),CashboxLedger::cents($balance($k)),'cached cash equals immutable journal');
    };
    $t->assertSame(123456789012301,CashboxLedger::cents('1234567890123.01'),'large money remains exact');
    foreach (['1e5','NaN','1.001','1,000','--1',[]] as $bad) {
        $t->assertThrows(fn()=>CashboxLedger::cents($bad),'invalid money rejected');
    }
    $inv=fx_invoice($case,$doc,'sent','sent',100000); fx_treatment($case,100000,$inv);
    $db->update('case_invoices',['work_date'=>'2026-10-02','draft_received_amount'=>'30000.25','payment_intent'=>'debt'],'id=?',[$inv]);
    $ledger->reconcile($key);
    $t->assertEqualsMoney(0,$balance($key),'sent cash request is not spendable');
    $approved=(new CaseInvoice())->approve($case,$inv,$admin);
    $t->assertTrue($approved['success'],'invoice approval succeeds');
    $t->assertEqualsMoney('30000.25',$balance($key),'actual chair cash counts even on partially unpaid invoice');
    $count=$db->count('cashbox_entries','account_key=?',[$key]);
    $ledger->reconcile($key); $ledger->reconcile($key);
    $t->assertSame($count,$db->count('cashbox_entries','account_key=?',[$key]),'repeated reconciliation never duplicates money');
    $old=fx_invoice($case,$doc,'sent','approved',1000); fx_treatment($case,1000,$old);
    $db->update('case_invoices',['work_date'=>'2026-09-30','draft_received_amount'=>1000],'id=?',[$old]);
    $future=fx_invoice($case,$doc,'sent','draft',500); fx_treatment($case,500,$future);
    $db->update('case_invoices',['draft_received_amount'=>500],'id=?',[$future]);
    $ledger->reconcile($key);
    $t->assertEqualsMoney('30000.25',$balance($key),'pre-October cash and drafts are excluded');
    $payments=new Payment();
    $p=$payments->directPayment(['case_id'=>$case,'amount'=>10000,'payment_method'=>'card','payment_type'=>'cash','payment_date'=>'2026-10-03'],$admin);
    $t->assertTrue($p['success'],'card payment accepted by original service');
    $t->assertEqualsMoney('30000.25',$balance($key),'card is not drawer cash');
    $p=$payments->createRequest(['case_id'=>$case,'amount'=>5000,'payment_method'=>'cash','received_by'=>$doc,'payment_date'=>'2026-10-03']);
    $t->assertTrue($p['success'],'pending cash request created');
    $t->assertEqualsMoney('30000.25',$balance($key),'pending receipt adds no cash');
    $t->assertTrue($payments->approve($p['payment_id'],$admin)['success'],'cash receipt approved');
    $t->assertEqualsMoney('35000.25',$balance($key),'approved cash receipt adds exactly once');
    $t->assertTrue($payments->refund($p['payment_id'],'patient refund',$admin)['success'],'refund succeeds');
    $t->assertEqualsMoney('30000.25',$balance($key),'refund removes cash while keeping receipt history');
    $t->assertTrue(!$payments->refund($p['payment_id'],'again',$admin)['success'],'double refund rejected');
    $t->assertEqualsMoney('30000.25',$balance($key),'double refund does not change cash');
    $advance=(new PatientAdvance())->receive($pat,2000,'cash','advance',$admin,$doc);
    $t->assertTrue($advance['success'],'assigned advance received');
    $t->assertEqualsMoney('30000.25',$balance($key),'custody does not enter the doctor cashbox on receipt');
    $draft=fx_invoice($case,$doc,'draft','draft',3000); fx_treatment($case,3000,$draft);
    $allocation=(new PatientAdvance())->allocate($draft,1000,$admin);
    $t->assertTrue($allocation['success'],'advance allocation succeeds');
    $ledger->reconcile($key);
    $t->assertEqualsMoney('31000.25',$balance($key),'applied custody enters the doctor cashbox once');
    $t->assertTrue((new PatientAdvance())->refund($advance['advance_id'],500,'refund test',$admin)['success'],'partial advance refund succeeds');
    $t->assertEqualsMoney('31000.25',$balance($key),'unused custody refund does not affect doctor cash');
    $token=bin2hex(random_bytes(24));
    $move=$ledger->move($key,'deposit','0.10','Test exact deposit',$token,$doc);
    $t->assertEqualsMoney('31000.35',$balance($key),'doctor can deposit into own box in exact cents');
    $dupe=$ledger->move($key,'deposit','0.10','Test exact deposit',$token,$doc);
    $t->assertSame($move['id'],$dupe['id'],'double click returns original movement');
    $t->assertEqualsMoney('31000.35',$balance($key),'duplicate deposit does not increase money');
    $t->assertThrows(fn()=>$ledger->move($key,'deposit','0.20','Test exact deposit',$token,$doc),'reusing a token for different money fails');
    $t->assertThrows(fn()=>$ledger->move($key,'withdrawal','999999','Too much cash',bin2hex(random_bytes(24)),$doc),'cannot withdraw above balance');
    $t->assertThrows(fn()=>$ledger->authorize($doc2,$key),'doctor cannot inspect another doctor');
    $t->assertThrows(fn()=>$ledger->move($key,'withdrawal',1,'Forbidden',bin2hex(random_bytes(24)),$doc2),'doctor cannot move another doctor cash');
    $t->assertThrows(fn()=>$ledger->authorize($acct,$key),'accountant does not inherit admin rights');
    $t->assertThrows(fn()=>$ledger->authorize($doc,'xray'),'doctor does not see xray cashbox');
    $ledger->authorize($xrUser,'xray');
    $t->assertThrows(fn()=>$ledger->move('xray','deposit',1,'Forbidden',bin2hex(random_bytes(24)),$xrUser),'xray staff have read access only');
    $out=$ledger->move($key,'withdrawal','1500.35','Cash handed to admin',bin2hex(random_bytes(24)),$doc);
    $t->assertEqualsMoney(29500,$balance($key),'withdrawal decreases exact amount');
    $reverse=$ledger->reverse($out['id'],'Correct mistaken withdrawal',$admin);
    $t->assertEqualsMoney('31000.35',$balance($key),'admin reversal restores cash');
    $t->assertSame($reverse,$ledger->reverse($out['id'],'Correct mistaken withdrawal',$admin),'manual reversal is idempotent');
    $t->assertThrows(fn()=>$ledger->reverse($move['id'],'Doctor tries reversal',$doc),'doctor cannot silently reverse manual history');
    $before=$balance($key);
    $db->beginTransaction();
    $ledger->move($key,'deposit','77.77','Rolled back deposit',bin2hex(random_bytes(24)),$admin);
    $db->rollback();
    $t->assertSame($before,$balance($key),'outer rollback also rolls back the cash journal');
    $xr=(int)$db->insert('xray_requests',['request_number'=>uniqid('CBXR'),'patient_id'=>$pat,'requested_by'=>$doc,'xray_type'=>'OPG','amount'=>8000,'original_amount'=>8000,'final_amount'=>8000,'status'=>'pending','request_status'=>'pending_accountant','payment_status'=>'pending','payment_type'=>'cash']);
    $xrManager=new XrayManager();
    $t->assertTrue($xrManager->approvePaymentAndSendToXray($xr,$admin,'admin')['success'],'xray cash approved');
    $t->assertEqualsMoney(8000,$balance('xray'),'xray money enters dedicated xray box');
    $t->assertSame($before,$balance($key),'requesting doctor receives none of xray cash');
    $xd=(int)$db->insert('xray_requests',['request_number'=>uniqid('CBXD'),'patient_id'=>$pat,'requested_by'=>$doc,'xray_type'=>'OPG','amount'=>10000,'original_amount'=>10000,'final_amount'=>10000,'status'=>'pending','request_status'=>'pending_accountant','payment_status'=>'debt_pending','payment_type'=>'debt']);
    $debt=(int)$db->insert('patient_xray_debts',['patient_id'=>$pat,'request_id'=>$xd,'amount'=>10000,'remaining_amount'=>10000]);
    $db->update('xray_requests',['request_status'=>'pending_xray','payment_status'=>'debt_approved'],'id=?',[$xd]);
    $t->assertTrue($xrManager->payDebt($debt,3000,$admin,'cash')['success'],'partial xray debt cash collected');
    $t->assertEqualsMoney(11000,$balance('xray'),'partial xray collection enters xray box');
    $t->assertTrue($xrManager->payDebt($debt,7000,$admin,'card')['success'],'remaining xray debt paid by card');
    $t->assertEqualsMoney(11000,$balance('xray'),'paid xray debt is not counted again and card stays out');
    $t->assertTrue((new ApprovalReversal())->remove('xray',$xr,$admin,'Xray refunded')['success'],'xray reversal succeeds');
    $t->assertEqualsMoney(3000,$balance('xray'),'reversing xray cash reduces only the xray box');
    $t->assertSame($before,$balance($key),'xray refund does not touch doctor cash');
    $historicalLog=(int)$db->insert('activity_log',['user_id'=>$admin,'action'=>'xray_request_deleted','table_name'=>'xray_requests','record_id'=>$xr,'created_at'=>'2026-10-06 12:00:00','old_values'=>json_encode(['payment_status'=>'paid','paid_at'=>'2026-10-04 12:00:00','final_amount'=>400,'request_number'=>'HIST-XR'])]);
    $ledger->reconcile('xray');
    $t->assertSame(2,$db->count('cashbox_entries','source_type=? AND source_id=?',['history',$historicalLog]),'deleted historical xray cash is recovered from audit as an inflow and refund');
    $t->assertEqualsMoney(3000,$balance('xray'),'historical xray refund history does not invent a net balance');
    $ledger->reconcile('xray');
    $t->assertSame(2,$db->count('cashbox_entries','source_type=? AND source_id=?',['history',$historicalLog]),'historical xray reversal imports only once');
    $statement=$ledger->statement($key,$doc,'2026-10-01','2026-10-17','out');
    $t->assertSame($before,$statement['account']['balance'],'direction filter never changes current balance');
    $t->assertTrue($statement['summary']['incoming']>0,'summary includes all inflows even when outflows filtered');
    $t->assertThrows(fn()=>$ledger->statement('*',$doc,'2026-10-01','2026-10-17'),'doctor cannot request global history');
    $global=$ledger->statement('*',$admin,'2026-10-01','2026-10-17');
    $t->assertTrue($global['count']>0,'admin can see global cash history');
    $assertInvariant($key); $assertInvariant('xray');

    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE,'1');
    $t->assertTrue((new CaseInvoice())->editApproved($inv,['received_amount'=>'31000.25'],$admin)['success'],'approved chair cash can be corrected');
    $t->assertEqualsMoney('32000.35',$balance($key),'approved invoice edit appends only its cash difference');
    $testPayment=$payments->directPayment(['case_id'=>$case,'amount'=>5000,'payment_method'=>'cash','payment_date'=>'2026-10-05'],$admin);
    $t->assertTrue($testPayment['success'],'new cash receipt created');
    $t->assertTrue($payments->editApproved($testPayment['payment_id'],4000,$admin,'Correct receipt')['success'],'approved payment can be corrected');
    $t->assertEqualsMoney('36000.35',$balance($key),'payment correction changes the box by the difference only');
    $snap=$balance($key);
    $failedInv=fx_invoice($case,$doc,'sent','sent',1000); fx_treatment($case,1000,$failedInv);
    $db->update('case_invoices',['draft_received_amount'=>100],'id=?',[$failedInv]);
    $db->getConnection()->exec("CREATE TRIGGER cashbox_test_failure BEFORE INSERT ON cashbox_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='cash journal failed'");
    try { $failure=(new CaseInvoice())->approve($case,$failedInv,$admin); }
    finally { $db->getConnection()->exec('DROP TRIGGER cashbox_test_failure'); }
    $t->assertSame(false,$failure['success'],'journal failure blocks the financial approval');
    $t->assertSame('sent',$db->fetch('SELECT accounting_status FROM case_invoices WHERE id=?',[$failedInv])['accounting_status'],'failed journal leaves invoice unapproved');
    $t->assertSame($snap,$balance($key),'failed transaction leaves cash unchanged');
    $legacyRefund=(int)$db->insert('payments',['case_id'=>$case,'payment_number'=>uniqid('CBRF'),'amount'=>450,'payment_method'=>'cash','status'=>'refunded','payment_date'=>'2026-10-04','reversed_at'=>'2026-10-06 12:00:00','received_by'=>$doc,'reversed_by'=>$admin]);
    $ledger->reconcile($key);
    $t->assertSame(2,$db->count('cashbox_entries','source_type=? AND source_id=?',['payment',$legacyRefund]),'historical refunded receipt imports both original cash and refund');
    $t->assertSame($snap,$balance($key),'historical complete refund nets to zero');
    $inOnly=$ledger->statement($key,$doc,'2026-10-04','2026-10-04');
    $t->assertEqualsMoney(450,$inOnly['summary']['incoming'],'backfill retains the historical cash date');

    // Independent connections race for the same cash. Exactly one can spend it.
    $raceKey='doctor:' . $doc2;
    $ledger->move($raceKey,'deposit',1000,'Concurrency starting cash',bin2hex(random_bytes(24)),$admin);
    $workers=[];
    foreach ([1,2] as $n) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__DIR__ . '/../cashbox-worker.php',$raceKey,'800',bin2hex(random_bytes(24)),(string)$doc2],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        fclose($pipes[0]); $workers[]=[$process,$pipes];
    }
    $success=0;
    foreach ($workers as [$process,$pipes]) {
        $answer=json_decode(stream_get_contents($pipes[1]),true);
        $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $t->assertSame(0,proc_close($process),'concurrent worker exits normally: ' . $error);
        if (!empty($answer['ok'])) $success++;
    }
    $t->assertSame(1,$success,'two concurrent withdrawals cannot overspend the same balance');
    $t->assertEqualsMoney(200,$balance($raceKey),'balance reflects one concurrent withdrawal');
    $workers=[]; $sameToken=bin2hex(random_bytes(24));
    foreach ([1,2] as $n) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__DIR__ . '/../cashbox-worker.php',$raceKey,'100',$sameToken,(string)$doc2],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        fclose($pipes[0]); $workers[]=[$process,$pipes];
    }
    $ids=[];
    foreach ($workers as [$process,$pipes]) {
        $answer=json_decode(stream_get_contents($pipes[1]),true);
        $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $t->assertSame(0,proc_close($process),'duplicate-submit worker exits normally: ' . $error);
        $t->assertTrue(!empty($answer['ok']),'simultaneous duplicate submission returns success');
        $ids[]=$answer['id'] ?? null;
    }
    $t->assertSame($ids[0],$ids[1],'simultaneous double submit returns the same journal entry');
    $t->assertEqualsMoney(100,$balance($raceKey),'simultaneous duplicate deducts cash only once');

    $doc3=fx_user('doctor'); $pat3=fx_patient(); $case3=fx_case(fx_folder($pat3,$doc3));
    $invoice3=fx_invoice($case3,$doc3,'sent','sent',100); fx_treatment($case3,100,$invoice3);
    $db->update('case_invoices',['draft_received_amount'=>100],'id=?',[$invoice3]);
    $t->assertTrue((new CaseInvoice())->approve($case3,$invoice3,$admin)['success'],'deficit test invoice approved');
    $key3='doctor:' . $doc3;
    $ledger->move($key3,'withdrawal',100,'All cash handed to admin',bin2hex(random_bytes(24)),$doc3);
    $t->assertTrue((new ApprovalReversal())->remove('invoice',$invoice3,$admin,'Receipt corrected after handoff')['success'],'financial correction can proceed after cash handoff');
    $t->assertEqualsMoney(-100,$balance($key3),'correction after withdrawal exposes a deficit instead of hiding it');
    $t->assertThrows(fn()=>$ledger->move($key3,'withdrawal',1,'No cash available',bin2hex(random_bytes(24)),$doc3),'deficit blocks further withdrawals');
    $ledger->move($key3,'deposit',100,'Admin returned cash',bin2hex(random_bytes(24)),$admin);
    $t->assertEqualsMoney(0,$balance($key3),'returning cash settles the deficit');
    $assertInvariant($key3);
    $assertInvariant($key); $assertInvariant($raceKey); $assertInvariant('xray');
    $_SESSION=$savedSession;
})();
