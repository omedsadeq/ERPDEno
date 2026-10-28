<?php
/**
 * Locations API — شوێن: پارێزگا → ئیدارە (optional) → شار → گەڕەک.
 * - action=list: every active place, as {id, parent_id, level, name}
 * - action=add:  a new place by names {province, region?, city, area} — each
 *   level found or created in turn — returns the گەڕەک with its whole path.
 * The tree lives in `locations` (database/migrations/2026_10_28_locations.sql).
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

if ($action !== 'list') {
    Csrf::require();
}

if ($action === 'list') {
    $rows = $db->fetchAll(
        "SELECT id, parent_id, level, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name"
    );
    echo json_encode(['success' => true, 'locations' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'add') {
    $clean = fn($k) => trim(preg_replace('/\s+/u', ' ', (string)($input[$k] ?? $_POST[$k] ?? '')));
    $names = [
        'province' => $clean('province'),
        'region'   => $clean('region'),
        'city'     => $clean('city'),
        'area'     => $clean('area'),
    ];
    // پارێزگا، شار و گەڕەک are all required; ئیدارە only where there is one
    foreach (['province' => 'پارێزگا', 'city' => 'شار', 'area' => 'گەڕەک'] as $k => $label) {
        if ($names[$k] === '') {
            echo json_encode(['success' => false, 'message' => 'تکایە ' . $label . ' بنووسە']);
            exit;
        }
    }
    foreach ($names as $n) {
        if (mb_strlen($n) > 100) {
            echo json_encode(['success' => false, 'message' => 'ناوەکە زۆر درێژە']);
            exit;
        }
    }

    /** The place called $name under $parent, made if it is not there yet. */
    $findOrMake = function (?int $parent, string $level, string $name) use ($db): array {
        $row = $parent === null
            ? $db->fetch("SELECT id, name FROM locations WHERE parent_id IS NULL AND name = :n LIMIT 1", ['n' => $name])
            : $db->fetch("SELECT id, name FROM locations WHERE parent_id = :p AND name = :n LIMIT 1", ['p' => $parent, 'n' => $name]);
        if ($row) {
            $db->query("UPDATE locations SET is_active = 1 WHERE id = :id", ['id' => $row['id']]);
            return ['id' => (int)$row['id'], 'new' => false];
        }
        $max = $parent === null
            ? $db->fetch("SELECT MAX(sort_order) m FROM locations WHERE parent_id IS NULL")
            : $db->fetch("SELECT MAX(sort_order) m FROM locations WHERE parent_id = :p", ['p' => $parent]);
        $id = (int)$db->insert('locations', [
            'parent_id' => $parent, 'level' => $level, 'name' => $name,
            'sort_order' => (int)($max['m'] ?? 0) + 10, 'is_active' => 1,
        ]);
        return ['id' => $id, 'new' => true];
    };

    try {
        $db->beginTransaction();
        $made = [];
        $prov = $findOrMake(null, 'province', $names['province']);
        $made[] = ['id' => $prov['id'], 'parent_id' => null, 'level' => 'province', 'name' => $names['province']];
        $parent = $prov['id'];
        if ($names['region'] !== '') {
            $reg = $findOrMake($parent, 'region', $names['region']);
            $made[] = ['id' => $reg['id'], 'parent_id' => $parent, 'level' => 'region', 'name' => $names['region']];
            $parent = $reg['id'];
        }
        $city = $findOrMake($parent, 'city', $names['city']);
        $made[] = ['id' => $city['id'], 'parent_id' => $parent, 'level' => 'city', 'name' => $names['city']];
        $area = $findOrMake($city['id'], 'area', $names['area']);
        $made[] = ['id' => $area['id'], 'parent_id' => $city['id'], 'level' => 'area', 'name' => $names['area']];
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'id'      => $area['id'],
        'existed' => !$area['new'],
        'city'    => $names['city'],
        'area'    => $names['area'],
        'chain'   => $made,           // every level, so the page can add what it lacks
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
