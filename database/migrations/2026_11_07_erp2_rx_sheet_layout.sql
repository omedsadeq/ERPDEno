-- دەرمانی نەخۆش: how the prescription sheet on the case page is laid out.
--
-- The sheet is built from the pharmacy (erp2_categories = the columns,
-- erp2_product_types = the round choices under a column title, erp2_products
-- = the ticked items). The admin arranges the SHEET here without touching the
-- pharmacy: a name of its own on the sheet, its place in the order, taken off
-- the sheet, and for an item the column it stands in. The till, the stock and
-- the printed prescription keep the real product.
--
-- One row per thing the admin changed. kind is group, type or item and ref_id
-- the id in its pharmacy table (group 0 is the column of items with no group).
-- A NULL means as the pharmacy has it.
CREATE TABLE IF NOT EXISTS erp2_rx_sheet_layout (
    kind        VARCHAR(8)   NOT NULL,
    ref_id      INT UNSIGNED NOT NULL,
    label       VARCHAR(160) NULL,
    sort_order  INT          NULL,
    is_hidden   TINYINT(1)   NOT NULL DEFAULT 0,
    group_id    INT UNSIGNED NULL,
    updated_at  DATETIME     NULL,
    updated_by  INT          NULL,
    PRIMARY KEY (kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The admin arranges it, and can hand the right to a role from the
-- permissions screen. Doctors only use the sheet.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp2.rx_sheet.manage' AS k, 'Arrange the Prescription Sheet' AS n, 'ڕێکخستنی پەڕەی دەرمانی نەخۆش' AS ku, 'erp2' AS c, NULL AS d
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'erp2.rx_sheet.manage' AS k, 0 AS a
    UNION ALL SELECT 'accountant', 'erp2.rx_sheet.manage', 0
    UNION ALL SELECT 'receptionist', 'erp2.rx_sheet.manage', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
