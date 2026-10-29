<?php if (!isset($plan)) { exit; } ?>
<?php require __DIR__ . '/_view_script_chart.php'; ?>
<script>
// ═══════════════════════════════════════════════════════════════════
// پلانی چارەسەر — page logic. Every write goes through ajax/treatment_plan.php
// and the response (plan + items) re-renders chart, table and totals in place.
// Nothing here is money: see classes/TreatmentPlan.php.
// ═══════════════════════════════════════════════════════════════════
const API = '<?= APP_URL ?>/ajax/treatment_plan.php';
const PLAN_ID = <?= (int)$planId ?>;
const PATIENT_ID = <?= (int)$plan['patient_id'] ?>;
const CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;
const CAN_TRANSFER = <?= $canTransfer ? 'true' : 'false' ?>;
let planData = <?= json_encode($plan, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let planItems = <?= json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
<?php $favoriteTreatmentIds = $treatmentManager->favoriteIds((int)($_SESSION['user_id'] ?? 0)); ?>
let treatmentCatalog = <?= json_encode(array_map(function ($t) use ($favoriteTreatmentIds) {
    return ['id' => (int)$t['id'], 'name' => $t['treatment_name'], 'category' => $t['category'] ?? 'other', 'price' => (float)$t['default_price'],
        'icon' => $t['icon'] ?? null, 'fav' => in_array((int)$t['id'], $favoriteTreatmentIds, true)];
}, $toothTreatments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const STATUS_META = {
    open: ['چاوەڕوانی بڕیاری نەخۆش', 'badge-blue'], partial: ['بەشێکی گوازراوەتەوە', 'badge-orange'],
    transferred: ['تەواو گوازراوەتەوە', 'badge-green'], cancelled: ['هەڵوەشێنراوەتەوە', 'badge-secondary']
};

function isEditable() { return CAN_WRITE && (planData.status === 'open' || planData.status === 'partial'); }

function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    Object.keys(data || {}).forEach(k => {
        const v = data[k];
        if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
        else if (v !== undefined && v !== null) fd.append(k, v);
    });
    return fetch(API, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.json());
}
function setMsg(id, text, cls) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text || '';
    el.className = 'tp-dialog-msg' + (cls ? ' ' + cls : '');
}

// ── Derived state → chart feeds ─────────────────────────────────────
function rebuildFeeds() {
    toothTreatmentMap = {};
    serviceTreatmentRows = [];
    planItems.forEach(it => {
        if (it.status === 'cancelled') return;
        if (it.tooth_number) {
            const n = parseInt(it.tooth_number, 10);
            if (!toothTreatmentMap[n]) toothTreatmentMap[n] = { treatments: [], total_price: 0 };
            toothTreatmentMap[n].treatments.push(it);
            toothTreatmentMap[n].total_price += Number(it.final_price) || 0;
        } else {
            serviceTreatmentRows.push({
                id: it.id, treatment_name: it.treatment_name,
                arch_type: ['upper', 'lower'].includes(it.arch_type) ? it.arch_type : 'none',
                quantity: Number(it.quantity) || 1, unit_price: Number(it.unit_price) || 0,
                final_price: Number(it.final_price) || 0, category: it.category || 'other', icon: it.icon || null
            });
        }
    });
}

function applyResponse(res) {
    if (res.plan) planData = res.plan;
    if (res.items) planItems = res.items;
    renderAll();
}

function renderAll() {
    rebuildFeeds();
    renderSmartChart();
    renderItemsTable();
    renderTotals();
    renderHeader();
}

function renderHeader() {
    document.querySelectorAll('[data-plan-bind]').forEach(el => {
        const k = el.dataset.planBind;
        if (planData[k] !== undefined) el.textContent = numberFormat(planData[k]);
    });
    const badge = document.getElementById('pvStatusBadge');
    const meta = STATUS_META[planData.status] || [planData.status, 'badge-secondary'];
    const pill = { 'badge-blue': 'ch-pill--blue', 'badge-orange': 'ch-pill--pending', 'badge-green': 'ch-pill--open' }[meta[1]] || 'ch-pill--closed';
    if (badge) { badge.textContent = meta[0]; badge.className = 'ch-pill ' + pill; }
    const pending = planItems.filter(i => i.status === 'planned').length;
    const pc = document.getElementById('pvPendingCount');
    if (pc) pc.textContent = pending;
    const tb = document.getElementById('pvTransferBtn');
    if (tb) tb.style.display = pending > 0 && planData.status !== 'cancelled' ? '' : 'none';
    const live = planItems.filter(i => i.status !== 'cancelled').length;
    ['pvItemCount', 'pvSideCount'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = live; });
    const mins = Number(planData.total_minutes || 0);
    const mEl = document.getElementById('pvSideMinutes');
    if (mEl) mEl.textContent = mins ? (mins >= 60 ? Math.floor(mins / 60) + ' کاتژمێر ' + (mins % 60 ? (mins % 60) + ' خولەک' : '') : mins + ' خولەک') : '—';
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v || '—'; };
    set('pvSideDuration', planData.duration_text);
    set('pvSideComplaint', planData.chief_complaint);
    set('pvSideNotes', planData.notes);
    const ci = document.getElementById('pvComplaintInline');
    if (ci) ci.textContent = planData.chief_complaint || '';
}

// ── Items table + totals ────────────────────────────────────────────
function locationChip(it) {
    if (it.tooth_number) return `<span class="tp-tooth-chip" title="${escapeHtml(toothNames[it.tooth_number] || '')}">${palmerNumbers[it.tooth_number] || it.tooth_number} <small style="opacity:.7">(${it.tooth_number})</small></span>`;
    if (it.arch_type && it.arch_type !== 'none') return `<span class="tp-tooth-chip tp-arch-chip">${it.arch_type === 'upper' ? 'ئەرکی سەرەوە' : 'ئەرکی خوارەوە'}</span>`;
    return '<span style="color:#bbb;">—</span>';
}
// The table reads like the case's receipt (cases/partials/invoice_pane.php):
// one row per job — the same treatment, unit price, location and state — with
// its teeth in the quadrant cross, and a quick-entry row at the bottom. A row
// of several teeth opens below itself, tooth by tooth, for what differs per
// tooth (surface, discount, minutes, note) and is edited there.
const QUAD_OF = { 1: 'UR', 2: 'UL', 3: 'LL', 4: 'LR', 5: 'UR', 6: 'UL', 7: 'LL', 8: 'LR' };
const QUAD_FDI = { UR: [1, 5], UL: [2, 6], LL: [3, 7], LR: [4, 8] };
const openGroups = new Set();
let pvGroups = [];   // the rows on screen; buttons refer to them by index

function itemGroups() {
    const groups = [], byKey = {};
    planItems.filter(i => i.status !== 'cancelled').forEach(it => {
        const arch = ['upper', 'lower'].includes(it.arch_type) ? it.arch_type : 'none';
        const where = arch !== 'none' ? arch : (it.tooth_number ? 'tooth' : 'service');
        const key = [it.treatment_id || 0, it.treatment_name, Number(it.unit_price), where, it.status].join('|');
        let g = byKey[key];
        if (!g) {
            g = byKey[key] = { key, name: it.treatment_name, unit: Number(it.unit_price) || 0, where, status: it.status,
                quads: { UR: [], UL: [], LR: [], LL: [] }, qty: 0, total: 0, discount: 0, items: [], surfaces: new Set(), cases: {} };
            groups.push(g);
        }
        if (where === 'tooth') {
            const n = parseInt(it.tooth_number, 10);
            const q = QUAD_OF[Math.floor(n / 10)];
            if (q) g.quads[q].push(n);
        }
        String(it.tooth_surface || '').split(',').filter(Boolean).forEach(s => g.surfaces.add(s));
        g.qty += Number(it.quantity) || 1;
        g.total += Number(it.final_price) || 0;
        g.discount += Number(it.discount) || 0;
        g.items.push(it);
        if (it.transferred_case_id) g.cases[it.transferred_case_id] = it.transferred_case_number;
    });
    // Each quadrant from the midline outwards, as the receipt writes it.
    groups.forEach(g => Object.values(g.quads).forEach(q => q.sort((a, b) => (a % 10) - (b % 10))));
    return groups;
}

function teethCell(g) {
    if (g.where === 'upper') return '<span class="rt-location-badge is-upper"><i class="fas fa-teeth-open"></i> ماکزیلا / سەرەوە</span>';
    if (g.where === 'lower') return '<span class="rt-location-badge is-lower"><i class="fas fa-teeth-open"></i> ماندیبل / خوارەوە</span>';
    if (g.where === 'service') return '<span class="rt-location-badge is-service"><i class="fas fa-briefcase-medical"></i> بێ ژمارەی ددان</span>';
    const q = k => g.quads[k].map(n => palmerNumbers[n] || (n % 10)).join(',');
    return `<div class="tooth-cross" title="${escapeHtml(g.items.map(i => toothNames[i.tooth_number] || i.tooth_number).join('، '))}">
        <span class="tc-ur">${q('UR')}</span><span class="tc-ul">${q('UL')}</span>
        <span class="tc-lr">${q('LR')}</span><span class="tc-ll">${q('LL')}</span></div>`;
}

