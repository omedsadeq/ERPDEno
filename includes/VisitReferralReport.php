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
    // the visits behind every number, for the details dialog (row key => destination => ids)
    $cellIds = [];
    $pendingIds = $selfIds = [];
    $visibleDoctors = dh_visit_report_visible_doctors($doctors);
    foreach ($visits as $visit) {
        $source = trim((string) ($visit['referring_doctor_id'] ?? ''));
        if ($examinerId > 0 && $source !== (string) $examinerId) {
            continue;
        }
        $id = (int) ($visit['id'] ?? 0);
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
            if ($id) { $pendingIds[] = $id; }
            continue;
        }
        if ($destination === $sourceId) {
            $self += $count;
            if ($id) { $selfIds[] = $id; }
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
        if ($id) { $cellIds[$key][$destination][] = $id; }
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
        'cell_ids' => $cellIds,
        'pending_ids' => $pendingIds,
        'self_ids' => $selfIds,
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

/**
 * Shared image-style output for the second tab and its PDF. With $details (the
 * page, not the PDF) every number above zero carries a button that lists the
 * visits behind it (visits-report-detail.php?report=referrals).
 */
function dh_visit_referral_render(array $report, string $period, string $preparedBy, bool $details = false): string
{
    $escape = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
    $num = static function ($count, string $row, $doc) use ($details, $escape): string {
        $text = number_format((int) $count);
        if (!$details || (int) $count <= 0) {
            return $text;
        }
        return '<span class="vr-cell"><button type="button" class="vr-xbtn" data-vr-report="referrals" data-vr-row="' . $escape($row) . '" data-vr-doc="' . $escape((string) $doc) . '"'
            . ' title="ئەو سەردانانەی ئەم ژمارەیە" aria-label="ئەو سەردانانەی ئەم ژمارەیە"><i class="fas fa-layer-group"></i></button><span>' . $text . '</span></span>';
    };
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
    $html .= '<td rowspan="2" class="vr-photo-total vr-referral-big-total"><span class="vr-referral-total-label">کۆی گشتی</span>' . $num($report['total'], 'all', 'all') . '</td></tr><tr class="vr-referral-summary">';
    foreach ($report['doctors'] as $id => $_name) { $html .= '<td>' . $num($report['column_totals'][$id], 'all', $id) . '</td>'; }
    if (empty($report['doctors'])) { $html .= '<td>0</td>'; }
    $html .= '</tr></tbody></table>';

    $html .= '<table class="vr-photo-table vr-referral-table vr-referral-breakdown" data-report-table="distribution" aria-label="ئاماری چۆنیەتی دابەشکردن" dir="rtl">';
    $html .= '<colgroup><col style="width:16%">';
    if (!empty($report['doctors'])) { $html .= '<col span="' . count($report['doctors']) . '" style="width:' . $doctorWidth . '%">'; }
    $html .= '<col style="width:32%"></colgroup>';
    $html .= '<thead><tr><th scope="col">دیاریکەر / ناردەر</th>';
    foreach ($report['doctors'] as $name) { $html .= '<th scope="col">' . $escape($name) . '</th>'; }
    $html .= '<th scope="col" class="vr-photo-total">کۆی گشتی</th></tr></thead><tbody>';
    foreach ($report['rows'] as $key => $row) {
        $html .= '<tr><th scope="row">' . $escape($row['name']) . '</th>';
        foreach ($report['doctors'] as $id => $_name) { $html .= '<td>' . $num($row['doctors'][$id] ?? 0, (string) $key, $id) . '</td>'; }
        $html .= '<td class="vr-photo-total">' . $num($row['total'], (string) $key, 'all') . '</td></tr>';
    }
    $html .= '<tr class="vr-photo-grand"><th scope="row">کۆی گشتی</th>';
    foreach ($report['doctors'] as $id => $_name) { $html .= '<td>' . $num($report['column_totals'][$id], 'all', $id) . '</td>'; }
    $html .= '<td class="vr-photo-total">' . $num($report['total'], 'all', 'all') . '</td></tr></tbody></table></div>';
    $html .= '<div class="vr-photo-note"><div>' . implode(' + ', array_map('number_format', array_values($report['column_totals']))) . (empty($report['doctors']) ? '0' : '') . ' = ' . number_format($report['total']) . '</div><p>ئامار بە پێی تۆماری ناردن بۆ ئیشە. هەر سەردانێک تەنها جارێک بۆ دکتۆری وەرگر و دیاریکەر هەژمار دەکرێت.</p>';
    $html .= '<p>0 لە ستوونی دکتۆر واتە لەم ماوەیەدا هیچ ناردنێک بۆ ئەو دکتۆرە تۆمار نەکراوە. ناوی دکتۆر لە ڕیزدا واتە ئەو دکتۆرە وەرگری دیاری کردووە.</p>';
    if ($report['pending'] > 0) { $html .= '<p>' . $num($report['pending'], 'pending', 'all') . ' سەردان هێشتا دکتۆری وەرگریان دیاری نەکراوە.</p>'; }
    if ($report['self'] > 0) { $html .= '<p>' . $num($report['self'], 'self', 'all') . ' سەردان لای دکتۆری پشکنەر خۆی دیاریکراون و لە کۆی ناردن بۆ دکتۆرەکانی تر هەژمار نەکراون.</p>'; }
    $html .= '</div><div class="vr-referral-prepared">ئامادەکار: ' . $escape($preparedBy) . '</div>';
    return $html;
}

/**
 * The visits behind one number of the referral report: row is a row key of the
 * report (doctor, doctor_<id>, staff_<id>, patient, unknown), 'all' (کۆی گشتی),
 * 'pending' or 'self'; doc is a receiving doctor's id or 'all'.
 * Returns ['title' => ..., 'ids' => [...]] or null for a cell the report has not.
 */
function dh_visit_referral_cell(array $report, string $row, string $doc): ?array
{
    if ($doc !== 'all' && !ctype_digit($doc)) {
        return null;
    }
    $docId = $doc === 'all' ? null : (int) $doc;
    $docName = $docId === null ? 'هەموو دکتۆرەکان' : ($report['doctors'][$docId] ?? ('دکتۆر #' . $docId));
    if ($row === 'pending') {
        return ['title' => 'دکتۆری وەرگر دیاری نەکراوە', 'ids' => $report['pending_ids']];
    }
    if ($row === 'self') {
        return ['title' => 'لای دکتۆری پشکنەر خۆی', 'ids' => $report['self_ids']];
    }
    if ($row === 'all') {
        $rows = $report['cell_ids'];
        $rowName = 'کۆی گشتی';
    } elseif (isset($report['rows'][$row])) {
        $rows = [$row => $report['cell_ids'][$row] ?? []];
        $rowName = $report['rows'][$row]['name'];
    } else {
        return null;
    }
    $ids = [];
    foreach ($rows as $byDoctor) {
        foreach ($byDoctor as $destination => $list) {
            if ($docId === null || (int) $destination === $docId) {
                array_push($ids, ...$list);
            }
        }
    }
    return ['title' => $rowName . ' ← ' . $docName, 'ids' => $ids];
}

/** The dialog's body for a referral number: the visits, the examiner, who chose and the receiving doctor. */
function dh_visit_referral_detail_body(Database $db, array $ids, array $filters): string
{
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $from = $filters['from'];
    $to = $filters['to'];
    $byAppointment = ($filters['date_filter'] ?? '') === 'appointment_date';
    $html = '<div class="vrd-sum"><b>' . number_format(count($ids)) . '</b> سەردان · <span dir="ltr">' . $e($from) . ($from !== $to ? ' — ' . $e($to) : '') . '</span></div>';
    if (!$ids) {
        return $html . '<div class="vrd-empty">هیچ سەردانێک نییە</div>';
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $visits = $db->fetchAll(
        "SELECT v.id, v.visit_date, v.appointment_date, v.arrival_time, v.patient_id, COALESCE(NULLIF(p.full_name, ''), v.patient_name) AS patient,
                v.mobile_number, v.referring_doctor_id, v.referring_doctor_designation, v.referring_doctor_choice_id,
                v.appointment_added_by, v.appointment_doctor_id, v.procedure_treatment, v.referral_source
         FROM visits v LEFT JOIN patients p ON p.id = v.patient_id
         WHERE v.id IN ($marks)
         ORDER BY " . ($byAppointment ? 'v.appointment_date' : 'v.visit_date') . ", v.arrival_time, v.id",
        $ids
    );
    $doctors = [];
    foreach ($db->fetchAll('SELECT id, name FROM doctors') as $d) { $doctors[(int) $d['id']] = $d['name']; }
    $users = [];
    foreach ($db->fetchAll('SELECT id, full_name FROM users') as $u) { $users[(int) $u['id']] = $u['full_name']; }
    $app = APP_URL;
    $html .= '<input type="search" class="vrd-search" placeholder="گەڕان: ناو، مۆبایل، دکتۆر، کارمەند…" aria-label="گەڕان لە سەردانەکان">'
        . '<div class="vrd-scroll"><table class="vrd-tbl"><thead><tr><th>#</th><th>' . ($byAppointment ? 'بەرواری مەوعید' : 'بەرواری سەردان') . '</th><th>کات</th><th>نەخۆش</th><th>مۆبایل</th>'
        . '<th>دکتۆری پشکنەر</th><th>دیاریکەر / ناردەر</th><th>دکتۆری وەرگر</th><th>ئیش / سەرچاوە</th><th></th></tr></thead><tbody>';
    foreach ($visits as $i => $v) {
        $examiner = (int) $v['referring_doctor_id'];
        $destination = dh_visit_referral_destination($v, $doctors);
        $designation = trim((string) ($v['referring_doctor_designation'] ?? ''));
        if ($designation === 'PATIENT') {
            $chooser = '<span class="vrd-tag s-old">نەخۆش بەخۆی</span>';
        } elseif ($designation === 'DOCTORS') {
            $staff = (int) ($v['appointment_added_by'] ?? 0);
            $chooser = '<span class="vrd-tag a-reception">' . $e($users[$staff] ?? 'کارمەند دیاری نەکراوە') . '</span>';
        } elseif ($designation === '' || $designation === '0') {
            $chooser = '<span class="vrd-tag a-unassigned">تۆمار نەکراوە</span>';
        } else {
            $chooser = '<span class="vrd-tag a-requested">دکتۆری پشکنەر</span>';
        }
        $note = trim((string) ($v['procedure_treatment'] ?? '')) ?: trim((string) ($v['referral_source'] ?? ''));
        $links = '<a href="' . $e($app . '/edit_visit.php?id=' . (int) $v['id']) . '" target="_blank" rel="noopener" title="سەردان"><i class="fas fa-pen"></i></a>';
        if (!empty($v['patient_id'])) {
            $links .= '<a href="' . $e($app . '/patients/view.php?id=' . (int) $v['patient_id']) . '" target="_blank" rel="noopener" title="نەخۆش"><i class="fas fa-user"></i></a>';
        }
        $html .= '<tr><td class="n">' . ($i + 1) . '</td><td class="d">' . $e($byAppointment ? $v['appointment_date'] : $v['visit_date']) . '</td>'
            . '<td class="d">' . $e($v['arrival_time'] ? substr((string) $v['arrival_time'], 0, 5) : '') . '</td>'
            . '<td class="p">' . $e($v['patient'] ?: '—') . '</td><td class="d">' . $e($v['mobile_number'] ?? '') . '</td>'
            . '<td>' . $e($doctors[$examiner] ?? '—') . '</td><td>' . $chooser . '</td>'
            . '<td>' . $e($destination > 0 ? ($doctors[$destination] ?? '—') : 'دیاری نەکراوە') . '</td>'
            . '<td class="w">' . $e(mb_strimwidth($note, 0, 80, '…')) . '</td><td class="l">' . $links . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}
