<?php // tests/cases/XrayRedateTest.php
// X-ray money on the day the patient really paid: the approval may come a day
// later, and an approved request's date, type and note are corrected in place
// (XrayManager::parseMoneyDate, approve…($paidAt), editApprovedSettlement
// paid_at, editApprovedDetails) — never by deleting and making it again.
$db = Database::getInstance();
require_once __DIR__ . '/../../classes/XrayManager.php';
require_once __DIR__ . '/../../classes/Settings.php';

$doc = fx_user('doctor');
$acc = fx_user('accountant');
$pat = fx_patient();
$xm = new XrayManager();

$editKey = Settings::ALLOW_EDIT_APPROVED_INVOICE;
$editWas = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = ?", [$editKey]);
Settings::set($editKey, '1');

function xrd_request($db, $pat, $doc, $amount, $type = 'panoramic', $pay = 'pending_payment') {
    return (int)$db->insert('xray_requests', [
        'request_number'  => 'TXR' . substr(uniqid(), -8) . rand(10, 99),
        'patient_id'      => $pat,
        'requested_by'    => $doc,
        'xray_type'       => $type,
        'xray_reason'     => 'pathology',
        'status'          => 'pending',
        'request_status'  => 'pending_accountant',
        'payment_status'  => $pay,
        'amount'          => $amount,
        'original_amount' => $amount,
        'final_amount'    => $amount,
    ]);
}
$row = fn($id) => $db->fetch("SELECT * FROM xray_requests WHERE id = ?", [$id]);
$day = fn($n) => date('Y-m-d', strtotime("-{$n} day"));

// ── the typed date ──
$t->assertSame(null, XrayManager::parseMoneyDate(''), 'empty: now');
$t->assertSame('2026-01-05 09:30:00', XrayManager::parseMoneyDate('2026-01-05T09:30'), 'the browser\'s datetime-local');
$t->assertSame('2026-01-05 12:00:00', XrayManager::parseMoneyDate('2026-01-05'), 'a bare date: midday');
foreach (['abc', '2026-13-40 10:00', date('Y-m-d H:i', strtotime('+2 days'))] as $bad) {
    $threw = false;
    try { XrayManager::parseMoneyDate($bad); } catch (InvalidArgumentException $e) { $threw = true; }
    $t->assertTrue($threw, 'refused: ' . $bad);
}

// ── paid yesterday, approved today: the cash is yesterday's ──
$id = xrd_request($db, $pat, $doc, 50000);
$yesterday = $day(1) . ' 10:30:00';
$res = $xm->approvePaymentAndSendToXray($id, $acc, 'accountant', 'test', $yesterday);
$t->assertTrue(!empty($res['success']), 'approved with the day the patient paid');
$r = $row($id);
$t->assertSame($yesterday, $r['paid_at'], 'paid_at is the day the patient paid');
$t->assertSame(date('Y-m-d'), substr($r['accountant_approved_at'], 0, 10), 'the approval itself is still stamped today');
$cashY = $xm->getCashIncome($day(1), $day(1));
$t->assertTrue($cashY >= 50000, 'the cash counts on yesterday');

// ── corrected after approval: two days earlier ──
$earlier = $day(3) . ' 09:15';
$before3 = $xm->getCashIncome($day(3), $day(3));
$res = $xm->editApprovedSettlement($id, ['paid_at' => $earlier], $acc, 'accountant');
$t->assertTrue(!empty($res['success']) && empty($res['unchanged']), 'the money date moves on an approved request');
$r = $row($id);
$t->assertSame($day(3) . ' 09:15:00', $r['paid_at'], 'paid_at moved');
$t->assertSame('paid', $r['payment_status'], 'still paid');
$t->assertEqualsMoney($before3 + 50000, $xm->getCashIncome($day(3), $day(3)), 'the cash is now on that day');
$t->assertEqualsMoney($cashY - 50000, $xm->getCashIncome($day(1), $day(1)), 'and no longer on yesterday');
$t->assertSame(date('Y-m-d'), substr($r['accountant_approved_at'], 0, 10), 'the approval date is untouched');
$hist = $db->fetch("SELECT action_note FROM xray_request_history WHERE request_id = ? AND action_type = 'accountant_edited' ORDER BY id DESC LIMIT 1", [$id]);
$t->assertTrue($hist && strpos($hist['action_note'], 'ڕێکەوتی پارە') !== false, 'the history says the date moved');
$res = $xm->editApprovedSettlement($id, ['paid_at' => $earlier], $acc, 'accountant');
$t->assertTrue(!empty($res['unchanged']), 'the same date again: nothing to do');
$res = $xm->editApprovedSettlement($id, ['paid_at' => date('Y-m-d H:i', strtotime('+3 days'))], $acc, 'accountant');
$t->assertTrue(empty($res['success']), 'a future date is refused');
$t->assertEqualsMoney(50000, (float)$xm->approvedMoneyState($id)['received'], 'the money is unchanged');
$t->assertSame($day(3) . ' 09:15:00', $xm->approvedMoneyState($id)['money_at'], 'the reading gives the money date');

