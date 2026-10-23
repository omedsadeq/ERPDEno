-- Migration: Add work_type_id to waiting_room
-- Date: 2026-05-10
-- Description: Store selected dental work type for walk-in waiting-room entries

ALTER TABLE waiting_room ADD COLUMN work_type_id INT NULL AFTER appointment_id;
