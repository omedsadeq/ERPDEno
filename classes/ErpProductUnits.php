<?php
/**
 * ErpProductUnits — the alternate selling units of one product.
 *
 * A product always has a BASE unit (erp_products.unit_id). Stock, cost and
 * every movement are stored in that unit and only that unit. Alternates here
 * are conversions on top of it:
 *
 *     piece  base,  factor 1      (implicit, not a row)
 *     box    row,   factor 10     selling 1 box removes 10 pieces
 *     carton row,   factor 100
 *
 * Keeping stock in one unit is the whole point: if a box were stored as "1
 * box" the moment someone sold a single piece the arithmetic would need a
 * conversion anyway, and any later change to the box size would silently
 * restate historical quantities. A factor changed today affects only future
 * documents, because past movements were already written in base units.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ErpAudit.php';

class ErpProductUnits
{
    /**
     * Every sellable unit of a product, base first.
     *
     * The base unit is synthesised rather than stored, so a product with no
     * alternates still returns one usable option and the caller never has to
     * special-case an empty list.
     */
    public static function forProduct(int $productId): array
    {
        $db = Database::getInstance();

        $p = $db->fetch(
            "SELECT p.unit_id, p.sale_price, u.code AS unit_code, u.name AS unit_name
             FROM erp_products p
             LEFT JOIN erp_units u ON u.id = p.unit_id
             WHERE p.id = :id",
            ['id' => $productId]
        );
        if (!$p) {
            return [];
        }

        $out = [[
            'unit_id'    => $p['unit_id'] !== null ? (int)$p['unit_id'] : null,
            'unit_code'  => $p['unit_code'] ?? '',
            'unit_name'  => $p['unit_name'] ?? 'یەکە',
            'factor'     => 1.0,
            'sale_price' => (float)$p['sale_price'],
            'barcode'    => null,
            'is_base'    => 1,
            'is_default' => 1,
        ]];

        $rows = $db->fetchAll(
            "SELECT pu.unit_id, pu.factor, pu.is_default, pu.barcode, pu.sale_price,
                    u.code AS unit_code, u.name AS unit_name
             FROM erp_product_units pu
             LEFT JOIN erp_units u ON u.id = pu.unit_id
             WHERE pu.product_id = :id AND pu.is_active = 1
             ORDER BY pu.factor",
            ['id' => $productId]
        );

        $hasDefault = false;
        foreach ($rows as $r) {
            if ((int)$r['is_default'] === 1) { $hasDefault = true; }
            $out[] = [
                'unit_id'    => (int)$r['unit_id'],
                'unit_code'  => $r['unit_code'] ?? '',
                'unit_name'  => $r['unit_name'] ?? '',
                'factor'     => (float)$r['factor'],
                // An alternate with no price of its own is worth its factor
                // times the base price, which is what the operator expects
                // when they add "box = 10" and nothing else.
                'sale_price' => $r['sale_price'] !== null
                    ? (float)$r['sale_price']
                    : round((float)$p['sale_price'] * (float)$r['factor'], 2),
                'barcode'    => $r['barcode'],
                'is_base'    => 0,
                'is_default' => (int)$r['is_default'],
            ];
        }

        // An explicit default on an alternate wins; the base loses the flag.
        if ($hasDefault) {
            $out[0]['is_default'] = 0;
        }

        return $out;
    }

    /**
     * Replace a product's alternate units.
     *
     * @param array $units rows of [unit_id, factor, barcode?, sale_price?, is_default?]
     *                     The base unit must NOT appear here — it lives on the
     *                     product and is always factor 1.
     */
    public static function save(int $productId, array $units, ?int $baseUnitId = null): void
    {
        $db = Database::getInstance();

        $clean = [];
        $seen  = [];
        $defaultSeen = false;

        foreach ($units as $u) {
            $unitId = (int)($u['unit_id'] ?? 0);
            $factor = (float)($u['factor'] ?? 0);

            if ($unitId <= 0 || $factor <= 0) {
                continue;
            }
            if ($baseUnitId !== null && $unitId === $baseUnitId) {
                // The base is implicit at factor 1; storing it again would
                // give the product two rows claiming to be the same unit.
                continue;
            }
            if (isset($seen[$unitId])) {
                throw new RuntimeException('یەکەیەک دووبارە بووەتەوە');
            }
            if (abs($factor - 1.0) < 0.00005) {
                throw new RuntimeException('یەکەی جێگرەوە ناتوانێت فاکتەری ١ بێت — ئەوە یەکەی بنەڕەتییە');
            }
            $seen[$unitId] = true;

            $isDefault = !empty($u['is_default']) && !$defaultSeen;
            if ($isDefault) { $defaultSeen = true; }

            $barcode = trim((string)($u['barcode'] ?? ''));

            $clean[] = [
                'product_id' => $productId,
                'unit_id'    => $unitId,
                'factor'     => round($factor, 4),
                'is_default' => $isDefault ? 1 : 0,
                // '' would collide on the unique index for a second product
                // saved without a pack barcode; NULL repeats freely.
                'barcode'    => $barcode === '' ? null : $barcode,
                'sale_price' => ($u['sale_price'] ?? '') === '' ? null : round((float)$u['sale_price'], 2),
                'is_active'  => 1,
            ];
        }

        $inTx = $db->inTransaction();
        if (!$inTx) { $db->beginTransaction(); }
        try {
            $old = $db->fetchAll(
                "SELECT unit_id, factor, is_default FROM erp_product_units WHERE product_id = :id",
                ['id' => $productId]
            );

            // Replace wholesale rather than diffing: the editor posts the full
            // set every time, and a diff would have to guess at removals.
            $db->query("DELETE FROM erp_product_units WHERE product_id = :id", ['id' => $productId]);
            foreach ($clean as $row) {
                $db->insert('erp_product_units', $row);
            }

            if (!$inTx) { $db->commit(); }

            if ($old || $clean) {
                ErpAudit::log('product_units', 'PRODUCT', $productId, null,
                    ['units' => $old], ['units' => $clean]);
            }

        } catch (PDOException $e) {
            if (!$inTx && $db->inTransaction()) { $db->rollback(); }
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('ئەم بارکۆدە پێشتر بەکارهاتووە');
            }
            throw $e;
        } catch (Throwable $e) {
            if (!$inTx && $db->inTransaction()) { $db->rollback(); }
            throw $e;
        }
    }

    /**
     * The unit a sales grid should open on, and its factor.
     * Falls back to the base unit when nothing is flagged.
     */
    public static function defaultFor(int $productId): array
    {
        foreach (self::forProduct($productId) as $u) {
            if ((int)$u['is_default'] === 1) {
                return $u;
            }
        }
        return ['unit_id' => null, 'factor' => 1.0, 'unit_code' => '', 'sale_price' => 0.0];
    }
}
