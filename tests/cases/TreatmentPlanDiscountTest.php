<?php // tests/cases/TreatmentPlanDiscountTest.php
// داشکاندنی پلان (TreatmentPlan::setDiscount): an amount or a percentage on the
// whole plan, spread over its waiting items as their own discount — a
// percentage on each, an amount in proportion with the last item taking the
// rounding, so the parts always add up to exactly what was asked. A percentage
// reaches items added later; 0 clears it; items already moved keep theirs.
require_once __DIR__ . '/../../classes/TreatmentPlan.php';

(function () use ($t) {
    $db = fx_db(); $plans = new TreatmentPlan();
    $doctor = fx_user('doctor'); $patient = fx_patient();
    $planId = (int)$plans->create(['patient_id' => $patient, 'doctor_id' => $doctor])['plan_id'];
    $add = fn($name, $price) => (int)$plans->addItem($planId, ['treatment_name' => $name, 'unit_price' => $price])['item_id'];
    $a = $add('ZZ a', 100000); $b = $add('ZZ b', 200000); $c = $add('ZZ c', 300000);
    $disc = fn($id) => (float)$db->fetch('SELECT discount FROM treatment_plan_items WHERE id = ?', [$id])['discount'];
    $plan = fn() => $plans->getById($planId);

    $t->assertTrue(!empty($plans->setDiscount($planId, 'percent', 10, $doctor)['success']), '10% is applied');
    $t->assertSame([10000.0, 20000.0, 30000.0], [$disc($a), $disc($b), $disc($c)], '10% on each item');
    $t->assertSame([60000.0, 540000.0], [(float)$plan()['total_discount'], (float)$plan()['total_final']], 'the plan totals carry it');
    $t->assertSame('percent', $plan()['discount_mode'], 'the plan remembers it was a percentage');

    $d = $add('ZZ d', 50000);
    $t->assertSame(5000.0, $disc($d), 'an item added later gets the plan\'s 10% too');

    $t->assertTrue(!empty($plans->setDiscount($planId, 'amount', 100000, $doctor)['success']), 'an amount is applied');
    $shares = [$disc($a), $disc($b), $disc($c), $disc($d)];
    $t->assertSame(100000.0, array_sum($shares), 'the shares add up to exactly the amount');
    $t->assertTrue($shares[2] > $shares[1] && $shares[1] > $shares[0], 'in proportion to each item\'s price');
    $t->assertSame(550000.0, (float)$plan()['total_final'], '650,000 − 100,000');
    $e = $add('ZZ e', 10000);
    $t->assertSame(0.0, $disc($e), 'an amount is not spread again onto an item added later');

    $t->assertTrue(empty($plans->setDiscount($planId, 'amount', 999999, $doctor)['success']), 'more than the plan is refused');
    $t->assertTrue(empty($plans->setDiscount($planId, 'percent', 120, $doctor)['success']), 'more than 100% is refused');

    $db->update('treatment_plan_items', ['status' => 'transferred'], 'id = :id', ['id' => $a]);
    $before = $disc($a);
    $plans->setDiscount($planId, 'percent', 50, $doctor);
    $t->assertSame($before, $disc($a), 'an item moved to a case keeps the discount it took');
    $t->assertSame(100000.0, $disc($b), '50% on a waiting item');

    $plans->setDiscount($planId, 'amount', 0, $doctor);
    $t->assertSame([0.0, 0.0, 0.0], [$disc($b), $disc($c), $disc($d)], '0 clears the waiting items');
    $t->assertSame([null, 0.0], [$plan()['discount_mode'], (float)$plan()['discount_value']], 'and the plan\'s own');

    $db->update('treatment_plans', ['status' => 'cancelled'], 'id = :id', ['id' => $planId]);
    $t->assertTrue(empty($plans->setDiscount($planId, 'percent', 5, $doctor)['success']), 'a closed plan is not discounted');
})();
