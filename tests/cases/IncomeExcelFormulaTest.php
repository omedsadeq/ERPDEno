<?php // tests/cases/IncomeExcelFormulaTest.php
// The owner's Book1.xlsx, figure for figure. Its one row:
//   E کۆی گشتی 278,740,000 · F خەرجی گشتی 155,000 · G موختەبەر 2,500,000
//   H کۆگا 34,496,000 = I ئاسایی 1,925,000 (J دکتۆر 481,250 · K لێزان 1,443,750)
//                     + L Implant 32,571,000 (M دکتۆر 16,285,500 · N لێزان 16,285,500)
//   O = E − F − G = 276,085,000 (the store is not in it)
//   P = O × 80% − J − M = 204,101,250 · Q = O × 20% − K − N = 37,487,750
// P + Q is the net after every expense (E − F − G − H).
// The percentages are the doctor's own (80 / 25 / 50 here), not the sheet's.
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../doctors/_daily_summary_lib.php';

(function () use ($t) {
    $db = fx_db();
    // the function alone, with the sheet's figures: net = E − F − G − H
    $net = 278740000 - 155000 - 2500000 - 34496000;
    $doctor = ds_doctor_share($net, 80, 0, 34496000, 481250 + 16285500);
    $t->assertEqualsMoney(276085000, $net + 34496000, 'O: the net before the store');
    $t->assertEqualsMoney(204101250, $doctor, 'P: 80% of 276,085,000 less the 481,250 of ئاسایی and the 16,285,500 of Implant');
    $t->assertEqualsMoney(37487750, $net - $doctor, 'Q: 20% of it less the 1,443,750 of ئاسایی and the 16,285,500 of Implant');
    // the store percentages not set: his income percentage, so the plain split of the net
    $t->assertEqualsMoney(round($net * 0.8), ds_doctor_share($net, 80, 0, 34496000, 34496000 * 0.8), 'the store at his own percentage: 80% of the net');
    // a column wholly on him is his alone
    $t->assertEqualsMoney(204101250 - 100000, ds_doctor_share($net - 100000, 80, 100000, 34496000, 481250 + 16285500), 'a column marked on him comes off him whole');

    // the same through ds_compute_range, at a tenth of the sheet (one invoice)
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
    $t->assertEqualsMoney(27874000, $ds['cashIn'], 'E');
    $t->assertEqualsMoney(3449600, $ds['storeExpense'], 'H');
    $t->assertEqualsMoney(48125, $ds['storeOnDoctorByKind']['normal'], 'J');
    $t->assertEqualsMoney(1628550, $ds['storeOnDoctorByKind']['implant'], 'M');
    $t->assertEqualsMoney(27608500, $ds['netBeforeStore'], 'O');
    $t->assertEqualsMoney(20410125, $ds['doctorShare'], 'P');
    $t->assertEqualsMoney(3748775, $ds['centerShare'], 'Q');
    $t->assertEqualsMoney($ds['netAfterExpenses'], $ds['doctorShare'] + $ds['centerShare'], 'the two shares are the whole net');
    $rows = array_column(ds_row_lists($ds)['money'], 1, 0);
    $t->assertSame('(192,500)', $rows['— ئاسایی: دکتۆر 48,125 · لێزان 144,375'] ?? null, 'the summary lists ئاسایی with its division');
    $t->assertSame('(3,257,100)', $rows['— Implant: دکتۆر 1,628,550 · لێزان 1,628,550'] ?? null, 'and Implant the same way');

    // the Implant percentage not set: his income percentage carries it
    $db->update('users', ['store_share_implant' => null], 'id = ?', [$doc]);
    $ds = ds_compute_range($db, $doc, '2026-09-01', '2026-09-10', false);
    $t->assertEqualsMoney(24158900, $ds['netAfterExpenses'], 'the net is the same: every cost comes off it');
    $t->assertEqualsMoney(27608500, $ds['netBeforeStore'], 'O is the same too');
    $t->assertEqualsMoney(2605680, $ds['storeOnDoctorByKind']['implant'], 'M: 80% of the implant');
    $t->assertEqualsMoney(19432995, $ds['doctorShare'], 'P: 80% of O less 48,125 and 2,605,680');
    $t->assertEqualsMoney(4725905, $ds['centerShare'], 'the centre: the rest');
    $_SESSION = $savedSession;
})();