function groupRow(g, no, editable) {
    const transferred = g.status === 'transferred';
    const many = g.items.length > 1;
    const open = openGroups.has(g.key);
    const gi = no - 1;
    const surfaces = [...g.surfaces];
    const caseLinks = Object.keys(g.cases).map(id =>
        `<a href="<?= APP_URL ?>/cases/view.php?id=${id}" class="tp-case-link"><i class="fas fa-arrow-left"></i> ${escapeHtml(g.cases[id])}</a>`).join('');
    const desc = `${escapeHtml(g.name)}
        ${surfaces.length ? `<div class="treatment-surface-tags">${surfaces.map(s => `<span class="treatment-surface-tag">${SURFACE_LABELS[s] || s}</span>`).join('')}</div>` : ''}
        ${g.discount > 0 ? `<small class="tp-line-note">داشکاندن: ${numberFormat(g.discount)}</small>` : ''}
        ${transferred ? `<div class="tp-line-state"><span class="tp-item-state transferred">گوازراوە</span>${caseLinks}</div>` : ''}`;
    let act = '';
    if (editable && !transferred) {
        act = `<span class="rt-row-actions">
            <button type="button" class="rt-row-action is-edit" title="${many ? 'دەستکاری ددانەکان یەک یەک' : 'دەستکاری'}"
                onclick="${many ? `pvToggleGroup(${gi})` : `pvEditItem(${g.items[0].id})`}"><i class="fas fa-pen"></i></button>
            <button type="button" class="rt-row-action is-delete" title="سڕینەوە"
                onclick="${many ? `pvRemoveGroup(${gi})` : `pvRemoveItem(${g.items[0].id})`}"><i class="fas fa-trash-alt"></i></button></span>`;
    } else if (many) {
        act = `<button type="button" class="rt-row-action is-edit" title="ددانەکان یەک یەک" onclick="pvToggleGroup(${gi})"><i class="fas fa-list"></i></button>`;
    } else if (transferred) {
        act = '<i class="fas fa-lock" style="color:#a9b6c3;" title="گوازراوەتەوە بۆ کەیس"></i>';
    }
    let html = `<tr class="tp-group-row${transferred ? ' is-transferred' : ''}${open ? ' is-open' : ''}">
        <td class="rt-c-no">${no}</td>
        <td class="rt-c-desc">${desc}</td>
        <td class="rt-c-teeth">${teethCell(g)}</td>
        <td class="rt-c-qty">${numberFormat(g.qty)}</td>
        <td class="rt-c-unit">${numberFormat(g.unit)}</td>
        <td class="rt-c-total">${numberFormat(g.total)}</td>
        <td class="rt-c-act">${act}</td>
    </tr>`;
    if (many && open) html += groupDetailRow(g, editable && !transferred);
    return html;
}

// The teeth of one row, one line each — what can differ per tooth.
function groupDetailRow(g, editable) {
    const lines = g.items.map(it => `<tr>
        <td>${locationChip(it)}</td>
        <td>${String(it.tooth_surface || '').split(',').filter(Boolean).map(s => SURFACE_LABELS[s] || s).join('، ') || '—'}</td>
        <td class="tp-num">${Number(it.discount) > 0 ? numberFormat(it.discount) : '—'}</td>
        <td class="tp-num">${it.estimated_minutes ? it.estimated_minutes + ' خ' : '—'}</td>
        <td class="tp-num"><strong>${numberFormat(it.final_price)}</strong></td>
        <td>${escapeHtml(it.notes || '')}</td>
        <td>${editable ? `<div class="tp-row-actions">
            <button type="button" class="tp-icon-btn" title="دەستکاری" onclick="pvEditItem(${it.id})"><i class="fas fa-edit"></i></button>
            <button type="button" class="tp-icon-btn danger" title="سڕینەوە" onclick="pvRemoveItem(${it.id})"><i class="fas fa-trash"></i></button>
        </div>` : ''}</td>
    </tr>`).join('');
    return `<tr class="tp-group-detail"><td></td><td colspan="6"><table class="tp-detail-table"><thead><tr>
        <th>ددان</th><th>ڕووەکان</th><th>داشکاندن</th><th>کات</th><th>کۆ</th><th>تێبینی</th><th></th>
    </tr></thead><tbody>${lines}</tbody></table></td></tr>`;
}

// The quick-entry row: treatment → teeth (typed per quadrant, as on the
// receipt: «5,7») or nothing for a job without a tooth → price → Enter.
// Whole-arch work comes from «ئیشی ئەرک».
function addRowHtml() {
    const teeth = `<div class="rt-teeth-wrap">
            <div class="tooth-cross is-input">
                <input type="text" class="tc-ur rt-tooth-in" data-q="UR" placeholder="—" title="سەرەوەی ڕاست" autocomplete="off">
                <input type="text" class="tc-ul rt-tooth-in" data-q="UL" placeholder="—" title="سەرەوەی چەپ" autocomplete="off">
                <input type="text" class="tc-lr rt-tooth-in" data-q="LR" placeholder="—" title="خوارەوەی ڕاست" autocomplete="off">
                <input type="text" class="tc-ll rt-tooth-in" data-q="LL" placeholder="—" title="خوارەوەی چەپ" autocomplete="off">
            </div>
            <button type="button" class="rt-teeth-btn" tabindex="-1" onclick="showTab('chart', document.getElementById('pvTab-chart'))"
                title="هەڵبژاردنی ددان لە چارتەوە"><i class="fas fa-tooth"></i></button>
        </div>`;
    return `<tr class="rt-add-row" id="pvAddRow">
        <td class="rt-c-no"><i class="fas fa-plus" style="color:var(--win-accent, #0078D4);"></i></td>
        <td class="rt-c-desc"><div class="rt-combo">
            <input type="text" class="rt-field rt-combo-input" id="pvAddName" autocomplete="off" placeholder="چارەسەر بگەڕێ یان بنووسە..." aria-label="چارەسەر">
            <button type="button" class="rt-combo-caret" tabindex="-1" onclick="pvComboOpen(true)" title="هەموو چارەسەرەکان"><i class="fas fa-chevron-down"></i></button>
        </div></td>
        <td class="rt-c-teeth">${teeth}</td>
        <td class="rt-c-qty"><input type="number" class="rt-field rt-in-qty is-auto" id="pvAddQty" value="1" min="1" step="1" title="بۆ ددان: ژمارەی ددانەکان"></td>
        <td class="rt-c-unit"><input type="number" class="rt-field rt-in-price" id="pvAddPrice" value="0" min="0" step="any"></td>
        <td class="rt-c-total"><span class="rt-in-total" id="pvAddTotal">0</span></td>
        <td class="rt-c-act">
            <button type="button" class="rt-act-btn is-save" id="pvAddSaveBtn" onclick="pvAddSave()" title="زیادکردن (Enter)"><i class="fas fa-check"></i></button>
            <button type="button" class="rt-act-btn is-clear" tabindex="-1" onclick="pvAddClear()" title="پاککردنەوە (Esc)"><i class="fas fa-eraser"></i></button>
        </td>
    </tr>`;
}

function renderItemsTable() {
    const root = document.getElementById('pvItemsTable');
    if (!root) return;
    const editable = isEditable();
    // What is half-typed in the quick-entry row survives a redraw.
    const keep = pvAddState();
    const groups = pvGroups = itemGroups();
    let body = groups.map((g, i) => groupRow(g, i + 1, editable)).join('');
    if (editable) body += addRowHtml();
    if (!groups.length && !editable) {
        body = `<tr><td colspan="7"><div class="tp-empty-items"><i class="fas fa-clipboard-list"></i><strong>هێشتا هیچ چارەسەرێک نییە</strong></div></td></tr>`;
    }
    // Ruled to six lines, like the receipt pad.
    for (let n = groups.length + (editable ? 1 : 0) + 1; n <= 6; n++) {
        body += `<tr class="rt-blank-row"><td class="rt-c-no">${n}</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>`;
    }
    root.innerHTML = `<div class="table-container tp-items-wrap"><table class="table rcpt-table tp-items no-responsive"><thead><tr>
        <th class="rt-c-no">ژ</th><th class="rt-c-desc">ناوەڕۆک<span class="en">Description</span></th>
        <th class="rt-c-teeth">چارتی ددان<span class="en">Teeth</span></th>
        <th class="rt-c-qty">ژمارە<span class="en">Quantity</span></th><th class="rt-c-unit">نرخی تاک<span class="en">Unit Price</span></th>
        <th class="rt-c-total">نرخی کۆ<span class="en">Total Price</span></th><th class="rt-c-act">کردارەکان</th>
    </tr></thead><tbody>${body}</tbody></table></div>`;
    if (editable) pvAddBind(keep);
}

