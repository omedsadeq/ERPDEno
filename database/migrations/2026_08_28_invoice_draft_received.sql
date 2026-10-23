-- A chair-side received figure belongs to the editable invoice draft until the
-- doctor explicitly sends that invoice to accounting. It is not yet a payment
-- and must not appear in the accountant queue before that send action.
ALTER TABLE case_invoices
    ADD COLUMN draft_received_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_amount;
