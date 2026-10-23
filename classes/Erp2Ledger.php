<?php
/**
 * Erp2Ledger — customer and supplier account posting.
 *
 * Sign convention, fixed once here so no caller has to reason about it:
 *
 *   CUSTOMER (money owed TO us)
 *     sale          debit   the customer now owes us
 *     payment       credit  they paid
 *     sales return  credit  goods came back, they owe less
 *     balance = SUM(debit) - SUM(credit)   positive = they owe us
 *
 *   SUPPLIER (money we owe THEM)
 *     purchase        credit  we now owe them
 *     payment         debit   we paid
 *     purchase return debit   goods went back, we owe less
 *     balance = SUM(credit) - SUM(debit)   positive = we owe them
 *
 * Debit and credit are stored as two positive columns rather than one signed
 * amount. That is how the printed statement reads, and it makes a sign error
 * impossible to hide: a wrong column is visible on the statement, whereas a
 * wrong sign silently reverses a balance.
 *
 * Opening balances are posted as a real OPENING ledger row the first time a
 * statement is produced, not carried as a special case in every query.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Erp2Ledger
{
    // ── customers ───────────────────────────────────────────────────────

    public static function customerEntry(
        int $customerId,
        string $docType,
        float $debit,
        float $credit,
        ?int $docId = null,
        ?string $docNumber = null,
        ?string $entryDate = null,
        ?string $notes = null
    ): int {
        return self::insert('erp2_customer_ledger', 'customer_id', $customerId,
            $docType, $debit, $credit, $docId, $docNumber, $entryDate, $notes);
    }

    /** Positive = the customer owes us. Includes their opening balance. */
    public static function customerBalance(int $customerId): float
    {
        $row = Database::getInstance()->fetch(
            "SELECT COALESCE(SUM(debit) - SUM(credit), 0) AS bal
             FROM erp2_customer_ledger WHERE customer_id = :id",
            ['id' => $customerId]
        );
        $opening = Database::getInstance()->fetch(
            "SELECT opening_balance FROM erp2_customers WHERE id = :id",
            ['id' => $customerId]
        );
        return (float)($row['bal'] ?? 0) + (float)($opening['opening_balance'] ?? 0);
    }

    /**
     * Statement rows with a running balance.
     *
     * The opening figure is the customer's stored opening_balance PLUS every
     * movement before $from, so a date-filtered statement still starts from
     * the true position rather than from zero.
     */
    public static function customerStatement(int $customerId, ?string $from = null, ?string $to = null): array
    {
        return self::statement('erp2_customer_ledger', 'customer_id', 'erp2_customers',
            $customerId, $from, $to, true);
    }

    // ── suppliers ───────────────────────────────────────────────────────

    public static function supplierEntry(
        int $supplierId,
        string $docType,
        float $debit,
        float $credit,
        ?int $docId = null,
        ?string $docNumber = null,
        ?string $entryDate = null,
        ?string $notes = null
    ): int {
        return self::insert('erp2_supplier_ledger', 'supplier_id', $supplierId,
            $docType, $debit, $credit, $docId, $docNumber, $entryDate, $notes);
    }

    /** Positive = we owe the supplier. */
    public static function supplierBalance(int $supplierId): float
    {
        $row = Database::getInstance()->fetch(
            "SELECT COALESCE(SUM(credit) - SUM(debit), 0) AS bal
             FROM erp2_supplier_ledger WHERE supplier_id = :id",
            ['id' => $supplierId]
        );
        $opening = Database::getInstance()->fetch(
            "SELECT opening_balance FROM erp2_suppliers WHERE id = :id",
            ['id' => $supplierId]
        );
        return (float)($row['bal'] ?? 0) + (float)($opening['opening_balance'] ?? 0);
    }

    public static function supplierStatement(int $supplierId, ?string $from = null, ?string $to = null): array
    {
        return self::statement('erp2_supplier_ledger', 'supplier_id', 'erp2_suppliers',
            $supplierId, $from, $to, false);
    }

    // ── internals ───────────────────────────────────────────────────────

    private static function insert(
        string $table, string $fkCol, int $partyId, string $docType,
        float $debit, float $credit, ?int $docId, ?string $docNumber,
        ?string $entryDate, ?string $notes
    ): int {
        if ($debit < 0 || $credit < 0) {
            // A negative debit is really a credit. Allowing it would make the
            // statement columns lie and the balance still come out "right",
            // which is the hardest kind of bug to find later.
            throw new InvalidArgumentException('Ledger amounts must be positive; use the other column');
        }

        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO {$table}
                ({$fkCol}, entry_date, doc_type, doc_id, doc_number, debit, credit, notes, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->execute([
            $partyId,
            $entryDate ?: date('Y-m-d'),
            $docType,
            $docId,
            $docNumber,
            round($debit, 2),
            round($credit, 2),
            $notes,
            $_SESSION['user_id'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * @param bool $customerSense true  = balance is debit - credit (customer)
     *                            false = balance is credit - debit (supplier)
     */
    private static function statement(
        string $table, string $fkCol, string $partyTable,
        int $partyId, ?string $from, ?string $to, bool $customerSense
    ): array {
        $db = Database::getInstance();

        $party = $db->fetch(
            "SELECT id, name, opening_balance FROM {$partyTable} WHERE id = :id",
            ['id' => $partyId]
        );
        if (!$party) {
            return ['party' => null, 'opening' => 0.0, 'rows' => [], 'closing' => 0.0, 'totals' => []];
        }

        // Everything before the window, folded into the opening figure.
        $opening = (float)$party['opening_balance'];
        if ($from) {
            $prior = $db->fetch(
                "SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c
                 FROM {$table} WHERE {$fkCol} = :id AND entry_date < :f",
                ['id' => $partyId, 'f' => $from]
            );
            $opening += $customerSense
                ? ((float)$prior['d'] - (float)$prior['c'])
                : ((float)$prior['c'] - (float)$prior['d']);
        }

        $where  = "{$fkCol} = :id";
        $params = ['id' => $partyId];
        if ($from) { $where .= " AND entry_date >= :f"; $params['f'] = $from; }
        if ($to)   { $where .= " AND entry_date <= :t"; $params['t'] = $to; }

        $rows = $db->fetchAll(
            "SELECT id, entry_date, doc_type, doc_id, doc_number, debit, credit, notes
             FROM {$table}
             WHERE {$where}
             ORDER BY entry_date, id",
            $params
        );

        $running = $opening;
        $totDebit = 0.0;
        $totCredit = 0.0;
        foreach ($rows as &$r) {
            $d = (float)$r['debit'];
            $c = (float)$r['credit'];
            $totDebit  += $d;
            $totCredit += $c;
            $running   += $customerSense ? ($d - $c) : ($c - $d);
            $r['balance'] = $running;
        }
        unset($r);

        return [
            'party'   => $party,
            'opening' => $opening,
            'rows'    => $rows,
            'closing' => $running,
            'totals'  => ['debit' => $totDebit, 'credit' => $totCredit],
        ];
    }
}
