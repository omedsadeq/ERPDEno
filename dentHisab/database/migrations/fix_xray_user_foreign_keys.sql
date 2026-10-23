SET @fk_requested_by := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'xray_requests'
    AND COLUMN_NAME = 'requested_by'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);

SET @sql := IF(
  @fk_requested_by IS NOT NULL,
  CONCAT('ALTER TABLE `xray_requests` DROP FOREIGN KEY `', @fk_requested_by, '`'),
  'SELECT ''xray_requests.requested_by has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `xray_requests`
  ADD CONSTRAINT `xray_requests_requested_by_users_fk`
  FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

SET @fk_completed_by := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'xray_requests'
    AND COLUMN_NAME = 'completed_by'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);

SET @sql := IF(
  @fk_completed_by IS NOT NULL,
  CONCAT('ALTER TABLE `xray_requests` DROP FOREIGN KEY `', @fk_completed_by, '`'),
  'SELECT ''xray_requests.completed_by has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `xray_requests`
  ADD CONSTRAINT `xray_requests_completed_by_users_fk`
  FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

SET @fk_uploaded_by := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'xray_images'
    AND COLUMN_NAME = 'uploaded_by'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);

SET @sql := IF(
  @fk_uploaded_by IS NOT NULL,
  CONCAT('ALTER TABLE `xray_images` DROP FOREIGN KEY `', @fk_uploaded_by, '`'),
  'SELECT ''xray_images.uploaded_by has no foreign key to drop'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `xray_images`
  ADD CONSTRAINT `xray_images_uploaded_by_users_fk`
  FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;
