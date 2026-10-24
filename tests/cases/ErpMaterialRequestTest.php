<?php // tests/cases/ErpMaterialRequestTest.php
// داواکاری مەواد — a doctor asks the store for materials from a case; the
// store approves (creating the مەواد بۆ دکتۆر voucher: stock out, doctor
// charged) or rejects; the doctor may cancel while pending. Pinned: who the
// request is for, merged lines, partial approval, all-or-nothing on a
// shortage, no double approval, reject/cancel rules, closed cases refused.
require_once __DIR__ . '/../../classes/ErpMaterialRequest.php';

$db = Database::getInstance();
$tag = substr(uniqid(), -6);
$doctor = fx_user('doctor');
$otherDoctor = fx_user('doctor');
$admin = fx_user('admin');
$storeUser = fx_user('accountant');
$_SESSION['user_id'] = $storeUser;

$patient = fx_patient();
$case = fx_case(fx_folder($patient, $doctor));
$db->update('cases', ['status' => 'open'], 'id = :id', ['id' => $case]);
$closedCase = fx_case(fx_folder(fx_patient(), $doctor));
$db->update('cases', ['status' => 'closed'], 'id = :id', ['id' => $closedCase]);

ErpDoctorIssue::setPriceMode('cost');
$wh = ErpMaterialRequest::warehouseId();
$t->assertTrue($wh > 0, 'a default warehouse exists to fill requests from');
$product = function ($name, $cost) use ($db, $tag) {
    static $n = 0; $n++;
    return (int)$db->insert('erp_products', ['product_code' => 'MR' . $tag . $n, 'name' => $name, 'purchase_price' => $cost, 'is_active' => 1]);
};
$gloves = $product('Gloves ' . $tag, 1000);
$bur = $product('Bur ' . $tag, 5000);
$db->beginTransaction();
ErpStock::receive($gloves, $wh, 10, 1000, 'PURCHASE', null, 'TEST');
ErpStock::receive($bur, $wh, 2, 5000, 'PURCHASE', null, 'TEST');
$db->commit();
$level = fn($pid) => (float)ErpStock::levelOf($pid, $wh)['quantity'];
$status = fn($id) => $db->fetch('SELECT status FROM erp_material_requests WHERE id = :id', ['id' => $id])['status'];
$throws = function (callable $fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } };

// ── filing ──
$t->assertTrue($throws(fn() => ErpMaterialRequest::create($case, [], null, $doctor)), 'an empty request is refused');
$t->assertTrue($throws(fn() => ErpMaterialRequest::create($closedCase, [['product_id' => $gloves, 'quantity' => 1]], null, $doctor)),
    'a closed case cannot request materials');
$sheet = ErpMaterialRequest::sheet();
$found = false;
foreach ($sheet as $g) foreach ($g['items'] as $it) if ($it['id'] === $gloves) { $found = $it; }
$t->assertTrue($found && (float)$found['stock'] === 10.0, 'the sheet lists the material with its warehouse stock');

$r1 = ErpMaterialRequest::create($case, [
    ['product_id' => $gloves, 'quantity' => 2], ['product_id' => $gloves, 'quantity' => 1], ['product_id' => $bur, 'quantity' => 1],
], 'for crown prep', $doctor);
$req1 = $db->fetch('SELECT * FROM erp_material_requests WHERE id = :id', ['id' => $r1['id']]);
$t->assertSame('pending', $req1['status'], 'a new request is pending');
$t->assertSame($doctor, (int)$req1['doctor_id'], 'a doctor files the request in their own name');
$t->assertSame($patient, (int)$req1['patient_id'], 'the request carries the case\'s patient');
$lines = $db->fetchAll('SELECT product_id, quantity FROM erp_material_request_items WHERE request_id = :id ORDER BY product_id', ['id' => $r1['id']]);
$t->assertSame(2, count($lines), 'the same material twice is one line');
$t->assertSame(3.0, (float)$lines[0]['quantity'], 'its quantities are summed');
$t->assertSame(10.0, $level($gloves), 'nothing is reserved while pending');

$r2 = ErpMaterialRequest::create($case, [['product_id' => $bur, 'quantity' => 1]], null, $admin);
$t->assertSame($doctor, (int)$db->fetch('SELECT doctor_id FROM erp_material_requests WHERE id = :id', ['id' => $r2['id']])['doctor_id'],
    'filed by an admin, the request is for the case\'s doctor');
$t->assertSame(2, count(ErpMaterialRequest::forCase($case)), 'the case lists its requests');
$queueIds = array_map(fn($r) => (int)$r['id'], ErpMaterialRequest::queue('pending'));
$t->assertTrue(in_array($r1['id'], $queueIds, true) && in_array($r2['id'], $queueIds, true), 'both wait in the store\'s queue');

