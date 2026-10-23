<?php
// Run run-invoice-received.php first, then open the generated HTML in Chrome.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixtures = file_get_contents(__DIR__.'/storage/invoice-received-fixtures.json');
$source = file_get_contents(__DIR__.'/../assets/js/approval-center.js');
// Expose the real renderer and event handlers, skipping polling/initial load.
$boot = strrpos($source, '    // Boot');
if ($boot === false) throw new RuntimeException('Client boot marker missing.');
$source = substr($source,0,$boot)."window.receivedTest = { invoicePaneHtml, wireInvoicePane, renderInvoiceDrawer, renderPaymentDrawer };\n})();";
$css = file_get_contents(__DIR__.'/../assets/css/invoice-pane.css');
preg_match_all('~<style>(.*?)</style>~s',file_get_contents(__DIR__.'/../accountant/approval-center.php'),$pageStyles);
$css .= implode("\n",$pageStyles[1]);
$harness = <<<'HTML'
<!doctype html><html lang="ku" dir="rtl"><meta charset="utf-8"><meta name="csrf-token" content="test">
<style>body{font-family:Tahoma,sans-serif;background:#f1f5f9;margin:24px}.vt-pane{background:white;padding:20px;max-width:1020px;margin:auto}#result{direction:ltr;white-space:pre-wrap}__CSS__</style>
<div id="acDrawerErr"></div><main id="fixture"></main><pre id="result">Running...</pre>
<script>window.AC_INIT={};window.capturedWrites=[];window.confirm=()=>{throw new Error('Unexpected approval dialog');};window.fetch=async function(url,options){if(options&&options.body){capturedWrites.push(Object.fromEntries(new URLSearchParams(options.body)));}return{json:async()=>({ok:false,error:'Captured test request'})};};</script>
<script>__SOURCE__</script>
<script>
(async function(){
    const data=__FIXTURES__;
    let checks=0;
    const assert=(ok,msg)=>{checks++;if(!ok)throw new Error(msg);};
    const el=(id)=>document.getElementById(id);
    const display=(invoice,editing,available=0)=>{
        el('fixture').innerHTML=receivedTest.invoicePaneHtml(invoice,[],editing?'editing':'locked',available);
        return receivedTest.wireInvoicePane(invoice,{},{},available);
    };
    const blur=async(value)=>{
        const input=el('acRecvInput');input.value=String(value);
        input.dispatchEvent(new Event('input'));input.dispatchEvent(new Event('blur'));
        await new Promise(resolve=>setTimeout(resolve,0));
    };
    try{
        for(const [name,expected,remaining] of [['custody','150,000','1,500,000'],['mixed','40,000','50,000']]){
            display(data[name],false);
            assert(el('acInvPaidBox').textContent===expected,name+': one received total');
            assert(el('acInvRemainingBox').textContent===remaining,name+': correct remaining');
            assert(!el('fixture').textContent.includes('قەبزی پارەدانی دواتر'),name+': no later receipt row');
            assert(document.querySelectorAll('.vt-received-source').length===1,name+': one transfer source');
        }
        display(data.custody,true);
        assert(el('acRecvInput').value==='150000','edit starts with transferred money included');
        await blur(150000);
        assert(capturedWrites.at(-1).received_amount==='0','unchanged total never saves custody as fresh cash');
        await blur(175000);
        assert(capturedWrites.at(-1).received_amount==='25000','only additional cash is saved');
        assert(el('acInvRemainingBox').textContent==='1,475,000','live remaining counts custody once');
        const before=capturedWrites.length;
        await blur(149000);
        assert(capturedWrites.length===before,'cannot overwrite existing custody through the total');
        await blur(2000000);
        assert(capturedWrites.length===before,'cannot overpay the invoice');
        display(data.mixed,true);
        assert(el('acRecvInput').value==='40000','historical money excluded from received');
        await blur(40000);
        assert(capturedWrites.at(-1).received_amount==='5000','existing invoice cash is preserved on unchanged save');
        await blur(45000);
        assert(capturedWrites.at(-1).received_amount==='10000','mixed sources are not duplicated');
        assert(el('acInvRemainingBox').textContent==='45,000','mixed sources preview agrees with balance');
        const mixedBefore=capturedWrites.length;await blur(34000);
        assert(capturedWrites.length===mixedBefore,'standalone receipts cannot be reduced as invoice cash');
        display(Object.assign({},data.custody,{accounting_status:'sent',paid_amount:0,draft_received_amount:20000,declared_received_amount:20000,advance_received_amount:0}),true);
        await blur(20000);
        assert(capturedWrites.at(-1).received_amount==='20000','sent invoice cash retains its existing save behavior');
        const sent=Object.assign({},data.custody,{id:10001,accounting_status:'sent',total_amount:550000,net_amount:550000,paid_amount:0,remaining_amount:550000,draft_received_amount:0,declared_received_amount:0,advance_received_amount:0});
        let controls=display(sent,false,150000);
        assert(!!el('acInvoiceCustodyUse').closest('.vt-foot-totals'),'custody control is beside the received total');
        const beforeSelect=capturedWrites.length;
        assert(el('acInvoiceCustodyAmount').textContent==='150,000','available custody is shown automatically without typing');
        el('acInvoiceCustodyUse').click();
        assert(el('acRecvInput').value==='150000','inline custody fills received');
        assert(el('acInvRemainingBox').textContent==='400,000','inline selection updates the remainder');
        assert(el('acInvHeadRemaining').textContent==='ماوە 400,000','header remainder agrees with the inline preview');
        assert(capturedWrites.length===beforeSelect,'selection does not prematurely book or approve money');
        let fields=await controls.approvalFields();
        assert(fields.advance_amount===150000 && fields.received_cash===0,'approval separates custody from new cash');
        el('acInvoiceCustodyUse').click();
        assert(el('acRecvInput').value==='150000','repeat selection cannot duplicate received money');
        await blur(175000);fields=await controls.approvalFields();
        assert(fields.advance_amount===150000 && fields.received_cash===25000,'additional fresh cash remains separate from custody');
        assert(capturedWrites.length===beforeSelect,'selected custody and cash are saved together only on approval');
        controls=display(sent,false,150000);
        assert(el('acRecvInput').value==='175000','discount/refetch render preserves the selected source and received amount');
        el('acInvoiceCustodyClear').click();fields=await controls.approvalFields();
        assert(fields.advance_amount===0 && fields.received_cash===0,'removing selection restores the original invoice cash');
        controls=display(Object.assign({},sent,{id:10007,total_amount:100000,net_amount:100000,remaining_amount:100000}),false,150000);
        assert(el('acInvoiceCustodyAmount').textContent==='100,000','automatic custody is capped at the invoice amount');
        el('acInvoiceCustodyUse').click();fields=await controls.approvalFields();
        assert(fields.advance_amount===100000 && fields.received_cash===0,'automatic selection leaves excess custody unspent');

        const doctorEntered=Object.assign({},sent,{id:10002,draft_received_amount:150000,declared_received_amount:150000});
        controls=display(doctorEntered,false,150000);el('acInvoiceCustodyUse').click();fields=await controls.approvalFields();
        assert(el('acRecvInput').value==='150000' && fields.received_cash===0,'doctor-entered custody is reclassified rather than counted twice');

        const shell=()=>{el('fixture').innerHTML='<div id="acDrawerTitle"></div><div id="acDrawerSub"></div><div id="acDrawerBody"></div>';};
        const drawerData=(invoice)=>({invoice,treatments:[],patient:{full_name:'Test patient'},summary:{},advance_available:150000,intent:{}});
        shell();receivedTest.renderInvoiceDrawer(drawerData(Object.assign({},sent,{id:10003})));
        el('acInvoiceCustodyUse').click();
        const beforeApprove=capturedWrites.length;el('acApproveBtn').click();await new Promise(resolve=>setTimeout(resolve,0));
        assert(capturedWrites.length===beforeApprove+1,'approve sends one request without a selection dialog');
        assert(capturedWrites.at(-1).action==='approve_invoice' && capturedWrites.at(-1).advance_amount==='150000' && capturedWrites.at(-1).received_cash==='0','approve submits the inline source selection atomically');
        shell();receivedTest.renderInvoiceDrawer(drawerData(Object.assign({},sent,{id:10004})));
        el('acApproveBtn').click();await new Promise(resolve=>setTimeout(resolve,0));
        assert(capturedWrites.at(-1).action==='approve_invoice' && capturedWrites.at(-1).advance_amount==='0','unselected custody is left alone without a popup');

        shell();receivedTest.renderPaymentDrawer({payment:{id:99,case_id:sent.case_id,status:'pending',amount:550000,allocations:[]},patient:{full_name:'Test patient'},summary:{},advance_available:150000});
        assert(el('acPaymentCustodyAmount').textContent==='150,000','payment custody amount is also automatic');
        el('acPaymentCustodyUse').click();
        assert(el('acAdjAmount').value==='150000','payment request also has inline custody selection');
        el('acApprovePayBtn').click();await new Promise(resolve=>setTimeout(resolve,0));
        assert(capturedWrites.at(-1).action==='approve_payment' && capturedWrites.at(-1).use_advance==='1','payment approval uses the selected custody without a popup');
        el('acPaymentCustodyClear').click();el('acApprovePayBtn').click();await new Promise(resolve=>setTimeout(resolve,0));
        assert(capturedWrites.at(-1).use_advance==='0','payment source can be restored to fresh cash explicitly');

        // A pending blur-save must finish before approval may change status.
        controls=display(Object.assign({},sent,{id:10005}),false,0);
        const realFetch=window.fetch;let release;
        window.fetch=()=>new Promise(resolve=>{release=()=>resolve({json:async()=>({ok:false,error:'Failed pending save'})});});
        el('acRecvInput').value='50000';el('acRecvInput').dispatchEvent(new Event('blur'));
        let finished=false;const pending=controls.approvalFields().then(value=>{finished=true;return value;});
        await new Promise(resolve=>setTimeout(resolve,0));assert(!finished,'approval waits for a pending cash save');
        release();assert(await pending===null,'failed pending save prevents approval');window.fetch=realFetch;
        shell();receivedTest.renderInvoiceDrawer(drawerData(Object.assign({},sent,{id:10006,invoice_no:'C20261000806-2'})));
        el('acInvoiceCustodyUse').click();el('acDrawerErr').textContent='';
        if(document.querySelector('#fixture #acDrawerErr'))document.querySelector('#fixture #acDrawerErr').textContent='';
        el('result').textContent=JSON.stringify({checks,failures:0},null,2);
        document.documentElement.dataset.testResult='passed';
    }catch(error){el('result').textContent=JSON.stringify({checks,error:error.message},null,2);document.documentElement.dataset.testResult='failed';}
})();
</script></html>
HTML;
$html = str_replace(['__CSS__','__SOURCE__','__FIXTURES__'],[$css,$source,$fixtures],$harness);
$path = __DIR__.'/storage/invoice-received-browser.html';
file_put_contents($path,$html);
echo $path,PHP_EOL;
