<?php
// سڕینەوەی هەمیشەیی بەکارهێنەر — only a user with no records anywhere.
require_once __DIR__ . '/../../classes/UserDeletion.php';
(function () use ($t) {
    $savedSession = $_SESSION;
    $db = fx_db();
    $svc = new UserDeletion();
    $admin = fx_user('admin');
    $_SESSION = ['user_id' => $admin, 'role' => 'admin', 'logged_in' => true];

    // A doctor who never worked: only his own housekeeping rows.
    $idle = fx_user('doctor');
    $db->insert('notifications', ['user_id' => $idle, 'type' => 'system', 'title' => 't', 'message' => 'm']);
    $db->query("INSERT IGNORE INTO cashbox_accounts (account_key, owner_type, doctor_id) VALUES (?, 'doctor', ?)", ['doctor:' . $idle, $idle]);
    $t->assertSame([], $svc->references($idle), 'an idle doctor has no records');
    $t->assertTrue($svc->delete($idle, $admin)['success'], 'an idle doctor is deleted');
    $t->assertTrue(!$db->fetch('SELECT id FROM users WHERE id = ?', [$idle]), 'gone from users');
    $t->assertSame(0, $db->count('notifications', 'user_id = ?', [$idle]), 'his notifications went with him');
    $t->assertSame(0, $db->count('cashbox_accounts', 'account_key = ?', ['doctor:' . $idle]), 'his empty cashbox went with him');
    $t->assertTrue((bool)$db->fetch("SELECT id FROM activity_log WHERE action = 'user_deleted' AND record_id = ?", [$idle]), 'the deletion is logged');

    // A doctor with a patient folder: refused, and nothing cascades away.
    $busy = fx_user('doctor');
    $folder = fx_folder(fx_patient(), $busy);
    $refs = $svc->references($busy);
    $t->assertTrue(isset($refs['patient_folders.doctor_id']), 'his folder is found');
    $r = $svc->delete($busy, $admin);
    $t->assertSame(false, $r['success'], 'a doctor with records is refused');
    $t->assertTrue(!empty($r['references']), 'and told which records');
    $t->assertTrue((bool)$db->fetch('SELECT id FROM patient_folders WHERE id = ?', [$folder]), 'the folder is untouched');
    $t->assertTrue((bool)$db->fetch('SELECT id FROM users WHERE id = ?', [$busy]), 'the doctor is untouched');

    // A receptionist who registered one patient: refused too.
    $rec = fx_user('receptionist');
    $p = fx_patient();
    $db->update('patients', ['registered_by' => $rec], 'id = ?', [$p]);
    $t->assertSame(false, $svc->delete($rec, $admin)['success'], 'one registered patient is enough to refuse');

    // Never yourself, never the last active admin.
    $t->assertSame(false, $svc->delete($admin, $admin)['success'], 'cannot delete yourself');
    $t->assertTrue($svc->blockedReason($admin, $admin) !== null, 'self is blocked up front');

    $_SESSION = $savedSession;
})();
