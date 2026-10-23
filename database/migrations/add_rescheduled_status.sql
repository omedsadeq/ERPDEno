-- Migration: Add 'rescheduled' status and tracking columns to appointments
-- Supports the appointment rescheduling / postpone feature

ALTER TABLE appointments
    MODIFY COLUMN status ENUM('scheduled','confirmed','waiting','in_progress','completed','cancelled','no_show','rescheduled') DEFAULT 'scheduled';

ALTER TABLE appointments
    ADD COLUMN rescheduled_from_date DATE NULL COMMENT 'Original date before reschedule';

ALTER TABLE appointments
    ADD COLUMN rescheduled_from_time TIME NULL COMMENT 'Original time before reschedule';

ALTER TABLE appointments
    ADD COLUMN rescheduled_at DATETIME NULL COMMENT 'When the reschedule was performed';
