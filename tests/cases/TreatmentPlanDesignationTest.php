<?php // tests/cases/TreatmentPlanDesignationTest.php
// کێ چارەسەر دەکات؟ — the examiner (دکتۆری دەستنیشانکەر) routes his plan to
// himself, one colleague, or «چەند دکتۆرێک» (then reception picks). Pinned:
// who sees the plan, that ONLY the treating doctor books (nobody books for a
// colleague), that the decision locks once booked or transferred, that work
// lands in the treating doctor's own case, and that the reception checkup
// visit is filled from the plan instead of being typed twice.
require_once __DIR__ . '/../../classes/TreatmentPlan.php';
require_once __DIR__ . '/../../classes/Patient.php';

$db = Database::getInstance();
$tp = new TreatmentPlan();

$examiner = fx_user('doctor');
$docB = fx_user('doctor');
$docC = fx_user('doctor');
$docD = fx_user('doctor');
$recep = fx_user('receptionist');
$rowOf = function ($uid) use ($db) {
    return (int)$db->insert('doctors', ['name' => 'Doctor row ' . $uid, 'user_id' => $uid, 'is_active' => 1]);
};
$exRow = $rowOf($examiner);
$bRow = $rowOf($docB);
$cRow = $rowOf($docC);
$rowOf($docD);

$checkup = function ($patientId, $doctorRow, $date = null) use ($db, $recep) {
    return (int)$db->insert('visits', [
        'patient_id' => $patientId, 'patient_name' => 'Visit patient ' . $patientId, 'mobile_number' => '0750',
        'arrival_time' => '09:00:00', 'patient_status' => 'new', 'visit_date' => $date ?: date('Y-m-d'),
        'visit_type' => 'checkup', 'requested_doctor_id' => $doctorRow, 'assigned_doctor_id' => $doctorRow,
        'checkup_doctor_id' => $doctorRow, 'receptionist_id' => $recep,
    ]);
};
$visit = function ($id) use ($db) { return $db->fetch("SELECT * FROM visits WHERE id = :id", ['id' => $id]); };
$notes = function ($uid) use ($db) {
    return (int)$db->fetch("SELECT COUNT(*) c FROM notifications WHERE user_id = :u AND type = 'treatment_plan'", ['u' => $uid])['c'];
};
$in = function ($days) { return date('Y-m-d', strtotime("+{$days} days")); };

// ── 1. A new plan finds the examiner's checkup and names him دەستنیشانکەر ──
$pat = fx_patient();
$visitId = $checkup($pat, $exRow);
$planId = (int)$tp->create(['patient_id' => $pat, 'doctor_id' => $examiner, 'created_by' => $examiner])['plan_id'];
$plan = $tp->getById($planId);
$t->assertSame($visitId, (int)$plan['visit_id'], 'plan links to the examiner\'s checkup visit');
$t->assertSame('undecided', TreatmentPlan::designationState($plan), 'a new plan is undecided');
$t->assertSame($examiner, TreatmentPlan::treatingDoctorId($plan), 'undecided behaves like «خۆم»');
$v = $visit($visitId);
$t->assertSame((string)$exRow, (string)$v['referring_doctor_id'], 'visit: examiner is دکتۆری دەستنیشانکەر');
$t->assertSame(null, $v['referring_doctor_designation'], 'visit: nothing designated while undecided');

// ── 2. One colleague ──
$r = $tp->designate($planId, 'doctor', $docB, [], $examiner);
$t->assertTrue(!empty($r['success']), 'examiner sends the plan to doctor B');
$plan = $tp->getById($planId);
$t->assertSame('doctor', TreatmentPlan::designationState($plan), 'state: one doctor');
$t->assertSame($docB, TreatmentPlan::treatingDoctorId($plan), 'doctor B treats');
$t->assertSame((string)$bRow, (string)$visit($visitId)['referring_doctor_designation'], 'visit: B is دکتۆری دەستنیشانکراو');
$t->assertSame(1, $notes($docB), 'doctor B is notified');
$t->assertTrue(TreatmentPlan::doctorMaySee($plan, $docB), 'B sees the plan');
$t->assertTrue(TreatmentPlan::doctorMaySee($plan, $examiner), 'the examiner still sees his plan');
$t->assertTrue(!TreatmentPlan::doctorMaySee($plan, $docC), 'an uninvolved doctor does not');
$t->assertSame(1, count($tp->getByPatient($pat, $docB)), 'B finds it on the patient page');
$t->assertSame(0, count($tp->getByPatient($pat, $docC)), 'C does not');
$patients = new Patient();
$t->assertTrue($patients->wasPatientReferredToDoctor($pat, $docB), 'the plan lets B open the patient');
$t->assertTrue(!$patients->wasPatientReferredToDoctor($pat, $docC), '… and nobody else');
$again = $tp->designate($planId, 'doctor', $docB, [], $examiner);
$t->assertTrue(!empty($again['success']) && empty($again['changed']), 'the same decision twice is a no-op');
$t->assertSame(1, $notes($docB), '… and notifies nobody again');

