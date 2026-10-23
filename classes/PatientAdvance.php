<?php
require_once __DIR__ . '/CashboxLedger.php';
/**
 * PatientAdvance — ئامانەتی نەخۆش: money taken at the counter before any case
 * exists to put it on.
 *
 * receive() records custody on the patient's name, in قاسەی ئامانەت. It is
 * informational in reports and never income on the day it is received.
 * Only an accountant/admin may apply it to an invoice or a pending request.
 * That creates/funds a canonical approved payment dated today, with method
 * 'advance'; the payment alone counts as income and receives the doctor's
 * configured split. Refund/reopen restores the same custody atomically.
 * prior_paid_amount still means money paid before this system existed.
 * advance_paid_amount is retained only for legacy invoice allocations.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class PatientAdvance
{
    /** Who may take money at the counter / hand it back. */
    public const METHODS = ['cash', 'card', 'transfer', 'other'];

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function authorizeAccountant(int $actorId): void
    {
        $actor = $this->db->fetch('SELECT role, is_active FROM users WHERE id=?', [$actorId]);
        if (!$actor || !$actor['is_active'] || !in_array($actor['role'], ['admin', 'accountant'], true)) {
            throw new DomainException('بەکارهێنانی ئامانەت تەنها بۆ ژمێریار و ئەدمینە');
        }
    }

    /** Approve a doctor's request using money already held for this patient. */
    public function applyToPendingPayment(int $paymentId, ?float $amount, int $actorId, string $note = ''): array
    {
        require_once __DIR__ . '/Payment.php';
        return (new Payment())->approve($paymentId, $actorId, $amount, $note, true);
    }

    /** One approved payment, dated today, against this invoice. */
    public function applyToInvoice(int $invoiceId, float $amount, int $actorId, string $key = '', string $notes = ''): array
    {
        try {
            $this->authorizeAccountant($actorId);
            $invoice = $this->db->fetch('SELECT case_id FROM case_invoices WHERE id=?', [$invoiceId]);
            if (!$invoice) throw new RuntimeException('وەسڵ نەدۆزرایەوە');
            require_once __DIR__ . '/Payment.php';
            return (new Payment())->directPayment([
                'case_id' => (int)$invoice['case_id'], 'amount' => $amount,
                'payment_method' => 'advance', 'from_advance' => true,
                'payment_date' => date('Y-m-d H:i:s'), 'notes' => $notes,
                'allocations' => [$invoiceId => $amount], 'allow_draft' => true,
                'idempotency_key' => $key ?: ('amanat-' . bin2hex(random_bytes(16))),
            ], $actorId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Internal payment hook. The caller owns approval and the transaction. */
    public function fundPayment(int $paymentId, int $actorId): void
    {
        $this->authorizeAccountant($actorId);
        if (!$this->db->inTransaction()) throw new LogicException('Funding requires the payment transaction.');
        $payment = $this->db->fetch(
            "SELECT p.*, pf.patient_id FROM payments p
             JOIN cases c ON c.id=p.case_id JOIN patient_folders pf ON pf.id=c.folder_id
             WHERE p.id=? FOR UPDATE", [$paymentId]
        );
        if (!$payment || !in_array($payment['status'], ['pending', 'approved'], true)) {
            throw new RuntimeException('دۆخی پارەدان گۆڕاوە');
        }
        if ($this->db->fetch('SELECT id FROM patient_advance_allocations WHERE payment_id=? AND reversed_at IS NULL FOR UPDATE', [$paymentId])) {
            throw new RuntimeException('ئامانەت پێشتر بۆ ئەم پارەدانە بەکارهاتووە');
        }
        // Lock the actual receipts before reading their allocations. Locking
        // reads see the latest committed spend, including under REPEATABLE READ.
        $receipts = $this->db->fetchAll(
            'SELECT * FROM patient_advances WHERE patient_id=? ORDER BY id FOR UPDATE', [(int)$payment['patient_id']]
        );
        $used = [];
        if ($receipts) {
            $ids = array_column($receipts, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->fetchAll("SELECT advance_id,amount FROM patient_advance_allocations WHERE advance_id IN ($marks) AND reversed_at IS NULL FOR UPDATE", $ids) as $row) {
                $used[$row['advance_id']] = ($used[$row['advance_id']] ?? 0) + CashboxLedger::cents($row['amount']);
            }
        }
        usort($receipts, static function ($a, $b) {
            return strcmp($a['received_at'], $b['received_at']) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        $left = CashboxLedger::cents($payment['amount']);
        $cashAmount = 0;
        $invoices = $this->db->fetchAll('SELECT invoice_id FROM payment_allocations WHERE payment_id=?', [$paymentId]);
        $invoiceId = count($invoices) === 1 ? (int)$invoices[0]['invoice_id'] : null;
        $now = date('Y-m-d H:i:s');
        foreach ($receipts as $receipt) {
            if ($left <= 0) break;
            $available = CashboxLedger::cents($receipt['amount']) - CashboxLedger::cents($receipt['refunded_amount']) - ($used[$receipt['id']] ?? 0);
            $take = min($left, max(0, $available));
            if (!$take) continue;
            $this->db->insert('patient_advance_allocations', [
                'advance_id' => (int)$receipt['id'], 'patient_id' => (int)$payment['patient_id'],
                'case_id' => (int)$payment['case_id'], 'invoice_id' => $invoiceId,
                'payment_id' => $paymentId, 'amount' => CashboxLedger::decimal($take),
                'allocated_by' => $actorId, 'allocated_at' => $now,
            ]);
            CashboxLedger::capture('advance', (int)$receipt['id'], $actorId, 'گواستنەوەی ئامانەت بۆ ' . $payment['payment_number'], 'amanat_out');
            if ($receipt['payment_method'] === 'cash') $cashAmount += $take;
            $left -= $take;
        }
        if ($left > 0) throw new RuntimeException('ئامانەتی بەردەست بەشی ئەم بڕە ناکات');
        $this->db->update('payments', ['payment_method' => 'advance', 'payment_date' => $now, 'received_by' => $actorId, 'advance_cash_amount' => CashboxLedger::decimal($cashAmount)], 'id=?', [$paymentId]);
        AuditLog::log('patient_advance_applied', 'payments', $paymentId, null, ['amount' => $payment['amount'], 'patient_id' => $payment['patient_id']], $actorId);
    }

    /** A reversal restores custody, without handing fresh cash to the patient. */
    public function releaseForPayment(int $paymentId, int $actorId): float
    {
        if (!$this->db->inTransaction()) throw new LogicException('Release requires the payment transaction.');
        $receipts = $this->db->fetchAll(
            'SELECT a.id FROM patient_advances a WHERE a.id IN (SELECT advance_id FROM patient_advance_allocations WHERE payment_id=?) ORDER BY a.id FOR UPDATE', [$paymentId]
        );
        $rows = $this->db->fetchAll('SELECT id,amount FROM patient_advance_allocations WHERE payment_id=? AND reversed_at IS NULL FOR UPDATE', [$paymentId]);
        $released = array_sum(array_map(static function ($r) { return CashboxLedger::cents($r['amount']); }, $rows));
        $this->db->update('patient_advance_allocations', ['reversed_at' => date('Y-m-d H:i:s'), 'reversed_by' => $actorId], 'payment_id=? AND reversed_at IS NULL', [$paymentId]);
        foreach ($receipts as $receipt) CashboxLedger::capture('advance', (int)$receipt['id'], $actorId, 'گەڕاندنەوەی ئامانەت لە پارەدان', 'amanat_in');
        AuditLog::log('patient_advance_released', 'payments', $paymentId, null, ['released' => CashboxLedger::decimal($released)], $actorId);
        return $released / 100;
    }

    // ================================================================
    // Reads
    // ================================================================

    /**
     * What this patient still has sitting on their name, ready to be put on a
     * case. Computed, never stored: receipts minus refunds minus the
     * allocations that have not been undone. A stored running balance would be
     * one more thing that can drift out of step with the rows behind it.
     */
    public function balanceOf(int $patientId): float
    {
        return round($this->summaryFor($patientId)['available'], 2);
    }

    /**
     * @return array{received:float,refunded:float,allocated:float,available:float}
     */
    public function summaryFor(int $patientId): array
    {
        $totals = $this->db->fetch(
            "SELECT COALESCE(SUM(amount),0) AS received,
                    COALESCE(SUM(refunded_amount),0) AS refunded
             FROM patient_advances WHERE patient_id = :pid",
            ['pid' => $patientId]
        ) ?: ['received' => 0, 'refunded' => 0];

        $allocated = $this->db->fetch(
            "SELECT COALESCE(SUM(amount),0) AS allocated
             FROM patient_advance_allocations
             WHERE patient_id = :pid AND reversed_at IS NULL",
            ['pid' => $patientId]
        ) ?: ['allocated' => 0];

        $received = (float)$totals['received'];
        $refunded = (float)$totals['refunded'];
        $used     = (float)$allocated['allocated'];

        return [
            'received'  => round($received, 2),
            'refunded'  => round($refunded, 2),
            'allocated' => round($used, 2),
            'available' => round(max(0, $received - $refunded - $used), 2),
        ];
    }

    /**
     * The receipts themselves, newest first, each with how much of it is still
     * unspent — what the doctor's screen shows so they can see the patient
     * really did pay at 09:30 this morning.
     */
    public function listFor(int $patientId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT a.*,
                    u.full_name AS received_by_name,
                    doc.full_name AS doctor_name,
                    r.full_name AS refunded_by_name,
                    COALESCE(al.used,0) AS allocated_amount,
                    GREATEST(0, a.amount - a.refunded_amount - COALESCE(al.used,0)) AS available_amount
             FROM patient_advances a
             LEFT JOIN users u ON u.id = a.received_by
             LEFT JOIN users doc ON doc.id = a.doctor_id
             LEFT JOIN users r ON r.id = a.refunded_by
             LEFT JOIN (
                SELECT advance_id, SUM(amount) AS used
                FROM patient_advance_allocations
                WHERE reversed_at IS NULL
                GROUP BY advance_id
             ) al ON al.advance_id = a.id
             WHERE a.patient_id = :pid
             ORDER BY a.received_at DESC, a.id DESC
             LIMIT " . max(1, min(200, $limit)),
            ['pid' => $patientId]
        );
    }

    public function getById(int $advanceId): ?array
    {
        $row = $this->db->fetch(
            "SELECT a.*, p.full_name AS patient_name, p.phone AS patient_phone,
                    p.patient_code, u.full_name AS received_by_name,
                    doc.full_name AS doctor_name
             FROM patient_advances a
             JOIN patients p ON p.id = a.patient_id
             LEFT JOIN users u ON u.id = a.received_by
             LEFT JOIN users doc ON doc.id = a.doctor_id
             WHERE a.id = :id",
            ['id' => $advanceId]
        );
        return $row ?: null;
    }

    /** Where an advance went — shown on the receipt and the patient statement. */
    public function allocationsOf(int $advanceId): array
    {
        return $this->db->fetchAll(
            "SELECT al.*, ci.invoice_no, c.case_number, u.full_name AS allocated_by_name
             FROM patient_advance_allocations al
             LEFT JOIN case_invoices ci ON ci.id = al.invoice_id
             LEFT JOIN cases c ON c.id = al.case_id
             LEFT JOIN users u ON u.id = al.allocated_by
             WHERE al.advance_id = :id
             ORDER BY al.allocated_at ASC, al.id ASC",
            ['id' => $advanceId]
        );
    }

    // ================================================================
    // Writes
    // ================================================================

    /**
     * Take custody at the counter. Reports show this separately from income.
     *
     * @param int|null $doctorId whose patient this old balance belongs to. The
     *   counter picks it when the money is taken; NULL only for the receipts
     *   written before the column existed (2026_09_27_patient_advance_doctor).
     * @return array{success:bool,advance_id?:int,advance_no?:string,message?:string}
     */
    public function receive(int $patientId, float $amount, string $method, ?string $notes, int $actorId, ?int $doctorId = null): array
    {
        $amount = round((float)$amount, 2);
        if ($patientId <= 0) {
            return ['success' => false, 'message' => 'نەخۆش دیاری نەکراوە'];
        }
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'بڕی پارە دەبێت لە سفر زیاتر بێت'];
        }
        if (!in_array($method, self::METHODS, true)) {
            $method = 'cash';
        }

        $this->db->beginTransaction();
        try {
            $patient = $this->db->fetch('SELECT id FROM patients WHERE id = :id', ['id' => $patientId]);
            if (!$patient) {
                throw new RuntimeException('نەخۆش نەدۆزرایەوە');
            }

            if ($doctorId !== null && $doctorId > 0) {
                $doctor = $this->db->fetch(
                    "SELECT id FROM users WHERE id = :id AND role = 'doctor'",
                    ['id' => $doctorId]
                );
                if (!$doctor) {
                    throw new InvalidArgumentException('دکتۆر نەدۆزرایەوە');
                }
            }

            $now = date('Y-m-d H:i:s');
            $advanceId = (int)$this->db->insert('patient_advances', [
                'advance_no'      => $this->generateNumber(),
                'patient_id'      => $patientId,
                'doctor_id'       => ($doctorId !== null && $doctorId > 0) ? $doctorId : null,
                'amount'          => $amount,
                'payment_method'  => $method,
                'notes'           => ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
                'received_by'     => $actorId ?: null,
                'received_at'     => $now,
            ]);

            $saved = $this->db->fetch('SELECT advance_no FROM patient_advances WHERE id = :id', ['id' => $advanceId]);

            AuditLog::log('patient_advance_received', 'patient_advances', $advanceId, null, [
                'patient_id' => $patientId,
                'doctor_id'  => $doctorId ?: null,
                'amount'     => $amount,
                'method'     => $method,
            ], $actorId ?: null);
            CashboxLedger::capture('advance', $advanceId, $actorId, 'نەقدی وەسڵی پێشوەختە');

            $this->db->commit();
            return [
                'success'    => true,
                'advance_id' => $advanceId,
                'advance_no' => $saved['advance_no'] ?? '',
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Compatibility entry point for the shared case pane. Only accounting may
     * apply custody; it becomes an approved payment rather than draft credit.
     */
    public function allocate(int $invoiceId, float $amount, int $actorId): array
    {
        return $this->applyToInvoice($invoiceId, $amount, $actorId);
    }
    /**
     * Undo every allocation this invoice carries and hand the money back to the
     * patient's balance. Used when the doctor put it on the wrong case; the
     * rows stay, flagged reversed, so the history still shows what happened.
     */
    public function reverseForInvoice(int $invoiceId, int $actorId): array
    {
        $this->db->beginTransaction();
        try {
            $this->authorizeAccountant($actorId);
            require_once __DIR__ . '/Payment.php';
            $links = $this->db->fetchAll("SELECT DISTINCT p.id,p.amount,(SELECT COUNT(*) FROM payment_allocations pa2 WHERE pa2.payment_id=p.id) allocation_count FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.id WHERE pa.invoice_id=? AND p.payment_method='advance' AND p.status='approved'", [$invoiceId]);
            $released = 0.0;
            foreach ($links as $link) {
                if ((int)$link['allocation_count'] > 1) throw new RuntimeException('ئەم قەبزە چەند وەسڵێکی داوە؛ لە بەشی قەبزەکان هەڵیوەشێنەوە');
                $result = (new Payment())->refund((int)$link['id'], 'گەڕاندنەوەی ئامانەتی وەسڵ', $actorId);
                if (empty($result['success'])) throw new RuntimeException($result['message']);
                $released += (float)$link['amount'];
            }
            $invoice = $this->db->fetch(
                "SELECT * FROM case_invoices
                 WHERE id = :id
                 FOR UPDATE",
                ['id' => $invoiceId]
            );
            if (!$invoice) {
                throw new RuntimeException('وەسڵ نەدۆزرایەوە');
            }

            $released += $this->releaseAllocations($invoiceId, $actorId);

            $this->db->commit();
            return ['success' => true, 'released' => $released];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The release itself, WITHOUT the draft/returned gate above — for the
     * accounting side, where an already-approved invoice is being cancelled
     * (classes/ApprovalReversal.php): the bill is going away, so the ئامانەت it
     * consumed has to become available on the patient's name again whatever
     * state the invoice is in. Real cash was taken for it at the counter; it
     * may not stay spent on a cancelled document.
     *
     * Call inside the caller's own transaction. The allocation rows stay,
     * flagged reversed, so the history still shows where the money went.
     *
     * @return float how much was handed back
     */
    public function releaseAllocations(int $invoiceId, int $actorId): float
    {
        $before = (float)($this->db->fetch(
            'SELECT advance_paid_amount FROM case_invoices WHERE id = :id',
            ['id' => $invoiceId]
        )['advance_paid_amount'] ?? 0);

        $now = date('Y-m-d H:i:s');
        $this->db->update('patient_advance_allocations',
            ['reversed_at' => $now, 'reversed_by' => $actorId ?: null],
            'invoice_id = :id AND payment_id IS NULL AND reversed_at IS NULL',
            ['id' => $invoiceId]
        );
        $this->db->update('case_invoices',
            ['advance_paid_amount' => 0, 'updated_at' => $now],
            'id = :id', ['id' => $invoiceId]
        );

        AuditLog::log('invoice_advance_reversed', 'case_invoices', $invoiceId,
            ['advance_paid_amount' => $before],
            ['advance_paid_amount' => 0],
            $actorId ?: null
        );

        return round($before, 2);
    }

    /**
     * Give unspent custody back to the patient. Only custody changes; income,
     * doctor shares and the center's revenue do not change.
     */
    public function refund(int $advanceId, float $amount, string $reason, int $actorId): array
    {
        $amount = round((float)$amount, 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'بڕی گەڕاندنەوە دەبێت لە سفر زیاتر بێت'];
        }
        if (trim($reason) === '') {
            return ['success' => false, 'message' => 'هۆکاری گەڕاندنەوە بنووسە'];
        }

        $this->db->beginTransaction();
        try {
            $advance = $this->db->fetch(
                'SELECT * FROM patient_advances WHERE id = :id FOR UPDATE',
                ['id' => $advanceId]
            );
            if (!$advance) {
                throw new RuntimeException('وەسڵی ئامانەت نەدۆزرایەوە');
            }

            $used = (float)($this->db->fetch(
                "SELECT COALESCE(SUM(amount),0) AS used
                 FROM patient_advance_allocations
                 WHERE advance_id = :id AND reversed_at IS NULL",
                ['id' => $advanceId]
            )['used'] ?? 0);

            $refundable = round((float)$advance['amount'] - (float)$advance['refunded_amount'] - $used, 2);
            if ($amount > $refundable + 0.009) {
                throw new InvalidArgumentException('بڕی گەڕاندنەوە لە بڕی بەکارنەهاتوو زیاترە');
            }

            $newRefunded = round((float)$advance['refunded_amount'] + $amount, 2);
            $this->db->update('patient_advances', [
                'refunded_amount' => $newRefunded,
                'refunded_by'     => $actorId ?: null,
                'refunded_at'     => date('Y-m-d H:i:s'),
                'refund_reason'   => trim($reason),
            ], 'id = :id', ['id' => $advanceId]);

            AuditLog::log('patient_advance_refunded', 'patient_advances', $advanceId,
                ['refunded_amount' => (float)$advance['refunded_amount']],
                ['refunded_amount' => $newRefunded, 'reason' => trim($reason)],
                $actorId ?: null
            );
            CashboxLedger::capture('advance', $advanceId, $actorId, 'گەڕاندنەوەی نەقدی پێشوەختە');

            $this->db->commit();
            return ['success' => true, 'refunded' => $amount];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a receipt written by mistake (wrong patient, wrong amount, typed
     * twice). Refused while any of it still sits on an invoice — that has to
     * be taken off the invoice first, or the invoice would keep a payment no
     * receipt backs. Undone allocations are history of this receipt only and
     * go with it. The cash it put in the doctor's سندوق is taken back out as a
     * refund entry today (the journal is append-only), and the full row is
     * kept in the audit log.
     */
    public function delete(int $advanceId, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'هۆکاری سڕینەوە بنووسە'];
        }

        $this->db->beginTransaction();
        try {
            $advance = $this->db->fetch(
                'SELECT * FROM patient_advances WHERE id = :id FOR UPDATE',
                ['id' => $advanceId]
            );
            if (!$advance) {
                throw new RuntimeException('وەسڵی قەرزی کۆن نەدۆزرایەوە');
            }

            $onInvoices = $this->db->fetchAll(
                "SELECT DISTINCT COALESCE(ci.invoice_no, CONCAT('#', al.invoice_id)) AS invoice_no
                 FROM patient_advance_allocations al
                 LEFT JOIN case_invoices ci ON ci.id = al.invoice_id
                 WHERE al.advance_id = :id AND al.reversed_at IS NULL",
                ['id' => $advanceId]
            );
            if ($onInvoices) {
                throw new RuntimeException('ئەم قەبزە لەسەر وەسڵی '
                    . implode('، ', array_column($onInvoices, 'invoice_no'))
                    . ' دانراوە — سەرەتا لەسەر وەسڵەکە لایبەرە');
            }

            $this->db->query('DELETE FROM patient_advance_allocations WHERE advance_id = :id', ['id' => $advanceId]);
            $this->db->query('DELETE FROM patient_advances WHERE id = :id', ['id' => $advanceId]);

            AuditLog::log('patient_advance_deleted', 'patient_advances', $advanceId,
                $advance, ['reason' => $reason], $actorId ?: null
            );
            // The source row is gone, so the ledger books its cash back to 0.
            CashboxLedger::capture('advance', $advanceId, $actorId,
                'ڕەشکردنەوەی قەبزی قەرزی کۆن ' . $advance['advance_no'] . ' — ' . $reason);

            $this->db->commit();
            return ['success' => true, 'advance_no' => $advance['advance_no']];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof PDOException) {
                error_log('PatientAdvance::delete #' . $advanceId . ': ' . $e->getMessage());
                return ['success' => false, 'message' => 'سڕینەوە سەرنەکەوت'];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * ADV-YYMM-00001, restarting the counter each month. Same shape as the
     * other documents this clinic hands to a patient, so a receipt number says
     * at a glance when it was written.
     */
    private function generateNumber(): string
    {
        $prefix = 'ADV-' . date('ym') . '-';
        // The SUBSTRING offset is interpolated, not bound: prepares are not
        // emulated here (Database.php), so a bound parameter would reach MySQL
        // as a string in a position that wants an integer. It is derived from
        // our own constant prefix, never from input.
        $offset = strlen($prefix) + 1;
        $row = $this->db->fetch(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(advance_no, {$offset}) AS UNSIGNED)),0) AS max_num
             FROM patient_advances WHERE advance_no LIKE :prefix",
            ['prefix' => $prefix . '%']
        );
        return $prefix . str_pad((string)(((int)($row['max_num'] ?? 0)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
