<?php
/**
 * Appointments Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

// Include required files BEFORE any output
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Appointment.php';
require_once __DIR__ . '/classes/Patient.php';
require_once __DIR__ . '/classes/Notification.php';
require_once __DIR__ . '/classes/VisitDoctorSync.php';

// Initialize
$db = Database::getInstance();
$auth = new Auth();

// Check authentication
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$appointmentManager = new Appointment();
$patientManager = new Patient();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$hasFullAccess = $isAdmin || $isReceptionist; // Receptionist sees all like admin
$doctorId = $hasFullAccess ? null : $currentUser['id'];

// Get doctors list for admin
$doctors = $auth->getDoctors();

// Get cancellation reasons from database
$cancellationReasons = $db->fetchAll("SELECT * FROM cancellation_reasons WHERE is_active = 1 ORDER BY sort_order, id");

if (!function_exists('dh_duration_options')) {
    function dh_duration_options($selected = 30) {
        $kd = function($n) {
            return strtr((string)$n, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
        };
        $durations = [15, 30, 45];
        for ($m = 60; $m <= 720; $m += 30) { $durations[] = $m; }
        $out = '';
        foreach ($durations as $d) {
            if ($d < 60) {
                $label = $kd($d) . ' خولەک';
            } else {
                $h = intdiv($d, 60); $r = $d % 60;
                $label = $r === 0 ? $kd($h) . ' کاتژمێر' : $kd($h) . ' کاتژمێر و ' . $kd($r) . ' خولەک';
            }
            $sel = ($d == $selected) ? ' selected' : '';
            $out .= '<option value="' . $d . '"' . $sel . '>' . $label . '</option>';
        }
        return $out;
    }
}

// Work types are loaded further down — the edit dialog must also keep the
// types already saved on the listed appointments (see $workTypes below).

// Current date and view
$viewDate = $_GET['date'] ?? date('Y-m-d');
$viewMode = $_GET['view'] ?? 'day';
// Non-privileged users may only ever view their own schedule (no ?doctor= override)
$selectedDoctor = $hasFullAccess ? ($_GET['doctor'] ?? '') : $currentUser['id'];
// Work type (ئیش) filter — 0 means "all"
$selectedWorkType = isset($_GET['work_type']) ? (int)$_GET['work_type'] : 0;
if ($selectedWorkType < 0) { $selectedWorkType = 0; }
$selectedCallStatus = $_GET['call_status'] ?? 'all';
if (!in_array($selectedCallStatus, ['all', 'called', 'not_called'], true)) {
    $selectedCallStatus = 'all';
}

$APPOINTMENT_STATUSES = ['scheduled', 'confirmed', 'waiting', 'in_progress', 'completed', 'cancelled', 'no_show', 'rescheduled'];

// A doctor (or any non-admin/receptionist role) may only act on their own appointments
if (!function_exists('dh_can_touch_appointment')) {
    function dh_can_touch_appointment($apt, $hasFullAccess, $currentUser) {
        if ($hasFullAccess) return true;
        return $apt && (int)$apt['doctor_id'] === (int)$currentUser['id'];
    }
}

if (!function_exists('dh_appointment_return_url')) {
    function dh_appointment_return_url($date, $view, $doctor, $workType, $callStatus, $flag = '') {
        $query = ['date' => $date];
        if (in_array($view, ['week', 'month'], true)) {
            $query['view'] = $view;
        }
        if ((string)$doctor !== '') {
            $query['doctor'] = $doctor;
        }
        if ((int)$workType > 0) {
            $query['work_type'] = (int)$workType;
        }
        if (in_array($callStatus, ['called', 'not_called'], true)) {
            $query['call_status'] = $callStatus;
        }
        if ($flag !== '') {
            $query[$flag] = 1;
        }
        return APP_URL . '/appointments.php?' . http_build_query($query);
    }
}

// Handle actions BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'create') {
        $postedDoctorId = intval($_POST['doctor_id']);
        // Only admin/receptionist may book for other doctors
        if (!$hasFullAccess) {
            $postedDoctorId = (int)$currentUser['id'];
        }
        $validDoctor = $postedDoctorId > 0
            ? $db->fetch("SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1", ['id' => $postedDoctorId])
            : null;
        if (!$validDoctor) {
            $error = 'تکایە پزیشک هەڵبژێرە';
        } else {
            $data = [
                'patient_id' => intval($_POST['patient_id']),
                'doctor_id' => $postedDoctorId,
                'appointment_date' => $_POST['appointment_date'],
                'appointment_time' => $_POST['appointment_time'],
                'duration' => intval($_POST['duration']) ?: 30,
                'type' => $_POST['type'] ?? 'checkup',
                'work_type_id' => !empty($_POST['work_type_id']) ? intval($_POST['work_type_id']) : null,
                'promised_amount' => (isset($_POST['promised_amount']) && $_POST['promised_amount'] !== '')
                    ? floatval($_POST['promised_amount']) : null,
                'notes' => trim($_POST['notes'] ?? ''),
                'status' => 'scheduled',
                'created_by' => (int)$currentUser['id']
            ];
            $result = $appointmentManager->create($data);
            if ($result['success']) {
                AuditLog::log('create_appointment', 'appointments', $result['appointment_id'] ?? null, null, $data);
                $aptPatient = $db->fetch("SELECT full_name FROM patients WHERE id = :id", ['id' => $data['patient_id']]);
                (new Notification())->notifyAppointmentEvent(
                    'created', $data['doctor_id'], $aptPatient['full_name'] ?? '',
                    $result['appointment_id'] ?? 0, $data['appointment_date'], $data['appointment_time'],
                    (int)$currentUser['id']
                );
                header('Location: ' . APP_URL . '/appointments.php?date=' . $data['appointment_date'] . '&created=1');
                exit;
            }
        }
    } elseif ($action === 'update_full') {
        $appointmentId = intval($_POST['appointment_id']);
        $oldApt = $appointmentManager->getById($appointmentId);
        if (!dh_can_touch_appointment($oldApt, $hasFullAccess, $currentUser)) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
        $newStatus = in_array($_POST['status'] ?? '', $APPOINTMENT_STATUSES, true) ? $_POST['status'] : $oldApt['status'];
        $newDoctorId = intval($_POST['doctor_id']) ?: (int)$oldApt['doctor_id'];
        if (!$hasFullAccess) {
            $newDoctorId = (int)$oldApt['doctor_id']; // doctors cannot hand appointments to others
        }
        $updateData = [
            'doctor_id'        => $newDoctorId,
            'appointment_date' => $_POST['appointment_date'],
            'appointment_time' => $_POST['appointment_time'],
            'duration'         => intval($_POST['duration']) ?: 30,
            'type'             => $_POST['type'] ?? 'general',
            'work_type_id'     => !empty($_POST['work_type_id']) ? intval($_POST['work_type_id']) : null,
            'status'           => $newStatus,
            'notes'            => trim($_POST['notes'] ?? ''),
            'promised_amount'  => (isset($_POST['promised_amount']) && $_POST['promised_amount'] !== '')
                ? floatval($_POST['promised_amount']) : null,
            'updated_by'       => (int)$currentUser['id'],
        ];
        $result = $appointmentManager->update($appointmentId, $updateData);
        if ($result['success']) {
            AuditLog::logChange('update_appointment', 'appointments', $appointmentId, $oldApt ?: [], $updateData);

            // Each notification here reaches the doctor *and* every receptionist,
            // and it used to fire on any successful save — including a re-save
            // that changed nothing, or an edit to notes/promised amount that
            // reception cannot act on. That is what grew this type to ~21k rows.
            // Notify only when something they actually act on moved.
            $normalise = static function ($field, $value) {
                $v = trim((string)$value);
                if ($field === 'appointment_time') return substr($v, 0, 5);  // 14:30:00 === 14:30
                if ($field === 'appointment_date') return substr($v, 0, 10);
                return $v;
            };
            $notifyOnChangeOf = ['doctor_id', 'appointment_date', 'appointment_time', 'status'];
            $meaningfulChange = false;
            foreach ($notifyOnChangeOf as $field) {
                if ($normalise($field, $oldApt[$field] ?? '') !== $normalise($field, $updateData[$field])) {
                    $meaningfulChange = true;
                    break;
                }
            }

            if ($meaningfulChange) {
                (new Notification())->notifyAppointmentEvent(
                    $newStatus === 'cancelled' ? 'cancelled' : 'updated',
                    $newDoctorId, $oldApt['patient_name'] ?? '',
                    $appointmentId, $updateData['appointment_date'], $updateData['appointment_time'],
                    (int)$currentUser['id']
                );
            }
            header('Location: ' . APP_URL . '/appointments.php?date=' . $updateData['appointment_date'] . '&updated=1');
            exit;
        }
    } elseif ($action === 'update_status') {
        $appointmentId = intval($_POST['appointment_id']);
        $status = $_POST['status'];
        $oldApt = $appointmentManager->getById($appointmentId);
        if (!dh_can_touch_appointment($oldApt, $hasFullAccess, $currentUser)) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }
        if (!in_array($status, $APPOINTMENT_STATUSES, true)) {
            header('Location: ' . APP_URL . '/appointments.php?date=' . $viewDate);
            exit;
        }
        $statusData = ['status' => $status, 'updated_by' => (int)$currentUser['id']];
        if ($status === 'cancelled') {
            $statusData['cancelled_by'] = (int)$currentUser['id'];
            $statusData['cancelled_at'] = date('Y-m-d H:i:s');
        }
        $result = $appointmentManager->update($appointmentId, $statusData);
        if ($result['success']) {
            AuditLog::log('appointment_status', 'appointments', $appointmentId,
                ['status' => $oldApt['status'] ?? null], ['status' => $status]);
            if ($status === 'cancelled') {
                (new Notification())->notifyAppointmentEvent(
                    'cancelled', (int)($oldApt['doctor_id'] ?? 0), $oldApt['patient_name'] ?? '',
                    $appointmentId, $oldApt['appointment_date'] ?? '', $oldApt['appointment_time'] ?? null,
                    (int)$currentUser['id']
                );
            }
            header('Location: ' . APP_URL . '/appointments.php?date=' . $viewDate . '&updated=1');
            exit;
        }
    } elseif ($action === 'mark_called_to_center') {
        $appointmentId = intval($_POST['appointment_id'] ?? 0);
        $oldApt = $appointmentId ? $appointmentManager->getById($appointmentId) : null;
        if (!$oldApt || !dh_can_touch_appointment($oldApt, $hasFullAccess, $currentUser)) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }

        $callableStatuses = ['scheduled', 'confirmed', 'rescheduled'];
        if (!in_array($oldApt['status'], $callableStatuses, true)) {
            header('Location: ' . dh_appointment_return_url(
                $viewDate, $viewMode, $selectedDoctor, $selectedWorkType,
                $selectedCallStatus, 'call_unavailable'
            ));
            exit;
        }

        $result = $appointmentManager->markCalledToCenter($appointmentId, (int)$currentUser['id']);
        if (!empty($result['success'])) {
            $newApt = $appointmentManager->getById($appointmentId);
            AuditLog::logChange(
                'appointment_called_to_center',
                'appointments',
                $appointmentId,
                [
                    'called_to_center_at' => $oldApt['called_to_center_at'] ?? null,
                    'called_to_center_by' => $oldApt['called_to_center_by'] ?? null,
                ],
                [
                    'called_to_center_at' => $newApt['called_to_center_at'] ?? null,
                    'called_to_center_by' => $newApt['called_to_center_by'] ?? null,
                ]
            );
        }
        header('Location: ' . dh_appointment_return_url(
            $viewDate, $viewMode, $selectedDoctor, $selectedWorkType,
            $selectedCallStatus, !empty($result['success']) ? 'call_marked' : 'call_error'
        ));
        exit;
    } elseif ($action === 'clear_called_to_center') {
        $appointmentId = intval($_POST['appointment_id'] ?? 0);
        $oldApt = $appointmentId ? $appointmentManager->getById($appointmentId) : null;
        if (!$oldApt || !dh_can_touch_appointment($oldApt, $hasFullAccess, $currentUser)) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }

        $result = $appointmentManager->clearCalledToCenter($appointmentId, (int)$currentUser['id']);
        if (!empty($result['success'])) {
            AuditLog::logChange(
                'appointment_call_cleared',
                'appointments',
                $appointmentId,
                [
                    'called_to_center_at' => $oldApt['called_to_center_at'] ?? null,
                    'called_to_center_by' => $oldApt['called_to_center_by'] ?? null,
                ],
                ['called_to_center_at' => null, 'called_to_center_by' => null]
            );
        }
        header('Location: ' . dh_appointment_return_url(
            $viewDate, $viewMode, $selectedDoctor, $selectedWorkType,
            $selectedCallStatus, !empty($result['success']) ? 'call_cleared' : 'call_error'
        ));
        exit;
    } elseif ($action === 'check_in') {
        $appointmentId = intval($_POST['appointment_id']);
        $apt = $appointmentManager->getById($appointmentId);
        if ($apt && dh_can_touch_appointment($apt, $hasFullAccess, $currentUser)) {
            // Guard against double check-in for the same appointment/patient
            $activeEntry = $db->fetch(
                "SELECT id FROM waiting_room
                 WHERE (appointment_id = :apt_id OR patient_id = :patient_id)
                   AND status IN ('waiting', 'called', 'in_progress', 'in_treatment', 'on_hold')
                 LIMIT 1",
                ['apt_id' => $appointmentId, 'patient_id' => $apt['patient_id']]
            );
            if (!$activeEntry) {
                $waitingId = $db->insert('waiting_room', [
                    'patient_id' => $apt['patient_id'],
                    'doctor_id' => $apt['doctor_id'],
                    'appointment_id' => $appointmentId,
                    'check_in_time' => date('Y-m-d H:i:s'),
                    'status' => 'waiting',
                    'created_by' => (int)$currentUser['id']
                ]);
                AuditLog::log('waiting_check_in', 'waiting_room', $waitingId, null, [
                    'patient_id' => $apt['patient_id'],
                    'doctor_id'  => $apt['doctor_id'],
                    'appointment_id' => $appointmentId,
                    'source' => 'appointments',
                ]);

                // Record the appointment's doctor on today's visit row
                // (دکتۆری فەحس / دکتۆری ئیش) — only checkup/treatment types map.
                VisitDoctorSync::assignToVisit(
                    $db,
                    null,
                    (int)$apt['patient_id'],
                    $apt['type'] ?? null,
                    VisitDoctorSync::doctorsIdFromUserId($db, (int)$apt['doctor_id'])
                );
                $appointmentManager->updateStatus($appointmentId, 'waiting');
                (new Notification())->notifyPatientWaiting(
                    (int)$apt['doctor_id'], $apt['patient_name'] ?? '', $waitingId, (int)$currentUser['id']
                );
            }
            header('Location: ' . APP_URL . '/appointments.php?date=' . $viewDate . '&checked_in=1');
            exit;
        }
    } elseif ($action === 'cancel_with_reason') {
        $appointmentId = intval($_POST['appointment_id']);
        $reason = trim($_POST['cancellation_reason'] ?? '');
        $comment = trim($_POST['cancellation_comment'] ?? '');
        $oldApt = $appointmentManager->getById($appointmentId);

        if ($appointmentId && $reason && dh_can_touch_appointment($oldApt, $hasFullAccess, $currentUser)) {
            // Update appointment with cancellation details + who cancelled
            $db->update('appointments', [
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancellation_comment' => $comment,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancelled_by' => (int)$currentUser['id'],
                'updated_by'   => (int)$currentUser['id']
            ], 'id = ?', [$appointmentId]);
            AuditLog::log('cancel_appointment', 'appointments', $appointmentId,
                ['status' => $oldApt['status'] ?? null],
                ['status' => 'cancelled', 'reason' => $reason, 'comment' => $comment]);
            (new Notification())->notifyAppointmentEvent(
                'cancelled', (int)($oldApt['doctor_id'] ?? 0), $oldApt['patient_name'] ?? '',
                $appointmentId, $oldApt['appointment_date'] ?? '', $oldApt['appointment_time'] ?? null,
                (int)$currentUser['id']
            );

            header('Location: ' . APP_URL . '/appointments.php?date=' . $viewDate . '&cancelled=1');
            exit;
        }
    } elseif ($action === 'delete_appointment') {
        // Permanent delete: the row leaves `appointments` entirely, so every
        // list/report/PDF stops showing it without needing a deleted_at filter.
        // A copy goes to appointments_deleted first so a mis-click is recoverable.
        $appointmentId = intval($_POST['appointment_id']);
        $apt = $appointmentId ? $appointmentManager->getById($appointmentId) : null;

        if (!$apt || !dh_can_touch_appointment($apt, $hasFullAccess, $currentUser)) {
            header('Location: ' . APP_URL . '/403.php');
            exit;
        }

        try {
            $db->beginTransaction();
            // waiting_room.appointment_id is ON DELETE SET NULL — record which
            // check-in rows pointed here so a restore can re-link them.
            $wrIds = array_column(
                $db->fetchAll("SELECT id FROM waiting_room WHERE appointment_id = ?", [$appointmentId]),
                'id'
            );
            // Insert by column name instead of relying on physical column
            // order. This remains safe when new appointment fields are added.
            $archiveRow = $db->fetch("SELECT * FROM appointments WHERE id = ?", [$appointmentId]);
            $archiveRow['deleted_at'] = date('Y-m-d H:i:s');
            $archiveRow['deleted_by'] = (int)$currentUser['id'];
            $archiveRow['waiting_room_ids'] = implode(',', $wrIds);
            $db->insert('appointments_deleted', $archiveRow);
            $db->query("DELETE FROM appointments WHERE id = ?", [$appointmentId]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            error_log('delete_appointment failed for #' . $appointmentId . ': ' . $e->getMessage());
            header('Location: ' . APP_URL . '/appointments.php?date=' . $viewDate . '&apt_del=err');
            exit;
        }

        AuditLog::log('delete_appointment', 'appointments', $appointmentId, $apt, null);
        header('Location: ' . APP_URL . '/appointments.php?date=' . $viewDate . '&apt_del=1');
        exit;
    } elseif ($action === 'reschedule') {
        $appointmentId = intval($_POST['appointment_id']);
        $newDate = $_POST['new_date'] ?? '';
        $newTime = $_POST['new_time'] ?? '';

        if ($appointmentId && $newDate && $newTime) {
            // Get original appointment details before updating
            $apt = $appointmentManager->getById($appointmentId);
            if ($apt && dh_can_touch_appointment($apt, $hasFullAccess, $currentUser)) {
                // Overlapping bookings are allowed — no conflict check on reschedule
                $db->update('appointments', [
                    'status'               => 'rescheduled',
                    'appointment_date'     => $newDate,
                    'appointment_time'     => $newTime,
                    'rescheduled_from_date'=> $apt['appointment_date'],
                    'rescheduled_from_time'=> $apt['appointment_time'],
                    'rescheduled_at'       => date('Y-m-d H:i:s'),
                    'updated_by'           => (int)$currentUser['id'],
                ], 'id = ?', [$appointmentId]);
                AuditLog::log('reschedule_appointment', 'appointments', $appointmentId,
                    ['date' => $apt['appointment_date'], 'time' => $apt['appointment_time']],
                    ['date' => $newDate, 'time' => $newTime]);
                (new Notification())->notifyAppointmentEvent(
                    'updated', (int)$apt['doctor_id'], $apt['patient_name'] ?? '',
                    $appointmentId, $newDate, $newTime, (int)$currentUser['id']
                );
                header('Location: ' . APP_URL . '/appointments.php?date=' . $newDate . '&rescheduled=1');
                exit;
            }
        }
    } elseif ($action === 'send_to_reception') {
        // Doctor sends a day's appointment list to reception as a call list
        // (reception sees the list WITHOUT debt — promised amount only)
        if ($hasFullAccess) {
            header('Location: ' . APP_URL . '/appointments.php');
            exit;
        }
        $listDate = $_POST['list_date'] ?? '';
        $listNote = trim($_POST['list_note'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
            header('Location: ' . APP_URL . '/appointments.php?rl_sent=bad_date');
            exit;
        }
        $totalCount = (int)($db->fetch(
            "SELECT COUNT(*) AS c FROM appointments
             WHERE doctor_id = :d AND appointment_date = :dt
             AND status NOT IN ('cancelled', 'no_show')",
            ['d' => (int)$currentUser['id'], 'dt' => $listDate]
        )['c'] ?? 0);
        if ($totalCount === 0) {
            header('Location: ' . APP_URL . '/appointments.php?date=' . $listDate . '&rl_sent=empty');
            exit;
        }

        // Optional subset: doctor picked specific patients (empty = whole day)
        $selectedIds = [];
        $selectedRaw = trim($_POST['selected_ids'] ?? '');
        if ($selectedRaw !== '') {
            $candidates = array_values(array_unique(array_filter(
                array_map('intval', explode(',', $selectedRaw)),
                fn($v) => $v > 0
            )));
            if ($candidates) {
                // Only ids that really belong to this doctor on this date count
                $ph = implode(',', array_fill(0, count($candidates), '?'));
                $validRows = $db->fetchAll(
                    "SELECT id FROM appointments
                     WHERE doctor_id = ? AND appointment_date = ?
                     AND status NOT IN ('cancelled', 'no_show') AND id IN ({$ph})",
                    array_merge([(int)$currentUser['id'], $listDate], $candidates)
                );
                $selectedIds = array_map(fn($r) => (int)$r['id'], $validRows);
            }
            if (empty($selectedIds)) {
                header('Location: ' . APP_URL . '/appointments.php?date=' . $listDate . '&rl_sent=empty');
                exit;
            }
            if (count($selectedIds) >= $totalCount) {
                $selectedIds = []; // everything picked → treat as whole-day list
            }
        }
        $listCount = $selectedIds ? count($selectedIds) : $totalCount;

        (new Notification())->notifyReceptionCallList(
            (int)$currentUser['id'], $currentUser['full_name'] ?? '',
            $listDate, $listCount, $listNote, (int)$currentUser['id'], $selectedIds
        );
        // `raw` and `total` are recorded so a "reception got everyone" report can be
        // traced: raw = what the checkboxes posted, ids = what survived validation,
        // empty ids with a non-empty raw means the whole-day fallback kicked in.
        AuditLog::log('send_reception_call_list', 'appointments', null, null,
            ['date' => $listDate, 'count' => $listCount, 'note' => $listNote,
             'ids' => $selectedIds, 'raw' => $selectedRaw, 'total' => $totalCount]);
        header('Location: ' . APP_URL . '/appointments.php?date=' . $listDate . '&rl_sent=1');
        exit;
    }
}

// Get appointments based on view mode
if ($viewMode === 'week') {
    // Get current week (Saturday to Friday for Kurdish calendar)
    $dayOfWeek = date('w', strtotime($viewDate));
    // Adjust to start from Saturday (6)
    $daysToSaturday = ($dayOfWeek + 1) % 7;
    $startDate = date('Y-m-d', strtotime($viewDate . ' -' . $daysToSaturday . ' days'));
    $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
    $appointments = $appointmentManager->getByWeek($startDate, $endDate, $selectedDoctor ?: null);
    $viewTitle = 'ئەم هەفتەیە';
    $viewDateRange = $startDate . ' - ' . $endDate;
} elseif ($viewMode === 'month') {
    // Get current month
    $startDate = date('Y-m-01', strtotime($viewDate));
    $endDate = date('Y-m-t', strtotime($viewDate));
    $appointments = $appointmentManager->getByWeek($startDate, $endDate, $selectedDoctor ?: null);
    $viewTitle = 'ئەم مانگە';
    $months = ['کانوونی دووەم', 'شوبات', 'ئازار', 'نیسان', 'ئایار', 'حوزەیران', 'تەممووز', 'ئاب', 'ئەیلوول', 'تشرینی یەکەم', 'تشرینی دووەم', 'کانوونی یەکەم'];
    $monthName = $months[date('n', strtotime($viewDate)) - 1];
    $viewDateRange = $monthName . ' ' . date('Y', strtotime($viewDate));
} else {
    // Day view (default)
    $appointments = $appointmentManager->getByDate($viewDate, $selectedDoctor ?: null);
    $viewTitle = null;
    $viewDateRange = null;
}

// Work types offered on this page: the global ones + the ones added here,
// plus any type already saved on a listed appointment so the edit dialog
// never drops a type that was created on another page.
$workTypes = $db->fetchAll(
    "SELECT id, work_name FROM work_types
     WHERE is_active = 1 AND "
     . work_type_scope_sql(
         'appointments',
         array_merge(array_column($appointments, 'work_type_id'), [$selectedWorkType])
       ) . "
     ORDER BY sort_order, id"
);

// Only accept a filter the dropdown can actually show, so the select and the
// list never disagree (e.g. a ?work_type= pointing at a deactivated type).
$selectedWorkTypeName = '';
foreach ($workTypes as $wt) {
    if ((int)$wt['id'] === $selectedWorkType) { $selectedWorkTypeName = $wt['work_name']; break; }
}
if ($selectedWorkTypeName === '') { $selectedWorkType = 0; }

// Apply the ئیش filter AFTER $workTypes is built, so the dropdown keeps every
// option while the list, the statistics cards and the chips all narrow down.
if ($selectedWorkType > 0) {
    $appointments = array_values(array_filter(
        $appointments,
        fn($a) => (int)($a['work_type_id'] ?? 0) === $selectedWorkType
    ));
}

$callFilterAppointments = $appointments;
$calledCount = count(array_filter(
    $callFilterAppointments,
    fn($a) => !empty($a['called_to_center_at'])
));
$notCalledCount = count($callFilterAppointments) - $calledCount;
if ($selectedCallStatus === 'called') {
    $appointments = array_values(array_filter(
        $appointments,
        fn($a) => !empty($a['called_to_center_at'])
    ));
} elseif ($selectedCallStatus === 'not_called') {
    $appointments = array_values(array_filter(
        $appointments,
        fn($a) => empty($a['called_to_center_at'])
    ));
}

// Query string that carries the active filters across every navigation link
$aptFilterQs = '&doctor=' . urlencode((string)$selectedDoctor)
             . ($selectedWorkType > 0 ? '&work_type=' . $selectedWorkType : '')
             . ($selectedCallStatus !== 'all' ? '&call_status=' . urlencode($selectedCallStatus) : '');
// Keep week/month view on the filter controls, but only when it is really set
$aptViewQs = isset($_GET['view']) ? 'view=' . urlencode($viewMode) . '&' : '';

// Get available time slots
$availableSlots = $appointmentManager->getAvailableSlots($selectedDoctor ?: $currentUser['id'], $viewDate);

// NOW include header (which outputs HTML)
$pageTitle = 'مەوعیدەکان';
$breadcrumb = [['title' => 'مەوعیدەکان']];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/case-button.php';
?>

<style>
/* Appointments Statistics Cards */
.stats-grid-appointments {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 6px;
    margin-bottom: 10px;
}

