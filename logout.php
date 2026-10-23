<?php
/**
 * Logout Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Auth.php';

$auth = new Auth();
$auth->logout();

header('Location: ' . APP_URL . '/login.php');
exit;
