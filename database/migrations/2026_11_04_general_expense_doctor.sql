-- A general expense written in a doctor's name now comes out of his income like
-- a lab or store cost.
--
-- The form has always sent doctor_id, but no migration added the column: the
-- running databases have it, a database built from the migrations does not.
-- On the running ones the ADD is refused as a duplicate, which is expected.
ALTER TABLE expenses_general ADD COLUMN doctor_id INT NULL DEFAULT NULL AFTER category_id;
ALTER TABLE expenses_general ADD INDEX idx_expenses_general_doctor (doctor_id);

-- A settlement keeps it beside the lab and store figures, for the signed paper.
ALTER TABLE income_settlements ADD COLUMN general_expense DECIMAL(17,2) NOT NULL DEFAULT 0.00 AFTER store_expense;
