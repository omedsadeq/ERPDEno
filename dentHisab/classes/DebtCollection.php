<?php
/**
 * DebtCollection Class
 * کۆکردنەوەی قەرز و بەڕێوەبردنی پەیوەندیکردن
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class DebtCollection {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Get all patients with outstanding debt + their last call info
     */
    public function getDebtors($search = '', $callFilter = '', $sortBy = 'total_debt', $sortDir = 'DESC', $debtTypeFilter = '', $installmentDueFilter = '', $installmentDate = '', $dueDateFrom = '', $dueDateTo = '', $doctorId = null) {
        $params = [];

        // Doctor scope: a doctor sees ONLY their own patients (a folder they own,
        // or an X-ray debt they requested). Null = no scoping (admin/accountant).
        $doctorScopeSql = '';
        if ($doctorId !== null) {
            $doctorScopeSql = " AND (EXISTS (SELECT 1 FROM patient_folders pf_doc WHERE pf_doc.patient_id = p.id AND pf_doc.doctor_id = :doc_scope)
                                  OR EXISTS (SELECT 1 FROM xray_requests xr_doc WHERE xr_doc.patient_id = p.id AND xr_doc.requested_by = :doc_scope2 AND xr_doc.status = 'completed' AND xr_doc.payment_status = 'debt'))";
            $params['doc_scope']  = (int)$doctorId;
            $params['doc_scope2'] = (int)$doctorId;
        }

        $allowedSorts = ['total_debt', 'patient_name', 'last_call_date', 'remaining_balance', 'next_installment_due_date', 'next_due_date'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'total_debt';
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $validDate = function($date) {
            return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
        };
        $dueDateFrom = $validDate($dueDateFrom) ? $dueDateFrom : '';
        $dueDateTo = $validDate($dueDateTo) ? $dueDateTo : '';
        if ($dueDateFrom !== '' && $dueDateTo === '') {
            $dueDateTo = $dueDateFrom;
        }
        if ($dueDateTo !== '' && $dueDateFrom === '') {
            $dueDateFrom = $dueDateTo;
        }
        if ($dueDateFrom !== '' && $dueDateTo !== '' && $dueDateFrom > $dueDateTo) {
            [$dueDateFrom, $dueDateTo] = [$dueDateTo, $dueDateFrom];
        }

        $normalDueDateSql = "(SELECT MIN(c_due.payment_due_date)
                 FROM cases c_due
                 INNER JOIN patient_folders pf_due ON c_due.folder_id = pf_due.id
                 WHERE pf_due.patient_id = p.id
                 AND c_due.payment_due_date IS NOT NULL
                 AND GREATEST(c_due.total_amount - c_due.paid_amount - c_due.discount_amount, 0) > 0
                 AND NOT EXISTS (
                    SELECT 1 FROM installment_plans ip_due
                    WHERE ip_due.case_id = c_due.id AND ip_due.status = 'active'
                 ))";

        $normalDueAmountSql = "(SELECT SUM(GREATEST(c_due_amt.total_amount - c_due_amt.paid_amount - c_due_amt.discount_amount, 0))
                 FROM cases c_due_amt
                 INNER JOIN patient_folders pf_due_amt ON c_due_amt.folder_id = pf_due_amt.id
                 WHERE pf_due_amt.patient_id = p.id
                 AND c_due_amt.payment_due_date IS NOT NULL
                 AND GREATEST(c_due_amt.total_amount - c_due_amt.paid_amount - c_due_amt.discount_amount, 0) > 0
                 AND NOT EXISTS (
                    SELECT 1 FROM installment_plans ip_due_amt
                    WHERE ip_due_amt.case_id = c_due_amt.id AND ip_due_amt.status = 'active'
                 ))";

        $nextInstallmentDueDateSql = "(SELECT isch.due_date FROM installment_schedule isch
                 INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
                 INNER JOIN cases c2 ON ip2.case_id = c2.id
                 INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
                 WHERE pf3.patient_id = p.id
                 AND ip2.status = 'active'
                 AND isch.status IN ('pending', 'partial', 'overdue')
                 AND (isch.amount - isch.paid_amount) > 0
                 ORDER BY isch.due_date ASC, isch.installment_number ASC
                 LIMIT 1)";

        $sql = "SELECT
                p.id as patient_id,
                p.patient_code,
                p.full_name as patient_name,
                p.phone,
                p.phone2,

                -- Folder debt
                COALESCE((SELECT SUM(pf.total_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as folder_total,
                COALESCE((SELECT SUM(pf.paid_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as folder_paid,
                COALESCE((SELECT SUM(pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as folder_discount,

                -- X-ray debt
                COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed'), 0) as xray_total,
                COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'paid'), 0) as xray_paid,

                -- Combined totals
                (COALESCE((SELECT SUM(pf.total_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
                 COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed'), 0)) as total_amount,

                (COALESCE((SELECT SUM(pf.paid_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
                 COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'paid'), 0)) as total_paid,

                (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
                 COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0)) as total_debt,

                -- Installment info
                COALESCE((SELECT SUM(GREATEST(ip.total_amount - ip.down_payment - COALESCE((SELECT SUM(ipay.amount) FROM installment_payments ipay WHERE ipay.plan_id = ip.id), 0), 0))
                 FROM installment_plans ip
                 INNER JOIN cases c ON ip.case_id = c.id
                 INNER JOIN patient_folders pf2 ON c.folder_id = pf2.id
                 WHERE pf2.patient_id = p.id AND ip.status = 'active'), 0) as installment_debt,

                GREATEST(
                    (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
                     COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0))
                    - COALESCE((SELECT SUM(GREATEST(ip.total_amount - ip.down_payment - COALESCE((SELECT SUM(ipay.amount) FROM installment_payments ipay WHERE ipay.plan_id = ip.id), 0), 0))
                       FROM installment_plans ip
                       INNER JOIN cases c ON ip.case_id = c.id
                       INNER JOIN patient_folders pf2 ON c.folder_id = pf2.id
                       WHERE pf2.patient_id = p.id AND ip.status = 'active'), 0),
                    0
                ) as normal_debt,

                {$normalDueDateSql} as next_normal_due_date,
                COALESCE({$normalDueAmountSql}, 0) as normal_due_amount,

                (SELECT COUNT(*) FROM installment_plans ip
                 INNER JOIN cases c ON ip.case_id = c.id
                 INNER JOIN patient_folders pf2 ON c.folder_id = pf2.id
                 WHERE pf2.patient_id = p.id AND ip.status = 'active') as active_installments,

                (SELECT isch.installment_number FROM installment_schedule isch
                 INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
                 INNER JOIN cases c2 ON ip2.case_id = c2.id
                 INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
                 WHERE pf3.patient_id = p.id
                 AND ip2.status = 'active'
                 AND isch.status IN ('pending', 'partial', 'overdue')
                 AND (isch.amount - isch.paid_amount) > 0
                 ORDER BY isch.due_date ASC, isch.installment_number ASC
                 LIMIT 1) as next_installment_number,

                {$nextInstallmentDueDateSql} as next_installment_due_date,

                NULLIF(LEAST(
                    COALESCE({$normalDueDateSql}, '9999-12-31'),
                    COALESCE({$nextInstallmentDueDateSql}, '9999-12-31')
                ), '9999-12-31') as next_due_date,

                (SELECT GREATEST(isch.amount - isch.paid_amount, 0) FROM installment_schedule isch
                 INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
                 INNER JOIN cases c2 ON ip2.case_id = c2.id
                 INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
                 WHERE pf3.patient_id = p.id
                 AND ip2.status = 'active'
                 AND isch.status IN ('pending', 'partial', 'overdue')
                 AND (isch.amount - isch.paid_amount) > 0
                 ORDER BY isch.due_date ASC, isch.installment_number ASC
                 LIMIT 1) as next_installment_amount,

                (SELECT COUNT(*) FROM installment_schedule isch
                 INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
                 INNER JOIN cases c2 ON ip2.case_id = c2.id
                 INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
                 WHERE pf3.patient_id = p.id
                 AND ip2.status = 'active'
                 AND isch.status IN ('pending', 'partial', 'overdue')
                 AND isch.due_date < CURDATE()) as overdue_installments,

                COALESCE((SELECT SUM(GREATEST(isch.amount - isch.paid_amount, 0)) FROM installment_schedule isch
                 INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
                 INNER JOIN cases c2 ON ip2.case_id = c2.id
                 INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
                 WHERE pf3.patient_id = p.id
                 AND ip2.status = 'active'
                 AND isch.status IN ('pending', 'partial', 'overdue')
                 AND isch.due_date < CURDATE()), 0) as overdue_installment_amount,

                (SELECT COUNT(*) FROM installment_schedule isch
                 INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
                 INNER JOIN cases c2 ON ip2.case_id = c2.id
                 INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
                 WHERE pf3.patient_id = p.id
                 AND ip2.status = 'active'
                 AND isch.status IN ('pending', 'partial', 'overdue')
                 AND (isch.amount - isch.paid_amount) > 0
                 AND isch.due_date = CURDATE()) as due_today_installments,

                (SELECT COUNT(*) FROM installment_schedule isch
                 INNER JOIN installment_plans ip2 ON isch.plan_id = ip2.id
                 INNER JOIN cases c2 ON ip2.case_id = c2.id
                 INNER JOIN patient_folders pf3 ON c2.folder_id = pf3.id
                 WHERE pf3.patient_id = p.id
                 AND ip2.status = 'active'
                 AND isch.status IN ('pending', 'partial', 'overdue')
                 AND (isch.amount - isch.paid_amount) > 0
                 AND isch.due_date > CURDATE()) as upcoming_installments,

                -- Last payment date
                (SELECT MAX(pay.payment_date) FROM payments pay
                 INNER JOIN cases c ON pay.case_id = c.id
                 INNER JOIN patient_folders pf_pay ON c.folder_id = pf_pay.id
                 WHERE pf_pay.patient_id = p.id AND pay.status = 'approved') as last_payment_date,

                -- Last call info
                (SELECT cl.call_date FROM call_logs cl WHERE cl.patient_id = p.id ORDER BY cl.call_date DESC LIMIT 1) as last_call_date,
                (SELECT cl.call_status FROM call_logs cl WHERE cl.patient_id = p.id ORDER BY cl.call_date DESC LIMIT 1) as last_call_status,
                (SELECT cl.report_note FROM call_logs cl WHERE cl.patient_id = p.id ORDER BY cl.call_date DESC LIMIT 1) as last_call_note,
                (SELECT cl.next_follow_up FROM call_logs cl WHERE cl.patient_id = p.id ORDER BY cl.call_date DESC LIMIT 1) as next_follow_up,
                (SELECT cl.promised_amount FROM call_logs cl WHERE cl.patient_id = p.id AND cl.promised_date IS NOT NULL ORDER BY cl.call_date DESC LIMIT 1) as promised_amount,
                (SELECT cl.promised_date FROM call_logs cl WHERE cl.patient_id = p.id AND cl.promised_date IS NOT NULL ORDER BY cl.call_date DESC LIMIT 1) as promised_date,
                p.debt_call_optout as call_optout,
                (SELECT COUNT(*) FROM call_logs cl WHERE cl.patient_id = p.id) as total_calls

                FROM patients p
                WHERE (EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id)
                    OR EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt')){$doctorScopeSql}
                HAVING total_debt > 0";

        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

        // Call filter
        if ($callFilter === 'not_called') {
            $sql .= " AND (SELECT COUNT(*) FROM call_logs cl WHERE cl.patient_id = p.id) = 0";
        } elseif ($callFilter === 'called_today') {
            $sql .= " AND (SELECT MAX(cl.call_date) FROM call_logs cl WHERE cl.patient_id = p.id) >= CURDATE()";
        } elseif ($callFilter === 'overdue_call') {
            $sql .= " AND (
                (SELECT MAX(cl.call_date) FROM call_logs cl WHERE cl.patient_id = p.id) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                OR (SELECT COUNT(*) FROM call_logs cl WHERE cl.patient_id = p.id) = 0
            )";
        } elseif ($callFilter === 'follow_up_today') {
            $sql .= " AND (SELECT cl.next_follow_up FROM call_logs cl WHERE cl.patient_id = p.id ORDER BY cl.call_date DESC LIMIT 1) <= CURDATE()";
        }

        if ($debtTypeFilter === 'installment') {
            $sql .= " AND installment_debt > 0 AND normal_debt = 0";
        } elseif ($debtTypeFilter === 'normal') {
            $sql .= " AND normal_debt > 0 AND installment_debt = 0";
        } elseif ($debtTypeFilter === 'mixed') {
            $sql .= " AND installment_debt > 0 AND normal_debt > 0";
        } elseif ($debtTypeFilter === 'has_installment') {
            $sql .= " AND installment_debt > 0";
        }

        if ($installmentDueFilter === 'overdue') {
            $sql .= " AND overdue_installments > 0";
        } elseif ($installmentDueFilter === 'today') {
            $sql .= " AND due_today_installments > 0";
        } elseif ($installmentDueFilter === 'upcoming') {
            $sql .= " AND upcoming_installments > 0 AND overdue_installments = 0 AND due_today_installments = 0";
        }

        if (!empty($installmentDate)) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM installment_schedule isch_date
                INNER JOIN installment_plans ip_date ON isch_date.plan_id = ip_date.id
                INNER JOIN cases c_date ON ip_date.case_id = c_date.id
                INNER JOIN patient_folders pf_date ON c_date.folder_id = pf_date.id
                WHERE pf_date.patient_id = p.id
                AND ip_date.status = 'active'
                AND isch_date.status IN ('pending', 'partial', 'overdue')
                AND (isch_date.amount - isch_date.paid_amount) > 0
                AND isch_date.due_date = :installment_date
            )";
            $params['installment_date'] = $installmentDate;
        }

        if ($dueDateFrom !== '' && $dueDateTo !== '') {
            $sql .= " AND (
                EXISTS (
                    SELECT 1
                    FROM cases c_due_filter
                    INNER JOIN patient_folders pf_due_filter ON c_due_filter.folder_id = pf_due_filter.id
                    WHERE pf_due_filter.patient_id = p.id
                    AND c_due_filter.payment_due_date BETWEEN :normal_due_from AND :normal_due_to
                    AND GREATEST(c_due_filter.total_amount - c_due_filter.paid_amount - c_due_filter.discount_amount, 0) > 0
                    AND NOT EXISTS (
                        SELECT 1 FROM installment_plans ip_due_filter
                        WHERE ip_due_filter.case_id = c_due_filter.id AND ip_due_filter.status = 'active'
                    )
                )
                OR EXISTS (
                    SELECT 1
                    FROM installment_schedule isch_due_filter
                    INNER JOIN installment_plans ip_due_filter2 ON isch_due_filter.plan_id = ip_due_filter2.id
                    INNER JOIN cases c_due_filter2 ON ip_due_filter2.case_id = c_due_filter2.id
                    INNER JOIN patient_folders pf_due_filter2 ON c_due_filter2.folder_id = pf_due_filter2.id
                    WHERE pf_due_filter2.patient_id = p.id
                    AND ip_due_filter2.status = 'active'
                    AND isch_due_filter.status IN ('pending', 'partial', 'overdue')
                    AND (isch_due_filter.amount - isch_due_filter.paid_amount) > 0
                    AND isch_due_filter.due_date BETWEEN :installment_due_from AND :installment_due_to
                )
            )";
            $params['normal_due_from'] = $dueDateFrom;
            $params['normal_due_to'] = $dueDateTo;
            $params['installment_due_from'] = $dueDateFrom;
            $params['installment_due_to'] = $dueDateTo;
        }

        // Sort mapping
        $sortMap = [
            'total_debt' => 'total_debt',
            'patient_name' => 'p.full_name',
            'last_call_date' => 'last_call_date',
            'remaining_balance' => 'total_debt',
            'next_installment_due_date' => 'next_installment_due_date',
            'next_due_date' => 'next_due_date'
        ];
        $orderCol = $sortMap[$sortBy] ?? 'total_debt';
        $sql .= " ORDER BY {$orderCol} {$sortDir}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Log a call for a patient
     */
    public function logCall($patientId, $calledBy, $callStatus, $reportNote, $nextFollowUp = null, $promisedAmount = null, $promisedDate = null) {
        $data = [
            'patient_id' => intval($patientId),
            'called_by' => intval($calledBy),
            'call_date' => date('Y-m-d H:i:s'),
            'call_status' => $callStatus,
            'report_note' => trim($reportNote),
            'next_follow_up' => $nextFollowUp ?: null,
            'promised_amount' => ($promisedAmount !== null && $promisedAmount !== '') ? floatval($promisedAmount) : null,
            'promised_date' => $promisedDate ?: null
        ];

        return $this->db->insert('call_logs', $data);
    }

    /**
     * Include or exclude a patient from the daily call queue.
     */
    public function setCallOptOut($patientId, $optOut) {
        return $this->db->update('patients',
            ['debt_call_optout' => $optOut ? 1 : 0],
            'id = :id', ['id' => intval($patientId)]);
    }

    // ── Call queue priority (shared by page + API) ─────────────────────

    /**
     * Patient promised to pay on a FUTURE date and hasn't paid yet:
     * they sit in the blue waiting list — no calls until the date arrives.
     */
    public static function isActivePromise(array $d) {
        $today = date('Y-m-d');
        return !empty($d['promised_date'])
            && substr($d['promised_date'], 0, 10) > $today
            && $d['total_debt'] > 0
            && (empty($d['last_payment_date']) || substr($d['last_payment_date'], 0, 10) < substr($d['promised_date'], 0, 10));
    }

    /**
     * The promised date passed, debt remains, and no payment came after it.
     */
    public static function isBrokenPromise(array $d) {
        $today = date('Y-m-d');
        return !empty($d['promised_date'])
            && substr($d['promised_date'], 0, 10) < $today
            && $d['total_debt'] > 0
            && (empty($d['last_payment_date']) || substr($d['last_payment_date'], 0, 10) < substr($d['promised_date'], 0, 10));
    }

    /**
     * Who to call today, in order:
     * 1 follow-up due · 2 broken promise · 3 overdue installments · 4 never called.
     * 0 = not in the queue (opted out, waiting on an active promise, or nothing due).
     */
    public static function callPriority(array $d) {
        $today = date('Y-m-d');
        if ((int)($d['call_optout'] ?? 0) === 1) return 0;
        if (self::isActivePromise($d)) return 0;
        if (!empty($d['next_follow_up']) && substr($d['next_follow_up'], 0, 10) <= $today) return 1;
        if (self::isBrokenPromise($d)) return 2;
        if (!empty($d['overdue_installments'])) return 3;
        if (empty($d['total_calls'])) return 4;
        return 0;
    }

    // ── Work locks: one employee per patient at a time ─────────────────

    const LOCK_MINUTES = 10;

    private function cleanupLocks() {
        try {
            $this->db->query("DELETE FROM debt_call_locks WHERE locked_at < DATE_SUB(NOW(), INTERVAL " . self::LOCK_MINUTES . " MINUTE)");
        } catch (Exception $e) {
            // table may not exist yet
        }
    }

    /**
     * Lock a patient for this employee. Returns false when someone else holds it.
     */
    public function acquireLock($patientId, $userId) {
        $patientId = intval($patientId);
        $userId = intval($userId);
        try {
            $this->cleanupLocks();
            $existing = $this->db->fetch("SELECT locked_by FROM debt_call_locks WHERE patient_id = :pid", ['pid' => $patientId]);
            if ($existing && (int)$existing['locked_by'] !== $userId) {
                return false;
            }
            $this->db->query(
                "INSERT INTO debt_call_locks (patient_id, locked_by, locked_at) VALUES (:pid, :uid, NOW())
                 ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), locked_at = NOW()",
                ['pid' => $patientId, 'uid' => $userId]
            );
            return true;
        } catch (Exception $e) {
            return true; // locks are best-effort: never block calling if the table is missing
        }
    }

    public function releaseLock($patientId, $userId) {
        try {
            $this->db->query(
                "DELETE FROM debt_call_locks WHERE patient_id = :pid AND locked_by = :uid",
                ['pid' => intval($patientId), 'uid' => intval($userId)]
            );
        } catch (Exception $e) {
            // best-effort
        }
    }

    /**
     * All currently held locks with who holds them and for how long.
     */
    public function getActiveLocks() {
        try {
            $this->cleanupLocks();
            return $this->db->fetchAll(
                "SELECT l.patient_id, l.locked_by, u.full_name AS locked_by_name,
                        p.full_name AS patient_name,
                        TIMESTAMPDIFF(MINUTE, l.locked_at, NOW()) AS minutes
                 FROM debt_call_locks l
                 LEFT JOIN users u ON u.id = l.locked_by
                 LEFT JOIN patients p ON p.id = l.patient_id"
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * "Start Calling": pick the highest-priority debtor that is not locked by
     * a colleague and not already called today, lock them for this employee,
     * and return the row (or null when the queue is empty).
     */
    public function getNextToCall($userId, $doctorId = null) {
        $userId = intval($userId);
        $today = date('Y-m-d');

        $lockedByOthers = [];
        foreach ($this->getActiveLocks() as $lock) {
            if ((int)$lock['locked_by'] !== $userId) {
                $lockedByOthers[] = (int)$lock['patient_id'];
            }
        }

        // Doctor scope: only their own patients are eligible to be assigned.
        $debtorList = $this->getDebtors('', '', 'total_debt', 'DESC', '', '', '', '', '', $doctorId);
        $candidates = array_filter($debtorList, function ($d) use ($lockedByOthers, $today) {
            if (self::callPriority($d) <= 0) return false;
            if (in_array((int)$d['patient_id'], $lockedByOthers, true)) return false;
            // already handled today
            if (!empty($d['last_call_date']) && substr($d['last_call_date'], 0, 10) === $today) return false;
            return true;
        });

        usort($candidates, function ($a, $b) {
            $pa = self::callPriority($a);
            $pb = self::callPriority($b);
            return $pa === $pb ? ($b['total_debt'] <=> $a['total_debt']) : ($pa <=> $pb);
        });

        $next = $candidates[0] ?? null;
        if ($next) {
            $this->acquireLock($next['patient_id'], $userId);
        }
        return $next;
    }

    /**
     * Live room status: who is working on whom right now + today's numbers
     * per employee (for the admin monitoring panel).
     */
    public function getLiveStatus() {
        $perUser = $this->db->fetchAll(
            "SELECT cl.called_by, u.full_name,
                    COUNT(*) AS calls,
                    SUM(CASE WHEN cl.call_status NOT IN ('no_answer', 'busy', 'wrong_number') THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN cl.call_status = 'promised_to_pay' THEN 1 ELSE 0 END) AS promises,
                    SUM(CASE WHEN cl.call_status = 'paid' THEN 1 ELSE 0 END) AS paid
             FROM call_logs cl
             LEFT JOIN users u ON u.id = cl.called_by
             WHERE DATE(cl.call_date) = CURDATE()
             GROUP BY cl.called_by, u.full_name
             ORDER BY calls DESC"
        );

        $totals = ['calls' => 0, 'answered' => 0, 'promises' => 0, 'paid' => 0];
        foreach ($perUser as $u) {
            $totals['calls'] += (int)$u['calls'];
            $totals['answered'] += (int)$u['answered'];
            $totals['promises'] += (int)$u['promises'];
            $totals['paid'] += (int)$u['paid'];
        }

        return [
            'working_now' => $this->getActiveLocks(),
            'per_user' => $perUser,
            'totals' => $totals
        ];
    }

    /**
     * Get call history for a patient
     */
    public function getCallHistory($patientId, $limit = 20) {
        $sql = "SELECT cl.*, u.full_name as called_by_name
                FROM call_logs cl
                LEFT JOIN users u ON cl.called_by = u.id
                WHERE cl.patient_id = :patient_id
                ORDER BY cl.call_date DESC
                LIMIT {$limit}";
        return $this->db->fetchAll($sql, ['patient_id' => $patientId]);
    }

    /**
     * Get statistics for dashboard
     */
    public function getStatistics() {
        $stats = [];

        // Total debtors
        $sql = "SELECT COUNT(DISTINCT p.id) as count FROM patients p
                WHERE (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
                       COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0)) > 0";
        $stats['total_debtors'] = $this->db->fetch($sql)['count'] ?? 0;

        // Called today
        $sql = "SELECT COUNT(DISTINCT patient_id) as count FROM call_logs WHERE DATE(call_date) = CURDATE()";
        $stats['called_today'] = $this->db->fetch($sql)['count'] ?? 0;

        // Never called
        $sql = "SELECT COUNT(DISTINCT p.id) as count FROM patients p
                WHERE (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
                       COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0)) > 0
                AND NOT EXISTS (SELECT 1 FROM call_logs cl WHERE cl.patient_id = p.id)";
        $stats['never_called'] = $this->db->fetch($sql)['count'] ?? 0;

        // Follow-ups due today
        $sql = "SELECT COUNT(DISTINCT cl.patient_id) as count
                FROM call_logs cl
                INNER JOIN (SELECT patient_id, MAX(call_date) as max_date FROM call_logs GROUP BY patient_id) latest
                    ON cl.patient_id = latest.patient_id AND cl.call_date = latest.max_date
                WHERE cl.next_follow_up IS NOT NULL AND cl.next_follow_up <= CURDATE()";
        $stats['follow_ups_due'] = $this->db->fetch($sql)['count'] ?? 0;

        // Total outstanding debt
        $sql = "SELECT
                COALESCE(SUM(pf.total_amount - pf.paid_amount - pf.discount_amount), 0) as folder_debt
                FROM patient_folders pf";
        $folderDebt = $this->db->fetch($sql)['folder_debt'] ?? 0;

        $sql = "SELECT COALESCE(SUM(final_amount), 0) as xray_debt FROM xray_requests WHERE status = 'completed' AND payment_status = 'debt'";
        $xrayDebt = $this->db->fetch($sql)['xray_debt'] ?? 0;

        $stats['total_debt'] = $folderDebt + $xrayDebt;

        // Money actually collected against debts this month (all three debt channels)
        $monthStart = date('Y-m-01');
        $collected = 0;
        try {
            $collected += (float)($this->db->fetch(
                "SELECT COALESCE(SUM(amount),0) t FROM patient_debt_payments WHERE DATE(payment_date) >= :m", ['m' => $monthStart]
            )['t'] ?? 0);
        } catch (Exception $e) { /* table optional */ }
        try {
            $collected += (float)($this->db->fetch(
                "SELECT COALESCE(SUM(amount),0) t FROM installment_payments WHERE DATE(paid_at) >= :m", ['m' => $monthStart]
            )['t'] ?? 0);
        } catch (Exception $e) { /* table optional */ }
        try {
            $collected += (float)($this->db->fetch(
                "SELECT COALESCE(SUM(amount),0) t FROM xray_debt_payments WHERE DATE(created_at) >= :m", ['m' => $monthStart]
            )['t'] ?? 0);
        } catch (Exception $e) { /* table optional */ }
        $stats['collected_month'] = $collected;

        return $stats;
    }

    /**
     * Call log list for the report page (filterable, paginated).
     */
    public function getCallLogs($dateFrom = '', $dateTo = '', $userId = 0, $status = '', $search = '', $page = 1, $perPage = 30) {
        $where = '1=1';
        $params = [];

        $validDate = function ($date) {
            return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
        };
        // Half-open datetime range so idx_call_date is usable (DATE(col) is not)
        if ($validDate($dateFrom)) {
            $where .= ' AND cl.call_date >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($validDate($dateTo)) {
            $where .= ' AND cl.call_date < :date_to + INTERVAL 1 DAY';
            $params['date_to'] = $dateTo . ' 00:00:00';
        }
        if ($userId > 0) {
            $where .= ' AND cl.called_by = :user_id';
            $params['user_id'] = $userId;
        }
        if (is_string($status) && $status !== '' && array_key_exists($status, self::getCallStatusLabels())) {
            $where .= ' AND cl.call_status = :status';
            $params['status'] = $status;
        }
        if (is_string($search) && $search !== '') {
            $where .= ' AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)';
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

        $total = (int)($this->db->fetch(
            "SELECT COUNT(*) AS c FROM call_logs cl LEFT JOIN patients p ON p.id = cl.patient_id WHERE {$where}",
            $params
        )['c'] ?? 0);

        $perPage = max(1, intval($perPage));
        // Clamp to the last page so an out-of-range ?page= shows results, not an
        // empty table under a nonzero total
        $maxPage = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, intval($page)), $maxPage);
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->fetchAll(
            "SELECT cl.*, u.full_name AS called_by_name,
                    p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone
             FROM call_logs cl
             LEFT JOIN users u ON u.id = cl.called_by
             LEFT JOIN patients p ON p.id = cl.patient_id
             WHERE {$where}
             ORDER BY cl.call_date DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Statistics report for a period: totals, per-status and per-employee.
     * Accepts the same employee/status/search filters as getCallLogs() so the
     * stat cards always describe the same rows the list shows.
     */
    public function getCallReport($dateFrom = '', $dateTo = '', $userId = 0, $status = '', $search = '') {
        $where = '1=1';
        $params = [];
        $validDate = function ($date) {
            return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
        };
        // Half-open datetime range so idx_call_date is usable (DATE(col) is not)
        if ($validDate($dateFrom)) {
            $where .= ' AND cl.call_date >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($validDate($dateTo)) {
            $where .= ' AND cl.call_date < :date_to + INTERVAL 1 DAY';
            $params['date_to'] = $dateTo . ' 00:00:00';
        }
        if ($userId > 0) {
            $where .= ' AND cl.called_by = :user_id';
            $params['user_id'] = $userId;
        }
        if (is_string($status) && $status !== '' && array_key_exists($status, self::getCallStatusLabels())) {
            $where .= ' AND cl.call_status = :status';
            $params['status'] = $status;
        }
        if (is_string($search) && $search !== '') {
            $where .= ' AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)';
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }
        $join = ' LEFT JOIN patients p ON p.id = cl.patient_id';

        $totals = $this->db->fetch(
            "SELECT COUNT(*) AS calls,
                    COUNT(DISTINCT cl.patient_id) AS patients,
                    SUM(CASE WHEN cl.call_status NOT IN ('no_answer', 'busy', 'wrong_number') THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN cl.call_status = 'no_answer' THEN 1 ELSE 0 END) AS no_answer,
                    SUM(CASE WHEN cl.call_status = 'promised_to_pay' THEN 1 ELSE 0 END) AS promises,
                    COALESCE(SUM(CASE WHEN cl.call_status = 'promised_to_pay' THEN cl.promised_amount ELSE 0 END), 0) AS promised_amount,
                    SUM(CASE WHEN cl.call_status = 'paid' THEN 1 ELSE 0 END) AS paid,
                    SUM(CASE WHEN cl.call_status = 'scheduled_visit' THEN 1 ELSE 0 END) AS scheduled
             FROM call_logs cl{$join} WHERE {$where}",
            $params
        ) ?: [];

        $byStatus = $this->db->fetchAll(
            "SELECT cl.call_status, COUNT(*) AS c
             FROM call_logs cl{$join} WHERE {$where}
             GROUP BY cl.call_status ORDER BY c DESC",
            $params
        );

        $byUser = $this->db->fetchAll(
            "SELECT cl.called_by, u.full_name,
                    COUNT(*) AS calls,
                    SUM(CASE WHEN cl.call_status NOT IN ('no_answer', 'busy', 'wrong_number') THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN cl.call_status = 'promised_to_pay' THEN 1 ELSE 0 END) AS promises,
                    COALESCE(SUM(CASE WHEN cl.call_status = 'promised_to_pay' THEN cl.promised_amount ELSE 0 END), 0) AS promised_amount,
                    SUM(CASE WHEN cl.call_status = 'paid' THEN 1 ELSE 0 END) AS paid
             FROM call_logs cl{$join}
             LEFT JOIN users u ON u.id = cl.called_by
             WHERE {$where}
             GROUP BY cl.called_by, u.full_name
             ORDER BY calls DESC",
            $params
        );

        return ['totals' => $totals, 'by_status' => $byStatus, 'by_user' => $byUser];
    }

    /**
     * Call status labels in Kurdish
     */
    public static function getCallStatusLabels() {
        return [
            'no_answer' => 'وەڵامی نەدایەوە',
            'promised_to_pay' => 'بەڵێنی پارەدانی دا',
            'busy' => 'سەرقاڵ بوو',
            'paid' => 'پارەی دا',
            'refused' => 'ڕەتیکردەوە',
            'wrong_number' => 'ژمارە هەڵەیە',
            'scheduled_visit' => 'سەردانی دیاریکرد',
            'other' => 'هیتر'
        ];
    }

    /**
     * Call status badge colors
     */
    public static function getCallStatusColors() {
        return [
            'no_answer' => '#e74c3c',
            'promised_to_pay' => '#f39c12',
            'busy' => '#95a5a6',
            'paid' => '#27ae60',
            'refused' => '#c0392b',
            'wrong_number' => '#7f8c8d',
            'scheduled_visit' => '#3498db',
            'other' => '#8e44ad'
        ];
    }
}
