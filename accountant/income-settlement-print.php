<?php
/**
 * حیسابی دکتۆر — the full settlement report, printed for signing.
 *
 * Everything comes from the settlement's frozen snapshot (IncomeSettlement),
 * so a reprint is always the paper that was signed: the income, how it was
 * split, the cash moved (to قاسەی لێزان, to the doctor) and left in his box,
 * his visitors, his hours and his work — then the doctor's and the
 * receiver's signatures. A4, through the browser's print dialog.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/IncomeSettlement.php';

$auth = new Auth();
$auth->requireLogin();
$viewer = $auth->getCurrentUser();
$settlement = (new IncomeSettlement())->get((int)($_GET['id'] ?? 0));
if (!$settlement) {
    die('حیساب نەدۆزرایەوە');
}
// Finance staff, or the doctor his own settlement.
$isOwn = ($viewer['role'] ?? '') === 'doctor' && (int)$viewer['id'] === (int)$settlement['doctor_id'];
if (!$isOwn && !Permission::getInstance()->can('page.accountant_financial')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
require __DIR__ . '/../includes/clinic_branding.php';

$r = $settlement['report'];
$money = fn($v) => CashboxLedger::money(CashboxLedger::decimal(IncomeSettlement::toCents((float)$v)));
$signed = fn($v) => (float)$v < 0 ? '(' . $money(abs((float)$v)) . ')' : $money($v);
$stored = fn($v) => CashboxLedger::money($v);
$rate = rtrim(rtrim(number_format((float)$settlement['doctor_rate'], 2, '.', ''), '0'), '.') . '%';
$period = '<bdi>' . htmlspecialchars($settlement['period_from']) . '</bdi> بۆ <bdi>' . htmlspecialchars($settlement['period_to']) . '</bdi>';
$isCancelled = $settlement['status'] !== 'done';
$work = $r['work'] ?? ['total' => 0, 'treatment_visits' => 0, 'checkup_visits' => 0];
$check = $r['checkupBreakdown'] ?? ['given' => 0, 'pending' => 0, 'new_exam' => 0, 'old_exam' => 0];
$top = array_slice($r['topTreatments'] ?? [], 0, 14);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
// مافی سەنتەر: its share plus the lab/store costs it paid = cash − doctor's share.
$expenses = CashboxLedger::decimal(CashboxLedger::cents($settlement['lab_expense']) + CashboxLedger::cents($settlement['store_expense']));
$centerDue = CashboxLedger::decimal(max(0, CashboxLedger::cents($settlement['cash_in']) - max(0, CashboxLedger::cents($settlement['doctor_share']))));
$autoPrint = ($_GET['new'] ?? '') === '1';
$backUrl = APP_URL . '/accountant/income-distribution.php?from=' . urlencode($settlement['period_from']) . '&to=' . urlencode($settlement['period_to']);
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>حیسابی دکتۆر — <?= $e($settlement['settlement_no']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
@page { size: A4 portrait; margin: 9mm; }
:root { --ink: #0f172a; --teal: #0f766e; --teal-soft: #e6f6f4; --violet: #5b21b6; --violet-soft: #f1ecfd; --line: #cfd8e3; }
* { box-sizing: border-box; }
html, body { margin: 0; background: #e9edf2; color: var(--ink); font-family: var(--app-font-family, 'NRT', Tahoma, sans-serif); }
.bar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: center; gap: 8px; padding: 10px; background: #0f2a3a; }
.bar button, .bar a { padding: 8px 18px; border: 0; border-radius: 6px; background: var(--teal); color: #fff; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
.bar a { background: #475569; }
.page { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm 11mm; background: #fff; box-shadow: 0 8px 26px rgba(15, 30, 50, .18); position: relative; }

.head { display: flex; align-items: center; gap: 10px; padding-bottom: 5px; border-bottom: 2.5px solid var(--teal); }
.head img { width: 20mm; height: auto; }
.head .clinic { flex: 1; line-height: 1.5; }
.head .clinic b { font-size: 15pt; }
.head .clinic div { font-size: 8.5pt; color: #475569; }
.head .doc-no { text-align: center; padding: 4px 12px; border: 1.5px solid var(--teal); border-radius: 6px; background: var(--teal-soft); }
.head .doc-no small { display: block; font-size: 7.5pt; font-weight: 700; color: var(--teal); }
.head .doc-no b { font-size: 12pt; color: var(--teal); direction: ltr; display: block; }

.title { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 6px 0 5px; padding: 6px 12px; border-radius: 8px; color: #fff; background: linear-gradient(90deg, #0b4f4a, var(--teal) 65%, #14a3a1); }
.title b { font-size: 14pt; }
.title span { font-size: 9pt; opacity: .9; }

.info { display: grid; grid-template-columns: 1.25fr 1.65fr 1.15fr .75fr; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; }
.info div { padding: 4px 8px; border-inline-start: 1px solid var(--line); font-size: 8.5pt; color: #475569; }
.info div:first-child { border-inline-start: 0; }
.info b { display: block; font-size: 10pt; color: var(--ink); white-space: nowrap; }

.kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; margin-top: 7px; }
.kpi { border: 1px solid var(--line); border-radius: 7px; padding: 6px 10px; }
.kpi small { display: block; font-size: 8pt; color: #64748b; font-weight: 700; }
.kpi b { display: block; font-size: 15pt; font-variant-numeric: tabular-nums; direction: ltr; text-align: right; }
.kpi.doc { border: 2px solid var(--violet); background: var(--violet-soft); }
.kpi.doc b, .kpi.doc small { color: var(--violet); }
.kpi.ctr { border-color: #8fd3cc; background: var(--teal-soft); }
.kpi.ctr b, .kpi.ctr small { color: var(--teal); }

.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 8px; }
.box { border: 1px solid var(--line); border-radius: 7px; overflow: hidden; }
.box h3 { margin: 0; padding: 4px 9px; font-size: 9.5pt; background: #eef3f7; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 6px; }
.box h3 i { font-style: normal; color: var(--teal); }
.kv { width: 100%; border-collapse: collapse; }
.kv td { padding: 2.2px 9px; font-size: 9pt; border-bottom: 1px solid #edf1f5; }
.kv tr:last-child td { border-bottom: 0; }
.kv td.v { text-align: left; direction: ltr; white-space: nowrap; font-weight: 700; font-variant-numeric: tabular-nums; }
.kv tr.t td { background: #f4f7fa; font-weight: 800; border-top: 1px solid var(--line); }
.kv tr.sub td { background: #fafbfd; color: #64748b; font-size: 8pt; font-weight: 700; }
.kv .pos { color: #047857; } .kv .neg { color: #b45309; } .kv .doc { color: var(--violet); } .kv .ctr { color: var(--teal); }

.tbl { width: 100%; border-collapse: collapse; }
.tbl th { padding: 3px 8px; font-size: 8.5pt; background: #f4f7fa; border-bottom: 1px solid var(--line); text-align: start; }
.tbl td { padding: 2.5px 8px; font-size: 8.8pt; border-bottom: 1px solid #edf1f5; }
.tbl .n { text-align: left; direction: ltr; font-variant-numeric: tabular-nums; white-space: nowrap; }
.tbl tr.t td { font-weight: 800; background: #f4f7fa; }

.notes { margin-top: 8px; padding: 5px 9px; border: 1px dashed var(--line); border-radius: 6px; font-size: 9pt; }
.ack { margin-top: 9px; padding: 7px 11px; border-radius: 7px; background: #f8fafc; border: 1px solid var(--line); font-size: 9.5pt; line-height: 1.9; }
.ack b { color: var(--violet); }
.signs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 10px; }
.sign { text-align: center; font-size: 9pt; }
.sign .line { height: 13mm; border-bottom: 1.3px solid #334155; margin-bottom: 4px; }
.sign b { display: block; font-size: 9.5pt; }
.sign small { color: #64748b; }
.foot { margin-top: 8px; display: flex; justify-content: space-between; font-size: 7.5pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 4px; }
.void { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.void span { transform: rotate(-24deg); font-size: 64pt; font-weight: 900; color: rgba(220, 38, 38, .14); border: 8px solid rgba(220, 38, 38, .14); padding: 4mm 12mm; border-radius: 10mm; }
.void-note { margin-top: 6px; padding: 5px 9px; border-radius: 6px; background: #fef2f2; color: #b91c1c; font-size: 9pt; font-weight: 700; }

@media print {
    html, body { background: #fff; }
    .bar { display: none !important; }
    .page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    /* a doctor with many kinds of work still gets one page: fitPage() below */
    .page-in { zoom: var(--fit, 1); }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .box, .signs, .ack { break-inside: avoid; }
}
</style>
</head>
<body>
<div class="bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a href="<?= $e($backUrl) ?>">گەڕانەوە بۆ دابەشکردنی داهات</a>
</div>

