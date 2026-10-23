-- دەرمانخانە (erp2): the right to EDIT a posted purchase invoice
-- (erp2/purchases.php?edit=<id>) — lines, prices, supplier, date, discount,
-- paid amount. Stock and the supplier account are restated by
-- Erp2Purchase::update(). Admin-only unless a role is granted it on the
-- permissions page.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp2.purchase.edit' AS k, 'Edit Purchase Invoices' AS n, 'دەستکاری وەسڵی کڕین' AS ku, 'erp2' AS c, NULL AS d
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'erp2.purchase.edit' AS k, 0 AS a
    UNION ALL SELECT 'accountant', 'erp2.purchase.edit', 0
    UNION ALL SELECT 'receptionist', 'erp2.purchase.edit', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
