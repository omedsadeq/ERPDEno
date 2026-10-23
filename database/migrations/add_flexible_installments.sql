-- Migration: Flexible Installments System
-- Date: 2026-01-05
-- Description: Upgrades installment system to support flexible payments
--              Payments are stored separately, schedule is for planning only

-- ======================================
-- Installment Payments Table
-- Stores all actual payments made toward an installment plan
-- ======================================
CREATE TABLE IF NOT EXISTS installment_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plan_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'transfer', 'other') DEFAULT 'cash',
    paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    received_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES installment_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Add index for faster queries
CREATE INDEX idx_installment_payments_plan ON installment_payments(plan_id);
CREATE INDEX idx_installment_payments_date ON installment_payments(paid_at);

-- Add total_paid column to installment_plans for quick reference (cached value)
ALTER TABLE installment_plans
ADD COLUMN IF NOT EXISTS total_paid DECIMAL(12,2) DEFAULT 0.00 AFTER remaining_amount;

-- Add credit_balance column for overpayments
ALTER TABLE installment_plans
ADD COLUMN IF NOT EXISTS credit_balance DECIMAL(12,2) DEFAULT 0.00 AFTER total_paid;

-- Migrate existing paid amounts from schedule to new payments table
-- This preserves historical data
INSERT INTO installment_payments (plan_id, amount, payment_method, paid_at, notes, created_at)
SELECT
    plan_id,
    paid_amount,
    'cash',
    COALESCE(paid_date, created_at),
    CONCAT('قیست #', installment_number, ' (ڕەوانکراو لە سیستەمی کۆن)'),
    created_at
FROM installment_schedule
WHERE paid_amount > 0;

-- Update total_paid in plans based on migrated data
UPDATE installment_plans ip
SET total_paid = (
    SELECT COALESCE(SUM(amount), 0)
    FROM installment_payments
    WHERE plan_id = ip.id
);

-- Calculate and set credit_balance for any overpayments
UPDATE installment_plans
SET credit_balance = GREATEST(0, (down_payment + total_paid) - total_amount)
WHERE status = 'active';
