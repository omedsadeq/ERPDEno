<?php
/**
 * پوختەی ڕۆژانە — the modern look («مۆدێرن»): every figure as a card with
 * its own icon and colour, the work types as chips. The same row lists as
 * the classic tables and the PDF (ds_row_lists), so the numbers never differ;
 * only the look does. Included by daily-summary.php when the viewer chose it.
 * The cards' icons and colours are _daily_summary_cards.php's, which the
 * modern PDF uses too.
 */

require_once __DIR__ . '/_daily_summary_cards.php';

/**
 * One list of rows as cards. A «— …» row (the store split into ئاسایی /
 * Implant) is a note on the card before it, not a card of its own. An
 * expense is printed «(1,000)» in the tables; on a card it is just the
 * amount, as the label already says it is spent. Any other «(…)» is a
 * loss and shows as a minus.
 */
function dsm_cards(array $rows): string
{
    $html = '';
    foreach (dsm_card_list($rows) as $c) {
        $html .= '<div class="dsm-card c-' . $c['colour'] . ($c['total'] ? ' is-total' : '') . '">'
            . '<span class="dsm-ic"><i class="fas ' . $c['icon'] . '"></i></span>'
            . '<b class="dsm-v' . ($c['neg'] ? ' is-neg' : '') . '"><bdi>' . dsEsc($c['value']) . '</bdi></b>'
            . '<span class="dsm-l">' . dsEsc($c['label']) . '</span>'
            . ($c['note'] !== '' ? '<small class="dsm-n">' . dsEsc($c['note']) . '</small>' : '');
        foreach ($c['notes'] as $n) {
            $html .= '<small class="dsm-n">' . dsEsc($n) . '</small>';
        }
        $html .= '</div>';
    }
    return $html;
}

$dsmChipColours = ['green', 'blue', 'purple', 'orange', 'teal', 'pink', 'indigo', 'red'];
?>
<style>
.dsm{--purple:#8b5cf6;--purple-t:#f3e8ff;--pink:#ec4899;--pink-t:#fce7f3;--blue:#3b82f6;--blue-t:#dbeafe;
     --green:#22c55e;--green-t:#dcfce7;--orange:#f59e0b;--orange-t:#fef3c7;--red:#ef4444;--red-t:#fee2e2;
     --teal:#14b8a6;--teal-t:#ccfbf1;--indigo:#6366f1;--indigo-t:#e0e7ff;--slate:#64748b;--slate-t:#f1f5f9;
     margin-top:14px}
