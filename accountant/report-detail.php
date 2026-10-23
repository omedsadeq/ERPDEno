<?php
/**
 * report-detail.php — retired (Task 13).
 *
 * This page's whole workflow (invoice approval/return, receive/approve/
 * reverse payment, installment plans) now lives on patients/statement.php,
 * driven by the shared api/finance-actions.php handlers (Task 11). Its old
 * sync_legacy_accounting_report() helper — the last `processed`/`reviewed`
 * writer against accounting_reports for this flow — dies with the file,
 * which is correct: the table is frozen (read-only audit from here on).
 *
 * Any existing links (?case_id=N) are redirected to the equivalent patient
 * statement, anchored to that case.
 *
 * Login required before the DB lookup below — anonymous access here would
 * let an unauthenticated request enumerate case_id -> patient_id via the
 * resolved Location header. Role restriction beyond login isn't needed:
 * patients/statement.php itself enforces roles/AccessGuard.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
$auth->requireLogin();

$caseId = (int)($_GET['case_id'] ?? 0);
$pid = 0;
if ($caseId) {
    $row = Database::getInstance()->fetch(
        "SELECT pf.patient_id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = ?",
        [$caseId]
    );
    $pid = (int)($row['patient_id'] ?? 0);
}
header('Location: ' . APP_URL . '/patients/statement.php?patient_id=' . $pid . ($caseId ? '&case_id=' . $caseId : ''), true, 302);
exit;
