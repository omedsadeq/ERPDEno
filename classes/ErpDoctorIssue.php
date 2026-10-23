<?php
/**
 * ErpDoctorIssue — مەواد بۆ دکتۆر: the store hands materials to a doctor.
 *
 * One voucher, in one doctor's name, of one kind — ئاسایی (normal) or
 * ئیمپلانت (implant), chosen before the first line. Saving it does three
 * things in ONE transaction:
 *
 *   1. every line leaves the warehouse through ErpStock::issue (DOCTOR_ISSUE),
 *      at the warehouse's weighted average cost — what the material actually
 *      cost the clinic, so the inventory value stays true. A product the store
 *      has no cost for yet falls back to its purchase price;
 *   2. the doctor is charged per the saved price setting (priceMode()): the
 *      SALE price — the clinic's practice — or the purchase price. The
 *      voucher stores both: unit_cost/total_cost and unit_price/total_amount;
 *   3. the charged total becomes one row in خەرجی کۆگا (expenses_store) with
 *      the doctor, the kind and source_type/source_id pointing back here.
 *      The doctor's profile and daily summary already subtract store expenses
 *      tagged with their id, so the doctor's own account is charged with no
 *      further step.
 *
 * Not a sale on purpose: a sale would make the doctor a debtor and book store
 * revenue, and the expense would then charge the same materials twice.
 *
 * Cancelling reverses all three: the stock comes back at the cost it left with
 * (DOCTOR_RETURN) and the expense row is removed.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ErpStock.php';
require_once __DIR__ . '/ErpSequence.php';
require_once __DIR__ . '/ErpAudit.php';

class ErpDoctorIssue
{
    /** expense_kind => label. The order is the order the screens offer them. */
    const KINDS = ['normal' => 'ئاسایی', 'implant' => 'ئیمپلانت'];

    /** expenses_store.source_type for rows written by a voucher. */
    const SOURCE = 'erp_doctor_issue';

    /**
     * Which price a doctor is charged — one saved setting. The clinic gives
     * materials at the sale price; 'cost' charges what the material cost.
     */
    const PRICE_SETTING = 'erp_doctor_issue_price';
    const PRICE_MODES = ['sale' => 'نرخی فرۆشتن', 'cost' => 'نرخی کڕین'];

    public static function kindLabel(?string $kind): string
    {
        return self::KINDS[$kind ?? ''] ?? (string)$kind;
    }

    public static function priceMode(): string
    {
        require_once __DIR__ . '/Settings.php';
        $mode = Settings::get(self::PRICE_SETTING, 'sale');
        return isset(self::PRICE_MODES[$mode]) ? $mode : 'sale';
    }

    public static function setPriceMode(string $mode): void
    {
        if (!isset(self::PRICE_MODES[$mode])) {
            throw new RuntimeException('جۆری نرخ نادروستە');
        }
        require_once __DIR__ . '/Settings.php';
        $old = self::priceMode();
        Settings::set(self::PRICE_SETTING, $mode, 'text', 'مەواد بۆ دکتۆر: نرخی فرۆشتن (sale) یان نرخی کڕین (cost)');
        if ($old !== $mode) {
            ErpAudit::log('doctor_issue_price_mode', 'SETTING', null, self::PRICE_SETTING, ['mode' => $old], ['mode' => $mode]);
        }
    }

    public static function priceModeLabel(?string $mode): string
    {
        return self::PRICE_MODES[$mode ?? ''] ?? (string)$mode;
    }

    /** Doctor accounts that may receive materials: active users with role doctor. */
    public static function doctors(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name"
        );
    }

    /**
     * @param array $data doctor_id, warehouse_id, issue_date, expense_kind, notes,
     *                    items[] => [product_id, quantity, notes?]
     * @return array{id:int, issue_number:string, total_cost:float, expense_id:int, lines:int, zero_cost:array}
     */
    public static function create(array $data): array
    {
        $db = Database::getInstance();

        $kind = (string)($data['expense_kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new RuntimeException('جۆری خەرجی هەڵبژێرە: ئاسایی یان ئیمپلانت');
        }
        $doctorId = (int)($data['doctor_id'] ?? 0);
        $doctor = $doctorId > 0 ? $db->fetch(
            "SELECT id, full_name FROM users WHERE id = :id AND role = 'doctor' AND is_active = 1",
            ['id' => $doctorId]
        ) : null;
        if (!$doctor) {
            throw new RuntimeException('دکتۆر هەڵبژێرە');
        }
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $warehouse = $warehouseId > 0 ? $db->fetch(
            "SELECT id, name FROM erp_warehouses WHERE id = :id AND is_active = 1",
            ['id' => $warehouseId]
        ) : null;
        if (!$warehouse) {
            throw new RuntimeException('کۆگا نەدۆزرایەوە');
        }

        // One line per product: the same material scanned twice is one line
        // with the summed quantity, as on every other store document.
        $lines = [];
        foreach (($data['items'] ?? []) as $raw) {
            $productId = (int)($raw['product_id'] ?? 0);
            $qty = round((float)($raw['quantity'] ?? 0), 3);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            if (!isset($lines[$productId])) {
                $lines[$productId] = ['product_id' => $productId, 'quantity' => 0.0, 'notes' => null];
            }
            $lines[$productId]['quantity'] = round($lines[$productId]['quantity'] + $qty, 3);
            if (!empty($raw['notes'])) {
                $lines[$productId]['notes'] = mb_substr(trim((string)$raw['notes']), 0, 255);
            }
        }
        if (!$lines) {
            throw new RuntimeException('وەسڵ پێویستی بە لانیکەم یەک کاڵا هەیە');
        }

        $date = self::validDate($data['issue_date'] ?? null) ?: date('Y-m-d');
        $notes = trim((string)($data['notes'] ?? '')) ?: null;
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        // The saved setting decides, never the request: a voucher cannot be
        // priced differently from what the clinic set.
        $mode = self::priceMode();

        $db->beginTransaction();
        try {
            $number = ErpSequence::next('DOCTOR_ISSUE', 0);
            $issueId = (int)$db->insert('erp_doctor_issues', [
                'issue_number' => $number,
                'doctor_id'    => $doctorId,
                'warehouse_id' => $warehouseId,
                'issue_date'   => $date,
                'expense_kind' => $kind,
                'price_mode'   => $mode,
                'total_cost'   => 0,
                'total_amount' => 0,
                'status'       => 'confirmed',
                'notes'        => $notes,
                'created_by'   => $userId,
            ]);

            $totalCost = 0.0;
            $total = 0.0;
            $summary = [];
            $zeroCost = [];
            $noSalePrice = [];
            foreach ($lines as $line) {
                $product = $db->fetch(
                    "SELECT id, name, purchase_price, sale_price, is_active FROM erp_products WHERE id = :id",
                    ['id' => $line['product_id']]
                );
                if (!$product || !(int)$product['is_active']) {
                    throw new RuntimeException('کاڵا نەدۆزرایەوە یان ناچالاکە');
                }
                $level = ErpStock::levelOf((int)$product['id'], $warehouseId);
                $cost = (float)$level['average_cost'] > 0
                    ? (float)$level['average_cost']
                    : max(0.0, (float)$product['purchase_price']);

                // Stock always leaves at what it cost, whatever the doctor is charged.
                $moved = ErpStock::issue(
                    (int)$product['id'], $warehouseId, $line['quantity'], 'DOCTOR_ISSUE', $issueId, $number,
                    ['allow_negative' => false, 'unit_cost' => $cost,
                     'notes' => mb_substr('مەواد بۆ ' . $doctor['full_name'], 0, 255)]
                );
                $unitCost = round((float)$moved['unit_cost'], 4);

                // What the doctor is charged. A material with no sale price yet
                // is charged at cost rather than at zero, and reported.
                if ($mode === 'sale' && (float)$product['sale_price'] > 0) {
                    $unitPrice = round((float)$product['sale_price'], 2);
                } else {
                    if ($mode === 'sale') {
                        $noSalePrice[] = $product['name'];
                    }
                    $unitPrice = round($unitCost, 2);
                }
                if ($unitPrice <= 0) {
                    $zeroCost[] = $product['name'];
                }
                $lineTotal = round($line['quantity'] * $unitPrice, 2);

                $db->insert('erp_doctor_issue_items', [
                    'issue_id'   => $issueId,
                    'product_id' => (int)$product['id'],
                    'quantity'   => $line['quantity'],
                    'unit_cost'  => $unitCost,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'notes'      => $line['notes'],
                ]);
                $totalCost += $line['quantity'] * $unitCost;
                $total += $lineTotal;
                $summary[] = $product['name'] . ' × ' . rtrim(rtrim(number_format($line['quantity'], 3, '.', ''), '0'), '.');
            }
            $total = round($total, 2);
            $totalCost = round($totalCost, 2);

            // The voucher, as the clinic's expense, in the doctor's name.
            $expenseId = (int)$db->insert('expenses_store', [
                'category_id'    => null,
                'expense_kind'   => $kind,
                'doctor_id'      => $doctorId,
                'description'    => 'مەواد بۆ دکتۆر — ' . $doctor['full_name'] . ' (' . count($lines) . ' مەواد، '
                                    . self::priceModeLabel($mode) . ')',
                'amount'         => $total,
                'expense_date'   => $date,
                'supplier'       => $warehouse['name'],
                'invoice_number' => $number,
                'quantity'       => 1,
                'unit'           => null,
                'unit_price'     => $total,
                'payment_status' => 'paid',
                'notes'          => mb_substr(implode('، ', $summary), 0, 2000),
                'source_type'    => self::SOURCE,
                'source_id'      => $issueId,
                'created_by'     => $userId,
            ]);

            $db->update('erp_doctor_issues', ['total_cost' => $totalCost, 'total_amount' => $total, 'expense_id' => $expenseId],
                'id = :id', ['id' => $issueId]);
            $db->commit();

            ErpAudit::log('doctor_issue', 'DOCTOR_ISSUE', $issueId, $number, null, [
                'doctor_id'    => $doctorId,
                'warehouse_id' => $warehouseId,
                'expense_kind' => $kind,
                'price_mode'   => $mode,
                'total_amount' => $total,
                'total_cost'   => $totalCost,
                'lines'        => count($lines),
                'expense_id'   => $expenseId,
            ]);

            return [
                'id'            => $issueId,
                'issue_number'  => $number,
                'price_mode'    => $mode,
                'total_amount'  => $total,
                'total_cost'    => $totalCost,
                'expense_id'    => $expenseId,
                'lines'         => count($lines),
                'zero_cost'     => $zeroCost,
                'no_sale_price' => $noSalePrice,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * Undo a voucher: stock back at the cost it left with, expense removed.
     * The voucher itself stays, marked cancelled, as history.
     */
    public static function cancel(int $issueId, string $reason = ''): array
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $issue = $db->fetch("SELECT * FROM erp_doctor_issues WHERE id = :id FOR UPDATE", ['id' => $issueId]);
            if (!$issue) {
                throw new RuntimeException('وەسڵ نەدۆزرایەوە');
            }
            if ($issue['status'] !== 'confirmed') {
                throw new RuntimeException('ئەم وەسڵە پێشتر هەڵوەشێنراوەتەوە');
            }
            $items = $db->fetchAll(
                "SELECT * FROM erp_doctor_issue_items WHERE issue_id = :id ORDER BY id",
                ['id' => $issueId]
            );
            foreach ($items as $it) {
                ErpStock::receive(
                    (int)$it['product_id'], (int)$issue['warehouse_id'], (float)$it['quantity'], (float)$it['unit_cost'],
                    'DOCTOR_RETURN', $issueId, $issue['issue_number'],
                    ['notes' => 'هەڵوەشاندنەوەی ' . $issue['issue_number']]
                );
            }
            $removed = $db->fetch(
                "SELECT * FROM expenses_store WHERE source_type = :t AND source_id = :s",
                ['t' => self::SOURCE, 's' => $issueId]
            );
            $db->delete('expenses_store', 'source_type = :t AND source_id = :s', ['t' => self::SOURCE, 's' => $issueId]);
            $reason = mb_substr(trim($reason), 0, 255);
            $db->update('erp_doctor_issues', [
                'status'        => 'cancelled',
                'expense_id'    => null,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancelled_by'  => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ], 'id = :id', ['id' => $issueId]);
            $db->commit();

            ErpAudit::log('doctor_issue_cancel', 'DOCTOR_ISSUE', $issueId, $issue['issue_number'],
                ['status' => 'confirmed', 'expense' => $removed ?: null],
                ['status' => 'cancelled', 'reason' => $reason]);

            return ['id' => $issueId, 'issue_number' => $issue['issue_number'], 'restored_lines' => count($items)];
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    public static function load(int $issueId): ?array
    {
        $db = Database::getInstance();
        $issue = $db->fetch(
            "SELECT d.*, u.full_name AS doctor_name, w.name AS warehouse_name,
                    cu.full_name AS created_by_name, xu.full_name AS cancelled_by_name
             FROM erp_doctor_issues d
             LEFT JOIN users u  ON u.id = d.doctor_id
             LEFT JOIN users cu ON cu.id = d.created_by
             LEFT JOIN users xu ON xu.id = d.cancelled_by
             JOIN erp_warehouses w ON w.id = d.warehouse_id
             WHERE d.id = :id",
            ['id' => $issueId]
        );
        if (!$issue) {
            return null;
        }
        $issue['items'] = $db->fetchAll(
            "SELECT i.*, p.name AS product_name, p.product_code, un.name AS unit_name
             FROM erp_doctor_issue_items i
             JOIN erp_products p ON p.id = i.product_id
             LEFT JOIN erp_units un ON un.id = p.unit_id
             WHERE i.issue_id = :id
             ORDER BY i.id",
            ['id' => $issueId]
        );
        return $issue;
    }

    /**
     * Vouchers for the list screen, newest first, plus the totals the filter
     * selects (confirmed vouchers only — a cancelled one charged nobody).
     *
     * @param array $f from, to, doctor_id, kind, status
     */
    public static function search(array $f, int $limit = 200): array
    {
        $where = ['d.issue_date BETWEEN :from AND :to'];
        $params = [
            'from' => self::validDate($f['from'] ?? null) ?: date('Y-m-01'),
            'to'   => self::validDate($f['to'] ?? null) ?: date('Y-m-d'),
        ];
        if (!empty($f['doctor_id'])) {
            $where[] = 'd.doctor_id = :doc';
            $params['doc'] = (int)$f['doctor_id'];
        }
        if (!empty($f['kind']) && isset(self::KINDS[$f['kind']])) {
            $where[] = 'd.expense_kind = :kind';
            $params['kind'] = $f['kind'];
        }
        if (in_array($f['status'] ?? '', ['confirmed', 'cancelled'], true)) {
            $where[] = 'd.status = :st';
            $params['st'] = $f['status'];
        }
        $sql = implode(' AND ', $where);
        $limit = max(1, min(1000, $limit));
        $db = Database::getInstance();

        $rows = $db->fetchAll(
            "SELECT d.id, d.issue_number, d.issue_date, d.doctor_id, d.expense_kind, d.price_mode,
                    d.total_cost, d.total_amount, d.status,
                    d.cancel_reason, u.full_name AS doctor_name, w.name AS warehouse_name,
                    (SELECT COUNT(*) FROM erp_doctor_issue_items i WHERE i.issue_id = d.id) AS line_count
             FROM erp_doctor_issues d
             LEFT JOIN users u ON u.id = d.doctor_id
             JOIN erp_warehouses w ON w.id = d.warehouse_id
             WHERE {$sql}
             ORDER BY d.issue_date DESC, d.id DESC
             LIMIT {$limit}",
            $params
        );
        $totals = $db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN d.expense_kind = 'normal'  THEN d.total_amount END), 0) AS normal,
                    COALESCE(SUM(CASE WHEN d.expense_kind = 'implant' THEN d.total_amount END), 0) AS implant,
                    COUNT(*) AS cnt
             FROM erp_doctor_issues d
             WHERE {$sql} AND d.status = 'confirmed'",
            $params
        );
        $byDoctor = $db->fetchAll(
            "SELECT d.doctor_id, u.full_name AS doctor_name,
                    COALESCE(SUM(CASE WHEN d.expense_kind = 'normal'  THEN d.total_amount END), 0) AS normal,
                    COALESCE(SUM(CASE WHEN d.expense_kind = 'implant' THEN d.total_amount END), 0) AS implant,
                    COUNT(*) AS cnt
             FROM erp_doctor_issues d
             LEFT JOIN users u ON u.id = d.doctor_id
             WHERE {$sql} AND d.status = 'confirmed'
             GROUP BY d.doctor_id, u.full_name
             ORDER BY (COALESCE(SUM(d.total_amount), 0)) DESC",
            $params
        );
        return [
            'rows'      => $rows,
            'normal'    => (float)$totals['normal'],
            'implant'   => (float)$totals['implant'],
            'count'     => (int)$totals['cnt'],
            'by_doctor' => $byDoctor,
            'from'      => $params['from'],
            'to'        => $params['to'],
        ];
    }

    private static function validDate($v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }
}
