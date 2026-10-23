-- کێ چارەسەر دەکات؟ — who treats the patient after the examination.
--
-- The patient picks a doctor for the checkup; that doctor examines, writes the
-- plan and is the دکتۆری دەستنیشانکەر (plan.doctor_id, unchanged). He then
-- decides who does the work:
--
--   self     he treats the patient himself
--   doctor   one named colleague (دکتۆری دەستنیشانکراو)
--   doctors  «چەند دکتۆرێک» — reception decides which doctor gets the patient;
--            candidate_doctor_ids optionally narrows the choice
--   NULL     not decided yet (every plan written before this migration, and a
--            new plan until the examiner chooses). It behaves like `self`, so
--            existing plans keep working exactly as before.
--
-- treating_doctor_id is the doctor who actually does the work, filled for
-- self / doctor at once and for doctors once reception chooses. That doctor —
-- and only that doctor — books the treatment appointment (appointment_id), and
-- transferred items open a case under their name.
--
-- visit_id links the plan to the reception checkup visit it came from; the
-- visit's دەستنیشانکەر / دەستنیشانکراو / هەڵبژێردراو / مەوعید fields are filled
-- from the plan (TreatmentPlan::syncVisit), so reception no longer types them.
--
-- All ids are users.id except visit_id / appointment_id. No foreign keys on
-- purpose: an appointment or visit row can be deleted by its own module, and
-- the plan code treats a dangling id as "none".
ALTER TABLE treatment_plans
    ADD COLUMN treating_mode ENUM('self','doctor','doctors') NULL DEFAULT NULL AFTER doctor_id;

ALTER TABLE treatment_plans
    ADD COLUMN treating_doctor_id INT NULL DEFAULT NULL AFTER treating_mode;

ALTER TABLE treatment_plans
    ADD COLUMN candidate_doctor_ids VARCHAR(255) NULL DEFAULT NULL AFTER treating_doctor_id;

ALTER TABLE treatment_plans
    ADD COLUMN designated_by INT NULL DEFAULT NULL AFTER candidate_doctor_ids;

ALTER TABLE treatment_plans
    ADD COLUMN designated_at DATETIME NULL DEFAULT NULL AFTER designated_by;

ALTER TABLE treatment_plans
    ADD COLUMN chosen_by INT NULL DEFAULT NULL AFTER designated_at;

ALTER TABLE treatment_plans
    ADD COLUMN chosen_at DATETIME NULL DEFAULT NULL AFTER chosen_by;

ALTER TABLE treatment_plans
    ADD COLUMN appointment_id INT NULL DEFAULT NULL AFTER chosen_at;

ALTER TABLE treatment_plans
    ADD COLUMN visit_id INT NULL DEFAULT NULL AFTER appointment_id;

ALTER TABLE treatment_plans ADD KEY idx_tp_treating (treating_doctor_id, status);
ALTER TABLE treatment_plans ADD KEY idx_tp_mode (treating_mode, status);
ALTER TABLE treatment_plans ADD KEY idx_tp_visit (visit_id);

-- Reception decides which doctor gets a «چەند دکتۆرێک» patient.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'treatment_plan.assign' AS k, 'Assign Plan To Doctor' AS n,
           'ناردنی پلان بۆ دکتۆر (چەند دکتۆرێک)' AS ku, 'cases' AS c, NULL AS d
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'receptionist' AS r, 'treatment_plan.assign' AS k, 1 AS a
    UNION ALL SELECT 'doctor',          'treatment_plan.assign', 0
    UNION ALL SELECT 'accountant',      'treatment_plan.assign', 0
    UNION ALL SELECT 'xray_department', 'treatment_plan.assign', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
