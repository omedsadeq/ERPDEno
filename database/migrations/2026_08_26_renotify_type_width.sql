-- ============================================================
-- Re-widen notifications.type (fresh/scratch-DB migration ordering fix)
--
-- database/migrations/2026_07_10_notifications_v2.sql widens notifications.type
-- to VARCHAR(50). database/migrations/add_xray_module.sql later re-narrows it
-- to ENUM('appointment','payment','installment','lab','xray','system') NOT
-- NULL — a leftover from before notifications_v2 introduced free-form types
-- like 'invoice_sent', 'installment_plan', 'payment_pending', etc. On a LIVE
-- install this never bit, because add_xray_module.sql was applied long before
-- notifications_v2 existed (chronological deploy order). On a FRESH database
-- MigrationRunner applies every *.sql file in one pass, sorted by filename —
-- and because ASCII digits sort before ASCII letters, EVERY 2026_MM_DD_*.sql
-- file (this one included) sorts and therefore runs BEFORE add_xray_module.sql
-- (starts with 'a'), so add_xray_module.sql's narrowing always wins on a
-- from-scratch DB, truncating any type value that isn't one of its six enum
-- members (see tests/bootstrap.php's own note about this exact ordering gap).
--
-- Re-applying the widen here (copied verbatim from notifications_v2) would
-- therefore just get immediately undone by add_xray_module.sql running right
-- after it in the same MigrationRunner pass. To actually win, this file must
-- execute in a LATER pass than add_xray_module.sql. MigrationRunner already
-- has a mechanism for that (documented in tests/bootstrap.php): a statement
-- that fails because a dependency doesn't exist yet is left unrecorded and
-- retried on the next pass. The probe below references xray_requests, a
-- table add_xray_module.sql itself creates: on pass 1 (before that table
-- exists) it fails with "table doesn't exist", so this whole file is retried
-- on pass 2 — by which point add_xray_module.sql has already run, and the
-- MODIFY below is the last word. On an install where add_xray_module.sql
-- already ran (live, or any already-migrated DB), xray_requests already
-- exists, the probe succeeds immediately, and this is a harmless no-op re-widen.
-- ============================================================

ALTER TABLE notifications MODIFY COLUMN type VARCHAR(50) NOT NULL;

-- Ordering probe (see above) — no data/schema impact beyond its own comment.
ALTER TABLE xray_requests COMMENT = 'X-ray request records (see add_xray_module.sql)';
