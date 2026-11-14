<?php
/**
 * حساباتی مانگانەی دکتۆر — a doctor's own monthly paper, typed in by hand
 * (admin/doctor-ledger.php, admin/doctor-ledger-print.php). Nothing here reads
 * or changes anything else in the system; only the doctor's name is taken
 * from users.
 *
 * The paper: the summary lines (each added, or taken away when its sign is −)
 * make «کۆی گشتی»; the private-expense lines make «خەرجی تایبەتی»; what is
 * left is «ماوە». Dinars and dollars are counted apart.
 */

class DoctorLedger {

    /** A month as YYYY-MM, or this month when it is not one. */
    public static function month(?string $m): string {
        return (is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) ? $m : date('Y-m');
    }

    public static function monthLabel(string $month): string {
        [$y, $m] = explode('-', $month);
        return 'مانگی ' . (int)$m . 'ی ' . $y;
    }

    public static function doctors(): array {
        return Database::getInstance()->fetchAll(
            "SELECT id, full_name, is_active FROM users WHERE role = 'doctor' ORDER BY is_active DESC, full_name");
    }

    public static function doctor(int $id): ?array {
        return Database::getInstance()->fetch(
            "SELECT id, full_name FROM users WHERE id = ? AND role = 'doctor'", [$id]) ?: null;
    }

    public static function sheet(int $doctorId, string $month): ?array {
        return Database::getInstance()->fetch(
            "SELECT * FROM doctor_ledger_sheets WHERE doctor_id = ? AND month = ?", [$doctorId, $month]) ?: null;
    }

    /** ['summary' => [...], 'detail' => [...]] in their order. */
    public static function lines(int $sheetId): array {
        $out = ['summary' => [], 'detail' => []];
        $rows = Database::getInstance()->fetchAll(
            "SELECT * FROM doctor_ledger_lines WHERE sheet_id = ? ORDER BY section, sort_order, id", [$sheetId]);
        foreach ($rows as $r) {
            $out[$r['section'] === 'summary' ? 'summary' : 'detail'][] = $r;
        }
        return $out;
    }

    /**
     * What a month that has no paper yet starts with: the lines of the doctor's
     * latest paper before it, names only (no amounts), or the three lines of the
     * paper this page was made from.
     */
    public static function starterLines(array $doctor, string $month): array {
        $db = Database::getInstance();
        $prev = $db->fetch(
            "SELECT id, month FROM doctor_ledger_sheets WHERE doctor_id = ? AND month < ? ORDER BY month DESC LIMIT 1",
            [(int)$doctor['id'], $month]);
        if ($prev) {
            $lines = self::lines((int)$prev['id']);
            foreach ($lines as &$section) {
                foreach ($section as &$l) {
                    $l['amount_iqd'] = 0;
                    $l['amount_usd'] = 0;
                    $l['note'] = '';
                }
            }
            return ['lines' => $lines, 'from' => $prev['month']];
        }
        $line = fn($label) => ['label' => $label, 'amount_iqd' => 0, 'amount_usd' => 0, 'sign' => 1, 'note' => ''];
        return ['lines' => [
            'summary' => [
                $line('ماوەی 80% حسابی ' . $doctor['full_name']),
                $line('ماوەی لێزان'),
                $line('کرێ'),
            ],
            'detail' => [],
        ], 'from' => null];
    }

    /** Totals of a paper, dinars and dollars apart. */
    public static function totals(array $lines): array {
        $t = ['total_iqd' => 0.0, 'total_usd' => 0.0, 'private_iqd' => 0.0, 'private_usd' => 0.0];
        foreach ($lines['summary'] as $l) {
            $sign = ((int)($l['sign'] ?? 1)) < 0 ? -1 : 1;
            $t['total_iqd'] += $sign * (float)$l['amount_iqd'];
            $t['total_usd'] += $sign * (float)$l['amount_usd'];
        }
        foreach ($lines['detail'] as $l) {
            $t['private_iqd'] += (float)$l['amount_iqd'];
            $t['private_usd'] += (float)$l['amount_usd'];
        }
        $t['left_iqd'] = $t['total_iqd'] - $t['private_iqd'];
        $t['left_usd'] = $t['total_usd'] - $t['private_usd'];
        return $t;
    }

