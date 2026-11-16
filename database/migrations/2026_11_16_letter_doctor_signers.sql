-- نوسراوی فەرمی — every doctor signs with their own block (letters/settings.php).
--
-- The modern sheet's signature corner: the name, then the lines under it
-- (specialty, degree, certificate, post), and the doctor's own signature
-- image. One row per doctor; a letter remembers whose block it carries
-- (official_letters.signer_doctor_id, NULL = the centre's own signer from the
-- settings), so it prints that doctor's signature and nobody else's.

CREATE TABLE IF NOT EXISTS official_letter_signers (
    doctor_id      INT NOT NULL PRIMARY KEY,
    signer_name    VARCHAR(150) NULL,
    signer_title   VARCHAR(255) NULL,
    signature_file VARCHAR(255) NULL,
    updated_by     INT NULL,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE official_letters
    ADD COLUMN signer_doctor_id INT NULL AFTER signer_title,
    ADD KEY idx_letters_signer_doctor (signer_doctor_id);

ALTER TABLE official_letter_templates
    ADD COLUMN signer_doctor_id INT NULL AFTER signer_title;

-- Only the modern sheet is left: the classic one was taken away.
UPDATE official_letters SET design = 'modern' WHERE design <> 'modern';
UPDATE official_letter_templates SET design = NULL WHERE design IS NOT NULL;
ALTER TABLE official_letters ALTER COLUMN design SET DEFAULT 'modern';
UPDATE settings SET setting_value = 'modern' WHERE setting_key = 'letter_default_design';
