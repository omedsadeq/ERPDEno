<?php // tests/cases/IncomeColumnTest.php
// کۆڵۆمی تایبەت: a column of the accountant's own on the income sheet. An amount
// on a doctor comes off his income: by his percentage or wholly from his share,
// out of the boxes when it is cash given back, across to the centre when it is a
// charge. The sheet's figures, the settlement and the boxes must agree, with the
// split on and off, and a day already settled stays closed.
require_once __DIR__ . '/../../classes/CashboxLedger.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/IncomeSettlement.php';
require_once __DIR__ . '/../../classes/IncomeColumn.php';

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

    // A doctor at 40% who took 100,000 today.
    $doctorWithCash = function () use ($db, $admin, $ledger, $today) {
        $doc = fx_user('doctor');
        $db->update('users', ['commission_rate' => 40], 'id = ?', [$doc]);
        $case = fx_case(fx_folder(fx_patient(), $doc));
        $inv = fx_invoice($case, $doc, 'sent', 'sent', 100000);
        fx_treatment($case, 100000, $inv);
        $db->update('case_invoices', ['work_date' => $today, 'draft_received_amount' => 100000, 'payment_intent' => 'cash'], 'id = ?', [$inv]);
        (new CaseInvoice())->approve($case, $inv, $admin);
        $ledger->reconcile('doctor:' . $doc);
        return $doc;
    };
    $entry = fn(int $col, int $doc, $amount, string $note = 'نەخۆش — گەڕاندنەوە', ?string $day = null) => $columns->entryFromInput(
        ['column_id' => $col, 'doctor_id' => $doc, 'entry_date' => $day ?? $today, 'amount' => $amount, 'note' => $note]
    );

    $refund = $columns->saveColumn(0, ['name' => 'گەڕاندنەوەی تێست', 'cash_out' => 1, 'on_doctor' => 0], $admin);
    $refundOnDoctor = $columns->saveColumn(0, ['name' => 'گەڕاندنەوە لەسەر دکتۆر', 'cash_out' => 1, 'on_doctor' => 1], $admin);
    $charge = $columns->saveColumn(0, ['name' => 'خەرجی تێست', 'cash_out' => 0, 'on_doctor' => 0], $admin);
    $fine = $columns->saveColumn(0, ['name' => 'سزای تێست', 'cash_out' => 0, 'on_doctor' => 1], $admin);

    // ── Split on ──────────────────────────────────────────────────────────
    Settings::set(CashboxLedger::SPLIT_SETTING, '1', 'boolean');

    // Cash back, by percentage: both boxes give up their own part.
    $doc = $doctorWithCash();
    $key = 'doctor:' . $doc;
    $t->assertSame(4000000, $bal($key), 'his 40% of the 100,000 is in his box');
    $center = $bal($C);
    $id = $columns->createEntry($entry($refund, $doc, '20,000'), $admin);
    $t->assertSame(4000000 - 800000, $bal($key), 'his 40% of the 20,000 left his box');
    $t->assertSame($center - 1200000, $bal($C), 'the centre\'s 60% left قاسەی لێزان');
    $ds = ds_compute_range($db, $doc, $today, $today, false);
    $t->assertEqualsMoney(100000, $ds['cashIn'], 'what came in is unchanged');
    $t->assertEqualsMoney(80000, $ds['netAfterExpenses'], 'net = cash − the refund');
    $t->assertEqualsMoney(32000, $ds['doctorShare'], 'doctor 40% of 80,000');
    $t->assertEqualsMoney(48000, $ds['centerShare'], 'centre 60% of 80,000');
    $t->assertEqualsMoney(20000, $ds['columnCashOut'], 'all of it left as cash');
    $money = array_column(ds_row_lists($ds)['money'], 1, 0);
    $t->assertSame('(20,000)', $money['گەڕاندنەوەی تێست'] ?? null, 'the daily summary lists it under its column');

    // Correcting the amount moves only the difference, and deleting gives it back.
    $columns->updateEntry($id, $entry($refund, $doc, '30000'), $admin);
    $t->assertSame(4000000 - 1200000, $bal($key), 'his box follows the new amount');
    $t->assertSame($center - 1800000, $bal($C), 'the centre follows the new amount');
    $columns->deleteEntry($id, $admin);
    $t->assertSame(4000000, $bal($key), 'deleted: his box is whole again');
    $t->assertSame($center, $bal($C), 'deleted: the centre is whole again');
    $t->assertSame($journal($key), $bal($key), 'his balance is still the sum of his journal');
    $t->assertEqualsMoney(100000, ds_compute_range($db, $doc, $today, $today, false)['netAfterExpenses'], 'deleted: off the figures too');

    // Cash back, wholly on the doctor: the centre loses nothing.
    $doc = $doctorWithCash();
    $key = 'doctor:' . $doc;
    $center = $bal($C);
    $columns->createEntry($entry($refundOnDoctor, $doc, 10000), $admin);
    $t->assertSame(4000000 - 1000000, $bal($key), 'all 10,000 left his box');
    $t->assertSame($center, $bal($C), 'قاسەی لێزان is untouched');
    $ds = ds_compute_range($db, $doc, $today, $today, false);
    $t->assertEqualsMoney(90000, $ds['netAfterExpenses'], 'net = cash − the refund');
    $t->assertEqualsMoney(30000, $ds['doctorShare'], 'doctor 40% of 100,000, less all 10,000');
    $t->assertEqualsMoney(60000, $ds['centerShare'], 'the centre keeps its whole 60%');

    // A charge by percentage: nothing leaves, his part goes to the centre.
    $doc = $doctorWithCash();
    $key = 'doctor:' . $doc;
    $center = $bal($C);
    $columns->createEntry($entry($charge, $doc, 10000, ''), $admin);
    $t->assertSame(4000000 - 400000, $bal($key), 'his 40% left his box');
    $t->assertSame($center + 400000, $bal($C), 'and reached قاسەی لێزان');
    $ds = ds_compute_range($db, $doc, $today, $today, false);
    $t->assertEqualsMoney(36000, $ds['doctorShare'], 'doctor 40% of 90,000');

    // A charge wholly on the doctor.
    $doc = $doctorWithCash();
    $key = 'doctor:' . $doc;
    $center = $bal($C);
    $columns->createEntry($entry($fine, $doc, 10000, ''), $admin);
    $t->assertSame(4000000 - 1000000, $bal($key), 'all of it left his box');
    $t->assertSame($center + 1000000, $bal($C), 'and reached قاسەی لێزان');
    $t->assertEqualsMoney(30000, ds_compute_range($db, $doc, $today, $today, false)['doctorShare'], 'all of it off his share');

    // Whatever the column, with the split on his box is exactly his share.
    $p = $svc->preview($doc, $today, $today);
    $t->assertSame($p['shares']['doctor'], $p['box'], 'his box holds what the settlement pays him');
    $t->assertSame(0, $p['shares']['center_due'], 'nothing left to transfer');

    // Put on another doctor: the first is made whole, the second carries it.
    $first = $doctorWithCash();
    $second = $doctorWithCash();
    $id = $columns->createEntry($entry($refund, $first, 10000), $admin);
    $columns->updateEntry($id, $entry($refund, $second, 10000), $admin);
    $t->assertSame(4000000, $bal('doctor:' . $first), 'the first doctor has it back');
    $t->assertSame(4000000 - 400000, $bal('doctor:' . $second), 'the second doctor carries his part');

    // ── A settled day is closed; the day after is not ─────────────────────
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $doc = $doctorWithCash();
    $settled = $svc->create($doc, $yesterday, $today, '0', '0', '', $admin);
    $closed = false;
    try {
        $columns->createEntry($entry($refund, $doc, 5000), $admin);
    } catch (InvalidArgumentException $e) {
        $closed = strpos($e->getMessage(), $settled['settlement_no']) !== false;
    }
    $t->assertTrue($closed, 'a settled day refuses an entry and names the settlement');
    $t->assertSame(4000000, $bal('doctor:' . $doc), 'and no money moved');

    // Rules of a column.
    $locked = false;
    $doc = $doctorWithCash();
    $columns->createEntry($entry($refund, $doc, 1000), $admin);
    try {
        $columns->saveColumn($refund, ['name' => 'ناوی نوێ', 'cash_out' => 0, 'on_doctor' => 0], $admin);
    } catch (InvalidArgumentException $e) {
        $locked = true;
    }
    $t->assertTrue($locked, 'a column with entries keeps what it does');
    $columns->saveColumn($refund, ['name' => 'ناوی نوێ', 'cash_out' => 1, 'on_doctor' => 0], $admin);
    $t->assertSame('ناوی نوێ', $db->fetch('SELECT name FROM income_columns WHERE id = ?', [$refund])['name'], 'its name can change');

    $needsNote = false;
    try {
        $entry($refund, $doc, 1000, '');
    } catch (InvalidArgumentException $e) {
        $needsNote = true;
    }
    $t->assertTrue($needsNote, 'cash leaving needs a reason');

    $columns->removeColumn($refund, $admin);
    $kept = $db->fetch('SELECT is_active FROM income_columns WHERE id = ?', [$refund]);
    $t->assertSame(0, (int)$kept['is_active'], 'with entries it only leaves the sheet');
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
    $charge2 = $columns->saveColumn(0, ['name' => 'خەرجی دووەم', 'cash_out' => 0, 'on_doctor' => 0], $admin);

    $doc = $doctorWithCash();
    $key = 'doctor:' . $doc;
    $t->assertSame(10000000, $bal($key), 'split off: the whole 100,000 is in his box');
    $center = $bal($C);
    $columns->createEntry($entry($money2, $doc, 20000), $admin);
    $t->assertSame(8000000, $bal($key), 'the cash left his box whole');
    $t->assertSame($center, $bal($C), 'قاسەی لێزان waits for the settlement');
    $p = $svc->preview($doc, $today, $today);
    $t->assertSame(3200000, $p['shares']['doctor'], 'doctor 40% of 80,000');
    $t->assertSame(4800000, $p['shares']['center_due'], 'the centre is owed what is left, not the cash that went');
    $t->assertSame($p['box'], $p['defaults']['transfer'] + $p['defaults']['payout'], 'the settlement empties the box exactly');

    $doc = $doctorWithCash();
    $key = 'doctor:' . $doc;
    $columns->createEntry($entry($charge2, $doc, 20000, ''), $admin);
    $t->assertSame(10000000, $bal($key), 'a charge moves nothing yet');
    $p = $svc->preview($doc, $today, $today);
    $t->assertSame(3200000, $p['shares']['doctor'], 'doctor 40% of 80,000');
    $t->assertSame(6800000, $p['shares']['center_due'], 'the charge comes to the centre in the settlement');
    $t->assertSame($p['box'], $p['defaults']['transfer'] + $p['defaults']['payout'], 'the settlement empties the box exactly');

    Settings::set(CashboxLedger::SPLIT_SETTING, $savedSplit ? '1' : '0', 'boolean');
    $_SESSION = $savedSession;
})();
