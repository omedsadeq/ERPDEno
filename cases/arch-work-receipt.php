<?php
/**
 * وەسڵی کارە ئەنجامدراوەکان — the printed arch (ئەرک) work receipt.
 *
 * ONE document, printed through the browser like cases/receipt.php: the list of
 * work done this visit, then قەرزی پێشوو / دراو / ماوە. It deliberately carries
 * no per-work prices — an arch job is priced once when the case is opened — and
 * no separate payment voucher is produced for the money it collects.
 *
 * ?copies=N repeats the A5 sheet N times (patient + clinic file).
 * ?autoprint=0 opens it without the print dialog.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/AccessGuard.php';
require_once __DIR__ . '/../classes/ArchWorkReceipt.php';

$db = Database::getInstance();

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$receiptId = (int)($_GET['receipt'] ?? 0);
$service = new ArchWorkReceipt();
$receipt = $receiptId > 0 ? $service->getById($receiptId) : null;
if (!$receipt) {
    die('Receipt not found');
}

$caseId = (int)$receipt['case_id'];
// Doctor isolation: a doctor may only print receipts for their own cases.
AccessGuard::requireCaseWork($caseId, false);

$patient = $service->patientFor($caseId);
$works   = $service->getWorks($receiptId);

// «مەوعیدی داهاتوو» — the visit booked next on this case (the arch procedure
// log books it as an appointment too), then with the doctor who sent this
// receipt (see classes/NextAppointment.php); null prints no strip.
require_once __DIR__ . '/../classes/NextAppointment.php';
$nextAppointment = NextAppointment::forPatient((int)($patient['id'] ?? 0), $caseId, (int)($receipt['sent_by'] ?? 0));

// An approved receipt prints the figures it was approved with; one still under
// review previews against the patient's debt as it stands right now.
$debt      = $service->debtFor($receipt);
$paid      = (float)$receipt['paid_amount'];
$remaining = max(0, $debt - $paid);

$archLabels = ['upper' => 'سەرەوە', 'lower' => 'خوارەوە', 'both' => 'هەردووک'];
$methodLabels = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'یتر'];

// Clinic letterhead — shared with the other printed documents so they match.
require __DIR__ . '/../includes/clinic_branding.php';

// embed=1 → the sheet is shown inside another page's print dialog, which draws
// its own toolbar and drives printing (the Approval Center's openPrintDialog).
// Standalone keeps the toolbar and opens the print dialog by itself.
$isEmbedded = ($_GET['embed'] ?? '') === '1';
$autoPrint = !$isEmbedded && ($_GET['autoprint'] ?? '1') !== '0';
$copyCount = max(1, min(4, (int)($_GET['copies'] ?? 1)));
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>وەسڵی کارە ئەنجامدراوەکان — <?= htmlspecialchars($receipt['receipt_no']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
/* A5 portrait, same geometry as cases/receipt.php so the two documents sit on
   the same paper and print with the same margins. */
@page { size: A5 portrait; margin: 6mm; }

* { box-sizing: border-box; }
html, body {
    margin: 0; padding: 0;
    background: #eceff3;
    font-family: var(--app-font-family, 'NRT', Tahoma, sans-serif);
    color: #111;
}
.sheet {
    width: 148mm; min-height: 210mm;
    margin: 12px auto; padding: 6mm 6mm 5mm;
    background: #fff;
    box-shadow: 0 6px 22px rgba(20, 40, 70, .18);
}