// ── approving ──
$items1 = $db->fetchAll('SELECT id, product_id FROM erp_material_request_items WHERE request_id = :id', ['id' => $r1['id']]);
$itemOf = []; foreach ($items1 as $it) { $itemOf[(int)$it['product_id']] = (int)$it['id']; }

$t->assertTrue($throws(fn() => ErpMaterialRequest::approve($r1['id'], [$itemOf[$gloves] => 0, $itemOf[$bur] => 0], 'normal', $storeUser)),
    'approving nothing is refused');
$t->assertSame('pending', $status($r1['id']), '… and the request stays pending');
$t->assertTrue($throws(fn() => ErpMaterialRequest::approve($r1['id'], [$itemOf[$bur] => 5], 'normal', $storeUser)),
    'more than the stock is refused');
$t->assertSame('pending', $status($r1['id']), '… the request stays pending');
$t->assertSame(10.0, $level($gloves), '… and no stock moved (all or nothing)');

$res = ErpMaterialRequest::approve($r1['id'], [$itemOf[$gloves] => 2], 'normal', $storeUser);  // bur as requested (1)
$req1 = $db->fetch('SELECT * FROM erp_material_requests WHERE id = :id', ['id' => $r1['id']]);
$t->assertSame('approved', $req1['status'], 'approved');
$t->assertSame((int)$res['issue_id'], (int)$req1['issue_id'], 'the request keeps its voucher');
$t->assertSame(8.0, $level($gloves), 'the trimmed quantity (2 of 3) left the store');
$t->assertSame(1.0, $level($bur), 'the untouched line left as requested');
$issue = $db->fetch('SELECT * FROM erp_doctor_issues WHERE id = :id', ['id' => $res['issue_id']]);
$t->assertSame($doctor, (int)$issue['doctor_id'], 'the voucher is in the doctor\'s name');
$t->assertSame('confirmed', $issue['status'], 'and confirmed');
$expense = $db->fetch("SELECT * FROM expenses_store WHERE source_type = 'erp_doctor_issue' AND source_id = :s", ['s' => $res['issue_id']]);
$t->assertEqualsMoney(7000, $expense['amount'], 'the doctor is charged 2 × 1,000 + 1 × 5,000 (cost mode)');
$appr = $db->fetch('SELECT approved_quantity FROM erp_material_request_items WHERE id = :id', ['id' => $itemOf[$gloves]]);
$t->assertSame(2.0, (float)$appr['approved_quantity'], 'the approved quantity is kept on the line');
$t->assertTrue($throws(fn() => ErpMaterialRequest::approve($r1['id'], [], 'normal', $storeUser)), 'a request cannot be approved twice');
$t->assertSame(8.0, $level($gloves), '… and stock did not move again');

// ── rejecting and cancelling ──
$t->assertTrue($throws(fn() => ErpMaterialRequest::reject($r2['id'], '   ', $storeUser)), 'rejecting needs a reason');
ErpMaterialRequest::reject($r2['id'], 'out of season', $storeUser);
$req2 = $db->fetch('SELECT * FROM erp_material_requests WHERE id = :id', ['id' => $r2['id']]);
$t->assertSame('rejected', $req2['status'], 'rejected');
$t->assertSame('out of season', $req2['decision_note'], 'with the store\'s reason');
$t->assertTrue($throws(fn() => ErpMaterialRequest::approve($r2['id'], [], 'normal', $storeUser)), 'a rejected request cannot be approved');

$r3 = ErpMaterialRequest::create($case, [['product_id' => $gloves, 'quantity' => 1]], null, $doctor);
$t->assertTrue($throws(fn() => ErpMaterialRequest::cancel($r3['id'], $otherDoctor, false)), 'another doctor cannot cancel it');
ErpMaterialRequest::cancel($r3['id'], $doctor, false);
$t->assertSame('cancelled', $status($r3['id']), 'the doctor cancels their own pending request');
$t->assertTrue($throws(fn() => ErpMaterialRequest::cancel($r3['id'], $doctor, false)), 'a cancelled request cannot be cancelled again');
$t->assertSame(8.0, $level($gloves), 'reject and cancel never touch stock');

$audit = $db->fetchAll("SELECT action FROM erp_audit_logs WHERE doc_type = 'MATERIAL_REQUEST' AND doc_id IN (:a, :b, :c) ORDER BY id",
    ['a' => $r1['id'], 'b' => $r2['id'], 'c' => $r3['id']]);
$t->assertSame(['material_request', 'material_request', 'material_request_approve', 'material_request_reject', 'material_request', 'material_request_cancel'],
    array_column($audit, 'action'), 'every step is in the store\'s audit log');
