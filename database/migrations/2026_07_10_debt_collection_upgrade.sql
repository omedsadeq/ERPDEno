-- Migration: Debt collection upgrade (2026-07-10)
-- 1) Payment promises: when a patient says "I'll pay X on date Y" during a
--    call, store it so broken promises can be chased first.
-- 2) Opt-out flag: patients we agreed not to call (family, special cases)
--    stay out of the daily call queue.

ALTER TABLE call_logs
    ADD COLUMN promised_amount DECIMAL(12,0) DEFAULT NULL AFTER report_note,
    ADD COLUMN promised_date DATE DEFAULT NULL AFTER promised_amount;

ALTER TABLE patients
    ADD COLUMN debt_call_optout TINYINT(1) NOT NULL DEFAULT 0;
