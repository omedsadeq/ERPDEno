# Cashboxes

Cash is tracked from **2026-10-01 00:00:00, Asia/Baghdad**, as requested.

Admin opens `admin/cashboxes.php` from Finance and can inspect all activity or
select a doctor/X-ray box. Doctors open `doctors/cashbox.php` under Doctors,
and can deposit/withdraw only in their own box. X-ray staff can inspect
`xray/cashbox.php`; admin records its manual cash movements.

`page.cashboxes` and the account owner are checked on the server. Accounting
staff do not receive administrator rights through this feature.

## Cash definitions

- Approved treatment invoice: actual `draft_received_amount`, capped at net.
  Drafts, sent requests, debt promises, prior-system paid amounts and advance
  allocations are not additional cash.
- Approved cash `payments`, including debt collections and installment payments.
  Linked `installment_payments` are schedule metadata, never a second receipt.
- Cash patient advances attributed to a doctor, less their refunds. Unassigned
  advances are not guessed into a doctor's box.
- X-ray cash is always in the separate X-ray box: fully paid cash requests
  without a debt record, or individual cash debt collections. Paying off the
  debt does not count the full request a second time. Card/transfer payments
  are excluded.
- Manual deposits/withdrawals move cash custody only, never patient debt or
  clinic revenue. This feature does not infer salary, commission or expenses
  from a doctor's cashbox.

## Journal and corrections

`CashboxLedger` owns `cashbox_accounts`, `cashbox_sources`, and the append-only
`cashbox_entries`. Receipt and journal changes run in one transaction using
Database savepoints. Source ownership is frozen after first booking.

Historical receipts are imported once, on their recorded cash date, with stored
invoice/payment edits and refunds where their audit records remain available.
Source IDs and revisions prevent repeated imports. Cash reports before the
cutoff are excluded. Reconciliation also catches existing legacy writers.

Approved edits, refunds, cancelled receipts, and permanent invoice/case removal
append adjustments. They do not erase journal entries. Clinical record deletion
explicitly excludes the cashbox tables. Manual errors can be reversed by admin;
each original movement can be reversed only once.

Balances are calculated in integer cents and cached under a row lock, in the
same transaction as each journal entry. A withdrawal cannot exceed available
cash, including simultaneous withdrawals. Required reasons, CSRF and request
keys protect manual posting. A financial refund can expose a negative box after
cash was withdrawn; the deficit is visible and further withdrawals are blocked.

The current balance is independent of date/direction filters. Statements show
opening/incoming/outgoing/closing for the selected dates, while each row's
chronological balance is independent of pagination.

## Validation

`php tests/run-cashboxes.php` uses the isolated `denthisab_test` database. It
checks cash eligibility, cutoff, ownership, reversals, rollback, duplicate
submissions and concurrent withdrawals using independent DB connections.
