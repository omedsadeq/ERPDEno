<?php
/**
 * Payment receipt for a dental case — printed through the BROWSER print dialog.
 *
 * Deliberately not mPDF: generating a PDF server-side took seconds per receipt
 * and the staff only ever print it. This page is plain HTML/CSS laid out to
 * match the clinic's pre-printed A5 receipt pad (پسوولەی وەرگرتنی پارە), so what
 * comes out of the printer looks like the paper form it replaces.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/DentalChart.php';
require_once __DIR__ . '/../classes/CaseInvoice.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/NextAppointment.php';

$db = Database::getInstance();

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();

if (!isset($_GET['id'])) {
    die('Case ID is required');
}

$caseId = intval($_GET['id']);

// Doctor isolation: a doctor may only print receipts for cases they own.
AccessGuard::requireCaseWork($caseId, false);

$caseManager = new CaseManager();
$paymentManager = new Payment();
$dentalChart = new DentalChart();

$case = $caseManager->getById($caseId);
if (!$case) {
    die('Case not found');
}

$caseTreatments = $caseManager->getTreatments($caseId);

// «مەوعیدی داهاتوو» — the visit this case's doctor booked next (see
// classes/NextAppointment.php); null prints no strip.
$nextAppointment = NextAppointment::forPatient((int)($case['patient_id'] ?? 0), $caseId, (int)($case['doctor_id'] ?? 0));

// ── Which receipt? ────────────────────────────────────────────────
// A case is billed per visit day (وەسڵی سەردان). ?invoice=<id> prints that
// day's invoice; ?invoice=unbilled prints work not yet sent to the accountant;
// no parameter keeps the old behavior — the whole case on one sheet.
$invoiceService = new CaseInvoice();
$invoiceParam = (string)($_GET['invoice'] ?? '');
$invoice = null;
$invoiceScope = 'all';
$unbilledDay = null;
if ($invoiceParam === 'unbilled') {
    $invoiceScope = 'unbilled';
    // Optional &date=YYYY-MM-DD narrows the draft to ONE work day — the unit an
    // invoice will be numbered on. Without it, all unsent work prints together.
    $dateParam = (string)($_GET['date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
        $unbilledDay = $dateParam;
    }
    $caseTreatments = array_values(array_filter($caseTreatments, function ($ct) use ($unbilledDay) {
        if (!empty($ct['invoice_id']) || ($ct['status'] ?? '') === 'cancelled') {
            return false;
        }
        if ($unbilledDay !== null) {
            $day = substr((string)($ct['performed_date'] ?: $ct['created_at']), 0, 10);
            return $day === $unbilledDay;
        }
        return true;
    }));
} elseif (ctype_digit($invoiceParam) && (int)$invoiceParam > 0) {
    $invoice = $invoiceService->getById((int)$invoiceParam);
    if (!$invoice || (int)$invoice['case_id'] !== $caseId) {
        die('Invoice not found');
    }
    $invoiceScope = 'invoice';
    $caseTreatments = array_values(array_filter($caseTreatments, function ($ct) use ($invoice) {
        return (int)($ct['invoice_id'] ?? 0) === (int)$invoice['id'];
    }));
}

// Palmer numbers for display — covers permanent (1-8) and primary (A-E) teeth,
// so a mixed-dentition case prints its tooth letters instead of blanks.
$palmerNumbers = [];
foreach ($dentalChart->getAllToothNumbers() as $i) {
    $palmerNumbers[$i] = $dentalChart->getPalmerNumber($i);
}

// Group treatments by name + unit price, the same way the case page does, so the
// receipt reads one line per procedure instead of one line per tooth.
$groupedTreatments = [];
foreach ($caseTreatments as $ct) {
    $archType = in_array(($ct['arch_type'] ?? 'none'), ['upper', 'lower'], true) ? $ct['arch_type'] : 'none';
    // Any treatment may be saved with or without a tooth; the row decides.
    $requiresTooth = $archType === 'none' && !empty($ct['tooth_number']);
    $key = ($ct['treatment_id'] ?? 0) . '_' . $ct['treatment_name'] . '_' . $ct['unit_price']
        . '_' . $archType . '_' . ($requiresTooth ? 'tooth' : 'service');
    if (!isset($groupedTreatments[$key])) {
        $groupedTreatments[$key] = [
            'treatment_name' => $ct['treatment_name'],
            'unit_price'     => $ct['unit_price'],
            'arch_type'      => $archType,
            'requires_tooth' => $requiresTooth,
            // Palmer quadrants for the tooth-chart cross in its own column.
            'UR' => [], 'UL' => [], 'LR' => [], 'LL' => [],
            'tooth_count'    => 0,
            'total_quantity' => 0,
            'total_price'    => 0,
        ];
    }

    if ($requiresTooth && !empty($ct['tooth_number'])) {
        $quadrant  = $dentalChart->getQuadrant($ct['tooth_number']);
        $palmerNum = $palmerNumbers[$ct['tooth_number']] ?? $ct['tooth_number'];
        if (isset($groupedTreatments[$key][$quadrant])) {
            $groupedTreatments[$key][$quadrant][] = $palmerNum;
            $groupedTreatments[$key]['tooth_count']++;
        }
    }

    $groupedTreatments[$key]['total_quantity'] += $ct['quantity'];
    $groupedTreatments[$key]['total_price']    += $ct['final_price'];
}

$remaining     = (float)$case['total_amount'] - (float)$case['paid_amount'] - (float)$case['discount_amount'];
$afterDiscount = (float)$case['total_amount'] - (float)$case['discount_amount'];

// Amount of just the treatments on THIS sheet (equals the case total when
// printing the whole case).
$sheetTotal = 0;
foreach ($groupedTreatments as $gt) {
    $sheetTotal += (float)$gt['total_price'];
}

// A visit receipt settles on its own: what has been collected against THIS
// receipt (FIFO share of the case's money) and what it still owes. The case
// balance is never printed on a visit receipt — the clinic works per receipt.
$invoiceDiscount  = $invoice ? (float)($invoice['discount_amount'] ?? 0) : 0.0;
// A percentage discount prints as a percentage — "داشکاندن (١٠٪)" — so the
// patient sees the rate that was agreed, not only the resulting money.
$invoiceDiscMode  = $invoice && ($invoice['discount_mode'] ?? '') === 'percent' ? 'percent' : 'amount';
$invoiceDiscPct   = $invoiceDiscMode === 'percent'
    ? rtrim(rtrim(number_format((float)($invoice['discount_value'] ?? 0), 2, '.', ''), '0'), '.')
    : '';
$invoiceNet       = max(0, $sheetTotal - $invoiceDiscount);
$invoicePaid      = 0.0;
$invoicePrior     = 0.0;
$invoiceRemaining = $invoiceNet;
if ($invoice) {
    // دراوی پێشوو (historical cases) prints on its own line, never inside
    // وەرگیراو; an approved invoice's fifo_covered already includes it.
    $invoicePrior = min($invoiceNet, max(0, (float)($invoice['prior_paid_amount'] ?? 0)));
    // Before accounting approval the ledger's paid figure is intentionally 0,
    // but the doctor-entered amount is still part of the printed invoice.
    $invoicePaid = ($invoice['accounting_status'] ?? '') === 'approved'
        ? max(0, min($invoiceNet, (float)($invoice['fifo_covered'] ?? 0)) - $invoicePrior)
        : min(max(0, $invoiceNet - $invoicePrior), max(
            (float)($invoice['fifo_covered'] ?? 0),
            (float)($invoice['draft_received_amount'] ?? 0)
        ));
    $invoiceRemaining = max(0, $invoiceNet - $invoicePaid - $invoicePrior);
}

// Clinic letterhead — shared with the قەبز so the two documents match.
require __DIR__ . '/../includes/clinic_branding.php';

// The pad has six ruled lines; keep the sheet looking the same when the case
// has fewer procedures.
$minRows   = 6;
$rowsCount = max($minRows, count($groupedTreatments));

// The visit invoice stamps the sheet with its own number and work date; an
// unbilled day's draft carries that day; the whole-case sheet gets today.
if ($invoice) {
    $receiptDate = date('Y/n/j', strtotime($invoice['work_date']));
} elseif ($unbilledDay !== null) {
    $receiptDate = date('Y/n/j', strtotime($unbilledDay));
} else {
    $receiptDate = date('Y/n/j');
}

// embed=1 → the sheet is shown inside the case page's print dialog, which draws
// its own toolbar and drives printing. Standalone (a direct link) keeps the
// toolbar and opens the print dialog by itself.
$isEmbedded = ($_GET['embed'] ?? '') === '1';
$autoPrint  = !$isEmbedded && ($_GET['autoprint'] ?? '1') !== '0';

// ?copies=N prints the same A5 sheet N times, one per page — the accountant's
// "پەسەندکردن و چاپکردن" asks for two (patient + clinic file). A browser cannot
// set the print dialog's copy count from a page, so the copies are real pages
// in the job. Clamped so a hand-edited URL cannot spool a hundred sheets.
$copyCount = max(1, min(4, (int)($_GET['copies'] ?? 1)));
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پسوولەی وەرگرتنی پارە — <?= htmlspecialchars($case['case_number']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
/* ── Sheet geometry ────────────────────────────────────────────────
   A5 portrait, matching the paper pad. @page kills the browser's own
   header/footer margins so the print dialog needs no fiddling. */
