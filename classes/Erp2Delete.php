<?php
/**
 * Erp2Delete — removing records for good, without leaving the books wrong.
 *
 * Cancelling (Erp2Sales::cancel, Erp2Payment::cancel) keeps the record and
 * posts opposing entries. Deleting is for what should never have been there —
 * a test, a mistake, a duplicate — so the record goes, and with it everything
 * it caused: stock comes back or goes back out, the customer's / supplier's
 * account rows go, the stock-movement trail of that document goes (out and
 * back again nets to nothing), returned quantities on the original invoice
 * are given back.
 *
 *   sale / purchase           invoices
 *   saleReturn / purchaseReturn
 *   payment                   customer receipts, supplier payments
 *   adjustment / transfer     stock documents
 *   prescription              a doctor's request (no stock, no money)
 *   party                     a customer/supplier with no documents
 *   partyWithHistory          a customer/supplier AND every document of theirs
 *
 * Whatever would leave stock below zero is refused with the reason — goods a
 * purchase brought that are already sold cannot be taken back. Something that
 * hangs on a document (a return made against an invoice, a payment settling
 * it) is refused too, so it is deleted first — partyWithHistory does exactly
 * that, in order. Each call is one transaction: a cascade that fails half-way
 * leaves nothing deleted. Every delete leaves an audit row with a snapshot.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Erp2Stock.php';
require_once __DIR__ . '/Erp2Audit.php';

class Erp2Delete
{
    const WALK_IN_REASON = 'کڕیاری نەقدە — کاشێر پێویستی پێیەتی';

    /* ── customers & suppliers ─────────────────────────────────────── */

    /** Each kind of document a party has, as readable lines (empty = none). */
    public static function partyBlockers(string $kind, int $id): array
    {
        $db = Database::getInstance();
        $out = [];
        $count = fn(string $sql) => (int)($db->fetch($sql, ['id' => $id])['c'] ?? 0);

        if ($kind === 'customer') {
            $walk = $db->fetch("SELECT is_walk_in FROM erp2_customers WHERE id = :id", ['id' => $id]);
            if ($walk && (int)$walk['is_walk_in'] === 1) {
                $out[] = self::WALK_IN_REASON;
            }
            if ($n = $count("SELECT COUNT(*) c FROM erp2_sales_invoices WHERE customer_id = :id")) { $out[] = $n . ' وەسڵی فرۆشتن'; }
            if ($n = $count("SELECT COUNT(*) c FROM erp2_sales_returns WHERE customer_id = :id"))  { $out[] = $n . ' گەڕاندنەوەی فرۆشتن'; }
            if ($n = $count("SELECT COUNT(*) c FROM erp2_customer_payments WHERE customer_id = :id")) { $out[] = $n . ' پارەدان'; }
            if ($n = $count("SELECT COUNT(*) c FROM erp2_prescriptions WHERE customer_id = :id")) { $out[] = $n . ' داواکاری دەرمان'; }
        } else {
            if ($n = $count("SELECT COUNT(*) c FROM erp2_purchase_invoices WHERE supplier_id = :id")) { $out[] = $n . ' وەسڵی کڕین'; }
            if ($n = $count("SELECT COUNT(*) c FROM erp2_purchase_returns WHERE supplier_id = :id"))  { $out[] = $n . ' گەڕاندنەوەی کڕین'; }
            if ($n = $count("SELECT COUNT(*) c FROM erp2_supplier_payments WHERE supplier_id = :id")) { $out[] = $n . ' پارەدان'; }
        }
        return $out;
    }

    public static function party(string $kind, int $id): void
    {
        [$table, $code, $ledger] = self::partyTables($kind);
        $db  = Database::getInstance();
        $row = $db->fetch("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        if (!$row) {
            throw new RuntimeException('نەدۆزرایەوە');
        }
        $blockers = self::partyBlockers($kind, $id);
        if ($blockers) {
            throw new RuntimeException('ناسڕدرێتەوە، چونکە: ' . implode('، ', $blockers));
        }
        self::tx(function () use ($db, $table, $ledger, $kind, $id, $row, $code) {
            // only opening-balance rows can be left; they go with it
            $db->query("DELETE FROM {$ledger} WHERE {$kind}_id = :id", ['id' => $id]);
            $db->query("DELETE FROM {$table} WHERE id = :id", ['id' => $id]);
            Erp2Audit::log('delete_' . $kind, strtoupper($kind), $id, $row[$code], $row, null);
        });
    }

    /**
     * A party together with every document of theirs, newest dependants
     * first (payments, then returns, then invoices), then the party. One
     * transaction: if any document cannot be reversed, nothing is deleted.
     */
    public static function partyWithHistory(string $kind, int $id): array
    {
        [$table] = self::partyTables($kind);
        $db = Database::getInstance();
        if (!$db->fetch("SELECT id FROM {$table} WHERE id = :id", ['id' => $id])) {
            throw new RuntimeException('نەدۆزرایەوە');
        }
        if ($kind === 'customer' && in_array(self::WALK_IN_REASON, self::partyBlockers($kind, $id), true)) {
            throw new RuntimeException(self::WALK_IN_REASON);
        }
        $ids = fn(string $sql) => array_map('intval', array_column($db->fetchAll($sql, ['id' => $id]), 'id'));

        return self::tx(function () use ($kind, $id, $ids, $db) {
            $done = ['payments' => 0, 'returns' => 0, 'invoices' => 0, 'prescriptions' => 0];
            if ($kind === 'customer') {
                foreach ($ids("SELECT id FROM erp2_customer_payments WHERE customer_id = :id") as $x) { self::payment('customer', $x); $done['payments']++; }
                foreach ($ids("SELECT id FROM erp2_sales_returns WHERE customer_id = :id ORDER BY id DESC") as $x) { self::saleReturn($x); $done['returns']++; }
                foreach ($ids("SELECT id FROM erp2_sales_invoices WHERE customer_id = :id ORDER BY id DESC") as $x) { self::sale($x); $done['invoices']++; }
                foreach ($ids("SELECT id FROM erp2_prescriptions WHERE customer_id = :id") as $x) { self::prescription($x); $done['prescriptions']++; }
            } else {
                foreach ($ids("SELECT id FROM erp2_supplier_payments WHERE supplier_id = :id") as $x) { self::payment('supplier', $x); $done['payments']++; }
                foreach ($ids("SELECT id FROM erp2_purchase_returns WHERE supplier_id = :id ORDER BY id DESC") as $x) { self::purchaseReturn($x); $done['returns']++; }
                foreach ($ids("SELECT id FROM erp2_purchase_invoices WHERE supplier_id = :id ORDER BY id DESC") as $x) { self::purchase($x); $done['invoices']++; }
            }
            self::party($kind, $id);
            return $done;
        });
    }

    public static function deactivateParty(string $kind, int $id): void
    {
        [$table, $code] = self::partyTables($kind);
        $db  = Database::getInstance();
        $row = $db->fetch("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        if (!$row) {
            throw new RuntimeException('نەدۆزرایەوە');
        }
        $db->update($table, ['is_active' => 0], 'id = ?', [$id]);
        Erp2Audit::log('deactivate_' . $kind, strtoupper($kind), $id, $row[$code], ['is_active' => $row['is_active']], ['is_active' => 0]);
    }

    /* ── sales invoice ─────────────────────────────────────────────── */

    public static function sale(int $id): array
    {
        $db  = Database::getInstance();
        $inv = $db->fetch("SELECT * FROM erp2_sales_invoices WHERE id = :id", ['id' => $id]);
        if (!$inv) {
            throw new RuntimeException('وەسڵ نەدۆزرایەوە');
        }
        $blk = [];
        if ($n = (int)$db->fetch("SELECT COUNT(*) c FROM erp2_sales_returns WHERE original_invoice_id = :id", ['id' => $id])['c']) {
            $blk[] = $n . ' گەڕاندنەوەی لەسەر تۆمارکراوە';
        }
        if ($n = (int)$db->fetch("SELECT COUNT(*) c FROM erp2_invoice_payments WHERE payment_kind = 'customer' AND invoice_id = :id", ['id' => $id])['c']) {
            $blk[] = $n . ' پارەدانی بۆ دانراوە';
        }
        if ($blk) {
            throw new RuntimeException('وەسڵی ' . $inv['invoice_number'] . ' — سەرەتا ئەمانە بسڕەوە: ' . implode('، ', $blk));
        }

        $items = $db->fetchAll("SELECT * FROM erp2_sales_items WHERE invoice_id = :id", ['id' => $id]);
        self::tx(function () use ($db, $inv, $items, $id) {
            // A cancelled invoice already put its goods back.
            if ($inv['status'] === 'confirmed') {
                foreach ($items as $it) {
                    $base = (float)$it['quantity'] * ((float)$it['unit_factor'] ?: 1.0);
                    if ($base > 0) {
                        Erp2Stock::receive((int)$it['product_id'], (int)$inv['warehouse_id'], $base, (float)$it['unit_cost'],
                            'SALE_RETURN', $id, $inv['invoice_number'], ['notes' => 'سڕینەوەی وەسڵ']);
                    }
                }
            }
            self::dropTrail($id, $inv['invoice_number'], ['SALE', 'SALE_RETURN']);
            $db->query("DELETE FROM erp2_customer_ledger WHERE customer_id = :c AND doc_id = :id AND doc_number = :no",
                ['c' => $inv['customer_id'], 'id' => $id, 'no' => $inv['invoice_number']]);
            // a prescription it filled is waiting at the counter again
            $db->query("UPDATE erp2_prescriptions SET status = 'pending', invoice_id = NULL, sold_at = NULL WHERE invoice_id = :id",
                ['id' => $id]);
            $db->query("DELETE FROM erp2_sales_invoices WHERE id = :id", ['id' => $id]);   // items cascade
            Erp2Audit::log('delete_sale', 'SALE', $id, $inv['invoice_number'], $inv + ['items' => $items], null);
        });
        return ['number' => $inv['invoice_number'], 'lines' => count($items)];
    }

    /* ── purchase invoice ──────────────────────────────────────────── */

    public static function purchase(int $id): array
    {
        $db  = Database::getInstance();
        $inv = $db->fetch("SELECT * FROM erp2_purchase_invoices WHERE id = :id", ['id' => $id]);
        if (!$inv) {
            throw new RuntimeException('وەسڵ نەدۆزرایەوە');
        }
        $blk = [];
        if ($n = (int)$db->fetch("SELECT COUNT(*) c FROM erp2_purchase_returns WHERE original_invoice_id = :id", ['id' => $id])['c']) {
            $blk[] = $n . ' گەڕاندنەوەی لەسەر تۆمارکراوە';
        }
        if ($n = (int)$db->fetch("SELECT COUNT(*) c FROM erp2_invoice_payments WHERE payment_kind = 'supplier' AND invoice_id = :id", ['id' => $id])['c']) {
            $blk[] = $n . ' پارەدانی بۆ دانراوە';
        }
        if ($blk) {
            throw new RuntimeException('وەسڵی ' . $inv['invoice_number'] . ' — سەرەتا ئەمانە بسڕەوە: ' . implode('، ', $blk));
        }

        $items = $db->fetchAll("SELECT * FROM erp2_purchase_items WHERE invoice_id = :id", ['id' => $id]);
        $wh = (int)$inv['warehouse_id'];
        self::tx(function () use ($db, $inv, $items, $id, $wh) {
            if ($inv['status'] === 'confirmed') {
                $lines = [];
                foreach ($items as $it) {
                    $base = (float)$it['quantity'] * ((float)$it['unit_factor'] ?: 1.0);
                    // the cost this delivery came in at: line total over base units (create())
                    $lines[] = [(int)$it['product_id'], $base, $base > 0 ? (float)$it['line_total'] / $base : 0.0];
                }
                self::assertOnHand($wh, $lines, 'وەسڵی ' . $inv['invoice_number']);
                foreach ($lines as [$pid, $base, $cost]) {
                    self::takeOut($pid, $wh, $base, $cost, 'PURCHASE_RETURN', $id, $inv['invoice_number'], 'سڕینەوەی وەسڵی کڕین');
                }
            }
            self::dropTrail($id, $inv['invoice_number'], ['PURCHASE', 'PURCHASE_RETURN']);
            $db->query("DELETE FROM erp2_supplier_ledger WHERE supplier_id = :s AND doc_id = :id AND doc_number = :no",
                ['s' => $inv['supplier_id'], 'id' => $id, 'no' => $inv['invoice_number']]);
            $db->query("DELETE FROM erp2_purchase_invoices WHERE id = :id", ['id' => $id]);   // items cascade
            Erp2Audit::log('delete_purchase', 'PURCHASE', $id, $inv['invoice_number'], $inv + ['items' => $items], null);
        });
        return ['number' => $inv['invoice_number'], 'lines' => count($items)];
    }

    /* ── returns ───────────────────────────────────────────────────── */

    /** Goods the customer brought back go out of stock again; the invoice may return them again. */
    public static function saleReturn(int $id): array
    {
        $db  = Database::getInstance();
        $ret = $db->fetch("SELECT * FROM erp2_sales_returns WHERE id = :id", ['id' => $id]);
        if (!$ret) {
            throw new RuntimeException('گەڕاندنەوە نەدۆزرایەوە');
        }
        $items = $db->fetchAll("SELECT * FROM erp2_sales_return_items WHERE return_id = :id", ['id' => $id]);
        $wh = (int)$ret['warehouse_id'];
        self::tx(function () use ($db, $ret, $items, $id, $wh) {
            if ($ret['status'] === 'confirmed') {
                $lines = array_map(fn($it) => [(int)$it['product_id'], (float)$it['quantity'], (float)$it['unit_cost']], $items);
                self::assertOnHand($wh, $lines, 'گەڕاندنەوەی ' . $ret['return_number']);
                foreach ($lines as [$pid, $qty, $cost]) {
                    self::takeOut($pid, $wh, $qty, $cost, 'SALE', $id, $ret['return_number'], 'سڕینەوەی گەڕاندنەوە');
                }
                if ($ret['original_invoice_id']) {
                    self::giveBackReturned('erp2_sales_items', (int)$ret['original_invoice_id'], $items);
                }
            }
            self::dropTrail($id, $ret['return_number'], ['SALE_RETURN', 'SALE']);
            $db->query("DELETE FROM erp2_customer_ledger WHERE customer_id = :c AND doc_id = :id AND doc_number = :no",
                ['c' => $ret['customer_id'], 'id' => $id, 'no' => $ret['return_number']]);
            $db->query("DELETE FROM erp2_sales_returns WHERE id = :id", ['id' => $id]);   // items cascade
            Erp2Audit::log('delete_sale_return', 'SALE_RETURN', $id, $ret['return_number'], $ret + ['items' => $items], null);
        });
        return ['number' => $ret['return_number'], 'lines' => count($items)];
    }

    /** Goods sent back to the supplier come back into stock. */
    public static function purchaseReturn(int $id): array
    {
        $db  = Database::getInstance();
        $ret = $db->fetch("SELECT * FROM erp2_purchase_returns WHERE id = :id", ['id' => $id]);
        if (!$ret) {
            throw new RuntimeException('گەڕاندنەوە نەدۆزرایەوە');
        }
        $items = $db->fetchAll("SELECT * FROM erp2_purchase_return_items WHERE return_id = :id", ['id' => $id]);
        self::tx(function () use ($db, $ret, $items, $id) {
            if ($ret['status'] === 'confirmed') {
                foreach ($items as $it) {
                    if ((float)$it['quantity'] > 0) {
                        Erp2Stock::receive((int)$it['product_id'], (int)$ret['warehouse_id'], (float)$it['quantity'], (float)$it['unit_cost'],
                            'PURCHASE', $id, $ret['return_number'], ['notes' => 'سڕینەوەی گەڕاندنەوەی کڕین']);
                    }
                }
                if ($ret['original_invoice_id']) {
                    self::giveBackReturned('erp2_purchase_items', (int)$ret['original_invoice_id'], $items);
                }
            }
            self::dropTrail($id, $ret['return_number'], ['PURCHASE_RETURN', 'PURCHASE']);
            $db->query("DELETE FROM erp2_supplier_ledger WHERE supplier_id = :s AND doc_id = :id AND doc_number = :no",
                ['s' => $ret['supplier_id'], 'id' => $id, 'no' => $ret['return_number']]);
            $db->query("DELETE FROM erp2_purchase_returns WHERE id = :id", ['id' => $id]);   // items cascade
            Erp2Audit::log('delete_purchase_return', 'PURCHASE_RETURN', $id, $ret['return_number'], $ret + ['items' => $items], null);
        });
        return ['number' => $ret['return_number'], 'lines' => count($items)];
    }

    /* ── payment ───────────────────────────────────────────────────── */

    public static function payment(string $kind, int $id): array
    {
        $isCustomer = $kind === 'customer';
        $table  = $isCustomer ? 'erp2_customer_payments' : 'erp2_supplier_payments';
        $ledger = $isCustomer ? 'erp2_customer_ledger' : 'erp2_supplier_ledger';
        $fk     = $isCustomer ? 'customer_id' : 'supplier_id';

        $db  = Database::getInstance();
        $pay = $db->fetch("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        if (!$pay) {
            throw new RuntimeException('پارەدان نەدۆزرایەوە');
        }
        self::tx(function () use ($db, $kind, $id, $pay, $table, $ledger, $fk) {
            $db->query("DELETE FROM erp2_invoice_payments WHERE payment_kind = :k AND payment_id = :id", ['k' => $kind, 'id' => $id]);
            // the payment row and, if it was cancelled, its reversing row
            $db->query("DELETE FROM {$ledger} WHERE {$fk} = :p AND doc_id = :id AND doc_number = :no",
                ['p' => $pay[$fk], 'id' => $id, 'no' => $pay['payment_number']]);
            $db->query("DELETE FROM {$table} WHERE id = :id", ['id' => $id]);
            Erp2Audit::log('delete_' . $kind . '_payment', strtoupper($kind) . '_PAYMENT', $id, $pay['payment_number'], $pay, null);
        });
        return ['number' => $pay['payment_number']];
    }

    /* ── stock documents ───────────────────────────────────────────── */

    /** Undo each counted difference: what was added goes out, what was removed comes back. */
    public static function adjustment(int $id): array
    {
        $db  = Database::getInstance();
        $adj = $db->fetch("SELECT * FROM erp2_stock_adjustments WHERE id = :id", ['id' => $id]);
        if (!$adj) {
            throw new RuntimeException('ڕێکخستن نەدۆزرایەوە');
        }
        $items = $db->fetchAll("SELECT * FROM erp2_stock_adjustment_items WHERE adjustment_id = :id", ['id' => $id]);
        $wh = (int)$adj['warehouse_id'];
        self::tx(function () use ($db, $adj, $items, $id, $wh) {
            if ($adj['status'] === 'confirmed') {
                $added = [];
                foreach ($items as $it) {
                    if ((float)$it['difference'] > 0) {
                        $added[] = [(int)$it['product_id'], (float)$it['difference'], (float)$it['unit_cost']];
                    }
                }
                self::assertOnHand($wh, $added, 'ڕێکخستنی ' . $adj['adjustment_number']);
                foreach ($items as $it) {
                    $diff = (float)$it['difference'];
                    if ($diff > 0) {
                        self::takeOut((int)$it['product_id'], $wh, $diff, (float)$it['unit_cost'], 'ADJUSTMENT', $id, $adj['adjustment_number'], 'سڕینەوەی ڕێکخستن');
                    } elseif ($diff < 0) {
                        Erp2Stock::receive((int)$it['product_id'], $wh, abs($diff), (float)$it['unit_cost'], 'ADJUSTMENT', $id, $adj['adjustment_number'], ['notes' => 'سڕینەوەی ڕێکخستن']);
                    }
                }
            }
            self::dropTrail($id, $adj['adjustment_number'], ['ADJUSTMENT', 'OPENING', 'DAMAGE']);
            $db->query("DELETE FROM erp2_stock_adjustments WHERE id = :id", ['id' => $id]);   // items cascade
            Erp2Audit::log('delete_adjustment', 'ADJUSTMENT', $id, $adj['adjustment_number'], $adj + ['items' => $items], null);
        });
        return ['number' => $adj['adjustment_number'], 'lines' => count($items)];
    }

    /** The goods go back from where they were sent to, where they came from. */
    public static function transfer(int $id): array
    {
        $db = Database::getInstance();
        $tr = $db->fetch("SELECT * FROM erp2_stock_transfers WHERE id = :id", ['id' => $id]);
        if (!$tr) {
            throw new RuntimeException('گواستنەوە نەدۆزرایەوە');
        }
        $items = $db->fetchAll("SELECT * FROM erp2_stock_transfer_items WHERE transfer_id = :id", ['id' => $id]);
        $from = (int)$tr['from_warehouse_id'];
        $to   = (int)$tr['to_warehouse_id'];
        self::tx(function () use ($db, $tr, $items, $id, $from, $to) {
            if ($tr['status'] === 'confirmed') {
                $lines = array_map(fn($it) => [(int)$it['product_id'], (float)$it['quantity'], (float)$it['unit_cost']], $items);
                self::assertOnHand($to, $lines, 'گواستنەوەی ' . $tr['transfer_number']);
                foreach ($lines as [$pid, $qty, $cost]) {
                    self::takeOut($pid, $to, $qty, $cost, 'TRANSFER_OUT', $id, $tr['transfer_number'], 'سڕینەوەی گواستنەوە');
                    Erp2Stock::receive($pid, $from, $qty, $cost, 'TRANSFER_IN', $id, $tr['transfer_number'], ['notes' => 'سڕینەوەی گواستنەوە']);
                }
            }
            self::dropTrail($id, $tr['transfer_number'], ['TRANSFER_IN', 'TRANSFER_OUT']);
            $db->query("DELETE FROM erp2_stock_transfers WHERE id = :id", ['id' => $id]);   // items cascade
            Erp2Audit::log('delete_transfer', 'TRANSFER', $id, $tr['transfer_number'], $tr + ['items' => $items], null);
        });
        return ['number' => $tr['transfer_number'], 'lines' => count($items)];
    }

    /* ── prescription ──────────────────────────────────────────────── */

    /** A doctor's request: no stock, no money — an invoice it led to stays. */
    public static function prescription(int $id): array
    {
        $db = Database::getInstance();
        $pr = $db->fetch("SELECT * FROM erp2_prescriptions WHERE id = :id", ['id' => $id]);
        if (!$pr) {
            throw new RuntimeException('داواکاری دەرمان نەدۆزرایەوە');
        }
        $items = $db->fetchAll("SELECT * FROM erp2_prescription_items WHERE prescription_id = :id", ['id' => $id]);
        self::tx(function () use ($db, $pr, $items, $id) {
            $db->query("DELETE FROM erp2_prescription_items WHERE prescription_id = :id", ['id' => $id]);
            $db->query("DELETE FROM erp2_prescriptions WHERE id = :id", ['id' => $id]);
            Erp2Audit::log('delete_prescription', 'PRESCRIPTION', $id, (string)$id, $pr + ['items' => $items], null);
        });
        return ['number' => '#' . $id, 'lines' => count($items)];
    }

    /* ── internals ─────────────────────────────────────────────────── */

    /** Run inside the caller's transaction, or open one — so a cascade is all-or-nothing. */
    private static function tx(callable $fn)
    {
        $db = Database::getInstance();
        if ($db->inTransaction()) {
            return $fn();
        }
        $db->beginTransaction();
        try {
            $r = $fn();
            $db->commit();
            return $r;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /** Refuse, with names and numbers, when a warehouse no longer holds what must go back out. */
    private static function assertOnHand(int $wh, array $lines, string $what): void
    {
        $need = [];
        foreach ($lines as [$pid, $qty]) {
            $need[$pid] = ($need[$pid] ?? 0) + $qty;
        }
        $short = [];
        foreach ($need as $pid => $qty) {
            $have = Erp2Stock::levelOf($pid, $wh)['quantity'];
            if ($have + Erp2Stock::QTY_EPSILON < $qty) {
                $name = Database::getInstance()->fetch("SELECT name FROM erp2_products WHERE id = :id", ['id' => $pid])['name'] ?? ('#' . $pid);
                $short[] = sprintf('«%s» پێویست %s، ماوە %s', $name, self::qty($qty), self::qty($have));
            }
        }
        if ($short) {
            throw new RuntimeException($what . ' ناسڕدرێتەوە — ئەم کاڵایانە فرۆشراون یان گوازراونەتەوە: ' . implode('، ', $short));
        }
    }

    /**
     * Take goods that came in at $cost back out, and put the weighted average
     * back to what it was without them (not left at the blended figure).
     */
    private static function takeOut(int $pid, int $wh, float $qty, float $cost, string $type, int $docId, string $docNo, string $note): void
    {
        if ($qty <= 0) {
            return;
        }
        $lvl  = Erp2Stock::levelOf($pid, $wh);
        $left = $lvl['quantity'] - $qty;
        Erp2Stock::issue($pid, $wh, $qty, $type, $docId, $docNo, ['notes' => $note, 'unit_cost' => $cost]);
        if ($left > Erp2Stock::QTY_EPSILON) {
            $avg = max(0, ($lvl['quantity'] * $lvl['average_cost'] - $qty * $cost) / $left);
            Database::getInstance()->query(
                "UPDATE erp2_warehouse_product_stock SET average_cost = :a WHERE product_id = :p AND warehouse_id = :w",
                ['a' => round($avg, 4), 'p' => $pid, 'w' => $wh]
            );
        }
    }

    /** A document's own stock movements: out and back again nets to nothing. */
    private static function dropTrail(int $docId, string $docNo, array $types): void
    {
        $in = implode(',', array_map(fn($t) => "'" . preg_replace('/[^A-Z_]/', '', $t) . "'", $types));
        Database::getInstance()->query(
            "DELETE FROM erp2_stock_movements WHERE transaction_id = :id AND document_number = :no AND transaction_type IN ({$in})",
            ['id' => $docId, 'no' => $docNo]
        );
    }

    /** A deleted return gives its quantities back to the original invoice's lines, so they can be returned again. */
    private static function giveBackReturned(string $itemsTable, int $invoiceId, array $returnItems): void
    {
        $db = Database::getInstance();
        foreach ($returnItems as $ri) {
            $left = (float)$ri['quantity'];
            foreach ($db->fetchAll(
                "SELECT id, returned_qty FROM {$itemsTable} WHERE invoice_id = :i AND product_id = :p AND returned_qty > 0 ORDER BY id",
                ['i' => $invoiceId, 'p' => (int)$ri['product_id']]
            ) as $line) {
                if ($left <= Erp2Stock::QTY_EPSILON) { break; }
                $take = min($left, (float)$line['returned_qty']);
                $db->query("UPDATE {$itemsTable} SET returned_qty = returned_qty - :q WHERE id = :id", ['q' => $take, 'id' => (int)$line['id']]);
                $left -= $take;
            }
        }
    }

    private static function partyTables(string $kind): array
    {
        if ($kind === 'customer') {
            return ['erp2_customers', 'customer_code', 'erp2_customer_ledger'];
        }
        if ($kind === 'supplier') {
            return ['erp2_suppliers', 'supplier_code', 'erp2_supplier_ledger'];
        }
        throw new InvalidArgumentException('bad kind');
    }

    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
