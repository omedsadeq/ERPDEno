# Rollout Checklist — Accounting Approval Center

**Date:** 2026-08-26
**Target:** InfinityFree (PHP 8.3, manual upload — no CI/CD, no shell access)
**Source:** `docs/superpowers/specs/2026-08-26-accounting-approval-center-design.md` §14, plus rollout notes carried in `.superpowers/sdd/2026-08-26-accounting-approval-center/progress.md`

This is the upload runbook for the accounting-approval-center branch. Follow the
steps **in order** — several depend on the previous step already having run
against the live database.

---

## 1. Upload order

1. **Migrations first.** Upload `database/migrations/2026_08_26_*.sql` (and
   every other new file under `database/migrations/`) to the live
   `database/migrations/` folder. Do **not** run them by hand — the app's
   `MigrationRunner` auto-applies any `.sql` file it hasn't seen yet on the
   very next page request (statements are `;`-separated, idempotent-friendly:
   errors 1060/1061/1050/1091/1062 are ignored). Load any page once after this
   step (e.g. the login page) so the auto-apply actually runs before step 2's
   code goes live and starts calling the new columns/views.
2. **`classes/` next.** Upload the whole `classes/` directory. Every class in
   this project is backward-compatible with the *old* pages that are still
   live at this point (`accountant/receiving.php`,
   `accountant/pending-reports.php`, etc. still exist as real pages until
   step 3), so this order never leaves the site broken mid-upload.
3. **Pages, `api/`, `includes/`, `assets/`.** Upload the new
   `accountant/approval-center.php`, `patients/statement.php`, the retired
   pages' redirect stubs (`accountant/receiving.php`,
   `accountant/pending-reports.php`, `accountant/report-detail.php`,
   `api/accountant-feed.php`), every file under `api/` (new
   `api/finance-actions.php`, `api/approval-center.php`, plus any touched
   existing endpoints), `includes/header.php` + `includes/sidebar.php` (badge
   change), and `assets/` (new `assets/js/approval-center.js`,
   `assets/css/*` additions, and `sw.js` — see step 4).
4. **Confirm `sw.js` `CACHE_VERSION` was bumped** (this task bumped it to
   `denthisab-v7`) and is included in the step-3 upload. A stale service
   worker on a returning browser will otherwise keep serving the old
   `accountant/receiving.php` JS/CSS from cache even after the server-side
   redirect stub is live.
5. **`storage/migrations.lock` regenerates on its own** — it is a
   server-local cache file, never uploaded from a dev machine. The first
   request after step 1 rebuilds it against the live migrations folder.
6. **`ensureLegacyAllocations` needs nothing from you.** It lazily
   back-fills any old un-allocated `payments` rows into `payment_allocations`
   the first time a case's ledger is read (`AccountingLedger::getInvoices` /
   `getPayments`). No manual data rewrite, no maintenance-mode window
   required. Expect the *first* page-load per heavy legacy case (lots of old
   un-allocated payments) to run noticeably slower than every load after
   it — that request pays the one-time backfill cost, then it's cached.

**Never uploaded:**
- `tests/` — the whole directory (CLI-only harness, scratch-DB bootstrap,
  fixtures). It has no runtime role on live and nothing under it should ever
  reach the InfinityFree file tree.
- `.git` — never uploaded, as with every prior deploy on this project.

---

## 2. Deploy-time data check (Task 4 carry-forward)

`DebtPayment::distributePayment` now throws and rolls back the **whole
batch** if a case with outstanding debt does not have matching
`case_invoices` allocation capacity — the old hand-insert path never
consulted invoices at all, so this is a real behavior change against live
data, not just new code. Before flipping any receptionist-facing debt
collection live, run both reconciliation queries below against the
**live** database (read-only — they only `SELECT`) and resolve every row
they return (normally: send the case's un-invoiced work through
`CaseInvoice::createForUninvoiced` + accountant approval so it has a real
invoice) before receptionists start collecting against those patients.

**2a. Cases where the derived ledger and the `cases` mirror disagree on a real
(positive) balance.** A same-case drift here means `distributePayment` and
the mirror total will not agree on how much room the case has to absorb a
collection.

```sql
SELECT
    c.id                                              AS case_id,
    c.case_number,
    v.balance                                         AS ledger_balance,
    (c.total_amount - c.paid_amount - c.discount_amount) AS mirror_balance,
    ABS(v.balance - (c.total_amount - c.paid_amount - c.discount_amount)) AS drift
FROM cases c
JOIN v_case_financial_summary v ON v.case_id = c.id
WHERE v.balance > 0
  AND ABS(v.balance - (c.total_amount - c.paid_amount - c.discount_amount)) > 1
ORDER BY drift DESC;
```

**2b. Cases carrying a mirror balance but with no `case_invoices` row at
all** — these are exactly the cases `distributePayment` will now refuse to
allocate against (zero allocation capacity), where the old code would have
silently written an orphaned payment.

```sql
SELECT
    c.id                                              AS case_id,
    c.case_number,
    (c.total_amount - c.paid_amount - c.discount_amount) AS mirror_balance
FROM cases c
LEFT JOIN case_invoices ci ON ci.case_id = c.id
WHERE ci.id IS NULL
  AND (c.total_amount - c.paid_amount - c.discount_amount) > 0
ORDER BY mirror_balance DESC;
```

