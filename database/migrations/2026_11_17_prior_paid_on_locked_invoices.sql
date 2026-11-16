-- دراوی پێشوو لە وەسڵی نێردراو و پەسەندکراو (2026-11-17)
--
-- The doctor (and the admin) may still correct دراوی پێشوو of a historical
-- case after its receipt was sent or approved (Settings::
-- priorPaidOnLockedInvoices, CaseInvoice::setPriorPaid). The clinic asked for
-- it ON from the start; admin/settings.php (tab «دەستگەیشتن») turns it off.
-- INSERT IGNORE relies on the UNIQUE settings.setting_key: running it again
-- never overwrites what an admin has already chosen.

INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
    ('prior_paid_on_locked_invoices', '1', 'boolean', 'دکتۆر دەتوانێت دراوی پێشووی کەیسی کۆن لە وەسڵی نێردراو و پەسەندکراویش بگۆڕێت');
