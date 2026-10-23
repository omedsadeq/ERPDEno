<?php

/** Resolve the receiving doctor from the visit's existing treatment-routing card. */
function dh_visit_referral_destination(array $visit, array $doctors): int
{
    $designation = trim((string) ($visit['referring_doctor_designation'] ?? ''));
    if ($designation === 'DOCTORS' || $designation === 'PATIENT') {
        return (int) (($visit['referring_doctor_choice_id'] ?? 0) ?: ($visit['appointment_doctor_id'] ?? 0));
    }
    if (ctype_digit($designation)) {
        return (int) $designation;
    }
    foreach ($doctors as $id => $name) {
        if ($designation !== '' && $designation === trim((string) $name)) {
            return (int) $id;
        }
    }
    return (int) ($visit['appointment_doctor_id'] ?? 0);
}

/** A referral contributes once to its receiving doctor and its recorded chooser. */
function dh_visit_referral_matrix(array $visits, int $examinerId, array $doctors, array $users, ?array $activeDoctorIds = null): array
{
    $rows = $examinerId > 0 ? [
        'doctor' => ['name' => $doctors[$examinerId] ?? 'دکتۆر', 'type' => 'doctor', 'doctors' => [], 'total' => 0],
    ] : [];
    $totals = [];
    $pending = $self = $excluded = 0;
    $seen = [];
    $visibleDoctors = dh_visit_report_visible_doctors($doctors);
    foreach ($visits as $visit) {
        $source = trim((string) ($visit['referring_doctor_id'] ?? ''));
        if ($examinerId > 0 && $source !== (string) $examinerId) {
            continue;
        }
        if (!empty($visit['id'])) {
            if (isset($seen[$visit['id']])) { continue; }
            $seen[$visit['id']] = true;
        }
        $count = max(0, (int) ($visit['count'] ?? 1));
        $sourceId = ctype_digit($source) ? (int) $source : 0;
        if ($sourceId <= 0 || !isset($visibleDoctors[$sourceId])) {
            $excluded += $count;
            continue;
        }
        $destination = dh_visit_referral_destination($visit, $doctors);
        if ($destination <= 0 || !isset($doctors[$destination])) {
            $pending += $count;
            continue;
        }
        if ($destination === $sourceId) {
            $self += $count;
            continue;
        }
        if (!isset($visibleDoctors[$destination])) {
            $excluded += $count;
            continue;
        }
        $designation = trim((string) ($visit['referring_doctor_designation'] ?? ''));
        $key = $examinerId > 0 ? 'doctor' : 'doctor_' . $sourceId;
        $name = $doctors[$sourceId];
        $type = 'doctor';
        if ($designation === 'PATIENT') {
            $key = 'patient';
            $name = 'نەخۆش بەخۆی دیاریکرد';
            $type = 'patient';
        } elseif ($designation === 'DOCTORS') {
            $staff = (int) ($visit['appointment_added_by'] ?? 0);
            $key = 'staff_' . $staff;
            $name = $users[$staff] ?? ($staff > 0 ? 'کارمەند #' . $staff : 'کارمەند دیاری نەکراوە');
            $type = 'receptionist';
        } elseif ($designation === '' || $designation === '0') {
            $key = 'unknown';
            $name = 'دیاریکەر تۆمار نەکراوە';
            $type = 'unknown';
        }
        if (!isset($rows[$key])) {
            $rows[$key] = ['name' => $name, 'type' => $type, 'doctors' => [], 'total' => 0];
        }
        $rows[$key]['doctors'][$destination] = ($rows[$key]['doctors'][$destination] ?? 0) + $count;
        $rows[$key]['total'] += $count;
        $totals[$destination] = ($totals[$destination] ?? 0) + $count;
    }
    if (!isset($rows['patient'])) {
        $rows['patient'] = ['name' => 'نەخۆش بەخۆی دیاریکرد', 'type' => 'patient', 'doctors' => [], 'total' => 0];
    }
    $order = ['doctor' => 0, 'receptionist' => 1, 'patient' => 2, 'unknown' => 3];
    uasort($rows, static function (array $a, array $b) use ($order): int {
        return ($order[$a['type']] <=> $order[$b['type']]) ?: strcmp($a['name'], $b['name']);
    });
    // Keep active receiving doctors visible even when their count is zero.
    // Historical referrals also retain their receiving doctor's column.
    $columnIds = $activeDoctorIds === null ? array_keys($visibleDoctors) : array_merge($activeDoctorIds, array_keys($totals));
    $destinations = array_intersect_key($visibleDoctors, array_fill_keys($columnIds, true));
    unset($destinations[$examinerId]);
    return [
        'examiner_id' => $examinerId,
        'examiner_name' => $examinerId === 0 ? 'هەموو دکتۆرە پشکنەرەکان' : ($doctors[$examinerId] ?? 'دکتۆر دیاری نەکراوە'),
        'doctors' => $destinations,
        'rows' => $rows,
        'column_totals' => array_replace(array_fill_keys(array_keys($destinations), 0), $totals),
        'total' => array_sum($totals),
        'pending' => $pending,
        'self' => $self,
        'excluded' => $excluded,
    ];
}