window.pvToggleGroup = function (i) {
    const g = pvGroups[i];
    if (!g) return;
    if (openGroups.has(g.key)) openGroups.delete(g.key); else openGroups.add(g.key);
    renderItemsTable();
};
window.pvRemoveGroup = async function (i) {
    const g = pvGroups[i];
    if (!g || !confirm('«' + g.name + '» بۆ ' + g.items.length + ' ددان دەسڕدرێتەوە. دڵنیایت؟')) return;
    for (const it of g.items) {
        const res = await post('remove_item', { item_id: it.id });
        if (!res || !res.success) { toast('error', (res && res.error) || 'هەڵە'); return; }
        applyResponse(res);
    }
    toast('success', 'سڕایەوە');
};

// ── Quick-entry row ─────────────────────────────────────────────────
const PALMER_TO_FDI = { UR: {}, UL: {}, LL: {}, LR: {} };
Object.keys(palmerNumbers).forEach(fdi => {
    const q = QUAD_OF[Math.floor(fdi / 10)];
    if (q) PALMER_TO_FDI[q][String(palmerNumbers[fdi]).toUpperCase()] = Number(fdi);
});
const DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
                 '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };
const toothTokens = s => String(s || '').replace(/[٠-٩۰-۹]/g, c => DIGITS[c]).toUpperCase().replace(/[^0-9A-Z]/g, '');
let pvAddTreatmentId = '';
let pvQtyManual = false;

function pvAddRow() { return document.getElementById('pvAddRow'); }
function pvAddState() {
    const row = pvAddRow();
    if (!row) return null;
    const s = { name: row.querySelector('#pvAddName').value, id: pvAddTreatmentId,
        qty: row.querySelector('#pvAddQty').value, price: row.querySelector('#pvAddPrice').value, teeth: {} };
    row.querySelectorAll('.rt-tooth-in').forEach(i => { s.teeth[i.dataset.q] = i.value; });
    const arch = row.querySelector('#pvAddArch');
    if (arch) s.arch = arch.value;
    return s;
}

// Every tooth typed in the four boxes, as FDI numbers; a box with something
// that is not a tooth of its quadrant turns red.
function pvReadTeeth() {
    const out = [];
    let bad = false;
    document.querySelectorAll('#pvAddRow .rt-tooth-in').forEach(inp => {
        let boxBad = false;
        toothTokens(inp.value).split('').forEach(tok => {
            const fdi = PALMER_TO_FDI[inp.dataset.q][tok];
            if (!fdi) { boxBad = true; return; }
            if (!out.includes(fdi)) out.push(fdi);
        });
        inp.classList.toggle('is-bad', boxBad);
        bad = bad || boxBad;
    });
    return { teeth: out, bad };
}
function pvAddCalc() {
    const row = pvAddRow();
    if (!row) return;
    const qty = row.querySelector('#pvAddQty');
    if (!pvQtyManual) {
        const n = pvReadTeeth().teeth.length;
        qty.value = n || 1;
    }
    const total = (Number(qty.value) || 0) * (Number(row.querySelector('#pvAddPrice').value) || 0);
    row.querySelector('#pvAddTotal').textContent = numberFormat(total);
}
function pvAddBind(keep) {
    const row = pvAddRow();
    const name = row.querySelector('#pvAddName');
    if (keep) {
        name.value = keep.name;
        pvAddTreatmentId = keep.id;
        name.classList.toggle('is-chosen', !!keep.id);
        row.querySelector('#pvAddQty').value = keep.qty;
        row.querySelector('#pvAddPrice').value = keep.price;
        row.querySelectorAll('.rt-tooth-in').forEach(i => { i.value = keep.teeth[i.dataset.q] || ''; });
        if (keep.arch && row.querySelector('#pvAddArch')) row.querySelector('#pvAddArch').value = keep.arch;
    }
    name.addEventListener('input', () => { pvAddTreatmentId = ''; name.classList.remove('is-chosen'); pvComboOpen(false); });
    name.addEventListener('focus', () => pvComboOpen(false));
    name.addEventListener('keydown', pvComboKey);
    row.querySelectorAll('.rt-tooth-in').forEach(inp => inp.addEventListener('input', () => {
        // «167» reads as three teeth: 1,6,7 — the same as on the receipt.
        const out = toothTokens(inp.value).split('').join(',');
        if (out !== inp.value) inp.value = out;
        pvAddCalc();
    }));
    row.querySelector('#pvAddQty').addEventListener('input', () => { pvQtyManual = true; row.querySelector('#pvAddQty').classList.remove('is-auto'); pvAddCalc(); });
    row.querySelector('#pvAddPrice').addEventListener('input', pvAddCalc);
    const arch = row.querySelector('#pvAddArch');
    if (arch) arch.addEventListener('change', pvAddCalc);
    row.addEventListener('keydown', e => {
        if (e.target === name && pvComboMenu && !pvComboMenu.hidden) return;
        if (e.key === 'Enter') { e.preventDefault(); pvAddSave(); }
        if (e.key === 'Escape') { e.preventDefault(); pvAddClear(); }
    });
    pvAddCalc();
}

// The treatment search under the name box (the receipt's rt-combo look).
let pvComboMenu = null, pvComboIdx = -1;
const fold = s => String(s || '').toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[ەة]/g, 'ه').replace(/ڕ/g, 'ر').replace(/ڵ/g, 'ل').replace(/\s+/g, ' ').trim();
window.pvComboOpen = function (all) {
    const input = document.getElementById('pvAddName');
    if (!input) return;
    if (!pvComboMenu) {
        pvComboMenu = document.createElement('ul');
        pvComboMenu.className = 'rt-combo-list';
        pvComboMenu.hidden = true;
        document.body.appendChild(pvComboMenu);
        pvComboMenu.addEventListener('mousedown', e => {
            const li = e.target.closest('.rt-combo-item');
            if (li) { e.preventDefault(); pvComboPick(li.dataset.id); }
        });
        document.addEventListener('mousedown', e => {
            if (pvComboMenu.hidden || e.target.closest('.rt-combo-list') || e.target.closest('#pvAddRow .rt-combo')) return;
            pvComboMenu.hidden = true;
        });
        // The page scrolling moves the field away from the fixed list; the
        // list scrolling itself must not close it.
        window.addEventListener('scroll', e => { if (pvComboMenu && e.target !== pvComboMenu) pvComboMenu.hidden = true; }, true);
    }
    const q = all ? '' : fold(input.value);
    const rows = treatmentCatalog.filter(t => !q || fold(t.name).includes(q)).slice(0, 80);
    pvComboMenu.innerHTML = rows.length
        ? rows.map((t, i) => `<li class="rt-combo-item" data-id="${t.id}" data-i="${i}"><span>${escapeHtml(t.name)}</span><span class="rt-combo-price">${numberFormat(t.price)}</span></li>`).join('')
        : `<li class="rt-combo-empty">${q ? 'لە لیستەکەدا نییە — بە ناوی نووسراو زیاد دەکرێت' : 'هیچ چارەسەرێک نییە'}</li>`;
    const r = input.getBoundingClientRect();
    pvComboMenu.hidden = false;
    pvComboMenu.style.width = Math.max(r.width, 280) + 'px';
    pvComboMenu.style.left = Math.max(6, Math.min(r.left, window.innerWidth - pvComboMenu.offsetWidth - 6)) + 'px';
    const below = window.innerHeight - r.bottom;
    pvComboMenu.style.top = (below < 180 && r.top > below) ? Math.max(6, r.top - pvComboMenu.offsetHeight - 4) + 'px' : (r.bottom + 4) + 'px';
    pvComboIdx = rows.length ? 0 : -1;
    pvComboMark();
    if (all) input.focus();
};
function pvComboMark() {
    if (!pvComboMenu) return;
    pvComboMenu.querySelectorAll('.rt-combo-item').forEach((li, i) => {
        li.classList.toggle('is-active', i === pvComboIdx);
        if (i === pvComboIdx) li.scrollIntoView({ block: 'nearest' });
    });
}
function pvComboKey(e) {
    if (!pvComboMenu || pvComboMenu.hidden) return;
    const items = pvComboMenu.querySelectorAll('.rt-combo-item');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!items.length) return;
        pvComboIdx = (pvComboIdx + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        pvComboMark();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (pvComboIdx >= 0 && items[pvComboIdx]) pvComboPick(items[pvComboIdx].dataset.id);
        else pvComboMenu.hidden = true;
    } else if (e.key === 'Escape') {
        pvComboMenu.hidden = true;
    }
}
function pvComboPick(id) {
    const t = treatmentCatalog.find(x => String(x.id) === String(id));
    const row = pvAddRow();
    if (!t || !row) return;
    const name = row.querySelector('#pvAddName');
    name.value = t.name;
    name.classList.add('is-chosen');
    pvAddTreatmentId = String(t.id);
    row.querySelector('#pvAddPrice').value = t.price;
    pvComboMenu.hidden = true;
    pvAddCalc();
    const next = row.querySelector('.rt-tooth-in, #pvAddArch');
    if (next) next.focus();
}