// ── 3. Only the treating doctor books ──
$r = $tp->bookAppointment($planId, ['appointment_date' => $in(3), 'appointment_time' => '10:30'], $examiner);
$t->assertTrue(empty($r['success']),'the examiner cannot book for doctor B');
$r = $tp->bookAppointment($planId, ['appointment_date' => $in(3), 'appointment_time' => '10:30'], $docC);
$t->assertTrue(empty($r['success']),'an uninvolved doctor cannot book');
$r = $tp->bookAppointment($planId, ['appointment_date' => date('Y-m-d', strtotime('-1 day')), 'appointment_time' => '10:30'], $docB);
$t->assertTrue(empty($r['success']),'a past date is refused');
$r = $tp->bookAppointment($planId, ['appointment_date' => $in(3), 'appointment_time' => '10:30', 'duration' => 45], $docB);
$t->assertTrue(!empty($r['success']), 'doctor B books his own visit');
$apptId = (int)$r['appointment_id'];
$appt = $db->fetch("SELECT * FROM appointments WHERE id = :id", ['id' => $apptId]);
$t->assertSame($docB, (int)$appt['doctor_id'], 'booked under doctor B');
$t->assertSame($pat, (int)$appt['patient_id'], '… for the plan\'s patient');
$t->assertSame('10:30:00', $appt['appointment_time'], '… at the chosen time');
$t->assertSame(45, (int)$appt['duration'], '… for the chosen length');
$v = $visit($visitId);
$t->assertSame($in(3), $v['appointment_date'], 'visit: appointment date filled');
$t->assertSame($bRow, (int)$v['appointment_doctor_id'], 'visit: appointment doctor filled');

// ── 4. Booked → the decision is locked; B moves his own booking in place ──
$r = $tp->designate($planId, 'doctor', $docC, [], $examiner);
$t->assertTrue(empty($r['success']),'cannot re-route while B holds a booking');
$r = $tp->bookAppointment($planId, ['appointment_date' => $in(4), 'appointment_time' => '11:00'], $docB);
$t->assertTrue(!empty($r['success']) && (int)$r['appointment_id'] === $apptId, 'rebooking moves the same appointment');
$appt = $db->fetch("SELECT * FROM appointments WHERE id = :id", ['id' => $apptId]);
$t->assertSame($in(3), $appt['rescheduled_from_date'], 'the old date is kept as rescheduled_from');
$t->assertSame($in(4), $visit($visitId)['appointment_date'], 'visit follows the move');

// ── 5. Cancelling: only the doctor who booked ──
$r = $tp->cancelAppointment($planId, $examiner, '');
$t->assertTrue(empty($r['success']),'the examiner cannot cancel B\'s booking');
$r = $tp->cancelAppointment($planId, $docB, 'patient asked');
$t->assertTrue(!empty($r['success']), 'B cancels his booking');
$t->assertSame('cancelled', $db->fetch("SELECT status FROM appointments WHERE id = :id", ['id' => $apptId])['status'], 'appointment cancelled');
$t->assertSame(null, $tp->getById($planId)['appointment_id'], 'plan no longer points at it');
$t->assertSame(null, $visit($visitId)['appointment_date'], 'visit appointment cleared');

