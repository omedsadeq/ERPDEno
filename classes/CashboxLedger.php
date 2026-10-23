<?php
/**
 * Cash custody journal. Patient debt/revenue are never written here.
 * Source changes and journal changes share the caller's transaction/savepoint.
 * Amounts are calculated in integer cents; journal entries are append-only.
 *
 * دابەشکردنی ڕاستەوخۆ (setting cashbox_split_enabled): a receipt booked into a
 * doctor's box is split at booking — his percentage there, the rest straight
 * into قاسەی لێزان — and his percentage of a lab/store expense moves from his
 * box to قاسەی لێزان. Each cashbox_sources row keeps the percentage it was
 * split at; a row with booked_doctor NULL is unsplit (x-ray, or booked before
 * the switch — openingSplit() converts those once the switch is on).
 *
 * قاسەی ئامانەت (AMANAT): a قەرزی کۆن counter receipt (patient_advances) is not
 * any doctor's money when it is taken — it is booked here, on the patient's
 * name. When the accountant moves it onto a doctor's request it becomes a
 * payment with method 'advance': that payment books into the doctor's box
 * (split like any receipt) and the receipt's row here shrinks by the same
 * amount, so the cash is moved, never created. Receipts booked into a doctor's
 * box before this rule are moved here once (rehomeAdvance()).
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Permission.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/Settings.php';

class CashboxLedger
{
    public const START = '2026-10-01 00:00:00';
    /** قاسەی لێزان — the centre's own cashbox; income settlements fill it. */
    public const CENTER = 'center';
    /** قاسەی ئامانەت — counter receipts held for patients until they are applied. */
    public const AMANAT = 'amanat';
    /** Journal kinds a settlement writes (IncomeSettlement). */
    public const SETTLEMENT_KINDS = ['transfer_out', 'transfer_in', 'payout'];
    /** Switch for the per-receipt split (admin/settings.php?tab=access). */
    public const SPLIT_SETTING = 'cashbox_split_enabled';
    private $db;
    private const TABLES = ['invoice'=>'case_invoices', 'payment'=>'payments', 'advance'=>'patient_advances', 'installment'=>'installment_payments', 'xray'=>'xray_requests', 'lab'=>'expenses_lab', 'store'=>'expenses_store'];
    /** Lab/store costs: only the doctor's percentage of them moves, box → قاسەی لێزان. */
    private const EXPENSE_TABLES = ['lab'=>'expenses_lab', 'store'=>'expenses_store'];

    public function __construct() { $this->db = Database::getInstance(); }

    public static function cents($value): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException('بڕی پارە نادروستە');
        $value = trim((string)$value);
        if (!preg_match('/^(-?)([0-9]{1,15})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            throw new InvalidArgumentException('بڕی پارە نادروستە؛ تەنها ژمارە و تا دوو ژمارە دوای خاڵ بنووسە');
        }
        $n = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$n : $n;
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function money($value): string
    {
        $c = self::cents($value);
        return ($c < 0 ? '−' : '') . number_format(intdiv(abs($c), 100)) . (abs($c) % 100 ? '.' . str_pad((string)(abs($c) % 100), 2, '0', STR_PAD_LEFT) : '');
    }

    public function authorize(int $actorId, string $key, bool $write = false): array
    {
        $actor = $this->db->fetch('SELECT id,role,is_active FROM users WHERE id=?', [$actorId]);
        if (!$actor || !$actor['is_active'] || !Permission::getInstance()->hasPermission($actor['role'], 'page.cashboxes')) {
            throw new DomainException('دەسەڵاتی بینینی سندوقت نییە');
        }
        $own = $actor['role'] === 'doctor' ? 'doctor:' . $actorId : ($actor['role'] === 'xray_department' ? 'xray' : '');
        if ($actor['role'] !== 'admin' && ($own === '' || $key !== $own)) {
            throw new DomainException('تەنها سندوقی خۆت دەتوانیت ببینیت');
        }
        // Doctors manage their own cash; the X-ray staff can inspect theirs.
        if ($write && !in_array($actor['role'], ['admin','doctor'], true)) {
            throw new DomainException('دانان و ڕاکێشان لەم سندوقە تەنها بۆ ئەدمینە');
        }
        $this->ensureAccount($key);
        return $actor;
    }

    private function ensureAccount(string $key): void
    {
        if ($key === 'xray') {
            $doctorId = null; $type = 'xray';
        } elseif ($key === self::CENTER) {
            $doctorId = null; $type = 'center';
        } elseif ($key === self::AMANAT) {
            $doctorId = null; $type = 'amanat';
        } elseif (preg_match('/^doctor:([1-9][0-9]*)$/D', $key, $m)) {
            $doctorId = (int)$m[1]; $type = 'doctor';
            if (!$this->db->fetch("SELECT id FROM users WHERE id=? AND role='doctor'", [$doctorId])) {
                throw new DomainException('دکتۆر نەدۆزرایەوە');
            }
        } else {
            throw new DomainException('سندوق نەدۆزرایەوە');
        }
        $this->db->query('INSERT IGNORE INTO cashbox_accounts (account_key,owner_type,doctor_id) VALUES (?,?,?)', [$key,$type,$doctorId]);
    }

    /**
     * Internal booking hook: the business services have already authorized the actor.
     * $kind names the journal entry a change writes (e.g. 'amanat_out' when
     * ئامانەت is moved onto a payment) instead of the generic receipt/refund.
     */
    public static function capture(string $type, int $id, ?int $actorId = null, string $reason = '', ?string $kind = null): void
    {
        (new self())->syncSource($type, $id, $actorId, $reason, $kind);
    }

    private function eligibleDate(string $date): bool
    {
        return $date >= self::START && $date <= date('Y-m-d H:i:s');
    }

    public static function splitEnabled(): bool
    {
        return Settings::getBool(self::SPLIT_SETTING, false);
    }

    /**
     * $cents × a percentage in hundredths (80% → 8000), rounded half away from
     * zero to the cent — the same as SQL ROUND(x, 2), which sourceIds() uses.
     */
    public static function shareOf(int $cents, int $rate100): int
    {
        $share = intdiv(abs($cents) * $rate100 + 5000, 10000);
        return $cents < 0 ? -$share : $share;
    }

    /** 8000 → "80", 1250 → "12.5". */
    private static function percentLabel(int $rate100): string
    {
        return rtrim(rtrim(number_format($rate100 / 100, 2, '.', ''), '0'), '.');
    }

    /** The doctor's percentage in hundredths, or null when none is set (nothing is split). */
    private function doctorRate100(string $key): ?int
    {
        if (!preg_match('/^doctor:([1-9][0-9]*)$/D', $key, $m)) return null;
        $rate = (float)($this->db->fetch('SELECT commission_rate FROM users WHERE id=?', [(int)$m[1]])['commission_rate'] ?? 0);
        return $rate > 0 ? min(10000, (int)round($rate * 100)) : null;
    }

    private function doctorName(string $key): string
    {
        $id = (int)substr($key, 7);
        return (string)($this->db->fetch('SELECT full_name FROM users WHERE id=?', [$id])['full_name'] ?? $key);
    }

    /**
     * Switching the split on: everything a doctor's box received before it is
     * still 100% there. Give each of those rows his percentage as it is now and
     * move the centre's part to قاسەی لێزان in ONE transfer, dated now — the
     * journal is append-only, so nothing booked earlier is rewritten.
     */
    private function openingSplit(string $key): void
    {
        $this->db->beginTransaction();
        try {
            $this->ensureAccount(self::CENTER);
            $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=? FOR UPDATE', [$key]);
            // ئامانەت rows are never split: rehomeAdvance() moves them to قاسەی ئامانەت whole.
            $rows = $this->db->fetchAll("SELECT source_type, source_id, booked_amount FROM cashbox_sources WHERE account_key=? AND booked_doctor IS NULL AND source_type<>'advance' FOR UPDATE", [$key]);
            if (!$rows) { $this->db->commit(); return; }
            $rate = $this->doctorRate100($key);
            $toCenter = 0;
            foreach ($rows as $row) {
                $total = self::cents($row['booked_amount']);
                $doctorPart = $rate === null ? $total : self::shareOf($total, $rate);
                $toCenter += $total - $doctorPart;
                $this->db->update('cashbox_sources', [
                    'split_rate' => $rate === null ? null : number_format($rate / 100, 2, '.', ''),
                    'booked_doctor' => self::decimal($doctorPart),
                    'booked_center' => self::decimal($total - $doctorPart),
                ], 'source_type=? AND source_id=?', [$row['source_type'], (int)$row['source_id']]);
            }
            if ($toCenter !== 0) {
                $pct = self::percentLabel(10000 - (int)$rate);
                $name = $this->doctorName($key);
                $ref = 'SPLIT-' . date('Ymd');
                $now = date('Y-m-d H:i:s');
                $this->append($key, -$toCenter, 'transfer_out', 'پشکی لێزان (' . $pct . '%) لە پارەی پێش دابەشکردنی ڕاستەوخۆ — ' . count($rows) . ' وەسڵ', null, $now, ['reference_no' => $ref]);
                $this->append(self::CENTER, $toCenter, 'transfer_in', 'پشکی لێزان (' . $pct . '%) لە سندوقی ' . $name . ' — پارەی پێش دابەشکردنی ڕاستەوخۆ', null, $now, ['reference_no' => $ref]);
            }
            AuditLog::log('cashbox_opening_split', 'cashbox_accounts', null, null, [
                'account' => $key, 'doctor_rate' => $rate === null ? null : $rate / 100,
                'sources' => count($rows), 'moved_to_center' => self::decimal($toCenter),
            ], null);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    /** A lab/store cost's first booking: the doctor's percentage of it, on the cost's date. */
    private function expenseEvents(array $s, ?int $rate): array
    {
        if ($rate === null || !(int)$s['amount']) return [];
        $share = self::shareOf((int)$s['amount'], $rate);
        $label = ($s['type'] === 'lab' ? 'خەرجی تاقیگە ' : 'خەرجی کۆگا ') . $s['reference'];
        return $share ? [[$share, $s['date'], $s['actor'], $label]] : [];
    }

    private function source(string $type, int $id): ?array
    {
        $table = self::TABLES[$type] ?? null;
        if (!$table) throw new InvalidArgumentException('Unknown cash source');
        $r = $this->db->fetch("SELECT * FROM {$table} WHERE id=? FOR UPDATE", [$id]);
        if (!$r) return null;
        $caseId = (int)($r['case_id'] ?? 0);
        if ($type === 'installment') {
            $plan = $this->db->fetch('SELECT case_id FROM installment_plans WHERE id=?', [(int)$r['plan_id']]);
            $caseId = (int)($plan['case_id'] ?? 0);
        }
        $owner = $caseId ? $this->db->fetch('SELECT pf.doctor_id FROM cases c JOIN patient_folders pf ON pf.id=c.folder_id WHERE c.id=?', [$caseId]) : null;
        // A row's own doctor_id counts only when it names a doctor (an invoice
        // tab opened by an admin used to carry the admin) — else the case's doctor.
        $own = !empty($r['doctor_id']) && $this->db->fetch("SELECT id FROM users WHERE id=? AND role='doctor'", [(int)$r['doctor_id']]);
        $doc = (int)($own ? $r['doctor_id'] : ($owner['doctor_id'] ?? 0));
        $s = ['type'=>$type,'id'=>$id,'key'=>$type === 'xray' ? 'xray' : 'doctor:' . $doc,'case_id'=>$caseId ?: null,'raw'=>$r,'amount'=>0,'history'=>0,'active'=>false,'actor'=>(int)($r['approved_by'] ?? $r['received_by'] ?? $r['accountant_id'] ?? 0),'date'=>'','reference'=>''];
        switch ($type) {
            case 'invoice':
                $s['date'] = $r['work_date'] . ' 00:00:00';
                $s['reference'] = $r['invoice_no'];
                $s['history'] = min(max(0,self::cents($r['draft_received_amount'])), max(0,self::cents($r['total_amount'])-self::cents($r['discount_amount'])));
                $s['active'] = $r['accounting_status'] === 'approved';
                if ($r['accounting_status'] === 'cancelled') {
                    $log = $this->db->fetch("SELECT old_values,created_at FROM activity_log WHERE table_name='case_invoices' AND record_id=? AND action='invoice_deleted_reversed' ORDER BY id DESC LIMIT 1", [$id]);
                    $old = json_decode($log['old_values'] ?? '', true);
                    if (!$old || ($old['accounting_status'] ?? '') !== 'approved') $s['history'] = 0;
                    $s['refund_at'] = $log['created_at'] ?? null;
                } elseif (!$s['active']) { $s['history'] = 0; }
                break;
            case 'payment':
                $s['date'] = strlen($r['payment_date']) === 10 ? $r['payment_date'] . ' 00:00:00' : $r['payment_date'];
                $s['reference'] = $r['payment_number'];
                // 'advance' = cash moved in from قاسەی ئامانەت, so it is cash in this box too.
                $s['history'] = in_array($r['payment_method'], ['cash','advance'], true) && in_array($r['status'], ['approved','refunded'], true) ? self::cents($r['amount']) : 0;
                if ($r['payment_method'] === 'advance') {
                    // Card/transfer custody is income on approval too, but it
                    // must not manufacture physical cash in a doctor's drawer.
                    $cash = $this->db->fetch("SELECT COALESCE(SUM(al.amount),0) amount FROM patient_advance_allocations al JOIN patient_advances a ON a.id=al.advance_id WHERE al.payment_id=? AND a.payment_method='cash'", [$id]);
                    $s['history'] = in_array($r['status'], ['approved','refunded'], true) ? self::cents($r['advance_cash_amount'] ?? $cash['amount']) : 0;
                }
                $s['active'] = $r['status'] === 'approved';
                $s['refund_at'] = $r['reversed_at'] ?? null;
                break;
            case 'advance':
                // Held in قاسەی ئامانەت until applied: what is left of it here is
                // the cash taken, minus what was handed back, minus what has
                // been moved onto a payment (that part is in a doctor's box now).
                $s['key'] = self::AMANAT;
                $s['case_id'] = null;
                $s['date'] = $r['received_at']; $s['reference'] = $r['advance_no'];
                $s['history'] = $r['payment_method'] === 'cash' ? self::cents($r['amount']) : 0;
                $s['active'] = true;
                $used = (string)($this->db->fetch('SELECT COALESCE(SUM(amount),0) used FROM patient_advance_allocations WHERE advance_id=? AND reversed_at IS NULL', [$id])['used'] ?? '0');
                $s['refund'] = $r['payment_method'] === 'cash' ? self::cents($r['refunded_amount']) + self::cents($used) : 0;
                $s['refund_at'] = $r['refunded_at'] ?? null;
                break;
            case 'installment':
                $s['date'] = $r['paid_at']; $s['reference'] = 'IP-' . $id;
                $s['history'] = empty($r['payment_id']) && $r['payment_method'] === 'cash' ? self::cents($r['amount']) : 0;
                $s['active'] = true;
                break;
            case 'lab':
            case 'store':
                // Charged to the doctor named on the cost, never to the case's
                // folder doctor — the same rule the daily summary reads them by.
                $s['key'] = 'doctor:' . (int)($r['doctor_id'] ?? 0);
                $s['date'] = $r['expense_date'] . ' 00:00:00';
                $s['reference'] = ($type === 'lab' ? 'LAB-' : 'STR-') . $id;
                $s['history'] = max(0, self::cents($r['amount']));
                $s['active'] = true;
                $s['actor'] = (int)($r['created_by'] ?? 0);
                break;
            case 'xray':
                $s['date'] = $r['paid_at'] ?: $r['created_at']; $s['reference'] = $r['request_number'];
                $s['cash_events'] = [];
                $debt = $this->db->fetch('SELECT id FROM patient_xray_debts WHERE request_id=?', [$id]);
                if ($r['status'] === 'cancelled' || $r['request_status'] === 'cancelled') {
                    // A cancellation is booked as a reversal, never as debt cash.
                } elseif ($debt) {
                    foreach ($this->db->fetchAll("SELECT id,amount,created_at,received_by FROM xray_debt_payments WHERE debt_id=? AND payment_method='cash' ORDER BY created_at,id", [(int)$debt['id']]) as $p) {
                        if ($this->eligibleDate($p['created_at'])) $s['cash_events'][] = [self::cents($p['amount']),$p['created_at'],(int)$p['received_by'],'نەقدی قەرزی ئێکسڕەی #' . $p['id']];
                    }
                } elseif ($r['payment_status'] === 'paid' && !in_array($r['request_status'], ['cancelled','pending_accountant','returned_to_accountant'], true) && $r['status'] !== 'cancelled' && $this->eligibleDate($s['date'])) {
                    $s['cash_events'][] = [self::cents($r['final_amount']),$s['date'],$s['actor'],'نەقدی وەسڵی ئێکسڕەی'];
                }
                $s['amount'] = array_sum(array_column($s['cash_events'], 0));
                $s['history'] = $s['amount']; $s['active'] = true;
                return $s;
        }
        if (!$this->eligibleDate($s['date'])) $s['history'] = 0;
        $s['amount'] = $s['active'] ? max(0,$s['history'] - ($s['refund'] ?? 0)) : 0;
        return $s;
    }

    /** Import the stored receipt date and any recorded edit/refund trail once. */
    private function initialEvents(array $s): array
    {
        if ($s['type'] === 'xray') return $s['cash_events'];
        if (!$this->eligibleDate($s['date'])) return [];
        if ($s['type'] === 'payment' && (!in_array($s['raw']['payment_method'], ['cash','advance'], true) || !in_array($s['raw']['status'],['approved','refunded'],true))) return [];
        if ($s['type'] === 'invoice' && !in_array($s['raw']['accounting_status'],['approved','cancelled'],true)) return [];
        $edits = []; $field = $s['type'] === 'invoice' ? 'draft_received_amount' : 'amount';
        $table = self::TABLES[$s['type']];
        if (in_array($s['type'], ['invoice','payment'], true)) {
            $action = $s['type'] === 'invoice' ? 'invoice_approved_edited' : 'payment_approved_edited';
            foreach ($this->db->fetchAll('SELECT old_values,new_values,created_at,user_id FROM activity_log WHERE table_name=? AND record_id=? AND action=? ORDER BY created_at,id', [$table,$s['id'],$action]) as $log) {
                $old = json_decode($log['old_values'] ?? '', true) ?: [];
                $new = json_decode($log['new_values'] ?? '', true) ?: [];
                if (isset($old[$field], $new[$field])) $edits[] = [self::cents($old[$field]),self::cents($new[$field]),$log['created_at'],(int)$log['user_id']];
            }
        }
        $initial = $edits ? $edits[0][0] : $s['history'];
        if ($s['type'] === 'advance') {
            $label = 'ئامانەتی وەرگیراو ' . $s['reference'];
        } elseif ($s['type'] === 'payment' && ($s['raw']['payment_method'] ?? '') === 'advance') {
            $label = 'لە ئامانەتەوە — ' . $s['reference'];
        } else {
            $label = 'نەقدی وەسڵ — تۆماری پێشوو لە ١/١٠/٢٠٢٦وە';
        }
        $events = $initial ? [[$initial,$s['date'],$s['actor'],$label]] : [];
        foreach ($edits as $edit) {
            if ($edit[1] !== $edit[0]) $events[] = [$edit[1]-$edit[0],$edit[2],$edit[3],'دەستکاری بڕی نەقدی وەسڵ'];
        }
        $running = array_sum(array_column($events, 0));
        $remaining = $s['amount'] - $running;
        if ($remaining) $events[] = [$remaining,$s['refund_at'] ?: date('Y-m-d H:i:s'),(int)($s['raw']['reversed_by'] ?? $s['raw']['refunded_by'] ?? $s['actor']),'گەڕاندنەوە / ڕاستکردنەوەی نەقد'];
        return $events;
    }

    /** Undo both frozen shares of the old receipt before moving its custody. */
    private function rehomeAdvance(array $state, ?int $actorId): void
    {
        $revision = (int)$state['revision'];
        $doctor = $state['booked_doctor'] === null ? self::cents($state['booked_amount']) : self::cents($state['booked_doctor']);
        $center = self::cents($state['booked_center'] ?? 0);
        $extra = ['source_type'=>'advance', 'source_id'=>(int)$state['source_id'], 'reference_no'=>$state['reference_no'], 'case_id'=>null];
        $now = date('Y-m-d H:i:s');
        $this->ensureAccount($state['account_key']);
        if ($doctor) $this->append($state['account_key'], -$doctor, 'amanat_move', 'گواستنەوەی قەبزی کۆن بۆ قاسەی ئامانەت', $actorId, $now, $extra + ['source_revision'=>++$revision]);
        if ($center) {
            $this->ensureAccount(self::CENTER);
            $this->append(self::CENTER, -$center, 'amanat_move', 'گەڕاندنەوەی پشکی ئامانەت لە قاسەی لێزان', $actorId, $now, $extra + ['source_revision'=>++$revision]);
        }
        $this->db->update('cashbox_sources', [
            'account_key'=>self::AMANAT, 'booked_amount'=>'0.00', 'split_rate'=>null,
            'booked_doctor'=>null, 'booked_center'=>null, 'revision'=>$revision,
        ], 'source_type=? AND source_id=?', ['advance', (int)$state['source_id']]);
    }

    public function syncSource(string $type, int $id, ?int $actorId = null, string $reason = '', ?string $kind = null): void
    {
        $this->db->beginTransaction();
        try {
            $s = $this->source($type,$id);
            $state = $this->db->fetch('SELECT * FROM cashbox_sources WHERE source_type=? AND source_id=? FOR UPDATE', [$type,$id]);
            if (!$s && !$state) { $this->db->commit(); return; }
            // A counter receipt booked into a doctor's box under the old rule.
            if ($type === 'advance' && $state && $state['account_key'] !== self::AMANAT) {
                $this->rehomeAdvance($state, $actorId);
                $state = $this->db->fetch('SELECT * FROM cashbox_sources WHERE source_type=? AND source_id=? FOR UPDATE', [$type,$id]);
            }
            if ($s && !$state && $s['key'] === 'doctor:0') { $this->db->commit(); return; }
            $expense = isset(self::EXPENSE_TABLES[$type]);
            // A lab/store cost only moves money once the split is switched on.
            if ($expense && !$state && !self::splitEnabled()) { $this->db->commit(); return; }
            if (!$s) $s = ['type'=>$type,'id'=>$id,'key'=>$state['account_key'],'amount'=>0,'reference'=>$state['reference_no'],'date'=>$state['source_date'],'case_id'=>$state['case_id']];
            // Receipt ownership is frozen when first booked; changing a folder
            // later cannot move already-collected money to another doctor.
            if ($state) $s['key'] = $state['account_key'];
            $this->ensureAccount($s['key']);
            $isDoctor = strncmp($s['key'], 'doctor:', 7) === 0;
            // Booked before the split was switched on: the centre's part of all
            // this doctor's earlier money moves first, so this change splits too.
            if ($state && $isDoctor && $state['booked_doctor'] === null && self::splitEnabled()) {
                $this->openingSplit($s['key']);
                $state = $this->db->fetch('SELECT * FROM cashbox_sources WHERE source_type=? AND source_id=? FOR UPDATE', [$type,$id]);
            }
            if (!$state) {
                $split = $isDoctor && self::splitEnabled();
                $rate = $split ? $this->doctorRate100($s['key']) : null;
                $state = [
                    'booked_amount' => '0.00', 'revision' => 0,
                    'split_rate' => $rate === null ? null : number_format($rate / 100, 2, '.', ''),
                    'booked_doctor' => $split ? '0.00' : null,
                    'booked_center' => $split ? '0.00' : null,
                ];
                $this->db->insert('cashbox_sources', ['source_type'=>$type,'source_id'=>$id,'account_key'=>$s['key'],'reference_no'=>$s['reference'],'source_date'=>$s['date'],'case_id'=>$s['case_id'],
                    'split_rate'=>$state['split_rate'],'booked_doctor'=>$state['booked_doctor'],'booked_center'=>$state['booked_center']]);
                $events = $expense ? $this->expenseEvents($s, $rate) : $this->initialEvents($s);
            } else {
                $rate = $state['split_rate'] === null ? null : (int)round((float)$state['split_rate'] * 100);
                $target = $expense ? ($rate === null ? 0 : self::shareOf((int)$s['amount'], $rate)) : (int)$s['amount'];
                $delta = $target - self::cents($state['booked_amount']);
                $events = $delta ? [[$delta,date('Y-m-d H:i:s'),$actorId ?? 0,$reason ?: ($expense ? 'گۆڕانی خەرجی ' . $s['reference'] : 'گۆڕانی نەقدی وەسڵ / گەڕاندنەوە')]] : [];
            }

            // booked_doctor NULL = an unsplit row: all of it stays in its own box.
            $splitRow = $state['booked_doctor'] !== null;
            $booked = self::cents($state['booked_amount']);
            $bookedDoctor = $splitRow ? self::cents($state['booked_doctor']) : $booked;
            $bookedCenter = $splitRow ? self::cents($state['booked_center'] ?? '0') : 0;
            $revision = (int)$state['revision'];
            $doctorName = null;
            foreach ($events as $event) {
                $delta = (int)$event[0];
                if (!$delta) continue;
                $booked += $delta;
                if ($expense) {
                    // The booked figure IS the doctor's part of the cost: out of his box, into قاسەی لێزان.
                    $toDoctor = -$delta;
                } elseif ($splitRow && $rate !== null) {
                    // Worked out on the running total, so rounding never drifts.
                    $toDoctor = self::shareOf($booked, $rate) - $bookedDoctor;
                } else {
                    $toDoctor = $delta;
                }
                $toCenter = $expense ? $delta : $delta - $toDoctor;
                $bookedDoctor += $toDoctor;
                $bookedCenter += $toCenter;
                $actor = (int)$event[2] ?: $actorId;
                $extra = ['source_type'=>$type,'source_id'=>$id,'reference_no'=>$s['reference'],'case_id'=>$s['case_id']];
                if ($toDoctor) {
                    $revision++;
                    if ($expense) {
                        $kind = 'expense_share';
                        $why = 'بەشی دکتۆر (' . self::percentLabel((int)$rate) . '%) لە ' . $event[3] . ' — بۆ قاسەی لێزان';
                    } else {
                        $entryKind = $kind ?? ($toDoctor > 0 ? 'receipt' : 'refund');
                        $why = $toCenter ? $event[3] . ' — پشکی دکتۆر ' . self::percentLabel((int)$rate) . '%' : $event[3];
                    }
                    $this->append($s['key'], $toDoctor, $expense ? $entryKind = 'expense_share' : $entryKind, $why, $actor, $event[1], $extra + ['source_revision'=>$revision]);
                }
                if ($toCenter) {
                    $doctorName = $doctorName ?? $this->doctorName($s['key']);
                    $revision++;
                    $this->ensureAccount(self::CENTER);
                    $why = $expense
                        ? 'بەشی ' . $doctorName . ' (' . self::percentLabel((int)$rate) . '%) لە ' . $event[3]
                        : 'پشکی لێزان ' . self::percentLabel(10000 - (int)$rate) . '% — ' . $event[3] . ' — ' . $doctorName;
                    $this->append(self::CENTER, $toCenter, $expense ? 'expense_share' : 'center_share', $why, $actor, $event[1], $extra + ['source_revision'=>$revision]);
                }
            }
            $this->db->update('cashbox_sources', ['booked_amount'=>self::decimal($booked),'revision'=>$revision]
                + ($splitRow ? ['booked_doctor'=>self::decimal($bookedDoctor),'booked_center'=>self::decimal($bookedCenter)] : []),
                'source_type=? AND source_id=?', [$type,$id]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    private function append(string $key, int $delta, string $kind, string $reason, ?int $actor, string $date, array $extra = []): int
    {
        $account = $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=? FOR UPDATE', [$key]);
        $balance = self::cents($account['balance']) + $delta;
        if (abs($balance) > 99999999999999999) throw new DomainException('بڕی سندوق لە سنووری ڕێپێدراو زیاترە');
        $id = (int)$this->db->insert('cashbox_entries', $extra + ['account_key'=>$key,'entry_kind'=>$kind,'amount'=>self::decimal($delta),'balance_after'=>self::decimal($balance),'reason'=>mb_substr($reason,0,600),'actor_id'=>$actor ?: null,'occurred_at'=>$date]);
        $this->db->update('cashbox_accounts', ['balance'=>self::decimal($balance)], 'account_key=?', [$key]);
        return $id;
    }

    /** Canonical source IDs; debt/advance allocations never become new cash. */
    private function sourceIds(?string $key): array
    {
        $out = [];
        if ($key === null || $key !== 'xray') {
            $doctorId = $key !== null ? (int)substr($key,7) : 0;
            foreach (['invoice','payment','installment','advance'] as $type) {
                $start = "'" . self::START . "'";
                if ($type === 'advance') {
                    $sql = "SELECT a.id FROM patient_advances a LEFT JOIN cashbox_sources st ON st.source_type='advance' AND st.source_id=a.id
                            WHERE st.source_id IS NULL OR st.account_key<>'amanat' OR st.booked_amount <>
                            CASE WHEN a.payment_method='cash' AND a.received_at BETWEEN {$start} AND NOW()
                            THEN GREATEST(a.amount-a.refunded_amount-COALESCE((SELECT SUM(al.amount) FROM patient_advance_allocations al WHERE al.advance_id=a.id AND al.reversed_at IS NULL),0),0) ELSE 0 END";
                    foreach ($this->db->fetchAll($sql) as $r) $out[] = [$type,(int)$r['id']];
                    foreach ($this->db->fetchAll("SELECT st.source_id FROM cashbox_sources st LEFT JOIN patient_advances a ON a.id=st.source_id WHERE st.source_type='advance' AND st.account_key<>'amanat' AND a.id IS NULL") as $r) {
                        $out[] = [$type,(int)$r['source_id']];
                    }
                    continue;
                }
                if ($key === self::AMANAT) continue;
                if ($type === 'invoice') $sql = "SELECT s.id,COALESCE(iu.id,pf.doctor_id) doctor_id,CASE WHEN s.accounting_status='approved' AND s.work_date>=DATE({$start}) AND s.work_date<=CURDATE() THEN LEAST(GREATEST(s.draft_received_amount,0),GREATEST(s.total_amount-s.discount_amount,0)) ELSE 0 END target FROM case_invoices s JOIN cases c ON c.id=s.case_id JOIN patient_folders pf ON pf.id=c.folder_id LEFT JOIN users iu ON iu.id=s.doctor_id AND iu.role='doctor'";
                elseif ($type === 'payment') $sql = "SELECT s.id,pf.doctor_id,CASE WHEN s.status='approved' AND s.payment_date BETWEEN {$start} AND NOW() THEN CASE WHEN s.payment_method='cash' THEN s.amount WHEN s.payment_method='advance' THEN COALESCE(s.advance_cash_amount,(SELECT SUM(al.amount) FROM patient_advance_allocations al JOIN patient_advances a ON a.id=al.advance_id WHERE al.payment_id=s.id AND al.reversed_at IS NULL AND a.payment_method='cash'),0) ELSE 0 END ELSE 0 END target FROM payments s JOIN cases c ON c.id=s.case_id JOIN patient_folders pf ON pf.id=c.folder_id";
                else $sql = "SELECT s.id,pf.doctor_id,CASE WHEN s.payment_id IS NULL AND s.payment_method='cash' AND s.paid_at BETWEEN {$start} AND NOW() THEN s.amount ELSE 0 END target FROM installment_payments s JOIN installment_plans pl ON pl.id=s.plan_id JOIN cases c ON c.id=pl.case_id JOIN patient_folders pf ON pf.id=c.folder_id";
                $query = 'SELECT q.id FROM (' . $sql . ') q JOIN users u ON u.id=q.doctor_id AND u.role=\'doctor\' LEFT JOIN cashbox_sources st ON st.source_type=? AND st.source_id=q.id WHERE (st.source_id IS NULL OR st.booked_amount<>q.target)' . ($doctorId ? ' AND (q.doctor_id=? OR st.account_key=?)' : '');
                foreach ($this->db->fetchAll($query, $doctorId ? [$type,$doctorId,$key] : [$type]) as $r) $out[] = [$type,(int)$r['id']];
            }
        }
        // Lab/store costs charged to a doctor, once the split is on. The booked
        // figure is his percentage of the cost (frozen per row in split_rate).
        if (self::splitEnabled() && ($key === null || strncmp($key, 'doctor:', 7) === 0)) {
            $doctorId = $key !== null ? (int)substr($key,7) : 0;
            $start = "'" . self::START . "'";
            foreach (self::EXPENSE_TABLES as $type => $table) {
                $query = "SELECT e.id FROM {$table} e JOIN users u ON u.id=e.doctor_id AND u.role='doctor'
                          LEFT JOIN cashbox_sources st ON st.source_type=? AND st.source_id=e.id
                          WHERE (st.source_id IS NULL OR st.booked_amount <> CASE WHEN e.expense_date>=DATE({$start}) AND e.expense_date<=CURDATE()
                                 THEN COALESCE(ROUND(GREATEST(e.amount,0)*st.split_rate/100,2),0) ELSE 0 END)"
                    . ($doctorId ? ' AND (e.doctor_id=? OR st.account_key=?)' : '');
                foreach ($this->db->fetchAll($query, $doctorId ? [$type,$doctorId,$key] : [$type]) as $r) $out[] = [$type,(int)$r['id']];
            }
        }
        if ($key === null || $key === 'xray') {
            $start = "'" . self::START . "'";
            $sql = "SELECT q.id FROM (SELECT xr.id, CASE WHEN xr.status='cancelled' OR xr.request_status='cancelled' THEN 0 WHEN EXISTS(SELECT 1 FROM patient_xray_debts d WHERE d.request_id=xr.id) THEN COALESCE((SELECT SUM(p.amount) FROM patient_xray_debts d JOIN xray_debt_payments p ON p.debt_id=d.id WHERE d.request_id=xr.id AND p.payment_method='cash' AND p.created_at BETWEEN {$start} AND NOW()),0) WHEN xr.payment_status='paid' AND xr.request_status NOT IN ('pending_accountant','returned_to_accountant') AND COALESCE(xr.paid_at,xr.created_at) BETWEEN {$start} AND NOW() THEN xr.final_amount ELSE 0 END target FROM xray_requests xr) q LEFT JOIN cashbox_sources st ON st.source_type='xray' AND st.source_id=q.id WHERE st.source_id IS NULL OR st.booked_amount<>q.target ORDER BY q.id";
            foreach ($this->db->fetchAll($sql) as $r) $out[] = ['xray',(int)$r['id']];
        }
        return $out;
    }

    /** Safety reconciliation also covers legacy writers and deleted sources. */
    public function reconcile(?string $key = null): void
    {
        // قاسەی لێزان has no receipts of its own, but with the split on it holds
        // every doctor's centre share — bring them all up to date.
        if ($key === self::CENTER) {
            if (!self::splitEnabled()) return;
            $key = null;
        }
        if (self::splitEnabled()) {
            $legacy = $this->db->fetchAll(
                "SELECT DISTINCT account_key FROM cashbox_sources WHERE booked_doctor IS NULL AND account_key LIKE 'doctor:%'"
                . ($key !== null ? ' AND account_key=?' : ''), $key !== null ? [$key] : []);
            foreach ($legacy as $row) $this->openingSplit($row['account_key']);
        }
        foreach ($this->sourceIds($key) as [$type,$id]) $this->syncSource($type,$id);
        foreach (self::TABLES as $type=>$table) {
            $params = $key !== null ? [$type,$key] : [$type];
            $sql = "SELECT st.source_id FROM cashbox_sources st LEFT JOIN {$table} s ON s.id=st.source_id WHERE st.source_type=? AND st.booked_amount<>0 AND s.id IS NULL" . ($key !== null ? ' AND st.account_key=?' : '');
            foreach ($this->db->fetchAll($sql,$params) as $s) $this->syncSource($type,(int)$s['source_id']);
        }
        if ($key === null || $key === 'xray') $this->importHistoricalXrayReversals();
    }

    /** Deleted X-ray collections survive in the existing audit trail. Import
     * their original cash and matching refund without changing today's net. */
    private function importHistoricalXrayReversals(): void
    {
        $logs=$this->db->fetchAll("SELECT al.* FROM activity_log al JOIN cashbox_accounts a ON a.account_key='xray' WHERE al.action IN ('xray_request_deleted','xray_request_reopened','xray_debt_payment_reversed') AND al.created_at>=? AND al.created_at<a.created_at AND NOT EXISTS(SELECT 1 FROM cashbox_entries e WHERE e.request_key=CONCAT('xray-history:',al.id,':in')) ORDER BY al.created_at,al.id",[self::START]);
        foreach ($logs as $log) {
            $old=json_decode($log['old_values'] ?? '',true) ?: [];
            $amount=0; $cashAt=''; $reference='';
            if ($log['action']==='xray_debt_payment_reversed') {
                if (($old['payment_method'] ?? '')!=='cash') continue;
                $amount=self::cents($old['amount'] ?? '0'); $cashAt=$old['created_at'] ?? '';
                $reference='XDP-' . (int)$log['record_id'];
            } elseif (($old['payment_status'] ?? '')==='paid') {
                // Debt-settled requests belong to the individual collections,
                // not to the full request price a second time.
                $debt=$this->db->fetch("SELECT id FROM activity_log WHERE action='xray_debt_reversed' AND JSON_VALID(old_values) AND JSON_UNQUOTE(JSON_EXTRACT(old_values,'$.request_id'))=? LIMIT 1",[(string)$log['record_id']]);
                if ($debt) continue;
                $amount=self::cents($old['final_amount'] ?? '0'); $cashAt=$old['paid_at'] ?? $old['created_at'] ?? '';
                $reference=$old['request_number'] ?? ('XR-' . (int)$log['record_id']);
            }
            if ($amount<=0 || !$this->eligibleDate($cashAt)) continue;
            $token='xray-history:' . (int)$log['id'];
            // The cutoff above excludes events already handled by live hooks.
            $this->db->beginTransaction();
            try {
                $this->ensureAccount('xray');
                $this->db->fetch("SELECT account_key FROM cashbox_accounts WHERE account_key='xray' FOR UPDATE");
                if (!$this->db->fetch('SELECT id FROM cashbox_entries WHERE request_key=?',[$token . ':in'])) {
                    $extra=['source_type'=>'history','source_id'=>(int)$log['id'],'reference_no'=>$reference];
                    $this->append('xray',$amount,'receipt','نەقدی پێشووی ئێکسڕەی — لە مێژووی وەسڵی گەڕێنراوەوە',(int)$log['user_id'] ?: null,$cashAt,$extra+['source_revision'=>1,'request_key'=>$token . ':in']);
                    $this->append('xray',-$amount,'refund','گەڕاندنەوەی پێشووی نەقدی ئێکسڕەی',(int)$log['user_id'] ?: null,$log['created_at'],$extra+['source_revision'=>2,'request_key'=>$token . ':out']);
                }
                $this->db->commit();
            } catch (Throwable $e) { $this->db->rollback(); throw $e; }
        }
    }

    public static function captureCase(int $caseId, ?int $actorId = null, string $reason = ''): void
    {
        $ledger = new self();
        foreach (['invoice'=>'case_invoices','payment'=>'payments'] as $type=>$table) {
            foreach ($ledger->db->fetchAll("SELECT id FROM {$table} WHERE case_id=? ORDER BY id", [$caseId]) as $r) $ledger->syncSource($type,(int)$r['id'],$actorId,$reason);
        }
    }

    public function move(string $key, string $kind, $amount, string $reason, string $token, int $actorId): array
    {
        $this->authorize($actorId,$key,true);
        if (!in_array($kind,['deposit','withdrawal'],true)) throw new DomainException('جۆری جوڵە نادروستە');
        $cents = self::cents($amount);
        $reason = preg_replace('/^\s+|\s+$/u','',$reason) ?? '';
        if ($cents <= 0 || $cents > 999999999999999) throw new DomainException('بڕ دەبێت لە سفر زیاتر بێت و لە سنووری ڕێپێدراودا بێت');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 600) throw new DomainException('هۆکارێکی ڕوون بنووسە (٣ تا ٦٠٠ پیت)');
        if (!preg_match('/^[a-f0-9]{32,64}$/D',$token)) throw new DomainException('ناسنامەی جوڵە نادروستە؛ پەڕەکە نوێ بکەرەوە');
        $this->reconcile($key);
        $request = $key . ':' . $token;
        $fingerprint = hash('sha256',json_encode([$key,$kind,$cents,$reason,$actorId]));
        $this->db->beginTransaction();
        try {
            $a = $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=? FOR UPDATE',[$key]);
            $prior = $this->db->fetch('SELECT id,request_fingerprint FROM cashbox_entries WHERE request_key=?',[$request]);
            if ($prior) {
                if (!hash_equals($prior['request_fingerprint'],$fingerprint)) throw new DomainException('ئەم ناسنامەیە پێشتر بۆ جوڵەیەکی جیاواز بەکارهاتووە');
                $this->db->commit(); return ['id'=>(int)$prior['id'],'duplicate'=>true];
            }
            if ($kind === 'withdrawal' && $cents > self::cents($a['balance'])) throw new DomainException('بڕی ڕاکێشان لە نەقدی بەردەستی سندوق زیاترە');
            $id = $this->append($key,$kind==='withdrawal' ? -$cents : $cents,$kind,$reason,$actorId,date('Y-m-d H:i:s'),['request_key'=>$request,'request_fingerprint'=>$fingerprint]);
            AuditLog::log('cashbox_' . $kind,'cashbox_entries',$id,null,['account_key'=>$key,'amount'=>self::decimal($cents),'reason'=>$reason],$actorId);
            $this->db->commit(); return ['id'=>$id,'duplicate'=>false];
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function reverse(int $entryId, string $reason, int $actorId): int
    {
        $r = $this->db->fetch('SELECT * FROM cashbox_entries WHERE id=?',[$entryId]);
        if (!$r) throw new DomainException('جوڵە نەدۆزرایەوە');
        $actor = $this->authorize($actorId,$r['account_key'],true);
        if ($actor['role'] !== 'admin') throw new DomainException('تەنها ئەدمین دەتوانێت جوڵە ڕاست بکاتەوە');
        if (!in_array($r['entry_kind'],['deposit','withdrawal'],true)) throw new DomainException('جوڵەی وەسڵ لە وەسڵەکەی خۆی ڕاست دەکرێتەوە');
        $reason = preg_replace('/^\s+|\s+$/u','',$reason) ?? '';
        if (mb_strlen($reason)<3 || mb_strlen($reason)>600) throw new DomainException('هۆکاری ڕاستکردنەوە بنووسە');
        $this->reconcile($r['account_key']);
        $this->db->beginTransaction();
        try {
            $a = $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=? FOR UPDATE',[$r['account_key']]);
            $existing = $this->db->fetch('SELECT id FROM cashbox_entries WHERE reverses_entry_id=?',[$entryId]);
            if ($existing) { $this->db->commit(); return (int)$existing['id']; }
            $delta = -self::cents($r['amount']);
            if ($delta<0 && -$delta>self::cents($a['balance'])) throw new DomainException('نەقدی بەردەست بۆ ڕاستکردنەوەی ئەم جوڵەیە بەس نییە');
            $id = $this->append($r['account_key'],$delta,'reversal',$reason,$actorId,date('Y-m-d H:i:s'),['reverses_entry_id'=>$entryId,'reference_no'=>'#' . $entryId]);
            AuditLog::log('cashbox_reversal','cashbox_entries',$id,null,['reverses'=>$entryId,'reason'=>$reason],$actorId);
            $this->db->commit(); return $id;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    /** A box's cash right now, receipts brought up to date first. */
    public function balanceOf(string $key): int
    {
        $this->ensureAccount($key);
        $this->reconcile($key);
        return self::cents($this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=?', [$key])['balance'] ?? '0');
    }

    /**
     * دابەشکردنی داهات: the centre's share leaves the doctor's box for قاسەی
     * لێزان, and the doctor's own share is paid out of his box — one movement.
     * IncomeSettlement authorizes the actor and holds the outer transaction;
     * here only the cash rules apply: nothing below zero, nothing invented.
     *
     * @return array{before:int,after:int,transfer_out:?int,transfer_in:?int,payout:?int} cents + entry ids
     */
    public function settle(string $doctorKey, int $transferCents, int $payoutCents, string $reference, string $doctorName, int $actorId): array
    {
        if (!preg_match('/^doctor:[1-9][0-9]*$/D', $doctorKey)) throw new DomainException('سندوقی دکتۆر نادروستە');
        if ($transferCents < 0 || $payoutCents < 0) throw new DomainException('بڕی گواستنەوە و ڕاکێشان نابێت لە سفر کەمتر بێت');
        $this->ensureAccount($doctorKey);
        $this->ensureAccount(self::CENTER);
        $this->reconcile($doctorKey);
        $this->db->beginTransaction();
        try {
            $box = $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=? FOR UPDATE', [$doctorKey]);
            $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=? FOR UPDATE', [self::CENTER]);
            $before = self::cents($box['balance']);
            if ($transferCents + $payoutCents > $before) {
                throw new DomainException('نەقدی ناو سندوقی دکتۆر (' . self::money(self::decimal($before)) . ') بۆ ئەم گواستنەوە و ڕاکێشانە بەس نییە');
            }
            $now = date('Y-m-d H:i:s');
            $extra = ['reference_no' => $reference];
            $out = $in = $pay = null;
            if ($transferCents > 0) {
                $out = $this->append($doctorKey, -$transferCents, 'transfer_out', 'پشکی سەنتەر گوازرایەوە بۆ قاسەی لێزان — حیسابی ' . $reference, $actorId, $now, $extra);
                $in = $this->append(self::CENTER, $transferCents, 'transfer_in', 'پشکی سەنتەر لە سندوقی ' . $doctorName . ' — حیسابی ' . $reference, $actorId, $now, $extra);
            }
            if ($payoutCents > 0) {
                $pay = $this->append($doctorKey, -$payoutCents, 'payout', 'پشکی دکتۆر درا بە ' . $doctorName . ' — حیسابی ' . $reference, $actorId, $now, $extra);
            }
            $this->db->commit();
            return ['before' => $before, 'after' => $before - $transferCents - $payoutCents,
                    'transfer_out' => $out, 'transfer_in' => $in, 'payout' => $pay];
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    /**
     * Undo a settlement's movements when it is cancelled: each one is reversed
     * by a new entry, none is deleted. The centre's box must still hold the
     * money that is taken back out of it.
     */
    public function unsettle(array $entryIds, string $reason, int $actorId): void
    {
        $this->db->beginTransaction();
        try {
            foreach (array_filter(array_map('intval', $entryIds)) as $id) {
                $e = $this->db->fetch('SELECT * FROM cashbox_entries WHERE id=?', [$id]);
                if (!$e || !in_array($e['entry_kind'], self::SETTLEMENT_KINDS, true)) continue;
                if ($this->db->fetch('SELECT id FROM cashbox_entries WHERE reverses_entry_id=?', [$id])) continue;
                $a = $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key=? FOR UPDATE', [$e['account_key']]);
                $delta = -self::cents($e['amount']);
                if ($delta < 0 && -$delta > self::cents($a['balance'])) {
                    throw new DomainException('نەقدی قاسەی لێزان بۆ گەڕاندنەوەی ئەم حیسابە بەس نییە');
                }
                $this->append($e['account_key'], $delta, 'reversal', $reason, $actorId, date('Y-m-d H:i:s'),
                    ['reverses_entry_id' => $id, 'reference_no' => $e['reference_no']]);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function accounts(int $actorId): array
    {
        $actor = $this->db->fetch('SELECT role FROM users WHERE id=?',[$actorId]);
        if (($actor['role']??'')!=='admin') throw new DomainException('دەسەڵاتت نییە');
        $this->authorize($actorId,'xray');
        $this->db->query("INSERT IGNORE INTO cashbox_accounts (account_key,owner_type,doctor_id) SELECT CONCAT('doctor:',id),'doctor',id FROM users WHERE role='doctor'");
        $this->reconcile();
        return $this->db->fetchAll("SELECT a.*,u.full_name,u.is_active,(SELECT MAX(e.occurred_at) FROM cashbox_entries e WHERE e.account_key=a.account_key) last_movement FROM cashbox_accounts a LEFT JOIN users u ON u.id=a.doctor_id ORDER BY a.owner_type DESC,u.full_name");
    }

    public function statement(string $key, int $actorId, string $from, string $to, string $direction = '', int $page = 1): array
    {
        $global = $key === '*';
        $actor = $this->authorize($actorId,$global ? 'xray' : $key);
        if ($global && $actor['role'] !== 'admin') throw new DomainException('دەسەڵاتت نییە');
        $this->reconcile($global ? null : $key);
        foreach ([$from,$to] as $date) {
            $d=DateTime::createFromFormat('!Y-m-d',$date);
            if (!$d || $d->format('Y-m-d')!==$date) throw new DomainException('بەروار نادروستە');
        }
        if ($from>$to) throw new DomainException('بەرواری دەستپێک دەبێت پێش کۆتایی بێت');
        $where=($global ? '' : 'account_key=? AND ') . 'occurred_at>=? AND occurred_at<?';
        $params=$global ? [] : [$key];
        $params[]=$from . ' 00:00:00'; $params[]=date('Y-m-d',strtotime($to . ' +1 day')) . ' 00:00:00';
        $summary=$this->db->fetch('SELECT COALESCE(SUM(CASE WHEN amount>0 THEN amount ELSE 0 END),0) incoming,COALESCE(SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END),0) outgoing,COALESCE(SUM(amount),0) net FROM cashbox_entries WHERE ' . $where,$params);
        $opening=$this->db->fetch('SELECT COALESCE(SUM(amount),0) amount FROM cashbox_entries WHERE ' . ($global ? '' : 'account_key=? AND ') . 'occurred_at<?',$global ? [$from . ' 00:00:00'] : [$key,$from . ' 00:00:00']);
        $a=$global ? $this->db->fetch("SELECT '*' account_key,'all' owner_type,NULL doctor_id,'هەموو سندوقەکان' full_name,COALESCE(SUM(balance),0) balance FROM cashbox_accounts") : $this->db->fetch('SELECT a.*,u.full_name FROM cashbox_accounts a LEFT JOIN users u ON u.id=a.doctor_id WHERE a.account_key=?',[$key]);
        if ($direction==='in') $where.=' AND amount>0';
        elseif ($direction==='out') $where.=' AND amount<0';
        $count=(int)$this->db->fetch('SELECT COUNT(*) n FROM cashbox_entries WHERE ' . $where,$params)['n'];
        $page=max(1,min($page,max(1,(int)ceil($count/40)))); $offset=($page-1)*40;
        $entries=$this->db->fetchAll('SELECT e.*,u.full_name actor_name,owner.full_name owner_name,(SELECT r.id FROM cashbox_entries r WHERE r.reverses_entry_id=e.id) reversed_by_entry FROM cashbox_entries e LEFT JOIN users u ON u.id=e.actor_id LEFT JOIN cashbox_accounts a ON a.account_key=e.account_key LEFT JOIN users owner ON owner.id=a.doctor_id WHERE ' . str_replace(['account_key=','occurred_at','amount>','amount<'],['e.account_key=','e.occurred_at','e.amount>','e.amount<'],$where) . ' ORDER BY e.occurred_at DESC,e.id DESC LIMIT 40 OFFSET ' . $offset,$params);
        foreach ($entries as &$entry) {
            // Chronological balance, independent of pagination/direction filters.
            $entry['running_balance']=$this->db->fetch('SELECT COALESCE(SUM(amount),0) amount FROM cashbox_entries WHERE account_key=? AND (occurred_at<? OR (occurred_at=? AND id<=?))',[$entry['account_key'],$entry['occurred_at'],$entry['occurred_at'],(int)$entry['id']])['amount'];
        }
        unset($entry);
        return ['account'=>$a,'summary'=>$summary,'opening'=>$opening['amount'],'closing'=>self::decimal(self::cents($opening['amount'])+self::cents($summary['net'])),'entries'=>$entries,'count'=>$count,'page'=>$page,'pages'=>max(1,(int)ceil($count/40))];
    }
}
