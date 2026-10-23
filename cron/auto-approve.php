<?php
/**
 * پەسەندکردنی خۆکاری شەوانە — the scheduled runner.
 *
 * Windows Task Scheduler calls this every 15 minutes (registered by
 * cron/install-auto-approve-task.bat). It exits at once unless tonight's run
 * is due, so a PC that was off at 02:00 still runs it within 15 minutes of
 * coming back on. Schedule and on/off live on admin/settings.php?tab=access.
 *
 *   php cron/auto-approve.php            run if due
 *   php cron/auto-approve.php --status   print the schedule, change nothing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AutoApproval.php';

if (in_array('--status', $argv ?? [], true)) {
    echo 'enabled:  ' . (AutoApproval::enabled() ? 'yes' : 'no') . "\n";
    echo 'time:     ' . AutoApproval::time() . "\n";
    echo 'last run: ' . Settings::get(AutoApproval::LAST_RUN, '-') . "\n";
    echo 'due now:  ' . (AutoApproval::dueCutoff() ?? 'no') . "\n";
    exit(0);
}

try {
    $result = AutoApproval::runIfDue();
} catch (Throwable $e) {
    // A non-zero exit shows as the task's "Last Run Result"; the next
    // 15-minute tick tries again (e.g. MySQL still starting after a reboot).
    error_log('[auto-approve] ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo $result === null ? "not due\n" : json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
