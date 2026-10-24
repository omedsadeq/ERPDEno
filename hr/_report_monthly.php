<?php
/**
 * ڕاپۆرتی مانگانە — full screen.
 *
 * The monthly sheet is wide (one column per employee), so it opens on its own
 * page without the clinic's title bar, sidebar or HR section bar: one slim bar
 * with «گەڕانەوە», the month, and print / Excel / PDF, and the sheet fills
 * the rest of the window.
 *
 * Included by hr/reports.php after it has built $data and $pay; it expects
 * that file's variables ($month, $monthFrom, $monthTo, $fType, $fDept,
 * $departments, $canPay, $qs) and ends the request.
 *
 * «گەڕانەوە» goes back to the page the sheet was opened from — remembered for
 * the tab, so changing the month several times still returns there — or to
 * the HR dashboard when there is none.
 */

if (!isset($data, $pay, $month)) {
    http_response_code(404);
    exit;
}
$e = 'hr_esc';
// Columns can be dragged into another order and hidden, right on the sheet
// (saved through hr/api/columns.php for everyone, PDF and Excel included).
$canArrange = hr_can('hr.employees.manage');
$columnsAll = $columnsAll ?? array_column($data, 'employee');
$hiddenCount = count(array_filter($columnsAll, fn($c) => (int)($c['monthly_hidden'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
    <title>ڕاپۆرتی مانگانە — <?= $e(hr_month_label($month)) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
<?= $hrBaseCss ?>
body.hrf { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: #f4f6f8; }
.hrf-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex-shrink: 0; padding: 8px 14px;
    background: #fff; border-bottom: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(15, 23, 42, .06); }
.hrf-back { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 9px; background: #0f766e;
    color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; }
.hrf-back:hover { background: #115e59; color: #fff; text-decoration: none; }
.hrf-title { font-weight: 800; font-size: 15px; color: #0f172a; margin-inline-start: 4px; }
.hrf-title small { color: #64748b; font-weight: 600; margin-inline-start: 6px; }
.hrf-bar form { display: flex; gap: 8px; align-items: center; margin-inline-start: 10px; }
.hrf-bar .form-control { height: 34px; padding: 4px 10px; width: auto; min-width: 0; font-size: 13px; }
.hrf-tools { margin-inline-start: auto; display: flex; gap: 6px; }
.hrf-tools .btn { height: 34px; padding: 0 12px; font-size: 13px; }
.hrf-main { flex: 1; overflow: auto; padding: 10px 14px 18px; }
.hrf-sheet { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; display: inline-block; min-width: 100%; }
.hr-grid { border-collapse: separate; border-spacing: 0; font-size: 12.5px; width: 100%; }
.hr-grid th, .hr-grid td { border-bottom: 1px solid #eef2f7; border-inline-start: 1px solid #eef2f7; padding: 5px 4px; text-align: center; white-space: nowrap; }
.hr-grid thead th { background: #f8fafc; position: sticky; top: -10px; z-index: 2; vertical-align: bottom; }
.hr-grid th.emp { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 12.5px; font-weight: 700; color: #334155; padding: 8px 4px; min-width: 42px; }
.hr-grid .dcol { position: sticky; right: -14px; background: #fff; z-index: 1; text-align: right; font-weight: 700; min-width: 96px; }
.hr-grid thead .dcol { z-index: 3; background: #f8fafc; }
.hr-grid td.c-work { background: #ecfdf5; color: #065f46; font-weight: 700; direction: ltr; }
.hr-grid td.c-zero { background: #fffbeb; }
.hr-grid td.c-off { background: #fff7ed; color: #c2410c; font-size: 11px; }
.hr-grid td.c-abs { background: #fef2f2; color: #b91c1c; font-size: 11px; }
.hr-grid td.c-fut { background: #fff; }
.hr-grid .warn-dot, .hrf-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #f59e0b; vertical-align: top; margin-inline-start: 2px; }
.hr-grid tr.wk-off .dcol { color: #c2410c; }
.hr-grid tr.tot td { background: #f1f5f9; font-weight: 800; direction: ltr; border-top: 1px solid #cbd5e1; font-size: 11.5px; padding: 5px 3px; }
.hr-grid tr.tot td.dcol { direction: rtl; background: #e2e8f0; white-space: normal; max-width: 120px; line-height: 1.3; }
.hr-grid tr.money td { background: #f0fdf4; }
.hr-grid tr.money td.dcol { background: #dcfce7; }
.hr-grid tr.pct td.has { background: #ff9800; color: #fff; }
.hr-grid tr.final td { background: #e0f7fa; color: #0e7490; font-size: 12px; }
.hr-grid tr.final td.dcol { background: #cffafe; }
.hrf-legend { font-size: 12px; color: #64748b; margin: 8px 2px 18px; }
.hr-sumtable td.num b { color: #0e7490; }
.hrf-print-title { display: none; text-align: center; font-weight: 800; font-size: 16px; margin-bottom: 8px; }
/* Arranging columns */
.can-arrange .hr-grid th.emp { cursor: grab; }
.hr-grid th.emp .hrf-hide { display: inline-grid; place-items: center; visibility: hidden; transform: rotate(180deg); writing-mode: horizontal-tb;
    width: 22px; height: 22px; margin-block-start: 6px; border: 0; border-radius: 6px; background: #fee2e2; color: #b91c1c; cursor: pointer; font-size: 11px; }
.hr-grid th.emp:hover .hrf-hide { visibility: visible; }
.hr-grid th.emp.drag { opacity: .35; }
.hr-grid th.emp.drop-r { box-shadow: inset -3px 0 0 #0f766e; }
.hr-grid th.emp.drop-l { box-shadow: inset 3px 0 0 #0f766e; }
.hrf-colsbtn { position: relative; }
.hrf-colsbtn .n { position: absolute; top: -6px; inset-inline-start: -6px; min-width: 18px; height: 18px; border-radius: 9px; background: #dc2626;
    color: #fff; font-size: 10.5px; font-weight: 800; display: grid; place-items: center; padding: 0 4px; }
.hrf-cols { position: fixed; top: 58px; left: 14px; width: 330px; max-height: calc(100vh - 80px); display: flex; flex-direction: column; z-index: 40;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 18px 40px -12px rgba(15, 23, 42, .35); }
.hrf-cols[hidden] { display: none; }
.hrf-cols-h { display: flex; align-items: flex-start; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #f1f5f9; }
.hrf-cols-h b { font-size: 14px; }
.hrf-cols-h small { display: block; color: #94a3b8; font-size: 11.5px; }
.hrf-cols-h button { margin-inline-start: auto; border: 0; background: #f1f5f9; border-radius: 7px; width: 28px; height: 28px; cursor: pointer; }
.hrf-cols-list { list-style: none; margin: 0; padding: 6px; overflow: auto; flex: 1; }
.hrf-cols-list li { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; border: 1px solid transparent; }
.hrf-cols-list li:hover { background: #f8fafc; }
.hrf-cols-list li.is-off { opacity: .55; }
.hrf-cols-list li.drag { opacity: .35; }
.hrf-cols-list li.drop-t { box-shadow: inset 0 3px 0 #0f766e; }
.hrf-cols-list li.drop-b { box-shadow: inset 0 -3px 0 #0f766e; }
.hrf-cols-list .grip { color: #cbd5e1; cursor: grab; }
.hrf-cols-list label { flex: 1; display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 13px; }
.hrf-cols-list label small { color: #94a3b8; margin-inline-start: auto; direction: ltr; }
.hrf-cols-f { padding: 10px 14px; border-top: 1px solid #f1f5f9; display: flex; gap: 8px; }
.hrf-toast { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); background: #0f172a; color: #fff; padding: 10px 16px;
    border-radius: 10px; font-weight: 700; font-size: 13px; z-index: 60; display: none; align-items: center; gap: 12px; box-shadow: 0 10px 24px rgba(0, 0, 0, .25); }
.hrf-toast.show { display: flex; }
.hrf-toast.err { background: #b91c1c; }
.hrf-toast button { border: 0; background: #334155; color: #fff; border-radius: 7px; padding: 4px 10px; font: inherit; font-size: 12.5px; cursor: pointer; }
@media print {
    .hrf-cols, .hrf-toast, .hrf-hide { display: none !important; }
    @page { size: A3 landscape; margin: 8mm; }
    body.hrf { display: block; height: auto; overflow: visible; background: #fff; }
    .hrf-bar, .hrf-legend { display: none !important; }
    .hrf-main { overflow: visible; padding: 0; }
    .hrf-sheet { border: 0; display: block; }
    .hr-grid { font-size: 9px; }
    .hr-grid th.emp { min-width: 0; }
    .hr-grid thead th, .hr-grid .dcol { position: static; }
    .hrf-print-title { display: block; }
}
    </style>
</head>
<body class="hrf<?= $canArrange ? ' can-arrange' : '' ?>">

<div class="hrf-bar">
    <a class="hrf-back" id="hrfBack" href="<?= APP_URL ?>/hr/index.php" title="گەڕانەوە (Esc)"><i class="fas fa-arrow-right"></i> گەڕانەوە</a>
    <div class="hrf-title">ڕاپۆرتی مانگانە <small data-hr-refresh="count"><?= count($data) ?> کەس<?= $hiddenCount ? ' · ' . $hiddenCount . ' شاراوە' : '' ?></small></div>
    <form method="GET" id="hrfForm">
        <input type="hidden" name="r" value="monthly">
        <input type="month" name="month" class="form-control" value="<?= $e($month) ?>" onchange="this.form.submit()" aria-label="مانگ">
        <select name="type" class="form-control" onchange="this.form.submit()" aria-label="جۆر">
            <option value="">هەموو</option>
            <option value="staff" <?= $fType === 'staff' ? 'selected' : '' ?>>کارمەند</option>
            <option value="doctor" <?= $fType === 'doctor' ? 'selected' : '' ?>>دکتۆر</option>
        </select>
        <?php if ($departments): ?>
        <select name="department_id" class="form-control" onchange="this.form.submit()" aria-label="بەش">
            <option value="0">هەموو بەشەکان</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= $e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
    </form>
    <div class="hrf-tools">
        <?php if ($canArrange): ?>
        <button type="button" class="btn btn-secondary hrf-colsbtn" id="hrfColsBtn" title="ڕیزبەندی و شاردنەوەی ستوونەکان">
            <i class="fas fa-table-columns"></i> ستوونەکان<span data-hr-refresh="hiddenbadge"><?= $hiddenCount ? '<span class="n">' . $hiddenCount . '</span>' : '' ?></span>
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="window.print()" title="چاپ"><i class="fas fa-print"></i></button>
        <a class="btn btn-secondary" href="<?= $e($qs(['export' => 'csv'])) ?>" title="Excel"><i class="fas fa-file-excel"></i></a>
        <a class="btn btn-secondary" target="_blank" href="<?= APP_URL ?>/hr/report-pdf.php<?= $e($qs([])) ?>" title="PDF"><i class="fas fa-file-pdf"></i></a>
        <button type="button" class="btn btn-secondary" id="hrfFull" title="پڕبەشاشە"><i class="fas fa-expand"></i></button>
    </div>
</div>

<?php if ($canArrange): ?>
<div class="hrf-cols" id="hrfCols" hidden>
    <div class="hrf-cols-h">
        <div><b>ستوونەکانی ڕاپۆرت</b><small>ڕایبکێشە بۆ گۆڕینی ڕیزبەندی · نیشانەکە لابەرە بۆ شاردنەوە</small></div>
        <button type="button" id="hrfColsClose" title="داخستن"><i class="fas fa-times"></i></button>
    </div>
    <ol class="hrf-cols-list" id="hrfColsList" data-hr-refresh="columns">
        <?php foreach ($columnsAll as $c): $off = (int)($c['monthly_hidden'] ?? 0); ?>
        <li draggable="true" data-emp="<?= (int)$c['id'] ?>" class="<?= $off ? 'is-off' : '' ?>">
            <i class="fas fa-grip-vertical grip"></i>
            <label><input type="checkbox" data-show="<?= (int)$c['id'] ?>" <?= $off ? '' : 'checked' ?>> <?= $e($c['full_name']) ?><small><?= $e(hr_pin($c)) ?></small></label>
        </li>
        <?php endforeach; ?>
    </ol>
    <div class="hrf-cols-f">
        <button type="button" class="btn btn-sm btn-secondary" id="hrfShowAll"><i class="fas fa-eye"></i> پیشاندانی هەموو</button>
    </div>
</div>
<?php endif; ?>
<div class="hrf-toast" id="hrfToast"><span id="hrfToastText"></span></div>

<div class="hrf-main" data-hr-refresh="sheet">
    <div class="hrf-print-title">ڕاپۆرتی مانگانە — <?= $e(hr_month_label($month)) ?></div>
    <?php if (!$data): ?>
    <div class="hrf-sheet"><div class="hr-empty">هیچ کارمەندێک نییە</div></div>
    <?php else: ?>
    <div class="hrf-sheet">
        <table class="hr-grid">
            <thead>
                <tr>
                    <th class="dcol">بەروار</th>
                    <?php foreach ($data as $p): $pe = $p['employee']; ?><th class="emp" data-emp="<?= (int)$pe['id'] ?>"<?= $canArrange ? ' draggable="true"' : '' ?>
                        title="PIN <?= $e(hr_pin($pe)) ?><?= $canArrange ? ' — ڕایبکێشە بۆ گۆڕینی شوێن' : '' ?>"><?= $e($pe['full_name']) ?><?php if ($canArrange): ?><button type="button" class="hrf-hide" data-hide="<?= (int)$pe['id'] ?>" title="شاردنەوە لە ڕاپۆرتی مانگانە"><i class="fas fa-eye-slash"></i></button><?php endif; ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach (HrAttendance::dateRange($monthFrom, $monthTo) as $date):
                $wd = (int)date('w', strtotime($date)); ?>
                <tr class="<?= $wd === 5 ? 'wk-off' : '' ?>">
                    <td class="dcol"><?= $e(substr($date, 8)) ?> <small class="hr-muted"><?= $e(HrAttendance::WEEKDAYS[$wd]) ?></small></td>
                    <?php foreach ($data as $p):
                        $d = $p['days'][$date];
                        $tip = [];
                        if ($d['punches']) { $tip[] = hr_pairs_text($d['pairs']); }
                        if ($d['add'] > 0) { $tip[] = 'زیادە +' . HrAttendance::hm($d['add']); }
                        if ($d['sub'] > 0) { $tip[] = 'لێبڕین −' . HrAttendance::hm($d['sub']); }
                        if ($d['day_status'] === 'holiday' && $d['holiday']) { $tip[] = $d['holiday']; }
                        switch ($d['day_status']) {
                            case 'worked':
                                $cls = $d['hours'] > 0 ? 'c-work' : 'c-zero';
                                $txt = HrAttendance::hm($d['hours']);
                                break;
                            case 'holiday':
                                $cls = 'c-off';
                                $txt = 'پشوو';
                                break;
                            case 'absent':
                                $cls = 'c-abs';
                                $txt = 'غیاب';
                                break;
                            default:
                                $cls = 'c-fut';
                                $txt = '';
                        }
                    ?>
                    <td class="<?= $cls ?>" title="<?= $e(implode(' · ', $tip)) ?>" data-hrd-e="<?= (int)$p['employee']['id'] ?>" data-hrd-d="<?= $date ?>"><?= $e($txt) ?><?php if ($d['unpaired'] > 0 || $d['add'] > 0 || $d['sub'] > 0): ?><span class="warn-dot" style="<?= $d['unpaired'] > 0 ? '' : 'background:#0ea5e9' ?>"></span><?php endif; ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>

                <tr class="tot"><td class="dcol">کۆی گشتی پەنجەمۆر</td><?php foreach ($data as $p): ?><td><?= $e(HrAttendance::hm($p['totals']['hours'])) ?></td><?php endforeach; ?></tr>
                <tr class="tot"><td class="dcol">کۆی گشتی زیادە</td><?php foreach ($data as $p): ?><td><?= $e(HrAttendance::hm($p['totals']['add'])) ?></td><?php endforeach; ?></tr>
                <tr class="tot"><td class="dcol">کۆی گشتی لێبڕین</td><?php foreach ($data as $p): ?><td><?= $e(HrAttendance::hm($p['totals']['sub'])) ?></td><?php endforeach; ?></tr>
                <tr class="tot"><td class="dcol">کۆی گشتی سەعات</td><?php foreach ($data as $p): ?><td><b><?= $e(HrAttendance::hm($p['totals']['net'])) ?></b></td><?php endforeach; ?></tr>
                <?php if ($canPay): ?>
                <tr class="tot"><td class="dcol">کرێی کاتژمێر (IQD)</td><?php foreach ($data as $id => $p): ?><td><?= $pay[$id]['rate'] > 0 ? hr_money($pay[$id]['rate']) : '' ?></td><?php endforeach; ?></tr>
                <tr class="tot money"><td class="dcol">کۆی گشتی (IQD)</td><?php foreach ($data as $id => $p): ?><td title="<?= $pay[$id]['fixed'] ? 'مووچەی جێگیر' : '' ?>"><b><?= hr_money($pay[$id]['base']) ?></b></td><?php endforeach; ?></tr>
                <tr class="tot pct"><td class="dcol">ڕێژە (%)</td><?php foreach ($data as $id => $p): ?><td class="<?= $pay[$id]['pct'] > 0 ? 'has' : '' ?>"><?= number_format($pay[$id]['pct'], 2) ?>%</td><?php endforeach; ?></tr>
                <tr class="tot money"><td class="dcol">کۆی گشتی + ڕێژە (IQD)</td><?php foreach ($data as $id => $p): ?><td><b><?= hr_money($pay[$id]['total']) ?></b></td><?php endforeach; ?></tr>
                <tr class="tot final"><td class="dcol">دوای ڕێکخستنی دینار (IQD)</td><?php foreach ($data as $id => $p): ?><td><b><?= hr_money($pay[$id]['rounded']) ?></b></td><?php endforeach; ?></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="hrf-legend">
        ژمارەکانی ناو خانەکان کاتژمێری پەنجەمۆرن. <span class="hrf-dot"></span> پەنجەمۆری بێ جووت ·
        <span class="hrf-dot" style="background:#0ea5e9"></span> زیادە یان لێبڕینی هەیە. کرتە لەسەر خانەیەک بکە بۆ بینین و چاککردنی پەنجەمۆرەکانی ئەو ڕۆژە.
        <?php if ($canPay): ?>مووچە بۆ سەرەوە دەکرێتە نزیکترین <?= number_format(HrAttendance::ROUND_TO) ?> دینار.<?php endif; ?>
    </div>

    <?php if ($canPay): ?>
    <div class="hr-sec">پوختەی مووچە — <?= $e(hr_month_label($month)) ?></div>
    <div class="hr-table-wrap">
        <table class="hr-table hr-sumtable">
            <thead>
                <tr>
                    <th>#</th><th>کارمەند</th><th class="num">کاتژمێر</th><th class="num">کرێی کاتژمێر</th>
                    <th class="num">کۆ</th><th class="num">ڕێژە</th><th class="num">کۆ + ڕێژە</th><th class="num">دوای ڕێکخستنی دینار</th>
                </tr>
            </thead>
            <tbody>
            <?php $n = 0; $sum = ['base' => 0, 'total' => 0, 'rounded' => 0, 'hours' => 0];
            foreach ($data as $id => $p): $pp = $pay[$id];
                $sum['base'] += $pp['base']; $sum['total'] += $pp['total']; $sum['rounded'] += $pp['rounded']; $sum['hours'] += $p['totals']['net']; ?>
                <tr>
                    <td class="num"><?= ++$n ?></td>
                    <td><?= $e($p['employee']['full_name']) ?> <span class="hr-badge <?= $p['employee']['employee_type'] ?>"><?= $p['employee']['employee_type'] === 'doctor' ? 'دکتۆر' : 'کارمەند' ?></span></td>
                    <td class="num"><?= $e(HrAttendance::hm($p['totals']['net'], false)) ?></td>
                    <td class="num"><?= $pp['fixed'] ? '<small class="hr-muted">مووچەی جێگیر</small>' : hr_money($pp['rate']) ?></td>
                    <td class="num"><?= hr_money($pp['base']) ?></td>
                    <td class="num"><?= $pp['pct'] > 0 ? $e(rtrim(rtrim(number_format($pp['pct'], 2), '0'), '.')) . '%' : '' ?></td>
                    <td class="num"><?= hr_money($pp['total']) ?></td>
                    <td class="num"><b><?= hr_money($pp['rounded']) ?></b></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td></td><td>کۆی گشتی</td>
                    <td class="num"><?= $e(HrAttendance::hm($sum['hours'], false)) ?></td><td></td>
                    <td class="num"><?= hr_money($sum['base']) ?></td><td></td>
                    <td class="num"><?= hr_money($sum['total']) ?></td>
                    <td class="num"><?= hr_money($sum['rounded']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php hr_day_editor(); ?>
<?php if ($canArrange): ?>
<script>
// ── Arranging the columns, right on the sheet ──
// Drag a name to move its column, the eye to hide it; «ستوونەکان» lists
// everyone (hidden ones too) to reorder or show again. Each change is saved
// at once (hr/api/columns.php) and then only the sheet is redrawn from the
// server (HrDay.refresh swaps [data-hr-refresh]) so totals follow. Listeners
// sit on the document, so they keep working after a redraw.
(function () {
    var API = <?= json_encode(APP_URL . '/hr/api/columns.php') ?>;
    var toastTimer = null;

    function save(params) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { if (k !== 'undo') fd.append(k, params[k]); });
        var m = document.querySelector('meta[name="csrf-token"]');
        return fetch(API, {method: 'POST', body: fd, credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': m ? m.content : ''}})
            .then(function (r) { return r.json(); })
            .catch(function () { return {success: false, message: 'کێشەی پەیوەندی'}; })
            .then(function (j) {
                toast(j.message || (j.success ? 'پاشەکەوت کرا' : 'هەڵە'), !j.success, params.undo);
                if (window.HrDay) HrDay.refresh();
                return j;
            });
    }
    function toast(text, isError, undo) {
        var t = document.getElementById('hrfToast');
        t.className = 'hrf-toast show' + (isError ? ' err' : '');
        t.innerHTML = '';
        var s = document.createElement('span');
        s.textContent = text;
        t.appendChild(s);
        if (undo && !isError) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = 'گەڕاندنەوە';
            b.onclick = function () { save(undo); };
            t.appendChild(b);
        }
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.className = 'hrf-toast'; }, undo ? 7000 : 3000);
    }

    function sheet() { return document.querySelector('.hr-grid'); }
    function headerIds() {
        return Array.prototype.map.call(document.querySelectorAll('.hr-grid thead th.emp'), function (th) { return th.dataset.emp; });
    }
    // Move column `from` to just before column `before` (null: to the end) in every row.
    function moveColumn(from, before) {
        Array.prototype.forEach.call(sheet().rows, function (row) {
            var node = row.cells[from], ref = before === null ? null : row.cells[before];
            if (node && node !== ref) row.insertBefore(node, ref);
        });
    }
    function removeColumn(index) {
        Array.prototype.forEach.call(sheet().rows, function (row) { if (row.cells[index]) row.cells[index].remove(); });
    }

    // ── Dragging a column by its name ──
    var dragTh = null, dragLi = null;
    function clearMarks() {
        document.querySelectorAll('.drop-l,.drop-r,.drop-t,.drop-b').forEach(function (n) { n.classList.remove('drop-l', 'drop-r', 'drop-t', 'drop-b'); });
    }
    document.addEventListener('dragstart', function (e) {
        var th = e.target.closest && e.target.closest('th.emp[draggable="true"]');
        var li = e.target.closest && e.target.closest('#hrfColsList li');
        if (!th && !li) return;
        dragTh = th; dragLi = li;
        (th || li).classList.add('drag');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', (th || li).dataset.emp);
    });
    document.addEventListener('dragover', function (e) {
        if (dragTh) {
            var th = e.target.closest('th.emp');
            if (!th || th === dragTh) return;
            e.preventDefault();
            clearMarks();
            var r = th.getBoundingClientRect();
            th.classList.add(e.clientX > r.left + r.width / 2 ? 'drop-r' : 'drop-l');
        } else if (dragLi) {
            var li = e.target.closest('#hrfColsList li');
            if (!li || li === dragLi) return;
            e.preventDefault();
            clearMarks();
            var q = li.getBoundingClientRect();
            li.classList.add(e.clientY < q.top + q.height / 2 ? 'drop-t' : 'drop-b');
        }
    });
    document.addEventListener('drop', function (e) {
        if (dragTh) {
            var th = e.target.closest('th.emp');
            if (!th || th === dragTh) return;
            e.preventDefault();
            // In this right-to-left sheet the right half of a name means "before it".
            var r = th.getBoundingClientRect(), before = e.clientX > r.left + r.width / 2;
            var from = dragTh.cellIndex, to = th.cellIndex;
            moveColumn(from, before ? to : (to + 1 < th.parentNode.cells.length ? to + 1 : null));
            save({action: 'order', ids: headerIds().join(',')});
        } else if (dragLi) {
            var li = e.target.closest('#hrfColsList li');
            if (!li || li === dragLi) return;
            e.preventDefault();
            var q = li.getBoundingClientRect();
            li.parentNode.insertBefore(dragLi, e.clientY < q.top + q.height / 2 ? li : li.nextSibling);
            var ids = Array.prototype.map.call(document.querySelectorAll('#hrfColsList li'), function (n) { return n.dataset.emp; });
            save({action: 'order', ids: ids.join(',')});
        }
    });
    document.addEventListener('dragend', function () {
        if (dragTh) dragTh.classList.remove('drag');
        if (dragLi) dragLi.classList.remove('drag');
        dragTh = dragLi = null;
        clearMarks();
    });

    // ── Hiding and showing ──
    document.addEventListener('click', function (e) {
        var hide = e.target.closest('.hrf-hide');
        if (hide) {
            e.preventDefault();
            e.stopPropagation();
            var th = hide.closest('th');
            var id = hide.dataset.hide;
            removeColumn(th.cellIndex);
            save({action: 'hide', id: id, hidden: 1, undo: {action: 'hide', id: id, hidden: 0}});
        }
    }, true);
    document.addEventListener('change', function (e) {
        var cb = e.target.closest('#hrfColsList input[data-show]');
        if (cb) save({action: 'hide', id: cb.dataset.show, hidden: cb.checked ? 0 : 1});
    });

    var panel = document.getElementById('hrfCols');
    document.getElementById('hrfColsBtn').addEventListener('click', function () { panel.hidden = !panel.hidden; });
    document.getElementById('hrfColsClose').addEventListener('click', function () { panel.hidden = true; });
    document.getElementById('hrfShowAll').addEventListener('click', function () { save({action: 'show_all'}); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; e.preventDefault(); }
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    // Where «گەڕانەوە» goes: the page this sheet was opened from. Kept for the
    // tab, because changing the month reloads this page and would otherwise
    // make the sheet its own referrer.
    var KEY = 'hrMonthlyReturn';
    var back = document.getElementById('hrfBack');
    function isSheet(url) {
        try {
            var u = new URL(url);
            var r = u.searchParams.get('r');
            return /\/hr\/reports\.php$/.test(u.pathname) && (!r || r === 'monthly');
        } catch (e) { return true; }
    }
    var ref = document.referrer, target = null;
    if (ref && ref.indexOf(location.origin + '/') === 0 && !isSheet(ref)) {
        target = ref;
        try { sessionStorage.setItem(KEY, ref); } catch (e) {}
    } else {
        try { target = sessionStorage.getItem(KEY); } catch (e) {}
    }
    if (target) { back.href = target; }
    document.addEventListener('keydown', function (e) {
        // The day editor closes itself on Esc and marks the key as used.
        if (e.key === 'Escape' && !e.defaultPrevented && !document.fullscreenElement) { location.href = back.href; }
    });

    var full = document.getElementById('hrfFull');
    if (!document.documentElement.requestFullscreen) {
        full.style.display = 'none';
    }
    full.addEventListener('click', function () {
        if (document.fullscreenElement) { document.exitFullscreen(); } else { document.documentElement.requestFullscreen(); }
    });
    document.addEventListener('fullscreenchange', function () {
        full.querySelector('i').className = 'fas ' + (document.fullscreenElement ? 'fa-compress' : 'fa-expand');
    });
})();
</script>
</body>
</html>
