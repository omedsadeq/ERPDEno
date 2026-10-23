<?php
/**
 * Doctor Schedule API
 * API endpoints for managing doctor schedules, breaks, and exceptions
 * سیستەمی زیرەکی کلینیکی ددان
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/DoctorSchedule.php';
require_once __DIR__ . '/../classes/Appointment.php';

// Initialize
$auth = new Auth();

// Check authentication
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'ناتوانیت بچیتە ژوورەوە']);
    exit;
}

$scheduleManager = new DoctorSchedule();
$appointmentManager = new Appointment();
$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();

// Get action and doctor ID
$action = $_REQUEST['action'] ?? '';
$doctorId = intval($_REQUEST['doctor_id'] ?? 0);

// If no doctor ID specified, use current user (for doctors viewing their own schedule)
if (!$doctorId && $auth->isDoctor()) {
    $doctorId = $currentUser['id'];
}

// Permission check: Only admin can manage other doctors' schedules
if ($doctorId && $doctorId != $currentUser['id'] && !$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'ڕێگەپێدراو نیت']);
    exit;
}

// CSRF: every state-changing action (save_/add_/update_/delete_) — reads (get_*,
// is_working_day) stay open. The header.php fetch patch sends the token header.
if ($action !== '' && strpos($action, 'get_') !== 0 && $action !== 'is_working_day') {
    Csrf::require();
}

switch ($action) {
    // ===================== SCHEDULE ENDPOINTS =====================

    case 'get_schedule':
        // Get weekly schedule for a doctor
        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $schedule = $scheduleManager->getSchedule($doctorId);
        echo json_encode(['success' => true, 'data' => $schedule]);
        break;

    case 'save_schedule':
        // Save weekly schedule
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $scheduleData = json_decode($_POST['schedule'] ?? '[]', true);
        if (empty($scheduleData)) {
            echo json_encode(['success' => false, 'message' => 'داتای نادروست']);
            exit;
        }

        $result = $scheduleManager->setWeeklySchedule($doctorId, $scheduleData);
        echo json_encode($result);
        break;

    case 'save_day_schedule':
        // Save schedule for a single day
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        $dayOfWeek = intval($_POST['day_of_week'] ?? -1);
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            echo json_encode(['success' => false, 'message' => 'ڕۆژ نادروستە']);
            exit;
        }

        $data = [
            'is_working_day' => intval($_POST['is_working_day'] ?? 1),
            'start_time' => $_POST['start_time'] ?? '09:00:00',
            'end_time' => $_POST['end_time'] ?? '21:00:00'
        ];

        $result = $scheduleManager->setSchedule($doctorId, $dayOfWeek, $data);
        echo json_encode($result);
        break;

    // ===================== AVAILABILITY ENDPOINTS =====================

    case 'get_available_slots':
        // Get available time slots for a specific date
        $date = $_GET['date'] ?? date('Y-m-d');
        $duration = intval($_GET['duration'] ?? 30);

        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $slots = $scheduleManager->getAvailableSlots($doctorId, $date, $duration);
        echo json_encode(['success' => true, 'data' => $slots]);
        break;

    case 'get_all_slots':
        // Get all slots with their status (available, booked, break)
        $date = $_GET['date'] ?? date('Y-m-d');
        $duration = intval($_GET['duration'] ?? 30);

        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $result = $scheduleManager->getAllSlotsWithStatus($doctorId, $date, $duration);
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'get_bookings':
        // Get all bookings for a specific date and doctor
        $date = $_GET['date'] ?? date('Y-m-d');

        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $db = Database::getInstance();
        $bookings = $db->fetchAll("
            SELECT
                a.id,
                a.appointment_time as start_time,
                ADDTIME(a.appointment_time, SEC_TO_TIME(a.duration * 60)) as end_time,
                a.duration,
                a.type,
                a.status,
                p.full_name as patient_name,
                p.patient_code
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            WHERE a.doctor_id = :doctor_id
            AND a.appointment_date = :date
            AND a.status NOT IN ('cancelled', 'no_show')
            ORDER BY a.appointment_time ASC
        ", ['doctor_id' => $doctorId, 'date' => $date]);

        // Format times for display
        foreach ($bookings as &$booking) {
            $booking['start_time'] = date('H:i', strtotime($booking['start_time']));
            $booking['end_time'] = date('H:i', strtotime($booking['end_time']));
        }

        echo json_encode(['success' => true, 'data' => $bookings]);
        break;

    case 'is_working_day':
        // Check if a specific date is a working day
        $date = $_GET['date'] ?? date('Y-m-d');

        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $isWorking = $scheduleManager->isWorkingDay($doctorId, $date);
        $workingHours = $scheduleManager->getWorkingHours($doctorId, $date);

        echo json_encode([
            'success' => true,
            'data' => [
                'is_working' => $isWorking,
                'working_hours' => $workingHours
            ]
        ]);
        break;

    // ===================== BREAK ENDPOINTS =====================

    case 'get_breaks':
        // Get breaks for a doctor
        $dayOfWeek = isset($_GET['day_of_week']) ? intval($_GET['day_of_week']) : null;

        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $breaks = $scheduleManager->getBreaks($doctorId, $dayOfWeek);
        echo json_encode(['success' => true, 'data' => $breaks]);
        break;

    case 'add_break':
        // Add a break time
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        $dayOfWeek = intval($_POST['day_of_week'] ?? -1);
        $breakStart = $_POST['break_start'] ?? '';
        $breakEnd = $_POST['break_end'] ?? '';
        $breakName = $_POST['break_name'] ?? 'پشوو';

        if ($dayOfWeek < 0 || $dayOfWeek > 6 || !$breakStart || !$breakEnd) {
            echo json_encode(['success' => false, 'message' => 'داتای پێویست نییە']);
            exit;
        }

        $result = $scheduleManager->addBreak($doctorId, $dayOfWeek, $breakStart, $breakEnd, $breakName);
        echo json_encode($result);
        break;

    case 'update_break':
        // Update a break
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        $breakId = intval($_POST['break_id'] ?? 0);
        if (!$breakId) {
            echo json_encode(['success' => false, 'message' => 'پشووی دیاریکراو نییە']);
            exit;
        }

        $data = [];
        if (isset($_POST['break_start'])) $data['break_start'] = $_POST['break_start'];
        if (isset($_POST['break_end'])) $data['break_end'] = $_POST['break_end'];
        if (isset($_POST['break_name'])) $data['break_name'] = $_POST['break_name'];
        if (isset($_POST['is_active'])) $data['is_active'] = intval($_POST['is_active']);

        $result = $scheduleManager->updateBreak($breakId, $data);
        echo json_encode($result);
        break;

    case 'delete_break':
        // Delete a break
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        $breakId = intval($_POST['break_id'] ?? 0);
        if (!$breakId) {
            echo json_encode(['success' => false, 'message' => 'پشووی دیاریکراو نییە']);
            exit;
        }

        $result = $scheduleManager->deleteBreak($breakId);
        echo json_encode($result);
        break;

    // ===================== EXCEPTION ENDPOINTS =====================

    case 'get_exceptions':
        // Get date exceptions for a doctor
        $startDate = $_GET['start'] ?? null;
        $endDate = $_GET['end'] ?? null;

        if (!$doctorId) {
            echo json_encode(['success' => false, 'message' => 'دکتۆر دیاری نەکراوە']);
            exit;
        }

        $exceptions = $scheduleManager->getExceptions($doctorId, $startDate, $endDate);
        echo json_encode(['success' => true, 'data' => $exceptions]);
        break;

    case 'add_exception':
        // Add a date exception (day off or custom hours)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        $date = $_POST['exception_date'] ?? '';
        $type = $_POST['exception_type'] ?? 'off';
        $startTime = $_POST['start_time'] ?? null;
        $endTime = $_POST['end_time'] ?? null;
        $reason = $_POST['reason'] ?? '';

        if (!$date) {
            echo json_encode(['success' => false, 'message' => 'بەروار پێویستە']);
            exit;
        }

        if ($type === 'custom' && (!$startTime || !$endTime)) {
            echo json_encode(['success' => false, 'message' => 'کاتی دەستپێکردن و کۆتایی پێویستە']);
            exit;
        }

        $result = $scheduleManager->addException($doctorId, $date, $type, $startTime, $endTime, $reason);
        echo json_encode($result);
        break;

    case 'update_exception':
        // Update an exception
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        $exceptionId = intval($_POST['exception_id'] ?? 0);
        if (!$exceptionId) {
            echo json_encode(['success' => false, 'message' => 'تایبەتمەندی دیاریکراو نییە']);
            exit;
        }

        $data = [];
        if (isset($_POST['exception_type'])) $data['exception_type'] = $_POST['exception_type'];
        if (isset($_POST['start_time'])) $data['start_time'] = $_POST['start_time'];
        if (isset($_POST['end_time'])) $data['end_time'] = $_POST['end_time'];
        if (isset($_POST['reason'])) $data['reason'] = $_POST['reason'];

        $result = $scheduleManager->updateException($exceptionId, $data);
        echo json_encode($result);
        break;

    case 'delete_exception':
        // Delete an exception
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'POST method required']);
            exit;
        }

        $exceptionId = intval($_POST['exception_id'] ?? 0);
        if (!$exceptionId) {
            echo json_encode(['success' => false, 'message' => 'تایبەتمەندی دیاریکراو نییە']);
            exit;
        }

        $result = $scheduleManager->deleteException($exceptionId);
        echo json_encode($result);
        break;

    // ===================== CALENDAR ENDPOINTS =====================

    case 'get_calendar_events':
        // Get appointments and schedule events for FullCalendar
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-t');

        if (!$doctorId) {
            // If admin and no doctor specified, get all appointments
            if ($isAdmin) {
                $doctorId = null;
            } else {
                echo json_encode([]);
                exit;
            }
        }

        // Get appointments
        $appointments = $appointmentManager->getByWeek($start, $end, $doctorId);

        // Status colors
        $statusColors = [
            'scheduled' => '#ff8c00',
            'confirmed' => '#0078d4',
            'waiting' => '#ff9800',
            'in_progress' => '#2196f3',
            'completed' => '#4caf50',
            'cancelled' => '#9e9e9e',
            'no_show' => '#f44336'
        ];

        $events = [];

        foreach ($appointments as $apt) {
            $endTime = date('Y-m-d\TH:i:s', strtotime($apt['appointment_date'] . ' ' . $apt['appointment_time']) + ($apt['duration'] * 60));

            $events[] = [
                'id' => 'apt_' . $apt['id'],
                'title' => $apt['patient_name'],
                'start' => $apt['appointment_date'] . 'T' . $apt['appointment_time'],
                'end' => $endTime,
                'backgroundColor' => $statusColors[$apt['status']] ?? '#757575',
                'borderColor' => $statusColors[$apt['status']] ?? '#757575',
                'extendedProps' => [
                    'appointmentId' => $apt['id'],
                    'patientId' => $apt['patient_id'],
                    'patientName' => $apt['patient_name'],
                    'patientPhone' => $apt['patient_phone'] ?? '',
                    'doctorId' => $apt['doctor_id'],
                    'doctorName' => $apt['doctor_name'],
                    'status' => $apt['status'],
                    'type' => $apt['type'],
                    'duration' => $apt['duration'],
                    'notes' => $apt['notes'] ?? ''
                ]
            ];
        }

        // Add schedule events (day offs)
        if ($doctorId) {
            $scheduleEvents = $scheduleManager->getScheduleEvents($doctorId, $start, $end);
            $events = array_merge($events, $scheduleEvents);
        }

        // FullCalendar expects array directly (not wrapped in success/data)
        echo json_encode($events);
        break;

    case 'get_doctors':
        // Get list of doctors (for admin dropdown)
        if (!$isAdmin) {
            echo json_encode(['success' => false, 'message' => 'ڕێگەپێدراو نیت']);
            exit;
        }

        $authClass = new Auth();
        $doctors = $authClass->getDoctors();

        echo json_encode(['success' => true, 'data' => $doctors]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'کردار نەناسراو: ' . $action]);
}
