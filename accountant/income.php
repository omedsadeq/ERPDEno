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
             and only ticked kinds are subtracted. Lab/store are table columns;
             general has no column and only counts in the bottom خەرجیات. -->
        <div class="inc-exp">
            <span>خەرجی:</span>
            <input type="hidden" name="xs" value="1">
            <?php foreach (['lab' => 'تاقیگە', 'store' => 'کۆگا', 'general' => 'گشتی'] as $k => $short): ?>
            <label class="inc-chip" title="<?= $k === 'general' ? 'تەنها لە کۆی خەرجیاتی خوارەوە هەژمار دەکرێت' : 'کۆڵۆمی ' . $short . ' لە خشتەکە' ?>">
                <input type="checkbox" name="x[]" value="<?= $k ?>" <?= in_array($k, $expSel, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                <i class="fas fa-check"></i> <?= $short ?>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="inc-days">
            <a href="<?= dsEsc($rangeQs($prevFrom, $prevTo)) ?>">← پێشوو</a>
            <?php foreach ($rangePresets as [$pLabel, $pFrom, $pTo]): ?>
            <a href="<?= dsEsc($rangeQs($pFrom, $pTo)) ?>" class="<?= $pFrom === $from && $pTo === $to ? 'on' : '' ?>"><?= dsEsc($pLabel) ?></a>
            <?php endforeach; ?>
            <a href="<?= dsEsc($rangeQs($nextFrom, $nextTo)) ?>">داهاتوو →</a>
        </div>
    </form>

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
                    <tr>
                        <th>#</th><th>ناوەڕۆک</th><th>ژمارە</th><th>بڕی</th>
                        <?php foreach ($colSel as $k): ?><th><?= dsEsc($expenseKinds[$k]) ?></th><?php endforeach; ?>
                        <th>کۆی گشتی</th>
                        <th class="doc">پشکی دکتۆر<small>بە ڕێژەی خۆی</small></th>
                        <th class="ctr">پشکی سەنتەر<small>بۆ <?= htmlspecialchars(Branding::shortName()) ?></small></th>
                    </tr>
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
                        <td class="n amt"><?= incCell($r['amount']) ?></td>
                        <?php foreach ($colSel as $k): ?>
                        <td class="n exp"><?= incCell($r[$k]) ?></td>
                        <?php endforeach; ?>
                        <td class="n net<?= $r['net'] < 0 ? ' neg' : '' ?>"><?= incCell($r['net']) ?></td>
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
                        <?php endforeach; ?>
                        <td class="n"><?= $totals['net'] < 0 ? '(' . dsMoney(abs($totals['net'])) . ')' : dsMoney($totals['net']) ?></td>
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
                <?php if ($colSel): ?>
                <div class="s">داهات <?= dsMoney($totals['amount']) ?> − خەرجی تاقیگە و کۆگا</div>
                <?php endif; ?>
            </div>
            <div class="inc-box exp">
                <div class="k">خەرجیات</div>
                <div class="v"><?= dsMoney($expensesAll) ?></div>
                <div class="s"><?php
                    echo $expenseLines
                        ? implode(' · ', array_map(fn($l, $v) => $l . ' ' . dsMoney($v), array_keys($expenseLines), $expenseLines))
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
                <div class="s">هەر دکتۆرێک بە ڕێژەی خۆی لە کۆی گشتییەکەی</div>
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
    <section class="inc-mp" id="incManual">
        <div class="inc-mp-head">
            <h3><i class="fas fa-hand-holding-usd"></i> داهاتی دەستی — دەرمانخانە و پشکنین</h3>
            <span>ئەو پارەیەی سیستەمەکە تۆماری نەکردووە. دەخرێتە سەر ڕیزی <b dir="ltr">Pharmacy</b> و <b dir="ltr">Examinations</b>.</span>
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
</div>

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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
