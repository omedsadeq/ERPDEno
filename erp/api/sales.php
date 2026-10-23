<?php
/**
 * ERP sales API — save, load, cancel, recent list.
 *
 * The whole invoice arrives as one JSON POST and is handled by ErpSales, which
 * owns the transaction. This endpoint only authenticates, checks permissions,
 * decodes and reports; it contains no stock or accounting logic of its own.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/ErpSales.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'recent';

$writeActions = ['save', 'update', 'cancel', 'return'];
if (in_array($action, $writeActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erp_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
} elseif (!erp_can('erp.sales.view')) {
    erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$db = Database::getInstance();

/**
 * Read the invoice payload. The grid posts JSON so nested item rows survive
 * intact; form encoding would flatten them into items[0][product_id] strings.
 */
function erp_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    // Fall back to a form post carrying the document in one JSON field.
    if (!empty($_POST['payload'])) {
        $j = json_decode((string)$_POST['payload'], true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

try {
    switch ($action) {

        case 'save': {
            if (!erp_can('erp.sales.create')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $data = erp_payload();

            // The warehouse arrives from a dropdown this user may not have
            // been offered. Checked again here so a hand-written POST cannot
            // sell the main store's stock from the shop till.
            try {
                erp_assert_warehouse((int)($data['warehouse_id'] ?? 0));
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 403);
            }

            // A price override below the product's floor needs its own right,
            // enforced here rather than in the browser where it is only a
            // disabled input away from being bypassed.
            erp_guard_prices($db, $data['items'] ?? []);

            try {
                $res = ErpSales::create($data);
                erp_json(['ok' => true] + $res);
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        case 'update': {
            if (!erp_can('erp.sales.edit')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $data = erp_payload();
            $id   = (int)($data['id'] ?? 0);
            erp_guard_prices($db, $data['items'] ?? []);
            try {
                $res = ErpSales::update($id, $data);
                erp_json(['ok' => true] + $res);
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        case 'cancel': {
            if (!erp_can('erp.invoice.cancel')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            try {
                ErpSales::cancel($id, trim((string)($_POST['reason'] ?? '')) ?: null);
                erp_json(['ok' => true]);
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        case 'return': {
            if (!erp_can('erp.sales.return')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            try {
                erp_json(['ok' => true] + ErpSales::createReturn(erp_payload()));
            } catch (RuntimeException $e) {
                erp_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        /**
         * An invoice's lines with how much of each is still returnable.
         * Cost rides along only for users allowed to see it.
         */
        case 'returnable': {
            if (!erp_can('erp.sales.return') && !erp_can('erp.sales.view')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $inv = ErpSales::returnableLines((int)($_GET['id'] ?? 0));
            if (!$inv) {
                erp_json(['ok' => false, 'error' => 'وەسڵ نەدۆزرایەوە'], 404);
            }
            if (!erp_can('erp.profit.view')) {
                foreach ($inv['items'] as &$it) { unset($it['unit_cost']); }
                unset($it);
            }
            erp_json(['ok' => true, 'invoice' => $inv]);
        }

        /** Recent sales returns, for the history pane. */
        case 'recent_returns': {
            $rows = $db->fetchAll(
                "SELECT r.id, r.return_number, r.return_date, r.grand_total, r.reason, r.status,
                        c.name AS customer_name, si.invoice_number AS original_number
                 FROM erp_sales_returns r
                 JOIN erp_customers c ON c.id = r.customer_id
                 LEFT JOIN erp_sales_invoices si ON si.id = r.original_invoice_id
                 ORDER BY r.id DESC LIMIT 20"
            );
            erp_json(['ok' => true, 'results' => $rows]);
        }

        case 'load': {
            $id  = (int)($_GET['id'] ?? 0);
            $inv = ErpSales::load($id);
            if (!$inv) {
                erp_json(['ok' => false, 'error' => 'وەسڵ نەدۆزرایەوە'], 404);
            }
            if (!erp_can('erp.profit.view')) {
                // Cost and profit are privileged. Strip them server-side —
                // hiding a column in the grid still ships the numbers.
                unset($inv['total_cost']);
                foreach ($inv['items'] as &$it) {
                    unset($it['unit_cost'], $it['line_cost'], $it['line_profit']);
                }
                unset($it);
            }
            erp_json(['ok' => true, 'invoice' => $inv]);
        }

        /**
         * Paged, filtered invoice list for the browser screen.
         *
         * Separate from 'recent' on purpose: 'recent' is a fixed short list the
         * till uses for lookups, this one pages through the whole history with
         * filters. Sharing one action would mean the till paying for the COUNT.
         */
        case 'browse': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 25;
            $offset  = ($page - 1) * $perPage;

            $where  = ['1=1'];
            $params = [];

            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $where[] = '(si.invoice_number LIKE :pre OR c.name LIKE :pre2)';
                $params['pre']  = $q . '%';
                $params['pre2'] = $q . '%';
            }
            $from = erp_date($_GET['from'] ?? null);
            $to   = erp_date($_GET['to'] ?? null);
            if ($from) { $where[] = 'si.invoice_date >= :f'; $params['f'] = $from; }
            if ($to)   { $where[] = 'si.invoice_date <= :t'; $params['t'] = $to; }

            $status = $_GET['status'] ?? '';
            if (in_array($status, ['confirmed', 'cancelled', 'draft'], true)) {
                $where[] = 'si.status = :st';
                $params['st'] = $status;
            }
            $whereSql = implode(' AND ', $where);

            $rows = $db->fetchAll(
                "SELECT si.id, si.invoice_number, si.invoice_date, si.grand_total,
                        si.paid_amount, si.total_cost, si.status, c.name AS customer_name
                 FROM erp_sales_invoices si
                 JOIN erp_customers c ON c.id = si.customer_id
                 WHERE {$whereSql}
                 ORDER BY si.id DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            );

            if (!erp_can('erp.profit.view')) {
                foreach ($rows as &$r) { unset($r['total_cost']); }
                unset($r);
            }

            $total = (int)($db->fetch(
                "SELECT COUNT(*) c FROM erp_sales_invoices si
                 JOIN erp_customers c ON c.id = si.customer_id
                 WHERE {$whereSql}",
                $params
            )['c'] ?? 0);

            erp_json([
                'ok'         => true,
                'results'    => $rows,
                'page'       => $page,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ]);
        }

        case 'recent': {
            $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
            $q      = trim((string)($_GET['q'] ?? ''));
            $params = [];
            $where  = '1=1';
            if ($q !== '') {
                $where = '(si.invoice_number LIKE :pre OR c.name LIKE :pre2)';
                $params['pre']  = $q . '%';
                $params['pre2'] = $q . '%';
            }
            $rows = $db->fetchAll(
                "SELECT si.id, si.invoice_number, si.invoice_date, si.grand_total,
                        si.paid_amount, si.status, c.name AS customer_name
                 FROM erp_sales_invoices si
                 JOIN erp_customers c ON c.id = si.customer_id
                 WHERE {$where}
                 ORDER BY si.id DESC
                 LIMIT {$limit}",
                $params
            );
            erp_json(['ok' => true, 'results' => $rows]);
        }

        default:
            erp_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    error_log('[erp/api/sales] ' . $e->getMessage());
    erp_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}

/**
 * Refuse a line priced under the product's minimum unless the user holds
 * erp.price.sale. Checked in ONE query for all lines rather than per item.
 */
function erp_guard_prices(Database $db, array $items): void
{
    if (erp_can('erp.price.sale') || !$items) {
        return;
    }
    $ids = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        if ($pid > 0) { $ids[$pid] = true; }
    }
    if (!$ids) {
        return;
    }
    $in   = implode(',', array_map('intval', array_keys($ids)));
    $rows = $db->fetchAll("SELECT id, name, sale_price, min_sale_price FROM erp_products WHERE id IN ({$in})");
    $map  = [];
    foreach ($rows as $r) { $map[(int)$r['id']] = $r; }

    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        if (!isset($map[$pid])) {
            continue;
        }
        $floor = (float)$map[$pid]['min_sale_price'];
        $price = (float)($it['unit_price'] ?? 0);
        if ($floor > 0 && $price < $floor - 0.001) {
            erp_json([
                'ok'    => false,
                'error' => sprintf('ڕێگەت پێنەدراوە نرخ کەم بکەیتەوە: %s (کەمترین %s)',
                    $map[$pid]['name'], number_format($floor, 0)),
            ], 403);
        }
    }
}
