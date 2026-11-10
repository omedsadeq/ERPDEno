-- وەرگیراو on a receipt counts on the day it was RECEIVED. What the receipt
-- held when the doctor sent it is the money taken in the chair, on the day of
-- the work; what the accountant writes on it later, in the approval center,
-- becomes an approved payment on the day it was received (classes/CaseInvoice
-- approveWithAdvance), so the income sheet, the cashbox and the daily report
-- all show it that day. This keeps the sent figure to tell the two apart.
ALTER TABLE case_invoices ADD COLUMN sent_received_amount DECIMAL(15,2) NULL AFTER draft_received_amount;
