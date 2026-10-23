-- Migration: Add visit_type to waiting_room
-- Date: 2026-05-10
-- Description: Store whether waiting-room entry is a checkup or treatment

ALTER TABLE waiting_room
  ADD COLUMN visit_type ENUM('checkup', 'treatment') NOT NULL DEFAULT 'treatment' AFTER doctor_id;
