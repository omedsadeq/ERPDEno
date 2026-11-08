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
// a restricted doctor writes and reads only for the patients they may see
require_once __DIR__ . '/../../classes/AccessGuard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function erp2_presc_json($payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- the admin's arrangement of the sheet (erp2_rx_sheet_layout) ----------
 * A name of its own on the sheet, a place in the order, off the sheet, and for
 * an item the column it stands in. Only the sheet reads it: the till, the
 * stock and the printed prescription keep the pharmacy's own names. */

const ERP2_RX_KINDS = ['group' => 'erp2_categories', 'type' => 'erp2_product_types', 'item' => 'erp2_products'];

/** kind → ref_id → the admin's row. Empty until the migration has run. */
function erp2_rx_layout(Database $db): array
{
    $out = ['group' => [], 'type' => [], 'item' => []];
    if (!$db->tableExists('erp2_rx_sheet_layout')) {
        return $out;
    }
    foreach ($db->fetchAll('SELECT kind, ref_id, label, sort_order, is_hidden, group_id FROM erp2_rx_sheet_layout') as $r) {
        if (isset($out[$r['kind']])) {
            $out[$r['kind']][(int)$r['ref_id']] = $r;
        }
    }
    return $out;
}

/** One thing on the sheet: the admin's name over the pharmacy's, and where it stands. */
function erp2_rx_node(int $id, string $erpName, ?array $l, $erpSort): array
{
    $label = ($l && $l['label'] !== null && $l['label'] !== '') ? (string)$l['label'] : null;
    return [
        'id'       => $id,
        'name'     => $label ?? $erpName,
        'erp_name' => $erpName,
        'renamed'  => $label !== null,
        'hidden'   => $l && (int)$l['is_hidden'] === 1,
        // arranged ones first, in the admin's order; the rest (a pharmacy row
        // added later) after them, in the pharmacy's order
        '_sort'    => [$l && $l['sort_order'] !== null ? (int)$l['sort_order'] : 1000000 + (int)$erpSort, mb_strtolower($erpName)],
    ];
}

function erp2_rx_sort(array &$list): void
{
    usort($list, fn($a, $b) => $a['_sort'] <=> $b['_sort']);
    foreach ($list as &$x) {
        unset($x['_sort']);
    }
    unset($x);
}

function erp2_rx_require_arrange(): void
{
    if (!erp2_can('erp2.rx_sheet.manage') || !erp2_can('erp2.prescriptions.create')) {
        erp2_presc_json(['ok' => false, 'error' => 'تەنها بەڕێوەبەر پەڕەکە ڕێکدەخات'], 403);
    }
    if (!Database::getInstance()->tableExists('erp2_rx_sheet_layout')) {
        erp2_presc_json(['ok' => false, 'error' => 'خشتەی ڕێکخستنی پەڕە هێشتا دروست نەکراوە'], 500);
    }
}

/** Writes one row of the arrangement; a row back to the pharmacy's own is removed. */
function erp2_rx_layout_save(Database $db, string $kind, int $id, array $row, int $actor): void
{
    $plain = $row['label'] === null && $row['sort_order'] === null && (int)$row['is_hidden'] === 0 && $row['group_id'] === null;
    $db->query('DELETE FROM erp2_rx_sheet_layout WHERE kind = :k AND ref_id = :id', ['k' => $kind, 'id' => $id]);
    if ($plain) {
        return;
    }
    $db->insert('erp2_rx_sheet_layout', [
        'kind' => $kind, 'ref_id' => $id,
        'label' => $row['label'], 'sort_order' => $row['sort_order'],
        'is_hidden' => (int)$row['is_hidden'], 'group_id' => $row['group_id'],
        'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $actor ?: null,
    ]);
}

