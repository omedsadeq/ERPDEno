<?php
/**
 * قەبزی وەرگرتنی قەرزی کۆن — the numbered receipt the patient walks out with.
 *
 * This is the whole point of taking the money against the patient instead of
 * waiting for the doctor: the patient gets a document in the morning and does
 * not have to sit until 12:00 for one. It is also the clinic's proof of what it
 * now holds for them until the money is put on a case.
 *
 * Deliberately the SAME document as cases/voucher.php — same letterhead, same
 * rc- / vc- class layout, same half-A5 pad. A patient handed two receipts on two
 * visits should not be able to tell they came from different screens.
 *
 * Same print conventions as cases/voucher.php and xray/receipt.php:
 *   ?autoprint=1  open the print dialog as soon as the sheet is ready
 *   ?copies=N     N real pages in one job (a page cannot set the dialog's copy
 *                 count). Clamped, so a hand-edited URL cannot spool a hundred.
 *   ?embed=1      shown inside another page's print dialog, which draws its own
 *                 toolbar — so this page drops its own and never self-prints.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/PatientAdvance.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();

$advanceId = (int)($_GET['id'] ?? 0);
if ($advanceId <= 0) {
    die('Advance ID is required');
}

$manager = new PatientAdvance();
$advance = $manager->getById($advanceId);
if (!$advance) {
    die('Receipt not found');
}

// An advance is money, so it is the money roles' document. A doctor may see it
// only for a patient whose folders they own — the same isolation
// api/debt_payment.php applies to every patient-keyed financial read.
if ($auth->isDoctor() && !$auth->isAdmin()) {
    AccessGuard::requirePatientWork((int)$advance['patient_id'], true);
}

$isEmbedded = ($_GET['embed'] ?? '') === '1';
$autoPrint  = !$isEmbedded && ($_GET['autoprint'] ?? '0') === '1';
$copyCount  = max(1, min(4, (int)($_GET['copies'] ?? 1)));

$summary     = $manager->summaryFor((int)$advance['patient_id']);
$allocations = $manager->allocationsOf($advanceId);

// «مەوعیدی داهاتوو» — the patient's next booked visit, preferring the doctor
// this advance was taken for (see classes/NextAppointment.php); null prints no
// strip.
require_once __DIR__ . '/../classes/NextAppointment.php';
$nextAppointment = NextAppointment::forPatient((int)$advance['patient_id'], 0, (int)($advance['doctor_id'] ?? 0));

// Same letterhead as cases/receipt.php and cases/voucher.php.
require __DIR__ . '/../includes/clinic_branding.php';

$methodLabels = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'هی تر'];

$voucherNo   = (string)$advance['advance_no'];
$voucherDate = date('Y/n/j', strtotime((string)$advance['received_at']));
$voucherTime = date('H:i', strtotime((string)$advance['received_at']));

function av_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>قەبزی وەرگرتنی قەرزی کۆن — <?= av_h($voucherNo) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
/* Same sheet as cases/voucher.php — this is the same pad, torn from the same
   book, so every rule below is that file's rule. */
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

