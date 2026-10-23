<?php
/** Additional discounts agreed when collecting an existing, approved debt. */
require_once __DIR__ . '/AccountingLedger.php';
require_once __DIR__ . '/AuditLog.php';

class PaymentDiscount
{
    private $db;
    private $ledger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ledger = new AccountingLedger();
    }

    public static function number($raw)
    {
        $raw = trim(strtr((string)$raw, array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        )));
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $raw) || !is_finite((float)$raw)) {
            throw new InvalidArgumentException('بڕی پارە یان داشکاندن نادروستە');
        }
        return round((float)$raw, 2);
    }

    /** Percent means percent of the AVAILABLE debt, never of cash received. */
    public static function fields(array $data, $available)
    {
        $mode = (string)($data['discount_mode'] ?? 'amount');
        if (!in_array($mode, ['amount', 'percent'], true)) {
            throw new InvalidArgumentException('جۆری داشکاندن نادروستە');
        }
        $value = self::number($data['discount_value'] ?? '0');
        if ($mode === 'percent' && $value > 100) {
            throw new InvalidArgumentException('ڕێژەی داشکاندن نابێت لە ١٠٠٪ زیاتر بێت');
        }
        $amount = $mode === 'percent' ? round($available * $value / 100, 2) : $value;
        $cash = self::number($data['amount'] ?? '0');
        if ($cash <= 0 || $cash + $amount > $available + 0.009) {
            throw new InvalidArgumentException('کۆی پارە و داشکاندن نابێت لە قەرزی بەردەست زیاتر بێت');
        }
        $reason = trim((string)($data['discount_reason'] ?? ''));
        if ($amount > 0 && ($reason === '' || mb_strlen($reason) > 500)) {
            throw new InvalidArgumentException('هۆکاری داشکاندن پێویستە (تا ٥٠٠ پیت)');
        }
        return ['discount_mode' => $mode, 'discount_value' => $value,
            'discount_amount' => $amount, 'discount_reason' => $amount > 0 ? $reason : null];
    }

    /** Lock invoices before payments, matching the canonical money engine. */
    public function lockCase($caseId)
    {
        $this->db->fetchAll('SELECT id FROM case_invoices WHERE case_id=? ORDER BY id FOR UPDATE', [(int)$caseId]);
    }

    public function requireEligible($caseId)
    {
        $case = $this->db->fetch('SELECT status FROM cases WHERE id=?', [(int)$caseId]);
        if (!$case || $case['status'] !== 'open') {
            throw new RuntimeException('کەیسەکە داخراوە یان بەردەست نییە');
        }
        if ($this->db->fetch("SELECT id FROM installment_plans WHERE case_id=? AND status IN ('active','pending') LIMIT 1", [(int)$caseId])) {
            throw new RuntimeException('ئەم کەیسە پلانی قیستی هەیە؛ سەرەتا ژمێریار پلانەکە ڕێک بخاتەوە');
        }
    }

    public function requireAccountant($actorId)
    {
        if (!$this->db->fetch("SELECT id FROM users WHERE id=? AND is_active=1 AND role IN ('admin','accountant')", [(int)$actorId])) {
            throw new RuntimeException('تەنها بەڕێوەبەر یان ژمێریار داشکاندن پەسەند دەکات');
        }
    }

    /** Reserve both parts FIFO, with cash allocations kept strictly separate. */
    public function reserve(array $payment, $actorId)
    {
        $this->requireEligible((int)$payment['case_id']);
        $total = round((float)$payment['amount'] + (float)$payment['discount_amount'], 2);
        $plan = $this->ledger->autoAllocate((int)$payment['case_id'], $total, [], true, false, (int)$payment['id']);
        if (empty($plan['success'])) {
            throw new RuntimeException('قەرزی بەردەست گۆڕاوە؛ کۆی پارە و داشکاندن لە ماوە زیاترە');
        }
        $this->db->delete('payment_allocations', 'payment_id=?', [(int)$payment['id']]);
        $this->db->delete('payment_discount_allocations', 'payment_id=?', [(int)$payment['id']]);
        $cashLeft = (float)$payment['amount'];
        foreach ($plan['allocations'] as $row) {
            $cash = round(min($cashLeft, (float)$row['amount']), 2);
            if ($cash > 0) {
                $this->ledger->upsertAllocation((int)$payment['id'], (int)$row['invoice_id'], $cash, $actorId);
                $cashLeft = round($cashLeft - $cash, 2);
            }
            $discount = round((float)$row['amount'] - $cash, 2);
            if ($discount > 0) {
                $this->db->insert('payment_discount_allocations', [
                    'payment_id' => (int)$payment['id'], 'invoice_id' => (int)$row['invoice_id'], 'amount' => $discount,
                ]);
            }
        }
        return $plan['allocations'];
    }

    /** Called inside the receipt approval transaction, after all invoices lock. */
    public function apply(array $payment, $actorId, $finalAmount = null)
    {
        $this->requireAccountant($actorId);
        $this->requireEligible((int)$payment['case_id']);
        if ($finalAmount !== null) {
            $cash = self::number($finalAmount);
            if ($cash <= 0) { throw new InvalidArgumentException('بڕی پارە دەبێت گەورەتر بێت لە سفر'); }
            if (abs($cash - (float)$payment['amount']) > 0.009) {
                $this->db->update('payments', ['amount' => $cash], 'id=?', [(int)$payment['id']]);
                $payment['amount'] = $cash;
                $this->reserve($payment, $actorId);
            }
        }
        $cashRows = $this->db->fetchAll('SELECT invoice_id, allocated_amount AS amount FROM payment_allocations WHERE payment_id=?', [(int)$payment['id']]);
        $discountRows = $this->db->fetchAll('SELECT * FROM payment_discount_allocations WHERE payment_id=? ORDER BY invoice_id FOR UPDATE', [(int)$payment['id']]);
        $combined = [];
        foreach (array_merge($cashRows, $discountRows) as $row) {
            $id = (int)$row['invoice_id'];
            $combined[$id] = round(($combined[$id] ?? 0) + (float)$row['amount'], 2);
        }
        if (abs(array_sum(array_column($discountRows, 'amount')) - (float)$payment['discount_amount']) > 0.009
            || abs(array_sum(array_column($cashRows, 'amount')) - (float)$payment['amount']) > 0.009) {
            throw new RuntimeException('دابەشکردنی قەبز گۆڕاوە؛ داواکاری نوێ بنێرە');
        }
        $checked = $this->ledger->validateAllocations((int)$payment['case_id'], (float)$payment['amount'] + (float)$payment['discount_amount'], $combined, true, false, (int)$payment['id']);
        if (empty($checked['success'])) { throw new RuntimeException('قەرزی نەخۆش گۆڕاوە؛ سەرەتا داواکارییەکە ڕاست بکەرەوە'); }
        foreach ($discountRows as $row) {
            $invoice = $this->db->fetch('SELECT * FROM case_invoices WHERE id=? FOR UPDATE', [(int)$row['invoice_id']]);
            $after = round((float)$invoice['discount_amount'] + (float)$row['amount'], 2);
            $this->db->update('payment_discount_allocations', [
                'invoice_discount_before' => $invoice['discount_amount'], 'invoice_mode_before' => $invoice['discount_mode'],
                'invoice_value_before' => $invoice['discount_value'], 'invoice_discount_after' => $after,
            ], 'id=?', [(int)$row['id']]);
            $changes = ['discount_amount' => $after, 'discount_mode' => 'amount', 'discount_value' => $after,
                'updated_at' => date('Y-m-d H:i:s')];
            $this->db->update('case_invoices', $changes, 'id=?', [(int)$invoice['id']]);
            AuditLog::logChange('invoice_collection_discount', 'case_invoices', (int)$invoice['id'], $invoice,
                $changes + ['payment_id' => (int)$payment['id'], 'reason' => $payment['discount_reason']], $actorId);
        }
        return $payment;
    }

    /** Reverse the agreed discount with its receipt, preserving the old terms. */
    public function reverse(array $payment, $actorId)
    {
        if ((float)($payment['discount_amount'] ?? 0) <= 0) { return; }
        $this->requireAccountant($actorId);
        $rows = $this->db->fetchAll('SELECT * FROM payment_discount_allocations WHERE payment_id=? ORDER BY invoice_id FOR UPDATE', [(int)$payment['id']]);
        if (abs(array_sum(array_column($rows, 'amount')) - (float)$payment['discount_amount']) > 0.009) {
            throw new RuntimeException('تۆماری داشکاندن تەواو نییە؛ ژمێریار پشکنینی بکات');
        }
        foreach ($rows as $row) {
            $invoice = $this->db->fetch('SELECT * FROM case_invoices WHERE id=? FOR UPDATE', [(int)$row['invoice_id']]);
            if ($row['invoice_discount_after'] === null || !$invoice || $invoice['accounting_status'] !== 'approved'
                || $invoice['discount_mode'] !== 'amount'
                || abs((float)$invoice['discount_amount'] - (float)$row['invoice_discount_after']) > 0.009
                || abs((float)$invoice['discount_value'] - (float)$row['invoice_discount_after']) > 0.009) {
                throw new RuntimeException('داشکاندنی وەسڵ دوای ئەم قەبزە گۆڕاوە؛ سەرەتا گۆڕانکارییە نوێترەکان بگەڕێنەوە');
            }
            $changes = ['discount_amount' => $row['invoice_discount_before'], 'discount_mode' => $row['invoice_mode_before'],
                'discount_value' => $row['invoice_value_before'], 'updated_at' => date('Y-m-d H:i:s')];
            $this->db->update('case_invoices', $changes, 'id=?', [(int)$invoice['id']]);
            AuditLog::logChange('invoice_collection_discount_reversed', 'case_invoices', (int)$invoice['id'], $invoice, $changes, $actorId);
        }
    }
}