If either query returns rows, invoice that work (or write off the mirror
balance if it's genuinely stale/legacy) before relying on debt collection
for those patients — do not skip this because the queries come back empty
on staging; live has years of pre-invoice history staging does not.

**2c. Cases whose invoices exist but were never approved** — the ledger
already sees these as zero balance (only `accounting_status='approved'`
invoices ever count toward `v_case_financial_summary.balance`), while the
`cases` mirror still carries the old gross balance from the raw treatment
totals. This is the blind spot 2a/2b don't catch: 2a only flags cases where
the ledger balance is *positive* and drifts from the mirror; 2b only flags
cases with *zero* `case_invoices` rows. A case sitting here has invoices —
just all still `draft`/`sent`/`returned`/`cancelled` — and is exactly what
the new `distributePayment` will refuse to allocate against (zero approved
invoice capacity), the same as a 2b case, just for a different underlying
reason (unapproved, not uninvoiced).

```sql
SELECT
    c.id                                                  AS case_id,
    c.case_number,
    COALESCE(v.balance, 0)                                AS ledger_balance,
    (c.total_amount - c.paid_amount - c.discount_amount)  AS mirror_balance,
    GROUP_CONCAT(DISTINCT ci.accounting_status ORDER BY ci.accounting_status SEPARATOR ',') AS invoice_statuses
FROM cases c
JOIN case_invoices ci ON ci.case_id = c.id
LEFT JOIN v_case_financial_summary v ON v.case_id = c.id
WHERE (c.total_amount - c.paid_amount - c.discount_amount) > 0
  AND COALESCE(v.balance, 0) = 0
  AND NOT EXISTS (
      SELECT 1 FROM case_invoices ci2
      WHERE ci2.case_id = c.id AND ci2.accounting_status = 'approved'
  )
GROUP BY c.id, c.case_number, v.balance, c.total_amount, c.paid_amount, c.discount_amount
ORDER BY mirror_balance DESC;
```

Resolve every row the same way as 2a/2b: get the case's invoice(s) through
accountant approval (or write off the mirror balance if it's genuinely
stale/legacy) before receptionists start collecting against those patients.

---

## 3. Expected number shifts — not regressions

Release-note these to whoever watches the numbers day one, so a support
ticket doesn't get filed over an intentional correction:

- **X-ray debt totals shift.** `debt_approved` x-ray requests are now
  correctly *included* in every debt total (`debts.php`, `Patient::getById`,
  `DebtCollection`, etc. — previously several of these filtered on
  `payment_status = 'debt'` only and silently dropped `debt_approved` rows).
  `debt_pending` (a request still awaiting accountant approval, not real
  debt) is now correctly *excluded* everywhere it used to be counted. Net
  effect: most patients' x-ray debt figure moves, in either direction,
  matching the corrected definition in spec §3/§7.
- **Debt totals now net patient credit.** `debts.php` and
  `api/patient-debt.php` subtract `patient_credits.balance` from the debt
  figure (Task 15) — a patient sitting on unused credit will show a lower
  (or zero) debt than before.
- **The Approval Center badge count is a different formula than the old
  `accounting_reports.status='pending'` count it replaces** — expect the
  sidebar/header number to change on day one purely from the definition
  change, independent of any real backlog change.

---

## 4. Post-deploy smoke test (do this before calling it done)

Perform these as each real role, on live, immediately after the step-3
upload:

1. **Any authenticated page** — confirms migrations auto-applied cleanly
   (no fatal error banner, no missing-table error).
2. **Accountant/admin: `accountant/approval-center.php` loads**, all five
   tabs render (پسووڵەکان / پارەدانەکان / قیستەکان / ئێکسڕەی / ژووری
   چاوەڕوانی), and the sidebar/header badge count matches the tab counts
   (`ApprovalQueue::counts()['badge']` = پسووڵەکان + پارەدانەکان + قیستەکان
   + ئێکسڕەی, station excluded from the badge by design).
3. **Approve one real invoice on live data** end to end (پسووڵەکان tab →
   open drawer → approve) and confirm: the row leaves the queue, the badge
   count drops by one, and it appears under History.
4. **`debts.php` totals look sane** against a couple of patients you already
   know the numbers for — cross-check against §3 above rather than assuming
   any change is a bug.
5. **Old bookmarks still work**: `accountant/receiving.php`,
   `accountant/pending-reports.php`, `accountant/report-detail.php?case_id=N`
   and `api/accountant-feed.php` all redirect (or 410, for the feed) instead
   of erroring.
6. **Doctor: `cases/view.php`** — send an invoice with a payment intent,
   confirm the وەرگیراو one-shot request flow still works.
7. **Receptionist: the debts collection dialog** (`api/debt_payment.php`,
   Task 4's path) completes a collection against a patient who passed the
   reconciliation check in §2.
8. **X-ray department:** the x-ray queue still receives requests the
   accountant approved through the new Approval Center tab.

If any of steps 2–8 fails, do not proceed with announcing the release —
the classes/pages layering in §1 means the site stays usable at every
intermediate upload step, so there is no rush that would justify skipping
verification.
