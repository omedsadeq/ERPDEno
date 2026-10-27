<?php // tests/cases/EditApprovedPaymentDetailsTest.php
// The full «تەعدیل» of an approved receipt from the case's پارەدان tab —
// amount, date and time, method and the collection discount together
// (Payment::editApprovedDetails). Money is the point, so each step checks
// where the figures land (allocations, the invoice's discount, the cashbox),
// not only that the call said yes.
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Settings.php';

(function () use ($t) {
    $db = Database::getInstance();
    $pm = new Payment();
    $doc = fx_user('doctor');
    $admin = fx_user('admin');
    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');

    $case = fx_case(fx_folder(fx_patient(), $doc));
    $invA = fx_invoice($case, $doc, 'sent', 'approved', 100000); fx_treatment($case, 100000, $invA);
    $invB = fx_invoice($case, $doc, 'sent', 'approved', 50000);  fx_treatment($case, 50000, $invB);
    (new CaseManager())->updateCaseTotals($case);
    $made = $pm->directPayment([
        'case_id' => $case, 'payment_type' => 'cash', 'amount' => 60000, 'payment_method' => 'cash',
        'payment_date' => '2026-10-20 10:15:00', 'received_by' => $admin,
    ], $admin);
    $t->assertTrue(!empty($made['success']), 'details: fixture receipt booked');
    if (empty($made['success'])) { return; }
    $id = (int)$made['payment_id'];

    $row = function () use ($db, $id) { return $db->fetch('SELECT * FROM payments WHERE id = ?', [$id]); };
    $alloc = function () use ($db, $id) {
        $out = [];
        foreach ($db->fetchAll('SELECT invoice_id, allocated_amount FROM payment_allocations WHERE payment_id = ?', [$id]) as $a) {
            $out[(int)$a['invoice_id']] = (float)$a['allocated_amount'];
        }
        return $out;
    };
    $invDiscount = function ($inv) use ($db) {
        return (float)$db->fetch('SELECT discount_amount FROM case_invoices WHERE id = ?', [$inv])['discount_amount'];
    };
    $booked = function () use ($db, $id) {
        $r = $db->fetch("SELECT booked_amount FROM cashbox_sources WHERE source_type = 'payment' AND source_id = ?", [$id]);
        return $r ? (float)$r['booked_amount'] : null;
    };
    $logs = function () use ($db, $id) {
        return (int)$db->fetch("SELECT COUNT(*) c FROM activity_log WHERE table_name = 'payments' AND record_id = ? AND action = 'payment_approved_details_edited'", [$id])['c'];
    };
    // What the dialog sends: every field as it now stands, then the change.
    $edit = function (array $change, $actor = null) use ($pm, $id, $row, $admin) {
        $p = $row();
        $form = [
            'amount' => (string)(float)$p['amount'],
            'payment_date' => substr($p['payment_date'], 0, 16),
            'payment_method' => $p['payment_method'],
            'discount_mode' => $p['discount_mode'],
            'discount_value' => (float)$p['discount_value'] > 0 ? (string)(float)$p['discount_value'] : '',
            'discount_reason' => (string)$p['discount_reason'],
        ];
        return $pm->editApprovedDetails($id, array_merge($form, $change), $actor ?? $admin, 'تاقیکردنەوە');
    };
    $before = $row();

    // ── nothing changed ──
    $r = $edit([]);
    $t->assertTrue(!empty($r['success']) && !empty($r['unchanged']), 'details: an untouched form changes nothing');
    $t->assertSame(0, $logs(), 'details: … and logs nothing');

    // ── date and time ──
    $t->assertTrue(!empty($edit(['payment_date' => '2026-10-18 09:30'])['success']), 'date: moved');
    $t->assertSame('2026-10-18 09:30:00', $row()['payment_date'], 'date: new date and time stored');
    $t->assertTrue(!empty($edit(['payment_date' => '2026-10-18T11:05'])['success']), 'date: the datetime-local form is read too');
    $t->assertSame('2026-10-18 11:05:00', $row()['payment_date'], 'date: … to the minute');
    $t->assertEqualsMoney(60000, $alloc()[$invA] ?? 0, 'date: allocations untouched');
    $t->assertSame('approved', $row()['status'], 'date: still approved');
    $t->assertSame($before['payment_number'], $row()['payment_number'], 'date: number unchanged');
    $t->assertSame((string)$before['approved_by'], (string)$row()['approved_by'], 'date: approver unchanged');
    $t->assertTrue(empty($edit(['payment_date' => '2099-01-01 10:00'])['success']), 'date: the future refused');
    $t->assertTrue(empty($edit(['payment_date' => '2026-02-30 10:00'])['success']), 'date: an impossible date refused');
    $t->assertSame('2026-10-18 11:05:00', $row()['payment_date'], 'date: refused edits changed nothing');

    // ── method: cash ↔ card moves the money in and out of the doctor's box ──
    $cashBooked = $booked();
    $t->assertEqualsMoney(60000, (float)$cashBooked, 'method: the cash receipt is in the box');
    $t->assertTrue(!empty($edit(['payment_method' => 'card'])['success']), 'method: cash → card');
    $t->assertSame('card', $row()['payment_method'], 'method: stored');
    $t->assertEqualsMoney(0, (float)$booked(), 'method: card is no cash in the box');
    $t->assertTrue(!empty($edit(['payment_method' => 'cash'])['success']), 'method: card → cash');
    $t->assertEqualsMoney(60000, (float)$booked(), 'method: the cash is back in the box');
    $t->assertTrue(empty($edit(['payment_method' => 'advance'])['success']), 'method: ئامانەت cannot be chosen here');

    // ── amount, no discount: the receipt keeps its invoice ──
    $t->assertTrue(!empty($edit(['amount' => '70000'])['success']), 'amount: changed');
    $t->assertEqualsMoney(70000, $row()['amount'], 'amount: stored');
    $t->assertEqualsMoney(70000, $alloc()[$invA] ?? 0, 'amount: its allocation follows');
    $t->assertEqualsMoney(70000, (float)$booked(), 'amount: the box follows');

    // ── discount added, changed, as a percentage, reason only, removed ──
    $r = $edit(['discount_value' => '10000', 'discount_reason' => 'ڕێککەوتن']);
    $t->assertTrue(!empty($r['success']), 'discount: added ' . ($r['message'] ?? ''));
    $t->assertEqualsMoney(10000, $row()['discount_amount'], 'discount: on the receipt');
    $t->assertEqualsMoney(10000, $invDiscount($invA), 'discount: on the invoice it pays');
    $t->assertEqualsMoney(70000, $alloc()[$invA] ?? 0, 'discount: the cash allocation is unchanged');
    $t->assertEqualsMoney(70000, $row()['amount'], 'discount: the cash amount is unchanged');

    $t->assertTrue(!empty($edit(['amount' => '75000', 'discount_value' => '5000'])['success']), 'discount: amount and discount changed together');
    $t->assertEqualsMoney(5000, $invDiscount($invA), 'discount: the invoice carries the new discount, not old + new');
    $t->assertEqualsMoney(75000, $alloc()[$invA] ?? 0, 'discount: new cash on the invoice');

    $t->assertTrue(!empty($edit(['discount_mode' => 'percent', 'discount_value' => '10'])['success']), 'discount: as a percentage');
    $t->assertEqualsMoney(15000, $row()['discount_amount'], 'discount: 10% of the 150,000 the case owed without this receipt');
    $t->assertEqualsMoney(15000, $invDiscount($invA), 'discount: … on the invoice');

    $t->assertTrue(!empty($edit(['discount_reason' => 'هۆکاری نوێ'])['success']), 'discount: reason only');
    $t->assertSame('هۆکاری نوێ', $row()['discount_reason'], 'discount: reason stored');
    $t->assertEqualsMoney(15000, $invDiscount($invA), 'discount: a reason change moves no money');

    $t->assertTrue(empty($edit(['amount' => '140000'])['success']), 'discount: cash + discount over the debt refused');
    $t->assertTrue(empty($edit(['discount_mode' => 'amount', 'discount_value' => '5000', 'discount_reason' => ''])['success']), 'discount: no reason refused');
    $t->assertEqualsMoney(75000, $row()['amount'], 'discount: refused edits left the cash alone');
    $t->assertEqualsMoney(15000, $invDiscount($invA), 'discount: … and the invoice discount');

    $t->assertTrue(!empty($edit(['discount_value' => ''])['success']), 'discount: removed');
    $t->assertEqualsMoney(0, $row()['discount_amount'], 'discount: off the receipt');
    $t->assertEqualsMoney(0, $invDiscount($invA), 'discount: off the invoice, back to what it was');
    $t->assertEqualsMoney(75000, $alloc()[$invA] ?? 0, 'discount: the cash stays on its invoice');
    $t->assertEqualsMoney(75000, (float)$booked(), 'discount: the box holds the cash only');

    // ── a period already settled for the doctor ──
    if ($db->tableExists('income_settlements')) {
        $sid = (int)$db->insert('income_settlements', [
            'settlement_no' => 'T-' . uniqid(), 'doctor_id' => $doc, 'period_from' => '2026-10-15', 'period_to' => '2026-10-19', 'doctor_rate' => 0,
            'status' => 'done', 'created_by' => $admin, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $t->assertTrue(empty($edit(['payment_date' => '2026-10-22 10:00'])['success']), 'settled: moving out of a settled period refused');
        $t->assertTrue(!empty($edit(['amount' => '76000'])['success']), 'settled: an amount change still goes through (as before)');
        $db->update('income_settlements', ['period_from' => '2026-10-21', 'period_to' => '2026-10-25'], 'id = ?', [$sid]);
        $t->assertTrue(empty($edit(['payment_date' => '2026-10-22 10:00'])['success']), 'settled: moving into a settled period refused');
        $t->assertTrue(!empty($edit(['payment_date' => '2026-10-20 10:00'])['success']), 'settled: a free day is fine');
        $db->delete('income_settlements', 'id = ?', [$sid]);
    }

    // ── who and when ──
    $t->assertTrue(empty($edit(['amount' => '50000'], $doc)['success']), 'access: a doctor is refused');
    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '0');
    $t->assertTrue(empty($edit(['amount' => '50000'])['success']), 'access: switch OFF refused');
    Settings::set(Settings::ALLOW_EDIT_APPROVED_INVOICE, '1');
    $t->assertTrue((float)$row()['amount'] > 50000, 'access: refused edits changed nothing');

    $db->update('payments', ['notes' => 'وەسڵی کارە ئەنجامدراوەکان #1'], 'id = ?', [$id]);
    $t->assertTrue(empty($edit(['payment_date' => '2026-10-19 10:00'])['success']), 'blocked: an arch-work receipt is refused');
    $db->update('payments', ['notes' => null], 'id = ?', [$id]);

    $t->assertSame($db->tableExists('income_settlements') ? 12 : 10, $logs(), 'log: every saved change logged once');
})();
