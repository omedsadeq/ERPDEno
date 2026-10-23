<?php // tests/cases/PaymentReceiptDraftTest.php
// Later money against old approved debt is a separate draft receipt. It does
// not touch the old invoice and reaches the payment queue only after Send.
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/ApprovalQueue.php';

$db = Database::getInstance();
$doctor = fx_user('doctor');
$accountant = fx_user('accountant');
$patient = fx_patient();
$folder = fx_folder($patient, $doctor);
$caseId = fx_case($folder);
$invoiceId = fx_invoice($caseId, $doctor, 'sent', 'approved', 100000);
fx_treatment($caseId, 100000, $invoiceId);

$payments = new Payment();
$ledger = new AccountingLedger();
$queue = new ApprovalQueue();
$beforeQueue = $queue->counts(true)['payments'];

$saved = $payments->saveDraft([
    'case_id' => $caseId,
    'amount' => 30000,
    'payment_method' => 'cash',
    'notes' => 'patient returned later',
], $doctor);
$t->assertTrue(!empty($saved['success']), 'doctor saves a later payment receipt as draft');
$draftId = (int)$saved['payment_id'];
$draft = $payments->getById($draftId);
$t->assertSame('draft', $draft['status'], 'new later-payment receipt is draft');
$t->assertSame(0, count($draft['allocations']), 'draft receipt has no debt allocations yet');
$t->assertSame($beforeQueue, $queue->counts(true)['payments'], 'draft receipt is absent from accountant payment queue');

$updated = $payments->saveDraft([
    'case_id' => $caseId,
    'amount' => 40000,
    'payment_method' => 'cash',
    'notes' => 'agreed amount corrected',
], $doctor, $draftId);
$t->assertTrue(!empty($updated['success']) && !empty($updated['updated']), 'doctor can update the same unsent receipt draft');
$t->assertSame($draftId, (int)$updated['payment_id'], 'draft update keeps one receipt row');
$t->assertEqualsMoney(40000, $payments->getById($draftId)['amount'], 'updated draft amount is stored');

$sent = $payments->sendDraft($draftId, $doctor);
$t->assertTrue(!empty($sent['success']), 'doctor explicitly sends the draft receipt');
$pending = $payments->getById($draftId);
$t->assertSame('pending', $pending['status'], 'sent receipt reaches pending payment state');
$t->assertSame(1, count($pending['allocations']), 'send allocates receipt against old approved debt');
$t->assertSame($invoiceId, (int)$pending['allocations'][0]['invoice_id'], 'allocation targets the old approved invoice');
$t->assertSame($beforeQueue + 1, $queue->counts(true)['payments'], 'only sent receipt appears in accountant payment queue');

$approved = $payments->approve($draftId, $accountant);
$t->assertTrue(!empty($approved['success']), 'accountant confirms the later payment receipt');
$after = $ledger->getInvoice($invoiceId);
$t->assertSame('approved', $after['accounting_status'], 'old debt invoice remains approved');
$t->assertEqualsMoney(100000, $after['total_amount'], 'old invoice gross is unchanged');
$t->assertEqualsMoney(0, $after['discount_amount'], 'old invoice discount is unchanged');
$t->assertEqualsMoney(0, $after['draft_received_amount'], 'old invoice bundled received figure is unchanged');
$t->assertEqualsMoney(40000, $after['paid_amount'], 'confirmed later receipt settles part of the old debt');
$t->assertEqualsMoney(60000, $after['remaining_amount'], 'old debt keeps the correct remaining balance');

