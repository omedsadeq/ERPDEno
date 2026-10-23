-- Migration: X-ray-only walk-in patients (2026-07-10)
-- A patient can come straight to the clinic only for an x-ray, with no doctor.
-- Their waiting-room entry has doctor_id = NULL and flows:
--   waiting -> at_accounting -> at_xray -> completed (never "back to doctor").

ALTER TABLE waiting_room
MODIFY COLUMN `doctor_id` int NULL;
