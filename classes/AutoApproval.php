<?php
/**
 * AutoApproval — پەسەندکردنی خۆکاری شەوانە.
 *
 * Once a day, after the clinic has closed (auto_approve_time, 02:00 by
 * default), every invoice still waiting at the accountant (accounting_status
 * 'sent') is approved through CaseInvoice::approve() — the very call the
 * accountant's button makes, so the doctor's cashbox, the patient's debt and
 * the paid/unpaid status follow exactly the same rules. Each one is flagged
 * case_invoices.approved_auto = 1 with no approver, which is how the
 * پەسەندکراو tab tells 🤖 خۆکار from ✋ دەستی.
 *
 * Only invoices SENT before tonight's run time are taken: one sent after
 * 02:00 (the PC was off and the run happens at 08:30, say) waits for the next
 * night instead of being approved minutes after the doctor sent it.
 *
 * Triggered by cron/auto-approve.php (Windows Task Scheduler, every 15
 * minutes) and, as a fallback, by opening the Approval Center. runIfDue() is
 * one settings read when nothing is due, and a MySQL named lock keeps two
 * triggers from running at once.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/CaseInvoice.php';
require_once __DIR__ . '/Notification.php';

class AutoApproval
{
    const ENABLED     = 'auto_approve_enabled';
    const TIME        = 'auto_approve_time';
    const LAST_RUN    = 'auto_approve_last_run';
    const LAST_RESULT = 'auto_approve_last_result';
    const LOCK        = 'lezan_auto_approve';

    public static function enabled(): bool
    {
        return Settings::getBool(self::ENABLED, false);
    }

    /** HH:MM, falling back to 02:00 for anything malformed. */
    public static function time(): string
    {
        $time = (string)Settings::get(self::TIME, '02:00');
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '02:00';
    }

    /** The last run's summary, or null before the first one. */
    public static function lastResult(): ?array
    {
        $json = (string)Settings::get(self::LAST_RESULT, '');
        $data = $json !== '' ? json_decode($json, true) : null;
        return is_array($data) ? $data : null;
    }

    /**
     * Tonight's cutoff ('Y-m-d H:i:s'), or null when the run is switched off,
     * the time has not come yet, or it already ran today.
     */
    public static function dueCutoff(?int $now = null): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $now = $now ?? time();
        $today = date('Y-m-d', $now);
        $cutoff = $today . ' ' . self::time() . ':00';
        if (date('Y-m-d H:i:s', $now) < $cutoff) {
            return null;
        }
        if (Settings::get(self::LAST_RUN, '') === $today) {
            return null;
        }
        return $cutoff;
    }

    /** The scheduled task and the Approval Center fallback both call this. */
    public static function runIfDue(): ?array
    {
        $cutoff = self::dueCutoff();
        return $cutoff === null ? null : self::run($cutoff, 'schedule');
    }

    /**
     * Approve every invoice sent before $cutoff. Also used by «ئێستا جێبەجێی
     * بکە» on the settings page, with the current time as the cutoff.
     *
     * @return array summary — also stored as the last result and in the
     *               activity log; ['skipped' => ...] when another run holds
     *               the lock or already finished today.
     */
    public static function run(string $cutoff, string $trigger = 'schedule'): array
    {
        $db = Database::getInstance();
        $locked = (int)($db->fetch('SELECT GET_LOCK(:name, 0) AS got', ['name' => self::LOCK])['got'] ?? 0);
        if ($locked !== 1) {
            return ['skipped' => 'locked'];
        }
        try {
            // Re-read under the lock: the other trigger may have just finished.
            Settings::invalidate();
            $day = substr($cutoff, 0, 10);
            if ($trigger === 'schedule' && Settings::get(self::LAST_RUN, '') === $day) {
                return ['skipped' => 'done'];
            }
            @set_time_limit(600);

            $rows = $db->fetchAll(
                "SELECT id, case_id, invoice_no, accounting_notes, payment_intent
                 FROM case_invoices
                 WHERE accounting_status = 'sent' AND COALESCE(sent_at, created_at) < :cutoff
                 ORDER BY sent_at, id",
                ['cutoff' => $cutoff]
            );

            $invoiceManager = new CaseInvoice();
            $approved = 0;
            $byIntent = ['cash' => 0, 'debt' => 0, 'installment' => 0];
            $failed = [];
            foreach ($rows as $row) {
                try {
                    // The existing accounting note is passed back in — approve()
                    // writes the note it is given, and nobody is typing one here.
                    $result = $invoiceManager->approve(
                        (int)$row['case_id'], (int)$row['id'], null,
                        (string)($row['accounting_notes'] ?? ''), true
                    );
                } catch (Throwable $e) {
                    error_log('AutoApproval invoice #' . $row['id'] . ': ' . $e->getMessage());
                    $result = ['success' => false, 'message' => 'هەڵەی ناوخۆیی'];
                }
                if (!empty($result['success'])) {
                    $approved++;
                    $intent = $row['payment_intent'] ?? '';
                    if (isset($byIntent[$intent])) {
                        $byIntent[$intent]++;
                    }
                } else {
                    $failed[] = ['invoice_no' => $row['invoice_no'], 'message' => (string)($result['message'] ?? '')];
                }
            }

            $summary = [
                'ran_at'    => date('Y-m-d H:i:s'),
                'cutoff'    => $cutoff,
                'trigger'   => $trigger,
                'found'     => count($rows),
                'approved'  => $approved,
                'by_intent' => $byIntent,
                'failed'    => $failed,
            ];
            Settings::set(self::LAST_RUN, $day, 'text');
            Settings::set(self::LAST_RESULT, json_encode($summary, JSON_UNESCAPED_UNICODE), 'text');
            AuditLog::log('invoice_auto_approve_run', 'case_invoices', null, null, $summary,
                $trigger === 'manual' ? ($_SESSION['user_id'] ?? null) : null);
            self::notify($summary);
            return $summary;
        } finally {
            $db->fetch('SELECT RELEASE_LOCK(:name) AS released', ['name' => self::LOCK]);
        }
    }

    /** Morning note for the admin and the accountants — only when something happened. */
    private static function notify(array $summary): void
    {
        if ($summary['approved'] === 0 && !$summary['failed']) {
            return;
        }
        $message = $summary['approved'] . ' وەسڵ خۆکار پەسەندکرا'
            . ' (نەقد ' . $summary['by_intent']['cash']
            . '، قەرز ' . $summary['by_intent']['debt']
            . '، قیست ' . $summary['by_intent']['installment'] . ')';
        if ($summary['failed']) {
            $message .= ' — ' . count($summary['failed']) . ' وەسڵ پەسەند نەکرا و لای ژمێریار ماوەتەوە: '
                . implode('، ', array_slice(array_column($summary['failed'], 'invoice_no'), 0, 10));
        }
        (new Notification())->notifyRole(
            ['admin', 'accountant'],
            Notification::TYPE_SYSTEM,
            '🤖 پەسەندکردنی خۆکاری شەوانە',
            $message,
            ['auto_approve' => true],
            APP_URL . '/accountant/approval-center.php?tab=approved&approval=auto'
        );
    }
}
