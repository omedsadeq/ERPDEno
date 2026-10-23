-- Migration: New-visit permission (2026-07-11)
-- Creating a visit is the RECEPTION's job in the clinic workflow — doctors
-- must not add new visits. Gate new_visit.php behind its own permission key
-- so admin can adjust it later from admin/role-permissions.php.
-- (Permission::hasPermission defaults to DENY for any role with no row.)

INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.new_visit', 'New Visit', 'سەردانی نوێ', 'main', 'Create new patient visits (reception workflow)');

INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('receptionist', 'page.new_visit', 1),
('doctor', 'page.new_visit', 0),
('accountant', 'page.new_visit', 0),
('xray_department', 'page.new_visit', 0);
