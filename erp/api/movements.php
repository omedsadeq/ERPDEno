<?php
/**
 * ERP stock-movement API — the readable face of erp_stock_movements.
 *
 * The movement ledger is the truth behind every quantity in the system, but
 * until now nothing could read it back. This is deliberately read-only: a
 * movement is never edited or deleted, only offset by a new one, so there is
 * no write action here by design.
 *
 * Every query is bounded (date window + LIMIT) because this table is the one
 * expected to reach a million rows first.
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!erp_can('erp.products.view')) {
    erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$db      = Database::getInstance();
$action  = $_GET['action'] ?? 'list';
$canCost = erp_can('erp.profit.view');

/** Kurdish labels for the movement types, used by both actions. */
$TYPES = [
    'PURCHASE'        => 'کڕین',
    'PURCHASE_RETURN' => 'گەڕاندنەوەی کڕین',
    'SALE'            => 'فرۆشتن',
    'SALE_RETURN'     => 'گەڕاندنەوەی فرۆشتن',
    'TRANSFER_IN'     => 'هاتنی گواستنەوە',
    'TRANSFER_OUT'    => 'ڕۆیشتنی گواستنەوە',
    'ADJUSTMENT'      => 'ڕێکخستن',
    'DAMAGE'          => 'زیان',
    'OPENING'         => 'بڕی سەرەتایی',
    'DOCTOR_ISSUE'    => 'مەواد بۆ دکتۆر',
    'DOCTOR_RETURN'   => 'گەڕانەوەی مەوادی دکتۆر',
];

try {
    switch ($action) {

        case 'list': {
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 50;
            $offset  = ($page - 1) * $perPage;

            $where  = ['1=1'];
            $params = [];

            $productId = (int)($_GET['product_id'] ?? 0);
            if ($productId > 0) {
                $where[] = 'm.product_id = :pid';
                $params['pid'] = $productId;
            }
            $warehouseId = (int)($_GET['warehouse_id'] ?? 0);
            if ($warehouseId > 0) {
                $where[] = 'm.warehouse_id = :wid';
                $params['wid'] = $warehouseId;
            }
            $type = $_GET['type'] ?? '';
            if (isset($TYPES[$type])) {
                $where[] = 'm.transaction_type = :tp';
                $params['tp'] = $type;
            }
            // Dates are compared against the DATE part so a window that ends
            // "today" still includes movements made later today.
            $from = erp_date($_GET['from'] ?? null);
            $to   = erp_date($_GET['to'] ?? null);
            if ($from) { $where[] = 'm.created_at >= :f';  $params['f'] = $from . ' 00:00:00'; }
            if ($to)   { $where[] = 'm.created_at <= :t';  $params['t'] = $to . ' 23:59:59'; }

            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $where[] = '(p.name LIKE :pre OR p.product_code = :exact OR m.document_number LIKE :pre2)';
                $params['pre']   = $q . '%';
                $params['pre2']  = $q . '%';
                $params['exact'] = $q;
            }
            $whereSql = implode(' AND ', $where);

            $rows = $db->fetchAll(
                "SELECT m.id, m.created_at, m.transaction_type, m.document_number,
                        m.quantity_in, m.quantity_out, m.unit_cost, m.balance_after,
                        m.batch_number, m.notes,
                        p.name AS product_name, p.product_code,
                        w.name AS warehouse_name,
                        u.full_name AS user_name
                 FROM erp_stock_movements m
                 JOIN erp_products p ON p.id = m.product_id
                 JOIN erp_warehouses w ON w.id = m.warehouse_id
                 LEFT JOIN users u ON u.id = m.created_by
                 WHERE {$whereSql}
                 ORDER BY m.id DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            );

            foreach ($rows as &$r) {
                $r['type_label'] = $TYPES[$r['transaction_type']] ?? $r['transaction_type'];
                if (!$canCost) {
                    unset($r['unit_cost']);
                }
            }
            unset($r);

            $total = (int)($db->fetch(
                "SELECT COUNT(*) c
                 FROM erp_stock_movements m
                 JOIN erp_products p ON p.id = m.product_id
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

        /**
         * Totals per movement type for the current filter — the "where did the
         * stock go" summary that a raw list cannot show at a glance.
         */
        case 'summary': {
            $productId   = (int)($_GET['product_id'] ?? 0);
            $warehouseId = (int)($_GET['warehouse_id'] ?? 0);
            $from = erp_date($_GET['from'] ?? null);
            $to   = erp_date($_GET['to'] ?? null);

            $where  = ['1=1'];
            $params = [];
            if ($productId > 0)   { $where[] = 'product_id = :pid';   $params['pid'] = $productId; }
            if ($warehouseId > 0) { $where[] = 'warehouse_id = :wid'; $params['wid'] = $warehouseId; }
            if ($from) { $where[] = 'created_at >= :f'; $params['f'] = $from . ' 00:00:00'; }
            if ($to)   { $where[] = 'created_at <= :t'; $params['t'] = $to . ' 23:59:59'; }

            $rows = $db->fetchAll(
                "SELECT transaction_type,
                        COUNT(*) AS moves,
                        COALESCE(SUM(quantity_in), 0)  AS qty_in,
                        COALESCE(SUM(quantity_out), 0) AS qty_out
                 FROM erp_stock_movements
                 WHERE " . implode(' AND ', $where) . "
                 GROUP BY transaction_type
                 ORDER BY transaction_type",
                $params
            );
            foreach ($rows as &$r) {
                $r['type_label'] = $TYPES[$r['transaction_type']] ?? $r['transaction_type'];
            }
            unset($r);
            erp_json(['ok' => true, 'results' => $rows]);
        }

        default:
            erp_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    error_log('[erp/api/movements] ' . $e->getMessage());
    erp_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}
