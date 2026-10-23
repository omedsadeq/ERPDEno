<?php
/**
 * ERP customers + suppliers API — search, quick-create, balance, statement.
 *
 * One endpoint for both because the two are structurally identical; `kind`
 * (customer|supplier) picks the table and the ledger sense. Keeping them
 * together means the sales and purchase screens share one search widget.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/ErpLedger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'search';
$kind   = $_GET['kind']   ?? $_POST['kind']   ?? 'customer';
if (!in_array($kind, ['customer', 'supplier'], true)) {
    erp_json(['ok' => false, 'error' => 'bad kind'], 400);
}

$isCustomer = $kind === 'customer';
$table      = $isCustomer ? 'erp_customers' : 'erp_suppliers';
$codeCol    = $isCustomer ? 'customer_code' : 'supplier_code';
$viewPerm   = $isCustomer ? 'erp.customers.view' : 'erp.suppliers.view';
$seqType    = $isCustomer ? 'CUSTOMER' : 'SUPPLIER';

$writeActions = ['save'];
if (in_array($action, $writeActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erp_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
    // Creating a party is part of making an invoice, so the create right
    // follows the document right rather than needing its own permission.
    $mayWrite = $isCustomer
        ? (erp_can('erp.sales.create') || erp_can('erp.customers.view'))
        : (erp_can('erp.purchase.create') || erp_can('erp.suppliers.view'));
    if (!$mayWrite) {
        erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
} elseif (!erp_can($viewPerm)) {
    erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$db = Database::getInstance();

try {
    switch ($action) {

        /** Type-ahead for the invoice header. Max 20 rows, always LIMITed. */
        case 'search': {
            $q     = trim((string)($_GET['q'] ?? ''));
            $limit = max(1, min(20, (int)($_GET['limit'] ?? 15)));

            // No query: return the handful most recently used, so the sales
            // screen opens with the walk-in customer ready rather than blank.
            if ($q === '') {
                $rows = $db->fetchAll(
                    "SELECT id, {$codeCol} AS code, name, phone
                     FROM {$table} WHERE is_active = 1
                     ORDER BY id LIMIT {$limit}"
                );
                erp_json(['ok' => true, 'results' => $rows]);
            }

            $rows = $db->fetchAll(
                "SELECT id, {$codeCol} AS code, name, phone
                 FROM {$table}
                 WHERE is_active = 1
                   AND (name LIKE :pre OR phone LIKE :pre2 OR {$codeCol} = :exact)
                 ORDER BY name
                 LIMIT {$limit}",
                ['pre' => $q . '%', 'pre2' => $q . '%', 'exact' => $q]
            );
            if (!$rows) {
                $rows = $db->fetchAll(
                    "SELECT id, {$codeCol} AS code, name, phone
                     FROM {$table}
                     WHERE is_active = 1 AND name LIKE :any
                     ORDER BY name LIMIT {$limit}",
                    ['any' => '%' . $q . '%']
                );
            }
            erp_json(['ok' => true, 'results' => $rows]);
        }

        /** Paged list for the management screen, with live balances. */
        case 'list': {
            $q       = trim((string)($_GET['q'] ?? ''));
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 25;
            $offset  = ($page - 1) * $perPage;

            $where  = ['1=1'];
            $params = [];
            if ($q !== '') {
                $where[] = "(name LIKE :pre OR phone LIKE :pre2 OR {$codeCol} = :exact)";
                $params['pre']   = $q . '%';
                $params['pre2']  = $q . '%';
                $params['exact'] = $q;
            }
            $whereSql = implode(' AND ', $where);

            $rows = $db->fetchAll(
                "SELECT id, {$codeCol} AS code, name, phone, address,
                        opening_balance, credit_limit, is_active
                 FROM {$table}
                 WHERE {$whereSql}
                 ORDER BY name
                 LIMIT {$perPage} OFFSET {$offset}",
                $params
            );

            // Balances in ONE grouped query over the ledger, not per row.
            // A call per row would be an N+1 that gets slower as the ledger grows.
            if ($rows) {
                $ids = array_column($rows, 'id');
                $in  = implode(',', array_map('intval', $ids));
                $lt  = $isCustomer ? 'erp_customer_ledger' : 'erp_supplier_ledger';
                $fk  = $isCustomer ? 'customer_id' : 'supplier_id';
                $expr = $isCustomer
                    ? 'COALESCE(SUM(debit) - SUM(credit), 0)'
                    : 'COALESCE(SUM(credit) - SUM(debit), 0)';
                $bal = $db->fetchAll(
                    "SELECT {$fk} AS pid, {$expr} AS bal FROM {$lt}
                     WHERE {$fk} IN ({$in}) GROUP BY {$fk}"
                );
                $map = [];
                foreach ($bal as $b) { $map[(int)$b['pid']] = (float)$b['bal']; }
                foreach ($rows as &$r) {
                    $r['balance'] = ($map[(int)$r['id']] ?? 0) + (float)$r['opening_balance'];
                }
                unset($r);
            }

            $total = (int)($db->fetch("SELECT COUNT(*) c FROM {$table} WHERE {$whereSql}", $params)['c'] ?? 0);
            erp_json([
                'ok' => true, 'results' => $rows, 'page' => $page,
                'total' => $total, 'totalPages' => (int)ceil($total / $perPage),
            ]);
        }

        /**
         * Staff who can be sold to (doctors and the like), with whether they
         * already have a customer record. Selling materials to a doctor is a
         * normal sale, so they need to exist as a customer — but typing them
         * in by hand is how two spellings of the same doctor end up carrying
         * half the debt each.
         */
        case 'staff': {
            if (!$isCustomer) {
                erp_json(['ok' => false, 'error' => 'customers only'], 400);
            }
            $rows = $db->fetchAll(
                "SELECT u.id AS user_id, u.full_name, u.role,
                        c.id AS customer_id, c.customer_code
                 FROM users u
                 LEFT JOIN erp_customers c ON c.user_id = u.id
                 WHERE u.is_active = 1 AND u.role IN ('doctor', 'xray_department')
                 ORDER BY u.full_name"
            );
            erp_json(['ok' => true, 'results' => $rows]);
        }

        /**
         * Create (or return) the ERP customer for a staff member. Idempotent:
         * pressing it twice hands back the same customer rather than making a
         * duplicate, which the unique key on user_id also enforces.
         */
        case 'link_staff': {
            if (!$isCustomer) {
                erp_json(['ok' => false, 'error' => 'customers only'], 400);
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                erp_json(['ok' => false, 'error' => 'POST required'], 405);
            }
            Csrf::require();
            if (!erp_can('erp.sales.create') && !erp_can('erp.customers.view')) {
                erp_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $userId = (int)($_POST['user_id'] ?? 0);
            $user = $db->fetch(
                "SELECT id, full_name, phone FROM users WHERE id = :id AND is_active = 1",
                ['id' => $userId]
            );
            if (!$user) {
                erp_json(['ok' => false, 'error' => 'بەکارهێنەر نەدۆزرایەوە'], 404);
            }

            $existing = $db->fetch("SELECT id, name FROM erp_customers WHERE user_id = :u", ['u' => $userId]);
            if ($existing) {
                erp_json(['ok' => true, 'id' => (int)$existing['id'], 'name' => $existing['name'], 'existed' => true]);
            }

            try {
                $db->beginTransaction();
                $code = ErpSequence::next('CUSTOMER');
                $newId = (int)$db->insert('erp_customers', [
                    'customer_code' => $code,
                    'name'          => $user['full_name'],
                    'phone'         => $user['phone'] ?? null,
                    'user_id'       => $userId,
                    'is_active'     => 1,
                    'created_by'    => $_SESSION['user_id'] ?? null,
                ]);
                $db->commit();
                ErpAudit::log('link_staff_customer', 'CUSTOMER', $newId, $code, null,
                    ['user_id' => $userId, 'name' => $user['full_name']]);
                erp_json(['ok' => true, 'id' => $newId, 'name' => $user['full_name'], 'code' => $code]);
            } catch (PDOException $e) {
                if ($db->inTransaction()) { $db->rollback(); }
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    // Someone linked the same staff member a moment ago.
                    $row = $db->fetch("SELECT id, name FROM erp_customers WHERE user_id = :u", ['u' => $userId]);
                    if ($row) {
                        erp_json(['ok' => true, 'id' => (int)$row['id'], 'name' => $row['name'], 'existed' => true]);
                    }
                }
                throw $e;
            }
        }

        case 'balance': {
            $id = (int)($_GET['id'] ?? 0);
            $bal = $isCustomer ? ErpLedger::customerBalance($id) : ErpLedger::supplierBalance($id);
            erp_json(['ok' => true, 'balance' => $bal]);
        }

        case 'statement': {
            $id   = (int)($_GET['id'] ?? 0);
            $from = erp_date($_GET['from'] ?? null);
            $to   = erp_date($_GET['to'] ?? null);
            $data = $isCustomer
                ? ErpLedger::customerStatement($id, $from, $to)
                : ErpLedger::supplierStatement($id, $from, $to);
            erp_json(['ok' => true] + $data);
        }

        case 'save': {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                erp_json(['ok' => false, 'error' => 'ناو پێویستە'], 422);
            }

            $fields = [
                'name'            => $name,
                'phone'           => trim((string)($_POST['phone'] ?? '')) ?: null,
                'address'         => trim((string)($_POST['address'] ?? '')) ?: null,
                'credit_limit'    => (float)($_POST['credit_limit'] ?? 0),
                'notes'           => trim((string)($_POST['notes'] ?? '')) ?: null,
                'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if (!$isCustomer) {
                $fields['contact_person'] = trim((string)($_POST['contact_person'] ?? '')) ?: null;
            }

            try {
                $db->beginTransaction();

                if ($id > 0) {
                    $old = $db->fetch("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
                    if (!$old) {
                        $db->rollback();
                        erp_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
                    }
                    // opening_balance is deliberately NOT editable after
                    // creation: it is already reflected in every statement, so
                    // changing it would silently restate history.
                    $db->update($table, $fields, 'id = ?', [$id]);
                    $db->commit();
                    ErpAudit::logChange('edit_' . $kind, strtoupper($kind), $id, $old[$codeCol], $old, $fields);
                    erp_json(['ok' => true, 'id' => $id, 'name' => $name]);
                }

                $fields['opening_balance'] = (float)($_POST['opening_balance'] ?? 0);
                $fields[$codeCol]          = trim((string)($_POST['code'] ?? '')) ?: ErpSequence::next($seqType);
                $fields['created_by']      = $_SESSION['user_id'] ?? null;

                $newId = (int)$db->insert($table, $fields);
                $db->commit();

                ErpAudit::log('create_' . $kind, strtoupper($kind), $newId, $fields[$codeCol], null, $fields);
                erp_json(['ok' => true, 'id' => $newId, 'name' => $name, 'code' => $fields[$codeCol]]);

            } catch (PDOException $e) {
                if ($db->inTransaction()) { $db->rollback(); }
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    erp_json(['ok' => false, 'error' => 'ئەم کۆدە پێشتر بەکارهاتووە'], 409);
                }
                throw $e;
            }
        }

        default:
            erp_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    error_log('[erp/api/parties] ' . $e->getMessage());
    erp_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}
