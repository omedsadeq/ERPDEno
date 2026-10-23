-- Migration: Add 'general' value to appointments.type ENUM
-- Date: 2026-04-18

ALTER TABLE appointments
    MODIFY COLUMN type ENUM('general','checkup','treatment','followup','emergency','consultation')
    NOT NULL DEFAULT 'general';
