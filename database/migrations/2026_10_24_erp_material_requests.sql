-- داواکاری مەواد لە کۆگا — a doctor asks the store for materials from a case
-- (cases/view.php «مەواد» dialog); the store approves it on
-- erp/material-requests.php. Approval creates the normal مەواد بۆ دکتۆر
-- voucher (ErpDoctorIssue::create — stock out, doctor charged), whose id is
-- kept on the request. Nothing is reserved while a request is pending.

CREATE TABLE IF NOT EXISTS erp_material_requests (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id       INT NOT NULL,
    patient_id    INT NULL,
    doctor_id     INT NOT NULL,
    warehouse_id  INT UNSIGNED NOT NULL,
    status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    notes         VARCHAR(1000) NULL,
    decision_note VARCHAR(500) NULL,
    issue_id      BIGINT UNSIGNED NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by    INT NULL,
    decided_at    DATETIME NULL,
    KEY idx_mr_status (status, created_at),
    KEY idx_mr_case (case_id),
    KEY idx_mr_doctor (doctor_id),
    CONSTRAINT fk_mr_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_material_request_items (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id        INT UNSIGNED NOT NULL,
    product_id        INT UNSIGNED NOT NULL,
    product_name      VARCHAR(255) NOT NULL,
    quantity          DECIMAL(15,3) NOT NULL,
    approved_quantity DECIMAL(15,3) NULL,
    KEY idx_mri_request (request_id),
    CONSTRAINT fk_mri_request FOREIGN KEY (request_id) REFERENCES erp_material_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description)
SELECT * FROM (
    SELECT 'erp.material_request.create' AS k, 'Request Materials From The Store' AS n,
           'داواکاری مەواد لە کۆگا' AS ku, 'erp' AS c, NULL AS d
    UNION ALL SELECT 'erp.material_request.approve', 'Approve Material Requests',
           'پەسەندکردنی داواکاری مەواد', 'erp', NULL
) x
WHERE x.k NOT IN (SELECT permission_key FROM permission_definitions);

-- Doctors ask; the store (run from the accountant and reception accounts,
-- like مەواد بۆ دکتۆر) approves. Admin is always allowed.
INSERT INTO role_permissions (role, permission_key, is_allowed)
SELECT * FROM (
    SELECT 'doctor' AS r, 'erp.material_request.create' AS k, 1 AS a
    UNION ALL SELECT 'accountant',      'erp.material_request.create',  0
    UNION ALL SELECT 'receptionist',    'erp.material_request.create',  0
    UNION ALL SELECT 'xray_department', 'erp.material_request.create',  0
    UNION ALL SELECT 'doctor',          'erp.material_request.approve', 0
    UNION ALL SELECT 'accountant',      'erp.material_request.approve', 1
    UNION ALL SELECT 'receptionist',    'erp.material_request.approve', 1
    UNION ALL SELECT 'xray_department', 'erp.material_request.approve', 0
) y
WHERE CONCAT(y.r, ':', y.k) NOT IN (SELECT CONCAT(role, ':', permission_key) FROM role_permissions);
