<?php
/**
 * پوختەی ڕۆژانەی دکتۆر — one day of a doctor's work and money, on screen.
 *
 * Every figure comes from _daily_summary_data.php, which daily-summary-pdf.php
 * also uses — so the PDF is always exactly what this page shows.
 */

require_once __DIR__ . '/_daily_summary_data.php';

$pageTitle = 'پوختەی ڕۆژانەم';
$breadcrumb = [
    ['title' => 'دکتۆرەکان'],
    ['title' => 'پوختەی ڕۆژانەم'],
];

require_once __DIR__ . '/../includes/header.php';

$docQs = $isAdminView ? '&doctor=' . $docUserId : '';
/** Link to the same sheet for another period. */
$rangeQs = fn(string $f, string $t) => '?from=' . urlencode($f) . '&to=' . urlencode($t) . $docQs;
$pdfUrl = APP_URL . '/doctors/daily-summary-pdf.php' . $rangeQs($from, $to);

/** One compact key/value table — the same row lists the PDF prints. */
function dsRows(array $rows)
{
    foreach ($rows as [$label, $value, $style]) {
        echo '<tr class="' . ($style === 'total' ? 'is-total' : '') . '">'
            . '<td>' . dsEsc($label) . '</td>'
            . '<td class="v ' . ($style === 'pos' || $style === 'neg' ? $style : '') . '">' . dsEsc($value) . '</td>'
            . '</tr>';
    }
}
?>

