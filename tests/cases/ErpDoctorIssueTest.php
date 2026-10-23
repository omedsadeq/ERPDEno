<?php // tests/cases/ErpDoctorIssueTest.php
// مەواد بۆ دکتۆر — materials leave the store at cost on a voucher in a doctor's
// name, and the voucher total becomes ONE store expense (خەرجی کۆگا) for that
// doctor, typed ئاسایی or ئیمپلانت. Pinned: stock and cost, the expense row
// and its link back, all-or-nothing on a shortage, cancel restoring both,
// and the doctor profile's existing «خەرجی کۆگا» query picking it up.
require_once __DIR__ . '/../../classes/ErpDoctorIssue.php';

$db = Database::getInstance();
$tag = substr(uniqid(), -6);
$doctor = fx_user('doctor');
$notDoctor = fx_user('receptionist');
$_SESSION['user_id'] = fx_user('accountant');

$wh = (int)$db->insert('erp_warehouses', ['code' => 'DI' . $tag, 'name' => 'Store ' . $tag, 'is_active' => 1]);
$product = function ($name, $purchase, $sale = 0) use ($db, $tag) {
    static $n = 0; $n++;
    return (int)$db->insert('erp_products', [
        'product_code' => 'DI' . $tag . $n, 'name' => $name, 'purchase_price' => $purchase, 'sale_price' => $sale, 'is_active' => 1,
    ]);
};
// The cost-based assertions below run in «نرخی کڕین» mode; sale mode is
// pinned at the end.
ErpDoctorIssue::setPriceMode('cost');
$receive = function ($pid, $qty, $cost) use ($db, $wh) {
    $db->beginTransaction();
    ErpStock::receive($pid, $wh, $qty, $cost, 'PURCHASE', null, 'TEST');
    $db->commit();
};
$composite = $product('Composite', 9000, 18000);
$implant = $product('Implant fixture', 150000, 260000);
$noCost = $product('Sample', 2500);
$receive($composite, 10, 10000);
$receive($composite, 10, 14000);           // average 12,000
$receive($implant, 3, 200000);
$db->beginTransaction();
ErpStock::receive($noCost, $wh, 5, 0, 'OPENING', null, 'TEST');  // stock with no cost on record
$db->commit();

$level = function ($pid) use ($wh) { return ErpStock::levelOf($pid, $wh); };
$expenseFor = function ($issueId) use ($db) {
    return $db->fetch("SELECT * FROM expenses_store WHERE source_type = 'erp_doctor_issue' AND source_id = :s", ['s' => $issueId]);
};
$profileStoreExpense = function ($uid) use ($db) {
    // The exact query doctors/profile.php and doctors/_daily_summary_lib.php run.
    return (float)$db->fetch(
        "SELECT COALESCE(SUM(amount), 0) AS total FROM expenses_store WHERE doctor_id = :uid AND expense_date BETWEEN :from AND :to",
        ['uid' => $uid, 'from' => date('Y-m-01'), 'to' => date('Y-m-t')]
    )['total'];
};
$throws = function (callable $fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } };

// ── refused before anything is written ──
$t->assertTrue($throws(fn() => ErpDoctorIssue::create(['doctor_id' => $doctor, 'warehouse_id' => $wh, 'items' => [['product_id' => $composite, 'quantity' => 1]]])),
    'no kind → refused (ئاسایی یان ئیمپلانت must be chosen)');
$t->assertTrue($throws(fn() => ErpDoctorIssue::create(['expense_kind' => 'normal', 'doctor_id' => $notDoctor, 'warehouse_id' => $wh, 'items' => [['product_id' => $composite, 'quantity' => 1]]])),
    'a non-doctor account cannot receive materials');
$t->assertTrue($throws(fn() => ErpDoctorIssue::create(['expense_kind' => 'normal', 'doctor_id' => $doctor, 'warehouse_id' => $wh, 'items' => []])),
    'an empty voucher is refused');

// ── a normal voucher ──
$r = ErpDoctorIssue::create([
    'expense_kind' => 'normal', 'doctor_id' => $doctor, 'warehouse_id' => $wh, 'issue_date' => date('Y-m-d'),
    'items' => [['product_id' => $composite, 'quantity' => 2], ['product_id' => $composite, 'quantity' => 1]],
]);
$t->assertTrue(strpos($r['issue_number'], 'DRI-' . date('Y') . '-') === 0, 'numbered DRI-YYYY-…');
$t->assertSame(1, $r['lines'], 'the same material twice is one line');
$t->assertSame('cost', $r['price_mode'], 'priced per the saved setting (نرخی کڕین)');
$t->assertEqualsMoney(36000, $r['total_amount'], '3 × average cost 12,000');
$t->assertEqualsMoney(17, $level($composite)['quantity'], 'stock went down by 3');
$exp = $expenseFor($r['id']);
$t->assertTrue((bool)$exp, 'one store expense row was written');
$t->assertSame($doctor, (int)$exp['doctor_id'], '… in the doctor\'s name');
$t->assertSame('normal', $exp['expense_kind'], '… typed ئاسایی');
$t->assertEqualsMoney(36000, $exp['amount'], '… for the voucher total');
$t->assertSame($r['issue_number'], $exp['invoice_number'], '… carrying the voucher number');
$t->assertSame((int)$r['expense_id'], (int)$exp['id'], 'the voucher points at its expense');
$t->assertEqualsMoney(36000, $profileStoreExpense($doctor), 'the doctor profile\'s خەرجی کۆگا includes it');
$mv = $db->fetch("SELECT * FROM erp_stock_movements WHERE transaction_type = 'DOCTOR_ISSUE' AND transaction_id = :id", ['id' => $r['id']]);
$t->assertEqualsMoney(3, $mv['quantity_out'], 'a DOCTOR_ISSUE movement explains the units');

