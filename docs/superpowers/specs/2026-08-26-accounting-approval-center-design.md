# Accounting Consolidation: Approval Center, Doctor Requests, Patient Financial Statement

**Date:** 2026-08-26
**Status:** Approved design (user-reviewed)
**Scope:** dentHisab (PHP/MySQL, Sorani Kurdish RTL, InfinityFree PHP 8.3 target, manual upload deploys)

## 1. Goal

One flow: **Doctor prepares → Accountant verifies/books money → Department performs service → Patient financial statement reflects everything automatically.**

Keep the existing visit-invoice architecture (`case_invoices` + `payment_allocations`) as the accounting core. Consolidate the accountant's four scattered surfaces into one Approval Center. Re-enable doctor-originated requests (payment / debt intent / installment proposal) as strictly pending-until-approved. Give every patient one derived financial statement. Retire the legacy competing sources of truth without deleting any financial history.

## 2. Decisions made (user-confirmed)

1. **Debt request = derived debt + promise.** No new debt table or record. Debt is the remaining balance of approved invoices (ledger-derived). The doctor's "debt request" is a `payment_intent='debt'` flag on the sent invoice with optional promised date/amount. Accountant approval of the invoice books the receivable automatically.
2. **Installment request = pending plan.** Doctor proposes a plan → `installment_plans.status='pending'` (counts nowhere). Accountant edits terms, activates (→`active`, schedule finalized, down payment collected as direct payment) or rejects with reason.
3. **One page absorbs everything.** The Approval Center replaces `accountant/receiving.php`, `accountant/pending-reports.php`, and the approval half of `accountant/report-detail.php`, including the live station queue, 8-second polling, and sound alerts. Old URLs redirect.
4. **Legacy scope: money pages now, reports later.** All money-critical readers repoint to ledger-derived services/views this project. Cached `cases`/`patient_folders` totals remain as write-through mirrors updated only by services. Read-only PDF/analytics/export pages stay on the mirrors for a follow-up pass (list in §11).
5. **Architecture: derived queues + thin services.** No new queue/request tables. The Approval Center is a live view over real status columns. New services: `ApprovalQueue`, `PatientFinance`. Existing classes keep owning their transitions.

## 3. Source of truth (one definition per number)

| Number | Single source | Notes |
|---|---|---|
| Invoice total | `SUM(case_treatments.final_price)` for the invoice, excluding `status='cancelled'` | `CaseInvoice::recomputeTotal` (exists) |
| Invoice paid / remaining | `payment_allocations` × `payments.status='approved'` | Never stored. `AccountingLedger` (exists). Pending payments' allocations = reserved, shown as pending, never as paid |
| Case balance | `AccountingLedger::getSummary`: approved invoice net − approved allocations | `v_case_financial_summary` restates it in SQL (exists) |
| Installment remaining | `plan.total_amount − (down_payment + SUM(installment_payments.amount) where linked payment approved/null)` | `Installment::getPlanSummary` formula. `installment_plans.remaining_amount` (frozen) and `installment_schedule.paid_amount` become display-only legacy, never read for balances |
| X-ray charge | `original_amount − discount_amount` = `final_amount`; `final_amount` is never mutated after approval | Partial payments recorded via `patient_xray_debts` + `xray_debt_payments` |
| X-ray outstanding | `payment_status IN ('debt_approved','debt')` (legacy rows) minus debt payments, via `XrayManager` helpers only | `debt_pending` = pending request, NOT debt. `getOutstandingDebt` adjusted to exclude `debt_pending` |
| Patient balance | `PatientFinance` (new): Σ case balances + X-ray outstanding − patient credit | Plus new SQL view `v_patient_financial_summary` for list pages |
| Income | approved `payments` + X-ray income via `XrayManager::getIncome` | X-ray money never enters `payments` (unchanged) |

**Single-writer rules:**
- `payments` rows are created only by the `Payment` class (`createRequest`, `directPayment`). `DebtPayment::applyCasePayment` and `CaseManager::closeWithCredit` are refactored/removed accordingly.
- Installment money writes only via `Installment` against `installment_payments`; the legacy direct-`installment_schedule.paid_amount` path (`payInstallment`) is rewritten onto `installment_payments` + `updatePlanTotals`.
- Every `xray_requests` status change only via `XrayManager::transitionWorkflow`.
- Cached `cases.total/paid/discount_amount` and `patient_folders.*` are recomputed only by `CaseManager::updateCaseTotals` / `Folder::updateTotals`, fed from ledger-derived values, and fixed to exclude cancelled treatments.

## 4. Schema migrations (all additive; no drops; no history rewrites)

