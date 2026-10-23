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
const IS_ARCH = <?= $isArch ? 'true' : 'false' ?>;
const CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;
const CAN_TRANSFER = <?= $canTransfer ? 'true' : 'false' ?>;
let planData = <?= json_encode($plan, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let planItems = <?= json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
let treatmentCatalog = <?= json_encode(array_map(function ($t) {
    return ['id' => (int)$t['id'], 'name' => $t['treatment_name'], 'category' => $t['category'] ?? 'other', 'price' => (float)$t['default_price']];
}, $toothTreatments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const STATUS_META = {
    open: ['چاوەڕوانی بڕیاری نەخۆش', 'badge-blue'], partial: ['بەشێکی گوازراوەتەوە', 'badge-orange'],
    transferred: ['تەواو گوازراوەتەوە', 'badge-green'], cancelled: ['هەڵوەشێنراوەتەوە', 'badge-secondary']
};
const ARCH_LABELS = { upper: 'ئەرکی سەرەوە', lower: 'ئەرکی خوارەوە', both: 'هەردوو ئەرک' };

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
                final_price: Number(it.final_price) || 0, category: it.category || 'other'
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
    if (IS_ARCH) renderArchVisibility();
}

function renderHeader() {
    document.querySelectorAll('[data-plan-bind]').forEach(el => {
        const k = el.dataset.planBind;
        if (planData[k] !== undefined) el.textContent = numberFormat(planData[k]);
    });
    const badge = document.getElementById('pvStatusBadge');
    const meta = STATUS_META[planData.status] || [planData.status, 'badge-secondary'];
    if (badge) { badge.textContent = meta[0]; badge.className = 'badge ' + meta[1]; }
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
    ['pvArchLabel', 'pvArchChip', 'pvArchChip2'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = ARCH_LABELS[planData.selected_arch] || 'ئەرک'; });
}

function renderArchVisibility() {
    const sel = planData.selected_arch;
    const up = document.getElementById('archPanel-upper');
    const lo = document.getElementById('archPanel-lower');
    if (up) up.hidden = !(sel === 'upper' || sel === 'both' || !sel);
    if (lo) lo.hidden = !(sel === 'lower' || sel === 'both' || !sel);
}

// ── Items table + totals ────────────────────────────────────────────
function locationChip(it) {
    if (it.tooth_number) return `<span class="tp-tooth-chip" title="${escapeHtml(toothNames[it.tooth_number] || '')}">${palmerNumbers[it.tooth_number] || it.tooth_number} <small style="opacity:.7">(${it.tooth_number})</small></span>`;
    if (it.arch_type && it.arch_type !== 'none') return `<span class="tp-tooth-chip tp-arch-chip">${it.arch_type === 'upper' ? 'ئەرکی سەرەوە' : 'ئەرکی خوارەوە'}</span>`;
    return '<span style="color:#bbb;">—</span>';
}
function renderItemsTable() {
    const root = document.getElementById('pvItemsTable');
    if (!root) return;
    const rows = planItems.filter(i => i.status !== 'cancelled');
    const editable = isEditable();
    let body = rows.map((it, index) => {
        const transferred = it.status === 'transferred';
        const surfaces = String(it.tooth_surface || '').split(',').filter(Boolean);
        return `<tr class="${transferred ? 'is-transferred' : ''}">
            <td class="rt-c-no">${index + 1}</td>
            <td class="rt-c-desc">${escapeHtml(it.treatment_name)}
                ${surfaces.length ? `<div class="treatment-surface-tags">${surfaces.map(s => `<span class="treatment-surface-tag">${SURFACE_LABELS[s] || s}</span>`).join('')}</div>` : ''}
                ${it.notes ? `<small class="tp-line-note">${escapeHtml(it.notes)}</small>` : ''}</td>
            <td class="rt-c-teeth">${locationChip(it)}</td>
            <td class="tp-num rt-c-qty">${it.quantity}</td>
            <td class="tp-num rt-c-unit">${numberFormat(it.unit_price)}</td>
            <td class="tp-num">${Number(it.discount) > 0 ? numberFormat(it.discount) : '—'}</td>
            <td class="tp-num rt-c-total"><strong>${numberFormat(it.final_price)}</strong></td>
            <td class="tp-num">${it.estimated_minutes ? it.estimated_minutes + ' خ' : '—'}</td>
            <td>
                <span class="tp-item-state ${it.status}">${transferred ? 'گوازراوە' : 'چاوەڕوان'}</span>
                ${transferred && it.transferred_case_number ? `<a href="<?= APP_URL ?>/cases/view.php?id=${it.transferred_case_id}" style="display:block;font-size:11px;margin-top:3px;"><i class="fas fa-arrow-left"></i> ${escapeHtml(it.transferred_case_number)}</a>` : ''}
            </td>
            <td class="rt-c-act">${editable && !transferred ? `<div class="tp-row-actions">
                <button type="button" class="tp-icon-btn" title="دەستکاری" onclick="pvEditItem(${it.id})"><i class="fas fa-edit"></i></button>
                <button type="button" class="tp-icon-btn danger" title="سڕینەوە" onclick="pvRemoveItem(${it.id})"><i class="fas fa-trash"></i></button>
            </div>` : ''}</td>
        </tr>`;
    }).join('');
    if (!rows.length) {
        body = `<tr><td colspan="10"><div class="tp-empty-items"><i class="fas fa-clipboard-list"></i><strong>هێشتا هیچ چارەسەرێک نییە</strong><span>لە چارتەکە ددانێک هەڵبژێرە یان چارەسەرێک زیاد بکە.</span>${editable ? `<button type="button" class="btn btn-primary btn-sm" onclick="${IS_ARCH ? 'pvOpenArchDialog()' : 'pvOpenItemDialog({})'}"><i class="fas fa-plus"></i> زیادکردنی چارەسەر</button>` : ''}</div></td></tr>`;
    }
    root.innerHTML = `<div class="table-container tp-items-wrap"><table class="table rcpt-table tp-items no-responsive"><thead><tr>
        <th class="rt-c-no">ژ</th><th class="rt-c-desc">ناوەڕۆک<span class="en">Description</span></th><th class="rt-c-teeth">ددان / ئەرک<span class="en">Teeth</span></th>
        <th class="rt-c-qty">ژمارە<span class="en">Quantity</span></th><th class="rt-c-unit">نرخی تاک<span class="en">Unit Price</span></th>
        <th>داشکاندن</th><th class="rt-c-total">نرخی کۆ<span class="en">Total Price</span></th><th>کات</th><th>دۆخ</th><th class="rt-c-act">کردارەکان</th>
    </tr></thead><tbody>${body}</tbody></table></div>`;
}
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

// ── Treatment picker ────────────────────────────────────────────────
let tpActiveCat = 'all';
function categoryIconFor(t) {
    switch (t.category) {
        case 'filling': return legendSample('chip', /کۆمپۆزیت|كۆمپۆزیت|composite/i.test(t.name) ? CHART_COLORS.composite : CHART_COLORS.amalgam);
        case 'root_canal': return legendSample('canal');
        case 'crown': case 'bridge': return legendSample('crown');
        case 'implant': return legendSample('implant');
        case 'extraction': case 'surgery': return legendSample('extract');
        case 'cleaning': return legendSample('sparkle', CHART_COLORS.cleaning);
        case 'whitening': return legendSample('sparkle', CHART_COLORS.whitening);
        case 'cosmetic': return legendSample('chip', CHART_COLORS.cosmetic);
        default: return legendSample('chip', CHART_COLORS.other);
    }
}
window.openTreatmentPicker = function () {
    document.getElementById('treatmentPicker').classList.add('open');
    renderTreatmentPicker();
    setTimeout(() => document.getElementById('tpSearch')?.focus(), 60);
};
window.closeTreatmentPicker = function () { document.getElementById('treatmentPicker').classList.remove('open'); };
function renderTreatmentPicker() {
    const listEl = document.getElementById('tpList');
    const catsEl = document.getElementById('tpCats');
    const q = (document.getElementById('tpSearch').value || '').trim().toLowerCase();
    const present = [];
    treatmentCatalog.forEach(t => { const c = t.category || 'other'; if (!present.includes(c)) present.push(c); });
    const cats = ['all'].concat(present);
    if (!cats.includes(tpActiveCat)) tpActiveCat = 'all';
    catsEl.innerHTML = cats.map(c => `<button type="button" class="tp-cat ${tpActiveCat === c ? 'on' : ''}" data-cat="${c}">${c === 'all' ? 'هەموو' : (CATEGORY_LABELS[c] || c)}</button>`).join('');
    const rows = treatmentCatalog.filter(t => (tpActiveCat === 'all' || (t.category || 'other') === tpActiveCat) && (!q || t.name.toLowerCase().includes(q)));
    listEl.innerHTML = rows.length ? rows.map(t => `<button type="button" class="tp-item" data-id="${t.id}">
        <span class="tp-item-icon">${categoryIconFor(t)}</span><span class="tp-item-name">${escapeHtml(t.name)}</span><span class="tp-item-price">${numberFormat(t.price)}</span></button>`).join('')
        : '<div class="tp-empty">هیچ چارەسەرێک نەدۆزرایەوە</div>';
}
document.addEventListener('click', function (e) {
    const cat = e.target.closest('#tpCats .tp-cat');
    if (cat) { tpActiveCat = cat.dataset.cat; renderTreatmentPicker(); return; }
    const item = e.target.closest('#tpList .tp-item');
    if (item) { document.getElementById('itTreatmentSelect').value = String(item.dataset.id); itTreatmentChanged(); closeTreatmentPicker(); return; }
    const ov = document.getElementById('treatmentPicker');
    if (ov && e.target === ov) closeTreatmentPicker();
});
document.addEventListener('input', function (e) { if (e.target && e.target.id === 'tpSearch') renderTreatmentPicker(); });
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('treatmentPicker')?.classList.contains('open')) { e.stopPropagation(); closeTreatmentPicker(); }
}, true);

