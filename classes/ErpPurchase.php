<?php
/**
 * ErpPurchase — purchase invoice lifecycle and purchase returns.
 *
 * The mirror of ErpSales: stock comes IN and the supplier is CREDITED.
 * Same transaction discipline — invoice, items, stock movements, supplier
 * ledger and audit all commit together or not at all.
 *
 * This is where cost enters the system. Each received line carries its own
 * unit cost, and ErpStock::receive() rolls it into the warehouse's weighted
 * average. Getting the landed cost right here is what makes every later
 * profit figure right.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ErpStock.php';
require_once __DIR__ . '/ErpSequence.php';
require_once __DIR__ . '/ErpLedger.php';
require_once __DIR__ . '/ErpAudit.php';

class ErpPurchase
{
    /**
     * @param array $data supplier_id, warehouse_id, invoice_date, supplier_invoice_no,
     *                    discount_amount, tax_amount, paid_amount, payment_type, notes,
     *                    items[] => [product_id, quantity, unit_price, discount_amount,
     *                                tax_amount, batch_number, expiry_date, unit_factor]
     * @param array $opts update_product_price => also write the new cost onto
     *                    erp_products.purchase_price (default true)
     */
    public static function create(array $data, array $opts = []): array
    {
        $db = Database::getInstance();

        $supplierId  = (int)($data['supplier_id'] ?? 0);
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $items       = $data['items'] ?? [];

        if (!$items) {
            throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵا هەیە');
        }
        if ($supplierId <= 0 || !$db->fetch("SELECT id FROM erp_suppliers WHERE id = :id", ['id' => $supplierId])) {
            throw new RuntimeException('دابینکەر نەدۆزرایەوە');
        }
        if ($warehouseId <= 0 || !$db->fetch("SELECT id FROM erp_warehouses WHERE id = :id AND is_active = 1", ['id' => $warehouseId])) {
            throw new RuntimeException('کۆگا نەدۆزرایەوە');
        }

        $invoiceDate = self::validDate($data['invoice_date'] ?? null) ?: date('Y-m-d');
        $headDisc    = max(0, (float)($data['discount_amount'] ?? 0));
        $headTax     = max(0, (float)($data['tax_amount'] ?? 0));
        $paid        = max(0, (float)($data['paid_amount'] ?? 0));
        $updatePrice = !array_key_exists('update_product_price', $opts) || $opts['update_product_price'];

        $db->beginTransaction();
        try {
            $number = ErpSequence::next('PURCHASE', 0);

            $invoiceId = (int)$db->insert('erp_purchase_invoices', [
                'invoice_number'      => $number,
                'supplier_invoice_no' => trim((string)($data['supplier_invoice_no'] ?? '')) ?: null,
                'supplier_id'         => $supplierId,
                'warehouse_id'        => $warehouseId,
                'invoice_date'        => $invoiceDate,
                'subtotal'            => 0,
                'discount_amount'     => $headDisc,
                'tax_amount'          => $headTax,
                'grand_total'         => 0,
                'paid_amount'         => $paid,
                'payment_type'        => self::paymentType($data['payment_type'] ?? null),
                'status'              => 'confirmed',
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $_SESSION['user_id'] ?? null,
            ]);

            $subtotal = 0.0;
            foreach ($items as $raw) {
                $productId = (int)($raw['product_id'] ?? 0);
                $qty       = (float)($raw['quantity'] ?? 0);
                $price     = (float)($raw['unit_price'] ?? 0);
                $factor    = (float)($raw['unit_factor'] ?? 1) ?: 1.0;
                $lineDisc  = max(0, (float)($raw['discount_amount'] ?? 0));
                $lineTax   = max(0, (float)($raw['tax_amount'] ?? 0));

                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                $lineTotal = max(0, ($qty * $price) - $lineDisc + $lineTax);
                $baseQty   = $qty * $factor;

                // Cost per BASE unit, net of the line discount. Using the raw
                // price would overstate cost on a discounted line and quietly
                // understate every future sale's profit.
                $unitCost = $baseQty > 0 ? ($lineTotal / $baseQty) : 0.0;

                ErpStock::receive(
                    $productId, $warehouseId, $baseQty, $unitCost,
                    'PURCHASE', $invoiceId, $number,
                    [
                        'batch_number' => $raw['batch_number'] ?? null,
                        'expiry_date'  => self::validDate($raw['expiry_date'] ?? null),
                    ]
                );

                $db->insert('erp_purchase_items', [
                    'invoice_id'      => $invoiceId,
                    'product_id'      => $productId,
                    'unit_id'         => ((int)($raw['unit_id'] ?? 0)) ?: null,
                    'unit_factor'     => $factor,
                    'quantity'        => round($qty, 3),
                    'unit_price'      => round($price, 2),
                    'discount_amount' => round($lineDisc, 2),
                    'tax_amount'      => round($lineTax, 2),
                    'line_total'      => round($lineTotal, 2),
                    'batch_number'    => $raw['batch_number'] ?? null,
                    'expiry_date'     => self::validDate($raw['expiry_date'] ?? null),
                ]);

                if ($updatePrice) {
                    $db->query(
                        "UPDATE erp_products SET purchase_price = :p WHERE id = :id",
                        ['p' => round($unitCost, 2), 'id' => $productId]
                    );
                }

                $subtotal += $lineTotal;
            }

            if ($subtotal <= 0) {
                throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵای دروست هەیە');
            }

            $grand = max(0, $subtotal - $headDisc + $headTax);
            $db->update('erp_purchase_invoices', [
                'subtotal'    => round($subtotal, 2),
                'grand_total' => round($grand, 2),
            ], 'id = ?', [$invoiceId]);

            // Purchase credits the supplier (we owe them); cash paid now debits.
            ErpLedger::supplierEntry($supplierId, 'PURCHASE', 0, $grand, $invoiceId, $number, $invoiceDate);
            if ($paid > 0) {
                ErpLedger::supplierEntry($supplierId, 'PAYMENT', min($paid, $grand), 0,
                    $invoiceId, $number, $invoiceDate, 'پارەی وەسڵ');
            }

            $db->commit();

            ErpAudit::log('create_purchase', 'PURCHASE', $invoiceId, $number, null, [
                'supplier_id' => $supplierId,
                'grand_total' => $grand,
                'items'       => count($items),
            ]);

            return ['id' => $invoiceId, 'invoice_number' => $number, 'grand_total' => $grand];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Purchase return: goods go back to the supplier.
     *
     * The original invoice is never modified — it is linked, and the returned
     * quantity is tracked on its lines so the same goods cannot be returned
     * twice.
     *
     * @param array $data supplier_id, warehouse_id, original_invoice_id, return_date,
     *                    reason, items[] => [product_id, quantity, unit_price, item_id]
     */
    public static function createReturn(array $data): array
    {
        $db = Database::getInstance();

        $supplierId  = (int)($data['supplier_id'] ?? 0);
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $originalId  = ((int)($data['original_invoice_id'] ?? 0)) ?: null;
        $items       = $data['items'] ?? [];

        if (!$items) {
            throw new RuntimeException('گەڕاندنەوە پێویستی بە لانیکەم یەک کاڵا هەیە');
        }

        $returnDate = self::validDate($data['return_date'] ?? null) ?: date('Y-m-d');

        $db->beginTransaction();
        try {
            $number = ErpSequence::next('PURCHASE_RETURN', 0);

            $returnId = (int)$db->insert('erp_purchase_returns', [
                'return_number'       => $number,
                'original_invoice_id' => $originalId,
                'supplier_id'         => $supplierId,
                'warehouse_id'        => $warehouseId,
                'return_date'         => $returnDate,
                'subtotal'            => 0,
                'grand_total'         => 0,
                'reason'              => $data['reason'] ?? null,
                'status'              => 'confirmed',
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $_SESSION['user_id'] ?? null,
            ]);

            $subtotal = 0.0;
            foreach ($items as $raw) {
                $productId = (int)($raw['product_id'] ?? 0);
                $qty       = (float)($raw['quantity'] ?? 0);
                $price     = (float)($raw['unit_price'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                // Guard against returning more than was bought on that line.
                if (!empty($raw['item_id'])) {
                    $line = $db->fetch(
                        "SELECT quantity, returned_qty FROM erp_purchase_items WHERE id = :id",
                        ['id' => (int)$raw['item_id']]
                    );
                    if ($line) {
                        $remaining = (float)$line['quantity'] - (float)$line['returned_qty'];
                        if ($qty > $remaining + 0.0005) {
                            throw new RuntimeException(sprintf(
                                'ناتوانرێت %s بگەڕێندرێتەوە — تەنها %s ماوە',
                                rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                                rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.')
                            ));
                        }
                        $db->query(
                            "UPDATE erp_purchase_items SET returned_qty = returned_qty + :q WHERE id = :id",
                            ['q' => $qty, 'id' => (int)$raw['item_id']]
                        );
                    }
                }

                $moved = ErpStock::issue(
                    $productId, $warehouseId, $qty, 'PURCHASE_RETURN',
                    $returnId, $number, ['allow_negative' => false]
                );

                $lineTotal = $qty * $price;
                $db->insert('erp_purchase_return_items', [
                    'return_id'  => $returnId,
                    'product_id' => $productId,
                    'quantity'   => round($qty, 3),
                    'unit_price' => round($price, 2),
                    'unit_cost'  => round($moved['unit_cost'], 4),
                    'line_total' => round($lineTotal, 2),
                ]);
                $subtotal += $lineTotal;
            }

            if ($subtotal <= 0) {
                throw new RuntimeException('گەڕاندنەوە پێویستی بە لانیکەم یەک کاڵای دروست هەیە');
            }

            $db->update('erp_purchase_returns', [
                'subtotal'    => round($subtotal, 2),
                'grand_total' => round($subtotal, 2),
            ], 'id = ?', [$returnId]);

            // Returning goods reduces what we owe: supplier is DEBITED.
            ErpLedger::supplierEntry($supplierId, 'PURCHASE_RETURN', $subtotal, 0,
                $returnId, $number, $returnDate);

            $db->commit();

            ErpAudit::log('purchase_return', 'PURCHASE_RETURN', $returnId, $number, null, [
                'supplier_id' => $supplierId,
                'grand_total' => $subtotal,
                'original'    => $originalId,
            ]);

            return ['id' => $returnId, 'return_number' => $number, 'grand_total' => $subtotal];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * A purchase invoice's lines with how much of each may still go back.
     * Drives the return screen so it can never offer more than remains.
     *
     * Unlike a sales return, `returnable` here is capped twice over: by what
     * was bought and not yet returned, AND by what is still physically in the
     * warehouse. You cannot send back goods you have already sold on, and
     * showing the buy quantity alone would invite a return the stock engine
     * then refuses at save time.
     */
    public static function returnableLines(int $invoiceId): array
    {
        $db = Database::getInstance();
        $inv = $db->fetch(
            "SELECT pi.id, pi.invoice_number, pi.invoice_date, pi.supplier_id, pi.warehouse_id,
                    pi.status, s.name AS supplier_name, w.name AS warehouse_name
             FROM erp_purchase_invoices pi
             JOIN erp_suppliers s ON s.id = pi.supplier_id
             JOIN erp_warehouses w ON w.id = pi.warehouse_id
             WHERE pi.id = :id",
            ['id' => $invoiceId]
        );
        if (!$inv) {
            return [];
        }

        $inv['items'] = $db->fetchAll(
            "SELECT it.id AS item_id, it.product_id, it.quantity, it.returned_qty,
                    it.unit_price, it.unit_factor,
                    (it.quantity - it.returned_qty) AS un_returned,
                    COALESCE(st.quantity, 0) AS on_hand,
                    p.name AS product_name, p.product_code
             FROM erp_purchase_items it
             JOIN erp_products p ON p.id = it.product_id
             LEFT JOIN erp_warehouse_product_stock st
                    ON st.product_id = it.product_id AND st.warehouse_id = :wh
             WHERE it.invoice_id = :id
             ORDER BY it.id",
            ['id' => $invoiceId, 'wh' => (int)$inv['warehouse_id']]
        );

        foreach ($inv['items'] as &$it) {
            // Line quantities are in the purchase's unit; stock is in base
            // units. Compare like with like before taking the lower of the two.
            $factor   = (float)($it['unit_factor'] ?: 1);
            $unret    = (float)$it['un_returned'];
            $onHandIn = $factor > 0 ? ((float)$it['on_hand'] / $factor) : 0.0;
            $it['returnable'] = round(max(0, min($unret, $onHandIn)), 3);
        }
        unset($it);

        return $inv;
    }

    public static function load(int $invoiceId): ?array
    {
        $db = Database::getInstance();
        $inv = $db->fetch(
            "SELECT pi.*, s.name AS supplier_name, s.phone AS supplier_phone,
                    w.name AS warehouse_name
             FROM erp_purchase_invoices pi
             JOIN erp_suppliers s ON s.id = pi.supplier_id
             JOIN erp_warehouses w ON w.id = pi.warehouse_id
             WHERE pi.id = :id",
            ['id' => $invoiceId]
        );
        if (!$inv) {
            return null;
        }
        $inv['items'] = $db->fetchAll(
            "SELECT it.*, p.name AS product_name, p.product_code, p.barcode
             FROM erp_purchase_items it
             JOIN erp_products p ON p.id = it.product_id
             WHERE it.invoice_id = :id
             ORDER BY it.id",
            ['id' => $invoiceId]
        );
        return $inv;
    }

    private static function paymentType($v): string
    {
        return in_array($v, ['cash', 'credit', 'partial'], true) ? $v : 'cash';
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
