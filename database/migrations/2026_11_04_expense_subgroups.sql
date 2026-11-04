-- Sub-groups («لق») under the expense groups: کارەبا has two kinds, مۆلیدە and
-- حکومی. One table serves the three kinds of expense (each has its own group
-- table, so category_id only means something together with kind). An expense
-- may have one; the expenses written by other screens (salaries, vouchers) and
-- the ones from before this have none, so the column stays nullable.

CREATE TABLE IF NOT EXISTS expense_subgroups (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind        ENUM('general', 'lab', 'store') NOT NULL,
    category_id INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expense_subgroup (kind, category_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE expenses_general ADD COLUMN subgroup_id INT NULL AFTER category_id, ADD KEY idx_expenses_general_subgroup (subgroup_id);
ALTER TABLE expenses_lab     ADD COLUMN subgroup_id INT NULL AFTER category_id, ADD KEY idx_expenses_lab_subgroup (subgroup_id);
ALTER TABLE expenses_store   ADD COLUMN subgroup_id INT NULL AFTER category_id, ADD KEY idx_expenses_store_subgroup (subgroup_id);

INSERT IGNORE INTO expense_subgroups (kind, category_id, name)
SELECT 'general', id, 'مۆلیدە' FROM expense_categories_general WHERE category_name = 'کارەبا';

INSERT IGNORE INTO expense_subgroups (kind, category_id, name)
SELECT 'general', id, 'حکومی' FROM expense_categories_general WHERE category_name = 'کارەبا';
