# Accounting Approval Center Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate dentHisab's accounting into one derived-queue Approval Center, re-enable doctor requests as pending-until-approved, add a derived patient financial statement, and retire the legacy competing sources of truth — per the approved spec at `docs/superpowers/specs/2026-08-26-accounting-approval-center-design.md`.

**Architecture:** No new queue tables. Queues are live views over real status columns (`case_invoices.accounting_status='sent'`, `payments.status='pending'`, `installment_plans.status='pending'`, `xray_requests.request_status IN ('pending_accountant','returned_to_accountant')`). Two new services (`ApprovalQueue`, `PatientFinance`) plus targeted extensions to `CaseInvoice`, `Payment`, `Installment`, `XrayManager`, `Notification`. `accounting_reports` is frozen (read-only audit).

**Tech Stack:** Plain PHP 8.3 (no framework), MySQL (local: MySQL 9 on :3306; live: InfinityFree), Bootstrap RTL Sorani Kurdish UI, vanilla JS + fetch (global CSRF patch in `includes/header.php`), mPDF only where already used.

## Global Constraints

- **GIT (added 2026-08-26, user-approved):** local-only repo, branch `accounting-approval-center`, baseline `b2b4517`. Each task ends with green tests AND one commit (`git add <touched files> && git commit -m "task N: <summary>"`). Never push; `.git` is never uploaded to live.
- **NEVER reuse a PDO named placeholder** in one query (PDO emulation is off — reuse is fatal). Use positional `?` or distinct names.
- All money flows through the single-writer rules in spec §3. UI pages call services; no inline balance math or status flips in pages.
- Never delete financial rows. Corrections = status transitions with reasons + `AuditLog`.
- All user-facing strings in Sorani Kurdish, RTL, matching existing pages' tone. Currency: plain numbers, no symbol (existing rule).
- Notifications only via `classes/Notification.php` wrappers (never raw `create()`/raw inserts).
- Database API (`classes/Database.php`): `Database::getInstance()`, `->fetch($sql,$params)`, `->fetchAll(...)`, `->query(...)`, `->insert($table,$assoc)`, `->update($table,$assoc,$where,$whereParams)`, `->count($table,$where,$params)`, `->beginTransaction()/commit()/rollback()` (SAVEPOINT-nested — nest freely), `->lastInsertId()`.
- Migrations: drop `.sql` files into `database/migrations/`; `classes/MigrationRunner.php` auto-applies them sorted by filename on next request (lock file `storage/migrations.lock` invalidates automatically). Statements must be `;`-separated, `--` comments only, idempotent-friendly (errors 1060/1061/1050/1091/1062 are ignored).
- Test harness (built in Task 1): `php tests/run.php` from the project root. Tests use a scratch DB `denthisab_test` (env override `DB_NAME`), never the dev/live DB.
- Deployment is manual upload; final task bumps `sw.js` `CACHE_VERSION`.
- The two exploration reports' file:line references in this plan were verified on 2026-08-26; re-verify a line number before editing if the surrounding file has been touched by an earlier task.

---

### Task 1: CLI test harness + scratch database

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/run.php`
- Create: `tests/TestCase.php`
- Create: `tests/fixtures.php`
- Create: `tests/cases/SmokeTest.php`

**Interfaces:**
- Produces: `tests/run.php` (runs every `tests/cases/*Test.php`; exit 0 = all pass), class `TestCase` with `assertSame($exp,$act,$msg)`, `assertTrue($cond,$msg)`, `assertThrows(callable $fn, $msg)`, and `fx()` fixture helpers: `fx_user($role)`, `fx_patient()`, `fx_folder($patientId,$doctorUserId)`, `fx_case($folderId)`, `fx_treatment($caseId,$price,$invoiceId=null)`, `fx_invoice($caseId,$doctorId,$status,$accountingStatus,$total)` — each returns the inserted row id.
- Consumes: `config/config.php` (reads `DB_NAME` from env), `classes/MigrationRunner.php`, `database/schema.sql`.

- [ ] **Step 1: Write `tests/bootstrap.php`**

```php
<?php
// CLI-only test bootstrap. Builds a scratch DB and boots the app config against it.
if (PHP_SAPI !== 'cli') { die('tests are CLI only'); }

putenv('DB_NAME=denthisab_test');
$_ENV['DB_NAME'] = 'denthisab_test';

// Create the scratch DB before config.php connects to it.
// Read host/user/pass the same way config.php does (defaults live in config.php's $dbDefaults).
require_once __DIR__ . '/../config/env-defaults.php'; // ONLY if such a file exists; otherwise inline below
```

**Adaptation note (do this, don't guess):** open `config/config.php:20-45`, copy its `$dbDefaults` bootstrap lines verbatim into `tests/bootstrap.php` so host/user/pass resolve identically, then:

```php
$pdo = new PDO('mysql:host=' . $host . ';port=3306;charset=utf8mb4', $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fresh = getenv('TEST_KEEP_DB') ? false : true;
if ($fresh) { $pdo->exec('DROP DATABASE IF EXISTS denthisab_test'); }
$pdo->exec('CREATE DATABASE IF NOT EXISTS denthisab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE denthisab_test');
if ($fresh) {
    // Base schema
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $stmt) {
        try { $pdo->exec($stmt); } catch (PDOException $e) { /* seed rows may collide; tables must not */ }
    }
}
require_once __DIR__ . '/../config/config.php';   // connects Database:: to denthisab_test
require_once __DIR__ . '/../classes/MigrationRunner.php';
$runner = new MigrationRunner(
    Database::getInstance()->getConnection(),
    __DIR__ . '/../database/migrations',
    __DIR__ . '/storage'                    // test-local lock dir — NEVER storage/migrations.lock
);
@mkdir(__DIR__ . '/storage');
if ($fresh) { @unlink(__DIR__ . '/storage/migrations.lock'); }
$runner->run();
```

If `config/config.php` starts a session / checks auth / enters RECOVERY_MODE on CLI, guard those: read `config/config.php` first and set whatever constants it needs to skip web-only behavior (e.g. `define('APP_TESTING', true)` early and, if necessary, add `if (defined('APP_TESTING')) { ... skip ... }` guards to config.php — minimal, clearly-commented). `$_SESSION` must exist as an array for classes that read `$_SESSION['user_id']`: `if (session_status() !== PHP_SESSION_ACTIVE) { $_SESSION = $_SESSION ?? []; }`.

- [ ] **Step 2: Write `tests/TestCase.php` and `tests/fixtures.php`**

```php
<?php // tests/TestCase.php
class TestCase {
    public int $failures = 0; public int $assertions = 0;
    public function assertSame($exp, $act, $msg = '') {
        $this->assertions++;
        if ($exp !== $act) { $this->failures++; fwrite(STDERR, "  FAIL {$msg}: expected " . var_export($exp, true) . " got " . var_export($act, true) . "\n"); }
    }
    public function assertEqualsMoney($exp, $act, $msg = '') {
        $this->assertions++;
        if (abs((float)$exp - (float)$act) > 0.009) { $this->failures++; fwrite(STDERR, "  FAIL {$msg}: expected {$exp} got {$act}\n"); }
    }
    public function assertTrue($cond, $msg = '') { $this->assertSame(true, (bool)$cond, $msg); }
    public function assertThrows(callable $fn, $msg = '') {
        $this->assertions++;
        try { $fn(); $this->failures++; fwrite(STDERR, "  FAIL {$msg}: no exception thrown\n"); }
        catch (Throwable $e) { /* pass */ }
    }
}
```

```php
<?php // tests/fixtures.php — minimal inserts; look up real NOT NULL columns per table in database/schema.sql and supply them
function fx_db() { return Database::getInstance(); }
function fx_user($role = 'doctor') {
    static $n = 0; $n++;
    return (int) fx_db()->insert('users', [
        'username' => "t_{$role}_{$n}_" . uniqid(), 'password' => password_hash('x', PASSWORD_DEFAULT),
        'full_name' => "Test {$role} {$n}", 'role' => $role, 'is_active' => 1,
    ]);
}
function fx_patient() {
    static $n = 0; $n++;
    return (int) fx_db()->insert('patients', ['full_name' => "Test Patient {$n}", 'phone' => '0750' . rand(1000000, 9999999)]);
}
// fx_folder / fx_case / fx_treatment / fx_invoice: copy required columns from schema.sql definitions of
// patient_folders, cases, case_treatments, case_invoices; keep the signatures from the Interfaces block.
```

(Fill in the four remaining fixture functions against the real schema — every NOT NULL column without a default gets an explicit value. `fx_invoice` inserts directly into `case_invoices` with the given `status`/`accounting_status`, `invoice_seq`=next per case, `invoice_no` = `'T' . $caseId . '-' . $seq`, `work_date` = today.)

- [ ] **Step 3: Write `tests/run.php` and the smoke test**

```php
<?php // tests/run.php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/fixtures.php';
$t = new TestCase(); $files = glob(__DIR__ . '/cases/*Test.php'); sort($files);
foreach ($files as $f) { echo basename($f), "\n"; require $f; }   // each file uses $t
echo "\n{$t->assertions} assertions, {$t->failures} failures\n";
exit($t->failures ? 1 : 0);
```

```php
<?php // tests/cases/SmokeTest.php
$t->assertTrue(Database::getInstance()->tableExists('case_invoices'), 'schema loaded');
$t->assertTrue(Database::getInstance()->tableExists('payment_allocations'), 'migrations applied');
$uid = fx_user('doctor');
$t->assertTrue($uid > 0, 'fixture user inserted');
$pid = fx_patient(); $fid = fx_folder($pid, $uid); $cid = fx_case($fid);
$t->assertTrue($cid > 0, 'fixture chain patient->folder->case');
```

- [ ] **Step 4: Run and fix until green**

Run: `C:\xampp\php\php.exe tests/run.php` (from `c:\xampp\htdocs\dentHisab`; plain `php` if on PATH)
Expected: `... 0 failures`, exit 0. Iterate on fixture columns/config guards until green. Also confirm the real app still works: `storage/migrations.lock` must be untouched (tests use `tests/storage/`).

---

### Task 2: Schema migrations

**Files:**
- Create: `database/migrations/2026_08_26_installment_pending_status.sql`
- Create: `database/migrations/2026_08_26_invoice_payment_intent.sql`
- Create: `database/migrations/2026_08_26_patient_financial_summary_view.sql`
- Create: `tests/cases/MigrationTest.php`
- Move: `accountant/update_xray_full_workflow.sql` → `database/migrations/update_xray_full_workflow.sql` **only if** its statements are already applied on live (they are — it's the existing x-ray workflow). Actually: do NOT move it into migrations (it would re-run); move it to `database/archive/update_xray_full_workflow.sql` (create dir) so it leaves the web root without entering the runner's glob.

**Interfaces:**
- Produces: columns `installment_plans.status` incl. `'pending','rejected'`; `installment_plans.proposed_down_payment DECIMAL(15,2) NULL`, `approved_by INT NULL`, `approved_at DATETIME NULL`, `reject_reason VARCHAR(500) NULL`; `case_invoices.payment_intent ENUM('cash','debt','installment') NULL`, `promised_date DATE NULL`, `promised_amount DECIMAL(15,2) NULL`; view `v_patient_financial_summary(patient_id, total_invoiced, total_discount, approved_amount, paid_amount, case_balance)`.

- [ ] **Step 1: Write `tests/cases/MigrationTest.php` (failing first)**

```php
<?php
$db = Database::getInstance();
$col = fn($table, $name) => (bool) $db->fetch(
    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
    [$table, $name]);
