<?php
/** Explicit admin correction of a visit invoice, its work and linked receipts. */
require_once __DIR__ . '/CaseInvoice.php';
require_once __DIR__ . '/Payment.php';

class InvoiceCorrection
{
    private $db;
    public function __construct() { $this->db = Database::getInstance(); }

    private function admin(int $actor, bool $lock = false): void
    {
        $u = $this->db->fetch('SELECT role,is_active FROM users WHERE id=?'.($lock ? ' FOR UPDATE' : ''), [$actor]);
        if (!$u || !$u['is_active'] || $u['role'] !== 'admin') {
            throw new DomainException('دەستکاریکردنی ئەم وەسڵە تەنها بۆ ئەدمینە');
        }
    }

    /** The revision covers allocation/approval changes as well as invoice edits. */
    public function snapshot(int $caseId, int $invoiceId, int $actor, bool $lock = false): array
    {
        $this->admin($actor, $lock);
        $suffix = $lock ? ' FOR UPDATE' : '';
        $invoice = $this->db->fetch('SELECT * FROM case_invoices WHERE case_id=? AND id=?'.$suffix, [$caseId, $invoiceId]);
        if (!$invoice || $invoice['accounting_status'] === 'cancelled') {
            throw new DomainException('وەسڵ نەدۆزرایەوە یان سڕاوەتەوە');
        }
        $rows = $this->db->fetchAll("SELECT * FROM case_treatments WHERE invoice_id=? AND case_id=? AND status<>'cancelled' ORDER BY id".$suffix, [$invoiceId, $caseId]);
        $payments = $this->db->fetchAll('SELECT p.*,pa.allocated_amount FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.id WHERE pa.invoice_id=? ORDER BY p.id'.$suffix, [$invoiceId]);
        $plans = $this->db->fetchAll("SELECT * FROM installment_plans WHERE invoice_id=? AND status IN ('pending','active','completed') ORDER BY id".$suffix, [$invoiceId]);
        $case = $this->db->fetch('SELECT status,is_historical FROM cases WHERE id=?'.$suffix, [$caseId]);
        // A receipt may also settle another invoice: include the whole case in
        // the revision, so correcting it cannot overwrite a colleague's work.
        $revision = [
            $case, $rows, $plans,
            $this->db->fetchAll('SELECT * FROM case_invoices WHERE case_id=? ORDER BY id'.$suffix, [$caseId]),
            $this->db->fetchAll('SELECT * FROM payments WHERE case_id=? ORDER BY id'.$suffix, [$caseId]),
            $this->db->fetchAll('SELECT pa.* FROM payment_allocations pa JOIN case_invoices ci ON ci.id=pa.invoice_id WHERE ci.case_id=? ORDER BY pa.payment_id,pa.invoice_id'.$suffix, [$caseId]),
        ];
        return ['invoice'=>$invoice, 'rows'=>$rows, 'payments'=>$payments, 'plans'=>$plans,
            'revision'=>hash('sha256', json_encode($revision, JSON_THROW_ON_ERROR))];
    }

    private static function amount($value): int
    {
        $n = CashboxLedger::cents($value);
        if ($n < 0 || $n > 999999999999) throw new InvalidArgumentException('بڕی پارە لە سنووری ڕێگەپێدراو نییە');
        return $n;
    }