.rc-bar {
    position: sticky; top: 0; z-index: 5;
    display: flex; gap: 8px; justify-content: center;
    padding: 8px; background: #fff; border-bottom: 1px solid #d8dee6;
}
.rc-bar button, .rc-bar a {
    padding: 7px 18px; border: 1px solid <?= $printAccent ?>; border-radius: 6px;
    background: <?= $printAccent ?>; color: #fff; font: inherit; font-size: 12pt;
    cursor: pointer; text-decoration: none;
}
.rc-bar .rc-bar-alt { background: #fff; color: <?= $printAccent ?>; }

/* ── Embedded in another page's print dialog ──
   No toolbar (the dialog has one) and no page chrome — the iframe body IS the
   sheet, so it scrolls inside the dialog and prints on its own. */
body.is-embedded { background: #fff; }
body.is-embedded .rc-bar { display: none; }
body.is-embedded .sheet { margin: 0 auto; min-height: 0; box-shadow: none; }

.rc-head {
    display: flex; align-items: center; gap: 8px;
    padding-bottom: 4px; border-bottom: 2px solid <?= $printAccent ?>;
}
.rc-logo img { display: block; width: 21mm; height: auto; }
.rc-head-text { flex: 1 1 auto; text-align: right; line-height: 1.5; }
.rc-clinic-ku { font-size: 15pt; font-weight: 700; color: #1b2b3a; }
.rc-contact { font-size: 8.5pt; color: #3f4a56; direction: ltr; text-align: right; }
.rc-contact .ico { color: <?= $printAccent ?>; font-weight: 700; }
.rc-addr { font-size: 8pt; color: #4a5560; }

.rc-title {
    margin: 6px 0 4px; padding: 4px 8px;
    background: #eef7fc; border-right: 3px solid <?= $printAccent ?>; border-radius: 4px;
    font-size: 11.5pt; font-weight: 700; color: #1b2b3a;
}
.rc-meta { display: flex; flex-wrap: wrap; gap: 3px 18px; margin-bottom: 6px; }
.rc-row { display: flex; align-items: baseline; gap: 5px; font-size: 9.5pt; min-width: 45%; }
.rc-label { color: #4a5560; white-space: nowrap; }
.rc-fill { flex: 1 1 auto; border-bottom: 1px dotted #9fb0c0; font-weight: 700; }
.rc-no { color: #c62828; font-weight: 700; direction: ltr; }

table.rc-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
.rc-table th, .rc-table td {
    border: 1px solid #9fb8cc; padding: 3px 5px;
    font-size: 9pt; text-align: center; vertical-align: middle;
}
.rc-table th { background: #eef7fc; font-weight: 700; color: #1b2b3a; }
.rc-table td.rc-work { text-align: right; font-weight: 700; }
.rc-table td.rc-note { text-align: right; color: #4a5560; font-size: 8.5pt; }

.rc-money { margin-top: 8px; margin-right: auto; width: 62%; }
.rc-money-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; margin-bottom: 4px;
}
.rc-money-row .rc-label { font-size: 10pt; font-weight: 700; color: #1b2b3a; }
.rc-box {
    min-width: 34mm; padding: 3px 8px;
    border: 1px solid #9fb8cc; border-radius: 4px;
    font-size: 10.5pt; font-weight: 700; text-align: center; direction: ltr;
}
.rc-box.is-paid { color: #1b7a3d; border-color: #1b7a3d; }
.rc-box.is-due { color: #c62828; border-color: #c62828; }

.rc-sign { display: flex; justify-content: space-between; gap: 12px; margin-top: 12mm; }
.rc-sign div { flex: 1 1 0; text-align: center; font-size: 9pt; color: #4a5560; }
.rc-sign span { display: block; margin-top: 9mm; border-top: 1px dotted #9fb0c0; padding-top: 3px; }

.rc-terms { margin-top: 6px; font-size: 8pt; color: #4a5560; line-height: 1.6; }

@media print {
    html, body { background: #fff; }
    .rc-bar { display: none !important; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rc-table tr { page-break-inside: avoid; }
    /* ?copies=N — every extra copy starts its own A5 page. */
    .sheet + .sheet { page-break-before: always; break-before: page; }
}
</style>
</head>
<body class="<?= $isEmbedded ? 'is-embedded' : '' ?>">

<div class="rc-bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a class="rc-bar-alt" href="<?= APP_URL ?>/cases/view.php?id=<?= $caseId ?>#tab-logs">گەڕانەوە</a>
</div>

<?php
// Built once and repeated, so the copies can never drift apart.
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
                <span><?= htmlspecialchars(implode(' - ', $clinicPhones)) ?></span>
            </div>
            <div class="rc-addr">
                <span class="ico" style="color:<?= $printAccent ?>;">◉</span>
                <?= htmlspecialchars($clinicAddress) ?>
            </div>
        </div>
    </div>

    <div class="rc-title">وەسڵی کارە ئەنجامدراوەکان</div>

    <div class="rc-meta">
        <div class="rc-row">
            <span class="rc-label">ژمارەی وەسڵ :</span>
            <span class="rc-fill rc-no"><?= htmlspecialchars($receipt['receipt_no']) ?></span>
        </div>
        <div class="rc-row">
            <span class="rc-label">ڕێکەوت :</span>
            <span class="rc-fill"><?= htmlspecialchars(date('Y/n/j', strtotime($receipt['work_date']))) ?></span>
        </div>
        <div class="rc-row">
            <span class="rc-label">نەخۆش :</span>
            <span class="rc-fill"><?= htmlspecialchars($patient['full_name'] ?? '—') ?></span>
        </div>
        <div class="rc-row">
            <span class="rc-label">لاپەرە :</span>
            <span class="rc-fill"><?= htmlspecialchars($patient['patient_code'] ?? '') ?></span>
        </div>
        <div class="rc-row">
            <span class="rc-label">کەیس :</span>
            <span class="rc-fill" dir="ltr"><?= htmlspecialchars($receipt['case_number']) ?></span>
        </div>
        <div class="rc-row">
            <span class="rc-label">دکتۆر :</span>
            <span class="rc-fill"><?= htmlspecialchars($receipt['sent_by_name'] ?? '—') ?></span>
        </div>
    </div>

    <table class="rc-table">
        <thead>
            <tr>
                <th style="width:8%;">ژ</th>
                <th style="width:16%;">بەروار</th>
                <th style="width:34%;">کاری ئەنجامدراو</th>
                <th style="width:14%;">ئەرک</th>
                <th>تێبینی</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($works as $w): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td dir="ltr"><?= htmlspecialchars(date('Y/n/j', strtotime($w['procedure_date']))) ?></td>
                <td class="rc-work"><?= htmlspecialchars($w['display_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($archLabels[$w['arch_affected']] ?? $w['arch_affected']) ?></td>
                <td class="rc-note"><?= htmlspecialchars($w['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php // Blank rules to the pad's height, so the sheet always looks the same. ?>
            <?php for ($f = count($works); $f < 8; $f++): ?>
            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <div class="rc-money">
        <div class="rc-money-row">
            <span class="rc-label">قەرزی پێشوو :</span>
            <span class="rc-box"><?= number_format($debt) ?></span>
        </div>
        <div class="rc-money-row">
            <span class="rc-label">دراو :</span>
            <span class="rc-box is-paid"><?= number_format($paid) ?></span>
        </div>
        <div class="rc-money-row">
            <span class="rc-label">ماوە :</span>
            <span class="rc-box<?= $remaining > 0.009 ? ' is-due' : '' ?>"><?= number_format($remaining) ?></span>
        </div>
        <?php if ($paid > 0.009 && !empty($receipt['payment_method'])): ?>
        <div class="rc-money-row">
            <span class="rc-label">جۆری پارەدان :</span>
            <span class="rc-box"><?= htmlspecialchars($methodLabels[$receipt['payment_method']] ?? $receipt['payment_method']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/../includes/next_appointment_strip.php'; ?>

    <div class="rc-sign">
        <div>واژووی نەخۆش <span></span></div>
        <div>واژووی ژمێریار <span></span></div>
    </div>

    <div class="rc-terms">
        ئەم وەسڵە بەڵگەیە بۆ ئەو کارانەی سەرەوە کە بۆ نەخۆش ئەنجامدراون و ئەو بڕە پارەیەی وەرگیراوە.
    </div>

</div>
<?php
$sheetHtml = ob_get_clean();
for ($copyNo = 0; $copyNo < $copyCount; $copyNo++) {
    echo $sheetHtml;
}
?>

<script>
<?php if ($autoPrint): ?>
// Print once the logo has loaded, so the sheet is never printed without it.
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 150);
});
<?php endif; ?>
</script>

</body>
</html>
