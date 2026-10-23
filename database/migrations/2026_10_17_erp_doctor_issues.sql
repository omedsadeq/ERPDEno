-- مەواد بۆ دکتۆر — the store (کۆگا, erp/) hands materials to a doctor.
--
-- The clinic buys materials from suppliers into its own store (purchases,
-- supplier ledger, statements: unchanged), then gives them to its doctors.
-- A doctor issue is that hand-over: one voucher in the doctor's name, stock
-- leaves the warehouse at its weighted average cost, and the voucher's total
-- becomes ONE row in خەرجی کۆگا (expenses_store) tagged with the doctor and
-- its kind — ئاسایی (normal) or ئیمپلانت (implant). That doctor_id tag is what
-- the doctor's profile and daily summary already subtract as «خەرجی کۆگا», so
-- the doctor's own account is charged by the voucher with no extra step.
--
-- It is deliberately NOT a sale: a sale would make the doctor a debtor and
-- count store revenue, and the expense would then charge the same materials
-- a second time.
--
-- Cancelling a voucher puts the stock back at the cost it left with
-- (DOCTOR_RETURN) and removes its expense row.

ALTER TABLE erp_stock_movements
    MODIFY transaction_type ENUM('PURCHASE','PURCHASE_RETURN','SALE','SALE_RETURN',
                                 'TRANSFER_IN','TRANSFER_OUT','ADJUSTMENT','DAMAGE','OPENING',
                                 'DOCTOR_ISSUE','DOCTOR_RETURN') NOT NULL;

CREATE TABLE IF NOT EXISTS erp_doctor_issues (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_number  VARCHAR(40) NOT NULL,
    doctor_id     INT NOT NULL,                       -- users.id (role doctor)
    warehouse_id  INT UNSIGNED NOT NULL,
    issue_date    DATE NOT NULL,
    expense_kind  ENUM('normal','implant') NOT NULL DEFAULT 'normal',
    total_cost    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status        ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    expense_id    INT NULL,                           -- expenses_store.id while confirmed
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT NULL,
    cancelled_at  DATETIME NULL,
    cancelled_by  INT NULL,
    cancel_reason VARCHAR(255) NULL,
    UNIQUE KEY uq_erp_di_number (issue_number),
    KEY idx_erp_di_doctor (doctor_id, issue_date),
    KEY idx_erp_di_date (issue_date, status),
    KEY idx_erp_di_kind (expense_kind, issue_date),
    CONSTRAINT fk_erp_di_wh FOREIGN KEY (warehouse_id) REFERENCES erp_warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_doctor_issue_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_id    BIGINT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    DECIMAL(15,3) NOT NULL,
    unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    line_total  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes       VARCHAR(255) NULL,
    KEY idx_erp_dii_issue (issue_id),
    KEY idx_erp_dii_product (product_id),
    CONSTRAINT fk_erp_dii_issue FOREIGN KEY (issue_id) REFERENCES erp_doctor_issues (id) ON DELETE CASCADE,
    CONSTRAINT fk_erp_dii_product FOREIGN KEY (product_id) REFERENCES erp_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- خەرجی کۆگا: its kind, and where an automatic row came from. A row with a
-- source belongs to that document: it is changed only by cancelling the
-- document, never edited or deleted on the expense page.
-- doctor_id exists on live (added by hand, no migration ever created it) and
-- is what the doctor profile reads; added here so a fresh install has it too.
ALTER TABLE expenses_store
    ADD COLUMN doctor_id INT NULL DEFAULT NULL AFTER category_id;
ALTER TABLE expenses_store
    ADD COLUMN expense_kind ENUM('normal','implant') NOT NULL DEFAULT 'normal' AFTER category_id;
ALTER TABLE expenses_store
    ADD COLUMN source_type VARCHAR(30) NULL DEFAULT NULL AFTER notes;
ALTER TABLE expenses_store
    ADD COLUMN source_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER source_type;
ALTER TABLE expenses_store ADD KEY idx_expenses_store_kind (expense_kind, expense_date);
ALTER TABLE expenses_store ADD KEY idx_expenses_store_source (source_type, source_id);
ALTER TABLE expenses_store ADD KEY idx_expenses_store_doctor (doctor_id, expense_date);

INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp.doctor_issue.view' AS k, 'View Doctor Material Vouchers' AS n,
           'بینینی وەسڵی مەواد بۆ دکتۆر' AS ku, 'erp' AS c, NULL AS d
    UNION ALL SELECT 'erp.doctor_issue.create', 'Give Materials To Doctor',
           'پێدانی مەواد بە دکتۆر', 'erp', NULL
    UNION ALL SELECT 'erp.doctor_issue.cancel', 'Cancel Doctor Material Voucher',
           'هەڵوەشاندنەوەی وەسڵی مەواد بۆ دکتۆر', 'erp', NULL
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

-- The store is run from the accountant and reception accounts today (they
-- hold the store's sales/purchase rights); cancelling stays with the admin.
INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'accountant' AS r, 'erp.doctor_issue.view' AS k, 1 AS a
    UNION ALL SELECT 'receptionist',    'erp.doctor_issue.view',   1
    UNION ALL SELECT 'doctor',          'erp.doctor_issue.view',   0
    UNION ALL SELECT 'xray_department', 'erp.doctor_issue.view',   0
    UNION ALL SELECT 'accountant',      'erp.doctor_issue.create', 1
    UNION ALL SELECT 'receptionist',    'erp.doctor_issue.create', 1
    UNION ALL SELECT 'doctor',          'erp.doctor_issue.create', 0
    UNION ALL SELECT 'xray_department', 'erp.doctor_issue.create', 0
    UNION ALL SELECT 'accountant',      'erp.doctor_issue.cancel', 0
    UNION ALL SELECT 'receptionist',    'erp.doctor_issue.cancel', 0
    UNION ALL SELECT 'doctor',          'erp.doctor_issue.cancel', 0
    UNION ALL SELECT 'xray_department', 'erp.doctor_issue.cancel', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
