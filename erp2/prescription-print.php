<?php
/**
 * دەرمانخانە — a doctor's prescription, printed.
 *
 * Handed to a patient who buys outside the clinic (the «دەرەوەی کلینیک» button
 * on erp2/prescriptions.php opens it ready to print), and printable for any
 * request: patient, doctor, and every medicine with its form, quantity and
 * dose. A5 like a prescription pad; ?size=a4 for a full page.
 *
 *   ?id=      the prescription
 *   ?autoprint=1  print as soon as it opens
 */

require_once __DIR__ . '/_bootstrap.php';
if (!erp2_can('erp2.prescriptions.view') && !erp2_can('erp2.prescriptions.create')) {
    erp2_require('erp2.prescriptions.view');
}

$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
$pr = $db->fetch(
    "SELECT pr.*, pa.full_name AS patient_name, pa.gender, pa.date_of_birth, pa.phone AS patient_phone,
            u.full_name AS doctor_name
     FROM erp2_prescriptions pr
     JOIN patients pa ON pa.id = pr.patient_id
     LEFT JOIN users u ON u.id = pr.doctor_id
     WHERE pr.id = :id",
    ['id' => $id]
);
if (!$pr) {
    http_response_code(404);
    exit('ڕەچەتە نەدۆزرایەوە');
}
$items = $db->fetchAll(
    "SELECT i.product_name, i.quantity, i.dosage, i.notes,
            t.name AS type_name, un.name AS unit_name
     FROM erp2_prescription_items i
     LEFT JOIN erp2_products p ON p.id = i.product_id
     LEFT JOIN erp2_product_types t ON t.id = p.type_id
     LEFT JOIN erp2_units un ON un.id = p.unit_id
     WHERE i.prescription_id = :id
     ORDER BY i.id",
    ['id' => $id]
);

require __DIR__ . '/../includes/clinic_branding.php';

$isA4      = ($_GET['size'] ?? 'a5') === 'a4';
$autoprint = !empty($_GET['autoprint']);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');

