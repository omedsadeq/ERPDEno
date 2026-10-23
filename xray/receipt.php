<?php
/**
 * Generate X-Ray Receipt/Form - Professional Design
 * Based on Lezan Dental Clinics form template
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';
require_once __DIR__ . '/../classes/DentalChart.php';
require_once __DIR__ . '/../classes/NextAppointment.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$requestId = intval($_GET['id'] ?? 0);
if (!$requestId) {
    die('Request ID is required');
}

// Same print conventions as cases/receipt.php, so the Approval Center can open
// this form the way it opens the invoice and voucher receipts:
//   ?autoprint=1  open the browser print dialog as soon as the sheet is ready
//   ?copies=N     put N copies of the same sheet in one job (patient + clinic
//                 file) — a page cannot set the dialog's copy count, so the
//                 copies are real pages. Clamped so a hand-edited URL cannot
//                 spool a hundred sheets.
//   ?embed=1      the form is shown inside another page's print dialog, which
//                 draws its own toolbar and drives printing (the Approval
//                 Center's openPrintDialog) — so this page drops its toolbar
//                 and never prints itself.
$isEmbedded = ($_GET['embed'] ?? '') === '1';
$autoPrint = !$isEmbedded && ($_GET['autoprint'] ?? '0') === '1';
$copyCount = max(1, min(4, (int)($_GET['copies'] ?? 1)));

$xrayManager = new XrayManager();
$request = $xrayManager->getById($requestId);

if (!$request) {
    die('Request not found');
}

// Doctor isolation: a doctor may only view receipts for X-rays they requested.
if (AccessGuard::isRestrictedDoctor() && (int)($request['requested_by'] ?? 0) !== AccessGuard::doctorId()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

global $XRAY_TYPES, $XRAY_REASONS, $XRAY_STATUS;

// Clinic letterhead — names, three phones, address, logo (classes/Branding.php).
require __DIR__ . '/../includes/clinic_branding.php';
$clinicPhone = $clinicPhone1;

// Calculate age if date_of_birth exists
$patientAge = '';
if (!empty($request['date_of_birth'])) {
    $dob = new DateTime($request['date_of_birth']);
    $now = new DateTime();
    $patientAge = $now->diff($dob)->y;
}

// «مەوعیدی داهاتوو» — the patient's next booked visit, preferring one on the
// request's case and then one with the requesting doctor (see
// classes/NextAppointment.php); null prints nothing.
$nextAppointment = NextAppointment::forPatient(
    (int)($request['patient_id'] ?? 0),
    (int)($request['case_id'] ?? 0),
    (int)($request['requested_by'] ?? 0)
);

/**
 * Sort the teeth on a request into the four quadrants of the "Or Determine"
 * cross. Every tooth used to be printed as one flat list in the top corner of
 * the box ("6U, 4U, 5U, 6U"), which threw away the half the cross exists to
 * show: 16 and 26 are both a "6U", but one is on the patient's right and the
 * other on their left.
 *
 * DentalChart holds the one FDI→Palmer table the rest of the system uses, so
 * the quadrant and the printed number both come from there — that also brings
 * the primary teeth (51-85, lettered A-E) along, which the old U/L formatting
 * dropped on the floor because they are outside 11-48.
 *
 * @param array $teethStrings comma-separated FDI lists (selected + determine)
 * @return array ['UR' => [...], 'UL' => [...], 'LR' => [...], 'LL' => [...]]
 */
function groupTeethByQuadrant(array $teethStrings) {
    static $chart = null;
    if ($chart === null) {
        $chart = new DentalChart();
    }

    $quadrants = ['UR' => [], 'UL' => [], 'LR' => [], 'LL' => []];

    foreach ($teethStrings as $teethString) {
        foreach (explode(',', (string)$teethString) as $fdi) {
            $fdi = (int)trim($fdi);
            // An FDI number the table does not know is not a tooth — skip it
            // rather than let getQuadrant()'s fallback file it under UR.
            $palmer = $fdi > 0 ? $chart->fdiToPalmerNotation($fdi) : null;
            if (!$palmer) {
                continue;
            }
            list($quadrant, $number) = explode('-', $palmer);
            // The same tooth is often on BOTH selected_teeth and
            // determine_teeth — the sheet should name it once.
            if (!in_array($number, $quadrants[$quadrant], true)) {
                $quadrants[$quadrant][] = $number;
            }
        }
    }

    foreach ($quadrants as &$numbers) {
        sort($numbers, SORT_NATURAL);
    }
    unset($numbers);

    return $quadrants;
}

