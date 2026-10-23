/*
 * ONE-TIME FINANCIAL RESET  —  2026-08-16
 *
 * !!! NOT A MIGRATION !!!
 * This file lives in database/, NOT database/migrations/, on purpose.
 * MigrationRunner globs only database/migrations/*.sql, so this will never
 * auto-run. Moving it into that folder would wipe the live server's money
 * data on the next page load. Run it by hand, once, per environment.
 *
 * GOAL: clear all money data, keep all clinical/scheduling data.
 *
 *   DELETED : cases, payments, installments, invoices, x-ray requests,
 *             expenses, accounting reports, x-ray price list
 *   KEPT    : patients, visits, waiting_room, appointments, patient_folders,
 *             doctors, users, visit_flow_log
 *
 * "Debts" is not a table — it is derived from cases (total_amount -
 * paid_amount), xray_requests.payment_status and patient_credits. Clearing
 * those three sources is what zeroes every debt figure in the app.
 *
 * ORDER IS LOAD-BEARING. Three FKs are RESTRICT, not CASCADE:
 *   payment_allocations.payment_id  -> payments        RESTRICT
 *   payment_allocations.invoice_id  -> case_invoices   RESTRICT
 *   installment_payments.payment_id -> payments        RESTRICT
 * Deleting cases first would try to CASCADE into payments and abort the whole
 * statement with a constraint error, so the RESTRICT children go first.
 *
 * Take a backup before running:
 *   mysqldump -u root --single-transaction leza > backup.sql
 */

START TRANSACTION;

/* ── 1. RESTRICT children first, or the payment deletes below abort ────── */
DELETE FROM payment_allocations;
DELETE FROM installment_payments;

/* ── 2. Installments (schedule before plans; plans CASCADE from cases) ─── */
DELETE FROM installment_schedule;
DELETE FROM installment_plans;

/* ── 3. Payments and invoices ──────────────────────────────────────────── */
DELETE FROM payments;
DELETE FROM case_invoices;
DELETE FROM accounting_reports;

/* ── 4. X-ray: children first, then the requests ───────────────────────── */
DELETE FROM xray_debt_payments;
DELETE FROM patient_xray_debts;
DELETE FROM xray_payment_logs;
DELETE FROM xray_request_history;
DELETE FROM xray_images;
DELETE FROM xray_requests;
DELETE FROM xray_pricing;

/* ── 5. Expenses (the three sub-ledgers are already empty) ─────────────── */
DELETE FROM expenses;
DELETE FROM expenses_lab;
DELETE FROM expenses_general;
DELETE FROM expenses_store;

/* ── 6. Patient credit / debt ledgers ──────────────────────────────────── */
DELETE FROM patient_debt_payments;
DELETE FROM patient_credit_logs;
DELETE FROM patient_credits;
DELETE FROM debt_call_locks;

/* ── 7. Cases last. CASCADEs into case_images, case_treatments,
 *      dental_chart, arch_procedure_logs, lab_orders, prescriptions.
 *      appointments.case_id and xray_requests.case_id are SET NULL, so
 *      appointments survive with their case link cleared.
 *
 *      NOTE: case_images ROWS are removed here, but the 335 MB of actual
 *      photo files under uploads/cases/ and on S3 are deliberately left in
 *      place — deleting them is a separate, manual decision.                */
DELETE FROM cases;

/* ── 8. Repair dangling case_id references.
 *      These four tables carry a case_id with NO foreign key, so nothing
 *      cleaned them up automatically. The rows are audit/history records
 *      worth keeping, so blank the pointer instead of deleting the row.     */
UPDATE unassigned_case_log  SET case_id = NULL WHERE case_id IS NOT NULL;
UPDATE appointments_deleted SET case_id = NULL WHERE case_id IS NOT NULL;

COMMIT;

/* ── 9. AUTO_INCREMENT resets — REMOVED, and here is why. ────────────────
 *
 * This section used to run:
 *     ALTER TABLE accounting_reports AUTO_INCREMENT = 1;  (and 7 others)
 *
 * Running it against a LIVE system took the app down. An ALTER needs an
 * EXCLUSIVE metadata lock. Two idle connections were sitting on open
 * read transactions, so the ALTER could not acquire it and waited — and a
 * *pending* exclusive MDL request blocks every SELECT that queues up behind
 * it. Within seconds every page touching accounting_reports hung, and the
 * "new case" dialog spun forever. mysqld's default lock_wait_timeout is
 * 31536000 seconds (one year), so it would never have given up on its own.
 *
 * The resets were purely cosmetic — they only make the next inserted row
 * get id 1 instead of continuing the old sequence. Not worth an outage.
 *
 * If you ever do want them, run them during downtime, and ALWAYS set a
 * timeout first so a blocked ALTER fails fast instead of freezing the app:
 *
 *     SET SESSION lock_wait_timeout = 5;
 *     ALTER TABLE accounting_reports AUTO_INCREMENT = 1;
 *
 * Check for blockers before starting:
 *     SELECT trx_mysql_thread_id, trx_state, trx_rows_modified
 *     FROM information_schema.INNODB_TRX;
 */
