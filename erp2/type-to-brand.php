<?php
/**
 * ERP — one-time tool: move each product's جۆر (type) into its براند (brand).
 *
 * For every product that has a type, the brand with the type's name is used
 * (created when missing) and set as the product's brand; optionally the type
 * is then emptied. The products' original type_id/brand_id are copied into
 * erp2_products_type_brand_backup first, so «گەڕاندنەوە» can undo it.
 *
 * Admin only. Delete this file once the move is done.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.products.edit');
if (!$erpAuth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db   = Database::getInstance();
$self = APP_URL . '/erp2/type-to-brand.php';

/** Products that have a type, with the type's name and their current brand. */
function ttb_rows(Database $db): array
{
    return $db->fetchAll(
        "SELECT p.id, p.product_code, p.name, p.type_id, p.brand_id,
                c.name AS category_name, t.name AS type_name, br.name AS brand_name
         FROM erp2_products p
         JOIN erp2_product_types t ON t.id = p.type_id
         LEFT JOIN erp2_categories c ON c.id = p.category_id
         LEFT JOIN erp2_brands br ON br.id = p.brand_id
         ORDER BY c.sort_order, c.name, t.name, p.name"
    );
}

/** Brand names compare like the collation does: trimmed, case-insensitive. */
function ttb_same(?string $a, ?string $b): bool
{
    return mb_strtolower(trim((string)$a)) === mb_strtolower(trim((string)$b));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = $_POST['action'] ?? '';

    if ($action === 'apply') {
        $clearType = !empty($_POST['clear_type']);
        $overwrite = !empty($_POST['overwrite_brand']);

        // DDL commits implicitly, so the backup table is made before the transaction.
        $db->query(
            "CREATE TABLE IF NOT EXISTS erp2_products_type_brand_backup (
                product_id  INT UNSIGNED NOT NULL,
                type_id     INT UNSIGNED NULL,
                brand_id    INT UNSIGNED NULL,
                backed_up_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $moved = 0;
        $skipped = 0;
        $created = 0;
        $db->beginTransaction();
        try {
            $brandIds = [];   // lower-cased name => brand id
            foreach (ttb_rows($db) as $r) {
                $hasOtherBrand = $r['brand_id'] !== null && !ttb_same($r['brand_name'], $r['type_name']);
                if ($hasOtherBrand && !$overwrite) {
                    $skipped++;
                    continue;
                }

                $name = trim($r['type_name']);
                $key  = mb_strtolower($name);
                if (!isset($brandIds[$key])) {
                    $brand = $db->fetch("SELECT id, is_active FROM erp2_brands WHERE name = :n ORDER BY is_active DESC, id LIMIT 1", ['n' => $name]);
                    if ($brand) {
                        if (!$brand['is_active']) {
                            $db->update('erp2_brands', ['is_active' => 1], 'id = :id', ['id' => $brand['id']]);
                        }
                        $brandIds[$key] = (int)$brand['id'];
                    } else {
                        $brandIds[$key] = (int)$db->insert('erp2_brands', ['name' => $name, 'is_active' => 1]);
                        $created++;
                    }
                }

                // First backup wins: a second run never overwrites the original values.
                $db->query(
                    "INSERT IGNORE INTO erp2_products_type_brand_backup (product_id, type_id, brand_id)
                     VALUES (:p, :t, :b)",
                    ['p' => $r['id'], 't' => $r['type_id'], 'b' => $r['brand_id']]
                );

                $set = ['brand_id' => $brandIds[$key]];
                if ($clearType) {
                    $set['type_id'] = null;
                }
                $db->update('erp2_products', $set, 'id = :id', ['id' => $r['id']]);
                $moved++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            error_log('[type-to-brand] ' . $e->getMessage());
            header('Location: ' . $self . '?error=1');
            exit;
        }

        Erp2Audit::log('type_to_brand', 'product', null, null, null,
            ['moved' => $moved, 'skipped' => $skipped, 'brands_created' => $created, 'type_cleared' => $clearType]);
        header('Location: ' . $self . "?moved={$moved}&skipped={$skipped}&created={$created}");
        exit;
    }

    if ($action === 'restore' && $db->tableExists('erp2_products_type_brand_backup')) {
        $n = $db->query(
            "UPDATE erp2_products p
             JOIN erp2_products_type_brand_backup b ON b.product_id = p.id
             SET p.type_id = b.type_id, p.brand_id = b.brand_id"
        )->rowCount();
        $db->query("DELETE FROM erp2_products_type_brand_backup");
        Erp2Audit::log('type_to_brand_restore', 'product', null, null, null, ['restored' => $n]);
        header('Location: ' . $self . "?restored={$n}");
        exit;
    }
}

$rows       = ttb_rows($db);
$hasBackup  = $db->tableExists('erp2_products_type_brand_backup')
    && (int)$db->fetch("SELECT COUNT(*) AS n FROM erp2_products_type_brand_backup")['n'] > 0;
$existing   = [];
foreach ($db->fetchAll("SELECT name FROM erp2_brands") as $b) {
    $existing[mb_strtolower(trim($b['name']))] = true;
}
$conflicts = 0;
foreach ($rows as $r) {
    if ($r['brand_id'] !== null && !ttb_same($r['brand_name'], $r['type_name'])) {
        $conflicts++;
    }
}

$pageTitle  = 'گواستنەوەی جۆر بۆ براند';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'کاڵاکان', 'url' => APP_URL . '/erp2/products.php'],
    ['title' => 'جۆر ← براند'],
];
$customCSS = <<<CSS
.ttb-grid{font-size:13px}
.ttb-grid td,.ttb-grid th{padding:6px 10px}
.ttb-tag{display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:999px;background:#e0f2fe;color:#0369a1}
.ttb-new{background:#e8f5e9;color:#2e7d32}
.ttb-warn{background:#fff4ce;color:#9d5d00}
.ttb-opts{display:flex;flex-direction:column;gap:8px;margin:12px 0}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['moved'])): ?>
<div class="alert alert-success"><i class="fas fa-check-circle alert-icon"></i>
    <?= (int)$_GET['moved'] ?> کاڵا گواسترایەوە، <?= (int)$_GET['created'] ?> براندی نوێ دروستکرا<?= (int)$_GET['skipped'] ? '، ' . (int)$_GET['skipped'] . ' کاڵا تێپەڕێندرا (براندی تری هەبوو)' : '' ?>.
</div>
<?php endif; ?>
<?php if (isset($_GET['restored'])): ?>
<div class="alert alert-success"><i class="fas fa-undo alert-icon"></i> <?= (int)$_GET['restored'] ?> کاڵا گەڕێندرایەوە بۆ پێشوو.</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle alert-icon"></i> هەڵەیەک ڕوویدا — هیچ گۆڕانکارییەک نەکرا.</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-body">
        <h4 style="margin-top:0">گواستنەوەی «جۆر» بۆ «براند» بۆ هەموو کاڵاکان</h4>
        <p class="erp-muted" style="font-size:13px">
            <?= count($rows) ?> کاڵا جۆریان هەیە.
            <?php if ($conflicts): ?><br><span class="ttb-tag ttb-warn"><?= $conflicts ?> کاڵا پێشتر براندێکی تریان هەیە</span><?php endif; ?>
        </p>

        <?php if ($rows): ?>
        <form method="POST" onsubmit="return confirm('دڵنیایت؟ <?= count($rows) ?> کاڵا دەگۆڕدرێت.')">
            <input type="hidden" name="action" value="apply">
            <div class="ttb-opts">
                <label class="form-check" style="margin:0">
                    <input type="checkbox" name="clear_type" value="1" checked>
                    دوای گواستنەوە «جۆر» بەتاڵ بکرێتەوە (ئەگەر لاببرێت، جۆر وەک خۆی دەمێنێت و تەنها براند دادەنرێت)
                </label>
                <?php if ($conflicts): ?>
                <label class="form-check" style="margin:0">
                    <input type="checkbox" name="overwrite_brand" value="1">
                    براندی ئەو <?= $conflicts ?> کاڵایەش بگۆڕە کە پێشتر براندی تریان هەیە
                </label>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-exchange-alt"></i> گواستنەوە</button>
        </form>
        <?php endif; ?>

        <?php if ($hasBackup): ?>
        <form method="POST" style="margin-top:10px" onsubmit="return confirm('هەموو کاڵاکان دەگەڕێنرێنەوە بۆ جۆر و براندی پێشوویان. دڵنیایت؟')">
            <input type="hidden" name="action" value="restore">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-undo"></i> گەڕاندنەوە بۆ پێش گواستنەوە</button>
        </form>
        <?php endif; ?>

        <?php if ($rows): ?>
        <div class="table-container" style="margin-top:16px">
            <table class="data-grid ttb-grid no-responsive">
                <thead>
                    <tr>
                        <th>کۆد</th>
                        <th>ناو</th>
                        <th>گرووپ</th>
                        <th>جۆر ← براندی نوێ</th>
                        <th>براندی ئێستا</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $isNew   = !isset($existing[mb_strtolower(trim($r['type_name']))]);
                        $isOther = $r['brand_id'] !== null && !ttb_same($r['brand_name'], $r['type_name']);
                    ?>
                    <tr>
                        <td><?= erp2_esc($r['product_code']) ?></td>
                        <td><?= erp2_esc($r['name']) ?></td>
                        <td><?= erp2_esc($r['category_name'] ?? '—') ?></td>
                        <td>
                            <span class="ttb-tag <?= $isNew ? 'ttb-new' : '' ?>"><?= erp2_esc($r['type_name']) ?></span>
                            <?php if ($isNew): ?><span class="erp-muted" style="font-size:11px">(براندی نوێ)</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isOther): ?>
                            <span class="ttb-tag ttb-warn"><?= erp2_esc($r['brand_name']) ?></span>
                            <?php else: ?>
                            <span class="erp-muted"><?= $r['brand_name'] !== null ? erp2_esc($r['brand_name']) : '—' ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