window.pvAddClear = function () {
    const row = pvAddRow();
    if (!row) return;
    row.querySelector('#pvAddName').value = '';
    row.querySelector('#pvAddName').classList.remove('is-chosen');
    row.querySelectorAll('.rt-tooth-in').forEach(i => { i.value = ''; i.classList.remove('is-bad'); });
    row.querySelector('#pvAddQty').value = 1;
    row.querySelector('#pvAddQty').classList.add('is-auto');
    row.querySelector('#pvAddPrice').value = 0;
    pvAddTreatmentId = '';
    pvQtyManual = false;
    if (pvComboMenu) pvComboMenu.hidden = true;
    pvAddCalc();
    row.querySelector('#pvAddName').focus();
};

window.pvAddSave = async function () {
    const row = pvAddRow();
    if (!row) return;
    const btn = row.querySelector('#pvAddSaveBtn');
    if (btn.disabled) return;
    const name = row.querySelector('#pvAddName').value.trim();
    if (!name) { toast('error', 'چارەسەرێک هەڵبژێرە یان ناوەکەی بنووسە'); row.querySelector('#pvAddName').focus(); return; }
    const price = Number(row.querySelector('#pvAddPrice').value);
    if (!(price >= 0)) { toast('error', 'نرخ نادروستە'); return; }
    const base = { plan_id: PLAN_ID, treatment_id: pvAddTreatmentId, treatment_name: name, unit_price: price, discount: 0 };
    const calls = [];
    const read = pvReadTeeth();
    if (read.bad) { toast('error', 'ژمارەیەکی ددان هەڵەیە (سوورە) — ١ تا ٨ یان A تا E'); return; }
    if (read.teeth.length) {
        read.teeth.forEach(fdi => calls.push(Object.assign({}, base, { tooth_number: fdi, quantity: 1 })));
    } else {
        calls.push(Object.assign({}, base, { quantity: Math.max(1, parseInt(row.querySelector('#pvAddQty').value, 10) || 1) }));
    }
    btn.disabled = true;
    let added = 0;
    try {
        for (const c of calls) {
            const res = await post('add_item', c);
            if (!res || !res.success) {
                toast('error', ((res && (res.error || res.message)) || 'هەڵە') + (added ? ' — ' + added + ' زیادکرا، ئەوانی تر نەکران' : ''));
                return;
            }
            applyResponse(res);
            added++;
        }
        pvAddClear();
        toast('success', added > 1 ? added + ' ددان زیادکران' : 'زیادکرا');
    } catch (e) {
        toast('error', 'هەڵە لە پەیوەندی');
    } finally {
        const b = document.getElementById('pvAddSaveBtn');
        if (b) b.disabled = false;
    }
};
function renderTotals() {
    const root = document.getElementById('pvTotals');
    if (!root) return;
    const mins = Number(planData.total_minutes || 0);
    const hrs = mins >= 60 ? Math.floor(mins / 60) + 'ک ' + (mins % 60) + 'خ' : mins + ' خولەک';
    root.innerHTML = `<div class="vt-foot-side">
        <div class="vt-foot-row"><span class="vt-foot-label">تێبینی :</span><span class="vt-foot-fill">${escapeHtml(planData.notes || '—')}</span></div>
        <div class="vt-foot-row"><span class="vt-foot-label">کاتی خەمڵێنراو :</span><span class="vt-foot-fill">${mins ? hrs : '—'}</span></div>
        <div class="vt-foot-row"><span class="vt-foot-label">ماوەی چارەسەر :</span><span class="vt-foot-fill">${escapeHtml(planData.duration_text || '—')}</span></div>
        <small class="tp-estimate-note"><i class="fas fa-info-circle"></i> کۆی خەمڵاندن تەنها بۆ چارەسەرە چاوەڕوانەکانە.</small>
    </div><div class="vt-foot-totals">
        <div class="vt-total-row"><span class="vt-total-label">کۆی گشتی :</span><span class="vt-total-box">${numberFormat(planData.total_estimated)}</span></div>
        <div class="vt-total-row"><span class="vt-total-label">داشکاندن :</span><span class="vt-total-box is-cut">${numberFormat(planData.total_discount)}</span></div>
        <div class="vt-total-row"><span class="vt-total-label">دوای داشکاندن :</span><span class="vt-total-box tp-quote-final">${numberFormat(planData.total_final)}</span></div>
    </div>`;
}

// ── Tooth detail panel ──────────────────────────────────────────────
let currentSelectedTooth = null;
window.showToothDetail = function (num) {
    document.querySelectorAll('.tooth.selected, .arch-tooth.selected').forEach(t => t.classList.remove('selected'));
    const el = document.querySelector('.tooth[data-tooth="' + num + '"], .arch-tooth[data-tooth="' + num + '"]');
    if (el) el.classList.add('selected');
    currentSelectedTooth = num;
    document.getElementById('toothDetailNumber').textContent = 'ددان #' + (palmerNumbers[num] || (num % 10)) + ' (FDI: ' + num + ')';
    document.getElementById('toothDetailName').textContent = toothNames[num] || 'ددان ' + num;
    document.getElementById('toothDetailSvg').innerHTML = toothSVG(num, {idPrefix: 'panel'});
    const d = toothTreatmentMap[num];
    const list = d ? d.treatments : [];
    document.getElementById('toothTreatmentCount').textContent = list.length;
    document.getElementById('toothTotalCost').textContent = numberFormat(d ? d.total_price : 0);
    const box = document.getElementById('toothTreatmentsList');
    if (!list.length) {
        box.innerHTML = `<div class="no-treatments"><i class="fas fa-tooth"></i><p>هیچ ئیشێک بۆ ئەم ددانە پلان نەکراوە</p></div>`;
    } else {
        box.innerHTML = list.map(t => {
            const cat = t.category || 'other';
            const surfaces = String(t.tooth_surface || '').split(',').filter(Boolean);
            const transferred = t.status === 'transferred';
            const btns = (isEditable() && !transferred)
                ? `<button type="button" class="treatment-del-btn" onclick="pvRemoveItem(${t.id})" title="سڕینەوە"><i class="fas fa-trash"></i></button>
                   <button type="button" class="treatment-edit-btn" onclick="closeToothDetail(); pvEditItem(${t.id})" title="دەستکاری"><i class="fas fa-edit"></i></button>` : '';
            return `<div class="treatment-history-item ${cat}">${btns}
                <div class="treatment-name">${escapeHtml(t.treatment_name)} <span style="font-weight:400;font-size:10px;color:var(--text-secondary);">(${CATEGORY_LABELS[cat] || 'چارەسەر'})</span></div>
                <div class="treatment-meta">
                    <span class="treatment-price"><i class="fas fa-money-bill"></i> ${numberFormat(t.final_price)}</span>
                    ${t.quantity > 1 ? `<span><i class="fas fa-times"></i> ${t.quantity}</span>` : ''}
                    ${t.estimated_minutes ? `<span><i class="fas fa-clock"></i> ${t.estimated_minutes} خ</span>` : ''}
                    <span class="tp-item-state ${t.status}">${transferred ? 'گوازراوە' : 'چاوەڕوان'}</span>
                </div>
                ${surfaces.length ? `<div class="treatment-surface-tags">${surfaces.map(s => `<span class="treatment-surface-tag">${SURFACE_LABELS[s] || s}</span>`).join('')}</div>` : ''}
                ${t.notes ? `<div style="margin-top:6px;font-size:11px;color:var(--text-secondary);"><i class="fas fa-sticky-note"></i> ${escapeHtml(t.notes)}</div>` : ''}
            </div>`;
        }).join('');
    }
    document.getElementById('toothDetailOverlay').classList.add('open');
    document.getElementById('toothDetailPanel').classList.add('open');
};
window.closeToothDetail = function () {
    document.getElementById('toothDetailOverlay').classList.remove('open');
    document.getElementById('toothDetailPanel').classList.remove('open');
};
window.pvAddForCurrentTooth = function () {
    closeToothDetail();
    pvOpenItemDialog({ tooth: currentSelectedTooth });
};

<?php if ($canWrite): ?>
// ── Item dialog (add tooth job / edit any job) ──────────────────────
let pickedSurfaces = new Set();
let itDefaultPrice = 0;

