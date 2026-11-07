<?php
/**
 * بەراورد — two periods of the income sheet, laid out as the clinic's own paper
 * (_income_compare_report.php): the second period on top with how it compares,
 * the first below. This month against the same month last year, two months
 * against two months, a year against a year, any dates. Each period's figures
 * are the income sheet's own (accountant/_income_data.php, run once per
 * period), so this page can never disagree with the sheet; the expense kinds
 * switched on there count here too.
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

$auth = new Auth();
$auth->requireLogin();
if (!Permission::getInstance()->can('page.accountant_financial')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

/** The income sheet of one period: its rows and its totals. */
function income_compare_period(string $from, string $to): array
{
    $saved = $_GET;
    $_GET = ['from' => $from, 'to' => $to];
    try {
        include __DIR__ . '/_income_data.php';
    } finally {
        $_GET = $saved;
    }
    $byKey = [];
    foreach ($rows as $r) {
        $byKey[$r['kind'] === 'doctor' ? 'd' . $r['id'] : $r['kind']] = $r;
    }
    return [
        'from' => $from, 'to' => $to, 'days' => (int)round((strtotime($to) - strtotime($from)) / 86400) + 1,
        'rows' => $byKey, 'totals' => $totals, 'expenses' => $expensesAll, 'centerAfter' => $centerAfterExpenses,
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

$A = income_compare_period($aFrom, $aTo);
$B = income_compare_period($bFrom, $bTo);
// A monthly average: a period's figure scaled to an average month's length.
$scale = fn(array $p) => $avg ? 30.4375 / max(1, $p['days']) : 1.0;
$qs = fn(array $over) => '?' . http_build_query(array_merge(
    ['a_from' => $aFrom, 'a_to' => $aTo, 'b_from' => $bFrom, 'b_to' => $bTo] + ($avg ? ['avg' => 1] : []), $over));

$periodTitle = fn(string $which, array $p) => $which . ': ' . ($p['from'] === $p['to'] ? $p['from'] : $p['from'] . ' بۆ ' . $p['to'])
    . ' · ' . $p['days'] . ' ڕۆژ' . ($avg ? ' — تێکڕای مانگانە' : '');
$reportB = income_compare_report($B, $A, $periodTitle('ماوەی دووەم', $B), $scale($B), $scale($A));
$reportA = income_compare_report($A, null, $periodTitle('ماوەی یەکەم', $A), $scale($A));

// ── The same two tables as a PDF, A4 across ─────────────────────────
if (!empty($_GET['pdf'])) {
    require_once __DIR__ . '/../services/InvoicePdfService.php';
    require __DIR__ . '/../includes/clinic_branding.php';
    $viewer = $auth->getCurrentUser();
    $html = '<style>
body { font-family: nrt; direction: rtl; color: #0f172a; }
.hd { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
.hd td { vertical-align: middle; }
.ku { font-size: 13pt; font-weight: bold; color: #0369a1; }
.sub { font-size: 8.5pt; color: #475569; }
.cr-title { margin: 3mm 0 1mm; padding: 1.2mm 2mm; background: #fde68a; font-size: 9pt; font-weight: bold; }
.cr { width: 100%; border-collapse: collapse; }
.cr th { background: #fde68a; font-size: 6.6pt; padding: 1.2mm 0.8mm; border: 0.6px solid #555; }
.cr td { font-size: 6.8pt; padding: 1.3mm 0.8mm; border: 0.6px solid #777; }
.cr td.nm { font-weight: bold; white-space: nowrap; }
.cr td.n { text-align: center; white-space: nowrap; }
.cr td.c, .cr td.rt, .cr td.ch { text-align: center; white-space: nowrap; }
.cr td.b { font-weight: bold; }
.cr tr.dept td { background: #f1f5f9; }
.cr tr.tot td { background: #fde68a; font-weight: bold; }
.cr tr.chg td { font-size: 6.4pt; color: #334155; }
.cr tr.end td { font-weight: bold; }
</style>
<table class="hd"><tr><td><div class="ku">' . dsEsc($clinicName) . '</div><div class="sub">بەراوردی داهات و سەردانەکانی دکتۆرەکان</div></td>
<td style="text-align:left;"><div class="sub" style="direction:ltr;">' . date('Y-m-d H:i') . '</div></td></tr></table>'
        . $reportB . $reportA;
    $pdf = new InvoicePdfService([
        'orientation' => 'L', 'format' => 'A4', 'show_header' => 0, 'show_footer' => 0,
        'margin_top_mm' => 8, 'margin_bottom_mm' => 12, 'margin_left_mm' => 8, 'margin_right_mm' => 8,
    ]);
    $pdf->setTitle('بەراورد — ' . $bFrom . ' / ' . $aFrom);
    $pdf->getMpdf()->SetHTMLFooter(
        '<table width="100%" style="font-family:nrt;font-size:7pt;color:#64748b;border-top:1px solid #e2e8f0;"><tr>'
        . '<td style="text-align:right;">چاپکراوە لەلایەن ' . dsEsc($viewer['full_name'] ?? '') . '</td>'
        . '<td style="text-align:left;direction:ltr;">{PAGENO} / {nbpg}</td></tr></table>'
    );
    $pdf->setContent($html);
    $pdf->output('compare-' . $bFrom . '_' . $bTo . '-vs-' . $aFrom . '_' . $aTo . '.pdf', 'I');
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
.cr tr.dept td{background:#f8fafc}
.cr tr.tot td{background:#fef3c7;font-weight:800}
.cr tr.chg td{background:#fffbeb;font-size:11.5px}
.cr tr.end td{font-weight:800}
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
        <a class="cmp-pdf" href="<?= dsEsc($qs(['pdf' => 1])) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="cmp-presets">
        <?php foreach ($presets as [$name, $af, $at, $bf, $bt]): ?>
        <a href="<?= dsEsc($qs(['a_from' => $ymd($af), 'a_to' => $ymd($at), 'b_from' => $ymd($bf), 'b_to' => $ymd($bt)])) ?>"><?= dsEsc($name) ?></a>
        <?php endforeach; ?>
    </div>
</form>

<div class="cmp-sheet">
    <div class="cmp-scroll"><?= $reportB ?></div>
    <div class="cmp-scroll"><?= $reportA ?></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
