<?php
/**
 * HrAttendance — hours and pay for سەرچاوە مرۆییەکان (hr/).
 *
 * The one place the attendance rules live. Every HR screen, the CSV export and
 * the PDF read their numbers from here, so a report can never disagree with
 * the screen it came from. (In Panjamor the salary formula was written six
 * times — the screen rounded to 250 IQD and the PDF to 1,000.)
 *
 * Rules, carried over unchanged from Panjamor's vw_work_hours and its monthly
 * report:
 *   - A work day runs 08:00 → 08:00. A punch belongs to DATE(punch_time − 8h),
 *     so a 01:30 punch-out still closes the evening before.
 *   - Worked hours are the sum of every IN that is immediately followed by an
 *     OUT on the same work day. An unpaired punch adds nothing.
 *   - Manual adjustments add (overtime) or remove (deduction) hours on a day.
 *   - Pay: hourly_rate × hours, or basic_salary when there is no hourly rate;
 *     plus salary_percentage %; rounded UP to the next ROUND_TO (1,000 IQD).
 *   - Lateness and leave do not change pay.
 *
 * Nothing is stored: hours are worked out from the punches every time.
 */

require_once __DIR__ . '/Database.php';

class HrAttendance
{
    /** Hour of the day a work day starts; earlier punches belong to the day before. */
    public const DAY_START_HOUR = 8;

    /**
     * Salaries are paid in whole 1,000-dinar steps, rounded up — the clinic's
     * choice (2026-10-24). Panjamor's screen used 250 and its PDF 1,000.
     */
    public const ROUND_TO = 1000;

    /** Used when an employee has no shift: every day but Friday. */
    public const DEFAULT_WORK_DAYS = [6, 0, 1, 2, 3, 4];

    /** PHP date('w') → Kurdish day name. */
    public const WEEKDAYS = [
        0 => 'یەکشەممە', 1 => 'دووشەممە', 2 => 'سێشەممە', 3 => 'چوارشەممە',
        4 => 'پێنجشەممە', 5 => 'هەینی', 6 => 'شەممە',
    ];

