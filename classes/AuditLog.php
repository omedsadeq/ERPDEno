<?php
/**
 * AuditLog - central activity/audit logger
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Writes every important action (create / update / status change / approve /
 * delete ...) to the existing `activity_log` table together with the acting
 * user (from the session), the affected table/record and the old/new values.
 *
 * Usage:
 *   AuditLog::log('delete_visit', 'visits', $visitId, $oldRow);
 *   AuditLog::logChange('update_visit', 'visits', $id, $oldRow, $newData);
 *   AuditLog::log('waiting_status', 'waiting_room', $id, ['status' => $old], ['status' => $new]);
 *
 * Logging is best-effort: a failure to write the log never breaks the
 * business action (it is reported to error_log instead).
 */

require_once __DIR__ . '/Database.php';

class AuditLog {

    /**
     * Record an action in activity_log.
     *
     * @param string      $action   short action key e.g. 'create_visit'
     * @param string|null $table    affected table name
     * @param int|null    $recordId affected record id
     * @param array|null  $old      old values (before)
     * @param array|null  $new      new values (after)
     * @param int|null    $userId   acting user; defaults to logged-in user
     */
    public static function log($action, $table = null, $recordId = null, $old = null, $new = null, $userId = null) {
        try {
            $db = Database::getInstance();
            $db->insert('activity_log', [
                'user_id'    => $userId ?? ($_SESSION['user_id'] ?? null),
                'action'     => mb_substr((string)$action, 0, 50),
                'table_name' => $table !== null ? mb_substr((string)$table, 0, 50) : null,
                'record_id'  => $recordId !== null ? (int)$recordId : null,
                'old_values' => $old ? json_encode(self::sanitize($old), JSON_UNESCAPED_UNICODE) : null,
                'new_values' => $new ? json_encode(self::sanitize($new), JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            ]);
        } catch (Exception $e) {
            error_log('[AuditLog] failed for action "' . $action . '": ' . $e->getMessage());
        }
    }

    /**
     * Diff old row vs. new data and log only the fields that actually changed.
     * Returns true when a change was logged, false when nothing changed.
     */
    public static function logChange($action, $table, $recordId, array $oldRow, array $newData, $userId = null) {
        $oldChanged = [];
        $newChanged = [];
        foreach ($newData as $field => $value) {
            $oldValue = array_key_exists($field, $oldRow) ? $oldRow[$field] : null;
            // Loose comparison after string cast so 5 == "5" and "0.00" == 0 don't log as changes
            if ((string)$oldValue !== (string)$value) {
                $oldChanged[$field] = $oldValue;
                $newChanged[$field] = $value;
            }
        }

        if (empty($newChanged)) {
            return false;
        }

        self::log($action, $table, $recordId, $oldChanged, $newChanged, $userId);
        return true;
    }

    /**
     * Fetch the audit history of one record (newest first), joined with the user.
     */
    public static function getForRecord($table, $recordId, $limit = 50) {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT al.*, u.full_name AS user_name, u.username, u.role AS user_role
             FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.table_name = :t AND al.record_id = :r
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT " . max(1, (int)$limit),
            ['t' => $table, 'r' => (int)$recordId]
        );
    }

    /**
     * Fetch recent activity of one user (newest first).
     */
    public static function getForUser($userId, $limit = 100) {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT al.* FROM activity_log al
             WHERE al.user_id = :u
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT " . max(1, (int)$limit),
            ['u' => (int)$userId]
        );
    }

    /** Never store secrets in the audit trail. */
    private static function sanitize($values) {
        if (!is_array($values)) {
            return $values;
        }
        foreach (['password', 'password_hash', 'new_password', 'current_password'] as $secret) {
            if (array_key_exists($secret, $values)) {
                $values[$secret] = '***';
            }
        }
        return $values;
    }
}