1. `installment_plans`: extend `status` enum with `'pending'`, `'rejected'`; add `proposed_down_payment DECIMAL(15,2) NULL`, `approved_by INT NULL`, `approved_at DATETIME NULL`, `reject_reason VARCHAR(500) NULL`.
2. `case_invoices`: add `payment_intent ENUM('cash','debt','installment') NULL`, `promised_date DATE NULL`, `promised_amount DECIMAL(15,2) NULL`.
3. New view `v_patient_financial_summary`: per-patient invoiced / discount / approved / paid / balance (from `case_invoices` + approved allocations) + X-ray outstanding + credit balance.
4. `accounting_reports`: frozen. No new writes anywhere in code. Table retained read-only for audit; historical rows reachable from the Approval Center history area.

No `payments.approved_at` column is added — the code writing it is a bug and is removed (§11).

## 5. Doctor-side flow (`cases/view.php` + invoice pane)

Unchanged: draft tab per work day; treatments auto-file into the active draft; per-invoice discount editable while draft; `send` recomputes, refuses empty, freezes as `sent`; `returned` invoices reopen for correction.

Changes:
1. **Intent at send.** The send dialog asks for payment intent: نەقد (cash — patient goes to accounting), قەرز (debt — optional promised date/amount), قیست (installment — opens the plan proposal dialog). Stored on the invoice; shown in the Approval Center as the required-action hint. Intent is doctor-declared metadata; it never changes balances by itself.
2. **وەرگیراو one-shot re-enabled for doctors.** Typing a received figure runs `planPaidTarget` dry-run → on confirm, auto-sends the draft in the same commit, then `Payment::createRequest` (pending payment + reserved allocations + idempotency key). Accountant/admin keep `directPayment`. The dead `request_payment` branch at `cases/view.php:683-759` and stale comments in `invoice_pane.php` are deleted.
3. **Installment proposal.** The ungated `create_installment` becomes: doctor → plan `status='pending'` with proposed terms + `proposed_down_payment` (no schedule finalization, no money). Accountant/admin creating a plan (Approval Center or statement page) still goes direct-to-active. One active-or-pending plan per case.
4. **Case close.** `closeWithCredit`'s fabricated pending `credit` payment is removed. Closing a case with a balance just closes it; the balance remains derived debt.
5. **No more `accounting_reports` writes** from any send/request path.
6. **Notifications:** new `notifyInvoiceSent` wrapper (recipients: accountants + admins, link → Approval Center); `notifyPaymentRequested` retargeted to accountants + admins with an Approval Center link; installment proposals notify via `notifyInstallmentPlan` with pending wording. All through the `Notification` class wrappers per the notifications-v2 rule.

## 6. Approval Center

**Page:** `accountant/approval-center.php` (accountant + admin). **API:** `api/approval-center.php`. **Service:** `classes/ApprovalQueue.php` — the only place queue queries live; also feeds the sidebar/header badge.

Queues are pure views of real status columns:

| Tab | Row source | Drawer actions |
|---|---|---|
| پسووڵەکان | `case_invoices.accounting_status='sent'` | approve · edit discount + accounting notes (`setDiscount`) · return (reason required) |
| پارەدانەکان | `payments.status='pending'` | approve · adjust amount pre-approval (new `Payment::updatePendingAmount`, audited, allocations re-validated) · reject (reason required) |
| قیستەکان | `installment_plans.status='pending'` | edit terms · activate (books down payment via `directPayment`, finalizes schedule, →`active`) · reject (reason required) |
| ئێکسڕەی | `xray_requests.request_status IN ('pending_accountant','returned_to_accountant')` | current modal semantics kept: approve payment / approve debt, optional amount correction + discount + note (`correctAmount`/`applyDiscount` → `approvePaymentAndSendToXray`/`approveDebtAndSendToXray`) |
| ژووری چاوەڕوانی | `waiting_room.status='at_accounting'` (today, de-duped against open items) | open patient statement · take payment |

Row fields: type, patient, doctor, number (`invoice_no`/`payment_number`/plan id/X-ray id), amount, payment type/intent (+promise for قەرز), created time, status chip, required action. Common header: patient search, doctor filter, date range, tab counts. Row click → inline detail drawer (invoice lines, ledger summary, allocation breakdown, history) + link to the patient financial statement. No navigation to other pages for actions.

Live behavior kept from `receiving.php`: 8s polling with `since` cursor, sound on new arrivals, open/history toggle. History = items acted on in the last 30 days read from the same real tables (`approved`/`returned`/`rejected`/`pending_xray`…). The mark-seen/hide/archive concept disappears with `accounting_reports`; queues empty by acting.

