<?php
/**
 * Cancellation Reasons API
 * API for managing cancellation reasons
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

// Check authentication
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Only admin can add reasons
if (!$auth->isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';

// CSRF: protect every state-changing action (reads stay open).
if (!in_array($action, ['list', 'lookup', 'compute', 'get', 'search', 'view', 'fetch', 'stats', ''], true)) {
    Csrf::require();
}

if ($action === 'add') {
    $reasonText = trim($input['reason_text'] ?? $_POST['reason_text'] ?? '');

    if (empty($reasonText)) {
        echo json_encode(['success' => false, 'message' => 'Reason text is required']);
        exit;
    }

    // Generate unique key
    $reasonKey = 'custom_' . time() . '_' . rand(100, 999);

    // Get max sort order
    $maxOrder = $db->fetch("SELECT MAX(sort_order) as max_order FROM cancellation_reasons");
    $newOrder = ($maxOrder['max_order'] ?? 0) + 1;

    try {
        $db->insert('cancellation_reasons', [
            'reason_key' => $reasonKey,
            'reason_text' => $reasonText,
            'sort_order' => $newOrder,
            'is_active' => 1
        ]);

        echo json_encode([
            'success' => true,
            'reason_key' => $reasonKey,
            'reason_text' => $reasonText,
            'message' => 'Reason added successfully'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} elseif ($action === 'list') {
    // Get all active reasons
    $reasons = $db->fetchAll("SELECT * FROM cancellation_reasons WHERE is_active = 1 ORDER BY sort_order, id");
    echo json_encode(['success' => true, 'reasons' => $reasons]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
