<?php
/**
 * دەرمانخانە — the cashier screen's own reads (erp2/pos.php).
 *
 * The sale itself is still saved by api/sales.php (Erp2Sales::create, the
 * price-floor guard and the warehouse check all live there). This endpoint
 * only feeds the screen, gated on the right to SELL rather than on the
 * product/customer management rights, so a cashier needs exactly one grant.
 *
 * Cost never leaves this endpoint: the cashier sees prices and stock only.
 *
 *   catalog       product cards — best sellers when there is no search
 *   scan          one product by barcode / pack barcode / code
 *   customers     customer picker, walk-in first, with what each one owes
 *   add_customer  POST — quick-create a customer from the till
 *   today         this cashier's sales today: totals + receipt list
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pos_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!erp2_can('erp2.sales.create')) {
    pos_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'catalog';
if ($action === 'add_customer') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pos_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
}

$db = Database::getInstance();

/** The requested warehouse when this user may use it, else their default. */
function pos_warehouse(): int
{
    $want = (int)($_GET['warehouse_id'] ?? 0);
    foreach (erp2_warehouses() as $w) {
        if ((int)$w['id'] === $want) {
            return $want;
        }
    }
    return erp2_default_warehouse_id();
}

const POS_PRODUCT_COLS = "p.id, p.product_code, p.barcode, p.name, p.name_ar, p.category_id, c.name AS category_name, p.type_id, t.name AS type_name,
        p.sale_price, p.min_sale_price, p.wholesale_price, p.unit_id, u.code AS unit_code, u.name AS unit_name,
        COALESCE(s.quantity, 0) AS stock";

