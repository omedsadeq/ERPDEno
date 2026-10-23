-- Transition-phase access for doctors: free case creation + its audit trail
-- قۆناغی گواستنەوە: دروستکردنی کەیسی ئازاد و تۆمارکردنی شوێنپێ
--
-- 1) `allow_unassigned_patient_cases` — the switch admins flip in
--    admin/settings.php. ON (default) lets a doctor look up any patient and
--    start a case for one nobody has assigned to them, which is what the clinic
--    needs while historical data is still being organised. OFF locks doctors
--    back to patients reception referred to them.
--
-- 2) `unassigned_case_log` — every case a doctor opens for a patient they did
--    not already own is recorded here, so before switching the flag OFF an
--    admin can see exactly how many patients entered the system this way and
--    who did it. Deliberately has no foreign keys: an audit trail must survive
--    the deletion of the user or patient it refers to.

CREATE TABLE IF NOT EXISTS unassigned_case_log (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doctor_id        INT          NOT NULL,
    patient_id       INT          NOT NULL,
    case_id          INT          NULL,
    folder_id        INT          NULL,
    action_type      VARCHAR(40)  NOT NULL,
    patient_relation VARCHAR(20)  NOT NULL,
    ip_address       VARCHAR(45)  NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ucl_doctor (doctor_id),
    INDEX idx_ucl_patient (patient_id),
    INDEX idx_ucl_created (created_at),
    INDEX idx_ucl_action (action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE relies on the UNIQUE key of settings.setting_key, so re-running
-- never overwrites the value an admin has already chosen.
INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES
('allow_unassigned_patient_cases', '1', 'boolean',
 'Allow doctors to open a case for a patient not assigned to them (data transition phase)');
