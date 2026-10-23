-- ======================================
-- Migration: Add Arch-Based Treatment Support
-- سیستەمی زیرەکی کلینیکی ددان
-- Date: 2025-01-15
-- ======================================

USE dental_clinics_soran;

-- Step 1: Add arch_type column to case_treatments table
ALTER TABLE case_treatments
ADD COLUMN arch_type ENUM('none', 'upper', 'lower') DEFAULT 'none'
COMMENT 'Indicates if treatment was arch-based (none=individual tooth, upper=upper arch, lower=lower arch)'
AFTER tooth_number;

-- Step 2: Add index for better query performance on arch-based filtering
CREATE INDEX idx_case_treatments_arch ON case_treatments(case_id, arch_type);

-- Step 3: Update any NULL values to 'none' (should not be needed due to DEFAULT, but safety measure)
UPDATE case_treatments
SET arch_type = 'none'
WHERE arch_type IS NULL;

-- Step 4: Verification Query - Run this to verify migration success
-- SELECT
--     COUNT(*) as total_treatments,
--     SUM(CASE WHEN arch_type = 'none' THEN 1 ELSE 0 END) as individual_tooth_treatments,
--     SUM(CASE WHEN arch_type = 'upper' THEN 1 ELSE 0 END) as upper_arch_treatments,
--     SUM(CASE WHEN arch_type = 'lower' THEN 1 ELSE 0 END) as lower_arch_treatments
-- FROM case_treatments;

-- ======================================
-- Migration Notes:
-- ======================================
-- 1. This migration adds support for arch-based treatments (treating entire dental arches)
-- 2. Upper arch includes 16 teeth: 18-11 (upper right) and 21-28 (upper left)
-- 3. Lower arch includes 16 teeth: 31-38 (lower left) and 48-41 (lower right)
-- 4. When a doctor selects "arch" mode, the system creates individual records for all teeth in that arch
-- 5. Each tooth record will have the same price as entered by the doctor
-- 6. The arch_type field allows grouping and identifying arch treatments in the UI
-- 7. Backward compatibility: All existing treatments will have arch_type='none'
--
-- ======================================
