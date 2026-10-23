<?php
/**
 * دەستپێکردنی وەسڵ — the step that opens every new sale and purchase.
 *
 * Before a single item can be entered the operator names who the document is
 * for (کڕیار / دابینکەر) and which warehouse it moves stock in. The warehouse
 * starts on the main one (erp2_default_warehouse_id), so the common case is
 * one pick and Enter. Nothing is assumed silently any more: the sales screen
 * used to start on the walk-in customer, and a name typed into the header
 * without picking it from the list still sold to walk-in.
 *
 * The page sets $docStart before including this file:
 *   kind          'customer' | 'supplier'
 *   api           parties API url (erp2/api/parties.php)
 *   title, icon, accent
 *   warehouses    erp2_warehouses(), defaultWh  erp2_default_warehouse_id()
 *   walkIn        ['id','name'] or null — offered as an explicit choice only
 *   canQuickAdd   may create a new party right here
 *   autoOpen      false for edit / prescription mode, where both are known
 *   party         ['id','name'] already chosen (those modes), else null
 *   warehouseId   warehouse already chosen, else the default
 *   lockRoot      selector of the screen the dialog covers (made inert)
 *
 * Exposes window.DocStart: ready, party, warehouseId, open({fresh}),
 * onApply(cb), canChangeWarehouse (the page may replace it).
 */
