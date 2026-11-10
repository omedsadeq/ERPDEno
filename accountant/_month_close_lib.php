<?php
/**
 * داخستنی مانگ — the income sheet of one month, as plain figures: what a
 * closing keeps frozen (period_closures.snapshot) and what the closing page
 * sets it against. Built by the income sheet itself (_income_data.php), so
 * the copy is exactly what the sheet showed. All three expense kinds count,
 * whatever chips the viewer last picked on the sheet.
 */

require_once __DIR__ . '/../classes/PeriodClose.php';

/** The month's sheet: rows, totals, the bottom lines. */
function month_close_sheet(string $month): array
{
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $savedGet = $_GET;
    $savedCookie = $_COOKIE['inc_x'] ?? null;
    $_GET = ['from' => $from, 'to' => $to];
    unset($_COOKIE['inc_x']);   // no chip choice: lab, store and general all count
    try {
        include __DIR__ . '/_income_data.php';
    } finally {
        $_GET = $savedGet;
        if ($savedCookie !== null) {
            $_COOKIE['inc_x'] = $savedCookie;
        }
    }
    $money = fn($v) => round((float)$v, 2);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'kind' => $r['kind'], 'id' => isset($r['id']) ? (int)$r['id'] : null, 'label' => (string)$r['label'],
            'count' => (int)$r['count'], 'amount' => $money($r['amount']),
            'lab' => $money($r['lab']), 'store' => $money($r['store']), 'general' => $money($r['general']),
            'cols' => array_map($money, $r['cols'] ?? []),
            'net' => $money($r['net']), 'doctorShare' => $money($r['doctorShare']), 'centerShare' => $money($r['centerShare']),
            'rate' => $r['rate'],
        ];
    }
    return [
        'month' => $month, 'from' => $from, 'to' => $to, 'made_at' => date('Y-m-d H:i:s'),
        'columns' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']], $incColumns),
        'rows' => $out,
        'totals' => array_map($money, array_intersect_key($totals, array_flip(['count', 'amount', 'lab', 'store', 'general', 'net', 'doctorShare', 'centerShare']))),
        'colTotals' => array_map($money, $totals['cols'] ?? []),
        'expenseLines' => array_map($money, $expenseLines),
        'expenses' => $money($expensesAll),
        'remaining' => $money($remaining),
        'centerAfter' => $money($centerAfterExpenses),
    ];
}

/** Where the sheet now differs from the copy: label => [copy, now]. Empty when the same. */
function month_close_diff(array $copy, array $now): array
{
    $names = ['amount' => 'پارەی وەرگیراو', 'lab' => 'خەرجی تاقیگە', 'store' => 'خەرجی کۆگا', 'general' => 'خەرجی گشتی',
              'net' => 'کۆی گشتی', 'doctorShare' => 'پشکی دکتۆرەکان', 'centerShare' => 'پشکی سەنتەر'];
    $diff = [];
    foreach ($names as $k => $label) {
        if (abs(($copy['totals'][$k] ?? 0) - ($now['totals'][$k] ?? 0)) >= 0.5) {
            $diff[$label] = [$copy['totals'][$k] ?? 0, $now['totals'][$k] ?? 0];
        }
    }
    foreach (['expenses' => 'خەرجیات', 'remaining' => 'ماوە'] as $k => $label) {
        if (abs(($copy[$k] ?? 0) - ($now[$k] ?? 0)) >= 0.5) {
            $diff[$label] = [$copy[$k] ?? 0, $now[$k] ?? 0];
        }
    }
    // a row that moved while the totals happen to agree
    $byKey = fn(array $s) => array_column(array_map(fn($r) => ['k' => $r['kind'] . ':' . ($r['id'] ?? ''), 'r' => $r], $s['rows']), 'r', 'k');
    $a = $byKey($copy);
    $b = $byKey($now);
    foreach ($a + $b as $k => $r) {
        $x = $a[$k]['net'] ?? 0;
        $y = $b[$k]['net'] ?? 0;
        if (abs($x - $y) >= 0.5) {
            $diff['کۆی گشتیی ' . ($r['label'] ?? $k)] = [$x, $y];
        }
    }
    return $diff;
}

/** The frozen sheet as a table (screen and print). */
function month_close_table(array $s): string
{
    $m = fn($v) => abs((float)$v) < 0.5 ? '<span class="z">—</span>' : number_format((float)$v);
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $cols = $s['columns'] ?? [];
    $html = '<table class="mc-tbl"><thead><tr><th>بەش</th><th>ژمارە</th><th>پارەی وەرگیراو</th><th>تاقیگە</th><th>کۆگا</th><th>گشتی</th>';
    foreach ($cols as $c) {
        $html .= '<th class="cus">' . $h($c['name']) . '</th>';
    }
    $html .= '<th>کۆی گشتی</th><th>پشکی دکتۆر</th><th>پشکی سەنتەر</th></tr></thead><tbody>';
    foreach ($s['rows'] as $r) {
        $html .= '<tr' . ($r['kind'] === 'doctor' ? '' : ' class="dept"') . '><td class="nm">' . $h($r['label'])
            . ($r['rate'] !== null && $r['kind'] === 'doctor' ? ' <small>' . $h(rtrim(rtrim(number_format((float)$r['rate'], 2, '.', ''), '0'), '.')) . '%</small>' : '')
            . '</td><td>' . $m($r['count']) . '</td><td>' . $m($r['amount']) . '</td><td>' . $m($r['lab']) . '</td><td>' . $m($r['store'])
            . '</td><td>' . $m($r['general']) . '</td>';
        foreach ($cols as $c) {
            $html .= '<td class="cus">' . $m($r['cols'][$c['id']] ?? 0) . '</td>';
        }
        $html .= '<td class="b">' . $m($r['net']) . '</td><td>' . $m($r['doctorShare']) . '</td><td>' . $m($r['centerShare']) . '</td></tr>';
    }
    $t = $s['totals'];
    $html .= '</tbody><tfoot><tr><td>کۆ</td><td>' . $m($t['count']) . '</td><td>' . $m($t['amount']) . '</td><td>' . $m($t['lab'])
        . '</td><td>' . $m($t['store']) . '</td><td>' . $m($t['general']) . '</td>';
    foreach ($cols as $c) {
        $html .= '<td class="cus">' . $m($s['colTotals'][$c['id']] ?? 0) . '</td>';
    }
    $html .= '<td>' . $m($t['net']) . '</td><td>' . $m($t['doctorShare']) . '</td><td>' . $m($t['centerShare']) . '</td></tr></tfoot></table>';
    $html .= '<div class="mc-bottom"><div><span>کۆی گشتی</span><b>' . $m($t['net']) . '</b></div>';
    foreach ($s['expenseLines'] as $label => $v) {
        $html .= '<div class="ex"><span>خەرجیات — ' . $h($label) . '</span><b>' . $m($v) . '</b></div>';
    }
    $html .= '<div class="end"><span>ماوە</span><b>' . $m($s['remaining']) . '</b></div>'
        . '<div><span>پشکی سەنتەر دوای خەرجیات</span><b>' . $m($s['centerAfter']) . '</b></div></div>';
    return $html;
}
