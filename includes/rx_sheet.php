<?php
/**
 * ڕەچەتە — renders the A5 landscape prescription sheet as a whole HTML page.
 *
 * Shared by erp2/prescription-print.php (a real prescription) and
 * admin/doctor-rx.php (the live preview while a doctor's panel is edited), so
 * the two can never look different. The page always carries everything; the
 * paper (blank / pre-printed pad), the pad's printer offset and the sold copy
 * are classes switched by assets/js/rx-sheet.js — from the bar on the page,
 * or from the print dialog around it (assets/js/rx-print.js).
 *
 * $ctx:
 *   doctor    RxProfile::resolve() result
 *   template  RxTemplate::get() result (optional: the saved one)
 *   patient   ['name' => , 'age' => ]
 *   date      Y-m-d
 *   items     [['name','form','qty','unit','dosage','price','total'], …]
 *   notes     the doctor's note, or ''
 *   rx_id     int|null           — the number under the barcode
 *   barcode   string|null        — RxProfile::barcode()
 *   sold      null | ['invoice' => , 'date' => , 'total' => ]   (prices + stamp offered)
 *   title     the browser tab
 *   embed     bool  — inside the print dialog: no bar, scaled to the frame
 *   bar       bool  — the bar with print / paper / sold buttons
 *   autoprint bool
 */

require_once __DIR__ . '/../classes/RxTemplate.php';
require_once __DIR__ . '/../classes/Code128.php';

