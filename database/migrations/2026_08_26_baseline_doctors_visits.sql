-- Baseline CREATE TABLE for `doctors` and `visits` — neither table has a
-- CREATE TABLE anywhere in database/schema.sql or any prior migration; both
-- exist on live/dev only via undocumented manual DDL. Nine existing
-- migrations ALTER these tables and can never apply to a from-scratch DB
-- without this. Filenames sort digit-prefixed before letter-prefixed, so this
-- runs before those nine.
--
-- Dumped verbatim from the dev DB via SHOW CREATE TABLE (2026-08-26),
-- converted to IF NOT EXISTS and stripped of its AUTO_INCREMENT=N counter.
-- On live this is a no-op (table already exists); table-already-exists is
-- also in MigrationRunner's ignorable-error list either way.
--
-- FOREIGN KEYs kept as dumped: users and patients are created by
-- database/schema.sql; work_types is created by
-- database/migrations/create_work_types_table.sql (undated, sorts after this
-- file) — MigrationRunner replays in multiple passes specifically so a
-- forward-reference like this resolves once that file has run; doctors is
-- created earlier in this same file, before visits references it.

CREATE TABLE IF NOT EXISTS `doctors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `specialization` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_doctors_user` (`user_id`),
  CONSTRAINT `fk_doctors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `visits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int DEFAULT NULL,
  `patient_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mobile_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `treated` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'no',
  `treated_reason` text COLLATE utf8mb4_unicode_ci,
  `requested_doctor_id` int DEFAULT NULL,
  `assigned_doctor_id` int DEFAULT NULL,
  `assigned_doctor_reason` text COLLATE utf8mb4_unicode_ci,
  `checkup_doctor_id` int DEFAULT NULL,
  `treatment_doctor_id` int DEFAULT NULL,
  `arrival_time` time NOT NULL,
  `checkin_time` time DEFAULT NULL,
  `notification_time` time DEFAULT NULL,
  `procedure_treatment` text COLLATE utf8mb4_unicode_ci,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `work_type_id` int DEFAULT NULL,
  `visit_type` enum('checkup','treatment','xray') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `patient_status` enum('new','old') COLLATE utf8mb4_unicode_ci NOT NULL,
  `receptionist_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `referral_source` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referral_source_notes` text COLLATE utf8mb4_unicode_ci,
  `appointment_date` date DEFAULT NULL,
  `appointment_added_by` int DEFAULT NULL,
  `appointment_doctor_id` int DEFAULT NULL,
  `referring_doctor_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referring_doctor_designation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referring_doctor_choice_id` int DEFAULT NULL,
  `appointment_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `visit_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `requested_doctor_id` (`requested_doctor_id`),
  KEY `assigned_doctor_id` (`assigned_doctor_id`),
  KEY `receptionist_id` (`receptionist_id`),
  KEY `idx_visit_date` (`visit_date`),
  KEY `idx_patient_name` (`patient_name`),
  KEY `idx_mobile` (`mobile_number`),
  KEY `fk_appointment_added_by` (`appointment_added_by`),
  KEY `fk_appointment_doctor_id` (`appointment_doctor_id`),
  KEY `fk_referring_doctor_choice_id` (`referring_doctor_choice_id`),
  KEY `idx_appointment_date` (`appointment_date`),
  KEY `idx_visits_patient_id` (`patient_id`),
  KEY `idx_visits_work_type` (`work_type_id`),
  KEY `idx_visits_visit_type` (`visit_type`),
  KEY `idx_visits_price` (`price`),
  KEY `fk_visits_created_by_user` (`created_by`),
  KEY `fk_visits_updated_by_user` (`updated_by`),
  KEY `idx_visits_checkup_doctor` (`checkup_doctor_id`),
  KEY `idx_visits_treatment_doctor` (`treatment_doctor_id`),
  CONSTRAINT `fk_appointment_added_by` FOREIGN KEY (`appointment_added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointment_doctor_id` FOREIGN KEY (`appointment_doctor_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_referring_doctor_choice_id` FOREIGN KEY (`referring_doctor_choice_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_created_by_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_updated_by_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_work_type` FOREIGN KEY (`work_type_id`) REFERENCES `work_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `visits_ibfk_1` FOREIGN KEY (`requested_doctor_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `visits_ibfk_2` FOREIGN KEY (`assigned_doctor_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `visits_ibfk_3` FOREIGN KEY (`receptionist_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