// ── 6. «چەند دکتۆرێک» — reception decides ──
$r = $tp->designate($planId, 'doctors', null, [$docC], $examiner);
$t->assertTrue(empty($r['success']),'one candidate is not «several»');
$recepNotesBefore = $notes($recep);
$r = $tp->designate($planId, 'doctors', null, [$docD, $docC], $examiner);
$t->assertTrue(!empty($r['success']), 'examiner hands the choice to reception (C or D)');
$plan = $tp->getById($planId);
$t->assertSame('awaiting_reception', TreatmentPlan::designationState($plan), 'waiting for reception');
$t->assertSame(null, TreatmentPlan::treatingDoctorId($plan), 'nobody treats yet');
$t->assertSame(min($docC, $docD) . ',' . max($docC, $docD), $plan['candidate_doctor_ids'], 'candidates stored in a fixed order');
$t->assertTrue(!TreatmentPlan::doctorMaySee($plan, $docB), 'B lost the plan');
$t->assertTrue(!TreatmentPlan::doctorMaySee($plan, $docC), 'candidates do not see it before the choice');
$t->assertSame($recepNotesBefore + 1, $notes($recep), 'reception is notified');
$v = $visit($visitId);
$t->assertSame('DOCTORS', $v['referring_doctor_designation'], 'visit: «چەند دکتۆرێک»');
$t->assertSame(null, $v['referring_doctor_choice_id'], 'visit: no doctor chosen yet');
$awaitingIds = array_map('intval', array_column($tp->awaitingReception(), 'id'));
$t->assertTrue(in_array($planId, $awaitingIds, true), 'listed for reception');
$t->assertTrue($tp->countAwaitingReception() >= 1, 'reception badge counts it');
$r = $tp->bookAppointment($planId, ['appointment_date' => $in(2), 'appointment_time' => '09:00'], $docC);
$t->assertTrue(empty($r['success']),'nobody books before reception chooses');
$tp->addItem($planId, ['treatment_name' => 'Filling', 'unit_price' => 50000, 'tooth_number' => 16]);
$itemId = (int)$db->fetch("SELECT id FROM treatment_plan_items WHERE plan_id = :p", ['p' => $planId])['id'];
$r = $tp->transferItems($planId, [$itemId], ['case_name' => 'Test'], $recep);
$t->assertTrue(empty($r['success']),'no transfer before reception chooses');

$r = $tp->chooseDoctor($planId, $docB, $recep);
$t->assertTrue(empty($r['success']),'reception must pick one of the named doctors');
$r = $tp->chooseDoctor($planId, $docC, $recep);
$t->assertTrue(!empty($r['success']), 'reception sends the patient to C');
$plan = $tp->getById($planId);
$t->assertSame('chosen', TreatmentPlan::designationState($plan), 'state: chosen');
$t->assertSame($docC, TreatmentPlan::treatingDoctorId($plan), 'C treats');
$t->assertSame(1, $notes($docC), 'C is notified');
$v = $visit($visitId);
$t->assertSame($cRow, (int)$v['referring_doctor_choice_id'], 'visit: دکتۆری هەڵبژێردراو = C');
$t->assertSame($recep, (int)$v['appointment_added_by'], 'visit: کارمەندی دەستنیشانکەر = the receptionist');
$t->assertTrue(in_array($planId, array_map('intval', array_column($tp->directedTo($docC), 'id')), true), 'on C\'s «sent to me» list');
$t->assertSame(1, $tp->countDirectedUnbooked($docC), 'C\'s badge: one plan to book');
$r = $tp->bookAppointment($planId, ['appointment_date' => $in(2), 'appointment_time' => '09:00'], $docC);
$t->assertTrue(!empty($r['success']), 'C books');
$t->assertSame(0, $tp->countDirectedUnbooked($docC), 'badge clears once booked');
$r = $tp->chooseDoctor($planId, $docD, $recep);
$t->assertTrue(empty($r['success']),'reception cannot move a patient C has booked');

