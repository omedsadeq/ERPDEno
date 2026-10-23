-- Independent archive: survives removal of the case, patient, folder and user.
CREATE TABLE IF NOT EXISTS deleted_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    case_number VARCHAR(100) NOT NULL,
    case_name VARCHAR(255) NULL,
    patient_id INT NOT NULL,
    patient_name VARCHAR(255) NOT NULL,
    doctor_id INT NOT NULL,
    doctor_name VARCHAR(255) NOT NULL,
    deleted_by INT NOT NULL,
    deleted_by_name VARCHAR(255) NOT NULL,
    deletion_reason TEXT NOT NULL,
    snapshot LONGTEXT NOT NULL,
    record_counts LONGTEXT NOT NULL,
    file_cleanup LONGTEXT NOT NULL,
    cleanup_pending TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_deleted_cases_patient (patient_id, deleted_at),
    KEY idx_deleted_cases_doctor (doctor_id, deleted_at),
    KEY idx_deleted_cases_date (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
