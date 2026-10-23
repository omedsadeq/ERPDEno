<?php
require_once __DIR__ . '/../../classes/ApprovalQueue.php';
require_once __DIR__ . '/../../classes/PatientAdvance.php';

(function () use ($t) {
    $db = Database::getInstance();
    $doctor = fx_user('doctor');
    $accountant = fx_user('accountant');
    $patientId = fx_patient();
    $manager = new PatientAdvance();
    $queue = new ApprovalQueue();
    $received = $manager->receive($patientId, 100000, 'cash', 'Old debt receipt', $accountant, $doctor);
    $t->assertTrue($received['success'], 'fixture receipt received before a case exists');
    $id = (int)$received['advance_id'];
    $auditBefore = (int)$db->count('activity_log');
    $receiptsBefore = (int)$db->count('patient_advances');
    $rows = $queue->list('advances', ['doctor_id' => $doctor]);
    $t->assertSame(1, count($rows), 'old-debt list contains receipt');
    $detail = $queue->detail($rows[0]['kind'], (int)$rows[0]['id']);
    $t->assertTrue($detail['success'], 'listed advance kind has a supported detail endpoint');
    $t->assertSame('advance', $detail['kind'], 'detail keeps advance kind');
    $t->assertSame($patientId, $detail['patient_id'], 'correct patient');
    $t->assertSame($received['advance_no'], $detail['advance']['advance_no'], 'correct receipt number');
    $t->assertSame('Old debt receipt', $detail['advance']['notes'], 'receipt notes included');
    $t->assertSame('open', $detail['advance']['status'], 'unallocated receipt opens');
    $t->assertEqualsMoney(100000, $detail['advance']['available_amount'], 'all money initially available');
    $t->assertEqualsMoney(0, $detail['advance']['allocated_amount'], 'no allocation without a case');
    $t->assertSame([], $detail['allocations'], 'no-case receipt needs no allocation');
    $t->assertSame($auditBefore, (int)$db->count('activity_log'), 'opening details writes no audit or money');
    $t->assertSame($receiptsBefore, (int)$db->count('patient_advances'), 'opening creates no duplicate receipt');

    $caseId = fx_case(fx_folder($patientId, $doctor));
    $invoiceId = fx_invoice($caseId, $doctor, 'draft', 'draft', 100000);
    fx_treatment($caseId, 100000, $invoiceId);
    $t->assertTrue(!$manager->allocate($invoiceId, 40000, $doctor)['success'], 'doctor cannot move custody');
    $t->assertTrue($manager->allocate($invoiceId, 40000, $accountant)['success'], 'part of receipt allocated');
    $partial = $queue->detail('advance', $id);
    $t->assertSame('partial', $partial['advance']['status'], 'partial allocation shown');
    $t->assertEqualsMoney(40000, $partial['advance']['allocated_amount'], 'used amount shown');
    $t->assertEqualsMoney(60000, $partial['advance']['available_amount'], 'remaining amount shown');
    $t->assertSame($invoiceId, (int)$partial['allocations'][0]['invoice_id'], 'correct invoice allocation shown');
    $t->assertSame($caseId, (int)$partial['allocations'][0]['case_id'], 'correct case shown');
    $t->assertTrue($manager->allocate($invoiceId, 60000, $accountant)['success'], 'remaining receipt allocated');
    $full = $queue->detail('advance', $id);
    $t->assertSame('allocated', $full['advance']['status'], 'full allocation shown');
    $t->assertEqualsMoney(0, $full['advance']['available_amount'], 'fully used receipt has no balance');

    $t->assertTrue($manager->reverseForInvoice($invoiceId, $accountant)['success'], 'invoice allocations reversed');
    $reversed = $queue->detail('advance', $id);
    $t->assertSame('open', $reversed['advance']['status'], 'reversed allocations return receipt to open');
    $t->assertEqualsMoney(0, $reversed['advance']['allocated_amount'], 'reversed rows do not count as spent');
    $t->assertEqualsMoney(100000, $reversed['advance']['available_amount'], 'released amount available again');
    $t->assertTrue(!empty($reversed['allocations'][0]['reversed_at']), 'reversed allocation history retained');
    $t->assertTrue($manager->refund($id, 100000, 'Refund test', $accountant)['success'], 'fixture receipt refunded');
    $refunded = $queue->detail('advance', $id);
    $t->assertSame('refunded', $refunded['advance']['status'], 'refund state shown');
    $t->assertEqualsMoney(100000, $refunded['advance']['refunded_amount'], 'refund amount shown');
    $t->assertEqualsMoney(0, $refunded['advance']['available_amount'], 'refunded money no longer available');
    $t->assertSame('Refund test', $refunded['advance']['refund_reason'], 'refund reason shown');
    $t->assertSame($queue->list('advances', ['doctor_id' => $doctor])[0]['status'], $refunded['advance']['status'], 'list and detail status agree');

    $legacy = $manager->receive($patientId, 50000, 'card', null, $accountant);
    $legacyDetail = $queue->detail('advance', (int)$legacy['advance_id']);
    $t->assertTrue($legacyDetail['success'], 'older receipt without doctor opens');
    $t->assertSame(null, $legacyDetail['advance']['doctor_name'], 'missing doctor remains optional');
    $t->assertSame(false, $queue->detail('advance', 0)['success'], 'missing receipt fails gracefully');
    $t->assertSame(false, $queue->detail('unknown', $id)['success'], 'unknown type still rejected');
})();
