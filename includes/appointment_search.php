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
.aps-bar > i { color: var(--win-accent, #0078d4); font-size: 17px; padding-inline-start: 6px; }
.aps-bar input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: inherit;
    font-size: 15px; padding: 9px 2px; color: #1e293b; }
.aps-bar input::placeholder { color: #94a3b8; }
.aps-key { font-size: 11px; color: #64748b; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 2px 7px; white-space: nowrap; }
.aps-clear { border: 0; background: #f1f5f9; color: #64748b; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; }
.aps-clear:hover { background: #fee2e2; color: #dc2626; }
.aps-spin { color: #94a3b8; }

.aps-out { margin-top: 10px; display: grid; gap: 10px; }
.aps-msg { padding: 14px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 10px; font-size: 13px; }
.aps-p { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .05); }
.aps-ph { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px;
    background: linear-gradient(to left, #f0f7ff, #ffffff); border-bottom: 1px solid #eef2f7; }
.aps-av { width: 36px; height: 36px; border-radius: 50%; flex: none; display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, var(--win-accent, #0078d4), #0e9f9a); color: #fff; font-weight: 800; }
.aps-name { font-weight: 800; font-size: 15px; color: var(--win-accent, #0078d4); text-decoration: none; }
.aps-name:hover { text-decoration: underline; }
.aps-meta { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; font-size: 12px; color: #475569; }
.aps-tag { background: #f1f5f9; border-radius: 6px; padding: 2px 7px; direction: ltr; unicode-bidi: isolate; }
.aps-ph .aps-acts { margin-inline-start: auto; display: flex; gap: 6px; }
.aps-btn { display: inline-flex; align-items: center; gap: 5px; border: 1px solid #cbd5e1; background: #fff; color: #334155;
    border-radius: 8px; padding: 5px 10px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer;
    text-decoration: none; white-space: nowrap; }
.aps-btn:hover { background: #f1f5f9; }
.aps-btn.is-new { background: var(--win-accent, #0078d4); border-color: var(--win-accent, #0078d4); color: #fff; }
.aps-btn.is-new:hover { filter: brightness(1.08); }
.aps-btn.is-wa { border-color: #86efac; color: #15803d; background: #f0fdf4; }
.aps-btn.is-wa:hover { background: #dcfce7; }

.aps-list { padding: 8px 10px 10px; display: grid; gap: 6px; }
.aps-a { display: grid; grid-template-columns: 96px 64px minmax(0, 1fr) auto; align-items: center; gap: 10px;
    border: 1px solid #e2e8f0; border-inline-start: 4px solid var(--sc, #0078d4); border-radius: 10px; padding: 7px 10px; }
.aps-a.is-today { background: #f0fdf4; border-color: #bbf7d0; border-inline-start-color: #16a34a; }
.aps-a.is-off { opacity: .55; }
.aps-a.is-off .aps-time { text-decoration: line-through; }
.aps-day { line-height: 1.25; }
.aps-day b { display: block; font-size: 13px; color: #1e293b; }
.aps-day span { font-size: 11.5px; color: #64748b; direction: ltr; unicode-bidi: isolate; }
.aps-time { font-size: 18px; font-weight: 800; color: #0f172a; direction: ltr; font-variant-numeric: tabular-nums; }
.aps-info { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 12.5px; color: #334155; min-width: 0; }
.aps-info i { color: #94a3b8; margin-inline-end: 3px; }
.aps-when { font-weight: 800; color: #0369a1; }
.aps-a.is-today .aps-when { color: #15803d; }
.aps-st { font-size: 11px; font-weight: 700; color: #fff; background: var(--sc, #0078d4); border-radius: 10px; padding: 1px 8px; }
.aps-note { flex-basis: 100%; color: #64748b; font-size: 11.5px; }
.aps-a .aps-acts { display: flex; gap: 5px; }
.aps-none { font-size: 13px; color: #b45309; background: #fffbeb; border: 1px dashed #fcd34d; border-radius: 10px; padding: 8px 12px; }
.aps-past summary { cursor: pointer; font-size: 12.5px; font-weight: 700; color: #64748b; padding: 2px 2px 4px; list-style: none; }
.aps-past summary::before { content: '\25B8'; display: inline-block; margin-inline-end: 6px; transition: transform .15s; }
.aps-past[open] summary::before { transform: rotate(90deg); }
.aps-past .aps-a { background: #fafafa; padding: 5px 10px; }
.aps-past .aps-time { font-size: 15px; }

tr.apt-hl > td { animation: aptHl 2.6s ease-out 1; }
@keyframes aptHl { 0%, 40% { background: #fef08a; } 100% { background: transparent; } }

@media (max-width: 640px) {
    .aps-key { display: none; }
    .aps-a { grid-template-columns: 84px 52px minmax(0, 1fr); }
    .aps-a .aps-acts { grid-column: 1 / -1; justify-content: flex-end; }
    .aps-time { font-size: 16px; }
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
    <div class="aps-out" id="apsOut" hidden></div>
</div>

<script>
(function () {
    const input = document.getElementById('apsInput');
    const out = document.getElementById('apsOut');
    const spin = document.getElementById('apsSpin');
    const clear = document.getElementById('apsClear');
    const api = <?= json_encode(APP_URL . '/api/appointment-search.php') ?>;
    const page = <?= json_encode(APP_URL . '/appointments.php') ?>;
    const patientUrl = <?= json_encode(APP_URL . '/patients/view.php?id=') ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    let timer = null, seq = 0;

    function row(a, past) {
        const today = a.when === 'ئەمڕۆ';
        const go = page + '?date=' + encodeURIComponent(a.date) + '&hl=' + a.id;
        return '<div class="aps-a' + (today && !a.off ? ' is-today' : '') + (a.off ? ' is-off' : '') + '" style="--sc:' + esc(a.statusColour) + '">'
            + '<div class="aps-day"><b>' + esc(a.day) + '</b><span>' + esc(a.date) + '</span></div>'
            + '<div class="aps-time">' + esc(a.time) + '</div>'
            + '<div class="aps-info">'
            +   '<span class="aps-when">' + esc(a.when) + '</span>'
            +   '<span><i class="fas fa-user-md"></i>د. ' + esc(a.doctor) + '</span>'
            +   (a.work ? '<span><i class="fas fa-briefcase-medical"></i>' + esc(a.work) + '</span>' : '')
            +   (past ? '' : '<span><i class="fas fa-hourglass-half"></i>' + esc(a.duration) + ' خولەک</span>')
            +   '<span class="aps-st">' + esc(a.statusLabel) + '</span>'
            +   (a.notes && !past ? '<span class="aps-note"><i class="fas fa-sticky-note"></i>' + esc(a.notes) + '</span>' : '')
            + '</div>'
            + '<div class="aps-acts">'
            +   (a.wa ? '<a class="aps-btn is-wa" href="' + esc(a.wa) + '" target="_blank" rel="noopener" title="ناردنی بیرخستنەوە بە واتسئاپ"><i class="fab fa-whatsapp"></i> بیرخستنەوە</a>' : '')
            +   '<a class="aps-btn" href="' + esc(go) + '" title="کردنەوەی لیستی ئەو ڕۆژە"><i class="fas fa-calendar-day"></i> ڕۆژەکە</a>'
            + '</div></div>';
    }

    function render(data, q) {
        const ps = data.patients || [];
        if (!ps.length) {
            out.innerHTML = '<div class="aps-msg"><i class="fas fa-user-slash"></i> هیچ نەخۆشێک بە «' + esc(q) + '» نەدۆزرایەوە</div>';
            return;
        }
        out.innerHTML = ps.map(p => {
            const up = p.upcoming.filter(a => !a.off);
            const head = '<div class="aps-ph">'
                + '<span class="aps-av">' + esc((p.name || '?').trim().charAt(0)) + '</span>'
                + '<div><a class="aps-name" href="' + patientUrl + p.id + '">' + esc(p.name) + '</a>'
                + '<div class="aps-meta">'
                +   (p.code ? '<span class="aps-tag">' + esc(p.code) + '</span>' : '')
                +   (p.phone ? '<span class="aps-tag"><i class="fas fa-phone"></i> ' + esc(p.phone) + '</span>' : '')
                +   '<span>' + (up.length ? up.length + ' مەوعیدی داهاتوو' : 'بێ مەوعیدی داهاتوو') + '</span>'
                + '</div></div>'
                + '<div class="aps-acts"><button type="button" class="aps-btn is-new" data-book="' + p.id + '"><i class="fas fa-plus"></i> مەوعیدی نوێ</button></div>'
                + '</div>';
            let body = p.upcoming.map(a => row(a, false)).join('');
            if (!up.length) {
                body += '<div class="aps-none"><i class="fas fa-info-circle"></i> ئەم نەخۆشە هیچ مەوعیدێکی داهاتووی نییە</div>';
            }
            if (p.past.length) {
                body += '<details class="aps-past"' + (up.length ? '' : ' open') + '><summary>مەوعیدە پێشووەکان (' + p.pastCount + ')</summary>'
                    + '<div class="aps-list" style="padding:0">' + p.past.map(a => row(a, true)).join('') + '</div></details>';
            }
            return '<div class="aps-p" data-pid="' + p.id + '" data-name="' + esc(p.name) + '" data-phone="' + esc(p.phone) + '">' + head + '<div class="aps-list">' + body + '</div></div>';
        }).join('');
    }

    async function run() {
        const q = input.value.trim();
        clear.hidden = !input.value;
        if (q.length < 2) {
            out.hidden = true; out.innerHTML = ''; spin.hidden = true;
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
            out.innerHTML = '<div class="aps-msg">گەڕانەکە سەرنەکەوت — دووبارە هەوڵ بدەرەوە</div>';
        }
        spin.hidden = true;
        out.hidden = false;
    }

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 220); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { clearTimeout(timer); run(); }
        if (e.key === 'Escape') { input.value = ''; run(); }
    });
    clear.addEventListener('click', () => { input.value = ''; run(); input.focus(); });

    // «مەوعیدی نوێ» on a found patient: the page's dialog, that patient chosen
    out.addEventListener('click', e => {
        const b = e.target.closest('[data-book]');
        if (!b) return;
        const p = b.closest('.aps-p');
        openDialog('newAppointmentDialog');
        if (typeof selectPatient === 'function') {
            selectPatient(p.dataset.pid, p.dataset.name, p.dataset.phone);
        }
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
