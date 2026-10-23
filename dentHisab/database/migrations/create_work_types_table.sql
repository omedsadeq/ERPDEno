-- Migration: Create work_types table
-- Date: 2026-04-18
-- Description: Dynamic list of dental work types for appointments

CREATE TABLE IF NOT EXISTS work_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    work_key VARCHAR(50) NOT NULL UNIQUE,
    work_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO work_types (work_key, work_name, sort_order) VALUES
('cleaning', 'پاککردنەوەی ددان', 1),
('filling', 'پڕکردنەوە', 2),
('extraction', 'دەرهێنان', 3),
('root_canal', 'گەڕی ڕەگ', 4),
('crown', 'تاج', 5),
('bridge', 'پرد', 6),
('braces', 'پەلکەزێرینە', 7),
('implant', 'چاندن', 8),
('whitening', 'سپیکردنەوە', 9),
('checkup', 'پشکنینی گشتی', 10);
