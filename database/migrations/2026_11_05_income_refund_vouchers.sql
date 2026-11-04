-- پسوولەی گەڕاندنەوەی پارە — money given back to a patient is a document of its
-- own, on a patient and a case. The original receipt is never edited: the
-- refund goes in the patient's file as two equal lines (money back, price cut),
-- so what he owes does not change, and it comes off the doctor's income on the
-- day it was given.
--
-- patient_name and case_label are kept on the voucher so a signed paper can
-- always be reprinted as it was. patient_id/case_id have no foreign key:
-- CaseDeletion refuses a case with a live voucher (IncomeColumn::hasActiveRefund).
--
-- Nothing here is erased: an entry is cancelled, with who, when and why, and the
-- boxes get the money back through new journal lines.
ALTER TABLE income_column_entries ADD COLUMN patient_id INT NULL DEFAULT NULL AFTER doctor_id;
ALTER TABLE income_column_entries ADD COLUMN case_id INT NULL DEFAULT NULL AFTER patient_id;
ALTER TABLE income_column_entries ADD COLUMN patient_name VARCHAR(255) NULL DEFAULT NULL AFTER case_id;
ALTER TABLE income_column_entries ADD COLUMN case_label VARCHAR(255) NULL DEFAULT NULL AFTER patient_name;
ALTER TABLE income_column_entries ADD COLUMN voucher_no VARCHAR(30) NULL DEFAULT NULL AFTER case_label;
ALTER TABLE income_column_entries ADD COLUMN status ENUM('active','cancelled') NOT NULL DEFAULT 'active' AFTER note;
ALTER TABLE income_column_entries ADD COLUMN cancelled_by INT NULL DEFAULT NULL AFTER updated_at;
ALTER TABLE income_column_entries ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL AFTER cancelled_by;
ALTER TABLE income_column_entries ADD COLUMN cancel_reason VARCHAR(255) NULL DEFAULT NULL AFTER cancelled_at;
ALTER TABLE income_column_entries ADD UNIQUE KEY uq_income_column_voucher (voucher_no);
ALTER TABLE income_column_entries ADD KEY idx_income_column_entries_case (case_id, status);
ALTER TABLE income_column_entries ADD KEY idx_income_column_entries_patient (patient_id, status);
ALTER TABLE income_column_entries ADD CONSTRAINT fk_income_column_entry_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;
