<?php
/**
 * Retired 2026-08-26 — the pending-invoice/payment/x-ray review queues are
 * now the Approval Center (accountant/approval-center.php). This file stays
 * as a redirect stub so old bookmarks/links/notifications keep working.
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

// This page's own x-ray sub-filter used ?tab=xray&xray_search=... — carry
// both across onto the new tab's ?tab=xray&q=... contract.
$tab = $_GET['tab'] ?? '';
$xraySearch = $_GET['xray_search'] ?? '';

$target = APP_URL . '/accountant/approval-center.php';
if ($tab === 'xray') {
    $target .= '?tab=xray';
    if (is_string($xraySearch) && trim($xraySearch) !== '') {
        $target .= '&q=' . urlencode(trim($xraySearch));
    }
}

header('Location: ' . $target, true, 302);
exit;
