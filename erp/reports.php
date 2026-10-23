<?php
/**
 * ERP — reports and profit & loss.
 *
 * PROFIT COMES FROM STORED COST, NEVER FROM TODAY'S PURCHASE PRICE.
 * erp_sales_items.line_cost and line_profit were written at the moment of sale
 * from the Weighted Average Cost consumed then, so every figure below is a
 * plain indexed SUM. Re-deriving cost at report time would make a re-priced
 * product silently rewrite last month's profit — the one thing the brief is
 * explicit about avoiding.
 *
 * Every query is date-bounded and grouped in SQL; nothing iterates rows in PHP
 * and nothing loads a transaction list into the page.
 */

require_once __DIR__ . '/_bootstrap.php';
erp_require('erp.reports.view');

$pageTitle  = 'ڕاپۆرتەکان';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'ڕاپۆرتەکان'],
];

$db      = Database::getInstance();
$canCost = erp_can('erp.profit.view');

$from = erp_date($_GET['from'] ?? null) ?: date('Y-m-01');
$to   = erp_date($_GET['to'] ?? null)   ?: date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }

$whId = (int)($_GET['warehouse_id'] ?? 0);
$whSql = $whId > 0 ? ' AND si.warehouse_id = :wh' : '';
$whP   = $whId > 0 ? ['wh' => $whId] : [];
$range = ['f' => $from, 't' => $to];

/* ── headline figures ──────────────────────────────────────────────── */
$sales = $db->fetch(
    "SELECT COUNT(*) cnt, COALESCE(SUM(grand_total),0) total,
            COALESCE(SUM(total_cost),0) cost, COALESCE(SUM(discount_amount),0) disc
     FROM erp_sales_invoices si
     WHERE si.invoice_date BETWEEN :f AND :t AND si.status = 'confirmed'{$whSql}",
    $range + $whP
);
$purch = $db->fetch(
    "SELECT COUNT(*) cnt, COALESCE(SUM(grand_total),0) total
     FROM erp_purchase_invoices si
     WHERE si.invoice_date BETWEEN :f AND :t AND si.status = 'confirmed'{$whSql}",
    $range + $whP
);
$sRet = $db->fetch(
    "SELECT COUNT(*) cnt, COALESCE(SUM(grand_total),0) total, COALESCE(SUM(total_cost),0) cost
     FROM erp_sales_returns si
     WHERE si.return_date BETWEEN :f AND :t AND si.status = 'confirmed'{$whSql}",
    $range + $whP
);
$pRet = $db->fetch(
    "SELECT COUNT(*) cnt, COALESCE(SUM(grand_total),0) total
     FROM erp_purchase_returns si
     WHERE si.return_date BETWEEN :f AND :t AND si.status = 'confirmed'{$whSql}",
    $range + $whP
);

$grossSales  = (float)$sales['total'];
$grossCost   = (float)$sales['cost'];
$returnValue = (float)$sRet['total'];
$returnCost  = (float)$sRet['cost'];
// A returned item gives back both its revenue and its cost, so net profit is
// the difference of the two differences — not revenue minus returns minus cost.
$netSales  = $grossSales - $returnValue;
$netCost   = $grossCost - $returnCost;
$netProfit = $netSales - $netCost;
$margin    = $netSales > 0 ? ($netProfit / $netSales) * 100 : 0;

/* ── breakdowns (all grouped in SQL, all LIMITed) ──────────────────── */
$byProduct = $canCost ? $db->fetchAll(
    "SELECT p.name, p.product_code,
            SUM(it.quantity) qty, SUM(it.line_total) revenue,
            SUM(it.line_cost) cost, SUM(it.line_profit) profit
     FROM erp_sales_items it
     JOIN erp_sales_invoices si ON si.id = it.invoice_id
     JOIN erp_products p ON p.id = it.product_id
     WHERE si.invoice_date BETWEEN :f AND :t AND si.status = 'confirmed'{$whSql}
     GROUP BY it.product_id, p.name, p.product_code
     ORDER BY profit DESC
     LIMIT 15",
    $range + $whP
) : [];

