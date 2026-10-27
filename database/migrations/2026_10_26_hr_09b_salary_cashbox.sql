/*
 * سەرچاوە مرۆییەکان — a salary paid out of قاسەی لێزان (the 'center' cashbox).
 *
 * from_cashbox: chosen on each payment (hr/salary-receipt.php). When on, the
 * amount is taken out of the centre's cashbox with CashboxLedger::move(), and
 * cashbox_entry_id is that withdrawal. Editing the amount or deleting the
 * payment books a reversal (CashboxLedger::reverse()); the cashbox journal
 * itself is never rewritten.
 */

ALTER TABLE hr_salary_receipts ADD COLUMN from_cashbox TINYINT(1) NOT NULL DEFAULT 0 AFTER expense_id;
ALTER TABLE hr_salary_receipts ADD COLUMN cashbox_entry_id INT NULL DEFAULT NULL AFTER from_cashbox;
