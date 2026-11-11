-- A general expense can be the X-ray department's: written on no doctor but
-- on the department (dept = 'xray'), so the income sheet takes it off the
-- X-ray row rather than counting it with the centre's general expenses.
ALTER TABLE expenses_general ADD COLUMN dept VARCHAR(12) NULL AFTER doctor_id;
ALTER TABLE expenses_general ADD INDEX idx_expenses_general_dept (dept, expense_date);
