<?php
/**
 * Run Flexible Installments Migration
 * Execute this file once to add the installment_payments table
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<pre>";
echo "=== Running Flexible Installments Migration ===\n\n";

$db = Database::getInstance()->getConnection();

try {
    // Read migration file
    $sql = file_get_contents(__DIR__ . '/database/migrations/add_flexible_installments.sql');

    // Split into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }

        try {
            $db->exec($statement);
            echo "OK: " . substr($statement, 0, 60) . "...\n";
        } catch (PDOException $e) {
            // Ignore "already exists" errors
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "SKIP (already exists): " . substr($statement, 0, 50) . "...\n";
            } else {
                echo "ERROR: " . $e->getMessage() . "\n";
                echo "Statement: " . substr($statement, 0, 100) . "...\n\n";
            }
        }
    }

    echo "\n=== Migration Complete ===\n";

    // Verify table exists
    $result = $db->query("SHOW TABLES LIKE 'installment_payments'");
    if ($result->rowCount() > 0) {
        echo "\ninstallment_payments table exists.\n";

        // Show table structure
        echo "\nTable structure:\n";
        $cols = $db->query("DESCRIBE installment_payments");
        foreach ($cols as $col) {
            echo "  - {$col['Field']}: {$col['Type']}\n";
        }
    } else {
        echo "\nWARNING: installment_payments table was not created!\n";
    }

    // Check for new columns in installment_plans
    echo "\nChecking installment_plans columns:\n";
    $cols = $db->query("DESCRIBE installment_plans");
    foreach ($cols as $col) {
        if (in_array($col['Field'], ['total_paid', 'credit_balance'])) {
            echo "  - {$col['Field']}: {$col['Type']} (NEW)\n";
        }
    }

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "\n<p><strong>You can now delete this file.</strong></p>";
echo "<p><a href='cases/index.php'>Go to Cases</a></p>";
