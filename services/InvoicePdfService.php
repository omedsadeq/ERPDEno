<?php
/**
 * InvoicePdfService - Centralized PDF Invoice Generation Service
 *
 * This service is the ONLY authorized way to generate invoice PDFs.
 * It ensures consistent branding, headers, footers, and styling across all invoices.
 *
 * Usage:
 *   $pdfService = new InvoicePdfService();
 *   $pdfService->setContent($htmlContent);
 *   $pdfService->output('invoice.pdf', 'I'); // I = Inline, D = Download
 *
 * @author DentHisab System
 * @version 1.0
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/PdfSettings.php';

use Mpdf\Mpdf;

class InvoicePdfService
{
    private $mpdf;
    private $settings;
    private $content = '';

    /**
     * Constructor - Initializes mPDF with global settings
     *
     * @param array $customConfig Optional custom configuration to override settings
     *              Supported options:
     *              - 'orientation' => 'P' or 'L' (Portrait/Landscape)
     *              - 'format' => 'A4', 'A5', etc.
     *              - 'margin_left', 'margin_right', 'margin_top', 'margin_bottom' (in mm)
     * @throws Exception If settings cannot be loaded
     */
    public function __construct($customConfig = [])
    {
        $this->loadSettings();

        // Apply custom config overrides
        if (!empty($customConfig)) {
            foreach ($customConfig as $key => $value) {
                if ($key === 'orientation') {
                    $this->settings['page_orientation'] = $value;
                } elseif ($key === 'format') {
                    $this->settings['page_format'] = $value;
                } else {
                    $this->settings[$key] = $value;
                }
            }
        }

        $this->initializeMpdf();
        $this->applyStyles();
    }

    /**
     * Load active PDF settings from database
     */
    private function loadSettings()
    {
        $pdfSettings = new PdfSettings();
        $this->settings = $pdfSettings->getActiveSettings();

        // Use defaults if no settings found
        if (!$this->settings) {
            $this->settings = $pdfSettings->getDefaults();
        }

        // App-wide typography is NRT (fonts/NRT Font/) — map the legacy
        // default (XBRiyaz) and empty values to the registered 'nrt' face so
        // existing saved settings pick the new font up automatically.
        $storedFont = strtolower(trim($this->settings['font_family'] ?? ''));
        if ($storedFont === '' || $storedFont === 'xbriyaz') {
            $this->settings['font_family'] = 'nrt';
        }
    }

    /**
     * Initialize mPDF with configured settings
     */
    private function initializeMpdf()
    {
        // Register the NRT app font (fonts/NRT Font/NRT-Reg.ttf) with mpdf.
        // useOTL enables Arabic-script shaping so Kurdish/Arabic letters join.
        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();

        $config = [
            'mode' => 'utf-8',
            'format' => $this->settings['page_format'] ?? 'A4',
            'default_font_size' => $this->settings['font_size'] ?? 12,
            'default_font' => $this->settings['font_family'] ?? 'nrt',
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
            'margin_left' => $this->settings['margin_left_mm'] ?? 15,
            'margin_right' => $this->settings['margin_right_mm'] ?? 15,
            'margin_top' => $this->settings['margin_top_mm'] ?? 40,
            'margin_bottom' => $this->settings['margin_bottom_mm'] ?? 35,
            'margin_header' => 10,
            'margin_footer' => 10,
            'orientation' => $this->settings['page_orientation'] ?? 'P',
            'tempDir' => sys_get_temp_dir() . '/mpdf',

            // RTL and font settings
            'autoLangToFont' => true,
            'autoScriptToLang' => true,
        ];

        $this->mpdf = new Mpdf($config);

        // Set text direction
        if (($this->settings['direction'] ?? 'rtl') === 'rtl') {
            $this->mpdf->SetDirectionality('rtl');
        }

        // Apply watermark if configured
        if (!empty($this->settings['watermark_text'])) {
            $this->mpdf->SetWatermarkText(
                $this->settings['watermark_text'],
                $this->settings['watermark_opacity'] ?? 0.1
            );
            $this->mpdf->showWatermarkText = true;
        }

        // Apply header and footer
        $this->applyHeader();
        $this->applyFooter();
    }

    /**
     * Apply header to PDF
     */
    private function applyHeader()
    {
        if (empty($this->settings['show_header'])) {
            return;
        }

        $headerHtml = $this->buildHeaderHtml();

        if (!empty($headerHtml)) {
            $this->mpdf->SetHTMLHeader($headerHtml);
        }
    }

    /**
     * Apply footer to PDF
     */
    private function applyFooter()
    {
        if (empty($this->settings['show_footer'])) {
            return;
        }

        $footerHtml = $this->buildFooterHtml();

        if (!empty($footerHtml)) {
            $this->mpdf->SetHTMLFooter($footerHtml);
        }
    }

    /**
     * Build header HTML from settings
     *
     * @return string Header HTML
     */
    private function buildHeaderHtml()
    {
        // If custom header HTML is set, use it
        if (!empty($this->settings['header_html'])) {
            return $this->processTemplate($this->settings['header_html']);
        }

        $headerHeight = $this->settings['header_height_mm'] ?? 30;
        $borderColor = $this->settings['header_border_color'] ?? '#0099cc';
        $headerType = $this->settings['header_type'] ?? 'image';

        // Option 1: Full image header
        if ($headerType === 'image' && !empty($this->settings['header_image_path'])) {
            $imagePath = $this->getAbsolutePath($this->settings['header_image_path']);
            if (file_exists($imagePath)) {
                return '<div style="width: 100%; text-align: center; border-bottom: 1px solid ' . $borderColor . '; padding-bottom: 2mm;">
                    <img src="' . $imagePath . '" style="max-height: ' . ($headerHeight - 5) . 'mm; max-width: 100%;">
                </div>';
            }
        }

        // Option 2: Manual header design (logo center, text left/right)
        if ($headerType === 'manual') {
            $nameKu = $this->settings['header_company_name_ku'] ?? '';
            $nameEn = $this->settings['header_company_name_en'] ?? '';
            $phone1 = $this->settings['header_phone1'] ?? '';
            $phone2 = $this->settings['header_phone2'] ?? '';
            $address = $this->settings['header_address'] ?? '';

            // Styling options
            $showBoxBorder = $this->settings['header_show_box_border'] ?? 1;
            $boxBorderWidth = $this->settings['header_box_border_width'] ?? 1;
            $boxPadding = $this->settings['header_box_padding'] ?? 10;
            $boxBgColor = $this->settings['header_box_bg_color'] ?? '#ffffff';
            $nameKuColor = $this->settings['header_name_ku_color'] ?? '#333333';
            $nameEnColor = $this->settings['header_name_en_color'] ?? '#333333';
            $phoneColor = $this->settings['header_phone_color'] ?? '#666666';
            $addressColor = $this->settings['header_address_color'] ?? '#666666';
            $showPhoneIcon = $this->settings['header_show_phone_icon'] ?? 1;
            $nameKuSize = $this->settings['header_name_ku_size'] ?? 11;
            $nameEnSize = $this->settings['header_name_en_size'] ?? 11;
            $phoneSize = $this->settings['header_phone_size'] ?? 9;
            $addressSize = $this->settings['header_address_size'] ?? 9;

            // Phone icon (Unicode telephone symbol)
            $phoneIcon = $showPhoneIcon ? '📞 ' : '';

            // Get logo
            $logoHtml = '';
            if (!empty($this->settings['header_logo_path'])) {
                $logoPath = $this->getAbsolutePath($this->settings['header_logo_path']);
                if (file_exists($logoPath)) {
                    $logoHtml = '<img src="' . $logoPath . '" style="max-height: ' . ($headerHeight - 10) . 'mm;">';
                }
            }

            // Box border style
            $boxBorderStyle = $showBoxBorder ? "border: {$boxBorderWidth}px solid {$borderColor};" : '';
            $bottomBorderStyle = "border-bottom: {$boxBorderWidth}px solid {$borderColor};";

            $html = '<div style="width: 100%; ' . $boxBorderStyle . ' background: ' . $boxBgColor . '; padding: ' . $boxPadding . 'px; ' . (!$showBoxBorder ? $bottomBorderStyle : '') . '">';
            $html .= '<table width="100%">';
            $html .= '<tr>';

            // Left side (English name + phones)
            $html .= '<td width="30%" style="text-align: left; vertical-align: middle;">';
            if ($nameEn) {
                $html .= '<div style="font-weight: bold; font-size: ' . $nameEnSize . 'pt; color: ' . $nameEnColor . ';">' . htmlspecialchars($nameEn) . '</div>';
            }
            if ($phone1) {
                $html .= '<div style="font-size: ' . $phoneSize . 'pt; color: ' . $phoneColor . ';">' . $phoneIcon . htmlspecialchars($phone1) . '</div>';
            }
            if ($phone2) {
                $html .= '<div style="font-size: ' . $phoneSize . 'pt; color: ' . $phoneColor . ';">' . $phoneIcon . htmlspecialchars($phone2) . '</div>';
            }
            $html .= '</td>';

            // Center (Logo)
            $html .= '<td width="40%" style="text-align: center; vertical-align: middle;">';
            $html .= $logoHtml;
            $html .= '</td>';

            // Right side (Kurdish name + address)
            $html .= '<td width="30%" style="text-align: right; vertical-align: middle;">';
            if ($nameKu) {
                $html .= '<div style="font-weight: bold; font-size: ' . $nameKuSize . 'pt; color: ' . $nameKuColor . ';">' . htmlspecialchars($nameKu) . '</div>';
            }
            if ($address) {
                $html .= '<div style="font-size: ' . $addressSize . 'pt; color: ' . $addressColor . ';">' . htmlspecialchars($address) . '</div>';
            }
            $html .= '</td>';

            $html .= '</tr>';
            $html .= '</table>';
            $html .= '</div>';

            return $html;
        }

        // Fallback: Default header with company info
        $primaryColor = $this->settings['primary_color'] ?? '#2c5282';
        $html = '<div style="width: 100%; border-bottom: 1px solid ' . $borderColor . '; padding-bottom: 3mm;">';

        if (!empty($this->settings['company_name'])) {
            $html .= '<table width="100%" style="font-size: 10pt;">';
            $html .= '<tr>';
            $html .= '<td style="text-align: right;">';
            $html .= '<strong style="font-size: 12pt; color: ' . $primaryColor . ';">';
            $html .= htmlspecialchars($this->settings['company_name']);
            $html .= '</strong>';
            if (!empty($this->settings['company_name_secondary'])) {
                $html .= '<br><span style="font-size: 10pt;">' . htmlspecialchars($this->settings['company_name_secondary']) . '</span>';
            }
            $html .= '</td>';
            $html .= '<td style="text-align: left; font-size: 9pt; color: #666;">';
            if (!empty($this->settings['company_phone'])) {
                $html .= htmlspecialchars($this->settings['company_phone']);
            }
            $html .= '</td>';
            $html .= '</tr>';
            $html .= '</table>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Build footer HTML from settings
     *
     * @return string Footer HTML
     */
    private function buildFooterHtml()
    {
        // If custom footer HTML is set, use it
        if (!empty($this->settings['footer_html'])) {
            return $this->processTemplate($this->settings['footer_html']);
        }

        $footerHeight = $this->settings['footer_height_mm'] ?? 25;
        $borderColor = $this->settings['footer_border_color'] ?? '#0099cc';
        $footerType = $this->settings['footer_type'] ?? 'manual';

        // Option 1: Full image footer
        if ($footerType === 'image' && !empty($this->settings['footer_image_path'])) {
            $imagePath = $this->getAbsolutePath($this->settings['footer_image_path']);
            if (file_exists($imagePath)) {
                return '<div style="width: 100%; text-align: center; border-top: 1px solid ' . $borderColor . '; padding-top: 2mm;">
                    <img src="' . $imagePath . '" style="max-height: ' . ($footerHeight - 5) . 'mm; max-width: 100%;">
                </div>';
            }
        }

        // Option 2: Manual footer design
        $textRight = $this->settings['footer_text_right'] ?? 'سیستەمی زیرەکی کلینیکی ددان';
        $textLeft = $this->settings['footer_text_left'] ?? 'بەرواری چاپ: {DATETIME}';
        $bgColor = $this->settings['footer_bg_color'] ?? '#ffffff';
        $textColor = $this->settings['footer_text_color'] ?? '#666666';

        // Process template variables in footer text
        $textLeft = str_replace(
            ['{DATE}', '{DATETIME}'],
            [date('Y-m-d'), date('Y-m-d H:i')],
            $textLeft
        );

        $html = '<div style="width: 100%; border-top: 1px solid ' . $borderColor . '; padding-top: 2mm; font-size: 8pt; color: ' . $textColor . '; background: ' . $bgColor . ';">';
        $html .= '<table width="100%">';
        $html .= '<tr>';

        // Left side
        $html .= '<td style="text-align: left; color: ' . $textColor . ';">';
        $html .= htmlspecialchars($textLeft);
        $html .= '</td>';

        // Center - page numbers
        if (!empty($this->settings['show_page_numbers'])) {
            $html .= '<td style="text-align: center; color: ' . $textColor . ';">';
            $pageFormat = $this->settings['page_number_format'] ?? 'لاپەڕە {PAGENO} لە {nbpg}';
            $html .= $pageFormat;
            $html .= '</td>';
        }

        // Right side
        $html .= '<td style="text-align: right; color: ' . $textColor . ';">';
        $html .= htmlspecialchars($textRight);
        $html .= '</td>';

        $html .= '</tr>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Process template variables in HTML
     *
     * @param string $html Template HTML
     * @return string Processed HTML
     */
    private function processTemplate($html)
    {
        $replacements = [
            '{COMPANY_NAME}' => $this->settings['company_name'] ?? '',
            '{COMPANY_NAME_SECONDARY}' => $this->settings['company_name_secondary'] ?? '',
            '{COMPANY_ADDRESS}' => $this->settings['company_address'] ?? '',
            '{COMPANY_PHONE}' => $this->settings['company_phone'] ?? '',
            '{COMPANY_EMAIL}' => $this->settings['company_email'] ?? '',
            '{DATE}' => date('Y-m-d'),
            '{TIME}' => date('H:i'),
            '{DATETIME}' => date('Y-m-d H:i'),
            '{YEAR}' => date('Y'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /**
     * Apply CSS styles to mPDF
     */
    private function applyStyles()
    {
        $primaryColor = $this->settings['primary_color'] ?? '#2c5282';
        $secondaryColor = $this->settings['secondary_color'] ?? '#4a5568';
        $tableHeaderBg = $this->settings['table_header_bg'] ?? '#edf2f7';
        $tableBorderColor = $this->settings['table_border_color'] ?? '#cbd5e0';
        $fontFamily = $this->settings['font_family'] ?? 'nrt';
        $fontSize = $this->settings['font_size'] ?? 12;

        $css = "
            body {
                font-family: '{$fontFamily}', sans-serif;
                font-size: {$fontSize}pt;
                color: #333;
                line-height: 1.6;
            }

            h1, h2, h3, h4, h5, h6 {
                color: {$primaryColor};
                margin-bottom: 10px;
            }

            .invoice-title {
                font-size: 18pt;
                font-weight: bold;
                color: {$primaryColor};
                text-align: center;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 2px solid {$primaryColor};
            }

            .invoice-subtitle {
                font-size: 12pt;
                color: {$secondaryColor};
                text-align: center;
                margin-bottom: 10px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
            }

            table.invoice-table th {
                background-color: {$tableHeaderBg};
                color: {$primaryColor};
                font-weight: bold;
                padding: 10px 8px;
                border: 1px solid {$tableBorderColor};
                text-align: right;
            }

            table.invoice-table td {
                padding: 8px;
                border: 1px solid {$tableBorderColor};
                text-align: right;
            }

            table.invoice-table tr:nth-child(even) {
                background-color: #f9f9f9;
            }

            .info-box {
                background-color: #f7fafc;
                border: 1px solid {$tableBorderColor};
                border-radius: 5px;
                padding: 10px 15px;
                margin-bottom: 15px;
            }

            .info-row {
                margin-bottom: 5px;
            }

            .info-label {
                font-weight: bold;
                color: {$secondaryColor};
                display: inline-block;
                min-width: 100px;
            }

            .info-value {
                color: #333;
            }

            .total-row {
                background-color: {$tableHeaderBg} !important;
                font-weight: bold;
            }

            .total-amount {
                font-size: 14pt;
                color: {$primaryColor};
                font-weight: bold;
            }

            .text-primary { color: {$primaryColor}; }
            .text-secondary { color: {$secondaryColor}; }
            .text-success { color: #38a169; }
            .text-danger { color: #e53e3e; }
            .text-warning { color: #d69e2e; }

            .bg-primary { background-color: {$primaryColor}; color: white; }
            .bg-light { background-color: {$tableHeaderBg}; }

            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .text-left { text-align: left; }

            .mb-1 { margin-bottom: 5px; }
            .mb-2 { margin-bottom: 10px; }
            .mb-3 { margin-bottom: 15px; }
            .mb-4 { margin-bottom: 20px; }

            .mt-1 { margin-top: 5px; }
            .mt-2 { margin-top: 10px; }
            .mt-3 { margin-top: 15px; }
            .mt-4 { margin-top: 20px; }

            .p-1 { padding: 5px; }
            .p-2 { padding: 10px; }
            .p-3 { padding: 15px; }

            .border { border: 1px solid {$tableBorderColor}; }
            .border-bottom { border-bottom: 1px solid {$tableBorderColor}; }
            .border-top { border-top: 1px solid {$tableBorderColor}; }

            .badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 9pt;
            }

            .badge-success { background-color: #c6f6d5; color: #22543d; }
            .badge-warning { background-color: #fefcbf; color: #744210; }
            .badge-danger { background-color: #fed7d7; color: #742a2a; }
            .badge-info { background-color: #bee3f8; color: #2a4365; }

            /* Quadrant colors for dental chart */
            .quadrant-ul { background-color: #ebf8ff; }
            .quadrant-ur { background-color: #f0fff4; }
            .quadrant-ll { background-color: #fffaf0; }
            .quadrant-lr { background-color: #fff5f5; }
        ";

        // Add custom CSS if configured
        if (!empty($this->settings['custom_css'])) {
            $css .= "\n" . $this->settings['custom_css'];
        }

        $this->mpdf->WriteHTML('<style>' . $css . '</style>', \Mpdf\HTMLParserMode::HEADER_CSS);
    }

    /**
     * Get absolute file path from relative path
     *
     * @param string $relativePath Relative path
     * @return string Absolute path
     */
    private function getAbsolutePath($relativePath)
    {
        if (strpos($relativePath, '/') === 0) {
            return APP_ROOT . $relativePath;
        }
        return APP_ROOT . '/' . $relativePath;
    }

    /**
     * Set invoice content (HTML body only, no header/footer)
     *
     * @param string $html Invoice body HTML
     * @return self
     */
    public function setContent($html)
    {
        $this->content = $html;
        return $this;
    }

    /**
     * Add content to the PDF
     *
     * @param string $html HTML content to add
     * @return self
     */
    public function addContent($html)
    {
        $this->content .= $html;
        return $this;
    }

    /**
     * Add a page break
     *
     * @return self
     */
    public function addPageBreak()
    {
        $this->content .= '<pagebreak />';
        return $this;
    }

    /**
     * Write content and output PDF
     *
     * @param string $filename Output filename
     * @param string $destination Output destination: I=Inline, D=Download, F=File, S=String
     * @return string|null PDF content if destination is S, null otherwise
     */
    public function output($filename = 'invoice.pdf', $destination = 'I')
    {
        // Write the content
        $this->mpdf->WriteHTML($this->content);

        // Output the PDF
        return $this->mpdf->Output($filename, $destination);
    }

    /**
     * Save PDF to file
     *
     * @param string $filepath Full file path
     * @return bool Success status
     */
    public function saveToFile($filepath)
    {
        $this->mpdf->WriteHTML($this->content);
        $this->mpdf->Output($filepath, 'F');
        return file_exists($filepath);
    }

    /**
     * Get PDF as string
     *
     * @return string PDF content
     */
    public function getString()
    {
        $this->mpdf->WriteHTML($this->content);
        return $this->mpdf->Output('', 'S');
    }

    /**
     * Get current settings
     *
     * @return array Current PDF settings
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * Get mPDF instance for advanced usage
     * Note: Use with caution - prefer using service methods
     *
     * @return Mpdf mPDF instance
     */
    public function getMpdf()
    {
        return $this->mpdf;
    }

    /**
     * Set document title
     *
     * @param string $title Document title
     * @return self
     */
    public function setTitle($title)
    {
        $this->mpdf->SetTitle($title);
        return $this;
    }

    /**
     * Set document author
     *
     * @param string $author Author name
     * @return self
     */
    public function setAuthor($author)
    {
        $this->mpdf->SetAuthor($author);
        return $this;
    }

    /**
     * Override header for specific invoice
     *
     * @param string $headerHtml Custom header HTML
     * @return self
     */
    public function setCustomHeader($headerHtml)
    {
        $this->mpdf->SetHTMLHeader($headerHtml);
        return $this;
    }

    /**
     * Override footer for specific invoice
     *
     * @param string $footerHtml Custom footer HTML
     * @return self
     */
    public function setCustomFooter($footerHtml)
    {
        $this->mpdf->SetHTMLFooter($footerHtml);
        return $this;
    }

    /**
     * Disable header for this invoice
     *
     * @return self
     */
    public function disableHeader()
    {
        $this->mpdf->SetHTMLHeader('');
        return $this;
    }

    /**
     * Disable footer for this invoice
     *
     * @return self
     */
    public function disableFooter()
    {
        $this->mpdf->SetHTMLFooter('');
        return $this;
    }

    /**
     * Set page orientation
     *
     * @param string $orientation 'P' for portrait, 'L' for landscape
     * @return self
     */
    public function setOrientation($orientation)
    {
        $this->mpdf->AddPage($orientation);
        return $this;
    }

    /**
     * Helper: Format currency for invoice
     *
     * @param float $amount Amount
     * @param bool $showSymbol Show currency symbol
     * @return string Formatted amount
     */
    public static function formatCurrency($amount, $showSymbol = true)
    {
        return number_format((float)$amount, 0);
    }

    /**
     * Helper: Build info box HTML
     *
     * @param array $items Key-value pairs to display
     * @param string $title Optional box title
     * @return string HTML
     */
    public static function buildInfoBox($items, $title = null)
    {
        $html = '<div class="info-box">';

        if ($title) {
            $html .= '<div class="info-row" style="font-weight: bold; font-size: 12pt; margin-bottom: 8px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">' . htmlspecialchars($title) . '</div>';
        }

        foreach ($items as $label => $value) {
            $html .= '<div class="info-row">';
            $html .= '<span class="info-label">' . htmlspecialchars($label) . ':</span> ';
            $html .= '<span class="info-value">' . htmlspecialchars($value) . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Helper: Build invoice table HTML
     *
     * @param array $headers Table headers
     * @param array $rows Table data rows
     * @param array $totals Optional total row
     * @return string HTML
     */
    public static function buildTable($headers, $rows, $totals = null)
    {
        $html = '<table class="invoice-table">';

        // Headers
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>'; // Allow HTML in cells
            }
            $html .= '</tr>';
        }

        // Totals row
        if ($totals) {
            $html .= '<tr class="total-row">';
            foreach ($totals as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }
}
