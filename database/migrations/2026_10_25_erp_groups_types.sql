-- کۆگا (erp): گرووپ، جۆر و براند — the same classification as the pharmacy
-- (2026_10_17_erp2_groups_types.sql). A product sits in one group
-- (erp_categories), optionally one of that group's types (erp_product_types)
-- and optionally a brand (erp_brands).
--
-- The store used to file a جۆر as a child category under its پۆل
-- (erp_categories.parent_id). Each such child becomes a type of its parent
-- group, and its products move to the parent group with that type. The old
-- child rows are kept but switched off, so nothing that points at them breaks.
--
-- Plain ADD KEY / ADD CONSTRAINT (no IF NOT EXISTS): MySQL has no such form,
-- and a re-run's duplicate errors (1061, 1826) are ignored by MigrationRunner.

ALTER TABLE erp_categories ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER parent_id;

CREATE TABLE IF NOT EXISTS erp_product_types (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_erp_ptype (category_id, name),
    KEY idx_erp_ptype_sort (category_id, sort_order),
    CONSTRAINT fk_erp_ptype_cat FOREIGN KEY (category_id) REFERENCES erp_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE erp_products ADD COLUMN IF NOT EXISTS type_id INT UNSIGNED NULL AFTER category_id;
ALTER TABLE erp_products ADD KEY idx_erp_prod_type (type_id);
ALTER TABLE erp_products ADD CONSTRAINT fk_erp_prod_type FOREIGN KEY (type_id)
    REFERENCES erp_product_types (id) ON DELETE SET NULL;

-- Every child category of a top-level one becomes a type of it.
INSERT IGNORE INTO erp_product_types (category_id, name, sort_order, is_active)
SELECT c.parent_id, LEFT(c.name, 80), 0, c.is_active
FROM erp_categories c
JOIN erp_categories g ON g.id = c.parent_id AND g.parent_id IS NULL;

-- Its products move up to the group, keeping the old child as their type.
UPDATE erp_products p
JOIN erp_categories c ON c.id = p.category_id
JOIN erp_categories g ON g.id = c.parent_id AND g.parent_id IS NULL
JOIN erp_product_types t ON t.category_id = g.id AND t.name = LEFT(c.name, 80)
SET p.category_id = g.id, p.type_id = t.id;

-- The old child rows stay (history) but leave the group lists.
UPDATE erp_categories c
JOIN erp_categories g ON g.id = c.parent_id AND g.parent_id IS NULL
SET c.is_active = 0;

-- Groups in the order they were made; a new group is added at the end.
UPDATE erp_categories SET sort_order = id WHERE parent_id IS NULL AND sort_order = 0;

-- Types within a group by name.
UPDATE erp_product_types t
JOIN (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY category_id ORDER BY name, id) AS n
    FROM erp_product_types
) o ON o.id = t.id
SET t.sort_order = o.n
WHERE t.sort_order = 0;

-- The right to edit and merge posted purchase invoices
-- (erp/purchase-invoices.php, erp/purchases.php?edit=<id>). Admin only unless
-- a role is granted it on the permissions page — the same as the pharmacy.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp.purchase.edit' AS k, 'Edit Purchase Invoices' AS n, 'دەستکاری وەسڵی کڕین' AS ku, 'erp' AS c, NULL AS d
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'erp.purchase.edit' AS k, 0 AS a
    UNION ALL SELECT 'accountant', 'erp.purchase.edit', 0
    UNION ALL SELECT 'receptionist', 'erp.purchase.edit', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
