<?php
require_once __DIR__ . '/../../classes/Appointment.php';

$db = Database::getInstance();
$appointmentManager = new Appointment();

$calledAtColumn = $db->fetch("SHOW COLUMNS FROM appointments LIKE 'called_to_center_at'");
$calledByColumn = $db->fetch("SHOW COLUMNS FROM appointments LIKE 'called_to_center_by'");
$t->assertTrue(!empty($calledAtColumn), 'appointments store when the patient was called');
$t->assertTrue(!empty($calledByColumn), 'appointments store who called the patient');
$t->assertSame(null, $calledAtColumn['Default'], 'call timestamp is empty by default');

$callDoctor = fx_user('doctor');
$callReception = fx_user('receptionist');
$callPatient = fx_patient();
$appointmentResult = $appointmentManager->create([
    'patient_id' => $callPatient,
    'doctor_id' => $callDoctor,
    'appointment_date' => date('Y-m-d', strtotime('+2 days')),
    'appointment_time' => '10:30:00',
    'duration' => 30,
    'type' => 'checkup',
    'status' => 'scheduled',
    'created_by' => $callDoctor,
]);
$t->assertTrue(!empty($appointmentResult['success']), 'appointment for call tracking is created');
$callAppointmentId = (int)$appointmentResult['appointment_id'];

$beforeCall = $appointmentManager->getById($callAppointmentId);
$t->assertSame(null, $beforeCall['called_to_center_at'], 'new appointment starts not called');
$t->assertSame(null, $beforeCall['called_to_center_by'], 'new appointment has no caller');

$marked = $appointmentManager->markCalledToCenter($callAppointmentId, $callDoctor);
$t->assertTrue(!empty($marked['success']), 'doctor can record the call through appointment service');
$afterDoctorCall = $appointmentManager->getById($callAppointmentId);
$t->assertTrue(!empty($afterDoctorCall['called_to_center_at']), 'successful call stores timestamp');
$t->assertSame($callDoctor, (int)$afterDoctorCall['called_to_center_by'], 'successful call stores doctor user');
$t->assertSame('Test doctor', substr($afterDoctorCall['called_to_center_by_name'], 0, 11), 'caller name is joined for the table');

$markedByReception = $appointmentManager->markCalledToCenter($callAppointmentId, $callReception);
$t->assertTrue(!empty($markedByReception['success']), 'reception can update the latest call');
$afterReceptionCall = $appointmentManager->getById($callAppointmentId);
$t->assertSame($callReception, (int)$afterReceptionCall['called_to_center_by'], 'latest caller replaces prior caller');

$dayRows = $appointmentManager->getByDate($afterReceptionCall['appointment_date'], $callDoctor);
$matchingRows = array_values(array_filter($dayRows, fn($row) => (int)$row['id'] === $callAppointmentId));
$t->assertSame(1, count($matchingRows), 'daily appointment query returns call-tracked appointment');
$t->assertTrue(!empty($matchingRows[0]['called_to_center_by_name']), 'daily table query includes caller name');

$cleared = $appointmentManager->clearCalledToCenter($callAppointmentId, $callReception);
$t->assertTrue(!empty($cleared['success']), 'accidental call mark can be cleared');
$afterClear = $appointmentManager->getById($callAppointmentId);
$t->assertSame(null, $afterClear['called_to_center_at'], 'clearing removes call timestamp');
$t->assertSame(null, $afterClear['called_to_center_by'], 'clearing removes caller');

$archiveCalledAt = $db->fetch("SHOW COLUMNS FROM appointments_deleted LIKE 'called_to_center_at'");
$archiveCalledBy = $db->fetch("SHOW COLUMNS FROM appointments_deleted LIKE 'called_to_center_by'");
$t->assertTrue(!empty($archiveCalledAt) && !empty($archiveCalledBy), 'appointment archive keeps call tracking fields');
