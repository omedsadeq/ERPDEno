/*
 * ERP / Store module — customers, ledgers and the stock engine.
 *
 * The stock engine is the heart of the module: erp_stock_movements is the
 * append-only truth, and erp_warehouse_product_stock is a cache of it that
 * exists purely so the sales grid does not have to SUM a million rows to show
 * one quantity. Everything in the cache can be rebuilt from the movements.
 */

/*
 * ERP customers are their OWN table, never the dental patients table.
 * patient_id is an optional link so a walk-in customer can later be tied to a
 * patient record; it is nullable and ON DELETE SET NULL, so the ERP keeps
 * working whether or not the dental module is in use.
 */
CREATE TABLE IF NOT EXISTS erp_customers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_code   VARCHAR(30) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    phone           VARCHAR(40) NULL,
    address         VARCHAR(255) NULL,
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit_limit    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes           TEXT NULL,
    patient_id      INT NULL,
    is_walk_in      TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT NULL,
    UNIQUE KEY uq_erp_cust_code (customer_code),
    KEY idx_erp_cust_name (name),
    KEY idx_erp_cust_phone (phone),
    KEY idx_erp_cust_active (is_active),
    KEY idx_erp_cust_patient (patient_id),
    CONSTRAINT fk_erp_cust_patient FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE SET NULL,
    CONSTRAINT fk_erp_cust_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Cached stock per warehouse+product, plus the Weighted Average Cost.
 *
 * average_cost is DECIMAL(15,4): WAC divides by quantity constantly, and
 * rounding the running cost to 2 places lets error accumulate across
 * thousands of receipts until the profit report visibly drifts.
 */
CREATE TABLE IF NOT EXISTS erp_warehouse_product_stock (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    quantity     DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    average_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    updated_at   DATETIME NULL,
    UNIQUE KEY uq_erp_wps (warehouse_id, product_id),
    KEY idx_erp_wps_product (product_id),
    KEY idx_erp_wps_qty (quantity),
    CONSTRAINT fk_erp_wps_wh FOREIGN KEY (warehouse_id)
        REFERENCES erp_warehouses (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_wps_product FOREIGN KEY (product_id)
        REFERENCES erp_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Append-only stock ledger. Never UPDATE or DELETE a row here: a correction is
 * a new opposing movement, which is what keeps the history auditable and lets
 * the cache above be rebuilt at any time.
 *
 * transaction_id points at the document that caused the movement (a sales
 * invoice id, a transfer id...). It is intentionally NOT a foreign key: it is
 * polymorphic across nine document types, and a movement must survive as
 * history even if its parent document is later cancelled.
 *
 * unit_cost is the cost consumed/added by THIS movement, captured at the time
 * it happened. The profit report reads it rather than today's purchase price.
 */
CREATE TABLE IF NOT EXISTS erp_stock_movements (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id       INT UNSIGNED NOT NULL,
    warehouse_id     INT UNSIGNED NOT NULL,
    transaction_type ENUM('PURCHASE','PURCHASE_RETURN','SALE','SALE_RETURN',
                          'TRANSFER_IN','TRANSFER_OUT','ADJUSTMENT','DAMAGE','OPENING') NOT NULL,
    transaction_id   BIGINT UNSIGNED NULL,
    transaction_item_id BIGINT UNSIGNED NULL,
    document_number  VARCHAR(40) NULL,
    quantity_in      DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    quantity_out     DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    unit_cost        DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    balance_after    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    batch_number     VARCHAR(80) NULL,
    expiry_date      DATE NULL,
    notes            VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by       INT NULL,
    KEY idx_erp_mov_prod_wh (product_id, warehouse_id, id),
    KEY idx_erp_mov_wh (warehouse_id),
    KEY idx_erp_mov_type (transaction_type),
    KEY idx_erp_mov_doc (transaction_type, transaction_id),
    KEY idx_erp_mov_created (created_at),
    KEY idx_erp_mov_creator (created_by),
    CONSTRAINT fk_erp_mov_product FOREIGN KEY (product_id)
        REFERENCES erp_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_mov_wh FOREIGN KEY (warehouse_id)
        REFERENCES erp_warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Party ledgers. Debit/credit are kept as two positive columns rather than one
 * signed amount, because that is how the printed statement reads and it stops
 * a sign error from silently reversing a balance.
 *
 * Customer: sale = debit, payment = credit, sales return = credit.
 * Supplier: purchase = credit, payment = debit, purchase return = debit.
 */
CREATE TABLE IF NOT EXISTS erp_customer_ledger (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    entry_date  DATE NOT NULL,
    doc_type    ENUM('OPENING','SALE','SALE_RETURN','PAYMENT','ADJUSTMENT') NOT NULL,
    doc_id      BIGINT UNSIGNED NULL,
    doc_number  VARCHAR(40) NULL,
    debit       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes       VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT NULL,
    KEY idx_erp_cl_cust_date (customer_id, entry_date, id),
    KEY idx_erp_cl_doc (doc_type, doc_id),
    KEY idx_erp_cl_created (created_at),
    CONSTRAINT fk_erp_cl_cust FOREIGN KEY (customer_id)
        REFERENCES erp_customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_supplier_ledger (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    entry_date  DATE NOT NULL,
    doc_type    ENUM('OPENING','PURCHASE','PURCHASE_RETURN','PAYMENT','ADJUSTMENT') NOT NULL,
    doc_id      BIGINT UNSIGNED NULL,
    doc_number  VARCHAR(40) NULL,
    debit       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    credit      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes       VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT NULL,
    KEY idx_erp_sl_sup_date (supplier_id, entry_date, id),
    KEY idx_erp_sl_doc (doc_type, doc_id),
    KEY idx_erp_sl_created (created_at),
    CONSTRAINT fk_erp_sl_sup FOREIGN KEY (supplier_id)
        REFERENCES erp_suppliers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Audit trail for the ERP. Separate from the dental activity_log so ERP
 * retention and reporting never interfere with clinical audit.
 */
CREATE TABLE IF NOT EXISTS erp_audit_logs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NULL,
    action       VARCHAR(60) NOT NULL,
    doc_type     VARCHAR(40) NULL,
    doc_id       BIGINT UNSIGNED NULL,
    doc_number   VARCHAR(40) NULL,
    old_value    MEDIUMTEXT NULL,
    new_value    MEDIUMTEXT NULL,
    ip_address   VARCHAR(45) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_erp_audit_user (user_id),
    KEY idx_erp_audit_doc (doc_type, doc_id),
    KEY idx_erp_audit_created (created_at),
    KEY idx_erp_audit_action (action),
    CONSTRAINT fk_erp_audit_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO erp_customers (customer_code, name, is_walk_in, is_active)
VALUES ('C000001', 'کڕیاری نەقد', 1, 1);
