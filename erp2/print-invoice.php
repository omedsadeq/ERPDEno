<?php
/**
 * ERP — printable sales invoice.
 *
 * Two sizes from one page:
 *   ?size=80  (default) the 80mm receipt for the thermal printer
 *   ?size=a4  a full-page invoice with the clinic letterhead
 *
 * Opened by the till's Ctrl+P and by the cashier screen (erp2/pos.php), which
 * loads it in a hidden frame. Deliberately a standalone print sheet rather
 * than a page inside the app shell: it auto-prints on load, so the cashier's
 * flow is pay → paper, without leaving the counter.
 *
 * Cost and profit are never rendered here, whatever the viewer's permissions —
 * this document goes to the customer.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/Erp2Sales.php';

// The cashier who just made the sale prints it, even without the sales list.
if (!erp2_can('erp2.sales.create')) {
    erp2_require('erp2.sales.view');
}

$invoiceId = (int)($_GET['id'] ?? 0);
$invoice   = Erp2Sales::load($invoiceId);
if (!$invoice) {
    http_response_code(404);
    exit('وەسڵ نەدۆزرایەوە');
}

$db = Database::getInstance();
require __DIR__ . '/../includes/clinic_branding.php';

$isA4      = ($_GET['size'] ?? '80') === 'a4';
$autoprint = ($_GET['autoprint'] ?? '1') !== '0';
$remaining = max(0, (float)$invoice['grand_total'] - (float)$invoice['paid_amount']);
$time      = date('H:i', strtotime((string)$invoice['created_at']));
$lineDisc  = array_sum(array_map(fn($it) => (float)$it['discount_amount'], $invoice['items']));
$payLabel  = ['cash' => 'نەقد', 'credit' => 'قەرز', 'partial' => 'بەشێک نەقد'][$invoice['payment_type']] ?? '';
$cancelled = $invoice['status'] === 'cancelled';
?><!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>وەسڵ <?= erp2_esc($invoice['invoice_number']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
    *{box-sizing:border-box}
    body{font-family:'NRT',Tahoma,Arial,sans-serif;margin:0;padding:14px;background:#f0f0f0;color:#111}
    .n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
    .noprint{text-align:center;margin:12px 0}
    .noprint button{padding:8px 18px;font-size:14px;font-family:inherit;cursor:pointer;border:1px solid #888;border-radius:6px;background:#fff}
    .void{color:#b91c1c;font-weight:800;text-align:center;border:2px solid #b91c1c;border-radius:6px;padding:3px;margin:6px 0}
<?php if (!$isA4): ?>
    .sheet{max-width:80mm;margin:0 auto;background:#fff;padding:12px 14px}
    .hd{text-align:center;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:8px}
    .hd img{width:20mm;height:auto;display:block;margin:0 auto 4px}
    .hd h1{margin:0;font-size:15px}
    .hd .sub{font-size:10.5px;color:#444;margin-top:2px}
    .hd .doc{display:inline-block;margin-top:5px;font-size:11.5px;font-weight:800;border:1px solid #000;border-radius:4px;padding:1px 10px}
    .meta{font-size:11.5px;line-height:1.85;margin-bottom:8px}
    .meta div{display:flex;justify-content:space-between;gap:6px}
    .meta b{font-weight:700}
    table{width:100%;border-collapse:collapse;font-size:11.5px}
    th{border-bottom:1px solid #000;padding:4px 2px;text-align:right;font-size:11px}
    td{border-bottom:1px dotted #bbb;padding:4px 2px;vertical-align:top}
    .tot{margin-top:8px;border-top:2px solid #000;padding-top:6px;font-size:12px}
    .tot .r{display:flex;justify-content:space-between;padding:2px 0}
    .tot .grand{font-size:15px;font-weight:800;border-top:1px solid #000;margin-top:4px;padding-top:5px}
    .due{color:#c00;font-weight:800}
    .ft{margin-top:10px;text-align:center;font-size:10.5px;color:#555;border-top:1px dashed #999;padding-top:6px;line-height:1.7}
    @media print{
        body{background:#fff;padding:0}
        .sheet{max-width:none;padding:0}
        .noprint{display:none!important}
        @page{margin:4mm}
    }
<?php else: ?>
    .sheet{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:14mm 14mm 12mm;display:flex;flex-direction:column}
    .lh{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10mm;padding-bottom:5mm;border-bottom:2.5px solid #0f766e}
    .lh .ku b{display:block;font-size:19px;color:#0f766e}
    .lh .ku small{display:block;font-size:11.5px;color:#475569;margin-top:2px}
    .lh .en{text-align:left;direction:ltr}
    .lh .en b{display:block;font-size:16px;color:#0f766e;letter-spacing:.5px}
    .lh .en small{display:block;font-size:11.5px;color:#475569;margin-top:2px}
    .lh img{width:24mm;height:auto}
    .title{display:flex;justify-content:space-between;align-items:flex-end;margin:7mm 0 5mm}
    .title h1{margin:0;font-size:22px;color:#0f172a}
    .title h1 small{display:block;font-size:12px;color:#64748b;font-weight:600;margin-top:2px}
    .title .no{text-align:left}
    .title .no b{display:block;font-size:17px;direction:ltr}
    .title .no small{font-size:11.5px;color:#64748b}
    .info{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin-bottom:6mm}
    .info div{background:#f1f5f9;border-radius:6px;padding:2.5mm 3.5mm}
    .info small{display:block;font-size:10.5px;color:#64748b}
    .info b{font-size:13px}
    table{width:100%;border-collapse:collapse;font-size:12.5px}
    thead th{background:#0f766e;color:#fff;padding:2.6mm 2.5mm;text-align:right;font-size:11.5px;font-weight:700}
    thead th:first-child{border-radius:0 6px 0 0}
    thead th:last-child{border-radius:6px 0 0 0}
    tbody td{padding:2.6mm 2.5mm;border-bottom:1px solid #e2e8f0}
    tbody tr:nth-child(even) td{background:#f8fafc}
    td.i{width:9mm;text-align:center;color:#64748b}
    .bottom{display:grid;grid-template-columns:1fr 78mm;gap:8mm;margin-top:6mm;align-items:start}
    .notes{font-size:12px;color:#334155;line-height:1.8}
    .tot{border:1.5px solid #cbd5e1;border-radius:8px;overflow:hidden;font-size:13px}
    .tot .r{display:flex;justify-content:space-between;padding:2.2mm 4mm;border-bottom:1px solid #e2e8f0}
    .tot .grand{background:#0f766e;color:#fff;font-size:16px;font-weight:800;border-bottom:0}
    .tot .due{color:#b91c1c;font-weight:800}
    .sign{display:grid;grid-template-columns:1fr 1fr;gap:20mm;margin-top:auto;padding-top:18mm;font-size:12px;color:#475569;text-align:center}
    .sign div{border-top:1px solid #94a3b8;padding-top:2mm}
    .ft{margin-top:6mm;text-align:center;font-size:10.5px;color:#64748b}
    @media print{
        body{background:#fff;padding:0}
        .sheet{width:auto;min-height:calc(297mm - 16mm);padding:0}
        .noprint{display:none!important}
        @page{size:A4;margin:8mm}
        thead th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
        tbody tr:nth-child(even) td,.info div,.tot .grand{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
<?php endif; ?>
</style>
</head>
<body>

<div class="noprint">
    <button onclick="window.print()">چاپکردن</button>
    <button onclick="window.close()">داخستن</button>
</div>

<?php if (!$isA4): ?>
<div class="sheet">
    <div class="hd">
        <img src="<?= erp2_esc($logoUrl) ?>" alt="" onerror="this.remove()">
        <h1><?= erp2_esc($clinicName) ?></h1>
        <div class="sub"><span dir="ltr"><?= erp2_esc($clinicPhone1) ?></span></div>
        <div class="doc">وەسڵی فرۆشتن — دەرمانخانە</div>
    </div>
    <?php if ($cancelled): ?><div class="void">هەڵوەشێنراوەتەوە</div><?php endif; ?>

    <div class="meta">
        <div><b>ژمارە</b><span class="n"><?= erp2_esc($invoice['invoice_number']) ?></span></div>
        <div><b>بەروار</b><span class="n"><?= erp2_esc($invoice['invoice_date']) ?> &nbsp;<?= erp2_esc($time) ?></span></div>
        <div><b>کڕیار</b><span><?= erp2_esc($invoice['customer_name']) ?></span></div>
        <?php if (!empty($invoice['customer_phone'])): ?>
        <div><b>مۆبایل</b><span class="n"><?= erp2_esc($invoice['customer_phone']) ?></span></div>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>کاڵا</th>
                <th class="n" style="width:30px">بڕ</th>
                <th class="n" style="width:56px">نرخ</th>
                <th class="n" style="width:62px">کۆ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoice['items'] as $it): ?>
            <tr>
                <td><?= erp2_esc($it['product_name']) ?><?= ($it['type_name'] ?? $it['category_name'] ?? '') !== '' ? ' <small style="color:#555">· ' . erp2_esc($it['type_name'] ?? $it['category_name']) . '</small>' : '' ?></td>
                <td class="n"><?= erp2_qty($it['quantity']) ?></td>
                <td class="n"><?= erp2_money($it['unit_price']) ?></td>
                <td class="n"><?= erp2_money($it['line_total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tot">
        <div class="r"><span>کۆی گشتی</span><span class="n"><?= erp2_money($invoice['subtotal']) ?></span></div>
        <?php if ((float)$invoice['discount_amount'] > 0): ?>
        <div class="r"><span>داشکاندن</span><span class="n">- <?= erp2_money($invoice['discount_amount']) ?></span></div>
        <?php endif; ?>
        <?php if ((float)$invoice['tax_amount'] > 0): ?>
        <div class="r"><span>باج</span><span class="n"><?= erp2_money($invoice['tax_amount']) ?></span></div>
        <?php endif; ?>
        <div class="r grand"><span>کۆی کۆتایی</span><span class="n"><?= erp2_money($invoice['grand_total']) ?></span></div>
        <div class="r"><span>پارەی دراو</span><span class="n"><?= erp2_money($invoice['paid_amount']) ?></span></div>
        <?php if ($remaining > 0.009): ?>
        <div class="r due"><span>ماوە</span><span class="n"><?= erp2_money($remaining) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($invoice['notes'])): ?>
    <div style="margin-top:8px;font-size:11px"><b>تێبینی:</b> <?= erp2_esc($invoice['notes']) ?></div>
    <?php endif; ?>

    <div class="ft">
        سوپاس بۆ متمانەتان — ئومێدی چاکبوونەوەتان بۆ دەخوازین
        <div><?= erp2_esc($invoice['created_by_name'] ?? '') ?></div>
    </div>
</div>

<?php else: ?>
<div class="sheet">
    <div class="lh">
        <div class="ku"><b><?= erp2_esc($clinicName) ?></b><small><?= erp2_esc($clinicAddress) ?></small></div>
        <img src="<?= erp2_esc($logoUrl) ?>" alt="" onerror="this.remove()">
        <div class="en"><b><?= erp2_esc($clinicNameEn) ?></b><small><?= erp2_esc(implode(' · ', $clinicPhones)) ?></small></div>
    </div>

    <div class="title">
        <h1>وەسڵی فرۆشتن<small>دەرمانخانە</small></h1>
        <div class="no"><small>ژمارەی وەسڵ</small><b><?= erp2_esc($invoice['invoice_number']) ?></b></div>
    </div>
    <?php if ($cancelled): ?><div class="void">ئەم وەسڵە هەڵوەشێنراوەتەوە</div><?php endif; ?>

    <div class="info">
        <div><small>کڕیار</small><b><?= erp2_esc($invoice['customer_name']) ?></b></div>
        <div><small>بەروار و کات</small><b class="n" style="display:block;text-align:right"><?= erp2_esc($invoice['invoice_date']) ?> · <?= erp2_esc($time) ?></b></div>
        <div><small>جۆری پارەدان</small><b><?= erp2_esc($payLabel) ?></b></div>
        <?php if (!empty($invoice['customer_phone'])): ?>
        <div><small>مۆبایل</small><b class="n" style="display:block;text-align:right"><?= erp2_esc($invoice['customer_phone']) ?></b></div>
        <?php endif; ?>
        <div><small>کۆگا</small><b><?= erp2_esc($invoice['warehouse_name']) ?></b></div>
        <div><small>کاشێر</small><b><?= erp2_esc($invoice['created_by_name'] ?? '') ?></b></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>کاڵا</th>
                <th>کۆد</th>
                <th class="n">بڕ</th>
                <th class="n">نرخ</th>
                <?php if ($lineDisc > 0): ?><th class="n">داشکاندن</th><?php endif; ?>
                <th class="n">کۆ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoice['items'] as $i => $it): ?>
            <tr>
                <td class="i"><?= $i + 1 ?></td>
                <td><?= erp2_esc($it['product_name']) ?><?= ($it['type_name'] ?? $it['category_name'] ?? '') !== '' ? ' <small style="color:#555">· ' . erp2_esc($it['type_name'] ?? $it['category_name']) . '</small>' : '' ?></td>
                <td class="n" style="text-align:right;color:#64748b;font-size:11.5px"><?= erp2_esc($it['product_code']) ?></td>
                <td class="n"><?= erp2_qty($it['quantity']) ?><?= !empty($it['unit_code']) ? ' <small style="color:#64748b">' . erp2_esc($it['unit_code']) . '</small>' : '' ?></td>
                <td class="n"><?= erp2_money($it['unit_price']) ?></td>
                <?php if ($lineDisc > 0): ?><td class="n"><?= (float)$it['discount_amount'] > 0 ? erp2_money($it['discount_amount']) : '' ?></td><?php endif; ?>
                <td class="n" style="font-weight:700"><?= erp2_money($it['line_total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="bottom">
        <div class="notes">
            <?php if (!empty($invoice['notes'])): ?><b>تێبینی:</b> <?= nl2br(erp2_esc($invoice['notes'])) ?><?php endif; ?>
        </div>
        <div class="tot">
            <div class="r"><span>کۆی کاڵاکان</span><span class="n"><?= erp2_money($invoice['subtotal']) ?></span></div>
            <?php if ((float)$invoice['discount_amount'] > 0): ?>
            <div class="r"><span>داشکاندن</span><span class="n">- <?= erp2_money($invoice['discount_amount']) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$invoice['tax_amount'] > 0): ?>
            <div class="r"><span>باج</span><span class="n"><?= erp2_money($invoice['tax_amount']) ?></span></div>
            <?php endif; ?>
            <div class="r grand"><span>کۆی کۆتایی</span><span class="n"><?= erp2_money($invoice['grand_total']) ?> د.ع</span></div>
            <div class="r"><span>پارەی دراو</span><span class="n"><?= erp2_money($invoice['paid_amount']) ?></span></div>
            <?php if ($remaining > 0.009): ?>
            <div class="r due"><span>ماوە</span><span class="n"><?= erp2_money($remaining) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="sign">
        <div>واژووی کاشێر</div>
        <div>واژووی کڕیار</div>
    </div>
    <div class="ft">سوپاس بۆ متمانەتان — ئومێدی چاکبوونەوەتان بۆ دەخوازین</div>
</div>
<?php endif; ?>

<?php if ($autoprint): ?>
<script>
    // Print once the webfont and logo have settled, otherwise the first sheet
    // can go out in a fallback face. document.fonts is not everywhere.
    function go() { window.print(); }
    window.addEventListener('load', function () {
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { setTimeout(go, 150); });
        } else {
            setTimeout(go, 350);
        }
    });
</script>
<?php endif; ?>

</body>
</html>
