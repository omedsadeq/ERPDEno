<?php // tests/cases/StoreExpenseShareTest.php
// بەشی دکتۆر و لێزان لە خەرجی کۆگا (includes/store-expense-share.php): the figures
// of the clinic's own sheet, and that a percentage is never guessed.

require_once dirname(__DIR__, 2) . '/includes/store-expense-share.php';

(function () use ($t) {
    $db = fx_db();

    // the sheet for د.هێرش, month 8 of 2026, at 50 of each
    $t->assertSame(['doctor' => 962500.0, 'center' => 962500.0], store_share_split(1925000, 50.0), 'کۆگا 1,925,000 at 50 is 962,500 each');
    $t->assertSame(['doctor' => 16285500.0, 'center' => 16285500.0], store_share_split(32571000, 50.0), 'implant 32,571,000 at 50 is 16,285,500 each');
    $odd = store_share_split(1001, 33.33);
    $t->assertSame(1001.0, $odd['doctor'] + $odd['center'], 'the two parts always add up to the amount');
    $t->assertSame(['doctor' => null, 'center' => null], store_share_split(5000, null), 'no percentage, no division');
    $t->assertSame(['doctor' => 0.0, 'center' => 5000.0], store_share_split(5000, 0.0), 'zero is a percentage: all on لێزان');

    $t->assertSame(null, store_share_clean(''), 'an empty box is not set');
    $t->assertSame(null, store_share_clean('abc'), 'text is not a percentage');
    $t->assertSame(100.0, store_share_clean('250'), 'held to 100');
    $t->assertSame(0.0, store_share_clean('-5'), 'held to 0');

    $db->beginTransaction();
    try {
        $hersh = fx_user('doctor');
        $unset = fx_user('doctor');
        $admin = fx_user('admin');

        store_share_save([$hersh => ['normal' => '50', 'implant' => '40']], $admin);
        $saved = $db->fetch('SELECT store_share_normal, store_share_implant, commission_rate FROM users WHERE id = :id', ['id' => $hersh]);
        $t->assertSame(50.0, (float)$saved['store_share_normal'], 'the ئاسایی percentage is saved');
        $t->assertSame(40.0, (float)$saved['store_share_implant'], 'the ئیمپلانت percentage is saved, apart from it');
        $t->assertTrue((float)$saved['commission_rate'] === 0.0, 'the income percentage is left alone');

        $day = '2031-03-04';
        $add = fn($doctor, $kind, $amount) => $db->insert('expenses_store', [
            'expense_kind' => $kind, 'doctor_id' => $doctor, 'description' => 'test', 'amount' => $amount, 'expense_date' => $day,
        ]);
        $add($hersh, 'normal', 1000000);
        $add($hersh, 'normal', 925000);
        $add($hersh, 'implant', 1000);
        $add($unset, 'normal', 700);
        $add(null, 'implant', 300);

        $rows = store_share_rows('e.expense_date BETWEEN :from AND :to', ['from' => $day, 'to' => $day]);
        $by = [];
        foreach ($rows as $row) {
            $by[(int)$row['doctor_id']] = $row;
        }
        $t->assertSame(3, count($rows), 'a row for each doctor, and one for no doctor');
        $t->assertSame(0, (int)$rows[2]['doctor_id'], 'no doctor comes last');
        $t->assertSame(1925000.0, $by[$hersh]['normal']['amount'], 'a doctor\'s ئاسایی adds up');
        $t->assertSame(962500.0, $by[$hersh]['normal']['doctor'], '50 of ئاسایی on the doctor');
        $t->assertSame(962500.0, $by[$hersh]['normal']['center'], 'the rest on لێزان');
        $t->assertSame(400.0, $by[$hersh]['implant']['doctor'], 'ئیمپلانت goes by its own percentage');
        $t->assertSame(600.0, $by[$hersh]['implant']['center'], 'and the rest of it on لێزان');
        $t->assertSame(null, $by[$unset]['normal']['doctor'], 'a doctor with no percentage is not divided');
        $t->assertSame(0.0, $by[$unset]['implant']['doctor'], 'but nothing spent of a kind needs no percentage');
        $t->assertSame(0.0, $by[0]['implant']['doctor'], 'no doctor: nothing on a doctor');
        $t->assertSame(300.0, $by[0]['implant']['center'], 'no doctor: all on لێزان');

        $t->assertSame(962900.0, store_share_both($by[$hersh], 'doctor'), 'ئاسایی and ئیمپلانت together, on the doctor');
        $t->assertSame(963100.0, store_share_both($by[$hersh], 'center'), 'and together, on لێزان');
        $t->assertSame(null, store_share_both($by[$unset], 'doctor'), 'not added up while a spent kind has no percentage');

        $onDoctors = store_share_on_doctors($day, $day);
        $t->assertSame(962900.0, $onDoctors[$hersh], 'the income sheet gets what fell on the doctor');
        $t->assertSame(null, $onDoctors[$unset], 'and no figure for a doctor with no percentage');
        $t->assertSame(2, count($onDoctors), 'the expenses with no doctor are not on the income sheet');
        $t->assertSame('—', store_share_cell(['storeDoctor' => null], 'strval'), 'a missing percentage shows as —');
        $t->assertSame('', store_share_cell(['label' => 'OPG'], 'strval'), 'a row with no doctor stays empty');
        $t->assertSame('962900', store_share_cell(['storeDoctor' => 962900.0], 'strval'), 'a doctor\'s part is shown');

        $only = store_share_rows('e.expense_date BETWEEN :from AND :to AND e.expense_kind = :kind', ['from' => $day, 'to' => $day, 'kind' => 'implant']);
        $t->assertSame(2, count($only), 'the page\'s filter applies');

        store_share_save([$hersh => ['normal' => '', 'implant' => '40']], $admin);
        $cleared = $db->fetch('SELECT store_share_normal FROM users WHERE id = :id', ['id' => $hersh]);
        $t->assertSame(null, $cleared['store_share_normal'], 'emptying the box clears the percentage');
    } finally {
        $db->rollback();
    }
})();
