<?php
/**
 * دەرمانخانە — the prescription queue.
 *
 * One row per prescription the doctor sent from a case: patient, how many
 * medicines, and how much of each. Clicking a waiting row opens the till
 * (sales.php?prescription=<id>) already filled with those lines, so the
 * counter never retypes what the doctor chose.
 *
 * Not every patient buys here. A waiting request can be:
 *   «دەرەوەی کلینیک»  the patient buys outside — it moves to its own tab and
 *                     the printed prescription (prescription-print.php) opens
 *                     to hand over
 *   «ڕەتکردنەوە»      rejected, with an optional reason
 * Either can be put back in the queue. Any request can be printed.
 *
 * The queue is a view over erp2_prescriptions; nothing here touches stock.
 * Stock only moves when the till saves the sale.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.prescriptions.view');

$pageTitle  = 'داواکاری دەرمان';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'داواکاری دەرمان'],
];

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'sold', 'outside', 'cancelled', 'all'], true)) {
    $status = 'pending';
}
$canSell   = erp2_can('erp2.sales.create');
$canDelete = erp2_can('erp2.delete');

$customCSS = <<<CSS
.rxq-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
.rxq-tabs a{display:inline-flex;align-items:center;gap:7px;border:1px solid #dcdcdc;background:#fff;border-radius:8px;padding:7px 14px;font-size:12.5px;
            text-decoration:none;color:#444;font-weight:600}
.rxq-tabs a.on{background:#0f766e;border-color:#0f766e;color:#fff}
.rxq-tabs a .n{min-width:20px;height:19px;padding:0 6px;border-radius:10px;background:#eef2f5;color:#475569;font-size:11px;display:inline-grid;place-items:center;direction:ltr}
.rxq-tabs a.on .n{background:rgba(255,255,255,.25);color:#fff}
.rxq-card{background:#fff;border:1px solid #e3e3e3;border-radius:8px;overflow:hidden}
table.rxq{width:100%;border-collapse:collapse;font-size:13px}
table.rxq th{background:#eef2f5;border-bottom:2px solid #c8d3da;padding:9px 10px;text-align:right;font-size:12px;font-weight:700}
table.rxq td{border-bottom:1px solid #f0f0f0;padding:6px 8px;vertical-align:top}
table.rxq th{padding:7px 8px}
table.rxq tr.clickable{cursor:pointer}
table.rxq tr.clickable:hover td{background:#f2fbfa}
.rxq-name{font-weight:700;font-size:13.5px}
.rxq-sub{font-size:11px;color:#888;margin-top:2px}
.rxq-count{display:inline-flex;align-items:center;gap:5px;background:#eef7f6;color:#0f766e;
           border-radius:12px;padding:3px 10px;font-weight:700;font-size:12px;direction:ltr}
.rxq-items{font-size:11.5px;color:#666;min-width:260px;max-width:520px}
.rxq-line{padding:1px 0;line-height:1.4}
.rxq-line .ds{display:inline;margin-inline-start:6px}
.rxq-acts .btn-sm{padding:3px 8px;font-size:11.5px}
.rxq-line+.rxq-line{border-top:1px dashed #eef2f5}
.rxq-line .nm{font-weight:700;color:#1f2937;font-size:12.5px}
.rxq-line .fm{display:inline-block;margin:0 4px;padding:0 7px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:10.5px;font-weight:700}
.rxq-line .qt{color:#0f766e;font-weight:700;direction:ltr;display:inline-block}
.rxq-line .ds{color:#64748b;font-size:11.5px}
.rxq-badge{border-radius:10px;padding:3px 10px;font-size:11px;font-weight:700;white-space:nowrap}
.rxq-badge.pending{background:#fff4e5;color:#8a5a00}
.rxq-badge.sold{background:#e8f5e9;color:#2e7d32}
.rxq-badge.outside{background:#ede9fe;color:#5b21b6}
.rxq-badge.cancelled{background:#fdecea;color:#b71c1c}
.rxq-note{font-size:11px;color:#64748b;margin-top:4px;max-width:180px}
.rxq-empty{text-align:center;color:#999;padding:44px 16px}
.rxq-empty i{font-size:42px;color:#ddd;display:block;margin-bottom:12px}
.rxq-acts{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.rxq-acts .btn{white-space:nowrap}
.rxq-out{background:#7c3aed;border-color:#7c3aed;color:#fff}
.rxq-out:hover{background:#6d28d9;color:#fff}
.rxq-print{color:#0e74a4;border-color:#bcd6ea;background:#f0f7fc}
.rxq-print:hover{background:#e0f2fe;color:#0e74a4}
.rxq-rej{color:#b71c1c;border-color:#f3c5c5;background:#fff}
.rxq-rej:hover{background:#fdecea;color:#b71c1c}
CSS;

require_once __DIR__ . '/../includes/header.php';

$db = Database::getInstance();
$where  = $status === 'all' ? '1=1' : 'pr.status = :st';
$params = $status === 'all' ? [] : ['st' => $status];

$rows = $db->fetchAll(
    "SELECT pr.id, pr.status, pr.created_at, pr.notes, pr.invoice_id, pr.patient_id, pr.status_note,
            pa.full_name AS patient_name, pa.phone AS patient_phone,
            u.full_name AS doctor_name, c.case_number, sb.full_name AS status_by_name,
            COUNT(i.id) AS item_count,
            COALESCE(SUM(i.quantity), 0) AS total_qty,
            GROUP_CONCAT(CONCAT(i.product_name, ' × ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.quantity)),
                CASE WHEN i.dosage IS NULL OR i.dosage = '' THEN '' ELSE CONCAT(' (', i.dosage, ')') END)
                ORDER BY i.id SEPARATOR ' · ') AS items_summary
     FROM erp2_prescriptions pr
     JOIN patients pa ON pa.id = pr.patient_id
     LEFT JOIN users u ON u.id = pr.doctor_id
     LEFT JOIN users sb ON sb.id = pr.status_by
     LEFT JOIN cases c ON c.id = pr.case_id
     LEFT JOIN erp2_prescription_items i ON i.prescription_id = pr.id
     WHERE {$where}
     GROUP BY pr.id
     ORDER BY pr.created_at DESC
     LIMIT 200",
    $params
);

// Each request's lines, one per row of the list: a Latin drug name and a
// Kurdish dose in one run of text jumble each other.
$lines = [];
if ($rows) {
    $in = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach ($db->fetchAll(
        "SELECT i.prescription_id, i.product_name, i.quantity, i.dosage, COALESCE(NULLIF(i.notes, ''), t.name) AS form
         FROM erp2_prescription_items i
         LEFT JOIN erp2_products p ON p.id = i.product_id
         LEFT JOIN erp2_product_types t ON t.id = p.type_id
         WHERE i.prescription_id IN ({$in}) ORDER BY i.id"
    ) as $l) {
        $lines[(int)$l['prescription_id']][] = $l;
    }
}

$counts = ['all' => 0];
foreach ($db->fetchAll("SELECT status, COUNT(*) AS n FROM erp2_prescriptions GROUP BY status") as $c) {
    $counts[$c['status']] = (int)$c['n'];
    $counts['all'] += (int)$c['n'];
}

$tabs = [
    'pending'   => 'چاوەڕوان',
    'sold'      => 'فرۆشراو',
    'outside'   => 'دەرەوەی کلینیک',
    'cancelled' => 'ڕەتکراوە',
    'all'       => 'هەموو',
];
$statusLabels = ['pending' => 'چاوەڕوان', 'sold' => 'فرۆشراوە', 'outside' => 'دەرەوەی کلینیک', 'cancelled' => 'ڕەتکراوە'];
?>

<div class="rxq-tabs no-print">
    <?php foreach ($tabs as $key => $label): ?>
    <a href="?status=<?= erp2_esc($key) ?>" class="<?= $status === $key ? 'on' : '' ?>">
        <?= $label ?> <span class="n"><?= (int)($counts[$key] ?? 0) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="rxq-card">
    <?php if (!$rows): ?>
    <div class="rxq-empty">
        <i class="fas fa-prescription-bottle-medical"></i>
        هیچ داواکارییەکی دەرمان لەم دۆخەدا نییە.
    </div>
    <?php else: ?>
    <table class="rxq">
        <thead>
            <tr>
                <th style="width:60px">#</th>
                <th>نەخۆش</th>
                <th style="width:110px">کۆی دەرمان</th>
                <th>دەرمانەکان</th>
                <th style="width:130px">دکتۆر</th>
                <th style="width:120px">بەروار</th>
                <th style="width:130px">دۆخ</th>
                <th style="width:230px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $st        = $r['status'];
            $isPending = $st === 'pending';
            $tillUrl   = APP_URL . '/erp2/sales.php?prescription=' . (int)$r['id'];
            $rid       = (int)$r['id'];
        ?>
            <tr class="<?= ($isPending && $canSell) ? 'clickable' : '' ?>"
                <?= ($isPending && $canSell) ? 'onclick="location.href=\'' . $tillUrl . '\'"' : '' ?>>
                <td style="color:#999">#<?= $rid ?></td>
                <td>
                    <div class="rxq-name"><?= erp2_esc($r['patient_name']) ?></div>
                    <?php if (!empty($r['patient_phone'])): ?><div class="rxq-sub" dir="ltr" style="text-align:right"><?= erp2_esc($r['patient_phone']) ?></div><?php endif; ?>
                </td>
                <td>
                    <span class="rxq-count">
                        <i class="fas fa-pills"></i> <?= (int)$r['item_count'] ?>
                    </span>
                </td>
                <td class="rxq-items">
                    <?php foreach ($lines[$rid] ?? [] as $l): ?>
                    <div class="rxq-line">
                        <bdi class="nm"><?= erp2_esc($l['product_name']) ?></bdi>
                        <?php if (!empty($l['form'])): ?><span class="fm"><?= erp2_esc($l['form']) ?></span><?php endif; ?>
                        <span class="qt">× <?= erp2_qty($l['quantity']) ?></span>
                        <?php if (trim((string)$l['dosage']) !== ''): ?><div class="ds"><?= erp2_esc($l['dosage']) ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($lines[$rid])): ?>—<?php endif; ?>
                </td>
                <td style="font-size:12px;color:#666"><?= erp2_esc($r['doctor_name'] ?: '—') ?></td>
                <td style="font-size:12px;color:#666" dir="ltr"><?= erp2_esc(substr((string)$r['created_at'], 0, 16)) ?></td>
                <td>
                    <span class="rxq-badge <?= erp2_esc($st) ?>"><?= $statusLabels[$st] ?? erp2_esc($st) ?></span>
                    <?php if (in_array($st, ['outside', 'cancelled'], true) && ($r['status_note'] || $r['status_by_name'])): ?>
                    <div class="rxq-note"><?= erp2_esc($r['status_note'] ?: '') ?><?= $r['status_by_name'] ? ($r['status_note'] ? ' · ' : '') . erp2_esc($r['status_by_name']) : '' ?></div>
                    <?php endif; ?>
                </td>
                <td onclick="event.stopPropagation()">
                    <div class="rxq-acts">
                    <?php if ($isPending && $canSell): ?>
                    <a class="btn btn-primary btn-sm" href="<?= $tillUrl ?>"><i class="fas fa-cash-register"></i> فرۆشتن</a>
                    <?php endif; ?>
                    <?php if ($isPending): ?>
                    <button type="button" class="btn btn-sm rxq-out" onclick="rxOutside(<?= $rid ?>)" title="نەخۆش لە دەرەوەی کلینیک دەیکڕێت — ڕەچەتەکە چاپ دەکرێت">
                        <i class="fas fa-person-walking-arrow-right"></i> دەرەوەی کلینیک</button>
                    <button type="button" class="btn btn-sm rxq-rej" onclick="rxReject(<?= $rid ?>)"><i class="fas fa-ban"></i> ڕەتکردنەوە</button>
                    <?php endif; ?>
                    <?php if ($st === 'sold' && $r['invoice_id']): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= APP_URL ?>/erp2/print-invoice.php?id=<?= (int)$r['invoice_id'] ?>&autoprint=0" target="_blank">
                        <i class="fas fa-file-invoice"></i> وەسڵ</a>
                    <?php endif; ?>
                    <?php if (in_array($st, ['outside', 'cancelled'], true)): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="rxReopen(<?= $rid ?>)" title="گەڕاندنەوە بۆ لیستی چاوەڕوان"><i class="fas fa-rotate-left"></i> گەڕاندنەوە</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm rxq-print" onclick="RxPrint.open(<?= $rid ?>, { sold: <?= $st === 'sold' ? 'true' : 'false' ?> })"
                            title="<?= $st === 'sold' ? 'ڕەچەتەکە بە نرخ و مۆری «فرۆشرا»وە' : 'ڕەچەتەی A5 ـی دکتۆر — کاغەزی سپی یان چاپکراو' ?>">
                        <i class="fas fa-print"></i> چاپی ڕەچەتە</button>
                    <?php if ($canDelete): ?>
                    <button type="button" class="btn btn-icon btn-sm" title="سڕینەوە" style="color:#c62828" onclick="rxDelete(<?= $rid ?>, this)"><i class="fas fa-trash-can"></i></button>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script src="<?= asset_url('assets/js/rx-print.js') ?>" data-app="<?= APP_URL ?>"></script>
<script>
const RX_API = '<?= APP_URL ?>/erp2/api/prescriptions.php';

async function rxPost(data) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    try {
        const res = await fetch(RX_API, { method: 'POST', body: fd });
        return await res.json();
    } catch (e) { return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' }; }
}

/**
 * The patient buys outside: one click. The request moves to «دەرەوەی کلینیک»
 * and the doctor's sheet opens in the print dialog (assets/js/rx-print.js);
 * the list refreshes once the dialog is closed.
 */
