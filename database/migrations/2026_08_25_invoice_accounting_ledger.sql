ALTER TABLE case_invoices
    ADD COLUMN doctor_id INT NULL AFTER case_id;

ALTER TABLE case_invoices
    ADD COLUMN accounting_status ENUM('draft','sent','approved','returned','cancelled') NOT NULL DEFAULT 'draft' AFTER status;

ALTER TABLE case_invoices
    ADD COLUMN accounting_notes TEXT NULL AFTER notes;

ALTER TABLE case_invoices
    ADD COLUMN approved_by INT NULL AFTER processed_at;

ALTER TABLE case_invoices
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by;

ALTER TABLE case_invoices
    ADD COLUMN returned_by INT NULL AFTER approved_at;

ALTER TABLE case_invoices
    ADD COLUMN returned_at DATETIME NULL AFTER returned_by;

ALTER TABLE case_invoices
    ADD COLUMN return_reason TEXT NULL AFTER returned_at;

ALTER TABLE case_invoices
    ADD COLUMN updated_at DATETIME NULL AFTER created_at;

UPDATE case_invoices ci
LEFT JOIN cases c ON c.id = ci.case_id
LEFT JOIN patient_folders pf ON pf.id = c.folder_id
SET ci.doctor_id = COALESCE(ci.sent_by, pf.doctor_id)
WHERE ci.doctor_id IS NULL;

UPDATE case_invoices
SET accounting_status = CASE
    WHEN status = 'draft' THEN 'draft'
    ELSE 'approved'
END;

ALTER TABLE case_invoices
    ADD INDEX idx_ci_accounting_status (accounting_status),
    ADD INDEX idx_ci_doctor (doctor_id),
    ADD CONSTRAINT fk_ci_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_ci_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_ci_returned_by FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE payments
    ADD COLUMN idempotency_key VARCHAR(80) NULL AFTER payment_number;

ALTER TABLE payments
    ADD COLUMN reversed_by INT NULL AFTER rejected_by;

ALTER TABLE payments
    ADD COLUMN reversed_at DATETIME NULL AFTER reversed_by;

ALTER TABLE payments
    ADD COLUMN reversal_reason TEXT NULL AFTER reversed_at;

ALTER TABLE payments
    ADD UNIQUE INDEX uq_payments_idempotency (idempotency_key),
    ADD CONSTRAINT fk_payments_reversed_by FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE payment_allocations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id INT NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    allocated_amount DECIMAL(15,2) NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_invoice (payment_id, invoice_id),
    KEY idx_pa_invoice (invoice_id),
    KEY idx_pa_creator (created_by),
    CONSTRAINT fk_pa_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pa_invoice FOREIGN KEY (invoice_id) REFERENCES case_invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pa_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_pa_amount_positive CHECK (allocated_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE installment_payments
    ADD COLUMN payment_id INT NULL AFTER plan_id;

ALTER TABLE installment_payments
    ADD UNIQUE INDEX uq_installment_payment_record (payment_id),
    ADD CONSTRAINT fk_installment_payment_record FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT;

