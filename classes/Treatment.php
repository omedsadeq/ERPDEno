<?php
/**
 * Treatment Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class Treatment {
    /** The icon keys a treatment type may carry (drawn by assets/js/treatment-icons.js). */
    const ICONS = [
        'exam', 'filling', 'filling_cosmetic', 'temporary', 'cleaning', 'extraction', 'surgical_extraction',
        'root_canal', 'retreatment', 'post', 'crown', 'crown_metal', 'cementation', 'bridge', 'implant',
        'orthodontic', 'whitening', 'veneer', 'gem', 'pediatric', 'surgery', 'denture', 'xray', 'sealant',
        'fluoride', 'pulpotomy', 'inlay', 'apicoectomy', 'partial_denture', 'night_guard', 'splint',
        'space_maintainer', 'gum_surgery', 'bone_graft', 'anesthesia', 'laser', 'impression', 'retainer', 'wisdom',
        'other',
    ];

    /** True for an icon a type may be saved with: a known one, not «تر» (it must say what it is). */
    public static function iconChosen($key): bool {
        $key = self::cleanIcon($key);
        return $key !== null && $key !== 'other';
    }

    /** A known icon key, or null (no icon: the picker guesses one from the name). */
    public static function cleanIcon($key): ?string {
        $key = trim((string)$key);
        return in_array($key, self::ICONS, true) ? $key : null;
    }

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
     * Get treatments by scope (tooth, arch, or both). Each row also carries
     * requires_tooth_number so every picker can consistently enable/disable
     * its tooth controls.
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

    /** The treatment types a user starred (★ in the treatment picker), as ids. */
    public function favoriteIds($userId) {
        $rows = $this->db->fetchAll(
            "SELECT treatment_id FROM user_treatment_favorites WHERE user_id = :u",
            ['u' => (int)$userId]
        );
        return array_map(fn($r) => (int)$r['treatment_id'], $rows);
    }

    /** Star or unstar a treatment type for one user. */
    public function setFavorite($userId, $treatmentId, $on) {
        $params = ['u' => (int)$userId, 't' => (int)$treatmentId];
        if ($on) {
            $this->db->query(
                "INSERT IGNORE INTO user_treatment_favorites (user_id, treatment_id) VALUES (:u, :t)", $params);
        } else {
            $this->db->query(
                "DELETE FROM user_treatment_favorites WHERE user_id = :u AND treatment_id = :t", $params);
        }
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
     * Every (table, column) that points at treatments.id: any column named
     * treatment_id plus any declared foreign key to treatments. Read from the
     * live schema so tables added by later migrations are covered too.
     */
    private function getReferencingColumns() {
        $sql = "SELECT c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col
                FROM information_schema.COLUMNS c
                JOIN information_schema.TABLES t
                  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
                WHERE c.TABLE_SCHEMA = DATABASE()
                  AND t.TABLE_TYPE = 'BASE TABLE'
                  AND c.TABLE_NAME <> 'treatments'
                  AND c.COLUMN_NAME = 'treatment_id'
                UNION
                SELECT k.TABLE_NAME AS tbl, k.COLUMN_NAME AS col
                FROM information_schema.KEY_COLUMN_USAGE k
                WHERE k.TABLE_SCHEMA = DATABASE()
                  AND k.REFERENCED_TABLE_NAME = 'treatments'
                  AND k.REFERENCED_COLUMN_NAME = 'id'
                  AND k.TABLE_NAME <> 'treatments'";
        return $this->db->fetchAll($sql);
    }

    /**
     * How many records use each treatment, as [treatment_id => count].
     * Treatments with no usage are absent from the result.
     */
    public function getUsageCounts() {
        $counts = [];
        foreach ($this->getReferencingColumns() as $ref) {
            $tbl = str_replace('`', '', $ref['tbl']);
            $col = str_replace('`', '', $ref['col']);
            $rows = $this->db->fetchAll(
                "SELECT `{$col}` AS tid, COUNT(*) AS cnt FROM `{$tbl}`
                 WHERE `{$col}` IS NOT NULL GROUP BY `{$col}`"
            );
            foreach ($rows as $r) {
                $counts[(int)$r['tid']] = ($counts[(int)$r['tid']] ?? 0) + (int)$r['cnt'];
            }
        }
        return $counts;
    }

    /**
     * How many records use one treatment.
     */
    public function getUsageCount($id) {
        $total = 0;
        foreach ($this->getReferencingColumns() as $ref) {
            $tbl = str_replace('`', '', $ref['tbl']);
            $col = str_replace('`', '', $ref['col']);
            $total += (int)$this->db->fetch(
                "SELECT COUNT(*) AS cnt FROM `{$tbl}` WHERE `{$col}` = :id",
                ['id' => $id]
            )['cnt'];
        }
        return $total;
    }

    /**
     * Permanently delete a treatment — only allowed when nothing uses it.
     * A used treatment must be deactivated instead, so history keeps its link.
     */
    public function hardDelete($id) {
        if ($this->getUsageCount($id) > 0) {
            return ['success' => false, 'in_use' => true];
        }
        try {
            $this->db->delete('treatments', 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
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