    /** Week order for pickers, starting Saturday. */
    public const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5];

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Dates ─────────────────────────────────────────────────────────

    /** The work day a punch at $dateTime belongs to (Y-m-d). */
    public static function workDate(string $dateTime): string
    {
        return date('Y-m-d', strtotime($dateTime) - self::DAY_START_HOUR * 3600);
    }

    /** Today's work day: before 08:00 it is still yesterday. */
    public static function currentWorkDate(): string
    {
        return self::workDate(date('Y-m-d H:i:s'));
    }

    /** Every Y-m-d from $from to $to inclusive. */
    public static function dateRange(string $from, string $to): array
    {
        $dates = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
            $dates[] = date('Y-m-d', $t);
        }
        return $dates;
    }

    /** "6,0,1" → [6, 0, 1]; empty → every day but Friday. */
    public static function workDays(?string $csv): array
    {
        $days = [];
        foreach (explode(',', (string)$csv) as $d) {
            if (trim($d) !== '' && ctype_digit(trim($d)) && (int)$d <= 6) {
                $days[] = (int)$d;
            }
        }
        return $days ?: self::DEFAULT_WORK_DAYS;
    }

    // ── Reading ───────────────────────────────────────────────────────

    /**
     * Employees for a report, in the monthly report's order (display_order,
     * then PIN). Filters: status ('active' default, '' for all), type,
     * department_id, ids, monthly (true: without those hidden from the sheet).
     */
    public function employees(array $filters = []): array
    {
        $where = [];
        $params = [];
        $status = $filters['status'] ?? 'active';
        if ($status !== '') {
            $where[] = 'e.status = ?';
            $params[] = $status;
        }
        if (!empty($filters['type'])) {
            $where[] = 'e.employee_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 'e.department_id = ?';
            $params[] = (int)$filters['department_id'];
        }
        if (!empty($filters['monthly'])) {
            // The monthly sheet leaves out the people hidden from it.
            $where[] = 'e.monthly_hidden = 0';
        }
        if (!empty($filters['ids'])) {
            $ids = array_map('intval', (array)$filters['ids']);
            $where[] = 'e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        return $this->db->fetchAll(
            "SELECT e.*, s.name AS shift_name, s.start_time AS shift_start, s.end_time AS shift_end,
                    s.grace_minutes AS shift_grace,
                    s.work_days, d.name AS department_name, u.username, u.full_name AS user_full_name
             FROM hr_employees e
             LEFT JOIN hr_shifts s ON s.id = e.shift_id
             LEFT JOIN hr_departments d ON d.id = e.department_id
             LEFT JOIN users u ON u.id = e.user_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY COALESCE(e.display_order, 9999), e.id',
            $params
        );
    }

    /**
     * Punches whose work day is in [$from, $to]:
     * [employee_id][work_date] => [['id','time','type','source','notes'], …] in time order.
     */
    public function punches(array $employeeIds, string $from, string $to): array
    {
        if (!$employeeIds) {
            return [];
        }
        $ids = array_map('intval', $employeeIds);
        $start = $from . sprintf(' %02d:00:00', self::DAY_START_HOUR);
        $end = date('Y-m-d', strtotime($to . ' +1 day')) . sprintf(' %02d:00:00', self::DAY_START_HOUR);
        $rows = $this->db->fetchAll(
            'SELECT id, employee_id, punch_time, punch_type, source, notes
             FROM hr_punches
             WHERE employee_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
               AND punch_time >= ? AND punch_time < ?
             ORDER BY employee_id, punch_time, id',
            array_merge($ids, [$start, $end])
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['employee_id']][self::workDate($r['punch_time'])][] = [
                'id'     => (int)$r['id'],
                'time'   => $r['punch_time'],
                'type'   => $r['punch_type'],
                'source' => $r['source'],
                'notes'  => $r['notes'],
            ];
        }
        return $out;
    }

    /** Manual hours: [employee_id][date] => ['add' => h, 'sub' => h]. */
    public function adjustments(array $employeeIds, string $from, string $to): array
    {
        if (!$employeeIds) {
            return [];
        }
        $ids = array_map('intval', $employeeIds);
        $rows = $this->db->fetchAll(
            "SELECT employee_id, adj_date,
                    SUM(CASE WHEN adj_type = 'overtime'  THEN hours ELSE 0 END) AS add_hours,
                    SUM(CASE WHEN adj_type = 'deduction' THEN hours ELSE 0 END) AS sub_hours
             FROM hr_adjustments
             WHERE employee_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
               AND adj_date BETWEEN ? AND ?
             GROUP BY employee_id, adj_date",
            array_merge($ids, [$from, $to])
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['employee_id']][$r['adj_date']] = [
                'add' => (float)$r['add_hours'],
                'sub' => (float)$r['sub_hours'],
            ];
        }
        return $out;
    }

    /** Active holidays falling in [$from, $to], yearly ones included: [date => name]. */
    public function holidays(string $from, string $to): array
    {
        $out = [];
        $rows = $this->db->fetchAll('SELECT name, holiday_date, is_recurring FROM hr_holidays WHERE is_active = 1');
        $years = range((int)substr($from, 0, 4), (int)substr($to, 0, 4));
        foreach ($rows as $h) {
            $dates = [$h['holiday_date']];
            if ((int)$h['is_recurring'] === 1) {
                foreach ($years as $y) {
                    $dates[] = $y . substr($h['holiday_date'], 4);
                }
            }
            foreach ($dates as $d) {
                if ($d >= $from && $d <= $to) {
                    $out[$d] = $h['name'];
                }
            }
        }
        return $out;
    }

    // ── Working out a day ─────────────────────────────────────────────

    /**
     * One work day's punches → hours and pairs.
     *
     * hours  : every IN immediately followed by an OUT (Panjamor's rule)
     * pairs  : what a person reads — complete, missing_out (an IN with no OUT
     *          after it) or missing_in (an OUT with no IN before it)
     * status : calculated | missing_out | missing_in | incomplete | no_data
     */
    public static function summariseDay(array $punches): array
    {
        $seconds = 0;
        $in = 0;
        $out = 0;
        $n = count($punches);
        for ($i = 0; $i < $n; $i++) {
            if ($punches[$i]['type'] === 'in') {
                $in++;
                if ($i + 1 < $n && $punches[$i + 1]['type'] === 'out') {
                    $seconds += strtotime($punches[$i + 1]['time']) - strtotime($punches[$i]['time']);
                }
            } else {
                $out++;
            }
        }

        $pairs = [];
        $open = null;
        foreach ($punches as $p) {
            if ($p['type'] === 'in') {
                if ($open !== null) {
                    $pairs[] = ['in' => $open, 'out' => null, 'hours' => 0.0, 'status' => 'missing_out'];
                }
                $open = $p;
            } elseif ($open !== null) {
                $pairs[] = [
                    'in' => $open, 'out' => $p,
                    'hours' => (strtotime($p['time']) - strtotime($open['time'])) / 3600,
                    'status' => 'complete',
                ];
                $open = null;
            } else {
                $pairs[] = ['in' => null, 'out' => $p, 'hours' => 0.0, 'status' => 'missing_in'];
            }
        }
        if ($open !== null) {
            $pairs[] = ['in' => $open, 'out' => null, 'hours' => 0.0, 'status' => 'missing_out'];
        }

        // Four decimals per day, as MySQL's SUM(seconds) / 3600.0 gave in
        // Panjamor's view, so a month's total matches its report to the dinar.
        $hours = round($seconds / 3600, 4);
        if (($in > 0 || $out > 0) && $hours > 0) {
            $status = 'calculated';
        } elseif ($in > 0 && $out === 0) {
            $status = 'missing_out';
        } elseif ($in === 0 && $out > 0) {
            $status = 'missing_in';
        } elseif ($in !== $out) {
            $status = 'incomplete';
        } else {
            $status = 'no_data';
        }

        $unpaired = 0;
        foreach ($pairs as $pair) {
            if ($pair['status'] !== 'complete') {
                $unpaired++;
            }
        }

        return [
            'hours'    => $hours,
            'in'       => $in,
            'out'      => $out,
            'first_in' => self::firstOf($punches, 'in'),
            'last_out' => self::lastOf($punches, 'out'),
            'pairs'    => $pairs,
            'unpaired' => $unpaired,
            'status'   => $status,
        ];
    }

    private static function firstOf(array $punches, string $type): ?string
    {
        foreach ($punches as $p) {
            if ($p['type'] === $type) {
                return $p['time'];
            }
        }
        return null;
    }

    private static function lastOf(array $punches, string $type): ?string
    {
        for ($i = count($punches) - 1; $i >= 0; $i--) {
            if ($punches[$i]['type'] === $type) {
                return $punches[$i]['time'];
            }
        }
        return null;
    }

    // ── Working out a period ──────────────────────────────────────────

    /**
     * Day-by-day attendance of $employees over [$from, $to].
     *
     * Returns [employee_id => ['employee' => row, 'days' => [date => day], 'totals' => […]]]
     * in the order $employees came in. A day is:
     *   day_status  worked | holiday | absent | future
     *               (worked = any punch or adjustment that day, as in Panjamor;
     *                holiday = an official holiday or not one of the shift's days)
     *   holiday     the holiday's name, or null
     *   punches, pairs, unpaired, status — from summariseDay()
     *   hours (from punches), add, sub, net = hours + add − sub
     */
    public function period(array $employees, string $from, string $to): array
    {
        $ids = array_map(fn($e) => (int)$e['id'], $employees);
        $punches = $this->punches($ids, $from, $to);
        $adjust = $this->adjustments($ids, $from, $to);
        $holidays = $this->holidays($from, $to);
        $dates = self::dateRange($from, $to);
        $today = self::currentWorkDate();

        $out = [];
        foreach ($employees as $e) {
            $id = (int)$e['id'];
            $workDays = self::workDays($e['work_days'] ?? null);
            $totals = ['hours' => 0.0, 'add' => 0.0, 'sub' => 0.0, 'net' => 0.0,
                       'worked_days' => 0, 'absent_days' => 0, 'unpaired' => 0];
            $days = [];
            foreach ($dates as $date) {
                $dayPunches = $punches[$id][$date] ?? [];
                $adj = $adjust[$id][$date] ?? ['add' => 0.0, 'sub' => 0.0];
                $sum = self::summariseDay($dayPunches);
                $hasRecord = $dayPunches || $adj['add'] > 0 || $adj['sub'] > 0;
                $holiday = $holidays[$date] ?? null;
                $isWorkDay = in_array((int)date('w', strtotime($date)), $workDays, true);

                if ($hasRecord) {
                    $status = 'worked';
                } elseif ($holiday !== null || !$isWorkDay) {
                    $status = 'holiday';
                } elseif ($date > $today) {
                    $status = 'future';
                } else {
                    $status = 'absent';
                }

                $net = $sum['hours'] + $adj['add'] - $adj['sub'];
                $days[$date] = $sum + [
                    'date'       => $date,
                    'weekday'    => (int)date('w', strtotime($date)),
                    'day_status' => $status,
                    'holiday'    => $holiday ?? (!$isWorkDay ? self::WEEKDAYS[(int)date('w', strtotime($date))] : null),
                    'punches'    => $dayPunches,
                    'add'        => $adj['add'],
                    'sub'        => $adj['sub'],
                    'net'        => $net,
                ];
                $totals['hours'] += $sum['hours'];
                $totals['add'] += $adj['add'];
                $totals['sub'] += $adj['sub'];
                $totals['net'] += $net;
                $totals['unpaired'] += $sum['unpaired'];
                if ($status === 'worked') {
                    $totals['worked_days']++;
                } elseif ($status === 'absent') {
                    $totals['absent_days']++;
                }
            }
            $out[$id] = ['employee' => $e, 'days' => $days, 'totals' => $totals];
        }
        return $out;
    }

    /** One employee's work day: ['employee' => row, 'day' => period() day], or null. */
    public function day(int $employeeId, string $date): ?array
    {
        $emp = $this->employees(['ids' => [$employeeId], 'status' => ''])[0] ?? null;
        if (!$emp) {
            return null;
        }
        return ['employee' => $emp, 'day' => $this->period([$emp], $date, $date)[$employeeId]['days'][$date]];
    }

    // ── Shift, lateness, fixes ────────────────────────────────────────

    /**
     * The shift's start and end on work day $workDate, as date-times; a shift
     * ending at or before it starts (16:00 → 00:00) ends the next day.
     * Null when the employee has no shift.
     */
    public static function shiftWindow(array $employee, string $workDate): ?array
    {
        $start = $employee['shift_start'] ?? null;
        $end = $employee['shift_end'] ?? null;
        if (!$start || !$end) {
            return null;
        }
        $endDate = $end <= $start ? date('Y-m-d', strtotime($workDate . ' +1 day')) : $workDate;
        return [
            'start' => $workDate . ' ' . $start,
            'end'   => $endDate . ' ' . $end,
            'grace' => (int)($employee['shift_grace'] ?? 15),
        ];
    }

    /**
     * Late arrival and early leave on one day, against the shift. Shown only:
     * neither changes pay.
     *   late  : minutes from shift start to the first IN; counted when more
     *           than the shift's grace minutes (Panjamor: 15)
     *   early : minutes from the last OUT to shift end, when the day ends on
     *           an OUT (an open IN has no leaving time yet)
     */
    public static function lateness(array $day, array $employee): ?array
    {
        $win = self::shiftWindow($employee, $day['date']);
        if (!$win || !$day['punches']) {
            return null;
        }
        $late = 0;
        if ($day['first_in']) {
            $mins = intdiv(max(0, strtotime($day['first_in']) - strtotime($win['start'])), 60);
            $late = $mins > $win['grace'] ? $mins : 0;
        }
        $early = 0;
        $last = end($day['punches']);
        if ($last['type'] === 'out') {
            $early = intdiv(max(0, strtotime($win['end']) - strtotime($last['time'])), 60);
        }
        return ['late' => $late, 'early' => $early, 'start' => $win['start'], 'end' => $win['end'], 'grace' => $win['grace']];
    }

    /**
     * An employee's usual first IN and last OUT ("HH:MM" or null): the median
     * over the complete days among the $days before $beforeDate. Counted on
     * the 08:00 → 08:00 work-day clock, so a 00:30 OUT sorts after 23:00.
     *
     * The clinic's shift says 08:00–17:00 but most people leave between 19:00
     * and midnight, so a missing OUT is best guessed from the person's own
     * habit, not from the shift.
     */
    public function usualTimes(int $employeeId, string $beforeDate, int $days = 30): array
    {
        static $cache = [];
        $key = $employeeId . '|' . $beforeDate . '|' . $days;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $from = date('Y-m-d', strtotime($beforeDate . ' -' . $days . ' days'));
        $to = date('Y-m-d', strtotime($beforeDate . ' -1 day'));
        $ins = [];
        $outs = [];
        foreach ($this->punches([$employeeId], $from, $to)[$employeeId] ?? [] as $list) {
            $sum = self::summariseDay($list);
            if ($sum['unpaired'] === 0 && $sum['first_in'] && $sum['last_out']) {
                $ins[] = self::workMinutes(substr($sum['first_in'], 11, 5));
                $outs[] = self::workMinutes(substr($sum['last_out'], 11, 5));
            }
        }
        return $cache[$key] = ['in' => self::medianTime($ins), 'out' => self::medianTime($outs)];
    }

    /** "HH:MM" → minutes since the work day began at 08:00. */
    private static function workMinutes(string $hhmm): int
    {
        $m = (int)substr($hhmm, 0, 2) * 60 + (int)substr($hhmm, 3, 2);
        return ($m - self::DAY_START_HOUR * 60 + 1440) % 1440;
    }

    /** Median of work-day minutes as "HH:MM"; null with fewer than 3 days to go on. */
    private static function medianTime(array $minutes): ?string
    {
        if (count($minutes) < 3) {
            return null;
        }
        sort($minutes);
        $m = ($minutes[intdiv(count($minutes), 2)] + self::DAY_START_HOUR * 60) % 1440;
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    /**
     * One-click fixes for a day whose punches do not pair up:
     *   - two INs in a row: the second was probably meant as OUT (and two
     *     OUTs: the first as IN) — offer to switch it
     *   - the day ends on an IN: offer an OUT at the person's usual leaving
     *     time ($usual, from usualTimes()) and at the shift's end
     *   - the day starts on an OUT: the same with IN and arriving
     * A time is offered only when it falls on the right side of the punch it
     * completes. Only offers: nothing changes until the user clicks one.
     */
    public static function suggestions(array $day, array $employee, array $usual = []): array
    {
        $p = $day['punches'];
        $n = count($p);
        if ($n === 0 || $day['unpaired'] === 0) {
            return [];
        }
        $out = [];
        $touched = [];
        for ($i = 0; $i + 1 < $n; $i++) {
            if ($p[$i]['type'] !== $p[$i + 1]['type']) {
                continue;
            }
            $target = $p[$i]['type'] === 'in' ? $p[$i + 1] : $p[$i];
            $new = $target['type'] === 'in' ? 'out' : 'in';
            $out[] = [
                'kind'  => 'toggle',
                'id'    => $target['id'],
                'type'  => $new,
                'label' => 'گۆڕینی ' . self::clock($target['time']) . ' بۆ ' . ($new === 'in' ? 'هاتن' : 'ڕۆیشتن'),
            ];
            $touched[$p[$i]['id']] = $touched[$p[$i + 1]['id']] = true;
            $i++;
        }
        $win = self::shiftWindow($employee, $day['date']);
        $offer = function (string $type, array $anchor, bool $after) use (&$out, $usual, $win): void {
            $at = self::workMinutes(substr($anchor['time'], 11, 5));
            $candidates = [];
            if (!empty($usual[$type])) {
                $candidates[$usual[$type]] = $type === 'out' ? 'کاتی ئاسایی ڕۆیشتنی' : 'کاتی ئاسایی هاتنی';
            }
            if ($win) {
                $shift = substr($type === 'out' ? $win['end'] : $win['start'], 11, 5);
                $candidates[$shift] = $candidates[$shift] ?? ($type === 'out' ? 'کۆتایی شەفت' : 'دەستپێکی شەفت');
            }
            foreach ($candidates as $time => $why) {
                $m = self::workMinutes((string)$time);
                if ($after ? $m > $at : $m < $at) {
                    $out[] = [
                        'kind'  => 'add',
                        'type'  => $type,
                        'time'  => (string)$time,
                        'label' => 'زیادکردنی ' . ($type === 'out' ? 'ڕۆیشتن' : 'هاتن') . ' لە ' . $time . ' (' . $why . ')',
                    ];
                }
            }
        };
        if ($p[$n - 1]['type'] === 'in' && !isset($touched[$p[$n - 1]['id']])) {
            $offer('out', $p[$n - 1], true);
        }
        if ($p[0]['type'] === 'out' && !isset($touched[$p[0]['id']])) {
            $offer('in', $p[0], false);
        }
        return $out;
    }

    // ── Pay ───────────────────────────────────────────────────────────

    /**
     * One employee's pay for $netHours, exactly as Panjamor's monthly report:
     * hourly_rate × hours, or basic_salary when there is no hourly rate; then
     * + salary_percentage %; then rounded UP to the next ROUND_TO dinars.
     */
    public static function pay(array $employee, float $netHours): array
    {
        $rate = (float)($employee['hourly_rate'] ?? 0);
        $basic = (float)($employee['basic_salary'] ?? 0);
        $fixed = $rate == 0 && $basic > 0;
        $base = $fixed ? $basic : $rate * $netHours;
        $pct = (float)($employee['salary_percentage'] ?? 0);
        $pctAmount = $base * $pct / 100;
        $total = $base + $pctAmount;
        // round() first so 1000.0000001 from float maths is not pushed to 2000.
        $rounded = ceil(round($total / self::ROUND_TO, 6)) * self::ROUND_TO;

        return [
            'rate'       => $rate,
            'basic'      => $basic,
            'fixed'      => $fixed,
            'base'       => $base,
            'pct'        => $pct,
            'pct_amount' => $pctAmount,
            'total'      => $total,
            'rounded'    => $rounded,
            'rounding'   => $rounded - $total,
        ];
    }

    // ── Formatting ────────────────────────────────────────────────────

    /** Decimal hours → "H:MM" ('' for zero), the way Panjamor's reports show them. */
    public static function hm(float $hours, bool $blankZero = true): string
    {
        $minutes = (int)round(abs($hours) * 60);
        if ($minutes === 0 && $blankZero) {
            return '';
        }
        return ($hours < 0 ? '-' : '') . intdiv($minutes, 60) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** "2026-10-05 17:03:00" → "17:03". */
    public static function clock(?string $dateTime): string
    {
        return $dateTime ? date('H:i', strtotime($dateTime)) : '';
    }
}