.stat-card-appointment {
    background: linear-gradient(135deg, var(--bg-color), var(--hover-color));
    border-radius: 6px;
    padding: 8px 10px;
    text-align: center;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card-appointment::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1.5px;
    background: var(--accent-color);
}

.stat-card-appointment:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: var(--accent-color);
}

.stat-card-appointment.stat-blue {
    --bg-color: #f0f7ff;
    --hover-color: #e3f2fd;
    --border-color: #bbdefb;
    --accent-color: #2196f3;
}

.stat-card-appointment.stat-orange {
    --bg-color: #fff8f0;
    --hover-color: #fff3e0;
    --border-color: #ffe0b2;
    --accent-color: #ff9800;
}

.stat-card-appointment.stat-red {
    --bg-color: #fff5f5;
    --hover-color: #ffebee;
    --border-color: #ffcdd2;
    --accent-color: #f44336;
}

.stat-card-appointment.stat-green {
    --bg-color: #f1f8f4;
    --hover-color: #e8f5e9;
    --border-color: #c8e6c9;
    --accent-color: #4caf50;
}

.stat-card-appointment.stat-purple {
    --bg-color: #faf5fc;
    --hover-color: #f3e5f5;
    --border-color: #e1bee7;
    --accent-color: #9c27b0;
}

