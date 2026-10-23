-- Doctor daily attendance (چوونە ژوورەوە / چوونە دەرەوە).
--
-- One row per doctor per day. Deliberately a single check-in/check-out pair
-- (not a multi-session punch log) and deliberately keyed on users.id — same
-- id space as case_invoices.doctor_id, matching doctors/daily-summary.php's
-- $docUserId, not the separate doctors.id space visits.* uses.
CREATE TABLE IF NOT EXISTS doctor_attendance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id       INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_time   DATETIME DEFAULT NULL,
    check_out_time  DATETIME DEFAULT NULL,
    checked_in_by   INT DEFAULT NULL,
    checked_out_by  INT DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doctor_attendance_day (doctor_id, attendance_date),
    KEY idx_doctor_attendance_date (attendance_date),
    CONSTRAINT fk_doctor_attendance_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_doctor_attendance_checkin_by FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_doctor_attendance_checkout_by FOREIGN KEY (checked_out_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
