<?php
/**
 * PDF Header Template
 * This template generates the header HTML for invoice PDFs
 *
 * Available variables:
 * $settings - PDF settings array from database
 * $invoiceData - Optional invoice-specific data array
 *
 * Template variables supported in header_html:
 * {COMPANY_NAME}, {COMPANY_NAME_SECONDARY}, {COMPANY_ADDRESS}
 * {COMPANY_PHONE}, {COMPANY_EMAIL}, {DATE}, {TIME}, {DATETIME}, {YEAR}
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Generate header HTML from settings
 *
 * @param array $settings PDF settings
 * @param array $invoiceData Optional invoice data
 * @return string Header HTML
 */
function generatePdfHeader($settings, $invoiceData = [])
{
    // If custom header HTML is defined, use it
    if (!empty($settings['header_html'])) {
        return processHeaderTemplate($settings['header_html'], $settings, $invoiceData);
    }

    // Generate default header
    return generateDefaultHeader($settings, $invoiceData);
}

/**
 * Process template variables in header HTML
 *
 * @param string $html Template HTML
 * @param array $settings Settings array
 * @param array $invoiceData Invoice data
 * @return string Processed HTML
 */
function processHeaderTemplate($html, $settings, $invoiceData = [])
{
    $replacements = [
        '{COMPANY_NAME}' => $settings['company_name'] ?? '',
        '{COMPANY_NAME_SECONDARY}' => $settings['company_name_secondary'] ?? '',
        '{COMPANY_ADDRESS}' => $settings['company_address'] ?? '',
        '{COMPANY_PHONE}' => $settings['company_phone'] ?? '',
        '{COMPANY_EMAIL}' => $settings['company_email'] ?? '',
        '{DATE}' => date('Y-m-d'),
        '{TIME}' => date('H:i'),
        '{DATETIME}' => date('Y-m-d H:i'),
        '{YEAR}' => date('Y'),
        '{INVOICE_NUMBER}' => $invoiceData['invoice_number'] ?? '',
        '{INVOICE_DATE}' => $invoiceData['invoice_date'] ?? date('Y-m-d'),
        '{PATIENT_NAME}' => $invoiceData['patient_name'] ?? '',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $html);
}

/**
 * Generate default header structure
 *
 * @param array $settings Settings array
 * @param array $invoiceData Invoice data
 * @return string Header HTML
 */
function generateDefaultHeader($settings, $invoiceData = [])
{
    $primaryColor = $settings['primary_color'] ?? '#2c5282';
    $headerHeight = $settings['header_height_mm'] ?? 30;

    ob_start();
    ?>
    <div style="width: 100%; border-bottom: 2px solid <?= $primaryColor ?>; padding-bottom: 3mm;">
        <?php if (!empty($settings['header_image_path'])): ?>
            <?php $imagePath = getAbsolutePath($settings['header_image_path']); ?>
            <?php if (file_exists($imagePath)): ?>
                <div style="text-align: center; margin-bottom: 2mm;">
                    <img src="<?= $imagePath ?>" style="max-height: <?= $headerHeight - 12 ?>mm; max-width: 100%;">
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($settings['company_name'])): ?>
            <table width="100%" style="font-size: 10pt;">
                <tr>
                    <td style="text-align: right; vertical-align: top;">
                        <?php if (!empty($settings['company_name'])): ?>
                            <div style="font-size: 14pt; font-weight: bold; color: <?= $primaryColor ?>;">
                                <?= htmlspecialchars($settings['company_name']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($settings['company_name_secondary'])): ?>
                            <div style="font-size: 11pt; color: #555;">
                                <?= htmlspecialchars($settings['company_name_secondary']) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td style="text-align: left; vertical-align: top; font-size: 9pt; color: #666;">
                        <?php if (!empty($settings['company_address'])): ?>
                            <div><?= htmlspecialchars($settings['company_address']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($settings['company_phone'])): ?>
                            <div>تەلەفۆن: <?= htmlspecialchars($settings['company_phone']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($settings['company_email'])): ?>
                            <div><?= htmlspecialchars($settings['company_email']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Get absolute path from relative path
 *
 * @param string $relativePath Relative path
 * @return string Absolute path
 */
function getAbsolutePath($relativePath)
{
    if (strpos($relativePath, '/') === 0) {
        return APP_ROOT . $relativePath;
    }
    return APP_ROOT . '/' . $relativePath;
}