function itCatalogEntry() {
    const sel = document.getElementById('itTreatmentSelect');
    return treatmentCatalog.find(x => String(x.id) === sel.value) || null;
}
function itUpdatePickerBtn() {
    const btn = document.getElementById('itPickerBtn');
    const t = itCatalogEntry();
    btn.innerHTML = t
        ? `<span class="tp-btn-icon">${categoryIconFor(t)}</span><span class="tp-btn-name">${escapeHtml(t.name)}</span><span class="tp-btn-price">${numberFormat(t.price)}</span>`
        : '<i class="fas fa-hand-pointer"></i> هەڵبژاردنی چارەسەر...';
}
function itUpdateSurfaceSection() {
    const sec = document.getElementById('surfaceSection');
    const t = itCatalogEntry();
    const hasTooth = !!document.getElementById('itToothSelect').value;
    const show = hasTooth && t && SURFACE_CATEGORIES.includes(t.category || 'other');
    sec.style.display = show ? '' : 'none';
    if (!show) { pickedSurfaces.clear(); document.getElementById('itSurface').value = ''; }
    else renderSurfacePicker();
}
function renderSurfacePicker() {
    const holder = document.getElementById('surfaceToothPreview');
    const fdi = parseInt(document.getElementById('itToothSelect').value, 10) || 26;
    holder.innerHTML = toothSVG(fdi, { idPrefix: 'pick', showAllZones: true, selectedSurfaces: pickedSurfaces, state: {surfaces: {}, count: 0, treatments: []} });
    document.getElementById('surfaceChips').innerHTML = Object.keys(SURFACE_LABELS).map(s =>
        `<button type="button" class="surface-chip ${pickedSurfaces.has(s) ? 'on' : ''}" data-surface="${s}">${SURFACE_LABELS[s]}</button>`).join('');
    document.getElementById('itSurface').value = Array.from(pickedSurfaces).join(',');
}
document.addEventListener('click', function (e) {
    const z = e.target.closest('#surfaceToothPreview [data-surface], #surfaceChips [data-surface]');
    if (!z) return;
    const s = z.dataset.surface;
    if (pickedSurfaces.has(s)) pickedSurfaces.delete(s); else pickedSurfaces.add(s);
    renderSurfacePicker();
});
window.itOnToothChange = function () { itUpdateSurfaceSection(); };
window.itTreatmentChanged = function () {
    const t = itCatalogEntry();
    itDefaultPrice = t ? t.price : 0;
    document.getElementById('itDefaultPrice').textContent = numberFormat(itDefaultPrice);
    document.getElementById('itUnitPrice').value = itDefaultPrice;
    if (t) document.getElementById('itName').value = t.name;
    itUpdatePickerBtn();
    itUpdateSurfaceSection();
    itRecalc();
};
window.itUseDefault = function () { document.getElementById('itUnitPrice').value = itDefaultPrice; itRecalc(); };
window.itRecalc = function () {
    const q = Math.max(1, Number(document.getElementById('itQty').value || 1));
    const p = Math.max(0, Number(document.getElementById('itUnitPrice').value || 0));
    const d = Math.max(0, Number(document.getElementById('itDiscount').value || 0));
    document.getElementById('itTotal').textContent = numberFormat(Math.max(0, q * p - d));
};

window.pvOpenItemDialog = function (opts) {
    if (!isEditable()) return;
    opts = opts || {};
    document.getElementById('itItemId').value = '';
    document.getElementById('itTitle').textContent = 'زیادکردنی ئیش';
    document.getElementById('itSaveLabel').textContent = 'زیادکردن';
    document.getElementById('itSaveNewBtn').style.display = '';
    document.getElementById('itToothField').style.display = '';
    document.getElementById('itPickerField').style.display = '';
    document.getElementById('itToothSelect').value = opts.tooth ? String(opts.tooth) : '';
    document.getElementById('itTreatmentSelect').value = '';
    document.getElementById('itName').value = '';
    document.getElementById('itNotes').value = '';
    document.getElementById('itQty').value = 1;
    document.getElementById('itUnitPrice').value = '';
    document.getElementById('itDiscount').value = 0;
    document.getElementById('itMinutes').value = '';
    itDefaultPrice = 0;
    document.getElementById('itDefaultPrice').textContent = '0';
    pickedSurfaces.clear();
    itUpdatePickerBtn();
    itUpdateSurfaceSection();
    itRecalc();
    setMsg('itMsg', '');
    openDialog('addTreatmentDialog');
};

window.pvEditItem = function (itemId) {
    const it = planItems.find(x => Number(x.id) === Number(itemId));
    if (!it || !isEditable()) return;
    document.getElementById('itItemId').value = it.id;
    document.getElementById('itTitle').textContent = 'دەستکاری ئیش';
    document.getElementById('itSaveLabel').textContent = 'پاشەکەوت';
    document.getElementById('itSaveNewBtn').style.display = 'none';
    // Location and catalogue link are fixed once created; numbers and name stay editable.
    document.getElementById('itToothField').style.display = 'none';
    document.getElementById('itPickerField').style.display = 'none';
    document.getElementById('surfaceSection').style.display = 'none';
    document.getElementById('itToothSelect').value = it.tooth_number ? String(it.tooth_number) : '';
    document.getElementById('itTreatmentSelect').value = it.treatment_id ? String(it.treatment_id) : '';
    const t = itCatalogEntry();
    itDefaultPrice = t ? t.price : Number(it.unit_price);
    document.getElementById('itDefaultPrice').textContent = numberFormat(itDefaultPrice);
    document.getElementById('itName').value = it.treatment_name || '';
    document.getElementById('itNotes').value = it.notes || '';
    document.getElementById('itQty').value = it.quantity;
    document.getElementById('itUnitPrice').value = Number(it.unit_price);
    document.getElementById('itDiscount').value = Number(it.discount);
    document.getElementById('itMinutes').value = it.estimated_minutes || '';
    itRecalc();
    setMsg('itMsg', '');
    openDialog('addTreatmentDialog');
};

window.itSave = function (andNew) {
    const itemId = document.getElementById('itItemId').value;
    const name = document.getElementById('itName').value.trim();
    if (!name) { setMsg('itMsg', 'ناوی چارەسەر پێویستە', 'is-error'); return; }
    const payload = {
        treatment_name: name,
        quantity: document.getElementById('itQty').value,
        unit_price: document.getElementById('itUnitPrice').value,
        discount: document.getElementById('itDiscount').value,
        estimated_minutes: document.getElementById('itMinutes').value,
        notes: document.getElementById('itNotes').value.trim(),
    };
    let req;
    if (itemId) {
        payload.item_id = itemId;
        req = post('update_item', payload);
    } else {
        payload.plan_id = PLAN_ID;
        payload.treatment_id = document.getElementById('itTreatmentSelect').value || '';
        payload.tooth_number = document.getElementById('itToothSelect').value || '';
        payload.tooth_surface = document.getElementById('itSurface').value || '';
        req = post('add_item', payload);
    }
    const btns = [document.getElementById('itSaveBtn'), document.getElementById('itSaveNewBtn')];
    btns.forEach(b => b.disabled = true);
    setMsg('itMsg', 'پاشەکەوت دەکرێت...');
    req.then(res => {
        btns.forEach(b => b.disabled = false);
        if (!res || !res.success) { setMsg('itMsg', (res && (res.error || res.message)) || 'هەڵە', 'is-error'); return; }
        applyResponse(res);
        toast('success', itemId ? 'ئیشەکە نوێکرایەوە' : 'ئیشەکە زیادکرا');
        if (andNew) {
            const keepTooth = document.getElementById('itToothSelect').value;
            pvOpenItemDialog({ tooth: keepTooth });
        } else {
            closeDialog('addTreatmentDialog');
        }
    }).catch(() => { btns.forEach(b => b.disabled = false); setMsg('itMsg', 'هەڵە لە پەیوەندی', 'is-error'); });
};

window.pvRemoveItem = function (itemId) {
    if (!confirm('ئەم ئیشە بسڕدرێتەوە؟')) return;
    post('remove_item', { item_id: itemId }).then(res => {
        if (!res || !res.success) { toast('error', (res && res.error) || 'هەڵە'); return; }
        applyResponse(res);
        closeToothDetail();
        toast('success', 'ئیشەکە سڕایەوە');
    });
};

// ── Treatment picker — the case page's (cases/view.php) ─────────────
// One card a treatment type, with its icon, ★ favourites first. A click
// picks a card; «دیارکردن» (or a double click, or Enter) uses it — for the
// item dialog, or straight onto the teeth selected on the chart.
let tpActiveCat = 'all';
/** A treatment type's illustrated icon (assets/js/treatment-icons.js). */
function treatmentIconSvg(t) {
    return window.TreatmentIcons ? TreatmentIcons.svg(TreatmentIcons.keyFor(t)) : '';
}
function categoryIconFor(t) { return treatmentIconSvg(t); }

// ★ on a card: the user's own favourites, shown first (saved per user,
// api/treatments.php?action=favorite).
const TP_STAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.5l-5.9 3.1 1.2-6.5-4.8-4.6 6.6-.9z" fill="currentColor"/></svg>';
function tpToggleFavorite(id) {
    const t = treatmentCatalog.find(x => Number(x.id) === id);
    if (!t) return;
    t.fav = !t.fav;
    renderTreatmentPicker();
    fetch('<?= APP_URL ?>/api/treatments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
        body: JSON.stringify({ action: 'favorite', id: id, on: t.fav })
    }).then(r => r.json())
        .then(d => { if (!d.success) throw new Error(d.message || ''); })
        .catch(() => {
            t.fav = !t.fav;
            renderTreatmentPicker();
            toast('error', 'نەتوانرا ئەستێرەکە پاشەکەوت بکرێت');
        });
}

