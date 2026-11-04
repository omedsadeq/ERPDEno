-- کۆڵۆمی تایبەت — columns the accountant adds to the income sheet
-- (accountant/income.php), with an amount on a doctor under each one.
--
-- A column says what its amounts are:
--   cash_out  1 = the money really leaves a cashbox (a patient given 100,000
--             back two weeks later); 0 = a charge, the doctor's part moves from
--             his box to the centre's, like a lab or store cost
--   on_doctor 1 = the whole amount comes off the doctor's share; 0 = it comes
--             off his income before the percentage, so he and the centre each
--             carry their part
-- These two cannot change once a column has entries: the boxes were moved by them.
-- An entry belongs to the day the money went (entry_date), so a refund lands in
-- the period it was given, not the one it was received in.
CREATE TABLE IF NOT EXISTS income_columns (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60) NOT NULL,
    cash_out    TINYINT(1) NOT NULL DEFAULT 0,
    on_doctor   TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT NULL,
    CONSTRAINT fk_income_column_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_column_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    column_id   INT UNSIGNED NOT NULL,
    doctor_id   INT NOT NULL,
    entry_date  DATE NOT NULL,
    amount      DECIMAL(15,2) NOT NULL,
    note        VARCHAR(255) DEFAULT NULL,
    created_by  INT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by  INT DEFAULT NULL,
    updated_at  DATETIME DEFAULT NULL,
    KEY idx_income_column_entries_doctor (doctor_id, entry_date),
    KEY idx_income_column_entries_date (entry_date),
    CONSTRAINT fk_income_column_entry_column FOREIGN KEY (column_id) REFERENCES income_columns(id),
    CONSTRAINT fk_income_column_entry_doctor FOREIGN KEY (doctor_id) REFERENCES users(id),
    CONSTRAINT fk_income_column_entry_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_income_column_entry_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The one the clinic asked for, ready on the sheet.
INSERT INTO income_columns (name, cash_out, on_doctor)
SELECT 'گەڕاندنەوەی پارە', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM income_columns);

-- Moves cash, so like income.distribution it starts with the admin only.
INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
VALUES ('income.columns', 'Income sheet columns', 'کۆڵۆمی تایبەتی داهات (لێبڕین و خەرجی سەر دکتۆر)', 'financial',
        'Add columns to the income sheet and put amounts on a doctor under them; a refund column takes the money out of the cashboxes.');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant', 'income.columns', 0), ('doctor', 'income.columns', 0),
('receptionist', 'income.columns', 0), ('xray_department', 'income.columns', 0);
