<?php // tests/cases/QuickOpenCaseTest.php
// «کەیس» in one click (CaseManager::quickOpen): the doctor's open case on the
// patient, or a general one made at once — the same one on the next click,
// never a second; another doctor gets their own; a closed case is not reopened.
require_once __DIR__ . '/../../classes/CaseManager.php';
require_once __DIR__ . '/../../classes/CaseNamePreset.php';

(function () use ($t) {
    $db = fx_db(); $cm = new CaseManager();
    $docA = fx_user('doctor'); $docB = fx_user('doctor'); $patient = fx_patient();
    $row = fn($id) => $db->fetch('SELECT c.case_name, c.scope_type, c.status, c.is_historical, pf.doctor_id, pf.patient_id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = ?', [$id]);

    $first = $cm->quickOpen($patient, $docA, $docA);
    $t->assertTrue($first > 0, 'first click: a case is made');
    $r = $row($first);
    $t->assertSame(CaseNamePreset::GENERAL, $r['case_name'], 'first click: it is the general case');
    $t->assertSame(['tooth', 'open', '0'], [$r['scope_type'], $r['status'], (string)$r['is_historical']], 'first click: a tooth case, open, not historical');
    $t->assertSame([$docA, $patient], [(int)$r['doctor_id'], (int)$r['patient_id']], 'first click: on the doctor\'s folder for this patient');
    $t->assertTrue((new CaseNamePreset())->exists(CaseNamePreset::GENERAL), 'the general name is in the admin list');

    $t->assertSame($first, $cm->quickOpen($patient, $docA, $docA), 'second click: the same case, not another');
    $other = $cm->quickOpen($patient, $docB, $docB);
    $t->assertTrue($other > 0 && $other !== $first, 'another doctor: a case of their own');

    $db->update('cases', ['status' => 'closed'], 'id = ?', [$first]);
    $again = $cm->quickOpen($patient, $docA, $docA);
    $t->assertTrue($again > 0 && $again !== $first, 'closed: a new one is made, the closed one stays closed');
    $t->assertSame('closed', $row($first)['status'], 'closed: untouched');

    $t->assertSame(null, $cm->quickOpen($patient, 0, $docA), 'no doctor: nothing is made');
})();
