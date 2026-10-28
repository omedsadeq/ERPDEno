<?php // tests/cases/AdvanceReceiptDateTest.php
// «ڕۆژی ئامانەت» (Payment::redateAdvanceReceipt): a receipt paid from ئامانەت
// is dated, by the admin, on the day the patient paid — or back on the day it
// was moved onto the case. Only the receipt's date moves: the cashbox journal
// is not written, and the rules of «تەعدیل» hold (the switch, the admin, the
// settled periods).
require_once __DIR__ . '/../../classes/PatientAdvance.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Settings.php';

(function () use ($t) {
    $db = fx_db(); $pm = new Payment(); $advances = new PatientAdvance();
    $admin = fx_user('admin'); $accountant = fx_user('accountant'); $doctor = fx_user('doctor');
    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');

    $patient = fx_patient(); $case = fx_case(fx_folder($patient, $doctor));
    $invoice = fx_invoice($case, $doctor, 'sent', 'approved', 100000); fx_treatment($case, 100000, $invoice);
    (new CaseManager())->updateCaseTotals($case);

    // 100,000 taken at the counter on 25/10 16:55, moved onto the invoice later
    $got = $advances->receive($patient, 100000, 'cash', null, $accountant, $doctor);
    $t->assertTrue(!empty($got['success']), 'advance: counter receipt taken');
    $advanceId = (int)($got['advance_id'] ?? $got['id'] ?? 0);
    $db->update('patient_advances', ['received_at' => '2026-10-25 16:55:00'], 'id = ?', [$advanceId]);
    $moved = $advances->applyToInvoice($invoice, 100000, $accountant);
    $t->assertTrue(!empty($moved['success']), 'advance: moved onto the invoice');
    $pid = (int)$moved['payment_id'];
    $movedAt = $db->fetch('SELECT payment_date FROM payments WHERE id = ?', [$pid])['payment_date'];
    $date = fn() => $db->fetch('SELECT payment_date FROM payments WHERE id = ?', [$pid])['payment_date'];
    $entries = fn() => (int)$db->fetch('SELECT COUNT(*) c FROM cashbox_entries')['c'];

    $days = $pm->advanceDaysOf($pid);
    $t->assertSame('2026-10-25 16:55:00', $days['paid_at'] ?? null, 'days: the day the patient paid');
    $t->assertSame($movedAt, $days['moved_at'] ?? null, 'days: the day it was moved is the receipt\'s own');

    // who may
    $t->assertTrue(empty($pm->redateAdvanceReceipt($pid, 'paid', $accountant)['success']), 'access: an accountant is refused');
    $t->assertTrue(empty($pm->redateAdvanceReceipt($pid, 'paid', $doctor)['success']), 'access: a doctor is refused');
    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
    $t->assertTrue(empty($pm->redateAdvanceReceipt($pid, 'paid', $admin)['success']), 'access: switch OFF refused');
    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');
    $t->assertTrue(empty($pm->redateAdvanceReceipt($pid, 'tomorrow', $admin)['success']), 'input: an unknown target refused');
    $t->assertSame($movedAt, $date(), 'refused calls changed nothing');

    // a settled period on the day the patient paid holds it
    $sid = (int)$db->insert('income_settlements', [
        'settlement_no' => 'T-' . uniqid(), 'doctor_id' => $doctor, 'period_from' => '2026-10-24', 'period_to' => '2026-10-25',
        'doctor_rate' => 0, 'status' => 'done', 'created_by' => $admin, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    $t->assertTrue(empty($pm->redateAdvanceReceipt($pid, 'paid', $admin)['success']), 'settled: cannot enter a settled period');
    $db->delete('income_settlements', 'id = ?', [$sid]);

    // the day the patient paid — the cashbox is not written
    $before = $entries();
    $r = $pm->redateAdvanceReceipt($pid, 'paid', $admin);
    $t->assertTrue(!empty($r['success']), 'paid: dated on the day the patient paid: ' . ($r['message'] ?? ''));
    $t->assertSame('2026-10-25 16:55:00', $date(), 'paid: the receipt carries 25/10 16:55');
    $t->assertSame($before, $entries(), 'paid: no cashbox entry is written');
    $t->assertTrue((bool)$db->fetch("SELECT id FROM activity_log WHERE action = 'payment_advance_redated' AND record_id = ?", [$pid]), 'paid: the change is logged');
    $t->assertTrue(!empty($pm->redateAdvanceReceipt($pid, 'paid', $admin)['unchanged']), 'paid: again is no change');

    // and back
    $r = $pm->redateAdvanceReceipt($pid, 'moved', $admin);
    $t->assertTrue(!empty($r['success']), 'moved: back on the day it was moved');
    $t->assertSame($movedAt, $date(), 'moved: the receipt carries its first date again');

    // a receipt not paid from ئامانەت
    $cash = $pm->directPayment(['case_id' => $case, 'amount' => 1000, 'payment_method' => 'cash', 'payment_date' => '2026-10-26 09:00:00'], $admin);
    if (!empty($cash['success'])) {
        $t->assertSame(null, $pm->advanceDaysOf((int)$cash['payment_id']), 'cash: no ئامانەت days');
        $t->assertTrue(empty($pm->redateAdvanceReceipt((int)$cash['payment_id'], 'paid', $admin)['success']), 'cash: refused');
    }
})();
