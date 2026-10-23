<?php
/**
 * دابەشکردنی داهات — settling a doctor's income for a period.
 *
 * The figures are the doctor's daily summary's (ds_compute_range in
 * doctors/_daily_summary_lib.php), so this sheet, the summary and the income
 * report never disagree:
 *   کۆی نەقد  = cash for the period's work + old debt paid back
 *   سافی       = کۆی نەقد − lab − store expenses
 *   پشکی دکتۆر = سافی × his percentage (users.commission_rate); the rest is
 *               پشکی سەنتەر
 *   مافی سەنتەر = پشکی سەنتەر + the lab/store expenses — the centre paid the
 *               lab and the materials, so that money comes back to it too
 *               (= کۆی نەقد − پشکی دکتۆر)
 *
 * Settling moves the centre's share from the doctor's cashbox into قاسەی لێزان
 * and pays the doctor his share out of the box (CashboxLedger::settle), all in
 * one transaction, and freezes every figure of the report in the row's
 * snapshot — the signed paper can always be reprinted exactly as signed.
 * A period is settled once per doctor; cancelling (admin) reverses the cash.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Permission.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/CashboxLedger.php';
require_once __DIR__ . '/../doctors/_daily_summary_lib.php';

class IncomeSettlement
{
    public const PERMISSION = 'income.distribution';

    /** The daily-summary figures a settlement keeps (the patient list is not). */
    private const SNAPSHOT_KEYS = [
        'grossBilled', 'invoiceDisc', 'invoiceNet', 'newDebtToday', 'treatmentCash', 'chairPending',
        'oldDebtCollected', 'advanceCollected',
        'cashIn', 'labExpense', 'storeExpense', 'netAfterExpenses', 'doctorRate', 'doctorShare', 'centerShare',
        'work', 'checkupBreakdown', 'attendanceMinutes', 'attendedDay', 'workMinutes', 'examMinutes', 'idleMinutes',
        'treatmentCount', 'treatmentAmount', 'topTreatments', 'xrayCount', 'xrayPatients', 'xrayByType',
    ];

    private $db;
    private $ledger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ledger = new CashboxLedger();
    }

    /** Whole dinars (the reports' floats) → the ledger's exact cents. */
    public static function toCents(float $v): int
    {
        return CashboxLedger::cents(number_format(round($v, 2), 2, '.', ''));
    }

    /** A settlement can only cover days that are over: the end is today at the latest. */
    public static function clampPeriod(string $from, string $to): array
    {
        $today = date('Y-m-d');
        if (!dsValidDate($from) || !dsValidDate($to)) {
            throw new DomainException('بەروار نادروستە');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from > $today) {
            throw new DomainException('ناتوانرێت حیساب بۆ ماوەیەکی داهاتوو بکرێت');
        }
        return [$from, min($to, $today)];
    }

    /** May this user settle? Admin always; anyone else by the permission. */
    public function canSettle(int $actorId): bool
    {
        $actor = $this->db->fetch('SELECT role, is_active FROM users WHERE id = ?', [$actorId]);
        if (!$actor || !$actor['is_active']) {
            return false;
        }
        return $actor['role'] === 'admin' || Permission::getInstance()->hasPermission($actor['role'], self::PERMISSION);
    }

    /** A settlement of this doctor that already covers any day of the period. */
    public function overlapping(int $doctorId, string $from, string $to): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM income_settlements
             WHERE doctor_id = ? AND status = 'done' AND period_from <= ? AND period_to >= ?
             ORDER BY period_from LIMIT 1",
            [$doctorId, $to, $from]
        ) ?: null;
    }

    /**
     * Everything the settlement dialog and the report need, before anything is
     * written: the figures, the doctor's cash now, and the suggested amounts.
     * Amounts are in cents.
     */
    public function preview(int $doctorId, string $from, string $to): array
    {
        [$from, $to] = self::clampPeriod($from, $to);
        $doctor = $this->db->fetch("SELECT id, full_name FROM users WHERE id = ? AND role = 'doctor'", [$doctorId]);
        if (!$doctor) {
            throw new DomainException('دکتۆر نەدۆزرایەوە');
        }
        $ds = ds_compute_range($this->db, $doctorId, $from, $to, false);
        $box = $this->ledger->balanceOf('doctor:' . $doctorId);
        $doctorShare = $ds['doctorShare'] !== null ? max(0, self::toCents($ds['doctorShare'])) : 0;
        $centerShare = $ds['centerShare'] !== null ? max(0, self::toCents($ds['centerShare'])) : 0;
        $expenses = self::toCents($ds['labExpense'] + $ds['storeExpense']);
        // What قاسەی لێزان is owed: everything taken in except the doctor's share.
        // With the per-receipt split on (CashboxLedger::splitEnabled) the centre
        // got its part — and the doctor's part of the lab/store costs — the
        // moment the money was booked, so nothing is left to transfer: the
        // doctor's box holds only his share, and settling just pays it out.
        $centerDue = CashboxLedger::splitEnabled() ? 0 : max(0, self::toCents($ds['cashIn']) - $doctorShare);
        $transfer = min($centerDue, max(0, $box));
        $payout = min($doctorShare, max(0, $box - $transfer));
        return [
            'doctor_id'   => $doctorId,
            'doctor_name' => $doctor['full_name'],
            'from'        => $from,
            'to'          => $to,
            'ds'          => $ds,
            'rate'        => $ds['doctorRate'],
            'box'         => $box,
            'shares'      => ['doctor' => $doctorShare, 'center' => $centerShare, 'expenses' => $expenses, 'center_due' => $centerDue],
            'defaults'    => ['transfer' => $transfer, 'payout' => $payout],
            'existing'    => $this->overlapping($doctorId, $from, $to),
        ];
    }

    /**
     * Settle: record the period, move what the centre is owed to قاسەی لێزان and
     * pay the doctor. $transfer / $payout are what is really moved now (the
     * form's strings, in dinars) — never more than مافی سەنتەر / پشکی دکتۆر,
     * never more than the box holds.
     *
     * @return array{id:int, settlement_no:string}
     */
    public function create(int $doctorId, string $from, string $to, $transfer, $payout, string $notes, int $actorId): array
    {
        if (!$this->canSettle($actorId)) {
            throw new DomainException('دەسەڵاتی دابەشکردنی داهاتت نییە');
        }
        [$from, $to] = self::clampPeriod($from, $to);
        $transferCents = CashboxLedger::cents(is_string($transfer) && trim($transfer) === '' ? '0' : $transfer);
        $payoutCents = CashboxLedger::cents(is_string($payout) && trim($payout) === '' ? '0' : $payout);
        $notes = trim(preg_replace('/\s+/u', ' ', $notes) ?? '');
        if (mb_strlen($notes) > 600) {
            throw new DomainException('تێبینی زۆر درێژە');
        }
        $key = 'doctor:' . $doctorId;

        $this->db->beginTransaction();
        try {
            $this->ledger->balanceOf($key);
            // One settlement per doctor at a time: his box row is the lock.
            $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key = ? FOR UPDATE', [$key]);
            if ($prior = $this->overlapping($doctorId, $from, $to)) {
                throw new DomainException('ئەم ماوەیە پێشتر حیسابی بۆ کراوە (' . $prior['settlement_no'] . ')');
            }
            $p = $this->preview($doctorId, $from, $to);
            if ($p['rate'] === null) {
                throw new DomainException('ڕێژەی ئەم دکتۆرە دیاری نەکراوە — سەرەتا لە ئەکاونتەکەی دیاری بکە');
            }
            if ($transferCents < 0 || $payoutCents < 0) {
                throw new DomainException('بڕەکان نابێت لە سفر کەمتر بن');
            }
            if ($transferCents > $p['shares']['center_due']) {
                throw new DomainException('گواستنەوە لە مافی سەنتەر (پشک + خەرجی) زیاترە');
            }
            if ($payoutCents > $p['shares']['doctor']) {
                throw new DomainException('ڕاکێشان لە پشکی دکتۆر زیاترە');
            }
            $ds = $p['ds'];
            $snapshot = array_intersect_key($ds, array_flip(self::SNAPSHOT_KEYS));
            $snapshot['doctorName'] = $p['doctor_name'];

            $id = (int)$this->db->insert('income_settlements', [
                'settlement_no' => 'TMP-' . bin2hex(random_bytes(8)),
                'doctor_id'     => $doctorId,
                'period_from'   => $from,
                'period_to'     => $to,
                'doctor_rate'   => $p['rate'],
                'cash_in'       => CashboxLedger::decimal(self::toCents($ds['cashIn'])),
                'lab_expense'   => CashboxLedger::decimal(self::toCents($ds['labExpense'])),
                'store_expense' => CashboxLedger::decimal(self::toCents($ds['storeExpense'])),
                'net_amount'    => CashboxLedger::decimal(self::toCents($ds['netAfterExpenses'])),
                'doctor_share'  => CashboxLedger::decimal(self::toCents($ds['doctorShare'])),
                'center_share'  => CashboxLedger::decimal(self::toCents($ds['centerShare'])),
                'transferred_to_center' => CashboxLedger::decimal($transferCents),
                'paid_to_doctor'        => CashboxLedger::decimal($payoutCents),
                'snapshot'      => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'notes'         => $notes !== '' ? $notes : null,
                'status'        => 'done',
                'created_by'    => $actorId,
            ]);
            $number = 'IS-' . date('Ym') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $moved = $this->ledger->settle($key, $transferCents, $payoutCents, $number, $p['doctor_name'], $actorId);
            $this->db->update('income_settlements', [
                'settlement_no'         => $number,
                'box_before'            => CashboxLedger::decimal($moved['before']),
                'box_after'             => CashboxLedger::decimal($moved['after']),
                'transfer_out_entry_id' => $moved['transfer_out'],
                'transfer_in_entry_id'  => $moved['transfer_in'],
                'payout_entry_id'       => $moved['payout'],
            ], 'id = ?', [$id]);
            AuditLog::log('income_settlement_created', 'income_settlements', $id, null, [
                'settlement_no' => $number, 'doctor_id' => $doctorId, 'from' => $from, 'to' => $to,
                'transferred' => CashboxLedger::decimal($transferCents), 'paid' => CashboxLedger::decimal($payoutCents),
            ], $actorId);
            $this->db->commit();
            return ['id' => $id, 'settlement_no' => $number];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** Cancel (admin): the cash goes back where it came from; the row stays as history. */
    public function cancel(int $id, string $reason, int $actorId): void
    {
        $actor = $this->db->fetch('SELECT role FROM users WHERE id = ?', [$actorId]);
        if (($actor['role'] ?? '') !== 'admin') {
            throw new DomainException('تەنها ئەدمین دەتوانێت حیساب هەڵبوەشێنێتەوە');
        }
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 600) {
            throw new DomainException('هۆکاری هەڵوەشاندنەوە بنووسە');
        }
        $this->db->beginTransaction();
        try {
            $s = $this->db->fetch('SELECT * FROM income_settlements WHERE id = ? FOR UPDATE', [$id]);
            if (!$s) {
                throw new DomainException('حیساب نەدۆزرایەوە');
            }
            if ($s['status'] !== 'done') {
                throw new DomainException('ئەم حیسابە پێشتر هەڵوەشێنراوەتەوە');
            }
            // The centre's box first: it is the one that must still hold the money.
            $this->ledger->unsettle([$s['transfer_in_entry_id'], $s['transfer_out_entry_id'], $s['payout_entry_id']],
                'هەڵوەشاندنەوەی حیسابی ' . $s['settlement_no'] . ' — ' . $reason, $actorId);
            $this->db->update('income_settlements', [
                'status' => 'cancelled', 'cancelled_by' => $actorId,
                'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason,
            ], 'id = ?', [$id]);
            AuditLog::log('income_settlement_cancelled', 'income_settlements', $id, ['status' => 'done'],
                ['status' => 'cancelled', 'reason' => $reason], $actorId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** «واژووکرا»: the printed report came back signed by the doctor. */
    public function markSigned(int $id, int $actorId, bool $signed = true): void
    {
        if (!$this->canSettle($actorId)) {
            throw new DomainException('دەسەڵاتت نییە');
        }
        $s = $this->db->fetch('SELECT id, status FROM income_settlements WHERE id = ?', [$id]);
        if (!$s || $s['status'] !== 'done') {
            throw new DomainException('حیساب نەدۆزرایەوە');
        }
        $this->db->update('income_settlements', [
            'signed_at' => $signed ? date('Y-m-d H:i:s') : null,
            'signed_marked_by' => $signed ? $actorId : null,
        ], 'id = ?', [$id]);
        AuditLog::log($signed ? 'income_settlement_signed' : 'income_settlement_unsigned', 'income_settlements', $id, null, null, $actorId);
    }

    /** One settlement with names and its frozen report. */
    public function get(int $id): ?array
    {
        $s = $this->db->fetch(
            "SELECT s.*, d.full_name AS doctor_name, c.full_name AS created_by_name,
                    x.full_name AS cancelled_by_name, g.full_name AS signed_marked_by_name
             FROM income_settlements s
             LEFT JOIN users d ON d.id = s.doctor_id
             LEFT JOIN users c ON c.id = s.created_by
             LEFT JOIN users x ON x.id = s.cancelled_by
             LEFT JOIN users g ON g.id = s.signed_marked_by
             WHERE s.id = ?",
            [$id]
        );
        if (!$s) {
            return null;
        }
        $s['report'] = json_decode((string)$s['snapshot'], true) ?: [];
        return $s;
    }

    /** Settlements that touch the period, newest first. */
    public function forPeriod(string $from, string $to): array
    {
        return $this->db->fetchAll(
            "SELECT s.*, d.full_name AS doctor_name, c.full_name AS created_by_name
             FROM income_settlements s
             LEFT JOIN users d ON d.id = s.doctor_id
             LEFT JOIN users c ON c.id = s.created_by
             WHERE s.period_from <= ? AND s.period_to >= ?
             ORDER BY s.created_at DESC, s.id DESC",
            [$to, $from]
        );
    }
}
