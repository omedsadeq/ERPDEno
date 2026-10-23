# Post-Merge Follow-ups — accounting-approval-center

Source: final whole-branch review triage (2026-08-26). The branch merged with all Critical/Important findings fixed; these are the accepted "fix-soon" items and open decisions, in priority order.

## Product decision needed

1. **DONE (2026-08-26).** Debt-collection call queue: gross vs net-of-credit. Decision: net the queue AND surface the credit. `DebtCollection::getDebtors`/`getStatistics`/`getNextToCall` now LEFT JOIN `patient_credits` and net `total_debt = GREATEST(0, case_debt + xray_debt - credit)` — same formula shape as debts.php's post-Task-15 fix — with `credit_balance` exposed per row; a fully-credit-covered patient drops off the queue entirely. `normal_debt` derives from the netted total. `accountant/debt-collection.php` shows a credit column, visible to admin/accountant only (doctors still see only the net total, per the statement page's existing credit-is-money-roles-only rule). `accountant/debt-collection-pdf.php`/`call-reports.php` needed no changes (they render `DebtCollection`'s output directly, or don't show debt totals at all). Tests: `tests/cases/DebtQueueTest.php` (new) + updated `tests/cases/RepointTest.php` assertions.

## Fix-soon (small, safe, post-merge batch)

2. `XrayManager::getDailyReport`/`getPaymentStatistics` still count `debt_pending` in daily debt figures — contradicts the pending-isn't-debt principle everywhere else. Small status-list change.
3. `DebtPayment::getPatientDebtItems` case rows still derive `remaining` from the `cases` mirror — the payment modal's per-case numbers can disagree with the (ledger-derived) list that opened it. Repoint to `v_case_financial_summary.balance`.
4. `patient_xray_debts.request_id` lacks a UNIQUE key and the ensure-row path is a non-atomic SELECT-then-INSERT — a concurrent duplicate row would fan out through the new LEFT JOINs. One additive migration + upsert.
5. `xray_debt_payments.payment_method` is hardcoded `'cash'` in the distribution path — thread the real method through `distributePayment` → `applyXrayPayment`.
6. **DONE (2026-08-26).** cases/view.php: unmatched POST actions fall through the handler chain silently (e.g. a doctor submitting the admin-only Alt+P payment dialog gets a plain reload). One-line `case_view_fail` fallback after the chain. Landed together with the case-level doctor payment-request feature (`request_payment` action) that made the gap concrete — see `tests/cases/DoctorCasePaymentRequestTest.php`.
7. وەرگیراو auto-send stores `payment_intent = NULL`; consider defaulting it to `'cash'`, and preserving a returned invoice's prior intent on resend.
8. `Installment::activatePlan` doesn't re-check one-plan-per-case inside its transaction — two pending plans created through the (rare) proposal race could both activate. Cheap in-transaction guard.
9. `api/finance-actions.php` docblock overstates the domain-exception claim for `Payment::updatePendingAmount` (one plain-`Exception` throw exists; behavior is correct, comment is not).
10. Invoice modal (approval center): consider showing stored `accounting_notes` read-only in non-editable invoice states (returned/processed) — currently displayed only while editable; deliberately reverted out of the layout-polish change as an undisclosed behavior addition, but flagged as a genuine UX improvement candidate.

## Spec §15 follow-up pass (unchanged scope)

Read-only report/PDF/analytics pages still on mirror columns AND on frozen `xr.final_amount` sums (no partial-collection awareness): `appointments-by-type(.pdf).php`, `appointments-analytics(-pdf).php`, `classes/Appointment.php` (2 sites), `today-appointments(-pdf).php`, `patients/index.php`, `patients/ajax_search.php`, `cases/export_excel.php`, `cases/export_pdf.php`, `patients/report(.pdf).php`, `doctors/` report pages, `folders/` list figures. Also future removal candidates: `case_invoices.status` legacy column, `cases`/`patient_folders` mirror columns, ~270 lines of dead discountDialog CSS in cases/view.php.

## Operations / deploy watch

- First live day: watch badge/queue latency on InfinityFree (`ApprovalQueue::counts()` is memoized per request, not across requests) and the `v_patient_financial_summary` list-page cost; the rollout checklist's reconciliation queries (2a/2b/2c) must run BEFORE go-live.
- Expected number shifts (already in the rollout checklist): `debt_approved` X-ray debt now visible, `debt_pending` excluded, credit netted, X-ray partials now reduce outstanding.
- X-ray debt collection now lives in the debt-distribution flow (debts.php modal / api/debt_payment.php); xray/payments.php is history-only — spec §7.1's "keeps payDebt" note is superseded.
