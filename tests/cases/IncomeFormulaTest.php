<?php // tests/cases/IncomeFormulaTest.php
// فۆرمولی تایبەتی پشکی دکتۆر — classes/IncomeFormula.php read and worked out,
// then on a doctor through ds_compute_range (the scratch DB). The figures are
// the owner's Book1.xlsx row (IncomeExcelFormulaTest) and a tenth of it.
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/IncomeFormula.php';
require_once __DIR__ . '/../../doctors/_daily_summary_lib.php';

(function () use ($t) {
    // ── reading ──
    $f = ['amount' => 278740000, 'general' => 155000, 'lab' => 2500000, 'normal' => 1925000, 'implant' => 32571000,
          'rate' => 80, 'normal_rate' => 25, 'implant_rate' => 50, 'columns' => 0, 'on_doctor' => 0];
    $f['store'] = $f['normal'] + $f['implant'];
    $f['net'] = $f['amount'] - $f['general'] - $f['lab'] - $f['store'];
    $t->assertEqualsMoney(204101250, IncomeFormula::evaluate(IncomeFormula::DEFAULT_TEXT, $f), 'the sheet written as a formula gives the sheet P');
    $hersh = '(amount - general - lab - normal / 2 - implant) * rate%';
    $t->assertEqualsMoney((278740000 - 155000 - 2500000 - 962500 - 32571000) * 0.8, IncomeFormula::evaluate($hersh, $f), 'د.هێرش: half of ئاسایی and all of Implant off before the percentage');
    $t->assertEqualsMoney(IncomeFormula::evaluate($hersh, $f), IncomeFormula::evaluate('(بڕی − گشتی − تاقیگە − ئاسایی ÷ ٢ − ئیمپلانت) × ڕێژە٪', $f), 'the Kurdish names, signs and digits read the same');
    $t->assertEqualsMoney(20, IncomeFormula::evaluate('20% * 100', []), '20% is 0.2');
    $t->assertEqualsMoney(-3, IncomeFormula::evaluate('-(1 + 2)', []), 'a minus before a bracket');
    $t->assertEqualsMoney(7, IncomeFormula::evaluate('1 + 2 * 3', []), '× before +');
    $t->assertEqualsMoney(5, IncomeFormula::evaluate('max(1, 5, 3) + min(2, 0)', []), 'min and max');
    $t->assertEqualsMoney(3, IncomeFormula::evaluate('round(2.5) + abs(-0)', []), 'round and abs');
    foreach (['' => 'بەتاڵە', 'amount +' => 'تەواو نییە', 'foo * 2' => 'ناناسرێت', '(amount' => '«)»', 'amount $ 2' => 'ناناسرێت',
              'sum(1,2)' => 'فانکشن', 'min(1)' => 'ژمارەی بەهاکان', '1 2' => 'هەڵەیە'] as $bad => $word) {
        $err = IncomeFormula::check($bad);
        $t->assertTrue($err !== null && mb_strpos($err, $word) !== false, "«{$bad}» is refused: {$err}");
    }
    $t->assertSame(null, IncomeFormula::check($hersh), 'a good formula passes');
    try {
        IncomeFormula::evaluate('amount / normal', ['amount' => 5, 'normal' => 0]);
        $t->assertTrue(false, 'division by zero throws');
    } catch (InvalidArgumentException $e) {
        $t->assertTrue(mb_strpos($e->getMessage(), 'سفر') !== false, 'division by zero names itself');
    }

    // ── the share: the sheet's rule until a formula is set; a broken one falls back and says why ──
    $fd = $f + ['normal_doc' => 481250, 'implant_doc' => 16285500];
    $err = null;
    $t->assertEqualsMoney(204101250, ds_doctor_share_of(null, $fd, $err), 'no formula: the sheet');
    $t->assertEqualsMoney(IncomeFormula::evaluate($hersh, $f), ds_doctor_share_of($hersh, $fd, $err), 'a formula: its value');
    $t->assertSame(null, $err, 'and no error');
    $t->assertEqualsMoney(204101250, ds_doctor_share_of('amount / 0', $fd, $err), 'a broken formula: the sheet');
    $t->assertTrue($err !== null, 'and the reason');

    // ── on a doctor, through ds_compute_range ──
    $db = fx_db();
    $savedSession = $_SESSION;
    $admin = fx_user('admin');
    $_SESSION = ['user_id' => $admin, 'role' => 'admin', 'logged_in' => true];
    $doc = fx_user('doctor');
    $db->update('users', ['commission_rate' => 80, 'store_share_normal' => 25, 'store_share_implant' => 50], 'id = ?', [$doc]);
    $case = fx_case(fx_folder(fx_patient(), $doc));
    $inv = fx_invoice($case, $doc, 'sent', 'sent', 27874000);
    fx_treatment($case, 27874000, $inv);
    $db->update('case_invoices', ['work_date' => '2026-09-05', 'draft_received_amount' => 27874000, 'payment_intent' => 'cash'], 'id = ?', [$inv]);
    $t->assertTrue((new CaseInvoice())->approve($case, $inv, $admin)['success'], 'invoice approved');
    $db->insert('expenses_general', ['doctor_id' => $doc, 'description' => 'F', 'amount' => 15500, 'expense_date' => '2026-09-06']);
    $db->insert('expenses_lab', ['doctor_id' => $doc, 'description' => 'G', 'amount' => 250000, 'expense_date' => '2026-09-06']);
    $db->insert('expenses_store', ['doctor_id' => $doc, 'expense_kind' => 'normal', 'description' => 'I', 'amount' => 192500, 'expense_date' => '2026-09-06']);
    $db->insert('expenses_store', ['doctor_id' => $doc, 'expense_kind' => 'implant', 'description' => 'L', 'amount' => 3257100, 'expense_date' => '2026-09-06']);
    $ds = ds_compute_range($db, $doc, '2026-09-01', '2026-09-10', false);
    $t->assertEqualsMoney(20410125, $ds['doctorShare'], 'no formula: P as before');
    $t->assertSame(null, $ds['doctorFormula'], 'no formula on him');
    $t->assertEqualsMoney(25, $ds['storeRates']['normal'], 'his ئاسایی percentage comes along');

    IncomeFormula::save($doc, $hersh);
    $t->assertSame($hersh, IncomeFormula::forDoctor($doc), 'the formula is kept');
    $ds = ds_compute_range($db, $doc, '2026-09-01', '2026-09-10', false);
    $expected = round((27874000 - 15500 - 250000 - 96250 - 3257100) * 0.8);
    $t->assertEqualsMoney($expected, $ds['doctorShare'], 'his formula: the share');
    $t->assertEqualsMoney($ds['netAfterExpenses'] - $expected, $ds['centerShare'], 'the centre: the net less his');
    $t->assertEqualsMoney(24158900, $ds['netAfterExpenses'], 'the net itself is untouched');
    $rows = array_column(ds_row_lists($ds)['money'], 1, 0);
    $t->assertTrue(isset($rows['پشکی دکتۆر (فۆرمولی تایبەت)']), 'the summary names the formula');
    $t->assertSame('(192,500)', $rows['— ئاسایی'] ?? null, 'the store lines carry no division under a formula');

    // the sheet's figures, chips and all
    $fig = ds_share_figures($ds);
    $t->assertEqualsMoney(27874000, $fig['amount'], 'amount');
    $t->assertEqualsMoney(24158900, $fig['net'], 'net');
    $t->assertEqualsMoney(25, $fig['normal_rate'], 'normal_rate');
    $t->assertEqualsMoney(48125, $fig['normal_doc'], 'normal_doc');
    $fig = ds_share_figures($ds, ['lab']);
    $t->assertEqualsMoney(0, $fig['normal'] + $fig['general'] + $fig['normal_doc'], 'only the ticked kinds count');
    $t->assertEqualsMoney(27874000 - 250000, $fig['net'], 'the net under those chips');

    // a bad formula cannot be saved; '' takes it away
    try {
        IncomeFormula::save($doc, 'amount +');
        $t->assertTrue(false, 'a broken formula is refused');
    } catch (InvalidArgumentException $e) {
        $t->assertTrue(true, 'a broken formula is refused');
    }
    $t->assertSame($hersh, IncomeFormula::forDoctor($doc), 'and the old one stays');
    IncomeFormula::save($doc, '');
    $t->assertSame(null, IncomeFormula::forDoctor($doc), 'taken away');
    $t->assertEqualsMoney(20410125, ds_compute_range($db, $doc, '2026-09-01', '2026-09-10', false)['doctorShare'], 'back to the sheet');
    $_SESSION = $savedSession;
})();
