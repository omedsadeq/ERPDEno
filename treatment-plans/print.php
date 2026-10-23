<?php
/**
 * پلانی چارەسەر — the printed plan, on the same A5 sheet as the case receipt
 * (cases/receipt.php): same letterhead, ruled rows, Palmer-cross table and
 * total boxes, so the two read as one family of documents.
 *
 * What makes it a plan and not a receipt, at a glance across the desk:
 *   - its own teal colour and a «پلانی چارەسەر · TREATMENT PLAN» title band
 *   - a tooth map: the planned teeth drawn and numbered with their table row
 *   - a «خەمڵاندن» stamp and no paid/remaining boxes — nothing was received.
 * Every figure is a خەمڵاندن; the money only exists once the work is
 * transferred onto a case (classes/TreatmentPlan.php::transferItems).
 *
 * One A5 page per copy: «کۆپی نەخۆش» goes home, «کۆپی کلینیک» stays in the
 * file. ?copies=1 prints the patient's copy only.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/AccessGuard.php';
require_once __DIR__ . '/../classes/TreatmentPlan.php';
require_once __DIR__ . '/../classes/DentalChart.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
if (!Permission::getInstance()->can('treatment_plan.view')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$planId = (int)($_GET['id'] ?? 0);
$plans = new TreatmentPlan();
$plan = $planId > 0 ? $plans->getById($planId) : null;
if (!$plan) {
    die('پلان نەدۆزرایەوە');
}
// Same isolation as the plan page: the examiner or the doctor it was sent to.
if (AccessGuard::isRestrictedDoctor() && !TreatmentPlan::doctorMaySee($plan, AccessGuard::doctorId())) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

// کێ چارەسەر دەکات؟ — printed when someone other than the examiner treats.
$printState = TreatmentPlan::designationState($plan);
$printTreating = null;
if ($printState === 'doctor' || $printState === 'chosen') {
    $printTreating = $plan['treating_doctor_name'] ?? null;
} elseif ($printState === 'awaiting_reception') {
    $printTreating = 'پێشوازی دیاری دەکات';
}

// «مەوعیدی داهاتوو»: the visit booked from this plan, else the patient's
// next visit with the treating doctor (the same strip every receipt prints).
require_once __DIR__ . '/../classes/NextAppointment.php';
$nextAppointment = null;
$planAppt = $plans->liveAppointment($plan);
if ($planAppt) {
    $apptTs = strtotime($planAppt['appointment_date'] . ' ' . $planAppt['appointment_time']);
    if ($apptTs !== false && $apptTs >= strtotime(date('Y-m-d H:i:00'))) {
        $apptDays = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
        $nextAppointment = [
            'date'         => date('Y-m-d', $apptTs),
            'time'         => date('H:i', $apptTs),
            'day'          => $apptDays[(int)date('w', $apptTs)],
            'date_display' => date('Y/m/d', $apptTs),
            'doctor_name'  => $planAppt['doctor_name'] ?: null,
        ];
    }
}
if (!$nextAppointment) {
    $nextAppointment = NextAppointment::forPatient((int)$plan['patient_id'], 0, (int)(TreatmentPlan::treatingDoctorId($plan) ?? 0));
}

// Cancelled lines were never part of the offer.
$items = array_values(array_filter($plans->getItems($planId), fn($i) => $i['status'] !== 'cancelled'));

$patientAge = null;
if (!empty($plan['date_of_birth'])) {
    try {
        $patientAge = (new DateTime())->diff(new DateTime($plan['date_of_birth']))->y;
    } catch (Exception $e) {
        $patientAge = null;
    }
}

// ── Lines, grouped the way the case receipt groups them ─────────────
// One row per procedure + price; its teeth go into the Palmer cross. Work
// already moved onto a case keeps its own row, marked as started.
$dentalChart = new DentalChart();
$groups = [];
$toothMap = [];                       // fdi => [rows[], work[], done]
$archRows = ['upper' => [], 'lower' => []];
$sumGross = $sumDiscount = $sumFinal = 0.0;
$sumMinutes = 0;
foreach ($items as $it) {
    $arch = in_array($it['arch_type'] ?? 'none', ['upper', 'lower'], true) ? $it['arch_type'] : 'none';
    $tooth = $arch === 'none' ? (int)($it['tooth_number'] ?? 0) : 0;
    $moved = $it['status'] === 'transferred';
    $key = ($it['treatment_id'] ?? 0) . '|' . $it['treatment_name'] . '|' . $it['unit_price'] . '|' . $arch
         . '|' . ($tooth ? 'tooth' : 'service') . '|' . ($moved ? 'moved' : 'planned');
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'row' => count($groups) + 1, 'name' => $it['treatment_name'], 'unit' => (float)$it['unit_price'],
            'arch' => $arch, 'tooth' => $tooth > 0, 'moved' => $moved, 'case' => $it['transferred_case_number'] ?? '',
            'UR' => [], 'UL' => [], 'LR' => [], 'LL' => [], 'qty' => 0, 'gross' => 0.0,
        ];
    }
    $g = &$groups[$key];
    if ($tooth > 0) {
        $q = $dentalChart->getQuadrant($tooth);
        if (isset($g[$q])) {
            $g[$q][] = $dentalChart->getPalmerNumber($tooth);
        }
        $toothMap[$tooth] = $toothMap[$tooth] ?? ['rows' => [], 'work' => [], 'done' => true];
        if (!in_array($g['row'], $toothMap[$tooth]['rows'], true)) {
            $toothMap[$tooth]['rows'][] = $g['row'];
        }
        $toothMap[$tooth]['work'][] = [
            'category' => $it['category'] ?? 'other', 'treatment_name' => $it['treatment_name'], 'tooth_surface' => $it['tooth_surface'] ?? '',
        ];
        $toothMap[$tooth]['done'] = $toothMap[$tooth]['done'] && $moved;
    } elseif ($arch !== 'none' && !in_array($g['row'], $archRows[$arch], true)) {
        $archRows[$arch][] = $g['row'];
    }
    $g['qty'] += (int)$it['quantity'];
    $g['gross'] += (float)$it['total_price'];
    unset($g);

    $sumGross += (float)$it['total_price'];
    $sumDiscount += (float)$it['discount'];
    $sumFinal += (float)$it['final_price'];
    $sumMinutes += (int)($it['estimated_minutes'] ?? 0);
}
$groups = array_values($groups);
$hasPrimary = (bool)array_filter(array_keys($toothMap), fn($t) => intdiv($t, 10) >= 5);
$minutesText = '';
if ($sumMinutes > 0) {
    $minutesText = $sumMinutes >= 60
        ? intdiv($sumMinutes, 60) . ' کاتژمێر' . ($sumMinutes % 60 ? ' و ' . ($sumMinutes % 60) . ' خولەک' : '')
        : $sumMinutes . ' خولەک';
}

// The plan's own table stays short so the sheet keeps its shape; a long plan
// simply runs on.
$rowsCount = max(4, count($groups));
$planDate = date('Y/n/j', strtotime($plan['created_at']));
$planNo = (string)$plan['plan_number'];
$noClass = mb_strlen($planNo) > 10 ? ' is-xlong' : (mb_strlen($planNo) > 6 ? ' is-long' : '');

// clinic_branding.php reads the settings through $db from the including scope.
$db = Database::getInstance();
require __DIR__ . '/../includes/clinic_branding.php';

$autoPrint = ($_GET['autoprint'] ?? '1') !== '0';
$copyCount = max(1, min(4, (int)($_GET['copies'] ?? 2)));
$copyLabels = [1 => 'کۆپی نەخۆش', 2 => 'کۆپی کلینیک'];
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پلانی چارەسەر — <?= htmlspecialchars($planNo) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
/* ── Sheet geometry: the case receipt's A5 pad ── */
@page { size: A5 portrait; margin: 6mm; }

