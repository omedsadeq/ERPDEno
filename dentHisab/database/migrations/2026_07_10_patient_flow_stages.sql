-- Migration: Patient Flow Stages (2026-07-10)
-- Makes the waiting room the single tracker of where a patient is right now.
--
-- OLD flow: waiting -> in_progress (doctor) -> completed
-- NEW flow: waiting -> in_progress (doctor) -> at_accounting / at_xray -> in_progress -> completed
--
-- Also adds visit_flow_log: one row per handoff, recording who moved the
-- patient to which station and why.

-- 1) New queue stages
ALTER TABLE waiting_room
MODIFY COLUMN status ENUM('waiting', 'called', 'in_treatment', 'in_progress', 'on_hold', 'at_accounting', 'at_xray', 'completed', 'rejected', 'cancelled', 'left') DEFAULT 'waiting';

-- 2) Per-step movement log
CREATE TABLE IF NOT EXISTS visit_flow_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    waiting_room_id INT DEFAULT NULL,
    patient_id INT NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) NOT NULL,
    action VARCHAR(50) NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    moved_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_flow_patient (patient_id, created_at),
    KEY idx_flow_waiting_room (waiting_room_id),
    CONSTRAINT fk_flow_waiting_room FOREIGN KEY (waiting_room_id) REFERENCES waiting_room(id) ON DELETE CASCADE,
    CONSTRAINT fk_flow_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_flow_moved_by FOREIGN KEY (moved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
