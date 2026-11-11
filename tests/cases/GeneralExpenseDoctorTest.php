<?php // tests/cases/GeneralExpenseDoctorTest.php
// What a doctor carries of the expenses in his name, the owner's sheet's way
// (Book1.xlsx): a general expense, a lab cost and an implant cost come off
// before the split, so he carries them by his income percentage; an ئاسایی
// store cost by its own percentage (his income percentage until it is set),
// off his share. One with no doctor stays the centre's. His share, his box and
// his settlement agree, and a cost whose doctor is changed follows the new name.
require_once __DIR__ . '/../../classes/CashboxLedger.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/IncomeSettlement.php';

(function () use ($t) {
    $savedSession = $_SESSION;
    $db = fx_db();
    $ledger = new CashboxLedger();
    $svc = new IncomeSettlement();
    $admin = fx_user('admin');
    $_SESSION = ['user_id' => $admin, 'role' => 'admin', 'logged_in' => true];
    $C = CashboxLedger::CENTER;
    $bal = fn($k) => CashboxLedger::cents($db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key = ?', [$k])['balance'] ?? '0.00');
    $journal = fn($k) => CashboxLedger::cents($db->fetch('SELECT COALESCE(SUM(amount), 0) s FROM cashbox_entries WHERE account_key = ?', [$k])['s']);
    Settings::set(CashboxLedger::SPLIT_SETTING, '1', 'boolean');

    // A doctor at a percentage with 100,000 taken on 5 Oct, booked to his box.
    $doctorWithIncome = function (int $rate) use ($db, $admin, $ledger, $t) {
        $doc = fx_user('doctor');
        $db->update('users', ['commission_rate' => $rate], 'id = ?', [$doc]);
        $case = fx_case(fx_folder(fx_patient(), $doc));
        $inv = fx_invoice($case, $doc, 'sent', 'sent', 100000);
        fx_treatment($case, 100000, $inv);
        $db->update('case_invoices', ['work_date' => '2026-10-05', 'draft_received_amount' => 100000, 'payment_intent' => 'cash'], 'id = ?', [$inv]);
        $t->assertTrue((new CaseInvoice())->approve($case, $inv, $admin)['success'], 'invoice approved');
        $ledger->reconcile('doctor:' . $doc);
        return $doc;
    };

    // ── General: his percentage of it, off before the split ──
    $doc = $doctorWithIncome(40);
    $other = fx_user('doctor');
    $db->update('users', ['commission_rate' => 50], 'id = ?', [$other]);
    $key = 'doctor:' . $doc;
    $otherKey = 'doctor:' . $other;
    $t->assertSame(4000000, $bal($key), 'his 40% of the 100,000 (cents)');
    $center = $bal($C);

    // one in his name, one for the centre
    $exp = (int)$db->insert('expenses_general', ['doctor_id' => $doc, 'description' => 'in his name', 'amount' => 10000, 'expense_date' => '2026-10-06']);
    $db->insert('expenses_general', ['description' => 'rent', 'amount' => 7000, 'expense_date' => '2026-10-06']);

    $ds = ds_compute_range($db, $doc, '2026-10-01', '2026-10-10', false);
    $t->assertEqualsMoney(10000, $ds['generalExpense'], 'only the one in his name is his');
    $t->assertEqualsMoney(90000, $ds['netAfterExpenses'], 'net = cash − the general expense');
    $t->assertEqualsMoney(36000, $ds['doctorShare'], 'his 40% of the 90,000');
    $t->assertEqualsMoney(54000, $ds['centerShare'], 'the centre\'s 60% of it');

    $money = array_column(ds_row_lists($ds)['money'], 1, 0);
    $t->assertSame('(10,000)', $money['خەرجی گشتی'] ?? null, 'the daily summary lists it');

    $ledger->reconcile($key);
    $t->assertSame([3600000, $center + 400000], [$bal($key), $bal($C)], 'box: his 40% of the cost goes to the centre');

    // the wrong doctor was chosen: the cost follows the corrected name
    $db->update('expenses_general', ['doctor_id' => $other], 'id = ?', [$exp]);
    $ledger->reconcile($key);
    $t->assertSame([4000000, -500000, $center + 500000], [$bal($key), $bal($otherKey), $bal($C)], 'moved: back to the first, the second\'s 50% on him');

    // taken off every doctor: it is the centre's again
    $db->update('expenses_general', ['doctor_id' => null], 'id = ?', [$exp]);
    $ledger->reconcile($otherKey);
    $t->assertSame([4000000, 0, $center], [$bal($key), $bal($otherKey), $bal($C)], 'no doctor: nothing is left on anyone');

    // and put back
    $db->update('expenses_general', ['doctor_id' => $doc], 'id = ?', [$exp]);
    $ledger->reconcile($key);
    $t->assertSame([3600000, $center + 400000], [$bal($key), $bal($C)], 'back on him: his 40% again');

    // a lab cost stays on his income percentage, and moves between doctors the same way
    $lab = (int)$db->insert('expenses_lab', ['doctor_id' => $doc, 'description' => 'lab', 'amount' => 5000, 'expense_date' => '2026-10-07']);
    $ledger->reconcile($key);
    $t->assertSame(3400000, $bal($key), 'a lab cost: his 40% of it');
    $db->update('expenses_lab', ['doctor_id' => $other], 'id = ?', [$lab]);
    $ledger->reconcile($key);
    $t->assertSame([3600000, -250000], [$bal($key), $bal($otherKey)], 'a lab cost moves the same way');
    $db->delete('expenses_lab', 'id = ?', [$lab]);
    $ledger->reconcile($otherKey);
    $t->assertSame(0, $bal($otherKey), 'deleted: nothing left on him');

    // settling keeps it beside the lab and store figures
    $p = $svc->preview($doc, '2026-10-01', '2026-10-10');
    $t->assertSame(3600000, $p['box'], 'the box holds exactly his share');
    $t->assertSame(3600000, $p['shares']['doctor'], 'the report agrees with the box');
    $t->assertSame(1000000, $p['shares']['expenses'], 'expenses include the general one');
    $res = $svc->create($doc, '2026-10-01', '2026-10-10', '0', '36000', '', $admin);
    $row = $db->fetch('SELECT general_expense, snapshot FROM income_settlements WHERE id = ?', [$res['id']]);
    $t->assertSame('10000.00', $row['general_expense'], 'the settlement records it');
    $t->assertEqualsMoney(10000, json_decode($row['snapshot'], true)['generalExpense'], 'the signed paper keeps it');
    $t->assertSame(0, $bal($key), 'paid out, box empty');

    // ── Store: ئاسایی by its own percentage off his share; Implant whole before the split ──
    $store = $doctorWithIncome(40);
    $storeKey = 'doctor:' . $store;
    $center = $bal($C);
    $db->update('users', ['store_share_normal' => 50, 'store_share_implant' => 25], 'id = ?', [$store]);
    $normal = (int)$db->insert('expenses_store', ['doctor_id' => $store, 'expense_kind' => 'normal', 'description' => 'normal', 'amount' => 10000, 'expense_date' => '2026-10-06']);
    $db->insert('expenses_store', ['doctor_id' => $store, 'expense_kind' => 'implant', 'description' => 'implant', 'amount' => 20000, 'expense_date' => '2026-10-06']);

    $ds = ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false);
    $t->assertEqualsMoney(30000, $ds['storeExpense'], 'both kinds are the store cost');
    $t->assertEqualsMoney(10000, $ds['storeOnDoctor'], 'the sheet\'s lines: 50% of 10,000 + 25% of 20,000');
    $t->assertEqualsMoney(70000, $ds['netAfterExpenses'], 'the whole cost still comes off the net');
    $t->assertEqualsMoney(80000, $ds['netBeforeNormal'], 'کۆی ماوە دوای خەرجی: before the ئاسایی store');
    $t->assertEqualsMoney(27000, $ds['doctorShare'], '40% of the 80,000 less his 5,000 of ئاسایی');
    $t->assertEqualsMoney(43000, $ds['centerShare'], 'the centre: 60% of the 80,000 less its 5,000');

    $ledger->reconcile($storeKey);
    $t->assertSame([2700000, $center + 1300000], [$bal($storeKey), $bal($C)], 'box: 50% of ئاسایی and 40% of the implant go to the centre');

    // a percentage set later reaches the costs already booked
    $db->update('users', ['store_share_normal' => 100], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(2200000, $bal($storeKey), 'ئاسایی at 100%: 5,000 more on him');
    $t->assertEqualsMoney(22000, ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false)['doctorShare'], 'his share says the same');

    // 0% is a percentage, not a missing one
    $db->update('users', ['store_share_normal' => 0], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(3200000, $bal($storeKey), 'ئاسایی at 0%: none of it on him');

    // not set: his income percentage, as before
    $db->update('users', ['store_share_normal' => null, 'store_share_implant' => null], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(2800000, $bal($storeKey), 'not set: 40% of the 30,000');
    $t->assertEqualsMoney(28000, ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false)['doctorShare'], 'not set: 40% of the 70,000 net');
    // the ئیمپلانت percentage describes the line, it moves nothing
    $db->update('users', ['store_share_implant' => 100], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(2800000, $bal($storeKey), 'ئیمپلانت at 100%: the box is the same');
    $t->assertEqualsMoney(28000, ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false)['doctorShare'], 'and so is his share');
    $db->update('users', ['store_share_implant' => null], 'id = ?', [$store]);
    // «Implant لە کۆی ماوە» at 50%: only half the implant comes off before his split
    $db->update('users', ['implant_pool_share' => 50], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(3200000, $bal($storeKey), 'implant pool 50%: 40% of half the 20,000 on him');
    $t->assertEqualsMoney(32000, ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false)['doctorShare'], '40% of (70,000 + 10,000 + 10,000) less 4,000 of ئاسایی');
    $db->update('users', ['implant_pool_share' => null], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $db->delete('expenses_store', 'id = ?', [$normal]);
    $ledger->reconcile($storeKey);
    $t->assertSame(3200000, $bal($storeKey), 'deleted: its part comes back');

    foreach ([$key, $otherKey, $storeKey, $C] as $k) {
        $t->assertSame($journal($k), $bal($k), "cached balance equals journal ({$k})");
    }
    Settings::set(CashboxLedger::SPLIT_SETTING, '0', 'boolean');
    $_SESSION = $savedSession;
})();
