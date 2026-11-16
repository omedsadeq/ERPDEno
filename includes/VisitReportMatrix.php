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

require_once __DIR__ . '/../classes/Settings.php';

/** The setting that holds the doctor columns' order, as dragged on visits-report.php. */
const DH_VISIT_REPORT_COLUMN_ORDER = 'visits_report_column_order';

/**
 * Hide the clinic placeholder from report columns without changing totals, and
 * put the columns in the order the clinic set by dragging them on the report
 * (the screen, the PDF and the referral report all follow it). A column not in
 * that order yet (a new doctor) comes after the ordered ones, as before.
 */
function dh_visit_report_visible_doctors(array $doctors): array
{
    $visible = array_filter($doctors, static function ($name): bool {
        return trim((string) $name) !== 'لێزان';
    });
    $order = dh_visit_report_column_order();
    if (!$order) {
        return $visible;
    }
    $rank = array_flip($order);
    $pos = array_flip(array_map('strval', array_keys($visible)));
    uksort($visible, static function ($a, $b) use ($rank, $pos): int {
        $ra = $rank[(string) $a] ?? PHP_INT_MAX;
        $rb = $rank[(string) $b] ?? PHP_INT_MAX;
        return $ra === $rb ? $pos[(string) $a] <=> $pos[(string) $b] : $ra <=> $rb;
    });
    return $visible;
}

/** The saved column order: doctor column ids as strings, first to last. */
function dh_visit_report_column_order(): array
{
    if (!defined('DB_HOST')) {
        return [];   // no database configured (a bare script): the natural order
    }
    try {
        $raw = (string) Settings::get(DH_VISIT_REPORT_COLUMN_ORDER, '');
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_filter(explode(',', $raw), static fn($id): bool => preg_match('/^-?\d+$/', $id) === 1));
}

/** Save the order the columns were dragged into (ids, first to last). */
function dh_visit_report_save_column_order(array $ids): void
{
    $clean = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if (preg_match('/^-?\d{1,10}$/', $id) && !in_array($id, $clean, true)) {
            $clean[] = $id;
        }
    }
    // A table may show only some of the columns (the referral report): those
    // take the places they held in the saved order, in their new order; the
    // others stay where they were.
    $full = dh_visit_report_column_order();
    foreach ($clean as $id) {
        if (!in_array($id, $full, true)) {
            $full[] = $id;
        }
    }
    $moved = $clean;
    foreach ($full as $i => $id) {
        if (in_array($id, $clean, true)) {
            $full[$i] = array_shift($moved);
        }
    }
    Settings::set(DH_VISIT_REPORT_COLUMN_ORDER, implode(',', array_slice($full, 0, 200)), 'text',
        'ڕاپۆرتی سەردان: ڕیزبەندی ستوونی دکتۆرەکان');
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
    foreach ($doctors as $id => $name) {
        // on the page a doctor column can be dragged to another place (visits-report.php)
        $heading .= '<th scope="col" data-vr-col="' . $escape((string) $id) . '"'
            . ($details ? ' draggable="true" title="بۆ گۆڕینی شوێن، ڕایبکێشە"' : '') . '>' . $escape($name) . '</th>';
    }
    $heading .= '<th scope="col" class="vr-photo-total">کۆی گشتی</th></tr>';
    $html .= $heading . '</thead><tbody>';
    // ئیش و پشکنین: first every patient each doctor saw, in three rows — all of
    // them, the ئیش, the پشکنین — then the checkups split below
    $both = isset($stats['patients']);
    if ($both) {
        $patientRows = [
            ['total', 'patients', 'کۆی سەردانیکەران', 'vr-photo-patients vr-pt-first'],
            ['treatment', 'patients_treatment', 'کۆی ئیش', 'vr-photo-patients vr-pt-part'],
            ['checkup', 'patients_checkup', 'کۆی پشکنین', 'vr-photo-patients vr-pt-part vr-pt-end'],
        ];
        foreach ($patientRows as [$part, $rowKey, $label, $class]) {
            $html .= '<tr class="' . $class . '"><th scope="row">' . $label . '</th>';
            foreach ($doctors as $id => $_name) {
                $html .= '<td data-vr-col="' . $escape((string) $id) . '">' . $cell($stats['patients'][$id][$part] ?? 0, $rowKey, $id) . '</td>';
            }
            $html .= '<td class="vr-photo-total">' . $cell($stats['patients_total'][$part], $rowKey, 'all') . '</td></tr>';
        }
    }
    $rows = ['requested' => 'داواکراو', 'reception' => 'گواستنەوەی پێشوازی'];
    if ($stats['doctor_totals']['other'] > 0) {
        $rows['other'] = 'دیاریکردنی تر';
    }
    if ($stats['doctor_totals']['unassigned'] > 0) {
        $rows['unassigned'] = 'دکتۆر دیاری نەکراوە';
    }
    // with both types the checkups' total is already the «کۆی پشکنین» row at the top
    $rows += ($both ? [] : ['assigned' => 'کۆی گشتی']) + ['new' => 'نوێ', 'old' => 'کۆن'];
    if ($stats['doctor_totals']['unknown'] > 0) {
        $rows['unknown'] = 'دۆخی نەخۆش نادیارە';
    }
    foreach ($rows as $key => $label) {
        $html .= '<tr' . ($key === 'assigned' ? ' class="vr-photo-grand"' : '') . '><th scope="row">' . $label . '</th>';
        foreach ($doctors as $id => $_name) {
            $html .= '<td data-vr-col="' . $escape((string) $id) . '">' . $cell($stats['doctor_stats'][$id][$key] ?? 0, $key, $id) . '</td>';
        }
        $html .= '<td class="vr-photo-total">' . $cell($stats['doctor_totals'][$key], $key, 'all') . '</td></tr>';
    }
    $html .= '<tr><th class="vr-photo-section" colspan="' . (count($doctors) + 2) . '">گواستنەوەی پێشوازی — دابەشکردنی نەخۆشەکان بۆ دکتۆرەکان</th></tr>';
    $html .= str_replace('جۆری سەردان', 'کارمەندی پێشوازی', $heading);
    foreach ($stats['receptionist_redirections'] as $staffId => $row) {
        $html .= '<tr><th scope="row">' . $escape($row['name']) . '</th>';
        foreach ($doctors as $id => $_name) {
            $html .= '<td data-vr-col="' . $escape((string) $id) . '">' . $cell($row['doctors'][$id] ?? 0, 'r:' . $staffId, $id) . '</td>';
        }
        $html .= '<td class="vr-photo-total">' . $cell($row['total'], 'r:' . $staffId, 'all') . '</td></tr>';
    }
    if (empty($stats['receptionist_redirections'])) {
        $html .= '<tr><td colspan="' . (count($doctors) + 2) . '">هیچ دابەشکردنێکی پێشوازی نییە</td></tr>';
    }
    $html .= '<tr class="vr-photo-grand"><th scope="row">کۆی گواستنەوەی پێشوازی</th>';
    foreach ($doctors as $id => $_name) {
        $html .= '<td data-vr-col="' . $escape((string) $id) . '">' . $cell($stats['receptionist_redirection_column_totals'][$id] ?? 0, 'reception', $id) . '</td>';
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