// A debt sheet reprinted after the patient has paid part of it off should say
// what is still open, not only what was owed on the day it was approved. The
// live Approval Center path does not create a patient_xray_debts row at all,
// so an absent row simply means "nothing collected against this yet".
$request['debt_recorded_total'] = 0;
$request['debt_remaining_amount'] = 0;
try {
    $debtRow = $db->fetch(
        "SELECT COALESCE(SUM(amount),0) AS total, COALESCE(SUM(remaining_amount),0) AS remaining
         FROM patient_xray_debts WHERE request_id = :id",
        ['id' => $requestId]
    );
    $request['debt_recorded_total'] = (float)($debtRow['total'] ?? 0);
    $request['debt_remaining_amount'] = (float)($debtRow['remaining'] ?? 0);
} catch (Throwable $e) {
    // Collection detail is a nicety on this form — never let it stop the print.
}

// Check if mPDF is available
$usePdf = file_exists(__DIR__ . '/../vendor/autoload.php');

if ($usePdf && isset($_GET['pdf'])) {
    require_once __DIR__ . '/../vendor/autoload.php';

    // Register the NRT app font (fonts/NRT Font/) — same setup as InvoicePdfService
    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 10,
        'margin_bottom' => 10,
        'default_font' => 'nrt',
        'fontDir' => array_merge($defaultConfig['fontDir'], [
            __DIR__ . '/../fonts/NRT Font',
        ]),
        'fontdata' => $defaultFontConfig['fontdata'] + [
            'nrt' => [
                'R' => 'NRT-Reg.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ],
        ],
    ]);

    $mpdf->SetDirectionality('rtl');
    $mpdf->WriteHTML(generateReceiptHtml($request, $clinicName, $clinicNameEn, $clinicPhone, $clinicPhone2, $clinicPhone3, $clinicAddress, $patientAge, true, $nextAppointment));

    $filename = 'XRay_' . $request['request_number'] . '.pdf';
    $mpdf->Output($filename, 'I');
    exit;
}

