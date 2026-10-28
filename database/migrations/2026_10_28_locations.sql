-- شوێن: where a patient lives, in four steps — پارێزگا → ئیدارە (optional) →
-- شار / قەزا → گەڕەک / ناحیە / گوند. new_visit.php picks one place from it
-- (the patient's city stays in patients.city, as before; the neighbourhood goes
-- to patients.area and the place itself to patients.location_id), and its
-- «+» adds a new one, all three names required.
--
-- Seeded with the Soran administration as soran.gov.krd lists it (districts and
-- sub-districts) and Soran's best-known neighbourhoods; the rest is added from
-- the visit form. Every insert checks first, so the file is safe to re-run.

CREATE TABLE IF NOT EXISTS locations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    parent_id   INT NULL,
    level       ENUM('province', 'region', 'city', 'area') NOT NULL,
    name        VARCHAR(100) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_locations_parent (parent_id),
    KEY idx_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE patients ADD COLUMN IF NOT EXISTS area VARCHAR(100) NULL AFTER city;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS location_id INT NULL AFTER area;

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT NULL, 'province', 'هەولێر', 10 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'region', 'ئیدارەی سەربەخۆی سۆران', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ئیدارەی سەربەخۆی سۆران') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'سۆران', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'سۆران') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی سۆران', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی سۆران') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ئازادی', 20 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ئازادی') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'زانکۆ', 30 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'زانکۆ') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'دیانا', 40 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'دیانا') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'ڕەواندز', 20 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ڕەواندز') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی ڕەواندز', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'ڕەواندز') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی ڕەواندز') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'وەرتێ', 20 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'ڕەواندز') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'وەرتێ') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'خەلیفان', 30 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'خەلیفان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی خەلیفان', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'خەلیفان') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی خەلیفان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'سیدەکان', 40 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'سیدەکان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی سیدەکان', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'سیدەکان') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی سیدەکان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'چۆمان', 50 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'چۆمان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی چۆمان', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'چۆمان') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی چۆمان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'حاجی ئۆمەران', 20 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'چۆمان') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'حاجی ئۆمەران') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'سمیلان', 30 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'چۆمان') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'سمیلان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'قەسرێ', 40 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'چۆمان') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'قەسرێ') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'گەڵاڵە', 50 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'چۆمان') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'گەڵاڵە') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'مێرگەسۆر', 60 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'مێرگەسۆر') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی مێرگەسۆر', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'مێرگەسۆر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی مێرگەسۆر') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'بارزان', 20 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'مێرگەسۆر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'بارزان') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'شێروانی مەزن', 30 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'مێرگەسۆر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'شێروانی مەزن') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'مەزنێ', 40 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'مێرگەسۆر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'مەزنێ') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'گۆرەتوو', 50 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'مێرگەسۆر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'گۆرەتوو') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'پیران', 60 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'ئیدارەی سەربەخۆی سۆران') AND name = 'مێرگەسۆر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'پیران') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'هەولێر', 20 FROM (SELECT (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'هەولێر') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی هەولێر', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'هەولێر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی هەولێر') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'شەقڵاوە', 30 FROM (SELECT (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'شەقڵاوە') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی شەقڵاوە', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'شەقڵاوە') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی شەقڵاوە') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'هەریر', 20 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'شەقڵاوە') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'هەریر') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'کۆیە', 40 FROM (SELECT (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'کۆیە') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی کۆیە', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەولێر') AND name = 'کۆیە') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی کۆیە') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT NULL, 'province', 'سلێمانی', 20 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'سلێمانی') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'سلێمانی', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'سلێمانی') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'سلێمانی') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی سلێمانی', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'سلێمانی') AND name = 'سلێمانی') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی سلێمانی') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT NULL, 'province', 'دهۆک', 30 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'دهۆک') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'دهۆک', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'دهۆک') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'دهۆک') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی دهۆک', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'دهۆک') AND name = 'دهۆک') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی دهۆک') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT NULL, 'province', 'هەڵەبجە', 40 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەڵەبجە') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'city', 'هەڵەبجە', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەڵەبجە') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'هەڵەبجە') x);

INSERT INTO locations (parent_id, level, name, sort_order)
SELECT pid, 'area', 'ناوەندی هەڵەبجە', 10 FROM (SELECT (SELECT id FROM locations WHERE parent_id = (SELECT id FROM locations WHERE parent_id IS NULL AND name = 'هەڵەبجە') AND name = 'هەڵەبجە') AS pid) par
WHERE pid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM locations WHERE parent_id = par.pid AND name = 'ناوەندی هەڵەبجە') x);
