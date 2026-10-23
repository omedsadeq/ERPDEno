-- Migration: Create cities lookup table
-- Date: 2026-05-17
-- Purpose: Replace hard-coded city list in new_visit.php with a DB-backed
--          lookup so receptionists can add new cities at runtime via UI.

CREATE TABLE IF NOT EXISTS `cities` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(100) NOT NULL,
    `province`   VARCHAR(100) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_city_name` (`name`),
    KEY `idx_city_province` (`province`),
    KEY `idx_city_active`   (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial Iraqi Kurdistan + major Iraqi cities.
-- INSERT IGNORE so re-runs are safe (unique key on name).

INSERT IGNORE INTO `cities` (`name`, `province`, `sort_order`) VALUES
    ('سلێمانی',      'پارێزگای سلێمانی', 10),
    ('ڕانیە',         'پارێزگای سلێمانی', 20),
    ('قەڵادزێ',      'پارێزگای سلێمانی', 30),
    ('چەمچەماڵ',     'پارێزگای سلێمانی', 40),
    ('کفری',         'پارێزگای سلێمانی', 50),
    ('کەلار',         'پارێزگای سلێمانی', 60),
    ('دەربەندیخان',  'پارێزگای سلێمانی', 70),
    ('پێنجوێن',      'پارێزگای سلێمانی', 80),
    ('شارباژێر',     'پارێزگای سلێمانی', 90),
    ('سەید سادق',    'پارێزگای سلێمانی', 100),
    ('بازیان',        'پارێزگای سلێمانی', 110),
    ('دوکان',         'پارێزگای سلێمانی', 120),
    ('تەکیە',         'پارێزگای سلێمانی', 130),
    ('هەڵەبجە',      'پارێزگای سلێمانی', 140),

    ('هەولێر',        'پارێزگای هەولێر',   210),
    ('شەقڵاوە',      'پارێزگای هەولێر',   220),
    ('سۆران',         'پارێزگای هەولێر',   230),
    ('کۆیە',          'پارێزگای هەولێر',   240),
    ('مەخمور',       'پارێزگای هەولێر',   250),
    ('چۆمان',         'پارێزگای هەولێر',   260),
    ('ڕواندز',       'پارێزگای هەولێر',   270),

    ('دهۆک',         'پارێزگای دهۆک',     310),
    ('زاخۆ',          'پارێزگای دهۆک',     320),
    ('ئامێدی',       'پارێزگای دهۆک',     330),
    ('ئاکرێ',        'پارێزگای دهۆک',     340),
    ('بەعدرە',       'پارێزگای دهۆک',     350),
    ('سیمێل',        'پارێزگای دهۆک',     360),

    ('کەرکوک',       'شارە تر',           410),
    ('بەغدا',         'شارە تر',           420),
    ('موسڵ',          'شارە تر',           430),
    ('بەسرە',         'شارە تر',           440),
    ('نەجەف',         'شارە تر',           450),
    ('کەربەلا',      'شارە تر',           460);
