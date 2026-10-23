<?php
/**
 * Work Types API
 */

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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF: protect every state-changing action (reads stay open).
if (!in_array($action, ['list', 'lookup', 'compute', 'get', 'search', 'view', 'fetch', 'stats', ''], true)) {
    Csrf::require();
}

if ($action === 'list') {
    $rows = $db->fetchAll("SELECT id, work_key, work_name FROM work_types WHERE is_active = 1 ORDER BY sort_order, id");
    echo json_encode(['success' => true, 'work_types' => $rows]);
    exit;
}

if ($action === 'add') {
    $name = trim($input['work_name'] ?? $_POST['work_name'] ?? '');
    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'ناوی ئیش داواکراوە']);
        exit;
    }

    $workKey = 'custom_' . time() . '_' . rand(100, 999);
    $max = $db->fetch("SELECT MAX(sort_order) as m FROM work_types");
    $order = ($max['m'] ?? 0) + 1;

    try {
        $id = $db->insert('work_types', [
            'work_key'   => $workKey,
            'work_name'  => $name,
            'sort_order' => $order,
            'is_active'  => 1,
        ]);
        echo json_encode([
            'success'   => true,
            'id'        => $id,
            'work_key'  => $workKey,
            'work_name' => $name,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