    /** A figure as typed: commas and spaces dropped, Kurdish/Arabic digits read. */
    public static function number($v): float {
        $v = strtr((string)$v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5',
            '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '', ',' => '', ' ' => '', '$' => '']);
        return is_numeric($v) ? round((float)$v, 2) : 0.0;
    }

    /** Write the whole paper of a month: its note and every line, replacing what was there. */
    public static function save(int $doctorId, string $month, array $summary, array $detail, string $note, int $userId): void {
        $db = Database::getInstance();
        $clean = function (array $rows, string $section) {
            $out = [];
            foreach ($rows as $r) {
                $label = trim(mb_substr((string)($r['label'] ?? ''), 0, 255));
                $iqd = self::number($r['iqd'] ?? 0);
                $usd = self::number($r['usd'] ?? 0);
                $rowNote = trim(mb_substr((string)($r['note'] ?? ''), 0, 500));
                if ($label === '' && $iqd == 0 && $usd == 0 && $rowNote === '') {
                    continue; // an empty row left on the form
                }
                $out[] = [
                    'section' => $section,
                    'label' => $label,
                    'amount_iqd' => $iqd,
                    'amount_usd' => $usd,
                    'sign' => ($section === 'summary' && (int)($r['sign'] ?? 1) < 0) ? -1 : 1,
                    'note' => $rowNote,
                ];
            }
            return $out;
        };
        $rows = array_merge($clean($summary, 'summary'), $clean($detail, 'detail'));
        $now = date('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            $sheet = self::sheet($doctorId, $month);
            if ($sheet) {
                $sheetId = (int)$sheet['id'];
                $db->update('doctor_ledger_sheets',
                    ['note' => mb_substr(trim($note), 0, 500), 'updated_by' => $userId, 'updated_at' => $now],
                    'id = ?', [$sheetId]);
                $db->delete('doctor_ledger_lines', 'sheet_id = ?', [$sheetId]);
            } else {
                $db->insert('doctor_ledger_sheets', [
                    'doctor_id' => $doctorId, 'month' => $month, 'note' => mb_substr(trim($note), 0, 500),
                    'created_by' => $userId, 'created_at' => $now, 'updated_by' => $userId, 'updated_at' => $now,
                ]);
                $sheetId = (int)$db->lastInsertId();
            }
            foreach ($rows as $i => $r) {
                $db->insert('doctor_ledger_lines', $r + ['sheet_id' => $sheetId, 'sort_order' => $i]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    public static function delete(int $doctorId, string $month): void {
        $db = Database::getInstance();
        $sheet = self::sheet($doctorId, $month);
        if (!$sheet) return;
        $db->beginTransaction();
        try {
            $db->delete('doctor_ledger_lines', 'sheet_id = ?', [(int)$sheet['id']]);
            $db->delete('doctor_ledger_sheets', 'id = ?', [(int)$sheet['id']]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /** The months the doctor has a paper for, latest first, with what was left. */
    public static function months(int $doctorId): array {
        $db = Database::getInstance();
        $sheets = $db->fetchAll(
            "SELECT id, month FROM doctor_ledger_sheets WHERE doctor_id = ? ORDER BY month DESC", [$doctorId]);
        foreach ($sheets as &$s) {
            $s['totals'] = self::totals(self::lines((int)$s['id']));
        }
        return $sheets;
    }

    /** A figure for the paper: blank when nothing, no decimals unless it has them. */
    public static function fmt($v, bool $usd = false): string {
        $v = (float)$v;
        if (abs($v) < 0.005) return '';
        $dec = (abs($v - round($v)) >= 0.005) ? 2 : 0;
        return ($v < 0 ? '-' : '') . ($usd ? '$' : '') . number_format(abs($v), $dec);
    }
}
