<?php
/**
 * Payment Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CaseManager.php';
require_once __DIR__ . '/Installment.php';
require_once __DIR__ . '/Notification.php';

class Payment {
    private $db;
    private $caseManager;
    private $installmentManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->caseManager = new CaseManager();
        $this->installmentManager = new Installment();
    }

    /**
     * Generate payment number
     */
    private function generatePaymentNumber() {
        return 'PAY' . date('YmdHis') . rand(100, 999);
    }

    /**
     * Accounting snapshot for a case: what it costs, what's already been
     * collected (approved), what's still awaiting approval (pending), and how
     * much is genuinely still owed. This is the single source of truth that
     * stops a case being over-collected by stacked/stale payment requests.
     *
     * @param int      $caseId
     * @param int|null $excludePaymentId  ignore this payment id in the pending
     *                                    sum (use when re-evaluating that row)
     */
    public function getCaseBalance($caseId, $excludePaymentId = null) {
        $case = $this->db->fetch(
            "SELECT total_amount, discount_amount FROM cases WHERE id = :id",
            ['id' => (int)$caseId]
        );
        if (!$case) {
            return null;
        }
        $billable = max(0, (float)$case['total_amount'] - (float)$case['discount_amount']);

        $approved = (float)($this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS t FROM payments WHERE case_id = :id AND status = 'approved'",
            ['id' => (int)$caseId]
        )['t'] ?? 0);

        $pendingSql = "SELECT COALESCE(SUM(amount), 0) AS t FROM payments WHERE case_id = :id AND status = 'pending'";
        $pendingParams = ['id' => (int)$caseId];
        if ($excludePaymentId !== null) {
            $pendingSql .= " AND id <> :pid";
            $pendingParams['pid'] = (int)$excludePaymentId;
        }
        $pending = (float)($this->db->fetch($pendingSql, $pendingParams)['t'] ?? 0);

        return [
            'billable'          => $billable,          // total − discount
            'approved'          => $approved,          // already collected
            'pending'           => $pending,           // awaiting approval
            'remaining'         => max(0, $billable - $approved),           // owed vs. collected
            'unrequested'       => max(0, $billable - $approved - $pending), // owed once pending clears
        ];
    }

    /**
     * Mark any still-pending payment requests on a case as rejected once the
     * balance is fully collected — otherwise they dangle and could later be
     * approved into an over-collection (paid > total). Returns count superseded.
     */
    public function supersedeStalePendingRequests($caseId, $actorId = null, $exceptPaymentId = null) {
        $balance = $this->getCaseBalance($caseId);
        if (!$balance || $balance['remaining'] > 0.01) {
            return 0; // still money owed — every pending request is still valid
        }
        $sql = "SELECT id, amount FROM payments WHERE case_id = :id AND status = 'pending'";
        $params = ['id' => (int)$caseId];
        if ($exceptPaymentId !== null) {
            $sql .= " AND id <> :pid";
            $params['pid'] = (int)$exceptPaymentId;
        }
        $stale = $this->db->fetchAll($sql, $params);
        $count = 0;
        foreach ($stale as $row) {
            $this->db->update('payments', [
                'status'      => 'rejected',
                'rejected_by' => $actorId,
                'notes'       => 'داواکاری پارەدان بەتاڵکرایەوە: باڵانسی کەیس پێشتر تەواو دراوە'
            ], 'id = :id', ['id' => (int)$row['id']]);
            $count++;
        }
        return $count;
    }

    /**
     * Patient name + doctor user id for a case (for notifications). Fail-soft.
     */
    private function caseContext($caseId) {
        try {
            return $this->db->fetch(
                "SELECT c.id AS case_id, p.full_name AS patient_name, pf.doctor_id
                 FROM cases c
                 LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                 LEFT JOIN patients p ON pf.patient_id = p.id
                 WHERE c.id = :id",
                ['id' => (int)$caseId]
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Create payment request (from doctor)
     * UNIFIED: Payment remains pending. Auto-allocation happens when admin approves via approve() method
     */
    public function createRequest($data) {
        if (!isset($data['amount']) || floatval($data['amount']) <= 0) {
            return ['success' => false, 'message' => 'بڕی پارە دەبێت گەورەتر بێت لە سفر'];
        }
        // Anti-stacking: a new request may not exceed what is still owed AFTER
        // already-approved payments AND already-pending requests. Otherwise a
        // doctor could file several pending requests that together exceed the
        // case total, and approving them all would over-collect.
        if (!empty($data['case_id']) && ($data['payment_type'] ?? '') !== PAYMENT_INSTALLMENT) {
            $balance = $this->getCaseBalance($data['case_id']);
            if ($balance && floatval($data['amount']) > $balance['unrequested'] + 0.01) {
                if ($balance['unrequested'] <= 0.01) {
                    return ['success' => false, 'message' => 'ئەم کەیسە هیچ باڵانسێکی کراوەی نییە یان داواکاری پارەدانی چاوەڕوانی هەیە'];
                }
                return ['success' => false, 'message' => 'بڕی داواکراو زۆرترە لە ماوەی کراوە (' . number_format($balance['unrequested']) . ')'];
            }
        }
        $data['payment_number'] = $this->generatePaymentNumber();
        $data['status'] = 'pending';
        $data['payment_date'] = date('Y-m-d H:i:s');
        // Always know who entered the payment request
        if (empty($data['received_by']) && !empty($_SESSION['user_id'])) {
            $data['received_by'] = (int)$_SESSION['user_id'];
        }

        try {
            $paymentId = $this->db->insert('payments', $data);

            // Notify admins: a payment is waiting for approval
            if (!empty($data['case_id'])) {
                $ctx = $this->caseContext($data['case_id']);
                if ($ctx) {
                    (new Notification())->notifyPaymentRequested(
                        $ctx['case_id'],
                        $ctx['patient_name'] ?? '',
                        $data['amount'] ?? 0,
                        $paymentId,
                        $data['received_by'] ?? null
                    );
                }
            }

            return ['success' => true, 'payment_id' => $paymentId, 'payment_number' => $data['payment_number']];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Approve payment (admin only)
     * UNIFIED: Always auto-allocates to installments if active plan exists
     */
    public function approve($id, $approvedBy) {
        try {
            $this->db->beginTransaction();

            $payment = $this->getById($id);

            // Guard: only pending payments can be approved. Re-approving an
            // approved payment would re-run auto-allocation and double-credit
            // the installment schedule.
            if (!$payment) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'پارەدانەکە نەدۆزرایەوە'];
            }
            if (($payment['status'] ?? '') !== 'pending') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'ئەم پارەدانە پێشتر یەکلایی کراوەتەوە'];
            }

            // Over-collection guard: never approve a payment that would push the
            // total collected past what the case actually costs (total − discount).
            // This is the safety net for stale/duplicate pending requests — e.g.
            // a request left dangling after the balance was collected another way.
            // Installment payments are allocated by their own engine and skip this.
            $isInstallmentType = isset($payment['payment_type']) && $payment['payment_type'] === PAYMENT_INSTALLMENT;
            if (!$isInstallmentType && $payment['case_id']) {
                $balance = $this->getCaseBalance($payment['case_id'], $id);
                if ($balance && (float)$payment['amount'] > $balance['remaining'] + 0.01) {
                    // Auto-reject the stale request instead of leaving it pending forever
                    $this->db->update('payments', [
                        'status'      => 'rejected',
                        'rejected_by' => $approvedBy,
                        'notes'       => 'پەسەندنەکرا: بڕەکە لە ماوەی کەیس زیاترە (ماوە: ' . number_format($balance['remaining']) . ')'
                    ], 'id = :id', ['id' => $id]);
                    $this->db->commit();
                    return [
                        'success' => false,
                        'superseded' => true,
                        'message' => 'ئەم پارەدانە لە ماوەی کەیس زیاترە و پەسەندنەکرا (ماوە: ' . number_format($balance['remaining']) . '). ڕەنگە کەیسەکە پێشتر تەواو درابێت.'
                    ];
                }
            }

            $data = [
                'status' => 'approved',
                'approved_by' => $approvedBy
            ];
            $this->db->update('payments', $data, 'id = :id', ['id' => $id]);

            // Update case totals
            if ($payment['case_id']) {
                $this->caseManager->updateCaseTotals($payment['case_id']);
                // Balance now settled? Clear any other dangling pending requests.
                $this->supersedeStalePendingRequests($payment['case_id'], $approvedBy, $id);

                $isInstallmentPayment = isset($payment['payment_type']) && $payment['payment_type'] === PAYMENT_INSTALLMENT;

                if ($isInstallmentPayment) {
                    // Doctor's flexible installment REQUEST, now confirmed by the
                    // accountant: apply it to the plan schedule at approval time
                    // (the allocation was deferred until this confirmation).
                    $activePlan = $this->installmentManager->getPlanByCase($payment['case_id']);
                    if ($activePlan) {
                        $this->installmentManager->applyFlexibleToPlan(
                            $activePlan['id'],
                            (float)$payment['amount'],
                            $payment['payment_method'] ?? 'cash',
                            $payment['notes'] ?? 'پارەدانی قیست',
                            $approvedBy
                        );
                    }
                } else {
                    // UNIFIED PAYMENT ENGINE: Auto-allocate to installments if active plan exists
                    $activePlan = $this->installmentManager->getPlanByCase($payment['case_id']);
                    if ($activePlan) {
                        $allocationResult = $this->installmentManager->autoAllocatePayment(
                            $payment['case_id'],
                            $payment['amount'],
                            $id
                        );

                        // Store allocation details in response
                        if ($allocationResult['success']) {
                            $this->db->commit();
                            $this->notifyPaymentDecision($payment, true, $approvedBy);
                            return [
                                'success' => true,
                                'allocation' => $allocationResult
                            ];
                        }
                    }
                }
            }

            $this->db->commit();
            $this->notifyPaymentDecision($payment, true, $approvedBy);
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Notify doctor + accountants about an approve/reject decision. Fail-soft.
     */
    private function notifyPaymentDecision($payment, $approved, $actorId) {
        try {
            if (empty($payment['case_id'])) {
                return;
            }
            $ctx = $this->caseContext($payment['case_id']);
            (new Notification())->notifyPaymentResult(
                $approved,
                $payment['case_id'],
                $payment['patient_name'] ?? ($ctx['patient_name'] ?? ''),
                $payment['amount'] ?? 0,
                $ctx['doctor_id'] ?? 0,
                $actorId
            );
        } catch (Exception $e) {
            error_log('Payment::notifyPaymentDecision failed: ' . $e->getMessage());
        }
    }

    /**
     * Reject payment
     */
    public function reject($id, $reason = '', $rejectedBy = null) {
        $data = [
            'status' => 'rejected',
            'notes' => $reason,
            'rejected_by' => $rejectedBy ?? ($_SESSION['user_id'] ?? null)
        ];

        try {
            $payment = $this->getById($id);
            $wasApproved = $payment && $payment['status'] === 'approved';
            $this->db->update('payments', $data, 'id = :id', ['id' => $id]);
            // If an already-approved payment is being rejected, its amount was
            // counted in the case's paid_amount — recompute so the balance is correct.
            if ($wasApproved && !empty($payment['case_id'])) {
                $this->caseManager->updateCaseTotals($payment['case_id']);
            }
            if ($payment) {
                $this->notifyPaymentDecision($payment, false, $data['rejected_by']);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Direct payment (admin creates and approves)
     * UNIFIED: Always auto-allocates to installments if active plan exists
     */
    public function directPayment($data, $userId) {
        if (!isset($data['amount']) || floatval($data['amount']) <= 0) {
            return ['success' => false, 'message' => 'بڕی پارە دەبێت گەورەتر بێت لە سفر'];
        }
        $data['payment_number'] = $this->generatePaymentNumber();
        $data['status'] = 'approved';
        $data['received_by'] = $userId;
        $data['approved_by'] = $userId;
        $data['payment_date'] = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();

            $paymentId = $this->db->insert('payments', $data);

            // Update case totals
            if ($data['case_id']) {
                $this->caseManager->updateCaseTotals($data['case_id']);

                // Balance settled by this direct payment? Clear any dangling
                // pending requests so they can't later be approved into an
                // over-collection (the exact case-50 phantom-payment bug).
                $isInstallmentPayment = isset($data['payment_type']) && $data['payment_type'] === PAYMENT_INSTALLMENT;
                if (!$isInstallmentPayment) {
                    $this->supersedeStalePendingRequests($data['case_id'], $userId, $paymentId);
                }

                // UNIFIED PAYMENT ENGINE: Auto-allocate to installments if active plan exists
                // Skip auto-allocation for installment-type payments (already allocated by flexible system)
                if (!$isInstallmentPayment) {
                    $activePlan = $this->installmentManager->getPlanByCase($data['case_id']);
                    if ($activePlan) {
                        $allocationResult = $this->installmentManager->autoAllocatePayment(
                            $data['case_id'],
                            $data['amount'],
                            $paymentId
                        );

                        // Store allocation details in response
                        if ($allocationResult['success']) {
                            $this->db->commit();
                            $this->notifyDirectPayment($data, $userId);
                            return [
                                'success' => true,
                                'payment_id' => $paymentId,
                                'payment_number' => $data['payment_number'],
                                'allocation' => $allocationResult
                            ];
                        }
                    }
                }
            }

            $this->db->commit();
            $this->notifyDirectPayment($data, $userId);
            return ['success' => true, 'payment_id' => $paymentId, 'payment_number' => $data['payment_number']];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Notify admins + accountants that a payment was received. Fail-soft.
     */
    private function notifyDirectPayment($data, $actorId) {
        try {
            if (empty($data['case_id'])) {
                return;
            }
            $ctx = $this->caseContext($data['case_id']);
            (new Notification())->notifyPaymentReceived(
                $data['case_id'],
                $ctx['patient_name'] ?? '',
                $data['amount'] ?? 0,
                $actorId
            );
        } catch (Exception $e) {
            error_log('Payment::notifyDirectPayment failed: ' . $e->getMessage());
        }
    }

    /**
     * Get payment by ID
     */
    public function getById($id) {
        $sql = "SELECT pay.*, c.case_number, p.full_name as patient_name, p.patient_code,
                u1.full_name as received_by_name, u2.full_name as approved_by_name
                FROM payments pay
                LEFT JOIN cases c ON pay.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u1 ON pay.received_by = u1.id
                LEFT JOIN users u2 ON pay.approved_by = u2.id
                WHERE pay.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get payments by case
     */
    public function getByCase($caseId) {
        $sql = "SELECT pay.*, u1.full_name as received_by_name, u2.full_name as approved_by_name
                FROM payments pay
                LEFT JOIN users u1 ON pay.received_by = u1.id
                LEFT JOIN users u2 ON pay.approved_by = u2.id
                WHERE pay.case_id = :case_id
                ORDER BY pay.payment_date DESC";
        return $this->db->fetchAll($sql, ['case_id' => $caseId]);
    }

    /**
     * Get pending payments
     */
    public function getPending() {
        $sql = "SELECT pay.*, c.case_number, p.full_name as patient_name,
                u.full_name as doctor_name
                FROM payments pay
                LEFT JOIN cases c ON pay.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE pay.status = 'pending'
                ORDER BY pay.payment_date DESC";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get all payments
     */
    public function getAll($page = 1, $limit = ITEMS_PER_PAGE, $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = [];

        $sql = "SELECT pay.*, c.case_number, p.full_name as patient_name, p.patient_code,
                u.full_name as doctor_name
                FROM payments pay
                LEFT JOIN cases c ON pay.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE 1=1";

        if (!empty($filters['status'])) {
            $sql .= " AND pay.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['payment_type'])) {
            $sql .= " AND pay.payment_type = :payment_type";
            $params['payment_type'] = $filters['payment_type'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(pay.payment_date) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(pay.payment_date) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['doctor_id'])) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $filters['doctor_id'];
        }

        if (!empty($filters['search'])) {
            // Distinct placeholders — PDO with emulation off rejects re-use
            $sql .= " AND (p.full_name LIKE :search1 OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
            $params['search1'] = $params['search2'] = $params['search3'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY pay.payment_date DESC LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count payments (mirrors getAll filters)
     */
    public function getCount($filters = []) {
        $params = [];

        $sql = "SELECT COUNT(*) as total
                FROM payments pay
                LEFT JOIN cases c ON pay.case_id = c.id
                LEFT JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                WHERE 1=1";

        if (!empty($filters['status'])) {
            $sql .= " AND pay.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['payment_type'])) {
            $sql .= " AND pay.payment_type = :payment_type";
            $params['payment_type'] = $filters['payment_type'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(pay.payment_date) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(pay.payment_date) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['doctor_id'])) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $filters['doctor_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.full_name LIKE :search1 OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
            $params['search1'] = $params['search2'] = $params['search3'] = '%' . $filters['search'] . '%';
        }

        $result = $this->db->fetch($sql, $params);
        return (int)($result['total'] ?? 0);
    }

    /**
     * Get daily total
     */
    public function getDailyTotal($date = null) {
        $date = $date ?? date('Y-m-d');
        $sql = "SELECT SUM(amount) as total FROM payments
                WHERE DATE(payment_date) = :date AND status = 'approved'";
        $result = $this->db->fetch($sql, ['date' => $date]);
        return $result['total'] ?? 0;
    }

    /**
     * Get monthly total
     */
    public function getMonthlyTotal($year = null, $month = null) {
        $year = $year ?? date('Y');
        $month = $month ?? date('m');
        $sql = "SELECT SUM(amount) as total FROM payments
                WHERE YEAR(payment_date) = :year AND MONTH(payment_date) = :month
                AND status = 'approved'";
        $result = $this->db->fetch($sql, ['year' => $year, 'month' => $month]);
        return $result['total'] ?? 0;
    }

    /**
     * Get payment statistics
     */
    public function getStatistics($filters = []) {
        $stats = [];
        $params = [];
        $where = "status = 'approved'";

        if (!empty($filters['date_from'])) {
            $where .= " AND DATE(payment_date) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND DATE(payment_date) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Total by payment type
        $sql = "SELECT payment_type, SUM(amount) as total, COUNT(*) as count
                FROM payments WHERE {$where} GROUP BY payment_type";
        $byType = $this->db->fetchAll($sql, $params);
        $stats['by_type'] = [];
        foreach ($byType as $type) {
            $stats['by_type'][$type['payment_type']] = [
                'total' => $type['total'],
                'count' => $type['count']
            ];
        }

        // Total by payment method
        $sql = "SELECT payment_method, SUM(amount) as total, COUNT(*) as count
                FROM payments WHERE {$where} GROUP BY payment_method";
        $byMethod = $this->db->fetchAll($sql, $params);
        $stats['by_method'] = [];
        foreach ($byMethod as $method) {
            $stats['by_method'][$method['payment_method']] = [
                'total' => $method['total'],
                'count' => $method['count']
            ];
        }

        // Grand total
        $sql = "SELECT SUM(amount) as total, COUNT(*) as count FROM payments WHERE {$where}";
        $result = $this->db->fetch($sql, $params);
        $stats['total'] = $result['total'] ?? 0;
        $stats['count'] = $result['count'] ?? 0;

        return $stats;
    }

    /**
     * Refund payment
     */
    public function refund($id, $reason = '') {
        $payment = $this->getById($id);
        if (!$payment) {
            return ['success' => false, 'message' => 'پارەدانەکە نەدۆزرایەوە'];
        }

        try {
            $this->db->beginTransaction();

            $this->db->update('payments', ['status' => 'refunded', 'notes' => $reason], 'id = :id', ['id' => $id]);

            // Update case totals
            if ($payment['case_id']) {
                $this->caseManager->updateCaseTotals($payment['case_id']);
            }

            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
