<?php

require_once __DIR__ . '/VisitReportMatrix.php';

function dh_visit_report_doctor_sql(string $visitType): string
{
    $clinicalDoctor = $visitType === 'treatment' ? 'treatment_doctor_id' : 'checkup_doctor_id';
    return "COALESCE(NULLIF(v.assigned_doctor_id, 0), NULLIF(v.{$clinicalDoctor}, 0), NULLIF(v.requested_doctor_id, 0), 0)";
}

if (!function_exists('dh_visit_report_matrix_totals')) {
    function dh_visit_report_matrix_totals(array $rows, array $doctors) {
        $totals = [];
        foreach ($doctors as $doctorId => $_name) {
            $totals[$doctorId] = 0;
        }

        foreach ($rows as $row) {
            foreach (($row['doctors'] ?? []) as $doctorId => $count) {
                if (!isset($totals[$doctorId])) {
                    $totals[$doctorId] = 0;
                }
                $totals[$doctorId] += (int) $count;
            }
        }

        return $totals;
    }
}

if (!function_exists('dh_visit_report_stats')) {
    function dh_visit_report_stats(Database $db, string $rangeFrom, string $rangeTo, string $appointmentDateFilter = 'visit_date', string $visitType = 'checkup') {
        $appointmentDateFilter = $appointmentDateFilter === 'appointment_date' ? 'appointment_date' : 'visit_date';
        $visitType = in_array($visitType, ['checkup', 'treatment'], true) ? $visitType : 'checkup';
        $params = ['from' => $rangeFrom, 'to' => $rangeTo, 'visit_type' => $visitType];

        // Preserve all active doctors and any historical doctor used by this period.
        $doctorRows = $db->fetchAll("SELECT id, name, is_active FROM doctors ORDER BY name");
        $allDoctors = [];
        $doctors = [];
        foreach ($doctorRows as $doctor) {
            $id = (int) $doctor['id'];
            $allDoctors[$id] = $doctor['name'];
            if ((int) $doctor['is_active'] === 1) {
                $doctors[$id] = $doctor['name'];
            }
        }
        // Clinical doctor fields cover visits entered through the newer forms.
        $doctorSql = dh_visit_report_doctor_sql($visitType);
        $visitGroups = $db->fetchAll(
            "SELECT {$doctorSql} AS doctor_id,
                    requested_doctor_id, assigned_doctor_id, doctor_assignment_source, patient_status, receptionist_id, COUNT(*) AS count
             FROM visits v
             WHERE visit_date BETWEEN :from AND :to AND visit_type = :visit_type
             GROUP BY doctor_id, requested_doctor_id, assigned_doctor_id, doctor_assignment_source, patient_status, receptionist_id",
            $params
        );
        foreach ($visitGroups as $group) {
            $id = (int) $group['doctor_id'];
            if ($id > 0 && isset($allDoctors[$id])) {
                $doctors[$id] = $allDoctors[$id];
            }
        }
        $receptionistStats = $db->fetchAll(
            "SELECT v.receptionist_id, COALESCE(u.full_name, CONCAT('کارمەند #', v.receptionist_id)) AS name, COUNT(*) AS count
             FROM visits v LEFT JOIN users u ON v.receptionist_id = u.id
             WHERE v.visit_date BETWEEN :from AND :to AND v.visit_type = :visit_type
               AND v.receptionist_id IS NOT NULL
             GROUP BY v.receptionist_id, u.full_name ORDER BY count DESC, name",
            $params
        );
        $receptionistTotal = array_sum(array_column($receptionistStats, 'count'));
        $users = [];
        foreach ($db->fetchAll("SELECT id, full_name FROM users ORDER BY full_name") as $user) {
            $users[(int) $user['id']] = $user['full_name'];
        }

        $dateColumn = $appointmentDateFilter === 'appointment_date' ? 'v.appointment_date' : 'v.visit_date';
        $appointmentRows = $db->fetchAll(
            "SELECT
                 v.referring_doctor_id,
                 v.referring_doctor_designation,
                 v.referring_doctor_choice_id,
                 v.appointment_added_by,
                 v.appointment_doctor_id,
                 d1.name AS referring_doctor_name,
                 u.full_name AS receptionist_name
             FROM visits v
             LEFT JOIN doctors d1 ON v.referring_doctor_id = d1.id
             LEFT JOIN users u ON v.appointment_added_by = u.id
             WHERE {$dateColumn} BETWEEN :from AND :to
               AND {$dateColumn} IS NOT NULL
               AND v.visit_type = :visit_type
               AND v.referring_doctor_designation IS NOT NULL
               AND TRIM(v.referring_doctor_designation) <> ''
               AND TRIM(v.referring_doctor_designation) <> '0'",
            $params
        );

        $appointmentRedirections = [];
        foreach ($appointmentRows as $row) {
            $redirectorId = null;
            $redirectorName = null;
            $redirectorType = null;
            $destinationDoctorId = null;
            $designation = trim((string) ($row['referring_doctor_designation'] ?? ''));

            if ($designation === 'PATIENT') {
                $redirectorId = 'patient';
                $redirectorName = 'نەخۆش بەخۆی دیاریکرد';
                $redirectorType = 'patient';
                $destinationDoctorId = (int) (($row['referring_doctor_choice_id'] ?? 0) ?: ($row['appointment_doctor_id'] ?? 0));
            } elseif ($designation === 'DOCTORS') {
                $addedBy = (int) ($row['appointment_added_by'] ?? 0);
                if ($addedBy > 0) {
                    $redirectorId = 'r_' . $addedBy;
                    $redirectorName = $row['receptionist_name'] ?: ($users[$addedBy] ?? ('کارمەند #' . $addedBy));
                    $redirectorType = 'receptionist';
                    $destinationDoctorId = !empty($row['referring_doctor_choice_id'])
                        ? (int) $row['referring_doctor_choice_id']
                        : (int) ($row['appointment_doctor_id'] ?? 0);
                }
            } elseif ($designation !== '') {
                $referringDoctorId = (int) ($row['referring_doctor_id'] ?? 0);
                if ($referringDoctorId > 0) {
                    $redirectorId = 'd_' . $referringDoctorId;
                    $redirectorName = $row['referring_doctor_name'] ?: ($doctors[$referringDoctorId] ?? ('دکتۆر #' . $referringDoctorId));
                    $redirectorType = 'doctor';

                    if (is_numeric($designation)) {
                        $destinationDoctorId = (int) $designation;
                    } else {
                        foreach ($allDoctors as $doctorId => $doctorName) {
                            if (stripos($designation, (string) $doctorName) !== false) {
                                $destinationDoctorId = (int) $doctorId;
                                break;
                            }
                        }
                    }
                }
            }

            if (!$redirectorId || !$destinationDoctorId || !isset($allDoctors[$destinationDoctorId])) {
                continue;
            }

            $doctors[$destinationDoctorId] = $allDoctors[$destinationDoctorId];

            if (!isset($appointmentRedirections[$redirectorId])) {
                $appointmentRedirections[$redirectorId] = [
                    'name' => $redirectorName,
                    'type' => $redirectorType,
                    'doctors' => [],
                    'total' => 0
                ];
            }

            if (!isset($appointmentRedirections[$redirectorId]['doctors'][$destinationDoctorId])) {
                $appointmentRedirections[$redirectorId]['doctors'][$destinationDoctorId] = 0;
            }

            $appointmentRedirections[$redirectorId]['doctors'][$destinationDoctorId]++;
            $appointmentRedirections[$redirectorId]['total']++;
        }

        $matrix = dh_visit_report_build_matrix($visitGroups, $doctors, $users);
        $appointmentRedirectionColumnTotals = dh_visit_report_matrix_totals($appointmentRedirections, $matrix['doctors']);

        return array_merge($matrix, [
            'receptionist_stats' => $receptionistStats,
            'receptionist_total' => $receptionistTotal,
            'appointment_redirections' => $appointmentRedirections,
            'appointment_redirection_total' => array_sum($appointmentRedirectionColumnTotals),
            'appointment_redirection_column_totals' => $appointmentRedirectionColumnTotals,
            'appointment_date_filter' => $appointmentDateFilter,
            'visit_type' => $visitType
        ]);
    }
}
