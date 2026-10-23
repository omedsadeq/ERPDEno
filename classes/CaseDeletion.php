<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Folder.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/CashboxLedger.php';

/** Permanent case removal with a mandatory, transactional archive. */
class CaseDeletion {
    private $db;
    public function __construct() { $this->db = Database::getInstance(); }
    private function ident(string $name): string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new RuntimeException('Invalid schema identifier'); }
        return '`' . $name . '`';
    }
    private function json($value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Doctors may delete their own cases (setting: permission cases.delete_own). */
    public const DOCTOR_PERMISSION = 'cases.delete_own';

    public static function doctorMayDelete(): bool {
        require_once __DIR__ . '/Permission.php';
        return Permission::getInstance()->hasPermission(ROLE_DOCTOR, self::DOCTOR_PERMISSION);
    }

    /**
     * Was any money received on this case — approved or still waiting for the
     * accountant? A doctor may only delete a case that is debt alone; one with
     * money on it is the admin's to delete (CaseDeletion::delete).
     */
    public function hasReceivedMoney(int $caseId): bool {
        $checks = [
            "SELECT 1 FROM case_invoices WHERE case_id = ? AND accounting_status <> 'cancelled'
               AND (draft_received_amount > 0 OR prior_paid_amount > 0 OR advance_paid_amount > 0) LIMIT 1",
            "SELECT 1 FROM payments WHERE case_id = ? AND status IN ('approved','pending') AND amount > 0 LIMIT 1",
            "SELECT 1 FROM installment_payments ip JOIN installment_plans pl ON pl.id = ip.plan_id WHERE pl.case_id = ? AND ip.amount > 0 LIMIT 1",
        ];
        if ($this->db->tableExists('arch_work_receipts')) {
            $checks[] = "SELECT 1 FROM arch_work_receipts WHERE case_id = ? AND status IN ('approved','sent') AND paid_amount > 0 LIMIT 1";
        }
        if ($this->db->fetch("SHOW COLUMNS FROM xray_requests LIKE 'case_id'")) {
            $checks[] = "SELECT 1 FROM xray_requests WHERE case_id = ? AND payment_status = 'paid' AND final_amount > 0 LIMIT 1";
        }
        foreach ($checks as $sql) {
            if ($this->db->fetch($sql, [$caseId])) {
                return true;
            }
        }
        return false;
    }

    public function delete(int $caseId, int $patientId, string $reason): array {
        $reason = preg_replace('/^\s+|\s+$/u', '', $reason);
        if ($reason === null) { return ['success' => false, 'message' => 'هۆکاری سڕینەوە بنووسە']; }
        if ($reason === '' || mb_strlen($reason) > 2000) {
            return ['success' => false, 'message' => 'هۆکاری سڕینەوە بنووسە (زۆرترین ٢٠٠٠ پیت).'];
        }
        $auth = new Auth();
        $authUser = $auth->getCurrentUser();
        $byAdmin = $auth->isAdmin() && $authUser && $authUser['role'] === ROLE_ADMIN && !empty($authUser['is_active']);
        // A doctor, when the clinic allows it (permission cases.delete_own) —
        // his own case only, and only one that is debt alone: no money
        // received on it at all (hasReceivedMoney). Otherwise the admin's.
        $byDoctor = !$byAdmin && $authUser && $authUser['role'] === ROLE_DOCTOR && !empty($authUser['is_active'])
            && self::doctorMayDelete();
        if (!$byAdmin && !$byDoctor) {
            return ['success' => false, 'message' => 'تەنها بەڕێوەبەر دەتوانێت کەیس بسڕێتەوە'];
        }
        $pdo = $this->db->getConnection();
        if ($pdo->inTransaction()) { throw new RuntimeException('Case deletion requires its own transaction'); }
        try {
            $pdo->beginTransaction();
            $case = $this->db->fetch('SELECT c.*, pf.patient_id, pf.doctor_id AS case_doctor_id,
                p.full_name AS patient_name, u.full_name AS doctor_name
                FROM cases c JOIN patient_folders pf ON pf.id=c.folder_id
                JOIN patients p ON p.id=pf.patient_id JOIN users u ON u.id=pf.doctor_id
                WHERE c.id=? AND pf.patient_id=? FOR UPDATE', [$caseId, $patientId]);
            if (!$case) {
                throw new DomainException('کەیس نەدۆزرایەوە یان ڕێگەت پێنەدراوە');
            }
            if ($byDoctor) {
                if ((int)$case['case_doctor_id'] !== (int)$authUser['id']) {
                    throw new DomainException('تەنها کەیسی خۆت دەتوانیت بسڕیتەوە');
                }
                if ($this->hasReceivedMoney($caseId)) {
                    throw new DomainException('ئەم کەیسە پارەی وەرگیراوی تێدایە — دکتۆر تەنها کەیسێک دەسڕێتەوە کە تەنها قەرز بێت؛ ئەمە تەنها بەڕێوەبەر دەیسڕێتەوە');
                }
            }
            CashboxLedger::captureCase($caseId, (int)$authUser['id']);
            [$records, $edges] = $this->collect($caseId);
            foreach ($records['xray_requests'] ?? [] as $xr) CashboxLedger::capture('xray', (int)$xr['id'], (int)$authUser['id']);
            $financial = $this->db->fetch('SELECT * FROM v_case_financial_summary WHERE case_id=?', [$caseId]);
            $sharedBefore = $this->adjustMasterPayments($caseId, $patientId, $records);
            foreach (array_unique(array_column($records['treatment_plan_items'] ?? [], 'plan_id')) as $planId) {
                $sharedBefore['treatment_plans'][] = $this->db->fetch('SELECT * FROM treatment_plans WHERE id=? FOR UPDATE', [$planId]);
            }
            // Undo the stock movement when deleting a case-linked inventory transaction.
            foreach ($records['inventory_transactions'] ?? [] as $movement) {
                $item = $this->db->fetch('SELECT * FROM inventory_items WHERE id=? FOR UPDATE', [$movement['item_id']]);
                if (!isset($sharedBefore['inventory_items'][$item['id']])) { $sharedBefore['inventory_items'][$item['id']] = $item; }
                $delta = $movement['transaction_type'] === 'use' ? (int)$movement['quantity'] : -(int)$movement['quantity'];
                $this->db->query('UPDATE inventory_items SET current_stock=current_stock+? WHERE id=?', [$delta, $item['id']]);
            }
            $sharedBefore = array_map('array_values', $sharedBefore);
            $files = [];
            foreach ($records as $table => $rows) {
                foreach ($rows as $row) {
                    $pathColumn = !empty($row['image_path']) ? 'image_path' : (!empty($row['attachment']) ? 'attachment' : null);
                    if ($pathColumn !== null || !empty($row['s3_key'])) {
                        $file = ['path' => $pathColumn ? $row[$pathColumn] : '', 's3_key' => $row['s3_key'] ?? '', 'done' => false];
                        // A physical attachment may be shared with another record.
                        $column = !empty($file['s3_key']) ? 's3_key' : $pathColumn;
                        $ids = array_column($rows, 'id');
                        $other = $this->db->fetch('SELECT id FROM ' . $this->ident($table) . ' WHERE ' . $this->ident($column)
                            . '=? AND id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ') LIMIT 1',
                            array_merge([$row[$column]], $ids));
                        if (!$other) { $files[hash('sha256', $file['path'] . '|' . $file['s3_key'])] = $file; }
                    }
                }
            }
            $counts = array_map('count', $records);
            $archiveId = (int)$this->db->insert('deleted_cases', [
                'case_id' => $caseId, 'case_number' => $case['case_number'], 'case_name' => $case['case_name'],
                'patient_id' => $patientId, 'patient_name' => $case['patient_name'],
                'doctor_id' => $case['case_doctor_id'], 'doctor_name' => $case['doctor_name'],
                'deleted_by' => $authUser['id'], 'deleted_by_name' => $authUser['full_name'],
                'deletion_reason' => $reason,
                'snapshot' => $this->json(['case' => $case, 'financial_summary' => $financial,
                    'records' => array_map('array_values', $records), 'shared_records_before' => $sharedBefore]),
                'record_counts' => $this->json($counts), 'file_cleanup' => $this->json(array_values($files)),
                'cleanup_pending' => $files ? 1 : 0, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            // Delete children before parents, including SET NULL relations. Never disable foreign keys.
            $order = []; $visiting = []; $visited = [];
            $visit = function ($table) use (&$visit, &$order, &$visiting, &$visited, $edges, $records) {
                if (isset($visited[$table])) { return; }
                if (isset($visiting[$table])) { throw new RuntimeException('Cyclic case dependencies'); }
                $visiting[$table] = true;
                foreach ($edges as $edge) {
                    if ($edge['parent'] === $table && isset($records[$edge['child']])) { $visit($edge['child']); }
                }
                unset($visiting[$table]); $visited[$table] = true; $order[] = $table;
            };
            foreach (array_keys($records) as $table) { $visit($table); }
            foreach ($order as $table) {
                $ids = array_column($records[$table], 'id');
                $this->db->query('DELETE FROM ' . $this->ident($table) . ' WHERE id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            }
            foreach ($this->db->fetchAll('SELECT source_type,source_id FROM cashbox_sources WHERE case_id=?', [$caseId]) as $source) {
                CashboxLedger::capture($source['source_type'], (int)$source['source_id'], (int)$authUser['id'], 'سڕینەوەی کەیس: ' . mb_substr($reason,0,500));
            }
            foreach ($records['xray_requests'] ?? [] as $xr) CashboxLedger::capture('xray', (int)$xr['id'], (int)$authUser['id'], 'سڕینەوەی کەیس: ' . mb_substr($reason,0,500));
            foreach (array_unique(array_column($records['patient_advance_allocations'] ?? [], 'advance_id')) as $advanceId) {
                CashboxLedger::capture('advance', (int)$advanceId, (int)$authUser['id'], 'گەڕاندنەوەی ئامانەت دوای سڕینەوەی کەیس', 'amanat_in');
            }
            (new Folder())->updateTotals($case['folder_id']);
            if (!empty($records['treatment_plan_items'])) {
                require_once __DIR__ . '/TreatmentPlan.php';
                $planManager = new TreatmentPlan();
                foreach (array_unique(array_column($records['treatment_plan_items'], 'plan_id')) as $planId) {
                    $planManager->recomputeTotals((int)$planId);
                }
            }
            AuditLog::log('delete_case', 'cases', $caseId, $case, ['deletion_id' => $archiveId, 'reason' => $reason, 'record_counts' => $counts]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[CaseDeletion] ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof DomainException ? $e->getMessage() : 'سڕینەوە سەرکەوتوو نەبوو؛ هیچ تۆمارێک نەسڕایەوە.'];
        }
        // Files cannot participate in a DB transaction. Persist outstanding work for retry.
        try { $this->cleanupFiles($archiveId); } catch (Throwable $e) { error_log('[CaseDeletion cleanup] ' . $e->getMessage()); }
        return ['success' => true, 'deletion_id' => $archiveId];
    }

    private function collect(int $caseId): array {
        $columns = $this->db->fetchAll("SELECT c.TABLE_NAME, c.COLUMN_NAME, t.ENGINE FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
            WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'");
        $schema = []; $engines = [];
        foreach ($columns as $c) { $schema[$c['TABLE_NAME']][$c['COLUMN_NAME']] = true; $engines[$c['TABLE_NAME']] = $c['ENGINE']; }
        $excluded = function ($table) { return $table === 'deleted_cases' || strpos($table, 'cashbox_') === 0 || preg_match('/(?:_deleted|_archive)$/', $table); };
        $edges = [];
        foreach ($this->db->fetchAll('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_COLUMN_NAME=\'id\'') as $fk) {
            if (!$excluded($fk['TABLE_NAME'])) { $edges[] = ['child' => $fk['TABLE_NAME'], 'column' => $fk['COLUMN_NAME'], 'parent' => $fk['REFERENCED_TABLE_NAME']]; }
        }
        foreach ($schema as $table => $cols) {
            if (!$excluded($table) && isset($cols['case_id'])) { $edges[] = ['child' => $table, 'column' => 'case_id', 'parent' => 'cases']; }
        }
        // Legacy links without physical FK constraints; never infer ERP invoice IDs from their names.
        foreach (['case_invoices' => ['case_treatments', 'payments', 'installment_plans'], 'payments' => ['arch_work_receipts']] as $parent => $children) {
            $column = $parent === 'payments' ? 'payment_id' : 'invoice_id';
            foreach ($children as $child) {
                if (isset($schema[$child][$column])) { $edges[] = ['child' => $child, 'column' => $column, 'parent' => $parent]; }
            }
        }
        $records = ['cases' => [$caseId => $this->db->fetch('SELECT * FROM cases WHERE id=? FOR UPDATE', [$caseId])]];
        do {
            $changed = false;
            foreach ($edges as $edge) {
                if (empty($records[$edge['parent']])) { continue; }
                $table = $edge['child'];
                if (!isset($schema[$table]['id']) || strtoupper($engines[$table]) !== 'INNODB') {
                    throw new RuntimeException('Unsupported case dependency: ' . $table);
                }
                $ids = array_keys($records[$edge['parent']]);
                $rows = $this->db->fetchAll('SELECT * FROM ' . $this->ident($table) . ' WHERE ' . $this->ident($edge['column'])
                    . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE', $ids);
                foreach ($rows as $row) {
                    if (isset($row['case_id']) && $row['case_id'] !== null && (int)$row['case_id'] !== $caseId) {
                        throw new RuntimeException('Dependency belongs to another case: ' . $table);
                    }
                    if (!isset($records[$table][$row['id']])) { $records[$table][$row['id']] = $row; $changed = true; }
                }
            }
        } while ($changed);
        // Notifications have JSON links rather than foreign keys.
        if (isset($schema['notifications']['data'])) {
            foreach ($this->db->fetchAll("SELECT * FROM notifications WHERE JSON_VALID(data)
                AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.case_id'))=? FOR UPDATE", [(string)$caseId]) as $row) {
                $records['notifications'][$row['id']] = $row;
            }
        }
        return [$records, $edges];
    }

    private function adjustMasterPayments(int $caseId, int $patientId, array $records): array {
        $before = [];
        $xrayIds = array_keys($records['xray_requests'] ?? []);
        foreach ($this->db->fetchAll('SELECT * FROM patient_debt_payments WHERE patient_id=? FOR UPDATE', [$patientId]) as $master) {
            $allocations = json_decode($master['allocations'] ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?: [];
            $remaining = []; $removed = 0;
            foreach ($allocations as $allocation) {
                $matches = ($allocation['item_type'] ?? '') === 'case' && (int)($allocation['item_id'] ?? 0) === $caseId;
                $matches = $matches || (($allocation['item_type'] ?? '') === 'xray' && in_array((int)($allocation['item_id'] ?? 0), $xrayIds, true));
                if ($matches) { $removed += (float)$allocation['amount']; } else { $remaining[] = $allocation; }
            }
            if ($removed <= 0) { continue; }
            $before['patient_debt_payments'][] = $master;
            // This is a patient-wide receipt; retain the part assigned to other cases and patient credit.
            $this->db->update('patient_debt_payments', ['amount' => max(0, (float)$master['amount'] - $removed),
                'allocations' => $this->json($remaining)], 'id=?', [$master['id']]);
            if (!$remaining && (float)$master['credit_amount'] === 0) {
                $this->db->delete('patient_debt_payments', 'id=?', [$master['id']]);
            }
        }
        return $before;
    }

    public function cleanupFiles(int $archiveId): void {
        $auth = new Auth(); $user = $auth->getCurrentUser();
        if (!$auth->isAdmin() || !$user || $user['role'] !== ROLE_ADMIN || empty($user['is_active'])) {
            throw new DomainException('تەنها بەڕێوەبەر دەتوانێت فایلەکانی کەیس بسڕێتەوە');
        }
        $row = $this->db->fetch('SELECT * FROM deleted_cases WHERE id=?', [$archiveId]);
        if (!$row) {
            throw new DomainException('ڕێگەت پێنەدراوە');
        }
        $files = json_decode($row['file_cleanup'], true, 512, JSON_THROW_ON_ERROR);
        $pending = false;
        foreach ($files as &$file) {
            if ($file['done']) { continue; }
            try {
                if ($file['s3_key'] !== '') {
                    require_once __DIR__ . '/S3Uploader.php';
                    $result = S3Uploader::getInstance()->deleteFile($file['s3_key']);
                    if (empty($result['success'])) { throw new RuntimeException($result['message'] ?? 'S3 delete failed'); }
                } elseif (preg_match('#^https?://#i', $file['path'])) {
                    throw new RuntimeException('Remote attachment has no storage key');
                } elseif ($file['path'] !== '') {
                    $root = realpath(APP_ROOT . '/uploads');
                    $path = realpath(APP_ROOT . '/' . ltrim($file['path'], '/'));
                    if ($path !== false) {
                        if (!$root || stripos(str_replace('\\', '/', $path), str_replace('\\', '/', $root) . '/') !== 0 || !is_file($path)) {
                            throw new RuntimeException('Attachment path is outside uploads');
                        }
                        if (!unlink($path)) { throw new RuntimeException('File delete failed'); }
                    }
                }
                $file['done'] = true; unset($file['error']);
            } catch (Throwable $e) { $pending = true; $file['error'] = $e->getMessage(); }
        }
        unset($file);
        $this->db->update('deleted_cases', ['file_cleanup' => $this->json($files), 'cleanup_pending' => $pending ? 1 : 0], 'id=?', [$archiveId]);
    }
}
