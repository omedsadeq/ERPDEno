<?php
/**
 * ErpSequence — human document numbers for the ERP.
 *
 *   SAL-2026-000001   sales invoice
 *   PUR-2026-000001   purchase invoice
 *   SRET / PRET       sales / purchase returns
 *   CP / SP           customer / supplier payment
 *   TRF / ADJ         stock transfer / adjustment
 *
 * The auto-increment id is never shown to the user: ids are shared across
 * every till and leak how many documents exist, and a gap in them looks like
 * a deleted invoice to an auditor.
 *
 * Concurrency: next() MUST run inside the caller's transaction. It takes a row
 * lock (SELECT ... FOR UPDATE) on the sequence row, so two tills saving at the
 * same instant queue rather than both reading the same next_number. The lock
 * is released when the caller commits, which is also the moment the document
 * that consumed the number becomes visible.
 *
 * Consequence worth knowing: because the number is consumed inside the
 * transaction, a rolled-back save leaves a gap in the sequence. That is the
 * correct trade — reusing a number would let two different documents share one
 * invoice number, which is far worse than a gap.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class ErpSequence
{
    /**
     * Where each document type's numbers actually live.
     *
     * Used to SEED a missing sequence row from the highest number already
     * issued, rather than starting at 1. Without this, anything that loses the
     * counter — a restore from a backup taken before the documents, a manual
     * cleanup, a fresh copy of the schema onto existing data — makes the next
     * document collide with one that exists, and the save fails with a
     * duplicate-key error the operator cannot act on.
     *
     * [table, column] per type; types with no document table are absent and
     * simply start at 1.
     */
    const SOURCES = [
        'SALE'             => ['erp_sales_invoices',    'invoice_number'],
        'PURCHASE'         => ['erp_purchase_invoices', 'invoice_number'],
        'SALE_RETURN'      => ['erp_sales_returns',     'return_number'],
        'PURCHASE_RETURN'  => ['erp_purchase_returns',  'return_number'],
        'CUSTOMER_PAYMENT' => ['erp_customer_payments', 'payment_number'],
        'SUPPLIER_PAYMENT' => ['erp_supplier_payments', 'payment_number'],
        'TRANSFER'         => ['erp_stock_transfers',   'transfer_number'],
        'ADJUSTMENT'       => ['erp_stock_adjustments', 'adjustment_number'],
        'DOCTOR_ISSUE'     => ['erp_doctor_issues',     'issue_number'],
        'CUSTOMER'         => ['erp_customers',         'customer_code'],
        'SUPPLIER'         => ['erp_suppliers',         'supplier_code'],
        'PRODUCT'          => ['erp_products',          'product_code'],
    ];

    /** doc_type => default prefix. */
    const PREFIXES = [
        'SALE'            => 'SAL',
        'PURCHASE'        => 'PUR',
        'SALE_RETURN'     => 'SRET',
        'PURCHASE_RETURN' => 'PRET',
        'CUSTOMER_PAYMENT'=> 'CP',
        'SUPPLIER_PAYMENT'=> 'SP',
        'TRANSFER'        => 'TRF',
        'ADJUSTMENT'      => 'ADJ',
        'DOCTOR_ISSUE'    => 'DRI',   // مەواد بۆ دکتۆر
        'CUSTOMER'        => 'C',
        'SUPPLIER'        => 'S',
        'PRODUCT'         => 'P',
    ];

    /**
     * Reserve and return the next number for a document type.
     *
     * @param string   $docType     one of self::PREFIXES
     * @param int      $warehouseId 0 = one shared sequence for all warehouses
     * @param int|null $year        defaults to the current year
     * @throws InvalidArgumentException on an unknown document type
     * @throws RuntimeException if called outside a transaction
     */
    public static function next(string $docType, int $warehouseId = 0, ?int $year = null): string
    {
        if (!isset(self::PREFIXES[$docType])) {
            throw new InvalidArgumentException("Unknown ERP document type: {$docType}");
        }

        $db = Database::getInstance();
        if (!$db->inTransaction()) {
            // Without a transaction the FOR UPDATE lock would be released
            // immediately and two concurrent callers could take one number.
            throw new RuntimeException(
                "ErpSequence::next('{$docType}') must be called inside a transaction"
            );
        }

        $year        = $year ?: (int)date('Y');
        $prefix      = self::PREFIXES[$docType];
        $warehouseId = max(0, $warehouseId);

        $pdo = $db->getConnection();

        // Create the row if this is the first document of the year. INSERT
        // IGNORE rather than a SELECT-then-INSERT: two tills hitting a brand
        // new year at the same moment would both see "no row" and one INSERT
        // would fail on the unique key.
        //
        // It starts AFTER the highest number already issued, not at 1, so a
        // lost or restored counter cannot hand out a number that is already
        // on a document.
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO erp_number_sequences
                 (doc_type, year, warehouse_id, prefix, padding, next_number)
             VALUES (?, ?, ?, ?, 6, ?)"
        );
        $stmt->execute([$docType, $year, $warehouseId, $prefix, self::seedFrom($docType, $prefix, $year)]);

        // Lock the row, read the number, bump it.
        $stmt = $pdo->prepare(
            "SELECT id, prefix, padding, next_number
             FROM erp_number_sequences
             WHERE doc_type = ? AND year = ? AND warehouse_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$docType, $year, $warehouseId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Could not obtain sequence row for {$docType}");
        }

        $number  = (int)$row['next_number'];
        $padding = max(1, (int)$row['padding']);

        $upd = $pdo->prepare(
            "UPDATE erp_number_sequences
             SET next_number = next_number + 1, updated_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([$row['id']]);

        return sprintf('%s-%d-%s', $row['prefix'], $year, str_pad((string)$number, $padding, '0', STR_PAD_LEFT));
    }

    /**
     * The number a fresh sequence row should start at: one past the highest
     * already issued for this prefix and year, or 1 when there are none.
     *
     * Reads the document table itself rather than trusting the counter,
     * because the whole point is to recover when the counter is the thing that
     * went missing. Matching on the 'PFX-YYYY-' prefix keeps years separate
     * and ignores any hand-typed code that does not follow the pattern.
     */
    private static function seedFrom(string $docType, string $prefix, int $year): int
    {
        if (!isset(self::SOURCES[$docType])) {
            return 1;
        }
        [$table, $column] = self::SOURCES[$docType];
        $like = $prefix . '-' . $year . '-%';

        try {
            // SUBSTRING past "PFX-YYYY-" leaves the padded counter; CAST drops
            // the leading zeros so MAX compares numerically, not as text
            // (where '9' would beat '10').
            $offset = strlen($prefix) + strlen((string)$year) + 3;
            $row = Database::getInstance()->fetch(
                "SELECT MAX(CAST(SUBSTRING({$column}, {$offset}) AS UNSIGNED)) AS mx
                 FROM {$table}
                 WHERE {$column} LIKE :lk",
                ['lk' => $like]
            );
            $max = (int)($row['mx'] ?? 0);
            return $max > 0 ? $max + 1 : 1;
        } catch (Throwable $e) {
            // A missing table must not stop a document being saved; the
            // duplicate key would still catch a genuine collision.
            error_log('[ErpSequence] seed lookup failed for ' . $docType . ': ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Peek at the number that next() would return, WITHOUT consuming it.
     * For showing "next invoice will be ..." on a form that has not saved yet.
     * Never use the result as the number actually stored on a document.
     */
    public static function preview(string $docType, int $warehouseId = 0, ?int $year = null): string
    {
        if (!isset(self::PREFIXES[$docType])) {
            throw new InvalidArgumentException("Unknown ERP document type: {$docType}");
        }
        $year   = $year ?: (int)date('Y');
        $prefix = self::PREFIXES[$docType];

        $row = Database::getInstance()->fetch(
            "SELECT prefix, padding, next_number
             FROM erp_number_sequences
             WHERE doc_type = :t AND year = :y AND warehouse_id = :w",
            ['t' => $docType, 'y' => $year, 'w' => max(0, $warehouseId)]
        );

        $number  = $row ? (int)$row['next_number'] : 1;
        $padding = $row ? max(1, (int)$row['padding']) : 6;
        $prefix  = $row['prefix'] ?? $prefix;

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string)$number, $padding, '0', STR_PAD_LEFT));
    }
}