$t->assertTrue($col('installment_plans', 'proposed_down_payment'), 'plans.proposed_down_payment');
$t->assertTrue($col('installment_plans', 'approved_at'), 'plans.approved_at');
$t->assertTrue($col('case_invoices', 'payment_intent'), 'invoices.payment_intent');
$t->assertTrue($col('case_invoices', 'promised_date'), 'invoices.promised_date');
$statusType = $db->fetch("SELECT COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='installment_plans' AND COLUMN_NAME='status'");
$t->assertTrue(str_contains($statusType['ct'], 'pending') && str_contains($statusType['ct'], 'rejected'), 'plan status enum extended');
$t->assertTrue((bool) $db->fetch("SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v_patient_financial_summary'"), 'patient view exists');
```

Run: `php tests/run.php` → these assertions FAIL (columns absent).

- [ ] **Step 2: Write the three migration files**

`database/migrations/2026_08_26_installment_pending_status.sql`:
```sql
-- Doctor installment proposals: pending until accountant activates (spec §4.1)
ALTER TABLE installment_plans MODIFY COLUMN status ENUM('pending','active','completed','cancelled','rejected') NOT NULL DEFAULT 'active';
ALTER TABLE installment_plans ADD COLUMN proposed_down_payment DECIMAL(15,2) NULL AFTER down_payment;
ALTER TABLE installment_plans ADD COLUMN approved_by INT NULL AFTER created_by;
ALTER TABLE installment_plans ADD COLUMN approved_at DATETIME NULL AFTER approved_by;
ALTER TABLE installment_plans ADD COLUMN reject_reason VARCHAR(500) NULL AFTER approved_at;
```
**Before writing:** check `installment_plans`'s real current enum + column order in `database/schema.sql` / live `SHOW CREATE TABLE`, keep every existing enum value, and anchor `AFTER` clauses to columns that actually exist (drop the `AFTER` clause if unsure — order is cosmetic). If `created_by` doesn't exist, anchor after `status`.

`database/migrations/2026_08_26_invoice_payment_intent.sql`:
```sql
-- Doctor-declared intent at send: cash now / debt with promise / installment (spec §4.2)
ALTER TABLE case_invoices ADD COLUMN payment_intent ENUM('cash','debt','installment') NULL AFTER accounting_notes;
ALTER TABLE case_invoices ADD COLUMN promised_date DATE NULL AFTER payment_intent;
ALTER TABLE case_invoices ADD COLUMN promised_amount DECIMAL(15,2) NULL AFTER promised_date;
```

`database/migrations/2026_08_26_patient_financial_summary_view.sql` — model it exactly on the existing `database/migrations/2026_08_25_case_financial_summary_view.sql` (read it first; reuse its join/aggregation shape and its `CREATE OR REPLACE VIEW` style):
```sql
CREATE OR REPLACE VIEW v_patient_financial_summary AS
SELECT p.id AS patient_id,
       COALESCE(SUM(s.total_invoiced), 0) AS total_invoiced,
       COALESCE(SUM(s.total_discount), 0) AS total_discount,
       COALESCE(SUM(s.approved_amount), 0) AS approved_amount,
       COALESCE(SUM(s.paid_amount), 0) AS paid_amount,
       COALESCE(SUM(s.balance), 0) AS case_balance
FROM patients p
LEFT JOIN patient_folders pf ON pf.patient_id = p.id
LEFT JOIN cases c ON c.folder_id = pf.id
LEFT JOIN v_case_financial_summary s ON s.case_id = c.id
GROUP BY p.id;
```
(Verify the real FK column names `patient_folders.patient_id` / `cases.folder_id` and `v_case_financial_summary`'s exact column names against the 2026_08_25 view file — match them exactly. X-ray outstanding and credit are deliberately NOT in the view; `PatientFinance` composes them in PHP.)

- [ ] **Step 3: Run tests**

Run: `php tests/run.php` (fresh run rebuilds scratch DB → applies new migrations)
Expected: MigrationTest PASSES, SmokeTest still green.

- [ ] **Step 4: Move the stray SQL out of the web root**

Create `database/archive/`, move `accountant/update_xray_full_workflow.sql` there. Verify `accountant/` has no remaining `.sql` files.

---

### Task 3: Pure bug fixes (no behavior redesign)

**Files:**
- Modify: `classes/AccountingLedger.php:443-470` (`getActivity`)
- Modify: `classes/CaseManager.php:446-497` (`updateCaseTotals`)
- Modify: `accountant/installments.php:69-72, 224`
- Modify: `doctors/report-pdf.php:53`
- Modify: `classes/Installment.php:974-1062` (delete dead `autoAllocatePayment`)
- Create: `tests/cases/BugfixTest.php`

**Interfaces:**
- Consumes: Task 1 fixtures.
- Produces: `AccountingLedger::getActivity($caseId,$limit)` now includes `case_treatments` activity rows; `CaseManager::updateCaseTotals($caseId)` excludes cancelled treatments and caps `paid` at approved allocations (mirror semantics, spec §3).

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/BugfixTest.php
$db = Database::getInstance();
require_once __DIR__ . '/../../classes/AuditLog.php';      // whatever AccountingLedger requires — mirror the requires used by accountant/report-detail.php
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/CaseManager.php';

// --- getActivity includes treatment rows ---
$doc = fx_user('doctor'); $pat = fx_patient(); $fold = fx_folder($pat, $doc); $case = fx_case($fold);
$tr = fx_treatment($case, 50000);
$db->insert('activity_log', ['table_name' => 'case_treatments', 'record_id' => $tr, 'action' => 'insert',
    'user_id' => $doc, 'created_at' => date('Y-m-d H:i:s')]);   // match activity_log's real columns (see AuditLog::log)
$ledger = new AccountingLedger($db);
$acts = $ledger->getActivity($case, 50);
$found = false; foreach ($acts as $a) { if (($a['table_name'] ?? '') === 'case_treatments') $found = true; }
$t->assertTrue($found, 'getActivity returns case_treatments rows');

// --- updateCaseTotals excludes cancelled treatments ---
$case2 = fx_case($fold);
fx_treatment($case2, 100000);
$cancelled = fx_treatment($case2, 40000);
$db->update('case_treatments', ['status' => 'cancelled'], 'id = ?', [$cancelled]);
$cm = new CaseManager($db);
$cm->updateCaseTotals($case2);
$row = $db->fetch("SELECT total_amount FROM cases WHERE id = ?", [$case2]);
$t->assertEqualsMoney(100000, $row['total_amount'], 'cancelled treatment excluded from case total');
```

