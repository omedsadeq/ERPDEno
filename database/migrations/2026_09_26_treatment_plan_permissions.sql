-- Three keys, split along who actually does each job.
--
-- WRITE is the examination itself — clinical judgement, so doctors only.
-- TRANSFER is the commercial step: the patient came back and said yes, and
-- whoever is at the desk turns the estimate into real work. Reception does
-- that in practice, so they get it too.
-- VIEW is everyone who needs to see what was quoted.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'treatment_plan.view' AS k, 'View Treatment Plans' AS n, 'بینینی پلانی چارەسەر' AS ku, 'cases' AS c, NULL AS d
    UNION ALL
    SELECT 'treatment_plan.write', 'Write Treatment Plan', 'نووسینی پلانی چارەسەر', 'cases', NULL
    UNION ALL
    SELECT 'treatment_plan.transfer', 'Transfer Plan To Case', 'گواستنەوەی پلان بۆ کەیس', 'cases', NULL
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'treatment_plan.view' AS k, 1 AS a
    UNION ALL SELECT 'receptionist',    'treatment_plan.view', 1
    UNION ALL SELECT 'accountant',      'treatment_plan.view', 1
    UNION ALL SELECT 'xray_department', 'treatment_plan.view', 0

    -- Only the examining doctor writes the plan.
    UNION ALL SELECT 'doctor',          'treatment_plan.write', 1
    UNION ALL SELECT 'receptionist',    'treatment_plan.write', 0
    UNION ALL SELECT 'accountant',      'treatment_plan.write', 0
    UNION ALL SELECT 'xray_department', 'treatment_plan.write', 0

    -- Turning the estimate into billable work.
    UNION ALL SELECT 'doctor',          'treatment_plan.transfer', 1
    UNION ALL SELECT 'receptionist',    'treatment_plan.transfer', 1
    UNION ALL SELECT 'accountant',      'treatment_plan.transfer', 0
    UNION ALL SELECT 'xray_department', 'treatment_plan.transfer', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
