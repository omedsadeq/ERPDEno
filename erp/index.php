<?php
/**
 * ERP dashboard.
 *
 * Kept deliberately cheap: every card is one indexed aggregate, and the whole
 * page is a handful of queries with no per-row work. Today's figures filter on
 * indexed date columns (idx_erp_si_date_status, idx_erp_pi_date).
 *
 * Cost and profit are gated behind erp.profit.view, so a receptionist sees
 * turnover and stock counts but never margin.
 */

require_once __DIR__ . '/_bootstrap.php';
erp_require('erp.dashboard');

$pageTitle  = 'کۆگا';
$breadcrumb = [['title' => 'کۆگا']];

$db       = Database::getInstance();
$today    = date('Y-m-d');
$canCost  = erp_can('erp.profit.view');

$sales = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total, COALESCE(SUM(total_cost),0) AS cost
     FROM erp_sales_invoices WHERE invoice_date = :d AND status = 'confirmed'",
    ['d' => $today]
);
$purchases = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total
     FROM erp_purchase_invoices WHERE invoice_date = :d AND status = 'confirmed'",
    ['d' => $today]
);
$salesReturns = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total
     FROM erp_sales_returns WHERE return_date = :d AND status = 'confirmed'",
    ['d' => $today]
);
$purchaseReturns = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total
     FROM erp_purchase_returns WHERE return_date = :d AND status = 'confirmed'",
    ['d' => $today]
);

/*
 * Party balances come from the ledgers, which are the accounting truth.
 * Customer owes us when debit exceeds credit; supplier is the mirror.
 */
$custBal = $db->fetch(
    "SELECT COALESCE(SUM(debit) - SUM(credit), 0) AS bal FROM erp_customer_ledger"
);
$supBal = $db->fetch(
    "SELECT COALESCE(SUM(credit) - SUM(debit), 0) AS bal FROM erp_supplier_ledger"
);

$stockValue = ErpStock::totalStockValue();

$lowStock = $db->fetch(
    "SELECT COUNT(*) AS c
     FROM erp_products p
     LEFT JOIN (SELECT product_id, SUM(quantity) AS qty
                FROM erp_warehouse_product_stock GROUP BY product_id) s ON s.product_id = p.id
     WHERE p.is_active = 1 AND p.min_stock > 0 AND COALESCE(s.qty,0) <= p.min_stock"
);
$warehouseCount = $db->fetch("SELECT COUNT(*) AS c FROM erp_warehouses WHERE is_active = 1");
$productCount   = $db->fetch("SELECT COUNT(*) AS c FROM erp_products WHERE is_active = 1");

$todayProfit = (float)$sales['total'] - (float)$sales['cost'];

/*
 * Every store screen, grouped exactly as the bar at the top of the page and
 * the sidebar group them (erp/_nav_map.php — permissions included), so the
 * three can never disagree about what the module contains.
 */
require_once __DIR__ . '/_nav_map.php';

// مەواد بۆ دکتۆر this month — the store's main job, split by kind.
$issueMonth = null;
if (erp_can('erp.doctor_issue.view')) {
    require_once __DIR__ . '/../classes/ErpDoctorIssue.php';
    try {
        $issueMonth = ErpDoctorIssue::search(['from' => date('Y-m-01'), 'to' => date('Y-m-d')], 1);
    } catch (Throwable $e) {
        $issueMonth = null; // installation still migrating
    }
}

