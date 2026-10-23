-- Migration: Add case images table for storing 6 images per case
-- Date: 2026-01-18
-- Description: Allows uploading up to 6 images per case with S3 support

CREATE TABLE IF NOT EXISTS case_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    slot_number TINYINT NOT NULL DEFAULT 1 COMMENT 'Image slot 1-6',
    image_path VARCHAR(1000) NOT NULL,
    s3_key VARCHAR(500) NULL,
    image_type VARCHAR(10) NOT NULL DEFAULT 'jpg',
    original_filename VARCHAR(255) NULL,
    file_size INT NULL,
    description VARCHAR(500) NULL,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY unique_case_slot (case_id, slot_number),
    INDEX idx_case_id (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