function dh_visit_referral_report(Database $db, array $filters): array
{
    $doctorRows = $db->fetchAll("SELECT d.id, d.name, d.is_active, u.role FROM doctors d LEFT JOIN users u ON u.id=d.user_id ORDER BY d.name");
    $doctors = [];
    $activeDoctorIds = [];
    foreach ($doctorRows as $doctor) {
        if (($doctor['role'] ?? '') !== 'xray_department' && strtolower(trim($doctor['name'])) !== 'x.ray') {
            $doctors[(int) $doctor['id']] = $doctor['name'];
            if ((int) $doctor['is_active'] === 1) {
                $activeDoctorIds[] = (int) $doctor['id'];
            }
        }
    }
    $examiners = [0 => 'هەموو دکتۆرە پشکنەرەکان'] + dh_visit_report_visible_doctors($doctors);
    $examinerId = (int) ($filters['examiner_id'] ?? 0);
    if (!isset($examiners[$examinerId])) {
        $examinerId = 0;
    }
    $dateColumn = ($filters['date_filter'] ?? '') === 'appointment_date' ? 'v.appointment_date' : 'v.visit_date';
    $parameters = ['from' => $filters['from'], 'to' => $filters['to']];
    $examinerCondition = "TRIM(v.referring_doctor_id) REGEXP '^[0-9]+$' AND CAST(v.referring_doctor_id AS UNSIGNED)>0";
    if ($examinerId > 0) {
        $examinerCondition = 'v.referring_doctor_id=:examiner';
        $parameters['examiner'] = (string) $examinerId;
    }
    $visits = $db->fetchAll(
        "SELECT v.id, v.referring_doctor_id, v.referring_doctor_designation,
                v.referring_doctor_choice_id, v.appointment_added_by, v.appointment_doctor_id
         FROM visits v
         WHERE {$dateColumn} BETWEEN :from AND :to AND v.visit_type='checkup'
           AND {$examinerCondition}
         ORDER BY v.id",
        $parameters
    );
    $users = [];
    foreach ($db->fetchAll('SELECT id, full_name FROM users') as $user) {
        $users[(int) $user['id']] = $user['full_name'];
    }
    return dh_visit_referral_matrix($visits, $examinerId, $doctors, $users, $activeDoctorIds) + ['examiners' => $examiners];
}

