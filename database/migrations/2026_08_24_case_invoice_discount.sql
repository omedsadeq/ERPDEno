-- Discounts belong to the receipt, not the case.
--
-- The clinic negotiates per visit: "this receipt gets 10% off", never "this
-- whole case gets 10% off". Until now the only discount lived on cases, so an
-- invoice's remaining balance silently depended on a case-wide figure — exactly
-- what the receipt must not do.
--
-- cases.discount_amount stays (it is derived from cases.discount_type/value by
-- CaseManager::updateCaseTotals and older records may carry one), but the
-- per-invoice figure below is what a visit receipt prints and settles against.

ALTER TABLE case_invoices
    ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount;
