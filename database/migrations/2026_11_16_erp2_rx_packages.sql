-- پاکێجی دەرمان — medicines that go together again and again (after an
-- extraction, a child's set …), saved under a name and ticked on the
-- prescription sheet in one click (includes/rx-dialog.php). The doctor then
-- adds or takes away as usual; the package itself does not change.
--
-- items: JSON list of the lines as the sheet keeps them —
--   {product_id, outside, name, quantity, dosage, note}
-- product_id is the sheet's (an erp2 product, or a دەرمانی دەرەوە of the
-- sheet); a medicine no longer on the sheet is skipped when the package is used.

CREATE TABLE IF NOT EXISTS erp2_rx_packages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    items       MEDIUMTEXT NOT NULL,
    use_count   INT UNSIGNED NOT NULL DEFAULT 0,
    created_by  INT NULL,
    updated_by  INT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_erp2_rx_packages_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