// ── Arch dialog ─────────────────────────────────────────────────────
let selectedArches = [];
let arDefault = 0;
window.pvOpenArchDialog = function () {
    if (!isEditable()) return;
    selectedArches = [];
    document.querySelectorAll('.arch-select-panel').forEach(p => p.classList.remove('selected'));
    document.getElementById('arArchType').value = '';
    document.getElementById('arTreatmentSelect').value = '';
    document.getElementById('arName').value = '';
    document.getElementById('arNotes').value = '';
    document.getElementById('arMinutes').value = '';
    ['arUnitPrice', 'arTotalAgreed', 'arUpperPrice', 'arLowerPrice'].forEach(id => document.getElementById(id).value = '');
    arDefault = 0;
    document.getElementById('arDefaultPrice').textContent = '0';
    // Preselect the plan's own arch(es) — one click less on the common path.
    const sel = planData.selected_arch;
    if (sel === 'upper' || sel === 'both') arSelectArch('upper');
    if (sel === 'lower' || sel === 'both') arSelectArch('lower');
    arSwitchMode('per_arch');
    setMsg('arMsg', '');
    openDialog('archTreatmentDialog');
};
window.arSelectArch = function (arch) {
    const panel = document.querySelector(`.arch-select-panel[data-arch="${arch}"]`);
    panel.classList.toggle('selected');
    if (panel.classList.contains('selected')) { if (!selectedArches.includes(arch)) selectedArches.push(arch); }
    else selectedArches = selectedArches.filter(a => a !== arch);
    document.getElementById('arArchType').value = selectedArches.length === 2 ? 'both' : (selectedArches[0] || '');
    document.getElementById('arArchCount').textContent = selectedArches.length;
    const sep = document.getElementById('arSeparateOption');
    if (selectedArches.length === 2) sep.style.display = 'flex';
    else {
        sep.style.display = 'none';
        if ((document.querySelector('input[name="arPricingMode"]:checked') || {}).value === 'separate') arSwitchMode('per_arch');
    }
    arCalc();
};
window.arSwitchMode = function (mode) {
    document.querySelectorAll('.arch-pricing-mode-option').forEach(opt => {
        const on = opt.dataset.mode === mode;
        opt.classList.toggle('selected', on);
        if (on) opt.querySelector('input[type="radio"]').checked = true;
    });
    document.getElementById('arPerArchPricing').style.display = mode === 'per_arch' ? 'block' : 'none';
    document.getElementById('arTotalPricing').style.display = mode === 'total' ? 'block' : 'none';
    document.getElementById('arSeparatePricing').style.display = mode === 'separate' ? 'block' : 'none';
    arCalc();
};
window.arTreatmentChanged = function () {
    const sel = document.getElementById('arTreatmentSelect');
    const opt = sel.options[sel.selectedIndex];
    arDefault = parseFloat(opt.dataset.price) || 0;
    document.getElementById('arDefaultPrice').textContent = numberFormat(arDefault);
    document.getElementById('arUnitPrice').value = arDefault;
    document.getElementById('arName').value = opt.dataset.name || '';
    arCalc();
};
window.arUseDefault = function () { document.getElementById('arUnitPrice').value = arDefault; arCalc(); };
function arPrices() {
    const mode = (document.querySelector('input[name="arPricingMode"]:checked') || {}).value || 'per_arch';
    const n = selectedArches.length;
    let upper = 0, lower = 0;
    if (mode === 'per_arch') { upper = lower = Math.max(0, parseFloat(document.getElementById('arUnitPrice').value) || 0); }
    else if (mode === 'total') { const t = Math.max(0, parseFloat(document.getElementById('arTotalAgreed').value) || 0); upper = lower = n ? Math.round(t / n) : 0; }
    else { upper = Math.max(0, parseFloat(document.getElementById('arUpperPrice').value) || 0); lower = Math.max(0, parseFloat(document.getElementById('arLowerPrice').value) || 0); }
    let total = 0;
    if (selectedArches.includes('upper')) total += upper;
    if (selectedArches.includes('lower')) total += lower;
    return { mode, upper, lower, total };
}
window.arCalc = function () {
    const p = arPrices();
    document.getElementById('arCalcPerArch').textContent = numberFormat(p.mode === 'total' ? p.upper : 0);
    document.getElementById('arTotal').textContent = numberFormat(p.total);
};
window.arSave = function () {
    const arch = document.getElementById('arArchType').value;
    if (!arch) { setMsg('arMsg', 'تکایە لانیکەم یەک ئەرک هەڵبژێرە', 'is-error'); return; }
    const name = document.getElementById('arName').value.trim();
    if (!name) { setMsg('arMsg', 'ناوی چارەسەر پێویستە', 'is-error'); return; }
    const p = arPrices();
    const btn = document.getElementById('arSaveBtn');
    btn.disabled = true;
    setMsg('arMsg', 'پاشەکەوت دەکرێت...');
    post('add_item', {
        plan_id: PLAN_ID, arch_type: arch,
        treatment_id: document.getElementById('arTreatmentSelect').value || '',
        treatment_name: name, unit_price: p.upper,
        upper_unit_price: p.upper, lower_unit_price: p.lower,
        estimated_minutes: document.getElementById('arMinutes').value,
        notes: document.getElementById('arNotes').value.trim(),
    }).then(res => {
        btn.disabled = false;
        if (!res || !res.success) { setMsg('arMsg', (res && (res.error || res.message)) || 'هەڵە', 'is-error'); return; }
        applyResponse(res);
        closeDialog('archTreatmentDialog');
        toast('success', 'کاری ئەرک زیادکرا');
    }).catch(() => { btn.disabled = false; setMsg('arMsg', 'هەڵە لە پەیوەندی', 'is-error'); });
};

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
    if (qs.get('tab') === 'items') showTab('items', document.querySelectorAll('.tabs-container .tab')[1]);
    // «گواستنەوە بۆ کەیس» on the patient's plans tab lands here with the dialog open.
    if (qs.get('transfer') === '1' && typeof window.pvOpenTransfer === 'function') window.pvOpenTransfer();
});
</script>
