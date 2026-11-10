<?php
/**
 * داتای پێشوو — a month's income paper from the clinic's other system (2025),
 * kept here so «بەراورد» (accountant/income-compare.php) can set this year
 * against it. Typed or pasted in on accountant/income-archive.php.
 *
 * A doctor of this system is stored as row_key d<users.id>: his line lines up
 * with his row today. X-ray, Pharmacy, Examinations and Other keep their own
 * keys; a line of a doctor who is not in this system gets x<hash of its name>.
 *
 * period() answers a comparison period made of stored months as the same
 * array the income sheet gives it (income_compare_period), so the paper and
 * its «بەراوردی پار ساڵ» come out the same way. A month cut by the period
 * counts for its share of days.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';

class IncomeArchive
{
    public const PERMISSION = 'income.archive';

    /** The rows that are not doctors: key => label, in the paper's order. */
    public const DEPTS = ['pharmacy' => 'Pharmacy', 'xray' => 'X-ray', 'exam' => 'Examinations', 'other' => 'Other'];

    /** Money columns, then counts: field => label (the paper's headings). */
    public const MONEY = [
        'amount' => 'داهات', 'lab' => 'تاقیگە', 'store_normal' => 'کۆگا', 'store_normal_doc' => 'کۆگا دکتۆر',
        'store_implant' => 'ئیمپلانت', 'store_implant_doc' => 'ئیمپلانت دکتۆر', 'net' => 'ماوە',
        'doctor_share' => 'ماوەی دکتۆر', 'center_share' => 'ماوەی لێزان',
    ];
    public const COUNTS = ['days' => 'ڕۆژ', 'pending' => 'داواکراو', 'given' => 'پێدراو', 'old_exam' => 'کۆن', 'new_exam' => 'نوێ', 'cases_given' => 'کەیسی پێدراو'];

    /**
     * The owner's paper «لیستی داهات و سەردانیکەران و کاتی هاتنی دکتۆرەکان بۆ
     * ساڵی 2025» — one month of 2025 as the old system gave it, the same for
     * every month of the year. A doctor is found here by his name (ids differ
     * from one install to another); one not found keeps a line of his own.
     * Pharmacy, X-ray and Examinations count their whole income as ماوە and
     * لێزان's, as the sheet does; «Other» is only لێزان's, as on the paper.
     */
    public const TEMPLATE_2025 = [
        'head' => ['expenses' => 22856000, 'deductions' => 3500000, 'note' => 'لە پەڕەی ساڵی 2025 (مووچەی نورالدین و محمد لە لێبڕینی تر)'],
        'rows' => [
            ['match' => ['هێرش', 'هيرش'], 'label' => 'د.هێرش', 'amount' => 175192000, 'lab' => 11275000, 'store_normal' => 2147000, 'store_normal_doc' => 1073000,
             'store_implant' => 16677000, 'store_implant_doc' => 8338000, 'net' => 145093000, 'rate' => 80, 'doctor_share' => 116074000, 'center_share' => 29018000,
             'minutes' => '157:00', 'days' => 15, 'pending' => 140, 'given' => 2, 'old_exam' => 67, 'new_exam' => 75, 'cases_given' => 0],
            ['match' => ['شوان'], 'label' => 'د.شوان', 'amount' => 10136000, 'lab' => 377000, 'store_normal' => 316000, 'store_normal_doc' => 158000,
             'store_implant' => 26000, 'store_implant_doc' => 13000, 'net' => 9417000, 'rate' => 50, 'doctor_share' => 4708000, 'center_share' => 4708000,
             'minutes' => '108:00', 'days' => 19, 'pending' => 15, 'given' => 10, 'old_exam' => 8, 'new_exam' => 17, 'cases_given' => 18],
            ['match' => ['دانا'], 'label' => 'د.دانا', 'amount' => 69525000, 'lab' => 6605000, 'store_normal' => 1596000, 'store_normal_doc' => 798000,
             'store_implant' => 6070000, 'store_implant_doc' => 3035000, 'net' => 55254000, 'rate' => 50, 'doctor_share' => 27627000, 'center_share' => 27627000,
             'minutes' => '240:00', 'days' => 24, 'pending' => 82, 'given' => 16, 'old_exam' => 36, 'new_exam' => 62, 'cases_given' => 7],
            ['match' => ['ڕێناس', 'رێناس', 'ريناس'], 'label' => 'د.ڕێناس', 'amount' => 21295000, 'lab' => 750000, 'store_normal' => 831000, 'store_normal_doc' => 415000,
             'store_implant' => 1044000, 'store_implant_doc' => 522000, 'net' => 18670000, 'rate' => 50, 'doctor_share' => 9335000, 'center_share' => 9335000,
             'minutes' => '255:00', 'days' => 23, 'pending' => 55, 'given' => 16, 'old_exam' => 18, 'new_exam' => 53, 'cases_given' => 10],
            ['match' => ['کاژین', 'كاژین'], 'label' => 'د.کاژین', 'amount' => 4400000, 'lab' => 2000, 'store_normal' => 458000, 'store_normal_doc' => 229000,
             'store_implant' => 0, 'store_implant_doc' => 0, 'net' => 3940000, 'rate' => 50, 'doctor_share' => 1970000, 'center_share' => 1970000,
             'minutes' => '138:00', 'days' => 19, 'pending' => 31, 'given' => 14, 'old_exam' => 22, 'new_exam' => 23, 'cases_given' => 8],
            ['match' => [], 'label' => 'sxp', 'amount' => 246000, 'net' => 246000, 'rate' => 50, 'doctor_share' => 123000, 'center_share' => 123000],
            ['dept' => 'pharmacy', 'amount' => 1178000, 'net' => 1178000, 'center_share' => 1178000],
            ['dept' => 'xray', 'amount' => 6677000, 'net' => 6677000, 'center_share' => 6677000],
            ['dept' => 'exam', 'amount' => 352000, 'net' => 352000, 'center_share' => 352000],
            ['dept' => 'other', 'center_share' => 208000],
        ],
    ];

    /** A name for matching: Arabic letters folded into Kurdish, no spaces, dots or joiners. */
    private static function fold(string $s): string
    {
        $s = strtr($s, ['ك' => 'ک', 'ي' => 'ی', 'ى' => 'ی', 'ة' => 'ە', 'ڕ' => 'ر']);
        return preg_replace('/[\s.\x{200C}\x{200D}]+/u', '', $s);
    }

    /** The template as save() takes it, its doctors found among this system's. */
    public static function templateRows(): array
    {
        $doctors = Database::getInstance()->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor' ORDER BY is_active DESC, id");
        $rows = [];
        foreach (self::TEMPLATE_2025['rows'] as $i => $t) {
            $fields = array_diff_key($t, array_flip(['match', 'label', 'dept']));
            if (isset($t['dept'])) {
                $rows[$t['dept']] = $fields;
                continue;
            }
            $key = null;
            foreach ($t['match'] as $name) {
                foreach ($doctors as $d) {
                    if (mb_strpos(self::fold($d['full_name']), self::fold($name)) !== false) {
                        $key = 'd' . $d['id'];
                        break 2;
                    }
                }
            }
            $rows[$key ?? 'tpl' . $i] = $fields + ($key === null ? ['label' => $t['label']] : []);
        }
        return $rows;
    }

    /**
     * Fill months from the template. A month that already holds data is kept
     * unless $overwrite. Returns [filled months, kept months].
     */
    public static function applyTemplate(array $months, bool $overwrite, int $userId): array
    {
        $kept = self::months();
        $rows = self::templateRows();
        $filled = $skipped = [];
        foreach ($months as $m) {
            if (isset($kept[$m]) && !$overwrite) {
                $skipped[] = $m;
                continue;
            }
            self::save($m, self::TEMPLATE_2025['head'], $rows, $userId);
            $filled[] = $m;
        }
        return [$filled, $skipped];
    }

    public static function available(): bool
    {
        return Database::getInstance()->tableExists('income_archive_rows');
    }

    /** The months kept: month => [amount, center, rows]. */
    public static function months(): array
    {
        if (!self::available()) {
            return [];
        }
        $out = [];
        foreach (Database::getInstance()->fetchAll(
            'SELECT m.month, COALESCE(SUM(r.amount), 0) AS amount, COALESCE(SUM(r.center_share), 0) AS center, COUNT(r.id) AS n
             FROM income_archive_months m LEFT JOIN income_archive_rows r ON r.month = m.month
             GROUP BY m.month ORDER BY m.month'
        ) as $r) {
            $out[$r['month']] = ['amount' => (float)$r['amount'], 'center' => (float)$r['center'], 'rows' => (int)$r['n']];
        }
        return $out;
    }

    /** One month: ['head' => month row or null, 'rows' => row_key => row]. */
    public static function month(string $month): array
    {
        $db = Database::getInstance();
        if (!self::available()) {
            return ['head' => null, 'rows' => []];
        }
        $rows = [];
        foreach ($db->fetchAll('SELECT * FROM income_archive_rows WHERE month = ? ORDER BY sort_order, id', [$month]) as $r) {
            $rows[$r['row_key']] = $r;
        }
        return ['head' => $db->fetch('SELECT * FROM income_archive_months WHERE month = ?', [$month]) ?: null, 'rows' => $rows];
    }

    /** A number from the form: «1,234,000», Kurdish digits, blank → 0. */
    public static function num($v): float
    {
        $v = strtr(trim((string)$v), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        $v = preg_replace('/[\s,،%]/u', '', $v);
        if ($v === '' || in_array(mb_strtolower($v), ['نەبوو', 'نهبوو', 'نیه', 'نییە', '-', '—'], true)) {
            return 0.0;
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            throw new InvalidArgumentException('ژمارەیەکی هەڵە: «' . $v . '»');
        }
        return (float)$v;
    }

    /** Hours as typed: «175:38», «175.5» or minutes left blank → null. */
    public static function minutes($v): ?int
    {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d+):(\d{1,2})(?::\d{1,2})?$/', $v, $m)) {
            return (int)$m[1] * 60 + (int)$m[2];
        }
        return (int)round(self::num($v) * 60);
    }

    /**
     * Keep a month: $head (expenses, deductions, note) and $rows (row_key => fields
     * + label). A row with nothing in it is left out. Replaces what the month had.
     */
    public static function save(string $month, array $head, array $rows, int $userId): void
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('مانگ دروست نییە');
        }
        $db = Database::getInstance();
        $clean = [];
        $sort = 0;
        foreach ($rows as $key => $in) {
            $key = (string)$key;
            $label = trim(preg_replace('/\s+/u', ' ', (string)($in['label'] ?? '')) ?? '');
            if (preg_match('/^d(\d+)$/', $key, $m)) {
                $u = $db->fetch("SELECT full_name FROM users WHERE id = ? AND role = 'doctor'", [(int)$m[1]]);
                if (!$u) {
                    continue;
                }
                $kind = 'doctor';
                $label = $label !== '' ? $label : $u['full_name'];
            } elseif (isset(self::DEPTS[$key])) {
                $kind = 'dept';
                $label = self::DEPTS[$key];
            } else {
                if ($label === '') {
                    continue;
                }
                $kind = 'doctor';
                $key = 'x' . substr(md5(mb_strtolower($label)), 0, 10);
            }
            $row = ['month' => $month, 'row_key' => $key, 'kind' => $kind, 'label' => mb_substr($label, 0, 80), 'sort_order' => $sort++];
            $any = false;
            foreach (array_keys(self::MONEY) as $f) {
                $row[$f] = round(self::num($in[$f] ?? ''), 2);
                $any = $any || abs($row[$f]) >= 0.5;
            }
            foreach (array_keys(self::COUNTS) as $f) {
                $row[$f] = (int)round(self::num($in[$f] ?? ''));
                $any = $any || $row[$f] !== 0;
            }
            $row['minutes'] = self::minutes($in['minutes'] ?? '');
            $rate = trim((string)($in['rate'] ?? ''));
            $row['rate'] = $rate === '' ? null : max(0, min(100, self::num($rate)));
            if ($any || $row['minutes']) {
                $clean[$key] = $row;
            }
        }
        $db->beginTransaction();
        try {
            $old = self::month($month);
            $db->query('DELETE FROM income_archive_rows WHERE month = ?', [$month]);
            $db->query('DELETE FROM income_archive_months WHERE month = ?', [$month]);
            $db->insert('income_archive_months', [
                'month' => $month,
                'expenses' => round(self::num($head['expenses'] ?? ''), 2),
                'deductions' => round(self::num($head['deductions'] ?? ''), 2),
                'note' => mb_substr(trim((string)($head['note'] ?? '')), 0, 255) ?: null,
                'updated_by' => $userId ?: null, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($clean as $row) {
                $db->insert('income_archive_rows', $row);
            }
            AuditLog::log('income_archive_save', 'income_archive_months', 0, ['month' => $month, 'rows' => count($old['rows'])],
                ['month' => $month, 'rows' => count($clean)], $userId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    public static function delete(string $month, int $userId): void
    {
        $db = Database::getInstance();
        $db->query('DELETE FROM income_archive_rows WHERE month = ?', [$month]);
        $db->query('DELETE FROM income_archive_months WHERE month = ?', [$month]);
        AuditLog::log('income_archive_delete', 'income_archive_months', 0, ['month' => $month], null, $userId);
    }

    /**
     * A comparison period made of kept months, as income_compare_period() gives
     * one: rows, expenses, the centre after them. null when a month of it is
     * not kept. A month the period only cuts counts for its share of days.
     */
    public static function period(string $from, string $to): ?array
    {
        if (!self::available()) {
            return null;
        }
        $kept = self::months();
        $start = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new DateTimeImmutable($to);
        $parts = [];   // month => share of it
        for ($m = $start; $m <= $end; $m = $m->modify('+1 month')) {
            $key = $m->format('Y-m');
            if (!isset($kept[$key])) {
                return null;
            }
            $mFrom = max($from, $m->format('Y-m-01'));
            $mTo = min($to, $m->format('Y-m-t'));
            $days = (int)$m->format('t');
            $parts[$key] = ((strtotime($mTo) - strtotime($mFrom)) / 86400 + 1) / $days;
        }
        if (!$parts) {
            return null;
        }
        $db = Database::getInstance();
        $names = array_column($db->fetchAll("SELECT id, full_name FROM users WHERE role = 'doctor'"), 'full_name', 'id');
        $rows = [];
        $expenses = 0.0;
        $sort = [];
        foreach ($parts as $month => $f) {
            $data = self::month($month);
            $expenses += ((float)($data['head']['expenses'] ?? 0) + (float)($data['head']['deductions'] ?? 0)) * $f;
            foreach ($data['rows'] as $key => $r) {
                $isDoctor = $r['kind'] === 'doctor';
                $outKey = $key;
                if (!isset($rows[$outKey])) {
                    $uid = preg_match('/^d(\d+)$/', $key, $mm) ? (int)$mm[1] : null;
                    $rows[$outKey] = [
                        'kind' => $isDoctor ? 'doctor' : $key, 'id' => $uid,
                        'label' => $uid && isset($names[$uid]) ? $names[$uid] : $r['label'],
                        'count' => 0, 'amount' => 0.0, 'lab' => 0.0, 'store' => 0.0, 'general' => 0.0,
                        'net' => 0.0, 'doctorShare' => 0.0, 'centerShare' => 0.0, 'rate' => null,
                        'report' => $isDoctor ? ['storeByKind' => ['normal' => 0.0, 'implant' => 0.0], 'storeOnDoctorByKind' => ['normal' => 0.0, 'implant' => 0.0],
                                                 'minutes' => null, 'days' => 0.0,
                                                 'checkups' => ['pending' => 0.0, 'given' => 0.0, 'old_exam' => 0.0, 'new_exam' => 0.0], 'casesGiven' => 0.0] : null,
                    ];
                    $sort[$outKey] = [$isDoctor ? 0 : 1, (int)$r['sort_order']];
                }
                $o = &$rows[$outKey];
                $o['amount'] += $r['amount'] * $f;
                $o['lab'] += $r['lab'] * $f;
                $o['store'] += ($r['store_normal'] + $r['store_implant']) * $f;
                $o['net'] += $r['net'] * $f;
                $o['doctorShare'] += $r['doctor_share'] * $f;
                $o['centerShare'] += $r['center_share'] * $f;
                if ($r['rate'] !== null) {
                    $o['rate'] = (float)$r['rate'];
                }
                if ($isDoctor) {
                    $o['report']['storeByKind']['normal'] += $r['store_normal'] * $f;
                    $o['report']['storeByKind']['implant'] += $r['store_implant'] * $f;
                    $o['report']['storeOnDoctorByKind']['normal'] += $r['store_normal_doc'] * $f;
                    $o['report']['storeOnDoctorByKind']['implant'] += $r['store_implant_doc'] * $f;
                    if ($r['minutes'] !== null) {
                        $o['report']['minutes'] = ($o['report']['minutes'] ?? 0) + $r['minutes'] * $f;
                    }
                    $o['report']['days'] += $r['days'] * $f;
                    foreach (['pending', 'given', 'old_exam', 'new_exam'] as $c) {
                        $o['report']['checkups'][$c] += $r[$c] * $f;
                    }
                    $o['report']['casesGiven'] += $r['cases_given'] * $f;
                }
                unset($o);
            }
        }
        uksort($rows, fn($a, $b) => $sort[$a] <=> $sort[$b]);
        $center = array_sum(array_column($rows, 'centerShare'));
        return [
            'from' => $from, 'to' => $to, 'days' => (int)round((strtotime($to) - strtotime($from)) / 86400) + 1,
            'rows' => $rows, 'totals' => [], 'expenses' => $expenses, 'centerAfter' => $center - $expenses,
            'archived' => array_keys($parts),
            // how many months it holds (a cut month counts for its share): its
            // monthly average is the sum over this, as the paper averages a year
            'months' => array_sum($parts),
        ];
    }
}
