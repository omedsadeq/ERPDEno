<?php // tests/cases/BugfixTest.php
$db = Database::getInstance();
require_once __DIR__ . '/../../classes/AuditLog.php';
require_once __DIR__ . '/../../classes/AccountingLedger.php';
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/Installment.php';

$t->assertSame([1, 1, 1, 1, 1], CaseManager::splitBulkQuantity(5, 5), 'bulk qty 5 across 5 teeth stays total qty 5');
$t->assertSame([2, 1, 1, 1, 1], CaseManager::splitBulkQuantity(6, 5), 'extra bulk quantity is distributed without multiplication');
$t->assertThrows(fn() => CaseManager::splitBulkQuantity(4, 5), 'bulk quantity cannot be less than selected teeth');

$bulkDoc = fx_user('doctor');
$bulkPatient = fx_patient();
$bulkFolder = fx_folder($bulkPatient, $bulkDoc);
$bulkCase = fx_case($bulkFolder);
$bulkInvoice = fx_invoice($bulkCase, $bulkDoc, 'draft', 'draft', 0);
$bulkManager = new CaseManager();
$bulkQuantities = CaseManager::splitBulkQuantity(5, 5);
foreach ([11, 12, 13, 14, 15] as $bulkIndex => $bulkTooth) {
    $bulkResult = $bulkManager->addTreatment($bulkCase, [
        'invoice_id' => $bulkInvoice,
        'tooth_number' => $bulkTooth,
        'treatment_name' => 'Bulk quantity regression',
        'quantity' => $bulkQuantities[$bulkIndex],
        'unit_price' => 35000,
        'performed_by' => $bulkDoc,
    ]);
    $t->assertTrue(!empty($bulkResult['success']), 'bulk tooth row saves');
}
$bulkSaved = $db->fetch(
    'SELECT SUM(quantity) AS qty, SUM(final_price) AS total FROM case_treatments WHERE case_id=:case_id AND invoice_id=:invoice_id',
    ['case_id' => $bulkCase, 'invoice_id' => $bulkInvoice]
);
$t->assertSame(5, (int)$bulkSaved['qty'], 'saved bulk group preserves visible quantity 5');
$t->assertEqualsMoney(175000, $bulkSaved['total'], 'saved bulk group preserves visible 5 x 35000 total');

$orderCase = fx_case($bulkFolder);
$olderTreatment = fx_treatment($orderCase, 10000);
$newerTreatment = fx_treatment($orderCase, 20000);
$orderedTreatments = $bulkManager->getTreatments($orderCase);
$t->assertSame($olderTreatment, (int)$orderedTreatments[0]['id'], 'oldest treatment remains the first table row');
$t->assertSame($newerTreatment, (int)$orderedTreatments[1]['id'], 'new treatment is appended as the last table row');

// --- Toothless services and whole-arch work are single jobs ---
$toothRuleColumn = $db->fetch("SHOW COLUMNS FROM treatments LIKE 'requires_tooth_number'");
$t->assertTrue(!empty($toothRuleColumn), 'treatment catalog stores whether a tooth number is required');
$t->assertSame('1', (string)$toothRuleColumn['Default'], 'tooth number remains required by default');

$serviceCase = fx_case($bulkFolder);
$serviceInvoice = fx_invoice($serviceCase, $bulkDoc, 'draft', 'draft', 0);
$serviceCatalogId = (int)$db->insert('treatments', [
    'treatment_code' => 'TS' . substr(uniqid(), -12),
    'treatment_name' => 'Cleaning without tooth',
    'category' => 'cleaning',
    'scope' => 'tooth',
    'requires_tooth_number' => 0,
    'default_price' => 25000,
    'is_active' => 1,
]);
$serviceResult = $bulkManager->addTreatment($serviceCase, [
    'invoice_id' => $serviceInvoice,
    'treatment_id' => $serviceCatalogId,
    'tooth_number' => null,
    'arch_type' => 'none',
    'treatment_name' => 'Cleaning without tooth',
    'quantity' => 2,
    'unit_price' => 25000,
    'performed_by' => $bulkDoc,
]);
$t->assertTrue(!empty($serviceResult['success']), 'toothless service saves without a tooth number');
$serviceSaved = $db->fetch('SELECT tooth_number,quantity,final_price FROM case_treatments WHERE id=?', [$serviceResult['treatment_id']]);
$t->assertSame(null, $serviceSaved['tooth_number'], 'toothless service keeps tooth_number NULL');
$t->assertSame(2, (int)$serviceSaved['quantity'], 'toothless service keeps job quantity');
$t->assertEqualsMoney(50000, $serviceSaved['final_price'], 'toothless service price is quantity x unit price');

