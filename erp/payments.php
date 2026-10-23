<?php
/**
 * ERP — customer receipts and supplier payments.
 *
 * One screen, two directions, chosen by a tab. They are the same form and the
 * same list with the arrow reversed, so splitting them into two pages would
 * duplicate everything for no gain.
 *
 * The open-invoice panel is the point of the screen: the cashier sees exactly
 * what the money will settle BEFORE saving, rather than typing a number and
 * hoping the allocation lands somewhere sensible.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpPayment.php';

$kind = $_GET['kind'] ?? 'customer';
if (!in_array($kind, ['customer', 'supplier'], true)) {
    $kind = 'customer';
}
$isCustomer = $kind === 'customer';

erp_require($isCustomer ? 'erp.payments.customer' : 'erp.payments.supplier');

$pageTitle  = $isCustomer ? 'وەرگرتنی پارە لە کڕیار' : 'دانی پارە بە دابینکەر';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'پارەدان'],
];

$canCustomer = erp_can('erp.payments.customer');
$canSupplier = erp_can('erp.payments.supplier');
$nextNumber  = ErpSequence::preview($isCustomer ? 'CUSTOMER_PAYMENT' : 'SUPPLIER_PAYMENT', 0);

$customCSS = <<<CSS
.pay-tabs{display:flex;gap:6px;margin-bottom:12px}
.pay-tabs a{padding:8px 18px;border:1px solid #d5d5d5;border-radius:8px;background:#fff;font-size:13px;font-weight:600;color:#444}
.pay-tabs a.on{background:#0f766e;color:#fff;border-color:#0f766e}
.pay-wrap{display:grid;grid-template-columns:360px 1fr;gap:12px}
.pay-form,.pay-side{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px}
.pay-form label{display:block;font-size:11.5px;color:#666;margin-bottom:3px;margin-top:8px}
.pay-form .form-control{height:34px;font-size:13px;padding:4px 9px}
.pay-form .amount{height:46px;font-size:22px;font-weight:700;text-align:left;direction:ltr}
.pay-bal{display:flex;justify-content:space-between;padding:8px 10px;background:#f5f7f9;border-radius:6px;margin-top:10px;font-size:13px}
.pay-bal .v{direction:ltr;font-weight:700;font-variant-numeric:tabular-nums}
.due{color:#c62828}.ok{color:#2e7d32}
table.pay-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.pay-t th{background:#eef2f5;padding:6px 8px;text-align:right;font-size:11.5px;border-bottom:2px solid #c8d3da}
table.pay-t td{border-bottom:1px solid #f0f0f0;padding:5px 8px}
.n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.will-pay{background:#e8f5e9}
.muted{color:#9e9e9e}
.ac-box{position:absolute;z-index:60;background:#fff;border:1px solid #ccc;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.16);max-height:260px;overflow:auto;min-width:280px}
.ac-item{padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f2f2f2}
.ac-item:hover,.ac-item.on{background:#e8f4fd}
.ac-item .meta{font-size:11px;color:#888}
@media(max-width:900px){.pay-wrap{grid-template-columns:1fr}}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="pay-tabs">
    <?php if ($canCustomer): ?>
    <a href="?kind=customer" class="<?= $isCustomer ? 'on' : '' ?>">
        <i class="fas fa-arrow-down"></i> وەرگرتن لە کڕیار</a>
    <?php endif; ?>
    <?php if ($canSupplier): ?>
    <a href="?kind=supplier" class="<?= $isCustomer ? '' : 'on' ?>">
        <i class="fas fa-arrow-up"></i> دان بە دابینکەر</a>
    <?php endif; ?>
</div>

<div class="pay-wrap">
    <div class="pay-form">
        <div style="position:relative">
            <label><?= $isCustomer ? 'کڕیار' : 'دابینکەر' ?> <span style="opacity:.6">(F4)</span></label>
            <input class="form-control" id="partyBox" autocomplete="off" placeholder="ناو یان مۆبایل...">
            <input type="hidden" id="partyId" value="0">
        </div>

        <label>بڕی پارە</label>
        <input class="form-control amount" id="amountBox" type="number" min="0" step="1" value="" placeholder="0">

        <label>شێواز</label>
        <select class="form-control" id="methodBox">
            <option value="cash">نەقد</option>
            <option value="bank">بانک</option>
            <option value="card">کارت</option>
            <option value="other">هیتر</option>
        </select>

        <label>بەروار</label>
        <input class="form-control" type="date" id="dateBox" value="<?= date('Y-m-d') ?>">

        <label>ژمارەی پسووڵە</label>
        <input class="form-control" id="numBox" value="<?= erp_esc($nextNumber) ?>" readonly dir="ltr">

        <label>سەرچاوە / تێبینی</label>
        <input class="form-control" id="refBox" placeholder="ئارەزوومەندانە">

        <div class="pay-bal">
            <span><?= $isCustomer ? 'ماوە لەسەر کڕیار' : 'ماوە لەسەر ئێمە' ?></span>
            <span class="v" id="balBox">—</span>
        </div>

        <button class="btn btn-primary" id="btnSave" onclick="paySave()" style="width:100%;margin-top:10px;justify-content:center">
            <i class="fas fa-check"></i> تۆمارکردن
        </button>
        <div id="payMsg" style="color:#c62828;font-weight:600;font-size:12.5px;margin-top:8px"></div>
    </div>

    <div class="pay-side">
        <h4 style="font-size:14px;margin:0 0 8px">
            وەسڵە کراوەکان
            <span class="muted" style="font-size:11.5px;font-weight:400">— پارەکە بەم ڕیزبەندییە دادەبەزرێت</span>
        </h4>
        <table class="pay-t">
            <thead><tr><th style="width:150px">ژمارە</th><th style="width:100px">بەروار</th><th class="n">کۆ</th><th class="n">ماوە</th><th class="n" style="width:100px">دەدرێت</th></tr></thead>
            <tbody id="openBody"><tr><td colspan="5" style="padding:16px;color:#999"><?= $isCustomer ? 'کڕیار' : 'دابینکەر' ?> هەڵبژێرە</td></tr></tbody>
        </table>

        <h4 style="font-size:14px;margin:16px 0 8px">پارەدانی دواییەکان</h4>
        <table class="pay-t">
            <thead><tr><th style="width:150px">ژمارە</th><th style="width:100px">بەروار</th><th>لایەن</th><th style="width:70px">شێواز</th><th class="n">بڕ</th></tr></thead>
            <tbody id="recentBody"><tr><td colspan="5" style="padding:16px;color:#999">بارکردن...</td></tr></tbody>
        </table>
    </div>
</div>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>

<?php
$apiC = APP_URL . '/erp/api/parties.php';
$apiPay = APP_URL . '/erp/api/payments.php';
$jsKind = $kind;

$customJS = <<<JS
const API_C = '{$apiC}', API_PAY = '{$apiPay}', KIND = '{$jsKind}';
const U = window.ErpUI;
let openInv = [], partyTimer = null;

const msg = t => { document.getElementById('payMsg').textContent = t || ''; };
const partyId = () => Number(document.getElementById('partyId').value);
const amount  = () => Number(document.getElementById('amountBox').value || 0);

async function openPartySearch() {
    const box = document.getElementById('partyBox');
    const d = await U.getJson(API_C + '?action=search&kind=' + KIND + '&q=' + encodeURIComponent(box.value.trim()));
    if (!d || !d.ok || !d.results.length) { msg('نەدۆزرایەوە'); return; }
    msg('');
    U.showAC(box, d.results.map(x => ({
        label: x.name, meta: (x.phone || '') + ' · ' + x.code, data: x
    })), x => {
        document.getElementById('partyId').value = x.id;
        document.getElementById('partyBox').value = x.name;
        loadOpen();
        document.getElementById('amountBox').focus();
    });
}

async function loadOpen() {
    if (!partyId()) return;
    const d = await U.getJson(API_PAY + '?action=open_invoices&kind=' + KIND + '&party_id=' + partyId());
    const b = document.getElementById('openBody');
    if (!d || !d.ok) { b.innerHTML = '<tr><td colspan="5" style="padding:16px;color:#c62828">هەڵە</td></tr>'; return; }

    openInv = d.results || [];
    const bal = Number(d.balance || 0);
    const balEl = document.getElementById('balBox');
    balEl.textContent = U.nf(bal);
    balEl.className = 'v ' + (bal > 0.009 ? 'due' : 'ok');

    if (!openInv.length) {
        b.innerHTML = '<tr><td colspan="5" style="padding:16px;color:#999">هیچ وەسڵێکی کراوە نییە</td></tr>';
        return;
    }
    paintAllocation();
}

/**
 * Show how the typed amount would land, oldest-first — the same rule the
 * server applies, so what the cashier sees is what gets recorded.
 */
