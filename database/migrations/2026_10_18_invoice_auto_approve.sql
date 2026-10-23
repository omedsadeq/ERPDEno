-- پەسەندکردنی خۆکاری شەوانە — every invoice still waiting at the accountant
-- is approved by classes/AutoApproval.php once a night (02:00 by default).
--
-- approved_auto = 1 marks an invoice approved by that run (approved_by stays
-- NULL), so the پەسەندکراو tab can tell 🤖 خۆکار from ✋ دەستی.
ALTER TABLE case_invoices
    ADD COLUMN IF NOT EXISTS approved_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER approved_at;

-- On by default (the clinic asked for it). last_run is set to the day this
-- shipped so the first automatic run is the FOLLOWING night, not minutes
-- after deployment; «ئێستا جێبەجێی بکە» on the settings page runs it sooner.
INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
    ('auto_approve_enabled', '1', 'boolean', 'پەسەندکردنی خۆکاری وەسڵە نێردراوەکان هەموو شەوێک'),
    ('auto_approve_time', '02:00', 'text', 'کاتی پەسەندکردنی خۆکاری شەوانە'),
    ('auto_approve_last_run', '2026-10-18', 'text', 'دوایین ڕۆژی پەسەندکردنی خۆکار'),
    ('auto_approve_last_result', '', 'text', 'ئەنجامی دوایین پەسەندکردنی خۆکار');
