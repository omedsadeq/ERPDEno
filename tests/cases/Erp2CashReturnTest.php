<?php // tests/cases/Erp2CashReturnTest.php
// دەرمانخانە — returning goods at the cashier and handing the cash back.
// Pinned: a line is credited at what was actually paid (line and receipt
// discount), a walk-in customer always gets the cash back (balance stays 0),
// a named one may take cash or keep it on account, cash never exceeds what was
// paid, a box comes back as its pieces (and deleting the return undoes it),
// and an invoice with a return can be neither cancelled nor edited.
require_once __DIR__ . '/../../classes/Erp2Sales.php';
require_once __DIR__ . '/../../classes/Erp2Stock.php';
require_once __DIR__ . '/../../classes/Erp2Delete.php';

$db  = Database::getInstance();
$tag = substr(uniqid(), -6);
$_SESSION['user_id'] = fx_user('admin');

$wh   = (int)$db->insert('erp2_warehouses', ['code' => 'CR' . $tag, 'name' => 'CR ' . $tag, 'is_active' => 1]);
$cust = fn($n, $walk) => (int)$db->insert('erp2_customers', ['customer_code' => 'CR' . $tag . $n, 'name' => $n . ' ' . $tag, 'is_walk_in' => $walk ? 1 : 0, 'is_active' => 1]);
$walk  = $cust('walk', true);
$named = $cust('named', false);
$other = $cust('other', false);
$prod  = fn($n) => (int)$db->insert('erp2_products', ['product_code' => 'CR' . $tag . $n, 'name' => 'Item ' . $n . ' ' . $tag, 'is_active' => 1]);
$a = $prod('a'); $b = $prod('b'); $box = $prod('box');
$db->beginTransaction();
foreach ([$a, $b, $box] as $p) { Erp2Stock::receive($p, $wh, 100, 300, 'PURCHASE', null, 'TEST'); }
$db->commit();

$level   = fn($pid) => (float)Erp2Stock::levelOf($pid, $wh)['quantity'];
$balance = fn($c) => (float)$db->fetch("SELECT COALESCE(SUM(debit - credit), 0) b FROM erp2_customer_ledger WHERE customer_id = :c", ['c' => $c])['b'];
$throws  = function (callable $fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } };
$lineOf  = fn($inv, $pid) => (int)$db->fetch("SELECT id FROM erp2_sales_items WHERE invoice_id = :i AND product_id = :p", ['i' => $inv, 'p' => $pid])['id'];
$sell    = fn($c, $items, $disc, $paid) => Erp2Sales::create(['customer_id' => $c, 'warehouse_id' => $wh, 'items' => $items,
    'discount_amount' => $disc, 'paid_amount' => $paid], ['allow_negative' => true]);
$ret     = fn($c, $inv, $lines, $extra = []) => Erp2Sales::createReturn($extra + ['customer_id' => $c, 'warehouse_id' => $wh,
    'original_invoice_id' => $inv, 'items' => $lines]);

// ── walk-in, with a receipt discount: 3 × 1,000 + 1 × 2,000 = 5,000, −500 → 4,500 paid ──
$s1 = $sell($walk, [['product_id' => $a, 'quantity' => 3, 'unit_price' => 1000], ['product_id' => $b, 'quantity' => 1, 'unit_price' => 2000]], 500, 4500);
$inv1 = (int)$s1['id'];
$t->assertEqualsMoney(0, $balance($walk), 'a paid walk-in sale leaves the walk-in account at 0');
$view = Erp2Sales::returnableLines($inv1);
$t->assertEqualsMoney(900, $view['items'][0]['refund_price'], 'the screen offers 900 a piece: 1,000 less its share of the discount');
$t->assertEqualsMoney(4500, $view['refundable'], 'all 4,500 paid may go back');

$r1 = $ret($walk, $inv1, [['item_id' => $lineOf($inv1, $a), 'product_id' => $a, 'quantity' => 1]], ['refund_amount' => 0]);
$t->assertEqualsMoney(900, $r1['grand_total'], 'one returned piece is credited at what was paid for it (900)');
$t->assertEqualsMoney(900, $r1['refund_amount'], 'a walk-in customer always gets the cash back, whatever was posted');
$t->assertEqualsMoney(0, $balance($walk), 'credit and cash back net out: the walk-in account stays at 0');
$t->assertSame(98.0, $level($a), 'the piece is back on the shelf');
$t->assertEqualsMoney(3600, Erp2Sales::returnableLines($inv1)['refundable'], 'what is left to hand back on the receipt shrank');
$t->assertTrue($throws(fn() => $ret($walk, $inv1, [['item_id' => $lineOf($inv1, $a), 'product_id' => $a, 'quantity' => 3]])),
    'never more than is still open on the line (2 left)');