<div class="page">
    <?php if ($isCancelled): ?><div class="void"><span>هەڵوەشێنراوە</span></div><?php endif; ?>
    <div class="page-in">

    <div class="head">
        <img src="<?= $e($logoUrl) ?>" alt="" onerror="this.style.display='none'">
        <div class="clinic">
            <b><?= $e($clinicName) ?></b>
            <div><bdi><?= $e($clinicPhone1) ?> - <?= $e($clinicPhone2) ?></bdi></div>
            <div><?= $e($clinicAddress) ?></div>
        </div>
        <div class="doc-no"><small>ژمارەی حیساب</small><b><?= $e($settlement['settlement_no']) ?></b></div>
    </div>

    <div class="title">
        <b>حیسابی دکتۆر — دابەشکردنی داهات</b>
        <span>ڕاپۆرتی تەواوی داهات، سەردان، کات و سندوق</span>
    </div>

    <div class="info">
        <div>دکتۆر<b><?= $e($settlement['doctor_name'] ?? ($r['doctorName'] ?? '')) ?></b></div>
        <div>ماوەی حیساب<b><?= $period ?></b></div>
        <div>بەرواری حیساب<b><bdi><?= $e(substr($settlement['created_at'], 0, 16)) ?></bdi></b></div>
        <div>ڕێژەی دکتۆر<b><?= $e($rate) ?></b></div>
    </div>
    <?php if ($isCancelled): ?>
    <div class="void-note">ئەم حیسابە هەڵوەشێنراوەتەوە<?= $settlement['cancelled_at'] ? ' لە ' . $e(substr($settlement['cancelled_at'], 0, 16)) : '' ?><?= $settlement['cancel_reason'] ? ' — ' . $e($settlement['cancel_reason']) : '' ?></div>
    <?php endif; ?>

    <div class="kpis">
        <div class="kpi"><small>سافی داهات</small><b><?= $e($signed($settlement['net_amount'])) ?></b></div>
        <div class="kpi doc"><small>پشکی دکتۆر (<?= $e($rate) ?>)</small><b><?= $e($stored($settlement['doctor_share'])) ?></b></div>
        <div class="kpi ctr"><small>پشکی سەنتەر — قاسەی لێزان</small><b><?= $e($stored($settlement['center_share'])) ?></b></div>
    </div>

    <div class="cols">
        <div class="box">
            <h3><i>●</i> داهات</h3>
            <table class="kv">
                <tr><td>کۆی گشتی کار</td><td class="v"><?= $e($money($r['grossBilled'] ?? 0)) ?></td></tr>
                <tr><td>داشکاندن</td><td class="v neg">(<?= $e($money($r['invoiceDisc'] ?? 0)) ?>)</td></tr>
                <tr class="t"><td>کار دوای داشکاندن</td><td class="v"><?= $e($money($r['invoiceNet'] ?? 0)) ?></td></tr>
                <tr><td>بڕی ماوەی کاری ئەم ماوەیە (قەرز)</td><td class="v neg"><?= $e($money($r['newDebtToday'] ?? 0)) ?></td></tr>
                <tr><td>بڕی وەرگیراو</td><td class="v pos"><?= $e($money($r['treatmentCash'] ?? 0)) ?></td></tr>
                <tr><td>پارەی گەڕاوەی قەرزی کۆن</td><td class="v pos"><?= $e($money($r['oldDebtCollected'] ?? 0)) ?></td></tr>
                <tr class="t"><td>کۆی نەقدی وەرگیراو</td><td class="v"><?= $e($stored($settlement['cash_in'])) ?></td></tr>
                <tr><td>خەرجی تاقیگە</td><td class="v neg">(<?= $e($stored($settlement['lab_expense'])) ?>)</td></tr>
                <tr><td>خەرجی کۆگا</td><td class="v neg">(<?= $e($stored($settlement['store_expense'])) ?>)</td></tr>
                <tr class="t"><td>سافی</td><td class="v"><?= $e($signed($settlement['net_amount'])) ?></td></tr>
            </table>
        </div>
        <div class="box">
            <h3><i>●</i> دابەشکردن و سندوق</h3>
            <table class="kv">
                <tr><td>سافی</td><td class="v"><?= $e($signed($settlement['net_amount'])) ?></td></tr>
                <tr><td>پشکی دکتۆر (<?= $e($rate) ?>)</td><td class="v doc"><?= $e($stored($settlement['doctor_share'])) ?></td></tr>
                <tr><td>پشکی سەنتەر</td><td class="v ctr"><?= $e($stored($settlement['center_share'])) ?></td></tr>
                <tr><td>+ خەرجی تاقیگە و کۆگا (بۆ سەنتەر)</td><td class="v ctr"><?= $e($stored($expenses)) ?></td></tr>
                <tr class="t"><td>کۆی مافی سەنتەر</td><td class="v ctr"><?= $e($stored($centerDue)) ?></td></tr>
                <tr class="sub"><td colspan="2">سندوقی دکتۆر لە کاتی حیساب</td></tr>
                <tr><td>نەقدی ناو سندوق پێش حیساب</td><td class="v"><?= $e($stored($settlement['box_before'])) ?></td></tr>
                <tr><td>گواستراوە بۆ قاسەی لێزان</td><td class="v ctr">(<?= $e($stored($settlement['transferred_to_center'])) ?>)</td></tr>
                <tr><td>بڕی ڕاکێشان — درا بە دکتۆر</td><td class="v doc">(<?= $e($stored($settlement['paid_to_doctor'])) ?>)</td></tr>
                <tr class="t"><td>بڕی ماوەی ناو سندوق</td><td class="v"><?= $e($stored($settlement['box_after'])) ?></td></tr>
            </table>
        </div>
    </div>

    <div class="cols">
        <div class="box">
            <h3><i>●</i> سەردانیکەران</h3>
            <table class="kv">
                <tr class="t"><td>کۆی گشتی سەردانیکەران</td><td class="v"><?= $e(dsMoney($work['total'] ?? 0)) ?></td></tr>
                <tr><td>سەردانی ئیش (چارەسەر)</td><td class="v"><?= $e(dsMoney($work['treatment_visits'] ?? 0)) ?></td></tr>
                <tr><td>سەردانی پشکنین</td><td class="v"><?= $e(dsMoney($work['checkup_visits'] ?? 0)) ?></td></tr>
                <tr><td>پشکنینی نوێ / کۆن</td><td class="v"><?= $e(dsMoney($check['new_exam'] ?? 0)) ?> / <?= $e(dsMoney($check['old_exam'] ?? 0)) ?></td></tr>
                <tr><td>پشکنینی پێدراو / داواکراو</td><td class="v"><?= $e(dsMoney($check['given'] ?? 0)) ?> / <?= $e(dsMoney($check['pending'] ?? 0)) ?></td></tr>
                <tr><td>نەخۆشی نێردراو بۆ ئێکسڕەی</td><td class="v"><?= $e(dsMoney($r['xrayPatients'] ?? 0)) ?></td></tr>
            </table>
        </div>
        <div class="box">
            <h3><i>●</i> کات</h3>
            <table class="kv">
                <tr><td>ڕۆژی هاتن</td><td class="v"><?= $e(dsMoney($r['attendedDay'] ?? 0)) ?></td></tr>
                <tr class="t"><td>کاتژمێری هاتن</td><td class="v"><?= $e(dsHours($r['attendanceMinutes'] ?? null)) ?></td></tr>
                <tr><td>کاتی چارەسەر (ئیشکردن)</td><td class="v"><?= $e(dsHours($r['workMinutes'] ?? 0)) ?></td></tr>
                <tr><td>کاتی پشکنین</td><td class="v"><?= $e(dsHours($r['examMinutes'] ?? 0)) ?></td></tr>
                <tr><td>کاتی بەتاڵ</td><td class="v"><?= $e(dsHours($r['idleMinutes'] ?? null)) ?></td></tr>
                <tr><td>ژمارەی ئیشەکان</td><td class="v"><?= $e(dsMoney($r['treatmentCount'] ?? 0)) ?></td></tr>
            </table>
        </div>
    </div>

    <?php if ($top): ?>
    <div class="box" style="margin-top:8px;">
        <h3><i>●</i> جۆری ئیشەکان</h3>
        <table class="tbl">
            <thead><tr><th style="width:8mm;">#</th><th>جۆر</th><th class="n">ژمارە</th><th class="n">بڕ</th></tr></thead>
            <tbody>
            <?php foreach ($top as $i => $t): ?>
                <tr><td><?= $i + 1 ?></td><td><?= $e($t['treatment_name']) ?></td><td class="n"><?= $e(dsMoney($t['cnt'])) ?></td><td class="n"><?= $e(dsMoney($t['amt'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (count($r['topTreatments'] ?? []) > count($top)): ?>
                <tr><td></td><td colspan="3" style="color:#94a3b8;">+ <?= count($r['topTreatments']) - count($top) ?> جۆری تر</td></tr>
            <?php endif; ?>
                <tr class="t"><td></td><td>کۆ</td><td class="n"><?= $e(dsMoney($r['treatmentCount'] ?? 0)) ?></td><td class="n"><?= $e(dsMoney($r['treatmentAmount'] ?? 0)) ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($settlement['notes'])): ?>
    <div class="notes"><b>تێبینی:</b> <?= $e($settlement['notes']) ?></div>
    <?php endif; ?>

    <div class="ack">
        من، <b><?= $e($settlement['doctor_name'] ?? '') ?></b>، حیسابی داهاتی خۆمم بۆ ماوەی <?= $period ?> وەرگرت؛
        پشکی من (<?= $e($rate) ?>) <b><?= $e($stored($settlement['doctor_share'])) ?></b> دینارە و
        بڕی <b><?= $e($stored($settlement['paid_to_doctor'])) ?></b> دینارم بە نەقد وەرگرت.
    </div>

    <div class="signs">
        <div class="sign"><div class="line"></div><b>واژووی دکتۆر</b><small>وەرگرتنی حیساب — <?= $e($settlement['doctor_name'] ?? '') ?></small></div>
        <div class="sign"><div class="line"></div><b>واژووی وەرگر</b><small>ژمێریار / قاسەی لێزان — <?= $e($settlement['created_by_name'] ?? '') ?></small></div>
        <div class="sign"><div class="line"></div><b>مۆری سەنتەر</b><small><?= $e($clinicName) ?></small></div>
    </div>

    <div class="foot">
        <span>چاپکراوە لەلایەن <?= $e($viewer['full_name'] ?? '') ?> — <bdi><?= date('Y-m-d H:i') ?></bdi></span>
        <span><?= $settlement['signed_at'] ? 'واژووکراوە لە ' . $e(substr($settlement['signed_at'], 0, 10)) : '' ?></span>
        <span><bdi><?= $e($settlement['settlement_no']) ?></bdi></span>
    </div>
    </div>
</div>

<script>
// One A4 page: the printable height is 297mm − 2 × 9mm; a taller report is
// printed slightly smaller. Measured on screen at the printed width (192mm).
function fitPage() {
    const el = document.querySelector('.page-in');
    if (!el) return;
    el.style.removeProperty('--fit');
    const limit = 279 * 96 / 25.4 - 4;
    const h = el.offsetHeight;
    if (h > limit) el.style.setProperty('--fit', Math.max(0.7, limit / h).toFixed(3));
}
window.addEventListener('load', fitPage);
window.addEventListener('beforeprint', fitPage);
<?php if ($autoPrint): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
<?php endif; ?>
</script>
</body>
</html>
