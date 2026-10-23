<?php
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/CaseManager.php';
require_once __DIR__ . '/CashboxLedger.php';

/** Remove one invoice and its dependent records, without removing its case. */
class InvoiceDeletion {
    private $db;
    public function __construct() { $this->db = Database::getInstance(); }
    private function ident(string $name): string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new RuntimeException('Invalid schema identifier'); }
        return '`' . $name . '`';
    }
    private function json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }

    public function delete(int $caseId, int $invoiceId, string $reason): array {
        $auth = new Auth(); $user = $auth->getCurrentUser();
        if (!$auth->isAdmin() || !$user || $user['role'] !== ROLE_ADMIN || empty($user['is_active'])) {
            return ['success' => false, 'message' => 'تەنها بەڕێوەبەر دەتوانێت پسووڵە بسڕێتەوە'];
        }
        $reason = preg_replace('/^\s+|\s+$/u', '', $reason);
        if ($reason === null || $reason === '' || mb_strlen($reason) > 2000) {
            return ['success' => false, 'message' => 'هۆکاری سڕینەوە بنووسە (زۆرترین ٢٠٠٠ پیت)'];
        }
        $pdo = $this->db->getConnection();
        if ($pdo->inTransaction()) { throw new RuntimeException('Invoice deletion requires its own transaction'); }
        try {
            $pdo->beginTransaction();
            $case = $this->db->fetch('SELECT c.*, pf.patient_id FROM cases c JOIN patient_folders pf ON pf.id=c.folder_id WHERE c.id=? FOR UPDATE', [$caseId]);
            $invoice = $this->db->fetch('SELECT * FROM case_invoices WHERE id=? AND case_id=? FOR UPDATE', [$invoiceId, $caseId]);
            if (!$case || !$invoice) { throw new DomainException('پسووڵە نەدۆزرایەوە لەم کەیسە'); }
            $discountReceipt = $this->db->fetch("SELECT p.id FROM payments p
                WHERE p.case_id=? AND p.discount_amount>0 AND p.status IN ('approved','pending')
                  AND (EXISTS(SELECT 1 FROM payment_allocations pa WHERE pa.payment_id=p.id AND pa.invoice_id=?)
                    OR EXISTS(SELECT 1 FROM payment_discount_allocations da WHERE da.payment_id=p.id AND da.invoice_id=?)) LIMIT 1",
                [$caseId, $invoiceId, $invoiceId]);
            if ($discountReceipt) { throw new DomainException('ئەم وەسڵە بە قەبزی داشکاندن بەستراوە؛ سەرەتا قەبزەکە بگەڕێنەوە یان داواکارییەکە ڕەت بکەرەوە'); }
            CashboxLedger::captureCase($caseId, (int)$user['id']);
            $plans = $this->db->fetchAll('SELECT * FROM installment_plans WHERE invoice_id=? FOR UPDATE', [$invoiceId]);
            $planPaymentIds = [];
            foreach ($plans as $plan) {
                foreach (['installment_schedule', 'installment_payments'] as $table) {
                    foreach ($this->db->fetchAll("SELECT * FROM $table WHERE plan_id=? FOR UPDATE", [$plan['id']]) as $row) {
                        if (!empty($row['payment_id'])) { $planPaymentIds[(int)$row['payment_id']] = true; }
                    }
                }
            }
            $seeds = ['case_invoices' => [$invoiceId => $invoice]];
            $sharedBefore = []; $paymentUpdates = []; $removedByMaster = [];
            foreach ($this->db->fetchAll('SELECT * FROM payments WHERE case_id=? FOR UPDATE', [$caseId]) as $payment) {
                $allocations = $this->db->fetchAll('SELECT * FROM payment_allocations WHERE payment_id=? FOR UPDATE', [$payment['id']]);
                $targetAmount = 0; $remainingAmount = 0; $remainingInvoice = null;
                foreach ($allocations as $allocation) {
                    if ((int)$allocation['invoice_id'] === $invoiceId) { $targetAmount += (float)$allocation['allocated_amount']; }
                    else { $remainingAmount += (float)$allocation['allocated_amount']; $remainingInvoice = (int)$allocation['invoice_id']; }
                }
                $originMatches = (int)($payment['invoice_id'] ?? 0) === $invoiceId;
                $planMatches = isset($planPaymentIds[(int)$payment['id']]);
                if (!$originMatches && !$planMatches && $targetAmount <= 0) { continue; }
                // Origin-only drafts/rejections have no allocations. A split receipt retains the other invoices' share.
                $removed = ($originMatches || $planMatches) ? (float)$payment['amount'] - $remainingAmount : $targetAmount;
                $left = round((float)$payment['amount'] - $removed, 2);
                if ($removed < -0.009 || $left < -0.009) { throw new RuntimeException('Inconsistent payment allocations'); }
                if ($left <= 0.009) { $seeds['payments'][$payment['id']] = $payment; }
                else {
                    if (($payment['payment_method'] ?? '') === 'advance' && $payment['status'] === 'approved') {
                        throw new DomainException('ئەم قەبزە لە ئامانەتەوە چەند وەسڵێکی داوە؛ سەرەتا قەبزەکە هەڵبوەشێنەوە، پاشان وەسڵەکە بسڕەوە');
                    }
                    $sharedBefore['payments'][] = $payment;
                    $changes = ['amount' => $left];
                    if ($originMatches) { $changes['invoice_id'] = $remainingInvoice; }
                    $paymentUpdates[$payment['id']] = $changes;
                }
                if (!empty($payment['master_payment_id']) && $removed > 0) {
                    $mid = (int)$payment['master_payment_id'];
                    $removedByMaster[$mid] = ($removedByMaster[$mid] ?? 0) + $removed;
                }
            }
            [$records, $edges] = $this->collect($seeds, $caseId, $invoiceId, $invoice['invoice_no']);
            // Plan-less legacy allocations can carry another case's payment: refuse collateral deletion.
            foreach ($records['payment_allocations'] ?? [] as $allocation) {
                $payment = $this->db->fetch('SELECT case_id FROM payments WHERE id=?', [$allocation['payment_id']]);
                if (!$payment || (int)$payment['case_id'] !== $caseId) { throw new RuntimeException('Payment belongs to another case'); }
            }
            $masterUpdates = [];
            foreach ($removedByMaster as $masterId => $removed) {
                $master = $this->db->fetch('SELECT * FROM patient_debt_payments WHERE id=? AND patient_id=? FOR UPDATE', [$masterId, $case['patient_id']]);
                if (!$master) { throw new RuntimeException('Shared receipt missing'); }
                $sharedBefore['patient_debt_payments'][] = $master;
                $allocations = json_decode($master['allocations'] ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?: [];
                $toRemove = $removed; $remaining = [];
                foreach ($allocations as $allocation) {
                    if (($allocation['item_type'] ?? '') === 'case' && (int)($allocation['item_id'] ?? 0) === $caseId) {
                        $part = min($toRemove, (float)$allocation['amount']);
                        $allocation['amount'] = round((float)$allocation['amount'] - $part, 2); $toRemove -= $part;
                    }
                    if ((float)$allocation['amount'] > 0.009) { $remaining[] = $allocation; }
                }
                if ($toRemove > 0.009 || (float)$master['amount'] < $removed - 0.009) { throw new RuntimeException('Inconsistent shared receipt'); }
                $masterUpdates[$masterId] = !$remaining && (float)$master['credit_amount'] === 0 ? null
                    : ['amount' => round((float)$master['amount'] - $removed, 2), 'allocations' => $this->json($remaining)];
            }
            foreach (array_unique(array_column($records['treatment_plan_items'] ?? [], 'plan_id')) as $planId) {
                $sharedBefore['treatment_plans'][] = $this->db->fetch('SELECT * FROM treatment_plans WHERE id=? FOR UPDATE', [$planId]);
            }
            // The log is mandatory and uses the same transaction as the deletion.
            $logId = (int)$this->db->insert('activity_log', [
                'user_id' => $user['id'], 'action' => 'delete_invoice', 'table_name' => 'case_invoices', 'record_id' => $invoiceId,
                'old_values' => $this->json(['case_id' => $caseId, 'invoice' => $invoice, 'records' => array_map('array_values', $records), 'shared_records_before' => $sharedBefore]),
                'new_values' => $this->json(['case_id' => $caseId, 'reason' => $reason, 'deleted_by_name' => $user['full_name'],
                    'record_counts' => array_map('count', $records), 'shared_payment_changes' => $paymentUpdates]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            ]);
            foreach ($paymentUpdates as $id => $changes) { $this->db->update('payments', $changes, 'id=?', [$id]); }
            $order = []; $visiting = []; $visited = [];
            $visit = function ($table) use (&$visit, &$order, &$visiting, &$visited, $records, $edges) {
                if (isset($visited[$table])) { return; }
                if (isset($visiting[$table])) { throw new RuntimeException('Cyclic invoice dependencies'); }
                $visiting[$table] = true;
                foreach ($edges as $edge) {
                    if ($edge['parent'] === $table && isset($records[$edge['child']])) { $visit($edge['child']); }
                }
                unset($visiting[$table]); $visited[$table] = true; $order[] = $table;
            };
            foreach (array_keys($records) as $table) { $visit($table); }
            foreach ($order as $table) {
                $ids = array_keys($records[$table]);
                $this->db->query('DELETE FROM ' . $this->ident($table) . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            }
            foreach ($masterUpdates as $id => $changes) {
                if ($changes === null) { $this->db->delete('patient_debt_payments', 'id=?', [$id]); }
                else { $this->db->update('patient_debt_payments', $changes, 'id=?', [$id]); }
            }
            if (!empty($records['treatment_plan_items'])) {
                require_once __DIR__ . '/TreatmentPlan.php';
                foreach (array_unique(array_column($records['treatment_plan_items'], 'plan_id')) as $planId) {
                    (new TreatmentPlan())->recomputeTotals((int)$planId);
                }
            }
            (new CaseManager())->updateCaseTotals($caseId);
            foreach (array_unique(array_column($records['patient_advance_allocations'] ?? [], 'advance_id')) as $advanceId) {
                CashboxLedger::capture('advance', (int)$advanceId, (int)$user['id'], 'گەڕاندنەوەی ئامانەت دوای سڕینەوەی وەسڵ', 'amanat_in');
            }
            foreach ($this->db->fetchAll('SELECT source_type,source_id FROM cashbox_sources WHERE case_id=?', [$caseId]) as $source) {
                CashboxLedger::capture($source['source_type'], (int)$source['source_id'], (int)$user['id'], 'سڕینەوەی وەسڵ: ' . mb_substr($reason,0,500));
            }
            $pdo->commit();
            return ['success' => true, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[InvoiceDeletion] ' . $e->getMessage());
            return ['success' => false, 'message' => $e instanceof DomainException ? $e->getMessage() : 'سڕینەوە سەرکەوتوو نەبوو؛ هیچ تۆمارێک نەسڕایەوە'];
        }
    }

    private function collect(array $records, int $caseId, int $invoiceId, string $invoiceNo): array {
        $schema = []; $engines = [];
        foreach ($this->db->fetchAll("SELECT c.TABLE_NAME, c.COLUMN_NAME, t.ENGINE FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
            WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'") as $col) {
            $schema[$col['TABLE_NAME']][$col['COLUMN_NAME']] = true; $engines[$col['TABLE_NAME']] = $col['ENGINE'];
        }
        $edges = [];
        foreach ($this->db->fetchAll("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_COLUMN_NAME='id'") as $fk) {
            if ($fk['TABLE_NAME'] !== 'deleted_cases' && !preg_match('/(?:_deleted|_archive)$/', $fk['TABLE_NAME'])) {
                $edges[] = ['child' => $fk['TABLE_NAME'], 'column' => $fk['COLUMN_NAME'], 'parent' => $fk['REFERENCED_TABLE_NAME']];
            }
        }
        foreach (['case_treatments', 'installment_plans', 'payments'] as $table) {
            if (isset($schema[$table]['invoice_id'])) { $edges[] = ['child' => $table, 'column' => 'invoice_id', 'parent' => 'case_invoices']; }
        }
        foreach (['arch_work_receipts' => ['payment_id', 'payments'], 'treatment_plan_items' => ['transferred_treatment_id', 'case_treatments']] as $table => $link) {
            if (isset($schema[$table][$link[0]])) { $edges[] = ['child' => $table, 'column' => $link[0], 'parent' => $link[1]]; }
        }
        do {
            $changed = false;
            foreach ($edges as $edge) {
                if (empty($records[$edge['parent']]) || $edge['child'] === 'payments') { continue; }
                $table = $edge['child'];
                if (!isset($schema[$table]['id']) || strtoupper($engines[$table]) !== 'INNODB') { throw new RuntimeException('Unsupported invoice dependency: ' . $table); }
                $ids = array_keys($records[$edge['parent']]);
                foreach ($this->db->fetchAll('SELECT * FROM ' . $this->ident($table) . ' WHERE ' . $this->ident($edge['column'])
                    . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE', $ids) as $row) {
                    if (isset($row['case_id']) && (int)$row['case_id'] !== $caseId) { throw new RuntimeException('Dependency belongs to another case'); }
                    if (!empty($row['invoice_id']) && (int)$row['invoice_id'] !== $invoiceId) { throw new RuntimeException('Dependency belongs to another invoice'); }
                    if (in_array($table, ['installment_schedule', 'installment_payments'], true)) {
                        $plan = $this->db->fetch('SELECT invoice_id FROM installment_plans WHERE id=?', [$row['plan_id']]);
                        if (!$plan || (int)$plan['invoice_id'] !== $invoiceId) { throw new RuntimeException('Installment belongs to another invoice'); }
                    }
                    if (!isset($records[$table][$row['id']])) { $records[$table][$row['id']] = $row; $changed = true; }
                }
            }
        } while ($changed);
        if (isset($schema['notifications']['data'])) {
            foreach ($this->db->fetchAll("SELECT * FROM notifications WHERE JSON_VALID(data)
                AND (JSON_UNQUOTE(JSON_EXTRACT(data, '$.invoice_id'))=?
                    OR (JSON_UNQUOTE(JSON_EXTRACT(data, '$.case_id'))=? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.invoice_no'))=?)) FOR UPDATE",
                [(string)$invoiceId, (string)$caseId, $invoiceNo]) as $row) { $records['notifications'][$row['id']] = $row; }
        }
        return [$records, $edges];
    }
}
