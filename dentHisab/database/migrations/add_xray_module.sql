-- ======================================
-- X-Ray Department Module Migration
-- Add X-Ray functionality to dental clinic
-- Run this migration to enable X-Ray features
-- ======================================

-- 1. Update users role ENUM to include xray_department
ALTER TABLE users
MODIFY COLUMN role ENUM('admin', 'doctor', 'receptionist', 'xray_department')
DEFAULT 'doctor';

-- 2. Create xray_requests table
CREATE TABLE IF NOT EXISTS xray_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_number VARCHAR(30) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    case_id INT,
    requested_by INT NOT NULL,
    xray_type ENUM('panoramic', 'cbct', 'periapical', 'cephalometric') NOT NULL,
    xray_reason ENUM('implant', 'pathology', 'impaction', 'trauma', 'other') NOT NULL,
    reason_details TEXT,
    payment_type ENUM('cash', 'non_cash') DEFAULT 'cash',
    payment_reason TEXT,
    amount DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    priority ENUM('normal', 'urgent') DEFAULT 'normal',
    notes TEXT,
    full_mouth TINYINT(1) DEFAULT 0,
    determine_details VARCHAR(255),
    completed_by INT,
    completed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create xray_images table
CREATE TABLE IF NOT EXISTS xray_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    image_type ENUM('pdf', 'jpg', 'jpeg', 'png') NOT NULL,
    original_filename VARCHAR(255),
    file_size INT,
    description TEXT,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES xray_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create indexes for performance
CREATE INDEX idx_xray_requests_status ON xray_requests(status);
CREATE INDEX idx_xray_requests_doctor ON xray_requests(requested_by);
CREATE INDEX idx_xray_requests_patient ON xray_requests(patient_id);
CREATE INDEX idx_xray_requests_date ON xray_requests(created_at);
CREATE INDEX idx_xray_images_request ON xray_images(request_id);

-- 5. Update notifications type to include xray
ALTER TABLE notifications
MODIFY COLUMN type ENUM('appointment', 'payment', 'installment', 'lab', 'xray', 'system') NOT NULL;
