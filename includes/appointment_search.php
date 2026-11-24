<?php
/**
 * گەڕانی مەوعید — the search bar at the top of appointments.php.
 *
 * Reception on the phone types a name, a mobile or a patient code and sees,
 * at once and on any day, every appointment that patient has: the day, the
 * time, the doctor, the work, the status, then a WhatsApp reminder and a link
 * to that day's list. A patient with nothing booked gets «مەوعیدی نوێ», which
 * opens the page's own dialog with them already chosen.
 *
 * The results open in a panel that floats under the bar (its own scroll, the
 * patient's line pinned while his appointments scroll), so the day's list
 * below never moves and is back the moment the panel closes — Esc, ×, a click
 * outside, or the box emptied. ↑ ↓ walk the rows, Enter opens that day.
 *
 * Data: api/appointment-search.php (a doctor sees only their own). Expects
 * APP_URL; uses the page's openDialog() and selectPatient().
 */
?>
<style>
.aps { position: relative; margin-bottom: 14px; }
.aps [hidden] { display: none !important; }
.aps-bar input::-webkit-search-cancel-button { -webkit-appearance: none; display: none; }
.aps-bar { display: flex; align-items: center; gap: 10px; background: #fff; border: 2px solid #cfe3f7;
    border-radius: 12px; padding: 4px 6px 4px 12px; transition: border-color .15s, box-shadow .15s; }
