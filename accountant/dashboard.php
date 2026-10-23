<?php
/**
 * داشبۆردی ژمێریار — retired; forwards to the approval center.
 *
 * The old page was six stat cards and six quick buttons that all led to the
 * same six pages, two tables repeating the approval center and the payments
 * page, and a "today's income" that counted approved payments + x-ray only —
 * leaving out chair-side cash and installments, so it showed less than
 * پوختەی دارایی for the same day. The accountant's real starting point is the
 * work queue, so that is where this address now goes (login lands there too).
 *
 * Kept as a redirect, not deleted, so bookmarks and any old link still work.
 * The original page is in storage/backups/removed_pages/accountant_dashboard.php.bak.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

(new Auth())->requireLogin();

header('Location: ' . APP_URL . '/accountant/approval-center.php');
exit;
