-- 2026-07-20 — دکتۆری فەحس و دکتۆری ئیش لەسەر تەبڵی visits
-- Two per-type doctor columns on visits (both reference doctors.id, like the
-- other visit doctor columns):
--   checkup_doctor_id   → دکتۆری فەحس  (checkup + xray visits)
--   treatment_doctor_id → دکتۆری ئیش   (treatment visits)
--
-- Kept in sync by classes/VisitDoctorSync.php:
--   1. new_visit.php form           → doctor from the form
--   2. send-to-waiting-room dialogs → doctor picked in the dialog
--   3. waiting-room "call" action   → the doctor who actually takes the patient
--
-- SAFE TO RUN MORE THAN ONCE. Every step checks information_schema first, so
-- re-running never raises #1060 (duplicate column) or #1061 (duplicate key) —
-- a half-finished run just completes the parts that are still missing.
-- Paste the whole file into phpMyAdmin's SQL tab and run it.

SET @db := DATABASE();

-- ── 1. checkup_doctor_id ────────────────────────────────────────
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'visits'
        AND COLUMN_NAME = 'checkup_doctor_id') = 0,
    'ALTER TABLE visits ADD COLUMN checkup_doctor_id INT NULL AFTER assigned_doctor_reason',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. treatment_doctor_id ──────────────────────────────────────
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'visits'
        AND COLUMN_NAME = 'treatment_doctor_id') = 0,
    'ALTER TABLE visits ADD COLUMN treatment_doctor_id INT NULL AFTER checkup_doctor_id',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. index on checkup_doctor_id ───────────────────────────────
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'visits'
        AND INDEX_NAME = 'idx_visits_checkup_doctor') = 0,
    'ALTER TABLE visits ADD INDEX idx_visits_checkup_doctor (checkup_doctor_id)',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. index on treatment_doctor_id ─────────────────────────────
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'visits'
        AND INDEX_NAME = 'idx_visits_treatment_doctor') = 0,
    'ALTER TABLE visits ADD INDEX idx_visits_treatment_doctor (treatment_doctor_id)',
    'DO 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 5. Backfill from existing data ──────────────────────────────
-- The doctor who actually saw the patient (assigned) wins, otherwise the
-- requested doctor. The IS NULL guard makes this re-runnable: rows already
-- filled — including ones the app has since written — are never overwritten.
UPDATE visits
SET checkup_doctor_id = COALESCE(assigned_doctor_id, requested_doctor_id)
WHERE visit_type IN ('checkup', 'xray')
  AND checkup_doctor_id IS NULL;

UPDATE visits
SET treatment_doctor_id = COALESCE(assigned_doctor_id, requested_doctor_id)
WHERE visit_type = 'treatment'
  AND treatment_doctor_id IS NULL;

-- NOTE: no trailing SELECT on purpose. MigrationRunner runs every statement
-- through PDO::exec(); a SELECT leaves an unbuffered result set and the
-- INSERT that records the migration then throws
-- "SQLSTATE[HY000] 2014 Cannot execute queries while other unbuffered queries
-- are active" outside applyMigration()'s try/catch — which fatals every page.
-- Migrations must be write-only.
--
-- To verify by hand in phpMyAdmin (select the database first!):
--   SELECT 'columns' AS check_type, COLUMN_NAME AS name
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visits'
--      AND COLUMN_NAME IN ('checkup_doctor_id','treatment_doctor_id')
--   UNION ALL
--   SELECT 'indexes', INDEX_NAME
--     FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visits'
--      AND INDEX_NAME IN ('idx_visits_checkup_doctor','idx_visits_treatment_doctor');
