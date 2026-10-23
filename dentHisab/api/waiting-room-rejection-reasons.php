<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$canManageReasons = $auth->isAdmin() || $auth->isReceptionist();

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';

// CSRF: protect every state-changing action (reads stay open).
if (!in_array($action, ['list', 'lookup', 'compute', 'get', 'search', 'view', 'fetch', 'stats', ''], true)) {
    Csrf::require();
}

if ($action === 'list') {
    $reasons = $db->fetchAll("
        SELECT id, reason_key, reason_text, sort_order
        FROM waiting_room_rejection_reasons
        WHERE is_active = 1
        ORDER BY sort_order, id
    ");
    echo json_encode(['success' => true, 'reasons' => $reasons]);
    exit;
}

if ($action === 'add') {
    if (!$canManageReasons) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $reasonText = trim($input['reason_text'] ?? $_POST['reason_text'] ?? '');

    if ($reasonText === '') {
        echo json_encode(['success' => false, 'message' => 'Reason text is required']);
        exit;
    }

    $maxOrder = $db->fetch("SELECT MAX(sort_order) AS max_order FROM waiting_room_rejection_reasons");
    $newOrder = ((int)($maxOrder['max_order'] ?? 0)) + 1;
    $reasonKey = 'custom_' . time() . '_' . rand(100, 999);

    try {
        $db->insert('waiting_room_rejection_reasons', [
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
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
