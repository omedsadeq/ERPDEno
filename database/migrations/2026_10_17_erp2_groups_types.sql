-- دەرمانخانە (erp2): گرووپ و جۆر — the shape of the clinic's paper
-- prescription sheet. Each group (erp2_categories: Antibiotic, Analgesic,
-- Toothpaste, Brush, Mouth Wash, Other) has its own list of types — the form a
-- medicine comes in (Tab, Capsule, Vial, Syrup, …) or, for toothpaste, the
-- brand (Splat, Lacalut, …). A product sits in one group and optionally one of
-- that group's types. sort_order keeps everything in the paper's order.

ALTER TABLE erp2_categories ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER parent_id;

CREATE TABLE IF NOT EXISTS erp2_product_types (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_erp2_ptype (category_id, name),
    KEY idx_erp2_ptype_sort (category_id, sort_order),
    CONSTRAINT fk_erp2_ptype_cat FOREIGN KEY (category_id) REFERENCES erp2_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE erp2_products ADD COLUMN IF NOT EXISTS type_id INT UNSIGNED NULL AFTER category_id;
ALTER TABLE erp2_products ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER type_id;
ALTER TABLE erp2_products ADD KEY IF NOT EXISTS idx_erp2_prod_type (type_id);
ALTER TABLE erp2_products ADD KEY IF NOT EXISTS idx_erp2_prod_cat_sort (category_id, sort_order);
ALTER TABLE erp2_products ADD CONSTRAINT fk_erp2_prod_type FOREIGN KEY IF NOT EXISTS (type_id)
    REFERENCES erp2_product_types (id) ON DELETE SET NULL;

-- The six groups of the sheet, in its order. Existing groups are reused by
-- name (the collation ignores case, so 'toothpaste' is the sheet's Toothpaste).
UPDATE erp2_categories SET name = 'Toothpaste' WHERE name = 'toothpaste';
INSERT INTO erp2_categories (name, is_active) SELECT 'Antibiotic', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM erp2_categories WHERE name = 'Antibiotic');
INSERT INTO erp2_categories (name, is_active) SELECT 'Analgesic',  1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM erp2_categories WHERE name = 'Analgesic');
INSERT INTO erp2_categories (name, is_active) SELECT 'Toothpaste', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM erp2_categories WHERE name = 'Toothpaste');
INSERT INTO erp2_categories (name, is_active) SELECT 'Brush',      1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM erp2_categories WHERE name = 'Brush');
INSERT INTO erp2_categories (name, is_active) SELECT 'Mouth Wash', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM erp2_categories WHERE name = 'Mouth Wash');
INSERT INTO erp2_categories (name, is_active) SELECT 'Other',      1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM erp2_categories WHERE name = 'Other');
UPDATE erp2_categories SET is_active = 1, sort_order = CASE name
        WHEN 'Antibiotic' THEN 1 WHEN 'Analgesic' THEN 2 WHEN 'Toothpaste' THEN 3
        WHEN 'Brush' THEN 4 WHEN 'Mouth Wash' THEN 5 WHEN 'Other' THEN 6 END
    WHERE name IN ('Antibiotic', 'Analgesic', 'Toothpaste', 'Brush', 'Mouth Wash', 'Other');
-- any other group goes after the sheet's six
UPDATE erp2_categories SET sort_order = 100 + id
    WHERE name NOT IN ('Antibiotic', 'Analgesic', 'Toothpaste', 'Brush', 'Mouth Wash', 'Other') AND sort_order = 0;

-- Types, as printed in each group's header.
INSERT IGNORE INTO erp2_product_types (category_id, name, sort_order)
SELECT c.id, t.name, t.ord
FROM erp2_categories c
JOIN (
          SELECT 'Antibiotic' AS grp, 'Tab' AS name, 1 AS ord
    UNION SELECT 'Antibiotic', 'Capsule', 2
    UNION SELECT 'Antibiotic', 'Vial', 3
    UNION SELECT 'Antibiotic', 'Syrup', 4
    UNION SELECT 'Antibiotic', 'Sachet', 5
    UNION SELECT 'Analgesic', 'Tab', 1
    UNION SELECT 'Analgesic', 'Capsule', 2
    UNION SELECT 'Analgesic', 'Ampule', 3
    UNION SELECT 'Analgesic', 'Syrup', 4
    UNION SELECT 'Analgesic', 'Powder', 5
    UNION SELECT 'Analgesic', 'Sachet', 6
    UNION SELECT 'Toothpaste', 'Splat', 1
    UNION SELECT 'Toothpaste', 'Lacalut', 2
    UNION SELECT 'Toothpaste', 'Dentissimo', 3
    UNION SELECT 'Toothpaste', 'Kin', 4
) t ON t.grp = c.name;
