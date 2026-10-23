# Editable وەرگیراو (money received) on visit receipts

**Date:** 2026-08-25
**Status:** approved, ready to implement

## Problem

On `cases/view.php` the receipt totals block renders داشکاندن as a live input but
وەرگیراو as a static figure. A doctor agrees a price *and* a payment with the
patient at the chair, and today has no way to write the second number down — it
has to be re-entered through a separate dialog, or by the accountant from
scratch.

`case_invoices` deliberately does not store "paid": `annotateFifo()` derives it
by pouring `cases.paid_amount + cases.discount_amount` over the case's non-draft
receipts in `invoice_seq` order. That derivation is not being changed. This work
gives the box a way to *drive* the number it derives from.

## Workflow being modelled

1. Doctor fills a draft receipt, writes داشکاندن, then writes وەرگیراو.
2. Receipt goes to the accountant, carrying a pending payment request.
3. Accountant opens the same receipt and adjusts داشکاندن / وەرگیراو, then books.

## Design

### 1. Two meanings, one box

| Who | Draft receipt | Sent receipt | Commit does |
|---|---|---|---|
| Doctor | editable | locked (unchanged) | `send()` + `createRequest()` — a `pending` payments row |
| Admin / accountant | editable | editable | `directPayment()` — an `approved` payments row |

The doctor's figure never moves case money. `directPayment()` already calls
`supersedeStalePendingRequests()`, so when the accountant books through the same
box the doctor's request is cleared and the two cannot double-count.

Because a doctor's receipt auto-sends on commit, a doctor writes the figure at
most once per receipt — no supersede path is needed on their side.

### 2. The typed number means "what this box will read"

```
net           = total_amount - discount_amount
prior         = Σ net of non-draft receipts with a lower invoice_seq
required pool = prior + typed
current pool  = cases.paid_amount + cases.discount_amount
amount        = required pool - current pool
```

`amount` is what gets requested (doctor) or booked (admin/accountant). It is
computed server-side only; the client never does money arithmetic.

FIFO forbids a newer receipt reading paid while an older one is unpaid, so
working backwards from the typed figure is the only interpretation that leaves
the box showing what was typed.

### 3. Confirm before commit

Blur/Enter POSTs with `confirm=0`. The server returns the breakdown it computed
and commits nothing. JS renders it; OK re-POSTs with `confirm=1`. The doctor's
version also warns that the receipt is about to leave them:

```
وەرگیراوی C20260800002-3 = 13,489,556
⚠ ئەم وەسڵە بۆ ژمێریار دەنێردرێت — دوای ئەوە ناتوانیت دەستکاری بکەیت
پێویستە ئەم وەسڵانە پێشتر تەواو بن:
   C20260800002-1     375,000
   C20260800002-2   3,323,340
داواکاری پارەدان        17,187,896
```

### 4. Lowering is refused

`Payment::refund()` voids a whole payment row; there is no partial reversal.

The guard is on **this box's own figure**, not on the case pool. Testing only
`amount <= 0` is wrong: typing 0 into a later receipt still leaves a positive
`amount` (it would settle the earlier ones), so the refusal compares `target`
against what the box currently reads — `0` for a draft, otherwise
`clamp(current pool − prior, 0, net)`, the same figure `annotateFifo()` derives.
`target == covered` answers "nothing changed"; `target < covered` points at the
payments list.

### 5. Accountant surface

`accountant/report-detail.php` replaces its compact وەسڵەکانی سەردان list with
the same tab strip + `cases/partials/invoice_pane.php` panes the doctor sees.
Money boxes (داشکاندن, وەرگیراو) are live; treatment rows keep the lock icon —
an accountant does not rewrite billed clinical work.

`$canDiscount` gains `|| $canReceiveMoney`. It previously opened only for a
draft or the `allowEditSentInvoice` admin escape hatch, which would have left
the accountant unable to do the one thing the receipt is handed over for.
Re-cutting the discount is a money term; rewriting the billed work stays behind
`$editable` and the escape hatch, untouched. This also widens it for admins on
`cases/view.php` — deliberate: this database has no accountant user, so every
accountant duty is currently performed by an admin.

This requires the pane's CSS and money JS to be shared rather than inlined in
`cases/view.php`.

### 6. Instant recalculation

While typing, ماوە moves against the typed value. `rtDiscPreview()` currently
reads a static `rem.dataset.paid`; both previews are refactored onto one helper
so they cannot fight each other.

On commit the page reloads: receipt status, tab badges, payments list,
accountant queue and case totals all change at once, and a pane-only swap would
leave several of them stale.

## Schema

One additive migration, `2026_08_25_payment_invoice_link.sql`:

```sql
ALTER TABLE payments ADD COLUMN invoice_id INT UNSIGNED NULL DEFAULT NULL AFTER case_id;
ALTER TABLE payments ADD KEY idx_payments_invoice (invoice_id);
```