let tpSelectedId = null;
let tpPicked = [];                  // ids picked for the chart's teeth, in click order
function tpPaintSelection() {
    // from the chart several cards can be picked; for a dialog's field, one
    const multi = tpQuickTeeth !== null;
    document.querySelectorAll('#tpList .tp-card').forEach(c => {
        const id = Number(c.dataset.id);
        c.classList.toggle('on', multi ? tpPicked.includes(id) : id === tpSelectedId);
    });
    const ok = document.getElementById('tpConfirm');
    if (ok) {
        const n = multi ? tpPicked.length : (tpSelectedId === null ? 0 : 1);
        ok.disabled = !n || quickAddBusy;
        ok.textContent = 'دیارکردن' + (multi && n > 1 ? ' (' + n + ')' : '');
    }
}
window.tpConfirmPick = function () {
    if (quickAddBusy) return;
    if (tpQuickTeeth !== null) { if (tpPicked.length) quickAddTreatments(tpPicked.slice()); }
    else if (tpSelectedId !== null) chooseTreatment(tpSelectedId);
};
window.openTreatmentPicker = function () {
    const ov = document.getElementById('treatmentPicker');
    if (!ov) return;
    // from the item dialog: the treatment already chosen there starts picked
    const sel = document.getElementById('itTreatmentSelect');
    tpSelectedId = sel && sel.value ? parseInt(sel.value, 10) : null;
    ov.classList.add('open');
    renderTreatmentPicker();
    setTimeout(() => document.getElementById('tpSearch')?.focus(), 60);
};
window.closeTreatmentPicker = function () {
    document.getElementById('treatmentPicker')?.classList.remove('open');
    setQuickToothMode(null);
};
function chooseTreatment(id) {
    document.getElementById('itTreatmentSelect').value = String(id);
    itTreatmentChanged();
    closeTreatmentPicker();
}

// ── Quick add from the chart: teeth → treatment → planned ──
// The catalog's price, quantity 1 a tooth, no discount — corrected
// afterwards in the items table, as on the case page.
let tpQuickTeeth = null;            // FDI numbers the picked work goes to, or null
let quickAddBusy = false;
const palmerOf = num => palmerNumbers[num] || (num % 10);
/** A tooth as dentists write it (Palmer): 3┘ upper right, └3 upper left, 3┐ lower right, ┌3 lower left. */
function toothTag(num) {
    const q = Math.floor(num / 10), p = escapeHtml(String(palmerOf(num)));
    const t = (q === 1 || q === 5) ? p + '┘' : (q === 2 || q === 6) ? '└' + p : (q === 4 || q === 8) ? p + '┐' : '┌' + p;
    return '<bdi dir="ltr" class="tooth-tag">' + t + '</bdi>';
}
function setQuickToothMode(nums) {
    tpQuickTeeth = nums === null ? null : [].concat(nums);
    const title = document.getElementById('tpTitle');
    if (!title) return;
    const history = document.getElementById('tpHistory');
    if (tpQuickTeeth === null) {
        title.innerHTML = '<i class="fas fa-briefcase-medical"></i> هەڵبژاردنی چارەسەر';
        if (history) history.hidden = true;
        return;
    }
    tpSelectedId = null;
    tpPicked = [];
    tpPaintSelection();
    if (tpQuickTeeth.length === 1) {
        const num = tpQuickTeeth[0];
        title.innerHTML = '<i class="fas fa-tooth"></i> ددان ' + escapeHtml(String(palmerOf(num)))
            + ' <small>' + escapeHtml(toothNames[num] || '') + '</small>';
        const count = (toothTreatmentMap[num] && toothTreatmentMap[num].treatments || []).length;
        if (history) {
            history.hidden = count === 0;
            history.innerHTML = '<i class="fas fa-history"></i> ' + count + ' ئیشی پلانکراو';
        }
    } else {
        title.innerHTML = '<i class="fas fa-tooth"></i> ' + tpQuickTeeth.length + ' ددان'
            + ' <small>' + tpQuickTeeth.map(toothTag).join(' ') + ' — هەر ددانێک بە نرخی خۆی</small>';
        if (history) history.hidden = true;
    }
    title.insertAdjacentHTML('beforeend', ' <small class="tp-multi-hint">· دەتوانیت چەند چارەسەرێک هەڵبژێریت</small>');
}
/** Right click, or «زیادکردنی ئیش» on the bar: the cards for every selected tooth. */
window.openQuickTeethAdd = function () {
    if (!bulkSelected.length || !isEditable()) return;
    openTreatmentPicker();
    setQuickToothMode(bulkSelected.slice());
};
/** Every picked treatment onto every selected tooth, one request a treatment (each all-or-nothing). */
async function quickAddTreatments(ids) {
    const list = ids.map(id => treatmentCatalog.find(x => x.id === id)).filter(Boolean);
    if (!list.length || quickAddBusy || !tpQuickTeeth || !tpQuickTeeth.length) return;
    quickAddBusy = true;
    tpPaintSelection();
    const teeth = tpQuickTeeth.slice();
    const done = [];
    document.getElementById('tpList')?.classList.add('is-busy');
    try {
        for (const t of list) {
            const base = { plan_id: PLAN_ID, treatment_id: t.id, treatment_name: t.name, unit_price: t.price, discount: 0, quantity: 1 };
            const res = await (teeth.length === 1
                ? post('add_item', Object.assign({ tooth_number: teeth[0] }, base))
                : post('add_items', Object.assign({ tooth_numbers: teeth }, base)));
            if (!res || !res.success) {
                toast('error', (done.length ? done.length + ' زیادکرا؛ ' : '') + t.name + ': ' + ((res && (res.error || res.message)) || 'هەڵەیەک ڕوویدا'));
                break;
            }
            applyResponse(res);
            done.push(t);
        }
        if (done.length === list.length) {
            closeTreatmentPicker();
            clearBulkSelection();
            toast('success', (done.length === 1 ? done[0].name : done.length + ' چارەسەر')
                + (teeth.length === 1 ? ' بۆ ددان ' + palmerOf(teeth[0]) : ' بۆ ' + teeth.length + ' ددان') + ' زیادکرا');
        } else {
            tpPicked = tpPicked.filter(id => !done.some(t => t.id === id));
        }
    } catch (e) {
        toast('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن');
    } finally {
        quickAddBusy = false;
        document.getElementById('tpList')?.classList.remove('is-busy');
        tpPaintSelection();
    }
}
window.openQuickToothHistory = function () {
    const num = tpQuickTeeth && tpQuickTeeth.length === 1 ? tpQuickTeeth[0] : null;
    closeTreatmentPicker();
    if (num !== null) { clearBulkSelection(); showToothDetail(num); }
};

function renderTreatmentPicker() {
    const listEl = document.getElementById('tpList');
    const catsEl = document.getElementById('tpCats');
    if (!listEl || !catsEl) return;
    const q = (document.getElementById('tpSearch')?.value || '').trim().toLowerCase();
    const present = [];
    treatmentCatalog.forEach(t => { const c = t.category || 'other'; if (!present.includes(c)) present.push(c); });
    const cats = ['all'].concat(present);
    if (!cats.includes(tpActiveCat)) tpActiveCat = 'all';
    catsEl.innerHTML = cats.map(c =>
        `<button type="button" class="tp-cat ${tpActiveCat === c ? 'on' : ''}" data-cat="${c}">${c === 'all' ? 'هەموو' : (CATEGORY_LABELS[c] || c)}</button>`
    ).join('');
    const rows = treatmentCatalog.filter(t =>
        (tpActiveCat === 'all' || (t.category || 'other') === tpActiveCat) &&
        (!q || t.name.toLowerCase().includes(q))
    ).sort((a, b) => (b.fav ? 1 : 0) - (a.fav ? 1 : 0));   // stable: ★ first, else as listed
    const card = t => `<button type="button" class="tp-card${t.fav ? ' is-fav' : ''}" data-id="${t.id}">
            <span class="tp-card-check"><i class="fas fa-check"></i></span>
            <span class="tp-card-star" data-star="${t.id}" role="button"
                  title="${t.fav ? 'لابردن لە دڵخوازەکان' : 'دڵخواز — لە سەرەتای لیستەکە پیشان بدرێت'}">${TP_STAR}</span>
            <span class="tp-card-art">${treatmentIconSvg(t)}</span>
            <span class="tp-card-name">${escapeHtml(t.name)}</span>
            <span class="tp-card-price">${numberFormat(t.price)}</span>
        </button>`;
    const favs = rows.filter(t => t.fav), rest = rows.filter(t => !t.fav);
    listEl.innerHTML = !rows.length ? '<div class="tp-empty">هیچ چارەسەرێک نەدۆزرایەوە</div>'
        : favs.length && rest.length
            ? '<div class="tp-section"><i class="fas fa-star"></i> دڵخوازەکانم</div>' + favs.map(card).join('')
              + '<div class="tp-section">هەموو چارەسەرەکان</div>' + rest.map(card).join('')
            : rows.map(card).join('');
    tpPaintSelection();
}
document.addEventListener('click', function (e) {
    const cat = e.target.closest('#tpCats .tp-cat');
    if (cat) { tpActiveCat = cat.dataset.cat; renderTreatmentPicker(); return; }
    const star = e.target.closest('#tpList .tp-card-star');
    if (star) { tpToggleFavorite(parseInt(star.dataset.star, 10)); return; }
    const card = e.target.closest('#tpList .tp-card');
    if (card) {
        const id = parseInt(card.dataset.id, 10);
        if (tpQuickTeeth !== null) {
            const i = tpPicked.indexOf(id);
            if (i === -1) tpPicked.push(id); else tpPicked.splice(i, 1);
        } else {
            tpSelectedId = id;
        }
        tpPaintSelection();
        return;
    }
    const ov = document.getElementById('treatmentPicker');
    if (ov && e.target === ov) closeTreatmentPicker();
});
document.addEventListener('dblclick', function (e) {
    const card = e.target.closest('#tpList .tp-card');
    if (!card || e.target.closest('.tp-card-star')) return;
    const id = parseInt(card.dataset.id, 10);
    if (tpQuickTeeth !== null) { if (!tpPicked.includes(id)) tpPicked.push(id); }
    else tpSelectedId = id;
    tpConfirmPick();
});
document.addEventListener('input', function (e) { if (e.target && e.target.id === 'tpSearch') renderTreatmentPicker(); });
// Enter in the search: uses the picked card, or picks the first one shown
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target || e.target.id !== 'tpSearch') return;
    e.preventDefault();
    const shown = Array.from(document.querySelectorAll('#tpList .tp-card')).map(c => Number(c.dataset.id));
    if (tpQuickTeeth !== null) {
        if (tpPicked.length) tpConfirmPick();
        else if (shown.length) { tpPicked = [shown[0]]; tpPaintSelection(); }
    } else if (tpSelectedId !== null && shown.includes(tpSelectedId)) tpConfirmPick();
    else if (shown.length) { tpSelectedId = shown[0]; tpPaintSelection(); }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('treatmentPicker')?.classList.contains('open')) { e.stopPropagation(); closeTreatmentPicker(); }
}, true);