function paintAllocation() {
    let left = amount();
    const b = document.getElementById('openBody');
    b.innerHTML = openInv.map(i => {
        const out = Number(i.outstanding);
        const take = Math.max(0, Math.min(left, out));
        left -= take;
        return '<tr class="' + (take > 0.009 ? 'will-pay' : '') + '">'
          + '<td dir="ltr">' + U.esc(i.invoice_number) + '</td>'
          + '<td dir="ltr">' + U.esc(i.invoice_date) + '</td>'
          + '<td class="n muted">' + U.nf(i.grand_total) + '</td>'
          + '<td class="n due">' + U.nf(out) + '</td>'
          + '<td class="n">' + (take > 0.009 ? U.nf(take) : '—') + '</td></tr>';
    }).join('');
    if (left > 0.009) {
        b.innerHTML += '<tr><td colspan="4" style="color:#8d6e00">زیادە — وەک قەرزی پێشەکی دەمێنێتەوە</td>'
                     + '<td class="n" style="color:#8d6e00">' + U.nf(left) + '</td></tr>';
    }
}

async function paySave() {
    if (!partyId()) { msg('سەرەتا لایەن هەڵبژێرە'); return; }
    if (amount() <= 0) { msg('بڕی پارە بنووسە'); return; }

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    msg('');
    const d = await U.postJson(API_PAY + '?action=save&kind=' + KIND, {
        party_id: partyId(),
        amount: amount(),
        payment_method: document.getElementById('methodBox').value,
        payment_date: document.getElementById('dateBox').value,
        reference: document.getElementById('refBox').value
    });
    btn.disabled = false;
    if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }

    let note = 'پسووڵە ' + d.payment_number + ' تۆمارکرا';
    if (Number(d.unallocated) > 0.009) {
        note += ' — ' + U.nf(d.unallocated) + ' وەک پێشەکی مایەوە';
    }
    showToast('success', note);

    document.getElementById('amountBox').value = '';
    document.getElementById('refBox').value = '';
    loadOpen();
    loadRecent();
}

