-- Migration: Patient soft-delete
-- Date: 2026-07-10
--
-- Patient delete used to be a hard DELETE that CASCADEd through
-- patient_folders -> cases -> case_treatments -> payments, permanently
-- erasing a patient's entire financial history. These columns let us
-- deactivate a patient instead of destroying the records.

ALTER TABLE patients ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at;
ALTER TABLE patients ADD COLUMN deleted_by INT NULL DEFAULT NULL AFTER deleted_at;
ALTER TABLE patients ADD CONSTRAINT fk_patients_deleted_by_user
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE patients ADD INDEX idx_patients_deleted_at (deleted_at);
