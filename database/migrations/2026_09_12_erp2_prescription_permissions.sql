-- Two new keys for the prescription bridge. Split on purpose: the doctor
-- WRITES prescriptions from the case screen but has no business in the sales
-- till, and the counter staff READ/dispense them but must not author a
-- clinical instruction.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp2.prescriptions.create' AS k, 'Write Prescription' AS n, 'نووسینی دەرمان' AS ku, 'erp2' AS c, NULL AS d
    UNION ALL
    SELECT 'erp2.prescriptions.view', 'View/Dispense Prescriptions', 'بینینی داواکاری دەرمان', 'erp2', NULL
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

-- Doctors write them; the roles that already run the till read them.
INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'erp2.prescriptions.create' AS k, 1 AS a
    UNION ALL SELECT 'accountant', 'erp2.prescriptions.create', 0
    UNION ALL SELECT 'receptionist', 'erp2.prescriptions.create', 0
    UNION ALL SELECT 'accountant', 'erp2.prescriptions.view', 1
    UNION ALL SELECT 'receptionist', 'erp2.prescriptions.view', 1
    UNION ALL SELECT 'doctor', 'erp2.prescriptions.view', 1
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
