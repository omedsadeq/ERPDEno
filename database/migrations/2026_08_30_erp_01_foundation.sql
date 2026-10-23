/*
 * ERP / Store module — Phase 1 foundation
 * Categories, brands, units, warehouses, products, number sequences.
 *
 * Every table is prefixed erp_ and is independent of the dental schema. The
 * ONLY cross-module reference is users(id) for created_by / manager_id, which
 * is deliberate: the ERP reuses the existing authentication rather than
 * duplicating it, per the module brief.
 *
 * NOTE for whoever edits this file: MigrationRunner strips everything after a
 * "- -" pair on a line and splits statements on ";", so this file uses only
 * block comments and contains no stored routines.
 *
 * COSTING METHOD: Weighted Average Cost (WAC), used consistently across the
 * whole ERP. Chosen over FIFO because the brief's own summary table carries a
 * single average_cost per warehouse+product, which is exactly WAC's state.
 * FIFO would need a cost-layer table and layer consumption on every sale.
 *
 * There is deliberately NO products.current_stock column. Stock is derived
 * from erp_stock_movements and cached in erp_warehouse_product_stock, so it
 * can always be rebuilt from the movement history.
 */

CREATE TABLE IF NOT EXISTS erp_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    name_ar     VARCHAR(150) NULL,
    parent_id   INT UNSIGNED NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_erp_cat_parent (parent_id),
    KEY idx_erp_cat_active (is_active),
    KEY idx_erp_cat_name (name),
    CONSTRAINT fk_erp_cat_parent FOREIGN KEY (parent_id)
        REFERENCES erp_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_brands (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    name_ar     VARCHAR(150) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_erp_brand_active (is_active),
    KEY idx_erp_brand_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Units. `code` is the short form shown in grids (pcs, box, kg).
 * decimals = how many decimal places this unit allows, so Piece can be forced
 * to whole numbers while Kg accepts 2.500.
 */
CREATE TABLE IF NOT EXISTS erp_units (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(80) NOT NULL,
    name_ar     VARCHAR(80) NULL,
    decimals    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_erp_unit_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_warehouses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(30) NOT NULL,
    name        VARCHAR(150) NOT NULL,
    location    VARCHAR(255) NULL,
    manager_id  INT NULL,
    is_default  TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_erp_wh_code (code),
    KEY idx_erp_wh_active (is_active),
    CONSTRAINT fk_erp_wh_manager FOREIGN KEY (manager_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Suppliers are created here (before products) because erp_products carries a
 * default supplier_id. Customers live in migration 02 with the ledgers.
 */
CREATE TABLE IF NOT EXISTS erp_suppliers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_code   VARCHAR(30) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    phone           VARCHAR(40) NULL,
    address         VARCHAR(255) NULL,
    contact_person  VARCHAR(150) NULL,
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit_limit    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes           TEXT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT NULL,
    UNIQUE KEY uq_erp_sup_code (supplier_code),
    KEY idx_erp_sup_name (name),
    KEY idx_erp_sup_phone (phone),
    KEY idx_erp_sup_active (is_active),
    CONSTRAINT fk_erp_sup_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Products. Sized for the brief's 100k-row target:
 *   barcode / product_code  unique lookups for the scanner
 *   idx_erp_prod_name       prefix search (name LIKE 'amox%') uses this
 *   ft_erp_prod_search      FULLTEXT for word-anywhere search across the three
 *                           name languages, so the search endpoint never needs
 *                           a leading-wildcard LIKE over the whole table
 *
 * barcode is UNIQUE but NULLable: MySQL allows many NULLs in a unique index,
 * so products without a barcode do not collide with each other.
 */
CREATE TABLE IF NOT EXISTS erp_products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_code    VARCHAR(50) NOT NULL,
    barcode         VARCHAR(64) NULL,
    name            VARCHAR(200) NOT NULL,
    name_ar         VARCHAR(200) NULL,
    name_ku         VARCHAR(200) NULL,
    category_id     INT UNSIGNED NULL,
    brand_id        INT UNSIGNED NULL,
    unit_id         INT UNSIGNED NULL,
    supplier_id     INT UNSIGNED NULL,
    purchase_price  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    sale_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    min_sale_price  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    wholesale_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    min_stock       DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    max_stock       DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    track_batch     TINYINT(1) NOT NULL DEFAULT 0,
    track_expiry    TINYINT(1) NOT NULL DEFAULT 0,
    expiry_date     DATE NULL,
    batch_number    VARCHAR(80) NULL,
    image_path      VARCHAR(255) NULL,
    notes           TEXT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT NULL,
    updated_at      DATETIME NULL,
    updated_by      INT NULL,
    UNIQUE KEY uq_erp_prod_code (product_code),
    UNIQUE KEY uq_erp_prod_barcode (barcode),
    KEY idx_erp_prod_name (name),
    KEY idx_erp_prod_cat (category_id),
    KEY idx_erp_prod_brand (brand_id),
    KEY idx_erp_prod_supplier (supplier_id),
    KEY idx_erp_prod_active (is_active),
    KEY idx_erp_prod_expiry (expiry_date),
    KEY idx_erp_prod_active_name (is_active, name),
    FULLTEXT KEY ft_erp_prod_search (name, name_ar, name_ku),
    CONSTRAINT fk_erp_prod_cat FOREIGN KEY (category_id)
        REFERENCES erp_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_erp_prod_brand FOREIGN KEY (brand_id)
        REFERENCES erp_brands (id) ON DELETE SET NULL,
    CONSTRAINT fk_erp_prod_unit FOREIGN KEY (unit_id)
        REFERENCES erp_units (id) ON DELETE SET NULL,
    CONSTRAINT fk_erp_prod_supplier FOREIGN KEY (supplier_id)
        REFERENCES erp_suppliers (id) ON DELETE SET NULL,
    CONSTRAINT fk_erp_prod_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Alternate selling units, e.g. 1 Box = 10 Pieces.
 * factor is how many BASE units (erp_products.unit_id) one of this unit holds.
 * A box can carry its own barcode so scanning a carton sells 10 pieces.
 */
CREATE TABLE IF NOT EXISTS erp_product_units (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id  INT UNSIGNED NOT NULL,
    unit_id     INT UNSIGNED NOT NULL,
    factor      DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    barcode     VARCHAR(64) NULL,
    sale_price  DECIMAL(15,2) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_erp_pu (product_id, unit_id),
    UNIQUE KEY uq_erp_pu_barcode (barcode),
    KEY idx_erp_pu_product (product_id),
    CONSTRAINT fk_erp_pu_product FOREIGN KEY (product_id)
        REFERENCES erp_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_pu_unit FOREIGN KEY (unit_id)
        REFERENCES erp_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Document numbering: SAL-2026-000001 etc. One row per
 * (doc_type, year, warehouse) so sequences can differ per branch/warehouse.
 * warehouse_id = 0 means "shared across all warehouses"; it is 0 rather than
 * NULL because MySQL unique keys treat NULLs as distinct, which would let two
 * shared sequences exist for the same type and silently duplicate numbers.
 * next_number is bumped inside the document's own transaction with
 * SELECT ... FOR UPDATE, so concurrent tills cannot take the same number.
 */
CREATE TABLE IF NOT EXISTS erp_number_sequences (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_type     VARCHAR(20) NOT NULL,
    year         SMALLINT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL DEFAULT 0,
    prefix       VARCHAR(12) NOT NULL,
    padding      TINYINT UNSIGNED NOT NULL DEFAULT 6,
    next_number  INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at   DATETIME NULL,
    UNIQUE KEY uq_erp_seq (doc_type, year, warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Seed data. INSERT IGNORE keeps re-runs harmless, and MigrationRunner
 * additionally treats duplicate-entry (1062) as a non-error.
 */
INSERT IGNORE INTO erp_units (code, name, name_ar, decimals) VALUES
    ('pcs',   'دانە',     'قطعة',   0),
    ('box',   'کارتۆن',   'كرتون',  0),
    ('pack',  'پاکێت',    'باكيت',  0),
    ('bottle','شووشە',    'قنينة',  0),
    ('set',   'سێت',      'طقم',    0),
    ('kg',    'کیلۆگرام', 'كيلوغرام', 3),
    ('gram',  'گرام',     'غرام',   3),
    ('meter', 'مەتر',     'متر',    2);

INSERT IGNORE INTO erp_warehouses (code, name, location, is_default, is_active) VALUES
    ('MAIN',   'کۆگای سەرەکی',      NULL, 1, 1),
    ('DENTAL', 'کەرەستەی ددان',     NULL, 0, 1),
    ('CLINIC', 'ستۆری کلینیک',      NULL, 0, 1),
    ('DAMAGE', 'کۆگای زیانلێکەوتوو', NULL, 0, 1);

INSERT IGNORE INTO erp_categories (name, name_ar, is_active) VALUES
    ('گشتی', 'عام', 1);
