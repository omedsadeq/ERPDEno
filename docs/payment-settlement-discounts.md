The case payment dialog accepts an additional discount when a patient returns
to settle an approved debt. Enter the actual cash, a fixed IQD discount or a
percentage of the available outstanding debt, and the discount reason.

A doctor can save a draft or send cash and discount together for approval.
Administrators and accountants receive the existing payment notification and
review the agreement in the Approval Center. Rejection releases the entire
reservation and leaves the debt unchanged. Accounting staff can also collect
and approve a discounted settlement directly from the case dialog.

For an outstanding debt of 100,000, cash of 80,000 and a discount of 20,000
settle the balance. Only 80,000 enters paid totals, cashboxes and income.
The additional discount is recorded on the existing invoices, so patient
statements, case summaries and debt reports use the same adjusted balance.
The payment voucher shows cash and discount separately.

Pending cash and discount reserve the available debt. Approval revalidates
the invoice balances while holding database locks. Double submissions reuse
the receipt. Reversal undoes both the cash and discount atomically, restoring
the invoice's previous discount settings. If its discount was subsequently
changed, reverse the newer change first. A receipt containing a discount is
corrected by reversing it and recording the replacement agreement.

An active or pending installment plan must first be adjusted by accounting;
this dialog does not rewrite agreed installment schedules. Cash must be
positive. Discounts cannot reduce debt below existing paid amounts or other
pending reservations. Existing invoice discounts remain included in the
outstanding balance; this field adds a further discount.

Schema: `database/migrations/2026_10_20_payment_settlement_discounts.sql`
is applied by the existing automatic migration runner. New payment columns
store the proposal; `payment_discount_allocations` records its invoice shares
separately from cash allocations. Approval, rejection and reversal retain
their audit trail.

Run the isolated database tests with:

```powershell
& C:\xampp\php\php.exe tests\run-payment-discounts.php
```
