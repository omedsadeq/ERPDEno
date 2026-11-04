-- Expense groups were seeded more than once (create_separate_expense_tables.sql
-- has plain INSERTs and no unique name), so every group of general, lab and
-- store expenses existed up to five times — the same name five times in every
-- dropdown. Keep the first of each name, move every expense onto it, drop the
-- copies, and make the name unique so it cannot happen again.
-- (The derived tables are wrapped once more so MySQL builds them before it
-- changes the table they read from.)

-- ── گشتی ──
UPDATE expenses_general e
JOIN expense_categories_general d ON d.id = e.category_id
JOIN (SELECT * FROM (SELECT TRIM(category_name) AS n, MIN(id) AS keep_id FROM expense_categories_general GROUP BY TRIM(category_name)) x) k
  ON k.n = TRIM(d.category_name)
SET e.category_id = k.keep_id
WHERE e.category_id <> k.keep_id;

DELETE d FROM expense_categories_general d
JOIN (SELECT * FROM (SELECT TRIM(category_name) AS n, MIN(id) AS keep_id FROM expense_categories_general GROUP BY TRIM(category_name)) x) k
  ON k.n = TRIM(d.category_name)
WHERE d.id <> k.keep_id;

ALTER TABLE expense_categories_general ADD UNIQUE KEY uq_ecg_name (category_name);

-- ── تاقیگە ──
UPDATE expenses_lab e
JOIN expense_categories_lab d ON d.id = e.category_id
JOIN (SELECT * FROM (SELECT TRIM(category_name) AS n, MIN(id) AS keep_id FROM expense_categories_lab GROUP BY TRIM(category_name)) x) k
  ON k.n = TRIM(d.category_name)
SET e.category_id = k.keep_id
WHERE e.category_id <> k.keep_id;

DELETE d FROM expense_categories_lab d
JOIN (SELECT * FROM (SELECT TRIM(category_name) AS n, MIN(id) AS keep_id FROM expense_categories_lab GROUP BY TRIM(category_name)) x) k
  ON k.n = TRIM(d.category_name)
WHERE d.id <> k.keep_id;

ALTER TABLE expense_categories_lab ADD UNIQUE KEY uq_ecl_name (category_name);

-- ── کۆگا ──
UPDATE expenses_store e
JOIN expense_categories_store d ON d.id = e.category_id
JOIN (SELECT * FROM (SELECT TRIM(category_name) AS n, MIN(id) AS keep_id FROM expense_categories_store GROUP BY TRIM(category_name)) x) k
  ON k.n = TRIM(d.category_name)
SET e.category_id = k.keep_id
WHERE e.category_id <> k.keep_id;

DELETE d FROM expense_categories_store d
JOIN (SELECT * FROM (SELECT TRIM(category_name) AS n, MIN(id) AS keep_id FROM expense_categories_store GROUP BY TRIM(category_name)) x) k
  ON k.n = TRIM(d.category_name)
WHERE d.id <> k.keep_id;

ALTER TABLE expense_categories_store ADD UNIQUE KEY uq_ecs_name (category_name);
