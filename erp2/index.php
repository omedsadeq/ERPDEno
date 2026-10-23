<?php
/**
 * ERP dashboard.
 *
 * Kept deliberately cheap: every card is one indexed aggregate, and the whole
 * page is a handful of queries with no per-row work. Today's figures filter on
 * indexed date columns (idx_erp2_si_date_status, idx_erp2_pi_date).
 *
 * Cost and profit are gated behind erp2.profit.view, so a receptionist sees
 * turnover and stock counts but never margin.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.dashboard');

$pageTitle  = 'دەرمانخانە';
$breadcrumb = [['title' => 'دەرمانخانە']];

$db       = Database::getInstance();
$today    = date('Y-m-d');
$canCost  = erp2_can('erp2.profit.view');

$sales = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total, COALESCE(SUM(total_cost),0) AS cost
     FROM erp2_sales_invoices WHERE invoice_date = :d AND status = 'confirmed'",
    ['d' => $today]
);
$purchases = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total
     FROM erp2_purchase_invoices WHERE invoice_date = :d AND status = 'confirmed'",
    ['d' => $today]
);
$salesReturns = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total
     FROM erp2_sales_returns WHERE return_date = :d AND status = 'confirmed'",
    ['d' => $today]
);
$purchaseReturns = $db->fetch(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total
     FROM erp2_purchase_returns WHERE return_date = :d AND status = 'confirmed'",
    ['d' => $today]
);

/*
 * Party balances come from the ledgers, which are the accounting truth.
 * Customer owes us when debit exceeds credit; supplier is the mirror.
 */
$custBal = $db->fetch(
    "SELECT COALESCE(SUM(debit) - SUM(credit), 0) AS bal FROM erp2_customer_ledger"
);
$supBal = $db->fetch(
    "SELECT COALESCE(SUM(credit) - SUM(debit), 0) AS bal FROM erp2_supplier_ledger"
);

$stockValue = Erp2Stock::totalStockValue();

$lowStock = $db->fetch(
    "SELECT COUNT(*) AS c
     FROM erp2_products p
     LEFT JOIN (SELECT product_id, SUM(quantity) AS qty
                FROM erp2_warehouse_product_stock GROUP BY product_id) s ON s.product_id = p.id
     WHERE p.is_active = 1 AND p.min_stock > 0 AND COALESCE(s.qty,0) <= p.min_stock"
);
$warehouseCount = $db->fetch("SELECT COUNT(*) AS c FROM erp2_warehouses WHERE is_active = 1");
$productCount   = $db->fetch("SELECT COUNT(*) AS c FROM erp2_products WHERE is_active = 1");

$todayProfit = (float)$sales['total'] - (float)$sales['cost'];

/*
 * Every pharmacy screen, grouped exactly as the bar at the top of the page and
 * the sidebar group them (erp2/_nav_map.php — permissions included), so the
 * three can never disagree about what the module contains.
 */
require_once __DIR__ . '/_nav_map.php';
$pendingRx = erp2_nav_pending_prescriptions();

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

<?php module_nav_hub(erp2_nav_groups(), APP_URL . '/erp2/', ['page:prescriptions' => $pendingRx]); ?>

<div class="erp-sec">ئەمڕۆ</div>
<div class="erp-cards">
    <div class="erp-card good">
        <div class="lbl">فرۆشتنی ئەمڕۆ</div>
        <div class="val"><?= erp2_money($sales['total']) ?></div>
        <div class="sub"><?= erp2_money($sales['cnt']) ?> وەسڵ</div>
    </div>
    <div class="erp-card">
        <div class="lbl">کڕینی ئەمڕۆ</div>
        <div class="val"><?= erp2_money($purchases['total']) ?></div>
        <div class="sub"><?= erp2_money($purchases['cnt']) ?> وەسڵ</div>
    </div>
    <?php if ($canCost): ?>
    <div class="erp-card <?= $todayProfit >= 0 ? 'good' : 'bad' ?>">
        <div class="lbl">قازانجی ئەمڕۆ</div>
        <div class="val"><?= erp2_money($todayProfit) ?></div>
        <div class="sub">فرۆشتن - تێچوو</div>
    </div>
    <?php endif; ?>
    <div class="erp-card warn">
        <div class="lbl">گەڕاندنەوەی فرۆشتن</div>
        <div class="val"><?= erp2_money($salesReturns['total']) ?></div>
        <div class="sub"><?= erp2_money($salesReturns['cnt']) ?> دانە</div>
    </div>
    <div class="erp-card warn">
        <div class="lbl">گەڕاندنەوەی کڕین</div>
        <div class="val"><?= erp2_money($purchaseReturns['total']) ?></div>
        <div class="sub"><?= erp2_money($purchaseReturns['cnt']) ?> دانە</div>
    </div>
    <div class="erp-card bad">
        <div class="lbl">قەرزی کڕیارەکان</div>
        <div class="val"><?= erp2_money($custBal['bal']) ?></div>
        <div class="sub">ماوە لەسەر کڕیاران</div>
    </div>
    <div class="erp-card bad">
        <div class="lbl">قەرز بۆ دابینکەران</div>
        <div class="val"><?= erp2_money($supBal['bal']) ?></div>
        <div class="sub">ماوە لەسەر ئێمە</div>
    </div>
    <?php if ($canCost): ?>
    <div class="erp-card">
        <div class="lbl">نرخی کۆگا</div>
        <div class="val"><?= erp2_money($stockValue) ?></div>
        <div class="sub">بڕ × تێچووی ناوەند</div>
    </div>
    <?php endif; ?>
    <div class="erp-card <?= (int)$lowStock['c'] > 0 ? 'warn' : '' ?>">
        <div class="lbl">کاڵای کەم</div>
        <div class="val"><?= erp2_money($lowStock['c']) ?></div>
        <div class="sub">لە <?= erp2_money($productCount['c']) ?> کاڵا</div>
    </div>
    <div class="erp-card">
        <div class="lbl">کۆگاکان</div>
        <div class="val"><?= erp2_money($warehouseCount['c']) ?></div>
        <div class="sub">چالاک</div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
