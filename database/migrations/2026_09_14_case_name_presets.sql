-- Migration: case_name_presets
-- Date: 2026-09-14
-- Description: Admin-managed list of case names. Doctors pick a name from this
-- list when opening a case instead of typing it free-form. cases.case_name
-- still stores the chosen text, so renaming or removing a preset never
-- touches cases that were already opened.

CREATE TABLE IF NOT EXISTS case_name_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case_name_presets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starter list so doctors can open cases right after deploy; the admin can
-- edit or remove any of these from the "+" button in the new-case dialog.
INSERT IGNORE INTO case_name_presets (name, sort_order) VALUES
('پاککردنەوەی ددان', 1),
('پڕکردنەوە', 2),
('دەرهێنان', 3),
('گەڕی ڕەگ', 4),
('تاج', 5),
('پرد', 6),
('پەلکەزێرینە', 7),
('چاندن', 8),
('سپیکردنەوە', 9),
('پشکنینی گشتی', 10);
