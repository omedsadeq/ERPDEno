<?php // tests/cases/XrayDebtTest.php
// Canonical x-ray debt statuses: 'debt' and 'debt_approved' are receivable
// debt; 'debt_pending' is a request still awaiting accountant approval and
// must NOT count as debt (spec §3, XrayManager::DEBT_STATUSES).
$db = Database::getInstance();
require_once __DIR__ . '/../../classes/XrayManager.php';

$doc = fx_user('doctor');
$pat = fx_patient();

function xdt_insert_request($db, $pat, $doc, $status, $amount) {
    return (int) $db->insert('xray_requests', [
        'request_number'  => 'TXD' . substr(uniqid(), -8) . rand(10, 99),
        'patient_id'      => $pat,
        'requested_by'    => $doc,
        'xray_type'       => 'panoramic',
        'xray_reason'     => 'pathology',
        'status'          => 'completed',
        'request_status'  => 'completed',
        'payment_status'  => $status,
        'original_amount' => $amount,
        'final_amount'    => $amount,
    ]);
}

$xm = new XrayManager();
// getOutstandingDebt() is a whole-clinic aggregate (no patient filter), so
// other test files that legitimately create debt/debt_approved fixtures
// (e.g. PatientFinanceTest.php, which sorts before this file) also
// contribute to it. Snapshot before this fixture's own inserts and assert
// the DELTA those inserts add, not an absolute value.
$outstandingBefore = $xm->getOutstandingDebt();

// Three completed xray_requests for one patient.
xdt_insert_request($db, $pat, $doc, 'debt', 30000);
xdt_insert_request($db, $pat, $doc, 'debt_approved', 20000);
xdt_insert_request($db, $pat, $doc, 'debt_pending', 99999);

$t->assertEqualsMoney(50000, $xm->getPatientXrayDebt($pat), 'debt + debt_approved counted, debt_pending excluded');
$t->assertEqualsMoney($outstandingBefore + 50000, $xm->getOutstandingDebt(), 'outstanding excludes debt_pending');

// ================================================================
// Review finding 1 regression: final_amount is frozen once a request enters
// the debt channel — a partial collection is recorded in patient_xray_debts,
// never by shrinking final_amount. Every reader of "how much x-ray debt is
// left" (getPatientXrayDebt/-ForDoctor, PatientFinance::summary()
// ['xray_outstanding'], debts.php, DebtCollection, Patient::getAll,
// api/export-debts.php) must reflect the collection, not just the debt-items
// breakdown modal. Before the fix, all of those still reported the full
// frozen 50000 after a 20000 partial collection.
// ================================================================
require_once __DIR__ . '/../../classes/PatientFinance.php';

$pat2 = fx_patient();
$reqId2 = xdt_insert_request($db, $pat2, $doc, 'debt', 50000);

// Mirror DebtPayment::applyXrayPayment's bookkeeping for a 20000 partial
// collection (that method is private; this reproduces exactly what it
// writes: a patient_xray_debts row taken from 50000 to 30000 remaining, plus
// its xray_debt_payments audit row).
$debtId2 = (int) $db->insert('patient_xray_debts', [
    'patient_id'       => $pat2,
    'request_id'       => $reqId2,
    'amount'           => 50000,
    'remaining_amount' => 50000,
    'status'           => 'pending',
]);
$db->update('patient_xray_debts', [
    'remaining_amount' => 30000,
    'status'           => 'partial',
], 'id = :id', ['id' => $debtId2]);
$db->insert('xray_debt_payments', [
    'debt_id'        => $debtId2,
    'amount'         => 20000,
    'payment_method' => 'cash',
    'received_by'    => $doc,
    'notes'          => 'XrayDebtTest partial collection',
]);

$t->assertEqualsMoney(30000, $xm->getPatientXrayDebt($pat2), 'partial collection: getPatientXrayDebt reports the 30000 remaining, not the frozen 50000 final_amount');
$t->assertEqualsMoney(30000, $xm->getPatientXrayDebtForDoctor($pat2, $doc), 'partial collection: getPatientXrayDebtForDoctor agrees');

$pf = new PatientFinance();
$t->assertEqualsMoney(30000, $pf->summary($pat2)['xray_outstanding'], 'PatientFinance::summary xray_outstanding reflects the partial collection');

// The exact xray_debt fragment now in debts.php (Finding 1 sweep) must agree.
$debtsPhpXraySql = "SELECT COALESCE(SUM(" . XrayManager::remainingAmountSql('xr', 'pxd') . "), 0) AS xray_debt
                     FROM xray_requests xr " . XrayManager::remainingAmountJoinSql('xr', 'pxd') . "
                     WHERE xr.patient_id = ? AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")";
$debtsPhpXray = $db->fetch($debtsPhpXraySql, [$pat2]);
$t->assertEqualsMoney(30000, $debtsPhpXray['xray_debt'], 'debts.php-shaped xray_debt query agrees with getPatientXrayDebt after partial collection');

// A request in debt status with NO patient_xray_debts row yet (still awaiting
// debt-collection bookkeeping — the common case for a freshly-approved debt
// request) must count at its full final_amount: the LEFT JOIN's NULL branch,
// not silently zeroed out.
$pat3 = fx_patient();
xdt_insert_request($db, $pat3, $doc, 'debt_approved', 15000);
$t->assertEqualsMoney(15000, $xm->getPatientXrayDebt($pat3), 'a debt request with no patient_xray_debts row yet still counts at full final_amount');
