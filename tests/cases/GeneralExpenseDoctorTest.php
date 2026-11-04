<?php // tests/cases/GeneralExpenseDoctorTest.php
// What a doctor carries of the expenses in his name. A general expense has no
// percentage: all of it comes off his share, and one with no doctor stays the
// centre's. A store cost falls on him by the store's own percentages (ئاسایی /
// ئیمپلانت), his income percentage until they are set. His share, his box and
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

    // ── General: all of it on him ──
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
    $t->assertEqualsMoney(30000, $ds['doctorShare'], 'his 40,000 less all of the 10,000');
    $t->assertEqualsMoney(60000, $ds['centerShare'], 'the centre keeps its whole 60%');

    $money = array_column(ds_row_lists($ds)['money'], 1, 0);
    $t->assertSame('(10,000)', $money['خەرجی گشتی'] ?? null, 'the daily summary lists it');

    $ledger->reconcile($key);
    $t->assertSame([3000000, $center + 1000000], [$bal($key), $bal($C)], 'box: all of the cost goes to the centre');

    // the wrong doctor was chosen: the cost follows the corrected name
    $db->update('expenses_general', ['doctor_id' => $other], 'id = ?', [$exp]);
    $ledger->reconcile($key);
    $t->assertSame([4000000, -1000000, $center + 1000000], [$bal($key), $bal($otherKey), $bal($C)], 'moved: back to the first, all on the second');

    // taken off every doctor: it is the centre's again
    $db->update('expenses_general', ['doctor_id' => null], 'id = ?', [$exp]);
    $ledger->reconcile($otherKey);
    $t->assertSame([4000000, 0, $center], [$bal($key), $bal($otherKey), $bal($C)], 'no doctor: nothing is left on anyone');

    // and put back
    $db->update('expenses_general', ['doctor_id' => $doc], 'id = ?', [$exp]);
    $ledger->reconcile($key);
    $t->assertSame([3000000, $center + 1000000], [$bal($key), $bal($C)], 'back on him: all of it again');

    // a lab cost stays on his income percentage, and moves between doctors the same way
    $lab = (int)$db->insert('expenses_lab', ['doctor_id' => $doc, 'description' => 'lab', 'amount' => 5000, 'expense_date' => '2026-10-07']);
    $ledger->reconcile($key);
    $t->assertSame(2800000, $bal($key), 'a lab cost: his 40% of it');
    $db->update('expenses_lab', ['doctor_id' => $other], 'id = ?', [$lab]);
    $ledger->reconcile($key);
    $t->assertSame([3000000, -250000], [$bal($key), $bal($otherKey)], 'a lab cost moves the same way');
    $db->delete('expenses_lab', 'id = ?', [$lab]);
    $ledger->reconcile($otherKey);
    $t->assertSame(0, $bal($otherKey), 'deleted: nothing left on him');

    // settling keeps it beside the lab and store figures
    $p = $svc->preview($doc, '2026-10-01', '2026-10-10');
    $t->assertSame(3000000, $p['box'], 'the box holds exactly his share');
    $t->assertSame(3000000, $p['shares']['doctor'], 'the report agrees with the box');
    $t->assertSame(1000000, $p['shares']['expenses'], 'expenses include the general one');
    $res = $svc->create($doc, '2026-10-01', '2026-10-10', '0', '30000', '', $admin);
    $row = $db->fetch('SELECT general_expense, snapshot FROM income_settlements WHERE id = ?', [$res['id']]);
    $t->assertSame('10000.00', $row['general_expense'], 'the settlement records it');
    $t->assertEqualsMoney(10000, json_decode($row['snapshot'], true)['generalExpense'], 'the signed paper keeps it');
    $t->assertSame(0, $bal($key), 'paid out, box empty');

    // ── Store: its own percentages, one for each kind ──
    $store = $doctorWithIncome(40);
    $storeKey = 'doctor:' . $store;
    $center = $bal($C);
    $db->update('users', ['store_share_normal' => 50, 'store_share_implant' => 25], 'id = ?', [$store]);
    $normal = (int)$db->insert('expenses_store', ['doctor_id' => $store, 'expense_kind' => 'normal', 'description' => 'normal', 'amount' => 10000, 'expense_date' => '2026-10-06']);
    $db->insert('expenses_store', ['doctor_id' => $store, 'expense_kind' => 'implant', 'description' => 'implant', 'amount' => 20000, 'expense_date' => '2026-10-06']);

    $ds = ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false);
    $t->assertEqualsMoney(30000, $ds['storeExpense'], 'both kinds are the store cost');
    $t->assertEqualsMoney(10000, $ds['storeOnDoctor'], '50% of 10,000 + 25% of 20,000');
    $t->assertEqualsMoney(70000, $ds['netAfterExpenses'], 'the whole cost still comes off the net');
    $t->assertEqualsMoney(30000, $ds['doctorShare'], 'his 40,000 less his 10,000 of the store');
    $t->assertEqualsMoney(40000, $ds['centerShare'], 'the centre carries the other 20,000');

    $ledger->reconcile($storeKey);
    $t->assertSame([3000000, $center + 1000000], [$bal($storeKey), $bal($C)], 'box: his part of each kind goes to the centre');

    // a percentage set later reaches the costs already booked
    $db->update('users', ['store_share_normal' => 100], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(2500000, $bal($storeKey), 'ئاسایی at 100%: 5,000 more on him');
    $t->assertEqualsMoney(25000, ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false)['doctorShare'], 'his share says the same');

    // 0% is a percentage, not a missing one
    $db->update('users', ['store_share_normal' => 0], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(3500000, $bal($storeKey), 'ئاسایی at 0%: none of it on him');

    // not set: his income percentage, as before
    $db->update('users', ['store_share_normal' => null, 'store_share_implant' => null], 'id = ?', [$store]);
    $ledger->reconcile($storeKey);
    $t->assertSame(2800000, $bal($storeKey), 'not set: 40% of the 30,000');
    $t->assertEqualsMoney(28000, ds_compute_range($db, $store, '2026-10-01', '2026-10-10', false)['doctorShare'], 'not set: 40% of the 70,000 net');
    $db->delete('expenses_store', 'id = ?', [$normal]);
    $ledger->reconcile($storeKey);
    $t->assertSame(3200000, $bal($storeKey), 'deleted: its part comes back');

    foreach ([$key, $otherKey, $storeKey, $C] as $k) {
        $t->assertSame($journal($k), $bal($k), "cached balance equals journal ({$k})");
    }
    Settings::set(CashboxLedger::SPLIT_SETTING, '0', 'boolean');
    $_SESSION = $savedSession;
})();
