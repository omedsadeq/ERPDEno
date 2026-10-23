-- Migration: Update X-Ray Workflow - Doctor → Accountant → X-Ray Dept
-- Date: 2026-03-09
-- Description: Changes X-ray workflow:
--   OLD: Doctor → X-ray Dept (scan + mark debt) → Admin (payment)
--   NEW: Doctor → Accountant (payment first) → X-ray Dept (scan)
--   Accountant handles billing, then sends to X-ray dept to do the scan.

-- ── 1. Grant Accountant Access to X-Ray Pages ──────────────────────
UPDATE role_permissions
SET is_allowed = 1
WHERE role = 'accountant'
AND permission_key IN ('page.xray', 'page.xray_payments', 'page.xray_history');

-- If rows don't exist yet, insert them
INSERT INTO role_permissions (role, permission_key, is_allowed) VALUES
('accountant', 'page.xray',          1),
('accountant', 'page.xray_payments', 1),
('accountant', 'page.xray_history',  1)
ON DUPLICATE KEY UPDATE is_allowed = 1;
