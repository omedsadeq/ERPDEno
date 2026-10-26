-- ڕەچەتە: each doctor's prescription sheet (A5 landscape, erp2/prescription-print.php).
--
-- The left half of the sheet is the doctor's own panel: title, name, the
-- credentials one per line, the English degrees, phones, address and two QR
-- codes — or, panel_mode 'image', a ready-made design uploaded as a picture
-- (uploads/rx/), printed exactly as it is. Empty fields fall back to the
-- clinic's settings (classes/RxProfile.php). Edited by the admin on
-- admin/doctor-rx.php, opened from the users page.

CREATE TABLE IF NOT EXISTS doctor_rx_profiles (
    user_id      INT          NOT NULL,
    panel_mode   ENUM('fields','image') NOT NULL DEFAULT 'fields',
    title        VARCHAR(60)  NULL,
    display_name VARCHAR(150) NULL,
    credentials  TEXT         NULL,
    degrees      VARCHAR(300) NULL,
    phones       VARCHAR(150) NULL,
    address      VARCHAR(255) NULL,
    qr1_url      VARCHAR(500) NULL,
    qr2_url      VARCHAR(500) NULL,
    qr_caption   VARCHAR(150) NULL,
    panel_image  VARCHAR(255) NULL,
    updated_at   DATETIME     NULL,
    updated_by   INT          NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_rxprof_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dr. Hêrsh's sheet as designed in «lezan a5 v3.pdf»: his panel is the
-- PDF's left half (uploads/rx/panel_doctor_8.jpg); the same text is filled in
-- so switching to 'fields' gives the same sheet. Only when user 8 is him.
INSERT IGNORE INTO doctor_rx_profiles
    (user_id, panel_mode, title, display_name, credentials, degrees, phones, address, qr1_url, qr2_url, panel_image)
SELECT id, 'image', 'دکتۆر', 'هێرش حسن شێروانی',
       'ماستەر لە چاندنی ددان - فرانکفۆرت - ئەڵمانیا\nدیبلۆمی بۆردی کۆنگرێسی نێودەوڵەتی پزیشکانی چاندنی ددان . ئەمریکا\nماستەر لە راستکردنەوە و جوانکاری ددان\nبەکالۆریۆس لە پزیشکی و نەشتەرگەری دەم و ددان\nوانەبێژ و ڕاهێنەر لە ئەکادیمیای سویسری بۆ چاندنی ددان\nشارەزا لە چاندن و لێنانەوەی ددان',
       'B.D.S., M. Sc (Implantology), M. Sc (Orthodontics), BCD, (ICOI).',
       '0750 693 2626 - 0750 632 7575',
       'سۆران، ترافیک لایتی دەروازەی سۆران\nنزیک سۆران کڵاس',
       'https://lezan.zediamarketing.com/', 'https://lezanclinics.carrd.co/',
       'uploads/rx/panel_doctor_8.jpg'
FROM users WHERE id = 8 AND full_name LIKE '%هێرش%';
