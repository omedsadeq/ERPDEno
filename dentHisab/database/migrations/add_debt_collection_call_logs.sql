-- Migration: Add Debt Collection & Call Management Module
-- Date: 2026-03-09
-- Description: Creates call_logs table for tracking patient debt follow-up calls.
--   Linked to patients table. Used by accountant/admin for debt collection workflow.

-- ── 1. Create call_logs table ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS call_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    called_by INT UNSIGNED NOT NULL,
    call_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    call_status ENUM('no_answer','promised_to_pay','busy','paid','refused','wrong_number','scheduled_visit','other') NOT NULL DEFAULT 'no_answer',
    report_note TEXT NULL,
    next_follow_up DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_patient_id (patient_id),
    INDEX idx_call_date (call_date),
    INDEX idx_call_status (call_status),
    INDEX idx_called_by (called_by),
    INDEX idx_next_follow_up (next_follow_up)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Permission definition ────────────────────────────────────────
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('page.debt_collection', 'Debt Collection & Calls', 'کۆکردنەوەی قەرز و پەیوەندیکردن', 'financial', 'Access to debt collection and call management page')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- ── 3. Grant permission to accountant, admin, and doctor ────────────
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant', 'page.debt_collection', 1),
('admin', 'page.debt_collection', 1),
('doctor', 'page.debt_collection', 1)
ON DUPLICATE KEY UPDATE is_allowed = 1;
