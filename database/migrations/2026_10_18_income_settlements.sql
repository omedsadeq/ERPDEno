-- دابەشکردنی داهات — settling a doctor's income for a period.
-- The centre's share moves from the doctor's cashbox into قاسەی لێزان and the
-- doctor is paid his share out of his box (cashbox_entries, append-only). Each
-- settlement freezes its full report in `snapshot`, so the signed paper can
-- always be reprinted exactly as it was signed.

CREATE TABLE IF NOT EXISTS income_settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    settlement_no VARCHAR(30) NOT NULL,
    doctor_id INT NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,
    doctor_rate DECIMAL(5,2) NOT NULL,
    cash_in DECIMAL(17,2) NOT NULL DEFAULT 0,
    lab_expense DECIMAL(17,2) NOT NULL DEFAULT 0,
    store_expense DECIMAL(17,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(17,2) NOT NULL DEFAULT 0,
    doctor_share DECIMAL(17,2) NOT NULL DEFAULT 0,
    center_share DECIMAL(17,2) NOT NULL DEFAULT 0,
    box_before DECIMAL(17,2) NOT NULL DEFAULT 0,
    transferred_to_center DECIMAL(17,2) NOT NULL DEFAULT 0,
    paid_to_doctor DECIMAL(17,2) NOT NULL DEFAULT 0,
    box_after DECIMAL(17,2) NOT NULL DEFAULT 0,
    snapshot LONGTEXT NULL,
    notes VARCHAR(600) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'done',
    transfer_out_entry_id BIGINT UNSIGNED NULL,
    transfer_in_entry_id BIGINT UNSIGNED NULL,
    payout_entry_id BIGINT UNSIGNED NULL,
    signed_at DATETIME NULL,
    signed_marked_by INT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_by INT NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(600) NULL,
    UNIQUE KEY uq_income_settlement_no (settlement_no),
    KEY idx_income_settlement_doctor (doctor_id, status, period_from, period_to),
    KEY idx_income_settlement_period (period_from, period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- قاسەی لێزان: the centre's own cashbox, beside the doctors' and x-ray's.
INSERT IGNORE INTO cashbox_accounts (account_key, owner_type, doctor_id) VALUES ('center', 'center', NULL);

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
VALUES ('income.distribution', 'Income distribution', 'دابەشکردنی داهات', 'financial',
        'Settle a doctor''s income: move the centre''s share to the Lezan cashbox and pay the doctor his share.');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant', 'income.distribution', 0), ('doctor', 'income.distribution', 0), ('receptionist', 'income.distribution', 0);
