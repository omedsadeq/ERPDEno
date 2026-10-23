-- Doctor-declared intent at send: cash now / debt with promise / installment (spec §4.2)
ALTER TABLE case_invoices ADD COLUMN payment_intent ENUM('cash','debt','installment') NULL AFTER accounting_notes;
ALTER TABLE case_invoices ADD COLUMN promised_date DATE NULL AFTER payment_intent;
ALTER TABLE case_invoices ADD COLUMN promised_amount DECIMAL(15,2) NULL AFTER promised_date;
