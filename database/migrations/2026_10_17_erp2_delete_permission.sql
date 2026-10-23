-- دەرمانخانە (erp2): the right to DELETE records for good — customers and
-- suppliers without history, sales and purchase invoices (stock and accounts
-- reversed first), payments. Separate from erp2.invoice.cancel, which keeps
-- the record; admin-only unless a role is granted it on the permissions page.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp2.delete' AS k, 'Delete Records' AS n, 'سڕینەوەی تۆمار (وەسڵ، کڕیار، دابینکەر، پارەدان)' AS ku, 'erp2' AS c, NULL AS d
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'erp2.delete' AS k, 0 AS a
    UNION ALL SELECT 'accountant', 'erp2.delete', 0
    UNION ALL SELECT 'receptionist', 'erp2.delete', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
