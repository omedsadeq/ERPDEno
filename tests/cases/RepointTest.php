<?php // tests/cases/RepointTest.php
// Task 15: legacy repoints — money-critical readers (Patient.php,
// DebtCollection.php, debts.php, admin/invoices.php, api/patient-debt.php,
// api/export-debts.php, patients/view.php stat cards) must source debt/
// balance figures from the derived accounting ledger (v_case_financial_
// summary / v_patient_financial_summary / PatientFinance::summary), never
// from the legacy cases/patient_folders total_amount-paid_amount-discount_
// amount mirror columns. Those columns stay write-through mirrors (still
// updated by CaseManager::updateCaseTotals/Folder::updateTotals) — this test
// proves nothing money-critical reads them back.
//
// Strategy: build a patient whose ledger and legacy mirrors AGREE (via the
// normal application write-through path, exactly like PatientFinanceTest),
// then corrupt the case-level mirror directly and propagate that corruption
// into the folder-level cache the same way the app's own Folder::updateTotals
// would — i.e. exactly what a stale/bad case mirror looks like once the
// existing write-through machinery has already run over it. Every repointed
// reader must still report the LEDGER number afterwards.
require_once __DIR__ . '/../../classes/Patient.php';
require_once __DIR__ . '/../../classes/DebtCollection.php';
require_once __DIR__ . '/../../classes/PatientFinance.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Folder.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/XrayManager.php';

$db = Database::getInstance();

$doc = fx_user('doctor');
$acct = fx_user('accountant');
$pat = fx_patient();
$patientCode = $db->fetch('SELECT patient_code FROM patients WHERE id = ?', [$pat])['patient_code'];

// ---- Snapshot whole-clinic aggregates BEFORE this fixture creates ANY debt,
// so the delta assertions below aren't polluted by other test files'
// fixtures sharing this database (same pattern as XrayDebtTest.php), and
// aren't fooled by the fact that the ledger-based readers below don't care
// when the corruption happens — only whether this patient's debt exists yet. ----
$patientObj = new Patient();
$debtCollection = new DebtCollection();
$countWithDebtBefore = (int)$patientObj->countPatientsWithDebt();
$totalDebtAmountBefore = (float)$patientObj->getTotalDebtAmount();
$dcStatsBefore = $debtCollection->getStatistics();

// ---- Case A: approved invoice net 100000, 60000 allocated-approved
// (balance = 40000) — built through the real write-through path so the
// legacy mirror and the ledger AGREE before we corrupt anything. ----
$foldA = fx_folder($pat, $doc);
$caseA = fx_case($foldA);
$invA = fx_invoice($caseA, $doc, 'sent', 'approved', 100000);
fx_treatment($caseA, 100000, $invA);
(new CaseManager())->updateCaseTotals($caseA);

$payment = new Payment();
$direct = $payment->directPayment([
    'case_id' => $caseA, 'amount' => 60000, 'payment_type' => 'cash', 'payment_method' => 'cash',
], $acct);
$t->assertTrue(!empty($direct['success']), 'fixture: approved payment of 60000 on case A');

// A pending request must never be booked as paid by any reader.
$payReq = $payment->createRequest([
    'case_id' => $caseA, 'amount' => 10000, 'payment_type' => 'cash',
    'payment_method' => 'cash', 'received_by' => $doc,
]);
$t->assertTrue(!empty($payReq['success']), 'fixture: pending payment request of 10000 on case A (must not count as paid)');

// ---- Case B: sent (not yet approved) invoice — contributes nothing to
// balance in either model, exercises the multi-folder aggregation path. ----
$foldB = fx_folder($pat, $doc);
$caseB = fx_case($foldB);
$invB = fx_invoice($caseB, $doc, 'sent', 'sent', 50000);
fx_treatment($caseB, 50000, $invB);

