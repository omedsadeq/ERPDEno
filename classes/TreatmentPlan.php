<?php
/**
 * پلانی چارەسەر — the first-visit examination estimate, and its one-way door
 * into the real case/billing world.
 *
 * WHY THIS IS NOT A CASE
 * ----------------------
 * A plan is a quote. The doctor examines the patient, charts what each tooth
 * needs and what it would cost, and the patient leaves to think about it. On
 * paper this was two copies of a sheet; nothing was owed and nothing was
 * earned. So a plan deliberately has no invoice, no payment, no allocation,
 * and no row in any table the accounting code reads. The estimate columns here
 * are invisible to AccountingLedger, PatientLedger, PatientFinance, the
 * accountant's financial summary and every case/folder total — by construction,
 * because none of them join these two tables. Do not change that.
 *
 * The money becomes real at exactly one point: transferItems(). That copies a
 * planned item into case_treatments through CaseManager::addTreatment(), the
 * same call a doctor makes by hand, and from then on it is ordinary billable
 * work that the normal invoice/approval flow prices and collects. The plan row
 * keeps a pointer to where it went so the estimate stays auditable and can
 * never be transferred twice.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CaseManager.php';
require_once __DIR__ . '/Folder.php';
require_once __DIR__ . '/AuditLog.php';

class TreatmentPlan
{
    /** Plan statuses that still accept edits. */
    public const EDITABLE_STATUSES = ['open', 'partial'];

    /**
     * کێ چارەسەر دەکات؟ — decided by the examining doctor (plan.doctor_id,
     * the دکتۆری دەستنیشانکەر). NULL in the column means "not decided yet" and
     * behaves like MODE_SELF, which is how every plan behaved before.
     */
    public const MODE_SELF    = 'self';     // the examiner treats the patient himself
    public const MODE_DOCTOR  = 'doctor';   // one named colleague — دکتۆری دەستنیشانکراو
    public const MODE_DOCTORS = 'doctors';  // «چەند دکتۆرێک» — reception picks the doctor
    public const TREATING_MODES = [self::MODE_SELF, self::MODE_DOCTOR, self::MODE_DOCTORS];

    /**
     * Appointment statuses that still hold the patient's slot. 'rescheduled'
     * is appointments.php's «دواخراو»: the same booking moved to a new date,
     * so it is just as live as a scheduled one.
     */
    public const LIVE_APPOINTMENT_STATUSES = ['scheduled', 'confirmed', 'waiting', 'in_progress', 'rescheduled'];

    /** One notification type for every plan-routing message. */
    public const NOTIFY_TYPE = 'treatment_plan';

    private $db;

    /** users.id → doctors.id, per request (visits keep doctors.id). */
    private $doctorRowCache = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // Tooth helpers
    // ================================================================

    /**
     * FDI validity, per tooth set. The chart only ever offers these, but a
     * hand-crafted POST must not chart work on tooth 99.
     */
    public static function isValidTooth($tooth, string $dentition = 'permanent'): bool
    {
        $tooth = (int)$tooth;
        // Both tooth sets are charted together (mixed dentition), exactly as a
        // case does, so a plan accepts any permanent OR primary FDI number.
        // $dentition is kept for signature compatibility only.
        $ranges = [
            [11, 18], [21, 28], [31, 38], [41, 48],
            [51, 55], [61, 65], [71, 75], [81, 85],
        ];
        foreach ($ranges as [$lo, $hi]) {
            if ($tooth >= $lo && $tooth <= $hi) {
                return true;
            }
        }
        return false;
    }

    // ================================================================
    // Plans
    // ================================================================

    private function generatePlanNumber(): string
    {
        $prefix = 'TP' . date('Ym');
        $row = $this->db->fetch(
            "SELECT MAX(CAST(SUBSTRING(plan_number, 9) AS UNSIGNED)) AS max_num
             FROM treatment_plans WHERE plan_number LIKE :p",
            ['p' => $prefix . '%']
        );
        return $prefix . str_pad((int)($row['max_num'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Start a plan for a patient. $doctorId is the examining doctor's USER id
     * (same id space as patient_folders.doctor_id and case_invoices.doctor_id).
     */
    public function create(array $data): array
    {
        $patientId = (int)($data['patient_id'] ?? 0);
        $doctorId = (int)($data['doctor_id'] ?? 0);
        if ($patientId <= 0 || $doctorId <= 0) {
            return ['success' => false, 'message' => 'نەخۆش و دکتۆر پێویستن'];
        }
        $dentition = in_array($data['dentition'] ?? '', ['permanent', 'primary'], true)
            ? $data['dentition'] : 'permanent';
        // ددان / ئەرک — the same choice the new-case dialog offers. An arch plan
        // remembers which arch(es) it covers so its chart opens on them.
        $scopeType = ($data['scope_type'] ?? 'tooth') === 'arch' ? 'arch' : 'tooth';
        $selectedArch = in_array($data['selected_arch'] ?? '', ['upper', 'lower', 'both'], true)
            ? $data['selected_arch'] : null;
        if ($scopeType !== 'arch') {
            $selectedArch = null;
        }

        try {
            $planId = $this->db->insert('treatment_plans', [
                'plan_number'     => $this->generatePlanNumber(),
                'patient_id'      => $patientId,
                'doctor_id'       => $doctorId,
                'dentition'       => $dentition,
                'scope_type'      => $scopeType,
                'selected_arch'   => $selectedArch,
                'chief_complaint' => trim((string)($data['chief_complaint'] ?? '')) ?: null,
                'notes'           => trim((string)($data['notes'] ?? '')) ?: null,
                'duration_text'   => trim((string)($data['duration_text'] ?? '')) ?: null,
                'status'          => 'open',
                'created_by'      => (int)($data['created_by'] ?? $doctorId),
            ]);
            AuditLog::log('treatment_plan_created', 'treatment_plans', $planId, null, [
                'patient_id' => $patientId, 'doctor_id' => $doctorId,
            ]);
            // Tie the plan to the checkup visit reception registered, and
            // record the examiner there as دکتۆری دەستنیشانکەر.
            $this->safeSyncVisit((int)$planId);
            return ['success' => true, 'plan_id' => $planId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * «پلانی چارەسەر» in one click: the doctor's plan for this patient begun
     * today and still open, or a new one — no dialog. A plan is only its day
     * to begin with; the complaint and the rest are written on the plan page,
     * and every plan holds tooth and whole-arch work alike, as a case does.
     *
     * @return int|null the plan id, or null when it could not be made
     */
    public function quickOpen(int $patientId, int $doctorId, int $actorId): ?int
    {
        if ($patientId <= 0 || $doctorId <= 0) {
            return null;
        }
        $today = $this->db->fetch(
            "SELECT id FROM treatment_plans
             WHERE patient_id = :p AND doctor_id = :d AND DATE(created_at) = CURDATE()
               AND status IN ('" . implode("', '", self::EDITABLE_STATUSES) . "')
             ORDER BY id DESC LIMIT 1",
            ['p' => $patientId, 'd' => $doctorId]
        );
        if ($today) {
            return (int)$today['id'];
        }
        $made = $this->create(['patient_id' => $patientId, 'doctor_id' => $doctorId, 'created_by' => $actorId ?: $doctorId]);
        return !empty($made['success']) ? (int)$made['plan_id'] : null;
    }

    public function getById(int $planId): ?array
    {
        $row = $this->db->fetch(
            "SELECT tp.*,
                    p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                    p.gender, p.date_of_birth,
                    u.full_name AS doctor_name,
                    cu.full_name AS created_by_name,
                    tu.full_name AS treating_doctor_name,
                    chu.full_name AS chosen_by_name,
                    du.full_name AS designated_by_name
             FROM treatment_plans tp
             JOIN patients p ON p.id = tp.patient_id
             LEFT JOIN users u ON u.id = tp.doctor_id
             LEFT JOIN users cu ON cu.id = tp.created_by
             LEFT JOIN users tu ON tu.id = tp.treating_doctor_id
             LEFT JOIN users chu ON chu.id = tp.chosen_by
             LEFT JOIN users du ON du.id = tp.designated_by
             WHERE tp.id = :id",
            ['id' => $planId]
        );
        return $row ?: null;
    }

    /**
     * The plans of a page of patients at once (the patients list): for each
     * patient who has one, how many and the newest. Cancelled plans do not
     * count; a doctor sees only the plans getByPatient() would show them.
     *
     * @return array patient id => ['count' => int, 'latest' => [id, plan_number, status]]
     */
    public function summaryForPatients(array $patientIds, ?int $doctorUserId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $patientIds))));
        if (!$ids) {
            return [];
        }
        $sql = "SELECT tp.id, tp.patient_id, tp.plan_number, tp.status FROM treatment_plans tp
                WHERE tp.patient_id IN (" . implode(',', $ids) . ") AND tp.status <> 'cancelled'";
        $params = [];
        if ($doctorUserId !== null) {
            $sql .= " AND (tp.doctor_id = :did OR tp.treating_doctor_id = :did2)";
            $params = ['did' => $doctorUserId, 'did2' => $doctorUserId];
        }
        $out = [];
        foreach ($this->db->fetchAll($sql . " ORDER BY tp.created_at DESC, tp.id DESC", $params) as $row) {
            $pid = (int)$row['patient_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['count' => 0, 'latest' => $row];
            }
            $out[$pid]['count']++;
        }
        return $out;
    }

    /** Every plan for a patient, newest first, with a live item count. */
    public function getByPatient(int $patientId, ?int $doctorUserId = null): array
    {
        $sql = "SELECT tp.*,
                       u.full_name AS doctor_name,
                       tu.full_name AS treating_doctor_name,
                       (SELECT COUNT(*) FROM treatment_plan_items i
                         WHERE i.plan_id = tp.id AND i.status <> 'cancelled') AS item_count,
                       (SELECT COUNT(*) FROM treatment_plan_items i
                         WHERE i.plan_id = tp.id AND i.status = 'planned') AS pending_count
                FROM treatment_plans tp
                LEFT JOIN users u ON u.id = tp.doctor_id
                LEFT JOIN users tu ON tu.id = tp.treating_doctor_id
                WHERE tp.patient_id = :pid";
        $params = ['pid' => $patientId];
        // Doctor isolation: a plan belongs to the doctor who examined and to
        // the doctor it was sent to for treatment — nobody else. The same
        // rule cases and x-rays follow.
        if ($doctorUserId !== null) {
            $sql .= " AND (tp.doctor_id = :did OR tp.treating_doctor_id = :did2)";
            $params['did'] = $doctorUserId;
            $params['did2'] = $doctorUserId;
        }
        $sql .= " ORDER BY tp.created_at DESC";
        return $this->db->fetchAll($sql, $params);
    }

    public function getItems(int $planId): array
    {
        return $this->db->fetchAll(
            "SELECT i.*,
                    t.category,
                    t.icon,
                    c.case_number AS transferred_case_number,
                    tu.full_name AS transferred_by_name
             FROM treatment_plan_items i
             LEFT JOIN treatments t ON t.id = i.treatment_id
             LEFT JOIN cases c ON c.id = i.transferred_case_id
             LEFT JOIN users tu ON tu.id = i.transferred_by
             WHERE i.plan_id = :pid
             ORDER BY i.sort_order, i.id",
            ['pid' => $planId]
        );
    }

    // ================================================================
    // Items
    // ================================================================

    /**
     * Price maths, shared by add and update so a row can never be stored with
     * a final_price that disagrees with its parts.
     */
    private function priceFields(array $data): array
    {
        $qty = max(1, (int)($data['quantity'] ?? 1));
        $unit = max(0, (float)($data['unit_price'] ?? 0));
        $discount = max(0, (float)($data['discount'] ?? 0));
        $total = $unit * $qty;
        if ($discount > $total) {
            $discount = $total; // a quote may be discounted to zero, never below
        }
        return [
            'quantity'    => $qty,
            'unit_price'  => $unit,
            'total_price' => $total,
            'discount'    => $discount,
            'final_price' => $total - $discount,
        ];
    }

    public function addItem(int $planId, array $data): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if (!in_array($plan['status'], self::EDITABLE_STATUSES, true)) {
            return ['success' => false, 'message' => 'ئەم پلانە داخراوە'];
        }

        $name = trim((string)($data['treatment_name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'ناوی چارەسەر پێویستە'];
        }

        $arch = in_array($data['arch_type'] ?? '', ['none', 'upper', 'lower'], true)
            ? $data['arch_type'] : 'none';
        $tooth = (int)($data['tooth_number'] ?? 0) ?: null;
        if ($tooth !== null && !self::isValidTooth($tooth, $plan['dentition'])) {
            return ['success' => false, 'message' => 'ژمارەی ددان نادروستە'];
        }
        // An arch job is a location, not a tooth — never both.
        if ($arch !== 'none') {
            $tooth = null;
        }
        // A whole-arch job on an arch plan widens the plan's arch selection,
        // the same way add_arch_treatment widens cases.selected_arch.
        if ($arch !== 'none' && ($plan['scope_type'] ?? 'tooth') === 'arch') {
            $this->widenSelectedArch($planId, $plan['selected_arch'] ?? null, $arch);
        }

        $minutes = isset($data['estimated_minutes']) && $data['estimated_minutes'] !== ''
            ? max(0, (int)$data['estimated_minutes']) : null;

        // The plan's percentage discount (setDiscount) reaches a new item too,
        // unless the item names its own.
        if (($plan['discount_mode'] ?? null) === 'percent' && (float)($plan['discount_value'] ?? 0) > 0
            && (float)($data['discount'] ?? 0) <= 0) {
            $gross = max(1, (int)($data['quantity'] ?? 1)) * max(0, (float)($data['unit_price'] ?? 0));
            $data['discount'] = round($gross * (float)$plan['discount_value'] / 100);
        }

        $row = array_merge($this->priceFields($data), [
            'plan_id'           => $planId,
            'treatment_id'      => (int)($data['treatment_id'] ?? 0) ?: null,
            'treatment_name'    => $name,
            'tooth_number'      => $tooth,
            'tooth_surface'     => $tooth !== null ? (trim((string)($data['tooth_surface'] ?? '')) ?: null) : null,
            'arch_type'         => $arch,
            'estimated_minutes' => $minutes,
            'notes'             => trim((string)($data['notes'] ?? '')) ?: null,
            'sort_order'        => (int)($this->db->fetch(
                "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM treatment_plan_items WHERE plan_id = :p",
                ['p' => $planId]
            )['n'] ?? 1),
            'status'            => 'planned',
        ]);

        try {
            $itemId = $this->db->insert('treatment_plan_items', $row);
            $this->recomputeTotals($planId);
            return ['success' => true, 'item_id' => $itemId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Whole-arch work — Maxilla / Mandible are ONE job each, never sixteen
     * teeth. Mirrors CaseManager::addArchTreatment(): 'both' becomes two rows
     * (one per arch), each priced by its own upper/lower unit price when given,
     * else by the shared unit price.
     */
    public function addArchItems(int $planId, array $data, string $arch): array
    {
        $arches = $arch === 'both' ? ['upper', 'lower'] : [$arch];
        if (array_diff($arches, ['upper', 'lower'])) {
            return ['success' => false, 'message' => 'هەڵبژاردنی ئەرک نادروستە'];
        }
        $upperUnit = isset($data['upper_unit_price']) && $data['upper_unit_price'] !== ''
            ? max(0, (float)$data['upper_unit_price']) : null;
        $lowerUnit = isset($data['lower_unit_price']) && $data['lower_unit_price'] !== ''
            ? max(0, (float)$data['lower_unit_price']) : null;
        $defaultUnit = max(0, (float)($data['unit_price'] ?? 0));

        $ids = [];
        foreach ($arches as $selectedArch) {
            $unit = $selectedArch === 'upper'
                ? ($upperUnit !== null ? $upperUnit : $defaultUnit)
                : ($lowerUnit !== null ? $lowerUnit : $defaultUnit);
            $res = $this->addItem($planId, [
                'treatment_id'      => $data['treatment_id'] ?? null,
                'treatment_name'    => $data['treatment_name'] ?? '',
                'arch_type'         => $selectedArch,
                'quantity'          => 1,
                'unit_price'        => $unit,
                'discount'          => $data['discount'] ?? 0,
                'estimated_minutes' => $data['estimated_minutes'] ?? '',
                'notes'             => $data['notes'] ?? '',
            ]);
            if (empty($res['success'])) {
                return $res;
            }
            $ids[] = (int)$res['item_id'];
        }
        return ['success' => true, 'item_ids' => $ids, 'count' => count($ids)];
    }

    /** Merge a newly used arch into the plan's selected_arch (upper+lower → both). */
    private function widenSelectedArch(int $planId, ?string $current, string $arch): void
    {
        if ($current === 'both' || $arch === 'both') {
            $new = 'both';
        } elseif (empty($current)) {
            $new = $arch;
        } elseif ($current !== $arch) {
            $new = 'both';
        } else {
            $new = $arch;
        }
        if ($new !== $current) {
            $this->db->update('treatment_plans', ['selected_arch' => $new], 'id = :id', ['id' => $planId]);
        }
    }

    /**
     * Edit a still-planned item. A transferred item is frozen: its numbers are
     * now the history behind a real case_treatments row, so changing them would
     * rewrite what the patient was quoted after the fact.
     */
    public function updateItem(int $itemId, array $data): array
    {
        $item = $this->db->fetch("SELECT * FROM treatment_plan_items WHERE id = :id", ['id' => $itemId]);
        if (!$item) {
            return ['success' => false, 'message' => 'ئایتم نەدۆزرایەوە'];
        }
        if ($item['status'] !== 'planned') {
            return ['success' => false, 'message' => 'ئەم ئایتمە گوازراوەتەوە و ناتوانرێت بگۆڕدرێت'];
        }

        $fields = $this->priceFields([
            'quantity'   => $data['quantity']   ?? $item['quantity'],
            'unit_price' => $data['unit_price'] ?? $item['unit_price'],
            'discount'   => $data['discount']   ?? $item['discount'],
        ]);
        if (array_key_exists('estimated_minutes', $data)) {
            $fields['estimated_minutes'] = $data['estimated_minutes'] !== ''
                ? max(0, (int)$data['estimated_minutes']) : null;
        }
        if (array_key_exists('notes', $data)) {
            $fields['notes'] = trim((string)$data['notes']) ?: null;
        }
        if (array_key_exists('treatment_name', $data)) {
            $name = trim((string)$data['treatment_name']);
            if ($name === '') {
                return ['success' => false, 'message' => 'ناوی چارەسەر پێویستە'];
            }
            $fields['treatment_name'] = $name;
        }

        try {
            $this->db->update('treatment_plan_items', $fields, 'id = :id', ['id' => $itemId]);
            $this->recomputeTotals((int)$item['plan_id']);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Remove a planned item outright. Transferred ones stay as history. */
    public function removeItem(int $itemId): array
    {
        $item = $this->db->fetch("SELECT * FROM treatment_plan_items WHERE id = :id", ['id' => $itemId]);
        if (!$item) {
            return ['success' => false, 'message' => 'ئایتم نەدۆزرایەوە'];
        }
        if ($item['status'] === 'transferred') {
            return ['success' => false, 'message' => 'ئایتمی گوازراوە ناسڕدرێتەوە'];
        }
        try {
            $this->db->delete('treatment_plan_items', 'id = :id', ['id' => $itemId]);
            $this->recomputeTotals((int)$item['plan_id']);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Header edits (complaint, notes, expected duration). */
    public function updatePlan(int $planId, array $data): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if (!in_array($plan['status'], self::EDITABLE_STATUSES, true)) {
            return ['success' => false, 'message' => 'ئەم پلانە داخراوە'];
        }
        $fields = [];
        foreach (['chief_complaint', 'notes', 'duration_text'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[$f] = trim((string)$data[$f]) ?: null;
            }
        }
        if (array_key_exists('dentition', $data)
            && in_array($data['dentition'], ['permanent', 'primary'], true)) {
            $fields['dentition'] = $data['dentition'];
        }
        if (array_key_exists('selected_arch', $data)
            && in_array($data['selected_arch'], ['upper', 'lower', 'both'], true)
            && ($plan['scope_type'] ?? 'tooth') === 'arch') {
            $fields['selected_arch'] = $data['selected_arch'];
        }
        if (!$fields) {
            return ['success' => true];
        }
        try {
            $this->db->update('treatment_plans', $fields, 'id = :id', ['id' => $planId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function cancelPlan(int $planId, ?string $reason, int $userId): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if ($plan['status'] === 'transferred') {
            return ['success' => false, 'message' => 'پلانێکی تەواو گوازراوە هەڵنەوەشێنرێتەوە'];
        }
        // A booked visit belongs to the doctor who booked it: the plan cannot
        // be withdrawn from under it, and nobody cancels it on his behalf.
        $live = $this->liveAppointment($plan);
        if ($live) {
            return ['success' => false, 'message' => 'ئەم پلانە مەوعیدێکی چالاکی هەیە ('
                . $live['appointment_date'] . ' — ' . ($live['doctor_name'] ?? '')
                . '). سەرەتا دەبێت ئەو دکتۆرە مەوعیدەکە هەڵبوەشێنێتەوە.'];
        }
        try {
            $this->db->update('treatment_plans', [
                'status'        => 'cancelled',
                'cancel_reason' => trim((string)$reason) ?: null,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancelled_by'  => $userId,
            ], 'id = :id', ['id' => $planId]);
            AuditLog::log('treatment_plan_cancelled', 'treatment_plans', $planId, null, ['reason' => $reason]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Refresh the cached estimate totals and the plan's status.
     *
     * Only 'planned' items count toward the estimate: once an item is
     * transferred its money lives on the case, and leaving it in the plan total
     * would show the patient a quote for work already being billed.
     */
    /**
     * A discount on the whole plan — an amount or a percentage, as on a
     * receipt. It is spread over the plan's waiting items as their own
     * discount, so the totals, the table and the transfer to a case carry it
     * unchanged: a percentage on each item, an amount in proportion to each
     * item's price (the last one takes the rounding). It replaces the items'
     * discounts; 0 clears them. Items already moved to a case keep theirs.
     */
    public function setDiscount(int $planId, string $mode, float $value, int $userId): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if (!in_array($plan['status'], self::EDITABLE_STATUSES, true)) {
            return ['success' => false, 'message' => 'ئەم پلانە داخراوە'];
        }
        $mode = $mode === 'percent' ? 'percent' : 'amount';
        $value = max(0, $value);
        if ($mode === 'percent' && $value > 100) {
            return ['success' => false, 'message' => 'ڕێژە ناتوانێت لە ١٠٠٪ زیاتر بێت'];
        }
        $items = $this->db->fetchAll(
            "SELECT id, total_price FROM treatment_plan_items WHERE plan_id = :p AND status = 'planned' ORDER BY sort_order, id",
            ['p' => $planId]
        );
        $base = array_sum(array_map(fn($i) => (float)$i['total_price'], $items));
        if ($value > 0 && $base <= 0) {
            return ['success' => false, 'message' => 'هیچ ئیشێکی چاوەڕوان نییە بۆ داشکاندن'];
        }
        if ($mode === 'amount' && $value > $base) {
            return ['success' => false, 'message' => 'داشکاندن لە کۆی پلان (' . number_format($base) . ') زیاترە'];
        }
        $amount = $mode === 'percent' ? round($base * $value / 100) : round($value);

        $shares = [];
        $given = 0;
        foreach ($items as $i => $item) {
            $total = (float)$item['total_price'];
            $share = $i === count($items) - 1
                ? $amount - $given
                : ($mode === 'percent' ? round($total * $value / 100) : round($amount * $total / $base));
            $share = min(max(0, $share), $total);
            $shares[(int)$item['id']] = [$share, $total];
            $given += $share;
        }

        try {
            $this->db->beginTransaction();
            foreach ($shares as $itemId => [$share, $total]) {
                $this->db->update('treatment_plan_items', ['discount' => $share, 'final_price' => $total - $share],
                    'id = :id', ['id' => $itemId]);
            }
            $this->db->update('treatment_plans', [
                'discount_mode'  => $value > 0 ? $mode : null,
                'discount_value' => $value,
            ], 'id = :id', ['id' => $planId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
        $this->recomputeTotals($planId);
        AuditLog::log('treatment_plan_discount', 'treatment_plans', $planId,
            ['discount_mode' => $plan['discount_mode'] ?? null, 'discount_value' => (float)($plan['discount_value'] ?? 0)],
            ['discount_mode' => $value > 0 ? $mode : null, 'discount_value' => $value, 'amount' => $amount], $userId ?: null);
        return ['success' => true, 'amount' => $amount];
    }

    public function recomputeTotals(int $planId): void
    {
        $sums = $this->db->fetch(
            "SELECT COALESCE(SUM(total_price), 0) AS est,
                    COALESCE(SUM(discount), 0)    AS disc,
                    COALESCE(SUM(final_price), 0) AS fin,
                    COALESCE(SUM(estimated_minutes), 0) AS mins
             FROM treatment_plan_items
             WHERE plan_id = :p AND status = 'planned'",
            ['p' => $planId]
        );
        $counts = $this->db->fetch(
            "SELECT
                SUM(status = 'planned')     AS planned,
                SUM(status = 'transferred') AS transferred
             FROM treatment_plan_items WHERE plan_id = :p",
            ['p' => $planId]
        );
        $planned = (int)($counts['planned'] ?? 0);
        $transferred = (int)($counts['transferred'] ?? 0);

        $current = $this->db->fetch("SELECT status FROM treatment_plans WHERE id = :p", ['p' => $planId]);
        $status = $current['status'] ?? 'open';
        // A cancelled plan stays cancelled; otherwise the status is a plain
        // function of how many items are still waiting on a decision.
        if ($status !== 'cancelled') {
            if ($transferred > 0 && $planned === 0) {
                $status = 'transferred';
            } elseif ($transferred > 0) {
                $status = 'partial';
            } else {
                $status = 'open';
            }
        }

        $this->db->update('treatment_plans', [
            'total_estimated' => (float)$sums['est'],
            'total_discount'  => (float)$sums['disc'],
            'total_final'     => (float)$sums['fin'],
            'total_minutes'   => (int)$sums['mins'],
            'status'          => $status,
        ], 'id = :id', ['id' => $planId]);
    }

    // ================================================================
    // Transfer — the one place an estimate becomes money
    // ================================================================

    /**
     * Move chosen planned items into a real case.
     *
     * $target is either ['case_id' => N] to append to one of the TREATING
     * doctor's open cases for this patient, or ['case_name' => '...'] to open
     * a new one under that doctor. The treating doctor is whoever the examiner
     * designated (or reception chose, for «چەند دکتۆرێک») — each doctor works
     * only in their own case, so the work never lands in the examiner's case
     * when somebody else is doing it. Every item is inserted with
     * CaseManager::addTreatment(), the identical call the doctor uses by hand —
     * so the work is priced, invoiced, approved and collected by the existing
     * flow with no special cases anywhere downstream. This method writes
     * nothing to case_invoices: the doctor still builds and sends the invoice
     * as always, which is what keeps "money only when it is real" true.
     *
     * The whole move is one transaction. A half-transferred plan would either
     * bill work twice or lose it.
     */
    public function transferItems(int $planId, array $itemIds, array $target, int $userId): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if ($plan['status'] === 'cancelled') {
            return ['success' => false, 'message' => 'پلانی هەڵوەشێنراوە ناگوازرێتەوە'];
        }
        $treatingId = self::treatingDoctorId($plan);
        if ($treatingId === null) {
            return ['success' => false, 'message' => 'پێشوازی هێشتا دکتۆری چارەسەرکەری دیاری نەکردووە — سەرەتا دکتۆرێک هەڵبژێرە'];
        }

        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if (!$itemIds) {
            return ['success' => false, 'message' => 'هیچ ئایتمێک هەڵنەبژێردراوە'];
        }

        // Re-read the items under the plan we were given: this is what stops a
        // forged request moving another plan's rows, and what stops a double
        // click transferring the same item twice.
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $items = $this->db->fetchAll(
            "SELECT * FROM treatment_plan_items
             WHERE plan_id = ? AND status = 'planned' AND id IN ($ph)
             ORDER BY sort_order, id",
            array_merge([$planId], $itemIds)
        );
        if (!$items) {
            return ['success' => false, 'message' => 'ئایتمی چاوەڕوان نەدۆزرایەوە (لەوانەیە پێشتر گوازرابێتەوە)'];
        }

        $caseManager = new CaseManager();

        try {
            $this->db->beginTransaction();

            // --- resolve the destination case ---
            $caseId = (int)($target['case_id'] ?? 0);
            if ($caseId > 0) {
                // Must be a case belonging to THIS patient, still open, and
                // the treating doctor's own.
                $case = $this->db->fetch(
                    "SELECT c.id, c.status, pf.doctor_id AS case_doctor_id FROM cases c
                     JOIN patient_folders pf ON pf.id = c.folder_id
                     WHERE c.id = :cid AND pf.patient_id = :pid",
                    ['cid' => $caseId, 'pid' => (int)$plan['patient_id']]
                );
                if (!$case) {
                    throw new Exception('کەیس لەگەڵ نەخۆش ناگونجێت');
                }
                if ((int)$case['case_doctor_id'] !== $treatingId) {
                    throw new Exception('ئەم کەیسە هی دکتۆری چارەسەرکەر نییە — تەنها بۆ کەیسی خۆی دەگوازرێتەوە');
                }
                if ($case['status'] === 'closed') {
                    throw new Exception('ناتوانرێت بۆ کەیسێکی داخراو بگوازرێتەوە');
                }
            } else {
                $caseName = trim((string)($target['case_name'] ?? ''));
                if ($caseName === '') {
                    throw new Exception('ناوی کەیس پێویستە');
                }
                $folderId = (new Folder())->findOrCreateDefault(
                    (int)$plan['patient_id'], $treatingId
                );
                if (!$folderId) {
                    throw new Exception('هەڵە لە دروستکردنی دۆسیە');
                }
                // The case opens on the same chart the estimate was drawn on:
                // an ئەرک plan becomes an ئەرک case, on the same arch(es).
                $created = $caseManager->create([
                    'folder_id'       => $folderId,
                    'case_name'       => $caseName,
                    'scope_type'      => ($plan['scope_type'] ?? 'tooth') === 'arch' ? 'arch' : 'tooth',
                    'selected_arch'   => ($plan['scope_type'] ?? 'tooth') === 'arch' ? ($plan['selected_arch'] ?: null) : null,
                    'dentition'       => $plan['dentition'],
                    'chief_complaint' => $plan['chief_complaint'],
                    'notes'           => 'دروستکراوە لە پلانی چارەسەر ' . $plan['plan_number'],
                    'created_by'      => $userId,
                ]);
                if (empty($created['success'])) {
                    throw new Exception($created['message'] ?? 'هەڵە لە دروستکردنی کەیس');
                }
                $caseId = (int)$created['case_id'];
            }

            // --- copy each item across ---
            $moved = [];
            foreach ($items as $item) {
                $res = $caseManager->addTreatment($caseId, [
                    'treatment_id'   => $item['treatment_id'] !== null ? (int)$item['treatment_id'] : null,
                    'tooth_number'   => $item['tooth_number'] !== null ? (int)$item['tooth_number'] : null,
                    'tooth_surface'  => $item['tooth_surface'],
                    'arch_type'      => $item['arch_type'],
                    'treatment_name' => $item['treatment_name'],
                    'quantity'       => (int)$item['quantity'],
                    'unit_price'     => (float)$item['unit_price'],
                    'discount'       => (float)$item['discount'],
                    'notes'          => $item['notes'],
                    'performed_by'   => $treatingId,
                ]);
                if (empty($res['success'])) {
                    throw new Exception($res['message'] ?? 'هەڵە لە گواستنەوەی ئایتم');
                }
                $this->db->update('treatment_plan_items', [
                    'status'                   => 'transferred',
                    'transferred_case_id'      => $caseId,
                    'transferred_treatment_id' => (int)$res['treatment_id'],
                    'transferred_at'           => date('Y-m-d H:i:s'),
                    'transferred_by'           => $userId,
                ], 'id = :id', ['id' => (int)$item['id']]);
                $moved[] = (int)$item['id'];
            }

            $this->db->commit();

            // Totals and status sit outside the transaction on purpose: they are
            // a derived cache, and a failure here must not undo a real transfer.
            $this->recomputeTotals($planId);

            AuditLog::log('treatment_plan_transferred', 'treatment_plans', $planId, null, [
                'case_id' => $caseId, 'items' => $moved, 'treating_doctor_id' => $treatingId,
            ]);

            // Work moved on a plan nobody had routed yet: it went to the
            // examiner's own case, so the examiner is treating it himself —
            // record that decision instead of leaving the plan "undecided".
            if (empty($plan['treating_mode'])) {
                $this->pinSelf($plan, $userId);
            }
            // The plan's booked visit is now a visit for this case, so the
            // case page and every receipt printed from it show it as the
            // case's own next appointment.
            $live = $this->liveAppointment($this->getById($planId) ?: $plan);
            if ($live && empty($live['case_id'])) {
                try {
                    $this->db->update('appointments', ['case_id' => $caseId], 'id = :id', ['id' => (int)$live['id']]);
                } catch (Throwable $e) {
                    error_log('TreatmentPlan: linking appointment to case failed: ' . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'case_id' => $caseId,
                'moved'   => count($moved),
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ================================================================
    // Small read helpers for the UI
    // ================================================================

    /** Open plans waiting on a patient's decision — drives the tab badge. */
    public function countActiveForPatient(int $patientId, ?int $doctorUserId = null): int
    {
        $sql = "SELECT COUNT(*) c FROM treatment_plans
                WHERE patient_id = :pid AND status IN ('open','partial')";
        $params = ['pid' => $patientId];
        if ($doctorUserId !== null) {
            $sql .= " AND (doctor_id = :did OR treating_doctor_id = :did2)";
            $params['did'] = $doctorUserId;
            $params['did2'] = $doctorUserId;
        }
        return (int)($this->db->fetch($sql, $params)['c'] ?? 0);
    }

    /**
     * The patient's open cases a transfer may target. Given the treating
     * doctor, only that doctor's own cases — work is never put into another
     * doctor's case.
     */
    public function transferTargets(int $patientId, ?int $doctorUserId = null): array
    {
        $sql = "SELECT c.id, c.case_number, c.case_name, pf.folder_name,
                       u.full_name AS doctor_name
                FROM cases c
                JOIN patient_folders pf ON pf.id = c.folder_id
                LEFT JOIN users u ON u.id = pf.doctor_id
                WHERE pf.patient_id = :pid AND c.status <> 'closed'";
        $params = ['pid' => $patientId];
        if ($doctorUserId !== null) {
            $sql .= " AND pf.doctor_id = :did";
            $params['did'] = $doctorUserId;
        }
        $sql .= " ORDER BY c.created_at DESC";
        return $this->db->fetchAll($sql, $params);
    }

    // ================================================================
    // کێ چارەسەر دەکات؟ — designation, reception's choice, the booking
    // ================================================================
    //
    // The patient chose a doctor for the checkup. That doctor examined, wrote
    // this plan and is its دکتۆری دەستنیشانکەر (plan.doctor_id). He decides who
    // does the work: himself, one named colleague, or «چەند دکتۆرێک» — then
    // reception decides which doctor the patient goes to. The doctor who ends
    // up treating (دکتۆری بینراو) is the only one who books the appointment:
    // no doctor books on another doctor's behalf, and reception routes the
    // patient but does not book in this flow. Each doctor then works in their
    // own case (transferItems opens it under the treating doctor).

    /**
     * The doctor (users.id) who does the work: the examiner while nothing is
     * decided or when he keeps it, the named colleague, or reception's pick.
     * NULL only for «چەند دکتۆرێک» before reception has chosen.
     */
    public static function treatingDoctorId(array $plan): ?int
    {
        $mode = $plan['treating_mode'] ?? null;
        if ($mode === null || $mode === '' || $mode === self::MODE_SELF) {
            $examiner = (int)($plan['doctor_id'] ?? 0);
            return $examiner > 0 ? $examiner : null;
        }
        $id = (int)($plan['treating_doctor_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** undecided | self | doctor | awaiting_reception | chosen */
    public static function designationState(array $plan): string
    {
        switch ($plan['treating_mode'] ?? null) {
            case self::MODE_SELF:
                return 'self';
            case self::MODE_DOCTOR:
                return 'doctor';
            case self::MODE_DOCTORS:
                return (int)($plan['treating_doctor_id'] ?? 0) > 0 ? 'chosen' : 'awaiting_reception';
            default:
                return 'undecided';
        }
    }

    /** A doctor may see a plan he examined or one he was sent to treat. */
    public static function doctorMaySee(array $plan, int $userId): bool
    {
        return $userId > 0
            && ((int)($plan['doctor_id'] ?? 0) === $userId || self::treatingDoctorId($plan) === $userId);
    }

    /** «چەند دکتۆرێک» may be narrowed to named doctors; empty = any doctor. */
    public static function candidateIds(array $plan): array
    {
        $raw = trim((string)($plan['candidate_doctor_ids'] ?? ''));
        if ($raw === '') {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), function ($v) {
            return $v > 0;
        })));
    }

    /** Active doctor accounts, id → name, for the pickers and the checks. */
    public function activeDoctors(): array
    {
        $map = [];
        foreach ($this->db->fetchAll(
            "SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name"
        ) as $row) {
            $map[(int)$row['id']] = $row['full_name'];
        }
        return $map;
    }

    private function activeDoctorName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $row = $this->db->fetch(
            "SELECT full_name FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1",
            ['id' => $userId]
        );
        return $row ? (string)$row['full_name'] : null;
    }

    private function userName(int $userId): string
    {
        $row = $userId > 0 ? $this->db->fetch("SELECT full_name FROM users WHERE id = :id", ['id' => $userId]) : null;
        return $row ? (string)$row['full_name'] : '';
    }

    /**
     * users.id → doctors.id. The visits module keys doctors by the `doctors`
     * table; doctors.user_id is the link (DoctorSync), with the same legacy
     * name fallback edit_visit.php uses for rows not linked yet.
     */
    public function doctorRowId(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        if (!array_key_exists($userId, $this->doctorRowCache)) {
            $row = $this->db->fetch("SELECT id FROM doctors WHERE user_id = :uid ORDER BY is_active DESC, id LIMIT 1", ['uid' => $userId]);
            if (!$row) {
                // Explicit collation: users and doctors were created at
                // different times and need not share one.
                $row = $this->db->fetch(
                    "SELECT d.id FROM doctors d
                     JOIN users u ON u.full_name COLLATE utf8mb4_unicode_ci = d.name COLLATE utf8mb4_unicode_ci
                     WHERE u.id = :uid ORDER BY d.is_active DESC, d.id LIMIT 1",
                    ['uid' => $userId]
                );
            }
            $this->doctorRowCache[$userId] = $row ? (int)$row['id'] : null;
        }
        return $this->doctorRowCache[$userId];
    }

    /** The plan's booked appointment while it still holds a slot, else null. */
    public function liveAppointment(array $plan): ?array
    {
        $apptId = (int)($plan['appointment_id'] ?? 0);
        if ($apptId <= 0) {
            return null;
        }
        try {
            $row = $this->db->fetch(
                "SELECT a.*, u.full_name AS doctor_name
                 FROM appointments a
                 LEFT JOIN users u ON u.id = a.doctor_id
                 WHERE a.id = :id",
                ['id' => $apptId]
            );
        } catch (Throwable $e) {
            error_log('TreatmentPlan::liveAppointment failed: ' . $e->getMessage());
            return null;
        }
        if (!$row || !in_array($row['status'], self::LIVE_APPOINTMENT_STATUSES, true)) {
            return null;
        }
        return $row;
    }

    /**
     * Why the treating doctor can no longer be changed, or null when it can.
     * Once work sits in the treating doctor's case, or that doctor has booked
     * the patient, moving the plan would strand it — and nobody may cancel
     * another doctor's appointment for him.
     */
    public function designationLockReason(array $plan): ?string
    {
        $moved = $this->db->fetch(
            "SELECT c.case_number FROM treatment_plan_items i
             LEFT JOIN cases c ON c.id = i.transferred_case_id
             WHERE i.plan_id = :p AND i.status = 'transferred'
             LIMIT 1",
            ['p' => (int)$plan['id']]
        );
        if ($moved) {
            return 'ئیشی ئەم پلانە گوازراوەتەوە بۆ کەیسی ' . ($moved['case_number'] ?? '')
                . ' — ئیتر دکتۆری چارەسەرکەر ناگۆڕدرێت';
        }
        $appt = $this->liveAppointment($plan);
        if ($appt) {
            return ($appt['doctor_name'] ?: 'دکتۆری چارەسەرکەر') . ' مەوعیدی ' . $appt['appointment_date']
                . ' ی داناوە — سەرەتا دەبێت خۆی مەوعیدەکە هەڵبوەشێنێتەوە';
        }
        return null;
    }

    /**
     * The examiner decides who treats. $doctorUserId is the named colleague
     * for MODE_DOCTOR; $candidateIds optionally narrows «چەند دکتۆرێک».
     * Naming yourself as the colleague is simply MODE_SELF.
     */
    public function designate(int $planId, string $mode, ?int $doctorUserId, array $candidateIds, int $actorId): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if (!in_array($plan['status'], self::EDITABLE_STATUSES, true)) {
            return ['success' => false, 'message' => 'ئەم پلانە داخراوە'];
        }
        if (!in_array($mode, self::TREATING_MODES, true)) {
            return ['success' => false, 'message' => 'هەڵبژاردنەکە نادروستە'];
        }

        $examinerId = (int)$plan['doctor_id'];
        $treating = null;
        $candidates = null;
        if ($mode === self::MODE_DOCTOR) {
            $doctorUserId = (int)$doctorUserId;
            if ($doctorUserId === $examinerId) {
                $mode = self::MODE_SELF;
            } elseif ($this->activeDoctorName($doctorUserId) === null) {
                return ['success' => false, 'message' => 'تکایە دکتۆرێک هەڵبژێرە'];
            } else {
                $treating = $doctorUserId;
            }
        }
        if ($mode === self::MODE_SELF) {
            $treating = $examinerId;
        }
        if ($mode === self::MODE_DOCTORS) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $candidateIds), function ($v) {
                return $v > 0;
            })));
            $active = $this->activeDoctors();
            foreach ($ids as $id) {
                if (!isset($active[$id])) {
                    return ['success' => false, 'message' => 'یەکێک لە دکتۆرە هەڵبژێردراوەکان چالاک نییە'];
                }
            }
            if (count($ids) === 1) {
                return ['success' => false, 'message' => 'تەنها یەک دکتۆرت هەڵبژاردووە — «دکتۆرێکی دیاریکراو» هەڵبژێرە'];
            }
            sort($ids); // same set in another order is the same decision
            $candidates = $ids ? implode(',', $ids) : null;
        }

        // Same decision again: nothing to do (and reception's pick survives).
        $sameMode = ($plan['treating_mode'] ?? null) === $mode;
        if ($sameMode && $mode === self::MODE_DOCTORS
            && (string)($plan['candidate_doctor_ids'] ?? '') === (string)($candidates ?? '')) {
            return ['success' => true, 'changed' => false];
        }
        if ($sameMode && $mode !== self::MODE_DOCTORS && (int)($plan['treating_doctor_id'] ?? 0) === (int)$treating) {
            return ['success' => true, 'changed' => false];
        }

        $lock = $this->designationLockReason($plan);
        if ($lock !== null) {
            return ['success' => false, 'message' => $lock];
        }

        $row = [
            'treating_mode'        => $mode,
            'treating_doctor_id'   => $treating,
            'candidate_doctor_ids' => $candidates,
            'designated_by'        => $actorId ?: null,
            'designated_at'        => date('Y-m-d H:i:s'),
            'chosen_by'            => null,
            'chosen_at'            => null,
        ];
        $this->db->update('treatment_plans', $row, 'id = :id', ['id' => $planId]);
        AuditLog::logChange('treatment_plan_designated', 'treatment_plans', $planId, $plan, $row, $actorId ?: null);
        $this->safeSyncVisit($planId);

        $fresh = $this->getById($planId) ?: $plan;
        $patient = $plan['patient_name'] ?? '';
        $examiner = $plan['doctor_name'] ?? '';
        if ($mode === self::MODE_DOCTOR) {
            $this->notify([$treating], 'پلانی چارەسەر بۆ تۆ ئاراستەکرا',
                "نەخۆش {$patient} — {$examiner} پلانی {$plan['plan_number']} ی بۆ تۆ دەستنیشان کرد. تکایە مەوعیدی بۆ دابنێ.",
                $fresh, $actorId);
        } elseif ($mode === self::MODE_DOCTORS) {
            $this->notifyReception('پلانێک چاوەڕوانی دیاریکردنی دکتۆرە',
                "نەخۆش {$patient} — {$examiner} پلانی {$plan['plan_number']} ی بۆ «چەند دکتۆرێک» دەستنیشان کرد. تکایە دکتۆرێک هەڵبژێرە.",
                $fresh, $actorId);
        }
        return ['success' => true, 'changed' => true];
    }

    /** Reception picks the doctor for a «چەند دکتۆرێک» plan. */
    public function chooseDoctor(int $planId, int $doctorUserId, int $actorId): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if (!in_array($plan['status'], self::EDITABLE_STATUSES, true)) {
            return ['success' => false, 'message' => 'ئەم پلانە داخراوە'];
        }
        if (($plan['treating_mode'] ?? null) !== self::MODE_DOCTORS) {
            return ['success' => false, 'message' => 'ئەم پلانە بۆ «چەند دکتۆرێک» دەستنیشان نەکراوە'];
        }
        $doctorName = $this->activeDoctorName($doctorUserId);
        if ($doctorName === null) {
            return ['success' => false, 'message' => 'تکایە دکتۆرێک هەڵبژێرە'];
        }
        $candidates = self::candidateIds($plan);
        if ($candidates && !in_array($doctorUserId, $candidates, true)) {
            return ['success' => false, 'message' => 'ئەم دکتۆرە لەناو ئەو دکتۆرانەدا نییە کە دکتۆری پشکنەر دیاری کردوون'];
        }
        if ((int)($plan['treating_doctor_id'] ?? 0) === $doctorUserId) {
            return ['success' => true, 'changed' => false];
        }
        $lock = $this->designationLockReason($plan);
        if ($lock !== null) {
            return ['success' => false, 'message' => $lock];
        }

        $row = [
            'treating_doctor_id' => $doctorUserId,
            'chosen_by'          => $actorId ?: null,
            'chosen_at'          => date('Y-m-d H:i:s'),
        ];
        $this->db->update('treatment_plans', $row, 'id = :id', ['id' => $planId]);
        AuditLog::logChange('treatment_plan_doctor_chosen', 'treatment_plans', $planId, $plan, $row, $actorId ?: null);
        $this->safeSyncVisit($planId);

        $fresh = $this->getById($planId) ?: $plan;
        $patient = $plan['patient_name'] ?? '';
        $this->notify([$doctorUserId], 'نەخۆشێک بۆ تۆ نێردرا',
            "نەخۆش {$patient} — پێشوازی پلانی {$plan['plan_number']} (پشکنینی {$plan['doctor_name']}) ی بۆ تۆ نارد. تکایە مەوعیدی بۆ دابنێ.",
            $fresh, $actorId);
        return ['success' => true, 'changed' => true];
    }

    /**
     * The treating doctor books (or moves) the patient's treatment visit.
     * Only that doctor — nobody books on another doctor's behalf.
     * $data: appointment_date (Y-m-d), appointment_time (H:i), duration
     * (minutes, optional), notes (optional).
     */
    public function bookAppointment(int $planId, array $data, int $actorId): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        if ($plan['status'] === 'cancelled') {
            return ['success' => false, 'message' => 'پلانی هەڵوەشێنراوە مەوعیدی بۆ دانانرێت'];
        }
        $treatingId = self::treatingDoctorId($plan);
        if ($treatingId === null) {
            return ['success' => false, 'message' => 'پێشوازی هێشتا دکتۆری چارەسەرکەری دیاری نەکردووە'];
        }
        if ($treatingId !== $actorId) {
            $name = $this->userName($treatingId) ?: 'دکتۆری چارەسەرکەر';
            return ['success' => false, 'message' => 'تەنها ' . $name . ' دەتوانێت مەوعیدی ئەم پلانە دابنێت'];
        }

        $date = trim((string)($data['appointment_date'] ?? ''));
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return ['success' => false, 'message' => 'بەروار نادروستە'];
        }
        if ($date < date('Y-m-d')) {
            return ['success' => false, 'message' => 'بەرواری مەوعید نابێت ڕابردوو بێت'];
        }
        $time = trim((string)($data['appointment_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time)) {
            return ['success' => false, 'message' => 'کاتی مەوعید نادروستە'];
        }
        $time = substr($time, 0, 5) . ':00';
        $duration = (int)($data['duration'] ?? 0);
        if ($duration <= 0) {
            $duration = (int)$plan['total_minutes'] > 0 ? (int)$plan['total_minutes'] : 30;
        }
        $duration = max(5, min(480, $duration));
        $extra = trim((string)($data['notes'] ?? ''));
        $notes = 'پلانی چارەسەر ' . $plan['plan_number'] . ($extra !== '' ? ' — ' . $extra : '');

        // The examiner booking his own patient on an undecided plan has made
        // the decision: he is treating it himself.
        if (empty($plan['treating_mode'])) {
            $this->pinSelf($plan, $actorId);
            $plan = $this->getById($planId) ?: $plan;
        }

        require_once __DIR__ . '/Appointment.php';
        $appointments = new Appointment();
        $live = $this->liveAppointment($plan);
        $rescheduled = false;
        if ($live && (int)$live['doctor_id'] === $actorId) {
            $update = [
                'appointment_date' => $date,
                'appointment_time' => $time,
                'duration'         => $duration,
                'notes'            => $notes,
                'updated_by'       => $actorId,
            ];
            if ($live['appointment_date'] !== $date || substr((string)$live['appointment_time'], 0, 5) !== substr($time, 0, 5)) {
                $update['rescheduled_from_date'] = $live['appointment_date'];
                $update['rescheduled_from_time'] = $live['appointment_time'];
                $update['rescheduled_at'] = date('Y-m-d H:i:s');
                $rescheduled = true;
            }
            $res = $appointments->update((int)$live['id'], $update);
            $appointmentId = (int)$live['id'];
        } else {
            // Work already moved into a case → the visit belongs to that case.
            $case = $this->db->fetch(
                "SELECT transferred_case_id FROM treatment_plan_items
                 WHERE plan_id = :p AND status = 'transferred' AND transferred_case_id IS NOT NULL
                 ORDER BY transferred_at DESC, id DESC LIMIT 1",
                ['p' => $planId]
            );
            $res = $appointments->create([
                'patient_id'       => (int)$plan['patient_id'],
                'doctor_id'        => $actorId,
                'case_id'          => $case ? (int)$case['transferred_case_id'] : null,
                'appointment_date' => $date,
                'appointment_time' => $time,
                'duration'         => $duration,
                'type'             => 'treatment',
                'status'           => 'scheduled',
                'notes'            => $notes,
                'created_by'       => $actorId,
            ]);
            $appointmentId = (int)($res['appointment_id'] ?? 0);
        }
        if (empty($res['success']) || $appointmentId <= 0) {
            return ['success' => false, 'message' => $res['message'] ?? 'هەڵە لە دانانی مەوعید'];
        }

        $this->db->update('treatment_plans', ['appointment_id' => $appointmentId], 'id = :id', ['id' => $planId]);
        AuditLog::log('treatment_plan_appointment_booked', 'treatment_plans', $planId,
            $live ? ['appointment_id' => (int)$live['id'], 'date' => $live['appointment_date'], 'time' => $live['appointment_time']] : null,
            ['appointment_id' => $appointmentId, 'date' => $date, 'time' => $time, 'duration' => $duration],
            $actorId);
        $this->safeSyncVisit($planId);

        return [
            'success'        => true,
            'appointment_id' => $appointmentId,
            'rescheduled'    => $rescheduled,
            'updated'        => (bool)$live,
        ];
    }

    /** The doctor who booked the plan's appointment cancels it. */
    public function cancelAppointment(int $planId, int $actorId, string $reason = ''): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return ['success' => false, 'message' => 'پلان نەدۆزرایەوە'];
        }
        $live = $this->liveAppointment($plan);
        if (!$live) {
            return ['success' => false, 'message' => 'ئەم پلانە مەوعیدێکی چالاکی نییە'];
        }
        if ((int)$live['doctor_id'] !== $actorId) {
            return ['success' => false, 'message' => 'تەنها ' . ($live['doctor_name'] ?: 'دکتۆرەکەی') . ' دەتوانێت ئەم مەوعیدە هەڵبوەشێنێتەوە'];
        }
        require_once __DIR__ . '/Appointment.php';
        $reason = trim($reason);
        $res = (new Appointment())->update((int)$live['id'], [
            'status'               => 'cancelled',
            'cancellation_reason'  => mb_substr($reason !== '' ? $reason : 'هەڵوەشاندنەوە لە پلانی چارەسەر', 0, 100),
            'cancellation_comment' => 'پلانی چارەسەر ' . $plan['plan_number'],
            'cancelled_at'         => date('Y-m-d H:i:s'),
            'cancelled_by'         => $actorId,
            'updated_by'           => $actorId,
        ]);
        if (empty($res['success'])) {
            return ['success' => false, 'message' => $res['message'] ?? 'هەڵە لە هەڵوەشاندنەوەی مەوعید'];
        }
        $this->db->update('treatment_plans', ['appointment_id' => null], 'id = :id', ['id' => $planId]);
        AuditLog::log('treatment_plan_appointment_cancelled', 'treatment_plans', $planId,
            ['appointment_id' => (int)$live['id'], 'date' => $live['appointment_date'], 'time' => $live['appointment_time']],
            ['reason' => $reason], $actorId);
        $this->safeSyncVisit($planId);
        return ['success' => true];
    }

    /** Record "the examiner treats it himself" on an undecided plan. Fail-soft. */
    private function pinSelf(array $plan, int $actorId): void
    {
        try {
            $row = [
                'treating_mode'      => self::MODE_SELF,
                'treating_doctor_id' => (int)$plan['doctor_id'],
                'designated_by'      => $actorId ?: null,
                'designated_at'      => date('Y-m-d H:i:s'),
            ];
            $this->db->update('treatment_plans', $row,
                "id = :id AND (treating_mode IS NULL OR treating_mode = '')", ['id' => (int)$plan['id']]);
            AuditLog::logChange('treatment_plan_designated', 'treatment_plans', (int)$plan['id'], $plan, $row, $actorId ?: null);
            $this->safeSyncVisit((int)$plan['id']);
        } catch (Throwable $e) {
            error_log('TreatmentPlan::pinSelf failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- visit

    /**
     * The reception checkup visit this plan came from: the patient's checkup
     * on the plan's day (or up to a week before), seen by the examiner, and
     * not already carrying another live plan. One plan per visit — the visit
     * has one دەستنیشانکراو slot. Stores the link; returns the visit id or 0.
     */
    private function linkVisit(array $plan): int
    {
        $day = substr((string)($plan['created_at'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = date('Y-m-d');
        }
        $params = [
            'pid'  => (int)$plan['patient_id'],
            'from' => date('Y-m-d', strtotime($day . ' -7 days')),
            'to'   => $day,
            'self' => (int)$plan['id'],
            'day'  => $day,
        ];
        // Never borrow a colleague's checkup: the visit must be the examiner's.
        // An examiner with no `doctors` row (e.g. the admin account) cannot be
        // matched to any visit, so such a plan stays unlinked.
        $examinerRow = $this->doctorRowId((int)$plan['doctor_id']);
        if (!$examinerRow) {
            return 0;
        }
        $params['d1'] = $examinerRow;
        $params['d2'] = $examinerRow;
        $params['d3'] = $examinerRow;
        $row = $this->db->fetch(
            "SELECT v.id FROM visits v
             WHERE v.patient_id = :pid
               AND v.visit_type = 'checkup'
               AND v.visit_date BETWEEN :from AND :to
               AND (v.checkup_doctor_id = :d1 OR v.assigned_doctor_id = :d2 OR v.requested_doctor_id = :d3)
               AND NOT EXISTS (SELECT 1 FROM treatment_plans o
                               WHERE o.visit_id = v.id AND o.id <> :self AND o.status <> 'cancelled')
             ORDER BY (v.visit_date = :day) DESC, v.visit_date DESC, v.id DESC
             LIMIT 1",
            $params
        );
        if (!$row) {
            return 0;
        }
        $this->db->update('treatment_plans', ['visit_id' => (int)$row['id']], 'id = :id', ['id' => (int)$plan['id']]);
        return (int)$row['id'];
    }

    /**
     * What the linked visit's appointment card must say, from this plan.
     * Undecided plans only name the examiner (دکتۆری دەستنیشانکەر). A doctor
     * account with no `doctors` row (the admin) cannot appear on a visit, so
     * those fields are left as reception typed them.
     */
    public function visitFieldsFor(array $plan): array
    {
        $fields = [];
        $examinerRow = $this->doctorRowId((int)$plan['doctor_id']);
        if ($examinerRow) {
            $fields['referring_doctor_id'] = (string)$examinerRow;
        }
        switch ($plan['treating_mode'] ?? null) {
            case self::MODE_SELF:
                if ($examinerRow) {
                    $fields['referring_doctor_designation'] = (string)$examinerRow;
                    $fields['referring_doctor_choice_id'] = null;
                }
                break;
            case self::MODE_DOCTOR:
                $row = $this->doctorRowId((int)$plan['treating_doctor_id']);
                if ($row) {
                    $fields['referring_doctor_designation'] = (string)$row;
                    $fields['referring_doctor_choice_id'] = null;
                }
                break;
            case self::MODE_DOCTORS:
                // Reception's pick is credited to reception, exactly as the
                // visit report reads it (کارمەندی دەستنیشانکەر).
                $fields['referring_doctor_designation'] = 'DOCTORS';
                $fields['referring_doctor_choice_id'] = (int)($plan['treating_doctor_id'] ?? 0) > 0
                    ? $this->doctorRowId((int)$plan['treating_doctor_id'])
                    : null;
                if ((int)($plan['chosen_by'] ?? 0) > 0) {
                    $fields['appointment_added_by'] = (int)$plan['chosen_by'];
                }
                break;
        }
        $appt = $this->liveAppointment($plan);
        $fields['appointment_date'] = $appt ? $appt['appointment_date'] : null;
        $fields['appointment_doctor_id'] = $appt ? $this->doctorRowId((int)$appt['doctor_id']) : null;
        return $fields;
    }

    /**
     * Write the plan's decision onto its checkup visit, so reception never
     * types it twice and the visit report counts it. Returns the changed
     * fields (empty when nothing changed or no visit is linked).
     */
    public function syncVisit(int $planId): array
    {
        $plan = $this->getById($planId);
        if (!$plan) {
            return [];
        }
        $visitId = (int)($plan['visit_id'] ?? 0);
        $visit = $visitId > 0 ? $this->db->fetch("SELECT * FROM visits WHERE id = :id", ['id' => $visitId]) : null;
        if (!$visit) {
            $visitId = $this->linkVisit($plan);
            if ($visitId <= 0) {
                return [];
            }
            $visit = $this->db->fetch("SELECT * FROM visits WHERE id = :id", ['id' => $visitId]);
            if (!$visit) {
                return [];
            }
        }

        $changes = [];
        foreach ($this->visitFieldsFor($plan) as $field => $value) {
            if (!array_key_exists($field, $visit)) {
                continue;
            }
            if ((string)($visit[$field] ?? '') !== (string)($value ?? '')) {
                $changes[$field] = $value;
            }
        }
        if (!$changes) {
            return [];
        }
        $this->db->update('visits', $changes, 'id = :id', ['id' => $visitId]);
        AuditLog::logChange('visit_synced_from_plan', 'visits', $visitId, $visit, $changes);
        return $changes;
    }

    /** syncVisit for callers whose own work already succeeded. */
    private function safeSyncVisit(int $planId): void
    {
        try {
            $this->syncVisit($planId);
        } catch (Throwable $e) {
            error_log('TreatmentPlan::syncVisit failed for plan ' . $planId . ': ' . $e->getMessage());
        }
    }

    /** The live plan (if any) whose decision fills this visit's card. */
    public function planForVisit(int $visitId): ?array
    {
        if ($visitId <= 0) {
            return null;
        }
        $row = $this->db->fetch(
            "SELECT id FROM treatment_plans
             WHERE visit_id = :v AND status <> 'cancelled'
             ORDER BY id LIMIT 1",
            ['v' => $visitId]
        );
        return $row ? $this->getById((int)$row['id']) : null;
    }

    // ---------------------------------------------------------------- notify

    private function notify(array $userIds, string $title, string $message, array $plan, int $actorId): void
    {
        try {
            require_once __DIR__ . '/Notification.php';
            (new Notification())->notifyUsers(
                $userIds, self::NOTIFY_TYPE, $title, $message,
                ['plan_id' => (int)$plan['id'], 'patient_id' => (int)$plan['patient_id'], 'plan_number' => $plan['plan_number']],
                '/treatment-plans/view.php?id=' . (int)$plan['id'],
                $actorId ?: null
            );
        } catch (Throwable $e) {
            error_log('TreatmentPlan notify failed: ' . $e->getMessage());
        }
    }

    private function notifyReception(string $title, string $message, array $plan, int $actorId): void
    {
        try {
            require_once __DIR__ . '/Notification.php';
            (new Notification())->notifyRole(
                ['receptionist'], self::NOTIFY_TYPE, $title, $message,
                ['plan_id' => (int)$plan['id'], 'patient_id' => (int)$plan['patient_id'], 'plan_number' => $plan['plan_number']],
                '/treatment-plans/index.php?tab=awaiting',
                $actorId ?: null
            );
        } catch (Throwable $e) {
            error_log('TreatmentPlan notifyReception failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- lists

    /**
     * Plan rows for the routing lists (treatment-plans/index.php), with the
     * patient, both doctors and the booked visit. $where is built by the
     * callers below from fixed SQL only.
     */
    private function routingList(string $where, array $params, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $live = "'" . implode("','", self::LIVE_APPOINTMENT_STATUSES) . "'";
        return $this->db->fetchAll(
            "SELECT tp.*,
                    p.full_name AS patient_name, p.patient_code, p.phone AS patient_phone,
                    u.full_name AS doctor_name,
                    tu.full_name AS treating_doctor_name,
                    chu.full_name AS chosen_by_name,
                    a.appointment_date, a.appointment_time,
                    (a.id IS NOT NULL) AS has_live_appointment,
                    (SELECT COUNT(*) FROM treatment_plan_items i
                      WHERE i.plan_id = tp.id AND i.status = 'planned') AS pending_count,
                    (SELECT COUNT(*) FROM treatment_plan_items i
                      WHERE i.plan_id = tp.id AND i.status <> 'cancelled') AS item_count
             FROM treatment_plans tp
             JOIN patients p ON p.id = tp.patient_id
             LEFT JOIN users u ON u.id = tp.doctor_id
             LEFT JOIN users tu ON tu.id = tp.treating_doctor_id
             LEFT JOIN users chu ON chu.id = tp.chosen_by
             LEFT JOIN appointments a ON a.id = tp.appointment_id AND a.status IN ({$live})
             WHERE {$where}
             ORDER BY COALESCE(tp.designated_at, tp.created_at) DESC, tp.id DESC
             LIMIT {$limit}",
            $params
        );
    }

    /** «چەند دکتۆرێک» plans reception still has to route. */
    public function awaitingReception(int $limit = 200): array
    {
        return $this->routingList(
            "tp.treating_mode = 'doctors' AND tp.treating_doctor_id IS NULL AND tp.status IN ('open','partial')",
            [], $limit
        );
    }

    /** Plans another doctor sent to this doctor to treat. */
    public function directedTo(int $doctorUserId, bool $openOnly = true, int $limit = 200): array
    {
        $where = "tp.treating_doctor_id = :did AND tp.doctor_id <> :did2";
        if ($openOnly) {
            $where .= " AND tp.status IN ('open','partial')";
        }
        return $this->routingList($where, ['did' => $doctorUserId, 'did2' => $doctorUserId], $limit);
    }

    /** Plans this doctor examined. */
    public function examinedBy(int $doctorUserId, int $limit = 200): array
    {
        return $this->routingList("tp.doctor_id = :did AND tp.status <> 'cancelled'", ['did' => $doctorUserId], $limit);
    }

    /** Every routed plan (reception/admin overview), newest decision first. */
    public function routedPlans(int $limit = 200): array
    {
        return $this->routingList(
            "tp.treating_mode IS NOT NULL AND tp.status <> 'cancelled'",
            [], $limit
        );
    }

    /** Badge: plans reception still has to route. Fail-soft (sidebar). */
    public function countAwaitingReception(): int
    {
        try {
            return (int)($this->db->fetch(
                "SELECT COUNT(*) c FROM treatment_plans
                 WHERE treating_mode = 'doctors' AND treating_doctor_id IS NULL AND status IN ('open','partial')"
            )['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Badge: plans sent to this doctor that he has not booked yet. Fail-soft. */
    public function countDirectedUnbooked(int $doctorUserId): int
    {
        try {
            $live = "'" . implode("','", self::LIVE_APPOINTMENT_STATUSES) . "'";
            return (int)($this->db->fetch(
                "SELECT COUNT(*) c FROM treatment_plans tp
                 LEFT JOIN appointments a ON a.id = tp.appointment_id AND a.status IN ({$live})
                 WHERE tp.treating_doctor_id = :did AND tp.doctor_id <> :did2
                   AND tp.status IN ('open','partial') AND a.id IS NULL",
                ['did' => $doctorUserId, 'did2' => $doctorUserId]
            )['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
