<?php
/**
 * The comparison laid out as the clinic's own paper, «لیستی داهات و کاتی هاتنی
 * دکتۆرەکان»: one table per period with, per doctor, داهات, تاقیگە, کۆگا and
 * ئیمپلانت with the doctor's and لێزان's parts, ماوە, the split, the two
 * shares, and the doctor's hours, days and examinations; X-ray, Pharmacy and
 * Examinations below. Against the other period, a «بەراورد» column (لێزان's
 * share) and a change line under the totals.
 *
 * Used by accountant/income-compare.php on screen and in its PDF. A period is
 * income_compare_period()'s array; $scale turns its figures into a monthly
 * average when asked.
 */

/** The columns, right to left as on the paper: key => heading. */
function income_compare_report_columns(bool $withChange): array
{
    $cols = [
        'name' => 'دکتۆر', 'amount' => 'داهات', 'lab' => 'تاقیگە',
        'normal' => 'کۆگا', 'normalDoc' => 'کۆگا دکتۆر', 'normalCtr' => 'کۆگا لێزان',
        'implant' => 'ئیمپلانت', 'implantDoc' => 'ئیمپلانت دکتۆر', 'implantCtr' => 'ئیمپلانت لێزان',
        'net' => 'ماوە', 'rate' => 'دابەشکردن', 'doctor' => 'ماوەی دکتۆر', 'center' => 'ماوەی لێزان',
        'change' => 'بەراورد', 'minutes' => 'سەعات', 'days' => 'ڕۆژ',
        'pending' => 'داواکراو', 'given' => 'پێدراو', 'old' => 'کۆن', 'new' => 'نوێ',
    ];
    if (!$withChange) {
        unset($cols['change']);
    }
    return $cols;
}

/** One row's figures, scaled; null where the row has none (a department has no lab or hours). */
function income_compare_report_line(array $r, float $s): array
{
    $rep = $r['report'] ?? null;
    $n = fn($v) => $v === null ? null : (float)$v * $s;
    $by = $rep['storeByKind'] ?? [];
    $doc = $rep['storeOnDoctorByKind'] ?? [];
    $line = ['amount' => $n($r['amount']), 'net' => $n($r['net']), 'center' => $n($r['centerShare'])];
    if ($rep) {
        $line += [
            'lab' => $n($r['lab']),
            'normal' => $n($by['normal'] ?? 0), 'normalDoc' => $n($doc['normal'] ?? 0), 'normalCtr' => $n(($by['normal'] ?? 0) - ($doc['normal'] ?? 0)),
            'implant' => $n($by['implant'] ?? 0), 'implantDoc' => $n($doc['implant'] ?? 0), 'implantCtr' => $n(($by['implant'] ?? 0) - ($doc['implant'] ?? 0)),
            'doctor' => $n($r['doctorShare']), 'minutes' => $rep['minutes'] === null ? null : $n($rep['minutes']), 'days' => $n($rep['days']),
            'pending' => $n($rep['checkups']['pending'] ?? 0), 'given' => $n($rep['checkups']['given'] ?? 0),
            'old' => $n($rep['checkups']['old_exam'] ?? 0), 'new' => $n($rep['checkups']['new_exam'] ?? 0),
        ];
    }
    return $line;
}

/** «+12%» / «−8%» against the other period, or '' when there is nothing to compare. */
function income_compare_report_pct(?float $base, ?float $now): string
{
    if ($base === null || $now === null || (abs($base) < 0.5 && abs($now) < 0.5)) {
        return '';
    }
    if (abs($base) < 0.5) {
        return 'نوێ';
    }
    $p = ($now - $base) / abs($base) * 100;
    return '<span dir="ltr">' . ($p >= 0 ? '+' : '−') . number_format(abs($p), abs($p) < 10 ? 1 : 0) . '%</span>';
}

