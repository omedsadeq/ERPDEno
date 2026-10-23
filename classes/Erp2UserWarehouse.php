<?php
/**
 * Erp2UserWarehouse — which warehouses a user may work in.
 *
 * The point is to stop the shop cashier reaching the main store. Enforcement
 * happens in two places on purpose:
 *
 *   - erp2_warehouses() filters every dropdown, so a restricted user never even
 *     sees a warehouse they cannot use
 *   - assertCanUse() is called by the document services, because a dropdown is
 *     only a suggestion and a hand-written POST ignores it entirely
 *
 * DEFAULT IS OPEN. A user with no assignment may use every warehouse.
 * Restriction is something an admin switches on for the accounts that need it.
 * The alternative — default closed — would have locked every existing account
 * out of the ERP the moment the table was created.
 *
 * Admins are never restricted: someone has to be able to move stock between
 * the shop and the store, and an admin who locked themselves out of the main
 * warehouse would have no way back in through the UI.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Erp2Audit.php';

class Erp2UserWarehouse
{
    /** Per-request cache: this is consulted on nearly every ERP page load. */
    private static array $cache = [];

    /**
     * Warehouse ids this user may use, or NULL meaning "no restriction".
     * NULL and "all of them" are deliberately different: the caller can tell
     * an unrestricted user from one who happens to be assigned everything.
     */
    public static function allowedIds(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        if (array_key_exists($userId, self::$cache)) {
            return self::$cache[$userId];
        }

        $rows = Database::getInstance()->fetchAll(
            "SELECT warehouse_id FROM erp2_user_warehouses WHERE user_id = :u",
            ['u' => $userId]
        );

        $ids = array_map('intval', array_column($rows, 'warehouse_id'));
        self::$cache[$userId] = $ids ? $ids : null;   // no rows = unrestricted
        return self::$cache[$userId];
    }

    /** True when this user is limited to a subset of warehouses. */
    public static function isRestricted(int $userId): bool
    {
        return self::allowedIds($userId) !== null;
    }

    /**
     * May this user act in this warehouse?
     *
     * @param bool $isAdmin admins bypass the restriction entirely
     */
    public static function canUse(int $userId, int $warehouseId, bool $isAdmin = false): bool
    {
        if ($isAdmin) {
            return true;
        }
        $allowed = self::allowedIds($userId);
        if ($allowed === null) {
            return true;
        }
        return in_array($warehouseId, $allowed, true);
    }

    /**
     * Throw unless the user may act in this warehouse.
     *
     * Called by the services that move stock. The message deliberately names
     * the warehouse: "you may not use this warehouse" with no name reads as a
     * bug rather than a policy the operator can act on.
     */
    public static function assertCanUse(int $userId, int $warehouseId, bool $isAdmin = false): void
    {
        if (self::canUse($userId, $warehouseId, $isAdmin)) {
            return;
        }
        $row = Database::getInstance()->fetch(
            "SELECT name FROM erp2_warehouses WHERE id = :id",
            ['id' => $warehouseId]
        );
        throw new RuntimeException(
            'ڕێگەت پێنەدراوە لەم کۆگایەدا کار بکەیت: ' . ($row['name'] ?? ('#' . $warehouseId))
        );
    }

    /** Replace a user's assignment. An empty list removes the restriction. */
    public static function save(int $userId, array $warehouseIds, ?int $actorId = null): void
    {
        $db = Database::getInstance();

        $clean = [];
        foreach ($warehouseIds as $w) {
            $w = (int)$w;
            if ($w > 0) { $clean[$w] = true; }
        }
        $clean = array_keys($clean);

        $inTx = $db->inTransaction();
        if (!$inTx) { $db->beginTransaction(); }
        try {
            $old = self::allowedIds($userId);

            $db->query("DELETE FROM erp2_user_warehouses WHERE user_id = :u", ['u' => $userId]);
            foreach ($clean as $wid) {
                $db->insert('erp2_user_warehouses', [
                    'user_id'      => $userId,
                    'warehouse_id' => $wid,
                    'created_by'   => $actorId,
                ]);
            }

            if (!$inTx) { $db->commit(); }
            unset(self::$cache[$userId]);

            Erp2Audit::log('user_warehouses', 'USER', $userId, null,
                ['warehouses' => $old], ['warehouses' => $clean ?: null]);

        } catch (Throwable $e) {
            if (!$inTx && $db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /** Users with a restriction, for the admin screen's summary column. */
    public static function assignments(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT uw.user_id, u.full_name, u.role,
                    GROUP_CONCAT(w.name ORDER BY w.name SEPARATOR '، ') AS warehouse_names
             FROM erp2_user_warehouses uw
             JOIN users u ON u.id = uw.user_id
             JOIN erp2_warehouses w ON w.id = uw.warehouse_id
             GROUP BY uw.user_id, u.full_name, u.role
             ORDER BY u.full_name"
        );
    }
}
