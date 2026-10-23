<?php
/**
 * ERP — one-time tool: clear every product's جۆر and delete all types, so the
 * clinic can start the type lists again with «+».
 *
 * Before anything is removed, the types and each product's type are copied
 * into erp2_product_types_backup / erp2_products_type_backup, and
 * «گەڕاندنەوە» puts them back.
 *
 * Admin only. Delete this file once it is done.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.products.edit');
if (!$erpAuth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db   = Database::getInstance();
$self = APP_URL . '/erp2/clear-types.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = $_POST['action'] ?? '';

    if ($action === 'clear') {
        // DDL commits implicitly, so the backup tables are made before the transaction.
        $db->query(
            "CREATE TABLE IF NOT EXISTS erp2_product_types_backup (
                id           INT UNSIGNED NOT NULL,
                category_id  INT UNSIGNED NOT NULL,
                name         VARCHAR(80)  NOT NULL,
                sort_order   INT          NOT NULL DEFAULT 0,
                is_active    TINYINT(1)   NOT NULL DEFAULT 1,
                created_at   DATETIME     NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->query(
            "CREATE TABLE IF NOT EXISTS erp2_products_type_backup (
                product_id   INT UNSIGNED NOT NULL,
                type_id      INT UNSIGNED NOT NULL,
                PRIMARY KEY (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->beginTransaction();
        try {
            // INSERT IGNORE: a second run never overwrites the first backup.
            $db->query(
                "INSERT IGNORE INTO erp2_product_types_backup (id, category_id, name, sort_order, is_active, created_at)
                 SELECT id, category_id, name, sort_order, is_active, created_at FROM erp2_product_types"
            );
            $db->query(
                "INSERT IGNORE INTO erp2_products_type_backup (product_id, type_id)
                 SELECT id, type_id FROM erp2_products WHERE type_id IS NOT NULL"
            );
            $products = $db->query("UPDATE erp2_products SET type_id = NULL WHERE type_id IS NOT NULL")->rowCount();
            $types    = $db->query("DELETE FROM erp2_product_types")->rowCount();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            error_log('[clear-types] ' . $e->getMessage());
            header('Location: ' . $self . '?error=1');
            exit;
        }

        Erp2Audit::log('clear_all_types', 'product', null, null, null,
            ['products_cleared' => $products, 'types_deleted' => $types]);
        header('Location: ' . $self . "?cleared={$products}&deleted={$types}");
        exit;
    }

    if ($action === 'restore' && $db->tableExists('erp2_product_types_backup')) {
        $db->beginTransaction();
        try {
            // A type added again since then with the same group + name keeps its new row.
            $types = $db->query(
                "INSERT IGNORE INTO erp2_product_types (id, category_id, name, sort_order, is_active, created_at)
                 SELECT id, category_id, name, sort_order, is_active, COALESCE(created_at, NOW())
                 FROM erp2_product_types_backup"
            )->rowCount();
            // Only products that are still empty, and only to a type that exists again.
            $products = 0;
            if ($db->tableExists('erp2_products_type_backup')) {
                $products = $db->query(
                    "UPDATE erp2_products p
                     JOIN erp2_products_type_backup b ON b.product_id = p.id
                     JOIN erp2_product_types t ON t.id = b.type_id
                     SET p.type_id = b.type_id
                     WHERE p.type_id IS NULL"
                )->rowCount();
                $db->query("DELETE FROM erp2_products_type_backup");
            }
            $db->query("DELETE FROM erp2_product_types_backup");
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            error_log('[clear-types restore] ' . $e->getMessage());
            header('Location: ' . $self . '?error=1');
            exit;
        }
        Erp2Audit::log('restore_all_types', 'product', null, null, null,
            ['types_restored' => $types, 'products_restored' => $products]);
        header('Location: ' . $self . "?restored_types={$types}&restored_products={$products}");
        exit;
    }
}

$typeCount = (int)$db->fetch("SELECT COUNT(*) AS n FROM erp2_product_types")['n'];
$prodCount = (int)$db->fetch("SELECT COUNT(*) AS n FROM erp2_products WHERE type_id IS NOT NULL")['n'];
$byGroup   = $db->fetchAll(
    "SELECT c.name AS category_name, GROUP_CONCAT(t.name ORDER BY t.sort_order, t.name SEPARATOR '، ') AS types
     FROM erp2_product_types t
     LEFT JOIN erp2_categories c ON c.id = t.category_id
     GROUP BY t.category_id, c.name, c.sort_order
     ORDER BY c.sort_order, c.name"
);
$hasBackup = $db->tableExists('erp2_product_types_backup')
    && (int)$db->fetch("SELECT COUNT(*) AS n FROM erp2_product_types_backup")['n'] > 0;

$pageTitle  = 'سڕینەوەی هەموو جۆرەکان';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'کاڵاکان', 'url' => APP_URL . '/erp2/products.php'],
    ['title' => 'سڕینەوەی جۆرەکان'],
];

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['cleared'])): ?>
<div class="alert alert-success"><i class="fas fa-check-circle alert-icon"></i>
    جۆری <?= (int)$_GET['cleared'] ?> کاڵا بەتاڵ کرایەوە و <?= (int)$_GET['deleted'] ?> جۆر سڕایەوە.
    <a href="<?= APP_URL ?>/erp2/products.php">بڕۆ بۆ کاڵاکان</a> و بە «+» جۆری نوێ زیاد بکە.
</div>
<?php endif; ?>
<?php if (isset($_GET['restored_types'])): ?>
<div class="alert alert-success"><i class="fas fa-undo alert-icon"></i>
    <?= (int)$_GET['restored_types'] ?> جۆر و جۆری <?= (int)$_GET['restored_products'] ?> کاڵا گەڕێندرانەوە.
</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle alert-icon"></i> هەڵەیەک ڕوویدا — هیچ گۆڕانکارییەک نەکرا.</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-body">
        <h4 style="margin-top:0">سڕینەوەی هەموو جۆرەکان</h4>
        <p style="font-size:14px">
            <b><?= $typeCount ?></b> جۆر هەیە، و <b><?= $prodCount ?></b> کاڵا جۆریان بۆ دانراوە.
            <br>ئەم کارە «جۆر»ی هەموو کاڵاکان بەتاڵ دەکاتەوە و هەموو جۆرەکان دەسڕێتەوە. پاشان بە «+» جۆری نوێ زیاد دەکەیت.
        </p>
        <p class="erp-muted" style="font-size:12.5px">
            ئەگەر دەتەوێت جۆرەکان سەرەتا بکرێن بە براند،
            <a href="<?= APP_URL ?>/erp2/type-to-brand.php">پێشتر ئەم پەڕەیە بەکاربهێنە</a>.
        </p>

        <?php if ($byGroup): ?>
        <div class="table-container" style="margin:12px 0">
            <table class="data-grid no-responsive" style="font-size:13px">
                <thead><tr><th style="width:160px">گرووپ</th><th>ئەو جۆرانەی دەسڕدرێنەوە</th></tr></thead>
                <tbody>
                    <?php foreach ($byGroup as $g): ?>
                    <tr>
                        <td><?= erp2_esc($g['category_name'] ?? '—') ?></td>
                        <td><?= erp2_esc($g['types']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($typeCount || $prodCount): ?>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('دڵنیایت؟ <?= $typeCount ?> جۆر دەسڕدرێتەوە و جۆری <?= $prodCount ?> کاڵا بەتاڵ دەکرێتەوە.')">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> سڕینەوەی هەموو جۆرەکان</button>
        </form>
        <?php else: ?>
        <p class="erp-muted">هیچ جۆرێک نەماوە.</p>
        <?php endif; ?>

        <?php if ($hasBackup): ?>
        <form method="POST" style="display:inline;margin-inline-start:8px"
              onsubmit="return confirm('جۆرە کۆنەکان و جۆری کاڵاکان دەگەڕێنرێنەوە. دڵنیایت؟')">
            <input type="hidden" name="action" value="restore">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-undo"></i> گەڕاندنەوەی جۆرە کۆنەکان</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