// HTML version for printing
function generateReceiptHtml($request, $clinicName, $clinicNameEn, $clinicPhone, $clinicPhone2, $clinicPhone3, $clinicAddress, $patientAge, $isPdf = false, $nextAppointment = null) {
    // One plain block line (no flex/grid) so the ?pdf=1 path renders it too.
    $nextApptHtml = '';
    if (!empty($nextAppointment)) {
        $nextApptHtml = '<div class="next-appt">مەوعیدی داهاتوو : <strong>'
            . htmlspecialchars($nextAppointment['day'])
            . ' <span dir="ltr">' . htmlspecialchars($nextAppointment['date_display']) . '</span>'
            . ' — کاتژمێر <span dir="ltr">' . htmlspecialchars($nextAppointment['time']) . '</span></strong>'
            . (!empty($nextAppointment['doctor_name']) ? ' — ' . htmlspecialchars($nextAppointment['doctor_name']) : '')
            . '</div>';
    }

    $logoPath = htmlspecialchars($isPdf ? Branding::logoPath() : Branding::logoUrl());
    $phonesLine = implode(' - ', array_map('htmlspecialchars', array_filter([$clinicPhone, $clinicPhone2, $clinicPhone3], 'strlen')));
    $socialsHtml = '';
    foreach (Branding::socials() as $icon => $handle) {
        $socialsHtml .= '<span class="footer-item">' . $icon . ' ' . htmlspecialchars($handle) . '</span>';
    }

    // Both tooth fields feed the one cross — the doctor may have used the
    // periapical chart, the Determine chart, or both.
    $teethQuadrants = groupTeethByQuadrant([
        $request['selected_teeth'] ?? '',
        $request['determine_teeth'] ?? '',
    ]);
    $hasChartTeeth = false;
    foreach ($teethQuadrants as $numbers) {
        if ($numbers) {
            $hasChartTeeth = true;
            break;
        }
    }

    // The referring doctor is named twice on purpose: once up top as part of
    // the patient block (who sent this patient), and again on the signature
    // line at the bottom (who signs for it).
    $doctorName = trim((string)($request['requested_by_name'] ?? ''));
    $doctorTop = $doctorName !== '' ? htmlspecialchars($doctorName) : '........';

    // XrayManager::getById() carries the patient's number as patient_phone.
    // The x-ray room calls the patient in from it, and the accountant calls
    // back on an unpaid sheet — a dotted blank when the file has no number.
    $patientPhoneRaw = trim((string)($request['patient_phone'] ?? ''));
    $patientPhone = $patientPhoneRaw !== '' ? htmlspecialchars($patientPhoneRaw) : '........';

    $paymentType = $request['payment_type'] ?? '';
    $paymentStatus = $request['payment_status'] ?? '';

    // The accountant settles the amount in the Approval Center, so the sheet
    // printed from there has to carry the money — final_amount once it is set,
    // otherwise the amount the doctor requested.
    $printedAmount = (float)($request['final_amount'] ?? 0);
    if ($printedAmount <= 0) {
        $printedAmount = (float)($request['amount'] ?? 0);
    }
    $discountAmount = (float)($request['discount_amount'] ?? 0);

    // کۆی گشتی — the fee before any discount. original_amount is what
    // XrayManager::correctAmount()/applyDiscount() freeze as the pre-discount
    // figure; on a request that never went through either it is unset, so the
    // printed total is rebuilt from what is on the row.
    $grossAmount = (float)($request['original_amount'] ?? 0);
    if ($grossAmount <= 0) {
        $grossAmount = $printedAmount + $discountAmount;
    }

    // بڕی وەرگیراوە / ماوە — what was actually collected against this request.
    //   - payment_status 'paid'      → the whole fee came in (cash at the
    //     counter, or a debt later settled by XrayManager::markDebtSettled)
    //   - a patient_xray_debts row   → the fee minus what is still open on it,
    //     which is exactly the part collected on a part-cash settlement
    //     (XrayManager::approvePartialAndSendToXray)
    //   - anything else              → nothing collected yet, including a plain
    //     debt approval (that path writes no debt row) and a sheet printed
    //     before the accountant has acted at all.
    $debtRecordedTotal = (float)($request['debt_recorded_total'] ?? 0);
    $debtRemaining = (float)($request['debt_remaining_amount'] ?? 0);
    if ($paymentStatus === 'paid') {
        $receivedAmount = $printedAmount;
    } elseif ($debtRecordedTotal > 0) {
        $receivedAmount = max(0.0, $debtRecordedTotal - $debtRemaining);
    } else {
        $receivedAmount = 0.0;
    }
    $receivedAmount = min($printedAmount, $receivedAmount);
    $remainingAmount = max(0.0, $printedAmount - $receivedAmount);

    // Payment box. The sheet must show the decision that was actually taken,
    // not only what the doctor ticked when the request was written: the
    // Approval Center lets the accountant settle a doctor-requested debt in
    // cash (and the reverse), and it records that choice in payment_status —
    // XrayManager::approve{Payment,Debt}AndSendToXray never touch
    // payment_type. So payment_status decides wherever it is decisive, and
    // payment_type is the fallback for a sheet printed before the accountant
    // has acted.
    //
    // payment_type values written by xray/request.php are cash|dont_cash|debt;
    // 'non_cash' only ever came from the deprecated
    // XrayManager::processDebtPayment(), so both spellings mean "Don't Cash".
    //
    // A part-cash settlement is decided before any of that, on the money
    // itself: XrayManager::approvePartialAndSendToXray files it under the same
    // payment_status as a plain debt approval ('debt_approved'), but cash DID
    // change hands at the counter — it writes an xray_debt_payments row for
    // what was taken and leaves only the remainder open. Reading that status
    // alone printed a پارەی قەرز sheet for the WHOLE fee on a request the
    // patient had already part-paid. Money in with a balance still open can
    // only come from that path, so it is a cash sheet; the ماوە line
    // underneath carries what is still owed.
    $isPartlyPaid = ($receivedAmount > 0.009 && $remainingAmount > 0.009);

    if ($isPartlyPaid) {
        $paymentMode = 'cash';
    } elseif (in_array($paymentStatus, ['debt', 'debt_pending', 'debt_approved'], true)) {
        $paymentMode = 'debt';
    } elseif ($paymentStatus === 'paid') {
        // Covers both "collected at the counter" and a debt later paid off
        // (XrayManager::markDebtSettled) — either way the money came in.
        $paymentMode = 'cash';
    } elseif ($paymentStatus === 'not_required' || in_array($paymentType, ['dont_cash', 'non_cash'], true)) {
        $paymentMode = 'free';
    } elseif ($paymentType === 'debt') {
        $paymentMode = 'debt';
    } else {
        $paymentMode = 'cash';
    }
    $isFreeOfCharge = ($paymentMode === 'free');

    // One labelled headline figure, named for what the money actually is: cash
    // taken at the counter ("بڕی وەرگیراو") or an amount the patient still owes
    // ("پارەی قەرز"), then the full breakdown the accountant settles with —
    // کۆی گشتی، داشکاندن، بڕی وەرگیراوە، ماوە. A free request prints no figure.
    // Plain block divs on purpose: this markup also has to render through mPDF
    // on the ?pdf=1 path, which is why there is no flex/grid here.
    $amountHtml = '';
    if ($isFreeOfCharge) {
        $amountHtml = '<div class="amount-box is-free"><div class="amount-label">بێ بەرامبەر</div></div>';
    } elseif ($printedAmount > 0) {
        // The headline figure must be the one its own label names, or the sheet
        // contradicts the breakdown printed directly underneath it:
        //   پارەی قەرز    → what is still owed
        //   بڕی وەرگیراو  → what actually came in (on a part-cash settlement
        //                   that is the part taken, not the whole fee)
        //   بڕی پارە      → nothing collected yet, so just the fee
        $isDebt = ($paymentMode === 'debt');
        if ($isDebt) {
            $amountLabel = 'پارەی قەرز';
            $amountValue = $remainingAmount > 0.009 ? $remainingAmount : $printedAmount;
        } elseif ($receivedAmount > 0.009) {
            $amountLabel = 'بڕی وەرگیراو';
            $amountValue = $receivedAmount;
        } else {
            $amountLabel = 'بڕی پارە';
            $amountValue = $printedAmount;
        }
        $amountHtml = '<div class="amount-box' . ($isDebt ? ' is-debt' : '') . '">'
            . '<div class="amount-label">' . $amountLabel . '</div>'
            . '<div class="amount-value">' . number_format($amountValue) . ' <small>IQD</small></div>'
            . '<table class="amount-breakdown"><tr>'
            . '<td class="lbl">کۆی گشتی</td><td class="val">' . number_format($grossAmount) . '</td>'
            . '<td class="lbl">داشکاندن</td><td class="val">' . number_format($discountAmount) . '</td>'
            . '</tr><tr>'
            . '<td class="lbl">بڕی وەرگیراوە</td><td class="val is-paid">' . number_format($receivedAmount) . '</td>'
            . '<td class="lbl">ماوە</td><td class="val is-due">' . number_format($remainingAmount) . '</td>'
            . '</tr></table>'
            . '</div>';
    }

    $html = '
    <!DOCTYPE html>
    <html dir="rtl" lang="ku">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>X-Ray Request - ' . htmlspecialchars($request['request_number']) . '</title>
        <style>
            @font-face {
                font-family: "NRT";
                src: url("' . APP_URL . '/fonts/NRT%20Font/NRT-Reg.woff") format("woff"),
                     url("' . APP_URL . '/fonts/NRT%20Font/NRT-Reg.ttf") format("truetype");
                font-weight: normal;
                font-style: normal;
                font-display: swap;
            }
            /* One A5 sheet, like every other document this clinic prints
               (cases/receipt.php, voucher.php, arch-work-receipt.php). The
               whole form — letterhead, both option blocks, payment, notes and
               signature — is sized to fit inside it with no second page, so
               every measurement below is deliberately tight. */
            @page { size: A5 portrait; margin: 6mm; }

            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: "NRT", "Segoe UI", Tahoma, Arial, sans-serif;
                font-size: 9pt;
                line-height: 1.35;
                direction: rtl;
                background: #f0f0f0;
                color: #333;
            }
            .page {
                width: 148mm;
                max-width: 100%;
                margin: 12px auto;
                background: white;
                padding: 0;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .container {
                padding: 7px 9px;
                border: 2px solid #6a5a8e;
                margin: 0;
            }

            /* Header */
            .header {
                display: table;
                width: 100%;
                border-bottom: 2px solid #6a5a8e;
                padding-bottom: 5px;
                margin-bottom: 7px;
            }
            .header-logo {
                display: table-cell;
                width: 62px;
                vertical-align: top;
                text-align: center;
            }
            .header-logo img {
                max-width: 48px;
                max-height: 48px;
            }
            .logo-text {
                font-size: 6pt;
                font-weight: bold;
                color: #2a2a5a;
                margin-top: 1px;
            }
            .header-info {
                display: table-cell;
                vertical-align: top;
                text-align: right;
                padding-right: 9px;
            }
            .clinic-name-ar {
                font-size: 13pt;
                font-weight: bold;
                color: #2a2a5a;
                margin-bottom: 1px;
            }
            .clinic-dept {
                font-size: 9.5pt;
                color: #8a6aaa;
                font-weight: bold;
                margin-bottom: 2px;
            }
            .clinic-contact {
                font-size: 7.5pt;
                color: #444;
                direction: ltr;
                text-align: right;
                margin-bottom: 1px;
            }
            .clinic-address {
                font-size: 7.5pt;
                color: #555;
                display: flex;
                align-items: center;
                gap: 4px;
            }

            /* Patient Info Line — two rows of two fields. The wide cell takes
               the name and the referring doctor, which are the two values that
               actually need room; age and date sit beside them. */
            .patient-line {
                padding: 4px 0;
                border-bottom: 1px solid #ccc;
                margin-bottom: 7px;
                font-size: 9pt;
            }
            .patient-row {
                display: table;
                width: 100%;
            }
            .patient-row + .patient-row {
                margin-top: 3px;
            }
            .patient-field {
                display: table-cell;
                width: 38%;
            }
            .patient-field.is-main {
                width: 62%;
            }
            /* The second row carries three fields — referring doctor, patient
               phone and date — so the number the x-ray room and the cashier
               ring back on is on the sheet without costing a third row (this
               form is budgeted to a single A5 page). The narrow cells get a
               smaller value rule than the 70px default, otherwise their
               min-width steals the width a doctor name needs. */
            .patient-field.is-wide {
                width: 40%;
            }
            .patient-field.is-mid {
                width: 30%;
            }
            .patient-field.is-mid .field-value {
                min-width: 60px;
            }
            .field-label {
                font-weight: bold;
                color: #333;
                white-space: nowrap;
            }
            .field-value {
                display: inline-block;
                min-width: 70px;
                border-bottom: 1px dotted #333;
                padding: 0 6px;
            }

            /* Section */
            .section {
                margin-bottom: 7px;
            }
            .section-title {
                font-weight: bold;
                font-size: 9pt;
                color: #000;
                margin-bottom: 4px;
            }
            .section-title::before {
                content: "■ ";
                color: #333;
            }

            /* Options Grid */
            .options-grid {
                margin-left: 10px;
            }
            .options-row {
                display: table;
                width: 100%;
                margin-bottom: 3px;
            }
            .option-item {
                display: table-cell;
                padding: 1px 0;
                vertical-align: middle;
                font-size: 9pt;
            }
            .option-item-half {
                width: 50%;
            }
            .option-item-third {
                width: 33.33%;
            }
            .radio-circle {
                display: inline-block;
                width: 11px;
                height: 11px;
                border: 1.5px solid #333;
                border-radius: 50%;
                margin-left: 5px;
                vertical-align: middle;
                position: relative;
            }
            .radio-circle.checked::after {
                content: "";
                position: absolute;
                top: 1.5px;
                left: 1.5px;
                width: 5px;
                height: 5px;
                background: #333;
                border-radius: 50%;
            }
            .checkbox-square {
                display: inline-block;
                width: 11px;
                height: 11px;
                border: 1.5px solid #333;
                margin-left: 5px;
                vertical-align: middle;
                text-align: center;
                line-height: 8px;
                font-size: 9px;
                font-weight: bold;
            }
            .checkbox-square.checked::after {
                content: "✓";
            }

            /* Determine Box with Cross */
            .determine-row {
                display: table;
                width: 100%;
                margin-top: 4px;
            }
            .determine-label {
                display: table-cell;
                width: 95px;
                vertical-align: top;
                padding-top: 14px;
            }
            .determine-box-wrapper {
                display: table-cell;
                vertical-align: top;
            }
            /* The quadrant cross. direction: ltr is load-bearing — the page is
               RTL, and without it the two columns swap and every tooth is
               printed on the wrong side of the patient. */
            .determine-box {
                border: 1px solid #333;
                width: 100%;
                height: 42px;
                background: white;
                border-collapse: collapse;
                table-layout: fixed;
                direction: ltr;
            }
            .q-cell {
                width: 50%;
                height: 21px;
                padding: 2px 5px;
                font-size: 8.5pt;
                font-weight: bold;
                text-align: center;
                vertical-align: middle;
            }
            /* Only the inner edges are drawn, so the four cells read as one
               cross inside the box border. */
            .q-ul, .q-ll { border-right: 1px solid #999; }
            .q-ul, .q-ur { border-bottom: 1px solid #999; }
            .determine-note {
                margin-top: 2px;
                font-size: 8pt;
            }

            /* Payment Section */
            .payment-row {
                display: table;
                width: 100%;
                margin-bottom: 4px;
            }
            .payment-options {
                display: table-cell;
                width: 58%;
                vertical-align: middle;
            }
            /* The three cells share the 58% by content, and no label may wrap —
               a label broken over two lines would sit under the wrong circle. */
            .payment-options .options-row {
                margin-bottom: 0;
            }
            .payment-options .option-item {
                white-space: nowrap;
                padding-left: 10px;
            }
            .payment-amount {
                display: table-cell;
                width: 42%;
                text-align: left;
                vertical-align: middle;
            }
            /* The one figure on this sheet the patient and the cashier both
               look for. It is labelled for what it IS — money received vs
               money owed — so a debt form can never be read as a paid one.
               Plain block divs, not inline-block: the same markup has to come
               out right through mPDF on the ?pdf=1 path. */
            .amount-box {
                padding: 3px 7px;
                border: 1px solid #6a5a8e;
                border-radius: 3px;
                background: #f6f4fa;
                text-align: center;
            }
            .amount-box.is-debt { border-color: #b03a2e; background: #fdf3f2; }
            .amount-box.is-free { border-color: #999; background: #f4f4f4; }
            .amount-label {
                font-size: 8pt;
                font-weight: bold;
                color: #4a3f6b;
            }
            .amount-box.is-debt .amount-label { color: #96271a; }
            .amount-box.is-free .amount-label { color: #555; }
            .amount-value {
                font-size: 13pt;
                font-weight: bold;
                color: #1a1a1a;
                direction: ltr;
                line-height: 1.25;
            }
            .amount-value small { font-size: 7.5pt; font-weight: normal; color: #666; }
            .amount-sub {
                font-size: 7.5pt;
                color: #666;
                direction: rtl;
            }
            /* کۆی گشتی / داشکاندن / بڕی وەرگیراوە / ماوە — a 2x2 table rather
               than four stacked lines, so the whole settlement fits under the
               headline figure without pushing the A5 sheet over a page. A real
               <table> because mPDF lays these out reliably and flex/grid do
               not. */
            .amount-breakdown {
                width: 100%;
                margin-top: 2px;
                border-collapse: collapse;
                direction: rtl;
                font-size: 7.5pt;
            }
            .amount-breakdown td {
                padding: 1px 3px;
                border-top: 1px solid #ddd8e8;
            }
            .amount-breakdown .lbl { color: #6b6480; text-align: right; white-space: nowrap; }
            .amount-breakdown .val {
                color: #1a1a1a;
                font-weight: bold;
                text-align: left;
                direction: ltr;
                white-space: nowrap;
            }
            .amount-breakdown .val.is-paid { color: #14713b; }
            .amount-breakdown .val.is-due { color: #96271a; }
            .amount-box.is-debt .amount-breakdown td { border-top-color: #f0d9d6; }
            .reason-row {
                margin-top: 2px;
            }
            .reason-line {
                display: inline-block;
                min-width: 78%;
                border-bottom: 1px dotted #333;
                min-height: 14px;
            }

            /* Notes */
            .notes-line {
                border-bottom: 1px dotted #333;
                min-height: 15px;
                margin-bottom: 4px;
            }

            /* Signature */
            .signature-section {
                margin-top: 7px;
            }
            .signature-title {
                font-weight: bold;
                font-size: 9pt;
            }
            .signature-title::before {
                content: "> ";
            }
            .signature-area {
                min-height: 28px;
                border-bottom: 1px dotted #333;
                position: relative;
            }
            .doctor-name {
                position: absolute;
                bottom: 2px;
                left: 8px;
                font-style: italic;
                font-size: 9pt;
            }

            /* Next appointment: one bordered line above the footer, printed
               only when the patient has a visit booked. */
            .next-appt {
                margin-top: 6px;
                padding: 3px 8px;
                border: 1px solid #6a5a8e;
                border-radius: 3px;
                background: #f6f4fa;
                font-size: 8.5pt;
                color: #2a2a5a;
                text-align: center;
            }

            /* Footer */
            .footer {
                margin-top: 7px;
                padding-top: 5px;
                border-top: 1px solid #ccc;
                text-align: center;
                font-size: 6.5pt;
                color: #666;
            }
            .footer-social {
                display: flex;
                justify-content: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            .footer-item {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }

            @media print {
                body {
                    background: white;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .page {
                    box-shadow: none;
                    margin: 0;
                    width: auto;
                    max-width: 100%;
                }
                .no-print { display: none !important; }
                /* The form is sized to one A5 sheet — never let a section
                   orphan itself onto a second page. */
                .container { page-break-inside: avoid; break-inside: avoid; }
                /* ?copies=N — every extra copy starts its own page. */
                .page + .page { page-break-before: always; break-before: page; }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="container">
                <!-- Header -->
                <div class="header">
                    <div class="header-logo">
                        <img src="' . $logoPath . '" alt="Logo" onerror="this.style.display=\'none\'">
                        <div class="logo-text">' . htmlspecialchars($clinicNameEn) . '</div>
                    </div>
                    <div class="header-info">
                        <div class="clinic-name-ar">' . htmlspecialchars($clinicName) . '</div>
                        <div class="clinic-dept">بەشی تیشک ( أشعة )</div>
                        <div class="clinic-contact">
                            📞 ' . $phonesLine . '
                        </div>
                        <div class="clinic-address">
                            📍 ' . htmlspecialchars($clinicAddress) . '
                        </div>
                    </div>
                </div>

                <!-- Patient Info — two rows: who the patient is, then who sent
                     them and when. Four fields on one line left the doctor
                     barely 100px at 9pt, which clipped real names. -->
                <div class="patient-line">
                    <div class="patient-row">
                        <div class="patient-field is-main">
                            <span class="field-label">ناو :</span>
                            <span class="field-value">' . htmlspecialchars($request['patient_name']) . '</span>
                        </div>
                        <div class="patient-field">
                            <span class="field-label">تەمەن :</span>
                            <span class="field-value">' . ($patientAge ? $patientAge : '........') . '</span>
                        </div>
                    </div>
                    <div class="patient-row">
                        <div class="patient-field is-wide">
                            <span class="field-label">دکتۆری نێردراو :</span>
                            <span class="field-value">' . $doctorTop . '</span>
                        </div>
                        <div class="patient-field is-mid">
                            <span class="field-label">ژ. مۆبایل :</span>
                            <span class="field-value" style="direction: ltr;">' . $patientPhone . '</span>
                        </div>
                        <div class="patient-field is-mid">
                            <span class="field-label">ڕێکەوت :</span>
                            <span class="field-value" style="direction: ltr;">' . date('j / n / Y', strtotime($request['created_at'])) . '</span>
                        </div>
                    </div>
                </div>

                <!-- Radiographic Image Type -->
                <div class="section">
                    <div class="section-title">Please Specify the Radiographic Image :</div>
                    <div class="options-grid">
                        <div class="options-row">
                            <div class="option-item option-item-third">
                                <span class="radio-circle ' . ($request['xray_type'] === 'panoramic' ? 'checked' : '') . '"></span>
                                Panoramic
                            </div>
                            <div class="option-item option-item-third">
                                <span class="radio-circle ' . ($request['xray_type'] === 'cephalometric' ? 'checked' : '') . '"></span>
                                Cephalometric
                            </div>
                            <div class="option-item option-item-third">
                                <span class="radio-circle ' . ($request['xray_type'] === 'periapical' ? 'checked' : '') . '"></span>
                                Periapical
                            </div>
                        </div>
                        <div class="options-row">
                            <div class="option-item">
                                <span class="radio-circle ' . ($request['xray_type'] === 'cbct' ? 'checked' : '') . '"></span>
                                CBCT (3D)' . (($request['xray_type'] === 'cbct' && !empty($request['cbct_option']) && isset(XRAY_CBCT_OPTIONS[$request['cbct_option']]))
                                    ? ' — <strong>' . htmlspecialchars(XRAY_CBCT_OPTIONS[$request['cbct_option']]['en']) . '</strong>'
                                    : '') . '
                            </div>
                        </div>
                        <div style="margin-top: 4px;">
                            <div class="option-item">
                                <span class="checkbox-square ' . ($request['full_mouth'] ? 'checked' : '') . '"></span>
                                Full Mouth
                            </div>
                        </div>
                        <div class="determine-row">
                            <div class="determine-label">
                                <span class="checkbox-square ' . (($request['determine_details'] || $hasChartTeeth) ? 'checked' : '') . '"></span>
                                Or Determine
                            </div>
                            <div class="determine-box-wrapper">
                                <!-- The cross IS the chart: each tooth is printed in
                                     its own quadrant, laid out the way the doctor
                                     picked it in xray/request.php — Left on the left
                                     of the page, Right on the right. The table is
                                     forced to LTR so the RTL page cannot swap the two
                                     sides and hand the x-ray room a mirror image. The
                                     dividing lines are cell borders rather than the
                                     old absolutely-positioned divs, which also gives
                                     mPDF something it can draw on the ?pdf=1 path. -->
                                <table class="determine-box">
                                    <tr>
                                        <td class="q-cell q-ul">' . ($teethQuadrants['UL'] ? htmlspecialchars(implode(', ', $teethQuadrants['UL'])) : '&nbsp;') . '</td>
                                        <td class="q-cell q-ur">' . ($teethQuadrants['UR'] ? htmlspecialchars(implode(', ', $teethQuadrants['UR'])) : '&nbsp;') . '</td>
                                    </tr>
                                    <tr>
                                        <td class="q-cell q-ll">' . ($teethQuadrants['LL'] ? htmlspecialchars(implode(', ', $teethQuadrants['LL'])) : '&nbsp;') . '</td>
                                        <td class="q-cell q-lr">' . ($teethQuadrants['LR'] ? htmlspecialchars(implode(', ', $teethQuadrants['LR'])) : '&nbsp;') . '</td>
                                    </tr>
                                </table>' . ($request['determine_details']
                                    ? '<div class="determine-note">' . htmlspecialchars($request['determine_details']) . '</div>'
                                    : '') . '
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="section">
                    <div class="section-title">Please Specify the Reason :</div>
                    <div class="options-grid">
                        <div class="options-row">
                            <div class="option-item option-item-half">
                                <span class="radio-circle ' . ($request['xray_reason'] === 'implant' ? 'checked' : '') . '"></span>
                                Dental Implant
                            </div>
                            <div class="option-item option-item-half">
                                <span class="radio-circle ' . ($request['xray_reason'] === 'impaction' ? 'checked' : '') . '"></span>
                                Impactions
                            </div>
                        </div>
                        <div class="options-row">
                            <div class="option-item option-item-half">
                                <span class="radio-circle ' . ($request['xray_reason'] === 'pathology' ? 'checked' : '') . '"></span>
                                Pathology
                            </div>
                            <div class="option-item option-item-half">
                                <span class="radio-circle ' . ($request['xray_reason'] === 'trauma' ? 'checked' : '') . '"></span>
                                Maxillofacial Trauma
                            </div>
                        </div>
                        <div class="options-row">
                            <div class="option-item option-item-half">
                                <span class="radio-circle ' . ($request['xray_reason'] === 'endo' ? 'checked' : '') . '"></span>
                                Endo
                            </div>
                            <div class="option-item option-item-half">
                                <span class="radio-circle ' . ($request['xray_reason'] === 'other' ? 'checked' : '') . '"></span>
                                Other
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment -->
                <div class="section">
                    <div class="section-title">Payment :</div>
                    <div class="options-grid">
                        <div class="payment-row">
                            <!-- One table-cell per option, exactly like every other
                                 option row on this sheet. They must not sit loose in
                                 one cell: three Latin labels in a row inside an RTL
                                 box merge into a single left-to-right bidi run, and
                                 the first circle — Cash\'s — is pushed to the far
                                 right of the line, where it lands next to the word
                                 "Debt". A cash sheet then reads as a debt sheet. -->
                            <div class="payment-options">
                                <div class="options-row">
                                    <div class="option-item">
                                        <span class="radio-circle ' . ($paymentMode === 'cash' ? 'checked' : '') . '"></span>
                                        Cash
                                    </div>
                                    <div class="option-item">
                                        <span class="radio-circle ' . ($isFreeOfCharge ? 'checked' : '') . '"></span>
                                        Don\'t Cash
                                    </div>
                                    <div class="option-item">
                                        <span class="radio-circle ' . ($paymentMode === 'debt' ? 'checked' : '') . '"></span>
                                        Debt
                                    </div>
                                </div>
                            </div>
                            <div class="payment-amount">' . $amountHtml . '</div>
                        </div>
                        <div class="reason-row">
                            <span>Reason:</span>
                            <span class="reason-line">' . (($isFreeOfCharge || $paymentMode === 'debt') ? htmlspecialchars($request['payment_reason'] ?? '') : '') . '</span>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="section">
                    <div class="section-title">Notes :</div>
                    <div class="notes-line">' . htmlspecialchars($request['notes'] ?? '') . '</div>
                    <!-- One spare handwriting line. The form is budgeted to a
                         single A5 sheet, so there is no room for the two extra
                         blank rules this block used to carry. -->
                    <div class="notes-line"></div>
                </div>

                <!-- Signature -->
                <div class="signature-section">
                    <div class="signature-title">Name & Signature</div>
                    <div class="signature-area">
                        <div class="doctor-name">د. ' . htmlspecialchars($request['requested_by_name']) . '</div>
                    </div>
                </div>
                ' . $nextApptHtml . '

                <!-- Footer -->
                <div class="footer">
                    <div class="footer-social">
                        ' . $socialsHtml . '
                    </div>
                    <div style="margin-top: 5px; font-size: 9pt; color: #999;">
                        ژمارەی داواکاری: <strong>' . htmlspecialchars($request['request_number']) . '</strong>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>';

    return $html;
}

// Output HTML version
echo generateReceiptHtml($request, $clinicName, $clinicNameEn, $clinicPhone, $clinicPhone2, $clinicPhone3, $clinicAddress, $patientAge, false, $nextAppointment);
?>
<?php if ($isEmbedded): ?>
<!-- Embedded in another page's print dialog: no toolbar (the dialog has one)
     and no page chrome — the iframe body IS the form. -->
<style>
    body { background: #fff !important; }
    .no-print { display: none !important; }
    .page { margin: 0 auto !important; max-width: 100% !important; box-shadow: none !important; }
</style>
<?php endif; ?>
<div class="no-print" style="position: fixed; bottom: 20px; left: 20px; display: flex; gap: 10px; z-index: 1000;">
    <button onclick="window.print()" style="padding: 12px 24px; background: #2E5090; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <i class="fas fa-print"></i> چاپکردن
    </button>
    <?php if ($usePdf): ?>
    <a href="<?= APP_URL ?>/xray/receipt.php?id=<?= $requestId ?>&pdf=1" target="_blank" style="padding: 12px 24px; background: #d32f2f; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; text-decoration: none; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <i class="fas fa-file-pdf"></i> داگرتنی PDF
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/xray/view.php?id=<?= $requestId ?>" onclick="return closeReceipt();" style="padding: 12px 24px; background: #666; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; text-decoration: none; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <i class="fas fa-arrow-right"></i> گەڕانەوە
    </a>
</div>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<script>
// Opened in its own tab from the Approval Center — "گەڕانەوە" should just shut
// that tab instead of navigating to a page the accountant did not ask for.
function closeReceipt() {
    if (window.opener && !window.opener.closed) { window.close(); return false; }
    return true;
}

<?php if ($copyCount > 1): ?>
// ?copies=N — clone the sheet so the job really contains N pages.
(function () {
    var sheet = document.querySelector('.page');
    if (!sheet) return;
    for (var i = 1; i < <?= $copyCount ?>; i++) {
        sheet.parentNode.insertBefore(sheet.cloneNode(true), sheet.nextSibling);
    }
})();
<?php endif; ?>

<?php if ($autoPrint): ?>
// Open the print dialog once the logo has loaded, so the sheet is never
// printed with a missing image. Without ?autoprint=1 the page just sits there.
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 150);
});
<?php endif; ?>
</script>