$byCustomer = $db->fetchAll(
    "SELECT c.name, COUNT(*) invoices, SUM(si.grand_total) revenue, SUM(si.total_cost) cost
     FROM erp_sales_invoices si
     JOIN erp_customers c ON c.id = si.customer_id
     WHERE si.invoice_date BETWEEN :f AND :t AND si.status = 'confirmed'{$whSql}
     GROUP BY si.customer_id, c.name
     ORDER BY revenue DESC
     LIMIT 15",
    $range + $whP
);

$byDay = $db->fetchAll(
    "SELECT si.invoice_date d, COUNT(*) cnt,
            SUM(si.grand_total) revenue, SUM(si.total_cost) cost
     FROM erp_sales_invoices si
     WHERE si.invoice_date BETWEEN :f AND :t AND si.status = 'confirmed'{$whSql}
     GROUP BY si.invoice_date
     ORDER BY si.invoice_date DESC
     LIMIT 31",
    $range + $whP
);

/* ── receivables / payables (position, not period) ─────────────────── */
$recv = $db->fetch("SELECT COALESCE(SUM(debit)-SUM(credit),0) v FROM erp_customer_ledger");
$paya = $db->fetch("SELECT COALESCE(SUM(credit)-SUM(debit),0) v FROM erp_supplier_ledger");
$stockValue = ErpStock::totalStockValue($whId > 0 ? $whId : null);

/* ── stock warnings ────────────────────────────────────────────────── */
$lowStock = $db->fetchAll(
    "SELECT p.name, p.product_code, p.min_stock, COALESCE(s.qty,0) qty
     FROM erp_products p
     LEFT JOIN (SELECT product_id, SUM(quantity) qty FROM erp_warehouse_product_stock GROUP BY product_id) s
            ON s.product_id = p.id
     WHERE p.is_active = 1 AND p.min_stock > 0 AND COALESCE(s.qty,0) <= p.min_stock
     ORDER BY (COALESCE(s.qty,0) - p.min_stock)
     LIMIT 15"
);
$expiring = $db->fetchAll(
    "SELECT name, product_code, expiry_date
     FROM erp_products
     WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL 60 DAY
     ORDER BY expiry_date
     LIMIT 15"
);

$warehouses = erp_warehouses();

