-- دابەشکردنی ڕاستەوخۆی پارە بە ڕێژە (CashboxLedger::syncSource).
-- Every receipt booked into a doctor's cashbox is split the moment it is
-- booked: his percentage (users.commission_rate) to his box, the rest to
-- قاسەی لێزان; his percentage of a lab/store expense moves from his box to
-- قاسەی لێزان. Each source keeps the percentage it was split at (split_rate),
-- so a later change of a doctor's percentage only affects new money.
--
-- booked_doctor IS NULL marks a row booked before the split (all of it in its
-- own box); switching the split on moves the centre's part of those once, in
-- one transfer per doctor (CashboxLedger::openingSplit).
ALTER TABLE cashbox_sources
    ADD COLUMN IF NOT EXISTS split_rate DECIMAL(5,2) NULL AFTER booked_amount,
    ADD COLUMN IF NOT EXISTS booked_doctor DECIMAL(17,2) NULL AFTER split_rate,
    ADD COLUMN IF NOT EXISTS booked_center DECIMAL(17,2) NULL AFTER booked_doctor;

-- Off until it is switched on (admin/settings.php?tab=access).
INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
    ('cashbox_split_enabled', '0', 'boolean', 'دابەشکردنی ڕاستەوخۆی پارە: ڕێژەی دکتۆر بۆ سندوقەکەی، ئەوەی تر بۆ قاسەی لێزان');
