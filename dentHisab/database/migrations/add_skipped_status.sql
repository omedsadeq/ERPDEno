-- Migration: Add 'skipped' status to installment_schedule
-- Date: 2026-01-05

ALTER TABLE installment_schedule
MODIFY COLUMN status ENUM('pending', 'paid', 'overdue', 'partial', 'skipped') DEFAULT 'pending';
