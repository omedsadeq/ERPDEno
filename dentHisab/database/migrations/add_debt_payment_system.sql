-- Migration: Flexible Debt Payment Distribution System
-- Date: 2026-01-05
-- Description: Adds tables for patient-level debt payments and credit management

-- ======================================
-- Patient Debt Payments Table
-- Master record for payments made toward patient debts
-- Payments are distributed across debt items (cases, x-rays)
-- ======================================
CREATE TABLE IF NOT EXISTS patient_debt_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'transfer', 'credit', 'other') DEFAULT 'cash',
    received_by INT,
    notes TEXT,
    allocations JSON COMMENT 'JSON array of how payment was distributed',
    credit_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Amount added to patient credit if overpaid',
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for faster queries
CREATE INDEX idx_patient_debt_payments_patient ON patient_debt_payments(patient_id);
CREATE INDEX idx_patient_debt_payments_date ON patient_debt_payments(payment_date);

-- ======================================
-- Patient Credits Table
-- Stores credit balance for each patient (from overpayments)
-- ======================================
CREATE TABLE IF NOT EXISTS patient_credits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL UNIQUE,
    balance DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- Patient Credit Logs Table
-- Transaction history for patient credits
-- ======================================
CREATE TABLE IF NOT EXISTS patient_credit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL COMMENT 'Positive for credit, negative for debit',
    type ENUM('credit', 'debit') NOT NULL,
    description TEXT,
    performed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_patient_credit_logs_patient ON patient_credit_logs(patient_id);

-- ======================================
-- Add master_payment_id to payments table
-- Links individual case payments to master debt payment
-- ======================================
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS master_payment_id INT DEFAULT NULL AFTER approved_at,
ADD FOREIGN KEY (master_payment_id) REFERENCES patient_debt_payments(id) ON DELETE SET NULL;
