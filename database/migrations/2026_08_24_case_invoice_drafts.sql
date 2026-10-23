-- Visit invoices become explicit BEFORE they are sent.
--
-- Until now an invoice row only appeared when the doctor pressed "send to
-- accountant", and the un-sent work was grouped by date on the fly. That made
-- "add a new visit tab" impossible to express (and forced two visits on the
-- same day into one bucket).
--
-- With 'draft' the tab IS the invoice: the doctor opens a draft, treatments are
-- stamped with its id as they are entered, and sending only flips the status.
--   draft     — the doctor's open tab; editable, not with the accountant yet
--   sent      — handed to the accountant
--   processed — fully covered by the case's money (FIFO, see CaseInvoice)
--
-- Existing rows are all 'sent' or 'processed', so widening the enum is safe.
-- Legacy treatments with invoice_id IS NULL are backfilled into per-day drafts
-- lazily by CaseInvoice::backfillDrafts() — no data migration needed here.

ALTER TABLE case_invoices
    MODIFY COLUMN status ENUM('draft','sent','processed') NOT NULL DEFAULT 'draft';
