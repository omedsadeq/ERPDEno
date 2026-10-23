-- Migration: Doctor rooms (a doctor can work in 1 or 2 rooms)
-- Date: 2026-07-10
-- Description:
--   users.room_number     — existing primary room (added by add_room_number_to_users.sql)
--   users.room_number_2   — optional second room for doctors working in two rooms
--   waiting_room.room_name — the room the patient was called into (picked at call time)

ALTER TABLE users ADD COLUMN room_number_2 VARCHAR(20) NULL DEFAULT NULL AFTER room_number;

ALTER TABLE waiting_room ADD COLUMN room_name VARCHAR(60) NULL DEFAULT NULL AFTER doctor_id;

-- Cleanup: an earlier draft of this migration put the column on the legacy
-- doctors table; the waiting room & monitor use users, so it is unused.
-- (Error 1091 "can't drop, doesn't exist" is ignorable on fresh databases.)
ALTER TABLE doctors DROP COLUMN rooms;
