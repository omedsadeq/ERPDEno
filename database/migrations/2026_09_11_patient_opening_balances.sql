-- باڵانسی سەرەتایی / قەرزی کۆن — a patient's carried-forward balance from
-- before this system tracked their money, entered once (editable) so the
-- account-statement ledger (patients/statement.php) can post it as the
-- first row, before any case/payment/x-ray transaction.
--
-- One row per patient (not a log): this is a single opening figure, not a
-- history of edits. amount is always >= 0 — the patient OWES this much
-- carried forward; a patient who starts in credit is out of scope (no such
-- case existed when this was built) and should simply have no row here.
CREATE TABLE IF NOT EXISTS patient_opening_balances (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT NOT NULL,
    amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    as_of_date  DATE NOT NULL,
    notes       VARCHAR(255) DEFAULT NULL,
    created_by  INT DEFAULT NULL,
    updated_by  INT DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_patient_opening_balance (patient_id),
    CONSTRAINT fk_pob_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_pob_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pob_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