// «د.» is already part of some names; print it once.
$doctor = trim(preg_replace('/^\s*د\s*\.\s*/u', '', (string)($pr['doctor_name'] ?? '')));
$age = '';
if (!empty($pr['date_of_birth']) && $pr['date_of_birth'] !== '0000-00-00') {
    try { $age = (string)(new DateTime($pr['date_of_birth']))->diff(new DateTime())->y; } catch (Throwable $ex) { $age = ''; }
}
$gender = ['male' => 'نێر', 'female' => 'مێ', 'm' => 'نێر', 'f' => 'مێ'][strtolower((string)$pr['gender'])] ?? (string)$pr['gender'];
$date = substr((string)$pr['created_at'], 0, 10);
$selfUrl = fn(array $q) => '?' . http_build_query(array_merge(['id' => $id, 'size' => $isA4 ? 'a4' : 'a5'], $q));
?><!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ڕەچەتە #<?= $id ?> — <?= $e($pr['patient_name']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
    *{box-sizing:border-box}
    body{margin:0;background:#e9eef2;color:#0f172a;font-family:'NRT',Tahoma,Arial,sans-serif}
    .bar{position:sticky;top:0;z-index:2;display:flex;gap:8px;justify-content:center;align-items:center;padding:10px;background:#0f2a3a}
    .bar a,.bar button{font:13px inherit;font-weight:700;padding:7px 16px;border-radius:8px;border:0;cursor:pointer;text-decoration:none;color:#0f2a3a;background:#fff}
    .bar .pr{background:#14b8a6;color:#fff}
    .bar .on{outline:2px solid #5eead4}
    /* compact content at the top; the signature and the clinic stamp always
       sit at the BOTTOM of the page (the sheet is one page tall, .foot is
       pushed down by margin-top:auto) */
    .sheet{background:#fff;margin:18px auto;padding:7mm 8mm 6mm;width:<?= $isA4 ? '210mm' : '148mm' ?>;
           min-height:<?= $isA4 ? '297mm' : '210mm' ?>;display:flex;flex-direction:column;
           box-shadow:0 10px 30px -12px rgba(15,23,42,.35)}
    .lh{display:flex;align-items:center;gap:3mm;padding-bottom:2mm;border-bottom:2px solid #0f766e}
    .lh img{width:<?= $isA4 ? '14mm' : '11mm' ?>;height:auto}
    .lh .ku{flex:1;min-width:0}
    .lh .ku b{font-size:<?= $isA4 ? '15px' : '13px' ?>;color:#0f766e}
    .lh .ku small{display:block;font-size:9.5px;color:#64748b;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .lh .ph{font-size:10px;color:#475569;direction:ltr;text-align:left;line-height:1.5;white-space:nowrap}
    .ttl{display:flex;align-items:baseline;gap:2mm;margin:2.5mm 0 2mm}
    .rx{font-family:Georgia,'Times New Roman',serif;font-size:<?= $isA4 ? '24px' : '20px' ?>;font-weight:700;color:#0f766e;line-height:1;direction:ltr}
    .ttl h1{margin:0;font-size:<?= $isA4 ? '15px' : '13px' ?>}
    .ttl .no{margin-inline-start:auto;font-size:10.5px;color:#64748b}
    .ttl .no b{color:#0f172a;direction:ltr;display:inline-block}
    .pt{display:flex;flex-wrap:wrap;gap:1mm 5mm;align-items:baseline;background:#f1f5f9;border-radius:5px;padding:1.6mm 3mm;margin-bottom:2.5mm;
        font-size:<?= $isA4 ? '11.5px' : '10.5px' ?>;color:#475569}
    .pt b{color:#0f172a}
    .pt .nm b{font-size:<?= $isA4 ? '13px' : '12px' ?>}
    table{width:100%;border-collapse:collapse;font-size:<?= $isA4 ? '12px' : '11px' ?>}
    th{background:#0f766e;color:#fff;font-weight:700;font-size:10px;padding:1.4mm 1.6mm;text-align:right}
    td{padding:1.6mm;border-bottom:1px solid #e2e8f0;vertical-align:top}
    tbody tr:nth-child(even) td{background:#f8fafc}
    td.i{width:6mm;text-align:center;color:#64748b}
    td.nm{direction:ltr;text-align:left;font-weight:700}
    td.nm small{font-weight:600;color:#0369a1;font-size:9.5px;margin-left:1.5mm}
    td.q{width:13mm;text-align:center;white-space:nowrap}
    td.q small{color:#64748b;font-size:9px;margin-right:.8mm}
    td.ds{width:48%;color:#0f172a}
    td.ds .none{color:#94a3b8}
    .note{margin-top:2mm;font-size:10.5px;background:#fffbeb;border-radius:5px;padding:1.4mm 2.5mm;color:#713f12}
    /* the patient notice: allergies and how to take the course */
    .warn{margin-top:2.5mm;border:1px solid #fecaca;border-inline-start:3px solid #dc2626;border-radius:5px;background:#fef2f2;
          padding:1.5mm 2.5mm;font-size:<?= $isA4 ? '11px' : '10px' ?>;color:#450a0a;line-height:1.7}
    .warn b.h{display:block;color:#b91c1c}
    .warn p{margin:0}
    .warn .tel{direction:ltr;display:inline-block;font-weight:700;color:#0f172a}
    .foot{margin-top:auto;padding-top:6mm;display:flex;gap:8mm;align-items:flex-end}
    .sig{flex:1;text-align:center;font-size:10px;color:#475569}
    .sig b{display:block;font-size:11.5px;color:#0f172a;margin-bottom:6mm}
    .sig span{display:block;border-top:1px solid #94a3b8;padding-top:1mm}
    .out{margin-top:2.5mm;text-align:center;font-size:9.5px;color:#64748b;border-top:1px dashed #cbd5e1;padding-top:1.5mm}
    @media print{
        body{background:#fff}
        .bar{display:none}
        /* one printed page: its height less the 6mm margins (and a hair, so
           rounding never spills an empty second page) */
        .sheet{margin:0;box-shadow:none;width:auto;padding:0;min-height:calc(<?= $isA4 ? '297mm' : '210mm' ?> - 13mm)}
        @page{size:<?= $isA4 ? 'A4' : 'A5' ?>;margin:6mm}
        th,tbody tr:nth-child(even) td,.pt,.note,.warn{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
</style>
</head>
<body>
<div class="bar">
    <button class="pr" onclick="window.print()">چاپکردن</button>
    <a href="<?= $e($selfUrl(['size' => 'a5'])) ?>" class="<?= $isA4 ? '' : 'on' ?>">A5</a>
    <a href="<?= $e($selfUrl(['size' => 'a4'])) ?>" class="<?= $isA4 ? 'on' : '' ?>">A4</a>
    <button onclick="window.close()">داخستن</button>
</div>

<div class="sheet">
    <div class="lh">
        <img src="<?= $e($logoUrl) ?>" alt="" onerror="this.remove()">
        <div class="ku"><b><?= $e($clinicName) ?></b><small><?= $e($clinicAddress) ?></small></div>
        <div class="ph"><?= implode('<br>', array_map($e, $clinicPhones)) ?></div>
    </div>

    <div class="ttl">
        <span class="rx">&#8478;</span>
        <h1>ڕەچەتەی دەرمان</h1>
        <div class="no"><b dir="ltr">#<?= $id ?></b> · <b><?= $e($date) ?></b></div>
    </div>

    <div class="pt">
        <span class="nm">نەخۆش: <b><?= $e($pr['patient_name']) ?></b></span>
        <?php if ($age !== ''): ?><span>تەمەن: <b><?= $e($age) ?></b></span><?php endif; ?>
        <?php if ($gender !== ''): ?><span><b><?= $e($gender) ?></b></span><?php endif; ?>
        <?php if (!empty($pr['patient_phone'])): ?><span><b dir="ltr"><?= $e($pr['patient_phone']) ?></b></span><?php endif; ?>
        <?php if ($doctor !== ''): ?><span>دکتۆر: <b>د. <?= $e($doctor) ?></b></span><?php endif; ?>
    </div>

    <table>
        <thead><tr><th style="text-align:center">#</th><th>دەرمان</th><th style="text-align:center">بڕ</th><th>دۆز و ڕێنمایی</th></tr></thead>
        <tbody>
            <?php foreach ($items as $n => $it):
                $form = trim((string)($it['notes'] ?: $it['type_name']));   // the circled form, else the item's own
            ?>
            <tr>
                <td class="i"><?= $n + 1 ?></td>
                <td class="nm"><?= $e($it['product_name']) ?><?php if ($form !== ''): ?><small><?= $e($form) ?></small><?php endif; ?></td>
                <td class="q"><b><?= $e($qty($it['quantity'])) ?></b><?php if (!empty($it['unit_name'])): ?> <small><?= $e($it['unit_name']) ?></small><?php endif; ?></td>
                <td class="ds"><?= trim((string)$it['dosage']) !== '' ? $e($it['dosage']) : '<span class="none">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="4" style="text-align:center;color:#94a3b8">هیچ دەرمانێک نییە</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($pr['notes'])): ?>
    <div class="note"><b>تێبینی دکتۆر:</b> <?= nl2br($e($pr['notes'])) ?></div>
    <?php endif; ?>

    <div class="warn">
        <b class="h">&#9888; ئاگاداری بۆ چارەخوازی بەڕێز:</b>
        <p>دڵنیابە کە هیچ هەستیارییەکت بەرامبەر ئەم دەرمانانە نییە.</p>
        <p>ئەگەر هەستیاریت هەبوو، دەستبەجێ دەرمانەکە ڕابگرە و پەلە بکە لە پەیوەندیکردن پێمانەوە: <span class="tel"><?= $e($clinicPhone1) ?></span></p>
        <p>تکایە دەرمانەکان تا کۆتایی و بەپێی کاتی خۆیان بخۆ، تەنانەت ئەگەر زووتر هەستت بە باشبوونیش کرد.</p>
    </div>

    <div class="foot">
        <div class="sig"><b><?= $doctor !== '' ? 'د. ' . $e($doctor) : '&nbsp;' ?></b><span>واژووی دکتۆر</span></div>
        <div class="sig"><b>&nbsp;</b><span>مۆری کلینیک</span></div>
    </div>
    <div class="out">
        <?= $pr['status'] === 'outside' ? 'ئەم ڕەچەتەیە بۆ کڕینی دەرمانەکانە لە دەرەوەی کلینیک.' : 'ئومێدی چاکبوونەوەتان بۆ دەخوازین.' ?>
    </div>
</div>

<?php if ($autoprint): ?>
<script>
    window.addEventListener('load', function () {
        var go = function () { window.print(); };
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(function () { setTimeout(go, 150); }); }
        else { setTimeout(go, 350); }
    });
</script>
<?php endif; ?>
</body>
</html>
