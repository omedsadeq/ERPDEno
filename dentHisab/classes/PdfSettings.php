<?php
/**
 * PdfSettings Manager Class
 * Manages PDF invoice settings from database
 *
 * @author DentHisab System
 * @version 1.0
 */

require_once __DIR__ . '/Database.php';

class PdfSettings
{
    private $db;
    private static $cachedSettings = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get active PDF settings
     * Uses caching to avoid multiple database queries
     *
     * @return array|null Active settings or null if none found
     */
    public function getActiveSettings()
    {
        if (self::$cachedSettings !== null) {
            return self::$cachedSettings;
        }

        try {
            $settings = $this->db->fetch(
                "SELECT * FROM pdf_invoice_settings WHERE is_active = 1 LIMIT 1"
            );

            if ($settings) {
                self::$cachedSettings = $settings;
            }

            return $settings;
        } catch (PDOException $e) {
            // Table might not exist yet
            return null;
        }
    }

    /**
     * Check if the pdf_invoice_settings table exists
     *
     * @return bool True if table exists
     */
    public function tableExists()
    {
        try {
            // Try to select from the table - if it fails, table doesn't exist
            $this->db->query("SELECT 1 FROM pdf_invoice_settings LIMIT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Clear cached settings (call after update)
     */
    public static function clearCache()
    {
        self::$cachedSettings = null;
    }

    /**
     * Get all settings records
     *
     * @return array All settings records
     */
    public function getAll()
    {
        return $this->db->fetchAll(
            "SELECT * FROM pdf_invoice_settings ORDER BY is_active DESC, id DESC"
        );
    }

    /**
     * Get settings by ID
     *
     * @param int $id Settings ID
     * @return array|null Settings record
     */
    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT * FROM pdf_invoice_settings WHERE id = ?",
            [$id]
        );
    }

    /**
     * Create new PDF settings
     *
     * @param array $data Settings data
     * @return int|false New record ID or false on failure
     */
    public function create($data)
    {
        // If this is being set as active, deactivate others
        if (!empty($data['is_active'])) {
            $this->deactivateAll();
        }

        $fields = $this->sanitizeData($data);

        $result = $this->db->insert('pdf_invoice_settings', $fields);
        self::clearCache();

        return $result;
    }

    /**
     * Update PDF settings
     *
     * @param int $id Settings ID
     * @param array $data Settings data
     * @return bool Success status
     */
    public function update($id, $data)
    {
        // If this is being set as active, deactivate others
        if (!empty($data['is_active'])) {
            $this->deactivateAll();
        }

        $fields = $this->sanitizeData($data);

        $result = $this->db->update('pdf_invoice_settings', $fields, 'id = :where_id', ['where_id' => $id]);
        self::clearCache();

        return $result;
    }

    /**
     * Delete PDF settings
     *
     * @param int $id Settings ID
     * @return bool Success status
     */
    public function delete($id)
    {
        $result = $this->db->delete('pdf_invoice_settings', 'id = :id', ['id' => $id]);
        self::clearCache();

        return $result;
    }

    /**
     * Activate specific settings and deactivate others
     *
     * @param int $id Settings ID to activate
     * @return bool Success status
     */
    public function activate($id)
    {
        $this->deactivateAll();
        $result = $this->db->update(
            'pdf_invoice_settings',
            ['is_active' => 1],
            'id = :where_id',
            ['where_id' => $id]
        );
        self::clearCache();

        return $result;
    }

    /**
     * Deactivate all settings
     */
    private function deactivateAll()
    {
        $this->db->query("UPDATE pdf_invoice_settings SET is_active = 0");
    }

    /**
     * Sanitize and validate settings data
     *
     * @param array $data Raw input data
     * @return array Sanitized data
     */
    private function sanitizeData($data)
    {
        $allowed = [
            'header_image_path', 'header_html', 'header_height_mm', 'show_header',
            'header_type', 'header_logo_path', 'header_company_name_ku', 'header_company_name_en',
            'header_phone1', 'header_phone2', 'header_address', 'header_border_color',
            'header_show_box_border', 'header_box_border_width', 'header_box_padding', 'header_box_bg_color',
            'header_name_ku_color', 'header_name_en_color', 'header_phone_color', 'header_address_color',
            'header_show_phone_icon', 'header_name_ku_size', 'header_name_en_size', 'header_phone_size', 'header_address_size',
            'footer_image_path', 'footer_html', 'footer_height_mm', 'show_footer',
            'footer_type', 'footer_text_right', 'footer_text_left', 'footer_border_color',
            'footer_bg_color', 'footer_text_color',
            'margin_left_mm', 'margin_right_mm', 'margin_top_mm', 'margin_bottom_mm',
            'font_family', 'font_size', 'primary_color', 'secondary_color',
            'table_header_bg', 'table_border_color',
            'page_format', 'page_orientation', 'direction',
            'watermark_text', 'watermark_opacity',
            'show_page_numbers', 'page_number_format',
            'company_name', 'company_name_secondary', 'company_address',
            'company_phone', 'company_email',
            'custom_css', 'is_active'
        ];

        $sanitized = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];

                // Handle numeric fields
                if (strpos($field, '_mm') !== false || $field === 'font_size') {
                    $sanitized[$field] = (int) $value;
                }
                // Handle boolean fields
                elseif (in_array($field, ['show_header', 'show_footer', 'show_page_numbers', 'is_active'])) {
                    $sanitized[$field] = $value ? 1 : 0;
                }
                // Handle opacity
                elseif ($field === 'watermark_opacity') {
                    $sanitized[$field] = min(1, max(0, (float) $value));
                }
                // Handle string fields
                else {
                    $sanitized[$field] = trim($value);
                }
            }
        }

        // Validate margin safety rules
        if (isset($sanitized['header_height_mm']) && isset($sanitized['margin_top_mm'])) {
            $minTopMargin = $sanitized['header_height_mm'] + 10;
            if ($sanitized['margin_top_mm'] < $minTopMargin) {
                $sanitized['margin_top_mm'] = $minTopMargin;
            }
        }

        if (isset($sanitized['footer_height_mm']) && isset($sanitized['margin_bottom_mm'])) {
            $minBottomMargin = $sanitized['footer_height_mm'] + 10;
            if ($sanitized['margin_bottom_mm'] < $minBottomMargin) {
                $sanitized['margin_bottom_mm'] = $minBottomMargin;
            }
        }

        return $sanitized;
    }

    /**
     * Upload header image
     *
     * @param array $file $_FILES array element
     * @param string $prefix Optional filename prefix
     * @return string|false File path or false on failure
     */
    public function uploadHeaderImage($file, $prefix = 'header_')
    {
        return $this->uploadImage($file, $prefix);
    }

    /**
     * Upload footer image
     *
     * @param array $file $_FILES array element
     * @param string $prefix Optional filename prefix
     * @return string|false File path or false on failure
     */
    public function uploadFooterImage($file, $prefix = 'footer_')
    {
        return $this->uploadImage($file, $prefix);
    }

    /**
     * Handle image upload
     *
     * @param array $file $_FILES array element
     * @param string $prefix Filename prefix
     * @return string|false File path or false on failure
     */
    private function uploadImage($file, $prefix)
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('تەنها وێنەی JPEG, PNG, و GIF ڕێگەپێدراوە');
        }

        if ($file['size'] > $maxSize) {
            throw new Exception('قەبارەی وێنە زۆرە (حەدی زۆر 5MB)');
        }

        $uploadDir = APP_ROOT . '/uploads/pdf_assets/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $prefix . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return '/uploads/pdf_assets/' . $filename;
        }

        return false;
    }

    /**
     * Get default settings structure
     *
     * @return array Default settings
     */
    public function getDefaults()
    {
        return [
            'header_image_path' => null,
            'header_html' => null,
            'header_height_mm' => 30,
            'show_header' => 1,
            'header_type' => 'image',
            'header_logo_path' => null,
            'header_company_name_ku' => null,
            'header_company_name_en' => null,
            'header_phone1' => null,
            'header_phone2' => null,
            'header_address' => null,
            'header_border_color' => '#0099cc',
            'header_show_box_border' => 1,
            'header_box_border_width' => 1,
            'header_box_padding' => 10,
            'header_box_bg_color' => '#ffffff',
            'header_name_ku_color' => '#333333',
            'header_name_en_color' => '#333333',
            'header_phone_color' => '#666666',
            'header_address_color' => '#666666',
            'header_show_phone_icon' => 1,
            'header_name_ku_size' => 11,
            'header_name_en_size' => 11,
            'header_phone_size' => 9,
            'header_address_size' => 9,
            'footer_image_path' => null,
            'footer_html' => null,
            'footer_height_mm' => 25,
            'show_footer' => 1,
            'footer_type' => 'manual',
            'footer_text_right' => 'سیستەمی زیرەکی کلینیکی ددان',
            'footer_text_left' => 'بەرواری چاپ: {DATETIME}',
            'footer_border_color' => '#0099cc',
            'footer_bg_color' => '#ffffff',
            'footer_text_color' => '#666666',
            'margin_left_mm' => 15,
            'margin_right_mm' => 15,
            'margin_top_mm' => 40,
            'margin_bottom_mm' => 35,
            'font_family' => 'nrt',
            'font_size' => 12,
            'primary_color' => '#2c5282',
            'secondary_color' => '#4a5568',
            'table_header_bg' => '#edf2f7',
            'table_border_color' => '#cbd5e0',
            'page_format' => 'A4',
            'page_orientation' => 'P',
            'direction' => 'rtl',
            'watermark_text' => null,
            'watermark_opacity' => 0.10,
            'show_page_numbers' => 1,
            'page_number_format' => 'لاپەڕە {PAGENO} لە {nbpg}',
            'company_name' => null,
            'company_name_secondary' => null,
            'company_address' => null,
            'company_phone' => null,
            'company_email' => null,
            'custom_css' => null,
            'is_active' => 1
        ];
    }
}
