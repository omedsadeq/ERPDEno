-- ئامانەت — money the counter takes from a patient before any doctor has
-- reopened their (historical) case. It is NOT income when it is taken: it sits
-- in قاسەی ئامانەت on the patient's name. The accountant later moves it onto
-- the doctor's request; only then does it become an approved payment
-- (payment_method 'advance'), counted as income on that day and booked into
-- the doctor's cashbox (classes/PatientAdvance.php, classes/CashboxLedger.php).

-- A payment made from ئامانەت rather than new cash.
ALTER TABLE payments
    MODIFY payment_method ENUM('cash','card','transfer','other','advance') NULL DEFAULT 'cash';

-- Which payment an allocation of ئامانەت became. An allocation made this way
-- belongs to the payment, not to one invoice, so invoice_id may stay empty.
ALTER TABLE patient_advance_allocations ADD COLUMN payment_id INT NULL AFTER invoice_id;
ALTER TABLE patient_advance_allocations MODIFY invoice_id INT UNSIGNED NULL;
ALTER TABLE patient_advance_allocations ADD KEY idx_paa_payment (payment_id);

-- قاسەی ئامانەت: the cash held for patients, beside the doctors' boxes.
INSERT IGNORE INTO cashbox_accounts (account_key, owner_type, doctor_id) VALUES ('amanat', 'amanat', NULL);