function erp2_rx_layout_row(Database $db, string $kind, int $id): array
{
    $r = $db->fetch(
        'SELECT label, sort_order, is_hidden, group_id FROM erp2_rx_sheet_layout WHERE kind = :k AND ref_id = :id',
        ['k' => $kind, 'id' => $id]
    );
    return [
        'label'      => $r['label'] ?? null,
        'sort_order' => isset($r['sort_order']) ? (int)$r['sort_order'] : null,
        'is_hidden'  => (int)($r['is_hidden'] ?? 0),
        'group_id'   => isset($r['group_id']) ? (int)$r['group_id'] : null,
    ];
}

$db     = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$actor  = (int)($_SESSION['user_id'] ?? 0);

$writeActions = ['save', 'cancel', 'mark_sold', 'outside', 'reopen', 'sheet_set', 'sheet_order', 'sheet_reset', 'sheet_delete'];
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
            // The admin's arrangement (erp2_rx_sheet_layout). ?edit=1, for the
            // admin, also returns what was taken off the sheet, to put it back.
            $canArrange = erp2_can('erp2.rx_sheet.manage');
            $editing    = $canArrange && !empty($_GET['edit']);
            $layout     = erp2_rx_layout($db);
            $isOff      = fn(?array $l) => $l && (int)$l['is_hidden'] === 1;
            $wh = erp2_default_warehouse_id();
            $groups = [];
            $offSheet = [];   // columns taken off the sheet: their items go with them
            foreach ($db->fetchAll("SELECT id, name, sort_order FROM erp2_categories WHERE is_active = 1") as $g) {
                $l = $layout['group'][(int)$g['id']] ?? null;
                if ($isOff($l) && !$editing) {
                    $offSheet[(int)$g['id']] = true;
                    continue;
                }
                $groups[(int)$g['id']] = erp2_rx_node((int)$g['id'], (string)$g['name'], $l, $g['sort_order']) + ['types' => [], 'items' => []];
            }
            $typeName = [];   // the type's name on the sheet, for the label beside an item
            foreach ($db->fetchAll("SELECT id, category_id, name, sort_order FROM erp2_product_types WHERE is_active = 1") as $t) {
                $l = $layout['type'][(int)$t['id']] ?? null;
                $node = erp2_rx_node((int)$t['id'], (string)$t['name'], $l, $t['sort_order']);
                $typeName[(int)$t['id']] = $node['name'];
                if (isset($groups[(int)$t['category_id']]) && (!$isOff($l) || $editing)) {
                    $groups[(int)$t['category_id']]['types'][] = $node;
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
            // The column of items whose group is not on the pharmacy's list; the
            // admin may rename it, place it or take it off like any other.
            $looseLay = $layout['group'][0] ?? null;
            $loose = erp2_rx_node(0, 'بێ گرووپ', $looseLay, 1000000) + ['types' => [], 'items' => []];
            $loose['_sort'][0] = $looseLay && $looseLay['sort_order'] !== null ? (int)$looseLay['sort_order'] : 2000000000;
            $looseOff = $isOff($looseLay) && !$editing;
            foreach ($db->fetchAll(
                "SELECT p.id, p.name, p.name_ku, p.category_id, p.type_id, p.sort_order, t.name AS type_name,
                        u.name AS unit_name, COALESCE(s.quantity, 0) AS stock
                 FROM erp2_products p
                 LEFT JOIN erp2_product_types t ON t.id = p.type_id
                 LEFT JOIN erp2_units u ON u.id = p.unit_id
                 LEFT JOIN erp2_warehouse_product_stock s ON s.product_id = p.id AND s.warehouse_id = :w
                 WHERE p.is_active = 1",
                ['w' => $wh]
            ) as $p) {
                $l = $layout['item'][(int)$p['id']] ?? null;
                if ($isOff($l) && !$editing) {
                    continue;
                }
                $typeId = $p['type_id'] !== null ? (int)$p['type_id'] : null;
                $item = erp2_rx_node((int)$p['id'], (string)$p['name'], $l, $p['sort_order']) + [
                    'name_ku' => $p['name_ku'],
                    'type_id' => $typeId, 'type_name' => $typeId !== null ? ($typeName[$typeId] ?? $p['type_name']) : null,
                    'unit_name' => $p['unit_name'], 'stock' => (float)$p['stock'],
                    'doses' => $doses[(int)$p['id']] ?? [],
                    'home_group' => (int)$p['category_id'],
                ];
                $gid = (int)$p['category_id'];
                // the admin may stand an item in another column
                if ($l && $l['group_id'] !== null && (isset($groups[(int)$l['group_id']]) || isset($offSheet[(int)$l['group_id']]))) {
                    $gid = (int)$l['group_id'];
                }
                if (isset($offSheet[$gid])) {
                    continue;
                }
                if (isset($groups[$gid])) {
                    $groups[$gid]['items'][] = $item;
                } elseif (!$looseOff) {
                    $loose['items'][] = $item;
                }
            }
            // دەرمانی دەرەوە the admin added (erp2_rx_sheet_outside): on the sheet
            // beside the pharmacy's, with a NEGATIVE id so the two never meet,
            // and no stock. Ticked, one goes on the prescription as an outside
            // line by its name — nothing to do with the pharmacy.
            if ($db->tableExists('erp2_rx_sheet_outside')) {
                foreach ($db->fetchAll('SELECT id, group_id, name, dosage, sort_order, is_hidden FROM erp2_rx_sheet_outside') as $o) {
                    $off = (int)$o['is_hidden'] === 1;
                    $gid = (int)$o['group_id'];
                    if (($off && !$editing) || isset($offSheet[$gid])) {
                        continue;
                    }
                    $item = [
                        'id' => -(int)$o['id'], 'name' => (string)$o['name'], 'erp_name' => (string)$o['name'],
                        'renamed' => false, 'hidden' => $off, 'outside' => true, 'dosage' => $o['dosage'],
                        // after the arranged ones, and after the pharmacy's own
                        '_sort' => [$o['sort_order'] !== null ? (int)$o['sort_order'] : 1500000 + (int)$o['id'], mb_strtolower((string)$o['name'])],
                        'name_ku' => null, 'type_id' => null, 'type_name' => null, 'unit_name' => null, 'stock' => 0,
                        'doses' => ($o['dosage'] !== null && $o['dosage'] !== '') ? [['label' => null, 'text' => $o['dosage']]] : [],
                        'home_group' => $gid,
                    ];
                    if ($gid !== 0 && isset($groups[$gid])) {
                        $groups[$gid]['items'][] = $item;
                    } elseif (!$looseOff) {
                        $loose['items'][] = $item;
                    }
                }
            }
            if ($loose['items']) {
                $groups[0] = $loose;
            }
            foreach ($groups as &$g) {
                erp2_rx_sort($g['types']);
                erp2_rx_sort($g['items']);
            }
            unset($g);
            $out = array_values($groups);
            erp2_rx_sort($out);
            // While arranging every column stays, an empty one too, so an item
            // can be moved into it.
            if (!$editing) {
                $out = array_values(array_filter($out, fn($g) => $g['items'] || $g['types']));
            }
            erp2_presc_json(['ok' => true, 'groups' => $out, 'warehouse_id' => $wh, 'can_arrange' => $canArrange, 'editing' => $editing]);
        }

        /**
         * The admin arranges the sheet: one thing's name on the sheet, taken
         * off or put back, and for an item the column it stands in. Only the
         * sheet changes — the pharmacy keeps its own names and groups.
         */
        case 'sheet_set': {
            erp2_rx_require_arrange();
            $kind = (string)($_POST['kind'] ?? '');
            $id   = (int)($_POST['id'] ?? -1);

            // A دەرمانی دەرەوە is the sheet's own: its name, dose, column and
            // whether it is on the sheet are written straight into its row.
            // id 0 adds a new one.
            if ($kind === 'outside') {
                if (!$db->tableExists('erp2_rx_sheet_outside') || $id < 0) {
                    erp2_presc_json(['ok' => false, 'error' => 'دەرمانی دەرەوە نەدۆزرایەوە'], 404);
                }
                $row = $id > 0 ? $db->fetch('SELECT id, group_id FROM erp2_rx_sheet_outside WHERE id = :id', ['id' => $id]) : null;
                if ($id > 0 && !$row) {
                    erp2_presc_json(['ok' => false, 'error' => 'دەرمانی دەرەوە نەدۆزرایەوە'], 404);
                }
                $set = [];
                if ($id === 0 || array_key_exists('label', $_POST)) {
                    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['label'] ?? ''))), 0, 160);
                    if ($name === '') {
                        erp2_presc_json(['ok' => false, 'error' => 'ناوی دەرمانەکە بنووسە'], 422);
                    }
                    $set['name'] = $name;
                }
                if (array_key_exists('dosage', $_POST)) {
                    $dose = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$_POST['dosage'])), 0, 255);
                    $set['dosage'] = $dose === '' ? null : $dose;
                }
                if (array_key_exists('hidden', $_POST)) {
                    $set['is_hidden'] = (string)$_POST['hidden'] === '1' ? 1 : 0;
                }
                if ($id === 0 || array_key_exists('group_id', $_POST)) {
                    $to = (int)($_POST['group_id'] ?? 0);
                    if ($to !== 0 && !$db->fetch('SELECT id FROM erp2_categories WHERE id = :id AND is_active = 1', ['id' => $to])) {
                        erp2_presc_json(['ok' => false, 'error' => 'ئەو ستوونە نەدۆزرایەوە'], 422);
                    }
                    // a new column: at its end until placed
                    if (!$row || (int)$row['group_id'] !== $to) {
                        $set['group_id'] = $to;
                        $set['sort_order'] = null;
                    }
                }
                if ($id === 0) {
                    $newId = (int)$db->insert('erp2_rx_sheet_outside', $set + ['created_by' => $actor ?: null]);
                    erp2_presc_json(['ok' => true, 'id' => $newId]);
                }
                if ($set) {
                    $db->update('erp2_rx_sheet_outside', $set + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $actor ?: null],
                        'id = :oid', ['oid' => $id]);
                }
                erp2_presc_json(['ok' => true]);
            }
            $known = isset(ERP2_RX_KINDS[$kind]) && $id >= 0
                && (($kind === 'group' && $id === 0)
                    || $db->fetch('SELECT id FROM ' . ERP2_RX_KINDS[$kind] . ' WHERE id = :id', ['id' => $id]));
            if (!$known) {
                erp2_presc_json(['ok' => false, 'error' => 'لە پەڕەکەدا نەدۆزرایەوە'], 404);
            }
            $row = erp2_rx_layout_row($db, $kind, $id);
            if (array_key_exists('label', $_POST)) {
                $label = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$_POST['label'])), 0, 160);
                $row['label'] = $label === '' ? null : $label;
            }
            if (array_key_exists('hidden', $_POST)) {
                $row['is_hidden'] = (string)$_POST['hidden'] === '1' ? 1 : 0;
            }
            if ($kind === 'item' && array_key_exists('group_id', $_POST)) {
                $to   = (int)$_POST['group_id'];
                $home = (int)($db->fetch('SELECT category_id FROM erp2_products WHERE id = :id', ['id' => $id])['category_id'] ?? 0);
                if ($to !== $home && !$db->fetch('SELECT id FROM erp2_categories WHERE id = :id AND is_active = 1', ['id' => $to])) {
                    erp2_presc_json(['ok' => false, 'error' => 'ئەو ستوونە نەدۆزرایەوە'], 422);
                }
                // back in its own pharmacy group: nothing to remember
                $row['group_id'] = $to === $home ? null : $to;
                // a move lands at the end of the new column until placed
                $row['sort_order'] = null;
            }
            erp2_rx_layout_save($db, $kind, $id, $row, $actor);
            erp2_presc_json(['ok' => true]);
        }

        /**
         * The admin's order for one list on the sheet: the columns, the round
         * choices of one column, or the items of one column. For items the
         * list is the column as it now stands, so an item dropped into it from
         * another column stands there from now on.
         */
        case 'sheet_order': {
            erp2_rx_require_arrange();
            $kind = (string)($_POST['kind'] ?? '');
            $ids  = json_decode((string)($_POST['ids'] ?? '[]'), true);
            $col  = (int)($_POST['group_id'] ?? 0);
            if (!isset(ERP2_RX_KINDS[$kind]) || !is_array($ids) || !$ids || count($ids) > 500) {
                erp2_presc_json(['ok' => false, 'error' => 'ڕیزبەندی نادروستە'], 422);
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if ($kind === 'item' && $col > 0
                && !$db->fetch('SELECT id FROM erp2_categories WHERE id = :id AND is_active = 1', ['id' => $col])) {
                erp2_presc_json(['ok' => false, 'error' => 'ئەو ستوونە نەدۆزرایەوە'], 422);
            }
            $db->beginTransaction();
            try {
                foreach ($ids as $i => $id) {
                    // a دەرمانی دەرەوە (negative id): its own row takes the place
                    // and, dropped into another column, the column
                    if ($kind === 'item' && $id < 0) {
                        if ($db->tableExists('erp2_rx_sheet_outside')) {
                            $upd = ['sort_order' => ($i + 1) * 10, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $actor ?: null];
                            if ($col > 0) {
                                $upd['group_id'] = $col;
                            }
                            $db->update('erp2_rx_sheet_outside', $upd, 'id = :oid', ['oid' => -$id]);
                        }
                        continue;
                    }
                    if ($kind === 'group' && $id === 0) {
                        $home = null;
                    } else {
                        $ref = $db->fetch(
                            'SELECT id' . ($kind === 'group' ? '' : ', category_id') . ' FROM ' . ERP2_RX_KINDS[$kind] . ' WHERE id = :id',
                            ['id' => $id]
                        );
                        if (!$ref) {
                            continue;
                        }
                        $home = $kind === 'group' ? null : (int)$ref['category_id'];
                    }
                    // a round choice belongs to its own column, it does not move
                    if ($kind === 'type' && $home !== $col) {
                        continue;
                    }
                    $row = erp2_rx_layout_row($db, $kind, $id);
                    $row['sort_order'] = ($i + 1) * 10;
                    if ($kind === 'item' && $col > 0) {
                        $row['group_id'] = $col === $home ? null : $col;
                    }
                    erp2_rx_layout_save($db, $kind, $id, $row, $actor);
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
            erp2_presc_json(['ok' => true]);
        }

        /** Back to the pharmacy's own names, order and groups — the whole sheet. */
        case 'sheet_reset': {
            erp2_rx_require_arrange();
            $db->query('DELETE FROM erp2_rx_sheet_layout');
            // the outside medicines stay, in their columns: only back on the
            // sheet and in their default place
            if ($db->tableExists('erp2_rx_sheet_outside')) {
                $db->query('UPDATE erp2_rx_sheet_outside SET sort_order = NULL, is_hidden = 0');
            }
            erp2_presc_json(['ok' => true]);
        }

        /** A دەرمانی دەرەوە off the sheet for good. Prescriptions already
         *  written keep it by name. */
        case 'sheet_delete': {
            erp2_rx_require_arrange();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 || !$db->tableExists('erp2_rx_sheet_outside')) {
                erp2_presc_json(['ok' => false, 'error' => 'دەرمانی دەرەوە نەدۆزرایەوە'], 404);
            }
            $db->query('DELETE FROM erp2_rx_sheet_outside WHERE id = :id', ['id' => $id]);
            erp2_presc_json(['ok' => true]);
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
                // دەرمانی دەرەوە: not in the system at all — written by name for
                // the patient to buy outside, so there is no product to check.
                if (!empty($row['outside'])) {
                    $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 200);
                    if ($name === '' || $qty <= 0) {
                        continue;
                    }
                    $clean[] = [
                        'product_id'   => null,
                        'is_outside'   => 1,
                        'product_name' => $name,
                        'quantity'     => $qty,
                        'dosage'       => trim((string)($row['dosage'] ?? '')) ?: null,
                        'notes'        => null,
                    ];
                    continue;
                }
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
            // Everything bought outside: nothing for the counter to sell, so it
            // goes straight to «دەرەوەی کلینیک» instead of the waiting queue.
            $allOutside = !array_filter($clean, fn($it) => empty($it['is_outside']));

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
            // No case — the doctor's room, a treatment plan: the visit's or the
            // plan's doctor, sent along by the page, when it is not a doctor
            // who writes it.
            if (($_SESSION['role'] ?? '') !== 'doctor' && $caseId <= 0 && (int)($_POST['doctor_id'] ?? 0) > 0) {
                $hinted = $db->fetch(
                    "SELECT id FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1",
                    ['id' => (int)$_POST['doctor_id']]
                );
                if ($hinted) {
                    $doctorId = (int)$hinted['id'];
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
                    'status'      => $allOutside ? 'outside' : 'pending',
                    'outside_at'  => $allOutside ? date('Y-m-d H:i:s') : null,
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
            erp2_presc_json(['ok' => true, 'id' => $prescId, 'items' => count($clean), 'outside' => $allOutside]);
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
                            CASE WHEN i.is_outside = 1 THEN ' [دەرەوە]' ELSE '' END,
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

        /**
         * A patient's prescriptions, whatever the case — the history under the
         * sheet where there is no case (the doctor's room, a treatment plan).
         */
        case 'patient_list': {
            if (!erp2_can('erp2.prescriptions.create') && !erp2_can('erp2.prescriptions.view')) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $patientId = (int)($_GET['patient_id'] ?? 0);
            if ($patientId <= 0) {
                erp2_presc_json(['ok' => true, 'results' => []]);
            }
            if (class_exists('AccessGuard') && AccessGuard::isRestrictedDoctor()
                && !AccessGuard::doctorCanViewPatient($patientId)) {
                erp2_presc_json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            $rows = $db->fetchAll(
                "SELECT pr.id, pr.status, pr.created_at, pr.invoice_id, pr.created_by, pr.doctor_id,
                        u.full_name AS doctor_name,
                        COUNT(i.id) AS item_count,
                        GROUP_CONCAT(CONCAT(i.product_name,
                            CASE WHEN i.is_outside = 1 THEN ' [دەرەوە]' ELSE '' END,
                            CASE WHEN i.notes IS NULL OR i.notes = '' THEN ''
                                 ELSE CONCAT(' (', i.notes, ')') END,
                            CASE WHEN i.quantity <> 1 THEN CONCAT(' ×', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.quantity))) ELSE '' END,
                            CASE WHEN i.dosage IS NULL OR i.dosage = '' THEN ''
                                 ELSE CONCAT(' — ', i.dosage) END)
                            ORDER BY i.id SEPARATOR ' | ') AS items_summary
                 FROM erp2_prescriptions pr
                 LEFT JOIN users u ON u.id = pr.doctor_id
                 LEFT JOIN erp2_prescription_items i ON i.prescription_id = pr.id
                 WHERE pr.patient_id = :pid
                 GROUP BY pr.id
                 ORDER BY pr.created_at DESC
                 LIMIT 30",
                ['pid' => $patientId]
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
                "SELECT i.id, i.product_id, i.is_outside, i.product_name, i.quantity, i.dosage, i.notes,
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