(Adapt constructor calls to the real signatures — open each class's `__construct`. If `AccountingLedger`'s constructor takes no args or uses `Database::getInstance()` internally, call it that way. Same for `CaseManager`. Match `activity_log`'s real column names from `classes/AuditLog.php::log()`.)

Run: `php tests/run.php` → the `getActivity` assertion FAILS (bug drops the clause); the totals assertion FAILS (no cancelled filter).

- [ ] **Step 2: Fix `AccountingLedger::getActivity`**

At `classes/AccountingLedger.php:443-470`: the treatment-id clause is appended to `$parts` *before* `$parts` is re-initialized at :458, discarding it. Reorder — initialize `$parts` with the base `cases` clause FIRST, then append invoice/payment/treatment clauses:

```php
$parts = ["(al.table_name = 'cases' AND al.record_id = " . (int)$caseId . ")"];
if ($invoiceIds)   { $parts[] = "(al.table_name = 'case_invoices' AND al.record_id IN (" . implode(',', $invoiceIds) . "))"; }
if ($paymentIds)   { $parts[] = "(al.table_name = 'payments' AND al.record_id IN (" . implode(',', $paymentIds) . "))"; }
if ($treatmentIds) { $parts[] = "(al.table_name = 'case_treatments' AND al.record_id IN (" . implode(',', $treatmentIds) . "))"; }
```
Keep the existing id-collection queries above untouched; only fix the ordering.

- [ ] **Step 3: Fix `CaseManager::updateCaseTotals`**

At `classes/CaseManager.php:454-468`: add `AND status <> 'cancelled'` to the `case_treatments` SUM, and change the `paid` source to ledger-derived (mirror semantics per spec §3): `paid = SUM of approved payments' allocations for this case's invoices, plus approved payments with no allocations (legacy unallocated)`. Concretely replace the raw `SUM(payments.amount)` with:

```php
$paidRow = $this->db->fetch(
    "SELECT COALESCE(SUM(pa.allocated_amount), 0) AS allocated
       FROM payment_allocations pa
       JOIN payments p ON p.id = pa.payment_id AND p.status = 'approved'
       JOIN case_invoices ci ON ci.id = pa.invoice_id
      WHERE ci.case_id = ?", [$caseId]);
$unallocRow = $this->db->fetch(
    "SELECT COALESCE(SUM(p.amount), 0) AS amt FROM payments p
      WHERE p.case_id = ? AND p.status = 'approved'
        AND NOT EXISTS (SELECT 1 FROM payment_allocations pa2 WHERE pa2.payment_id = p.id)", [$caseId]);
$paid = (float)$paidRow['allocated'] + (float)$unallocRow['amt'];
```

- [ ] **Step 4: Fix the two display bugs + delete dead code**

- `accountant/installments.php:224`: `$plan['total_amount'] - $plan['paid_amount']` reads a non-existent column. Replace the remaining/paid figures with `Installment::getPlanSummary($plan['id'])` values (require the class at top if absent; call once per row, it's a small page). Use the summary's `remaining` for the "ماوە" cell and its paid total for the paid cell.
- `doctors/report-pdf.php:53`: change `pay.status = 'completed'` → `pay.status = 'approved'`.
- `classes/Installment.php:974-1062`: delete `autoAllocatePayment()` entirely (zero callers — verified). Leave a one-line comment: `// autoAllocatePayment removed 2026-08-26: dead code (no callers).`

- [ ] **Step 5: Run tests**

Run: `php tests/run.php` → all green. Load `accountant/installments.php` and a doctor report PDF locally if the local Apache (:8080) is running — no fatals.

---

### Task 4: Payment single-writer — reroute `DebtPayment` and remove `closeWithCredit`

**Files:**
- Modify: `classes/DebtPayment.php:314-340` (`applyCasePayment`), `:345-383` (`applyXrayPayment`)
- Modify: `classes/CaseManager.php:141-203` (`close` / `closeWithCredit`)
- Create: `tests/cases/SingleWriterTest.php`

**Interfaces:**
- Consumes: `Payment::directPayment($data,$userId)` — `$data` keys: `case_id`, `amount`, `payment_type` (`cash|credit|installment`), `payment_method` (`cash|card|transfer|other`), `notes`, optional `allocations` (invoice_id⇒amount map) — read `accountant/report-detail.php:106-133` for a working call.
- Produces: `DebtPayment::applyCasePayment(...)` returns the new payment id and ALWAYS produces `payment_allocations` rows via the ledger; `CaseManager::close($id,$comment)` closes without fabricating a payment.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/SingleWriterTest.php
// Build: case with one APPROVED invoice net 100000 (fx_invoice(..., 'sent', 'approved', 100000) + a treatment stamped to it)
// Then call DebtPayment::applyCasePayment (mirror its internal call signature — read the class first) for 60000.
$pay = $db->fetch("SELECT * FROM payments WHERE case_id = ? ORDER BY id DESC LIMIT 1", [$case]);
$t->assertSame('approved', $pay['status'], 'debt payment booked approved');
$t->assertTrue(!empty($pay['payment_number']), 'payment_number generated (Payment class path)');
$alloc = $db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$pay['id']]);
$t->assertEqualsMoney(60000, $alloc['s'], 'allocations written');
$t->assertTrue(in_array($pay['payment_type'], ['cash','credit','installment'], true), 'payment_type within enum');

// closeWithCredit removed: close a case with remaining balance, assert NO new pending payment row appears
$before = (int)$db->fetch("SELECT COUNT(*) c FROM payments WHERE case_id = ?", [$case2])['c'];
$cm->close($case2, 'test close');
$after = (int)$db->fetch("SELECT COUNT(*) c FROM payments WHERE case_id = ?", [$case2])['c'];
$t->assertSame($before, $after, 'close() fabricates no payment');
$t->assertSame('closed', $db->fetch("SELECT status FROM cases WHERE id = ?", [$case2])['status'], 'case closed');
```

Run → FAIL (old path: no allocations / no payment_number; close creates a pending credit payment).

- [ ] **Step 2: Reroute `applyCasePayment` through `Payment::directPayment`**

Replace the hand-built insert at `classes/DebtPayment.php:314-340` with:

```php
require_once __DIR__ . '/Payment.php';
$payment = new Payment($this->db);            // match Payment's real constructor
$method = in_array($paymentMethod, ['cash','card','transfer','other'], true) ? $paymentMethod : 'other';
$paymentId = $payment->directPayment([
    'case_id'        => $caseId,
    'amount'         => $amount,
    'payment_type'   => 'cash',               // booked collection of existing debt
    'payment_method' => $method,
    'notes'          => $note,                // keep the existing note text this method built
], $userId);
return $paymentId;
```

Delete the `'approved_at'` and bogus `'payment_type' => $paymentMethod` lines with the old insert. `directPayment` handles allocations (FIFO via ledger when no explicit map), `updateCaseTotals`, `syncStatuses`, audit, notification. Verify `directPayment`'s return shape at `classes/Payment.php:314-390` (it may return an id or an array — adapt the return). Keep this call inside the caller's existing transaction (`distributePayment` at :182 already wraps — Database nests SAVEPOINTs safely).

- [ ] **Step 3: Fix `applyXrayPayment` (stop mutating `final_amount`)**

At `classes/DebtPayment.php:345-383`: remove the `UPDATE xray_requests SET final_amount = final_amount - ...` (lines ~365-367). Instead:
1. Ensure a `patient_xray_debts` row exists for the request (`SELECT` by request id — check the real linking column in the table; create one with `amount` = current outstanding if missing, mirroring `XrayManager::processDebtPayment`'s insert at `classes/XrayManager.php:1251-1304`).
2. Insert an `xray_debt_payments` row (`debt_id`, `amount`, `payment_method`, `received_by`).
3. Update the debt row: `remaining_amount = remaining_amount - $amount`, `status = remaining <= 0.009 ? 'paid' : 'partial'`.
4. When remaining hits 0: set `xray_requests.payment_status = 'paid'` **via `XrayManager`** — add a tiny public method `XrayManager::markDebtSettled($requestId, $userId)` that runs the update + `logPaymentAction($requestId, 'debt_paid', ...)` + `logHistory`. Do not touch `request_status`.
5. Change the log action `'debt_payment'` → `'debt_paid'` (valid enum member).

- [ ] **Step 4: Remove `closeWithCredit`**

`classes/CaseManager.php:141-203`: in `close()`, delete the `remaining > 0` branch that calls `closeWithCredit`, and delete `closeWithCredit()` itself. `close()` now just validates, sets `status='closed'` + `closed_by` + `closing_comment`, audits. Remaining balance stays as derived debt (spec §2.1). Search for other `closeWithCredit` callers first: `Grep closeWithCredit` — if any page calls it directly, update it to `close()`.

- [ ] **Step 5: Run tests**

Run: `php tests/run.php` → green.

---

### Task 5: X-ray canonical status readers + retire the divergent settlement path

**Files:**
- Modify: `classes/XrayManager.php` (add helpers; adjust `getOutstandingDebt`; gut `processCashPayment`/`processDebtPayment` callers)
- Modify: `xray/payments.php:30-68`
- Modify: `classes/Patient.php` (`:139-140, :170-173, :421-423` x-ray debt fragments), `classes/DebtCollection.php:108-110`, `classes/DebtPayment.php` (x-ray item filters `:46-101`), `debts.php:77-89`, `api/patient-debt.php:37-56`, `api/export-debts.php:35`
- Create: `tests/cases/XrayDebtTest.php`

**Interfaces:**
- Produces: `XrayManager::DEBT_STATUSES = ['debt','debt_approved']` (public const — receivable statuses; `debt_pending` is a pending request, NOT debt), `XrayManager::debtStatusSqlList(): string` returning `"'debt','debt_approved'"` for safe interpolation, and `XrayManager::markDebtSettled($requestId,$userId)` (from Task 4).
- Consumes: nothing new.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/XrayDebtTest.php
// Insert three completed xray_requests for one patient: payment_status 'debt' (30000), 'debt_approved' (20000), 'debt_pending' (99999)
// (set final_amount, status='completed', request_status='completed' — copy required columns from schema)
$xm = new XrayManager($db);   // real constructor
$t->assertEqualsMoney(50000, $xm->getPatientXrayDebt($pat), 'debt + debt_approved counted, debt_pending excluded');
$t->assertEqualsMoney(50000, $xm->getOutstandingDebt(), 'outstanding excludes debt_pending');   // adapt args if it takes a range
```

Run → the `getOutstandingDebt` assertion FAILS (currently includes `debt_pending`); `getPatientXrayDebt` may already pass (it uses the 3-value list) — after the helper change it must still pass with the new 2-value list, so the fixture's `debt_pending` row is the tripwire.

- [ ] **Step 2: Add the canonical helpers and adjust `XrayManager`**

Top of `classes/XrayManager.php`:
```php
/** Receivable x-ray debt statuses. 'debt_pending' = awaiting accountant approval — a pending request, not debt (spec §3). */
public const DEBT_STATUSES = ['debt', 'debt_approved'];
public static function debtStatusSqlList(): string {
    return "'" . implode("','", self::DEBT_STATUSES) . "'";
}
```
Update `getOutstandingDebt` (:111-131), `getPatientXrayDebt` (:648), `getPatientXrayPaid`/doctor variants, and `getIncome`-adjacent debt queries to use `payment_status IN (" . self::debtStatusSqlList() . ")` — removing `debt_pending` where present.

- [ ] **Step 3: Repoint every stale `payment_status='debt'` reader**

In each listed file, replace the literal `payment_status = 'debt'` (and any hand-rolled IN lists) inside x-ray debt expressions with `payment_status IN (' . XrayManager::debtStatusSqlList() . ')` (require the class where missing). Files + lines (re-verify each): `classes/Patient.php:139-140, 170-173, 421-423` (+ any sibling occurrences — grep the file for `payment_status`), `classes/DebtCollection.php:108-110`, `classes/DebtPayment.php:46-101` (`getPatientDebtItems` x-ray branch), `debts.php:77-89`, `api/patient-debt.php:37-56` (both scoped and unscoped queries), `api/export-debts.php:35`. Run `Grep "payment_status\s*=\s*'debt'"` over the repo afterward — zero remaining hits outside `XrayManager` itself and historical migrations.

- [ ] **Step 4: Retire the divergent settlement actions**

`xray/payments.php:30-68`: remove the `process_cash`, `process_debt`, `apply_discount`, and `set_price` POST branches (settlement now happens only in the Approval Center; pricing stays in `admin/xray-pricing.php`). Keep the page's history listing and the existing `pay_debt` collection flow (`XrayManager::payDebt`) — that collects previously-approved debts, which is legitimate. Remove now-unused UI (buttons/modals) for the removed actions. In `classes/XrayManager.php`, mark `processCashPayment` (:1205) and `processDebtPayment` (:1251) `@deprecated — replaced by Approval Center transitionWorkflow path 2026-08-26` and make their first line `throw new RuntimeException('deprecated: settle via approval center');` (any missed caller fails loudly instead of corrupting statuses). Grep for callers first and clean them.

