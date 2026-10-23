<?php
/**
 * ERP product API — list, search, barcode lookup, save.
 *
 * Follows the api/approval-center.php envelope used elsewhere in the project:
 * {"ok": true, ...} / {"ok": false, "error": "..."}.
 *
 * PERFORMANCE (the module brief's 100k-product target):
 *   - every query is LIMITed, server side. Nothing ever returns the full table
 *   - `search` clamps to 20 rows for the invoice grid's type-ahead
 *   - `list` pages at 25 rows
 *   - a numeric-looking term tries barcode/product_code EQUALITY first, which
 *     is a unique-index hit rather than a scan
 *   - text search uses a PREFIX LIKE ('amox%'), which uses idx_erp2_prod_name.
 *     A leading-wildcard '%amox%' cannot use an index and would table-scan
 *     100k rows on every keystroke, so it is only the fallback when the prefix
 *     match finds nothing
 *   - explicit column lists, never SELECT *
 *   - the stock quantity is one LEFT JOIN on the summary table, not a SUM over
 *     erp2_stock_movements and not a per-row lookup (no N+1)
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/Erp2ProductUnits.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Emit and stop. */
function erp2_json($payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'search';

// Reads need view rights; writes need their own and must be POST + CSRF.
$writeActions = ['save', 'toggle_active', 'set_type', 'quick_category', 'quick_brand', 'quick_type'];
if (in_array($action, $writeActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erp2_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
} elseif (!erp2_can('erp2.products.view')) {
    erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$db = Database::getInstance();

try {
    switch ($action) {

        /**
         * Type-ahead for the sales/purchase grid. Returns at most 20 rows.
         */
        case 'search': {
            $q           = trim((string)($_GET['q'] ?? ''));
            $warehouseId = (int)($_GET['warehouse_id'] ?? erp2_default_warehouse_id());
            $limit       = max(1, min(20, (int)($_GET['limit'] ?? 20)));

            if ($q === '') {
                // browse=1: nothing typed yet — the whole list in the order of
                // the prescription sheet, so the cell can be picked from at once.
                if (empty($_GET['browse'])) {
                    erp2_json(['ok' => true, 'results' => []]);
                }
                erp2_json(['ok' => true, 'results' => erp2_product_browse($db, $warehouseId, 200)]);
            }

            $rows = erp2_product_search($db, $q, $warehouseId, $limit);
            erp2_json(['ok' => true, 'results' => $rows]);
        }

        /**
         * Exact barcode resolve for the scanner. Returns ONE product or null.
         * Also matches an alternate pack barcode, returning its unit factor so
         * scanning a box of 10 sells 10 pieces.
         */
        case 'barcode': {
            $code        = trim((string)($_GET['code'] ?? ''));
            $warehouseId = (int)($_GET['warehouse_id'] ?? erp2_default_warehouse_id());
            if ($code === '') {
                erp2_json(['ok' => false, 'error' => 'no code']);
            }

            $row = $db->fetch(
                "SELECT p.id, p.product_code, p.barcode, p.name, p.sale_price, p.min_sale_price,
                        p.wholesale_price, p.purchase_price, p.unit_id, u.code AS unit_code,
                        p.category_id, c.name AS category_name, p.type_id, t.name AS type_name,
                        p.brand_id, br.name AS brand_name,
                        COALESCE(s.quantity, 0) AS stock, COALESCE(s.average_cost, 0) AS avg_cost,
                        1.0 AS unit_factor
                 FROM erp2_products p
                 LEFT JOIN erp2_units u ON u.id = p.unit_id
                 LEFT JOIN erp2_categories c ON c.id = p.category_id
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 LEFT JOIN erp2_brands br ON br.id = p.brand_id
                 LEFT JOIN erp2_warehouse_product_stock s
                        ON s.product_id = p.id AND s.warehouse_id = :w
                 WHERE p.barcode = :c AND p.is_active = 1
                 LIMIT 1",
                ['c' => $code, 'w' => $warehouseId]
            );

            if (!$row) {
                $row = $db->fetch(
                    "SELECT p.id, p.product_code, p.barcode, p.name,
                            COALESCE(pu.sale_price, p.sale_price * pu.factor) AS sale_price,
                            p.min_sale_price, p.wholesale_price, p.purchase_price,
                            pu.unit_id, u.code AS unit_code,
                            p.category_id, c.name AS category_name, p.type_id, t.name AS type_name,
                            p.brand_id, br.name AS brand_name,
                            COALESCE(s.quantity, 0) AS stock, COALESCE(s.average_cost, 0) AS avg_cost,
                            pu.factor AS unit_factor
                     FROM erp2_product_units pu
                     JOIN erp2_products p ON p.id = pu.product_id
                     LEFT JOIN erp2_units u ON u.id = pu.unit_id
                     LEFT JOIN erp2_categories c ON c.id = p.category_id
                     LEFT JOIN erp2_product_types t ON t.id = p.type_id
                     LEFT JOIN erp2_brands br ON br.id = p.brand_id
                     LEFT JOIN erp2_warehouse_product_stock s
                            ON s.product_id = p.id AND s.warehouse_id = :w
                     WHERE pu.barcode = :c AND pu.is_active = 1 AND p.is_active = 1
                     LIMIT 1",
                    ['c' => $code, 'w' => $warehouseId]
                );
            }

            erp2_json(['ok' => true, 'product' => $row ?: null]);
        }

        /**
         * Paged product master for the management screen.
         */
        case 'list': {
            $q          = trim((string)($_GET['q'] ?? ''));
            $categoryId = (int)($_GET['category_id'] ?? 0);
            $typeId     = (int)($_GET['type_id'] ?? 0);
            $status     = $_GET['status'] ?? 'active';
            $lowOnly    = !empty($_GET['low_stock']);
            $page       = max(1, (int)($_GET['page'] ?? 1));
            $perPage    = 25;
            $offset     = ($page - 1) * $perPage;

            $where  = ['1=1'];
            $params = [];

            if ($status === 'active')        { $where[] = 'p.is_active = 1'; }
            elseif ($status === 'inactive')  { $where[] = 'p.is_active = 0'; }

            if ($categoryId > 0) {
                $where[] = 'p.category_id = :cat';
                $params['cat'] = $categoryId;
            }
            if ($typeId > 0) {
                $where[] = 'p.type_id = :typ';
                $params['typ'] = $typeId;
            }
            if ($q !== '') {
                // Anchored LIKE so idx_erp2_prod_name is usable. Exact code and
                // barcode are unique-index lookups.
                $where[] = '(p.name LIKE :qpre OR p.name_ar LIKE :qpre2 OR p.name_ku LIKE :qpre3
                             OR p.product_code = :qexact OR p.barcode = :qexact2)';
                $params['qpre']    = $q . '%';
                $params['qpre2']   = $q . '%';
                $params['qpre3']   = $q . '%';
                $params['qexact']  = $q;
                $params['qexact2'] = $q;
            }

            $whereSql = implode(' AND ', $where);

            // Stock is aggregated across warehouses in a derived table rather
            // than a correlated subquery per row, so this stays one pass.
            $stockJoin = "LEFT JOIN (
                    SELECT product_id, SUM(quantity) AS qty, SUM(quantity * average_cost) AS value
                    FROM erp2_warehouse_product_stock GROUP BY product_id
                 ) st ON st.product_id = p.id";

            $having = $lowOnly ? 'HAVING COALESCE(st.qty,0) <= p.min_stock AND p.min_stock > 0' : '';

            // In the order of the clinic's prescription sheet: group, then the
            // item's place in it (erp2_products.sort_order), then name.
            $rows = $db->fetchAll(
                "SELECT p.id, p.product_code, p.barcode, p.name, p.name_ar,
                        p.sale_price, p.purchase_price, p.min_stock, p.is_active,
                        p.category_id, p.type_id,
                        c.name AS category_name, t.name AS type_name, br.name AS brand_name,
                        u.code AS unit_code, u.name AS unit_name,
                        COALESCE(st.qty, 0) AS stock,
                        COALESCE(st.value, 0) AS stock_value
                 FROM erp2_products p
                 LEFT JOIN erp2_categories c ON c.id = p.category_id
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 LEFT JOIN erp2_brands br ON br.id = p.brand_id
                 LEFT JOIN erp2_units u ON u.id = p.unit_id
                 {$stockJoin}
                 WHERE {$whereSql}
                 {$having}
                 ORDER BY COALESCE(c.sort_order, 999), p.sort_order, p.name
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            );

            $totalRow = $db->fetch(
                "SELECT COUNT(*) AS c FROM erp2_products p WHERE {$whereSql}",
                $params
            );
            $total = (int)($totalRow['c'] ?? 0);

            erp2_json([
                'ok'         => true,
                'results'    => $rows,
                'page'       => $page,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ]);
        }

        /** Per-warehouse breakdown for one product. */
        case 'stock': {
            $productId = (int)($_GET['product_id'] ?? 0);
            if ($productId <= 0) {
                erp2_json(['ok' => false, 'error' => 'product_id required'], 400);
            }
            erp2_json(['ok' => true] + Erp2Stock::levelsByWarehouse($productId));
        }

        /** Create or update a product. */
        case 'save': {
            $id = (int)($_POST['id'] ?? 0);
            if (!erp2_can($id > 0 ? 'erp2.products.edit' : 'erp2.products.create')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                erp2_json(['ok' => false, 'error' => 'ناوی کاڵا پێویستە'], 422);
            }

            // Empty barcode must store NULL, not ''. The column is UNIQUE, and
            // MySQL allows many NULLs but only one '' — so a second product
            // saved without a barcode would collide.
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            $barcode = $barcode === '' ? null : $barcode;

            // A type only counts inside its own group: «Capsule» of Analgesic
            // is not a type of Toothpaste, whatever the form posted.
            $categoryId = ((int)($_POST['category_id'] ?? 0)) ?: null;
            $typeId     = ((int)($_POST['type_id'] ?? 0)) ?: null;
            if ($typeId !== null && ($categoryId === null || !$db->fetch(
                "SELECT id FROM erp2_product_types WHERE id = :t AND category_id = :c",
                ['t' => $typeId, 'c' => $categoryId]
            ))) {
                $typeId = null;
            }

            $fields = [
                'name'            => $name,
                'name_ar'         => trim((string)($_POST['name_ar'] ?? '')) ?: null,
                'name_ku'         => trim((string)($_POST['name_ku'] ?? '')) ?: null,
                'barcode'         => $barcode,
                'category_id'     => $categoryId,
                'type_id'         => $typeId,
                'brand_id'        => ((int)($_POST['brand_id'] ?? 0)) ?: null,
                'unit_id'         => ((int)($_POST['unit_id'] ?? 0)) ?: null,
                'supplier_id'     => ((int)($_POST['supplier_id'] ?? 0)) ?: null,
                'purchase_price'  => (float)($_POST['purchase_price'] ?? 0),
                'sale_price'      => (float)($_POST['sale_price'] ?? 0),
                'min_sale_price'  => (float)($_POST['min_sale_price'] ?? 0),
                'wholesale_price' => (float)($_POST['wholesale_price'] ?? 0),
                'min_stock'       => (float)($_POST['min_stock'] ?? 0),
                'max_stock'       => (float)($_POST['max_stock'] ?? 0),
                'expiry_date'     => erp2_date($_POST['expiry_date'] ?? null),
                'batch_number'    => trim((string)($_POST['batch_number'] ?? '')) ?: null,
                'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
            ];

            // The alternate-unit table arrives as one JSON field, so the whole
            // set survives the form post intact.
            $unitRows = [];
            if (!empty($_POST['units_json'])) {
                $decoded = json_decode((string)$_POST['units_json'], true);
                if (is_array($decoded)) {
                    $unitRows = $decoded;
                }
            }
            $baseUnitId = $fields['unit_id'];

            try {
                $db->beginTransaction();

                if ($id > 0) {
                    $old = $db->fetch("SELECT * FROM erp2_products WHERE id = :id", ['id' => $id]);
                    if (!$old) {
                        $db->rollback();
                        erp2_json(['ok' => false, 'error' => 'کاڵا نەدۆزرایەوە'], 404);
                    }
                    $fields['updated_at'] = date('Y-m-d H:i:s');
                    $fields['updated_by'] = $_SESSION['user_id'] ?? null;
                    // Moved to another group: it joins the end of that list.
                    if ((int)$old['category_id'] !== (int)$categoryId) {
                        $fields['sort_order'] = erp2_next_sort($db, $categoryId);
                    }
                    $db->update('erp2_products', $fields, 'id = ?', [$id]);

                    // Units are saved inside the SAME transaction: a product
                    // whose unit table failed to write would sell at the wrong
                    // conversion until someone noticed.
                    Erp2ProductUnits::save($id, $unitRows, $baseUnitId);

                    $db->commit();

                    Erp2Audit::logChange('edit_product', 'PRODUCT', $id, $old['product_code'], $old, $fields);
                    erp2_json(['ok' => true, 'id' => $id]);
                }

                $fields['product_code'] = trim((string)($_POST['product_code'] ?? '')) ?: Erp2Sequence::next('PRODUCT');
                $fields['created_by']   = $_SESSION['user_id'] ?? null;
                $fields['sort_order']   = erp2_next_sort($db, $categoryId);
                $newId = (int)$db->insert('erp2_products', $fields);

                Erp2ProductUnits::save($newId, $unitRows, $baseUnitId);

                $db->commit();

                Erp2Audit::log('create_product', 'PRODUCT', $newId, $fields['product_code'], null, $fields);
                erp2_json(['ok' => true, 'id' => $newId, 'product_code' => $fields['product_code']]);

            } catch (RuntimeException $e) {
                if ($db->inTransaction()) { $db->rollback(); }
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 422);
            } catch (PDOException $e) {
                if ($db->inTransaction()) { $db->rollback(); }
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    erp2_json(['ok' => false, 'error' => 'ئەم کۆد یان بارکۆدە پێشتر بەکارهاتووە'], 409);
                }
                throw $e;
            }
        }

        /** Soft delete: deactivate rather than remove, so history survives. */
        case 'toggle_active': {
            if (!erp2_can('erp2.products.delete')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            $row = $db->fetch("SELECT id, product_code, is_active FROM erp2_products WHERE id = :id", ['id' => $id]);
            if (!$row) {
                erp2_json(['ok' => false, 'error' => 'کاڵا نەدۆزرایەوە'], 404);
            }
            $new = ((int)$row['is_active'] === 1) ? 0 : 1;
            $db->update('erp2_products', ['is_active' => $new], 'id = ?', [$id]);
            Erp2Audit::log('toggle_product', 'PRODUCT', $id, $row['product_code'],
                ['is_active' => $row['is_active']], ['is_active' => $new]);
            erp2_json(['ok' => true, 'is_active' => $new]);
        }

        /** Change only a product's type, straight from the list. 0 clears it. */
        case 'set_type': {
            if (!erp2_can('erp2.products.edit')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id     = (int)($_POST['id'] ?? 0);
            $typeId = ((int)($_POST['type_id'] ?? 0)) ?: null;
            $row = $db->fetch("SELECT id, product_code, category_id, type_id FROM erp2_products WHERE id = :id", ['id' => $id]);
            if (!$row) {
                erp2_json(['ok' => false, 'error' => 'کاڵا نەدۆزرایەوە'], 404);
            }
            // Same rule as save: a type only counts inside the product's own group.
            $type = null;
            if ($typeId !== null) {
                $type = $db->fetch(
                    "SELECT id, name FROM erp2_product_types WHERE id = :t AND category_id = :c",
                    ['t' => $typeId, 'c' => (int)$row['category_id']]
                );
                if (!$type) {
                    erp2_json(['ok' => false, 'error' => 'ئەم جۆرە سەر بە گرووپی ئەم کاڵایە نییە'], 422);
                }
            }
            $db->update('erp2_products', ['type_id' => $typeId], 'id = ?', [$id]);
            Erp2Audit::log('set_product_type', 'PRODUCT', $id, $row['product_code'],
                ['type_id' => $row['type_id']], ['type_id' => $typeId]);
            erp2_json(['ok' => true, 'type_id' => $typeId, 'type_name' => $type['name'] ?? null]);
        }

        /**
         * One product's full record for the edit dialog, including its unit
         * table. Fetched on demand rather than shipped with every list row —
         * the units are only needed when a product is actually opened.
         */
        case 'detail': {
            $id = (int)($_GET['id'] ?? 0);
            $row = $db->fetch(
                "SELECT p.*, c.name AS category_name, t.name AS type_name, b.name AS brand_name, u.code AS unit_code
                 FROM erp2_products p
                 LEFT JOIN erp2_categories c ON c.id = p.category_id
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 LEFT JOIN erp2_brands b ON b.id = p.brand_id
                 LEFT JOIN erp2_units u ON u.id = p.unit_id
                 WHERE p.id = :id",
                ['id' => $id]
            );
            if (!$row) {
                erp2_json(['ok' => false, 'error' => 'کاڵا نەدۆزرایەوە'], 404);
            }
            if (!erp2_can('erp2.profit.view')) {
                unset($row['purchase_price']);
            }
            // Alternates only — the base unit is already on the product and
            // the dialog renders it separately as a fixed row.
            $alt = $db->fetchAll(
                "SELECT pu.unit_id, pu.factor, pu.is_default, pu.barcode, pu.sale_price,
                        u.name AS unit_name, u.code AS unit_code
                 FROM erp2_product_units pu
                 LEFT JOIN erp2_units u ON u.id = pu.unit_id
                 WHERE pu.product_id = :id AND pu.is_active = 1
                 ORDER BY pu.factor",
                ['id' => $id]
            );
            erp2_json(['ok' => true, 'product' => $row, 'units' => $alt]);
        }

        /** Every sellable unit of a product, for the sales/purchase grids. */
        case 'units': {
            $id = (int)($_GET['product_id'] ?? 0);
            if ($id <= 0) {
                erp2_json(['ok' => false, 'error' => 'product_id required'], 400);
            }
            erp2_json(['ok' => true, 'units' => Erp2ProductUnits::forProduct($id)]);
        }

        /** Brand list for the picker. */
        case 'brands': {
            erp2_json(['ok' => true, 'results' => $db->fetchAll(
                "SELECT id, name FROM erp2_brands WHERE is_active = 1 ORDER BY name"
            )]);
        }

        /** Create a brand inline, mirroring quick_category. */
        case 'quick_brand': {
            if (!erp2_can('erp2.products.create')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                erp2_json(['ok' => false, 'error' => 'ناو پێویستە'], 422);
            }
            $existing = $db->fetch("SELECT id FROM erp2_brands WHERE name = :n LIMIT 1", ['n' => $name]);
            if ($existing) {
                erp2_json(['ok' => true, 'id' => (int)$existing['id'], 'name' => $name, 'existed' => true]);
            }
            $newId = (int)$db->insert('erp2_brands', [
                'name'      => $name,
                'name_ar'   => trim((string)($_POST['name_ar'] ?? '')) ?: null,
                'is_active' => 1,
            ]);
            erp2_json(['ok' => true, 'id' => $newId, 'name' => $name]);
        }

        /** Create a category inline, so adding a product never leaves the form. */
        case 'quick_category': {
            if (!erp2_can('erp2.products.create')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                erp2_json(['ok' => false, 'error' => 'ناو پێویستە'], 422);
            }
            $existing = $db->fetch("SELECT id FROM erp2_categories WHERE name = :n LIMIT 1", ['n' => $name]);
            if ($existing) {
                erp2_json(['ok' => true, 'id' => (int)$existing['id'], 'name' => $name, 'existed' => true]);
            }
            $newId = (int)$db->insert('erp2_categories', [
                'name'       => $name,
                'name_ar'    => trim((string)($_POST['name_ar'] ?? '')) ?: null,
                'sort_order' => (int)$db->fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM erp2_categories")['n'],
                'is_active'  => 1,
            ]);
            erp2_json(['ok' => true, 'id' => $newId, 'name' => $name]);
        }

        /** A new type inside one group (a form like «Drops», or a brand). */
        case 'quick_type': {
            if (!erp2_can('erp2.products.create')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || $categoryId <= 0) {
                erp2_json(['ok' => false, 'error' => 'گرووپ و ناو پێویستن'], 422);
            }
            if (!$db->fetch("SELECT id FROM erp2_categories WHERE id = :id", ['id' => $categoryId])) {
                erp2_json(['ok' => false, 'error' => 'گرووپ نەدۆزرایەوە'], 404);
            }
            $existing = $db->fetch(
                "SELECT id, name FROM erp2_product_types WHERE category_id = :c AND name = :n LIMIT 1",
                ['c' => $categoryId, 'n' => $name]
            );
            if ($existing) {
                $db->update('erp2_product_types', ['is_active' => 1], 'id = ?', [(int)$existing['id']]);
                erp2_json(['ok' => true, 'id' => (int)$existing['id'], 'name' => $existing['name'], 'existed' => true]);
            }
            $newId = (int)$db->insert('erp2_product_types', [
                'category_id' => $categoryId,
                'name'        => $name,
                'sort_order'  => (int)$db->fetch(
                    "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM erp2_product_types WHERE category_id = :c",
                    ['c' => $categoryId])['n'],
                'is_active'   => 1,
            ]);
            erp2_json(['ok' => true, 'id' => $newId, 'name' => $name]);
        }

        default:
            erp2_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    // Log the detail, return a generic message. The dental ajax_search.php
    // echoes getMessage() straight to the client, which leaks schema and file
    // paths on error; that mistake is not repeated here.
    error_log('[erp/api/products] ' . $e->getMessage());
    erp2_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}

/**
 * Product type-ahead.
 *
 * Order matters and is a performance decision, not a preference:
 *   1. exact barcode / product_code  — unique index, one row, instant
 *   2. name prefix                   — range scan on idx_erp2_prod_name
 *   3. contains                      — only if 1 and 2 found nothing. This one
 *      cannot use an index, so it is the last resort rather than the default.
 */
function erp2_product_search(Database $db, string $q, int $warehouseId, int $limit): array
{
    $select = "SELECT p.id, p.product_code, p.barcode, p.name, p.name_ar,
                      p.sale_price, p.min_sale_price, p.wholesale_price, p.purchase_price,
                      p.unit_id, u.code AS unit_code,
                      p.category_id, c.name AS category_name, p.type_id, t.name AS type_name,
                      p.brand_id, br.name AS brand_name,
                      COALESCE(s.quantity, 0) AS stock,
                      COALESCE(s.average_cost, 0) AS avg_cost
               FROM erp2_products p
               LEFT JOIN erp2_units u ON u.id = p.unit_id
               LEFT JOIN erp2_categories c ON c.id = p.category_id
               LEFT JOIN erp2_product_types t ON t.id = p.type_id
               LEFT JOIN erp2_brands br ON br.id = p.brand_id
               LEFT JOIN erp2_warehouse_product_stock s
                      ON s.product_id = p.id AND s.warehouse_id = :w";

    $exact = $db->fetchAll(
        "{$select} WHERE p.is_active = 1 AND (p.barcode = :q OR p.product_code = :q2) LIMIT {$limit}",
        ['w' => $warehouseId, 'q' => $q, 'q2' => $q]
    );
    if ($exact) {
        return $exact;
    }

    $prefix = $db->fetchAll(
        "{$select}
         WHERE p.is_active = 1
           AND (p.name LIKE :a OR p.name_ar LIKE :b OR p.name_ku LIKE :c OR p.product_code LIKE :d)
         ORDER BY p.name
         LIMIT {$limit}",
        ['w' => $warehouseId, 'a' => $q . '%', 'b' => $q . '%', 'c' => $q . '%', 'd' => $q . '%']
    );
    if ($prefix) {
        return $prefix;
    }

    return $db->fetchAll(
        "{$select}
         WHERE p.is_active = 1
           AND (p.name LIKE :a OR p.name_ar LIKE :b OR p.name_ku LIKE :c)
         ORDER BY p.name
         LIMIT {$limit}",
        ['w' => $warehouseId, 'a' => '%' . $q . '%', 'b' => '%' . $q . '%', 'c' => '%' . $q . '%']
    );
}

/** The next place at the end of a group's list (the sheet's order). */
function erp2_next_sort(Database $db, ?int $categoryId): int
{
    return (int)$db->fetch(
        "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM erp2_products WHERE category_id <=> :c",
        ['c' => $categoryId]
    )['n'];
}

/** Every active product in the sheet's order: group, then place in it. */
function erp2_product_browse(Database $db, int $warehouseId, int $limit): array
{
    return $db->fetchAll(
        "SELECT p.id, p.product_code, p.barcode, p.name, p.name_ar,
                p.sale_price, p.min_sale_price, p.wholesale_price, p.purchase_price,
                p.unit_id, u.code AS unit_code,
                p.category_id, c.name AS category_name, p.type_id, t.name AS type_name,
                p.brand_id, br.name AS brand_name,
                COALESCE(s.quantity, 0) AS stock,
                COALESCE(s.average_cost, 0) AS avg_cost
         FROM erp2_products p
         LEFT JOIN erp2_units u ON u.id = p.unit_id
         LEFT JOIN erp2_categories c ON c.id = p.category_id
         LEFT JOIN erp2_product_types t ON t.id = p.type_id
         LEFT JOIN erp2_brands br ON br.id = p.brand_id
         LEFT JOIN erp2_warehouse_product_stock s ON s.product_id = p.id AND s.warehouse_id = :w
         WHERE p.is_active = 1
         ORDER BY COALESCE(c.sort_order, 999), p.sort_order, p.name
         LIMIT {$limit}",
        ['w' => $warehouseId]
    );
}