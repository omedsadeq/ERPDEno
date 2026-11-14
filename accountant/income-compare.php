<?php
/**
 * بەراورد — two periods of the income sheet, laid out as the clinic's own paper
 * (_income_compare_report.php): the second period on top with how it compares,
 * the first below. This month against the same month last year, two months
 * against two months, a year against a year, any dates. Each period's figures
 * are the income sheet's own (accountant/_income_data.php, run once per
 * period, with all three expense kinds on — تاقیگە, کۆگا, گشتی — as the paper
 * has them), so this page can never disagree with the sheet. The examination
 * columns are the visits report's (visits-report.php, its «پشکنین» matrix):
 * the doctors' through ds_compute_range, which applies the same rules, the
 * X-ray department's and any column that report has without a doctor account
 * straight from dh_visit_report_stats, so the totals are that report's totals.
 *
 *   ?a_from=&a_to=   the first period (the one compared against)
 *   ?b_from=&b_to=   the second period
 *   ?avg=1           every figure as a monthly average, for periods of unequal length
 *   ?pdf=1           the same two tables as a PDF, A4 across
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../doctors/_daily_summary_lib.php';
require_once __DIR__ . '/_income_compare_report.php';
require_once __DIR__ . '/../includes/VisitReportStats.php';
require_once __DIR__ . '/../includes/VisitReferralReport.php';
require_once __DIR__ . '/../classes/IncomeArchive.php';

$auth = new Auth();
$auth->requireLogin();
if (!Permission::getInstance()->can('page.accountant_financial')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// ── کۆپیی دەستکاریکراو: the tables edited by hand, saved apart ───────
// The live report below is never changed by them (classes/IncomeCompareCopy.php).
require_once __DIR__ . '/../classes/IncomeCompareCopy.php';
$copyReady = IncomeCompareCopy::available();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $copyReady) {
    Csrf::require();
    $me = (int)($auth->getCurrentUser()['id'] ?? 0);
    $back = APP_URL . '/accountant/income-compare.php';
    if (($_POST['copy_action'] ?? '') === 'delete') {
        $gone = IncomeCompareCopy::find((int)($_POST['copy_id'] ?? 0));
        if ($gone) {
            IncomeCompareCopy::delete((int)$gone['id']);
            AuditLog::log('income_compare_copy_delete', 'income_compare_copies', (int)$gone['id'], ['title' => $gone['title']], null, $me);
        }
        header('Location: ' . $back . '?copy_done=deleted');
        exit;
    }
    if (($_POST['copy_action'] ?? '') === 'save') {
        try {
            $asNew = !empty($_POST['as_new']);
            $id = IncomeCompareCopy::save($asNew ? null : ((int)($_POST['copy_id'] ?? 0) ?: null), (string)($_POST['title'] ?? ''),
                (string)($_POST['doc'] ?? ''), $_POST, $me);
            AuditLog::log('income_compare_copy_save', 'income_compare_copies', $id, null, ['title' => (string)($_POST['title'] ?? '')], $me);
            header('Location: ' . $back . '?copy=' . $id . '&copy_done=saved');
        } catch (InvalidArgumentException $ex) {
            header('Location: ' . $back . '?copy_error=' . rawurlencode($ex->getMessage()));
        }
        exit;
    }
}
$copy = ($copyReady && !empty($_GET['copy'])) ? IncomeCompareCopy::find((int)$_GET['copy']) : null;
$copies = $copyReady ? IncomeCompareCopy::all() : [];

/** The income sheet of one period: its rows and its totals. */
function income_compare_period(string $from, string $to): array
{
    // A period of months kept from the old system (داتای پێشوو, 2025): their paper.
    $kept = IncomeArchive::period($from, $to);
    if ($kept !== null) {
        return $kept;
    }
    // The sheet with every expense kind on, whatever the income page's own
    // switch (its inc_x cookie) was last set to: this page has fixed columns
    // for the lab and the store, and its ماوە is always the sheet's «کۆی ماوە
    // دوای خەرجی» with the general expense taken off too. No xs= is passed, so
    // the sheet sets no cookie and the income page keeps its own choice.
    $saved = [$_GET, $_COOKIE['inc_x'] ?? null];
    $_GET = ['from' => $from, 'to' => $to];
    unset($_COOKIE['inc_x']);
    try {
        include __DIR__ . '/_income_data.php';
    } finally {
        [$_GET, $savedCookie] = $saved;
        if ($savedCookie !== null) {
            $_COOKIE['inc_x'] = $savedCookie;
        }
    }
    $byKey = [];
    foreach ($rows as $r) {
        $byKey[$r['kind'] === 'doctor' ? 'd' . $r['id'] : $r['kind']] = $r;
    }
    $db = Database::getInstance();
    // پشکنینەکان: the visits report's own «پشکنین» matrix (visits-report.php,
    // dh_visit_report_stats — by visit date, each visit once, for the doctor
    // who saw it). The doctors' rows already carry it from ds_compute_range,
    // which applies the same rules; here the columns that report has without
    // a doctor account: the X-ray department's goes on the x.ray row, any
    // other («دکتۆر دیاری نەکراوە», the «لێزان» placeholder) becomes a row of
    // examinations only. So each column's total here is that report's total.
    $visits = dh_visit_report_stats($db, $from, $to, 'visit_date', 'checkup');
    $checkups = fn(array $s) => ['given' => (int)$s['reception'], 'pending' => (int)$s['requested'], 'other' => (int)$s['other'],
                                 'new_exam' => (int)$s['new'], 'old_exam' => (int)$s['old']];
    $examOnly = fn(array $c) => ['storeByKind' => [], 'storeOnDoctorByKind' => [], 'minutes' => null, 'days' => null, 'checkups' => $c];
    $doctorRows = [];
    foreach ($db->fetchAll("SELECT d.id, d.name, d.user_id, u.role FROM doctors d LEFT JOIN users u ON u.id = d.user_id") as $d) {
        $doctorRows[(int)$d['id']] = $d;
    }
    $xrayChecks = ['given' => 0, 'pending' => 0, 'other' => 0, 'new_exam' => 0, 'old_exam' => 0];
    foreach ($visits['doctor_stats'] as $id => $s) {
        $id = (int)$id;
        $d = $doctorRows[$id] ?? null;
        if ($d && $d['user_id'] !== null && isset($byKey['d' . $d['user_id']])) {
            continue;   // a doctor of the sheet: his row has it already
        }
        if ((int)$s['assigned'] === 0) {
            continue;
        }
        // the visits report's own rule for the X-ray column (VisitReferralReport.php)
        if ($d && (($d['role'] ?? '') === 'xray_department' || strtolower(trim((string)$d['name'])) === 'x.ray')) {
            foreach ($checkups($s) as $k => $v) {
                $xrayChecks[$k] += $v;
            }
            continue;
        }
        $byKey['v' . $id] = ['kind' => 'visits', 'id' => $id, 'label' => (string)($visits['doctors'][$id] ?? $s['name']),
                             'amount' => 0.0, 'lab' => 0.0, 'store' => 0.0, 'general' => 0.0, 'net' => 0.0, 'pool' => 0.0,
                             'rate' => null, 'doctorShare' => 0.0, 'centerShare' => 0.0, 'report' => $examOnly($checkups($s))];
    }
    if (isset($byKey['xray']) && array_sum($xrayChecks) > 0) {
        $byKey['xray']['report'] = $examOnly($xrayChecks);
    }
    // کەیسی پێدراو: patients another examiner, reception or the patient sent to
    // this doctor for work — the visits report's «ناردن بۆ ئیش» tab, its column total.
    $referrals = dh_visit_referral_report($db, ['from' => $from, 'to' => $to, 'date_filter' => 'visit_date', 'examiner_id' => 0]);
    foreach ($db->fetchAll('SELECT id, user_id FROM doctors WHERE user_id IS NOT NULL') as $d) {
        if (isset($byKey['d' . $d['user_id']])) {
            $byKey['d' . $d['user_id']]['report']['casesGiven'] = (int)($referrals['column_totals'][(int)$d['id']] ?? 0);
        }
    }
    // The store percentages as set in the system (the store page's «ڕێژەی دکتۆرەکان»),
    // shown beside the doctor's own so the paper says which rule made each share.
    foreach ($db->fetchAll("SELECT id, store_share_normal, store_share_implant FROM users WHERE role = 'doctor'") as $u) {
        if (isset($byKey['d' . $u['id']])) {
            $byKey['d' . $u['id']]['report']['storeRates'] = ['normal' => $u['store_share_normal'], 'implant' => $u['store_share_implant']];
        }
    }
    return [
        'from' => $from, 'to' => $to, 'days' => (int)round((strtotime($to) - strtotime($from)) / 86400) + 1,
        'rows' => $byKey, 'totals' => $totals, 'expenses' => $expensesAll, 'deductions' => 0.0, 'note' => '',
        'centerAfter' => $centerAfterExpenses,
    ];
}

