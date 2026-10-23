<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Database.php';

$db = Database::getInstance();

$statements = [
    "ALTER TABLE appointments ADD COLUMN cancellation_reason VARCHAR(100) DEFAULT NULL AFTER status",
    "ALTER TABLE appointments ADD COLUMN cancellation_comment TEXT DEFAULT NULL AFTER cancellation_reason",
    "ALTER TABLE appointments ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER cancellation_comment"
];

foreach ($statements as $stmt) {
    try {
        $db->query($stmt);
        echo "OK: " . substr($stmt, 0, 70) . "...\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "Skip (exists): " . substr($stmt, 0, 50) . "...\n";
        } else {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nMigration completed!\n";
