<?php
/**
 * ERP — printable sales invoice.
 *
 * Opened by the till's Ctrl+P. Deliberately a standalone print sheet rather
 * than a page inside the app shell: it auto-prints on load and closes itself,
 * so the cashier's flow is scan → Ctrl+P → hand over the paper.
 *
 * Cost and profit are never rendered here, whatever the viewer's permissions —
 * this document goes to the customer.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpSales.php';

erp_require('erp.sales.view');

$invoiceId = (int)($_GET['id'] ?? 0);
$invoice   = ErpSales::load($invoiceId);
if (!$invoice) {
    http_response_code(404);
    exit('وەسڵ نەدۆزرایەوە');
}

$autoprint = ($_GET['autoprint'] ?? '1') !== '0';
$remaining = max(0, (float)$invoice['grand_total'] - (float)$invoice['paid_amount']);
?><!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>وەسڵ <?= erp_esc($invoice['invoice_number']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
    *{box-sizing:border-box}
    body{font-family:'NRT',Tahoma,Arial,sans-serif;margin:0;padding:14px;background:#f0f0f0;color:#111}
    .sheet{max-width:80mm;margin:0 auto;background:#fff;padding:12px 14px}
    .hd{text-align:center;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:8px}
    .hd h1{margin:0;font-size:16px}
    .hd .sub{font-size:11px;color:#555;margin-top:2px}
    .meta{font-size:11.5px;line-height:1.9;margin-bottom:8px}
    .meta b{display:inline-block;min-width:64px}
    table{width:100%;border-collapse:collapse;font-size:11.5px}
    th{border-bottom:1px solid #000;padding:4px 2px;text-align:right;font-size:11px}
    td{border-bottom:1px dotted #bbb;padding:4px 2px;vertical-align:top}
    .n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
    .tot{margin-top:8px;border-top:2px solid #000;padding-top:6px;font-size:12px}
    .tot .r{display:flex;justify-content:space-between;padding:2px 0}
    .tot .grand{font-size:15px;font-weight:800;border-top:1px solid #000;margin-top:4px;padding-top:5px}
    .due{color:#c00;font-weight:800}
    .ft{margin-top:10px;text-align:center;font-size:10.5px;color:#666;border-top:1px dashed #999;padding-top:6px}
    .noprint{text-align:center;margin:12px 0}
    .noprint button{padding:8px 18px;font-size:14px;font-family:inherit;cursor:pointer;border:1px solid #888;border-radius:6px;background:#fff}
    @media print{
        body{background:#fff;padding:0}
        .sheet{max-width:none;padding:0}
        .noprint{display:none!important}
        @page{margin:5mm}
    }
</style>
</head>
<body>

<div class="noprint">
    <button onclick="window.print()">چاپکردن</button>
    <button onclick="window.close()">داخستن</button>
</div>

<div class="sheet">
    <div class="hd">
        <h1><?= erp_esc(APP_NAME) ?></h1>
        <div class="sub">وەسڵی فرۆشتن</div>
    </div>

    <div class="meta">
        <div><b>ژمارە:</b> <span dir="ltr"><?= erp_esc($invoice['invoice_number']) ?></span></div>
        <div><b>بەروار:</b> <span dir="ltr"><?= erp_esc($invoice['invoice_date']) ?></span></div>
        <div><b>کڕیار:</b> <?= erp_esc($invoice['customer_name']) ?></div>
        <?php if (!empty($invoice['customer_phone'])): ?>
        <div><b>مۆبایل:</b> <span dir="ltr"><?= erp_esc($invoice['customer_phone']) ?></span></div>
        <?php endif; ?>
        <div><b>کۆگا:</b> <?= erp_esc($invoice['warehouse_name']) ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>کاڵا</th>
                <th class="n" style="width:38px">بڕ</th>
                <th class="n" style="width:58px">نرخ</th>
                <th class="n" style="width:64px">کۆ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoice['items'] as $it): ?>
            <tr>
                <td><?= erp_esc($it['product_name']) ?></td>
                <td class="n"><?= erp_qty($it['quantity']) ?></td>
                <td class="n"><?= erp_money($it['unit_price']) ?></td>
                <td class="n"><?= erp_money($it['line_total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tot">
        <div class="r"><span>کۆی گشتی</span><span class="n"><?= erp_money($invoice['subtotal']) ?></span></div>
        <?php if ((float)$invoice['discount_amount'] > 0): ?>
        <div class="r"><span>داشکاندن</span><span class="n">- <?= erp_money($invoice['discount_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ((float)$invoice['tax_amount'] > 0): ?>
        <div class="r"><span>باج</span><span class="n"><?= erp_money($invoice['tax_amount']) ?></span></div>
        <?php endif; ?>
        <div class="r grand"><span>کۆی کۆتایی</span><span class="n"><?= erp_money($invoice['grand_total']) ?></span></div>
        <div class="r"><span>پارەی دراو</span><span class="n"><?= erp_money($invoice['paid_amount']) ?></span></div>
        <?php if ($remaining > 0.009): ?>
        <div class="r due"><span>ماوە</span><span class="n"><?= erp_money($remaining) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($invoice['notes'])): ?>
    <div style="margin-top:8px;font-size:11px"><b>تێبینی:</b> <?= erp_esc($invoice['notes']) ?></div>
    <?php endif; ?>

    <div class="ft">
        سوپاس بۆ متمانەتان
        <div style="margin-top:3px"><?= erp_esc($invoice['created_by_name'] ?? '') ?></div>
    </div>
</div>

<?php if ($autoprint): ?>
<script>
    // Print once the webfont has settled, otherwise the first sheet can go out
    // in a fallback face. document.fonts is not everywhere, hence the guard.
    function go() { window.print(); }
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => setTimeout(go, 120));
    } else {
        window.addEventListener('load', () => setTimeout(go, 350));
    }
</script>
<?php endif; ?>

</body>
</html>