// ── The two periods ──────────────────────────────────────────────────
$today = new DateTimeImmutable('today');
$ymd = fn(DateTimeImmutable $d) => $d->format('Y-m-d');
$monthStart = $today->modify('first day of this month');
$day = (int)$today->format('j');
// The same days of another month: 1 → today's day, cut to that month's length.
$sameDays = function (DateTimeImmutable $start) use ($day) {
    return [$start, $start->modify('+' . (min($day, (int)$start->format('t')) - 1) . ' days')];
};
[$lastMonthFrom, $lastMonthTo] = $sameDays($monthStart->modify('-1 month'));
[$lastYearFrom, $lastYearTo] = $sameDays($monthStart->modify('-1 year'));
$prevMonth = $monthStart->modify('-1 month');
$presets = [
    'month_prev' => ['ئەم مانگە ↔ مانگی پێشوو', $lastMonthFrom, $lastMonthTo, $monthStart, $today],
    'month_year' => ['ئەم مانگە ↔ هەمان مانگی پار', $lastYearFrom, $lastYearTo, $monthStart, $today],
    'last_year'  => ['مانگی پێشوو ↔ هەمان مانگی پار', $prevMonth->modify('-1 year'), $prevMonth->modify('-1 year')->modify('last day of this month'),
                     $prevMonth, $prevMonth->modify('last day of this month')],
    'two_months' => ['دوو مانگی دوایی ↔ هەمان دوو مانگی پار', $prevMonth->modify('-1 year'), $today->modify('-1 year'), $prevMonth, $today],
    'year'       => ['ئەم ساڵ ↔ پار، هەمان ماوە', $today->modify('-1 year')->modify('first day of january this year'), $today->modify('-1 year'),
                     $today->modify('first day of january this year'), $today],
];
$valid = fn($v) => is_string($v) && dsValidDate($v);
$aFrom = $valid($_GET['a_from'] ?? null) ? $_GET['a_from'] : $ymd($presets['month_prev'][1]);
$aTo   = $valid($_GET['a_to'] ?? null) ? $_GET['a_to'] : $ymd($presets['month_prev'][2]);
$bFrom = $valid($_GET['b_from'] ?? null) ? $_GET['b_from'] : $ymd($presets['month_prev'][3]);
$bTo   = $valid($_GET['b_to'] ?? null) ? $_GET['b_to'] : $ymd($presets['month_prev'][4]);
if ($aFrom > $aTo) { [$aFrom, $aTo] = [$aTo, $aFrom]; }
if ($bFrom > $bTo) { [$bFrom, $bTo] = [$bTo, $bFrom]; }
$avg = !empty($_GET['avg']);
if ($copy) {
    // a copy shows its own periods in the form, and its own tables below
    $aFrom = $copy['a_from'] ?? $aFrom; $aTo = $copy['a_to'] ?? $aTo;
    $bFrom = $copy['b_from'] ?? $bFrom; $bTo = $copy['b_to'] ?? $bTo;
    $avg = (bool)$copy['avg'];
}
$qs = fn(array $over) => '?' . http_build_query(array_merge(
    ['a_from' => $aFrom, 'a_to' => $aTo, 'b_from' => $bFrom, 'b_to' => $bTo] + ($avg ? ['avg' => 1] : []), $over));