// ── part paid: the counter cash row moves ──
$id2 = xrd_request($db, $pat, $doc, 60000, 'cbct');
$res = $xm->approvePartialAndSendToXray($id2, 20000, $acc, 'accountant', '', $day(1) . ' 11:00:00');
$t->assertTrue(!empty($res['success']), 'part paid, approved later');
$p = $db->fetch("SELECT p.* FROM xray_debt_payments p JOIN patient_xray_debts d ON d.id = p.debt_id WHERE d.request_id = ?", [$id2]);
$t->assertSame($day(1) . ' 11:00:00', $p['created_at'], 'the counter cash is dated the day it was paid');
$t->assertSame($day(1) . ' 11:00:00', $xm->approvedMoneyState($id2)['money_at'], 'and the form reads that day');
$res = $xm->editApprovedSettlement($id2, ['paid_at' => $day(4) . ' 08:00', 'received_amount' => 20000], $acc, 'accountant');
$t->assertTrue(!empty($res['success']), 'its date corrected');
$p = $db->fetch("SELECT p.* FROM xray_debt_payments p WHERE p.id = ?", [(int)$p['id']]);
$t->assertSame($day(4) . ' 08:00:00', $p['created_at'], 'the counter cash row moved');
$t->assertEqualsMoney(20000, (float)$p['amount'], 'with the same amount');
$t->assertEqualsMoney(40000, (float)$xm->approvedMoneyState($id2)['remaining'], 'what is owed is unchanged');
// made fully paid on the moved day: cash, on that day
$res = $xm->editApprovedSettlement($id2, ['received_amount' => 60000], $acc, 'accountant');
$r2 = $row($id2);
$t->assertTrue(!empty($res['success']) && $r2['payment_status'] === 'paid', 'all of it now: cash');
$t->assertSame($day(4) . ' 08:00:00', $r2['paid_at'], 'on the day the counter cash was moved to');

// ── the request's own details ──
$res = $xm->editApprovedDetails($id, ['requested_at' => $day(3) . ' 08:45', 'xray_type' => 'cbct', 'notes' => 'ڕاستکراوە'], $acc, 'accountant');
$t->assertTrue(!empty($res['success']), 'date, type and note corrected');
$r = $row($id);
$t->assertSame($day(3) . ' 08:45:00', $r['created_at'], 'the request date');
$t->assertSame('cbct', $r['xray_type'], 'the type');
$t->assertSame('ڕاستکراوە', $r['notes'], 'the note');
$t->assertEqualsMoney(50000, (float)$r['final_amount'], 'the money untouched');
$res = $xm->editApprovedDetails($id, ['xray_type' => 'nonsense'], $acc, 'accountant');
$t->assertTrue(empty($res['success']), 'an unknown type is refused');
$res = $xm->editApprovedDetails($id, ['xray_type' => 'cbct', 'notes' => 'ڕاستکراوە'], $acc, 'accountant');
$t->assertTrue(!empty($res['unchanged']), 'the same again: nothing to do');
$res = $xm->editApprovedDetails($id, ['notes' => ''], $acc, 'accountant');
$t->assertTrue($row($id)['notes'] === null, 'a note can be cleared');

// a free request, approved: its details change too
$id3 = xrd_request($db, $pat, $doc, 0, 'periapical', 'not_required');
$db->update('xray_requests', ['request_status' => 'pending_xray', 'accountant_approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id3]);
$res = $xm->editApprovedDetails($id3, ['xray_type' => 'panoramic'], $acc, 'accountant');
$t->assertTrue(!empty($res['success']) && $row($id3)['xray_type'] === 'panoramic', 'a free approved request: its type corrected');

// not approved yet: the normal form, not this
$id4 = xrd_request($db, $pat, $doc, 10000);
$res = $xm->editApprovedDetails($id4, ['notes' => 'x'], $acc, 'accountant');
$t->assertTrue(empty($res['success']), 'a request not approved yet is not edited here');

// switched off: nothing changes
Settings::set($editKey, '0');
$res = $xm->editApprovedDetails($id, ['notes' => 'y'], $acc, 'accountant');
$t->assertTrue(empty($res['success']), 'with approved-record editing off, refused');
$res = $xm->editApprovedSettlement($id, ['paid_at' => $day(2) . ' 10:00'], $acc, 'accountant');
$t->assertTrue(empty($res['success']), 'and the money date too');

if ($editWas) {
    Settings::set($editKey, $editWas['setting_value']);
} else {
    $db->query("DELETE FROM settings WHERE setting_key = ?", [$editKey]);
    Settings::invalidate();
}
