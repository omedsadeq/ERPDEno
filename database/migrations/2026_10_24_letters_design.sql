-- نوسراوی فەرمی — two letter designs (letters/settings.php).
--
--   classic  the original sheet: Kurdish labels (ژمارە / ڕێکەوت / بۆ / بابەت)
--   modern   the clinic's designed letterhead: English Number / Date / Subject,
--            the logo faded behind the text, a coloured wave footer with the
--            phones, a QR code and the address
--
-- Each letter keeps the design it was written in, so a reprint never changes
-- look when the default in the settings changes. Letters written before this
-- were all classic. A template's design is a preference only: NULL = use the
-- default from the settings.

ALTER TABLE official_letters
    ADD COLUMN design VARCHAR(20) NOT NULL DEFAULT 'classic' AFTER template_id;

ALTER TABLE official_letter_templates
    ADD COLUMN design VARCHAR(20) DEFAULT NULL AFTER attachments;
