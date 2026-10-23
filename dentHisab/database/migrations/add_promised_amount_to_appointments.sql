-- Migration: Add promised_amount column to appointments
-- Date: 2026-04-18
-- Description: Amount patient promised to bring for debt repayment after phone reminder

ALTER TABLE appointments
    ADD COLUMN promised_amount DECIMAL(10,2) NULL AFTER work_type_id;
