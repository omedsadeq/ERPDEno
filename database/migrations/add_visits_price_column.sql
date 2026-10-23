-- Migration: Add price to visits
-- Purpose: Store the checkup visit price entered on new_visit.php/edit_visit.php.

ALTER TABLE `visits`
    ADD COLUMN `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `procedure_treatment`;

ALTER TABLE `visits`
    ADD KEY `idx_visits_price` (`price`);
