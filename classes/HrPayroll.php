<?php
/**
 * HrPayroll — paying the monthly salaries (hr/payroll.php) and their
 * receipts, وەسڵی مووچەدان (hr/salary-receipt.php).
 *
 * The salary is HrAttendance::pay() of the month's net hours, the same number
 * as the monthly report. A month goes through two steps:
 *
 *   approve   the figures are kept in hr_payroll as they are now, with a bonus,
 *             a deduction (an advance already given, a penalty…) and a note.
 *             Until it is paid it can be approved again or withdrawn.
 *   pay       one person at a time: a receipt is written (hr_salary_receipts,
 *             its id is the receipt number) with its own copy of the figures
 *             and, if given, the person's electronic signature. Two choices
 *             on each payment:
 *               as_expense    (on by default) the amount is also one general
 *                             expense, category «مووچە», tied to the payroll
 *                             row (source_type 'hr_payroll');
 *               from_cashbox  (off by default) the amount is taken out of
 *                             قاسەی لێزان (CashboxLedger, the 'center' box —
 *                             admins only, and only what the box holds).
 *
 * A paid salary can be edited (bonus, deduction, note, date, way, the two
 * choices): the expense follows, and a cashbox withdrawal whose amount
 * changes is reversed and booked again. A new amount clears the signature —
 * the person signed for the old one. Deleting a payment removes its receipt
 * and expense, reverses its cashbox withdrawal and leaves the month approved
 * again; the audit log keeps a copy of what was deleted.
 *
 * One row per employee and month (unique), so nobody is paid twice for a
 * month. A punch corrected after approval does not change the kept figures;
 * month() reports it as 'changed' so the page can say so.
 *
 * Doctors who take a share of the income (users.commission_rate, paid through
 * «دابەشکردنی داهات») are flagged: paying them a salary too would pay twice.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/HrAttendance.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/CashboxLedger.php';

class HrPayroll
{
    public const SOURCE = 'hr_payroll';
    public const METHODS = ['cash' => 'نەختینە', 'transfer' => 'گواستنەوە'];
    /** A drawn signature is a few tens of KB; this only stops abuse. */
    private const SIGNATURE_MAX_BYTES = 400000;

    private $db;
    private HrAttendance $hr;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->hr = new HrAttendance();
    }

    /** First and last day of a Y-m month. */
    public static function range(string $month): array
    {
        $from = $month . '-01';
        return [$from, date('Y-m-t', strtotime($from))];
    }

    /** "10 / 2026" */
    public static function monthLabel(string $month): string
    {
        return (int)substr($month, 5, 2) . ' / ' . substr($month, 0, 4);
    }

    /** Receipt number as printed: 12 → "0012". */
    public static function receiptNo($id): string
    {
        return str_pad((string)(int)$id, 4, '0', STR_PAD_LEFT);
    }

    /** Whether $userId may take money out of قاسەی لێزان (CashboxLedger's rule: admins). */
    public static function canUseCashbox(int $userId): bool
    {
        try {
            (new CashboxLedger())->authorize($userId, CashboxLedger::CENTER, true);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Cash in قاسەی لێزان now, in dinars (null if it cannot be read). */
    public static function cashboxBalance(): ?float
    {
        try {
            return (new CashboxLedger())->balanceOf(CashboxLedger::CENTER) / 100;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A signature as sent by the signature pad: a JPEG (or PNG) data URL, or
     * ''/null for none. Returns it unchanged or null; throws when it is not a
     * real image.
     */
    public static function cleanSignature(?string $data): ?string
    {
        $data = trim((string)$data);
        if ($data === '') {
            return null;
        }
        $bin = preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $data, $m) ? base64_decode($m[2], true) : false;
        if ($bin === false || strlen($bin) > self::SIGNATURE_MAX_BYTES || !@getimagesizefromstring($bin)) {
            throw new InvalidArgumentException('واژۆکە دروست نییە، دووبارە واژۆ بکە');
        }
        return $data;
    }

    /**
     * The month, one entry per employee who is active, worked in it, or
     * already has a payroll row — in the monthly report's order:
     *   employee, hours (net), pay (HrAttendance::pay now), saved (hr_payroll
     *   row or null), status (none|approved|paid), changed (the figures now
     *   differ from the kept ones), commission (% of income, 0 if none).
     */
    public function month(string $month): array
    {
        [$from, $to] = self::range($month);
        $employees = $this->hr->employees(['status' => '']);
        $period = $this->hr->period($employees, $from, $to);
        $saved = [];
        foreach ($this->db->fetchAll('SELECT * FROM hr_payroll WHERE pay_month = ?', [$month]) as $r) {
            $saved[(int)$r['employee_id']] = $r;
        }
        $commission = $this->commissions();

        $rows = [];
        foreach ($employees as $e) {
            $id = (int)$e['id'];
            $net = (float)($period[$id]['totals']['net'] ?? 0);
            $s = $saved[$id] ?? null;
            if ($e['status'] !== 'active' && !$s && $net <= 0) {
                continue;
            }
            $pay = HrAttendance::pay($e, $net);
            $rows[$id] = [
                'employee'   => $e,
                'hours'      => $net,
                'pay'        => $pay,
                'saved'      => $s,
                'status'     => $s ? $s['status'] : 'none',
                'changed'    => $s !== null && (abs((float)$s['calculated'] - $pay['rounded']) >= 0.5
                                                || abs((float)$s['net_hours'] - round($net, 4)) >= 0.0001),
                'commission' => (float)($commission[(int)$e['user_id']] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Approve some employees' month: $items = [employee_id => [bonus, deduction,
     * note]]. Paid ones are left alone. Returns [approved count, messages].
     */
    public function approve(string $month, array $items, int $userId): array
    {
        $rows = $this->month($month);
        $done = 0;
        $messages = [];
        foreach ($items as $employeeId => $item) {
            $r = $rows[(int)$employeeId] ?? null;
            if (!$r) {
                continue;
            }
            $msg = $this->approveOne($month, $r, $item, $userId);
            if ($msg === null) {
                $done++;
            } else {
                $messages[] = $msg;
            }
        }
        return [$done, $messages];
    }

    /**
     * Pay one employee's month: approve it with $item (bonus, deduction, note),
     * write the receipt and, as chosen, the general expense and the cashbox
     * withdrawal. Returns the receipt id.
     */
    public function payOne(string $month, int $employeeId, array $item, string $date, string $method,
                           bool $asExpense, bool $fromCashbox, ?string $signature, int $userId): int
    {
        if (!isset(self::METHODS[$method])) {
            throw new InvalidArgumentException('شێوازی پێدان هەڵبژێرە');
        }
        $signature = self::cleanSignature($signature);
        $r = $this->month($month)[$employeeId] ?? null;
        if (!$r) {
            throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
        }
        $msg = $this->approveOne($month, $r, $item, $userId);
        if ($msg !== null) {
            throw new InvalidArgumentException($msg);
        }
        $e = $r['employee'];
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $row = $this->db->fetch('SELECT * FROM hr_payroll WHERE employee_id = ? AND pay_month = ? FOR UPDATE', [$employeeId, $month]);
            if (!$row || $row['status'] !== 'approved') {
                throw new InvalidArgumentException('«' . $e['full_name'] . '»: مووچەی ئەم مانگە پێشتر دراوە');
            }
            $receipt = [
                'payroll_id'        => (int)$row['id'],
                'employee_id'       => $employeeId,
                'pay_month'         => $month,
                'employee_name'     => $e['full_name'],
                'employee_pin'      => (string)(($e['device_pin'] ?? '') !== '' ? $e['device_pin'] : $e['id']),
                'employee_type'     => $e['employee_type'],
                'department_name'   => $e['department_name'] ?? null,
                'net_hours'         => $row['net_hours'],
                'hourly_rate'       => $row['hourly_rate'],
                'basic_salary'      => $row['basic_salary'],
                'salary_percentage' => $row['salary_percentage'],
                'calculated'        => $row['calculated'],
                'bonus'             => $row['bonus'],
                'deduction'         => $row['deduction'],
                'amount'            => $row['amount'],
                'note'              => $row['note'],
                'paid_date'         => $date,
                'payment_method'    => $method,
                'as_expense'        => $asExpense ? 1 : 0,
                'from_cashbox'      => $fromCashbox ? 1 : 0,
                'signature'         => $signature,
                'signed_at'         => $signature ? $now : null,
                'created_by'        => $userId ?: null,
            ];
            $receiptId = (int)$this->db->insert('hr_salary_receipts', $receipt);
            $receipt['id'] = $receiptId;
            $expenseId = $asExpense ? $this->writeExpense(null, $receipt, $userId) : null;
            $entryId = $fromCashbox ? $this->cashOut($receipt, $userId) : null;
            $this->db->update('hr_salary_receipts', ['expense_id' => $expenseId, 'cashbox_entry_id' => $entryId], 'id = ?', [$receiptId]);
            $this->db->update('hr_payroll', [
                'status' => 'paid', 'paid_date' => $date, 'payment_method' => $method, 'paid_by' => $userId ?: null,
                'paid_at' => $now, 'expense_id' => $expenseId, 'receipt_id' => $receiptId,
            ], 'id = ?', [$row['id']]);
            $this->db->commit();
        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
        AuditLog::log('hr_payroll_pay', 'hr_salary_receipts', $receiptId, null, [
            'employee' => $e['full_name'], 'month' => $month, 'amount' => (float)$row['amount'], 'date' => $date, 'method' => $method,
            'as_expense' => $asExpense, 'expense_id' => $expenseId, 'from_cashbox' => $fromCashbox, 'cashbox_entry_id' => $entryId,
            'signed' => $signature !== null,
        ], $userId);
        return $receiptId;
    }

    /**
     * Change a paid salary: $item (bonus, deduction, note), the date, the way
     * and the two choices. The expense and the cashbox follow. Returns true
     * when the amount changed on a signed receipt, so its signature was cleared.
     */
    public function editPayment(int $receiptId, array $item, string $date, string $method,
                                bool $asExpense, bool $fromCashbox, int $userId): bool
    {
        if (!isset(self::METHODS[$method])) {
            throw new InvalidArgumentException('شێوازی پێدان هەڵبژێرە');
        }
        $this->db->beginTransaction();
        try {
            $rc = $this->db->fetch('SELECT * FROM hr_salary_receipts WHERE id = ? FOR UPDATE', [$receiptId]);
            if (!$rc || $rc['status'] !== 'valid') {
                throw new InvalidArgumentException('وەسڵەکە نەدۆزرایەوە');
            }
            $bonus = max(0.0, round((float)($item['bonus'] ?? 0)));
            $deduction = max(0.0, round((float)($item['deduction'] ?? 0)));
            $note = mb_substr(trim((string)($item['note'] ?? '')), 0, 255);
            $amount = (float)$rc['calculated'] + $bonus - $deduction;
            if ($amount <= 0) {
                throw new InvalidArgumentException('بڕی مووچە دەبێت لە سفر زیاتر بێت');
            }
            $amountChanged = abs($amount - (float)$rc['amount']) >= 0.005;
            $new = [
                'bonus' => $bonus, 'deduction' => $deduction, 'amount' => $amount, 'note' => $note !== '' ? $note : null,
                'paid_date' => $date, 'payment_method' => $method, 'as_expense' => $asExpense ? 1 : 0, 'from_cashbox' => $fromCashbox ? 1 : 0,
            ];
            $merged = $new + $rc;

            // The expense: written, brought up to date, or removed.
            $expenseId = $rc['expense_id'] ? (int)$rc['expense_id'] : null;
            if ($asExpense) {
                $expenseId = $this->writeExpense($expenseId, $merged, $userId);
            } elseif ($expenseId) {
                $this->db->delete('expenses_general', 'id = ? AND source_type = ?', [$expenseId, self::SOURCE]);
                $expenseId = null;
            }
            // The cashbox: a withdrawal whose amount changes, or that is no
            // longer wanted, is reversed; a wanted one is booked anew.
            $entryId = $rc['cashbox_entry_id'] ? (int)$rc['cashbox_entry_id'] : null;
            if ($entryId && (!$fromCashbox || $amountChanged)) {
                $this->cashBack($entryId, 'گۆڕینی وەسڵی مووچەدان ' . self::receiptNo($receiptId) . ' — ' . $rc['employee_name'], $userId);
                $entryId = null;
            }
            if ($fromCashbox && !$entryId) {
                $entryId = $this->cashOut($merged, $userId);
            }

            $new += ['expense_id' => $expenseId, 'cashbox_entry_id' => $entryId];
            $cleared = $amountChanged && !empty($rc['signature']);
            if ($amountChanged) {
                $new += ['signature' => null, 'signed_at' => null];
            }
            $this->db->update('hr_salary_receipts', $new, 'id = ?', [$receiptId]);
            if ($rc['payroll_id']) {
                $this->db->update('hr_payroll', [
                    'bonus' => $bonus, 'deduction' => $deduction, 'amount' => $amount, 'note' => $new['note'],
                    'paid_date' => $date, 'payment_method' => $method, 'expense_id' => $expenseId,
                ], 'id = ? AND receipt_id = ?', [$rc['payroll_id'], $receiptId]);
            }
            $this->db->commit();
        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
        unset($rc['signature'], $new['signature']);
        AuditLog::logChange('hr_salary_receipt_edit', 'hr_salary_receipts', $receiptId, $rc, $new + ['signature_cleared' => $cleared], $userId);
        return $cleared;
    }

    /**
     * Delete a payment: its receipt and expense go, its cashbox withdrawal is
     * reversed, and the month is approved again (ready to be paid anew).
     * Returns the deleted receipt.
     */
    public function deletePayment(int $receiptId, int $userId): array
    {
        $this->db->beginTransaction();
        try {
            $rc = $this->db->fetch('SELECT * FROM hr_salary_receipts WHERE id = ? FOR UPDATE', [$receiptId]);
            if (!$rc) {
                throw new InvalidArgumentException('وەسڵەکە نەدۆزرایەوە');
            }
            if ($rc['cashbox_entry_id']) {
                $this->cashBack((int)$rc['cashbox_entry_id'], 'سڕینەوەی وەسڵی مووچەدان ' . self::receiptNo($receiptId) . ' — ' . $rc['employee_name'], $userId);
            }
            if ($rc['expense_id']) {
                $this->db->delete('expenses_general', 'id = ? AND source_type = ?', [$rc['expense_id'], self::SOURCE]);
            }
            if ($rc['payroll_id']) {
                $this->db->update('hr_payroll', [
                    'status' => 'approved', 'paid_date' => null, 'payment_method' => null,
                    'paid_by' => null, 'paid_at' => null, 'expense_id' => null, 'receipt_id' => null,
                ], 'id = ? AND receipt_id = ?', [$rc['payroll_id'], $receiptId]);
            }
            $this->db->delete('hr_salary_receipts', 'id = ?', [$receiptId]);
            $this->db->commit();
        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
        $signed = !empty($rc['signature']);
        unset($rc['signature']);
        AuditLog::log('hr_salary_receipt_delete', 'hr_salary_receipts', $receiptId, $rc + ['signed' => $signed], null, $userId);
        return $rc;
    }

    /** Add the person's signature to a receipt that has none yet. */
    public function sign(int $receiptId, ?string $signature, int $userId): void
    {
        $signature = self::cleanSignature($signature);
        if ($signature === null) {
            throw new InvalidArgumentException('سەرەتا واژۆ بکە');
        }
        $n = $this->db->query(
            "UPDATE hr_salary_receipts SET signature = ?, signed_at = NOW()
             WHERE id = ? AND signature IS NULL AND status = 'valid'", [$signature, $receiptId])->rowCount();
        if ($n !== 1) {
            throw new InvalidArgumentException('ئەم وەسڵە پێشتر واژۆ کراوە');
        }
        AuditLog::log('hr_salary_receipt_sign', 'hr_salary_receipts', $receiptId, null, ['signed' => true], $userId);
    }

    /** Withdraw an approval that is not paid yet. */
    public function unapprove(string $month, int $employeeId, int $userId): void
    {
        $row = $this->db->fetch('SELECT * FROM hr_payroll WHERE employee_id = ? AND pay_month = ?', [$employeeId, $month]);
        if (!$row || $row['status'] !== 'approved') {
            throw new InvalidArgumentException('ئەم مووچەیە پەسەند نەکراوە یان دراوە');
        }
        $this->db->delete('hr_payroll', 'id = ?', [$row['id']]);
        AuditLog::log('hr_payroll_unapprove', 'hr_payroll', (int)$row['id'], $row, null, $userId);
    }

    /** One receipt, with who paid it; null if none. */
    public function receipt(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT r.*, u.full_name AS created_by_name, c.full_name AS cancelled_by_name
             FROM hr_salary_receipts r
             LEFT JOIN users u ON u.id = r.created_by
             LEFT JOIN users c ON c.id = r.cancelled_by
             WHERE r.id = ?', [$id]) ?: null;
    }

    /** Receipts, newest first. Filters: month (Y-m), employee_id. */
    public function receipts(array $filters = [], int $limit = 500): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['month'])) {
            $where[] = 'r.pay_month = ?';
            $params[] = $filters['month'];
        }
        if (!empty($filters['employee_id'])) {
            $where[] = 'r.employee_id = ?';
            $params[] = (int)$filters['employee_id'];
        }
        return $this->db->fetchAll(
            'SELECT r.id, r.employee_id, r.pay_month, r.employee_name, r.employee_pin, r.amount, r.paid_date, r.payment_method,
                    r.as_expense, r.expense_id, r.from_cashbox, r.status, r.created_at, (r.signature IS NOT NULL) AS signed,
                    u.full_name AS created_by_name
             FROM hr_salary_receipts r
             LEFT JOIN users u ON u.id = r.created_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.id DESC LIMIT ' . max(1, $limit),
            $params);
    }

    /** The «مووچە» general-expense category, made if it is missing. */
    public function salaryCategoryId(): int
    {
        $row = $this->db->fetch(
            "SELECT id FROM expense_categories_general WHERE category_name IN ('مووچە', 'موچە') ORDER BY is_active DESC, id LIMIT 1");
        if ($row) {
            return (int)$row['id'];
        }
        return (int)$this->db->insert('expense_categories_general', [
            'category_name' => 'مووچە', 'description' => 'مووچەی کارمەندان', 'icon' => 'fas fa-money-bill-wave',
            'color' => '#0f766e', 'is_active' => 1, 'sort_order' => 5,
        ]);
    }

    // ── internals ────────────────────────────────────────────────────

    /** null when approved (or nothing to change), else why not. */
    private function approveOne(string $month, array $r, array $item, int $userId): ?string
    {
        $e = $r['employee'];
        $name = '«' . $e['full_name'] . '»';
        $bonus = max(0.0, round((float)($item['bonus'] ?? 0)));
        $deduction = max(0.0, round((float)($item['deduction'] ?? 0)));
        $note = mb_substr(trim((string)($item['note'] ?? '')), 0, 255);
        $calculated = (float)$r['pay']['rounded'];
        $amount = $calculated + $bonus - $deduction;
        if ($amount <= 0) {
            return $name . ': بڕی مووچە سفرە یان کەمترە';
        }
        $data = [
            'net_hours'         => round($r['hours'], 4),
            'hourly_rate'       => (float)$e['hourly_rate'],
            'basic_salary'      => (float)$e['basic_salary'],
            'salary_percentage' => (float)$e['salary_percentage'],
            'calculated'        => $calculated,
            'bonus'             => $bonus,
            'deduction'         => $deduction,
            'amount'            => $amount,
            'note'              => $note !== '' ? $note : null,
        ];

        $this->db->beginTransaction();
        try {
            $old = $this->db->fetch('SELECT * FROM hr_payroll WHERE employee_id = ? AND pay_month = ? FOR UPDATE', [$e['id'], $month]);
            if ($old && $old['status'] === 'paid') {
                $this->db->rollback();
                return $name . ': مووچەی ئەم مانگە پێشتر دراوە';
            }
            if ($old && !$this->differs($old, $data)) {
                $this->db->rollback();
                return null;
            }
            $data += ['status' => 'approved', 'approved_by' => $userId ?: null, 'approved_at' => date('Y-m-d H:i:s')];
            if ($old) {
                $this->db->update('hr_payroll', $data, 'id = ?', [$old['id']]);
                $id = (int)$old['id'];
            } else {
                $id = (int)$this->db->insert('hr_payroll', $data + ['employee_id' => (int)$e['id'], 'pay_month' => $month]);
            }
            $this->db->commit();
        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
        AuditLog::log('hr_payroll_approve', 'hr_payroll', $id, $old ?: null, $data + ['employee' => $e['full_name'], 'month' => $month], $userId);
        return null;
    }

    private function differs(array $old, array $new): bool
    {
        foreach ($new as $k => $v) {
            if (is_float($v) ? abs((float)$old[$k] - $v) >= 0.00005 : (string)$old[$k] !== (string)$v) {
                return true;
            }
        }
        return false;
    }

    /** Write (or, with $expenseId, bring up to date) the general expense of receipt $rc. Returns its id. */
    private function writeExpense(?int $expenseId, array $rc, int $userId): int
    {
        $data = [
            'description'    => 'مووچەی ' . self::monthLabel($rc['pay_month']) . ' — ' . $rc['employee_name'],
            'amount'         => (float)$rc['amount'],
            'expense_date'   => $rc['paid_date'],
            'receipt_number' => 'HR-' . self::receiptNo($rc['id']),
            'payment_method' => $rc['payment_method'],
            'notes'          => 'سەرچاوە مرۆییەکان — وەسڵی مووچەدان ' . self::receiptNo($rc['id'])
                . ' · کاتژمێر ' . HrAttendance::hm((float)$rc['net_hours'], false)
                . ' · حیسابکراو ' . number_format((float)$rc['calculated'])
                . ((float)$rc['bonus'] > 0 ? ' · زیادە ' . number_format((float)$rc['bonus']) : '')
                . ((float)$rc['deduction'] > 0 ? ' · کەمکردنەوە ' . number_format((float)$rc['deduction']) : '')
                . ($rc['note'] ? ' · ' . $rc['note'] : ''),
        ];
        if ($expenseId && $this->db->fetch('SELECT id FROM expenses_general WHERE id = ?', [$expenseId])) {
            $this->db->update('expenses_general', $data, 'id = ?', [$expenseId]);
            return $expenseId;
        }
        return (int)$this->db->insert('expenses_general', $data + [
            'category_id' => $this->salaryCategoryId(), 'created_by' => $userId ?: null,
            'source_type' => self::SOURCE, 'source_id' => (int)$rc['payroll_id'],
        ]);
    }

    /** Take receipt $rc's amount out of قاسەی لێزان. Returns the cashbox entry id. */
    private function cashOut(array $rc, int $userId): int
    {
        try {
            $r = (new CashboxLedger())->move(CashboxLedger::CENTER, 'withdrawal', (string)(float)$rc['amount'],
                'مووچەی ' . self::monthLabel($rc['pay_month']) . ' — ' . $rc['employee_name'] . ' — وەسڵی مووچەدان ' . self::receiptNo($rc['id']),
                bin2hex(random_bytes(16)), $userId);
            return (int)$r['id'];
        } catch (DomainException $e) {
            throw new InvalidArgumentException('قاسەی لێزان: ' . $e->getMessage());
        }
    }

    /** Put a withdrawal back into قاسەی لێزان (a reversal entry; the journal is never rewritten). */
    private function cashBack(int $entryId, string $reason, int $userId): void
    {
        try {
            (new CashboxLedger())->reverse($entryId, $reason, $userId);
        } catch (DomainException $e) {
            throw new InvalidArgumentException('قاسەی لێزان: ' . $e->getMessage());
        }
    }

    /** users.id => commission % for the doctors paid through «دابەشکردنی داهات». */
    private function commissions(): array
    {
        try {
            $out = [];
            foreach ($this->db->fetchAll('SELECT id, commission_rate FROM users WHERE commission_rate > 0') as $u) {
                $out[(int)$u['id']] = (float)$u['commission_rate'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}
