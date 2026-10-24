/*
 * سەرچاوە مرۆییەکان — grace minutes per shift, for the late-arrival report.
 *
 * Arriving up to this many minutes after the shift starts is not counted as
 * late. 15, as in Panjamor (its shifts.grace_period_minutes and
 * company_settings.late_threshold_minutes). It changes no pay.
 */

ALTER TABLE hr_shifts ADD COLUMN grace_minutes INT NOT NULL DEFAULT 15 AFTER break_minutes;