$archCase = fx_case($bulkFolder);
$archInvoice = fx_invoice($archCase, $bulkDoc, 'draft', 'draft', 0);
$archResult = $bulkManager->addArchTreatment($archCase, [
    'invoice_id' => $archInvoice,
    'treatment_name' => 'Whole arch job',
    'upper_unit_price' => 120000,
    'lower_unit_price' => 150000,
    'performed_by' => $bulkDoc,
], 'both');
$t->assertTrue(!empty($archResult['success']), 'both arches save successfully');
$t->assertSame(2, (int)$archResult['count'], 'both arches create two jobs, not thirty-two tooth rows');
$archSaved = $db->fetchAll('SELECT tooth_number,arch_type,quantity,final_price,invoice_id FROM case_treatments WHERE case_id=? ORDER BY id', [$archCase]);
$t->assertSame(2, count($archSaved), 'whole-arch work has one row per selected arch');
$t->assertSame(null, $archSaved[0]['tooth_number'], 'upper arch job has no synthetic tooth number');
$t->assertSame('upper', $archSaved[0]['arch_type'], 'upper arch location is preserved');
$t->assertEqualsMoney(120000, $archSaved[0]['final_price'], 'upper arch uses its direct job price');
$t->assertSame(null, $archSaved[1]['tooth_number'], 'lower arch job has no synthetic tooth number');
$t->assertSame('lower', $archSaved[1]['arch_type'], 'lower arch location is preserved');
$t->assertEqualsMoney(150000, $archSaved[1]['final_price'], 'lower arch uses its direct job price');
$t->assertSame($archInvoice, (int)$archSaved[1]['invoice_id'], 'whole-arch work remains on the selected draft receipt');

// --- getActivity includes treatment rows ---
$doc = fx_user('doctor'); $pat = fx_patient(); $fold = fx_folder($pat, $doc); $case = fx_case($fold);
$tr = fx_treatment($case, 50000);
$db->insert('activity_log', ['table_name' => 'case_treatments', 'record_id' => $tr, 'action' => 'insert',
    'user_id' => $doc, 'created_at' => date('Y-m-d H:i:s')]);   // match activity_log's real columns (see AuditLog::log)
$ledger = new AccountingLedger();
$acts = $ledger->getActivity($case, 50);
$found = false; foreach ($acts as $a) { if (($a['table_name'] ?? '') === 'case_treatments') $found = true; }
$t->assertTrue($found, 'getActivity returns case_treatments rows');

// --- updateCaseTotals excludes cancelled treatments ---
$case2 = fx_case($fold);
fx_treatment($case2, 100000);
$cancelled = fx_treatment($case2, 40000);
$db->update('case_treatments', ['status' => 'cancelled'], 'id = ?', [$cancelled]);
$cm = new CaseManager();
$cm->updateCaseTotals($case2);
$row = $db->fetch("SELECT total_amount FROM cases WHERE id = ?", [$case2]);
$t->assertEqualsMoney(100000, $row['total_amount'], 'cancelled treatment excluded from case total');

// --- updateCaseTotals paid = approved allocations + approved unallocated payments ---
$case3 = fx_case($fold);
fx_treatment($case3, 100000);
$inv3 = fx_invoice($case3, $doc, 'sent', 'approved', 100000);
// approved payment, allocated to the invoice
$approvedAllocatedPaymentId = (int) $db->insert('payments', [
    'case_id' => $case3, 'payment_number' => 'TPAY' . $case3 . 'A_' . substr(uniqid(), -6),
    'payment_type' => 'cash', 'amount' => 30000, 'payment_method' => 'cash',
    'status' => 'approved', 'received_by' => $doc, 'payment_date' => date('Y-m-d'),
]);
$db->insert('payment_allocations', [
    'payment_id' => $approvedAllocatedPaymentId, 'invoice_id' => $inv3, 'allocated_amount' => 30000,
]);
// approved payment, no allocation row (legacy unallocated) — should still count
$approvedUnallocatedPaymentId = (int) $db->insert('payments', [
    'case_id' => $case3, 'payment_number' => 'TPAY' . $case3 . 'B_' . substr(uniqid(), -6),
    'payment_type' => 'cash', 'amount' => 20000, 'payment_method' => 'cash',
    'status' => 'approved', 'received_by' => $doc, 'payment_date' => date('Y-m-d'),
]);
// pending payment — must NOT count toward paid
$db->insert('payments', [
    'case_id' => $case3, 'payment_number' => 'TPAY' . $case3 . 'C_' . substr(uniqid(), -6),
    'payment_type' => 'cash', 'amount' => 15000, 'payment_method' => 'cash',
    'status' => 'pending', 'received_by' => $doc, 'payment_date' => date('Y-m-d'),
]);
$cm->updateCaseTotals($case3);
$row3 = $db->fetch("SELECT paid_amount FROM cases WHERE id = ?", [$case3]);
$t->assertEqualsMoney(50000, $row3['paid_amount'], 'paid = approved allocations + approved unallocated payments, pending excluded');

// --- Installment::autoAllocatePayment removed ---
$t->assertTrue(!method_exists('Installment', 'autoAllocatePayment'), 'dead autoAllocatePayment method removed');