$customCSS = <<<CSS
.rf{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px;margin-bottom:12px}
.rf label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.rf .form-control{height:32px;font-size:13px;padding:3px 8px}
.rc{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin-bottom:14px}
.rcard{background:#fff;border:1px solid #e3e3e3;border-radius:8px;padding:10px 12px}
.rcard .l{font-size:11.5px;color:#6b6b6b}
.rcard .v{font-size:20px;font-weight:700;direction:ltr;text-align:left;font-variant-numeric:tabular-nums}
.rcard .s{font-size:10.5px;color:#9a9a9a;margin-top:2px}
.good .v{color:#2e7d32}.bad .v{color:#d32f2f}.warn .v{color:#ed6c02}
.rgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.rsec{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px}
.rsec h4{margin:0 0 8px;font-size:14px}
table.rt{width:100%;border-collapse:collapse;font-size:12.5px}
table.rt th{background:#eef2f5;padding:6px 8px;text-align:right;font-size:11.5px;border-bottom:2px solid #c8d3da}
table.rt td{border-bottom:1px solid #f0f0f0;padding:5px 8px}
.n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.muted{color:#9e9e9e}
.pl{max-width:520px}
.pl .row{display:flex;justify-content:space-between;padding:5px 0;font-size:13.5px}
.pl .row.sub{border-top:1px solid #e8e8e8;margin-top:4px;padding-top:8px;font-weight:600}
.pl .row.fin{border-top:2px solid #333;margin-top:6px;padding-top:9px;font-size:18px;font-weight:800}
.pl .v{direction:ltr;font-variant-numeric:tabular-nums}
@media(max-width:900px){.rgrid{grid-template-columns:1fr}}
@media print{.no-print,.nav-pane,.titlebar,.page-header,.statusbar{display:none!important}
 body,.content-area,.page-content{background:#fff!important;margin:0!important;padding:0!important}}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="rf no-print">
    <form method="GET" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <div><label>لە</label><input type="date" name="from" class="form-control" value="<?= erp_esc($from) ?>"></div>
        <div><label>بۆ</label><input type="date" name="to" class="form-control" value="<?= erp_esc($to) ?>"></div>
        <div>
            <label>کۆگا</label>
            <select name="warehouse_id" class="form-control" style="max-width:170px">
                <option value="0">هەموو</option>
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['id'] ?>" <?= $whId === (int)$w['id'] ? 'selected' : '' ?>>
                    <?= erp_esc($w['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> پیشاندان</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> چاپ</button>
    </form>
</div>

<div class="rc">
    <div class="rcard good"><div class="l">فرۆشتن</div><div class="v"><?= erp_money($grossSales) ?></div>
        <div class="s"><?= erp_money($sales['cnt']) ?> وەسڵ</div></div>
    <div class="rcard"><div class="l">کڕین</div><div class="v"><?= erp_money($purch['total']) ?></div>
        <div class="s"><?= erp_money($purch['cnt']) ?> وەسڵ</div></div>
    <div class="rcard warn"><div class="l">گەڕاندنەوەی فرۆشتن</div><div class="v"><?= erp_money($returnValue) ?></div>
        <div class="s"><?= erp_money($sRet['cnt']) ?> دانە</div></div>
    <div class="rcard warn"><div class="l">گەڕاندنەوەی کڕین</div><div class="v"><?= erp_money($pRet['total']) ?></div>
        <div class="s"><?= erp_money($pRet['cnt']) ?> دانە</div></div>
    <?php if ($canCost): ?>
    <div class="rcard <?= $netProfit >= 0 ? 'good' : 'bad' ?>">
        <div class="l">قازانجی پاک</div><div class="v"><?= erp_money($netProfit) ?></div>
        <div class="s"><?= number_format($margin, 1) ?>% ڕێژە</div></div>
    <div class="rcard"><div class="l">نرخی کۆگا</div><div class="v"><?= erp_money($stockValue) ?></div>
        <div class="s">ئێستا</div></div>
    <?php endif; ?>
    <div class="rcard bad"><div class="l">قەرزی کڕیاران</div><div class="v"><?= erp_money($recv['v']) ?></div>
        <div class="s">ئێستا</div></div>
    <div class="rcard bad"><div class="l">قەرز بۆ دابینکەران</div><div class="v"><?= erp_money($paya['v']) ?></div>
        <div class="s">ئێستا</div></div>
</div>

<?php if ($canCost): ?>
<div class="rsec" style="margin-bottom:12px">
    <h4>قازانج و زیان — <?= erp_esc($from) ?> بۆ <?= erp_esc($to) ?></h4>
    <div class="pl">
        <div class="row"><span>کۆی فرۆشتن</span><span class="v"><?= erp_money($grossSales) ?></span></div>
        <div class="row"><span>گەڕاندنەوەی فرۆشتن</span><span class="v">- <?= erp_money($returnValue) ?></span></div>
        <div class="row sub"><span>فرۆشتنی پاک</span><span class="v"><?= erp_money($netSales) ?></span></div>
        <div class="row"><span>تێچووی کاڵای فرۆشراو</span><span class="v">- <?= erp_money($grossCost) ?></span></div>
        <div class="row"><span>تێچووی گەڕاوە</span><span class="v">+ <?= erp_money($returnCost) ?></span></div>
        <div class="row sub"><span>تێچووی پاک</span><span class="v"><?= erp_money($netCost) ?></span></div>
        <div class="row fin"><span>قازانجی پاک</span>
            <span class="v" style="color:<?= $netProfit >= 0 ? '#2e7d32' : '#c62828' ?>"><?= erp_money($netProfit) ?></span></div>
        <div class="row muted" style="font-size:11.5px">
            <span>داشکاندنی دراو لەم ماوەیەدا</span><span class="v"><?= erp_money($sales['disc']) ?></span></div>
    </div>
    <div class="muted" style="font-size:11.5px;margin-top:8px">
        تێچوو لە کاتی فرۆشتن تۆمارکراوە (تێچووی ناوەندی کێشدار) — گۆڕینی نرخی کڕین قازانجی ڕابردوو ناگۆڕێت.
    </div>
</div>
<?php endif; ?>

<div class="rgrid">
    <?php if ($canCost): ?>
    <div class="rsec">
        <h4>قازانج بەپێی کاڵا</h4>
        <table class="rt">
            <thead><tr><th>کاڵا</th><th class="n">بڕ</th><th class="n">داهات</th><th class="n">قازانج</th></tr></thead>
            <tbody>
            <?php foreach ($byProduct as $r): ?>
                <tr><td><?= erp_esc($r['name']) ?></td>
                    <td class="n"><?= erp_qty($r['qty']) ?></td>
                    <td class="n"><?= erp_money($r['revenue']) ?></td>
                    <td class="n" style="color:<?= (float)$r['profit'] >= 0 ? '#2e7d32' : '#c62828' ?>">
                        <?= erp_money($r['profit']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byProduct): ?><tr><td colspan="4" class="muted" style="padding:14px">هیچ فرۆشتنێک نییە</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="rsec">
        <h4>فرۆشتن بەپێی کڕیار</h4>
        <table class="rt">
            <thead><tr><th>کڕیار</th><th class="n">وەسڵ</th><th class="n">داهات</th><?php if ($canCost): ?><th class="n">قازانج</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($byCustomer as $r): ?>
                <tr><td><?= erp_esc($r['name']) ?></td>
                    <td class="n"><?= erp_money($r['invoices']) ?></td>
                    <td class="n"><?= erp_money($r['revenue']) ?></td>
                    <?php if ($canCost): ?>
                    <td class="n"><?= erp_money((float)$r['revenue'] - (float)$r['cost']) ?></td>
                    <?php endif; ?></tr>
            <?php endforeach; ?>
            <?php if (!$byCustomer): ?><tr><td colspan="4" class="muted" style="padding:14px">هیچ فرۆشتنێک نییە</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="rsec">
        <h4>فرۆشتنی ڕۆژانە</h4>
        <table class="rt">
            <thead><tr><th>بەروار</th><th class="n">وەسڵ</th><th class="n">داهات</th><?php if ($canCost): ?><th class="n">قازانج</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($byDay as $r): ?>
                <tr><td dir="ltr"><?= erp_esc($r['d']) ?></td>
                    <td class="n"><?= erp_money($r['cnt']) ?></td>
                    <td class="n"><?= erp_money($r['revenue']) ?></td>
                    <?php if ($canCost): ?>
                    <td class="n"><?= erp_money((float)$r['revenue'] - (float)$r['cost']) ?></td>
                    <?php endif; ?></tr>
            <?php endforeach; ?>
            <?php if (!$byDay): ?><tr><td colspan="4" class="muted" style="padding:14px">هیچ فرۆشتنێک نییە</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="rsec">
        <h4>کاڵای کەم</h4>
        <table class="rt">
            <thead><tr><th>کاڵا</th><th class="n">بەردەست</th><th class="n">کەمترین</th></tr></thead>
            <tbody>
            <?php foreach ($lowStock as $r): ?>
                <tr><td><?= erp_esc($r['name']) ?></td>
                    <td class="n" style="color:#c62828;font-weight:700"><?= erp_qty($r['qty']) ?></td>
                    <td class="n muted"><?= erp_qty($r['min_stock']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$lowStock): ?><tr><td colspan="3" class="muted" style="padding:14px">هەموو کاڵاکان بەردەستن</td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php if ($expiring): ?>
        <h4 style="margin-top:14px">بەسەرچوون لە ٦٠ ڕۆژدا</h4>
        <table class="rt">
            <thead><tr><th>کاڵا</th><th class="n">بەروار</th></tr></thead>
            <tbody>
            <?php foreach ($expiring as $r): ?>
                <tr><td><?= erp_esc($r['name']) ?></td>
                    <td class="n" style="color:#ed6c02"><?= erp_esc($r['expiry_date']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
