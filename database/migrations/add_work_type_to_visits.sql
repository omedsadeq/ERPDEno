-- Migration: Add work_type_id to visits
-- Date: 2026-05-17
-- Purpose: Track the selected work type (جۆری ئیش) at visit registration
--          on the new_visit.php page. Required field.

ALTER TABLE `visits`
    ADD COLUMN `work_type_id` INT NULL AFTER `procedure_treatment`;

ALTER TABLE `visits`
    ADD KEY `idx_visits_work_type` (`work_type_id`);

ALTER TABLE `visits`
    ADD CONSTRAINT `fk_visits_work_type`
    FOREIGN KEY (`work_type_id`) REFERENCES `work_types` (`id`)
    ON DELETE SET NULL;
