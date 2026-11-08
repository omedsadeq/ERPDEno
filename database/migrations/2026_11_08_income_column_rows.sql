-- کۆڵۆمی تایبەت on the X-ray and Pharmacy rows of the income sheet too: they
-- have costs of their own (a repair, supplies) the accountant wants under the
-- row they belong to.
--
-- An entry is on a doctor (doctor_id) OR on a row (row_kind = xray / pharmacy,
-- doctor_id NULL). A row has no doctor to share with, so the accountant says
-- on each entry whether the money left a box (row_cash_out = 1: X-ray from its
-- own box, Pharmacy from the centre's) or it only lowers the row on the sheet
-- (0). The column's own rules (cash_out, on_doctor) are for doctors.
ALTER TABLE income_column_entries MODIFY doctor_id INT NULL;
ALTER TABLE income_column_entries ADD COLUMN row_kind VARCHAR(12) NULL AFTER doctor_id;
ALTER TABLE income_column_entries ADD COLUMN row_cash_out TINYINT(1) NULL AFTER row_kind;
ALTER TABLE income_column_entries ADD KEY idx_income_column_entries_row (row_kind, entry_date);
