-- Finish installations that picked up the earlier custody migration while
-- its payment-service work was still incomplete. Preserve reversal history:
-- a replacement/reapproved payment can consume a previously released receipt.
ALTER TABLE patient_advance_allocations DROP INDEX uq_amanat_payment_receipt;
ALTER TABLE payments ADD COLUMN advance_cash_amount DECIMAL(15,2) NULL;
ALTER TABLE installment_payments
    MODIFY payment_method ENUM('cash','card','transfer','other','advance') NULL DEFAULT 'cash';
