<?php
/**
 * Erp2Sales — sales invoice lifecycle: create, edit, cancel.
 *
 * Every method wraps ONE database transaction covering the invoice, its items,
 * the stock movements, the customer ledger and the audit row. If any part
 * fails the whole document rolls back, so stock can never move without an
 * invoice and an invoice can never exist without its stock.
 *
 * Profit: each line stores the Weighted Average Cost consumed at the instant
 * it was sold (Erp2Stock::issue returns it). line_profit is stored, not
 * derived, so the profit reports are indexed SUMs and a later price change
 * cannot rewrite history.
 *
 * Invoices are never deleted. cancel() posts opposing stock movements and a
 * reversing ledger entry and flips status to 'cancelled', leaving the number
 * and the trail intact.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Erp2Stock.php';
require_once __DIR__ . '/Erp2Sequence.php';
require_once __DIR__ . '/Erp2Ledger.php';
require_once __DIR__ . '/Erp2Audit.php';

class Erp2Sales
{
    /**
     * Create and post a sales invoice.
     *
     * @param array $data customer_id, warehouse_id, invoice_date, discount_amount,
     *                    tax_amount, paid_amount, payment_type, notes,
     *                    items[] => [product_id, quantity, unit_price,
     *                                discount_amount, tax_amount, unit_id, unit_factor]
     * @param array $opts allow_negative => permit selling below zero stock
     * @return array{id:int, invoice_number:string, grand_total:float, profit:float}
     */
    public static function create(array $data, array $opts = []): array
    {
        $db = Database::getInstance();

        $customerId  = (int)($data['customer_id'] ?? 0);
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $items       = $data['items'] ?? [];

        self::validateHeader($db, $customerId, $warehouseId, $items);

        $invoiceDate = self::validDate($data['invoice_date'] ?? null) ?: date('Y-m-d');
        $headDisc    = max(0, (float)($data['discount_amount'] ?? 0));
        $headTax     = max(0, (float)($data['tax_amount'] ?? 0));
        $paid        = max(0, (float)($data['paid_amount'] ?? 0));

        $db->beginTransaction();
        try {
            $number = Erp2Sequence::next('SALE', 0);

            // Header first with zero totals: the items need an invoice_id, and
            // the real totals are only known once every line has been costed.
            $invoiceId = (int)$db->insert('erp2_sales_invoices', [
                'invoice_number' => $number,
                'customer_id'    => $customerId,
                'warehouse_id'   => $warehouseId,
                'invoice_date'   => $invoiceDate,
                'subtotal'       => 0,
                'discount_amount'=> $headDisc,
                'tax_amount'     => $headTax,
                'grand_total'    => 0,
                'paid_amount'    => $paid,
                'total_cost'     => 0,
                'payment_type'   => self::paymentType($data['payment_type'] ?? null),
                'status'         => 'confirmed',
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $_SESSION['user_id'] ?? null,
            ]);

            $totals = self::postItems($db, $invoiceId, $number, $warehouseId, $items, $opts);

            $grand = max(0, $totals['subtotal'] - $headDisc + $headTax);
            // Cash handed over beyond the total is change, not payment.
            $paid  = min($paid, $grand);

            $db->update('erp2_sales_invoices', [
                'subtotal'    => round($totals['subtotal'], 2),
                'grand_total' => round($grand, 2),
                'paid_amount' => round($paid, 2),
                'total_cost'  => round($totals['cost'], 2),
            ], 'id = ?', [$invoiceId]);

            // Accounting: the sale puts the whole invoice on the customer's
            // account, and any cash taken now comes straight back off it. A
            // fully paid cash sale therefore nets to zero rather than being
            // skipped, so the statement still shows both sides.
            Erp2Ledger::customerEntry($customerId, 'SALE', $grand, 0, $invoiceId, $number, $invoiceDate);
            if ($paid > 0) {
                Erp2Ledger::customerEntry($customerId, 'PAYMENT', 0, min($paid, $grand),
                    $invoiceId, $number, $invoiceDate, 'پارەی وەسڵ');
            }

            $db->commit();

            Erp2Audit::log('create_sale', 'SALE', $invoiceId, $number, null, [
                'customer_id' => $customerId,
                'warehouse_id'=> $warehouseId,
                'grand_total' => $grand,
                'items'       => count($items),
            ]);

            return [
                'id'             => $invoiceId,
                'invoice_number' => $number,
                'grand_total'    => $grand,
                'profit'         => $totals['subtotal'] - $totals['cost'],
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Edit a posted invoice.
     *
     * Reverses every original line back into stock at the cost it left with,
     * removes the old lines, then posts the new ones. Net effect on stock is
     * the difference, which is what the brief's "10 becomes 7 must return 3"
     * example requires — and it stays correct even when lines are added,
     * removed or re-priced, which a naive delta on quantity alone would not.
     *
     * The invoice NUMBER never changes.
     */
    public static function update(int $invoiceId, array $data, array $opts = []): array
    {
        $db = Database::getInstance();

        $inv = $db->fetch("SELECT * FROM erp2_sales_invoices WHERE id = :id", ['id' => $invoiceId]);
        if (!$inv) {
            throw new RuntimeException('وەسڵ نەدۆزرایەوە');
        }
        if ($inv['status'] !== 'confirmed') {
            throw new RuntimeException('تەنها وەسڵی پەسەندکراو دەکرێت دەستکاری بکرێت');
        }

        $items = $data['items'] ?? [];
        if (!$items) {
            throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵا هەیە');
        }

        $warehouseId = (int)($data['warehouse_id'] ?? $inv['warehouse_id']);
        $customerId  = (int)($data['customer_id'] ?? $inv['customer_id']);
        $invoiceDate = self::validDate($data['invoice_date'] ?? null) ?: $inv['invoice_date'];
        $headDisc    = max(0, (float)($data['discount_amount'] ?? 0));
        $headTax     = max(0, (float)($data['tax_amount'] ?? 0));
        $paid        = max(0, (float)($data['paid_amount'] ?? 0));

        $db->beginTransaction();
        try {
            $oldItems = $db->fetchAll(
                "SELECT * FROM erp2_sales_items WHERE invoice_id = :id",
                ['id' => $invoiceId]
            );

            // 1. put the old quantities back, at their original cost — in BASE
            //    units: a line sold as a box of 10 took 10 pieces out
            foreach ($oldItems as $oi) {
                Erp2Stock::receive(
                    (int)$oi['product_id'],
                    (int)$inv['warehouse_id'],
                    (float)$oi['quantity'] * ((float)$oi['unit_factor'] ?: 1.0),
                    (float)$oi['unit_cost'],
                    'SALE_RETURN',
                    $invoiceId,
                    $inv['invoice_number'],
                    ['notes' => 'گەڕاندنەوە بۆ دەستکاری وەسڵ']
                );
            }
            $db->query("DELETE FROM erp2_sales_items WHERE invoice_id = :id", ['id' => $invoiceId]);

            // 2. post the new lines
            $totals = self::postItems($db, $invoiceId, $inv['invoice_number'], $warehouseId, $items, $opts);
            $grand  = max(0, $totals['subtotal'] - $headDisc + $headTax);
            // A bigger discount must not leave the invoice "overpaid": what was
            // paid beyond the new total goes back to the customer.
            $paid   = min($paid, $grand);

            $db->update('erp2_sales_invoices', [
                'customer_id'    => $customerId,
                'warehouse_id'   => $warehouseId,
                'invoice_date'   => $invoiceDate,
                'subtotal'       => round($totals['subtotal'], 2),
                'discount_amount'=> $headDisc,
                'tax_amount'     => $headTax,
                'grand_total'    => round($grand, 2),
                'paid_amount'    => round($paid, 2),
                'total_cost'     => round($totals['cost'], 2),
                'payment_type'   => self::paymentType($data['payment_type'] ?? $inv['payment_type']),
                'notes'          => $data['notes'] ?? $inv['notes'],
                'updated_at'     => date('Y-m-d H:i:s'),
                'updated_by'     => $_SESSION['user_id'] ?? null,
            ], 'id = ?', [$invoiceId]);

            // 3. restate the customer account: drop the old postings for this
            //    invoice and re-post, so the statement shows the corrected
            //    figure instead of two half-right rows. Matched on the invoice
            //    number too: a payment receipt posts doc_type PAYMENT with its
            //    OWN id, which can equal this invoice's id.
            $db->query(
                "DELETE FROM erp2_customer_ledger
                 WHERE doc_id = :id AND doc_number = :num AND doc_type IN ('SALE','PAYMENT')",
                ['id' => $invoiceId, 'num' => $inv['invoice_number']]
            );
            Erp2Ledger::customerEntry($customerId, 'SALE', $grand, 0, $invoiceId, $inv['invoice_number'], $invoiceDate);
            if ($paid > 0) {
                Erp2Ledger::customerEntry($customerId, 'PAYMENT', 0, min($paid, $grand),
                    $invoiceId, $inv['invoice_number'], $invoiceDate, 'پارەی وەسڵ');
            }

            $db->commit();

            Erp2Audit::log('edit_sale', 'SALE', $invoiceId, $inv['invoice_number'],
                ['grand_total' => $inv['grand_total'], 'items' => count($oldItems)],
                ['grand_total' => $grand, 'items' => count($items)]
            );

            return [
                'id'             => $invoiceId,
                'invoice_number' => $inv['invoice_number'],
                'grand_total'    => $grand,
                'profit'         => $totals['subtotal'] - $totals['cost'],
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Cancel a posted invoice: stock back, account reversed, status flipped.
     * The row and its number stay for the audit trail.
     */
    public static function cancel(int $invoiceId, ?string $reason = null): void
    {
        $db = Database::getInstance();

        $inv = $db->fetch("SELECT * FROM erp2_sales_invoices WHERE id = :id", ['id' => $invoiceId]);
        if (!$inv) {
            throw new RuntimeException('وەسڵ نەدۆزرایەوە');
        }
        if ($inv['status'] === 'cancelled') {
            throw new RuntimeException('ئەم وەسڵە پێشتر هەڵوەشێنراوەتەوە');
        }

        $db->beginTransaction();
        try {
            $items = $db->fetchAll("SELECT * FROM erp2_sales_items WHERE invoice_id = :id", ['id' => $invoiceId]);
            foreach ($items as $it) {
                Erp2Stock::receive(
                    (int)$it['product_id'],
                    (int)$inv['warehouse_id'],
                    (float)$it['quantity'] * ((float)$it['unit_factor'] ?: 1.0),   // base units, as postItems() took them
                    (float)$it['unit_cost'],
                    'SALE_RETURN',
                    $invoiceId,
                    $inv['invoice_number'],
                    ['notes' => 'هەڵوەشاندنەوەی وەسڵ']
                );
            }

            // Reversing entry rather than deleting the original rows: the
            // statement must still show that the sale happened and was undone.
            Erp2Ledger::customerEntry(
                (int)$inv['customer_id'], 'ADJUSTMENT', 0, (float)$inv['grand_total'],
                $invoiceId, $inv['invoice_number'], date('Y-m-d'),
                'هەڵوەشاندنەوەی وەسڵ' . ($reason ? ': ' . $reason : '')
            );
            if ((float)$inv['paid_amount'] > 0) {
                Erp2Ledger::customerEntry(
                    (int)$inv['customer_id'], 'ADJUSTMENT', min((float)$inv['paid_amount'], (float)$inv['grand_total']), 0,
                    $invoiceId, $inv['invoice_number'], date('Y-m-d'), 'گەڕاندنەوەی پارەی وەسڵی هەڵوەشاو'
                );
            }

            $db->update('erp2_sales_invoices', [
                'status'       => 'cancelled',
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancelled_by' => $_SESSION['user_id'] ?? null,
                'notes'        => trim(($inv['notes'] ?? '') . "\n" . 'هەڵوەشێنرایەوە: ' . (string)$reason),
            ], 'id = ?', [$invoiceId]);

            $db->commit();

            Erp2Audit::log('cancel_sale', 'SALE', $invoiceId, $inv['invoice_number'],
                ['status' => $inv['status']], ['status' => 'cancelled', 'reason' => $reason]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Sales return: goods come back from the customer.
     *
     * The original invoice is never modified — it is linked, and the returned
     * quantity is accumulated on its lines so the same goods cannot be
     * returned twice.
     *
     * COST. Stock is received back at the unit cost recorded on the ORIGINAL
     * sale line, not at today's weighted average. That is what makes the
     * profit reversal exact: the sale removed 10 units at 350 and booked the
     * cost, so the return must put them back at 350. Receiving at a newer
     * average would leave a phantom gain or loss on the books for goods that
     * only went out and came back.
     *
     * @param array $data customer_id, warehouse_id, original_invoice_id, return_date,
     *                    reason, notes,
     *                    items[] => [item_id (original line), product_id, quantity,
     *                                unit_price?, unit_cost?]
     * @return array{id:int, return_number:string, grand_total:float, total_cost:float}
     */
    public static function createReturn(array $data): array
    {
        $db = Database::getInstance();

        $customerId  = (int)($data['customer_id'] ?? 0);
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $originalId  = ((int)($data['original_invoice_id'] ?? 0)) ?: null;
        $items       = $data['items'] ?? [];

        if (!$items) {
            throw new RuntimeException('گەڕاندنەوە پێویستی بە لانیکەم یەک کاڵا هەیە');
        }
        if ($customerId <= 0 || !$db->fetch("SELECT id FROM erp2_customers WHERE id = :id", ['id' => $customerId])) {
            throw new RuntimeException('کڕیار نەدۆزرایەوە');
        }
        if ($warehouseId <= 0
            || !$db->fetch("SELECT id FROM erp2_warehouses WHERE id = :id AND is_active = 1", ['id' => $warehouseId])) {
            throw new RuntimeException('کۆگا نەدۆزرایەوە');
        }

        $returnDate = self::validDate($data['return_date'] ?? null) ?: date('Y-m-d');

        $db->beginTransaction();
        try {
            $number = Erp2Sequence::next('SALE_RETURN', 0);

            $returnId = (int)$db->insert('erp2_sales_returns', [
                'return_number'       => $number,
                'original_invoice_id' => $originalId,
                'customer_id'         => $customerId,
                'warehouse_id'        => $warehouseId,
                'return_date'         => $returnDate,
                'subtotal'            => 0,
                'grand_total'         => 0,
                'total_cost'          => 0,
                'reason'              => trim((string)($data['reason'] ?? '')) ?: null,
                'status'              => 'confirmed',
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $_SESSION['user_id'] ?? null,
            ]);

            $subtotal = 0.0;
            $costTotal = 0.0;

            foreach ($items as $raw) {
                $productId = (int)($raw['product_id'] ?? 0);
                $qty       = (float)($raw['quantity'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                $price = (float)($raw['unit_price'] ?? 0);
                $cost  = isset($raw['unit_cost']) ? (float)$raw['unit_cost'] : null;

                // Returning against a known invoice line: take the price and
                // cost from the line itself and cap the quantity at what is
                // still outstanding on it. Trusting the posted figures would
                // let a crafted request refund more than was ever charged.
                if (!empty($raw['item_id'])) {
                    $line = $db->fetch(
                        "SELECT it.quantity, it.returned_qty, it.unit_price, it.unit_cost, it.product_id
                         FROM erp2_sales_items it
                         WHERE it.id = :id" . ($originalId ? " AND it.invoice_id = :inv" : ""),
                        $originalId
                            ? ['id' => (int)$raw['item_id'], 'inv' => $originalId]
                            : ['id' => (int)$raw['item_id']]
                    );
                    if ($line) {
                        if ((int)$line['product_id'] !== $productId) {
                            throw new RuntimeException('کاڵا لەگەڵ ڕیزی وەسڵ یەک ناگرێتەوە');
                        }
                        $remaining = (float)$line['quantity'] - (float)$line['returned_qty'];
                        if ($qty > $remaining + 0.0005) {
                            throw new RuntimeException(sprintf(
                                'ناتوانرێت %s بگەڕێندرێتەوە — تەنها %s ماوە لەو ڕیزە',
                                rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                                rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.')
                            ));
                        }
                        $price = (float)$line['unit_price'];
                        $cost  = (float)$line['unit_cost'];
                        $db->query(
                            "UPDATE erp2_sales_items SET returned_qty = returned_qty + :q WHERE id = :id",
                            ['q' => $qty, 'id' => (int)$raw['item_id']]
                        );
                    }
                }

                // No original line (a goodwill return): fall back to the
                // warehouse's current average so the books still balance.
                if ($cost === null) {
                    $cost = (float)Erp2Stock::levelOf($productId, $warehouseId)['average_cost'];
                }

                Erp2Stock::receive(
                    $productId, $warehouseId, $qty, $cost,
                    'SALE_RETURN', $returnId, $number,
                    ['notes' => 'گەڕاندنەوەی فرۆشتن']
                );

                $lineTotal = $qty * $price;
                $lineCost  = $qty * $cost;

                $db->insert('erp2_sales_return_items', [
                    'return_id'  => $returnId,
                    'product_id' => $productId,
                    'quantity'   => round($qty, 3),
                    'unit_price' => round($price, 2),
                    'unit_cost'  => round($cost, 4),
                    'line_total' => round($lineTotal, 2),
                    'line_cost'  => round($lineCost, 2),
                ]);

                $subtotal  += $lineTotal;
                $costTotal += $lineCost;
            }

            if ($subtotal <= 0 && $costTotal <= 0) {
                throw new RuntimeException('گەڕاندنەوە پێویستی بە لانیکەم یەک کاڵای دروست هەیە');
            }

            $db->update('erp2_sales_returns', [
                'subtotal'    => round($subtotal, 2),
                'grand_total' => round($subtotal, 2),
                'total_cost'  => round($costTotal, 2),
            ], 'id = ?', [$returnId]);

            // Goods back means the customer owes less: CREDIT.
            Erp2Ledger::customerEntry($customerId, 'SALE_RETURN', 0, $subtotal,
                $returnId, $number, $returnDate);

            $db->commit();

            Erp2Audit::log('sale_return', 'SALE_RETURN', $returnId, $number, null, [
                'customer_id' => $customerId,
                'original'    => $originalId,
                'grand_total' => $subtotal,
                'total_cost'  => $costTotal,
            ]);

            return [
                'id'            => $returnId,
                'return_number' => $number,
                'grand_total'   => $subtotal,
                'total_cost'    => $costTotal,
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * An invoice's lines with how much of each is still returnable.
     * Drives the return screen so it can never offer more than remains.
     */
    public static function returnableLines(int $invoiceId): array
    {
        $db = Database::getInstance();
        $inv = $db->fetch(
            "SELECT si.id, si.invoice_number, si.invoice_date, si.customer_id, si.warehouse_id,
                    si.status, c.name AS customer_name, w.name AS warehouse_name
             FROM erp2_sales_invoices si
             JOIN erp2_customers c ON c.id = si.customer_id
             JOIN erp2_warehouses w ON w.id = si.warehouse_id
             WHERE si.id = :id",
            ['id' => $invoiceId]
        );
        if (!$inv) {
            return [];
        }
        $inv['items'] = $db->fetchAll(
            "SELECT it.id AS item_id, it.product_id, it.quantity, it.returned_qty,
                    (it.quantity - it.returned_qty) AS returnable,
                    it.unit_price, it.unit_cost, p.name AS product_name, p.product_code
             FROM erp2_sales_items it
             JOIN erp2_products p ON p.id = it.product_id
             WHERE it.invoice_id = :id
             ORDER BY it.id",
            ['id' => $invoiceId]
        );
        return $inv;
    }

    /** Invoice header + lines + customer, for printing or reloading into the grid. */
    public static function load(int $invoiceId): ?array
    {
        $db = Database::getInstance();
        $inv = $db->fetch(
            "SELECT si.*, c.name AS customer_name, c.phone AS customer_phone,
                    w.name AS warehouse_name, u.full_name AS created_by_name
             FROM erp2_sales_invoices si
             JOIN erp2_customers c ON c.id = si.customer_id
             JOIN erp2_warehouses w ON w.id = si.warehouse_id
             LEFT JOIN users u ON u.id = si.created_by
             WHERE si.id = :id",
            ['id' => $invoiceId]
        );
        if (!$inv) {
            return null;
        }
        $inv['items'] = $db->fetchAll(
            "SELECT it.*, p.name AS product_name, p.product_code, p.barcode, un.code AS unit_code,
                    pt.name AS type_name, pc.name AS category_name
             FROM erp2_sales_items it
             JOIN erp2_products p ON p.id = it.product_id
             LEFT JOIN erp2_product_types pt ON pt.id = p.type_id
             LEFT JOIN erp2_categories pc ON pc.id = p.category_id
             LEFT JOIN erp2_units un ON un.id = it.unit_id
             WHERE it.invoice_id = :id
             ORDER BY it.id",
            ['id' => $invoiceId]
        );
        return $inv;
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * Issue stock for each line and write the item rows.
     * @return array{subtotal: float, cost: float}
     */
    private static function postItems(
        Database $db, int $invoiceId, string $number, int $warehouseId, array $items, array $opts
    ): array {
        $subtotal = 0.0;
        $costTotal = 0.0;

        foreach ($items as $raw) {
            $productId = (int)($raw['product_id'] ?? 0);
            $factor    = (float)($raw['unit_factor'] ?? 1) ?: 1.0;
            $qty       = (float)($raw['quantity'] ?? 0);
            $price     = (float)($raw['unit_price'] ?? 0);
            $lineDisc  = max(0, (float)($raw['discount_amount'] ?? 0));
            $lineTax   = max(0, (float)($raw['tax_amount'] ?? 0));

            if ($productId <= 0 || $qty <= 0) {
                continue; // blank grid row
            }

            // The grid works in the chosen unit; stock is always kept in the
            // product's base unit, so a box of 10 removes 10 pieces.
            $baseQty = $qty * $factor;

            $moved = Erp2Stock::issue(
                $productId, $warehouseId, $baseQty, 'SALE', $invoiceId, $number,
                ['allow_negative' => !empty($opts['allow_negative'])]
            );

            $lineTotal = max(0, ($qty * $price) - $lineDisc + $lineTax);
            $lineCost  = $baseQty * $moved['unit_cost'];

            $db->insert('erp2_sales_items', [
                'invoice_id'      => $invoiceId,
                'product_id'      => $productId,
                'unit_id'         => ((int)($raw['unit_id'] ?? 0)) ?: null,
                'unit_factor'     => $factor,
                'quantity'        => round($qty, 3),
                'unit_price'      => round($price, 2),
                'unit_cost'       => round($moved['unit_cost'], 4),
                'discount_amount' => round($lineDisc, 2),
                'tax_amount'      => round($lineTax, 2),
                'line_total'      => round($lineTotal, 2),
                'line_cost'       => round($lineCost, 2),
                'line_profit'     => round($lineTotal - $lineCost, 2),
            ]);

            $subtotal  += $lineTotal;
            $costTotal += $lineCost;
        }

        if ($subtotal <= 0 && $costTotal <= 0) {
            throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵای دروست هەیە');
        }

        return ['subtotal' => $subtotal, 'cost' => $costTotal];
    }

    private static function validateHeader(Database $db, int $customerId, int $warehouseId, array $items): void
    {
        if (!$items) {
            throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵا هەیە');
        }
        if ($customerId <= 0 || !$db->fetch("SELECT id FROM erp2_customers WHERE id = :id", ['id' => $customerId])) {
            throw new RuntimeException('کڕیار نەدۆزرایەوە');
        }
        if ($warehouseId <= 0 || !$db->fetch("SELECT id FROM erp2_warehouses WHERE id = :id AND is_active = 1", ['id' => $warehouseId])) {
            throw new RuntimeException('کۆگا نەدۆزرایەوە');
        }
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
