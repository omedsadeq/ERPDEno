<?php
/**
 * Retired 2026-08-26 — the live receiving feed is now one of the Approval
 * Center's tabs (accountant/approval-center.php). This file stays as a
 * redirect stub so old bookmarks/links keep working.
 *
 * Auth::requireLogin() gate first (Task 13 lesson: a stub with no login gate
 * is an unauthenticated-request vector even though it does nothing but
 * redirect) — role/permission enforcement itself lives on
 * approval-center.php, so no second gate is needed here.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

$auth = new Auth();
$auth->requireLogin();

// The old feed's kind= filter maps loosely onto the new tab names — preserve
// a deep link into the x-ray queue specifically, since that one carries a
// distinct, still-meaningful destination (station/report/payment kinds were
// broader "everything" views with no single new-tab equivalent).
$target = APP_URL . '/accountant/approval-center.php';
if (($_GET['kind'] ?? '') === 'xray') {
    $target .= '?tab=xray';
}

header('Location: ' . $target, true, 302);
exit;