// ---- X-ray debt (canonical status, Task 5) so total_debt figures that
// combine case + x-ray parts (getAll/getDebtors/PatientFinance) have a
// nonzero x-ray component to verify too. ----
function rpt_xray_request($db, $pat, $doc, $status, $amount) {
    return (int) $db->insert('xray_requests', [
        'request_number'  => 'TRP' . substr(uniqid(), -8) . rand(10, 99),
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
rpt_xray_request($db, $pat, $doc, 'debt_approved', 20000);

// ---- Credit balance (review follow-up finding): debts.php must net the
// list's total_debt against patient_credits.balance exactly like
// PatientFinance::summary()'s net_debt, so this patient reads the same
// figure (45000 = 40000 case + 20000 xray - 15000 credit) on debts.php as
// on api/patient-debt.php. patient_credits.patient_id is UNIQUE (one row
// per patient), unrelated to the cases/patient_folders mirror this fixture
// corrupts below, so its placement here (before the corruption) doesn't
// matter — included early for readability. ----
$db->insert('patient_credits', ['patient_id' => $pat, 'balance' => 15000]);

// ================================================================
// Reconciliation (Step 4 of the brief): view totals must equal summed
// AccountingLedger::getSummary() balances for the fixture patient — the
// same underlying formula, computed two different ways.
// ================================================================
$ledger = new AccountingLedger();
$sumLedgerBalance = $ledger->getSummary($caseA)['balance'] + $ledger->getSummary($caseB)['balance'];
$t->assertEqualsMoney(40000, $sumLedgerBalance, 'reconciliation fixture: AccountingLedger balances sum to 40000');
$viewRowPre = $db->fetch('SELECT case_balance FROM v_patient_financial_summary WHERE patient_id = ?', [$pat]);
$t->assertEqualsMoney($sumLedgerBalance, (float)$viewRowPre['case_balance'], 'reconciliation: v_patient_financial_summary.case_balance equals summed AccountingLedger balances');

// ================================================================
// Corrupt the LEGACY mirror: cases.paid_amount on case A.
// ================================================================
$db->update('cases', ['paid_amount' => 999999], 'id = ?', [$caseA]);
// Propagate into the folder-level cache the same way the app's own
// Folder::updateTotals() already does elsewhere (CaseManager::updateCaseTotals
// calls it after every legitimate case-total change) — this is what a
// corrupted case mirror actually looks like once it has been aggregated
// upward, which is exactly the scenario the folder-level readers below
// (Patient.php, DebtCollection.php) must be immune to.
(new Folder())->updateTotals($foldA);

$corruptedFolder = $db->fetch('SELECT total_amount, paid_amount, discount_amount FROM patient_folders WHERE id = ?', [$foldA]);
$t->assertEqualsMoney(999999, $corruptedFolder['paid_amount'], 'fixture sanity: folder mirror now carries the corrupted case value');

// ================================================================
// Every repointed reader must now report the LEDGER number (40000 case
// part; 60000 with the 20000 x-ray debt added), NOT the mirror's.
// ================================================================

// -- PatientFinance / views (Task 2/9/10 — never read the mirror at all) --
$pf = new PatientFinance();
$t->assertEqualsMoney(40000, $pf->summary($pat)['case_balance'], 'PatientFinance ignores the corrupted mirror');
$t->assertEqualsMoney(45000, $pf->summary($pat)['net_debt'], 'PatientFinance net_debt = 40000 case + 20000 xray - 15000 credit (this is what api/patient-debt.php returns)');

$vrow = $db->fetch('SELECT case_balance FROM v_patient_financial_summary WHERE patient_id = ?', [$pat]);
$t->assertEqualsMoney(40000, $vrow['case_balance'], 'v_patient_financial_summary ignores the corrupted mirror');

$caseAView = $db->fetch('SELECT balance, paid_amount FROM v_case_financial_summary WHERE case_id = ?', [$caseA]);
$t->assertEqualsMoney(40000, $caseAView['balance'], 'v_case_financial_summary.balance ignores corrupted cases.paid_amount');
$t->assertEqualsMoney(60000, $caseAView['paid_amount'], 'v_case_financial_summary.paid_amount ignores corrupted cases.paid_amount');

// -- classes/Patient.php (Task 15 Step 2) --
$byId = $patientObj->getById($pat);
$t->assertEqualsMoney(40000, $byId['total_debt'], 'Patient::getById total_debt (case-only) reads the ledger, not the mirror');

$stats = $patientObj->getStatistics($pat);
$t->assertEqualsMoney(40000, $stats['total_debt'], 'Patient::getStatistics total_debt reads the ledger, not the mirror (was RED pre-repoint)');

$statsForDoc = $patientObj->getStatisticsForDoctor($pat, $doc);
$t->assertEqualsMoney(40000, $statsForDoc['total_debt'], 'Patient::getStatisticsForDoctor total_debt reads the ledger, not the mirror');

$foreignDoc = fx_user('doctor');
$statsForeign = $patientObj->getStatisticsForDoctor($pat, $foreignDoc);
$t->assertEqualsMoney(0, $statsForeign['total_debt'], 'Patient::getStatisticsForDoctor: a doctor who owns no folder here sees 0, ledger-scoped correctly');

$allRows = $patientObj->getAll(1, 50, $patientCode);
$t->assertSame(1, count($allRows), 'Patient::getAll search finds exactly the fixture patient');
$t->assertEqualsMoney(60000, $allRows[0]['total_debt'], 'Patient::getAll total_debt = ledger case_balance (40000) + canonical xray debt (20000)');

$countAll = $patientObj->count($patientCode);
$t->assertSame(1, (int)$countAll, 'Patient::count search finds exactly the fixture patient');

// has_debt filter, now sourced from the joined view — exercise both getAll()
// and count() with the search term + filter combined (watch-out from the brief).
$withDebtFilterRows = $patientObj->getAll(1, 50, $patientCode, 'created_at DESC', ['has_debt' => '1']);
$t->assertSame(1, count($withDebtFilterRows), 'getAll() has_debt=1 filter (view-sourced) still finds the patient after repoint');
$noDebtFilterRows = $patientObj->getAll(1, 50, $patientCode, 'created_at DESC', ['has_debt' => '0']);
$t->assertSame(0, count($noDebtFilterRows), 'getAll() has_debt=0 filter (view-sourced) correctly excludes a patient who has debt');
$countWithDebtFilter = $patientObj->count($patientCode, ['has_debt' => '1']);
$t->assertSame(1, (int)$countWithDebtFilter, 'count() has_debt=1 filter (view-sourced) still finds the patient after repoint');

// Doctor-scoped list + search combined (Patient::getDoctorPatients — brief's
// explicit watch-out: "keep the doctor-scoping and search filters working").
$docPatients = $patientObj->getDoctorPatients($doc, 1, 50, $patientCode);
$t->assertSame(1, count($docPatients), 'getDoctorPatients search finds exactly the fixture patient for the owning doctor');
$t->assertEqualsMoney(40000, $docPatients[0]['total_debt'] ?? -1, 'getDoctorPatients total_debt (case-only, doctor-scoped) reads the ledger');
$docPatientsFiltered = $patientObj->getDoctorPatients($doc, 1, 50, $patientCode, ['has_debt' => '1']);
$t->assertSame(1, count($docPatientsFiltered), 'getDoctorPatients search+has_debt filter (view-sourced) still finds the patient');
$docPatientsForeign = $patientObj->getDoctorPatients($foreignDoc, 1, 50, $patientCode);
$t->assertSame(0, count($docPatientsForeign), 'getDoctorPatients: a doctor who owns no folder here sees nothing, ledger-scoped correctly');

// -- Global aggregates (delta, per XrayDebtTest.php's pattern — other test
// files' fixtures share this database) --
$t->assertEqualsMoney($countWithDebtBefore + 1, $patientObj->countPatientsWithDebt(), 'countPatientsWithDebt: this patient now counted via the ledger view');
$t->assertEqualsMoney($totalDebtAmountBefore + 40000, $patientObj->getTotalDebtAmount(), 'getTotalDebtAmount: +40000 ledger case debt, not the corrupted mirror');

// -- classes/DebtCollection.php (Task 15 Step 2) --
// Post-merge follow-up #1: getDebtors() now nets total_debt against
// patient_credits.balance exactly like debts.php — this fixture's patient
// already carries a 15000 credit row (inserted above), so the expected
// figure here is 45000 (60000 gross - 15000 credit), not the old gross
// 60000, and credit_balance surfaces the raw credit for the UI.
$debtorRows = $debtCollection->getDebtors($patientCode);
$t->assertSame(1, count($debtorRows), 'DebtCollection::getDebtors search finds exactly the fixture patient');
$t->assertEqualsMoney(45000, $debtorRows[0]['total_debt'], 'DebtCollection::getDebtors total_debt = ledger case debt (40000) + canonical xray debt (20000) - credit (15000)');
$t->assertEqualsMoney(15000, $debtorRows[0]['credit_balance'], 'DebtCollection::getDebtors exposes credit_balance for the UI');
$t->assertEqualsMoney(45000, $debtorRows[0]['normal_debt'], 'DebtCollection::getDebtors normal_debt (no active installment plan here) matches the netted total_debt');

// Doctor-scoped variant.
$debtorRowsDoc = $debtCollection->getDebtors($patientCode, '', 'total_debt', 'DESC', '', '', '', '', '', $doc);
$t->assertSame(1, count($debtorRowsDoc), 'DebtCollection::getDebtors doctor-scoped search finds the patient for the owning doctor');
$t->assertEqualsMoney(45000, $debtorRowsDoc[0]['total_debt'], 'DebtCollection::getDebtors doctor-scoped total_debt reads the netted ledger figure');
$debtorRowsForeign = $debtCollection->getDebtors($patientCode, '', 'total_debt', 'DESC', '', '', '', '', '', $foreignDoc);
$t->assertSame(0, count($debtorRowsForeign), 'DebtCollection::getDebtors doctor-scoped: a doctor who owns no folder here sees nothing');

// normalDueDateSql / normalDueAmountSql (case-level mirror fragment, also
// repointed): give case A a due date with no active installment plan, and
// confirm the due-amount reads the ledger balance, not the corrupted mirror.
$db->update('cases', ['payment_due_date' => date('Y-m-d', strtotime('+3 days'))], 'id = ?', [$caseA]);
$dueRows = $debtCollection->getDebtors($patientCode);
$t->assertEqualsMoney(40000, $dueRows[0]['normal_due_amount'], 'normal_due_amount reads the ledger balance, not the corrupted cases.paid_amount mirror');
$t->assertSame(date('Y-m-d', strtotime('+3 days')), substr((string)$dueRows[0]['next_normal_due_date'], 0, 10), 'next_normal_due_date still finds the due case (ledger balance > 0)');

// getStatistics() global aggregates (delta). Post-merge follow-up #1: nets
// against the fixture's 15000 patient_credits row, so the delta is 45000
// (40000 case + 20000 xray - 15000 credit), not the old gross 60000.
$dcStatsAfter = $debtCollection->getStatistics();
$t->assertEqualsMoney(($dcStatsBefore['total_debt'] ?? 0) + 45000, $dcStatsAfter['total_debt'], 'DebtCollection::getStatistics total_debt: +40000 ledger case debt +20000 xray debt -15000 credit, not the corrupted mirror');
$t->assertSame(($dcStatsBefore['total_debtors'] ?? 0) + 1, $dcStatsAfter['total_debtors'], 'DebtCollection::getStatistics total_debtors: patient now counted via the ledger view');

// ================================================================
// Page-level SQL patterns: reconciliation checks that replicate the exact
// join shape now used in admin/invoices.php, debts.php and
// api/export-debts.php, run directly against the corrupted fixture — a
// guard against those pages drifting back onto the mirror.
// ================================================================

// admin/invoices.php pattern (case-level: v_case_financial_summary balance/paid_amount).
$invoicesPagePattern = $db->fetch(
    "SELECT COALESCE(fs.balance, 0) as remaining_amount, COALESCE(fs.paid_amount, 0) as ledger_paid_amount
     FROM cases c LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
     WHERE c.id = ?",
    [$caseA]
);
$t->assertEqualsMoney(40000, $invoicesPagePattern['remaining_amount'], 'admin/invoices.php pattern: remaining_amount from the view ignores the corrupted mirror');
$t->assertEqualsMoney(60000, $invoicesPagePattern['ledger_paid_amount'], 'admin/invoices.php pattern: ledger_paid_amount from the view ignores the corrupted mirror');

// api/export-debts.php pattern: the underlying v_patient_financial_summary
// join ignores the corrupted mirror regardless of the netting fix below.
$exportDebtsPagePattern = $db->fetch(
    "SELECT COALESCE(vfs.case_balance, 0) as folder_debt
     FROM patients p LEFT JOIN v_patient_financial_summary vfs ON vfs.patient_id = p.id
     WHERE p.id = ?",
    [$pat]
);
$t->assertEqualsMoney(40000, $exportDebtsPagePattern['folder_debt'], 'api/export-debts.php pattern: folder_debt from the view ignores the corrupted mirror');

// Review fix (Finding 4): api/export-debts.php's total_debt column now nets
// against patient_credits and uses the Finding-1 x-ray remaining expression
// exactly like debts.php — same formula, so it must agree with debts.php's
// total_debt (and PatientFinance's net_debt) for the same patient, not the
// old gross figure the export used to compute independently.
$exportDebtsTotalDebtSql =
    "SELECT GREATEST(0, COALESCE(vfs.case_balance, 0)
         + COALESCE((SELECT SUM(" . XrayManager::remainingAmountSql('xr', 'pxd') . ") FROM xray_requests xr " . XrayManager::remainingAmountJoinSql('xr', 'pxd') . " WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")), 0)
         - COALESCE(pc.balance, 0)) as total_debt
     FROM patients p
     LEFT JOIN v_patient_financial_summary vfs ON vfs.patient_id = p.id
     LEFT JOIN patient_credits pc ON pc.patient_id = p.id
     WHERE p.id = ?";
$exportDebtsTotalDebt = $db->fetch($exportDebtsTotalDebtSql, [$pat]);
$t->assertEqualsMoney(45000, $exportDebtsTotalDebt['total_debt'], 'api/export-debts.php pattern: total_debt nets against patient_credits (Finding 4) — agrees with debts.php, not the old gross figure');
$t->assertEqualsMoney($pf->summary($pat)['net_debt'], $exportDebtsTotalDebt['total_debt'], 'api/export-debts.php total_debt agrees exactly with PatientFinance net_debt for the same patient');

// debts.php pattern (review follow-up fix): total_debt = GREATEST(0, case
// balance + xray debt - patient_credits.balance), the exact formula now in
// debts.php's SQL — must agree with PatientFinance's net_debt (45000) for
// the same patient, not the old gross 60000. The x-ray part uses
// XrayManager::remainingAmountSql()/remainingAmountJoinSql() (Finding 1) —
// no partial x-ray collection exists in this fixture, so it evaluates
// identically to a bare final_amount sum here, but this keeps the pattern a
// true mirror of debts.php's actual SQL rather than a stale duplicate of it.
$debtsPagePatternSql =
    "SELECT GREATEST(0, COALESCE(vfs.case_balance, 0) +
         COALESCE((SELECT SUM(" . XrayManager::remainingAmountSql('xr', 'pxd') . ") FROM xray_requests xr " . XrayManager::remainingAmountJoinSql('xr', 'pxd') . " WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")), 0)
         - COALESCE(pc.balance, 0)) as total_debt
     FROM patients p
     LEFT JOIN v_patient_financial_summary vfs ON vfs.patient_id = p.id
     LEFT JOIN patient_credits pc ON pc.patient_id = p.id
     WHERE p.id = ?";
$debtsPagePattern = $db->fetch($debtsPagePatternSql, [$pat]);
$t->assertEqualsMoney(45000, $debtsPagePattern['total_debt'], 'debts.php pattern: total_debt nets against patient_credits (review fix) — agrees with PatientFinance net_debt, not the old gross 60000');
$t->assertEqualsMoney($pf->summary($pat)['net_debt'], $debtsPagePattern['total_debt'], 'debts.php total_debt agrees exactly with api/patient-debt.php (PatientFinance net_debt) for the same patient');

// api/patient-debt.php pattern: both branches now delegate straight to
// PatientFinance::summary()['net_debt'] (already exercised above), staff
// (unscoped) and doctor-scoped versions must agree given $doc owns everything here.
$t->assertEqualsMoney($pf->summary($pat)['net_debt'], $pf->summary($pat, $doc)['net_debt'], 'api/patient-debt.php pattern: unscoped and owning-doctor net_debt agree (sole doctor on this patient)');

// ---- Review follow-up regression: a patient whose credit balance fully
// covers their debt must drop off the debtors list entirely (net <= 0),
// matching PatientFinance's max(0, ...) floor and api/patient-debt.php
// returning 0 — not just show a wrong (or negative) number. ----
$patCovered = fx_patient();
$foldCovered = fx_folder($patCovered, $doc);
$caseCovered = fx_case($foldCovered);
$invCovered = fx_invoice($caseCovered, $doc, 'sent', 'approved', 20000);
fx_treatment($caseCovered, 20000, $invCovered);
(new CaseManager())->updateCaseTotals($caseCovered);
$db->insert('patient_credits', ['patient_id' => $patCovered, 'balance' => 25000]); // covers the 20000 debt with room to spare

$coveredPattern = $db->fetch($debtsPagePatternSql, [$patCovered]);
$t->assertEqualsMoney(0, $coveredPattern['total_debt'], 'debts.php pattern: credit fully covering the debt floors total_debt at 0 (GREATEST(0, ...))');
$t->assertEqualsMoney(0, $pf->summary($patCovered)['net_debt'], 'PatientFinance net_debt agrees: fully covered by credit');

// Same query, but with the real HAVING total_debt > 0 clause debts.php uses
// — proves the row actually disappears from the debtor list, not just that
// the computed figure would be <= 0.
$coveredHavingRows = $db->fetchAll(
    $debtsPagePatternSql . " HAVING total_debt > 0",
    [$patCovered]
);
$t->assertSame(0, count($coveredHavingRows), 'debts.php pattern: HAVING total_debt > 0 excludes a patient fully covered by credit (matches api/patient-debt.php returning 0)');

// Contrast: the main fixture patient (net debt 45000, credit does not cover
// it) must still pass the same HAVING filter and stay on the list.
$mainHavingRows = $db->fetchAll(
    $debtsPagePatternSql . " HAVING total_debt > 0",
    [$pat]
);
$t->assertSame(1, count($mainHavingRows), 'debts.php pattern: HAVING total_debt > 0 still includes a patient whose credit does not fully cover their debt');

// Finding 4: the same HAVING-nets-too behavior must hold for the (now
// identically-formulated) api/export-debts.php query — a credit-covered
// patient drops out of the export exactly as they drop out of debts.php.
$exportCoveredHavingRows = $db->fetchAll(
    $exportDebtsTotalDebtSql . " HAVING total_debt > 0",
    [$patCovered]
);
$t->assertSame(0, count($exportCoveredHavingRows), 'api/export-debts.php pattern: HAVING total_debt > 0 excludes a patient fully covered by credit');
