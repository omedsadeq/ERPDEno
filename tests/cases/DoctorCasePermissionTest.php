<?php
// دەسەڵاتەکانی دکتۆر — cases.delete_own: a doctor deletes his own case only,
// and only one that is debt alone — any money received on it (approved or
// still waiting) makes it the admin's (CaseDeletion::delete).
require_once __DIR__ . '/../../classes/CaseDeletion.php';
require_once __DIR__ . '/../../classes/CaseInvoice.php';
require_once __DIR__ . '/../../classes/Permission.php';
(function () use ($t) {
    $savedSession = $_SESSION;
    $db = fx_db();
    $perm = Permission::getInstance();
    $service = new CaseDeletion();
    $admin = fx_user('admin'); $doc = fx_user('doctor'); $other = fx_user('doctor');
    $as = function ($id, $role) { $_SESSION['user_id'] = $id; $_SESSION['role'] = $role; $_SESSION['logged_in'] = true; };
    $pat = fx_patient();
    $folder = fx_folder($pat, $doc);
    $as($admin, 'admin');

    $plain = fx_case($folder);              // work sent, no money
    fx_treatment($plain, 50000, fx_invoice($plain, $doc, 'sent', 'sent', 50000));
    $debt = fx_case($folder);               // approved, nothing paid — all debt
    $dinv = fx_invoice($debt, $doc, 'sent', 'sent', 70000); fx_treatment($debt, 70000, $dinv);
    $db->update('case_invoices', ['work_date' => '2026-10-05', 'payment_intent' => 'debt'], 'id = ?', [$dinv]);
    $t->assertTrue((new CaseInvoice())->approve($debt, $dinv, $admin)['success'], 'debt-only case approved');
    $paid = fx_case($folder);               // approved, with chair cash
    $inv = fx_invoice($paid, $doc, 'sent', 'sent', 80000); fx_treatment($paid, 80000, $inv);
    $db->update('case_invoices', ['work_date' => '2026-10-05', 'draft_received_amount' => 30000], 'id = ?', [$inv]);
    $t->assertTrue((new CaseInvoice())->approve($paid, $inv, $admin)['success'], 'paid case approved');
    $prior = fx_case($folder);              // approved, only دراوی پێشوو
    $pinv = fx_invoice($prior, $doc, 'sent', 'sent', 60000); fx_treatment($prior, 60000, $pinv);
    $db->update('case_invoices', ['work_date' => '2026-10-05', 'prior_paid_amount' => 60000], 'id = ?', [$pinv]);
    $t->assertTrue((new CaseInvoice())->approve($prior, $pinv, $admin)['success'], 'prior-paid case approved');
    $waiting = fx_case($folder);            // sent, chair cash not approved yet
    $winv = fx_invoice($waiting, $doc, 'sent', 'sent', 40000); fx_treatment($waiting, 40000, $winv);
    $db->update('case_invoices', ['draft_received_amount' => 10000], 'id = ?', [$winv]);

    $t->assertTrue(!$service->hasReceivedMoney($plain), 'sent work, no money: nothing received');
    $t->assertTrue(!$service->hasReceivedMoney($debt), 'approved but all debt: nothing received');
    $t->assertTrue($service->hasReceivedMoney($paid), 'approved chair cash is received money');
    $t->assertTrue($service->hasReceivedMoney($prior), 'دراوی پێشوو is received money');
    $t->assertTrue($service->hasReceivedMoney($waiting), 'chair cash still waiting for approval counts too');

    $perm->updatePermission(ROLE_DOCTOR, CaseDeletion::DOCTOR_PERMISSION, false);
    $as($doc, 'doctor');
    $t->assertSame(false, $service->delete($plain, $pat, 'switch off')['success'], 'permission off: the doctor cannot delete');

    $perm->updatePermission(ROLE_DOCTOR, CaseDeletion::DOCTOR_PERMISSION, true);
    $as($other, 'doctor');
    $t->assertSame(false, $service->delete($plain, $pat, 'not mine')['success'], 'another doctor\'s case is refused');
    $as($doc, 'doctor');
    foreach (['paid' => $paid, 'prior' => $prior, 'waiting' => $waiting] as $label => $caseId) {
        $r = $service->delete($caseId, $pat, 'has money');
        $t->assertSame(false, $r['success'], "a case with received money ({$label}) is refused for the doctor");
        $t->assertTrue((bool)$db->fetch('SELECT id FROM cases WHERE id = ?', [$caseId]), "and stays ({$label})");
    }
    $t->assertTrue(strpos((string)($service->delete($paid, $pat, 'again')['message'] ?? ''), 'تەنها قەرز') !== false, 'the refusal says only debt cases');
    $t->assertTrue($service->delete($plain, $pat, 'mine, no money')['success'], 'his case with no money is deleted');
    $t->assertTrue($service->delete($debt, $pat, 'approved debt only')['success'], 'his approved debt-only case is deleted');
    $t->assertTrue(!$db->fetch('SELECT id FROM cases WHERE id IN (?, ?)', [$plain, $debt]), 'both gone');

    $as($admin, 'admin');
    $t->assertTrue($service->delete($paid, $pat, 'admin may')['success'], 'an admin still deletes a case with money');

    $perm->updatePermission(ROLE_DOCTOR, CaseDeletion::DOCTOR_PERMISSION, false);
    $_SESSION = $savedSession;
})();
