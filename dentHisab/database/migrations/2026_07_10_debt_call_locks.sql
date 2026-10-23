-- Migration: Debt-call work locks (2026-07-10)
-- When an employee opens a debtor to call them, the patient is locked for
-- everyone else for 10 minutes ("Sara is working on this case") so two
-- employees never call the same patient. Locks expire automatically.

CREATE TABLE IF NOT EXISTS debt_call_locks (
    patient_id INT NOT NULL PRIMARY KEY,
    locked_by INT NOT NULL,
    locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dcl_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_dcl_user FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
