<?php
/**
 * Dental Chart Class - Interactive Tooth Chart Management
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class DentalChart {
    private $db;

    // Adult teeth (FDI notation for database compatibility)
    private $adultTeeth = [
        // Upper Right (Quadrant 1)
        'upper_right' => [18, 17, 16, 15, 14, 13, 12, 11],
        // Upper Left (Quadrant 2)
        'upper_left' => [21, 22, 23, 24, 25, 26, 27, 28],
        // Lower Left (Quadrant 3)
        'lower_left' => [31, 32, 33, 34, 35, 36, 37, 38],
        // Lower Right (Quadrant 4)
        'lower_right' => [48, 47, 46, 45, 44, 43, 42, 41]
    ];

    // Child teeth (FDI notation)
    private $childTeeth = [
        // Upper Right (Quadrant 5)
        'upper_right' => [55, 54, 53, 52, 51],
        // Upper Left (Quadrant 6)
        'upper_left' => [61, 62, 63, 64, 65],
        // Lower Left (Quadrant 7)
        'lower_left' => [71, 72, 73, 74, 75],
        // Lower Right (Quadrant 8)
        'lower_right' => [85, 84, 83, 82, 81]
    ];

    // FDI to Palmer mapping
    private $fdiToPalmer = [
        // Upper Right (18-11 → UR-8 to UR-1)
        18 => 'UR-8', 17 => 'UR-7', 16 => 'UR-6', 15 => 'UR-5',
        14 => 'UR-4', 13 => 'UR-3', 12 => 'UR-2', 11 => 'UR-1',
        // Upper Left (21-28 → UL-1 to UL-8)
        21 => 'UL-1', 22 => 'UL-2', 23 => 'UL-3', 24 => 'UL-4',
        25 => 'UL-5', 26 => 'UL-6', 27 => 'UL-7', 28 => 'UL-8',
        // Lower Left (31-38 → LL-1 to LL-8)
        31 => 'LL-1', 32 => 'LL-2', 33 => 'LL-3', 34 => 'LL-4',
        35 => 'LL-5', 36 => 'LL-6', 37 => 'LL-7', 38 => 'LL-8',
        // Lower Right (48-41 → LR-8 to LR-1)
        48 => 'LR-8', 47 => 'LR-7', 46 => 'LR-6', 45 => 'LR-5',
        44 => 'LR-4', 43 => 'LR-3', 42 => 'LR-2', 41 => 'LR-1',
        // Child teeth
        55 => 'UR-E', 54 => 'UR-D', 53 => 'UR-C', 52 => 'UR-B', 51 => 'UR-A',
        61 => 'UL-A', 62 => 'UL-B', 63 => 'UL-C', 64 => 'UL-D', 65 => 'UL-E',
        71 => 'LL-A', 72 => 'LL-B', 73 => 'LL-C', 74 => 'LL-D', 75 => 'LL-E',
        85 => 'LR-E', 84 => 'LR-D', 83 => 'LR-C', 82 => 'LR-B', 81 => 'LR-A'
    ];

    // Palmer to FDI mapping (reverse)
    private $palmerToFdiMap = null; // Will be built in constructor

    // Tooth surfaces
    private $surfaces = ['mesial', 'distal', 'occlusal', 'lingual', 'buccal'];

    // Tooth conditions with colors
    private $conditions = [
        'healthy' => ['color' => '#4CAF50', 'name' => 'ساغ'],
        'needs_treatment' => ['color' => '#F44336', 'name' => 'پێویستی بە چارەسەر'],
        'treated' => ['color' => '#2196F3', 'name' => 'چارەسەرکراو'],
        'missing' => ['color' => '#9E9E9E', 'name' => 'نییە'],
        'crown' => ['color' => '#FFD700', 'name' => 'تاج'],
        'filling' => ['color' => '#00BCD4', 'name' => 'پڕکراوە'],
        'root_canal' => ['color' => '#9C27B0', 'name' => 'دەمار چارەسەرکراو'],
        'implant' => ['color' => '#FF9800', 'name' => 'چێندراو']
    ];

    public function __construct() {
        $this->db = Database::getInstance();
        // Build Palmer to FDI reverse mapping
        $this->palmerToFdiMap = array_flip($this->fdiToPalmer);
    }

    /**
     * Convert FDI notation to Palmer notation
     * @param int $fdiNumber FDI tooth number (e.g., 18, 21, 48)
     * @return string|null Palmer notation (e.g., "UR-8", "UL-1", "LR-8")
     */
    public function fdiToPalmerNotation($fdiNumber) {
        return $this->fdiToPalmer[$fdiNumber] ?? null;
    }

    /**
     * Convert Palmer notation to FDI notation
     * @param string $palmerNotation Palmer tooth notation (e.g., "UR-8", "UL-1")
     * @return int|null FDI tooth number
     */
    public function palmerToFdi($palmerNotation) {
        return $this->palmerToFdiMap[$palmerNotation] ?? null;
    }

    /**
     * Get Palmer number only (without quadrant)
     * @param int $fdiNumber FDI tooth number
     * @return int|string Palmer number only (1-8 or A-E)
     */
    public function getPalmerNumber($fdiNumber) {
        $palmer = $this->fdiToPalmerNotation($fdiNumber);
        if ($palmer) {
            return explode('-', $palmer)[1];
        }
        return $fdiNumber % 10; // Fallback
    }

    /**
     * Get quadrant from FDI number
     * @param int $fdiNumber FDI tooth number
     * @return string Quadrant (UR, UL, LL, LR)
     */
    public function getQuadrant($fdiNumber) {
        $palmer = $this->fdiToPalmerNotation($fdiNumber);
        if ($palmer) {
            return explode('-', $palmer)[0];
        }
        // Fallback logic
        $first = floor($fdiNumber / 10);
        $quadrants = [1 => 'UR', 2 => 'UL', 3 => 'LL', 4 => 'LR', 5 => 'UR', 6 => 'UL', 7 => 'LL', 8 => 'LR'];
        return $quadrants[$first] ?? 'UR';
    }

    /**
     * Build FDI number from quadrant and Palmer number
     * @param string $quadrant Quadrant (UR, UL, LL, LR)
     * @param int|string $palmerNum Palmer number (1-8 or A-E)
     * @return int|null FDI number
     */
    public function buildFdiFromPalmer($quadrant, $palmerNum) {
        $palmerNotation = strtoupper($quadrant) . '-' . $palmerNum;
        return $this->palmerToFdi($palmerNotation);
    }

    /**
     * Get Palmer display info for chart
     * Returns array with quadrant info and Palmer numbers for display
     * @return array
     */
    public function getPalmerDisplayData() {
        return [
            'upper' => [
                'right' => ['quadrant' => 'UR', 'numbers' => [8, 7, 6, 5, 4, 3, 2, 1], 'fdi' => $this->adultTeeth['upper_right']],
                'left' => ['quadrant' => 'UL', 'numbers' => [1, 2, 3, 4, 5, 6, 7, 8], 'fdi' => $this->adultTeeth['upper_left']]
            ],
            'lower' => [
                'right' => ['quadrant' => 'LR', 'numbers' => [8, 7, 6, 5, 4, 3, 2, 1], 'fdi' => $this->adultTeeth['lower_right']],
                'left' => ['quadrant' => 'LL', 'numbers' => [1, 2, 3, 4, 5, 6, 7, 8], 'fdi' => $this->adultTeeth['lower_left']]
            ]
        ];
    }

    /**
     * Get all teeth for chart
     */
    public function getAllTeeth($isChild = false) {
        return $isChild ? $this->childTeeth : $this->adultTeeth;
    }

    /**
     * Get tooth surfaces
     */
    public function getSurfaces() {
        global $TOOTH_SURFACES;
        return $TOOTH_SURFACES;
    }

    /**
     * Get tooth conditions
     */
    public function getConditions() {
        return $this->conditions;
    }

    /**
     * Add dental chart entry
     */
    public function addEntry($data) {
        try {
            $entryId = $this->db->insert('dental_chart', $data);
            return ['success' => true, 'entry_id' => $entryId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update dental chart entry
     */
    public function updateEntry($id, $data) {
        try {
            $this->db->update('dental_chart', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get chart entries by case
     */
    public function getByCase($caseId) {
        $sql = "SELECT dc.*, t.treatment_name as default_treatment_name, t.category,
                u.full_name as doctor_name
                FROM dental_chart dc
                LEFT JOIN treatments t ON dc.treatment_id = t.id
                LEFT JOIN users u ON dc.treated_by = u.id
                WHERE dc.case_id = :case_id
                ORDER BY dc.tooth_number, dc.created_at";
        return $this->db->fetchAll($sql, ['case_id' => $caseId]);
    }

    /**
     * Get chart entries by patient (all cases)
     */
    public function getByPatient($patientId) {
        $sql = "SELECT dc.*, c.case_number, t.treatment_name as default_treatment_name,
                u.full_name as doctor_name
                FROM dental_chart dc
                INNER JOIN cases c ON dc.case_id = c.id
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN treatments t ON dc.treatment_id = t.id
                LEFT JOIN users u ON dc.treated_by = u.id
                WHERE pf.patient_id = :patient_id
                ORDER BY dc.tooth_number, dc.treatment_date DESC";
        return $this->db->fetchAll($sql, ['patient_id' => $patientId]);
    }

    /**
     * Get tooth history
     */
    public function getToothHistory($patientId, $toothNumber) {
        $sql = "SELECT dc.*, c.case_number, t.treatment_name, u.full_name as doctor_name
                FROM dental_chart dc
                INNER JOIN cases c ON dc.case_id = c.id
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN treatments t ON dc.treatment_id = t.id
                LEFT JOIN users u ON dc.treated_by = u.id
                WHERE pf.patient_id = :patient_id AND dc.tooth_number = :tooth_number
                ORDER BY dc.treatment_date DESC";
        return $this->db->fetchAll($sql, ['patient_id' => $patientId, 'tooth_number' => $toothNumber]);
    }

    /**
     * Get current tooth status for a patient
     */
    public function getCurrentToothStatus($patientId) {
        // Get the latest status for each tooth
        $sql = "SELECT dc.tooth_number, dc.status, dc.treatment_name, dc.notes,
                MAX(dc.treatment_date) as last_treatment
                FROM dental_chart dc
                INNER JOIN cases c ON dc.case_id = c.id
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                WHERE pf.patient_id = :patient_id
                GROUP BY dc.tooth_number
                ORDER BY dc.tooth_number";
        $entries = $this->db->fetchAll($sql, ['patient_id' => $patientId]);

        $status = [];
        foreach ($entries as $entry) {
            $status[$entry['tooth_number']] = $entry;
        }
        return $status;
    }

    /**
     * Record treatment on tooth
     */
    public function recordTreatment($caseId, $toothNumber, $treatmentId, $data, $doctorId) {
        $entryData = array_merge($data, [
            'case_id' => $caseId,
            'tooth_number' => $toothNumber,
            'treatment_id' => $treatmentId,
            'treated_by' => $doctorId,
            'treatment_date' => date('Y-m-d'),
            'status' => 'completed'
        ]);

        return $this->addEntry($entryData);
    }

    /**
     * Plan treatment on tooth
     */
    public function planTreatment($caseId, $toothNumber, $treatmentId, $data) {
        $entryData = array_merge($data, [
            'case_id' => $caseId,
            'tooth_number' => $toothNumber,
            'treatment_id' => $treatmentId,
            'status' => 'planned'
        ]);

        return $this->addEntry($entryData);
    }

    /**
     * Get planned treatments for a case
     */
    public function getPlannedTreatments($caseId) {
        $sql = "SELECT dc.*, t.treatment_name, t.default_price
                FROM dental_chart dc
                LEFT JOIN treatments t ON dc.treatment_id = t.id
                WHERE dc.case_id = :case_id AND dc.status = 'planned'
                ORDER BY dc.tooth_number";
        return $this->db->fetchAll($sql, ['case_id' => $caseId]);
    }

    /**
     * Mark treatment as complete
     */
    public function completeTreatment($id, $doctorId) {
        return $this->updateEntry($id, [
            'status' => 'completed',
            'treated_by' => $doctorId,
            'treatment_date' => date('Y-m-d')
        ]);
    }

    /**
     * Delete chart entry
     */
    public function deleteEntry($id) {
        try {
            $this->db->delete('dental_chart', 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get tooth name by number
     */
    public function getToothName($number) {
        $names = [
            // Adult teeth names in Kurdish
            11 => 'پێشەوەی ناوەڕاستی سەرەوەی چەپ',
            12 => 'پێشەوەی لاوەکی سەرەوەی چەپ',
            13 => 'نوکتێژی سەرەوەی چەپ',
            14 => 'پێش داڵی یەکەمی سەرەوەی چەپ',
            15 => 'پێش داڵی دووەمی سەرەوەی چەپ',
            16 => 'داڵی یەکەمی سەرەوەی چەپ',
            17 => 'داڵی دووەمی سەرەوەی چەپ',
            18 => 'عەقڵی سەرەوەی چەپ',
            21 => 'پێشەوەی ناوەڕاستی سەرەوەی ڕاست',
            22 => 'پێشەوەی لاوەکی سەرەوەی ڕاست',
            23 => 'نوکتێژی سەرەوەی ڕاست',
            24 => 'پێش داڵی یەکەمی سەرەوەی ڕاست',
            25 => 'پێش داڵی دووەمی سەرەوەی ڕاست',
            26 => 'داڵی یەکەمی سەرەوەی ڕاست',
            27 => 'داڵی دووەمی سەرەوەی ڕاست',
            28 => 'عەقڵی سەرەوەی ڕاست',
            31 => 'پێشەوەی ناوەڕاستی خوارەوەی ڕاست',
            32 => 'پێشەوەی لاوەکی خوارەوەی ڕاست',
            33 => 'نوکتێژی خوارەوەی ڕاست',
            34 => 'پێش داڵی یەکەمی خوارەوەی ڕاست',
            35 => 'پێش داڵی دووەمی خوارەوەی ڕاست',
            36 => 'داڵی یەکەمی خوارەوەی ڕاست',
            37 => 'داڵی دووەمی خوارەوەی ڕاست',
            38 => 'عەقڵی خوارەوەی ڕاست',
            41 => 'پێشەوەی ناوەڕاستی خوارەوەی چەپ',
            42 => 'پێشەوەی لاوەکی خوارەوەی چەپ',
            43 => 'نوکتێژی خوارەوەی چەپ',
            44 => 'پێش داڵی یەکەمی خوارەوەی چەپ',
            45 => 'پێش داڵی دووەمی خوارەوەی چەپ',
            46 => 'داڵی یەکەمی خوارەوەی چەپ',
            47 => 'داڵی دووەمی خوارەوەی چەپ',
            48 => 'عەقڵی خوارەوەی چەپ'
        ];

        return $names[$number] ?? "ددان {$number}";
    }

    /**
     * Get statistics
     */
    public function getStatistics($doctorId = null, $dateFrom = null, $dateTo = null) {
        $stats = [];
        $params = [];
        $where = "dc.status = 'completed'";

        if ($doctorId) {
            $where .= " AND dc.treated_by = :doctor_id";
            $params['doctor_id'] = $doctorId;
        }

        if ($dateFrom) {
            $where .= " AND dc.treatment_date >= :date_from";
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= " AND dc.treatment_date <= :date_to";
            $params['date_to'] = $dateTo;
        }

        // Treatment count by type
        $sql = "SELECT t.treatment_name, t.category, COUNT(*) as count
                FROM dental_chart dc
                LEFT JOIN treatments t ON dc.treatment_id = t.id
                WHERE {$where}
                GROUP BY dc.treatment_id
                ORDER BY count DESC";
        $stats['by_treatment'] = $this->db->fetchAll($sql, $params);

        // Most treated teeth
        $sql = "SELECT tooth_number, COUNT(*) as count
                FROM dental_chart dc
                WHERE {$where}
                GROUP BY tooth_number
                ORDER BY count DESC
                LIMIT 10";
        $stats['by_tooth'] = $this->db->fetchAll($sql, $params);

        return $stats;
    }
}
