<?php
/**
 * ERP payments API — receive from customers, pay suppliers.
 *
 * Thin dispatch onto Erp2Payment, which owns the transaction covering the
 * receipt, the ledger entry and the invoice allocation.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';
require_once __DIR__ . '/../../classes/Erp2Payment.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp2_json($p, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'recent';
$kind   = $_GET['kind']   ?? $_POST['kind']   ?? 'customer';
if (!in_array($kind, ['customer', 'supplier'], true)) {
    erp2_json(['ok' => false, 'error' => 'bad kind'], 400);
}
$isCustomer = $kind === 'customer';
$perm = $isCustomer ? 'erp2.payments.customer' : 'erp2.payments.supplier';

$writeActions = ['save', 'cancel'];
if (in_array($action, $writeActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erp2_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
    if (!erp2_can($perm)) {
        erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
} else {
    // Reading the payment history is part of seeing the party's account.
    $viewPerm = $isCustomer ? 'erp2.customers.view' : 'erp2.suppliers.view';
    if (!erp2_can($perm) && !erp2_can($viewPerm)) {
        erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

function erp2_payload(): array
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

        case 'save': {
            $data = erp2_payload();
            try {
                erp2_json(['ok' => true] + Erp2Payment::create($kind, $data));
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        case 'cancel': {
            if (!erp2_can('erp2.invoice.cancel')) {
                erp2_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            try {
                Erp2Payment::cancel($kind, (int)($_POST['id'] ?? 0), trim((string)($_POST['reason'] ?? '')) ?: null);
                erp2_json(['ok' => true]);
            } catch (RuntimeException $e) {
                erp2_json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        /** Invoices still owing, so the screen can show what will be settled. */
        case 'open_invoices': {
            $partyId = (int)($_GET['party_id'] ?? 0);
            if ($partyId <= 0) {
                erp2_json(['ok' => false, 'error' => 'party_id required'], 400);
            }
            $rows = Erp2Payment::openInvoices($kind, $partyId);
            $total = 0.0;
            foreach ($rows as $r) { $total += (float)$r['outstanding']; }
            $balance = $isCustomer
                ? Erp2Ledger::customerBalance($partyId)
                : Erp2Ledger::supplierBalance($partyId);
            erp2_json([
                'ok' => true, 'results' => $rows,
                'outstanding_total' => round($total, 2),
                'balance' => $balance,
            ]);
        }

        case 'recent': {
            $limit   = max(1, min(50, (int)($_GET['limit'] ?? 20)));
            $partyId = (int)($_GET['party_id'] ?? 0);
            $table   = $isCustomer ? 'erp2_customer_payments' : 'erp2_supplier_payments';
            $ptable  = $isCustomer ? 'erp2_customers' : 'erp2_suppliers';
            $fk      = $isCustomer ? 'customer_id' : 'supplier_id';

            $where  = '1=1';
            $params = [];
            if ($partyId > 0) {
                $where = "p.{$fk} = :pid";
                $params['pid'] = $partyId;
            }

            $rows = $db->fetchAll(
                "SELECT p.id, p.payment_number, p.payment_date, p.amount, p.payment_method,
                        p.reference, p.status, x.name AS party_name,
                        COALESCE(a.alloc, 0) AS allocated
                 FROM {$table} p
                 JOIN {$ptable} x ON x.id = p.{$fk}
                 LEFT JOIN (
                     SELECT payment_id, SUM(amount) AS alloc
                     FROM erp2_invoice_payments WHERE payment_kind = :k GROUP BY payment_id
                 ) a ON a.payment_id = p.id
                 WHERE {$where}
                 ORDER BY p.id DESC
                 LIMIT {$limit}",
                $params + ['k' => $kind]
            );
            foreach ($rows as &$r) {
                $r['method_label'] = Erp2Payment::methodLabel($r['payment_method']);
            }
            unset($r);
            erp2_json(['ok' => true, 'results' => $rows]);
        }

        default:
            erp2_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    error_log('[erp/api/payments] ' . $e->getMessage());
    erp2_json(['ok' => false, 'error' => 'هەڵەیەکی ناوخۆیی ڕوویدا'], 500);
}
