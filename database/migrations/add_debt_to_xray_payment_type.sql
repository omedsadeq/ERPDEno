-- Migration: Add 'debt' value to xray_requests.payment_type ENUM
-- Date: 2026-04-21
-- Description: New payment option so x-ray requests can be marked as debt

ALTER TABLE xray_requests
    MODIFY COLUMN payment_type ENUM('cash','non_cash','debt') NOT NULL DEFAULT 'cash';
