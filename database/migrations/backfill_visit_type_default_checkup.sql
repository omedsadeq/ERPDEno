-- Migration: Backfill old visits with empty visit_type → 'checkup'
-- Date: 2026-05-17
-- Purpose: For records created before visit_type existed (or rows that
--          never received a value), set visit_type to 'checkup' (پشکنین)
--          so reports treat them as the default category.
--          Also set DEFAULT 'checkup' so any future insert that omits the
--          column lands in the same bucket.

UPDATE `visits`
   SET `visit_type` = 'checkup'
 WHERE `visit_type` IS NULL OR `visit_type` = '';

ALTER TABLE `visits`
    MODIFY COLUMN `visit_type` ENUM('checkup','treatment') NULL DEFAULT 'checkup';
