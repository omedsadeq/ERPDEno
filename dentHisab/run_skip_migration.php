<?php
require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

$db = Database::getInstance()->getConnection();

try {
    $db->exec("ALTER TABLE installment_schedule MODIFY COLUMN status ENUM('pending', 'paid', 'overdue', 'partial', 'skipped') DEFAULT 'pending'");
    echo "Migration successful: 'skipped' status added to installment_schedule";
} catch (PDOException $e) {
    echo "Migration: " . $e->getMessage();
}
