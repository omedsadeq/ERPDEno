<?php
/**
 * Installment Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Installment {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
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

            // One active plan per case — a second plan makes payment allocation ambiguous
            $existingPlan = $this->getPlanByCase($data['case_id']);
            if ($existingPlan) {
                return ['success' => false, 'message' => 'ئەم کەیسە پێشتر پلانی قیستی چالاکی هەیە'];
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

            // Create schedule
            $startDate = new DateTime($data['start_date']);
            for ($i = 1; $i <= $numberOfInstallments; $i++) {
                $dueDate = clone $startDate;
                $dueDate->modify('+' . $i . ' month');

                // Last installment might have different amount
                $amount = ($i == $numberOfInstallments)
                    ? $remainingAmount - ($installmentAmount * ($numberOfInstallments - 1))
                    : $installmentAmount;

                $scheduleData = [
                    'plan_id' => $planId,
                    'installment_number' => $i,
                    'due_date' => $dueDate->format('Y-m-d'),
                    'amount' => $amount,
                    'status' => 'pending'
                ];
                $this->db->insert('installment_schedule', $scheduleData);
            }

            // If there's a down payment, record it — and fail the whole plan
            // if the payment can't be recorded, otherwise the plan would claim
            // money that has no payments row.
            if ($downPayment > 0) {
                require_once __DIR__ . '/Payment.php';
                $payment = new Payment();
                $downResult = $payment->directPayment([
                    'case_id' => $data['case_id'],
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
     * Get plan by case
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
     * Pay installment
     */
    public function payInstallment($scheduleId, $amount, $userId) {
        try {
            $this->db->beginTransaction();

            $schedule = $this->getScheduleById($scheduleId);
            $plan = $this->getPlanById($schedule['plan_id']);

            // Create payment
            require_once __DIR__ . '/Payment.php';
            $payment = new Payment();
            $paymentResult = $payment->directPayment([
                'case_id' => $plan['case_id'],
                'payment_type' => PAYMENT_INSTALLMENT,
                'amount' => $amount,
                'payment_method' => 'cash',
                'notes' => 'قیستی ژمارە ' . $schedule['installment_number']
            ], $userId);

            if (!$paymentResult['success']) {
                throw new Exception($paymentResult['message']);
            }

            // Update schedule
            $newPaidAmount = $schedule['paid_amount'] + $amount;
            $status = ($newPaidAmount >= $schedule['amount']) ? 'paid' : 'partial';

            $this->db->update('installment_schedule', [
                'paid_amount' => $newPaidAmount,
                'status' => $status,
                'payment_id' => $paymentResult['payment_id'],
                'paid_date' => date('Y-m-d')
            ], 'id = :id', ['id' => $scheduleId]);

            // Check if all installments are paid
            $this->checkPlanCompletion($schedule['plan_id']);

            $this->db->commit();
            return ['success' => true, 'payment_id' => $paymentResult['payment_id']];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
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

            $this->db->commit();

            return [
                'success' => true,
                'payment_id' => $paymentId,
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
    public function applyFlexibleToPlan($planId, $amount, $method, $notes, $userId) {
        $method = in_array($method, ['cash', 'card', 'transfer', 'other'], true) ? $method : 'cash';
        $ipId = $this->db->insert('installment_payments', [
            'plan_id' => $planId,
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
        $sql = "SELECT ip.*, u.full_name as received_by_name
                FROM installment_payments ip
                LEFT JOIN users u ON ip.received_by = u.id
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
        $sql = "SELECT COALESCE(SUM(amount), 0) as total_paid FROM installment_payments WHERE plan_id = :plan_id";
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
            $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM installment_payments WHERE plan_id = :plan_id";
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
        $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM installment_payments WHERE plan_id = :plan_id";
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
        $sql = "SELECT COALESCE(SUM(amount), 0) as total FROM installment_payments WHERE plan_id = :plan_id";
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

            // Delete the payment
            $this->db->delete('installment_payments', 'id = :id', ['id' => $paymentId]);

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

        // Total active plans
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

    /**
     * Auto-allocate general payment to installments
     * Distributes payment amount across earliest unpaid installments
     *
     * @param int $caseId Case ID
     * @param float $amount Payment amount to allocate
     * @param int $paymentId Payment record ID
     * @return array Result with success status and allocation details
     */
    public function autoAllocatePayment($caseId, $amount, $paymentId) {
        try {
            // Get active installment plan for this case
            $plan = $this->getPlanByCase($caseId);
            if (!$plan) {
                return ['success' => false, 'message' => 'No active installment plan found'];
            }

            // Get unpaid/partial installments ordered by due date (earliest first)
            $sql = "SELECT * FROM installment_schedule
                    WHERE plan_id = :plan_id
                    AND status IN ('pending', 'partial', 'overdue')
                    ORDER BY due_date ASC, installment_number ASC";
            $installments = $this->db->fetchAll($sql, ['plan_id' => $plan['id']]);

            if (empty($installments)) {
                return ['success' => false, 'message' => 'No unpaid installments found'];
            }

            $this->db->beginTransaction();

            $remainingAmount = $amount;
            $allocations = [];
            $firstInstallmentId = null;

            foreach ($installments as $installment) {
                if ($remainingAmount <= 0) {
                    break;
                }

                $installmentRemaining = $installment['amount'] - $installment['paid_amount'];

                if ($installmentRemaining <= 0) {
                    continue;
                }

                // Determine how much to allocate to this installment
                $allocation = min($remainingAmount, $installmentRemaining);
                $newPaidAmount = $installment['paid_amount'] + $allocation;

                // Determine new status
                $newStatus = ($newPaidAmount >= $installment['amount']) ? 'paid' : 'partial';

                // Update installment schedule
                $updateData = [
                    'paid_amount' => $newPaidAmount,
                    'status' => $newStatus
                ];

                // Link payment to first installment that receives funds
                if ($firstInstallmentId === null) {
                    $updateData['payment_id'] = $paymentId;
                    $updateData['paid_date'] = date('Y-m-d');
                    $firstInstallmentId = $installment['id'];
                }

                $this->db->update('installment_schedule', $updateData, 'id = :id', ['id' => $installment['id']]);

                // Track allocation
                $allocations[] = [
                    'installment_number' => $installment['installment_number'],
                    'installment_id' => $installment['id'],
                    'allocated_amount' => $allocation,
                    'new_status' => $newStatus
                ];

                $remainingAmount -= $allocation;
            }

            // Check if plan is completed
            if (!empty($allocations)) {
                $this->checkPlanCompletion($plan['id']);
            }

            $this->db->commit();

            return [
                'success' => true,
                'allocated' => $amount - $remainingAmount,
                'remaining_unallocated' => $remainingAmount,
                'allocations' => $allocations,
                'installments_affected' => count($allocations)
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
