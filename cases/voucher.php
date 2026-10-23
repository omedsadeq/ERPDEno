<?php
/**
 * قەبزی وەرگرتنی پارە — the paper the PATIENT takes away when they pay.
 *
 * One قەبز per payment. cases/receipt.php is the other half of the pair: it
 * lists the work on a visit (پسوولەی وەرگرتنی پارە); this one records money
 * actually handed over against that visit, and what is still owed on it. The
 * patient brings it back when they come to pay the rest.
 *
 * Same A5 sheet and letterhead as the receipt, printed through the browser —
 * never mPDF, for the reasons in receipt.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseManager.php';
require_once __DIR__ . '/../classes/CaseInvoice.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/AccountingLedger.php';

$db = Database::getInstance();

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
$currentUser = $auth->getCurrentUser();

$paymentId = intval($_GET['payment'] ?? 0);
if (!$paymentId) {
    die('Payment ID is required');
}

$paymentService = new Payment();
$payment = $paymentService->getById($paymentId);
if (!$payment) {
    die('Payment not found');
}
// A rejected request was never money — there is nothing to give the patient.
if (($payment['status'] ?? '') === 'rejected') {
    die('ئەم داواکاری پارەدانە ڕەتکراوەتەوە — قەبزی بۆ نییە');
}

$caseId = (int)$payment['case_id'];
// Same isolation as the receipt: a doctor may only print for cases they own.
AccessGuard::requireCaseWork($caseId, false);

$caseManager = new CaseManager();
$case = $caseManager->getById($caseId);
if (!$case) {
    die('Case not found');
}

// «مەوعیدی داهاتوو» — the visit this case's doctor booked next (see
// classes/NextAppointment.php); null prints no strip. Instalment collections
// print on this voucher too, so a قیست receipt carries it as well.
require_once __DIR__ . '/../classes/NextAppointment.php';
$nextAppointment = NextAppointment::forPatient((int)($case['patient_id'] ?? 0), $caseId, (int)($case['doctor_id'] ?? 0));

$isDraft = ($payment['status'] ?? '') === 'draft';
$isPending = ($payment['status'] ?? '') === 'pending';
$isRefunded = ($payment['status'] ?? '') === 'refunded';
$paidLabel = $isDraft ? 'بڕی ڕەشنووس (نەنێردراو)' : ($isPending ? 'بڕی داواکراو (چاوەڕوان)' : ($isRefunded ? 'بڕی گەڕێنراوە' : 'بڕی وەرگیراو'));
$ledger = new AccountingLedger();
$financial = $ledger->getSummary($caseId);
$allocations = $payment['allocations'] ?? [];
$scopeLabel = count($allocations) === 1
    ? 'پسووڵەی ' . $allocations[0]['invoice_no']
    : 'کەیسی ' . $case['case_number'] . ' · ' . count($allocations) . ' پسووڵە';
$scopeTotal = (float)($financial['approved'] ?? 0);
$scopeCovered = (float)($financial['paid'] ?? 0);
$patientDue = (float)($financial['balance'] ?? 0);

$voucherNo   = (string)($payment['payment_number'] ?? $payment['id']);
$voucherDate = date('Y/n/j', strtotime($payment['payment_date'] ?? 'now'));

$methodLabels = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'تر', 'advance' => 'لە ئامانەت'];
$typeLabels   = ['cash' => 'نەقد', 'credit' => 'قەرز', 'installment' => 'قیست'];

// Clinic letterhead — shared with the receipt so the two documents match.
require __DIR__ . '/../includes/clinic_branding.php';

// embed=1 → the voucher is shown inside another page's print dialog, which
// draws its own toolbar and drives printing (the Approval Center's
// openPrintDialog). Standalone keeps the toolbar and prints by itself.
$isEmbedded = ($_GET['embed'] ?? '') === '1';
$autoPrint = !$isEmbedded && ($_GET['autoprint'] ?? '1') !== '0';
// Actual duplicate pages: JavaScript cannot set the browser's copy count.
$copyCount = max(1, min(4, (int)($_GET['copies'] ?? 1)));
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>قەبزی وەرگرتنی پارە — <?= htmlspecialchars($voucherNo) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
/* Half of an A5 sheet: a قەبز is a short document and the pad is torn in two. */
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
    margin: 12px auto;
    padding: 6mm;
    background: #fff;
    box-shadow: 0 6px 22px rgba(20, 40, 70, .18);
}

