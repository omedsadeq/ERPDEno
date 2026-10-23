<?php
require_once __DIR__ . '/config/config.php';
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/classes/Database.php';

$db = Database::getInstance();

echo "<h2>Debug: Checking pdf_invoice_settings table</h2>";
echo "<pre>";

try {
    // Check if table exists
    $tables = $db->fetchAll("SHOW TABLES");
    echo "All tables in database:\n";
    print_r($tables);
    echo "\n\n";

    // Try to select from the table
    echo "Trying to query pdf_invoice_settings table:\n";
    $result = $db->fetchAll("SELECT * FROM pdf_invoice_settings");
    echo "Table exists! Records found: " . count($result) . "\n";
    print_r($result);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
}

echo "</pre>";
?>
