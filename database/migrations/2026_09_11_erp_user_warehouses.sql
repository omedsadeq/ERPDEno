/*
 * Keeping the shop and the main store from mixing.
 *
 * The clinic buys into one main store, moves goods to a shop that has its own
 * cashier, and sells materials to its own doctors. Nothing about that needs a
 * second application — it needs warehouses that people cannot reach past.
 *
 * Two additions:
 *
 * 1. erp_user_warehouses — which warehouses a user may work in. Without it the
 *    shop cashier can pick the main store from the dropdown and sell the
 *    clinic's bulk stock as if it were shop stock, which is exactly the mixing
 *    being guarded against.
 *
 *    IMPORTANT DEFAULT: a user with NO rows here may use EVERY warehouse.
 *    Restriction is opt-in. Defaulting to "no access" would lock every
 *    existing account out of the ERP the moment this migration ran on a live
 *    system, which is a far worse failure than the one it prevents. An admin
 *    assigns the cashier to the shop, and only that user becomes restricted.
 *
 * 2. erp_customers.user_id — a staff member (doctor) as an ERP customer.
 *    Selling materials to a doctor is a normal sale: they get an invoice, a
 *    balance and a statement. Without this link every doctor has to be typed
 *    in by hand and matched by name, which is how two "Dr Hersh" customers
 *    with half the debt each appear.
 */

CREATE TABLE IF NOT EXISTS erp_user_warehouses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by   INT NULL,
    UNIQUE KEY uq_erp_uw (user_id, warehouse_id),
    KEY idx_erp_uw_user (user_id),
    CONSTRAINT fk_erp_uw_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_uw_wh FOREIGN KEY (warehouse_id)
        REFERENCES erp_warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Staff link on the customer record. Nullable and ON DELETE SET NULL, so the
 * ERP keeps working for ordinary walk-in customers who are nobody's user
 * account, and a removed staff account leaves its invoice history intact.
 */
ALTER TABLE erp_customers
    ADD COLUMN user_id INT NULL AFTER patient_id;

ALTER TABLE erp_customers
    ADD CONSTRAINT fk_erp_cust_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE erp_customers
    ADD UNIQUE KEY uq_erp_cust_user (user_id);

/*
 * The shop's own stock, separate from the main store it is supplied from.
 * Goods reach it by transfer, which already carries their cost, so the shop's
 * profit is measured against what the goods actually cost the clinic.
 */
INSERT IGNORE INTO erp_warehouses (code, name, location, is_default, is_active) VALUES
    ('SHOP', 'کۆگای فرۆشگا', NULL, 0, 1);
