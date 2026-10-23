<?php // tests/cases/NextAppointmentTest.php
// NextAppointment::forPatient() — the «مەوعیدی داهاتوو» printed on every
// receipt. Pinned: only visits still ahead count (scheduled/confirmed, later
// day or today at a time not passed yet), and among several the one the
// receipt's own treatment asked for wins — same case, then same doctor, then
// simply the earliest.
require_once __DIR__ . '/../../classes/NextAppointment.php';

$db   = Database::getInstance();
$docA = fx_user('doctor');
$docB = fx_user('doctor');
$pat  = fx_patient();
$caseA = fx_case(fx_folder($pat, $docA));

$book = function ($date, $time, $doctor, $case = null, $status = 'scheduled') use ($db, $pat) {
    return (int)$db->insert('appointments', [
        'patient_id' => $pat, 'doctor_id' => $doctor, 'case_id' => $case,
        'appointment_date' => $date, 'appointment_time' => $time, 'status' => $status,
    ]);
};
$in = function ($days) { return date('Y-m-d', strtotime("+{$days} days")); };

$t->assertSame(null, NextAppointment::forPatient($pat, $caseA, $docA), 'no booking → nothing printed');

// Never counted: past, cancelled, completed, and today at a time already gone.
$book(date('Y-m-d', strtotime('-2 days')), '10:00:00', $docA, $caseA);
$book($in(1), '09:00:00', $docA, $caseA, 'cancelled');
$book($in(1), '09:30:00', $docA, $caseA, 'completed');
$book(date('Y-m-d'), '00:00:01', $docA, $caseA);
$t->assertSame(null, NextAppointment::forPatient($pat, $caseA, $docA), 'past / cancelled / completed / earlier today are ignored');

// Three live bookings: another doctor soonest, this doctor next, this case last.
$book($in(3), '10:00:00', $docB);
$book($in(5), '11:00:00', $docA);
$book($in(9), '12:30:00', $docA, $caseA);

$onCase = NextAppointment::forPatient($pat, $caseA, $docA);
$t->assertSame($in(9), $onCase['date'], 'the booking on this case wins');
$t->assertSame('12:30', $onCase['time'], '… with its own time');

$sameDoc = NextAppointment::forPatient($pat, 0, $docA);
$t->assertSame($in(5), $sameDoc['date'], 'no case given → the same doctor wins');

$any = NextAppointment::forPatient($pat);
$t->assertSame($in(3), $any['date'], 'nothing known → simply the earliest');

$days = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
$t->assertSame($days[(int)date('w', strtotime($in(3)))], $any['day'], 'the Kurdish weekday matches the date');
$t->assertSame(date('Y/m/d', strtotime($in(3))), $any['date_display'], 'printed date form');
$t->assertTrue(strpos(NextAppointment::text($any), 'کاتژمێر 10:00') !== false, 'one-line text carries the time');

$book($in(2), '08:00:00', $docA, $caseA, 'confirmed');
$t->assertSame($in(2), NextAppointment::forPatient($pat, $caseA, $docA)['date'], 'a confirmed booking counts too, earliest on the case first');
$t->assertSame(null, NextAppointment::forPatient(0, $caseA, $docA), 'no patient → nothing');
