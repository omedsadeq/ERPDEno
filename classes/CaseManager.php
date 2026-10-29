<?php
/**
 * Case Manager Class (Level 3 of hierarchy)
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Folder.php';
require_once __DIR__ . '/S3Uploader.php';
require_once __DIR__ . '/AuditLog.php';

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
    /**
     * «کەیس» in one click: the doctor's open case on this patient, or — when
     * there is none — a general one (CaseNamePreset::GENERAL) made without a
     * dialog. Name, complaint and the rest are filled in on the case itself if
     * ever needed. Used by the patient's profile and by the new-visit save.
     *
     * @return int|null the case id, or null when it could not be made
     */
    public function quickOpen($patientId, $doctorId, $actorId) {
        $patientId = (int)$patientId; $doctorId = (int)$doctorId; $actorId = (int)$actorId;
        if ($patientId <= 0 || $doctorId <= 0) {
            return null;
        }
        $open = $this->db->fetch(
            "SELECT c.id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id
             WHERE pf.patient_id = :pid AND pf.doctor_id = :did AND c.status = 'open'
             ORDER BY c.created_at DESC, c.id DESC LIMIT 1",
            ['pid' => $patientId, 'did' => $doctorId]
        );
        if ($open) {
            return (int)$open['id'];
        }
        require_once __DIR__ . '/Folder.php';
        require_once __DIR__ . '/CaseNamePreset.php';
        require_once __DIR__ . '/Patient.php';
        require_once __DIR__ . '/UnassignedCaseLog.php';
        require_once __DIR__ . '/AuditLog.php';
        $presets = new CaseNamePreset();
        if (!$presets->exists(CaseNamePreset::GENERAL)) {
            $presets->add(CaseNamePreset::GENERAL, 'tooth', $actorId ?: null);
        }
        // how the doctor reached this patient, captured before the folder makes them an owner
        $relation = (new Patient())->doctorPatientRelation($patientId, $doctorId);
        $folderId = (new Folder())->findOrCreateDefault($patientId, $doctorId);
        if (!$folderId) {
            return null;
        }
        $made = $this->create([
            'folder_id' => $folderId, 'case_name' => CaseNamePreset::GENERAL, 'scope_type' => 'tooth',
            'selected_arch' => null, 'chief_complaint' => '', 'notes' => '', 'is_historical' => 0, 'historical_date' => null,
        ]);
        if (empty($made['success'])) {
            return null;
        }
        UnassignedCaseLog::recordCaseCreation($doctorId, $patientId, $relation, (int)$made['case_id'], (int)$folderId);
        AuditLog::log('create_case', 'cases', (int)$made['case_id'], null, [
            'patient_id' => $patientId, 'doctor_id' => $doctorId, 'folder_id' => (int)$folderId,
            'source' => 'quick_open', 'relation' => $relation, 'is_historical' => 0, 'historical_date' => null,
        ], $actorId ?: null);
        return (int)$made['case_id'];
    }

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
        // Any remaining balance is left as derived debt — it is never
        // fabricated into a payment row. payments are created only by the
        // Payment class (single-writer rule).
        $case = $this->getById($id);
        if (!$case) {
            return ['success' => false, 'message' => 'کەیس نەدۆزرایەوە'];
        }

        $data = [
            'status' => CASE_CLOSED,
            'closed_at' => date('Y-m-d H:i:s'),
            'closed_by' => $_SESSION['user_id'] ?? null,
            'closing_comment' => $closingComment
        ];

        try {
            $this->db->update('cases', $data, 'id = :id', ['id' => $id]);
            AuditLog::logChange('case_closed', 'cases', $id, $case, $data, $_SESSION['user_id'] ?? null);
            return ['success' => true];
        } catch (Exception $e) {
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
    public static function splitBulkQuantity($totalQuantity, $itemCount) {
        $totalQuantity = max(1, (int)$totalQuantity);
        $itemCount = (int)$itemCount;
        if ($itemCount < 1 || $totalQuantity < $itemCount) {
            throw new InvalidArgumentException('Quantity must be at least the number of selected teeth.');
        }
        $base = intdiv($totalQuantity, $itemCount);
        $extra = $totalQuantity % $itemCount;
        $parts = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $parts[] = $base + ($i < $extra ? 1 : 0);
        }
        return $parts;
    }

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
        // Caller may pin the date (work entered under a back-dated visit tab);
        // otherwise the treatment is stamped now.
        if (empty($data['performed_date'])) {
            $data['performed_date'] = date('Y-m-d H:i:s');
        }

        try {
            $this->db->beginTransaction();
            $treatmentId = $this->db->insert('case_treatments', $data);
            AuditLog::log('case_treatment_created', 'case_treatments', $treatmentId, null, $data);

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
     * Add whole-arch work. An arch is a clinical location, not sixteen billable
     * teeth: one selected arch creates one row with tooth_number=NULL and the
     * agreed price for that job. Selecting both creates two rows (upper/lower),
     * which keeps Maxilla and Mandible independently editable and reportable.
     *
     * @param int $caseId Case ID
     * @param array $data Treatment data (may carry upper/lower job prices)
     * @param string $arch 'upper', 'lower', or 'both'
     * @return array Result with success status
     */
    public function addArchTreatment($caseId, $data, $arch) {
        $arches = $arch === 'both' ? ['upper', 'lower'] : [$arch];
        if (array_diff($arches, ['upper', 'lower'])) {
            return ['success' => false, 'message' => 'Invalid arch selection'];
        }
        $upperUnitPrice = isset($data['upper_unit_price']) ? floatval($data['upper_unit_price']) : null;
        $lowerUnitPrice = isset($data['lower_unit_price']) ? floatval($data['lower_unit_price']) : null;
        $defaultUnitPrice = max(0, floatval($data['unit_price'] ?? 0));

        $successCount = 0;
        $treatmentIds = [];

        try {
            $this->db->beginTransaction();

            foreach ($arches as $selectedArch) {
                $toothUnitPrice = $defaultUnitPrice;
                if ($selectedArch === 'upper' && $upperUnitPrice !== null) {
                    $toothUnitPrice = $upperUnitPrice;
                } elseif ($selectedArch === 'lower' && $lowerUnitPrice !== null) {
                    $toothUnitPrice = $lowerUnitPrice;
                }
                $toothUnitPrice = max(0, $toothUnitPrice);

                $treatmentData = [
                    'case_id' => $caseId,
                    'invoice_id' => $data['invoice_id'] ?? null,
                    'tooth_number' => null,
                    'tooth_surface' => null,
                    'arch_type' => $selectedArch,
                    'treatment_id' => $data['treatment_id'] ?? null,
                    'treatment_name' => $data['treatment_name'] ?? '',
                    'quantity' => 1,
                    'unit_price' => $toothUnitPrice,
                    'total_price' => $toothUnitPrice,
                    'discount' => 0,
                    'notes' => $data['notes'] ?? '',
                    'performed_by' => $data['performed_by'] ?? null,
                    'performed_date' => $data['performed_date'] ?? date('Y-m-d H:i:s')
                ];
                $treatmentData['final_price'] = $toothUnitPrice;

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
                'arches_count' => count($arches),
                'teeth_count' => 0
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
                COALESCE(t.requires_tooth_number, 1) AS requires_tooth_number,
                u.full_name as performed_by_name
                FROM case_treatments ct
                LEFT JOIN treatments t ON ct.treatment_id = t.id
                LEFT JOIN users u ON ct.performed_by = u.id
                WHERE ct.case_id = :case_id
                -- The clinical table reads top-to-bottom as work was added.
                -- New rows must append at the end, never jump to row one.
                ORDER BY ct.created_at ASC, ct.id ASC";
        return $this->db->fetchAll($sql, ['case_id' => $caseId]);
    }

    /**
     * Update treatment
     */
    public function updateTreatment($caseId, $treatmentId, $data) {
        try {
            $this->db->beginTransaction();
            $oldTreatment = $this->db->fetch(
                'SELECT * FROM case_treatments WHERE id=:id AND case_id=:case_id FOR UPDATE',
                ['id' => $treatmentId, 'case_id' => $caseId]
            );
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
            if ($oldTreatment) {
                AuditLog::logChange('case_treatment_updated', 'case_treatments', $treatmentId, $oldTreatment, $data);
            }
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
            $oldTreatment = $this->db->fetch(
                'SELECT * FROM case_treatments WHERE id=:id AND case_id=:case_id FOR UPDATE',
                ['id' => $treatmentId, 'case_id' => $caseId]
            );
            $stmt = $this->db->delete('case_treatments', 'id = :id AND case_id = :case_id', [
                'id' => $treatmentId,
                'case_id' => $caseId
            ]);
            if ($stmt->rowCount() === 0) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'چارەسەرەکە نەدۆزرایەوە'];
            }
            $this->updateCaseTotals($caseId);
            if ($oldTreatment) {
                AuditLog::log('case_treatment_deleted', 'case_treatments', $treatmentId, $oldTreatment, null);
            }
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

        // Calculate total from treatments (cancelled treatments no longer count)
        $sql = "SELECT SUM(final_price) as total FROM case_treatments WHERE case_id = :case_id AND status <> 'cancelled'";
        $result = $this->db->fetch($sql, ['case_id' => $caseId]);
        $treatmentTotal = $result['total'] ?? 0;

        // Also include arch procedure log prices (for arch-based cases)
        $sql = "SELECT SUM(price) as total FROM arch_procedure_logs WHERE case_id = :case_id";
        $result = $this->db->fetch($sql, ['case_id' => $caseId]);
        $archLogTotal = $result['total'] ?? 0;

        // Combined total
        $total = $treatmentTotal + $archLogTotal;

        // paid = the received amount carried by approved treatment invoices,
        // plus approved later-payment allocations and legacy unallocated money.
        $paidRow = $this->db->fetch(
            "SELECT COALESCE(SUM(pa.allocated_amount), 0) AS allocated
               FROM payment_allocations pa
               JOIN payments p ON p.id = pa.payment_id AND p.status = 'approved'
               JOIN case_invoices ci ON ci.id = pa.invoice_id
              WHERE ci.case_id = ?", [$caseId]);
        $unallocRow = $this->db->fetch(
            "SELECT COALESCE(SUM(p.amount), 0) AS amt FROM payments p
              WHERE p.case_id = ? AND p.status = 'approved'
                AND NOT EXISTS (SELECT 1 FROM payment_allocations pa2 WHERE pa2.payment_id = p.id)", [$caseId]);
        $invoiceReceivedRow = $this->db->fetch(
            "SELECT COALESCE(SUM(LEAST(
                        GREATEST(0, ci.total_amount-ci.discount_amount),
                        GREATEST(0, COALESCE(ci.draft_received_amount,0))
                          + GREATEST(0, COALESCE(ci.prior_paid_amount,0))
                          + GREATEST(0, COALESCE(ci.advance_paid_amount,0))
                    )),0) AS amt
               FROM case_invoices ci
              WHERE ci.case_id = ? AND ci.accounting_status = 'approved'", [$caseId]);
        $paid = (float)$paidRow['allocated'] + (float)$unallocRow['amt'] + (float)$invoiceReceivedRow['amt'];

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

        // Money comes from the invoice ledger (v_case_financial_summary), never
        // from the cached c.total_amount/paid_amount/discount_amount columns:
        // those are fed by updateCaseTotals(), whose discount is the LEGACY
        // case-level discount_type/discount_value. A discount given on a receipt
        // never reaches them, so `total - paid - discount` overstates what the
        // patient still owes by exactly that discount. Same formula as
        // cases/view.php, the approval center and the financial summary.
        $sql = "SELECT c.*, pf.folder_name, pf.doctor_id, pf.patient_id,
                p.full_name as patient_name, p.patient_code,
                u.full_name as doctor_name,
                COALESCE(fs.total_invoiced, 0) AS ledger_total,
                COALESCE(fs.total_discount, 0) AS ledger_discount,
                COALESCE(fs.paid_amount, 0)    AS ledger_paid,
                COALESCE(fs.balance, 0)        AS ledger_balance
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN users u ON pf.doctor_id = u.id
                LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
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
     *
     * Admin only — deletes whatever is attached. The `cases` foreign keys
     * CASCADE into case_treatments, case_images, dental_chart, payments,
     * installment_plans, prescriptions, lab_orders and arch_procedure_logs,
     * so this can destroy treatment and money records without warning.
     * User actions must go through deleteIfEmpty(), or CaseDeletion for
     * permanent removal with a required reason and a transactional archive.
     */
    public function delete($id) {
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        if (!$auth->isAdmin() || !$user || $user['role'] !== ROLE_ADMIN || empty($user['is_active'])) {
            return ['success' => false, 'message' => 'تەنها بەڕێوەبەر دەتوانێت کەیس بسڕێتەوە'];
        }
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
     * What attached records stop this case from being deleted.
     *
     * Returns [] when the case holds nothing — i.e. it is safe to remove.
     * Otherwise each entry is ['key','count','label' (Kurdish)] so the caller
     * can tell the user exactly what is in the way.
     *
     * Invoices and treatments are the two the clinic asked for. The rest are
     * here because the cases FKs CASCADE: without these checks, deleting a
     * case would silently take payments, installment plans and uploaded
     * photos with it. case_invoices and accounting_reports have NO foreign
     * key to cases, so they would instead be left pointing at a dead case id.
     */
    public function deletionBlockers($caseId) {
        $caseId = (int)$caseId;
        $row = $this->db->fetch(
            "SELECT
                (SELECT COUNT(*) FROM case_treatments    WHERE case_id = :c1) AS treatments,
                (SELECT COUNT(*) FROM case_invoices      WHERE case_id = :c2) AS invoices,
                (SELECT COUNT(*) FROM payments           WHERE case_id = :c3) AS payments,
                (SELECT COUNT(*) FROM installment_plans  WHERE case_id = :c4) AS installments,
                (SELECT COUNT(*) FROM case_images        WHERE case_id = :c5) AS images,
                (SELECT COUNT(*) FROM accounting_reports WHERE case_id = :c6) AS accounting",
            ['c1' => $caseId, 'c2' => $caseId, 'c3' => $caseId,
             'c4' => $caseId, 'c5' => $caseId, 'c6' => $caseId]
        ) ?: [];

        $labels = [
            'treatments'   => 'چارەسەر',
            'invoices'     => 'پسووڵە',
            'payments'     => 'پارەدان',
            'installments' => 'قیست',
            'images'       => 'وێنە',
            'accounting'   => 'ڕاپۆرتی ژمێریاری',
        ];

        $blockers = [];
        foreach ($labels as $key => $label) {
            $n = (int)($row[$key] ?? 0);
            if ($n > 0) {
                $blockers[] = ['key' => $key, 'count' => $n, 'label' => $label];
            }
        }
        return $blockers;
    }

    /**
     * Admin only: delete a case ONLY when nothing is attached to it.
     *
     * The emptiness check is repeated here rather than trusted from the page
     * that drew the button: the list is rendered once, and a treatment or
     * invoice can be added by someone else before the admin clicks delete.
     * Re-checking inside the transaction closes that window.
     */
    public function deleteIfEmpty($caseId) {
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        if (!$auth->isAdmin() || !$user || $user['role'] !== ROLE_ADMIN || empty($user['is_active'])) {
            return ['success' => false, 'message' => 'تەنها بەڕێوەبەر دەتوانێت کەیس بسڕێتەوە'];
        }
        $caseId = (int)$caseId;
        $case   = $this->getById($caseId);
        if (!$case) {
            return ['success' => false, 'message' => 'کەیس نەدۆزرایەوە'];
        }

        try {
            $this->db->beginTransaction();

            $blockers = $this->deletionBlockers($caseId);
            if ($blockers) {
                $this->db->rollback();
                $parts = array_map(fn($b) => $b['count'] . ' ' . $b['label'], $blockers);
                return [
                    'success'  => false,
                    'blockers' => $blockers,
                    'message'  => 'ناتوانرێت بسڕدرێتەوە — ئەم کەیسە ' . implode('، ', $parts) . ' ی تێدایە',
                ];
            }

            $this->db->delete('cases', 'id = :id', ['id' => $caseId]);
            $this->db->commit();

            // Outside the transaction: recomputing folder totals is a
            // convenience, and must never roll back a completed delete.
            try {
                $this->folder->updateTotals($case['folder_id']);
            } catch (Throwable $e) {
                error_log('[CaseManager] folder totals after delete: ' . $e->getMessage());
            }

            return ['success' => true, 'case' => $case];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
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
        // Ledger-derived, like getAll(). total_remaining sums each case's own
        // balance (already floored at zero), so an overpaid case never cancels
        // out someone else's debt.
        $sql = "SELECT
                COALESCE(SUM(fs.total_invoiced), 0) as total_amount,
                COALESCE(SUM(fs.paid_amount), 0) as total_paid,
                COALESCE(SUM(fs.total_discount), 0) as total_discount,
                COALESCE(SUM(fs.balance), 0) as total_remaining
                FROM cases c
                INNER JOIN patient_folders pf ON c.folder_id = pf.id
                LEFT JOIN patients p ON pf.patient_id = p.id
                LEFT JOIN v_case_financial_summary fs ON fs.case_id = c.id
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

    /** True when S3 credentials exist — needed to read/delete existing S3 rows. */
    private function s3Configured(): bool {
        return !empty($_ENV['S3_ACCESS_KEY'] ?? getenv('S3_ACCESS_KEY'));
    }

    /**
     * Where NEW case-image uploads go. Driven by CASE_IMAGES_STORAGE (config),
     * which defaults to local on the dev machine and S3 on the live host.
     * Falls back to S3 only when it is actually configured.
     */
    private function storeImagesOnS3(): bool {
        $mode = defined('CASE_IMAGES_STORAGE')
            ? CASE_IMAGES_STORAGE
            : ($this->s3Configured() ? 's3' : 'local');
        return $mode === 's3' && $this->s3Configured();
    }

    /**
     * Upload image to a specific slot (1-6)
     * S3 Structure:    Cases/[Patient_Name]/[Case_ID]/Images/[filename]
     * Local structure: uploads/cases/[Case_ID]/[filename]
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

        // NEW uploads follow CASE_IMAGES_STORAGE (local on this dev machine).
        $useS3 = $this->storeImagesOnS3();

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
            // Local storage, one folder per case: uploads/cases/<case_id>/.
            // No patient name in the path (that folder is PHI-sensitive) and the
            // image is served through file.php, not by this path directly.
            $relDir   = 'uploads/cases/' . (int)$caseId . '/';
            $uploadDir = APP_ROOT . '/' . $relDir;
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return ['success' => false, 'message' => 'نەتوانرا فۆڵدەری وێنەکان دروست بکرێت'];
            }

            $filepath = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                return ['success' => false, 'message' => 'هەڵە لە بارکردنی فایل'];
            }
            $imagePath = $relDir . $filename;
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

        // Storage location is decided per-row (s3_key), not by the upload
        // preference — an old S3 image must still be removed from S3 even while
        // new uploads go local.
        if (!empty($image['s3_key']) && $this->s3Configured()) {
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

        // Per-row again: an S3 image is served presigned regardless of where
        // new uploads currently go.
        if (!empty($image['s3_key']) && $this->s3Configured()) {
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