function rx_sheet_page(array $ctx): void
{
    $e   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $d   = $ctx['doctor'];
    $sold = $ctx['sold'] ?? null;

    // The clinic's part of the sheet: the template (admin/rx-templates.php),
    // or the posted, unsaved one while it is being edited.
    $tpl  = $ctx['template'] ?? RxTemplate::get();
    $vars = '--rx-frame:' . $tpl['rx_color_frame'] . ';--rx-blue:' . $tpl['rx_color_text'];

    $ts = strtotime((string)($ctx['date'] ?? '')) ?: time();
    $year = date('Y', $ts);
    $fs  = APP_URL . '/assets/css/fonts.css';
    $css = APP_URL . '/assets/css/rx-sheet.css?v=' . @filemtime(APP_ROOT . '/assets/css/rx-sheet.css');
    $js  = APP_URL . '/assets/js/rx-sheet.js?v=' . @filemtime(APP_ROOT . '/assets/js/rx-sheet.js');
    $qrJs = APP_URL . '/assets/vendor/qrcode-generator/qrcode.min.js';

    $pin = '<svg viewBox="0 0 24 24" fill="none" stroke="#333" stroke-width="1.5" aria-hidden="true"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.6"/></svg>';
    $tel = '<svg viewBox="0 0 24 24" fill="none" stroke="#333" stroke-width="1.5" aria-hidden="true"><rect x="6.5" y="2.5" width="11" height="19" rx="2"/><path d="M10.5 18.6h3"/></svg>';
    ?><!DOCTYPE html>
<html lang="ku" dir="rtl" class="paper-blank" data-can-sold="<?= $sold ? '1' : '0' ?>" style="<?= $e($vars) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($ctx['title'] ?? 'ڕەچەتە') ?></title>
<link rel="stylesheet" href="<?= $e($fs) ?>">
<link rel="stylesheet" href="<?= $e($css) ?>">
</head>
<body class="<?= !empty($ctx['embed']) ? 'embed' : '' ?>">
<?php if (!empty($ctx['bar'])): ?>
<div class="rx-bar">
    <button type="button" class="pr" onclick="rxSheet.print()">چاپکردن</button>
    <button type="button" data-paper="blank" onclick="rxSheet.setPaper('blank')">کاغەزی سپی</button>
    <button type="button" data-paper="pad" onclick="rxSheet.setPaper('pad')">کاغەزی چاپکراو</button>
    <?php if ($sold): ?><label><input type="checkbox" id="rxSoldBox" onchange="rxSheet.setSold(this.checked)"> نرخ و مۆری «فرۆشرا»</label><?php endif; ?>
    <button type="button" onclick="window.close()">داخستن</button>
</div>
<?php endif; ?>
<div class="rx-desk">
<div class="rx-sheet">

    <?php if ($d['mode'] === 'image'): ?>
    <div class="rx-panel is-image"><img src="<?= $e($d['image_url']) ?>" alt=""></div>
    <?php else: ?>
    <div class="rx-panel">
        <?php if ($tpl['rx_image_left'] !== '' || $tpl['rx_logo'] !== '' || $tpl['rx_image_right'] !== ''): ?>
        <div class="rx-top">
            <?php if ($tpl['rx_image_left'] !== ''): ?><img class="deco l" src="<?= $e($tpl['rx_image_left']) ?>" alt=""><?php endif; ?>
            <?php if ($tpl['rx_logo'] !== ''): ?><img class="logo" src="<?= $e($tpl['rx_logo']) ?>" alt="" onerror="this.remove()"><?php endif; ?>
            <?php if ($tpl['rx_image_right'] !== ''): ?><img class="deco r" src="<?= $e($tpl['rx_image_right']) ?>" alt=""><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($tpl['rx_line1'] !== ''): ?><div class="rx-clinic"><?= $e($tpl['rx_line1']) ?></div><?php endif; ?>
        <?php if ($tpl['rx_line2'] !== ''): ?><div class="rx-sub"><?= $e($tpl['rx_line2']) ?></div><?php endif; ?>
        <div class="rx-rule"></div>
        <div class="rx-title"><?= $e($d['title']) ?></div>
        <div class="rx-name"><?= $e($d['display_name']) ?></div>
        <?php if ($d['credentials']): ?>
        <div class="rx-creds"><?php foreach ($d['credentials'] as $line): ?><div><?= $e($line) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($d['degrees'] !== ''): ?><div class="rx-deg"><?= $e($d['degrees']) ?></div><?php endif; ?>
        <div class="rx-rule"></div>
        <div class="rx-contact">
            <?php if ($d['address']): ?>
            <div class="ln rx-addr"><?= $pin ?><span><?= implode('<br>', array_map($e, $d['address'])) ?></span></div>
            <?php endif; ?>
            <?php if ($d['phones'] !== ''): ?><div class="ln rx-phones"><?= $tel ?><span><?= $e($d['phones']) ?></span></div><?php endif; ?>
        </div>
        <div class="rx-qrs">
            <div class="rx-qr" data-qr="<?= $e($d['qr1_url']) ?>"></div>
            <div class="rx-cap"><?= implode('<br>', array_map($e, $d['qr_caption'])) ?></div>
            <div class="rx-qr" data-qr="<?= $e($d['qr2_url']) ?>"></div>
        </div>
    </div>
    <?php endif; ?>
    <div class="rx-strip"></div>

    <!-- the pad's own printing: labels and the line under the date -->
    <div class="rx-lbl" data-w="5" style="right:2.6mm">ناو:</div>
    <div class="rx-lbl" data-w="9.1" style="right:51.7mm">تەمەن:</div>
    <div class="rx-lbl" data-w="11.8" style="right:75.4mm">ڕێکەوت:</div>
    <div class="rx-lbl l" style="left:116.3mm">/</div>
    <div class="rx-lbl l" style="left:110.4mm">/</div>
    <div class="rx-lbl l" data-w="6" style="left:100.9mm;direction:ltr"><?= $e(substr($year, 0, 3)) ?></div>
    <div class="rx-uline"></div>

    <div class="rx-fill">
        <div class="rx-val name"><?= $e($ctx['patient']['name'] ?? '') ?></div>
        <div class="rx-val age"><?= $e($ctx['patient']['age'] ?? '') ?></div>
        <div class="rx-val d"><?= date('j', $ts) ?></div>
        <div class="rx-val m"><?= date('n', $ts) ?></div>
        <div class="rx-val y"><?= $e(substr($year, 3)) ?></div>

        <div class="rx-body">
            <div class="rx-mark">&#8478;</div>
            <ol class="rx-items">
                <?php foreach ($ctx['items'] as $n => $it): ?>
                <li class="rx-it">
                    <div class="nm"><bdi><?= $e($it['name']) ?></bdi><?php if (($it['form'] ?? '') !== ''): ?><i><?= $e($it['form']) ?></i><?php endif; ?><span class="no"><?= $n + 1 ?>.</span></div>
                    <div class="qt"><?= $e($it['qty']) ?> <small><?= $e($it['unit'] ?? '') ?></small></div>
                    <?php if ($sold): ?><div class="pr"><?= $e($it['total'] ?? '') ?></div><?php endif; ?>
                    <?php if (trim((string)($it['dosage'] ?? '')) !== ''): ?><div class="ds"><?= $e($it['dosage']) ?></div><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php if (trim((string)($ctx['notes'] ?? '')) !== ''): ?>
            <div class="rx-note"><b>تێبینی:</b> <?= nl2br($e($ctx['notes'])) ?></div>
            <?php endif; ?>
            <?php if ($sold): ?>
            <div class="rx-total"><span>کۆی گشتی</span><span><?= $e($sold['total']) ?></span></div>
            <?php endif; ?>
        </div>

        <?php if (trim($tpl['rx_warning']) !== ''): ?><div class="rx-warn">&#9888; <bdi><?= $e($tpl['rx_warning']) ?></bdi></div><?php endif; ?>
        <div class="rx-foot">
            <div class="rx-sign"><b><?= $e(trim($d['title'] . ' ' . $d['display_name'])) ?></b><span><?= $e($tpl['rx_sign']) ?></span></div>
            <?php if (!empty($ctx['barcode'])): ?>
            <div class="rx-code"><?= Code128::svg($ctx['barcode'], 0.3, 8) ?><b>Rx #<?= (int)$ctx['rx_id'] ?></b> · <?= $e($ctx['barcode']) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($sold): ?>
        <div class="rx-stamp"><b>فرۆشرا</b><small><?= $e($sold['invoice']) ?> · <?= $e($sold['date']) ?></small></div>
        <?php endif; ?>
    </div>
</div>
</div>
<script src="<?= $e($qrJs) ?>"></script>
<script src="<?= $e($js) ?>"<?= !empty($ctx['autoprint']) ? ' data-autoprint="1"' : '' ?>></script>
</body>
</html>
<?php
}