**Badge:** sidebar (`includes/sidebar.php`) + header count = `ApprovalQueue::countOpen()` (sum of the four approval queues), replacing the `accounting_reports.status='pending'` count.

**Redirects:** `accountant/receiving.php`, `accountant/pending-reports.php` → Approval Center; `accountant/report-detail.php` → patient statement (`?case_id` mapped to patient + anchor). `api/accountant-feed.php` retired (page that called it is gone); stray `accountant/update_xray_full_workflow.sql` moved out of the web root into `database/migrations/`.

**Duplicate-processing protection (centralized):** every transition runs in a transaction with an optimistic status guard (`UPDATE … WHERE id=? AND status='<expected>'`, affected-rows checked), `FOR UPDATE` invoice locks during allocation (exists in `AccountingLedger::lockInvoicesForAllocation`), idempotency keys on payment booking (exists). A second submission gets a friendly "پێشتر جێبەجێکراوە" plus a refreshed row — never a second booking, approval, or allocation.

## 7. X-ray workflow (kept, repaired)

Routing preserved exactly:
- `cash` → `pending_accountant` / `pending_payment`; accountant approval → `pending_xray` / `paid`.
- `debt` → `pending_accountant` / `debt_pending`; accountant approval → `pending_xray` / `debt_approved`.
- `dont_cash` → mandatory reason → directly `pending_xray` / `not_required` (never touches accounting).
- X-ray money never enters `payments`; the X-ray ledger (`xray_requests`, `xray_payment_logs`, `xray_request_history`, `patient_xray_debts`, `xray_debt_payments`) stays authoritative.

Repairs:
1. Retire the divergent settlement path: `xray/payments.php` `process_cash`/`process_debt`/`apply_discount` actions removed; settlement happens only via the Approval Center → `transitionWorkflow`. The page remains for payment history and collecting existing `patient_xray_debts` (`payDebt`).
2. All X-ray debt readers switch to `XrayManager` helpers with the canonical status list — fixing the `payment_status='debt'`-only filter that makes workflow-approved debt invisible in `debts.php`, `api/patient-debt.php`, `Patient::getAll`, `DebtCollection`, `DebtPayment`.
3. `DebtPayment::applyXrayPayment` stops mutating `final_amount`; partial payments are recorded through `patient_xray_debts` + `xray_debt_payments`; the invalid `'debt_payment'` log action becomes `'debt_paid'`.
4. `XrayManager` raw `notifications` inserts move onto `Notification` wrappers.
5. `getOutstandingDebt` excludes `debt_pending` (pending-until-approved principle); dashboard/debt figures change accordingly and this is called out in the rollout notes.

## 8. Patient Financial Statement

**Page:** `patients/statement.php?patient_id=` with `#case-N` anchors. **Service:** `classes/PatientFinance.php` composing live from `AccountingLedger` (`getSummary`/`getInvoices`/`getPayments`/`getActivity`), `Installment::getPlanSummary` + dynamic schedule, `XrayManager`, and `DebtPayment` credit. Zero stored patient totals.

Layout: summary cards (invoiced · discounts · paid · pending requests · case balance · X-ray outstanding · credit · installment remaining) → per-case blocks with per-invoice rows (no, date, total, discount, net, allocated paid, remaining, status, intent/promise) and case subtotal → payments with allocation breakdown incl. refunds/reversals → installment plan + schedule (dynamic waterfall statuses) → X-ray charges/payments → chronological audit timeline (`getActivity` merged with `xray_request_history`).

Access: admin/accountant full, including cash-desk actions (receive payment with allocation picker, create plan, reverse) via the same service endpoints the Approval Center uses (`api/finance-actions.php` shared handlers — no duplicated logic). Doctors: AccessGuard-scoped read-only view of their own folders/cases/X-rays.

## 9. Service layer summary

| Service | Status | Responsibility |
|---|---|---|
| `AccountingLedger` | exists, bug-fixed | invoice decoration, allocations, case summary, activity |
| `CaseInvoice` | exists, extended | lifecycle + intent/promise fields on send |
| `Payment` | exists, extended | requests, booking, approve/reject/refund, `updatePendingAmount` |
| `Installment` | exists, reworked | pending→active lifecycle, payments via `installment_payments` only |
| `XrayManager` | exists, repaired | all X-ray transitions + money helpers, canonical status lists |
| `ApprovalQueue` | **new** | queue lists, counts, detail payloads, badge, since-cursor |
| `PatientFinance` | **new** | patient-level statement + summary; backend of `v_patient_financial_summary` consumers |
| `Notification` | exists, extended | `notifyInvoiceSent`, retargeted request notifications, X-ray wrappers |