@page { size: A5 portrait; margin: 6mm; }

* { box-sizing: border-box; }
html, body {
    margin: 0;
    padding: 0;
    background: #eceff3;
    font-family: var(--app-font-family, 'NRT', Tahoma, sans-serif);
    color: #111;
}
.sheet {
    width: 148mm;
    min-height: 210mm;
    margin: 12px auto;
    padding: 6mm 6mm 5mm;
    background: #fff;
    box-shadow: 0 6px 22px rgba(20, 40, 70, .18);
}

/* ── Letterhead ── */
.rc-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 4px;
    border-bottom: 2px solid #29a3dc;
}
.rc-logo { flex: 0 0 auto; }
.rc-logo img { display: block; width: 21mm; height: auto; }
.rc-head-text { flex: 1 1 auto; text-align: right; line-height: 1.5; }
.rc-clinic-ku { font-size: 15pt; font-weight: 700; color: #1b2b3a; }
/* LTR so the two numbers keep their printed order — an RTL run flips them. */
.rc-contact { font-size: 8.5pt; color: #3f4a56; direction: ltr; text-align: right; }
.rc-contact span { display: inline-block; }
.rc-contact .ico { color: #29a3dc; font-weight: 700; }
.rc-addr { font-size: 8pt; color: #4a5560; }

/* ── Identification block ── */
.rc-meta {
    display: flex;
    gap: 6px;
    margin-top: 5px;
}
.rc-meta > div { flex: 1 1 50%; }
.rc-row {
    display: flex;
    align-items: baseline;
    gap: 5px;
    padding: 2px 0;
    font-size: 9.5pt;
}
.rc-label { flex: 0 0 auto; font-weight: 700; color: #1b2b3a; white-space: nowrap; }
/* Dotted rule the way the pad is ruled — the value sits on the line. */
.rc-fill {
    flex: 1 1 auto;
    min-height: 15px;
    border-bottom: 1px dotted #7d8b99;
    padding: 0 4px 1px;
    font-size: 10pt;
}
.rc-no-box {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 6px;
    border: 1.5px solid #1b2b3a;
    margin-bottom: 3px;
}
.rc-no-caption { flex: 1 1 auto; font-size: 8pt; font-weight: 700; color: #1b2b3a; line-height: 1.25; }
.rc-no-value {
    flex: 0 0 auto;
    font-size: 17pt;
    font-weight: 700;
    color: #c62026;
    letter-spacing: .5px;
    direction: ltr;
}
/* Case numbers are longer than the pad's 5-digit stamp — step the size down
   instead of letting them wrap or push the caption out. */
.rc-no-value.is-long { font-size: 12pt; letter-spacing: 0; }
.rc-no-value.is-xlong { font-size: 10pt; letter-spacing: -.2px; }

/* ── Procedures table ── */
.rc-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
    table-layout: fixed;
}
.rc-table th, .rc-table td {
    border: 1px solid #1b2b3a;
    padding: 3px 4px;
    font-size: 9pt;
    text-align: center;
    vertical-align: middle;
}
.rc-table thead th {
    background: #eef4f9;
    font-weight: 700;
    line-height: 1.25;
    color: #1b2b3a;
}
.rc-table thead .en {
    display: block;
    font-size: 7pt;
    font-weight: 400;
    color: #5a6672;
}
.rc-table tbody td { height: 9mm; }
.rc-c-no    { width: 7%; }
.rc-c-desc  { width: 32%; text-align: right !important; }
.rc-c-teeth { width: 18%; padding: 2px !important; }
.rc-c-qty   { width: 10%; }
.rc-c-unit  { width: 16.5%; }
.rc-c-total { width: 16.5%; font-weight: 700; }

/* Palmer cross — the four quadrants around a cross, the way the mPDF receipt
   drew them and the way a dentist reads a chart:
       UR | UL        upper right | upper left
       ---+---
       LR | LL        lower right | lower left
   LTR inside the box so the patient's right stays on the viewer's left, the
   same convention as the odontogram on the case page. */
.tooth-cross {
    display: grid;
    grid-template-columns: 1fr 1fr;
    width: 100%;
    max-width: 24mm;
    margin: 0 auto;
    direction: ltr;
    border: 1px solid #8a97a5;
    background: #fff;
    font-size: 7.5pt;
    line-height: 1.25;
    color: #1b2b3a;
}
.tooth-cross span {
    min-height: 4mm;
    padding: 1px 2px;
    text-align: center;
    word-break: break-word;
}
.tc-ur, .tc-ul { border-bottom: 1px solid #1b2b3a; }
.rc-location { display: inline-block; padding: 1.2mm 2mm; border: .25mm solid #8aa8c4; border-radius: 4mm; background: #f4f8fc; color: #244b6e; font-size: 7pt; font-weight: 700; white-space: nowrap; }
.tc-ur, .tc-lr { border-right: 1px solid #1b2b3a; }

/* ── Totals + signature block ── */
.rc-foot {
    display: flex;
    gap: 6px;
    margin-top: 6px;
    align-items: flex-start;
}
.rc-foot-side { flex: 1 1 46%; }
.rc-foot-totals { flex: 1 1 54%; }
.rc-total-row {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 2px 0;
    font-size: 9.5pt;
}
.rc-total-row .rc-label { flex: 0 0 26mm; text-align: left; }
.rc-received-source { color: #14713b; font-size: 10px; line-height: 1.6; text-align: left; }
.rc-total-box {
    flex: 1 1 auto;
    min-height: 7mm;
    padding: 1px 6px;
    border: 1px solid #1b2b3a;
    font-size: 11pt;
    font-weight: 700;
    text-align: center;
    line-height: 6.5mm;
}
.rc-total-box.is-due  { color: #c62026; }
.rc-total-box.is-paid { color: #14713b; }

/* ── Terms strip ── */
.rc-terms {
    margin-top: 7px;
    padding-top: 5px;
    border-top: 1.5px solid #29a3dc;
    font-size: 8pt;
    color: #2b3742;
    line-height: 1.7;
}
.rc-terms .rc-terms-lead { font-weight: 700; }
.rc-terms ul { margin: 2px 0 0; padding: 0 14px 0 0; }
.rc-terms li { margin: 0; }

/* ── Screen-only toolbar ── */
.rc-bar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    justify-content: center;
    gap: 8px;
    padding: 10px;
    background: #1b2b3a;
}
.rc-bar button, .rc-bar a {
    padding: 8px 18px;
    border: 0;
    border-radius: 6px;
    background: #29a3dc;
    color: #fff;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}
.rc-bar .rc-bar-alt { background: #48566a; }

/* ── Embedded in the case page's dialog ──
   No toolbar (the dialog has one) and no page chrome — the iframe body IS the
   sheet, so it scrolls inside the dialog and prints on its own. */
body.is-embedded { background: #fff; }
body.is-embedded .rc-bar { display: none; }
body.is-embedded .sheet { margin: 0 auto; min-height: 0; box-shadow: none; }

@media screen {
    /* The sheet is a fixed 148mm — narrower than a phone-sized dialog. Shrink it
       to fit the frame instead of making the user scroll sideways. `zoom`, not
       `transform`, because zoom reflows so the iframe height stays correct.
       --fit is set by fitEmbeddedSheet() below and is 1 whenever there is room. */
    body.is-embedded .sheet { zoom: var(--fit, 1); }
}

@media print {
    html, body { background: #fff; }
    .rc-bar { display: none !important; }
    .sheet {
        width: auto;
        min-height: 0;
        margin: 0;
        padding: 0;
        box-shadow: none;
    }
    /* Keep the table rules and the red receipt number on paper. */
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rc-table { page-break-inside: auto; }
    .rc-table tr { page-break-inside: avoid; }
    /* ?copies=N — every extra copy starts its own A5 page. */
    .sheet + .sheet { page-break-before: always; break-before: page; }
}
</style>
</head>
<body class="<?= $isEmbedded ? 'is-embedded' : '' ?>">

<div class="rc-bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a class="rc-bar-alt" href="<?= APP_URL ?>/cases/view.php?id=<?= $caseId ?>" onclick="return closeReceipt();">گەڕانەوە</a>
</div>

<?php
// The sheet is built once and repeated $copyCount times, so the copies can
// never drift apart.
ob_start();
?>
<div class="sheet">

    <div class="rc-head">
        <div class="rc-logo">
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($clinicNameEn) ?>"
                 onerror="this.style.display='none'">
        </div>
        <div class="rc-head-text">
            <div class="rc-clinic-ku"><?= htmlspecialchars($clinicName) ?></div>
            <div class="rc-contact">
                <span class="ico">✆</span>
                <span><?= htmlspecialchars($clinicPhone1) ?> - <?= htmlspecialchars($clinicPhone2) ?></span>
            </div>
            <div class="rc-addr">
                <span class="ico" style="color:#29a3dc;">◉</span>
                <?= htmlspecialchars($clinicAddress) ?>
            </div>
        </div>
    </div>

    <div class="rc-meta">
        <!-- RTL: this column prints on the left of the sheet, like the pad. -->
        <div>
            <div class="rc-no-box">
                <div class="rc-no-caption">پسوولەی وەرگرتنی پارە<br>ژمارە :</div>
                <?php
                // A visit invoice prints its own number (C…-2); the whole-case
                // sheet keeps the case number. Unbilled work is clearly a draft.
                $receiptNo = $invoice ? (string)$invoice['invoice_no'] : (string)$case['case_number'];
                if ($invoiceScope === 'unbilled') {
                    $receiptNo = $case['case_number'] . ' *';
                }
                $noClass = mb_strlen($receiptNo) > 10 ? ' is-xlong' : (mb_strlen($receiptNo) > 6 ? ' is-long' : '');
                ?>
                <div class="rc-no-value<?= $noClass ?>"><?= htmlspecialchars($receiptNo) ?></div>
            </div>
            <div class="rc-row">
                <span class="rc-label">لاپەرە :</span>
                <span class="rc-fill"><?= htmlspecialchars($case['patient_code'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">ڕێکەوت :</span>
                <span class="rc-fill"><?= htmlspecialchars($receiptDate) ?></span>
            </div>
        </div>
        <div>
            <div class="rc-row">
                <span class="rc-label">دکتۆر :</span>
                <span class="rc-fill"><?= htmlspecialchars($case['doctor_name'] ?? $currentUser['full_name']) ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">نەخۆش :</span>
                <span class="rc-fill"><?= htmlspecialchars($case['patient_name'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">تەلەفۆن :</span>
                <span class="rc-fill" style="direction: ltr; text-align: right;"><?= htmlspecialchars($case['patient_phone'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <table class="rc-table">
        <thead>
            <tr>
                <th class="rc-c-no">ژ</th>
                <th class="rc-c-desc">ناوەڕۆک<span class="en">Description</span></th>
                <th class="rc-c-teeth">چارتی ددان<span class="en">Teeth</span></th>
                <th class="rc-c-qty">ژمارە<span class="en">Quantity</span></th>
                <th class="rc-c-unit">نرخی تاک<span class="en">Unit Price</span></th>
                <th class="rc-c-total">نرخی کۆ<span class="en">Total Price</span></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $rowNum = 0;
            foreach ($groupedTreatments as $gt):
                $rowNum++;
            ?>
            <tr>
                <td class="rc-c-no"><?= $rowNum ?></td>
                <td class="rc-c-desc"><?= htmlspecialchars($gt['treatment_name']) ?></td>
                <td class="rc-c-teeth">
                    <?php if ($gt['arch_type'] === 'upper'): ?>
                    <span class="rc-location">ماکزیلا / سەرەوە</span>
                    <?php elseif ($gt['arch_type'] === 'lower'): ?>
                    <span class="rc-location">ماندیبل / خوارەوە</span>
                    <?php elseif (!$gt['requires_tooth']): ?>
                    <span class="rc-location">بێ ژمارەی ددان</span>
                    <?php elseif ($gt['tooth_count'] > 0): ?>
                    <div class="tooth-cross">
                        <span class="tc-ur"><?= htmlspecialchars(implode(',', $gt['UR'])) ?></span>
                        <span class="tc-ul"><?= htmlspecialchars(implode(',', $gt['UL'])) ?></span>
                        <span class="tc-lr"><?= htmlspecialchars(implode(',', $gt['LR'])) ?></span>
                        <span class="tc-ll"><?= htmlspecialchars(implode(',', $gt['LL'])) ?></span>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="rc-c-qty"><?= number_format($gt['total_quantity']) ?></td>
                <td class="rc-c-unit"><?= number_format($gt['unit_price']) ?></td>
                <td class="rc-c-total"><?= number_format($gt['total_price']) ?></td>
            </tr>
            <?php endforeach; ?>

            <?php for ($i = $rowNum + 1; $i <= $rowsCount; $i++): ?>
            <tr>
                <td class="rc-c-no"><?= $i ?></td>
                <td class="rc-c-desc">&nbsp;</td>
                <td class="rc-c-teeth">&nbsp;</td>
                <td class="rc-c-qty">&nbsp;</td>
                <td class="rc-c-unit">&nbsp;</td>
                <td class="rc-c-total">&nbsp;</td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <div class="rc-foot">
        <!-- Notes/signature first so RTL prints it on the right, totals on
             the left — the same way the paper pad is laid out. -->
        <div class="rc-foot-side">
            <div class="rc-row">
                <span class="rc-label">تێبینی :</span>
                <span class="rc-fill"><?= htmlspecialchars($case['notes'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">وەرگر :</span>
                <span class="rc-fill">&nbsp;</span>
            </div>
            <div class="rc-row">
                <span class="rc-label">واژوو :</span>
                <span class="rc-fill">&nbsp;</span>
            </div>
            <div class="rc-row">
                <span class="rc-label">مۆر :</span>
                <span class="rc-fill">&nbsp;</span>
            </div>
        </div>
        <div class="rc-foot-totals">
            <?php if ($invoiceScope === 'all'): ?>
            <!-- Whole-case sheet: the case IS the scope, so its balance is shown. -->
            <div class="rc-total-row">
                <span class="rc-label">کۆی گشتی :</span>
                <span class="rc-total-box"><?= number_format($case['total_amount']) ?></span>
            </div>
            <?php if ((float)$case['discount_amount'] > 0): ?>
            <div class="rc-total-row">
                <span class="rc-label">داشکاندن :</span>
                <span class="rc-total-box"><?= number_format($case['discount_amount']) ?></span>
            </div>
            <div class="rc-total-row">
                <span class="rc-label">دوای داشکاندن :</span>
                <span class="rc-total-box"><?= number_format($afterDiscount) ?></span>
            </div>
            <?php endif; ?>
            <div class="rc-total-row">
                <span class="rc-label">وەرگیراو :</span>
                <span class="rc-total-box is-paid"><?= number_format($case['paid_amount']) ?></span>
            </div>
            <div class="rc-total-row">
                <span class="rc-label">ماوە :</span>
                <span class="rc-total-box<?= $remaining > 0 ? ' is-due' : '' ?>"><?= number_format(max(0, $remaining)) ?></span>
            </div>

            <?php else: ?>
            <!-- One visit receipt. The clinic settles per receipt, so the case
                 balance is deliberately NOT printed here — every figure on this
                 sheet belongs to this receipt alone. -->
            <div class="rc-total-row">
                <span class="rc-label">کۆی گشتی :</span>
                <span class="rc-total-box" style="border-width: 2px;"><?= number_format($sheetTotal) ?></span>
            </div>
            <?php if ($invoiceDiscount > 0): ?>
            <div class="rc-total-row">
                <span class="rc-label">داشکاندن<?= $invoiceDiscPct !== '' ? ' (' . $invoiceDiscPct . '%)' : '' ?> :</span>
                <span class="rc-total-box"><?= number_format($invoiceDiscount) ?></span>
            </div>
            <div class="rc-total-row">
                <span class="rc-label">دوای داشکاندن :</span>
                <span class="rc-total-box"><?= number_format($invoiceNet) ?></span>
            </div>
            <?php endif; ?>
            <div class="rc-total-row">
                <span class="rc-label">وەرگیراو :</span>
                <span class="rc-total-box is-paid"><?= number_format($invoicePaid) ?></span>
            </div>
            <?php if ((float)($invoice['advance_received_amount'] ?? 0) > 0.009): ?>
            <div class="rc-received-source">لەوانە <?= number_format($invoice['advance_received_amount']) ?> لە ئەمانەت گوازراوەتەوە</div>
            <?php endif; ?>
            <?php if ($invoicePrior > 0.009): ?>
            <div class="rc-total-row">
                <span class="rc-label">دراوی پێشوو :</span>
                <span class="rc-total-box" style="color:#7a4a12;"><?= number_format($invoicePrior) ?></span>
            </div>
            <?php endif; ?>
            <div class="rc-total-row">
                <span class="rc-label">ماوە :</span>
                <span class="rc-total-box<?= $invoiceRemaining > 0.009 ? ' is-due' : '' ?>"><?= number_format($invoiceRemaining) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php require __DIR__ . '/../includes/next_appointment_strip.php'; ?>

    <div class="rc-terms">
        <div class="rc-terms-lead">چارەخوازی بەڕێز ئەم پسوولەیە بە کاردێت وەکو بەڵگەیەک بۆ :</div>
        <ul>
            <li>بڕی ئەو پارەیەی کە داوتە.</li>
            <li>ژمارە و فۆرم و جۆر و ڕێکەوتی ئەو کارانەی کە بۆت ئەنجامدراوە.</li>
        </ul>
    </div>

</div>
<?php
$sheetHtml = ob_get_clean();
for ($copyNo = 0; $copyNo < $copyCount; $copyNo++) {
    echo $sheetHtml;
}
?>

<script>
// window.close() only works on script-opened windows; when the receipt was
// opened by a plain target="_blank" link the browser ignores it, so fall through
// to the href and navigate back to the case instead.
function closeReceipt() {
    window.close();
    return true;
}

<?php if ($isEmbedded): ?>
// Scale the A5 sheet down when the dialog is narrower than 148mm (phones), so
// the whole receipt is readable without sideways scrolling. Screen only —
// @page still drives the printed size, and zoom is not applied when printing.
function fitEmbeddedSheet() {
    const sheet = document.querySelector('.sheet');
    if (!sheet) return;
    document.body.style.setProperty('--fit', '1');
    const avail = document.documentElement.clientWidth;
    // Every copy is the same width, so measuring the first fits them all.
    const natural = sheet.offsetWidth;
    if (!natural) return;
    const fit = Math.min(1, avail / (natural + 4));
    document.body.style.setProperty('--fit', fit.toFixed(4));
}
window.addEventListener('load', fitEmbeddedSheet);
let fitTimer;
window.addEventListener('resize', function () {
    clearTimeout(fitTimer);
    fitTimer = setTimeout(fitEmbeddedSheet, 120);
});
<?php endif; ?>

<?php if ($autoPrint): ?>
// Open the print dialog as soon as the logo has loaded, so the sheet is never
// printed with a missing image. ?autoprint=0 opens the page without the dialog.
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 150);
});
<?php endif; ?>
</script>

</body>
</html>