// ── an implant voucher, and a material with no cost on record ──
$r2 = ErpDoctorIssue::create([
    'expense_kind' => 'implant', 'doctor_id' => $doctor, 'warehouse_id' => $wh,
    'items' => [['product_id' => $implant, 'quantity' => 1], ['product_id' => $noCost, 'quantity' => 2]],
]);
$t->assertEqualsMoney(205000, $r2['total_amount'], 'implant 200,000 + 2 × purchase price 2,500 when no cost is on record');
$t->assertSame('implant', $expenseFor($r2['id'])['expense_kind'], 'expense typed ئیمپلانت');
$t->assertSame([], $r2['zero_cost'], 'nothing went out at zero cost');

// ── a shortage writes nothing at all ──
$before = (int)$db->fetch("SELECT COUNT(*) c FROM erp_doctor_issues")['c'];
$t->assertTrue($throws(fn() => ErpDoctorIssue::create([
    'expense_kind' => 'implant', 'doctor_id' => $doctor, 'warehouse_id' => $wh,
    'items' => [['product_id' => $composite, 'quantity' => 1], ['product_id' => $implant, 'quantity' => 50]],
])), 'more than the warehouse holds is refused');
$t->assertSame($before, (int)$db->fetch("SELECT COUNT(*) c FROM erp_doctor_issues")['c'], 'no voucher left behind');
$t->assertEqualsMoney(17, $level($composite)['quantity'], 'the first line did not leave stock either');

// ── lists and totals ──
$list = ErpDoctorIssue::search(['from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'doctor_id' => $doctor]);
$t->assertEqualsMoney(36000, $list['normal'], 'list total: ئاسایی');
$t->assertEqualsMoney(205000, $list['implant'], 'list total: ئیمپلانت');
$onlyImplant = ErpDoctorIssue::search(['from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'doctor_id' => $doctor, 'kind' => 'implant']);
$t->assertSame(1, count($onlyImplant['rows']), 'the kind filter narrows the list');

// ── cancel ──
$c = ErpDoctorIssue::cancel($r['id'], 'wrong doctor');
$t->assertSame(1, $c['restored_lines'], 'cancel restores the lines');
$t->assertEqualsMoney(20, $level($composite)['quantity'], 'stock is back');
$t->assertEqualsMoney(12000, $level($composite)['average_cost'], '… at the cost it left with');
$t->assertTrue(!$expenseFor($r['id']), 'its expense row is gone');
$t->assertEqualsMoney(205000, $profileStoreExpense($doctor), 'the doctor is no longer charged for it');
$after = ErpDoctorIssue::load($r['id']);
$t->assertSame('cancelled', $after['status'], 'the voucher stays, marked cancelled');
$t->assertSame('wrong doctor', $after['cancel_reason'], '… with its reason');
$t->assertTrue($throws(fn() => ErpDoctorIssue::cancel($r['id'])), 'a voucher cannot be cancelled twice');
$t->assertSame(1, ErpDoctorIssue::search(['from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'doctor_id' => $doctor])['count'],
    'totals count confirmed vouchers only');

// ── «نرخی فرۆشتن»: the doctor is charged the sale price, stock still leaves at cost ──
ErpDoctorIssue::setPriceMode('sale');
$t->assertSame('sale', ErpDoctorIssue::priceMode(), 'the price setting is saved');
$t->assertTrue($throws(fn() => ErpDoctorIssue::setPriceMode('free')), 'an unknown price mode is refused');
$r3 = ErpDoctorIssue::create([
    'expense_kind' => 'normal', 'doctor_id' => $doctor, 'warehouse_id' => $wh,
    'items' => [['product_id' => $composite, 'quantity' => 2], ['product_id' => $noCost, 'quantity' => 1]],
]);
$t->assertSame('sale', $r3['price_mode'], 'the voucher records its price mode');
$t->assertEqualsMoney(2 * 18000 + 2500, $r3['total_amount'], 'charged: 2 × sale price 18,000, plus the no-sale-price item at cost');
$t->assertEqualsMoney(2 * 12000 + 2500, $r3['total_cost'], 'stock value that left: still the cost');
$t->assertSame(['Sample'], $r3['no_sale_price'], 'a material with no sale price is reported');
$t->assertEqualsMoney($r3['total_amount'], $expenseFor($r3['id'])['amount'], 'خەرجی کۆگا gets the charged total');
$mv3 = $db->fetch("SELECT unit_cost FROM erp_stock_movements WHERE transaction_type = 'DOCTOR_ISSUE' AND transaction_id = :id AND product_id = :p",
    ['id' => $r3['id'], 'p' => $composite]);
$t->assertEqualsMoney(12000, $mv3['unit_cost'], 'the stock movement is at cost, not at the sale price');
$items3 = ErpDoctorIssue::load($r3['id'])['items'];
$t->assertEqualsMoney(18000, $items3[0]['unit_price'], 'the line keeps the price charged');
$t->assertEqualsMoney(12000, $items3[0]['unit_cost'], '… and the cost that left');
ErpDoctorIssue::cancel($r3['id']);
$t->assertEqualsMoney(12000, $level($composite)['average_cost'], 'cancelling a sale-priced voucher returns stock at cost');
