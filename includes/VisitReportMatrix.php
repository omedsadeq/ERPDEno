<?php

/**
 * Where a visit (or a group of like visits) counts: its doctor column, its
 * allocation row (requested | reception | other | unassigned), its patient
 * row (new | old | unknown) and the reception staff it is credited to. The
 * matrix and the list behind each of its cells (VisitReportDetail.php) both
 * use this, so a cell's list is always exactly the visits it counts.
 */
function dh_visit_report_classify(array $group): array
{
    $id = (int) $group['doctor_id'];
    $requestedId = (int) ($group['requested_doctor_id'] ?? 0);
    $assignedId = (int) ($group['assigned_doctor_id'] ?? 0);
    $staffId = (int) ($group['receptionist_id'] ?? 0);
    // An explicit reception choice also covers patients with no preferred
    // doctor. Older rows retain the established doctor-change rule.
    $isReceptionTransfer = $staffId > 0 && (
        (($group['doctor_assignment_source'] ?? null) === 'reception' && $id > 0)
        || ($requestedId > 0 && $assignedId > 0 && $requestedId !== $assignedId)
    );
    return [
        'doctor' => $id,
        'allocation' => $id === 0 ? 'unassigned' : ($isReceptionTransfer ? 'reception' : ($requestedId === $id ? 'requested' : 'other')),
        'status' => in_array($group['patient_status'] ?? '', ['new', 'old'], true) ? $group['patient_status'] : 'unknown',
        'staff' => $staffId,
    ];
}

/** Each grouped visit contributes once to one doctor and one allocation row. */
function dh_visit_report_build_matrix(array $groups, array $doctors, array $users): array
{
    $stats = [];
    $reception = [];
    foreach ($groups as $group) {
        $doctorId = (int) $group['doctor_id'];
        if (!isset($doctors[$doctorId])) {
            $doctors[$doctorId] = $doctorId > 0 ? 'دکتۆر #' . $doctorId : 'دکتۆر دیاری نەکراوە';
        }
    }
    $empty = ['requested' => 0, 'reception' => 0, 'other' => 0, 'unassigned' => 0, 'assigned' => 0, 'new' => 0, 'old' => 0, 'unknown' => 0];
    foreach ($doctors as $id => $name) {
        $stats[$id] = ['name' => $name] + $empty;
    }
    $totals = $empty;
    foreach ($groups as $group) {
        ['doctor' => $id, 'allocation' => $allocation, 'status' => $status, 'staff' => $staffId] = dh_visit_report_classify($group);
        $count = (int) $group['count'];
        foreach ([$allocation, 'assigned', $status] as $key) {
            $stats[$id][$key] += $count;
            $totals[$key] += $count;
        }
        if ($allocation === 'reception') {
            if (!isset($reception[$staffId])) {
                $reception[$staffId] = [
                    'name' => $users[$staffId] ?? ($staffId > 0 ? 'کارمەند #' . $staffId : 'کارمەند دیاری نەکراوە'),
                    'doctors' => [],
                    'total' => 0,
                ];
            }
            $reception[$staffId]['doctors'][$id] = ($reception[$staffId]['doctors'][$id] ?? 0) + $count;
            $reception[$staffId]['total'] += $count;
        }
    }
    uasort($reception, static function (array $a, array $b): int {
        return strcmp($a['name'], $b['name']);
    });
    return [
        'doctors' => $doctors,
        'doctor_stats' => $stats,
        'doctor_totals' => $totals,
        'overall' => ['total' => $totals['assigned'], 'new_total' => $totals['new'], 'old_total' => $totals['old'], 'unknown_total' => $totals['unknown']],
        'receptionist_redirections' => $reception,
        'receptionist_redirection_total' => $totals['reception'],
        'receptionist_redirection_column_totals' => dh_visit_report_matrix_totals($reception, $doctors),
    ];
}

/** Hide the clinic placeholder from report columns without changing totals. */
function dh_visit_report_visible_doctors(array $doctors): array
{
    return array_filter($doctors, static function ($name): bool {
        return trim((string) $name) !== 'لێزان';
    });
}

