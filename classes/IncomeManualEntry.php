<?php
/**
 * داهاتی دەستی — pharmacy and examination money typed in by hand on the
 * income sheet, for what the system did not record (a sale not rung up in
 * the pharmacy, an examination fee not put on a visit).
 *
 * accountant/_income_data.php ADDS these on top of the automatic figures, so
 * the pharmacy row = erp2 sales + manual, examinations = visits.price +
 * manual, and the sheet shows both parts. Report-only: no cashbox moves.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class IncomeManualEntry
{
    public const PERMISSION = 'income.manual';

    /** kind => label on the sheet */
    public const KINDS = [
        'pharmacy' => 'دەرمانخانە',
        'exam'     => 'پشکنین',
    ];

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** False until the migration has run, so the income sheet never breaks on it. */
    public function available(): bool
    {
        return $this->db->tableExists('income_manual_entries');
    }

    /**
     * Totals per kind for a period.
     * @return array<string, array{amount: float, qty: int, entries: int}>
     */
    public function totals(string $from, string $to): array
    {
        $out = array_fill_keys(array_keys(self::KINDS), ['amount' => 0.0, 'qty' => 0, 'entries' => 0]);
        if (!$this->available()) {
            return $out;
        }
        foreach ($this->db->fetchAll(
            "SELECT kind, COALESCE(SUM(amount), 0) AS amt, COALESCE(SUM(qty), 0) AS q, COUNT(*) AS n
             FROM income_manual_entries
             WHERE entry_date BETWEEN :f AND :t
             GROUP BY kind",
            ['f' => $from, 't' => $to]
        ) as $r) {
            if (isset($out[$r['kind']])) {
                $out[$r['kind']] = ['amount' => (float)$r['amt'], 'qty' => (int)$r['q'], 'entries' => (int)$r['n']];
            }
        }
        return $out;
    }

    /** Every entry of a period, oldest day first — the list under the sheet. */
    public function listRange(string $from, string $to): array
    {
        if (!$this->available()) {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT m.*, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
             FROM income_manual_entries m
             LEFT JOIN users cu ON cu.id = m.created_by
             LEFT JOIN users uu ON uu.id = m.updated_by
             WHERE m.entry_date BETWEEN :f AND :t
             ORDER BY m.entry_date, m.kind, m.id",
            ['f' => $from, 't' => $to]
        );
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetch("SELECT * FROM income_manual_entries WHERE id = :id", ['id' => $id]);
        return $row ?: null;
    }

    /**
     * Clean a submitted entry. Throws InvalidArgumentException with a message
     * for the user. Amounts may be typed with thousands separators or Kurdish
     * digits («١٢٥,٠٠٠»).
     */
    public static function fromInput(array $in): array
    {
        $kind = (string)($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('جۆر هەڵبژێرە: دەرمانخانە یان پشکنین');
        }
        $digits = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ''];
        $date = strtr(trim((string)($in['entry_date'] ?? '')), $digits);
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('ڕێکەوت دروست نییە');
        }
        if ($date > date('Y-m-d')) {
            throw new InvalidArgumentException('ناتوانرێت داهات بۆ ڕۆژێکی داهاتوو تۆمار بکرێت');
        }
        $amountText = preg_replace('/[\s,،]/u', '', strtr((string)($in['amount'] ?? ''), $digits));
        if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amountText) || (float)$amountText <= 0) {
            throw new InvalidArgumentException('بڕی پارە دەبێت ژمارەیەکی سەرووی سفر بێت');
        }
        $qtyText = trim(strtr((string)($in['qty'] ?? ''), $digits));
        if ($qtyText !== '' && !ctype_digit($qtyText)) {
            throw new InvalidArgumentException('ژمارە (دانە) دەبێت ژمارەیەکی تەواو بێت');
        }
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['note'] ?? ''))), 0, 255);
        return [
            'kind'       => $kind,
            'entry_date' => $date,
            'amount'     => round((float)$amountText, 2),
            'qty'        => $qtyText === '' ? 0 : min(100000, (int)$qtyText),
            'note'       => $note !== '' ? $note : null,
        ];
    }

    public function create(array $data, int $userId): int
    {
        $data['created_by'] = $userId ?: null;
        $id = (int)$this->db->insert('income_manual_entries', $data);
        AuditLog::log('income_manual_create', 'income_manual_entries', $id, null, $data);
        return $id;
    }

    public function update(int $id, array $data, int $userId): void
    {
        $old = $this->find($id);
        if (!$old) {
            throw new InvalidArgumentException('تۆمارەکە نەدۆزرایەوە');
        }
        $data['updated_by'] = $userId ?: null;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('income_manual_entries', $data, 'id = :id', ['id' => $id]);
        AuditLog::logChange('income_manual_update', 'income_manual_entries', $id, $old, $data);
    }

    public function delete(int $id): void
    {
        $old = $this->find($id);
        if (!$old) {
            return;
        }
        $this->db->delete('income_manual_entries', 'id = :id', ['id' => $id]);
        AuditLog::log('income_manual_delete', 'income_manual_entries', $id, $old, null);
    }
}
