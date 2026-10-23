-- Track when an appointment patient was called and which staff member called.
-- The latest call is kept on the appointment; AuditLog keeps the full history.

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
     AND COLUMN_NAME = 'called_to_center_at') = 0,
  'ALTER TABLE appointments ADD COLUMN called_to_center_at DATETIME NULL AFTER reminder_sent',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
     AND COLUMN_NAME = 'called_to_center_by') = 0,
  'ALTER TABLE appointments ADD COLUMN called_to_center_by INT NULL AFTER called_to_center_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
     AND INDEX_NAME = 'idx_appointments_called_to_center') = 0,
  'CREATE INDEX idx_appointments_called_to_center ON appointments(called_to_center_at)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
     AND CONSTRAINT_NAME = 'fk_appointments_called_by_user') = 0,
  'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_called_by_user FOREIGN KEY (called_to_center_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing installations already have the delete archive. Fresh databases
-- create it later with CREATE TABLE ... LIKE appointments, so these are no-ops.
SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments_deleted') > 0
  AND
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments_deleted'
     AND COLUMN_NAME = 'called_to_center_at') = 0,
  'ALTER TABLE appointments_deleted ADD COLUMN called_to_center_at DATETIME NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments_deleted') > 0
  AND
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments_deleted'
     AND COLUMN_NAME = 'called_to_center_by') = 0,
  'ALTER TABLE appointments_deleted ADD COLUMN called_to_center_by INT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
