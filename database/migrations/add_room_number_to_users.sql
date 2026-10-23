-- Migration: Add room_number column to users
-- Date: 2026-04-20
-- Description: Each doctor can have a clinic room assigned

ALTER TABLE users ADD COLUMN room_number VARCHAR(20) NULL AFTER specialization;
