<?php
/**
 * The work tray under a tooth chart — cases/view.php and treatment-plans/view.php.
 *
 * Every treatment of the catalog as a cell, the legend's look. Teeth picked on
 * the chart, a click ticks a work (several for the same tooth, numbered in
 * click order), and the basket above the list shows the teeth, the works and
 * the total; «زیادکردن» (or Enter) puts them all on through the page's own
 * quickAddTreatments(). Works may be ticked before or after the teeth.
 *
 * work_tray_html() is the markup under the chart; work_tray_assets() prints
 * the style and the script once. The page provides: treatmentCatalog,
 * bulkSelected, setQuickToothMode(), quickAddTreatments() (returning the works
 * that went in), quickAddBusy, tpToggleFavorite(), treatmentIconSvg(),
 * toothTag(), palmerOf(), toast(), escapeHtml(), numberFormat(), TP_STAR; it
 * calls renderWorkTrayState() when the picked teeth change and renderWorkTray()
 * when the catalog does.
 */

function work_tray_html(): string
{
    return '<div class="work-tray" id="workTray" aria-live="polite">'
        . '<div class="wt-head"><span class="wt-prompt" id="wtPrompt"></span></div>'
        . '<div class="wt-basket" id="wtBasket" hidden></div>'
        . '<div class="wt-list" id="wtList"></div>'
        . '<button type="button" class="wt-more" id="wtMore" hidden></button>'
        . '</div>';
}

function work_tray_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<style>
/* ── The work tray: every treatment under the chart, the legend's look ──
   Pale until teeth are picked, then lit: one chip adds its work to them. */
