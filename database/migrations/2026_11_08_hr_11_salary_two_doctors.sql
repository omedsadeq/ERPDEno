-- A salary often falls on TWO doctors (500,000: 200,000 on one, 300,000 on the
-- other). The receipt then writes two «مووچە» general expenses, one per doctor
-- with its share; expense_id is the first, expense2_id the second's.
ALTER TABLE hr_salary_receipts ADD COLUMN expense2_id INT NULL AFTER expense_id;
