<?php
/**
 * Erp2Transfer — move stock between warehouses.
 *
 * A transfer is TWO movements on one document: TRANSFER_OUT of the source and
 * TRANSFER_IN to the destination, inside a single transaction. Either both
 * land or neither does — a half-applied transfer would invent or destroy
 * stock, which no later report could detect.
 *
 * COST TRAVELS WITH THE GOODS. The destination receives at the source's
 * weighted average cost, not at the product's list price. Moving stock between
 * your own warehouses is not a purchase and must not create profit or loss:
 * receiving at list price would silently revalue inventory on every move, and
 * a warehouse used as a staging area would drift further from reality each
 * time something passed through it.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Erp2Stock.php';
require_once __DIR__ . '/Erp2Sequence.php';
require_once __DIR__ . '/Erp2Audit.php';

class Erp2Transfer
{
    /**
     * @param array $data from_warehouse_id, to_warehouse_id, transfer_date, notes,
     *                    items[] => [product_id, quantity]
     * @return array{id:int, transfer_number:string, lines:int, total_cost:float}
     */
    public static function create(array $data): array
    {
        $db   = Database::getInstance();
        $from = (int)($data['from_warehouse_id'] ?? 0);
        $to   = (int)($data['to_warehouse_id'] ?? 0);
        $items = $data['items'] ?? [];

        if (!$items) {
            throw new RuntimeException('گواستنەوە پێویستی بە لانیکەم یەک کاڵا هەیە');
        }
        if ($from <= 0 || $to <= 0) {
            throw new RuntimeException('کۆگای سەرچاوە و مەبەست دیاری بکە');
        }
        if ($from === $to) {
            throw new RuntimeException('ناتوانرێت بۆ هەمان کۆگا بگوازرێتەوە');
        }
        foreach ([$from, $to] as $wid) {
            if (!$db->fetch("SELECT id FROM erp2_warehouses WHERE id = :id AND is_active = 1", ['id' => $wid])) {
                throw new RuntimeException('کۆگا نەدۆزرایەوە یان ناچالاکە');
            }
        }

        $date = self::validDate($data['transfer_date'] ?? null) ?: date('Y-m-d');

        $db->beginTransaction();
        try {
            $number = Erp2Sequence::next('TRANSFER', 0);

            $transferId = (int)$db->insert('erp2_stock_transfers', [
                'transfer_number'   => $number,
                'from_warehouse_id' => $from,
                'to_warehouse_id'   => $to,
                'transfer_date'     => $date,
                'status'            => 'confirmed',
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $_SESSION['user_id'] ?? null,
            ]);

            $lines = 0;
            $totalCost = 0.0;

            foreach ($items as $raw) {
                $productId = (int)($raw['product_id'] ?? 0);
                $qty       = (float)($raw['quantity'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                // OUT first: issue() returns the cost actually consumed, which
                // is what the destination must receive at. Doing it in this
                // order also means an insufficient-stock refusal happens
                // before anything has been added anywhere.
                $out = Erp2Stock::issue(
                    $productId, $from, $qty, 'TRANSFER_OUT', $transferId, $number,
                    ['allow_negative' => false, 'notes' => 'گواستنەوە']
                );

                Erp2Stock::receive(
                    $productId, $to, $qty, $out['unit_cost'],
                    'TRANSFER_IN', $transferId, $number,
                    ['notes' => 'گواستنەوە']
                );

                $db->insert('erp2_stock_transfer_items', [
                    'transfer_id' => $transferId,
                    'product_id'  => $productId,
                    'quantity'    => round($qty, 3),
                    'unit_cost'   => round($out['unit_cost'], 4),
                ]);

                $lines++;
                $totalCost += $qty * $out['unit_cost'];
            }

            if ($lines === 0) {
                throw new RuntimeException('گواستنەوە پێویستی بە لانیکەم یەک کاڵای دروست هەیە');
            }

            $db->commit();

            Erp2Audit::log('stock_transfer', 'TRANSFER', $transferId, $number, null, [
                'from' => $from, 'to' => $to, 'lines' => $lines, 'value' => round($totalCost, 2),
            ]);

            return [
                'id'              => $transferId,
                'transfer_number' => $number,
                'lines'           => $lines,
                'total_cost'      => $totalCost,
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    public static function load(int $transferId): ?array
    {
        $db = Database::getInstance();
        $t = $db->fetch(
            "SELECT t.*, wf.name AS from_name, wt.name AS to_name, u.full_name AS created_by_name
             FROM erp2_stock_transfers t
             JOIN erp2_warehouses wf ON wf.id = t.from_warehouse_id
             JOIN erp2_warehouses wt ON wt.id = t.to_warehouse_id
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.id = :id",
            ['id' => $transferId]
        );
        if (!$t) {
            return null;
        }
        $t['items'] = $db->fetchAll(
            "SELECT i.*, p.name AS product_name, p.product_code
             FROM erp2_stock_transfer_items i
             JOIN erp2_products p ON p.id = i.product_id
             WHERE i.transfer_id = :id
             ORDER BY i.id",
            ['id' => $transferId]
        );
        return $t;
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
