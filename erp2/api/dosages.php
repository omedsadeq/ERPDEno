<?php
/**
 * دەرمانخانە — دۆز و ڕێنمایی API (erp2/dosages.php).
 *
 *   list     every active product, in the order of the prescription sheet,
 *            with its saved doses
 *   save     POST — add or edit one dose of a product
 *   delete   POST — remove one
 *   default  POST — make one the product's default
 *
 * A product's first dose is its default, and a product never keeps two
 * defaults: the sheet fills the doctor's line from exactly one.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dose_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!erp2_can('erp2.dosages.manage')) {
    dose_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
if (in_array($action, ['save', 'delete', 'default'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        dose_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
}

$db = Database::getInstance();
$me = (int)($_SESSION['user_id'] ?? 0) ?: null;

/** A product's doses, default first. */
function dose_rows(Database $db, int $productId): array
{
    return $db->fetchAll(
        "SELECT id, product_id, label, dose, instructions, is_default
         FROM erp2_product_dosages WHERE product_id = :p
         ORDER BY is_default DESC, sort_order, id",
        ['p' => $productId]
    );
}

/** Exactly one default once a product has any dose at all. */
function dose_keep_default(Database $db, int $productId): void
{
    $has = (int)$db->fetch(
        "SELECT COUNT(*) AS c FROM erp2_product_dosages WHERE product_id = :p AND is_default = 1",
        ['p' => $productId]
    )['c'];
    if ($has === 0) {
        $first = $db->fetch(
            "SELECT id FROM erp2_product_dosages WHERE product_id = :p ORDER BY sort_order, id LIMIT 1",
            ['p' => $productId]
        );
        if ($first) {
            $db->update('erp2_product_dosages', ['is_default' => 1], 'id = ?', [(int)$first['id']]);
        }
    }
}

try {
    switch ($action) {

        case 'list': {
            $products = $db->fetchAll(
                "SELECT p.id, p.name, p.name_ku, p.product_code, p.category_id,
                        c.name AS category_name, t.name AS type_name
                 FROM erp2_products p
                 LEFT JOIN erp2_categories c ON c.id = p.category_id
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 WHERE p.is_active = 1
                 ORDER BY COALESCE(c.sort_order, 999), p.sort_order, p.name"
            );
            $doses = [];
            foreach ($db->fetchAll(
                "SELECT d.id, d.product_id, d.label, d.dose, d.instructions, d.is_default
                 FROM erp2_product_dosages d
                 JOIN erp2_products p ON p.id = d.product_id AND p.is_active = 1
                 ORDER BY d.is_default DESC, d.sort_order, d.id"
            ) as $d) {
                $doses[(int)$d['product_id']][] = $d;
            }
            foreach ($products as &$p) {
                $p['doses'] = $doses[(int)$p['id']] ?? [];
            }
            unset($p);
            dose_json(['ok' => true, 'products' => $products]);
        }

        case 'save': {
            $id        = (int)($_POST['id'] ?? 0);
            $productId = (int)($_POST['product_id'] ?? 0);
            $dose      = trim((string)($_POST['dose'] ?? ''));
            $label     = trim((string)($_POST['label'] ?? ''));
            $instr     = trim((string)($_POST['instructions'] ?? ''));
            $isDefault = !empty($_POST['is_default']);

            if ($dose === '') {
                dose_json(['ok' => false, 'error' => 'دۆز پێویستە'], 422);
            }
            if (mb_strlen($dose) > 160 || mb_strlen($label) > 60 || mb_strlen($instr) > 255) {
                dose_json(['ok' => false, 'error' => 'دەقەکە زۆر درێژە'], 422);
            }
            if (!$db->fetch("SELECT id FROM erp2_products WHERE id = :id", ['id' => $productId])) {
                dose_json(['ok' => false, 'error' => 'دەرمان نەدۆزرایەوە'], 404);
            }

            $db->beginTransaction();
            try {
                $fields = [
                    'label'        => $label !== '' ? $label : null,
                    'dose'         => $dose,
                    'instructions' => $instr !== '' ? $instr : null,
                ];
                if ($id > 0) {
                    $old = $db->fetch("SELECT * FROM erp2_product_dosages WHERE id = :id AND product_id = :p",
                        ['id' => $id, 'p' => $productId]);
                    if (!$old) {
                        $db->rollback();
                        dose_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
                    }
                    $fields['updated_at'] = date('Y-m-d H:i:s');
                    $fields['updated_by'] = $me;
                    $db->update('erp2_product_dosages', $fields, 'id = ?', [$id]);
                } else {
                    $fields['product_id'] = $productId;
                    $fields['sort_order'] = (int)$db->fetch(
                        "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM erp2_product_dosages WHERE product_id = :p",
                        ['p' => $productId])['n'];
                    $fields['created_by'] = $me;
                    $id = (int)$db->insert('erp2_product_dosages', $fields);
                }
                if ($isDefault) {
                    $db->query("UPDATE erp2_product_dosages SET is_default = (id = :id) WHERE product_id = :p",
                        ['id' => $id, 'p' => $productId]);
                }
                dose_keep_default($db, $productId);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollback(); }
                throw $e;
            }
            Erp2Audit::log('save_dosage', 'PRODUCT', $productId, null, null, $fields + ['dosage_id' => $id]);
            dose_json(['ok' => true, 'id' => $id, 'doses' => dose_rows($db, $productId)]);
        }

        case 'delete': {
            $id  = (int)($_POST['id'] ?? 0);
            $row = $db->fetch("SELECT * FROM erp2_product_dosages WHERE id = :id", ['id' => $id]);
            if (!$row) {
                dose_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
            }
            $db->query("DELETE FROM erp2_product_dosages WHERE id = :id", ['id' => $id]);
            dose_keep_default($db, (int)$row['product_id']);
            Erp2Audit::log('delete_dosage', 'PRODUCT', (int)$row['product_id'], null, $row, null);
            dose_json(['ok' => true, 'doses' => dose_rows($db, (int)$row['product_id'])]);
        }

        case 'default': {
            $id  = (int)($_POST['id'] ?? 0);
            $row = $db->fetch("SELECT product_id FROM erp2_product_dosages WHERE id = :id", ['id' => $id]);
            if (!$row) {
                dose_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
            }
            $db->query("UPDATE erp2_product_dosages SET is_default = (id = :id) WHERE product_id = :p",
                ['id' => $id, 'p' => (int)$row['product_id']]);
            dose_json(['ok' => true, 'doses' => dose_rows($db, (int)$row['product_id'])]);
        }

        default:
            dose_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('[erp2/api/dosages] ' . $action . ': ' . $e->getMessage());
    dose_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}
