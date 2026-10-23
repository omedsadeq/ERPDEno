-- نوسراوی فەرمی — outgoing official letters (letters/).
--
-- One row per letter the clinic sends out: number + date, بۆ, بابەت, the
-- text, who signs it and «وێنەیەک بۆ». The letterhead itself (logo, header
-- lines, footer, signature/seal images, numbering format) is NOT stored per
-- letter: it lives in `settings` under letter_* keys, so changing the
-- letterhead once restyles every reprint. See classes/OfficialLetter.php.
--
-- Numbers: seq_no is the numeric part of letter_number, counted per seq_year
-- (the year of letter_date) so the auto-numbering can restart every year.
-- A number typed by hand is parsed back into seq_no, so typing «150» once
-- makes the next automatic number 151. A letter is cancelled, never
-- renumbered: its number stays taken and the archive keeps it, struck out.

CREATE TABLE IF NOT EXISTS official_letter_templates (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    recipient      VARCHAR(255) DEFAULT NULL,
    subject        VARCHAR(255) DEFAULT NULL,
    reference_text VARCHAR(255) DEFAULT NULL,
    greeting       VARCHAR(255) DEFAULT NULL,
    body           MEDIUMTEXT   DEFAULT NULL,
    closing        VARCHAR(255) DEFAULT NULL,
    signer_name    VARCHAR(150) DEFAULT NULL,
    signer_title   VARCHAR(255) DEFAULT NULL,
    cc_list        TEXT         DEFAULT NULL,
    attachments    TEXT         DEFAULT NULL,
    sort_order     INT          NOT NULL DEFAULT 0,
    created_by     INT          DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     DEFAULT NULL,
    KEY idx_letter_tpl_sort (sort_order, name),
    CONSTRAINT fk_letter_tpl_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS official_letters (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    letter_number  VARCHAR(60)  NOT NULL,
    seq_year       SMALLINT UNSIGNED NOT NULL,
    seq_no         INT UNSIGNED NOT NULL DEFAULT 0,
    letter_date    DATE         NOT NULL,
    recipient      VARCHAR(255) NOT NULL,
    subject        VARCHAR(255) NOT NULL,
    reference_text VARCHAR(255) DEFAULT NULL,
    greeting       VARCHAR(255) DEFAULT NULL,
    body           MEDIUMTEXT   NOT NULL,
    closing        VARCHAR(255) DEFAULT NULL,
    signer_name    VARCHAR(150) DEFAULT NULL,
    signer_title   VARCHAR(255) DEFAULT NULL,
    cc_list        TEXT         DEFAULT NULL,
    attachments    TEXT         DEFAULT NULL,
    show_signature TINYINT(1)   NOT NULL DEFAULT 0,
    show_seal      TINYINT(1)   NOT NULL DEFAULT 0,
    template_id    INT UNSIGNED DEFAULT NULL,
    status         ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    cancel_reason  VARCHAR(255) DEFAULT NULL,
    cancelled_at   DATETIME     DEFAULT NULL,
    cancelled_by   INT          DEFAULT NULL,
    created_by     INT          DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by     INT          DEFAULT NULL,
    updated_at     DATETIME     DEFAULT NULL,
    UNIQUE KEY uq_letter_year_number (seq_year, letter_number),
    KEY idx_letter_seq (seq_year, seq_no),
    KEY idx_letter_date (letter_date),
    KEY idx_letter_recipient (recipient(100)),
    KEY idx_letter_status (status),
    CONSTRAINT fk_letter_template  FOREIGN KEY (template_id)  REFERENCES official_letter_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_letter_creator   FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_letter_updater   FOREIGN KEY (updated_by)   REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_letter_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Three keys. VIEW is the archive and reprints; CREATE is writing, editing
-- and cancelling letters plus keeping the reusable templates; SETTINGS is the
-- letterhead — including the uploaded signature and seal, which is why it is
-- admin-only until someone is deliberately given it.
INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'letters.view' AS k, 'View Official Letters' AS n, 'بینینی نوسراوە فەرمییەکان' AS ku, 'letters' AS c, NULL AS d
    UNION ALL
    SELECT 'letters.create', 'Write Official Letters', 'نووسین و دەستکاریکردنی نوسراوی فەرمی', 'letters', NULL
    UNION ALL
    SELECT 'letters.settings', 'Letterhead, Signature & Seal', 'ڕێکخستنی سەردێڕ و واژۆ و مۆر', 'letters', NULL
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'receptionist' AS r, 'letters.view' AS k, 1 AS a
    UNION ALL SELECT 'accountant',      'letters.view', 1
    UNION ALL SELECT 'doctor',          'letters.view', 0
    UNION ALL SELECT 'xray_department', 'letters.view', 0

    UNION ALL SELECT 'receptionist',    'letters.create', 1
    UNION ALL SELECT 'accountant',      'letters.create', 1
    UNION ALL SELECT 'doctor',          'letters.create', 0
    UNION ALL SELECT 'xray_department', 'letters.create', 0

    UNION ALL SELECT 'receptionist',    'letters.settings', 0
    UNION ALL SELECT 'accountant',      'letters.settings', 0
    UNION ALL SELECT 'doctor',          'letters.settings', 0
    UNION ALL SELECT 'xray_department', 'letters.settings', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);

-- Starter templates. Plain rows: edit or delete them from letters/templates.php.
-- Text in [ ] is a blank to fill in — the preview highlights any left behind.
INSERT INTO official_letter_templates (name, recipient, subject, body, sort_order)
SELECT * FROM (
    SELECT
        'داواکاری لێخۆشبوون لە غەرامە' AS n,
        'بەڕێوەبەرایەتی باجی دەرامەت' AS r,
        'داواکاری لێخۆشبوون لە غەرامە' AS s,
        'بەڕێزتان ئاگادار دەکەینەوە کە سەنتەرەکەمان بەهۆی [هۆکار] نەیتوانیوە لە کاتی دیاریکراودا [بابەت] جێبەجێ بکات، بۆیە داواکارین بە چاوی ڕەزامەندییەوە سەیری داواکارییەکەمان بکەن و لە غەرامەی دواکەوتن لێمان خۆش بن.\n\nهیوادارین داواکارییەکەمان جێگەی ڕەزامەندی بەڕێزتان بێت.' AS b,
        10 AS o
    UNION ALL SELECT
        'پشتگیری کارکردن (تەئیید)',
        'هەر لایەنێکی پەیوەندیدار',
        'پشتگیری کارکردن',
        'بەڕێزتان ئاگادار دەکەینەوە کە بەڕێز ([ناوی کارمەند]) کارمەندە لە سەنتەرەکەماندا بە ناونیشانی ([ناونیشانی وەزیفی]) لە بەرواری ([بەروار]) ەوە تا ئێستا.\n\nئەم نوسراوە لەسەر داوای خۆی پێدرا.',
        20
    UNION ALL SELECT
        'پشتگیری سەردانی نەخۆش',
        '[ناوی فەرمانگەی نەخۆش]',
        'پشتگیری سەردانی نەخۆش',
        'بەڕێزتان ئاگادار دەکەینەوە کە بەڕێز ([ناوی نەخۆش]) لە بەرواری ([بەروار]) سەردانی سەنتەرەکەمانی کردووە بۆ وەرگرتنی چارەسەری ددان، و پێویستی بە ([ژمارە]) ڕۆژ پشوو هەیە.\n\nئەم نوسراوە لەسەر داوای خۆی پێدرا.',
        30
) t
WHERE NOT EXISTS (SELECT 1 FROM official_letter_templates);
