<?php

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

        $doctorRows = $db->fetchAll(
            "SELECT DISTINCT d.id, d.name
             FROM doctors d
             WHERE d.is_active = 1
                OR EXISTS (
                    SELECT 1
                    FROM visits v
                    WHERE v.visit_date BETWEEN :from AND :to
                      AND v.visit_type = :visit_type
                      AND (
                          v.requested_doctor_id = d.id
                          OR v.assigned_doctor_id = d.id
                          OR v.referring_doctor_id = d.id
                          OR v.referring_doctor_choice_id = d.id
                          OR v.appointment_doctor_id = d.id
                          OR v.referring_doctor_designation = CAST(d.id AS CHAR)
                      )
                )
             ORDER BY d.name",
            $params
        );

        $doctors = [];
        $doctorStats = [];
        foreach ($doctorRows as $doctor) {
            $id = (int) $doctor['id'];
            $doctors[$id] = $doctor['name'];
            $doctorStats[$id] = [
                'name' => $doctor['name'],
                'requested' => 0,
                'assigned' => 0,
                'new' => 0,
                'old' => 0
            ];
        }

        $overall = $db->fetch(
            "SELECT
                 COUNT(*) AS total,
                 SUM(CASE WHEN patient_status = 'new' THEN 1 ELSE 0 END) AS new_total,
                 SUM(CASE WHEN patient_status = 'old' THEN 1 ELSE 0 END) AS old_total
             FROM visits
             WHERE visit_date BETWEEN :from AND :to
               AND visit_type = :visit_type",
            $params
        ) ?: ['total' => 0, 'new_total' => 0, 'old_total' => 0];

        $requestedRows = $db->fetchAll(
            "SELECT requested_doctor_id, patient_status, COUNT(*) AS count
             FROM visits
             WHERE visit_date BETWEEN :from AND :to
               AND visit_type = :visit_type
               AND requested_doctor_id IS NOT NULL
             GROUP BY requested_doctor_id, patient_status",
            $params
        );

        foreach ($requestedRows as $row) {
            $doctorId = (int) $row['requested_doctor_id'];
            if (!isset($doctorStats[$doctorId])) {
                continue;
            }
            $count = (int) $row['count'];
            $doctorStats[$doctorId]['requested'] += $count;
            if (($row['patient_status'] ?? '') === 'new') {
                $doctorStats[$doctorId]['new'] += $count;
            } else {
                $doctorStats[$doctorId]['old'] += $count;
            }
        }

        $assignedRows = $db->fetchAll(
            "SELECT assigned_doctor_id, COUNT(*) AS count
             FROM visits
             WHERE visit_date BETWEEN :from AND :to
               AND visit_type = :visit_type
               AND assigned_doctor_id IS NOT NULL
             GROUP BY assigned_doctor_id",
            $params
        );

        foreach ($assignedRows as $row) {
            $doctorId = (int) $row['assigned_doctor_id'];
            if (isset($doctorStats[$doctorId])) {
                $doctorStats[$doctorId]['assigned'] = (int) $row['count'];
            }
        }

        $doctorTotals = ['requested' => 0, 'assigned' => 0, 'new' => 0, 'old' => 0];
        foreach ($doctorStats as $stats) {
            $doctorTotals['requested'] += (int) $stats['requested'];
            $doctorTotals['assigned'] += (int) $stats['assigned'];
            $doctorTotals['new'] += (int) $stats['new'];
            $doctorTotals['old'] += (int) $stats['old'];
        }

        $receptionistStats = $db->fetchAll(
            "SELECT v.receptionist_id, COALESCE(u.full_name, CONCAT('User #', v.receptionist_id)) AS name, COUNT(*) AS count
             FROM visits v
             LEFT JOIN users u ON v.receptionist_id = u.id
             WHERE v.visit_date BETWEEN :from AND :to
               AND v.visit_type = :visit_type
               AND v.receptionist_id IS NOT NULL
             GROUP BY v.receptionist_id, u.full_name
             ORDER BY count DESC, name",
            $params
        );

        $receptionistTotal = 0;
        foreach ($receptionistStats as $stat) {
            $receptionistTotal += (int) $stat['count'];
        }

        $receptionistRedirections = [];
        $receptionistRedirectionRows = $db->fetchAll(
            "SELECT
                 v.receptionist_id,
                 COALESCE(u.full_name, CONCAT('User #', v.receptionist_id)) AS receptionist_name,
                 v.assigned_doctor_id,
                 COUNT(*) AS count
             FROM visits v
             LEFT JOIN users u ON v.receptionist_id = u.id
             WHERE v.visit_date BETWEEN :from AND :to
               AND v.visit_type = :visit_type
               AND v.receptionist_id IS NOT NULL
               AND v.requested_doctor_id IS NOT NULL
               AND v.assigned_doctor_id IS NOT NULL
               AND v.requested_doctor_id <> v.assigned_doctor_id
             GROUP BY v.receptionist_id, u.full_name, v.assigned_doctor_id
             ORDER BY receptionist_name",
            $params
        );

        foreach ($receptionistRedirectionRows as $row) {
            $receptionistId = (int) $row['receptionist_id'];
            $doctorId = (int) $row['assigned_doctor_id'];
            if (!isset($doctors[$doctorId])) {
                continue;
            }
            if (!isset($receptionistRedirections[$receptionistId])) {
                $receptionistRedirections[$receptionistId] = [
                    'name' => $row['receptionist_name'],
                    'doctors' => [],
                    'total' => 0
                ];
            }
            $count = (int) $row['count'];
            $receptionistRedirections[$receptionistId]['doctors'][$doctorId] = $count;
            $receptionistRedirections[$receptionistId]['total'] += $count;
        }

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

            if ($designation === 'DOCTORS') {
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
                        foreach ($doctors as $doctorId => $doctorName) {
                            if (stripos($designation, (string) $doctorName) !== false) {
                                $destinationDoctorId = (int) $doctorId;
                                break;
                            }
                        }
                    }
                }
            }

            if (!$redirectorId || !$destinationDoctorId || !isset($doctors[$destinationDoctorId])) {
                continue;
            }

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

        $receptionistRedirectionColumnTotals = dh_visit_report_matrix_totals($receptionistRedirections, $doctors);
        $appointmentRedirectionColumnTotals = dh_visit_report_matrix_totals($appointmentRedirections, $doctors);

        return [
            'doctors' => $doctors,
            'doctor_stats' => $doctorStats,
            'doctor_totals' => $doctorTotals,
            'overall' => [
                'total' => (int) ($overall['total'] ?? 0),
                'new_total' => (int) ($overall['new_total'] ?? 0),
                'old_total' => (int) ($overall['old_total'] ?? 0)
            ],
            'receptionist_stats' => $receptionistStats,
            'receptionist_total' => $receptionistTotal,
            'receptionist_redirections' => $receptionistRedirections,
            'receptionist_redirection_total' => array_sum($receptionistRedirectionColumnTotals),
            'receptionist_redirection_column_totals' => $receptionistRedirectionColumnTotals,
            'appointment_redirections' => $appointmentRedirections,
            'appointment_redirection_total' => array_sum($appointmentRedirectionColumnTotals),
            'appointment_redirection_column_totals' => $appointmentRedirectionColumnTotals,
            'appointment_date_filter' => $appointmentDateFilter,
            'visit_type' => $visitType
        ];
    }
}
