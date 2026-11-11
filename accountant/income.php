<?php
/**
 * داهات — the daily income sheet, laid out like the cashier's paper
 * تۆماری ڕۆژی. All figures come from _income_data.php (shared with the PDF).
 */

require_once __DIR__ . '/_income_data.php';

$pageTitle = 'داهات';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/approval-center.php'],
    ['title' => 'داهات'],
];

require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/clinic_branding.php';

$pdfUrl = APP_URL . '/accountant/income-pdf.php' . $rangeQs($from, $to);

/** A money cell: blank when nothing, like the paper; brackets when negative. */
function incCell(float $v): string
{
    if (abs($v) < 0.5) {
        return '';
    }
    return $v < 0 ? '(' . dsMoney(abs($v)) . ')' : dsMoney($v);
}

/** Like incCell but 0 stays visible — for the «سیستەم … + دەستی …» line. */
function incSigned(float $v): string
{
    return $v < 0 ? '(' . dsMoney(abs($v)) . ')' : dsMoney($v);
}

// داهاتی دەستی form: the sheet's own day, else today if it is in the range.
$manDate = $from === $to ? $from : (($today >= $from && $today <= $to) ? $today : $to);
$manDate = min($manDate, $today);
$manReturn = $rangeQs($from, $to);
?>

