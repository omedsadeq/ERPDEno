-- Migration: Add Accountant Role
-- Date: 2026-03-08
-- Description: Adds accountant role with accounting_reports workflow

-- ── 1. Extend users.role ENUM to include accountant ───────────────
ALTER TABLE users
MODIFY COLUMN role ENUM('admin', 'doctor', 'receptionist', 'xray_department', 'resident_receptionist', 'accountant') DEFAULT 'doctor';

-- ── 2. Accounting Reports Table ───────────────────────────────────
CREATE TABLE IF NOT EXISTS accounting_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    sent_by INT UNSIGNED NOT NULL,
    status ENUM('pending','reviewed','processed') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    processed_by INT UNSIGNED NULL,
    INDEX idx_case_id (case_id),
    INDEX idx_status (status),
    INDEX idx_sent_by (sent_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Permission Definitions for Accountant Pages ────────────────
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.accountant_dashboard', 'Accountant Dashboard', 'داشبۆردی ژمێریار', 'financial', 'Access to accountant dashboard'),
('page.accountant_reports',   'Accounting Reports',   'ڕاپۆرتی ژمێریاری',   'financial', 'Access to pending doctor reports'),
('page.accountant_payments',  'Accountant Payments',  'پارەدانی ژمێریار',    'financial', 'Accountant collect payments'),
('page.accountant_financial', 'Financial Summary',    'پوختەی دارایی',       'financial', 'Access to financial summary'),
('page.expenses_general',     'General Expenses',     'خەرجی گشتی',          'expenses',  'Access to general expenses'),
('page.expenses_inventory',   'Inventory Expenses',   'خەرجی کۆگا',          'expenses',  'Access to inventory expenses'),
('page.expenses_lab',         'Lab Expenses',         'خەرجی تاقیگە',        'expenses',  'Access to lab expenses'),
('page.expense_groups',       'Expense Groups',       'گرووپی خەرجی',        'expenses',  'Access to expense groups'),
('page.quick_actions',        'Quick Actions',        'کردارە خێرەکان',      'main',      'Access to quick actions')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- ── 3. Accountant Role Permissions ────────────────────────────────
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
-- Main
('accountant', 'page.dashboard',           1),
('accountant', 'page.appointments',        0),
('accountant', 'page.doctor_schedules',    0),
('accountant', 'page.waiting_room',        0),
('accountant', 'page.visits',              0),
('accountant', 'page.quick_actions',       0),
-- Patients (read-only view)
('accountant', 'page.patients',            1),
('accountant', 'page.patients_add',        0),
('accountant', 'page.cases',              1),
('accountant', 'page.folders',             1),
-- Doctors
('accountant', 'page.doctors',             0),
('accountant', 'page.doctor_profile',      0),
('accountant', 'page.doctor_report',       0),
-- X-Ray
('accountant', 'page.xray',               0),
('accountant', 'page.xray_request',        0),
('accountant', 'page.xray_payments',       0),
('accountant', 'page.xray_history',        0),
-- Financial (full access)
('accountant', 'page.debts',              1),
('accountant', 'page.payments',            0),
('accountant', 'page.installments',        1),
('accountant', 'page.expenses',            1),
('accountant', 'page.invoices',            1),
-- Accountant-specific pages
('accountant', 'page.accountant_dashboard',1),
('accountant', 'page.accountant_reports',  1),
('accountant', 'page.accountant_payments', 1),
('accountant', 'page.accountant_financial',1),
-- Expenses sub-pages
('accountant', 'page.expenses_general',    1),
('accountant', 'page.expenses_inventory',  1),
('accountant', 'page.expenses_lab',        1),
('accountant', 'page.expense_groups',      1),
-- Admin (no access)
('accountant', 'page.users',              0),
('accountant', 'page.treatments',          0),
('accountant', 'page.inventory',           0),
('accountant', 'page.reports',             0),
('accountant', 'page.settings',            0),
('accountant', 'page.permissions',         0),
-- Actions (no medical actions)
('accountant', 'action.delete_visit',      0),
('accountant', 'action.edit_visit',        0),
('accountant', 'action.add_to_waiting',    0)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);
