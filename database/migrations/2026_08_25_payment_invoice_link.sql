-- Which visit receipt a payment belongs to.
--
-- Money is still owned by the CASE — annotateFifo() keeps pouring the case's
-- paid_amount over the receipts in invoice_seq order, and per-invoice "paid"
-- stays derived, never stored. This column records only where a payment was
-- entered from, which the derivation does not capture:
--
--   * a doctor's pending request can be shown on the receipt it came from
--     (چاوەڕوانی ژمێریار) instead of being matched by parsing notes text
--   * the accountant can see which receipt each queued request belongs to
--
-- NULL = entered somewhere that isn't a receipt (the payment dialog, the
-- accountant's collect box, an installment) — every existing row.

ALTER TABLE payments
    ADD COLUMN invoice_id INT UNSIGNED NULL DEFAULT NULL AFTER case_id;

ALTER TABLE payments
    ADD KEY idx_payments_invoice (invoice_id);
