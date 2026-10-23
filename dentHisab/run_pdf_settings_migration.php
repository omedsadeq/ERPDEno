<?php
/**
 * Migration Runner for PDF Invoice Settings
 * Run this file once to create the pdf_invoice_settings table
 */

require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

echo "<h2>PDF Invoice Settings Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Read migration SQL
    $sqlFile = __DIR__ . '/database/migrations/add_pdf_invoice_settings.sql';

    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);

    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Split by semicolon but handle multi-line statements
    $statements = explode(';', $sql);

    $executedCount = 0;

    foreach ($statements as $statement) {
        $statement = trim($statement);

        // Skip empty statements
        if (empty($statement)) {
            continue;
        }

        echo "Executing statement " . (++$executedCount) . ":\n";
        echo substr($statement, 0, 100) . "...\n\n";

        try {
            // Use exec for DDL statements instead of prepare/execute
            $pdo->exec($statement);
            echo "✓ Success\n\n";
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n\n";
            throw $e;
        }
    }

    echo "\n✅ Migration completed successfully!\n";
    echo "Executed $executedCount SQL statements.\n";
    echo "Table 'pdf_invoice_settings' has been created with default settings.\n";

    // Verify table was created
    $tables = $pdo->query("SHOW TABLES LIKE 'pdf_invoice_settings'")->fetchAll();
    if (count($tables) > 0) {
        echo "\n✓ Verified: Table 'pdf_invoice_settings' exists in database.\n";

        // Check record count
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM pdf_invoice_settings")->fetch();
        echo "✓ Records in table: " . $count['cnt'] . "\n";
    } else {
        echo "\n✗ Warning: Table 'pdf_invoice_settings' not found after migration!\n";
    }

} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<p><strong>You can now delete this file.</strong></p>";
echo "<p><a href='admin/pdf_settings.php'>Go to PDF Settings</a></p>";
?>
