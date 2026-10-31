-- داشکاندن on a whole treatment plan, as on a receipt: an amount or a
-- percentage (TreatmentPlan::setDiscount). It is spread over the plan's
-- waiting items as their own discount; the plan keeps what was asked, so the
-- dialog reopens on it and a percentage reaches items added later.
ALTER TABLE treatment_plans ADD COLUMN discount_mode ENUM('amount','percent') NULL AFTER total_minutes;
ALTER TABLE treatment_plans ADD COLUMN discount_value DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_mode;
