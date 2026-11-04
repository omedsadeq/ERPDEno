<?php // tests/cases/IncomeColumnTest.php
// کۆڵۆمی تایبەت: a column of the accountant's own on the income sheet. A charge
// on a doctor comes off his income by his percentage or wholly from his share and
// moves his part to the centre. Money given back to a patient is a voucher on a
// patient and a case: it leaves the boxes, comes off the doctor's income on the
// day it went, shows in the patient's file as two equal lines (his debt does not
// move), can never exceed what he paid, and is cancelled, never erased.
require_once __DIR__ . '/../../classes/CashboxLedger.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/CaseDeletion.php';
require_once __DIR__ . '/../../classes/IncomeSettlement.php';
require_once __DIR__ . '/../../classes/IncomeColumn.php';
require_once __DIR__ . '/../../classes/PatientLedger.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';

(function () use ($t) {
    $savedSession = $_SESSION;
    $savedSplit = CashboxLedger::splitEnabled();
    $db = fx_db();
    $ledger = new CashboxLedger();
    $svc = new IncomeSettlement();
    $columns = new IncomeColumn();
    $admin = fx_user('admin');
    $_SESSION = ['user_id' => $admin, 'role' => 'admin', 'logged_in' => true];
    $C = CashboxLedger::CENTER;
    $bal = fn($k) => CashboxLedger::cents($db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key = ?', [$k])['balance'] ?? '0.00');
    $journal = fn($k) => CashboxLedger::cents($db->fetch('SELECT COALESCE(SUM(amount), 0) s FROM cashbox_entries WHERE account_key = ?', [$k])['s']);
    $today = date('Y-m-d');
    $refused = function (callable $fn): string {
        try {
            $fn();
        } catch (InvalidArgumentException | DomainException $e) {
            return $e->getMessage();
        }
        return '';
    };

    // A doctor at 40% with a patient who paid 100,000 of a 100,000 case today.
    $paidCase = function () use ($db, $admin, $ledger, $today) {
        $doc = fx_user('doctor');
        $db->update('users', ['commission_rate' => 40], 'id = ?', [$doc]);
        $patient = fx_patient();
        $case = fx_case(fx_folder($patient, $doc));
        $inv = fx_invoice($case, $doc, 'sent', 'sent', 100000);
        fx_treatment($case, 100000, $inv);
        $db->update('case_invoices', ['work_date' => $today, 'draft_received_amount' => 100000, 'payment_intent' => 'cash'], 'id = ?', [$inv]);
        (new CaseInvoice())->approve($case, $inv, $admin);
        $ledger->reconcile('doctor:' . $doc);
        return ['doc' => $doc, 'patient' => $patient, 'case' => $case];
    };
    $voucher = fn(int $col, array $p, $amount, string $note = 'چارەسەر تەواو نەکرا', ?string $day = null) => $columns->entryFromInput([
        'column_id' => $col, 'doctor_id' => $p['doc'], 'patient_id' => $p['patient'], 'case_id' => $p['case'],
        'entry_date' => $day ?? $today, 'amount' => $amount, 'note' => $note,
    ]);
    $charge = fn(int $col, int $doc, $amount) => $columns->entryFromInput(
        ['column_id' => $col, 'doctor_id' => $doc, 'entry_date' => $today, 'amount' => $amount, 'note' => '']
    );

    $refund = $columns->saveColumn(0, ['name' => 'گەڕاندنەوەی تێست', 'cash_out' => 1, 'on_doctor' => 0], $admin);
    $refundOnDoctor = $columns->saveColumn(0, ['name' => 'گەڕاندنەوە لەسەر دکتۆر', 'cash_out' => 1, 'on_doctor' => 1], $admin);
    $cost = $columns->saveColumn(0, ['name' => 'خەرجی تێست', 'cash_out' => 0, 'on_doctor' => 0], $admin);
    $fine = $columns->saveColumn(0, ['name' => 'سزای تێست', 'cash_out' => 0, 'on_doctor' => 1], $admin);

    // ── Split on: a voucher ───────────────────────────────────────────────
    Settings::set(CashboxLedger::SPLIT_SETTING, '1', 'boolean');

    $p = $paidCase();
    $key = 'doctor:' . $p['doc'];
    $t->assertSame(4000000, $bal($key), 'his 40% of the 100,000 is in his box');
    $center = $bal($C);
    $file = fn() => (new PatientLedger())->statement($p['patient']);
    $before = $file();
    $owedBefore = (new AccountingLedger())->getSummary($p['case'])['balance'];

    $id = $columns->createEntry($voucher($refund, $p, '20,000'), $admin);
    $row = $db->fetch('SELECT * FROM income_column_entries WHERE id = ?', [$id]);
    $t->assertTrue(preg_match('/^RF-\d{6}-\d{4,}$/', (string)$row['voucher_no']) === 1, 'it has a voucher number');
    $t->assertSame($p['patient'], (int)$row['patient_id'], 'on the patient');
    $t->assertTrue($row['patient_name'] !== '' && $row['patient_name'] !== null, 'the name is kept for the paper');
    $t->assertSame(4000000 - 800000, $bal($key), 'his 40% of the 20,000 left his box');
    $t->assertSame($center - 1200000, $bal($C), 'the centre\'s 60% left قاسەی لێزان');

    $ds = ds_compute_range($db, $p['doc'], $today, $today, false);
    $t->assertEqualsMoney(100000, $ds['cashIn'], 'what came in is unchanged');
    $t->assertEqualsMoney(80000, $ds['netAfterExpenses'], 'net = cash − the refund');
    $t->assertEqualsMoney(32000, $ds['doctorShare'], 'doctor 40% of 80,000');
    $t->assertEqualsMoney(48000, $ds['centerShare'], 'centre 60% of 80,000');
    $t->assertEqualsMoney(20000, $ds['columnCashOut'], 'all of it left as cash');
    $money = array_column(ds_row_lists($ds)['money'], 1, 0);
    $t->assertSame('(20,000)', $money['گەڕاندنەوەی تێست'] ?? null, 'the daily summary lists it under its column');

    // The patient's file: two equal lines, what he owes does not move.
    $after = $file();
    $lines = array_values(array_filter($after['rows'], fn($r) => in_array($r['doc_type'], ['INCOME_REFUND', 'REFUND_PRICE'], true)));
    $t->assertSame(2, count($lines), 'two lines in the patient\'s file');
    $t->assertEqualsMoney(20000, $lines[0]['debit'], 'the money he got back');
    $t->assertEqualsMoney(20000, $lines[1]['credit'], 'the price down by as much');
    $t->assertSame($row['voucher_no'], $lines[0]['doc_number'], 'both carry the voucher number');
    $t->assertEqualsMoney($before['closing'], $after['closing'], 'the running balance does not move');
    $t->assertEqualsMoney($owedBefore, (new AccountingLedger())->getSummary($p['case'])['balance'], 'the case balance does not move');
    $t->assertEqualsMoney(20000, IncomeColumn::refundedToPatient($db, $p['patient']), 'the file knows what went back');
    $t->assertEqualsMoney(20000, IncomeColumn::refundedBetween($db, $today, $today), 'the financial summary reads it');

    // Never more than he paid, the earlier voucher counted.
    $over = $refused(fn() => $columns->createEntry($voucher($refund, $p, 80001), $admin));
    $t->assertTrue(strpos($over, '80,000') !== false, 'more than he paid is refused and says how much is left');
    $t->assertSame(4000000 - 800000, $bal($key), 'and moves no money');
    $cases = $columns->refundableCases($p['patient']);
    $t->assertEqualsMoney(80000, $cases[0]['room'] ?? 0, 'the dialog offers what is left');
    $t->assertSame($p['doc'], $cases[0]['doctor_id'] ?? 0, 'and the case\'s doctor');

    // A voucher is not edited.
    $t->assertTrue($refused(fn() => $columns->updateEntry($id, $voucher($refund, $p, 30000), $admin)) !== '', 'a voucher cannot be edited');

    // A case with a live voucher cannot be deleted away from under it.
    $deleted = (new CaseDeletion())->delete($p['case'], $p['patient'], 'سڕینەوەی تێست');
    $t->assertTrue(empty($deleted['success']), 'a case with a live voucher is not deleted');
    $t->assertTrue(strpos((string)($deleted['message'] ?? ''), 'پسوولە') !== false, 'and says why');

    // Cancelling: the money comes back, the document and the why stay.
    $t->assertTrue($refused(fn() => $columns->cancelEntry($id, '', $admin)) !== '', 'cancelling needs a reason');
    $columns->cancelEntry($id, 'بە هەڵە تۆمارکرا', $admin);
    $t->assertSame(4000000, $bal($key), 'cancelled: his box is whole again');
    $t->assertSame($center, $bal($C), 'cancelled: the centre is whole again');
    $t->assertSame($journal($key), $bal($key), 'his balance is still the sum of his journal');
    $kept = $db->fetch('SELECT * FROM income_column_entries WHERE id = ?', [$id]);
    $t->assertSame('cancelled', $kept['status'], 'the voucher stays, cancelled');
    $t->assertSame('بە هەڵە تۆمارکرا', $kept['cancel_reason'], 'with why');
    $t->assertEqualsMoney(100000, ds_compute_range($db, $p['doc'], $today, $today, false)['netAfterExpenses'], 'off the figures');
    $t->assertTrue($refused(fn() => $columns->cancelEntry($id, 'دووبارە', $admin)) !== '', 'it cannot be cancelled twice');
    $notes = array_values(array_filter($file()['rows'], fn($r) => $r['doc_type'] === 'REFUND_CANCELLED'));
    $t->assertSame(1, count($notes), 'the patient\'s file keeps it as a note');
    $t->assertEqualsMoney(0, $notes[0]['debit'] + $notes[0]['credit'], 'a note moves nothing');
    $t->assertEqualsMoney(0, IncomeColumn::refundedToPatient($db, $p['patient']), 'nothing counts as given back');
    $t->assertEqualsMoney(100000, $columns->refundableCases($p['patient'])[0]['room'] ?? 0, 'the whole amount can go back again');

    // A voucher needs a patient, a case of his, and a reason.
    $other = $paidCase();
    $t->assertTrue($refused(fn() => $columns->entryFromInput(
        ['column_id' => $refund, 'doctor_id' => $p['doc'], 'entry_date' => $today, 'amount' => 1000, 'note' => 'هۆکار']
    )) !== '', 'no patient, no voucher');
    $t->assertTrue($refused(fn() => $voucher($refund, ['doc' => $p['doc'], 'patient' => $p['patient'], 'case' => $other['case']], 1000)) !== '', 'the case must be his');
    $t->assertTrue($refused(fn() => $voucher($refund, $p, 1000, '')) !== '', 'cash leaving needs a reason');

    // Wholly on the doctor: the centre loses nothing.
    $p = $paidCase();
    $key = 'doctor:' . $p['doc'];
    $center = $bal($C);
    $columns->createEntry($voucher($refundOnDoctor, $p, 10000), $admin);
    $t->assertSame(4000000 - 1000000, $bal($key), 'all 10,000 left his box');
    $t->assertSame($center, $bal($C), 'قاسەی لێزان is untouched');
    $ds = ds_compute_range($db, $p['doc'], $today, $today, false);
    $t->assertEqualsMoney(90000, $ds['netAfterExpenses'], 'net = cash − the refund');
    $t->assertEqualsMoney(30000, $ds['doctorShare'], 'doctor 40% of 100,000, less all 10,000');
    $t->assertEqualsMoney(60000, $ds['centerShare'], 'the centre keeps its whole 60%');

    // ── Split on: charges ─────────────────────────────────────────────────
    $p = $paidCase();
    $key = 'doctor:' . $p['doc'];
    $center = $bal($C);
    $id = $columns->createEntry($charge($cost, $p['doc'], 10000), $admin);
    $t->assertSame(4000000 - 400000, $bal($key), 'his 40% left his box');
    $t->assertSame($center + 400000, $bal($C), 'and reached قاسەی لێزان');
    $t->assertEqualsMoney(36000, ds_compute_range($db, $p['doc'], $today, $today, false)['doctorShare'], 'doctor 40% of 90,000');
    $columns->updateEntry($id, $charge($cost, $p['doc'], 20000), $admin);
    $t->assertSame(4000000 - 800000, $bal($key), 'a charge can be corrected: only the difference moves');

    $p = $paidCase();
    $key = 'doctor:' . $p['doc'];
    $center = $bal($C);
    $columns->createEntry($charge($fine, $p['doc'], 10000), $admin);
    $t->assertSame(4000000 - 1000000, $bal($key), 'all of it left his box');
    $t->assertSame($center + 1000000, $bal($C), 'and reached قاسەی لێزان');
    $t->assertEqualsMoney(30000, ds_compute_range($db, $p['doc'], $today, $today, false)['doctorShare'], 'all of it off his share');

    // Whatever the column, with the split on his box is exactly his share.
    $pv = $svc->preview($p['doc'], $today, $today);
    $t->assertSame($pv['shares']['doctor'], $pv['box'], 'his box holds what the settlement pays him');
    $t->assertSame(0, $pv['shares']['center_due'], 'nothing left to transfer');

    // A charge put on another doctor: the first is made whole, the second carries it.
    $first = $paidCase();
    $second = $paidCase();
    $id = $columns->createEntry($charge($cost, $first['doc'], 10000), $admin);
    $columns->updateEntry($id, $charge($cost, $second['doc'], 10000), $admin);
    $t->assertSame(4000000, $bal('doctor:' . $first['doc']), 'the first doctor has it back');
    $t->assertSame(4000000 - 400000, $bal('doctor:' . $second['doc']), 'the second doctor carries his part');

    // ── A settled day is closed ───────────────────────────────────────────
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $p = $paidCase();
    $settled = $svc->create($p['doc'], $yesterday, $today, '0', '0', '', $admin);
    $closed = $refused(fn() => $columns->createEntry($voucher($refund, $p, 5000), $admin));
    $t->assertTrue(strpos($closed, $settled['settlement_no']) !== false, 'a settled day refuses an entry and names the settlement');
    $t->assertSame(4000000, $bal('doctor:' . $p['doc']), 'and no money moved');

    // Rules of a column.
    $p = $paidCase();
    $columns->createEntry($voucher($refund, $p, 1000), $admin);
    $t->assertTrue($refused(fn() => $columns->saveColumn($refund, ['name' => 'ناوی نوێ', 'cash_out' => 0, 'on_doctor' => 0], $admin)) !== '', 'a column with entries keeps what it does');
    $columns->saveColumn($refund, ['name' => 'ناوی نوێ', 'cash_out' => 1, 'on_doctor' => 0], $admin);
    $t->assertSame('ناوی نوێ', $db->fetch('SELECT name FROM income_columns WHERE id = ?', [$refund])['name'], 'its name can change');

    $columns->removeColumn($refund, $admin);
    $t->assertSame(0, (int)$db->fetch('SELECT is_active FROM income_columns WHERE id = ?', [$refund])['is_active'], 'with entries it only leaves the sheet');
    $t->assertTrue(
        in_array($refund, array_map('intval', array_column($columns->shownColumns($today, $today), 'id')), true),
        'still shown in a period where it has money'
    );
    $empty = $columns->saveColumn(0, ['name' => 'بەتاڵ', 'cash_out' => 0, 'on_doctor' => 0], $admin);
    $columns->removeColumn($empty, $admin);
    $t->assertTrue(!$db->fetch('SELECT id FROM income_columns WHERE id = ?', [$empty]), 'with none it is gone');

    // ── Split off: everything is in his box until the settlement ──────────
    Settings::set(CashboxLedger::SPLIT_SETTING, '0', 'boolean');
    $money2 = $columns->saveColumn(0, ['name' => 'گەڕاندنەوەی دووەم', 'cash_out' => 1, 'on_doctor' => 0], $admin);
    $cost2 = $columns->saveColumn(0, ['name' => 'خەرجی دووەم', 'cash_out' => 0, 'on_doctor' => 0], $admin);

    $p = $paidCase();
    $key = 'doctor:' . $p['doc'];
    $t->assertSame(10000000, $bal($key), 'split off: the whole 100,000 is in his box');
    $center = $bal($C);
    $columns->createEntry($voucher($money2, $p, 20000), $admin);
    $t->assertSame(8000000, $bal($key), 'the cash left his box whole');
    $t->assertSame($center, $bal($C), 'قاسەی لێزان waits for the settlement');
    $pv = $svc->preview($p['doc'], $today, $today);
    $t->assertSame(3200000, $pv['shares']['doctor'], 'doctor 40% of 80,000');
    $t->assertSame(4800000, $pv['shares']['center_due'], 'the centre is owed what is left, not the cash that went');
    $t->assertSame($pv['box'], $pv['defaults']['transfer'] + $pv['defaults']['payout'], 'the settlement empties the box exactly');

    $p = $paidCase();
    $key = 'doctor:' . $p['doc'];
    $columns->createEntry($charge($cost2, $p['doc'], 20000), $admin);
    $t->assertSame(10000000, $bal($key), 'a charge moves nothing yet');
    $pv = $svc->preview($p['doc'], $today, $today);
    $t->assertSame(3200000, $pv['shares']['doctor'], 'doctor 40% of 80,000');
    $t->assertSame(6800000, $pv['shares']['center_due'], 'the charge comes to the centre in the settlement');
    $t->assertSame($pv['box'], $pv['defaults']['transfer'] + $pv['defaults']['payout'], 'the settlement empties the box exactly');

    Settings::set(CashboxLedger::SPLIT_SETTING, $savedSplit ? '1' : '0', 'boolean');
    $_SESSION = $savedSession;
})();
