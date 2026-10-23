-- Add an "archived" (hidden) status to accounting_reports so the accountant can
-- remove a report from the receiving feed / pending-reports lists via a button,
-- without deleting it. Archived reports are excluded from all active lists and
-- appear only under a dedicated "hidden" tab, where they can be restored.
ALTER TABLE accounting_reports
    MODIFY COLUMN status ENUM('pending','reviewed','processed','archived') NOT NULL DEFAULT 'pending';
