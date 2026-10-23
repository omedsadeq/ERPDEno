-- ======================================
-- Resident Module Integration Migration
-- سیستەمی یەکخستنی مۆدیولی ڕیزدەنت
-- ======================================
-- This migration integrates the Resident system into the main Dental Clinic database
-- Run this script on the dental_clinics_soran database
-- ======================================

USE dental_clinics_soran;

-- ======================================
-- Step 1: Update Users Table - Add 'resident_receptionist' role
-- ======================================
ALTER TABLE users
MODIFY COLUMN role ENUM('admin', 'doctor', 'receptionist', 'xray_department', 'resident_receptionist') DEFAULT 'doctor';

-- ======================================
-- Step 2: Create Resident Doctors Table
-- This stores doctors specific to the Resident module
-- ======================================
CREATE TABLE IF NOT EXISTS resident_doctors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT DEFAULT NULL COMMENT 'Links to users table if doctor is also a system user',
    name VARCHAR(100) NOT NULL,
    specialization VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- Step 3: Create Resident Procedures Table
-- ======================================
CREATE TABLE IF NOT EXISTS resident_procedures (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- Step 4: Create Referral Sources Table
-- ======================================
CREATE TABLE IF NOT EXISTS resident_referral_sources (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- Step 5: Create Resident Visits Table
-- This is the main table for tracking patient visits in Resident module
-- ======================================
CREATE TABLE IF NOT EXISTS resident_visits (
    id INT PRIMARY KEY AUTO_INCREMENT,

    -- Patient Information (linked to main patients table when possible)
    patient_id INT DEFAULT NULL COMMENT 'Links to main patients table',
    patient_name VARCHAR(100) NOT NULL,
    mobile_number VARCHAR(20) NOT NULL,

    -- Treatment Status
    treated VARCHAR(10) DEFAULT 'no',
    treated_reason TEXT DEFAULT NULL,

    -- Doctor Assignment
    requested_doctor_id INT DEFAULT NULL,
    assigned_doctor_id INT DEFAULT NULL,
    assigned_doctor_reason TEXT DEFAULT NULL,

    -- Timing Information
    arrival_time TIME NOT NULL,
    checkin_time TIME DEFAULT NULL,
    notification_time TIME DEFAULT NULL,

    -- Visit Details
    procedure_treatment TEXT DEFAULT NULL,
    patient_status ENUM('new', 'old') NOT NULL,

    -- Staff Assignment
    receptionist_id INT DEFAULT NULL,

    -- Referral Information
    referral_source VARCHAR(200) DEFAULT NULL,
    referral_source_notes TEXT DEFAULT NULL,

    -- Appointment Information
    appointment_date DATE DEFAULT NULL,
    appointment_added_by INT DEFAULT NULL,
    appointment_doctor_id INT DEFAULT NULL,

    -- Referring Doctor Information
    referring_doctor_id VARCHAR(100) DEFAULT NULL,
    referring_doctor_designation VARCHAR(100) DEFAULT NULL,
    referring_doctor_choice_id INT DEFAULT NULL,

    -- Additional Fields
    appointment_image VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    visit_date DATE NOT NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign Keys
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_doctor_id) REFERENCES resident_doctors(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_doctor_id) REFERENCES resident_doctors(id) ON DELETE SET NULL,
    FOREIGN KEY (receptionist_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_added_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_doctor_id) REFERENCES resident_doctors(id) ON DELETE SET NULL,
    FOREIGN KEY (referring_doctor_choice_id) REFERENCES resident_doctors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- Step 6: Create Indexes for Better Performance
-- ======================================
CREATE INDEX idx_resident_visits_patient ON resident_visits(patient_id);
CREATE INDEX idx_resident_visits_date ON resident_visits(visit_date);
CREATE INDEX idx_resident_visits_mobile ON resident_visits(mobile_number);
CREATE INDEX idx_resident_visits_assigned_doctor ON resident_visits(assigned_doctor_id);
CREATE INDEX idx_resident_doctors_user ON resident_doctors(user_id);

-- ======================================
-- Step 7: Insert Default Procedures (from Resident system)
-- ======================================
INSERT INTO resident_procedures (name, name_en, is_active) VALUES
('پڕکردنەوە', 'Filling', 1),
('پاکایی ددان', 'Teeth Cleaning', 1),
('هەڵکۆڵین', 'Extraction', 1),
('چاندن (ortho)', 'Orthodontics', 1),
('جراحی ددان عقل', 'Wisdom Tooth Surgery', 1),
('عصب', 'Root Canal', 1),
('شوشتن', 'Scaling', 1),
('Scaling', 'Scaling', 1),
('Filing', 'Filing', 1),
('گشتی', 'General', 1),
('Filling', 'Filling', 1),
('Implant', 'Implant', 1),
('Extraction', 'Extraction', 1),
('Composite', 'Composite', 1),
('مشاورە', 'Consultation', 1),
('RCT', 'Root Canal Treatment', 1),
('تاج', 'Crown', 1),
('Crown', 'Crown', 1),
('Bleaching', 'Bleaching', 1),
('Bridge', 'Bridge', 1),
('Veneer', 'Veneer', 1),
('X-Ray', 'X-Ray', 1);

-- ======================================
-- Step 8: Insert Default Referral Sources
-- ======================================
INSERT INTO resident_referral_sources (name, is_active) VALUES
('سۆشیال میدیا', 1),
('فەیسبوک', 1),
('ئینستاگرام', 1),
('سناپ چات', 1),
('تیک تۆک', 1),
('بۆخۆی', 1),
('خۆی', 1),
('هاوڕێیان', 1),
('خێزان', 1),
('گووگڵ', 1),
('ناوبانگ', 1),
('دکتۆر', 1),
('کۆن', 1),
('تر', 1);

-- ======================================
-- Step 9: Create View for Visit Statistics
-- ======================================
CREATE OR REPLACE VIEW resident_visit_statistics AS
SELECT
    DATE(visit_date) as visit_day,
    COUNT(*) as total_visits,
    SUM(CASE WHEN patient_status = 'new' THEN 1 ELSE 0 END) as new_patients,
    SUM(CASE WHEN patient_status = 'old' THEN 1 ELSE 0 END) as returning_patients,
    SUM(CASE WHEN treated = 'yes' THEN 1 ELSE 0 END) as treated_count,
    SUM(CASE WHEN treated = 'no' THEN 1 ELSE 0 END) as not_treated_count
FROM resident_visits
GROUP BY DATE(visit_date);

-- ======================================
-- Notes for Data Migration:
-- ======================================
-- After running this migration, you need to:
-- 1. Import existing doctors from the old Resident database into resident_doctors
-- 2. Import existing users from the old Resident database into main users table
-- 3. Import existing visits from the old Resident database into resident_visits
-- 4. Link visits to patients by matching mobile_number with patients.phone
--
-- See the PHP migration script: run_resident_migration.php
-- ======================================
