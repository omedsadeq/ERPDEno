-- 2026-07-20 — پڕکردنەوەی دکتۆری سەردانەکان لە ژووری چاوەڕوانییەوە
--
-- WHY: new_visit.php hides the "زانیاری سەردان بۆ پشکنین" block — and with it
-- both doctor dropdowns — when جۆری نەخۆش = ئیش (treatment). Those visits are
-- therefore saved with requested_doctor_id AND assigned_doctor_id both NULL,
-- so no doctor could ever see them in visits.php: the doctor list filters on
-- those columns. The waiting room DOES know who took the patient
-- (waiting_room.doctor_id), so the doctor is recoverable.
--
-- This fills ONLY visits that have no doctor at all in any of the four
-- columns, from the waiting-room entry for the same patient on the same day.
-- Rows that already name a doctor are never touched, so it is safe to re-run.
--
-- waiting_room.doctor_id is users.id; visits.*_doctor_id is doctors.id —
-- the JOIN through doctors.user_id is what bridges the two.
-- When a patient was queued twice in one day (9 such patient-days locally),
-- the LATEST check-in wins — that is the doctor who actually finished them.

-- ── ئیش (treatment) → treatment_doctor_id ───────────────────────
UPDATE visits v
SET v.treatment_doctor_id = (
        SELECT d.id
          FROM waiting_room wr
          JOIN doctors d ON d.user_id = wr.doctor_id
         WHERE wr.patient_id = v.patient_id
           AND DATE(wr.check_in_time) = v.visit_date
         ORDER BY wr.check_in_time DESC
         LIMIT 1)
WHERE v.visit_type = 'treatment'
  AND v.requested_doctor_id IS NULL
  AND v.assigned_doctor_id  IS NULL
  AND v.checkup_doctor_id   IS NULL
  AND v.treatment_doctor_id IS NULL
  AND EXISTS (
        SELECT 1
          FROM waiting_room wr
          JOIN doctors d ON d.user_id = wr.doctor_id
         WHERE wr.patient_id = v.patient_id
           AND DATE(wr.check_in_time) = v.visit_date);

-- ── پشکنین / ئێکسڕەی (checkup, xray) → checkup_doctor_id ────────
UPDATE visits v
SET v.checkup_doctor_id = (
        SELECT d.id
          FROM waiting_room wr
          JOIN doctors d ON d.user_id = wr.doctor_id
         WHERE wr.patient_id = v.patient_id
           AND DATE(wr.check_in_time) = v.visit_date
         ORDER BY wr.check_in_time DESC
         LIMIT 1)
WHERE v.visit_type IN ('checkup', 'xray')
  AND v.requested_doctor_id IS NULL
  AND v.assigned_doctor_id  IS NULL
  AND v.checkup_doctor_id   IS NULL
  AND v.treatment_doctor_id IS NULL
  AND EXISTS (
        SELECT 1
          FROM waiting_room wr
          JOIN doctors d ON d.user_id = wr.doctor_id
         WHERE wr.patient_id = v.patient_id
           AND DATE(wr.check_in_time) = v.visit_date);

-- NOTE: this file must NOT end with a SELECT. MigrationRunner applies every
-- statement with PDO::exec(); a trailing SELECT leaves an unbuffered result
-- set, and the INSERT that records the migration then dies with
-- "SQLSTATE[HY000] 2014 Cannot execute queries while other unbuffered queries
-- are active" — an exception thrown outside applyMigration()'s try/catch, so
-- it escapes config.php and fatals EVERY page. Keep migrations write-only.
--
-- To check the result, run this by hand in phpMyAdmin:
--   SELECT SUM(requested_doctor_id IS NULL AND assigned_doctor_id IS NULL
--              AND checkup_doctor_id IS NULL AND treatment_doctor_id IS NULL) AS still_without_doctor,
--          SUM(checkup_doctor_id IS NOT NULL)   AS checkup_filled,
--          SUM(treatment_doctor_id IS NOT NULL) AS treatment_filled
--     FROM visits;
