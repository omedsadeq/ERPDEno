<?php // tests/cases/HrPayrollEditTest.php
// A paid salary (HrPayroll): it can be charged to a doctor and edited in full,
// month and calculated pay included. Pinned: the expense follows every change,
// a doctor named on it carries the whole amount, and one person still has one
// row per month.
require_once __DIR__ . '/../../classes/HrPayroll.php';
require_once __DIR__ . '/../../doctors/_daily_summary_lib.php';

(function () use ($t) {
    $savedSession = $_SESSION;
    $db = fx_db();
    $admin = fx_user('admin');
    $_SESSION = ['user_id' => $admin, 'role' => 'admin', 'logged_in' => true];
    $payroll = new HrPayroll();
    $employee = random_int(900000, 999999);   // the ids are the attendance device's, not auto-numbered
    $db->insert('hr_employees', ['id' => $employee, 'full_name' => 'ZZ ' . uniqid(), 'employee_type' => 'staff', 'basic_salary' => 500000, 'status' => 'active']);
    $doctor = fx_user('doctor');
    $other = fx_user('doctor');
    $db->update('users', ['commission_rate' => 40], 'id IN (?, ?)', [$doctor, $other]);
    $throws = function (callable $fn) { try { $fn(); return false; } catch (InvalidArgumentException $e) { return true; } };
    $expense = fn($id) => $db->fetch('SELECT * FROM expenses_general WHERE id = ?', [$id]);
    $payrollRow = fn($month) => $db->fetch('SELECT * FROM hr_payroll WHERE employee_id = ? AND pay_month = ?', [$employee, $month]);
    $pay = fn($month, array $item = []) => $payroll->payOne($month, $employee, $item, '2026-09-02', 'cash', true, false, null, $admin);
    $edit = fn($id, array $item, bool $asExpense = true) => $payroll->editPayment($id, $item, '2026-09-02', 'cash', $asExpense, false, $admin);

    // ── paid in a doctor's name ──
    $t->assertTrue($throws(fn() => $pay('2026-08', ['doctor_id' => $admin])), 'only a doctor can carry a salary');
    $id = $pay('2026-08', ['doctor_id' => $doctor]);
    $receipt = $payroll->receipt($id);
    $t->assertSame($doctor, (int)$receipt['doctor_id'], 'the receipt shows the doctor');
    $exp = $expense($receipt['expense_id']);
    $t->assertSame($doctor, (int)$exp['doctor_id'], 'the expense is in his name');
    $ds = ds_compute_range($db, $doctor, '2026-09-01', '2026-09-30', false);
    $t->assertEqualsMoney(500000, $ds['generalExpense'], 'it reaches his figures');
    $t->assertEqualsMoney(-500000, $ds['doctorShare'], 'all of it comes off his share');

    // ── the doctor changed, then removed ──
    $edit($id, ['doctor_id' => $other, 'calculated' => 500000]);
    $t->assertSame($other, (int)$expense($receipt['expense_id'])['doctor_id'], 'another doctor takes it over');
    $t->assertEqualsMoney(0, ds_compute_range($db, $doctor, '2026-09-01', '2026-09-30', false)['generalExpense'], 'the first is free of it');
    $edit($id, ['doctor_id' => 0, 'calculated' => 500000]);
    $t->assertSame(null, $expense($receipt['expense_id'])['doctor_id'], 'no doctor: the centre pays it');

    // ── moved to another month ──
    $edit($id, ['month' => '2026-07', 'calculated' => 500000]);
    $t->assertSame('2026-07', $payroll->receipt($id)['pay_month'], 'the receipt moved');
    $t->assertTrue((bool)$payrollRow('2026-07'), 'the payroll row moved');
    $t->assertTrue(!$payrollRow('2026-08'), 'the old month is free again');
    $t->assertTrue(strpos($expense($receipt['expense_id'])['description'], '7 / 2026') !== false, 'the expense says the new month');
    $t->assertTrue($throws(fn() => $edit($id, ['month' => '2026-13', 'calculated' => 500000])), 'a month that does not exist is refused');

    // ── one row per person and month ──
    $second = $pay('2026-06');
    $t->assertTrue($throws(fn() => $edit($id, ['month' => '2026-06', 'calculated' => 500000])), 'a month already paid is refused');
    $payroll->approve('2026-05', [$employee => ['bonus' => 0, 'deduction' => 0, 'note' => '']], $admin);
    $t->assertTrue($throws(fn() => $edit($id, ['month' => '2026-05', 'calculated' => 500000])), 'a month already approved is refused');
    $t->assertSame('2026-07', $payroll->receipt($id)['pay_month'], 'a refused move changes nothing');

    // ── the calculated pay ──
    $db->update('hr_salary_receipts', ['signature' => 'x', 'signed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    $t->assertSame(false, $edit($id, ['month' => '2026-04', 'calculated' => 500000]), 'a new month alone keeps the signature');
    $t->assertSame('x', $payroll->receipt($id)['signature'], 'it is still there');
    $t->assertSame(true, $edit($id, ['month' => '2026-04', 'calculated' => 600000, 'bonus' => 50000, 'deduction' => 20000]), 'a new amount clears it');
    $row = $payroll->receipt($id);
    $t->assertEqualsMoney(630000, $row['amount'], 'calculated + bonus − deduction');
    $t->assertEqualsMoney(600000, $row['calculated'], 'the receipt keeps the calculated pay');
    $t->assertEqualsMoney(600000, $payrollRow('2026-04')['calculated'], 'so does the payroll row');
    $t->assertEqualsMoney(630000, $expense($row['expense_id'])['amount'], 'the expense follows');
    $t->assertTrue($throws(fn() => $edit($id, ['calculated' => 0])), 'an amount of nothing is refused');

    // ── no expense: nothing is charged ──
    $edit($id, ['doctor_id' => $doctor, 'calculated' => 600000], false);
    $t->assertTrue(!$expense($row['expense_id']), 'taken off the expenses, the row goes');
    $t->assertSame(null, $payroll->receipt($id)['doctor_id'], 'and no doctor is left on it');

    $payroll->deletePayment($id, $admin);
    $payroll->deletePayment($second, $admin);
    $_SESSION = $savedSession;
})();
