/*
 * ERP / Store module — trading documents.
 *
 * Shared conventions across every document below:
 *   invoice_number  the human number (SAL-2026-000001). Never expose the id.
 *   status          draft = no stock/ledger effect yet; confirmed = posted;
 *                   cancelled = reversed by opposing movements, never deleted.
 *   subtotal        sum of line totals BEFORE header discount and tax
 *   grand_total     subtotal - discount_amount + tax_amount
 *   paid_amount     cash taken against this document at save time
 *
 * Line rows keep unit_cost as well as unit_price. The price is what the
 * customer paid; the cost is the Weighted Average Cost consumed at that exact
 * moment. Profit reports read the stored cost, never today's purchase price,
 * so re-pricing a product can never retroactively rewrite past profit.
 */

CREATE TABLE IF NOT EXISTS erp_purchase_invoices (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number    VARCHAR(40) NOT NULL,
    supplier_invoice_no VARCHAR(60) NULL,
    supplier_id       INT UNSIGNED NOT NULL,
    warehouse_id      INT UNSIGNED NOT NULL,
    invoice_date      DATE NOT NULL,
    subtotal          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_amount        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    grand_total       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    paid_amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_type      ENUM('cash','credit','partial') NOT NULL DEFAULT 'cash',
    status            ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes             TEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        INT NULL,
    updated_at        DATETIME NULL,
    updated_by        INT NULL,
    cancelled_at      DATETIME NULL,
    cancelled_by      INT NULL,
    UNIQUE KEY uq_erp_pi_number (invoice_number),
    KEY idx_erp_pi_supplier (supplier_id),
    KEY idx_erp_pi_wh (warehouse_id),
    KEY idx_erp_pi_date (invoice_date),
    KEY idx_erp_pi_status (status),
    KEY idx_erp_pi_created (created_at),
    CONSTRAINT fk_erp_pi_supplier FOREIGN KEY (supplier_id) REFERENCES erp_suppliers (id),
    CONSTRAINT fk_erp_pi_wh FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_purchase_items (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      BIGINT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    unit_id         INT UNSIGNED NULL,
    unit_factor     DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    quantity        DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    batch_number    VARCHAR(80) NULL,
    expiry_date     DATE NULL,
    returned_qty    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    KEY idx_erp_pit_invoice (invoice_id),
    KEY idx_erp_pit_product (product_id),
    CONSTRAINT fk_erp_pit_invoice FOREIGN KEY (invoice_id)
        REFERENCES erp_purchase_invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_pit_product FOREIGN KEY (product_id) REFERENCES erp_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_purchase_returns (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_number       VARCHAR(40) NOT NULL,
    original_invoice_id BIGINT UNSIGNED NULL,
    supplier_id         INT UNSIGNED NOT NULL,
    warehouse_id        INT UNSIGNED NOT NULL,
    return_date         DATE NOT NULL,
    subtotal            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    grand_total         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    reason              VARCHAR(255) NULL,
    status              ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          INT NULL,
    UNIQUE KEY uq_erp_pr_number (return_number),
    KEY idx_erp_pr_supplier (supplier_id),
    KEY idx_erp_pr_original (original_invoice_id),
    KEY idx_erp_pr_date (return_date),
    KEY idx_erp_pr_wh (warehouse_id),
    CONSTRAINT fk_erp_pr_supplier FOREIGN KEY (supplier_id) REFERENCES erp_suppliers (id),
    CONSTRAINT fk_erp_pr_wh FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses (id),
    CONSTRAINT fk_erp_pr_original FOREIGN KEY (original_invoice_id)
        REFERENCES erp_purchase_invoices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_purchase_return_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id   BIGINT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    unit_price  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    line_total  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    KEY idx_erp_prit_return (return_id),
    KEY idx_erp_prit_product (product_id),
    CONSTRAINT fk_erp_prit_return FOREIGN KEY (return_id)
        REFERENCES erp_purchase_returns (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_prit_product FOREIGN KEY (product_id) REFERENCES erp_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_sales_invoices (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number  VARCHAR(40) NOT NULL,
    customer_id     INT UNSIGNED NOT NULL,
    warehouse_id    INT UNSIGNED NOT NULL,
    invoice_date    DATE NOT NULL,
    subtotal        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    grand_total     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_cost      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_type    ENUM('cash','credit','partial') NOT NULL DEFAULT 'cash',
    status          ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT NULL,
    updated_at      DATETIME NULL,
    updated_by      INT NULL,
    cancelled_at    DATETIME NULL,
    cancelled_by    INT NULL,
    UNIQUE KEY uq_erp_si_number (invoice_number),
    KEY idx_erp_si_customer (customer_id),
    KEY idx_erp_si_wh (warehouse_id),
    KEY idx_erp_si_date (invoice_date),
    KEY idx_erp_si_status (status),
    KEY idx_erp_si_created (created_at),
    KEY idx_erp_si_creator (created_by),
    KEY idx_erp_si_date_status (invoice_date, status),
    CONSTRAINT fk_erp_si_customer FOREIGN KEY (customer_id) REFERENCES erp_customers (id),
    CONSTRAINT fk_erp_si_wh FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * unit_cost here is the WAC consumed when this line was sold. line_profit is
 * stored rather than derived so the profit reports stay a plain indexed SUM
 * instead of re-deriving cost across a million rows.
 */
CREATE TABLE IF NOT EXISTS erp_sales_items (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      BIGINT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    unit_id         INT UNSIGNED NULL,
    unit_factor     DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
    quantity        DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    unit_cost       DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_cost       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_profit     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    returned_qty    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    KEY idx_erp_sit_invoice (invoice_id),
    KEY idx_erp_sit_product (product_id),
    CONSTRAINT fk_erp_sit_invoice FOREIGN KEY (invoice_id)
        REFERENCES erp_sales_invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_sit_product FOREIGN KEY (product_id) REFERENCES erp_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_sales_returns (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_number       VARCHAR(40) NOT NULL,
    original_invoice_id BIGINT UNSIGNED NULL,
    customer_id         INT UNSIGNED NOT NULL,
    warehouse_id        INT UNSIGNED NOT NULL,
    return_date         DATE NOT NULL,
    subtotal            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    grand_total         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_cost          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    reason              VARCHAR(255) NULL,
    status              ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by          INT NULL,
    UNIQUE KEY uq_erp_sr_number (return_number),
    KEY idx_erp_sr_customer (customer_id),
    KEY idx_erp_sr_original (original_invoice_id),
    KEY idx_erp_sr_date (return_date),
    KEY idx_erp_sr_wh (warehouse_id),
    CONSTRAINT fk_erp_sr_customer FOREIGN KEY (customer_id) REFERENCES erp_customers (id),
    CONSTRAINT fk_erp_sr_wh FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses (id),
    CONSTRAINT fk_erp_sr_original FOREIGN KEY (original_invoice_id)
        REFERENCES erp_sales_invoices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_sales_return_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id   BIGINT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    unit_price  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    line_total  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_cost   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    KEY idx_erp_srit_return (return_id),
    KEY idx_erp_srit_product (product_id),
    CONSTRAINT fk_erp_srit_return FOREIGN KEY (return_id)
        REFERENCES erp_sales_returns (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_srit_product FOREIGN KEY (product_id) REFERENCES erp_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_customer_payments (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number VARCHAR(40) NOT NULL,
    customer_id    INT UNSIGNED NOT NULL,
    payment_date   DATE NOT NULL,
    amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','bank','card','other') NOT NULL DEFAULT 'cash',
    reference      VARCHAR(80) NULL,
    notes          TEXT NULL,
    status         ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by     INT NULL,
    UNIQUE KEY uq_erp_cp_number (payment_number),
    KEY idx_erp_cp_customer (customer_id),
    KEY idx_erp_cp_date (payment_date),
    KEY idx_erp_cp_created (created_at),
    CONSTRAINT fk_erp_cp_customer FOREIGN KEY (customer_id) REFERENCES erp_customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_supplier_payments (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number VARCHAR(40) NOT NULL,
    supplier_id    INT UNSIGNED NOT NULL,
    payment_date   DATE NOT NULL,
    amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','bank','card','other') NOT NULL DEFAULT 'cash',
    reference      VARCHAR(80) NULL,
    notes          TEXT NULL,
    status         ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by     INT NULL,
    UNIQUE KEY uq_erp_sp_number (payment_number),
    KEY idx_erp_sp_supplier (supplier_id),
    KEY idx_erp_sp_date (payment_date),
    KEY idx_erp_sp_created (created_at),
    CONSTRAINT fk_erp_sp_supplier FOREIGN KEY (supplier_id) REFERENCES erp_suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Allocation of a payment onto specific invoices. doc_type distinguishes the
 * two payment kinds so one table serves customers and suppliers.
 */
CREATE TABLE IF NOT EXISTS erp_invoice_payments (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_kind ENUM('customer','supplier') NOT NULL,
    payment_id   BIGINT UNSIGNED NOT NULL,
    invoice_id   BIGINT UNSIGNED NOT NULL,
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_erp_ip_payment (payment_kind, payment_id),
    KEY idx_erp_ip_invoice (payment_kind, invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_stock_transfers (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_number VARCHAR(40) NOT NULL,
    from_warehouse_id INT UNSIGNED NOT NULL,
    to_warehouse_id   INT UNSIGNED NOT NULL,
    transfer_date   DATE NOT NULL,
    status          ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT NULL,
    UNIQUE KEY uq_erp_tr_number (transfer_number),
    KEY idx_erp_tr_from (from_warehouse_id),
    KEY idx_erp_tr_to (to_warehouse_id),
    KEY idx_erp_tr_date (transfer_date),
    CONSTRAINT fk_erp_tr_from FOREIGN KEY (from_warehouse_id) REFERENCES erp_warehouses (id),
    CONSTRAINT fk_erp_tr_to FOREIGN KEY (to_warehouse_id) REFERENCES erp_warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_stock_transfer_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    KEY idx_erp_trit_transfer (transfer_id),
    KEY idx_erp_trit_product (product_id),
    CONSTRAINT fk_erp_trit_transfer FOREIGN KEY (transfer_id)
        REFERENCES erp_stock_transfers (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_trit_product FOREIGN KEY (product_id) REFERENCES erp_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_stock_adjustments (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    adjustment_number VARCHAR(40) NOT NULL,
    warehouse_id      INT UNSIGNED NOT NULL,
    adjustment_date   DATE NOT NULL,
    reason            ENUM('OPENING','COUNT','DAMAGE','EXPIRED','LOST','FOUND','CORRECTION') NOT NULL,
    status            ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes             TEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by        INT NULL,
    UNIQUE KEY uq_erp_adj_number (adjustment_number),
    KEY idx_erp_adj_wh (warehouse_id),
    KEY idx_erp_adj_date (adjustment_date),
    KEY idx_erp_adj_reason (reason),
    CONSTRAINT fk_erp_adj_wh FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * qty_before / qty_after record what the count actually was and became, so a
 * stock take is reviewable long after the fact. difference is signed:
 * positive adds stock, negative removes it.
 */
CREATE TABLE IF NOT EXISTS erp_stock_adjustment_items (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    adjustment_id BIGINT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED NOT NULL,
    qty_before    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    qty_after     DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    difference    DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    unit_cost     DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    notes         VARCHAR(255) NULL,
    KEY idx_erp_adjit_adj (adjustment_id),
    KEY idx_erp_adjit_product (product_id),
    CONSTRAINT fk_erp_adjit_adj FOREIGN KEY (adjustment_id)
        REFERENCES erp_stock_adjustments (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_adjit_product FOREIGN KEY (product_id) REFERENCES erp_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
