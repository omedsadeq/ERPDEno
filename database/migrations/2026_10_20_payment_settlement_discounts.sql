-- A later collection can propose an ADDITIONAL discount on existing debt.
-- payments.amount and payment_allocations remain cash only. The discount
-- becomes part of the original invoice only when accounting approves it.
ALTER TABLE payments ADD COLUMN discount_mode ENUM('amount','percent') NOT NULL DEFAULT 'amount';
ALTER TABLE payments ADD COLUMN discount_value DECIMAL(15,2) NOT NULL DEFAULT 0;
ALTER TABLE payments ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0;
ALTER TABLE payments ADD COLUMN discount_reason VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS payment_discount_allocations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    invoice_discount_before DECIMAL(15,2) NULL,
    invoice_mode_before VARCHAR(10) NULL,
    invoice_value_before DECIMAL(15,2) NULL,
    invoice_discount_after DECIMAL(15,2) NULL,
    UNIQUE KEY uq_payment_discount_invoice (payment_id, invoice_id),
    KEY idx_payment_discount_invoice (invoice_id),
    CONSTRAINT fk_pda_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_pda_invoice FOREIGN KEY (invoice_id) REFERENCES case_invoices(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payment_discount_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
