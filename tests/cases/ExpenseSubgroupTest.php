<?php // tests/cases/ExpenseSubgroupTest.php
// Sub-groups («لق», 2026_11_04_expense_subgroups.sql): کارەبا → مۆلیدە / حکومی.
// An expense keeps a sub-group only of its own group, and the summaries divide
// a group by them without changing its total.

require_once dirname(__DIR__, 2) . '/includes/expense-subgroup.php';

(function () use ($t) {
    $db = fx_db();

    $power = $db->fetch("SELECT id FROM expense_categories_general WHERE category_name = 'کارەبا'");
    $t->assertTrue((bool)$power, 'the group کارەبا exists');
    $names = array_column(expense_subgroups_by_group('general')[(int)$power['id']] ?? [], 'name');
    $t->assertTrue(in_array('مۆلیدە', $names, true) && in_array('حکومی', $names, true), 'کارەبا has مۆلیدە and حکومی');

    $db->beginTransaction();
    try {
        $groupA = $db->insert('expense_categories_general', ['category_name' => 'ZZ A ' . uniqid(), 'is_active' => 1]);
        $groupB = $db->insert('expense_categories_general', ['category_name' => 'ZZ B ' . uniqid(), 'is_active' => 1]);
        $sub = fn($group, $name) => $db->insert('expense_subgroups', ['kind' => 'general', 'category_id' => $group, 'name' => $name]);
        $one = $sub($groupA, 'یەک');
        $two = $sub($groupA, 'دوو');
        $other = $sub($groupB, 'یەک');
        $t->assertTrue($other > 0, 'the same name may sit under another group');

        $refused = false;
        try {
            $sub($groupA, 'یەک');
        } catch (Throwable $e) {
            $refused = true;
        }
        $t->assertTrue($refused, 'one group cannot have the same sub-group twice');

        // the form: only a sub-group of the chosen group is kept
        $_POST['subgroup_id'] = (string)$one;
        $t->assertSame((int)$one, expense_subgroup_posted('general', (int)$groupA), "the group's own sub-group is kept");
        $t->assertSame(null, expense_subgroup_posted('general', (int)$groupB), "another group's sub-group is dropped");
        $t->assertSame(null, expense_subgroup_posted('lab', (int)$groupA), "another kind's sub-group is dropped");
        $_POST['subgroup_id'] = '';
        $t->assertSame(null, expense_subgroup_posted('general', (int)$groupA), 'a sub-group is optional');
        unset($_POST['subgroup_id']);

        $expense = fn($group, $subId, $amount) => $db->insert('expenses_general', [
            'category_id' => $group, 'subgroup_id' => $subId, 'description' => 'ZZ',
            'amount' => $amount, 'expense_date' => '1999-01-15',
        ]);
        $expense($groupA, $one, 1000);
        $expense($groupA, $one, 500);
        $expense($groupA, $two, 700);
        $expense($groupA, null, 300);
        $expense($groupB, null, 900);

        $totals = expense_subgroup_totals('general', 'e.expense_date = :d', ['d' => '1999-01-15']);
        $parts = [];
        foreach ($totals[(int)$groupA] ?? [] as $part) {
            $parts[expense_subgroup_name($part)] = (float)$part['total'];
        }
        $t->assertSame(['یەک' => 1500.0, 'دوو' => 700.0, 'بێ لق' => 300.0], $parts, 'a group is divided by its sub-groups');
        $t->assertTrue(!isset($totals[(int)$groupB]), 'a group with no sub-group on its expenses is left whole');

        $t->assertSame('کارەبا · مۆلیدە', expense_group_label(['category_name' => 'کارەبا', 'subgroup_name' => 'مۆلیدە']), 'the label names both');
        $t->assertSame('کارەبا', expense_group_label(['category_name' => 'کارەبا', 'subgroup_name' => null]), 'the label without a sub-group');
    } finally {
        $db->rollback();
    }
})();
