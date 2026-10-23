<?php
/**
 * Treatment Management Page (Admin Only)
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/../config/config.php';

// Redirect to settings page treatments tab
header('Location: ' . APP_URL . '/admin/settings.php?tab=treatments');
exit;
