-- Migration: User audit tracking + foreign-key repair
-- Date: 2026-07-10
--
-- Fixes the root causes of "visits/appointments are not connected to users":
--   1. 29 foreign keys pointed at the dead legacy table `user_a` (4 rows).
--      Every insert with a real users.id (5+) violated those FKs and threw —
--      this is why the appointments table stayed empty.
--   2. users.role ENUM was missing 'accountant' (an earlier migration failed
--      silently because one row held an invalid '' role).
--   3. visits / waiting_room / cases / patient_folders had no created_by /
--      updated_by columns, so nobody could tell which user acted.
--   4. doctors table had no link to the users table (code bridged the two by
--      matching names, which fails for most doctors).

-- ────────────────────────────────────────────────────────────────────
-- 1. Repair users.role: clear invalid values, then extend the ENUM
-- ────────────────────────────────────────────────────────────────────
UPDATE users SET role = NULL
WHERE role IS NOT NULL
  AND role NOT IN ('admin','doctor','receptionist','xray_department','resident_receptionist','accountant');

ALTER TABLE users
MODIFY COLUMN role ENUM('admin','doctor','receptionist','xray_department','resident_receptionist','accountant') DEFAULT 'doctor';

-- ────────────────────────────────────────────────────────────────────
-- 2. Repoint every foreign key from legacy `user_a` to `users`
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE `appointments` DROP FOREIGN KEY `appointments_ibfk_2`;
ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_doctor_id_user` FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`);
ALTER TABLE `appointments` DROP FOREIGN KEY `appointments_ibfk_5`;
ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_created_by_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `arch_procedure_logs` DROP FOREIGN KEY `arch_procedure_logs_ibfk_3`;
ALTER TABLE `arch_procedure_logs` ADD CONSTRAINT `fk_arch_procedure_logs_performed_by_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `case_treatments` DROP FOREIGN KEY `case_treatments_ibfk_4`;
ALTER TABLE `case_treatments` ADD CONSTRAINT `fk_case_treatments_performed_by_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `case_treatments` DROP FOREIGN KEY `case_treatments_ibfk_5`;
ALTER TABLE `case_treatments` ADD CONSTRAINT `fk_case_treatments_approved_by_user` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `dental_chart` DROP FOREIGN KEY `dental_chart_ibfk_3`;
ALTER TABLE `dental_chart` ADD CONSTRAINT `fk_dental_chart_treated_by_user` FOREIGN KEY (`treated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `doctor_breaks` DROP FOREIGN KEY `doctor_breaks_ibfk_1`;
ALTER TABLE `doctor_breaks` ADD CONSTRAINT `fk_doctor_breaks_doctor_id_user` FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`);
ALTER TABLE `doctor_exceptions` DROP FOREIGN KEY `doctor_exceptions_ibfk_1`;
ALTER TABLE `doctor_exceptions` ADD CONSTRAINT `fk_doctor_exceptions_doctor_id_user` FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`);
ALTER TABLE `doctor_permissions` DROP FOREIGN KEY `doctor_permissions_ibfk_1`;
ALTER TABLE `doctor_permissions` ADD CONSTRAINT `fk_doctor_permissions_doctor_id_user` FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`);
ALTER TABLE `doctor_schedules` DROP FOREIGN KEY `doctor_schedules_ibfk_1`;
ALTER TABLE `doctor_schedules` ADD CONSTRAINT `fk_doctor_schedules_doctor_id_user` FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`);
ALTER TABLE `expenses` DROP FOREIGN KEY `expenses_ibfk_4`;
ALTER TABLE `expenses` ADD CONSTRAINT `fk_expenses_created_by_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `expenses` DROP FOREIGN KEY `expenses_ibfk_5`;
ALTER TABLE `expenses` ADD CONSTRAINT `fk_expenses_approved_by_user` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `installment_payments` DROP FOREIGN KEY `installment_payments_ibfk_2`;
ALTER TABLE `installment_payments` ADD CONSTRAINT `fk_installment_payments_received_by_user` FOREIGN KEY (`received_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `installment_plans` DROP FOREIGN KEY `installment_plans_ibfk_2`;
ALTER TABLE `installment_plans` ADD CONSTRAINT `fk_installment_plans_created_by_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `inventory_transactions` DROP FOREIGN KEY `inventory_transactions_ibfk_3`;
ALTER TABLE `inventory_transactions` ADD CONSTRAINT `fk_inventory_transactions_created_by_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `lab_orders` DROP FOREIGN KEY `lab_orders_ibfk_2`;
ALTER TABLE `lab_orders` ADD CONSTRAINT `fk_lab_orders_created_by_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `notification_settings` DROP FOREIGN KEY `notification_settings_ibfk_1`;
ALTER TABLE `notification_settings` ADD CONSTRAINT `fk_notification_settings_user_id_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`);
ALTER TABLE `patient_access_logs` DROP FOREIGN KEY `patient_access_logs_ibfk_2`;
ALTER TABLE `patient_access_logs` ADD CONSTRAINT `fk_patient_access_logs_user_id_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`);
ALTER TABLE `patient_credit_logs` DROP FOREIGN KEY `patient_credit_logs_ibfk_2`;
ALTER TABLE `patient_credit_logs` ADD CONSTRAINT `fk_patient_credit_logs_performed_by_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `patient_debt_payments` DROP FOREIGN KEY `patient_debt_payments_ibfk_2`;
ALTER TABLE `patient_debt_payments` ADD CONSTRAINT `fk_patient_debt_payments_received_by_user` FOREIGN KEY (`received_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `patient_images` DROP FOREIGN KEY `patient_images_ibfk_3`;
ALTER TABLE `patient_images` ADD CONSTRAINT `fk_patient_images_uploaded_by_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `patients` DROP FOREIGN KEY `patients_ibfk_1`;
-- Clean legacy zero-dates first: the FK ALTER rebuilds the table and strict
-- mode rejects '0000-00-00' rows, which would abort the constraint add.
UPDATE `patients` SET date_of_birth = NULL WHERE CAST(date_of_birth AS CHAR(10)) = '0000-00-00';
ALTER TABLE `patients` ADD CONSTRAINT `fk_patients_registered_by_user` FOREIGN KEY (`registered_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `payments` DROP FOREIGN KEY `payments_ibfk_2`;
ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_received_by_user` FOREIGN KEY (`received_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `payments` DROP FOREIGN KEY `payments_ibfk_3`;
ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_approved_by_user` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `prescriptions` DROP FOREIGN KEY `prescriptions_ibfk_3`;
ALTER TABLE `prescriptions` ADD CONSTRAINT `fk_prescriptions_doctor_id_user` FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`);
ALTER TABLE `xray_debt_payments` DROP FOREIGN KEY `xray_debt_payments_ibfk_2`;
ALTER TABLE `xray_debt_payments` ADD CONSTRAINT `fk_xray_debt_payments_received_by_user` FOREIGN KEY (`received_by`) REFERENCES `users`(`id`);
ALTER TABLE `xray_payment_logs` DROP FOREIGN KEY `xray_payment_logs_ibfk_2`;
ALTER TABLE `xray_payment_logs` ADD CONSTRAINT `fk_xray_payment_logs_performed_by_user` FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`);
ALTER TABLE `xray_requests` DROP FOREIGN KEY `fk_xray_paid_by`;
ALTER TABLE `xray_requests` ADD CONSTRAINT `fk_xray_requests_paid_by_user` FOREIGN KEY (`paid_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
ALTER TABLE `xray_requests` DROP FOREIGN KEY `fk_xray_settled_by`;
ALTER TABLE `xray_requests` ADD CONSTRAINT `fk_xray_requests_settled_by_user` FOREIGN KEY (`settled_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- ────────────────────────────────────────────────────────────────────
-- 3. Audit columns: who created / last edited each record
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE visits ADD COLUMN created_by INT NULL AFTER receptionist_id;
ALTER TABLE visits ADD COLUMN updated_by INT NULL AFTER created_by;
ALTER TABLE visits ADD CONSTRAINT fk_visits_created_by_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE visits ADD CONSTRAINT fk_visits_updated_by_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
-- Historical visits: the receptionist recorded on the row is the best-known creator
UPDATE visits SET created_by = receptionist_id WHERE created_by IS NULL;

ALTER TABLE waiting_room ADD COLUMN created_by INT NULL AFTER notes;
ALTER TABLE waiting_room ADD COLUMN updated_by INT NULL AFTER created_by;
ALTER TABLE waiting_room ADD CONSTRAINT fk_waiting_room_created_by_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE waiting_room ADD CONSTRAINT fk_waiting_room_updated_by_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE appointments ADD COLUMN updated_by INT NULL AFTER created_by;
ALTER TABLE appointments ADD COLUMN cancelled_by INT NULL AFTER cancelled_at;
ALTER TABLE appointments ADD CONSTRAINT fk_appointments_updated_by_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE appointments ADD CONSTRAINT fk_appointments_cancelled_by_user FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE payments ADD COLUMN rejected_by INT NULL AFTER approved_by;
ALTER TABLE payments ADD CONSTRAINT fk_payments_rejected_by_user FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE cases ADD COLUMN created_by INT NULL AFTER notes;
ALTER TABLE cases ADD COLUMN closed_by INT NULL AFTER closed_at;
ALTER TABLE cases ADD CONSTRAINT fk_cases_created_by_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE cases ADD CONSTRAINT fk_cases_closed_by_user FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE patient_folders ADD COLUMN created_by INT NULL AFTER notes;
ALTER TABLE patient_folders ADD CONSTRAINT fk_patient_folders_created_by_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

-- ────────────────────────────────────────────────────────────────────
-- 4. Link the clinical `doctors` directory to real user accounts
--    (replaces fragile name-matching between doctors.name and users.full_name)
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE doctors ADD COLUMN user_id INT NULL AFTER name;
ALTER TABLE doctors ADD CONSTRAINT fk_doctors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
-- Backfill exact name matches; remaining rows must be linked by the admin
UPDATE doctors d
JOIN users u ON u.full_name = d.name AND u.role = 'doctor'
SET d.user_id = u.id
WHERE d.user_id IS NULL;

-- ────────────────────────────────────────────────────────────────────
-- 5. Make the audit log queryable per record
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE activity_log ADD INDEX idx_activity_record (table_name, record_id);
ALTER TABLE activity_log ADD INDEX idx_activity_user_time (user_id, created_at);