<style>
.ds{max-width:1400px;margin:0 auto 28px;color:#172b3a;direction:rtl}
.ds-bar{background:#244b68;color:#fff;padding:10px 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.ds-bar h1{margin:0;font-size:17px;font-weight:700}
.ds-bar .day{color:#cfe0ec;font-size:12.5px}
.ds-pdf{margin-inline-start:auto;background:#fff;color:#244b68;padding:6px 14px;font-size:12px;font-weight:700;text-decoration:none;border-radius:3px}
.ds-pdf:hover{background:#e3edf4}
.ds-lockform{margin:0}
.ds-lockform button{background:transparent;color:#fff;border:1px solid #9fbbd0;padding:5px 12px;font:inherit;font-size:12px;font-weight:700;border-radius:3px;cursor:pointer}
.ds-lockform button:hover{background:#1b3a51}
.ds-filter{border:1px solid #c3d0da;border-top:0;background:#f5f7f9;padding:7px 10px;display:flex;flex-wrap:wrap;align-items:center;gap:7px}
.ds-filter label{display:flex;align-items:center;gap:5px;font-size:12px;color:#445b6c}
.ds-filter input,.ds-filter select{height:31px;border:1px solid #9cafbd;background:#fff;padding:3px 7px;font:inherit;font-size:12px}
.ds-filter button{height:31px;border:1px solid #244b68;background:#244b68;color:#fff;padding:0 14px;font:inherit;font-size:12px;cursor:pointer}
.ds-days{display:flex;gap:4px;flex-wrap:wrap;margin-inline-start:auto}
.ds-days a{border:1px solid #9cafbd;background:#fff;color:#244b68;text-decoration:none;padding:5px 10px;font-size:11.5px}
.ds-days a.on{background:#dbe8f1;border-color:#244b68;font-weight:700}
.ds-warn{border:1px solid #d9a441;background:#fdf6e6;color:#7a5200;padding:7px 11px;font-size:12px;margin-top:10px}

.ds-grid{display:grid;gap:12px;margin-top:12px}
.ds-grid.g3{grid-template-columns:repeat(3,1fr)}
.ds-grid.g2{grid-template-columns:minmax(260px,1fr) 2fr;align-items:start}
.ds-card{border:1px solid #b9c6cf;background:#fff}
.ds-card h2{margin:0;padding:7px 11px;background:#e6edf2;border-bottom:1px solid #b9c6cf;font-size:13px;color:#18364c;display:flex;justify-content:space-between;gap:8px}
.ds-card h2 small{font-weight:400;color:#5a6e7d}
.ds-kv{width:100%;border-collapse:collapse}
.ds-kv td{padding:5px 11px;font-size:12.5px;border-bottom:1px solid #eef2f5}
.ds-kv td.v{text-align:left;direction:ltr;white-space:nowrap;font-weight:600;font-variant-numeric:tabular-nums}
.ds-kv tr.is-total td{background:#f2f6f9;font-weight:800;border-top:1px solid #9cafbd}
.ds-kv tr.sub td{background:#f7f9fb;color:#526b7d;font-size:11.5px;font-weight:700;padding-top:7px}
.ds-kv .pos{color:#087b35}.ds-kv .neg{color:#a25c00}
.ds-kv .ds-note{font-weight:400;color:#7a8b98}

.ds-tbl{width:100%;border-collapse:collapse}
.ds-tbl th{background:#eef2f5;color:#18364c;font-size:11.5px;padding:6px 8px;border-bottom:1px solid #b9c6cf;white-space:nowrap;text-align:right}
.ds-tbl td{padding:5px 8px;font-size:12.5px;border-bottom:1px solid #eef2f5}
.ds-tbl .n{text-align:left;direction:ltr;white-space:nowrap;font-variant-numeric:tabular-nums}
.ds-tbl .i{width:30px;color:#7a8b98;text-align:center}
.ds-tbl tr.is-total td{background:#f2f6f9;font-weight:800;border-top:1px solid #9cafbd}
.ds-tbl .pos{color:#087b35;font-weight:600}.ds-tbl .neg{color:#a25c00;font-weight:600}
.ds-tbl .empty{text-align:center;color:#7a8b98;padding:16px}
.ds-scroll{overflow-x:auto}

.ds-att{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:7px 11px;background:#f7f9fb;border-top:1px solid #eef2f5;font-size:12px;color:#445b6c}
.ds-att button{border:0;color:#fff;padding:5px 12px;font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.ds-att .in{background:#087b35}.ds-att .out{background:#a25c00}

@media(max-width:1100px){.ds-grid.g3,.ds-grid.g2{grid-template-columns:1fr}.ds-days{margin-inline-start:0}}
</style>

<div class="ds">

    <div class="ds-bar">
        <h1><?= dsEsc($docName) ?></h1>
        <span class="day"><bdi><?= dsEsc($rangeLabel) ?></bdi><?= $isToday ? ' · ئەمڕۆ' : '' ?></span>
        <a class="ds-pdf" href="<?= dsEsc($pdfUrl) ?>" target="_blank">
            <i class="fas fa-file-pdf"></i> ڕاپۆرتی PDF
        </a>
        <form method="post" class="ds-lockform">
            <?= Csrf::field() ?>
            <button type="submit" name="ds_lock" value="1" title="پەڕەکە قفڵ بکە؛ بۆ کردنەوەی دووبارە وشەی نهێنی دەوێت">
                <i class="fas fa-lock"></i> قفڵکردن
            </button>
        </form>
    </div>

    <form method="get" class="ds-filter">
        <label>لە <input type="date" name="from" value="<?= dsEsc($from) ?>"></label>
        <label>بۆ <input type="date" name="to" value="<?= dsEsc($to) ?>"></label>
        <?php if ($isAdminView): ?>
        <select name="doctor">
            <?php foreach ($doctorList as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $docUserId ? 'selected' : '' ?>><?= dsEsc($d['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit">پیشاندان</button>
        <div class="ds-days">
            <a href="<?= dsEsc($rangeQs($prevFrom, $prevTo)) ?>" title="ماوەی پێشوو">← پێشوو</a>
            <?php foreach ($rangePresets as [$pLabel, $pFrom, $pTo]): ?>
            <a href="<?= dsEsc($rangeQs($pFrom, $pTo)) ?>" class="<?= $pFrom === $from && $pTo === $to ? 'on' : '' ?>"><?= dsEsc($pLabel) ?></a>
            <?php endforeach; ?>
            <a href="<?= dsEsc($rangeQs($nextFrom, $nextTo)) ?>" title="ماوەی داهاتوو">داهاتوو →</a>
        </div>
    </form>

    <?php if ($visitsLinkBroken): ?>
    <div class="ds-warn">
        <i class="fas fa-exclamation-triangle"></i>
        ئەم هەژمارە بە تۆماری دکتۆرەوە نەبەستراوە، بۆیە سەردانەکان سفرن — داوا لە بەڕێوەبەر بکە بیبەستێتەوە.
    </div>
    <?php endif; ?>

    <div class="ds-grid g3">
        <div class="ds-card">
            <h2><span><i class="fas fa-coins"></i> پارە</span></h2>
            <table class="ds-kv">
                <?php dsRows($dsMoneyRows); ?>
            </table>
        </div>

        <div class="ds-card">
            <h2><span><i class="fas fa-user-injured"></i> سەردان و پشکنین</span></h2>
            <table class="ds-kv">
                <?php dsRows($dsVisitRows); ?>
                <!-- X-ray is the centre's own department: only who was sent. -->
                <tr class="sub"><td colspan="2"><i class="fas fa-x-ray"></i> ناردن بۆ ئێکسڕەی <span class="ds-note">— داهاتەکەی هی سەنتەرە</span></td></tr>
                <?php dsRows($dsXrayRows); ?>
            </table>
        </div>

        <div class="ds-card">
            <h2><span><i class="fas fa-stopwatch"></i> کات</span></h2>
            <table class="ds-kv"><?php dsRows($dsTimeRows); ?></table>
            <?php if ($isToday && ($docUserId === $viewerUserId || $isAdminView)): ?>
            <div class="ds-att" id="ds-att" data-doctor-id="<?= (int)$docUserId ?>">
                <?php if (empty($attendance['check_in_time'])): ?>
                    <span>هێشتا نەهاتوویتە ژوورەوە</span>
                    <button type="button" class="in" data-action="check_in"><i class="fas fa-door-open"></i> هاتنەژوورەوە</button>
                <?php elseif (empty($attendance['check_out_time'])): ?>
                    <span>هاتن: <?= dsEsc(date('H:i', strtotime($attendance['check_in_time']))) ?></span>
                    <button type="button" class="out" data-action="check_out"><i class="fas fa-door-closed"></i> چوونەدەرەوە</button>
                <?php else: ?>
                    <span><?= dsEsc(date('H:i', strtotime($attendance['check_in_time']))) ?> → <?= dsEsc(date('H:i', strtotime($attendance['check_out_time']))) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ds-grid g2">
        <div class="ds-card">
            <h2><span><i class="fas fa-tooth"></i> جۆری ئیشەکان</span><small><?= dsMoney($treatmentCount) ?></small></h2>
            <div class="ds-scroll">
                <table class="ds-tbl">
                    <thead><tr><th class="i">#</th><th>جۆر</th><th>ژمارە</th><th>بڕ</th></tr></thead>
                    <tbody>
                    <?php if (!$topTreatments): ?>
                        <tr><td colspan="4" class="empty">هیچ ئیشێک تۆمار نەکراوە</td></tr>
                    <?php else: foreach ($topTreatments as $i => $t): ?>
                        <tr>
                            <td class="i"><?= $i + 1 ?></td>
                            <td><?= dsEsc($t['treatment_name']) ?></td>
                            <td class="n"><?= dsMoney($t['cnt']) ?></td>
                            <td class="n"><?= dsMoney($t['amt']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="is-total">
                            <td></td><td>کۆ</td>
                            <td class="n"><?= dsMoney($treatmentCount) ?></td>
                            <td class="n"><?= dsMoney($treatmentAmount) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ds-card">
            <h2><span><i class="fas fa-list-alt"></i> نەخۆشەکان</span><small><?= dsMoney(count($patientRows)) ?> کەیس</small></h2>
            <div class="ds-scroll">
                <table class="ds-tbl">
                    <thead><tr>
                        <th class="i">#</th><th>نەخۆش</th><th>بەروار</th><th>کات</th><th>ئیش</th>
                        <th>حسابکراو</th><th>وەرگیراو</th><th>ماوە</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$patientRows): ?>
                        <tr><td colspan="8" class="empty">هیچ نەخۆشێک نییە</td></tr>
                    <?php else: foreach ($patientRows as $i => $r): ?>
                        <?php $atTs = !empty($r['last_at']) ? strtotime($r['last_at']) : null; ?>
                        <tr>
                            <td class="i"><?= $i + 1 ?></td>
                            <td><a href="<?= APP_URL ?>/cases/view.php?id=<?= (int)$r['case_id'] ?>"><?= dsEsc($r['full_name']) ?></a></td>
                            <td class="n"><?= $atTs ? dsEsc(date('Y-m-d', $atTs)) : '' ?></td>
                            <td class="n"><?= $atTs ? dsEsc(date('H:i', $atTs)) : '' ?></td>
                            <td class="n"><?= dsMoney($r['treatments']) ?></td>
                            <td class="n"><?= dsMoney($r['billed']) ?></td>
                            <td class="n pos"><?= dsMoney($r['received']) ?></td>
                            <td class="n <?= $r['left'] > 0 ? 'neg' : '' ?>"><?= dsMoney($r['left']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="is-total">
                            <td></td><td colspan="4">کۆ</td>
                            <td class="n"><?= dsMoney($patientTotals['billed']) ?></td>
                            <td class="n pos"><?= dsMoney($patientTotals['paid']) ?></td>
                            <td class="n neg"><?= dsMoney($patientTotals['left']) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    var box = document.getElementById('ds-att');
    if (!box) return;
    box.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-action]');
        if (!btn) return;
        btn.disabled = true;
        fetch('<?= APP_URL ?>/ajax/doctor_attendance.php', {
            method: 'POST',
            body: new URLSearchParams({ action: btn.dataset.action, doctor_id: box.dataset.doctorId })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success) { location.reload(); return; }
            alert(data.error || 'هەڵەیەک ڕوویدا');
            btn.disabled = false;
        }).catch(function () {
            alert('هەڵەیەک ڕوویدا');
            btn.disabled = false;
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
