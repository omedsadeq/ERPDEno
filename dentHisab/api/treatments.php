<?php
/**
 * Treatments API — list & add
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db   = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF: protect every state-changing action (reads stay open).
if (!in_array($action, ['list', 'lookup', 'compute', 'get', 'search', 'view', 'fetch', 'stats', ''], true)) {
    Csrf::require();
}

if ($action === 'list') {
    $scope = $_GET['scope'] ?? $input['scope'] ?? 'tooth';
    $rows = $db->fetchAll(
        "SELECT id, treatment_code, treatment_name, default_price, scope, category
         FROM treatments
         WHERE is_active = 1 AND (scope = :scope OR scope = 'both')
         ORDER BY treatment_name",
        ['scope' => $scope]
    );
    echo json_encode(['success' => true, 'treatments' => $rows]);
    exit;
}

if ($action === 'add') {
    $name     = trim($input['treatment_name'] ?? $_POST['treatment_name'] ?? '');
    $priceRaw = $input['default_price'] ?? $_POST['default_price'] ?? '';
    $scope    = $input['scope']          ?? $_POST['scope']          ?? 'tooth';
    $category = $input['category']       ?? $_POST['category']       ?? 'other';

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'ناوی چارەسەر داواکراوە']);
        exit;
    }
    $price = is_numeric($priceRaw) ? floatval($priceRaw) : 0;
    if ($price < 0) $price = 0;

    $validScopes     = ['tooth', 'arch', 'both'];
    $validCategories = ['examination','cleaning','filling','extraction','root_canal','crown','bridge','implant','orthodontic','whitening','cosmetic','surgery','other'];
    if (!in_array($scope, $validScopes, true))       $scope = 'tooth';
    if (!in_array($category, $validCategories, true)) $category = 'other';

    // Auto-generate a unique treatment_code
    $code = 'TRT_' . time() . '_' . rand(100, 999);

    try {
        $id = $db->insert('treatments', [
            'treatment_code' => $code,
            'treatment_name' => $name,
            'category'       => $category,
            'scope'          => $scope,
            'default_price'  => $price,
            'is_active'      => 1,
        ]);
        echo json_encode([
            'success'        => true,
            'id'             => (int)$id,
            'treatment_code' => $code,
            'treatment_name' => $name,
            'default_price'  => $price,
            'scope'          => $scope,
            'category'       => $category,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
