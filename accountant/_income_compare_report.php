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
        'name' => 'دکتۆر', 'amount' => 'داهات', 'lab' => 'مختبر',
        'normal' => 'کۆگا', 'normalDoc' => 'کۆگا دکتۆر', 'normalCtr' => 'کۆگا لێزان',
        'implant' => 'implant', 'implantDoc' => 'دکتۆر imp', 'implantCtr' => 'لێزان imp',
        'net' => 'ماوە', 'rate' => 'دابەشکردن', 'doctor' => 'ماوەی دکتۆر', 'center' => 'ماوەی لێزان',
        'change' => 'بەراوردی پار سال', 'minutes' => 'سعات', 'days' => 'ڕۆژ',
        'pending' => 'داواکراو', 'given' => 'پێدراو', 'old' => 'کۆن', 'new' => 'نوێ', 'cases' => 'کەیسی پێدراو',
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
    // ماوە is the income sheet's «کۆی ماوە دوای خەرجی» (pool: before the
    // store); a kept 2025 month has only the paper's ماوە.
    $line = ['amount' => $n($r['amount']), 'net' => $n(array_key_exists('pool', $r) ? $r['pool'] : $r['net']), 'center' => $n($r['centerShare'])];
    if ($rep) {
        $line += [
            'lab' => $n($r['lab']),
            'normal' => $n($by['normal'] ?? 0), 'normalDoc' => $n($doc['normal'] ?? 0), 'normalCtr' => $n(($by['normal'] ?? 0) - ($doc['normal'] ?? 0)),
            'implant' => $n($by['implant'] ?? 0), 'implantDoc' => $n($doc['implant'] ?? 0), 'implantCtr' => $n(($by['implant'] ?? 0) - ($doc['implant'] ?? 0)),
            'doctor' => $n($r['doctorShare']), 'minutes' => $rep['minutes'] === null ? null : $n($rep['minutes']), 'days' => $n($rep['days']),
            'pending' => $n($rep['checkups']['pending'] ?? 0), 'given' => $n($rep['checkups']['given'] ?? 0),
            'old' => $n($rep['checkups']['old_exam'] ?? 0), 'new' => $n($rep['checkups']['new_exam'] ?? 0),
            'cases' => $n($rep['casesGiven'] ?? 0),
        ];
    }
    return $line;
}

/**
 * Whether a row's ماوە is written: a doctor's always; a department's when the
 * row is the live income sheet's (it has a pool, as on accountant/income.php),
 * not when it is a kept 2025 month's (the paper leaves it empty).
 */
