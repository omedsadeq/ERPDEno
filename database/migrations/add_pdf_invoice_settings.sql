-- PDF Invoice Settings Table
-- Creates centralized configuration for all mPDF invoices
-- Created: 2026-01-06

CREATE TABLE IF NOT EXISTS `pdf_invoice_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,

    -- Header Settings
    `header_image_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to header image',
    `header_html` TEXT DEFAULT NULL COMMENT 'Custom HTML header content',
    `header_height_mm` INT(11) DEFAULT 30 COMMENT 'Header height in mm',
    `show_header` TINYINT(1) DEFAULT 1 COMMENT 'Show header on invoices',

    -- Footer Settings
    `footer_image_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to footer image',
    `footer_html` TEXT DEFAULT NULL COMMENT 'Custom HTML footer content',
    `footer_height_mm` INT(11) DEFAULT 25 COMMENT 'Footer height in mm',
    `show_footer` TINYINT(1) DEFAULT 1 COMMENT 'Show footer on invoices',

    -- Margin Settings (in mm)
    `margin_left_mm` INT(11) DEFAULT 15,
    `margin_right_mm` INT(11) DEFAULT 15,
    `margin_top_mm` INT(11) DEFAULT 40 COMMENT 'Must be >= header_height + 10',
    `margin_bottom_mm` INT(11) DEFAULT 35 COMMENT 'Must be >= footer_height + 10',

    -- Font & Styling
    `font_family` VARCHAR(100) DEFAULT 'XBRiyaz' COMMENT 'Default font family',
    `font_size` INT(11) DEFAULT 12 COMMENT 'Default font size in pt',
    `primary_color` VARCHAR(20) DEFAULT '#2c5282' COMMENT 'Primary brand color',
    `secondary_color` VARCHAR(20) DEFAULT '#4a5568' COMMENT 'Secondary color',
    `table_header_bg` VARCHAR(20) DEFAULT '#edf2f7' COMMENT 'Table header background',
    `table_border_color` VARCHAR(20) DEFAULT '#cbd5e0' COMMENT 'Table border color',

    -- Page Settings
    `page_format` VARCHAR(20) DEFAULT 'A4' COMMENT 'Paper format: A4, A5, Letter',
    `page_orientation` CHAR(1) DEFAULT 'P' COMMENT 'P=Portrait, L=Landscape',
    `direction` VARCHAR(5) DEFAULT 'rtl' COMMENT 'Text direction: rtl or ltr',

    -- Watermark
    `watermark_text` VARCHAR(255) DEFAULT NULL COMMENT 'Watermark text (optional)',
    `watermark_opacity` DECIMAL(3,2) DEFAULT 0.10 COMMENT 'Watermark opacity 0-1',

    -- Page Numbers
    `show_page_numbers` TINYINT(1) DEFAULT 1 COMMENT 'Show page numbers in footer',
    `page_number_format` VARCHAR(100) DEFAULT 'لاپەڕە {PAGENO} لە {nbpg}' COMMENT 'Page number format',

    -- Company Info (for header)
    `company_name` VARCHAR(255) DEFAULT NULL,
    `company_name_secondary` VARCHAR(255) DEFAULT NULL,
    `company_address` VARCHAR(500) DEFAULT NULL,
    `company_phone` VARCHAR(100) DEFAULT NULL,
    `company_email` VARCHAR(255) DEFAULT NULL,

    -- Additional CSS
    `custom_css` TEXT DEFAULT NULL COMMENT 'Additional CSS styles',

    -- Status
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Only one record should be active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO `pdf_invoice_settings` (
    `header_height_mm`,
    `footer_height_mm`,
    `margin_left_mm`,
    `margin_right_mm`,
    `margin_top_mm`,
    `margin_bottom_mm`,
    `font_family`,
    `font_size`,
    `primary_color`,
    `secondary_color`,
    `page_format`,
    `direction`,
    `show_page_numbers`,
    `is_active`
) VALUES (
    30,
    25,
    15,
    15,
    40,
    35,
    'XBRiyaz',
    12,
    '#2c5282',
    '#4a5568',
    'A4',
    'rtl',
    1,
    1
);
