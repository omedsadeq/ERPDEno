-- Visit invoices (وەسڵی سەردان): each day the doctor works on a case becomes
-- its own numbered receipt that travels to the accountant and back.
--
--   * The case stays ONE case and the chart stays cumulative — invoices only
--     partition the money side by visit day.
--   * invoice_no = case_number + '-' + seq (C20260800002-1, -2, ...).
--   * total_amount is kept in sync with SUM(final_price) of the linked
--     case_treatments rows (recomputed on any admin edit of a billed row).
--   * "Paid" per invoice is NOT stored — it is derived FIFO from the case's
--     paid_amount + discount_amount, so it can never drift from case totals.
--     status flips to 'processed' when the FIFO waterfall covers the invoice.

CREATE TABLE IF NOT EXISTS case_invoices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id INT NOT NULL,
    invoice_seq INT NOT NULL,
    invoice_no VARCHAR(60) NOT NULL,
    work_date DATE NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('sent','processed') NOT NULL DEFAULT 'sent',
    notes TEXT NULL,
    sent_by INT NULL,
    sent_at DATETIME NULL,
    processed_by INT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_case_invoice_no (invoice_no),
    UNIQUE KEY uq_case_invoice_seq (case_id, invoice_seq),
    KEY idx_case_invoices_case (case_id),
    KEY idx_case_invoices_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which invoice a treatment row is billed on. NULL = not yet invoiced.
ALTER TABLE case_treatments
    ADD COLUMN invoice_id INT UNSIGNED NULL DEFAULT NULL AFTER case_id;

ALTER TABLE case_treatments
    ADD KEY idx_case_treatments_invoice (invoice_id);
