<?php // tests/cases/InlineDraftPaymentTest.php
// Doctor-entered "received" money stays only on the editable invoice draft.
// Explicit send keeps it inside the invoice bundle; invoice approval then
// makes that bundled figure count in the ledger without a payments row.
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/Installment.php';

$db = Database::getInstance();
$doctor = fx_user('doctor');
$accountant = fx_user('accountant');
$patient = fx_patient();
$folder = fx_folder($patient, $doctor);
$caseId = fx_case($folder);
$invoiceId = fx_invoice($caseId, $doctor, 'draft', 'draft', 100000);
fx_treatment($caseId, 100000, $invoiceId);

$payments = new Payment();
$invoices = new CaseInvoice();
$ledger = new AccountingLedger();

$draftSave = $invoices->setDraftReceived($caseId, $invoiceId, 40000, $doctor);
$t->assertTrue(!empty($draftSave['success']), 'doctor can save received money locally on a draft invoice');
$draft = $ledger->getInvoice($invoiceId);
$t->assertSame('draft', $draft['accounting_status'], 'declaring received money does not send or lock the invoice');
$t->assertEqualsMoney(0, $draft['paid_amount'], 'pending declaration is not approved money');
$t->assertEqualsMoney(0, $draft['pending_amount'], 'draft received figure does not enter the accountant payment queue');
$t->assertEqualsMoney(40000, $draft['draft_received_amount'], 'draft received figure stays on the invoice draft itself');
$draftPaymentCount = (int)$db->fetch('SELECT COUNT(*) AS c FROM payments WHERE case_id=:case_id', ['case_id' => $caseId])['c'];
$t->assertSame(0, $draftPaymentCount, 'saving the draft figure creates no payment row');

$changed = $invoices->setDraftReceived($caseId, $invoiceId, 55000, $doctor);
$t->assertTrue(!empty($changed['success']), 'doctor can change the inline pending amount');
$draftChanged = $ledger->getInvoice($invoiceId);
$t->assertEqualsMoney(55000, $draftChanged['draft_received_amount'], 'changed amount remains only on the same draft invoice');
$t->assertSame('draft', $draftChanged['accounting_status'], 'changing the amount still does not send the invoice');

$sent = $invoices->send($caseId, $invoiceId, $doctor, '', ['payment_intent' => 'cash']);
$t->assertTrue(!empty($sent['success']), 'the same invoice can be sent later');
$afterSend = $ledger->getInvoice($invoiceId);
$t->assertEqualsMoney(55000, $afterSend['draft_received_amount'], 'send keeps received money inside the treatment invoice');
$t->assertEqualsMoney(0, $afterSend['pending_amount'], 'sending a treatment invoice creates no payment queue amount');
$t->assertSame(0, (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id=?', [$caseId])['c'], 'sending the invoice creates no payment row');

$approved = $invoices->approve($caseId, $invoiceId, $accountant);
$t->assertTrue(!empty($approved['success']), 'accountant approves gross, discount and received as one invoice bundle');
$afterApproval = $ledger->getInvoice($invoiceId);
$t->assertSame('approved', $afterApproval['accounting_status'], 'accountant approval completes the explicitly sent invoice');
$t->assertEqualsMoney(55000, $afterApproval['paid_amount'], 'approved invoice received figure becomes ledger money');
$t->assertEqualsMoney(45000, $afterApproval['remaining_amount'], 'draft receipt shows the correct remaining amount after approval');

// The explicitly sent invoice keeps the approved partial amount connected.
$finalInvoice = $ledger->getInvoice($invoiceId);
$t->assertSame('approved', $finalInvoice['accounting_status'], 'invoice is accounting-approved');
$t->assertEqualsMoney(55000, $finalInvoice['paid_amount'], 'earlier received money remains connected after send');
$t->assertEqualsMoney(45000, $finalInvoice['remaining_amount'], 'partial cash remainder stays due');

// The combined send dialog applies its discount first, then attaches the
// doctor's cash/partial declaration to that exact receipt before sending it.
$caseDiscount = fx_case($folder);
$invoiceDiscount = fx_invoice($caseDiscount, $doctor, 'draft', 'draft', 200000);
fx_treatment($caseDiscount, 200000, $invoiceDiscount);
$discounted = $invoices->setDiscount($caseDiscount, $invoiceDiscount, 'percent', 10, $doctor);
$t->assertTrue(!empty($discounted['success']), 'doctor can add the dialog discount while the invoice is draft');
$partial = $invoices->setDraftReceived($caseDiscount, $invoiceDiscount, 50000, $doctor);
$t->assertTrue(!empty($partial['success']), 'dialog partial cash amount stays on the invoice bundle');
$sentDiscount = $invoices->send($caseDiscount, $invoiceDiscount, $doctor, '', ['payment_intent' => 'cash']);
$t->assertTrue(!empty($sentDiscount['success']), 'discounted partial-cash invoice sends to accounting');
$discountInvoice = $ledger->getInvoice($invoiceDiscount);
$t->assertEqualsMoney(20000, $discountInvoice['discount_amount'], 'ten-percent dialog discount is stored on the receipt');
$t->assertEqualsMoney(180000, $discountInvoice['net_amount'], 'accounting receives the discounted net amount');
$t->assertEqualsMoney(50000, $discountInvoice['draft_received_amount'], 'accounting receives the partial amount inside the invoice');
$t->assertEqualsMoney(0, $discountInvoice['pending_amount'], 'discounted invoice still creates no payment request');
$t->assertSame('sent', $discountInvoice['accounting_status'], 'combined dialog leaves the receipt awaiting accountant approval');

// Converting the pending received amount to an installment proposal makes it
// the proposed down payment and removes the old cash request, preventing a
// double booking when accounting activates the plan.
$case2 = fx_case($folder);
$invoice2 = fx_invoice($case2, $doctor, 'draft', 'draft', 120000);
fx_treatment($case2, 120000, $invoice2);
$saved2 = $invoices->setDraftReceived($case2, $invoice2, 30000, $doctor);
$t->assertTrue(!empty($saved2['success']), 'installment example stores its down payment inside the invoice');
$send2 = $invoices->send($case2, $invoice2, $doctor, '', ['payment_intent' => 'installment']);
$t->assertTrue(!empty($send2['success']), 'installment invoice sends successfully');
$planId = (new Installment())->proposePlan([
    'case_id' => $case2,
    'invoice_id' => $invoice2,
    'total_amount' => 120000,
    'down_payment' => 30000,
    'number_of_installments' => 3,
    'start_date' => date('Y-m-d'),
    'created_by' => $doctor,
], $doctor);
$plan = (new Installment())->getPlanById($planId);
$t->assertSame('pending', $plan['status'], 'doctor installment terms wait for accountant decision');
$t->assertSame($invoice2, (int)$plan['invoice_id'], 'installment proposal stays linked to the visit invoice');
$t->assertEqualsMoney(30000, $plan['proposed_down_payment'], 'received declaration becomes the proposed down payment');
$t->assertSame(3, (int)$plan['number_of_installments'], 'doctor-selected installment count is preserved');
$t->assertSame(0, (int)$db->fetch('SELECT COUNT(*) c FROM payments WHERE case_id=?', [$case2])['c'], 'installment invoice send creates no payment row');
