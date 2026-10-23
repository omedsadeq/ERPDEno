<?php
/**
 * Migration Runner for Real-time Notifications System
 * سیستەمی ئاگادارکردنەوەی ڕاستەوخۆ
 * Run this file once to create the required tables
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<h2>Running Notifications System Migration...</h2>";

$db = Database::getInstance();

try {
    // Check if table already exists
    $checkTable = $db->fetch("SHOW TABLES LIKE 'notifications'");
    if ($checkTable) {
        echo "<p style='color: orange;'>⚠ Tables already exist. Skipping migration.</p>";
        echo "<p><a href='waiting-room.php'>Go to Waiting Room</a></p>";
        exit;
    }

    // Create notifications table
    $sql1 = "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL COMMENT 'Recipient user ID',
        type VARCHAR(50) NOT NULL COMMENT 'Type: patient_called, patient_waiting, xray_ready, etc.',
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        data JSON DEFAULT NULL COMMENT 'Additional data like patient_id, waiting_id, etc.',
        is_read TINYINT(1) DEFAULT 0,
        is_seen TINYINT(1) DEFAULT 0 COMMENT 'Seen in notification dropdown but not clicked',
        created_by INT DEFAULT NULL COMMENT 'User who triggered the notification',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL DEFAULT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Auto-expire notifications',

        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,

        INDEX idx_user_unread (user_id, is_read),
        INDEX idx_user_unseen (user_id, is_seen),
        INDEX idx_type (type),
        INDEX idx_created (created_at),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->query($sql1);
    echo "<p style='color: green;'>✓ Created notifications table</p>";

    // Create notification_settings table for user preferences
    $sql2 = "CREATE TABLE IF NOT EXISTS notification_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        sound_enabled TINYINT(1) DEFAULT 1,
        toast_enabled TINYINT(1) DEFAULT 1,
        patient_called_enabled TINYINT(1) DEFAULT 1,
        patient_waiting_enabled TINYINT(1) DEFAULT 1,
        xray_ready_enabled TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->query($sql2);
    echo "<p style='color: green;'>✓ Created notification_settings table</p>";

    // Add last_notification_check column to users table if not exists
    $checkColumn = $db->fetch("SHOW COLUMNS FROM users LIKE 'last_notification_check'");
    if (!$checkColumn) {
        $db->query("ALTER TABLE users ADD COLUMN last_notification_check TIMESTAMP NULL DEFAULT NULL");
        echo "<p style='color: green;'>✓ Added last_notification_check column to users table</p>";
    }

    echo "<h3 style='color: green;'>✓ Migration completed successfully!</h3>";
    echo "<p>You can now delete this file.</p>";
    echo "<p><a href='waiting-room.php'>Go to Waiting Room</a></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
