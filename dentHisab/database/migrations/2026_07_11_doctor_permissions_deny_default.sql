-- Doctor–patient isolation: default to DENY for cross-doctor access.
-- A doctor must never see another doctor's patients' profiles or X-rays unless
-- reception referred the patient to them (checked at runtime) or an admin
-- explicitly re-enables one of these toggles for a specific doctor.

-- Flip column defaults for any future rows.
ALTER TABLE doctor_permissions
    MODIFY COLUMN can_view_other_xrays   TINYINT(1) DEFAULT 0 COMMENT 'Can view X-rays of other doctors patients (default off)';
ALTER TABLE doctor_permissions
    MODIFY COLUMN can_view_other_profiles TINYINT(1) DEFAULT 0 COMMENT 'Can view basic profiles of other patients (default off)';

-- Reset existing rows that still carry the old permissive default so no doctor
-- keeps cross-doctor access granted only by the previous default-on behaviour.
UPDATE doctor_permissions
   SET can_view_other_xrays = 0,
       can_view_other_profiles = 0;
