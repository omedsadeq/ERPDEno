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
             WHERE e.doctor_id = :uid AND e.entry_date BETWEEN :f AND :t
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
                     WHERE e.column_id = c.id AND e.entry_date BETWEEN :f AND :t) AS period_total
             FROM income_columns c
             HAVING is_active = 1 OR period_total > 0
             ORDER BY c.id",
            ['f' => $from, 't' => $to]
        );
    }

    /** Every entry of a period, oldest day first — the list under the sheet. */
    public function listEntries(string $from, string $to): array
    {
        if (!$this->available()) {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT e.*, c.name AS column_name, c.cash_out, c.on_doctor, c.is_active AS column_active,
                    d.full_name AS doctor_name, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
             FROM income_column_entries e
             JOIN income_columns c ON c.id = e.column_id
             LEFT JOIN users d ON d.id = e.doctor_id
             LEFT JOIN users cu ON cu.id = e.created_by
             LEFT JOIN users uu ON uu.id = e.updated_by
             WHERE e.entry_date BETWEEN :f AND :t
             ORDER BY e.entry_date, e.id",
            ['f' => $from, 't' => $to]
        );
    }

    /** The short line under a column's title on the sheet. */
    public static function behaviourLabel(array $column): string
    {
        return (!empty($column['cash_out']) ? 'لە قاسە دێتە خوارێ' : 'خەرجی')
            . ' · ' . (!empty($column['on_doctor']) ? 'هەمووی لەسەر دکتۆر' : 'بە ڕێژە');
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
        // Cash leaving a box needs a reason, as every other cash movement does.
        if ($column['cash_out'] && mb_strlen($note) < 3) {
            throw new InvalidArgumentException('تێبینی بنووسە: ناوی نەخۆش و هۆکاری گەڕاندنەوەکە');
        }
        return [
            'column_id'  => (int)$column['id'],
            'doctor_id'  => $doctorId,
            'entry_date' => $date,
            'amount'     => number_format((float)$amountText, 2, '.', ''),
            'note'       => $note !== '' ? $note : null,
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

    private function book(array $entry, array $column, int $id, int $cents, int $userId): void
    {
        $label = $column['name'] . ' COL-' . $id . ($entry['note'] ? ' — ' . $entry['note'] : '');
        (new CashboxLedger())->bookIncomeColumn(
            $id, (int)$entry['doctor_id'], $cents, (bool)$column['cash_out'], (bool)$column['on_doctor'],
            $label, (string)$entry['entry_date'], $userId ?: null
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
            $data['created_by'] = $userId ?: null;
            $id = (int)$this->db->insert('income_column_entries', $data);
            $this->book($data, $column, $id, CashboxLedger::cents($data['amount']), $userId);
            AuditLog::log('income_column_entry_create', 'income_column_entries', $id, null, $data, $userId);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function updateEntry(int $id, array $data, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $old = $this->entry($id);
            if ((int)$data['column_id'] !== (int)$old['column_id']) {
                throw new InvalidArgumentException('کۆڵۆمی تۆمارێک ناگۆڕدرێت؛ بیسڕەوە و لە کۆڵۆمەکەی تر تۆماری بکە');
            }
            $this->assertOpen((int)$old['doctor_id'], $old['entry_date']);
            $this->assertOpen((int)$data['doctor_id'], $data['entry_date']);
            $data['updated_by'] = $userId ?: null;
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('income_column_entries', $data, 'id = :id', ['id' => $id]);
            $this->book($data, $old, $id, CashboxLedger::cents($data['amount']), $userId);
            AuditLog::log('income_column_entry_update', 'income_column_entries', $id, $old, $data, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** The money goes back to the boxes; the journal keeps both movements. */
    public function deleteEntry(int $id, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $old = $this->entry($id);
            $this->assertOpen((int)$old['doctor_id'], $old['entry_date']);
            $this->book($old, $old, $id, 0, $userId);
            $this->db->delete('income_column_entries', 'id = :id', ['id' => $id]);
            AuditLog::log('income_column_entry_delete', 'income_column_entries', $id, $old, null, $userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
