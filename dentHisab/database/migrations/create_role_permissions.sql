-- Migration: Create Role Permissions System
-- Date: 2026-01-18
-- Description: Dynamic permission system for controlling page access by role

-- Role Permissions Table
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    permission_key VARCHAR(100) NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_role_permission (role, permission_key),
    INDEX idx_role (role),
    INDEX idx_permission_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission Definitions Table (for reference)
CREATE TABLE IF NOT EXISTS permission_definitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    permission_name VARCHAR(255) NOT NULL,
    permission_name_ku VARCHAR(255) NOT NULL,
    category VARCHAR(50) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert Permission Definitions
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
-- Main Section
('page.dashboard', 'Dashboard', 'داشبۆرد', 'main', 'Access to dashboard'),
('page.appointments', 'Appointments', 'مەوعیدەکان', 'main', 'Access to appointments'),
('page.doctor_schedules', 'Doctor Schedules', 'کاتەکانی دکتۆر', 'main', 'Access to doctor schedules'),
('page.waiting_room', 'Waiting Room', 'ژووری چاوەڕوانی', 'main', 'Access to waiting room'),
('page.visits', 'Visits', 'سەردان', 'main', 'Access to visits'),

-- Patients Section
('page.patients', 'Patients List', 'نەخۆشەکان', 'patients', 'Access to patients list'),
('page.patients_add', 'Add Patient', 'نەخۆشی نوێ', 'patients', 'Access to add new patient'),
('page.cases', 'Cases', 'کەیسەکان', 'patients', 'Access to cases'),
('page.folders', 'Folders', 'دۆسیەکان', 'patients', 'Access to folders'),

-- Doctors Section
('page.doctors', 'Doctors List', 'هەموو دکتۆرەکان', 'doctors', 'Access to doctors list'),
('page.doctor_profile', 'Doctor Profile', 'پرۆفایلی دکتۆر', 'doctors', 'Access to doctor profile'),
('page.doctor_report', 'Doctor Report', 'ڕاپۆرتی ڕۆژانە', 'doctors', 'Access to daily report'),

-- X-Ray Section
('page.xray', 'X-Ray Requests', 'داواکارییەکان', 'xray', 'Access to x-ray requests'),
('page.xray_request', 'New X-Ray Request', 'داواکاری نوێ', 'xray', 'Create new x-ray request'),
('page.xray_payments', 'X-Ray Payments', 'پارەدانی ئێکسڕەی', 'xray', 'Access to x-ray payments'),
('page.xray_history', 'X-Ray Payment History', 'مێژووی پارەدان', 'xray', 'Access to x-ray payment history'),

-- Financial Section
('page.debts', 'Debts', 'قەرز و قیست', 'financial', 'Access to debts'),
('page.payments', 'Payments', 'پارەدانەکان', 'financial', 'Access to payments'),
('page.installments', 'Installments', 'قیستەکان', 'financial', 'Access to installments'),
('page.expenses', 'Expenses', 'خەرجییەکان', 'financial', 'Access to expenses'),
('page.invoices', 'Invoices', 'وەسڵەکان', 'financial', 'Access to invoices'),

-- Admin Section
('page.users', 'Users Management', 'بەکارهێنەران', 'admin', 'Access to user management'),
('page.treatments', 'Treatments', 'چارەسەرەکان', 'admin', 'Access to treatments'),
('page.inventory', 'Inventory', 'کۆگا', 'admin', 'Access to inventory'),
('page.reports', 'Reports', 'ڕاپۆرتەکان', 'admin', 'Access to reports'),
('page.settings', 'Settings', 'ڕێکخستنەکان', 'admin', 'Access to settings'),
('page.permissions', 'Permissions Management', 'بەڕێوەبردنی مۆڵەتەکان', 'admin', 'Access to permission management'),

-- Actions
('action.delete_visit', 'Delete Visit', 'سڕینەوەی سەردان', 'actions', 'Can delete visits'),
('action.edit_visit', 'Edit Visit', 'دەستکاری سەردان', 'actions', 'Can edit visits'),
('action.add_to_waiting', 'Add to Waiting Room', 'زیادکردن بۆ چاوەڕوانی', 'actions', 'Can add patients to waiting room')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- Insert Default Permissions for Receptionist Role
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
-- Main pages (receptionist can access)
('receptionist', 'page.dashboard', 1),
('receptionist', 'page.appointments', 1),
('receptionist', 'page.doctor_schedules', 1),
('receptionist', 'page.waiting_room', 1),
('receptionist', 'page.visits', 1),

