-- دەرمانی دەرەوە: a prescription line the system does not stock at all,
-- written by name for the patient to buy outside. It has no product
-- (product_id NULL) and is flagged, so the till leaves it out and the counter
-- shows it as bought outside; the printed sheet carries it like any other.

ALTER TABLE erp2_prescription_items MODIFY product_id INT UNSIGNED NULL;
ALTER TABLE erp2_prescription_items ADD COLUMN IF NOT EXISTS is_outside TINYINT(1) NOT NULL DEFAULT 0 AFTER product_id;
