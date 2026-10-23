-- External (outside) doctors who refer patients here for imaging only.
--
-- Deliberately its OWN table rather than a flag on `doctors` or a row in
-- `users`: an outside doctor has no account, no schedule, no waiting-room
-- presence and no share of clinic revenue, and `doctors.id` is the identity
-- that visits.*_doctor_id points at. Mixing the two would put strangers into
-- every doctor dropdown, rota and payroll query in the system.
CREATE TABLE IF NOT EXISTS xray_external_doctors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    clinic VARCHAR(150) NULL,
    specialization VARCHAR(120) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_xray_external_doctor_name (name),
    KEY idx_xray_external_doctor_active (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which outside doctor this request belongs to. NULL = an in-house doctor
-- ordered it, and `requested_by` is that doctor (the pre-existing meaning).
-- When this IS set, `requested_by` stays the STAFF MEMBER who typed the
-- request — it is the audit trail and the FK target users(id) requires a real
-- account — so nothing that scopes work by doctor starts handing an outside
-- doctor's imaging to whoever happens to share their name.
ALTER TABLE xray_requests
    ADD COLUMN external_doctor_id INT NULL DEFAULT NULL AFTER requested_by;

ALTER TABLE xray_requests
    ADD KEY idx_xray_requests_external_doctor (external_doctor_id);

ALTER TABLE xray_requests
    ADD CONSTRAINT xray_requests_external_doctor_fk
    FOREIGN KEY (external_doctor_id) REFERENCES xray_external_doctors(id) ON DELETE SET NULL;
