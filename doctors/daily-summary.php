<?php
/**
 * پوختەی ڕۆژانەی دکتۆر — one day of a doctor's work and money, on screen.
 *
 * Every figure comes from _daily_summary_data.php, which daily-summary-pdf.php
 * also uses — so the PDF is always exactly what this page shows.
 */

require_once __DIR__ . '/_daily_summary_data.php';
require_once __DIR__ . '/../classes/Settings.php';

// The look: «کلاسیک» (the tables) or «مۆدێرن» (cards). Each user picks
// their own with the switch on the page, and it stays until they change it.
$dsStyleKey = 'daily_summary_style_u' . $viewerUserId;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ds_style'])) {
    Csrf::require();
    Settings::set($dsStyleKey, $_POST['ds_style'] === 'modern' ? 'modern' : 'classic', 'text', 'پوختەی ڕۆژانە: شێوازی پیشاندان (هی یەک بەکارهێنەر)');
    header('Location: ' . $dsLockSelf);
    exit;
}
$dsModern = Settings::get($dsStyleKey, 'classic') === 'modern';

// ڕێژەی پیشاندان: only the admin sets how much of their money a doctor is
// shown — this doctor alone, or every doctor (which clears their own ones).
$dsCanSetPct = $authGate->isAdmin() && in_array($docUserId, array_map('intval', array_column($doctorList, 'id')), true);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ds_view_pct'])) {
    Csrf::require();
    if (!$dsCanSetPct) {
        http_response_code(403);
        exit('Forbidden');
    }
    $pct = (int)max(1, min(100, round((float)$_POST['ds_view_pct'])));
    $all = ($_POST['ds_view_scope'] ?? '') === 'all';
    if ($all) {
        $db->query("DELETE FROM settings WHERE setting_key LIKE 'daily\_summary\_view\_pct\_d%'");
        Settings::invalidate();
        Settings::set(DS_VIEW_PCT_KEY, (string)$pct, 'text', 'پوختەی ڕۆژانە: ڕێژەی ئەو پارەیەی هەموو دکتۆرێک لە پوختەی خۆیدا دەیبینێت (تەنها پیشاندان)');
    } else {
        Settings::set(DS_VIEW_PCT_KEY . '_d' . $docUserId, (string)$pct, 'text', 'پوختەی ڕۆژانە: ڕێژەی ئەو پارەیەی ئەم دکتۆرە لە پوختەی خۆیدا دەیبینێت (تەنها پیشاندان)');
    }
    AuditLog::log('daily_summary_view_percent', 'users', $all ? null : $docUserId, ['percent' => $dsViewPct], ['percent' => $pct, 'scope' => $all ? 'all' : 'doctor']);
    header('Location: ' . $dsLockSelf);
    exit;
}

$pageTitle = 'پوختەی ڕۆژانەم';
$breadcrumb = [
    ['title' => 'دکتۆرەکان'],
    ['title' => 'پوختەی ڕۆژانەم'],
];

require_once __DIR__ . '/../includes/header.php';

$docQs = $isAdminView ? '&doctor=' . $docUserId . ($dsAsDoctor ? '&as_doctor=1' : '') : '';
/** The same sheet with the admin's «as the doctor sees it» on or off. */
$dsAsDoctorUrl = fn(bool $on) => '?' . http_build_query(array_merge(array_diff_key($_GET, ['as_doctor' => 1]), $on ? ['as_doctor' => 1] : []));
/** Link to the same sheet for another period. */
$rangeQs = fn(string $f, string $t) => '?from=' . urlencode($f) . '&to=' . urlencode($t) . $docQs;
$pdfUrl = APP_URL . '/doctors/daily-summary-pdf.php' . $rangeQs($from, $to);
// the list behind a card or a row (daily-summary-detail.php), «&kind=» added by the script
$detailUrl = APP_URL . '/doctors/daily-summary-detail.php' . $rangeQs($from, $to);

/** One compact key/value table — the same row lists the PDF prints; a row with a list behind it opens on a click. */
function dsRows(array $rows)
{
    foreach ($rows as $row) {
        [$label, $value, $style] = $row;
        $kind = ds_detail_kind($label);
        echo '<tr class="' . ($style === 'total' ? 'is-total' : '') . ($kind ? ' is-link' : '') . '"'
            . ($kind ? ' data-ds-detail="' . dsEsc($kind) . '" tabindex="0" title="کلیک بکە بۆ وردەکاری"' : '') . '>'
            . '<td>' . dsEsc($label) . (($row[3] ?? '') !== '' ? ' <span class="ds-note">' . dsEsc($row[3]) . '</span>' : '') . '</td>'
            . '<td class="v ' . ($style === 'pos' || $style === 'neg' ? $style : '') . '">' . dsEsc($value) . '</td>'
            . '</tr>';
    }
}

