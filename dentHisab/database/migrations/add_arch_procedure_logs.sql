-- ======================================
-- Migration: Add Arch Procedure Logs
-- سیستەمی تۆمارکردنی کارەکانی ئارچ
-- Date: 2026-01-01
-- ======================================

USE dental_clinics_soran;

-- Step 1: Create procedure_types table for dynamic procedure types
CREATE TABLE IF NOT EXISTS arch_procedure_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_code VARCHAR(50) NOT NULL UNIQUE,
    type_name VARCHAR(100) NOT NULL COMMENT 'Kurdish name',
    type_name_en VARCHAR(100) DEFAULT NULL COMMENT 'English name',
    description TEXT DEFAULT NULL,
    default_price DECIMAL(10,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: Insert default procedure types
INSERT INTO arch_procedure_types (type_code, type_name, type_name_en, description, default_price, sort_order) VALUES
('monthly_adjustment', 'توندکردنەوەی مانگانە', 'Monthly Adjustment', 'توندکردنەوەی مانگانەی ئارچی سەرەوە/خوارەوە', 0.00, 1),
('wire_change', 'گۆڕینی وایەر', 'Wire Change', 'گۆڕینی وایەری ئارچ', 0.00, 2),
('bracket_repair', 'چاککردنەوەی برێکێت', 'Bracket Repair', 'چاککردنەوەی برێکێتی شکاو یان لێبوو', 0.00, 3),
('emergency_visit', 'سەردانی کتوپڕ', 'Emergency Visit', 'سەردانی کتوپڕ بۆ کێشەی ڕوویداو', 0.00, 4),
('elastic_change', 'گۆڕینی لاستیک', 'Elastic Change', 'گۆڕینی لاستیکەکان', 0.00, 5),
('retainer_check', 'پشکنینی ڕیتەینەر', 'Retainer Check', 'پشکنینی ڕیتەینەر دوای تەواوبوونی چارەسەر', 0.00, 6),
('other', 'کاری تر', 'Other', 'کاری تر کە لە لیستەکەدا نییە', 0.00, 99)
ON DUPLICATE KEY UPDATE type_name = VALUES(type_name);

-- Step 3: Create main procedure logs table
CREATE TABLE IF NOT EXISTS arch_procedure_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    procedure_type_id INT DEFAULT NULL,
    custom_procedure_name VARCHAR(200) DEFAULT NULL COMMENT 'For custom/other procedures',
    arch_affected ENUM('upper', 'lower', 'both') DEFAULT 'both' COMMENT 'Which arch was worked on',
    procedure_date DATE NOT NULL,
    procedure_time TIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    price DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Price charged for this procedure',
    is_paid TINYINT(1) DEFAULT 0,
    next_appointment_date DATE DEFAULT NULL,
    next_appointment_notes VARCHAR(500) DEFAULT NULL,
    performed_by INT DEFAULT NULL COMMENT 'User ID of doctor/staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (procedure_type_id) REFERENCES arch_procedure_types(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_case_date (case_id, procedure_date),
    INDEX idx_procedure_type (procedure_type_id),
    INDEX idx_next_appointment (next_appointment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 4: Create view for easy querying
CREATE OR REPLACE VIEW v_arch_procedure_logs AS
SELECT
    apl.*,
    apt.type_code,
    apt.type_name,
    apt.type_name_en,
    COALESCE(apl.custom_procedure_name, apt.type_name) as display_name,
    c.case_number,
    c.case_name,
    pf.folder_name,
    p.full_name as patient_name,
    p.patient_code,
    u.full_name as performed_by_name
FROM arch_procedure_logs apl
LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id = apt.id
INNER JOIN cases c ON apl.case_id = c.id
INNER JOIN patient_folders pf ON c.folder_id = pf.id
LEFT JOIN patients p ON pf.patient_id = p.id
LEFT JOIN users u ON apl.performed_by = u.id;

-- ======================================
-- Migration Notes:
-- ======================================
-- 1. arch_procedure_types: Stores the types of procedures (can add more dynamically)
-- 2. arch_procedure_logs: Stores each procedure/visit log for arch cases
-- 3. Supports custom procedure names for flexibility
-- 4. Tracks which arch was affected (upper/lower/both)
-- 5. Includes next appointment scheduling
-- 6. Price tracking for each procedure
-- ======================================