/**
 * One renderer keeps the photo-style table identical on screen and in PDF.
 * With $details (the page, not the PDF) every cell above zero carries a button
 * that lists the visits behind it (visits-report-detail.php).
 */
function dh_visit_report_render_matrix(array $stats, string $period, string $visitTypeLabel, bool $details = false): string
{
    $doctors = dh_visit_report_visible_doctors($stats['doctors']);
    $escape = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
    $cell = static function ($count, string $row, $doc) use ($details, $escape): string {
        $text = number_format((int) $count);
        if (!$details || (int) $count <= 0) {
            return $text;
        }
        return '<span class="vr-cell"><button type="button" class="vr-xbtn" data-vr-row="' . $escape($row) . '" data-vr-doc="' . $escape((string) $doc) . '"'
            . ' title="ئەو سەردانانەی ئەم ژمارەیە" aria-label="ئەو سەردانانەی ئەم ژمارەیە"><i class="fas fa-layer-group"></i></button><span>' . $text . '</span></span>';
    };
    $html = '<table class="vr-photo-table" dir="rtl"><thead><tr><th class="vr-photo-title" colspan="' . (count($doctors) + 2) . '">ئاماری سەردانەکان — <span dir="ltr">&#8206;' . $escape($period) . '&#8206;</span> (' . $escape($visitTypeLabel) . ')</th></tr>';
    $heading = '<tr><th scope="col">جۆری سەردان</th>';
    foreach ($doctors as $name) {
        $heading .= '<th scope="col">' . $escape($name) . '</th>';
    }
    $heading .= '<th scope="col" class="vr-photo-total">کۆی گشتی</th></tr>';
    $html .= $heading . '</thead><tbody>';
    // ئیش و پشکنین: first every patient each doctor saw, then the checkups split below
    $both = isset($stats['patients']);
    if ($both) {
        $split = static function (array $p): string {
            // two lines, its own size: the PDF wraps a mixed one badly and gives it the total's big type
            return '<div class="vr-pt-split" style="font-size:11px;font-weight:600;color:#475569;">ئیش ' . number_format($p['treatment'])
                . '<br>پشکنین ' . number_format($p['checkup']) . '</div>';
        };
        $html .= '<tr class="vr-photo-patients"><th scope="row">نەخۆشەکان<div class="vr-pt-split">ئیش + پشکنین</div></th>';
        foreach ($doctors as $id => $_name) {
            $p = $stats['patients'][$id] ?? ['treatment' => 0, 'checkup' => 0, 'total' => 0];
            $html .= '<td>' . $cell($p['total'], 'patients', $id) . $split($p) . '</td>';
        }
        $html .= '<td class="vr-photo-total">' . $cell($stats['patients_total']['total'], 'patients', 'all') . $split($stats['patients_total']) . '</td></tr>';
    }
    $rows = ['requested' => 'داواکراو', 'reception' => 'گواستنەوەی پێشوازی'];
    if ($stats['doctor_totals']['other'] > 0) {
        $rows['other'] = 'دیاریکردنی تر';
    }
    if ($stats['doctor_totals']['unassigned'] > 0) {
        $rows['unassigned'] = 'دکتۆر دیاری نەکراوە';
    }
    $rows += ['assigned' => $both ? 'کۆی پشکنین' : 'کۆی گشتی', 'new' => 'نوێ', 'old' => 'کۆن'];
    if ($stats['doctor_totals']['unknown'] > 0) {
        $rows['unknown'] = 'دۆخی نەخۆش نادیارە';
    }
    foreach ($rows as $key => $label) {
        $html .= '<tr' . ($key === 'assigned' ? ' class="vr-photo-grand"' : '') . '><th scope="row">' . $label . '</th>';
        foreach ($doctors as $id => $_name) {
            $html .= '<td>' . $cell($stats['doctor_stats'][$id][$key] ?? 0, $key, $id) . '</td>';
        }
        $html .= '<td class="vr-photo-total">' . $cell($stats['doctor_totals'][$key], $key, 'all') . '</td></tr>';
    }
    $html .= '<tr><th class="vr-photo-section" colspan="' . (count($doctors) + 2) . '">گواستنەوەی پێشوازی — دابەشکردنی نەخۆشەکان بۆ دکتۆرەکان</th></tr>';
    $html .= str_replace('جۆری سەردان', 'کارمەندی پێشوازی', $heading);
    foreach ($stats['receptionist_redirections'] as $staffId => $row) {
        $html .= '<tr><th scope="row">' . $escape($row['name']) . '</th>';
        foreach ($doctors as $id => $_name) {
            $html .= '<td>' . $cell($row['doctors'][$id] ?? 0, 'r:' . $staffId, $id) . '</td>';
        }
        $html .= '<td class="vr-photo-total">' . $cell($row['total'], 'r:' . $staffId, 'all') . '</td></tr>';
    }
    if (empty($stats['receptionist_redirections'])) {
        $html .= '<tr><td colspan="' . (count($doctors) + 2) . '">هیچ دابەشکردنێکی پێشوازی نییە</td></tr>';
    }
    $html .= '<tr class="vr-photo-grand"><th scope="row">کۆی گواستنەوەی پێشوازی</th>';
    foreach ($doctors as $id => $_name) {
        $html .= '<td>' . $cell($stats['receptionist_redirection_column_totals'][$id] ?? 0, 'reception', $id) . '</td>';
    }
    return $html . '<td class="vr-photo-total">' . $cell($stats['receptionist_redirection_total'], 'reception', 'all') . '</td></tr></tbody></table>';
}

