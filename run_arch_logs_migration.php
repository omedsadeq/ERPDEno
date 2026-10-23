<?php
/**
 * Migration Runner for Arch Procedure Logs
 * Run this file once to create the required tables
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<h2>Running Arch Procedure Logs Migration...</h2>";

$db = Database::getInstance();

try {
    // Check if table already exists
    $checkTable = $db->fetch("SHOW TABLES LIKE 'arch_procedure_logs'");
    if ($checkTable) {
        echo "<p style='color: orange;'>⚠ Tables already exist. Skipping migration.</p>";
        exit;
    }

    // Create procedure_types table
    $sql1 = "CREATE TABLE IF NOT EXISTS arch_procedure_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_code VARCHAR(50) NOT NULL UNIQUE,
        type_name VARCHAR(100) NOT NULL COMMENT 'Kurdish name',
        type_name_en VARCHAR(100) DEFAULT NULL COMMENT 'English name',
        description TEXT DEFAULT NULL,
        default_price DECIMAL(10,2) DEFAULT 0.00,
        is_active TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->query($sql1);
    echo "<p style='color: green;'>✓ Created arch_procedure_types table</p>";

    // Insert default procedure types
    $sql2 = "INSERT INTO arch_procedure_types (type_code, type_name, type_name_en, description, default_price, sort_order) VALUES
    ('monthly_adjustment', 'توندکردنەوەی مانگانە', 'Monthly Adjustment', 'توندکردنەوەی مانگانەی ئارچی سەرەوە/خوارەوە', 0.00, 1),
    ('wire_change', 'گۆڕینی وایەر', 'Wire Change', 'گۆڕینی وایەری ئارچ', 0.00, 2),
    ('bracket_repair', 'چاککردنەوەی برێکێت', 'Bracket Repair', 'چاککردنەوەی برێکێتی شکاو یان لێبوو', 0.00, 3),
    ('emergency_visit', 'سەردانی کتوپڕ', 'Emergency Visit', 'سەردانی کتوپڕ بۆ کێشەی ڕوویداو', 0.00, 4),
    ('elastic_change', 'گۆڕینی لاستیک', 'Elastic Change', 'گۆڕینی لاستیکەکان', 0.00, 5),
    ('retainer_check', 'پشکنینی ڕیتەینەر', 'Retainer Check', 'پشکنینی ڕیتەینەر دوای تەواوبوونی چارەسەر', 0.00, 6),
    ('other', 'کاری تر', 'Other', 'کاری تر کە لە لیستەکەدا نییە', 0.00, 99)
    ON DUPLICATE KEY UPDATE type_name = VALUES(type_name)";

    $db->query($sql2);
    echo "<p style='color: green;'>✓ Inserted default procedure types</p>";

    // Create main procedure logs table
    $sql3 = "CREATE TABLE IF NOT EXISTS arch_procedure_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT NOT NULL,
        procedure_type_id INT DEFAULT NULL,
        custom_procedure_name VARCHAR(200) DEFAULT NULL COMMENT 'For custom/other procedures',
        arch_affected ENUM('upper', 'lower', 'both') DEFAULT 'both' COMMENT 'Which arch was worked on',
        procedure_date DATE NOT NULL,
        procedure_time TIME DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        price DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Price charged for this procedure',
        is_paid TINYINT(1) DEFAULT 0,
        next_appointment_date DATE DEFAULT NULL,
        next_appointment_notes VARCHAR(500) DEFAULT NULL,
        performed_by INT DEFAULT NULL COMMENT 'User ID of doctor/staff',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
        FOREIGN KEY (procedure_type_id) REFERENCES arch_procedure_types(id) ON DELETE SET NULL,
        FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,

        INDEX idx_case_date (case_id, procedure_date),
        INDEX idx_procedure_type (procedure_type_id),
        INDEX idx_next_appointment (next_appointment_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->query($sql3);
    echo "<p style='color: green;'>✓ Created arch_procedure_logs table</p>";

    echo "<h3 style='color: green;'>✓ Migration completed successfully!</h3>";
    echo "<p>You can now delete this file.</p>";
    echo "<p><a href='cases/index.php'>Go to Cases</a></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
