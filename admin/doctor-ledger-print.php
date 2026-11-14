<?php
/**
 * حساباتی مانگانەی دکتۆر — the printed paper (admin/doctor-ledger.php):
 * the summary, then the private expenses line by line, and a place to sign.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/DoctorLedger.php';
$auth = new Auth();
$auth->requirePermission('page.doctor_ledger');

$doctorId = (int)($_GET['doctor'] ?? 0);
$month = DoctorLedger::month($_GET['month'] ?? null);
$doctor = DoctorLedger::doctor($doctorId);
$sheet = $doctor ? DoctorLedger::sheet($doctorId, $month) : null;
if (!$sheet) {
    http_response_code(404);
    die('ئەم مانگە بۆ ئەم دکتۆرە پاشەکەوت نەکراوە');
}
$lines = DoctorLedger::lines((int)$sheet['id']);
$t = DoctorLedger::totals($lines);
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$iqd = fn($v) => $e(DoctorLedger::fmt($v));
$usd = fn($v) => $e(DoctorLedger::fmt($v, true));
$monthNo = (int)substr($month, 5, 2);
$name = $doctor['full_name'];
// the dollar column only when a dollar figure was written
$hasUsd = false;
foreach (array_merge($lines['summary'], $lines['detail']) as $l) {
    if (abs((float)$l['amount_usd']) >= 0.005) { $hasUsd = true; break; }
}
$cols = $hasUsd ? 4 : 3;
$row = function ($label, $a, $b, $note = '', $class = '') use ($e, $iqd, $usd, $hasUsd) {
    return '<tr class="' . $class . '"><td class="l">' . $label . '</td><td class="n">' . $iqd($a) . '</td>'
        . ($hasUsd ? '<td class="n">' . $usd($b) . '</td>' : '')
        . '<td class="note">' . $e($note) . '</td></tr>';
};
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>حساباتی مانگی <?= $monthNo ?> — <?= $e($name) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
@page { size: A4 portrait; margin: 14mm; }
* { box-sizing: border-box; }
html, body { margin: 0; background: #e9edf2; color: #111; font-family: var(--app-font-family, 'NRT', Tahoma, sans-serif); }
.bar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: center; gap: 8px; padding: 10px; background: #0f2a3a; }
.bar button { padding: 8px 18px; border: 0; border-radius: 6px; background: #1565c0; color: #fff; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
.bar button.close { background: #475569; }
.page { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 16mm 18mm; background: #fff; box-shadow: 0 8px 26px rgba(15, 30, 50, .18); }

table { width: 100%; border-collapse: collapse; }
td, th { border: 1px solid #333; padding: 4px 8px; font-size: 11pt; height: 8mm; }
th.title { font-size: 12.5pt; font-weight: 800; text-align: center; background: #fff; }
tr.head th { font-size: 9.5pt; font-weight: 700; background: #f1f1f1; }
td.l { width: 40%; font-weight: 600; }
td.n { width: <?= $hasUsd ? '19%' : '24%' ?>; background: #e3e3e3; direction: ltr; text-align: left; font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
td.note { font-size: 9pt; color: #333; }
tr.tot td.l { font-size: 12.5pt; font-weight: 800; text-align: center; }
tr.tot td.n { font-size: 12pt; }
tr.left td { font-weight: 800; }
tr.sign td.l { font-weight: 700; }
tr.sign td { height: 14mm; }
.gap td { border: 0; height: 0; padding: 0; }
.note-all { margin-top: 8mm; padding: 6px 10px; border: 1px dashed #777; font-size: 10pt; }
.foot { margin-top: 10mm; font-size: 8pt; color: #888; text-align: left; direction: ltr; }

@media print {
    html, body { background: #fff; }
    .bar { display: none !important; }
    .page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<div class="bar">
    <button type="button" onclick="window.print()">چاپکردن</button>
    <button type="button" class="close" onclick="window.close()">داخستن</button>
</div>
<div class="page">
    <table>
        <tr><th class="title" colspan="<?= $cols ?>">حساباتی مانگی <?= $monthNo ?> بۆ <?= $e($name) ?> <span style="font-weight:600;font-size:10pt;">(<?= $e(substr($month, 0, 4)) ?>)</span></th></tr>
        <?php if ($hasUsd): ?>
        <tr class="head"><th></th><th>دینار</th><th>دۆلار</th><th></th></tr>
        <?php endif; ?>
        <?php foreach ($lines['summary'] as $l): ?>
        <?= $row($e($l['label']) . ((int)$l['sign'] < 0 ? ' <small>(−)</small>' : ''), $l['amount_iqd'], $l['amount_usd'], $l['note'] ?? '') ?>
        <?php endforeach; ?>
        <?= $row('کۆی گشتی', $t['total_iqd'], $t['total_usd']) ?>
        <?= $row('خەرجی تایبەتی ' . $e($name), $t['private_iqd'], $t['private_usd']) ?>
        <?= $row('ماوە', $t['left_iqd'], $t['left_usd'], '', 'left') ?>

        <tr><th class="title" colspan="<?= $cols ?>">وردەکاری خەرجی تایبەتی <?= $e($name) ?> مانگی <?= $monthNo ?></th></tr>
        <?php foreach ($lines['detail'] as $l): ?>
        <?= $row($e($l['label']), $l['amount_iqd'], $l['amount_usd'], $l['note'] ?? '') ?>
        <?php endforeach; ?>
        <?= $row('کۆی گشتی', $t['private_iqd'], $t['private_usd'], '', 'tot') ?>
        <tr class="sign"><td class="l">ئیمزا</td><td colspan="<?= $cols - 1 ?>"></td></tr>
    </table>

    <?php if (trim((string)$sheet['note']) !== ''): ?>
    <div class="note-all"><b>تێبینی:</b> <?= nl2br($e($sheet['note'])) ?></div>
    <?php endif; ?>
    <div class="foot"><?= $e(date('Y-m-d H:i')) ?></div>
</div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
