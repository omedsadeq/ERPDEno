-- مەواد بۆ دکتۆر — the price the doctor is charged.
--
-- The clinic gives materials to its doctors at the SALE price, not at cost.
-- Which price is used is one saved setting (settings.erp_doctor_issue_price =
-- 'sale' | 'cost', read by ErpDoctorIssue::priceMode()); each voucher records
-- the mode it was made with.
--
-- Stock still leaves at its real weighted average cost (unit_cost /
-- total_cost), so the inventory value stays true. What the doctor is charged
-- — and what goes into خەرجی کۆگا — is unit_price / total_amount.
--
-- Vouchers made before this change were priced at cost; they are carried over
-- as such.

ALTER TABLE erp_doctor_issues
    ADD COLUMN price_mode ENUM('sale','cost') NOT NULL DEFAULT 'cost' AFTER expense_kind;
ALTER TABLE erp_doctor_issues
    ADD COLUMN total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER total_cost;
ALTER TABLE erp_doctor_issue_items
    ADD COLUMN unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER unit_cost;

UPDATE erp_doctor_issues SET total_amount = total_cost WHERE total_amount = 0 AND total_cost <> 0;
UPDATE erp_doctor_issue_items SET unit_price = ROUND(unit_cost, 2) WHERE unit_price = 0 AND unit_cost <> 0;
