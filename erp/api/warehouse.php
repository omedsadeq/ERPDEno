<?php
/**
 * ERP warehouse API — warehouse CRUD, stock transfers, stock adjustments.
 *
 * Thin dispatch onto ErpTransfer / ErpAdjustment, which own the transactions.
 * No stock logic here.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/ErpTransfer.php';
require_once __DIR__ . '/../../classes/ErpAdjustment.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

$writeActions = ['save_warehouse', 'transfer', 'adjust'];
if (in_array($action, $writeActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erp_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
} elseif (!erp_can('erp.products.view')) {
    erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

function erp_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $j = json_decode($raw, true);
        if (is_array($j)) { return $j; }
    }
    return $_POST;
}

$db = Database::getInstance();

try {
    switch ($action) {

        /** Warehouses with their current stock value and line count. */
        case 'list': {
            $rows = $db->fetchAll(
                "SELECT w.id, w.code, w.name, w.location, w.is_default, w.is_active,
                        u.full_name AS manager_name,
                        COALESCE(s.line_count, 0) AS product_lines,
                        COALESCE(s.qty, 0)   AS total_qty,
                        COALESCE(s.value, 0) AS total_value
                 FROM erp_warehouses w
                 LEFT JOIN users u ON u.id = w.manager_id
                 LEFT JOIN (
                     SELECT warehouse_id,
                            COUNT(*) AS line_count,
                            SUM(quantity) AS qty,
                            SUM(quantity * average_cost) AS value
                     FROM erp_warehouse_product_stock
                     WHERE quantity <> 0
                     GROUP BY warehouse_id
                 ) s ON s.warehouse_id = w.id
                 ORDER BY w.is_default DESC, w.name"
            );
            if (!erp_can('erp.profit.view')) {
                foreach ($rows as &$r) { unset($r['total_value']); }
                unset($r);
            }
            erp_json(['ok' => true, 'results' => $rows]);
        }

        case 'save_warehouse': {
            if (!erp_can('erp.settings')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            if ($name === '' || $code === '') {
                erp_json(['ok' => false, 'error' => 'کۆد و ناو پێویستن'], 422);
            }

            $fields = [
                'code'       => $code,
                'name'       => $name,
                'location'   => trim((string)($_POST['location'] ?? '')) ?: null,
                'manager_id' => ((int)($_POST['manager_id'] ?? 0)) ?: null,
                'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
            ];

            try {
                $db->beginTransaction();

                // Exactly one default. Clearing the others first keeps the
                // "which warehouse does a new sale open on" answer unambiguous.
                if (!empty($_POST['is_default'])) {
                    $db->query("UPDATE erp_warehouses SET is_default = 0");
                    $fields['is_default'] = 1;
                }

                if ($id > 0) {
                    $old = $db->fetch("SELECT * FROM erp_warehouses WHERE id = :id", ['id' => $id]);
                    if (!$old) {
                        $db->rollback();
                        erp_json(['ok' => false, 'error' => 'کۆگا نەدۆزرایەوە'], 404);
                    }
                    // Refuse to deactivate a warehouse that still holds stock —
                    // it would vanish from every picker while its goods stayed
                    // on the books, unreachable.
                    if (empty($fields['is_active']) && (int)$old['is_active'] === 1) {
                        $held = $db->fetch(
                            "SELECT COALESCE(SUM(quantity),0) q FROM erp_warehouse_product_stock WHERE warehouse_id = :id",
                            ['id' => $id]
                        );
                        if ((float)$held['q'] > 0.0005) {
                            $db->rollback();
                            erp_json(['ok' => false,
                                'error' => 'ناتوانرێت ناچالاک بکرێت — هێشتا کاڵای تێدایە. سەرەتا بیگوازەوە'], 409);
                        }
                    }
                    $db->update('erp_warehouses', $fields, 'id = ?', [$id]);
                    $db->commit();
                    ErpAudit::logChange('edit_warehouse', 'WAREHOUSE', $id, $old['code'], $old, $fields);
                    erp_json(['ok' => true, 'id' => $id]);
                }

                $newId = (int)$db->insert('erp_warehouses', $fields);
                $db->commit();
                ErpAudit::log('create_warehouse', 'WAREHOUSE', $newId, $code, null, $fields);
                erp_json(['ok' => true, 'id' => $newId]);

            } catch (PDOException $e) {
                if ($db->inTransaction()) { $db->rollback(); }
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    erp_json(['ok' => false, 'error' => 'ئەم کۆدە پێشتر بەکارهاتووە'], 409);
                }
                throw $e;
            }
        }

        case 'transfer': {
            if (!erp_can('erp.stock.transfer')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $data = erp_payload();
            // BOTH ends are checked. A user allowed only in the shop must not
            // be able to pull stock out of the main store into it, which is
            // the same mixing the dropdown filter prevents by hand.
            try {
                erp_assert_warehouse((int)($data['from_warehouse_id'] ?? 0));
                erp_assert_warehouse((int)($data['to_warehouse_id'] ?? 0));
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            try {
                erp_json(['ok' => true] + ErpTransfer::create($data));
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        case 'adjust': {
            if (!erp_can('erp.stock.adjust')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $data = erp_payload();
            try {
                erp_assert_warehouse((int)($data['warehouse_id'] ?? 0));
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            try {
                erp_json(['ok' => true] + ErpAdjustment::create($data));
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        /** Recent transfers / adjustments for the history panes. */
        case 'recent_transfers': {
            $rows = $db->fetchAll(
                "SELECT t.id, t.transfer_number, t.transfer_date, t.status,
                        wf.name AS from_name, wt.name AS to_name,
                        (SELECT COUNT(*) FROM erp_stock_transfer_items i WHERE i.transfer_id = t.id) AS line_count
                 FROM erp_stock_transfers t
                 JOIN erp_warehouses wf ON wf.id = t.from_warehouse_id
                 JOIN erp_warehouses wt ON wt.id = t.to_warehouse_id
                 ORDER BY t.id DESC LIMIT 20"
            );
            erp_json(['ok' => true, 'results' => $rows]);
        }

        case 'recent_adjustments': {
            $rows = $db->fetchAll(
                "SELECT a.id, a.adjustment_number, a.adjustment_date, a.reason, a.status,
                        w.name AS warehouse_name,
                        (SELECT COUNT(*) FROM erp_stock_adjustment_items i WHERE i.adjustment_id = a.id) AS line_count
                 FROM erp_stock_adjustments a
                 JOIN erp_warehouses w ON w.id = a.warehouse_id
                 ORDER BY a.id DESC LIMIT 20"
            );
            foreach ($rows as &$r) {
                $r['reason_label'] = ErpAdjustment::reasonLabel($r['reason']);
            }
            unset($r);
            erp_json(['ok' => true, 'results' => $rows]);
        }

        /** Current quantity of one product in one warehouse, for the grids. */
        case 'level': {
            $productId   = (int)($_GET['product_id'] ?? 0);
            $warehouseId = (int)($_GET['warehouse_id'] ?? 0);
            if ($productId <= 0 || $warehouseId <= 0) {
                erp_json(['ok' => false, 'error' => 'product_id and warehouse_id required'], 400);
            }
            $lvl = ErpStock::levelOf($productId, $warehouseId);
            if (!erp_can('erp.profit.view')) {
                unset($lvl['average_cost']);
            }
            erp_json(['ok' => true] + $lvl);
        }

        default:
            erp_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    error_log('[erp/api/warehouse] ' . $e->getMessage());
    erp_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}
