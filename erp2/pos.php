<?php
/**
 * دەرمانخانە — کاشێر (POS).
 *
 * A full-screen counter for selling over the desk, built for speed:
 *   - the barcode box keeps focus, so a scanner drives the whole sale.
 *     «3*code» sells three. Kurdish/Arabic digits are accepted everywhere.
 *   - product cards beside the cart (best sellers until something is typed)
 *   - one big total, quick cash buttons and the change to hand back
 *   - pay with the 80mm receipt (F10), an A4 invoice (F8) or no paper (F9);
 *     printing runs in a hidden frame, so the counter never leaves this screen
 *   - hold a sale (F6) and serve the next customer, the doctors' prescription
 *     queue (F7), and today's receipts for a reprint
 *
 * Standalone page (not inside the app shell) so the counter gets the whole
 * screen. Sales are saved through api/sales.php exactly like erp2/sales.php,
 * so stock, ledger, price-floor and warehouse rules are the same; the screen's
 * own lookups come from api/pos.php.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.sales.create');

$db         = Database::getInstance();
$warehouses = erp2_warehouses();
$defaultWh  = erp2_default_warehouse_id();
$me         = $erpAuth->getCurrentUser() ?: [];
$walkIn     = $db->fetch(
    "SELECT id, name FROM erp2_customers WHERE is_walk_in = 1 AND is_active = 1 ORDER BY id LIMIT 1"
) ?: null;
// Groups and their types in the order of the clinic's prescription sheet.
$categories = $db->fetchAll("SELECT id, name FROM erp2_categories WHERE is_active = 1 ORDER BY sort_order, name");
$typesByCat = [];
foreach ($db->fetchAll("SELECT id, category_id, name FROM erp2_product_types WHERE is_active = 1 ORDER BY sort_order, name") as $t) {
    $typesByCat[(int)$t['category_id']][] = ['id' => (int)$t['id'], 'name' => $t['name']];
}
$canRx      = erp2_can('erp2.prescriptions.view');
$pendingRx  = 0;
if ($canRx) {
    $pendingRx = (int)($db->fetch("SELECT COUNT(*) AS c FROM erp2_prescriptions WHERE status = 'pending'")['c'] ?? 0);
}
$whName = '';
foreach ($warehouses as $w) {
    if ((int)$w['id'] === $defaultWh) {
        $whName = $w['name'];
    }
}

/*
 * Edit mode (?edit=<id>), opened from the invoice list for a cash sale. The
 * cart starts on the stored lines and paying posts to api/sales.php?action=update,
 * so Erp2Sales::update() reverses the old lines and posts these: the same path
 * erp2/sales.php's edit takes. The invoice number never changes.
 */
$edit   = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    erp2_require('erp2.sales.edit');
    require_once __DIR__ . '/../classes/Erp2Sales.php';
    $inv = Erp2Sales::load($editId);
    if (!$inv || $inv['status'] !== 'confirmed') {
        $_SESSION['erp2_flash'] = $inv ? 'تەنها وەسڵی پەسەندکراو دەکرێت دەستکاری بکرێت' : 'وەسڵ نەدۆزرایەوە';
        header('Location: ' . APP_URL . '/erp2/invoices.php');
        exit;
    }
    $editWh = (int)$inv['warehouse_id'];
    // What this invoice already took is free again once the edit reverses it,
    // so each product's stock counts it back in (base units).
    $back = [];
    foreach ($inv['items'] as $it) {
        $pid = (int)$it['product_id'];
        $back[$pid] = ($back[$pid] ?? 0) + (float)$it['quantity'] * ((float)$it['unit_factor'] ?: 1.0);
    }
    $editRows = [];
    foreach ($inv['items'] as $it) {
        $pid   = (int)$it['product_id'];
        $price = (float)$it['unit_price'];
        $editRows[] = [
            'pid' => $pid, 'name' => $it['product_name'], 'code' => (string)($it['product_code'] ?? ''),
            'qty' => (float)$it['quantity'], 'factor' => ((float)$it['unit_factor'] ?: 1.0),
            // invoiced prices are kept; switching the price tier leaves them alone
            'retail' => $price, 'whole' => $price, 'price' => $price, 'manual' => true,
            'disc' => (float)$it['discount_amount'],
            'stock' => (float)Erp2Stock::levelOf($pid, $editWh)['quantity'] + $back[$pid],
            'unit_id' => $it['unit_id'] !== null ? (int)$it['unit_id'] : null,
            'unit' => (string)($it['unit_code'] ?? ''), 'dosage' => '',
            'type' => (string)($it['type_name'] ?? $it['category_name'] ?? ''),
        ];
    }
    $editCust = $db->fetch("SELECT is_walk_in FROM erp2_customers WHERE id = :id", ['id' => (int)$inv['customer_id']]);
    // Owed before this invoice, so the customer chip's debt hint leaves it out.
    $editBal = $db->fetch(
        "SELECT COALESCE(SUM(debit) - SUM(credit), 0) AS b FROM erp2_customer_ledger
         WHERE customer_id = :c
           AND NOT (COALESCE(doc_id, 0) = :id AND COALESCE(doc_number, '') = :num AND doc_type IN ('SALE','PAYMENT'))",
        ['c' => (int)$inv['customer_id'], 'id' => $editId, 'num' => $inv['invoice_number']]
    );
    $edit = [
        'id'     => (int)$inv['id'],
        'number' => $inv['invoice_number'],
        'wh'     => $editWh,
        'date'   => $inv['invoice_date'],
        'disc'   => (float)$inv['discount_amount'],
        'paid'   => (float)$inv['paid_amount'],
        'grand'  => (float)$inv['grand_total'],
        'notes'  => (string)($inv['notes'] ?? ''),
        'cust'   => [
            'id' => (int)$inv['customer_id'], 'name' => $inv['customer_name'],
            'phone' => (string)($inv['customer_phone'] ?? ''), 'walk' => !empty($editCust['is_walk_in']),
            'balance' => (float)($editBal['b'] ?? 0),
        ],
        'back'   => (object)$back,
        'rows'   => $editRows,
    ];
}

