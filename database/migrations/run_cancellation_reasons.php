<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Database.php';

$db = Database::getInstance();

// Create table
$createTable = "CREATE TABLE IF NOT EXISTS cancellation_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason_key VARCHAR(50) NOT NULL UNIQUE,
    reason_text VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

try {
    $db->query($createTable);
    echo "OK: Table created\n";
} catch (Exception $e) {
    echo "Note: " . $e->getMessage() . "\n";
}

// Insert default reasons
$defaults = [
    ['patient_request', 'نەخۆش داوای هەڵوەشاندنەوەی کرد', 1],
    ['patient_no_answer', 'نەخۆش وەڵامی نەدایەوە', 2],
    ['patient_sick', 'نەخۆش نەخۆشە', 3],
    ['schedule_conflict', 'کێشەی کات', 4],
    ['doctor_unavailable', 'دکتۆر بەردەست نییە', 5],
    ['emergency', 'کەیسی فریاگوزاری', 6],
    ['other', 'هۆکاری تر', 99]
];

foreach ($defaults as $reason) {
    try {
        $db->query(
            "INSERT IGNORE INTO cancellation_reasons (reason_key, reason_text, sort_order) VALUES (?, ?, ?)",
            [$reason[0], $reason[1], $reason[2]]
        );
        echo "OK: Added reason - {$reason[1]}\n";
    } catch (Exception $e) {
        echo "Skip: {$reason[1]}\n";
    }
}

echo "\nMigration completed!\n";