/** Shared image-style output for the second tab and its PDF. */
function dh_visit_referral_render(array $report, string $period, string $preparedBy): string
{
    $escape = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
    $doctorColumns = max(1, count($report['doctors']));
    $doctorWidth = 52 / $doctorColumns;
    $html = '<div class="vr-referral-report" dir="rtl">';
    $html .= '<p class="vr-referral-guide">ڕیزەکان: کەسی دیاریکەر / ناردەر. ستوونەکان: دکتۆری وەرگر.</p>';
    $title = $report['examiner_id'] === 0
        ? 'نەخۆشە پشکنراوەکان کە بۆ ئیش نێردراون بۆ دکتۆرەکان — هەموو دکتۆرە پشکنەرەکان'
        : 'ئەوانەی ' . $report['examiner_name'] . ' پشکنینی کردوون و بۆ ئیش نێردراون بۆ دکتۆرەکان';
    $html .= '<table class="vr-photo-table vr-referral-table vr-referral-overview" data-report-table="summary" aria-label="پوختەی ناردن بۆ دکتۆرەکان" dir="rtl">';
    $html .= '<colgroup><col span="' . $doctorColumns . '" style="width:' . $doctorWidth . '%"><col style="width:48%"></colgroup>';
    $html .= '<thead><tr><th colspan="' . ($doctorColumns + 1) . '" class="vr-photo-title">' . $escape($title) . '<br><span class="vr-referral-period" dir="ltr">&#8206;' . $escape($period) . '&#8206;</span></th></tr></thead><tbody><tr>';
    foreach ($report['doctors'] as $name) { $html .= '<th scope="col">' . $escape($name) . '</th>'; }
    if (empty($report['doctors'])) { $html .= '<th scope="col">دکتۆری وەرگر</th>'; }
    $html .= '<td rowspan="2" class="vr-photo-total vr-referral-big-total"><span class="vr-referral-total-label">کۆی گشتی</span>' . number_format($report['total']) . '</td></tr><tr class="vr-referral-summary">';
    foreach ($report['doctors'] as $id => $_name) { $html .= '<td>' . number_format($report['column_totals'][$id]) . '</td>'; }
    if (empty($report['doctors'])) { $html .= '<td>0</td>'; }
    $html .= '</tr></tbody></table>';

    $html .= '<table class="vr-photo-table vr-referral-table vr-referral-breakdown" data-report-table="distribution" aria-label="ئاماری چۆنیەتی دابەشکردن" dir="rtl">';
    $html .= '<colgroup><col style="width:16%">';
    if (!empty($report['doctors'])) { $html .= '<col span="' . count($report['doctors']) . '" style="width:' . $doctorWidth . '%">'; }
    $html .= '<col style="width:32%"></colgroup>';
    $html .= '<thead><tr><th scope="col">دیاریکەر / ناردەر</th>';
    foreach ($report['doctors'] as $name) { $html .= '<th scope="col">' . $escape($name) . '</th>'; }
    $html .= '<th scope="col" class="vr-photo-total">کۆی گشتی</th></tr></thead><tbody>';
    foreach ($report['rows'] as $row) {
        $html .= '<tr><th scope="row">' . $escape($row['name']) . '</th>';
        foreach ($report['doctors'] as $id => $_name) { $html .= '<td>' . number_format($row['doctors'][$id] ?? 0) . '</td>'; }
        $html .= '<td class="vr-photo-total">' . number_format($row['total']) . '</td></tr>';
    }
    $html .= '<tr class="vr-photo-grand"><th scope="row">کۆی گشتی</th>';
    foreach ($report['doctors'] as $id => $_name) { $html .= '<td>' . number_format($report['column_totals'][$id]) . '</td>'; }
    $html .= '<td class="vr-photo-total">' . number_format($report['total']) . '</td></tr></tbody></table></div>';
    $html .= '<div class="vr-photo-note"><div>' . implode(' + ', array_map('number_format', array_values($report['column_totals']))) . (empty($report['doctors']) ? '0' : '') . ' = ' . number_format($report['total']) . '</div><p>ئامار بە پێی تۆماری ناردن بۆ ئیشە. هەر سەردانێک تەنها جارێک بۆ دکتۆری وەرگر و دیاریکەر هەژمار دەکرێت.</p>';
    $html .= '<p>0 لە ستوونی دکتۆر واتە لەم ماوەیەدا هیچ ناردنێک بۆ ئەو دکتۆرە تۆمار نەکراوە. ناوی دکتۆر لە ڕیزدا واتە ئەو دکتۆرە وەرگری دیاری کردووە.</p>';
    if ($report['pending'] > 0) { $html .= '<p>' . number_format($report['pending']) . ' سەردان هێشتا دکتۆری وەرگریان دیاری نەکراوە.</p>'; }
    if ($report['self'] > 0) { $html .= '<p>' . number_format($report['self']) . ' سەردان لای دکتۆری پشکنەر خۆی دیاریکراون و لە کۆی ناردن بۆ دکتۆرەکانی تر هەژمار نەکراون.</p>'; }
    $html .= '</div><div class="vr-referral-prepared">ئامادەکار: ' . $escape($preparedBy) . '</div>';
    return $html;
}