async function rxOutside(id) {
    const d = await rxPost({ action: 'outside', id: id });
    if (!d || !d.ok) {
        showToast('error', (d && (d.error || d.message)) || 'هەڵە');
        return;
    }
    showToast('success', 'داواکاری #' + id + ' نێردرا بۆ دەرەوەی کلینیک');
    RxPrint.open(id, { onClose: () => location.reload() });
}

async function rxReject(id) {
    const note = prompt('هۆکاری ڕەتکردنەوەی داواکاری #' + id + ' (ئیختیاری):', '');
    if (note === null) return;
    const d = await rxPost({ action: 'cancel', id: id, note: note });
    if (!d || !d.ok) { showToast('error', (d && (d.error || d.message)) || 'هەڵە'); return; }
    showToast('success', 'داواکاری #' + id + ' ڕەتکرایەوە');
    setTimeout(() => location.reload(), 500);
}

async function rxReopen(id) {
    const d = await rxPost({ action: 'reopen', id: id });
    if (!d || !d.ok) { showToast('error', (d && (d.error || d.message)) || 'هەڵە'); return; }
    showToast('success', 'داواکاری #' + id + ' گەڕایەوە بۆ چاوەڕوان');
    setTimeout(() => location.reload(), 500);
}

<?php if ($canDelete): ?>
/** Delete a request for good. A sale made from it stays (it is its own document). */
async function rxDelete(id, btn) {
    if (!confirm('داواکاری دەرمانی #' + id + ' بە یەکجاری بسڕدرێتەوە؟')) return;
    const fd = new FormData();
    fd.set('action', 'prescription');
    fd.set('id', id);
    try {
        const res = await fetch('<?= APP_URL ?>/erp2/api/delete.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (!d.ok) { showToast('error', d.error || d.message || 'هەڵە'); return; }
        btn.closest('tr').remove();
        showToast('success', 'داواکاری #' + id + ' سڕایەوە');
    } catch (e) { showToast('error', 'کێشەی پەیوەندی ڕوویدا'); }
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
