<?php
/**
 * دکتۆرەکان — one card per doctor, for a chosen period (this month by default).
 *
 * All the figures come from _doctors_data.php, which doctors/index-pdf.php
 * uses too — so the PDF report is always exactly what these cards show.
 */

require_once __DIR__ . '/_doctors_data.php';

$pageTitle = 'دکتۆرەکان';
$currentPage = 'doctors';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.dc{max-width:1400px;margin:0 auto 32px;color:#0f172a;direction:rtl}

/* ── page banner ── */
.dc-top{position:relative;overflow:hidden;border-radius:20px;padding:22px 24px 48px;color:#fff;
    background:linear-gradient(120deg,#0f172a 0%,#1e3a8a 55%,#4338ca 100%);
    display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap}
.dc-top::before,.dc-top::after{content:'';position:absolute;border-radius:50%;pointer-events:none}
.dc-top::before{width:320px;height:320px;left:-80px;top:-160px;background:radial-gradient(circle,rgba(129,140,248,.45),transparent 70%)}
.dc-top::after{width:220px;height:220px;left:30%;bottom:-140px;background:radial-gradient(circle,rgba(56,189,248,.3),transparent 70%)}
.dc-top > *{position:relative;z-index:1}
.dc-eyebrow{font-size:12px;color:#c7d2fe;letter-spacing:.3px}
.dc-top h1{margin:4px 0 6px;font-size:26px;font-weight:800;color:#fff}
.dc-top .meta{display:flex;gap:14px;flex-wrap:wrap;font-size:12.5px;color:#e0e7ff}
.dc-top .meta b{color:#fff}
.dc-months{display:flex;flex-wrap:wrap;gap:4px;padding:4px;border-radius:999px;background:rgba(255,255,255,.1);backdrop-filter:blur(6px)}
.dc-months a{padding:6px 14px;border-radius:999px;color:#e0e7ff;text-decoration:none;font-size:12.5px;transition:background .15s}
.dc-months a:hover{background:rgba(255,255,255,.12)}
.dc-months a.on{background:#fff;color:#1e3a8a;font-weight:700}

/* ── filters, floating over the banner ── */
.dc-filter{position:relative;z-index:2;margin:-26px 18px 0;background:#fff;border-radius:14px;padding:10px;
    display:flex;flex-wrap:wrap;gap:8px;align-items:center;box-shadow:0 10px 30px -12px rgba(15,23,42,.25)}
.dc-search{position:relative;flex:1;min-width:200px}
.dc-search i{position:absolute;top:50%;inset-inline-start:12px;transform:translateY(-50%);color:#94a3b8;font-size:13px}
.dc-filter input,.dc-filter select{height:38px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:0 12px;font:inherit;font-size:13px;color:#0f172a;transition:border-color .15s,background .15s}
.dc-search input{width:100%;padding-inline-start:34px}
.dc-filter input:focus,.dc-filter select:focus{outline:0;border-color:#6366f1;background:#fff}
.dc-filter button{height:38px;border:0;border-radius:10px;padding:0 18px;font:inherit;font-size:13px;font-weight:600;color:#fff;cursor:pointer;
    background:linear-gradient(135deg,#4f46e5,#7c3aed);box-shadow:0 6px 14px -6px #4f46e5}
.dc-date{display:flex;align-items:center;gap:6px;font-size:12.5px;color:#475569;white-space:nowrap}
.dc-filter .pdf{height:38px;display:inline-flex;align-items:center;gap:6px;padding:0 16px;border-radius:10px;font-size:13px;font-weight:600;
    text-decoration:none;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;transition:background .15s}
.dc-filter .pdf:hover{background:#fee2e2}
.dc-filter .reset{height:38px;width:38px;display:grid;place-items:center;border-radius:10px;background:#f1f5f9;color:#64748b;text-decoration:none}

/* ── clinic KPIs ── */
.dc-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:16px}
.dc-kpi{display:flex;align-items:center;gap:10px;background:#fff;border-radius:14px;padding:12px;min-width:0;
    box-shadow:0 1px 2px rgba(15,23,42,.05),0 4px 14px -8px rgba(15,23,42,.15)}
.dc-kpi .ic{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;flex:0 0 auto;font-size:15px;background:var(--kb);color:var(--k)}
.dc-kpi .tx{min-width:0}
.dc-kpi .tx span{display:block;font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dc-kpi .tx b{display:block;font-size:16.5px;font-weight:800;color:var(--kv,#0f172a);direction:ltr;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.dc-kpi.hl{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff}
.dc-kpi.hl .ic{background:rgba(255,255,255,.18);color:#fff}
.dc-kpi.hl .tx span{color:#e0e7ff}.dc-kpi.hl .tx b{color:#fff}

/* ── doctor cards ── */
.dc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-top:20px}
.dc-card{background:#fff;border-radius:20px;overflow:hidden;display:flex;flex-direction:column;
    box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22);
    transition:transform .2s ease,box-shadow .2s ease;animation:dcIn .45s ease backwards;animation-delay:var(--d,0ms)}
.dc-card:hover{transform:translateY(-4px);box-shadow:0 2px 4px rgba(15,23,42,.06),0 18px 36px -14px rgba(15,23,42,.3)}
.dc-card.is-off{opacity:.78}
@keyframes dcIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

.dc-hero{position:relative;overflow:hidden;display:flex;align-items:center;gap:12px;padding:16px 16px 38px;color:#fff;
    background:linear-gradient(135deg,var(--c1),var(--c2))}
.dc-hero::before,.dc-hero::after{content:'';position:absolute;border-radius:50%;background:rgba(255,255,255,.12);pointer-events:none}
.dc-hero::before{width:150px;height:150px;left:-50px;top:-70px}
.dc-hero::after{width:90px;height:90px;left:90px;bottom:-55px;background:rgba(255,255,255,.08)}
.dc-avatar{position:relative;z-index:1;width:50px;height:50px;border-radius:16px;flex:0 0 auto;display:grid;place-items:center;overflow:hidden;
    font-size:20px;font-weight:800;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.45)}
.dc-avatar img{width:100%;height:100%;object-fit:cover}
.dc-id{position:relative;z-index:1;flex:1;min-width:0}
.dc-id h3{margin:0;font-size:15px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* one line always, so every card's header is the same height */
.dc-tags{display:flex;gap:6px;flex-wrap:nowrap;overflow:hidden;margin-top:6px}
.dc-tag{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,.18);white-space:nowrap}
.dc-tag .dot{width:7px;height:7px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 3px rgba(74,222,128,.25)}
.dc-card.is-off .dc-tag .dot{background:#fca5a5;box-shadow:0 0 0 3px rgba(252,165,165,.25)}

/* collection ring: how much of the month's net work has been paid */
.dc-ring{position:relative;z-index:1;width:58px;height:58px;border-radius:50%;flex:0 0 auto;display:grid;place-items:center;
    background:conic-gradient(#fff calc(var(--p) * 1%),rgba(255,255,255,.25) 0)}
.dc-ring::before{content:'';position:absolute;inset:5px;border-radius:50%;background:linear-gradient(135deg,var(--c1),var(--c2))}
.dc-ring span{position:relative;text-align:center;line-height:1.05}
.dc-ring b{display:block;font-size:13px;font-weight:800}
.dc-ring small{font-size:8.5px;opacity:.85}

.dc-stats{position:relative;z-index:2;display:grid;grid-template-columns:repeat(4,1fr);margin:-24px 14px 0;padding:9px 4px;
    background:#fff;border-radius:14px;box-shadow:0 8px 20px -10px rgba(15,23,42,.25)}
.dc-stats div{text-align:center}
.dc-stats div + div{border-inline-start:1px solid #f1f5f9}
.dc-stats i{font-size:12px;color:var(--c1)}
.dc-stats b{display:block;font-size:17px;font-weight:800;line-height:1.25;color:#0f172a}
.dc-stats span{font-size:10.5px;color:#64748b}

/* money as a straight list, one line per figure */
.dc-list{flex:1;list-style:none;margin:0;padding:12px 14px 4px}
.dc-list li{display:flex;align-items:center;gap:9px;padding:7px 8px;border-bottom:1px dashed #e9eef3}
.dc-list li:last-child{border-bottom:0}
.dc-list .ic{width:26px;height:26px;border-radius:8px;display:grid;place-items:center;flex:0 0 auto;font-size:11px;background:var(--soft);color:var(--c1)}
.dc-list .lb{flex:1;min-width:0;font-size:12.5px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dc-list b{direction:ltr;font-size:13.5px;font-weight:700;color:#0f172a;white-space:nowrap;font-variant-numeric:tabular-nums}
.dc-list li.pos .ic{background:#ecfdf5;color:#059669}.dc-list li.pos b{color:#047857}
.dc-list li.neg .ic{background:#fff7ed;color:#ea580c}.dc-list li.neg b{color:#c2410c}
.dc-list li.debt .ic{background:#fef2f2;color:#dc2626}.dc-list li.debt b{color:#dc2626}
/* the headline figure: after discount */
.dc-list li.net{margin:4px 0;border:0;border-radius:12px;background:var(--soft)}
.dc-list li.net .ic{background:linear-gradient(135deg,var(--c1),var(--c2));color:#fff}
.dc-list li.net .lb{color:#0f172a;font-weight:700}
.dc-list li.net b{font-size:16px;font-weight:800;color:var(--c1)}

.dc-actions{display:flex;gap:6px;padding:8px 14px 14px}
.dc-actions a{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:8px 6px;border-radius:999px;
    font-size:11.5px;font-weight:600;text-decoration:none;white-space:nowrap;background:#f1f5f9;color:#334155;transition:background .15s,transform .15s}
.dc-actions a:hover{background:#e2e8f0}
.dc-actions a.primary{color:#fff;background:linear-gradient(135deg,var(--c1),var(--c2));box-shadow:0 6px 14px -8px var(--c1)}
.dc-actions a.primary:hover{transform:translateY(-1px)}

.dc-empty{margin-top:20px;background:#fff;border-radius:20px;padding:48px;text-align:center;color:#64748b;box-shadow:0 4px 14px -8px rgba(15,23,42,.15)}
.dc-empty i{display:block;margin-bottom:10px;font-size:34px;color:#c7d2fe}

@media(max-width:1100px){.dc-kpis{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){
    .dc-kpis{grid-template-columns:repeat(2,1fr)}
    .dc-top{padding:18px 16px 46px}.dc-top h1{font-size:22px}
    .dc-filter{margin-inline:8px}
}
@media(prefers-reduced-motion:reduce){.dc-card{animation:none}.dc-card:hover,.dc-actions a.primary:hover{transform:none}}
</style>

<div class="dc">

    <section class="dc-top">
        <div>
            <div class="dc-eyebrow">ڕاپۆرتی دکتۆرەکان</div>
            <h1><bdi><?= dsEsc($rangeLabel) ?></bdi></h1>
            <div class="meta">
                <span><i class="fas fa-user-md"></i> <b><?= count($cards) ?></b> دکتۆر</span>
                <span><i class="fas fa-chart-pie"></i> ڕێژەی وەرگیراو <b><bdi dir="ltr"><?= $clinicPct ?>%</bdi></b></span>
            </div>
        </div>
        <nav class="dc-months">
            <a href="<?= dsEsc($rangeQs($prevFrom, $prevTo)) ?>" title="ماوەی پێشوو">← پێشوو</a>
            <?php foreach ($rangePresets as [$pLabel, $pFrom, $pTo]): ?>
            <a href="<?= dsEsc($rangeQs($pFrom, $pTo)) ?>" class="<?= $pFrom === $from && $pTo === $to ? 'on' : '' ?>"><?= dsEsc($pLabel) ?></a>
            <?php endforeach; ?>
            <a href="<?= dsEsc($rangeQs($nextFrom, $nextTo)) ?>" title="ماوەی داهاتوو">داهاتوو →</a>
        </nav>
    </section>

    <form method="get" class="dc-filter">
        <div class="dc-search">
            <i class="fas fa-search"></i>
            <input type="text" name="search" value="<?= dsEsc($search) ?>" placeholder="گەڕان بە ناو یان تەلەفۆن...">
        </div>
        <label class="dc-date">لە <input type="date" name="from" value="<?= dsEsc($from) ?>"></label>
        <label class="dc-date">بۆ <input type="date" name="to" value="<?= dsEsc($to) ?>"></label>
        <select name="status">
            <option value="">هەموو دکتۆرەکان</option>
            <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>چالاک</option>
            <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>ناچالاک</option>
        </select>
        <button type="submit">پیشاندان</button>
        <a class="pdf" href="<?= APP_URL ?>/doctors/index-pdf.php<?= dsEsc($rangeQs($from, $to)) ?>" target="_blank">
            <i class="fas fa-file-pdf"></i> ڕاپۆرتی PDF
        </a>
        <?php if ($search !== '' || $statusFilter !== '' || !$isDefaultRange): ?>
        <a class="reset" href="<?= APP_URL ?>/doctors/index.php" title="پاککردنەوە"><i class="fas fa-redo"></i></a>
        <?php endif; ?>
    </form>

    <?php if (!$cards): ?>
    <div class="dc-empty"><i class="fas fa-user-md"></i> هیچ دکتۆرێک نەدۆزرایەوە</div>
    <?php else: ?>

    <?php
    // [label, value, icon, icon colour, icon background, value colour]
    $kpis = [
        ['سەردان',             dsMoney($totals['visits']),   'fa-users',               '#2563eb', '#eff6ff', ''],
        ['ئیش',                dsMoney($totals['work']),     'fa-tooth',               '#7c3aed', '#f5f3ff', ''],
        ['پشکنین',             dsMoney($totals['exam']),     'fa-stethoscope',         '#0d9488', '#f0fdfa', ''],
        ['کۆی گشتی پارە',      dsMoney($totals['gross']),    'fa-coins',               '#0f172a', '#f1f5f9', ''],
        ['داشکێنراو',          dsMoney($totals['disc']),     'fa-tags',                '#ea580c', '#fff7ed', '#c2410c'],
        ['دوای داشکاندن',      dsMoney($totals['net']),      'fa-wallet',              '', '', ''],
        ['وەرگیراو',           dsMoney($totals['received']), 'fa-hand-holding-usd',    '#059669', '#ecfdf5', '#047857'],
        ['ماوە',               dsMoney($totals['left']),     'fa-hourglass-half',      '#d97706', '#fffbeb', '#b45309'],
        ['گەڕاوەی قەرزی کۆن',  dsMoney($totals['oldDebt']),  'fa-undo-alt',            '#059669', '#ecfdf5', '#047857'],
        ['قەرزی ماوەی گشتی',   dsMoney($totals['debt']),     'fa-exclamation-triangle', '#dc2626', '#fef2f2', '#dc2626'],
    ];
    ?>
    <div class="dc-kpis">
        <?php foreach ($kpis as $i => [$label, $value, $icon, $k, $kb, $kv]): ?>
        <div class="dc-kpi<?= $i === 5 ? ' hl' : '' ?>" style="--k:<?= $k ?>;--kb:<?= $kb ?><?= $kv ? ';--kv:' . $kv : '' ?>">
            <span class="ic"><i class="fas <?= $icon ?>"></i></span>
            <div class="tx"><span><?= dsEsc($label) ?></span><b><?= dsEsc($value) ?></b></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="dc-grid">
        <?php foreach ($cards as $n => $c):
            $m = $c['m'];
            $id = (int)$c['id'];
        ?>
        <article class="dc-card<?= $c['is_active'] ? '' : ' is-off' ?>"
                 style="<?= dcColors($c, $dcPalette, $dcSlot) ?>;--d:<?= min($n, 12) * 45 ?>ms">

            <header class="dc-hero">
                <span class="dc-avatar">
                    <?php if (!empty($c['profile_image'])): ?>
                    <img src="<?= APP_URL ?>/uploads/profiles/<?= dsEsc($c['profile_image']) ?>" alt="">
                    <?php else: ?>
                    <?= dsEsc(dcInitial($c['full_name'])) ?>
                    <?php endif; ?>
                </span>
                <div class="dc-id">
                    <h3><?= dsEsc($c['full_name']) ?></h3>
                    <div class="dc-tags">
                        <span class="dc-tag"><i class="dot"></i><?= $c['is_active'] ? 'چالاک' : 'ناچالاک' ?></span>
                        <?php if (!empty($c['specialization'])): ?>
                        <span class="dc-tag"><?= dsEsc($c['specialization']) ?></span>
                        <?php endif; ?>
                        <?php if ($c['today_appointments'] > 0): ?>
                        <span class="dc-tag" title="مەوعیدی ئەمڕۆ"><i class="fas fa-calendar-check"></i> <?= (int)$c['today_appointments'] ?> مەوعید</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="dc-ring" style="--p:<?= $c['paid_pct'] ?>" title="ڕێژەی وەرگیراو لە ماوەی دوای داشکێنراو">
                    <span><b><?= $c['paid_pct'] ?>%</b><small>وەرگیراو</small></span>
                </div>
            </header>

            <div class="dc-stats">
                <div><i class="fas fa-users"></i><b><?= dsMoney($m['work']['total']) ?></b><span>سەردان</span></div>
                <div><i class="fas fa-tooth"></i><b><?= dsMoney($m['work']['treatment_visits']) ?></b><span>ئیش</span></div>
                <div><i class="fas fa-stethoscope"></i><b><?= dsMoney($m['work']['checkup_visits']) ?></b><span>پشکنین</span></div>
                <div><i class="fas fa-x-ray"></i><b><?= dsMoney($m['xrayCount']) ?></b><span>ئیکسرە</span></div>
            </div>

            <ul class="dc-list">
                <?php foreach (dcMoneyList($c) as [$label, $value, $icon, $style]): ?>
                <li class="<?= $style ?>">
                    <span class="ic"><i class="fas <?= $icon ?>"></i></span>
                    <span class="lb"><?= dsEsc($label) ?></span>
                    <b><?= dsEsc($value) ?></b>
                </li>
                <?php endforeach; ?>
            </ul>

            <footer class="dc-actions">
                <?php if ($isAdmin && $permission->can('page.cashboxes')): ?>
                <a href="<?= APP_URL ?>/admin/cashboxes.php?box=doctor:<?= $id ?>"><i class="fas fa-vault"></i> سندوق</a>
                <?php endif; ?>
                <a class="primary" href="<?= APP_URL ?>/doctors/profile.php?id=<?= $id ?>"><i class="fas fa-user"></i> پرۆفایل</a>
                <?php if ($canDaily): ?>
                <a href="<?= APP_URL ?>/doctors/daily-summary.php?doctor=<?= $id ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="fas fa-file-invoice-dollar"></i> پوختەی ڕۆژانە</a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/cases/index.php?doctor=<?= $id ?>"><i class="fas fa-briefcase-medical"></i> کەیسەکان</a>
            </footer>
        </article>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
