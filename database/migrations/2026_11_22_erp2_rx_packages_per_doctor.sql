-- پاکێجی دەرمان: each doctor's own. A doctor sees and makes only their own
-- packages, because another doctor may not prescribe what is in them. Written for a
-- doctor by someone else (the reception, the admin), the prescription's doctor's
-- packages are shown (erp2/api/prescriptions.php, erp2_rx_package_owner).
--
-- doctor_id: users.id of the doctor whose package it is. A package made before
-- this is its maker's. The same name may now be used by two doctors.
ALTER TABLE erp2_rx_packages ADD COLUMN doctor_id INT NULL AFTER name;
UPDATE erp2_rx_packages SET doctor_id = created_by WHERE doctor_id IS NULL;
ALTER TABLE erp2_rx_packages DROP INDEX uq_erp2_rx_packages_name;
ALTER TABLE erp2_rx_packages ADD UNIQUE KEY uq_erp2_rx_packages_doctor_name (doctor_id, name);
