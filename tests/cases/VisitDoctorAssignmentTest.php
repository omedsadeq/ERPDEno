<?php
require_once __DIR__ . '/../../classes/VisitDoctorAssignment.php';
require_once __DIR__ . '/../../classes/VisitDoctorSync.php';
require_once __DIR__ . '/../../includes/VisitReportStats.php';
$db = Database::getInstance();

$t->assertSame('requested', VisitDoctorAssignment::derive(1, null), 'Patient request before waiting room');
$t->assertSame('requested', VisitDoctorAssignment::derive(1, 1), 'Patient seen by requested doctor');
$t->assertSame('reception', VisitDoctorAssignment::derive(1, 2), 'Different seen doctor is a reception transfer');
$t->assertSame('reception', VisitDoctorAssignment::derive(null, 2), 'Reception chose without patient preference');
$t->assertSame(null, VisitDoctorAssignment::derive(null, null), 'No doctor leaves source unknown');
$t->assertSame(null, VisitDoctorAssignment::source(['requested']), 'Malformed source rejected');

$docUserA = fx_user('doctor');
$docUserB = fx_user('doctor');
$staff = fx_user('receptionist');
$docA = (int) $db->insert('doctors', ['name' => 'Assignment Doctor A', 'user_id' => $docUserA]);
$docB = (int) $db->insert('doctors', ['name' => 'Assignment Doctor B', 'user_id' => $docUserB]);
$patient = fx_patient();
$otherPatient = fx_patient();
$date = '2040-10-12';
$insertVisit = static function (array $extra = []) use ($db, $patient, $staff, $date): int {
    return (int) $db->insert('visits', array_merge([
        'patient_id' => $patient, 'patient_name' => 'Assignment Test', 'mobile_number' => '0750',
        'arrival_time' => '09:00:00', 'patient_status' => 'old', 'visit_date' => $date,
        'visit_type' => 'checkup', 'receptionist_id' => $staff,
    ], $extra));
};
$getVisit = static function (int $id) use ($db): array {
    return $db->fetch('SELECT * FROM visits WHERE id=:id', ['id' => $id]);
};
$requestedVisit = $insertVisit(['requested_doctor_id' => $docA, 'doctor_assignment_source' => 'requested']);
VisitDoctorSync::assignToVisit($db, $requestedVisit, $patient, 'checkup', $docA);
$row = $getVisit($requestedVisit);
$t->assertSame($docA, (int) $row['assigned_doctor_id'], 'Queue assignment saves seen doctor');
$t->assertSame($docA, (int) $row['checkup_doctor_id'], 'Queue assignment saves clinical doctor');
VisitDoctorSync::assignToVisit($db, $requestedVisit, $patient, 'checkup', $docB);
$row = $getVisit($requestedVisit);
$t->assertSame($docB, (int) $row['assigned_doctor_id'], 'Doctor change updates seen doctor');
$t->assertSame($docB, (int) $row['checkup_doctor_id'], 'Doctor change updates clinical doctor');
$t->assertSame($docA, (int) $row['requested_doctor_id'], 'Original patient request preserved');
$t->assertSame('requested', $row['doctor_assignment_source'], 'Original source preserved');
VisitDoctorSync::assignToVisit($db, $requestedVisit, $otherPatient, 'checkup', $docA);
$t->assertSame($docB, (int) $getVisit($requestedVisit)['assigned_doctor_id'], 'Wrong patient cannot be updated');
VisitDoctorSync::assignToVisit($db, $requestedVisit, $patient, 'treatment', $docA);
$t->assertSame(null, $getVisit($requestedVisit)['treatment_doctor_id'], 'Wrong visit type cannot be updated');

$receptionVisit = $insertVisit(['doctor_assignment_source' => 'reception']);
$treatmentVisit = $insertVisit(['visit_type' => 'treatment']);
VisitDoctorSync::assignToVisit($db, null, $patient, 'checkup', $docB, $date . ' 12:00:00');
$t->assertSame($docB, (int) $getVisit($receptionVisit)['assigned_doctor_id'], 'Latest matching checkup receives doctor');
$t->assertSame(null, $getVisit($treatmentVisit)['assigned_doctor_id'], 'Later treatment visit is not mistaken for checkup');
VisitDoctorSync::assignToVisit($db, $treatmentVisit, $patient, 'treatment', $docA);
$t->assertSame($docA, (int) $getVisit($treatmentVisit)['treatment_doctor_id'], 'Treatment doctor saved');
$t->assertSame($docA, (int) $getVisit($treatmentVisit)['assigned_doctor_id'], 'Treatment received doctor agrees');
VisitDoctorSync::assignToVisit($db, $receptionVisit, $patient, 'checkup', PHP_INT_MAX);
$t->assertSame($docB, (int) $getVisit($receptionVisit)['assigned_doctor_id'], 'Invalid doctor ignored');
$t->assertSame($docA, VisitDoctorSync::doctorsIdFromUserId($db, $docUserA), 'User and clinical IDs mapped');

$stats = dh_visit_report_stats($db, $date, $date, 'visit_date', 'checkup');
$t->assertSame(2, $stats['doctor_totals']['reception'], 'Explicit reception and legacy doctor change both counted');
$t->assertSame(0, $stats['doctor_totals']['other'], 'Complete new visits do not become other');
$t->assertSame(2, $stats['receptionist_redirections'][$staff]['doctors'][$docB], 'Receiving doctor and receptionist get both cases');
$insertVisit(['checkup_doctor_id' => $docA]);
$stats = dh_visit_report_stats($db, $date, $date);
$t->assertSame(1, $stats['doctor_totals']['other'], 'Unknown historical intent stays unknown');

// The source update joins the existing mysqli transaction, including rollback.
$t->assertSame('denthisab_test', DB_NAME, 'Source-write test restricted to scratch database');
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$connection->begin_transaction();
VisitDoctorAssignment::saveSource($connection, $receptionVisit, 'requested');
$connection->rollback();
$t->assertSame('reception', $getVisit($receptionVisit)['doctor_assignment_source'], 'Source rolls back with failed visit save');
$t->assertThrows(static function () use ($connection, $receptionVisit) {
    VisitDoctorAssignment::saveSource($connection, $receptionVisit, 'invalid');
}, 'Invalid source cannot be saved');
$connection->close();
