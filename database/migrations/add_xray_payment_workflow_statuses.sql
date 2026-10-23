-- Migration: Add Payment Routing Workflow to X-Ray Requests
-- Description: Implements payment-based routing (Cash/Don't Cash/Debt) with accountant approval
-- Date: 2026-05-13

-- 1. Add payment_type field to xray_requests if not exists
ALTER TABLE xray_requests
ADD COLUMN IF NOT EXISTS payment_type ENUM('cash', 'dont_cash', 'debt') DEFAULT 'cash' COMMENT 'جۆری پارەدان - نوع الدفع';

-- 2. Add request_status field to xray_requests if not exists
ALTER TABLE xray_requests
ADD COLUMN IF NOT EXISTS request_status VARCHAR(50) DEFAULT 'pending_xray' COMMENT 'دۆخی داواکاری - حالة الطلب';

-- 3. Add accountant approval fields
ALTER TABLE xray_requests
ADD COLUMN IF NOT EXISTS accountant_id INT DEFAULT NULL COMMENT 'ژمێریاری پەسەند‌کار - معرف المحاسب الذي وافق';

ALTER TABLE xray_requests
ADD COLUMN IF NOT EXISTS accountant_approved_at DATETIME DEFAULT NULL COMMENT 'کاتی پەسەندکردنی ژمێریار - وقت موافقة المحاسب';

-- 4. Update existing records to use new request_status field based on payment_type
UPDATE xray_requests 
SET request_status = 'pending_xray' 
WHERE request_status IS NULL OR request_status = '';

-- 5. Update status mapping for existing records
UPDATE xray_requests 
SET request_status = 'pending_accountant' 
WHERE payment_type IN ('cash', 'debt') AND request_status = 'pending_xray' AND accountant_id IS NULL;

-- 6. Add index for request_status
SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND INDEX_NAME = 'idx_xray_request_status') = 0,
  'CREATE INDEX idx_xray_request_status ON xray_requests(request_status)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add index for payment_type
SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND INDEX_NAME = 'idx_xray_payment_type') = 0,
  'CREATE INDEX idx_xray_payment_type ON xray_requests(payment_type)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Add index for accountant_id
SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND INDEX_NAME = 'idx_xray_accountant_id') = 0,
  'CREATE INDEX idx_xray_accountant_id ON xray_requests(accountant_id)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. Add index for accountant_approved_at
SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xray_requests' AND INDEX_NAME = 'idx_xray_accountant_approved_at') = 0,
  'CREATE INDEX idx_xray_accountant_approved_at ON xray_requests(accountant_approved_at)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 10. Foreign key creation is handled manually by newer migrations/install scripts.
DO 0;