function income_compare_report_has_net(array $r): bool
{
    return $r['kind'] === 'doctor' || array_key_exists('pool', $r);
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
        return abs($v) < 0.5 ? ($doctorRow ? 'نیە' : '') : '<span dir="ltr">' . ($v < 0 ? '−' : '') . number_format(abs(round($v))) . '</span>';
    };
    $hours = fn($m) => $m === null ? '' : '<span dir="ltr">' . intdiv((int)round($m), 60) . ':' . str_pad((string)((int)round($m) % 60), 2, '0', STR_PAD_LEFT) . '</span>';
    $sumKeys = array_diff(array_keys($cols), ['name', 'rate', 'change']);

    $totals = array_fill_keys($sumKeys, 0.0);
    $baseTotals = array_fill_keys($sumKeys, 0.0);
    foreach ($base['rows'] ?? [] as $r) {
        foreach (income_compare_report_line($r, $baseScale) as $k => $v) {
            if ($k === 'net' && !income_compare_report_has_net($r)) {
                continue;
            }
            $baseTotals[$k] += (float)$v;
        }
    }

    // Fixed widths (%), so both periods' tables line up column for column in the PDF.
    $widths = ['name' => 9, 'amount' => 6, 'lab' => 5, 'normal' => 5, 'normalDoc' => 5, 'normalCtr' => 5, 'implant' => 5.5,
               'implantDoc' => 5.5, 'implantCtr' => 5.5, 'net' => 6, 'rate' => 5, 'doctor' => 6.5, 'center' => 6.5, 'change' => 4,
               'minutes' => 4, 'days' => 2.5, 'pending' => 3, 'given' => 3, 'old' => 2.5, 'new' => 2.5, 'cases' => 3];
    $html = '<div class="cr-title">' . $e($title) . '</div><table class="cr"><thead><tr>';
    foreach ($cols as $k => $label) {
        $html .= '<th width="' . ($widths[$k] ?? 4) . '%">' . $e($label) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    // The departments as the paper names them; a `visits` row (examinations the
    // visits report counts under no doctor account) keeps that report's name.
    $deptNames = ['pharmacy' => 'صیدلیە', 'xray' => 'x.ray', 'exam' => 'exam', 'other' => 'other'];
    $i = 0;
    foreach ($P['rows'] as $key => $r) {
        $line = income_compare_report_line($r, $scale);
        $doctorRow = $r['kind'] === 'doctor';
        $hasNet = income_compare_report_has_net($r);
        foreach ($line as $k => $v) {
            if ($k === 'net' && !$hasNet) {
                continue;
            }
            $totals[$k] += (float)$v;
        }
        // grey and white by turns, as on the paper
        $html .= '<tr class="' . ($doctorRow ? '' : 'dept') . ($i++ % 2 ? ' alt' : '') . '">';
        foreach ($cols as $k => $label) {
            if ($k === 'name') {
                $html .= '<td class="nm">' . $e($deptNames[$r['kind']] ?? $r['label']) . '</td>';
            } elseif ($k === 'rate') {
                $pct = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . '%';
                $cell = $doctorRow && $r['rate'] !== null ? 'دکتۆر ' . $pct($r['rate']) : '';
                // the store's own percentages, when set; unset, the doctor's applies
                $sr = $r['report']['storeRates'] ?? [];
                $parts = array_filter([
                    ($sr['normal'] ?? null) !== null ? 'کۆگا ' . $pct($sr['normal']) : null,
                    ($sr['implant'] ?? null) !== null ? 'ئیمپ ' . $pct($sr['implant']) : null,
                ]);
                $html .= '<td class="rt">' . $cell . ($cell !== '' && $parts ? '<br><small>' . implode(' · ', $parts) . '</small>' : '') . '</td>';
            } elseif ($k === 'change') {
                $was = isset($base['rows'][$key]) ? income_compare_report_line($base['rows'][$key], $baseScale)['center'] : 0.0;
                $html .= '<td class="ch">' . income_compare_report_pct($was, $line['center']) . '</td>';
            } elseif ($k === 'minutes') {
                $html .= '<td class="n">' . $hours($line['minutes'] ?? null) . '</td>';
            } elseif (in_array($k, ['days', 'pending', 'given', 'old', 'new', 'cases'], true)) {
                $v = $line[$k] ?? null;
                $html .= '<td class="c">' . ($v === null ? '' : (abs($v) < 0.05 ? ($doctorRow ? 'نەبوو' : '') : rtrim(rtrim(number_format($v, 1), '0'), '.'))) . '</td>';
            } else {
                $html .= '<td class="n' . (in_array($k, ['center', 'net'], true) ? ' b' : '') . '">'
                    . ($k === 'net' && !$hasNet ? '' : $money($line[$k] ?? null, $doctorRow)) . '</td>';
            }
        }
        $html .= '</tr>';
    }
    // کۆی گشتی: the money and the examinations; the paper adds up no hours or days.
    $html .= '</tbody><tfoot><tr class="tot">';
    foreach ($cols as $k => $label) {
        if ($k === 'name') {
            $html .= '<td class="nm">کۆی گشتی</td>';
        } elseif (in_array($k, ['rate', 'minutes', 'days'], true)) {
            $html .= '<td></td>';
        } elseif ($k === 'change') {
            $html .= '<td class="ch">' . income_compare_report_pct($baseTotals['center'], $totals['center']) . '</td>';
        } elseif (in_array($k, ['pending', 'given', 'old', 'new', 'cases'], true)) {
            $html .= '<td class="c">' . rtrim(rtrim(number_format($totals[$k], 1), '0'), '.') . '</td>';
        } else {
            $html .= '<td class="n">' . $money($totals[$k], false) . '</td>';
        }
    }
    $html .= '</tr>';
    if ($base !== null) {
        $html .= '<tr class="chg">';
        foreach ($cols as $k => $label) {
            $html .= in_array($k, $sumKeys, true) && !in_array($k, ['minutes', 'days'], true)
                ? '<td class="ch">' . income_compare_report_pct($baseTotals[$k], $totals[$k]) . '</td>'
                : '<td>' . ($k === 'name' ? 'گۆڕان' : '') . '</td>';
        }
        $html .= '</tr>';
    }
    // The bottom of the paper, under ماوەی لێزان: خەرجی, then ماوە (what the
    // centre keeps, yellow); a deduction with its note beside it, and what is
    // left after it (yellow). The change against the other period sits where
    // the paper writes its «بەراوردی پار سال».
    $exp = $P['expenses'] * $scale;
    $ded = (float)($P['deductions'] ?? 0) * $scale;
    $keep = $totals['center'] - $exp;
    $baseExp = $base ? $base['expenses'] * $baseScale : null;
    $baseDed = $base ? (float)($base['deductions'] ?? 0) * $baseScale : 0.0;
    $baseKeep = $base ? $baseTotals['center'] - $baseExp : null;
    $bottom = [['خەرجی', $exp, $baseExp, '', false], ['ماوە', $keep, $baseKeep, '', true]];
    if (abs($ded) >= 0.5 || abs($baseDed) >= 0.5) {
        $bottom[] = ['', -$ded, null, (string)($P['note'] ?? ''), false];
        $bottom[] = ['', $keep - $ded, $baseKeep === null ? null : $baseKeep - $baseDed, '', true];
    }
    $noteCol = isset($cols['change']) ? 'change' : 'minutes';
    foreach ($bottom as [$name, $v, $was, $note, $yellow]) {
        $html .= '<tr class="end' . ($yellow ? ' y' : '') . '">';
        foreach ($cols as $k => $label) {
            if ($k === 'name') {
                $html .= '<td class="nm">' . $e($name) . '</td>';
            } elseif ($k === 'center') {
                $html .= '<td class="n b">' . $money($v, false) . '</td>';
            } elseif ($k === $noteCol) {
                $html .= '<td class="ch">' . ($note !== '' ? $e($note) : ($was === null ? '' : income_compare_report_pct($was, $v))) . '</td>';
            } else {
                $html .= '<td></td>';
            }
        }
        $html .= '</tr>';
    }
    return $html . '</tfoot></table>';
}
