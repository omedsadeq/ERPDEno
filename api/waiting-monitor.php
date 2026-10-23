<?php
/**
 * Waiting Monitor API — returns current state for the big-screen display
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

// Patient names are PII — this feed must not be public
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();

// Doctor isolation: a doctor viewing the monitor sees ONLY their own queue,
// never other doctors' patients. Admin/reception see the full board.
$monitorDoctorId = ($auth->isDoctor() && !$auth->isAdmin()) ? (int)($_SESSION['user_id'] ?? 0) : 0;
$doctorScope   = $monitorDoctorId ? " AND id = " . $monitorDoctorId : "";
$entryScope    = $monitorDoctorId ? " AND wr.doctor_id = " . $monitorDoctorId : "";

// Active doctors
$doctors = $db->fetchAll(
    "SELECT id, full_name, room_number, room_number_2
     FROM users
     WHERE role='doctor' AND is_active=1{$doctorScope}
     ORDER BY (room_number IS NULL OR room_number = '') ASC, room_number ASC, full_name"
);

// Today's waiting-room entries, active statuses only
$entries = $db->fetchAll(
    "SELECT wr.id, wr.doctor_id, wr.status, wr.priority,
            wr.check_in_time, wr.called_time, wr.room_name,
            p.full_name AS patient_name,
            p.patient_code
     FROM waiting_room wr
     LEFT JOIN patients p ON wr.patient_id = p.id
     WHERE DATE(wr.check_in_time) = CURDATE()
       AND wr.status IN ('waiting','called','in_progress','in_treatment','on_hold'){$entryScope}
     ORDER BY
       CASE wr.status
         WHEN 'in_progress' THEN 1
         WHEN 'in_treatment' THEN 1
         WHEN 'called'       THEN 2
         WHEN 'on_hold'      THEN 3
         WHEN 'waiting'      THEN 4
         ELSE 5
       END,
       wr.priority DESC,
       wr.check_in_time ASC"
);

// Group by doctor
$byDoctor = [];
foreach ($entries as $e) {
    $byDoctor[$e['doctor_id']][] = $e;
}

$output = [];
foreach ($doctors as $d) {
    $list = $byDoctor[$d['id']] ?? [];
    $current = null;
    $current2 = null;
    $next = [];
    foreach ($list as $e) {
        // A two-room doctor can have two patients in treatment at once —
        // show both (each with the room it was called into).
        if (in_array($e['status'], ['in_progress','in_treatment','called'], true)) {
            if ($current === null) {
                $current = $e;
                continue;
            }
            if ($current2 === null) {
                $current2 = $e;
                continue;
            }
        }
        $next[] = $e;
    }
    // If no in-progress/called, first waiting is the "next to be seen"
    if ($current === null && !empty($next)) {
        $current = array_shift($next);
    }

    $format = function($e) {
        if (!$e) return null;
        return [
            'id'              => (int)$e['id'],
            'patient_name'    => $e['patient_name'] ?? '—',
            'patient_code'    => $e['patient_code'] ?? '',
            'status'          => $e['status'],
            'priority'        => (int)$e['priority'],
            'check_in_time'   => date('H:i', strtotime($e['check_in_time'])),
            'room'            => $e['room_name'] ?? '',
        ];
    };

    $output[] = [
        'id'          => (int)$d['id'],
        'name'        => $d['full_name'],
        'room'        => $d['room_number'] ?: '',
        'room2'       => $d['room_number_2'] ?: '',
        'current'     => $format($current),
        'current2'    => $format($current2),
        'next'        => array_map($format, array_slice($next, 0, 2)),
        'queue_count' => count($next),
    ];
}

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'date'      => date('Y-m-d'),
    'doctors'   => $output,
], JSON_UNESCAPED_UNICODE);
