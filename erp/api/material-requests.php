<?php
/**
 * داواکاری مەواد API — the doctor's page, doctors/material-requests.php
 * (sheet, mine, save, cancel), and the store's queue (list, approve, reject). All rules live in ErpMaterialRequest;
 * approval goes through ErpDoctorIssue, which owns stock and the doctor charge.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/ErpMaterialRequest.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp_mr_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

$db       = Database::getInstance();
$action   = $_GET['action'] ?? $_POST['action'] ?? '';
$actorId  = (int)($_SESSION['user_id'] ?? 0);
$isAdmin  = ($_SESSION['role'] ?? '') === 'admin';
$canAsk   = erp_can(ErpMaterialRequest::CREATE_PERMISSION);
$canStore = erp_can(ErpMaterialRequest::APPROVE_PERMISSION);

/** Whose requests: a doctor's own; the admin names the doctor. */
function erp_mr_doctor(int $asked): int
{
    return ($_SESSION['role'] ?? '') === 'doctor' ? (int)($_SESSION['user_id'] ?? 0) : $asked;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($action) {
            case 'sheet':
                if (!$canAsk && !$canStore) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
                erp_mr_json(['ok' => true, 'groups' => ErpMaterialRequest::sheet()]);

            case 'mine': {
                if (!$canAsk) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
                $doctorId = erp_mr_doctor((int)($_GET['doctor_id'] ?? 0));
                erp_mr_json(['ok' => true, 'requests' => $doctorId > 0 ? ErpMaterialRequest::forDoctor($doctorId) : []]);
            }

            case 'list':
                if (!$canStore) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
                erp_mr_json(['ok' => true, 'requests' => ErpMaterialRequest::queue((string)($_GET['status'] ?? 'pending'))]);
        }
        erp_mr_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

    Csrf::require();
    switch ($action) {
        case 'save': {
            if (!$canAsk) erp_mr_json(['ok' => false, 'error' => 'ڕێگەت پێنەدراوە داواکاری مەواد بکەیت'], 403);
            $items = json_decode((string)($_POST['items'] ?? '[]'), true);
            $res = ErpMaterialRequest::create(erp_mr_doctor((int)($_POST['doctor_id'] ?? 0)), is_array($items) ? $items : [],
                (string)($_POST['notes'] ?? ''), $actorId);
            erp_mr_json(['ok' => true] + $res);
        }

        case 'cancel':
            if (!$canAsk) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
            ErpMaterialRequest::cancel((int)($_POST['id'] ?? 0), $actorId, $isAdmin);
            erp_mr_json(['ok' => true]);

        case 'approve': {
            if (!$canStore) erp_mr_json(['ok' => false, 'error' => 'ڕێگەت پێنەدراوە داواکاری پەسەند بکەیت'], 403);
            $id = (int)($_POST['id'] ?? 0);
            $req = $db->fetch("SELECT warehouse_id FROM erp_material_requests WHERE id = :id", ['id' => $id]);
            if (!$req) erp_mr_json(['ok' => false, 'error' => 'داواکاری نەدۆزرایەوە'], 404);
            try {
                erp_assert_warehouse((int)$req['warehouse_id']);
            } catch (RuntimeException $e) {
                erp_mr_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            $qty = json_decode((string)($_POST['quantities'] ?? '{}'), true);
            $res = ErpMaterialRequest::approve($id, is_array($qty) ? $qty : [], (string)($_POST['kind'] ?? 'normal'), $actorId);
            erp_mr_json(['ok' => true] + $res);
        }

        case 'reject':
            if (!$canStore) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
            ErpMaterialRequest::reject((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''), $actorId);
            erp_mr_json(['ok' => true]);
    }
    erp_mr_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (RuntimeException $e) {
    erp_mr_json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('material-requests API: ' . $e->getMessage());
    erp_mr_json(['ok' => false, 'error' => 'هەڵەیەک ڕوویدا'], 500);
}
