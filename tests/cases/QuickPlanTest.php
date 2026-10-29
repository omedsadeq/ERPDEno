<?php // tests/cases/QuickPlanTest.php
// «پلانی چارەسەر» in one click (TreatmentPlan::quickOpen): the doctor's plan
// begun today, or a new one — the same one on the next click; another doctor
// gets their own; yesterday's or a cancelled plan is not reused. And
// «ئیشی ئەرک» (addArchItems) adds whole-arch work to any plan, beside its teeth.
require_once __DIR__ . '/../../classes/TreatmentPlan.php';

(function () use ($t) {
    $db = fx_db(); $plans = new TreatmentPlan();
    $docA = fx_user('doctor'); $docB = fx_user('doctor'); $patient = fx_patient();

    $first = $plans->quickOpen($patient, $docA, $docA);
    $t->assertTrue($first > 0, 'first click: a plan is made');
    $p = $plans->getById($first);
    $t->assertSame(['open', 'tooth', $docA], [$p['status'], $p['scope_type'], (int)$p['doctor_id']], 'first click: an open plan, on the doctor');
    $t->assertSame(null, $p['chief_complaint'], 'first click: nothing asked — only its day');

    $t->assertSame($first, $plans->quickOpen($patient, $docA, $docA), 'second click: the same plan, not another');
    $other = $plans->quickOpen($patient, $docB, $docB);
    $t->assertTrue($other > 0 && $other !== $first, 'another doctor: a plan of their own');

    // a mixed plan: a tooth, then «ئیشی ئەرک» on both arches
    $t->assertTrue(!empty($plans->addItem($first, ['treatment_name' => 'ZZ پڕکردنەوە', 'tooth_number' => 16, 'unit_price' => 50000])['success']), 'a tooth job');
    $arch = $plans->addArchItems($first, ['treatment_name' => 'ZZ زنجیر', 'unit_price' => 400000], 'both');
    $t->assertSame(2, $arch['count'] ?? 0, 'both arches: two rows, one an arch');
    $rows = $db->fetchAll('SELECT tooth_number, arch_type, final_price FROM treatment_plan_items WHERE plan_id = ? ORDER BY id', [$first]);
    $t->assertSame([['16', 'none'], [null, 'upper'], [null, 'lower']],
        array_map(fn($r) => [$r['tooth_number'] === null ? null : (string)$r['tooth_number'], $r['arch_type']], $rows), 'tooth and arch work side by side');
    $t->assertSame(850000.0, (float)$plans->getById($first)['total_final'], 'the totals count both');

    $db->update('treatment_plans', ['created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))], 'id = ?', [$first]);
    $next = $plans->quickOpen($patient, $docA, $docA);
    $t->assertTrue($next > 0 && $next !== $first, "yesterday's plan: a new one for today");
    $db->update('treatment_plans', ['status' => 'cancelled'], 'id = ?', [$next]);
    $again = $plans->quickOpen($patient, $docA, $docA);
    $t->assertTrue($again > 0 && $again !== $next, 'a cancelled plan is not reused');

    $t->assertSame(null, $plans->quickOpen($patient, 0, $docA), 'no doctor: nothing is made');
})();