if (empty($docStart) || !is_array($docStart)) {
    return;
}
$dsIsCustomer = ($docStart['kind'] ?? 'customer') === 'customer';
$dsPartyWord  = $dsIsCustomer ? 'کڕیار' : 'دابینکەر';
$dsWarehouses = $docStart['warehouses'] ?? [];
$dsDefaultWh  = (int)($docStart['defaultWh'] ?? 0);
$dsSelectedWh = (int)($docStart['warehouseId'] ?? $dsDefaultWh) ?: $dsDefaultWh;
$dsCfg = [
    'kind'        => $dsIsCustomer ? 'customer' : 'supplier',
    'api'         => (string)$docStart['api'],
    'walkIn'      => !empty($docStart['walkIn']['id'])
        ? ['id' => (int)$docStart['walkIn']['id'], 'name' => (string)$docStart['walkIn']['name']] : null,
    'canQuickAdd' => !empty($docStart['canQuickAdd']),
    'autoOpen'    => !empty($docStart['autoOpen']),
    'party'       => !empty($docStart['party']['id'])
        ? ['id' => (int)$docStart['party']['id'], 'name' => (string)$docStart['party']['name']] : null,
    'warehouseId' => $dsSelectedWh,
    'defaultWh'   => $dsDefaultWh,
    'lockRoot'    => (string)($docStart['lockRoot'] ?? '.till'),
    'word'        => $dsPartyWord,
];
$dsJson = json_encode($dsCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="ds-overlay" id="dsOverlay" hidden>
    <div class="ds-card" role="dialog" aria-modal="true" aria-labelledby="dsTitle"
         style="--ds: <?= erp2_esc($docStart['accent'] ?? '#0f766e') ?>;">
        <div class="ds-head">
            <span class="ds-icon"><i class="fas <?= erp2_esc($docStart['icon'] ?? 'fa-file-invoice') ?>" aria-hidden="true"></i></span>
            <div>
                <h3 id="dsTitle"><?= erp2_esc($docStart['title'] ?? 'وەسڵی نوێ') ?></h3>
                <p>سەرەتا <?= $dsPartyWord ?> و کۆگا دیاری بکە، پاشان کاڵا زیاد بکە.</p>
            </div>
        </div>

        <section class="ds-step">
            <div class="ds-step-label"><span class="ds-num">1</span> <?= $dsPartyWord ?></div>

            <div class="ds-picked" id="dsPicked" hidden>
                <span class="ds-picked-icon"><i class="fas <?= $dsIsCustomer ? 'fa-user' : 'fa-industry' ?>" aria-hidden="true"></i></span>
                <div class="ds-picked-text"><b id="dsPickedName"></b><small id="dsPickedMeta"></small></div>
                <button type="button" class="ds-link" id="dsRepick">گۆڕین</button>
            </div>

            <div id="dsFind">
                <div class="ds-search">
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                    <input id="dsQ" type="search" autocomplete="off"
                           placeholder="ناو، مۆبایل یان کۆدی <?= $dsPartyWord ?>..." aria-label="گەڕانی <?= $dsPartyWord ?>">
                </div>
                <div class="ds-quick">
                    <?php if ($dsCfg['walkIn']): ?>
                    <button type="button" class="ds-chip" id="dsWalkIn">
                        <i class="fas fa-person-walking" aria-hidden="true"></i> کڕیاری ڕاستەوخۆ
                        <small><?= erp2_esc($dsCfg['walkIn']['name']) ?></small>
                    </button>
                    <?php endif; ?>
                    <?php if ($dsCfg['canQuickAdd']): ?>
                    <button type="button" class="ds-chip ds-chip-add" id="dsAddToggle">
                        <i class="fas fa-plus" aria-hidden="true"></i> <?= $dsPartyWord ?>ی نوێ
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($dsCfg['canQuickAdd']): ?>
                <div class="ds-add" id="dsAdd" hidden>
                    <input id="dsAddName" class="form-control" placeholder="ناوی <?= $dsPartyWord ?>" autocomplete="off">
                    <input id="dsAddPhone" class="form-control" placeholder="مۆبایل (ئارەزوومەندانە)" dir="ltr" autocomplete="off">
                    <button type="button" class="btn btn-primary btn-sm" id="dsAddSave">زیادکردن</button>
                </div>
                <?php endif; ?>
                <div class="ds-results" id="dsResults" role="listbox" aria-label="ئەنجامەکان"></div>
            </div>
        </section>

        <section class="ds-step">
            <div class="ds-step-label"><span class="ds-num">2</span> کۆگا</div>
            <div class="ds-whs" role="radiogroup" aria-label="کۆگا">
                <?php foreach ($dsWarehouses as $w):
                    $isMain = (int)$w['id'] === $dsDefaultWh; ?>
                <label class="ds-wh">
                    <input type="radio" name="dsWh" value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $dsSelectedWh ? 'checked' : '' ?>>
                    <span class="ds-wh-box">
                        <i class="fas <?= $isMain ? 'fa-warehouse' : 'fa-building' ?>" aria-hidden="true"></i>
                        <span><?= erp2_esc($w['name']) ?></span>
                        <?php if ($isMain): ?><em>سەرەکی</em><?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="ds-note" id="dsWhNote" hidden>
                <i class="fas fa-lock" aria-hidden="true"></i>
                کاڵا لە لیستەکەدایە و بەردەستی ئەو کۆگایە نیشان دەدات. بۆ گۆڕینی کۆگا سەرەتا لیستەکە پاک بکەرەوە.
            </div>
        </section>

        <div class="ds-msg" id="dsMsg" role="alert"></div>

        <div class="ds-foot">
            <a class="btn btn-subtle" id="dsBack" href="<?= APP_URL ?>/erp2/index.php">گەڕانەوە</a>
            <button type="button" class="btn btn-subtle" id="dsCancel" hidden>پاشگەزبوونەوە</button>
            <button type="button" class="ds-go" id="dsGo" disabled>
                دەستپێکردن <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</div>

<style>
.ds-overlay { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 20px;
    background: rgba(15, 23, 42, .42); backdrop-filter: blur(3px); }
.ds-overlay[hidden], .ds-card [hidden] { display: none !important; }
.ds-card { width: min(560px, 100%); max-height: calc(100vh - 40px); overflow: auto; background: #fff;
    border-radius: 16px; box-shadow: 0 24px 60px -20px rgba(15, 23, 42, .45); position: relative; }
.ds-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 4px; background: var(--ds); border-radius: 16px 16px 0 0; }
.ds-head { display: flex; gap: 12px; align-items: center; padding: 20px 20px 6px; }
.ds-icon { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; flex-shrink: 0;
    background: var(--ds); color: #fff; font-size: 19px; }
.ds-head h3 { margin: 0; font-size: 17px; color: #0f172a; }
.ds-head p { margin: 2px 0 0; font-size: 12.5px; color: #64748b; }
.ds-step { padding: 12px 20px 4px; }
.ds-step-label { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #1f2937; margin-bottom: 8px; }
.ds-num { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; font-size: 12px;
    background: var(--ds); color: #fff; }
.ds-search { position: relative; }
.ds-search i { position: absolute; top: 50%; inset-inline-start: 12px; transform: translateY(-50%); color: #94a3b8; }
.ds-search input { width: 100%; height: 44px; padding-inline: 38px 12px; border: 1.5px solid #dbe3ea; border-radius: 10px;
    font-size: 14px; font-family: inherit; }
.ds-search input:focus { outline: none; border-color: var(--ds); box-shadow: 0 0 0 3px rgba(15, 118, 110, .12); }
.ds-quick { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.ds-chip { display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px; border-radius: 999px; cursor: pointer;
    border: 1.5px solid #dbe3ea; background: #f8fafc; color: #334155; font-size: 12.5px; font-weight: 600; font-family: inherit; }
.ds-chip small { color: #94a3b8; font-weight: 500; }
.ds-chip:hover, .ds-chip:focus-visible { border-color: var(--ds); color: var(--ds); outline: none; }
.ds-chip-add { border-style: dashed; }
.ds-add { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
.ds-add .form-control { flex: 1 1 140px; height: 36px; }
.ds-results { margin-top: 8px; max-height: 230px; overflow: auto; border-radius: 10px; }
.ds-results:empty { display: none; }
.ds-res { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border: 0; border-bottom: 1px solid #f1f5f9;
    background: #fff; text-align: start; cursor: pointer; font-family: inherit; }
.ds-res:last-child { border-bottom: 0; }
.ds-res b { font-size: 13.5px; color: #0f172a; font-weight: 600; }
.ds-res small { display: block; font-size: 11.5px; color: #94a3b8; direction: ltr; text-align: end; }
.ds-res span { flex: 1; }
.ds-res.on, .ds-res:hover { background: #f0fdfa; }
.ds-res.on b { color: var(--ds); }
.ds-empty { padding: 12px; font-size: 12.5px; color: #94a3b8; text-align: center; }
.ds-picked { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px;
    border: 1.5px solid var(--ds); background: #f8fffd; }
.ds-picked-icon { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; background: var(--ds); color: #fff; }
.ds-picked-text { flex: 1; display: flex; flex-direction: column; }
.ds-picked-text b { font-size: 14px; color: #0f172a; }
.ds-picked-text small { font-size: 11.5px; color: #64748b; }
.ds-link { border: 0; background: none; color: var(--ds); font-weight: 700; cursor: pointer; font-family: inherit; font-size: 12.5px; }
.ds-whs { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
.ds-wh input { position: absolute; opacity: 0; pointer-events: none; }
.ds-wh-box { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 10px; border: 1.5px solid #dbe3ea;
    cursor: pointer; font-size: 13px; font-weight: 600; color: #334155; }
.ds-wh-box i { color: #94a3b8; }
.ds-wh-box em { margin-inline-start: auto; font-style: normal; font-size: 10.5px; padding: 2px 8px; border-radius: 999px;
    background: #ecfdf5; color: #047857; }
.ds-wh input:checked + .ds-wh-box { border-color: var(--ds); background: #f8fffd; color: #0f172a; box-shadow: inset 0 0 0 1px var(--ds); }
.ds-wh input:checked + .ds-wh-box i { color: var(--ds); }
.ds-wh input:focus-visible + .ds-wh-box { outline: 2px solid var(--ds); outline-offset: 2px; }
.ds-wh input:disabled + .ds-wh-box { opacity: .55; cursor: not-allowed; }
.ds-note { margin-top: 8px; padding: 8px 11px; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 12px; line-height: 1.6; }
.ds-msg { min-height: 18px; padding: 6px 20px 0; font-size: 12.5px; font-weight: 600; color: #c62828; }
.ds-foot { display: flex; gap: 8px; align-items: center; padding: 10px 20px 20px; }
.ds-foot .btn { flex: 0 0 auto; }
.ds-go { margin-inline-start: auto; display: inline-flex; align-items: center; gap: 9px; padding: 11px 22px; border: 0; border-radius: 10px;
    background: var(--ds); color: #fff; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer;
    box-shadow: 0 8px 18px -10px var(--ds); }
.ds-go:disabled { background: #cbd5e1; box-shadow: none; cursor: not-allowed; }
.ds-go:not(:disabled):hover { filter: brightness(1.06); }
.ds-go:focus-visible { outline: 3px solid rgba(15, 23, 42, .25); outline-offset: 2px; }
.ds-head-pick { display: flex; gap: 6px; align-items: center; }
.ds-head-pick .form-control[readonly] { background: #f8fafc; cursor: pointer; font-weight: 600; }
.ds-head-btn { flex: 0 0 auto; height: 32px; padding: 0 10px; border: 1px solid #c8d3da; border-radius: 5px; background: #fff;
    color: #334155; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; }
.ds-head-btn:hover { border-color: #0078D4; color: #0078D4; }
@media (prefers-reduced-motion: reduce) { .ds-overlay { backdrop-filter: none; } }
</style>

<script>
window.DocStart = (function () {
    const C = <?= $dsJson ?>;
    const $ = id => document.getElementById(id);
    const overlay = $('dsOverlay'), q = $('dsQ'), results = $('dsResults');
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let list = [], on = -1, timer = null, req = 0, pending = null, applyCb = null, lastFocus = null;

    const api = {
        ready: !C.autoOpen && !!C.party,
        party: C.party,
        warehouseId: C.warehouseId,
        isOpen: () => !overlay.hidden,
        canChangeWarehouse: () => true,
        onApply(cb) { applyCb = cb; },
        open, close
    };

    function msg(t) { $('dsMsg').textContent = t || ''; }

    function showPicked(p) {
        pending = p;
        $('dsPicked').hidden = !p;
        $('dsFind').hidden = !!p;
        if (p) {
            $('dsPickedName').textContent = p.name;
            $('dsPickedMeta').textContent = [p.phone, p.code].filter(Boolean).join(' · ');
        }
        $('dsGo').disabled = !p;
        msg('');
    }

    function renderResults(rows) {
        list = rows; on = rows.length ? 0 : -1;
        if (!rows.length) { results.innerHTML = '<div class="ds-empty">هیچ ' + C.word + 'ێک نەدۆزرایەوە</div>'; return; }
        results.innerHTML = rows.map((r, i) =>
            '<button type="button" class="ds-res' + (i === 0 ? ' on' : '') + '" role="option" data-i="' + i + '">'
            + '<i class="fas ' + (C.kind === 'customer' ? 'fa-user' : 'fa-industry') + '" style="color:#94a3b8"></i>'
            + '<span><b>' + esc(r.name) + '</b></span>'
            + '<small>' + esc([r.phone, r.code].filter(Boolean).join(' · ')) + '</small></button>'
        ).join('');
    }
    function move(d) {
        if (!list.length) return;
        on = Math.max(0, Math.min(list.length - 1, on + d));
        results.querySelectorAll('.ds-res').forEach((b, i) => b.classList.toggle('on', i === on));
        const el = results.querySelector('.ds-res.on');
        if (el) el.scrollIntoView({ block: 'nearest' });
    }
    function choose(p) { showPicked(p); setTimeout(() => $('dsGo').focus(), 0); }

    async function search() {
        const my = ++req;
        try {
            const r = await fetch(C.api + '?action=search&kind=' + C.kind + '&q=' + encodeURIComponent(q.value.trim()));
            const d = await r.json();
            if (my !== req) return;
            if (!d || !d.ok) { results.innerHTML = '<div class="ds-empty">ڕێگەت نییە لیستی ' + C.word + ' ببینیت</div>'; list = []; return; }
            renderResults(d.results || []);
        } catch (e) {
            if (my === req) { results.innerHTML = '<div class="ds-empty">کێشەی پەیوەندی</div>'; list = []; }
        }
    }

    q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 200); });
    q.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter') { e.preventDefault(); if (on >= 0 && list[on]) choose(list[on]); }
    });
    results.addEventListener('mousedown', e => {
        const b = e.target.closest('.ds-res');
        if (!b) return;
        e.preventDefault();
        choose(list[Number(b.dataset.i)]);
    });
    $('dsRepick').addEventListener('click', () => { showPicked(null); q.value = ''; q.focus(); search(); });
    if ($('dsWalkIn')) $('dsWalkIn').addEventListener('click', () => choose({ id: C.walkIn.id, name: C.walkIn.name, code: 'کڕیاری ڕاستەوخۆ' }));

    if ($('dsAddToggle')) {
        $('dsAddToggle').addEventListener('click', () => {
            const box = $('dsAdd');
            box.hidden = !box.hidden;
            if (!box.hidden) { $('dsAddName').value = q.value.trim(); $('dsAddName').focus(); }
        });
        const addSave = async () => {
            const name = $('dsAddName').value.trim();
            if (!name) { msg('ناوی ' + C.word + ' پێویستە'); $('dsAddName').focus(); return; }
            const btn = $('dsAddSave');
            btn.disabled = true;
            try {
                const fd = new FormData();
                fd.set('action', 'save'); fd.set('kind', C.kind); fd.set('name', name);
                fd.set('phone', $('dsAddPhone').value.trim()); fd.set('is_active', '1');
                const r = await fetch(C.api, { method: 'POST', headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' }, body: fd });
                const d = await r.json();
                if (!d || !d.ok) { msg((d && d.error) || 'هەڵە'); return; }
                $('dsAdd').hidden = true;
                choose({ id: Number(d.id), name: d.name, phone: $('dsAddPhone').value.trim(), code: d.code || '' });
            } catch (e) { msg('کێشەی پەیوەندی ڕوویدا'); }
            finally { btn.disabled = false; }
        };
        $('dsAddSave').addEventListener('click', addSave);
        [$('dsAddName'), $('dsAddPhone')].forEach(el => el.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); addSave(); }
        }));
    }

    function selectedWh() {
        const r = document.querySelector('input[name="dsWh"]:checked');
        return r ? Number(r.value) : 0;
    }

    $('dsGo').addEventListener('click', () => {
        if (!pending) { msg(C.word + ' هەڵبژێرە'); q.focus(); return; }
        const wh = selectedWh();
        if (!wh) { msg('کۆگا هەڵبژێرە'); return; }
        api.party = pending;
        api.warehouseId = wh;
        api.ready = true;
        close();
        if (applyCb) applyCb({ party: api.party, warehouseId: wh });
    });
    $('dsCancel').addEventListener('click', () => close());

    function setRootInert(v) {
        const root = document.querySelector(C.lockRoot);
        if (root) root.inert = v;
        const nav = document.querySelector('.mnav');
        if (nav) nav.inert = false; // the section bar stays usable to leave the page
    }

    /**
     * fresh: a new document — the party is asked again (it must never carry
     * over from the previous sale), the warehouse stays where it was.
     */
    function open(opts) {
        opts = opts || {};
        lastFocus = document.activeElement;
        if (opts.fresh) { api.ready = false; api.party = null; }
        showPicked(api.ready ? api.party : null);
        document.querySelectorAll('input[name="dsWh"]').forEach(r => { r.checked = Number(r.value) === Number(api.warehouseId); });
        const whLocked = !api.canChangeWarehouse();
        document.querySelectorAll('input[name="dsWh"]').forEach(r => { r.disabled = whLocked && !r.checked; });
        $('dsWhNote').hidden = !whLocked;
        $('dsCancel').hidden = !api.ready;
        $('dsBack').hidden = api.ready;
        q.value = '';
        overlay.hidden = false;
        setRootInert(true);
        if (window.ErpUI) window.ErpUI.hideAC();
        if (api.ready) { setTimeout(() => $('dsGo').focus(), 0); }
        else { search(); setTimeout(() => q.focus(), 0); }
    }
    function close() {
        overlay.hidden = true;
        setRootInert(false);
        msg('');
        if (!api.ready && lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // While the dialog is up, the screen's shortcuts (F2, F4, Ctrl+S, …) must
    // not reach the grid behind it. Esc closes only when nothing is lost.
    document.addEventListener('keydown', e => {
        if (overlay.hidden) return;
        const fkey = /^F\d+$/.test(e.key);
        const ctrl = e.ctrlKey && ['s', 'S', 'p', 'P'].includes(e.key);
        if (e.key === 'Escape' && api.ready) { e.preventDefault(); e.stopImmediatePropagation(); close(); return; }
        if (fkey || ctrl) { e.preventDefault(); e.stopImmediatePropagation(); }
        if (e.key === 'Delete' && !overlay.contains(document.activeElement)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    if (C.autoOpen || !C.party) {
        document.addEventListener('DOMContentLoaded', () => open());
        if (document.readyState !== 'loading') setTimeout(() => open(), 0);
    }
    return api;
})();
</script>
