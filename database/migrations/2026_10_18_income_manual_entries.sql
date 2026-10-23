-- داهاتی دەستی — pharmacy and examination money the system did not record,
-- typed in by the accountant on the income sheet (accountant/income.php).
--
-- The sheet ADDS these on top of what it already calculates: the pharmacy row
-- is erp2 sales + these, the examinations row is visits.price + these. One
-- row per amount received, dated the day the money came in; qty is what goes
-- into the sheet's ژمارە column (sales / patients), 0 when not counted.
-- Report-only: nothing here moves money in a cashbox.
CREATE TABLE IF NOT EXISTS income_manual_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_date  DATE NOT NULL,
    kind        ENUM('pharmacy','exam') NOT NULL,
    amount      DECIMAL(15,2) NOT NULL,
    qty         INT UNSIGNED NOT NULL DEFAULT 0,
    note        VARCHAR(255) DEFAULT NULL,
    created_by  INT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by  INT DEFAULT NULL,
    updated_at  DATETIME DEFAULT NULL,
    KEY idx_income_manual_date (entry_date, kind),
    CONSTRAINT fk_income_manual_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_income_manual_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
VALUES ('income.manual', 'Manual pharmacy/exam income', 'داهاتی دەستی (دەرمانخانە و پشکنین)', 'financial',
        'Add, edit and delete manual pharmacy and examination amounts on the income sheet.');
INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant', 'income.manual', 1), ('doctor', 'income.manual', 0),
('receptionist', 'income.manual', 0), ('xray_department', 'income.manual', 0);