.stat-icon-appointment {
    width: 28px;
    height: 28px;
    border-radius: 5px;
    background: var(--accent-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    margin: 0 auto 4px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.stat-value-appointment {
    font-size: 16px;
    font-weight: 800;
    color: var(--accent-color);
    line-height: 1.1;
    margin-bottom: 1px;
}

.stat-label-appointment {
    font-size: 11px;
    color: #546e7a;
    font-weight: 600;
    line-height: 1.2;
}

/* Filters Panel */
.filters-panel-appointments {
    background: linear-gradient(to bottom, #ffffff, #fafafa);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e0e0e0;
}

.filter-chips-appointments {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.filter-chip-apt {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 16px;
    background: #f5f5f5;
    color: #37474f;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border: 1.5px solid transparent;
    transition: all 0.2s ease;
    cursor: pointer;
}

.filter-chip-apt:hover {
    background: #e8e8e8;
    border-color: #bdbdbd;
}

.filter-chip-apt.active {
    background: var(--win-accent, #0078d4);
    color: white;
    border-color: var(--win-accent, #0078d4);
}

.chip-badge-apt {
    background: rgba(255, 255, 255, 0.3);
    padding: 1px 6px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
}

.filter-chip-apt:not(.active) .chip-badge-apt {
    background: #e0e0e0;
    color: #37474f;
}

.calendar-nav-modern {
    display: flex;
    align-items: center;
    gap: 8px;
}

.calendar-nav-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1.5px solid #e0e0e0;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #37474f;
    text-decoration: none;
}

.calendar-nav-btn:hover {
    background: #f5f5f5;
    border-color: var(--win-accent, #0078d4);
    color: var(--win-accent, #0078d4);
}

.calendar-nav-date {
    font-size: 15px;
    font-weight: 700;
    min-width: 180px;
    text-align: center;
    color: #2c3e50;
    padding: 8px 16px;
    background: linear-gradient(135deg, #f8f9fa, #ffffff);
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.filters-grid-appointments {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
    justify-content: space-between;
}

/* ── Filter action row — fluid at every width ──────────────────────────
   This row holds 11 controls (3 selects, a date box, the view toggle and 5
   buttons); their natural width is ~1693px. It is a single flex ITEM of
   .filters-grid-appointments, so the parent's flex-wrap cannot break it up.
   It used to get flex-wrap only inside @media (max-width:767px), which left
   every screen from 768px to ~1700px clipping it off the left edge (RTL) —
   on a 1366px laptop it overflowed by ~740px and مەوعیدی نوێ was unreachable.
   Wrapping here lets the row reflow into as many lines as the width needs,
   so no breakpoint has to guess a control count. */
.apt-filter-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex: 1 1 auto;
    justify-content: flex-end;
}
.apt-filter-actions > * {
    min-width: 0;
    /* the buttons carry inline margin-left:6px; `gap` owns the spacing now */
    margin-left: 0 !important;
}
/* Selects may shrink below their inline min-width:160px before wrapping */
.apt-filter-actions .filter-control-appointments {
    min-width: 132px !important;
    flex: 0 1 auto;
}
.apt-filter-actions .btn { white-space: nowrap; }

.filter-control-appointments {
    padding: 8px 12px;
    font-size: 13px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    transition: all 0.2s ease;
    font-family: inherit;
}

.filter-control-appointments:hover {
    border-color: #bdbdbd;
}

.filter-control-appointments:focus {
    border-color: var(--win-accent, #0078d4);
    box-shadow: 0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1);
    outline: none;
}

/* A filter that is actually narrowing the list must look like it */
.filter-control-appointments.filter-active-apt {
    border-color: var(--win-accent, #0078d4);
    background: #eef4fb;
    color: #0d47a1;
    font-weight: 600;
}

/* Appointments Table */
.appointments-table {
    width: 100%;
    border-collapse: collapse;
}

.appointments-table thead {
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    border-bottom: 2px solid #e0e0e0;
}

.appointments-table th {
    padding: 10px 8px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}

.appointments-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
}

.appointments-table tbody tr:hover {
    background: linear-gradient(to left, #f8f9fa, #ffffff);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.appointments-table td {
    padding: 10px 8px;
    font-size: 12px;
    color: #2c3e50;
    text-align: center;
}

.patient-name-apt {
    font-weight: 700;
    color: var(--win-accent, #0078d4);
    text-decoration: none;
    font-size: 13px;
}

.patient-name-apt:hover {
    text-decoration: underline;
}

.time-cell-apt {
    font-weight: 700;
    font-size: 14px;
    color: var(--win-accent, #0078d4);
    font-family: var(--app-font-family);
}

.duration-cell-apt {
    font-size: 12px;
    color: #546e7a;
    font-weight: 500;
}

.work-type-cell-apt {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 10px;
    background: #eef4fb;
    color: #1565c0;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.work-type-cell-apt i {
    font-size: 10px;
    opacity: .8;
}

/* Time Slots Modern */
.time-slots-modern {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
    gap: 6px;
}

.time-slot-modern {
    padding: 8px 6px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 13px;
    font-weight: 600;
    background: white;
    color: #37474f;
}

.time-slot-modern:hover {
    border-color: var(--win-accent, #0078d4);
    background: #e3f2fd;
    color: var(--win-accent, #0078d4);
    transform: translateY(-1px);
}

.time-slot-modern.booked {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
    cursor: not-allowed;
    opacity: 0.7;
}

/* Empty State */
.empty-state-appointments {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 14px;
    border: 2px dashed #e0e0e0;
}

.empty-state-appointments i {
    font-size: 64px;
    color: #e0e0e0;
    margin-bottom: 16px;
}

.empty-state-appointments h3 {
    font-size: 18px;
    color: #757575;
    margin-bottom: 8px;
}

.empty-state-appointments p {
    font-size: 14px;
    color: #9e9e9e;
    margin-bottom: 16px;
}

/* Side Panel */
.side-panel-modern {
    background: linear-gradient(to bottom, #ffffff, #fafafa);
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.side-panel-header {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);
    color: white;
    padding: 12px 16px;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.side-panel-body {
    padding: 16px;
}

.guide-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.guide-list li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 12px;
    color: #546e7a;
}

.guide-list li:last-child {
    border-bottom: none;
}

.guide-list li i {
    color: var(--win-accent, #0078d4);
    font-size: 10px;
    margin-top: 3px;
}

@media (max-width: 1200px) {
    .stats-grid-appointments {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Tablet (768–1024px) */
@media (min-width: 768px) and (max-width: 1024px) {
    /* Layout container with inline-style fixed 280px sidebar — relax it */
    #tableView {
        grid-template-columns: 1fr 240px !important;
    }
    .calendar-nav-date {
        min-width: 140px;
        font-size: 13px;
        padding: 6px 10px;
    }
}

/* Mobile (≤767px) */
@media (max-width: 767px) {
    /* Stats: all 5 in a single compact row (no lonely 2+2+1 layout) */
    .stats-grid-appointments {
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 4px;
    }
    .stat-card-appointment {
        padding: 6px 3px;
        min-width: 0;
        overflow: hidden;
    }
    .stat-icon-appointment {
        width: 22px;
        height: 22px;
        font-size: 10px;
        margin: 0 auto 2px;
    }
    .stat-value-appointment {
        font-size: 15px;
        line-height: 1;
        margin-bottom: 1px;
    }
    .stat-label-appointment {
        font-size: 9px;
        line-height: 1.15;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Filters panel — stack chips + nav */
    .filters-panel-appointments { padding: 12px; }
    .filter-chips-appointments {
        gap: 5px;
        margin-bottom: 10px;
    }
    .filter-chip-apt {
        font-size: 12px;
        padding: 6px 10px;
        flex: 1 1 calc(50% - 3px);
        justify-content: center;
    }
    .filters-grid-appointments {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .calendar-nav-modern {
        justify-content: space-between;
        width: 100%;
    }
    .calendar-nav-date {
        min-width: 0;
        flex: 1;
        font-size: 13px;
        padding: 8px 10px;
    }
    .calendar-nav-btn {
        width: 40px;
        height: 40px;
        flex-shrink: 0;
    }
    .filter-control-appointments {
        width: 100%;
        min-width: 0 !important;
        font-size: 16px; /* prevent iOS zoom */
        min-height: 42px;
        padding: 10px 12px;
    }

    /* CRITICAL: the second child of .filters-grid-appointments is an
       inline-styled <div style="display:flex;gap:8px"> that contains
       the doctor select, date input, view-toggle, and 4 nav buttons
       (بەپێی جۆر / ئامارەکان / ئەمڕۆ / مەوعیدی نوێ). Without flex-wrap
       it overflows off the left edge. Force it to wrap, full width,
       and give each child a sensible mobile size. */
    .filters-grid-appointments > div:not(.calendar-nav-modern) {
        flex-wrap: wrap !important;
        width: 100%;
        gap: 8px !important;
    }
    .filters-grid-appointments > div:not(.calendar-nav-modern) > * {
        min-width: 0;
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
    .filters-grid-appointments > div:not(.calendar-nav-modern)
        > select.filter-control-appointments,
    .filters-grid-appointments > div:not(.calendar-nav-modern)
        > input.filter-control-appointments {
        flex: 1 1 calc(50% - 4px);
        min-width: 0 !important;
        width: auto;
    }
    .filters-grid-appointments > div:not(.calendar-nav-modern) > .btn,
    .filters-grid-appointments > div:not(.calendar-nav-modern) > a.btn,
    .filters-grid-appointments > div:not(.calendar-nav-modern) > button.btn {
        flex: 1 1 calc(50% - 4px);
        justify-content: center;
        min-height: 42px;
        padding: 9px 8px !important;
        font-size: 12px !important;
        white-space: normal;
        line-height: 1.2;
    }

    /* View toggle (Table / Calendar) — full-width, 50/50 */
    .view-toggle {
        width: 100%;
        flex: 1 1 100%;
    }
    .view-toggle-btn {
        flex: 1;
        justify-content: center;
        padding: 10px 12px;
        min-height: 42px;
    }

    /* CRITICAL: #tableView has inline style="grid-template-columns: 1fr 280px"
       which beats normal media queries. Override with !important. */
    #tableView {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }

    /* Appointments table — global initResponsiveTables converts to cards */
    .appointments-table th,
    .appointments-table td {
        padding: 8px 10px;
        font-size: 13px;
    }

    /* Side panel */
    .side-panel-modern { margin-bottom: 12px; }
    .side-panel-header { padding: 10px 12px; font-size: 13px; }
    .side-panel-body { padding: 12px; }

    /* Time slots grid: smaller boxes */
    .time-slots-modern {
        grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
        gap: 5px;
    }
    .time-slot-modern { padding: 8px 4px; font-size: 12px; }
    .smart-time-picker-grid {
        grid-template-columns: repeat(auto-fill, minmax(56px, 1fr)) !important;
    }

    /* FullCalendar mobile — toolbar wraps, smaller fonts */
    .fc .fc-toolbar.fc-header-toolbar {
        flex-direction: column;
        gap: 8px;
        margin-bottom: 10px;
    }
    .fc .fc-toolbar-chunk {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 4px;
    }
    .fc .fc-toolbar-title { font-size: 14px; }
    .fc .fc-button {
        padding: 6px 10px !important;
        font-size: 12px !important;
    }
    .fc .fc-col-header-cell-cushion,
    .fc .fc-daygrid-day-number {
        font-size: 11px;
    }
    .fc-direction-rtl .fc-toolbar > * > :not(:first-child) {
        margin-right: 4px;
    }

    /* Dropdowns inside table action cells — wider on mobile */
    .dropdown-menu {
        min-width: 180px;
    }
}

/* Small mobile (≤480px) */
@media (max-width: 480px) {
    /* Keep all 5 stats in a row, just tighter */
    .stats-grid-appointments { gap: 3px; }
    .stat-card-appointment { padding: 5px 2px; }
    .stat-icon-appointment { width: 20px; height: 20px; font-size: 9px; margin-bottom: 1px; }
    .stat-value-appointment { font-size: 13px; }
    .stat-label-appointment { font-size: 8px; }

    .filter-chip-apt { flex: 1 1 100%; }
    .apt-form-grid {
        grid-template-columns: 1fr;
    }
    .apt-form-field.apt-field-full {
        grid-column: 1;
    }
    .fc .fc-toolbar-title { font-size: 13px; }
}

/* Fix dropdown overflow in table */
.table-container {
    overflow: visible !important;
}

.panel-body {
    overflow: visible;
}

.appointments-table {
    overflow: visible;
}

.appointments-table td {
    overflow: visible;
}

/* Dropdown Styles */
.dropdown {
    position: relative;
}

/* Positioned in viewport coordinates by positionDropdown() so the menu escapes
   the overflow:hidden app shell (body / .app-window / .content-area) and the
   .page-content scroller — otherwise it is clipped on the bottom table rows. */
.dropdown-menu {
    display: none;
    position: fixed;
    left: 0;
    top: 0;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    z-index: 1000;
    min-width: 160px;
    padding: 4px 0;
    max-height: calc(100vh - 16px);
    overflow-y: auto;
}

.dropdown-menu.show {
    display: block;
}

.dropdown-item {
    display: flex !important;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 10px 14px !important;
    text-align: right;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 13px !important;
    font-family: inherit;
    transition: background 0.2s;
    color: #37474f;
}

.dropdown-item:hover {
    background: #f5f5f5;
}

.dropdown-item i {
    width: 16px;
    text-align: center;
}

/* View Toggle Styles */
.view-toggle {
    display: flex;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
}

.view-toggle-btn {
    padding: 8px 14px;
    border: none;
    background: white;
    color: #546e7a;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    font-family: inherit;
}

.view-toggle-btn:not(:last-child) {
    border-left: 1px solid #e0e0e0;
}

.view-toggle-btn:hover {
    background: #f5f5f5;
}

.view-toggle-btn.active {
    background: var(--win-accent, #0078d4);
    color: white;
}

/* FullCalendar Container */
#calendarView {
    display: none;
}

#calendarView.active {
    display: block;
}

#tableView.hidden {
    display: none;
}

/* FullCalendar Custom Styles */
.fc {
    font-family: inherit;
    direction: rtl;
}

.fc .fc-toolbar-title {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
}

.fc .fc-button {
    border-radius: 6px !important;
    font-weight: 500;
    padding: 6px 12px;
    font-size: 12px;
    border: 1.5px solid #e0e0e0 !important;
    background: white !important;
    color: #37474f !important;
}

.fc .fc-button:hover {
    background: #f5f5f5 !important;
    border-color: var(--win-accent, #0078d4) !important;
}

.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background: var(--win-accent, #0078d4) !important;
    color: white !important;
    border-color: var(--win-accent, #0078d4) !important;
}

.fc .fc-daygrid-day.fc-day-today,
.fc .fc-timegrid-col.fc-day-today {
    background: rgba(var(--win-accent-rgb, 0, 120, 212), 0.05) !important;
}

.fc .fc-event {
    border-radius: 4px;
    border: none;
    padding: 2px 4px;
    font-size: 11px;
    cursor: pointer;
}

.fc .fc-timegrid-slot {
    height: 35px;
}

.fc .fc-col-header-cell-cushion {
    font-weight: 600;
    color: #2c3e50;
}

.fc .fc-timegrid-slot-label-cushion {
    font-size: 11px;
    color: #757575;
}

.fc-direction-rtl .fc-toolbar > * > :not(:first-child) {
    margin-right: 0.75em;
    margin-left: 0;
}

/* Smart Time Picker Styles */
.smart-time-picker {
    margin-top: 12px;
}

.smart-time-picker-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.smart-time-picker-header label {
    font-weight: 600;
    font-size: 13px;
    color: #2c3e50;
}

.smart-time-picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(65px, 1fr));
    gap: 6px;
    max-height: 200px;
    overflow-y: auto;
    padding: 4px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.smart-time-slot {
    padding: 8px 4px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 12px;
    font-weight: 600;
    background: white;
    color: #37474f;
}

.smart-time-slot:hover:not(.busy):not(.break) {
    border-color: var(--win-accent, #0078d4);
    background: #e3f2fd;
    color: var(--win-accent, #0078d4);
    transform: translateY(-1px);
}

.smart-time-slot.selected {
    background: var(--win-accent, #0078d4);
    color: white;
    border-color: var(--win-accent, #0078d4);
}

.smart-time-slot.busy {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
    cursor: not-allowed;
    opacity: 0.7;
}

/* Booked slots stay selectable (overlapping bookings allowed) —
   red tint just signals there is already an appointment there */
.smart-time-slot.booked {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
    cursor: pointer;
}

.smart-time-slot.booked.selected {
    background: var(--win-accent, #0078d4);
    color: white;
    border-color: var(--win-accent, #0078d4);
}

.smart-time-slot.break {
    background: #fff3e0;
    border-color: #ffe0b2;
    color: #e65100;
    cursor: not-allowed;
    opacity: 0.7;
}

.smart-time-picker-legend {
    display: flex;
    gap: 16px;
    margin-top: 10px;
    font-size: 11px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.legend-item::before {
    content: '';
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.legend-item.available::before {
    background: #e3f2fd;
    border: 1px solid var(--win-accent, #0078d4);
}

.legend-item.busy::before {
    background: #ffebee;
    border: 1px solid #f44336;
}

.legend-item.break::before {
    background: #fff3e0;
    border: 1px solid #ff9800;
}

.no-working-day {
    text-align: center;
    padding: 20px;
    color: #f44336;
    background: #ffebee;
    border-radius: 8px;
    font-size: 13px;
}

.no-working-day i {
    font-size: 24px;
    display: block;
    margin-bottom: 8px;
}

.slots-loading {
    text-align: center;
    padding: 20px;
    color: #757575;
}

/* Existing Bookings Section - Compact Inline */
.existing-bookings-section {
    margin: 10px 0;
    padding: 8px 10px;
    background: linear-gradient(135deg, #fff8e1, #fffde7);
    border-radius: 6px;
    border: 1px solid #ffe082;
}

.existing-bookings-section label {
    display: block;
    font-weight: 600;
    font-size: 10px;
    color: #ef6c00;
    margin-bottom: 6px;
}

.existing-bookings-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.existing-booking-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    background: white;
    border: 1px solid #ffcc80;
    border-radius: 16px;
    font-size: 11px;
    color: #e65100;
}

.existing-booking-item i {
    font-size: 9px;
}

.existing-booking-item .patient-name {
    font-weight: 600;
    color: #bf360c;
    max-width: 80px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.no-bookings-message {
    padding: 8px;
    text-align: center;
    color: #7cb342;
    font-size: 12px;
}

/* Booking Summary - Compact Horizontal */
.booking-summary-apt {
    margin-top: 10px;
    padding: 8px 12px;
    background: linear-gradient(135deg, #e8f4fd 0%, #d4e9fc 100%);
    border-radius: 6px;
    border: 1px solid #90caf9;
}

.booking-summary-apt .summary-title {
    font-weight: 600;
    font-size: 10px;
    color: #1976d2;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.booking-summary-apt .summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
}

.booking-summary-apt .summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 4px 6px;
    background: rgba(255,255,255,0.8);
    border-radius: 4px;
    font-size: 10px;
    text-align: center;
}

.booking-summary-apt .summary-label {
    color: #78909c;
    font-weight: 500;
    font-size: 9px;
    margin-bottom: 2px;
}

.booking-summary-apt .summary-value {
    color: #1565c0;
    font-weight: 700;
    font-size: 11px;
}

.booking-summary-apt.has-overlap {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    border-color: #e57373;
}

.booking-summary-apt.has-overlap .summary-title {
    color: #c62828;
}

.booking-summary-apt.has-overlap .summary-value {
    color: #c62828;
}

.no-bookings-message {
    text-align: center;
    padding: 6px;
    color: #66bb6a;
    font-size: 11px;
}

.no-bookings-message i {
    margin-left: 4px;
}

.appointment-call-state {
    min-width: 126px;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    line-height: 1.35;
}

.appointment-call-state.called {
    color: #08783e;
}

.appointment-call-state.not-called {
    color: #8a98a4;
}

.appointment-call-state .call-main {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
}

.appointment-call-state .call-meta {
    font-size: 9px;
    color: #607584;
    white-space: nowrap;
}

.btn-call-appointment {
    background: #e7f8f0;
    border: 1px solid #89d4af;
    color: #08783e;
}

.btn-call-appointment:hover {
    background: #08783e;
    color: #fff;
}

.btn-call-appointment.recall {
    background: #08783e;
    border-color: #08783e;
    color: #fff;
}

.call-filter-control.filter-active-apt {
    background: #e7f8f0;
    border-color: #279464;
    color: #086b39;
    font-weight: 700;
}
</style>

<!-- FullCalendar CDN -->
<script src='<?= APP_URL ?>/assets/vendor/fullcalendar/index.global.min.js'></script>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> مەوعید دروستکرا</div>
<?php endif; ?>
<?php if (isset($_GET['checked_in'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> نەخۆش چوویە ژووری چاوەڕوانی</div>
<?php endif; ?>
<?php if (isset($_GET['cancelled'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-calendar-times alert-icon"></i> مەوعید هەڵوەشایەوە</div>
<?php endif; ?>
<?php if (isset($_GET['rescheduled'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-calendar-alt alert-icon"></i> مەوعید دواخرا و کاتی نوێی بۆ دیاری کرا</div>
<?php endif; ?>
<?php if (($_GET['apt_del'] ?? '') === '1'): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-trash-alt alert-icon"></i> مەوعید سڕایەوە</div>
<?php elseif (($_GET['apt_del'] ?? '') === 'err'): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> سڕینەوەی مەوعید سەرکەوتوو نەبوو</div>
<?php endif; ?>
<?php if (($_GET['rl_sent'] ?? '') === '1'): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-paper-plane alert-icon"></i> لیستی مەوعیدەکان نێردرا بۆ ڕیسێپشن</div>
<?php elseif (($_GET['rl_sent'] ?? '') === 'empty'): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> بۆ ئەو ڕۆژە هیچ مەوعیدێکت نییە — هیچ نەنێردرا</div>
<?php elseif (($_GET['rl_sent'] ?? '') === 'bad_date'): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> بەروارەکە دروست نییە</div>
<?php endif; ?>

<?php if (isset($_GET['call_marked'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-phone-alt alert-icon"></i> بانگکردنی نەخۆش بۆ سەنتەر تۆمارکرا</div>
<?php elseif (isset($_GET['call_cleared'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-undo alert-icon"></i> نیشانەی بانگکردن هەڵوەشێندرایەوە</div>
<?php elseif (isset($_GET['call_unavailable'])): ?>
<div class="alert alert-warning" data-auto-hide><i class="fas fa-exclamation-triangle alert-icon"></i> تەنها مەوعیدی داهاتوو دەتوانرێت وەک بانگکراو تۆمار بکرێت</div>
<?php elseif (isset($_GET['call_error'])): ?>
<div class="alert alert-danger" data-auto-hide><i class="fas fa-exclamation-circle alert-icon"></i> تۆمارکردنی بانگ سەرکەوتوو نەبوو</div>
<?php endif; ?>

<?php
// Calculate statistics
$totalToday = count($appointments);
$scheduledCount = count(array_filter($appointments, fn($a) => $a['status'] === 'scheduled'));
$confirmedCount = count(array_filter($appointments, fn($a) => $a['status'] === 'confirmed'));
$waitingCount = count(array_filter($appointments, fn($a) => $a['status'] === 'waiting'));
$completedCount = count(array_filter($appointments, fn($a) => $a['status'] === 'completed'));
$cancelledApts  = array_values(array_filter($appointments, fn($a) => $a['status'] === 'cancelled'));
$cancelledCount = count($cancelledApts);

$days = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
$dayNum = date('w', strtotime($viewDate));
$dayName = $days[$dayNum];
?>

<!-- Statistics Cards -->
<div class="stats-grid-appointments">
    <div class="stat-card-appointment stat-blue">
        <div class="stat-icon-appointment">
            <i class="fas fa-calendar-day"></i>
        </div>
        <div class="stat-value-appointment"><?= $totalToday ?></div>
        <div class="stat-label-appointment">کۆی مەوعیدەکان</div>
    </div>

    <div class="stat-card-appointment stat-orange">
        <div class="stat-icon-appointment">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-value-appointment"><?= $scheduledCount ?></div>
        <div class="stat-label-appointment">داڕێژراو</div>
    </div>

    <div class="stat-card-appointment stat-purple">
        <div class="stat-icon-appointment">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-value-appointment"><?= $confirmedCount ?></div>
        <div class="stat-label-appointment">پشتڕاستکراو</div>
    </div>

    <div class="stat-card-appointment stat-red">
        <div class="stat-icon-appointment">
            <i class="fas fa-user-clock"></i>
        </div>
        <div class="stat-value-appointment"><?= $waitingCount ?></div>
        <div class="stat-label-appointment">چاوەڕوان</div>
    </div>

    <div class="stat-card-appointment stat-green">
        <div class="stat-icon-appointment">
            <i class="fas fa-check-double"></i>
        </div>
        <div class="stat-value-appointment"><?= $completedCount ?></div>
        <div class="stat-label-appointment">تەواوبوو</div>
    </div>
</div>

<!-- Filters Panel -->
<div class="filters-panel-appointments">
    <div class="filter-chips-appointments">
        <a href="?date=<?= date('Y-m-d') ?><?= $aptFilterQs ?>" class="filter-chip-apt <?= $viewDate === date('Y-m-d') && !isset($_GET['view']) ? 'active' : '' ?>">
            <i class="fas fa-calendar-day"></i>
            ئەمڕۆ
            <?php if ($viewDate === date('Y-m-d') && $totalToday > 0): ?>
            <span class="chip-badge-apt"><?= $totalToday ?></span>
            <?php endif; ?>
        </a>
        <a href="?date=<?= date('Y-m-d', strtotime('+1 day')) ?><?= $aptFilterQs ?>" class="filter-chip-apt <?= $viewDate === date('Y-m-d', strtotime('+1 day')) && !isset($_GET['view']) ? 'active' : '' ?>">
            <i class="fas fa-calendar-plus"></i>
            سبەینێ
        </a>
        <a href="?view=week<?= $aptFilterQs ?>" class="filter-chip-apt <?= ($viewMode === 'week' && isset($_GET['view'])) ? 'active' : '' ?>">
            <i class="fas fa-calendar-week"></i>
            ئەم هەفتەیە
        </a>
        <a href="?view=month<?= $aptFilterQs ?>" class="filter-chip-apt <?= ($viewMode === 'month' && isset($_GET['view'])) ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i>
            ئەم مانگە
        </a>
        <button type="button" class="filter-chip-apt" onclick="openDialog('cancelledListDialog')"
                style="background: #ffebee; color: #c62828; border-color: #ffcdd2; cursor: pointer; border: 1px solid #ffcdd2;">
            <i class="fas fa-calendar-times"></i>
            هەڵوەشاندنەوەکان
            <?php if ($cancelledCount > 0): ?>
            <span class="chip-badge-apt" style="background: #c62828;"><?= $cancelledCount ?></span>
            <?php endif; ?>
        </button>
        <?php $rescheduledCount = count(array_filter($appointments, fn($a) => $a['status'] === 'rescheduled')); ?>
        <button type="button" class="filter-chip-apt" onclick="openDialog('rescheduledListDialog')"
                style="background: #fff3e0; color: #e65100; border-color: #ffe0b2; cursor: pointer; border: 1px solid #ffe0b2;">
            <i class="fas fa-calendar-alt"></i>
            دواخستنەکان
            <?php if ($rescheduledCount > 0): ?>
            <span class="chip-badge-apt" style="background: #e65100;"><?= $rescheduledCount ?></span>
            <?php endif; ?>
        </button>
        <?php if ($selectedWorkType > 0): ?>
        <span class="filter-chip-apt active-work-type-chip" style="background: #eef4fb; color: #0d47a1; border: 1px solid #90caf9;">
            <i class="fas fa-briefcase-medical"></i>
            <?= htmlspecialchars($selectedWorkTypeName) ?>
            <span class="chip-badge-apt" style="background: #0d47a1;"><?= $totalToday ?></span>
            <a href="?<?= $aptViewQs ?>date=<?= $viewDate ?>&doctor=<?= urlencode((string)$selectedDoctor) ?><?= $selectedCallStatus !== 'all' ? '&call_status=' . urlencode($selectedCallStatus) : '' ?>"
               title="لابردنی پاڵاوتن" style="color: #0d47a1; margin-right: 4px; text-decoration: none;">
                <i class="fas fa-times"></i>
            </a>
        </span>
        <?php endif; ?>
    </div>

    <div class="filters-grid-appointments">
        <div class="calendar-nav-modern">
            <?php if ($viewMode === 'week'): ?>
                <a href="?view=week&date=<?= date('Y-m-d', strtotime($viewDate . ' -7 days')) ?><?= $aptFilterQs ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <span class="calendar-nav-date">
                    <?= $viewDateRange ?>
                </span>
                <a href="?view=week&date=<?= date('Y-m-d', strtotime($viewDate . ' +7 days')) ?><?= $aptFilterQs ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php elseif ($viewMode === 'month'): ?>
                <a href="?view=month&date=<?= date('Y-m-d', strtotime($viewDate . ' -1 month')) ?><?= $aptFilterQs ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <span class="calendar-nav-date">
                    <?= $viewDateRange ?>
                </span>
                <a href="?view=month&date=<?= date('Y-m-d', strtotime($viewDate . ' +1 month')) ?><?= $aptFilterQs ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <a href="?date=<?= date('Y-m-d', strtotime($viewDate . ' -1 day')) ?><?= $aptFilterQs ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <span class="calendar-nav-date">
                    <?= $dayName ?> - <?= $viewDate ?>
                </span>
                <a href="?date=<?= date('Y-m-d', strtotime($viewDate . ' +1 day')) ?><?= $aptFilterQs ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php endif; ?>
        </div>

        <div class="apt-filter-actions">
            <?php if ($hasFullAccess): ?>
            <select class="filter-control-appointments" onchange="location='?<?= $aptViewQs ?>date=<?= $viewDate ?><?= $selectedWorkType > 0 ? '&work_type=' . $selectedWorkType : '' ?><?= $selectedCallStatus !== 'all' ? '&call_status=' . urlencode($selectedCallStatus) : '' ?>&doctor='+this.value" style="min-width: 160px;">
                <option value="">هەموو دکتۆرەکان</option>
                <?php foreach ($doctors as $doc): ?>
                <option value="<?= $doc['id'] ?>" <?= $selectedDoctor == $doc['id'] ? 'selected' : '' ?>>
                    د. <?= htmlspecialchars($doc['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <!-- Work type (ئیش) filter -->
            <select class="filter-control-appointments<?= $selectedWorkType > 0 ? ' filter-active-apt' : '' ?>"
                    id="workTypeFilter"
                    onchange="location='?<?= $aptViewQs ?>date=<?= $viewDate ?>&doctor=<?= urlencode((string)$selectedDoctor) ?><?= $selectedCallStatus !== 'all' ? '&call_status=' . urlencode($selectedCallStatus) : '' ?>&work_type='+this.value"
                    style="min-width: 150px;" title="پاڵاوتن بەپێی ئیش">
                <option value="">هەموو ئیشەکان</option>
                <?php foreach ($workTypes as $wt): ?>
                <option value="<?= $wt['id'] ?>" <?= $selectedWorkType === (int)$wt['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($wt['work_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select class="filter-control-appointments call-filter-control<?= $selectedCallStatus !== 'all' ? ' filter-active-apt' : '' ?>"
                    id="callStatusFilter"
                    onchange="location='?<?= $aptViewQs ?>date=<?= $viewDate ?>&doctor=<?= urlencode((string)$selectedDoctor) ?><?= $selectedWorkType > 0 ? '&work_type=' . $selectedWorkType : '' ?>&call_status='+this.value"
                    style="min-width: 165px;" title="پاڵاوتن بەپێی بانگکردن">
                <option value="all" <?= $selectedCallStatus === 'all' ? 'selected' : '' ?>>هەموو بانگەکان (<?= count($callFilterAppointments) ?>)</option>
                <option value="called" <?= $selectedCallStatus === 'called' ? 'selected' : '' ?>>بانگکراوەکان (<?= $calledCount ?>)</option>
                <option value="not_called" <?= $selectedCallStatus === 'not_called' ? 'selected' : '' ?>>بانگ‌نەکراوەکان (<?= $notCalledCount ?>)</option>
            </select>

            <input type="date" class="filter-control-appointments" value="<?= $viewDate ?>"
                   onchange="location='?date='+this.value+'<?= $aptFilterQs ?>'">

            <!-- View Toggle -->
            <div class="view-toggle">
                <button type="button" class="view-toggle-btn active" id="tableViewBtn" onclick="switchView('table')">
                    <i class="fas fa-table"></i> تەیبڵ
                </button>
                <button type="button" class="view-toggle-btn" id="calendarViewBtn" onclick="switchView('calendar')">
                    <i class="fas fa-calendar"></i> کالێندەر
                </button>
            </div>

            <a href="<?= APP_URL ?>/appointments-by-type.php" class="btn" style="padding: 8px 16px; font-size: 13px; border-radius: 8px; font-weight: 600; margin-left: 6px; background: #0ea5e9; border-color: #0ea5e9; color: #fff;">
                <i class="fas fa-filter"></i> بەپێی جۆر
            </a>
            <a href="<?= APP_URL ?>/appointments-analytics.php" class="btn" style="padding: 8px 16px; font-size: 13px; border-radius: 8px; font-weight: 600; margin-left: 6px; background: #7c3aed; border-color: #7c3aed; color: #fff;">
                <i class="fas fa-chart-pie"></i> ئامارەکان
            </a>
            <a href="<?= APP_URL ?>/today-appointments.php" class="btn btn-success" style="padding: 8px 16px; font-size: 13px; border-radius: 8px; font-weight: 600; margin-left: 6px;">
                <i class="fas fa-calendar-day"></i> مەوعیدەکانی ئەمڕۆ
            </a>
            <?php if (!$hasFullAccess): ?>
            <button class="btn" onclick="openSendToReceptionDialog()" style="padding: 8px 16px; font-size: 13px; border-radius: 8px; font-weight: 600; margin-left: 6px; background: #0d9488; border-color: #0d9488; color: #fff;">
                <i class="fas fa-paper-plane"></i> ناردن بۆ ڕیسێپشن
            </button>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="openDialog('newAppointmentDialog')" style="padding: 8px 16px; font-size: 13px; border-radius: 8px; font-weight: 600;">
                <i class="fas fa-plus"></i> مەوعیدی نوێ
            </button>
        </div>
    </div>
</div>

<!-- FullCalendar View -->
<div id="calendarView" class="panel" style="margin-bottom: 16px;">
    <div class="panel-header">
        <h3 class="panel-title">
            <i class="fas fa-calendar"></i>
            کالێندەری مەوعیدەکان
        </h3>
    </div>
    <div class="panel-body" style="padding: 16px;">
        <div id="fullCalendar"></div>
    </div>
</div>

<div id="tableView" style="display: grid; grid-template-columns: 1fr 280px; gap: 16px;">
    <!-- Appointments Table -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title">
                <i class="fas fa-calendar-alt"></i>
                <?php if ($viewTitle): ?>
                    مەوعیدەکانی <?= $viewTitle ?> <span style="font-size: 12px; color: #757575; font-weight: normal;">(<?= $viewDateRange ?>)</span>
                <?php else: ?>
                    مەوعیدەکانی <?= $dayName ?>
                <?php endif; ?>
            </h3>
        </div>
        <div class="panel-body">
            <?php if (empty($appointments) && $selectedCallStatus !== 'all'): ?>
            <div class="empty-state-appointments">
                <i class="fas fa-phone-slash"></i>
                <h3><?= $selectedCallStatus === 'called' ? 'هیچ نەخۆشێکی بانگکراو نییە' : 'هەموو نەخۆشەکان بانگکراون' ?></h3>
                <p>فلتەری بانگکردن هیچ مەوعیدێکی لەم لیستەدا نەدۆزییەوە</p>
                <a href="?<?= $aptViewQs ?>date=<?= $viewDate ?>&doctor=<?= urlencode((string)$selectedDoctor) ?><?= $selectedWorkType > 0 ? '&work_type=' . $selectedWorkType : '' ?>"
                   class="btn btn-primary" style="padding: 10px 20px; font-size: 14px; border-radius: 8px;">
                    <i class="fas fa-list"></i> پیشاندانی هەموو مەوعیدەکان
                </a>
            </div>
            <?php elseif (empty($appointments) && $selectedWorkType > 0): ?>
            <div class="empty-state-appointments">
                <i class="fas fa-briefcase-medical"></i>
                <h3>هیچ مەوعیدێک بۆ «<?= htmlspecialchars($selectedWorkTypeName) ?>» نییە</h3>
                <p>هیچ مەوعیدێک نییە کە ئیشەکەی «<?= htmlspecialchars($selectedWorkTypeName) ?>» بێت</p>
                <a href="?<?= $aptViewQs ?>date=<?= $viewDate ?>&doctor=<?= urlencode((string)$selectedDoctor) ?>"
                   class="btn btn-primary" style="padding: 10px 20px; font-size: 14px; border-radius: 8px;">
                    <i class="fas fa-times"></i> لابردنی پاڵاوتن
                </a>
            </div>
            <?php elseif (empty($appointments)): ?>
            <div class="empty-state-appointments">
                <i class="fas fa-calendar-check"></i>
                <h3>هیچ مەوعیدێک نییە</h3>
                <p>بۆ ئەم ڕۆژە هیچ مەوعیدێک داڕێژرا نیە</p>
                <button class="btn btn-primary" onclick="openDialog('newAppointmentDialog')" style="padding: 10px 20px; font-size: 14px; border-radius: 8px;">
                    <i class="fas fa-plus"></i> زیادکردنی مەوعید
                </button>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="appointments-table">
                    <thead>
                        <tr>
                            <?php if ($viewMode === 'week' || $viewMode === 'month'): ?>
                            <th>بەروار</th>
                            <?php endif; ?>
                            <th>کات</th>
                            <th>نەخۆش</th>
                            <th>دکتۆر</th>
                            <th>ماوە</th>
                            <th>ئیش</th>
                            <th>ژ. مۆبایل</th>
                            <th>پارەی قەرز</th>
                            <th>پارەی هێنراو</th>
                            <th>بانگکراو</th>
                            <th>تێبینی</th>
                            <th>دۆخ</th>
                            <th>کردار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $apt):
                            $statusLabels = [
                                'scheduled' => ['', 'داڕێژراو', '#ff8c00'],
                                'confirmed' => ['badge-blue', 'پشتڕاستکراو', '#0078d4'],
                                'waiting' => ['badge-orange', 'چاوەڕوان', '#ff9800'],
                                'in_progress' => ['badge-blue', 'لە چارەسەردا', '#0078d4'],
                                'completed' => ['badge-green', 'تەواوبوو', '#4caf50'],
                                'cancelled' => ['badge-red', 'هەڵوەشاوە', '#f44336'],
                                'no_show'      => ['badge-red', 'نەهات', '#f44336'],
                                'rescheduled'  => ['badge-orange', 'دواخراو', '#ff9800']
                            ];
                            $st = $statusLabels[$apt['status']] ?? ['', $apt['status'], '#666'];
                        ?>
                        <tr data-appointment-id="<?= (int)$apt['id'] ?>" data-call-status="<?= !empty($apt['called_to_center_at']) ? 'called' : 'not_called' ?>">
                            <?php if ($viewMode === 'week' || $viewMode === 'month'): ?>
                            <td>
                                <?php
                                    $aptDate = strtotime($apt['appointment_date']);
                                    $aptDayNum = date('w', $aptDate);
                                    $aptDayName = $days[$aptDayNum];
                                ?>
                                <div style="font-weight: 600; color: #2c3e50; font-size: 12px;"><?= $aptDayName ?></div>
                                <div style="font-size: 11px; color: #757575;"><?= date('Y-m-d', $aptDate) ?></div>
                            </td>
                            <?php endif; ?>
                            <td>
                                <span class="time-cell-apt"><?= date('H:i', strtotime($apt['appointment_time'])) ?></span>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/patients/view.php?id=<?= $apt['patient_id'] ?>" class="patient-name-apt">
                                    <?= htmlspecialchars($apt['patient_name']) ?>
                                </a>
                                <?php // «کەیس» by the name, so a wide table never hides it — the appointment's doctor's case ?>
                                <?php if (case_button_allowed($auth, (int)$apt['patient_id'])): ?>
                                <div style="margin-top: 5px;"><?= case_button((int)$apt['patient_id'], ['size' => 'sm', 'doctor' => (int)$apt['doctor_id']]) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: #546e7a;">
                                د. <?= htmlspecialchars($apt['doctor_name']) ?>
                            </td>
                            <td>
                                <span class="duration-cell-apt"><?= $apt['duration'] ?> خولەک</span>
                            </td>
                            <td style="font-size: 12px;">
                                <?php if (!empty($apt['work_type_name'])): ?>
                                    <span class="work-type-cell-apt"><i class="fas fa-briefcase-medical"></i> <?= htmlspecialchars($apt['work_type_name']) ?></span>
                                <?php else: ?>
                                    <span style="color:#9aa7b3;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family: var(--app-font-family); font-size: 12px; direction: ltr;">
                                <?= $apt['patient_phone'] ?>
                            </td>
                            <td style="font-size: 12px;">
                                <?php $aptDebt = max(0, (float)($apt['total_debt'] ?? 0)); ?>
                                <?php if ($aptDebt > 0): ?>
                                    <span style="color:#c62828; font-weight:600;"><?= number_format($aptDebt) ?></span>
                                <?php else: ?>
                                    <span style="color:#9aa7b3; font-size:11px;">بێ قەرز</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px;">
                                <?php $aptPromised = (float)($apt['promised_amount'] ?? 0); ?>
                                <?php if ($aptPromised > 0): ?>
                                    <span style="color:#15803d; font-weight:600;"><?= number_format($aptPromised) ?></span>
                                <?php else: ?>
                                    <span style="color:#9aa7b3;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($apt['called_to_center_at'])): ?>
                                    <span class="appointment-call-state called">
                                        <span class="call-main"><i class="fas fa-phone-alt"></i> بانگکراوە</span>
                                        <span class="call-meta"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($apt['called_to_center_at']))) ?></span>
                                    </span>
                                <?php else: ?>
                                    <span class="appointment-call-state not-called">
                                        <span class="call-main"><i class="fas fa-phone-slash"></i> بانگ‌نەکراوە</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 11px; color: #757575;">
                                <?= !empty($apt['notes']) ? htmlspecialchars($apt['notes']) : '-' ?>
                            </td>
                            <td>
                                <span class="badge <?= $st[0] ?>" style="font-size: 11px;"><?= $st[1] ?></span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; justify-content: center;">
                                    <?php if (in_array($apt['status'], ['scheduled', 'confirmed', 'rescheduled'], true)): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="mark_called_to_center">
                                        <input type="hidden" name="appointment_id" value="<?= (int)$apt['id'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-call-appointment<?= !empty($apt['called_to_center_at']) ? ' recall' : '' ?>"
                                                data-testid="appointment-call-<?= (int)$apt['id'] ?>"
                                                aria-label="<?= !empty($apt['called_to_center_at']) ? 'دووبارە بانگکردن' : 'تۆمارکردن وەک بانگکراو' ?>"
                                                title="<?= !empty($apt['called_to_center_at']) ? 'دووبارە بانگکردن و نوێکردنەوەی کات' : 'تۆمارکردن وەک بانگکراو بۆ سەنتەر' ?>"
                                                style="padding: 4px 8px;">
                                            <i class="fas <?= !empty($apt['called_to_center_at']) ? 'fa-phone-volume' : 'fa-phone-alt' ?>"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($apt['status'] === 'scheduled' || $apt['status'] === 'confirmed'): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="check_in">
                                        <input type="hidden" name="appointment_id" value="<?= $apt['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success" title="چاوەڕوانی" style="padding: 4px 8px;">
                                            <i class="fas fa-user-check"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-secondary" onclick="toggleDropdown(this, event)" style="padding: 4px 8px;">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php if (in_array($apt['status'], ['scheduled', 'confirmed', 'rescheduled'], true)): ?>
                                            <form method="POST" action="">
                                                <input type="hidden" name="action" value="mark_called_to_center">
                                                <input type="hidden" name="appointment_id" value="<?= (int)$apt['id'] ?>">
                                                <button type="submit" class="dropdown-item" style="color: #08783e;">
                                                    <i class="fas fa-phone-alt"></i>
                                                    <?= !empty($apt['called_to_center_at']) ? 'دووبارە بانگکردن' : 'بانگکراوە بۆ سەنتەر' ?>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if (!empty($apt['called_to_center_at'])): ?>
                                            <form method="POST" action="" onsubmit="return confirm('دڵنیایت لە هەڵوەشاندنەوەی نیشانەی بانگکردن؟')">
                                                <input type="hidden" name="action" value="clear_called_to_center">
                                                <input type="hidden" name="appointment_id" value="<?= (int)$apt['id'] ?>">
                                                <button type="submit" class="dropdown-item" style="color: #6b7280;">
                                                    <i class="fas fa-undo"></i> هەڵوەشاندنەوەی بانگ
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <?php
                                            $editData = json_encode([
                                                'id'              => $apt['id'],
                                                'patient_id'      => $apt['patient_id'],
                                                'patient_name'    => $apt['patient_name'],
                                                'patient_phone'   => $apt['patient_phone'],
                                                'doctor_id'       => $apt['doctor_id'],
                                                'appointment_date'=> $apt['appointment_date'],
                                                'appointment_time'=> date('H:i', strtotime($apt['appointment_time'])),
                                                'duration'        => (int)$apt['duration'],
                                                'type'            => $apt['type'],
                                                'work_type_id'    => $apt['work_type_id'] ?? '',
                                                'status'          => $apt['status'],
                                                'notes'           => $apt['notes'] ?? '',
                                                'promised_amount' => $apt['promised_amount'] ?? '',
                                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                                            ?>
                                            <button type="button" class="dropdown-item" style="color: var(--win-accent, #0078d4);"
                                                    data-apt='<?= htmlspecialchars($editData, ENT_QUOTES) ?>'
                                                    onclick="openEditAppointmentDialog(this)">
                                                <i class="fas fa-edit"></i> دەستکاری
                                            </button>
                                            <form method="POST" action="">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="appointment_id" value="<?= $apt['id'] ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="fas fa-check-double" style="color: #4caf50;"></i> تەواوبوو
                                                </button>
                                            </form>
                                            <button type="button" class="dropdown-item" style="color: #f57c00;"
                                                    onclick="openRescheduleDialog(<?= $apt['id'] ?>, '<?= htmlspecialchars(addslashes($apt['patient_name'])) ?>', '<?= $apt['patient_phone'] ?>', '<?= $apt['appointment_date'] ?>', '<?= date('H:i', strtotime($apt['appointment_time'])) ?>', <?= $apt['doctor_id'] ?>)">
                                                <i class="fas fa-calendar-alt"></i> دواخستن
                                            </button>
                                            <button type="button" class="dropdown-item" style="color: #f44336;" onclick="openCancelDialog(<?= $apt['id'] ?>)">
                                                <i class="fas fa-times"></i> هەڵوەشاندنەوە
                                            </button>
                                            <button type="button" class="dropdown-item" style="color: #b71c1c; border-top: 1px solid #f0f0f0;"
                                                    onclick="deleteAppointment(<?= $apt['id'] ?>, <?= htmlspecialchars(json_encode($apt['patient_name'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)">
                                                <i class="fas fa-trash-alt"></i> سڕینەوە
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Side Panel -->
    <div>
        <!-- Available Slots -->
        <div class="side-panel-modern" style="margin-bottom: 12px;">
            <div class="side-panel-header">
                <i class="fas fa-clock"></i>
                کاتە بەتاڵەکان
            </div>
            <div class="side-panel-body">
                <?php if (empty($availableSlots)): ?>
                <p style="text-align: center; color: #9e9e9e; font-size: 13px; margin: 0;">هیچ کاتێکی بەتاڵ نییە</p>
                <?php else: ?>
                <div class="time-slots-modern">
                    <?php foreach ($availableSlots as $slot): ?>
                    <div class="time-slot-modern" onclick="selectTimeSlot('<?= $slot ?>')">
                        <?= $slot ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rescheduled Appointments Panel -->
        <?php
        $rescheduledApts = array_filter($appointments, fn($a) => $a['status'] === 'rescheduled');
        if (!empty($rescheduledApts)):
        ?>
        <div class="side-panel-modern" style="margin-bottom: 12px; border-color: #ff9800;">
            <div class="side-panel-header" style="background: linear-gradient(135deg, #ff9800, #e65100);">
                <i class="fas fa-calendar-alt"></i>
                دواخراوەکان (<?= count($rescheduledApts) ?>)
            </div>
            <div class="side-panel-body" style="padding: 8px;">
                <?php foreach ($rescheduledApts as $rApt): ?>
                <div style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 8px 10px; margin-bottom: 6px; font-size: 12px;">
                    <div style="font-weight: 700; color: #37474f; margin-bottom: 4px;">
                        <i class="fas fa-user" style="color: #ff8f00;"></i>
                        <?= htmlspecialchars($rApt['patient_name']) ?>
                    </div>
                    <?php if (!empty($rApt['rescheduled_from_date'])): ?>
                    <div style="color: #f44336; text-decoration: line-through; margin-bottom: 2px;">
                        <i class="fas fa-calendar-times"></i>
                        <?= $rApt['rescheduled_from_date'] ?> — <?= date('H:i', strtotime($rApt['rescheduled_from_time'])) ?>
                    </div>
                    <?php endif; ?>
                    <div style="color: #2e7d32; font-weight: 600;">
                        <i class="fas fa-calendar-check"></i>
                        <?= $rApt['appointment_date'] ?> — <?= date('H:i', strtotime($rApt['appointment_time'])) ?>
                    </div>
                    <div style="color: #78909c; margin-top: 3px;">
                        <i class="fas fa-user-md"></i> د. <?= htmlspecialchars($rApt['doctor_name']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Guide Panel -->
        <div class="side-panel-modern">
            <div class="side-panel-header">
                <i class="fas fa-info-circle"></i>
                ڕێنمایی
            </div>
            <div class="side-panel-body">
                <ul class="guide-list">
                    <li>
                        <i class="fas fa-circle"></i>
                        <span>کلیک لەسەر کات بکە بۆ دروستکردنی مەوعید</span>
                    </li>
                    <li>
                        <i class="fas fa-circle"></i>
                        <span>مەوعیدەکان دەتوانرێت بگۆڕدرێن</span>
                    </li>
                    <li>
                        <i class="fas fa-circle"></i>
                        <span>پەیوەندی بەنەخۆش بکە بۆ پشتڕاستکردنەوە</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- New Appointment Dialog - Modern Grid Layout -->
<div class="dialog-overlay" id="newAppointmentDialog">
    <div class="dialog apt-dialog-modern">
        <div class="apt-dialog-header">
            <h4><i class="fas fa-calendar-plus"></i> مەوعیدی نوێ</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('newAppointmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="appointmentForm">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="patient_id" id="selectedPatientId" required>
            <input type="hidden" name="appointment_time" id="appointmentTime" required>

            <div class="apt-dialog-body">
                <!-- Row 1: Patient Search -->
                <div class="apt-form-grid">
                    <div class="apt-form-field apt-field-2col">
                        <label><i class="fas fa-user"></i> نەخۆش <span class="required">*</span></label>
                        <div class="apt-patient-search">
                            <input type="text" id="patientSearchInput" placeholder="ناو یان مۆبایل..." autocomplete="off">
                            <div id="patientSearchResults" class="apt-search-results"></div>
                        </div>
                        <div id="selectedPatientDisplay" class="apt-selected-patient"></div>
                    </div>

                    <?php if ($hasFullAccess): ?>
                    <div class="apt-form-field">
                        <label><i class="fas fa-user-md"></i> دکتۆر <span class="required">*</span></label>
                        <select name="doctor_id" required onchange="refreshSmartTimePicker()">
                            <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id'] ?>">د. <?= htmlspecialchars($doc['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="doctor_id" value="<?= $currentUser['id'] ?>">
                    <div class="apt-form-field">
                        <label><i class="fas fa-hourglass-half"></i> ماوە</label>
                        <select name="duration" id="appointmentDuration">
                            <?php echo dh_duration_options(30); ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Row 2: Date, Duration -->
                <div class="apt-form-grid" style="margin-top: 10px;">
                    <div class="apt-form-field">
                        <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                        <input type="date" name="appointment_date" id="appointmentDate" value="<?= $viewDate ?>" required onchange="refreshSmartTimePicker()">
                    </div>
                    <?php if ($hasFullAccess): ?>
                    <div class="apt-form-field">
                        <label><i class="fas fa-hourglass-half"></i> ماوە</label>
                        <select name="duration" id="appointmentDuration">
                            <?php echo dh_duration_options(30); ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="apt-form-field">
                        <label><i class="fas fa-tag"></i> جۆر</label>
                        <select name="type">
                            <option value="general">گشتی</option>
                            <option value="checkup">پشکنین</option>
                            <option value="treatment">چارەسەر</option>
                        </select>
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-briefcase-medical"></i> ئیش</label>
                        <div class="work-type-combo" style="display: flex; gap: 4px;">
                            <select name="work_type_id" id="appointmentWorkType" style="flex: 1;">
                                <option value="">— هەڵبژێرە —</option>
                                <?php foreach ($workTypes as $wt): ?>
                                <option value="<?= $wt['id'] ?>"><?= htmlspecialchars($wt['work_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="apt-btn-add-work" onclick="openAddWorkTypeDialog()" title="زیادکردنی ئیشی نوێ"
                                    style="padding: 0 10px; background: var(--win-accent, #0078d4); color: #fff; border: 1px solid var(--win-accent, #0078d4); border-radius: 4px; cursor: pointer;">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Debt / Promised amount - revealed after patient is selected -->
                <div id="aptDebtSection" class="apt-form-grid" style="margin-top: 10px; display: none;">
                    <div class="apt-form-field">
                        <label><i class="fas fa-hand-holding-usd" style="color:#c62828;"></i> پارەی قەرز</label>
                        <div id="aptPatientDebt"
                             style="padding: 8px 10px; background: #fff0f0; border: 1px solid #fca5a5; border-radius: 6px; font-weight: 700; color: #c62828; text-align: center; min-height: 36px; display: flex; align-items: center; justify-content: center;">
                            <span style="color:#9aa7b3; font-weight: 400;">—</span>
                        </div>
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-money-bill-wave" style="color:#16a34a;"></i> بڕی پارەی هێنراو</label>
                        <input type="number" name="promised_amount" id="aptPromisedAmount"
                               min="0" step="500" placeholder="0"
                               style="direction: ltr; text-align: left;">
                    </div>
                </div>

                <!-- Existing Bookings - Inline -->
                <div class="existing-bookings-section" id="aptExistingBookingsSection" style="display: none;">
                    <label><i class="fas fa-calendar-check"></i> گیراوەکان</label>
                    <div id="aptExistingBookingsList" class="existing-bookings-list"></div>
                </div>

                <!-- Time Picker Section -->
                <div class="apt-time-section" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                    <label><i class="fas fa-clock"></i> کات <span class="required">*</span></label>
                    <input type="hidden" name="end_time" id="appointmentEndTime">
                    <div id="smartTimePickerContainer" class="apt-time-grid-container">
                        <div class="apt-time-loading"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                    <div class="apt-time-legend">
                        <span class="legend-available"><i class="fas fa-circle"></i> بەردەست</span>
                        <span class="legend-busy"><i class="fas fa-circle"></i> گیراو</span>
                        <span class="legend-break"><i class="fas fa-circle"></i> پشوو</span>
                    </div>
                </div>

                <!-- Booking Summary - Compact Horizontal -->
                <div class="booking-summary-apt" id="aptBookingSummary" style="display: none;">
                    <div class="summary-title"><i class="fas fa-info-circle"></i> پوختە</div>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="summary-label">بەروار</span>
                            <span class="summary-value" id="aptSummaryDate">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">دەستپێک</span>
                            <span class="summary-value" id="aptSummaryStartTime">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">کۆتایی</span>
                            <span class="summary-value" id="aptSummaryEndTime">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">ماوە</span>
                            <span class="summary-value" id="aptSummaryDuration">-</span>
                        </div>
                    </div>
                </div>

                <!-- Collapsible Notes -->
                <div class="apt-advanced-toggle" onclick="toggleAdvancedOptions()">
                    <span><i class="fas fa-sticky-note"></i> تێبینی</span>
                    <i class="fas fa-chevron-down apt-toggle-icon"></i>
                </div>

                <div class="apt-advanced-options" id="aptAdvancedOptions">
                    <input type="text" name="notes" placeholder="تێبینی..." style="width: 100%; padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 12px; background: #fafafa;">
                </div>
            </div>

            <div class="apt-dialog-footer">
                <button type="submit" class="apt-btn-primary"><i class="fas fa-check"></i> دروستکردن</button>
                <button type="button" class="apt-btn-secondary" onclick="closeDialog('newAppointmentDialog')"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$hasFullAccess): ?>
<!-- Send day list to reception Dialog (doctor only) -->
<div class="dialog-overlay" id="sendToReceptionDialog">
    <div class="dialog apt-dialog-modern" style="max-width: 480px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);">
            <h4><i class="fas fa-paper-plane"></i> ناردنی لیستی مەوعید بۆ ڕیسێپشن</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('sendToReceptionDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="sendToReceptionForm">
            <input type="hidden" name="action" value="send_to_reception">
            <input type="hidden" name="selected_ids" id="strSelectedIds" value="">
            <div class="apt-dialog-body">
                <div class="apt-form-field">
                    <label><i class="fas fa-calendar"></i> بەرواری لیستەکە <span class="required">*</span></label>
                    <input type="date" name="list_date" id="strListDate" value="<?= date('Y-m-d') ?>" required onchange="strLoadList()">
                </div>
                <div class="apt-form-field" style="margin-top: 10px;">
                    <label style="display: flex; align-items: center; justify-content: space-between;">
                        <span><i class="fas fa-users"></i> نەخۆشەکان <span id="strCountBadge" style="color:#0d9488;"></span></span>
                        <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; cursor: pointer; font-weight: 400;" onclick="event.preventDefault(); strToggleAll(!document.getElementById('strSelectAll').checked); document.getElementById('strSelectAll').checked = !document.getElementById('strSelectAll').checked;">
                            <input type="checkbox" id="strSelectAll" checked onclick="event.stopPropagation();" onchange="strToggleAll(this.checked)"> هەمووی
                        </span>
                    </label>
                    <div id="strPatientList" style="max-height: 220px; overflow-y: auto; border: 1.5px solid #e0e0e0; border-radius: 6px; background: #fafafa;">
                        <div style="padding: 14px; text-align: center; color: #9aa7b3; font-size: 12px;"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                </div>
                <div class="apt-form-field" style="margin-top: 10px;">
                    <label><i class="fas fa-sticky-note"></i> تێبینی بۆ ڕیسێپشن (ئارەزوومەندانە)</label>
                    <input type="text" name="list_note" placeholder="تێبینی...">
                </div>
                <div style="margin-top: 12px; padding: 10px 12px; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 6px; font-size: 12px; color: #0f766e; line-height: 1.8;">
                    <i class="fas fa-info-circle"></i>
                    تەنیا نەخۆشە هەڵبژێردراوەکان دەنێردرێن. ڕیسێپشن لیستەکە دەبینێت بۆ پەیوەندیکردن —
                    <strong>پارەی هێنراو</strong> پیشان دەدرێت بەڵام <strong>قەرز پیشان نادرێت</strong>.
                </div>
            </div>
            <div class="apt-dialog-footer">
                <button type="submit" class="apt-btn-primary" style="background: #0d9488; border-color: #0d9488;"><i class="fas fa-paper-plane"></i> ناردن</button>
                <button type="button" class="apt-btn-secondary" onclick="closeDialog('sendToReceptionDialog')"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Send-to-reception: patient picker (doctor only) ──
function openSendToReceptionDialog() {
    openDialog('sendToReceptionDialog');
    strLoadList();
}

async function strLoadList() {
    const date = document.getElementById('strListDate').value;
    const box  = document.getElementById('strPatientList');
    const badge = document.getElementById('strCountBadge');
    badge.textContent = '';
    if (!date) {
        box.innerHTML = '<div style="padding:14px;text-align:center;color:#9aa7b3;font-size:12px;">بەروار هەڵبژێرە</div>';
        return;
    }
    box.innerHTML = '<div style="padding:14px;text-align:center;color:#9aa7b3;font-size:12px;"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch(`${SCHEDULE_API}?action=get_bookings&doctor_id=<?= (int)$currentUser['id'] ?>&date=${date}`);
        const d = await res.json();
        const rows = (d.success && d.data) ? d.data : [];
        if (!rows.length) {
            box.innerHTML = '<div style="padding:14px;text-align:center;color:#e65100;font-size:12px;"><i class="fas fa-calendar-times"></i> بۆ ئەم ڕۆژە هیچ مەوعیدێک نییە</div>';
            strSyncAll();
            return;
        }
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        box.innerHTML = rows.map(b => `
            <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-bottom:1px solid #eef2f5;cursor:pointer;font-size:12px;background:#fff;">
                <input type="checkbox" class="str-check" value="${parseInt(b.id)}" checked onchange="strSyncAll()">
                <span style="font-weight:700;color:var(--win-accent, #0078d4);direction:ltr;">${esc((b.start_time || '').substring(0, 5))}</span>
                <span style="flex:1;">${esc(b.patient_name || 'مەوعید')}</span>
            </label>`).join('');
        strSyncAll();
    } catch (e) {
        console.error(e);
        box.innerHTML = '<div style="padding:14px;text-align:center;color:#c62828;font-size:12px;">هەڵەیەک ڕوویدا</div>';
    }
}

function strToggleAll(on) {
    document.querySelectorAll('.str-check').forEach(c => { c.checked = on; });
    strSyncAll();
}

function strSyncAll() {
    const all = [...document.querySelectorAll('.str-check')];
    const checked = all.filter(c => c.checked);
    document.getElementById('strSelectAll').checked = all.length > 0 && checked.length === all.length;
    document.getElementById('strCountBadge').textContent = all.length ? `(${checked.length}/${all.length})` : '';
}

document.getElementById('sendToReceptionForm')?.addEventListener('submit', function(e) {
    const all = [...document.querySelectorAll('.str-check')];
    const checked = all.filter(c => c.checked).map(c => c.value);
    if (!all.length || !checked.length) {
        e.preventDefault();
        alert('تکایە لانیکەم یەک نەخۆش هەڵبژێرە');
        return;
    }
    // Empty value = whole day; ids only when it's a subset
    document.getElementById('strSelectedIds').value = (checked.length === all.length) ? '' : checked.join(',');
});
</script>
<?php endif; ?>

<!-- Add Work Type Dialog -->
<div class="dialog-overlay" id="addWorkTypeDialog">
    <div class="dialog apt-dialog-modern" style="max-width: 400px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);">
            <h4><i class="fas fa-briefcase-medical"></i> زیادکردنی ئیشی نوێ</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('addWorkTypeDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="apt-dialog-body">
            <div class="apt-form-field">
                <label><i class="fas fa-tag"></i> ناوی ئیش <span class="required" style="color:#f44336;">*</span></label>
                <input type="text" id="newWorkTypeName" placeholder="بۆ نموونە: تیشکی ددان" style="width: 100%;">
            </div>
            <div id="addWorkTypeError" style="color: #f44336; font-size: 13px; margin-top: 6px; display: none;"></div>
        </div>
        <div class="apt-dialog-footer">
            <button type="button" class="apt-btn-secondary" onclick="closeDialog('addWorkTypeDialog')"><i class="fas fa-times"></i> هەڵوەشاندنەوە</button>
            <button type="button" class="apt-btn-primary" onclick="submitNewWorkType()"><i class="fas fa-check"></i> زیادکردن</button>
        </div>
    </div>
</div>

<!-- Cancellation Dialog -->
<div class="dialog-overlay" id="cancelAppointmentDialog">
    <div class="dialog apt-dialog-modern" style="max-width: 450px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, #f44336 0%, #c62828 100%);">
            <h4><i class="fas fa-calendar-times"></i> هەڵوەشاندنەوەی مەوعید</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('cancelAppointmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="cancelForm">
            <input type="hidden" name="action" value="cancel_with_reason">
            <input type="hidden" name="appointment_id" id="cancelAppointmentId">

            <div class="apt-dialog-body" style="padding: 20px;">
                <div class="cancel-warning" style="background: #fff3e0; border: 1px solid #ffe0b2; border-radius: 8px; padding: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-exclamation-triangle" style="color: #f57c00; font-size: 20px;"></i>
                    <span style="color: #e65100; font-size: 13px;">ئایا دڵنیایت لە هەڵوەشاندنەوەی ئەم مەوعیدە؟</span>
                </div>

                <div class="apt-form-field" style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #37474f;"><i class="fas fa-question-circle"></i> هۆکاری هەڵوەشاندنەوە <span class="required" style="color: #f44336;">*</span></label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <select name="cancellation_reason" id="cancellationReason" required style="flex: 1; padding: 10px 12px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 13px; font-family: inherit;">
                            <option value="">-- هۆکارێک هەڵبژێرە --</option>
                            <?php foreach ($cancellationReasons as $reason): ?>
                            <option value="<?= htmlspecialchars($reason['reason_key']) ?>"><?= htmlspecialchars($reason['reason_text']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($hasFullAccess): ?>
                        <button type="button" onclick="toggleAddReasonForm()" class="btn btn-success" style="padding: 10px 12px; border-radius: 8px;" title="زیادکردنی هۆکاری نوێ">
                            <i class="fas fa-plus"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <!-- Inline Add Reason Form -->
                    <?php if ($hasFullAccess): ?>
                    <div id="addReasonForm" style="display: none; margin-top: 10px; padding: 12px; background: #f0f8ff; border: 1px solid var(--win-accent, #0078d4); border-radius: 8px;">
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" id="newReasonText" placeholder="هۆکاری نوێ بنووسە..." style="flex: 1; padding: 8px 12px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 13px; font-family: inherit;">
                            <button type="button" onclick="saveNewReason()" class="btn btn-primary" style="padding: 8px 14px; border-radius: 6px;">
                                <i class="fas fa-check"></i> زیادکردن
                            </button>
                            <button type="button" onclick="toggleAddReasonForm()" class="btn btn-secondary" style="padding: 8px 10px; border-radius: 6px;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="apt-form-field">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #37474f;"><i class="fas fa-comment"></i> تێبینی (ئارەزوومەندانە)</label>
                    <textarea name="cancellation_comment" id="cancellationComment" rows="3" placeholder="تێبینی زیاتر بنووسە..." style="width: 100%; padding: 10px 12px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 13px; font-family: inherit; resize: vertical;"></textarea>
                </div>
            </div>

            <div class="apt-dialog-footer" style="padding: 16px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; gap: 10px; justify-content: flex-start;">
                <button type="submit" class="apt-btn-primary" style="background: #f44336; border-color: #f44336;"><i class="fas fa-times-circle"></i> هەڵوەشاندنەوە</button>
                <button type="button" class="apt-btn-secondary" onclick="closeDialog('cancelAppointmentDialog')"><i class="fas fa-arrow-right"></i> گەڕانەوە</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancelled Appointments List Dialog -->
<div class="dialog-overlay" id="cancelledListDialog">
    <div class="dialog apt-dialog-modern" style="max-width: 600px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, #f44336 0%, #b71c1c 100%);">
            <h4><i class="fas fa-calendar-times"></i> هەڵوەشاندنەوەکان
                <?php if ($cancelledCount > 0): ?>
                <span style="background: rgba(255,255,255,0.25); border-radius: 20px; padding: 2px 10px; font-size: 13px; margin-right: 8px;"><?= $cancelledCount ?></span>
                <?php endif; ?>
            </h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('cancelledListDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="apt-dialog-body" style="padding: 0; max-height: 65vh; overflow-y: auto;">
            <?php if (empty($cancelledApts)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #9e9e9e;">
                <i class="fas fa-check-circle" style="font-size: 40px; color: #4caf50; display: block; margin-bottom: 12px;"></i>
                <div style="font-size: 14px;">هیچ مەوعیدێک هەڵوەشاندراوە نییە</div>
            </div>
            <?php else: ?>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="background: #fafafa; border-bottom: 2px solid #f5f5f5; position: sticky; top: 0; z-index: 1;">
                        <th style="padding: 10px 14px; text-align: right; font-weight: 600; color: #546e7a;">نەخۆش</th>
                        <th style="padding: 10px 8px; text-align: center; font-weight: 600; color: #546e7a;">کات</th>
                        <th style="padding: 10px 8px; text-align: center; font-weight: 600; color: #546e7a;">دکتۆر</th>
                        <th style="padding: 10px 8px; text-align: center; font-weight: 600; color: #546e7a;">هۆکار</th>
                        <th style="padding: 10px 8px; text-align: center; font-weight: 600; color: #546e7a;">کاتی هەڵوەشاندن</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cancelledApts as $cApt): ?>
                    <tr style="border-bottom: 1px solid #f5f5f5;">
                        <td style="padding: 10px 14px;">
                            <div style="font-weight: 600; color: #37474f;"><?= htmlspecialchars($cApt['patient_name']) ?></div>
                            <div style="font-size: 11px; color: #90a4ae; direction: ltr;"><?= $cApt['patient_phone'] ?></div>
                        </td>
                        <td style="padding: 10px 8px; text-align: center; color: #546e7a;">
                            <?= date('H:i', strtotime($cApt['appointment_time'])) ?>
                        </td>
                        <td style="padding: 10px 8px; text-align: center; color: #546e7a; font-size: 12px;">
                            د. <?= htmlspecialchars($cApt['doctor_name']) ?>
                        </td>
                        <td style="padding: 10px 8px; text-align: center;">
                            <?php if (!empty($cApt['cancellation_reason'])): ?>
                            <span style="background: #ffebee; color: #c62828; border-radius: 4px; padding: 2px 8px; font-size: 11px; display: inline-block;">
                                <?= htmlspecialchars($cApt['cancellation_reason']) ?>
                            </span>
                            <?php else: ?>
                            <span style="color: #bdbdbd; font-size: 11px;">—</span>
                            <?php endif; ?>
                            <?php if (!empty($cApt['cancellation_comment'])): ?>
                            <div style="font-size: 11px; color: #78909c; margin-top: 3px; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                 title="<?= htmlspecialchars($cApt['cancellation_comment']) ?>">
                                <?= htmlspecialchars($cApt['cancellation_comment']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px 8px; text-align: center; font-size: 11px; color: #90a4ae;">
                            <?= !empty($cApt['cancelled_at']) ? date('H:i', strtotime($cApt['cancelled_at'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="apt-dialog-footer" style="padding: 12px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <a href="<?= APP_URL ?>/cancellation-report.php" style="font-size: 12px; color: var(--win-accent, #0078d4); text-decoration: none;">
                <i class="fas fa-external-link-alt"></i> ڕاپۆرتی تەواو
            </a>
            <button type="button" class="apt-btn-secondary" onclick="closeDialog('cancelledListDialog')">
                <i class="fas fa-arrow-right"></i> داخستن
            </button>
        </div>
    </div>
</div>

<!-- Rescheduled Appointments List Dialog -->
<?php
// Load ALL rescheduled appointments from DB (not just current view).
// Doctor isolation: a doctor sees only their OWN rescheduled appointments,
// never other doctors' patients.
$rescheduledWhere = "a.status = 'rescheduled'";
$rescheduledParams = [];
if (AccessGuard::isRestrictedDoctor()) {
    $rescheduledWhere .= " AND a.doctor_id = :rd";
    $rescheduledParams['rd'] = AccessGuard::doctorId();
}
$allRescheduled = $db->fetchAll(
    "SELECT a.*, p.full_name as patient_name, p.phone as patient_phone, u.full_name as doctor_name
     FROM appointments a
     LEFT JOIN patients p ON a.patient_id = p.id
     LEFT JOIN users u ON a.doctor_id = u.id
     WHERE {$rescheduledWhere}
     ORDER BY a.rescheduled_at DESC, a.appointment_date DESC",
    $rescheduledParams
);
?>
<div class="dialog-overlay" id="rescheduledListDialog">
    <div class="dialog apt-dialog-modern" style="max-width: 680px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, #ff9800 0%, #e65100 100%);">
            <h4><i class="fas fa-calendar-alt"></i> دواخستنەکان
                <span id="rldTotalBadge" style="background: rgba(255,255,255,0.25); border-radius: 20px; padding: 2px 10px; font-size: 13px; margin-right: 8px;"></span>
            </h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('rescheduledListDialog')"><i class="fas fa-times"></i></button>
        </div>

        <!-- Filter Bar -->
        <div style="padding: 12px 16px; background: #fff8f0; border-bottom: 1px solid #ffe0b2;">
            <!-- Search -->
            <div style="display: flex; gap: 8px; margin-bottom: 10px; align-items: center;">
                <div style="position: relative; flex: 1;">
                    <i class="fas fa-search" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #bdbdbd; font-size: 13px;"></i>
                    <input type="text" id="rldSearch" placeholder="گەڕان بەناوی نەخۆش..." oninput="rldApplyFilters()"
                           style="width: 100%; padding: 8px 32px 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 13px; font-family: inherit; box-sizing: border-box;">
                </div>
                <button onclick="rldReset()" style="padding: 8px 12px; background: #fff; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 12px; color: #78909c; cursor: pointer; white-space: nowrap;">
                    <i class="fas fa-times"></i> پاکردنەوە
                </button>
            </div>
            <!-- Quick filters -->
            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px;">
                <button class="rld-quick-btn active" onclick="rldSetQuick('all',this)">هەمووی</button>
                <button class="rld-quick-btn" onclick="rldSetQuick('today',this)">ئەمڕۆ</button>
                <button class="rld-quick-btn" onclick="rldSetQuick('week',this)">ئەم هەفتەیە</button>
                <button class="rld-quick-btn" onclick="rldSetQuick('month',this)">ئەم مانگە</button>
                <button class="rld-quick-btn" onclick="rldSetQuick('year',this)">ئەم ساڵە</button>
            </div>
            <!-- Month / Year dropdowns -->
            <div style="display: flex; gap: 8px; align-items: center;">
                <select id="rldMonth" onchange="rldSetQuick('custom',null)"
                        style="padding: 7px 10px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 12px; font-family: inherit; background: #fff;">
                    <option value="">-- مانگ --</option>
                    <option value="1">کانوونی دووەم</option><option value="2">شوبات</option>
                    <option value="3">ئازار</option><option value="4">نیسان</option>
                    <option value="5">ئایار</option><option value="6">حوزەیران</option>
                    <option value="7">تەممووز</option><option value="8">ئاب</option>
                    <option value="9">ئەیلوول</option><option value="10">تشرینی یەکەم</option>
                    <option value="11">تشرینی دووەم</option><option value="12">کانوونی یەکەم</option>
                </select>
                <select id="rldYear" onchange="rldSetQuick('custom',null)"
                        style="padding: 7px 10px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 12px; font-family: inherit; background: #fff;">
                    <option value="">-- ساڵ --</option>
                    <?php
                    $curYear = (int)date('Y');
                    for ($y = $curYear; $y >= $curYear - 3; $y--) {
                        echo "<option value=\"$y\">$y</option>";
                    }
                    ?>
                </select>
                <div style="margin-right: auto; font-size: 12px; color: #78909c;">
                    ئەنجام: <strong id="rldResultCount">0</strong>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div style="max-height: 50vh; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="background: #fafafa; border-bottom: 2px solid #f0f0f0; position: sticky; top: 0; z-index: 1;">
                        <th style="padding: 9px 14px; text-align: right; font-weight: 600; color: #546e7a;">نەخۆش</th>
                        <th style="padding: 9px 8px; text-align: center; font-weight: 600; color: #546e7a;">بەرواری کۆن</th>
                        <th style="padding: 9px 8px; text-align: center; font-weight: 600; color: #546e7a;">بەرواری نوێ</th>
                        <th style="padding: 9px 8px; text-align: center; font-weight: 600; color: #546e7a;">دکتۆر</th>
                        <th style="padding: 9px 8px; text-align: center; font-weight: 600; color: #546e7a;">واتساپ</th>
                    </tr>
                </thead>
                <tbody id="rldTableBody"></tbody>
            </table>
            <div id="rldEmpty" style="display:none; text-align: center; padding: 30px 20px; color: #9e9e9e;">
                <i class="fas fa-search" style="font-size: 30px; display: block; margin-bottom: 10px;"></i>
                هیچ ئەنجامێک نەدۆزرایەوە
            </div>
        </div>

        <div class="apt-dialog-footer" style="padding: 12px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; justify-content: flex-end;">
            <button type="button" class="apt-btn-secondary" onclick="closeDialog('rescheduledListDialog')">
                <i class="fas fa-arrow-right"></i> داخستن
            </button>
        </div>
    </div>
</div>

<style>
.rld-quick-btn {
    padding: 5px 12px; border-radius: 20px; border: 1.5px solid #ffe0b2;
    background: #fff; color: #e65100; font-size: 12px; font-family: inherit;
    cursor: pointer; transition: all 0.15s;
}
.rld-quick-btn.active { background: #ff9800; color: #fff; border-color: #ff9800; }
.rld-quick-btn:hover:not(.active) { background: #fff3e0; }
</style>

<script>
const RLD_DATA = <?= json_encode(array_map(fn($a) => [
    'patient'      => $a['patient_name'],
    'phone'        => $a['patient_phone'] ?? '',
    'doctor'       => $a['doctor_name'],
    'old_date'     => $a['rescheduled_from_date'] ?? '',
    'old_time'     => !empty($a['rescheduled_from_time']) ? date('H:i', strtotime($a['rescheduled_from_time'])) : '',
    'new_date'     => $a['appointment_date'],
    'new_time'     => date('H:i', strtotime($a['appointment_time'])),
    'rescheduled_at' => $a['rescheduled_at'] ?? '',
], $allRescheduled), JSON_UNESCAPED_UNICODE) ?>;

let rldQuickMode = 'all';

function rldSetQuick(mode, btn) {
    rldQuickMode = mode;
    document.querySelectorAll('.rld-quick-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    if (mode !== 'custom') {
        document.getElementById('rldMonth').value = '';
        document.getElementById('rldYear').value  = '';
    }
    rldApplyFilters();
}

function rldReset() {
    document.getElementById('rldSearch').value = '';
    document.getElementById('rldMonth').value  = '';
    document.getElementById('rldYear').value   = '';
    rldSetQuick('all', document.querySelector('.rld-quick-btn'));
}

function rldApplyFilters() {
    const search = document.getElementById('rldSearch').value.trim().toLowerCase();
    const month  = parseInt(document.getElementById('rldMonth').value) || 0;
    const year   = parseInt(document.getElementById('rldYear').value)  || 0;
    const today  = new Date(); today.setHours(0,0,0,0);

    const filtered = RLD_DATA.filter(r => {
        // Search
        if (search && !r.patient.toLowerCase().includes(search) && !r.phone.includes(search)) return false;

        const d = r.new_date ? new Date(r.new_date) : null;
        if (!d) return true;
        d.setHours(0,0,0,0);

        if (rldQuickMode === 'today') {
            return d.getTime() === today.getTime();
        }
        if (rldQuickMode === 'week') {
            const wStart = new Date(today); wStart.setDate(today.getDate() - today.getDay());
            const wEnd   = new Date(wStart); wEnd.setDate(wStart.getDate() + 6);
            return d >= wStart && d <= wEnd;
        }
        if (rldQuickMode === 'month') {
            return d.getMonth() === today.getMonth() && d.getFullYear() === today.getFullYear();
        }
        if (rldQuickMode === 'year') {
            return d.getFullYear() === today.getFullYear();
        }
        if (rldQuickMode === 'custom') {
            if (month && (d.getMonth() + 1) !== month) return false;
            if (year  && d.getFullYear() !== year)     return false;
        }
        return true;
    });

    rldRender(filtered);
}

function rldRender(rows) {
    const tbody = document.getElementById('rldTableBody');
    const empty = document.getElementById('rldEmpty');
    document.getElementById('rldResultCount').textContent = rows.length;
    document.getElementById('rldTotalBadge').textContent  = rows.length;

    if (!rows.length) {
        tbody.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    tbody.innerHTML = rows.map(r => `
        <tr style="border-bottom: 1px solid #f5f5f5;">
            <td style="padding: 9px 14px;">
                <div style="font-weight: 600; color: #37474f;">${r.patient}</div>
                <div style="font-size: 11px; color: #90a4ae; direction: ltr;">${r.phone}</div>
            </td>
            <td style="padding: 9px 8px; text-align: center;">
                ${r.old_date
                    ? `<div style="color:#f44336;text-decoration:line-through;font-size:12px;">${r.old_date}</div>
                       <div style="color:#f44336;text-decoration:line-through;font-size:11px;">${r.old_time}</div>`
                    : '<span style="color:#bdbdbd">—</span>'}
            </td>
            <td style="padding: 9px 8px; text-align: center;">
                <div style="color:#2e7d32;font-weight:600;font-size:12px;">${r.new_date}</div>
                <div style="color:#2e7d32;font-size:11px;">${r.new_time}</div>
            </td>
            <td style="padding: 9px 8px; text-align: center; color:#546e7a; font-size:12px;">
                د. ${r.doctor}
            </td>
            <td style="padding: 9px 8px; text-align: center;">
                <button onclick="notifyReschedule('${r.phone}','${r.patient.replace(/'/g,"\\'")}','${r.new_date}','${r.new_time}')"
                        style="background:#25d366;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;">
                    <i class="fab fa-whatsapp"></i>
                </button>
            </td>
        </tr>`).join('');
}

// Init when dialog opens — hook into openDialog
(function() {
    const _orig = window.openDialog;
    window.openDialog = function(id) {
        _orig && _orig(id);
        if (id === 'rescheduledListDialog') {
            rldReset();
        }
    };
})();
</script>

<!-- Edit Appointment Dialog -->
<div class="dialog-overlay" id="editAppointmentDialog">
    <div class="dialog apt-dialog-modern">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-dark, #005a9e) 100%);">
            <h4><i class="fas fa-edit"></i> دەستکاری مەوعید</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('editAppointmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="editAppointmentForm">
            <input type="hidden" name="action" value="update_full">
            <input type="hidden" name="appointment_id" id="editAppointmentId">

            <div class="apt-dialog-body">
                <!-- Patient info (read-only) -->
                <div id="editPatientBanner"
                     style="background: #e3f2fd; padding: 10px 12px; border-radius: 6px; margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div style="flex: 1;">
                        <div id="editPatientName" style="font-weight: 700; color: var(--win-accent, #0078d4); font-size: 14px;"></div>
                        <div id="editPatientPhone" style="font-size: 12px; color: #546e7a; direction: ltr; text-align: right;"></div>
                    </div>
                </div>

                <input type="hidden" name="appointment_time" id="editAppointmentTime" required>
                <input type="hidden" id="editAppointmentEndTime">

                <div class="apt-form-grid">
                    <?php if ($hasFullAccess): ?>
                    <div class="apt-form-field">
                        <label><i class="fas fa-user-md"></i> دکتۆر <span class="required">*</span></label>
                        <select name="doctor_id" id="editDoctorId" required onchange="refreshEditTimePicker()">
                            <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id'] ?>">د. <?= htmlspecialchars($doc['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="doctor_id" id="editDoctorId" value="<?= $currentUser['id'] ?>">
                    <?php endif; ?>

                    <div class="apt-form-field">
                        <label><i class="fas fa-calendar"></i> بەروار <span class="required">*</span></label>
                        <input type="date" name="appointment_date" id="editAppointmentDate" required onchange="refreshEditTimePicker()">
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-hourglass-half"></i> ماوە</label>
                        <select name="duration" id="editDuration" onchange="refreshEditTimePicker()">
                            <?php echo dh_duration_options(30); ?>
                        </select>
                    </div>
                </div>

                <div class="apt-form-grid" style="margin-top: 10px;">
                    <div class="apt-form-field">
                        <label><i class="fas fa-tag"></i> جۆر</label>
                        <select name="type" id="editType">
                            <option value="general">گشتی</option>
                            <option value="checkup">پشکنین</option>
                            <option value="treatment">چارەسەر</option>
                        </select>
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-briefcase-medical"></i> ئیش</label>
                        <select name="work_type_id" id="editWorkType">
                            <option value="">— هەڵبژێرە —</option>
                            <?php foreach ($workTypes as $wt): ?>
                            <option value="<?= $wt['id'] ?>"><?= htmlspecialchars($wt['work_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Existing Bookings -->
                <div class="existing-bookings-section" id="editExistingBookingsSection" style="display: none; margin-top: 10px;">
                    <label><i class="fas fa-calendar-check"></i> گیراوەکان</label>
                    <div id="editExistingBookingsList" class="existing-bookings-list"></div>
                </div>

                <!-- Smart Time Picker -->
                <div class="apt-time-section" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                    <label><i class="fas fa-clock"></i> کات <span class="required">*</span></label>
                    <div id="editTimePickerContainer" class="apt-time-grid-container">
                        <div class="apt-time-loading"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                    <div class="apt-time-legend">
                        <span class="legend-item"><span class="legend-dot available"></span> بەردەست</span>
                        <span class="legend-item"><span class="legend-dot booked"></span> گیراو</span>
                        <span class="legend-item"><span class="legend-dot past"></span> پێشوو</span>
                    </div>
                </div>

                <div class="apt-form-grid" style="margin-top: 10px;">
                    <div class="apt-form-field">
                        <label><i class="fas fa-info-circle"></i> دۆخ</label>
                        <select name="status" id="editStatus">
                            <option value="scheduled">داڕێژراو</option>
                            <option value="confirmed">پشتڕاستکراو</option>
                            <option value="waiting">چاوەڕوان</option>
                            <option value="in_progress">لە چارەسەردا</option>
                            <option value="completed">تەواوبوو</option>
                            <option value="cancelled">هەڵوەشاوە</option>
                            <option value="no_show">نەهات</option>
                            <option value="rescheduled">دواخراو</option>
                        </select>
                    </div>
                    <div class="apt-form-field">
                        <label><i class="fas fa-money-bill-wave" style="color:#16a34a;"></i> بڕی پارەی هێنراو</label>
                        <input type="number" name="promised_amount" id="editPromisedAmount"
                               min="0" step="500" placeholder="0" style="direction: ltr; text-align: left;">
                    </div>
                </div>

                <div class="apt-form-field" style="margin-top: 10px;">
                    <label><i class="fas fa-sticky-note"></i> تێبینی</label>
                    <textarea name="notes" id="editNotes" rows="2" style="width: 100%; resize: vertical;"></textarea>
                </div>
            </div>

            <div class="apt-dialog-footer">
                <button type="button" class="apt-btn-secondary" onclick="closeDialog('editAppointmentDialog')">
                    <i class="fas fa-times"></i> پاشگەزبوون
                </button>
                <button type="submit" class="apt-btn-primary">
                    <i class="fas fa-save"></i> پاشەکەوتکردنی گۆڕانکاری
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reschedule Appointment Dialog -->
<div class="dialog-overlay" id="rescheduleDialog">
    <div class="dialog apt-dialog-modern" style="max-width: 540px;">
        <div class="apt-dialog-header" style="background: linear-gradient(135deg, #ff9800 0%, #e65100 100%);">
            <h4><i class="fas fa-calendar-alt"></i> دواخستنی مەوعید</h4>
            <button type="button" class="apt-dialog-close" onclick="closeDialog('rescheduleDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" id="rescheduleForm">
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="appointment_id" id="rescheduleAppointmentId">
            <input type="hidden" name="new_time" id="rescheduleTimeHidden">
            <input type="hidden" id="rescheduleDoctorId">

            <div class="apt-dialog-body" style="padding: 16px 20px; max-height: 70vh; overflow-y: auto;">

                <!-- Patient Info Banner -->
                <div style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-user-circle" style="color: #ff8f00; font-size: 20px;"></i>
                    <div>
                        <div id="reschedulePatientName" style="font-weight: 700; color: #37474f; font-size: 14px;"></div>
                        <div id="rescheduleCurrentDate" style="font-size: 12px; color: #78909c; margin-top: 2px;"></div>
                    </div>
                </div>

                <!-- New Date -->
                <div class="apt-form-field" style="margin-bottom: 12px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #37474f; font-size: 13px;">
                        <i class="fas fa-calendar"></i> بەروارى نوێ <span style="color: #f44336;">*</span>
                    </label>
                    <input type="date" name="new_date" id="rescheduleDate" required
                           onchange="refreshRescheduleTimePicker()"
                           style="width: 100%; padding: 9px 12px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 13px; font-family: inherit; box-sizing: border-box;">
                </div>

                <!-- Existing Bookings for that day -->
                <div id="rescheduleExistingSection" style="display:none; margin-bottom: 10px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #37474f; font-size: 12px;">
                        <i class="fas fa-calendar-check"></i> گیراوەکانی ئەو ڕۆژە
                    </label>
                    <div id="rescheduleExistingList" class="existing-bookings-list"></div>
                </div>

                <!-- Smart Time Picker -->
                <div style="margin-bottom: 12px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #37474f; font-size: 13px;">
                        <i class="fas fa-clock"></i> کاتى نوێ <span style="color: #f44336;">*</span>
                    </label>
                    <div id="rescheduleTimePickerContainer" class="apt-time-grid-container">
                        <div class="slots-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>
                    </div>
                    <div class="apt-time-legend" style="margin-top: 6px;">
                        <span class="legend-available"><i class="fas fa-circle"></i> بەردەست</span>
                        <span class="legend-busy"><i class="fas fa-circle"></i> گیراو</span>
                        <span class="legend-break"><i class="fas fa-circle"></i> پشوو</span>
                    </div>
                </div>

                <!-- Selected Time Display -->
                <div id="rescheduleSelectedTime" style="display:none; background: #fff3e0; border: 1.5px solid #ff9800; border-radius: 8px; padding: 8px 14px; margin-bottom: 12px; font-size: 13px; color: #e65100; font-weight: 600;">
                    <i class="fas fa-check-circle"></i> کاتی هەڵبژێردراو: <span id="rescheduleSelectedTimeText"></span>
                </div>

                <!-- WhatsApp note -->
                <div style="background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 8px 14px; font-size: 12px; color: #2e7d32; display: flex; align-items: center; gap: 8px;">
                    <i class="fab fa-whatsapp" style="font-size: 15px;"></i>
                    <span>دوای تۆمارکردن، دەتوانیت ئاگاداری واتساپ بنێریت بۆ نەخۆش</span>
                </div>
            </div>

            <div class="apt-dialog-footer" style="padding: 14px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-start;">
                <button type="button" class="apt-btn-primary" style="background: #ff9800; border-color: #ff9800;"
                        onclick="submitReschedule(false)">
                    <i class="fas fa-save"></i> تۆمارکردن
                </button>
                <button type="button" class="apt-btn-primary"
                        style="background: #25d366; border-color: #25d366;"
                        onclick="submitReschedule(true)">
                    <i class="fab fa-whatsapp"></i> تۆمار و ئاگادارکردنەوە
                </button>
                <button type="button" class="apt-btn-secondary" onclick="closeDialog('rescheduleDialog')">
                    <i class="fas fa-arrow-right"></i> گەڕانەوە
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ========== COMPACT MODERN APPOINTMENT DIALOG ========== */
.apt-dialog-modern {
    max-width: 760px;
    width: 96%;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    background: #fff;
    max-height: 92vh;
    max-height: 92dvh;
    display: flex;
    flex-direction: column;
}

/* The <form> sits between the dialog and its body/footer — it must pass the
   flex layout through, otherwise the body can't scroll and the footer
   (save button) gets clipped below the fold */
.apt-dialog-modern > form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}

.apt-dialog-header {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-deep, #0063b1) 100%);
    color: white;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.apt-dialog-header h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.apt-dialog-close {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
}

.apt-dialog-close:hover {
    background: rgba(255,255,255,0.25);
}

.apt-dialog-body {
    padding: 14px 16px;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}

/* Compact Form Grid - 3 columns for key fields */
.apt-form-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.apt-form-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.apt-form-field.apt-field-full {
    grid-column: 1 / -1;
}

.apt-form-field.apt-field-2col {
    grid-column: span 2;
}

.apt-form-field label {
    font-size: 11px;
    font-weight: 600;
    color: #546e7a;
    display: flex;
    align-items: center;
    gap: 5px;
}

.apt-form-field label i {
    color: var(--win-accent, #0078d4);
    font-size: 10px;
}

.apt-form-field label .required {
    color: #f44336;
    font-size: 10px;
}

.apt-form-field input,
.apt-form-field select {
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 12px;
    font-family: inherit;
    transition: all 0.2s;
    background: #fafafa;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.apt-form-field input:focus,
.apt-form-field select:focus {
    border-color: var(--win-accent, #0078d4);
    box-shadow: 0 0 0 2px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1);
    outline: none;
    background: #fff;
}

/* Patient Search - Compact */
.apt-patient-search {
    position: relative;
}

.apt-patient-search input {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid #e0e0e0;
    border-radius: 6px;
    font-size: 12px;
    background: #fafafa;
}

.apt-search-results {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1.5px solid var(--win-accent, #0078d4);
    border-top: none;
    border-radius: 0 0 6px 6px;
    max-height: 160px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
}

.apt-selected-patient {
    display: none;
    padding: 6px 10px;
    background: linear-gradient(135deg, #e8f4fd, #f5faff);
    border: 1.5px solid var(--win-accent, #0078d4);
    border-radius: 6px;
    margin-top: 6px;
    align-items: center;
    gap: 8px;
    font-size: 12px;
}

.apt-selected-patient.active {
    display: flex;
}

/* Search Results Items - Compact */
.apt-search-item {
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.15s;
}

.apt-search-item:last-child {
    border-bottom: none;
}

.apt-search-item:hover {
    background: #e3f2fd;
}

.apt-search-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-deep, #0063b1));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 11px;
    flex-shrink: 0;
}

.apt-search-info {
    flex: 1;
    min-width: 0;
}

.apt-search-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.apt-search-phone {
    font-size: 11px;
    color: #757575;
    direction: ltr;
    text-align: right;
}

.apt-search-empty {
    padding: 20px;
    text-align: center;
    color: #9e9e9e;
    font-size: 12px;
}

/* Time Section */
.apt-time-section {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #f0f0f0;
}

/* Time Section - Compact */
.apt-time-section > label {
    font-size: 11px;
    font-weight: 600;
    color: #546e7a;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 6px;
}

.apt-time-section > label i {
    color: var(--win-accent, #0078d4);
    font-size: 10px;
}

.apt-time-section > label .required {
    color: #f44336;
    font-size: 10px;
}

.apt-time-grid-container {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 8px;
    max-height: 120px;
    overflow-y: auto;
}

.apt-time-loading {
    text-align: center;
    padding: 15px;
    color: #757575;
    font-size: 12px;
}

.apt-time-legend {
    display: flex;
    gap: 12px;
    margin-top: 6px;
    font-size: 10px;
    color: #78909c;
}

.apt-time-legend span {
    display: flex;
    align-items: center;
    gap: 3px;
}

.apt-time-legend .legend-available i { color: #4caf50; font-size: 6px; }
.apt-time-legend .legend-busy i { color: #f44336; font-size: 6px; }
.apt-time-legend .legend-break i { color: #ff9800; font-size: 6px; }

/* Advanced Options Toggle - Compact */
.apt-advanced-toggle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    margin-top: 10px;
    cursor: pointer;
    color: #78909c;
    font-size: 11px;
    font-weight: 600;
    border-top: 1px dashed #e0e0e0;
    transition: color 0.2s;
}

.apt-advanced-toggle:hover {
    color: var(--win-accent, #0078d4);
}

.apt-advanced-toggle span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.apt-toggle-icon {
    transition: transform 0.3s;
    font-size: 10px;
}

.apt-advanced-toggle.open .apt-toggle-icon {
    transform: rotate(180deg);
}

.apt-advanced-options {
    display: none;
    padding-top: 10px;
    animation: slideDown 0.25s ease;
}

.apt-advanced-options.open {
    display: block;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Dialog Footer - Compact */
.apt-dialog-footer {
    padding: 10px 16px;
    background: #f5f7f9;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 8px;
    flex-direction: row-reverse;
    flex-shrink: 0;
}

.apt-btn-primary,
.apt-btn-secondary {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
}

.apt-btn-primary {
    background: linear-gradient(135deg, var(--win-accent, #0078d4) 0%, var(--win-accent-deep, #0063b1) 100%);
    color: white;
}

.apt-btn-primary:hover {
    background: linear-gradient(135deg, var(--win-accent-deep, #0063b1) 0%, #004c8c 100%);
}

.apt-btn-secondary {
    background: white;
    color: #546e7a;
    border: 1.5px solid #e0e0e0;
}

.apt-btn-secondary:hover {
    background: #f5f5f5;
    border-color: #bdbdbd;
}

@media (max-width: 480px) {
    .apt-form-grid {
        grid-template-columns: 1fr;
    }
    .apt-form-field.apt-field-full {
        grid-column: 1;
    }
}
</style>

<script>
function toggleAdvancedOptions() {
    const toggle = document.querySelector('.apt-advanced-toggle');
    const options = document.getElementById('aptAdvancedOptions');
    toggle.classList.toggle('open');
    options.classList.toggle('open');
}
</script>

<style>
/* Patient Search Styles */
.patient-search-container {
    position: relative;
}

.patient-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1.5px solid var(--win-accent, #0078d4);
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.patient-search-item {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 10px;
}

.patient-search-item:last-child {
    border-bottom: none;
}

.patient-search-item:hover {
    background: #e3f2fd;
}

.patient-search-item .patient-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}

.patient-search-item .patient-info {
    flex: 1;
}

.patient-search-item .patient-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
}

.patient-search-item .patient-phone {
    font-size: 12px;
    color: #757575;
    direction: ltr;
    text-align: right;
}

.selected-patient-display {
    margin-top: 8px;
    padding: 10px 14px;
    background: linear-gradient(135deg, #e3f2fd, #f0f7ff);
    border: 1.5px solid var(--win-accent, #0078d4);
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.selected-patient-display .patient-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
}

.selected-patient-display .patient-info {
    flex: 1;
}

.selected-patient-display .patient-name {
    font-weight: 700;
    color: var(--win-accent, #0078d4);
    font-size: 15px;
}

.selected-patient-display .patient-phone {
    font-size: 12px;
    color: #546e7a;
    direction: ltr;
    text-align: right;
}

.selected-patient-display .remove-patient {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #ffebee;
    color: #f44336;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.selected-patient-display .remove-patient:hover {
    background: #f44336;
    color: white;
}

.patient-search-loading {
    padding: 20px;
    text-align: center;
    color: #757575;
}

.patient-search-empty {
    padding: 20px;
    text-align: center;
    color: #9e9e9e;
}

.dialog-modern-apt {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.dialog-modern-apt .form-group {
    margin-bottom: 16px;
}

.dialog-modern-apt .form-control:focus {
    border-color: var(--win-accent, #0078d4);
    box-shadow: 0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), 0.1);
    outline: none;
}
</style>

<script>
function toggleDropdown(btn, event) {
    event.stopPropagation();
    const menu = btn.nextElementSibling;
    const isVisible = menu.classList.contains('show');

    // Close all other dropdowns first
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));

    // Toggle current dropdown
    if (!isVisible) {
        menu.classList.add('show');
        positionDropdown(btn, menu);
    }
}

// The menu is position:fixed, so place it against the viewport: below the button
// when it fits, flipped above it otherwise. Nothing paints between .show and the
// final coordinates, so this is flicker-free.
function positionDropdown(btn, menu) {
    const GAP = 4, EDGE = 8;

    // An ancestor may be the containing block for fixed children (.app-window
    // uses backdrop-filter), so measure where offset 0,0 actually lands and
    // subtract that origin instead of assuming it is the viewport corner.
    menu.style.top = '0px';
    menu.style.left = '0px';
    const origin = menu.getBoundingClientRect();

    const rect = btn.getBoundingClientRect();
    const vh = window.innerHeight, vw = window.innerWidth;

    let top = rect.bottom + GAP;
    if (top + origin.height > vh - EDGE) {
        const above = rect.top - GAP - origin.height;
        top = above >= EDGE ? above : Math.max(EDGE, vh - EDGE - origin.height);
    }

    // Keep the button-left alignment the absolute version had, but never let the
    // menu run off either edge.
    let left = Math.min(rect.left, vw - EDGE - origin.width);
    left = Math.max(EDGE, left);

    menu.style.top = (top - origin.top) + 'px';
    menu.style.left = (left - origin.left) + 'px';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    }
});

// Permanent delete. The form is built here rather than sitting inside the table
// row; HTMLFormElement.prototype.submit is patched in header.php to attach the
// CSRF token, so a programmatic submit is still protected.
function deleteAppointment(id, name) {
    Swal.fire({
        title: 'سڕینەوەی مەوعید',
        html: '<div style="line-height:1.9;">'
            + (name ? '<b style="font-size:15px;">' + String(name).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])) + '</b><br>' : '')
            + 'ئەم مەوعیدە بە تەواوی دەسڕێتەوە و لە هیچ لیست و ڕاپۆرتێکدا نامێنێت.'
            + '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#b71c1c',
        cancelButtonColor: '#5C5C5C',
        confirmButtonText: 'بەڵێ، بیسڕەوە',
        cancelButtonText: 'نەخێر'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = '<?= APP_URL ?>/appointments.php';
        f.style.display = 'none';
        f.innerHTML = '<input type="hidden" name="action" value="delete_appointment">'
                    + '<input type="hidden" name="appointment_id" value="' + parseInt(id, 10) + '">';
        document.body.appendChild(f);
        f.submit();
    });
}

// A fixed menu cannot follow its row, so dismiss it when the page scrolls or resizes.
document.addEventListener('scroll', function() {
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
}, true);
window.addEventListener('resize', function() {
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
});

function selectTimeSlot(time) {
    document.getElementById('appointmentTime').value = time;
    openDialog('newAppointmentDialog');
}

// Add Work Type Dialog
function openAddWorkTypeDialog() {
    const input = document.getElementById('newWorkTypeName');
    const err = document.getElementById('addWorkTypeError');
    if (input) input.value = '';
    if (err) { err.style.display = 'none'; err.textContent = ''; }
    openDialog('addWorkTypeDialog');
    setTimeout(() => input && input.focus(), 50);
}

function submitNewWorkType() {
    const input = document.getElementById('newWorkTypeName');
    const err = document.getElementById('addWorkTypeError');
    const name = (input.value || '').trim();
    err.style.display = 'none';
    if (!name) {
        err.textContent = 'تکایە ناوی ئیش بنووسە';
        err.style.display = 'block';
        return;
    }
    fetch('<?= APP_URL ?>/api/work-types.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', work_name: name, scope: 'appointments' })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            err.textContent = data.message || 'هەڵەیەک ڕوویدا';
            err.style.display = 'block';
            return;
        }
        const sel = document.getElementById('appointmentWorkType');
        if (sel) {
            const opt = document.createElement('option');
            opt.value = data.id;
            opt.textContent = data.work_name;
            opt.selected = true;
            sel.appendChild(opt);
        }
        closeDialog('addWorkTypeDialog');
    })
    .catch(e => {
        err.textContent = 'کێشەی پەیوەندی: ' + e.message;
        err.style.display = 'block';
    });
}

// Edit Appointment Dialog
let _editExistingBookings = [];
let _editExcludeId         = 0;

function openEditAppointmentDialog(btn) {
    const raw = btn.getAttribute('data-apt');
    if (!raw) return;
    let apt;
    try { apt = JSON.parse(raw); }
    catch (e) { alert('کێشە لە خوێندنەوەی زانیاری مەوعید'); return; }

    _editExcludeId = apt.id;

    document.getElementById('editAppointmentId').value      = apt.id;
    document.getElementById('editPatientName').textContent  = apt.patient_name || '—';
    document.getElementById('editPatientPhone').textContent = apt.patient_phone || '';
    document.getElementById('editAppointmentDate').value    = apt.appointment_date || '';
    document.getElementById('editAppointmentTime').value    = apt.appointment_time || '';
    document.getElementById('editDuration').value           = apt.duration || 30;
    document.getElementById('editType').value               = apt.type || 'general';
    document.getElementById('editWorkType').value           = apt.work_type_id || '';
    document.getElementById('editStatus').value             = apt.status || 'scheduled';
    document.getElementById('editNotes').value              = apt.notes || '';
    document.getElementById('editPromisedAmount').value     = apt.promised_amount || '';

    const docSel = document.getElementById('editDoctorId');
    if (docSel) docSel.value = apt.doctor_id || '';

    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    openDialog('editAppointmentDialog');
    setTimeout(refreshEditTimePicker, 150);
}

async function refreshEditTimePicker() {
    const container    = document.getElementById('editTimePickerContainer');
    const dateInput    = document.getElementById('editAppointmentDate');
    const durationSel  = document.getElementById('editDuration');
    const doctorInput  = document.getElementById('editDoctorId');
    const existingSec  = document.getElementById('editExistingBookingsSection');
    const existingList = document.getElementById('editExistingBookingsList');
    const timeHidden   = document.getElementById('editAppointmentTime');
    const endHidden    = document.getElementById('editAppointmentEndTime');

    if (!container) return;

    const date     = dateInput?.value || '';
    const duration = durationSel?.value || 30;
    const doctorId = doctorInput?.value || '';

    if (!date || !doctorId) {
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i> تکایە دکتۆر و بەروار هەڵبژێرە</div>';
        if (existingSec) existingSec.style.display = 'none';
        return;
    }

    container.innerHTML = '<div class="slots-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>';

    try {
        const [slotsRes, bookingsRes] = await Promise.all([
            fetch(`${SCHEDULE_API}?action=get_all_slots&doctor_id=${doctorId}&date=${date}&duration=${duration}`),
            fetch(`${SCHEDULE_API}?action=get_bookings&doctor_id=${doctorId}&date=${date}`)
        ]);
        const slotsResult    = await slotsRes.json();
        const bookingsResult = await bookingsRes.json();

        // Exclude the current appointment from "existing bookings"
        const allBookings = bookingsResult.success && Array.isArray(bookingsResult.data) ? bookingsResult.data : [];
        _editExistingBookings = allBookings.filter(b => String(b.id) !== String(_editExcludeId));

        if (existingSec && existingList) {
            if (_editExistingBookings.length > 0) {
                existingSec.style.display = 'block';
                existingList.innerHTML = _editExistingBookings.map(b => `
                    <div class="existing-booking-item">
                        <i class="fas fa-clock"></i>
                        <span>${b.start_time} - ${b.end_time}</span>
                        <span class="patient-name" title="${b.patient_name || ''}">${b.patient_name || 'مەوعید'}</span>
                    </div>`).join('');
            } else {
                existingSec.style.display = 'block';
                existingList.innerHTML = '<div class="no-bookings-message"><i class="fas fa-check-circle"></i> هیچ مەوعیدێک نییە</div>';
            }
        }

        if (!slotsResult.success) {
            container.innerHTML = `<div class="no-working-day"><i class="fas fa-exclamation-circle"></i>${slotsResult.message || 'هەڵەیەک ڕوویدا'}</div>`;
            return;
        }

        const data = slotsResult.data;
        if (!data.working) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-bed"></i> ئەم ڕۆژە ڕۆژی پشووە</div>';
            return;
        }
        if (!data.slots.length) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-calendar-times"></i> هیچ کاتێک بەردەست نییە</div>';
            return;
        }

        const currentSelected = timeHidden?.value || '';
        const excludeStart    = currentSelected;
        const excludeEnd      = addMinutesToTime(currentSelected, parseInt(duration) || 30);

        let html = '<div class="smart-time-picker-grid">';
        data.slots.forEach(slot => {
            let status = slot.status;
            // Re-enable slots that were blocked by THIS appointment
            if (status !== 'available' && currentSelected && slot.time >= excludeStart && slot.time < excludeEnd) {
                status = 'available';
            }
            const sel   = slot.time === currentSelected ? 'selected' : '';
            const cls   = status === 'available' ? '' : status;
            const click = (status === 'available' || status === 'booked') ? `onclick="selectEditTimeSlot('${slot.time}')"` : '';
            const title = slot.info ? `title="${slot.info}"` : '';
            html += `<div class="smart-time-slot ${cls} ${sel}" ${click} ${title}>${slot.time}</div>`;
        });
        html += '</div>';
        container.innerHTML = html;

        // Keep end-time in sync with selected time + duration
        if (currentSelected && endHidden) {
            endHidden.value = excludeEnd;
        }
    } catch (err) {
        console.error(err);
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-triangle"></i> هەڵەیەک ڕوویدا</div>';
    }
}

function addMinutesToTime(time, mins) {
    if (!time) return '';
    const [h, m] = time.split(':').map(Number);
    const total = h * 60 + m + mins;
    const hh = String(Math.floor(total / 60) % 24).padStart(2, '0');
    const mm = String(total % 60).padStart(2, '0');
    return `${hh}:${mm}`;
}

function selectEditTimeSlot(time) {
    const timeHidden  = document.getElementById('editAppointmentTime');
    const endHidden   = document.getElementById('editAppointmentEndTime');
    const durationSel = document.getElementById('editDuration');
    timeHidden.value = time;
    endHidden.value  = addMinutesToTime(time, parseInt(durationSel.value) || 30);

    document.querySelectorAll('#editTimePickerContainer .smart-time-slot').forEach(s => {
        s.classList.toggle('selected', s.textContent.trim() === time);
    });
}

// Overlapping bookings are allowed — only require that a time is chosen
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editAppointmentForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        const start = document.getElementById('editAppointmentTime').value;
        if (!start) { e.preventDefault(); alert('تکایە کاتێک هەڵبژێرە'); }
    });
});

// Cancel Appointment Dialog
function openCancelDialog(appointmentId) {
    document.getElementById('cancelAppointmentId').value = appointmentId;
    document.getElementById('cancellationReason').value = '';
    document.getElementById('cancellationComment').value = '';
    // Hide add reason form if open
    const addForm = document.getElementById('addReasonForm');
    if (addForm) addForm.style.display = 'none';
    // Close dropdown menu
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    openDialog('cancelAppointmentDialog');
}

// Toggle Add Reason Form
function toggleAddReasonForm() {
    const form = document.getElementById('addReasonForm');
    const input = document.getElementById('newReasonText');
    if (form.style.display === 'none') {
        form.style.display = 'block';
        input.value = '';
        input.focus();
    } else {
        form.style.display = 'none';
    }
}

// Save New Cancellation Reason via AJAX
async function saveNewReason() {
    const input = document.getElementById('newReasonText');
    const reasonText = input.value.trim();

    if (!reasonText) {
        alert('تکایە هۆکارێک بنووسە');
        input.focus();
        return;
    }

    try {
        const response = await fetch('<?= APP_URL ?>/api/cancellation-reasons.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add', reason_text: reasonText })
        });

        const data = await response.json();

        if (data.success) {
            // Add new option to select
            const select = document.getElementById('cancellationReason');
            const option = document.createElement('option');
            option.value = data.reason_key;
            option.textContent = reasonText;
            select.appendChild(option);

            // Select the new option
            select.value = data.reason_key;

            // Hide form and clear input
            document.getElementById('addReasonForm').style.display = 'none';
            input.value = '';
        } else {
            alert(data.message || 'هەڵە ڕوویدا');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('هەڵە لە پەیوەندیکردن');
    }
}

// Patient Search System
(function() {
    const searchInput = document.getElementById('patientSearchInput');
    const searchResults = document.getElementById('patientSearchResults');
    const selectedDisplay = document.getElementById('selectedPatientDisplay');
    const hiddenInput = document.getElementById('selectedPatientId');

    if (!searchInput) return;

    let searchTimeout;
    let selectedPatient = null;

    // Search for patients
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();

        clearTimeout(searchTimeout);

        if (query.length < 2) {
            searchResults.style.display = 'none';
            return;
        }

        // Show loading
        searchResults.innerHTML = '<div class="patient-search-loading"><i class="fas fa-spinner fa-spin"></i> گەڕان...</div>';
        searchResults.style.display = 'block';

        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch(`<?= APP_URL ?>/api/search.php?type=patients&q=${encodeURIComponent(query)}`);
                const data = await response.json();

                if (data.success && data.data && data.data.length > 0) {
                    searchResults.innerHTML = data.data.map(patient => `
                        <div class="apt-search-item" onclick="selectPatient(${patient.id}, '${escapeHtml(patient.full_name)}', '${patient.phone || ''}')">
                            <div class="apt-search-avatar">${patient.full_name.charAt(0)}</div>
                            <div class="apt-search-info">
                                <div class="apt-search-name">${escapeHtml(patient.full_name)}</div>
                                <div class="apt-search-phone">${patient.phone || 'بێ ژمارە'}</div>
                            </div>
                        </div>
                    `).join('');
                } else {
                    searchResults.innerHTML = '<div class="apt-search-empty"><i class="fas fa-user-slash"></i><br>هیچ نەخۆشێک نەدۆزرایەوە</div>';
                }
            } catch (error) {
                console.error('Search error:', error);
                searchResults.innerHTML = '<div class="apt-search-empty" style="color: #f44336;"><i class="fas fa-exclamation-triangle"></i><br>هەڵەیەک ڕوویدا</div>';
            }
        }, 300);
    });

    // Close results when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.apt-patient-search') && !e.target.closest('.patient-search-container')) {
            searchResults.style.display = 'none';
        }
    });

    // Focus on search input shows results if there's a query
    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2 && searchResults.innerHTML) {
            searchResults.style.display = 'block';
        }
    });

    // Form validation
    document.getElementById('appointmentForm')?.addEventListener('submit', function(e) {
        if (!hiddenInput.value) {
            e.preventDefault();
            alert('تکایە نەخۆشێک هەڵبژێرە');
            searchInput.focus();
            return false;
        }
    });

    // Reset form when dialog opens
    const originalOpenDialog = window.openDialog;
    window.openDialog = function(id) {
        if (id === 'newAppointmentDialog') {
            resetPatientSearch();
        }
        if (originalOpenDialog) {
            originalOpenDialog(id);
        } else {
            document.getElementById(id).classList.add('active');
        }
    };
})();

// Select a patient from search results
function selectPatient(id, name, phone) {
    const searchContainer = document.querySelector('.apt-patient-search');
    const searchResults = document.getElementById('patientSearchResults');
    const selectedDisplay = document.getElementById('selectedPatientDisplay');
    const hiddenInput = document.getElementById('selectedPatientId');

    // Set hidden input value
    hiddenInput.value = id;

    // Hide search container and results
    if (searchContainer) searchContainer.style.display = 'none';
    searchResults.style.display = 'none';

    // Show selected patient with modern styling
    selectedDisplay.innerHTML = `
        <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-dark, #005a9e)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;">${name.charAt(0)}</div>
        <div style="flex: 1;">
            <div style="font-weight: 600; color: var(--win-accent, #0078d4); font-size: 14px;">${escapeHtml(name)}</div>
            <div style="font-size: 11px; color: #757575; direction: ltr; text-align: right;">${phone || 'بێ ژمارە'}</div>
        </div>
        <button type="button" onclick="resetPatientSearch()" style="width: 28px; height: 28px; border-radius: 50%; background: #ffebee; color: #f44336; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;" title="لابردن">
            <i class="fas fa-times"></i>
        </button>
    `;
    selectedDisplay.classList.add('active');

    // Fetch patient debt and reveal debt/promised-amount section
    loadPatientDebt(id);
}

function loadPatientDebt(patientId) {
    const section = document.getElementById('aptDebtSection');
    const debtBox = document.getElementById('aptPatientDebt');
    const promised = document.getElementById('aptPromisedAmount');
    if (!section || !debtBox) return;
    section.style.display = '';
    debtBox.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#9aa7b3;"></i>';
    if (promised) { promised.value = ''; promised.disabled = false; }

    fetch(`<?= APP_URL ?>/api/patient-debt.php?id=${patientId}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                debtBox.innerHTML = '<span style="color:#9aa7b3;font-weight:400;">—</span>';
                return;
            }
            const amount = Number(d.total_debt) || 0;
            if (amount > 0) {
                debtBox.style.background = '#fff0f0';
                debtBox.style.borderColor = '#fca5a5';
                debtBox.style.color = '#c62828';
                debtBox.innerHTML = amount.toLocaleString() + '';
                if (promised) promised.disabled = false;
            } else {
                debtBox.style.background = '#f0fdf4';
                debtBox.style.borderColor = '#86efac';
                debtBox.style.color = '#15803d';
                debtBox.innerHTML = '<i class="fas fa-check-circle"></i> بێ قەرز';
                if (promised) { promised.value = ''; promised.disabled = true; }
            }
        })
        .catch(() => {
            debtBox.innerHTML = '<span style="color:#9aa7b3;font-weight:400;">—</span>';
        });
}

// Reset patient search
function resetPatientSearch() {
    const searchContainer = document.querySelector('.apt-patient-search');
    const searchInput = document.getElementById('patientSearchInput');
    const searchResults = document.getElementById('patientSearchResults');
    const selectedDisplay = document.getElementById('selectedPatientDisplay');
    const hiddenInput = document.getElementById('selectedPatientId');

    hiddenInput.value = '';
    if (searchInput) searchInput.value = '';
    if (searchContainer) searchContainer.style.display = 'block';
    searchResults.style.display = 'none';
    searchResults.innerHTML = '';
    selectedDisplay.classList.remove('active');
    selectedDisplay.innerHTML = '';

    // Hide debt section
    const debtSection = document.getElementById('aptDebtSection');
    const promised = document.getElementById('aptPromisedAmount');
    if (debtSection) debtSection.style.display = 'none';
    if (promised) { promised.value = ''; promised.disabled = false; }
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ===================== VIEW TOGGLE =====================
let currentView = 'table';
let calendar = null;

function switchView(view) {
    currentView = view;

    const tableView = document.getElementById('tableView');
    const calendarView = document.getElementById('calendarView');
    const tableBtn = document.getElementById('tableViewBtn');
    const calendarBtn = document.getElementById('calendarViewBtn');

    if (view === 'table') {
        tableView.classList.remove('hidden');
        calendarView.classList.remove('active');
        tableBtn.classList.add('active');
        calendarBtn.classList.remove('active');
    } else {
        tableView.classList.add('hidden');
        calendarView.classList.add('active');
        tableBtn.classList.remove('active');
        calendarBtn.classList.add('active');

        // Initialize calendar on first view
        if (!calendar) {
            initFullCalendar();
        } else {
            calendar.refetchEvents();
        }
    }
}

// ===================== FULLCALENDAR =====================
function initFullCalendar() {
    const calendarEl = document.getElementById('fullCalendar');
    if (!calendarEl) return;

    const doctorId = '<?= $selectedDoctor ?: ($hasFullAccess ? '' : $currentUser['id']) ?>';

    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        direction: 'rtl',
        locale: 'ar',
        firstDay: 6, // Start week on Saturday
        slotMinTime: '08:00:00',
        slotMaxTime: '22:00:00',
        slotDuration: '00:30:00',
        slotLabelInterval: '01:00:00',
        allDaySlot: false,
        nowIndicator: true,
        selectable: true,
        selectMirror: true,
        editable: false,
        height: 'auto',

        headerToolbar: {
            start: 'prev,next today',
            center: 'title',
            end: 'dayGridMonth,timeGridWeek,timeGridDay'
        },

        buttonText: {
            today: 'ئەمڕۆ',
            month: 'مانگ',
            week: 'هەفتە',
            day: 'ڕۆژ'
        },

        // Events source
        events: function(info, successCallback, failureCallback) {
            const url = `<?= APP_URL ?>/api/schedule.php?action=get_calendar_events&doctor_id=${doctorId}&start=${info.startStr}&end=${info.endStr}&work_type=<?= $selectedWorkType ?>&call_status=<?= urlencode($selectedCallStatus) ?>`;
            fetch(url)
                .then(response => response.json())
                .then(data => successCallback(data))
                .catch(error => {
                    console.error('Error fetching events:', error);
                    failureCallback(error);
                });
        },

        // Click on empty slot to create appointment
        select: function(info) {
            document.getElementById('appointmentDate').value = info.startStr.split('T')[0];
            const time = info.startStr.split('T')[1]?.substring(0, 5) || '';
            if (time) {
                document.getElementById('appointmentTime').value = time;
            }
            openDialog('newAppointmentDialog');
            refreshSmartTimePicker();
        },

        // Click on existing event
        eventClick: function(info) {
            const props = info.event.extendedProps;
            alert(`مەوعید: ${props.patientName}\nکات: ${info.event.start.toLocaleTimeString('ar')}\nدۆخ: ${props.status}`);
        },

        // Custom event display
        eventContent: function(arg) {
            return {
                html: `<div style="padding: 2px 4px; overflow: hidden; text-overflow: ellipsis;">
                    <b>${arg.timeText}</b> ${arg.event.title}
                </div>`
            };
        }
    });

    calendar.render();
}

// ===================== SMART TIME PICKER =====================
const SCHEDULE_API = '<?= APP_URL ?>/api/schedule.php';

// Store existing bookings for the selected date
let aptExistingBookings = [];

async function refreshSmartTimePicker() {
    const container = document.getElementById('smartTimePickerContainer');
    const dateInput = document.getElementById('appointmentDate');
    const durationSelect = document.getElementById('appointmentDuration');
    const doctorSelect = document.querySelector('[name="doctor_id"]');
    const existingSection = document.getElementById('aptExistingBookingsSection');
    const existingList = document.getElementById('aptExistingBookingsList');
    const bookingSummary = document.getElementById('aptBookingSummary');

    if (!container || !dateInput) return;

    const date = dateInput.value;
    const duration = durationSelect?.value || 30;
    const doctorId = doctorSelect?.value || '<?= $hasFullAccess ? '' : $currentUser['id'] ?>';

    // Reset state
    aptExistingBookings = [];
    if (bookingSummary) bookingSummary.style.display = 'none';
    document.getElementById('appointmentTime').value = '';
    document.getElementById('appointmentEndTime').value = '';

    if (!date || !doctorId) {
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i>تکایە دکتۆر و بەروار هەڵبژێرە</div>';
        if (existingSection) existingSection.style.display = 'none';
        return;
    }

    // Show loading
    container.innerHTML = '<div class="slots-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>';

    try {
        // Fetch time slots and existing bookings in parallel
        const [slotsResponse, bookingsResponse] = await Promise.all([
            fetch(`${SCHEDULE_API}?action=get_all_slots&doctor_id=${doctorId}&date=${date}&duration=${duration}`),
            fetch(`${SCHEDULE_API}?action=get_bookings&doctor_id=${doctorId}&date=${date}`)
        ]);

        const slotsResult = await slotsResponse.json();
        const bookingsResult = await bookingsResponse.json();

        // Display existing bookings
        if (existingSection && existingList) {
            if (bookingsResult.success && bookingsResult.data && bookingsResult.data.length > 0) {
                aptExistingBookings = bookingsResult.data;
                existingSection.style.display = 'block';
                existingList.innerHTML = aptExistingBookings.map(b => `
                    <div class="existing-booking-item">
                        <i class="fas fa-clock"></i>
                        <span>${b.start_time} - ${b.end_time}</span>
                        <span class="patient-name" title="${b.patient_name || ''}">${b.patient_name || 'مەوعید'}</span>
                    </div>
                `).join('');
            } else {
                existingSection.style.display = 'block';
                existingList.innerHTML = '<div class="no-bookings-message"><i class="fas fa-check-circle"></i> هیچ مەوعیدێک نییە</div>';
                aptExistingBookings = [];
            }
        }

        if (!slotsResult.success) {
            container.innerHTML = `<div class="no-working-day"><i class="fas fa-exclamation-circle"></i>${slotsResult.message || 'هەڵەیەک ڕوویدا'}</div>`;
            return;
        }

        const data = slotsResult.data;

        if (!data.working) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-bed"></i>ئەم ڕۆژە ڕۆژی پشووە</div>';
            return;
        }

        if (data.slots.length === 0) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-calendar-times"></i>هیچ کاتێک بەردەست نییە</div>';
            return;
        }

        // Render slots
        const currentTime = document.getElementById('appointmentTime').value;
        let html = '<div class="smart-time-picker-grid">';

        data.slots.forEach(slot => {
            const isSelected = slot.time === currentTime;
            const statusClass = slot.status === 'available' ? '' : slot.status;
            const selectedClass = isSelected ? 'selected' : '';
            // Booked slots stay selectable — overlapping bookings are allowed
            const clickHandler = (slot.status === 'available' || slot.status === 'booked') ? `onclick="selectSmartTimeSlot('${slot.time}')"` : '';
            const title = slot.info ? `title="${slot.info}"` : '';

            html += `<div class="smart-time-slot ${statusClass} ${selectedClass}" ${clickHandler} ${title}>${slot.time}</div>`;
        });

        html += '</div>';
        container.innerHTML = html;

    } catch (error) {
        console.error('Error loading slots:', error);
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-triangle"></i>هەڵەیەک ڕوویدا</div>';
    }
}

// Helper: Convert time string to minutes
function timeToMinutes(timeStr) {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
}

// Helper: Convert minutes to time string
function minutesToTime(minutes) {
    const hours = Math.floor(minutes / 60) % 24;
    const mins = minutes % 60;
    return `${hours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}`;
}

// Check overlap with existing bookings
function checkAptOverlap(startTime, endTime) {
    const newStart = timeToMinutes(startTime);
    const newEnd = timeToMinutes(endTime);

    for (const booking of aptExistingBookings) {
        const bookingStart = timeToMinutes(booking.start_time);
        const bookingEnd = timeToMinutes(booking.end_time);

        // Check for overlap: new booking starts before existing ends AND new booking ends after existing starts
        if (newStart < bookingEnd && newEnd > bookingStart) {
            return booking; // Return the conflicting booking
        }
    }
    return null; // No overlap
}

// Calculate and display end time
function calculateAptEndTime() {
    const startTime = document.getElementById('appointmentTime').value;
    const durationSelect = document.getElementById('appointmentDuration');
    const endTimeInput = document.getElementById('appointmentEndTime');
    const bookingSummary = document.getElementById('aptBookingSummary');

    if (!startTime || !durationSelect) {
        if (bookingSummary) bookingSummary.style.display = 'none';
        return;
    }

    const duration = parseInt(durationSelect.value);
    const startMinutes = timeToMinutes(startTime);
    const endMinutes = startMinutes + duration;
    const endTime = minutesToTime(endMinutes);

    if (endTimeInput) endTimeInput.value = endTime;

    // Update booking summary
    updateAptBookingSummary(startTime, endTime, duration);

    return endTime;
}

// Update booking summary display
function updateAptBookingSummary(startTime, endTime, duration) {
    const bookingSummary = document.getElementById('aptBookingSummary');
    const dateInput = document.getElementById('appointmentDate');

    if (!bookingSummary) return;

    document.getElementById('aptSummaryDate').textContent = dateInput?.value || '-';
    document.getElementById('aptSummaryStartTime').textContent = startTime;
    document.getElementById('aptSummaryEndTime').textContent = endTime;

    // Format duration text
    let durationText;
    if (duration >= 60) {
        const hours = Math.floor(duration / 60);
        const mins = duration % 60;
        durationText = mins > 0 ? `${hours} کاتژمێر و ${mins} خولەک` : `${hours} کاتژمێر`;
    } else {
        durationText = `${duration} خولەک`;
    }
    document.getElementById('aptSummaryDuration').textContent = durationText;

    // Check for overlap
    const overlap = checkAptOverlap(startTime, endTime);
    if (overlap) {
        bookingSummary.classList.add('has-overlap');
        bookingSummary.querySelector('.summary-title').innerHTML =
            `<i class="fas fa-exclamation-triangle"></i> ئاگاداری: تێپەڕینی کات لەگەڵ مەوعیدی ${overlap.patient_name || 'تر'}`;
    } else {
        bookingSummary.classList.remove('has-overlap');
        bookingSummary.querySelector('.summary-title').innerHTML =
            '<i class="fas fa-info-circle"></i> پوختەی مەوعید';
    }

    bookingSummary.style.display = 'block';
}

function selectSmartTimeSlot(time) {
    // Update hidden input
    document.getElementById('appointmentTime').value = time;

    // Update visual selection
    document.querySelectorAll('.smart-time-slot').forEach(slot => {
        slot.classList.remove('selected');
        if (slot.textContent.trim() === time) {
            slot.classList.add('selected');
        }
    });

    // Calculate end time and update booking summary
    calculateAptEndTime();
}

// Override selectTimeSlot to also refresh smart picker
const originalSelectTimeSlot = window.selectTimeSlot || function() {};
function selectTimeSlot(time) {
    document.getElementById('appointmentTime').value = time;
    openDialog('newAppointmentDialog');
    setTimeout(refreshSmartTimePicker, 100);
}

// Refresh when dialog opens
const originalOpenDialog = window.openDialog;
window.openDialog = function(id) {
    if (originalOpenDialog) {
        originalOpenDialog(id);
    } else {
        document.getElementById(id)?.classList.add('active');
    }

    if (id === 'newAppointmentDialog') {
        setTimeout(refreshSmartTimePicker, 100);
    }
    if (id === 'rescheduleDialog') {
        setTimeout(refreshRescheduleTimePicker, 150);
    }
};

// Form validation: only require a time — overlapping bookings are allowed
document.getElementById('appointmentForm')?.addEventListener('submit', function(e) {
    const timeInput = document.getElementById('appointmentTime');

    if (!timeInput.value) {
        e.preventDefault();
        alert('تکایە کاتێک هەڵبژێرە');
        return false;
    }
});

// Add event listener for duration change
document.getElementById('appointmentDuration')?.addEventListener('change', function() {
    const timeInput = document.getElementById('appointmentTime');
    if (timeInput.value) {
        calculateAptEndTime();
    }
    // Also refresh time slots as duration affects availability
    refreshSmartTimePicker();
});

// ===== RESCHEDULE APPOINTMENT =====
let _reschedulePhone = '';
let _rescheduleDoctorId = '';
let _rescheduleExistingBookings = [];

function openRescheduleDialog(appointmentId, patientName, phone, currentDate, currentTime, doctorId) {
    _reschedulePhone = phone;
    _rescheduleDoctorId = doctorId;
    _rescheduleExistingBookings = [];

    document.getElementById('rescheduleAppointmentId').value = appointmentId;
    document.getElementById('rescheduleDoctorId').value = doctorId;
    document.getElementById('reschedulePatientName').textContent = patientName;
    document.getElementById('rescheduleCurrentDate').textContent = 'مەوعیدی ئێستا: ' + currentDate + ' — ' + currentTime;
    document.getElementById('rescheduleDate').value = currentDate;
    document.getElementById('rescheduleTimeHidden').value = '';
    document.getElementById('rescheduleSelectedTime').style.display = 'none';

    // Close any open dropdown
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    openDialog('rescheduleDialog');
    setTimeout(refreshRescheduleTimePicker, 150);
}

async function refreshRescheduleTimePicker() {
    const container  = document.getElementById('rescheduleTimePickerContainer');
    const dateInput  = document.getElementById('rescheduleDate');
    const doctorId   = document.getElementById('rescheduleDoctorId').value;
    const existingSec  = document.getElementById('rescheduleExistingSection');
    const existingList = document.getElementById('rescheduleExistingList');

    if (!container || !dateInput.value || !doctorId) {
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-circle"></i> تکایە بەروار دیاری بکە</div>';
        return;
    }

    container.innerHTML = '<div class="slots-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕوان بە...</div>';

    try {
        const [slotsRes, bookingsRes] = await Promise.all([
            fetch(`${SCHEDULE_API}?action=get_all_slots&doctor_id=${doctorId}&date=${dateInput.value}&duration=30`),
            fetch(`${SCHEDULE_API}?action=get_bookings&doctor_id=${doctorId}&date=${dateInput.value}`)
        ]);

        const slotsResult    = await slotsRes.json();
        const bookingsResult = await bookingsRes.json();

        // Show existing bookings
        _rescheduleExistingBookings = bookingsResult.success && bookingsResult.data?.length
            ? bookingsResult.data : [];

        if (existingSec && existingList) {
            if (_rescheduleExistingBookings.length > 0) {
                existingSec.style.display = 'block';
                existingList.innerHTML = _rescheduleExistingBookings.map(b => `
                    <div class="existing-booking-item">
                        <i class="fas fa-clock"></i>
                        <span>${b.start_time} - ${b.end_time}</span>
                        <span class="patient-name">${b.patient_name || 'مەوعید'}</span>
                    </div>`).join('');
            } else {
                existingSec.style.display = 'block';
                existingList.innerHTML = '<div class="no-bookings-message"><i class="fas fa-check-circle"></i> هیچ مەوعیدێک نییە</div>';
            }
        }

        if (!slotsResult.success) {
            container.innerHTML = `<div class="no-working-day"><i class="fas fa-exclamation-circle"></i>${slotsResult.message || 'هەڵەیەک ڕوویدا'}</div>`;
            return;
        }

        const data = slotsResult.data;
        if (!data.working) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-bed"></i> ئەم ڕۆژە ڕۆژی پشووە</div>';
            return;
        }
        if (!data.slots.length) {
            container.innerHTML = '<div class="no-working-day"><i class="fas fa-calendar-times"></i> هیچ کاتێک بەردەست نییە</div>';
            return;
        }

        const currentSelected = document.getElementById('rescheduleTimeHidden').value;
        let html = '<div class="smart-time-picker-grid">';
        data.slots.forEach(slot => {
            const sel   = slot.time === currentSelected ? 'selected' : '';
            const cls   = slot.status === 'available' ? '' : slot.status;
            const click = (slot.status === 'available' || slot.status === 'booked') ? `onclick="selectRescheduleSlot('${slot.time}')"` : '';
            const title = slot.info ? `title="${slot.info}"` : '';
            html += `<div class="smart-time-slot ${cls} ${sel}" ${click} ${title}>${slot.time}</div>`;
        });
        html += '</div>';
        container.innerHTML = html;

    } catch (err) {
        console.error(err);
        container.innerHTML = '<div class="no-working-day"><i class="fas fa-exclamation-triangle"></i> هەڵەیەک ڕوویدا</div>';
    }
}

function selectRescheduleSlot(time) {
    document.getElementById('rescheduleTimeHidden').value = time;

    // Highlight selected slot
    document.querySelectorAll('#rescheduleTimePickerContainer .smart-time-slot').forEach(s => {
        s.classList.toggle('selected', s.textContent.trim() === time);
    });

    // Show selected time badge
    const badge = document.getElementById('rescheduleSelectedTime');
    document.getElementById('rescheduleSelectedTimeText').textContent = time;
    badge.style.display = 'block';
}

function submitReschedule(withWhatsApp) {
    const newDate = document.getElementById('rescheduleDate').value;
    const newTime = document.getElementById('rescheduleTimeHidden').value;
    const patientName = document.getElementById('reschedulePatientName').textContent;

    if (!newDate) { alert('تکایە بەروارێک هەڵبژێرە'); return; }
    if (!newTime) { alert('تکایە کاتێک هەڵبژێرە'); return; }

    if (withWhatsApp) {
        notifyReschedule(_reschedulePhone, patientName, newDate, newTime);
    }

    setTimeout(() => document.getElementById('rescheduleForm').submit(), withWhatsApp ? 300 : 0);
}

function notifyReschedule(phoneNumber, patientName, newDate, newTime) {
    let formattedNumber = phoneNumber.replace(/^0/, '964');
    let message = `سڵاو ${patientName}،\n\n` +
                  `دەمانەوێت ئاگادارت بکەینەوە کە مەوعیدەکەت گۆڕاوەتەوە بۆ:\n` +
                  `📅 بەروار: ${newDate}\n` +
                  `⏰ کات: ${newTime}\n\n` +
                  `تەندروستی خوازراو.`;
    window.open('https://wa.me/' + formattedNumber + '?text=' + encodeURIComponent(message), '_blank');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // If calendar view is requested via URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('calendar') === '1') {
        switchView('calendar');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