- [ ] **Step 5: Run tests**

Run: `php tests/run.php` → green. Manual: open `debts.php` locally — a patient with a `debt_approved` x-ray now shows that debt.

---

### Task 6: Installment lifecycle — pending plans

**Files:**
- Modify: `classes/Installment.php`
- Create: `tests/cases/InstallmentPendingTest.php`

**Interfaces:**
- Produces:
  - `Installment::proposePlan(array $data, int $doctorUserId): int` — inserts plan `status='pending'`, stores `proposed_down_payment`, builds NO schedule, moves NO money; `$data` = same keys as `createPlan` (read `:20-120`) + `invoice_id`.
  - `Installment::activatePlan(int $planId, array $edits, int $accountantUserId): array` — pending→active with optional term edits (`total_amount`,`number_of_installments`,`installment_amount`,`start_date`,`down_payment`), builds the schedule, books the down payment via `Payment::directPayment`, stamps `approved_by/approved_at`. Optimistic guard on `status='pending'`.
  - `Installment::rejectPlan(int $planId, string $reason, int $userId): bool` — pending→rejected, reason required.
  - Existing `createPlan` unchanged for accountant/admin direct creation.
- Consumes: `Payment::directPayment` (Task 4 semantics), `AuditLog`.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/InstallmentPendingTest.php
$inst = new Installment($db);   // real constructor
$planId = $inst->proposePlan(['case_id' => $case, 'invoice_id' => $inv, 'total_amount' => 300000,
    'number_of_installments' => 6, 'start_date' => date('Y-m-d'), 'down_payment' => 50000], $doc);
$plan = $db->fetch("SELECT * FROM installment_plans WHERE id = ?", [$planId]);
$t->assertSame('pending', $plan['status'], 'proposal is pending');
$t->assertEqualsMoney(50000, $plan['proposed_down_payment'], 'down payment stored as proposal only');
$t->assertSame(0, (int)$db->fetch("SELECT COUNT(*) c FROM installment_schedule WHERE plan_id = ?", [$planId])['c'], 'no schedule yet');
$t->assertSame(0, (int)$db->fetch("SELECT COUNT(*) c FROM payments WHERE case_id = ?", [$case])['c'], 'no money moved');

// pending plans count nowhere
$t->assertSame(0, count(array_filter($inst->getActivePlans(), fn($p) => (int)$p['id'] === $planId)), 'pending plan not in active list');

// activate with an edit
$inst->activatePlan($planId, ['number_of_installments' => 5], $acct);
$plan = $db->fetch("SELECT * FROM installment_plans WHERE id = ?", [$planId]);
$t->assertSame('active', $plan['status'], 'activated');
$t->assertSame(5, (int)$db->fetch("SELECT COUNT(*) c FROM installment_schedule WHERE plan_id = ?", [$planId])['c'], 'schedule built on activation');
$t->assertTrue((int)$plan['approved_by'] === $acct, 'approved_by stamped');

// double-activate blocked; reject requires reason
$t->assertThrows(fn() => $inst->activatePlan($planId, [], $acct), 'double activation blocked');
$t->assertThrows(fn() => $inst->rejectPlan(fx_pending_plan($case2, $doc), '', $acct), 'empty reason rejected');
```

(Add a tiny local helper `fx_pending_plan` in this file using `proposePlan`. Down-payment booking assertion: after activation of a plan WITH `proposed_down_payment` and an approved invoice, assert one approved `payments` row of that amount exists.)

Run → FAIL (methods don't exist).

- [ ] **Step 2: Implement the three methods**

Structure `proposePlan` as `createPlan`'s validation (reuse: one active-or-pending plan per case → change the existing guard at `:31-34` to `status IN ('active','pending')`; grep other `status = 'active'` checks in the class and extend the uniqueness one only), then a single `insert` with `status='pending'`, `proposed_down_payment`, `down_payment=0`, no schedule, no `Payment` call. Audit via `AuditLog` (`table_name='installment_plans'`). Notify via `Notification::notifyInstallmentPlan` (existing wrapper; Task 8 adjusts wording).

`activatePlan`: transaction → `SELECT * ... WHERE id=? FOR UPDATE` via `$this->db->fetch("SELECT * FROM installment_plans WHERE id = ? FOR UPDATE", [$planId])`; verify `status==='pending'` else throw; apply `$edits` (whitelist the five keys; recompute `installment_amount = ceil((total - down)/n)` the same way `createPlan` does — copy its formula); `update` with optimistic guard `"id = ? AND status = 'pending'"` and throw if 0 rows affected; build the schedule by extracting `createPlan`'s schedule-building loop (`:76-83`) into a private `buildSchedule($planId,$total,$down,$n,$startDate)` used by both; if down payment > 0 → `Payment::directPayment` (type `'installment'`) and roll back on failure exactly like `createPlan:89-104`; stamp `approved_by`/`approved_at`; audit.

`rejectPlan`: `trim($reason) !== ''` else `InvalidArgumentException`; optimistic `update ... "id = ? AND status = 'pending'"` → `status='rejected'`, `reject_reason`; audit; notify doctor via `notifyPaymentResult`-style wrapper (Task 8 adds `notifyInstallmentDecision`— until then use `Notification::notifyInstallmentPlan` with rejected wording; leave a `// Task 8 swaps wrapper` comment).

- [ ] **Step 3: Exclude pending plans everywhere active plans are summed**

Grep `installment_plans` across the repo for status filters. Verify these keep `status='active'` semantics (they already filter `active`, so pending is naturally excluded — just confirm): `Installment::getActivePlans:369`, `getStatistics:932`, `accountant/installments.php:81`, `DebtCollection` installment fragments `:119-131`. Anywhere a query counts plans WITHOUT a status filter (grep hits will show), add `AND status IN ('active','completed')` if it feeds a balance, or leave it if it's a pure listing that should show pending (mark each decision with a one-line comment).

- [ ] **Step 4: Unify `payInstallment` onto `installment_payments`**

`classes/Installment.php:173-216`: rewrite `payInstallment($scheduleId,$amount,$userId)` to: load the schedule row → its plan → delegate to `makeFlexiblePayment($planId, ['amount'=>$amount, 'payment_method'=>'cash', 'note'=>'قیستی ژمارە ' . $row['installment_number']], $userId)`. Delete the direct `installment_schedule.paid_amount` arithmetic. Grep `payInstallment` callers (`installments.php:35-41` admin action is one) and confirm the signature still fits.

- [ ] **Step 5: Run tests**

Run: `php tests/run.php` → green.

---

### Task 7: `Payment::updatePendingAmount` + duplicate-approval guards

**Files:**
- Modify: `classes/Payment.php`
- Create: `tests/cases/PaymentGuardTest.php`

**Interfaces:**
- Produces: `Payment::updatePendingAmount(int $paymentId, float $newAmount, int $actorId, string $note = ''): bool` — only while `status='pending'`; re-derives reserved allocations via `AccountingLedger::autoAllocate`/`replacePaymentAllocations`; audits old→new.
- Consumes: `AccountingLedger::autoAllocate`, `replacePaymentAllocations`, `validateAllocations` (read `classes/Payment.php:186-229` `createRequest` — reuse its allocation-preparation path, likely `prepareAllocations` at `:62`).

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/PaymentGuardTest.php
// Setup: approved invoice net 100000, doctor request for 80000 via Payment::createRequest
$p = new Payment($db);
$reqId = $p->createRequest(['case_id' => $case, 'amount' => 80000, 'payment_type' => 'cash', 'payment_method' => 'cash', 'received_by' => $doc]);
// adapt: createRequest may return array; extract the id. Read :186-229.
$t->assertTrue($p->updatePendingAmount($reqId, 60000, $acct, 'گۆڕینی بڕ'), 'amount adjusted while pending');
$t->assertEqualsMoney(60000, $db->fetch("SELECT amount FROM payments WHERE id = ?", [$reqId])['amount'], 'amount stored');
$t->assertEqualsMoney(60000, $db->fetch("SELECT COALESCE(SUM(allocated_amount),0) s FROM payment_allocations WHERE payment_id = ?", [$reqId])['s'], 'reservations follow the new amount');
$t->assertThrows(fn() => $p->updatePendingAmount($reqId, 150000, $acct), 'cannot exceed remaining balance');

