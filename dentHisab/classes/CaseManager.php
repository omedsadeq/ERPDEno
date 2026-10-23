<?php
/**
 * Case Manager Class (Level 3 of hierarchy)
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Folder.php';
require_once __DIR__ . '/S3Uploader.php';

class CaseManager {
    private $db;
    private $folder;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->folder = new Folder();
    }

    /**
     * Generate unique case number
     */
    private function generateCaseNumber() {
        $year = date('Y');
        $month = date('m');
        $sql = "SELECT MAX(CAST(SUBSTRING(case_number, 9) AS UNSIGNED)) as max_num
                FROM cases WHERE case_number LIKE :prefix";
        $result = $this->db->fetch($sql, ['prefix' => "C{$year}{$month}%"]);
        $nextNum = ($result['max_num'] ?? 0) + 1;
        return "C{$year}{$month}" . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create new case
     */
    public function create($data) {
        $data['case_number'] = $this->generateCaseNumber();
        // Always record which user created the case
        if (empty($data['created_by']) && !empty($_SESSION['user_id'])) {
            $data['created_by'] = (int)$_SESSION['user_id'];
        }

        try {
            $caseId = $this->db->insert('cases', $data);
            return ['success' => true, 'case_id' => $caseId, 'case_number' => $data['case_number']];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update case
     */
    public function update($id, $data) {
        try {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('cases', $data, 'id = :id', ['id' => $id]);

            // Update folder totals
            $case = $this->getById($id);
            if ($case) {
                $this->folder->updateTotals($case['folder_id']);
            }

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get case by ID
     */
    public function getById($id) {
        $sql = "SELECT c.*, pf.patient_id, pf.folder_name, pf.doctor_id,
                p.full_name as patient_name, p.patient_code, p.phone as patient_phone,
                p.gender, p.date_of_birth,
                u.full_name as doctor_name
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE c.id = :id";
        return $this->db->fetch($sql, ['id' => $id]);
    }

    /**
     * Get case by number
     */
    public function getByNumber($caseNumber) {
        $sql = "SELECT * FROM cases WHERE case_number = :case_number";
        return $this->db->fetch($sql, ['case_number' => $caseNumber]);
    }

    /**
     * Get cases by folder
     */
    public function getByFolder($folderId, $status = null) {
        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM case_treatments ct WHERE ct.case_id = c.id) as treatment_count
                FROM cases c WHERE c.folder_id = :folder_id";
        $params = ['folder_id' => $folderId];

        if ($status) {
            $sql .= " AND c.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY c.created_at DESC";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get cases by doctor
     */
    public function getByDoctor($doctorId, $status = null, $page = 1, $limit = ITEMS_PER_PAGE) {
        $offset = ($page - 1) * $limit;
        $sql = "SELECT c.*, pf.folder_name, p.full_name as patient_name, p.patient_code
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                WHERE pf.doctor_id = :doctor_id";
        $params = ['doctor_id' => $doctorId];

        if ($status) {
            $sql .= " AND c.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY c.updated_at DESC LIMIT {$limit} OFFSET {$offset}";
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Close case
     * @param int $id Case ID
     * @param string $closingComment Optional closing comment
     */
    public function close($id, $closingComment = null) {
        // Check if there's remaining balance
        $case = $this->getById($id);
        $remaining = $case['total_amount'] - $case['paid_amount'] - $case['discount_amount'];

        if ($remaining > 0) {
            // Convert to credit
            return $this->closeWithCredit($id, $remaining, $closingComment);
        }

        $data = [
            'status' => CASE_CLOSED,
            'closed_at' => date('Y-m-d H:i:s'),
            'closed_by' => $_SESSION['user_id'] ?? null,
            'closing_comment' => $closingComment
        ];

        try {
            $this->db->update('cases', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Close case with remaining as credit
     * @param int $id Case ID
     * @param float $creditAmount Credit amount
     * @param string $closingComment Optional closing comment
     */
    private function closeWithCredit($id, $creditAmount, $closingComment = null) {
        $data = [
            'status' => CASE_CLOSED,
            'closed_at' => date('Y-m-d H:i:s'),
            'closed_by' => $_SESSION['user_id'] ?? null,
            'closing_comment' => $closingComment
        ];

        try {
            $this->db->beginTransaction();

            $this->db->update('cases', $data, 'id = :id', ['id' => $id]);

            // Record as credit payment (pending)
            $paymentData = [
                'case_id' => $id,
                'payment_number' => 'CR' . date('YmdHis') . rand(100, 999),
                'payment_type' => PAYMENT_CREDIT,
                'amount' => $creditAmount,
                'status' => 'pending',
                'received_by' => $_SESSION['user_id'] ?? null,
                'notes' => 'قەرزی ماوە لە داخستنی کەیس'
            ];
            $this->db->insert('payments', $paymentData);

            $this->db->commit();
            return ['success' => true, 'credit_amount' => $creditAmount];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Reopen case
     */
    public function reopen($id) {
        $data = [
            'status' => CASE_OPEN,
            'closed_at' => null
        ];

        try {
            $this->db->update('cases', $data, 'id = :id', ['id' => $id]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get teeth numbers for an arch
     * @param string $arch 'upper', 'lower', or 'both'
     * @return array Array of FDI tooth numbers
     */
    public function getArchTeeth($arch) {
        // Upper arch teeth (FDI notation)
        $upperTeeth = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
        // Lower arch teeth (FDI notation)
        $lowerTeeth = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

        switch ($arch) {
            case 'upper':
                return $upperTeeth;
            case 'lower':
                return $lowerTeeth;
            case 'both':
                return array_merge($upperTeeth, $lowerTeeth);
            default:
                return [];
        }
    }

    /**
     * Add treatment to case
     */
    public function addTreatment($caseId, $data) {
        // Server-side validation — the UI enforces these too, but a stale or
        // hand-crafted POST must not create nameless/negative treatments.
        if (trim((string)($data['treatment_name'] ?? '')) === '') {
            return ['success' => false, 'message' => 'ناوی چارەسەر پێویستە'];
        }
        $data['quantity'] = max(1, intval($data['quantity'] ?? 1));
        $data['unit_price'] = max(0, floatval($data['unit_price'] ?? 0));
        $data['discount'] = max(0, floatval($data['discount'] ?? 0));

        $data['case_id'] = $caseId;
        $data['total_price'] = $data['unit_price'] * $data['quantity'];
        if ($data['discount'] > $data['total_price']) {
            return ['success' => false, 'message' => 'داشکاندن ناتوانێت لە کۆی نرخ زیاتر بێت'];
        }
        $data['final_price'] = $data['total_price'] - $data['discount'];
        $data['performed_date'] = date('Y-m-d H:i:s');

        try {
            $this->db->beginTransaction();
            $treatmentId = $this->db->insert('case_treatments', $data);

            // Update case totals
            $this->updateCaseTotals($caseId);

            $this->db->commit();
            return ['success' => true, 'treatment_id' => $treatmentId];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Add arch treatment - creates treatments for all teeth in the selected arch
     * @param int $caseId Case ID
     * @param array $data Treatment data (can include upper_unit_price and lower_unit_price for separate pricing)
     * @param string $arch 'upper', 'lower', or 'both'
     * @return array Result with success status
     */
    public function addArchTreatment($caseId, $data, $arch) {
        $teeth = $this->getArchTeeth($arch);

        if (empty($teeth)) {
            return ['success' => false, 'message' => 'Invalid arch selection'];
        }

        // Get upper and lower teeth arrays for price determination
        $upperTeeth = $this->getArchTeeth('upper');
        $lowerTeeth = $this->getArchTeeth('lower');

        // Determine if using separate pricing
        $upperUnitPrice = isset($data['upper_unit_price']) ? floatval($data['upper_unit_price']) : null;
        $lowerUnitPrice = isset($data['lower_unit_price']) ? floatval($data['lower_unit_price']) : null;
        $defaultUnitPrice = isset($data['unit_price']) ? floatval($data['unit_price']) : 0;

        $successCount = 0;
        $treatmentIds = [];

        try {
            $this->db->beginTransaction();

            foreach ($teeth as $toothNumber) {
                // Determine the correct unit price for this tooth
                $toothUnitPrice = $defaultUnitPrice;
                if ($upperUnitPrice !== null && in_array($toothNumber, $upperTeeth)) {
                    $toothUnitPrice = $upperUnitPrice;
                } elseif ($lowerUnitPrice !== null && in_array($toothNumber, $lowerTeeth)) {
                    $toothUnitPrice = $lowerUnitPrice;
                }

                $treatmentData = [
                    'case_id' => $caseId,
                    'tooth_number' => $toothNumber,
                    'treatment_id' => $data['treatment_id'] ?? null,
                    'treatment_name' => $data['treatment_name'] ?? '',
                    'quantity' => $data['quantity'] ?? 1,
                    'unit_price' => $toothUnitPrice,
                    'total_price' => $toothUnitPrice * ($data['quantity'] ?? 1),
                    'discount' => $data['discount'] ?? 0,
                    'notes' => $data['notes'] ?? '',
                    'performed_by' => $data['performed_by'] ?? null,
                    'performed_date' => date('Y-m-d H:i:s')
                ];
                $treatmentData['final_price'] = $treatmentData['total_price'] - $treatmentData['discount'];

                $treatmentId = $this->db->insert('case_treatments', $treatmentData);
                $treatmentIds[] = $treatmentId;
                $successCount++;
            }

            // Update case totals
            $this->updateCaseTotals($caseId);

            $this->db->commit();

            return [
                'success' => true,
                'treatment_ids' => $treatmentIds,
                'count' => $successCount,
                'teeth_count' => count($teeth)
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get case treatments
     */
    public function getTreatments($caseId) {
        $sql = "SELECT ct.*, t.treatment_name as default_name, t.category,
                u.full_name as performed_by_name
                FROM case_treatments ct
                LEFT JOIN treatments t ON ct.treatment_id = t.id
                LEFT JOIN users u ON ct.performed_by = u.id
                WHERE ct.case_id = :case_id
                ORDER BY ct.created_at DESC";
        return $this->db->fetchAll($sql, ['case_id' => $caseId]);
    }

    /**
     * Update treatment
     */
    public function updateTreatment($caseId, $treatmentId, $data) {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->update('case_treatments', $data, 'id = :id AND case_id = :case_id', [
                'id' => $treatmentId,
                'case_id' => $caseId
            ]);
            if ($stmt->rowCount() === 0) {
                // Distinguish "no such treatment on this case" from a no-op edit
                $exists = $this->db->fetch(
                    "SELECT id FROM case_treatments WHERE id = :id AND case_id = :case_id",
                    ['id' => $treatmentId, 'case_id' => $caseId]
                );
                if (!$exists) {
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'چارەسەرەکە نەدۆزرایەوە'];
                }
            }
            $this->updateCaseTotals($caseId);
            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete treatment
     */
    public function deleteTreatment($caseId, $treatmentId) {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->delete('case_treatments', 'id = :id AND case_id = :case_id', [
                'id' => $treatmentId,
                'case_id' => $caseId
            ]);
            if ($stmt->rowCount() === 0) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'چارەسەرەکە نەدۆزرایەوە'];
            }
            $this->updateCaseTotals($caseId);
            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update case totals
     * Includes both case_treatments AND arch_procedure_logs for arch-based cases
     */
    public function updateCaseTotals($caseId) {
        // Get case to check scope type
        $case = $this->getById($caseId);
        if (!$case) {
            return;
        }

        // Calculate total from treatments
        $sql = "SELECT SUM(final_price) as total FROM case_treatments WHERE case_id = :case_id";
        $result = $this->db->fetch($sql, ['case_id' => $caseId]);
        $treatmentTotal = $result['total'] ?? 0;

        // Also include arch procedure log prices (for arch-based cases)
        $sql = "SELECT SUM(price) as total FROM arch_procedure_logs WHERE case_id = :case_id";
        $result = $this->db->fetch($sql, ['case_id' => $caseId]);
        $archLogTotal = $result['total'] ?? 0;

        // Combined total
        $total = $treatmentTotal + $archLogTotal;

        $sql = "SELECT SUM(amount) as paid FROM payments WHERE case_id = :case_id AND status = 'approved'";
        $result = $this->db->fetch($sql, ['case_id' => $caseId]);
        $paid = $result['paid'] ?? 0;

        $discountAmount = 0;
        if ($case['discount_type'] === 'percentage') {
            $discountAmount = $total * ($case['discount_value'] / 100);
        } else {
            $discountAmount = $case['discount_value'];
        }

        // Never let the discount exceed the total. Otherwise deleting treatments
        // after a fixed discount was set could push remaining negative and make
        // close() treat an unpaid case as fully paid.
        if ($discountAmount > $total) {
            $discountAmount = $total;
        }
        if ($discountAmount < 0) {
            $discountAmount = 0;
        }

        $data = [
            'total_amount' => $total,
            'paid_amount' => $paid,
            'discount_amount' => $discountAmount
        ];

        $this->db->update('cases', $data, 'id = :id', ['id' => $caseId]);

        // Update folder totals
        $this->folder->updateTotals($case['folder_id']);
    }

    /**
     * Apply discount
     */
    public function applyDiscount($caseId, $type, $value) {
        $data = [
            'discount_type' => $type,
            'discount_value' => $value
        ];

        try {
            $this->db->update('cases', $data, 'id = :id', ['id' => $caseId]);
            $this->updateCaseTotals($caseId);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all cases
     */
    public function getAll($page = 1, $limit = ITEMS_PER_PAGE, $filters = []) {
        $offset = ($page - 1) * $limit;
        $params = [];

        $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
                p.full_name as patient_name, p.patient_code,
                u.full_name as doctor_name
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                WHERE 1=1";

        if (!empty($filters['status'])) {
            $sql .= " AND c.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['doctor_id'])) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $filters['doctor_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
            $params['search'] = "%{$filters['search']}%";
            $params['search2'] = "%{$filters['search']}%";
            $params['search3'] = "%{$filters['search']}%";
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(c.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(c.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $sql .= " ORDER BY c.updated_at DESC LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count cases
     */
    public function count($filters = []) {
        $params = [];
        $sql = "SELECT COUNT(*) as count FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                WHERE 1=1";

        if (!empty($filters['status'])) {
            $sql .= " AND c.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['doctor_id'])) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $filters['doctor_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
            $params['search'] = "%{$filters['search']}%";
            $params['search2'] = "%{$filters['search']}%";
            $params['search3'] = "%{$filters['search']}%";
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(c.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(c.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        return $this->db->fetch($sql, $params)['count'];
    }

    /**
     * Delete case
     */
    public function delete($id) {
        $case = $this->getById($id);

        try {
            $this->db->delete('cases', 'id = :id', ['id' => $id]);

            // Update folder totals
            if ($case) {
                $this->folder->updateTotals($case['folder_id']);
            }

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get cases pending payment approval
     */
    public function getPendingPayments() {
        $sql = "SELECT c.*, p.full_name as patient_name, u.full_name as doctor_name,
                ct.treatment_name, ct.final_price
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                INNER JOIN case_treatments ct ON c.id = ct.case_id
                WHERE ct.status = 'pending'
                ORDER BY ct.created_at DESC";
        return $this->db->fetchAll($sql);
    }

    /**
     * Get totals for cases
     */
    public function getTotals($filters = []) {
        $params = [];
        $sql = "SELECT
                SUM(c.total_amount) as total_amount,
                SUM(c.paid_amount) as total_paid,
                SUM(c.discount_amount) as total_discount,
                SUM(c.total_amount - c.paid_amount - c.discount_amount) as total_remaining
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                WHERE 1=1";

        if (!empty($filters['status'])) {
            $sql .= " AND c.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['doctor_id'])) {
            $sql .= " AND pf.doctor_id = :doctor_id";
            $params['doctor_id'] = $filters['doctor_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.full_name LIKE :search OR c.case_number LIKE :search2 OR p.phone LIKE :search3)";
            $params['search'] = "%{$filters['search']}%";
            $params['search2'] = "%{$filters['search']}%";
            $params['search3'] = "%{$filters['search']}%";
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(c.created_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(c.created_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $result = $this->db->fetch($sql, $params);

        return [
            'total_amount' => $result['total_amount'] ?? 0,
            'total_paid' => $result['total_paid'] ?? 0,
            'total_discount' => $result['total_discount'] ?? 0,
            'total_remaining' => $result['total_remaining'] ?? 0
        ];
    }

    // ==========================================
    // Case Images Methods (S3 Support)
    // ==========================================

    /**
     * Get all images for a case (slots 1-6)
     */
    public function getImages($caseId) {
        $sql = "SELECT ci.*, u.full_name as uploaded_by_name
                FROM case_images ci
                LEFT JOIN users u ON ci.uploaded_by = u.id
                WHERE ci.case_id = :case_id
                ORDER BY ci.slot_number ASC";
        $images = $this->db->fetchAll($sql, ['case_id' => $caseId]);

        // Index by slot number for easy access
        $indexed = [];
        foreach ($images as $img) {
            $indexed[$img['slot_number']] = $img;
        }
        return $indexed;
    }

    /**
     * Sanitize folder name for S3 path (remove special characters, keep Kurdish/Arabic safe)
     */
    private function sanitizeFolderName($name) {
        // Replace spaces with underscores
        $name = str_replace(' ', '_', trim($name));
        // Remove characters that are problematic in S3 paths
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
        // Limit length
        $name = mb_substr($name, 0, 50, 'UTF-8');
        return $name ?: 'Unknown';
    }

    /**
     * Upload image to a specific slot (1-6)
     * S3 Structure: Cases/[Patient_Name]/[Case_ID]/Images/[filename]
     */
    public function uploadImage($caseId, $slotNumber, $file, $userId, $description = '') {
        $maxFileSize = 10 * 1024 * 1024; // 10MB
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'webp'];

        // Validate slot number
        if ($slotNumber < 1 || $slotNumber > 6) {
            return ['success' => false, 'message' => 'ژمارەی شوێن نادروستە (1-6)'];
        }

        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'فایلێک هەڵنەبژێردراوە'];
        }

        if ($file['size'] > $maxFileSize) {
            return ['success' => false, 'message' => 'قەبارەی فایل زۆر گەورەیە (زۆرترین: 10MB)'];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            return ['success' => false, 'message' => 'جۆری فایل ڕێگەپێدراو نییە'];
        }

        // Get case and patient info for folder structure
        $caseInfo = $this->db->fetch(
            "SELECT c.id as case_id, p.full_name as patient_name
             FROM cases c
             INNER JOIN patient_folders pf ON c.folder_id = pf.id
             LEFT JOIN patients p ON pf.patient_id = p.id
             WHERE c.id = ?",
            [$caseId]
        );

        if (!$caseInfo) {
            return ['success' => false, 'message' => 'کەیس نەدۆزرایەوە'];
        }

        // Generate unique filename
        $filename = 'slot_' . $slotNumber . '_' . uniqid() . '.' . $extension;

        // Check if S3 is configured
        $useS3 = !empty($_ENV['S3_ACCESS_KEY'] ?? getenv('S3_ACCESS_KEY'));

        if ($useS3) {
            // Upload to S3 (iDrive e2)
            // Structure: Cases/[Patient_Name]/[Case_ID]/Images/[filename]
            $s3 = S3Uploader::getInstance();
            $patientFolder = $this->sanitizeFolderName($caseInfo['patient_name']);
            $s3Folder = 'Cases/' . $patientFolder . '/' . $caseId . '/Images';
            $uploadResult = $s3->uploadFromPost($file, $s3Folder, $filename);

            if (!$uploadResult['success']) {
                return ['success' => false, 'message' => $uploadResult['message'] ?? 'هەڵە لە بارکردنی فایل بۆ S3'];
            }

            $imagePath = $uploadResult['url'];
            $s3Key = $uploadResult['key'];
        } else {
            // Fallback to local storage
            $uploadDir = APP_ROOT . '/uploads/cases/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $filepath = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                return ['success' => false, 'message' => 'هەڵە لە بارکردنی فایل'];
            }
            $imagePath = 'uploads/cases/' . $filename;
            $s3Key = null;
        }

        // The new file is safely in storage — only now replace the old slot
        // image, so a failed upload never loses the existing image.
        $this->deleteImage($caseId, $slotNumber);

        // Insert into database
        $imageData = [
            'case_id' => $caseId,
            'slot_number' => $slotNumber,
            'image_path' => $imagePath,
            's3_key' => $s3Key,
            'image_type' => $extension,
            'original_filename' => $file['name'],
            'file_size' => $file['size'],
            'description' => $description,
            'uploaded_by' => $userId
        ];

        try {
            $imageId = $this->db->insert('case_images', $imageData);
            return [
                'success' => true,
                'image_id' => $imageId,
                'slot_number' => $slotNumber,
                'url' => $imagePath,
                's3_key' => $s3Key
            ];
        } catch (Exception $e) {
            // Delete uploaded file if database insert fails
            if ($useS3 && $s3Key) {
                $s3 = S3Uploader::getInstance();
                $s3->deleteFile($s3Key);
            } elseif (!$useS3 && isset($filepath) && file_exists($filepath)) {
                unlink($filepath);
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete image from a specific slot
     */
    public function deleteImage($caseId, $slotNumber) {
        $sql = "SELECT * FROM case_images WHERE case_id = :case_id AND slot_number = :slot_number";
        $image = $this->db->fetch($sql, ['case_id' => $caseId, 'slot_number' => $slotNumber]);

        if (!$image) {
            return ['success' => true]; // No image to delete
        }

        // Delete from storage
        $useS3 = !empty($_ENV['S3_ACCESS_KEY'] ?? getenv('S3_ACCESS_KEY'));

        if (!empty($image['s3_key']) && $useS3) {
            // Delete from S3
            $s3 = S3Uploader::getInstance();
            $s3->deleteFile($image['s3_key']);
        } elseif (strpos($image['image_path'], 'http') !== 0) {
            // Delete local file
            $filepath = APP_ROOT . '/' . $image['image_path'];
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }

        // Delete database record
        $this->db->delete('case_images', 'case_id = :case_id AND slot_number = :slot_number', [
            'case_id' => $caseId,
            'slot_number' => $slotNumber
        ]);

        return ['success' => true];
    }

    /**
     * Get image URL (presigned for S3)
     */
    public function getImageUrl($image) {
        if (empty($image)) {
            return null;
        }

        $useS3 = !empty($_ENV['S3_ACCESS_KEY'] ?? getenv('S3_ACCESS_KEY'));

        if (!empty($image['s3_key']) && $useS3) {
            // Use presigned URL for S3 images
            $s3 = S3Uploader::getInstance();
            return $s3->getPresignedUrl($image['s3_key'], 60);
        } elseif (strpos($image['image_path'], 'http') === 0) {
            return $image['image_path'];
        } else {
            // Local file: never link the public path directly (that folder is
            // blocked from direct HTTP access). Serve through the authenticated
            // gateway, which re-checks case ownership.
            return APP_URL . '/file.php?type=case&id=' . (int)($image['id'] ?? 0);
        }
    }
}
