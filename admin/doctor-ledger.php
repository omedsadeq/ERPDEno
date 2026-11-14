<?php
/**
 * حساباتی مانگانەی دکتۆر — a doctor's own monthly paper, typed in by hand and
 * printed as a report. Stands on its own: nothing is read from or written to
 * the rest of the system except the doctor's name (includes/DoctorLedger.php).
 */

$pageTitle = 'حساباتی مانگانەی دکتۆر';
$breadcrumb = [['title' => 'حساباتی مانگانەی دکتۆر']];

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/DoctorLedger.php';
$authGate = new Auth();
$authGate->requirePermission('page.doctor_ledger');

$doctors = DoctorLedger::doctors();
$doctorId = (int)($_REQUEST['doctor'] ?? ($doctors[0]['id'] ?? 0));
$doctor = $doctorId ? DoctorLedger::doctor($doctorId) : null;
$month = DoctorLedger::month($_REQUEST['month'] ?? null);
$selfUrl = fn($m = null, $extra = '') => APP_URL . '/admin/doctor-ledger.php?doctor=' . $doctorId . '&month=' . urlencode($m ?? $month) . $extra;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $doctor) {
    Csrf::require();
    $userId = (int)($authGate->getCurrentUser()['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $rows = function (string $p) {
            $out = [];
            foreach ((array)($_POST[$p . '_label'] ?? []) as $i => $label) {
                $out[] = [
                    'label' => $label,
                    'iqd' => $_POST[$p . '_iqd'][$i] ?? 0,
                    'usd' => $_POST[$p . '_usd'][$i] ?? 0,
                    'sign' => $_POST[$p . '_sign'][$i] ?? 1,
                    'note' => $_POST[$p . '_note'][$i] ?? '',
                ];
            }
            return $out;
        };
        DoctorLedger::save($doctorId, $month, $rows('summary'), $rows('detail'), (string)($_POST['note'] ?? ''), $userId);
        header('Location: ' . $selfUrl(null, '&saved=1'));
        exit;
    }
    if ($action === 'delete') {
        DoctorLedger::delete($doctorId, $month);
        header('Location: ' . $selfUrl(null, '&deleted=1'));
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';

$sheet = $doctor ? DoctorLedger::sheet($doctorId, $month) : null;
$starterFrom = null;
if ($sheet) {
    $lines = DoctorLedger::lines((int)$sheet['id']);
} elseif ($doctor) {
    $starter = DoctorLedger::starterLines($doctor, $month);
    $lines = $starter['lines'];
    $starterFrom = $starter['from'];
} else {
    $lines = ['summary' => [], 'detail' => []];
}
$totals = DoctorLedger::totals($lines);
$months = $doctor ? DoctorLedger::months($doctorId) : [];
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = fn($v) => (abs((float)$v) < 0.005) ? '' : rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
$monthNo = (int)substr($month, 5, 2);
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> پاشەکەوت کرا</div>
<?php elseif (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> حساباتی ئەم مانگە سڕایەوە</div>
<?php endif; ?>

<div class="panel dl-bar">
    <form method="GET" class="dl-filter">
        <label>
            <span>دکتۆر</span>
            <select name="doctor" class="form-control" onchange="this.form.submit()">
                <?php foreach ($doctors as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $doctorId ? 'selected' : '' ?>><?= $e($d['full_name']) ?><?= $d['is_active'] ? '' : ' (ناچالاک)' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>مانگ</span>
            <span class="dl-month">
                <a class="btn btn-sm btn-secondary" href="<?= $e($selfUrl($prevMonth)) ?>" title="مانگی پێشوو"><i class="fas fa-chevron-right"></i></a>
                <input type="month" name="month" value="<?= $e($month) ?>" class="form-control" onchange="this.form.submit()">
                <a class="btn btn-sm btn-secondary" href="<?= $e($selfUrl($nextMonth)) ?>" title="مانگی دواتر"><i class="fas fa-chevron-left"></i></a>
            </span>
        </label>
    </form>
    <div class="dl-hint"><i class="fas fa-info-circle"></i> ئەم پەڕەیە سەربەخۆیە: هیچ ژمارەیەک لە سیستەمەوە ناهێنێت و هیچ شتێک لە سیستەم ناگۆڕێت، تەنها ناوی دکتۆرەکە.</div>
</div>

<?php if (!$doctor): ?>
<div class="panel"><div class="panel-body" style="text-align:center;padding:40px;color:#888;">هیچ دکتۆرێک نییە</div></div>
<?php else: ?>

<div class="dl-grid">
<form method="POST" id="dlForm" class="panel dl-sheet" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="doctor" value="<?= $doctorId ?>">
    <input type="hidden" name="month" value="<?= $e($month) ?>">

    <?php if (!$sheet): ?>
    <div class="dl-new">
        <i class="fas fa-file-circle-plus"></i>
        حساباتی ئەم مانگە هێشتا پاشەکەوت نەکراوە.
        <?= $starterFrom ? 'ناوی ڕیزەکان لە ' . $e(DoctorLedger::monthLabel($starterFrom)) . ' هێنراون، تەنها بڕەکان بنووسە.' : '' ?>
    </div>
    <?php endif; ?>

    <table class="dl-table">
        <colgroup><col class="c-label"><col class="c-amt"><col class="c-amt"><col class="c-note"><col class="c-act"></colgroup>
        <thead>
            <tr class="dl-title"><th colspan="5">حساباتی مانگی <?= $monthNo ?> بۆ <?= $e($doctor['full_name']) ?> <small><?= $e(substr($month, 0, 4)) ?></small></th></tr>
            <tr class="dl-head"><th>بابەت</th><th>دینار</th><th>دۆلار</th><th>تێبینی</th><th></th></tr>
        </thead>
        <tbody id="summaryRows">
            <?php foreach ($lines['summary'] as $l): ?>
            <tr class="dl-row" data-section="summary">
                <td><div class="dl-label-cell">
                    <button type="button" class="dl-sign <?= (int)($l['sign'] ?? 1) < 0 ? 'neg' : '' ?>" title="کۆکردنەوە / لێدەرکردن">
                        <?= (int)($l['sign'] ?? 1) < 0 ? '−' : '+' ?></button>
                    <input type="hidden" name="summary_sign[]" value="<?= (int)($l['sign'] ?? 1) < 0 ? -1 : 1 ?>">
                    <input type="text" name="summary_label[]" value="<?= $e($l['label']) ?>" class="dl-in" placeholder="ناوی ڕیز">
                </div></td>
                <td><input type="text" inputmode="decimal" name="summary_iqd[]" value="<?= $e($num($l['amount_iqd'])) ?>" class="dl-in dl-num" data-cur="iqd"></td>
                <td><input type="text" inputmode="decimal" name="summary_usd[]" value="<?= $e($num($l['amount_usd'])) ?>" class="dl-in dl-num" data-cur="usd"></td>
                <td><input type="text" name="summary_note[]" value="<?= $e($l['note'] ?? '') ?>" class="dl-in dl-note"></td>
                <td><button type="button" class="dl-del" title="سڕینەوەی ڕیز"><i class="fas fa-times"></i></button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tbody>
            <tr class="dl-addrow"><td colspan="5"><button type="button" class="dl-add" data-target="summary"><i class="fas fa-plus"></i> ڕیزێکی تر</button></td></tr>
            <tr class="dl-calc"><td>کۆی گشتی</td><td class="n" id="tTotalIqd"></td><td class="n" id="tTotalUsd"></td><td colspan="2"></td></tr>
            <tr class="dl-calc"><td>خەرجی تایبەتی <?= $e($doctor['full_name']) ?></td><td class="n" id="tPrivIqd"></td><td class="n" id="tPrivUsd"></td><td colspan="2" class="dl-sub">کۆی وردەکارییەکەی خوارەوە</td></tr>
            <tr class="dl-calc dl-left"><td>ماوە</td><td class="n" id="tLeftIqd"></td><td class="n" id="tLeftUsd"></td><td colspan="2"></td></tr>
        </tbody>

        <tbody>
            <tr class="dl-title"><th colspan="5">وردەکاری خەرجی تایبەتی <?= $e($doctor['full_name']) ?> مانگی <?= $monthNo ?></th></tr>
        </tbody>
        <tbody id="detailRows">
            <?php foreach ($lines['detail'] as $l): ?>
            <tr class="dl-row" data-section="detail">
                <td><input type="text" name="detail_label[]" value="<?= $e($l['label']) ?>" class="dl-in" placeholder="ناوی خەرجی"></td>
                <td><input type="text" inputmode="decimal" name="detail_iqd[]" value="<?= $e($num($l['amount_iqd'])) ?>" class="dl-in dl-num" data-cur="iqd"></td>
                <td><input type="text" inputmode="decimal" name="detail_usd[]" value="<?= $e($num($l['amount_usd'])) ?>" class="dl-in dl-num" data-cur="usd"></td>
                <td><input type="text" name="detail_note[]" value="<?= $e($l['note'] ?? '') ?>" class="dl-in dl-note"></td>
                <td><button type="button" class="dl-del" title="سڕینەوەی ڕیز"><i class="fas fa-times"></i></button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tbody>
            <tr class="dl-addrow"><td colspan="5"><button type="button" class="dl-add" data-target="detail"><i class="fas fa-plus"></i> خەرجییەکی تر</button></td></tr>
            <tr class="dl-calc"><td>کۆی گشتی</td><td class="n" id="tDetIqd"></td><td class="n" id="tDetUsd"></td><td colspan="2"></td></tr>
        </tbody>
    </table>

    <div class="dl-note-box">
        <label for="dlNote">تێبینی گشتی</label>
        <textarea id="dlNote" name="note" class="form-control" rows="2"><?= $e($sheet['note'] ?? '') ?></textarea>
    </div>

    <div class="dl-actions">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
        <?php if ($sheet): ?>
        <a class="btn btn-success" target="_blank" href="<?= APP_URL ?>/admin/doctor-ledger-print.php?doctor=<?= $doctorId ?>&month=<?= $e($month) ?>"><i class="fas fa-print"></i> چاپکردن</a>
        <?php endif; ?>
        <span class="dl-dirty" id="dlDirty" hidden><i class="fas fa-circle"></i> گۆڕانکاری پاشەکەوت نەکراوە</span>
        <?php if ($sheet): ?>
        <button type="button" class="btn btn-danger dl-delete-btn" id="dlDelete"><i class="fas fa-trash"></i> سڕینەوەی ئەم مانگە</button>
        <?php endif; ?>
    </div>
</form>

<div class="panel dl-months">
    <div class="panel-header"><h3 class="panel-title"><i class="fas fa-calendar-alt"></i> مانگەکانی <?= $e($doctor['full_name']) ?></h3></div>
    <div class="panel-body" style="padding:0;">
        <?php if (!$months): ?>
        <div style="padding:20px;text-align:center;color:#888;">هێشتا هیچ مانگێک نییە</div>
        <?php else: ?>
        <table class="data-grid">
            <thead><tr><th>مانگ</th><th>ماوە</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($months as $m): ?>
            <tr class="<?= $m['month'] === $month ? 'dl-current' : '' ?>">
                <td><a href="<?= $e($selfUrl($m['month'])) ?>"><?= $e(DoctorLedger::monthLabel($m['month'])) ?></a></td>
                <td class="n">
                    <?= $e(DoctorLedger::fmt($m['totals']['left_iqd'])) ?>
                    <?php if (abs($m['totals']['left_usd']) >= 0.005): ?><div><?= $e(DoctorLedger::fmt($m['totals']['left_usd'], true)) ?></div><?php endif; ?>
                </td>
                <td><a class="btn btn-sm btn-secondary" target="_blank" title="چاپکردن" href="<?= APP_URL ?>/admin/doctor-ledger-print.php?doctor=<?= $doctorId ?>&month=<?= $e($m['month']) ?>"><i class="fas fa-print"></i></a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if ($sheet): ?>
<form method="POST" id="dlDeleteForm" hidden>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="doctor" value="<?= $doctorId ?>">
    <input type="hidden" name="month" value="<?= $e($month) ?>">
</form>
<?php endif; ?>

<template id="tplSummary">
    <tr class="dl-row" data-section="summary">
        <td><div class="dl-label-cell">
            <button type="button" class="dl-sign" title="کۆکردنەوە / لێدەرکردن">+</button>
            <input type="hidden" name="summary_sign[]" value="1">
            <input type="text" name="summary_label[]" class="dl-in" placeholder="ناوی ڕیز">
        </div></td>
        <td><input type="text" inputmode="decimal" name="summary_iqd[]" class="dl-in dl-num" data-cur="iqd"></td>
        <td><input type="text" inputmode="decimal" name="summary_usd[]" class="dl-in dl-num" data-cur="usd"></td>
        <td><input type="text" name="summary_note[]" class="dl-in dl-note"></td>
        <td><button type="button" class="dl-del" title="سڕینەوەی ڕیز"><i class="fas fa-times"></i></button></td>
    </tr>
</template>
<template id="tplDetail">
    <tr class="dl-row" data-section="detail">
        <td><input type="text" name="detail_label[]" class="dl-in" placeholder="ناوی خەرجی"></td>
        <td><input type="text" inputmode="decimal" name="detail_iqd[]" class="dl-in dl-num" data-cur="iqd"></td>
        <td><input type="text" inputmode="decimal" name="detail_usd[]" class="dl-in dl-num" data-cur="usd"></td>
        <td><input type="text" name="detail_note[]" class="dl-in dl-note"></td>
        <td><button type="button" class="dl-del" title="سڕینەوەی ڕیز"><i class="fas fa-times"></i></button></td>
    </tr>
</template>

<script>
(function () {
    const form = document.getElementById('dlForm');
    const digits = {'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9','٫':'.'};
    const parse = v => {
        v = String(v || '').replace(/[٠-٩٫]/g, c => digits[c]).replace(/[,٬\s$]/g, '');
        const n = parseFloat(v);
        return isFinite(n) ? n : 0;
    };
    const fmt = (n, usd) => {
        if (Math.abs(n) < 0.005) return '';
        const s = Math.abs(n).toLocaleString('en-US', {maximumFractionDigits: 2});
        return (n < 0 ? '-' : '') + (usd ? '$' : '') + s;
    };
    const set = (id, n, usd) => {
        const el = document.getElementById(id);
        el.textContent = fmt(n, usd);
        el.classList.toggle('neg', n < -0.004);
    };
    let dirty = false;
    const markDirty = () => { dirty = true; document.getElementById('dlDirty').hidden = false; };

    function recalc() {
        const t = {sumIqd: 0, sumUsd: 0, detIqd: 0, detUsd: 0};
        form.querySelectorAll('#summaryRows .dl-row').forEach(tr => {
            const sign = parseInt(tr.querySelector('[name="summary_sign[]"]').value, 10) < 0 ? -1 : 1;
            t.sumIqd += sign * parse(tr.querySelector('[data-cur="iqd"]').value);
            t.sumUsd += sign * parse(tr.querySelector('[data-cur="usd"]').value);
        });
        form.querySelectorAll('#detailRows .dl-row').forEach(tr => {
            t.detIqd += parse(tr.querySelector('[data-cur="iqd"]').value);
            t.detUsd += parse(tr.querySelector('[data-cur="usd"]').value);
        });
        set('tTotalIqd', t.sumIqd); set('tTotalUsd', t.sumUsd, true);
        set('tPrivIqd', t.detIqd); set('tPrivUsd', t.detUsd, true);
        set('tLeftIqd', t.sumIqd - t.detIqd); set('tLeftUsd', t.sumUsd - t.detUsd, true);
        set('tDetIqd', t.detIqd); set('tDetUsd', t.detUsd, true);
    }

    function addRow(section, focus) {
        const tpl = document.getElementById(section === 'summary' ? 'tplSummary' : 'tplDetail');
        const tr = tpl.content.firstElementChild.cloneNode(true);
        document.getElementById(section + 'Rows').appendChild(tr);
        if (focus) tr.querySelector('[name$="_label[]"]').focus();
        return tr;
    }

    form.addEventListener('click', ev => {
        const add = ev.target.closest('.dl-add');
        if (add) { addRow(add.dataset.target, true); markDirty(); return; }
        const del = ev.target.closest('.dl-del');
        if (del) { del.closest('tr').remove(); recalc(); markDirty(); return; }
        const sign = ev.target.closest('.dl-sign');
        if (sign) {
            const input = sign.parentElement.querySelector('[name="summary_sign[]"]');
            const neg = input.value !== '-1';
            input.value = neg ? '-1' : '1';
            sign.textContent = neg ? '−' : '+';
            sign.classList.toggle('neg', neg);
            recalc(); markDirty();
        }
    });
    form.addEventListener('input', ev => {
        markDirty();
        if (ev.target.classList.contains('dl-num')) recalc();
    });
    // 1500000 → 1,500,000 when leaving the box
    form.addEventListener('focusout', ev => {
        if (!ev.target.classList.contains('dl-num') || ev.target.value.trim() === '') return;
        const n = parse(ev.target.value);
        ev.target.value = Math.abs(n) < 0.005 ? '' : n.toLocaleString('en-US', {maximumFractionDigits: 2});
    });
    // Enter on the last row of a part opens a new row instead of sending the form
    form.addEventListener('keydown', ev => {
        if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
        ev.preventDefault();
        const tr = ev.target.closest('.dl-row');
        if (!tr) return;
        const next = tr.nextElementSibling || addRow(tr.dataset.section, false);
        const col = [...tr.querySelectorAll('input:not([type=hidden])')].indexOf(ev.target);
        const inputs = next.querySelectorAll('input:not([type=hidden])');
        (inputs[col] || inputs[0]).focus();
        markDirty();
    });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', ev => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });

    const delBtn = document.getElementById('dlDelete');
    if (delBtn) delBtn.addEventListener('click', () => {
        if (confirm('هەموو حساباتی ئەم مانگە دەسڕدرێتەوە. دڵنیایت؟')) {
            dirty = false;
            document.getElementById('dlDeleteForm').submit();
        }
    });

    // a new paper always has a few empty expense rows to type into
    if (!document.querySelector('#detailRows .dl-row')) for (let i = 0; i < 5; i++) addRow('detail', false);
    form.querySelectorAll('.dl-num').forEach(i => { if (i.value) i.value = parse(i.value).toLocaleString('en-US', {maximumFractionDigits: 2}); });
    recalc();
})();
</script>
<?php endif; ?>

<style>
.dl-bar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; padding: 14px 16px; margin-bottom: 16px; }
.dl-filter { display: flex; flex-wrap: wrap; gap: 14px; }
.dl-filter label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 700; color: #555; }
.dl-filter select { min-width: 220px; }
.dl-month { display: flex; align-items: center; gap: 6px; }
.dl-month input { width: 170px; }
.dl-hint { flex: 1; min-width: 240px; font-size: 12px; color: #6b7280; padding-bottom: 8px; }
.dl-hint i { color: #0ea5e9; }

.dl-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 16px; align-items: start; }
.dl-sheet { padding: 16px; }
.dl-new { margin-bottom: 12px; padding: 9px 12px; border-radius: 8px; background: #fff8e1; border: 1px solid #ffe08a; color: #8a6100; font-size: 13px; }

.dl-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.dl-table col.c-label { width: 36%; } .dl-table col.c-amt { width: 17%; } .dl-table col.c-note { width: auto; } .dl-table col.c-act { width: 34px; }
.dl-table td, .dl-table th { border: 1px solid #c9d2dc; padding: 0; }
.dl-title th { padding: 9px 10px; font-size: 15px; font-weight: 800; text-align: center; background: linear-gradient(90deg, #1565c0, #1e88e5); color: #fff; }
.dl-title th small { font-weight: 600; opacity: .85; margin-inline-start: 6px; }
.dl-head th { padding: 6px 10px; font-size: 12px; background: #eef3f8; color: #445; text-align: center; }
.dl-in { width: 100%; border: 0; background: transparent; padding: 7px 10px; font: inherit; font-size: 13.5px; color: inherit; }
.dl-in:focus { outline: 2px solid #1e88e5; outline-offset: -2px; background: #f5faff; }
.dl-num { direction: ltr; text-align: left; font-variant-numeric: tabular-nums; background: #f3f5f7; }
.dl-note { font-size: 12.5px; color: #555; }
.dl-label-cell { display: flex; align-items: center; }
.dl-sign { flex: none; width: 24px; height: 24px; margin-inline-start: 6px; border-radius: 50%; border: 1px solid #a5d6a7; background: #e8f5e9; color: #2e7d32; font-weight: 800; line-height: 1; cursor: pointer; }
.dl-sign.neg { border-color: #ef9a9a; background: #ffebee; color: #c62828; }
.dl-del { width: 100%; height: 32px; border: 0; background: none; color: #b0b8c1; cursor: pointer; }
.dl-del:hover { color: #e53935; background: #fff1f1; }
.dl-addrow td { border-left: 0; border-right: 0; padding: 4px 0; }
.dl-add { border: 1px dashed #90caf9; background: #f5faff; color: #1565c0; border-radius: 6px; padding: 4px 12px; font: inherit; font-size: 12.5px; cursor: pointer; }
.dl-add:hover { background: #e3f2fd; }
.dl-calc td { padding: 7px 10px; font-weight: 700; background: #fafbfc; font-size: 13.5px; }
.dl-calc td.n { direction: ltr; text-align: left; font-variant-numeric: tabular-nums; }
.dl-calc .dl-sub { font-weight: 500; font-size: 11.5px; color: #8a94a0; }
.dl-calc.dl-left td { background: #e8f5e9; color: #1b5e20; font-size: 15px; font-weight: 800; }
.dl-calc td.neg, .dl-calc.dl-left td.neg { color: #c62828; }
.dl-table tbody + tbody .dl-title th { border-top: 10px solid #fff; }

.dl-note-box { margin-top: 14px; }
.dl-note-box label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 4px; }
.dl-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 14px; }
.dl-dirty { font-size: 12px; color: #e65100; }
.dl-dirty i { font-size: 8px; vertical-align: middle; }
.dl-delete-btn { margin-inline-start: auto; }

.dl-months td.n { direction: ltr; text-align: left; font-variant-numeric: tabular-nums; font-weight: 700; }
.dl-months tr.dl-current td { background: #e3f2fd; }

@media (max-width: 1000px) {
    .dl-grid { grid-template-columns: 1fr; }
}
@media (max-width: 700px) {
    .dl-sheet { overflow-x: auto; }
    .dl-table { min-width: 640px; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
