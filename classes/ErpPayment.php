<?php
/**
 * ErpPayment — money in from customers, money out to suppliers.
 *
 * This is the step that closes the loop in the module brief:
 *   Product -> Warehouse -> Purchase -> Stock -> Sale -> Customer -> PAYMENT -> Profit
 *
 * A payment does three things in one transaction: it records the receipt, it
 * posts the ledger entry that moves the party's balance, and it allocates the
 * money against specific invoices. All three commit together — a receipt whose
 * ledger entry failed would show the customer as still owing money they had
 * just handed over.
 *
 * Ledger direction (fixed in ErpLedger, restated here because it is the whole
 * point of this class):
 *   customer payment -> CREDIT  (they owe us less)
 *   supplier payment -> DEBIT   (we owe them less)
 *
 * ALLOCATION. A payment is matched to invoices oldest-first unless the caller
 * names them. Oldest-first is the convention every counter already follows
 * verbally ("this clears last month's"), and leaving money unallocated makes
 * the "which invoices are still open" question unanswerable later. Any excess
 * beyond the open invoices stays on the account as a credit rather than being
 * refused — customers do round up, and refusing the payment would be worse
 * than carrying the balance.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ErpSequence.php';
require_once __DIR__ . '/ErpLedger.php';
require_once __DIR__ . '/ErpAudit.php';

class ErpPayment
{
    const METHODS = ['cash', 'bank', 'card', 'other'];

    /**
     * @param string $kind 'customer' | 'supplier'
     * @param array  $data party_id, amount, payment_date, payment_method,
     *                     reference, notes, invoice_ids[] (optional)
     * @return array{id:int, payment_number:string, allocated:float, unallocated:float, balance:float}
     */
    public static function create(string $kind, array $data): array
    {
        if (!in_array($kind, ['customer', 'supplier'], true)) {
            throw new InvalidArgumentException('bad payment kind');
        }
        $isCustomer = $kind === 'customer';

        $db      = Database::getInstance();
        $partyId = (int)($data['party_id'] ?? 0);
        $amount  = round((float)($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new RuntimeException('بڕی پارە دەبێت لە سفر زیاتر بێت');
        }

        $table = $isCustomer ? 'erp_customers' : 'erp_suppliers';
        $party = $db->fetch("SELECT id, name FROM {$table} WHERE id = :id", ['id' => $partyId]);
        if (!$party) {
            throw new RuntimeException($isCustomer ? 'کڕیار نەدۆزرایەوە' : 'دابینکەر نەدۆزرایەوە');
        }

        $method = in_array($data['payment_method'] ?? '', self::METHODS, true)
            ? $data['payment_method'] : 'cash';
        $date = self::validDate($data['payment_date'] ?? null) ?: date('Y-m-d');

        $db->beginTransaction();
        try {
            $number = ErpSequence::next($isCustomer ? 'CUSTOMER_PAYMENT' : 'SUPPLIER_PAYMENT', 0);

            $paymentId = (int)$db->insert(
                $isCustomer ? 'erp_customer_payments' : 'erp_supplier_payments',
                [
                    'payment_number' => $number,
                    ($isCustomer ? 'customer_id' : 'supplier_id') => $partyId,
                    'payment_date'   => $date,
                    'amount'         => $amount,
                    'payment_method' => $method,
                    'reference'      => trim((string)($data['reference'] ?? '')) ?: null,
                    'notes'          => trim((string)($data['notes'] ?? '')) ?: null,
                    'status'         => 'confirmed',
                    'created_by'     => $_SESSION['user_id'] ?? null,
                ]
            );

            $allocated = self::allocate($db, $kind, $paymentId, $partyId, $amount, $data['invoice_ids'] ?? null);

            // The ledger entry is the FULL amount, not just the allocated part:
            // the party handed over that money regardless of which invoices it
            // happened to cover, and the balance must reflect it.
            if ($isCustomer) {
                ErpLedger::customerEntry($partyId, 'PAYMENT', 0, $amount, $paymentId, $number, $date,
                    self::methodLabel($method));
            } else {
                ErpLedger::supplierEntry($partyId, 'PAYMENT', $amount, 0, $paymentId, $number, $date,
                    self::methodLabel($method));
            }

            $db->commit();

            ErpAudit::log(
                $isCustomer ? 'customer_payment' : 'supplier_payment',
                strtoupper($kind) . '_PAYMENT', $paymentId, $number, null,
                ['party_id' => $partyId, 'amount' => $amount, 'method' => $method, 'allocated' => $allocated]
            );

            $balance = $isCustomer
                ? ErpLedger::customerBalance($partyId)
                : ErpLedger::supplierBalance($partyId);

            return [
                'id'             => $paymentId,
                'payment_number' => $number,
                'allocated'      => $allocated,
                'unallocated'    => round($amount - $allocated, 2),
                'balance'        => $balance,
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Cancel a payment: reverse the ledger, drop the allocations, mark it
     * cancelled. The row and its number survive, as with every other document.
     */
    public static function cancel(string $kind, int $paymentId, ?string $reason = null): void
    {
        $isCustomer = $kind === 'customer';
        $table = $isCustomer ? 'erp_customer_payments' : 'erp_supplier_payments';
        $fk    = $isCustomer ? 'customer_id' : 'supplier_id';

        $db  = Database::getInstance();
        $pay = $db->fetch("SELECT * FROM {$table} WHERE id = :id", ['id' => $paymentId]);
        if (!$pay) {
            throw new RuntimeException('پارەدان نەدۆزرایەوە');
        }
        if ($pay['status'] === 'cancelled') {
            throw new RuntimeException('ئەم پارەدانە پێشتر هەڵوەشێنراوەتەوە');
        }

        $db->beginTransaction();
        try {
            $db->query(
                "DELETE FROM erp_invoice_payments WHERE payment_kind = :k AND payment_id = :id",
                ['k' => $kind, 'id' => $paymentId]
            );

            // Opposing entry rather than deleting the original: the statement
            // must still show that money arrived and was then reversed.
            $amount = (float)$pay['amount'];
            $note   = 'هەڵوەشاندنەوەی پارەدان' . ($reason ? ': ' . $reason : '');
            if ($isCustomer) {
                ErpLedger::customerEntry((int)$pay[$fk], 'ADJUSTMENT', $amount, 0,
                    $paymentId, $pay['payment_number'], date('Y-m-d'), $note);
            } else {
                ErpLedger::supplierEntry((int)$pay[$fk], 'ADJUSTMENT', 0, $amount,
                    $paymentId, $pay['payment_number'], date('Y-m-d'), $note);
            }

            $db->update($table, ['status' => 'cancelled'], 'id = ?', [$paymentId]);
            $db->commit();

            ErpAudit::log('cancel_' . $kind . '_payment', strtoupper($kind) . '_PAYMENT',
                $paymentId, $pay['payment_number'], ['status' => $pay['status']],
                ['status' => 'cancelled', 'reason' => $reason]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Invoices with money still outstanding, oldest first.
     * Used by the payment screen to show what the money will settle.
     */
    public static function openInvoices(string $kind, int $partyId, int $limit = 50): array
    {
        $db = Database::getInstance();
        $isCustomer = $kind === 'customer';
        $table = $isCustomer ? 'erp_sales_invoices' : 'erp_purchase_invoices';
        $fk    = $isCustomer ? 'customer_id' : 'supplier_id';
        $limit = max(1, min(200, $limit));

        // outstanding = grand_total - paid_at_save - later allocations
        return $db->fetchAll(
            "SELECT i.id, i.invoice_number, i.invoice_date, i.grand_total, i.paid_amount,
                    COALESCE(a.alloc, 0) AS allocated,
                    (i.grand_total - i.paid_amount - COALESCE(a.alloc, 0)) AS outstanding
             FROM {$table} i
             LEFT JOIN (
                 SELECT invoice_id, SUM(amount) AS alloc
                 FROM erp_invoice_payments
                 WHERE payment_kind = :k
                 GROUP BY invoice_id
             ) a ON a.invoice_id = i.id
             WHERE i.{$fk} = :pid
               AND i.status = 'confirmed'
               AND (i.grand_total - i.paid_amount - COALESCE(a.alloc, 0)) > 0.009
             ORDER BY i.invoice_date, i.id
             LIMIT {$limit}",
            ['k' => $kind, 'pid' => $partyId]
        );
    }

    public static function methodLabel(string $m): string
    {
        return ['cash' => 'نەقد', 'bank' => 'بانک', 'card' => 'کارت', 'other' => 'هیتر'][$m] ?? $m;
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * Spread a payment across invoices. Returns the amount actually allocated,
     * which may be less than the payment when the party overpays.
     */
    private static function allocate(
        Database $db, string $kind, int $paymentId, int $partyId, float $amount, $invoiceIds
    ): float {
        $open = self::openInvoices($kind, $partyId, 200);

        // An explicit selection narrows the list but keeps the same order and
        // the same outstanding figures, so a stale screen can never allocate
        // more to an invoice than is actually left on it.
        if (is_array($invoiceIds) && $invoiceIds) {
            $wanted = array_flip(array_map('intval', $invoiceIds));
            $open = array_values(array_filter($open, fn($i) => isset($wanted[(int)$i['id']])));
        }

        $remaining = $amount;
        $allocated = 0.0;

        foreach ($open as $inv) {
            if ($remaining <= 0.009) {
                break;
            }
            $take = min($remaining, (float)$inv['outstanding']);
            if ($take <= 0.009) {
                continue;
            }
            $db->insert('erp_invoice_payments', [
                'payment_kind' => $kind,
                'payment_id'   => $paymentId,
                'invoice_id'   => (int)$inv['id'],
                'amount'       => round($take, 2),
            ]);
            $remaining -= $take;
            $allocated += $take;
        }

        return round($allocated, 2);
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
