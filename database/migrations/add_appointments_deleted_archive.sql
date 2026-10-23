-- Archive table for permanently deleted appointments.
--
-- Deleting an appointment removes the row from `appointments` outright, so every
-- existing query (lists, analytics, PDFs, reports, patient counts) keeps working
-- with no `deleted_at` filter to add and none to forget. The row is copied here
-- first, so an admin can still recover an accidental delete.
--
-- CREATE TABLE ... LIKE copies the columns, PK and indexes but NOT the foreign
-- keys, which is what we want: an archived row must not be dragged around by
-- constraints on patients/doctors.
--
-- Run this on the live host BEFORE uploading appointments.php.
--
-- Already applied? The CREATE is guarded by IF NOT EXISTS, but the ALTER is not:
-- it will fail with "Duplicate column name 'deleted_at'". That error is safe to
-- ignore — it means the archive columns are already in place. Verify with:
--   SHOW COLUMNS FROM appointments_deleted;
-- The last three columns must be deleted_at, deleted_by, waiting_room_ids, in
-- that order, because the delete uses INSERT ... SELECT a.*, ?, ?, ?.

CREATE TABLE IF NOT EXISTS appointments_deleted LIKE appointments;

ALTER TABLE appointments_deleted
    ADD COLUMN deleted_at       DATETIME     NULL,
    ADD COLUMN deleted_by       INT          NULL,
    -- waiting_room.appointment_id is ON DELETE SET NULL, so the check-in rows
    -- that pointed here are recorded to make a restore able to re-link them.
    ADD COLUMN waiting_room_ids VARCHAR(255) NULL;