UI pages call services; no page computes balances or flips statuses with inline SQL.

## 10. Legacy retirement (this project)

Repointed to `PatientFinance`/ledger now: root `debts.php`, `api/patient-debt.php`, `Patient::getById/getAll/getStatistics*/count*` debt figures, `DebtCollection::getDebtors` + stats, `patients/view.php` cards, `admin/invoices.php`, all `accountant/` pages, `api/export-debts.php`.

Frozen/removed: all `accounting_reports` writes; `receiving.php`/`pending-reports.php`/`report-detail.php`/`accountant-feed.php` (redirect stubs); dead `request_payment` branch; `Installment::autoAllocatePayment` (dead code); case-level `apply_discount` UI in `cases/view.php` (per-invoice discount supersedes; columns kept for history/mirror math); `closeWithCredit` payment fabrication.

## 11. Confirmed bug fixes in scope

1. `AccountingLedger::getActivity` — `$parts` re-initialized after the `case_treatments` clause is appended, silently discarding it.
2. `accountant/installments.php:224` — reads non-existent `paid_amount` on the plan; remaining always shows full total. Use `getPlanSummary`.
3. `doctors/report-pdf.php:53` — invalid `pay.status='completed'` filter; always 0. Use `'approved'`.
4. `DebtPayment::applyCasePayment` — writes non-existent `payments.approved_at`, out-of-enum `payment_type`, bypasses `Payment` (no allocations/number/idempotency). Reroute through `Payment::directPayment` with allocations.
5. X-ray `payment_status='debt'`-only filters (~10 sites) — replace with `XrayManager` helpers.
6. `CaseManager::updateCaseTotals` — include `status<>'cancelled'` filter on treatments; feed `paid` from ledger-derived values (mirror semantics).
7. Dead `accountant/station.php` links in the feed — gone with the feed rewrite.
8. Irreversible archive / missing un-archive — moot (archive concept removed).
9. `installment_schedule.paid_amount` dueling writers — single path via `installment_payments`.
10. `case_invoices.status` legacy column: kept, synced only by existing `syncStatuses`; flagged for future removal, not in this project.

## 12. Safety, audit, permissions

- Every transition: transaction (Database SAVEPOINT nesting), optimistic status guard, `AuditLog::logChange` with actor, before/after, and reason.
- Return/reject reasons server-enforced non-empty. `dont_cash` reason already mandatory.
- Roles: doctor = prepare/propose only (no booking, no activation); accountant/admin = book/approve/return/reverse/adjust; xray_department untouched. CSRF via existing global fetch patch + `Csrf` class. NEVER-reuse-PDO-named-placeholders rule respected (emulation off).
- Financial history never deleted; corrections are reversals (`refund`, `returned`, `rejected`, `cancelled`) with audit rows.

## 13. Testing

CLI test scripts under `tests/` (plain PHP, run against a scratch local database; local MySQL 9 on :3306):
- Transition tests: double-approve blocked; return rejects pending allocated payments; pending plan counts in no balance; intent never changes balances; idempotent payment booking; X-ray transitions log history + sync waiting room.
- Derivation tests: `PatientFinance` totals vs hand-computed fixtures; ledger vs mirror agreement after each operation; X-ray outstanding across `debt`/`debt_approved`/`debt_pending` fixtures; installment remaining across payment/refund sequences.
- Regression checks for each §11 bug.

## 14. Rollout (manual InfinityFree upload)

1. Run additive migrations (`database/migrations/2026_08_26_*.sql`) on live first (per existing practice).
2. Upload classes/services (backward-compatible with old pages still present).
3. Upload new pages + redirect stubs + sidebar/header badge change.
4. Bump `sw.js` `CACHE_VERSION`.
5. `ensureLegacyAllocations` continues lazily back-filling old payments; no data rewrite needed.
6. Release note: debt totals may shift slightly (X-ray `debt_approved` now visible; `debt_pending` excluded) — expected correction, not regression.

## 15. Out of scope (follow-up pass)

- Repointing read-only PDF/analytics/export pages still on mirror columns: `cases/export_excel.php`, `cases/export_pdf.php`, `patients/report.php` + `report-pdf.php`, `doctors/` report pages, `appointments-analytics*`, `appointments-by-type*`, `today-appointments*`, `folders/` list figures.
- Removing `case_invoices.status` legacy column and the `cases`/`patient_folders` mirror columns.
- Expenses subsystem (untouched).
- UI restyling beyond the two new pages (the compact-UI pass rides on the new pages only).
