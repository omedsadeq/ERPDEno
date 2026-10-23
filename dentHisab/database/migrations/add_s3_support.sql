-- Migration: Add S3 support for X-Ray images
-- Date: 2026-01-18
-- Description: Add s3_key column to xray_images table for S3 storage support

-- Add s3_key column to store the S3 object key for deletion
ALTER TABLE xray_images
ADD COLUMN s3_key VARCHAR(500) NULL AFTER image_path;

-- Add index for faster lookups
ALTER TABLE xray_images
ADD INDEX idx_s3_key (s3_key);

-- Update image_path column to support longer URLs (S3 URLs can be long)
ALTER TABLE xray_images
MODIFY COLUMN image_path VARCHAR(1000) NOT NULL;
