<?php
/**
 * X-ray Pricing API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';

$db = Database::getInstance();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$isAdmin = $auth->isAdmin();

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? 'list';

// CSRF: protect every state-changing action (reads stay open).
if (!in_array($action, ['list', 'lookup', 'compute', 'get', 'search', 'view', 'fetch', 'stats', ''], true)) {
    Csrf::require();
}

$validTypes = ['panoramic','cbct','periapical','cephalometric'];
$validModes = ['full_mouth','left_side','right_side','upper_arch','lower_arch','per_tooth','single'];

if ($action === 'list') {
    $rows = $db->fetchAll(
        "SELECT id, xray_type, pricing_mode, price, is_active, sort_order
         FROM xray_pricing WHERE is_active = 1
         ORDER BY xray_type, sort_order, pricing_mode"
    );
    echo json_encode(['success' => true, 'prices' => $rows]);
    exit;
}

if ($action === 'lookup') {
    $type = $_GET['type'] ?? $input['type'] ?? '';
    $mode = $_GET['mode'] ?? $input['mode'] ?? '';
    if (!in_array($type, $validTypes, true) || !in_array($mode, $validModes, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid type/mode']);
        exit;
    }
    $row = $db->fetch(
        "SELECT price FROM xray_pricing WHERE xray_type = :t AND pricing_mode = :m AND is_active = 1",
        ['t' => $type, 'm' => $mode]
    );
    echo json_encode(['success' => true, 'price' => $row ? (float)$row['price'] : 0]);
    exit;
}

if ($action === 'compute') {
    // Compute price for a given type + selection state
    $type       = $input['type']       ?? $_POST['type']       ?? '';
    $fullMouth  = !empty($input['full_mouth'] ?? $_POST['full_mouth'] ?? 0);
    $determine  = $input['determine_details'] ?? $_POST['determine_details'] ?? '';
    $teeth      = $input['teeth']      ?? $_POST['teeth']      ?? '';

    if (!in_array($type, $validTypes, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
        exit;
    }

    // Load all prices for this type keyed by mode
    $rows = $db->fetchAll(
        "SELECT pricing_mode, price FROM xray_pricing WHERE xray_type = :t AND is_active = 1",
        ['t' => $type]
    );
    $priceMap = [];
    foreach ($rows as $r) { $priceMap[$r['pricing_mode']] = (float)$r['price']; }

    $price = 0;
    $mode  = null;

    if ($fullMouth) {
        $mode = 'full_mouth';
        $price = $priceMap['full_mouth'] ?? 0;
    } else {
        // Normalize determine text to detect side
        $hint = mb_strtolower(trim($determine));
        $sideGuess = null;
        if     (str_contains($hint, 'left'))  $sideGuess = 'left_side';
        elseif (str_contains($hint, 'right')) $sideGuess = 'right_side';
        elseif (str_contains($hint, 'upper')) $sideGuess = 'upper_arch';
        elseif (str_contains($hint, 'lower')) $sideGuess = 'lower_arch';

        // Parse selected teeth
        $toothList = array_values(array_filter(array_map('trim', explode(',', (string)$teeth)), fn($x) => $x !== ''));
        $count = count($toothList);

        if ($sideGuess && isset($priceMap[$sideGuess])) {
            $mode = $sideGuess;
            $price = $priceMap[$sideGuess];
        } elseif ($count > 0) {
            // Use per_tooth × count if available, otherwise single price
            if (isset($priceMap['per_tooth'])) {
                $mode = 'per_tooth';
                $price = $priceMap['per_tooth'] * $count;
            } elseif (isset($priceMap['single'])) {
                $mode = 'single';
                $price = $priceMap['single'];
            }
        } elseif (isset($priceMap['single'])) {
            $mode = 'single';
            $price = $priceMap['single'];
        }
    }

    echo json_encode([
        'success' => true,
        'type'    => $type,
        'mode'    => $mode,
        'price'   => round($price),
    ]);
    exit;
}

// Below actions require admin
if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'Admin only']); exit; }

if ($action === 'save') {
    $id    = intval($input['id'] ?? $_POST['id'] ?? 0);
    $type  = $input['xray_type']    ?? $_POST['xray_type']    ?? '';
    $mode  = $input['pricing_mode'] ?? $_POST['pricing_mode'] ?? '';
    $price = floatval($input['price'] ?? $_POST['price'] ?? 0);

    if (!in_array($type, $validTypes, true)) { echo json_encode(['success' => false, 'message' => 'جۆری نادروست']); exit; }
    if (!in_array($mode, $validModes, true)) { echo json_encode(['success' => false, 'message' => 'دۆخی نادروست']); exit; }
    if ($price < 0) $price = 0;

    try {
        if ($id > 0) {
            $db->update('xray_pricing', [
                'xray_type'    => $type,
                'pricing_mode' => $mode,
                'price'        => $price,
                'is_active'    => 1,
            ], 'id = ?', [$id]);
            echo json_encode(['success' => true, 'id' => $id, 'action' => 'updated']);
        } else {
            // Upsert by unique key
            $existing = $db->fetch("SELECT id FROM xray_pricing WHERE xray_type = :t AND pricing_mode = :m", ['t' => $type, 'm' => $mode]);
            if ($existing) {
                $db->update('xray_pricing', ['price' => $price, 'is_active' => 1], 'id = ?', [$existing['id']]);
                echo json_encode(['success' => true, 'id' => (int)$existing['id'], 'action' => 'updated']);
            } else {
                $newId = $db->insert('xray_pricing', [
                    'xray_type'    => $type,
                    'pricing_mode' => $mode,
                    'price'        => $price,
                    'is_active'    => 1,
                ]);
                echo json_encode(['success' => true, 'id' => (int)$newId, 'action' => 'created']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $id = intval($input['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }
    try {
        $db->update('xray_pricing', ['is_active' => 0], 'id = ?', [$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