/** «هاتنەژوورەوە / چوونەدەرەوە» — only on today's own sheet. */
$dsAttendance = function () use ($isToday, $docUserId, $viewerUserId, $isAdminView, $attendance) {
    if (!($isToday && ($docUserId === $viewerUserId || $isAdminView))) {
        return;
    }
    ?>

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
    <?php
};

/** The patients' table, the same in both looks. */
$dsPatients = function () use ($patientRows, $patientTotals) {
    ?>
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
    <?php
};
?>

<style>
.ds{max-width:1400px;margin:0 auto 28px;color:#172b3a;direction:rtl}
.ds-bar{background:#244b68;color:#fff;padding:10px 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.ds-bar h1{margin:0;font-size:17px;font-weight:700}
.ds-bar .day{color:#cfe0ec;font-size:12.5px}
.ds-pdf{margin-inline-start:auto;background:#fff;color:#244b68;padding:6px 14px;font-size:12px;font-weight:700;text-decoration:none;border-radius:3px}
.ds-pdf:hover{background:#e3edf4}
.ds-pdf.is-modern{margin-inline-start:0;background:linear-gradient(120deg,#e0f0fb,#d5f5f2);color:#0b5f8a}
.ds-pdf.is-modern:hover{background:#fff}
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

/* ڕێژەی پیشاندان — the admin's control (never shown to a doctor) */
.ds-pct{margin-top:10px;border:1px solid #c7d7e6;background:#f4f8fc;border-radius:10px;padding:8px 12px;display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:12.5px;color:#334155}
.ds-pct > i{color:#0078d4}
.ds-pct b{color:#0f172a}
.ds-pct input{width:70px;height:30px;border:1px solid #9cafbd;border-radius:6px;padding:2px 6px;font:inherit;text-align:center}
.ds-pct button{height:30px;border:1px solid #0078d4;background:#0078d4;color:#fff;border-radius:6px;padding:0 11px;font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.ds-pct button.alt{background:#fff;color:#0078d4}
.ds-pct a{margin-inline-start:auto;color:#0067c0;font-weight:700;text-decoration:none}
.ds-pct small{flex-basis:100%;color:#64748b}
.ds-pct.is-preview{background:#fff7e6;border-color:#f0c36d}

/* a row or a card with a list behind it */
.ds [data-ds-detail]{cursor:pointer}
.ds-kv tr.is-link:hover td,.ds-tbl tr.is-link:hover td{background:#eef6fd}
.ds-kv tr.is-link td:first-child::after,.ds-tbl tr.is-link td:nth-child(2)::after{content:'\203A';color:#9cb3c4;margin-inline-start:6px;font-weight:700}
.ds [data-ds-detail]:focus-visible{outline:2px solid #0078d4;outline-offset:-2px}

/* the details window */
.dsd{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px}
.dsd[hidden]{display:none}
.dsd-box{background:#fff;border-radius:16px;width:min(980px,100%);max-height:calc(100vh - 32px);display:flex;flex-direction:column;
    box-shadow:0 24px 60px rgba(15,23,42,.35);direction:rtl;overflow:hidden}
.dsd-head{display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(120deg,var(--win-accent,#0078d4),#0e9f9a);color:#fff}
.dsd-head h3{margin:0;font-size:16px;font-weight:800}
.dsd-head small{color:#dff4f7;font-size:12px}
.dsd-head button{margin-inline-start:auto;border:0;background:rgba(255,255,255,.2);color:#fff;width:32px;height:32px;border-radius:9px;cursor:pointer;font-size:15px}
.dsd-head button:hover{background:rgba(255,255,255,.35)}
.dsd-body{overflow:auto;padding:0}
.dsd-body .ds-tbl th{position:sticky;top:0;z-index:1}
.dsd-body .ds-tbl th.n{text-align:left}
.dsd-body .ds-tbl td a{color:#0067c0;font-weight:700;text-decoration:none}
.dsd-msg{padding:28px;text-align:center;color:#64748b}
.dsd-note{padding:8px 16px;font-size:12px;color:#64748b;background:#f8fafc;border-top:1px solid #eef2f5}

/* the switch between the two looks */
.ds-style{margin:0;display:inline-flex;border:1px solid #9fbbd0;border-radius:999px;overflow:hidden}
.ds-style button{background:transparent;color:#fff;border:0;padding:5px 12px;font:inherit;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px}
.ds-style button.on{background:#fff;color:#244b68}
.ds-style button:not(.on):hover{background:rgba(255,255,255,.15)}

/* «مۆدێرن»: the header in the system's own blue running into a medical teal */
.ds.is-modern .ds-bar{background:linear-gradient(120deg,var(--win-accent,#0078d4),#0e9f9a);border-radius:16px 16px 0 0;padding:14px 16px;gap:14px}
.ds.is-modern .ds-bar .ds-who{width:50px;height:50px;border:2px solid rgba(255,255,255,.8);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;flex:none}
.ds.is-modern .ds-bar h1{font-size:21px;font-weight:800}
.ds.is-modern .ds-bar .day{color:#dff4f7;font-size:13.5px;display:block;margin-top:2px}
.ds.is-modern .ds-bar .ds-pdf{border-radius:999px;color:var(--win-accent-dark,#005a9e)}
.ds.is-modern .ds-lockform button,.ds.is-modern .ds-style{border-color:rgba(255,255,255,.6);border-radius:999px}
.ds.is-modern .ds-style button.on{color:var(--win-accent-dark,#005a9e)}
.ds.is-modern .ds-filter{border-radius:0 0 16px 16px;border-color:#e2e8f0;background:#f5fafd}
.ds.is-modern .ds-filter input,.ds.is-modern .ds-filter select,.ds.is-modern .ds-filter button,.ds.is-modern .ds-days a{border-radius:8px}
.ds.is-modern .ds-filter button{background:var(--win-accent,#0078d4);border-color:var(--win-accent,#0078d4)}
.ds.is-modern .ds-days a.on{background:#e0f0fb;border-color:var(--win-accent,#0078d4);color:var(--win-accent-dark,#005a9e)}
@media(max-width:640px){.ds.is-modern .ds-bar h1{font-size:18px}.ds.is-modern .ds-bar .ds-who{width:42px;height:42px;font-size:20px}}
</style>

<div class="ds<?= $dsModern ? ' is-modern' : '' ?>">

    <div class="ds-bar">
        <?php if ($dsModern): ?>
        <span class="ds-who"><i class="fas fa-user-md"></i></span>
        <div>
            <h1>پوختەی دکتۆر · <?= dsEsc($docName) ?></h1>
            <span class="day"><i class="far fa-calendar-alt"></i> بەروار: <bdi><?= dsEsc($rangeLabel) ?></bdi><?= $isToday ? ' · ئەمڕۆ' : '' ?></span>
        </div>
        <?php else: ?>
        <h1><?= dsEsc($docName) ?></h1>
        <span class="day"><bdi><?= dsEsc($rangeLabel) ?></bdi><?= $isToday ? ' · ئەمڕۆ' : '' ?></span>
        <?php endif; ?>
        <form method="post" class="ds-style" title="شێوازی پیشاندان — هەڵبژاردنەکەت دەمێنێتەوە">
            <?= Csrf::field() ?>
            <button type="submit" name="ds_style" value="classic" class="<?= $dsModern ? '' : 'on' ?>"><i class="fas fa-table"></i> کلاسیک</button>
            <button type="submit" name="ds_style" value="modern" class="<?= $dsModern ? 'on' : '' ?>"><i class="fas fa-th-large"></i> مۆدێرن</button>
        </form>
        <a class="ds-pdf" href="<?= dsEsc($pdfUrl) ?>" target="_blank" title="PDF بە خشتە">
            <i class="fas fa-file-pdf"></i> ڕاپۆرتی PDF
        </a>
        <a class="ds-pdf is-modern" href="<?= dsEsc($pdfUrl . '&style=modern') ?>" target="_blank" title="PDF بە کارت و ئایکۆن، وەک شێوازی مۆدێرن">
            <i class="fas fa-file-pdf"></i> PDF ـی مۆدێرن
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
        <?php if ($dsAsDoctor): ?><input type="hidden" name="as_doctor" value="1"><?php endif; ?>
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

    <?php if ($dsCanSetPct): ?>
    <form method="post" class="ds-pct<?= $dsAsDoctor ? ' is-preview' : '' ?>">
        <?= Csrf::field() ?>
        <i class="fas fa-user-secret"></i>
        <?php if ($dsAsDoctor): ?>
        <span>ئێستا وەک دکتۆرەکە دەیبینیت: تەنها <b><?= dsMoney($dsViewPct) ?>%</b> ی پارەکەی.</span>
        <?php else: ?>
        <span>دکتۆر لە پوختەی خۆیدا <b><?= dsMoney($dsViewPct) ?>%</b> ی پارەکەی دەبینێت<?= $dsViewPct < 100 && !ds_view_percent_is_own($docUserId) ? ' (ڕێژەی هەموو دکتۆرەکان)' : '' ?>.</span>
        <?php endif; ?>
        <label>ڕێژە <input type="number" name="ds_view_pct" min="1" max="100" step="1" value="<?= (int)round($dsViewPct) ?>" required> %</label>
        <button type="submit" name="ds_view_scope" value="doctor">بۆ ئەم دکتۆرە</button>
        <button type="submit" name="ds_view_scope" value="all" class="alt" onclick="return confirm('ئەم ڕێژەیە بۆ هەموو دکتۆرەکان دابنرێت؟ ڕێژەی تایبەتی هەر دکتۆرێک لادەچێت.')">بۆ هەموو دکتۆرەکان</button>
        <?php if ($dsAsDoctor): ?>
        <a href="<?= dsEsc($dsAsDoctorUrl(false)) ?>"><i class="fas fa-eye"></i> ژمارەی تەواو ببینە</a>
        <?php else: ?>
        <a href="<?= dsEsc($dsAsDoctorUrl(true)) ?>"><i class="fas fa-user-md"></i> وەک دکتۆرەکە ببینە</a>
        <?php endif; ?>
        <small>تەنها پیشاندانە: هەموو بڕە پارەکانی ئەم پەیجە و PDF ـەکەی بەم ڕێژەیە کەم دەکرێنەوە، ژمارە و کاتەکان وەک خۆیان دەمێننەوە. هیچ شتێک لە حساباتدا ناگۆڕێت، و ئەدمین و ژمێریار هەمیشە ژمارەی تەواو دەبینن.
            هەموو دکتۆرەکان پێکەوە: <a href="<?= APP_URL ?>/admin/settings.php?tab=doctor_income" style="margin:0">ڕێکخستنەکان ← داهاتی دکتۆر</a></small>
    </form>
    <?php endif; ?>

    <?php if ($visitsLinkBroken): ?>
    <div class="ds-warn">
        <i class="fas fa-exclamation-triangle"></i>
        ئەم هەژمارە بە تۆماری دکتۆرەوە نەبەستراوە، بۆیە سەردانەکان سفرن — داوا لە بەڕێوەبەر بکە بیبەستێتەوە.
    </div>
    <?php endif; ?>

    <?php if ($dsModern): require __DIR__ . '/_daily_summary_modern.php'; else: ?>
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
                <tr class="sub"><td colspan="2"><i class="fas fa-pills"></i> ناردن بۆ دەرمانخانە <span class="ds-note">— داهاتەکەی هی سەنتەرە</span></td></tr>
                <?php dsRows($dsPharmacyRows); ?>
            </table>
        </div>

        <div class="ds-card">
            <h2><span><i class="fas fa-stopwatch"></i> کات</span></h2>
            <table class="ds-kv"><?php dsRows($dsTimeRows); ?></table>
            <?php $dsAttendance(); ?>
        </div>
    </div>

    <div class="ds-grid g2">
        <div class="ds-card">
            <h2><span><i class="fas fa-tooth"></i> جۆری ئیشەکان</span><small><?= dsMoney($treatmentCount) ?></small></h2>
            <div class="ds-scroll">
                <table class="ds-tbl">
                    <thead><tr><th class="i">#</th><th>جۆر</th><th>ژمارە</th><th>بڕ</th><th>وەرگیراو</th></tr></thead>
                    <tbody>
                    <?php if (!$topTreatments): ?>
                        <tr><td colspan="5" class="empty">هیچ ئیشێک تۆمار نەکراوە</td></tr>
                    <?php else: foreach ($topTreatments as $i => $t): ?>
                        <tr class="is-link" data-ds-detail="<?= dsEsc('treatment:' . $t['treatment_name']) ?>" tabindex="0" title="کلیک بکە بۆ وردەکاری">
                            <td class="i"><?= $i + 1 ?></td>
                            <td><?= dsEsc($t['treatment_name']) ?></td>
                            <td class="n"><?= dsMoney($t['cnt']) ?></td>
                            <td class="n"><?= dsMoney($t['amt']) ?></td>
                            <td class="n pos"><?= dsMoney($t['received'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="is-total">
                            <td></td><td>کۆ</td>
                            <td class="n"><?= dsMoney($treatmentCount) ?></td>
                            <td class="n"><?= dsMoney($treatmentAmount) ?></td>
                            <td class="n pos"><?= dsMoney($treatmentReceived ?? 0) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ds-card">
            <h2><span><i class="fas fa-list-alt"></i> نەخۆشەکان</span><small><?= dsMoney(count($patientRows)) ?> کەیس</small></h2>
            <?php $dsPatients(); ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<div class="dsd" id="dsDetail" hidden role="dialog" aria-modal="true" aria-labelledby="dsDetailTitle">
    <div class="dsd-box">
        <div class="dsd-head">
            <i class="fas fa-list-ul"></i>
            <div><h3 id="dsDetailTitle"></h3><small id="dsDetailPeriod"></small></div>
            <button type="button" data-ds-close title="داخستن (Esc)"><i class="fas fa-times"></i></button>
        </div>
        <div class="dsd-body" id="dsDetailBody"></div>
        <div class="dsd-note" id="dsDetailNote" hidden></div>
    </div>
</div>

<script>
// A card or a row with a list behind it: the list in a window (daily-summary-detail.php)
(function () {
    var url = <?= json_encode($detailUrl) ?>;
    var win = document.getElementById('dsDetail');
    var body = document.getElementById('dsDetailBody');
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
    var num = function (s) { return parseFloat(String(s).replace(/,/g, '')) || 0; };
    var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
    var seq = 0;
    function close() { win.hidden = true; document.body.style.overflow = ''; }
    function open(kind, label) {
        var mine = ++seq;
        win.hidden = false;
        document.body.style.overflow = 'hidden';
        document.getElementById('dsDetailTitle').textContent = label || '';
        document.getElementById('dsDetailPeriod').textContent = '';
        document.getElementById('dsDetailNote').hidden = true;
        body.innerHTML = '<div class="dsd-msg"><i class="fas fa-spinner fa-spin"></i> باردەکرێت…</div>';
        fetch(url + '&kind=' + encodeURIComponent(kind), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (mine !== seq) return;
                if (!d.success) { body.innerHTML = '<div class="dsd-msg">' + esc(d.message || 'هەڵەیەک ڕوویدا') + '</div>'; return; }
                document.getElementById('dsDetailTitle').textContent = d.title;
                document.getElementById('dsDetailPeriod').textContent = d.period + ' · ' + d.rows.length + ' دانە';
                if (d.note) { var n = document.getElementById('dsDetailNote'); n.textContent = d.note; n.hidden = false; }
                if (!d.rows.length) { body.innerHTML = '<div class="dsd-msg">هیچ شتێک لەم ماوەیەدا نییە</div>'; return; }
                var sums = d.cols.map(function () { return 0; });
                var html = '<table class="ds-tbl"><thead><tr><th class="i">#</th>'
                    + d.cols.map(function (c) { return '<th' + (c.n ? ' class="n"' : '') + '>' + esc(c.t) + '</th>'; }).join('') + '</tr></thead><tbody>';
                d.rows.forEach(function (r, i) {
                    html += '<tr><td class="i">' + (i + 1) + '</td>' + r.map(function (cell, k) {
                        var t = cell && typeof cell === 'object' ? cell.t : cell;
                        if (d.cols[k].sum) sums[k] += num(t);
                        var inner = cell && typeof cell === 'object' && cell.href ? '<a href="' + esc(cell.href) + '" target="_blank">' + esc(t) + '</a>' : esc(t);
                        return '<td' + (d.cols[k].n ? ' class="n"' : '') + '>' + inner + '</td>';
                    }).join('') + '</tr>';
                });
                if (d.cols.some(function (c) { return c.sum; })) {
                    html += '<tr class="is-total"><td></td>' + d.cols.map(function (c, k) {
                        return '<td' + (c.n ? ' class="n"' : '') + '>' + (c.sum ? fmt(sums[k]) : (k === 0 ? 'کۆ' : '')) + '</td>';
                    }).join('') + '</tr>';
                }
                body.innerHTML = html + '</tbody></table>';
            })
            .catch(function () { if (mine === seq) body.innerHTML = '<div class="dsd-msg">هەڵەیەک ڕوویدا — پەڕەکە نوێ بکەرەوە</div>'; });
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-ds-close]') || e.target === win) { close(); return; }
        var el = e.target.closest('[data-ds-detail]');
        if (!el || e.target.closest('a')) return;
        var label = el.querySelector('.dsm-l, td:first-child:not(.i), td:nth-child(2), span:not(.dsm-ic)');
        open(el.dataset.dsDetail, label ? label.textContent.trim() : '');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !win.hidden) { close(); return; }
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-ds-detail]')) {
            e.preventDefault();
            e.target.click();
        }
    });
})();

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
