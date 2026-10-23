<?php
// دابەشکردنی داهات — IncomeSettlement + CashboxLedger::settle/unsettle.
require_once __DIR__ . '/../../classes/IncomeSettlement.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
(function () use ($t) {
    $savedSession = $_SESSION;
    $db = fx_db();
    $svc = new IncomeSettlement();
    $ledger = new CashboxLedger();
    $doc = fx_user('doctor'); $noRate = fx_user('doctor'); $admin = fx_user('admin'); $acct = fx_user('accountant');
    $_SESSION = ['user_id' => $admin, 'role' => 'admin', 'logged_in' => true];
    $db->update('users', ['commission_rate' => 40], 'id = ?', [$doc]);
    $box = fn($k) => $db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key = ?', [$k])['balance'] ?? '0.00';
    $journal = fn($k) => $db->fetch('SELECT COALESCE(SUM(amount), 0) s FROM cashbox_entries WHERE account_key = ?', [$k])['s'];

    // 100,000 cash taken in the chair on 5 Oct, and a 10,000 store expense.
    $case = fx_case(fx_folder(fx_patient(), $doc));
    $inv = fx_invoice($case, $doc, 'sent', 'sent', 100000); fx_treatment($case, 100000, $inv);
    $db->update('case_invoices', ['work_date' => '2026-10-05', 'draft_received_amount' => 100000, 'payment_intent' => 'cash'], 'id = ?', [$inv]);
    $t->assertTrue((new CaseInvoice())->approve($case, $inv, $admin)['success'], 'invoice approved');
    $db->insert('expenses_store', ['doctor_id' => $doc, 'description' => 'test material', 'amount' => 10000, 'expense_date' => '2026-10-06']);
    $key = 'doctor:' . $doc;
    $centerBefore = $ledger->balanceOf(CashboxLedger::CENTER);   // cents; other tests may have filled it

    $p = $svc->preview($doc, '2026-10-01', '2026-10-10');
    $t->assertEqualsMoney(100000, $p['ds']['cashIn'], 'cash in = chair cash');
    $t->assertEqualsMoney(90000, $p['ds']['netAfterExpenses'], 'net = cash − store');
    $t->assertSame(3600000, $p['shares']['doctor'], 'doctor share 40% of 90,000 (cents)');
    $t->assertSame(5400000, $p['shares']['center'], 'centre share 60% (cents)');
    $t->assertSame(10000000, $p['box'], 'doctor box holds the chair cash');
    $t->assertSame(1000000, $p['shares']['expenses'], 'expenses (cents)');
    $t->assertSame(6400000, $p['shares']['center_due'], 'centre is owed its share + the store cost = cash − doctor share');
    $t->assertSame(['transfer' => 6400000, 'payout' => 3600000], $p['defaults'], 'defaults: everything the centre is owed, the doctor his share');
    $t->assertTrue($p['existing'] === null, 'nothing settled yet');

    $t->assertThrows(fn() => $svc->create($doc, '2026-10-01', '2026-10-10', '54000', '36000', '', $acct), 'accountant without the permission cannot settle');
    $t->assertThrows(fn() => $svc->create($noRate, '2026-10-01', '2026-10-10', '0', '0', '', $admin), 'no percentage → no settlement');
    $t->assertThrows(fn() => $svc->create($doc, '2026-10-01', '2026-10-10', '64000.01', '0', '', $admin), 'transfer above what the centre is owed refused');
    $t->assertThrows(fn() => $svc->create($doc, '2026-10-01', '2026-10-10', '0', '36001', '', $admin), 'payout above doctor share refused');
    $t->assertThrows(fn() => $svc->create($doc, '2099-01-01', '2099-01-31', '0', '0', '', $admin), 'future period refused');
    $t->assertEqualsMoney(100000, $box($key), 'refused attempts move no cash');

    $res = $svc->create($doc, '2026-10-01', '2026-10-10', '64000', '36000', 'October first third', $admin);
    $t->assertTrue((bool)preg_match('/^IS-\d{6}-\d{4,}$/', $res['settlement_no']), 'settlement number issued');
    $t->assertEqualsMoney(0, $box($key), 'doctor box: 100,000 − 64,000 − 36,000');
    $t->assertSame($centerBefore + 6400000, CashboxLedger::cents($box(CashboxLedger::CENTER)), 'centre box got its share and the store cost');
    $s = $svc->get($res['id']);
    $t->assertSame('done', $s['status'], 'settlement recorded');
    $t->assertEqualsMoney(100000, $s['box_before'], 'box before frozen');
    $t->assertEqualsMoney(0, $s['box_after'], 'box after frozen');
    $t->assertEqualsMoney(64000, $s['transferred_to_center'], 'transfer frozen');
    $t->assertEqualsMoney(36000, $s['paid_to_doctor'], 'payout frozen');
    $t->assertTrue(isset($s['report']['work']['total'], $s['report']['cashIn'], $s['report']['topTreatments']), 'report snapshot keeps the visit figures');
    $t->assertTrue(!isset($s['report']['patientRows']), 'the patient list is not frozen into the snapshot');
    $t->assertSame('40.00', (string)$s['doctor_rate'], 'rate frozen');
    $kinds = array_column($db->fetchAll('SELECT entry_kind FROM cashbox_entries WHERE reference_no = ? ORDER BY id', [$res['settlement_no']]), 'entry_kind');
    $t->assertSame(['transfer_out', 'transfer_in', 'payout'], $kinds, 'three journal entries under the settlement number');

    $t->assertThrows(fn() => $svc->create($doc, '2026-10-08', '2026-10-12', '0', '0', '', $admin), 'overlapping period refused');
    $t->assertTrue($svc->preview($doc, '2026-10-01', '2026-10-31')['existing'] !== null, 'the month shows as settled');

    $svc->markSigned($res['id'], $admin);
    $t->assertTrue($svc->get($res['id'])['signed_at'] !== null, 'marked as signed');
    $t->assertThrows(fn() => $svc->markSigned($res['id'], $acct), 'only a settler marks signatures');

    $t->assertThrows(fn() => $svc->cancel($res['id'], 'mistake', $acct), 'only admin cancels');
    $svc->cancel($res['id'], 'wrong period', $admin);
    $t->assertSame('cancelled', $svc->get($res['id'])['status'], 'settlement cancelled');
    $t->assertEqualsMoney(100000, $box($key), 'cancel returns all cash to the doctor box');
    $t->assertSame($centerBefore, CashboxLedger::cents($box(CashboxLedger::CENTER)), 'cancel takes the share back out of قاسەی لێزان');
    $t->assertThrows(fn() => $svc->cancel($res['id'], 'again', $admin), 'double cancel refused');

    // The box cannot pay more than it holds.
    $ledger->move($key, 'withdrawal', '95000', 'cash handed over early', bin2hex(random_bytes(24)), $admin);
    $t->assertThrows(fn() => $svc->create($doc, '2026-10-01', '2026-10-10', '5000', '1000', '', $admin), 'settlement above box cash refused');
    $svc->create($doc, '2026-10-01', '2026-10-10', '5000', '0', '', $admin);
    $t->assertEqualsMoney(0, $box($key), 'a cancelled period can be settled again, within the cash');

    foreach ([$key, CashboxLedger::CENTER] as $k) {
        $t->assertSame(CashboxLedger::cents($journal($k)), CashboxLedger::cents($box($k)), "cached balance equals journal ({$k})");
    }
    $_SESSION = $savedSession;
})();
