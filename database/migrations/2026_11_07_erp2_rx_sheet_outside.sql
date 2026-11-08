-- دەرمانی نەخۆش: medicines bought OUTSIDE the clinic, standing on the
-- prescription sheet beside the pharmacy's own.
--
-- The admin adds them to a column of the sheet. They have nothing to do with
-- the pharmacy: no product, no stock, no sale. Ticked, one goes on the
-- prescription as an outside line (erp2_prescription_items.is_outside) by its
-- name, for the patient to buy elsewhere, like one the doctor types by hand.
--
-- group_id is the column (erp2_categories.id, 0 = the column with no group),
-- dosage the dose filled in when it is ticked, sort_order its place in the
-- column (NULL = after the arranged ones), is_hidden off the sheet for now.
CREATE TABLE IF NOT EXISTS erp2_rx_sheet_outside (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id    INT UNSIGNED NOT NULL DEFAULT 0,
    name        VARCHAR(160) NOT NULL,
    dosage      VARCHAR(255) NULL,
    sort_order  INT          NULL,
    is_hidden   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT          NULL,
    updated_at  DATETIME     NULL,
    updated_by  INT          NULL,
    PRIMARY KEY (id),
    KEY idx_erp2_rx_outside_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
