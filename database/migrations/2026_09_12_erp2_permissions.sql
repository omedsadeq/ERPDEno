-- Permission definitions + role grants for بەشی فرۆشتن (erp2), copied from
-- the erp.* rows with the key/category prefix swapped. Same roles get the
-- same access erp.* already grants them, so nothing regresses for existing
-- staff; erp2 access can be tuned independently from here on.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT
    REPLACE(permission_key, 'erp.', 'erp2.'),
    permission_name,
    permission_name_ku,
    'erp2',
    description
FROM permission_definitions
WHERE permission_key LIKE 'erp.%'
  AND REPLACE(permission_key, 'erp.', 'erp2.') NOT IN (
    SELECT permission_key FROM permission_definitions WHERE permission_key LIKE 'erp2.%'
  );

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT
    role,
    REPLACE(permission_key, 'erp.', 'erp2.'),
    is_allowed
FROM role_permissions
WHERE permission_key LIKE 'erp.%'
  AND CONCAT(role, ':', REPLACE(permission_key, 'erp.', 'erp2.')) NOT IN (
    SELECT CONCAT(role, ':', permission_key) FROM role_permissions WHERE permission_key LIKE 'erp2.%'
  );
