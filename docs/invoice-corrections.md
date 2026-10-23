# Admin invoice corrections

On an open case, the invoice header's **دەستکاری / Edit** button opens an
explicit admin editor. Save applies the invoice details, treatment rows, line
and invoice discounts, chair cash, historical prior payments, and eligible
linked approved receipts together. Cancel discards the staged changes.

`InvoiceCorrection` checks the actor's active admin role in the database. This
route does not change the settings that govern ordinary accountant editors.
The case endpoint also requires the session CSRF token.

Amounts are validated in integer cents. Invoice totals and outstanding debt
are derived from the source rows. Pending receipt allocations reserve room;
advance allocations and historical money are never counted as fresh cash.
Approved receipts use Payment's canonical allocation correction, including
receipts shared with another invoice and linked installment payments.

The original invoice approval identity and timestamp are preserved. Changes
to monetary sources, case/folder totals, cashbox journal/cache, and the mandatory
`invoice_admin_corrected` audit entry share one database transaction. The audit
includes the administrator, reason, and before/after records.

An optimistic revision covers the work, invoices, receipts, allocations, case
state, and associated installment terms. Conflicts require a refresh. Automatic
page refresh is suspended while editing so unsaved fields remain intact.

Agreed installment principal/down payments must be changed through the plan.
Distributed debt receipts and arch receipts retain their existing parent-record
restrictions. Pending/refunded receipts are shown with their state rather than
silently changed into approved money. Patient advances remain backed by their
existing allocation records. Signatures, stamps, approval state and ownership
are not editable text fields.

Validation: `php tests/run-invoice-corrections.php` builds the isolated
`denthisab_test` database and checks authorization, stale edits, exact totals,
shared receipts, cashbox corrections, and rollback after a mandatory audit failure.
