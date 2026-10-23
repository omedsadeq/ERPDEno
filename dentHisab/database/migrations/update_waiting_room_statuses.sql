-- Migration: Update Waiting Room Statuses
-- Adds new status values for better patient management

-- Modify the status column to include new values
ALTER TABLE waiting_room
MODIFY COLUMN status ENUM('waiting', 'called', 'in_treatment', 'in_progress', 'on_hold', 'completed', 'rejected', 'cancelled', 'left') DEFAULT 'waiting';

-- Add check_out_time column for tracking when patient leaves
-- Note: Run this only if the column doesn't exist (check first with SHOW COLUMNS)
-- SHOW COLUMNS FROM waiting_room LIKE 'check_out_time';
ALTER TABLE waiting_room ADD COLUMN check_out_time DATETIME DEFAULT NULL AFTER called_time;