/** One period's table. */
function income_compare_report(array $P, ?array $base, string $title, float $scale = 1.0, float $baseScale = 1.0): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $cols = income_compare_report_columns($base !== null);
    $money = function ($v, bool $doctorRow) {
        if ($v === null) {
            return '';
        }
        return abs($v) < 0.5 ? ($doctorRow ? 'نەبوو' : '') : '<span dir="ltr">' . ($v < 0 ? '−' : '') . number_format(abs(round($v))) . '</span>';
    };
    $hours = fn($m) => $m === null ? '' : '<span dir="ltr">' . intdiv((int)round($m), 60) . ':' . str_pad((string)((int)round($m) % 60), 2, '0', STR_PAD_LEFT) . '</span>';
    $sumKeys = array_diff(array_keys($cols), ['name', 'rate', 'change']);

    $totals = array_fill_keys($sumKeys, 0.0);
    $baseTotals = array_fill_keys($sumKeys, 0.0);
    foreach ($base['rows'] ?? [] as $r) {
        foreach (income_compare_report_line($r, $baseScale) as $k => $v) {
            $baseTotals[$k] += (float)$v;
        }
    }

    // Fixed widths (%), so both periods' tables line up column for column in the PDF.
    $widths = ['name' => 10, 'amount' => 6.5, 'lab' => 5, 'normal' => 5, 'normalDoc' => 5, 'normalCtr' => 5, 'implant' => 5.5,
               'implantDoc' => 5.5, 'implantCtr' => 5.5, 'net' => 6.5, 'rate' => 5, 'doctor' => 6.5, 'center' => 6.5, 'change' => 4,
               'minutes' => 4, 'days' => 2.5, 'pending' => 3, 'given' => 3, 'old' => 2.5, 'new' => 2.5];
    $html = '<div class="cr-title">' . $e($title) . '</div><table class="cr"><thead><tr>';
    foreach ($cols as $k => $label) {
        $html .= '<th width="' . ($widths[$k] ?? 4) . '%">' . $e($label) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($P['rows'] as $key => $r) {
        $line = income_compare_report_line($r, $scale);
        $doctorRow = $r['kind'] === 'doctor';
        $anyMinutes = ($anyMinutes ?? false) || ($line['minutes'] ?? null) !== null;
        foreach ($line as $k => $v) {
            $totals[$k] += (float)$v;
        }
        $html .= '<tr' . ($doctorRow ? '' : ' class="dept"') . '>';
        foreach ($cols as $k => $label) {
            if ($k === 'name') {
                $html .= '<td class="nm">' . $e($r['label']) . '</td>';
            } elseif ($k === 'rate') {
                $html .= '<td class="rt">' . ($doctorRow && $r['rate'] !== null ? 'دکتۆر ' . rtrim(rtrim(number_format($r['rate'], 2, '.', ''), '0'), '.') . '%' : '') . '</td>';
            } elseif ($k === 'change') {
                $was = isset($base['rows'][$key]) ? income_compare_report_line($base['rows'][$key], $baseScale)['center'] : 0.0;
                $html .= '<td class="ch">' . income_compare_report_pct($was, $line['center']) . '</td>';
            } elseif ($k === 'minutes') {
                $html .= '<td class="n">' . $hours($line['minutes'] ?? null) . '</td>';
            } elseif (in_array($k, ['days', 'pending', 'given', 'old', 'new'], true)) {
                $v = $line[$k] ?? null;
                $html .= '<td class="c">' . ($v === null ? '' : (abs($v) < 0.05 ? ($doctorRow ? 'نەبوو' : '') : rtrim(rtrim(number_format($v, 1), '0'), '.'))) . '</td>';
            } else {
                $html .= '<td class="n' . (in_array($k, ['center', 'net'], true) ? ' b' : '') . '">' . $money($line[$k] ?? null, $doctorRow) . '</td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</tbody><tfoot><tr class="tot">';
    foreach ($cols as $k => $label) {
        if ($k === 'name') {
            $html .= '<td class="nm">کۆی گشتی</td>';
        } elseif ($k === 'rate') {
            $html .= '<td></td>';
        } elseif ($k === 'change') {
            $html .= '<td class="ch">' . income_compare_report_pct($baseTotals['center'], $totals['center']) . '</td>';
        } elseif ($k === 'minutes') {
            $html .= '<td class="n">' . $hours(!empty($anyMinutes) ? $totals['minutes'] : null) . '</td>';
        } elseif (in_array($k, ['days', 'pending', 'given', 'old', 'new'], true)) {
            $html .= '<td class="c">' . rtrim(rtrim(number_format($totals[$k], 1), '0'), '.') . '</td>';
        } else {
            $html .= '<td class="n">' . $money($totals[$k], false) . '</td>';
        }
    }
    $html .= '</tr>';
    if ($base !== null) {
        $html .= '<tr class="chg">';
        foreach ($cols as $k => $label) {
            $html .= in_array($k, $sumKeys, true)
                ? '<td class="ch">' . income_compare_report_pct($baseTotals[$k], $totals[$k]) . '</td>'
                : '<td>' . ($k === 'name' ? 'گۆڕان' : '') . '</td>';
        }
        $html .= '</tr>';
    }
    // The bottom of the paper: what the centre spent, and what it keeps.
    $span = count($cols) - 2;
    foreach ([['خەرجی', $P['expenses'] * $scale, $base ? $base['expenses'] * $baseScale : null],
              ['ماوە', $P['centerAfter'] * $scale, $base ? $base['centerAfter'] * $baseScale : null]] as [$name, $v, $was]) {
        $html .= '<tr class="end"><td class="nm">' . $e($name) . '</td><td class="n b">' . $money($v, false) . '</td>'
            . '<td class="ch">' . ($was === null ? '' : income_compare_report_pct($was, $v)) . '</td><td colspan="' . ($span - 1) . '"></td></tr>';
    }
    return $html . '</tfoot></table>';
}
