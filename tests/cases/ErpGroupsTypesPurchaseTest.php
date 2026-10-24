<?php // tests/cases/ErpGroupsTypesPurchaseTest.php
// کۆگا brought in line with the pharmacy: گرووپ / جۆر / براند, and editing or
// merging posted purchase invoices. Pinned: the migration turns an old
// پۆل › جۆر child category into a type of its group and moves its products;
// ErpPurchase::update restates stock and the supplier account and refuses to
// take back goods already used; ErpPurchase::merge keeps the supplier balance.
require_once __DIR__ . '/../../classes/ErpPurchase.php';
require_once __DIR__ . '/../../classes/ErpStock.php';

$db  = Database::getInstance();
$tag = substr(uniqid(), -6);
$_SESSION['user_id'] = fx_user('admin');

// ── the migration's conversion, run on fresh rows ──
$sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_25_erp_groups_types.sql');
preg_match_all('/^(INSERT IGNORE INTO erp_product_types|UPDATE erp_products p|UPDATE erp_categories c)\b.*?;/ms', $sql, $m);
$t->assertSame(3, count($m[0]), 'the three conversion statements are found in the migration');

$group = (int)$db->insert('erp_categories', ['name' => 'Endo ' . $tag, 'is_active' => 1]);
$child = (int)$db->insert('erp_categories', ['name' => 'Files ' . $tag, 'parent_id' => $group, 'is_active' => 1]);
$inChild = (int)$db->insert('erp_products', ['product_code' => 'GT' . $tag . 'a', 'name' => 'K-File ' . $tag, 'category_id' => $child, 'is_active' => 1]);
$inGroup = (int)$db->insert('erp_products', ['product_code' => 'GT' . $tag . 'b', 'name' => 'Sealer ' . $tag, 'category_id' => $group, 'is_active' => 1]);
foreach ($m[0] as $stmt) {
    $db->query($stmt);
}
$type = $db->fetch("SELECT * FROM erp_product_types WHERE category_id = :g", ['g' => $group]);
$t->assertTrue($type && $type['name'] === 'Files ' . $tag, 'the child category became a type of its group');
$p = $db->fetch("SELECT category_id, type_id FROM erp_products WHERE id = :id", ['id' => $inChild]);
$t->assertSame($group, (int)$p['category_id'], 'its product moved up to the group');
$t->assertSame((int)$type['id'], (int)$p['type_id'], '… with the old child as its type');
$p = $db->fetch("SELECT category_id, type_id FROM erp_products WHERE id = :id", ['id' => $inGroup]);
$t->assertTrue((int)$p['category_id'] === $group && $p['type_id'] === null, 'a product filed on the group itself is untouched');
$t->assertSame(0, (int)$db->fetch("SELECT is_active FROM erp_categories WHERE id = :id", ['id' => $child])['is_active'],
    'the old child row is switched off, not deleted');
foreach ($m[0] as $stmt) {
    $db->query($stmt);
}
$t->assertSame(1, (int)$db->fetch("SELECT COUNT(*) c FROM erp_product_types WHERE category_id = :g", ['g' => $group])['c'],
    'running it again changes nothing');

// ── editing a posted purchase ──
$wh  = (int)$db->insert('erp_warehouses', ['code' => 'GT' . $tag, 'name' => 'GT ' . $tag, 'is_active' => 1]);
$sup = fn($n) => (int)$db->insert('erp_suppliers', ['supplier_code' => 'GT' . $tag . $n, 'name' => 'Supplier ' . $n . ' ' . $tag, 'is_active' => 1]);
$supA = $sup('A');
$supB = $sup('B');
$prod = fn($n) => (int)$db->insert('erp_products', ['product_code' => 'GTP' . $tag . $n, 'name' => 'Item ' . $n . ' ' . $tag, 'is_active' => 1]);
$x = $prod('x');
$y = $prod('y');
$level   = fn($pid) => ErpStock::levelOf($pid, $wh);
$balance = fn($s) => (float)$db->fetch("SELECT COALESCE(SUM(credit - debit), 0) b FROM erp_supplier_ledger WHERE supplier_id = :s", ['s' => $s])['b'];
$throws  = function (callable $fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } };
$line    = fn($pid, $q, $price) => ['product_id' => $pid, 'quantity' => $q, 'unit_price' => $price];

$inv = ErpPurchase::create(['supplier_id' => $supA, 'warehouse_id' => $wh, 'items' => [$line($x, 10, 1000)], 'paid_amount' => 0]);
$t->assertSame(10.0, $level($x)['quantity'], 'the purchase brought 10 in');
$t->assertEqualsMoney(10000, $balance($supA), 'the supplier is owed 10,000');

$res = ErpPurchase::update((int)$inv['id'], ['supplier_id' => $supA, 'items' => [$line($x, 7, 1200), $line($y, 2, 500)], 'paid_amount' => 1000]);
$t->assertSame($inv['invoice_number'], $res['invoice_number'], 'the invoice keeps its number');
$t->assertSame(7.0, $level($x)['quantity'], 'edited to 7: stock follows');
$t->assertEqualsMoney(1200, $level($x)['average_cost'], 'and the cost is the edited price, not a blend');
$t->assertSame(2.0, $level($y)['quantity'], 'a new line came in');
$t->assertEqualsMoney(9400 - 1000, $balance($supA), 'the supplier account is restated: 8,400 + 1,000 − 1,000 paid');
$t->assertSame(2, (int)$db->fetch("SELECT COUNT(*) c FROM erp_purchase_items WHERE invoice_id = :id", ['id' => $inv['id']])['c'],
    'the invoice holds the edited lines');

$db->beginTransaction();
ErpStock::issue($x, $wh, 5, 'SALE', 999999, 'TEST', []);
$db->commit();
$t->assertTrue($throws(fn() => ErpPurchase::update((int)$inv['id'], ['supplier_id' => $supA, 'items' => [$line($x, 1, 1200)]])),
    'goods already used cannot be edited away (7 in, 5 used, edited to 1)');
$t->assertSame(2.0, $level($x)['quantity'], '… and nothing moved');
$t->assertEqualsMoney(8400, $balance($supA), '… the account neither');

// ── merging ──
$inv2 = ErpPurchase::create(['supplier_id' => $supA, 'warehouse_id' => $wh, 'items' => [$line($y, 3, 500)], 'paid_amount' => 500]);
$before = $balance($supA);
$merged = ErpPurchase::merge([(int)$inv2['id'], (int)$inv['id']]);
$t->assertSame((int)$inv['id'], (int)$merged['id'], 'merged into the earliest invoice');
$t->assertEqualsMoney($before, $balance($supA), 'the supplier balance does not move');
$t->assertSame(null, $db->fetch("SELECT id FROM erp_purchase_invoices WHERE id = :id", ['id' => $inv2['id']]) ?: null, 'the other invoice is gone');
$t->assertSame(3, (int)$db->fetch("SELECT COUNT(*) c FROM erp_purchase_items WHERE invoice_id = :id", ['id' => $inv['id']])['c'],
    'its line moved over');
$t->assertEqualsMoney(9400 + 1500, $db->fetch("SELECT grand_total FROM erp_purchase_invoices WHERE id = :id", ['id' => $inv['id']])['grand_total'],
    'the totals are summed');
$t->assertSame(5.0, $level($y)['quantity'], 'stock is not received twice');

$invB = ErpPurchase::create(['supplier_id' => $supB, 'warehouse_id' => $wh, 'items' => [$line($y, 1, 500)]]);
$t->assertTrue($throws(fn() => ErpPurchase::merge([(int)$inv['id'], (int)$invB['id']])), 'two suppliers are never merged');
