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
     * Edit a posted purchase invoice (erp/purchases.php?edit=<id>).
     *
     * The new lines are received FIRST and the original lines then taken back
     * out at the cost they came in at, with the weighted average put back to
     * what it would be without them (as the pharmacy's Erp2Delete::purchase does). In that
     * order the only stock that must exist is what the edit itself removes:
     * 10 bought, 5 already sold, edited to 7 → 5 + 7 − 10 = 2 left, fine; edited
     * to 4 → refused, because one of the sold pieces would vanish.
     *
     * Refused when a purchase return was made against the invoice (its
     * returned quantities hang on the lines being replaced), and when later
     * supplier payments settled more than the edited total leaves open.
     * The warehouse and the invoice NUMBER never change.
     */
    public static function update(int $invoiceId, array $data, array $opts = []): array
    {
        $db = Database::getInstance();

        $inv = $db->fetch("SELECT * FROM erp_purchase_invoices WHERE id = :id", ['id' => $invoiceId]);
        if (!$inv) {
            throw new RuntimeException('وەسڵ نەدۆزرایەوە');
        }
        if ($inv['status'] !== 'confirmed') {
            throw new RuntimeException('تەنها وەسڵی پەسەندکراو دەکرێت دەستکاری بکرێت');
        }
        if ((int)$db->fetch("SELECT COUNT(*) c FROM erp_purchase_returns WHERE original_invoice_id = :id", ['id' => $invoiceId])['c']) {
            throw new RuntimeException('گەڕاندنەوەی لەسەر ئەم وەسڵە تۆمارکراوە — سەرەتا گەڕاندنەوەکە بسڕەوە');
        }

        $items = $data['items'] ?? [];
        if (!$items) {
            throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵا هەیە');
        }

        $warehouseId = (int)$inv['warehouse_id'];
        $number      = $inv['invoice_number'];
        $oldSupplier = (int)$inv['supplier_id'];
        $supplierId  = (int)($data['supplier_id'] ?? $oldSupplier);
        if ($supplierId <= 0 || !$db->fetch("SELECT id FROM erp_suppliers WHERE id = :id", ['id' => $supplierId])) {
            throw new RuntimeException('دابینکەر نەدۆزرایەوە');
        }
        $allocated = (float)($db->fetch(
            "SELECT COALESCE(SUM(amount), 0) s FROM erp_invoice_payments WHERE payment_kind = 'supplier' AND invoice_id = :id",
            ['id' => $invoiceId]
        )['s'] ?? 0);
        if ($allocated > 0.009 && $supplierId !== $oldSupplier) {
            throw new RuntimeException('پارەدانی دابینکەر بۆ ئەم وەسڵە دانراوە — دابینکەر ناگۆڕدرێت');
        }

        $invoiceDate = self::validDate($data['invoice_date'] ?? null) ?: $inv['invoice_date'];
        $headDisc    = max(0, (float)($data['discount_amount'] ?? 0));
        $headTax     = max(0, (float)($data['tax_amount'] ?? 0));
        $paid        = max(0, (float)($data['paid_amount'] ?? 0));
        $updatePrice = !array_key_exists('update_product_price', $opts) || $opts['update_product_price'];

        $db->beginTransaction();
        try {
            $oldItems = $db->fetchAll("SELECT * FROM erp_purchase_items WHERE invoice_id = :id", ['id' => $invoiceId]);
            $db->query("DELETE FROM erp_purchase_items WHERE invoice_id = :id", ['id' => $invoiceId]);

            // 1. the new lines come in
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
                $unitCost  = $baseQty > 0 ? ($lineTotal / $baseQty) : 0.0;
                $expiry    = self::validDate($raw['expiry_date'] ?? null);

                ErpStock::receive($productId, $warehouseId, $baseQty, $unitCost, 'PURCHASE', $invoiceId, $number, [
                    'batch_number' => $raw['batch_number'] ?? null,
                    'expiry_date'  => $expiry,
                    'notes'        => 'دەستکاری وەسڵی کڕین',
                ]);

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
                    'expiry_date'     => $expiry,
                ]);

                if ($updatePrice) {
                    $db->query("UPDATE erp_products SET purchase_price = :p WHERE id = :id",
                        ['p' => round($unitCost, 2), 'id' => $productId]);
                }
                $subtotal += $lineTotal;
            }
            if ($subtotal <= 0) {
                throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵای دروست هەیە');
            }

            // 2. the original lines go back out, at the cost they came in at
            $out = [];
            foreach ($oldItems as $oi) {
                $base = (float)$oi['quantity'] * ((float)$oi['unit_factor'] ?: 1.0);
                if ($base > 0) {
                    $out[] = [(int)$oi['product_id'], $base, (float)$oi['line_total'] / $base];
                }
            }
            $need = [];
            foreach ($out as [$pid, $base]) {
                $need[$pid] = ($need[$pid] ?? 0) + $base;
            }
            $short = [];
            foreach ($need as $pid => $q) {
                $have = ErpStock::levelOf($pid, $warehouseId)['quantity'];
                if ($have + ErpStock::QTY_EPSILON < $q) {
                    $name = $db->fetch("SELECT name FROM erp_products WHERE id = :id", ['id' => $pid])['name'] ?? ('#' . $pid);
                    $short[] = sprintf('«%s» %s کەم دەبێت', $name, self::qty($q - $have));
                }
            }
            if ($short) {
                throw new RuntimeException('ئەم کاڵایانە پێشتر فرۆشراون یان گوازراونەتەوە، بڕەکەیان ناتوانرێت ئەوەندە کەم بکرێتەوە: ' . implode('، ', $short));
            }
            foreach ($out as [$pid, $base, $cost]) {
                self::takeBack($pid, $warehouseId, $base, $cost, $invoiceId, $number);
            }

            $grand = max(0, $subtotal - $headDisc + $headTax);
            if ($allocated > 0.009 && $grand + 0.009 < min($paid, $grand) + $allocated) {
                throw new RuntimeException(sprintf(
                    'پارەدانی دواتری دابینکەر %s لەم وەسڵە دراوە — کۆی کۆتایی و پارەی دراو پێکەوە ناتوانن لەوە کەمتر بمێننەوە',
                    number_format($allocated, 0)
                ));
            }

            $db->update('erp_purchase_invoices', [
                'supplier_invoice_no' => trim((string)($data['supplier_invoice_no'] ?? '')) ?: null,
                'supplier_id'         => $supplierId,
                'invoice_date'        => $invoiceDate,
                'subtotal'            => round($subtotal, 2),
                'discount_amount'     => $headDisc,
                'tax_amount'          => $headTax,
                'grand_total'         => round($grand, 2),
                'paid_amount'         => $paid,
                'payment_type'        => self::paymentType($data['payment_type'] ?? $inv['payment_type']),
                'notes'               => $data['notes'] ?? $inv['notes'],
            ], 'id = ?', [$invoiceId]);

            // 3. restate the supplier account: the invoice's own rows go and are
            //    posted again (a supplier payment has its own doc number, so it stays)
            $db->query(
                "DELETE FROM erp_supplier_ledger WHERE supplier_id = :s AND doc_id = :id AND doc_number = :no AND doc_type IN ('PURCHASE','PAYMENT')",
                ['s' => $oldSupplier, 'id' => $invoiceId, 'no' => $number]
            );
            ErpLedger::supplierEntry($supplierId, 'PURCHASE', 0, $grand, $invoiceId, $number, $invoiceDate);
            if ($paid > 0) {
                ErpLedger::supplierEntry($supplierId, 'PAYMENT', min($paid, $grand), 0,
                    $invoiceId, $number, $invoiceDate, 'پارەی وەسڵ');
            }

            $db->commit();

            ErpAudit::log('edit_purchase', 'PURCHASE', $invoiceId, $number,
                ['grand_total' => $inv['grand_total'], 'supplier_id' => $oldSupplier, 'items' => $oldItems],
                ['grand_total' => $grand, 'supplier_id' => $supplierId, 'items' => count($items)]
            );

            return ['id' => $invoiceId, 'invoice_number' => $number, 'grand_total' => $grand];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Merge several posted purchase invoices of one supplier into one
     * (erp/purchase-invoices.php).
     *
     * Nothing is received or issued again: the goods are already in stock at
     * the cost they came in at. The lines (ids and returned quantities kept),
     * their stock movements, later payment allocations and purchase returns
     * all move onto the earliest invoice, which keeps its number and date.
     * The supplier account is restated as one purchase row and one paid-at-save
     * row carrying the same sums as the rows they replace, so the balance does
     * not move. The other invoices are then removed: their full before-image
     * goes to the audit log, and a summary of each into the merged notes.
     *
     * Refused unless every invoice is confirmed and they share one supplier
     * and one warehouse.
     */
    public static function merge(array $ids): array
    {
        $db  = Database::getInstance();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (count($ids) < 2) {
            throw new RuntimeException('لانیکەم دوو وەسڵ هەڵبژێرە بۆ یەکخستن');
        }

        $db->beginTransaction();
        try {
            $in   = implode(',', $ids);
            $invs = $db->fetchAll(
                "SELECT * FROM erp_purchase_invoices WHERE id IN ({$in}) ORDER BY invoice_date, id FOR UPDATE"
            );
            if (count($invs) !== count($ids)) {
                throw new RuntimeException('هەندێک لە وەسڵەکان نەدۆزرانەوە');
            }
            foreach ($invs as $v) {
                if ($v['status'] !== 'confirmed') {
                    throw new RuntimeException('تەنها وەسڵی پەسەندکراو یەکدەخرێت: ' . $v['invoice_number']);
                }
                if ((int)$v['supplier_id'] !== (int)$invs[0]['supplier_id']) {
                    throw new RuntimeException('وەسڵەکان دەبێت هی هەمان دابینکەر بن');
                }
                if ((int)$v['warehouse_id'] !== (int)$invs[0]['warehouse_id']) {
                    throw new RuntimeException('وەسڵەکان دەبێت هی هەمان کۆگا بن');
                }
            }

            $target     = $invs[0];
            $targetId   = (int)$target['id'];
            $number     = $target['invoice_number'];
            $supplierId = (int)$target['supplier_id'];

            $sum     = ['subtotal' => 0.0, 'discount_amount' => 0.0, 'tax_amount' => 0.0, 'grand_total' => 0.0, 'paid_amount' => 0.0];
            $posted  = ['PURCHASE' => [0.0, 0.0], 'PAYMENT' => [0.0, 0.0]];   // [debit, credit]
            $types   = [];
            $refs    = [];
            $before  = [];
            $merged  = [];
            $summary = [];
            $notes   = trim((string)$target['notes']) !== '' ? [trim((string)$target['notes'])] : [];

            foreach ($invs as $v) {
                $id = (int)$v['id'];
                $no = $v['invoice_number'];
                $ledger = $db->fetchAll(
                    "SELECT * FROM erp_supplier_ledger
                     WHERE supplier_id = :s AND doc_id = :id AND doc_number = :no AND doc_type IN ('PURCHASE','PAYMENT')",
                    ['s' => $supplierId, 'id' => $id, 'no' => $no]
                );
                $before[] = $v + [
                    'items'  => $db->fetchAll("SELECT * FROM erp_purchase_items WHERE invoice_id = :id ORDER BY id", ['id' => $id]),
                    'ledger' => $ledger,
                ];
                foreach ($ledger as $l) {
                    $posted[$l['doc_type']][0] += (float)$l['debit'];
                    $posted[$l['doc_type']][1] += (float)$l['credit'];
                }
                foreach ($sum as $k => $_) {
                    $sum[$k] += (float)$v[$k];
                }
                $types[$v['payment_type']] = true;
                if (($ref = trim((string)$v['supplier_invoice_no'])) !== '') {
                    $refs[$ref] = true;
                }

                // every invoice's own account rows go; one pair is posted below
                $db->query(
                    "DELETE FROM erp_supplier_ledger
                     WHERE supplier_id = :s AND doc_id = :id AND doc_number = :no AND doc_type IN ('PURCHASE','PAYMENT')",
                    ['s' => $supplierId, 'id' => $id, 'no' => $no]
                );
                if ($id === $targetId) {
                    continue;
                }

                $db->query("UPDATE erp_purchase_items SET invoice_id = :t WHERE invoice_id = :id",
                    ['t' => $targetId, 'id' => $id]);
                $db->query(
                    "UPDATE erp_stock_movements SET transaction_id = :t, document_number = :tno
                     WHERE transaction_id = :id AND document_number = :no AND transaction_type IN ('PURCHASE','PURCHASE_RETURN')",
                    ['t' => $targetId, 'tno' => $number, 'id' => $id, 'no' => $no]
                );
                $db->query("UPDATE erp_invoice_payments SET invoice_id = :t WHERE payment_kind = 'supplier' AND invoice_id = :id",
                    ['t' => $targetId, 'id' => $id]);
                $db->query("UPDATE erp_purchase_returns SET original_invoice_id = :t WHERE original_invoice_id = :id",
                    ['t' => $targetId, 'id' => $id]);
                // nothing hangs on it any more, so neither the item cascade nor the return SET NULL fires
                $db->query("DELETE FROM erp_purchase_invoices WHERE id = :id", ['id' => $id]);

                if (trim((string)$v['notes']) !== '') {
                    $notes[] = '[' . $no . '] ' . trim((string)$v['notes']);
                }
                $merged[]  = $no;
                $summary[] = sprintf('%s (%s، %s، %s)', $no, $v['supplier_invoice_no'] ?: '—',
                    $v['invoice_date'], number_format((float)$v['grand_total'], 0));
            }
            $notes[] = 'یەکخراوە لەگەڵ: ' . implode('، ', $summary);

            $grand   = round($sum['grand_total'], 2);
            $paid    = round($sum['paid_amount'], 2);
            $payType = count($types) === 1
                ? array_key_first($types)
                : ($paid <= 0.009 ? 'credit' : ($paid + 0.009 >= $grand ? 'cash' : 'partial'));
            $ref = implode(' / ', array_keys($refs));
            if (mb_strlen($ref) > 60) {
                $ref = mb_substr($ref, 0, 59) . '…';   // the full list is in the notes
            }

            $db->update('erp_purchase_invoices', [
                'supplier_invoice_no' => $ref !== '' ? $ref : null,
                'subtotal'            => round($sum['subtotal'], 2),
                'discount_amount'     => round($sum['discount_amount'], 2),
                'tax_amount'          => round($sum['tax_amount'], 2),
                'grand_total'         => $grand,
                'paid_amount'         => $paid,
                'payment_type'        => $payType,
                'notes'               => implode("\n", $notes),
                'updated_at'          => date('Y-m-d H:i:s'),
                'updated_by'          => $_SESSION['user_id'] ?? null,
            ], 'id = ?', [$targetId]);

            foreach ($posted as $type => [$debit, $credit]) {
                if ($debit > 0.009 || $credit > 0.009) {
                    ErpLedger::supplierEntry($supplierId, $type, $debit, $credit, $targetId, $number,
                        $target['invoice_date'], $type === 'PAYMENT' ? 'پارەی وەسڵ' : null);
                }
            }

            $db->commit();

            ErpAudit::log('merge_purchase', 'PURCHASE', $targetId, $number,
                ['invoices' => $before],
                ['merged' => $merged, 'grand_total' => $grand, 'paid_amount' => $paid]
            );

            return ['id' => $targetId, 'invoice_number' => $number, 'merged' => $merged, 'grand_total' => $grand];

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

    /**
     * Take goods that came in at $cost back out, and put the weighted average
     * back to what it was without them (the same as the pharmacy's Erp2Delete::takeOut).
     */
    private static function takeBack(int $pid, int $wh, float $qty, float $cost, int $docId, string $docNo): void
    {
        $lvl  = ErpStock::levelOf($pid, $wh);
        $left = $lvl['quantity'] - $qty;
        ErpStock::issue($pid, $wh, $qty, 'PURCHASE_RETURN', $docId, $docNo,
            ['notes' => 'دەستکاری وەسڵی کڕین', 'unit_cost' => $cost]);
        if ($left > ErpStock::QTY_EPSILON) {
            $avg = max(0, ($lvl['quantity'] * $lvl['average_cost'] - $qty * $cost) / $left);
            Database::getInstance()->query(
                "UPDATE erp_warehouse_product_stock SET average_cost = :a WHERE product_id = :p AND warehouse_id = :w",
                ['a' => round($avg, 4), 'p' => $pid, 'w' => $wh]
            );
        }
    }

    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
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