$t->assertTrue($throws(fn() => Erp2Sales::cancel($inv1)), 'a receipt with a return cannot be cancelled (goods would come back twice)');
$t->assertTrue($throws(fn() => Erp2Sales::update($inv1, ['items' => [['product_id' => $a, 'quantity' => 1, 'unit_price' => 1]]])),
    '… nor edited');
$t->assertTrue($throws(fn() => $ret($named, $inv1, [['item_id' => $lineOf($inv1, $b), 'product_id' => $b, 'quantity' => 1]])),
    'goods go back to the customer who bought them, not another account');

// ── named customer on credit: nothing was paid, so no cash can go back ──
$s2 = $sell($named, [['product_id' => $a, 'quantity' => 2, 'unit_price' => 1000]], 0, 0);
$inv2 = (int)$s2['id'];
$r2 = $ret($named, $inv2, [['item_id' => $lineOf($inv2, $a), 'product_id' => $a, 'quantity' => 1]], ['refund_mode' => 'cash']);
$t->assertEqualsMoney(0, $r2['refund_amount'], 'an unpaid receipt hands no cash back, even when cash is asked');
$t->assertEqualsMoney(1000, $balance($named), 'it only lowers what is owed: 2,000 → 1,000');
$t->assertTrue($throws(fn() => $ret($named, $inv2, [['item_id' => $lineOf($inv2, $a), 'product_id' => $a, 'quantity' => 1]], ['refund_amount' => 500])),
    'a typed refund above what was paid is refused');

// ── named customer, paid: cash back or kept on account ──
$s3 = $sell($other, [['product_id' => $b, 'quantity' => 2, 'unit_price' => 1500]], 0, 3000);
$inv3 = (int)$s3['id'];
$r3 = $ret($other, $inv3, [['item_id' => $lineOf($inv3, $b), 'product_id' => $b, 'quantity' => 1]], ['refund_mode' => 'account']);
$t->assertEqualsMoney(0, $r3['refund_amount'], 'kept on account: no cash');
$t->assertEqualsMoney(-1500, $balance($other), 'the customer now has 1,500 in credit');
$r4 = $ret($other, $inv3, [['item_id' => $lineOf($inv3, $b), 'product_id' => $b, 'quantity' => 1]], ['refund_mode' => 'cash']);
$t->assertEqualsMoney(1500, $r4['refund_amount'], 'cash: the paid 1,500 goes back');
$t->assertEqualsMoney(-1500, $balance($other), 'the cash return nets out; the earlier credit stays');

// ── a box of 10 ──
$s5 = $sell($walk, [['product_id' => $box, 'quantity' => 1, 'unit_price' => 9000, 'unit_factor' => 10]], 0, 9000);
$inv5 = (int)$s5['id'];
$t->assertSame(90.0, $level($box), 'selling a box takes 10 pieces');
$r5 = $ret($walk, $inv5, [['item_id' => $lineOf($inv5, $box), 'product_id' => $box, 'quantity' => 1]]);
$t->assertSame(100.0, $level($box), 'returning the box puts its 10 pieces back');
$t->assertEqualsMoney(9000, $r5['grand_total'], 'credited at the box price');
$t->assertSame(10.0, (float)$db->fetch("SELECT quantity FROM erp2_sales_return_items WHERE return_id = :r", ['r' => $r5['id']])['quantity'],
    'the return line counts pieces');
$t->assertSame(1.0, (float)$db->fetch("SELECT returned_qty FROM erp2_sales_items WHERE id = :i", ['i' => $lineOf($inv5, $box)])['returned_qty'],
    'the sale line counts one box returned');
Erp2Delete::saleReturn((int)$r5['id']);
$t->assertSame(90.0, $level($box), 'deleting the return takes the 10 pieces out again');
$t->assertSame(0.0, (float)$db->fetch("SELECT returned_qty FROM erp2_sales_items WHERE id = :i", ['i' => $lineOf($inv5, $box)])['returned_qty'],
    '… and the box can be returned again');
$t->assertEqualsMoney(0, $balance($walk), '… and both its account rows are gone');

$t->assertTrue($throws(fn() => $ret($walk, $inv5, [['item_id' => $lineOf($inv1, $a), 'product_id' => $a, 'quantity' => 1]])),
    'a line from another receipt is refused, never taken as a goodwill return');
