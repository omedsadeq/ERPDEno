-- Add payment due date for normal, non-installment case debts.
ALTER TABLE cases
    ADD COLUMN IF NOT EXISTS payment_due_date DATE NULL AFTER discount_value;

ALTER TABLE cases
    ADD INDEX idx_cases_payment_due_date (payment_due_date);
