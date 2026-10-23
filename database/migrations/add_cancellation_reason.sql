-- Migration: Add cancellation reason and comment to appointments table
-- Date: 2025-12-31
-- Description: Allows tracking why appointments were cancelled

-- Add cancellation_reason column (predefined reasons)
ALTER TABLE appointments
ADD COLUMN cancellation_reason VARCHAR(100) DEFAULT NULL AFTER status;

-- Add cancellation_comment column (optional additional details)
ALTER TABLE appointments
ADD COLUMN cancellation_comment TEXT DEFAULT NULL AFTER cancellation_reason;

-- Add cancelled_at timestamp
ALTER TABLE appointments
ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER cancellation_comment;

-- Common cancellation reasons (stored as values):
-- patient_request: نەخۆش داوای هەڵوەشاندنەوەی کرد
-- patient_no_answer: نەخۆش وەڵامی نەدایەوە
-- patient_sick: نەخۆش نەخۆش بوو
-- schedule_conflict: کێشەی کات
-- doctor_unavailable: دکتۆر بەردەست نییە
-- emergency: کەیسی فریاگوزاری
-- other: هۆکاری تر
