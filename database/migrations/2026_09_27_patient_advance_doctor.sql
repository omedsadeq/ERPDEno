-- Migration: patient_advance_doctor — دکتۆری وەسڵی قەرزی کۆن
-- Date: 2026-09-27
--
-- قەرزی کۆن is taken at the counter before a case exists, so nothing in the
-- row said WHOSE patient the money belongs to. Reception now picks the doctor
-- when they take it, which is what the receipt has to print and what lets the
-- دکتۆر filter in the Approval Center find these rows at all.
--
-- Nullable on purpose: every receipt taken before this migration has no doctor
-- on it, and inventing one would be a guess. The screens fall back to «کێ
-- وەریگرتووە» (received_by) exactly as they did before.

SET @has_adv := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_advances'
);

SET @sql := IF(@has_adv = 1 AND (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_advances' AND COLUMN_NAME = 'doctor_id'
  ) = 0,
  'ALTER TABLE patient_advances ADD COLUMN doctor_id INT NULL DEFAULT NULL AFTER patient_id',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_adv = 1 AND (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_advances' AND INDEX_NAME = 'idx_patient_advance_doctor'
  ) = 0,
  'CREATE INDEX idx_patient_advance_doctor ON patient_advances(doctor_id)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
