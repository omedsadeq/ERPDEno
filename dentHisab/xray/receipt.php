<?php
/**
 * Generate X-Ray Receipt/Form - Professional Design
 * Based on Lezan Dental Clinics form template
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/XrayManager.php';

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

// Load clinic settings
$settingsData = $db->fetchAll("SELECT setting_key, setting_value FROM settings");
$settings = [];
foreach ($settingsData as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

$clinicName = $settings['clinic_name'] ?? 'لێزان بۆ پزیشکی ددان';
$clinicNameEn = $settings['clinic_name_en'] ?? 'LEZAN DENTAL CLINICS';
$clinicPhone = $settings['clinic_phone'] ?? '0750 693 2626';
$clinicPhone2 = $settings['clinic_phone2'] ?? '0750 632 7575';
$clinicPhone3 = $settings['clinic_phone3'] ?? '0751 192 2326';
$clinicAddress = $settings['clinic_address'] ?? 'سۆران : تراقیک لایێنی دەروازەی سۆران ، بەرامبەر میلان کلاس';

// Calculate age if date_of_birth exists
$patientAge = '';
if (!empty($request['date_of_birth'])) {
    $dob = new DateTime($request['date_of_birth']);
    $now = new DateTime();
    $patientAge = $now->diff($dob)->y;
}

// Function to convert FDI teeth to simple format (number + U/L)
function formatTeethSimple($teethString) {
    if (empty($teethString)) return '';

    $teeth = array_filter(explode(',', $teethString));
    $formatted = [];

    foreach ($teeth as $fdi) {
        $fdi = intval($fdi);
        $toothNum = $fdi % 10;

        // Determine Upper or Lower
        if ($fdi >= 11 && $fdi <= 28) {
            $formatted[] = $toothNum . 'U';
        } else if ($fdi >= 31 && $fdi <= 48) {
            $formatted[] = $toothNum . 'L';
        }
    }

    return implode(', ', $formatted);
}

// Get formatted teeth
$selectedTeethFormatted = formatTeethSimple($request['selected_teeth'] ?? '');
$determineTeethFormatted = formatTeethSimple($request['determine_teeth'] ?? '');

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
    $mpdf->WriteHTML(generateReceiptHtml($request, $clinicName, $clinicNameEn, $clinicPhone, $clinicPhone2, $clinicPhone3, $clinicAddress, $patientAge, $selectedTeethFormatted, $determineTeethFormatted, true));

    $filename = 'XRay_' . $request['request_number'] . '.pdf';
    $mpdf->Output($filename, 'I');
    exit;
}

// HTML version for printing
function generateReceiptHtml($request, $clinicName, $clinicNameEn, $clinicPhone, $clinicPhone2, $clinicPhone3, $clinicAddress, $patientAge, $selectedTeethFormatted, $determineTeethFormatted, $isPdf = false) {
    $logoPath = $isPdf ? __DIR__ . '/../assets/images/logo.png' : APP_URL . '/assets/images/logo.png';

    // Combine teeth displays
    $teethDisplay = '';
    if ($selectedTeethFormatted) {
        $teethDisplay = $selectedTeethFormatted;
    }
    if ($determineTeethFormatted) {
        $teethDisplay .= ($teethDisplay ? ', ' : '') . $determineTeethFormatted;
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
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: "NRT", "Segoe UI", Tahoma, Arial, sans-serif;
                font-size: 11pt;
                line-height: 1.5;
                direction: rtl;
                background: #f0f0f0;
                color: #333;
            }
            .page {
                max-width: 600px;
                margin: 20px auto;
                background: white;
                padding: 0;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .container {
                padding: 20px 25px;
                border: 3px solid #6a5a8e;
                margin: 0;
            }

            /* Header */
            .header {
                display: table;
                width: 100%;
                border-bottom: 3px solid #6a5a8e;
                padding-bottom: 12px;
                margin-bottom: 15px;
            }
            .header-logo {
                display: table-cell;
                width: 100px;
                vertical-align: top;
                text-align: center;
            }
            .header-logo img {
                max-width: 75px;
                max-height: 75px;
            }
            .logo-text {
                font-size: 8pt;
                font-weight: bold;
                color: #2a2a5a;
                margin-top: 3px;
            }
            .header-info {
                display: table-cell;
                vertical-align: top;
                text-align: right;
                padding-right: 15px;
            }
            .clinic-name-ar {
                font-size: 18pt;
                font-weight: bold;
                color: #2a2a5a;
                margin-bottom: 3px;
            }
            .clinic-dept {
                font-size: 13pt;
                color: #8a6aaa;
                font-weight: bold;
                margin-bottom: 6px;
            }
            .clinic-contact {
                font-size: 9pt;
                color: #444;
                direction: ltr;
                text-align: right;
                margin-bottom: 3px;
            }
            .clinic-address {
                font-size: 9pt;
                color: #555;
                display: flex;
                align-items: center;
                gap: 5px;
            }

            /* Patient Info Line */
            .patient-line {
                display: table;
                width: 100%;
                padding: 10px 0;
                border-bottom: 1px solid #ccc;
                margin-bottom: 15px;
                font-size: 11pt;
            }
            .patient-field {
                display: table-cell;
            }
            .field-label {
                font-weight: bold;
                color: #333;
            }
            .field-value {
                display: inline-block;
                min-width: 100px;
                border-bottom: 1px dotted #333;
                padding: 0 8px;
            }

            /* Section */
            .section {
                margin-bottom: 15px;
            }
            .section-title {
                font-weight: bold;
                font-size: 11pt;
                color: #000;
                margin-bottom: 10px;
            }
            .section-title::before {
                content: "■ ";
                color: #333;
            }

            /* Options Grid */
            .options-grid {
                margin-left: 15px;
            }
            .options-row {
                display: table;
                width: 100%;
                margin-bottom: 8px;
            }
            .option-item {
                display: table-cell;
                padding: 2px 0;
                vertical-align: middle;
                font-size: 11pt;
            }
            .option-item-half {
                width: 50%;
            }
            .option-item-third {
                width: 33.33%;
            }
            .radio-circle {
                display: inline-block;
                width: 14px;
                height: 14px;
                border: 2px solid #333;
                border-radius: 50%;
                margin-left: 6px;
                vertical-align: middle;
                position: relative;
            }
            .radio-circle.checked::after {
                content: "";
                position: absolute;
                top: 2px;
                left: 2px;
                width: 6px;
                height: 6px;
                background: #333;
                border-radius: 50%;
            }
            .checkbox-square {
                display: inline-block;
                width: 14px;
                height: 14px;
                border: 2px solid #333;
                margin-left: 6px;
                vertical-align: middle;
                text-align: center;
                line-height: 10px;
                font-size: 12px;
                font-weight: bold;
            }
            .checkbox-square.checked::after {
                content: "✓";
            }

            /* Determine Box with Cross */
            .determine-row {
                display: table;
                width: 100%;
                margin-top: 8px;
            }
            .determine-label {
                display: table-cell;
                width: 120px;
                vertical-align: top;
                padding-top: 25px;
            }
            .determine-box-wrapper {
                display: table-cell;
                vertical-align: top;
            }
            .determine-box {
                border: 1px solid #333;
                width: 100%;
                height: 70px;
                position: relative;
                background: white;
            }
            .cross-vertical {
                position: absolute;
                top: 0;
                left: 50%;
                width: 1px;
                height: 100%;
                background: #999;
            }
            .cross-horizontal {
                position: absolute;
                top: 50%;
                left: 0;
                width: 100%;
                height: 1px;
                background: #999;
            }
            .determine-content {
                position: absolute;
                top: 5px;
                right: 5px;
                left: 5px;
                font-size: 10pt;
                z-index: 1;
            }

            /* Payment Section */
            .payment-row {
                display: table;
                width: 100%;
                margin-bottom: 8px;
            }
            .payment-options {
                display: table-cell;
                width: 60%;
            }
            .payment-amount {
                display: table-cell;
                text-align: left;
                vertical-align: middle;
            }
            .reason-row {
                margin-top: 5px;
            }
            .reason-line {
                display: inline-block;
                min-width: 80%;
                border-bottom: 1px dotted #333;
                min-height: 18px;
            }

            /* Notes */
            .notes-line {
                border-bottom: 1px dotted #333;
                min-height: 22px;
                margin-bottom: 8px;
            }

            /* Signature */
            .signature-section {
                margin-top: 20px;
            }
            .signature-title {
                font-weight: bold;
                font-size: 11pt;
            }
            .signature-title::before {
                content: "> ";
            }
            .signature-area {
                min-height: 50px;
                border-bottom: 1px dotted #333;
                position: relative;
            }
            .doctor-name {
                position: absolute;
                bottom: 5px;
                left: 10px;
                font-style: italic;
                font-size: 11pt;
            }

            /* Footer */
            .footer {
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #ccc;
                text-align: center;
                font-size: 8pt;
                color: #666;
            }
            .footer-social {
                display: flex;
                justify-content: center;
                gap: 15px;
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
                    max-width: 100%;
                }
                .no-print { display: none !important; }
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
                            📞 ' . htmlspecialchars($clinicPhone) . ' - ' . htmlspecialchars($clinicPhone2) . ' - ' . htmlspecialchars($clinicPhone3) . '
                        </div>
                        <div class="clinic-address">
                            📍 ' . htmlspecialchars($clinicAddress) . '
                        </div>
                    </div>
                </div>

                <!-- Patient Info -->
                <div class="patient-line">
                    <div class="patient-field">
                        <span class="field-label">ناو :</span>
                        <span class="field-value">' . htmlspecialchars($request['patient_name']) . '</span>
                    </div>
                    <div class="patient-field">
                        <span class="field-label">تەمەن :</span>
                        <span class="field-value">' . ($patientAge ? $patientAge : '........') . '</span>
                    </div>
                    <div class="patient-field">
                        <span class="field-label">ڕێکەوت :</span>
                        <span class="field-value" style="direction: ltr;">' . date('j / n / Y', strtotime($request['created_at'])) . '</span>
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
                                CBCT (3D)
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <div class="option-item">
                                <span class="checkbox-square ' . ($request['full_mouth'] ? 'checked' : '') . '"></span>
                                Full Mouth
                            </div>
                        </div>
                        <div class="determine-row">
                            <div class="determine-label">
                                <span class="checkbox-square ' . (($request['determine_details'] || $teethDisplay) ? 'checked' : '') . '"></span>
                                Or Determine
                            </div>
                            <div class="determine-box-wrapper">
                                <div class="determine-box">
                                    <div class="cross-vertical"></div>
                                    <div class="cross-horizontal"></div>
                                    <div class="determine-content">';

    // Show teeth or determine details
    if ($teethDisplay) {
        $html .= '<strong>' . htmlspecialchars($teethDisplay) . '</strong>';
    }
    if ($request['determine_details']) {
        $html .= ($teethDisplay ? '<br>' : '') . htmlspecialchars($request['determine_details']);
    }

    $html .= '</div>
                                </div>
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
                            <div class="option-item">
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
                            <div class="payment-options">
                                <span class="radio-circle ' . ($request['payment_type'] === 'cash' ? 'checked' : '') . '"></span>
                                Cash
                                <span style="margin: 0 20px;"></span>
                                <span class="radio-circle ' . ($request['payment_type'] === 'non_cash' ? 'checked' : '') . '"></span>
                                Don\'t Cash
                            </div>
                        </div>
                        <div class="reason-row">
                            <span>Reason:</span>
                            <span class="reason-line">' . ($request['payment_type'] === 'non_cash' ? htmlspecialchars($request['payment_reason'] ?? '') : '') . '</span>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="section">
                    <div class="section-title">Notes :</div>
                    <div class="notes-line">' . htmlspecialchars($request['notes'] ?? '') . '</div>
                    <div class="notes-line"></div>
                    <div class="notes-line"></div>
                </div>

                <!-- Signature -->
                <div class="signature-section">
                    <div class="signature-title">Name & Signature</div>
                    <div class="signature-area">
                        <div class="doctor-name">د. ' . htmlspecialchars($request['requested_by_name']) . '</div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="footer">
                    <div class="footer-social">
                        <span class="footer-item">📘 ' . htmlspecialchars($clinicNameEn) . '</span>
                        <span class="footer-item">📷 lezan.dental.clinics</span>
                        <span class="footer-item">👤 lezanclinics</span>
                        <span class="footer-item">🌐 www.lezandentalclinics.com</span>
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
echo generateReceiptHtml($request, $clinicName, $clinicNameEn, $clinicPhone, $clinicPhone2, $clinicPhone3, $clinicAddress, $patientAge, $selectedTeethFormatted, $determineTeethFormatted, false);
?>
<div class="no-print" style="position: fixed; bottom: 20px; left: 20px; display: flex; gap: 10px; z-index: 1000;">
    <button onclick="window.print()" style="padding: 12px 24px; background: #2E5090; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <i class="fas fa-print"></i> چاپکردن
    </button>
    <?php if ($usePdf): ?>
    <a href="<?= APP_URL ?>/xray/receipt.php?id=<?= $requestId ?>&pdf=1" target="_blank" style="padding: 12px 24px; background: #d32f2f; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; text-decoration: none; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <i class="fas fa-file-pdf"></i> داگرتنی PDF
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/xray/view.php?id=<?= $requestId ?>" style="padding: 12px 24px; background: #666; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; text-decoration: none; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
        <i class="fas fa-arrow-right"></i> گەڕانەوە
    </a>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
