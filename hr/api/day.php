<?php
/**
 * سەرچاوە مرۆییەکان — JSON for the day editor (hr/_day_editor.php).
 *
 *   GET  ?action=day&employee_id=&date=      one work day, ready to draw
 *   POST action=add          employee_id, date, time, type, notes
 *        action=set_time     id, time            (stays on the same work day)
 *        action=toggle       id                  (IN ↔ OUT)
 *        action=note         id, notes
 *        action=delete       id
 *        action=quick        pin, type (in|out|auto) — a punch at this moment
 *        action=adj_add      employee_id, date, adj_type, hours, reason
 *        action=adj_delete   id
 *
 * Every write answers with the refreshed day, so the editor redraws from the
 * same numbers the reports will show. Reading needs hr.reports.view or
 * hr.punches.manage; punches need hr.punches.manage; hours need
 * hr.adjustments.manage.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/HrPunch.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function hr_api_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function hr_api_need(string ...$keys): void
{
    foreach ($keys as $key) {
        if (hr_can($key)) {
            return;
        }
    }
    hr_api_out(['success' => false, 'message' => 'ڕێگەپێدراو نیت'], 403);
}

/** One work day as the editor draws it. */
function hr_api_day(int $employeeId, string $date): array
{
    $hr = new HrAttendance();
    $found = $hr->day($employeeId, $date);
    if (!$found) {
        throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
    }
    $usual = $hr->usualTimes($employeeId, $date);
    ['employee' => $e, 'day' => $d] = $found;
    $adjust = Database::getInstance()->fetchAll(
        'SELECT a.id, a.adj_type, a.hours, a.reason, u.full_name AS by_name
         FROM hr_adjustments a LEFT JOIN users u ON u.id = a.created_by
         WHERE a.employee_id = ? AND a.adj_date = ? ORDER BY a.id',
        [$employeeId, $date]
    );
    $late = HrAttendance::lateness($d, $e);
    $win = HrAttendance::shiftWindow($e, $date);
    // The editor's add form starts on the time most likely needed: the
    // person's usual time, else the shift's.
    $last = $d['punches'] ? end($d['punches']) : null;
    $nextType = ($last && $last['type'] === 'in') ? 'out' : 'in';
    $nextTime = $usual[$nextType] ?? ($win ? substr($nextType === 'in' ? $win['start'] : $win['end'], 11, 5) : '');

    return [
        'employee'    => [
            'id' => (int)$e['id'], 'pin' => hr_pin($e), 'name' => $e['full_name'], 'type' => $e['employee_type'],
            'shift' => (string)$e['shift_name'],
            'shift_time' => $e['shift_name'] ? substr((string)$e['shift_start'], 0, 5) . '–' . substr((string)$e['shift_end'], 0, 5) : '',
        ],
        'date'        => $date,
        'weekday'     => HrAttendance::WEEKDAYS[$d['weekday']],
        'status'      => $d['day_status'],
        'holiday'     => $d['day_status'] === 'holiday' ? $d['holiday'] : null,
        'punches'     => array_map(fn($p) => [
            'id'       => $p['id'],
            'time'     => substr($p['time'], 11, 8),
            'next_day' => substr($p['time'], 0, 10) !== $date,
            'type'     => $p['type'],
            'source'   => $p['source'],
            'notes'    => (string)$p['notes'],
        ], $d['punches']),
        'pairs'       => array_map(fn($pr) => [
            'in'    => $pr['in'] ? HrAttendance::clock($pr['in']['time']) : null,
            'out'   => $pr['out'] ? HrAttendance::clock($pr['out']['time']) : null,
            'hours' => $pr['status'] === 'complete' ? HrAttendance::hm($pr['hours'], false) : '',
            'ok'    => $pr['status'] === 'complete',
        ], $d['pairs']),
        'hours'       => HrAttendance::hm($d['hours'], false),
        'add'         => $d['add'] > 0 ? HrAttendance::hm($d['add'], false) : '',
        'sub'         => $d['sub'] > 0 ? HrAttendance::hm($d['sub'], false) : '',
        'net'         => HrAttendance::hm($d['net'], false),
        'unpaired'    => $d['unpaired'],
        'late'        => $late ? $late['late'] : 0,
        'early'       => $late ? $late['early'] : 0,
        'suggestions' => hr_can('hr.punches.manage') ? HrAttendance::suggestions($d, $e, $usual) : [],
        'usual'       => $usual,
        'next'        => ['type' => $nextType, 'time' => $nextTime],
        'adjustments' => array_map(fn($a) => [
            'id' => (int)$a['id'], 'type' => $a['adj_type'], 'hours' => HrAttendance::hm((float)$a['hours'], false),
            'reason' => (string)$a['reason'], 'by' => (string)($a['by_name'] ?? ''),
        ], $adjust),
        'can'         => ['punches' => hr_can('hr.punches.manage'), 'adjustments' => hr_can('hr.adjustments.manage')],
    ];
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
    if (!$isPost) {
        hr_api_need('hr.reports.view', 'hr.punches.manage');
        $date = hr_date($_GET['date'] ?? null);
        if ($action !== 'day' || !$date) {
            throw new InvalidArgumentException('داواکاری نادروست');
        }
        hr_api_out(['success' => true, 'day' => hr_api_day((int)($_GET['employee_id'] ?? 0), $date)]);
    }

    Csrf::require();
    $db = Database::getInstance();
    $id = (int)($_POST['id'] ?? 0);
    $message = '';

    switch ($action) {
        case 'add':
            hr_api_need('hr.punches.manage');
            $empId = (int)($_POST['employee_id'] ?? 0);
            $date = hr_date($_POST['date'] ?? null);
            $time = HrPunch::cleanTime($_POST['time'] ?? null);
            if (!$date || !$time) {
                throw new InvalidArgumentException('کاتێکی دروست بنووسە');
            }
            HrPunch::add($empId, HrPunch::dateTimeFor($date, $time), (string)($_POST['type'] ?? ''), $_POST['notes'] ?? null, $hrUserId);
            $message = 'پەنجەمۆرەکە زیادکرا';
            break;

        case 'set_time':
        case 'toggle':
        case 'note':
        case 'delete':
            hr_api_need('hr.punches.manage');
            $old = HrPunch::find($id);
            $empId = (int)$old['employee_id'];
            $date = HrAttendance::workDate($old['punch_time']);
            if ($action === 'set_time') {
                $time = HrPunch::cleanTime($_POST['time'] ?? null);
                if (!$time) {
                    throw new InvalidArgumentException('کاتێکی دروست بنووسە');
                }
                HrPunch::setTime($id, $time, $hrUserId);
                $message = 'کاتەکە گۆڕدرا';
            } elseif ($action === 'toggle') {
                $message = 'کرا بە ' . HrPunch::TYPE_LABELS[HrPunch::toggle($id, $hrUserId)];
            } elseif ($action === 'note') {
                HrPunch::setNote($id, $_POST['notes'] ?? null, $hrUserId);
                $message = 'تێبینی پاشەکەوت کرا';
            } else {
                HrPunch::delete($id);
                $message = 'پەنجەمۆرەکە سڕایەوە';
            }
            break;

        case 'quick':
            hr_api_need('hr.punches.manage');
            $r = HrPunch::quick((string)($_POST['pin'] ?? ''), (string)($_POST['type'] ?? 'auto'), $hrUserId);
            hr_api_out([
                'success' => true,
                'message' => HrPunch::TYPE_LABELS[$r['type']] . 'ی «' . $r['name'] . '» لە ' . HrAttendance::clock($r['time']) . ' تۆمارکرا',
                'punch'   => ['type' => $r['type'], 'time' => HrAttendance::clock($r['time']), 'name' => $r['name']],
            ]);
            // no break: hr_api_out() ends the request

        case 'adj_add':
            hr_api_need('hr.adjustments.manage');
            $empId = (int)($_POST['employee_id'] ?? 0);
            $date = hr_date($_POST['date'] ?? null);
            $type = (string)($_POST['adj_type'] ?? '');
            $hours = round((float)str_replace(',', '.', (string)($_POST['hours'] ?? '0')), 2);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $max = ['overtime' => 12, 'deduction' => 8];
            if (!$date || !isset($max[$type])) {
                throw new InvalidArgumentException('جۆر و ڕێکەوت هەڵبژێرە');
            }
            if ($hours <= 0 || $hours > $max[$type]) {
                throw new InvalidArgumentException('کاتژمێر دەبێت لە نێوان 0 و ' . $max[$type] . ' بێت');
            }
            if ($reason === '') {
                throw new InvalidArgumentException('هۆکار پێویستە');
            }
            if (!$db->fetch('SELECT id FROM hr_employees WHERE id = ?', [$empId])) {
                throw new InvalidArgumentException('کارمەند نەدۆزرایەوە');
            }
            $row = ['employee_id' => $empId, 'adj_date' => $date, 'adj_type' => $type, 'hours' => $hours,
                    'reason' => mb_substr($reason, 0, 2000), 'created_by' => $hrUserId ?: null];
            AuditLog::log('hr_adjustment_add', 'hr_adjustments', (int)$db->insert('hr_adjustments', $row), null, $row);
            $message = ($type === 'overtime' ? 'زیادە' : 'لێبڕین') . ' تۆمارکرا';
            break;

        case 'adj_delete':
            hr_api_need('hr.adjustments.manage');
            $old = $db->fetch('SELECT * FROM hr_adjustments WHERE id = ?', [$id]);
            if (!$old) {
                throw new InvalidArgumentException('تۆمارەکە نەدۆزرایەوە');
            }
            $db->delete('hr_adjustments', 'id = ?', [$id]);
            AuditLog::log('hr_adjustment_delete', 'hr_adjustments', $id, $old, null);
            [$empId, $date] = [(int)$old['employee_id'], $old['adj_date']];
            $message = 'تۆمارەکە سڕایەوە';
            break;

        default:
            throw new InvalidArgumentException('داواکاری نادروست');
    }

    hr_api_out(['success' => true, 'message' => $message, 'day' => hr_api_day($empId, $date)]);
} catch (InvalidArgumentException $ex) {
    hr_api_out(['success' => false, 'message' => $ex->getMessage()], 422);
}
