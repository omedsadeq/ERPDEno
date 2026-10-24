<?php
/**
 * داواکاری مەواد API — the case dialog (sheet, case_list, save, cancel) and the
 * store's queue (list, approve, reject). All rules live in ErpMaterialRequest;
 * approval goes through ErpDoctorIssue, which owns stock and the doctor charge.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/AccessGuard.php';
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

/** A restricted doctor only reaches cases of patients they may see. */
function erp_mr_case_allowed(int $caseId): bool
{
    $row = Database::getInstance()->fetch(
        "SELECT pf.patient_id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = :id",
        ['id' => $caseId]
    );
    if (!$row) {
        return false;
    }
    return !(AccessGuard::isRestrictedDoctor() && !AccessGuard::doctorCanViewPatient((int)$row['patient_id']));
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($action) {
            case 'sheet':
                if (!$canAsk && !$canStore) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
                erp_mr_json(['ok' => true, 'groups' => ErpMaterialRequest::sheet()]);

            case 'case_list': {
                $caseId = (int)($_GET['case_id'] ?? 0);
                if ((!$canAsk && !$canStore) || !erp_mr_case_allowed($caseId)) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
                erp_mr_json(['ok' => true, 'requests' => ErpMaterialRequest::forCase($caseId)]);
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
            $caseId = (int)($_POST['case_id'] ?? 0);
            if (!erp_mr_case_allowed($caseId)) erp_mr_json(['ok' => false, 'error' => 'Forbidden'], 403);
            $items = json_decode((string)($_POST['items'] ?? '[]'), true);
            $res = ErpMaterialRequest::create($caseId, is_array($items) ? $items : [], (string)($_POST['notes'] ?? ''), $actorId);
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