if ($copy) {
    $tablesHtml = array_map([IncomeCompareCopy::class, 'tableHtml'], $copy['doc']['tables']);
} else {
    $A = income_compare_period($aFrom, $aTo);
    $B = income_compare_period($bFrom, $bTo);
    // A monthly average: a period's figure scaled to an average month's length.
    $scale = fn(array $p) => !$avg ? 1.0 : (!empty($p['months']) ? 1 / $p['months'] : 30.4375 / max(1, $p['days']));
    $periodTitle = fn(string $which, array $p) => $which . ': ' . ($p['from'] === $p['to'] ? $p['from'] : $p['from'] . ' بۆ ' . $p['to'])
        . ' · ' . $p['days'] . ' ڕۆژ' . ($avg ? ' — تێکڕای مانگانە' : '')
        . (!empty($p['archived']) ? ' — داتای پێشوو (' . implode('، ', $p['archived']) . ')' : '');
    $tablesHtml = [
        income_compare_report($B, $A, $periodTitle('ماوەی دووەم', $B), $scale($B), $scale($A)),
        income_compare_report($A, null, $periodTitle('ماوەی یەکەم', $A), $scale($A)),
    ];
}

// ── The same two tables as a PDF, A4 across ─────────────────────────
if (!empty($_GET['pdf'])) {
    require_once __DIR__ . '/../services/InvoicePdfService.php';
    require __DIR__ . '/../includes/clinic_branding.php';
    $viewer = $auth->getCurrentUser();
    // Both periods on ONE page, no heading above them: written at the paper's
    // size first, and again a little smaller each time until it fits.
    $css = function (float $f) {
        $pt = fn($v) => round($v * $f, 2) . 'pt';
        $mm = fn($v) => round($v * $f, 2) . 'mm';
        return '<style>
body { font-family: nrt; direction: rtl; color: #0f172a; }
.cr-title { margin: ' . $mm(2) . ' 0 ' . $mm(1) . '; padding: ' . $mm(1) . ' ' . $mm(2) . '; background: #fde68a; font-size: ' . $pt(8.5) . '; font-weight: bold; }
.cr { width: 100%; border-collapse: collapse; }
.cr th { background: #fde68a; font-size: ' . $pt(6.6) . '; padding: ' . $mm(1.1) . ' ' . $mm(0.7) . '; border: 0.6px solid #555; }
.cr td { font-size: ' . $pt(6.8) . '; padding: ' . $mm(1.1) . ' ' . $mm(0.7) . '; border: 0.6px solid #777; }
.cr td small { font-size: ' . $pt(5.6) . '; }
.cr td.nm { font-weight: bold; white-space: nowrap; }
.cr td.n { text-align: center; white-space: nowrap; }
.cr td.c, .cr td.rt, .cr td.ch { text-align: center; white-space: nowrap; }
.cr td.b { font-weight: bold; }
.cr tr.alt td { background: #e5e7eb; }
.cr tr.tot td { background: #fde68a; font-weight: bold; }
.cr tr.chg td { font-size: ' . $pt(6.4) . '; color: #334155; }
.cr tr.end td { font-weight: bold; }
.cr tr.end.y td { background: #fde68a; }
.cr tr.end td.ch { font-size: ' . $pt(6.4) . '; }
</style>';
    };
    $sizes = [1.0, 0.92, 0.85, 0.78, 0.72, 0.66, 0.6, 0.55, 0.5];
    foreach ($sizes as $n => $f) {
        $pdf = new InvoicePdfService([
            'orientation' => 'L', 'format' => 'A4', 'show_header' => 0, 'show_footer' => 0,
            'margin_top_mm' => 6, 'margin_bottom_mm' => 9, 'margin_left_mm' => 6, 'margin_right_mm' => 6,
        ]);
        $pdf->setTitle($copy ? $copy['title'] : 'بەراورد — ' . $bFrom . ' / ' . $aFrom);
        $mpdf = $pdf->getMpdf();
        // a copy says so, small, in the footer: its figures were changed by hand
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="font-family:nrt;font-size:6.5pt;color:#94a3b8;"><tr>'
            . '<td style="text-align:right;">' . dsEsc($viewer['full_name'] ?? '') . ($copy ? ' — کۆپی: ' . dsEsc($copy['title']) : '') . '</td>'
            . '<td style="text-align:left;direction:ltr;">' . date('Y-m-d H:i') . '</td></tr></table>'
        );
        $mpdf->WriteHTML($css($f) . implode('', $tablesHtml));
        if ($mpdf->page <= 1 || $n === count($sizes) - 1) {
            $mpdf->Output(($copy ? 'compare-copy-' . (int)$copy['id'] : 'compare-' . $bFrom . '_' . $bTo . '-vs-' . $aFrom . '_' . $aTo) . '.pdf', 'I');
            exit;
        }
    }
    exit;
}

$pageTitle = 'بەراورد';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'],
    ['title' => 'بەراورد'],
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.cmp-bar{background:#fff;border-radius:16px;padding:12px 16px;box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22)}
.cmp-periods{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px}
.cmp-p{flex:0 1 auto;min-width:0;display:flex;flex-direction:column;gap:5px;padding:8px 10px;border-radius:12px;border:1.5px solid var(--c);background:color-mix(in srgb,var(--c) 6%,#fff)}
.cmp-p b{font-size:12px;color:var(--c)}
.cmp-p div{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:12.5px;color:#475569}
.cmp-p input{flex:1 1 130px;min-width:0;height:34px;border:1px solid #cbd5e1;border-radius:8px;padding:0 8px;font:inherit;font-size:13px;background:#fff}
@media (max-width:600px){.cmp-p{flex:1 1 100%}}
.cmp-avg{display:flex;align-items:center;gap:6px;height:40px;font-size:13px;color:#334155;cursor:pointer}
.cmp-go{height:40px;padding:0 18px;border:0;border-radius:10px;background:#0284c7;color:#fff;font:inherit;font-weight:700;cursor:pointer}
.cmp-pdf{display:inline-flex;align-items:center;gap:6px;height:40px;padding:0 16px;border-radius:10px;border:1px solid #fecaca;background:#fff1f2;color:#be123c;font-weight:700;text-decoration:none}
.cmp-pdf:hover{background:#be123c;color:#fff}
.cmp-presets{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.cmp-presets a{padding:4px 11px;border-radius:999px;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;font-size:12px;text-decoration:none}
.cmp-presets a:hover{border-color:#0284c7;color:#0284c7}
.cmp-presets a.kept{border-color:#fcd34d;background:#fffbeb;color:#92400e}
.cmp-sheet{margin-top:12px;background:#fff;border-radius:16px;padding:4px 14px 14px;box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22)}
.cmp-scroll{overflow-x:auto}
/* the clinic's paper (_income_compare_report.php) */
.cr-title{margin:12px 0 6px;padding:6px 10px;border-radius:8px;background:#fef3c7;color:#78350f;font-size:13px;font-weight:800}
.cr{width:100%;border-collapse:collapse;font-size:12px;min-width:1500px}
.cr th{padding:7px 6px;background:#fde68a;color:#422006;font-size:11.5px;border:1px solid #e5d3a0;white-space:nowrap}
.cr td{padding:6px;border:1px solid #e2e8f0;white-space:nowrap}
.cr td.nm{font-weight:800;color:#0f172a}
.cr td.n,.cr td.c,.cr td.rt,.cr td.ch{text-align:center;font-variant-numeric:tabular-nums}
.cr td.b{font-weight:800}
.cr td.rt{color:#6d28d9;font-size:11.5px}
.cr td.ch{color:#0369a1;font-weight:700}
.cr tr.alt td{background:#eef1f5}
.cr tr.tot td{background:#fef3c7;font-weight:800}
.cr tr.chg td{background:#fffbeb;font-size:11.5px}
.cr tr.end td{font-weight:800}
.cr tr.end.y td{background:#fef3c7}
.cr tr.end td.ch{font-size:11.5px}
/* پڕ بە شاشە، کۆپییەکان و دەستکاری */
.cmp-tool{display:inline-flex;align-items:center;gap:6px;height:40px;padding:0 14px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;color:#334155;font:inherit;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.cmp-tool:hover{background:#f1f5f9;color:#0f172a}
.cmp-tool.is-edit{border-color:#c4b5fd;background:#f5f3ff;color:#6d28d9}
.cmp-tool.is-edit:hover{background:#6d28d9;color:#fff}
.cmp-tool.is-danger{border-color:#fecaca;background:#fff1f2;color:#be123c}
.cmp-copies{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:8px;font-size:12px;color:#6d28d9}
.cmp-copies a{padding:4px 11px;border-radius:999px;border:1px solid #ddd6fe;background:#faf5ff;color:#6d28d9;text-decoration:none}
.cmp-copies a:hover,.cmp-copies a.on{background:#6d28d9;color:#fff}
.cmp-copybar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-top:12px;padding:10px 14px;border-radius:14px;background:#faf5ff;border:1.5px dashed #a78bfa;color:#4c1d95}
.cmp-copybar>i{font-size:20px}
.cmp-copybar div{flex:1;min-width:200px}
.cmp-copybar small{display:block;color:#6d28d9;font-size:12px}
.cmp-copybar form{margin:0}
.cmp-sheet.is-copy{outline:2px dashed #c4b5fd;outline-offset:-2px}
.cmp-editbar{position:sticky;top:0;z-index:5;display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:8px 0;padding:10px 12px;border-radius:12px;background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6}
.cmp-editbar[hidden]{display:none}
.cmp-editnote{flex:1 1 260px;font-size:12px}
.cmp-editbar input[type=text]{flex:1 1 220px;height:40px;border:1px solid #c4b5fd;border-radius:10px;padding:0 10px;font:inherit;font-size:13px;background:#fff}
.cmp-sheet.is-editing [contenteditable]{cursor:text}
.cmp-sheet.is-editing .cr td:hover,.cmp-sheet.is-editing .cr th:hover{box-shadow:inset 0 0 0 1px #a78bfa}
.cmp-sheet.is-editing .cr td:focus,.cmp-sheet.is-editing .cr th:focus,.cmp-sheet.is-editing .cr-title:focus{outline:2px solid #7c3aed;outline-offset:-2px;background:#fff}
.cmp-sheet.is-editing .cr td.is-changed{background:#ede9fe !important}
.cmp-rowtools{position:fixed;z-index:60;display:flex;gap:4px;padding:3px;border-radius:9px;background:#fff;box-shadow:0 6px 18px -6px rgba(15,23,42,.35);border:1px solid #ddd6fe}
.cmp-rowtools[hidden]{display:none}
.cmp-rowtools button{width:28px;height:28px;border:0;border-radius:7px;background:#f5f3ff;color:#6d28d9;cursor:pointer}
.cmp-rowtools button.is-del{background:#fff1f2;color:#be123c}
/* the sheet over the whole screen */
.cmp-sheet:fullscreen{overflow:auto;margin:0;border-radius:0;padding:12px 18px;background:#fff}
.cmp-sheet:fullscreen .cr{min-width:0}
.cmp-sheet:fullscreen .cmp-scroll{overflow-x:auto}
</style>

<form method="get" class="cmp-bar">
    <div class="cmp-periods">
        <div class="cmp-p" style="--c:#64748b"><b>ماوەی یەکەم — بەراورد لەگەڵ</b>
            <div>لە <input type="date" name="a_from" value="<?= dsEsc($aFrom) ?>"> بۆ <input type="date" name="a_to" value="<?= dsEsc($aTo) ?>"></div></div>
        <div class="cmp-p" style="--c:#0284c7"><b>ماوەی دووەم</b>
            <div>لە <input type="date" name="b_from" value="<?= dsEsc($bFrom) ?>"> بۆ <input type="date" name="b_to" value="<?= dsEsc($bTo) ?>"></div></div>
        <label class="cmp-avg" title="هەر ژمارەیەک دەکاتە تێکڕای مانگێک، بۆ بەراوردی ماوەی جیاواز (وەک ساڵێک لەگەڵ مانگێک)">
            <input type="checkbox" name="avg" value="1" <?= $avg ? 'checked' : '' ?>> تێکڕای مانگانە</label>
        <button type="submit" class="cmp-go"><i class="fas fa-scale-balanced"></i> بەراوردکردن</button>
        <a class="cmp-pdf" href="<?= dsEsc($copy ? '?copy=' . (int)$copy['id'] . '&pdf=1' : $qs(['pdf' => 1])) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        <button type="button" class="cmp-tool" id="cmpFull" title="پڕ بە شاشە (Esc بۆ دەرچوون)"><i class="fas fa-expand"></i> پڕ بە شاشە</button>
        <?php if ($copyReady): ?>
        <button type="button" class="cmp-tool is-edit" id="cmpEdit" title="ژمارە، ناو و ڕیزەکان لەسەر کۆپییەک بگۆڕە — ڕاپۆرتی ڕاستەقینە ناگۆڕێت"><i class="fas fa-pen-to-square"></i> <?= $copy ? 'دەستکاری' : 'دەستکاری لەسەر کۆپی' ?></button>
        <?php endif; ?>
    </div>
    <div class="cmp-presets">
        <?php foreach ($presets as [$name, $af, $at, $bf, $bt]): ?>
        <a href="<?= dsEsc($qs(['a_from' => $ymd($af), 'a_to' => $ymd($at), 'b_from' => $ymd($bf), 'b_to' => $ymd($bt)])) ?>"><?= dsEsc($name) ?></a>
        <?php endforeach; ?>
        <?php // as on the owner's paper: last month against the monthly average of a whole year kept in داتای پێشوو
        $keptYears = array_unique(array_map(fn($m) => substr($m, 0, 4), array_keys(IncomeArchive::months())));
        foreach ($keptYears as $ky): ?>
        <a class="kept" href="?<?= dsEsc(http_build_query(['a_from' => $ky . '-01-01', 'a_to' => $ky . '-12-31', 'b_from' => $ymd($prevMonth), 'b_to' => $ymd($prevMonth->modify('last day of this month')), 'avg' => 1])) ?>"
           title="لە داتای پێشوو — دەبێت هەموو ١٢ مانگەکەی <?= dsEsc($ky) ?> پاشەکەوت کرابێت">مانگی پێشوو ↔ تێکڕای مانگانەی <?= dsEsc($ky) ?></a>
        <?php endforeach; ?>
        <a class="kept" href="<?= APP_URL ?>/accountant/income-archive.php"><i class="fas fa-box-archive"></i> داتای پێشوو</a>
    </div>
    <?php if ($copies): ?>
    <div class="cmp-copies">
        <span><i class="fas fa-copy"></i> کۆپییەکان:</span>
        <?php foreach ($copies as $c): ?>
        <a href="?copy=<?= (int)$c['id'] ?>" class="<?= $copy && (int)$copy['id'] === (int)$c['id'] ? 'on' : '' ?>" title="<?= dsEsc(($c['created_by_name'] ?? '') . ' — ' . $c['updated_at']) ?>"><?= dsEsc($c['title']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</form>

<?php if (isset($_GET['copy_done'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> <?= $_GET['copy_done'] === 'deleted' ? 'کۆپییەکە سڕایەوە.' : 'کۆپییەکە پاشەکەوت کرا — ڕاپۆرتی ڕاستەقینە وەک خۆی ماوە.' ?></div>
<?php elseif (isset($_GET['copy_error'])): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle alert-icon"></i> <?= dsEsc((string)$_GET['copy_error']) ?></div>
<?php endif; ?>

<?php if ($copy): ?>
<div class="cmp-copybar">
    <i class="fas fa-copy"></i>
    <div><b>کۆپی: <?= dsEsc($copy['title']) ?></b><small>دەستکاری کراوە — ئەمە ڕاپۆرتی ڕاستەقینە نییە. ژمارە ڕاستەقینەکان لە داهات و سیستەمدا وەک خۆیانن.</small></div>
    <a href="<?= dsEsc($qs([])) ?>" class="cmp-tool"><i class="fas fa-rotate-left"></i> ڕاپۆرتی ڕاستەقینە</a>
    <form method="post" onsubmit="return confirm('ئەم کۆپییە بسڕدرێتەوە؟ ڕاپۆرتی ڕاستەقینە ناگۆڕێت.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="copy_action" value="delete"><input type="hidden" name="copy_id" value="<?= (int)$copy['id'] ?>">
        <button type="submit" class="cmp-tool is-danger"><i class="fas fa-trash"></i> سڕینەوەی کۆپی</button>
    </form>
</div>
<?php endif; ?>

<div class="cmp-sheet<?= $copy ? ' is-copy' : '' ?>" id="cmpSheet">
    <?php if ($copyReady): ?>
    <?php // shown while editing: the copy's name and its save buttons ?>
    <form method="post" class="cmp-editbar" id="cmpEditBar" hidden>
        <?= Csrf::field() ?>
        <input type="hidden" name="copy_action" value="save">
        <input type="hidden" name="copy_id" value="<?= $copy ? (int)$copy['id'] : '' ?>">
        <input type="hidden" name="doc" id="cmpDoc">
        <?php foreach (['a_from' => $aFrom, 'a_to' => $aTo, 'b_from' => $bFrom, 'b_to' => $bTo] as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= dsEsc($v) ?>"><?php endforeach; ?>
        <?php if ($avg): ?><input type="hidden" name="avg" value="1"><?php endif; ?>
        <i class="fas fa-pen-to-square"></i>
        <span class="cmp-editnote">دەستکاری لەسەر کۆپی: کلیک لە هەر خانەیەک بکە و بیگۆڕە. کۆی گشتی خۆی دەژمێردرێتەوە. ڕاپۆرتی ڕاستەقینە ناگۆڕێت.</span>
        <input type="text" name="title" maxlength="150" required placeholder="ناوی کۆپی، بۆ نموونە: بەراوردی مانگی 10 بۆ کۆبوونەوە"
               value="<?= dsEsc($copy['title'] ?? '') ?>">
        <button type="submit" class="cmp-go" data-save><i class="fas fa-save"></i> پاشەکەوت</button>
        <?php if ($copy): ?><button type="submit" class="cmp-tool" data-save name="as_new" value="1"><i class="fas fa-clone"></i> وەک کۆپیی نوێ</button><?php endif; ?>
        <button type="button" class="cmp-tool" id="cmpCancel"><i class="fas fa-xmark"></i> پاشگەزبوونەوە</button>
    </form>
    <div class="cmp-rowtools" id="cmpRowTools" hidden>
        <button type="button" data-row="add" title="ڕیزێکی نوێ لە ژێر ئەمە"><i class="fas fa-plus"></i></button>
        <button type="button" data-row="del" title="سڕینەوەی ئەم ڕیزە" class="is-del"><i class="fas fa-trash"></i></button>
    </div>
    <?php endif; ?>
    <?php foreach ($tablesHtml as $tableHtml): ?>
    <div class="cmp-scroll"><?= $tableHtml ?></div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    const sheet = document.getElementById('cmpSheet');
    // پڕ بە شاشە: the two tables alone over the whole screen (Esc leaves it)
    const fullBtn = document.getElementById('cmpFull');
    fullBtn.addEventListener('click', function () {
        if (document.fullscreenElement) { document.exitFullscreen(); return; }
        if (sheet.requestFullscreen) sheet.requestFullscreen();
    });
    document.addEventListener('fullscreenchange', function () {
        fullBtn.innerHTML = document.fullscreenElement
            ? '<i class="fas fa-compress"></i> دەرچوون لە شاشەی پڕ' : '<i class="fas fa-expand"></i> پڕ بە شاشە';
    });

    // ── دەستکاری لەسەر کۆپی: every cell editable; saving makes a copy ──
    const editBtn = document.getElementById('cmpEdit');
    const bar = document.getElementById('cmpEditBar');
    const tools = document.getElementById('cmpRowTools');
    if (!editBtn || !bar) return;
    let row = null;

    // a cell's number: «1,925,000», «−12», «نیە» (none) → 0; anything else is not a number
    const num = function (t) {
        t = t.replace(/[,\s‎‏]/g, '').replace('−', '-');
        if (t === '' || t === 'نیە' || t === 'نەبوو') return 0;
        return /^-?\d+(\.\d+)?$/.test(t) ? parseFloat(t) : null;
    };
    const fmt = function (v, decimals) {
        if (decimals) return (Math.round(v * 10) / 10).toString();
        const s = Math.abs(Math.round(v)).toLocaleString('en-US');
        return v < 0 ? '−' + s : s;
    };
    // کۆی گشتی again from the rows above it, column by column (money and counts only)
    const retotal = function (table) {
        const tot = table.querySelector('tfoot tr.tot');
        if (!tot) return;
        Array.from(tot.cells).forEach(function (cell, i) {
            const money = cell.classList.contains('n'), count = cell.classList.contains('c');
            if (!money && !count) return;
            let sum = 0, ok = false;
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                const td = tr.cells[i];
                if (!td) return;
                const v = num(td.innerText);
                if (v !== null) { sum += v; ok = true; }
            });
            if (ok) cell.textContent = fmt(sum, count);
        });
    };

    editBtn.addEventListener('click', function () {
        sheet.classList.add('is-editing');
        bar.hidden = false;
        editBtn.disabled = true;
        sheet.querySelectorAll('.cr th, .cr td, .cr-title').forEach(function (c) { c.contentEditable = 'true'; });
        bar.querySelector('input[name="title"]').focus();
        sheet.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    document.getElementById('cmpCancel').addEventListener('click', function () {
        if (!sheet.querySelector('.is-changed') || confirm('گۆڕانکارییەکان پاشەکەوت نەکراون. پاشگەزببیتەوە؟')) location.reload();
    });

    sheet.addEventListener('input', function (e) {
        const td = e.target.closest('td');
        if (!td) return;
        td.classList.add('is-changed');
        if (td.closest('tbody')) retotal(td.closest('table'));
    });

    // + / ✕ beside the row being edited
    sheet.addEventListener('focusin', function (e) {
        const tr = e.target.closest('tbody tr');
        if (!tr || !sheet.classList.contains('is-editing')) return;
        row = tr;
        const r = tr.getBoundingClientRect();
        tools.style.top = (r.top + r.height / 2 - 17) + 'px';
        tools.style.left = Math.max(4, r.left - 70) + 'px';
        tools.hidden = false;
    });
    window.addEventListener('scroll', function () { tools.hidden = true; }, true);
    tools.addEventListener('mousedown', function (e) { e.preventDefault(); });
    tools.addEventListener('click', function (e) {
        const b = e.target.closest('button');
        if (!b || !row) return;
        const table = row.closest('table');
        if (b.dataset.row === 'del') {
            if (!confirm('ئەم ڕیزە لە کۆپییەکە بسڕدرێتەوە؟')) return;
            row.remove();
        } else {
            const copy = row.cloneNode(true);
            Array.from(copy.cells).forEach(function (c, i) { c.textContent = i === 0 ? 'ڕیزی نوێ' : ''; c.contentEditable = 'true'; c.classList.add('is-changed'); });
            row.after(copy);
            copy.cells[0].focus();
        }
        tools.hidden = true;
        row = null;
        retotal(table);
    });

    // the tables as plain text (titles, headings, rows of cells), for the copy
    bar.addEventListener('submit', function () {
        const rows = function (list) {
            return Array.from(list).map(function (tr) {
                return { c: tr.className.replace('is-changed', '').trim(), cells: Array.from(tr.cells).map(function (td) {
                    return { t: td.innerText.trim(), c: td.className.replace('is-changed', '').trim() };
                }) };
            });
        };
        const tables = Array.from(sheet.querySelectorAll('table.cr')).map(function (t) {
            const title = t.previousElementSibling && t.previousElementSibling.classList.contains('cr-title') ? t.previousElementSibling.innerText.trim() : '';
            return {
                title: title,
                cols: Array.from(t.querySelectorAll('thead th')).map(function (th) { return { t: th.innerText.trim(), w: parseFloat(th.getAttribute('width')) || 4 }; }),
                body: rows(t.querySelectorAll('tbody tr')),
                foot: rows(t.querySelectorAll('tfoot tr')),
            };
        });
        document.getElementById('cmpDoc').value = JSON.stringify({ tables: tables });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
