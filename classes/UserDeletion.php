<?php
/**
 * UserDeletion — permanently deleting a user who never did anything.
 *
 * Deactivating (users.is_active = 0) is the normal way to retire an account and
 * keeps every record's "who". This is for accounts that were made by mistake
 * or never used: the user is deleted only when NO row anywhere points at them.
 *
 * "Anywhere" is found at run time, so a table added later is covered too:
 *   - every declared foreign key to users, and
 *   - every undeclared user-ish column (…_by, user_id, doctor_id, actor_id, …)
 *     that does not point at another table — erring on the side of refusing.
 * Some of those FKs CASCADE (patient_folders.doctor_id, waiting_room.doctor_id,
 * xray_requests.requested_by …): deleting a user with such rows would silently
 * delete clinical records, which is exactly why any row blocks the delete.
 *
 * What goes with the user is their own housekeeping only — notification
 * settings, notifications, doctor schedule/breaks/exceptions, doctor
 * permissions, warehouse access, call locks, an empty never-used cashbox, a
 * doctors-directory row nothing points at — plus their own access and audit
 * logs, which keep their rows with the user emptied (or, for the access log,
 * are removed: its FK does not allow an empty user). The deletion itself is
 * logged with the user's full details.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class UserDeletion
{
    /** Rows that are the user's own settings or logs — never a reason to refuse. */
    private const HOUSEKEEPING = [
        'activity_log.user_id', 'erp_audit_logs.user_id', 'erp2_audit_logs.user_id', 'backup_history.created_by',
        'notifications.user_id', 'notifications.created_by', 'notification_settings.user_id',
        'doctor_permissions.doctor_id', 'doctor_schedules.doctor_id', 'doctor_breaks.doctor_id', 'doctor_exceptions.doctor_id',
        'patient_access_logs.user_id', 'debt_call_locks.locked_by',
        'erp_user_warehouses.user_id', 'erp2_user_warehouses.user_id',
        'doctors.user_id', 'cashbox_accounts.doctor_id', 'users.id',
    ];

    /** Housekeeping rows removed with the user (the rest keep their row, user emptied). */
    private const REMOVE_WITH_USER = [
        'notification_settings' => 'user_id', 'notifications' => 'user_id',
        'doctor_permissions' => 'doctor_id', 'doctor_schedules' => 'doctor_id',
        'doctor_breaks' => 'doctor_id', 'doctor_exceptions' => 'doctor_id',
        'patient_access_logs' => 'user_id', 'debt_call_locks' => 'locked_by',
        'erp_user_warehouses' => 'user_id', 'erp2_user_warehouses' => 'user_id',
    ];

    private const USER_COLUMN = "(c.COLUMN_NAME REGEXP '_by$' OR c.COLUMN_NAME IN ('user_id','doctor_id','actor_id','accountant_id','receptionist_id','requested_by','performed_by','assigned_to','sender_id','recipient_id','owner_id','checkup_doctor_id','treated_by'))";

    private $db;
    private static $columns = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Every [table, column] that may hold a users.id — declared FKs + the undeclared user-ish ones. */
    private function userColumns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }
        $fk = [];
        foreach ($this->db->fetchAll(
            "SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME r FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"
        ) as $r) {
            $fk[$r['t'] . '.' . $r['c']] = $r['r'];
        }
        $cols = [];
        foreach ($fk as $key => $target) {
            if ($target === 'users') {
                $cols[$key] = explode('.', $key, 2);
            }
        }
        foreach ($this->db->fetchAll(
            "SELECT c.TABLE_NAME t, c.COLUMN_NAME c FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.DATA_TYPE IN ('int','bigint','smallint','mediumint') AND " . self::USER_COLUMN
        ) as $r) {
            $key = $r['t'] . '.' . $r['c'];
            // Points at some other table (visits.*doctor_id → doctors): not a user.
            if (isset($fk[$key]) && $fk[$key] !== 'users') {
                continue;
            }
            $cols[$key] = [$r['t'], $r['c']];
        }
        return self::$columns = $cols;
    }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
            throw new RuntimeException('Invalid identifier');
        }
        return '`' . $name . '`';
    }

    /**
     * What this user still has in the system: ['table.column' => rows], empty
     * when the user may be deleted. A doctor's cashbox and directory row count
     * too once anything is in or behind them.
     */
    public function references(int $userId): array
    {
        $found = [];
        foreach ($this->userColumns() as $key => [$table, $column]) {
            if (in_array($key, self::HOUSEKEEPING, true)) {
                continue;
            }
            $n = (int)($this->db->fetch(
                'SELECT COUNT(*) AS n FROM ' . self::ident($table) . ' WHERE ' . self::ident($column) . ' = ?', [$userId]
            )['n'] ?? 0);
            if ($n > 0) {
                $found[$key] = $n;
            }
        }
        // A cashbox that ever moved, and journal rows booked into it.
        $box = $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key = ?', ['doctor:' . $userId]);
        if ($box && abs((float)$box['balance']) > 0.004) {
            $found['cashbox_accounts.balance'] = 1;
        }
        $entries = (int)($this->db->fetch('SELECT COUNT(*) AS n FROM cashbox_entries WHERE account_key = ?', ['doctor:' . $userId])['n'] ?? 0);
        if ($entries > 0) {
            $found['cashbox_entries.account_key'] = $entries;
        }
        // The doctors-directory row: visits and others point at doctors.id.
        foreach ($this->doctorRows($userId) as $doctorRowId) {
            foreach ($this->db->fetchAll(
                "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'doctors'"
            ) as $ref) {
                $n = (int)($this->db->fetch(
                    'SELECT COUNT(*) AS n FROM ' . self::ident($ref['t']) . ' WHERE ' . self::ident($ref['c']) . ' = ?', [$doctorRowId]
                )['n'] ?? 0);
                if ($n > 0) {
                    $found[$ref['t'] . '.' . $ref['c']] = ($found[$ref['t'] . '.' . $ref['c']] ?? 0) + $n;
                }
            }
            if ($this->db->tableExists('visits') && $this->db->fetch("SHOW COLUMNS FROM visits LIKE 'doctor_id'")) {
                $n = (int)($this->db->fetch('SELECT COUNT(*) AS n FROM visits WHERE doctor_id = ?', [$doctorRowId])['n'] ?? 0);
                if ($n > 0) {
                    $found['visits.doctor_id'] = ($found['visits.doctor_id'] ?? 0) + $n;
                }
            }
        }
        return $found;
    }

    private function doctorRows(int $userId): array
    {
        if (!$this->db->tableExists('doctors')) {
            return [];
        }
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT id FROM doctors WHERE user_id = ?', [$userId]), 'id'));
    }

    /** Why a user cannot be deleted — refused for these regardless of their records. */
    public function blockedReason(int $userId, int $actorId): ?string
    {
        if ($userId === $actorId) {
            return 'ناتوانیت ئەکاونتی خۆت بسڕیتەوە';
        }
        $user = $this->db->fetch('SELECT id, role FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return 'بەکارهێنەر نەدۆزرایەوە';
        }
        if ($user['role'] === 'admin'
            && (int)$this->db->fetch("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$userId])['n'] === 0) {
            return 'دوایین بەڕێوەبەری چالاکە — ناسڕدرێتەوە';
        }
        return null;
    }

    /** @return array{success:bool, message?:string, references?:array} */
    public function delete(int $userId, int $actorId): array
    {
        if ($reason = $this->blockedReason($userId, $actorId)) {
            return ['success' => false, 'message' => $reason];
        }
        $this->db->beginTransaction();
        try {
            $user = $this->db->fetch('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user) {
                throw new DomainException('بەکارهێنەر نەدۆزرایەوە');
            }
            $refs = $this->references($userId);
            if ($refs) {
                $this->db->rollback();
                return ['success' => false, 'references' => $refs,
                        'message' => 'ئەم بەکارهێنەرە کاری لە سیستەمدا هەیە — تەنها ناچالاک دەکرێت'];
            }
            $removed = [];
            foreach (self::REMOVE_WITH_USER as $table => $column) {
                if ($this->db->tableExists($table)) {
                    $removed[$table] = $this->db->query('DELETE FROM ' . self::ident($table) . ' WHERE ' . self::ident($column) . ' = ?', [$userId])->rowCount();
                }
            }
            if ($this->db->tableExists('notifications')) {
                $this->db->query('UPDATE notifications SET created_by = NULL WHERE created_by = ?', [$userId]);
            }
            foreach ($this->doctorRows($userId) as $doctorRowId) {
                $this->db->query('DELETE FROM doctors WHERE id = ?', [$doctorRowId]);
            }
            $this->db->query('DELETE FROM cashbox_accounts WHERE account_key = ? AND balance = 0', ['doctor:' . $userId]);
            $this->db->query('DELETE FROM users WHERE id = ?', [$userId]);
            unset($user['password']);
            AuditLog::log('user_deleted', 'users', $userId, $user, ['removed_with_user' => array_filter($removed)], $actorId);
            $this->db->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof PDOException) {
                error_log('UserDeletion #' . $userId . ': ' . $e->getMessage());
                return ['success' => false, 'message' => 'سڕینەوە سەرنەکەوت — ئەم بەکارهێنەرە پەیوەندی بە تۆمارێکی ترەوە هەیە'];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Readable Kurdish labels for the blocking tables, for the refusal message. */
    public static function label(string $key): string
    {
        $table = explode('.', $key, 2)[0];
        $labels = [
            'patient_folders' => 'فایلی نەخۆش', 'cases' => 'کەیس', 'case_invoices' => 'وەسڵ', 'case_treatments' => 'چارەسەر',
            'payments' => 'پارەدان', 'payment_allocations' => 'پارەدان', 'appointments' => 'مەوعید', 'visits' => 'سەردان',
            'visit_flow_log' => 'سەردان', 'resident_visits' => 'سەردان', 'xray_payment_logs' => 'ئێکسڕەی', 'xray_debt_payments' => 'ئێکسڕەی',
            'waiting_room' => 'ژووری چاوەڕوانی', 'xray_requests' => 'ئێکسڕەی', 'patients' => 'نەخۆش', 'expenses' => 'خەرجی',
            'expenses_lab' => 'خەرجی تاقیگە', 'expenses_store' => 'خەرجی کۆگا', 'expenses_general' => 'خەرجی گشتی',
            'cashbox_entries' => 'سندوق', 'cashbox_accounts' => 'سندوق', 'patient_advances' => 'قەرزی کۆن',
            'installment_plans' => 'قیست', 'installment_payments' => 'قیست', 'doctor_attendance' => 'ئامادەبوون',
            'treatment_plans' => 'پلانی چارەسەر', 'prescriptions' => 'ڕەچەتە', 'erp2_prescriptions' => 'ڕەچەتە',
            'dental_chart' => 'چارتی ددان', 'case_images' => 'وێنە', 'patient_images' => 'وێنە', 'income_settlements' => 'دابەشکردنی داهات',
        ];
        if (isset($labels[$table])) {
            return $labels[$table];
        }
        return strpos($table, 'erp') === 0 ? 'دەرمانخانە / کۆگا' : $table;
    }
}