$p->approve($reqId, $acct);
$t->assertThrows(fn() => $p->updatePendingAmount($reqId, 50000, $acct), 'cannot edit after approval');
// double approval blocked
$t->assertThrows(fn() => $p->approve($reqId, $acct), 'double approve blocked');
// reject requires reason? (match existing behavior at :293 — if reason optional there, enforce non-empty NOW per spec §12)
$req2 = /* second pending request */;
$t->assertThrows(fn() => $p->reject($req2, ''), 'reject requires reason');
```

(If `approve` currently returns `false` rather than throwing on a non-pending row, assert `false === $p->approve(...)` instead — match the class's error style, but the second approval must be a no-op with NO second allocation write; assert allocation sum unchanged.)

Run → FAIL.

- [ ] **Step 2: Implement `updatePendingAmount`**

```php
public function updatePendingAmount($paymentId, $newAmount, $actorId, $note = '')
{
    $paymentId = (int)$paymentId; $newAmount = round((float)$newAmount, 2);
    if ($newAmount <= 0) { throw new InvalidArgumentException('بڕ نادروستە'); }
    $this->db->beginTransaction();
    try {
        $row = $this->db->fetch("SELECT * FROM payments WHERE id = ? FOR UPDATE", [$paymentId]);
        if (!$row || $row['status'] !== 'pending') { throw new RuntimeException('تەنها داواکاری چاوەڕوان دەگۆڕدرێت'); }
        $balance = $this->getCaseBalance((int)$row['case_id'], $paymentId);   // excludes this request's own reservation
        if ($newAmount - 0.009 > (float)$balance['remaining']) { throw new RuntimeException('بڕ لە ماوە زیاترە'); }
        $alloc = $this->ledger->autoAllocate((int)$row['case_id'], $newAmount, /* mirror createRequest's args */);
        $this->ledger->replacePaymentAllocations($paymentId, $alloc, (int)$actorId);   // mirror approve()'s usage at :232-291
        $this->db->update('payments', ['amount' => $newAmount], 'id = ? AND status = ?', [$paymentId, 'pending']);
        // AuditLog: old amount → new amount, actor, note
        $this->db->commit();
        return true;
    } catch (Throwable $e) { $this->db->rollback(); throw $e; }
}
```
Mirror the exact `autoAllocate`/`replacePaymentAllocations` call shapes used in `createRequest` (:186-229) and `approve` (:232-291) — copy their argument lists. Use the class's existing audit call style. Enforce non-empty reason in `reject` (:293) with the same exception style.

- [ ] **Step 3: Run tests**

Run: `php tests/run.php` → green.

---

### Task 8: Notification wrappers

**Files:**
- Modify: `classes/Notification.php`
- Modify: `classes/XrayManager.php:974-1078` (raw insert methods)
- Create: `tests/cases/NotificationTest.php`

**Interfaces:**
- Produces:
  - `Notification::notifyInvoiceSent(int $caseId, string $invoiceNo, int $sentByUserId): void` — type `invoice_sent` (add const `TYPE_INVOICE_SENT = 'invoice_sent'`), recipients: all active accountants + admins, link `/accountant/approval-center.php?tab=invoices&q=` . urlencode($invoiceNo).
  - `Notification::notifyInstallmentDecision(int $planId, string $decision /* activated|rejected */, int $doctorUserId, string $reason = ''): void` — notifies the proposing doctor.
  - `notifyPaymentRequested` (:449-460) retargeted: recipients accountants + admins, link `/accountant/approval-center.php?tab=payments`.
- Consumes: existing recipient-resolution helpers inside `Notification` (see how `notifyCaseCompleted:288-319` finds accountant+admin user ids — reuse that exact mechanism).

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/NotificationTest.php
$n = new Notification($db);   // real constructor
$acct2 = fx_user('accountant'); $admin = fx_user('admin');
$n->notifyInvoiceSent($case, 'T1-1', $doc);
$rows = $db->fetchAll("SELECT * FROM notifications WHERE type = 'invoice_sent'");
$t->assertTrue(count($rows) >= 2, 'accountant + admin notified');
$t->assertTrue(str_contains($rows[0]['link'] ?? $rows[0]['url'] ?? '', 'approval-center'), 'links to approval center');
```
(Match the `notifications` table's real column names — read an existing wrapper.) Run → FAIL.

- [ ] **Step 2: Implement wrappers + retarget**

Copy `notifyCaseCompleted`'s body shape for recipient lookup and creation. Kurdish copy: invoice sent → title `پسووڵەی نوێ`, message `پسووڵەی {invoiceNo} نێردرا بۆ ژمێریاری`. Installment decision: activated → `قیستەکە پەسەندکرا`; rejected → `قیستەکە ڕەتکرایەوە: {reason}`. Retarget `notifyPaymentRequested`'s recipient query from admins-only to accountants+admins and change its link. Swap the Task 6 placeholder call in `Installment::rejectPlan`/`activatePlan` to `notifyInstallmentDecision`.

- [ ] **Step 3: Route `XrayManager` notifications through wrappers**

Add `Notification::notifyXrayWorkflow(int $requestId, string $stage /* to_accountant|to_xray|to_doctor|to_scan */, array $ctx): void` implementing the same recipient routing `XrayManager::notifyXrayDepartment` (:974-1026), `notifyDoctor` (:1031-1049), `notifyXrayDepartmentToScan` (:1055-1078) do today (same roles, same links — except accountant links now point to `/accountant/approval-center.php?tab=xray&q=...`). Replace the three raw-insert methods' bodies with delegation to the wrapper (keep the method names so `transitionWorkflow` callers don't change).

- [ ] **Step 4: Run tests**

Run: `php tests/run.php` → green.

---

### Task 9: `ApprovalQueue` service

**Files:**
- Create: `classes/ApprovalQueue.php`
- Create: `tests/cases/ApprovalQueueTest.php`

