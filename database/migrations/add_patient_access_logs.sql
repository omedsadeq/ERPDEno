-- Patient Access Logs Table for Audit Trail
-- سیستەمی زیرەکی کلینیکی ددان - Doctor-Patient Privacy System

CREATE TABLE IF NOT EXISTS patient_access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    user_id INT NOT NULL,
    access_type VARCHAR(50) NOT NULL COMMENT 'view_profile, view_xray, view_folder, view_financial',
    data_accessed TEXT NULL COMMENT 'JSON data of what was accessed',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_patient_access_patient (patient_id),
    INDEX idx_patient_access_user (user_id),
    INDEX idx_patient_access_date (created_at),
    INDEX idx_patient_access_type (access_type),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doctor Permission Settings Table
CREATE TABLE IF NOT EXISTS doctor_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL UNIQUE,
    can_view_other_xrays TINYINT(1) DEFAULT 1 COMMENT 'Can view X-rays of other doctors patients',
    can_view_other_profiles TINYINT(1) DEFAULT 1 COMMENT 'Can view basic profiles of other patients',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
