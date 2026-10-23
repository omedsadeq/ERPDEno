-- Migration: Create waiting_room_rejection_reasons table
-- Description: Stores configurable reasons for rejecting waiting-room entries

CREATE TABLE IF NOT EXISTS waiting_room_rejection_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason_key VARCHAR(100) NOT NULL UNIQUE,
    reason_text VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO waiting_room_rejection_reasons (reason_key, reason_text, sort_order)
SELECT 'patient_not_present', 'نەخۆش نەهات', 1
WHERE NOT EXISTS (SELECT 1 FROM waiting_room_rejection_reasons WHERE reason_key = 'patient_not_present');

INSERT INTO waiting_room_rejection_reasons (reason_key, reason_text, sort_order)
SELECT 'not_suitable_time', 'کاتی نەگونجاو', 2
WHERE NOT EXISTS (SELECT 1 FROM waiting_room_rejection_reasons WHERE reason_key = 'not_suitable_time');

INSERT INTO waiting_room_rejection_reasons (reason_key, reason_text, sort_order)
SELECT 'patient_request', 'داوای نەخۆش', 3
WHERE NOT EXISTS (SELECT 1 FROM waiting_room_rejection_reasons WHERE reason_key = 'patient_request');

INSERT INTO waiting_room_rejection_reasons (reason_key, reason_text, sort_order)
SELECT 'medical_reason', 'هۆکاری پزیشکی', 4
WHERE NOT EXISTS (SELECT 1 FROM waiting_room_rejection_reasons WHERE reason_key = 'medical_reason');

INSERT INTO waiting_room_rejection_reasons (reason_key, reason_text, sort_order)
SELECT 'other', 'هۆکاری تر', 99
WHERE NOT EXISTS (SELECT 1 FROM waiting_room_rejection_reasons WHERE reason_key = 'other');
