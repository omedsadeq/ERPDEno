<?php
/**
 * HrPunch — every change to a punch, in one place.
 *
 * Used by the day editor (hr/api/day.php) and so by every screen that opens
 * it. Each change is written to the activity log with the old and new row.
 * Mistakes the user can fix throw InvalidArgumentException with a Kurdish
 * message; anything else is a real error.
 *
 * Times are entered against a WORK day (08:00 → 08:00): "01:30" on the 5th's
 * work day is stored as the 6th at 01:30, so it still closes the 5th.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/HrAttendance.php';

class HrPunch
{
    /** A second quick punch this soon after the last one is a double press. */
    public const QUICK_GAP_SECONDS = 60;

    public const TYPE_LABELS = ['in' => 'هاتن', 'out' => 'ڕۆیشتن'];

    /** "17:03" or "17:03:20" → "17:03:20", or null when it is not a time. */
    public static function cleanTime(?string $time): ?string
    {
        if (!is_string($time) || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $m)) {
            return null;
        }
        [$h, $i, $s] = [(int)$m[1], (int)$m[2], (int)($m[3] ?? 0)];
        if ($h > 23 || $i > 59 || $s > 59) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', $h, $i, $s);
    }

    /** The real date-time of $time on work day $workDate (before 08:00 = the next calendar day). */
    public static function dateTimeFor(string $workDate, string $time): string
    {
        $date = (int)substr($time, 0, 2) < HrAttendance::DAY_START_HOUR
            ? date('Y-m-d', strtotime($workDate . ' +1 day'))
            : $workDate;
        return $date . ' ' . $time;
    }

    public static function find(int $id): array
    {
        $row = Database::getInstance()->fetch('SELECT * FROM hr_punches WHERE id = ?', [$id]);
        if (!$row) {
            throw new InvalidArgumentException('پەنجەمۆرەکە نەدۆزرایەوە');
        }
        return $row;
    }

    /** Add a manual punch. Returns its id. */
    public static function add(int $employeeId, string $dateTime, string $type, ?string $notes, int $userId): int
    {
        $db = Database::getInstance();
        $emp = $db->fetch('SELECT id, full_name FROM hr_employees WHERE id = ?', [$employeeId]);
        if (!$emp) {
            throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
        }
        if (!isset(self::TYPE_LABELS[$type])) {
            throw new InvalidArgumentException('جۆری پەنجەمۆر هەڵبژێرە: هاتن یان ڕۆیشتن');
        }
        $row = [
            'employee_id' => $employeeId,
            'punch_time'  => $dateTime,
            'punch_type'  => $type,
            'source'      => 'manual',
            'notes'       => self::cleanNote($notes),
            'created_by'  => $userId ?: null,
        ];
        try {
            $id = (int)$db->insert('hr_punches', $row);
        } catch (PDOException $ex) {
            self::rethrowDuplicate($ex);
        }
        AuditLog::log('hr_punch_add', 'hr_punches', $id, null, $row + ['employee_name' => $emp['full_name']]);
        return $id;
    }

    /** Move a punch to another time on the SAME work day. */
    public static function setTime(int $id, string $time, int $userId): void
    {
        $old = self::find($id);
        $new = self::dateTimeFor(HrAttendance::workDate($old['punch_time']), $time);
        self::update($old, ['punch_time' => $new], $userId);
    }

    /** IN ↔ OUT. Returns the new type. */
    public static function toggle(int $id, int $userId): string
    {
        $old = self::find($id);
        $type = $old['punch_type'] === 'in' ? 'out' : 'in';
        self::update($old, ['punch_type' => $type], $userId);
        return $type;
    }

    public static function setNote(int $id, ?string $notes, int $userId): void
    {
        self::update(self::find($id), ['notes' => self::cleanNote($notes)], $userId);
    }

    public static function delete(int $id): void
    {
        $old = self::find($id);
        Database::getInstance()->delete('hr_punches', 'id = ?', [$id]);
        AuditLog::log('hr_punch_delete', 'hr_punches', $id, $old, null);
    }

    /**
     * «تۆماری خێرا»: a punch at this moment, for when the device is down.
     *
     * $type 'auto' takes the opposite of the employee's last punch today (IN
     * when there is none). An explicit type that repeats the last punch of the
     * day, or any punch within a minute of the last one, is refused: those are
     * the double presses Panjamor's quick punch guarded against.
     */
    public static function quick(string $pin, string $type, int $userId): array
    {
        $db = Database::getInstance();
        $emp = self::byPin($pin, true);
        if (!$emp) {
            throw new InvalidArgumentException('هیچ کارمەندێکی چالاک بەم PIN ـە نییە');
        }
        $employeeId = (int)$emp['id'];
        $now = date('Y-m-d H:i:s');
        $workDate = HrAttendance::workDate($now);
        $dayStart = $workDate . sprintf(' %02d:00:00', HrAttendance::DAY_START_HOUR);
        $last = $db->fetch(
            'SELECT punch_type, punch_time FROM hr_punches
             WHERE employee_id = ? AND punch_time >= ? AND punch_time <= ?
             ORDER BY punch_time DESC, id DESC LIMIT 1',
            [$employeeId, $dayStart, $now]
        );
        if ($last && strtotime($now) - strtotime($last['punch_time']) < self::QUICK_GAP_SECONDS) {
            throw new InvalidArgumentException('«' . $emp['full_name'] . '» ئێستا پەنجەمۆری لێدراوە (' . HrAttendance::clock($last['punch_time']) . ')');
        }
        if ($type === 'auto') {
            $type = ($last && $last['punch_type'] === 'in') ? 'out' : 'in';
        } elseif (!isset(self::TYPE_LABELS[$type])) {
            throw new InvalidArgumentException('جۆری پەنجەمۆر هەڵبژێرە');
        } elseif ($last && $last['punch_type'] === $type) {
            throw new InvalidArgumentException('دوایین پەنجەمۆری ئەمڕۆی «' . $emp['full_name'] . '» ' . self::TYPE_LABELS[$type]
                . ' بووە (' . HrAttendance::clock($last['punch_time']) . ')');
        }
        $id = self::add($employeeId, $now, $type, 'تۆماری خێرا', $userId);
        return ['id' => $id, 'type' => $type, 'time' => $now, 'name' => $emp['full_name']];
    }

    /**
     * The employee who uses $pin on the fingerprint device: their device_pin,
     * else (for a record without one) their id. Null when nobody does.
     */
    public static function byPin(string $pin, bool $activeOnly = false): ?array
    {
        $pin = trim($pin);
        if ($pin === '') {
            return null;
        }
        $db = Database::getInstance();
        $active = $activeOnly ? " AND status = 'active'" : '';
        $row = $db->fetch('SELECT id, full_name, status FROM hr_employees WHERE device_pin = ?' . $active, [$pin]);
        if (!$row && ctype_digit($pin)) {
            $row = $db->fetch("SELECT id, full_name, status FROM hr_employees WHERE id = ? AND (device_pin IS NULL OR device_pin = '')" . $active, [(int)$pin]);
        }
        return $row ?: null;
    }

    private static function update(array $old, array $changes, int $userId): void
    {
        $changes['updated_by'] = $userId ?: null;
        try {
            Database::getInstance()->update('hr_punches', $changes, 'id = ?', [(int)$old['id']]);
        } catch (PDOException $ex) {
            self::rethrowDuplicate($ex);
        }
        AuditLog::logChange('hr_punch_update', 'hr_punches', (int)$old['id'], $old, $changes);
    }

    private static function cleanNote(?string $notes): ?string
    {
        $notes = trim((string)$notes);
        return $notes === '' ? null : mb_substr($notes, 0, 255);
    }

    /** The unique key on (employee, time) means a punch already sits at that second. */
    private static function rethrowDuplicate(PDOException $ex): void
    {
        if ((int)($ex->errorInfo[1] ?? 0) === 1062) {
            throw new InvalidArgumentException('ئەم کارمەندە لەو کاتەدا پەنجەمۆرێکی تری هەیە');
        }
        throw $ex;
    }
}
