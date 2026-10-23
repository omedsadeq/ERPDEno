-- پلانی چارەسەر — same two shapes a case has: ددان (tooth chart) or ئەرک (arch).
--
-- A plan is created exactly like a case now (patients/view.php new-plan dialog
-- mirrors the new-case dialog), so it carries the same scope columns as
-- `cases`. When the plan is transferred into a NEW case these two values are
-- copied across, so the case opens on the same chart the estimate was drawn on.
ALTER TABLE treatment_plans
    ADD COLUMN scope_type ENUM('tooth','arch') NOT NULL DEFAULT 'tooth' AFTER dentition;

ALTER TABLE treatment_plans
    ADD COLUMN selected_arch ENUM('upper','lower','both') NULL DEFAULT NULL AFTER scope_type;
