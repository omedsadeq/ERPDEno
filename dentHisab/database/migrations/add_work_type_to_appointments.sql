-- Migration: Add work_type_id to appointments
-- Date: 2026-04-18
-- Description: Link appointments to work_types lookup

ALTER TABLE appointments ADD COLUMN work_type_id INT NULL AFTER type;
