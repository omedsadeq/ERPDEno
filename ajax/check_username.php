<?php
/**
 * Check if Username Exists AJAX Endpoint
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

// Check if user is logged in
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();

// Get username from query string
$username = isset($_GET['username']) ? trim($_GET['username']) : '';

if (empty($username)) {
    echo json_encode(['success' => false, 'error' => 'ناوی بەکارهێنەر پێویستە']);
    exit;
}

// Check if username exists
$existing = $db->fetch("SELECT id FROM users WHERE username = :username", ['username' => $username]);

if ($existing) {
    echo json_encode([
        'success' => true,
        'exists' => true,
        'message' => 'ئەم ناوی بەکارهێنەرە پێشتر بەکارهاتووە'
    ]);
} else {
    echo json_encode([
        'success' => true,
        'exists' => false
    ]);
}
