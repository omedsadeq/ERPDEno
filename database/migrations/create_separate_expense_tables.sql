-- Migration: Create Separate Expense Tables
-- Date: 2026-01-19
-- Description: Create separate tables for General, Lab, and Store expenses
-- Each expense type has its own table and category table

-- ======================================
-- GENERAL EXPENSES SYSTEM
-- ======================================

-- General Expense Categories Table
CREATE TABLE IF NOT EXISTS expense_categories_general (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fas fa-tag',
    color VARCHAR(20) DEFAULT '#6c757d',
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General Expenses Table
CREATE TABLE IF NOT EXISTS expenses_general (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT,
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    vendor VARCHAR(255),
    receipt_number VARCHAR(100),
    payment_method ENUM('cash', 'card', 'transfer', 'check') DEFAULT 'cash',
    notes TEXT,
    attachment VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES expense_categories_general(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_expense_date (expense_date),
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default General Categories
INSERT INTO expense_categories_general (category_name, description, icon, color, sort_order) VALUES
('کرێی شوێن', 'کرێی مانگانەی کلینیک', 'fas fa-building', '#e91e63', 1),
('کارەبا', 'پارەی کارەبا', 'fas fa-bolt', '#ff9800', 2),
('ئاو', 'پارەی ئاو', 'fas fa-tint', '#2196f3', 3),
('ئینتەرنێت', 'پارەی ئینتەرنێت و تەلەفۆن', 'fas fa-wifi', '#9c27b0', 4),
('مووچە', 'مووچەی کارمەندان', 'fas fa-users', '#4caf50', 5),
('پاکیکردنەوە', 'خەرجی پاکیکردنەوە', 'fas fa-broom', '#00bcd4', 6),
('گواستنەوە', 'خەرجی گواستنەوە و گەیاندن', 'fas fa-truck', '#795548', 7),
('چاککردنەوە', 'چاککردنەوەی شتەکان', 'fas fa-tools', '#607d8b', 8),
('هەمیشەیی', 'خەرجی هەمیشەیی', 'fas fa-redo', '#3f51b5', 9),
('جۆراوجۆر', 'خەرجی جۆراوجۆر', 'fas fa-ellipsis-h', '#9e9e9e', 10)
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);


-- ======================================
-- LABORATORY EXPENSES SYSTEM
-- ======================================

-- Lab Expense Categories Table
CREATE TABLE IF NOT EXISTS expense_categories_lab (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fas fa-tag',
    color VARCHAR(20) DEFAULT '#9c27b0',
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lab Expenses Table
CREATE TABLE IF NOT EXISTS expenses_lab (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT,
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    lab_name VARCHAR(255),
    receipt_number VARCHAR(100),
    patient_id INT,
    case_id INT,
    tooth_number VARCHAR(50),
    work_type VARCHAR(100),
    payment_status ENUM('paid', 'pending', 'partial') DEFAULT 'paid',
    notes TEXT,
    attachment VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES expense_categories_lab(id) ON DELETE SET NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_expense_date (expense_date),
    INDEX idx_category (category_id),
    INDEX idx_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Lab Categories
INSERT INTO expense_categories_lab (category_name, description, icon, color, sort_order) VALUES
('تاج زیرکۆنیا', 'تاجی زیرکۆنیا', 'fas fa-tooth', '#e91e63', 1),
('تاج PFM', 'تاجی PFM/Metal Ceramic', 'fas fa-tooth', '#9c27b0', 2),
('تاج ئی-ماکس', 'تاجی E-Max', 'fas fa-tooth', '#673ab7', 3),
('ڤینیەر', 'ڤینیەری ددان', 'fas fa-teeth', '#3f51b5', 4),
('بریج', 'بریجی ددان', 'fas fa-teeth-open', '#2196f3', 5),
('ددانی دەکراو', 'ددانی دەکراوی تەواو', 'fas fa-smile', '#00bcd4', 6),
('ددانی نیوەی', 'ددانی دەکراوی نیوەی', 'fas fa-smile-beam', '#009688', 7),
('ئیمپلانت', 'کاری تاقیگەی ئیمپلانت', 'fas fa-screwdriver', '#4caf50', 8),
('ئۆرتۆدۆنتیک', 'کاری تاقیگەی ئۆرتۆدۆنتیک', 'fas fa-ruler', '#8bc34a', 9),
('چاککردنەوە', 'چاککردنەوەی کارەکانی تاقیگە', 'fas fa-tools', '#ff9800', 10),
('جۆراوجۆر', 'کاری جۆراوجۆری تاقیگە', 'fas fa-ellipsis-h', '#9e9e9e', 11)
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);


-- ======================================
-- STORE/INVENTORY EXPENSES SYSTEM
-- ======================================

-- Store Expense Categories Table
CREATE TABLE IF NOT EXISTS expense_categories_store (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fas fa-tag',
    color VARCHAR(20) DEFAULT '#ff9800',
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store Expenses Table
CREATE TABLE IF NOT EXISTS expenses_store (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT,
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    supplier VARCHAR(255),
    invoice_number VARCHAR(100),
    quantity INT DEFAULT 1,
    unit VARCHAR(50),
    unit_price DECIMAL(12,2),
    payment_status ENUM('paid', 'pending', 'partial') DEFAULT 'paid',
    notes TEXT,
    attachment VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES expense_categories_store(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_expense_date (expense_date),
    INDEX idx_category (category_id),
    INDEX idx_supplier (supplier(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Store Categories
INSERT INTO expense_categories_store (category_name, description, icon, color, sort_order) VALUES
('مەوادی پڕکردنەوە', 'کۆمپۆزیت، گلاس ئایۆنۆمەر، ئەمالگەم', 'fas fa-fill-drip', '#e91e63', 1),
('مەوادی ڕەگ', 'فایل، گوتا پێرچا، سیلەر', 'fas fa-syringe', '#9c27b0', 2),
('مەوادی ئیمپریشن', 'ئەلجینەیت، سیلیکۆن، پۆلی ئیسەر', 'fas fa-hand-paper', '#673ab7', 3),
('دەزووی یەکجار', 'گلۆڤ، ماسک، سرنج', 'fas fa-box', '#3f51b5', 4),
('ئامێری یەکجار', 'سەکشن، میرۆر، ئیکسپلۆرەر یەکجار', 'fas fa-tools', '#2196f3', 5),
('دەرمان', 'دەرمانی بێهۆشی، ئەنتیبایۆتیک', 'fas fa-pills', '#00bcd4', 6),
('سیمان', 'سیمانی GIC، سیمانی ڕێزن', 'fas fa-mortar-pestle', '#009688', 7),
('بۆندینگ', 'مەوادی بۆندینگ و ئێچینگ', 'fas fa-tint', '#4caf50', 8),
('سفیدکردنەوە', 'مەوادی سفیدکردنەوەی ددان', 'fas fa-sparkles', '#8bc34a', 9),
('پاکیکەرەوە', 'مەوادی پاکیکردنەوە و ستیریلایز', 'fas fa-spray-can', '#ff9800', 10),
('ئامێری گەورە', 'ئامێری گەورەی کلینیک', 'fas fa-desktop', '#795548', 11),
('ئامێری بچووک', 'ئامێری بچووکی دەست', 'fas fa-wrench', '#607d8b', 12),
('جۆراوجۆر', 'مەوادی جۆراوجۆر', 'fas fa-ellipsis-h', '#9e9e9e', 13)
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);


-- ======================================
-- MIGRATE EXISTING DATA (Optional)
-- Run these queries manually if you have existing data
-- ======================================

-- Migrate General expenses
-- INSERT INTO expenses_general (category_id, description, amount, expense_date, vendor, receipt_number, created_by, created_at)
-- SELECT NULL, e.description, e.amount, e.expense_date, e.vendor, e.receipt_number, e.created_by, e.created_at
-- FROM expenses e
-- LEFT JOIN expense_categories ec ON e.category_id = ec.id
-- WHERE ec.category_type = 'general';

-- Migrate Lab expenses
-- INSERT INTO expenses_lab (category_id, description, amount, expense_date, lab_name, receipt_number, patient_id, created_by, created_at)
-- SELECT NULL, e.description, e.amount, e.expense_date, e.vendor, e.receipt_number, e.patient_id, e.created_by, e.created_at
-- FROM expenses e
-- LEFT JOIN expense_categories ec ON e.category_id = ec.id
-- WHERE ec.category_type = 'lab';

-- Migrate Store expenses
-- INSERT INTO expenses_store (category_id, description, amount, expense_date, supplier, invoice_number, created_by, created_at)
-- SELECT NULL, e.description, e.amount, e.expense_date, e.vendor, e.receipt_number, e.created_by, e.created_at
-- FROM expenses e
-- LEFT JOIN expense_categories ec ON e.category_id = ec.id
-- WHERE ec.category_type = 'inventory';
