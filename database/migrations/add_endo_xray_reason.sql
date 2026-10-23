-- Add 'endo' (Endodontics / root canal) to the X-ray request reasons.
--
-- xray_reason is an ENUM, so the new value must exist in the column definition
-- before the form can save it. Adding a value to an ENUM only rewrites the
-- definition — existing rows are untouched because MySQL maps enum values by
-- string, not position.
--
-- Run this on the live host BEFORE uploading the changed PHP files.

ALTER TABLE xray_requests
    MODIFY xray_reason ENUM('implant','pathology','impaction','trauma','endo','other') NOT NULL;
