-- Fix case creation for the Lezan server database.
-- Run this in phpMyAdmin / MySQL after taking a database backup.
-- The downloaded user_a.sql creates user_a, but this app expects users.

USE `u670910047_lezan`;

-- If the server only has user_a, rename it to the table name used by the app.
SET @has_users := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
);
SET @has_user_a := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_a'
);
SET @sql := IF(
  @has_users = 0 AND @has_user_a = 1,
  'RENAME TABLE `user_a` TO `users`',
  'SELECT ''users table already exists, or user_a is not present'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Keep the users table compatible with the current application code.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email` varchar(100) DEFAULT NULL AFTER `full_name`,
  ADD COLUMN IF NOT EXISTS `phone` varchar(20) DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `specialization` varchar(100) DEFAULT NULL AFTER `role`,
  ADD COLUMN IF NOT EXISTS `room_number` varchar(20) DEFAULT NULL AFTER `specialization`,
  ADD COLUMN IF NOT EXISTS `profile_image` varchar(255) DEFAULT NULL AFTER `room_number`,
  ADD COLUMN IF NOT EXISTS `commission_rate` decimal(5,2) DEFAULT 0.00 AFTER `profile_image`,
  ADD COLUMN IF NOT EXISTS `is_active` tinyint(1) DEFAULT 1 AFTER `commission_rate`,
  ADD COLUMN IF NOT EXISTS `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `last_notification_check` timestamp NULL DEFAULT NULL AFTER `updated_at`;

ALTER TABLE `users`
  MODIFY COLUMN `role` enum('admin','doctor','receptionist','xray_department','resident_receptionist','accountant') DEFAULT 'doctor';

-- If both user_a and users exist, copy any missing login rows into users.
SET @has_user_a := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_a'
);
SET @sql := IF(
  @has_user_a = 1,
  'INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `phone`, `role`, `specialization`, `profile_image`, `commission_rate`, `is_active`, `created_at`, `updated_at`, `last_notification_check`)
   SELECT ua.`id`, ua.`username`, ua.`password`, ua.`full_name`, ua.`email`, ua.`phone`, ua.`role`, ua.`specialization`, ua.`profile_image`, ua.`commission_rate`, ua.`is_active`, ua.`created_at`, ua.`updated_at`, ua.`last_notification_check`
   FROM `user_a` ua
   WHERE NOT EXISTS (SELECT 1 FROM `users` u WHERE u.`id` = ua.`id`)
     AND NOT EXISTS (SELECT 1 FROM `users` u WHERE u.`username` = ua.`username`)',
  'SELECT ''no user_a merge needed'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The current create-case form inserts these two columns.
ALTER TABLE `cases`
  ADD COLUMN IF NOT EXISTS `scope_type` enum('tooth','arch') DEFAULT 'tooth' AFTER `case_name`,
  ADD COLUMN IF NOT EXISTS `selected_arch` enum('upper','lower','both') DEFAULT NULL AFTER `scope_type`;

-- Used after case close/reopen flows; harmless if already present.
ALTER TABLE `cases`
  ADD COLUMN IF NOT EXISTS `closing_comment` text DEFAULT NULL AFTER `closed_at`;

-- Used by arch treatment flows after case creation.
ALTER TABLE `case_treatments`
  ADD COLUMN IF NOT EXISTS `arch_type` enum('none','upper','lower') DEFAULT 'none' AFTER `tooth_number`;

CREATE INDEX IF NOT EXISTS `idx_case_treatments_arch`
  ON `case_treatments` (`case_id`, `arch_type`);

-- Repoint the folder doctor foreign key to users(id).
SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_folders'
    AND COLUMN_NAME = 'doctor_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(
  @fk IS NOT NULL,
  CONCAT('ALTER TABLE `patient_folders` DROP FOREIGN KEY `', @fk, '`'),
  'SELECT ''patient_folders.doctor_id has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE INDEX IF NOT EXISTS `idx_folders_doctor` ON `patient_folders` (`doctor_id`);

ALTER TABLE `patient_folders`
  ADD CONSTRAINT `patient_folders_doctor_users_fk`
  FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Repoint notifications.user_id as well; this fixes the common user_a/users FK mismatch.
SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notifications'
    AND COLUMN_NAME = 'user_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(
  @fk IS NOT NULL,
  CONCAT('ALTER TABLE `notifications` DROP FOREIGN KEY `', @fk, '`'),
  'SELECT ''notifications.user_id has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE INDEX IF NOT EXISTS `idx_notifications_user_id` ON `notifications` (`user_id`);

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_user_users_fk`
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Repoint xray foreign keys to users(id) as well.
SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'xray_requests'
    AND COLUMN_NAME = 'requested_by'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(
  @fk IS NOT NULL,
  CONCAT('ALTER TABLE `xray_requests` DROP FOREIGN KEY `', @fk, '`'),
  'SELECT ''xray_requests.requested_by has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `xray_requests`
  ADD CONSTRAINT `xray_requests_requested_by_users_fk`
  FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'xray_requests'
    AND COLUMN_NAME = 'completed_by'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(
  @fk IS NOT NULL,
  CONCAT('ALTER TABLE `xray_requests` DROP FOREIGN KEY `', @fk, '`'),
  'SELECT ''xray_requests.completed_by has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `xray_requests`
  ADD CONSTRAINT `xray_requests_completed_by_users_fk`
  FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'xray_images'
    AND COLUMN_NAME = 'uploaded_by'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(
  @fk IS NOT NULL,
  CONCAT('ALTER TABLE `xray_images` DROP FOREIGN KEY `', @fk, '`'),
  'SELECT ''xray_images.uploaded_by has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `xray_images`
  ADD CONSTRAINT `xray_images_uploaded_by_users_fk`
  FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Quick verification.
SELECT 'users' AS table_name, COUNT(*) AS rows_count FROM `users`
UNION ALL
SELECT 'patient_folders', COUNT(*) FROM `patient_folders`
UNION ALL
SELECT 'cases', COUNT(*) FROM `cases`;