-- Patient pages (limited access)
('receptionist', 'page.patients', 0),
('receptionist', 'page.patients_add', 0),
('receptionist', 'page.cases', 0),
('receptionist', 'page.folders', 0),

-- Doctor pages (no access)
('receptionist', 'page.doctors', 0),
('receptionist', 'page.doctor_profile', 0),
('receptionist', 'page.doctor_report', 0),

-- X-Ray pages (no access)
('receptionist', 'page.xray', 0),
('receptionist', 'page.xray_request', 0),
('receptionist', 'page.xray_payments', 0),
('receptionist', 'page.xray_history', 0),

-- Financial pages (no access)
('receptionist', 'page.debts', 0),
('receptionist', 'page.payments', 0),
('receptionist', 'page.installments', 0),
('receptionist', 'page.expenses', 0),
('receptionist', 'page.invoices', 0),

-- Admin pages (no access)
('receptionist', 'page.users', 0),
('receptionist', 'page.treatments', 0),
('receptionist', 'page.inventory', 0),
('receptionist', 'page.reports', 0),
('receptionist', 'page.settings', 0),
('receptionist', 'page.permissions', 0),

-- Actions (limited)
('receptionist', 'action.delete_visit', 0),
('receptionist', 'action.edit_visit', 1),
('receptionist', 'action.add_to_waiting', 1)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);

-- Insert Default Permissions for Doctor Role
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('doctor', 'page.dashboard', 1),
('doctor', 'page.appointments', 1),
('doctor', 'page.doctor_schedules', 1),
('doctor', 'page.waiting_room', 1),
('doctor', 'page.visits', 1),
('doctor', 'page.patients', 1),
('doctor', 'page.patients_add', 1),
('doctor', 'page.cases', 1),
('doctor', 'page.folders', 1),
('doctor', 'page.doctors', 0),
('doctor', 'page.doctor_profile', 1),
('doctor', 'page.doctor_report', 1),
('doctor', 'page.xray', 1),
('doctor', 'page.xray_request', 1),
('doctor', 'page.xray_payments', 1),
('doctor', 'page.xray_history', 0),
('doctor', 'page.debts', 1),
('doctor', 'page.payments', 0),
('doctor', 'page.installments', 1),
('doctor', 'page.expenses', 0),
('doctor', 'page.invoices', 0),
('doctor', 'page.users', 0),
('doctor', 'page.treatments', 0),
('doctor', 'page.inventory', 0),
('doctor', 'page.reports', 0),
('doctor', 'page.settings', 0),
('doctor', 'page.permissions', 0),
('doctor', 'action.delete_visit', 0),
('doctor', 'action.edit_visit', 1),
('doctor', 'action.add_to_waiting', 1)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);

-- Insert Default Permissions for X-Ray Department Role
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('xray_department', 'page.dashboard', 1),
('xray_department', 'page.appointments', 0),
('xray_department', 'page.doctor_schedules', 0),
('xray_department', 'page.waiting_room', 0),
('xray_department', 'page.visits', 0),
('xray_department', 'page.patients', 1),
('xray_department', 'page.patients_add', 0),
('xray_department', 'page.cases', 0),
('xray_department', 'page.folders', 0),
('xray_department', 'page.doctors', 0),
('xray_department', 'page.doctor_profile', 0),
('xray_department', 'page.doctor_report', 0),
('xray_department', 'page.xray', 1),
('xray_department', 'page.xray_request', 1),
('xray_department', 'page.xray_payments', 0),
('xray_department', 'page.xray_history', 1),
('xray_department', 'page.debts', 0),
('xray_department', 'page.payments', 0),
('xray_department', 'page.installments', 0),
('xray_department', 'page.expenses', 0),
('xray_department', 'page.invoices', 0),
('xray_department', 'page.users', 0),
('xray_department', 'page.treatments', 0),
('xray_department', 'page.inventory', 0),
('xray_department', 'page.reports', 0),
('xray_department', 'page.settings', 0),
('xray_department', 'page.permissions', 0),
('xray_department', 'action.delete_visit', 0),
('xray_department', 'action.edit_visit', 0),
('xray_department', 'action.add_to_waiting', 0)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);
