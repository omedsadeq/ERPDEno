-- Migration: X-ray visit type (2026-07-10)
-- A patient can come just for an x-ray scan (پشکنینی ئێکسڕەی).
-- Adds 'xray' as a visit type so the waiting room clearly shows
-- the visit is for an x-ray, with its own badge and filter.

ALTER TABLE waiting_room
MODIFY COLUMN visit_type ENUM('checkup', 'treatment', 'xray') NOT NULL DEFAULT 'treatment';

-- visits.visit_type is nullable by design (see add_visit_type_to_visits.sql)
ALTER TABLE visits
MODIFY COLUMN visit_type ENUM('checkup', 'treatment', 'xray') NULL;
