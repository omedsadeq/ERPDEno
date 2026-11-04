<?php // tests/cases/ExpenseCategoriesTest.php
// Expense groups (2026_11_04_expense_categories_dedupe.sql): each name once per
// kind. They were seeded up to five times, so every dropdown listed each group
// five times; the name is unique now, and the database refuses a second one.

(function () use ($t) {
    $db = fx_db();
    foreach (['expense_categories_general', 'expense_categories_lab', 'expense_categories_store'] as $table) {
        $copies = $db->fetch("SELECT COUNT(*) - COUNT(DISTINCT category_name) AS extra FROM {$table}");
        $t->assertSame(0, (int)$copies['extra'], "{$table}: every name once");

        $name = 'ZZ ' . uniqid();
        $db->insert($table, ['category_name' => $name, 'is_active' => 1]);
        $refused = false;
        try {
            $db->insert($table, ['category_name' => $name, 'is_active' => 1]);
        } catch (Throwable $e) {
            $refused = true;
        }
        $t->assertTrue($refused, "{$table}: a second group of the same name is refused");
        $db->delete($table, 'category_name = :n', ['n' => $name]);
    }
})();
