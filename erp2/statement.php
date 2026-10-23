<?php
/**
 * ERP — customer / supplier statement.
 *
 * The classic account sheet: opening balance, then every movement with a
 * running balance, then the closing figure. Debit and credit stay in their own
 * columns exactly as Erp2Ledger stores them, so what is printed matches what is
 * recorded with no re-derivation in between.
 *
 * Print uses the browser (@media print), matching doctors/daily-summary.php
 * and accountant/financial-summary.php rather than introducing a PDF path for
 * a document that is nearly always printed on the spot.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/Erp2Ledger.php';

$kind = $_GET['kind'] ?? 'customer';
if (!in_array($kind, ['customer', 'supplier'], true)) {
    $kind = 'customer';
}
$isCustomer = $kind === 'customer';
erp2_require($isCustomer ? 'erp2.customers.view' : 'erp2.suppliers.view');

$partyId = (int)($_GET['id'] ?? 0);
$from    = erp2_date($_GET['from'] ?? null);
$to      = erp2_date($_GET['to'] ?? null);

$data = $isCustomer
    ? Erp2Ledger::customerStatement($partyId, $from, $to)
    : Erp2Ledger::supplierStatement($partyId, $from, $to);

$party = $data['party'];
$pageTitle  = 'کشف حساب';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => $isCustomer ? 'کڕیارەکان' : 'دابینکەرەکان',
     'url'   => APP_URL . '/erp2/' . ($isCustomer ? 'customers.php' : 'suppliers.php')],
    ['title' => 'کشف حساب'],
];

$docLabels = [
    'OPENING'         => 'باڵانسی سەرەتایی',
    'SALE'            => 'فرۆشتن',
    'SALE_RETURN'     => 'گەڕاندنەوەی فرۆشتن',
    'PURCHASE'        => 'کڕین',
    'PURCHASE_RETURN' => 'گەڕاندنەوەی کڕین',
    'PAYMENT'         => 'پارەدان',
    'ADJUSTMENT'      => 'ڕێکخستن',
];

$customCSS = <<<CSS
.stm{max-width:1000px;margin:0 auto;background:#fff;border:1px solid #d9d9d9;border-radius:8px;padding:16px}
.stm-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #333;padding-bottom:10px;margin-bottom:12px;flex-wrap:wrap;gap:10px}
.stm-title{font-size:19px;font-weight:800}
.stm-sub{font-size:12.5px;color:#666;margin-top:3px}
.stm-filter{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px}
.stm-filter label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.stm-filter .form-control{height:32px;font-size:13px;padding:3px 8px}
table.stm-t{width:100%;border-collapse:collapse;font-size:13px}
table.stm-t th{background:#eef2f5;border:1px solid #c8d3da;padding:7px 8px;font-size:12px;font-weight:700}
table.stm-t td{border:1px solid #e3e3e3;padding:6px 8px}
.n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.stm-open td,.stm-close td{background:#f5f7f9;font-weight:700}
.stm-close td{border-top:2px solid #333}
.due{color:#c62828;font-weight:700}
.ok{color:#2e7d32;font-weight:700}
.stm-sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:14px}
.stm-sum div{border:1px solid #e3e3e3;border-radius:6px;padding:8px 10px}
.stm-sum .l{font-size:11.5px;color:#666}
.stm-sum .v{font-size:17px;font-weight:700;direction:ltr;text-align:left;font-variant-numeric:tabular-nums}
@media print{
  .no-print,.nav-pane,.titlebar,.page-header,.statusbar,.mobile-header{display:none!important}
  body,.content-area,.page-content{background:#fff!important;margin:0!important;padding:0!important}
  .stm{border:0;max-width:none;padding:0}
  table.stm-t th,table.stm-t td{border-color:#555!important}
}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$party): ?>
<div class="alert alert-warning"><div class="alert-content">نەدۆزرایەوە</div></div>
<?php else: ?>

<div class="stm-filter no-print">
    <form method="GET" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="kind" value="<?= erp2_esc($kind) ?>">
        <input type="hidden" name="id" value="<?= (int)$partyId ?>">
        <div><label>لە</label><input type="date" name="from" class="form-control" value="<?= erp2_esc($from) ?>"></div>
        <div><label>بۆ</label><input type="date" name="to" class="form-control" value="<?= erp2_esc($to) ?>"></div>
        <button class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> پاڵاوتن</button>
        <a class="btn btn-secondary btn-sm" href="?kind=<?= erp2_esc($kind) ?>&id=<?= (int)$partyId ?>">پاککردنەوە</a>
        <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> چاپ</button>
        <a class="btn btn-secondary btn-sm"
           href="<?= APP_URL ?>/erp2/statement-export.php?kind=<?= erp2_esc($kind) ?>&id=<?= (int)$partyId ?><?= $from ? '&from=' . erp2_esc($from) : '' ?><?= $to ? '&to=' . erp2_esc($to) : '' ?>">
            <i class="fas fa-file-excel"></i> Excel
        </a>
    </form>
</div>

<div class="stm">
    <div class="stm-head">
        <div>
            <div class="stm-title"><?= erp2_esc($party['name']) ?></div>
            <div class="stm-sub">
                <?= $isCustomer ? 'کڕیار' : 'دابینکەر' ?>
                <?php if ($from || $to): ?>
                    · <?= erp2_esc($from ?: '...') ?> — <?= erp2_esc($to ?: '...') ?>
                <?php else: ?>
                    · هەموو کات
                <?php endif; ?>
            </div>
        </div>
        <div class="stm-sub"><?= APP_NAME ?> · <?= date('Y-m-d') ?></div>
    </div>

    <table class="stm-t">
        <thead>
            <tr>
                <th style="width:100px">بەروار</th>
                <th style="width:150px">جۆر</th>
                <th>وەسڵ / تێبینی</th>
                <th style="width:110px">قەرز</th>
                <th style="width:110px">دانەوە</th>
                <th style="width:120px">باڵانس</th>
            </tr>
        </thead>
        <tbody>
            <tr class="stm-open">
                <td>—</td>
                <td>باڵانسی سەرەتایی</td>
                <td></td>
                <td class="n">—</td>
                <td class="n">—</td>
                <td class="n"><?= erp2_money($data['opening']) ?></td>
            </tr>
            <?php foreach ($data['rows'] as $r): ?>
            <tr>
                <td><?= erp2_esc($r['entry_date']) ?></td>
                <td><?= erp2_esc($docLabels[$r['doc_type']] ?? $r['doc_type']) ?></td>
                <td>
                    <?php if (!empty($r['doc_number'])): ?><span dir="ltr"><?= erp2_esc($r['doc_number']) ?></span><?php endif; ?>
                    <?php if (!empty($r['notes'])): ?>
                        <span style="color:#777;font-size:12px"><?= erp2_esc($r['notes']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="n"><?= (float)$r['debit'] > 0 ? erp2_money($r['debit']) : '—' ?></td>
                <td class="n"><?= (float)$r['credit'] > 0 ? erp2_money($r['credit']) : '—' ?></td>
                <td class="n"><?= erp2_money($r['balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$data['rows']): ?>
            <tr><td colspan="6" style="text-align:center;padding:22px;color:#999">هیچ جوڵەیەک نییە</td></tr>
            <?php endif; ?>
            <tr class="stm-close">
                <td colspan="3">کۆتا باڵانس</td>
                <td class="n"><?= erp2_money($data['totals']['debit'] ?? 0) ?></td>
                <td class="n"><?= erp2_money($data['totals']['credit'] ?? 0) ?></td>
                <td class="n <?= $data['closing'] > 0.009 ? 'due' : 'ok' ?>"><?= erp2_money($data['closing']) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="stm-sum">
        <div><div class="l">باڵانسی سەرەتایی</div><div class="v"><?= erp2_money($data['opening']) ?></div></div>
        <div><div class="l">کۆی قەرز</div><div class="v"><?= erp2_money($data['totals']['debit'] ?? 0) ?></div></div>
        <div><div class="l">کۆی دانەوە</div><div class="v"><?= erp2_money($data['totals']['credit'] ?? 0) ?></div></div>
        <div>
            <div class="l"><?= $isCustomer ? 'ماوە لەسەر کڕیار' : 'ماوە لەسەر ئێمە' ?></div>
            <div class="v <?= $data['closing'] > 0.009 ? 'due' : 'ok' ?>"><?= erp2_money($data['closing']) ?></div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
