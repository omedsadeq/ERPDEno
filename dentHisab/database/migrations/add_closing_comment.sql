-- Migration: Add closing_comment column to cases and patient_folders tables
-- Date: 2025-12-31
-- Description: Allows users to add a final comment when closing a case or folder

ALTER TABLE cases ADD COLUMN closing_comment TEXT DEFAULT NULL AFTER closed_at;
ALTER TABLE patient_folders ADD COLUMN closing_comment TEXT DEFAULT NULL AFTER closed_at;