<style>
.inc{max-width:1100px;margin:0 auto 32px;color:#0f172a;direction:rtl}
.inc-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;background:#fff;border-radius:14px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.05),0 6px 18px -10px rgba(15,23,42,.2)}
.inc-bar label{display:flex;align-items:center;gap:6px;font-size:12.5px;color:#475569;white-space:nowrap}
.inc-bar input{height:36px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc;padding:0 10px;font:inherit;font-size:13px}
.inc-bar button{height:36px;border:0;border-radius:9px;padding:0 16px;font:inherit;font-size:13px;font-weight:600;color:#fff;cursor:pointer;
    background:linear-gradient(135deg,#0369a1,#0284c7)}
.inc-pdf{height:36px;display:inline-flex;align-items:center;gap:6px;padding:0 14px;border-radius:9px;font-size:13px;font-weight:600;
    text-decoration:none;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca}
.inc-exp{display:flex;align-items:center;gap:5px;font-size:12.5px;color:#475569}
.inc-chip{display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border-radius:999px;cursor:pointer;user-select:none;
    font-size:12.5px;font-weight:600;color:#9a3412;background:#fff;border:1px dashed #fdba74;transition:background .15s}
.inc-chip input{display:none}
.inc-chip i{font-size:10px;opacity:0}
.inc-chip:has(input:checked){background:#ffedd5;border-style:solid;border-color:#fb923c}
.inc-chip:has(input:checked) i{opacity:1}
.inc-days{display:flex;flex-wrap:wrap;gap:4px;margin-inline-start:auto}
.inc-days a{padding:6px 11px;border-radius:999px;font-size:12px;text-decoration:none;color:#0369a1;background:#f0f9ff;border:1px solid #e0f2fe}
.inc-days a.on{background:#0369a1;border-color:#0369a1;color:#fff}

/* the sheet */
.inc-lock{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:10px 14px;border-radius:12px;background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;font-size:13px}
.inc-lock > i{font-size:18px}
.inc-lock span{flex:1;min-width:220px}
.inc-lock a{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:9px;background:#fff;border:1px solid currentColor;color:inherit;font-weight:700;text-decoration:none}
.inc-lock.open{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.inc-sheet{margin-top:14px;background:#fff;border-radius:16px;padding:22px 24px;
    box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22)}
.inc-head{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:16px;padding-bottom:12px;border-bottom:2px solid #0284c7}
.inc-head .ku{font-size:19px;font-weight:800;color:#0369a1}
.inc-head .addr{font-size:12px;color:#64748b;margin-top:3px}
.inc-head img{width:66px;height:auto;display:block}
.inc-head .en{text-align:left;direction:ltr}
.inc-head .en b{font-size:18px;color:#0369a1;letter-spacing:.5px}
.inc-head .en div{font-size:12px;color:#64748b;margin-top:3px}
.inc-title{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:14px 0 10px}
.inc-title h2{margin:0;font-size:16px;color:#0f172a}
.inc-title .date{padding:5px 14px;border-radius:8px;background:#e0f2fe;color:#075985;font-weight:700;font-size:13px}

.inc-scroll{overflow-x:auto}
/* «پڕ بە شاشە»: the sheet over the whole screen, every column in view */
.inc-full-btn{margin-inline-start:auto;height:32px;padding:0 12px;border-radius:8px;border:1px solid #bae6fd;background:#f0f9ff;color:#0369a1;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.inc-full-btn:hover{background:#0284c7;border-color:#0284c7;color:#fff}
.inc-sheet.is-full{position:fixed;inset:0;z-index:990;margin:0;border-radius:0;padding:12px 16px;overflow:auto;box-shadow:none}
.inc-sheet.is-full .inc-head{display:none}
.inc-sheet.is-full .inc-title{margin-top:0}
.inc-sheet.is-full .inc-scroll{overflow:visible}
.inc-sheet.is-full .inc-tbl{min-width:0}
.inc-sheet.is-full .inc-tbl th{padding:7px 6px;font-size:12px}
.inc-sheet.is-full .inc-tbl td{padding:6px;font-size:12.5px}
body.inc-full-open{overflow:hidden}
.inc-tbl{width:100%;border-collapse:collapse;min-width:820px}
.inc-tbl th{background:#cfe6f3;color:#0c4a6e;font-size:12.5px;font-weight:700;padding:9px 10px;border:1px solid #9cc5dc;white-space:nowrap}
.inc-tbl td{padding:8px 10px;border:1px solid #d5e3ec;font-size:13px;height:20px}
.inc-tbl td.i{width:34px;text-align:center;color:#64748b}
.inc-tbl td.n{text-align:left;direction:ltr;white-space:nowrap;font-variant-numeric:tabular-nums}
.inc-tbl td.c{text-align:center;width:64px;font-variant-numeric:tabular-nums}
.inc-tbl td.lb a{color:inherit;text-decoration:none}
.inc-tbl td.lb a:hover{color:#0369a1;text-decoration:underline}
.inc-tbl td.lb.en{direction:ltr;text-align:right;font-weight:600;color:#334155}
.inc-tbl tr.grp td{border-top:2px solid #9cc5dc}
.inc-tbl td.amt{font-weight:700}
.inc-tbl td.exp{color:#c2410c}
.inc-tbl td.net{font-weight:700;color:#075985}
.inc-tbl td.neg{color:#dc2626}
.inc-tbl tr.tot td{background:#f0f9ff;font-weight:800;border-top:2px solid #0284c7}
/* the split of کۆی گشتی: the doctor's share | the centre's share */
.inc-tbl th.doc{background:#ede9fe;color:#5b21b6;border-color:#c4b5fd}
.inc-tbl th.ctr{background:#ccfbf1;color:#115e59;border-color:#5eead4}
.inc-tbl th small{display:block;font-size:10.5px;font-weight:600;opacity:.75}
/* خەرجی کۆگا the owner's way: کۆ · ئاسایی (دکتۆر · لێزان) · Implant (دکتۆر · لێزان) */
.inc-tbl th.sto{background:#fde68a;color:#78350f;border-color:#f59e0b}
.inc-tbl tr.sub th{font-size:11px;padding:5px 6px}
.inc-tbl th.nrm{background:#ccfbf1;color:#0f766e;border-color:#5eead4}
.inc-tbl th.imp{background:#ede9fe;color:#6d28d9;border-color:#c4b5fd}
.inc-tbl td.nrm{color:#0f766e;background:#f7fefc;font-size:12px}
.inc-tbl td.imp{color:#6d28d9;background:#fbfaff;font-size:12px}
.inc-tbl td.doc{color:#5b21b6;background:#faf8ff}
.inc-tbl td.ctr{color:#0f766e;font-weight:700;background:#f4fdfb}
.inc-tbl td.ctr.neg{color:#dc2626}
.inc-tbl tr.tot td.doc{background:#ede9fe}
.inc-tbl tr.tot td.ctr{background:#ccfbf1}
.inc-sh{display:flex;align-items:center;justify-content:space-between;gap:6px}
.inc-rt{flex:0 0 auto;padding:1px 6px;border-radius:999px;font-size:10.5px;font-weight:700;color:#6d28d9;background:#ede9fe}
.inc-rt.none{color:#b45309;background:#fef3c7;cursor:help}
.inc-note{display:flex;align-items:flex-start;gap:8px;margin:0 0 10px;padding:9px 12px;border-radius:10px;
    background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12.5px;line-height:1.7}
.inc-note a{font-weight:700;color:#b45309;white-space:nowrap}

.inc-foot{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:16px}
.inc-box{border:1px solid #d5e3ec;border-radius:12px;padding:10px 14px;background:#f8fafc}
.inc-box .k{font-size:12px;color:#64748b}
.inc-box .v{font-size:20px;font-weight:800;direction:ltr;text-align:right;font-variant-numeric:tabular-nums;margin-top:2px}
.inc-box .s{font-size:11px;color:#94a3b8;margin-top:3px}
.inc-box.total .v{color:#0369a1}
.inc-box.exp .v{color:#c2410c}
.inc-box.left{background:#ecfdf5;border-color:#bbf7d0}
.inc-box.left .v{color:#047857}
.inc-box.left.neg{background:#fef2f2;border-color:#fecaca}
.inc-box.left.neg .v{color:#dc2626}
.inc-split{margin-top:10px}
.inc-box.doc{background:#faf8ff;border-color:#ddd6fe}
.inc-box.doc .v{color:#5b21b6}
.inc-box.ctr{background:#f0fdfa;border-color:#99f6e4}
.inc-box.ctr .v{color:#0f766e}
.inc-box.cnet{background:#ecfdf5;border-color:#6ee7b7}
.inc-box.cnet .v{color:#047857}
.inc-box.cnet.neg{background:#fef2f2;border-color:#fecaca}
.inc-box.cnet.neg .v{color:#dc2626}
@media(max-width:760px){.inc-foot{grid-template-columns:1fr}.inc-head{grid-template-columns:1fr}.inc-head .en{text-align:right}.inc-days{margin-inline-start:0}}
</style>

<div class="inc">

    <form method="get" class="inc-bar">
        <label>لە <input type="date" name="from" value="<?= dsEsc($from) ?>"></label>
        <label>بۆ <input type="date" name="to" value="<?= dsEsc($to) ?>"></label>
        <button type="submit">پیشاندان</button>
        <a class="inc-pdf" href="<?= dsEsc($pdfUrl) ?>" target="_blank"><i class="fas fa-file-pdf"></i> ڕاپۆرتی PDF</a>
        <!-- Expenses on/off. Ticking applies at once; the choice is remembered,
             and only ticked kinds are subtracted. Lab/store are table columns,
             and so is general in a doctor's name; the centre's general only
             counts in the bottom خەرجیات. -->
        <div class="inc-exp">
            <span>خەرجی:</span>
            <input type="hidden" name="xs" value="1">
            <?php foreach (['lab' => 'تاقیگە', 'store' => 'کۆگا', 'general' => 'گشتی'] as $k => $short): ?>
            <label class="inc-chip" title="<?= $k === 'general' ? 'خەرجی سەنتەر لە خەرجیاتی خوارەوە هەژمار دەکرێت؛ ئەوەی ناوی دکتۆری لەسەرە لە ڕیزی ئەو دکتۆرە دەبڕدرێت' : 'کۆڵۆمی ' . $short . ' لە خشتەکە' ?>">
                <input type="checkbox" name="x[]" value="<?= $k ?>" <?= in_array($k, $expSel, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                <i class="fas fa-check"></i> <?= $short ?>
            </label>
            <?php endforeach; ?>
            <?php if ($canColumns): ?>
            <button type="button" class="inc-newcol" data-col-new title="کۆڵۆمێکی نوێ بۆ خشتەکە: خەرجی سەر دکتۆر یان لێبڕین"><i class="fas fa-plus"></i> کۆڵۆم</button>
            <?php endif; ?>
        </div>
        <div class="inc-days">
            <a href="<?= dsEsc($rangeQs($prevFrom, $prevTo)) ?>">← پێشوو</a>
            <?php foreach ($rangePresets as [$pLabel, $pFrom, $pTo]): ?>
            <a href="<?= dsEsc($rangeQs($pFrom, $pTo)) ?>" class="<?= $pFrom === $from && $pTo === $to ? 'on' : '' ?>"><?= dsEsc($pLabel) ?></a>
            <?php endforeach; ?>
            <a href="<?= dsEsc($rangeQs($nextFrom, $nextTo)) ?>">داهاتوو →</a>
        </div>
    </form>

    <?php
    // داخستنی مانگ: the months of this period that stand closed, and — for one
    // whole finished month still open — the way to close it.
    require_once __DIR__ . '/../classes/PeriodClose.php';
    $incClosed = PeriodClose::closedIn($from, $to);
    $incWholeMonth = substr($from, 8) === '01' && $to === date('Y-m-t', strtotime($from)) ? substr($from, 0, 7) : null;
    ?>
    <?php foreach ($incClosed as $cm): ?>
    <div class="inc-lock"><i class="fas fa-lock"></i>
        <span><b>مانگی <bdi><?= dsEsc($cm) ?></bdi> داخراوە</b> — هیچ پارە یان خەرجییەک بە ڕێکەوتی ئەو مانگە زیاد، دەستکاری یان سڕینەوە ناکرێت.</span>
        <a href="<?= APP_URL ?>/accountant/month-close.php?month=<?= dsEsc($cm) ?>"><i class="fas fa-file-lines"></i> کۆپیی داخستن</a>
    </div>
    <?php endforeach; ?>
    <?php if ($incWholeMonth && !$incClosed && PeriodClose::isOver($incWholeMonth) && Permission::getInstance()->can(PeriodClose::PERMISSION)): ?>
    <div class="inc-lock open"><i class="fas fa-lock-open"></i>
        <span>مانگی <bdi><?= dsEsc($incWholeMonth) ?></bdi> کراوەیە. کە حیساباتەکەت تەواو کرد، دایبخە تا هیچ شتێکی تێدا نەگۆڕدرێت.</span>
        <a href="<?= APP_URL ?>/accountant/month-close.php?month=<?= dsEsc($incWholeMonth) ?>"><i class="fas fa-lock"></i> داخستنی مانگ</a>
    </div>
    <?php endif; ?>

    <div class="inc-sheet">
        <div class="inc-head">
            <div>
                <div class="ku"><?= dsEsc($clinicName) ?></div>
                <div class="addr"><?= dsEsc($clinicAddress) ?></div>
            </div>
            <img src="<?= dsEsc($logoUrl) ?>" alt="" onerror="this.style.display='none'">
            <div class="en">
                <b><?= dsEsc($clinicNameEn) ?></b>
                <div><?= dsEsc(implode(' · ', $clinicPhones)) ?></div>
            </div>
        </div>

        <div class="inc-title">
            <h2><i class="fas fa-cash-register" style="color:#0284c7;"></i> تۆماری ڕۆژی — داهات</h2>
            <span class="date"><bdi><?= dsEsc($rangeLabel) ?></bdi></span>
            <button type="button" class="inc-full-btn" id="incFullBtn" title="هەموو ستوونەکان لەسەر یەک شاشە"><i class="fas fa-expand"></i> <span>پڕ بە شاشە</span></button>
        </div>

        <?php if ($doctorsWithoutRate): ?>
        <div class="inc-note">
            <i class="fas fa-percent" style="margin-top:4px;"></i>
            <div>
                ڕێژەی ئەم <?= count($doctorsWithoutRate) === 1 ? 'دکتۆرە' : 'دکتۆرانە' ?> دیاری نەکراوە، بۆیە هەموو پارەکەیان لە پشکی سەنتەردا هەژمار کراوە:
                <b><?= dsEsc(implode('، ', $doctorsWithoutRate)) ?></b>.
                <?php if ($canSetRates): ?>
                <a href="<?= APP_URL ?>/admin/users.php">دیاریکردنی ڕێژە ←</a>
                <?php else: ?>
                بەڕێوەبەر لە ئەکاونتی دکتۆرەکە دیاری دەکات.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="inc-scroll">
            <table class="inc-tbl">
                <thead>
                    <?php
                    // The store as on the owner's sheet: its total, then ئاسایی
                    // (دکتۆر by his store percentage · لێزان) and Implant (its
                    // division for information: the whole of it comes off before the split).
                    $storeCols = in_array('store', $colSel, true);
                    $rs = $storeCols ? ' rowspan="2"' : '';
                    ?>
                    <tr>
                        <th<?= $rs ?>>#</th><th<?= $rs ?>>ناوەڕۆک</th><th<?= $rs ?>>ژمارە</th><th<?= $rs ?>>بڕی</th>
                        <?php foreach ($colSel as $k): ?><?php if ($k === 'store'): ?><th colspan="7" class="sto">خەرجی کۆگا</th><?php else: ?><th<?= $rs ?>><?= dsEsc($expenseKinds[$k]) ?></th><?php endif; ?><?php endforeach; ?>
                        <?php foreach ($incColumns as $c): ?><th class="cus"<?= $rs ?>><?= dsEsc($c['name']) ?></th><?php endforeach; ?>
                        <th<?= $rs ?>>کۆی ماوە دوای خەرجی<small>بڕی − گشتی − تاقیگە − Implant</small></th>
                        <th class="doc"<?= $rs ?>>پشکی دکتۆر<small>بە ڕێژەی خۆی − کۆگای ئاسایی</small></th>
                        <th class="ctr"<?= $rs ?>>پشکی سەنتەر<small>بۆ <?= htmlspecialchars(Branding::shortName()) ?></small></th>
                    </tr>
                    <?php if ($storeCols): ?>
                    <tr class="sub">
                        <th>کۆ</th>
                        <th class="nrm">ئاسایی</th><th class="nrm">سەر دکتۆر</th><th class="nrm">سەر لێزان</th>
                        <th class="imp">Implant</th><th class="imp">سەر دکتۆر</th><th class="imp">لێزان</th>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                <?php
                $n = 0;
                $detailQs = '&from=' . urlencode($from) . '&to=' . urlencode($to);
                foreach ($rows as $i => $r):
                    $n++;
                    // a heavier line where the doctors end and the departments begin
                    $grp = $i === count($doctorRows) ? ' class="grp"' : '';
                ?>
                    <tr<?= $grp ?>>
                        <td class="i"><?= $n ?></td>
                        <?php if ($r['kind'] === 'doctor'): ?>
                        <td class="lb"><?php if ($canDoctorDetail): ?>
                            <a href="<?= APP_URL ?>/doctors/daily-summary.php?doctor=<?= (int)$r['id'] . $detailQs ?>" title="پوختەی ڕۆژانەی دکتۆر"><?= dsEsc($r['label']) ?></a>
                            <?php else: ?><?= dsEsc($r['label']) ?><?php endif; ?></td>
                        <?php elseif (isset($r['manualAmount'])): ?>
                        <td class="lb en">
                            <div class="inc-dept" dir="rtl">
                                <span dir="ltr"><?= dsEsc($r['label']) ?></span>
                                <?php if ($canManual): ?>
                                <button type="button" class="inc-add" data-kind="<?= dsEsc($r['kind']) ?>" title="زیادکردنی داهاتی دەستی بۆ <?= dsEsc(IncomeManualEntry::KINDS[$r['kind']]) ?>"><i class="fas fa-plus"></i></button>
                                <?php endif; ?>
                            </div>
                            <?php if (abs($r['manualAmount']) >= 0.5): ?>
                            <div class="inc-man" dir="rtl">
                                سیستەم <bdi><?= incSigned($r['sysAmount']) ?></bdi> + <a href="#incManual">دەستی <bdi><?= dsMoney($r['manualAmount']) ?></bdi></a>
                            </div>
                            <?php endif; ?>
                        </td>
                        <?php else: ?>
                        <td class="lb en"><?= dsEsc($r['label']) ?></td>
                        <?php endif; ?>
                        <td class="c"><?= $r['count'] ? dsMoney($r['count']) : '' ?></td>
                        <?php if (abs($r['amount']) >= 0.5 && in_array($r['kind'], INCOME_DETAIL_KINDS, true)): ?>
                        <td class="n amt"><div class="xi-amt"><?= income_detail_button($r['kind'], (int)($r['id'] ?? 0)) ?><span><?= incCell($r['amount']) ?></span></div></td>
                        <?php else: ?>
                        <td class="n amt"><?= incCell($r['amount']) ?></td>
                        <?php endif; ?>
                        <?php foreach ($colSel as $k): ?>
                        <?php if ($r['kind'] === 'doctor' && abs($r[$k]) >= 0.5): ?>
                        <td class="n exp"><div class="xi-amt"><?= income_detail_button($k, (int)$r['id']) ?><span><?= incCell($r[$k]) ?></span></div></td>
                        <?php else: ?>
                        <td class="n exp"><?= incCell($r[$k]) ?></td>
                        <?php endif; ?>
                        <?php if ($k === 'store'): ?>
                        <td class="n nrm"><?= incCell($r['storeNormal']) ?></td><td class="n nrm"><?= incCell($r['storeNormalDoc']) ?></td><td class="n nrm"><?= incCell($r['storeNormalCtr']) ?></td>
                        <td class="n imp"><?= incCell($r['storeImplant']) ?></td><td class="n imp"><?= incCell($r['storeImplantDoc']) ?></td><td class="n imp"><?= incCell($r['storeImplantCtr']) ?></td>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php foreach ($incColumns as $c): ?>
                        <td class="n cus">
                            <?php if ($r['kind'] === 'doctor' || isset(IncomeColumn::ROWS[$r['kind']])): ?>
                            <div class="inc-cc">
                                <?php if ($canColumns && $c['is_active']): ?>
                                <button type="button" class="inc-cadd" data-col="<?= (int)$c['id'] ?>" data-doctor="<?= $r['kind'] === 'doctor' ? (int)$r['id'] : dsEsc($r['kind']) ?>" title="زیادکردن لەسەر <?= dsEsc($r['label']) ?>"><i class="fas fa-plus"></i></button>
                                <?php endif; ?>
                                <span><?= incCell($r['cols'][$c['id']] ?? 0.0) ?></span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="n net<?= $r['pool'] < 0 ? ' neg' : '' ?>"><?= incCell($r['pool']) ?></td>
                        <td class="n doc">
                            <?php if ($r['kind'] === 'doctor'): ?>
                            <div class="inc-sh">
                                <span><?= incCell($r['doctorShare']) ?></span>
                                <?php if ($r['rate'] !== null): ?>
                                <span class="inc-rt"><?= rtrim(rtrim(number_format($r['rate'], 2, '.', ''), '0'), '.') ?>%</span>
                                <?php else: ?>
                                <span class="inc-rt none" title="ڕێژەی دکتۆر دیاری نەکراوە">؟</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="n ctr<?= $r['centerShare'] < 0 ? ' neg' : '' ?>"><?= incCell($r['centerShare']) ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="tot">
                        <td></td>
                        <td>کۆ</td>
                        <td class="c"><?= dsMoney($totals['count']) ?></td>
                        <td class="n"><?= dsMoney($totals['amount']) ?></td>
                        <?php foreach ($colSel as $k): ?>
                        <td class="n"><?= dsMoney($totals[$k]) ?></td>
                        <?php if ($k === 'store'): ?>
                        <td class="n nrm"><?= dsMoney($totals['storeNormal']) ?></td><td class="n nrm"><?= dsMoney($totals['storeNormalDoc']) ?></td><td class="n nrm"><?= dsMoney($totals['storeNormalCtr']) ?></td>
                        <td class="n imp"><?= dsMoney($totals['storeImplant']) ?></td><td class="n imp"><?= dsMoney($totals['storeImplantDoc']) ?></td><td class="n imp"><?= dsMoney($totals['storeImplantCtr']) ?></td>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php foreach ($incColumns as $c): ?><td class="n cus"><?= dsMoney($totals['cols'][$c['id']]) ?></td><?php endforeach; ?>
                        <td class="n"><?= $totals['pool'] < 0 ? '(' . dsMoney(abs($totals['pool'])) . ')' : dsMoney($totals['pool']) ?></td>
                        <td class="n doc"><?= incCell($totals['doctorShare']) ?: '0' ?></td>
                        <td class="n ctr"><?= incCell($totals['centerShare']) ?: '0' ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="inc-foot">
            <div class="inc-box total">
                <div class="k">کۆی گشتی</div>
                <div class="v"><?= $sheetTotal < 0 ? '(' . dsMoney(abs($sheetTotal)) . ')' : dsMoney($sheetTotal) ?></div>
                <?php $incLess = array_merge(array_map(fn($k) => $expenseKinds[$k], $colSel), array_column(array_filter($incColumns, fn($c) => $c['period_total'] > 0), 'name')); ?>
                <?php if ($incLess): ?>
                <div class="s">داهات <?= dsMoney($totals['amount']) ?> − <?= dsEsc(implode('، ', $incLess)) ?></div>
                <?php endif; ?>
            </div>
            <div class="inc-box exp">
                <div class="k">خەرجیات</div>
                <div class="v"><?= dsMoney($expensesAll) ?></div>
                <div class="s"><?php
                    // each line, and what stands behind the centre's general expenses
                    $expDetail = ['گشتی سەنتەر (بێ دکتۆر)' => ['general', 0], 'تاقیگەی بێ دکتۆر' => ['lab', 0], 'کۆگای بێ دکتۆر' => ['store', 0]];
                    echo $expenseLines
                        ? implode(' · ', array_map(fn($l, $v) => '<span class="inc-xl">' . dsEsc($l) . ' ' . dsMoney($v)
                            . (isset($expDetail[$l]) && abs($v) >= 0.5 ? ' ' . income_detail_button($expDetail[$l][0], $expDetail[$l][1]) : '') . '</span>',
                            array_keys($expenseLines), $expenseLines))
                        : (in_array('general', $expSel, true) ? 'هیچ خەرجییەکی گشتی نییە' : 'خەرجی گشتی کوژاوەتەوە');
                ?></div>
            </div>
            <div class="inc-box left<?= $remaining < 0 ? ' neg' : '' ?>">
                <div class="k">ماوە</div>
                <div class="v"><?= $remaining < 0 ? '(' . dsMoney(abs($remaining)) . ')' : dsMoney($remaining) ?></div>
            </div>
        </div>

        <!-- دابەشکردن: کۆی گشتی = پشکی دکتۆران + پشکی سەنتەر; the centre then
             carries خەرجیات. -->
        <div class="inc-foot inc-split">
            <div class="inc-box doc">
                <div class="k">پشکی دکتۆران</div>
                <div class="v"><?= $totals['doctorShare'] < 0 ? '(' . dsMoney(abs($totals['doctorShare'])) . ')' : dsMoney($totals['doctorShare']) ?></div>
                <div class="s">ڕێژەی هەر دکتۆرێک لە کۆی ماوە دوای خەرجی − بەشی لە کۆگای ئاسایی</div>
            </div>
            <div class="inc-box ctr">
                <div class="k">پشکی سەنتەر</div>
                <div class="v"><?= $totals['centerShare'] < 0 ? '(' . dsMoney(abs($totals['centerShare'])) . ')' : dsMoney($totals['centerShare']) ?></div>
                <div class="s">ماوەی دکتۆران + بەشەکانی تر</div>
            </div>
            <div class="inc-box cnet<?= $centerAfterExpenses < 0 ? ' neg' : '' ?>">
                <div class="k">سافی سەنتەر</div>
                <div class="v"><?= $centerAfterExpenses < 0 ? '(' . dsMoney(abs($centerAfterExpenses)) . ')' : dsMoney($centerAfterExpenses) ?></div>
                <div class="s">پشکی سەنتەر − خەرجیات</div>
            </div>
        </div>
    </div>

    <?php if ($canManual || $manualList): ?>
    <!-- داهاتی دەستی: pharmacy / examination money the system did not record,
         added on top of those two rows (classes/IncomeManualEntry.php). -->
    <section class="inc-mp" id="incManual" data-fold>
        <div class="inc-mp-head">
            <h3><i class="fas fa-hand-holding-usd"></i> داهاتی دەستی — دەرمانخانە و پشکنین</h3>
            <span>ئەو پارەیەی سیستەمەکە تۆماری نەکردووە. دەخرێتە سەر ڕیزی <b dir="ltr">Pharmacy</b> و <b dir="ltr">Examinations</b>.</span>
            <b class="inc-fold-sum"><?= count($manualList) ?> تۆمار · <?= dsMoney(array_sum(array_map(fn($m) => (float)$m['amount'], $manualList))) ?></b>
            <button type="button" class="inc-fold-btn" aria-controls="incManual"></button>
        </div>

        <?php if (isset($_GET['man_added'])): ?>
        <div class="inc-mp-msg ok"><i class="fas fa-check-circle"></i> داهاتەکە زیادکرا.</div>
        <?php elseif (isset($_GET['man_updated'])): ?>
        <div class="inc-mp-msg ok"><i class="fas fa-check-circle"></i> داهاتەکە نوێکرایەوە.</div>
        <?php elseif (isset($_GET['man_deleted'])): ?>
        <div class="inc-mp-msg ok"><i class="fas fa-check-circle"></i> داهاتەکە سڕایەوە.</div>
        <?php elseif (isset($_GET['man_error'])): ?>
        <div class="inc-mp-msg err"><i class="fas fa-exclamation-circle"></i> <?= dsEsc((string)$_GET['man_error']) ?></div>
        <?php endif; ?>

        <?php if ($canManual): ?>
        <form method="post" action="<?= APP_URL ?>/accountant/income-manual.php" class="inc-mp-form" id="incManForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="entry_id" value="">
            <input type="hidden" name="return" value="<?= dsEsc($manReturn) ?>">
            <div class="inc-mp-kinds" role="radiogroup" aria-label="جۆر">
                <?php foreach (IncomeManualEntry::KINDS as $k => $label): ?>
                <label class="inc-mp-kind">
                    <input type="radio" name="kind" value="<?= $k ?>" <?= $k === 'pharmacy' ? 'checked' : '' ?>>
                    <i class="fas <?= $k === 'pharmacy' ? 'fa-pills' : 'fa-stethoscope' ?>"></i> <?= dsEsc($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <label>ڕێکەوت <input type="date" name="entry_date" value="<?= dsEsc($manDate) ?>" max="<?= dsEsc($today) ?>" required></label>
            <label>بڕ <input type="text" name="amount" inputmode="numeric" placeholder="0" required class="inc-mp-amt"></label>
            <label>ژمارە <input type="number" name="qty" min="0" step="1" placeholder="—" class="inc-mp-qty" title="چەند فرۆشتن / چەند نەخۆش (ئارەزوومەندانە)"></label>
            <label class="inc-mp-note">تێبینی <input type="text" name="note" maxlength="255" placeholder="ئارەزوومەندانە"></label>
            <button type="submit" class="inc-mp-save"><i class="fas fa-plus"></i> <span>زیادکردن</span></button>
            <button type="button" class="inc-mp-cancel" hidden>پاشگەزبوونەوە</button>
        </form>
        <?php endif; ?>

        <?php if ($manualList): ?>
        <div class="inc-scroll">
            <table class="inc-mp-tbl">
                <thead><tr><th>ڕێکەوت</th><th>جۆر</th><th>ژمارە</th><th>بڕ</th><th>تێبینی</th><th>تۆمارکەر</th><?php if ($canManual): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($manualList as $m): ?>
                    <tr>
                        <td><bdi><?= dsEsc($m['entry_date']) ?></bdi></td>
                        <td><span class="inc-mp-tag <?= dsEsc($m['kind']) ?>"><?= dsEsc(IncomeManualEntry::KINDS[$m['kind']] ?? $m['kind']) ?></span></td>
                        <td class="c"><?= (int)$m['qty'] ?: '' ?></td>
                        <td class="n"><?= dsMoney((float)$m['amount']) ?></td>
                        <td><?= dsEsc($m['note'] ?? '') ?></td>
                        <td class="who"><?= dsEsc($m['created_by_name'] ?? '—') ?><?php if ($m['updated_by_name']): ?><small>دەستکاری: <?= dsEsc($m['updated_by_name']) ?></small><?php endif; ?></td>
                        <?php if ($canManual): ?>
                        <td class="act">
                            <button type="button" class="inc-mp-edit" title="دەستکاری" data-entry="<?= dsEsc(json_encode([
                                'id' => (int)$m['id'], 'kind' => $m['kind'], 'entry_date' => $m['entry_date'],
                                'amount' => (float)$m['amount'], 'qty' => (int)$m['qty'], 'note' => (string)($m['note'] ?? ''),
                            ], JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i></button>
                            <form method="post" action="<?= APP_URL ?>/accountant/income-manual.php" onsubmit="return confirm('ئەم داهاتە دەستییە بسڕدرێتەوە؟')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entry_id" value="<?= (int)$m['id'] ?>">
                                <input type="hidden" name="return" value="<?= dsEsc($manReturn) ?>">
                                <button type="submit" class="del" title="سڕینەوە"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">کۆ</td>
                        <td class="c"><?= dsMoney(array_sum(array_column($manualTotals, 'qty'))) ?: '' ?></td>
                        <td class="n"><?= dsMoney(array_sum(array_column($manualTotals, 'amount'))) ?></td>
                        <td colspan="<?= $canManual ? 3 : 2 ?>" class="sub">
                            <?php foreach (IncomeManualEntry::KINDS as $k => $label): if ($manualTotals[$k]['amount'] > 0): ?>
                            <span><?= dsEsc($label) ?>: <bdi><?= dsMoney($manualTotals[$k]['amount']) ?></bdi></span>
                            <?php endif; endforeach; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="inc-mp-empty">هیچ داهاتێکی دەستی بۆ ئەم ماوەیە تۆمار نەکراوە.</div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canColumns || $incColumnEntries): ?>
    <!-- کۆڵۆمی تایبەت: columns of the accountant's own, each amount on a doctor
         (classes/IncomeColumn.php). «+» on a doctor's cell opens the same dialog. -->
    <section class="inc-mp inc-cs" id="incCols" data-fold>
        <div class="inc-mp-head">
            <h3><i class="fas fa-user-minus"></i> کۆڵۆمی تایبەت — لێبڕین و خەرجی سەر دکتۆر، X-ray و Pharmacy</h3>
            <span>بڕەکان لە ڕیزی دکتۆرەکە دەبڕدرێن. گەڕاندنەوەی پارە پسوولەی خۆی هەیە، لە فایلی نەخۆش دەردەکەوێت و لە قاسە کەم دەبێتەوە.</span>
            <b class="inc-fold-sum"><?= count($incColumns) ?> کۆڵۆم · <?= count(array_filter($incColumnEntries, fn($m) => $m['status'] === 'active')) ?> تۆمار</b>
            <button type="button" class="inc-fold-btn" aria-controls="incCols"></button>
        </div>

        <?php
        $colFlash = ['added' => 'تۆمارەکە زیادکرا.', 'updated' => 'تۆمارەکە نوێکرایەوە.', 'cancelled' => 'تۆمارەکە هەڵوەشێنرایەوە و پارەکە گەڕایەوە سندوقەکان.',
                     'col_saved' => 'کۆڵۆمەکە پاشەکەوت کرا.', 'col_removed' => 'کۆڵۆمەکە لابرا.'][(string)($_GET['col_done'] ?? '')] ?? '';
        ?>
        <?php if ($colFlash): ?>
        <div class="inc-mp-msg ok">
            <i class="fas fa-check-circle"></i> <?= dsEsc($colFlash) ?>
            <?php if (!empty($_GET['voucher'])): ?>
            <a href="<?= APP_URL ?>/accountant/income-refund-print.php?id=<?= (int)$_GET['voucher'] ?>" target="_blank"><i class="fas fa-print"></i> چاپکردنی پسوولە</a>
            <?php endif; ?>
        </div>
        <?php elseif (isset($_GET['col_error'])): ?>
        <div class="inc-mp-msg err"><i class="fas fa-exclamation-circle"></i> <?= dsEsc((string)$_GET['col_error']) ?></div>
        <?php endif; ?>

        <?php if ($canColumns): ?>
        <div class="inc-cs-cols">
            <?php foreach ($incColumns as $c): if (!$c['is_active']) continue; ?>
            <span class="inc-cs-col">
                <span><b><?= dsEsc($c['name']) ?></b><small><?= dsEsc(IncomeColumn::behaviourLabel($c)) ?></small></span>
                <button type="button" class="inc-cs-edit" title="دەستکاری" data-column="<?= dsEsc(json_encode([
                    'id' => (int)$c['id'], 'name' => $c['name'], 'cash_out' => (int)$c['cash_out'],
                    'on_doctor' => (int)$c['on_doctor'], 'entries' => (int)$c['entries'],
                ], JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i></button>
                <form method="post" action="<?= APP_URL ?>/accountant/income-columns.php" onsubmit="return confirm(<?= dsEsc(json_encode($c['entries'] > 0 ? 'ئەم کۆڵۆمە لە خشتەکە لادەبرێت. تۆمارەکانی و پارەکەیان وەک خۆیان دەمێننەوە.' : 'ئەم کۆڵۆمە بسڕدرێتەوە؟', JSON_UNESCAPED_UNICODE)) ?>)">
                    <input type="hidden" name="action" value="column_remove">
                    <input type="hidden" name="column_id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="return" value="<?= dsEsc($manReturn) ?>">
                    <button type="submit" class="del" title="لابردن"><i class="fas fa-trash"></i></button>
                </form>
            </span>
            <?php endforeach; ?>
            <button type="button" class="inc-cs-new" data-col-new><i class="fas fa-plus"></i> کۆڵۆمی نوێ</button>
            <?php if ($incColumns): ?>
            <button type="button" class="inc-cs-add" data-entry-new><i class="fas fa-plus"></i> تۆمارکردن</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($incColumnEntries): ?>
        <div class="inc-scroll">
            <table class="inc-mp-tbl">
                <thead><tr><th>ڕێکەوت</th><th>کۆڵۆم</th><th>نەخۆش</th><th>لەسەر</th><th>بڕ</th><th>تێبینی</th><th>تۆمارکەر</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($incColumnEntries as $m): $isLive = $m['status'] === 'active'; ?>
                    <tr class="<?= $isLive ? '' : 'is-off' ?>">
                        <td><bdi><?= dsEsc($m['entry_date']) ?></bdi></td>
                        <td>
                            <span class="inc-mp-tag cus"><?= dsEsc($m['column_name']) ?></span>
                            <?php if ($m['voucher_no']): ?><small class="inc-vno" dir="ltr"><?= dsEsc($m['voucher_no']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['patient_id']): ?>
                            <a href="<?= APP_URL ?>/patients/statement.php?patient_id=<?= (int)$m['patient_id'] ?>"><?= dsEsc($m['patient_name']) ?></a>
                            <small class="inc-vno"><?= dsEsc($m['case_label']) ?></small>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($m['row_kind'])): ?>
                            <b><?= dsEsc(IncomeColumn::ROWS[$m['row_kind']] ?? $m['row_kind']) ?></b>
                            <small class="inc-vno"><?= $m['row_cash_out'] ? 'لە قاسە دەرچوو' : 'تەنها لە خشتەکە' ?></small>
                            <?php else: ?><?= dsEsc($m['doctor_name'] ?? '—') ?><?php endif; ?>
                        </td>
                        <td class="n"><?= dsMoney((float)$m['amount']) ?></td>
                        <td>
                            <?= dsEsc($m['note'] ?? '') ?>
                            <?php if (!$isLive): ?>
                            <small class="inc-off">هەڵوەشێنراوە<?= $m['cancelled_by_name'] ? ' لەلایەن ' . dsEsc($m['cancelled_by_name']) : '' ?>: <?= dsEsc($m['cancel_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="who"><?= dsEsc($m['created_by_name'] ?? '—') ?><?php if ($m['updated_by_name']): ?><small>دەستکاری: <?= dsEsc($m['updated_by_name']) ?></small><?php endif; ?></td>
                        <td class="act">
                            <?php if ($m['voucher_no']): ?>
                            <a class="inc-ce-print" href="<?= APP_URL ?>/accountant/income-refund-print.php?id=<?= (int)$m['id'] ?>" target="_blank" title="چاپکردنی پسوولە"><i class="fas fa-print"></i></a>
                            <?php endif; ?>
                            <?php if ($canColumns && $isLive): ?>
                            <?php if (!$m['voucher_no']): ?>
                            <button type="button" class="inc-ce-edit" title="دەستکاری" data-entry="<?= dsEsc(json_encode([
                                'id' => (int)$m['id'], 'column_id' => (int)$m['column_id'], 'doctor_id' => (int)$m['doctor_id'],
                                'row_kind' => (string)($m['row_kind'] ?? ''), 'row_cash_out' => (int)($m['row_cash_out'] ?? 1),
                                'entry_date' => $m['entry_date'], 'amount' => (float)$m['amount'], 'note' => (string)($m['note'] ?? ''),
                            ], JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i></button>
                            <?php endif; ?>
                            <button type="button" class="del inc-ce-cancel" title="هەڵوەشاندنەوە" data-id="<?= (int)$m['id'] ?>"><i class="fas fa-ban"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">کۆ</td>
                        <td class="n"><?= dsMoney(array_sum(array_map(fn($m) => $m['status'] === 'active' ? (float)$m['amount'] : 0.0, $incColumnEntries))) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="inc-mp-empty">هیچ تۆمارێک بۆ ئەم ماوەیە نییە.</div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php if ($canColumns): ?>
<div class="dialog-overlay" id="incColumnDialog"><div class="dialog inc-dlg" role="dialog" aria-modal="true" aria-labelledby="inc-column-title">
    <div class="dialog-header"><h3 class="dialog-title" id="inc-column-title">کۆڵۆمی نوێ</h3><button type="button" class="dialog-close" onclick="closeDialog('incColumnDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <form method="post" action="<?= APP_URL ?>/accountant/income-columns.php" id="incColumnForm" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="column_save">
        <input type="hidden" name="column_id" value="">
        <input type="hidden" name="return" value="<?= dsEsc($manReturn) ?>">
        <div class="dialog-body">
            <label class="inc-dlg-f">ناوی کۆڵۆم <input type="text" name="name" maxlength="60" required placeholder="بۆ نموونە: گەڕاندنەوەی پارە"></label>
            <div class="inc-dlg-lock" hidden><i class="fas fa-lock"></i> ئەم کۆڵۆمە تۆماری هەیە، تەنها ناوەکەی دەگۆڕدرێت.</div>
            <div class="inc-dlg-q">
                <span>پارەکە لە قاسە دێتە خوارێ؟</span>
                <label class="inc-dlg-o"><input type="radio" name="cash_out" value="1" checked><b>بەڵێ — پارە دەدرێتەوە بە نەخۆش</b><small>ناوی نەخۆش و کەیس دەخوازێت، پسوولەی هەیە، لە فایلی نەخۆش دەردەکەوێت و لە سندوقەکان کەم دەبێتەوە.</small></label>
                <label class="inc-dlg-o"><input type="radio" name="cash_out" value="0"><b>نەخێر — خەرجییە</b><small>پارە ناچێتە دەرەوە: بەشی دکتۆر لە سندوقی ئەوەوە دەچێتە قاسەی <?= htmlspecialchars(Branding::shortName()) ?>، وەک خەرجی تاقیگە.</small></label>
            </div>
            <div class="inc-dlg-q">
                <span>لەسەر کێ دەکەوێت؟</span>
                <label class="inc-dlg-o"><input type="radio" name="on_doctor" value="0" checked><b>لە داهاتی دکتۆر، بە ڕێژە</b><small>١٠٠,٠٠٠ و ڕێژەی ٤٠٪: دکتۆر ٤٠,٠٠٠ و سەنتەر ٦٠,٠٠٠ هەڵدەگرن.</small></label>
                <label class="inc-dlg-o"><input type="radio" name="on_doctor" value="1"><b>هەمووی لەسەر دکتۆر</b><small>١٠٠,٠٠٠ هەمووی لە پشکی دکتۆر دەبڕدرێت و پشکی سەنتەر کەم نابێتەوە.</small></label>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="inc-mp-cancel" onclick="closeDialog('incColumnDialog')">پاشگەزبوونەوە</button>
            <button type="submit" class="inc-mp-save">پاشەکەوت</button>
        </div>
    </form>
</div></div>

<div class="dialog-overlay" id="incEntryDialog"><div class="dialog inc-dlg" role="dialog" aria-modal="true" aria-labelledby="inc-entry-title">
    <div class="dialog-header"><h3 class="dialog-title" id="inc-entry-title">تۆمارکردن لەسەر دکتۆر</h3><button type="button" class="dialog-close" onclick="closeDialog('incEntryDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <form method="post" action="<?= APP_URL ?>/accountant/income-columns.php" id="incEntryForm" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="entry_save">
        <input type="hidden" name="entry_id" value="">
        <input type="hidden" name="column_id" value="">
        <input type="hidden" name="patient_id" value="">
        <input type="hidden" name="return" value="<?= dsEsc($manReturn) ?>">
        <div class="dialog-body">
            <label class="inc-dlg-f">کۆڵۆم
                <select data-column-pick required>
                    <?php foreach ($incColumns as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" data-cash="<?= (int)$c['cash_out'] ?>" data-hint="<?= dsEsc(IncomeColumn::behaviourLabel($c)) ?>"><?= dsEsc($c['name']) ?><?= $c['is_active'] ? '' : ' (لابراوە)' ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="inc-dlg-hint"></small>
            </label>
            <div class="inc-dlg-refund" hidden>
                <div class="inc-dlg-f inc-dlg-pt">
                    <label for="incPatientQ">نەخۆش</label>
                    <input type="search" id="incPatientQ" data-patient-q placeholder="ناو یان ژمارەی مۆبایل بنووسە..." autocomplete="off">
                    <div class="inc-dlg-sug" hidden></div>
                </div>
                <label class="inc-dlg-f">کەیس
                    <select name="case_id" disabled><option value="">سەرەتا نەخۆش هەڵبژێرە</option></select>
                    <small class="inc-dlg-room"></small>
                </label>
            </div>
            <label class="inc-dlg-f">لەسەر
                <select name="doctor_id" required>
                    <option value="">— هەڵبژێرە —</option>
                    <optgroup label="دکتۆرەکان">
                    <?php foreach ($doctorRows as $d): ?>
                    <option value="<?= (int)$d['id'] ?>"><?= dsEsc($d['label']) ?></option>
                    <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="بەشەکان">
                    <?php foreach (IncomeColumn::ROWS as $rk => $rl): ?>
                    <option value="<?= dsEsc($rk) ?>"><?= dsEsc($rl) ?></option>
                    <?php endforeach; ?>
                    </optgroup>
                </select>
            </label>
            <!-- X-ray / Pharmacy have no doctor to share with: the accountant says
                 whether the money left a box -->
            <div class="inc-dlg-q inc-dlg-rowcash" hidden>
                <span>پارەکە لە قاسە دێتە خوارێ؟</span>
                <label class="inc-dlg-o"><input type="radio" name="row_cash_out" value="1" checked><b>بەڵێ — پارەکە دراوە</b><small>X-ray لە سندوقی ئێکسرە کەم دەبێت، Pharmacy لە قاسەی سەنتەر. ڕیزەکەش لە خشتەکە کەم دەبێت.</small></label>
                <label class="inc-dlg-o"><input type="radio" name="row_cash_out" value="0"><b>نەخێر — تەنها لە خشتەکە</b><small>تەنها کۆی ڕیزەکە و پشکی سەنتەر لە خشتەکە کەم دەبێت؛ هیچ سندوقێک ناجووڵێت.</small></label>
            </div>
            <div class="inc-dlg-row">
                <label class="inc-dlg-f">ڕێکەوت <input type="date" name="entry_date" value="<?= dsEsc($manDate) ?>" max="<?= dsEsc($today) ?>" required></label>
                <label class="inc-dlg-f">بڕ <input type="text" name="amount" inputmode="numeric" placeholder="0" required class="inc-mp-amt"></label>
            </div>
            <label class="inc-dlg-f">تێبینی <input type="text" name="note" maxlength="255" placeholder="هۆکار"></label>
        </div>
        <div class="dialog-footer">
            <button type="button" class="inc-mp-cancel" onclick="closeDialog('incEntryDialog')">پاشگەزبوونەوە</button>
            <button type="submit" class="inc-mp-save">پاشەکەوت</button>
        </div>
    </form>
</div></div>

<form method="post" action="<?= APP_URL ?>/accountant/income-columns.php" id="incCancelForm" hidden>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="entry_cancel">
    <input type="hidden" name="entry_id" value="">
    <input type="hidden" name="reason" value="">
    <input type="hidden" name="return" value="<?= dsEsc($manReturn) ?>">
</form>
<?php endif; ?>


<style>
.inc-dept{display:flex;align-items:center;justify-content:space-between;gap:8px}
.inc-add{width:22px;height:22px;border-radius:7px;border:1px dashed #38bdf8;background:#f0f9ff;color:#0369a1;cursor:pointer;font-size:10px;
    display:inline-grid;place-items:center;padding:0}
.inc-add:hover{background:#0284c7;border-color:#0284c7;color:#fff}
.inc-man{margin-top:3px;font-size:11px;font-weight:500;color:#64748b;text-align:right}
.inc-man a{color:#b45309;font-weight:700;text-decoration:none}
.inc-man a:hover{text-decoration:underline}

.inc-mp{margin-top:14px;background:#fff;border-radius:16px;padding:16px 20px;
    box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22)}
.inc-mp-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 14px;margin-bottom:12px}
/* The lists under the sheet open on demand (the script after this style). */
.inc-fold-sum{margin-inline-start:auto;font-size:12.5px;color:#0369a1;white-space:nowrap}
.inc-fold-btn{height:30px;padding:0 12px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#334155;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap}
.inc-fold-btn:hover{border-color:#0284c7;color:#0284c7}
.inc-fold-btn::after{content:' ▾'}
.inc-mp:not(.is-folded) .inc-fold-btn::after{content:' ▴'}
.inc-mp.is-folded{padding-top:12px;padding-bottom:12px}
.inc-mp.is-folded .inc-mp-head{margin-bottom:0;align-items:center}
.inc-mp.is-folded .inc-mp-head>span,.inc-mp.is-folded>:not(.inc-mp-head){display:none}
.inc-mp-head h3{margin:0;font-size:15px;color:#0f172a}
.inc-mp-head h3 i{color:#d97706}
.inc-mp-head span{font-size:12.5px;color:#64748b}
.inc-mp-msg{margin-bottom:10px;padding:8px 12px;border-radius:9px;font-size:13px}
.inc-mp-msg.ok{background:#ecfdf5;color:#065f46}
.inc-mp-msg.err{background:#fef2f2;color:#991b1b}
.inc-mp-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:12px;border-radius:12px;background:#fffbeb;border:1px solid #fde68a}
.inc-mp-form.is-edit{background:#eff6ff;border-color:#bfdbfe}
.inc-mp-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#475569}
.inc-mp-form input[type=date],.inc-mp-form input[type=text],.inc-mp-form input[type=number]{height:36px;border:1px solid #e2e8f0;border-radius:9px;
    background:#fff;padding:0 10px;font:inherit;font-size:13px}
.inc-mp-amt{width:140px;direction:ltr;text-align:left;font-weight:700}
.inc-mp-qty{width:80px}
.inc-mp-note{flex:1 1 180px}
.inc-mp-note input{width:100%}
.inc-mp-kinds{display:flex;gap:6px;align-self:center}
.inc-mp-kind{flex-direction:row!important;align-items:center;gap:6px!important;padding:8px 12px;border-radius:999px;border:1px solid #fcd34d;
    background:#fff;cursor:pointer;font-size:13px!important;font-weight:600;color:#92400e!important}
.inc-mp-kind input{display:none}
.inc-mp-kind:has(input:checked){background:#f59e0b;border-color:#f59e0b;color:#fff!important}
.inc-mp-save,.inc-mp-cancel{height:36px;border:0;border-radius:9px;padding:0 16px;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.inc-mp-save{color:#fff;background:linear-gradient(135deg,#d97706,#f59e0b)}
.inc-mp-form.is-edit .inc-mp-save{background:linear-gradient(135deg,#1d4ed8,#2563eb)}
.inc-mp-cancel{background:#e2e8f0;color:#334155}
.inc-mp-tbl{width:100%;border-collapse:collapse;margin-top:12px;min-width:640px}
.inc-mp-tbl th{background:#fef3c7;color:#92400e;font-size:12px;padding:7px 9px;border:1px solid #fde68a;white-space:nowrap}
.inc-mp-tbl td{padding:7px 9px;border:1px solid #f1f5f9;font-size:13px}
.inc-mp-tbl td.n{direction:ltr;text-align:left;font-weight:700;white-space:nowrap}
.inc-mp-tbl td.c{text-align:center}
.inc-mp-tbl td.who{font-size:12px;color:#64748b}
.inc-mp-tbl td.who small{display:block;font-size:10.5px;color:#94a3b8}
.inc-mp-tbl td.act{white-space:nowrap;width:1%}
.inc-mp-tbl td.act form{display:inline}
.inc-mp-tbl td.act button{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;color:#475569;cursor:pointer}
.inc-mp-tbl td.act button.del{color:#dc2626;border-color:#fecaca}
.inc-mp-tbl tfoot td{background:#fffbeb;font-weight:800;border-top:2px solid #fde68a}
.inc-mp-tbl tfoot td.sub{font-weight:600;font-size:12px;color:#92400e}
.inc-mp-tbl tfoot td.sub span{margin-inline-end:12px}
.inc-mp-tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:700}
.inc-mp-tag.pharmacy{background:#dcfce7;color:#166534}
.inc-mp-tag.exam{background:#e0f2fe;color:#075985}
.inc-mp-empty{margin-top:12px;padding:14px;text-align:center;color:#94a3b8;font-size:13px;border:1px dashed #e2e8f0;border-radius:10px}

/* کۆڵۆمی تایبەت */
.inc-bar button.inc-newcol{height:auto;padding:6px 11px;border-radius:999px;font-size:12.5px;color:#be123c;background:#fff;border:1px dashed #fda4af}
.inc-bar button.inc-newcol:hover{background:#ffe4e6}
.inc-bar button.inc-newcol i{font-size:10px}
.inc-tbl th.cus{background:#ffe4e6;color:#9f1239;border-color:#fda4af}
.inc-tbl td.cus{color:#be123c;background:#fff7f8;font-weight:600}
.inc-tbl tr.tot td.cus{background:#ffe4e6}
.inc-cc{display:flex;align-items:center;justify-content:space-between;gap:6px;direction:rtl}
.inc-cc span{direction:ltr}
.inc-cadd{flex:0 0 auto;width:20px;height:20px;padding:0;border-radius:6px;border:1px dashed #fda4af;background:#fff;color:#e11d48;cursor:pointer;font-size:9px;
    display:inline-grid;place-items:center;opacity:.55}
.inc-tbl tr:hover .inc-cadd{opacity:1}
.inc-cadd:hover{background:#e11d48;border-color:#e11d48;color:#fff}
.inc-cs .inc-mp-head h3 i{color:#e11d48}
.inc-cs-cols{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px;border-radius:12px;background:#fff1f2;border:1px solid #fecdd3}
.inc-cs-col{display:inline-flex;align-items:center;gap:6px;padding:5px 6px 5px 10px;border-radius:10px;background:#fff;border:1px solid #fecdd3}
.inc-cs-col>span{display:flex;flex-direction:column;line-height:1.35}
.inc-cs-col b{font-size:13px;color:#9f1239}
.inc-cs-col small{font-size:10.5px;color:#64748b}
.inc-cs-col form{display:inline}
.inc-cs-col button{width:26px;height:26px;padding:0;border-radius:7px;border:1px solid #e2e8f0;background:#fff;color:#475569;cursor:pointer;font-size:11px}
.inc-cs-col button.del{color:#dc2626;border-color:#fecaca}
.inc-cs-new,.inc-cs-add{height:36px;border-radius:9px;padding:0 14px;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.inc-cs-new{color:#be123c;background:#fff;border:1px dashed #fda4af}
.inc-cs-add{margin-inline-start:auto;border:0;color:#fff;background:linear-gradient(135deg,#be123c,#e11d48)}
.inc-mp-tag.cus{background:#ffe4e6;color:#9f1239}
.inc-cs .inc-mp-tbl th{background:#ffe4e6;color:#9f1239;border-color:#fecdd3}
.inc-cs .inc-mp-tbl tfoot td{background:#fff1f2;border-top-color:#fecdd3}
.inc-dlg{max-width:480px;width:calc(100vw - 32px)}
.inc-dlg .dialog-footer{display:flex;justify-content:flex-end;gap:8px}
.inc-dlg-f{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;font-size:12.5px;color:#475569}
.inc-dlg-f input,.inc-dlg-f select{height:38px;border:1px solid #e2e8f0;border-radius:9px;background:#fff;padding:0 10px;font:inherit;font-size:13.5px;width:100%}
.inc-dlg-f .inc-mp-amt{width:100%}
.inc-dlg-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.inc-dlg-hint{font-size:11.5px;color:#be123c}
.inc-dlg-q{margin-bottom:12px}
.inc-dlg-q>span{display:block;margin-bottom:6px;font-size:12.5px;font-weight:700;color:#334155}
.inc-dlg-o{display:grid;grid-template-columns:auto 1fr;column-gap:8px;align-items:center;padding:8px 10px;margin-bottom:6px;border:1px solid #e2e8f0;border-radius:10px;cursor:pointer}
.inc-dlg-o:has(input:checked){background:#fff1f2;border-color:#fda4af}
.inc-dlg-o b{font-size:13px;color:#0f172a}
.inc-dlg-o small{grid-column:2;font-size:11.5px;color:#64748b;line-height:1.6}
.inc-dlg-lock{margin-bottom:12px;padding:7px 10px;border-radius:9px;background:#fffbeb;color:#92400e;font-size:12px}
.inc-dlg-q.is-locked{opacity:.6;pointer-events:none}
.inc-dlg-pt{position:relative}
.inc-dlg-sug{position:absolute;top:100%;inset-inline:0;z-index:5;max-height:220px;overflow-y:auto;margin-top:2px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;
    box-shadow:0 10px 24px -10px rgba(15,23,42,.3)}
.inc-dlg-sug button{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:8px 10px;border:0;background:none;font:inherit;font-size:13px;cursor:pointer;text-align:start}
.inc-dlg-sug button:hover{background:#fff1f2}
.inc-dlg-sug small{font-size:11.5px;color:#64748b}
.inc-dlg-none{padding:10px;text-align:center;font-size:12.5px;color:#94a3b8}
.inc-dlg-room{font-size:11.5px;font-weight:700;color:#0f766e}
.inc-mp-msg a{margin-inline-start:10px;font-weight:700;color:inherit}
.inc-cs .inc-mp-tbl td:first-child{white-space:nowrap}
.inc-vno{display:block;margin-top:2px;font-size:11px;color:#64748b}
.inc-off{display:block;margin-top:2px;font-size:11px;font-weight:700;color:#b91c1c}
.inc-mp-tbl tr.is-off td{background:#f8fafc;color:#94a3b8}
.inc-mp-tbl tr.is-off td.n{text-decoration:line-through}
.inc-mp-tbl td.act .inc-ce-print{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;color:#475569;text-decoration:none;vertical-align:top}
</style>

<?php if ($canManual): ?>
<script>
(function () {
    var form = document.getElementById('incManForm');
    if (!form) return;
    var amount = form.elements['amount'];
    var save = form.querySelector('.inc-mp-save span');
    var cancel = form.querySelector('.inc-mp-cancel');
    var ku = '٠١٢٣٤٥٦٧٨٩';

    // 125000 → 125,000 while typing (Kurdish digits accepted).
    function fmt(v) {
        v = String(v).replace(/[٠-٩]/g, function (d) { return ku.indexOf(d); }).replace(/[^\d.]/g, '');
        var parts = v.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.length > 1 ? parts[0] + '.' + parts[1].slice(0, 2) : parts[0];
    }
    amount.addEventListener('input', function () { amount.value = fmt(amount.value); });

    function pickKind(kind) {
        var r = form.querySelector('input[name="kind"][value="' + kind + '"]');
        if (r) r.checked = true;
    }
    function reset() {
        form.reset();
        form.elements['action'].value = 'create';
        form.elements['entry_id'].value = '';
        form.classList.remove('is-edit');
        save.textContent = 'زیادکردن';
        cancel.hidden = true;
    }

    // «+» on the Pharmacy / Examinations row: jump here with that kind picked.
    document.querySelectorAll('.inc-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            reset();
            pickKind(btn.dataset.kind);
            document.getElementById('incManual').scrollIntoView({ behavior: 'smooth', block: 'start' });
            setTimeout(function () { amount.focus(); }, 350);
        });
    });

    document.querySelectorAll('.inc-mp-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var e = JSON.parse(btn.dataset.entry);
            form.elements['action'].value = 'update';
            form.elements['entry_id'].value = e.id;
            pickKind(e.kind);
            form.elements['entry_date'].value = e.entry_date;
            amount.value = fmt(Math.round(e.amount * 100) / 100);
            form.elements['qty'].value = e.qty || '';
            form.elements['note'].value = e.note || '';
            form.classList.add('is-edit');
            save.textContent = 'نوێکردنەوە';
            cancel.hidden = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            amount.focus();
        });
    });
    cancel.addEventListener('click', reset);
})();
</script>
<?php endif; ?>

<?php if ($canColumns): ?>
<script>
(function () {
    var appUrl = <?= json_encode(APP_URL) ?>;
    var ku = '٠١٢٣٤٥٦٧٨٩';
    // 100000 → 100,000 while typing (Kurdish digits accepted).
    function fmt(v) {
        v = String(v).replace(/[٠-٩]/g, function (d) { return ku.indexOf(d); }).replace(/[^\d.]/g, '');
        var parts = v.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.length > 1 ? parts[0] + '.' + parts[1].slice(0, 2) : parts[0];
    }
    function each(selector, fn) { document.querySelectorAll(selector).forEach(fn); }

    // ── a column ──
    var colForm = document.getElementById('incColumnForm');
    var colLock = colForm.querySelector('.inc-dlg-lock');
    function openColumn(c) {
        colForm.reset();
        colForm.elements['column_id'].value = c ? c.id : '';
        document.getElementById('inc-column-title').textContent = c ? 'دەستکاری کۆڵۆم' : 'کۆڵۆمی نوێ';
        if (c) {
            colForm.elements['name'].value = c.name;
            colForm.querySelector('input[name="cash_out"][value="' + c.cash_out + '"]').checked = true;
            colForm.querySelector('input[name="on_doctor"][value="' + c.on_doctor + '"]').checked = true;
        }
        var locked = !!(c && c.entries > 0);
        colLock.hidden = !locked;
        colForm.querySelectorAll('.inc-dlg-q').forEach(function (q) { q.classList.toggle('is-locked', locked); });
        openDialog('incColumnDialog');
        colForm.elements['name'].focus();
    }
    each('[data-col-new]', function (b) { b.addEventListener('click', function () { openColumn(null); }); });
    each('.inc-cs-edit', function (b) { b.addEventListener('click', function () { openColumn(JSON.parse(b.dataset.column)); }); });

    // ── an amount on a doctor ──
    var form = document.getElementById('incEntryForm');
    var pick = form.querySelector('[data-column-pick]');
    var hint = form.querySelector('.inc-dlg-hint');
    var amount = form.elements['amount'];
    var doctor = form.elements['doctor_id'];
    var title = document.getElementById('inc-entry-title');
    var day = form.elements['entry_date'].value;
    amount.addEventListener('input', function () { amount.value = fmt(amount.value); });

    // Money going back: the patient, then his case, which names the doctor.
    var refundBox = form.querySelector('.inc-dlg-refund');
    var patientQ = form.querySelector('[data-patient-q]');
    var suggestions = form.querySelector('.inc-dlg-sug');
    var casePick = form.elements['case_id'];
    var room = form.querySelector('.inc-dlg-room');
    var searchTimer = null, searchTurn = 0;

    function emptyCases(text) {
        casePick.innerHTML = '';
        casePick.add(new Option(text, ''));
        casePick.disabled = true;
        room.textContent = '';
    }
    function syncCase() {
        var opt = casePick.options[casePick.selectedIndex];
        if (!opt || !opt.value) { room.textContent = ''; return; }
        room.textContent = 'زۆرترین بڕی گەڕاندنەوە: ' + Number(opt.dataset.room).toLocaleString('en-US');
        if (opt.dataset.doctor !== '0') {
            if (![].some.call(doctor.options, function (o) { return o.value === opt.dataset.doctor; })) {
                doctor.add(new Option(opt.dataset.doctorName, opt.dataset.doctor));
            }
            doctor.value = opt.dataset.doctor;
        }
    }
    casePick.addEventListener('change', syncCase);
    function pickPatient(p) {
        form.elements['patient_id'].value = p.id;
        patientQ.value = p.full_name;
        suggestions.hidden = true;
        emptyCases('بارکردن...');
        fetch(appUrl + '/accountant/income-columns.php?patient_cases=' + encodeURIComponent(p.id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var cases = data.cases || [];
                if (!cases.length) { emptyCases('ئەم نەخۆشە پارەی دراوی نییە کە بگەڕێنرێتەوە'); return; }
                casePick.innerHTML = '';
                cases.forEach(function (c) {
                    var opt = new Option(c.label + ' — ' + c.doctor_name, c.id);
                    opt.dataset.room = c.room;
                    opt.dataset.doctor = c.doctor_id;
                    opt.dataset.doctorName = c.doctor_name;
                    casePick.add(opt);
                });
                casePick.disabled = false;
                syncCase();
            })
            .catch(function () { emptyCases('هەڵەیەک ڕوویدا، دووبارە هەوڵ بدەرەوە'); });
    }
    patientQ.addEventListener('input', function () {
        form.elements['patient_id'].value = '';
        emptyCases('سەرەتا نەخۆش هەڵبژێرە');
        clearTimeout(searchTimer);
        var q = patientQ.value.trim();
        if (q.length < 2) { suggestions.hidden = true; return; }
        var turn = ++searchTurn;
        searchTimer = setTimeout(function () {
            fetch(appUrl + '/ajax/patient_search.php?slim=1&limit=8&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (turn !== searchTurn) return;
                    suggestions.innerHTML = '';
                    var results = data.results || [];
                    if (!results.length) {
                        var none = document.createElement('div');
                        none.className = 'inc-dlg-none';
                        none.textContent = 'هیچ نەخۆشێک نەدۆزرایەوە';
                        suggestions.appendChild(none);
                    }
                    results.forEach(function (p) {
                        var row = document.createElement('button');
                        row.type = 'button';
                        var name = document.createElement('b');
                        name.textContent = p.full_name;
                        var phone = document.createElement('small');
                        phone.dir = 'ltr';
                        phone.textContent = p.phone || '';
                        row.appendChild(name);
                        row.appendChild(phone);
                        row.addEventListener('click', function () { pickPatient(p); });
                        suggestions.appendChild(row);
                    });
                    suggestions.hidden = false;
                });
        }, 220);
    });

    var rowCash = form.querySelector('.inc-dlg-rowcash');
    function onRow() { return doctor.value === 'xray' || doctor.value === 'pharmacy'; }
    function syncColumn() {
        var opt = pick.options[pick.selectedIndex];
        var row = onRow();
        // money given back is on a doctor's work; on a row an amount is a cost
        var refund = !!(opt && opt.dataset.cash === '1') && !row;
        rowCash.hidden = !row;
        form.elements['column_id'].value = pick.value;
        // a row has no doctor: all of it comes off the row and the centre's share
        hint.textContent = row ? 'هەمووی لە کۆی ڕیزەکە و پشکی سەنتەر کەم دەبێت' : (opt ? opt.dataset.hint : '');
        refundBox.hidden = !refund;
        patientQ.required = refund;
        casePick.required = refund;
        form.elements['note'].required = refund;
        form.elements['note'].placeholder = refund ? 'هۆکاری گەڕاندنەوە' : 'هۆکار';
        title.textContent = form.elements['entry_id'].value ? 'دەستکاری تۆمار'
            : (refund ? 'پسوولەی گەڕاندنەوەی پارە' : (row ? 'تۆمارکردن لەسەر ' + doctor.options[doctor.selectedIndex].text : 'تۆمارکردن لەسەر دکتۆر'));
    }
    pick.addEventListener('change', syncColumn);
    doctor.addEventListener('change', syncColumn);
    function openEntry(e) {
        form.reset();
        form.elements['entry_date'].value = (e && e.entry_date) || day;
        form.elements['entry_id'].value = (e && e.id) || '';
        form.elements['patient_id'].value = '';
        suggestions.hidden = true;
        emptyCases('سەرەتا نەخۆش هەڵبژێرە');
        if (e && e.column_id) pick.value = e.column_id;
        if (e && e.row_kind) doctor.value = e.row_kind;
        else if (e && e.doctor_id) doctor.value = e.doctor_id;
        var cashPick = form.querySelector('input[name="row_cash_out"][value="' + (e && e.row_kind && String(e.row_cash_out) === '0' ? '0' : '1') + '"]');
        if (cashPick) cashPick.checked = true;
        if (e && e.amount) amount.value = fmt(Math.round(e.amount * 100) / 100);
        form.elements['note'].value = (e && e.note) || '';
        // An entry stays under its column: the boxes were moved by that column's rules.
        pick.disabled = !!(e && e.id);
        syncColumn();
        openDialog('incEntryDialog');
        (refundBox.hidden ? amount : patientQ).focus();
    }
    form.addEventListener('submit', function (ev) {
        // A name typed but never picked from the list is not a patient.
        if (!refundBox.hidden && !form.elements['patient_id'].value) {
            ev.preventDefault();
            patientQ.focus();
        }
    });
    each('[data-entry-new]', function (b) { b.addEventListener('click', function () { openEntry(null); }); });
    each('.inc-cadd', function (b) {
        b.addEventListener('click', function () { openEntry({ column_id: b.dataset.col, doctor_id: b.dataset.doctor }); });
    });
    each('.inc-ce-edit', function (b) { b.addEventListener('click', function () { openEntry(JSON.parse(b.dataset.entry)); }); });

    // ── cancelling: the entry stays, with why ──
    var cancelForm = document.getElementById('incCancelForm');
    each('.inc-ce-cancel', function (b) {
        b.addEventListener('click', function () {
            Swal.fire({
                title: 'هەڵوەشاندنەوە', text: 'پارەکە دەگەڕێتەوە سندوقەکان و تۆمارەکە وەک هەڵوەشێنراوە دەمێنێتەوە.',
                input: 'text', inputPlaceholder: 'هۆکاری هەڵوەشاندنەوە',
                showCancelButton: true, confirmButtonText: 'هەڵوەشاندنەوە', cancelButtonText: 'نەخێر', confirmButtonColor: '#dc2626',
                inputValidator: function (v) { return v && v.trim().length >= 3 ? null : 'هۆکار بنووسە'; }
            }).then(function (r) {
                if (!r.isConfirmed) return;
                cancelForm.elements['entry_id'].value = b.dataset.id;
                cancelForm.elements['reason'].value = r.value;
                cancelForm.submit();
            });
        });
    });
})();
</script>
<?php endif; ?>

<script>
// The lists under the sheet (داهاتی دەستی, کۆڵۆمی تایبەت) are folded to their
// head line until opened; the choice is remembered per list. One opens by
// itself when it has just been saved to, when the page or a link points at it,
// or when a «+» on the sheet sends the cursor into its form.
(function () {
    var key = function (s) { return 'inc-fold-' + s.id; };
    function set(s, open, remember) {
        s.classList.toggle('is-folded', !open);
        var b = s.querySelector('.inc-fold-btn');
        b.textContent = open ? 'شاردنەوە' : 'پیشاندان';
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (remember) { try { localStorage.setItem(key(s), open ? '1' : '0'); } catch (e) {} }
    }
    function open(id) { var s = document.getElementById(id); if (s && s.hasAttribute('data-fold')) set(s, true, false); }
    document.querySelectorAll('.inc-mp[data-fold]').forEach(function (s) {
        var saved = null;
        try { saved = localStorage.getItem(key(s)); } catch (e) {}
        set(s, saved === '1' || !!s.querySelector('.inc-mp-msg') || location.hash === '#' + s.id, false);
        s.querySelector('.inc-fold-btn').addEventListener('click', function () { set(s, s.classList.contains('is-folded'), true); });
    });
    // Before the sheet's own handlers scroll into the list.
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest('a[href^="#inc"]');
        if (a) open(a.getAttribute('href').slice(1));
        if (ev.target.closest('.inc-add')) open('incManual');
    }, true);
    window.addEventListener('hashchange', function () { open(location.hash.slice(1)); });
})();
</script>

<script>
// «پڕ بە شاشە»: the sheet covers the window (and the screen, where the browser
// allows it) so every column shows; the same button or Escape brings it back.
// The whole page goes full screen, not the sheet alone, so the detail dialogs
// still open over it.
(function () {
    var sheet = document.querySelector('.inc-sheet'), btn = document.getElementById('incFullBtn');
    if (!sheet || !btn) return;
    function set(on) {
        sheet.classList.toggle('is-full', on);
        document.body.classList.toggle('inc-full-open', on);
        btn.querySelector('span').textContent = on ? 'گەڕانەوە' : 'پڕ بە شاشە';
        btn.querySelector('i').className = on ? 'fas fa-compress' : 'fas fa-expand';
    }
    btn.addEventListener('click', function () {
        var on = !sheet.classList.contains('is-full');
        set(on);
        try {
            if (on && document.documentElement.requestFullscreen && !document.fullscreenElement) document.documentElement.requestFullscreen().catch(function () {});
            if (!on && document.fullscreenElement) document.exitFullscreen().catch(function () {});
        } catch (e) {}
    });
    document.addEventListener('fullscreenchange', function () { if (!document.fullscreenElement && sheet.classList.contains('is-full')) set(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-full') && !document.querySelector('.dialog-overlay.open')) set(false);
    });
})();
</script>

<?php income_detail_shell($from, $to); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