`Database::insert()` builds its column list from the array, so `createRequest()`
and `directPayment()` carry the new key through with no signature change.
Without it a pending request can only be tied to its receipt by string-matching
notes, and the receipt cannot honestly show چاوەڕوانی ژمێریار after sending.

`annotateFifo()` is untouched; `pending_amount` is a separate annotation.

## Files

| File | Change |
|---|---|
| `database/migrations/2026_08_25_payment_invoice_link.sql` | new — `payments.invoice_id` |
| `classes/CaseInvoice.php` | `planPaidTarget()` back-solve; `pending_amount` annotation |
| `cases/partials/invoice_pane.php` | وەرگیراو becomes an input; pending badge; `$canReceiveMoney` in the scope contract |
| `assets/css/invoice-pane.css` | new — extracted from `cases/view.php` |
| `assets/js/invoice-pane.js` | new — discount + paid preview/save/confirm, shared |
| `cases/view.php` | `set_invoice_paid` handler; use shared css/js; set `$canReceiveMoney` |
| `accountant/report-detail.php` | tab strip + panes; `set_invoice_paid` / `set_invoice_discount` handlers |
| `sw.js` | `CACHE_VERSION` → v6, so clients fetch the two new assets |

## Guards

Case open; receipt belongs to the case; doctor must own the case and the receipt
must be a draft; typed value clamped to `0..net`; computed amount `> 0`; capped
at case remaining; `createRequest()`'s anti-stacking refusal surfaced verbatim;
CSRF on both POSTs; every role check server-side, not merely hidden in the UI.

## Out of scope

Invoice discounts live on `case_invoices.discount_amount` and never roll up into
`cases.discount_amount`. A case whose receipts all read fully paid therefore
still shows the sum of its receipt discounts as case-level remaining. That is
true today; fixing it means changing how case totals absorb invoice discounts.

## Verified

Driven over HTTP against the local install with forged sessions, on case 65
(`C20260800002`, receipts -1 sent / -2 sent / -3 draft), then restored to its
original state — money, invoice statuses, payments and report notes all put back.

* **Back-solve** — typing 13,489,555.83 into `-3` plans 17,187,895.83 with `-1`
  (375,000) and `-2` (3,323,340) listed as ahead of it.
* **Admin commit** — 1,000,000 into the draft `-3`: draft sent, one approved
  payment of 4,698,340 linked to invoice 8, `-1`/`-2` flipped to processed, and
  `-3` reads exactly the 1,000,000 that was typed.
* **Accountant commit** — 10% discount on `-3` (net → 12,141,000), then paying
  that net settled the receipt to processed.
* **Doctor commit** — 5,000,000 on their own draft: receipt sent, case money
  unmoved (`paid_amount` still 0), one pending request of 8,698,340, and a
  single combined accounting-report entry.
* **Permissions** — a doctor gets one editable box (their draft) and is refused
  on a sent receipt; after their one-shot commit they have none.
* **Guards** — 0 on an uncovered receipt → "هیچ گۆڕانکارییەک نەکرا"; another
  case's invoice id → "وەسڵ نەدۆزرایەوە"; above net → clamped to net.

Double-collect (accountant books through the box while the doctor's request is
still pending) needs no new code: `Payment::approve()` already auto-rejects a
request that would push past the case balance.

---

# Follow-up: receipt-by-receipt accounting (same day)

The clinic works receipt by receipt, so the accountant screen's case-level
controls were the wrong shape for it.

## Sidebar stripped

Removed: `چاپکردنی پسوڵە`, `بینراو مارک بکە`, `وەرگرتنی پارە`,
`داشکاندن + پارەدان`, `داشکاندن` (button and the summary-box pencil),
`وەسڵی کەیس`, `لابردن لە لیست`, `کۆتاییهێنان بە ڕاپۆرت` — with their dialogs,
their JS, and the `collect_payment` / `quick_settle` / `apply_discount` /
`mark_reviewed` / `archive_report` handlers they were the only entry to.

Kept: the financial summary figures, and `گەڕاندنەوە بۆ لیست` on a report that
is *already* archived — without it, anything archived before this change could
never be recovered.

## پلانی قیست per receipt

`installment_plans.invoice_id` (nullable; NULL = a legacy case-wide plan). The
button lives on the receipt pane and defaults to that receipt's remaining.

A receipt may only start a plan once every earlier receipt is settled
(`CaseInvoice::installmentEligibility()`). That is not just a UI nicety: it is
what keeps a case to ONE active plan at a time, which is why `getPlanByCase()`,
`autoAllocatePayment()` and the three call sites in `Payment.php` needed no
change at all.

## پەسەندکردن — one button per receipt

