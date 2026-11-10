<?php
/**
 * داخستنی مانگ — a month of the accounts, closed once they are done.
 *
 * While a month stands closed, nothing the income sheet counts that is dated in
 * it may be added, changed or deleted. The line is held in the database itself
 * (installGuards(): one BEFORE INSERT / UPDATE / DELETE trigger per money
 * table), so every screen — the case page, the till, the pharmacy, the expense
 * pages, the HR payroll, a script — is stopped the same way. Database::query()
 * turns the trigger's signal into PeriodClosedException with a sentence for
 * the user. The income sheet's own writers also ask first (assertOpen), for a
 * clean message before any work is done.
 *
 * A row counts for a month only while it is income or a cost there: an
 * approved receipt, an approved payment, a paid x-ray, a confirmed sale, a
 * visit with a fee, a live entry. A draft in a closed month may still be
 * worked on; approving it would change the month, and is refused. An update
 * that touches none of a table's money columns (a note, a treatment status) is
 * never stopped.
 *
 * Each closing keeps a frozen copy of the income sheet as it stood
 * (snapshot): what was closed can always be shown, and set against the sheet
 * as it is now. The admin may reopen a month, with a reason; closing it again
 * keeps a new copy and the history.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class PeriodClose
{
    public const PERMISSION = 'income.month_close';
    /** What the trigger signals; Database::query() reads it back. */
    public const MARKER = 'LZ_PERIOD_CLOSED:';

    /**
     * The tables the income sheet reads, and how a row of each belongs to a
     * month: its date ({r} is OLD or NEW), when it counts at all, and the
     * columns whose change would move the month's figures.
     */
    private const GUARDS = [
        'case_invoices'         => ['date' => '{r}.work_date', 'counts' => "{r}.accounting_status = 'approved'",
                                    'cols' => ['work_date', 'draft_received_amount', 'discount_amount', 'total_amount', 'accounting_status', 'doctor_id', 'case_id', 'advance_paid_amount']],
        'payments'              => ['date' => '{r}.payment_date', 'counts' => "{r}.status = 'approved'",
                                    'cols' => ['payment_date', 'amount', 'status', 'case_id', 'invoice_id', 'payment_method', 'advance_cash_amount', 'discount_amount']],
        'installment_payments'  => ['date' => '{r}.paid_at', 'counts' => '1',
                                    'cols' => ['paid_at', 'amount', 'payment_id', 'payment_method', 'plan_id']],
        'xray_requests'         => ['date' => 'COALESCE({r}.paid_at, {r}.created_at)', 'counts' => "{r}.payment_status = 'paid'",
                                    'cols' => ['paid_at', 'final_amount', 'payment_status']],
        'xray_debt_payments'    => ['date' => '{r}.created_at', 'counts' => '1',
                                    'cols' => ['created_at', 'amount', 'debt_id']],
        'erp2_sales_invoices'   => ['date' => '{r}.invoice_date', 'counts' => "{r}.status = 'confirmed'",
                                    'cols' => ['invoice_date', 'grand_total', 'status']],
        'erp2_sales_returns'    => ['date' => '{r}.return_date', 'counts' => "{r}.status = 'confirmed'",
                                    'cols' => ['return_date', 'grand_total', 'status']],
        'visits'                => ['date' => '{r}.visit_date', 'counts' => '{r}.price > 0',
                                    'cols' => ['visit_date', 'price']],
        'income_manual_entries' => ['date' => '{r}.entry_date', 'counts' => '1',
                                    'cols' => ['entry_date', 'kind', 'amount', 'qty']],
        'income_column_entries' => ['date' => '{r}.entry_date', 'counts' => "{r}.status = 'active'",
                                    'cols' => ['entry_date', 'amount', 'status', 'column_id', 'doctor_id', 'row_kind', 'row_cash_out']],
        'expenses_lab'          => ['date' => '{r}.expense_date', 'counts' => '1',
                                    'cols' => ['expense_date', 'amount', 'doctor_id']],
        'expenses_store'        => ['date' => '{r}.expense_date', 'counts' => '1',
                                    'cols' => ['expense_date', 'amount', 'doctor_id', 'expense_kind']],
        'expenses_general'      => ['date' => '{r}.expense_date', 'counts' => '1',
                                    'cols' => ['expense_date', 'amount', 'doctor_id']],
        'expenses'              => ['date' => '{r}.expense_date', 'counts' => "COALESCE({r}.status, '') NOT IN ('rejected', 'cancelled')",
                                    'cols' => ['expense_date', 'amount', 'status']],
    ];

    /** @var string[]|null this request's closed months */
    private static $closed = null;

    public static function available(): bool
    {
        return Database::getInstance()->tableExists('period_closures');
    }

    /** The months standing closed now, 'Y-m' => true. */
    public static function closedMonths(): array
    {
        if (self::$closed === null) {
            self::$closed = [];
            if (self::available()) {
                foreach (Database::getInstance()->fetchAll("SELECT DISTINCT month FROM period_closures WHERE status = 'closed'") as $r) {
                    self::$closed[$r['month']] = true;
                }
            }
        }
        return self::$closed;
    }

    /** The closed month a date (Y-m-d or a datetime) falls in, or null. */
    public static function closedMonthOf(?string $date): ?string
    {
        if (!$date || !preg_match('/^(\d{4}-\d{2})/', $date, $m)) {
            return null;
        }
        return isset(self::closedMonths()[$m[1]]) ? $m[1] : null;
    }

    /** The closed months a period [from, to] touches. */
    public static function closedIn(string $from, string $to): array
    {
        $f = substr($from, 0, 7);
        $t = substr($to, 0, 7);
        return array_values(array_filter(array_keys(self::closedMonths()), fn($m) => $m >= $f && $m <= $t));
    }

    /** What the user reads when a closed month stops them. */
    public static function message(string $month): string
    {
        return 'مانگی ' . $month . ' داخراوە: هیچ پارە یان خەرجییەک بە ڕێکەوتی ئەو مانگە زیاد، دەستکاری یان سڕینەوە ناکرێت.'
            . ' ئەگەر پێویستە، بەڕێوەبەر لە «داخستنی مانگ» بیکاتەوە، یان لە مانگی کراوەدا تۆماری بکە.';
    }

    /** Stop here if $date is in a closed month (a clean message before any work is done). */
    public static function assertOpen(?string $date): void
    {
        $month = self::closedMonthOf($date);
        if ($month !== null) {
            throw new InvalidArgumentException(self::message($month));
        }
    }

    /** The current row of a month (its latest closing), or null. */
    public static function current(string $month): ?array
    {
        if (!self::available()) {
            return null;
        }
        return Database::getInstance()->fetch(
            "SELECT pc.*, cu.full_name AS closed_by_name, ru.full_name AS reopened_by_name
             FROM period_closures pc
             LEFT JOIN users cu ON cu.id = pc.closed_by
             LEFT JOIN users ru ON ru.id = pc.reopened_by
             WHERE pc.month = ? ORDER BY pc.id DESC LIMIT 1",
            [$month]
        ) ?: null;
    }

    /** Every closing and reopening, newest first. */
    public static function history(int $limit = 60): array
    {
        if (!self::available()) {
            return [];
        }
        return Database::getInstance()->fetchAll(
            "SELECT pc.id, pc.month, pc.status, pc.note, pc.closed_at, pc.reopened_at, pc.reopen_reason,
                    cu.full_name AS closed_by_name, ru.full_name AS reopened_by_name
             FROM period_closures pc
             LEFT JOIN users cu ON cu.id = pc.closed_by
             LEFT JOIN users ru ON ru.id = pc.reopened_by
             ORDER BY pc.id DESC LIMIT " . max(1, $limit)
        );
    }

    /** Is the month over (only a finished month is closed)? */
    public static function isOver(string $month): bool
    {
        return date('Y-m-t', strtotime($month . '-01')) < date('Y-m-d');
    }

    /**
     * What is still waiting in a month — worth finishing before it is closed:
     * label => count.
     */
    public static function pending(string $month): array
    {
        $db = Database::getInstance();
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $out = [];
        $out['وەسڵی نێردراو کە هێشتا پەسەند نەکراوە'] = (int)($db->fetch(
            "SELECT COUNT(*) n FROM case_invoices WHERE accounting_status IN ('sent', 'returned') AND work_date BETWEEN ? AND ?",
            [$from, $to])['n'] ?? 0);
        $out['پارەدانی چاوەڕوانی پەسەندکردن'] = (int)($db->fetch(
            "SELECT COUNT(*) n FROM payments WHERE status = 'pending' AND DATE(payment_date) BETWEEN ? AND ?",
            [$from, $to])['n'] ?? 0);
        $out['ئێکسرەی چاوەڕوانی پارەدان'] = (int)($db->fetch(
            "SELECT COUNT(*) n FROM xray_requests WHERE payment_status = 'pending_payment' AND DATE(created_at) BETWEEN ? AND ?",
            [$from, $to])['n'] ?? 0);
        return array_filter($out);
    }

    /**
     * Close a month: the frozen copy of its sheet, the row, and the guards.
     * Returns ['id' => …, 'guard' => null or the reason the guards could not be
     * put in place].
     */
    public static function close(string $month, array $snapshot, string $note, int $userId): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('مانگ دروست نییە');
        }
        if (!self::isOver($month)) {
            throw new InvalidArgumentException('تەنها مانگێکی تەواوبوو دادەخرێت');
        }
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $now = $db->fetch("SELECT id FROM period_closures WHERE month = ? AND status = 'closed' FOR UPDATE", [$month]);
            if ($now) {
                throw new InvalidArgumentException('مانگی ' . $month . ' پێشتر داخراوە');
            }
            $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? ''), 0, 255);
            $id = (int)$db->insert('period_closures', [
                'month' => $month, 'status' => 'closed',
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'note' => $note !== '' ? $note : null, 'closed_by' => $userId ?: null,
            ]);
            AuditLog::log('period_close', 'period_closures', $id, null, ['month' => $month, 'note' => $note], $userId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        self::$closed = null;
        return ['id' => $id, 'guard' => self::installGuards()];
    }

    /** Open a closed month again (the admin), with the reason. Its copy stays. */
    public static function reopen(string $month, string $reason, int $userId): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('هۆکاری کردنەوە بنووسە (٣ تا ٢٥٥ پیت)');
        }
        $db = Database::getInstance();
        $row = $db->fetch("SELECT id FROM period_closures WHERE month = ? AND status = 'closed' ORDER BY id DESC LIMIT 1", [$month]);
        if (!$row) {
            throw new InvalidArgumentException('مانگی ' . $month . ' داخراو نییە');
        }
        $db->update('period_closures', [
            'status' => 'reopened', 'reopened_by' => $userId ?: null,
            'reopened_at' => date('Y-m-d H:i:s'), 'reopen_reason' => $reason,
        ], 'id = ?', [(int)$row['id']]);
        AuditLog::log('period_reopen', 'period_closures', (int)$row['id'], ['status' => 'closed'], ['month' => $month, 'reason' => $reason], $userId);
        self::$closed = null;
    }

    /** Are the triggers in place (all of them, on the tables that exist)? */
    public static function guardsInstalled(): bool
    {
        $db = Database::getInstance();
        $want = 0;
        foreach (array_keys(self::GUARDS) as $table) {
            if ($db->tableExists($table)) {
                $want += 3;
            }
        }
        $have = (int)($db->fetch(
            "SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'lz\\_pc\\_%'"
        )['n'] ?? 0);
        return $have >= $want;
    }

    /**
     * Put the triggers in place (again): each money table refuses a row of a
     * closed month. Returns null, or why it could not (a host whose database
     * user may not create triggers): the sheet's own screens still ask first,
     * and the closing page says so.
     */
    public static function installGuards(): ?string
    {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        try {
            foreach (self::GUARDS as $table => $g) {
                if (!$db->tableExists($table)) {
                    continue;
                }
                foreach (self::triggerSql($table, $g) as $name => $sql) {
                    $pdo->exec('DROP TRIGGER IF EXISTS `' . $name . '`');
                    $pdo->exec($sql);
                }
            }
        } catch (PDOException $e) {
            error_log('[PeriodClose] guards: ' . $e->getMessage());
            return $e->getMessage();
        }
        return null;
    }

    /** The three triggers of one table: name => CREATE TRIGGER. */
    private static function triggerSql(string $table, array $g): array
    {
        $at = fn(string $r) => str_replace('{r}', $r, $g['date']);
        $counts = fn(string $r) => str_replace('{r}', $r, $g['counts']);
        $monthOf = fn(string $r) => "(SELECT pc.month FROM period_closures pc WHERE pc.status = 'closed'"
            . " AND pc.month = DATE_FORMAT(" . $at($r) . ", '%Y-%m') LIMIT 1)";
        $stop = "IF m IS NOT NULL THEN SET msg = CONCAT('" . self::MARKER . "', m); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg; END IF;";
        $declare = 'DECLARE m VARCHAR(7) DEFAULT NULL; DECLARE msg VARCHAR(128);';
        $changed = implode(' OR ', array_map(fn($c) => "NOT (OLD.`$c` <=> NEW.`$c`)", $g['cols']));
        $base = 'lz_pc_' . $table;
        return [
            $base . '_bi' => "CREATE TRIGGER `{$base}_bi` BEFORE INSERT ON `$table` FOR EACH ROW BEGIN $declare"
                . " IF " . $counts('NEW') . " THEN SET m = " . $monthOf('NEW') . "; END IF; $stop END",
            $base . '_bu' => "CREATE TRIGGER `{$base}_bu` BEFORE UPDATE ON `$table` FOR EACH ROW BEGIN $declare"
                . " IF $changed THEN"
                . " IF " . $counts('OLD') . " THEN SET m = " . $monthOf('OLD') . "; END IF;"
                . " IF m IS NULL AND " . $counts('NEW') . " THEN SET m = " . $monthOf('NEW') . "; END IF;"
                . " END IF; $stop END",
            $base . '_bd' => "CREATE TRIGGER `{$base}_bd` BEFORE DELETE ON `$table` FOR EACH ROW BEGIN $declare"
                . " IF " . $counts('OLD') . " THEN SET m = " . $monthOf('OLD') . "; END IF; $stop END",
        ];
    }
}

/** A write refused because its month is closed (from the database's own guard). */
class PeriodClosedException extends PDOException
{
}
