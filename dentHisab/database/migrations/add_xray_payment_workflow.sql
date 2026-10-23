-- X-Ray Payment Workflow Migration
-- سیستەمی پارەدانی ئێکسڕەی
-- Compatible with MySQL 5.7+

-- Add payment workflow fields to xray_requests table
-- Run each ALTER separately to handle existing columns gracefully

ALTER TABLE xray_requests
ADD COLUMN original_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT 'نرخی سەرەتایی کە دکتۆر دایناوە';

ALTER TABLE xray_requests
ADD COLUMN discount_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT 'بڕی داشکاندن';

ALTER TABLE xray_requests
ADD COLUMN discount_reason VARCHAR(255) DEFAULT NULL COMMENT 'هۆکاری داشکاندن';

ALTER TABLE xray_requests
ADD COLUMN final_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT 'نرخی کۆتایی دوای داشکاندن';

ALTER TABLE xray_requests
ADD COLUMN payment_status ENUM('pending', 'awaiting_payment', 'paid', 'debt') DEFAULT 'pending' COMMENT 'دۆخی پارەدان';

ALTER TABLE xray_requests
ADD COLUMN paid_at DATETIME DEFAULT NULL COMMENT 'کاتی پارەدان';

ALTER TABLE xray_requests
ADD COLUMN paid_by INT DEFAULT NULL COMMENT 'کێ پارەی وەرگرتووە';

ALTER TABLE xray_requests
ADD COLUMN settled_by INT DEFAULT NULL COMMENT 'کێ حسابی کردووە';

ALTER TABLE xray_requests
ADD COLUMN settled_at DATETIME DEFAULT NULL COMMENT 'کاتی یەکلاکردنەوە';

-- Add foreign keys for the new fields (ignore if already exists)
-- ALTER TABLE xray_requests
-- ADD CONSTRAINT fk_xray_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL;

-- ALTER TABLE xray_requests
-- ADD CONSTRAINT fk_xray_settled_by FOREIGN KEY (settled_by) REFERENCES users(id) ON DELETE SET NULL;

-- Create X-Ray Payment Log Table for auditing
CREATE TABLE IF NOT EXISTS xray_payment_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    action ENUM('created', 'completed', 'discount_applied', 'paid_cash', 'paid_debt', 'debt_paid') NOT NULL,
    original_amount DECIMAL(12,2) DEFAULT NULL,
    discount_amount DECIMAL(12,2) DEFAULT NULL,
    final_amount DECIMAL(12,2) DEFAULT NULL,
    action_data JSON DEFAULT NULL COMMENT 'Additional action data in JSON format',
    notes TEXT,
    performed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES xray_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Create Patient Debts Table for tracking X-Ray debts
CREATE TABLE IF NOT EXISTS patient_xray_debts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    request_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    remaining_amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    due_date DATE DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES xray_requests(id) ON DELETE CASCADE
);

-- Create Debt Payments Table for tracking debt payments
CREATE TABLE IF NOT EXISTS xray_debt_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    debt_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'transfer') DEFAULT 'cash',
    received_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (debt_id) REFERENCES patient_xray_debts(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indexes for better performance (ignore errors if already exist)
-- CREATE INDEX idx_xray_payment_status ON xray_requests(payment_status);
-- CREATE INDEX idx_xray_payment_logs_request ON xray_payment_logs(request_id);
-- CREATE INDEX idx_patient_debts_patient ON patient_xray_debts(patient_id);
-- CREATE INDEX idx_patient_debts_status ON patient_xray_debts(status);

-- Update existing records to set original_amount = amount
UPDATE xray_requests SET original_amount = amount, final_amount = amount WHERE original_amount = 0 OR original_amount IS NULL;
