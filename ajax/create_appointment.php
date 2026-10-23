<?php
/**
 * AJAX: Create Appointment
 * Used from new_visit.php (and anywhere else that needs to create an appointment inline)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Appointment.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

Csrf::require();

$currentUser = $auth->getCurrentUser();

$doctorId = intval($_POST['doctor_id'] ?? 0);
if ($doctorId <= 0 && ($currentUser['role'] ?? '') === 'doctor') {
    $doctorId = (int)$currentUser['id'];
}

// Doctor isolation: a doctor may only book on THEIR OWN calendar, and only for a
// patient they already own or that reception referred to them (prevents a doctor
// self-referring an arbitrary patient to gain access to their info).
if ($auth->isDoctor() && !$auth->isAdmin()) {
    $doctorId = (int)$currentUser['id'];
    AccessGuard::requirePatientGeneral((int)($_POST['patient_id'] ?? 0), true);
}
// appointments.doctor_id must be a real doctor account — never default to a
// receptionist/admin id just because no doctor was picked.
$validDoctor = $doctorId > 0
    ? Database::getInstance()->fetch("SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1", ['id' => $doctorId])
    : null;
if (!$validDoctor) {
    echo json_encode(['success' => false, 'message' => 'تکایە پزیشک هەڵبژێرە']);
    exit;
}

$data = [
    'patient_id'       => intval($_POST['patient_id'] ?? 0),
    'doctor_id'        => $doctorId,
    'appointment_date' => $_POST['appointment_date'] ?? '',
    'appointment_time' => $_POST['appointment_time'] ?? '',
    'duration'         => intval($_POST['duration'] ?? 30) ?: 30,
    'type'             => $_POST['type'] ?? 'general',
    'work_type_id'     => !empty($_POST['work_type_id']) ? intval($_POST['work_type_id']) : null,
    'promised_amount'  => (isset($_POST['promised_amount']) && $_POST['promised_amount'] !== '')
                            ? floatval($_POST['promised_amount']) : null,
    'notes'            => trim($_POST['notes'] ?? ''),
    'status'           => 'scheduled',
    'created_by'       => (int)$currentUser['id'],
];

if ($data['patient_id'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'نەخۆش دیاری نەکراوە']);
    exit;
}
if (empty($data['appointment_date']) || empty($data['appointment_time'])) {
    echo json_encode(['success' => false, 'message' => 'بەروار و کات داواکراون']);
    exit;
}

$appointmentManager = new Appointment();
$result = $appointmentManager->create($data);

if (!empty($result['success'])) {
    AuditLog::log('create_appointment', 'appointments', $result['appointment_id'] ?? null, null, $data);

    // Notify the doctor + reception (fail-soft)
    try {
        require_once __DIR__ . '/../classes/Notification.php';
        $aptPatient = Database::getInstance()->fetch(
            "SELECT full_name FROM patients WHERE id = :id",
            ['id' => $data['patient_id']]
        );
        (new Notification())->notifyAppointmentEvent(
            'created', $doctorId, $aptPatient['full_name'] ?? '',
            $result['appointment_id'] ?? 0, $data['appointment_date'], $data['appointment_time'],
            (int)$currentUser['id']
        );
    } catch (Exception $notifyError) {
        error_log('create_appointment notify failed: ' . $notifyError->getMessage());
    }
}

echo json_encode($result);
