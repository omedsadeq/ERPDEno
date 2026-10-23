<?php
/**
 * ERP purchase API — save, update, merge, load, return, recent.
 *
 * Thin dispatch onto Erp2Purchase, which owns the transaction covering the
 * invoice, its items, the stock receipt and the supplier ledger. No stock or
 * accounting logic lives here.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/Erp2Purchase.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp2_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'recent';

$writeActions = ['save', 'update', 'return'];
if (in_array($action, $writeActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erp2_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
} elseif (!erp2_can('erp2.purchase.view')) {
    erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$db = Database::getInstance();

/** Invoice bodies arrive as JSON so the nested item rows survive intact. */
function erp2_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

try {
    switch ($action) {

        case 'save': {
            if (!erp2_can('erp2.purchase.create')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $data = erp2_payload();

            try {
                erp2_assert_warehouse((int)($data['warehouse_id'] ?? 0));
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }

            // Overriding what a product costs is a privileged act: it moves the
            // weighted average and therefore every future profit figure.
            // Without the right, the line price is forced back to the
            // product's recorded purchase price.
            if (!erp2_can('erp2.price.purchase')) {
                $data['items'] = erp2_lock_costs($db, $data['items'] ?? []);
            }

            try {
                $res = Erp2Purchase::create($data);
                erp2_json(['ok' => true] + $res);
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        /** Edit a posted invoice: same payload as 'save', plus its id. */
        case 'update': {
            if (!erp2_can('erp2.purchase.edit')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $data = erp2_payload();
            $id   = (int)($data['id'] ?? 0);
            $inv  = $db->fetch("SELECT warehouse_id FROM erp2_purchase_invoices WHERE id = :id", ['id' => $id]);
            if (!$inv) {
                erp2_json(['ok' => false, 'error' => 'وەسڵ نەدۆزرایەوە'], 404);
            }
            try {
                erp2_assert_warehouse((int)$inv['warehouse_id']);
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            if (!erp2_can('erp2.price.purchase')) {
                $data['items'] = erp2_lock_costs($db, $data['items'] ?? []);
            }
            try {
                $res = Erp2Purchase::update($id, $data);
                erp2_json(['ok' => true] + $res);
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        /** Fold several posted invoices of one supplier into the earliest: { ids: [...] }. */
        case 'merge': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                erp2_json(['ok' => false, 'error' => 'POST required'], 405);
            }
            Csrf::require();
            if (!erp2_can('erp2.purchase.edit')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $ids = array_values(array_filter(array_map('intval', (array)(erp2_payload()['ids'] ?? []))));
            try {
                if ($ids) {
                    $in = implode(',', $ids);
                    foreach ($db->fetchAll("SELECT DISTINCT warehouse_id FROM erp2_purchase_invoices WHERE id IN ({$in})") as $w) {
                        erp2_assert_warehouse((int)$w['warehouse_id']);
                    }
                }
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }
            try {
                $res = Erp2Purchase::merge($ids);
                erp2_json(['ok' => true] + $res);
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        case 'return': {
            if (!erp2_can('erp2.purchase.return')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            try {
                $res = Erp2Purchase::createReturn(erp2_payload());
                erp2_json(['ok' => true] + $res);
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        case 'load': {
            $inv = Erp2Purchase::load((int)($_GET['id'] ?? 0));
            if (!$inv) {
                erp2_json(['ok' => false, 'error' => 'وەسڵ نەدۆزرایەوە'], 404);
            }
            erp2_json(['ok' => true, 'invoice' => $inv]);
        }

        /** Lines of a purchase invoice with how much may still be returned. */
        case 'returnable': {
            if (!erp2_can('erp2.purchase.return') && !erp2_can('erp2.purchase.view')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $inv = Erp2Purchase::returnableLines((int)($_GET['id'] ?? 0));
            if (!$inv) {
                erp2_json(['ok' => false, 'error' => 'وەسڵ نەدۆزرایەوە'], 404);
            }
            erp2_json(['ok' => true, 'invoice' => $inv]);
        }

        case 'recent_returns': {
            $rows = $db->fetchAll(
                "SELECT r.id, r.return_number, r.return_date, r.grand_total, r.reason, r.status,
                        s.name AS supplier_name, pi.invoice_number AS original_number
                 FROM erp2_purchase_returns r
                 JOIN erp2_suppliers s ON s.id = r.supplier_id
                 LEFT JOIN erp2_purchase_invoices pi ON pi.id = r.original_invoice_id
                 ORDER BY r.id DESC LIMIT 20"
            );
            erp2_json(['ok' => true, 'results' => $rows]);
        }

        /** Paged, filtered purchase-invoice list (erp2/purchase-invoices.php). */
        case 'browse': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 25;
            $offset  = ($page - 1) * $perPage;
            $where   = ['1=1'];
            $params  = [];
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $where[] = '(pi.invoice_number LIKE :a OR pi.supplier_invoice_no LIKE :b OR s.name LIKE :c)';
                $params += ['a' => $q . '%', 'b' => $q . '%', 'c' => $q . '%'];
            }
            $from = erp2_date($_GET['from'] ?? null);
            $to   = erp2_date($_GET['to'] ?? null);
            if ($from) { $where[] = 'pi.invoice_date >= :f'; $params['f'] = $from; }
            if ($to)   { $where[] = 'pi.invoice_date <= :t'; $params['t'] = $to; }
            $status = $_GET['status'] ?? '';
            if (in_array($status, ['confirmed', 'cancelled', 'draft'], true)) {
                $where[] = 'pi.status = :st';
                $params['st'] = $status;
            }
            $whereSql = implode(' AND ', $where);
            $rows = $db->fetchAll(
                "SELECT pi.id, pi.invoice_number, pi.supplier_invoice_no, pi.invoice_date, pi.grand_total,
                        pi.paid_amount, pi.status, s.name AS supplier_name,
                        (SELECT COUNT(*) FROM erp2_purchase_items it WHERE it.invoice_id = pi.id) AS line_count
                 FROM erp2_purchase_invoices pi
                 JOIN erp2_suppliers s ON s.id = pi.supplier_id
                 WHERE {$whereSql}
                 ORDER BY pi.id DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            );
            $total = (int)($db->fetch(
                "SELECT COUNT(*) c FROM erp2_purchase_invoices pi JOIN erp2_suppliers s ON s.id = pi.supplier_id WHERE {$whereSql}",
                $params
            )['c'] ?? 0);
            erp2_json(['ok' => true, 'results' => $rows, 'page' => $page, 'total' => $total,
                       'totalPages' => (int)ceil($total / $perPage)]);
        }

        case 'recent': {
            $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
            $q      = trim((string)($_GET['q'] ?? ''));
            $where  = '1=1';
            $params = [];
            if ($q !== '') {
                $where = '(pi.invoice_number LIKE :pre OR s.name LIKE :pre2)';
                $params['pre']  = $q . '%';
                $params['pre2'] = $q . '%';
            }
            $rows = $db->fetchAll(
                "SELECT pi.id, pi.invoice_number, pi.invoice_date, pi.grand_total,
                        pi.paid_amount, pi.status, s.name AS supplier_name
                 FROM erp2_purchase_invoices pi
                 JOIN erp2_suppliers s ON s.id = pi.supplier_id
                 WHERE {$where}
                 ORDER BY pi.id DESC
                 LIMIT {$limit}",
                $params
            );
            erp2_json(['ok' => true, 'results' => $rows]);
        }

        default:
            erp2_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    error_log('[erp/api/purchases] ' . $e->getMessage());
    erp2_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}

/**
 * Replace every line price with the product's stored purchase price, in ONE
 * lookup for the whole invoice rather than a query per line.
 */
function erp2_lock_costs(Database $db, array $items): array
{
    $ids = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        if ($pid > 0) { $ids[$pid] = true; }
    }
    if (!$ids) {
        return $items;
    }
    $in   = implode(',', array_map('intval', array_keys($ids)));
    $rows = $db->fetchAll("SELECT id, purchase_price FROM erp2_products WHERE id IN ({$in})");
    $map  = [];
    foreach ($rows as $r) { $map[(int)$r['id']] = (float)$r['purchase_price']; }

    foreach ($items as &$it) {
        $pid = (int)($it['product_id'] ?? 0);
        if (isset($map[$pid])) {
            $it['unit_price'] = $map[$pid];
        }
    }
    unset($it);
    return $items;
}
