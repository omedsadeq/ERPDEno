<?php
/**
 * Migration: Add header/footer options columns to pdf_invoice_settings
 * Run this file to add the new columns for header/footer design options
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

echo "<h2>Adding Header/Footer Options Columns</h2>";

try {
    $db = Database::getInstance();

    // List of columns to add
    $columns = [
        // Header options - basic
        "header_type VARCHAR(20) DEFAULT 'image'",
        "header_logo_path VARCHAR(255) DEFAULT NULL",
        "header_company_name_ku VARCHAR(255) DEFAULT NULL",
        "header_company_name_en VARCHAR(255) DEFAULT NULL",
        "header_phone1 VARCHAR(50) DEFAULT NULL",
        "header_phone2 VARCHAR(50) DEFAULT NULL",
        "header_address VARCHAR(255) DEFAULT NULL",
        "header_border_color VARCHAR(20) DEFAULT '#0099cc'",

        // Header options - advanced styling
        "header_show_box_border TINYINT(1) DEFAULT 1",
        "header_box_border_width INT DEFAULT 1",
        "header_box_padding INT DEFAULT 10",
        "header_box_bg_color VARCHAR(20) DEFAULT '#ffffff'",
        "header_name_ku_color VARCHAR(20) DEFAULT '#333333'",
        "header_name_en_color VARCHAR(20) DEFAULT '#333333'",
        "header_phone_color VARCHAR(20) DEFAULT '#666666'",
        "header_address_color VARCHAR(20) DEFAULT '#666666'",
        "header_show_phone_icon TINYINT(1) DEFAULT 1",
        "header_name_ku_size INT DEFAULT 11",
        "header_name_en_size INT DEFAULT 11",
        "header_phone_size INT DEFAULT 9",
        "header_address_size INT DEFAULT 9",

        // Footer options
        "footer_type VARCHAR(20) DEFAULT 'manual'",
        "footer_text_right VARCHAR(255) DEFAULT NULL",
        "footer_text_left VARCHAR(255) DEFAULT NULL",
        "footer_border_color VARCHAR(20) DEFAULT '#0099cc'",
        "footer_bg_color VARCHAR(20) DEFAULT '#ffffff'",
        "footer_text_color VARCHAR(20) DEFAULT '#666666'"
    ];

    $added = 0;
    $skipped = 0;

    foreach ($columns as $columnDef) {
        // Extract column name from definition
        preg_match('/^(\w+)/', $columnDef, $matches);
        $columnName = $matches[1];

        // Check if column exists
        try {
            $db->query("SELECT {$columnName} FROM pdf_invoice_settings LIMIT 1");
            echo "<p style='color: orange;'>⚠️ Column '{$columnName}' already exists - skipped</p>";
            $skipped++;
        } catch (Exception $e) {
            // Column doesn't exist, add it
            $sql = "ALTER TABLE pdf_invoice_settings ADD COLUMN {$columnDef}";
            $db->query($sql);
            echo "<p style='color: green;'>✅ Added column: {$columnName}</p>";
            $added++;
        }
    }

    echo "<hr>";
    echo "<p><strong>Summary:</strong> {$added} columns added, {$skipped} columns skipped (already exist)</p>";
    echo "<p style='color: green; font-weight: bold;'>✅ Migration completed successfully!</p>";
    echo "<p><a href='" . APP_URL . "/admin/pdf_settings.php'>← Back to PDF Settings</a></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
