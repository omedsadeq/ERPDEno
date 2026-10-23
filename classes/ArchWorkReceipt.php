<?php
/**
 * ArchWorkReceipt — وەسڵی کارە ئەنجامدراوەکان
 *
 * The arch (ئەرک) counterpart of a visit invoice. An arch job is priced once
 * when the case is opened; every later visit only records WHAT WAS DONE. So
 * this receipt deliberately:
 *
 *   - carries no prices and creates no new debt (the works are a list, not a
 *     bill — arch_procedure_logs.price is not printed on it),
 *   - is settled against the patient's EXISTING outstanding debt, which the
 *     accountant may take a part of when approving,
 *   - is ONE document: works + قەرزی پێشوو + دراو + ماوە. No separate payment
 *     voucher is produced for the money it collects.
 *
 * The money itself is booked through DebtPayment::distributePayment(), the
 * clinic's existing patient-debt collection path, so an approved receipt shows
 * up in the income reports like any other collection. The resulting master
 * payment id is stored on the receipt, which is what ties the cash to this
 * document instead of to a voucher of its own.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/PatientFinance.php';
require_once __DIR__ . '/DebtPayment.php';

class ArchWorkReceipt
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** The feature is inert until its migration has run. */
    public function tableExists(): bool
    {
        return $this->db->tableExists('arch_work_receipts');
    }

    // ================================================================
    // Doctor side — sending
    // ================================================================

    /**
     * Attach every not-yet-sent procedure log of the case to a new receipt and
     * hand it to accounting. Returns the created receipt, or a failure when
     * there is nothing unsent to send.
     */
    public function sendUnsent(int $caseId, ?int $userId = null, string $doctorNotes = ''): array
    {
        if (!$this->tableExists()) {
            return ['success' => false, 'message' => 'خشتەی وەسڵی کارەکان بەردەست نییە'];
        }
        $case = $this->db->fetch('SELECT id, case_number, scope_type FROM cases WHERE id = :id', ['id' => $caseId]);
        if (!$case) {
            return ['success' => false, 'message' => 'کەیس نەدۆزرایەوە'];
        }

        $logs = $this->db->fetchAll(
            'SELECT id, procedure_date FROM arch_procedure_logs
             WHERE case_id = :cid AND work_receipt_id IS NULL
             ORDER BY procedure_date, id',
            ['cid' => $caseId]
        );
        if (!$logs) {
            return ['success' => false, 'message' => 'هیچ کارێکی نەنێردراو نییە'];
        }

        // The receipt is dated by the newest work it carries, the way a visit
        // invoice is dated by its work day.
        $workDate = $logs[count($logs) - 1]['procedure_date'] ?: date('Y-m-d');

        $this->db->beginTransaction();
        try {
            $seqRow = $this->db->fetch(
                'SELECT COALESCE(MAX(receipt_seq), 0) AS max_seq FROM arch_work_receipts WHERE case_id = :cid FOR UPDATE',
                ['cid' => $caseId]
            );
            $seq = (int)($seqRow['max_seq'] ?? 0) + 1;
            $receiptNo = $case['case_number'] . '-W' . $seq;

            $receiptId = $this->db->insert('arch_work_receipts', [
                'case_id'      => $caseId,
                'receipt_no'   => $receiptNo,
                'receipt_seq'  => $seq,
                'work_date'    => $workDate,
                'status'       => 'sent',
                'doctor_notes' => $doctorNotes !== '' ? $doctorNotes : null,
                'sent_by'      => $userId ? (int)$userId : null,
                'sent_at'      => date('Y-m-d H:i:s'),
            ]);

            $ids = implode(',', array_map('intval', array_column($logs, 'id')));
            $this->db->query(
                "UPDATE arch_procedure_logs SET work_receipt_id = :rid WHERE id IN ({$ids})",
                ['rid' => $receiptId]
            );

            AuditLog::log('arch_work_receipt_sent', 'arch_work_receipts', $receiptId, null, [
                'case_id'    => $caseId,
                'receipt_no' => $receiptNo,
                'logs'       => count($logs),
            ], $userId);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success'    => true,
            'receipt_id' => (int)$receiptId,
            'receipt_no' => $receiptNo,
            'logs'       => count($logs),
        ];
    }

    // ================================================================
    // Reads
    // ================================================================

    public function getById(int $id)
    {
        if (!$this->tableExists()) {
            return null;
        }
        return $this->db->fetch(
            'SELECT r.*, c.case_number, c.scope_type,
                    sender.full_name AS sent_by_name,
                    approver.full_name AS approved_by_name
             FROM arch_work_receipts r
             JOIN cases c ON c.id = r.case_id
             LEFT JOIN users sender ON sender.id = r.sent_by
             LEFT JOIN users approver ON approver.id = r.approved_by
             WHERE r.id = :id',
            ['id' => $id]
        );
    }

    public function getByCase(int $caseId): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT r.*, sender.full_name AS sent_by_name, approver.full_name AS approved_by_name,
                    (SELECT COUNT(*) FROM arch_procedure_logs l WHERE l.work_receipt_id = r.id) AS work_count
             FROM arch_work_receipts r
             LEFT JOIN users sender ON sender.id = r.sent_by
             LEFT JOIN users approver ON approver.id = r.approved_by
             WHERE r.case_id = :cid
             ORDER BY r.receipt_seq DESC',
            ['cid' => $caseId]
        );
    }

    /** The works carried by one receipt, in the order they were performed. */
    public function getWorks(int $receiptId): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT l.id, l.procedure_date, l.procedure_time, l.arch_affected, l.notes,
                    l.next_appointment_date,
                    COALESCE(l.custom_procedure_name, t.type_name) AS display_name,
                    t.type_code,
                    u.full_name AS performed_by_name
             FROM arch_procedure_logs l
             LEFT JOIN arch_procedure_types t ON t.id = l.procedure_type_id
             LEFT JOIN users u ON u.id = l.performed_by
             WHERE l.work_receipt_id = :rid
             ORDER BY l.procedure_date, l.id',
            ['rid' => $receiptId]
        );
    }

    /** Patient behind a receipt's case. */
    public function patientFor(int $caseId)
    {
        return $this->db->fetch(
            'SELECT p.id, p.full_name, p.phone, p.patient_code
             FROM cases c
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE c.id = :cid',
            ['cid' => $caseId]
        );
    }

    /**
     * The debt figure this receipt is settled against: the patient's whole
     * outstanding balance (every case + X-ray, less any credit), which is what
     * PatientFinance already computes for the statement screen.
     *
     * A receipt that is already approved keeps the figure it was approved with
     * — the printed document must not change under later activity.
     */
    public function debtFor(array $receipt): float
    {
        if (($receipt['status'] ?? '') === 'approved') {
            return (float)$receipt['patient_debt_before'];
        }
        $patient = $this->patientFor((int)$receipt['case_id']);
        if (!$patient) {
            return 0.0;
        }
        $summary = (new PatientFinance())->summary((int)$patient['id']);
        return (float)$summary['net_debt'];
    }

    // ================================================================
    // Accountant side
    // ================================================================

    /**
     * Approve the receipt and, when the accountant entered an amount, collect
     * it against the patient's debt.
     *
     * @param float $paid amount taken now (0 = works acknowledged, no money)
     */
    public function approve(int $receiptId, int $actorId, float $paid = 0.0, string $method = 'cash', string $notes = ''): array
    {
        $receipt = $this->getById($receiptId);
        if (!$receipt) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        if ($receipt['status'] !== 'sent') {
            return ['success' => false, 'message' => 'ئەم وەسڵە پێشتر جێبەجێکراوە'];
        }
        $patient = $this->patientFor((int)$receipt['case_id']);
        if (!$patient) {
            return ['success' => false, 'message' => 'نەخۆش نەدۆزرایەوە'];
        }

        $debtBefore = $this->debtFor($receipt);
        $paid = round(max(0, $paid), 2);
        if ($paid > $debtBefore + 0.009) {
            return ['success' => false, 'message' => 'بڕی دراو ناتوانێت لە قەرزی ماوە زیاتر بێت'];
        }
        $method = in_array($method, ['cash', 'card', 'transfer', 'other'], true) ? $method : 'cash';

        $paymentId = null;
        if ($paid > 0.009) {
            // Booked through the clinic's existing debt collection so the money
            // lands in the income reports and the patient's debt actually drops.
            // The receipt keeps the master payment id, which is what makes this
            // document — not a separate voucher — the record of the cash.
            $collect = (new DebtPayment())->distributePayment(
                (int)$patient['id'],
                $paid,
                $method,
                $actorId,
                'وەسڵی کارە ئەنجامدراوەکان ' . $receipt['receipt_no']
            );
            if (empty($collect['success'])) {
                return ['success' => false, 'message' => $collect['message'] ?? 'پارەکە تۆمار نەکرا'];
            }
            // distributePayment() writes one master row and spreads it over the
            // patient's debts itself; it only omits the id when there was no
            // debt at all and the whole amount became credit.
            $paymentId = isset($collect['master_payment_id']) ? (int)$collect['master_payment_id'] : null;
        }

        $this->db->update('arch_work_receipts', [
            'status'              => 'approved',
            'patient_debt_before' => $debtBefore,
            'paid_amount'         => $paid,
            'payment_method'      => $paid > 0.009 ? $method : null,
            'payment_id'          => $paymentId,
            'accounting_notes'    => $notes !== '' ? $notes : null,
            'approved_by'         => $actorId,
            'approved_at'         => date('Y-m-d H:i:s'),
        ], 'id = :id AND status = :st', ['id' => $receiptId, 'st' => 'sent']);

        AuditLog::log('arch_work_receipt_approved', 'arch_work_receipts', $receiptId, $receipt, [
            'paid_amount'         => $paid,
            'patient_debt_before' => $debtBefore,
            'payment_id'          => $paymentId,
        ], $actorId);

        return ['success' => true, 'receipt' => $this->getById($receiptId)];
    }

    /**
     * Hand the receipt back to the doctor. Its works are released so they can
     * be corrected and sent again on a fresh receipt.
     */
    public function returnForCorrection(int $receiptId, int $actorId, string $reason): array
    {
        $receipt = $this->getById($receiptId);
        if (!$receipt) {
            return ['success' => false, 'message' => 'وەسڵ نەدۆزرایەوە'];
        }
        if ($receipt['status'] !== 'sent') {
            return ['success' => false, 'message' => 'ئەم وەسڵە پێشتر جێبەجێکراوە'];
        }
        if (trim($reason) === '') {
            return ['success' => false, 'message' => 'هۆکار پێویستە'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->update('arch_work_receipts', [
                'status'        => 'returned',
                'return_reason' => trim($reason),
                'approved_by'   => $actorId,
                'approved_at'   => date('Y-m-d H:i:s'),
            ], 'id = :id AND status = :st', ['id' => $receiptId, 'st' => 'sent']);
            // Released, so the doctor's next send picks them up again.
            $this->db->query(
                'UPDATE arch_procedure_logs SET work_receipt_id = NULL WHERE work_receipt_id = :rid',
                ['rid' => $receiptId]
            );
            AuditLog::log('arch_work_receipt_returned', 'arch_work_receipts', $receiptId, $receipt, [
                'reason' => trim($reason),
            ], $actorId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        return ['success' => true];
    }
}