/* ── Letterhead (identical to the receipt) ── */
.rc-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 4px;
    border-bottom: 2px solid <?= $printAccent ?>;
}
.rc-logo { flex: 0 0 auto; }
.rc-logo img { display: block; width: 21mm; height: auto; }
.rc-head-text { flex: 1 1 auto; text-align: right; line-height: 1.5; }
.rc-clinic-ku { font-size: 15pt; font-weight: 700; color: #1b2b3a; }
/* LTR so the two numbers keep their printed order — an RTL run flips them. */
.rc-contact { font-size: 8.5pt; color: #3f4a56; direction: ltr; text-align: right; }
.rc-contact span { display: inline-block; }
.rc-contact .ico { color: <?= $printAccent ?>; font-weight: 700; }
.rc-addr { font-size: 8pt; color: #4a5560; }

/* ── Identification block ── */
.rc-meta { display: flex; gap: 6px; margin-top: 5px; }
.rc-meta > div { flex: 1 1 50%; }
.rc-row {
    display: flex;
    align-items: baseline;
    gap: 5px;
    padding: 2px 0;
    font-size: 9.5pt;
}
.rc-label { flex: 0 0 auto; font-weight: 700; color: #1b2b3a; white-space: nowrap; }
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
    font-size: 13pt;
    font-weight: 700;
    color: #c62026;
    letter-spacing: .3px;
    direction: ltr;
}
.rc-no-value.is-long { font-size: 10pt; letter-spacing: 0; }

/* ── The figure itself: the one thing the patient reads ── */
.vc-amount {
    margin-top: 8px;
    padding: 6px 10px;
    border: 2px solid #1b2b3a;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.vc-amount-label { font-size: 10pt; font-weight: 700; color: #1b2b3a; }
.vc-amount-value { font-size: 20pt; font-weight: 700; color: #14713b; direction: ltr; }

.vc-against {
    margin-top: 6px;
    padding: 4px 8px;
    background: #f4f7fa;
    border: 1px solid #cfd9e3;
    font-size: 9.5pt;
    display: flex;
    justify-content: space-between;
    gap: 8px;
}
.vc-against .v { font-weight: 700; color: #0f6cbd; direction: ltr; }

/* ── What this leaves on that visit ── */
.vc-balance { width: 100%; border-collapse: collapse; margin-top: 8px; }
.vc-balance td {
    border: 1px solid #1b2b3a;
    padding: 4px 8px;
    font-size: 10pt;
}
.vc-balance td.l { width: 42%; font-weight: 700; color: #1b2b3a; background: #f7fafc; }
.vc-balance td.v { text-align: center; font-weight: 700; direction: ltr; }
.vc-balance tr.due td.v { color: #c62026; font-size: 12pt; }
.vc-balance tr.paid td.v { color: #14713b; }
.vc-allocations { width:100%; border-collapse:collapse; margin-top:6px; }
.vc-allocations th, .vc-allocations td { border:1px solid #cfd9e3; padding:3px 7px; font-size:8.8pt; }
.vc-allocations th { background:#edf3f8; color:#1b2b3a; text-align:right; }
.vc-allocations td:last-child { direction:ltr; text-align:center; font-weight:700; }

.vc-pending {
    margin-top: 8px;
    padding: 5px 9px;
    border: 1.5px dashed #b07505;
    background: #fff8ec;
    color: #8a5a00;
    font-size: 9pt;
    font-weight: 700;
    text-align: center;
}

/* ── Signature rules, same as the pad ── */
.vc-foot { display: flex; gap: 14px; margin-top: 12px; }
.vc-foot > div { flex: 1 1 50%; }
.vc-foot .rc-fill { min-height: 20px; }

.vc-note { margin-top: 10px; font-size: 8pt; color: #5a6672; text-align: center; }

/* ── Screen-only toolbar ── */
.rc-bar {
    display: flex;
    gap: 8px;
    justify-content: center;
    padding: 10px;
}
.rc-bar button, .rc-bar a {
    padding: 8px 18px;
    border: 0;
    border-radius: 7px;
    background: #0f6cbd;
    color: #fff;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}
.rc-bar .rc-bar-alt { background: #48566a; }

/* ── Embedded in another page's print dialog ──
   No toolbar (the dialog has one) and no page chrome — the iframe body IS the
   voucher, so it scrolls inside the dialog and prints on its own. */
body.is-embedded { background: #fff; }
body.is-embedded .rc-bar { display: none; }
body.is-embedded .sheet { margin: 0 auto; box-shadow: none; }

@media print {
    html, body { background: #fff; }
    .rc-bar { display: none !important; }
    .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
    .sheet + .sheet { page-break-before: always; break-before: page; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body class="<?= $isEmbedded ? 'is-embedded' : '' ?>">

<div class="rc-bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a class="rc-bar-alt" href="<?= APP_URL ?>/cases/view.php?id=<?= $caseId ?>&tab=payments">گەڕانەوە</a>
</div>

<?php ob_start(); ?>
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

    <div class="rc-meta">
        <!-- RTL: this column prints on the left of the sheet, like the pad. -->
        <div>
            <div class="rc-no-box">
                <div class="rc-no-caption">قەبزی وەرگرتنی پارە<br>ژمارە :</div>
                <div class="rc-no-value<?= mb_strlen($voucherNo) > 8 ? ' is-long' : '' ?>"><?= htmlspecialchars($voucherNo) ?></div>
            </div>
            <div class="rc-row">
                <span class="rc-label">لاپەرە :</span>
                <span class="rc-fill"><?= htmlspecialchars($case['patient_code'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">ڕێکەوت :</span>
                <span class="rc-fill"><?= htmlspecialchars($voucherDate) ?></span>
            </div>
        </div>
        <div>
            <div class="rc-row">
                <span class="rc-label">دکتۆر :</span>
                <span class="rc-fill"><?= htmlspecialchars($case['doctor_name'] ?? '') ?></span>
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

    <div class="vc-amount">
        <span class="vc-amount-label"><?= htmlspecialchars($paidLabel) ?></span>
        <span class="vc-amount-value"><?= number_format((float)$payment['amount']) ?></span>
    </div>
    <?php if ((float)($payment['discount_amount'] ?? 0) > 0): ?>
    <div class="vc-note">
        <strong><?= $isDraft || $isPending ? 'داشکاندنی داواکراو (هێشتا پەسەند نەکراوە)' : ($isRefunded ? 'داشکاندنی گەڕێنراوە' : 'داشکاندنی زیاتر') ?>:</strong>
        <?= number_format((float)$payment['discount_amount'], 2) ?> IQD
        — <?= htmlspecialchars($payment['discount_reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <div class="vc-against">
        <span>لەسەر : <strong><?= htmlspecialchars($scopeLabel) ?></strong></span>
        <span class="v"><?= htmlspecialchars($typeLabels[$payment['payment_type']] ?? $payment['payment_type']) ?>
            · <?= htmlspecialchars($methodLabels[$payment['payment_method']] ?? ($payment['payment_method'] ?? '')) ?></span>
    </div>

    <?php if ($allocations): ?>
    <table class="vc-allocations" aria-label="Payment allocations">
        <thead><tr><th>پسووڵەی بەستنەوە</th><th>بڕی بەستراو</th></tr></thead>
        <tbody>
        <?php foreach ($allocations as $allocation): ?>
            <tr>
                <td><?= htmlspecialchars($allocation['invoice_no']) ?></td>
                <td><?= number_format((float)$allocation['allocated_amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <table class="vc-balance">
        <tr>
            <td class="l">کۆی پسووڵە پەسەندکراوەکان</td>
            <td class="v"><?= number_format($scopeTotal) ?></td>
        </tr>
        <tr class="paid">
            <td class="l">کۆی پارەی دروست</td>
            <td class="v"><?= number_format($scopeCovered) ?></td>
        </tr>
        <tr class="due">
            <td class="l">ماوە لەسەر نەخۆش</td>
            <td class="v"><?= number_format($patientDue) ?></td>
        </tr>
    </table>

    <?php if ($isPending): ?>
    <div class="vc-pending">
        ئەم بڕە لەلایەن دکتۆرەوە وەرگیراوە و چاوەڕوانی پەسەندکردنی ژمێریارە
    </div>
    <?php endif; ?>
    <?php if ($isDraft): ?>
    <div class="vc-pending">ڕەشنووس — هێشتا بۆ ژمێریار نەنێردراوە و پارەدانێکی پەسەندکراو نییە</div>
    <?php endif; ?>
    <?php if ($isRefunded): ?>
    <div class="vc-pending">ئەم پارەدانە هەڵوەشێنراوەتەوە<?= !empty($payment['reversal_reason']) ? ' — ' . htmlspecialchars($payment['reversal_reason']) : '' ?></div>
    <?php endif; ?>

    <?php if (trim((string)($payment['notes'] ?? '')) !== ''): ?>
    <div class="rc-row" style="margin-top:8px;">
        <span class="rc-label">تێبینی :</span>
        <span class="rc-fill"><?= htmlspecialchars($payment['notes']) ?></span>
    </div>
    <?php endif; ?>

    <?php require __DIR__ . '/../includes/next_appointment_strip.php'; ?>

    <div class="vc-foot">
        <div>
            <div class="rc-row">
                <span class="rc-label">وەرگر :</span>
                <span class="rc-fill"><?= htmlspecialchars($payment['received_by_name'] ?? '') ?></span>
            </div>
        </div>
        <div>
            <div class="rc-row">
                <span class="rc-label">واژووی نەخۆش :</span>
                <span class="rc-fill">&nbsp;</span>
            </div>
        </div>
    </div>

    <div class="vc-note">
        تکایە ئەم قەبزە بپارێزە — لە کاتی پارەدانی داهاتوودا پێویستە.
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
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
<?php endif; ?>
</script>
</body>
</html>
