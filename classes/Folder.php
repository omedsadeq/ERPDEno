<?php
/**
 * Patient Folder Class (Level 2 of hierarchy)
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Folder {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create new folder
     */
    public function create($data) {
        // doctor_id is the assigned doctor; created_by records who actually created the folder
        if (empty($data['created_by']) && !empty($_SESSION['user_id'])) {
            $data['created_by'] = (int)$_SESSION['user_id'];
        }
        try {
            $folderId = $this->db->insert('patient_folders', $data);
            return ['success' => true, 'folder_id' => $folderId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Auto-Folder: the UI works with Patient → Case directly; the folder layer
     * stays as a hidden technical level. Returns the id of the patient's
     * active folder for this doctor, creating one silently when needed.
     */
    public function findOrCreateDefault($patientId, $doctorId) {
        $patientId = (int)$patientId;
        $doctorId = (int)$doctorId;
        if ($patientId <= 0 || $doctorId <= 0) {
            return null;
        }

        $existing = $this->db->fetch(
            "SELECT id FROM patient_folders
             WHERE patient_id = :patient_id AND doctor_id = :doctor_id AND status = 'active'
             ORDER BY id ASC
             LIMIT 1",
            ['patient_id' => $patientId, 'doctor_id' => $doctorId]
        );
        if ($existing) {
            return (int)$existing['id'];
        }

        $result = $this->create([
            'patient_id'  => $patientId,
            'doctor_id'   => $doctorId,
            'folder_name' => 'دۆسیەی گشتی',
            'folder_type' => 'general',
            'notes'       => ''
        ]);

        return !empty($result['success']) ? (int)$result['folder_id'] : null;
    }

    /**
     * Update folder
     */
    public function update($id, $data) {
        try {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('patient_folders', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get folder by ID
     */
    public function getById($id) {
        $sql = "SELECT pf.*, p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                u.full_name as doctor_name,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id AND c.status = 'open') as open_cases
                FROM patient_folders pf
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE pf.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get folders by patient
     */
    public function getByPatient($patientId, $status = null) {
        $sql = "SELECT pf.*, u.full_name as doctor_name,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id AND c.status = 'open') as open_cases
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
     * Get folders by doctor
     */
    public function getByDoctor($doctorId, $status = null, $page = 1, $limit = ITEMS_PER_PAGE) {
        $offset = ($page - 1) * $limit;
        $sql = "SELECT pf.*, p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id AND c.status = 'open') as open_cases
                FROM patient_folders pf
                LEFT JOIN patients p ON pf.patient_id = p.id
                WHERE pf.doctor_id = :doctor_id";
        $params = ['doctor_id' => $doctorId];

        if ($status) {
            $sql .= " AND pf.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY pf.updated_at DESC LIMIT {$limit} OFFSET {$offset}";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count folders by doctor
     */
    public function countByDoctor($doctorId, $status = null) {
        $sql = "SELECT COUNT(*) as count FROM patient_folders WHERE doctor_id = :doctor_id";
        $params = ['doctor_id' => $doctorId];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        return $this->db->fetch($sql, $params)['count'];
    }

    /**
     * Archive folder (close it)
     * @param int $id Folder ID
     * @param string $closingComment Optional closing comment
     */
    public function archive($id, $closingComment = null) {
        // Check if all cases are closed
        $sql = "SELECT COUNT(*) as count FROM cases WHERE folder_id = :folder_id AND status = 'open'";
        $openCases = $this->db->fetch($sql, ['folder_id' => $id])['count'];

        if ($openCases > 0) {
            return ['success' => false, 'message' => 'ناتوانیت دۆسیەکە بدایت تا هەموو کەیسەکان نەدابخرێن'];
        }

        $data = [
            'status' => FOLDER_ARCHIVED,
            'closed_at' => date('Y-m-d H:i:s'),
            'closing_comment' => $closingComment
        ];

        try {
            $this->db->update('patient_folders', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Reopen folder
     */
    public function reopen($id) {
        $data = [
            'status' => FOLDER_ACTIVE,
            'closed_at' => null
        ];

        try {
            $this->db->update('patient_folders', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update folder totals
     */
    public function updateTotals($id) {
        $sql = "SELECT SUM(total_amount) as total, SUM(paid_amount) as paid, SUM(discount_amount) as discount
                FROM cases WHERE folder_id = :folder_id";
        $totals = $this->db->fetch($sql, ['folder_id' => $id]);

        $data = [
            'total_amount' => $totals['total'] ?? 0,
            'paid_amount' => $totals['paid'] ?? 0,
            'discount_amount' => $totals['discount'] ?? 0
        ];

        $this->db->update('patient_folders', $data, 'id = :id', ['id' => $id]);
    }

    /**
     * Get all folders
     */
    public function getAll($page = 1, $limit = ITEMS_PER_PAGE, $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = [];

        $sql = "SELECT pf.*, p.full_name as patient_name, p.patient_code,
                u.full_name as doctor_name,
                (SELECT COUNT(*) FROM cases c WHERE c.folder_id = pf.id) as case_count
                FROM patient_folders pf
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE 1=1";

        if (!empty($filters['status'])) {
            $sql .= " AND pf.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['doctor_id'])) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $filters['doctor_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.full_name LIKE :search OR p.patient_code LIKE :search2)";
            $params['search'] = "%{$filters['search']}%";
            $params['search2'] = "%{$filters['search']}%";
        }

        $sql .= " ORDER BY pf.updated_at DESC LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Delete folder
     */
    public function delete($id) {
        try {
            $this->db->delete('patient_folders', 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get folder statistics
     */
    public function getStatistics($id) {
        $stats = [];

        // Cases count by status
        $sql = "SELECT status, COUNT(*) as count FROM cases WHERE folder_id = :id GROUP BY status";
        $caseStats = $this->db->fetchAll($sql, ['id' => $id]);
        $stats['cases'] = [];
        foreach ($caseStats as $stat) {
            $stats['cases'][$stat['status']] = $stat['count'];
        }

        // Treatments count
        $sql = "SELECT COUNT(*) as count FROM case_treatments ct
                INNER JOIN cases c ON ct.case_id = c.id
                WHERE c.folder_id = :id";
        $stats['treatments'] = $this->db->fetch($sql, ['id' => $id])['count'];

        // Payments
        $sql = "SELECT SUM(amount) as total FROM payments pm
                INNER JOIN cases c ON pm.case_id = c.id
                WHERE c.folder_id = :id AND pm.status = 'approved'";
        $stats['total_paid'] = $this->db->fetch($sql, ['id' => $id])['total'] ?? 0;

        return $stats;
    }
}
