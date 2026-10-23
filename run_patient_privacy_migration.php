<?php
/**
 * Migration Runner for Patient Privacy System
 * Run this file once to create the required tables for audit logging
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<h2>Running Patient Privacy System Migration...</h2>";

$db = Database::getInstance();

try {
    // Check if table already exists
    $checkTable = $db->fetch("SHOW TABLES LIKE 'patient_access_logs'");
    if ($checkTable) {
        echo "<p style='color: orange;'>Tables already exist. Checking for updates...</p>";
    } else {
        // Create patient_access_logs table
        $sql1 = "CREATE TABLE IF NOT EXISTS patient_access_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            user_id INT NOT NULL,
            access_type VARCHAR(50) NOT NULL COMMENT 'view_profile, view_xray, view_folder, view_financial',
            data_accessed TEXT NULL COMMENT 'JSON data of what was accessed',
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_patient_access_patient (patient_id),
            INDEX idx_patient_access_user (user_id),
            INDEX idx_patient_access_date (created_at),
            INDEX idx_patient_access_type (access_type),
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->query($sql1);
        echo "<p style='color: green;'>Created patient_access_logs table</p>";
    }

    // Check if doctor_permissions table exists
    $checkTable2 = $db->fetch("SHOW TABLES LIKE 'doctor_permissions'");
    if (!$checkTable2) {
        // Create doctor_permissions table
        $sql2 = "CREATE TABLE IF NOT EXISTS doctor_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doctor_id INT NOT NULL UNIQUE,
            can_view_other_xrays TINYINT(1) DEFAULT 1 COMMENT 'Can view X-rays of other doctors patients',
            can_view_other_profiles TINYINT(1) DEFAULT 1 COMMENT 'Can view basic profiles of other patients',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $db->query($sql2);
        echo "<p style='color: green;'>Created doctor_permissions table</p>";
    } else {
        echo "<p style='color: orange;'>doctor_permissions table already exists</p>";
    }

    echo "<h3 style='color: green;'>Migration completed successfully!</h3>";
    echo "<p>The Patient Privacy System is now ready.</p>";
    echo "<p><strong>Features enabled:</strong></p>";
    echo "<ul>";
    echo "<li>Doctors can only see their own patients by default</li>";
    echo "<li>Doctors can search other clinic patients with restricted view</li>";
    echo "<li>Restricted view shows only basic profile and X-rays</li>";
    echo "<li>All patient data access is logged for audit</li>";
    echo "</ul>";
    echo "<p>You can now delete this file.</p>";
    echo "<p><a href='patients/index.php'>Go to Patients</a></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
