/*
 * Multi-unit products: mark which unit a product is normally SOLD in.
 *
 * The model, unchanged by this migration and restated because the new flag
 * only makes sense against it:
 *
 *   erp_products.unit_id      the BASE unit. Stock, costs and every movement
 *                             are kept in this unit and nothing else. A box of
 *                             10 leaving the shelf removes 10 pieces.
 *   erp_product_units         additional units with a factor, e.g. box = 10.
 *                             factor is how many BASE units one of these holds.
 *
 * is_default answers a different question from "which unit is the base": it is
 * the unit the SALES GRID should open on. A shop that buys in pieces but sells
 * by the box wants the box pre-selected without changing how stock is stored,
 * and conflating the two would force the base unit to change every time the
 * selling habit did, invalidating the historical movements.
 *
 * Only one alternate unit per product may carry the flag. With none set, the
 * base unit is used, which is the existing behaviour.
 */

ALTER TABLE erp_product_units
    ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER factor;

/*
 * Partial uniqueness is not available in MariaDB, so "only one default per
 * product" is enforced in ErpProductUnits::save() by clearing the others
 * inside the same transaction. This index keeps that lookup cheap.
 */
ALTER TABLE erp_product_units
    ADD INDEX idx_erp_pu_default (product_id, is_default);

/*
 * Seed a few brands so the new picker is not empty on first use. Names are
 * generic dental-supply placeholders; the clinic renames or replaces them.
 */
INSERT IGNORE INTO erp_brands (name, name_ar, is_active) VALUES
    ('گشتی', 'عام', 1);
