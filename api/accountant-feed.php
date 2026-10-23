<?php
/**
 * Retired 2026-08-26 — replaced by api/approval-center.php (the Approval
 * Center's read side). This endpoint's caller (accountant/receiving.php) is
 * itself now a redirect stub, so nothing live should still be polling this
 * URL; a plain 410 JSON body — no auth, no DB — is enough to tell any
 * stale client/bookmark to stop and where to go instead. Nothing here
 * leaks any data, so no login gate is needed for a stub that does nothing
 * but announce its own retirement.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'moved: api/approval-center.php']);
