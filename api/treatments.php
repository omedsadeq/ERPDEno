<?php
/**
 * Treatments API — list & add
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Treatment.php';

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
        "SELECT id, treatment_code, treatment_name, default_price, scope, category, icon, requires_tooth_number
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
    $icon     = Treatment::cleanIcon($input['icon'] ?? $_POST['icon'] ?? '');
    $requiresTooth = filter_var(
        $input['requires_tooth_number'] ?? $_POST['requires_tooth_number'] ?? true,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );
    $requiresTooth = $requiresTooth === null ? true : $requiresTooth;

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'ناوی چارەسەر داواکراوە']);
        exit;
    }
    if (!Treatment::iconChosen($icon)) {
        echo json_encode(['success' => false, 'message' => 'تکایە ئایکۆنێک بۆ چارەسەرەکە هەڵبژێرە']);
        exit;
    }
    $price = is_numeric($priceRaw) ? floatval($priceRaw) : 0;
    if ($price < 0) $price = 0;

    $validScopes     = ['tooth', 'arch', 'both'];
    $validCategories = ['examination','cleaning','filling','extraction','root_canal','crown','bridge','implant','orthodontic','whitening','cosmetic','surgery','other'];
    if (!in_array($scope, $validScopes, true))       $scope = 'tooth';
    if (!in_array($category, $validCategories, true)) $category = 'other';
    if ($scope === 'arch') $requiresTooth = false;

    // Auto-generate a unique treatment_code
    $code = 'TRT_' . time() . '_' . rand(100, 999);

    try {
        $id = $db->insert('treatments', [
            'treatment_code' => $code,
            'treatment_name' => $name,
            'category'       => $category,
            'icon'           => $icon,
            'scope'          => $scope,
            'requires_tooth_number' => $requiresTooth ? 1 : 0,
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
            'requires_tooth_number' => $requiresTooth ? 1 : 0,
            'category'       => $category,
            'icon'           => $icon,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'update') {
    $id       = (int)($input['id'] ?? $_POST['id'] ?? 0);
    $name     = trim($input['treatment_name'] ?? $_POST['treatment_name'] ?? '');
    $priceRaw = $input['default_price'] ?? $_POST['default_price'] ?? '';
    $scope    = $input['scope']          ?? $_POST['scope']          ?? 'tooth';
    $category = $input['category']       ?? $_POST['category']       ?? 'other';
    $icon     = Treatment::cleanIcon($input['icon'] ?? $_POST['icon'] ?? '');
    $requiresTooth = filter_var(
        $input['requires_tooth_number'] ?? $_POST['requires_tooth_number'] ?? true,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );
    $requiresTooth = $requiresTooth === null ? true : $requiresTooth;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'چارەسەر دیاری نەکراوە']);
        exit;
    }
    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'ناوی چارەسەر داواکراوە']);
        exit;
    }
    if (!Treatment::iconChosen($icon)) {
        echo json_encode(['success' => false, 'message' => 'تکایە ئایکۆنێک بۆ چارەسەرەکە هەڵبژێرە']);
        exit;
    }
    $price = is_numeric($priceRaw) ? floatval($priceRaw) : 0;
    if ($price < 0) $price = 0;

    $validScopes     = ['tooth', 'arch', 'both'];
    $validCategories = ['examination','cleaning','filling','extraction','root_canal','crown','bridge','implant','orthodontic','whitening','cosmetic','surgery','other'];
    if (!in_array($scope, $validScopes, true))       $scope = 'tooth';
    if (!in_array($category, $validCategories, true)) $category = 'other';
    if ($scope === 'arch') $requiresTooth = false;

    try {
        $db->update('treatments', [
            'treatment_name' => $name,
            'category'       => $category,
            'icon'           => $icon,
            'scope'          => $scope,
            'requires_tooth_number' => $requiresTooth ? 1 : 0,
            'default_price'  => $price,
        ], 'id = :id', ['id' => $id]);
        echo json_encode([
            'success'        => true,
            'id'             => $id,
            'treatment_name' => $name,
            'default_price'  => $price,
            'scope'          => $scope,
            'requires_tooth_number' => $requiresTooth ? 1 : 0,
            'category'       => $category,
            'icon'           => $icon,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'favorite') {
    // ★ on a treatment card: the signed-in user's own favourites
    $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
    $on = filter_var($input['on'] ?? $_POST['on'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($id <= 0 || !$db->fetch("SELECT id FROM treatments WHERE id = :id", ['id' => $id])) {
        echo json_encode(['success' => false, 'message' => 'چارەسەر نەدۆزرایەوە']);
        exit;
    }
    try {
        (new Treatment())->setFavorite((int)$auth->getCurrentUserId(), $id, $on);
        echo json_encode(['success' => true, 'id' => $id, 'on' => $on]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    // Soft delete: existing case_treatments keep their treatment_id/name,
    // the type just stops appearing in pickers.
    $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'چارەسەر دیاری نەکراوە']);
        exit;
    }
    try {
        $db->update('treatments', ['is_active' => 0], 'id = :id', ['id' => $id]);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
