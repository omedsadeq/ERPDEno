<?php
// دابەشکردنی ڕاستەوخۆ — every doctor receipt split at booking (CashboxLedger,
// setting cashbox_split_enabled): his percentage to his box, the rest to
// قاسەی لێزان; his percentage of a lab/store cost moves box → قاسەی لێزان.
require_once __DIR__ . '/../../classes/CashboxLedger.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/PatientAdvance.php';
require_once __DIR__ . '/../../classes/IncomeSettlement.php';
(function () use ($t) {
    $savedSession = $_SESSION;
    $db = fx_db();
    $ledger = new CashboxLedger();
    $admin = fx_user('admin');
    $_SESSION = ['user_id' => $admin, 'role' => 'admin', 'logged_in' => true];
    $C = CashboxLedger::CENTER;
    $bal = fn($k) => CashboxLedger::cents($db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key = ?', [$k])['balance'] ?? '0.00');
    $journal = fn($k) => CashboxLedger::cents($db->fetch('SELECT COALESCE(SUM(amount), 0) s FROM cashbox_entries WHERE account_key = ?', [$k])['s']);
    $row = fn($type, $id) => $db->fetch('SELECT * FROM cashbox_sources WHERE source_type = ? AND source_id = ?', [$type, $id]);
    $approved = function ($case, $doctor, $amount, $received, $day) use ($db, $admin) {
        $inv = fx_invoice($case, $doctor, 'sent', 'sent', $amount); fx_treatment($case, $amount, $inv);
        $db->update('case_invoices', ['work_date' => $day, 'draft_received_amount' => $received, 'payment_intent' => 'cash'], 'id = ?', [$inv]);
        return [$inv, (new CaseInvoice())->approve($case, $inv, $admin)];
    };
    Settings::set(CashboxLedger::SPLIT_SETTING, '0', 'boolean');

    // ── Before the switch: everything stays in the doctor's box ──
    $doc = fx_user('doctor');
    $db->update('users', ['commission_rate' => 80], 'id = ?', [$doc]);
    $key = 'doctor:' . $doc;
    $pat = fx_patient();
    $case = fx_case(fx_folder($pat, $doc));
    [$inv1, $res] = $approved($case, $doc, 100000, 100000, '2026-10-05');
    $t->assertTrue($res['success'], 'switch off: invoice approved');
    $ledger->reconcile($key);
    $centerStart = $bal($C);
    $t->assertSame(10000000, $bal($key), 'switch off: 100,000 all in the doctor box');
    $t->assertTrue($row('invoice', $inv1)['booked_doctor'] === null, 'switch off: row stays unsplit');

    // ── Switching on: the centre's part of the earlier money moves once ──
    Settings::set(CashboxLedger::SPLIT_SETTING, '1', 'boolean');
    $ledger->reconcile($key);
    $t->assertSame(8000000, $bal($key), 'opening split: doctor keeps 80,000');
    $t->assertSame($centerStart + 2000000, $bal($C), 'opening split: 20,000 to قاسەی لێزان');
    $r1 = $row('invoice', $inv1);
    $t->assertSame(['80.00', '80000.00', '20000.00'], [$r1['split_rate'], $r1['booked_doctor'], $r1['booked_center']], 'opening split: row converted at 80%');
    $t->assertSame(1, $db->count('cashbox_entries', "account_key = ? AND entry_kind = 'transfer_out'", [$key]), 'opening split: one transfer');
    $ledger->reconcile($key); $ledger->reconcile();
    $t->assertSame(8000000, $bal($key), 'opening split never repeats');
    $t->assertSame($centerStart + 2000000, $bal($C), 'opening split never repeats (centre)');

    // ── New money is split the moment it is booked ──
    [$inv2] = $approved($case, $doc, 50000, 50000, '2026-10-06');
    $t->assertSame(12000000, $bal($key), 'approval: 40,000 to the doctor');
    $t->assertSame($centerStart + 3000000, $bal($C), 'approval: 10,000 to قاسەی لێزان');
    $e2 = $db->fetchAll("SELECT account_key, entry_kind, amount, source_revision FROM cashbox_entries WHERE source_type = 'invoice' AND source_id = ? ORDER BY id", [$inv2]);
    $t->assertSame([[$key, 'receipt', '40000.00', 1], [$C, 'center_share', '10000.00', 2]],
        array_map(fn($e) => [$e['account_key'], $e['entry_kind'], $e['amount'], (int)$e['source_revision']], $e2), 'approval: two entries, own revisions');

    // An unpaid invoice for the receipt below to settle (books no cash itself).
    [$debtInv] = $approved($case, $doc, 20000, 0, '2026-10-07');
    $t->assertSame([12000000, $centerStart + 3000000], [$bal($key), $bal($C)], 'an unpaid approval moves nothing');
    $payments = new Payment();
    $p = $payments->createRequest(['case_id' => $case, 'amount' => 10000, 'payment_method' => 'cash', 'received_by' => $doc, 'payment_date' => '2026-10-07']);
    $t->assertTrue($payments->approve($p['payment_id'], $admin)['success'], 'cash receipt approved');
    $t->assertSame([12800000, $centerStart + 3200000], [$bal($key), $bal($C)], 'receipt: 8,000 / 2,000');
    $t->assertTrue($payments->refund($p['payment_id'], 'patient refund', $admin)['success'], 'receipt refunded');
    $t->assertSame([12000000, $centerStart + 3000000], [$bal($key), $bal($C)], 'refund comes back out of both boxes');

    // ── A percentage change touches new money only ──
    $db->update('users', ['commission_rate' => 50], 'id = ?', [$doc]);
    $adv = (new PatientAdvance())->receive($pat, 10000, 'cash', 'old debt', $admin, $doc);
    $t->assertTrue($adv['success'], 'old-debt receipt taken');
    $t->assertSame([12000000, $centerStart + 3000000], [$bal($key), $bal($C)], 'custody has no doctor/center split before application');
    $t->assertTrue((new PatientAdvance())->applyToInvoice($debtInv, 8000, $admin)['success'], 'accountant applies part of custody');
    $t->assertSame([12400000, $centerStart + 3400000], [$bal($key), $bal($C)], 'applied custody uses the new 50%');
    $db->update('users', ['commission_rate' => 80], 'id = ?', [$doc]);
    $t->assertTrue((new PatientAdvance())->refund($adv['advance_id'], 2000, 'refund', $admin)['success'], 'part of it refunded');
    $t->assertSame([12400000, $centerStart + 3400000], [$bal($key), $bal($C)], 'unused custody refund leaves the frozen 50% split intact');

    // ── Lab/store costs: the doctor's percentage moves box → قاسەی لێزان ──
    $exp = (int)$db->insert('expenses_store', ['doctor_id' => $doc, 'description' => 'split test', 'amount' => 10000, 'expense_date' => '2026-10-08']);
    $ledger->reconcile($key);
    $t->assertSame([11600000, $centerStart + 4200000], [$bal($key), $bal($C)], 'cost: 8,000 of it from the doctor to the centre');
    $db->update('expenses_store', ['amount' => 5000], 'id = ?', [$exp]);
    $ledger->reconcile($key);
    $t->assertSame([12000000, $centerStart + 3800000], [$bal($key), $bal($C)], 'cost lowered: 4,000 back');
    $db->delete('expenses_store', 'id = ?', [$exp]);
    $ledger->reconcile($key);
    $t->assertSame([12400000, $centerStart + 3400000], [$bal($key), $bal($C)], 'cost deleted: all back');
    $old = (int)$db->insert('expenses_lab', ['doctor_id' => $doc, 'description' => 'september', 'amount' => 9000, 'expense_date' => '2026-09-20']);
    $ledger->reconcile($key);
    $t->assertSame(12400000, $bal($key), 'a cost before 1 Oct moves nothing');

    // ── A doctor without a percentage: everything is his, costs move nothing ──
    $free = fx_user('doctor');
    $freeKey = 'doctor:' . $free;
    $freeCase = fx_case(fx_folder(fx_patient(), $free));
    $c0 = $bal($C);
    $approved($freeCase, $free, 30000, 30000, '2026-10-09');
    $db->insert('expenses_lab', ['doctor_id' => $free, 'description' => 'no rate', 'amount' => 1000, 'expense_date' => '2026-10-09']);
    $ledger->reconcile($freeKey);
    $t->assertSame([3000000, $c0], [$bal($freeKey), $bal($C)], 'no percentage: nothing split');

    // ── An invoice opened by an admin belongs to the case's doctor ──
    $made = (new CaseInvoice())->createDraft($case, '2026-10-10', $admin);
    $madeRow = $db->fetch('SELECT doctor_id, created_by FROM case_invoices WHERE id = ?', [$made['invoice_id']]);
    $t->assertSame($doc, (int)$madeRow['doctor_id'], 'createDraft by admin files it under the case doctor');
    $t->assertSame($admin, (int)$madeRow['created_by'], 'and remembers the admin made it');
    $other = fx_user('doctor');
    $picked = (new CaseInvoice())->createDraft($case, '2026-10-10', $admin, $other);
    $t->assertSame($other, (int)$db->fetch('SELECT doctor_id FROM case_invoices WHERE id = ?', [$picked['invoice_id']])['doctor_id'], 'the doctor the admin picked is used');
    $t->assertTrue(empty((new CaseInvoice())->createDraft($case, '2026-10-10', $admin, $admin)['success']), 'a non-doctor cannot be picked as the doctor');
    $t->assertTrue((new CaseInvoice())->setDoctor($case, $picked['invoice_id'], $doc, $admin)['success'], 'a draft can be moved to another doctor');
    $t->assertSame($doc, (int)$db->fetch('SELECT doctor_id FROM case_invoices WHERE id = ?', [$picked['invoice_id']])['doctor_id'], 'moved');
    $t->assertTrue(empty((new CaseInvoice())->setDoctor($case, $inv2, $other, $admin)['success']), 'an approved invoice keeps its doctor');
    $byDoctor = (new CaseInvoice())->createDraft($case, '2026-10-10', $doc);
    $t->assertSame([$doc, $doc], array_map('intval', array_values($db->fetch('SELECT doctor_id, created_by FROM case_invoices WHERE id = ?', [$byDoctor['invoice_id']]))), 'a doctor\'s own tab: his, made by him');
    [$adminInv, $res] = $approved($case, $admin, 20000, 20000, '2026-10-10');
    $t->assertTrue($res['success'], 'an invoice still carrying an admin approves');
    $t->assertSame($key, $row('invoice', $adminInv)['account_key'], 'and its cash goes to the case doctor');

    // ── Settling: the centre already has its share, so only the payout is left ──
    $d3 = fx_user('doctor');
    $db->update('users', ['commission_rate' => 40], 'id = ?', [$d3]);
    $k3 = 'doctor:' . $d3;
    $case3 = fx_case(fx_folder(fx_patient(), $d3));
    $approved($case3, $d3, 100000, 100000, '2026-10-05');
    $db->insert('expenses_store', ['doctor_id' => $d3, 'description' => 'material', 'amount' => 10000, 'expense_date' => '2026-10-06']);
    $p3 = (new IncomeSettlement())->preview($d3, '2026-10-01', '2026-10-10');
    $t->assertSame(3600000, $p3['box'], 'settle: the box holds exactly his 40% of (100,000 − 10,000)');
    $t->assertSame(3600000, $p3['shares']['doctor'], 'settle: report agrees — doctor share 36,000');
    $t->assertSame(0, $p3['shares']['center_due'], 'settle: nothing left to transfer');
    $t->assertSame(['transfer' => 0, 'payout' => 3600000], $p3['defaults'], 'settle: defaults are just the payout');
    $t->assertThrows(fn() => (new IncomeSettlement())->create($d3, '2026-10-01', '2026-10-10', '1', '0', '', $admin), 'settle: a transfer is refused');
    (new IncomeSettlement())->create($d3, '2026-10-01', '2026-10-10', '0', '36000', '', $admin);
    $t->assertSame(0, $bal($k3), 'settle: paid out, box empty');

    foreach ([$key, $freeKey, $k3, $C] as $k) {
        $t->assertSame($journal($k), $bal($k), "cached balance equals journal ({$k})");
    }
    Settings::set(CashboxLedger::SPLIT_SETTING, '0', 'boolean');
    $_SESSION = $savedSession;
})();
