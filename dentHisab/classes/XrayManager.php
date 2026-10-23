<?php
/**
 * X-Ray Manager Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/S3Uploader.php';
require_once __DIR__ . '/PatientFlow.php';

class XrayManager {
    private $db;
    private $uploadDir;
    private $maxFileSize;
    private $allowedExtensions;
    private $s3Uploader;
    private $useS3;
    private $paymentLogUserTable = null;
    private $paymentLogUserTableColumns = [];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->uploadDir = APP_ROOT . '/uploads/xray/';
        $this->maxFileSize = 10 * 1024 * 1024; // 10MB for x-ray images
        $this->allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];

        // Check if S3 is configured
        $this->useS3 = !empty($_ENV['S3_ACCESS_KEY'] ?? getenv('S3_ACCESS_KEY'));

        if ($this->useS3) {
            $this->s3Uploader = S3Uploader::getInstance();
        }

        // Create upload directory if it doesn't exist (for local fallback)
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    // ── Income helpers ─────────────────────────────────────────────────
    // X-ray money is NOT stored in the `payments` table — cash settlements
    // live in xray_requests and debt collections in xray_debt_payments. The
    // accountant income reports must add these explicitly or X-ray revenue is
    // invisible. Dates are inclusive 'Y-m-d' strings; pass null for all-time.

    /** Cash income from fully-paid X-ray requests in the range. */
    public function getCashIncome($dateFrom = null, $dateTo = null) {
        $sql = "SELECT COALESCE(SUM(final_amount),0) AS t FROM xray_requests WHERE payment_status = 'paid'";
        // Requests that went through the debt channel are settled row-by-row in
        // xray_debt_payments and counted by getDebtCollected(); counting their
        // final_amount here too would double the income on the payoff day.
        if ($this->db->tableExists('patient_xray_debts')) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM patient_xray_debts pxd WHERE pxd.request_id = xray_requests.id)";
        }
        $params = [];
        if ($dateFrom) { $sql .= " AND DATE(COALESCE(paid_at, created_at)) >= :df"; $params['df'] = $dateFrom; }
        if ($dateTo)   { $sql .= " AND DATE(COALESCE(paid_at, created_at)) <= :dt"; $params['dt'] = $dateTo; }
        return (float)($this->db->fetch($sql, $params)['t'] ?? 0);
    }

    /** Money actually collected against X-ray debts in the range. */
    public function getDebtCollected($dateFrom = null, $dateTo = null) {
        if (!$this->db->tableExists('xray_debt_payments')) {
            return 0.0;
        }
        $sql = "SELECT COALESCE(SUM(amount),0) AS t FROM xray_debt_payments WHERE 1=1";
        $params = [];
        if ($dateFrom) { $sql .= " AND DATE(created_at) >= :df"; $params['df'] = $dateFrom; }
        if ($dateTo)   { $sql .= " AND DATE(created_at) <= :dt"; $params['dt'] = $dateTo; }
        return (float)($this->db->fetch($sql, $params)['t'] ?? 0);
    }

    /** Total X-ray income (cash + debt collections) in the range. */
    public function getIncome($dateFrom = null, $dateTo = null) {
        return $this->getCashIncome($dateFrom, $dateTo) + $this->getDebtCollected($dateFrom, $dateTo);
    }

    /** X-ray income grouped by month: ['YYYY-MM' => total] since $startDate. */
    public function getMonthlyIncome($startDate) {
        $out = [];
        $sql = "SELECT DATE_FORMAT(COALESCE(paid_at, created_at),'%Y-%m') AS m, COALESCE(SUM(final_amount),0) AS t
                FROM xray_requests
                WHERE payment_status = 'paid' AND COALESCE(paid_at, created_at) >= :start";
        if ($this->db->tableExists('patient_xray_debts')) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM patient_xray_debts pxd WHERE pxd.request_id = xray_requests.id)";
        }
        $sql .= " GROUP BY m";
        foreach ($this->db->fetchAll($sql, ['start' => $startDate]) as $r) {
            $out[$r['m']] = ($out[$r['m']] ?? 0) + (float)$r['t'];
        }
        if ($this->db->tableExists('xray_debt_payments')) {
            $rows = $this->db->fetchAll(
                "SELECT DATE_FORMAT(created_at,'%Y-%m') AS m, COALESCE(SUM(amount),0) AS t
                 FROM xray_debt_payments WHERE created_at >= :start GROUP BY m",
                ['start' => $startDate]
            );
            foreach ($rows as $r) {
                $out[$r['m']] = ($out[$r['m']] ?? 0) + (float)$r['t'];
            }
        }
        return $out;
    }

    /**
     * Total outstanding X-ray debt across all patients. Partial collections
     * recorded in patient_xray_debts reduce the total (remaining_amount), and
     * cancelled requests are excluded; requests still awaiting debt approval
     * (no debt record yet) count at full value.
     */
    public function getOutstandingDebt() {
        if ($this->db->tableExists('patient_xray_debts')) {
            $sql = "SELECT COALESCE(SUM(CASE WHEN d.request_id IS NULL THEN xr.final_amount ELSE d.rem END),0) AS t
                    FROM xray_requests xr
                    LEFT JOIN (
                        SELECT request_id,
                               SUM(CASE WHEN status IN ('pending','partial') THEN remaining_amount ELSE 0 END) AS rem
                        FROM patient_xray_debts GROUP BY request_id
                    ) d ON d.request_id = xr.id
                    WHERE xr.payment_status IN ('debt', 'debt_pending', 'debt_approved')
                      AND xr.status != 'cancelled'";
        } else {
            $sql = "SELECT COALESCE(SUM(final_amount),0) AS t FROM xray_requests
                    WHERE payment_status IN ('debt', 'debt_pending', 'debt_approved')
                      AND status != 'cancelled'";
        }
        return (float)($this->db->fetch($sql)['t'] ?? 0);
    }

    /**
     * Generate unique request number
     */
    private function generateRequestNumber() {
        $year = date('Y');
        $month = date('m');
        $prefix = "XR{$year}{$month}";

        $sql = "SELECT MAX(CAST(SUBSTRING(request_number, 9) AS UNSIGNED)) as max_num
                FROM xray_requests WHERE request_number LIKE :prefix";
        $result = $this->db->fetch($sql, ['prefix' => $prefix . '%']);
        $nextNum = ($result['max_num'] ?? 0) + 1;

        return $prefix . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create new X-Ray request
     */
    public function createRequest($data) {
        $data['request_number'] = $this->generateRequestNumber();
        $data['status'] = 'pending';

        try {
            $this->db->beginTransaction();

            $requestId = $this->db->insert('xray_requests', $data);

            // Create notification for Accountant/Admin users
            $this->notifyXrayDepartment($requestId);

            $this->db->commit();

            // Mirror the initial route (accountant vs x-ray) onto the waiting-room
            // queue. If the patient has no check-in today, syncFromXray registers
            // them in the waiting room so they are called in turn — free (dont_cash)
            // requests queue at x-ray, paid/debt ones at the accountant first.
            if (!empty($data['request_status'])) {
                PatientFlow::syncFromXray(
                    (int)($data['patient_id'] ?? 0),
                    $data['request_status'],
                    isset($data['requested_by']) ? (int)$data['requested_by'] : null,
                    'X-Ray request ' . $data['request_number'],
                    isset($data['requested_by']) ? (int)$data['requested_by'] : null
                );
            }

            return [
                'success' => true,
                'request_id' => $requestId,
                'request_number' => $data['request_number']
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update X-Ray request
     */
    public function updateRequest($id, $data) {
        try {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('xray_requests', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update request status
     */
    public function updateStatus($id, $status, $userId = null) {
        $data = ['status' => $status];

        if ($status === 'completed') {
            $data['completed_by'] = $userId;
            $data['completed_at'] = date('Y-m-d H:i:s');
        }

        return $this->updateRequest($id, $data);
    }

    /**
     * Legacy status mirrors the detailed workflow state for older pages.
     */
    private function getLegacyStatusForWorkflow($requestStatus) {
        if ($requestStatus === 'completed') {
            return 'completed';
        }
        if ($requestStatus === 'cancelled') {
            return 'cancelled';
        }
        if ($requestStatus === 'in_xray' || $requestStatus === 'in_progress') {
            return 'in_progress';
        }
        return 'pending';
    }

    /**
     * Central workflow transition with history tracking.
     */
    public function transitionWorkflow($requestId, $newRequestStatus, $newPaymentStatus, $actionType, $actionNote, $userId, $role, array $extraData = []) {
        $requestId = intval($requestId);
        if ($requestId <= 0) {
            return ['success' => false, 'message' => 'Invalid request ID'];
        }

        try {
            $request = $this->db->fetch(
                "SELECT id, patient_id, requested_by, request_status, payment_status FROM xray_requests WHERE id = :id",
                ['id' => $requestId]
            );

            if (!$request) {
                return ['success' => false, 'message' => 'X-Ray request not found'];
            }

            $oldRequestStatus = $request['request_status'] ?? null;
            $oldPaymentStatus = $request['payment_status'] ?? null;
            $effectivePaymentStatus = $newPaymentStatus === null ? $oldPaymentStatus : $newPaymentStatus;

            $data = array_merge([
                'request_status' => $newRequestStatus,
                'payment_status' => $effectivePaymentStatus,
                'status' => $this->getLegacyStatusForWorkflow($newRequestStatus),
                'last_updated_at' => date('Y-m-d H:i:s')
            ], $extraData);

            $this->db->update('xray_requests', $data, 'id = :id', ['id' => $requestId]);
            $this->logHistory(
                $requestId,
                $oldRequestStatus,
                $newRequestStatus,
                $oldPaymentStatus,
                $effectivePaymentStatus,
                $actionType,
                $actionNote,
                $userId,
                $role
            );

            if ($newRequestStatus === 'pending_xray') {
                $this->notifyXrayDepartmentToScan($requestId);
            } elseif ($newRequestStatus === 'completed') {
                $this->notifyCompletedRequest($requestId);
            }

            // Keep the waiting-room queue in sync with the x-ray stage (creates
            // the waiting-room entry when the patient has no check-in today)
            PatientFlow::syncFromXray(
                (int)($request['patient_id'] ?? 0),
                $newRequestStatus,
                $userId ? (int)$userId : null,
                trim($actionType . ($actionNote !== '' && $actionNote !== null ? ': ' . $actionNote : '')),
                !empty($request['requested_by']) ? (int)$request['requested_by'] : null
            );

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function approvePaymentAndSendToXray($requestId, $userId, $role, $note = '') {
        $now = date('Y-m-d H:i:s');
        $result = $this->transitionWorkflow($requestId, 'pending_xray', 'paid', 'payment_collected', $note, $userId, $role, [
            'accountant_id' => $userId,
            'accountant_approved_at' => $now,
            'paid_at' => $now,
            'paid_by' => $userId,
            'settled_at' => $now,
            'settled_by' => $userId,
            'xray_received_at' => $now
        ]);

        if (!empty($result['success'])) {
            try {
                $request = $this->getById($requestId);
                if ($request) {
                    $finalAmount = floatval($request['final_amount'] ?: $request['amount']);
                    $this->logPaymentAction($requestId, 'paid_cash', [
                        'original_amount' => $request['original_amount'] ?: $request['amount'],
                        'discount_amount' => $request['discount_amount'],
                        'final_amount' => $finalAmount,
                        'notes' => $note
                    ], $userId);
                }
            } catch (Exception $e) {
                // Workflow history is the primary audit trail; payment logs are best-effort here.
            }
        }

        return $result;
    }

    public function approveDebtAndSendToXray($requestId, $userId, $role, $note = '') {
        $now = date('Y-m-d H:i:s');
        $result = $this->transitionWorkflow($requestId, 'pending_xray', 'debt_approved', 'debt_approved', $note, $userId, $role, [
            'accountant_id' => $userId,
            'accountant_approved_at' => $now,
            'settled_at' => $now,
            'settled_by' => $userId,
            'xray_received_at' => $now
        ]);

        if (!empty($result['success'])) {
            try {
                $request = $this->getById($requestId);
                if ($request) {
                    $finalAmount = floatval($request['final_amount'] ?: $request['amount']);
                    $this->logPaymentAction($requestId, 'paid_debt', [
                        'original_amount' => $request['original_amount'] ?: $request['amount'],
                        'discount_amount' => $request['discount_amount'],
                        'final_amount' => $finalAmount,
                        'notes' => $note
                    ], $userId);
                }
            } catch (Exception $e) {
                // Workflow history is the primary audit trail; payment logs are best-effort here.
            }
        }

        return $result;
    }

    public function sendAgainToXray($requestId, $userId, $role, $note = '') {
        $now = date('Y-m-d H:i:s');
        return $this->transitionWorkflow($requestId, 'pending_xray', null, 'sent_to_xray', $note, $userId, $role, [
            'accountant_id' => $userId,
            'accountant_approved_at' => $now,
            'xray_received_at' => $now
        ]);
    }

    public function markRefunded($requestId, $userId, $role, $note = '') {
        return $this->transitionWorkflow($requestId, 'returned_to_accountant', 'refunded', 'refunded', $note, $userId, $role, [
            'refunded_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function correctAmount($requestId, $amount, $userId, $role, $note = '') {
        $amount = max(0, floatval($amount));
        try {
            $request = $this->db->fetch(
                "SELECT request_status, payment_status FROM xray_requests WHERE id = :id",
                ['id' => intval($requestId)]
            );
            if (!$request) {
                return ['success' => false, 'message' => 'X-Ray request not found'];
            }

            $this->db->update('xray_requests', [
                'amount' => $amount,
                'original_amount' => $amount,
                'discount_amount' => 0,
                'discount_reason' => null,
                'final_amount' => $amount,
                'last_updated_at' => date('Y-m-d H:i:s')
            ], 'id = :id', ['id' => intval($requestId)]);

            $this->logHistory(
                intval($requestId),
                $request['request_status'],
                $request['request_status'],
                $request['payment_status'],
                $request['payment_status'],
                'amount_corrected',
                "Amount corrected to {$amount}. " . $note,
                $userId,
                $role
            );

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function cancelWorkflowRequest($requestId, $userId, $role, $reason = '') {
        return $this->transitionWorkflow($requestId, 'cancelled', null, 'cancelled', $reason, $userId, $role, [
            'cancel_reason' => $reason,
            'cancelled_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function startXray($requestId, $userId, $role, $note = '') {
        return $this->transitionWorkflow($requestId, 'in_xray', null, 'xray_started', $note, $userId, $role, [
            'xray_started_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function completeXray($requestId, $userId, $role, $notes = '') {
        $extra = [
            'completed_by' => $userId,
            'completed_at' => date('Y-m-d H:i:s'),
            'xray_completed_at' => date('Y-m-d H:i:s')
        ];

        if ($notes !== '') {
            $request = $this->getById($requestId);
            $existingNotes = $request['notes'] ?? '';
            $extra['notes'] = $existingNotes . ($existingNotes ? "\n---\n" : '') . "Completion notes: " . $notes;
        }

        return $this->transitionWorkflow($requestId, 'completed', null, 'xray_completed', $notes, $userId, $role, $extra);
    }

    public function returnToAccounting($requestId, $reason, $userId, $role) {
        return $this->transitionWorkflow($requestId, 'returned_to_accountant', null, 'returned_to_accountant', $reason, $userId, $role, [
            'return_reason' => $reason,
            'returned_to_accountant_at' => date('Y-m-d H:i:s'),
            'returned_by_xray_user_id' => $userId
        ]);
    }

    /**
     * Get request by ID with full details
     */
    public function getById($id) {
        $sql = "SELECT xr.*,
                p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                p.gender, p.date_of_birth, p.date_of_birth as patient_dob,
                u1.full_name as requested_by_name,
                u2.full_name as completed_by_name,
                u3.full_name as accountant_approved_by_name,
                u4.full_name as returned_by_xray_name,
                c.case_number
                FROM xray_requests xr
                LEFT JOIN patients p ON xr.patient_id = p.id
                LEFT JOIN users u1 ON xr.requested_by = u1.id
                LEFT JOIN users u2 ON xr.completed_by = u2.id
                LEFT JOIN users u3 ON xr.accountant_id = u3.id
                LEFT JOIN users u4 ON xr.returned_by_xray_user_id = u4.id
                LEFT JOIN cases c ON xr.case_id = c.id
                WHERE xr.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get request by number
     */
    public function getByNumber($requestNumber) {
        $sql = "SELECT * FROM xray_requests WHERE request_number = :request_number";
        return $this->db->fetch($sql, ['request_number' => $requestNumber]);
    }

    /**
     * Get all requests with filters
     */
    public function getAll($page = 1, $limit = ITEMS_PER_PAGE, $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = [];

        $sql = "SELECT xr.*,
                p.full_name as patient_name, p.patient_code,
                u.full_name as requested_by_name
                FROM xray_requests xr
                LEFT JOIN patients p ON xr.patient_id = p.id
                LEFT JOIN users u ON xr.requested_by = u.id
                WHERE 1=1";

        // Support both 'status' and 'request_status' filters for backward compatibility
        if (!empty($filters['status'])) {
            $sql .= " AND (xr.status = :status OR xr.request_status = :status)";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['request_status'])) {
            $sql .= " AND xr.request_status = :request_status";
            $params['request_status'] = $filters['request_status'];
        }

        if (!empty($filters['request_status_in']) && is_array($filters['request_status_in'])) {
            $placeholders = [];
            foreach (array_values($filters['request_status_in']) as $idx => $value) {
                $key = 'request_status_in_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $value;
            }
            $sql .= " AND xr.request_status IN (" . implode(',', $placeholders) . ")";
        }

        if (!empty($filters['xray_type'])) {
            $sql .= " AND xr.xray_type = :xray_type";
            $params['xray_type'] = $filters['xray_type'];
        }

        if (!empty($filters['requested_by'])) {
            $sql .= " AND xr.requested_by = :requested_by";
            $params['requested_by'] = $filters['requested_by'];
        }

        if (!empty($filters['patient_id'])) {
            $sql .= " AND xr.patient_id = :patient_id";
            $params['patient_id'] = $filters['patient_id'];
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND xr.priority = :priority";
            $params['priority'] = $filters['priority'];
        }

        if (!empty($filters['payment_status'])) {
            $sql .= " AND xr.payment_status = :payment_status";
            $params['payment_status'] = $filters['payment_status'];
        }

        if (!empty($filters['payment_type'])) {
            $sql .= " AND xr.payment_type = :payment_type";
            $params['payment_type'] = $filters['payment_type'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(xr.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(xr.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.full_name LIKE :search OR xr.request_number LIKE :search2 OR CAST(xr.id AS CHAR) LIKE :search4 OR p.phone LIKE :search3)";
            $params['search'] = "%{$filters['search']}%";
            $params['search2'] = "%{$filters['search']}%";
            $params['search3'] = "%{$filters['search']}%";
            $params['search4'] = "%{$filters['search']}%";
        }

        // 'queue' = the x-ray room's turn order: urgent cases jump ahead,
        // everyone else strictly first-come-first-served. Default (history
        // and admin lists) stays newest-first.
        if (($filters['order'] ?? '') === 'queue') {
            $sql .= " ORDER BY (xr.priority = 'urgent') DESC, xr.created_at ASC LIMIT {$limit} OFFSET {$offset}";
        } else {
            $sql .= " ORDER BY xr.created_at DESC LIMIT {$limit} OFFSET {$offset}";
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get pending requests for X-Ray department
     */
    public function getPendingRequests() {
        return $this->getAll(1, 1000, ['status' => 'pending']);
    }

    /**
     * Get requests by doctor
     */
    public function getByDoctor($doctorId, $status = null, $page = 1, $limit = ITEMS_PER_PAGE) {
        $filters = ['requested_by' => $doctorId];
        if ($status) {
            $filters['status'] = $status;
        }
        return $this->getAll($page, $limit, $filters);
    }

    /**
     * Get requests by patient
     */
    public function getByPatient($patientId) {
        $sql = "SELECT xr.*, u.full_name as requested_by_name
                FROM xray_requests xr
                LEFT JOIN users u ON xr.requested_by = u.id
                WHERE xr.patient_id = :patient_id
                ORDER BY xr.created_at DESC";
        return $this->db->fetchAll($sql, ['patient_id' => $patientId]);
    }

    /**
     * Get patient's total X-ray debt (unpaid X-rays)
     * This is separate from case/folder debts
     */
    public function getPatientXrayDebt($patientId) {
        $sql = "SELECT COALESCE(SUM(final_amount), 0) as total_debt
                FROM xray_requests
                WHERE patient_id = :patient_id
                AND status = 'completed'
                AND payment_status IN ('debt', 'debt_pending', 'debt_approved')";
        $result = $this->db->fetch($sql, ['patient_id' => $patientId]);
        return floatval($result['total_debt'] ?? 0);
    }

    /**
     * Get patient's total paid X-rays
     */
    public function getPatientXrayPaid($patientId) {
        $sql = "SELECT COALESCE(SUM(final_amount), 0) as total_paid
                FROM xray_requests
                WHERE patient_id = :patient_id
                AND status = 'completed'
                AND payment_status = 'paid'";
        $result = $this->db->fetch($sql, ['patient_id' => $patientId]);
        return floatval($result['total_paid'] ?? 0);
    }

    /**
     * Count requests
     */
    public function count($filters = []) {
        $params = [];
        $sql = "SELECT COUNT(*) as count FROM xray_requests xr
                LEFT JOIN patients p ON xr.patient_id = p.id
                WHERE 1=1";

        // Support both 'status' and 'request_status' filters for backward compatibility
        if (!empty($filters['status'])) {
            $sql .= " AND (xr.status = :status OR xr.request_status = :status)";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['request_status'])) {
            $sql .= " AND xr.request_status = :request_status";
            $params['request_status'] = $filters['request_status'];
        }

        if (!empty($filters['request_status_in']) && is_array($filters['request_status_in'])) {
            $placeholders = [];
            foreach (array_values($filters['request_status_in']) as $idx => $value) {
                $key = 'request_status_in_' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $value;
            }
            $sql .= " AND xr.request_status IN (" . implode(',', $placeholders) . ")";
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND xr.priority = :priority";
            $params['priority'] = $filters['priority'];
        }
        
        if (!empty($filters['payment_status'])) {
            $sql .= " AND xr.payment_status = :payment_status";
            $params['payment_status'] = $filters['payment_status'];
        }

        if (!empty($filters['payment_type'])) {
            $sql .= " AND xr.payment_type = :payment_type";
            $params['payment_type'] = $filters['payment_type'];
        }

        if (!empty($filters['requested_by'])) {
            $sql .= " AND xr.requested_by = :requested_by";
            $params['requested_by'] = $filters['requested_by'];
        }

        if (!empty($filters['xray_type'])) {
            $sql .= " AND xr.xray_type = :xray_type";
            $params['xray_type'] = $filters['xray_type'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(xr.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(xr.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.full_name LIKE :search OR xr.request_number LIKE :search2 OR CAST(xr.id AS CHAR) LIKE :search3)";
            $params['search'] = "%{$filters['search']}%";
            $params['search2'] = "%{$filters['search']}%";
            $params['search3'] = "%{$filters['search']}%";
        }

        return $this->db->fetch($sql, $params)['count'];
    }

    /**
     * Upload X-Ray image
     * Uploads to S3 (iDrive e2) if configured, otherwise falls back to local storage
     */
    public function uploadImage($requestId, $file, $userId, $description = '') {
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'فایلێک هەڵنەبژێردراوە'];
        }

        if ($file['size'] > $this->maxFileSize) {
            return ['success' => false, 'message' => 'قەبارەی فایل زۆر گەورەیە (زۆرترین: 10MB)'];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            return ['success' => false, 'message' => 'جۆری فایل ڕێگەپێدراو نییە'];
        }

        // Generate unique filename
        $filename = uniqid() . '_' . time() . '.' . $extension;

        // Upload to S3 or local storage
        if ($this->useS3) {
            // Upload to S3 (iDrive e2)
            $s3Folder = 'xray/' . date('Y/m'); // Organize by year/month
            $uploadResult = $this->s3Uploader->uploadFromPost($file, $s3Folder, $filename);

            if (!$uploadResult['success']) {
                return ['success' => false, 'message' => $uploadResult['message'] ?? 'هەڵە لە بارکردنی فایل بۆ S3'];
            }

            $imagePath = $uploadResult['url']; // Full S3 URL
            $s3Key = $uploadResult['key']; // S3 key for deletion
        } else {
            // Fallback to local storage
            $filepath = $this->uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                return ['success' => false, 'message' => 'هەڵە لە بارکردنی فایل'];
            }
            $imagePath = 'uploads/xray/' . $filename;
            $s3Key = null;
        }

        // Insert into database
        $imageData = [
            'request_id' => $requestId,
            'image_path' => $imagePath,
            'image_type' => $extension,
            'original_filename' => $file['name'],
            'file_size' => $file['size'],
            'description' => $description,
            'uploaded_by' => $userId,
            's3_key' => $s3Key // Store S3 key for deletion
        ];

        try {
            $imageId = $this->db->insert('xray_images', $imageData);
            return [
                'success' => true,
                'image_id' => $imageId,
                'filename' => $filename,
                'url' => $imagePath,
                's3_key' => $s3Key
            ];
        } catch (Exception $e) {
            // Delete uploaded file if database insert fails
            if ($this->useS3 && $s3Key) {
                $this->s3Uploader->deleteFile($s3Key);
            } elseif (!$this->useS3 && isset($filepath) && file_exists($filepath)) {
                unlink($filepath);
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get images for a request
     */
    public function getImages($requestId) {
        $sql = "SELECT xi.*, u.full_name as uploaded_by_name
                FROM xray_images xi
                LEFT JOIN users u ON xi.uploaded_by = u.id
                WHERE xi.request_id = :request_id
                ORDER BY xi.created_at DESC";
        return $this->db->fetchAll($sql, ['request_id' => $requestId]);
    }

    /**
     * Delete image
     * Deletes from S3 or local storage based on where it was stored
     */
    public function deleteImage($imageId, $expectedRequestId = null) {
        // Scope by request so one request cannot delete another request's image
        $sql = "SELECT image_path, s3_key, request_id FROM xray_images WHERE id = :id";
        $image = $this->db->fetch($sql, ['id' => $imageId]);

        if ($image && $expectedRequestId !== null && (int)$image['request_id'] !== (int)$expectedRequestId) {
            return ['success' => false, 'message' => 'Not allowed'];
        }

        if ($image) {
            // Check if it's an S3 file
            if (!empty($image['s3_key']) && $this->useS3) {
                // Delete from S3
                $this->s3Uploader->deleteFile($image['s3_key']);
            } elseif (strpos($image['image_path'], 'http') !== 0) {
                // Delete local file (only if it's a relative path, not a URL)
                $filepath = APP_ROOT . '/' . $image['image_path'];
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
            }

            // Delete database record
            $this->db->delete('xray_images', 'id = :id', ['id' => $imageId]);
            return ['success' => true];
        }

        return ['success' => false, 'message' => 'وێنە نەدۆزرایەوە'];
    }

    /**
     * Cancel request
     */
    public function cancelRequest($id) {
        return $this->updateStatus($id, 'cancelled');
    }

    /**
     * Complete request
     */
    public function completeRequest($id, $userId, $notes = '') {
        $data = [
            'status' => 'completed',
            'completed_by' => $userId,
            'completed_at' => date('Y-m-d H:i:s')
        ];

        if (!empty($notes)) {
            $request = $this->getById($id);
            $existingNotes = $request['notes'] ?? '';
            $data['notes'] = $existingNotes . ($existingNotes ? "\n---\n" : '') . "تێبینی تەواوبوون: " . $notes;
        }

        $result = $this->updateRequest($id, $data);

        if ($result['success']) {
            // Notify requesting doctor
            $this->notifyDoctor($id);
        }

        return $result;
    }

    /**
     * Get statistics
     */
    public function getStatistics($doctorId = null, $dateFrom = null, $dateTo = null) {
        $stats = [];
        $params = [];
        $where = "1=1";

        if ($doctorId) {
            $where .= " AND requested_by = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        if ($dateFrom) {
            $where .= " AND DATE(created_at) >= :date_from";
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= " AND DATE(created_at) <= :date_to";
            $params['date_to'] = $dateTo;
        }

        // Count by status (checking both status and request_status for backward compatibility)
        $sql = "SELECT 
                    COALESCE(NULLIF(request_status, ''), status) as status_key,
                    COUNT(*) as count 
                FROM xray_requests 
                WHERE {$where} 
                GROUP BY status_key";
        $byStatus = $this->db->fetchAll($sql, $params);
        $stats['by_status'] = [];
        foreach ($byStatus as $status) {
            $stats['by_status'][$status['status_key']] = $status['count'];
        }

        // Count by type
        $sql = "SELECT xray_type, COUNT(*) as count FROM xray_requests WHERE {$where} GROUP BY xray_type";
        $byType = $this->db->fetchAll($sql, $params);
        $stats['by_type'] = [];
        foreach ($byType as $type) {
            $stats['by_type'][$type['xray_type']] = $type['count'];
        }

        // Total
        $sql = "SELECT COUNT(*) as total FROM xray_requests WHERE {$where}";
        $stats['total'] = $this->db->fetch($sql, $params)['total'];

        // Today's count
        $todayParams = $params;
        $todayWhere = $where . " AND DATE(created_at) = CURDATE()";
        $sql = "SELECT COUNT(*) as today FROM xray_requests WHERE {$todayWhere}";
        $stats['today'] = $this->db->fetch($sql, $todayParams)['today'];

        // Pending count (checking pending_xray status for new workflow)
        $sql = "SELECT COUNT(*) as pending FROM xray_requests WHERE (request_status = 'pending_xray' OR status = 'pending')";
        if ($doctorId) {
            $sql .= " AND requested_by = :doctor_id";
            $stats['pending'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['pending'];
        } else {
            $stats['pending'] = $this->db->fetch($sql)['pending'];
        }

        // Total amount (for statistics)
        $sql = "SELECT COALESCE(SUM(amount), 0) as total_amount FROM xray_requests WHERE {$where}";
        $stats['total_amount'] = $this->db->fetch($sql, $params)['total_amount'];

        return $stats;
    }

    /**
     * Notify X-Ray department about new request
     */
    private function notifyXrayDepartment($requestId) {
        $request = $this->getById($requestId);
        if (!$request) {
            return;
        }

        $targetRoles = ['admin'];
        if (($request['request_status'] ?? '') === 'pending_xray') {
            $targetRoles[] = 'xray_department';
        } else {
            $targetRoles[] = 'accountant';
        }

        $rolePlaceholders = implode(',', array_fill(0, count($targetRoles), '?'));
        $usersToNotify = $this->db->fetchAll(
            "SELECT id FROM users WHERE role IN ({$rolePlaceholders}) AND is_active = 1",
            $targetRoles
        );

        if (empty($usersToNotify)) {
            return; // No users to notify
        }

        $goesToAccounting = ($request['request_status'] ?? '') === 'pending_accountant';
        $notificationTitle = $goesToAccounting ? 'داواکاری ئێکسڕەی بۆ ژمێریار' : 'داواکاری ئێکسڕەی نوێ';
        $notificationMessage = $goesToAccounting
            ? "دکتۆر داواکاری ئێکسڕەی {$request['request_number']} بۆ {$request['patient_name']} ناردووە بۆ پشتڕاستکردنەوەی پارەدان."
            : "داواکاری ئێکسڕەی نوێ {$request['request_number']} بۆ {$request['patient_name']} ئامادەیە.";
        $notificationLink = $goesToAccounting
            ? APP_URL . '/accountant/pending-reports.php?tab=xray&xray_search=' . urlencode($request['request_number'])
            : APP_URL . '/xray/view.php?id=' . $requestId;

        // Deduplicate by user id
        $notifiedIds = [];
        foreach ($usersToNotify as $user) {
            if (in_array($user['id'], $notifiedIds)) continue;
            $notifiedIds[] = $user['id'];

            $notificationData = [
                'user_id' => $user['id'],
                'type' => 'xray',
                'title' => $notificationTitle,
                'message' => $notificationMessage,
                'link' => $notificationLink
            ];

            try {
                $this->db->insert('notifications', $notificationData);
            } catch (Exception $e) {
                // Silently fail for notifications
            }
        }
    }

    /**
     * Notify doctor about completed request
     */
    private function notifyDoctor($requestId) {
        $request = $this->getById($requestId);

        if ($request) {
            $notificationData = [
                'user_id' => $request['requested_by'],
                'type' => 'xray',
                'title' => 'ئێکسڕەی تەواوبوو',
                'message' => "ئێکسڕەی {$request['request_number']} تەواوبوو",
                'link' => APP_URL . '/xray/view.php?id=' . $requestId
            ];

            try {
                $this->db->insert('notifications', $notificationData);
            } catch (Exception $e) {
                // Silently fail for notifications
            }
        }
    }

    /**
     * Notify X-Ray department to start scanning after accountant processes payment
     * Workflow: Doctor → Accountant (payment) → X-Ray Dept (scan)
     */
    private function notifyXrayDepartmentToScan($requestId) {
        $sql = "SELECT id FROM users WHERE role = 'xray_department' AND is_active = 1";
        $xrayUsers = $this->db->fetchAll($sql);

        if (empty($xrayUsers)) {
            return;
        }

        $request = $this->getById($requestId);

        foreach ($xrayUsers as $user) {
            try {
                $this->db->insert('notifications', [
                    'user_id' => $user['id'],
                    'type' => 'xray',
                    'title' => 'ئێکسڕەی ئامادەی سکان',
                    'message' => "پارەدانی ئێکسڕەی {$request['request_number']} بۆ {$request['patient_name']} تەواوبوو - تکایە سکانەکە دەست پێبکە",
                    'link' => APP_URL . '/xray/view.php?id=' . $requestId
                ]);
            } catch (Exception $e) {
                // Silently fail for notifications
            }
        }
    }

    /**
     * Get recent requests for dashboard
     */
    public function getRecent($limit = 5) {
        $sql = "SELECT xr.*,
                p.full_name as patient_name,
                u.full_name as requested_by_name
                FROM xray_requests xr
                LEFT JOIN patients p ON xr.patient_id = p.id
                LEFT JOIN users u ON xr.requested_by = u.id
                ORDER BY xr.created_at DESC
                LIMIT {$limit}";
        return $this->db->fetchAll($sql);
    }

    // ==========================================
    // Payment Workflow Methods
    // ==========================================

    /**
     * Complete X-ray scan (imaging done by X-ray department)
     * Workflow: Payment is already handled by Accountant before scan starts
     * X-ray dept only marks the imaging as completed here
     */
    public function completeAndAwaitPayment($id, $userId, $notes = '') {
        try {
            $this->db->beginTransaction();

            $request = $this->getById($id);
            if (!$request) {
                throw new Exception('داواکاری نەدۆزرایەوە');
            }

            // Only update imaging status to completed - payment already handled by accountant
            $data = [
                'status' => 'completed',
                'completed_by' => $userId,
                'completed_at' => date('Y-m-d H:i:s')
            ];

            if (!empty($notes)) {
                $existingNotes = $request['notes'] ?? '';
                $data['notes'] = $existingNotes . ($existingNotes ? "\n---\n" : '') . "تێبینی تەواوبوون: " . $notes;
            }

            $this->db->update('xray_requests', $data, 'id = :id', ['id' => $id]);

            // Log the completion
            $this->logPaymentAction($id, 'completed', [
                'notes' => 'سکانی ئێکسڕەی تەواوبوو. ' . $notes
            ], $userId);

            // Notify doctor about completion
            $this->notifyCompletedRequest($id);

            $this->db->commit();
            return ['success' => true, 'message' => 'سکانی ئێکسڕەی تەواوبوو'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Apply discount to request (Admin only)
     */
    public function applyDiscount($id, $discountAmount, $discountReason, $userId) {
        try {
            $this->db->beginTransaction();

            $request = $this->getById($id);
            if (!$request) {
                throw new Exception('داواکاری نەدۆزرایەوە');
            }

            $originalAmount = floatval($request['original_amount'] ?: $request['amount']);
            $discountAmount = floatval($discountAmount);

            if ($discountAmount > $originalAmount) {
                throw new Exception('داشکاندن نابێت لە نرخی سەرەتایی زیاتر بێت');
            }

            $finalAmount = $originalAmount - $discountAmount;

            $data = [
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'discount_reason' => $discountReason,
                'final_amount' => $finalAmount,
                'last_updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('xray_requests', $data, 'id = :id', ['id' => $id]);

            // Log the discount
            $this->logPaymentAction($id, 'discount_applied', [
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'notes' => $discountReason
            ], $userId);

            $this->logHistory(
                $id,
                $request['request_status'] ?? null,
                $request['request_status'] ?? null,
                $request['payment_status'] ?? null,
                $request['payment_status'] ?? null,
                'amount_corrected',
                'Discount applied: ' . $discountAmount . '. ' . $discountReason,
                $userId,
                $_SESSION['role'] ?? null
            );

            $this->db->commit();
            return ['success' => true, 'final_amount' => $finalAmount];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process cash payment (Admin)
     */
    public function processCashPayment($id, $userId, $notes = '') {
        try {
            $this->db->beginTransaction();

            $request = $this->getById($id);
            if (!$request) {
                throw new Exception('داواکاری نەدۆزرایەوە');
            }

            $finalAmount = floatval($request['final_amount'] ?: $request['amount']);

            $data = [
                'payment_status' => 'paid',
                'payment_type' => 'cash',
                'paid_at' => date('Y-m-d H:i:s'),
                'paid_by' => $userId,
                'settled_by' => $userId,
                'settled_at' => date('Y-m-d H:i:s'),
                'original_amount' => $finalAmount,
                'final_amount' => $finalAmount
            ];

            $this->db->update('xray_requests', $data, 'id = :id', ['id' => $id]);

            // Log the payment
            $this->logPaymentAction($id, 'paid_cash', [
                'original_amount' => $request['original_amount'],
                'discount_amount' => $request['discount_amount'],
                'final_amount' => $finalAmount,
                'notes' => $notes
            ], $userId);

            // Notify X-Ray department to start the scan
            $this->notifyXrayDepartmentToScan($id);

            $this->db->commit();
            return ['success' => true, 'amount_paid' => $finalAmount];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process debt payment (record as debt on patient)
     */
    public function processDebtPayment($id, $userId, $dueDate = null, $notes = '') {
        try {
            $this->db->beginTransaction();

            $request = $this->getById($id);
            if (!$request) {
                throw new Exception('داواکاری نەدۆزرایەوە');
            }

            $finalAmount = floatval($request['final_amount'] ?: $request['amount']);

            // Update request status
            $data = [
                'payment_status' => 'debt',
                'payment_type' => 'non_cash',
                'settled_by' => $userId,
                'settled_at' => date('Y-m-d H:i:s'),
                'original_amount' => $finalAmount,
                'final_amount' => $finalAmount
            ];

            $this->db->update('xray_requests', $data, 'id = :id', ['id' => $id]);

            // Create debt record
            $debtData = [
                'patient_id' => $request['patient_id'],
                'request_id' => $id,
                'amount' => $finalAmount,
                'remaining_amount' => $finalAmount,
                'status' => 'pending',
                'due_date' => $dueDate,
                'notes' => $notes
            ];

            $debtId = $this->db->insert('patient_xray_debts', $debtData);

            // Log the debt
            $this->logPaymentAction($id, 'paid_debt', [
                'original_amount' => $request['original_amount'],
                'discount_amount' => $request['discount_amount'],
                'final_amount' => $finalAmount,
                'notes' => "قەرز تۆمارکرا - " . $notes
            ], $userId);

            // Notify X-Ray department to start the scan
            $this->notifyXrayDepartmentToScan($id);

            $this->db->commit();
            return ['success' => true, 'debt_id' => $debtId, 'amount' => $finalAmount];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get requests awaiting payment (for accountant/admin)
     * Workflow: Doctor creates → Accountant handles payment → X-ray dept scans
     * Shows pending requests where payment has not been processed yet
     */
    public function getAwaitingPayment($page = 1, $limit = ITEMS_PER_PAGE) {
        $offset = ($page - 1) * $limit;

        $sql = "SELECT xr.*,
                p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                u.full_name as requested_by_name,
                u2.full_name as completed_by_name
                FROM xray_requests xr
                LEFT JOIN patients p ON xr.patient_id = p.id
                LEFT JOIN users u ON xr.requested_by = u.id
                LEFT JOIN users u2 ON xr.completed_by = u2.id
                WHERE xr.payment_status IN ('pending', 'awaiting_payment')
                AND xr.status != 'cancelled'
                ORDER BY xr.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql);
    }

    /**
     * Count awaiting payment requests
     * Requests where accountant hasn't processed payment yet
     */
    public function countAwaitingPayment() {
        $sql = "SELECT COUNT(*) as count FROM xray_requests
                WHERE payment_status IN ('pending', 'awaiting_payment') AND status != 'cancelled'";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get patient debts
     */
    public function getPatientDebts($patientId = null, $status = null) {
        $params = [];
        $sql = "SELECT pxd.*,
                xr.request_number, xr.xray_type,
                p.full_name as patient_name, p.phone as patient_phone
                FROM patient_xray_debts pxd
                LEFT JOIN xray_requests xr ON pxd.request_id = xr.id
                LEFT JOIN patients p ON pxd.patient_id = p.id
                WHERE 1=1";

        if ($patientId) {
            $sql .= " AND pxd.patient_id = :patient_id";
            $params['patient_id'] = $patientId;
        }

        if ($status) {
            $sql .= " AND pxd.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY pxd.created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Pay debt (partial or full)
     */
    public function payDebt($debtId, $amount, $userId, $paymentMethod = 'cash', $notes = '') {
        try {
            $this->db->beginTransaction();

            $sql = "SELECT * FROM patient_xray_debts WHERE id = :id";
            $debt = $this->db->fetch($sql, ['id' => $debtId]);

            if (!$debt) {
                throw new Exception('قەرز نەدۆزرایەوە');
            }

            $amount = floatval($amount);
            $remaining = floatval($debt['remaining_amount']);

            if ($amount > $remaining) {
                throw new Exception('بڕی پارە لە قەرزی ماوە زیاترە');
            }

            $newRemaining = $remaining - $amount;
            $newStatus = $newRemaining <= 0 ? 'paid' : 'partial';

            // Update debt
            $this->db->update('patient_xray_debts', [
                'remaining_amount' => $newRemaining,
                'status' => $newStatus
            ], 'id = :id', ['id' => $debtId]);

            // Record payment
            $this->db->insert('xray_debt_payments', [
                'debt_id' => $debtId,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'received_by' => $userId,
                'notes' => $notes
            ]);

            // If fully paid, update request status
            if ($newStatus === 'paid') {
                $this->db->update('xray_requests', [
                    'payment_status' => 'paid',
                    'paid_at' => date('Y-m-d H:i:s'),
                    'paid_by' => $userId
                ], 'id = :id', ['id' => $debt['request_id']]);

                $this->logPaymentAction($debt['request_id'], 'debt_paid', [
                    'final_amount' => $debt['amount'],
                    'notes' => 'قەرز تەواو دراوە'
                ], $userId);
            }

            $this->db->commit();
            return ['success' => true, 'remaining' => $newRemaining, 'status' => $newStatus];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Log payment action for auditing
     */
    private function logPaymentAction($requestId, $action, $data, $userId) {
        $logData = [
            'request_id' => $requestId,
            'action' => $action,
            'original_amount' => $data['original_amount'] ?? null,
            'discount_amount' => $data['discount_amount'] ?? null,
            'final_amount' => $data['final_amount'] ?? null,
            'notes' => $data['notes'] ?? null,
            'performed_by' => $this->resolvePaymentLogUserId($userId)
        ];

        $this->db->insert('xray_payment_logs', $logData);
    }

    /**
     * Create a payment log entry from external callers using the same user resolution logic.
     */
    public function createPaymentLog(array $logData) {
        if (isset($logData['performed_by'])) {
            $logData['performed_by'] = $this->resolvePaymentLogUserId($logData['performed_by']);
        }

        return $this->db->insert('xray_payment_logs', $logData);
    }

    /**
     * Resolve the audit user id against the actual foreign-key target in this database.
     */
    private function resolvePaymentLogUserId($userId) {
        $userId = intval($userId);
        if ($userId <= 0) {
            return $userId;
        }

        $referenceTable = $this->getPaymentLogUserTable();
        if (!$referenceTable || !$this->db->tableExists($referenceTable)) {
            return $userId;
        }

        $existingId = $this->findMatchingUserIdInTable($referenceTable, ['id' => $userId]);
        if ($existingId !== null) {
            return $existingId;
        }

        $sourceUser = $this->db->fetch(
            "SELECT id, username, full_name FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if ($sourceUser) {
            foreach (['username', 'full_name'] as $column) {
                if (!empty($sourceUser[$column])) {
                    $matchedId = $this->findMatchingUserIdInTable($referenceTable, [
                        $column => $sourceUser[$column]
                    ]);

                    if ($matchedId !== null) {
                        return $matchedId;
                    }
                }
            }
        }

        return $this->getFallbackPaymentLogUserId($referenceTable, $userId);
    }

    /**
     * Detect the referenced table for xray_payment_logs.performed_by at runtime.
     */
    private function getPaymentLogUserTable() {
        if ($this->paymentLogUserTable !== null) {
            return $this->paymentLogUserTable;
        }

        $sql = "SELECT REFERENCED_TABLE_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'xray_payment_logs'
                  AND COLUMN_NAME = 'performed_by'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                LIMIT 1";

        $row = $this->db->fetch($sql);
        $this->paymentLogUserTable = $row['REFERENCED_TABLE_NAME'] ?? 'users';

        return $this->paymentLogUserTable;
    }

    /**
     * Cache column metadata for compatibility lookups.
     */
    private function getTableColumns($tableName) {
        if (isset($this->paymentLogUserTableColumns[$tableName])) {
            return $this->paymentLogUserTableColumns[$tableName];
        }

        $rows = $this->db->fetchAll(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table",
            ['table' => $tableName]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['COLUMN_NAME']] = true;
        }

        $this->paymentLogUserTableColumns[$tableName] = $columns;
        return $columns;
    }

    /**
     * Find a compatible user id in the target audit table.
     */
    private function findMatchingUserIdInTable($tableName, array $criteria) {
        $columns = $this->getTableColumns($tableName);
        $whereParts = [];
        $params = [];

        foreach ($criteria as $column => $value) {
            if (!isset($columns[$column]) || $value === null || $value === '') {
                continue;
            }

            $whereParts[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }

        if (!$whereParts) {
            return null;
        }

        $sql = "SELECT id FROM {$tableName} WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";
        $row = $this->db->fetch($sql, $params);

        return $row ? intval($row['id']) : null;
    }

    /**
     * Final fallback for legacy production schemas with a separate audit-user table.
     */
    private function getFallbackPaymentLogUserId($tableName, $originalUserId) {
        foreach ([['username' => 'admin'], ['role' => 'admin']] as $candidate) {
            $matchedId = $this->findMatchingUserIdInTable($tableName, $candidate);
            if ($matchedId !== null) {
                return $matchedId;
            }
        }

        $row = $this->db->fetch("SELECT id FROM {$tableName} ORDER BY id ASC LIMIT 1");
        return $row ? intval($row['id']) : $originalUserId;
    }

    /**
     * Notify admin and doctor about completed X-ray request
     */
    private function notifyCompletedRequest($requestId) {
        $request = $this->getById($requestId);

        if (!$request) return;

        // Notify the requesting doctor
        $this->db->insert('notifications', [
            'user_id' => $request['requested_by'],
            'type' => 'xray',
            'title' => 'ئێکسڕەی تەواوبوو',
            'message' => "ئێکسڕەی {$request['request_number']} بۆ {$request['patient_name']} تەواوبوو و ئامادەیە بۆ پارەدان",
            'link' => APP_URL . '/xray/view.php?id=' . $requestId
        ]);

        // Notify all accountants and admins
        $sql = "SELECT id FROM users WHERE role IN ('accountant', 'admin') AND is_active = 1";
        $financeUsers = $this->db->fetchAll($sql);

        foreach ($financeUsers as $financeUser) {
            $this->db->insert('notifications', [
                'user_id' => $financeUser['id'],
                'type' => 'xray',
                'title' => 'ئێکسڕەی ئامادەی پارەدان',
                'message' => "ئێکسڕەی {$request['request_number']} ئامادەیە بۆ وەرگرتنی پارە",
                'link' => APP_URL . '/xray/view.php?id=' . $requestId
            ]);
        }
    }

    /**
     * Get payment logs for a request
     */
    public function getPaymentLogs($requestId) {
        $logs = $this->db->fetchAll(
            "SELECT * FROM xray_payment_logs WHERE request_id = :request_id ORDER BY created_at ASC",
            ['request_id' => $requestId]
        );

        if (!$logs) {
            return [];
        }

        $referenceTable = $this->getPaymentLogUserTable();
        if (!$referenceTable || !$this->db->tableExists($referenceTable)) {
            return $logs;
        }

        $columns = $this->getTableColumns($referenceTable);
        if (!isset($columns['full_name'])) {
            return $logs;
        }

        $userIds = array_values(array_unique(array_filter(array_map(function($log) {
            return intval($log['performed_by'] ?? 0);
        }, $logs))));

        if (!$userIds) {
            return $logs;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $nameRows = $this->db->fetchAll(
            "SELECT id, full_name FROM {$referenceTable} WHERE id IN ({$placeholders})",
            $userIds
        );

        $nameMap = [];
        foreach ($nameRows as $row) {
            $nameMap[intval($row['id'])] = $row['full_name'];
        }

        foreach ($logs as &$log) {
            $performedBy = intval($log['performed_by'] ?? 0);
            $log['performed_by_name'] = $nameMap[$performedBy] ?? '';
        }
        unset($log);

        return $logs;
    }

    /**
     * Get daily revenue report
     */
    public function getDailyReport($date = null) {
        $date = $date ?: date('Y-m-d');

        $sql = "SELECT
                COUNT(*) as total_completed,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN payment_status IN ('debt', 'debt_pending', 'debt_approved') THEN 1 ELSE 0 END) as debt_count,
                SUM(CASE WHEN payment_status = 'paid' THEN original_amount ELSE 0 END) as total_original,
                SUM(CASE WHEN payment_status = 'paid' THEN discount_amount ELSE 0 END) as total_discount,
                SUM(CASE WHEN payment_status = 'paid' THEN final_amount ELSE 0 END) as total_revenue,
                SUM(CASE WHEN payment_status IN ('debt', 'debt_pending', 'debt_approved') THEN final_amount ELSE 0 END) as total_debt
                FROM xray_requests
                WHERE DATE(settled_at) = :date AND payment_status IN ('paid', 'debt', 'debt_pending', 'debt_approved')";

        return $this->db->fetch($sql, ['date' => $date]);
    }

    /**
     * Set price for request (Accountant/Admin - when doctor left price as 0)
     */
    public function setPrice($id, $amount, $userId) {
        try {
            $this->db->beginTransaction();

            $request = $this->getById($id);
            if (!$request) {
                throw new Exception('داواکاری نەدۆزرایەوە');
            }

            $amount = floatval($amount);
            if ($amount <= 0) {
                throw new Exception('نرخ دەبێت لە سفر زیاتر بێت');
            }

            $data = [
                'amount' => $amount,
                'original_amount' => $amount,
                'final_amount' => $amount
            ];

            $this->db->update('xray_requests', $data, 'id = :id', ['id' => $id]);

            // Log the action
            $this->logPaymentAction($id, 'price_set', [
                'original_amount' => $amount,
                'final_amount' => $amount,
                'notes' => 'نرخ لەلایەن ژمێریارەوە دانرا'
            ], $userId);

            $this->db->commit();
            return ['success' => true, 'amount' => $amount];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get payment statistics
     */
    public function getPaymentStatistics($dateFrom = null, $dateTo = null) {
        $params = [];
        $where = "1=1";

        if ($dateFrom) {
            $where .= " AND DATE(settled_at) >= :date_from";
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= " AND DATE(settled_at) <= :date_to";
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT
                COUNT(*) as total_settled,
                SUM(original_amount) as total_original,
                SUM(discount_amount) as total_discount,
                SUM(final_amount) as total_final,
                SUM(CASE WHEN payment_status = 'paid' THEN final_amount ELSE 0 END) as cash_revenue,
                SUM(CASE WHEN payment_status IN ('debt', 'debt_pending', 'debt_approved') THEN final_amount ELSE 0 END) as debt_amount
                FROM xray_requests
                WHERE {$where} AND payment_status IN ('paid', 'debt', 'debt_pending', 'debt_approved')";

        return $this->db->fetch($sql, $params);
    }

    /**
     * Log X-Ray action to history tracking table
     */
    public function logHistory($requestId, $oldStatus, $newStatus, $oldPayStatus, $newPayStatus, $actionType, $actionNote, $userId, $role) {
        try {
            $this->db->insert('xray_request_history', [
                'request_id' => $requestId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'old_payment_status' => $oldPayStatus,
                'new_payment_status' => $newPayStatus,
                'action_type' => $actionType,
                'action_note' => $actionNote,
                'changed_by_user_id' => $userId,
                'changed_by_role' => $role
            ]);
        } catch (Exception $e) {
            // Gracefully fail if migration hasn't been executed yet
        }
    }
}
