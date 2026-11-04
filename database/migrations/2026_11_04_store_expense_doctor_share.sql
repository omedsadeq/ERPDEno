-- How much of the store expense written in a doctor's name falls on that doctor,
-- one percentage for ئاسایی and one for ئیمپلانت; the rest falls on لێزان.
-- Not users.commission_rate: that one splits income, and the two differ
-- (د.هێرش: 80 of the income, 50 of the store). NULL until it is set.

ALTER TABLE users ADD COLUMN store_share_normal DECIMAL(5,2) NULL DEFAULT NULL AFTER commission_rate;
ALTER TABLE users ADD COLUMN store_share_implant DECIMAL(5,2) NULL DEFAULT NULL AFTER store_share_normal;
