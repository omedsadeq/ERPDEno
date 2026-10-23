<?php
/**
 * Erp2Stock — the stock and costing engine. Every quantity change in the ERP
 * goes through receive() or issue(); nothing writes stock directly.
 *
 * ── Costing: Weighted Average Cost (WAC) ────────────────────────────────
 * One method, used everywhere, per the module brief.
 *
 *   Receiving   new_avg = (old_qty * old_avg + in_qty * in_cost) / new_qty
 *   Issuing     average is UNCHANGED. The issue consumes stock at the current
 *               average, and that consumed cost is stamped on the movement.
 *
 * Issuing not moving the average is the property that makes profit stable: a
 * sale's cost is fixed at the moment it happens, so a later purchase at a new
 * price can never retroactively rewrite yesterday's profit.
 *
 * ── Two-layer storage ───────────────────────────────────────────────────
 *   erp2_stock_movements          append-only truth, never updated or deleted
 *   erp2_warehouse_product_stock  cache of (quantity, average_cost) so the
 *                                sales grid reads one row instead of SUMming
 *                                a million movements
 * rebuild() regenerates the cache from the movements, so the cache can never
 * become the only copy of the truth.
 *
 * ── Concurrency ─────────────────────────────────────────────────────────
 * Both entry points require an open transaction and lock the cache row with
 * SELECT ... FOR UPDATE before reading it. Without that lock two concurrent
 * sales of the same product both read the same starting quantity and the
 * second silently overwrites the first — stock drifts upward and never
 * reconciles. The lock makes the read-modify-write atomic.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Erp2Stock
{
    /** Movements that add stock. */
    const IN_TYPES  = ['PURCHASE', 'SALE_RETURN', 'TRANSFER_IN', 'ADJUSTMENT', 'OPENING'];
    /** Movements that remove stock. */
    const OUT_TYPES = ['SALE', 'PURCHASE_RETURN', 'TRANSFER_OUT', 'ADJUSTMENT', 'DAMAGE'];

    /** Quantities are DECIMAL(15,3); compare against half a unit of that. */
    const QTY_EPSILON = 0.0005;

    /**
     * Add stock and roll the weighted average forward.
     *
     * @param float $unitCost cost per unit of THIS receipt, not the product's
     *                        list purchase price.
     * @return array{quantity: float, average_cost: float, movement_id: int}
     */
    public static function receive(
        int $productId,
        int $warehouseId,
        float $quantity,
        float $unitCost,
        string $type,
        ?int $documentId = null,
        ?string $documentNumber = null,
        array $opts = []
    ): array {
        if (!in_array($type, self::IN_TYPES, true)) {
            throw new InvalidArgumentException("receive() cannot be used with movement type {$type}");
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('receive() needs a positive quantity');
        }

        $db = self::requireTransaction();
        $current = self::lockRow($productId, $warehouseId);

        $oldQty = (float)$current['quantity'];
        $oldAvg = (float)$current['average_cost'];
        $newQty = $oldQty + $quantity;

        // Weighted average. Guarded because $oldQty can be <= 0 two ways:
        // a first-ever receipt (0), or stock sold into negative if the
        // operator allowed it. In both cases the only meaningful cost is the
        // incoming one — blending against a negative quantity would produce a
        // nonsense (possibly negative) average that then prices every sale.
        if ($oldQty > 0 && $newQty > 0) {
            $newAvg = (($oldQty * $oldAvg) + ($quantity * $unitCost)) / $newQty;
        } else {
            $newAvg = $unitCost;
        }

        self::writeCache($productId, $warehouseId, $newQty, $newAvg);
        $movementId = self::writeMovement([
            'product_id'       => $productId,
            'warehouse_id'     => $warehouseId,
            'transaction_type' => $type,
            'transaction_id'   => $documentId,
            'document_number'  => $documentNumber,
            'quantity_in'      => $quantity,
            'quantity_out'     => 0,
            'unit_cost'        => $unitCost,
            'balance_after'    => $newQty,
            'batch_number'     => $opts['batch_number'] ?? null,
            'expiry_date'      => $opts['expiry_date'] ?? null,
            'notes'            => $opts['notes'] ?? null,
            'transaction_item_id' => $opts['item_id'] ?? null,
        ]);

        return ['quantity' => $newQty, 'average_cost' => $newAvg, 'movement_id' => $movementId];
    }

    /**
     * Remove stock at the current weighted average cost.
     *
     * The average is deliberately NOT recalculated here — see the class note.
     *
     * @param array $opts 'allow_negative' => true to permit overselling
     *                    (default false: a store that cannot go below zero
     *                    catches mis-scans at the till instead of at audit).
     *                    'unit_cost' => override the cost consumed, used by a
     *                    sales return so stock goes back at the cost it left.
     * @return array{quantity: float, average_cost: float, unit_cost: float, movement_id: int}
     */
    public static function issue(
        int $productId,
        int $warehouseId,
        float $quantity,
        string $type,
        ?int $documentId = null,
        ?string $documentNumber = null,
        array $opts = []
    ): array {
        if (!in_array($type, self::OUT_TYPES, true)) {
            throw new InvalidArgumentException("issue() cannot be used with movement type {$type}");
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('issue() needs a positive quantity');
        }

        $db = self::requireTransaction();
        $current = self::lockRow($productId, $warehouseId);

        $oldQty = (float)$current['quantity'];
        $avg    = (float)$current['average_cost'];
        $newQty = $oldQty - $quantity;

        if ($newQty < -self::QTY_EPSILON && empty($opts['allow_negative'])) {
            $name = self::productName($productId);
            throw new RuntimeException(sprintf(
                'بڕی پێویست لە کۆگا نییە: %s — داواکراو %s، بەردەست %s',
                $name,
                rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($oldQty, 3, '.', ''), '0'), '.')
            ));
        }

        $unitCost = isset($opts['unit_cost']) ? (float)$opts['unit_cost'] : $avg;

        // Quantity changes, average does not.
        self::writeCache($productId, $warehouseId, $newQty, $avg);
        $movementId = self::writeMovement([
            'product_id'       => $productId,
            'warehouse_id'     => $warehouseId,
            'transaction_type' => $type,
            'transaction_id'   => $documentId,
            'document_number'  => $documentNumber,
            'quantity_in'      => 0,
            'quantity_out'     => $quantity,
            'unit_cost'        => $unitCost,
            'balance_after'    => $newQty,
            'batch_number'     => $opts['batch_number'] ?? null,
            'expiry_date'      => $opts['expiry_date'] ?? null,
            'notes'            => $opts['notes'] ?? null,
            'transaction_item_id' => $opts['item_id'] ?? null,
        ]);

        return [
            'quantity'     => $newQty,
            'average_cost' => $avg,
            'unit_cost'    => $unitCost,
            'movement_id'  => $movementId,
        ];
    }

    /** Quantity and average cost in one warehouse. Never returns null. */
    public static function levelOf(int $productId, int $warehouseId): array
    {
        $row = Database::getInstance()->fetch(
            "SELECT quantity, average_cost
             FROM erp2_warehouse_product_stock
             WHERE product_id = :p AND warehouse_id = :w",
            ['p' => $productId, 'w' => $warehouseId]
        );
        return [
            'quantity'     => (float)($row['quantity'] ?? 0),
            'average_cost' => (float)($row['average_cost'] ?? 0),
        ];
    }

    /** Per-warehouse breakdown for one product, plus the total. */
    public static function levelsByWarehouse(int $productId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT w.id AS warehouse_id, w.name AS warehouse_name, w.code,
                    COALESCE(s.quantity, 0) AS quantity,
                    COALESCE(s.average_cost, 0) AS average_cost
             FROM erp2_warehouses w
             LEFT JOIN erp2_warehouse_product_stock s
                    ON s.warehouse_id = w.id AND s.product_id = :p
             WHERE w.is_active = 1
             ORDER BY w.is_default DESC, w.name",
            ['p' => $productId]
        );
        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float)$r['quantity'];
        }
        return ['warehouses' => $rows, 'total' => $total];
    }

    /**
     * Recompute the cache for one product+warehouse by replaying its
     * movements. The movement history is the truth, so this is the repair
     * tool if the cache is ever suspected of drifting.
     *
     * Replays the same WAC arithmetic as receive()/issue() in id order.
     *
     * @return array{quantity: float, average_cost: float, movements: int}
     */
    public static function rebuild(int $productId, int $warehouseId): array
    {
        $db = self::requireTransaction();

        $rows = $db->fetchAll(
            "SELECT transaction_type, quantity_in, quantity_out, unit_cost
             FROM erp2_stock_movements
             WHERE product_id = :p AND warehouse_id = :w
             ORDER BY id",
            ['p' => $productId, 'w' => $warehouseId]
        );

        $qty = 0.0;
        $avg = 0.0;
        foreach ($rows as $r) {
            $in   = (float)$r['quantity_in'];
            $out  = (float)$r['quantity_out'];
            $cost = (float)$r['unit_cost'];
            if ($in > 0) {
                $newQty = $qty + $in;
                $avg = ($qty > 0 && $newQty > 0)
                    ? (($qty * $avg) + ($in * $cost)) / $newQty
                    : $cost;
                $qty = $newQty;
            }
            if ($out > 0) {
                $qty -= $out;
            }
        }

        self::writeCache($productId, $warehouseId, $qty, $avg);
        return ['quantity' => $qty, 'average_cost' => $avg, 'movements' => count($rows)];
    }

    /** Total value of stock on hand: SUM(quantity * average_cost). */
    public static function totalStockValue(?int $warehouseId = null): float
    {
        $sql = "SELECT COALESCE(SUM(quantity * average_cost), 0) AS v
                FROM erp2_warehouse_product_stock
                WHERE quantity > 0";
        $params = [];
        if ($warehouseId !== null) {
            $sql .= " AND warehouse_id = :w";
            $params['w'] = $warehouseId;
        }
        $row = Database::getInstance()->fetch($sql, $params);
        return (float)($row['v'] ?? 0);
    }

    // ── internals ───────────────────────────────────────────────────────

    private static function requireTransaction(): Database
    {
        $db = Database::getInstance();
        if (!$db->inTransaction()) {
            throw new RuntimeException(
                'Erp2Stock requires an open transaction so the stock row lock is held until commit'
            );
        }
        return $db;
    }

    /**
     * Lock (and create if missing) the cache row, returning its current state.
     *
     * INSERT IGNORE before SELECT ... FOR UPDATE on purpose: FOR UPDATE cannot
     * lock a row that does not exist, so without seeding it first, two
     * concurrent first-ever movements for a product would both find nothing
     * and both insert.
     */
    private static function lockRow(int $productId, int $warehouseId): array
    {
        $pdo = Database::getInstance()->getConnection();

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO erp2_warehouse_product_stock
                 (warehouse_id, product_id, quantity, average_cost, updated_at)
             VALUES (?, ?, 0, 0, NOW())"
        );
        $ins->execute([$warehouseId, $productId]);

        $sel = $pdo->prepare(
            "SELECT quantity, average_cost
             FROM erp2_warehouse_product_stock
             WHERE product_id = ? AND warehouse_id = ?
             FOR UPDATE"
        );
        $sel->execute([$productId, $warehouseId]);
        $row = $sel->fetch();

        return [
            'quantity'     => (float)($row['quantity'] ?? 0),
            'average_cost' => (float)($row['average_cost'] ?? 0),
        ];
    }

    private static function writeCache(int $productId, int $warehouseId, float $qty, float $avg): void
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE erp2_warehouse_product_stock
             SET quantity = ?, average_cost = ?, updated_at = NOW()
             WHERE product_id = ? AND warehouse_id = ?"
        );
        $stmt->execute([
            round($qty, 3),
            round(max(0, $avg), 4),
            $productId,
            $warehouseId,
        ]);
    }

    private static function writeMovement(array $m): int
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO erp2_stock_movements
                (product_id, warehouse_id, transaction_type, transaction_id, transaction_item_id,
                 document_number, quantity_in, quantity_out, unit_cost, balance_after,
                 batch_number, expiry_date, notes, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->execute([
            $m['product_id'],
            $m['warehouse_id'],
            $m['transaction_type'],
            $m['transaction_id'],
            $m['transaction_item_id'],
            $m['document_number'],
            round((float)$m['quantity_in'], 3),
            round((float)$m['quantity_out'], 3),
            round((float)$m['unit_cost'], 4),
            round((float)$m['balance_after'], 3),
            $m['batch_number'],
            $m['expiry_date'] ?: null,
            $m['notes'],
            $_SESSION['user_id'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function productName(int $productId): string
    {
        $row = Database::getInstance()->fetch(
            "SELECT name FROM erp2_products WHERE id = :id",
            ['id' => $productId]
        );
        return $row['name'] ?? ('#' . $productId);
    }
}