function dh_visit_report_matrix_note(array $stats): string
{
    $totals = $stats['doctor_totals'];
    $patients = isset($stats['patients_total'])
        ? '<div>نەخۆشەکان: ئیش (' . number_format($stats['patients_total']['treatment']) . ') + پشکنین (' . number_format($stats['patients_total']['checkup'])
            . ') = ' . number_format($stats['patients_total']['total']) . '. ڕیزەکانی خوارەوەی تەنها پشکنینەکان دابەش دەکەن.</div>'
        : '';
    $other = $totals['other'] > 0 ? ' + دیاریکردنی تر (' . number_format($totals['other']) . ')' : '';
    $extra = $totals['unassigned'] > 0 ? ' + دیاری نەکراو (' . number_format($totals['unassigned']) . ')' : '';
    $unknown = $totals['unknown'] > 0 ? ' + نادیار (' . number_format($totals['unknown']) . ')' : '';
    return '<div class="vr-photo-note">' . $patients
        . '<div>داواکراو (' . number_format($totals['requested']) . ') + گواستنەوەی پێشوازی (' . number_format($totals['reception']) . ')' . $other . $extra . ' = کۆی گشتی (' . number_format($totals['assigned']) . ')</div>'
        . '<div>نوێ (' . number_format($totals['new']) . ') + کۆن (' . number_format($totals['old']) . ')' . $unknown . ' = کۆی گشتی (' . number_format($totals['assigned']) . ')</div>'
        . '<p>داواکراو: نەخۆش لای دکتۆری داواکراوە. گواستنەوەی پێشوازی: پێشوازی هەڵبژاردنی دکتۆری تۆمار کردووە، یان دکتۆری داواکراو و بینراو جیاوازن و کارمەندی پێشوازی تۆمارکراوە. ژمارەکە بۆ دکتۆری بینراو و ئەو کارمەندە هەژمار دەکرێت. دیاریکردنی تر: دکتۆر دیاریکراوە، بەڵام چۆنیەتی دیاریکردنەکە تۆمار نەکراوە. ئەگەر دکتۆری بینراو بەتاڵ بێت، دکتۆری پشکنین/ئیش و دواتر دکتۆری داواکراو بەکاردێت. ژمارەکان ژمارەی سەردانن؛ هەر سەردانێک تەنها جارێک هەژمار دەکرێت.</p>'
        . '</div>';
}
