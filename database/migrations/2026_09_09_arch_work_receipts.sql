-- وەسڵی کارە ئەنجامدراوەکان — the arch (ئەرک) counterpart of a visit invoice.
--
-- An arch job is priced ONCE when the case is opened (the case_treatments arch
-- rows on its invoice). Every later visit only records what was done, so this
-- receipt deliberately carries NO prices and creates NO new debt: it is the
-- document the doctor hands to accounting saying "this is the work done".
--
-- What the accountant settles against it is the patient's EXISTING outstanding
-- debt, so the row stores the debt it was raised against, how much was taken,
-- and the payment that took it — enough to reprint the document exactly as it
-- was approved, without a separate payment voucher.
CREATE TABLE IF NOT EXISTS arch_work_receipts (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    case_id             INT NOT NULL,
    receipt_no          VARCHAR(40) NOT NULL,
    receipt_seq         INT NOT NULL DEFAULT 1,
    work_date           DATE NOT NULL,
    status              ENUM('sent','approved','returned') NOT NULL DEFAULT 'sent',
    -- Patient-wide debt as it stood when the accountant opened the receipt.
    patient_debt_before DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_method      VARCHAR(20) DEFAULT NULL,
    payment_id          INT DEFAULT NULL,
    doctor_notes        TEXT DEFAULT NULL,
    accounting_notes    TEXT DEFAULT NULL,
    return_reason       TEXT DEFAULT NULL,
    sent_by             INT DEFAULT NULL,
    sent_at             DATETIME DEFAULT NULL,
    approved_by         INT DEFAULT NULL,
    approved_at         DATETIME DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_arch_work_receipt_no (receipt_no),
    KEY idx_arch_work_receipt_case (case_id, status),
    KEY idx_arch_work_receipt_queue (status, sent_at),
    CONSTRAINT fk_arch_work_receipt_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NULL = the log has not been sent to accounting yet. Sending groups every
-- unsent log of the case onto one receipt.
ALTER TABLE arch_procedure_logs ADD COLUMN work_receipt_id INT NULL DEFAULT NULL AFTER case_id;
ALTER TABLE arch_procedure_logs ADD KEY idx_apl_work_receipt (work_receipt_id);