// ── Teeth picked on the chart, as on the case page ──────────────────
// A click picks a tooth (a second click lets it go); press and sweep across
// the teeth to pick them all; Ctrl+click adds one, Shift+click a range. A
// right click opens the cards for the selection — or that tooth alone. The
// bar at the bottom does the same on a touch screen. On a plan that can no
// longer change, a click or right click shows the tooth's planned work.
let bulkSelected = [];               // FDI tooth numbers, in click order
let lastBulkClicked = null;
function toggleBulkTooth(num) {
    num = parseInt(num, 10);
    const i = bulkSelected.indexOf(num);
    if (i === -1) bulkSelected.push(num); else bulkSelected.splice(i, 1);
    lastBulkClicked = num;
    renderBulkSelection();
}
function selectBulkRange(num) {
    num = parseInt(num, 10);
    const order = [...document.querySelectorAll('#smartDentalChart .dentition-pane:not([hidden]) .tooth')]
        .map(t => parseInt(t.dataset.tooth, 10));
    if (lastBulkClicked == null || order.indexOf(lastBulkClicked) === -1) { toggleBulkTooth(num); return; }
    let a = order.indexOf(lastBulkClicked), b = order.indexOf(num);
    if (a > b) { const t = a; a = b; b = t; }
    for (let i = a; i <= b; i++) if (!bulkSelected.includes(order[i])) bulkSelected.push(order[i]);
    renderBulkSelection();
}
function renderBulkSelection() {
    document.querySelectorAll('.smart-dental-chart .tooth.bulk-selected').forEach(t => t.classList.remove('bulk-selected'));
    bulkSelected.forEach(num => {
        document.querySelectorAll('.smart-dental-chart .tooth[data-tooth="' + num + '"]').forEach(el => el.classList.add('bulk-selected'));
    });
    const bar = document.getElementById('bulkActionBar');
    if (!bar) return;
    if (!bulkSelected.length) { bar.style.display = 'none'; return; }
    bar.style.display = 'block';
    const hist = document.getElementById('bulkHistBtn');
    if (hist) hist.style.display = bulkSelected.length === 1 ? '' : 'none';
    document.getElementById('bulkCount').textContent = bulkSelected.length;
    document.getElementById('bulkTeethList').innerHTML = bulkSelected.map(num =>
        '<span class="bt-chip" title="' + escapeHtml(toothNames[num] || ('ددان ' + num)) + '">' + toothTag(num) +
        ' <span class="bt-x" onclick="toggleBulkTooth(' + num + ')">&times;</span></span>'
    ).join('');
}
function clearBulkSelection() {
    bulkSelected = [];
    renderBulkSelection();
}
/** «مێژوو» on the bar: the one selected tooth's planned work. */
function bulkShowHistory() {
    if (bulkSelected.length !== 1) return;
    const num = bulkSelected[0];
    clearBulkSelection();
    showToothDetail(num);
}
document.addEventListener('DOMContentLoaded', function () {
    const chart = document.getElementById('smartDentalChart');
    if (!chart) return;
    // Sweep: starting on an unpicked tooth picks every tooth passed over,
    // starting on a picked one lets them go. A press without moving is the
    // click. elementFromPoint, not pointerenter: a finger's pointer stays
    // captured by the tooth it started on.
    let sweep = null, sweptClick = false;
    const sweepApply = function (num) {
        if (!sweep || num === sweep.last) return;
        sweep.last = num;
        const i = bulkSelected.indexOf(num);
        if (sweep.add && i === -1) bulkSelected.push(num);
        if (!sweep.add && i !== -1) bulkSelected.splice(i, 1);
        lastBulkClicked = num;
        renderBulkSelection();
    };
    chart.addEventListener('pointerdown', function (e) {
        if (!isEditable() || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
        const toothEl = e.target.closest('.tooth');
        if (!toothEl) return;
        const num = parseInt(toothEl.dataset.tooth, 10);
        sweep = { add: !bulkSelected.includes(num), last: null };
        sweepApply(num);
        sweptClick = true;      // the click that follows is already handled here
    });
    chart.addEventListener('pointermove', function (e) {
        if (!sweep) return;
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const toothEl = el && el.closest('.smart-dental-chart .tooth');
        if (toothEl) sweepApply(parseInt(toothEl.dataset.tooth, 10));
    });
    const sweepEnd = function () { sweep = null; setTimeout(() => { sweptClick = false; }, 0); };
    document.addEventListener('pointerup', sweepEnd);
    document.addEventListener('pointercancel', sweepEnd);

    // Hold a tooth: the list of its planned work, without picking it (tooth-art.js)
    const toothHold = initToothHold(chart, {
        snapshot: () => bulkSelected.slice(),
        undo: before => { sweep = null; bulkSelected = before; renderBulkSelection(); },
        list: num => (toothTreatmentMap[num] && toothTreatmentMap[num].treatments) || [],
        more: num => showToothDetail(num)
    });

    // Capture phase, so this beats the tooth's own onclick="showToothDetail(...)"
    chart.addEventListener('click', function (e) {
        const toothEl = e.target.closest('.tooth');
        if (toothEl && toothHold.held()) { e.stopPropagation(); e.preventDefault(); return; }   // that press was a hold
        if (!toothEl || !isEditable()) return;
        const num = parseInt(toothEl.dataset.tooth, 10);
        if (e.ctrlKey || e.metaKey) { e.stopPropagation(); e.preventDefault(); toggleBulkTooth(num); }
        else if (e.shiftKey) { e.stopPropagation(); e.preventDefault(); selectBulkRange(num); }
        else if (sweptClick) { e.stopPropagation(); e.preventDefault(); }
    }, true);

    chart.addEventListener('contextmenu', function (e) {
        const toothEl = e.target.closest('.tooth');
        if (!toothEl) return;
        e.preventDefault();
        if (toothHold.held()) return;          // a finger's hold showed the list already
        const num = parseInt(toothEl.dataset.tooth, 10);
        if (!isEditable()) { showToothDetail(num); return; }
        if (!bulkSelected.includes(num)) {
            bulkSelected = [num];
            lastBulkClicked = num;
            renderBulkSelection();
        }
        openQuickTeethAdd();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !document.querySelector('.dialog-overlay.open, .tp-overlay.open')) clearBulkSelection();
    });
});

