<?php
/**
 * دەرمان API — the bridge between the doctor's case screen and the بەشی
 * فرۆشتن till.
 *
 * Flow: the doctor searches medicines (only what is actually IN STOCK in the
 * main warehouse — prescribing something the counter cannot hand over is the
 * whole problem this replaces), builds lines with a dose each, and sends the
 * prescription. The counter sees it in a queue, clicks it, and the till opens
 * pre-filled.
 *
 * Same {"ok":true,...} / {"ok":false,"error":"..."} envelope as the other
 * erp2 api files.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../classes/Csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp2_presc_json($payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$db     = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$actor  = (int)($_SESSION['user_id'] ?? 0);

$writeActions = ['save', 'cancel', 'mark_sold', 'outside', 'reopen'];
if (in_array($action, $writeActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        erp2_presc_json(['ok' => false, 'error' => 'POST required'], 405);
    }
    Csrf::require();
}

try {
    switch ($action) {

        /**
         * Medicine type-ahead for the doctor. ONLY rows with stock left in the
         * main warehouse: a prescription the counter cannot fill is worse than
         * no prescription.
         */
        case 'search': {
            if (!erp2_can('erp2.prescriptions.create') && !erp2_can('erp2.prescriptions.view')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $q     = trim((string)($_GET['q'] ?? ''));
            $wh    = erp2_default_warehouse_id();
            $limit = max(1, min(20, (int)($_GET['limit'] ?? 15)));
            if ($q === '' || $wh <= 0) {
                erp2_presc_json(['ok' => true, 'results' => [], 'warehouse_id' => $wh]);
            }

            $rows = $db->fetchAll(
                "SELECT p.id, p.product_code, p.name, p.name_ku, p.sale_price,
                        u.code AS unit_code, s.quantity AS stock,
                        c.name AS category_name, t.name AS type_name
                 FROM erp2_products p
                 JOIN erp2_warehouse_product_stock s
                      ON s.product_id = p.id AND s.warehouse_id = :w
                 LEFT JOIN erp2_units u ON u.id = p.unit_id
                 LEFT JOIN erp2_categories c ON c.id = p.category_id
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 WHERE p.is_active = 1
                   AND s.quantity > 0
                   AND (p.name LIKE :a OR p.name_ku LIKE :b OR p.name_ar LIKE :c
                        OR p.product_code LIKE :d OR p.barcode = :e)
                 ORDER BY p.name
                 LIMIT {$limit}",
                [
                    'w' => $wh,
                    'a' => '%' . $q . '%', 'b' => '%' . $q . '%', 'c' => '%' . $q . '%',
                    'd' => $q . '%',       'e' => $q,
                ]
            );
            erp2_presc_json(['ok' => true, 'results' => $rows, 'warehouse_id' => $wh]);
        }

        /**
         * The whole pharmacy list laid out like the clinic's paper sheet: each
         * group with its types (the header circles) and its items in the
         * sheet's order, with what the main warehouse holds of each. Every
         * active item is listed — on paper the doctor can tick anything; the
         * stock figure is there so the screen can say what the counter lacks.
         */
        case 'sheet': {
            if (!erp2_can('erp2.prescriptions.create')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $wh = erp2_default_warehouse_id();
            $groups = [];
            foreach ($db->fetchAll("SELECT id, name FROM erp2_categories WHERE is_active = 1 ORDER BY sort_order, name") as $g) {
                $groups[(int)$g['id']] = ['id' => (int)$g['id'], 'name' => $g['name'], 'types' => [], 'items' => []];
            }
            foreach ($db->fetchAll("SELECT id, category_id, name FROM erp2_product_types WHERE is_active = 1 ORDER BY sort_order, name") as $t) {
                if (isset($groups[(int)$t['category_id']])) {
                    $groups[(int)$t['category_id']]['types'][] = ['id' => (int)$t['id'], 'name' => $t['name']];
                }
            }
            // The admin's standing doses (erp2/dosages.php), default first: the
            // sheet fills a ticked line with the first and offers the rest.
            $doses = [];
            if ($db->tableExists('erp2_product_dosages')) {
                foreach ($db->fetchAll(
                    "SELECT product_id, label, dose, instructions
                     FROM erp2_product_dosages ORDER BY is_default DESC, sort_order, id"
                ) as $d) {
                    $doses[(int)$d['product_id']][] = [
                        'label' => $d['label'],
                        'text'  => implode(' — ', array_filter([$d['dose'], $d['instructions']], fn($x) => $x !== null && $x !== '')),
                    ];
                }
            }
            $loose = ['id' => 0, 'name' => 'بێ گرووپ', 'types' => [], 'items' => []];
            foreach ($db->fetchAll(
                "SELECT p.id, p.name, p.name_ku, p.category_id, p.type_id, t.name AS type_name,
                        u.name AS unit_name, COALESCE(s.quantity, 0) AS stock
                 FROM erp2_products p
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 LEFT JOIN erp2_units u ON u.id = p.unit_id
                 LEFT JOIN erp2_warehouse_product_stock s ON s.product_id = p.id AND s.warehouse_id = :w
                 WHERE p.is_active = 1
                 ORDER BY p.sort_order, p.name",
                ['w' => $wh]
            ) as $p) {
                $item = [
                    'id' => (int)$p['id'], 'name' => $p['name'], 'name_ku' => $p['name_ku'],
                    'type_id' => $p['type_id'] !== null ? (int)$p['type_id'] : null, 'type_name' => $p['type_name'],
                    'unit_name' => $p['unit_name'], 'stock' => (float)$p['stock'],
                    'doses' => $doses[(int)$p['id']] ?? [],
                ];
                $gid = (int)$p['category_id'];
                if (isset($groups[$gid])) { $groups[$gid]['items'][] = $item; } else { $loose['items'][] = $item; }
            }
            $out = array_values(array_filter($groups, fn($g) => $g['items'] || $g['types']));
            if ($loose['items']) { $out[] = $loose; }
            erp2_presc_json(['ok' => true, 'groups' => $out, 'warehouse_id' => $wh]);
        }

        /** Doctor sends a prescription for one case. */
        case 'save': {
            if (!erp2_can('erp2.prescriptions.create')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $caseId    = (int)($_POST['case_id'] ?? 0);
            $notes     = trim((string)($_POST['notes'] ?? '')) ?: null;
            $itemsRaw  = json_decode((string)($_POST['items'] ?? '[]'), true);

            if ($patientId <= 0 || !is_array($itemsRaw) || !$itemsRaw) {
                erp2_presc_json(['ok' => false, 'error' => 'هیچ دەرمانێک هەڵنەبژێردراوە'], 422);
            }

            // The case must really belong to this patient — the ids arrive from
            // a form and a mismatched pair would file the prescription against
            // the wrong chart.
            if ($caseId > 0) {
                $owns = $db->fetch(
                    "SELECT c.id FROM cases c
                     JOIN patient_folders pf ON pf.id = c.folder_id
                     WHERE c.id = :cid AND pf.patient_id = :pid",
                    ['cid' => $caseId, 'pid' => $patientId]
                );
                if (!$owns) {
                    erp2_presc_json(['ok' => false, 'error' => 'کەیس لەگەڵ نەخۆش ناگونجێت'], 422);
                }
            }
            // A restricted doctor may only prescribe for a patient they hold.
            if (class_exists('AccessGuard') && AccessGuard::isRestrictedDoctor()
                && !AccessGuard::doctorCanViewPatient($patientId)) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $patient = $db->fetch('SELECT id, full_name, phone FROM patients WHERE id = :id', ['id' => $patientId]);
            if (!$patient) {
                erp2_presc_json(['ok' => false, 'error' => 'نەخۆش نەدۆزرایەوە'], 404);
            }

            $wh = erp2_default_warehouse_id();
            $clean = [];
            foreach ($itemsRaw as $row) {
                $pid = (int)($row['product_id'] ?? 0);
                $qty = round((float)($row['quantity'] ?? 0), 3);
                if ($pid <= 0 || $qty <= 0) {
                    continue;
                }
                $prod = $db->fetch(
                    "SELECT p.id, p.name, COALESCE(s.quantity,0) AS stock
                     FROM erp2_products p
                     LEFT JOIN erp2_warehouse_product_stock s
                            ON s.product_id = p.id AND s.warehouse_id = :w
                     WHERE p.id = :id AND p.is_active = 1",
                    ['id' => $pid, 'w' => $wh]
                );
                if (!$prod) {
                    continue;
                }
                $clean[] = [
                    'product_id'   => $pid,
                    'product_name' => $prod['name'],
                    'quantity'     => $qty,
                    'dosage'       => trim((string)($row['dosage'] ?? '')) ?: null,
                    'notes'        => trim((string)($row['notes'] ?? '')) ?: null,
                ];
            }
            if (!$clean) {
                erp2_presc_json(['ok' => false, 'error' => 'هیچ دەرمانێکی دروست نەدۆزرایەوە'], 422);
            }

            // The prescription is the prescribing doctor's — it prints on their
            // sheet. Written by someone who is not a doctor (an admin, the
            // reception, for the case): the case's own doctor.
            $doctorId = $actor;
            if (($_SESSION['role'] ?? '') !== 'doctor' && $caseId > 0) {
                $caseDoctor = $db->fetch(
                    "SELECT pf.doctor_id FROM cases c JOIN patient_folders pf ON pf.id = c.folder_id WHERE c.id = :id",
                    ['id' => $caseId]
                );
                if (!empty($caseDoctor['doctor_id'])) {
                    $doctorId = (int)$caseDoctor['doctor_id'];
                }
            }

            $db->beginTransaction();
            try {
                // Link (or create) the erp2 customer for this patient — the
                // table already carries patient_id for exactly this, and the
                // till needs a real customer row to bill against.
                $customer = $db->fetch(
                    'SELECT id FROM erp2_customers WHERE patient_id = :pid AND is_active = 1 LIMIT 1',
                    ['pid' => $patientId]
                );
                $customerId = $customer ? (int)$customer['id'] : (int)$db->insert('erp2_customers', [
                    'customer_code' => Erp2Sequence::next('CUSTOMER'),
                    'name'          => $patient['full_name'],
                    'phone'         => $patient['phone'] ?? null,
                    'patient_id'    => $patientId,
                    'created_by'    => $actor ?: null,
                ]);

                $prescId = (int)$db->insert('erp2_prescriptions', [
                    'patient_id'  => $patientId,
                    'case_id'     => $caseId ?: null,
                    'doctor_id'   => $doctorId ?: null,
                    'customer_id' => $customerId,
                    'status'      => 'pending',
                    'notes'       => $notes,
                    'created_by'  => $actor ?: null,
                ]);
                foreach ($clean as $it) {
                    $it['prescription_id'] = $prescId;
                    $db->insert('erp2_prescription_items', $it);
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }

            Erp2Audit::log('prescription_created', 'PRESCRIPTION', $prescId, (string)$prescId, null, [
                'patient_id' => $patientId, 'case_id' => $caseId, 'items' => count($clean),
            ]);
            erp2_presc_json(['ok' => true, 'id' => $prescId, 'items' => count($clean)]);
        }

        /** Queue for the counter: one row per pending prescription. */
        case 'list': {
            if (!erp2_can('erp2.prescriptions.view')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $status = $_GET['status'] ?? 'pending';
            if (!in_array($status, ['pending', 'sold', 'cancelled', 'outside', 'all'], true)) {
                $status = 'pending';
            }
            $where  = $status === 'all' ? '1=1' : 'pr.status = :st';
            $params = $status === 'all' ? [] : ['st' => $status];

            $rows = $db->fetchAll(
                "SELECT pr.id, pr.status, pr.created_at, pr.notes, pr.invoice_id,
                        pr.patient_id, pa.full_name AS patient_name, pa.phone AS patient_phone,
                        u.full_name AS doctor_name,
                        c.case_number,
                        COUNT(i.id)            AS item_count,
                        COALESCE(SUM(i.quantity), 0) AS total_qty
                 FROM erp2_prescriptions pr
                 JOIN patients pa ON pa.id = pr.patient_id
                 LEFT JOIN users u ON u.id = pr.doctor_id
                 LEFT JOIN cases c ON c.id = pr.case_id
                 LEFT JOIN erp2_prescription_items i ON i.prescription_id = pr.id
                 WHERE {$where}
                 GROUP BY pr.id
                 ORDER BY pr.created_at DESC
                 LIMIT 200",
                $params
            );
            erp2_presc_json(['ok' => true, 'results' => $rows]);
        }

        /** Every prescription written on one case — the doctor's own tab. */
        case 'case_list': {
            $caseId = (int)($_GET['case_id'] ?? 0);
            if ($caseId <= 0) {
                erp2_presc_json(['ok' => true, 'results' => []]);
            }
            $rows = $db->fetchAll(
                "SELECT pr.id, pr.status, pr.created_at, pr.invoice_id, pr.created_by, pr.doctor_id,
                        u.full_name AS doctor_name,
                        COUNT(i.id) AS item_count,
                        GROUP_CONCAT(CONCAT(i.product_name,
                            CASE WHEN i.notes IS NULL OR i.notes = '' THEN ''
                                 ELSE CONCAT(' (', i.notes, ')') END,
                            CASE WHEN i.quantity <> 1 THEN CONCAT(' ×', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.quantity))) ELSE '' END,
                            CASE WHEN i.dosage IS NULL OR i.dosage = '' THEN ''
                                 ELSE CONCAT(' — ', i.dosage) END)
                            ORDER BY i.id SEPARATOR ' | ') AS items_summary
                 FROM erp2_prescriptions pr
                 LEFT JOIN users u ON u.id = pr.doctor_id
                 LEFT JOIN erp2_prescription_items i ON i.prescription_id = pr.id
                 WHERE pr.case_id = :cid
                 GROUP BY pr.id
                 ORDER BY pr.created_at DESC",
                ['cid' => $caseId]
            );
            erp2_presc_json(['ok' => true, 'results' => $rows]);
        }

        /** One prescription with live stock/price — what the till prefills from. */
        case 'detail': {
            if (!erp2_can('erp2.prescriptions.view')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int)($_GET['id'] ?? 0);
            $pr = $db->fetch(
                "SELECT pr.*, pa.full_name AS patient_name, pa.phone AS patient_phone,
                        u.full_name AS doctor_name, c.case_number
                 FROM erp2_prescriptions pr
                 JOIN patients pa ON pa.id = pr.patient_id
                 LEFT JOIN users u ON u.id = pr.doctor_id
                 LEFT JOIN cases c ON c.id = pr.case_id
                 WHERE pr.id = :id",
                ['id' => $id]
            );
            if (!$pr) {
                erp2_presc_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
            }
            $wh = erp2_default_warehouse_id();
            $pr['items'] = $db->fetchAll(
                "SELECT i.id, i.product_id, i.product_name, i.quantity, i.dosage, i.notes,
                        p.sale_price, p.min_sale_price, p.unit_id,
                        COALESCE(s.quantity, 0) AS stock
                 FROM erp2_prescription_items i
                 LEFT JOIN erp2_products p ON p.id = i.product_id
                 LEFT JOIN erp2_warehouse_product_stock s
                        ON s.product_id = i.product_id AND s.warehouse_id = :w
                 WHERE i.prescription_id = :id
                 ORDER BY i.id",
                ['id' => $id, 'w' => $wh]
            );
            erp2_presc_json(['ok' => true, 'prescription' => $pr]);
        }

        case 'cancel': {
            if (!erp2_can('erp2.prescriptions.view') && !erp2_can('erp2.prescriptions.create')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            $pr = $db->fetch('SELECT id, status FROM erp2_prescriptions WHERE id = :id', ['id' => $id]);
            if (!$pr) {
                erp2_presc_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
            }
            if ($pr['status'] !== 'pending') {
                erp2_presc_json(['ok' => false, 'error' => 'تەنها داواکاری چاوەڕوان هەڵدەوەشێتەوە'], 409);
            }
            $db->update('erp2_prescriptions', [
                'status'       => 'cancelled',
                'cancelled_at' => date('Y-m-d H:i:s'),
                'status_note'  => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null,
                'status_by'    => $actor ?: null,
            ], 'id = :id', ['id' => $id]);
            Erp2Audit::log('prescription_cancelled', 'PRESCRIPTION', $id, (string)$id, null, null);
            erp2_presc_json(['ok' => true]);
        }

        /**
         * The patient buys outside the clinic: the request leaves the waiting
         * queue for its own list, and the counter prints the prescription
         * (erp2/prescription-print.php) to hand over.
         */
        case 'outside': {
            if (!erp2_can('erp2.prescriptions.view')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            $pr = $db->fetch('SELECT id, status FROM erp2_prescriptions WHERE id = :id', ['id' => $id]);
            if (!$pr) {
                erp2_presc_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
            }
            if ($pr['status'] !== 'pending') {
                erp2_presc_json(['ok' => false, 'error' => 'تەنها داواکاری چاوەڕوان دەنێردرێت بۆ دەرەوە'], 409);
            }
            $db->update('erp2_prescriptions', [
                'status'      => 'outside',
                'outside_at'  => date('Y-m-d H:i:s'),
                'status_note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null,
                'status_by'   => $actor ?: null,
            ], 'id = :id', ['id' => $id]);
            Erp2Audit::log('prescription_outside', 'PRESCRIPTION', $id, (string)$id, ['status' => 'pending'], ['status' => 'outside']);
            erp2_presc_json(['ok' => true]);
        }

        /** Undo «outside» or «rejected»: back to the waiting queue. */
        case 'reopen': {
            if (!erp2_can('erp2.prescriptions.view')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            $pr = $db->fetch('SELECT id, status FROM erp2_prescriptions WHERE id = :id', ['id' => $id]);
            if (!$pr) {
                erp2_presc_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
            }
            if (!in_array($pr['status'], ['outside', 'cancelled'], true)) {
                erp2_presc_json(['ok' => false, 'error' => 'تەنها داواکاری دەرەوە یان ڕەتکراو دەگەڕێتەوە'], 409);
            }
            $db->update('erp2_prescriptions', [
                'status' => 'pending', 'outside_at' => null, 'cancelled_at' => null, 'status_note' => null, 'status_by' => $actor ?: null,
            ], 'id = :id', ['id' => $id]);
            Erp2Audit::log('prescription_reopen', 'PRESCRIPTION', $id, (string)$id, ['status' => $pr['status']], ['status' => 'pending']);
            erp2_presc_json(['ok' => true]);
        }

        /** Called by the till right after the sale saves. */
        case 'mark_sold': {
            if (!erp2_can('erp2.prescriptions.view')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $id        = (int)($_POST['id'] ?? 0);
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $pr = $db->fetch('SELECT id, status FROM erp2_prescriptions WHERE id = :id', ['id' => $id]);
            if (!$pr) {
                erp2_presc_json(['ok' => false, 'error' => 'نەدۆزرایەوە'], 404);
            }
            // Not an error: the sale itself already succeeded, and the till must
            // never show a scary failure for a bookkeeping flag that was
            // already set (a double submit, or two counters on the same queue).
            // One sent «outside» and then bought here after all (its printed
            // barcode scanned at the till) is sold too.
            if (!in_array($pr['status'], ['pending', 'outside'], true)) {
                erp2_presc_json(['ok' => true, 'already' => true]);
            }
            $db->update('erp2_prescriptions', [
                'status'     => 'sold',
                'invoice_id' => $invoiceId ?: null,
                'sold_at'    => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
            Erp2Audit::log('prescription_sold', 'PRESCRIPTION', $id, (string)$id, null,
                ['invoice_id' => $invoiceId]);
            erp2_presc_json(['ok' => true]);
        }

        default:
            erp2_presc_json(['ok' => false, 'error' => 'کردارێکی نادروست'], 400);
    }
} catch (Throwable $e) {
    error_log('erp2 prescriptions api [' . $action . ']: ' . $e->getMessage());
    erp2_presc_json(['ok' => false, 'error' => 'هەڵەیەک ڕوویدا'], 500);
}
