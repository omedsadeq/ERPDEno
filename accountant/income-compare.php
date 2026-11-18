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
.cmp-editnote{flex:1 1 260px;font-size:12px;line-height:1.9}
.cmp-editnote code{display:inline-block;direction:ltr;padding:0 6px;border-radius:5px;background:#fff;border:1px solid #ddd6fe;color:#5b21b6;font:12px/18px Consolas,Menlo,monospace}
.cmp-editbar input[type=text]{flex:1 1 220px;height:40px;border:1px solid #c4b5fd;border-radius:10px;padding:0 10px;font:inherit;font-size:13px;background:#fff}
.cmp-sheet.is-editing [contenteditable]{cursor:text}
.cmp-sheet.is-editing .cr td:hover,.cmp-sheet.is-editing .cr th:hover{box-shadow:inset 0 0 0 1px #a78bfa}
.cmp-sheet.is-editing .cr td:focus,.cmp-sheet.is-editing .cr th:focus,.cmp-sheet.is-editing .cr-title:focus{outline:2px solid #7c3aed;outline-offset:-2px;background:#fff}
.cmp-sheet.is-editing .cr td.is-changed{background:#ede9fe !important}
.cmp-rowtools{position:fixed;z-index:60;display:flex;gap:4px;padding:3px;border-radius:9px;background:#fff;box-shadow:0 6px 18px -6px rgba(15,23,42,.35);border:1px solid #ddd6fe}
.cmp-rowtools[hidden]{display:none}
.cmp-rowtools button{width:28px;height:28px;border:0;border-radius:7px;background:#f5f3ff;color:#6d28d9;cursor:pointer}
.cmp-rowtools button:hover{background:#7c3aed;color:#fff}
.cmp-rowtools button.is-del{background:#fff1f2;color:#be123c}
.cmp-rowtools button.is-del:hover{background:#e11d48;color:#fff}
.cmp-rowtools button.is-grip{cursor:grab;touch-action:none;background:#f1f5f9;color:#475569}
.cmp-rowtools button:disabled{opacity:.35;cursor:default;background:#f5f3ff;color:#6d28d9}
.cmp-rowtools .sep{width:1px;margin:3px 1px;background:#e9e5ff}
.cmp-sheet.is-editing .cr tbody tr.is-row{box-shadow:inset 0 0 0 2px #a78bfa}
.cmp-sheet.is-editing .cr tbody tr.is-dragging{opacity:.75;box-shadow:inset 0 0 0 2px #7c3aed;background:#f5f3ff}
.cmp-sheet.is-dragging-row,.cmp-sheet.is-dragging-row *{cursor:grabbing !important;user-select:none}
/* the sheet over the whole screen */
/* ژمێردن وەک ئێکسڵ: letters over the columns, numbers on the rows, T1/T2 on the tables */
.cmp-sheet.is-editing{counter-reset:crtab}
.cmp-sheet.is-editing .cr-title{counter-increment:crtab}
.cmp-sheet.is-editing .cr-title::before{content:'T' counter(crtab);display:inline-block;margin-inline-end:8px;padding:0 6px;border-radius:6px;background:#7c3aed;color:#fff;font:700 11px/18px Consolas,Menlo,monospace}
.cmp-sheet.is-editing table.cr{counter-reset:crcol crrow}
.cmp-sheet.is-editing .cr thead th{counter-increment:crcol}
.cmp-sheet.is-editing .cr thead th::before{content:counter(crcol, upper-alpha);display:block;margin-bottom:2px;color:#7c3aed;font:700 10.5px/1 Consolas,Menlo,monospace}
.cmp-sheet.is-editing .cr tbody tr,.cmp-sheet.is-editing .cr tfoot tr{counter-increment:crrow}
.cmp-sheet.is-editing .cr tbody tr>td:first-child::before,.cmp-sheet.is-editing .cr tfoot tr>td:first-child::before{content:counter(crrow);display:inline-block;min-width:16px;margin-inline-end:6px;padding:0 4px;border-radius:5px;background:#ede9fe;color:#6d28d9;font:700 10px/16px Consolas,Menlo,monospace;text-align:center}
.cmp-sheet.is-editing .cr td[data-f]{background:#ecfdf5 !important;color:#065f46}
.cmp-sheet.is-editing .cr td[data-f].is-err{background:#fef2f2 !important;color:#b91c1c}
.cmp-sheet.is-editing .cr td.is-formula{direction:ltr;font-family:Consolas,Menlo,monospace;color:#0f172a;background:#fff !important}
.cmp-fx{position:fixed;z-index:61;max-width:420px;padding:6px 10px;border-radius:9px;background:#0f172a;color:#e2e8f0;font:12px/1.5 Consolas,Menlo,monospace;direction:ltr;box-shadow:0 8px 20px -8px rgba(15,23,42,.5);pointer-events:none}
.cmp-fx[hidden]{display:none}
.cmp-fx b{color:#5eead4}
.cmp-fx .err{color:#fca5a5;font-family:inherit}
/* کلیکی ڕاست لەسەر ڕیز: the row menu, as in Excel */
.cmp-ctx{position:fixed;z-index:70;min-width:220px;padding:5px;border-radius:12px;background:#fff;border:1px solid #ddd6fe;box-shadow:0 14px 34px -10px rgba(15,23,42,.4)}
.cmp-ctx[hidden]{display:none}
.cmp-ctx .hd{padding:4px 10px 6px;font-size:11.5px;color:#7c3aed;font-weight:700;border-bottom:1px solid #f1edff;margin-bottom:4px}
.cmp-ctx button{display:flex;align-items:center;gap:10px;width:100%;padding:7px 10px;border:0;border-radius:8px;background:none;font:inherit;font-size:13px;color:#1e293b;text-align:start;cursor:pointer}
.cmp-ctx button i{width:16px;text-align:center;color:#7c3aed}
.cmp-ctx button:hover,.cmp-ctx button:focus{background:#f5f3ff;outline:none}
.cmp-ctx button:disabled{opacity:.4;cursor:default;background:none}
.cmp-ctx button.is-del,.cmp-ctx button.is-del i{color:#be123c}
.cmp-ctx button.is-del:hover{background:#fff1f2}
.cmp-ctx button kbd{margin-inline-start:auto;font:11px Consolas,Menlo,monospace;color:#94a3b8}
.cmp-ctx .sep{height:1px;margin:4px 6px;background:#f1edff}
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
        <?php else: ?>
        <button type="button" class="cmp-tool is-edit" disabled title="خشتەی income_compare_copies لە داتابەیس دروست نەکرا — بڕوانە لۆگی هەڵەکانی PHP"><i class="fas fa-pen-to-square"></i> دەستکاری لەسەر کۆپی (ئامادە نییە)</button>
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
        <span class="cmp-editnote">دەستکاری وەک ئێکسڵ: خانە بگۆڕە، ڕیز بە ⠿ ڕاکێشە یان زیاد/دووبارە/ڕەش بکەوە (Alt+↑↓، Enter). ڕاپۆرتی ڕاستەقینە ناگۆڕێت.
            <br><b>ژمێردن:</b> <code>=</code> بنووسە — پیت ستوونە، ژمارە ڕیزە:
            <code>=B3+C3</code> <code>=SUM(B1:B5)</code> <code>=B3*20%</code> <code>=T1!B3-T2!B3</code>
            · کرتە لە خانەیەک ناونیشانەکەی دەخاتە ناو فۆرمولەکە. <b>کلیکی ڕاست</b> لە هەر شوێنێکی خشتە: ڕیز یان ستوون زیاد/ڕەش بکە.</span>
        <button type="button" class="cmp-tool" id="cmpUndo" disabled title="گەڕاندنەوەی دوا کاری ڕیز (Ctrl+Z لە دەرەوەی خانە)"><i class="fas fa-rotate-left"></i> گەڕاندنەوە</button>
        <input type="text" name="title" maxlength="150" required placeholder="ناوی کۆپی، بۆ نموونە: بەراوردی مانگی 10 بۆ کۆبوونەوە"
               value="<?= dsEsc($copy['title'] ?? '') ?>">
        <button type="submit" class="cmp-go" data-save><i class="fas fa-save"></i> پاشەکەوت</button>
        <?php if ($copy): ?><button type="submit" class="cmp-tool" data-save name="as_new" value="1"><i class="fas fa-clone"></i> وەک کۆپیی نوێ</button><?php endif; ?>
        <button type="button" class="cmp-tool" id="cmpCancel"><i class="fas fa-xmark"></i> پاشگەزبوونەوە</button>
    </form>
    <div class="cmp-fx" id="cmpFx" hidden></div>
    <div class="cmp-ctx" id="cmpCtx" hidden role="menu">
        <div class="hd"></div>
        <div data-part="row">
            <button type="button" data-ctx="above" role="menuitem"><i class="fas fa-arrow-up-from-bracket"></i> ڕیزێک لە سەرەوە زیاد بکە</button>
            <button type="button" data-ctx="below" role="menuitem"><i class="fas fa-plus"></i> ڕیزێک لە خوارەوە زیاد بکە</button>
            <button type="button" data-ctx="dup" role="menuitem"><i class="fas fa-clone"></i> دووبارەکردنەوەی ئەم ڕیزە</button>
            <button type="button" data-ctx="up" role="menuitem"><i class="fas fa-arrow-up"></i> بۆ سەرەوە <kbd>Alt+↑</kbd></button>
            <button type="button" data-ctx="down" role="menuitem"><i class="fas fa-arrow-down"></i> بۆ خوارەوە <kbd>Alt+↓</kbd></button>
            <button type="button" data-ctx="del" class="is-del" role="menuitem"><i class="fas fa-trash"></i> ڕەشکردنەوەی ئەم ڕیزە</button>
            <div class="sep"></div>
        </div>
        <div data-part="table">
            <button type="button" data-ctx="top" role="menuitem"><i class="fas fa-angles-up"></i> ڕیزێک لە سەرەتای خشتە</button>
            <button type="button" data-ctx="end" role="menuitem"><i class="fas fa-angles-down"></i> ڕیزێک لە کۆتایی خشتە</button>
        </div>
        <div data-part="col">
            <div class="sep"></div>
            <button type="button" data-ctx="colRight" role="menuitem"><i class="fas fa-arrow-right"></i> ستوونێک لە لای ڕاست</button>
            <button type="button" data-ctx="colLeft" role="menuitem"><i class="fas fa-arrow-left"></i> ستوونێک لە لای چەپ</button>
            <button type="button" data-ctx="colDel" class="is-del" role="menuitem"><i class="fas fa-trash"></i> ڕەشکردنەوەی ئەم ستوونە</button>
        </div>
    </div>
    <div class="cmp-rowtools" id="cmpRowTools" hidden>
        <button type="button" data-row="grip" class="is-grip" title="ڕاکێشان بۆ گواستنەوەی ڕیزەکە"><i class="fas fa-grip-vertical"></i></button>
        <button type="button" data-row="up" title="بۆ سەرەوە (Alt+↑)"><i class="fas fa-arrow-up"></i></button>
        <button type="button" data-row="down" title="بۆ خوارەوە (Alt+↓)"><i class="fas fa-arrow-down"></i></button>
        <span class="sep"></span>
        <button type="button" data-row="add" title="ڕیزێکی نوێ لە ژێر ئەمە"><i class="fas fa-plus"></i></button>
        <button type="button" data-row="dup" title="دووبارەکردنەوەی ئەم ڕیزە"><i class="fas fa-clone"></i></button>
        <button type="button" data-row="del" title="ڕەشکردنەوەی ئەم ڕیزە" class="is-del"><i class="fas fa-trash"></i></button>
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
        t = t.replace(/[,\s‎‏]/g, '').replace('−', '-').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
        if (t === '' || t === 'نیە' || t === 'نەبوو') return 0;
        if (/^[+-]?\d+(\.\d+)?%$/.test(t)) return parseFloat(t) / 100;   // 12% → 0.12, as in Excel
        return /^[+-]?\d+(\.\d+)?$/.test(t) ? parseFloat(t) : null;
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
            if ((!money && !count) || cell.dataset.f) return;   // a formula there is the user's own
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

    // started from the button: the copy's name is asked and the sheet comes
    // into view; from a right click on a row (quiet): it stays where it is
    let quietStart = false;
    editBtn.addEventListener('click', function () {
        const quiet = quietStart;
        quietStart = false;
        sheet.classList.add('is-editing');
        bar.hidden = false;
        editBtn.disabled = true;
        sheet.querySelectorAll('.cr th, .cr td, .cr-title').forEach(function (c) { c.contentEditable = 'true'; });
        ensureIds();
        recompute();
        if (quiet) return;
        bar.querySelector('input[name="title"]').focus();
        sheet.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    document.getElementById('cmpCancel').addEventListener('click', function () {
        if (!(sheet.querySelector('.is-changed') || !document.getElementById('cmpUndo').disabled) || confirm('گۆڕانکارییەکان پاشەکەوت نەکراون. پاشگەزببیتەوە؟')) location.reload();
    });

    sheet.addEventListener('input', function (e) {
        const td = e.target.closest('td');
        if (!td) return;
        td.classList.add('is-changed');
        if (td.classList.contains('is-formula') || td.innerText.trim().charAt(0) === '=') { showFx(td); return; }
        recompute();
    });

    // ── ژمێردن وەک ئێکسڵ ────────────────────────────────────────────
    // A cell that starts with = is a formula. On screen its references are
    // Excel's (B3 = column B, row 3 of the same table; T2!B3 = the second
    // table); inside, data-f keeps them by row id — [r12c1] — so moving,
    // adding or deleting rows never points a formula at the wrong row (a
    // deleted one gives #REF!). Its value is what the cell shows and saves.
    const fxBox = document.getElementById('cmpFx');
    let nextRid = 1;
    const allRows = function () { return Array.from(sheet.querySelectorAll('table.cr tbody tr, table.cr tfoot tr')); };
    const tableRows = function (t) { return Array.from(t.querySelectorAll('tbody tr, tfoot tr')); };
    const ensureIds = function () {
        allRows().forEach(function (tr) { const r = parseInt(tr.dataset.r, 10); if (r >= nextRid) nextRid = r + 1; });
        allRows().forEach(function (tr) { if (!tr.dataset.r) tr.dataset.r = nextRid++; });
    };
    const letter = function (i) { let s = ''; i++; while (i > 0) { const m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; };
    const colIndex = function (s) { let n = 0; s.toUpperCase().split('').forEach(function (ch) { n = n * 26 + ch.charCodeAt(0) - 64; }); return n - 1; };
    const rowById = function (id) { return sheet.querySelector('table.cr tr[data-r="' + id + '"]'); };
    const plain = function (s) {   // Kurdish digits and signs as typed → the plain ones
        return s.replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
            .replace(/×/g, '*').replace(/÷/g, '/').replace(/[−–]/g, '-').replace(/،/g, ',').replace(/٫/g, '.').replace(/٪/g, '%');
    };
    // B3 / T2!B3 → [r12c1]; a reference to no row is kept as #REF!
    const toInternal = function (text, cell) {
        const mine = cell.closest('table'), list = tables();
        return plain(text).replace(/(?:\b[Tt](\d+)!)?\b([A-Za-z]{1,2})(\d+)\b/g, function (m, t, col, row) {
            const table = t ? list[parseInt(t, 10) - 1] : mine;
            const tr = table && tableRows(table)[parseInt(row, 10) - 1];
            return tr ? '[r' + tr.dataset.r + 'c' + colIndex(col) + ']' : '#REF!';
        });
    };
    // [r12c1] → B3 as the rows stand now
    const toDisplay = function (f, cell) {
        const mine = cell.closest('table'), list = tables();
        return f.replace(/\[r(\d+)c(\d+)\]/g, function (m, rid, col) {
            const tr = rowById(rid);
            if (!tr) return '#REF!';
            const table = tr.closest('table');
            return (table === mine ? '' : 'T' + (list.indexOf(table) + 1) + '!') + letter(parseInt(col, 10)) + (tableRows(table).indexOf(tr) + 1);
        });
    };
    const fmtV = function (v) {
        if (Math.abs(v - Math.round(v)) < 1e-9) return fmt(v, false);
        const s = (Math.round(Math.abs(v) * 100) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 });
        return v < 0 ? '−' + s : s;
    };

    // the reader: + − × ÷ ^, brackets, a trailing %, ranges (B1:B5) and SUM / AVERAGE / MIN / MAX / COUNT / ABS / ROUND
    const FUNCS = {
        SUM: function (a) { return a.reduce(function (x, y) { return x + y; }, 0); },
        AVERAGE: function (a) { if (!a.length) throw new Error('AVERAGE بەتاڵە'); return a.reduce(function (x, y) { return x + y; }, 0) / a.length; },
        MIN: function (a) { return a.length ? Math.min.apply(null, a) : 0; },
        MAX: function (a) { return a.length ? Math.max.apply(null, a) : 0; },
        COUNT: function (a) { return a.length; },
        ABS: function (a) { return Math.abs(a[0] || 0); },
        ROUND: function (a) { const p = Math.pow(10, a[1] || 0); return Math.round((a[0] || 0) * p) / p; },
    };
    FUNCS.AVG = FUNCS.AVERAGE; FUNCS.کۆ = FUNCS.SUM; FUNCS.تێکڕا = FUNCS.AVERAGE;
    const evaluate = function (src, valueOf) {
        const toks = [];
        const re = /\s*(?:(\d+(?:\.\d+)?)|(\[r\d+c\d+\])|(#REF!)|([A-Za-z\u0600-\u06FF_]+)|(\S))/gy;
        let m;
        while (re.lastIndex < src.length && (m = re.exec(src))) {
            if (m[0].trim() === '') break;
            toks.push(m[1] ? { k: 'n', v: parseFloat(m[1]) } : m[2] ? { k: 'ref', v: m[2] } : m[3] ? { k: 'bad' } : m[4] ? { k: 'id', v: m[4] } : { k: 'op', v: m[5] });
        }
        let i = 0;
        const peek = function () { return toks[i] || { k: 'end' }; };
        const isOp = function (o) { const t = peek(); return t.k === 'op' && t.v === o; };
        const need = function (o) { if (!isOp(o)) throw new Error('«' + o + '» نەدۆزرایەوە'); i++; };
        const scalar = function (v) { if (Array.isArray(v)) throw new Error('ماوە (:) تەنها لە ناو SUM و ئەوانەدا'); return v; };
        const cellRef = function (tok) { const p = /\[r(\d+)c(\d+)\]/.exec(tok); return { rid: p[1], col: parseInt(p[2], 10) }; };
        function expr() { let a = term(); while (isOp('+') || isOp('-')) { const o = toks[i++].v; const b = term(); a = o === '+' ? scalar(a) + scalar(b) : scalar(a) - scalar(b); } return a; }
        function term() { let a = power(); while (isOp('*') || isOp('/')) { const o = toks[i++].v; const b = scalar(power()); if (o === '/' && Math.abs(b) < 1e-12) throw new Error('دابەشکردن بەسەر سفر'); a = o === '*' ? scalar(a) * b : scalar(a) / b; } return a; }
        function power() { const a = unary(); if (isOp('^')) { i++; return Math.pow(scalar(a), scalar(power())); } return a; }
        function unary() { if (isOp('-')) { i++; return -scalar(unary()); } if (isOp('+')) { i++; return unary(); } let a = primary(); while (isOp('%')) { i++; a = scalar(a) / 100; } return a; }
        function primary() {
            const t = peek();
            if (t.k === 'n') { i++; return t.v; }
            if (t.k === 'bad') throw new Error('#REF! — ئەو ڕیز یان ستوونە نەماوە');
            if (t.k === 'ref') {
                i++;
                if (isOp(':')) {
                    i++;
                    const e = peek();
                    if (e.k !== 'ref') throw new Error('کۆتایی ماوەکە نییە');
                    i++;
                    return range(cellRef(t.v), cellRef(e.v));
                }
                const r = cellRef(t.v);
                return valueOf(r.rid, r.col, false);
            }
            if (t.k === 'id') {
                const name = t.v.toUpperCase(), fn = FUNCS[name] || FUNCS[t.v];
                if (!fn) throw new Error('«' + t.v + '» ناناسرێت');
                i++;
                need('(');
                const args = [];
                if (!isOp(')')) {
                    do { const v = expr(); Array.isArray(v) ? v.forEach(function (x) { args.push(x); }) : args.push(v); } while (isOp(',') && ++i);
                }
                need(')');
                return fn(args);
            }
            if (isOp('(')) { i++; const v = expr(); need(')'); return v; }
            throw new Error(t.k === 'end' ? 'فۆرمولەکە تەواو نییە' : 'لە «' + (t.v || '') + '» دا هەڵەیە');
        }
        // B1:D4 — the rows between the two as they stand now, in one table
        function range(a, b) {
            const ta = rowById(a.rid), tb = rowById(b.rid);
            if (!ta || !tb) throw new Error('#REF! — ئەو ڕیز یان ستوونە نەماوە');
            if (ta.closest('table') !== tb.closest('table')) throw new Error('ماوە دەبێت لە یەک خشتەدا بێت');
            const rows = tableRows(ta.closest('table'));
            const r1 = Math.min(rows.indexOf(ta), rows.indexOf(tb)), r2 = Math.max(rows.indexOf(ta), rows.indexOf(tb));
            const c1 = Math.min(a.col, b.col), c2 = Math.max(a.col, b.col);
            const out = [];
            for (let r = r1; r <= r2; r++) for (let c = c1; c <= c2; c++) {
                const v = valueOf(rows[r].dataset.r, c, true);
                if (v !== null) out.push(v);
            }
            return out;
        }
        const v = expr();
        if (peek().k !== 'end') throw new Error('لە «' + (peek().v || '') + '» دا هەڵەیە');
        return scalar(v);
    };

    // every formula worked out again, then کۆی گشتی, then the formulas once more
    // (one may read the total). A circle of references is an error, not a hang.
    const workOut = function () {
        const memo = new Map(), busy = new Set();
        const valueOf = function (rid, col, inRange) {
            const tr = rowById(rid);
            if (!tr) throw new Error('#REF! — ئەو ڕیز یان ستوونە نەماوە');
            const cell = tr.cells[col];
            if (!cell) return inRange ? null : 0;
            if (cell.dataset.f) return compute(cell);
            const v = num(cell.innerText);
            return v === null ? (inRange ? null : 0) : v;
        };
        const compute = function (cell) {
            if (memo.has(cell)) { const m = memo.get(cell); if (m instanceof Error) throw m; return m; }
            if (busy.has(cell)) throw new Error('فۆرمولەکە بازنەیی دەبێتەوە (خۆی دەخوێنێتەوە)');
            busy.add(cell);
            try {
                const f = cell.dataset.f;
                const v = evaluate(f.charAt(0) === '#' && f.indexOf('#REF!') !== 0 ? f.slice(1) : f, valueOf);
                if (!isFinite(v)) throw new Error('ئەنجام ژمارە نییە');
                memo.set(cell, v);
                return v;
            } catch (err) {
                memo.set(cell, err);
                throw err;
            } finally { busy.delete(cell); }
        };
        sheet.querySelectorAll('table.cr td[data-f]').forEach(function (cell) {
            if (cell.classList.contains('is-formula')) return;   // being typed
            try {
                cell.textContent = fmtV(compute(cell));
                cell.classList.remove('is-err');
                cell.removeAttribute('data-err');
            } catch (err) {
                cell.textContent = '#هەڵە';
                cell.classList.add('is-err');
                cell.dataset.err = err.message;
            }
        });
    };
    const recompute = function () {
        workOut();
        tables().forEach(retotal);
        workOut();
    };

    // the formula beside the cell being typed in or pointed at
    const showFx = function (cell) {
        if (!cell) { fxBox.hidden = true; return; }
        const r = cell.getBoundingClientRect();
        if (cell.classList.contains('is-formula')) {
            fxBox.innerHTML = '';
            fxBox.textContent = cell.innerText.trim();
        } else if (cell.dataset.f) {
            fxBox.innerHTML = '';
            const b = document.createElement('b'); b.textContent = '=' + toDisplay(cell.dataset.f, cell);
            fxBox.appendChild(b);
            if (cell.dataset.err) { const s = document.createElement('div'); s.className = 'err'; s.textContent = cell.dataset.err; fxBox.appendChild(s); }
        } else { fxBox.hidden = true; return; }
        fxBox.hidden = false;
        fxBox.style.left = Math.max(4, Math.min(window.innerWidth - fxBox.offsetWidth - 4, r.left)) + 'px';
        fxBox.style.top = (r.bottom + 4) + 'px';
    };
    sheet.addEventListener('mouseover', function (e) {
        if (!sheet.classList.contains('is-editing')) return;
        const cell = e.target.closest && e.target.closest('td[data-f]');
        const typing = sheet.querySelector('td.is-formula');
        showFx(typing || cell);
    });
    // into a formula cell: its formula to edit; out of any cell: = makes it one
    sheet.addEventListener('focusin', function (e) {
        if (!sheet.classList.contains('is-editing')) return;
        const cell = e.target.closest && e.target.closest('td');
        if (!cell || !cell.dataset.f) return;
        cell.classList.add('is-formula');
        cell.textContent = '=' + toDisplay(cell.dataset.f, cell);
        const sel = document.getSelection(), rg = document.createRange();
        rg.selectNodeContents(cell); rg.collapse(false); sel.removeAllRanges(); sel.addRange(rg);
        showFx(cell);
    });
    sheet.addEventListener('focusout', function (e) {
        if (!sheet.classList.contains('is-editing')) return;
        const cell = e.target.closest && e.target.closest('td');
        if (!cell) return;
        const text = cell.innerText.trim();
        const had = cell.dataset.f;
        cell.classList.remove('is-formula');
        if (text.charAt(0) === '=') {
            let f = toInternal(text.slice(1), cell);
            cell.dataset.f = f;
            if (f !== had) { cell.classList.add('is-changed'); }
        } else if (had) {
            delete cell.dataset.f;   // a plain value typed over the formula
            cell.classList.remove('is-err');
        }
        fxBox.hidden = true;
        recompute();
    });
    // while a formula is being typed, a click on another cell puts its address in (as in Excel)
    sheet.addEventListener('mousedown', function (e) {
        const typing = sheet.querySelector('td.is-formula') || (document.activeElement && document.activeElement.closest && document.activeElement.closest('td'));
        if (!sheet.classList.contains('is-editing') || !typing || typing.innerText.trim().charAt(0) !== '=') return;
        const target = e.target.closest && e.target.closest('table.cr tbody td, table.cr tfoot td');
        if (!target || target === typing) return;
        const text = typing.innerText.trim();
        if (!/[=+\-*\/(,:^]\s*$/.test(text)) return;   // only where a reference may stand
        e.preventDefault();
        const table = target.closest('table'), list = tables();
        const ref = (table === typing.closest('table') ? '' : 'T' + (list.indexOf(table) + 1) + '!') + letter(target.cellIndex) + (tableRows(table).indexOf(target.parentNode) + 1);
        typing.textContent = text + ref;
        typing.classList.add('is-formula', 'is-changed');
        const sel = document.getSelection(), rg = document.createRange();
        rg.selectNodeContents(typing); rg.collapse(false); sel.removeAllRanges(); sel.addRange(rg);
        showFx(typing);
    });
    window.addEventListener('scroll', function () { fxBox.hidden = true; }, true);

    // ── rows as in Excel: drag ⠿, ↑ ↓, add, duplicate, delete — and گەڕاندنەوە ──
    const undoBtn = document.getElementById('cmpUndo');
    const undo = [];
    const tables = function () { return Array.from(sheet.querySelectorAll('table.cr')); };
    const remember = function () {   // the tables as they are, before a row changes
        undo.push(tables().map(function (t) { return t.innerHTML; }));
        if (undo.length > 50) undo.shift();
        undoBtn.disabled = false;
    };
    undoBtn.addEventListener('click', function () {
        const last = undo.pop();
        if (!last) return;
        tables().forEach(function (t, i) { t.innerHTML = last[i]; t.querySelectorAll('tr.is-row,tr.is-dragging').forEach(function (tr) { tr.classList.remove('is-row', 'is-dragging'); }); });
        undoBtn.disabled = !undo.length;
        pick(null);
        ensureIds();
        recompute();
    });

    const place = function () {      // the tools beside the chosen row (outside the table when there is room)
        if (!row) { tools.hidden = true; return; }
        tools.hidden = false;
        const r = row.getBoundingClientRect(), w = tools.offsetWidth, h = tools.offsetHeight;
        // a table wider than the screen scrolls sideways: only its visible part counts
        const box = (row.closest('.cmp-scroll') || row).getBoundingClientRect();
        const left = Math.max(r.left, box.left, 0);
        const outside = left - w - 8 >= 4;
        tools.style.left = (outside ? left - w - 8 : left + 4) + 'px';
        tools.style.top = (outside ? r.top + r.height / 2 - h / 2 : r.top - h - 4) + 'px';
        tools.querySelector('[data-row="up"]').disabled = !row.previousElementSibling;
        tools.querySelector('[data-row="down"]').disabled = !row.nextElementSibling;
    };
    const pick = function (tr) {
        if (row) row.classList.remove('is-row');
        row = tr;
        if (row) row.classList.add('is-row');
        place();
    };
    const move = function (tr, dir) {   // −1 up, +1 down, within its own part of the table
        const other = dir < 0 ? tr.previousElementSibling : tr.nextElementSibling;
        if (!other) return;
        remember();
        dir < 0 ? other.before(tr) : other.after(tr);
        place();
        recompute();
    };
    const blank = function (tr, label) {
        const r = tr.cloneNode(true);
        r.classList.remove('is-row', 'is-dragging');
        r.dataset.r = nextRid++;
        Array.from(r.cells).forEach(function (c, i) {
            if (label !== undefined) { c.textContent = i === 0 ? label : ''; delete c.dataset.f; c.classList.remove('is-err'); }
            c.classList.remove('is-formula');
            c.contentEditable = 'true';
            c.classList.add('is-changed');
        });
        return r;
    };

    sheet.addEventListener('focusin', function (e) {
        if (!sheet.classList.contains('is-editing')) return;
        pick(e.target.closest('tbody tr'));
    });
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
    tools.addEventListener('mousedown', function (e) { e.preventDefault(); });
    tools.addEventListener('click', function (e) {
        const b = e.target.closest('button');
        if (!b || !row || b.dataset.row === 'grip') return;
        const table = row.closest('table');
        const act = b.dataset.row;
        if (act === 'up' || act === 'down') { move(row, act === 'up' ? -1 : 1); return; }
        remember();
        if (act === 'del') {
            const next = row.nextElementSibling || row.previousElementSibling;
            row.remove();
            row = null;
            pick(next);
        } else {
            const r = blank(row, act === 'add' ? 'ڕیزی نوێ' : undefined);
            row.after(r);
            r.cells[0].focus();
            if (act === 'add') document.getSelection().selectAllChildren(r.cells[0]);
        }
        retotal(table);
        recompute();
    });

    // ── کلیکی ڕاست لەسەر ڕیز: add above / below anywhere, as in Excel ──
    // A right click on any row (the report too: it starts editing a copy)
    // opens the menu; «لە سەرەوە» on row 14 makes the new row 14 and the old
    // one 15. Formulas follow their rows, so nothing they read moves.
    const ctx = document.getElementById('cmpCtx');
    let ctxRow = null, ctxAt = null, ctxTable = null, ctxCol = null;
    const ctxClose = function () { ctx.hidden = true; ctxRow = null; ctxTable = null; ctxCol = null; };
    // a row to start from when the table has none left: one empty cell per column
    const templateRow = function (table) {
        const any = table.querySelector('tbody tr') || table.querySelector('tfoot tr');
        if (any) return any;
        const tr = document.createElement('tr');
        table.querySelectorAll('thead th').forEach(function () { const td = document.createElement('td'); tr.appendChild(td); });
        return tr;
    };
    const addRowAt = function (table, where) {
        remember();
        const r = blank(templateRow(table), 'ڕیزی نوێ');
        r.className = '';
        const body = table.tBodies[0] || table.appendChild(document.createElement('tbody'));
        where === 'top' ? body.prepend(r) : body.appendChild(r);
        retotal(table);
        recompute();
        pick(r);
        r.cells[0].focus();
        document.getSelection().selectAllChildren(r.cells[0]);
    };
    // a column in or out: every formula reading this table's columns at or past it moves with them
    const shiftCols = function (table, at, delta) {
        const ids = new Set(Array.from(table.querySelectorAll('tbody tr, tfoot tr')).map(function (tr) { return tr.dataset.r; }));
        sheet.querySelectorAll('table.cr td[data-f]').forEach(function (cell) {
            cell.dataset.f = cell.dataset.f.replace(/\[r(\d+)c(\d+)\]/g, function (m, rid, col) {
                col = parseInt(col, 10);
                if (!ids.has(rid) || col < at) return m;
                if (delta < 0 && col === at) return '#REF!';
                return '[r' + rid + 'c' + (col + delta) + ']';
            });
        });
    };
    const addCol = function (table, at) {
        remember();
        shiftCols(table, at, 1);
        Array.from(table.rows).forEach(function (tr) {
            const head = tr.parentNode.tagName === 'THEAD';
            const c = document.createElement(head ? 'th' : 'td');
            if (head) { c.textContent = 'ستوونی نوێ'; c.setAttribute('width', '5%'); }
            else c.className = 'n';
            c.contentEditable = 'true';
            c.classList.add('is-changed');
            tr.insertBefore(c, tr.cells[at] || null);
        });
        recompute();
        const th = table.querySelector('thead tr').cells[at];
        th.focus();
        document.getSelection().selectAllChildren(th);
    };
    const delCol = function (table, at) {
        if (table.querySelector('thead tr').cells.length < 2) return;
        remember();
        shiftCols(table, at, -1);
        Array.from(table.rows).forEach(function (tr) { if (tr.cells[at]) tr.deleteCell(at); });
        recompute();
    };
    const addRow = function (tr, where, copyIt) {
        remember();
        const r = blank(tr, copyIt ? undefined : 'ڕیزی نوێ');
        where === 'above' ? tr.before(r) : tr.after(r);
        retotal(tr.closest('table'));
        recompute();
        pick(r);
        r.cells[0].focus();
        if (!copyIt) document.getSelection().selectAllChildren(r.cells[0]);
    };
    sheet.addEventListener('contextmenu', function (e) {
        if (!e.target.closest) return;
        const title = e.target.closest('.cr-title');
        const cell = e.target.closest('table.cr th, table.cr td');
        // the site wraps tables (responsive-table-wrapper): the title's table is the one in its own box
        const table = cell ? cell.closest('table') : (title && title.closest('.cmp-scroll') ? title.closest('.cmp-scroll').querySelector('table.cr') : null);
        if (!table) return;
        e.preventDefault();
        if (!sheet.classList.contains('is-editing')) { quietStart = true; editBtn.click(); }
        const tr = cell && cell.parentNode.parentNode.tagName !== 'THEAD' ? cell.parentNode : null;
        ctxRow = tr; ctxTable = table; ctxCol = cell ? cell.cellIndex : null;
        pick(tr && tr.parentNode.tagName === 'TBODY' ? tr : null);
        const t = tables().indexOf(table) + 1;
        const colName = ctxCol !== null ? letter(ctxCol) : '';
        if (tr) {
            const n = Array.from(table.querySelectorAll('tbody tr, tfoot tr')).indexOf(tr) + 1;
            ctx.querySelector('.hd').textContent = 'ڕیزی ' + n + ' · ستوونی ' + colName + ' — T' + t + ' · ' + (tr.cells[0] ? tr.cells[0].innerText.trim().slice(0, 24) : '');
            ctx.querySelector('[data-ctx="up"]').disabled = !tr.previousElementSibling;
            ctx.querySelector('[data-ctx="down"]').disabled = !tr.nextElementSibling;
        } else {
            ctx.querySelector('.hd').textContent = (cell ? 'ستوونی ' + colName + ' — ' : '') + 'خشتەی T' + t;
        }
        ctx.querySelector('[data-part="row"]').hidden = !tr;
        ctx.querySelector('[data-part="col"]').hidden = ctxCol === null;
        ctx.querySelector('[data-ctx="colDel"]').disabled = table.querySelector('thead tr').cells.length < 2;
        ctx.hidden = false;
        const w = ctx.offsetWidth, h = ctx.offsetHeight;
        ctx.style.left = Math.max(4, Math.min(window.innerWidth - w - 4, e.clientX - (document.dir === 'rtl' || getComputedStyle(sheet).direction === 'rtl' ? w : 0))) + 'px';
        ctx.style.top = Math.max(4, Math.min(window.innerHeight - h - 4, e.clientY)) + 'px';
        ctxAt = { row: (tr || cell || title).getBoundingClientRect().top, menu: parseFloat(ctx.style.top), el: tr || cell || title };
        ctx.querySelector('button:not(:disabled)').focus({ preventScroll: true });
    });
    ctx.addEventListener('mousedown', function (e) { e.preventDefault(); });
    ctx.addEventListener('click', function (e) {
        const b = e.target.closest('button');
        if (!b || b.disabled || !ctxTable) return;
        const tr = ctxRow, table = ctxTable, col = ctxCol, act = b.dataset.ctx;
        ctxClose();
        if (act === 'top' || act === 'end') { addRowAt(table, act); return; }
        if (act === 'colRight') { addCol(table, col); return; }       // RTL: the right is before it
        if (act === 'colLeft') { addCol(table, col + 1); return; }
        if (act === 'colDel') {
            const th = table.querySelector('thead tr').cells[col];
            if (confirm('ستوونی «' + (th ? th.innerText.trim() : letter(col)) + '» ڕەش بکرێتەوە؟ (گەڕاندنەوە دەیگەڕێنێتەوە)')) delCol(table, col);
            return;
        }
        if (!tr) return;
        if (act === 'above' || act === 'below') addRow(tr, act, false);
        else if (act === 'dup') addRow(tr, 'below', true);
        else if (act === 'up' || act === 'down') { move(tr, act === 'up' ? -1 : 1); pick(tr.parentNode.tagName === 'TBODY' ? tr : null); }
        else if (act === 'del') {
            remember();
            const table = tr.closest('table'), next = tr.nextElementSibling || tr.previousElementSibling;
            tr.remove();
            if (row === tr) row = null;
            pick(next && next.parentNode.tagName === 'TBODY' ? next : null);
            retotal(table);
            recompute();
        }
    });
    document.addEventListener('mousedown', function (e) { if (!ctx.hidden && !ctx.contains(e.target)) ctxClose(); });
    document.addEventListener('keydown', function (e) {
        if (ctx.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); ctxClose(); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const list = Array.from(ctx.querySelectorAll('button:not(:disabled)'));
            const i = list.indexOf(document.activeElement);
            list[(i + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length].focus();
        }
    });
    // a scroll carries the menu with its row; it closes once the row leaves the window
    window.addEventListener('scroll', function () {
        if (ctx.hidden || !ctxAt) return;
        const r = ctxAt.el.getBoundingClientRect();
        if (!ctxAt.el.isConnected || r.bottom < 0 || r.top > window.innerHeight) { ctxClose(); return; }
        ctx.style.top = (ctxAt.menu + r.top - ctxAt.row) + 'px';
    }, true);
    window.addEventListener('resize', ctxClose);

    // ⠿ held down: the row follows the pointer up and down its table
    tools.querySelector('[data-row="grip"]').addEventListener('pointerdown', function (e) {
        if (!row) return;
        e.preventDefault();
        const grip = e.currentTarget, body = row.parentNode, dragged = row;
        let moved = false;
        try { grip.setPointerCapture(e.pointerId); } catch (err) { /* moves still reach the grip while over it */ }
        dragged.classList.add('is-dragging');
        sheet.classList.add('is-dragging-row');
        const onMove = function (ev) {
            const el = document.elementFromPoint(ev.clientX, ev.clientY);
            const over = el && el.closest ? el.closest('tr') : null;
            if (!over || over === dragged || over.parentNode !== body) {
                // past the first or last row: to that end
                const rows = body.rows, first = rows[0], last = rows[rows.length - 1];
                if (ev.clientY < first.getBoundingClientRect().top && first !== dragged) { if (!moved) remember(); moved = true; first.before(dragged); }
                else if (ev.clientY > last.getBoundingClientRect().bottom && last !== dragged) { if (!moved) remember(); moved = true; last.after(dragged); }
                place();
                return;
            }
            const r = over.getBoundingClientRect();
            const below = ev.clientY > r.top + r.height / 2;
            if ((below && over.nextElementSibling === dragged) || (!below && over.previousElementSibling === dragged)) return;
            if (!moved) remember();
            moved = true;
            below ? over.after(dragged) : over.before(dragged);
            place();
        };
        const onUp = function () {
            grip.removeEventListener('pointermove', onMove);
            grip.removeEventListener('pointerup', onUp);
            grip.removeEventListener('pointercancel', onUp);
            dragged.classList.remove('is-dragging');
            sheet.classList.remove('is-dragging-row');
            place();
            if (moved) recompute();
        };
        grip.addEventListener('pointermove', onMove);
        grip.addEventListener('pointerup', onUp);
        grip.addEventListener('pointercancel', onUp);
    });

    // keys as in Excel: Enter → the cell below, Alt+↑/↓ moves the row, Shift+Enter a new line in the cell
    sheet.addEventListener('keydown', function (e) {
        if (!sheet.classList.contains('is-editing')) return;
        const cell = e.target.closest('td, th');
        const tr = cell && cell.parentNode;
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown') && tr && tr.parentNode.tagName === 'TBODY') {
            e.preventDefault();
            move(tr, e.key === 'ArrowUp' ? -1 : 1);
            cell.focus();
            return;
        }
        if (e.key === 'Enter' && !e.shiftKey && cell) {
            e.preventDefault();
            const rows = Array.from(cell.closest('table').rows);
            const next = rows[rows.indexOf(tr) + (e.ctrlKey ? -1 : 1)];
            const target = next && next.cells[Math.min(cell.cellIndex, next.cells.length - 1)];
            if (target) { target.focus(); document.getSelection().selectAllChildren(target); }
        }
    });
    document.addEventListener('keydown', function (e) {
        const inCell = e.target.closest && e.target.closest('[contenteditable="true"]');
        if (!inCell && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && sheet.classList.contains('is-editing') && undo.length) {
            e.preventDefault();
            undoBtn.click();
        }
    });

    // the tables as plain text (titles, headings, rows of cells), for the copy
    bar.addEventListener('submit', function () {
        const rows = function (list) {
            return Array.from(list).map(function (tr) {
                return { c: tr.className.replace('is-changed', '').trim(), r: parseInt(tr.dataset.r, 10) || 0, cells: Array.from(tr.cells).map(function (td) {
                    const cell = { t: td.innerText.trim(), c: td.className.replace(/is-(changed|err|formula)/g, '').trim() };
                    if (td.dataset.f) cell.f = td.dataset.f;
                    return cell;
                }) };
            });
        };
        const tables = Array.from(sheet.querySelectorAll('table.cr')).map(function (t) {
            // the title sits in the table's own box, not always right before it (the site wraps tables)
            const box = t.closest('.cmp-scroll'), head = box && box.querySelector('.cr-title');
            const title = head ? head.innerText.trim() : '';
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
