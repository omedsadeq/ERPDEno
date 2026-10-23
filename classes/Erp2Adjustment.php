<?php
/**
 * Erp2Adjustment — correct stock to a counted figure, with a reason.
 *
 * The only sanctioned way to change a quantity without a purchase, sale or
 * transfer. Stock is never written directly: an adjustment produces a real
 * movement like everything else, so the ledger still explains every unit and
 * Erp2Stock::rebuild() can still reproduce the cache from history.
 *
 * The caller supplies the COUNTED quantity (qty_after), not a delta. A stock
 * take is "there are 47 on the shelf", and letting the screen compute the
 * difference invites an operator arithmetic error that is then recorded as
 * fact. The class re-reads the current figure inside the transaction and
 * derives the difference itself, which also closes the window where a sale
 * lands between counting and saving.
 *
 * Costing:
 *   increase - needs a unit cost. Uses the warehouse's current average when
 *              one exists, else the caller's figure, else the product's
 *              purchase price. An increase at zero cost would drag the
 *              weighted average toward zero and inflate every later profit.
 *   decrease - consumes at the current average, like any other issue.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Erp2Stock.php';
require_once __DIR__ . '/Erp2Sequence.php';
require_once __DIR__ . '/Erp2Audit.php';

class Erp2Adjustment
{
    /** Reasons that ADD stock when the count is higher than the record. */
    const REASONS = ['OPENING', 'COUNT', 'DAMAGE', 'EXPIRED', 'LOST', 'FOUND', 'CORRECTION'];

    /** Reasons that may only ever reduce stock — damage cannot create units. */
    const DECREASE_ONLY = ['DAMAGE', 'EXPIRED', 'LOST'];

    /**
     * @param array $data warehouse_id, adjustment_date, reason, notes,
     *                    items[] => [product_id, qty_after, unit_cost?, notes?]
     * @return array{id:int, adjustment_number:string, lines:int, increased:float, decreased:float}
     */
    public static function create(array $data): array
    {
        $db          = Database::getInstance();
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $reason      = strtoupper(trim((string)($data['reason'] ?? '')));
        $items       = $data['items'] ?? [];

        if (!in_array($reason, self::REASONS, true)) {
            throw new RuntimeException('هۆکاری ڕێکخستن نادروستە');
        }
        if (!$items) {
            throw new RuntimeException('ڕێکخستن پێویستی بە لانیکەم یەک کاڵا هەیە');
        }
        if ($warehouseId <= 0
            || !$db->fetch("SELECT id FROM erp2_warehouses WHERE id = :id AND is_active = 1", ['id' => $warehouseId])) {
            throw new RuntimeException('کۆگا نەدۆزرایەوە');
        }

        $date = self::validDate($data['adjustment_date'] ?? null) ?: date('Y-m-d');

        $db->beginTransaction();
        try {
            $number = Erp2Sequence::next('ADJUSTMENT', 0);

            $adjId = (int)$db->insert('erp2_stock_adjustments', [
                'adjustment_number' => $number,
                'warehouse_id'      => $warehouseId,
                'adjustment_date'   => $date,
                'reason'            => $reason,
                'status'            => 'confirmed',
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $_SESSION['user_id'] ?? null,
            ]);

            $lines = 0;
            $up = 0.0;
            $down = 0.0;

            foreach ($items as $raw) {
                $productId = (int)($raw['product_id'] ?? 0);
                if ($productId <= 0 || !array_key_exists('qty_after', $raw) || $raw['qty_after'] === '') {
                    continue;
                }
                $qtyAfter = round(max(0, (float)$raw['qty_after']), 3);

                // Read the live figure INSIDE the transaction, so the recorded
                // before/after pair is what actually happened rather than what
                // the screen was showing when the count started.
                $level    = Erp2Stock::levelOf($productId, $warehouseId);
                $qtyBefore = round((float)$level['quantity'], 3);
                $diff      = round($qtyAfter - $qtyBefore, 3);

                if (abs($diff) < 0.0005) {
                    continue; // counted the same as recorded — nothing to record
                }

                if ($diff > 0 && in_array($reason, self::DECREASE_ONLY, true)) {
                    throw new RuntimeException(
                        'هۆکاری «' . self::reasonLabel($reason) . '» تەنها دەتوانێت بڕ کەم بکاتەوە'
                    );
                }

                if ($diff > 0) {
                    $cost = (float)$level['average_cost'];
                    if ($cost <= 0) {
                        $cost = isset($raw['unit_cost']) && $raw['unit_cost'] !== ''
                            ? max(0, (float)$raw['unit_cost'])
                            : (float)($db->fetch(
                                "SELECT purchase_price FROM erp2_products WHERE id = :id",
                                ['id' => $productId]
                              )['purchase_price'] ?? 0);
                    }
                    $moved = Erp2Stock::receive(
                        $productId, $warehouseId, $diff, $cost,
                        $reason === 'OPENING' ? 'OPENING' : 'ADJUSTMENT',
                        $adjId, $number,
                        ['notes' => self::reasonLabel($reason)]
                    );
                    $unitCost = $cost;
                    $up += $diff;
                } else {
                    $moved = Erp2Stock::issue(
                        $productId, $warehouseId, abs($diff),
                        in_array($reason, ['DAMAGE', 'EXPIRED'], true) ? 'DAMAGE' : 'ADJUSTMENT',
                        $adjId, $number,
                        ['allow_negative' => false, 'notes' => self::reasonLabel($reason)]
                    );
                    $unitCost = $moved['unit_cost'];
                    $down += abs($diff);
                }

                $db->insert('erp2_stock_adjustment_items', [
                    'adjustment_id' => $adjId,
                    'product_id'    => $productId,
                    'qty_before'    => $qtyBefore,
                    'qty_after'     => $qtyAfter,
                    'difference'    => $diff,
                    'unit_cost'     => round($unitCost, 4),
                    'notes'         => $raw['notes'] ?? null,
                ]);
                $lines++;
            }

            if ($lines === 0) {
                throw new RuntimeException('هیچ جیاوازییەک نییە بۆ تۆمارکردن');
            }

            $db->commit();

            Erp2Audit::log('stock_adjustment', 'ADJUSTMENT', $adjId, $number, null, [
                'warehouse_id' => $warehouseId,
                'reason'       => $reason,
                'lines'        => $lines,
                'increased'    => $up,
                'decreased'    => $down,
            ]);

            return [
                'id'                => $adjId,
                'adjustment_number' => $number,
                'lines'             => $lines,
                'increased'         => $up,
                'decreased'         => $down,
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    public static function reasonLabel(string $reason): string
    {
        return [
            'OPENING'    => 'بڕی سەرەتایی',
            'COUNT'      => 'جیاوازی ژماردن',
            'DAMAGE'     => 'زیانلێکەوتوو',
            'EXPIRED'    => 'بەسەرچوو',
            'LOST'       => 'ونبوو',
            'FOUND'      => 'دۆزرایەوە',
            'CORRECTION' => 'ڕاستکردنەوە',
        ][$reason] ?? $reason;
    }

    public static function load(int $adjId): ?array
    {
        $db = Database::getInstance();
        $a = $db->fetch(
            "SELECT a.*, w.name AS warehouse_name, u.full_name AS created_by_name
             FROM erp2_stock_adjustments a
             JOIN erp2_warehouses w ON w.id = a.warehouse_id
             LEFT JOIN users u ON u.id = a.created_by
             WHERE a.id = :id",
            ['id' => $adjId]
        );
        if (!$a) {
            return null;
        }
        $a['items'] = $db->fetchAll(
            "SELECT i.*, p.name AS product_name, p.product_code
             FROM erp2_stock_adjustment_items i
             JOIN erp2_products p ON p.id = i.product_id
             WHERE i.adjustment_id = :id
             ORDER BY i.id",
            ['id' => $adjId]
        );
        return $a;
    }

    private static function validDate($v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }
}