/* ── Letterhead ── */
.rc-head { display: flex; align-items: center; gap: 8px; padding-bottom: 4px; border-bottom: 2px solid <?= $printAccent ?>; }
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
.rc-row { display: flex; align-items: baseline; gap: 5px; padding: 2px 0; font-size: 9.5pt; }
.rc-label { flex: 0 0 auto; font-weight: 700; color: #1b2b3a; white-space: nowrap; }
.rc-fill { flex: 1 1 auto; min-height: 15px; border-bottom: 1px dotted #7d8b99; padding: 0 4px 1px; font-size: 10pt; }
.rc-no-box { display: flex; align-items: center; gap: 6px; padding: 3px 6px; border: 1.5px solid #1b2b3a; margin-bottom: 3px; }
.rc-no-caption { flex: 1 1 auto; font-size: 8pt; font-weight: 700; color: #1b2b3a; line-height: 1.25; }
.rc-no-value { flex: 0 0 auto; font-size: 13pt; font-weight: 700; color: #c62026; letter-spacing: .3px; direction: ltr; }
.rc-no-value.is-long { font-size: 10pt; letter-spacing: 0; }

/* ── The figure itself: the one thing the patient reads ── */
.vc-amount { margin-top: 8px; padding: 6px 10px; border: 2px solid #1b2b3a; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.vc-amount-label { font-size: 10pt; font-weight: 700; color: #1b2b3a; }
.vc-amount-value { font-size: 20pt; font-weight: 700; color: #14713b; direction: ltr; }

.vc-against { margin-top: 6px; padding: 4px 8px; background: #f4f7fa; border: 1px solid #cfd9e3; font-size: 9.5pt; display: flex; justify-content: space-between; gap: 8px; }
.vc-against .v { font-weight: 700; color: #0f6cbd; direction: ltr; }

.vc-balance { width: 100%; border-collapse: collapse; margin-top: 8px; }
.vc-balance td { border: 1px solid #1b2b3a; padding: 4px 8px; font-size: 10pt; }
.vc-balance td.l { width: 42%; font-weight: 700; color: #1b2b3a; background: #f7fafc; }
.vc-balance td.v { text-align: center; font-weight: 700; direction: ltr; }
.vc-balance tr.paid td.v { color: #14713b; }
.vc-balance tr.due td.v { color: #c62026; font-size: 12pt; }
.vc-allocations { width:100%; border-collapse:collapse; margin-top:6px; }
.vc-allocations th, .vc-allocations td { border:1px solid #cfd9e3; padding:3px 7px; font-size:8.8pt; }
.vc-allocations th { background:#edf3f8; color:#1b2b3a; text-align:right; }
.vc-allocations td:last-child { direction:ltr; text-align:center; font-weight:700; }
.vc-allocations tr.is-reversed td { color:#9aa4ae; text-decoration:line-through; }

.vc-pending { margin-top: 8px; padding: 5px 9px; border: 1.5px dashed #b07505; background: #fff8ec; color: #8a5a00; font-size: 9pt; font-weight: 700; text-align: center; }

.vc-foot { display: flex; gap: 14px; margin-top: 12px; }
.vc-foot > div { flex: 1 1 50%; }
.vc-foot .rc-fill { min-height: 20px; }

.vc-note { margin-top: 10px; font-size: 8pt; color: #5a6672; text-align: center; }

/* ── Screen-only toolbar ── */
.rc-bar { display: flex; gap: 8px; justify-content: center; padding: 10px; }
.rc-bar button, .rc-bar a { padding: 8px 18px; border: 0; border-radius: 7px; background: #0f6cbd; color: #fff; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; }
.rc-bar .rc-bar-alt { background: #48566a; }

body.is-embedded { background: #fff; }
body.is-embedded .rc-bar { display: none; }
body.is-embedded .sheet { margin: 0 auto; box-shadow: none; }

@media print {
    html, body { background: #fff; }
    .rc-bar { display: none !important; }
    .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
    /* ?copies=N — every extra copy starts its own page. */
    .sheet + .sheet { page-break-before: always; break-before: page; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body class="<?= $isEmbedded ? 'is-embedded' : '' ?>">

<div class="rc-bar">
    <button type="button" onclick="window.print()">🖨 چاپکردن</button>
    <a class="rc-bar-alt" href="<?= APP_URL ?>/patients/statement.php?patient_id=<?= (int)$advance['patient_id'] ?>">کشف حساب</a>
</div>

<div class="sheet">

    <div class="rc-head">
        <div class="rc-logo">
            <img src="<?= av_h($logoUrl) ?>" alt="<?= av_h($clinicNameEn) ?>" onerror="this.style.display='none'">
        </div>
        <div class="rc-head-text">
            <div class="rc-clinic-ku"><?= av_h($clinicName) ?></div>
            <div class="rc-contact">
                <span class="ico">✆</span>
                <span><?= av_h($clinicPhone1) ?><?= $clinicPhone2 !== '' ? ' - ' . av_h($clinicPhone2) : '' ?></span>
            </div>
            <div class="rc-addr">
                <span class="ico" style="color:<?= $printAccent ?>;">◉</span>
                <?= av_h($clinicAddress) ?>
            </div>
        </div>
    </div>

    <div class="rc-meta">
        <div>
            <div class="rc-no-box">
                <div class="rc-no-caption">قەبزی وەرگرتنی قەرزی کۆن<br>ژمارە :</div>
                <div class="rc-no-value<?= mb_strlen($voucherNo) > 8 ? ' is-long' : '' ?>"><?= av_h($voucherNo) ?></div>
            </div>
            <div class="rc-row">
                <span class="rc-label">لاپەرە :</span>
                <span class="rc-fill"><?= av_h($advance['patient_code'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">ڕێکەوت :</span>
                <span class="rc-fill"><?= av_h($voucherDate) ?> · <?= av_h($voucherTime) ?></span>
            </div>
        </div>
        <div>
            <div class="rc-row">
                <span class="rc-label">نەخۆش :</span>
                <span class="rc-fill"><?= av_h($advance['patient_name'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">تەلەفۆن :</span>
                <span class="rc-fill" style="direction: ltr; text-align: right;"><?= av_h($advance['patient_phone'] ?? '') ?></span>
            </div>
            <div class="rc-row">
                <span class="rc-label">ڕێگا :</span>
                <span class="rc-fill"><?= av_h($methodLabels[$advance['payment_method']] ?? $advance['payment_method']) ?></span>
            </div>
            <?php if (!empty($advance['doctor_name'])): ?>
            <!-- Whose patient this old balance is, chosen at the counter. Older
                 receipts carry no doctor, so the row is simply not drawn. -->
            <div class="rc-row">
                <span class="rc-label">دکتۆر :</span>
                <span class="rc-fill"><?= av_h($advance['doctor_name']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="vc-amount">
        <span class="vc-amount-label">بڕی وەرگیراو</span>
        <span class="vc-amount-value"><?= number_format((float)$advance['amount']) ?></span>
    </div>

    <div class="vc-against">
        <span>بەرامبەر بە :</span>
        <span class="v">قەرزی کۆن — پێش کردنەوەی کەیس</span>
    </div>

    <table class="vc-balance">
        <tr>
            <td class="l">کۆی وەرگیراو لەم نەخۆشە</td>
            <td class="v"><?= number_format($summary['received']) ?></td>
        </tr>
        <tr class="paid">
            <td class="l">دانراو لەسەر وەسڵ</td>
            <td class="v"><?= number_format($summary['allocated']) ?></td>
        </tr>
        <tr class="due">
            <td class="l">ماوە (بەردەست)</td>
            <td class="v"><?= number_format($summary['available']) ?></td>
        </tr>
    </table>

    <?php if ($allocations): ?>
    <table class="vc-allocations" aria-label="Allocations">
        <thead><tr><th>وەسڵی چارەسەر</th><th>کەیس</th><th>بەروار</th><th>بڕ</th></tr></thead>
        <tbody>
        <?php foreach ($allocations as $al): ?>
        <tr class="<?= !empty($al['reversed_at']) ? 'is-reversed' : '' ?>">
            <td dir="ltr"><?= av_h($al['invoice_no'] ?: '—') ?></td>
            <td dir="ltr"><?= av_h($al['case_number'] ?: '—') ?></td>
            <td dir="ltr"><?= av_h(substr((string)$al['allocated_at'], 0, 10)) ?></td>
            <td><?= number_format((float)$al['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (trim((string)($advance['notes'] ?? '')) !== ''): ?>
    <div class="rc-row" style="margin-top:8px;">
        <span class="rc-label">تێبینی :</span>
        <span class="rc-fill"><?= av_h($advance['notes']) ?></span>
    </div>
    <?php endif; ?>

    <?php if ((float)($advance['refunded_amount'] ?? 0) > 0.009): ?>
    <div class="vc-pending">
        گەڕێندراوەتەوە: <?= number_format((float)$advance['refunded_amount']) ?>
        <?= !empty($advance['refund_reason']) ? ' — ' . av_h($advance['refund_reason']) : '' ?>
    </div>
    <?php endif; ?>

    <?php require __DIR__ . '/../includes/next_appointment_strip.php'; ?>

    <div class="vc-foot">
        <div>
            <div class="rc-row">
                <span class="rc-label">وەرگر :</span>
                <span class="rc-fill"><?= av_h($advance['received_by_name'] ?? '') ?></span>
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

<script>
<?php if ($copyCount > 1): ?>
// ?copies=N — clone the sheet so the job really contains N pages.
(function () {
    var sheet = document.querySelector('.sheet');
    if (!sheet) return;
    for (var i = 1; i < <?= $copyCount ?>; i++) {
        sheet.parentNode.insertBefore(sheet.cloneNode(true), sheet.nextSibling);
    }
})();
<?php endif; ?>
<?php if ($autoPrint): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
<?php endif; ?>
</script>
</body>
</html>
