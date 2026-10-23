<?php
// Admin-only maintenance script — must never be publicly accessible
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Auth.php';
(new Auth())->requireAdmin();
$dir = __DIR__ . '/uploads/pdf_assets';

if (!is_dir($dir)) {
    if (mkdir($dir, 0755, true)) {
        echo "✓ Directory created: $dir";
    } else {
        echo "✗ Failed to create directory: $dir";
    }
} else {
    echo "✓ Directory already exists: $dir";
}
?>
