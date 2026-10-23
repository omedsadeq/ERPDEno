-- Migration: approval_reversal — سڕینەوە و کردنەوەی تۆمارە پەسەندکراوەکان
-- Date: 2026-09-27
--
-- The Approval Center's پەسەندکراو tab can now take an approved record back:
-- «کردنەوە بۆ دەستکاری» returns it to the queue, «سڕینەوە» closes it out and
-- reverses its money (classes/ApprovalReversal.php).
--
-- Every other source already had somewhere to record that:
--   case_invoices     accounting_status already carries 'cancelled'
--   payments          status already carries 'refunded' + reversed_by/at/reason
--   installment_plans status already carries 'cancelled' + reject_reason
--   xray_requests     request_status is a VARCHAR and already uses 'cancelled'
-- وەسڵی کارە ئەنجامدراوەکان is the one that did not: its status enum stopped at
-- 'returned', which means "handed back to the doctor", not "cancelled and its
-- collection reversed". It also had no columns for who reversed it and why.
--
-- Every statement is guarded on the table existing, so this is a no-op on a
-- database where the arch-work feature was never installed.

SET @has_arch := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'arch_work_receipts'
);

SET @sql := IF(@has_arch = 1,
  'ALTER TABLE arch_work_receipts MODIFY COLUMN status ENUM(''sent'',''approved'',''returned'',''cancelled'') NOT NULL DEFAULT ''sent''',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_arch = 1 AND (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'arch_work_receipts' AND COLUMN_NAME = 'reversed_by'
  ) = 0,
  'ALTER TABLE arch_work_receipts ADD COLUMN reversed_by INT NULL DEFAULT NULL AFTER approved_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_arch = 1 AND (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'arch_work_receipts' AND COLUMN_NAME = 'reversed_at'
  ) = 0,
  'ALTER TABLE arch_work_receipts ADD COLUMN reversed_at DATETIME NULL DEFAULT NULL AFTER reversed_by',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_arch = 1 AND (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'arch_work_receipts' AND COLUMN_NAME = 'reversal_reason'
  ) = 0,
  'ALTER TABLE arch_work_receipts ADD COLUMN reversal_reason TEXT NULL DEFAULT NULL AFTER reversed_at',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
