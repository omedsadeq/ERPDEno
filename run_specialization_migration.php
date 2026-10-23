<?php
/**
 * Migration: Add specialization column to users table
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

$db = Database::getInstance();

echo "Adding specialization column to users table...\n";

try {
    // Check if column already exists
    $columns = $db->fetchAll("SHOW COLUMNS FROM users LIKE 'specialization'");

    if (empty($columns)) {
        $db->query("ALTER TABLE users ADD COLUMN specialization VARCHAR(100) AFTER role");
        echo "SUCCESS: specialization column added to users table.\n";
    } else {
        echo "Column 'specialization' already exists. No changes needed.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nMigration completed successfully!\n";
echo "You can now access the doctors page.\n";
