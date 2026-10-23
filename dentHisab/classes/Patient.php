<?php
/**
 * Patient Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

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
        // total_debt must subtract discount_amount (matches getStatistics,
        // getAll, getStatisticsForDoctor and the debt API) and COALESCE to 0
        // so a patient with no folders reports 0, not NULL.
        $sql = "SELECT p.*,
                COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as total_debt,
                COALESCE((SELECT SUM(pf.paid_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) as total_paid
                FROM patients p WHERE p.id = :id";
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

        $sql = "SELECT p.*,
                (SELECT COUNT(*) FROM patient_folders pf WHERE pf.patient_id = p.id) as folder_count,
                (COALESCE((SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) FROM patient_folders pf WHERE pf.patient_id = p.id), 0) +
                 COALESCE((SELECT SUM(xr.final_amount) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'), 0)) as total_debt
                FROM patients p WHERE p.deleted_at IS NULL";

        // Search filter
        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

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

        // Has debt filter (includes both folder debt and X-ray debt)
        if (isset($filters['has_debt']) && $filters['has_debt'] !== '') {
            if ($filters['has_debt'] == '1') {
                $sql .= " AND (EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0)
                          OR EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'))";
            } else {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0)
                         AND NOT EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt')";
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

        $sql .= " ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count patients with advanced filters
     */
    public function count($search = '', $filters = []) {
        $sql = "SELECT COUNT(*) as count FROM patients p WHERE p.deleted_at IS NULL";
        $params = [];

        // Search filter
        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

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

        // Has debt filter (includes both folder debt and X-ray debt)
        if (isset($filters['has_debt']) && $filters['has_debt'] !== '') {
            if ($filters['has_debt'] == '1') {
                $sql .= " AND (EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0)
                          OR EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt'))";
            } else {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM patient_folders pf WHERE pf.patient_id = p.id AND (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0)
                         AND NOT EXISTS (SELECT 1 FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed' AND xr.payment_status = 'debt')";
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
                WHERE is2.status IN ('pending', 'partial') AND is2.due_date < CURDATE()
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

        // Total debt
        $sql = "SELECT SUM(total_amount - paid_amount - discount_amount) as total FROM patient_folders WHERE patient_id = :id";
        $stats['total_debt'] = $this->db->fetch($sql, ['id' => $patientId])['total'] ?? 0;

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
        $sql = "SELECT COUNT(DISTINCT pf.patient_id) as count
                FROM patient_folders pf
                WHERE (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0";
        return $this->db->fetch($sql)['count'];
    }

    /**
     * Get total debt amount
     */
    public function getTotalDebtAmount() {
        $sql = "SELECT SUM(pf.total_amount - pf.paid_amount - pf.discount_amount) as total
                FROM patient_folders pf
                WHERE (pf.total_amount - pf.paid_amount - pf.discount_amount) > 0";
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
     * Was this patient routed to this doctor by reception?
     * True when an appointment or a waiting-room entry links the patient to
     * this doctor. Both tables key doctor_id to users.id (same id space as
     * patient_folders.doctor_id), so this is the reception "referral" signal
     * that lets a doctor see the patient's GENERAL info before they own a folder.
     * It grants demographic visibility ONLY — never clinical/financial (work) data.
     */
    public function wasPatientReferredToDoctor($patientId, $doctorId) {
        $sql = "SELECT (
                    (SELECT COUNT(*) FROM appointments WHERE patient_id = :p1 AND doctor_id = :d1)
                  + (SELECT COUNT(*) FROM waiting_room  WHERE patient_id = :p2 AND doctor_id = :d2)
                ) AS c";
        $result = $this->db->fetch($sql, [
            'p1' => $patientId, 'd1' => $doctorId,
            'p2' => $patientId, 'd2' => $doctorId,
        ]);
        return ((int)($result['c'] ?? 0)) > 0;
    }

    /**
     * Can this doctor see the patient's GENERAL (demographic) info?
     * Yes if they own a folder for the patient, OR reception referred the
     * patient to them (appointment / waiting-room). This NEVER implies access
     * to another doctor's cases, folders, treatments, X-rays or finances —
     * that is gated separately by isPatientOwnedByDoctor().
     */
    public function canDoctorViewPatientGeneral($patientId, $doctorId) {
        return $this->isPatientOwnedByDoctor($patientId, $doctorId)
            || $this->wasPatientReferredToDoctor($patientId, $doctorId);
    }

    /**
     * Get doctor's own patients (patients with folders assigned to this doctor)
     */
    public function getDoctorPatients($doctorId, $page = 1, $limit = ITEMS_PER_PAGE, $search = '', $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = ['doctor_id' => $doctorId];

        $sql = "SELECT DISTINCT p.*,
                (SELECT COUNT(*) FROM patient_folders pf WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id2) as folder_count,
                (SELECT COALESCE(SUM(pf.total_amount - pf.paid_amount - pf.discount_amount), 0)
                 FROM patient_folders pf WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id3) as total_debt
                FROM patients p
                INNER JOIN patient_folders pf ON p.id = pf.patient_id
                WHERE pf.doctor_id = :doctor_id AND p.deleted_at IS NULL";

        $params['doctor_id2'] = $doctorId;
        $params['doctor_id3'] = $doctorId;

        // Search filter
        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

        // Apply additional filters
        $sql .= $this->buildFilterConditions($filters, $params, $doctorId);

        $sql .= " ORDER BY p.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count doctor's own patients
     */
    public function countDoctorPatients($doctorId, $search = '', $filters = []) {
        $params = ['doctor_id' => $doctorId];

        $sql = "SELECT COUNT(DISTINCT p.id) as count
                FROM patients p
                INNER JOIN patient_folders pf ON p.id = pf.patient_id
                WHERE pf.doctor_id = :doctor_id AND p.deleted_at IS NULL";

        // Search filter
        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

        // Apply additional filters
        $sql .= $this->buildFilterConditions($filters, $params, $doctorId);

        return $this->db->fetch($sql, $params)['count'];
    }

    /**
     * Get all clinic patients with restricted info (for doctors viewing other doctors' patients)
     * Returns only basic info: name, code, phone, gender, age
     */
    public function getAllPatientsRestricted($doctorId, $page = 1, $limit = ITEMS_PER_PAGE, $search = '') {
        $offset = ($page - 1) * $limit;
        $params = ['doctor_id' => $doctorId];

        // Patients the doctor does NOT own but reception referred to them
        // (an appointment or waiting-room entry links them). This is the
        // "other patients" list — general info only, and ONLY for referred
        // patients, never the whole clinic directory.
        $sql = "SELECT p.id, p.patient_code, p.full_name, p.phone, p.gender, p.date_of_birth, p.created_at,
                (SELECT COUNT(*) FROM xray_requests xr WHERE xr.patient_id = p.id AND xr.status = 'completed') as xray_count
                FROM patients p
                WHERE p.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM patient_folders pf
                    WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id
                )
                AND (
                    EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = :doctor_id_a)
                    OR EXISTS (SELECT 1 FROM waiting_room wr WHERE wr.patient_id = p.id AND wr.doctor_id = :doctor_id_w)
                )";
        $params['doctor_id_a'] = $doctorId;
        $params['doctor_id_w'] = $doctorId;

        // Search filter
        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

        $sql .= " ORDER BY p.full_name ASC LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count all clinic patients (excluding doctor's own)
     */
    public function countAllPatientsRestricted($doctorId, $search = '') {
        $params = ['doctor_id' => $doctorId];

        $sql = "SELECT COUNT(*) as count FROM patients p
                WHERE p.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM patient_folders pf
                    WHERE pf.patient_id = p.id AND pf.doctor_id = :doctor_id
                )
                AND (
                    EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND a.doctor_id = :doctor_id_a)
                    OR EXISTS (SELECT 1 FROM waiting_room wr WHERE wr.patient_id = p.id AND wr.doctor_id = :doctor_id_w)
                )";
        $params['doctor_id_a'] = $doctorId;
        $params['doctor_id_w'] = $doctorId;

        if (!empty($search)) {
            $sql .= " AND (p.full_name LIKE :search OR p.phone LIKE :search2 OR p.patient_code LIKE :search3)";
            $params['search'] = "%{$search}%";
            $params['search2'] = "%{$search}%";
            $params['search3'] = "%{$search}%";
        }

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

        // Total debt (from doctor's folders only)
        $sql = "SELECT COALESCE(SUM(total_amount - paid_amount - discount_amount), 0) as total
                FROM patient_folders WHERE patient_id = :id AND doctor_id = :doctor_id";
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

        // Has debt filter (doctor-specific)
        if (isset($filters['has_debt']) && $filters['has_debt'] !== '' && $doctorId) {
            if ($filters['has_debt'] == '1') {
                $conditions .= " AND EXISTS (SELECT 1 FROM patient_folders pf2
                                WHERE pf2.patient_id = p.id AND pf2.doctor_id = :debt_doctor_id
                                AND (pf2.total_amount - pf2.paid_amount - pf2.discount_amount) > 0)";
                $params['debt_doctor_id'] = $doctorId;
            } else {
                $conditions .= " AND NOT EXISTS (SELECT 1 FROM patient_folders pf2
                                WHERE pf2.patient_id = p.id AND pf2.doctor_id = :debt_doctor_id
                                AND (pf2.total_amount - pf2.paid_amount - pf2.discount_amount) > 0)";
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

        // Not owned: the doctor gets access ONLY if reception routed this patient
        // to them (appointment / waiting-room). That grants GENERAL info; the
        // doctor still never sees another doctor's cases/finances (that is gated
        // by isPatientOwnedByDoctor everywhere work data is read).
        $referred = $this->wasPatientReferredToDoctor($patientId, $doctorId);

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
