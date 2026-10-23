<?php
/**
 * ErpAudit — audit trail for ERP documents.
 *
 * Separate from the dental AuditLog/activity_log on purpose: ERP retention,
 * volume and reporting are different from clinical audit, and mixing them
 * makes both harder to search. Same idea, different table.
 *
 * Fail-soft by design. An audit write must never be the reason a sale fails
 * to save, so every error is logged and swallowed. The caller's transaction
 * is left untouched.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class ErpAudit
{
    /**
     * @param string $action    create_sale, edit_sale, cancel_invoice, payment,
     *                          stock_adjustment, stock_transfer, price_change...
     * @param mixed  $old       state before (array/scalar/null) — JSON encoded
     * @param mixed  $new       state after
     */
    public static function log(
        string $action,
        ?string $docType = null,
        ?int $docId = null,
        ?string $docNumber = null,
        $old = null,
        $new = null
    ): void {
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO erp_audit_logs
                    (user_id, action, doc_type, doc_id, doc_number, old_value, new_value, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                mb_substr($action, 0, 60),
                $docType !== null ? mb_substr($docType, 0, 40) : null,
                $docId,
                $docNumber !== null ? mb_substr($docNumber, 0, 40) : null,
                self::encode($old),
                self::encode($new),
                self::clientIp(),
            ]);
        } catch (Throwable $e) {
            error_log('[ErpAudit] ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * Log only the fields that actually changed, so a price-change entry shows
     * the price and not fifty identical columns.
     */
    public static function logChange(
        string $action,
        string $docType,
        int $docId,
        ?string $docNumber,
        array $oldRow,
        array $newRow
    ): void {
        $oldDiff = [];
        $newDiff = [];
        foreach ($newRow as $field => $value) {
            $before = $oldRow[$field] ?? null;
            // String-cast comparison so 5 == "5" and "0.00" == 0 are not
            // reported as edits by PDO's string-typed returns.
            if ((string)$before !== (string)$value) {
                $oldDiff[$field] = $before;
                $newDiff[$field] = $value;
            }
        }
        if (!$oldDiff && !$newDiff) {
            return;
        }
        self::log($action, $docType, $docId, $docNumber, $oldDiff, $newDiff);
    }

    private static function encode($v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Client IP. Trusts no proxy header by default: on this LAN the requests
     * arrive directly, and honouring X-Forwarded-For would let a client forge
     * the address recorded against its own actions.
     */
    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip !== null ? mb_substr($ip, 0, 45) : null;
    }
}
