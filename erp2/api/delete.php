<?php
/**
 * دەرمانخانە — permanent deletes (classes/Erp2Delete.php does the work).
 *
 *   party            POST kind=customer|supplier, id [, cascade=1]
 *                    without cascade it is refused while the party has
 *                    documents, and the reply lists them so the screen can
 *                    offer: deactivate, or delete WITH every transaction
 *   deactivate       POST kind, id
 *   sale | purchase  POST id — invoices, stock and account reversed
 *   sale_return | purchase_return | adjustment | transfer   POST id
 *   payment          POST kind, id
 *   prescription     POST id — also the doctor who wrote it, while it is
 *                    still pending (nothing sold from it yet)
 *
 * Everything else behind erp2.delete (admin unless granted). POST + CSRF.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/Erp2Delete.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function del_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    del_json(['ok' => false, 'error' => 'POST required'], 405);
}
Csrf::require();

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
$kind   = (string)($_POST['kind'] ?? '');
$db     = Database::getInstance();

$canDelete = erp2_can('erp2.delete');
if (!$canDelete) {
    // The one exception: a doctor withdrawing their own request before the
    // pharmacy has acted on it.
    $own = $action === 'prescription' && erp2_can('erp2.prescriptions.create') && $db->fetch(
        "SELECT id FROM erp2_prescriptions WHERE id = :id AND status = 'pending' AND (created_by = :u OR doctor_id = :u2)",
        ['id' => $id, 'u' => (int)($_SESSION['user_id'] ?? 0), 'u2' => (int)($_SESSION['user_id'] ?? 0)]
    );
    if (!$own) {
        del_json(['ok' => false, 'error' => 'ڕێگەت نییە بسڕیتەوە'], 403);
    }
}
if (in_array($action, ['party', 'deactivate', 'payment'], true) && !in_array($kind, ['customer', 'supplier'], true)) {
    del_json(['ok' => false, 'error' => 'bad kind'], 400);
}

try {
    switch ($action) {
        case 'party': {
            if (!empty($_POST['cascade'])) {
                $done = Erp2Delete::partyWithHistory($kind, $id);
                del_json(['ok' => true, 'removed' => $done]);
            }
            $blockers = Erp2Delete::partyBlockers($kind, $id);
            if ($blockers) {
                $walkIn = in_array(Erp2Delete::WALK_IN_REASON, $blockers, true);
                del_json(['ok' => false, 'blocked' => true, 'blockers' => $blockers,
                          'can_deactivate' => !$walkIn, 'can_cascade' => !$walkIn,
                          'error' => 'ناسڕدرێتەوە، چونکە: ' . implode('، ', $blockers)], 409);
            }
            Erp2Delete::party($kind, $id);
            del_json(['ok' => true]);
        }
        case 'deactivate':
            Erp2Delete::deactivateParty($kind, $id);
            del_json(['ok' => true]);
        case 'sale':            del_json(['ok' => true] + Erp2Delete::sale($id));
        case 'purchase':        del_json(['ok' => true] + Erp2Delete::purchase($id));
        case 'sale_return':     del_json(['ok' => true] + Erp2Delete::saleReturn($id));
        case 'purchase_return': del_json(['ok' => true] + Erp2Delete::purchaseReturn($id));
        case 'adjustment':      del_json(['ok' => true] + Erp2Delete::adjustment($id));
        case 'transfer':        del_json(['ok' => true] + Erp2Delete::transfer($id));
        case 'payment':         del_json(['ok' => true] + Erp2Delete::payment($kind, $id));
        case 'prescription':    del_json(['ok' => true] + Erp2Delete::prescription($id));
        default:
            del_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (RuntimeException $e) {
    del_json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[erp2/api/delete] ' . $action . ': ' . $e->getMessage());
    del_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}
