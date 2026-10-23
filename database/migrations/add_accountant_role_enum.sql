-- Migration: Extend users.role ENUM to include accountant
-- Date: 2026-03-08

ALTER TABLE users
MODIFY COLUMN role ENUM('admin', 'doctor', 'receptionist', 'xray_department', 'resident_receptionist', 'accountant') DEFAULT 'doctor';
