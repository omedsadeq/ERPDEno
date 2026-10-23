-- Migration: Create xray_pricing table
-- Date: 2026-04-21
-- Description: Price matrix for X-ray by type and selection mode

CREATE TABLE IF NOT EXISTS xray_pricing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    xray_type ENUM('panoramic','cbct','periapical','cephalometric') NOT NULL,
    pricing_mode ENUM('full_mouth','left_side','right_side','upper_arch','lower_arch','per_tooth','single') NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_xray_price (xray_type, pricing_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed defaults (Iraqi Dinar)
INSERT INTO xray_pricing (xray_type, pricing_mode, price, sort_order) VALUES
-- Periapical
('periapical', 'full_mouth',   50000, 1),
('periapical', 'left_side',    25000, 2),
('periapical', 'right_side',   25000, 3),
('periapical', 'upper_arch',   25000, 4),
('periapical', 'lower_arch',   25000, 5),
('periapical', 'per_tooth',     3000, 6),
('periapical', 'single',        5000, 7),
-- Panoramic
('panoramic',  'full_mouth',   20000, 1),
('panoramic',  'single',       20000, 2),
-- CBCT
('cbct',       'full_mouth',  150000, 1),
('cbct',       'upper_arch',   75000, 2),
('cbct',       'lower_arch',   75000, 3),
('cbct',       'single',       75000, 4),
-- Cephalometric
('cephalometric','single',     25000, 1);
