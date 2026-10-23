-- Physical cash custody, separate from revenue and patient balances.
-- No historical financial documents are modified. History starts 2026-10-01.
CREATE TABLE IF NOT EXISTS cashbox_accounts (
    account_key VARCHAR(48) PRIMARY KEY,
    owner_type VARCHAR(16) NOT NULL,
    doctor_id INT NULL,
    balance DECIMAL(17,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cashbox_doctor (doctor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cashbox_sources (
    source_type VARCHAR(24) NOT NULL,
    source_id INT NOT NULL,
    account_key VARCHAR(48) NOT NULL,
    booked_amount DECIMAL(17,2) NOT NULL DEFAULT 0,
    revision INT NOT NULL DEFAULT 0,
    reference_no VARCHAR(100) NOT NULL DEFAULT '',
    source_date DATETIME NOT NULL,
    case_id INT NULL,
    PRIMARY KEY (source_type, source_id),
    KEY idx_cashbox_sources_account (account_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cashbox_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_key VARCHAR(48) NOT NULL,
    entry_kind VARCHAR(24) NOT NULL,
    amount DECIMAL(17,2) NOT NULL,
    balance_after DECIMAL(17,2) NOT NULL,
    source_type VARCHAR(24) NULL,
    source_id INT NULL,
    source_revision INT NULL,
    reference_no VARCHAR(100) NOT NULL DEFAULT '',
    case_id INT NULL,
    reason VARCHAR(600) NOT NULL,
    actor_id INT NULL,
    occurred_at DATETIME NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_key VARCHAR(96) NULL,
    request_fingerprint CHAR(64) NULL,
    reverses_entry_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_cashbox_source_revision (source_type, source_id, source_revision),
    UNIQUE KEY uq_cashbox_request (request_key),
    UNIQUE KEY uq_cashbox_reversal (reverses_entry_id),
    KEY idx_cashbox_entries_account (account_key, occurred_at, id),
    KEY idx_cashbox_entries_recorded (account_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cashbox_accounts (account_key, owner_type, doctor_id)
SELECT CONCAT('doctor:', id), 'doctor', id FROM users WHERE role='doctor';
INSERT IGNORE INTO cashbox_accounts (account_key, owner_type, doctor_id) VALUES ('xray', 'xray', NULL);

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
VALUES ('page.cashboxes', 'Cashboxes', 'سندوقەکان', 'financial', 'Admin: all cashboxes. Doctors: own cashbox. X-ray: own cashbox.');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('doctor','page.cashboxes',1), ('xray_department','page.cashboxes',1),
('accountant','page.cashboxes',0), ('receptionist','page.cashboxes',0);
