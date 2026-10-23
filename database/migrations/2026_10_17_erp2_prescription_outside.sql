-- دەرمانخانە (erp2): a prescription the patient takes OUTSIDE the clinic.
-- Some patients buy their medicines at another pharmacy: the counter marks
-- the request «دەرەوەی کلینیک» and hands over the printed prescription
-- (erp2/prescription-print.php). Like a rejected one it leaves the waiting
-- queue, but it is kept apart so the clinic can see how many go outside.
-- status_note / status_by / status_at record why and who, for either.
ALTER TABLE erp2_prescriptions MODIFY status ENUM('pending','sold','cancelled','outside') NOT NULL DEFAULT 'pending';
ALTER TABLE erp2_prescriptions ADD COLUMN IF NOT EXISTS outside_at DATETIME NULL AFTER cancelled_at;
ALTER TABLE erp2_prescriptions ADD COLUMN IF NOT EXISTS status_note VARCHAR(255) NULL AFTER outside_at;
ALTER TABLE erp2_prescriptions ADD COLUMN IF NOT EXISTS status_by INT NULL AFTER status_note;