`approve_invoice` approves every pending request carrying that `invoice_id`,
re-runs the waterfall, and marks the report seen — `بینراو مارک بکە` merged in
as asked. A refusal from `Payment::approve()` (it auto-rejects a request that
would over-collect) is surfaced, not swallowed.

## `case_receipts_settled()` — found while testing

A report was closed on `total − paid − discount <= 0`. Receipt discounts live on
`case_invoices` and never roll into `cases.discount_amount`, so on any case with
receipt discounts that expression stays positive by exactly their sum — the
report could never reach `processed`, and with the archive button gone it would
have been stuck in the queue forever. Closing now asks the receipts: every
non-draft receipt settled, no drafts, no uninvoiced work. Verified on case 65,
which carries 502,104 of receipt discounts and now closes correctly.

## `$showInvoiceActions` — also found while testing

The header buttons were first gated on `$canReceiveMoney`, which is true for an
admin on `cases/view.php` too — where neither handler exists. They are now gated
on a separate flag that only the accountant screen sets, so no page shows a
button it cannot honour.

## Verified

Driven over HTTP on case 65, then restored.

* Eligibility — `-1` allowed; `-2` refused naming `-1`; the draft `-3` refused.
* Plan on `-1` — 375,000 over 3 months with 75,000 down; the down payment posts
  against invoice 5 and `-1`'s وەرگیراو moves to 75,000, remaining 300,000.
  Plan on `-2` refused. The pane then shows a قیست badge and offers no button.
* Approve — a doctor request of 5,623,340 on `-3` approved in one click, linked
  to the report, `-1`/`-2` flipped to processed, `-3` reading the 2,000,000 the
  doctor typed, report → `reviewed`.
* Settling every receipt → report `processed` despite the case row still showing
  502,104.
* Buttons render on the accountant screen only: doctor 0, admin-on-view.php 0,
  accountant 2 approve + 1 plan.

---

# Follow-up 2: the patient's قەبز, and case totals back on the doctor's page

## قەبزی وەرگرتنی پارە — `cases/voucher.php`

One قەبز per PAYMENT, printed at the chair. `cases/receipt.php` lists the work
on a visit (پسوولەی وەرگرتنی پارە); this is its other half — money actually
handed over, and what is still owed. The patient brings it back to pay the rest.

Same A5 sheet, same letterhead, browser print. The letterhead moved to
`includes/clinic_branding.php` so the two documents cannot drift apart.

Printed immediately, before the accountant confirms — the doctor physically took
the money. A pending قەبز carries a `چاوەڕوانی پەسەندکردنی ژمێریار` mark, and its
figures already include the payment, so the patient's copy reads the same before
and after approval. A rejected request has no قەبز at all.

Reachable from the قەبز column in both payment lists, and from a banner on the
doctor's page immediately after money is taken. The banner rather than an
auto-opened window: a popup fired from the post-commit reload is blocked.

### The three figures — three bugs found while testing

The naive versions were all wrong on real data:

1. **Crediting the whole payment to the receipt it was typed into.** Money
   settles the oldest receipt first, so a 4,198,340 payment entered on `-3`
   actually put 3,698,340 on `-1`/`-2` and only 500,000 on `-3`. The قەبز now
   re-pours the pool itself and prints the receipt's true share.
2. **`total − paid − discount` as the debt.** Receipt discounts never reach
   `cases.discount_amount`, so that overstates what the patient owes by exactly
   the discounts already granted — the one number that must never be printed too
   high. The debt is summed from the receipts instead.
3. **Missing work on draft receipts.** `getUninvoiced()` only counts treatments
   with no `invoice_id`, so 100,000 sitting on an unsent draft on case 52 was
   invisible. Debt = unpaid on sent receipts + draft receipt nets + uninvoiced.

The case-scoped قەبز totals its top row the same way it totals the debt, so
`total − received = debt` always holds on the printed page.

## Case totals on the doctor's page

`.case-total-chip` beside the case number now carries کۆی گشتی / پارەی دراو /
ماوە instead of the total alone, each bound with `data-case-bind` so they update
live through the existing `updateCaseTotalsUI()`. The sidebar پوختەی دارایی panel
stays removed — work is entered receipt by receipt, but the case figures still
have to be readable at a glance.

## Verified

* Receipt-scoped قەبز (case 65, doctor, pending): 4,198,340 handed over, 500,000
  credited to `C…-3`, 12,989,556 still owed, pending mark present.
* Case-scoped قەبز (case 52, payment #57, no `invoice_id`): 515,000 / 25,000 /
  490,000 — reconciles, and 490,000 matches 415,000 billed − 25,000 paid +
  100,000 on the unsent draft.
* Chip on cases 52 and 65; قەبز column in both payment lists; the banner after a
  commit; every touched page 200 with no notices. Case 65 restored.