/** A sample patient and three medicines, for the previews on the admin pages. */
function rx_sample_ctx(): array
{
    return [
        'title'   => 'ڕەچەتە — پێشبینین',
        'patient' => ['name' => 'نموونەی ناوی نەخۆش', 'age' => '32'],
        'date'    => date('Y-m-d'),
        'items'   => [
            ['name' => 'AMOXICILLIN 500MG', 'form' => 'Capsule', 'qty' => '2', 'unit' => 'پاکەت', 'dosage' => 'ڕۆژانە ٣ جار، هەر ٨ کاتژمێر جارێک، بۆ ماوەی ٥ ڕۆژ'],
            ['name' => 'IBUPROFEN 400MG', 'form' => 'Tab', 'qty' => '1', 'unit' => 'پاکەت', 'dosage' => 'لە کاتی ئازاردا، دوای نانخواردن'],
            ['name' => 'Chlorhexidine 0.12%', 'form' => 'Mouth Wash', 'qty' => '1', 'unit' => 'بوتڵ', 'dosage' => 'ڕۆژانە ٢ جار دەم بشۆ'],
        ],
        'notes'   => '',
        'rx_id'   => 1234,
        'barcode' => '7700001234',
        'sold'    => null,
        'embed'   => true,
        'bar'     => false,
    ];
}
