<?php
/**
 * وەسڵی مەواد بۆ دکتۆر — the printed hand-over sheet.
 *
 * Two signatures: the store hands over, the doctor receives. Shows the cost
 * per line and the total, because that total is exactly what was booked as
 * the doctor's خەرجی کۆگا.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpDoctorIssue.php';
erp_require('erp.doctor_issue.view');

$issue = ErpDoctorIssue::load((int)($_GET['id'] ?? 0));
if (!$issue) {
    http_response_code(404);
    exit('وەسڵ نەدۆزرایەوە');
}
$autoprint = ($_GET['autoprint'] ?? '1') !== '0';
$isImplant = $issue['expense_kind'] === 'implant';
$cancelled = $issue['status'] === 'cancelled';
?><!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>وەسڵی مەواد <?= erp_esc($issue['issue_number']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
    *{box-sizing:border-box}
    body{font-family:'NRT',Tahoma,Arial,sans-serif;margin:0;padding:16px;background:#eef1f4;color:#111}
    .sheet{max-width:148mm;margin:0 auto;background:#fff;padding:14px 16px;position:relative}
    .hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px}
    .hd h1{margin:0;font-size:17px}
    .hd .sub{font-size:12px;color:#444;margin-top:3px}
    .kind{padding:4px 12px;border-radius:999px;font-size:13px;font-weight:800;border:2px solid <?= $isImplant ? '#6d28d9' : '#0f766e' ?>;color:<?= $isImplant ? '#6d28d9' : '#0f766e' ?>;white-space:nowrap}
    .meta{display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;font-size:12px;margin-bottom:10px}
    .meta b{display:inline-block;min-width:70px;color:#444}
    table{width:100%;border-collapse:collapse;font-size:12px}
    th{border-bottom:1.5px solid #000;padding:5px 3px;text-align:right;font-size:11.5px}
    td{border-bottom:1px dotted #bbb;padding:5px 3px;vertical-align:top}
    .n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
    .tot{display:flex;justify-content:space-between;margin-top:8px;border-top:2px solid #000;padding-top:7px;font-size:15px;font-weight:800}
    .note{margin-top:8px;font-size:11.5px;color:#333}
    .booked{margin-top:6px;font-size:10.5px;color:#666}
    .sign{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:28px;font-size:11.5px;text-align:center}
    .sign div{border-top:1px solid #000;padding-top:4px}
    .void{position:absolute;inset:40% 0 auto 0;text-align:center;font-size:44px;font-weight:900;color:rgba(220,38,38,.22);transform:rotate(-14deg);pointer-events:none}
    .noprint{text-align:center;margin:0 0 12px}
    .noprint button{padding:8px 18px;font-size:14px;font-family:inherit;cursor:pointer;border:1px solid #888;border-radius:6px;background:#fff}
    @media print{body{background:#fff;padding:0}.sheet{max-width:none;padding:0}.noprint{display:none!important}@page{size:A5;margin:8mm}}
</style>
</head>
<body>
<div class="noprint">
    <button onclick="window.print()">چاپکردن</button>
    <button onclick="window.close()">داخستن</button>
</div>
<div class="sheet">
    <?php if ($cancelled): ?><div class="void">هەڵوەشێنراوە</div><?php endif; ?>
    <div class="hd">
        <div>
            <h1><?= erp_esc(APP_NAME) ?></h1>
            <div class="sub">وەسڵی پێدانی مەواد بە دکتۆر</div>
        </div>
        <span class="kind"><?= erp_esc(ErpDoctorIssue::kindLabel($issue['expense_kind'])) ?></span>
    </div>
    <div class="meta">
        <div><b>ژمارە:</b> <span dir="ltr"><?= erp_esc($issue['issue_number']) ?></span></div>
        <div><b>بەروار:</b> <span dir="ltr"><?= erp_esc($issue['issue_date']) ?></span></div>
        <div><b>دکتۆر:</b> <?= erp_esc($issue['doctor_name'] ?? '—') ?></div>
        <div><b>کۆگا:</b> <?= erp_esc($issue['warehouse_name']) ?></div>
    </div>
    <table>
        <thead><tr><th style="width:22px">#</th><th>مەواد</th><th class="n" style="width:56px">بڕ</th><th class="n" style="width:80px">نرخ</th><th class="n" style="width:90px">کۆ</th></tr></thead>
        <tbody>
            <?php foreach ($issue['items'] as $i => $it): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= erp_esc($it['product_name']) ?><?php if (!empty($it['unit_name'])): ?> <span style="color:#777">(<?= erp_esc($it['unit_name']) ?>)</span><?php endif; ?></td>
                <td class="n"><?= erp_qty($it['quantity']) ?></td>
                <td class="n"><?= erp_money($it['unit_price']) ?></td>
                <td class="n"><?= erp_money($it['line_total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="tot"><span>کۆی گشتی <small style="font-size:11px;font-weight:600;color:#555">(<?= erp_esc(ErpDoctorIssue::priceModeLabel($issue['price_mode'])) ?>)</small></span><span class="n"><?= erp_money($issue['total_amount']) ?></span></div>
    <?php if (!empty($issue['notes'])): ?><div class="note"><b>تێبینی:</b> <?= erp_esc($issue['notes']) ?></div><?php endif; ?>
    <div class="booked">
        <?= $cancelled
            ? 'هەڵوەشێنراوەتەوە' . (!empty($issue['cancel_reason']) ? ' — ' . erp_esc($issue['cancel_reason']) : '') . ' · ئەم بڕە لە خەرجی کۆگا لابراوە.'
            : 'ئەم بڕە وەک خەرجی کۆگا (' . erp_esc(ErpDoctorIssue::kindLabel($issue['expense_kind'])) . ') لەسەر ناوی دکتۆر تۆمارکراوە.' ?>
    </div>
    <div class="sign">
        <div>کۆگا — <?= erp_esc($issue['created_by_name'] ?? '') ?></div>
        <div>وەرگر — <?= erp_esc($issue['doctor_name'] ?? '') ?></div>
    </div>
</div>
<?php if ($autoprint): ?>
<script>
    function go() { window.print(); }
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(() => setTimeout(go, 120)); }
    else { window.addEventListener('load', () => setTimeout(go, 350)); }
</script>
<?php endif; ?>
</body>
</html>
