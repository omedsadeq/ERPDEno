-- Migration: Add visit_type to visits
-- Date: 2026-05-17
-- Purpose: Track whether the visit is a checkup (پشکنین) or treatment work (ئیش)
--          at visit registration on new_visit.php. Mirrors waiting_room.visit_type.

ALTER TABLE `visits`
    ADD COLUMN `visit_type` ENUM('checkup','treatment') NULL AFTER `work_type_id`;

ALTER TABLE `visits`
    ADD KEY `idx_visits_visit_type` (`visit_type`);