**Interfaces:**
- Produces (all static-free; `new ApprovalQueue()` uses `Database::getInstance()` internally, matching other classes):
  - `counts(): array` → `['invoices'=>int,'payments'=>int,'installments'=>int,'xray'=>int,'station'=>int,'badge'=>int]` where `badge` = invoices+payments+installments+xray (station excluded — presence, not approval).
  - `list(string $tab, array $filters = []): array` — `$tab ∈ invoices|payments|installments|xray|station|history`; `$filters`: `q` (patient name/phone/number), `doctor_id`, `date_from`, `date_to`. Each row: `['kind','id','uid','patient_id','patient_name','doctor_name','number','amount','payment_type','intent','promised_date','promised_amount','created_at','status','action']` (`uid` = `"{kind}:{id}"` for the since-cursor; nulls where a field doesn't apply).
  - `newSince(string $isoDatetime): array` → `['count'=>int,'uids'=>string[]]` across the four approval queues + station.
  - `detail(string $kind, int $id): array` — full drawer payload (per-kind, see Step 3).
- Consumes: `AccountingLedger::getSummary/getInvoices/getPayments`, `Installment::getPlanSummary`, `XrayManager`, plus the row-source queries below.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/ApprovalQueueTest.php
$q = new ApprovalQueue();
// Fixture: one sent invoice, one pending payment, one pending plan, one pending_accountant xray, nothing at station
$c = $q->counts();
$t->assertSame(1, $c['invoices'], 'sent invoice counted');
$t->assertSame(1, $c['payments'], 'pending payment counted');
$t->assertSame(1, $c['installments'], 'pending plan counted');
$t->assertSame(1, $c['xray'], 'pending_accountant counted');
$t->assertSame(4, $c['badge'], 'badge = 4 approval queues');
$rows = $q->list('invoices');
$t->assertSame('invoice', $rows[0]['kind']); $t->assertTrue($rows[0]['amount'] > 0, 'net amount present');
$t->assertSame(1, count($q->list('invoices', ['q' => $patientName])), 'search filter');
$t->assertSame(0, count($q->list('invoices', ['q' => 'no-such-patient-xyz'])), 'search excludes');
// approve the invoice → queue empties, history shows it
(new CaseInvoice($db))->approve($case, $inv, $acct);   // real signature at classes/CaseInvoice.php:297
$t->assertSame(0, $q->counts()['invoices'], 'approved leaves the queue');
$t->assertTrue(count($q->list('history')) >= 1, 'history shows acted items');
$d = $q->detail('invoice', $inv);
$t->assertTrue(isset($d['invoice']) && isset($d['summary']) && isset($d['treatments']), 'drawer payload complete');
```

Run → FAIL (class missing).

- [ ] **Step 2: Implement row sources**

One private method per tab; UNION nothing — return per-tab arrays (the page renders per-tab). Base queries (adapt join/column names against the live schema — these follow `accountant/pending-reports.php:184-270`'s verified joins):

```php
// invoices: case_invoices ci JOIN cases c ON c.id=ci.case_id JOIN patient_folders pf ON pf.id=c.folder_id
//   JOIN patients p ON p.id=pf.patient_id LEFT JOIN users d ON d.id=ci.doctor_id? — VERIFY: ci.doctor_id references
//   doctors.id or users.id (check the 2026_08_23_case_invoices.sql migration + pending-reports.php's join) — copy that join.
// WHERE ci.accounting_status='sent'  → amount = GREATEST(0, ci.total_amount - ci.discount_amount),
//   intent/promise columns straight from ci, number = ci.invoice_no, action = 'پەسەندکردن / گەڕاندنەوە'
// payments: payments pay ... WHERE pay.status='pending' → number = payment_number, amount, payment_type
// installments: installment_plans ip ... WHERE ip.status='pending' → amount = total_amount, number = CONCAT('P-', ip.id)
// xray: xray_requests xr ... WHERE xr.request_status IN ('pending_accountant','returned_to_accountant')
//   → amount = final_amount, payment_type = xr.payment_type, number = CONCAT('X-', xr.id)  (copy joins from pending-reports.php:263-270)
// station: copy the waiting_room query + de-dup from api/accountant-feed.php:304-350 verbatim (it is correct), minus the dead station.php link
// history: per-source rows acted in the last 30 days:
//   invoices: accounting_status IN ('approved','returned') AND COALESCE(approved_at, returned_at) >= NOW() - INTERVAL 30 DAY
//   payments: status IN ('approved','rejected','refunded') AND updated_at >= ... (verify payments has updated_at; else use payment_date/created_at)
//   installments: status IN ('active','rejected') AND COALESCE(approved_at, updated_at) >= ...
//   xray: request_status NOT IN ('pending_accountant','returned_to_accountant') AND accountant_approved_at >= ...
```
Filters: `q` matches `p.full_name LIKE ? OR p.phone LIKE ?` OR the tab's number column `LIKE ?` (three separate `?` params — no named-placeholder reuse); `doctor_id`, `date_from/to` on the tab's created/sent timestamp. Order: oldest first within each tab (FIFO work queue), station: urgent/arrival order copied from the feed.

`counts()`: five cheap `COUNT(*)` queries with the same WHEREs. `newSince($iso)`: `created_at > ?` (or `sent_at` for invoices) per queue, return combined count + uids.

- [ ] **Step 3: Implement `detail()`**

- `invoice`: `['invoice' => AccountingLedger::getInvoice($id), 'treatments' => rows of case_treatments WHERE invoice_id=$id (id, name/description, tooth, final_price — copy the column list report-detail's invoice tab uses), 'summary' => getSummary($caseId), 'patient' => …, 'intent' => …]`.
- `payment`: payment row + its allocations (`AccountingLedger::getPayments` filtered to the id) + `getSummary`.
- `installment`: plan row + `getPlanSummary` + proposed terms + the invoice's remaining via `CaseInvoice::installmentEligibility`.
- `xray`: request row + `xray_request_history` rows (copy the feed's detail query at `api/accountant-feed.php:608+`).
- `station`: copy from `api/accountant-feed.php:551+`.
Each payload includes `patient_id` so the drawer can link to the statement.

- [ ] **Step 4: Run tests**

Run: `php tests/run.php` → green.

---

### Task 10: `PatientFinance` service

**Files:**
- Create: `classes/PatientFinance.php`
- Create: `tests/cases/PatientFinanceTest.php`

**Interfaces:**
- Produces:
  - `PatientFinance::summary(int $patientId, ?int $doctorUserId = null): array` → `['total_invoiced','total_discount','approved','paid','pending_requests','case_balance','xray_outstanding','credit','installment_remaining','net_debt']` (floats; `net_debt = max(0, case_balance + xray_outstanding - credit)`; when `$doctorUserId` given, scope to that doctor's folders/cases/x-rays — reuse the ownership predicates from `Patient::getStatisticsForDoctor:866-880` and `XrayManager::getPatientXrayDebtForDoctor:621`).
  - `PatientFinance::statement(int $patientId, ?int $doctorUserId = null): array` → `['summary'=>…, 'cases'=>[ per case: ['case'=>row,'invoices'=>decorated rows,'payments'=>rows+allocations,'installment'=>plan+schedule|null,'summary'=>getSummary] ], 'xray'=>['requests'=>rows,'debts'=>rows,'payments'=>rows], 'credit'=>['balance'=>float,'log'=>rows], 'activity'=>merged chronological rows]`.
- Consumes: `AccountingLedger` (getInvoices/getPayments/getSummary/getActivity), `Installment::getPlanSummary/getScheduleWithDynamicStatus/getPlanByCase`, `XrayManager` helpers (Task 5), `DebtPayment::getPatientCredit:423`, view `v_patient_financial_summary`.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/PatientFinanceTest.php
// Fixture: patient with 2 cases.
// Case A: approved invoice net 100000, approved payment 60000 allocated. Case B: sent (not approved) invoice net 50000.
// Plus: xray debt_approved 20000, xray debt_pending 99999, patient credit 5000, pending doctor request 10000 on case A.
$pf = new PatientFinance();
$s = $pf->summary($pat);
$t->assertEqualsMoney(100000, $s['approved'], 'only approved invoices in receivable');
$t->assertEqualsMoney(60000,  $s['paid'], 'approved allocations only');
$t->assertEqualsMoney(40000,  $s['case_balance'], 'balance = approved - paid');
$t->assertEqualsMoney(10000,  $s['pending_requests'], 'pending request visible but not booked');
$t->assertEqualsMoney(20000,  $s['xray_outstanding'], 'debt_pending excluded');
$t->assertEqualsMoney(5000,   $s['credit'], 'credit read');
$t->assertEqualsMoney(55000,  $s['net_debt'], '40000 + 20000 - 5000');
$st = $pf->statement($pat);
$t->assertSame(2, count($st['cases']), 'both cases present');
$t->assertTrue(count($st['activity']) > 0, 'activity timeline populated');
// doctor scoping: a second doctor sees none of it
$t->assertEqualsMoney(0, $pf->summary($pat, fx_user('doctor'))['case_balance'], 'foreign doctor scoped out');
```

Run → FAIL.

- [ ] **Step 2: Implement**

`summary()`: resolve the patient's case ids (`patients → patient_folders → cases`, doctor-scoped when requested); loop `AccountingLedger::getSummary($caseId)` accumulating `approved/paid/balance/total_invoiced/total_discount`; `pending_requests` = `SELECT COALESCE(SUM(amount),0) FROM payments WHERE case_id IN (...) AND status='pending'`; x-ray via `XrayManager::getPatientXrayDebt($patientId)` (or the doctor variant); credit via `DebtPayment::getPatientCredit`. `statement()`: per case compose from the ledger getters + `Installment`; x-ray section = the patient's `xray_requests` (all payment types, chronological) + `patient_xray_debts` + `xray_debt_payments`; activity = merge `getActivity` rows per case with `xray_request_history` rows, sort by timestamp desc. No SQL computes money that the services already compute — this class only aggregates service outputs (spec §9).

- [ ] **Step 3: Run tests**

Run: `php tests/run.php` → green.

---

### Task 11: `api/finance-actions.php` + `api/approval-center.php`

**Files:**
- Create: `api/finance-actions.php`
- Create: `api/approval-center.php`
- Create: `tests/cases/FinanceActionsTest.php` (service-level re-checks only — HTTP layer is thin)

**Interfaces:**
- Produces (both JSON; role gate accountant|admin exactly like `api/accountant-feed.php:27-37`; CSRF comes free via the global fetch patch — verify POST handlers check the token the same way existing api files do, copy that check):
  - `api/approval-center.php?action=counts` → `ApprovalQueue::counts()`
  - `?action=list&tab=&q=&doctor_id=&date_from=&date_to=` → `['rows'=>…, 'counts'=>…, 'server_time'=>…]`
  - `?action=new_since&since=` → `ApprovalQueue::newSince`
  - `?action=detail&kind=&id=` → `ApprovalQueue::detail`
  - `api/finance-actions.php` POST `action` ∈:
    - `approve_invoice` (case_id, invoice_id) → `CaseInvoice::approve` — copy the call from `accountant/report-detail.php:64-70`
    - `return_invoice` (case_id, invoice_id, reason — required non-empty) → `returnForCorrection` (`:71-82`)
    - `update_invoice_accounting` (invoice_id, discount_mode, discount_value, accounting_notes) → `setDiscount` (`:83-105`)
    - `approve_payment` (payment_id) → `Payment::approve` (`:135-143`)
    - `adjust_payment` (payment_id, amount, note) → `Payment::updatePendingAmount` (Task 7)
    - `reject_payment` (payment_id, reason — required) → `Payment::reject`
    - `receive_payment` (case_id, amount, payment_method, allocations[], idempotency token) → `Payment::directPayment` — copy the handler from `report-detail.php:106-134` including its idempotency token handling
    - `activate_installment` (plan_id, edits{...}) → `Installment::activatePlan`
    - `reject_installment` (plan_id, reason — required) → `Installment::rejectPlan`
    - `create_installment` (case_id, invoice_id, terms) → eligibility + `Installment::createPlan` — copy `report-detail.php:161-178`
    - `receive_installment` (plan_id, amount, method, note) → `makeFlexiblePayment` — copy `:179-189`
    - `reverse_payment` (payment_id, reason — required) → `Payment::refund` (`:152-160`)
    - `xray_settle` (id, decision approve_payment|approve_debt, new_amount?, discount_amount?, discount_reason?, note?) → copy the whole handler body from `api/accountant-feed.php:457-525` (correctAmount → applyDiscount → approve*AndSendToXray)
  - Every action returns `{ok:true, ...}` or `{ok:false, error:'…kurdish…'}` with HTTP 200 (front-end branches on `ok`), 4xx only for auth/CSRF. Each handler wraps in try/catch; "already processed" errors return `{ok:false, error:'پێشتر جێبەجێکراوە', stale:true}` so the UI refreshes the row.
- Consumes: Tasks 6, 7, 9 methods. `sync_legacy_accounting_report` is NOT called (accounting_reports frozen — Task 13 removes it).

- [ ] **Step 1: Write the two endpoints**

Skeleton (both files): require `config/config.php` (which boots auth/session the way `api/accountant-feed.php` does — copy its first 40 lines: requires, role gate, JSON headers, CSRF check for POST). Then a `switch ($action)` dispatching to the services; nothing else. No SQL in these files beyond what `ApprovalQueue` provides.

- [ ] **Step 2: Service-level tests**

```php
<?php // tests/cases/FinanceActionsTest.php
// The endpoints are thin; test the two behaviors they add:
// (1) reasons enforced: CaseInvoice::returnForCorrection with '' throws (check the class — if it doesn't yet, ADD the guard there)
$t->assertThrows(fn() => (new CaseInvoice($db))->returnForCorrection($case, $inv2, $acct, ''), 'return reason required');
// (2) return auto-rejects pending allocated payments (existing behavior at CaseInvoice.php:358-370 — regression-pin it)
// build: sent invoice + pending request allocated to it → returnForCorrection → assert request status = 'rejected'
```

Run: `php tests/run.php` → green (add the non-empty-reason guard to `returnForCorrection` if missing — spec §12).

- [ ] **Step 3: Manual smoke via local Apache (if running)**

`GET http://localhost:8080/dentHisab/api/approval-center.php?action=counts` logged in as accountant → JSON counts. Not logged in → 401 JSON.

---

### Task 12: Doctor-side flow changes (`cases/view.php` + invoice pane)

**Files:**
- Modify: `cases/view.php` (handlers listed below)
- Modify: `cases/partials/invoice_pane.php`
- Modify: `classes/CaseInvoice.php` (`send` signature + intent)
- Create: `tests/cases/DoctorFlowTest.php`

**Interfaces:**
- Consumes: `Installment::proposePlan` (Task 6), `Payment::createRequest`, `Notification::notifyInvoiceSent` (Task 8).
- Produces: `CaseInvoice::send($caseId, $invoiceId, $userId, $notes = '', array $intent = [])` — `$intent` = `['payment_intent' => 'cash'|'debt'|'installment'|null, 'promised_date' => 'Y-m-d'|null, 'promised_amount' => float|null]`, persisted on the invoice row in the same optimistic-locked update.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/cases/DoctorFlowTest.php
$ci = new CaseInvoice($db);
// send with debt intent + promise
$inv = /* draft invoice with one treatment (fx helpers) */;
$ci->send($case, $inv, $doc, '', ['payment_intent' => 'debt', 'promised_date' => date('Y-m-d', strtotime('+14 days')), 'promised_amount' => 50000]);
$row = $db->fetch("SELECT * FROM case_invoices WHERE id = ?", [$inv]);
$t->assertSame('debt', $row['payment_intent'], 'intent persisted at send');
$t->assertSame('sent', $row['accounting_status'], 'sent');
// invalid intent rejected
$t->assertThrows(fn() => $ci->send($case, $inv2, $doc, '', ['payment_intent' => 'bitcoin']), 'intent whitelist');
// intent changes no balance: AccountingLedger summary approved == 0 while only sent
$t->assertEqualsMoney(0, (new AccountingLedger($db))->getSummary($case)['approved'], 'sent-not-approved books nothing');
```

Run → FAIL (send has no 5th arg).

- [ ] **Step 2: Extend `CaseInvoice::send`**

At `classes/CaseInvoice.php:115-151`: add the `$intent` param; whitelist `payment_intent` against `['cash','debt','installment', null]` (throw on other values); include the three columns in the existing optimistic-locked UPDATE; include them in the audit payload. Existing callers (grep `->send(`) keep working via the default `[]`.

- [ ] **Step 3: `cases/view.php` handler surgery**

All references are pre-task line numbers — re-locate by handler name, not line, since earlier edits shift lines:
1. **Delete the dead `request_payment` branch** (`:683-759`) and its stale comments; also the vestigial `action = $isAdmin ? 'direct_payment' : 'request_payment'` JS at `:7612` → always the real action per role (see item 4).
2. **`send_invoice` handler (`:1139-1167`)**: accept `payment_intent`, `promised_date`, `promised_amount` from POST → pass to `send()`. Replace the `accounting_reports` insert (`:1156-1164`) and `notifyCaseCompleted` (`:1166`) with `Notification::notifyInvoiceSent`. Same for `send_to_accounting` (`:1490-1522`) and delete `resend_to_accounting`'s report write (`:1534-1555`) — a resend becomes `notifyInvoiceSent` again with the note appended to the invoice's `notes` via `update`.
3. **Re-enable وەرگیراو for doctors**: at `:1001-1003` change the gate from `!$canReceiveMoney → fail` to allow doctors: doctor path → `Payment::createRequest` (the code already exists in this handler's role fork at `:1058-1104` — re-read it; restore the doctor branch, keep admin/accountant on `directPayment`), delete its `accounting_reports` note write (`:1091-1104`), notification = `notifyPaymentRequested` (now retargeted). In `cases/partials/invoice_pane.php:294-296` change `$canSetPaid` to also allow the case's own doctor while invoice is draft/sent; update the stale comments (`:288-296, 377-380`).
4. **`create_installment` (`:806-854`)**: role fork — money roles keep current direct `createPlan`; doctors → `Installment::proposePlan` (terms + `proposed_down_payment` from the form's down-payment field — delete the force-to-zero at `:814` and the notes hack `:817-838` and its report write). Success flash for doctor: `داواکاری قیست نێردرا بۆ ژمێریاری`.
5. **`flexible_installment_payment` (`:855-910`)**: keep, but delete its `accounting_reports` write (`:887-895`).
6. **Case-level `apply_discount` (`:1453`)**: remove the handler + its button/dialog markup (per-invoice discount supersedes; grep the pane for the trigger element). Keep `CaseManager::applyDiscount` method untouched (mirror math still reads the columns).
7. **`close_case` (`:930`)**: now plain `close()` (Task 4 already changed the class) — remove any UI copy promising a credit payment.
8. Grep `cases/view.php` for `accounting_reports` — zero write sites remain; the two helpers `create_accounting_report`/`append_to_accounting_report` (`:209-229`) are deleted.

- [ ] **Step 4: Send-dialog UI (invoice pane)**

In `cases/partials/invoice_pane.php`, extend the send confirmation dialog (find the existing send form/dialog — it posts `send_invoice`) with a required intent radio group styled like the x-ray `payment_type` radios (`xray/request.php:1262-1276` is the pattern): `نەقد` / `قەرز` / `قیست`. Choosing `قەرز` reveals optional `promised_date` (date input) + `promised_amount` (number input). Choosing `قیست` submits with intent `installment` and then opens the existing installment dialog pre-focused (reuse the pane's dialog-manager JS layer). Default checked: `نەقد`.

- [ ] **Step 5: Run tests + manual pass**

Run: `php tests/run.php` → green. Manual (local Apache): as doctor — build draft, send with قەرز + promise; type وەرگیراو → confirm creates a pending request (visible in DB); propose installment → plan `pending`. As admin — same page still books directly.

---

### Task 13: Patient statement page + freeze `accounting_reports`

**Files:**
- Create: `patients/statement.php`
- Modify: `accountant/report-detail.php` → replace with redirect stub
- Modify: `api/notifications.php:375-381, 435-441` (complete_case report writes)
- Create: `tests/cases/FreezeTest.php`

**Interfaces:**
- Consumes: `PatientFinance::statement/summary` (Task 10), `api/finance-actions.php` (Task 11), `AccessGuard` (`config/config.php`).
- Produces: URL contract `patients/statement.php?patient_id=N[&case_id=M]` (case_id → scrolls to `#case-M`); accountant/admin see actions, doctors AccessGuard-scoped read-only.

- [ ] **Step 1: Build `patients/statement.php`**

Structure (copy the page chrome — header include, sidebar include, RTL container — from `patients/view.php`'s top; copy table/card styling classes from `accountant/report-detail.php`):
1. Gate: logged in; if role doctor → `AccessGuard::requirePatientGeneral($patientId)` (same call style as `xray/request.php:42-47`) and pass `$doctorUserId` into `PatientFinance`; accountant/admin unscoped.
2. `$data = (new PatientFinance())->statement($patientId, $doctorScopeUserId);`
3. Render: summary cards row (8 values from `summary` — reuse the stat-tile markup from `accountant/receiving.php:160-181`); per-case section with invoice table (columns: ژمارە، بەروار، کۆ، داشکاندن، پوختە، دراوە، ماوە، دۆخ، مەبەست — statuses via the label maps copied from `accountant/report-detail.php:222-234`); payments table with allocation sub-rows incl. refunds; installment block (plan + dynamic schedule); x-ray section; activity timeline (reuse report-detail's activity tab markup + event labels `:236-251`).
4. For accountant/admin: action buttons posting to `api/finance-actions.php` (`receive_payment` with the allocation picker — port the JS + form from `report-detail.php`'s receive dialog; `create_installment`; `reverse_payment` with required reason prompt). Doctors: no action buttons.
5. Anchors: each case block `id="case-<caseId>"`; on load, `location.hash` scroll (or `?case_id` → JS scroll).

- [ ] **Step 2: Redirect `report-detail.php`**

Replace the file's body with:
```php
<?php
require_once __DIR__ . '/../config/config.php';
$caseId = (int)($_GET['case_id'] ?? 0);
$pid = 0;
if ($caseId) {
    $row = Database::getInstance()->fetch(
        "SELECT pf.patient_id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = ?", [$caseId]);
    $pid = (int)($row['patient_id'] ?? 0);
}
header('Location: ' . APP_URL . '/patients/statement.php?patient_id=' . $pid . ($caseId ? '&case_id=' . $caseId : ''), true, 302);
exit;
```
(Verify `APP_URL`-style base constant name in `config/config.php`; use the same one other redirects use.) The POST handlers it contained are already re-homed in `api/finance-actions.php` (Task 11). `sync_legacy_accounting_report()` dies with the file — that was the only `processed` writer, which is correct: the table is frozen.

- [ ] **Step 3: Remove the last `accounting_reports` writers**

`api/notifications.php:375-381, 435-441` (complete_case): delete the report inserts; the notification itself stays. Then `Grep "accounting_reports"` repo-wide: remaining hits must be reads only (feed/pending-reports die in Task 14) or `Database.php`'s table-exists guard. Document surviving read-only hits in the task summary.

- [ ] **Step 4: Freeze test + run**

```php
<?php // tests/cases/FreezeTest.php
// After a full doctor flow (draft → send with intent), assert accounting_reports gained no rows:
$before = (int)$db->fetch("SELECT COUNT(*) c FROM accounting_reports")['c'];
/* run: create draft, add treatment, send() */
$after = (int)$db->fetch("SELECT COUNT(*) c FROM accounting_reports")['c'];
$t->assertSame($before, $after, 'accounting_reports frozen');
```
Run: `php tests/run.php` → green. Manual: open `patients/statement.php?patient_id=…` as admin and as a scoped doctor.

---

### Task 14: Approval Center page + retire the old accountant pages

**Files:**
- Create: `accountant/approval-center.php`
- Create: `assets/js/approval-center.js` (page-local; follow how `receiving.php` structures its inline JS — a separate file is fine and cache-busted like other assets)
- Modify: `accountant/receiving.php`, `accountant/pending-reports.php` → redirect stubs to `approval-center.php` (same 302 pattern as Task 13, preserving `?tab=xray` → `?tab=xray`)
- Modify: `api/accountant-feed.php` → redirect/410 stub: `http_response_code(410); echo json_encode(['ok'=>false,'error'=>'moved: api/approval-center.php']);`
- Modify: `includes/sidebar.php:27`, `includes/header.php:73-76` (badge), plus the sidebar link items for receiving/pending-reports → single `approval-center.php` entry

**Interfaces:**
- Consumes: `api/approval-center.php` + `api/finance-actions.php` (Task 11 contracts), `ApprovalQueue::counts` for the server-rendered badge.

- [ ] **Step 1: Build the page**

Single page, accountant+admin gate (copy from `receiving.php:22-26`). Server-side: render shell + initial `ApprovalQueue::counts()` for instant tabs. Client (`approval-center.js`):
1. Tab bar: پسووڵەکان / پارەدانەکان / قیستەکان / ئێکسڕەی / ژووری چاوەڕوانی + toggle کراوەکان|مێژوو (30 ڕۆژ). Tab badges from `counts`.
2. Common filter header: search input (patient/phone/number), doctor `<select>` (populate server-side from active doctors — copy the doctor-select population any accountant page uses), date range. Filters map to `?action=list` params; re-fetch on change (300ms debounce on search).
3. Table columns exactly per spec §6: type icon, patient, doctor, number, amount, payment type/intent (قەرز rows show `📅 promised_date` beneath), created time, status chip, action button. Reuse `receiving.php`'s table/status-chip CSS classes (`:210-219` markup as the template).
4. Row click → drawer: fixed-position RTL side panel (or the existing dialog-manager modal pattern from `cases/view.php` JS layer — reuse `.tp-overlay`-style classes NOT `.dialog-overlay` per project convention). Content = `?action=detail`, rendered per kind with the action forms:
   - invoice: treatments table, net/discount/paid, intent/promise; buttons پەسەندکردن (approve_invoice), داشکاندن/تێبینی (update_invoice_accounting inline form), گەڕاندنەوە (return_invoice — reason textarea required client+server).
   - payment: amount (editable → adjust_payment), allocations list; پەسەندکردن / ڕەتکردنەوە(+reason).
   - installment: terms form pre-filled (editable) + چالاککردن (activate_installment with edits) / ڕەتکردنەوە(+reason).
   - xray: port the modal from `accountant/pending-reports.php:700-742` verbatim (approve_payment/approve_debt + new_amount/discount/reason/note) posting `xray_settle`.
   - Every drawer: header link `کشف حساب` → `patients/statement.php?patient_id=…`.
5. Actions POST to `api/finance-actions.php`; on `{ok:true}` → toast, close drawer, refetch list+counts; on `{stale:true}` → toast `پێشتر جێبەجێکراوە` + refetch. Double-submit guard: disable buttons during fetch (reuse the double-submit guard JS pattern from `cases/view.php`).
6. Polling: every 8s `?action=new_since&since=<server_time from last list>`; on new uids → refetch current tab + counts + play the sound (copy the audio element + play logic from `receiving.php:530s` polling block; keep the same sound file).

- [ ] **Step 2: Badge + sidebar**

`includes/sidebar.php:27` and `includes/header.php:73-76`: replace the `accounting_reports status='pending'` count with `ApprovalQueue::counts()['badge']` (require the class; counts() is 4 cheap COUNTs — acceptable, and header already caches similar badges if a pattern exists there; follow it). Sidebar: one link `ناوەندی پەسەندکردن` (approval-center) for accountant/admin replacing the receiving + pending-reports entries.

- [ ] **Step 3: Redirect stubs**

`receiving.php` and `pending-reports.php` → 302 to `approval-center.php` (map `?tab=xray&xray_search=` → `?tab=xray&q=`). Keep files as stubs (external links/notifications may point at them). `api/accountant-feed.php` → the 410 JSON stub.

- [ ] **Step 4: Repo-wide link sweep**

Grep for `receiving.php`, `pending-reports.php`, `report-detail.php`, `accountant-feed.php`, `station.php` — update every generator of those URLs (notification links from Task 8 already point to approval-center; `XrayManager` link strings; any sidebar/dashboard cards) to the new pages. Zero remaining generators; stubs only catch stale bookmarks/old notifications.

- [ ] **Step 5: Run tests + manual pass**

`php tests/run.php` → green. Manual: as accountant — approve an invoice from the drawer; return one (reason enforced); adjust+approve a doctor request; activate a proposed plan; settle a cash x-ray → lands in `pending_xray`; badge count drops accordingly; second tab open on the same item → acting in both → second gets `پێشتر جێبەجێکراوە`.

---

### Task 15: Legacy repoints (money-critical readers → derived)

**Files:**
- Modify: `debts.php:64-89`, `api/patient-debt.php:37-56`, `classes/Patient.php` (`getById:105-111`, `getAll:133-140`, `count:224`, `getStatistics:410-423`, `countPatientsWithDebt:496`, `getTotalDebtAmount:506`, `getDoctorPatients:655-662`, `getStatisticsForDoctor:866-880`), `classes/DebtCollection.php:20-131, 548-613`, `patients/view.php` (stat cards — the `Patient::getStatistics` consumers), `admin/invoices.php:41-134`, `api/export-debts.php:35`, `accountant/dashboard.php:48-52`
- Create: `tests/cases/RepointTest.php`

**Interfaces:**
- Consumes: view `v_patient_financial_summary` (Task 2), `PatientFinance::summary` (Task 10), `XrayManager::debtStatusSqlList` (Task 5, already applied to these files' x-ray fragments).

- [ ] **Step 1: Write the invariant test**

```php
<?php // tests/cases/RepointTest.php
// Build a patient whose LEGACY mirror disagrees with the ledger:
// approved invoice net 100000 with 60000 allocated-approved, then corrupt the mirror:
$db->update('cases', ['paid_amount' => 999999], 'id = ?', [$case]);
// Every repointed reader must now report the LEDGER number (40000), not the mirror's:
$t->assertEqualsMoney(40000, (new PatientFinance())->summary($pat)['case_balance'], 'PatientFinance ignores mirror');
$vrow = $db->fetch("SELECT case_balance FROM v_patient_financial_summary WHERE patient_id = ?", [$pat]);
$t->assertEqualsMoney(40000, $vrow['case_balance'], 'view ignores mirror');
require_once __DIR__ . '/../../classes/Patient.php';
$stats = (new Patient($db))->getStatistics();   // real signature
// assert the debt figure in $stats equals 40000 (+ any xray fixture), NOT the corrupted mirror value
```

Run → view/PatientFinance pass (built derived), `Patient::getStatistics` FAILS (still on mirror).

- [ ] **Step 2: Repoint the `Patient` and `DebtCollection` classes**

Replace every `pf.total_amount - pf.paid_amount - pf.discount_amount` fragment in the listed `Patient.php` methods with a JOIN to `v_patient_financial_summary s ON s.patient_id = p.id` using `s.case_balance` (list pages) — for doctor-scoped variants, keep the ownership predicates but source the money from a scoped subquery over `v_case_financial_summary` joined through the doctor's folders (same join shape as the unscoped one; copy the ownership WHERE from the current code). Same treatment for `DebtCollection::getDebtors` (`:85-131`): case debt from `v_case_financial_summary` aggregated per patient, x-ray fragment already canonical (Task 5), installment fragment already derived from `installment_payments` — keep it. `getStatistics` (`:548-613`) likewise.

- [ ] **Step 3: Repoint the pages**

- `debts.php:77-89` + drill-down modal: patient list from `v_patient_financial_summary` (case part) + canonical x-ray + credit — or simply call `PatientFinance::summary` per displayed row if the list is paginated small; for the big list use the view (one query). Keep `$debtorWhere` doctor scoping.
- `api/patient-debt.php`: both branches → `PatientFinance::summary($patientId, $doctorScope)['net_debt']`.
- `admin/invoices.php`: the `total - paid - discount` expressions (`:41-134`) → `v_case_financial_summary` joins (`balance`, `paid_amount`).
- `patients/view.php` stat cards: already fed by `Patient::getStatistics*` — verify they now show derived numbers; remove any residual inline `- paid_amount -` math (`:2072`).
- `accountant/dashboard.php:48-52`: already on the view — just confirm the x-ray part uses the Task 5 helper.
- `api/export-debts.php`: same view join as `debts.php`.

- [ ] **Step 4: Run tests + reconciliation script**

`php tests/run.php` → green. Additionally create a one-off reconciliation check inside the test run (assert view totals equal summed `AccountingLedger::getSummary` for the fixture patients). Manual: open `debts.php`, `patients/view.php`, `admin/invoices.php` locally — numbers consistent with `patients/statement.php` for the same patient.

---

### Task 16: Final integration pass + rollout packaging

**Files:**
- Modify: `sw.js` (bump `CACHE_VERSION`)
- Create: `docs/superpowers/specs/2026-08-26-rollout-checklist.md`
- Create: `tests/cases/EndToEndTest.php`

- [ ] **Step 1: End-to-end test**

```php
<?php // tests/cases/EndToEndTest.php — the spec's headline flow, one narrative:
// 1 doctor: draft invoice + 2 treatments (120000) → send intent=debt promise 30 days
// 2 assert: queue invoices=1, badge=1, balance still 0 (not approved)
// 3 accountant: setDiscount 20000 → approve → assert balance 100000, queue empty, history=1
// 4 doctor: وەرگیراو request 40000 → assert payments queue 1, balance still 100000, pending_requests 40000
// 5 accountant: adjust to 35000 → approve → assert paid 35000, balance 65000, allocations sum 35000
// 6 doctor: propose installment on remainder → accountant edits months → activate → schedule rows exist, plan active
// 7 xray: doctor cash request → queue xray=1 → accountant approves with 5000 discount → request_status pending_xray, payment_status paid
// 8 statement: PatientFinance::summary matches every number above; activity timeline ≥ 8 events
// 9 duplicate safety: re-approve invoice + re-approve payment + re-activate plan all throw/no-op with zero row changes
// 10 accounting_reports row count unchanged across the whole narrative
```
Write it with real assertions at each numbered point (reuse fixtures/helpers). Run → green.

- [ ] **Step 2: Repo-wide dead-reference sweep**

Grep: `accounting_reports` (writes: zero), `payment_status = 'debt'` (zero outside XrayManager/migrations), `closeWithCredit` (zero), `autoAllocatePayment` (zero), `station.php` (zero), `request_payment` (zero in cases/view.php), `processCashPayment|processDebtPayment` (only the deprecated throwing stubs + no live callers). Fix stragglers.

- [ ] **Step 3: Bump service worker + write the rollout checklist**

`sw.js`: increment `CACHE_VERSION`. Checklist file content — upload order per spec §14: (1) `database/migrations/2026_08_26_*.sql` (auto-applies on first request), (2) `classes/`, (3) `api/` + pages + `includes/` + `assets/`, (4) verify `storage/migrations.lock` regenerates, (5) smoke: approval center loads, badge correct, one invoice approve on live data, `debts.php` totals sane, (6) note: debt totals shift expectedly (`debt_approved` now visible; `debt_pending` excluded — spec §14.6), (7) `tests/` folder is NOT uploaded to live.

- [ ] **Step 4: Full test run + manual sweep**

`php tests/run.php` → `0 failures`. Manual sweep on local Apache (:8080) as each role: doctor (cases/view flow), accountant (approval-center all five tabs + statement actions), admin (admin/payments.php still works — it calls `Payment::approve` directly, unchanged), receptionist (debts collection dialog via `api/debt_payment.php` — Task 4 path), x-ray user (queue receives approved requests).

---

## Self-Review (performed at write time)

- **Spec coverage:** §3 truth table → Tasks 3-5, 10, 15; §4 migrations → Task 2; §5 doctor flow → Tasks 6, 12; §6 center → Tasks 9, 11, 14; §7 x-ray → Tasks 5, 11, 14; §8 statement → Tasks 10, 13; §10 retirement → Tasks 13-15; §11 bugs 1-10 → Tasks 3 (1,2,3,6-per-item 10), 4 (4,9-partial), 5 (5), 6 (9), 14 (7,8); §12 safety → Tasks 6, 7, 11; §13 tests → every task; §14 rollout → Task 16. Gap check: spec §11.10 (`case_invoices.status` sync) — deliberately untouched, matches spec.
- **Placeholder scan:** adaptation notes are bounded ("copy from file:line", "verify signature") — each names the exact source to copy; no open-ended TBDs.
- **Type consistency:** `ApprovalQueue::counts()['badge']` used in Tasks 9/14; `PatientFinance::summary` keys match between Tasks 10/13/15; `debtStatusSqlList` between 5/15; `proposePlan/activatePlan/rejectPlan` between 6/11/14; `updatePendingAmount` between 7/11/14; `send($caseId,$invoiceId,$userId,$notes,$intent)` between 12 and its callers.
