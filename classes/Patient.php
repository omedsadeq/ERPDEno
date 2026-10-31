<?php
/**
 * Patient Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/XrayManager.php';

class Patient {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Generate unique patient code
     */
    private function generatePatientCode() {
        $year = date('Y');
        $sql = "SELECT MAX(CAST(SUBSTRING(patient_code, 6) AS UNSIGNED)) as max_num
                FROM patients WHERE patient_code LIKE :prefix";
        $result = $this->db->fetch($sql, ['prefix' => "P{$year}%"]);
        $nextNum = ($result['max_num'] ?? 0) + 1;
        return "P{$year}" . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create new patient
     */
    public function create($data) {
        $data['patient_code'] = $this->generatePatientCode();

        try {
            $this->db->beginTransaction();

            // Extract medical conditions if present
            $medicalConditions = $data['medical_conditions'] ?? [];
            unset($data['medical_conditions']);

            // Insert patient
            $patientId = $this->db->insert('patients', $data);

            // Insert medical conditions
            if (!empty($medicalConditions)) {
                foreach ($medicalConditions as $condition) {
                    $this->db->insert('patient_medical_conditions', [
                        'patient_id' => $patientId,
                        'condition_type' => $condition['type'],
                        'condition_details' => $condition['details'] ?? null,
                        'severity' => $condition['severity'] ?? 'medium'
                    ]);
                }
            }

            $this->db->commit();
            return ['success' => true, 'patient_id' => $patientId, 'patient_code' => $data['patient_code']];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update patient
     */
    public function update($id, $data) {
        try {
            $this->db->beginTransaction();

            // Extract medical conditions if present
            $medicalConditions = $data['medical_conditions'] ?? null;
            unset($data['medical_conditions']);

            // Update patient
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('patients', $data, 'id = :id', ['id' => $id]);

            // Update medical conditions if provided
            if ($medicalConditions !== null) {
                $this->db->delete('patient_medical_conditions', 'patient_id = :patient_id', ['patient_id' => $id]);
                foreach ($medicalConditions as $condition) {
                    $this->db->insert('patient_medical_conditions', [
                        'patient_id' => $id,
                        'condition_type' => $condition['type'],
                        'condition_details' => $condition['details'] ?? null,
                        'severity' => $condition['severity'] ?? 'medium'
                    ]);
                }
            }

            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get patient by ID
     */
    public function getById($id) {
        // total_debt (Task 15: repointed to the derived ledger — v_patient_
        // financial_summary.case_balance is max(0, approved-paid) summed per
        // case, never the stale patient_folders mirror) and COALESCE to 0
        // so a patient with no folders reports 0, not NULL.
        $sql = "SELECT p.*,
                COALESCE(s.case_balance, 0) as total_debt,
                COALESCE((SELECT SUM(pf.paid_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as total_paid
                FROM patients p
                LEFT JOIN v_patient_financial_summary s ON s.patient_id = p.id
                WHERE p.id = :id";
        $patient = $this->db->fetch($sql, ['id' => $id]);

        if ($patient) {
            $patient['medical_conditions'] = $this->getMedicalConditions($id);
        }

        return $patient;
    }

    /**
     * Get patient by code
     */
    public function getByCode($code) {
        $sql = "SELECT * FROM patients WHERE patient_code = :code";
        return $this->db->fetch($sql, ['code' => $code]);
    }

    /**
     * Get all patients with advanced filters
     */
    public function getAll($page = 1, $limit = ITEMS_PER_PAGE, $search = '', $orderBy = 'created_at DESC', $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = [];

        // total_debt (Task 15: repointed) — case part from the derived ledger
        // view, x-ray part already canonical (XrayManager::debtStatusSqlList,
        // Task 5). Review fix (Finding 1): x-ray part now uses the
        // remaining-balance expression, not the frozen final_amount, so a
        // partially-collected x-ray debt is reflected correctly here too.
        $sql = "SELECT p.*,
                (SELECT COUNT(*) FROM patient_folders pf WHERE pf.patient_id = p.id) as folder_count,
                (COALESCE(s.case_balance, 0) +
                 COALESCE((SELECT SUM(" . XrayManager::remainingAmountSql('xr', 'pxd') . ") FROM xray_requests xr " . XrayManager::remainingAmountJoinSql('xr', 'pxd') . " WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")), 0)) as total_debt,
                " . $this->searchRank($search, $params) . " as search_rank
                FROM patients p
                LEFT JOIN v_patient_financial_summary s ON s.patient_id = p.id
                WHERE p.deleted_at IS NULL";

        // Search filter (best matches first — see searchRank)
        $sql .= $this->searchWhere($search, $params);

        // Gender filter
        if (!empty($filters['gender'])) {
            $sql .= " AND p.gender = :gender";
            $params['gender'] = $filters['gender'];
        }

        // Date range filter
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(p.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(p.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Has debt filter (includes both folder debt and X-ray debt).
        // Task 15: case part reads the joined ledger view's case_balance
        // (never negative — GREATEST(0, approved-paid) per case, summed) so
        // "has debt" agrees exactly with the same view every other reader now
        // uses, instead of the stale patient_folders mirror.
        if (isset($filters['has_debt']) && $filters['has_debt'] !== '') {
            if ($filters['has_debt'] == '1') {
                $sql .= " AND (COALESCE(s.case_balance, 0) > 0
                          OR EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")))";
            } else {
                $sql .= " AND COALESCE(s.case_balance, 0) = 0
                         AND NOT EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . "))";
            }
        }

        // Has medical alerts filter
        if (isset($filters['has_medical_alerts']) && $filters['has_medical_alerts'] !== '') {
            if ($filters['has_medical_alerts'] == '1') {
                $sql .= " AND EXISTS (SELECT 1 FROM patient_medical_conditions pmc WHERE pmc.patient_id = p.id)";
            } else {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM patient_medical_conditions pmc WHERE pmc.patient_id = p.id)";
            }
        }

        // Folder status filter
        if (!empty($filters['folder_status'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id AND pf.status = :folder_status)";
            $params['folder_status'] = $filters['folder_status'];
        }

        // ORDER BY cannot be a bound parameter, so it is interpolated — every
        // value must therefore come from this whitelist, never from the request.
        $sql .= " ORDER BY search_rank, " . $this->safeOrderBy($orderBy) . " LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * The patients list's search box, read the way people actually type:
     *  - every word must appear, in any order: «ئەحمەد محمد» finds «محمد کەریم ئەحمەد»;
     *  - a number is one phone however it was typed or stored: spaces, dashes,
     *    +964 / 00964, a leading 0, Arabic-Indic digits (٠٧٥٠…);
     *  - Arabic ي ى ك are read as Kurdish ی ک (the columns' unicode_ci collation
     *    already treats most other letter variants — ه/ە، ر/ڕ، و/ۆ — as one).
     * Returns ['words' => [...], 'phone' => core digits or null, 'text' => the cleaned term].
     */
    public static function searchTerms($search) {
        $s = strtr((string)$search, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ', "\u{0640}" => '',
        ]);
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        $digits = preg_replace('/[\s\-+().]/u', '', $s);
        if ($digits !== '' && ctype_digit($digits) && strlen($digits) >= 4) {
            // A phone typed with spaces or dashes is still one number
            if (strpos($digits, '00964') === 0) {
                $core = substr($digits, 5);
            } elseif (strpos($digits, '964') === 0 && strlen($digits) > 6) {
                $core = substr($digits, 3);
            } else {
                $core = $digits;
            }
            $core = ltrim($core, '0');
            return ['words' => [$digits], 'phone' => $core !== '' ? $core : $digits, 'text' => $digits];
        }
        $words = array_values(array_unique(array_filter(explode(' ', $s), 'strlen')));
        return ['words' => array_slice($words, 0, 6), 'phone' => null, 'text' => $s];
    }

    /** The stored phone without its spaces, dashes and plus, for matching typed numbers. */
    private const PHONE_DIGITS_SQL = "REPLACE(REPLACE(REPLACE(REPLACE(p.phone, ' ', ''), '-', ''), '+', ''), '.', '')";

    /** WHERE part for the search box (see searchTerms); every list and its count use it, so they agree. */
    private function searchWhere($search, array &$params) {
        $t = self::searchTerms($search);
        if (!$t['words']) {
            return '';
        }
        $like = fn($v) => '%' . addcslashes($v, '%_\\') . '%';
        if ($t['phone'] !== null) {
            $params['sq_phone'] = $like($t['phone']);
            $params['sq_code'] = $like($t['words'][0]);
            return " AND (" . self::PHONE_DIGITS_SQL . " LIKE :sq_phone OR p.patient_code LIKE :sq_code)";
        }
        $sql = '';
        foreach ($t['words'] as $i => $w) {
            $params["sq_n$i"] = $like($w);
            $params["sq_c$i"] = $like($w);
            $either = "p.full_name LIKE :sq_n$i OR p.patient_code LIKE :sq_c$i";
            if (ctype_digit($w)) {
                $params["sq_p$i"] = $like($w);
                $either .= " OR " . self::PHONE_DIGITS_SQL . " LIKE :sq_p$i";
            }
            $sql .= " AND ($either)";
        }
        return $sql;
    }

    /**
     * How well a row matches the search, best first: 0 the exact name, code or
     * number; 1 the name starts with what was typed; 2 it contains it as typed;
     * 3 only the words, in another order. '0' when there is no search.
     */
    private function searchRank($search, array &$params) {
        $t = self::searchTerms($search);
        if (!$t['words']) {
            return '0';
        }
        $esc = fn($v) => addcslashes($v, '%_\\');
        if ($t['phone'] !== null) {
            $params['sr_phone_end'] = '%' . $esc($t['phone']);
            $params['sr_code_eq'] = $t['words'][0];
            return "(CASE WHEN " . self::PHONE_DIGITS_SQL . " LIKE :sr_phone_end OR p.patient_code = :sr_code_eq THEN 0 ELSE 1 END)";
        }
        $params['sr_name_eq'] = $t['text'];
        $params['sr_code_eq'] = $t['text'];
        $params['sr_name_start'] = $esc($t['text']) . '%';
        $params['sr_name_has'] = '%' . $esc($t['text']) . '%';
        return "(CASE WHEN p.full_name = :sr_name_eq OR p.patient_code = :sr_code_eq THEN 0
                      WHEN p.full_name LIKE :sr_name_start THEN 1
                      WHEN p.full_name LIKE :sr_name_has THEN 2
                      ELSE 3 END)";
    }

    /** Map a requested sort to a known-safe ORDER BY clause. */
    private function safeOrderBy($orderBy) {
        $allowed = [
            'created_at DESC'   => 'p.created_at DESC',
            'created_at ASC'    => 'p.created_at ASC',
            'full_name ASC'     => 'p.full_name ASC',
            'full_name DESC'    => 'p.full_name DESC',
            'patient_code ASC'  => 'p.patient_code ASC',
            'patient_code DESC' => 'p.patient_code DESC',
        ];
        $key = trim((string)$orderBy);
        if (isset($allowed[$key])) {
            return $allowed[$key];
        }
        // Already-qualified values from callers that passed a whitelisted clause.
        if (in_array($key, $allowed, true)) {
            return $key;
        }
        return 'p.created_at DESC';
    }

    /**
     * Count patients with advanced filters
     */
    public function count($search = '', $filters = []) {
        // v_patient_financial_summary aggregates patients × folders × cases ×
        // v_case_financial_summary and GROUP BYs the lot, so MariaDB
        // materialises the whole thing (~11s on this dataset) before counting.
        // COUNT(*) never reads a column from it — only the has_debt filter
        // does — so the join is added on demand. Joining it unconditionally
        // made the patients directory take 11s to load for admin/staff.
        $needsFinancials = isset($filters['has_debt']) && $filters['has_debt'] !== '';

        $sql = "SELECT COUNT(*) as count FROM patients p";
        if ($needsFinancials) {
            $sql .= " LEFT JOIN v_patient_financial_summary s ON s.patient_id = p.id";
        }
        $sql .= " WHERE p.deleted_at IS NULL";
        $params = [];

        // Search filter
        $sql .= $this->searchWhere($search, $params);

        // Gender filter
        if (!empty($filters['gender'])) {
            $sql .= " AND p.gender = :gender";
            $params['gender'] = $filters['gender'];
        }

        // Date range filter
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(p.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(p.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Has debt filter (includes both folder debt and X-ray debt).
        // Task 15: case part reads the joined ledger view — see getAll().
        if (isset($filters['has_debt']) && $filters['has_debt'] !== '') {
            if ($filters['has_debt'] == '1') {
                $sql .= " AND (COALESCE(s.case_balance, 0) > 0
                          OR EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . ")))";
            } else {
                $sql .= " AND COALESCE(s.case_balance, 0) = 0
                         AND NOT EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status IN (" . XrayManager::debtStatusSqlList() . "))";
            }
        }

        // Has medical alerts filter
        if (isset($filters['has_medical_alerts']) && $filters['has_medical_alerts'] !== '') {
            if ($filters['has_medical_alerts'] == '1') {
                $sql .= " AND EXISTS (SELECT 1 FROM patient_medical_conditions pmc WHERE pmc.patient_id = p.id)";
            } else {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM patient_medical_conditions pmc WHERE pmc.patient_id = p.id)";
            }
        }

        // Folder status filter
        if (!empty($filters['folder_status'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id AND pf.status = :folder_status)";
            $params['folder_status'] = $filters['folder_status'];
        }

        return $this->db->fetch($sql, $params)['count'];
    }

    /**
     * Search patients (for autocomplete)
     */
    public function search($term, $limit = 10) {
        $sql = "SELECT id, patient_code, full_name, phone, gender
                FROM patients
                WHERE deleted_at IS NULL
                  AND (full_name LIKE :term OR phone LIKE :term2 OR patient_code LIKE :term3)
                ORDER BY full_name
                LIMIT {$limit}";
        return $this->db->fetchAll($sql, [
            'term' => "%{$term}%",
            'term2' => "%{$term}%",
            'term3' => "%{$term}%"
        ]);
    }

    /**
     * Get patient's medical conditions
     */
    public function getMedicalConditions($patientId) {
        $sql = "SELECT * FROM patient_medical_conditions WHERE patient_id = :patient_id";
        return $this->db->fetchAll($sql, ['patient_id' => $patientId]);
    }

    /**
     * Check if patient has medical alert
     */
    public function hasMedicalAlert($patientId) {
        $conditions = $this->getMedicalConditions($patientId);
        return !empty($conditions);
    }

    /**
     * Get patient folders
     */
    public function getFolders($patientId, $status = null) {
        $sql = "SELECT pf.*, u.full_name as doctor_name,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count
                FROM patient_folders pf
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE pf.patient_id = :patient_id";
        $params = ['patient_id' => $patientId];

        if ($status) {
            $sql .= " AND pf.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY pf.created_at DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get patients with overdue installments
     */
    public function getWithOverdueInstallments() {
        $sql = "SELECT p.*,
                GROUP_CONCAT(DISTINCT is2.due_date ORDER BY is2.due_date) as overdue_dates,
                SUM(is2.amount - is2.paid_amount) as overdue_amount
                FROM patients p
                INNER JOIN patient_folders pf ON p.id = pf.patient_id
                INNER JOIN cases c ON pf.id = c.folder_id
                INNER JOIN installment_plans ip ON c.id = ip.case_id
                INNER JOIN installment_schedule is2 ON ip.id = is2.plan_id
                -- Step 3 (Task 6): explicit status filter added — a pending plan has
                -- no schedule rows yet so this was already safe, but 'cancelled'
                -- plans can still carry stale schedule rows, so require 'active'.
                WHERE ip.status = 'active' AND is2.status IN ('pending', 'partial') AND is2.due_date < CURDATE()
                GROUP BY p.id
                ORDER BY overdue_amount DESC";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get patients with appointments today
     */
    public function getWithAppointmentsToday() {
        $sql = "SELECT p.*, a.appointment_time, a.status as appointment_status,
                u.full_name as doctor_name
                FROM patients p
                INNER JOIN appointments a ON p.id = a.patient_id
                LEFT JOIN users u ON a.doctor_id = u.id
                WHERE a.appointment_date = CURDATE()
                ORDER BY a.appointment_time";
        return $this->db->fetchAll($sql);
    }

    /**
     * Delete patient
     */
    public function delete($id) {
        try {
            // Soft delete — never hard-delete a patient. A hard DELETE would
            // CASCADE through folders -> cases -> treatments -> payments and
            // wipe the patient's whole financial history. Instead we stamp
            // deleted_at/deleted_by; the patient drops out of all lists but
            // every linked record (cases, visits, payments) stays intact.
            $this->db->update(
                'patients',
                ['deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => $_SESSION['user_id'] ?? null],
                'id = :id',
                ['id' => $id]
            );
            if (class_exists('AuditLog')) {
                AuditLog::log('delete_patient', 'patients', $id);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Restore a soft-deleted patient.
     */
    public function restore($id) {
        try {
            $this->db->update('patients', ['deleted_at' => null, 'deleted_by' => null], 'id = :id', ['id' => $id]);
            if (class_exists('AuditLog')) {
                AuditLog::log('restore_patient', 'patients', $id);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get patient statistics
     */
    public function getStatistics($patientId) {
        $stats = [];

        // Total visits
        $sql = "SELECT COUNT(*) as count FROM appointments WHERE patient_id = :id AND status = 'completed'";
        $stats['total_visits'] = $this->db->fetch($sql, ['id' => $patientId])['count'];

        // Total paid
        $sql = "SELECT SUM(paid_amount) as total FROM patient_folders WHERE patient_id = :id";
        $stats['total_paid'] = $this->db->fetch($sql, ['id' => $patientId])['total'] ?? 0;

        // Total debt (Task 15: repointed to the derived ledger view)
        $sql = "SELECT case_balance FROM v_patient_financial_summary WHERE patient_id = :id";
        $stats['total_debt'] = $this->db->fetch($sql, ['id' => $patientId])['case_balance'] ?? 0;

        // Active cases
        $sql = "SELECT COUNT(*) as count FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.patient_id = :id AND c.status = 'open'";
        $stats['active_cases'] = $this->db->fetch($sql, ['id' => $patientId])['count'];

        return $stats;
    }

    /**
     * Add family relation
     */
    public function addFamilyRelation($patientId, $relatedPatientId, $relationship) {
        $data = [
            'patient_id' => $patientId,
            'related_patient_id' => $relatedPatientId,
            'relationship' => $relationship
        ];
        return $this->db->insert('patient_family', $data);
    }

    /**
     * Get family members
     */
    public function getFamilyMembers($patientId) {
        $sql = "SELECT pf.*, p.full_name, p.phone, p.patient_code
                FROM patient_family pf
                INNER JOIN patients p ON pf.related_patient_id = p.id
                WHERE pf.patient_id = :patient_id";
        return $this->db->fetchAll($sql, ['patient_id' => $patientId]);
    }

    /**
     * Get count of patients with active folders
     */
    public function countPatientsWithActiveFolders() {
        $sql = "SELECT COUNT(DISTINCT pf.patient_id) as count
                FROM patient_folders pf
                WHERE pf.status = 'active'";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get count of patients with closed folders
     */
    public function countPatientsWithClosedFolders() {
        $sql = "SELECT COUNT(DISTINCT pf.patient_id) as count
                FROM patient_folders pf
                WHERE pf.status = 'closed'";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get total active folders count
     */
    public function countActiveFolders() {
        $sql = "SELECT COUNT(*) as count FROM patient_folders WHERE status = 'active'";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get total closed folders count
     */
    public function countClosedFolders() {
        $sql = "SELECT COUNT(*) as count FROM patient_folders WHERE status = 'closed'";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get count of patients with debt
     */
    public function countPatientsWithDebt() {
        // Task 15: repointed to the derived ledger view — case_balance is
        // never negative (GREATEST(0, approved-paid) per case), so "at least
        // one folder had positive debt" and "the patient's aggregate
        // case_balance > 0" are equivalent.
        $sql = "SELECT COUNT(*) as count
                FROM v_patient_financial_summary
                WHERE case_balance > 0";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get total debt amount
     */
    public function getTotalDebtAmount() {
        // Task 15: repointed to the derived ledger view.
        $sql = "SELECT COALESCE(SUM(case_balance), 0) as total
                FROM v_patient_financial_summary
                WHERE case_balance > 0";
        return $this->db->fetch($sql)['total'] ?? 0;
    }

    /**
     * Get count of patients with medical alerts
     */
    public function countPatientsWithMedicalAlerts() {
        $sql = "SELECT COUNT(DISTINCT patient_id) as count FROM patient_medical_conditions";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get count of new patients this month
     */
    public function countNewPatientsThisMonth() {
        $sql = "SELECT COUNT(*) as count FROM patients
                WHERE YEAR(created_at) = YEAR(CURDATE())
                AND MONTH(created_at) = MONTH(CURDATE())";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get count of active cases
     */
    public function countActiveCases() {
        $sql = "SELECT COUNT(*) as count FROM cases WHERE status = 'open'";
        return $this->db->fetch($sql)['count'];
    }

    // ==========================================
    // DOCTOR-PATIENT PRIVACY SYSTEM METHODS
    // ==========================================

    /**
     * Check if a doctor owns (has folders for) a patient
     * A doctor "owns" a patient if they have at least one folder assigned to them
     */
    public function isPatientOwnedByDoctor($patientId, $doctorId) {
        $sql = "SELECT COUNT(*) as count FROM patient_folders
                WHERE patient_id = :patient_id AND doctor_id = :doctor_id";
        $result = $this->db->fetch($sql, [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId
        ]);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Was this patient routed to this doctor by reception — or registered by
     * the doctor themselves?
     * True when an appointment or a waiting-room entry links the patient to
     * this doctor, OR when the doctor registered the patient (patients.
     * registered_by). All three columns key to users.id (same id space as
     * patient_folders.doctor_id). The registered_by signal lets a doctor who
     * adds a patient via patients/add.php open the profile and create the
     * first case immediately, without a visit or waiting-room entry.
     * A treatment plan the examining doctor (or reception, for «چەند
     * دکتۆرێک») sent to this doctor counts too — he has to open the patient
     * to book the visit and start the case.
     * It grants demographic visibility ONLY — never clinical/financial (work) data.
     */
    public function wasPatientReferredToDoctor($patientId, $doctorId) {
        $sql = "SELECT (
                    (SELECT COUNT(*) FROM appointments WHERE patient_id = :p1 AND doctor_id = :d1)
                  + (SELECT COUNT(*) FROM waiting_room  WHERE patient_id = :p2 AND doctor_id = :d2)
                  + (SELECT COUNT(*) FROM patients      WHERE id = :p3 AND registered_by = :d3)
                ) AS c";
        $result = $this->db->fetch($sql, [
            'p1' => $patientId, 'd1' => $doctorId,
            'p2' => $patientId, 'd2' => $doctorId,
            'p3' => $patientId, 'd3' => $doctorId,
        ]);
        if (((int)($result['c'] ?? 0)) > 0) {
            return true;
        }
        try {
            $plan = $this->db->fetch(
                "SELECT 1 AS ok FROM treatment_plans
                 WHERE patient_id = :p AND treating_doctor_id = :d AND status <> 'cancelled'
                 LIMIT 1",
                ['p' => $patientId, 'd' => $doctorId]
            );
        } catch (Exception $e) {
            return false; // installation still migrating — no plan routing yet
        }
        return !empty($plan);
    }

    /**
     * Can this doctor see the patient's GENERAL (demographic) info?
     *
     * Governed by the `allow_unassigned_patient_cases` transition switch
     * (admin settings → "دەستگەیشتنی نەرم"):
     *   ON  (default, data-transition phase) — any patient in the clinic, so the
     *        directory search is usable while historical data is still being
     *        organised.
     *   OFF (locked) — only patients the doctor owns, that reception referred to
     *        them (appointment / waiting-room), or that they registered.
     *
     * Either way this is demographics ONLY. It NEVER implies access to another
     * doctor's cases, folders, treatments, X-rays or finances — that is gated
     * separately by isPatientOwnedByDoctor() / AccessGuard::requirePatientWork(),
     * which stay owner-only in both modes.
     *
     * Soft-deleted patients stay hidden.
     */
    public function canDoctorViewPatientGeneral($patientId, $doctorId) {
        $row = $this->db->fetch(
            "SELECT 1 AS ok FROM patients WHERE id = :id AND deleted_at IS NULL",
            ['id' => $patientId]
        );
        if (empty($row)) {
            return false;
        }
        if (Settings::allowUnassignedPatientCases()) {
            return true;
        }
        return $this->isPatientOwnedByDoctor($patientId, $doctorId)
            || $this->wasPatientReferredToDoctor($patientId, $doctorId);
    }

    /**
     * May this doctor START a new case for this patient?
     *
     * Creating a case makes the doctor the owner of a (new) folder, so this is
     * the moment the transition switch actually matters:
     *   - always yes for a patient they already own or that reception referred /
     *     they registered themselves;
     *   - additionally yes for ANY patient while
     *     `allow_unassigned_patient_cases` is ON.
     * It never grants sight of an existing case belonging to another doctor.
     */
    public function canDoctorCreateCaseFor($patientId, $doctorId) {
        return $this->canDoctorViewPatientGeneral($patientId, $doctorId);
    }

    /**
     * Classify how a doctor reached this patient, for the unassigned-case audit
     * trail. Returns one of:
     *   'own'         — already owns a folder for the patient
     *   'referred'    — reception referral or they registered the patient
     *   'other_doctor'— patient belongs to other doctor(s) only
     *   'unassigned'  — nobody has a folder for this patient yet
     */
    public function doctorPatientRelation($patientId, $doctorId) {
        if ($this->isPatientOwnedByDoctor($patientId, $doctorId)) {
            return 'own';
        }
        if ($this->wasPatientReferredToDoctor($patientId, $doctorId)) {
            return 'referred';
        }
        return $this->patientHasOtherDoctorFolders($patientId, $doctorId)
            ? 'other_doctor'
            : 'unassigned';
    }

    /**
     * Get doctor's own patients: patients with folders assigned to this doctor
     * PLUS patients this doctor registered themselves (registered_by), so a
     * freshly added patient shows up here before any visit/folder exists.
     */
    public function getDoctorPatients($doctorId, $page = 1, $limit = ITEMS_PER_PAGE, $search = '', $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = ['doctor_id' => $doctorId];

        // total_debt (Task 15: repointed) — doctor-scoped subquery over the
        // derived ledger view, same join shape (cases -> patient_folders,
        // filtered to this doctor's folders) as the legacy pf mirror sum it
        // replaces.
        $sql = "SELECT DISTINCT p.*,
                (SELECT COUNT(*) FROM patient_folders pf WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id2) as folder_count,
                (SELECT COALESCE(SUM(vcs.balance), 0)
                 FROM cases cd
                 INNER JOIN patient_folders pfd ON pfd.id = cd.folder_id
                 INNER JOIN v_case_financial_summary vcs ON vcs.case_id = cd.id
                 WHERE pfd.patient_id = p.id AND pfd.doctor_id = :doctor_id3) as total_debt,
                " . $this->searchRank($search, $params) . " as search_rank
                FROM patients p
                WHERE p.deleted_at IS NULL
                AND (
                    EXISTS (SELECT 1 FROM patient_folders pf
                            WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id)
                    OR p.registered_by = :doctor_id_reg
                )";

        $params['doctor_id2'] = $doctorId;
        $params['doctor_id3'] = $doctorId;
        $params['doctor_id_reg'] = $doctorId;

        // Search filter (best matches first — see searchRank)
        $sql .= $this->searchWhere($search, $params);

        // Apply additional filters
        $sql .= $this->buildFilterConditions($filters, $params, $doctorId);

        $sql .= " ORDER BY search_rank, p.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count doctor's own patients (folder-owned OR self-registered — must
     * mirror getDoctorPatients).
     */
    public function countDoctorPatients($doctorId, $search = '', $filters = []) {
        $params = ['doctor_id' => $doctorId];

        $sql = "SELECT COUNT(DISTINCT p.id) as count
                FROM patients p
                WHERE p.deleted_at IS NULL
                AND (
                    EXISTS (SELECT 1 FROM patient_folders pf
                            WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id)
                    OR p.registered_by = :doctor_id_reg
                )";
        $params['doctor_id_reg'] = $doctorId;

        // Search filter
        $sql .= $this->searchWhere($search, $params);

        // Apply additional filters
        $sql .= $this->buildFilterConditions($filters, $params, $doctorId);

        return $this->db->fetch($sql, $params)['count'];
    }

    /**
     * The clinic-wide patient directory as a doctor sees it: every patient the
     * doctor does NOT already own, with GENERAL (demographic) columns only.
     *
     * Policy (set 2026-07-20, replacing a referral-only rule): a doctor may
     * find any patient in the clinic so the search box is useful, but this
     * SELECT list is deliberately narrow — no financial columns, no folder or
     * case data. Clinical/financial ("work") access stays owner-only and is
     * enforced separately by AccessGuard::requirePatientWork().
     *
     * Keep the column list in sync with getByIdRestricted().
     */
    public function getAllPatientsRestricted($doctorId, $page = 1, $limit = ITEMS_PER_PAGE, $search = '', $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = ['doctor_id' => $doctorId];

        $sql = "SELECT p.id, p.patient_code, p.full_name, p.phone, p.gender, p.date_of_birth, p.created_at,
                (SELECT COUNT(*) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed') as xray_count,
                " . $this->searchRank($search, $params) . " as search_rank
                FROM patients p
                WHERE p.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM patient_folders pf
                    WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id
                )";
        $sql .= $this->unassignedDirectoryScope($params, $doctorId);

        // Search filter (best matches first — see searchRank)
        $sql .= $this->searchWhere($search, $params);

        // Demographic filters only — buildFilterConditions() also handles debt and
        // folder-status filters, which must never widen this restricted view.
        $sql .= $this->buildRestrictedFilterConditions($filters, $params);

        $sql .= " ORDER BY search_rank, p.full_name ASC LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Extra WHERE clause for the clinic directory a doctor browses
     * (getAllPatientsRestricted / countAllPatientsRestricted).
     *
     * While `allow_unassigned_patient_cases` is ON the directory is the whole
     * clinic. When an admin switches it OFF the directory narrows back to
     * patients reception referred to this doctor or that the doctor registered —
     * the same rule canDoctorViewPatientGeneral() then enforces on the profile
     * page, so the list can never show a row the doctor cannot open.
     */
    private function unassignedDirectoryScope(&$params, $doctorId) {
        if (Settings::allowUnassignedPatientCases()) {
            return '';
        }
        $params['scope_doctor_a'] = $doctorId;
        $params['scope_doctor_w'] = $doctorId;
        $params['scope_doctor_r'] = $doctorId;
        return " AND (
                    EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = :scope_doctor_a)
                    OR EXISTS (SELECT 1 FROM waiting_room wr WHERE wr.patient_id = p.id AND wr.doctor_id = :scope_doctor_w)
                    OR p.registered_by = :scope_doctor_r
                )";
    }

    /**
     * Filter conditions safe for the restricted (other-doctors' patients) view.
     * Only demographic predicates; deliberately excludes the debt/folder filters
     * of buildFilterConditions() so no work data leaks through a filter.
     */
    private function buildRestrictedFilterConditions($filters, &$params) {
        $sql = '';
        if (!empty($filters['gender']) && in_array($filters['gender'], ['male', 'female'], true)) {
            $sql .= " AND p.gender = :f_gender";
            $params['f_gender'] = $filters['gender'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(p.created_at) >= :f_date_from";
            $params['f_date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(p.created_at) <= :f_date_to";
            $params['f_date_to'] = $filters['date_to'];
        }
        return $sql;
    }

    /**
     * Count for getAllPatientsRestricted — must mirror its WHERE clause exactly,
     * or pagination reports a different total than the list actually returns.
     */
    public function countAllPatientsRestricted($doctorId, $search = '', $filters = []) {
        $params = ['doctor_id' => $doctorId];

        $sql = "SELECT COUNT(*) as count FROM patients p
                WHERE p.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM patient_folders pf
                    WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id
                )";
        $sql .= $this->unassignedDirectoryScope($params, $doctorId);

        $sql .= $this->searchWhere($search, $params);

        $sql .= $this->buildRestrictedFilterConditions($filters, $params);

        return $this->db->fetch($sql, $params)['count'];
    }

    /**
     * Get patient's basic info only (restricted view)
     */
    public function getByIdRestricted($patientId) {
        $sql = "SELECT p.id, p.patient_code, p.full_name, p.phone, p.gender, p.date_of_birth, p.created_at
                FROM patients p WHERE p.id = :id";
        return $this->db->fetch($sql, ['id' => $patientId]);
    }

    /**
     * Get patient folders for a specific doctor only
     */
    public function getFoldersForDoctor($patientId, $doctorId) {
        $sql = "SELECT pf.*, u.full_name as doctor_name,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count
                FROM patient_folders pf
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE pf.patient_id = :patient_id AND pf.doctor_id = :doctor_id
                ORDER BY pf.created_at DESC";
        return $this->db->fetchAll($sql, [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId
        ]);
    }

    /**
     * Get statistics for a patient - doctor's own data only
     */
    public function getStatisticsForDoctor($patientId, $doctorId) {
        $stats = [];

        // Total visits (appointments with this doctor)
        $sql = "SELECT COUNT(*) as count FROM appointments
                WHERE patient_id = :id AND doctor_id = :doctor_id AND status = 'completed'";
        $stats['total_visits'] = $this->db->fetch($sql, ['id' => $patientId, 'doctor_id' => $doctorId])['count'];

        // Total paid (from doctor's folders only)
        $sql = "SELECT COALESCE(SUM(paid_amount), 0) as total FROM patient_folders
                WHERE patient_id = :id AND doctor_id = :doctor_id";
        $stats['total_paid'] = $this->db->fetch($sql, ['id' => $patientId, 'doctor_id' => $doctorId])['total'];

        // Total debt (from doctor's folders only — Task 15: repointed to the
        // derived ledger view, same doctor-scoped join shape as getDoctorPatients())
        $sql = "SELECT COALESCE(SUM(vcs.balance), 0) as total
                FROM cases c
                INNER JOIN patient_folders pf ON pf.id = c.folder_id
                INNER JOIN v_case_financial_summary vcs ON vcs.case_id = c.id
                WHERE pf.patient_id = :id AND pf.doctor_id = :doctor_id";
        $stats['total_debt'] = $this->db->fetch($sql, ['id' => $patientId, 'doctor_id' => $doctorId])['total'];

        // Active cases (from doctor's folders only)
        $sql = "SELECT COUNT(*) as count FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.patient_id = :id AND pf.doctor_id = :doctor_id AND c.status = 'open'";
        $stats['active_cases'] = $this->db->fetch($sql, ['id' => $patientId, 'doctor_id' => $doctorId])['count'];

        return $stats;
    }

    /**
     * Get doctor-specific statistics for their patients
     */
    public function getDoctorPatientsStats($doctorId) {
        $stats = [];

        // Total unique patients
        $sql = "SELECT COUNT(DISTINCT patient_id) as count FROM patient_folders WHERE doctor_id = :doctor_id";
        $stats['total_patients'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['count'];

        // New patients this month
        $sql = "SELECT COUNT(DISTINCT pf.patient_id) as count FROM patient_folders pf
                WHERE pf.doctor_id = :doctor_id
                AND YEAR(pf.created_at) = YEAR(CURDATE())
                AND MONTH(pf.created_at) = MONTH(CURDATE())";
        $stats['new_this_month'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['count'];

        // Active folders
        $sql = "SELECT COUNT(*) as count FROM patient_folders
                WHERE doctor_id = :doctor_id AND status = 'active'";
        $stats['active_folders'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['count'];

        // Closed folders
        $sql = "SELECT COUNT(*) as count FROM patient_folders
                WHERE doctor_id = :doctor_id AND status IN ('closed', 'archived')";
        $stats['closed_folders'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['count'];

        // Active cases
        $sql = "SELECT COUNT(*) as count FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.doctor_id = :doctor_id AND c.status = 'open'";
        $stats['active_cases'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['count'];

        // Patients with debt
        $sql = "SELECT COUNT(DISTINCT pf.patient_id) as count FROM patient_folders pf
                WHERE pf.doctor_id = :doctor_id
                AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0";
        $stats['patients_with_debt'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['count'];

        // Total debt amount
        $sql = "SELECT COALESCE(SUM(pf.total_amount - pf.paid_amount - pf.discount_amount), 0) as total
                FROM patient_folders pf
                WHERE pf.doctor_id = :doctor_id
                AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0";
        $stats['total_debt'] = $this->db->fetch($sql, ['doctor_id' => $doctorId])['total'];

        return $stats;
    }

    /**
     * Helper: Build filter conditions for queries
     */
    private function buildFilterConditions($filters, &$params, $doctorId = null) {
        $conditions = '';

        // Gender filter
        if (!empty($filters['gender'])) {
            $conditions .= " AND p.gender = :gender";
            $params['gender'] = $filters['gender'];
        }

        // Date range filter
        if (!empty($filters['date_from'])) {
            $conditions .= " AND DATE(p.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions .= " AND DATE(p.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        // Has debt filter (doctor-specific). Task 15: repointed to the derived
        // ledger view, same doctor-scoped join shape as getDoctorPatients().
        if (isset($filters['has_debt']) && $filters['has_debt'] !== '' && $doctorId) {
            if ($filters['has_debt'] == '1') {
                $conditions .= " AND EXISTS (SELECT 1 FROM cases chd
                                INNER JOIN patient_folders pfhd ON pfhd.id = chd.folder_id
                                INNER JOIN v_case_financial_summary vcshd ON vcshd.case_id = chd.id
                                WHERE pfhd.patient_id = p.id AND pfhd.doctor_id = :debt_doctor_id
                                AND vcshd.balance > 0)";
                $params['debt_doctor_id'] = $doctorId;
            } else {
                $conditions .= " AND NOT EXISTS (SELECT 1 FROM cases chd
                                INNER JOIN patient_folders pfhd ON pfhd.id = chd.folder_id
                                INNER JOIN v_case_financial_summary vcshd ON vcshd.case_id = chd.id
                                WHERE pfhd.patient_id = p.id AND pfhd.doctor_id = :debt_doctor_id
                                AND vcshd.balance > 0)";
                $params['debt_doctor_id'] = $doctorId;
            }
        }

        // Has medical alerts filter
        if (isset($filters['has_medical_alerts']) && $filters['has_medical_alerts'] !== '') {
            if ($filters['has_medical_alerts'] == '1') {
                $conditions .= " AND EXISTS (SELECT 1 FROM patient_medical_conditions pmc WHERE pmc.patient_id = p.id)";
            } else {
                $conditions .= " AND NOT EXISTS (SELECT 1 FROM patient_medical_conditions pmc WHERE pmc.patient_id = p.id)";
            }
        }

        // Folder status filter (doctor-specific)
        if (!empty($filters['folder_status']) && $doctorId) {
            $conditions .= " AND EXISTS (SELECT 1 FROM patient_folders pf3
                            WHERE pf3.patient_id = p.id AND pf3.doctor_id = :folder_doctor_id
                            AND pf3.status = :folder_status)";
            $params['folder_doctor_id'] = $doctorId;
            $params['folder_status'] = $filters['folder_status'];
        }

        return $conditions;
    }

    /**
     * Log patient data access for audit trail
     */
    public function logAccess($patientId, $userId, $accessType, $dataAccessed = null) {
        try {
            $this->db->insert('patient_access_logs', [
                'patient_id' => $patientId,
                'user_id' => $userId,
                'access_type' => $accessType, // 'view_profile', 'view_xray', 'view_folder', 'view_financial'
                'data_accessed' => $dataAccessed,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            return true;
        } catch (Exception $e) {
            // Log error but don't break the application
            error_log("Failed to log patient access: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if patient has folders from OTHER doctors (not the specified doctor)
     * Used to show appropriate message when doctor views patient they don't own
     */
    public function patientHasOtherDoctorFolders($patientId, $doctorId) {
        $sql = "SELECT COUNT(*) as count FROM patient_folders
                WHERE patient_id = :patient_id AND doctor_id != :doctor_id";
        $result = $this->db->fetch($sql, [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId
        ]);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get total folder count for patient (from all doctors)
     */
    public function getTotalFolderCount($patientId) {
        $sql = "SELECT COUNT(*) as count FROM patient_folders WHERE patient_id = :patient_id";
        return $this->db->fetch($sql, ['patient_id' => $patientId])['count'] ?? 0;
    }

    // ==========================================
    // ADMIN PERMISSION CONTROL METHODS
    // ==========================================

    /**
     * Get doctor's permissions from admin settings
     * Returns default permissions if not set
     */
    public function getDoctorPermissions($doctorId) {
        $sql = "SELECT * FROM doctor_permissions WHERE doctor_id = :doctor_id";
        $result = $this->db->fetch($sql, ['doctor_id' => $doctorId]);

        // Deny by default. A doctor must never see another doctor's patients'
        // X-rays or profiles unless (a) reception referred the patient to them
        // — handled by getDoctorAccessLevel — or (b) an admin explicitly grants
        // a wider cross-doctor permission here.
        if (!$result) {
            return [
                'can_view_other_xrays' => 0,      // Default: NO cross-doctor X-ray access
                'can_view_other_profiles' => 0    // Default: NO cross-doctor profile access
            ];
        }

        return $result;
    }

    /**
     * Check if doctor can view X-rays of other doctors' patients
     */
    public function canDoctorViewOtherXrays($doctorId) {
        $permissions = $this->getDoctorPermissions($doctorId);
        return (bool)($permissions['can_view_other_xrays'] ?? 1);
    }

    /**
     * Check if doctor can view profiles of other doctors' patients
     */
    public function canDoctorViewOtherProfiles($doctorId) {
        $permissions = $this->getDoctorPermissions($doctorId);
        return (bool)($permissions['can_view_other_profiles'] ?? 1);
    }

    /**
     * Check if doctor can access patient based on admin permissions
     * Returns: 'full' | 'profile_xray' | 'profile_only' | 'xray_only' | 'denied'
     */
    public function getDoctorAccessLevel($patientId, $doctorId) {
        // Own patient (has a folder assigned to this doctor): full access.
        if ($this->isPatientOwnedByDoctor($patientId, $doctorId)) {
            return 'full';
        }

        // Not owned: GENERAL visibility follows exactly the same rule as the
        // patient directory (canDoctorViewPatientGeneral) — reception referral /
        // self-registration, plus every patient while the
        // `allow_unassigned_patient_cases` switch is ON. Keeping the two in sync
        // matters: if the list can show a patient the profile must open, or the
        // doctor gets a dead row that redirects to "no permission".
        // The doctor still never sees another doctor's cases/finances (gated by
        // isPatientOwnedByDoctor everywhere work data is read).
        $referred = $this->canDoctorViewPatientGeneral($patientId, $doctorId);

        // Admin overrides (default off) can additionally widen cross-doctor view.
        $canViewProfiles = $this->canDoctorViewOtherProfiles($doctorId);
        $canViewXrays = $this->canDoctorViewOtherXrays($doctorId);

        $profileAllowed = $referred || $canViewProfiles;

        if (!$profileAllowed && !$canViewXrays) {
            return 'denied'; // No reception referral and no admin grant → no access
        }

        if ($profileAllowed && $canViewXrays) {
            return 'profile_xray';
        } elseif ($profileAllowed) {
            return 'profile_only'; // General info only — no X-rays, no work data
        } else {
            return 'xray_only';
        }
    }
}
