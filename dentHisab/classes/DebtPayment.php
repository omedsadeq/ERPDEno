<?php
/**
 * Flexible Debt Payment Distribution System
 *
 * This class handles distributing payments across patient debts
 * following oldest-first order (FIFO).
 *
 * Debts include:
 * - Cases (from patient folders)
 * - X-ray requests
 *
 * Payment distribution:
 * - Payments are allocated to items in chronological order
 * - Partial payments are supported
 * - Overpayments create patient credit
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/CaseManager.php';
require_once __DIR__ . '/XrayManager.php';

class DebtPayment {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get all debt items for a patient ordered by date (oldest first)
     * Returns a unified list of cases and x-rays with remaining amounts
     */
    public function getPatientDebtItems($patientId) {
        $items = [];

        // Get case debts ordered by folder and case creation date
        $caseSql = "SELECT
                    c.id as item_id,
                    'case' as item_type,
                    c.case_number as item_number,
                    c.total_amount,
                    c.paid_amount,
                    c.discount_amount,
                    (c.total_amount - c.paid_amount - c.discount_amount) as remaining,
                    pf.id as folder_id,
                    pf.folder_name,
                    CONCAT(pf.folder_name, ' - ', c.case_number) as item_name,
                    LEAST(pf.created_at, c.created_at) as sort_date,
                    c.created_at as item_date
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.patient_id = :patient_id
                AND (c.total_amount - c.paid_amount - c.discount_amount) > 0
                ORDER BY pf.created_at ASC, c.created_at ASC";

        $caseItems = $this->db->fetchAll($caseSql, ['patient_id' => $patientId]);
        if (is_array($caseItems)) {
            foreach ($caseItems as $item) {
                $items[] = $item;
            }
        }

        // Get X-ray debts ordered by creation date
        $xraySql = "SELECT
                    xr.id as item_id,
                    'xray' as item_type,
                    xr.request_number as item_number,
                    xr.final_amount as total_amount,
                    0 as paid_amount,
                    COALESCE(xr.discount_amount, 0) as discount_amount,
                    xr.final_amount as remaining,
                    NULL as folder_id,
                    NULL as folder_name,
                    CONCAT('ئێکسڕەی - ', xr.request_number) as item_name,
                    xr.created_at as sort_date,
                    xr.created_at as item_date
                FROM xray_requests xr
                WHERE xr.patient_id = :patient_id
                AND xr.status = 'completed'
                AND xr.payment_status = 'debt'
                ORDER BY xr.created_at ASC";

        $xrayItems = $this->db->fetchAll($xraySql, ['patient_id' => $patientId]);
        if (is_array($xrayItems)) {
            foreach ($xrayItems as $item) {
                $items[] = $item;
            }
        }

        // Sort all items by date (oldest first)
        usort($items, function($a, $b) {
            return strtotime($a['sort_date']) - strtotime($b['sort_date']);
        });

        return $items;
    }

    /**
     * Get total debt amount for a patient
     */
    public function getPatientTotalDebt($patientId) {
        $items = $this->getPatientDebtItems($patientId);
        $total = 0;
        foreach ($items as $item) {
            $total += floatval($item['remaining']);
        }
        return $total;
    }

    /**
     * Preview payment distribution without actually making the payment
     * Useful for showing user how payment will be allocated
     */
    public function previewDistribution($patientId, $amount) {
        $items = $this->getPatientDebtItems($patientId);
        $paidPool = floatval($amount);
        $allocations = [];
        $totalDebt = 0;

        foreach ($items as $item) {
            $totalDebt += floatval($item['remaining']);
        }

        // Calculate allocations
        foreach ($items as $item) {
            $remaining = floatval($item['remaining']);

            if ($paidPool <= 0) {
                // No more money in pool, item unpaid
                $allocations[] = [
                    'item' => $item,
                    'allocated' => 0,
                    'remaining_after' => $remaining,
                    'status' => 'unpaid'
                ];
            } else {
                $toPay = min($paidPool, $remaining);
                $remainingAfter = $remaining - $toPay;

                $status = 'unpaid';
                if ($toPay >= $remaining) {
                    $status = 'fully_paid';
                } else if ($toPay > 0) {
                    $status = 'partial';
                }

                $allocations[] = [
                    'item' => $item,
                    'allocated' => $toPay,
                    'remaining_after' => $remainingAfter,
                    'status' => $status
                ];

                $paidPool -= $toPay;
            }
        }

        // Calculate credit if overpaid
        $credit = max(0, $paidPool);

        return [
            'total_debt' => $totalDebt,
            'payment_amount' => $amount,
            'total_allocated' => $amount - $credit,
            'credit' => $credit,
            'allocations' => $allocations
        ];
    }

    /**
     * Distribute and apply payment to patient debts
     *
     * @param int $patientId Patient ID
     * @param float $amount Payment amount
     * @param string $paymentMethod Payment method (cash, card, transfer, other)
     * @param int $receivedBy User ID who received payment
     * @param string $notes Optional notes
     * @return array Result with success status and allocation details
     */
    public function distributePayment($patientId, $amount, $paymentMethod = 'cash', $receivedBy = null, $notes = '') {
        try {
            $this->db->beginTransaction();

            $items = $this->getPatientDebtItems($patientId);
            $paidPool = floatval($amount);
            $allocations = [];
            $totalDebt = 0;

            foreach ($items as $item) {
                $totalDebt += floatval($item['remaining']);
            }

            if ($totalDebt <= 0) {
                // No debt, entire amount goes to credit
                $this->addPatientCredit($patientId, $amount, $receivedBy, $notes);
                $this->db->commit();

                return [
                    'success' => true,
                    'message' => 'هیچ قەرزێک نەبوو، پارەکە زیادکرا بە باڵانسی نەخۆش',
                    'total_paid' => 0,
                    'credit' => $amount,
                    'allocations' => []
                ];
            }

            // Log the master payment
            $masterPaymentId = $this->logMasterPayment($patientId, $amount, $paymentMethod, $receivedBy, $notes);

            // Distribute payment to items
            foreach ($items as $item) {
                if ($paidPool <= 0) break;

                $remaining = floatval($item['remaining']);
                $toPay = min($paidPool, $remaining);

                if ($toPay > 0) {
                    // Apply payment based on item type
                    if ($item['item_type'] === 'case') {
                        $this->applyCasePayment(
                            $item['item_id'],
                            $toPay,
                            $paymentMethod,
                            $receivedBy,
                            "پارەدانی قەرز (دابەشکراو) - " . $notes,
                            $masterPaymentId
                        );
                    } else if ($item['item_type'] === 'xray') {
                        $this->applyXrayPayment(
                            $item['item_id'],
                            $toPay,
                            $receivedBy,
                            "پارەدانی قەرز (دابەشکراو) - " . $notes,
                            $masterPaymentId
                        );
                    }

                    $allocations[] = [
                        'item_type' => $item['item_type'],
                        'item_id' => $item['item_id'],
                        'item_name' => $item['item_name'],
                        'amount' => $toPay,
                        'remaining_after' => $remaining - $toPay
                    ];

                    $paidPool -= $toPay;
                }
            }

            // Handle overpayment - add to patient credit
            $credit = 0;
            if ($paidPool > 0) {
                $credit = $paidPool;
                $this->addPatientCredit($patientId, $credit, $receivedBy, "زیادەی پارەدان - " . $notes);
            }

            // Update master payment with allocation details
            $this->updateMasterPaymentAllocations($masterPaymentId, $allocations, $credit);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'پارەدان بە سەرکەوتوویی تۆمارکرا',
                'master_payment_id' => $masterPaymentId,
                'total_paid' => $amount - $credit,
                'credit' => $credit,
                'allocations' => $allocations
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => 'هەڵە: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Log master payment record
     */
    private function logMasterPayment($patientId, $amount, $paymentMethod, $receivedBy, $notes) {
        $data = [
            'patient_id' => $patientId,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'received_by' => $receivedBy,
            'notes' => $notes,
            'payment_date' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ];

        return $this->db->insert('patient_debt_payments', $data);
    }

    /**
     * Update master payment with allocation details
     */
    private function updateMasterPaymentAllocations($paymentId, $allocations, $credit) {
        $data = [
            'allocations' => json_encode($allocations, JSON_UNESCAPED_UNICODE),
            'credit_amount' => $credit
        ];

        $this->db->update('patient_debt_payments', $data, 'id = :id', ['id' => $paymentId]);
    }

    /**
     * Apply payment to a case
     */
    private function applyCasePayment($caseId, $amount, $paymentMethod, $receivedBy, $notes, $masterPaymentId = null) {
        // Get case details
        $case = $this->db->fetch("SELECT * FROM cases WHERE id = :id", ['id' => $caseId]);
        if (!$case) {
            throw new Exception("کەیس نەدۆزرایەوە");
        }

        // Insert payment record
        $paymentData = [
            'case_id' => $caseId,
            'amount' => $amount,
            'payment_type' => $paymentMethod,
            'payment_date' => date('Y-m-d H:i:s'),
            'notes' => $notes,
            'received_by' => $receivedBy,
            'status' => 'approved',
            'approved_by' => $receivedBy,
            'approved_at' => date('Y-m-d H:i:s'),
            'master_payment_id' => $masterPaymentId
        ];

        $this->db->insert('payments', $paymentData);

        // Update case totals
        $caseManager = new CaseManager();
        $caseManager->updateCaseTotals($caseId);
    }

    /**
     * Apply payment to an X-ray request
     */
    private function applyXrayPayment($xrayId, $amount, $receivedBy, $notes, $masterPaymentId = null) {
        // Get xray details
        $xray = $this->db->fetch("SELECT * FROM xray_requests WHERE id = :id", ['id' => $xrayId]);
        if (!$xray) {
            throw new Exception("داواکاری ئێکسڕەی نەدۆزرایەوە");
        }

        $finalAmount = floatval($xray['final_amount']);

        // If payment covers full amount, mark as paid
        if ($amount >= $finalAmount) {
            $this->db->update('xray_requests', [
                'payment_status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'paid_by' => $receivedBy
            ], 'id = :id', ['id' => $xrayId]);
        } else {
            // Partial payment - reduce final_amount to remaining
            // Note: This is a simplified approach. For full tracking,
            // we'd need a separate xray_payments table
            $this->db->update('xray_requests', [
                'final_amount' => $finalAmount - $amount
            ], 'id = :id', ['id' => $xrayId]);
        }

        // Reuse X-ray log user resolution so production FK mismatches do not break debt payments.
        $xrayManager = new XrayManager();
        $xrayManager->createPaymentLog([
            'request_id' => $xrayId,
            'action' => 'debt_payment',
            'final_amount' => $amount,
            'notes' => $notes,
            'performed_by' => $receivedBy,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Add credit to patient account
     */
    private function addPatientCredit($patientId, $amount, $addedBy, $notes) {
        // Check if patient has credit record
        $existing = $this->db->fetch(
            "SELECT * FROM patient_credits WHERE patient_id = :patient_id",
            ['patient_id' => $patientId]
        );

        if ($existing) {
            // Update existing credit
            $newBalance = floatval($existing['balance']) + $amount;
            $this->db->update('patient_credits', [
                'balance' => $newBalance,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'patient_id = :patient_id', ['patient_id' => $patientId]);
        } else {
            // Create new credit record
            $this->db->insert('patient_credits', [
                'patient_id' => $patientId,
                'balance' => $amount,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // Log credit transaction
        $this->db->insert('patient_credit_logs', [
            'patient_id' => $patientId,
            'amount' => $amount,
            'type' => 'credit',
            'description' => $notes,
            'performed_by' => $addedBy,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get patient credit balance
     */
    public function getPatientCredit($patientId) {
        $result = $this->db->fetch(
            "SELECT balance FROM patient_credits WHERE patient_id = :patient_id",
            ['patient_id' => $patientId]
        );
        return $result ? floatval($result['balance']) : 0;
    }

    /**
     * Use patient credit toward debt
     */
    public function useCredit($patientId, $amount, $usedBy, $notes = '') {
        $currentCredit = $this->getPatientCredit($patientId);

        if ($currentCredit <= 0) {
            return ['success' => false, 'message' => 'هیچ باڵانسێک نییە'];
        }

        $toUse = min($amount, $currentCredit);

        // Apply as payment
        $result = $this->distributePayment($patientId, $toUse, 'credit', $usedBy, 'بەکارهێنانی باڵانس - ' . $notes);

        if ($result['success']) {
            // Deduct from credit
            $newBalance = $currentCredit - $toUse;
            $this->db->update('patient_credits', [
                'balance' => $newBalance,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'patient_id = :patient_id', ['patient_id' => $patientId]);

            // Log credit usage
            $this->db->insert('patient_credit_logs', [
                'patient_id' => $patientId,
                'amount' => -$toUse,
                'type' => 'debit',
                'description' => 'بەکارهێنان بۆ پارەدانی قەرز - ' . $notes,
                'performed_by' => $usedBy,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        return $result;
    }

    /**
     * Get payment history for a patient
     */
    public function getPaymentHistory($patientId, $limit = 50) {
        $sql = "SELECT pdp.*, u.full_name as received_by_name
                FROM patient_debt_payments pdp
                LEFT JOIN users u ON pdp.received_by = u.id
                WHERE pdp.patient_id = :patient_id
                ORDER BY pdp.payment_date DESC
                LIMIT {$limit}";

        return $this->db->fetchAll($sql, ['patient_id' => $patientId]);
    }

    /**
     * Get debt summary for a patient
     */
    public function getDebtSummary($patientId) {
        $items = $this->getPatientDebtItems($patientId);
        $credit = $this->getPatientCredit($patientId);

        $totalDebt = 0;
        $caseDebt = 0;
        $xrayDebt = 0;
        $itemCount = count($items);

        foreach ($items as $item) {
            $remaining = floatval($item['remaining']);
            $totalDebt += $remaining;

            if ($item['item_type'] === 'case') {
                $caseDebt += $remaining;
            } else {
                $xrayDebt += $remaining;
            }
        }

        return [
            'total_debt' => $totalDebt,
            'case_debt' => $caseDebt,
            'xray_debt' => $xrayDebt,
            'credit_balance' => $credit,
            'net_debt' => max(0, $totalDebt - $credit),
            'item_count' => $itemCount,
            'items' => $items
        ];
    }
}
