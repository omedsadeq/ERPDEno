-- Migration: Create cancellation_reasons table
-- Date: 2025-12-31
-- Description: Dynamic cancellation reasons managed by admin

CREATE TABLE IF NOT EXISTS cancellation_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason_key VARCHAR(50) NOT NULL UNIQUE,
    reason_text VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default reasons
INSERT INTO cancellation_reasons (reason_key, reason_text, sort_order) VALUES
('patient_request', 'نەخۆش داوای هەڵوەشاندنەوەی کرد', 1),
('patient_no_answer', 'نەخۆش وەڵامی نەدایەوە', 2),
('patient_sick', 'نەخۆش نەخۆشە', 3),
('schedule_conflict', 'کێشەی کات', 4),
('doctor_unavailable', 'دکتۆر بەردەست نییە', 5),
('emergency', 'کەیسی فریاگوزاری', 6),
('other', 'هۆکاری تر', 99);