$cfg = [
    'api' => [
        'pos'   => APP_URL . '/erp2/api/pos.php',
        'sales' => APP_URL . '/erp2/api/sales.php',
        'rx'    => APP_URL . '/erp2/api/prescriptions.php',
        'print' => APP_URL . '/erp2/print-invoice.php',
    ],
    'edit'        => $edit,
    'invoicesUrl' => APP_URL . '/erp2/invoices.php',
    'wh'       => $defaultWh,
    'walkIn'   => $walkIn ? ['id' => (int)$walkIn['id'], 'name' => $walkIn['name']] : null,
    'canPrice' => erp2_can('erp2.price.sale'),
    'canRx'    => $canRx,
    'canReturn'  => erp2_can('erp2.sales.return'),
    'returnsUrl' => APP_URL . '/erp2/sales-returns.php',
    'types'    => (object)$typesByCat,
    'today'    => date('Y-m-d'),
    'user'     => ['id' => (int)($me['id'] ?? 0), 'name' => (string)($me['full_name'] ?? '')],
];
$cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$userName = (string)($me['full_name'] ?? '');
$initial  = mb_substr(trim($userName) !== '' ? trim($userName) : '?', 0, 1);
?><!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= erp2_esc(Csrf::token()) ?>">
<title>کاشێر — دەرمانخانە</title>
<link rel="icon" sizes="32x32" href="<?= erp2_esc(Branding::hasCustomLogo() ? Branding::logoUrl() : APP_URL . '/assets/images/icons/favicon-32.png') ?>">
<link rel="preload" href="<?= APP_URL ?>/fonts/NRT%20Font/NRT-Reg.woff" as="font" type="font/woff" crossorigin>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<style>
:root{
    --bg:#edf1f5; --panel:#fff; --ink:#0f172a; --ink2:#334155; --muted:#64748b; --faint:#94a3b8;
    --line:#e2e8f0; --soft:#f6f8fa;
    --brand:#0d9488; --brand-d:#0f766e; --brand-l:#ccfbf1;
    --ok:#059669; --warn:#b45309; --bad:#e11d48; --info:#2563eb; --hold:#ea580c;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--ink);font-size:14px;overflow:hidden;-webkit-font-smoothing:antialiased}
button,input,select{font-family:inherit;color:inherit}
button{cursor:pointer}
[hidden]{display:none!important}
.n{direction:ltr;unicode-bidi:isolate;font-variant-numeric:tabular-nums}
kbd{font-family:inherit;direction:ltr;display:inline-block;background:#fff;border:1px solid #d5dde5;border-bottom-width:2px;border-radius:6px;padding:0 6px;font-size:11px;line-height:17px;color:#334155}

.pos{height:100vh;display:flex;flex-direction:column}

/* ── top bar ─────────────────────────────────────────── */
.top{flex:0 0 auto;display:flex;align-items:center;gap:12px;padding:10px 14px;
     background:linear-gradient(105deg,#0a1f2b 0%,#0e3a3d 55%,#105248 100%);color:#d7efec;
     box-shadow:0 4px 14px -6px rgba(2,20,25,.5);position:relative;z-index:6}
.t-back,.ib{width:40px;height:40px;flex:0 0 auto;border-radius:12px;display:grid;place-items:center;text-decoration:none;
     background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);color:#cdeeea;font-size:15px;transition:background .15s}
.t-back:hover,.ib:hover{background:rgba(255,255,255,.16);color:#fff}
.t-brand{display:flex;align-items:center;gap:10px;min-width:0;flex:0 0 auto}
.t-mark{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;font-size:19px;color:#fff;
     background:linear-gradient(135deg,#2dd4bf,#0d9488);box-shadow:0 8px 18px -8px rgba(45,212,191,.9)}
.t-brand b{display:block;font-size:16px;font-weight:800;color:#fff;line-height:1.3}
.t-brand small{display:flex;align-items:center;gap:5px;font-size:11.5px;color:#9fd6cf}
.t-brand select{background:transparent;border:0;color:#9fd6cf;font-size:11.5px;padding:0;cursor:pointer}
.t-brand select option{color:#0f172a}
.scan{flex:1 1 auto;max-width:660px;margin-inline:auto;height:50px;display:flex;align-items:center;gap:10px;padding:0 14px;
     background:#fff;border-radius:15px;box-shadow:0 0 0 3px rgba(45,212,191,0);transition:box-shadow .15s}
.scan:focus-within{box-shadow:0 0 0 3px rgba(45,212,191,.6)}
.scan .bc{color:var(--brand);font-size:21px}
.scan input{flex:1;min-width:0;height:100%;border:0;outline:0;background:transparent;font-size:18px;font-weight:800;
     color:var(--ink);direction:ltr;text-align:right}
.scan input::placeholder{color:#a3b1c2;font-weight:600;font-size:14.5px}
.scan .hint{font-size:11px;color:var(--faint);white-space:nowrap}
.t-side{display:flex;align-items:center;gap:8px;flex:0 0 auto}
.chip{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 12px;border-radius:12px;white-space:nowrap;
     background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);color:#d7efec;font-size:12.5px;font-weight:600;transition:background .15s}
.chip:hover{background:rgba(255,255,255,.16)}
.chip b{color:#fff}
.chip i{color:#5eead4}
.chip.rx i{color:#f9a8d4}
.badge{min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#f43f5e;color:#fff;font-size:11px;font-weight:800;
     display:inline-grid;place-items:center;direction:ltr}
.clock{text-align:center;line-height:1.15;padding:0 4px}
.clock b{display:block;font-size:18px;color:#fff;letter-spacing:.5px}
.clock small{font-size:10.5px;color:#9fd6cf}
.me{display:flex;align-items:center;gap:8px;font-size:12.5px;color:#d7efec}
.me .av{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:800;color:#fff;
     background:linear-gradient(135deg,#14b8a6,#0e7490);border:2px solid rgba(255,255,255,.25)}

/* ── main grid ───────────────────────────────────────── */
.main{flex:1;min-height:0;display:grid;gap:12px;padding:12px;
      grid-template-columns:clamp(280px,25vw,400px) minmax(0,1fr) clamp(300px,24vw,370px)}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;min-height:0;display:flex;flex-direction:column;overflow:hidden;
       box-shadow:0 1px 2px rgba(15,23,42,.04),0 14px 30px -24px rgba(15,23,42,.4)}

/* catalog */
.cat-head{padding:12px 12px 0}
.search{position:relative}
.search>i{position:absolute;inset-inline-start:13px;top:50%;transform:translateY(-50%);color:var(--faint)}
.search input{width:100%;height:44px;border:1.5px solid var(--line);border-radius:13px;background:var(--soft);padding-inline:38px 46px;font-size:14px}
.search input:focus{outline:0;border-color:var(--brand);background:#fff;box-shadow:0 0 0 3px rgba(13,148,136,.12)}
.search kbd{position:absolute;inset-inline-end:10px;top:50%;transform:translateY(-50%)}
.cats{display:flex;gap:6px;overflow-x:auto;padding:10px 0 2px;scrollbar-width:none}
.cats::-webkit-scrollbar{display:none}
.cats button{flex:0 0 auto;border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 13px;font-size:12px;font-weight:700;color:#475569;transition:all .15s}
.cats button:hover{border-color:var(--brand);color:var(--brand-d)}
.cats button.on{background:var(--brand);border-color:var(--brand);color:#fff}
/* a group's types, drawn like the circles on the paper sheet */
.tchips{display:flex;flex-wrap:wrap;gap:4px 12px;padding:8px 2px 0}
.tchips button{display:inline-flex;align-items:center;gap:6px;border:0;background:transparent;padding:2px 0;font-size:12.5px;font-weight:700;color:#475569}
.tchips button::before{content:"";width:14px;height:14px;border-radius:50%;border:2px solid #16a34a;box-sizing:border-box;background:#fff}
.tchips button.on{color:#14532d}
.tchips button.on::before{background:#16a34a;box-shadow:inset 0 0 0 2.5px #fff}
.pc-tag{align-self:flex-start;font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px;background:#e0f2fe;color:#0369a1;margin-bottom:-3px}
.cat-sub{display:flex;justify-content:space-between;align-items:center;padding:8px 14px 0;font-size:11.5px;font-weight:600;color:var(--faint)}
.grid{flex:1;overflow:auto;padding:10px 12px 14px;display:grid;gap:10px;align-content:start;
      grid-template-columns:repeat(auto-fill,minmax(130px,1fr))}
.pc{position:relative;display:flex;flex-direction:column;gap:7px;text-align:start;padding:11px;min-height:136px;border-radius:15px;
    border:1.5px solid var(--line);background:#fff;transition:transform .12s,border-color .12s,box-shadow .12s}
.pc:hover,.pc.on{border-color:var(--brand);transform:translateY(-1px);box-shadow:0 12px 22px -16px rgba(13,148,136,.9)}
.pc:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
.pc-av{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-size:14px;font-weight:800;color:#fff;direction:ltr;
       background:linear-gradient(135deg,hsl(var(--h) 70% 52%),hsl(calc(var(--h) + 25) 65% 40%))}
.pc-name{font-size:12.5px;font-weight:700;line-height:1.45;color:var(--ink);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:36px}
.pc-foot{margin-top:auto;display:flex;align-items:center;justify-content:space-between;gap:6px}
.pc-price{font-size:14.5px;font-weight:800;color:var(--brand-d)}
.st{font-size:10.5px;font-weight:800;padding:2px 7px;border-radius:999px;background:#dcfce7;color:#166534;white-space:nowrap}
.st.low{background:#fef3c7;color:#92400e}
.st.out{background:#ffe4e6;color:#be123c}
.pc.out{opacity:.55}
.pc-in{position:absolute;top:9px;inset-inline-end:9px;min-width:24px;height:24px;padding:0 6px;border-radius:8px;display:grid;place-items:center;
       background:var(--brand);color:#fff;font-size:12px;font-weight:800;box-shadow:0 4px 10px -4px rgba(13,148,136,.9)}
.pc-plus{position:absolute;top:9px;inset-inline-end:9px;width:24px;height:24px;border-radius:8px;display:grid;place-items:center;
       background:var(--brand-l);color:var(--brand-d);font-size:11px;opacity:0;transition:opacity .12s}
.pc:hover .pc-plus,.pc.on .pc-plus{opacity:1}
.pc-in + .pc-plus{display:none}
.sk{border-radius:15px;min-height:136px;background:linear-gradient(90deg,#f1f5f9 25%,#e8edf2 50%,#f1f5f9 75%);background-size:200% 100%;animation:sk 1.1s infinite}
@keyframes sk{to{background-position:-200% 0}}
.nores{grid-column:1/-1;text-align:center;color:var(--faint);padding:30px 10px;font-size:13px}
.nores i{display:block;font-size:30px;margin-bottom:8px;color:#cbd5e1}

/* cart */
.cartp{position:relative}
.cart-head{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.cart-title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:800}
.cart-title i{color:var(--brand)}
.cnt{min-width:24px;height:22px;padding:0 8px;border-radius:999px;background:var(--ink);color:#fff;font-size:11.5px;display:inline-grid;place-items:center;direction:ltr}
.cart-head .cat-toggle{display:none;padding:0 12px}
.cust{display:flex;align-items:center;gap:9px;padding:5px 12px 5px 8px;border-radius:13px;border:1.5px dashed #cbd5e1;background:var(--soft);text-align:start;
      margin-inline-start:auto;max-width:260px;transition:border-color .15s,background .15s}
.cust:hover{border-color:var(--brand)}
.cust .ci{width:32px;height:32px;flex:0 0 auto;border-radius:10px;display:grid;place-items:center;background:#e2e8f0;color:#475569}
.cust.named{border-style:solid;border-color:#7dd3fc;background:#f0f9ff}
.cust.named .ci{background:#0284c7;color:#fff}
.cust span{min-width:0}
.cust b{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cust small{display:block;font-size:11px;color:var(--muted);white-space:nowrap}
.cust .debt{color:var(--bad);font-weight:800}
.seg{display:flex;background:#f1f5f9;border-radius:11px;padding:3px;flex:0 0 auto}
.seg button{border:0;background:transparent;padding:6px 12px;border-radius:9px;font-size:12px;font-weight:700;color:var(--muted)}
.seg button.on{background:#fff;color:var(--brand-d);box-shadow:0 1px 3px rgba(15,23,42,.14)}
.icon-btn{width:36px;height:36px;flex:0 0 auto;border-radius:11px;border:1px solid var(--line);background:#fff;color:var(--muted);display:grid;place-items:center}
.icon-btn:hover{border-color:#fecdd3;color:var(--bad);background:#fff1f2}
.rx-ban{margin:10px 14px 0;padding:9px 12px;border-radius:12px;background:#fdf2f8;border:1px solid #fbcfe8;color:#9d174d;display:flex;gap:10px;align-items:center;font-size:12.5px}
.rx-ban b{color:#831843}
.rx-ban button{margin-inline-start:auto;border:0;background:transparent;color:#be185d;font-size:15px}
.rx-ban .rx-ban-print{font-size:12px;font-weight:700;border:1px solid #f9a8d4;border-radius:9px;padding:4px 10px;background:#fff;white-space:nowrap}
.rx-ban .rx-ban-print + button{margin-inline-start:4px}
.edit-ban{background:#fffbeb;border-color:#fde68a;color:#92400e}
.edit-ban b{color:#78350f}
.edit-ban a{margin-inline-start:auto;color:#b45309;font-weight:800;text-decoration:none;white-space:nowrap}
.cols,.row{display:grid;grid-template-columns:28px minmax(0,1fr) 116px 98px 80px 106px 30px;gap:8px;align-items:center}
.cols{padding:9px 20px 7px;font-size:11.5px;font-weight:700;color:var(--faint);border-bottom:1px solid var(--line)}
.cols .e{text-align:left}
.rows{flex:1;overflow:auto;padding:6px 8px 10px}
.row{padding:7px 6px;border-radius:13px;border:1.5px solid transparent;transition:background .12s,border-color .12s}
.row+.row{margin-top:3px}
.row:hover{background:#f8fafc}
.row.sel{background:#f0fdfa;border-color:#99f6e4}
.row.over{background:#fff1f2;border-color:#fecdd3}
.row.bump{animation:bump .6s ease}
@keyframes bump{0%{background:#99f6e4;transform:scale(1.01)}100%{transform:none}}
.ix{font-size:11.5px;font-weight:700;color:var(--faint);text-align:center}
.it{min-width:0}
.it b{display:block;font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:right}
.it small{display:block;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.it .warn{color:var(--bad);font-weight:800}
.it .dose{color:#be185d}
.qty{display:flex;align-items:center;background:#f1f5f9;border-radius:11px;padding:3px}
.qty button{width:30px;height:30px;flex:0 0 auto;border:0;border-radius:9px;background:#fff;color:var(--ink);font-size:16px;font-weight:800;line-height:1;box-shadow:0 1px 2px rgba(15,23,42,.12)}
.qty button:hover{color:var(--brand-d)}
.qty input{width:100%;min-width:0;border:0;background:transparent;text-align:center;font-size:14.5px;font-weight:800;outline:0}
.f{width:100%;height:34px;border:1.5px solid transparent;border-radius:10px;background:transparent;text-align:left;padding:0 8px;font-size:13px;font-weight:700}
.f:hover{border-color:var(--line);background:#fff}
.f:focus{outline:0;border-color:var(--brand);background:#fff}
.f[readonly]{cursor:default;color:var(--ink2)}
.f[readonly]:hover{border-color:transparent;background:transparent}
.lt{text-align:left;font-size:14.5px;font-weight:800}
.rm{width:28px;height:28px;border:0;border-radius:9px;background:transparent;color:#cbd5e1;font-size:14px}
.rm:hover{background:#ffe4e6;color:var(--bad)}
.empty{flex:1;display:grid;place-items:center;text-align:center;color:var(--faint);padding:30px}
.empty .ill{width:104px;height:104px;margin:0 auto 14px;border-radius:30px;display:grid;place-items:center;font-size:42px;color:#14b8a6;
            background:linear-gradient(135deg,#f0fdfa,#e0f2fe);box-shadow:inset 0 0 0 1px #ccfbf1}
.empty h4{margin:0 0 4px;font-size:15px;color:var(--ink2)}
.empty p{margin:0;font-size:12.5px;line-height:1.9}
.cart-foot{display:flex;align-items:center;gap:10px;padding:9px 14px;border-top:1px solid var(--line);background:#fafbfc;font-size:12px;color:var(--muted);min-height:46px}
.held{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.hchip{display:inline-flex;align-items:center;border:1px solid #fed7aa;background:#fff7ed;border-radius:10px;overflow:hidden}
.hchip button{border:0;background:transparent;color:#9a3412;font-size:12px;font-weight:700;padding:5px 9px}
.hchip button:hover{background:#ffedd5}
.hchip .hx{padding:5px 8px;color:#fb923c;border-inline-start:1px solid #fed7aa}
.cart-foot .sum{margin-inline-start:auto;white-space:nowrap}

/* done card (sits over the cart, never takes focus from the scanner) */
.done{position:absolute;inset:0;z-index:4;display:grid;place-items:center;background:rgba(255,255,255,.82);backdrop-filter:blur(2px);cursor:pointer}
.done-card{min-width:330px;text-align:center;padding:24px 28px 20px;border-radius:22px;background:#fff;cursor:default;
           box-shadow:0 30px 60px -24px rgba(2,44,34,.45),0 0 0 1px #d1fae5;animation:pop .28s cubic-bezier(.2,1.4,.4,1)}
@keyframes pop{from{transform:scale(.9);opacity:0}to{transform:none;opacity:1}}
.tick{width:74px;height:74px;margin:0 auto 10px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:32px;
      background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 14px 26px -12px rgba(5,150,105,.9)}
.done-card h3{margin:0;font-size:19px}
.done-card .no{font-size:12.5px;color:var(--muted);margin-top:2px}
.done-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0 12px}
.done-grid div{background:var(--soft);border-radius:12px;padding:8px 10px}
.done-grid small{display:block;font-size:11px;color:var(--muted)}
.done-grid b{font-size:16px}
.done-change{grid-column:1/-1;background:#ecfdf5!important}
.done-change b{font-size:28px;color:var(--ok)}
.done-debt{grid-column:1/-1;background:#fffbeb!important}
.done-debt b{color:var(--warn)}
.done-acts{display:flex;gap:8px;justify-content:center}
.done-acts button{border:1px solid var(--line);background:#fff;border-radius:11px;padding:8px 14px;font-size:12.5px;font-weight:700}
.done-acts button.go{background:var(--ink);border-color:var(--ink);color:#fff}

/* pay */
.pay{padding:14px;gap:12px;overflow:auto}
.pay>*{flex-shrink:0}
.total{position:relative;overflow:hidden;border-radius:18px;padding:15px 16px 13px;color:#ccfbf1;
       background:radial-gradient(130% 150% at 100% 0%,#155e57 0%,#0b2a33 58%,#081b23 100%);box-shadow:0 16px 30px -22px rgba(8,27,35,.9)}
.total::after{content:"";position:absolute;width:190px;height:190px;border-radius:50%;background:rgba(45,212,191,.12);top:-80px;left:-60px}
.total .lbl{position:relative;display:flex;justify-content:space-between;font-size:12.5px;font-weight:700;color:#99f6e4}
.total .big{position:relative;display:flex;align-items:baseline;gap:8px;margin-top:2px;line-height:1.15}
.total .big b{font-size:42px;font-weight:900;color:#fff;letter-spacing:.5px}
.total .big small{font-size:13px;color:#5eead4;font-weight:700}
.total .big.pulse b{animation:pulse .35s ease}
@keyframes pulse{50%{color:#5eead4}}
.total .lines{position:relative;display:grid;gap:3px;margin-top:8px;padding-top:8px;border-top:1px dashed rgba(153,246,228,.25);font-size:12px}
.total .lines div{display:flex;justify-content:space-between;color:#a7f3d0}
.blk label{display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px}
.inrow{display:flex;gap:6px}
.inp{flex:1;min-width:0;height:42px;border:1.5px solid var(--line);border-radius:12px;background:var(--soft);padding:0 12px;font-size:16px;font-weight:800;text-align:left;direction:ltr}
.inp:focus{outline:0;border-color:var(--brand);background:#fff;box-shadow:0 0 0 3px rgba(13,148,136,.12)}
.inp.big{height:52px;font-size:23px}
.inp::placeholder{color:#b6c2cf}
.mini{display:flex;background:#f1f5f9;border-radius:11px;padding:3px}
.mini button{border:0;background:transparent;border-radius:9px;padding:0 11px;font-size:12px;font-weight:800;color:var(--muted)}
.mini button.on{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(15,23,42,.14)}
.cash{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:8px}
.cash button{height:36px;border-radius:10px;border:1px solid var(--line);background:#fff;font-size:12.5px;font-weight:800;color:var(--ink2);direction:ltr;transition:all .12s}
.cash button:hover{border-color:var(--brand);color:var(--brand-d)}
.cash .ex{grid-column:span 2;background:#ecfdf5;border-color:#a7f3d0;color:#047857;direction:rtl}
.cash .clr{color:var(--bad)}
.result{margin-top:8px;border-radius:13px;padding:9px 12px;display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12.5px;font-weight:700;background:var(--soft);color:var(--muted);min-height:46px}
.result b{font-size:16px}
.result.change{background:#ecfdf5;color:#047857}
.result.change b{font-size:24px}
.result.debt{background:#fffbeb;color:var(--warn)}
.result.short{background:#fff1f2;color:var(--bad)}
.extra{display:flex;gap:6px}
.extra input{height:38px;border:1.5px solid var(--line);border-radius:11px;background:var(--soft);padding:0 10px;font-size:12.5px;min-width:0}
.extra input:focus{outline:0;border-color:var(--brand);background:#fff}
.extra .note{flex:1}
.extra input[type=date]{direction:ltr;width:132px;flex:0 0 auto}
.acts{display:grid;gap:8px;margin-top:auto}
.fk{position:absolute;top:7px;left:9px;font-size:10px;font-weight:800;padding:1px 6px;border-radius:6px;direction:ltr;background:rgba(255,255,255,.22)}
.btn-pay{position:relative;height:64px;border:0;border-radius:17px;color:#fff;font-size:17px;font-weight:800;display:flex;align-items:center;justify-content:center;gap:10px;
         background:linear-gradient(135deg,#10b981,#059669 60%,#047857);box-shadow:0 16px 28px -16px rgba(5,150,105,.95);transition:filter .15s,transform .1s}
.btn-pay:hover{filter:brightness(1.06)}
.btn-pay:active{transform:translateY(1px)}
.btn-pay:disabled{background:#cbd5e1;box-shadow:none;cursor:not-allowed}
.btn-pay i{font-size:19px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.three{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.btn2{position:relative;height:50px;border-radius:14px;border:1.5px solid var(--line);background:#fff;font-size:13px;font-weight:800;color:var(--ink);
      display:flex;align-items:center;justify-content:center;gap:8px;transition:border-color .15s,background .15s}
.btn2:hover{border-color:#94a3b8;background:#f8fafc}
.btn2:disabled{opacity:.5;cursor:not-allowed}
.btn2 .fk{background:#f1f5f9;color:var(--muted)}
.btn2.a4 i{color:var(--info)}
.btn2.np i{color:var(--ok)}
.btn3{height:40px;border-radius:12px;border:1px solid var(--line);background:var(--soft);font-size:12px;font-weight:700;color:#475569;
      display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;position:relative}
.btn3:hover{background:#fff;border-color:#cbd5e1;color:var(--ink)}
.btn3.hold i{color:var(--hold)}
.btn3.rec i{color:var(--info)}
.btn3.ret i{color:var(--bad)}
.busy .btn-pay,.busy .btn2{pointer-events:none;opacity:.6}

.keys{flex:0 0 auto;display:flex;gap:16px;align-items:center;justify-content:center;padding:0 12px 9px;font-size:11.5px;color:var(--muted);flex-wrap:wrap}
.keys span{white-space:nowrap}

/* overlays */
.ov{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:20px;background:rgba(8,26,34,.5);backdrop-filter:blur(3px)}
.md{width:min(540px,100%);max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden;background:#fff;border-radius:22px;
    box-shadow:0 34px 70px -24px rgba(2,20,25,.6);animation:pop .22s ease}
.ov.side{place-items:stretch end;padding:0}
.ov.side .md{width:min(470px,100vw);max-height:none;height:100%;border-radius:0 22px 22px 0;animation:slide .22s ease}
@keyframes slide{from{transform:translateX(-30px);opacity:0}to{transform:none;opacity:1}}
.md-h{display:flex;align-items:center;gap:12px;padding:16px 18px;border-bottom:1px solid var(--line)}
.md-h .ic{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;color:#fff;font-size:17px;flex:0 0 auto}
.md-h h3{margin:0;font-size:16px}
.md-h p{margin:1px 0 0;font-size:12px;color:var(--muted)}
.md-h .x{margin-inline-start:auto;width:36px;height:36px;border-radius:11px;border:1px solid var(--line);background:#fff;color:var(--muted)}
.md-b{padding:14px 18px 18px;overflow:auto;flex:1}
.list{display:grid;gap:4px;margin-top:10px}
.li{display:flex;align-items:center;gap:11px;width:100%;padding:10px 12px;border:1.5px solid transparent;border-radius:13px;background:transparent;text-align:start}
.li:hover,.li.on{background:#f0fdfa;border-color:#99f6e4}
.li .av{width:36px;height:36px;flex:0 0 auto;border-radius:11px;display:grid;place-items:center;font-weight:800;background:#e2e8f0;color:#475569}
.li .av.walk{background:#ccfbf1;color:var(--brand-d)}
.li .tx{flex:1;min-width:0}
.li .tx b{display:block;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.li .tx small{display:block;font-size:11.5px;color:var(--muted)}
.li .end{text-align:left;font-size:12px;font-weight:800;white-space:nowrap}
.li .end.debt{color:var(--bad)}
.li .end.ok{color:var(--ok)}
.addc{display:flex;gap:6px;margin-top:10px;padding:10px;border-radius:14px;background:var(--soft);border:1px dashed #cbd5e1}
.addc input{flex:1;min-width:0;height:40px;border:1.5px solid var(--line);border-radius:11px;padding:0 10px;font-size:13px;background:#fff}
.addc input:focus{outline:0;border-color:var(--brand)}
.addc button,.btn-add{height:40px;border:0;border-radius:11px;padding:0 14px;background:var(--brand);color:#fff;font-weight:800;font-size:12.5px;white-space:nowrap}
.btn-add{background:#fff;color:var(--brand-d);border:1.5px dashed var(--brand);width:100%;margin-top:10px}
.sums{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.sums div{background:var(--soft);border-radius:13px;padding:9px 11px}
.sums small{display:block;font-size:11px;color:var(--muted)}
.sums b{font-size:16px}
.rc{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:13px;border:1px solid var(--line);margin-bottom:6px}
.rc.cx{opacity:.55;background:#fafafa}
.rc .tx{flex:1;min-width:0}
.rc .tx b{font-size:13px}
.rc .tx small{display:block;font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rc .amt{font-weight:800;font-size:14px;white-space:nowrap}
.rc .pb{width:34px;height:34px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--muted);font-size:13px}
.rc .pb:hover{border-color:var(--info);color:var(--info)}
.tag{font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px;background:#ffe4e6;color:#be123c}
.empty-sm{text-align:center;color:var(--faint);padding:26px 10px;font-size:13px}

.toast{position:fixed;bottom:46px;left:50%;z-index:80;display:flex;align-items:center;gap:10px;max-width:min(560px,92vw);
       padding:11px 16px;border-radius:14px;background:#0f172a;color:#fff;font-size:13px;font-weight:600;
       box-shadow:0 18px 36px -16px rgba(2,6,23,.7);transform:translate(-50%,20px);opacity:0;pointer-events:none;transition:all .2s}
.toast.show{transform:translate(-50%,0);opacity:1;pointer-events:auto}
.toast.err{background:#9f1239}
.toast.ok{background:#065f46}
.toast button{border:0;background:rgba(255,255,255,.18);color:#fff;border-radius:9px;padding:4px 10px;font-weight:800;font-size:12px}

/* return (گەڕاندنەوە): find the receipt → pick what comes back → hand the cash back */
.md.wide{width:min(820px,100%)}
.ret-link{display:block;margin-top:12px;text-align:center;font-size:12.5px;font-weight:700;color:var(--muted);text-decoration:none}
.ret-link:hover{color:var(--brand-d)}
.ret-inv{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;border-radius:13px;background:var(--soft);margin-bottom:10px;font-size:12.5px}
.ret-inv b{font-size:14px}
.ret-inv .sp{margin-inline-start:auto}
.ret-inv button{height:32px;border-radius:10px;border:1px solid var(--line);background:#fff;font-size:12px;font-weight:700;color:#475569;padding:0 10px}
.rt{width:100%;border-collapse:collapse;font-size:13px}
.rt th{font-size:11px;font-weight:700;color:var(--muted);text-align:start;padding:6px 8px;border-bottom:1px solid var(--line)}
.rt td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.rt td.n,.rt th.n{text-align:left;white-space:nowrap}
.rt tr.done td{opacity:.45}
.rt tr.on td{background:#fff1f2}
.rt small{color:var(--muted);font-size:11px}
.rq{display:inline-flex;align-items:center;border:1.5px solid var(--line);border-radius:11px;overflow:hidden;background:#fff}
.rq button{width:32px;height:34px;border:0;background:var(--soft);font-size:16px;font-weight:800;color:#475569}
.rq input{width:56px;height:34px;border:0;text-align:center;font-weight:800;font-size:14px}
.rq input:focus{outline:0;background:#fff7ed}
.ret-opts{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}
.ret-opts label{flex:1;min-width:200px;display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid var(--line);border-radius:13px;font-size:13px;font-weight:700;cursor:pointer}
.ret-opts label:has(input:checked){border-color:var(--bad);background:#fff1f2}
.ret-note{margin-top:8px;font-size:12px;color:#b45309}
.ret-foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 18px;border-top:1px solid var(--line);background:#fff}
.ret-foot .reason{flex:1;min-width:180px;height:42px;border:1.5px solid var(--line);border-radius:12px;padding:0 12px;font-size:13px}
.ret-foot .reason:focus{outline:0;border-color:var(--bad)}
.ret-tot{text-align:left;line-height:1.25}
.ret-tot small{display:block;font-size:11px;color:var(--muted)}
.ret-tot b{font-size:22px;color:var(--bad)}
.ret-go{height:46px;border:0;border-radius:13px;padding:0 18px;background:var(--bad);color:#fff;font-weight:800;font-size:14px;white-space:nowrap}
.ret-go:disabled{opacity:.45}
.ret-done{text-align:center;padding:26px 10px 10px}
.ret-done .big{font-size:44px;font-weight:900;color:var(--bad);margin:6px 0 2px}
.ret-done p{margin:4px 0;color:var(--muted);font-size:13px}
.ret-done .acts2{display:flex;gap:8px;justify-content:center;margin-top:18px}
.ret-done .acts2 button{height:44px;border-radius:12px;padding:0 18px;font-weight:800;font-size:13px;border:1px solid var(--line);background:#fff}
.ret-done .acts2 button.pri{background:var(--brand);border-color:var(--brand);color:#fff}
.rc .pb.rt-b:hover{border-color:var(--bad);color:var(--bad)}

@media (max-width:1400px){
    .cols,.row{grid-template-columns:24px minmax(0,1fr) 104px 86px 64px 94px 28px;gap:6px}
}
/* laptop screens (768px high): everything in the pay column stays on screen */
@media (max-height:820px){
    .main{gap:10px;padding:10px}
    .pay{padding:12px;gap:9px}
    .total{padding:12px 14px 10px}
    .total .big b{font-size:36px}
    .inp{height:38px}
    .inp.big{height:46px;font-size:21px}
    .cash{gap:5px;margin-top:6px}
    .cash button{height:32px}
    .result{min-height:40px;padding:7px 12px;margin-top:6px}
    .result.change b{font-size:21px}
    .extra input{height:34px}
    .acts{gap:7px}
    .btn-pay{height:54px;font-size:16px}
    .btn2{height:42px}
    .btn3{height:36px}
    .grid{grid-template-columns:repeat(auto-fill,minmax(122px,1fr))}
    .pc{min-height:124px}
}
@media (max-width:1200px){
    .t-brand small{display:none}
    .me span{display:none}
    .scan .hint{display:none}
}
@media (max-width:1100px){
    .main{grid-template-columns:minmax(0,1fr) 320px}
    .catp{position:fixed;z-index:30;top:76px;bottom:12px;inset-inline-start:12px;width:min(390px,calc(100vw - 24px));
          transform:translateX(calc(100% + 24px));transition:transform .22s ease;box-shadow:0 30px 60px -20px rgba(2,20,25,.5)}
    .catp.open{transform:none}
    .cart-head .cat-toggle{display:inline-flex}
    .clock{display:none}
}
@media (max-width:760px){
    body{overflow:auto}
    .pos{height:auto;min-height:100vh}
    .top{flex-wrap:wrap}
    .scan{order:9;flex-basis:100%;max-width:none}
    .t-side .chip span.lbl{display:none}
    .main{grid-template-columns:1fr}
    .cartp{min-height:60vh}
    .cols{display:none}
    .row{grid-template-columns:minmax(0,1fr) 108px 96px 28px}
    .row .ix,.row .ds,.row .pr{display:none}
    .keys{display:none}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<div class="pos" id="pos">

<header class="top">
    <a class="t-back" href="<?= APP_URL ?>/erp2/<?= $edit ? 'invoices.php' : 'index.php' ?>" title="گەڕانەوە" aria-label="گەڕانەوە"><i class="fas fa-arrow-right"></i></a>
    <div class="t-brand">
        <span class="t-mark"><i class="fas fa-cash-register" aria-hidden="true"></i></span>
        <div>
            <b>کاشێر</b>
            <small>
                <i class="fas fa-warehouse" aria-hidden="true"></i>
                <?php if (count($warehouses) > 1): ?>
                <select id="whSel" aria-label="کۆگا">
                    <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $defaultWh ? 'selected' : '' ?>><?= erp2_esc($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <?= erp2_esc($whName ?: 'دەرمانخانە') ?>
                <?php endif; ?>
            </small>
        </div>
    </div>

    <label class="scan" for="scanBox">
        <i class="fas fa-barcode bc" aria-hidden="true"></i>
        <input id="scanBox" autocomplete="off" spellcheck="false" placeholder="بارکۆد بخوێنەوە یان کۆد بنووسە — 3*کۆد بۆ ٣ دانە" aria-label="بارکۆد">
        <span class="hint"><kbd>F2</kbd></span>
    </label>

    <div class="t-side">
        <?php if ($canRx): ?>
        <button type="button" class="chip rx" id="rxBtn" title="داواکاری دەرمانی دکتۆرەکان (F7)" <?= $edit ? 'hidden' : '' ?>>
            <i class="fas fa-prescription-bottle-medical" aria-hidden="true"></i><span class="lbl">داواکاری دەرمان</span>
            <span class="badge" id="rxCount" <?= $pendingRx > 0 ? '' : 'hidden' ?>><?= $pendingRx ?></span>
        </button>
        <?php endif; ?>
        <button type="button" class="chip" id="todayBtn" title="پسوولەکانی ئەمڕۆ">
            <i class="fas fa-receipt" aria-hidden="true"></i><span class="lbl">ئەمڕۆ</span>
            <b class="n" id="todayTotal">0</b>
        </button>
        <div class="clock" aria-live="off"><b class="n" id="clkT">--:--</b><small class="n" id="clkD"></small></div>
        <div class="me" title="<?= erp2_esc($userName) ?>"><span class="av"><?= erp2_esc($initial) ?></span><span><?= erp2_esc($userName) ?></span></div>
        <button type="button" class="ib" id="soundBtn" title="دەنگ" aria-label="دەنگ"><i class="fas fa-volume-high"></i></button>
        <button type="button" class="ib" id="fsBtn" title="پڕ بە شاشە" aria-label="پڕ بە شاشە"><i class="fas fa-expand"></i></button>
    </div>
</header>

<main class="main">
    <!-- product cards -->
    <section class="panel catp" id="catp" aria-label="کاڵاکان">
        <div class="cat-head">
            <div class="search">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input id="catQ" type="search" autocomplete="off" placeholder="گەڕان بە ناو یان کۆدی کاڵا..." aria-label="گەڕانی کاڵا">
                <kbd>F3</kbd>
            </div>
            <div class="cats" id="cats" role="tablist" aria-label="پۆلەکان">
                <button type="button" class="on" data-cat="0">هەموو</button>
                <?php foreach ($categories as $c): ?>
                <button type="button" data-cat="<?= (int)$c['id'] ?>"><?= erp2_esc($c['name']) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="tchips" id="typeChips" role="radiogroup" aria-label="جۆر" hidden></div>
        </div>
        <div class="cat-sub"><span id="catLabel">زۆرترین فرۆشراو</span><span id="catCount" class="n"></span></div>
        <div class="grid" id="grid" aria-live="polite"></div>
    </section>

    <!-- cart -->
    <section class="panel cartp" id="cartp" aria-label="سەبەتە">
        <div class="cart-head">
            <div class="cart-title"><i class="fas fa-basket-shopping" aria-hidden="true"></i> سەبەتە <span class="cnt" id="cnt">0</span></div>
            <button type="button" class="btn3 cat-toggle" id="catToggle"><i class="fas fa-capsules"></i> کاڵاکان</button>
            <button type="button" class="cust" id="custBtn" title="هەڵبژاردنی کڕیار (F4)">
                <span class="ci"><i class="fas fa-user" aria-hidden="true"></i></span>
                <span><b id="custName">کڕیار</b><small id="custMeta">F4</small></span>
            </button>
            <div class="seg" id="tierSeg" role="radiogroup" aria-label="جۆری نرخ">
                <button type="button" class="on" data-tier="retail" role="radio" aria-checked="true">نرخی تاک</button>
                <button type="button" data-tier="wholesale" role="radio" aria-checked="false">نرخی جوملە</button>
            </div>
            <button type="button" class="icon-btn" id="clearBtn" title="پاککردنەوەی سەبەتە" aria-label="پاککردنەوەی سەبەتە" <?= $edit ? 'hidden' : '' ?>><i class="fas fa-trash-can"></i></button>
        </div>
        <div class="rx-ban" id="rxBan" hidden>
            <i class="fas fa-prescription" aria-hidden="true"></i>
            <span>داواکاری دەرمانی <b id="rxBanName"></b> <span id="rxBanDoc"></span> — دوای فرۆشتن وەک «فرۆشراو» نیشانە دەکرێت</span>
            <button type="button" id="rxBanPrint" class="rx-ban-print" title="چاپی ڕەچەتەی دکتۆر (A5)"><i class="fas fa-print"></i> ڕەچەتە</button>
            <button type="button" id="rxBanX" title="جیاکردنەوە لە داواکارییەکە" aria-label="جیاکردنەوە"><i class="fas fa-xmark"></i></button>
        </div>
        <?php if ($edit): ?>
        <div class="rx-ban edit-ban">
            <i class="fas fa-pen-to-square" aria-hidden="true"></i>
            <span>دەستکاری وەسڵی <b class="n"><?= erp2_esc($edit['number']) ?></b> — کاڵا کۆنەکان دەگەڕێنەوە بۆ کۆگا و ئەمانەی سەبەتە دادەبەزرێن. ژمارەی وەسڵ ناگۆڕێت.</span>
            <a href="<?= APP_URL ?>/erp2/invoices.php">پاشگەزبوونەوە</a>
        </div>
        <?php endif; ?>
        <div class="cols" id="cols" hidden>
            <span></span><span>کاڵا</span><span style="text-align:center">بڕ</span><span class="e">نرخ</span><span class="e">داشکان</span><span class="e">کۆ</span><span></span>
        </div>
        <div class="rows" id="rows" hidden></div>
        <div class="empty" id="cartEmpty">
            <div>
                <div class="ill"><i class="fas fa-barcode" aria-hidden="true"></i></div>
                <h4>سەبەتەکە بەتاڵە</h4>
                <p>بارکۆد بخوێنەوە، یان کاڵا لە لیستەکە هەڵبژێرە.<br><kbd>3*</kbd> پێش بارکۆد بۆ چەند دانە · <kbd>+</kbd> <kbd>−</kbd> بڕی کاڵای دیاریکراو</p>
            </div>
        </div>
        <div class="cart-foot">
            <div class="held" id="heldWrap" hidden><span><i class="fas fa-pause" style="color:var(--hold)"></i> ڕاگیراو:</span><span class="held" id="held"></span></div>
            <span class="sum" id="tItems"></span>
        </div>

        <div class="done" id="done" hidden>
            <div class="done-card" role="status">
                <div class="tick"><i class="fas fa-check"></i></div>
                <h3 id="doneTitle">فرۆشرا</h3>
                <div class="no">وەسڵی <span class="n" id="doneNo"></span></div>
                <div class="done-grid" id="doneGrid"></div>
                <div class="done-acts">
                    <button type="button" id="donePrint"><i class="fas fa-receipt"></i> پسوولە</button>
                    <button type="button" id="doneA4"><i class="fas fa-file-lines"></i> A4</button>
                    <button type="button" id="doneRx" hidden title="ڕەچەتەکە بە نرخ و مۆری «فرۆشرا»وە"><i class="fas fa-prescription"></i> ڕەچەتە</button>
                    <button type="button" class="go" id="doneNext">فرۆشتنی نوێ <kbd>Enter</kbd></button>
                </div>
            </div>
        </div>
    </section>

    <!-- payment -->
    <aside class="panel pay" id="payp" aria-label="پارەدان">
        <div class="total">
            <div class="lbl"><span>کۆی گشتی</span><span id="tCount"></span></div>
            <div class="big" id="tBig"><b class="n" id="tGrand">0</b><small>د.ع</small></div>
            <!-- only with a discount: otherwise it would just repeat the total -->
            <div class="lines" id="tLines" hidden>
                <div><span>کۆی کاڵاکان</span><span class="n" id="tSub">0</span></div>
                <div><span>داشکاندن</span><span class="n" id="tDisc">0</span></div>
            </div>
        </div>

        <div class="blk">
            <label for="discIn"><span>داشکاندنی وەسڵ</span></label>
            <div class="inrow">
                <input class="inp" id="discIn" inputmode="decimal" autocomplete="off" placeholder="0">
                <div class="mini" id="discMode">
                    <button type="button" class="on" data-m="amt">د.ع</button>
                    <button type="button" data-m="pct">%</button>
                </div>
            </div>
        </div>

        <div class="blk">
            <label for="recv"><span>پارەی وەرگیراو</span><small style="color:var(--faint);font-weight:600">بەتاڵ = پارەی تەواو</small></label>
            <input class="inp big" id="recv" inputmode="decimal" autocomplete="off" placeholder="0">
            <div class="cash" id="cash">
                <button type="button" class="ex" data-cash="exact"><i class="fas fa-equals"></i> پارەی تەواو</button>
                <button type="button" data-cash="1000">1,000</button>
                <button type="button" class="clr" data-cash="clear" aria-label="پاککردنەوە"><i class="fas fa-delete-left"></i></button>
                <button type="button" data-cash="5000">5,000</button>
                <button type="button" data-cash="10000">10,000</button>
                <button type="button" data-cash="25000">25,000</button>
                <button type="button" data-cash="50000">50,000</button>
            </div>
            <div class="result" id="result"></div>
        </div>

        <div class="extra">
            <input class="note" id="note" placeholder="تێبینی..." autocomplete="off" aria-label="تێبینی">
            <input type="date" id="saleDate" value="<?= date('Y-m-d') ?>" aria-label="بەروار" title="بەرواری وەسڵ">
        </div>

        <div class="acts">
            <button type="button" class="btn-pay" id="btnPay" disabled>
                <span class="fk">F10</span><i class="fas fa-receipt"></i> <?= $edit ? 'نوێکردنەوە و چاپی پسوولە' : 'پارەدان و چاپی پسوولە' ?>
            </button>
            <div class="two">
                <button type="button" class="btn2 np" id="btnNoPrint" disabled><span class="fk">F9</span><i class="fas fa-check"></i> بێ چاپ</button>
                <button type="button" class="btn2 a4" id="btnA4" disabled><span class="fk">F8</span><i class="fas fa-file-lines"></i> چاپی A4</button>
            </div>
            <div class="three">
                <button type="button" class="btn3 hold" id="holdBtn" title="ڕاگرتنی ئەم فرۆشتنە (F6)" <?= $edit ? 'disabled' : '' ?>><i class="fas fa-pause"></i> ڕاگرتن</button>
                <button type="button" class="btn3 rec" id="recBtn"><i class="fas fa-clock-rotate-left"></i> پسوولەکان</button>
                <?php if (erp2_can('erp2.sales.return')): ?>
                <button type="button" class="btn3 ret" id="retBtn" title="گەڕاندنەوەی کاڵا و دانەوەی پارە"><i class="fas fa-rotate-left"></i> گەڕاندنەوە</button>
                <?php else: ?>
                <a class="btn3" href="<?= APP_URL ?>/erp2/index.php"><i class="fas fa-gauge-high"></i> داشبۆرد</a>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</main>

<div class="keys" aria-hidden="true">
    <span><kbd>F2</kbd>بارکۆد</span><span><kbd>F3</kbd>گەڕانی کاڵا</span><span><kbd>F4</kbd>کڕیار</span>
    <span><kbd>F6</kbd>ڕاگرتن</span><?php if ($canRx): ?><span><kbd>F7</kbd>داواکاری دەرمان</span><?php endif; ?>
    <span><kbd>↑</kbd><kbd>↓</kbd>هەڵبژاردنی ڕیز</span><span><kbd>+</kbd><kbd>−</kbd>بڕ</span><span><kbd>Delete</kbd>سڕینەوە</span>
    <span><kbd>Esc</kbd>پاشگەزبوونەوە</span>
</div>
</div>

<!-- customer picker -->
<div class="ov" id="custOv" hidden>
    <div class="md" role="dialog" aria-modal="true" aria-labelledby="custTitle">
        <div class="md-h">
            <span class="ic" style="background:linear-gradient(135deg,#38bdf8,#0369a1)"><i class="fas fa-user"></i></span>
            <div><h3 id="custTitle">کڕیار</h3><p>کڕیاری نەقد بۆ فرۆشتنی ئاسایی، یان کەسێکی ناودار بۆ قەرز</p></div>
            <button type="button" class="x" data-close aria-label="داخستن"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="md-b">
            <div class="search">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input id="custQ" type="search" autocomplete="off" placeholder="ناو، مۆبایل یان کۆد..." aria-label="گەڕانی کڕیار">
            </div>
            <div class="list" id="custList" role="listbox"></div>
            <button type="button" class="btn-add" id="addCustToggle"><i class="fas fa-user-plus"></i> کڕیاری نوێ</button>
            <div class="addc" id="addCust" hidden>
                <input id="newCustName" placeholder="ناوی کڕیار" autocomplete="off">
                <input id="newCustPhone" placeholder="مۆبایل" dir="ltr" autocomplete="off" style="max-width:140px">
                <button type="button" id="newCustSave">زیادکردن</button>
            </div>
        </div>
    </div>
</div>

<!-- receipts -->
<div class="ov side" id="recOv" hidden>
    <div class="md" role="dialog" aria-modal="true" aria-labelledby="recTitle">
        <div class="md-h">
            <span class="ic" style="background:linear-gradient(135deg,#60a5fa,#1d4ed8)"><i class="fas fa-clock-rotate-left"></i></span>
            <div><h3 id="recTitle">پسوولەکانی ئەمڕۆ</h3><p>ئەوانەی خۆت فرۆشتووتن — بۆ دووبارە چاپکردن</p></div>
            <button type="button" class="x" data-close aria-label="داخستن"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="md-b">
            <div class="sums">
                <div><small>ژمارە</small><b class="n" id="recCount">0</b></div>
                <div><small>کۆی فرۆشتن</small><b class="n" id="recTotal">0</b></div>
                <div><small id="recCashLbl">پارەی وەرگیراو</small><b class="n" id="recCash">0</b></div>
            </div>
            <div id="recList"></div>
        </div>
    </div>
</div>

<?php if (erp2_can('erp2.sales.return')): ?>
<!-- return: goods come back over the counter, the cash goes back -->
<div class="ov" id="retOv" hidden>
    <div class="md wide" role="dialog" aria-modal="true" aria-labelledby="retTitle">
        <div class="md-h">
            <span class="ic" style="background:linear-gradient(135deg,#fb7185,#be123c)"><i class="fas fa-rotate-left"></i></span>
            <div><h3 id="retTitle">گەڕاندنەوەی فرۆشتن</h3><p>پسوولەکە بدۆزەوە، بڕی گەڕاوە دیاری بکە و پارەکە بدەرەوە</p></div>
            <button type="button" class="x" data-close aria-label="داخستن"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="md-b" id="retFind">
            <div class="search">
                <i class="fas fa-receipt" aria-hidden="true"></i>
                <input id="retQ" type="search" autocomplete="off" placeholder="ژمارەی پسوولە سکان بکە یان بنووسە (تەنها کۆتاییەکەی بەسە)، یان ناوی کڕیار..." aria-label="گەڕانی پسوولە">
            </div>
            <div class="list" id="retList" role="listbox"></div>
            <a class="ret-link" href="<?= APP_URL ?>/erp2/sales-returns.php"><i class="fas fa-up-right-from-square"></i> گەڕاندنەوەی بێ پسوولە (پەڕەی گەڕاندنەوە)</a>
        </div>
        <div class="md-b" id="retForm" hidden>
            <div class="ret-inv" id="retInv"></div>
            <table class="rt">
                <thead><tr><th>کاڵا</th><th class="n">فرۆشراو</th><th class="n">نرخ</th><th>گەڕاوە</th><th class="n">کۆ</th></tr></thead>
                <tbody id="retRows"></tbody>
            </table>
            <div class="ret-opts" id="retOpts"></div>
            <div class="ret-note" id="retNote" hidden></div>
        </div>
        <div class="ret-foot" id="retFoot" hidden>
            <input class="reason" id="retReason" maxlength="250" placeholder="هۆکار (ئارەزوومەندانە) — نموونە: هەڵە لە فرۆشتن" autocomplete="off">
            <div class="ret-tot"><small>پارەی دەدرێتەوە</small><b class="n" id="retCash">0</b></div>
            <button type="button" class="ret-go" id="retGo" disabled><i class="fas fa-rotate-left"></i> گەڕاندنەوە و دانەوەی پارە</button>
        </div>
        <div class="md-b" id="retDone" hidden>
            <div class="ret-done">
                <i class="fas fa-circle-check" style="font-size:40px;color:var(--ok)"></i>
                <p id="retDoneWhat"></p>
                <div class="big n" id="retDoneCash">0</div>
                <p id="retDoneNote"></p>
                <div class="acts2">
                    <button type="button" id="retAgain"><i class="fas fa-rotate-left"></i> گەڕاندنەوەیەکی تر</button>
                    <button type="button" class="pri" data-close>تەواو <kbd>Enter</kbd></button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canRx): ?>
<!-- prescriptions -->
<div class="ov side" id="rxOv" hidden>
    <div class="md" role="dialog" aria-modal="true" aria-labelledby="rxTitle">
        <div class="md-h">
            <span class="ic" style="background:linear-gradient(135deg,#f472b6,#be185d)"><i class="fas fa-prescription-bottle-medical"></i></span>
            <div><h3 id="rxTitle">داواکاری دەرمان</h3><p>ئەوەی دکتۆرەکان نووسیویانە — کلیک بکە بۆ پڕکردنەوەی سەبەتە</p></div>
            <button type="button" class="x" data-close aria-label="داخستن"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="md-b"><div class="list" id="rxList"></div></div>
    </div>
</div>
<?php endif; ?>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script src="<?= asset_url('assets/js/rx-print.js') ?>" data-app="<?= APP_URL ?>"></script>
<script>
window.CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
</script>
<script>
(() => {
'use strict';
const CFG = <?= $cfgJson ?>;
const API = CFG.api;
const CSRF = window.CSRF_TOKEN;
const $ = s => document.querySelector(s);
const enc = encodeURIComponent;

/* ── helpers ─────────────────────────────────────────── */
const nf = n => Math.round(Number(n || 0)).toLocaleString('en-US');
const qf = n => { const v = Number(n || 0); return Number.isInteger(v) ? v.toLocaleString('en-US') : String(parseFloat(v.toFixed(3))); };
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
// Kurdish/Arabic keyboards type ٣ and ۳; the scanner and the books want 3.
const latin = s => String(s == null ? '' : s)
    .replace(/[٠-٩]/g, d => d.charCodeAt(0) - 0x660)
    .replace(/[۰-۹]/g, d => d.charCodeAt(0) - 0x6F0)
    .replace(/٫/g, '.');
const num = s => { const v = parseFloat(latin(s).replace(/[,٬،\s]/g, '')); return isFinite(v) ? v : 0; };
const round3 = v => Math.round(v * 1000) / 1000;
const initials = s => {
    const w = String(s || '').trim().split(/\s+/).filter(Boolean);
    return ((w[0] || '?').charAt(0) + (w[1] ? w[1].charAt(0) : (w[0] || '').charAt(1) || '')).toUpperCase();
};
const hue = s => { let h = 0; for (const ch of String(s)) h = (h * 31 + ch.charCodeAt(0)) % 360; return h; };

async function getJson(url) {
    try { const r = await fetch(url, { headers: { Accept: 'application/json' } }); return await r.json(); }
    catch (e) { return null; }
}
async function postJson(url, body) {
    try {
        const r = await fetch(url, { method: 'POST', body: JSON.stringify(body),
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF } });
        return await r.json();
    } catch (e) { return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' }; }
}
async function postForm(url, data) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    fd.append('csrf_token', CSRF);
    try {
        const r = await fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF } });
        return await r.json();
    } catch (e) { return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' }; }
}

/* browser storage: a convenience only (held sales, the unsaved cart) */
const KEY_HOLD = 'erp2_pos_hold_' + CFG.user.id, KEY_DRAFT = 'erp2_pos_draft_' + CFG.user.id, KEY_SOUND = 'erp2_pos_sound';
function store(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
function load(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }

/* ── sound ───────────────────────────────────────────── */
let actx = null, sound = load(KEY_SOUND) !== false;
function beep(kind) {
    if (!sound) return;
    try {
        actx = actx || new (window.AudioContext || window.webkitAudioContext)();
        const o = actx.createOscillator(), g = actx.createGain(), t = actx.currentTime;
        const err = kind === 'err', len = err ? .26 : (kind === 'done' ? .18 : .08);
        o.type = err ? 'square' : 'sine';
        o.frequency.setValueAtTime(err ? 210 : (kind === 'done' ? 880 : 1180), t);
        if (kind === 'done') o.frequency.setValueAtTime(1320, t + .09);
        g.gain.setValueAtTime(.0001, t);
        g.gain.exponentialRampToValueAtTime(err ? .08 : .14, t + .01);
        g.gain.exponentialRampToValueAtTime(.0001, t + len);
        o.connect(g); g.connect(actx.destination);
        o.start(t); o.stop(t + len + .02);
    } catch (e) {}
}
function paintSound() { $('#soundBtn i').className = 'fas ' + (sound ? 'fa-volume-high' : 'fa-volume-xmark'); }

/* ── toast ───────────────────────────────────────────── */
let toastT = null;
function toast(msg, kind, action) {
    const t = $('#toast');
    const icon = kind === 'err' ? 'fa-circle-exclamation' : (kind === 'ok' ? 'fa-circle-check' : 'fa-circle-info');
    t.className = 'toast show ' + (kind || '');
    t.innerHTML = '<i class="fas ' + icon + '"></i><span>' + esc(msg) + '</span>' + (action ? '<button type="button">' + esc(action.label) + '</button>' : '');
    if (action) t.querySelector('button').onclick = () => { t.classList.remove('show'); action.fn(); };
    clearTimeout(toastT);
    toastT = setTimeout(() => t.classList.remove('show'), action ? 6500 : 3400);
}

/* ── state ───────────────────────────────────────────── */
const walkIn = () => CFG.walkIn ? { id: CFG.walkIn.id, name: CFG.walkIn.name, walk: true, balance: 0 } : null;
const S = { wh: CFG.wh, tier: 'retail', cust: walkIn(), rows: [], sel: -1, discMode: 'amt', rx: null, busy: false, nextQty: 0,
            edit: CFG.edit || null };
// Editing: what the invoice already took comes back when it is re-posted.
const editBack = id => (S.edit && Number(S.edit.back[id])) || 0;
let held = load(KEY_HOLD) || [];

const scanEl = $('#scanBox'), recvEl = $('#recv'), discEl = $('#discIn'), noteEl = $('#note'), dateEl = $('#saleDate');

const lineTotal = r => Math.max(0, r.qty * r.price - (r.disc || 0));
function totals() {
    let sub = 0, qty = 0;
    S.rows.forEach(r => { sub += lineTotal(r); qty += r.qty; });
    const dv = num(discEl.value);
    const disc = Math.min(sub, Math.max(0, S.discMode === 'pct' ? Math.round(sub * Math.min(100, dv) / 100) : dv));
    const grand = Math.max(0, sub - disc);
    const rv = latin(recvEl.value).replace(/[,٬\s]/g, '');
    return { sub, disc, grand, qty, received: rv === '' ? null : num(rv) };
}
const overStock = r => r.qty * r.factor > r.stock + 1e-6;
const freeStock = r => r.stock / (r.factor || 1);

/* ── cart ────────────────────────────────────────────── */
function addProduct(p, qty, opts) {
    opts = opts || {};
    qty = qty > 0 ? qty : 1;
    hideDone();
    const factor = Number(p.unit_factor || 1) || 1;
    const stock = Number(p.stock || 0) + editBack(p.id);
    const at = S.rows.findIndex(r => r.pid === Number(p.id) && r.factor === factor);
    if (at < 0 && stock <= 0) {
        if (!opts.silent) { toast('«' + p.name + '» لە کۆگا نەماوە', 'err'); beep('err'); }
        return false;
    }
    // No sale price set yet: only someone allowed to price may ring it up,
    // and then types the price in on the line.
    const noPrice = at < 0 && !(Number(p.sale_price) > 0);
    if (noPrice && !CFG.canPrice) {
        if (!opts.silent) { toast('نرخی فرۆشتنی «' + p.name + '» دانەنراوە — لە کاڵاکان نرخی بۆ دابنێ', 'err'); beep('err'); }
        return false;
    }
    let i = at;
    if (at >= 0) {
        S.rows[at].qty = round3(S.rows[at].qty + qty);
    } else {
        const retail = Number(p.sale_price || 0);
        const whole = Number(p.wholesale_price || 0) > 0 ? Number(p.wholesale_price) * factor : retail;
        S.rows.push({
            pid: Number(p.id), name: p.name, code: p.product_code || '', qty, factor,
            retail, whole, price: S.tier === 'wholesale' ? whole : retail, manual: false, disc: 0,
            stock, unit_id: p.unit_id ? Number(p.unit_id) : null, unit: p.unit_name || p.unit_code || '', dosage: p.dosage || '',
            type: p.type_name || p.category_name || ''
        });
        i = S.rows.length - 1;
    }
    S.sel = i;
    renderCart();
    const el = document.querySelector('.row[data-i="' + i + '"]');
    if (el) { el.classList.add('bump'); el.scrollIntoView({ block: 'nearest' }); }
    if (noPrice) {
        const pr = el && el.querySelector('.pr');
        if (pr) pr.focus();
        toast('«' + p.name + '» نرخی نییە — نرخەکە بنووسە', 'err');
        return true;
    }
    if (overStock(S.rows[i])) {
        if (!opts.silent) toast('«' + p.name + '» — بەردەست تەنها ' + qf(freeStock(S.rows[i])), 'err');
        beep('err');
    } else if (!opts.silent) {
        beep('ok');
    }
    return true;
}

function rowHtml(r, i) {
    const over = overStock(r);
    const meta = [];
    if (r.type) meta.push('<b style="color:#0369a1;font-weight:800">' + esc(r.type) + '</b>');
    if (r.code) meta.push('<span class="n">' + esc(r.code) + '</span>');
    meta.push(over ? '<span class="warn">بەردەست ' + qf(freeStock(r)) + '</span>' : 'بەردەست ' + qf(freeStock(r)));
    if (r.factor > 1) meta.push(esc(r.unit || 'پاکێت') + ' <span class="n">×' + qf(r.factor) + '</span>');
    if (r.dosage) meta.push('<span class="dose"><i class="fas fa-prescription"></i> ' + esc(r.dosage) + '</span>');
    return '<div class="row' + (i === S.sel ? ' sel' : '') + (over ? ' over' : '') + '" data-i="' + i + '">'
        + '<span class="ix">' + (i + 1) + '</span>'
        // dir=auto: a Latin drug name is cut at its END, not its start
        + '<div class="it"><b dir="auto" title="' + esc(r.name) + '">' + esc(r.name) + '</b><small>' + meta.join(' · ') + '</small></div>'
        + '<div class="qty"><button type="button" data-act="inc" tabindex="-1" aria-label="زیادکردن">+</button>'
        +   '<input class="n" data-f="qty" inputmode="decimal" autocomplete="off" value="' + qf(r.qty).replace(/,/g, '') + '" aria-label="بڕ">'
        +   '<button type="button" data-act="dec" tabindex="-1" aria-label="کەمکردنەوە">−</button></div>'
        + '<input class="f n pr" data-f="price" inputmode="decimal" autocomplete="off" value="' + nf(r.price) + '" aria-label="نرخ"'
        +   (CFG.canPrice ? '' : ' readonly title="ڕێگەت نییە نرخ بگۆڕیت"') + '>'
        + '<input class="f n ds" data-f="disc" inputmode="decimal" autocomplete="off" value="' + (r.disc ? nf(r.disc) : '') + '" placeholder="0" aria-label="داشکاندن">'
        + '<b class="lt n">' + nf(lineTotal(r)) + '</b>'
        + '<button type="button" class="rm" data-act="rm" tabindex="-1" aria-label="سڕینەوە"><i class="fas fa-xmark"></i></button>'
        + '</div>';
}

function renderCart() {
    const has = S.rows.length > 0;
    $('#rows').hidden = !has; $('#cols').hidden = !has; $('#cartEmpty').hidden = has;
    $('#rows').innerHTML = S.rows.map(rowHtml).join('');
    $('#cnt').textContent = S.rows.length;
    renderTotals();
    renderCards();
    persist();
}

let lastGrand = 0;
function renderTotals() {
    const t = totals();
    $('#tGrand').textContent = nf(t.grand);
    if (t.grand !== lastGrand) { const b = $('#tBig'); b.classList.remove('pulse'); void b.offsetWidth; b.classList.add('pulse'); lastGrand = t.grand; }
    $('#tSub').textContent = nf(t.sub);
    $('#tLines').hidden = !(t.disc > 0);
    $('#tDisc').textContent = '− ' + nf(t.disc);
    $('#tCount').textContent = S.rows.length ? S.rows.length + ' کاڵا · ' + qf(t.qty) + ' دانە' : '';
    $('#tItems').textContent = S.rows.length ? 'کۆی دانە: ' + qf(t.qty) : '';
    recvEl.placeholder = nf(t.grand);
    const res = $('#result');
    if (!S.rows.length) {
        res.className = 'result'; res.innerHTML = '<span>چاوەڕێی کاڵا...</span>';
    } else if (t.received === null) {
        res.className = 'result'; res.innerHTML = '<span><i class="fas fa-circle-check" style="color:var(--ok)"></i> پارەی تەواو وەردەگیرێت</span><b class="n">' + nf(t.grand) + '</b>';
    } else if (t.received >= t.grand) {
        res.className = 'result change'; res.innerHTML = '<span>گەڕاوە بۆ کڕیار</span><b class="n">' + nf(t.received - t.grand) + '</b>';
    } else if (S.cust && !S.cust.walk) {
        res.className = 'result debt'; res.innerHTML = '<span>دەچێتە سەر قەرزی ' + esc(S.cust.name) + '</span><b class="n">' + nf(t.grand - t.received) + '</b>';
    } else {
        res.className = 'result short'; res.innerHTML = '<span>پارەکە کەمە — کڕیاری نەقد قەرز ناکات</span><b class="n">' + nf(t.grand - t.received) + '</b>';
    }
    const off = !S.rows.length;
    ['#btnPay', '#btnNoPrint', '#btnA4'].forEach(id => { $(id).disabled = off; });
}

function renderCust() {
    const c = S.cust, b = $('#custBtn');
    b.classList.toggle('named', !!c && !c.walk);
    b.querySelector('.ci i').className = 'fas ' + (c && !c.walk ? 'fa-user-check' : 'fa-user');
    $('#custName').textContent = c ? c.name : 'کڕیار هەڵبژێرە';
    $('#custMeta').innerHTML = !c ? 'F4 بۆ هەڵبژاردن'
        : c.walk ? 'نەقد · <kbd>F4</kbd> گۆڕین'
        : (c.balance > 0.5 ? '<span class="debt">قەرزی پێشوو <span class="n">' + nf(c.balance) + '</span></span>' : (c.phone ? '<span class="n">' + esc(c.phone) + '</span>' : 'بێ قەرز'));
}

function renderTier() {
    document.querySelectorAll('#tierSeg button').forEach(b => {
        const on = b.dataset.tier === S.tier;
        b.classList.toggle('on', on); b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
}

function renderRx() {
    $('#rxBan').hidden = !S.rx;
    if (S.rx) {
        $('#rxBanName').textContent = S.rx.patient;
        $('#rxBanDoc').textContent = S.rx.doctor ? '(د. ' + S.rx.doctor.replace(/^د\.\s*/, '') + ')' : '';
    }
}

function renderHeld() {
    $('#heldWrap').hidden = !held.length;
    $('#held').innerHTML = held.map((h, i) => {
        const sum = (h.snap.rows || []).reduce((s, r) => s + lineTotal(r), 0);
        const who = h.snap.cust && !h.snap.cust.walk ? h.snap.cust.name : '#' + (i + 1);
        return '<span class="hchip"><button type="button" data-resume="' + i + '" title="گەڕانەوە بۆ ئەم فرۆشتنە">'
            + esc(who) + ' · <span class="n">' + nf(sum) + '</span></button>'
            + '<button type="button" class="hx" data-drop="' + i + '" aria-label="لابردن">×</button></span>';
    }).join('');
}

function renderAll() { renderTier(); renderCust(); renderRx(); renderCart(); renderHeld(); }

function selectRow(i) {
    if (!S.rows.length) { S.sel = -1; return; }
    S.sel = Math.max(0, Math.min(S.rows.length - 1, i));
    document.querySelectorAll('#rows .row').forEach(el => el.classList.toggle('sel', Number(el.dataset.i) === S.sel));
    const el = document.querySelector('.row[data-i="' + S.sel + '"]');
    if (el) el.scrollIntoView({ block: 'nearest' });
}
function changeQty(i, d) {
    const r = S.rows[i];
    if (!r) return;
    r.qty = round3(Math.max(1, r.qty + d));
    S.sel = i;
    renderCart();
    if (d > 0 && overStock(r)) { toast('«' + r.name + '» — بەردەست تەنها ' + qf(freeStock(r)), 'err'); beep('err'); }
}
function removeRow(i) {
    const r = S.rows[i];
    if (!r) return;
    S.rows.splice(i, 1);
    if (S.sel >= S.rows.length) S.sel = S.rows.length - 1;
    renderCart();
    toast('«' + r.name + '» لابرا', '', { label: 'گەڕاندنەوە', fn: () => { S.rows.splice(i, 0, r); S.sel = i; renderCart(); } });
}

/* cart events (delegated) */
const rowsEl = $('#rows');
rowsEl.addEventListener('click', e => {
    const row = e.target.closest('.row'); if (!row) return;
    const i = Number(row.dataset.i);
    const act = e.target.closest('[data-act]');
    if (act) {
        if (act.dataset.act === 'inc') changeQty(i, 1);
        else if (act.dataset.act === 'dec') changeQty(i, -1);
        else if (act.dataset.act === 'rm') removeRow(i);
        scanEl.focus();
        return;
    }
    if (!e.target.closest('input')) selectRow(i);
});
rowsEl.addEventListener('focusin', e => {
    const inp = e.target.closest('input'); if (!inp) return;
    const row = inp.closest('.row'); selectRow(Number(row.dataset.i));
    if (inp.dataset.f !== 'qty') inp.value = inp.value.replace(/,/g, '');
    setTimeout(() => inp.select(), 0);
});
rowsEl.addEventListener('input', e => {
    const inp = e.target.closest('input'); if (!inp) return;
    const row = inp.closest('.row'), i = Number(row.dataset.i), r = S.rows[i], f = inp.dataset.f;
    const v = num(inp.value);
    if (f === 'qty') r.qty = round3(Math.max(0, v));
    else if (f === 'price') { r.price = Math.max(0, v); r.manual = true; }
    else if (f === 'disc') r.disc = Math.max(0, v);
    // update only what depends on this cell, so typing never loses focus
    row.querySelector('.lt').textContent = nf(lineTotal(r));
    row.classList.toggle('over', overStock(r));
    renderTotals(); renderCards(); persist();
});
rowsEl.addEventListener('focusout', e => {
    const inp = e.target.closest('input'); if (!inp) return;
    const r = S.rows[Number(inp.closest('.row').dataset.i)]; if (!r) return;
    const f = inp.dataset.f;
    if (f === 'qty' && !(r.qty > 0)) { r.qty = 1; }
    if (f === 'qty') inp.value = qf(r.qty).replace(/,/g, '');
    else if (f === 'price') inp.value = nf(r.price);
    else if (f === 'disc') inp.value = r.disc ? nf(r.disc) : '';
    inp.closest('.row').querySelector('.lt').textContent = nf(lineTotal(r));
    renderTotals();
});
rowsEl.addEventListener('keydown', e => {
    const inp = e.target.closest('input'); if (!inp) return;
    if (e.key === 'Enter' || e.key === 'Escape') { e.preventDefault(); inp.blur(); scanEl.focus(); }
});

/* ── scanner ─────────────────────────────────────────── */
let scanChain = Promise.resolve();
/* The barcode on a printed prescription (erp2/prescription-print.php): the
 * patient hands over the paper, one scan fills the cart with its medicines. */
function scannedRx(p) {
    const no = msg => { beep('err'); toast(msg, 'err'); };
    if (!CFG.canRx) { no('ڕێگەت نییە داواکاری دەرمان بکەیتەوە'); return; }
    if (S.edit) { no(EDIT_BLOCK); return; }
    if (p.status === 'missing') { no('ڕەچەتەی #' + p.id + ' نەدۆزرایەوە'); return; }
    if (p.status === 'sold') { no('ڕەچەتەی #' + p.id + ' پێشتر فرۆشراوە' + (p.invoice_number ? ' — ' + p.invoice_number : '')); return; }
    if (p.status === 'cancelled') { no('ڕەچەتەی #' + p.id + ' ڕەتکراوەتەوە'); return; }
    loadRx(-1, Number(p.id));   // pending, or one that was sent «outside» and is bought here after all
}
function parseScan(raw) {
    const m = raw.match(/^(\d+(?:\.\d+)?)\s*[*xX×]\s*(.+)$/);
    return m ? { qty: parseFloat(m[1]), code: m[2].trim() } : { qty: 1, code: raw };
}
async function doScan(raw) {
    const { qty, code } = parseScan(raw);
    if (!code) return;
    const d = await getJson(API.pos + '?action=scan&warehouse_id=' + S.wh + '&code=' + enc(code));
    if (d && d.ok && d.product) { addProduct(d.product, qty); return; }
    if (d && d.ok && d.prescription) { scannedRx(d.prescription); return; }
    // Not a code: treat it as a name. One hit sells it; several go to the cards.
    const s = await getJson(API.pos + '?action=catalog&limit=48&warehouse_id=' + S.wh + '&q=' + enc(code));
    const list = (s && s.ok && s.results) || [];
    if (list.length === 1) { addProduct(list[0], qty); return; }
    beep('err');
    if (list.length) {
        $('#catQ').value = code; catCat = 0; catType = 0; paintCats();
        showCatalog(list, 'search');
        S.nextQty = qty > 1 ? qty : 0;
        openCatalog();
        catMove(0, true);
        catQ.focus();
        toast(list.length + ' کاڵا دۆزرایەوە — یەکێکیان هەڵبژێرە');
    } else {
        toast('کاڵا نەدۆزرایەوە: ' + code, 'err');
    }
}
scanEl.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const raw = latin(scanEl.value).trim();
        if (!raw) { if (!$('#done').hidden) hideDone(); return; }
        scanEl.value = '';
        scanChain = scanChain.then(() => doScan(raw));
    } else if (e.key === 'Escape') {
        if (scanEl.value) { e.preventDefault(); e.stopPropagation(); scanEl.value = ''; }
    }
});

/* ── catalog ─────────────────────────────────────────── */
const gridEl = $('#grid'), catQ = $('#catQ');
let catList = [], catOn = -1, catReq = 0, catCat = 0, catType = 0, catTimer = null;
function inCartMap() {
    const m = {};
    S.rows.forEach(r => { m[r.pid] = (m[r.pid] || 0) + r.qty * r.factor; });
    return m;
}
function renderCards() {
    if (!catList.length) return;
    const inCart = inCartMap();
    gridEl.innerHTML = catList.map((p, i) => {
        const stock = Number(p.stock || 0) + editBack(p.id), used = inCart[p.id] || 0, left = stock - used;
        const cls = left <= 0 ? 'out' : (left <= 5 ? 'low' : '');
        return '<button type="button" class="pc' + (stock <= 0 ? ' out' : '') + (i === catOn ? ' on' : '') + '" data-i="' + i + '" title="' + esc(p.name) + '">'
            + '<span class="pc-av" style="--h:' + hue(p.category_id || p.name) + '">' + esc(initials(p.name)) + '</span>'
            + (used > 0 ? '<span class="pc-in">' + qf(used) + '</span>' : '')
            + '<span class="pc-plus"><i class="fas fa-plus"></i></span>'
            + ((p.type_name || p.category_name) ? '<span class="pc-tag">' + esc(p.type_name || p.category_name) + '</span>' : '')
            + '<span class="pc-name">' + esc(p.name) + '</span>'
            + '<span class="pc-foot"><b class="pc-price n">' + nf(S.tier === 'wholesale' && Number(p.wholesale_price) > 0 ? p.wholesale_price : p.sale_price) + '</b>'
            + '<span class="st ' + cls + '">' + (left <= 0 ? 'نەماوە' : qf(left) + ' ' + esc(p.unit_name || '')) + '</span></span>'
            + '</button>';
    }).join('');
}
function showCatalog(list, mode) {
    catList = list || []; catOn = -1;
    const q = catQ.value.trim();
    $('#catLabel').textContent = mode === 'top' ? (catCat ? 'بە ڕیزی پەڕەی ڕەچەتە' : 'زۆرترین فرۆشراو') : 'ئەنجامی گەڕان';
    $('#catCount').textContent = catList.length ? catList.length : '';
    if (!catList.length) {
        gridEl.innerHTML = '<div class="nores"><i class="fas fa-capsules"></i>' + (q ? 'هیچ کاڵایەک بە «' + esc(q) + '» نەدۆزرایەوە' : 'هیچ کاڵایەک نییە') + '</div>';
        return;
    }
    renderCards();
}
async function loadCatalog() {
    const my = ++catReq, q = latin(catQ.value).trim();
    if (!catList.length) gridEl.innerHTML = '<div class="sk"></div>'.repeat(6);
    const d = await getJson(API.pos + '?action=catalog&warehouse_id=' + S.wh + '&category_id=' + catCat + '&type_id=' + catType + '&q=' + enc(q));
    if (my !== catReq) return;
    if (!d || !d.ok) { gridEl.innerHTML = '<div class="nores"><i class="fas fa-plug-circle-xmark"></i>کێشەی پەیوەندی</div>'; catList = []; return; }
    showCatalog(d.results, q ? 'search' : 'top');
}
function paintCats() {
    document.querySelectorAll('#cats button').forEach(b => b.classList.toggle('on', Number(b.dataset.cat) === catCat));
    paintTypes();
}
/** The open group's types (Tab, Capsule, … / Splat, Kin, …) as the paper's circles. */
function paintTypes() {
    const list = CFG.types[catCat] || [], box = $('#typeChips');
    box.hidden = !list.length;
    box.innerHTML = list.map(t => '<button type="button" role="radio" aria-checked="' + (t.id === catType) + '"'
        + (t.id === catType ? ' class="on"' : '') + ' data-type="' + t.id + '">' + esc(t.name) + '</button>').join('');
}
$('#typeChips').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    const id = Number(b.dataset.type);
    catType = catType === id ? 0 : id;     // the chosen circle again clears it
    paintTypes(); loadCatalog();
});
$('#cats').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    catCat = Number(b.dataset.cat); catType = 0; paintCats(); loadCatalog();
});
catQ.addEventListener('input', () => { clearTimeout(catTimer); catTimer = setTimeout(loadCatalog, 180); });
function pickCard(i) {
    const p = catList[i]; if (!p) return;
    const q = S.nextQty || 1; S.nextQty = 0;
    addProduct(p, q);
}
gridEl.addEventListener('click', e => {
    const c = e.target.closest('.pc'); if (!c) return;
    pickCard(Number(c.dataset.i));
    if (window.matchMedia('(max-width:1100px)').matches) closeCatalog();
    scanEl.focus();
});
function catCols() { return Math.max(1, getComputedStyle(gridEl).gridTemplateColumns.split(' ').length); }
function catMove(d, abs) {
    if (!catList.length) return;
    catOn = abs ? d : Math.max(0, Math.min(catList.length - 1, (catOn < 0 ? 0 : catOn + d)));
    gridEl.querySelectorAll('.pc').forEach(el => el.classList.toggle('on', Number(el.dataset.i) === catOn));
    const el = gridEl.querySelector('.pc.on'); if (el) el.scrollIntoView({ block: 'nearest' });
}
catQ.addEventListener('keydown', e => {
    // RTL grid: the next card is to the LEFT.
    if (e.key === 'ArrowDown') { e.preventDefault(); catMove(catOn < 0 ? 0 : catCols()); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); catMove(-catCols()); }
    else if (e.key === 'ArrowLeft' && catOn >= 0) { e.preventDefault(); catMove(1); }
    else if (e.key === 'ArrowRight' && catOn >= 0) { e.preventDefault(); catMove(-1); }
    else if (e.key === 'Enter') {
        e.preventDefault();
        if (catList.length) { pickCard(catOn >= 0 ? catOn : 0); catQ.select(); }
    } else if (e.key === 'Escape') {
        e.preventDefault(); e.stopPropagation();
        if (catQ.value) { catQ.value = ''; loadCatalog(); } else { closeCatalog(); scanEl.focus(); }
    }
});
function openCatalog() { $('#catp').classList.add('open'); }
function closeCatalog() { $('#catp').classList.remove('open'); }
$('#catToggle').addEventListener('click', () => { $('#catp').classList.toggle('open'); if ($('#catp').classList.contains('open')) catQ.focus(); });

/* ── price tier, discount, cash ──────────────────────── */
$('#tierSeg').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b || b.dataset.tier === S.tier) return;
    S.tier = b.dataset.tier;
    // Lines the cashier priced by hand keep their price.
    S.rows.forEach(r => { if (!r.manual) r.price = S.tier === 'wholesale' ? r.whole : r.retail; });
    renderTier(); renderCart();
    toast(S.tier === 'wholesale' ? 'نرخی جوملە' : 'نرخی تاک');
});
$('#discMode').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    S.discMode = b.dataset.m;
    document.querySelectorAll('#discMode button').forEach(x => x.classList.toggle('on', x === b));
    renderTotals(); persist();
});
[discEl, recvEl].forEach(el => {
    el.addEventListener('input', () => { renderTotals(); persist(); });
    el.addEventListener('focus', () => { el.value = latin(el.value).replace(/,/g, ''); setTimeout(() => el.select(), 0); });
    el.addEventListener('blur', () => { if (el.value.trim() !== '') el.value = nf(num(el.value)); renderTotals(); });
});
recvEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); checkout('80'); } });
discEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); recvEl.focus(); } });
$('#cash').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    const t = totals(), v = b.dataset.cash;
    if (v === 'exact') recvEl.value = nf(t.grand);
    else if (v === 'clear') recvEl.value = '';
    else recvEl.value = nf((t.received || 0) + Number(v));   // counting notes: each tap adds
    renderTotals(); persist();
});

/* ── customer picker ─────────────────────────────────── */
let custList = [], custOn = -1, custReq = 0, custTimer = null;
function openOv(id) {
    $('#pos').inert = true;
    $(id).hidden = false;
}
function closeOv(id) {
    $(id).hidden = true;
    if (!document.querySelector('.ov:not([hidden])')) $('#pos').inert = false;
    scanEl.focus();
}
document.querySelectorAll('.ov').forEach(ov => {
    ov.addEventListener('mousedown', e => { if (e.target === ov) closeOv('#' + ov.id); });
    ov.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => closeOv('#' + ov.id)));
});
function openCustomer() {
    openOv('#custOv');
    $('#custQ').value = ''; $('#addCust').hidden = true;
    searchCust();
    setTimeout(() => $('#custQ').focus(), 0);
}
async function searchCust() {
    const my = ++custReq;
    const d = await getJson(API.pos + '?action=customers&q=' + enc(latin($('#custQ').value).trim()));
    if (my !== custReq) return;
    custList = (d && d.ok && d.results) || []; custOn = custList.length ? 0 : -1;
    paintCust();
}
function paintCust() {
    const box = $('#custList');
    if (!custList.length) { box.innerHTML = '<div class="empty-sm">هیچ کڕیارێک نەدۆزرایەوە — دەتوانیت نوێ زیاد بکەیت</div>'; return; }
    box.innerHTML = custList.map((c, i) => {
        const walk = Number(c.is_walk_in) === 1, bal = Number(c.balance || 0);
        return '<button type="button" class="li' + (i === custOn ? ' on' : '') + '" data-i="' + i + '" role="option">'
            + '<span class="av' + (walk ? ' walk' : '') + '">' + (walk ? '<i class="fas fa-person-walking"></i>' : esc(initials(c.name))) + '</span>'
            + '<span class="tx"><b>' + esc(c.name) + '</b><small>' + (walk ? 'کڕیاری نەقد — پارەی تەواو' : '<span class="n">' + esc([c.phone, c.code].filter(Boolean).join(' · ')) + '</span>') + '</small></span>'
            + (walk ? '' : '<span class="end n ' + (bal > 0.5 ? 'debt' : 'ok') + '">' + (bal > 0.5 ? nf(bal) : 'بێ قەرز') + '</span>')
            + '</button>';
    }).join('');
}
function chooseCust(c) {
    S.cust = { id: Number(c.id), name: c.name, phone: c.phone || '', walk: Number(c.is_walk_in) === 1, balance: Number(c.balance || 0) };
    renderCust(); renderTotals(); persist();
    closeOv('#custOv');
}
$('#custQ').addEventListener('input', () => { clearTimeout(custTimer); custTimer = setTimeout(searchCust, 180); });
$('#custQ').addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); custOn = Math.min(custList.length - 1, custOn + 1); paintCust(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); custOn = Math.max(0, custOn - 1); paintCust(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (custList[custOn]) chooseCust(custList[custOn]); }
});
$('#custList').addEventListener('click', e => { const b = e.target.closest('.li'); if (b) chooseCust(custList[Number(b.dataset.i)]); });
$('#addCustToggle').addEventListener('click', () => {
    const box = $('#addCust'); box.hidden = !box.hidden;
    if (!box.hidden) { $('#newCustName').value = $('#custQ').value.trim(); $('#newCustName').focus(); }
});
async function saveNewCust() {
    const name = $('#newCustName').value.trim();
    if (!name) { $('#newCustName').focus(); return; }
    const d = await postForm(API.pos, { action: 'add_customer', name, phone: latin($('#newCustPhone').value.trim()) });
    if (!d || !d.ok) { toast((d && (d.error || d.message)) || 'هەڵە', 'err'); return; }
    $('#newCustPhone').value = '';
    chooseCust(d.customer);
    toast(d.existed ? 'ئەم کڕیارە پێشتر هەبوو — هەڵبژێردرا' : 'کڕیاری نوێ زیادکرا', 'ok');
}
$('#newCustSave').addEventListener('click', saveNewCust);
['#newCustName', '#newCustPhone'].forEach(id => $(id).addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveNewCust(); } }));
$('#custBtn').addEventListener('click', openCustomer);

/* ── checkout ────────────────────────────────────────── */
let printFrame = null;
function printInvoice(id, size) {
    if (printFrame) printFrame.remove();
    printFrame = document.createElement('iframe');
    printFrame.setAttribute('aria-hidden', 'true');
    printFrame.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-9999px;bottom:0';
    printFrame.src = API.print + '?id=' + id + '&size=' + size;
    document.body.appendChild(printFrame);
}

async function checkout(print) {
    if (S.busy) return;
    if (!S.rows.length) { toast('سەبەتەکە بەتاڵە', 'err'); beep('err'); scanEl.focus(); return; }
    if (!S.cust) { toast('سەرەتا کڕیار هەڵبژێرە', 'err'); openCustomer(); return; }
    const zero = S.rows.find(r => !(r.qty > 0));
    if (zero) { toast('بڕی «' + zero.name + '» دروست نییە', 'err'); return; }
    const unpriced = S.rows.find(r => !(r.price > 0));
    if (unpriced) { toast('نرخی «' + unpriced.name + '» سفرە — نرخەکە بنووسە', 'err'); beep('err'); selectRow(S.rows.indexOf(unpriced)); return; }
    const over = S.rows.find(overStock);
    if (over) { toast('«' + over.name + '» — بەردەست تەنها ' + qf(freeStock(over)), 'err'); beep('err'); selectRow(S.rows.indexOf(over)); return; }
    const t = totals();
    const paid = t.received === null ? t.grand : Math.min(t.received, t.grand);
    if (S.cust.walk && paid < t.grand - 0.5) {
        toast('کڕیاری نەقد دەبێت هەموو پارەکە بدات — بۆ قەرز کڕیارێکی ناودار هەڵبژێرە (F4)', 'err');
        beep('err'); recvEl.focus(); return;
    }
    const payload = {
        customer_id: S.cust.id,
        warehouse_id: S.wh,
        invoice_date: dateEl.value || CFG.today,
        discount_amount: t.disc,
        paid_amount: paid,
        payment_type: paid <= 0 ? 'credit' : (paid >= t.grand - 0.5 ? 'cash' : 'partial'),
        notes: noteEl.value.trim() || null,
        items: S.rows.map(r => ({
            product_id: r.pid, quantity: r.qty, unit_price: r.price, discount_amount: r.disc || 0,
            unit_factor: r.factor, unit_id: r.unit_id
        }))
    };
    // Editing posts the same payload to 'update' with the invoice id.
    if (S.edit) payload.id = S.edit.id;
    S.busy = true; document.body.classList.add('busy');
    const d = await postJson(API.sales + '?action=' + (S.edit ? 'update' : 'save'), payload);
    S.busy = false; document.body.classList.remove('busy');
    if (!d || !d.ok) { toast((d && (d.error || d.message)) || 'هەڵە لە پاشەکەوتکردن', 'err'); beep('err'); return; }
    if (S.edit) { editDone(d, print, paid); return; }

    // The sale is booked. Flag the prescription; a failure here must never
    // read as "the sale failed", so it is quiet.
    let rxMarked = null;
    if (S.rx) {
        rxMarked = postForm(API.rx, { action: 'mark_sold', id: S.rx.id, invoice_id: d.id }).then(refreshRx).catch(() => {});
    }
    if (print) printInvoice(d.id, print);
    beep('done');
    showDone({
        rx: S.rx ? S.rx.id : 0, rxMarked: rxMarked,
        id: d.id, number: d.invoice_number, total: Number(d.grand_total), printed: print,
        received: t.received === null ? Number(d.grand_total) : t.received,
        change: t.received !== null ? Math.max(0, t.received - Number(d.grand_total)) : 0,
        debt: Math.max(0, Number(d.grand_total) - paid), cust: S.cust
    });
    resetSale();
    refreshToday();
    loadCatalog();      // stock moved
}
$('#btnPay').addEventListener('click', () => checkout('80'));
$('#btnNoPrint').addEventListener('click', () => checkout(''));
$('#btnA4').addEventListener('click', () => checkout('a4'));

/* Edit booked: what to settle at the desk, then back to the invoice list. */
function editDone(d, print, paid) {
    if (print) printInvoice(d.id, print);
    beep('done');
    S.busy = true;      // already re-posted; another F10 must not post it again
    showDone({
        id: d.id, number: d.invoice_number, total: Number(d.grand_total), printed: print, edited: true,
        received: paid, change: 0, debt: Math.max(0, Number(d.grand_total) - paid), cust: S.cust,
        diff: paid - S.edit.paid    // + collect from the customer, − hand back
    });
}

let doneT = null, lastSale = null;
function showDone(info) {
    lastSale = info;
    $('#doneTitle').textContent = info.edited ? 'نوێکرایەوە' : 'فرۆشرا';
    $('#doneNo').textContent = info.number;
    let g = '<div><small>کۆی وەسڵ</small><b class="n">' + nf(info.total) + '</b></div>'
          + '<div><small>' + (info.edited ? 'پارەی دراو' : 'وەرگیرا') + '</small><b class="n">' + nf(info.received) + '</b></div>';
    if (info.change > 0) g += '<div class="done-change"><small>گەڕاوە بۆ کڕیار</small><b class="n">' + nf(info.change) + '</b></div>';
    if (info.edited && Math.abs(info.diff) > 0.5) {
        g += info.diff < 0
            ? '<div class="done-change"><small>بگەڕێنەوە بۆ کڕیار</small><b class="n">' + nf(-info.diff) + '</b></div>'
            : '<div class="done-debt"><small>وەربگرە لە کڕیار</small><b class="n">' + nf(info.diff) + '</b></div>';
    }
    if (info.debt > 0.5) g += '<div class="done-debt"><small>چووە سەر قەرزی ' + esc(info.cust.name) + '</small><b class="n">' + nf(info.debt) + '</b></div>';
    $('#doneGrid').innerHTML = g;
    $('#donePrint').hidden = info.printed === '80';
    $('#doneA4').hidden = info.printed === 'a4';
    $('#doneRx').hidden = !info.rx;
    $('#doneNext').innerHTML = (info.edited ? 'گەڕانەوە بۆ وەسڵەکان' : 'فرۆشتنی نوێ') + ' <kbd>Enter</kbd>';
    $('#done').hidden = false;
    clearTimeout(doneT);
    // An edit's card stays until it is dismissed: a print may still be running.
    if (!info.edited) doneT = setTimeout(hideDone, info.change > 0 ? 20000 : 6000);
    scanEl.focus();
}
function hideDone() {
    if (lastSale && lastSale.edited) { location.href = CFG.invoicesUrl; return; }
    $('#done').hidden = true; clearTimeout(doneT);
}
$('#done').addEventListener('click', e => { if (e.target.id === 'done') { hideDone(); scanEl.focus(); } });
$('#doneNext').addEventListener('click', () => { hideDone(); scanEl.focus(); });
$('#donePrint').addEventListener('click', () => { if (lastSale) printInvoice(lastSale.id, '80'); scanEl.focus(); });
$('#doneA4').addEventListener('click', () => { if (lastSale) printInvoice(lastSale.id, 'a4'); scanEl.focus(); });
// the sold prescription, with its prices and the «فرۆشرا» stamp — once it is marked sold
$('#doneRx').addEventListener('click', async () => {
    if (!lastSale || !lastSale.rx || !window.RxPrint) return;
    clearTimeout(doneT);
    if (lastSale.rxMarked) await lastSale.rxMarked;
    window.RxPrint.open(lastSale.rx, { sold: true, onClose: () => scanEl.focus() });
});

/* ── sale lifecycle: reset, hold, draft ──────────────── */
function snapshot() {
    return JSON.parse(JSON.stringify({ rows: S.rows, cust: S.cust, tier: S.tier, discMode: S.discMode,
        disc: discEl.value, received: recvEl.value, note: noteEl.value, rx: S.rx }));
}
function restore(sn) {
    S.rows = sn.rows || []; S.cust = sn.cust || walkIn(); S.tier = sn.tier || 'retail';
    S.discMode = sn.discMode || 'amt'; S.rx = sn.rx || null; S.sel = S.rows.length - 1;
    discEl.value = sn.disc || ''; recvEl.value = sn.received || ''; noteEl.value = sn.note || '';
    document.querySelectorAll('#discMode button').forEach(x => x.classList.toggle('on', x.dataset.m === S.discMode));
    renderAll();
}
function resetSale() {
    restore({});
    dateEl.value = CFG.today;
}
let persistT = null;
function persist() {
    // An edit is never kept as a draft: restored later it would be sold again
    // as a new sale. The cashier's own draft is left untouched meanwhile.
    if (S.edit) return;
    clearTimeout(persistT);
    persistT = setTimeout(() => store(KEY_DRAFT, S.rows.length ? { at: Date.now(), snap: snapshot() } : null), 300);
}
const EDIT_BLOCK = 'لە کاتی دەستکاریکردنی وەسڵدا ناکرێت';
function hold() {
    if (S.edit) { toast(EDIT_BLOCK, 'err'); return; }
    if (!S.rows.length) { toast('هیچ شتێک نییە بۆ ڕاگرتن'); return; }
    if (held.length >= 8) { toast('زۆرترین ٨ فرۆشتن دەکرێت ڕابگیرێت', 'err'); return; }
    held.push({ at: Date.now(), snap: snapshot() });
    store(KEY_HOLD, held);
    resetSale();
    toast('فرۆشتنەکە ڕاگیرا — کاتێک ویستت لە خوارەوە کلیکی لێ بکە', 'ok');
    scanEl.focus();
}
function resume(i) {
    if (S.edit) { toast(EDIT_BLOCK, 'err'); return; }
    const h = held.splice(i, 1)[0]; if (!h) return;
    if (S.rows.length) held.push({ at: Date.now(), snap: snapshot() });   // swap, never lose the open sale
    store(KEY_HOLD, held);
    restore(h.snap);
    scanEl.focus();
}
$('#held').addEventListener('click', e => {
    const r = e.target.closest('[data-resume]'), x = e.target.closest('[data-drop]');
    if (r) resume(Number(r.dataset.resume));
    if (x) {
        const i = Number(x.dataset.drop), h = held.splice(i, 1)[0];
        store(KEY_HOLD, held); renderHeld();
        toast('فرۆشتنی ڕاگیراو لابرا', '', { label: 'گەڕاندنەوە', fn: () => { held.splice(i, 0, h); store(KEY_HOLD, held); renderHeld(); } });
    }
});
$('#holdBtn').addEventListener('click', hold);
$('#clearBtn').addEventListener('click', () => {
    if (S.edit) return;
    if (!S.rows.length && !S.rx) return;
    const sn = snapshot();
    resetSale();
    toast('سەبەتەکە پاککرایەوە', '', { label: 'گەڕاندنەوە', fn: () => restore(sn) });
    scanEl.focus();
});

/* ── today's receipts ────────────────────────────────── */
async function refreshToday() {
    const d = await getJson(API.pos + '?action=today');
    if (!d || !d.ok) return null;
    $('#todayTotal').textContent = nf(d.total);
    $('#todayBtn').title = 'ئەمڕۆ: ' + d.count + ' فرۆشتن';
    return d;
}
async function openReceipts() {
    openOv('#recOv');
    $('#recList').innerHTML = '<div class="empty-sm">...</div>';
    const d = await refreshToday();
    if (!d) { $('#recList').innerHTML = '<div class="empty-sm">کێشەی پەیوەندی</div>'; return; }
    $('#recCount').textContent = nf(d.count); $('#recTotal').textContent = nf(d.total); $('#recCash').textContent = nf(d.cash);
    // the drawer's cash is net of what was handed back on returns
    $('#recCashLbl').innerHTML = Number(d.refunds) > 0
        ? 'پارەی لای کاشێر <span style="color:var(--bad)">(−<span class="n">' + nf(d.refunds) + '</span> گەڕاوە)</span>' : 'پارەی وەرگیراو';
    $('#recList').innerHTML = d.results.length ? d.results.map(r => {
        const cx = r.status !== 'confirmed', due = Number(r.grand_total) - Number(r.paid_amount);
        return '<div class="rc' + (cx ? ' cx' : '') + '">'
            + '<div class="tx"><b class="n">' + esc(r.invoice_number) + '</b> ' + (cx ? '<span class="tag">هەڵوەشاوە</span>' : '')
            + (Number(r.returned) > 0 ? ' <span class="tag">گەڕاوە <span class="n">' + nf(r.returned) + '</span></span>' : '')
            + '<small><span class="n">' + esc(r.time) + '</span> · ' + esc(r.customer_name) + ' · ' + r.line_count + ' کاڵا'
            + (due > 0.5 && !cx ? ' · <span style="color:var(--bad)">قەرز <span class="n">' + nf(due) + '</span></span>' : '') + '</small></div>'
            + '<span class="amt n">' + nf(r.grand_total) + '</span>'
            + '<button type="button" class="pb" data-print="80" data-id="' + r.id + '" title="پسوولەی 80mm"><i class="fas fa-receipt"></i></button>'
            + '<button type="button" class="pb" data-print="a4" data-id="' + r.id + '" title="A4"><i class="fas fa-file-lines"></i></button>'
            + (CFG.canReturn && !cx ? '<button type="button" class="pb rt-b" data-ret="' + r.id + '" title="گەڕاندنەوە"><i class="fas fa-rotate-left"></i></button>' : '')
            + '</div>';
    }).join('') : '<div class="empty-sm"><i class="fas fa-receipt" style="font-size:26px;display:block;margin-bottom:8px;color:#cbd5e1"></i>هێشتا هیچ فرۆشتنێکت نەکردووە ئەمڕۆ</div>';
}
$('#recList').addEventListener('click', e => {
    const r = e.target.closest('[data-ret]');
    if (r) { closeOv('#recOv'); openReturn(Number(r.dataset.ret)); return; }
    const b = e.target.closest('[data-print]'); if (!b) return;
    printInvoice(b.dataset.id, b.dataset.print);
    toast('چاپ دەکرێت...', 'ok');
});
$('#todayBtn').addEventListener('click', openReceipts);
$('#recBtn').addEventListener('click', openReceipts);

/* ── return (گەڕاندنەوە) ─────────────────────────────────
 * Goods come back over the counter: find the receipt, pick what comes back
 * (never more than is still open on each line), hand the cash back. Saved by
 * api/sales.php?action=return (Erp2Sales::createReturn), which credits each
 * line at what was actually paid for it and posts the cash handed back.
 * A walk-in customer always gets the money; a named one may keep it on
 * account instead. */
const R = { inv: null, rows: [], mode: 'cash', req: 0, timer: null, list: [], listQ: null, on: -1, busy: false };
function retStep(step) {
    ['#retFind', '#retForm', '#retDone'].forEach(s => { $(s).hidden = s !== step; });
    $('#retFoot').hidden = step !== '#retForm';
}
function openReturn(invoiceId) {
    if (!CFG.canReturn) return;
    if (S.edit) { toast(EDIT_BLOCK, 'err'); return; }
    openOv('#retOv');
    R.inv = null; R.rows = [];
    if (invoiceId) { retPick(invoiceId); return; }
    retStep('#retFind');
    $('#retQ').value = '';
    retSearch();
    setTimeout(() => $('#retQ').focus(), 0);
}
async function retSearch() {
    const my = ++R.req, q = latin($('#retQ').value).trim();
    $('#retList').innerHTML = '<div class="empty-sm">...</div>';
    const d = await getJson(API.pos + '?action=receipts&q=' + enc(q));
    if (my !== R.req) return;
    if (!d || !d.ok) { $('#retList').innerHTML = '<div class="empty-sm">' + esc((d && d.error) || 'کێشەی پەیوەندی') + '</div>'; return; }
    R.list = d.results; R.listQ = q; R.on = R.list.length ? 0 : -1;
    $('#retList').innerHTML = R.list.length ? R.list.map((r, i) => {
        const open = Number(r.open_qty) > 0.0005;
        return '<button type="button" class="li' + (i === 0 ? ' on' : '') + '" data-i="' + i + '"' + (open ? '' : ' style="opacity:.5"') + '>'
            + '<span class="av"><i class="fas fa-receipt"></i></span>'
            + '<span class="tx"><b class="n">' + esc(r.invoice_number) + '</b><small>' + esc(r.customer_name)
            + ' · <span class="n">' + esc(r.invoice_date) + ' ' + esc(r.time) + '</span>' + (open ? '' : ' · هەمووی گەڕاوە') + '</small></span>'
            + '<span class="end n">' + nf(r.grand_total) + '</span></button>';
    }).join('') : '<div class="empty-sm">هیچ پسوولەیەک نەدۆزرایەوە</div>';
}
async function retPick(id) {
    const d = await getJson(API.sales + '?action=returnable&id=' + Number(id));
    if (!d || !d.ok) { toast((d && d.error) || 'پسوولەکە نەکرایەوە', 'err'); retStep('#retFind'); return; }
    const inv = d.invoice;
    if (inv.status !== 'confirmed') { toast('تەنها پسوولەی پەسەندکراو دەگەڕێندرێتەوە', 'err'); retStep('#retFind'); return; }
    R.inv = inv;
    R.rows = inv.items.map(it => ({
        item_id: Number(it.item_id), product_id: Number(it.product_id), name: it.product_name, unit: it.unit_name || '',
        sold: Number(it.quantity), open: Math.max(0, Number(it.returnable)), price: Number(it.refund_price), qty: 0
    }));
    // A paid receipt hands the cash back; an unpaid one only lowers what is owed.
    R.mode = Number(inv.is_walk_in) || Number(inv.refundable) > 0 ? 'cash' : 'account';
    $('#retReason').value = '';
    retStep('#retForm');
    retRender();
    const first = document.querySelector('#retRows input');
    if (first) setTimeout(() => { first.focus(); first.select(); }, 0);
}
function retTotal() { return R.rows.reduce((s, r) => s + r.qty * r.price, 0); }
function retCash() {
    const total = retTotal();
    if (!R.inv || R.mode !== 'cash') return 0;
    return Math.min(total, Number(R.inv.refundable));
}
function retRender() {
    const inv = R.inv;
    $('#retInv').innerHTML = '<b class="n">' + esc(inv.invoice_number) + '</b>'
        + '<span>' + esc(inv.customer_name) + '</span><span class="n">' + esc(inv.invoice_date) + '</span>'
        + '<span>کۆ <b class="n">' + nf(inv.grand_total) + '</b></span>'
        + '<span class="sp"></span>'
        + '<button type="button" id="retAll"><i class="fas fa-check-double"></i> هەمووی</button>'
        + '<button type="button" id="retBack"><i class="fas fa-arrow-right"></i> پسوولەیەکی تر</button>';
    $('#retRows').innerHTML = R.rows.map((r, i) => {
        const done = r.open <= 0.0005;
        return '<tr class="' + (done ? 'done' : (r.qty > 0 ? 'on' : '')) + '">'
            + '<td><b>' + esc(r.name) + '</b>' + (r.unit ? ' <small>' + esc(r.unit) + '</small>' : '')
            + (r.sold - r.open > 0.0005 ? '<br><small>پێشتر گەڕاوە: <span class="n">' + qf(r.sold - r.open) + '</span></small>' : '') + '</td>'
            + '<td class="n">' + qf(r.sold) + '</td>'
            + '<td class="n">' + nf(r.price) + '</td>'
            + '<td>' + (done ? '<small>هەمووی گەڕاوە</small>'
                : '<span class="rq"><button type="button" data-rd="' + i + '" aria-label="کەمتر">−</button>'
                + '<input class="n" inputmode="decimal" data-ri="' + i + '" value="' + qf(r.qty) + '" aria-label="بڕی گەڕاوە">'
                + '<button type="button" data-ru="' + i + '" aria-label="زیاتر">+</button></span> <small>لە <span class="n">' + qf(r.open) + '</span></small>') + '</td>'
            + '<td class="n">' + (r.qty > 0 ? nf(r.qty * r.price) : '') + '</td></tr>';
    }).join('');
    retTotals();
}
function retTotals() {
    const inv = R.inv, total = retTotal(), walk = Number(inv.is_walk_in) > 0;
    $('#retOpts').innerHTML = walk
        ? '<label style="cursor:default"><i class="fas fa-money-bill-wave" style="color:var(--ok)"></i> کڕیاری نەقد — پارەکەی بە نەقد دەدرێتەوە</label>'
        : '<label><input type="radio" name="retMode" value="cash"' + (R.mode === 'cash' ? ' checked' : '') + '> <i class="fas fa-money-bill-wave" style="color:var(--ok)"></i> پارە بدرێتەوە (نەقد)</label>'
        + '<label><input type="radio" name="retMode" value="account"' + (R.mode === 'account' ? ' checked' : '') + '> <i class="fas fa-user-tag" style="color:var(--info)"></i> بخرێتە سەر حیسابی کڕیار</label>';
    const cash = retCash();
    const note = $('#retNote');
    if (R.mode === 'cash' && total > cash + 0.5) {
        note.hidden = false;
        note.textContent = 'لەم پسوولەیە تەنها ' + nf(inv.refundable) + ' پارە دراوە کە هێشتا نەگەڕێنراوەتەوە — '
            + nf(total - cash) + ' دەچێتە سەر حیسابی کڕیار.';
    } else {
        note.hidden = true;
    }
    $('#retCash').textContent = nf(cash);
    $('#retCash').previousElementSibling.textContent = R.mode === 'cash' ? 'پارەی دەدرێتەوە' : 'دەچێتە سەر حیساب';
    if (R.mode !== 'cash') $('#retCash').textContent = nf(total);
    $('#retGo').disabled = !(total > 0) || R.busy;
    $('#retGo').innerHTML = '<i class="fas fa-rotate-left"></i> ' + (R.mode === 'cash' ? 'گەڕاندنەوە و دانەوەی پارە' : 'گەڕاندنەوە');
}
function retSetQty(i, v) {
    const r = R.rows[i]; if (!r) return;
    r.qty = Math.max(0, Math.min(r.open, round3(v)));
    retRender();
}
async function retSave() {
    if (R.busy || !R.inv) return;
    const items = R.rows.filter(r => r.qty > 0).map(r => ({ item_id: r.item_id, product_id: r.product_id, quantity: r.qty }));
    if (!items.length) { toast('بڕی گەڕاوە دیاری بکە', 'err'); return; }
    R.busy = true; retTotals();
    const d = await postJson(API.sales + '?action=return', {
        customer_id: Number(R.inv.customer_id), warehouse_id: Number(R.inv.warehouse_id),
        original_invoice_id: Number(R.inv.id), return_date: CFG.today,
        // the server works out the exact cash ('cash' = all that may go back)
        reason: $('#retReason').value.trim(), refund_mode: R.mode, items: items
    });
    R.busy = false;
    if (!d || !d.ok) { retTotals(); toast((d && d.error) || 'گەڕاندنەوە تۆمار نەکرا', 'err'); beep('err'); return; }
    const refund = Number(d.refund_amount || 0), total = Number(d.grand_total || 0);
    $('#retDoneWhat').innerHTML = 'گەڕاندنەوەی <b class="n">' + esc(d.return_number) + '</b> تۆمارکرا — '
        + (refund > 0 ? 'ئەم پارەیە بدەرەوە بە کڕیار:' : 'پارە نادرێتەوە');
    $('#retDoneCash').textContent = nf(refund);
    $('#retDoneNote').textContent = total - refund > 0.5 ? nf(total - refund) + ' چووە سەر حیسابی ' + R.inv.customer_name : '';
    retStep('#retDone');
    beep('ok');
    refreshToday();
    loadCatalog();   // the goods are back on the shelf
    setTimeout(() => { const b = document.querySelector('#retDone [data-close]'); if (b) b.focus(); }, 0);
}
if (CFG.canReturn) {
    $('#retBtn').addEventListener('click', () => openReturn());
    $('#retQ').addEventListener('input', () => { clearTimeout(R.timer); R.timer = setTimeout(retSearch, 220); });
    $('#retQ').addEventListener('keydown', e => {
        const items = document.querySelectorAll('#retList .li');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!items.length) return;
            R.on = Math.max(0, Math.min(items.length - 1, R.on + (e.key === 'ArrowDown' ? 1 : -1)));
            items.forEach((b, i) => b.classList.toggle('on', i === R.on));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // a scanner types the number and Enter: search it now, then open the match
            clearTimeout(R.timer);
            const q = latin($('#retQ').value).trim();
            const exact = R.list.find(r => r.invoice_number === q);
            if (exact) { retPick(exact.id); return; }
            // the list on screen is for what is typed: open the highlighted one
            if (R.listQ === q && R.on >= 0 && R.list[R.on]) { retPick(R.list[R.on].id); return; }
            retSearch().then(() => { if (R.list.length === 1 || (R.list[0] && R.list[0].invoice_number === q)) retPick(R.list[0].id); });
        }
    });
    $('#retList').addEventListener('click', e => { const b = e.target.closest('.li'); if (b && R.list[b.dataset.i]) retPick(R.list[b.dataset.i].id); });
    $('#retRows').addEventListener('click', e => {
        const d = e.target.closest('[data-rd]'), u = e.target.closest('[data-ru]');
        if (d) retSetQty(Number(d.dataset.rd), R.rows[d.dataset.rd].qty - 1);
        if (u) retSetQty(Number(u.dataset.ru), R.rows[u.dataset.ru].qty + 1);
    });
    $('#retRows').addEventListener('change', e => { const el = e.target.closest('[data-ri]'); if (el) retSetQty(Number(el.dataset.ri), num(el.value)); });
    $('#retRows').addEventListener('keydown', e => {
        const el = e.target.closest('[data-ri]'); if (!el) return;
        if (e.key === 'Enter') { e.preventDefault(); retSetQty(Number(el.dataset.ri), num(el.value)); $('#retGo').focus(); }
    });
    $('#retInv').addEventListener('click', e => {
        if (e.target.closest('#retBack')) { retStep('#retFind'); $('#retQ').focus(); retSearch(); }
        if (e.target.closest('#retAll')) { R.rows.forEach(r => { r.qty = r.open; }); retRender(); }
    });
    $('#retOpts').addEventListener('change', e => { if (e.target.name === 'retMode') { R.mode = e.target.value; retTotals(); } });
    $('#retGo').addEventListener('click', retSave);
    $('#retAgain').addEventListener('click', () => openReturn());
}

/* ── prescriptions ───────────────────────────────────── */
let rxList = [];
async function refreshRx() {
    if (!CFG.canRx) return [];
    const d = await getJson(API.rx + '?action=list&status=pending');
    rxList = (d && d.ok && d.results) || [];
    const b = $('#rxCount');
    b.textContent = rxList.length; b.hidden = !rxList.length;
    return rxList;
}
async function openRx() {
    if (!CFG.canRx) return;
    if (S.edit) { toast(EDIT_BLOCK, 'err'); return; }
    openOv('#rxOv');
    $('#rxList').innerHTML = '<div class="empty-sm">...</div>';
    await refreshRx();
    $('#rxList').innerHTML = rxList.length ? rxList.map((r, i) =>
        '<button type="button" class="li" data-i="' + i + '">'
        + '<span class="av" style="background:#fce7f3;color:#be185d"><i class="fas fa-prescription"></i></span>'
        + '<span class="tx"><b>' + esc(r.patient_name) + '</b><small>' + esc(r.doctor_name || '') + ' · <span class="n">' + esc(String(r.created_at || '').slice(5, 16)) + '</span>'
        + (r.notes ? ' · ' + esc(r.notes) : '') + '</small></span>'
        + '<span class="end">' + r.item_count + ' دەرمان</span></button>'
    ).join('') : '<div class="empty-sm"><i class="fas fa-circle-check" style="font-size:26px;display:block;margin-bottom:8px;color:#a7f3d0"></i>هیچ داواکارییەکی چاوەڕوان نییە</div>';
}
/* i: a row of the F7 list; or rxId: a scanned prescription barcode (doScan) */
async function loadRx(i, rxId) {
    const id = rxId || (rxList[i] && rxList[i].id); if (!id) return;
    const d = await getJson(API.rx + '?action=detail&id=' + id);
    if (!d || !d.ok) { toast('نەتوانرا داواکارییەکە بکرێتەوە', 'err'); return; }
    const pr = d.prescription;
    if (S.rows.length) { held.push({ at: Date.now(), snap: snapshot() }); store(KEY_HOLD, held); toast('فرۆشتنی پێشوو ڕاگیرا', 'ok'); }
    resetSale();
    S.rx = { id: Number(pr.id), patient: pr.patient_name, doctor: pr.doctor_name || '' };
    S.cust = pr.customer_id ? { id: Number(pr.customer_id), name: pr.patient_name, phone: pr.patient_phone || '', walk: false, balance: 0 } : walkIn();
    const skipped = [], outside = [];
    (pr.items || []).forEach(it => {
        // دەرمانی دەرەوە: written by name, not sold here
        if (Number(it.is_outside)) { outside.push(it.product_name); return; }
        if (!it.product_id) { skipped.push(it.product_name); return; }
        const stock = Number(it.stock || 0), want = Number(it.quantity || 1);
        const ok = addProduct({ id: it.product_id, name: it.product_name, sale_price: it.sale_price, min_sale_price: it.min_sale_price,
            wholesale_price: 0, stock, unit_id: it.unit_id,
            // the form the doctor circled on the sheet, then the dose
            dosage: [it.notes, it.dosage].filter(Boolean).join(' — ') }, stock > 0 ? Math.min(want, stock) : want, { silent: true });
        if (!ok) skipped.push(it.product_name);
    });
    closeOv('#rxOv');
    renderAll();
    beep('ok');
    if (skipped.length) toast('لە کۆگا نییە: ' + skipped.join('، '), 'err');
    else toast('داواکارییەکە بارکرا — ' + S.rows.length + ' دەرمان'
        + (outside.length ? ' · لە دەرەوە دەکڕدرێت: ' + outside.join('، ') : ''), 'ok');
}
if (CFG.canRx) {
    $('#rxBtn').addEventListener('click', openRx);
    $('#rxList').addEventListener('click', e => { const b = e.target.closest('.li'); if (b) loadRx(Number(b.dataset.i)); });
    setInterval(refreshRx, 60000);
}
$('#rxBanX').addEventListener('click', () => { S.rx = null; renderRx(); persist(); });
$('#rxBanPrint').addEventListener('click', () => {
    if (S.rx && window.RxPrint) window.RxPrint.open(S.rx.id, { onClose: () => scanEl.focus() });
});

/* ── keyboard ────────────────────────────────────────── */
document.addEventListener('keydown', e => {
    const openOvEl = document.querySelector('.ov:not([hidden])');
    if (openOvEl) {
        if (e.key === 'Escape') { e.preventDefault(); closeOv('#' + openOvEl.id); }
        else if (/^F\d+$/.test(e.key)) e.preventDefault();
        return;
    }
    const k = e.key;
    if (/^F(2|3|4|6|7|8|9|10)$/.test(k)) {
        e.preventDefault();
        if (k === 'F2') scanEl.focus();
        else if (k === 'F3') { openCatalog(); catQ.focus(); catQ.select(); }
        else if (k === 'F4') openCustomer();
        else if (k === 'F6') hold();
        else if (k === 'F7') openRx();
        else if (k === 'F8') checkout('a4');
        else if (k === 'F9') checkout('');
        else if (k === 'F10') checkout('80');
        return;
    }
    if (k === 'Escape') {
        if (!$('#done').hidden) { hideDone(); scanEl.focus(); return; }
        if ($('#catp').classList.contains('open')) { closeCatalog(); scanEl.focus(); return; }
        scanEl.focus();
        return;
    }
    // Row shortcuts work from the empty scanner box or from no field at all.
    const a = document.activeElement;
    const free = a === scanEl ? scanEl.value === '' : !(a && /INPUT|TEXTAREA|SELECT/.test(a.tagName));
    if (!free || !S.rows.length) return;
    if (k === 'ArrowDown') { e.preventDefault(); selectRow(S.sel + 1); }
    else if (k === 'ArrowUp') { e.preventDefault(); selectRow(S.sel - 1); }
    else if (k === '+' || k === 'Add') { e.preventDefault(); changeQty(S.sel, 1); }
    else if (k === '-' || k === 'Subtract') { e.preventDefault(); changeQty(S.sel, -1); }
    else if (k === 'Delete') { e.preventDefault(); removeRow(S.sel); }
});

/* ── top bar ─────────────────────────────────────────── */
const KU_DAYS = ['یەکشەممە', 'دووشەممە', 'سێشەممە', 'چوارشەممە', 'پێنجشەممە', 'هەینی', 'شەممە'];
function tick() {
    const d = new Date(), p = v => String(v).padStart(2, '0');
    $('#clkT').textContent = p(d.getHours()) + ':' + p(d.getMinutes());
    $('#clkD').textContent = KU_DAYS[d.getDay()] + ' ' + d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate());
}
tick(); setInterval(tick, 15000);
$('#soundBtn').addEventListener('click', () => { sound = !sound; store(KEY_SOUND, sound); paintSound(); if (sound) beep('ok'); });
$('#fsBtn').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    else document.documentElement.requestFullscreen().catch(() => {});
});
document.addEventListener('fullscreenchange', () => { $('#fsBtn i').className = 'fas ' + (document.fullscreenElement ? 'fa-compress' : 'fa-expand'); });
const whSel = $('#whSel');
if (whSel) whSel.addEventListener('change', () => {
    if (S.rows.length) { whSel.value = S.wh; toast('سەرەتا سەبەتەکە بەتاڵ بکە، پاشان کۆگا بگۆڕە', 'err'); return; }
    S.wh = Number(whSel.value); loadCatalog();
});

/* ── boot ────────────────────────────────────────────── */
/* Edit mode: the cart opens on the invoice as it was saved. */
function loadEdit(e) {
    S.rows = e.rows.map(r => Object.assign({}, r));
    S.cust = e.cust; S.wh = e.wh; S.sel = S.rows.length - 1;
    discEl.value = e.disc > 0 ? nf(e.disc) : '';
    // Paid in full stays "full" (empty box), so a new discount moves the
    // amount with it; a part payment keeps what was paid.
    recvEl.value = e.paid >= e.grand - 0.5 ? '' : nf(e.paid);
    noteEl.value = e.notes || '';
    dateEl.value = e.date;
    if (whSel) { whSel.value = String(e.wh); whSel.disabled = true; }
    renderAll();
}

paintSound();
const draft = S.edit ? null : load(KEY_DRAFT);
if (S.edit) {
    loadEdit(S.edit);
} else if (draft && draft.snap && (draft.snap.rows || []).length && Date.now() - draft.at < 12 * 3600 * 1000) {
    restore(draft.snap);
    toast('فرۆشتنی تەواونەکراو گەڕێندرایەوە', 'ok');
} else {
    renderAll();
}
if (!CFG.walkIn) toast('کڕیاری نەقد دیاری نەکراوە — بۆ هەر فرۆشتنێک کڕیار هەڵبژێرە (F4)', 'err');
loadCatalog();
refreshToday();
scanEl.focus();
})();
</script>
</body>
</html>
