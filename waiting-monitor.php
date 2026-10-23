<?php
/**
 * Waiting Monitor — Big-screen display for the waiting hall
 * شاشەی چاوەڕوانی — کارتی دکتۆرەکان + ژمارەی ژوور + نەخۆشی ئێستا و داهاتوو
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

// The monitor shows patient names — require a logged-in session
// (use a dedicated kiosk/receptionist account on the lobby screen).
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();

// Initial roster (for first-paint skeleton — the JS then keeps it fresh)
$doctors = $db->fetchAll(
    "SELECT id, full_name, room_number, room_number_2
     FROM users
     WHERE role='doctor' AND is_active=1
     ORDER BY (room_number IS NULL OR room_number = '') ASC, room_number ASC, full_name"
);

$clinicName = defined('CLINIC_NAME') ? CLINIC_NAME : (defined('APP_NAME') ? APP_NAME : 'کلینیک');
?>
<!doctype html>
<html lang="ku" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>شاشەی چاوەڕوانی — <?= htmlspecialchars($clinicName) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<link rel="preload" href="<?= APP_URL ?>/fonts/NRT%20Font/NRT-Reg.woff" as="font" type="font/woff" crossorigin>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
<style>
    :root {
        --bg: #0b1222;
        --card: linear-gradient(145deg, #152039 0%, #0f1a30 100%);
        --card-border: rgba(148, 163, 184, 0.15);
        --text: #e2e8f0;
        --muted: #94a3b8;
        --accent: #38bdf8;
        --accent-2: #22c55e;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0;
        background: radial-gradient(circle at 20% 0%, #1e293b 0%, var(--bg) 55%);
        color: var(--text);
        font-family: var(--app-font-family);
        overflow: hidden;
    }
    .monitor-root { display: flex; flex-direction: column; height: 100vh; }

    /* Top bar */
    .mon-top {
        display: flex; justify-content: space-between; align-items: center;
        padding: 14px 28px; background: rgba(15, 23, 42, 0.7);
        border-bottom: 1px solid var(--card-border); backdrop-filter: blur(8px);
    }
    .mon-top .title { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 700; }
    .mon-top .title i { color: var(--accent); }
    .mon-top .clock { display: flex; align-items: center; gap: 16px; font-variant-numeric: tabular-nums; }
    .mon-top .time  { font-size: 32px; font-weight: 800; color: #fff; }
    .mon-top .date  { font-size: 14px; color: var(--muted); }

    /* Grid */
    .mon-grid {
        flex: 1; display: grid; gap: 14px; padding: 14px 22px;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        align-content: flex-start; overflow: hidden;
    }

    /* Card */
    .doc-card {
        background: var(--card); border: 1px solid var(--card-border);
        border-radius: 18px; padding: 14px 16px 12px;
        display: flex; flex-direction: column; justify-content: space-between;
        position: relative; overflow: hidden; min-height: 220px;
        box-shadow: 0 8px 22px rgba(0,0,0,.25);
        transition: border-color .25s, box-shadow .25s;
    }
    .doc-card.has-current { border-color: rgba(34, 197, 94, .55); box-shadow: 0 0 0 2px rgba(34,197,94,.18), 0 8px 22px rgba(0,0,0,.35); }
    .doc-card.idle       { opacity: .65; }
    .doc-card.flash      { animation: cardFlash 1.3s ease-in-out 3; }
    @keyframes cardFlash {
        0%, 100% { background: var(--card); }
        50%      { background: linear-gradient(145deg, #14532d 0%, #064e3b 100%); }
    }

    .doc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 8px; }
    .doc-name { font-size: 16px; font-weight: 700; color: var(--accent); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .doc-room {
        background: rgba(56,189,248,.16); color: var(--accent);
        padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 700;
        white-space: nowrap; border: 1px solid rgba(56,189,248,.35);
    }
    .doc-room i { margin-left: 4px; font-size: 11px; }

    .doc-current {
        flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
        text-align: center; padding: 10px 6px; border-radius: 12px;
        background: rgba(255,255,255,.02); border: 1px dashed rgba(148,163,184,.25);
        min-height: 90px; position: relative;
    }
    .doc-current.active {
        background: linear-gradient(145deg, #052e1b, #064e3b);
        border: 1px solid rgba(34,197,94,.6);
        box-shadow: 0 0 0 3px rgba(34,197,94,.12);
    }
    .doc-current.active .pulse {
        position: absolute; inset: 0; border-radius: 12px;
        box-shadow: 0 0 0 0 rgba(34,197,94,.55);
        animation: pulse 1.8s infinite; pointer-events: none;
    }
    @keyframes pulse {
        0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.55); }
        70%  { box-shadow: 0 0 0 12px rgba(34,197,94,0); }
        100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
    }
    .current-label { font-size: 11px; color: var(--muted); margin-bottom: 6px; letter-spacing: .5px; }
    .doc-current.active .current-label { color: #86efac; }
    .current-name { font-size: 26px; font-weight: 800; color: #fff; line-height: 1.15; word-break: break-word; margin: 0; }
    .current-code { font-size: 11px; color: var(--muted); margin-top: 4px; font-family: var(--app-font-family); direction: ltr; }
    .current-room {
        display: inline-flex; align-items: center; gap: 6px;
        margin-top: 8px; padding: 3px 12px; border-radius: 999px;
        background: rgba(14,165,233,.22); color: #7dd3fc;
        font-size: 14px; font-weight: 700;
    }
    /* Two-room doctors: a second patient can be in the other room */
    .current-second {
        margin-top: 10px; padding-top: 8px;
        border-top: 1px dashed rgba(255,255,255,.15);
        font-size: 15px; color: #e2e8f0; font-weight: 600;
    }
    .current-second .room-tag { color: #7dd3fc; font-weight: 700; }
    .current-status {
        position: absolute; top: 6px; inset-inline-start: 8px;
        font-size: 10px; padding: 2px 8px; border-radius: 10px;
        background: rgba(34,197,94,.22); color: #86efac; font-weight: 700;
    }
    .current-status.waiting { background: rgba(245,158,11,.22); color: #fcd34d; }

    .doc-empty { font-size: 14px; color: var(--muted); font-weight: 600; }
    .doc-empty i { color: #475569; margin-left: 4px; }

    .doc-next {
        margin-top: 12px; padding-top: 10px;
        border-top: 1px solid rgba(148,163,184,.2);
        display: flex; flex-direction: column; gap: 8px;
    }
    .doc-next-title { font-size: 13px; color: var(--muted); display: flex; justify-content: space-between; align-items: center; font-weight: 700; }
    .doc-next-title .count {
        background: rgba(56,189,248,.18); color: var(--accent);
        padding: 2px 12px; border-radius: 12px; font-size: 13px; font-weight: 800;
    }
    .next-row {
        font-size: 20px; font-weight: 700; color: #e2e8f0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        padding: 6px 10px; background: rgba(148,163,184,.08); border-radius: 8px;
        display: flex; align-items: center; gap: 10px;
    }
    .next-row .num {
        flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px;
        background: linear-gradient(135deg, #38bdf8, #0891b2); color: #fff;
        border-radius: 50%; font-size: 14px; font-weight: 800;
    }

    /* Ticker */
    .mon-ticker {
        background: linear-gradient(90deg, #0c1a33 0%, #0a0f22 100%);
        border-top: 1px solid var(--card-border);
        padding: 10px 0; overflow: hidden; position: relative;
    }
    .ticker-track {
        display: inline-block; white-space: nowrap; padding-right: 100%;
        animation: tickerScroll 40s linear infinite;
        font-size: 16px; color: #cbd5e1;
    }
    .ticker-track span { margin-inline-end: 60px; }
    .ticker-track i { color: var(--accent); margin-inline-end: 6px; }
    @keyframes tickerScroll {
        0%   { transform: translateX(100%); }
        100% { transform: translateX(-100%); }
    }

    /* Connection pill */
    .conn-pill {
        position: fixed; bottom: 12px; inset-inline-end: 16px;
        background: rgba(15,23,42,.7); padding: 4px 10px; border-radius: 999px;
        font-size: 11px; color: var(--muted);
        display: flex; align-items: center; gap: 6px; z-index: 10;
    }
    .conn-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent-2); box-shadow: 0 0 8px var(--accent-2); }
    .conn-pill.err .conn-dot { background: #ef4444; box-shadow: 0 0 8px #ef4444; }

    @media (max-width: 900px) {
        .mon-top .time { font-size: 24px; }
        .mon-top .title { font-size: 16px; }
        .current-name { font-size: 22px; }
    }

    /* Phone: the kiosk layout is a fixed non-scrolling viewport — allow
       vertical scrolling so doctor cards beyond the first screen are reachable */
    @media (max-width: 767px) {
        body { overflow: auto; }
        .monitor-root { height: auto; min-height: 100vh; }
        .mon-grid { overflow: visible; }
    }
</style>
</head>
<body>
<div class="monitor-root">

    <div class="mon-top">
        <div class="title">
            <i class="fas fa-hospital-user"></i>
            <span>شاشەی چاوەڕوانی — <?= htmlspecialchars($clinicName) ?></span>
        </div>
        <div class="clock">
            <div><div class="date" id="monDate"></div></div>
            <div class="time" id="monTime">--:--:--</div>
        </div>
    </div>

    <div class="mon-grid" id="monGrid">
        <?php foreach ($doctors as $d): ?>
        <div class="doc-card idle" data-doctor-id="<?= (int)$d['id'] ?>">
            <div class="doc-header">
                <div class="doc-name">د. <?= htmlspecialchars($d['full_name']) ?></div>
                <?php if (!empty($d['room_number'])): ?>
                <div class="doc-room"><i class="fas fa-door-open"></i> ژوور <?= htmlspecialchars($d['room_number']) ?><?= !empty($d['room_number_2']) ? ' / ' . htmlspecialchars($d['room_number_2']) : '' ?></div>
                <?php endif; ?>
            </div>
            <div class="doc-current" data-current-slot>
                <div class="doc-empty"><i class="fas fa-bed"></i> چاوەڕوانی نییە</div>
            </div>
            <div class="doc-next" data-next-slot style="display:none;"></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mon-ticker">
        <div class="ticker-track">
            <span><i class="fas fa-heart-pulse"></i> بەخێرهاتنی <?= htmlspecialchars($clinicName) ?></span>
            <span><i class="fas fa-user-shield"></i> تکایە چاوەڕێ بکەن تا ناوتان بانگ دەکرێت</span>
            <span><i class="fas fa-tooth"></i> سیستەمی زیرەکی کلینیکی ددان</span>
            <span><i class="fas fa-bell"></i> کاتێک ناوت لە شاشە دەردەکەوێت، تکایە بەرەو ژووری دکتۆر بڕۆ</span>
        </div>
    </div>
</div>

<div class="conn-pill" id="connPill">
    <span class="conn-dot"></span>
    <span id="connText">پەیوەندی کراوە</span>
</div>

<script>
const REFRESH_MS = 4000;
const API = '<?= APP_URL ?>/api/waiting-monitor.php';
const KU_DIGITS = { '0':'٠','1':'١','2':'٢','3':'٣','4':'٤','5':'٥','6':'٦','7':'٧','8':'٨','9':'٩' };
const toKu = s => String(s).replace(/\d/g, d => KU_DIGITS[d]);

// Track which patient is currently displayed per doctor so we can detect changes
const lastCurrent = {};
let firstLoad = true;

// ── Clock ──
function tickClock() {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2,'0');
    const mm = String(now.getMinutes()).padStart(2,'0');
    const ss = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('monTime').textContent = toKu(`${hh}:${mm}:${ss}`);
    try {
        const dateStr = now.toLocaleDateString('ku-IQ', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
        document.getElementById('monDate').textContent = dateStr;
    } catch(e) {
        document.getElementById('monDate').textContent = now.toISOString().slice(0, 10);
    }
}
setInterval(tickClock, 1000);
tickClock();

// ── Audio chime (Web Audio API) ──
let audioCtx = null;
function chime() {
    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        [880, 1200].forEach((freq, i) => {
            const osc  = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, audioCtx.currentTime + i*0.25);
            gain.gain.exponentialRampToValueAtTime(0.25, audioCtx.currentTime + i*0.25 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + i*0.25 + 0.22);
            osc.connect(gain); gain.connect(audioCtx.destination);
            osc.start(audioCtx.currentTime + i*0.25);
            osc.stop(audioCtx.currentTime + i*0.25 + 0.25);
        });
    } catch (e) { /* ignore */ }
}
// Unlock audio on first user interaction (browsers require this)
document.addEventListener('click', () => {
    if (!audioCtx) {
        try { audioCtx = new (window.AudioContext||window.webkitAudioContext)(); } catch(e){}
    }
}, { once: true });

// ── Rendering ──
function renderCard(card, doc) {
    const currentSlot = card.querySelector('[data-current-slot]');
    const nextSlot    = card.querySelector('[data-next-slot]');
    const cur  = doc.current;
    const next = doc.next || [];

    card.classList.toggle('has-current', !!cur);
    card.classList.toggle('idle',        !cur);

    if (cur) {
        const isActive = ['in_progress','in_treatment','called'].includes(cur.status);
        const statusLabel = ({
            in_progress : 'لە چارەسەردا',
            in_treatment: 'لە چارەسەردا',
            called      : 'بانگکراو',
            waiting     : 'چاوەڕوان',
            on_hold     : 'ڕاگیراو'
        })[cur.status] || cur.status;

        // Second concurrent patient (doctor working in two rooms)
        const cur2 = doc.current2;
        currentSlot.className = 'doc-current' + (isActive ? ' active' : '');
        currentSlot.innerHTML = `
            ${isActive ? '<span class="pulse"></span>' : ''}
            <span class="current-status ${isActive ? '' : 'waiting'}">${statusLabel}</span>
            <div class="current-label">${isActive ? 'ئێستا لە ژوورەوە' : 'نۆرەی داهاتوو'}</div>
            <h2 class="current-name">${escapeHtml(cur.patient_name)}</h2>
            ${cur.patient_code ? `<div class="current-code">${escapeHtml(cur.patient_code)}</div>` : ''}
            ${isActive && cur.room ? `<div class="current-room"><i class="fas fa-door-open"></i> ژووری ${toKu(escapeHtml(cur.room))}</div>` : ''}
            ${cur2 ? `<div class="current-second"><i class="fas fa-user"></i> ${escapeHtml(cur2.patient_name)} ${cur2.room ? `— <span class="room-tag"><i class="fas fa-door-open"></i> ژووری ${toKu(escapeHtml(cur2.room))}</span>` : ''}</div>` : ''}
        `;
    } else {
        currentSlot.className = 'doc-current';
        currentSlot.innerHTML = '<div class="doc-empty"><i class="fas fa-bed"></i> چاوەڕوانی نییە</div>';
    }

    if (next.length > 0) {
        nextSlot.style.display = '';
        nextSlot.innerHTML = `
            <div class="doc-next-title"><span>دواتر</span><span class="count">${toKu(doc.queue_count || next.length)}</span></div>
            ${next.map((n, i) => `<div class="next-row"><span class="num">${toKu(i + 1)}</span> ${escapeHtml(n.patient_name)}</div>`).join('')}
        `;
    } else {
        nextSlot.style.display = 'none';
    }
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function refresh() {
    const pill = document.getElementById('connPill');
    const pillText = document.getElementById('connText');
    try {
        const r = await fetch(API + '?t=' + Date.now());
        const data = await r.json();
        if (!data || !Array.isArray(data.doctors)) throw new Error('bad payload');
        pill.classList.remove('err');
        pillText.textContent = 'پەیوەندی کراوە';

        let changed = false;
        data.doctors.forEach(doc => {
            const card = document.querySelector(`.doc-card[data-doctor-id="${doc.id}"]`);
            if (!card) return;
            // Key covers both rooms so a patient entering the second room also chimes
            const curId = (doc.current ? doc.current.id : '') + '|' + (doc.current2 ? doc.current2.id : '');
            const prev = lastCurrent[doc.id];
            if (!firstLoad && prev !== undefined && prev !== curId && (doc.current || doc.current2)) {
                card.classList.remove('flash');
                void card.offsetWidth; // restart animation
                card.classList.add('flash');
                changed = true;
            }
            lastCurrent[doc.id] = curId;
            renderCard(card, doc);
        });
        if (!firstLoad && changed) chime();
        firstLoad = false;
    } catch (e) {
        pill.classList.add('err');
        pillText.textContent = 'پەیوەندی کەوتوو';
    }
}

refresh();
setInterval(refresh, REFRESH_MS);
</script>
</body>
</html>
