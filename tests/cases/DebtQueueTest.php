<?php // tests/cases/DebtQueueTest.php
// Post-merge follow-up #1 (docs/superpowers/specs/2026-08-26-post-merge-followups.md,
// item 1): the debt-collection call queue (DebtCollection::getDebtors/
// getStatistics/getNextToCall, surfaced by accountant/debt-collection.php)
// used to show GROSS debt while debts.php, api/patient-debt.php and the
// patient statement page all net against patient_credits.balance — a
// collector could quote a number the payment API wouldn't confirm.
// Decision: net the queue too, and expose credit_balance per row so money
// roles can see the credit itself.
//
// Fixture pattern copied from RepointTest.php (approved-invoice + xray-debt
// + patient_credits rows via the real write-through path), but self-
// contained — this file sorts alphabetically BEFORE RepointTest.php, so it
// cannot rely on any helper function RepointTest.php defines at file scope.
//
// Each scenario below gets its OWN fresh doctor (fx_user('doctor') is
// unique every call) so doctor-scoped queries are isolated from both each
// other and from other test files' fixtures sharing this database.
require_once __DIR__ . '/../../classes/DebtCollection.php';
require_once __DIR__ . '/../../classes/CaseManager.php';

$db = Database::getInstance();
$debtCollection = new DebtCollection();

function dqt_xray_debt($db, $patientId, $doctorId, $status, $amount) {
    return (int) $db->insert('xray_requests', [
        'request_number'  => 'DQT' . substr(uniqid(), -8) . rand(10, 99),
        'patient_id'      => $patientId,
        'requested_by'    => $doctorId,
        'xray_type'       => 'panoramic',
        'xray_reason'     => 'pathology',
        'status'          => 'completed',
        'request_status'  => 'completed',
        'payment_status'  => $status,
        'original_amount' => $amount,
        'final_amount'    => $amount,
    ]);
}

// ================================================================
// Scenario 1: case debt 40000 + xray debt (debt_approved) 20000 + credit
// 15000 -> total_debt must net to 45000, and credit_balance must surface
// the raw 15000 so the UI can show it. RED against pre-fix (gross) code:
// total_debt would read 60000 there, credit_balance wouldn't exist.
// ================================================================
$doc1 = fx_user('doctor');
$pat1 = fx_patient();
$patientCode1 = $db->fetch('SELECT patient_code FROM patients WHERE id = ?', [$pat1])['patient_code'];
$fold1 = fx_folder($pat1, $doc1);
$case1 = fx_case($fold1);
$inv1 = fx_invoice($case1, $doc1, 'sent', 'approved', 40000);
fx_treatment($case1, 40000, $inv1);
dqt_xray_debt($db, $pat1, $doc1, 'debt_approved', 20000);
$db->insert('patient_credits', ['patient_id' => $pat1, 'balance' => 15000]);

$rows1 = $debtCollection->getDebtors($patientCode1);
$t->assertSame(1, count($rows1), 'DebtQueueTest: getDebtors finds the partially-credit-covered patient');
$t->assertEqualsMoney(45000, $rows1[0]['total_debt'], 'DebtQueueTest: total_debt nets to 40000 case + 20000 xray - 15000 credit = 45000');
$t->assertEqualsMoney(15000, $rows1[0]['credit_balance'], 'DebtQueueTest: credit_balance surfaces the raw 15000 credit for the UI');
$t->assertEqualsMoney(45000, $rows1[0]['normal_debt'], 'DebtQueueTest: normal_debt (no installment plan) matches the netted total_debt');

// ================================================================
// Scenario 2: credit fully covers the debt (case debt 20000, credit
// 100000) -> the patient must drop off the call queue entirely, not just
// show a floored-at-zero row.
// ================================================================
$doc2 = fx_user('doctor');
$pat2 = fx_patient();
$patientCode2 = $db->fetch('SELECT patient_code FROM patients WHERE id = ?', [$pat2])['patient_code'];
$fold2 = fx_folder($pat2, $doc2);
$case2 = fx_case($fold2);
$inv2 = fx_invoice($case2, $doc2, 'sent', 'approved', 20000);
fx_treatment($case2, 20000, $inv2);
$db->insert('patient_credits', ['patient_id' => $pat2, 'balance' => 100000]);

$rows2 = $debtCollection->getDebtors($patientCode2);
$t->assertSame(0, count($rows2), 'DebtQueueTest: a patient whose credit fully covers their debt is NOT in getDebtors output');

// Same patient must also be absent from the unfiltered (whole-queue) list —
// proves the HAVING total_debt > 0 filter, not just the search term, excludes them.
$allRows = $debtCollection->getDebtors();
$stillPresent = array_filter($allRows, fn($d) => (int)$d['patient_id'] === $pat2);
$t->assertSame(0, count($stillPresent), 'DebtQueueTest: the fully-credit-covered patient is absent from the unfiltered queue too');

// ================================================================
// getStatistics(): total_debt must equal the sum of the (netted)
// unfiltered getDebtors() list, so the stats bar on
// accountant/debt-collection.php never disagrees with the table under it.
// ================================================================
$sumNetted = array_sum(array_map(static fn($d) => (float)($d['total_debt'] ?? 0), $allRows));
$stats = $debtCollection->getStatistics();
$t->assertEqualsMoney($sumNetted, $stats['total_debt'], 'DebtQueueTest: getStatistics total_debt agrees with the summed netted getDebtors list');

// ================================================================
// getNextToCall() consistency: it must delegate to the same netted
// getDebtors() rather than run a separately-gross'd query, so it can never
// try to assign a patient the list itself has already dropped. Doctor
// scoping isolates each check to exactly one fixture patient, so nothing
// else sharing this test database can end up picked instead.
// ================================================================
$caller = fx_user('accountant');

// doc2 owns ONLY the fully-credit-covered pat2 -> nothing eligible to assign.
$nextForDoc2 = $debtCollection->getNextToCall($caller, $doc2);
$t->assertSame(null, $nextForDoc2, 'DebtQueueTest: getNextToCall never assigns a fully-credit-covered patient — consistent with getDebtors dropping them from the queue');
$debtCollection->releaseLock($pat2, $caller);

// doc1 owns ONLY pat1 -> must be assigned, carrying the same netted total_debt.
$nextForDoc1 = $debtCollection->getNextToCall($caller, $doc1);
$t->assertTrue($nextForDoc1 !== null, 'DebtQueueTest: getNextToCall assigns the real (net > 0) debtor for their owning doctor');
if ($nextForDoc1 !== null) {
    $t->assertSame($pat1, (int)$nextForDoc1['patient_id'], 'DebtQueueTest: getNextToCall(doc1) picks pat1, the only debtor in that doctor scope');
    $t->assertEqualsMoney(45000, $nextForDoc1['total_debt'], 'DebtQueueTest: getNextToCall surfaces the same netted total_debt as getDebtors (single source of truth)');
}
$debtCollection->releaseLock($pat1, $caller);
