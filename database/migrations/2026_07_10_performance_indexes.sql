-- Migration: performance indexes on hot filter columns
-- Date: 2026-07-10
-- All additive; duplicate-index errors are ignored by the migration runner.

ALTER TABLE waiting_room        ADD INDEX idx_wr_status (status);
ALTER TABLE waiting_room        ADD INDEX idx_wr_checkin (check_in_time);
ALTER TABLE appointments        ADD INDEX idx_appt_status (status);
ALTER TABLE payments            ADD INDEX idx_pay_status (status);
ALTER TABLE cases               ADD INDEX idx_cases_status (status);
ALTER TABLE patient_folders     ADD INDEX idx_pf_status (status);
ALTER TABLE installment_schedule ADD INDEX idx_isch_status_due (status, due_date);
ALTER TABLE expenses            ADD INDEX idx_exp_date (expense_date);
ALTER TABLE cases               ADD INDEX idx_cases_folder (folder_id);
