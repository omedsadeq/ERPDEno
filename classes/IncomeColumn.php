<?php
/**
 * کۆڵۆمی تایبەت — columns the accountant adds to the income sheet, with an
 * amount on a doctor under each: a cost that falls on him, or money given back
 * to a patient after the day it was received.
 *
 * A column says what its amounts are (cash_out, on_doctor; see the migration
 * 2026_11_05_income_columns.sql). ds_compute_range() takes them off the doctor's
 * income, so the sheet, the daily summary and the settlement stay one figure.
 * CashboxLedger::bookIncomeColumn() moves the boxes in the same transaction.
 *
 * پسوولەی گەڕاندنەوە: an amount under a cash_out column is money handed back,
 * so it is a document of its own, on a patient and a case, never more than he
 * paid there. The price of the work comes down by the same amount: PatientLedger
 * shows the two lines side by side and what he owes does not change. The old
 * receipt is not touched: changing it would change a closed day, and the ledger
 * would move the cash a second time. A voucher is never edited, only cancelled.
 *
 * A day a doctor is already settled for is closed: its paper is signed, so an
 * entry there would change money nobody would ever pay out. Give it the day the
 * money really went, and it comes off his next settlement.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/CashboxLedger.php';

class IncomeColumn
{
    public const PERMISSION = 'income.columns';

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** False until the migration has run, so the income sheet never breaks on it. */
    public function available(): bool
    {
        return $this->db->tableExists('income_column_entries');
    }

    /**
     * What a doctor's columns take off him in a period. `total` comes off his
     * net; `onDoctor` is the part that then comes off his share whole;
     * `cashOut` is the part that left the cashboxes.
     *
     * @return array{charges: array, total: float, onDoctor: float, cashOut: float}
     */
    public static function forDoctor(Database $db, int $doctorId, string $from, string $to): array
    {
        $out = ['charges' => [], 'total' => 0.0, 'onDoctor' => 0.0, 'cashOut' => 0.0];
        if (!$db->tableExists('income_column_entries')) {
            return $out;
        }
        foreach ($db->fetchAll(
            "SELECT c.id, c.name, c.cash_out, c.on_doctor, SUM(e.amount) AS amount
             FROM income_column_entries e
             JOIN income_columns c ON c.id = e.column_id
             WHERE e.doctor_id = :uid AND e.status = 'active' AND e.entry_date BETWEEN :f AND :t
             GROUP BY c.id, c.name, c.cash_out, c.on_doctor
             ORDER BY c.id",
            ['uid' => $doctorId, 'f' => $from, 't' => $to]
        ) as $r) {
            $amount = (float)$r['amount'];
            $out['charges'][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'amount' => $amount,
                                 'cash_out' => (bool)$r['cash_out'], 'on_doctor' => (bool)$r['on_doctor']];
            $out['total'] += $amount;
            $out['onDoctor'] += $r['on_doctor'] ? $amount : 0.0;
            $out['cashOut'] += $r['cash_out'] ? $amount : 0.0;
        }
        return $out;
    }

    /** Cash given back to patients in a period (accountant/financial-summary.php). */
    public static function refundedBetween(Database $db, string $from, string $to): float
    {
        if (!$db->tableExists('income_column_entries')) {
            return 0.0;
        }
        return (float)($db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS t FROM income_column_entries
             WHERE voucher_no IS NOT NULL AND status = 'active' AND entry_date BETWEEN :f AND :t",
            ['f' => $from, 't' => $to]
        )['t'] ?? 0);
    }

    /** Cash given back to one patient, on one doctor's cases if he is the one looking. */
    public static function refundedToPatient(Database $db, int $patientId, ?int $doctorId = null): float
    {
        if (!$db->tableExists('income_column_entries')) {
            return 0.0;
        }
        return (float)($db->fetch(
            "SELECT COALESCE(SUM(e.amount), 0) AS t FROM income_column_entries e
             WHERE e.patient_id = ? AND e.voucher_no IS NOT NULL AND e.status = 'active'"
            . ($doctorId !== null ? ' AND e.doctor_id = ?' : ''),
            $doctorId !== null ? [$patientId, $doctorId] : [$patientId]
        )['t'] ?? 0);
    }

    /** The live vouchers of a case. */
    public static function refundsOfCase(Database $db, int $caseId): array
    {
        if (!$db->tableExists('income_column_entries')) {
            return [];
        }
        return $db->fetchAll(
            "SELECT id, voucher_no, entry_date, amount FROM income_column_entries
             WHERE case_id = ? AND voucher_no IS NOT NULL AND status = 'active'
             ORDER BY entry_date, id",
            [$caseId]
        );
    }

    /** The sheet's columns: the active ones, and any other that has money in the period. */
    public function shownColumns(string $from, string $to): array
    {
        if (!$this->available()) {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM income_column_entries e WHERE e.column_id = c.id) AS entries,
                    (SELECT COALESCE(SUM(e.amount), 0) FROM income_column_entries e
                     WHERE e.column_id = c.id AND e.status = 'active' AND e.entry_date BETWEEN :f AND :t) AS period_total
             FROM income_columns c
             HAVING is_active = 1 OR period_total > 0
             ORDER BY c.id",
            ['f' => $from, 't' => $to]
        );
    }

    /** Every entry of a period, cancelled ones too, oldest day first — the list under the sheet. */
    public function listEntries(string $from, string $to): array
    {
        if (!$this->available()) {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT e.*, c.name AS column_name, c.cash_out, c.on_doctor, c.is_active AS column_active,
                    d.full_name AS doctor_name, cu.full_name AS created_by_name, uu.full_name AS updated_by_name,
                    xu.full_name AS cancelled_by_name
             FROM income_column_entries e
             JOIN income_columns c ON c.id = e.column_id
             LEFT JOIN users d ON d.id = e.doctor_id
             LEFT JOIN users cu ON cu.id = e.created_by
             LEFT JOIN users uu ON uu.id = e.updated_by
             LEFT JOIN users xu ON xu.id = e.cancelled_by
             WHERE e.entry_date BETWEEN :f AND :t
             ORDER BY e.entry_date, e.id",
            ['f' => $from, 't' => $to]
        );
    }

    /** One voucher with everything its paper prints. */
    public function voucher(int $id): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $row = $this->db->fetch(
            "SELECT e.*, c.name AS column_name, c.on_doctor, d.full_name AS doctor_name, p.phone AS patient_phone,
                    p.patient_code, cu.full_name AS created_by_name, xu.full_name AS cancelled_by_name
             FROM income_column_entries e
             JOIN income_columns c ON c.id = e.column_id
             LEFT JOIN users d ON d.id = e.doctor_id
             LEFT JOIN patients p ON p.id = e.patient_id
             LEFT JOIN users cu ON cu.id = e.created_by
             LEFT JOIN users xu ON xu.id = e.cancelled_by
             WHERE e.id = :id AND e.voucher_no IS NOT NULL",
            ['id' => $id]
        );
        return $row ?: null;
    }

    /** The short line beside a column's name. */
    public static function behaviourLabel(array $column): string
    {
        return (!empty($column['cash_out']) ? 'لە قاسە دێتە خوارێ' : 'خەرجی')
            . ' · ' . (!empty($column['on_doctor']) ? 'هەمووی لەسەر دکتۆر' : 'بە ڕێژە');
    }

    private static function caseLabel(array $case): string
    {
        return trim((string)($case['case_number'] ?? '') . ' ' . (string)($case['case_name'] ?? ''));
    }

    // ── Columns ──────────────────────────────────────────────────────────

    private function column(int $id): array
    {
        $column = $this->db->fetch(
            "SELECT c.*, (SELECT COUNT(*) FROM income_column_entries e WHERE e.column_id = c.id) AS entries
             FROM income_columns c WHERE c.id = :id",
            ['id' => $id]
        );
        if (!$column) {
            throw new InvalidArgumentException('کۆڵۆمەکە نەدۆزرایەوە');
        }
        return $column;
    }

    /** Add a column ($id 0) or change one. Returns its id. */
    public function saveColumn(int $id, array $in, int $userId): int
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('ناوی کۆڵۆم بنووسە (تا ٦٠ پیت)');
        }
        $same = $this->db->fetch(
            "SELECT id FROM income_columns WHERE name = :n AND is_active = 1 AND id <> :id",
            ['n' => $name, 'id' => $id]
        );
        if ($same) {
            throw new InvalidArgumentException('کۆڵۆمێک بەم ناوە هەیە');
        }
        $data = ['name' => $name, 'cash_out' => empty($in['cash_out']) ? 0 : 1, 'on_doctor' => empty($in['on_doctor']) ? 0 : 1];
        if ($id <= 0) {
            $data['created_by'] = $userId ?: null;
            $id = (int)$this->db->insert('income_columns', $data);
            AuditLog::log('income_column_create', 'income_columns', $id, null, $data, $userId);
            return $id;
        }
        $old = $this->column($id);
        if ($old['entries'] > 0 && ((int)$old['cash_out'] !== $data['cash_out'] || (int)$old['on_doctor'] !== $data['on_doctor'])) {
            throw new InvalidArgumentException('ئەم کۆڵۆمە تۆماری هەیە، تەنها ناوەکەی دەگۆڕدرێت');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('income_columns', $data, 'id = :id', ['id' => $id]);
        AuditLog::log('income_column_update', 'income_columns', $id, $old, $data, $userId);
        return $id;
    }

    /** Gone if nothing was entered under it; otherwise off the sheet, its entries kept. */
    public function removeColumn(int $id, int $userId): void
    {
        $old = $this->column($id);
        if ($old['entries'] > 0) {
            $this->db->update('income_columns', ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        } else {
            $this->db->delete('income_columns', 'id = :id', ['id' => $id]);
        }
        AuditLog::log('income_column_remove', 'income_columns', $id, $old, null, $userId);
    }

    // ── Refunds: what can still go back ──────────────────────────────────

    /** In cents: what he paid on a case, less what has already gone back. */
    private function roomOnCase(int $caseId): int
    {
        require_once __DIR__ . '/AccountingLedger.php';
        $paid = (float)(new AccountingLedger())->getSummary($caseId)['paid'];
        $given = array_sum(array_map('floatval', array_column(self::refundsOfCase($this->db, $caseId), 'amount')));
        return max(0, (int)round(($paid - $given) * 100));
    }

    /** A patient's cases with money that can go back, for the voucher's dialog. */
    public function refundableCases(int $patientId): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT c.id, c.case_number, c.case_name, pf.doctor_id, u.full_name AS doctor_name
             FROM cases c
             JOIN patient_folders pf ON pf.id = c.folder_id
             LEFT JOIN users u ON u.id = pf.doctor_id AND u.role = 'doctor'
             WHERE pf.patient_id = :p
             ORDER BY c.id DESC",
            ['p' => $patientId]
        ) as $c) {
            $room = $this->roomOnCase((int)$c['id']);
            if ($room > 0) {
                $out[] = ['id' => (int)$c['id'], 'label' => self::caseLabel($c), 'doctor_id' => (int)$c['doctor_id'],
                          'doctor_name' => (string)$c['doctor_name'], 'room' => $room / 100];
            }
        }
        return $out;
    }

    // ── Entries ──────────────────────────────────────────────────────────

    /**
     * Clean a submitted entry. Throws InvalidArgumentException with a message
     * for the user. Amounts may be typed with thousands separators or Kurdish
     * digits («١٠٠,٠٠٠»).
     */
    public function entryFromInput(array $in): array
    {
        $column = $this->column((int)($in['column_id'] ?? 0));
        $doctorId = (int)($in['doctor_id'] ?? 0);
        if (!$this->db->fetch("SELECT id FROM users WHERE id = :id AND role = 'doctor'", ['id' => $doctorId])) {
            throw new InvalidArgumentException('دکتۆر هەڵبژێرە');
        }
        $digits = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ''];
        $date = strtr(trim((string)($in['entry_date'] ?? '')), $digits);
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('ڕێکەوت دروست نییە');
        }
        if ($date > date('Y-m-d')) {
            throw new InvalidArgumentException('ناتوانرێت بۆ ڕۆژێکی داهاتوو تۆمار بکرێت');
        }
        $amountText = preg_replace('/[\s,،]/u', '', strtr((string)($in['amount'] ?? ''), $digits));
        if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amountText) || (float)$amountText <= 0) {
            throw new InvalidArgumentException('بڕی پارە دەبێت ژمارەیەکی سەرووی سفر بێت');
        }
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['note'] ?? '')) ?? ''), 0, 255);
        $data = [
            'column_id'  => (int)$column['id'],
            'doctor_id'  => $doctorId,
            'entry_date' => $date,
            'amount'     => number_format((float)$amountText, 2, '.', ''),
            'note'       => $note !== '' ? $note : null,
        ];
        if (!$column['cash_out']) {
            return $data;
        }
        // Money going back belongs to a patient and a case, with a reason.
        $case = $this->db->fetch(
            "SELECT c.id, c.case_number, c.case_name, p.id AS patient_id, p.full_name
             FROM cases c
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients p ON p.id = pf.patient_id
             WHERE c.id = :c AND p.id = :p",
            ['c' => (int)($in['case_id'] ?? 0), 'p' => (int)($in['patient_id'] ?? 0)]
        );
        if (!$case) {
            throw new InvalidArgumentException('نەخۆش و کەیسەکە هەڵبژێرە');
        }
        if (mb_strlen($note) < 3) {
            throw new InvalidArgumentException('هۆکاری گەڕاندنەوەکە بنووسە');
        }
        return $data + [
            'patient_id'   => (int)$case['patient_id'],
            'case_id'      => (int)$case['id'],
            'patient_name' => $case['full_name'],
            'case_label'   => self::caseLabel($case),
        ];
    }

    private function entry(int $id): array
    {
        $row = $this->db->fetch(
            "SELECT e.*, c.name, c.cash_out, c.on_doctor
             FROM income_column_entries e JOIN income_columns c ON c.id = e.column_id
             WHERE e.id = :id FOR UPDATE",
            ['id' => $id]
        );
        if (!$row) {
            throw new InvalidArgumentException('تۆمارەکە نەدۆزرایەوە');
        }
        if ($row['status'] !== 'active') {
            throw new InvalidArgumentException('ئەم تۆمارە پێشتر هەڵوەشێنراوەتەوە');
        }
        return $row;
    }

    /** The doctor's box row is the lock a settlement takes, so neither slips past the other. */
    private function assertOpen(int $doctorId, string $date): void
    {
        $this->db->fetch('SELECT balance FROM cashbox_accounts WHERE account_key = ? FOR UPDATE', ['doctor:' . $doctorId]);
        $settled = $this->db->fetch(
            "SELECT settlement_no FROM income_settlements
             WHERE doctor_id = ? AND status = 'done' AND period_from <= ? AND period_to >= ? LIMIT 1",
            [$doctorId, $date, $date]
        );
        if ($settled) {
            throw new InvalidArgumentException(
                'ڕۆژی ' . $date . ' بۆ ئەم دکتۆرە حیساب کراوە (' . $settled['settlement_no'] . '). ڕێکەوتی ئەو ڕۆژە بنووسە کە پارەکە دراوە، لە حیسابی داهاتوو دەبڕدرێت'
            );
        }
    }

    private function book(array $entry, array $column, int $id, int $cents, int $userId, string $note): void
    {
        $reference = $entry['voucher_no'] ?? ('COL-' . $id);
        $label = $column['name'] . ' ' . $reference
            . (!empty($entry['patient_name']) ? ' — ' . $entry['patient_name'] : '')
            . ($note !== '' ? ' — ' . $note : '');
        (new CashboxLedger())->bookIncomeColumn(
            $id, (int)$entry['doctor_id'], $cents, (bool)$column['cash_out'], (bool)$column['on_doctor'],
            $reference, $label, (string)$entry['entry_date'], $userId ?: null
        );
    }

    public function createEntry(array $data, int $userId): int
    {
        $this->db->beginTransaction();
        try {
            $column = $this->column((int)$data['column_id']);
            if (!$column['is_active']) {
                throw new InvalidArgumentException('ئەم کۆڵۆمە لابراوە');
            }
            $this->assertOpen((int)$data['doctor_id'], $data['entry_date']);
            $cents = CashboxLedger::cents($data['amount']);
            if ($column['cash_out']) {
                // The case row is the lock: two vouchers cannot both spend the same room.
                $this->db->fetch('SELECT id FROM cases WHERE id = ? FOR UPDATE', [(int)$data['case_id']]);
                $room = $this->roomOnCase((int)$data['case_id']);
                if ($cents > $room) {
                    throw new InvalidArgumentException('بڕەکە لەو پارەیە زیاترە کە نەخۆش لەم کەیسەدا داویەتی. زۆرترین: ' . CashboxLedger::money(CashboxLedger::decimal($room)));
                }
            }
            $data['created_by'] = $userId ?: null;
            $id = (int)$this->db->insert('income_column_entries', $data);
            if ($column['cash_out']) {
                $data['voucher_no'] = 'RF-' . date('Ym') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
                $this->db->update('income_column_entries', ['voucher_no' => $data['voucher_no']], 'id = :id', ['id' => $id]);
            }
            $this->book($data, $column, $id, $cents, $userId, (string)($data['note'] ?? ''));
            AuditLog::log('income_column_entry_create', 'income_column_entries', $id, null, $data, $userId);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** A charge can be corrected; a voucher went out on paper, so it is cancelled instead. */
    public function updateEntry(int $id, array $data, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $old = $this->entry($id);
            if ($old['cash_out']) {
                throw new InvalidArgumentException('پسوولە دەستکاری ناکرێت؛ هەڵیبوەشێنەوە و دانەیەکی نوێ تۆمار بکە');
            }
            if ((int)$data['column_id'] !== (int)$old['column_id']) {
                throw new InvalidArgumentException('کۆڵۆمی تۆمارێک ناگۆڕدرێت؛ هەڵیبوەشێنەوە و لە کۆڵۆمەکەی تر تۆماری بکە');
            }
            $this->assertOpen((int)$old['doctor_id'], $old['entry_date']);
            $this->assertOpen((int)$data['doctor_id'], $data['entry_date']);
            $data['updated_by'] = $userId ?: null;
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('income_column_entries', $data, 'id = :id', ['id' => $id]);
            $this->book($data, $old, $id, CashboxLedger::cents($data['amount']), $userId, (string)($data['note'] ?? ''));
            AuditLog::log('income_column_entry_update', 'income_column_entries', $id, $old, $data, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** The money goes back to the boxes; the entry, who cancelled it and why all stay. */
    public function cancelEntry(int $id, string $reason, int $userId): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('هۆکاری هەڵوەشاندنەوە بنووسە (٣ تا ٢٥٥ پیت)');
        }
        $this->db->beginTransaction();
        try {
            $old = $this->entry($id);
            $this->assertOpen((int)$old['doctor_id'], $old['entry_date']);
            $changes = ['status' => 'cancelled', 'cancelled_by' => $userId ?: null,
                        'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason];
            $this->db->update('income_column_entries', $changes, 'id = :id', ['id' => $id]);
            $this->book($old, $old, $id, 0, $userId, 'هەڵوەشاندنەوە: ' . $reason);
            AuditLog::log('income_column_entry_cancel', 'income_column_entries', $id, $old, $changes, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
