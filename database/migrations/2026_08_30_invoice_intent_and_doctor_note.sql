/*
 * Invoice payment-intent integrity + a private doctor-to-accounting note.
 *
 * Two problems this addresses, both reported from the counter:
 *
 * 1. An invoice could arrive at accounting marked "نەقد" (cash) while its
 *    remaining balance was the full amount. payment_intent was whatever the
 *    doctor picked and nothing checked it against the money, so the
 *    accountant saw "paid in cash" on an invoice where nothing was paid.
 *    The rule is now enforced in CaseInvoice: the intent follows the money.
 *
 * 2. There was no channel for the doctor to tell the accountant something
 *    about the invoice. `notes` is the invoice's own note and `accounting_notes`
 *    belongs to the accountant, so a doctor remark had nowhere to live that
 *    was clearly private between the two roles.
 *
 * doctor_note_to_accounting is internal by design: it is shown in the
 * approval screen and never rendered on a receipt, voucher or patient PDF.
 */

ALTER TABLE case_invoices
    ADD COLUMN doctor_note_to_accounting TEXT NULL AFTER notes;

/*
 * Existing sent invoices that contradict the new rule are left as they are.
 * Rewriting historical intent would restate what an accountant already
 * reviewed and signed off. The rule applies from now on; the reports read
 * the money, not the intent flag, so old rows stay correct where it counts.
 */
