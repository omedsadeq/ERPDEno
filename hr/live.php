<?php
/**
 * ئامادەبوونی ئەمڕۆ — ڕاستەوخۆ. A board for a screen at reception.
 *
 * Opens full screen, without the clinic layout (like the monthly sheet): a
 * slim bar with «گەڕانەوە», today's attendance grouped by state, and the
 * punches of the day as they arrive from the device. It asks hr/api/live.php
 * every few seconds and redraws; hours of the people at work keep counting
 * every second. A click on a person opens that day in the day editor.
 *
 * Today is the current WORK day (08:00 → 08:00), so at 01:00 the evening
 * shift is still on the board.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.employees.view', 'hr.reports.view');

$canOpen = hr_can('hr.reports.view') || hr_can('hr.punches.manage');
$e = 'hr_esc';
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
    <title>ئامادەبوونی ئەمڕۆ — ڕاستەوخۆ</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
body.lv { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: #f1f5f9; }
.lv-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex-shrink: 0; padding: 8px 14px; background: #fff;
    border-bottom: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(15, 23, 42, .06); }
.lv-back { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 9px; background: #0f766e;
    color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; }
.lv-back:hover { background: #115e59; color: #fff; text-decoration: none; }
.lv-title b { font-size: 17px; font-weight: 800; color: #0f172a; }
.lv-title small { display: block; color: #64748b; font-size: 12.5px; }
.lv-clock { font-size: 26px; font-weight: 800; color: #0f172a; direction: ltr; font-variant-numeric: tabular-nums; margin-inline-start: 6px; }
.lv-dev { display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 13px;
    background: #f1f5f9; color: #475569; }
.lv-dev .dot { width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
.lv-dev.ok { background: #dcfce7; color: #15803d; } .lv-dev.ok .dot { animation: lvpulse 1.6s infinite; }
.lv-dev.warn { background: #fef3c7; color: #b45309; } .lv-dev.down { background: #fee2e2; color: #b91c1c; }
.lv-tools { margin-inline-start: auto; display: flex; gap: 6px; }
.lv-tools button { width: 38px; height: 36px; border-radius: 9px; border: 1px solid #e2e8f0; background: #fff; color: #475569; cursor: pointer; font-size: 15px; }
.lv-tools button.on { background: #0f766e; border-color: #0f766e; color: #fff; }
.lv-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; padding: 12px 14px 0; flex-shrink: 0; }
.lv-stat { background: #fff; border-radius: 12px; padding: 10px 14px; border-inline-start: 5px solid var(--c); }
.lv-stat span { font-size: 12.5px; color: #64748b; font-weight: 700; }
.lv-stat b { display: block; font-size: 30px; font-weight: 800; color: var(--c); line-height: 1.15; font-variant-numeric: tabular-nums; }
.lv-main { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 12px; padding: 12px 14px 14px; }
.lv-board { overflow: auto; padding-inline-end: 2px; }
.lv-grp { margin-bottom: 14px; }
.lv-grp h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; font-size: 14px; font-weight: 800; color: #334155; }
.lv-grp h3 .n { background: var(--c); color: #fff; border-radius: 12px; padding: 1px 9px; font-size: 12.5px; }
.lv-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: 8px; }
.lv-card { background: #fff; border-radius: 11px; padding: 10px 12px; border-inline-start: 5px solid var(--c); box-shadow: 0 1px 2px rgba(15, 23, 42, .06);
    transition: box-shadow .3s, transform .3s; }
.lv-card .nm { display: flex; align-items: center; gap: 6px; font-weight: 800; font-size: 14.5px; color: #0f172a; }
.lv-card .nm small { color: #94a3b8; font-weight: 600; font-size: 11.5px; margin-inline-start: auto; direction: ltr; }
.lv-card .ln { display: flex; align-items: center; gap: 8px; margin-top: 5px; font-size: 12.5px; color: #64748b; }
.lv-card .hrs { margin-inline-start: auto; font-weight: 800; font-size: 16px; color: var(--c); direction: ltr; font-variant-numeric: tabular-nums; }
.lv-card .warn { color: #d97706; }
.lv-card.flash { box-shadow: 0 0 0 3px var(--c), 0 8px 20px -6px var(--c); transform: translateY(-2px); }
.lv-grp.off .lv-cards { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
.lv-grp.off .lv-card { padding: 6px 10px; opacity: .75; }
.s-in { --c: #16a34a; } .s-out { --c: #0369a1; } .s-none { --c: #d97706; } .s-off { --c: #94a3b8; } .s-all { --c: #0f766e; }
.lv-feed { display: flex; flex-direction: column; min-height: 0; background: #fff; border-radius: 12px; box-shadow: 0 1px 2px rgba(15, 23, 42, .06); }
.lv-feed h3 { margin: 0; padding: 12px 14px; font-size: 14px; font-weight: 800; color: #334155; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px; }
.lv-feed h3 .live { width: 9px; height: 9px; border-radius: 50%; background: #dc2626; animation: lvpulse 1.2s infinite; }
.lv-list { flex: 1; overflow: auto; padding: 6px 8px; }
.lv-p { display: flex; align-items: center; gap: 10px; padding: 8px 8px; border-radius: 9px; border-bottom: 1px solid #f8fafc; }
.lv-p .t { font-size: 17px; font-weight: 800; direction: ltr; font-variant-numeric: tabular-nums; color: #0f172a; min-width: 52px; }
.lv-p .w { flex: 1; min-width: 0; font-weight: 700; font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lv-p .b { font-size: 11.5px; font-weight: 800; padding: 2px 9px; border-radius: 6px; white-space: nowrap; }
.lv-p .b.in { background: #dcfce7; color: #15803d; } .lv-p .b.out { background: #fee2e2; color: #b91c1c; }
.lv-p .src { color: #cbd5e1; font-size: 12px; }
.lv-p.new { animation: lvnew 2.4s ease-out; }
.lv-empty { padding: 30px 12px; text-align: center; color: #94a3b8; }
.lv-views { display: inline-flex; background: #f1f5f9; border-radius: 9px; padding: 3px; gap: 2px; }
.lv-tools .lv-views button { width: auto; height: auto; border: 0; background: transparent; padding: 6px 12px; border-radius: 7px; font: inherit;
    font-size: 13px; font-weight: 700; color: #64748b; cursor: pointer; white-space: nowrap; }
.lv-tools .lv-views button.on { background: #fff; color: #0f766e; box-shadow: 0 1px 2px rgba(0, 0, 0, .08); }
.lv-tablewrap { background: #fff; border-radius: 12px; box-shadow: 0 1px 2px rgba(15, 23, 42, .06); overflow: hidden; }
.lv-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.lv-table th { position: sticky; top: 0; z-index: 1; background: #f8fafc; color: #475569; font-size: 12.5px; font-weight: 800; text-align: right; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
.lv-table td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.lv-table tr { transition: background .4s; }
.lv-table tr.flash td { background: #fef9c3; }
.lv-table td.pin { color: #94a3b8; font-weight: 700; direction: ltr; text-align: right; width: 54px; }
.lv-table td.nm { font-weight: 800; color: #0f172a; white-space: nowrap; }
.lv-table td.h { font-weight: 800; font-size: 16px; direction: ltr; text-align: left; font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--c); }
.lv-table tr.s-off td { color: #94a3b8; }
.lv-badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 800; white-space: nowrap;
    background: #f1f5f9; color: var(--c); }
tr.s-in .lv-badge { background: #dcfce7; } tr.s-out .lv-badge { background: #e0f2fe; } tr.s-none .lv-badge { background: #fef3c7; }
.lv-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
tr.s-in .lv-badge .dot { animation: lvpulse 1.6s infinite; }
.lv-type { font-size: 11.5px; font-weight: 800; padding: 2px 8px; border-radius: 10px; background: #e0f2fe; color: #0369a1; }
.lv-type.doctor { background: #ede9fe; color: #6d28d9; }
.lv-chips { display: flex; flex-wrap: wrap; gap: 4px; }
.lv-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 7px; font-size: 12.5px; font-weight: 700; direction: ltr;
    font-variant-numeric: tabular-nums; border: 1px solid transparent; }
.lv-chip.in { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
.lv-chip.out { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.lv-chip.manual { border-style: dashed; }
.lv-offline { display: none; position: fixed; bottom: 14px; left: 50%; transform: translateX(-50%); background: #b91c1c; color: #fff;
    padding: 8px 18px; border-radius: 20px; font-weight: 700; z-index: 10; box-shadow: 0 6px 16px rgba(0, 0, 0, .2); }
.lv-offline.show { display: block; }
[data-hrd-e] { cursor: pointer; }
@keyframes lvpulse { 50% { opacity: .25; } }
@keyframes lvnew { 0% { background: #fef9c3; transform: scale(1.02); } 100% { background: transparent; transform: none; } }
@media (max-width: 1000px) {
    body.lv { height: auto; overflow: auto; }
    .lv-main { grid-template-columns: 1fr; }
    .lv-stats { grid-template-columns: repeat(3, 1fr); }
    .lv-feed { max-height: 60vh; }
}
    </style>
</head>
<body class="lv">

<div class="lv-bar">
    <a class="lv-back" id="lvBack" href="<?= APP_URL ?>/hr/index.php" title="گەڕانەوە (Esc)"><i class="fas fa-arrow-right"></i> گەڕانەوە</a>
    <div class="lv-title"><b>ئامادەبوونی ئەمڕۆ</b><small id="lvDate">…</small></div>
    <div class="lv-clock" id="lvClock">--:--:--</div>
    <span class="lv-dev" id="lvDevice" style="display:none"><span class="dot"></span><span id="lvDeviceText"></span></span>
    <div class="lv-tools">
        <div class="lv-views" role="group" aria-label="شێوەی پیشاندان">
            <button type="button" data-view="table"><i class="fas fa-table-list"></i> خشتە</button>
            <button type="button" data-view="cards"><i class="fas fa-grip"></i> کارت</button>
        </div>
        <button type="button" id="lvSound" title="دەنگ بۆ هەر پەنجەمۆرێکی نوێ"><i class="fas fa-volume-xmark"></i></button>
        <button type="button" id="lvFull" title="پڕبەشاشە"><i class="fas fa-expand"></i></button>
    </div>
</div>

<div class="lv-stats">
    <div class="lv-stat s-in"><span>ئێستا لە دەوامن</span><b id="stIn">–</b></div>
    <div class="lv-stat s-all"><span>ئەمڕۆ هاتوون</span><b id="stCame">–</b></div>
    <div class="lv-stat s-none"><span>نەهاتوون</span><b id="stNone">–</b></div>
    <div class="lv-stat s-out"><span>ڕۆیشتوون</span><b id="stOut">–</b></div>
    <div class="lv-stat s-off"><span>پشوو</span><b id="stOff">–</b></div>
</div>

<div class="lv-main">
    <section class="lv-board">
        <div id="lvBoard"><div class="lv-empty"><i class="fas fa-spinner fa-spin"></i></div></div>
        <div class="lv-tablewrap" id="lvTableView" style="display:none">
            <table class="lv-table">
                <thead><tr><th>PIN</th><th>ناو</th><th>جۆر</th><th>دۆخ</th><th>پەنجەمۆرەکانی ئەمڕۆ</th><th style="text-align:left">کاتژمێر</th></tr></thead>
                <tbody id="lvRows"></tbody>
            </table>
        </div>
    </section>
    <aside class="lv-feed">
        <h3><span class="live"></span> پەنجەمۆری ڕاستەوخۆ <small style="margin-inline-start:auto;color:#94a3b8;font-weight:600" id="lvCount"></small></h3>
        <div class="lv-list" id="lvFeed"></div>
    </aside>
</div>

<div class="lv-offline" id="lvOffline"><i class="fas fa-plug-circle-xmark"></i> پەیوەندی لەگەڵ لێزان پچڕاوە — هەوڵ دەدرێتەوە…</div>

<?php hr_day_editor(); ?>
<script>
(function () {
    var API = <?= json_encode(APP_URL . '/hr/api/live.php') ?>;
    var CAN_OPEN = <?= $canOpen ? 'true' : 'false' ?>;
    var POLL_MS = 5000;
    var skew = 0, seen = null, timer = null, workDate = '';
    var GROUPS = [
        ['in', 'لە دەوامن'], ['none', 'نەهاتوون'], ['out', 'ڕۆیشتوون'], ['off', 'پشوو']
    ];

    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function serverNow() { return Date.now() + skew; }
    function parse(dt) { return Date.parse(dt.replace(' ', 'T')); }
    function hm(h) {
        var m = Math.round(h * 60);
        return Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');
    }

    // ── Sound: a short tone per new punch, high for IN, lower for OUT ──
    var audio = null, soundOn = false;
    try { soundOn = localStorage.getItem('hrLiveSound') === '1'; } catch (e) {}
    function soundIcon() {
        $('lvSound').classList.toggle('on', soundOn);
        $('lvSound').querySelector('i').className = 'fas ' + (soundOn ? 'fa-volume-high' : 'fa-volume-xmark');
    }
    function beep(type) {
        if (!soundOn) return;
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            var o = audio.createOscillator(), g = audio.createGain(), t = audio.currentTime;
            o.frequency.value = type === 'in' ? 880 : 620;
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.25, t + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.4);
            o.connect(g); g.connect(audio.destination); o.start(t); o.stop(t + 0.45);
        } catch (e) {}
    }
    $('lvSound').addEventListener('click', function () {
        soundOn = !soundOn;
        try { localStorage.setItem('hrLiveSound', soundOn ? '1' : '0'); } catch (e) {}
        soundIcon();
        if (soundOn) { beep('in'); }
    });
    soundIcon();

    // ── Drawing ──
    function card(p) {
        var attrs = CAN_OPEN ? ' data-hrd-e="' + p.id + '" data-hrd-d="' + workDate + '"' : '';
        var line = '';
        var loose = p.unpaired > (p.state === 'in' ? 1 : 0);
        if (p.state === 'in') {
            line = 'هاتن <b dir="ltr">' + esc(p.first_in) + '</b>'
                + (p.last_time !== p.first_in ? ' · دوایین <b dir="ltr">' + esc(p.last_time) + '</b>' : '')
                + '<span class="hrs" data-base="' + p.hours + '" data-open="' + esc(p.open_since) + '">' + hm(p.hours) + '</span>';
        } else if (p.state === 'out') {
            line = 'ڕۆیشت <b dir="ltr">' + esc(p.last_time) + '</b><span class="hrs">' + hm(p.hours) + '</span>';
        } else if (p.state === 'none') {
            line = 'هێشتا نەهاتووە';
        } else {
            line = esc(p.off_label || 'پشوو');
        }
        return '<div class="lv-card s-' + p.state + '" id="lvc' + p.id + '"' + attrs + '>'
            + '<div class="nm">' + esc(p.name) + (p.type === 'doctor' ? ' <i class="fas fa-user-doctor" style="color:#7c3aed;font-size:12px"></i>' : '')
            + (loose ? ' <i class="fas fa-triangle-exclamation warn" title="پەنجەمۆری بێ جووت"></i>' : '') + '<small>' + esc(p.pin) + '</small></div>'
            + (p.state === 'off' ? '' : '<div class="ln">' + line + '</div>')
            + '</div>';
    }

    function feedRow(p) {
        return '<div class="lv-p" id="lvp' + p.id + '"' + (CAN_OPEN ? ' data-hrd-e="' + p.emp + '" data-hrd-d="' + workDate + '"' : '') + '>'
            + '<span class="t">' + esc(p.clock) + '</span>'
            + '<span class="w">' + esc(p.name) + '</span>'
            + '<span class="b ' + p.type + '">' + (p.type === 'in' ? 'هاتن' : 'ڕۆیشتن') + '</span>'
            + '<i class="src fas ' + (p.source === 'device' ? 'fa-fingerprint' : 'fa-hand') + '" title="' + (p.source === 'device' ? 'ئامێر' : 'دەستی') + '"></i>'
            + '</div>';
    }

    // The table view: the dashboard's «ئامادەبوونی ئەمڕۆ», live.
    var STATE_TEXT = {'in': 'لە دەوامە', out: 'ڕۆیشتووە', none: 'نەهاتووە'};
    function tableRow(p, punches) {
        var attrs = CAN_OPEN ? ' data-hrd-e="' + p.id + '" data-hrd-d="' + workDate + '"' : '';
        var chips = punches.map(function (x) {
            return '<span class="lv-chip ' + x.type + (x.source === 'manual' ? ' manual' : '') + '" title="'
                + (x.type === 'in' ? 'هاتن' : 'ڕۆیشتن') + (x.source === 'manual' ? ' — دەستی' : ' — ئامێر') + '">'
                + '<i class="fas ' + (x.type === 'in' ? 'fa-arrow-right-to-bracket' : 'fa-arrow-right-from-bracket') + '"></i>'
                + esc(x.clock) + '</span>';
        }).join('');
        var hrs = p.state === 'in'
            ? '<span class="hrs" data-base="' + p.hours + '" data-open="' + esc(p.open_since) + '">' + hm(p.hours) + '</span>'
            : (p.hours > 0 ? hm(p.hours) : '');
        return '<tr class="s-' + p.state + '" id="lvr' + p.id + '"' + attrs + '>'
            + '<td class="pin">' + esc(p.pin) + '</td>'
            + '<td class="nm">' + esc(p.name) + '</td>'
            + '<td><span class="lv-type ' + (p.type === 'doctor' ? 'doctor' : '') + '">' + (p.type === 'doctor' ? 'دکتۆر' : 'کارمەند') + '</span></td>'
            + '<td><span class="lv-badge"><span class="dot"></span>' + esc(STATE_TEXT[p.state] || p.off_label || 'پشوو') + '</span></td>'
            + '<td><div class="lv-chips">' + chips + '</div></td>'
            + '<td class="h">' + hrs + '</td></tr>';
    }

    // Cards or table, remembered on this computer.
    var view = 'table';
    try { view = localStorage.getItem('hrLiveView') || 'table'; } catch (e) {}
    function setView(v) {
        view = v;
        $('lvBoard').style.display = v === 'cards' ? '' : 'none';
        $('lvTableView').style.display = v === 'table' ? '' : 'none';
        document.querySelectorAll('.lv-views button').forEach(function (b) { b.classList.toggle('on', b.dataset.view === v); });
        try { localStorage.setItem('hrLiveView', v); } catch (e) {}
    }
    document.querySelectorAll('.lv-views button').forEach(function (b) {
        b.addEventListener('click', function () { setView(b.dataset.view); });
    });
    setView(view);

    // ── Updating in place ──
    // Only what changed is touched: each person's card and each punch row is
    // kept with a signature of what it shows, and redrawn only when that
    // signature changes. A new punch is put at the top of the list; nothing
    // else on the page moves or flickers.
    var cards = {}, rows = {}, trs = {}, groups = null;
    var ROW_ORDER = {'in': 0, out: 1, none: 2, off: 3};

    function el(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    }
    function setText(id, v) {
        var n = $(id);
        if (n.textContent !== String(v)) n.textContent = v;
    }
    // Put `nodes` into `parent` in this order, moving only what is out of place.
    function order(parent, nodes) {
        nodes.forEach(function (n, i) {
            if (parent.children[i] !== n) parent.insertBefore(n, parent.children[i] || null);
        });
    }
    function buildGroups() {
        var board = $('lvBoard');
        board.innerHTML = '';
        groups = {};
        GROUPS.forEach(function (g) {
            var wrap = el('<div class="lv-grp ' + g[0] + ' s-' + g[0] + '" style="display:none"><h3>' + g[1]
                + ' <span class="n">0</span></h3><div class="lv-cards"></div></div>');
            board.appendChild(wrap);
            groups[g[0]] = {wrap: wrap, list: wrap.querySelector('.lv-cards'), count: wrap.querySelector('.n')};
        });
    }

    function render(d) {
        if (workDate !== d.work_date) {
            // A new work day (08:00): start the board afresh.
            workDate = d.work_date;
            cards = {};
            rows = {};
            trs = {};
            seen = null;
            buildGroups();
            $('lvFeed').innerHTML = '';
            $('lvRows').innerHTML = '';
            $('lvDate').innerHTML = esc(d.weekday) + ' <bdo dir="ltr">' + esc(d.work_date) + '</bdo>';
        }
        setText('stIn', d.stats['in']);
        setText('stCame', d.stats.came);
        setText('stNone', d.stats.none);
        setText('stOut', d.stats.out);
        setText('stOff', d.stats.off);

        var dev = d.devices[0];
        $('lvDevice').style.display = dev ? '' : 'none';
        if (dev) {
            if ($('lvDevice').className !== 'lv-dev ' + dev.cls) $('lvDevice').className = 'lv-dev ' + dev.cls;
            setText('lvDeviceText', dev.label);
            $('lvDevice').title = dev.name + (dev.error ? ' — ' + dev.error : '');
        }

        // People
        var byGroup = {in: [], none: [], out: [], off: []}, present = {};
        d.people.forEach(function (p) {
            present[p.id] = true;
            var sig = JSON.stringify([p.state, p.pin, p.name, p.type, p.first_in, p.last_time, p.hours, p.open_since, p.unpaired, p.off_label]);
            var c = cards[p.id];
            if (!c || c.sig !== sig) {
                var node = el(card(p));
                if (c) c.node.replaceWith(node);
                c = cards[p.id] = {node: node, sig: sig};
            }
            byGroup[p.state].push(c.node);
        });
        Object.keys(cards).forEach(function (id) {
            if (!present[id]) { cards[id].node.remove(); delete cards[id]; }
        });
        GROUPS.forEach(function (g) {
            var grp = groups[g[0]], list = byGroup[g[0]];
            order(grp.list, list);
            grp.wrap.style.display = list.length ? '' : 'none';
            if (grp.count.textContent !== String(list.length)) grp.count.textContent = list.length;
        });

        // The table: at work first, then left, not arrived, off — each in
        // the monthly report's order. A row is redrawn only when its person's
        // state, hours or punches changed.
        var punchesOf = {};
        d.feed.forEach(function (x) { (punchesOf[x.emp] = punchesOf[x.emp] || []).unshift(x); });
        var trNodes = [];
        d.people.slice().sort(function (a, b) { return ROW_ORDER[a.state] - ROW_ORDER[b.state]; }).forEach(function (p) {
            var ps = punchesOf[p.id] || [];
            var sig = JSON.stringify([p.state, p.pin, p.name, p.type, p.hours, p.open_since, p.off_label,
                ps.map(function (x) { return [x.id, x.type, x.clock, x.source]; })]);
            var r = trs[p.id];
            if (!r || r.sig !== sig) {
                var node = el(tableRow(p, ps));
                if (r) r.node.replaceWith(node);
                r = trs[p.id] = {node: node, sig: sig};
            }
            trNodes.push(r.node);
        });
        Object.keys(trs).forEach(function (id) {
            if (!present[id]) { trs[id].node.remove(); delete trs[id]; }
        });
        order($('lvRows'), trNodes);

        // Punches, newest first
        var feed = $('lvFeed'), fresh = [], nodes = [], keep = {};
        var empty = feed.querySelector('.lv-empty');
        if (empty) empty.remove();
        d.feed.forEach(function (p) {
            keep[p.id] = true;
            var sig = JSON.stringify([p.clock, p.type, p.name, p.source]);
            var r = rows[p.id];
            if (!r || r.sig !== sig) {
                var node = el(feedRow(p));
                if (r) r.node.replaceWith(node);
                else if (seen !== null) { node.classList.add('new'); fresh.push(p); }
                r = rows[p.id] = {node: node, sig: sig};
            }
            nodes.push(r.node);
        });
        Object.keys(rows).forEach(function (id) {
            if (!keep[id]) { rows[id].node.remove(); delete rows[id]; }
        });
        order(feed, nodes);
        if (!d.feed.length) feed.appendChild(el('<div class="lv-empty">ئەمڕۆ هێشتا هیچ پەنجەمۆرێک نییە</div>'));
        setText('lvCount', d.feed.length ? d.feed.length + ' پەنجەمۆر' : '');

        seen = {};
        d.feed.forEach(function (p) { seen[p.id] = true; });
        fresh.forEach(function (p, i) {
            setTimeout(function () { beep(p.type); }, i * 450);
            [cards[p.emp], trs[p.emp]].forEach(function (c) {
                if (!c) return;
                c.node.classList.add('flash');
                setTimeout(function () { c.node.classList.remove('flash'); }, 2500);
            });
        });
        tick();
    }
    // After a fix in the day editor: ask again now instead of reloading the page.
    window.hrOnDayChange = function () { poll(); };

    // ── Clock and the hours of those at work, every second ──
    function tick() {
        var now = new Date(serverNow());
        $('lvClock').textContent = [now.getHours(), now.getMinutes(), now.getSeconds()].map(function (n) {
            return String(n).padStart(2, '0');
        }).join(':');
        document.querySelectorAll('.hrs[data-open]').forEach(function (el) {
            var open = el.getAttribute('data-open');
            if (!open) return;
            var h = parseFloat(el.getAttribute('data-base')) + Math.max(0, serverNow() - parse(open)) / 3600000;
            el.textContent = hm(h);
        });
    }
    setInterval(tick, 1000);

    // ── Asking Lezan ──
    function poll() {
        clearTimeout(timer);
        fetch(API, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) throw new Error('bad answer');
                skew = parse(d.now) - Date.now();
                $('lvOffline').classList.remove('show');
                // Keep the board still while the day editor is open over it.
                if (!(window.HrDay && HrDay.isOpen())) render(d);
            })
            .catch(function () { $('lvOffline').classList.add('show'); })
            .then(function () {
                if (document.visibilityState === 'visible') timer = setTimeout(poll, POLL_MS);
            });
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') poll();
    });
    poll();

    // ── «گەڕانەوە»: back to the page the board was opened from ──
    var KEY = 'hrLiveReturn', back = $('lvBack');
    var ref = document.referrer, target = null;
    if (ref && ref.indexOf(location.origin + '/') === 0 && !/\/hr\/live\.php/.test(ref)) {
        target = ref;
        try { sessionStorage.setItem(KEY, ref); } catch (e) {}
    } else {
        try { target = sessionStorage.getItem(KEY); } catch (e) {}
    }
    if (target) back.href = target;
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !e.defaultPrevented && !document.fullscreenElement) location.href = back.href;
    });

    var full = $('lvFull');
    if (!document.documentElement.requestFullscreen) full.style.display = 'none';
    full.addEventListener('click', function () {
        if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen();
    });
    document.addEventListener('fullscreenchange', function () {
        full.querySelector('i').className = 'fas ' + (document.fullscreenElement ? 'fa-compress' : 'fa-expand');
    });
})();
</script>
</body>
</html>
