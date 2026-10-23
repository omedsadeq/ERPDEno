-- Doctor installment proposals: pending until accountant activates (spec §4.1)
ALTER TABLE installment_plans MODIFY COLUMN status ENUM('pending','active','completed','cancelled','rejected') DEFAULT 'active';
ALTER TABLE installment_plans ADD COLUMN proposed_down_payment DECIMAL(15,2) NULL AFTER down_payment;
ALTER TABLE installment_plans ADD COLUMN approved_by INT NULL AFTER created_by;
ALTER TABLE installment_plans ADD COLUMN approved_at DATETIME NULL AFTER approved_by;
ALTER TABLE installment_plans ADD COLUMN reject_reason VARCHAR(500) NULL AFTER approved_at;