    private static function text($value, int $max): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > $max) throw new InvalidArgumentException('دەقەکە زۆر درێژە یان نادروستە');
        return trim($value);
    }

    private static function day($value): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('بەروار نادروستە');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value || $value > date('Y-m-d')) throw new InvalidArgumentException('بەروار نادروستە یان لە داهاتوودایە');
        return $value;
    }

    public function save(int $caseId, int $invoiceId, int $actor, array $input): array
    {
        $this->db->beginTransaction();
        try {
            $this->admin($actor);
            // Match the allocation writers' lock order. No monetary edit can
            // interleave with this correction, including a new later receipt.
            $this->db->fetchAll('SELECT id FROM case_invoices WHERE case_id=? ORDER BY id FOR UPDATE', [$caseId]);
            $case = $this->db->fetch('SELECT * FROM cases WHERE id=? FOR UPDATE', [$caseId]);
            if (!$case || $case['status'] !== 'open') throw new DomainException('کەیسەکە داخراوە؛ سەرەتا بیکەرەوە');
            $this->db->fetchAll('SELECT id FROM payments WHERE case_id=? ORDER BY id FOR UPDATE', [$caseId]);
            $this->db->fetchAll('SELECT id FROM case_treatments WHERE invoice_id=? ORDER BY id FOR UPDATE', [$invoiceId]);
            $this->db->fetchAll('SELECT id FROM installment_plans WHERE invoice_id=? ORDER BY id FOR UPDATE', [$invoiceId]);
            $before = $this->snapshot($caseId, $invoiceId, $actor, true);
            if (!is_string($input['revision'] ?? null) || !hash_equals($before['revision'], $input['revision'])) {
                throw new DomainException('داتاکە لەلایەن کەسێکی تر گۆڕاوە؛ پەڕەکە نوێ بکەرەوە و دووبارە دەستکاری بکە');
            }
            $reason = self::text($input['reason'] ?? '', 500);
            if ($reason === '') throw new InvalidArgumentException('هۆکاری دەستکاریکردن بنووسە');
            $old = $before['invoice'];
            $date = self::day($input['work_date'] ?? '');
            $no = self::text($input['invoice_no'] ?? '', 60);
            if ($no === '') throw new InvalidArgumentException('ژمارەی وەسڵ پێویستە');
            if ($this->db->fetch('SELECT id FROM case_invoices WHERE invoice_no=? AND id<>?', [$no,$invoiceId])) throw new InvalidArgumentException('ژمارەی وەسڵ دووبارەیە');
            if (!is_array($input['rows'] ?? null) || count($input['rows']) > 500) throw new InvalidArgumentException('ڕیزەکانی وەسڵ نادروستن');
            $existing = array_column($before['rows'], null, 'id');
            $seen = []; $work = []; $total = 0;
            foreach ($input['rows'] as $r) {
                if (!is_array($r) || !(is_int($r['id'] ?? null) || is_string($r['id'] ?? null)) || !preg_match('/^\d+$/D', (string)$r['id'])) throw new InvalidArgumentException('ڕیزی نادروست');
                $id = (int)$r['id'];
                if ($id && (!isset($existing[$id]) || isset($seen[$id]))) throw new DomainException('ڕیزی دووبارە یان هی وەسڵێکی ترە');
                if ($id) $seen[$id] = true;
                if (!empty($r['remove'])) { if ($id) $work[] = ['id'=>$id,'remove'=>true]; continue; }
                $name = self::text($r['treatment_name'] ?? '', 100);
                if ($name === '') throw new InvalidArgumentException('ناوی چارەسەر پێویستە');
                if (!(is_int($r['quantity'] ?? null) || is_string($r['quantity'] ?? null))) throw new InvalidArgumentException('ژمارە نادروستە');
                $qty = (string)$r['quantity'];
                if (!preg_match('/^[1-9][0-9]{0,3}$/D', $qty)) throw new InvalidArgumentException('ژمارە دەبێت لە ١ تا ٩٩٩٩ بێت');
                $unit = self::amount($r['unit_price'] ?? '');
                $gross = $unit * (int)$qty;
                $cut = self::amount($r['discount'] ?? '0');
                if ($gross > 999999999999 || $cut > $gross) throw new InvalidArgumentException('نرخ یان داشکاندنی ڕیز نادروستە');
                $arch = $r['arch_type'] ?? 'none';
                if (!in_array($arch, ['none','upper','lower'], true)) throw new InvalidArgumentException('شوێنی چارەسەر نادروستە');
                if (isset($r['tooth_number']) && !is_int($r['tooth_number']) && !is_string($r['tooth_number'])) throw new InvalidArgumentException('ژمارەی ددان نادروستە');
                $tooth = (string)($r['tooth_number'] ?? '');
                if ($tooth !== '' && !preg_match('/^(?:[1-4][1-8]|[5-8][1-5])$/D', $tooth)) throw new InvalidArgumentException('ژمارەی ددان نادروستە');
                if ($arch !== 'none' && $tooth !== '') throw new InvalidArgumentException('چارەسەری چەناگە نابێت ژمارەی ددانی هەبێت');
                $data = ['treatment_name'=>$name, 'quantity'=>(int)$qty, 'unit_price'=>CashboxLedger::decimal($unit),
                    'total_price'=>CashboxLedger::decimal($gross), 'discount'=>CashboxLedger::decimal($cut),
                    'final_price'=>CashboxLedger::decimal($gross-$cut), 'tooth_number'=>$tooth === '' ? null : (int)$tooth,
                    'arch_type'=>$arch, 'notes'=>self::text($r['notes'] ?? '', 10000)];
                // A chart link describes its original tooth, never a new one.
                if (!$id || (string)$data['tooth_number'] !== (string)$existing[$id]['tooth_number']) $data['dental_chart_id'] = null;
                if ($id && $date !== $old['work_date']) {
                    $data['performed_date'] = $date.' '.(substr((string)($existing[$id]['performed_date'] ?? ''),11) ?: '00:00:00');
                }
                $work[] = ['id'=>$id, 'data'=>$data];
                $total += $gross-$cut;
            }
            if (count($seen) !== count($existing)) throw new DomainException('هەندێک ڕیز ونە؛ پەڕەکە نوێ بکەرەوە');
            if ($total > 999999999999) throw new InvalidArgumentException('کۆی وەسڵ زۆر گەورەیە');
            $mode = $input['discount_mode'] ?? '';
            if (!in_array($mode, ['amount','percent'], true)) throw new InvalidArgumentException('جۆری داشکاندن نادروستە');
            $value = self::amount($input['discount_value'] ?? '');
            if ($mode === 'percent' && $value > 10000) throw new InvalidArgumentException('داشکاندن لە ١٠٠٪ زیاترە');
            $discount = $mode === 'percent' ? intdiv($total*$value+5000,10000) : $value;
            if ($discount > $total) throw new InvalidArgumentException('داشکاندن لە کۆی وەسڵ زیاترە');
            $chair = self::amount($input['received_amount'] ?? '');
            $prior = self::amount($input['prior_paid_amount'] ?? '');
            if (empty($case['is_historical']) && $prior !== 0) throw new InvalidArgumentException('دراوی پێشوو تەنها بۆ کەیسی کۆنە');
            $net = $total-$discount;
            foreach ($before['plans'] as $plan) {
                if (in_array($plan['status'], ['pending','active','completed'], true) && ($net !== self::amount($old['total_amount'])-self::amount($old['discount_amount'])
                    || $chair !== self::amount($old['draft_received_amount']) || $prior !== self::amount($old['prior_paid_amount']))) {
                    throw new DomainException('پلانی قیستی چالاک هەیە؛ بڕی وەسڵ و پێشەکی لە پلانەکە ڕێکبخە');
                }
            }
            $changes = ['invoice_no'=>$no, 'work_date'=>$date, 'total_amount'=>CashboxLedger::decimal($total),
                'discount_mode'=>$mode, 'discount_value'=>CashboxLedger::decimal($value), 'discount_amount'=>CashboxLedger::decimal($discount),
                'draft_received_amount'=>CashboxLedger::decimal($chair), 'prior_paid_amount'=>CashboxLedger::decimal($prior),
                'notes'=>self::text($input['notes'] ?? '',10000), 'accounting_notes'=>self::text($input['accounting_notes'] ?? '',10000)];

            $paymentManager = new Payment();
            $paymentEdits = []; $seenPayments = [];
            if (!is_array($input['payments'] ?? [])) throw new InvalidArgumentException('قەبزەکان نادروستن');
            $linked = array_column($before['payments'], null, 'id');
            foreach (($input['payments'] ?? []) as $p) {
                if (!is_array($p)) throw new InvalidArgumentException('قەبزی نادروست');
                if (!(is_int($p['id'] ?? null) || is_string($p['id'] ?? null)) || !preg_match('/^[1-9][0-9]*$/D',(string)$p['id'])) throw new InvalidArgumentException('قەبزی نادروست');
                $pid = (int)($p['id'] ?? 0);
                if (!$pid || !isset($linked[$pid]) || isset($seenPayments[$pid]) || $linked[$pid]['status'] !== 'approved') throw new DomainException('قەبزەکە هی ئەم وەسڵە نییە یان پەسەند نەکراوە');
                $seenPayments[$pid] = true;
                $amount = self::amount($p['amount'] ?? '');
                if (!$amount) throw new InvalidArgumentException('بڕی قەبز دەبێت لە سفر زیاتر بێت؛ بۆ گەڕاندنەوە بەشی قەبزەکان بەکاربهێنە');
                $method = $p['payment_method'] ?? '';
                if (!in_array($method, ['cash','card','transfer','other'],true)) throw new InvalidArgumentException('شێوازی پارەدان نادروستە');
                $paymentDate = self::day($p['payment_date'] ?? '');
                // The date picker does not edit the receipt's time of day.
                if (strlen($linked[$pid]['payment_date']) > 10) $paymentDate .= substr($linked[$pid]['payment_date'],10);
                if ($paymentDate > date('Y-m-d H:i:s')) $paymentDate = date('Y-m-d H:i:s');
                $data = ['amount'=>CashboxLedger::decimal($amount),'payment_date'=>$paymentDate,'payment_method'=>$method];
                $changed = false;
                foreach ($data as $k=>$v) if ((string)$v !== (string)$linked[$pid][$k]) $changed = true;
                if ($changed) {
                    $block = $paymentManager->approvedEditBlockReason($linked[$pid]);
                    if ($block !== null) throw new DomainException($block);
                    $paymentEdits[$pid] = $data;
                }
            }
            // Reductions first, while the original invoice still has room.
            // Increases use the corrected net and the canonical allocator.
            foreach ($paymentEdits as $pid=>$p) {
                if (self::amount($p['amount']) < self::amount($linked[$pid]['amount'])) {
                    $r = $paymentManager->editApprovedFromInvoice($invoiceId,$pid,$p['amount'],$actor,$reason);
                    if (empty($r['success'])) throw new DomainException($r['message']);
                }
            }
            foreach ($work as $r) {
                if (!empty($r['remove'])) {
                    $this->db->delete('case_treatments','id=? AND case_id=?',[$r['id'],$caseId]);
                } elseif ($r['id']) {
                    $this->db->update('case_treatments',$r['data'],'id=?',[$r['id']]);
                } else {
                    $this->db->insert('case_treatments',$r['data']+['case_id'=>$caseId,'invoice_id'=>$invoiceId,'performed_by'=>$actor,'performed_date'=>$date.' '.date('H:i:s')]);
                }
            }
            $this->db->update('case_invoices',$changes,'id=?',[$invoiceId]);
            foreach ($paymentEdits as $pid=>$p) {
                if (self::amount($p['amount']) > self::amount($linked[$pid]['amount'])) {
                    $r = $paymentManager->editApprovedFromInvoice($invoiceId,$pid,$p['amount'],$actor,$reason);
                    if (empty($r['success'])) throw new DomainException($r['message']);
                }
                $this->db->update('payments',$p,'id=?',[$pid]);
                $installment = $this->db->fetch('SELECT id,plan_id FROM installment_payments WHERE payment_id=? FOR UPDATE',[$pid]);
                if ($installment) {
                    $this->db->update('installment_payments',['payment_method'=>$p['payment_method'],'paid_at'=>$p['payment_date']], 'id=?',[(int)$installment['id']]);
                    CashboxLedger::capture('installment',(int)$installment['id'],$actor,$reason);
                    (new Installment())->recomputePlan((int)$installment['plan_id']);
                }
                CashboxLedger::capture('payment',$pid,$actor,$reason);
            }
            $alloc = $this->db->fetch("SELECT COALESCE(SUM(CASE WHEN p.status='approved' THEN pa.allocated_amount ELSE 0 END),0) approved,
                COALESCE(SUM(CASE WHEN p.status='pending' THEN pa.allocated_amount ELSE 0 END),0) pending
                FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.invoice_id=?",[$invoiceId]);
            $paid = $chair+$prior+self::amount($old['advance_paid_amount'])+self::amount($alloc['approved']);
            $discountReservation = $this->db->fetch("SELECT COALESCE(SUM(da.amount),0) amount
                FROM payment_discount_allocations da JOIN payments p ON p.id=da.payment_id
                WHERE da.invoice_id=? AND p.status='pending'", [$invoiceId]);
            if ($paid+self::amount($alloc['pending'])+self::amount($discountReservation['amount']) > $net) throw new DomainException('کۆی وەرگیراو و قەبزی چاوەڕوان لە کۆی دوای داشکاندن زیاترە');
            $intent = $net === $paid ? 'cash' : (($old['payment_intent'] ?? '') === 'installment' ? 'installment' : 'debt');
            $this->db->update('case_invoices',['payment_intent'=>$intent,'updated_at'=>date('Y-m-d H:i:s')],'id=?',[$invoiceId]);
            (new CaseInvoice())->syncStatuses($caseId,$actor);
            (new CaseManager())->updateCaseTotals($caseId);
            CashboxLedger::capture('invoice',$invoiceId,$actor,$reason);
            $after = $this->snapshot($caseId,$invoiceId,$actor,true);
            // Mandatory audit, in the same transaction as every financial write.
            $this->db->insert('activity_log',[
                'user_id'=>$actor,'action'=>'invoice_admin_corrected','table_name'=>'case_invoices','record_id'=>$invoiceId,
                'old_values'=>json_encode(['invoice'=>$old,'rows'=>$before['rows'],'payments'=>$before['payments']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'new_values'=>json_encode(['reason'=>$reason,'invoice'=>$after['invoice'],'rows'=>$after['rows'],'payments'=>$after['payments']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            ]);
            $this->db->commit();
            return ['success'=>true,'invoice_id'=>$invoiceId];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof PDOException) {
                error_log('InvoiceCorrection::save: '.$e->getMessage());
                return ['success'=>false,'message'=>'تۆمارکردن سەرنەکەوت؛ هیچ گۆڕانکارییەک پاشەکەوت نەکرا. دووبارە هەوڵبدەرەوە'];
            }
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }
}
