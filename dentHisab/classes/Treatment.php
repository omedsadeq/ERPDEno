<?php
/**
 * Treatment Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Treatment {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Generate treatment code
     */
    private function generateCode() {
        $sql = "SELECT MAX(CAST(SUBSTRING(treatment_code, 4) AS UNSIGNED)) as max_num FROM treatments";
        $result = $this->db->fetch($sql);
        $nextNum = ($result['max_num'] ?? 0) + 1;
        return "TRT" . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Create new treatment
     */
    public function create($data) {
        if (empty($data['treatment_code'])) {
            $data['treatment_code'] = $this->generateCode();
        }

        try {
            $treatmentId = $this->db->insert('treatments', $data);
            return ['success' => true, 'treatment_id' => $treatmentId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update treatment
     */
    public function update($id, $data) {
        try {
            $this->db->update('treatments', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get treatment by ID
     */
    public function getById($id) {
        $sql = "SELECT * FROM treatments WHERE id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get all treatments
     */
    public function getAll($activeOnly = true) {
        $sql = "SELECT * FROM treatments WHERE 1=1";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY category, treatment_name";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get treatments by category
     */
    public function getByCategory($category) {
        $sql = "SELECT * FROM treatments WHERE category = :category AND is_active = 1 ORDER BY treatment_name";
        return $this->db->fetchAll($sql, ['category' => $category]);
    }

    /**
     * Get treatments by scope (tooth, arch, or both)
     * @param string $scope 'tooth' or 'arch'
     * @return array Treatments matching the scope
     */
    public function getByScope($scope) {
        // Include treatments that match the scope OR are 'both'
        $sql = "SELECT * FROM treatments
                WHERE is_active = 1
                AND (scope = :scope OR scope = 'both')
                ORDER BY category, treatment_name";
        return $this->db->fetchAll($sql, ['scope' => $scope]);
    }

    /**
     * Get treatment categories
     */
    public function getCategories() {
        return [
            'examination' => 'پشکنین',
            'cleaning' => 'پاککردنەوە',
            'filling' => 'پڕکردنەوە',
            'extraction' => 'ڕاکێشان',
            'root_canal' => 'چارەسەری دەمار',
            'crown' => 'تاج',
            'bridge' => 'پرد',
            'implant' => 'چاندن',
            'orthodontic' => 'ڕاستکردنەوە',
            'whitening' => 'سپیکردنەوە',
            'cosmetic' => 'جوانکاری',
            'surgery' => 'نەشتەرگەری',
            'other' => 'هی تر'
        ];
    }

    /**
     * Search treatments
     */
    public function search($term) {
        $sql = "SELECT * FROM treatments
                WHERE (treatment_name LIKE :term OR treatment_name_en LIKE :term2 OR treatment_code LIKE :term3)
                AND is_active = 1
                ORDER BY treatment_name";
        return $this->db->fetchAll($sql, [
            'term' => "%{$term}%",
            'term2' => "%{$term}%",
            'term3' => "%{$term}%"
        ]);
    }

    /**
     * Delete treatment (soft delete)
     */
    public function delete($id) {
        return $this->update($id, ['is_active' => 0]);
    }

    /**
     * Get treatment statistics
     */
    public function getStatistics($dateFrom = null, $dateTo = null) {
        $params = [];
        $where = "1=1";

        if ($dateFrom) {
            $where .= " AND DATE(ct.created_at) >= :date_from";
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= " AND DATE(ct.created_at) <= :date_to";
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT t.id, t.treatment_name, t.category,
                COUNT(ct.id) as usage_count,
                SUM(ct.final_price) as total_revenue
                FROM treatments t
                LEFT JOIN case_treatments ct ON t.id = ct.treatment_id AND {$where}
                GROUP BY t.id
                ORDER BY usage_count DESC";

        return $this->db->fetchAll($sql, $params);
    }
}
