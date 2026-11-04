<?php
/**
 * پسوولەی گەڕاندنەوەی پارە — the paper of money given back to a patient, for
 * him to sign. Printed from the row's own figures, so a cancelled voucher can
 * always be reprinted as it was, stamped هەڵوەشێنراوە.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/IncomeColumn.php';

$auth = new Auth();
$auth->requireLogin();
$viewer = $auth->getCurrentUser();
$voucher = (new IncomeColumn())->voucher((int)($_GET['id'] ?? 0));
if (!$voucher) {
    die('پسوولە نەدۆزرایەوە');
}
// Finance staff, or the doctor it was charged to.
$isOwn = ($viewer['role'] ?? '') === 'doctor' && (int)$viewer['id'] === (int)$voucher['doctor_id'];
if (!$isOwn && !Permission::getInstance()->can('page.accountant_financial')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
require __DIR__ . '/../includes/clinic_branding.php';

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$amount = CashboxLedger::money($voucher['amount']);
$isCancelled = $voucher['status'] !== 'active';
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پسوولەی گەڕاندنەوە — <?= $e($voucher['voucher_no']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
@page { size: A5 landscape; margin: 7mm; }
:root { --ink: #0f172a; --rose: #be123c; --rose-soft: #fff1f2; --line: #cfd8e3; }
* { box-sizing: border-box; }
html, body { margin: 0; background: #e9edf2; color: var(--ink); font-family: var(--app-font-family, 'NRT', Tahoma, sans-serif); }
.bar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: center; gap: 8px; padding: 10px; background: #0f2a3a; }
.bar button { padding: 8px 18px; border: 0; border-radius: 6px; background: var(--rose); color: #fff; font: inherit; font-size: 13px; cursor: pointer; }
.page { position: relative; width: 196mm; min-height: 134mm; margin: 12px auto; padding: 8mm 9mm; background: #fff; box-shadow: 0 8px 26px rgba(15, 30, 50, .1); overflow: hidden; }
.head { display: flex; align-items: center; gap: 10px; padding-bottom: 5px; border-bottom: 2.5px solid var(--rose); }
.head img { width: 17mm; height: auto; }
.head .clinic { flex: 1; line-height: 1.5; }
.head .clinic b { font-size: 13pt; }
.head .clinic div { font-size: 8.5pt; color: #475569; }
.head .doc-no { text-align: center; padding: 4px 12px; border: 1.5px solid var(--rose); border-radius: 6px; background: var(--rose-soft); }
.head .doc-no small { display: block; font-size: 7.5pt; font-weight: 700; color: var(--rose); }
.head .doc-no b { display: block; font-size: 11pt; color: var(--rose); direction: ltr; }
h1 { margin: 8px 0 7px; font-size: 14pt; text-align: center; }
.info { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid var(--line); border-radius: 6px; overflow: hidden; }
.info div { padding: 5px 9px; font-size: 8.5pt; color: #475569; border-inline-start: 1px solid var(--line); border-top: 1px solid var(--line); }
.info div:nth-child(3n+1) { border-inline-start: 0; }
.info div:nth-child(-n+3) { border-top: 0; }
.info b { display: block; font-size: 10.5pt; color: var(--ink); }
.amount { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 8px; padding: 8px 12px; border: 2px solid var(--rose); border-radius: 7px; background: var(--rose-soft); }
.amount span { font-size: 10.5pt; font-weight: 700; color: var(--rose); }
.amount b { font-size: 19pt; color: var(--rose); direction: ltr; font-variant-numeric: tabular-nums; }
.why { margin-top: 8px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 6px; font-size: 10pt; line-height: 1.7; }
.why small { display: block; font-size: 8pt; font-weight: 700; color: #64748b; }
.note { margin-top: 6px; font-size: 8.5pt; color: #475569; line-height: 1.7; }
.sign { display: grid; grid-template-columns: 1fr 1fr; gap: 26mm; margin-top: 15mm; padding: 0 8mm; }
.sign div { padding-top: 5px; border-top: 1px solid #64748b; text-align: center; font-size: 9pt; color: #475569; }
.void { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.void span { transform: rotate(-20deg); padding: 3mm 10mm; border: 6px solid rgba(220, 38, 38, .16); border-radius: 8mm; font-size: 44pt; font-weight: 900; color: rgba(220, 38, 38, .16); }
.void-note { margin-top: 6px; padding: 5px 9px; border-radius: 6px; background: #fef2f2; color: #b91c1c; font-size: 9pt; font-weight: 700; }
@media print {
    html, body { background: #fff; }
    .bar { display: none; }
    .page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">چاپکردن</button></div>
<div class="page">
    <?php if ($isCancelled): ?><div class="void"><span>هەڵوەشێنراوە</span></div><?php endif; ?>
    <div class="head">
        <img src="<?= $e($logoUrl) ?>" alt="" onerror="this.style.display='none'">
        <div class="clinic">
            <b><?= $e($clinicName) ?></b>
            <div><bdi><?= $e(implode(' - ', $clinicPhones)) ?></bdi></div>
            <div><?= $e($clinicAddress) ?></div>
        </div>
        <div class="doc-no"><small>ژمارەی پسوولە</small><b><?= $e($voucher['voucher_no']) ?></b></div>
    </div>

    <h1>پسوولەی گەڕاندنەوەی پارە</h1>

    <div class="info">
        <div>نەخۆش<b><?= $e($voucher['patient_name']) ?></b></div>
        <div>مۆبایل<b><bdi><?= $e($voucher['patient_phone'] ?: '—') ?></bdi></b></div>
        <div>بەروار<b><bdi><?= $e($voucher['entry_date']) ?></bdi></b></div>
        <div>کەیس<b><?= $e($voucher['case_label'] ?: '—') ?></b></div>
        <div>دکتۆر<b><?= $e($voucher['doctor_name'] ?? '—') ?></b></div>
        <div>تۆمارکەر<b><?= $e($voucher['created_by_name'] ?? '—') ?></b></div>
    </div>

    <div class="amount"><span>بڕی گەڕێنراوە</span><b><?= $e($amount) ?></b></div>

    <div class="why"><small>هۆکار</small><?= $e($voucher['note']) ?></div>
    <div class="note">ئەم بڕە لە نرخی چارەسەرەکە کەم کرایەوە و بە نەقد درایەوە بە نەخۆش.</div>

    <?php if ($isCancelled): ?>
    <div class="void-note">ئەم پسوولەیە هەڵوەشێنراوەتەوە<?= $voucher['cancelled_at'] ? ' لە ' . $e(substr($voucher['cancelled_at'], 0, 16)) : '' ?><?= $voucher['cancelled_by_name'] ? ' لەلایەن ' . $e($voucher['cancelled_by_name']) : '' ?> — <?= $e($voucher['cancel_reason']) ?></div>
    <?php endif; ?>

    <div class="sign">
        <div>واژووی نەخۆش (وەرگر)</div>
        <div>واژووی ژمێریار</div>
    </div>
</div>
</body>
</html>
