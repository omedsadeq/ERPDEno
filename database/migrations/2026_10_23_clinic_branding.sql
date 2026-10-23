-- Clinic branding — the centre's identity on one settings page (2026-10-23)
-- ناسنامەی سەنتەر: ناو، پەیوەندی، لۆگۆ و ڕەنگ لە یەک شوێن
--
-- classes/Branding.php reads every key below and admin/settings.php
-- (tab «ناسنامە و ڕەنگ») edits them. The values are Lezan's own, so a new
-- install looks exactly like Lezan until an admin changes them for another
-- centre. INSERT IGNORE relies on the UNIQUE key of settings.setting_key:
-- re-running never overwrites a value an admin has already saved.

INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
('clinic_name',         'سەنتەری لێزان',                 'text',  'ناوی سەنتەر — ناونیشانی پەڕەکان، چوونەژوورەوە، لیستی لاتەنیشت'),
('clinic_name_en',      'Lezan Dental Clinics',          'text',  'ناوی سەنتەر بە ئینگلیزی'),
('clinic_short_name',   'لێزان',                         'text',  'ناوی کورت — «قاسەی لێزان»، «پشکی لێزان» لە ژمێریاری'),
('clinic_print_name',   'لێزان بۆ پزیشکی ددان',          'text',  'ناوی سەر وەسڵ و ڕاپۆرتەکان (بەتاڵ = ناوی سەنتەر)'),
('clinic_phone',        '0750 693 2626',                 'text',  'ژمارەی پەیوەندی ١'),
('clinic_phone_2',      '0750 632 7575',                 'text',  'ژمارەی پەیوەندی ٢'),
('clinic_phone_3',      '0751 192 2326',                 'text',  'ژمارەی پەیوەندی ٣ (وەسڵی ئێکسڕە)'),
('clinic_address',      'سۆران: ترافیک لایەتی دەروازەی سۆران، بەرامبەر میلان کڵاس', 'text', 'ناونیشان'),
('clinic_email',        '',                              'text',  'ئیمەیڵ'),
('clinic_facebook',     'Lezan Dental Clinics',          'text',  'فەیسبووک (خوارەوەی وەسڵی ئێکسڕە)'),
('clinic_instagram',    'lezan.dental.clinics',          'text',  'ئینستاگرام'),
('clinic_social_other', 'lezanclinics',                  'text',  'ئەکاونتی تر'),
('clinic_website',      'www.lezandentalclinics.com',    'text',  'ماڵپەڕ'),
('clinic_logo_path',    '',                              'image', 'لۆگۆی بارکراو (بەتاڵ = لۆگۆی بنەڕەتی assets/images/logo.png)'),
('theme_primary_color', '#0078D4',                       'color', 'ڕەنگی سەرەکی سیستەم'),
('print_accent_color',  '#29a3dc',                       'color', 'ڕەنگی وەسڵەکان');

-- Install-time placeholders. The receipts used to swap these for Lezan's real
-- details on the fly (includes/clinic_branding.php); store the real values so
-- the settings page shows what is actually printed.
UPDATE settings SET setting_value = 'سەنتەری لێزان'
 WHERE setting_key = 'clinic_name'
   AND (TRIM(COALESCE(setting_value, '')) = '' OR setting_value IN ('کلینیکی ددانی نوێ', 'سەنتەری ددان'));

UPDATE settings SET setting_value = '0750 693 2626'
 WHERE setting_key = 'clinic_phone'
   AND (TRIM(COALESCE(setting_value, '')) = ''
        OR REPLACE(REPLACE(setting_value, '-', ''), ' ', '') REGEXP '0{5,}');

UPDATE settings SET setting_value = '0750 632 7575'
 WHERE setting_key = 'clinic_phone_2'
   AND (TRIM(COALESCE(setting_value, '')) = ''
        OR REPLACE(REPLACE(setting_value, '-', ''), ' ', '') REGEXP '0{5,}');

UPDATE settings SET setting_value = ''
 WHERE setting_key = 'clinic_email' AND setting_value = 'info@dental.com';