// ── 7. Work lands only in the treating doctor's own case ──
$exCase = fx_case(fx_folder($pat, $examiner));
$targets = array_map('intval', array_column($tp->transferTargets($pat, $docC), 'id'));
$t->assertTrue(!in_array($exCase, $targets, true), 'the examiner\'s case is not a target for C\'s work');
$r = $tp->transferItems($planId, [$itemId], ['case_id' => $exCase], $recep);
$t->assertTrue(empty($r['success']),'transfer into the examiner\'s case is refused');
$r = $tp->transferItems($planId, [$itemId], ['case_name' => 'Plan work'], $recep);
$t->assertTrue(!empty($r['success']), 'reception transfers the work');
$newCase = (int)$r['case_id'];
$owner = $db->fetch("SELECT pf.doctor_id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = :id", ['id' => $newCase]);
$t->assertSame($docC, (int)$owner['doctor_id'], 'the new case is doctor C\'s');
$ct = $db->fetch("SELECT performed_by FROM case_treatments WHERE case_id = :c", ['c' => $newCase]);
$t->assertSame($docC, (int)$ct['performed_by'], 'performed_by is C');
$caseOfAppt = $db->fetch("SELECT a.case_id FROM appointments a JOIN treatment_plans tp ON tp.appointment_id = a.id WHERE tp.id = :p", ['p' => $planId]);
$t->assertSame($newCase, (int)$caseOfAppt['case_id'], 'C\'s booking now belongs to the new case');
$r = $tp->designate($planId, 'self', null, [], $examiner);
$t->assertTrue(empty($r['success']),'transferred work locks the decision');

// ── 8. Undecided plans pin «خۆم» when the examiner acts ──
$pat2 = fx_patient();
$visit2 = $checkup($pat2, $exRow);
$plan2 = (int)$tp->create(['patient_id' => $pat2, 'doctor_id' => $examiner])['plan_id'];
$r = $tp->bookAppointment($plan2, ['appointment_date' => $in(5), 'appointment_time' => '12:00'], $examiner);
$t->assertTrue(!empty($r['success']), 'the examiner books his own undecided plan');
$t->assertSame('self', TreatmentPlan::designationState($tp->getById($plan2)), '… which records «خۆم»');
$t->assertSame((string)$exRow, (string)$visit($visit2)['referring_doctor_designation'], 'visit: examiner designated himself');
$r = $tp->cancelPlan($plan2, 'changed mind', $examiner);
$t->assertTrue(empty($r['success']), 'a plan with a live booking cannot be cancelled');
$tp->cancelAppointment($plan2, $examiner, '');
$r = $tp->cancelPlan($plan2, 'changed mind', $examiner);
$t->assertTrue(!empty($r['success']), '… once the booking is cancelled, it can');

$pat3 = fx_patient();
$plan3 = (int)$tp->create(['patient_id' => $pat3, 'doctor_id' => $examiner])['plan_id'];
$tp->addItem($plan3, ['treatment_name' => 'Scaling', 'unit_price' => 20000]);
$item3 = (int)$db->fetch("SELECT id FROM treatment_plan_items WHERE plan_id = :p", ['p' => $plan3])['id'];
$r = $tp->transferItems($plan3, [$item3], ['case_name' => 'Legacy'], $recep);
$t->assertTrue(!empty($r['success']), 'an undecided plan still transfers, to the examiner');
$t->assertSame('self', TreatmentPlan::designationState($tp->getById($plan3)), '… and is recorded as «خۆم»');

// ── 9. Visit linking: never a colleague's checkup, one plan per visit ──
$pat4 = fx_patient();
$checkup($pat4, $bRow);
$plan4 = (int)$tp->create(['patient_id' => $pat4, 'doctor_id' => $examiner])['plan_id'];
$t->assertSame(null, $tp->getById($plan4)['visit_id'], 'B\'s checkup is not borrowed by the examiner\'s plan');
$plan5 = (int)$tp->create(['patient_id' => $pat, 'doctor_id' => $examiner])['plan_id'];
$t->assertSame(null, $tp->getById($plan5)['visit_id'], 'a second plan does not take over a visit');
$pat6 = fx_patient();
$checkup($pat6, $bRow);
$noRowAdmin = fx_user('admin');
$plan6 = (int)$tp->create(['patient_id' => $pat6, 'doctor_id' => $noRowAdmin])['plan_id'];
$t->assertSame(null, $tp->getById($plan6)['visit_id'], 'an examiner with no doctors row never takes a colleague\'s visit');
$t->assertSame($planId, (int)$tp->planForVisit($visitId)['id'], 'the visit reports its own plan');

// ── 10. Cancelled / closed plans refuse routing ──
$tp->cancelPlan($plan4, 'test', $examiner);
$r = $tp->designate($plan4, 'doctor', $docB, [], $examiner);
$t->assertTrue(empty($r['success']),'a cancelled plan is not routed');
$r = $tp->bookAppointment($plan4, ['appointment_date' => $in(2), 'appointment_time' => '09:00'], $examiner);
$t->assertTrue(empty($r['success']),'… nor booked');
