<?php // tests/cases/SmokeTest.php
$t->assertTrue(Database::getInstance()->tableExists('case_invoices'), 'schema loaded');
$t->assertTrue(Database::getInstance()->tableExists('payment_allocations'), 'migrations applied');
$uid = fx_user('doctor');
$t->assertTrue($uid > 0, 'fixture user inserted');
$pid = fx_patient(); $fid = fx_folder($pid, $uid); $cid = fx_case($fid);
$t->assertTrue($cid > 0, 'fixture chain patient->folder->case');
