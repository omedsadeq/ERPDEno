-- ======================================
-- Smart Dental Clinic Management System
-- سیستەمی زیرەکی بەڕێوەبردنی کلینیکی ددان
-- Database Schema
-- ======================================

CREATE DATABASE IF NOT EXISTS dental_clinics_soran
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE dental_clinics_soran;

-- ======================================
-- Users Table (Admins, Doctors, Staff)
-- ======================================
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'doctor', 'receptionist') DEFAULT 'doctor',
    specialization VARCHAR(100),
    profile_image VARCHAR(255),
    commission_rate DECIMAL(5,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ======================================
-- Patients Table (Level 1)
-- ======================================
CREATE TABLE IF NOT EXISTS patients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_code VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    gender ENUM('male', 'female') NOT NULL,
    date_of_birth DATE,
    phone VARCHAR(20) NOT NULL,
    phone2 VARCHAR(20),
    address TEXT,
    city VARCHAR(50),
    occupation VARCHAR(100),
    blood_type VARCHAR(5),
    notes TEXT,
    profile_image VARCHAR(255),
    registered_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Patient Medical Conditions
-- ======================================
CREATE TABLE IF NOT EXISTS patient_medical_conditions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    condition_type ENUM('diabetes', 'blood_pressure', 'heart_disease', 'allergy', 'bleeding_disorder', 'pregnancy', 'other') NOT NULL,
    condition_details TEXT,
    severity ENUM('low', 'medium', 'high') DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================
-- Patient Family Relations
-- ======================================
CREATE TABLE IF NOT EXISTS patient_family (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    related_patient_id INT NOT NULL,
    relationship VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (related_patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================
-- Patient Folders/Files (Level 2)
-- ======================================
CREATE TABLE IF NOT EXISTS patient_folders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    folder_name VARCHAR(100) NOT NULL,
    folder_type ENUM('general', 'cosmetic', 'orthodontic', 'surgery', 'other') DEFAULT 'general',
    status ENUM('active', 'archived') DEFAULT 'active',
    total_amount DECIMAL(12,2) DEFAULT 0.00,
    paid_amount DECIMAL(12,2) DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================
-- Cases (Level 3)
-- ======================================
CREATE TABLE IF NOT EXISTS cases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    folder_id INT NOT NULL,
    case_number VARCHAR(30) UNIQUE NOT NULL,
    case_name VARCHAR(100),
    scope_type ENUM('tooth', 'arch') DEFAULT 'tooth',
    selected_arch ENUM('upper', 'lower', 'both') DEFAULT NULL,
    status ENUM('open', 'pending', 'closed') DEFAULT 'open',
    chief_complaint TEXT,
    diagnosis TEXT,
    treatment_plan TEXT,
    total_amount DECIMAL(12,2) DEFAULT 0.00,
    paid_amount DECIMAL(12,2) DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    discount_type ENUM('percentage', 'fixed') DEFAULT 'fixed',
    discount_value DECIMAL(12,2) DEFAULT 0.00,
    payment_due_date DATE DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME,
    FOREIGN KEY (folder_id) REFERENCES patient_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================
-- Treatments/Services List
-- ======================================
CREATE TABLE IF NOT EXISTS treatments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    treatment_code VARCHAR(20) UNIQUE NOT NULL,
    treatment_name VARCHAR(100) NOT NULL,
    treatment_name_en VARCHAR(100),
    category ENUM('examination', 'cleaning', 'filling', 'extraction', 'root_canal', 'crown', 'bridge', 'implant', 'orthodontic', 'whitening', 'cosmetic', 'surgery', 'other') NOT NULL,
    scope ENUM('tooth', 'arch', 'both') DEFAULT 'both',
    requires_tooth_number TINYINT(1) NOT NULL DEFAULT 1,
    default_price DECIMAL(12,2) DEFAULT 0.00,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ======================================
-- Dental Chart Records
-- ======================================
CREATE TABLE IF NOT EXISTS dental_chart (
    id INT PRIMARY KEY AUTO_INCREMENT,
    case_id INT NOT NULL,
    tooth_number INT NOT NULL,
    tooth_surface VARCHAR(50),
    treatment_id INT,
    treatment_name VARCHAR(100),
    condition_before TEXT,
    condition_after TEXT,
    status ENUM('planned', 'in_progress', 'completed') DEFAULT 'planned',
    price DECIMAL(12,2) DEFAULT 0.00,
    notes TEXT,
    treated_by INT,
    treatment_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (treatment_id) REFERENCES treatments(id) ON DELETE SET NULL,
    FOREIGN KEY (treated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Case Treatments (Services performed)
-- ======================================
CREATE TABLE IF NOT EXISTS case_treatments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    case_id INT NOT NULL,
    treatment_id INT,
    dental_chart_id INT,
    treatment_name VARCHAR(100) NOT NULL,
    tooth_number INT,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(12,2) DEFAULT 0.00,
    total_price DECIMAL(12,2) DEFAULT 0.00,
    discount DECIMAL(12,2) DEFAULT 0.00,
    final_price DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('pending', 'approved', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    performed_by INT,
    approved_by INT,
    performed_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (treatment_id) REFERENCES treatments(id) ON DELETE SET NULL,
    FOREIGN KEY (dental_chart_id) REFERENCES dental_chart(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Payments
-- ======================================
CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    case_id INT NOT NULL,
    payment_number VARCHAR(30) UNIQUE NOT NULL,
    payment_type ENUM('cash', 'credit', 'installment') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'transfer', 'other') DEFAULT 'cash',
    status ENUM('pending', 'approved', 'rejected', 'refunded') DEFAULT 'pending',
    received_by INT,
    approved_by INT,
    notes TEXT,
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Installment Plans
-- ======================================
CREATE TABLE IF NOT EXISTS installment_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    case_id INT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    down_payment DECIMAL(12,2) DEFAULT 0.00,
    remaining_amount DECIMAL(12,2) NOT NULL,
    number_of_installments INT NOT NULL,
    installment_amount DECIMAL(12,2) NOT NULL,
    start_date DATE NOT NULL,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Installment Schedule
-- ======================================
CREATE TABLE IF NOT EXISTS installment_schedule (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plan_id INT NOT NULL,
    installment_number INT NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('pending', 'paid', 'overdue', 'partial') DEFAULT 'pending',
    payment_id INT,
    paid_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES installment_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Appointments
-- ======================================
CREATE TABLE IF NOT EXISTS appointments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    folder_id INT,
    case_id INT,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    duration INT DEFAULT 30,
    type ENUM('checkup', 'treatment', 'followup', 'emergency', 'consultation') DEFAULT 'treatment',
    status ENUM('scheduled', 'confirmed', 'waiting', 'in_progress', 'completed', 'cancelled', 'no_show') DEFAULT 'scheduled',
    notes TEXT,
    reminder_sent TINYINT(1) DEFAULT 0,
    called_to_center_at DATETIME DEFAULT NULL,
    called_to_center_by INT DEFAULT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES patient_folders(id) ON DELETE SET NULL,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    FOREIGN KEY (called_to_center_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Waiting Room
-- ======================================
CREATE TABLE IF NOT EXISTS waiting_room (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    appointment_id INT,
    work_type_id INT NULL,
    check_in_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    called_time DATETIME,
    check_out_time DATETIME DEFAULT NULL,
    status ENUM('waiting', 'called', 'in_treatment', 'in_progress', 'on_hold', 'completed', 'rejected', 'cancelled', 'left') DEFAULT 'waiting',
    priority INT DEFAULT 0,
    notes TEXT,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Expense Categories
-- ======================================
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL,
    category_type ENUM('general', 'lab', 'inventory', 'salary', 'rent', 'utilities', 'other') NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ======================================
-- Expenses
-- ======================================
CREATE TABLE IF NOT EXISTS expenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT NOT NULL,
    expense_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description TEXT,
    vendor VARCHAR(100),
    receipt_number VARCHAR(50),
    receipt_image VARCHAR(255),
    case_id INT,
    patient_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Lab Orders
-- ======================================
CREATE TABLE IF NOT EXISTS lab_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    case_id INT NOT NULL,
    lab_name VARCHAR(100),
    order_type VARCHAR(100) NOT NULL,
    description TEXT,
    tooth_numbers VARCHAR(100),
    shade VARCHAR(20),
    cost DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('ordered', 'in_progress', 'received', 'delivered', 'cancelled') DEFAULT 'ordered',
    order_date DATE NOT NULL,
    expected_date DATE,
    received_date DATE,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Inventory Items
-- ======================================
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_code VARCHAR(20) UNIQUE NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50),
    unit VARCHAR(20),
    current_stock INT DEFAULT 0,
    minimum_stock INT DEFAULT 10,
    cost_price DECIMAL(12,2) DEFAULT 0.00,
    supplier VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ======================================
-- Inventory Transactions
-- ======================================
CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    transaction_type ENUM('purchase', 'use', 'adjustment', 'return') NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) DEFAULT 0.00,
    total_price DECIMAL(12,2) DEFAULT 0.00,
    case_id INT,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Patient Images (Before/After)
-- ======================================
CREATE TABLE IF NOT EXISTS patient_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    case_id INT,
    image_type ENUM('before', 'after', 'xray', 'document', 'other') NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    description TEXT,
    uploaded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Prescriptions
-- ======================================
CREATE TABLE IF NOT EXISTS prescriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    case_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    prescription_date DATE NOT NULL,
    diagnosis TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================
-- Prescription Items
-- ======================================
CREATE TABLE IF NOT EXISTS prescription_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prescription_id INT NOT NULL,
    medicine_name VARCHAR(100) NOT NULL,
    dosage VARCHAR(50),
    frequency VARCHAR(50),
    duration VARCHAR(50),
    instructions TEXT,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================
-- System Settings
-- ======================================
CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(20) DEFAULT 'text',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ======================================
-- Activity Log
-- ======================================
CREATE TABLE IF NOT EXISTS activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ======================================
-- Notifications
-- ======================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type ENUM('appointment', 'payment', 'installment', 'lab', 'system') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    link VARCHAR(255),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================
-- Insert Default Data
-- ======================================

-- Default Admin User (password: admin123)
INSERT INTO users (username, password, full_name, email, role, is_active) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'بەڕێوەبەری سیستەم', 'admin@dental.com', 'admin', 1);

-- Default Doctor (password: doctor123)
INSERT INTO users (username, password, full_name, email, phone, role, specialization, is_active) VALUES
('doctor1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'د. ئەحمەد محەمەد', 'doctor@dental.com', '0750-123-4567', 'doctor', 'پزیشکی گشتی ددان', 1);

-- Default Expense Categories
INSERT INTO expense_categories (category_name, category_type, description) VALUES
('کرێی کلینیک', 'rent', 'کرێی مانگانەی کلینیک'),
('کارەبا و ئاو', 'utilities', 'خەرجی کارەبا و ئاو'),
('خەرجی تاقیگە', 'lab', 'خەرجی کارەکانی تاقیگە'),
('کڕینی کەرەستە', 'inventory', 'کڕینی کەرەستە پزیشکییەکان'),
('خەرجی گشتی', 'general', 'خەرجییە گشتییەکان'),
('موچە', 'salary', 'موچەی کارمەندەکان');

-- Default Treatments
INSERT INTO treatments (treatment_code, treatment_name, treatment_name_en, category, default_price) VALUES
('TRT001', 'پشکنین', 'Examination', 'examination', 15000),
('TRT002', 'پاککردنەوەی ددان', 'Scaling & Cleaning', 'cleaning', 35000),
('TRT003', 'پڕکردنەوەی ئاسایی', 'Normal Filling', 'filling', 25000),
('TRT004', 'پڕکردنەوەی کۆمپۆزیت', 'Composite Filling', 'filling', 40000),
('TRT005', 'ڕاکێشانی ددان', 'Extraction', 'extraction', 20000),
('TRT006', 'ڕاکێشانی ددانی عەقڵ', 'Wisdom Tooth Extraction', 'extraction', 50000),
('TRT007', 'چارەسەری دەمار', 'Root Canal Treatment', 'root_canal', 75000),
('TRT008', 'تاجی ددان', 'Crown', 'crown', 150000),
('TRT009', 'پردی ددان', 'Bridge', 'bridge', 400000),
('TRT010', 'چاندنی ددان', 'Implant', 'implant', 800000),
('TRT011', 'سپیکردنەوەی ددان', 'Whitening', 'whitening', 100000),
('TRT012', 'ڕاستکردنەوەی ددان', 'Orthodontic', 'orthodontic', 1500000),
('TRT013', 'لامینیت', 'Laminate', 'cosmetic', 200000),
('TRT014', 'ڤینیر', 'Veneer', 'cosmetic', 180000);

-- Default Settings
INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES
('clinic_name', 'سەنتەری لێزان', 'text', 'ناوی کلینیک'),
('clinic_phone', '0750 693 2626', 'text', 'ژمارەی تەلەفۆن'),
('clinic_address', 'سۆران: ترافیک لایەتی دەروازەی سۆران، بەرامبەر میلان کڵاس', 'text', 'ناونیشان'),
('clinic_email', '', 'text', 'ئیمەیڵ'),
('clinic_logo', '', 'image', 'لۆگۆی کلینیک'),
('currency', 'IQD', 'text', 'جۆری دراو'),
('currency_symbol', 'د.ع', 'text', 'نیشانەی دراو'),
('working_hours_start', '09:00', 'time', 'دەستپێکی کاتی کار'),
('working_hours_end', '21:00', 'time', 'کۆتایی کاتی کار'),
('appointment_duration', '30', 'number', 'ماوەی مەوعید (خولەک)'),
('invoice_footer', 'سوپاس بۆ متمانەکەتان', 'text', 'تێبینی وەسڵ');

-- ======================================
-- Create Indexes for better performance
-- ======================================
CREATE INDEX idx_patients_phone ON patients(phone);
CREATE INDEX idx_patients_name ON patients(full_name);
CREATE INDEX idx_appointments_date ON appointments(appointment_date);
CREATE INDEX idx_appointments_doctor ON appointments(doctor_id, appointment_date);
CREATE INDEX idx_payments_case ON payments(case_id);
CREATE INDEX idx_payments_date ON payments(payment_date);
CREATE INDEX idx_cases_folder ON cases(folder_id);
CREATE INDEX idx_folders_patient ON patient_folders(patient_id);
CREATE INDEX idx_folders_doctor ON patient_folders(doctor_id);
