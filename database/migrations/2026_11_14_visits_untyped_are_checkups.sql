-- The visits of the old system came in (2026-07-10) with no visit type: when
-- the system began it had one kind of visit only, and ئیش was added later, so
-- every one of them was a پشکنین. Give them that type, so the visit statistics
-- (visits-report.php) count them like every visit since.
--
-- A visit has had to have a type since then (new_visit.php refuses one without),
-- so only those old visits are empty here. The two places that read an empty
-- type (accountant/financial-summary.php, doctors/profile.php) already took it
-- as «not ئیش», the same as پشکنین, so their figures do not change.
UPDATE visits SET visit_type = 'checkup' WHERE visit_type IS NULL;
