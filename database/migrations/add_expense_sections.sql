-- Migration: Add Expense Section Permissions
-- Date: 2026-01-19
-- Description: Add permissions for expense sidebar section

-- Add new permission definitions for expense sections
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.expenses_inventory', 'Inventory Expenses', 'خەرجی کۆگا', 'expenses', 'Access to inventory/warehouse expenses'),
('page.expenses_lab', 'Lab Expenses', 'خەرجی تاقیگە', 'expenses', 'Access to lab expenses'),
('page.expenses_general', 'General Expenses', 'خەرجی گشتی', 'expenses', 'Access to general expenses'),
('page.expense_groups', 'Expense Groups', 'گرووپی خەرجی', 'expenses', 'Access to expense groups management')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- Add default permissions for roles

-- Receptionist: No access to expense pages
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('receptionist', 'page.expenses_inventory', 0),
('receptionist', 'page.expenses_lab', 0),
('receptionist', 'page.expenses_general', 0),
('receptionist', 'page.expense_groups', 0)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);

-- Doctor: No access to expense pages
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('doctor', 'page.expenses_inventory', 0),
('doctor', 'page.expenses_lab', 0),
('doctor', 'page.expenses_general', 0),
('doctor', 'page.expense_groups', 0)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);

-- X-Ray Department: No access to expense pages
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('xray_department', 'page.expenses_inventory', 0),
('xray_department', 'page.expenses_lab', 0),
('xray_department', 'page.expenses_general', 0),
('xray_department', 'page.expense_groups', 0)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);

-- Note: Admin has all permissions by default (handled in Permission class)
