<?php
/**
 * Arch Procedure Log Class
 * کلاسی تۆمارکردنی کارەکانی ئارچ
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CaseManager.php';

class ArchProcedureLog {
    private $db;
    private $caseManager;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->caseManager = new CaseManager();
    }

    /**
     * Get all procedure types
     * @param bool $activeOnly Only return active types
     * @return array
     */
    public function getProcedureTypes($activeOnly = true) {
        $sql = "SELECT * FROM arch_procedure_types WHERE 1=1";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order, type_name";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get procedure type by ID
     * @param int $id
     * @return array|null
     */
    public function getProcedureTypeById($id) {
        $sql = "SELECT * FROM arch_procedure_types WHERE id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get procedure type by code
     * @param string $code
     * @return array|null
     */
    public function getProcedureTypeByCode($code) {
        $sql = "SELECT * FROM arch_procedure_types WHERE type_code = :code";
        return $this->db->fetch($sql, ['code' => $code]);
    }

    /**
     * Add new procedure type
     * @param array $data
     * @return array
     */
    public function addProcedureType($data) {
        try {
            $insertData = [
                'type_code' => $data['type_code'],
                'type_name' => $data['type_name'],
                'type_name_en' => $data['type_name_en'] ?? null,
                'description' => $data['description'] ?? null,
                'default_price' => floatval($data['default_price'] ?? 0),
                'sort_order' => intval($data['sort_order'] ?? 99)
            ];
            $id = $this->db->insert('arch_procedure_types', $insertData);
            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update procedure type
     * @param int $id
     * @param array $data
     * @return array
     */
    public function updateProcedureType($id, $data) {
        try {
            $updateData = [];
            if (isset($data['type_name'])) $updateData['type_name'] = $data['type_name'];
            if (isset($data['type_name_en'])) $updateData['type_name_en'] = $data['type_name_en'];
            if (isset($data['description'])) $updateData['description'] = $data['description'];
            if (isset($data['default_price'])) $updateData['default_price'] = floatval($data['default_price']);
            if (isset($data['is_active'])) $updateData['is_active'] = intval($data['is_active']);
            if (isset($data['sort_order'])) $updateData['sort_order'] = intval($data['sort_order']);

            $this->db->update('arch_procedure_types', $updateData, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Add procedure log
     * @param array $data
     * @return array
     */
    public function addLog($data) {
        try {
            $caseId = intval($data['case_id']);
            if (empty($data['procedure_date'])) {
                return ['success' => false, 'message' => 'بەرواری کارەکە پێویستە'];
            }
            if (floatval($data['price'] ?? 0) < 0) {
                return ['success' => false, 'message' => 'نرخ ناتوانێت کەمتر لە سفر بێت'];
            }
            $insertData = [
                'case_id' => $caseId,
                'procedure_type_id' => !empty($data['procedure_type_id']) ? intval($data['procedure_type_id']) : null,
                'custom_procedure_name' => !empty($data['custom_procedure_name']) ? trim($data['custom_procedure_name']) : null,
                'arch_affected' => $data['arch_affected'] ?? 'both',
                'procedure_date' => $data['procedure_date'],
                'procedure_time' => !empty($data['procedure_time']) ? $data['procedure_time'] : null,
                'notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                'price' => floatval($data['price'] ?? 0),
                'is_paid' => 0, // Always 0, payments tracked through payment system
                'next_appointment_date' => !empty($data['next_appointment_date']) ? $data['next_appointment_date'] : null,
                'next_appointment_notes' => !empty($data['next_appointment_notes']) ? trim($data['next_appointment_notes']) : null,
                'performed_by' => !empty($data['performed_by']) ? intval($data['performed_by']) : null
            ];

            $this->db->beginTransaction();
            $id = $this->db->insert('arch_procedure_logs', $insertData);

            // Update case totals to reflect the new procedure price
            $this->caseManager->updateCaseTotals($caseId);
            $this->db->commit();

            return ['success' => true, 'log_id' => $id];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update procedure log
     * @param int $id
     * @param array $data
     * @return array
     */
    public function updateLog($id, $data, $expectedCaseId = null) {
        try {
            // Get the log first to find case_id
            $log = $this->getLogById($id);
            if (!$log) {
                return ['success' => false, 'message' => 'Log not found'];
            }
            // Ownership guard: the log must belong to the case the caller is on
            // (prevents cross-case editing/repricing of another case's log).
            if ($expectedCaseId !== null && (int)$log['case_id'] !== (int)$expectedCaseId) {
                return ['success' => false, 'message' => 'Not allowed'];
            }

            $updateData = [];

            if (isset($data['procedure_type_id'])) {
                $updateData['procedure_type_id'] = !empty($data['procedure_type_id']) ? intval($data['procedure_type_id']) : null;
            }
            if (isset($data['custom_procedure_name'])) {
                $updateData['custom_procedure_name'] = !empty($data['custom_procedure_name']) ? trim($data['custom_procedure_name']) : null;
            }
            if (isset($data['arch_affected'])) {
                $updateData['arch_affected'] = $data['arch_affected'];
            }
            if (isset($data['procedure_date'])) {
                $updateData['procedure_date'] = $data['procedure_date'];
            }
            if (isset($data['procedure_time'])) {
                $updateData['procedure_time'] = !empty($data['procedure_time']) ? $data['procedure_time'] : null;
            }
            if (isset($data['notes'])) {
                $updateData['notes'] = !empty($data['notes']) ? trim($data['notes']) : null;
            }
            if (isset($data['price'])) {
                $updateData['price'] = floatval($data['price']);
            }
            // is_paid is no longer used - payments tracked through payment system
            if (isset($data['next_appointment_date'])) {
                $updateData['next_appointment_date'] = !empty($data['next_appointment_date']) ? $data['next_appointment_date'] : null;
            }
            if (isset($data['next_appointment_notes'])) {
                $updateData['next_appointment_notes'] = !empty($data['next_appointment_notes']) ? trim($data['next_appointment_notes']) : null;
            }

            $this->db->beginTransaction();
            $this->db->update('arch_procedure_logs', $updateData, 'id = :id', ['id' => $id]);

            // Update case totals if price was changed
            if (isset($data['price'])) {
                $this->caseManager->updateCaseTotals($log['case_id']);
            }
            $this->db->commit();

            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete procedure log
     * @param int $id
     * @return array
     */
    public function deleteLog($id, $expectedCaseId = null) {
        try {
            // Get the log first to find case_id
            $log = $this->getLogById($id);
            if (!$log) {
                return ['success' => false, 'message' => 'Log not found'];
            }
            // Ownership guard: the log must belong to the caller's case
            if ($expectedCaseId !== null && (int)$log['case_id'] !== (int)$expectedCaseId) {
                return ['success' => false, 'message' => 'Not allowed'];
            }

            $caseId = $log['case_id'];
            $this->db->beginTransaction();
            $this->db->delete('arch_procedure_logs', 'id = :id', ['id' => $id]);

            // Update case totals after deletion
            $this->caseManager->updateCaseTotals($caseId);
            $this->db->commit();

            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get log by ID
     * @param int $id
     * @return array|null
     */
    public function getLogById($id) {
        $sql = "SELECT apl.*,
                apt.type_code, apt.type_name, apt.type_name_en,
                COALESCE(apl.custom_procedure_name, apt.type_name) as display_name,
                u.full_name as performed_by_name
                FROM arch_procedure_logs apl
                LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id = apt.id
                LEFT JOIN users u ON apl.performed_by = u.id
                WHERE apl.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get logs by case ID
     * @param int $caseId
     * @param string $orderBy
     * @return array
     */
    public function getLogsByCase($caseId, $orderBy = 'DESC') {
        // Whitelist — $orderBy is interpolated into the SQL below
        $orderBy = strtoupper($orderBy) === 'ASC' ? 'ASC' : 'DESC';
        $sql = "SELECT apl.*,
                apt.type_code, apt.type_name, apt.type_name_en,
                COALESCE(apl.custom_procedure_name, apt.type_name) as display_name,
                u.full_name as performed_by_name
                FROM arch_procedure_logs apl
                LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id = apt.id
                LEFT JOIN users u ON apl.performed_by = u.id
                WHERE apl.case_id = :case_id
                ORDER BY apl.procedure_date {$orderBy}, apl.created_at {$orderBy}";
        return $this->db->fetchAll($sql, ['case_id' => $caseId]);
    }

    /**
     * Get case procedure statistics
     * @param int $caseId
     * @return array
     */
    public function getCaseStats($caseId) {
        $sql = "SELECT
                COUNT(*) as total_visits,
                SUM(price) as total_charged,
                SUM(CASE WHEN is_paid = 1 THEN price ELSE 0 END) as total_paid,
                MIN(procedure_date) as first_visit,
                MAX(procedure_date) as last_visit,
                MAX(next_appointment_date) as next_appointment
                FROM arch_procedure_logs
                WHERE case_id = :case_id";
        return $this->db->fetch($sql, ['case_id' => $caseId]);
    }

    /**
     * Get procedure type breakdown for a case
     * @param int $caseId
     * @return array
     */
    public function getCaseProcedureBreakdown($caseId) {
        $sql = "SELECT
                apt.type_name,
                apt.type_code,
                COUNT(*) as count,
                SUM(apl.price) as total_price
                FROM arch_procedure_logs apl
                LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id = apt.id
                WHERE apl.case_id = :case_id
                GROUP BY apl.procedure_type_id
                ORDER BY count DESC";
        return $this->db->fetchAll($sql, ['case_id' => $caseId]);
    }

    /**
     * Get upcoming appointments
     * @param int|null $doctorId Filter by doctor
     * @param int $days Number of days ahead to look
     * @return array
     */
    public function getUpcomingAppointments($doctorId = null, $days = 30) {
        $sql = "SELECT apl.*,
                apt.type_name,
                c.case_number, c.case_name,
                pf.folder_name,
                p.full_name as patient_name, p.phone as patient_phone,
                u.full_name as doctor_name
                FROM arch_procedure_logs apl
                LEFT JOIN arch_procedure_types apt ON apl.procedure_type_id = apt.id
                INNER JOIN cases c ON apl.case_id = c.id
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE apl.next_appointment_date IS NOT NULL
                AND apl.next_appointment_date >= CURDATE()
                AND apl.next_appointment_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)";

        $params = ['days' => $days];

        if ($doctorId) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        $sql .= " ORDER BY apl.next_appointment_date ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get arch label in Kurdish
     * @param string $arch
     * @return string
     */
    public function getArchLabel($arch) {
        $labels = [
            'upper' => 'سەرەوە',
            'lower' => 'خوارەوە',
            'both' => 'هەردووک'
        ];
        return $labels[$arch] ?? $arch;
    }

    /**
     * Check if tables exist (for migration status)
     * @return bool
     */
    public function tablesExist() {
        try {
            $sql = "SHOW TABLES LIKE 'arch_procedure_logs'";
            $result = $this->db->fetch($sql);
            return !empty($result);
        } catch (Exception $e) {
            return false;
        }
    }
}
