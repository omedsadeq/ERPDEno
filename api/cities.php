<?php
/**
 * Cities API
 * - action=list: return all active cities grouped by province
 * - action=add:  insert a new city, return {id, name, province}
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

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF: protect every state-changing action (reads stay open). The header.php
// fetch/XHR patch sends the X-CSRF-Token header on all non-GET requests.
if (!in_array($action, ['list', 'lookup', 'compute', 'get', 'search', 'view', 'fetch', 'stats', ''], true)) {
    Csrf::require();
}

if ($action === 'list') {
    $rows = $db->fetchAll(
        "SELECT id, name, province FROM cities
         WHERE is_active = 1
         ORDER BY sort_order, id"
    );
    echo json_encode(['success' => true, 'cities' => $rows]);
    exit;
}

if ($action === 'add') {
    $name     = trim($input['name']     ?? $_POST['name']     ?? '');
    $province = trim($input['province'] ?? $_POST['province'] ?? '');

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'ناوی شار داواکراوە']);
        exit;
    }
    if (mb_strlen($name) > 100) {
        echo json_encode(['success' => false, 'message' => 'ناوی شار زۆر درێژە']);
        exit;
    }

    // If an entry with the same name already exists, return it (idempotent add)
    $existing = $db->fetch(
        "SELECT id, name, province FROM cities WHERE name = :name LIMIT 1",
        ['name' => $name]
    );
    if ($existing) {
        echo json_encode([
            'success'  => true,
            'id'       => (int)$existing['id'],
            'name'     => $existing['name'],
            'province' => $existing['province'] ?? '',
            'existed'  => true,
        ]);
        exit;
    }

    $max   = $db->fetch("SELECT MAX(sort_order) AS m FROM cities");
    $order = (int)($max['m'] ?? 0) + 10;

    try {
        $id = $db->insert('cities', [
            'name'       => $name,
            'province'   => $province !== '' ? $province : null,
            'sort_order' => $order,
            'is_active'  => 1,
        ]);
        echo json_encode([
            'success'  => true,
            'id'       => (int)$id,
            'name'     => $name,
            'province' => $province,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
