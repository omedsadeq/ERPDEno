<?php
/**
 * Transaction-derived patient/case ledger.
 *
 * Treatment invoices can carry their chair-side received amount as part of the
 * invoice document. Later standalone payment receipts remain independent and
 * are linked through payment_allocations.
 * Cached totals on cases/folders remain compatibility fields for older pages;
 * financial decisions in the new workflow must use this class.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class AccountingLedger
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Convert pre-allocation payment history to deterministic FIFO allocations.
     * This is idempotent and intentionally only fills a payment's unallocated
     * remainder. Old payment.invoice_id was an origin hint, not an allocation,
     * so approved legacy money follows the exact FIFO rule previously displayed.
     */
    public function ensureLegacyAllocations($caseId = null)
    {
        if (!$this->db->tableExists('payment_allocations')) {
            return 0;
        }

        $params = [];
        $where = "p.status IN ('approved','pending')";
        if ($caseId !== null) {
            $where .= ' AND p.case_id = :case_id';
            $params['case_id'] = (int)$caseId;
        }
        $payments = $this->db->fetchAll(
            "SELECT p.*,
                    COALESCE((SELECT SUM(pa.allocated_amount) FROM payment_allocations pa WHERE pa.payment_id=p.id),0) AS allocated
             FROM payments p
             WHERE {$where}
             HAVING allocated < p.amount - 0.009
             ORDER BY p.case_id, p.payment_date, p.id",
            $params
        );
        if (!$payments) {
            return 0;
        }

        $made = 0;
        $this->db->beginTransaction();
        try {
            foreach ($payments as $payment) {
                $amount = round((float)$payment['amount'] - (float)$payment['allocated'], 2);
                if ($amount <= 0.009 || empty($payment['case_id'])) {
                    continue;
                }

                $selected = [];
                // Pending requests were displayed against their invoice_id.
                // Approved legacy money was displayed FIFO and must stay FIFO.
                if ($payment['status'] === 'pending' && !empty($payment['invoice_id'])) {
                    $selected[] = (int)$payment['invoice_id'];
                }
                $preview = $this->autoAllocate(
                    (int)$payment['case_id'],
                    $amount,
                    $selected,
                    $payment['status'] === 'pending',
                    $payment['status'] === 'pending',
                    (int)$payment['id']
                );
                foreach ($preview['allocations'] as $allocation) {
                    $this->upsertAllocation(
                        (int)$payment['id'],
                        (int)$allocation['invoice_id'],
                        (float)$allocation['amount'],
                        $payment['received_by'] ?: null
                    );
                    $made++;
                }
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
        return $made;
    }

    /** Invoice rows with calculated accounting and payment state. */
    public function getInvoices($caseId, $includeDrafts = true)
    {
        $this->ensureLegacyAllocations((int)$caseId);
        $draftSql = $includeDrafts ? '' : " AND ci.accounting_status <> 'draft'";
        $rows = $this->db->fetchAll(
            "SELECT ci.*,
                    d.full_name AS doctor_name,
                    s.full_name AS sent_by_name,
                    cr.full_name AS created_by_name,
                    CASE WHEN ci.approved_auto = 1 THEN 'پەسەندکردنی خۆکار' ELSE a.full_name END AS approved_by_name,
                    (SELECT COUNT(*) FROM case_treatments ct WHERE ct.invoice_id=ci.id AND ct.status<>'cancelled') AS treatment_count,
                    COALESCE(SUM(CASE WHEN p.status='approved' THEN pa.allocated_amount ELSE 0 END),0)
                      + CASE WHEN ci.accounting_status='approved' THEN LEAST(
                            GREATEST(0,ci.total_amount-ci.discount_amount),
                            GREATEST(0,COALESCE(ci.draft_received_amount,0))
                              + GREATEST(0,COALESCE(ci.prior_paid_amount,0))
                              + GREATEST(0,COALESCE(ci.advance_paid_amount,0))
                        ) ELSE 0 END AS paid_amount,
                    COALESCE(SUM(CASE WHEN p.status='approved' AND p.payment_method='advance' THEN pa.allocated_amount ELSE 0 END),0) AS advance_payment_amount,
                    COALESCE(SUM(CASE WHEN p.status='approved' AND pa.created_at > ci.approved_at THEN pa.allocated_amount ELSE 0 END),0) AS later_paid_amount,
                    GROUP_CONCAT(DISTINCT CASE WHEN p.status='approved' AND pa.created_at > ci.approved_at THEN p.payment_number END SEPARATOR '، ') AS later_payment_numbers,
                    " . self::laterDiscountSql() . " AS later_discount_amount,
                    " . self::laterDiscountSql('invoice_mode_before') . " AS receipt_discount_mode,
                    " . self::laterDiscountSql('invoice_value_before') . " AS receipt_discount_value,
                    COALESCE(SUM(CASE WHEN p.status='pending' THEN pa.allocated_amount ELSE 0 END),0) AS pending_amount,
                    " . self::pendingDiscountSql() . " AS pending_discount_amount
             FROM case_invoices ci
             LEFT JOIN users d ON d.id=ci.doctor_id
             LEFT JOIN users s ON s.id=ci.sent_by
             LEFT JOIN users cr ON cr.id=ci.created_by
             LEFT JOIN users a ON a.id=ci.approved_by
             LEFT JOIN payment_allocations pa ON pa.invoice_id=ci.id
             LEFT JOIN payments p ON p.id=pa.payment_id
             WHERE ci.case_id=:case_id {$draftSql}
             GROUP BY ci.id
             ORDER BY ci.work_date, ci.invoice_seq",
            ['case_id' => (int)$caseId]
        );
        foreach ($rows as &$row) {
            $row = $this->decorateInvoice($row);
        }
        unset($row);
        return $rows;
    }

    public function getInvoice($invoiceId)
    {
        $base = $this->db->fetch('SELECT case_id FROM case_invoices WHERE id=:id', ['id' => (int)$invoiceId]);
        if (!$base) {
            return null;
        }
        foreach ($this->getInvoices((int)$base['case_id']) as $invoice) {
            if ((int)$invoice['id'] === (int)$invoiceId) {
                return $invoice;
            }
        }
        return null;
    }

    private function decorateInvoice(array $row)
    {
        $gross = max(0, (float)$row['total_amount']);
        $discount = min($gross, max(0, (float)$row['discount_amount']));
        $net = max(0, $gross - $discount);
        $declared = min($net, max(0, (float)($row['draft_received_amount'] ?? 0)));
        // دراوی پێشوو — money taken before the system existed (historical
        // cases). Settles the invoice like chair cash, but is kept apart from
        // declared_received_amount because it is not cash collected here.
        $prior = min(max(0, $net - $declared), max(0, (float)($row['prior_paid_amount'] ?? 0)));
        // ئامانەت — a counter receipt taken before this case existed and later
        // put on this invoice. Like دراوی پێشوو it settles the bill without
        // being cash collected here, so it gets its own figure rather than
        // hiding inside وەرگیراو.
        $advance = min(max(0, $net - $declared - $prior), max(0, (float)($row['advance_paid_amount'] ?? 0)));
        $paid = min($net, max(0, (float)($row['paid_amount'] ?? 0)));
        $remaining = max(0, $net - $paid);

        if (($row['accounting_status'] ?? 'draft') !== 'approved') {
            $paymentStatus = 'not_due';
        } elseif ($remaining <= 0.009) {
            $paymentStatus = 'paid';
        } elseif ($paid > 0.009) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'unpaid';
        }

        $row['net_amount'] = $net;
        $row['declared_received_amount'] = $declared;
        $row['prior_paid_amount'] = $prior;
        $row['advance_paid_amount'] = $advance;
        $row['paid_amount'] = $paid;
        // A source breakdown of paid_amount, never an additional payment.
        $row['advance_received_amount'] = ($row['accounting_status'] ?? '') === 'approved'
            ? min(max(0, $paid - $prior), $advance + max(0, (float)($row['advance_payment_amount'] ?? 0)))
            : 0.0;
        $row['remaining_amount'] = $remaining;
        $row['payment_status'] = $paymentStatus;

        // پسوولە وەک خۆی — the receipt as it was issued. Money put on it up to
        // approval (chair cash, دراوی پێشوو, ئامانەت) belongs to it; a قەبز
        // allocated afterwards, and the collection discount such a قەبز adds to
        // discount_amount (PaymentDiscount), settle the patient's debt above but
        // never rewrite the printed receipt (cases/receipt.php, invoice pane).
        $laterPaid = max(0, (float)($row['later_paid_amount'] ?? 0));
        $laterDiscount = min($discount, max(0, (float)($row['later_discount_amount'] ?? 0)));
        $receiptDiscount = $discount - $laterDiscount;
        $receiptNet = max(0, $gross - $receiptDiscount);
        $receiptPaid = min($receiptNet, max(0, $paid - $laterPaid));
        $row['later_paid_amount'] = $laterPaid;
        $row['later_discount_amount'] = $laterDiscount;
        $row['receipt_discount_amount'] = $receiptDiscount;
        $row['receipt_net_amount'] = $receiptNet;
        $row['receipt_paid_amount'] = $receiptPaid;
        $row['receipt_remaining_amount'] = max(0, $receiptNet - $receiptPaid);
        if ($laterDiscount <= 0.009) {
            // No collection discount: the receipt's own discount terms stand.
            $row['receipt_discount_mode'] = $row['discount_mode'] ?? 'amount';
            $row['receipt_discount_value'] = $row['discount_value'] ?? $discount;
        }
        // Compatibility aliases used by the shared invoice pane while older
        // UI fragments are being retired. They now represent real allocations.
        $row['fifo_covered'] = $paid;
        $row['fifo_remaining'] = $remaining;
        return $row;
    }

    /** Standard patient/case formula: approved invoice net minus valid allocations. */
    public function getSummary($caseId)
    {
        $invoices = $this->getInvoices((int)$caseId);
        $summary = [
            'total_invoiced' => 0.0,
            'total_discount' => 0.0,
            'approved' => 0.0,
            'paid' => 0.0,
            'balance' => 0.0,
            'pending_invoices' => 0,
            'draft_invoices' => 0,
            'unallocated_payments' => 0.0,
        ];
        foreach ($invoices as $invoice) {
            $state = $invoice['accounting_status'];
            if ($state === 'draft') {
                $summary['draft_invoices']++;
                continue;
            }
            if ($state === 'cancelled') {
                continue;
            }
            $summary['total_invoiced'] += (float)$invoice['total_amount'];
            $summary['total_discount'] += (float)$invoice['discount_amount'];
            if ($state === 'approved') {
                $summary['approved'] += (float)$invoice['net_amount'];
                $summary['paid'] += min((float)$invoice['net_amount'], (float)$invoice['paid_amount']);
            } elseif ($state === 'sent') {
                $summary['pending_invoices']++;
            }
        }
        $summary['balance'] = max(0, $summary['approved'] - $summary['paid']);

        $unallocated = $this->db->fetch(
            "SELECT COALESCE(SUM(GREATEST(0,p.amount-COALESCE(x.allocated,0))),0) AS amount
             FROM payments p
             LEFT JOIN (
                SELECT pa.payment_id, SUM(pa.allocated_amount) AS allocated
                FROM payment_allocations pa
                JOIN case_invoices ci ON ci.id=pa.invoice_id
                WHERE ci.case_id=:case_alloc GROUP BY pa.payment_id
             ) x ON x.payment_id=p.id
             WHERE p.case_id=:case_pay AND p.status='approved'",
            ['case_alloc' => (int)$caseId, 'case_pay' => (int)$caseId]
        );
        $summary['unallocated_payments'] = (float)($unallocated['amount'] ?? 0);
        return $summary;
    }

    /**
     * Build an oldest-first allocation preview. If selected invoice ids are
     * supplied, only those invoices participate.
     */
    public function autoAllocate($caseId, $amount, array $selectedInvoiceIds = [], $reservePending = false, $allowSent = false, $excludePaymentId = null, $allowDraft = false)
    {
        $caseId = (int)$caseId;
        $amount = round(max(0, (float)$amount), 2);
        $selectedInvoiceIds = array_values(array_unique(array_filter(array_map('intval', $selectedInvoiceIds))));
        $params = ['case_id' => $caseId];
        $states = $allowDraft
            ? "('approved','sent','draft','returned')"
            : ($allowSent ? "('approved','sent')" : "('approved')");
        $selectedSql = '';
        if ($selectedInvoiceIds) {
            $selectedSql = ' AND ci.id IN (' . implode(',', $selectedInvoiceIds) . ')';
        }
        $statusSql = $reservePending ? "p.status IN ('approved','pending')" : "p.status='approved'";
        $excludeSql = '';
        if ($excludePaymentId) {
            $excludeSql = ' AND p.id<>:exclude_payment';
            $params['exclude_payment'] = (int)$excludePaymentId;
        }
        $rows = $this->db->fetchAll(
            "SELECT ci.id, ci.invoice_no, ci.work_date, ci.invoice_seq,
                    GREATEST(0,ci.total_amount-ci.discount_amount) AS net_amount,
                    COALESCE(SUM(CASE WHEN {$statusSql} {$excludeSql} THEN pa.allocated_amount ELSE 0 END),0)
                      + CASE WHEN ci.accounting_status IN ('sent','approved') THEN LEAST(
                            GREATEST(0,ci.total_amount-ci.discount_amount),
                            GREATEST(0,COALESCE(ci.draft_received_amount,0))
                        ) ELSE 0 END
                      + GREATEST(0,COALESCE(ci.prior_paid_amount,0))
                      + GREATEST(0,COALESCE(ci.advance_paid_amount,0))
                      + " . self::pendingDiscountSql($excludePaymentId) . " AS reserved
             FROM case_invoices ci
             LEFT JOIN payment_allocations pa ON pa.invoice_id=ci.id
             LEFT JOIN payments p ON p.id=pa.payment_id
             WHERE ci.case_id=:case_id AND ci.accounting_status IN {$states} {$selectedSql}
             GROUP BY ci.id
             ORDER BY ci.work_date, ci.invoice_seq",
            $params
        );

        $left = $amount;
        $allocations = [];
        foreach ($rows as $row) {
            $remaining = max(0, (float)$row['net_amount'] - (float)$row['reserved']);
            $apply = round(min($remaining, $left), 2);
            if ($apply > 0.009) {
                $allocations[] = [
                    'invoice_id' => (int)$row['id'],
                    'invoice_no' => $row['invoice_no'],
                    'amount' => $apply,
                    'invoice_remaining' => $remaining,
                ];
                $left = round($left - $apply, 2);
            }
            if ($left <= 0.009) {
                break;
            }
        }
        return [
            'success' => $amount > 0.009 && $left <= 0.009,
            'allocations' => $allocations,
            'allocated' => round($amount - $left, 2),
            'unallocated' => max(0, $left),
        ];
    }

    /** Validate manually entered invoice=>amount rows against live balances. */
    public function validateAllocations($caseId, $paymentAmount, array $allocations, $reservePending = false, $allowSent = false, $excludePaymentId = null, $allowDraft = false)
    {
        $normalized = [];
        foreach ($allocations as $key => $value) {
            if (is_array($value)) {
                $invoiceId = (int)($value['invoice_id'] ?? 0);
                $amount = (float)($value['amount'] ?? $value['allocated_amount'] ?? 0);
            } else {
                $invoiceId = (int)$key;
                $amount = (float)$value;
            }
            if ($invoiceId > 0 && $amount > 0.009) {
                $normalized[$invoiceId] = round(($normalized[$invoiceId] ?? 0) + $amount, 2);
            }
        }
        $paymentAmount = round((float)$paymentAmount, 2);
        if (!$normalized || abs(array_sum($normalized) - $paymentAmount) > 0.009) {
            return ['success' => false, 'message' => 'Allocated amounts must equal the payment total.'];
        }

        $preview = $this->allocationCapacity((int)$caseId, array_keys($normalized), $reservePending, $allowSent, $excludePaymentId, $allowDraft);
        foreach ($normalized as $invoiceId => $amount) {
            if (!isset($preview[$invoiceId])) {
                return ['success' => false, 'message' => 'An invoice is unavailable or belongs to another case.'];
            }
            if ($amount > $preview[$invoiceId]['remaining'] + 0.009) {
                return ['success' => false, 'message' => 'Allocation exceeds the remaining balance of ' . $preview[$invoiceId]['invoice_no'] . '.'];
            }
        }

        $result = [];
        foreach ($normalized as $invoiceId => $amount) {
            $result[] = ['invoice_id' => (int)$invoiceId, 'amount' => (float)$amount];
        }
        return ['success' => true, 'allocations' => $result];
    }

    /**
     * Serialize money operations for the selected invoices. Call only inside a
     * database transaction and always before the final balance validation.
     */
    public function lockInvoicesForAllocation($caseId, array $invoiceIds)
    {
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        sort($invoiceIds, SORT_NUMERIC);
        if (!$invoiceIds) {
            return false;
        }
        $rows = $this->db->fetchAll(
            "SELECT id FROM case_invoices
             WHERE case_id=:case_id AND id IN (" . implode(',', $invoiceIds) . ")
             ORDER BY id FOR UPDATE",
            ['case_id' => (int)$caseId]
        );
        return count($rows) === count($invoiceIds);
    }

    private function allocationCapacity($caseId, array $invoiceIds, $reservePending, $allowSent, $excludePaymentId, $allowDraft = false)
    {
        if (!$invoiceIds) {
            return [];
        }
        $params = ['case_id' => (int)$caseId];
        $states = $allowDraft
            ? "('approved','sent','draft','returned')"
            : ($allowSent ? "('approved','sent')" : "('approved')");
        $statusSql = $reservePending ? "p.status IN ('approved','pending')" : "p.status='approved'";
        $excludeSql = '';
        if ($excludePaymentId) {
            $excludeSql = ' AND p.id<>:exclude_payment';
            $params['exclude_payment'] = (int)$excludePaymentId;
        }
        $rows = $this->db->fetchAll(
            "SELECT ci.id, ci.invoice_no,
                    GREATEST(0,ci.total_amount-ci.discount_amount) AS net_amount,
                    COALESCE(SUM(CASE WHEN {$statusSql} {$excludeSql} THEN pa.allocated_amount ELSE 0 END),0)
                      + CASE WHEN ci.accounting_status IN ('sent','approved') THEN LEAST(
                            GREATEST(0,ci.total_amount-ci.discount_amount),
                            GREATEST(0,COALESCE(ci.draft_received_amount,0))
                        ) ELSE 0 END
                      + GREATEST(0,COALESCE(ci.prior_paid_amount,0))
                      + GREATEST(0,COALESCE(ci.advance_paid_amount,0))
                      + " . self::pendingDiscountSql($excludePaymentId) . " AS reserved
             FROM case_invoices ci
             LEFT JOIN payment_allocations pa ON pa.invoice_id=ci.id
             LEFT JOIN payments p ON p.id=pa.payment_id
             WHERE ci.case_id=:case_id AND ci.accounting_status IN {$states}
               AND ci.id IN (" . implode(',', array_map('intval', $invoiceIds)) . ")
             GROUP BY ci.id",
            $params
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['id']] = [
                'invoice_no' => $row['invoice_no'],
                'remaining' => max(0, (float)$row['net_amount'] - (float)$row['reserved']),
            ];
        }
        return $result;
    }

    /** Discounts awaiting a decision reserve capacity but never count as paid. */
    /**
     * Collection discounts of approved قەبزەکان on invoice ci: their total
     * (default), or — given a column — the invoice's discount terms from
     * before the first of them (invoice_mode_before / invoice_value_before).
     */
    public static function laterDiscountSql($beforeColumn = null)
    {
        $from = "FROM payment_discount_allocations da JOIN payments dp ON dp.id=da.payment_id
            WHERE da.invoice_id=ci.id AND dp.status='approved'";
        return $beforeColumn === null
            ? "COALESCE((SELECT SUM(da.amount) {$from}),0)"
            : "(SELECT da.{$beforeColumn} {$from} ORDER BY da.id LIMIT 1)";
    }

    public static function pendingDiscountSql($excludePaymentId = null)
    {
        $exclude = $excludePaymentId ? ' AND dp.id<>' . (int)$excludePaymentId : '';
        return "COALESCE((SELECT SUM(da.amount) FROM payment_discount_allocations da
            JOIN payments dp ON dp.id=da.payment_id
            WHERE da.invoice_id=ci.id AND dp.status='pending'{$exclude}),0)";
    }

    public function upsertAllocation($paymentId, $invoiceId, $amount, $actorId = null)
    {
        $existing = $this->db->fetch(
            'SELECT * FROM payment_allocations WHERE payment_id=:payment AND invoice_id=:invoice',
            ['payment' => (int)$paymentId, 'invoice' => (int)$invoiceId]
        );
        if ($existing) {
            $newAmount = round((float)$existing['allocated_amount'] + (float)$amount, 2);
            $this->db->update('payment_allocations', [
                'allocated_amount' => $newAmount,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=:id', ['id' => (int)$existing['id']]);
            return (int)$existing['id'];
        }
        return (int)$this->db->insert('payment_allocations', [
            'payment_id' => (int)$paymentId,
            'invoice_id' => (int)$invoiceId,
            'allocated_amount' => round((float)$amount, 2),
            'created_by' => $actorId ? (int)$actorId : null,
        ]);
    }

    public function replacePaymentAllocations($paymentId, $caseId, $paymentAmount, array $allocations, $actorId, $reservePending, $allowSent, $allowDraft = false)
    {
        $checked = $this->validateAllocations(
            (int)$caseId,
            (float)$paymentAmount,
            $allocations,
            (bool)$reservePending,
            (bool)$allowSent,
            (int)$paymentId,
            (bool)$allowDraft
        );
        if (empty($checked['success'])) {
            return $checked;
        }
        $this->db->delete('payment_allocations', 'payment_id=:payment', ['payment' => (int)$paymentId]);
        foreach ($checked['allocations'] as $allocation) {
            $this->upsertAllocation((int)$paymentId, $allocation['invoice_id'], $allocation['amount'], $actorId);
        }
        return $checked;
    }

    public function getPayments($caseId)
    {
        $this->ensureLegacyAllocations((int)$caseId);
        $payments = $this->db->fetchAll(
            "SELECT p.*, r.full_name AS received_by_name, a.full_name AS approved_by_name,
                    COALESCE(SUM(pa.allocated_amount),0) AS allocated_amount,
                    COUNT(pa.id) AS allocation_count
             FROM payments p
             LEFT JOIN users r ON r.id=p.received_by
             LEFT JOIN users a ON a.id=p.approved_by
             LEFT JOIN payment_allocations pa ON pa.payment_id=p.id
             WHERE p.case_id=:case_id
               AND COALESCE(p.notes,'') NOT LIKE '%Repaired%inline amount restored%'
             GROUP BY p.id
             ORDER BY p.payment_date DESC,p.id DESC",
            ['case_id' => (int)$caseId]
        );
        foreach ($payments as &$payment) {
            $payment['allocations'] = $this->db->fetchAll(
                "SELECT pa.*,ci.invoice_no,ci.work_date
                 FROM payment_allocations pa
                 JOIN case_invoices ci ON ci.id=pa.invoice_id
                 WHERE pa.payment_id=:payment ORDER BY ci.work_date,ci.invoice_seq",
                ['payment' => (int)$payment['id']]
            );
            $payment['unallocated_amount'] = max(0, (float)$payment['amount'] - (float)$payment['allocated_amount']);
            $payment['discount_allocations'] = $this->db->fetchAll(
                'SELECT da.*,ci.invoice_no FROM payment_discount_allocations da
                 JOIN case_invoices ci ON ci.id=da.invoice_id WHERE da.payment_id=? ORDER BY ci.work_date,ci.invoice_seq',
                [(int)$payment['id']]
            );
        }
        unset($payment);
        return $payments;
    }

    /** Activity for invoice, payment, allocation and case records in one timeline. */
    public function getActivity($caseId, $limit = 100)
    {
        $invoiceIds = array_column($this->db->fetchAll('SELECT id FROM case_invoices WHERE case_id=:id', ['id' => (int)$caseId]), 'id');
        $paymentIds = array_column($this->db->fetchAll('SELECT id FROM payments WHERE case_id=:id', ['id' => (int)$caseId]), 'id');
        $treatmentIds = array_column($this->db->fetchAll('SELECT id FROM case_treatments WHERE case_id=:id', ['id' => (int)$caseId]), 'id');
        $allocationIds = [];
        if ($paymentIds) {
            $allocationIds = array_column($this->db->fetchAll(
                'SELECT id FROM payment_allocations WHERE payment_id IN (' . implode(',', array_map('intval', $paymentIds)) . ')'
            ), 'id');
        }
        $parts = ["(al.table_name='cases' AND al.record_id=" . (int)$caseId . ')'];
        $parts[] = "(al.table_name='case_invoices' AND al.action='delete_invoice'
            AND JSON_UNQUOTE(JSON_EXTRACT(al.old_values,'$.case_id'))='" . (int)$caseId . "')";
        $parts[] = "(al.table_name='case_treatments' AND (
            JSON_UNQUOTE(JSON_EXTRACT(al.old_values,'$.case_id'))='" . (int)$caseId . "'
            OR JSON_UNQUOTE(JSON_EXTRACT(al.new_values,'$.case_id'))='" . (int)$caseId . "'
        ))";
        if ($invoiceIds) {
            $parts[] = "(al.table_name='case_invoices' AND al.record_id IN (" . implode(',', array_map('intval', $invoiceIds)) . '))';
        }
        if ($paymentIds) {
            $parts[] = "(al.table_name='payments' AND al.record_id IN (" . implode(',', array_map('intval', $paymentIds)) . '))';
        }
        if ($treatmentIds) {
            $parts[] = "(al.table_name='case_treatments' AND al.record_id IN (" . implode(',', array_map('intval', $treatmentIds)) . '))';
        }
        if ($allocationIds) {
            $parts[] = "(al.table_name='payment_allocations' AND al.record_id IN (" . implode(',', array_map('intval', $allocationIds)) . '))';
        }
        return $this->db->fetchAll(
            "SELECT al.*,u.full_name AS user_name,u.role AS user_role
             FROM activity_log al LEFT JOIN users u ON u.id=al.user_id
             WHERE " . implode(' OR ', $parts) . '
             ORDER BY al.created_at DESC,al.id DESC LIMIT ' . max(1, (int)$limit)
        );
    }
}