$customCSS = <<<CSS
.erp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.erp-card{background:#fff;border:1px solid #e3e3e3;border-radius:8px;padding:12px 14px}
.erp-card .lbl{font-size:12px;color:#6b6b6b;margin-bottom:6px}
.erp-card .val{font-size:22px;font-weight:700;direction:ltr;text-align:left;font-variant-numeric:tabular-nums}
.erp-card .sub{font-size:11px;color:#9a9a9a;margin-top:3px}
.erp-card.good .val{color:#2e7d32}
.erp-card.bad .val{color:#d32f2f}
.erp-card.warn .val{color:#ed6c02}
.erp-sec{font-size:12px;font-weight:700;color:#6b6b6b;margin:0 0 10px}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($issueMonth !== null): ?>
<div class="erp-sec">مەواد بۆ دکتۆران — ئەم مانگە</div>
<div class="erp-cards" style="margin-bottom:20px">
    <a class="erp-card" href="<?= APP_URL ?>/erp/doctor-issues.php" style="text-decoration:none;color:inherit;border-inline-start:4px solid #0369a1">
        <div class="lbl">کۆی مەواد بۆ دکتۆران</div>
        <div class="val" style="color:#0369a1"><?= erp_money($issueMonth['normal'] + $issueMonth['implant']) ?></div>
        <div class="sub"><?= (int)$issueMonth['count'] ?> وەسڵ</div>
    </a>
    <a class="erp-card" href="<?= APP_URL ?>/erp/doctor-issues.php?kind=normal" style="text-decoration:none;color:inherit;border-inline-start:4px solid #0d9488">
        <div class="lbl">ئاسایی</div>
        <div class="val" style="color:#0d9488"><?= erp_money($issueMonth['normal']) ?></div>
        <div class="sub">خەرجی کۆگا</div>
    </a>
    <a class="erp-card" href="<?= APP_URL ?>/erp/doctor-issues.php?kind=implant" style="text-decoration:none;color:inherit;border-inline-start:4px solid #7c3aed">
        <div class="lbl">ئیمپلانت</div>
        <div class="val" style="color:#7c3aed"><?= erp_money($issueMonth['implant']) ?></div>
        <div class="sub">خەرجی کۆگا</div>
    </a>
</div>
<?php endif; ?>

<?php module_nav_hub(erp_nav_groups(), APP_URL . '/erp/'); ?>

<div class="erp-sec">ئەمڕۆ</div>
<div class="erp-cards">
    <div class="erp-card good">
        <div class="lbl">فرۆشتنی ئەمڕۆ</div>
        <div class="val"><?= erp_money($sales['total']) ?></div>
        <div class="sub"><?= erp_money($sales['cnt']) ?> وەسڵ</div>
    </div>
    <div class="erp-card">
        <div class="lbl">کڕینی ئەمڕۆ</div>
        <div class="val"><?= erp_money($purchases['total']) ?></div>
        <div class="sub"><?= erp_money($purchases['cnt']) ?> وەسڵ</div>
    </div>
    <?php if ($canCost): ?>
    <div class="erp-card <?= $todayProfit >= 0 ? 'good' : 'bad' ?>">
        <div class="lbl">قازانجی ئەمڕۆ</div>
        <div class="val"><?= erp_money($todayProfit) ?></div>
        <div class="sub">فرۆشتن - تێچوو</div>
    </div>
    <?php endif; ?>
    <div class="erp-card warn">
        <div class="lbl">گەڕاندنەوەی فرۆشتن</div>
        <div class="val"><?= erp_money($salesReturns['total']) ?></div>
        <div class="sub"><?= erp_money($salesReturns['cnt']) ?> دانە</div>
    </div>
    <div class="erp-card warn">
        <div class="lbl">گەڕاندنەوەی کڕین</div>
        <div class="val"><?= erp_money($purchaseReturns['total']) ?></div>
        <div class="sub"><?= erp_money($purchaseReturns['cnt']) ?> دانە</div>
    </div>
    <div class="erp-card bad">
        <div class="lbl">قەرزی کڕیارەکان</div>
        <div class="val"><?= erp_money($custBal['bal']) ?></div>
        <div class="sub">ماوە لەسەر کڕیاران</div>
    </div>
    <div class="erp-card bad">
        <div class="lbl">قەرز بۆ دابینکەران</div>
        <div class="val"><?= erp_money($supBal['bal']) ?></div>
        <div class="sub">ماوە لەسەر ئێمە</div>
    </div>
    <?php if ($canCost): ?>
    <div class="erp-card">
        <div class="lbl">نرخی کۆگا</div>
        <div class="val"><?= erp_money($stockValue) ?></div>
        <div class="sub">بڕ × تێچووی ناوەند</div>
    </div>
    <?php endif; ?>
    <div class="erp-card <?= (int)$lowStock['c'] > 0 ? 'warn' : '' ?>">
        <div class="lbl">کاڵای کەم</div>
        <div class="val"><?= erp_money($lowStock['c']) ?></div>
        <div class="sub">لە <?= erp_money($productCount['c']) ?> کاڵا</div>
    </div>
    <div class="erp-card">
        <div class="lbl">کۆگاکان</div>
        <div class="val"><?= erp_money($warehouseCount['c']) ?></div>
        <div class="sub">چالاک</div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
