-- X-Ray request workflow, accounting return, and history tracking.
-- Safe to run after the older X-Ray migrations. MySQL/MariaDB compatible.

ALTER TABLE xray_requests MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending';
ALTER TABLE xray_requests MODIFY COLUMN payment_type VARCHAR(30) NOT NULL DEFAULT 'cash';
ALTER TABLE xray_requests MODIFY COLUMN payment_status VARCHAR(50) DEFAULT 'pending_payment';

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'request_status') = 0,
  'ALTER TABLE xray_requests ADD COLUMN request_status VARCHAR(50) DEFAULT ''pending_xray'' AFTER status',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'accountant_id') = 0,
  'ALTER TABLE xray_requests ADD COLUMN accountant_id INT DEFAULT NULL AFTER payment_status',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'accountant_approved_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN accountant_approved_at DATETIME DEFAULT NULL AFTER accountant_id',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'xray_received_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN xray_received_at DATETIME DEFAULT NULL AFTER accountant_approved_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'xray_started_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN xray_started_at DATETIME DEFAULT NULL AFTER xray_received_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'xray_completed_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN xray_completed_at DATETIME DEFAULT NULL AFTER xray_started_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'return_reason') = 0,
  'ALTER TABLE xray_requests ADD COLUMN return_reason TEXT DEFAULT NULL AFTER xray_completed_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'returned_to_accountant_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN returned_to_accountant_at DATETIME DEFAULT NULL AFTER return_reason',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'returned_by_xray_user_id') = 0,
  'ALTER TABLE xray_requests ADD COLUMN returned_by_xray_user_id INT DEFAULT NULL AFTER returned_to_accountant_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'refunded_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN refunded_at DATETIME DEFAULT NULL AFTER returned_by_xray_user_id',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'cancel_reason') = 0,
  'ALTER TABLE xray_requests ADD COLUMN cancel_reason TEXT DEFAULT NULL AFTER refunded_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'cancelled_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER cancel_reason',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND COLUMN_NAME = 'last_updated_at') = 0,
  'ALTER TABLE xray_requests ADD COLUMN last_updated_at DATETIME DEFAULT NULL AFTER cancelled_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS xray_request_history (
  id INT NOT NULL AUTO_INCREMENT,
  request_id INT NOT NULL,
  old_status VARCHAR(50) DEFAULT NULL,
  new_status VARCHAR(50) DEFAULT NULL,
  old_payment_status VARCHAR(50) DEFAULT NULL,
  new_payment_status VARCHAR(50) DEFAULT NULL,
  action_type VARCHAR(50) NOT NULL,
  action_note TEXT DEFAULT NULL,
  changed_by_user_id INT DEFAULT NULL,
  changed_by_role VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_xray_history_request_id (request_id),
  KEY idx_xray_history_action_type (action_type),
  KEY idx_xray_history_created_at (created_at),
  CONSTRAINT fk_xray_history_request_id FOREIGN KEY (request_id) REFERENCES xray_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE xray_requests
SET payment_type = 'dont_cash'
WHERE payment_type = 'non_cash';

UPDATE xray_requests
SET request_status = CASE
    WHEN status = 'completed' THEN 'completed'
    WHEN status = 'cancelled' THEN 'cancelled'
    WHEN status = 'in_progress' THEN 'in_xray'
    WHEN payment_type IN ('cash', 'debt') AND (accountant_id IS NULL OR accountant_approved_at IS NULL) THEN 'pending_accountant'
    ELSE 'pending_xray'
  END
WHERE request_status IS NULL OR request_status = '';

UPDATE xray_requests
SET payment_status = CASE
    WHEN payment_status = 'paid' THEN 'paid'
    WHEN payment_status = 'debt' THEN 'debt_approved'
    WHEN payment_type = 'dont_cash' THEN 'not_required'
    WHEN payment_type = 'debt' THEN 'debt_pending'
    ELSE 'pending_payment'
  END
WHERE payment_status IN ('pending', 'awaiting_payment', 'debt') OR payment_status IS NULL OR payment_status = '';

UPDATE xray_requests
SET original_amount = amount
WHERE original_amount IS NULL OR original_amount = 0;

UPDATE xray_requests
SET final_amount = amount
WHERE final_amount IS NULL OR final_amount = 0;

UPDATE xray_requests
SET last_updated_at = COALESCE(updated_at, created_at, NOW())
WHERE last_updated_at IS NULL;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND INDEX_NAME = 'idx_xray_request_status') = 0,
  'CREATE INDEX idx_xray_request_status ON xray_requests(request_status)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND INDEX_NAME = 'idx_xray_payment_type') = 0,
  'CREATE INDEX idx_xray_payment_type ON xray_requests(payment_type)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND INDEX_NAME = 'idx_xray_payment_status2') = 0,
  'CREATE INDEX idx_xray_payment_status2 ON xray_requests(payment_status)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
