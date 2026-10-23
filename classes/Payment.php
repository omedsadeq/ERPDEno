<?php
/** Canonical payment service: one receipt, one or many invoice allocations. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CaseManager.php';
require_once __DIR__ . '/Installment.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/AccountingLedger.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/CashboxLedger.php';
require_once __DIR__ . '/PaymentDiscount.php';

class Payment
{
    private $db;
    private $caseManager;
    private $installmentManager;
    private $ledger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->caseManager = new CaseManager();
        $this->installmentManager = new Installment();
        $this->ledger = new AccountingLedger();
    }

    private function generatePaymentNumber()
    {
        return 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function caseContext($caseId)
    {
        return $this->db->fetch(
            "SELECT c.id AS case_id,p.full_name AS patient_name,pf.doctor_id
             FROM cases c LEFT JOIN patient_folders pf ON pf.id=c.folder_id
             LEFT JOIN patients p ON p.id=pf.patient_id WHERE c.id=:id",
            ['id' => (int)$caseId]
        );
    }

    private function normalizeType($type)
    {
        return in_array($type, ['cash', 'credit', 'installment'], true) ? $type : 'cash';
    }

    /**
     * 'advance' (لە ئامانەت) is not a way a patient pays at the desk: it marks
     * a payment funded from money already held in قاسەی ئامانەت, and only
     * PatientAdvance::applyToInvoice/applyToPendingPayment may write it — they
     * consume the ئامانەت in the same transaction. Anywhere else it is cash.
     */
    private function normalizeMethod($method, $allowAdvance = false)
    {
        if ($method === 'advance') {
            return $allowAdvance ? 'advance' : 'cash';
        }
        return in_array($method, ['cash', 'card', 'transfer', 'other'], true) ? $method : 'cash';
    }

    private function requestedAllocations(array $data)
    {
        if (!empty($data['allocations']) && is_array($data['allocations'])) {
            return $data['allocations'];
        }
        if (!empty($data['invoice_id'])) {
            return [(int)$data['invoice_id'] => (float)($data['amount'] ?? 0)];
        }
        return [];
    }

    private function prepareAllocations($caseId, $amount, array $requested, $pending, $allowSent, $excludePaymentId = null, $allowDraft = false)
    {
        if (!$requested) {
            $preview = $this->ledger->autoAllocate(
                (int)$caseId, (float)$amount, [], (bool)$pending,
                (bool)$allowSent, $excludePaymentId, (bool)$allowDraft
            );
            if (empty($preview['success'])) {
                return ['success' => false, 'message' => 'The payment cannot be fully allocated to the available invoice balances.'];
            }
            foreach ($preview['allocations'] as $allocation) {
                $requested[$allocation['invoice_id']] = $allocation['amount'];
            }
        }
        return $this->ledger->validateAllocations(
            (int)$caseId, (float)$amount, $requested, (bool)$pending,
            (bool)$allowSent, $excludePaymentId, (bool)$allowDraft
        );
    }

    /**
     * Promotes any invoice actually being paid straight to approved — 'sent'
     * (the normal case: accountant approves while receiving) and, since
     * directPayment()'s allow_draft, also 'draft'/'returned' (the doctor
     * never sent it at all; the accountant collecting cash against it IS the
     * send+approve, just done in one step on the accountant's authority).
     */
    private function approveAllocatedSentInvoices(array $allocations, $actorId)
    {
        foreach ($allocations as $allocation) {
            $invoice = $this->db->fetch('SELECT * FROM case_invoices WHERE id=:id FOR UPDATE', [
                'id' => (int)$allocation['invoice_id']
            ]);
            if ($invoice && in_array($invoice['accounting_status'], ['sent', 'draft', 'returned'], true)) {
                $changes = [
                    'status' => 'sent',
                    'accounting_status' => 'approved',
                    'approved_by' => (int)$actorId,
                    'approved_at' => date('Y-m-d H:i:s'),
                    'returned_by' => null,
                    'returned_at' => null,
                    'return_reason' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                $this->db->update('case_invoices', $changes, 'id=:id', ['id' => (int)$invoice['id']]);
                AuditLog::logChange('invoice_approved_with_payment', 'case_invoices', (int)$invoice['id'], $invoice, $changes, $actorId);
            }
        }
    }

    private function insertPayment(array $data, $status, $actorId)
    {
        $paymentDate = trim((string)($data['payment_date'] ?? ''));
        if ($paymentDate === '' || strtotime($paymentDate) === false) {
            $paymentDate = date('Y-m-d H:i:s');
        } elseif (strlen($paymentDate) === 10) {
            $paymentDate .= ' ' . date('H:i:s');
        }
        return (int)$this->db->insert('payments', [
            'case_id' => (int)$data['case_id'],
            // Deprecated origin hint. payment_allocations is authoritative.
            'invoice_id' => !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null,
            'accounting_report_id' => !empty($data['accounting_report_id']) ? (int)$data['accounting_report_id'] : null,
            'payment_number' => $this->generatePaymentNumber(),
            'idempotency_key' => $data['idempotency_key'] ?? ('internal-' . bin2hex(random_bytes(16))),
            'payment_type' => $this->normalizeType($data['payment_type'] ?? 'cash'),
            'amount' => round((float)$data['amount'], 2),
            'discount_mode' => $data['discount_mode'] ?? 'amount',
            'discount_value' => $data['discount_value'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'discount_reason' => $data['discount_reason'] ?? null,
            'payment_method' => $this->normalizeMethod($data['payment_method'] ?? 'cash', !empty($data['from_advance'])),
            'status' => $status,
            'received_by' => !empty($data['received_by']) ? (int)$data['received_by'] : (int)$actorId,
            'approved_by' => $status === 'approved' ? (int)$actorId : null,
            'notes' => trim((string)($data['notes'] ?? '')),
            'payment_date' => $paymentDate,
        ]);
    }

    private function findIdempotent(array $data)
    {
        $key = trim((string)($data['idempotency_key'] ?? ''));
        if ($key === '') return null;
        return $this->db->fetch('SELECT * FROM payments WHERE idempotency_key=:key', ['key' => $key]);
    }

    /**
     * $allowSent (default false, preserving every existing caller's
     * behavior): when true, a SENT-but-not-yet-approved invoice's net amount
     * also counts as billable/remaining capacity, not just an approved
     * invoice's. Added for updatePendingAmount() (review fix, round 2): its
     * own capacity re-check right after this one already calls
     * AccountingLedger::validateAllocations(..., allowSent=true) — matching
     * Payment::createRequest()'s own allowSent=true — so a doctor's very
     * first request against a merely-sent invoice (the normal case: nothing
     * is ever "approved" until an accountant acts on it) already succeeds
     * fine, but editing that SAME pending request's amount afterwards was
     * being blocked here by a stricter, approved-only cap that had no
     * counterpart in createRequest() at all — an inconsistency between the
     * two paths for the identical underlying invoice, not a deliberate
     * "only after approval" restriction. supersedeStalePendingRequests()
     * (this class's other caller) keeps the strict approved-only default —
     * "is the whole case's confirmed total already fully collected" is a
     * different, and correctly stricter, question.
     */
    public function getCaseBalance($caseId, $excludePaymentId = null, $allowSent = false)
    {
        $summary = $this->ledger->getSummary((int)$caseId);
        $billable = (float)$summary['approved'];
        if ($allowSent) {
            $sentNet = (float)($this->db->fetch(
                "SELECT COALESCE(SUM(GREATEST(0, total_amount - discount_amount)), 0) AS net
                 FROM case_invoices WHERE case_id = :case_id AND accounting_status = 'sent'",
                ['case_id' => (int)$caseId]
            )['net'] ?? 0);
            $billable += $sentNet;
        }
        $params = ['case_id' => (int)$caseId];
        $exclude = '';
        if ($excludePaymentId !== null) {
            $exclude = ' AND p.id<>:payment_id';
            $params['payment_id'] = (int)$excludePaymentId;
        }
        $pending = (float)($this->db->fetch(
            "SELECT COALESCE(SUM(pa.allocated_amount),0) AS amount
             FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id
             JOIN case_invoices ci ON ci.id=pa.invoice_id
             WHERE ci.case_id=:case_id AND p.status='pending' {$exclude}",
            $params
        )['amount'] ?? 0);
        $remaining = max(0, $billable - (float)$summary['paid']);
        $pendingDiscount = (float)($this->db->fetch(
            "SELECT COALESCE(SUM(da.amount),0) AS amount FROM payment_discount_allocations da
             JOIN payments p ON p.id=da.payment_id WHERE p.case_id=:case_id AND p.status='pending' {$exclude}",
            $params
        )['amount'] ?? 0);
        return [
            'billable' => $billable,
            'approved' => $summary['paid'],
            'pending' => $pending,
            'remaining' => $remaining,
            'pending_discount' => $pendingDiscount,
            'unrequested' => max(0, $remaining - $pending - $pendingDiscount),
        ];
    }

    public function supersedeStalePendingRequests($caseId, $actorId = null, $exceptPaymentId = null)
    {
        $balance = $this->getCaseBalance((int)$caseId);
        if (!$balance || $balance['remaining'] > 0.009) return 0;
        $params = ['case_id' => (int)$caseId];
        $exclude = '';
        if ($exceptPaymentId) {
            $exclude = ' AND id<>:except_id';
            $params['except_id'] = (int)$exceptPaymentId;
        }
        $rows = $this->db->fetchAll("SELECT * FROM payments WHERE case_id=:case_id AND status='pending' {$exclude}", $params);
        foreach ($rows as $row) {
            $this->db->update('payments', [
                'status' => 'rejected',
                'rejected_by' => $actorId ? (int)$actorId : null,
                'notes' => trim((string)$row['notes'] . "\nSuperseded because the patient balance was settled."),
            ], 'id=:id', ['id' => (int)$row['id']]);
            AuditLog::log('payment_request_superseded', 'payments', (int)$row['id'], ['status' => 'pending'], ['status' => 'rejected'], $actorId);
        }
        return count($rows);
    }

    /** Doctor-side request. It reserves an allocation but is not valid money. */
    public function createRequest($data)
    {
        $caseId = (int)($data['case_id'] ?? 0);
        $amount = round((float)($data['amount'] ?? 0), 2);
        if ($caseId <= 0 || $amount <= 0) {
            return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
        }
        $actorId = (int)($data['received_by'] ?? ($_SESSION['user_id'] ?? 0));
        $existing = $this->findIdempotent($data);
        if ($existing) {
            return ['success' => true, 'duplicate' => true, 'payment_id' => (int)$existing['id'], 'payment_number' => $existing['payment_number']];
        }
        $allowDraft = !empty($data['allow_draft']);
        $checked = $this->prepareAllocations($caseId, $amount, $this->requestedAllocations($data), true, true, null, $allowDraft);
        if (empty($checked['success'])) return $checked;

        $this->db->beginTransaction();
        try {
            $invoiceIds = array_column($checked['allocations'], 'invoice_id');
            if (!$this->ledger->lockInvoicesForAllocation($caseId, $invoiceIds)) {
                throw new Exception('One or more invoices are no longer available.');
            }
            $checked = $this->ledger->validateAllocations($caseId, $amount, $checked['allocations'], true, true, null, $allowDraft);
            if (empty($checked['success'])) throw new Exception($checked['message']);
            $paymentId = $this->insertPayment($data, 'pending', $actorId);
            foreach ($checked['allocations'] as $allocation) {
                $this->ledger->upsertAllocation($paymentId, $allocation['invoice_id'], $allocation['amount'], $actorId);
            }
            AuditLog::log('payment_requested', 'payments', $paymentId, null, [
                'case_id' => $caseId, 'amount' => $amount, 'allocations' => $checked['allocations'],
            ], $actorId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        try {
            $ctx = $this->caseContext($caseId);
            (new Notification())->notifyPaymentRequested($caseId, $ctx['patient_name'] ?? '', $amount, $paymentId, $actorId);
        } catch (Exception $e) {
            error_log('Payment request notification failed: ' . $e->getMessage());
        }
        $created = $this->getById($paymentId);
        return ['success' => true, 'payment_id' => $paymentId, 'payment_number' => $created['payment_number']];
    }

    /**
     * Save (or update) the doctor's one unsent receipt draft for a case.
     * A draft has no allocations, reserves no balance, sends no notification,
     * and is invisible to the accountant's pending-payments queue.
     */
    public function saveDraft(array $data, $actorId, $draftId = 0)
    {
        $caseId = (int)($data['case_id'] ?? 0);
        $amount = round((float)($data['amount'] ?? 0), 2);
        $actorId = (int)$actorId;
        $draftId = (int)$draftId;
        if ($caseId <= 0 || $amount <= 0 || $actorId <= 0) {
            return ['success' => false, 'message' => 'بڕی پارە دەبێت گەورەتر بێت لە سفر'];
        }
        $balance = $this->getCaseBalance($caseId);
        if ($amount > (float)$balance['unrequested'] + 0.009) {
            return ['success' => false, 'message' => 'بڕی قەبزەکە لە قەرزی بەردەست زیاترە'];
        }

        $this->db->beginTransaction();
        try {
            $discountService = new PaymentDiscount();
            $discountService->lockCase($caseId);
            $available = $this->getCaseBalance($caseId)['unrequested'];
            $discountFields = PaymentDiscount::fields($data, $available);
            if ($discountFields['discount_amount'] > 0) { $discountService->requireEligible($caseId); }
            $draft = null;
            if ($draftId > 0) {
                $draft = $this->db->fetch(
                    "SELECT * FROM payments
                     WHERE id=:id AND case_id=:case_id AND received_by=:actor AND status='draft'
                     FOR UPDATE",
                    ['id' => $draftId, 'case_id' => $caseId, 'actor' => $actorId]
                );
            }
            if (!$draft) {
                $draft = $this->db->fetch(
                    "SELECT * FROM payments
                     WHERE case_id=:case_id AND received_by=:actor AND status='draft'
                     ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    ['case_id' => $caseId, 'actor' => $actorId]
                );
            }

            $changes = $discountFields + [
                'amount' => $amount,
                'payment_type' => 'cash',
                'payment_method' => $this->normalizeMethod($data['payment_method'] ?? 'cash'),
                'notes' => trim((string)($data['notes'] ?? '')),
                'payment_date' => date('Y-m-d H:i:s'),
            ];
            // A dialog submission stays identifiable when it updates an existing draft.
            if (!empty($data['idempotency_key'])) { $changes['idempotency_key'] = trim((string)$data['idempotency_key']); }
            if ($draft) {
                $paymentId = (int)$draft['id'];
                $this->db->update('payments', $changes, 'id=:id AND status=:status', [
                    'id' => $paymentId, 'status' => 'draft'
                ]);
                AuditLog::logChange('payment_draft_updated', 'payments', $paymentId, $draft, $changes, $actorId);
            } else {
                $data = array_merge($data, $changes, [
                    'case_id' => $caseId,
                    'received_by' => $actorId,
                    'idempotency_key' => trim((string)($data['idempotency_key'] ?? ''))
                        ?: ('payment-draft-' . $caseId . '-' . $actorId . '-' . bin2hex(random_bytes(12))),
                ]);
                $paymentId = $this->insertPayment($data, 'draft', $actorId);
                AuditLog::log('payment_draft_created', 'payments', $paymentId, null, [
                    'case_id' => $caseId, 'amount' => $amount,
                ], $actorId);
            }
            $this->db->commit();
            $saved = $this->getById($paymentId);
            return [
                'success' => true,
                'payment_id' => $paymentId,
                'payment_number' => $saved['payment_number'] ?? '',
                'updated' => (bool)$draft,
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** One dialog submission: save the entered amount and send it for approval. */
    public function saveAndSendDraft(array $data, $actorId, $draftId = 0)
    {
        $caseId = (int)($data['case_id'] ?? 0);
        $actorId = (int)$actorId;
        $owner = $this->db->fetch("SELECT c.id FROM cases c JOIN patient_folders pf ON pf.id=c.folder_id
            JOIN users u ON u.id=pf.doctor_id WHERE c.id=? AND c.status='open'
            AND u.id=? AND u.role='doctor' AND u.is_active=1", [$caseId, $actorId]);
        if (!$owner) { return ['success' => false, 'message' => 'ڕێگەت پێنەدراوە داواکاری پارەدان بنێریت']; }
        $existing = $this->findIdempotent($data);
        if ($existing) {
            if ((int)$existing['case_id'] !== $caseId || (int)$existing['received_by'] !== $actorId) {
                return ['success' => false, 'message' => 'داواکاری پارەدان نادروستە'];
            }
            if ($existing['status'] !== 'draft') {
                return ['success' => true, 'duplicate' => true, 'payment_id' => (int)$existing['id']];
            }
            $draftId = (int)$existing['id'];
        }
        // A stale draft form must not create a second receipt after sending.
        if ((int)$draftId > 0) {
            $prior = $this->db->fetch('SELECT * FROM payments WHERE id=? AND case_id=? AND received_by=?', [(int)$draftId, $caseId, $actorId]);
            if (!$prior) { return ['success' => false, 'message' => 'قەبزی ڕەشنووس نەدۆزرایەوە']; }
            if ($prior['status'] !== 'draft') {
                return ['success' => true, 'duplicate' => true, 'payment_id' => (int)$prior['id']];
            }
        }
        $saved = $this->saveDraft($data, $actorId, $draftId);
        if (empty($saved['success'])) { return $saved; }
        // A send failure preserves the entered receipt as a draft for correction.
        return $this->sendDraft((int)$saved['payment_id'], $actorId);
    }

    /** Promote a doctor-owned draft receipt to the accountant payment queue. */
    public function sendDraft($paymentId, $actorId)
    {
        $paymentId = (int)$paymentId;
        $actorId = (int)$actorId;
        $preview = $this->db->fetch('SELECT * FROM payments WHERE id=:id', ['id' => $paymentId]);
        if (!$preview || (int)$preview['received_by'] !== $actorId) {
            return ['success' => false, 'message' => 'قەبزی ڕەشنووس نەدۆزرایەوە'];
        }
        if ($preview['status'] === 'pending') {
            return ['success' => true, 'duplicate' => true, 'payment_id' => $paymentId];
        }
        if ($preview['status'] !== 'draft') {
            return ['success' => false, 'message' => 'تەنها قەبزی ڕەشنووس دەنێردرێت'];
        }
        if ((float)($preview['discount_amount'] ?? 0) > 0) {
            return $this->sendDiscountDraft($preview, $actorId);
        }
        $checked = $this->prepareAllocations(
            (int)$preview['case_id'], (float)$preview['amount'], [], true, false
        );
        if (empty($checked['success'])) {
            return ['success' => false, 'message' => 'بڕی قەبزەکە لە قەرزی بەردەست زیاترە یان قەرزی پەسەندکراو نییە'];
        }

        $this->db->beginTransaction();
        try {
            $invoiceIds = array_column($checked['allocations'], 'invoice_id');
            if (!$this->ledger->lockInvoicesForAllocation((int)$preview['case_id'], $invoiceIds)) {
                throw new RuntimeException('وەسڵە قەرزەکان چیتر بەردەست نین');
            }
            $draft = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $paymentId]);
            if (!$draft || (int)$draft['received_by'] !== $actorId) {
                throw new RuntimeException('قەبزی ڕەشنووس نەدۆزرایەوە');
            }
            if ($draft['status'] === 'pending') {
                $this->db->commit();
                return ['success' => true, 'duplicate' => true, 'payment_id' => $paymentId];
            }
            if ($draft['status'] !== 'draft') {
                throw new RuntimeException('تەنها قەبزی ڕەشنووس دەنێردرێت');
            }
            $checked = $this->ledger->validateAllocations(
                (int)$draft['case_id'], (float)$draft['amount'], $checked['allocations'], true, false
            );
            if (empty($checked['success'])) {
                throw new RuntimeException('قەرزی نەخۆش گۆڕاوە؛ بڕی قەبزەکە نوێ بکەرەوە');
            }
            $this->db->update('payments', [
                'status' => 'pending',
                'payment_date' => date('Y-m-d H:i:s'),
            ], 'id=:id AND status=:draft', ['id' => $paymentId, 'draft' => 'draft']);
            foreach ($checked['allocations'] as $allocation) {
                $this->ledger->upsertAllocation(
                    $paymentId, (int)$allocation['invoice_id'], (float)$allocation['amount'], $actorId
                );
            }
            AuditLog::log('payment_draft_sent', 'payments', $paymentId,
                ['status' => 'draft'],
                ['status' => 'pending', 'allocations' => $checked['allocations']],
                $actorId
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }

        try {
            $ctx = $this->caseContext((int)$preview['case_id']);
            (new Notification())->notifyPaymentRequested(
                (int)$preview['case_id'], $ctx['patient_name'] ?? '',
                (float)$preview['amount'], $paymentId, $actorId
            );
        } catch (Throwable $e) {
            error_log('Payment draft notification failed: ' . $e->getMessage());
        }
        return ['success' => true, 'payment_id' => $paymentId];
    }

    private function sendDiscountDraft(array $preview, $actorId)
    {
        $discounts = new PaymentDiscount();
        $this->db->beginTransaction();
        try {
            $discounts->lockCase((int)$preview['case_id']);
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=? FOR UPDATE', [(int)$preview['id']]);
            $owner = $this->db->fetch("SELECT c.id FROM cases c JOIN patient_folders pf ON pf.id=c.folder_id
                JOIN users u ON u.id=pf.doctor_id WHERE c.id=? AND u.id=? AND u.role='doctor' AND u.is_active=1",
                [(int)$preview['case_id'], (int)$actorId]);
            if (!$payment || !$owner || (int)$payment['received_by'] !== (int)$actorId) {
                throw new RuntimeException('ڕێگەت پێنەدراوە ئەم داواکارییە بنێریت');
            }
            if ($payment['status'] === 'pending') {
                $this->db->commit();
                return ['success' => true, 'duplicate' => true, 'payment_id' => (int)$payment['id']];
            }
            if ($payment['status'] !== 'draft') { throw new RuntimeException('تەنها ڕەشنووس دەنێردرێت'); }
            $allocations = $discounts->reserve($payment, $actorId);
            $this->db->update('payments', ['status' => 'pending', 'payment_date' => date('Y-m-d H:i:s')], 'id=?', [(int)$payment['id']]);
            AuditLog::log('payment_discount_requested', 'payments', (int)$payment['id'], ['status' => 'draft'], [
                'status' => 'pending', 'amount' => $payment['amount'], 'discount_amount' => $payment['discount_amount'],
                'discount_reason' => $payment['discount_reason'], 'allocations' => $allocations,
            ], $actorId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        try {
            $ctx = $this->caseContext((int)$payment['case_id']);
            (new Notification())->notifyPaymentRequested((int)$payment['case_id'], $ctx['patient_name'] ?? '',
                (float)$payment['amount'], (int)$payment['id'], $actorId, (float)$payment['discount_amount']);
        } catch (Throwable $e) { error_log('Discount request notification failed: ' . $e->getMessage()); }
        return ['success' => true, 'payment_id' => (int)$payment['id']];
    }

    private function approveDiscountPayment(array $preview, $actorId, $finalAmount, $note, $fromAdvance)
    {
        $discounts = new PaymentDiscount();
        $this->db->beginTransaction();
        try {
            $discounts->lockCase((int)$preview['case_id']);
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=? FOR UPDATE', [(int)$preview['id']]);
            if (!$payment || $payment['status'] !== 'pending') { throw new RuntimeException('ئەم داواکارییە پێشتر بڕیاری لەسەر دراوە'); }
            $before = $payment;
            $payment = $discounts->apply($payment, $actorId, $finalAmount);
            if ($fromAdvance) {
                require_once __DIR__ . '/PatientAdvance.php';
                (new PatientAdvance())->fundPayment((int)$payment['id'], $actorId);
            }
            $this->db->update('payments', ['status' => 'approved', 'approved_by' => (int)$actorId], 'id=?', [(int)$payment['id']]);
            AuditLog::logChange('payment_discount_approved', 'payments', (int)$payment['id'], $before,
                ['status' => 'approved', 'amount' => $payment['amount'], 'discount_amount' => $payment['discount_amount'], 'note' => trim((string)$note)], $actorId);
            $this->caseManager->updateCaseTotals((int)$payment['case_id']);
            CashboxLedger::captureCase((int)$payment['case_id'], $actorId, 'وەرگرتنی پارە لەگەڵ داشکاندن');
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->syncInvoiceStatuses((int)$payment['case_id'], $actorId);
        $this->notifyPaymentDecision($payment, true, $actorId);
        return ['success' => true, 'payment_id' => (int)$payment['id']];
    }

    /** Accountants can agree and book a discount and collection in one step. */
    private function directDiscountPayment(array $data, $actorId)
    {
        $discounts = new PaymentDiscount();
        $caseId = (int)($data['case_id'] ?? 0);
        $this->db->beginTransaction();
        try {
            $discounts->requireAccountant($actorId);
            $discounts->lockCase($caseId);
            $discounts->requireEligible($caseId);
            $existing = $this->findIdempotent($data);
            if ($existing) {
                if ((int)$existing['case_id'] !== $caseId || (int)$existing['received_by'] !== $actorId) {
                    throw new RuntimeException('داواکاری پارەدان نادروستە');
                }
                $this->db->commit();
                return ['success' => true, 'duplicate' => true, 'payment_id' => (int)$existing['id']];
            }
            if (!empty($data['installment_id']) || ($data['payment_type'] ?? 'cash') === 'installment') {
                throw new RuntimeException('داشکاندن لەسەر پلانی قیست پێویستی بە ڕێکخستنەوەی پلانە');
            }
            $available = $this->getCaseBalance($caseId)['unrequested'];
            $fields = PaymentDiscount::fields($data, $available);
            $data = array_merge($data, $fields, ['received_by' => $actorId]);
            $paymentId = $this->insertPayment($data, 'pending', $actorId);
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=?', [$paymentId]);
            $discounts->reserve($payment, $actorId);
            $payment = $discounts->apply($payment, $actorId);
            if (!empty($data['from_advance'])) {
                require_once __DIR__ . '/PatientAdvance.php';
                (new PatientAdvance())->fundPayment($paymentId, $actorId);
            }
            $this->db->update('payments', ['status' => 'approved', 'approved_by' => $actorId], 'id=?', [$paymentId]);
            AuditLog::log('payment_discount_received', 'payments', $paymentId, null,
                ['case_id' => $caseId, 'amount' => $payment['amount'], 'discount_amount' => $payment['discount_amount'], 'discount_reason' => $payment['discount_reason']], $actorId);
            $this->caseManager->updateCaseTotals($caseId);
            CashboxLedger::captureCase($caseId, $actorId, 'وەرگرتنی پارە لەگەڵ داشکاندن');
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->syncInvoiceStatuses($caseId, $actorId);
        try {
            $ctx = $this->caseContext($caseId);
            (new Notification())->notifyPaymentReceived($caseId, $ctx['patient_name'] ?? '', (float)$payment['amount'], $actorId);
        } catch (Throwable $e) { error_log('Discount collection notification failed: ' . $e->getMessage()); }
        return ['success' => true, 'payment_id' => $paymentId, 'payment_number' => $payment['payment_number']];
    }

    /**
     * Doctor/accountant edit of a still-pending request's amount. Reservations
     * are re-derived for the new amount (same FIFO rule as createRequest) and
     * atomically swapped in; the request itself must still be pending.
     */
    public function updatePendingAmount($paymentId, $newAmount, $actorId, $note = '')
    {
        $paymentId = (int)$paymentId;
        $newAmount = round((float)$newAmount, 2);
        $actorId = (int)$actorId;
        if ($newAmount <= 0) {
            throw new InvalidArgumentException('بڕ نادروستە');
        }
        $preview = $this->db->fetch('SELECT * FROM payments WHERE id=:id', ['id' => $paymentId]);
        if (!$preview) {
            throw new RuntimeException('تەنها داواکاری چاوەڕوان دەگۆڕدرێت');
        }
        if ((float)($preview['discount_amount'] ?? 0) > 0) {
            throw new RuntimeException('قەبزی داشکاندن لە کاتی پەسەندکردندا ڕاست دەکرێتەوە؛ یان ڕەتی بکەرەوە و دووبارە بینێرە');
        }
        $caseId = (int)$preview['case_id'];
        // Re-derive the FIFO allocation for the new amount, excluding this
        // request's own current reservations (mirrors createRequest's :198).
        $checked = $this->prepareAllocations($caseId, $newAmount, [], true, true, $paymentId);
        if (empty($checked['success'])) {
            throw new RuntimeException('بڕ لە ماوە زیاترە');
        }

        $this->db->beginTransaction();
        try {
            $invoiceIds = array_column($checked['allocations'], 'invoice_id');
            if (!$this->ledger->lockInvoicesForAllocation($caseId, $invoiceIds)) {
                throw new Exception('One or more invoices are no longer available.');
            }
            $row = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $paymentId]);
            if (!$row || $row['status'] !== 'pending') {
                throw new RuntimeException('تەنها داواکاری چاوەڕوان دەگۆڕدرێت');
            }
            // allowSent=true — matches the validateAllocations() call right
            // below (and Payment::createRequest()'s own original reservation
            // for this same payment); see getCaseBalance()'s docblock.
            $balance = $this->getCaseBalance((int)$row['case_id'], $paymentId, true);
            if ($newAmount - 0.009 > (float)$balance['remaining']) {
                throw new RuntimeException('بڕ لە ماوە زیاترە');
            }
            // Re-validate the locked candidate allocations (mirrors createRequest's :207).
            $reChecked = $this->ledger->validateAllocations($caseId, $newAmount, $checked['allocations'], true, true, $paymentId);
            if (empty($reChecked['success'])) {
                throw new RuntimeException('بڕ لە ماوە زیاترە');
            }
            $replaced = $this->ledger->replacePaymentAllocations(
                $paymentId, $caseId, $newAmount, $reChecked['allocations'], $actorId, true, true
            );
            if (empty($replaced['success'])) {
                throw new RuntimeException('بڕ لە ماوە زیاترە');
            }
            $oldAmount = (float)$row['amount'];
            $this->db->update('payments', ['amount' => $newAmount], 'id=? AND status=?', [$paymentId, 'pending']);
            AuditLog::log('payment_amount_updated', 'payments', $paymentId,
                ['amount' => $oldAmount],
                ['amount' => $newAmount, 'note' => trim((string)$note)],
                $actorId
            );
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Replace a pending request's explicit invoice allocations. This is used
     * by the doctor's inline "received" boxes: each box keeps its own target,
     * including while its invoice is still a draft, instead of being silently
     * redistributed across other visit receipts.
     */
    public function updatePendingAllocations($paymentId, array $allocations, $actorId, $note = '', $allowDraft = false)
    {
        $paymentId = (int)$paymentId;
        $actorId = (int)$actorId;
        $normalized = [];
        foreach ($allocations as $invoiceId => $amount) {
            $invoiceId = (int)$invoiceId;
            $amount = round((float)$amount, 2);
            if ($invoiceId > 0 && $amount > 0.009) {
                $normalized[$invoiceId] = $amount;
            }
        }
        $newAmount = round(array_sum($normalized), 2);
        if ($newAmount <= 0.009) {
            throw new InvalidArgumentException('بڕ نادروستە');
        }

        $preview = $this->db->fetch('SELECT * FROM payments WHERE id=:id', ['id' => $paymentId]);
        if (!$preview) {
            throw new RuntimeException('تەنها داواکاری چاوەڕوان دەگۆڕدرێت');
        }
        if ((float)($preview['discount_amount'] ?? 0) > 0) {
            throw new RuntimeException('دابەشکردنی قەبزی داشکاندن بە تەنها ناگۆڕدرێت؛ ڕەتی بکەرەوە و دووبارە بینێرە');
        }
        $caseId = (int)$preview['case_id'];
        $checked = $this->prepareAllocations($caseId, $newAmount, $normalized, true, true, $paymentId, $allowDraft);
        if (empty($checked['success'])) {
            throw new RuntimeException($checked['message'] ?? 'بڕ لە ماوە زیاترە');
        }

        $this->db->beginTransaction();
        try {
            if (!$this->ledger->lockInvoicesForAllocation($caseId, array_keys($normalized))) {
                throw new RuntimeException('وەسڵەکە چیتر بەردەست نییە');
            }
            $row = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $paymentId]);
            if (!$row || $row['status'] !== 'pending') {
                throw new RuntimeException('تەنها داواکاری چاوەڕوان دەگۆڕدرێت');
            }
            $replaced = $this->ledger->replacePaymentAllocations(
                $paymentId, $caseId, $newAmount, $checked['allocations'], $actorId,
                true, true, $allowDraft
            );
            if (empty($replaced['success'])) {
                throw new RuntimeException($replaced['message'] ?? 'بڕ لە ماوە زیاترە');
            }
            $this->db->update('payments', ['amount' => $newAmount], 'id=:id AND status=:pending', [
                'id' => $paymentId, 'pending' => 'pending'
            ]);
            AuditLog::log('payment_allocations_updated', 'payments', $paymentId,
                ['amount' => (float)$row['amount']],
                ['amount' => $newAmount, 'allocations' => $replaced['allocations'], 'note' => trim((string)$note)],
                $actorId
            );
            $this->db->commit();
            return ['success' => true, 'payment_id' => $paymentId, 'amount' => $newAmount];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Move one invoice's pending cash declaration into an installment-plan
     * proposal. Other invoice allocations on the shared request are preserved;
     * if this was its last allocation the old request is quietly superseded.
     */
    public function removePendingInvoiceAllocation($paymentId, $invoiceId, $actorId, $note = '')
    {
        $paymentId = (int)$paymentId;
        $invoiceId = (int)$invoiceId;
        $actorId = (int)$actorId;
        $this->db->beginTransaction();
        try {
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $paymentId]);
            if (!$payment || $payment['status'] !== 'pending') {
                throw new RuntimeException('داواکاری پارەدان چیتر چاوەڕوان نییە');
            }
            if ((float)($payment['discount_amount'] ?? 0) > 0) {
                throw new RuntimeException('پارە و داشکاندن پێکەوە ڕەت بکەرەوە؛ بەشێکی قەبزەکە لاناچێت');
            }
            $allocation = $this->db->fetch(
                'SELECT * FROM payment_allocations WHERE payment_id=:payment AND invoice_id=:invoice FOR UPDATE',
                ['payment' => $paymentId, 'invoice' => $invoiceId]
            );
            if (!$allocation) {
                $this->db->commit();
                return ['success' => true, 'amount' => 0, 'superseded' => false];
            }
            $moved = (float)$allocation['allocated_amount'];
            $this->db->delete('payment_allocations', 'id=:id', ['id' => (int)$allocation['id']]);
            $remaining = (float)($this->db->fetch(
                'SELECT COALESCE(SUM(allocated_amount),0) AS amount FROM payment_allocations WHERE payment_id=:payment',
                ['payment' => $paymentId]
            )['amount'] ?? 0);
            if ($remaining <= 0.009) {
                $this->db->update('payments', [
                    'status' => 'rejected',
                    'rejected_by' => $actorId,
                    'notes' => trim((string)$payment['notes'] . "\n" . trim((string)$note)),
                ], 'id=:id AND status=:pending', ['id' => $paymentId, 'pending' => 'pending']);
            } else {
                $this->db->update('payments', ['amount' => $remaining], 'id=:id AND status=:pending', [
                    'id' => $paymentId, 'pending' => 'pending'
                ]);
            }
            AuditLog::log('payment_request_allocation_removed', 'payments', $paymentId,
                ['amount' => (float)$payment['amount'], 'invoice_id' => $invoiceId],
                ['amount' => $remaining, 'moved_amount' => $moved, 'note' => trim((string)$note)],
                $actorId
            );
            $this->db->commit();
            return ['success' => true, 'amount' => $moved, 'superseded' => $remaining <= 0.009];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** Save the optional final amount and approve in the same transaction. */
    public function approve($id, $approvedBy, $finalAmount = null, $note = '', $fromAdvance = false)
    {
        $id = (int)$id;
        $approvedBy = (int)$approvedBy;
        $paymentPreview = $this->db->fetch('SELECT * FROM payments WHERE id=:id', ['id' => $id]);
        if (!$paymentPreview) return ['success' => false, 'message' => 'Payment not found.'];
        if ((float)($paymentPreview['discount_amount'] ?? 0) > 0) {
            return $this->approveDiscountPayment($paymentPreview, $approvedBy, $finalAmount, $note, $fromAdvance);
        }
        $requestedPreview = [];
        foreach ($this->db->fetchAll('SELECT invoice_id,allocated_amount FROM payment_allocations WHERE payment_id=:id', ['id' => $id]) as $allocation) {
            $requestedPreview[(int)$allocation['invoice_id']] = (float)$allocation['allocated_amount'];
        }
        $this->db->beginTransaction();
        try {
            if (!$this->ledger->lockInvoicesForAllocation((int)$paymentPreview['case_id'], array_keys($requestedPreview))) {
                throw new Exception('One or more invoices are no longer available.');
            }
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $id]);
            if (!$payment || $payment['status'] !== 'pending') throw new Exception('This payment request has already been decided.');
            $requested = [];
            foreach ($this->db->fetchAll('SELECT invoice_id,allocated_amount FROM payment_allocations WHERE payment_id=:id FOR UPDATE', ['id' => $id]) as $allocation) {
                $requested[(int)$allocation['invoice_id']] = (float)$allocation['allocated_amount'];
            }
            $previewIds = array_keys($requestedPreview);
            $liveIds = array_keys($requested);
            sort($previewIds, SORT_NUMERIC);
            sort($liveIds, SORT_NUMERIC);
            if ($previewIds !== $liveIds) throw new Exception('Payment allocations changed; reload and try again.');
            if ($finalAmount !== null) {
                $finalAmount = round((float)$finalAmount, 2);
                if (!is_finite($finalAmount) || $finalAmount <= 0) {
                    throw new InvalidArgumentException('بڕ نادروستە');
                }
                if (abs($finalAmount - (float)$payment['amount']) > 0.009) {
                    // The nested savepoint stays inside this approval transaction:
                    // a failed approval also rolls back the amount and reservations.
                    $this->updatePendingAmount($id, $finalAmount, $approvedBy, $note);
                    $payment = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $id]);
                    $requested = [];
                    foreach ($this->db->fetchAll('SELECT invoice_id,allocated_amount FROM payment_allocations WHERE payment_id=:id FOR UPDATE', ['id' => $id]) as $allocation) {
                        $requested[(int)$allocation['invoice_id']] = (float)$allocation['allocated_amount'];
                    }
                }
            }
            $hasDraftAllocation = (bool)$this->db->fetch(
                "SELECT ci.id FROM payment_allocations pa JOIN case_invoices ci ON ci.id=pa.invoice_id
                 WHERE pa.payment_id=:payment AND ci.accounting_status IN ('draft','returned') LIMIT 1",
                ['payment' => $id]
            );
            $checked = $this->prepareAllocations(
                (int)$payment['case_id'], (float)$payment['amount'], $requested,
                false, true, $id, $hasDraftAllocation
            );
            if (empty($checked['success'])) throw new Exception($checked['message']);
            $this->approveAllocatedSentInvoices($checked['allocations'], $approvedBy);
            $replaced = $this->ledger->replacePaymentAllocations(
                $id, (int)$payment['case_id'], (float)$payment['amount'], $checked['allocations'],
                $approvedBy, false, false, $hasDraftAllocation
            );
            if (empty($replaced['success'])) throw new Exception($replaced['message']);
            if ($fromAdvance) {
                require_once __DIR__ . '/PatientAdvance.php';
                (new PatientAdvance())->fundPayment($id, $approvedBy);
                $payment = $this->db->fetch('SELECT * FROM payments WHERE id=?', [$id]);
            }
            $this->db->update('payments', ['status' => 'approved', 'approved_by' => $approvedBy],
                'id=:id AND status=:pending', ['id' => $id, 'pending' => 'pending']);
            AuditLog::log('payment_approved', 'payments', $id, ['status' => 'pending'], [
                'status' => 'approved', 'amount' => (float)$payment['amount'],
                'allocations' => $replaced['allocations'], 'note' => trim((string)$note),
            ], $approvedBy);

            if (($payment['payment_type'] ?? '') === 'installment') {
                $plan = $this->installmentManager->getPlanByCase((int)$payment['case_id']);
                if ($plan) {
                    $this->installmentManager->applyFlexibleToPlan(
                        (int)$plan['id'], (float)$payment['amount'], $payment['payment_method'],
                        $payment['notes'], $approvedBy, $id
                    );
                }
            }
            $this->caseManager->updateCaseTotals((int)$payment['case_id']);
            if (!$hasDraftAllocation) {
                $this->supersedeStalePendingRequests((int)$payment['case_id'], $approvedBy, $id);
            }
            CashboxLedger::captureCase((int)$payment['case_id'], $approvedBy, 'پەسەندکردنی قەبزی نەقد');
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->syncInvoiceStatuses((int)$payment['case_id'], $approvedBy);
        $this->notifyPaymentDecision($payment, true, $approvedBy);
        return ['success' => true, 'payment_id' => $id];
    }

    public function reject($id, $reason = '', $rejectedBy = null)
    {
        $id = (int)$id;
        $actor = (int)($rejectedBy ?? ($_SESSION['user_id'] ?? 0));
        $this->db->beginTransaction();
        try {
            $reason = trim((string)$reason);
            if ($reason === '') throw new InvalidArgumentException('هۆکاری ڕەتکردنەوە پێویستە');
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $id]);
            if (!$payment || $payment['status'] !== 'pending') throw new Exception('Only a pending payment request can be rejected.');
            $changes = ['status' => 'rejected', 'rejected_by' => $actor, 'notes' => $reason];
            $this->db->update('payments', $changes, 'id=:id', ['id' => $id]);
            AuditLog::logChange('payment_rejected', 'payments', $id, $payment, $changes, $actor);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->notifyPaymentDecision($payment, false, $actor);
        return ['success' => true];
    }

    /**
     * Accountant/admin collection: creates one approved receipt atomically.
     *
     * $data['allow_draft']: lets this allocate against an invoice the doctor
     * has not even sent yet — a walk-up patient at the front desk who wants
     * to pay, while the doctor is mid-treatment and hasn't clicked "send".
     * Mirrors createRequest()'s own allow_draft (the doctor-side equivalent),
     * just skipping the request→approve round trip since the accountant is
     * the one taking the cash directly. approveAllocatedSentInvoices() is
     * what turns that draft into a real approved invoice the moment money is
     * allocated to it — same finality a normal send+approve would reach, on
     * the accountant's authority instead of the doctor's.
     */
    public function directPayment($data, $userId)
    {
        if (isset($data['discount_value']) && (string)$data['discount_value'] !== '0' && (string)$data['discount_value'] !== '') {
            try {
                $value = PaymentDiscount::number($data['discount_value']);
                if ($value > 0) { return $this->directDiscountPayment($data, (int)$userId); }
            } catch (Throwable $e) { return ['success' => false, 'message' => $e->getMessage()]; }
        }
        $caseId = (int)($data['case_id'] ?? 0);
        $amount = round((float)($data['amount'] ?? 0), 2);
        $userId = (int)$userId;
        if ($caseId <= 0 || $amount <= 0) {
            return ['success' => false, 'message' => 'Payment amount must be greater than zero.'];
        }
        $existing = $this->findIdempotent($data);
        if ($existing) {
            return ['success' => true, 'duplicate' => true, 'payment_id' => (int)$existing['id'], 'payment_number' => $existing['payment_number']];
        }
        $allowDraft = !empty($data['allow_draft']);
        $checked = $this->prepareAllocations($caseId, $amount, $this->requestedAllocations($data), false, true, null, $allowDraft);
        if (empty($checked['success'])) return $checked;

        $this->db->beginTransaction();
        try {
            $invoiceIds = array_column($checked['allocations'], 'invoice_id');
            if (!$this->ledger->lockInvoicesForAllocation($caseId, $invoiceIds)) {
                throw new Exception('One or more invoices are no longer available.');
            }
            $this->approveAllocatedSentInvoices($checked['allocations'], $userId);
            $checked = $this->ledger->validateAllocations($caseId, $amount, $checked['allocations'], false, false);
            if (empty($checked['success'])) throw new Exception($checked['message']);
            $paymentId = $this->insertPayment($data, 'approved', $userId);
            foreach ($checked['allocations'] as $allocation) {
                $allocationId = $this->ledger->upsertAllocation($paymentId, $allocation['invoice_id'], $allocation['amount'], $userId);
                AuditLog::log('payment_allocated', 'payment_allocations', $allocationId, null, [
                    'payment_id' => $paymentId, 'invoice_id' => $allocation['invoice_id'],
                    'allocated_amount' => $allocation['amount'],
                ], $userId);
            }
            if (!empty($data['from_advance'])) {
                require_once __DIR__ . '/PatientAdvance.php';
                (new PatientAdvance())->fundPayment($paymentId, $userId);
            }
            AuditLog::log('payment_received', 'payments', $paymentId, null, [
                'case_id' => $caseId, 'amount' => $amount,
                'method' => $this->normalizeMethod($data['payment_method'] ?? 'cash', !empty($data['from_advance'])),
                'allocations' => $checked['allocations'],
            ], $userId);
            $this->caseManager->updateCaseTotals($caseId);
            $this->supersedeStalePendingRequests($caseId, $userId, $paymentId);
            CashboxLedger::captureCase($caseId, $userId, (string)($data['cashbox_reason'] ?? 'وەرگرتنی نەقد'));
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->syncInvoiceStatuses($caseId, $userId);
        try {
            $ctx = $this->caseContext($caseId);
            (new Notification())->notifyPaymentReceived($caseId, $ctx['patient_name'] ?? '', $amount, $userId);
        } catch (Exception $e) {
            error_log('Payment notification failed: ' . $e->getMessage());
        }
        $created = $this->getById($paymentId);
        return ['success' => true, 'payment_id' => $paymentId, 'payment_number' => $created['payment_number']];
    }

    private function syncInvoiceStatuses($caseId, $actorId)
    {
        try {
            require_once __DIR__ . '/CaseInvoice.php';
            (new CaseInvoice())->syncStatuses((int)$caseId, (int)$actorId);
        } catch (Exception $e) {
            error_log('Invoice status sync failed: ' . $e->getMessage());
        }
    }

    private function notifyPaymentDecision($payment, $approved, $actorId)
    {
        try {
            $ctx = $this->caseContext((int)$payment['case_id']);
            (new Notification())->notifyPaymentResult(
                (bool)$approved, (int)$payment['case_id'], $ctx['patient_name'] ?? '',
                (float)$payment['amount'], (int)($ctx['doctor_id'] ?? 0), (int)$actorId
            );
        } catch (Exception $e) {
            error_log('Payment decision notification failed: ' . $e->getMessage());
        }
    }

    public function getById($id)
    {
        $payment = $this->db->fetch(
            "SELECT p.*,c.case_number,pt.full_name AS patient_name,pt.patient_code,
                    r.full_name AS received_by_name,a.full_name AS approved_by_name
             FROM payments p LEFT JOIN cases c ON c.id=p.case_id
             LEFT JOIN patient_folders pf ON pf.id=c.folder_id
             LEFT JOIN patients pt ON pt.id=pf.patient_id
             LEFT JOIN users r ON r.id=p.received_by LEFT JOIN users a ON a.id=p.approved_by
             WHERE p.id=:id",
            ['id' => (int)$id]
        );
        if ($payment) {
            $payment['discount_allocations'] = $this->db->fetchAll(
                'SELECT da.*,ci.invoice_no FROM payment_discount_allocations da
                 JOIN case_invoices ci ON ci.id=da.invoice_id WHERE da.payment_id=? ORDER BY ci.work_date,ci.invoice_seq',
                [(int)$id]
            );
            $payment['allocations'] = $this->db->fetchAll(
                "SELECT pa.*,ci.invoice_no,ci.work_date FROM payment_allocations pa
                 JOIN case_invoices ci ON ci.id=pa.invoice_id
                 WHERE pa.payment_id=:id ORDER BY ci.work_date,ci.invoice_seq",
                ['id' => (int)$id]
            );
        }
        return $payment;
    }

    public function getByCase($caseId)
    {
        return $this->ledger->getPayments((int)$caseId);
    }

    public function getPending()
    {
        return $this->db->fetchAll(
            "SELECT p.*,c.case_number,pt.full_name AS patient_name,u.full_name AS doctor_name
             FROM payments p LEFT JOIN cases c ON c.id=p.case_id
             LEFT JOIN patient_folders pf ON pf.id=c.folder_id
             LEFT JOIN patients pt ON pt.id=pf.patient_id LEFT JOIN users u ON u.id=pf.doctor_id
             WHERE p.status='pending' ORDER BY p.payment_date DESC,p.id DESC"
        );
    }

    private function filterSql(array $filters, array &$params, $alias = 'p')
    {
        $where = '1=1';
        foreach (['status', 'payment_type'] as $field) {
            if (!empty($filters[$field])) {
                $where .= " AND {$alias}.{$field}=:{$field}";
                $params[$field] = $filters[$field];
            }
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND DATE({$alias}.payment_date)>=:date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND DATE({$alias}.payment_date)<=:date_to";
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['doctor_id'])) {
            $where .= ' AND pf.doctor_id=:doctor_id';
            $params['doctor_id'] = (int)$filters['doctor_id'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (pt.full_name LIKE :search1 OR c.case_number LIKE :search2 OR pt.phone LIKE :search3)';
            $term = '%' . $filters['search'] . '%';
            $params['search1'] = $params['search2'] = $params['search3'] = $term;
        }
        return $where;
    }

    public function getAll($page = 1, $limit = ITEMS_PER_PAGE, $filters = [])
    {
        $params = [];
        $where = $this->filterSql($filters, $params);
        $limit = max(1, (int)$limit);
        $offset = max(0, ((int)$page - 1) * $limit);
        return $this->db->fetchAll(
            "SELECT p.*,c.case_number,pt.full_name AS patient_name,pt.patient_code,u.full_name AS doctor_name
             FROM payments p LEFT JOIN cases c ON c.id=p.case_id
             LEFT JOIN patient_folders pf ON pf.id=c.folder_id
             LEFT JOIN patients pt ON pt.id=pf.patient_id LEFT JOIN users u ON u.id=pf.doctor_id
             WHERE {$where} ORDER BY p.payment_date DESC,p.id DESC LIMIT {$limit} OFFSET {$offset}", $params
        );
    }

    public function getCount($filters = [])
    {
        $params = [];
        $where = $this->filterSql($filters, $params);
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM payments p LEFT JOIN cases c ON c.id=p.case_id
             LEFT JOIN patient_folders pf ON pf.id=c.folder_id
             LEFT JOIN patients pt ON pt.id=pf.patient_id WHERE {$where}", $params
        );
        return (int)($row['total'] ?? 0);
    }

    public function getDailyTotal($date = null)
    {
        $row = $this->db->fetch("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE DATE(payment_date)=:date AND status='approved'", [
            'date' => $date ?: date('Y-m-d')
        ]);
        return $row['total'] ?? 0;
    }

    public function getMonthlyTotal($year = null, $month = null)
    {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(amount),0) AS total FROM payments
             WHERE YEAR(payment_date)=:year AND MONTH(payment_date)=:month AND status='approved'",
            ['year' => $year ?: date('Y'), 'month' => $month ?: date('m')]
        );
        return $row['total'] ?? 0;
    }

    public function getStatistics($filters = [])
    {
        $params = [];
        $where = "status='approved'";
        if (!empty($filters['date_from'])) {
            $where .= ' AND DATE(payment_date)>=:date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND DATE(payment_date)<=:date_to';
            $params['date_to'] = $filters['date_to'];
        }
        $stats = ['by_type' => [], 'by_method' => [], 'total' => 0, 'count' => 0];
        foreach ($this->db->fetchAll("SELECT payment_type,SUM(amount) total,COUNT(*) count FROM payments WHERE {$where} GROUP BY payment_type", $params) as $row) {
            $stats['by_type'][$row['payment_type']] = ['total' => $row['total'], 'count' => $row['count']];
        }
        foreach ($this->db->fetchAll("SELECT payment_method,SUM(amount) total,COUNT(*) count FROM payments WHERE {$where} GROUP BY payment_method", $params) as $row) {
            $stats['by_method'][$row['payment_method']] = ['total' => $row['total'], 'count' => $row['count']];
        }
        $total = $this->db->fetch("SELECT COALESCE(SUM(amount),0) total,COUNT(*) count FROM payments WHERE {$where}", $params);
        $stats['total'] = $total['total'];
        $stats['count'] = $total['count'];
        return $stats;
    }

    /**
     * Correct an APPROVED receipt's amount in place. It stays approved: same
     * receipt number, same payment_date, same approver — the Approval Center's
     * پەسەندکراو list keeps it where it was, and the cash reports keep it on
     * its original day, just with the corrected figure.
     *
     * The receipt's invoice allocations must keep summing to its amount, and
     * they are rebalanced WITHOUT reassigning which invoices it paid:
     *   - smaller: the newest allocation shrinks first, so older invoices stay
     *     settled;
     *   - larger: the invoices it already sits on are topped up to their open
     *     balance first (oldest first), and only what is still left goes FIFO
     *     onto the case's other approved invoices.
     * Every figure is re-validated by AccountingLedger::validateAllocations()
     * against live balances with this receipt excluded, under a lock on the
     * case's invoices — the same capacity rule approve() applies.
     *
     * An instalment collection (installment_payments row) follows the new
     * amount and its plan is recomputed. Receipts owned by another document
     * are refused here, because changing them alone would contradict that
     * document: an arch-work receipt, a plan's down payment, and one child of a
     * distributed debt collection (the physical cash total lives on its master).
     *
     * Gated on Settings::allowEditApprovedInvoice() — the one admin switch for
     * editing approved records. Callers own the role check (accountant/admin).
     */
    public function editApproved($paymentId, $newAmount, $actorId, $note = '')
    {
        require_once __DIR__ . '/Settings.php';
        if (!Settings::allowEditApprovedInvoice()) {
            return ['success' => false, 'message' => 'دەستکاریکردنی تۆماری پەسەندکراو ناچالاکە — لە ڕێکخستنەکان چالاکی بکە'];
        }
        return $this->correctApproved($paymentId, $newAmount, $actorId, $note);
    }

    /** Explicit admin invoice editor; keep the normal accountant setting intact. */
    public function editApprovedFromInvoice($invoiceId, $paymentId, $newAmount, $actorId, $note = '')
    {
        $actor = $this->db->fetch('SELECT role,is_active FROM users WHERE id=?', [(int)$actorId]);
        $linked = $this->db->fetch(
            'SELECT pa.payment_id FROM payment_allocations pa JOIN case_invoices ci ON ci.id=pa.invoice_id
             JOIN payments p ON p.id=pa.payment_id AND p.case_id=ci.case_id
             WHERE pa.invoice_id=? AND pa.payment_id=?', [(int)$invoiceId, (int)$paymentId]
        );
        if (!$actor || !$actor['is_active'] || $actor['role'] !== 'admin' || !$linked) {
            return ['success' => false, 'message' => 'دەستکاریکردن تەنها بۆ ئەدمینە و قەبزەکە دەبێت هی ئەم وەسڵە بێت'];
        }
        return $this->correctApproved($paymentId, $newAmount, $actorId, $note);
    }

    private function correctApproved($paymentId, $newAmount, $actorId, $note)
    {
        $paymentId = (int)$paymentId;
        $actorId = (int)$actorId;
        $newAmount = round((float)$newAmount, 2);
        if ($newAmount <= 0.009) {
            return ['success' => false, 'message' => 'بڕ نادروستە'];
        }
        $head = $this->db->fetch('SELECT case_id FROM payments WHERE id=:id', ['id' => $paymentId]);
        if (!$head) {
            return ['success' => false, 'message' => 'قەبز نەدۆزرایەوە'];
        }
        $caseId = (int)$head['case_id'];
        $planId = 0;

        $this->db->beginTransaction();
        try {
            // Every invoice of the case, in id order (the order every other
            // allocation writer locks in), so growth can spill onto any of
            // them without racing another receipt.
            $this->db->fetchAll("SELECT id FROM case_invoices WHERE case_id = :cid ORDER BY id FOR UPDATE", ['cid' => $caseId]);
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $paymentId]);
            if (!$payment || $payment['status'] !== 'approved') {
                throw new RuntimeException('تەنها قەبزی پەسەندکراو بەم شێوەیە دەگۆڕدرێت');
            }
            $oldAmount = round((float)$payment['amount'], 2);
            if (abs($newAmount - $oldAmount) < 0.005) {
                $this->db->rollback();
                return ['success' => true, 'payment_id' => $paymentId, 'unchanged' => true];
            }

            $blocked = $this->approvedEditBlockReason($payment);
            if ($blocked !== null) {
                throw new RuntimeException($blocked);
            }
            $link = $this->db->tableExists('installment_payments')
                ? $this->db->fetch('SELECT * FROM installment_payments WHERE payment_id = :pid FOR UPDATE', ['pid' => $paymentId])
                : null;

            $oldAllocs = $this->db->fetchAll(
                "SELECT pa.invoice_id, pa.allocated_amount
                 FROM payment_allocations pa JOIN case_invoices ci ON ci.id = pa.invoice_id
                 WHERE pa.payment_id = :pid
                 ORDER BY ci.work_date, ci.invoice_seq, ci.id",
                ['pid' => $paymentId]
            );
            $newAllocs = [];
            if ($oldAllocs) {
                $target = [];
                foreach ($oldAllocs as $a) {
                    $target[(int)$a['invoice_id']] = round((float)$a['allocated_amount'], 2);
                }
                $allocated = round(array_sum($target), 2);

                if ($newAmount < $allocated) {
                    $cut = round($allocated - $newAmount, 2);
                    foreach (array_reverse(array_keys($target)) as $iid) {
                        if ($cut <= 0.009) break;
                        $take = min($cut, $target[$iid]);
                        $target[$iid] = round($target[$iid] - $take, 2);
                        $cut = round($cut - $take, 2);
                        if ($target[$iid] <= 0.009) unset($target[$iid]);
                    }
                } elseif ($newAmount > $allocated) {
                    $extra = round($newAmount - $allocated, 2);
                    foreach (array_keys($target) as $iid) {
                        if ($extra <= 0.009) break;
                        // This invoice's open balance with THIS receipt left
                        // out: what the receipt may hold on it at most.
                        $probe = $this->ledger->autoAllocate($caseId, 1e12, [$iid], false, false, $paymentId);
                        $room = round((float)($probe['allocations'][0]['amount'] ?? 0) - $target[$iid], 2);
                        if ($room > 0.009) {
                            $add = min($room, $extra);
                            $target[$iid] = round($target[$iid] + $add, 2);
                            $extra = round($extra - $add, 2);
                        }
                    }
                    if ($extra > 0.009) {
                        $others = array_map('intval', array_column($this->db->fetchAll(
                            "SELECT id FROM case_invoices WHERE case_id = :cid AND accounting_status = 'approved'",
                            ['cid' => $caseId]
                        ), 'id'));
                        $others = array_values(array_diff($others, array_keys($target)));
                        if ($others) {
                            $spill = $this->ledger->autoAllocate($caseId, $extra, $others, false, false, $paymentId);
                            foreach ($spill['allocations'] as $s) {
                                $target[(int)$s['invoice_id']] = round(($target[(int)$s['invoice_id']] ?? 0) + (float)$s['amount'], 2);
                                $extra = round($extra - (float)$s['amount'], 2);
                            }
                        }
                    }
                    if ($extra > 0.009) {
                        throw new RuntimeException('بڕەکە لە قەرزی ماوەی وەسڵەکانی ئەم کەیسە زیاترە');
                    }
                }

                $replaced = $this->ledger->replacePaymentAllocations(
                    $paymentId, $caseId, $newAmount, $target, $actorId, false, false
                );
                if (empty($replaced['success'])) {
                    throw new RuntimeException('بڕەکە لە قەرزی ماوەی وەسڵەکانی ئەم کەیسە زیاترە');
                }
                $newAllocs = $replaced['allocations'];
            }

            $this->db->update('payments', ['amount' => $newAmount], 'id=:id AND status=:approved', [
                'id' => $paymentId, 'approved' => 'approved',
            ]);
            if ($link) {
                $planId = (int)$link['plan_id'];
                $this->db->update('installment_payments', ['amount' => $newAmount], 'id = :id', ['id' => (int)$link['id']]);
            }
            AuditLog::log('payment_approved_edited', 'payments', $paymentId,
                ['amount' => $oldAmount, 'allocations' => $oldAllocs],
                ['amount' => $newAmount, 'allocations' => $newAllocs, 'note' => trim((string)$note)],
                $actorId
            );
            $this->caseManager->updateCaseTotals($caseId);
            CashboxLedger::capture('payment', $paymentId, $actorId, 'دەستکاری قەبزی نەقد');
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof PDOException) {
                error_log('Payment::editApproved failed: ' . $e->getMessage());
                return ['success' => false, 'message' => 'هەڵەیەک ڕوویدا، دووبارە هەوڵبدەرەوە'];
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
        if ($planId > 0) {
            try { $this->installmentManager->recomputePlan($planId); }
            catch (Throwable $e) { error_log('Plan recompute after receipt edit failed: ' . $e->getMessage()); }
        }
        $this->syncInvoiceStatuses($caseId, $actorId);
        return ['success' => true, 'payment_id' => $paymentId, 'amount' => $newAmount];
    }

    /**
     * Why an approved receipt may NOT be corrected on its own, or null when it
     * may. Shared by editApproved() and the Approval Center's drawer, so the
     * «تەعدیل» button is never offered on a receipt the server would refuse:
     *   - an arch-work receipt belongs to its وەسڵی کارە ئەنجامدراوەکان;
     *   - one child of a distributed debt collection shares its cash total
     *     with the master record;
     *   - a plan's down payment receipt is part of the plan's own terms.
     */
    public function approvedEditBlockReason(array $payment)
    {
        if ((float)($payment['discount_amount'] ?? 0) > 0) {
            return 'ئەم قەبزە داشکاندنی تێدایە؛ بۆ ڕاستکردنەوە قەبزەکە بگەڕێنەوە و دووبارە تۆماری بکە';
        }
        if (($payment['payment_method'] ?? '') === 'advance') {
            return 'ئەم قەبزە لە ئامانەتەوە دراوە — بۆ گۆڕین بیسڕەوە (ئامانەتەکە دەگەڕێتەوە) و دووبارە بیگوازەوە';
        }
        $notes = (string)($payment['notes'] ?? '');
        if (strpos($notes, 'وەسڵی کارە ئەنجامدراوەکان ') !== false) {
            return 'ئەم قەبزە بە وەسڵی کارە ئەنجامدراوەکان بەستراوە — لەوێ دەستکاری بکە';
        }
        if (strpos($notes, 'پارەدانی قەرز (دابەشکراو)') !== false) {
            return 'ئەم قەبزە بەشێکە لە پارەدانێکی قەرزی دابەشکراو — بە تەنها ناگۆڕدرێت';
        }
        if (($payment['payment_type'] ?? '') === 'installment' && strpos($notes, 'پارەی پێشەکی - پلانی قیست') !== false) {
            $linked = $this->db->tableExists('installment_payments')
                && $this->db->fetch('SELECT id FROM installment_payments WHERE payment_id = :pid', ['pid' => (int)$payment['id']]);
            if (!$linked) {
                return 'ئەم قەبزە پارەی پێشەکی پلانی قیستە — لە پلانەکەدا دەستکاری بکە';
            }
        }
        return null;
    }

    /** Reversal keeps the receipt and allocations; refunded rows do not count. */
    public function refund($id, $reason = '', $actorId = null)
    {
        $id = (int)$id;
        $actor = (int)($actorId ?? ($_SESSION['user_id'] ?? 0));
        $paymentPreview = $this->db->fetch('SELECT * FROM payments WHERE id=:id', ['id' => $id]);
        if (!$paymentPreview) return ['success' => false, 'message' => 'Payment not found.'];
        $invoiceIds = array_column($this->db->fetchAll(
            'SELECT invoice_id FROM payment_allocations WHERE payment_id=:id ORDER BY invoice_id',
            ['id' => $id]
        ), 'invoice_id');
        $this->db->beginTransaction();
        try {
            if ((float)($paymentPreview['discount_amount'] ?? 0) > 0) {
                (new PaymentDiscount())->lockCase((int)$paymentPreview['case_id']);
            }
            if ($invoiceIds && !$this->ledger->lockInvoicesForAllocation((int)$paymentPreview['case_id'], $invoiceIds)) {
                throw new Exception('One or more invoices are no longer available.');
            }
            $payment = $this->db->fetch('SELECT * FROM payments WHERE id=:id FOR UPDATE', ['id' => $id]);
            if ($payment['status'] !== 'approved') throw new Exception('Only an approved payment can be reversed.');
            (new PaymentDiscount())->reverse($payment, $actor);
            $changes = [
                'status' => 'refunded', 'reversed_by' => $actor,
                'reversed_at' => date('Y-m-d H:i:s'), 'reversal_reason' => trim((string)$reason),
            ];
            $this->db->update('payments', $changes, 'id=:id', ['id' => $id]);
            AuditLog::logChange('payment_reversed', 'payments', $id, $payment, $changes, $actor);
            CashboxLedger::capture('payment', $id, $actor, 'گەڕاندنەوەی نەقد: ' . trim((string)$reason));
            // Paid from ئامانەت: the money goes back onto the patient's name
            // (and back into قاسەی ئامانەت), not out of the clinic.
            if (($payment['payment_method'] ?? '') === 'advance') {
                require_once __DIR__ . '/PatientAdvance.php';
                (new PatientAdvance())->releaseForPayment($id, $actor);
                $installments = $this->db->fetchAll('SELECT id,plan_id FROM installment_payments WHERE payment_id=? FOR UPDATE', [$id]);
                foreach ($installments as $installment) {
                    $this->db->delete('installment_payments', 'id=?', [(int)$installment['id']]);
                    $this->installmentManager->recomputePlan((int)$installment['plan_id']);
                }
            }
            $this->caseManager->updateCaseTotals((int)$payment['case_id']);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->syncInvoiceStatuses((int)$payment['case_id'], $actor);
        return ['success' => true];
    }
}
