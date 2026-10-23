<?php
/**
 * Installment Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class Installment {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /** Amount already carried inside a linked treatment invoice. */
    private function bundledInvoiceReceived($invoiceId) {
        if (!(int)$invoiceId) return 0.0;
        $row = $this->db->fetch(
            "SELECT LEAST(
                        GREATEST(0,total_amount-discount_amount),
                        GREATEST(0,COALESCE(draft_received_amount,0))
                          + GREATEST(0,COALESCE(prior_paid_amount,0))
                          + GREATEST(0,COALESCE(advance_paid_amount,0))
                    ) AS amount
               FROM case_invoices WHERE id=:id",
            ['id' => (int)$invoiceId]
        );
        return max(0, (float)($row['amount'] ?? 0));
    }

    /**
     * Create installment plan
     */
    public function createPlan($data) {
        try {
            $totalAmount = max(0, (float) ($data['total_amount'] ?? 0));
            $downPayment = max(0, min((float) ($data['down_payment'] ?? 0), $totalAmount));
            $numberOfInstallments = max(1, (int) ($data['number_of_installments'] ?? 1));

            if ($totalAmount <= 0) {
                return ['success' => false, 'message' => 'کۆی پلانی قیست دەبێت گەورەتر بێت لە سفر'];
            }

            // One active-OR-pending plan per case — a second plan (even a still
            // unapproved proposal) makes payment allocation ambiguous once either
            // one activates. Uses hasBlockingPlan(), NOT getPlanByCase() — that
            // method stays 'active'-only for display/balance callers (see below).
            if ($this->hasBlockingPlan($data['case_id'])) {
                return ['success' => false, 'message' => 'ئەم کەیسە پێشتر پلانی قیستی چالاک یان چاوەڕوانی هەیە'];
            }

            $this->db->beginTransaction();

            // Calculate installment amount
            $remainingAmount = max(0, $totalAmount - $downPayment);
            $installmentAmount = ceil($remainingAmount / $numberOfInstallments);
            // ceil() can overshoot for small amounts (e.g. 5 ÷ 4 → per=2, last=-1);
            // fall back to floor so the last installment never goes negative.
            if ($remainingAmount - ($installmentAmount * ($numberOfInstallments - 1)) < 0) {
                $installmentAmount = floor($remainingAmount / $numberOfInstallments);
            }

            $planData = [
                'case_id' => $data['case_id'],
                // Which visit receipt this plan was agreed against. NULL for a
                // plan covering the case as a whole (how it used to work).
                'invoice_id' => !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null,
                'total_amount' => $totalAmount,
                'down_payment' => $downPayment,
                'remaining_amount' => $remainingAmount,
                'number_of_installments' => $numberOfInstallments,
                'installment_amount' => $installmentAmount,
                'start_date' => $data['start_date'],
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null
            ];

            $planId = $this->db->insert('installment_plans', $planData);

            // Create schedule (shared with activatePlan() — see buildSchedule()).
            $this->buildSchedule($planId, $totalAmount, $downPayment, $numberOfInstallments, $data['start_date']);

            // A down payment already written on a linked treatment invoice is
            // part of that invoice bundle; do not duplicate it in payments.
            $invoiceId = !empty($data['invoice_id']) ? (int)$data['invoice_id'] : 0;
            $bundledDown = $this->bundledInvoiceReceived($invoiceId);
            if ($invoiceId && $bundledDown > 0.009 && $downPayment > $bundledDown + 0.009) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'پارەی پێشەکی ناتوانێت لە بڕی وەرگیراوی ناو وەسڵ زیاتر بێت'];
            }
            if ($downPayment > 0 && (!$invoiceId || $bundledDown <= 0.009)) {
                require_once __DIR__ . '/Payment.php';
                $payment = new Payment();
                $downResult = $payment->directPayment([
                    'case_id' => $data['case_id'],
                    'invoice_id' => !empty($data['invoice_id']) ? (int)$data['invoice_id'] : null,
                    'payment_type' => PAYMENT_INSTALLMENT,
                    'amount' => $downPayment,
                    'payment_method' => 'cash',
                    'notes' => 'پارەی پێشەکی - پلانی قیست'
                ], $data['created_by']);
                if (empty($downResult['success'])) {
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'تۆمارکردنی پێشەکی سەرنەکەوت: ' . ($downResult['message'] ?? '')];
                }
            }

            $this->db->commit();

            // Notify admins + accountants about the new plan (fail-soft)
            try {
                require_once __DIR__ . '/Notification.php';
                $ctx = $this->db->fetch(
                    "SELECT p.full_name AS patient_name
                     FROM cases c
                     LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                     LEFT JOIN patients p ON pf.patient_id = p.id
                     WHERE c.id = :id",
                    ['id' => (int)$data['case_id']]
                );
                (new Notification())->notifyInstallmentPlan(
                    $data['case_id'],
                    $ctx['patient_name'] ?? '',
                    $totalAmount,
                    $numberOfInstallments,
                    $data['created_by'] ?? null
                );
            } catch (Exception $e) {
                error_log('Installment::createPlan notify failed: ' . $e->getMessage());
            }

            return ['success' => true, 'plan_id' => $planId];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Build the installment_schedule rows for a plan. Extracted from
     * createPlan()'s original schedule loop so createPlan() (direct
     * accountant/admin creation) and activatePlan() (pending -> active)
     * build an identical schedule from the same total/down/n/start inputs.
     * Recomputes remaining/installment amount with createPlan's own
     * ceil-then-floor-fallback formula so the stored per-row amounts always
     * match what a caller who also stores installment_amount computed.
     *
     * @return float the per-installment amount actually used (all but the
     *                last row; the last row absorbs the rounding remainder)
     */
    private function buildSchedule($planId, $totalAmount, $downPayment, $numberOfInstallments, $startDate) {
        $remainingAmount = max(0, $totalAmount - $downPayment);
        $installmentAmount = ceil($remainingAmount / $numberOfInstallments);
        // ceil() can overshoot for small amounts (e.g. 5 ÷ 4 → per=2, last=-1);
        // fall back to floor so the last installment never goes negative.
        if ($remainingAmount - ($installmentAmount * ($numberOfInstallments - 1)) < 0) {
            $installmentAmount = floor($remainingAmount / $numberOfInstallments);
        }

        $start = new DateTime($startDate);
        for ($i = 1; $i <= $numberOfInstallments; $i++) {
            $dueDate = clone $start;
            $dueDate->modify('+' . $i . ' month');

            // Last installment might have different amount
            $amount = ($i == $numberOfInstallments)
                ? $remainingAmount - ($installmentAmount * ($numberOfInstallments - 1))
                : $installmentAmount;

            $this->db->insert('installment_schedule', [
                'plan_id' => $planId,
                'installment_number' => $i,
                'due_date' => $dueDate->format('Y-m-d'),
                'amount' => $amount,
                'status' => 'pending'
            ]);
        }

        return $installmentAmount;
    }

    /**
     * One active-OR-pending plan per case. Deliberately separate from
     * getPlanByCase() (below), which stays 'active'-only: callers of
     * getPlanByCase() want the currently-in-force plan for display/balance
     * purposes, where a pending proposal must NOT show up (Step 3 of the
     * spec — a pending plan counts in no balance/debt figure anywhere).
     * This uniqueness guard is the one place a pending plan DOES need to be
     * seen, so a second proposal/plan can't be created out from under it.
     */
    private function hasBlockingPlan($caseId) {
        return (bool) $this->db->fetch(
            "SELECT id FROM installment_plans WHERE case_id = :case_id AND status IN ('active', 'pending')",
            ['case_id' => $caseId]
        );
    }

    /**
     * Doctor path: propose an installment plan. Pending until an
     * accountant/admin activates it via activatePlan() — no schedule is
     * built and no money moves yet; proposed_down_payment is a STORED
     * INTENT only, not the real down_payment column (kept at 0 until
     * activation books it).
     */
    public function proposePlan(array $data, int $doctorUserId): int {
        $totalAmount = max(0, (float) ($data['total_amount'] ?? 0));
        $proposedDownPayment = max(0, min((float) ($data['down_payment'] ?? 0), $totalAmount));
        $numberOfInstallments = max(1, (int) ($data['number_of_installments'] ?? 1));

        if ($totalAmount <= 0) {
            throw new InvalidArgumentException('کۆی پلانی قیست دەبێت گەورەتر بێت لە سفر');
        }
        if ($this->hasBlockingPlan($data['case_id'])) {
            throw new Exception('ئەم کەیسە پێشتر پلانی قیستی چالاک یان چاوەڕوانی هەیە');
        }

        $planData = [
            'case_id' => $data['case_id'],
            'invoice_id' => !empty($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            'total_amount' => $totalAmount,
            'down_payment' => 0, // real down payment is 0 until activation books it
            'proposed_down_payment' => $proposedDownPayment,
            'remaining_amount' => $totalAmount, // no money moved yet — full total is still owed
            'number_of_installments' => $numberOfInstallments,
            'installment_amount' => 0, // no schedule yet
            'start_date' => $data['start_date'],
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
            'created_by' => $doctorUserId,
        ];

        $planId = (int) $this->db->insert('installment_plans', $planData);

        AuditLog::log('installment_plan_proposed', 'installment_plans', $planId, null, $planData, $doctorUserId);

        // Notify admins + accountants a proposal is waiting (fail-soft).
        try {
            require_once __DIR__ . '/Notification.php';
            $ctx = $this->db->fetch(
                "SELECT p.full_name AS patient_name
                 FROM cases c
                 LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                 LEFT JOIN patients p ON pf.patient_id = p.id
                 WHERE c.id = :id",
                ['id' => (int) $data['case_id']]
            );
            (new Notification())->notifyInstallmentPlan(
                $data['case_id'],
                $ctx['patient_name'] ?? '',
                $totalAmount,
                $numberOfInstallments,
                $doctorUserId
            );
        } catch (Exception $e) {
            error_log('Installment::proposePlan notify failed: ' . $e->getMessage());
        }

        return $planId;
    }

    /**
     * Accountant/admin path: activate a pending plan, optionally editing its
     * terms first. Builds the schedule and books the down payment here —
     * this is the ONLY place a proposed plan's money/schedule become real.
     * Optimistic guard on status='pending'; throws (does not return a
     * ['success'=>false] array) so a caller's assumptions about a plan
     * having activated are never silently wrong.
     *
     * @param array $edits whitelist: total_amount, number_of_installments,
     *              start_date, down_payment. installment_amount is always
     *              recomputed (createPlan's formula) so the stored value
     *              never drifts from the schedule buildSchedule() writes.
     */
    public function activatePlan(int $planId, array $edits, int $accountantUserId): array {
        $this->db->beginTransaction();
        try {
            $plan = $this->db->fetch("SELECT * FROM installment_plans WHERE id = ? FOR UPDATE", [$planId]);
            if (!$plan || $plan['status'] !== 'pending') {
                throw new Exception('تەنها پلانی چاوەڕوان دەگۆڕدرێت');
            }

            $totalAmount = array_key_exists('total_amount', $edits)
                ? max(0, (float) $edits['total_amount'])
                : (float) $plan['total_amount'];
            $numberOfInstallments = array_key_exists('number_of_installments', $edits)
                ? max(1, (int) $edits['number_of_installments'])
                : (int) $plan['number_of_installments'];
            $startDate = $edits['start_date'] ?? $plan['start_date'];
            $downPayment = array_key_exists('down_payment', $edits)
                ? max(0, min((float) $edits['down_payment'], $totalAmount))
                : max(0, min((float) $plan['proposed_down_payment'], $totalAmount));

            if ($totalAmount <= 0) {
                throw new InvalidArgumentException('کۆی پلانی قیست دەبێت گەورەتر بێت لە سفر');
            }

            // Same ceil-then-floor-fallback formula as createPlan(); the loop
            // in buildSchedule() below recomputes the identical value.
            $remainingAmount = max(0, $totalAmount - $downPayment);
            $installmentAmount = ceil($remainingAmount / $numberOfInstallments);
            if ($remainingAmount - ($installmentAmount * ($numberOfInstallments - 1)) < 0) {
                $installmentAmount = floor($remainingAmount / $numberOfInstallments);
            }

            $updateData = [
                'status' => 'active',
                'total_amount' => $totalAmount,
                'down_payment' => $downPayment,
                'remaining_amount' => $remainingAmount,
                'number_of_installments' => $numberOfInstallments,
                'installment_amount' => $installmentAmount,
                'start_date' => $startDate,
                'approved_by' => $accountantUserId,
                'approved_at' => date('Y-m-d H:i:s'),
            ];
            $stmt = $this->db->update('installment_plans', $updateData, 'id = :id AND status = :expected', [
                'id' => $planId, 'expected' => 'pending'
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new Exception('پلانەکە پێشتر گۆڕدراوە. تکایە دووبارە هەوڵ بدەرەوە');
            }

            $this->buildSchedule($planId, $totalAmount, $downPayment, $numberOfInstallments, $startDate);

            // For a linked treatment invoice the declared received amount is
            // already inside the invoice bundle. Only legacy case-wide plans
            // create a separate payment row for their down payment.
            $invoiceId = !empty($plan['invoice_id']) ? (int)$plan['invoice_id'] : 0;
            $bundledDown = $this->bundledInvoiceReceived($invoiceId);
            if ($invoiceId && $bundledDown > 0.009 && $downPayment > $bundledDown + 0.009) {
                throw new Exception('پارەی پێشەکی ناتوانێت لە بڕی وەرگیراوی ناو وەسڵ زیاتر بێت');
            }
            if ($downPayment > 0 && (!$invoiceId || $bundledDown <= 0.009)) {
                require_once __DIR__ . '/Payment.php';
                $payment = new Payment();
                $downResult = $payment->directPayment([
                    'case_id' => $plan['case_id'],
                    'invoice_id' => !empty($plan['invoice_id']) ? (int) $plan['invoice_id'] : null,
                    'payment_type' => PAYMENT_INSTALLMENT,
                    'amount' => $downPayment,
                    'payment_method' => 'cash',
                    'notes' => 'پارەی پێشەکی - پلانی قیست'
                ], $accountantUserId);
                if (empty($downResult['success'])) {
                    throw new Exception('تۆمارکردنی پێشەکی سەرنەکەوت: ' . ($downResult['message'] ?? ''));
                }
            }

            AuditLog::log('installment_plan_activated', 'installment_plans', $planId, $plan, $updateData, $accountantUserId);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        // Notify the proposing doctor of the decision (fail-soft, outside the transaction).
        try {
            require_once __DIR__ . '/Notification.php';
            (new Notification())->notifyInstallmentDecision(
                $planId,
                'activated',
                (int) $plan['created_by']
            );
        } catch (Exception $e) {
            error_log('Installment::activatePlan notify failed: ' . $e->getMessage());
        }

        return $this->getPlanById($planId);
    }

    /**
     * READ-ONLY limits editApprovedPlan() enforces, so the Approval Center's
     * «تەعدیل» form can say them up front: the lowest total the plan may be
     * set to (what is already paid on it), and the highest down payment —
     * null when the down payment is a separate receipt and cannot change.
     */
    public function approvedEditLimits(int $planId): array {
        $plan = $this->getPlanById($planId);
        if (!$plan) {
            return ['paid_so_far' => 0.0, 'down_payment_max' => null];
        }
        $collected = (float)($this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN ip.payment_id IS NULL OR p.status='approved' THEN ip.amount ELSE 0 END), 0) AS t
             FROM installment_payments ip LEFT JOIN payments p ON p.id = ip.payment_id
             WHERE ip.plan_id = :plan",
            ['plan' => $planId]
        )['t'] ?? 0);
        $invoiceId = !empty($plan['invoice_id']) ? (int)$plan['invoice_id'] : 0;
        $bundled = $this->bundledInvoiceReceived($invoiceId);
        return [
            'paid_so_far'      => round((float)$plan['down_payment'] + $collected, 2),
            'collected'        => round($collected, 2),
            'down_payment_max' => ($invoiceId && $bundled > 0.009) ? round($bundled, 2) : null,
        ];
    }

    /**
     * Correct the terms of an APPROVED plan (active or completed) in place.
     * It stays approved: status, approved_by and approved_at are never
     * written, so it keeps its place and date in the Approval Center's
     * پەسەندکراو list. Same fields activatePlan() accepts — total, down
     * payment, number of instalments, start date.
     *
     * The schedule is rebuilt from the new terms, and NO money moves: every
     * paid/remaining figure on it is derived from the plan's collections
     * (installment_payments → approved receipts), which are left exactly as
     * they are and re-applied to the new rows by recomputePlan().
     *
     * Guards:
     *   - the new total may not fall below what is already paid on the plan
     *     (down payment + collections);
     *   - the down payment may only change when it is part of the invoice's
     *     chair-side received figure (and stays within it); one booked as its
     *     own receipt is money on record and is refused here.
     *
     * Gated on Settings::allowEditApprovedInvoice(); throws like
     * activatePlan() so a caller never mistakes a refusal for success.
     */
    public function editApprovedPlan(int $planId, array $edits, int $actorUserId): array {
        require_once __DIR__ . '/Settings.php';
        if (!Settings::allowEditApprovedInvoice()) {
            throw new RuntimeException('دەستکاریکردنی تۆماری پەسەندکراو ناچالاکە — لە ڕێکخستنەکان چالاکی بکە');
        }
        $this->db->beginTransaction();
        try {
            $plan = $this->db->fetch("SELECT * FROM installment_plans WHERE id = ? FOR UPDATE", [$planId]);
            if (!$plan || !in_array($plan['status'], ['active', 'completed'], true)) {
                throw new RuntimeException('تەنها پلانی چالاک یان تەواوبوو بەم شێوەیە دەگۆڕدرێت');
            }

            $total = array_key_exists('total_amount', $edits)
                ? round(max(0, (float)$edits['total_amount']), 2)
                : round((float)$plan['total_amount'], 2);
            $count = array_key_exists('number_of_installments', $edits)
                ? (int)$edits['number_of_installments']
                : (int)$plan['number_of_installments'];
            $start = array_key_exists('start_date', $edits) ? (string)$edits['start_date'] : (string)$plan['start_date'];
            $down = array_key_exists('down_payment', $edits)
                ? round(max(0, (float)$edits['down_payment']), 2)
                : round((float)$plan['down_payment'], 2);

            if ($total <= 0) {
                throw new InvalidArgumentException('کۆی پلانی قیست دەبێت گەورەتر بێت لە سفر');
            }
            if ($count < 1 || $count > 120) {
                throw new InvalidArgumentException('ژمارەی قیست دەبێت لە نێوان ١ و ١٢٠ بێت');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || strtotime($start) === false) {
                throw new InvalidArgumentException('بەرواری دەستپێک نادروستە');
            }
            if ($down > $total + 0.009) {
                throw new InvalidArgumentException('پارەی پێشەکی ناتوانێت لە کۆی پلان زیاتر بێت');
            }

            if (abs($down - (float)$plan['down_payment']) > 0.009) {
                $invoiceId = !empty($plan['invoice_id']) ? (int)$plan['invoice_id'] : 0;
                $bundled = $this->bundledInvoiceReceived($invoiceId);
                if (!$invoiceId || $bundled <= 0.009) {
                    throw new RuntimeException('پارەی پێشەکی ئەم پلانە بە قەبزێکی جیا وەرگیراوە — لێرە ناگۆڕدرێت');
                }
                if ($down > $bundled + 0.009) {
                    throw new InvalidArgumentException('پارەی پێشەکی ناتوانێت لە بڕی وەرگیراوی ناو وەسڵ زیاتر بێت (' . number_format($bundled) . ')');
                }
            }

            $collected = (float)($this->db->fetch(
                "SELECT COALESCE(SUM(CASE WHEN ip.payment_id IS NULL OR p.status='approved' THEN ip.amount ELSE 0 END), 0) AS t
                 FROM installment_payments ip LEFT JOIN payments p ON p.id = ip.payment_id
                 WHERE ip.plan_id = :plan",
                ['plan' => $planId]
            )['t'] ?? 0);
            if ($down + $collected > $total + 0.009) {
                throw new InvalidArgumentException('کۆی نوێی پلان لە پارەی دراو کەمترە (' . number_format($down + $collected) . ')');
            }

            // Same ceil-then-floor formula as activatePlan()/buildSchedule().
            $remaining = max(0, $total - $down);
            $per = ceil($remaining / $count);
            if ($remaining - ($per * ($count - 1)) < 0) {
                $per = floor($remaining / $count);
            }

            $changes = [
                'total_amount'           => $total,
                'down_payment'           => $down,
                'remaining_amount'       => $remaining,
                'number_of_installments' => $count,
                'installment_amount'     => $per,
                'start_date'             => $start,
            ];
            $moved = false;
            foreach ($changes as $k => $v) {
                $old = $plan[$k] ?? null;
                $same = (is_numeric($old) && is_numeric($v)) ? abs((float)$old - (float)$v) < 0.005 : (string)$old === (string)$v;
                if (!$same) { $moved = true; break; }
            }
            if (!$moved) {
                $this->db->rollback();
                return $this->getPlanById($planId);
            }

            // buildSchedule() appends, so the old rows go first. Their paid
            // figures were derived, never money: recomputePlan() re-derives
            // them on the new rows from the untouched collections.
            $this->db->delete('installment_schedule', 'plan_id = :plan', ['plan' => $planId]);
            $this->db->update('installment_plans', $changes, 'id = :id', ['id' => $planId]);
            $this->buildSchedule($planId, $total, $down, $count, $start);
            AuditLog::logChange('installment_plan_approved_edited', 'installment_plans', $planId, $plan, $changes, $actorUserId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->recomputePlan($planId);
        return $this->getPlanById($planId);
    }

    /**
     * Accountant/admin path: reject a pending plan. Reason is server-enforced
     * non-empty. Optimistic guard on status='pending'; throws on any failure.
     */
    public function rejectPlan(int $planId, string $reason, int $userId): bool {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('هۆکاری ڕەتکردنەوە پێویستە');
        }

        $this->db->beginTransaction();
        try {
            $plan = $this->db->fetch("SELECT * FROM installment_plans WHERE id = ? FOR UPDATE", [$planId]);
            if (!$plan || $plan['status'] !== 'pending') {
                throw new Exception('تەنها پلانی چاوەڕوان دەگۆڕدرێت');
            }

            $changes = [
                'status' => 'rejected',
                'reject_reason' => $reason,
            ];
            $stmt = $this->db->update('installment_plans', $changes, 'id = :id AND status = :expected', [
                'id' => $planId, 'expected' => 'pending'
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new Exception('پلانەکە پێشتر گۆڕدراوە. تکایە دووبارە هەوڵ بدەرەوە');
            }

            AuditLog::log('installment_plan_rejected', 'installment_plans', $planId, $plan, $changes, $userId);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        // Notify the proposing doctor of the decision (fail-soft, outside the transaction).
        try {
            require_once __DIR__ . '/Notification.php';
            (new Notification())->notifyInstallmentDecision(
                $planId,
                'rejected',
                (int) $plan['created_by'],
                $reason
            );
        } catch (Exception $e) {
            error_log('Installment::rejectPlan notify failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Get plan by ID
     */
    public function getPlanById($id) {
        $sql = "SELECT ip.*, c.case_number, p.full_name as patient_name, p.phone as patient_phone
                FROM installment_plans ip
                LEFT JOIN cases c ON ip.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                WHERE ip.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get plan by case. Step 3: 'active'-only by design — a pending
     * proposal must not surface as "the" plan for balance/display callers
     * (cases/view.php, accountant/report-detail.php, Payment::approve).
     * The uniqueness guard that DOES need to see pending plans uses the
     * separate hasBlockingPlan() above, not this method.
     */
    public function getPlanByCase($caseId) {
        $sql = "SELECT * FROM installment_plans WHERE case_id = :case_id AND status = 'active'";
        return $this->db->fetch($sql, ['case_id' => $caseId]);
    }

    /**
     * Get schedule for plan
     */
    public function getSchedule($planId) {
        $sql = "SELECT is2.*, pay.payment_number, pay.payment_date
                FROM installment_schedule is2
                LEFT JOIN payments pay ON is2.payment_id = pay.id
                WHERE is2.plan_id = :plan_id
                ORDER BY is2.installment_number";
        return $this->db->fetchAll($sql, ['plan_id' => $planId]);
    }

    /**
     * Pay installment (legacy schedule-row entry point). Step 4: unified onto
     * installment_payments — delegates to makeFlexiblePayment() instead of
     * writing installment_schedule.paid_amount directly, so this path and the
     * flexible-payment path share one accounting trail. Callers
     * (installments.php, cases/view.php) only ever read $result['success'] /
     * $result['message'], which makeFlexiblePayment() still returns.
     */
    public function payInstallment($scheduleId, $amount, $userId) {
        $schedule = $this->getScheduleById($scheduleId);
        if (!$schedule) {
            return ['success' => false, 'message' => 'قیست نەدۆزرایەوە'];
        }
        return $this->makeFlexiblePayment($schedule['plan_id'], [
            'amount' => $amount,
            'payment_method' => 'cash',
            'notes' => 'قیستی ژمارە ' . $schedule['installment_number']
        ], $userId);
    }

    /**
     * Get schedule by ID
     */
    public function getScheduleById($id) {
        $sql = "SELECT * FROM installment_schedule WHERE id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Check and update plan completion
     */
    private function checkPlanCompletion($planId) {
        $sql = "SELECT COUNT(*) as pending FROM installment_schedule
                WHERE plan_id = :plan_id AND status IN ('pending', 'partial', 'overdue')";
        $result = $this->db->fetch($sql, ['plan_id' => $planId]);

        if ($result['pending'] == 0) {
            $this->db->update('installment_plans', ['status' => 'completed'], 'id = :id', ['id' => $planId]);
        }
    }

    /**
     * Get overdue installments
     * @param int|null $doctorId Filter by doctor (null = all doctors for admin)
     */
    public function getOverdue($doctorId = null) {
        $sql = "SELECT is2.*, ip.case_id, c.case_number,
                p.full_name as patient_name, p.phone as patient_phone,
                u.full_name as doctor_name, pf.doctor_id
                FROM installment_schedule is2
                INNER JOIN installment_plans ip ON is2.plan_id = ip.id
                LEFT JOIN cases c ON ip.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE is2.status IN ('pending', 'partial')
                AND is2.due_date < CURDATE()";

        $params = [];
        if ($doctorId) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY is2.due_date";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get upcoming installments
     * @param int $days Number of days to look ahead
     * @param int|null $doctorId Filter by doctor (null = all doctors for admin)
     */
    public function getUpcoming($days = 7, $doctorId = null) {
        $sql = "SELECT is2.*, ip.case_id, c.case_number,
                p.full_name as patient_name, p.phone as patient_phone,
                u.full_name as doctor_name, pf.doctor_id
                FROM installment_schedule is2
                INNER JOIN installment_plans ip ON is2.plan_id = ip.id
                LEFT JOIN cases c ON ip.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE is2.status = 'pending'
                AND is2.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)";

        $params = ['days' => $days];
        if ($doctorId) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY is2.due_date";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Recalculate pending installment schedule amounts after a discount is applied to the case.
     * Only pending/overdue (unpaid) schedule rows are updated; paid rows stay unchanged.
     */
    public function recalculateAfterDiscount($planId, $newRemainingAmount) {
        try {
            $this->db->beginTransaction();

            // Get pending/overdue schedule rows ordered by installment number
            $pendingRows = $this->db->fetchAll(
                "SELECT id FROM installment_schedule
                 WHERE plan_id = :plan_id AND status IN ('pending', 'overdue')
                 ORDER BY installment_number ASC",
                ['plan_id' => $planId]
            );

            $count = count($pendingRows);
            if ($count === 0) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'No pending installments to update'];
            }

            // Distribute evenly; last row absorbs rounding remainder
            $perMonth = (int) ceil($newRemainingAmount / $count);
            $assigned = 0;

            foreach ($pendingRows as $i => $row) {
                $isLast  = ($i === $count - 1);
                $amount  = $isLast ? ($newRemainingAmount - $assigned) : $perMonth;
                if ($amount < 0) $amount = 0;

                $this->db->update(
                    'installment_schedule',
                    ['amount' => $amount],
                    'id = :id',
                    ['id' => $row['id']]
                );
                $assigned += $amount;
            }

            // Also update the plan summary columns
            $this->db->update(
                'installment_plans',
                [
                    'remaining_amount'   => $newRemainingAmount,
                    'installment_amount' => $perMonth,
                ],
                'id = :id',
                ['id' => $planId]
            );

            $this->db->commit();
            return ['success' => true, 'updated_rows' => $count, 'new_per_month' => $perMonth];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update overdue status (run daily via cron)
     */
    public function updateOverdueStatus() {
        $sql = "UPDATE installment_schedule
                SET status = 'overdue'
                WHERE status = 'pending' AND due_date < CURDATE()";
        return $this->db->query($sql);
    }

    /**
     * Get all active plans
     * Step 3: already status='active'-only — pending plans are naturally excluded.
     * @param int $page Page number
     * @param int $limit Items per page
     * @param int|null $doctorId Filter by doctor (null = all doctors for admin)
     */
    public function getActivePlans($page = 1, $limit = ITEMS_PER_PAGE, $doctorId = null) {
        $offset = ($page - 1) * $limit;
        $sql = "SELECT ip.*, c.case_number, p.full_name as patient_name, p.phone as patient_phone,
                u.full_name as doctor_name, pf.doctor_id,
                (SELECT COUNT(*) FROM installment_schedule is2 WHERE is2.plan_id = ip.id AND is2.status = 'paid') as paid_count,
                (SELECT SUM(paid_amount) FROM installment_schedule is2 WHERE is2.plan_id = ip.id) as total_paid
                FROM installment_plans ip
                LEFT JOIN cases c ON ip.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE ip.status = 'active'";

        $params = [];
        if ($doctorId) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY ip.created_at DESC LIMIT {$limit} OFFSET {$offset}";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Cancel plan
     */
    public function cancelPlan($planId, $reason = '') {
        try {
            $this->db->update('installment_plans', [
                'status' => 'cancelled',
                'notes' => $reason
            ], 'id = :id', ['id' => $planId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ======================================
    // FLEXIBLE INSTALLMENT PAYMENT METHODS
    // ======================================

    /**
     * Make a flexible payment toward an installment plan
     * Payment is not tied to a specific schedule row
     *
     * @param int $planId Plan ID
     * @param array $data Payment data (amount, payment_method, notes)
     * @param int $userId User making the payment
     * @return array Result with success status
     */
    public function makeFlexiblePayment($planId, $data, $userId) {
        try {
            $this->db->beginTransaction();

            $plan = $this->getPlanById($planId);
            if (!$plan) {
                throw new Exception('پلانی قیست نەدۆزرایەوە');
            }

            if ($plan['status'] !== 'active') {
                throw new Exception('ئەم پلانە چالاک نییە');
            }

            $amount = floatval($data['amount']);
            if ($amount <= 0) {
                throw new Exception('بڕی پارە دەبێت گەورەتر بێت لە سفر');
            }

            // Record the money in the main payments table (auto-approved:
            // this direct path is used by admin/accountant collection only).
            require_once __DIR__ . '/Payment.php';
            $payment = new Payment();
            $payResult = $payment->directPayment([
                'case_id' => $plan['case_id'],
                'invoice_id' => !empty($plan['invoice_id']) ? (int)$plan['invoice_id'] : null,
                'payment_type' => PAYMENT_INSTALLMENT,
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'notes' => $data['notes'] ?? 'پارەدانی قیست'
            ], $userId);
            if (empty($payResult['success'])) {
                throw new Exception($payResult['message'] ?? 'تۆمارکردنی پارەدان سەرنەکەوت');
            }

            // Apply to the plan schedule (installment_payments + schedule + completion)
            $paymentId = $this->applyFlexibleToPlan($planId, $amount, $data['payment_method'] ?? 'cash', $data['notes'] ?? 'پارەدانی قیست', $userId);

            // Keep the legacy installment row as schedule metadata, but link it
            // to the one canonical payment receipt used by the ledger.
            $this->db->update('installment_payments', [
                'payment_id' => (int)$payResult['payment_id']
            ], 'id = :id', ['id' => (int)$paymentId]);
            $installmentPaymentId = (int)$paymentId;
            $paymentId = (int)$payResult['payment_id'];

            $this->db->commit();

            return [
                'success' => true,
                'payment_id' => $paymentId,
                'installment_payment_id' => $installmentPaymentId,
                'message' => 'پارەدان بە سەرکەوتوویی تۆمارکرا'
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Apply an installment payment amount to the plan's own bookkeeping
     * (installment_payments row + plan totals + schedule statuses + completion).
     *
     * This is deliberately SEPARATE from creating the payments-table row, so it
     * can be called two ways:
     *   1. makeFlexiblePayment() — direct collection (admin/accountant).
     *   2. Payment::approve() — when the accountant confirms a doctor's PENDING
     *      installment request, the plan allocation is deferred to approval.
     * Only this step touches the schedule, so a pending (unconfirmed) request
     * never moves the installment schedule.
     */
    public function applyFlexibleToPlan($planId, $amount, $method, $notes, $userId, $canonicalPaymentId = null) {
        $method = in_array($method, ['cash', 'card', 'transfer', 'other'], true)
            || ($method === 'advance' && $canonicalPaymentId) ? $method : 'cash';
        $ipId = $this->db->insert('installment_payments', [
            'plan_id' => $planId,
            'payment_id' => $canonicalPaymentId ? (int)$canonicalPaymentId : null,
            'amount' => $amount,
            'payment_method' => $method,
            'paid_at' => date('Y-m-d H:i:s'),
            'received_by' => $userId,
            'notes' => $notes
        ]);
        $this->updatePlanTotals($planId);
        $this->updateScheduleStatuses($planId);
        $this->checkPlanCompletionFlexible($planId);
        return $ipId;
    }

    /**
     * Doctor path: file a flexible installment payment as a PENDING request that
     * the accountant must approve (same flow as a regular payment). Creates only
     * the pending payments-table row — the plan schedule is untouched until the
     * accountant confirms it (see Payment::approve → applyFlexibleToPlan).
     */
    public function requestFlexiblePayment($planId, $data, $userId) {
        $plan = $this->getPlanById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلانی قیست نەدۆزرایەوە'];
        }
        if ($plan['status'] !== 'active') {
            return ['success' => false, 'message' => 'ئەم پلانە چالاک نییە'];
        }
        $amount = floatval($data['amount']);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'بڕی پارە دەبێت گەورەتر بێت لە سفر'];
        }

        // Cap at the plan's remaining balance minus any installment requests
        // already pending on this case, so stacked requests can't over-collect
        // the plan when the accountant approves them.
        $summary = $this->getPlanSummary($planId);
        $planRemaining = (float)($summary['remaining_balance'] ?? 0);
        $pendingReq = (float)($this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS t FROM payments
             WHERE case_id = :cid AND status = 'pending' AND payment_type = :ptype",
            ['cid' => $plan['case_id'], 'ptype' => PAYMENT_INSTALLMENT]
        )['t'] ?? 0);
        $available = max(0, $planRemaining - $pendingReq);
        if ($amount > $available + 0.01) {
            if ($available <= 0.01) {
                return ['success' => false, 'message' => 'ئەم پلانە باڵانسی کراوەی نییە یان داواکاری چاوەڕوانی هەیە'];
            }
            return ['success' => false, 'message' => 'بڕی داواکراو زۆرترە لە ماوەی پلان (' . number_format($available) . ')'];
        }

        require_once __DIR__ . '/Payment.php';
        $payment = new Payment();
        return $payment->createRequest([
            'case_id' => $plan['case_id'],
            'invoice_id' => !empty($plan['invoice_id']) ? (int)$plan['invoice_id'] : null,
            'payment_type' => PAYMENT_INSTALLMENT,
            'amount' => $amount,
            'payment_method' => $data['payment_method'] ?? 'cash',
            'notes' => ($data['notes'] ?? '') !== '' ? $data['notes'] : 'داواکاری پارەدانی قیست',
            'received_by' => $userId
        ]);
    }

    /**
     * Get all payments for a plan
     */
    public function getPlanPayments($planId) {
        $sql = "SELECT ip.*, u.full_name as received_by_name,
                       p.payment_number, p.status AS payment_status, p.reversed_at
                FROM installment_payments ip
                LEFT JOIN users u ON ip.received_by = u.id
                LEFT JOIN payments p ON p.id = ip.payment_id
                WHERE ip.plan_id = :plan_id
                ORDER BY ip.paid_at DESC";
        return $this->db->fetchAll($sql, ['plan_id' => $planId]);
    }

    /**
     * Get payment summary for a plan (dynamic calculation)
     */
    public function getPlanSummary($planId) {
        $plan = $this->getPlanById($planId);
        if (!$plan) {
            return null;
        }

        // Get total payments from installment_payments table
        $sql = "SELECT COALESCE(SUM(CASE WHEN ip.payment_id IS NULL OR p.status='approved' THEN ip.amount ELSE 0 END), 0) as total_paid
                FROM installment_payments ip LEFT JOIN payments p ON p.id=ip.payment_id
                WHERE ip.plan_id = :plan_id";
        $result = $this->db->fetch($sql, ['plan_id' => $planId]);
        $totalPaidFromPayments = floatval($result['total_paid']);

        // Total includes down payment
        $totalPaid = $plan['down_payment'] + $totalPaidFromPayments;
        $remainingBalance = max(0, $plan['total_amount'] - $totalPaid);
        $creditBalance = max(0, $totalPaid - $plan['total_amount']);

        // Get schedule with dynamic status
        $schedule = $this->getScheduleWithDynamicStatus($planId, $totalPaidFromPayments);

        // Calculate overdue amount
        $overdueAmount = 0;
        $today = date('Y-m-d');
        foreach ($schedule as $sch) {
            if ($sch['due_date'] < $today && $sch['dynamic_remaining'] > 0) {
                $overdueAmount += $sch['dynamic_remaining'];
            }
        }

        return [
            'plan' => $plan,
            'total_amount' => $plan['total_amount'],
            'down_payment' => $plan['down_payment'],
            'total_paid' => $totalPaid,
            'total_paid_installments' => $totalPaidFromPayments,
            'remaining_balance' => $remainingBalance,
            'credit_balance' => $creditBalance,
            'overdue_amount' => $overdueAmount,
            'schedule' => $schedule,
            'is_completed' => $remainingBalance <= 0,
            'progress_percent' => $plan['total_amount'] > 0 ? min(100, round(($totalPaid / $plan['total_amount']) * 100, 1)) : 0
        ];
    }

    /**
     * Get schedule with dynamically calculated status
     * Payments are allocated to earliest due dates first
     */
    public function getScheduleWithDynamicStatus($planId, $totalPaidFromPayments = null) {
        if ($totalPaidFromPayments === null) {
            $sql = "SELECT COALESCE(SUM(CASE WHEN ip.payment_id IS NULL OR p.status='approved' THEN ip.amount ELSE 0 END), 0) as total
                    FROM installment_payments ip LEFT JOIN payments p ON p.id=ip.payment_id
                    WHERE ip.plan_id = :plan_id";
            $result = $this->db->fetch($sql, ['plan_id' => $planId]);
            $totalPaidFromPayments = floatval($result['total']);
        }

        $sql = "SELECT * FROM installment_schedule WHERE plan_id = :plan_id ORDER BY due_date ASC, installment_number ASC";
        $schedule = $this->db->fetchAll($sql, ['plan_id' => $planId]);

        $remainingPayment = $totalPaidFromPayments;
        $today = date('Y-m-d');
        $result = [];

        foreach ($schedule as $sch) {
            $amount = floatval($sch['amount']);

            // Calculate how much of this installment is covered
            $coveredAmount = min($remainingPayment, $amount);
            $dynamicRemaining = $amount - $coveredAmount;

            // Determine dynamic status
            if ($coveredAmount >= $amount) {
                $dynamicStatus = 'paid';
            } elseif ($coveredAmount > 0) {
                $dynamicStatus = 'partial';
            } elseif ($sch['due_date'] < $today) {
                $dynamicStatus = 'overdue';
            } else {
                $dynamicStatus = 'pending';
            }

            $sch['dynamic_paid'] = $coveredAmount;
            $sch['dynamic_remaining'] = $dynamicRemaining;
            $sch['dynamic_status'] = $dynamicStatus;
            $sch['is_overdue'] = $sch['due_date'] < $today && $dynamicStatus !== 'paid';

            $result[] = $sch;

            $remainingPayment -= $coveredAmount;
        }

        return $result;
    }

    /**
     * Update plan totals (cached values)
     */
    private function updatePlanTotals($planId) {
        $sql = "SELECT COALESCE(SUM(CASE WHEN ip.payment_id IS NULL OR p.status='approved' THEN ip.amount ELSE 0 END), 0) as total
                FROM installment_payments ip LEFT JOIN payments p ON p.id=ip.payment_id
                WHERE ip.plan_id = :plan_id";
        $result = $this->db->fetch($sql, ['plan_id' => $planId]);
        $totalPaid = floatval($result['total']);

        $plan = $this->getPlanById($planId);
        $totalWithDownPayment = $plan['down_payment'] + $totalPaid;
        $creditBalance = max(0, $totalWithDownPayment - $plan['total_amount']);

        $this->db->update('installment_plans', [
            'total_paid' => $totalPaid,
            'credit_balance' => $creditBalance
        ], 'id = :id', ['id' => $planId]);
    }

    /**
     * Update schedule statuses based on total payments
     * This updates the legacy paid_amount field for backward compatibility
     */
    private function updateScheduleStatuses($planId) {
        $sql = "SELECT COALESCE(SUM(CASE WHEN ip.payment_id IS NULL OR p.status='approved' THEN ip.amount ELSE 0 END), 0) as total
                FROM installment_payments ip LEFT JOIN payments p ON p.id=ip.payment_id
                WHERE ip.plan_id = :plan_id";
        $result = $this->db->fetch($sql, ['plan_id' => $planId]);
        $remainingPayment = floatval($result['total']);

        $sql = "SELECT * FROM installment_schedule WHERE plan_id = :plan_id ORDER BY due_date ASC, installment_number ASC";
        $schedule = $this->db->fetchAll($sql, ['plan_id' => $planId]);

        $today = date('Y-m-d');

        foreach ($schedule as $sch) {
            $amount = floatval($sch['amount']);
            $coveredAmount = min($remainingPayment, $amount);

            // Determine status
            if ($coveredAmount >= $amount) {
                $status = 'paid';
            } elseif ($coveredAmount > 0) {
                $status = 'partial';
            } elseif ($sch['due_date'] < $today) {
                $status = 'overdue';
            } else {
                $status = 'pending';
            }

            // Update schedule row
            $this->db->update('installment_schedule', [
                'paid_amount' => $coveredAmount,
                'status' => $status,
                'paid_date' => $status === 'paid' ? date('Y-m-d') : $sch['paid_date']
            ], 'id = :id', ['id' => $sch['id']]);

            $remainingPayment -= $coveredAmount;
        }
    }

    /**
     * Check if plan is completed (flexible version)
     */
    private function checkPlanCompletionFlexible($planId) {
        $summary = $this->getPlanSummary($planId);
        if ($summary && $summary['remaining_balance'] <= 0) {
            $this->db->update('installment_plans', ['status' => 'completed'], 'id = :id', ['id' => $planId]);
        }
    }

    /**
     * Rebuild a plan's cached totals and schedule statuses after money on it
     * moved from OUTSIDE this class — a canonical receipt reversed elsewhere
     * (classes/ApprovalReversal.php), or a schedule entry removed.
     *
     * Public so those paths do not have to duplicate the formulas: every
     * figure here is derived from installment_payments joined to the receipt
     * that backs it, so a refunded receipt simply stops counting.
     */
    public function recomputePlan($planId) {
        $planId = (int)$planId;
        $plan = $this->getPlanById($planId);
        if (!$plan) {
            return;
        }
        $this->updatePlanTotals($planId);
        $this->updateScheduleStatuses($planId);

        if ($plan['status'] === 'completed') {
            // Coverage receded — a finished plan is owed money again.
            $summary = $this->getPlanSummary($planId);
            if ($summary && $summary['remaining_balance'] > 0.009) {
                $this->db->update('installment_plans', ['status' => 'active'], 'id = :id', ['id' => $planId]);
            }
        } elseif ($plan['status'] === 'active') {
            $this->checkPlanCompletionFlexible($planId);
        }
    }

    /**
     * Delete a payment (with recalculation)
     */
    public function deletePayment($paymentId, $userId) {
        try {
            $this->db->beginTransaction();

            // Get payment info
            $sql = "SELECT * FROM installment_payments WHERE id = :id";
            $payment = $this->db->fetch($sql, ['id' => $paymentId]);

            if (!$payment) {
                throw new Exception('پارەدان نەدۆزرایەوە');
            }

            $planId = $payment['plan_id'];

            // Accounting payments are never hard-deleted. Reverse the linked
            // canonical receipt; its allocations remain as immutable history
            // and stop counting because the receipt becomes refunded.
            if (empty($payment['payment_id'])) {
                throw new Exception('This is a legacy installment entry without a canonical receipt and cannot be deleted.');
            }
            require_once __DIR__ . '/Payment.php';
            $reversal = (new Payment())->refund(
                (int)$payment['payment_id'],
                'Installment payment reversed',
                (int)$userId
            );
            if (empty($reversal['success'])) {
                throw new Exception($reversal['message'] ?? 'Payment reversal failed.');
            }

            // Recalculate totals
            $this->updatePlanTotals($planId);
            $this->updateScheduleStatuses($planId);

            // Check completion status
            $plan = $this->getPlanById($planId);
            if ($plan['status'] === 'completed') {
                // Reopen if no longer fully paid
                $summary = $this->getPlanSummary($planId);
                if ($summary['remaining_balance'] > 0) {
                    $this->db->update('installment_plans', ['status' => 'active'], 'id = :id', ['id' => $planId]);
                }
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'پارەدان سڕایەوە'];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update schedule dates (without losing payment data)
     */
    public function updateScheduleDates($planId, $newDates) {
        try {
            $this->db->beginTransaction();

            foreach ($newDates as $scheduleId => $newDate) {
                $this->db->update('installment_schedule', [
                    'due_date' => $newDate
                ], 'id = :id AND plan_id = :plan_id', [
                    'id' => $scheduleId,
                    'plan_id' => $planId
                ]);
            }

            // Recalculate statuses with new dates
            $this->updateScheduleStatuses($planId);

            $this->db->commit();
            return ['success' => true, 'message' => 'بەروارەکان نوێکرایەوە'];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Skip a month and move remaining balance to next month
     * ڕاپەڕاندنی مانگ و گواستنەوەی ماوە بۆ مانگی داهاتوو
     *
     * @param int $scheduleId Current month schedule ID to skip
     * @return array Result
     */
    public function skipMonth($scheduleId) {
        try {
            $this->db->beginTransaction();

            // Get current schedule
            $current = $this->getScheduleById($scheduleId);
            if (!$current) {
                throw new Exception('قیست نەدۆزرایەوە');
            }

            $planId = $current['plan_id'];

            // Get the summary to find dynamic remaining for this installment
            $summary = $this->getPlanSummary($planId);
            $currentRemaining = 0;

            foreach ($summary['schedule'] as $sch) {
                if ($sch['id'] == $scheduleId) {
                    $currentRemaining = $sch['dynamic_remaining'];
                    break;
                }
            }

            if ($currentRemaining <= 0) {
                throw new Exception('ئەم قیستە تەواو دراوە، پێویست بە ڕاپەڕاندن نییە');
            }

            // Find next month schedule
            $sql = "SELECT * FROM installment_schedule
                    WHERE plan_id = :plan_id
                    AND installment_number > :num
                    ORDER BY installment_number ASC
                    LIMIT 1";
            $next = $this->db->fetch($sql, [
                'plan_id' => $planId,
                'num' => $current['installment_number']
            ]);

            if (!$next) {
                // No next month - extend the plan by adding a new month
                $lastSchedule = $this->db->fetch(
                    "SELECT * FROM installment_schedule WHERE plan_id = :plan_id ORDER BY installment_number DESC LIMIT 1",
                    ['plan_id' => $planId]
                );

                $newDueDate = new DateTime($lastSchedule['due_date']);
                $newDueDate->modify('+1 month');

                $this->db->insert('installment_schedule', [
                    'plan_id' => $planId,
                    'installment_number' => $lastSchedule['installment_number'] + 1,
                    'due_date' => $newDueDate->format('Y-m-d'),
                    'amount' => $currentRemaining,
                    'status' => 'pending'
                ]);

                // Update plan's number of installments
                $plan = $this->getPlanById($planId);
                $this->db->update('installment_plans', [
                    'number_of_installments' => $plan['number_of_installments'] + 1
                ], 'id = :id', ['id' => $planId]);
            } else {
                // Add remaining to next month
                $newAmount = $next['amount'] + $currentRemaining;
                $this->db->update('installment_schedule', [
                    'amount' => $newAmount
                ], 'id = :id', ['id' => $next['id']]);
            }

            // Set current month's amount to what was paid (dynamic_paid)
            $dynamicPaid = $current['amount'] - $currentRemaining;
            $this->db->update('installment_schedule', [
                'amount' => $dynamicPaid,
                'status' => $dynamicPaid > 0 ? 'paid' : 'skipped',
                'notes' => 'ڕاپەڕێندراو - ' . number_format($currentRemaining) . ' گوازرایەوە بۆ مانگی داهاتوو'
            ], 'id = :id', ['id' => $scheduleId]);

            // Recalculate statuses
            $this->updateScheduleStatuses($planId);

            $this->db->commit();

            return [
                'success' => true,
                'message' => number_format($currentRemaining) . ' گوازرایەوە بۆ مانگی داهاتوو',
                'moved_amount' => $currentRemaining
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get installment statistics
     */
    public function getStatistics() {
        $stats = [];

        // Total active plans — Step 3: status='active'-only, so a pending
        // proposal contributes nothing to total_remaining until activated.
        $sql = "SELECT COUNT(*) as count, SUM(remaining_amount) as total_remaining
                FROM installment_plans WHERE status = 'active'";
        $result = $this->db->fetch($sql);
        $stats['active_plans'] = $result['count'];
        $stats['total_remaining'] = $result['total_remaining'] ?? 0;

        // Overdue amount
        $sql = "SELECT SUM(is2.amount - is2.paid_amount) as overdue
                FROM installment_schedule is2
                INNER JOIN installment_plans ip ON is2.plan_id = ip.id
                WHERE is2.status IN ('pending', 'partial', 'overdue')
                AND is2.due_date < CURDATE() AND ip.status = 'active'";
        $result = $this->db->fetch($sql);
        $stats['overdue_amount'] = $result['overdue'] ?? 0;

        // Due this month
        $sql = "SELECT SUM(is2.amount - is2.paid_amount) as due_amount
                FROM installment_schedule is2
                INNER JOIN installment_plans ip ON is2.plan_id = ip.id
                WHERE is2.status IN ('pending', 'partial')
                AND MONTH(is2.due_date) = MONTH(CURDATE())
                AND YEAR(is2.due_date) = YEAR(CURDATE())
                AND ip.status = 'active'";
        $result = $this->db->fetch($sql);
        $stats['due_this_month'] = $result['due_amount'] ?? 0;

        return $stats;
    }

    // autoAllocatePayment removed 2026-08-26: dead code (no callers).
}
