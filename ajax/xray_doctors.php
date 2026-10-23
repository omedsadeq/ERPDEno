<?php
/**
 * Doctor picker for the X-ray request form: searches in-house doctors and
 * outside (referring) doctors as two separate groups, and registers a new
 * outside doctor on the spot.
 *
 * The two groups come from two unrelated places on purpose. In-house doctors
 * ARE user accounts (users.role = 'doctor'), because the request is filed
 * against them and every doctor-scoped screen keys off users.id. Outside
 * doctors live only in xray_external_doctors — they have no account, so they
 * can never be the owner of a request; see the migration for why they are not
 * rows in `doctors`.
 *
 * A doctor logged in as themselves never reaches here: their own name is fixed
 * on the form, so letting them file imaging under a colleague (or under a
 * stranger) would only be a way around doctor isolation.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!Permission::getInstance()->can('page.xray_request')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}
// Same rule as the form: only staff who file on someone else's behalf may pick.
if ($auth->isDoctor() && !$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$db = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? 'search';

/** One external row shaped for the picker. */
function xed_row(array $r): array
{
    return [
        'id'             => (int)$r['id'],
        'name'           => $r['name'],
        'phone'          => $r['phone'] ?? '',
        'clinic'         => $r['clinic'] ?? '',
        'specialization' => $r['specialization'] ?? '',
    ];
}

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
    $like = '%' . $q . '%';

    // Both lists are capped: the picker is a search box, not a directory dump.
    if ($q === '') {
        $internal = $db->fetchAll(
            "SELECT id, full_name AS name FROM users
             WHERE role = 'doctor' AND is_active = 1
             ORDER BY full_name LIMIT 50"
        );
        $external = $db->fetchAll(
            "SELECT id, name, phone, clinic, specialization FROM xray_external_doctors
             WHERE is_active = 1 ORDER BY name LIMIT 50"
        );
    } else {
        $internal = $db->fetchAll(
            "SELECT id, full_name AS name FROM users
             WHERE role = 'doctor' AND is_active = 1 AND full_name LIKE :q
             ORDER BY full_name LIMIT 50",
            ['q' => $like]
        );
        $external = $db->fetchAll(
            "SELECT id, name, phone, clinic, specialization FROM xray_external_doctors
             WHERE is_active = 1
               AND (name LIKE :q OR COALESCE(clinic, '') LIKE :q2 OR COALESCE(phone, '') LIKE :q3)
             ORDER BY name LIMIT 50",
            ['q' => $like, 'q2' => $like, 'q3' => $like]
        );
    }

    echo json_encode([
        'success'  => true,
        'internal' => array_map(function ($r) {
            return ['id' => (int)$r['id'], 'name' => $r['name']];
        }, $internal),
        'external' => array_map('xed_row', $external),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
    Csrf::require();

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'ناوی دکتۆر پێویستە'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($name) > 150) {
        echo json_encode(['success' => false, 'error' => 'ناو زۆر درێژە'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Reuse an existing entry instead of failing on the unique index — staff
    // retyping a name that is already on file should land on that doctor, and
    // a name that was deactivated comes back rather than becoming unusable.
    $existing = $db->fetch(
        "SELECT id, name, phone, clinic, specialization, is_active
         FROM xray_external_doctors WHERE name = :name",
        ['name' => $name]
    );
    if ($existing) {
        if (!(int)$existing['is_active']) {
            $db->update('xray_external_doctors', ['is_active' => 1], 'id = :id', ['id' => (int)$existing['id']]);
        }
        echo json_encode([
            'success'  => true,
            'existing' => true,
            'doctor'   => xed_row($existing),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newId = $db->insert('xray_external_doctors', [
        'name'           => $name,
        'phone'          => trim((string)($_POST['phone'] ?? '')) ?: null,
        'clinic'         => trim((string)($_POST['clinic'] ?? '')) ?: null,
        'specialization' => trim((string)($_POST['specialization'] ?? '')) ?: null,
        'is_active'      => 1,
        'created_by'     => $auth->getCurrentUserId(),
    ]);

    $row = $db->fetch(
        "SELECT id, name, phone, clinic, specialization FROM xray_external_doctors WHERE id = :id",
        ['id' => $newId]
    );
    echo json_encode([
        'success'  => true,
        'existing' => false,
        'doctor'   => xed_row($row),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
