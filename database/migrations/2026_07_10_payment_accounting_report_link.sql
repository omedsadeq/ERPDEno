-- Migration: link a payment to the accounting report it settled
-- Date: 2026-07-10
-- Part of unifying the doctor -> accountant workflow (Option B): every
-- settlement becomes provably tied to the report it cleared.

-- accounting_reports.id is INT UNSIGNED, so this column must match for the FK.
ALTER TABLE payments ADD COLUMN accounting_report_id INT UNSIGNED NULL DEFAULT NULL AFTER case_id;
ALTER TABLE payments MODIFY COLUMN accounting_report_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE payments ADD INDEX idx_payments_acct_report (accounting_report_id);
ALTER TABLE payments ADD CONSTRAINT fk_payments_accounting_report
    FOREIGN KEY (accounting_report_id) REFERENCES accounting_reports(id) ON DELETE SET NULL;