:root { --ink: #1b2b3a; --plan: #0e7c7b; --plan-dark: #0a5c5b; --plan-soft: #e7f4f3; --stamp: #c2410c; }
* { box-sizing: border-box; }
html, body {
    margin: 0; padding: 0; background: #eceff3;
    font-family: var(--app-font-family, 'NRT', Tahoma, sans-serif); color: #111;
}
.sheet {
    width: 148mm; min-height: 210mm; margin: 12px auto; padding: 6mm 6mm 5mm;
    background: #fff; box-shadow: 0 6px 22px rgba(20, 40, 70, .18);
}

/* ── Letterhead (as the receipt, in the plan's colour) ── */
.rc-head { display: flex; align-items: center; gap: 8px; padding-bottom: 3px; border-bottom: 2px solid var(--plan); }
.rc-logo img { display: block; width: 17mm; height: auto; }
.rc-head-text { flex: 1 1 auto; text-align: right; line-height: 1.4; }
.rc-clinic-ku { font-size: 14pt; font-weight: 700; color: var(--ink); }
.rc-contact { font-size: 8.5pt; color: #3f4a56; direction: ltr; text-align: right; }
.rc-contact .ico, .rc-addr .ico { color: var(--plan); font-weight: 700; }
.rc-addr { font-size: 8pt; color: #4a5560; }

/* ── Title band: what this paper is ── */
.tp-band {
    display: flex; align-items: center; gap: 7px; margin-top: 4px; padding: 2px 9px;
    border-radius: 3mm; color: #fff; background: linear-gradient(90deg, var(--plan-dark), var(--plan) 60%, #14a3a1);
}
.tp-band svg { width: 4.6mm; height: 6.3mm; flex: 0 0 auto; }
.tp-band-title { font-size: 12pt; font-weight: 800; line-height: 1.2; }
.tp-band-en { display: block; font-size: 6.5pt; font-weight: 700; letter-spacing: 2.4px; opacity: .85; direction: ltr; text-align: right; }
.tp-copy {
    margin-inline-start: auto; padding: 2px 9px; border-radius: 999px; background: rgba(255, 255, 255, .18);
    border: 1px solid rgba(255, 255, 255, .55); font-size: 8pt; font-weight: 700; white-space: nowrap;
}

/* ── Identification block ── */
.rc-meta { display: flex; gap: 6px; margin-top: 4px; }
.rc-meta > div { flex: 1 1 50%; }
.rc-row { display: flex; align-items: baseline; gap: 5px; padding: 1px 0; font-size: 9pt; }
.rc-label { flex: 0 0 auto; font-weight: 700; color: var(--ink); white-space: nowrap; }
.rc-fill { flex: 1 1 auto; min-height: 14px; border-bottom: 1px dotted #7d8b99; padding: 0 4px 1px; font-size: 9.5pt; }
.rc-no-box { display: flex; align-items: center; gap: 6px; padding: 3px 6px; border: 1.5px solid var(--plan); border-radius: 1.5mm; margin-bottom: 3px; background: var(--plan-soft); }
.rc-no-caption { flex: 1 1 auto; font-size: 8pt; font-weight: 700; color: var(--plan-dark); line-height: 1.25; }
.rc-no-value { flex: 0 0 auto; font-size: 17pt; font-weight: 700; color: var(--plan-dark); letter-spacing: .5px; direction: ltr; }
.rc-no-value.is-long { font-size: 12pt; letter-spacing: 0; }
.rc-no-value.is-xlong { font-size: 10pt; letter-spacing: -.2px; }

/* ── Tooth map ── */
.tp-map-wrap { margin-top: 4px; padding: 2px 5px 2px; border: 1px solid #b9d9d7; border-radius: 2mm; background: #fbfefe; }
.tp-map-head { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; font-size: 7pt; color: #5a6672; }
.tp-map-head b { color: var(--plan-dark); font-size: 8pt; }
.tp-map { direction: ltr; }
.tp-jaw { display: flex; flex-direction: column; align-items: center; }
.tp-row { display: flex; justify-content: center; align-items: flex-end; }
.tp-jaw.is-lower .tp-row { align-items: flex-start; }
.tp-q { display: flex; }
.tp-qdiv { width: 0; margin: 1mm 1.2mm; border-left: 1px dashed #9fb3c4; }
.tp-t { position: relative; display: flex; flex-direction: column; align-items: center; width: 7.9mm; padding: .3mm 0; }
.tp-jaw.is-lower .tp-t { flex-direction: column-reverse; }
.tp-tn { font-size: 6pt; font-weight: 700; color: #94a3b8; line-height: 1.05; }
.tp-ts { width: 4.6mm; height: 6.3mm; }
.tp-ts svg { display: block; width: 100%; height: 100%; }
.tp-jaw.is-upper .tp-ts { transform: scaleY(-1); }
.tp-row.is-primary .tp-t { width: 7mm; }
.tp-row.is-primary .tp-ts { width: 3.7mm; height: 5mm; }
.tp-t.has .tp-tn { color: var(--plan-dark); }
.tp-t.has .tp-ts { background: radial-gradient(circle, #d8f0ee 45%, transparent 70%); }
.tp-rn {
    position: absolute; left: 50%; transform: translateX(-50%); bottom: -1.2mm; min-width: 3.6mm; padding: 0 .8mm;
    border-radius: 2mm; background: var(--plan); color: #fff; font-size: 5.8pt; font-weight: 800; line-height: 3.4mm; text-align: center; white-space: nowrap;
}
.tp-jaw.is-lower .tp-rn { bottom: auto; top: -1.2mm; }
.tp-t.is-done .tp-rn { background: #14713b; }
.tp-mid { display: flex; align-items: center; gap: 4px; margin: .8mm 0; font-size: 6pt; font-weight: 700; color: #94a3b8; }
.tp-mid i { flex: 1; border-top: 1px dashed #c8d3dd; }
.tp-arch { margin: .6mm auto; text-align: center; font-size: 7pt; font-weight: 700; color: var(--plan-dark); direction: rtl; line-height: 1.3; }
.tp-arch span { display: inline-block; padding: 0 2.4mm; border: 1px solid #9fd3cf; border-radius: 3mm; background: var(--plan-soft); }

/* ── Procedures table (the receipt's) ── */
.rc-table { width: 100%; border-collapse: collapse; margin-top: 5px; table-layout: fixed; }
.rc-table th, .rc-table td { border: 1px solid var(--ink); padding: 2px 4px; font-size: 9pt; text-align: center; vertical-align: middle; }
.rc-table thead th { background: var(--plan-soft); font-weight: 700; line-height: 1.2; color: var(--ink); }
.rc-table thead .en { display: block; font-size: 6.5pt; font-weight: 400; color: #5a6672; }
.rc-table tbody td { height: 7.4mm; }
.rc-c-no { width: 7%; }
.rc-c-desc { width: 32%; text-align: right !important; }
.rc-c-teeth { width: 18%; padding: 2px !important; }
.rc-c-qty { width: 10%; }
.rc-c-unit { width: 16.5%; }
.rc-c-total { width: 16.5%; font-weight: 700; }
.rc-c-no b { display: inline-block; min-width: 4.4mm; border-radius: 2.2mm; background: var(--plan); color: #fff; font-size: 7.5pt; line-height: 4.4mm; }
.rc-moved { display: block; font-size: 7pt; font-weight: 700; color: #14713b; }
.tooth-cross {
    display: grid; grid-template-columns: 1fr 1fr; width: 100%; max-width: 24mm; margin: 0 auto; direction: ltr;
    border: 1px solid #8a97a5; background: #fff; font-size: 7pt; line-height: 1.15; color: var(--ink);
}
.tooth-cross span { min-height: 3.2mm; padding: 0 2px; text-align: center; word-break: break-word; }
.tc-ur, .tc-ul { border-bottom: 1px solid var(--ink); }
.tc-ur, .tc-lr { border-right: 1px solid var(--ink); }
.rc-location { display: inline-block; padding: 1.2mm 2mm; border: .25mm solid #8fc9c4; border-radius: 4mm; background: var(--plan-soft); color: var(--plan-dark); font-size: 7pt; font-weight: 700; white-space: nowrap; }

/* ── Foot: the plan's facts and signatures | the estimate ── */
.rc-foot { display: flex; gap: 6px; margin-top: 5px; align-items: flex-start; }
.rc-foot-side { flex: 1 1 50%; }
.rc-foot-totals { flex: 1 1 50%; }
.rc-total-row { display: flex; align-items: center; gap: 5px; padding: 1.5px 0; font-size: 9pt; }
.rc-total-row .rc-label { flex: 0 0 24mm; text-align: left; }
.rc-total-box {
    flex: 1 1 auto; min-height: 6.4mm; padding: 0 6px; border: 1px solid var(--ink);
    font-size: 10.5pt; font-weight: 700; text-align: center; line-height: 6mm;
}
.rc-total-box.is-disc { color: var(--stamp); }
.rc-total-box.is-final { border: 2px double var(--plan-dark); color: var(--plan-dark); font-size: 12pt; background: var(--plan-soft); }
.tp-stamp {
    width: max-content; margin: 2mm auto 0; padding: .5mm 3.5mm; transform: rotate(-5deg);
    border: .55mm solid var(--stamp); border-radius: 2mm; color: var(--stamp); text-align: center; line-height: 1.3; opacity: .9;
}
.tp-stamp b { display: block; font-size: 10pt; font-weight: 800; letter-spacing: .5px; }
.tp-stamp small { display: block; font-size: 6pt; font-weight: 700; }
.tp-stamp small.en { letter-spacing: 1.5px; direction: ltr; }

/* ── Terms strip ── */
.rc-terms { margin-top: 5px; padding-top: 4px; border-top: 1.5px solid var(--plan); font-size: 7.5pt; color: #2b3742; line-height: 1.55; }
.rc-terms .rc-terms-lead { font-weight: 700; color: var(--plan-dark); }
.rc-terms ul { margin: 2px 0 0; padding: 0 14px 0 0; }

/* ── Screen-only toolbar ── */
.rc-bar { position: sticky; top: 0; z-index: 10; display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; padding: 10px; background: var(--ink); }
.rc-bar button, .rc-bar a {
    padding: 8px 18px; border: 0; border-radius: 6px; background: var(--plan); color: #fff;
    font-family: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer;
}
.rc-bar .rc-bar-alt { background: #48566a; }
.rc-bar .is-on { box-shadow: inset 0 0 0 2px #fff; }

@media print {
    html, body { background: #fff; }
    .rc-bar { display: none !important; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    /* A plan too long for one A5 is printed a little smaller instead of
       spilling onto a second page — set by fitSheets() below. */
    .sheet-in { zoom: var(--fit, 1); }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rc-table tr, .tp-map-wrap, .rc-foot { page-break-inside: avoid; break-inside: avoid; }
    .sheet + .sheet { page-break-before: always; break-before: page; }
}
</style>
</head>
<body>

<div class="rc-bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a class="rc-bar-alt<?= $copyCount === 2 ? ' is-on' : '' ?>" href="?id=<?= $planId ?>&copies=2&autoprint=0">٢ کۆپی</a>
    <a class="rc-bar-alt<?= $copyCount === 1 ? ' is-on' : '' ?>" href="?id=<?= $planId ?>&copies=1&autoprint=0">١ کۆپی</a>
    <a class="rc-bar-alt" href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$plan['patient_id'] ?>&tab=plans&plan=<?= $planId ?>">گەڕانەوە</a>
</div>

<?php for ($copyNo = 1; $copyNo <= $copyCount; $copyNo++): ?>
<div class="sheet"><div class="sheet-in">

    <div class="rc-head">
        <div class="rc-logo">
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($clinicNameEn) ?>" onerror="this.style.display='none'">
        </div>
        <div class="rc-head-text">
            <div class="rc-clinic-ku"><?= htmlspecialchars($clinicName) ?></div>
            <div class="rc-contact"><span class="ico">✆</span> <?= htmlspecialchars($clinicPhone1) ?> - <?= htmlspecialchars($clinicPhone2) ?></div>
            <div class="rc-addr"><span class="ico">◉</span> <?= htmlspecialchars($clinicAddress) ?></div>
        </div>
    </div>

    <div class="tp-band">
        <svg viewBox="0 0 44 60" aria-hidden="true"><path fill="#fff" d="M5,17 C5,6 11,3 22,3 C33,3 39,6 39,17 C39,23 37,27 34,30 C34.5,36 33,44 30.5,51 C29.5,54 27,54 26.5,51 C25.8,45 25,39 24.5,35 Q22,32 19.5,35 C19,39 18.2,45 17.5,51 C17,54 14.5,54 13.5,51 C11,44 9.5,36 10,30 C7,27 5,23 5,17 Z"/></svg>
        <div>
            <div class="tp-band-title">پلانی چارەسەر</div>
            <span class="tp-band-en">TREATMENT PLAN · ESTIMATE</span>
        </div>
        <?php if (isset($copyLabels[$copyNo]) && $copyCount > 1): ?>
        <span class="tp-copy"><?= $copyLabels[$copyNo] ?></span>
        <?php endif; ?>
    </div>

    <div class="rc-meta">
        <!-- RTL: this column prints on the left of the sheet, as on the receipt. -->
        <div>
            <div class="rc-no-box">
                <div class="rc-no-caption">پلانی چارەسەر<br>ژمارە :</div>
                <div class="rc-no-value<?= $noClass ?>"><?= htmlspecialchars($planNo) ?></div>
            </div>
            <div class="rc-row">
                <span class="rc-label">لاپەرە :</span>
                <span class="rc-fill"><?= htmlspecialchars($plan['patient_code'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">ڕێکەوت :</span>
                <span class="rc-fill"><?= htmlspecialchars($planDate) ?></span>
            </div>
        </div>
        <div>
            <div class="rc-row">
                <span class="rc-label">نەخۆش :</span>
                <span class="rc-fill"><?= htmlspecialchars($plan['patient_name']) ?><?= $patientAge !== null ? ' · ' . $patientAge . ' ساڵ' : '' ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">تەلەفۆن :</span>
                <span class="rc-fill" style="direction: ltr; text-align: right;"><?= htmlspecialchars($plan['patient_phone'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label"><?= $printTreating ? 'دکتۆری پشکنەر :' : 'دکتۆر :' ?></span>
                <span class="rc-fill"><?= htmlspecialchars($plan['doctor_name'] ?? '') ?></span>
            </div>
            <?php if ($printTreating): ?>
            <div class="rc-row">
                <span class="rc-label">چارەسەرکەر :</span>
                <span class="rc-fill"><?= htmlspecialchars($printTreating) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($plan['chief_complaint'])): ?>
    <div class="rc-row">
        <span class="rc-label">گلەیی :</span>
        <span class="rc-fill"><?= htmlspecialchars($plan['chief_complaint']) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($toothMap || $archRows['upper'] || $archRows['lower']): ?>
    <div class="tp-map-wrap">
        <div class="tp-map-head">
            <b>نەخشەی ددانەکان</b>
            <span>ژمارەی سەر ددان = ڕیزی خشتەکەی خوارەوە</span>
        </div>
        <div class="tp-map" data-copy="<?= $copyNo ?>"></div>
    </div>
    <?php endif; ?>

    <table class="rc-table">
        <thead>
            <tr>
                <th class="rc-c-no">ژ</th>
                <th class="rc-c-desc">ئیشی پێویست<span class="en">Treatment</span></th>
                <th class="rc-c-teeth">چارتی ددان<span class="en">Teeth</span></th>
                <th class="rc-c-qty">ژمارە<span class="en">Quantity</span></th>
                <th class="rc-c-unit">نرخی تاک<span class="en">Unit Price</span></th>
                <th class="rc-c-total">نرخی کۆ<span class="en">Total Price</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $g): ?>
            <tr>
                <td class="rc-c-no"><b><?= $g['row'] ?></b></td>
                <td class="rc-c-desc">
                    <?= htmlspecialchars($g['name']) ?>
                    <?php if ($g['moved']): ?>
                    <span class="rc-moved">✔ دەستی پێکراوە<?= $g['case'] ? ' — ' . htmlspecialchars($g['case']) : '' ?></span>
                    <?php endif; ?>
                </td>
                <td class="rc-c-teeth">
                    <?php if ($g['arch'] === 'upper'): ?>
                    <span class="rc-location">ماکزیلا / سەرەوە</span>
                    <?php elseif ($g['arch'] === 'lower'): ?>
                    <span class="rc-location">ماندیبل / خوارەوە</span>
                    <?php elseif (!$g['tooth']): ?>
                    <span class="rc-location">بێ ژمارەی ددان</span>
                    <?php else: ?>
                    <div class="tooth-cross">
                        <span class="tc-ur"><?= htmlspecialchars(implode(',', $g['UR'])) ?></span>
                        <span class="tc-ul"><?= htmlspecialchars(implode(',', $g['UL'])) ?></span>
                        <span class="tc-lr"><?= htmlspecialchars(implode(',', $g['LR'])) ?></span>
                        <span class="tc-ll"><?= htmlspecialchars(implode(',', $g['LL'])) ?></span>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="rc-c-qty"><?= number_format($g['qty']) ?></td>
                <td class="rc-c-unit"><?= number_format($g['unit']) ?></td>
                <td class="rc-c-total"><?= number_format($g['gross']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php for ($i = count($groups) + 1; $i <= $rowsCount; $i++): ?>
            <tr>
                <td class="rc-c-no" style="color:#9aa7b4;"><?= $i ?></td>
                <td class="rc-c-desc"><?= $i === 1 && !$groups ? '<span style="color:#9aa7b4;">هێشتا هیچ ئیشێک تۆمار نەکراوە</span>' : '&nbsp;' ?></td>
                <td class="rc-c-teeth">&nbsp;</td>
                <td class="rc-c-qty">&nbsp;</td>
                <td class="rc-c-unit">&nbsp;</td>
                <td class="rc-c-total">&nbsp;</td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <div class="rc-foot">
        <!-- First in the markup so RTL prints it on the right, totals on the left. -->
        <div class="rc-foot-side">
            <div class="rc-row">
                <span class="rc-label">ماوەی چارەسەر :</span>
                <span class="rc-fill"><?= htmlspecialchars($plan['duration_text'] ?? '') ?></span>
            </div>
            <?php if ($minutesText !== ''): ?>
            <div class="rc-row">
                <span class="rc-label">کاتی خەمڵێنراو :</span>
                <span class="rc-fill"><?= htmlspecialchars($minutesText) ?></span>
            </div>
            <?php endif; ?>
            <div class="rc-row">
                <span class="rc-label">تێبینی :</span>
                <span class="rc-fill"><?= htmlspecialchars($plan['notes'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">واژووی دکتۆر :</span>
                <span class="rc-fill">&nbsp;</span>
            </div>
            <div class="rc-row">
                <span class="rc-label">واژووی نەخۆش :</span>
                <span class="rc-fill">&nbsp;</span>
            </div>
        </div>
        <div class="rc-foot-totals">
            <div class="rc-total-row">
                <span class="rc-label">کۆی گشتی :</span>
                <span class="rc-total-box"><?= number_format($sumGross) ?></span>
            </div>
            <?php if ($sumDiscount > 0): ?>
            <div class="rc-total-row">
                <span class="rc-label">داشکاندن :</span>
                <span class="rc-total-box is-disc"><?= number_format($sumDiscount) ?></span>
            </div>
            <?php endif; ?>
            <div class="rc-total-row">
                <span class="rc-label">کۆی خەمڵێنراو :</span>
                <span class="rc-total-box is-final"><?= number_format($sumFinal) ?></span>
            </div>
            <div class="tp-stamp">
                <b>خەمڵاندن</b>
                <small>هیچ پارەیەک وەرنەگیراوە</small>
                <small class="en">ESTIMATE · NOT A RECEIPT</small>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/../includes/next_appointment_strip.php'; ?>

    <div class="rc-terms">
        <div class="rc-terms-lead">نەخۆشی بەڕێز، ئەم پلانە خەمڵاندنی نرخە و پسوولەی پارەدان نییە :</div>
        <ul>
            <li>هیچ پارەیەک وەرنەگیراوە و هیچ قەرزێک تۆمار نەکراوە.</li>
            <li>ڕەنگە دوای پشکنینی زیاتر (وەک ئێکسڕەی) ئیش یان نرخەکان بگۆڕێن.</li>
            <li>ئەگەر بڕیارت دا دەست پێبکەین، پەیوەندیمان پێوە بکە بۆ دانانی مەوعید.</li>
        </ul>
    </div>

</div></div>
<?php endfor; ?>

<script src="<?= asset_url('assets/js/tooth-chart.js') ?>"></script>
<script>
// The tooth map — drawn with the same teeth as the app's charts. Each planned
// tooth carries the number of its row in the table under it.
(function () {
    const TC = window.ToothChart;
    if (!TC) return;
    const MAP = <?= json_encode($toothMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
    const ARCH = <?= json_encode($archRows) ?>;
    const PRIMARY = <?= $hasPrimary ? 'true' : 'false' ?>;
    const TEETH = {
        permanent: { upper: [[18, 17, 16, 15, 14, 13, 12, 11], [21, 22, 23, 24, 25, 26, 27, 28]],
                     lower: [[48, 47, 46, 45, 44, 43, 42, 41], [31, 32, 33, 34, 35, 36, 37, 38]] },
        primary:   { upper: [[55, 54, 53, 52, 51], [61, 62, 63, 64, 65]],
                     lower: [[85, 84, 83, 82, 81], [71, 72, 73, 74, 75]] }
    };
    const label = f => TC.isPrimary(f) ? 'ABCDE'.charAt(f % 10 - 1) : String(f % 10);
    const tooth = (f, copy) => {
        const t = MAP[f];
        return '<div class="tp-t' + (t ? ' has' : '') + (t && t.done ? ' is-done' : '') + '">'
            + '<span class="tp-tn">' + label(f) + '</span>'
            + '<span class="tp-ts">' + TC.svg(f, t ? t.work : [], { idPrefix: 'p' + copy }) + '</span>'
            + (t ? '<span class="tp-rn">' + t.rows.join('،') + '</span>' : '') + '</div>';
    };
    const jaw = (j, copy) => {
        const sets = j === 'upper' ? ['permanent', 'primary'] : ['primary', 'permanent'];
        return '<div class="tp-jaw is-' + j + '">' + sets.filter(s => s === 'permanent' || PRIMARY).map(s =>
            '<div class="tp-row' + (s === 'primary' ? ' is-primary' : '') + '"><div class="tp-q">'
            + TEETH[s][j][0].map(f => tooth(f, copy)).join('') + '</div><span class="tp-qdiv"></span><div class="tp-q">'
            + TEETH[s][j][1].map(f => tooth(f, copy)).join('') + '</div></div>').join('') + '</div>';
    };
    const arch = (a, text) => ARCH[a].length ? '<div class="tp-arch"><span>' + text + ' — ڕیزی ' + ARCH[a].join('، ') + '</span></div>' : '';
    document.querySelectorAll('.tp-map').forEach(el => {
        const copy = el.dataset.copy;
        el.innerHTML = arch('upper', '▲ هەموو ئەرکی سەرەوە') + jaw('upper', copy)
            + '<div class="tp-mid"><span>ڕاست</span><i></i><span>چەپ</span></div>'
            + jaw('lower', copy) + arch('lower', '▼ هەموو ئەرکی خوارەوە');
    });
})();

// One copy, one A5 page: when a copy's content is taller than the printable
// area (210mm − 2 × 6mm margins), shrink it to fit. Measured on screen, where
// the sheet's inner width is the same 136mm the printer uses.
function fitSheets() {
    const limit = 198 * 96 / 25.4 - 4;
    document.querySelectorAll('.sheet-in').forEach(function (el) {
        el.style.removeProperty('--fit');
        const h = el.offsetHeight;
        if (h > limit) el.style.setProperty('--fit', Math.max(0.7, limit / h).toFixed(3));
    });
}
window.addEventListener('load', fitSheets);
window.addEventListener('beforeprint', fitSheets);
<?php if ($autoPrint): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
<?php endif; ?>
</script>
</body>
</html>
