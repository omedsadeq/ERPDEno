<?php
/**
 * Fix Notifications Table - Add missing columns
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<h2>Fixing Notifications Table...</h2>";

$db = Database::getInstance();

try {
    // Check if notifications table exists
    $checkTable = $db->fetch("SHOW TABLES LIKE 'notifications'");

    if (!$checkTable) {
        echo "<p style='color: orange;'>Notifications table does not exist. Please run the main migration first.</p>";
        echo "<p><a href='run_notifications_migration.php'>Run Main Migration</a></p>";
        exit;
    }

    // Check and add missing columns
    $columns = $db->fetchAll("SHOW COLUMNS FROM notifications");
    $existingColumns = array_column($columns, 'Field');

    // Fix 'type' column size if it exists
    if (in_array('type', $existingColumns)) {
        $db->query("ALTER TABLE notifications MODIFY COLUMN type VARCHAR(50) NOT NULL COMMENT 'Type: patient_called, patient_waiting, xray_ready, etc.'");
        echo "<p style='color: green;'>✓ Fixed 'type' column size</p>";
    }

    // Fix 'title' column size if it exists
    if (in_array('title', $existingColumns)) {
        $db->query("ALTER TABLE notifications MODIFY COLUMN title VARCHAR(255) NOT NULL");
        echo "<p style='color: green;'>✓ Fixed 'title' column size</p>";
    }

    // Add 'data' column if missing
    if (!in_array('data', $existingColumns)) {
        $db->query("ALTER TABLE notifications ADD COLUMN data JSON DEFAULT NULL COMMENT 'Additional data like patient_id, waiting_id, etc.' AFTER message");
        echo "<p style='color: green;'>✓ Added 'data' column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ 'data' column already exists</p>";
    }

    // Add 'is_seen' column if missing
    if (!in_array('is_seen', $existingColumns)) {
        $db->query("ALTER TABLE notifications ADD COLUMN is_seen TINYINT(1) DEFAULT 0 COMMENT 'Seen in notification dropdown but not clicked' AFTER is_read");
        echo "<p style='color: green;'>✓ Added 'is_seen' column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ 'is_seen' column already exists</p>";
    }

    // Add 'read_at' column if missing
    if (!in_array('read_at', $existingColumns)) {
        $db->query("ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER created_at");
        echo "<p style='color: green;'>✓ Added 'read_at' column</p>";
        // Refresh columns list
        $columns = $db->fetchAll("SHOW COLUMNS FROM notifications");
        $existingColumns = array_column($columns, 'Field');
    } else {
        echo "<p style='color: blue;'>ℹ 'read_at' column already exists</p>";
    }

    // Add 'expires_at' column if missing
    if (!in_array('expires_at', $existingColumns)) {
        $db->query("ALTER TABLE notifications ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Auto-expire notifications' AFTER read_at");
        echo "<p style='color: green;'>✓ Added 'expires_at' column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ 'expires_at' column already exists</p>";
    }

    // Add 'created_by' column if missing
    if (!in_array('created_by', $existingColumns)) {
        $db->query("ALTER TABLE notifications ADD COLUMN created_by INT DEFAULT NULL COMMENT 'User who triggered the notification' AFTER is_seen");
        echo "<p style='color: green;'>✓ Added 'created_by' column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ 'created_by' column already exists</p>";
    }

    // Add indexes if they don't exist
    $indexes = $db->fetchAll("SHOW INDEX FROM notifications");
    $existingIndexes = array_column($indexes, 'Key_name');

    if (!in_array('idx_user_unseen', $existingIndexes)) {
        $db->query("ALTER TABLE notifications ADD INDEX idx_user_unseen (user_id, is_seen)");
        echo "<p style='color: green;'>✓ Added idx_user_unseen index</p>";
    }

    if (!in_array('idx_expires', $existingIndexes)) {
        $db->query("ALTER TABLE notifications ADD INDEX idx_expires (expires_at)");
        echo "<p style='color: green;'>✓ Added idx_expires index</p>";
    }

    // Check users table for last_notification_check column
    $userColumns = $db->fetchAll("SHOW COLUMNS FROM users");
    $existingUserColumns = array_column($userColumns, 'Field');

    if (!in_array('last_notification_check', $existingUserColumns)) {
        $db->query("ALTER TABLE users ADD COLUMN last_notification_check TIMESTAMP NULL DEFAULT NULL");
        echo "<p style='color: green;'>✓ Added 'last_notification_check' column to users table</p>";
    } else {
        echo "<p style='color: blue;'>ℹ 'last_notification_check' column already exists in users table</p>";
    }

    // Check if notification_settings table exists
    $checkSettingsTable = $db->fetch("SHOW TABLES LIKE 'notification_settings'");
    if (!$checkSettingsTable) {
        $sql = "CREATE TABLE IF NOT EXISTS notification_settings (
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
        $db->query($sql);
        echo "<p style='color: green;'>✓ Created notification_settings table</p>";
    } else {
        echo "<p style='color: blue;'>ℹ notification_settings table already exists</p>";
    }

    echo "<h3 style='color: green;'>✓ Fix completed successfully!</h3>";
    echo "<p>You can now delete this file.</p>";
    echo "<p><a href='waiting-room.php'>Go to Waiting Room</a></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
