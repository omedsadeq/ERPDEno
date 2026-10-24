<?php
/**
 * داواکاری مەواد لە کۆگا — a doctor's request for store materials, made from a
 * case (cases/view.php «مەواد» dialog) and decided by the store
 * (erp/material-requests.php).
 *
 * pending → approved: the store may trim the quantities; approval creates the
 *           ordinary مەواد بۆ دکتۆر voucher (ErpDoctorIssue::create), which
 *           takes the stock out of the warehouse and charges the doctor.
 * pending → rejected (store, with a reason) or cancelled (the doctor).
 * Nothing is reserved while pending: stock is checked when it is approved.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ErpStock.php';
require_once __DIR__ . '/ErpSequence.php';
require_once __DIR__ . '/ErpAudit.php';
require_once __DIR__ . '/ErpDoctorIssue.php';
require_once __DIR__ . '/Notification.php';

class ErpMaterialRequest
{
    const CREATE_PERMISSION  = 'erp.material_request.create';
    const APPROVE_PERMISSION = 'erp.material_request.approve';

    const STATUS_LABELS = [
        'pending'   => 'چاوەڕوان',
        'approved'  => 'پەسەندکراو',
        'rejected'  => 'ڕەتکراوە',
        'cancelled' => 'هەڵوەشاوە',
    ];

    /** The store requests are filled from: the default warehouse. */
    public static function warehouseId(): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT id FROM erp_warehouses WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1"
        );
        return (int)($row['id'] ?? 0);
    }

    /** Every active material with its stock in the warehouse, grouped by category. */
    public static function sheet(): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT p.id, p.name, p.name_ku, c.name AS category, u.name AS unit,
                    COALESCE(s.quantity, 0) AS stock
             FROM erp_products p
             LEFT JOIN erp_categories c ON c.id = p.category_id
             LEFT JOIN erp_units u ON u.id = p.unit_id
             LEFT JOIN erp_warehouse_product_stock s ON s.product_id = p.id AND s.warehouse_id = :w
             WHERE p.is_active = 1
             ORDER BY c.name IS NULL, c.name, p.name",
            ['w' => self::warehouseId()]
        );
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['category'] ?? 'بێ پۆل';
            $groups[$key][] = [
                'id'      => (int)$r['id'],
                'name'    => $r['name'],
                'name_ku' => (string)($r['name_ku'] ?? ''),
                'unit'    => (string)($r['unit'] ?? ''),
                'stock'   => (float)$r['stock'],
            ];
        }
        $out = [];
        foreach ($groups as $name => $items) {
            $out[] = ['name' => $name, 'items' => $items];
        }
        return $out;
    }

    /**
     * File a request. The doctor is the user when they are a doctor, otherwise
     * the case's doctor — the one the materials are charged to on approval.
     */
    public static function create(int $caseId, array $items, ?string $notes, int $actorId): array
    {
        $db = Database::getInstance();
        $case = $db->fetch(
            "SELECT c.id, c.case_number, c.status, pf.patient_id, pf.doctor_id
             FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = :id",
            ['id' => $caseId]
        );
        if (!$case) {
            throw new RuntimeException('کەیس نەدۆزرایەوە');
        }
        if (($case['status'] ?? '') !== 'open') {
            throw new RuntimeException('کەیسەکە داخراوە');
        }
        $actor = $db->fetch("SELECT id, role FROM users WHERE id = :id AND is_active = 1", ['id' => $actorId]);
        $doctorId = ($actor['role'] ?? '') === 'doctor' ? (int)$actor['id'] : (int)($case['doctor_id'] ?? 0);
        if ($doctorId <= 0) {
            throw new RuntimeException('دکتۆری ئەم کەیسە دیاری نەکراوە');
        }

        $lines = [];
        foreach ($items as $raw) {
            $pid = (int)($raw['product_id'] ?? 0);
            $qty = round((float)($raw['quantity'] ?? 0), 3);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $lines[$pid] = round(($lines[$pid] ?? 0) + $qty, 3);
        }
        if (!$lines) {
            throw new RuntimeException('هیچ مەوادێک هەڵنەبژێردراوە');
        }
        $products = [];
        foreach (array_keys($lines) as $pid) {
            $p = $db->fetch("SELECT id, name FROM erp_products WHERE id = :id AND is_active = 1", ['id' => $pid]);
            if (!$p) {
                throw new RuntimeException('مەوادێک نەدۆزرایەوە یان ناچالاکە');
            }
            $products[$pid] = $p['name'];
        }
        $warehouseId = self::warehouseId();
        if ($warehouseId <= 0) {
            throw new RuntimeException('کۆگا نەدۆزرایەوە');
        }

        $db->beginTransaction();
        try {
            $id = (int)$db->insert('erp_material_requests', [
                'case_id'      => $caseId,
                'patient_id'   => $case['patient_id'] ? (int)$case['patient_id'] : null,
                'doctor_id'    => $doctorId,
                'warehouse_id' => $warehouseId,
                'status'       => 'pending',
                'notes'        => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : null,
                'created_by'   => $actorId,
            ]);
            foreach ($lines as $pid => $qty) {
                $db->insert('erp_material_request_items', [
                    'request_id'   => $id,
                    'product_id'   => $pid,
                    'product_name' => mb_substr($products[$pid], 0, 255),
                    'quantity'     => $qty,
                ]);
            }
            ErpAudit::log('material_request', 'MATERIAL_REQUEST', $id, '#' . $id, null, ['case_id' => $caseId, 'items' => $lines]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        // Tell the store. Never blocks the request.
        try {
            $roles = array_column($db->fetchAll(
                "SELECT role FROM role_permissions WHERE permission_key = :k AND is_allowed = 1",
                ['k' => self::APPROVE_PERMISSION]
            ), 'role');
            $roles[] = 'admin';
            (new Notification())->notifyRole(array_values(array_unique($roles)), 'material_request',
                'داواکاری مەوادی نوێ',
                'کەیس ' . $case['case_number'] . ' — ' . count($lines) . ' مەواد چاوەڕوانی پەسەندکردنە',
                ['request_id' => $id, 'case_id' => $caseId],
                APP_URL . '/erp/material-requests.php', $actorId);
        } catch (Throwable $e) {
            error_log('ErpMaterialRequest notify: ' . $e->getMessage());
        }
        return ['id' => $id, 'lines' => count($lines)];
    }

    /** Requests of one case, newest first, with their lines. */
    public static function forCase(int $caseId): array
    {
        return self::withItems(Database::getInstance()->fetchAll(
            "SELECT r.*, d.full_name AS doctor_name, i.issue_number
             FROM erp_material_requests r
             LEFT JOIN users d ON d.id = r.doctor_id
             LEFT JOIN erp_doctor_issues i ON i.id = r.issue_id
             WHERE r.case_id = :c ORDER BY r.id DESC",
            ['c' => $caseId]
        ));
    }

    /** The store's queue for one status, with case, patient, doctor and stock. */
    public static function queue(string $status, int $limit = 200): array
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            $status = 'pending';
        }
        return self::withItems(Database::getInstance()->fetchAll(
            "SELECT r.*, d.full_name AS doctor_name, p.full_name AS patient_name, c.case_number,
                    i.issue_number, du.full_name AS decided_by_name
             FROM erp_material_requests r
             LEFT JOIN users d ON d.id = r.doctor_id
             LEFT JOIN users du ON du.id = r.decided_by
             LEFT JOIN patients p ON p.id = r.patient_id
             LEFT JOIN cases c ON c.id = r.case_id
             LEFT JOIN erp_doctor_issues i ON i.id = r.issue_id
             WHERE r.status = :s
             ORDER BY " . ($status === 'pending' ? 'r.id ASC' : 'r.decided_at DESC, r.id DESC') . "
             LIMIT " . max(1, min(500, $limit)),
            ['s' => $status]
        ), true);
    }

    public static function countPending(): int
    {
        $row = Database::getInstance()->fetch("SELECT COUNT(*) AS n FROM erp_material_requests WHERE status = 'pending'");
        return (int)($row['n'] ?? 0);
    }

    private static function withItems(array $requests, bool $withStock = false): array
    {
        if (!$requests) {
            return [];
        }
        $db = Database::getInstance();
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $requests));
        $items = $db->fetchAll(
            "SELECT it.*, u.name AS unit" . ($withStock ? ", COALESCE(s.quantity, 0) AS stock" : '') . "
             FROM erp_material_request_items it
             JOIN erp_material_requests r ON r.id = it.request_id
             LEFT JOIN erp_products pr ON pr.id = it.product_id
             LEFT JOIN erp_units u ON u.id = pr.unit_id"
             . ($withStock ? " LEFT JOIN erp_warehouse_product_stock s ON s.product_id = it.product_id AND s.warehouse_id = r.warehouse_id" : '') . "
             WHERE it.request_id IN ($ids) ORDER BY it.id"
        );
        $by = [];
        foreach ($items as $it) {
            $by[(int)$it['request_id']][] = $it;
        }
        foreach ($requests as &$r) {
            $r['items'] = $by[(int)$r['id']] ?? [];
            $r['status_label'] = self::STATUS_LABELS[$r['status']] ?? $r['status'];
        }
        unset($r);
        return $requests;
    }

    /**
     * Approve: the store may lower a line (0 drops it). Creates the doctor's
     * materials voucher in the same transaction, so a request can never be
     * approved twice or approved without its stock leaving the warehouse.
     *
     * @param array $quantities item id => approved quantity (missing = as requested)
     */
    public static function approve(int $id, array $quantities, string $kind, int $actorId): array
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $req = $db->fetch("SELECT r.*, c.case_number FROM erp_material_requests r
                               LEFT JOIN cases c ON c.id = r.case_id WHERE r.id = :id FOR UPDATE", ['id' => $id]);
            if (!$req) {
                throw new RuntimeException('داواکاری نەدۆزرایەوە');
            }
            if ($req['status'] !== 'pending') {
                throw new RuntimeException('ئەم داواکارییە پێشتر بڕیاری لەسەر دراوە');
            }
            $items = $db->fetchAll("SELECT * FROM erp_material_request_items WHERE request_id = :id ORDER BY id", ['id' => $id]);
            $issueLines = [];
            $approved = [];
            foreach ($items as $it) {
                $qty = array_key_exists($it['id'], $quantities) ? round((float)$quantities[$it['id']], 3) : (float)$it['quantity'];
                if ($qty < 0) {
                    throw new RuntimeException('بڕ نابێت کەمتر لە سفر بێت');
                }
                $approved[(int)$it['id']] = $qty;
                if ($qty > 0) {
                    $issueLines[] = ['product_id' => (int)$it['product_id'], 'quantity' => $qty];
                }
            }
            if (!$issueLines) {
                throw new RuntimeException('هیچ بڕێک بۆ پەسەندکردن نییە — بۆ ڕەتکردنەوە دوگمەی ڕەتکردنەوە بەکاربهێنە');
            }
            $issue = ErpDoctorIssue::create([
                'doctor_id'    => (int)$req['doctor_id'],
                'warehouse_id' => (int)$req['warehouse_id'],
                'issue_date'   => date('Y-m-d'),
                'expense_kind' => $kind,
                'notes'        => 'داواکاری مەواد #' . $id . ($req['case_number'] ? ' — کەیس ' . $req['case_number'] : ''),
                'items'        => $issueLines,
            ]);
            foreach ($approved as $itemId => $qty) {
                $db->update('erp_material_request_items', ['approved_quantity' => $qty], 'id = :id', ['id' => $itemId]);
            }
            $db->update('erp_material_requests', [
                'status'     => 'approved',
                'issue_id'   => (int)$issue['id'],
                'decided_by' => $actorId,
                'decided_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
            ErpAudit::log('material_request_approve', 'MATERIAL_REQUEST', $id, '#' . $id, ['status' => 'pending'],
                ['status' => 'approved', 'issue_id' => (int)$issue['id'], 'quantities' => $approved]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        self::notifyDoctor($req, 'پەسەندکرا', 'داواکاری مەوادەکەت پەسەندکرا — وەسڵ ' . $issue['issue_number'], $actorId);
        return ['issue_id' => (int)$issue['id'], 'issue_number' => $issue['issue_number']];
    }

    public static function reject(int $id, string $reason, int $actorId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('هۆکاری ڕەتکردنەوە بنووسە');
        }
        $req = self::decide($id, 'rejected', mb_substr($reason, 0, 500), $actorId);
        self::notifyDoctor($req, 'ڕەتکرایەوە', 'داواکاری مەوادەکەت ڕەتکرایەوە: ' . $reason, $actorId);
    }

    /** The doctor takes back their own request while it is still pending. */
    public static function cancel(int $id, int $actorId, bool $isAdmin): void
    {
        $req = Database::getInstance()->fetch("SELECT doctor_id, created_by FROM erp_material_requests WHERE id = :id", ['id' => $id]);
        if (!$req) {
            throw new RuntimeException('داواکاری نەدۆزرایەوە');
        }
        if (!$isAdmin && (int)$req['doctor_id'] !== $actorId && (int)$req['created_by'] !== $actorId) {
            throw new RuntimeException('تەنها داواکەر دەتوانێت هەڵیبوەشێنێتەوە');
        }
        self::decide($id, 'cancelled', null, $actorId);
    }

    private static function decide(int $id, string $status, ?string $note, int $actorId): array
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $req = $db->fetch("SELECT r.*, c.case_number FROM erp_material_requests r
                               LEFT JOIN cases c ON c.id = r.case_id WHERE r.id = :id FOR UPDATE", ['id' => $id]);
            if (!$req) {
                throw new RuntimeException('داواکاری نەدۆزرایەوە');
            }
            if ($req['status'] !== 'pending') {
                throw new RuntimeException('ئەم داواکارییە پێشتر بڕیاری لەسەر دراوە');
            }
            $db->update('erp_material_requests', [
                'status'        => $status,
                'decision_note' => $note,
                'decided_by'    => $actorId,
                'decided_at'    => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
            ErpAudit::log('material_request_' . ($status === 'rejected' ? 'reject' : 'cancel'), 'MATERIAL_REQUEST', $id, '#' . $id,
                ['status' => 'pending'], ['status' => $status, 'note' => $note]);
            $db->commit();
            return $req;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private static function notifyDoctor(array $req, string $title, string $message, int $actorId): void
    {
        try {
            (new Notification())->notifyUsers([(int)$req['doctor_id']], 'material_request_decision',
                'داواکاری مەواد ' . $title, $message,
                ['request_id' => (int)$req['id'], 'case_id' => (int)$req['case_id']],
                APP_URL . '/cases/view.php?id=' . (int)$req['case_id'], $actorId);
        } catch (Throwable $e) {
            error_log('ErpMaterialRequest notify doctor: ' . $e->getMessage());
        }
    }
}