.dsm-sec{margin-top:16px}
.dsm-sec>h2{display:flex;align-items:center;gap:8px;margin:0 0 10px;padding:9px 14px;border-radius:12px;
     background:#eef4fc;color:#1e293b;font-size:14.5px;font-weight:800}
.dsm-sec>h2 i{color:#3b82f6}
.dsm-sec>h2 small{margin-inline-start:auto;font-weight:600;color:#64748b;font-size:12.5px}
.dsm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:12px}
.dsm-card{--c:var(--slate);--t:var(--slate-t);background:#fff;border-radius:14px;border-top:4px solid var(--c);
     box-shadow:0 1px 3px rgba(15,23,42,.08),0 4px 14px rgba(15,23,42,.05);padding:14px 10px 12px;
     display:flex;flex-direction:column;align-items:center;text-align:center;gap:5px;min-width:0}
.dsm-card.is-total{box-shadow:0 0 0 1.5px var(--c) inset,0 4px 14px rgba(15,23,42,.07)}
.dsm-ic{width:40px;height:40px;border-radius:11px;background:var(--t);color:var(--c);display:flex;align-items:center;justify-content:center;font-size:18px}
.dsm-v{font-size:21px;font-weight:800;color:#0f172a;direction:ltr;font-variant-numeric:tabular-nums;line-height:1.25;word-break:break-all}
.dsm-v.is-neg{color:#dc2626}
.dsm-l{font-size:13px;color:#334155;font-weight:600;line-height:1.35}
.dsm-n{font-size:11px;color:#64748b;line-height:1.35}
.c-purple{--c:var(--purple);--t:var(--purple-t)}.c-pink{--c:var(--pink);--t:var(--pink-t)}.c-blue{--c:var(--blue);--t:var(--blue-t)}
.c-green{--c:var(--green);--t:var(--green-t)}.c-orange{--c:var(--orange);--t:var(--orange-t)}.c-red{--c:var(--red);--t:var(--red-t)}
.c-teal{--c:var(--teal);--t:var(--teal-t)}.c-indigo{--c:var(--indigo);--t:var(--indigo-t)}.c-slate{--c:var(--slate);--t:var(--slate-t)}

.dsm-chips{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
.dsm-chip{--c:var(--slate);--t:var(--slate-t);display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid var(--c);
     border-color:color-mix(in srgb,var(--c) 45%,#fff);border-radius:12px;padding:9px 11px;min-width:0}
.dsm-chip .dsm-ic{width:38px;height:38px;flex:none}
.dsm-chip div{min-width:0;display:flex;flex-direction:column}
.dsm-chip b{font-size:17px;color:#0f172a;line-height:1.2}
.dsm-chip span{font-size:12.5px;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dsm-chip small{font-size:11px;color:#64748b;direction:ltr;text-align:right}
.dsm-empty{padding:16px;text-align:center;color:#64748b;background:#fff;border-radius:12px}

.dsm-box{background:#fff;border-radius:14px;box-shadow:0 1px 3px rgba(15,23,42,.08);overflow:hidden}
.dsm .ds-att{border-radius:12px;margin-top:10px;border:1px solid #e2e8f0}
.dsm .ds-att button{border-radius:8px}

@media(max-width:640px){
    .dsm-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
    .dsm-card{padding:10px 5px 9px;border-radius:12px}
    .dsm-ic{width:32px;height:32px;font-size:15px;border-radius:9px}
    .dsm-v{font-size:15px}
    .dsm-l{font-size:11.5px}
    .dsm-chips{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
}
</style>

<div class="dsm">
    <section class="dsm-sec">
        <h2><i class="fas fa-coins"></i> پارە</h2>
        <div class="dsm-grid"><?= dsm_cards($dsMoneyRows) ?></div>
    </section>

    <section class="dsm-sec">
        <h2><i class="fas fa-user-injured"></i> سەردان و پشکنین</h2>
        <div class="dsm-grid"><?= dsm_cards($dsVisitRows) ?></div>
    </section>

    <section class="dsm-sec">
        <h2><i class="fas fa-stopwatch"></i> کات</h2>
        <div class="dsm-grid"><?= dsm_cards($dsTimeRows) ?></div>
        <?php $dsAttendance(); ?>
    </section>

    <section class="dsm-sec">
        <h2><i class="fas fa-x-ray"></i> ناردن بۆ ئێکسڕەی <small>داهاتەکەی هی سەنتەرە</small></h2>
        <div class="dsm-grid"><?= dsm_cards($dsXrayRows) ?></div>
    </section>

    <section class="dsm-sec">
        <h2><i class="fas fa-folder-open"></i> جۆری ئیشەکان <small><?= dsMoney($treatmentCount) ?> ئیش · <?= dsMoney($treatmentAmount) ?></small></h2>
        <?php if (!$topTreatments): ?>
            <div class="dsm-empty">هیچ ئیشێک تۆمار نەکراوە</div>
        <?php else: ?>
        <div class="dsm-chips">
            <?php foreach ($topTreatments as $i => $t): ?>
            <div class="dsm-chip c-<?= $dsmChipColours[$i % count($dsmChipColours)] ?>">
                <span class="dsm-ic"><i class="fas fa-tooth"></i></span>
                <div>
                    <b><?= dsMoney($t['cnt']) ?></b>
                    <span title="<?= dsEsc($t['treatment_name']) ?>"><?= dsEsc($t['treatment_name']) ?></span>
                    <small><?= dsMoney($t['amt']) ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="dsm-sec">
        <h2><i class="fas fa-list-alt"></i> نەخۆشەکان <small><?= dsMoney(count($patientRows)) ?> کەیس</small></h2>
        <div class="dsm-box"><?php $dsPatients(); ?></div>
    </section>
</div>