try {
    switch ($action) {

        case 'catalog': {
            $wh    = pos_warehouse();
            $q     = trim((string)($_GET['q'] ?? ''));
            $cat   = (int)($_GET['category_id'] ?? 0);
            $typ   = (int)($_GET['type_id'] ?? 0);
            $limit = max(1, min(60, (int)($_GET['limit'] ?? 48)));

            $from = "FROM erp2_products p
                     LEFT JOIN erp2_categories c ON c.id = p.category_id
                     LEFT JOIN erp2_product_types t ON t.id = p.type_id
                     LEFT JOIN erp2_units u ON u.id = p.unit_id
                     LEFT JOIN erp2_warehouse_product_stock s ON s.product_id = p.id AND s.warehouse_id = :w";
            $catSql = ($cat > 0 ? ' AND p.category_id = :cat' : '') . ($typ > 0 ? ' AND p.type_id = :typ' : '');
            $base   = ['w' => $wh] + ($cat > 0 ? ['cat' => $cat] : []) + ($typ > 0 ? ['typ' => $typ] : []);

            if ($q === '') {
                // Nothing typed. One group open: its items in the order of the
                // clinic's prescription sheet. Everything: what this counter
                // sells most (last 60 days), then the sheet's order.
                $rows = $db->fetchAll(
                    "SELECT " . POS_PRODUCT_COLS . ", COALESCE(ts.sold, 0) AS sold
                     {$from}
                     LEFT JOIN (
                        SELECT it.product_id, SUM(it.quantity * it.unit_factor) AS sold
                        FROM erp2_sales_items it
                        JOIN erp2_sales_invoices si ON si.id = it.invoice_id
                        WHERE si.status = 'confirmed' AND si.invoice_date >= :since
                        GROUP BY it.product_id
                     ) ts ON ts.product_id = p.id
                     WHERE p.is_active = 1{$catSql}
                     ORDER BY " . ($cat > 0 ? 'p.sort_order, p.name' : 'sold DESC, COALESCE(c.sort_order, 999), p.sort_order, p.name') . "
                     LIMIT {$limit}",
                    $base + ['since' => date('Y-m-d', strtotime('-60 days'))]
                );
                pos_json(['ok' => true, 'results' => $rows, 'mode' => 'top']);
            }

            // Same order as the till's search: exact code, then prefix (index
            // friendly), and only then the slower "contains".
            $rows = $db->fetchAll(
                "SELECT " . POS_PRODUCT_COLS . " {$from}
                 WHERE p.is_active = 1{$catSql} AND (p.barcode = :q OR p.product_code = :q2)
                 LIMIT {$limit}",
                $base + ['q' => $q, 'q2' => $q]
            );
            if (!$rows) {
                $rows = $db->fetchAll(
                    "SELECT " . POS_PRODUCT_COLS . " {$from}
                     WHERE p.is_active = 1{$catSql}
                       AND (p.name LIKE :a OR p.name_ar LIKE :b OR p.name_ku LIKE :c OR p.product_code LIKE :d)
                     ORDER BY p.name LIMIT {$limit}",
                    $base + ['a' => $q . '%', 'b' => $q . '%', 'c' => $q . '%', 'd' => $q . '%']
                );
            }
            if (!$rows) {
                $rows = $db->fetchAll(
                    "SELECT " . POS_PRODUCT_COLS . " {$from}
                     WHERE p.is_active = 1{$catSql}
                       AND (p.name LIKE :a OR p.name_ar LIKE :b OR p.name_ku LIKE :c)
                     ORDER BY p.name LIMIT {$limit}",
                    $base + ['a' => '%' . $q . '%', 'b' => '%' . $q . '%', 'c' => '%' . $q . '%']
                );
            }
            pos_json(['ok' => true, 'results' => $rows, 'mode' => 'search']);
        }

        /**
         * The scanner. A product barcode sells one piece; a pack barcode
         * (erp2_product_units) sells the pack, priced per pack, with the factor
         * so stock is still taken in pieces. The product code works too, for
         * items without a label.
         */
        case 'scan': {
            $wh   = pos_warehouse();
            $code = trim((string)($_GET['code'] ?? ''));
            if ($code === '') {
                pos_json(['ok' => true, 'product' => null]);
            }
            $row = $db->fetch(
                "SELECT " . POS_PRODUCT_COLS . ", 1.0 AS unit_factor
                 FROM erp2_products p
                 LEFT JOIN erp2_categories c ON c.id = p.category_id
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 LEFT JOIN erp2_units u ON u.id = p.unit_id
                 LEFT JOIN erp2_warehouse_product_stock s ON s.product_id = p.id AND s.warehouse_id = :w
                 WHERE p.is_active = 1 AND (p.barcode = :c OR p.product_code = :c2)
                 LIMIT 1",
                ['w' => $wh, 'c' => $code, 'c2' => $code]
            );
            if (!$row) {
                $row = $db->fetch(
                    "SELECT p.id, p.product_code, p.barcode, p.name, p.name_ar, p.category_id, c.name AS category_name, p.type_id, t.name AS type_name,
                            COALESCE(pu.sale_price, p.sale_price * pu.factor) AS sale_price,
                            p.min_sale_price, p.wholesale_price, pu.unit_id, u.code AS unit_code, u.name AS unit_name,
                            COALESCE(s.quantity, 0) AS stock, pu.factor AS unit_factor
                     FROM erp2_product_units pu
                     JOIN erp2_products p ON p.id = pu.product_id
                     LEFT JOIN erp2_categories c ON c.id = p.category_id
                     LEFT JOIN erp2_product_types t ON t.id = p.type_id
                     LEFT JOIN erp2_units u ON u.id = pu.unit_id
                     LEFT JOIN erp2_warehouse_product_stock s ON s.product_id = p.id AND s.warehouse_id = :w
                     WHERE pu.barcode = :c AND pu.is_active = 1 AND p.is_active = 1
                     LIMIT 1",
                    ['w' => $wh, 'c' => $code]
                );
            }
            pos_json(['ok' => true, 'product' => $row ?: null]);
        }

        case 'customers': {
            $q = trim((string)($_GET['q'] ?? ''));
            $balance = "(SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0)
                         FROM erp2_customer_ledger l WHERE l.customer_id = c.id) AS balance";
            $cols = "c.id, c.customer_code AS code, c.name, c.phone, c.is_walk_in, {$balance}";
            if ($q === '') {
                $rows = $db->fetchAll(
                    "SELECT {$cols} FROM erp2_customers c WHERE c.is_active = 1
                     ORDER BY c.is_walk_in DESC, c.id DESC LIMIT 12"
                );
            } else {
                $rows = $db->fetchAll(
                    "SELECT {$cols} FROM erp2_customers c
                     WHERE c.is_active = 1
                       AND (c.name LIKE :a OR c.phone LIKE :b OR c.customer_code = :e)
                     ORDER BY c.is_walk_in DESC, c.name LIMIT 15",
                    ['a' => $q . '%', 'b' => $q . '%', 'e' => $q]
                );
                if (!$rows) {
                    $rows = $db->fetchAll(
                        "SELECT {$cols} FROM erp2_customers c
                         WHERE c.is_active = 1 AND c.name LIKE :a
                         ORDER BY c.name LIMIT 15",
                        ['a' => '%' . $q . '%']
                    );
                }
            }
            pos_json(['ok' => true, 'results' => $rows]);
        }

        case 'add_customer': {
            $name  = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($name === '') {
                pos_json(['ok' => false, 'error' => 'ناوی کڕیار پێویستە'], 422);
            }
            // The same name and phone again is the same person, not a second
            // account to split their debt across.
            $dupe = $db->fetch(
                "SELECT id, customer_code AS code, name, phone FROM erp2_customers
                 WHERE is_active = 1 AND name = :n AND COALESCE(phone, '') = :p LIMIT 1",
                ['n' => $name, 'p' => $phone]
            );
            if ($dupe) {
                pos_json(['ok' => true, 'customer' => $dupe + ['balance' => 0, 'is_walk_in' => 0], 'existed' => true]);
            }
            $db->beginTransaction();
            try {
                $code = Erp2Sequence::next('CUSTOMER');
                $id = (int)$db->insert('erp2_customers', [
                    'customer_code' => $code,
                    'name'          => $name,
                    'phone'         => $phone !== '' ? $phone : null,
                    'is_active'     => 1,
                    'created_by'    => $_SESSION['user_id'] ?? null,
                ]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollback(); }
                throw $e;
            }
            Erp2Audit::log('create_customer', 'CUSTOMER', $id, $code, null, ['name' => $name, 'phone' => $phone, 'via' => 'pos']);
            pos_json(['ok' => true, 'customer' => [
                'id' => $id, 'code' => $code, 'name' => $name, 'phone' => $phone, 'balance' => 0, 'is_walk_in' => 0,
            ]]);
        }

        /** This cashier's day: the counter chip and the receipts drawer. */
        case 'today': {
            // "Today" = rung up today, whatever date was typed on the invoice,
            // so the chip and the list below always agree. A range on
            // created_at keeps idx_erp2_si_created usable.
            $me    = (int)($_SESSION['user_id'] ?? 0);
            $range = ['u' => $me, 'a' => date('Y-m-d 00:00:00'), 'b' => date('Y-m-d 00:00:00', strtotime('+1 day'))];
            $sum = $db->fetch(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total,
                        COALESCE(SUM(LEAST(paid_amount, grand_total)), 0) AS cash
                 FROM erp2_sales_invoices
                 WHERE created_by = :u AND created_at >= :a AND created_at < :b AND status = 'confirmed'",
                $range
            );
            $rows = $db->fetchAll(
                "SELECT si.id, si.invoice_number, si.grand_total, si.paid_amount, si.status,
                        DATE_FORMAT(si.created_at, '%H:%i') AS time, c.name AS customer_name, c.is_walk_in,
                        (SELECT COUNT(*) FROM erp2_sales_items it WHERE it.invoice_id = si.id) AS line_count
                 FROM erp2_sales_invoices si
                 JOIN erp2_customers c ON c.id = si.customer_id
                 WHERE si.created_by = :u AND si.created_at >= :a AND si.created_at < :b
                 ORDER BY si.id DESC LIMIT 60",
                $range
            );
            pos_json(['ok' => true, 'count' => (int)$sum['cnt'], 'total' => (float)$sum['total'],
                      'cash' => (float)$sum['cash'], 'results' => $rows]);
        }

        default:
            pos_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('[erp2/api/pos] ' . $action . ': ' . $e->getMessage());
    pos_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}
