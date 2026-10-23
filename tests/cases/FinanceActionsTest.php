<?php // tests/cases/FinanceActionsTest.php
// Task 11: api/finance-actions.php + api/approval-center.php are thin HTTP
// dispatch with no logic of their own, so what's testable at the service
// level is the two behaviors this task adds/pins on CaseInvoice::returnForCorrection:
//   (1) an empty reason must throw (guard added this task — matches the
//       Payment::updatePendingAmount / Installment::rejectPlan convention).
//   (2) returning a sent invoice auto-rejects any pending payment request
//       allocated to it (pre-existing behavior at CaseInvoice.php:358-370 —
//       regression-pinned here so Task 11 doesn't silently break it).
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Payment.php';

$db = Database::getInstance();

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

$ci = new CaseInvoice($db); // extra ctor arg is harmless — CaseInvoice takes none

// ---- (1) reason guard: empty reason throws, invoice stays untouched ----
$inv1 = fx_invoice($case, $doc, 'sent', 'sent', 50000);
fx_treatment($case, 50000, $inv1);

$t->assertThrows(fn() => $ci->returnForCorrection($case, $inv1, $acct, ''), 'return reason required');
$still = $db->fetch("SELECT accounting_status FROM case_invoices WHERE id = ?", [$inv1]);
$t->assertSame('sent', $still['accounting_status'], 'invoice unchanged after a rejected empty-reason return');

// Whitespace-only counts as empty too.
$t->assertThrows(fn() => $ci->returnForCorrection($case, $inv1, $acct, '   '), 'whitespace-only reason also rejected');

// A real reason still works on the same invoice.
$returned1 = $ci->returnForCorrection($case, $inv1, $acct, 'کێشەی ژمارە هەیە');
$t->assertTrue(!empty($returned1['success']), 'return succeeds once a real reason is given');

// ---- (2) regression pin: return auto-rejects a pending allocated payment ----
$inv2 = fx_invoice($case, $doc, 'sent', 'sent', 40000);
fx_treatment($case, 40000, $inv2);

$payment = new Payment();
$req = $payment->createRequest([
    'case_id' => $case, 'amount' => 25000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
    'allocations' => [$inv2 => 25000],
]);
$t->assertTrue(!empty($req['success']), 'fixture: pending payment request allocated to inv2');
$reqId = (int)$req['payment_id'];
$pendingBefore = $db->fetch("SELECT status FROM payments WHERE id = ?", [$reqId]);
$t->assertSame('pending', $pendingBefore['status'], 'fixture: request starts pending');

$returned2 = $ci->returnForCorrection($case, $inv2, $acct, 'پێویستە دووبارە بپشکنرێت');
$t->assertTrue(!empty($returned2['success']), 'return succeeds with a real reason');

$invRow = $db->fetch("SELECT accounting_status FROM case_invoices WHERE id = ?", [$inv2]);
$t->assertSame('returned', $invRow['accounting_status'], 'invoice flips to returned');

$reqRow = $db->fetch("SELECT status FROM payments WHERE id = ?", [$reqId]);
$t->assertSame('rejected', $reqRow['status'], 'pending payment allocated to the returned invoice is auto-rejected');
