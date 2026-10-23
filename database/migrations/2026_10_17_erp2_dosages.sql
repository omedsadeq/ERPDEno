-- دەرمانخانە (erp2): دۆز و ڕێنمایی — the standing dose and instructions of a
-- medicine, set once by the admin (erp2/dosages.php) and filled in for the
-- doctor when the medicine is ticked on the prescription sheet.
--
-- A medicine may have several (an adult and a child dose, say); one is the
-- default the sheet starts with. `label` is who it is for, `dose` how much and
-- how often, `instructions` how to take it.
CREATE TABLE IF NOT EXISTS erp2_product_dosages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id   INT UNSIGNED NOT NULL,
    label        VARCHAR(60)  NULL,
    dose         VARCHAR(160) NOT NULL,
    instructions VARCHAR(255) NULL,
    is_default   TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order   INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by   INT          NULL,
    updated_at   DATETIME     NULL,
    updated_by   INT          NULL,
    PRIMARY KEY (id),
    KEY idx_erp2_dose_product (product_id, sort_order),
    CONSTRAINT fk_erp2_dose_product FOREIGN KEY (product_id) REFERENCES erp2_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Managing them is the admin's job; the right can be handed to a role from
-- the permissions screen. Doctors only READ them, through the sheet.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp2.dosages.manage' AS k, 'Manage Doses & Instructions' AS n, 'دۆز و ڕێنمایی دەرمان' AS ku, 'erp2' AS c, NULL AS d
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'erp2.dosages.manage' AS k, 0 AS a
    UNION ALL SELECT 'accountant', 'erp2.dosages.manage', 0
    UNION ALL SELECT 'receptionist', 'erp2.dosages.manage', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
