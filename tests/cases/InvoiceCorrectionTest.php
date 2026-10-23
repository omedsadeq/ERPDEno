<?php
require_once __DIR__ . '/../../classes/InvoiceCorrection.php';
(function () use ($t) {
    $db = fx_db(); $editor = new InvoiceCorrection(); $ci = new CaseInvoice(); $pm = new Payment();
    $saved = $_SESSION;
    $admin = fx_user('admin'); $doctor = fx_user('doctor'); $accountant = fx_user('accountant');
    $_SESSION = ['user_id'=>$admin,'role'=>'admin','logged_in'=>true];
    $patient = fx_patient(); $case = fx_case(fx_folder($patient,$doctor));
    $db->update('cases',['is_historical'=>1],'id=?',[$case]);
    $invoice = fx_invoice($case,$doctor,'sent','sent',100000);
    $row = fx_treatment($case,100000,$invoice);
    $db->update('case_invoices',['work_date'=>'2026-10-02','draft_received_amount'=>20000,'prior_paid_amount'=>10000],'id=?',[$invoice]);
    $t->assertTrue($ci->approve($case,$invoice,$accountant)['success'],'fixture approved');
    $approval = $db->fetch('SELECT approved_by,approved_at FROM case_invoices WHERE id=?',[$invoice]);
    $balance = static function () use ($db,$doctor) { return $db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=?',['doctor:'.$doctor])['balance'] ?? 0; };
    $payload = static function ($iid=null) use ($editor,$case,$invoice,$admin) {
        $s = $editor->snapshot($case,$iid ?? $invoice,$admin); $i=$s['invoice'];
        return ['revision'=>$s['revision'],'invoice_no'=>$i['invoice_no'],'work_date'=>$i['work_date'],
            'notes'=>$i['notes'] ?? '', 'accounting_notes'=>$i['accounting_notes'] ?? '',
            'discount_mode'=>$i['discount_mode'],'discount_value'=>$i['discount_mode'] === 'percent' ? $i['discount_value'] : $i['discount_amount'],
            'received_amount'=>$i['draft_received_amount'],'prior_paid_amount'=>$i['prior_paid_amount'],
            'reason'=>'Test financial correction','rows'=>$s['rows'],'payments'=>[]];
    };
    $save = static function ($p,$iid=null) use ($editor,$case,$invoice,$admin) { return $editor->save($case,$iid ?? $invoice,$admin,$p); };
    $inv = static function () use ($db,$invoice) { return $db->fetch('SELECT * FROM case_invoices WHERE id=?',[$invoice]); };
    $journal = static function () use ($db,$doctor,$t,$balance) {
        $sum = $db->fetch('SELECT COALESCE(SUM(amount),0) amount FROM cashbox_entries WHERE account_key=?',['doctor:'.$doctor])['amount'];
        $t->assertEqualsMoney($sum,$balance(),'cached cash equals immutable journal');
    };
    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE,'0');
    Settings::set(Settings::ALLOW_EDIT_SENT_INVOICE,'0');
    $p = $payload(); $p['rows'][0]['quantity']='2'; $p['rows'][0]['unit_price']='60000.25';
    $p['discount_mode']='percent'; $p['discount_value']='10'; $p['received_amount']='40000.25';
    $p['prior_paid_amount']='15000.50'; $p['notes']='Correction <script>unsafe</script>';
    $r = $save($p);
    $t->assertTrue($r['success'],'explicit admin mode works with ordinary unlock settings off: '.($r['message'] ?? ''));
    $t->assertEqualsMoney('120000.50',$inv()['total_amount'],'total follows corrected rows');
    $t->assertEqualsMoney('12000.05',$inv()['discount_amount'],'percent rounded to cents');
    $t->assertEqualsMoney('40000.25',$balance(),'only actual chair cash reaches the cashbox');
    $t->assertSame('approved',$inv()['accounting_status'],'approval retained');
    $t->assertSame($approval,$db->fetch('SELECT approved_by,approved_at FROM case_invoices WHERE id=?',[$invoice]),'approver and approval time retained');
    $t->assertSame(false,Settings::allowEditApprovedInvoice(),'global editing switch unchanged');
    $t->assertEqualsMoney('55000.75',$db->fetch('SELECT paid_amount FROM cases WHERE id=?',[$case])['paid_amount'],'case paid includes historical money once');
    $journal();
    $audit = $db->fetch("SELECT * FROM activity_log WHERE action='invoice_admin_corrected' AND record_id=? ORDER BY id DESC LIMIT 1",[$invoice]);
    $t->assertSame((string)$admin,(string)$audit['user_id'],'mandatory audit attributes actor');
    $t->assertSame('Test financial correction',json_decode($audit['new_values'],true)['reason'],'audit keeps reason');
    foreach ([$doctor,$accountant] as $actor) {
        $t->assertTrue(!$editor->save($case,$invoice,$actor,$payload())['success'],'non-admin correction refused');
        $t->assertThrows(fn()=>$editor->snapshot($case,$invoice,$actor),'non-admin seed refused');
    }
    $inactive=fx_user('admin'); $db->update('users',['is_active'=>0],'id=?',[$inactive]);
    $t->assertTrue(!$editor->save($case,$invoice,$inactive,$payload())['success'],'inactive admin refused');
    $p=$payload(); $db->update('case_invoices',['notes'=>'changed elsewhere'],'id=?',[$invoice]);
    $t->assertTrue(!$save($p)['success'],'stale editor rejected');
    $t->assertSame('changed elsewhere',$inv()['notes'],'stale save did not overwrite colleague');
    $p=$payload(); $p['received_amount']='999999'; $p['rows'][0]['treatment_name']='must roll back';
    $cashBefore=$balance();
    $t->assertTrue(!$save($p)['success'],'overpayment refused');
    $t->assertTrue($db->fetch('SELECT treatment_name FROM case_treatments WHERE id=?',[$row])['treatment_name'] !== 'must roll back','failed save rolls back row edits');
    $t->assertEqualsMoney($cashBefore,$balance(),'failed correction leaves cash unchanged');
    foreach (['-1','1e3','1.001','NaN'] as $bad) {
        $p=$payload(); $p['received_amount']=$bad;
        $t->assertTrue(!$save($p)['success'],'malformed money refused: '.$bad);
    }
    $p=$payload(); $p['work_date']='2026-02-30'; $t->assertTrue(!$save($p)['success'],'impossible date refused');
    $p=$payload(); $p['rows'][0]['tooth_number']='99'; $t->assertTrue(!$save($p)['success'],'invalid FDI refused');
    $p=$payload(); $p['reason']=''; $t->assertTrue(!$save($p)['success'],'correction requires a reason');

    // Real later receipts use Payment's canonical reallocation and cash hooks.
    $receipt = $pm->directPayment(['case_id'=>$case,'invoice_id'=>$invoice,'amount'=>10000,'payment_method'=>'cash','payment_date'=>'2026-10-03'],$admin);
    $t->assertTrue($receipt['success'],'later receipt fixture'); $pid=(int)$receipt['payment_id'];
    $t->assertTrue(!$pm->editApprovedFromInvoice($invoice,$pid,5000,$doctor)['success'],'dedicated payment API verifies actor');
    $p=$payload(); $p['payments']=[['id'=>$pid,'amount'=>'15000.25','payment_date'=>'2026-10-04','payment_method'=>'card']];
    $t->assertTrue($save($p)['success'],'receipt amount/date/method corrected with global setting off');
    $t->assertEqualsMoney(15000.25,$db->fetch('SELECT allocated_amount FROM payment_allocations WHERE payment_id=? AND invoice_id=?',[$pid,$invoice])['allocated_amount'],'receipt allocations follow correction');
    $t->assertEqualsMoney(40000.25,$balance(),'changing receipt to card removes exactly its old cash');
    $t->assertSame('2026-10-04',substr($db->fetch('SELECT payment_date FROM payments WHERE id=?',[$pid])['payment_date'],0,10),'receipt date corrected');
    $p=$payload(); $p['payments']=[['id'=>$pid,'amount'=>'12000.50','payment_date'=>'2026-10-04','payment_method'=>'cash']];
    $t->assertTrue($save($p)['success'],'receipt reduction and card to cash correction');
    $t->assertEqualsMoney(52000.75,$balance(),'cash increases by corrected cash receipt only');
    $journal();
    $p=$payload(); $p['payments']=[['id'=>$pid,'amount'=>'999999','payment_date'=>'2026-10-04','payment_method'=>'cash']];
    $p['notes']='rollback receipt';
    $t->assertTrue(!$save($p)['success'],'receipt growth above debt refused atomically');
    $t->assertTrue($inv()['notes'] !== 'rollback receipt','receipt failure also rolls back invoice');
    $t->assertEqualsMoney(52000.75,$balance(),'receipt failure rolls back cash');

    // Source dates crossing the cashbox cut-off reverse/reapply custody once.
    $p=$payload(); $p['work_date']='2026-09-30'; $t->assertTrue($save($p)['success'],'backdate before cash start');
    $t->assertEqualsMoney(12000.50,$balance(),'pre-October invoice cash removed, later receipt remains');
    $p=$payload(); $p['work_date']='2026-10-02'; $t->assertTrue($save($p)['success'],'restore invoice work day');
    $t->assertEqualsMoney(52000.75,$balance(),'cash restored once'); $journal();

    $other=fx_invoice($case,$doctor,'draft','draft',1000); $otherRow=fx_treatment($case,1000,$other);
    $p=$payload(); $p['rows'][0]['id']=$otherRow; $t->assertTrue(!$save($p)['success'],'cannot edit another invoice row');
    $p=$payload(); $p['invoice_no']=$db->fetch('SELECT invoice_no FROM case_invoices WHERE id=?',[$other])['invoice_no'];
    $t->assertTrue(!$save($p)['success'],'duplicate invoice number rejected');
    $p=$payload(); $p['rows'][0]['remove']=true;
    $p['rows'][]=['id'=>0,'treatment_name'=>'Replacement service','quantity'=>'3','unit_price'=>'50000.25','discount'=>'0.25','tooth_number'=>'','arch_type'=>'none','notes'=>'new'];
    $p['discount_mode']='amount'; $p['discount_value']='1000';
    $t->assertTrue($save($p)['success'],'remove and add treatment in one correction');
    $t->assertTrue(!$db->fetch('SELECT id FROM case_treatments WHERE id=?',[$row]),'removed row gone');
    $t->assertEqualsMoney(150000.50,$inv()['total_amount'],'replacement total exact');

    $sharedInvoice=fx_invoice($case,$doctor,'sent','sent',5000); fx_treatment($case,5000,$sharedInvoice);
    $db->update('case_invoices',['work_date'=>'2026-10-05'],'id=?',[$sharedInvoice]);
    $t->assertTrue($ci->approve($case,$sharedInvoice,$accountant)['success'],'second approved invoice');
    $shared=$pm->directPayment(['case_id'=>$case,'amount'=>1500,'allocations'=>[$invoice=>1000,$sharedInvoice=>500],'payment_method'=>'cash','payment_date'=>'2026-10-06'],$admin);
    $t->assertTrue($shared['success'],'shared receipt fixture'); $sharedId=(int)$shared['payment_id'];
    $p=$payload(); $p['payments']=[['id'=>$sharedId,'amount'=>'2000.25','payment_date'=>'2026-10-06','payment_method'=>'cash']];
    $t->assertTrue($save($p)['success'],'shared receipt grows through canonical allocation');
    $t->assertEqualsMoney(2000.25,$db->fetch('SELECT SUM(allocated_amount) amount FROM payment_allocations WHERE payment_id=?',[$sharedId])['amount'],'shared allocation sums to corrected receipt');
    $t->assertEqualsMoney(500,$db->fetch('SELECT allocated_amount FROM payment_allocations WHERE payment_id=? AND invoice_id=?',[$sharedId,$sharedInvoice])['allocated_amount'],'other invoice allocation retained on growth');
    $p=$payload(); $p['payments']=[['id'=>$sharedId,'amount'=>'100','payment_date'=>'2026-10-06','payment_method'=>'cash']];
    $t->assertTrue($save($p)['success'],'shared receipt shrinks through canonical allocation');
    $t->assertEqualsMoney(100,$db->fetch('SELECT SUM(allocated_amount) amount FROM payment_allocations WHERE payment_id=?',[$sharedId])['amount'],'all shared allocations shrink together');
    $t->assertEqualsMoney(52100.75,$balance(),'shared cash counted once per receipt, not per invoice'); $journal();
    $p=$payload(); $db->update('payments',['payment_date'=>'2026-10-07 10:00:00'],'id=?',[$sharedId]);
    $t->assertTrue(!$save($p)['success'],'receipt metadata change also invalidates stale editor');
    $db->update('payments',['payment_date'=>'2026-10-07 23:59:59'],'id=?',[$sharedId]);
    $p=$payload(); $p['payments']=[['id'=>$sharedId,'amount'=>'100','payment_date'=>date('Y-m-d'),'payment_method'=>'cash']];
    $t->assertTrue($save($p)['success'],'receipt can be moved to today');
    $t->assertTrue($db->fetch('SELECT payment_date FROM payments WHERE id=?',[$sharedId])['payment_date'] <= date('Y-m-d H:i:s'),'inherited clock time cannot move actual cash into the future');
    $t->assertEqualsMoney(52100.75,$balance(),'today correction keeps cash available immediately');
    $pending=$pm->createRequest(['case_id'=>$case,'invoice_id'=>$invoice,'amount'=>50000,'payment_method'=>'cash','received_by'=>$doctor,'payment_date'=>'2026-10-06']);
    $t->assertTrue($pending['success'],'pending request reserves balance');
    $p=$payload(); $p['received_amount']='80000.25';
    $t->assertTrue(!$save($p)['success'],'pending allocations count in overpayment cap');
    $db->update('payments',['status'=>'rejected'],'id=?',[(int)$pending['payment_id']]);
    $p=$payload(); $p['rows'][0]['quantity']=[];
    $t->assertTrue(!$save($p)['success'],'array instead of quantity rejected without warnings');
    $beforeRepeat=$db->count('cashbox_entries','account_key=?',['doctor:'.$doctor]);
    $t->assertTrue($save($payload())['success'],'unchanged correction accepted');
    $t->assertSame($beforeRepeat,$db->count('cashbox_entries','account_key=?',['doctor:'.$doctor]),'unchanged correction creates no duplicate cash movement');

    // Plans retain their agreed principal; nonfinancial corrections still work.
    $plan=(int)$db->insert('installment_plans',['case_id'=>$case,'invoice_id'=>$invoice,'total_amount'=>149000.50,'remaining_amount'=>81999.25,'number_of_installments'=>2,'installment_amount'=>41000,'start_date'=>date('Y-m-d'),'status'=>'active','created_by'=>$admin]);
    $p=$payload(); $p['notes']='plan note'; $t->assertTrue($save($p)['success'],'plan permits invoice notes');
    $p=$payload(); $p['received_amount']='41000'; $t->assertTrue(!$save($p)['success'],'plan principal protected');
    $db->delete('installment_plans','id=?',[$plan]);

    // An audit failure occurs after source + journal writes: everything rolls back.
    $p=$payload(); $p['received_amount']='45000.25';
    $beforeFailure=$inv(); $cashBefore=$balance();
    $db->query("CREATE TRIGGER test_invoice_correction_audit BEFORE INSERT ON activity_log FOR EACH ROW BEGIN IF NEW.action='invoice_admin_corrected' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'; END IF; END");
    try { $t->assertTrue(!$save($p)['success'],'audit failure aborts correction'); }
    finally { $db->query('DROP TRIGGER test_invoice_correction_audit'); }
    $t->assertSame($beforeFailure,$inv(),'audit failure rolls back invoice');
    $t->assertEqualsMoney($cashBefore,$balance(),'audit failure rolls back cash journal/cache'); $journal();

    $draft=$payload($other); $draft['received_amount']='250';
    $t->assertTrue($save($draft,$other)['success'],'draft correction supported');
    $t->assertEqualsMoney($cashBefore,$balance(),'unapproved invoice correction never books cash');
    $db->update('cases',['status'=>'closed'],'id=?',[$case]);
    $t->assertTrue(!$save($payload())['success'],'closed case correction refused');
    $db->update('cases',['status'=>'open'],'id=?',[$case]);
    $db->update('case_invoices',['accounting_status'=>'cancelled'],'id=?',[$other]);
    $t->assertTrue(!$editor->save($case,$other,$admin,$draft)['success'],'cancelled invoice refused');
    $_SESSION=$saved;
})();
