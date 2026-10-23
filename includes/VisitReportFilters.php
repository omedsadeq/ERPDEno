<?php

/** Shared, calendar-valid filters for the visits page and its PDF. */
function dh_visit_report_filters(array $input, ?string $today = null): array
{
    $today = new DateTimeImmutable($today ?? date('Y-m-d'));
    $value = static function (string $key, string $default = '') use ($input): string {
        return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : $default;
    };
    $mode = $value('mode', 'today');
    if (!in_array($mode, ['today', 'yesterday', 'week', 'month', 'range'], true)) {
        $mode = 'today';
    }
    $from = $to = $today->format('Y-m-d');
    $label = 'ئەمڕۆ';
    $month = $value('month', $today->format('Y-m'));
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $parts)
        || (int) $parts[1] < 1000 || !checkdate((int) $parts[2], 1, (int) $parts[1])) {
        $month = $today->format('Y-m');
    }
    $validDate = static function (string $date): bool {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
            && (int) $parts[1] >= 1000
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    };
    if ($mode === 'yesterday') {
        $from = $to = $today->modify('-1 day')->format('Y-m-d');
        $label = 'دوێنێ';
    } elseif ($mode === 'week') {
        $daysToSaturday = ((int) $today->format('w') + 1) % 7;
        $start = $today->modify("-{$daysToSaturday} days");
        $from = $start->format('Y-m-d');
        $to = $start->modify('+6 days')->format('Y-m-d');
        $label = 'ئەم هەفتەیە';
    } elseif ($mode === 'month') {
        $start = new DateTimeImmutable($month . '-01');
        $from = $start->format('Y-m-d');
        $to = $start->format('Y-m-t');
        $label = 'مانگی ' . $month;
    } elseif ($mode === 'range') {
        $fromIn = $value('from');
        $toIn = $value('to');
        if ($validDate($fromIn) && $validDate($toIn)) {
            $from = min($fromIn, $toIn);
            $to = max($fromIn, $toIn);
            $label = "لە {$from} بۆ {$to}";
        } else {
            $mode = 'today';
        }
    }
    return [
        'mode' => $mode,
        'month' => $month,
        'from' => $from,
        'to' => $to,
        'label' => $label,
        'date_filter' => $value('date_filter') === 'appointment_date' ? 'appointment_date' : 'visit_date',
        'visit_type' => $value('visit_type') === 'treatment' ? 'treatment' : 'checkup',
        'tab' => $value('tab') === 'referrals' ? 'referrals' : 'visits',
        'examiner_id' => ctype_digit($value('examiner_id')) ? (int) $value('examiner_id') : 0,
    ];
}