// ── «ئیشی ئەرک» — the case's dialog (cases/view.php): the arch, then the
// jobs as cards, several at once, each at its catalog price an arch ─────
const ARCH_TREATMENTS = <?= json_encode(array_map(function ($t) {
    return ['id' => (int)$t['id'], 'name' => $t['treatment_name'], 'category' => $t['category'] ?? 'other',
        'icon' => $t['icon'] ?? null, 'price' => (float)$t['default_price']];
}, $archTreatments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let arArch = '', arPicked = [];     // the arch, and the picked cards: [{id, name, icon, category, price}]
window.pvOpenArchDialog = function () {
    if (!isEditable()) return;
    arDrawArches();
    arArch = ''; arPicked = [];
    document.querySelectorAll('.ar2-arch').forEach(b => b.classList.remove('on'));
    const box = document.getElementById('arCards');
    box.innerHTML = ARCH_TREATMENTS.length ? ARCH_TREATMENTS.map(t => `<button type="button" class="tp-card" data-id="${t.id}" onclick="arPickTreatment(${t.id})">
            <span class="tp-card-check"><i class="fas fa-check"></i></span>
            <span class="tp-card-art">${treatmentIconSvg(t)}</span>
            <span class="tp-card-name">${escapeHtml(t.name)}</span>
            <span class="tp-card-price">${numberFormat(t.price)}</span>
        </button>`).join('')
        : '<div class="ar2-empty">هیچ جۆرێکی ئیشی ئەرک نییە — لە «جۆری چارەسەر» یەکێک بە بواری «ئەرک» زیاد بکە</div>';
    setMsg('arMsg', '');
    arCalc();
    openDialog('archTreatmentDialog');
};
window.arSetArch = function (arch) {
    arArch = arch;
    document.querySelectorAll('.ar2-arch').forEach(b => b.classList.toggle('on', b.dataset.arch === arch));
    arCalc();
};
/** A card click picks it, a second click lets it go — several can be picked. */
window.arPickTreatment = function (id) {
    const i = arPicked.findIndex(p => p.id === id);
    if (i !== -1) arPicked.splice(i, 1);
    else {
        const t = ARCH_TREATMENTS.find(x => x.id === id);
        if (t) arPicked.push(Object.assign({}, t));
    }
    document.querySelectorAll('#arCards .tp-card').forEach(c => c.classList.toggle('on', arPicked.some(p => p.id === Number(c.dataset.id))));
    arCalc();
};
/** Each card at its catalog price an arch; «هەردوو» is two arches. */
function arCalc() {
    const n = arArch === 'both' ? 2 : (arArch ? 1 : 0);
    const total = arPicked.reduce((sum, p) => sum + p.price * n, 0);
    document.getElementById('archTotalPriceDisplay').textContent = n && arPicked.length ? numberFormat(total) : '—';
    const save = document.getElementById('arSaveBtn');
    save.disabled = !(arArch && arPicked.length);
    save.innerHTML = '<i class="fas fa-plus-circle"></i> زیادکردن' + (arPicked.length > 1 ? ' (' + arPicked.length + ')' : '');
}
window.arSave = function () {
    if (!arArch) { setMsg('arMsg', 'ئەرکێک هەڵبژێرە', 'is-error'); return; }
    if (!arPicked.length) { setMsg('arMsg', 'ئیشێک هەڵبژێرە', 'is-error'); return; }
    const data = { plan_id: PLAN_ID, arch_type: arArch };
    arPicked.forEach((p, i) => {
        data['items[' + i + '][treatment_id]'] = p.id;
        data['items[' + i + '][treatment_name]'] = p.name;
        data['items[' + i + '][unit_price]'] = p.price;
    });
    const btn = document.getElementById('arSaveBtn');
    btn.disabled = true;
    setMsg('arMsg', 'پاشەکەوت دەکرێت...');
    post('add_arch_items', data).then(res => {
        if (!res || !res.success) { btn.disabled = false; setMsg('arMsg', (res && (res.error || res.message)) || 'هەڵە', 'is-error'); return; }
        const n = arPicked.length;
        applyResponse(res);
        closeDialog('archTreatmentDialog');
        toast('success', n > 1 ? n + ' ئیشی ئەرک زیادکرا' : 'ئیشی ئەرک زیادکرا');
    }).catch(() => { btn.disabled = false; setMsg('arMsg', 'هەڵە لە پەیوەندی', 'is-error'); });
};
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.chart-arch-btn .chart-arch-ico').forEach(ico => { if (window.TreatmentIcons) ico.innerHTML = TreatmentIcons.svg('orthodontic'); });
});

// ── Header edit / cancel ────────────────────────────────────────────
window.pvOpenHeaderEdit = function () { setMsg('hdMsg', ''); openDialog('planHeaderDialog'); };
window.hdSave = function () {
    const btn = document.getElementById('hdSaveBtn');
    btn.disabled = true;
    const payload = {
        plan_id: PLAN_ID,
        chief_complaint: document.getElementById('hdComplaint').value.trim(),
        duration_text: document.getElementById('hdDuration').value.trim(),
        notes: document.getElementById('hdNotes').value.trim(),
    };
    const arch = document.querySelector('input[name="hdArch"]:checked');
    if (arch) payload.selected_arch = arch.value;
    post('update_plan', payload).then(res => {
        btn.disabled = false;
        if (!res || !res.success) { setMsg('hdMsg', (res && res.error) || 'هەڵە', 'is-error'); return; }
        if (res.plan) planData = res.plan;
        renderAll();
        closeDialog('planHeaderDialog');
        toast('success', 'پاشەکەوت کرا');
    }).catch(() => { btn.disabled = false; setMsg('hdMsg', 'هەڵە لە پەیوەندی', 'is-error'); });
};
window.pvCancelPlan = function () {
    const reason = prompt('هۆکاری هەڵوەشاندنەوە (ئارەزوومەندانە):');
    if (reason === null) return;
    post('cancel_plan', { plan_id: PLAN_ID, reason: reason }).then(res => {
        if (!res || !res.success) { toast('error', (res && res.error) || 'هەڵە'); return; }
        location.reload();
    });
};
<?php endif; ?>

<?php if ($canTransfer): ?>
// ── گواستنەوە ──────────────────────────────────────────────────────
window.pvOpenTransfer = function () {
    const pending = planItems.filter(i => i.status === 'planned');
    if (!pending.length) { toast('info', 'هیچ ئایتمی چاوەڕوان نییە'); return; }
    setMsg('tpTrMsg', '');
    document.getElementById('tpTrItems').innerHTML = pending.map(it => `
        <label class="tp-tr-item">
            <input type="checkbox" class="tp-tr-cb" value="${it.id}" checked data-price="${Number(it.final_price)}" onchange="tpTrSum()">
            <span>${it.tooth_number ? 'ددان ' + it.tooth_number + ' — ' : (it.arch_type && it.arch_type !== 'none' ? (it.arch_type === 'upper' ? 'ئەرکی سەرەوە' : 'ئەرکی خوارەوە') + ' — ' : '')}${escapeHtml(it.treatment_name)}</span>
            <span class="tp-tr-price">${numberFormat(it.final_price)}</span>
        </label>`).join('');
    tpTrTargetChanged();
    tpTrSum();
    openDialog('tpTransferDialog');
};
window.tpTrSum = function () {
    const boxes = Array.from(document.querySelectorAll('.tp-tr-cb:checked'));
    const total = boxes.reduce((s, b) => s + Number(b.dataset.price || 0), 0);
    document.getElementById('tpTrSummary').innerHTML = `${boxes.length} ئایتم — کۆی گشتی <b style="direction:ltr;display:inline-block;">${numberFormat(total)}</b> دەچێتە ناو کەیس`;
};
window.tpTrTargetChanged = function () {
    const mode = (document.querySelector('input[name="tpTrTarget"]:checked') || {}).value;
    document.getElementById('tpTrNewCaseBox').hidden = mode !== 'new';
    document.getElementById('tpTrExistingCaseBox').hidden = mode !== 'existing';
};
window.tpDoTransfer = function () {
    const btn = document.getElementById('tpTrSave');
    const ids = Array.from(document.querySelectorAll('.tp-tr-cb:checked')).map(b => b.value);
    if (!ids.length) { setMsg('tpTrMsg', 'هیچ ئایتمێک هەڵنەبژێردراوە', 'is-error'); return; }
    const mode = (document.querySelector('input[name="tpTrTarget"]:checked') || {}).value;
    const payload = { plan_id: PLAN_ID, item_ids: ids };
    if (mode === 'existing') {
        payload.case_id = document.getElementById('tpTrCaseId').value;
        if (!payload.case_id) { setMsg('tpTrMsg', 'تکایە کەیس هەڵبژێرە', 'is-error'); return; }
    } else {
        payload.case_name = document.getElementById('tpTrCaseName').value;
        if (!payload.case_name) { setMsg('tpTrMsg', 'تکایە ناوی کەیس هەڵبژێرە', 'is-error'); return; }
    }
    btn.disabled = true;
    setMsg('tpTrMsg', 'دەگوازرێتەوە...');
    post('transfer', payload).then(res => {
        btn.disabled = false;
        if (!res || !res.success) { setMsg('tpTrMsg', (res && res.error) || 'هەڵە لە گواستنەوە', 'is-error'); return; }
        setMsg('tpTrMsg', res.moved + ' ئایتم گوازرایەوە', 'is-ok');
        location.href = '<?= APP_URL ?>/cases/view.php?id=' + res.case_id;
    }).catch(() => { btn.disabled = false; setMsg('tpTrMsg', 'هەڵە لە پەیوەندی', 'is-error'); });
};
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function () {
    renderAll();
    const qs = new URLSearchParams(location.search);
    if (qs.get('tab') === 'items' || location.hash === '#items') showTab('items', document.getElementById('pvTab-items'));
    // «گواستنەوە بۆ کەیس» on the patient's plans tab lands here with the dialog open.
    if (qs.get('transfer') === '1' && typeof window.pvOpenTransfer === 'function') window.pvOpenTransfer();
});
</script>
