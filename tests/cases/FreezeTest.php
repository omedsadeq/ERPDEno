<?php // tests/cases/FreezeTest.php
// Task 13: accounting_reports is frozen — nothing in the codebase writes to
// it any more. The doctor-side flow (draft → treatment → send with intent,
// via CaseInvoice::send()) is the harness-reachable path that pins this;
// it already stopped writing accounting_reports in Task 12 (see cases/view.php's
// "No accounting_reports row any more (frozen table)" comments), so this
// test is a regression pin, not new behavior.
//
// api/notifications.php's complete_case handler — the OTHER former writer,
// whose insert this task deletes (api/notifications.php:435-441, plus the
// now-meaningless accounting_reports read/gate at :373-383, since nothing
// ever advances a report to 'processed' any more) — is an HTTP-only endpoint
// (session/$_POST/auth-driven, header()+exit calls) not reachable from this
// CLI harness. That edit is verified instead by `php -l` and the repo-wide
// `accounting_reports` grep in task-13-report.md.
require_once __DIR__ . '/../../classes/CaseInvoice.php';

$db = Database::getInstance();
$before = (int)$db->fetch("SELECT COUNT(*) c FROM accounting_reports")['c'];

$doc = fx_user('doctor');
$pat = fx_patient();
$fold = fx_folder($pat, $doc);
$case = fx_case($fold);

$ci = new CaseInvoice();
$inv = fx_invoice($case, $doc, 'draft', 'draft', 0);
fx_treatment($case, 75000, $inv);
$res = $ci->send($case, $inv, $doc, '', [
    'payment_intent'  => 'cash',
    'promised_date'   => null,
    'promised_amount' => null,
]);
$t->assertTrue(!empty($res['success']), 'draft -> treatment -> send with intent succeeds');
$row = $db->fetch('SELECT accounting_status, payment_intent FROM case_invoices WHERE id = ?', [$inv]);
$t->assertSame('sent', $row['accounting_status'], 'invoice sent');
$t->assertSame('cash', $row['payment_intent'], 'intent persisted at send');

$after = (int)$db->fetch("SELECT COUNT(*) c FROM accounting_reports")['c'];
$t->assertSame($before, $after, 'accounting_reports frozen across draft -> treatment -> send with intent');
