-- Canonical installment payments may also be funded from patient custody.
ALTER TABLE installment_payments
    MODIFY payment_method ENUM('cash','card','transfer','other','advance') NULL DEFAULT 'cash';

-- Freeze the cash part of a funded payment, including mixed cash/card receipts.
-- A reopened payment can be funded again; old allocations remain as history.
ALTER TABLE payments ADD COLUMN advance_cash_amount DECIMAL(15,2) NULL;