async function loadRecent() {
    const d = await U.getJson(API_PAY + '?action=recent&kind=' + KIND
        + (partyId() ? '&party_id=' + partyId() : ''));
    const b = document.getElementById('recentBody');
    if (!d || !d.ok || !d.results.length) {
        b.innerHTML = '<tr><td colspan="5" style="padding:16px;color:#999">هیچ پارەدانێک نییە</td></tr>';
        return;
    }
    b.innerHTML = d.results.map(p =>
        '<tr' + (p.status === 'cancelled' ? ' style="opacity:.5;text-decoration:line-through"' : '') + '>'
        + '<td dir="ltr">' + U.esc(p.payment_number) + '</td>'
        + '<td dir="ltr">' + U.esc(p.payment_date) + '</td>'
        + '<td>' + U.esc(p.party_name) + '</td>'
        + '<td>' + U.esc(p.method_label) + '</td>'
        + '<td class="n">' + U.nf(p.amount) + '</td></tr>').join('');
}

document.getElementById('partyBox').addEventListener('keydown', e => {
    if (U.acHandleKey(e)) return;
    if (e.key === 'Enter') { e.preventDefault(); openPartySearch(); }
});
document.getElementById('partyBox').addEventListener('input', () => {
    clearTimeout(partyTimer);
    partyTimer = setTimeout(openPartySearch, 250);
});
document.getElementById('amountBox').addEventListener('input', () => { if (openInv.length) paintAllocation(); });
document.getElementById('amountBox').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); paySave(); }
});
document.addEventListener('keydown', e => {
    if (e.key === 'F4') { e.preventDefault(); document.getElementById('partyBox').focus(); openPartySearch(); }
    if (e.ctrlKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); paySave(); }
});

loadRecent();
document.getElementById('partyBox').focus();
JS;

require_once __DIR__ . '/../includes/footer.php';
