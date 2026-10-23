-- Record how reception chose the checkup doctor. NULL preserves unknown
-- historical intent; do not infer a patient request from a clinical doctor.
ALTER TABLE visits
    ADD COLUMN doctor_assignment_source ENUM('requested', 'reception') NULL DEFAULT NULL
    AFTER assigned_doctor_reason;
