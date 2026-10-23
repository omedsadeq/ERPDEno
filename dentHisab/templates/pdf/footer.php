<?php
/**
 * PDF Footer Template
 * This template generates the footer HTML for invoice PDFs
 *
 * Available variables:
 * $settings - PDF settings array from database
 * $invoiceData - Optional invoice-specific data array
 *
 * Template variables supported in footer_html:
 * {COMPANY_NAME}, {COMPANY_PHONE}, {DATE}, {TIME}, {DATETIME}, {YEAR}
 * {PAGENO}, {nbpg} - Page numbers (handled by mPDF)
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Generate footer HTML from settings
 *
 * @param array $settings PDF settings
 * @param array $invoiceData Optional invoice data
 * @return string Footer HTML
 */
function generatePdfFooter($settings, $invoiceData = [])
{
    // If custom footer HTML is defined, use it
    if (!empty($settings['footer_html'])) {
        return processFooterTemplate($settings['footer_html'], $settings, $invoiceData);
    }

    // Generate default footer
    return generateDefaultFooter($settings, $invoiceData);
}

/**
 * Process template variables in footer HTML
 *
 * @param string $html Template HTML
 * @param array $settings Settings array
 * @param array $invoiceData Invoice data
 * @return string Processed HTML
 */
function processFooterTemplate($html, $settings, $invoiceData = [])
{
    $replacements = [
        '{COMPANY_NAME}' => $settings['company_name'] ?? '',
        '{COMPANY_PHONE}' => $settings['company_phone'] ?? '',
        '{DATE}' => date('Y-m-d'),
        '{TIME}' => date('H:i'),
        '{DATETIME}' => date('Y-m-d H:i'),
        '{YEAR}' => date('Y'),
        '{INVOICE_NUMBER}' => $invoiceData['invoice_number'] ?? '',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $html);
}

/**
 * Generate default footer structure
 *
 * @param array $settings Settings array
 * @param array $invoiceData Invoice data
 * @return string Footer HTML
 */
function generateDefaultFooter($settings, $invoiceData = [])
{
    $primaryColor = $settings['primary_color'] ?? '#2c5282';
    $footerHeight = $settings['footer_height_mm'] ?? 25;
    $showPageNumbers = $settings['show_page_numbers'] ?? 1;
    $pageNumberFormat = $settings['page_number_format'] ?? 'لاپەڕە {PAGENO} لە {nbpg}';

    ob_start();
    ?>
    <div style="width: 100%; border-top: 1px solid <?= $primaryColor ?>; padding-top: 2mm; font-size: 8pt; color: #666;">
        <?php if (!empty($settings['footer_image_path'])): ?>
            <?php
            $imagePath = getAbsolutePath($settings['footer_image_path']);
            if (file_exists($imagePath)):
            ?>
                <div style="text-align: center; margin-bottom: 2mm;">
                    <img src="<?= $imagePath ?>" style="max-height: <?= $footerHeight - 12 ?>mm; max-width: 100%;">
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <table width="100%" style="font-size: 8pt;">
            <tr>
                <!-- Left: Print date -->
                <td style="text-align: left; width: 33%;">
                    بەرواری چاپ: <?= date('Y-m-d H:i') ?>
                </td>

                <!-- Center: Page numbers -->
                <td style="text-align: center; width: 34%;">
                    <?php if ($showPageNumbers): ?>
                        <?= $pageNumberFormat ?>
                    <?php endif; ?>
                </td>

                <!-- Right: System credit -->
                <td style="text-align: right; width: 33%;">
                    سیستەمی زیرەکی کلینیکی ددان
                </td>
            </tr>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Get absolute path helper (if not already defined)
 */
if (!function_exists('getAbsolutePath')) {
    function getAbsolutePath($relativePath)
    {
        if (strpos($relativePath, '/') === 0) {
            return APP_ROOT . $relativePath;
        }
        return APP_ROOT . '/' . $relativePath;
    }
}