.aps-bar:focus-within { border-color: var(--win-accent, #0078d4); box-shadow: 0 0 0 4px rgba(0, 120, 212, .12); }
.aps.is-open .aps-bar { border-color: var(--win-accent, #0078d4); border-bottom-left-radius: 4px; border-bottom-right-radius: 4px; }
.aps-bar > i { color: var(--win-accent, #0078d4); font-size: 17px; padding-inline-start: 6px; }
.aps-bar input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: inherit;
    font-size: 15px; padding: 9px 2px; color: #1e293b; }
.aps-bar input::placeholder { color: #94a3b8; }
.aps-key { font-size: 11px; color: #64748b; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 2px 7px; white-space: nowrap; }
.aps-clear { border: 0; background: #f1f5f9; color: #64748b; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; }
.aps-clear:hover { background: #fee2e2; color: #dc2626; }
.aps-spin { color: #94a3b8; }

/* the panel: floats under the bar, never pushes the list */
.aps-out { position: absolute; top: calc(100% + 6px); right: 0; left: auto; width: min(860px, calc(100vw - 140px)); min-width: 100%; z-index: 80; background: #fff;
    border: 1px solid #cfe3f7; border-radius: 14px; box-shadow: 0 18px 48px rgba(15, 23, 42, .18), 0 2px 8px rgba(15, 23, 42, .08);
    display: flex; flex-direction: column; max-height: min(72vh, 680px); overflow: hidden; }
.aps-top { display: flex; align-items: center; gap: 10px; padding: 7px 12px; border-bottom: 1px solid #eef2f7;
    background: #f8fbff; font-size: 12.5px; color: #475569; flex: none; }
.aps-top b { color: #1e293b; }
.aps-top .aps-hint { margin-inline-start: auto; display: flex; gap: 4px; }
.aps-top .aps-hint span { font-size: 10.5px; color: #64748b; background: #fff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 1px 6px; }
.aps-x { border: 0; background: transparent; color: #64748b; width: 26px; height: 26px; border-radius: 7px; cursor: pointer; font-size: 13px; }
.aps-x:hover { background: #fee2e2; color: #dc2626; }
/* block, not grid/flex: when the panel is full, a grid or flex column would
   squash the cards (overflow:hidden items have no minimum height) */
.aps-body { flex: 1 1 auto; min-height: 0; overflow: auto; overscroll-behavior: contain; padding: 6px; }
.aps-body > * + * { margin-top: 6px; }
.aps-msg { padding: 14px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 10px; font-size: 13px; }

.aps-p { border: 1px solid #e8eef5; border-radius: 10px; background: #fff; overflow: hidden; }
.aps-ph { position: sticky; top: 0; z-index: 1; display: flex; align-items: center; gap: 8px; padding: 6px 10px;
    background: linear-gradient(to left, #eef6ff, #f8fbff); border-bottom: 1px solid #e2e8f0; }
.aps-av { width: 28px; height: 28px; border-radius: 50%; flex: none; display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, var(--win-accent, #0078d4), #0e9f9a); color: #fff; font-weight: 800; font-size: 13px; }
.aps-name { font-weight: 800; font-size: 13.5px; color: var(--win-accent, #0078d4); text-decoration: none; white-space: nowrap; }
.aps-name:hover { text-decoration: underline; }
.aps-meta { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; font-size: 11.5px; color: #475569; min-width: 0; }
.aps-tag { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 1px 6px; direction: ltr; unicode-bidi: isolate; }
.aps-cnt { color: #0369a1; font-weight: 700; }
.aps-cnt.is-none { color: #b45309; }
.aps-ph .aps-acts { margin-inline-start: auto; display: flex; gap: 5px; }
.aps-btn { display: inline-flex; align-items: center; gap: 4px; border: 1px solid #cbd5e1; background: #fff; color: #334155;
    border-radius: 7px; padding: 3px 8px; font: inherit; font-size: 11.5px; font-weight: 700; cursor: pointer;
    text-decoration: none; white-space: nowrap; line-height: 1.4; }
.aps-btn:hover { background: #f1f5f9; }
.aps-btn.is-new { background: var(--win-accent, #0078d4); border-color: var(--win-accent, #0078d4); color: #fff; }
.aps-btn.is-new:hover { filter: brightness(1.08); }
.aps-btn.is-wa { border-color: #86efac; color: #15803d; background: #f0fdf4; }
.aps-btn.is-wa:hover { background: #dcfce7; }
.aps-btn i { font-size: 11px; }

/* one line per appointment */
.aps-list { padding: 4px 6px 6px; display: grid; gap: 2px; }
.aps-a { display: grid; grid-template-columns: 88px 50px minmax(0, 1fr) auto; align-items: center; gap: 8px;
    border: 1px solid transparent; border-inline-start: 3px solid var(--sc, #0078d4); border-radius: 8px; padding: 4px 8px; }
.aps-a:hover, .aps-a.is-active { background: #f8fafc; border-color: #e2e8f0; border-inline-start-color: var(--sc, #0078d4); }
.aps-a.is-active { box-shadow: 0 0 0 2px rgba(0, 120, 212, .18); }
.aps-a.is-today { background: #f0fdf4; border-inline-start-color: #16a34a; }
.aps-a.is-off { opacity: .55; }
.aps-a.is-off .aps-time { text-decoration: line-through; }
.aps-day { line-height: 1.2; }
.aps-day b { display: block; font-size: 12px; color: #1e293b; }
.aps-day span { font-size: 10.5px; color: #64748b; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
.aps-time { font-size: 14px; font-weight: 800; color: #0f172a; direction: ltr; font-variant-numeric: tabular-nums; }
.aps-info { display: flex; flex-wrap: wrap; gap: 2px 9px; font-size: 12px; color: #334155; min-width: 0; align-items: center; }
.aps-info i { color: #94a3b8; margin-inline-end: 3px; font-size: 11px; }
.aps-when { font-weight: 800; color: #0369a1; }
.aps-a.is-today .aps-when { color: #15803d; }
.aps-st { font-size: 10.5px; font-weight: 700; color: #fff; background: var(--sc, #0078d4); border-radius: 9px; padding: 0 7px; line-height: 17px; }
.aps-note { flex-basis: 100%; color: #64748b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.aps-a .aps-acts { display: flex; gap: 4px; }
.aps-none { font-size: 12px; color: #b45309; background: #fffbeb; border: 1px dashed #fcd34d; border-radius: 8px; padding: 5px 10px; }
.aps-past summary { cursor: pointer; font-size: 12px; font-weight: 700; color: #64748b; padding: 3px 4px; list-style: none; }
.aps-past summary::before { content: '\25B8'; display: inline-block; margin-inline-end: 6px; transition: transform .15s; }
.aps-past[open] summary::before { transform: rotate(90deg); }
.aps-past .aps-a { background: #fafafa; }
.aps-past .aps-time { font-size: 12.5px; }
.aps-past .aps-day b { font-size: 11.5px; }

tr.apt-hl > td { animation: aptHl 2.6s ease-out 1; }
@keyframes aptHl { 0%, 40% { background: #fef08a; } 100% { background: transparent; } }

@media (max-width: 640px) {
    .aps-key, .aps-top .aps-hint { display: none; }
    .aps-out { position: fixed; top: auto; bottom: 0; left: 0; right: 0; border-radius: 14px 14px 0 0; max-height: 78vh; }
    .aps-a { grid-template-columns: 74px 46px minmax(0, 1fr); }
    .aps-a .aps-acts { grid-column: 1 / -1; justify-content: flex-end; }
}
</style>

<div class="aps" id="aptSearch">
    <div class="aps-bar">
        <i class="fas fa-search"></i>
        <input type="search" id="apsInput" autocomplete="off" spellcheck="false"
               placeholder="گەڕانی مەوعید — ناوی نەخۆش، ژمارەی مۆبایل یان کۆدی نەخۆش…"
               aria-label="گەڕانی مەوعید بە ناو، مۆبایل یان کۆد">
        <i class="fas fa-spinner fa-spin aps-spin" id="apsSpin" hidden></i>
        <span class="aps-key" title="بۆ گەڕان کلیلی / دابگرە">/</span>
        <button type="button" class="aps-clear" id="apsClear" title="سڕینەوە (Esc)" hidden><i class="fas fa-times"></i></button>
    </div>
    <div class="aps-out" id="apsOut" hidden>
        <div class="aps-top">
            <i class="fas fa-list-ul" style="color:#94a3b8;"></i>
            <span id="apsCount"></span>
            <span class="aps-hint"><span>↑ ↓</span><span>Enter ڕۆژەکە</span><span>Esc</span></span>
            <button type="button" class="aps-x" id="apsClose" title="داخستن (Esc)"><i class="fas fa-times"></i></button>
        </div>
        <div class="aps-body" id="apsBody"></div>
    </div>
</div>

<script>
(function () {
    const box = document.getElementById('aptSearch');
    const input = document.getElementById('apsInput');
    const out = document.getElementById('apsOut');
    const body = document.getElementById('apsBody');
    const count = document.getElementById('apsCount');
    const spin = document.getElementById('apsSpin');
    const clear = document.getElementById('apsClear');
    const api = <?= json_encode(APP_URL . '/api/appointment-search.php') ?>;
    const page = <?= json_encode(APP_URL . '/appointments.php') ?>;
    const patientUrl = <?= json_encode(APP_URL . '/patients/view.php?id=') ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    let timer = null, seq = 0, hasResults = false;

    function row(a, past) {
        const today = a.when === 'ئەمڕۆ';
        const go = page + '?date=' + encodeURIComponent(a.date) + '&hl=' + a.id;
        return '<div class="aps-a' + (today && !a.off ? ' is-today' : '') + (a.off ? ' is-off' : '') + '" style="--sc:' + esc(a.statusColour) + '" data-go="' + esc(go) + '">'
            + '<div class="aps-day"><b>' + esc(a.day) + '</b><span>' + esc(a.date) + '</span></div>'
            + '<div class="aps-time">' + esc(a.time) + '</div>'
            + '<div class="aps-info">'
            +   '<span class="aps-when">' + esc(a.when) + '</span>'
            +   '<span><i class="fas fa-user-md"></i>د. ' + esc(a.doctor) + '</span>'
            +   (a.work ? '<span><i class="fas fa-briefcase-medical"></i>' + esc(a.work) + '</span>' : '')
            +   (past ? '' : '<span><i class="fas fa-hourglass-half"></i>' + esc(a.duration) + ' خولەک</span>')
            +   '<span class="aps-st">' + esc(a.statusLabel) + '</span>'
            +   (a.notes && !past ? '<span class="aps-note" title="' + esc(a.notes) + '"><i class="fas fa-sticky-note"></i>' + esc(a.notes) + '</span>' : '')
            + '</div>'
            + '<div class="aps-acts">'
            +   (a.wa ? '<a class="aps-btn is-wa" href="' + esc(a.wa) + '" target="_blank" rel="noopener" title="ناردنی بیرخستنەوە بە واتسئاپ"><i class="fab fa-whatsapp"></i> بیرخستنەوە</a>' : '')
            +   '<a class="aps-btn" href="' + esc(go) + '" title="کردنەوەی لیستی ئەو ڕۆژە"><i class="fas fa-calendar-day"></i> ڕۆژەکە</a>'
            + '</div></div>';
    }

    function render(data, q) {
        const ps = data.patients || [];
        if (!ps.length) {
            body.innerHTML = '<div class="aps-msg"><i class="fas fa-user-slash"></i> هیچ نەخۆشێک بە «' + esc(q) + '» نەدۆزرایەوە</div>';
            count.innerHTML = 'هیچ ئەنجامێک';
            return;
        }
        let upcomingAll = 0;
        body.innerHTML = ps.map(p => {
            const up = p.upcoming.filter(a => !a.off);
            upcomingAll += up.length;
            const head = '<div class="aps-ph">'
                + '<span class="aps-av">' + esc((p.name || '?').trim().charAt(0)) + '</span>'
                + '<a class="aps-name" href="' + patientUrl + p.id + '">' + esc(p.name) + '</a>'
                + '<div class="aps-meta">'
                +   (p.code ? '<span class="aps-tag">' + esc(p.code) + '</span>' : '')
                +   (p.phone ? '<span class="aps-tag"><i class="fas fa-phone"></i> ' + esc(p.phone) + '</span>' : '')
                +   '<span class="aps-cnt' + (up.length ? '' : ' is-none') + '">' + (up.length ? up.length + ' مەوعیدی داهاتوو' : 'بێ مەوعیدی داهاتوو') + '</span>'
                + '</div>'
                + '<div class="aps-acts"><button type="button" class="aps-btn is-new" data-book="' + p.id + '"><i class="fas fa-plus"></i> مەوعیدی نوێ</button></div>'
                + '</div>';
            let list = p.upcoming.map(a => row(a, false)).join('');
            if (!up.length) {
                list += '<div class="aps-none"><i class="fas fa-info-circle"></i> ئەم نەخۆشە هیچ مەوعیدێکی داهاتووی نییە</div>';
            }
            if (p.past.length) {
                list += '<details class="aps-past"' + (up.length ? '' : ' open') + '><summary>مەوعیدە پێشووەکان (' + p.pastCount + ')</summary>'
                    + '<div class="aps-list" style="padding:0">' + p.past.map(a => row(a, true)).join('') + '</div></details>';
            }
            return '<div class="aps-p" data-pid="' + p.id + '" data-name="' + esc(p.name) + '" data-phone="' + esc(p.phone) + '">' + head + '<div class="aps-list">' + list + '</div></div>';
        }).join('');
        count.innerHTML = '<b>' + ps.length + '</b> نەخۆش · <b>' + upcomingAll + '</b> مەوعیدی داهاتوو';
    }

    function open() { out.hidden = false; box.classList.add('is-open'); }
    function close() { out.hidden = true; box.classList.remove('is-open'); }

    async function run() {
        const q = input.value.trim();
        clear.hidden = !input.value;
        if (q.length < 2) {
            close(); body.innerHTML = ''; spin.hidden = true; hasResults = false;
            return;
        }
        const mine = ++seq;
        spin.hidden = false;
        try {
            const r = await fetch(api + '?q=' + encodeURIComponent(q), {credentials: 'same-origin'});
            const data = await r.json();
            if (mine !== seq) return;
            render(data, q);
        } catch (e) {
            if (mine !== seq) return;
            body.innerHTML = '<div class="aps-msg">گەڕانەکە سەرنەکەوت — دووبارە هەوڵ بدەرەوە</div>';
            count.textContent = '';
        }
        spin.hidden = true;
        hasResults = true;
        body.scrollTop = 0;
        open();
    }

    // ↑ ↓ walk the visible rows; Enter opens the lit row's day
    const rows = () => Array.from(body.querySelectorAll('.aps-a')).filter(r => r.offsetParent !== null);
    function move(step) {
        const list = rows();
        if (!list.length) return;
        const cur = list.findIndex(r => r.classList.contains('is-active'));
        const next = cur < 0 ? (step > 0 ? 0 : list.length - 1) : Math.min(list.length - 1, Math.max(0, cur + step));
        list.forEach(r => r.classList.remove('is-active'));
        list[next].classList.add('is-active');
        list[next].scrollIntoView({block: 'nearest'});
    }

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 220); });
    input.addEventListener('focus', () => { if (hasResults && input.value.trim().length >= 2) open(); });
    input.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (out.hidden && hasResults) open();
            e.preventDefault();
            move(e.key === 'ArrowDown' ? 1 : -1);
        } else if (e.key === 'Enter') {
            const lit = !out.hidden && body.querySelector('.aps-a.is-active');
            if (lit) { location.href = lit.dataset.go; return; }
            clearTimeout(timer); run();
        } else if (e.key === 'Escape') {
            if (!out.hidden) { close(); } else { input.value = ''; run(); }
        }
    });
    clear.addEventListener('click', () => { input.value = ''; run(); input.focus(); });
    document.getElementById('apsClose').addEventListener('click', close);
    // a click anywhere else folds the panel away; the box keeps its text
    document.addEventListener('mousedown', e => { if (!out.hidden && !box.contains(e.target)) close(); });

    // a row is a link to its day; the buttons on it keep their own targets
    body.addEventListener('click', e => {
        const b = e.target.closest('[data-book]');
        if (b) {
            // «مەوعیدی نوێ» on a found patient: the page's dialog, that patient chosen
            const p = b.closest('.aps-p');
            close();
            openDialog('newAppointmentDialog');
            if (typeof selectPatient === 'function') {
                selectPatient(p.dataset.pid, p.dataset.name, p.dataset.phone);
            }
            return;
        }
        if (e.target.closest('a, button, summary')) return;
        const r = e.target.closest('.aps-a');
        if (r && r.dataset.go) location.href = r.dataset.go;
    });

    // «/» anywhere (but in a field) jumps to the search
    document.addEventListener('keydown', e => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        const t = e.target;
        if (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
        e.preventDefault();
        input.focus();
        input.select();
    });

    // ?hl=<id> (from «ڕۆژەکە»): bring that appointment's row into view and flash it
    const hl = new URLSearchParams(location.search).get('hl');
    if (hl) {
        window.addEventListener('load', () => {
            const tr = document.querySelector('tr[data-appointment-id="' + CSS.escape(hl) + '"]');
            if (tr) {
                tr.scrollIntoView({block: 'center', behavior: 'smooth'});
                tr.classList.add('apt-hl');
            }
        });
    }
})();
</script>