.smart-dental-chart:has(.work-tray) .chart-legend { display: none; }
.work-tray {
    margin-top: 8px; padding: 8px 10px 10px; border-radius: 14px;
    background: #f8fafc; border: 1px solid #e2e8f0;
    transition: background-color .2s ease, border-color .2s ease;
}
.work-tray.is-ready { background: #f0fdfa; border-color: #5eead4; box-shadow: 0 0 0 3px rgba(20, 184, 166, .12); }
.wt-head { margin-bottom: 8px; }
.wt-prompt { font-size: 13px; font-weight: 700; color: #64748b; display: inline-flex; align-items: center; gap: 6px; }
.wt-prompt i { color: #94a3b8; }
.work-tray.is-ready .wt-prompt { color: #0f766e; }
.work-tray.is-ready .wt-prompt i { color: #14b8a6; }
/* A light table of four columns: hairlines between the cells; each cell the
   work's icon on a tint of its own colour, the name, and a ★ — blue, gold once
   pinned to the top. */
/* sized by its column, not its chips (it is a container: its own content cannot size it) */
.work-tray { container: wt / inline-size; width: 100%; box-sizing: border-box; }
.wt-list {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1px;
    background: #e5ecf4; border: 1px solid #e5ecf4; border-radius: 12px; overflow: hidden;
    max-height: 144px;
}
.work-tray.is-open .wt-list { max-height: none; }
@container wt (max-width: 480px) { .wt-list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.wt-fill { background: #fff; }
.wt-chip {
    --wt-bg: #fff;
    position: relative; display: flex; align-items: center; gap: 6px; width: 100%; min-width: 0; min-height: 46px;
    padding: 5px 6px; padding-inline-end: 17px; border: 0; border-radius: 0; background: var(--wt-bg); cursor: pointer;
    font: inherit; font-size: 12.5px; font-weight: 600; line-height: 1.3; color: #1e293b; text-align: start;
    transition: background-color .15s ease;
}
/* the hover's mark: a short bar of the work's colour on the cell's edge */
.wt-chip::before {
    content: ''; position: absolute; top: 10px; bottom: 10px; inset-inline-start: 0; width: 3px; border-radius: 3px;
    background: var(--tw, #94a3b8); opacity: 0; transform: scaleY(.4); transition: opacity .15s ease, transform .2s ease;
}
.wt-chip .wt-ico {
    width: 24px; height: 24px; flex: 0 0 auto; display: grid; place-items: center; padding: 3px; box-sizing: border-box;
    border-radius: 8px; background: color-mix(in srgb, var(--tw, #94a3b8) 14%, #fff);
}
.wt-chip .wt-ico svg { width: 100%; height: 100%; }
/* a long name takes more lines rather than being cut */
.wt-chip .wt-name { flex: 1; min-width: 0; overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow-wrap: break-word; }
/* pinned ones come first, on a faint gold */
.wt-chip.is-fav { --wt-bg: #fffbeb; }
/* the ★ on the cell's top corner, out of the name's way */
.wt-star { position: absolute; top: 4px; inset-inline-end: 4px; width: 13px; height: 13px; color: #60a5fa; cursor: pointer; transition: color .15s ease, transform .15s ease; }
.wt-star svg { display: block; width: 100%; height: 100%; }
.wt-star:hover { color: #2563eb; transform: scale(1.18); }
.wt-star.on { color: #f59e0b; }
.wt-star.on:hover { color: #d97706; }
/* before teeth are picked the works are pale; the ★ stays clear */
.work-tray:not(.is-ready) .wt-ico,
.work-tray:not(.is-ready) .wt-name { opacity: .5; }
.work-tray.is-ready .wt-chip:hover { background: color-mix(in srgb, var(--tw, #94a3b8) 8%, var(--wt-bg)); }
.work-tray.is-ready .wt-chip:hover::before { opacity: 1; transform: none; }
.work-tray.is-ready .wt-chip:active { background: color-mix(in srgb, var(--tw, #94a3b8) 16%, var(--wt-bg)); }
/* just added: a soft flash of its colour */
@keyframes wt-added { from { background: color-mix(in srgb, var(--tw, #94a3b8) 32%, #fff); } to { background: var(--wt-bg); } }
.wt-chip.is-added { animation: wt-added .9s ease-out; }
@media (prefers-reduced-motion: reduce) { .wt-chip.is-added { animation: none; } }
.work-tray.is-busy { pointer-events: none; }
.work-tray.is-busy .wt-chip { opacity: .45; }
/* folded: the last row fades out and «هەمووی» sits on its corner, so the
   extra chips cost no row of their own; open, it follows the chips */
.work-tray { position: relative; }
.work-tray.has-more:not(.is-open) .wt-list {
    -webkit-mask-image: linear-gradient(to bottom, #000 70%, transparent);
    mask-image: linear-gradient(to bottom, #000 70%, transparent);
}
.wt-more {
    position: absolute; bottom: 8px; inset-inline-end: 10px;
    padding: 3px 11px; border: 1px solid #cfe0f1; border-radius: 999px; cursor: pointer;
    background: #fff; color: #0f6cbd; font: inherit; font-size: 12px; font-weight: 700;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
}
.wt-more:hover { background: #eef5fc; }
.work-tray.is-open .wt-more { position: static; margin-top: 7px; box-shadow: none; }
/* several works for the same teeth: a click ticks a work (its order on the
   corner), the basket above the list shows the teeth, the works and the
   total, and one button adds them all */
.wt-chip.is-picked { --wt-bg: color-mix(in srgb, var(--tw, #14b8a6) 13%, #fff); box-shadow: inset 0 0 0 2px var(--tw, #14b8a6); z-index: 1; }
.wt-chip.is-picked::before { opacity: 1; transform: none; }
.wt-chip.is-picked .wt-ico, .wt-chip.is-picked .wt-name { opacity: 1 !important; }
.wt-pick-no {
    position: absolute; bottom: 4px; inset-inline-end: 4px; min-width: 17px; height: 17px; padding: 0 4px; box-sizing: border-box;
    border-radius: 999px; background: var(--tw, #14b8a6); color: #fff; font-size: 10.5px; font-weight: 800; line-height: 17px; text-align: center;
}
.wt-basket {
    display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px; padding: 10px 12px; border-radius: 12px;
    background: #fff; border: 1px solid #99f6e4; box-shadow: 0 4px 14px -8px rgba(13, 148, 136, .45);
    animation: wt-basket-in .18s ease-out;
}
.wt-basket[hidden] { display: none; }
/* a small slide only: never invisible, even where the animation does not run */
@keyframes wt-basket-in { from { transform: translateY(-4px); } to { transform: none; } }
@media (prefers-reduced-motion: reduce) { .wt-basket { animation: none; } }
.wt-b-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.wt-b-label { font-size: 12px; font-weight: 700; color: #64748b; min-width: 52px; }
.wt-b-teeth .tooth-tag { font-size: 13px; font-weight: 800; color: #0f766e; background: #ccfbf1; border-radius: 7px; padding: 2px 7px; }
.wt-b-none { font-size: 12.5px; font-weight: 700; color: #b45309; background: #fef3c7; border-radius: 7px; padding: 2px 8px; }
.wt-b-work {
    display: inline-flex; align-items: center; gap: 5px; padding-block: 3px; padding-inline: 8px 4px; border-radius: 999px;
    background: color-mix(in srgb, var(--tw, #94a3b8) 12%, #fff); border: 1px solid color-mix(in srgb, var(--tw, #94a3b8) 40%, #fff);
    font-size: 12.5px; font-weight: 700; color: #1e293b;
}
.wt-b-work .wt-ico { width: 18px; height: 18px; display: grid; place-items: center; }
.wt-b-work .wt-ico svg { width: 100%; height: 100%; }
.wt-b-x {
    width: 18px; height: 18px; border: 0; border-radius: 50%; padding: 0; cursor: pointer; display: grid; place-items: center;
    background: rgba(15, 23, 42, .08); color: #475569; font-size: 10px;
}
.wt-b-x:hover { background: #e11d48; color: #fff; }
.wt-b-foot { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding-top: 8px; border-top: 1px dashed #ccfbf1; }
.wt-b-total { flex: 1; font-size: 13px; color: #475569; }
.wt-b-total b { color: #0f172a; font-size: 14.5px; }
.wt-b-clear {
    border: 1px solid #e2e8f0; background: #fff; color: #64748b; border-radius: 10px; padding: 7px 12px; cursor: pointer;
    font: inherit; font-size: 12.5px; font-weight: 700;
}
.wt-b-clear:hover { border-color: #fda4af; color: #be123c; background: #fff1f2; }
.wt-b-add {
    border: 0; border-radius: 10px; padding: 8px 16px; cursor: pointer; font: inherit; font-size: 13.5px; font-weight: 800;
    background: linear-gradient(135deg, #14b8a6, #0d9488); color: #fff; box-shadow: 0 6px 14px -6px rgba(13, 148, 136, .7);
    display: inline-flex; align-items: center; gap: 7px;
}
.wt-b-add:hover { filter: brightness(1.06); }
.wt-b-add:disabled { background: #cbd5e1; box-shadow: none; cursor: not-allowed; }
@keyframes wt-nudge { 0%, 100% { transform: none; } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
.work-tray.is-nudge { animation: wt-nudge .32s ease 2; }
@media (prefers-reduced-motion: reduce) { .work-tray.is-nudge { animation: none; } .wt-chip { transition: none; } }
</style>
<script>
// ── The work tray under the chart ──
// Teeth picked on the chart, then one chip: that work on every picked tooth,
// on the receipt open beside the chart — quickAddTreatments, the picker's own
// path, without the picker. Nothing picked: a chip only points at the chart.
const WT_OPEN_KEY = 'dentHisab.workTrayOpen';
let wtPicked = [];                 // works ticked in the tray, in click order
function renderWorkTray() {
    const list = document.getElementById('wtList');
    if (!list) return;
    const rows = treatmentCatalog.slice().sort((a, b) => (b.fav ? 1 : 0) - (a.fav ? 1 : 0));   // ★ first, else as listed
    const tint = t => (typeof chartTintOf === 'function')
        ? chartTintOf({ treatment_name: t.name, category: t.category, icon: t.icon }) : '#94a3b8';
    list.innerHTML = rows.map(t => {
        const at = wtPicked.indexOf(t.id);
        return `<button type="button" class="wt-chip${t.fav ? ' is-fav' : ''}${at >= 0 ? ' is-picked' : ''}" data-wt="${t.id}" style="--tw:${tint(t)}"`
            + ` aria-pressed="${at >= 0}" title="${escapeHtml(t.name)} — ${numberFormat(t.price)}">`
            + `<span class="wt-ico">${treatmentIconSvg(t)}</span><span class="wt-name">${escapeHtml(t.name)}</span>`
            + `<span class="wt-star${t.fav ? ' on' : ''}" data-wt-star="${t.id}" role="button"`
            + ` title="${t.fav ? 'لابردنی پین' : 'پین — لە سەرەتای لیستەکە'}">${TP_STAR}</span>`
            + (at >= 0 ? `<span class="wt-pick-no">${at + 1}</span>` : '') + `</button>`;
    }).join('');
    renderWorkTrayState();
    requestAnimationFrame(syncWorkTrayMore);
}
/** «هەمووی»: shown only when the chips are more than the folded tray holds. */
function syncWorkTrayMore() {
    const tray = document.getElementById('workTray'), list = document.getElementById('wtList'), more = document.getElementById('wtMore');
    if (!tray || !list || !more) return;
    // blank cells where the works run out, so the last row's hairlines close
    list.querySelectorAll('.wt-fill').forEach(el => el.remove());
    const cols = getComputedStyle(list).gridTemplateColumns.split(' ').length || 1;
    const works = list.querySelectorAll('.wt-chip').length;
    for (let i = (cols - works % cols) % cols; i > 0; i--) list.insertAdjacentHTML('beforeend', '<span class="wt-fill"></span>');
    const open = tray.classList.contains('is-open');
    const overflowing = open || list.scrollHeight > list.clientHeight + 2;
    more.hidden = !overflowing;
    tray.classList.toggle('has-more', overflowing);
    more.innerHTML = open ? '<i class="fas fa-chevron-up"></i> کەمتر'
                          : '<i class="fas fa-chevron-down"></i> هەمووی (' + works + ')';
}
function renderWorkTrayState() {
    const tray = document.getElementById('workTray');
    if (!tray) return;
    const picked = (typeof bulkSelected !== 'undefined' && bulkSelected) ? bulkSelected : [];
    tray.classList.toggle('is-ready', picked.length > 0);
    const prompt = document.getElementById('wtPrompt');
    if (prompt) {
        prompt.innerHTML = picked.length
            ? '<i class="fas fa-hand-pointer"></i> ئیشەکان هەڵبژێرە — یەک یان چەندێک، پاشان «زیادکردن»'
            : '<i class="fas fa-tooth"></i> ددان لە چارتەکە هەڵبژێرە، پاشان ئیشەکان';
    }
    renderWorkBasket();
}
// ── Several works for the same teeth ──
// A chip ticks its work (numbered in click order); the basket shows the teeth,
// the works and the total, and «زیادکردن» puts them all on — works may be
// ticked before or after the teeth.
function renderWorkBasket() {
    const box = document.getElementById('wtBasket');
    if (!box) return;
    const works = wtPicked.map(id => treatmentCatalog.find(t => t.id === id)).filter(Boolean);
    const teeth = (typeof bulkSelected !== 'undefined' && bulkSelected) ? bulkSelected : [];
    box.hidden = works.length === 0;
    document.querySelectorAll('#wtList .wt-chip').forEach(chip => {
        const at = wtPicked.indexOf(parseInt(chip.dataset.wt, 10));
        chip.classList.toggle('is-picked', at >= 0);
        chip.setAttribute('aria-pressed', at >= 0 ? 'true' : 'false');
        let no = chip.querySelector('.wt-pick-no');
        if (at < 0) { if (no) no.remove(); return; }
        if (!no) { no = document.createElement('span'); no.className = 'wt-pick-no'; chip.appendChild(no); }
        no.textContent = at + 1;
    });
    if (!works.length) return;
    const tint = t => (typeof chartTintOf === 'function')
        ? chartTintOf({ treatment_name: t.name, category: t.category, icon: t.icon }) : '#94a3b8';
    const total = works.reduce((s, t) => s + (parseFloat(t.price) || 0), 0) * Math.max(1, teeth.length);
    const toothWord = teeth.length === 1 ? 'ددانی ' + escapeHtml(String(palmerOf(teeth[0]))) : teeth.length + ' ددان';
    box.innerHTML =
        '<div class="wt-b-row wt-b-teeth"><span class="wt-b-label"><i class="fas fa-tooth"></i> ددان</span>'
        + (teeth.length ? teeth.map(toothTag).join(' ') : '<span class="wt-b-none">هێشتا ددان هەڵنەبژێردراوە — لە چارتەکە کلیک بکە</span>')
        + '</div>'
        + '<div class="wt-b-row"><span class="wt-b-label"><i class="fas fa-briefcase-medical"></i> ئیش</span>'
        + works.map(t => `<span class="wt-b-work" style="--tw:${tint(t)}"><span class="wt-ico">${treatmentIconSvg(t)}</span>${escapeHtml(t.name)}`
            + `<button type="button" class="wt-b-x" data-wt-unpick="${t.id}" title="لابردن" aria-label="لابردنی ${escapeHtml(t.name)}"><i class="fas fa-xmark"></i></button></span>`).join('')
        + '</div>'
        + '<div class="wt-b-foot"><span class="wt-b-total">کۆ: <b>' + numberFormat(total) + '</b>'
        + (teeth.length > 1 ? ' <small>(' + works.length + ' ئیش × ' + teeth.length + ' ددان)</small>' : '') + '</span>'
        + '<button type="button" class="wt-b-clear" id="wtClear"><i class="fas fa-rotate-left"></i> پاککردنەوە</button>'
        + '<button type="button" class="wt-b-add" id="wtAdd"' + (teeth.length ? '' : ' disabled') + '>'
        + '<i class="fas fa-check"></i> زیادکردنی ' + works.length + ' ئیش' + (teeth.length ? ' بۆ ' + toothWord : '') + '</button></div>';
}
function wtToggle(id) {
    const at = wtPicked.indexOf(id);
    if (at >= 0) wtPicked.splice(at, 1); else wtPicked.push(id);
    renderWorkBasket();
}
async function workTrayAddPicked() {
    const tray = document.getElementById('workTray');
    if (!tray || quickAddBusy || !wtPicked.length) return;
    if (!bulkSelected.length) {
        tray.classList.remove('is-nudge');
        void tray.offsetWidth;          // restart the shake
        tray.classList.add('is-nudge');
        toast('warning', 'سەرەتا ددان لە چارتەکە هەڵبژێرە');
        return;
    }
    const ids = wtPicked.slice();
    setQuickToothMode(bulkSelected.slice());
    tray.classList.add('is-busy');
    try {
        const done = await quickAddTreatments(ids) || [];
        // what went in is not ticked any more, so trying again does not add it twice
        wtPicked = wtPicked.filter(id => !done.some(t => t.id === id));
        done.forEach(t => {
            const chip = tray.querySelector('.wt-chip[data-wt="' + t.id + '"]');
            if (!chip) return;
            chip.classList.remove('is-added');
            void chip.offsetWidth;
            chip.classList.add('is-added');
        });
    } finally {
        tray.classList.remove('is-busy');
        setQuickToothMode(null);
        renderWorkTrayState();
    }
}
// Enter adds the ticked works (not while typing somewhere)
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !wtPicked.length || !bulkSelected.length) return;
    if (e.target.closest && e.target.closest('input, textarea, select, [contenteditable="true"], .modal, dialog')) return;
    e.preventDefault();
    workTrayAddPicked();
});
document.addEventListener('click', function (e) {
    // ★ puts a work first (the picker's own favourites, saved per user); it never adds it
    const star = e.target.closest('#workTray .wt-star');
    if (star) { tpToggleFavorite(parseInt(star.dataset.wtStar, 10)); return; }
    const unpick = e.target.closest('#workTray [data-wt-unpick]');
    if (unpick) { wtToggle(parseInt(unpick.dataset.wtUnpick, 10)); return; }
    if (e.target.closest('#wtClear')) { wtPicked = []; renderWorkBasket(); return; }
    if (e.target.closest('#wtAdd')) { workTrayAddPicked(); return; }
    const chip = e.target.closest('#workTray .wt-chip');
    if (chip) { wtToggle(parseInt(chip.dataset.wt, 10)); return; }
    if (e.target.closest('#wtMore')) {
        const tray = document.getElementById('workTray');
        const open = !tray.classList.contains('is-open');
        tray.classList.toggle('is-open', open);
        try { localStorage.setItem(WT_OPEN_KEY, open ? '1' : '0'); } catch (err) { /* private mode */ }
        syncWorkTrayMore();
    }
});
document.addEventListener('animationend', function (e) {
    if (!e.target || !e.target.classList) return;
    if (e.target.id === 'workTray') e.target.classList.remove('is-nudge');
    if (e.target.classList.contains('wt-chip')) e.target.classList.remove('is-added');
});
document.addEventListener('DOMContentLoaded', function () {
    const tray = document.getElementById('workTray');
    if (!tray) return;
    try { if (localStorage.getItem(WT_OPEN_KEY) === '1') tray.classList.add('is-open'); } catch (err) { /* private mode */ }
    renderWorkTray();
    // the column narrows or widens (⤢, the side menu, a resize): re-count the rows
    if (window.ResizeObserver) new ResizeObserver(syncWorkTrayMore).observe(tray);
});

</script>
    <?php
}
