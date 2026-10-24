<?php
/**
 * سەرچاوە مرۆییەکان — today's attendance as JSON, for hr/live.php.
 *
 * The page asks every few seconds. Each answer is the whole picture of the
 * current WORK day (08:00 → 08:00): every active person's state, every punch
 * so far, and the devices. Small enough to send whole, and a corrected or
 * deleted punch then shows at once without the page tracking edits.
 *
 *   person.state  in   last punch is IN: at work now
 *                 out  last punch is OUT: left
 *                 none no punch today on a work day: not arrived yet
 *                 off  holiday, or not one of the shift's days, and no punch
 *   person.hours      completed IN→OUT pairs, in hours
 *   person.open_since the IN still open; the page adds now − open_since live
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!hr_can('hr.employees.view') && !hr_can('hr.reports.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ڕێگەپێدراو نیت'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hr = new HrAttendance();
$today = HrAttendance::currentWorkDate();
$people = [];
$feed = [];
$stats = ['in' => 0, 'out' => 0, 'none' => 0, 'off' => 0, 'came' => 0];

foreach ($hr->period($hr->employees(), $today, $today) as $id => $p) {
    $e = $p['employee'];
    $d = $p['days'][$today];
    $last = $d['punches'] ? end($d['punches']) : null;
    if ($last) {
        $state = $last['type'] === 'in' ? 'in' : 'out';
        $stats['came']++;
    } elseif ($d['day_status'] === 'holiday') {
        $state = 'off';
    } else {
        $state = 'none';
    }
    $stats[$state]++;
    $people[] = [
        'id'         => (int)$id,
        'pin'        => hr_pin($e),
        'name'       => $e['full_name'],
        'type'       => $e['employee_type'],
        'state'      => $state,
        'first_in'   => $d['first_in'] ? HrAttendance::clock($d['first_in']) : null,
        'last_time'  => $last ? HrAttendance::clock($last['time']) : null,
        'hours'      => round($d['hours'], 4),
        'open_since' => $state === 'in' ? $last['time'] : null,
        'unpaired'   => $d['unpaired'],
        'off_label'  => $state === 'off' ? $d['holiday'] : null,
    ];
    foreach ($d['punches'] as $pu) {
        $feed[] = [
            'id'     => $pu['id'],
            'emp'    => (int)$id,
            'name'   => $e['full_name'],
            'time'   => $pu['time'],
            'clock'  => HrAttendance::clock($pu['time']),
            'type'   => $pu['type'],
            'source' => $pu['source'],
        ];
    }
}
usort($feed, fn($a, $b) => [$b['time'], $b['id']] <=> [$a['time'], $a['id']]);

$devices = [];
foreach (hr_devices() as $dv) {
    $st = hr_device_state($dv);
    $devices[] = [
        'name'       => $dv['name'] ?: 'ئامێری پەنجەمۆر',
        'cls'        => $st['cls'],
        'label'      => $st['label'],
        'last_punch' => $dv['last_punch_at'],
        'error'      => $st['cls'] === 'warn' ? $dv['last_error'] : null,
    ];
}

echo json_encode([
    'success'   => true,
    'now'       => date('Y-m-d H:i:s'),
    'work_date' => $today,
    'weekday'   => HrAttendance::WEEKDAYS[(int)date('w', strtotime($today))],
    'stats'     => $stats + ['total' => count($people)],
    'people'    => $people,
    'feed'      => $feed,
    'devices'   => $devices,
], JSON_UNESCAPED_UNICODE);
