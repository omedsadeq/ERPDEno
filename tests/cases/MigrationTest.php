<?php // tests/cases/MigrationTest.php
$db = Database::getInstance();
$col = fn($table, $name) => (bool) $db->fetch(
    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
    [$table, $name]);
$t->assertTrue($col('installment_plans', 'proposed_down_payment'), 'plans.proposed_down_payment');
$t->assertTrue($col('installment_plans', 'approved_at'), 'plans.approved_at');
$t->assertTrue($col('case_invoices', 'payment_intent'), 'invoices.payment_intent');
$t->assertTrue($col('case_invoices', 'draft_received_amount'), 'invoices.draft_received_amount');
$t->assertTrue($col('case_invoices', 'promised_date'), 'invoices.promised_date');
$statusType = $db->fetch("SELECT COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='installment_plans' AND COLUMN_NAME='status'");
$t->assertTrue(str_contains($statusType['ct'], 'pending') && str_contains($statusType['ct'], 'rejected'), 'plan status enum extended');
$paymentStatusType = $db->fetch("SELECT COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME='status'");
$t->assertTrue(str_contains($paymentStatusType['ct'], 'draft'), 'payment receipt status supports doctor drafts');
$t->assertTrue((bool) $db->fetch("SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v_patient_financial_summary'"), 'patient view exists');

// Extra deliverable (controller add-on): doctors/visits baseline migration must
// create both tables on a from-scratch DB — see database/migrations/2026_08_26_baseline_doctors_visits.sql
$t->assertTrue($db->tableExists('doctors'), 'doctors table exists');
$t->assertTrue($db->tableExists('visits'), 'visits table exists');
