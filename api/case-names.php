<?php
/**
 * Case Names API
 *
 * list              — any logged-in user (doctors need it to pick a name)
 * add/update        — admin or doctor
 * delete            — admin only
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseNamePreset.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';
$presets = new CaseNamePreset();

if ($action === 'list') {
    echo json_encode(['success' => true, 'names' => $presets->all()], JSON_UNESCAPED_UNICODE);
    exit;
}

Csrf::require();

// Admins and doctors may add/rename; only an admin may delete.
$canManage = $auth->isAdmin() || $auth->isDoctor();
if (!$canManage || ($action === 'delete' && !$auth->isAdmin())) {
    http_response_code(403);
    $msg = $action === 'delete'
        ? 'تەنها ئەدمین دەتوانێت ناوی کەیس بسڕێتەوە'
        : 'ڕێگەت پێنەدراوە ناوی کەیسەکان دەستکاری بکەیت';
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = $auth->getCurrentUser();
$id = (int)($input['id'] ?? 0);
$name = (string)($input['name'] ?? '');
$scope = (string)($input['scope_type'] ?? 'tooth');

try {
    if ($action === 'add') {
        $result = $presets->add($name, $scope, $currentUser['id'] ?? null);
        if (!empty($result['success'])) {
            AuditLog::log('case_name_add', 'case_name_presets', $result['id'], null, ['name' => $result['name'], 'scope_type' => $result['scope_type']]);
        }
    } elseif ($action === 'update') {
        $result = $presets->update($id, $name, $scope);
        if (!empty($result['success'])) {
            AuditLog::log('case_name_update', 'case_name_presets', $id, null, ['name' => $result['name'], 'scope_type' => $result['scope_type']]);
        }
    } elseif ($action === 'delete') {
        $result = $presets->remove($id);
        AuditLog::log('case_name_delete', 'case_name_presets', $id, null, null);
    } else {
        $result = ['success' => false, 'message' => 'Invalid action'];
    }
} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
