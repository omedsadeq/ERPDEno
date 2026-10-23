<?php if (!isset($plan)) { exit; } ?>
<script>
// ═══════════════════════════════════════════════════════════════════
// Chart engine — same renderer as cases/view.php (anatomical SVG teeth,
// occlusal arch chart). Reads `toothTreatmentMap` / `serviceTreatmentRows`,
// which the plan script builds from the plan's items.
// ═══════════════════════════════════════════════════════════════════
const toothNames = <?= json_encode($toothNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const palmerNumbers = <?= json_encode($palmerNumbers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const DENTITION_TEETH = <?= json_encode([
    'permanent' => [
        'UR' => array_values($teethSets['permanent']['upper_right']),
        'UL' => array_values($teethSets['permanent']['upper_left']),
        'LL' => array_values($teethSets['permanent']['lower_left']),
        'LR' => array_values($teethSets['permanent']['lower_right']),
    ],
    'primary' => [
        'UR' => array_values($teethSets['primary']['upper_right']),
        'UL' => array_values($teethSets['primary']['upper_left']),
        'LL' => array_values($teethSets['primary']['lower_left']),
        'LR' => array_values($teethSets['primary']['lower_right']),
    ],
]) ?>;
const DENTITION_MODES = ['permanent', 'primary'];

let toothTreatmentMap = {};
let serviceTreatmentRows = [];

const CHART_COLORS = {
    amalgam: '#51b56b', composite: '#f06a94', canal: '#e8637f', crown: '#dfa32b',
    implant: '#78909c', extract: '#4a5560', cleaning: '#2bb3c9', whitening: '#4dabf7',
    cosmetic: '#9775fa', ortho: '#f76707', other: '#4dabf7', outline: '#b8c3d1', body: '#ffffff'
};
const SURFACE_LABELS = {
    occlusal: 'سەری ددان', mesial: 'لای ناوەند (M)', distal: 'لای دواوە (D)',
    buccal: 'ڕووی دەرەوە (B)', lingual: 'ڕووی ناوەوە (L)', root: 'ڕەگ'
};
const CATEGORY_LABELS = {
    examination: 'پشکنین', cleaning: 'پاککردنەوە', filling: 'پڕکردنەوە',
    extraction: 'ڕاکێشان', root_canal: 'چارەسەری دەمار', crown: 'تاج',
    bridge: 'پرد', implant: 'ئیمپلانت', orthodontic: 'ڕاستکردنەوە',
    whitening: 'سپیکردنەوە', cosmetic: 'جوانکاری', surgery: 'جراحی', other: 'تر'
};
const SURFACE_CATEGORIES = ['filling', 'cosmetic', 'other', 'examination'];

const TOOTH_SHAPES = {
    molar: {
        body: 'M5,17 C5,6 11,3 22,3 C33,3 39,6 39,17 C39,23 37,27 34,30 C34.5,36 33,44 30.5,51 C29.5,54 27,54 26.5,51 C25.8,45 25,39 24.5,35 Q22,32 19.5,35 C19,39 18.2,45 17.5,51 C17,54 14.5,54 13.5,51 C11,44 9.5,36 10,30 C7,27 5,23 5,17 Z',
        crown: 'M6,16 C6,6 12,3 22,3 C32,3 38,6 38,16 C38,20 37,23.5 35.5,26.5 L8.5,26.5 C7,23.5 6,20 6,16 Z',
        chamber: {cx: 22, cy: 25, rx: 4.5, ry: 3},
        canals: ['M16,30 C15.2,36 15,42.5 16.5,48.5', 'M28,30 C28.8,36 29,42.5 27.5,48.5'],
        zones: { occlusal: {star: [22, 14.5, 1]}, left: {cx: 10, cy: 15, rx: 4.5, ry: 7}, right: {cx: 34, cy: 15, rx: 4.5, ry: 7}, buccal: {cx: 22, cy: 6.5, rx: 9, ry: 4}, lingual: {cx: 22, cy: 23.5, rx: 9, ry: 4} }
    },
    premolar: {
        body: 'M8,16 C8,7 13,4 22,4 C31,4 36,7 36,16 C36,22 34,26 31.5,29 C31,37 29,46 24.5,52.5 Q22,55.5 19.5,52.5 C15,46 13,37 12.5,29 C10,26 8,22 8,16 Z',
        crown: 'M9,15 C9,7 14,4 22,4 C30,4 35,7 35,15 C35,19 34,22.5 32.5,25.5 L11.5,25.5 C10,22.5 9,19 9,15 Z',
        chamber: {cx: 22, cy: 24.5, rx: 3.8, ry: 2.8},
        canals: ['M22,28 C21.8,36 21.8,43 22,49'],
        zones: { occlusal: {star: [22, 14, 0.88]}, left: {cx: 12, cy: 14.5, rx: 4, ry: 6.2}, right: {cx: 32, cy: 14.5, rx: 4, ry: 6.2}, buccal: {cx: 22, cy: 7, rx: 8, ry: 3.6}, lingual: {cx: 22, cy: 22.5, rx: 8, ry: 3.6} }
    },
    canine: {
        body: 'M22,2.5 C29,4 35,7 35,14.5 C35,20 33,25 30,28.5 C29.5,38 27,47 23.8,53.5 Q22,56.5 20.2,53.5 C17,47 14.5,38 14,28.5 C11,25 9,20 9,14.5 C9,7 15,4 22,2.5 Z',
        crown: 'M22,2.5 C29,4 35,7 35,14.5 C35,18.5 34,22 32,25 L12,25 C10,22 9,18.5 9,14.5 C9,7 15,4 22,2.5 Z',
        chamber: {cx: 22, cy: 24, rx: 3.2, ry: 2.6},
        canals: ['M22,27 C21.8,35 21.9,43 22,50'],
        zones: { occlusal: {star: [22, 13.5, 0.82]}, left: {cx: 12.5, cy: 14, rx: 3.8, ry: 6}, right: {cx: 31.5, cy: 14, rx: 3.8, ry: 6}, buccal: {cx: 22, cy: 6.5, rx: 7.5, ry: 3.4}, lingual: {cx: 22, cy: 21.5, rx: 7.5, ry: 3.4} }
    },
    incisor: {
        body: 'M11,3.5 C18,2 26,2 33,3.5 C33.5,10 32.5,17 30.5,23.5 C30,32 27,44 23.5,52 Q22,55 20.5,52 C17,44 14,32 13.5,23.5 C11.5,17 10.5,10 11,3.5 Z',
        crown: 'M11,3.5 C18,2 26,2 33,3.5 C33.3,9 32.7,15 31.3,20.5 L12.7,20.5 C11.3,15 10.7,9 11,3.5 Z',
        chamber: {cx: 22, cy: 24, rx: 3, ry: 2.5},
        canals: ['M22,26.5 C21.8,34 21.9,42 22,49.5'],
        zones: { occlusal: {star: [22, 12, 0.78]}, left: {cx: 13, cy: 12, rx: 3.5, ry: 6}, right: {cx: 31, cy: 12, rx: 3.5, ry: 6}, buccal: {cx: 22, cy: 4.8, rx: 8, ry: 2.8}, lingual: {cx: 22, cy: 19, rx: 7.5, ry: 3.2} }
    }
};
const TOOTH_ANATOMY = {
    molar: ['M10.5,13.5 C14.5,17.5 18,17.5 22,13.5 C26,17.5 29.5,17.5 33.5,13.5', 'M12.5,22 C16.5,19 19.5,20 22,22 C24.5,20 27.5,19 31.5,22'],
    premolar: ['M13.5,13 C17.5,17 19.5,17 22,13 C24.5,17 26.5,17 30.5,13', 'M15,22 C18.5,19.5 21,20.5 22,22 C23,20.5 25.5,19.5 29,22'],
    canine: ['M14,14 C17.5,17.5 20,17.5 22,14 C24,17.5 26.5,17.5 30,14', 'M16,22 C18.5,20 20.5,20 22,22 C23.5,20 25.5,20 28,22'],
    incisor: ['M13,11.5 Q22,15.5 31,11.5', 'M14.5,19.5 Q22,17.2 29.5,19.5']
};

function isPrimaryTooth(fdi) { const q = Math.floor(fdi / 10); return q >= 5 && q <= 8; }
function toothShapeKey(fdi) {
    const p = fdi % 10;
    if (p <= 2) return 'incisor';
    if (p === 3) return 'canine';
    if (isPrimaryTooth(fdi)) return 'molar';
    if (p <= 5) return 'premolar';
    return 'molar';
}
function mesialIsLeft(fdi) { const q = Math.floor(fdi / 10); return q === 1 || q === 4 || q === 5 || q === 8; }
function starPath(cx, cy, s) {
    const p = [[0,-7],[1.6,-4.6],[4.9,-5.4],[4.2,-2.1],[7,0],[4.2,2.1],[4.9,5.4],[1.6,4.6],[0,7],[-1.6,4.6],[-4.9,5.4],[-4.2,2.1],[-7,0],[-4.2,-2.1],[-4.9,-5.4],[-1.6,-4.6]]
        .map(([x, y]) => (cx + x * s).toFixed(1) + ',' + (cy + y * s).toFixed(1));
    return 'M' + p.join(' L') + ' Z';
}

function toothState(fdi) {
    const st = {surfaces: {}, rootZone: null, canal: false, crown: false, implant: false, extract: false, sparkle: null, bracket: false, dot: false, count: 0, treatments: []};
    const d = toothTreatmentMap[fdi];
    if (!d || !d.treatments) return st;
    st.count = d.treatments.length;
    st.treatments = d.treatments;
    d.treatments.forEach(t => {
        const cat = t.category || 'other';
        const surfs = String(t.tooth_surface || '').split(',').filter(Boolean).map(s => s === 'incisal' ? 'occlusal' : s);
        const paint = (color, fallback) => {
            (surfs.length ? surfs : fallback).forEach(s => { if (s === 'root') st.rootZone = color; else st.surfaces[s] = color; });
        };
        if (cat === 'filling') paint(/کۆمپۆزیت|كۆمپۆزیت|composite/i.test(t.treatment_name) ? CHART_COLORS.composite : CHART_COLORS.amalgam, ['occlusal']);
        else if (cat === 'cosmetic') paint(CHART_COLORS.cosmetic, ['buccal']);
        else if (cat === 'root_canal') st.canal = true;
        else if (cat === 'crown' || cat === 'bridge') st.crown = true;
        else if (cat === 'implant') st.implant = true;
        else if (cat === 'extraction' || cat === 'surgery') st.extract = true;
        else if (cat === 'cleaning') st.sparkle = CHART_COLORS.cleaning;
        else if (cat === 'whitening') st.sparkle = CHART_COLORS.whitening;
        else if (cat === 'orthodontic') st.bracket = true;
        else { if (surfs.length) paint(CHART_COLORS.other, []); else st.dot = true; }
    });
    return st;
}

function toothSVG(fdi, opts) {
    opts = opts || {};
    const st = opts.state || toothState(fdi);
    const shapeKey = toothShapeKey(fdi);
    const shape = TOOTH_SHAPES[shapeKey];
    const uid = (opts.idPrefix || 't') + '-' + fdi;
    const selected = opts.selectedSurfaces || null;
    let defs = `
        <linearGradient id="tooth-base-${uid}" x1="18%" y1="0%" x2="82%" y2="100%"><stop offset="0%" stop-color="#ffffff"/><stop offset="58%" stop-color="#fbfcfe"/><stop offset="100%" stop-color="#edf2f7"/></linearGradient>
        <clipPath id="clip-${uid}"><path d="${shape.body}"/></clipPath>`;
    let parts = [];
    const ghost = st.extract ? ' opacity="0.18"' : '';
    if (!st.implant) {
        parts.push(`<g${ghost}>`);
        parts.push(`<path d="${shape.body}" fill="url(#tooth-base-${uid})" stroke="${CHART_COLORS.outline}" stroke-width="1.95" stroke-linejoin="round"/>`);
        const anatomy = TOOTH_ANATOMY[shapeKey] || [];
        if (anatomy.length) {
            parts.push(`<g fill="none" stroke="#d5dee8" stroke-width="1.05" stroke-linecap="round" stroke-linejoin="round" opacity="0.72">`);
            anatomy.forEach(path => parts.push(`<path d="${path}"/>`));
            parts.push('</g>');
        }
        parts.push(`<g clip-path="url(#clip-${uid})">`);
        const rootFill = selected ? (selected.has('root') ? CHART_COLORS.other : null) : st.rootZone;
        if (opts.showAllZones) {
            parts.push(`<rect x="0" y="29" width="44" height="31" fill="${rootFill || '#eceff3'}" fill-opacity="${rootFill ? 0.75 : 0.55}" data-surface="root" style="cursor:pointer"/>`);
        } else if (rootFill) {
            parts.push(`<rect x="0" y="29" width="44" height="31" fill="${rootFill}" fill-opacity="0.55"/>`);
        }
        const zoneEls = [];
        const zdef = shape.zones;
        const zoneGeo = { mesial: zdef[mesialIsLeft(fdi) ? 'left' : 'right'], distal: zdef[mesialIsLeft(fdi) ? 'right' : 'left'], buccal: zdef.buccal, lingual: zdef.lingual };
        ['mesial', 'distal', 'buccal', 'lingual'].forEach(surf => {
            const g = zoneGeo[surf];
            const fill = selected ? (selected.has(surf) ? CHART_COLORS.other : null) : st.surfaces[surf];
            if (opts.showAllZones) {
                zoneEls.push(`<ellipse cx="${g.cx}" cy="${g.cy}" rx="${(g.rx * 1.06).toFixed(2)}" ry="${(g.ry * 1.06).toFixed(2)}" fill="${fill || '#dfe5ea'}" fill-opacity="${fill ? 0.9 : 0.6}" stroke="${fill ? '#00000033' : '#c3ccd4'}" stroke-width="0.7" data-surface="${surf}" style="cursor:pointer"/>`);
            } else if (fill) {
                zoneEls.push(`<ellipse cx="${g.cx}" cy="${g.cy}" rx="${(g.rx * 1.08).toFixed(2)}" ry="${(g.ry * 1.08).toFixed(2)}" fill="${fill}" fill-opacity="0.94" stroke="#00000022" stroke-width="0.7"/>`);
            }
        });
        const [scx, scy, ss] = zdef.occlusal.star;
        const oFill = selected ? (selected.has('occlusal') ? CHART_COLORS.other : null) : st.surfaces.occlusal;
        if (opts.showAllZones) {
            zoneEls.push(`<path d="${starPath(scx, scy, ss * 1.12)}" fill="${oFill || '#dfe5ea'}" fill-opacity="${oFill ? 0.95 : 0.65}" stroke="${oFill ? '#00000033' : '#c3ccd4'}" stroke-width="0.7" data-surface="occlusal" style="cursor:pointer"/>`);
        } else if (oFill) {
            zoneEls.push(`<path d="${starPath(scx, scy, ss * 1.16)}" fill="${oFill}" fill-opacity="0.96" stroke="#00000022" stroke-width="0.7"/>`);
        }
        parts.push(zoneEls.join(''));
        parts.push('</g>');
        if (st.canal) {
            const c = shape.chamber;
            parts.push(`<ellipse cx="${c.cx}" cy="${c.cy}" rx="${c.rx}" ry="${c.ry}" fill="${CHART_COLORS.canal}" fill-opacity="0.9"/>`);
            shape.canals.forEach(p => parts.push(`<path d="${p}" fill="none" stroke="${CHART_COLORS.canal}" stroke-width="2.6" stroke-linecap="round"/>`));
        }
        parts.push('</g>');
    } else {
        parts.push(`<g fill="${CHART_COLORS.implant}" stroke="${CHART_COLORS.implant}">`);
        parts.push(`<rect x="17.5" y="6" width="9" height="6" rx="1.2"/>`);
        parts.push(`<path d="M14.5,13.5 L29.5,13.5 L27.8,23 L16.2,23 Z"/>`);
        parts.push(`<path d="M16.5,25 L27.5,25 L25.8,49 Q22,54 18.2,49 Z"/>`);
        [29, 34, 39, 44].forEach((y, i) => { const inset = 16.8 + i * 0.55; parts.push(`<line x1="${inset}" y1="${y}" x2="${44 - inset}" y2="${y}" stroke="#fff" stroke-width="1.6"/>`); });
        parts.push('</g>');
    }
    if (st.crown) parts.push(`<path d="${shape.crown}" fill="${CHART_COLORS.crown}" fill-opacity="0.18" stroke="${CHART_COLORS.crown}" stroke-width="2.65" stroke-linejoin="round"/>`);
    if (st.bracket) {
        parts.push(`<line x1="5" y1="14.5" x2="39" y2="14.5" stroke="${CHART_COLORS.ortho}" stroke-width="1.4"/>`);
        parts.push(`<rect x="18" y="10.5" width="8" height="8" rx="1.5" fill="#fff" stroke="${CHART_COLORS.ortho}" stroke-width="1.8"/>`);
    }
    if (st.sparkle) parts.push(`<path d="M34,1 L35.6,5 L39.5,6.5 L35.6,8 L34,12 L32.4,8 L28.5,6.5 L32.4,5 Z" fill="${st.sparkle}"/>`);
    if (st.dot) parts.push(`<circle cx="34" cy="23" r="3.2" fill="${CHART_COLORS.other}" stroke="#fff" stroke-width="1.2"/>`);
    if (st.extract) {
        parts.push(`<g stroke="${CHART_COLORS.extract}" stroke-width="3.6" stroke-linecap="round"><line x1="12" y1="14" x2="32" y2="44"/><line x1="32" y1="14" x2="12" y2="44"/></g>`);
    }
    return `<svg viewBox="0 0 44 60" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="${toothNames[fdi] || ('Tooth ' + fdi)}" shape-rendering="geometricPrecision"><defs>${defs}</defs>${parts.join('')}</svg>`;
}

function legendSample(kind, color) {
    const wrap = c => `<svg viewBox="0 0 44 60" style="width:20px;height:25px;display:block;">${c}</svg>`;
    const molar = TOOTH_SHAPES.molar;
    switch (kind) {
        case 'chip': return `<span class="legend-color" style="background:${color};border-color:${color};"></span>`;
        case 'canal': return wrap(`<path d="${molar.body}" fill="#fff" stroke="${CHART_COLORS.outline}" stroke-width="2"/><ellipse cx="22" cy="25" rx="4.5" ry="3" fill="${CHART_COLORS.canal}"/>` + molar.canals.map(p => `<path d="${p}" fill="none" stroke="${CHART_COLORS.canal}" stroke-width="3.4" stroke-linecap="round"/>`).join(''));
        case 'crown': return wrap(`<path d="${molar.body}" fill="#fff" stroke="${CHART_COLORS.outline}" stroke-width="2"/><path d="${molar.crown}" fill="${CHART_COLORS.crown}" fill-opacity="0.15" stroke="${CHART_COLORS.crown}" stroke-width="3.4"/>`);
        case 'implant': return wrap(`<g fill="${CHART_COLORS.implant}"><rect x="17.5" y="6" width="9" height="6" rx="1.2"/><path d="M14.5,13.5 L29.5,13.5 L27.8,23 L16.2,23 Z"/><path d="M16.5,25 L27.5,25 L25.8,49 Q22,54 18.2,49 Z"/></g>`);
        case 'extract': return wrap(`<g stroke="${CHART_COLORS.extract}" stroke-width="5" stroke-linecap="round"><line x1="12" y1="16" x2="32" y2="44"/><line x1="32" y1="16" x2="12" y2="44"/></g>`);
        case 'sparkle': return wrap(`<path d="M22,10 L26,24 L40,30 L26,36 L22,50 L18,36 L4,30 L18,24 Z" fill="${color}"/>`);
        default: return `<span class="legend-color" style="background:${color};"></span>`;
    }
}
function renderChartLegend() {
    const el = document.getElementById('smartChartLegend');
    if (!el) return;
    const items = [
        ['chip', CHART_COLORS.amalgam, 'پڕکردنەوە (ئاسایی)'], ['chip', CHART_COLORS.composite, 'پڕکردنەوە (کۆمپۆزیت)'],
        ['canal', null, 'چارەسەری دەمار'], ['crown', null, 'تاج / پرد'], ['implant', null, 'ئیمپلانت'],
        ['extract', null, 'ڕاکێشان'], ['sparkle', CHART_COLORS.cleaning, 'پاککردنەوە'], ['chip', CHART_COLORS.cosmetic, 'جوانکاری']
    ];
    el.innerHTML = items.map(([kind, color, label]) => `<div class="smart-chart-legend-item"><span class="smart-chart-legend-icon">${legendSample(kind, color)}</span><span>${label}</span></div>`).join('');
}

function renderServiceWork() {
    const root = document.getElementById('chartServiceWork');
    if (!root) return;
    const grouped = {};
    (serviceTreatmentRows || []).forEach(function (row) {
        const arch = ['upper', 'lower'].includes(row.arch_type) ? row.arch_type : 'none';
        const key = arch + '|' + row.treatment_name + '|' + row.unit_price;
        if (!grouped[key]) grouped[key] = { arch: arch, name: row.treatment_name, qty: 0, total: 0, id: row.id };
        grouped[key].qty += Number(row.quantity) || 0;
        grouped[key].total += Number(row.final_price) || 0;
    });
    const rows = Object.values(grouped);
    root.classList.toggle('has-items', rows.length > 0);
    if (!rows.length) { root.innerHTML = ''; return; }
    const lanes = [
        ['upper', 'ماکزیلا / ئەرکی سەرەوە', 'fa-arrow-up', 'is-upper'],
        ['none', 'کارەکانی بێ ژمارەی ددان', 'fa-briefcase-medical', 'is-service'],
        ['lower', 'ماندیبل / ئەرکی خوارەوە', 'fa-arrow-down', 'is-lower']
    ];
    root.innerHTML = lanes.map(function (lane) {
        const items = rows.filter(x => x.arch === lane[0]);
        if (!items.length) return '';
        return `<section class="chart-service-lane ${lane[3]}">
            <div class="chart-service-title"><i class="fas ${lane[2]}"></i>${lane[1]}</div>
            <div class="chart-service-list">${items.map(item => `<span class="chart-service-card" onclick="showTab('items', document.querySelector('.tab[onclick*=items]'))">
                <strong>${escapeHtml(item.name)}</strong><span class="qty">× ${numberFormat(item.qty)}</span><span class="price">${numberFormat(item.total)}</span>
            </span>`).join('')}</div>
        </section>`;
    }).join('');
}

function renderSmartChart() {
    document.querySelectorAll('.tooth[data-quadrant]').forEach(el => {
        const fdi = parseInt(el.dataset.tooth, 10);
        const st = toothState(fdi);
        el.classList.remove('tooth--incisor', 'tooth--canine', 'tooth--premolar', 'tooth--molar');
        el.classList.add('tooth--' + toothShapeKey(fdi));
        const holder = el.querySelector('.tooth-svg');
        if (holder) holder.innerHTML = toothSVG(fdi, {state: st, idPrefix: 'chart'});
        el.classList.toggle('has-treatments', st.count > 0);
        el.classList.toggle('has-transferred', st.count > 0 && st.treatments.every(t => t.status === 'transferred'));
        let badge = el.querySelector('.treatment-count');
        if (st.count > 0) {
            if (!badge) { badge = document.createElement('span'); badge.className = 'treatment-count'; el.appendChild(badge); }
            badge.textContent = st.count;
        } else if (badge) { badge.remove(); }
        const tip = el.querySelector('.tooth-tooltip');
        if (tip) {
            let txt = toothNames[fdi] || ('ددان ' + fdi);
            if (st.count > 0) txt += ' — ' + st.treatments[0].treatment_name + (st.count > 1 ? ' +' + (st.count - 1) : '');
            tip.textContent = txt;
        }
    });
    renderChartLegend();
    renderDentitionCounts();
    renderArchCharts();
    renderServiceWork();
}

// ── Adult / child panes ─────────────────────────────────────────────
const DENTITION_STORE_KEY = 'dentHisab.planChartPanes';
function dentitionPaneVisibility() {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(DENTITION_STORE_KEY) || 'null'); } catch (e) { saved = null; }
    const vis = {};
    DENTITION_MODES.forEach(m => { vis[m] = !(saved && saved[m] === false); });
    if (!DENTITION_MODES.some(m => vis[m])) DENTITION_MODES.forEach(m => { vis[m] = true; });
    return vis;
}
function dentitionTreatmentCount(mode) {
    const set = DENTITION_TEETH[mode];
    if (!set) return 0;
    let n = 0;
    ['UR', 'UL', 'LL', 'LR'].forEach(q => { (set[q] || []).forEach(fdi => { const d = toothTreatmentMap[fdi]; if (d && d.treatments) n += d.treatments.length; }); });
    return n;
}
function renderDentitionCounts() {
    DENTITION_MODES.forEach(mode => {
        const n = dentitionTreatmentCount(mode);
        document.querySelectorAll('[data-dentition-count="' + mode + '"]').forEach(el => { el.textContent = n > 0 ? n + ' ئیش' : ''; });
    });
}
const CHART_MIN_SCALE = 0.55;
function rowNaturalWidth(row, scale) {
    const quads = Array.prototype.slice.call(row.children).filter(el => el.classList.contains('quadrant'));
    if (!quads.length) return 0;
    const widths = quads.map(q => {
        const gap = parseFloat(getComputedStyle(q).columnGap) || 0;
        const teeth = Array.prototype.slice.call(q.children);
        return teeth.reduce((s, t) => s + t.getBoundingClientRect().width, 0) + gap * Math.max(0, teeth.length - 1);
    });
    if (getComputedStyle(row).flexWrap === 'wrap') return Math.max.apply(null, widths) / scale;
    let extra = 0;
    const div = row.querySelector('.quadrant-divider');
    if (div) { const ds = getComputedStyle(div); extra = div.getBoundingClientRect().width + (parseFloat(ds.marginLeft) || 0) + (parseFloat(ds.marginRight) || 0); }
    return (widths.reduce((a, b) => a + b, 0) + extra) / scale;
}
function fitChartScale() {
    document.querySelectorAll('.smart-dental-chart').forEach(chart => {
        const current = parseFloat(getComputedStyle(chart).getPropertyValue('--chart-scale')) || 1;
        let scale = 1;
        chart.querySelectorAll('.dentition-pane:not([hidden]) .jaw-section').forEach(sec => {
            const row = sec.querySelector('.teeth-row');
            const avail = sec.clientWidth - 12;
            if (!row || avail <= 0) return;
            const natural = rowNaturalWidth(row, current);
            if (natural > 0 && natural > avail) scale = Math.min(scale, avail / natural);
        });
        scale = Math.max(CHART_MIN_SCALE, Math.min(1, scale));
        chart.style.setProperty('--chart-scale', scale.toFixed(4));
    });
}
let _rtlScrollNegative = null;
function centerJawScroll(root) {
    (root || document).querySelectorAll('.smart-dental-chart .jaw-section').forEach(sec => {
        const max = sec.scrollWidth - sec.clientWidth;
        if (max <= 1) return;
        if (_rtlScrollNegative === null) { const before = sec.scrollLeft; sec.scrollLeft = -1; _rtlScrollNegative = sec.scrollLeft < 0; sec.scrollLeft = before; }
        sec.scrollLeft = _rtlScrollNegative ? -max / 2 : max / 2;
    });
}
function applyDentitionPanes(vis) {
    const chart = document.getElementById('smartDentalChart');
    if (chart) {
        chart.querySelectorAll('.dentition-pane').forEach(pane => { pane.hidden = (vis[pane.dataset.dentition] === false); });
        requestAnimationFrame(() => { fitChartScale(); centerJawScroll(chart); });
    }
    document.querySelectorAll('#dentitionForm .dentition-opt').forEach(btn => {
        const on = vis[btn.dataset.dentition] !== false;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    renderDentitionCounts();
}
function toggleDentitionPane(mode) {
    if (DENTITION_MODES.indexOf(mode) === -1) return;
    const vis = dentitionPaneVisibility();
    vis[mode] = !vis[mode];
    if (!DENTITION_MODES.some(m => vis[m])) { toast('error', 'لانیکەم یەک چارت دەبێت پیشان بدرێت'); return; }
    try { localStorage.setItem(DENTITION_STORE_KEY, JSON.stringify(vis)); } catch (e) {}
    applyDentitionPanes(vis);
}
document.addEventListener('DOMContentLoaded', function () {
    const chart = document.getElementById('smartDentalChart');
    if (!chart) return;
    applyDentitionPanes(dentitionPaneVisibility());
    const refit = function () { fitChartScale(); centerJawScroll(); };
    let t;
    const debounced = function () { clearTimeout(t); t = setTimeout(refit, 120); };
    window.addEventListener('resize', debounced);
    if (window.ResizeObserver) new ResizeObserver(debounced).observe(chart.parentElement || chart);
    refit();
});

// ═══ Occlusal arch chart ═══
const ARCH_COLORS = { filling: '#9775fa', canal: '#f59f00', crown: '#339af0', implant: '#2f9e44', extract: '#e03131', sparkle: '#22b8cf', other: '#4dabf7', gum: '#f8b1b1', gumEdge: '#ee9a9a', body: '#ffffff', outline: '#b9c2cf', num: '#0ca678', numBorder: '#cde3d6' };
const ARCH_ORDER = { upper: [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28], lower: [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38] };
function occlusalShape(fdi) {
    const p = fdi % 10;
    if (p === 1) return { body: 'M-15,-8 Q-15,-9 -13,-9 L13,-9 Q15,-9 15,-8 Q15,8 0,9 Q-15,8 -15,-8 Z', detail: 'M-11,-4 Q0,-8 11,-4' };
    if (p === 2) return { body: 'M-13,-7 Q-13,-8 -11,-8 L11,-8 Q13,-8 13,-7 Q13,7 0,8 Q-13,7 -13,-7 Z', detail: 'M-9,-3 Q0,-7 9,-3' };
    if (p === 3) return { body: 'M0,-13 Q12,-9 12,0 Q12,10 0,13 Q-12,10 -12,0 Q-12,-9 0,-13 Z', detail: 'M0,-8 Q2,0 0,8' };
    if (p === 4 || p === 5) return { body: 'M0,-16 Q14,-14 14,0 Q14,14 0,16 Q-14,14 -14,0 Q-14,-14 0,-16 Z', detail: 'M0,-9 Q-3,0 0,9 M-6,-2 Q0,1 6,-2' };
    const s = p === 6 ? 1 : (p === 7 ? 0.92 : 0.82);
    const w = 19 * s, h = 21 * s, r = 9 * s;
    return {
        body: `M${-w + r},${-h} L${w - r},${-h} Q${w},${-h} ${w},${-h + r} L${w},${h - r} Q${w},${h} ${w - r},${h} L${-w + r},${h} Q${-w},${h} ${-w},${h - r} L${-w},${-h + r} Q${-w},${-h} ${-w + r},${-h} Z`,
        detail: `M${-9 * s},${-11 * s} Q${-4 * s},${-3 * s} ${-9 * s},${7 * s} M${9 * s},${-11 * s} Q${4 * s},${-2 * s} ${8 * s},${8 * s} M${-8 * s},${-2 * s} Q0,${2 * s} ${8 * s},${-2 * s}`
    };
}
function archToothState(fdi) {
    const st = {filling: false, canal: false, crown: false, implant: false, extract: false, sparkle: false, dot: false, count: 0};
    const d = toothTreatmentMap[fdi];
    if (!d || !d.treatments) return st;
    st.count = d.treatments.length;
    d.treatments.forEach(t => {
        const cat = t.category || 'other';
        if (cat === 'filling' || cat === 'cosmetic') st.filling = true;
        else if (cat === 'root_canal') st.canal = true;
        else if (cat === 'crown' || cat === 'bridge') st.crown = true;
        else if (cat === 'implant') st.implant = true;
        else if (cat === 'extraction' || cat === 'surgery') st.extract = true;
        else if (cat === 'cleaning' || cat === 'whitening') st.sparkle = true;
        else st.dot = true;
    });
    return st;
}
function archGlyph(kind, scale) {
    const s = scale || 1;
    const g = c => `<g transform="scale(${s})">${c}</g>`;
    switch (kind) {
        case 'filling': return g(`<path d="${starPath(0, 0, 0.95)}" fill="${ARCH_COLORS.filling}" fill-opacity="0.95" stroke="#00000022" stroke-width="0.6"/>`);
        case 'canal': { const m = TOOTH_SHAPES.molar; return g(`<g transform="scale(0.42) translate(-22,-30)"><path d="${m.body}" fill="#fff" stroke="${ARCH_COLORS.canal}" stroke-width="3"/><ellipse cx="22" cy="25" rx="4.5" ry="3" fill="${ARCH_COLORS.canal}"/>${m.canals.map(p => `<path d="${p}" fill="none" stroke="${ARCH_COLORS.canal}" stroke-width="3.6" stroke-linecap="round"/>`).join('')}</g>`); }
        case 'crown': return g(`<path d="M-9,6 L-9,-3 L-4.5,1 L0,-7 L4.5,1 L9,-3 L9,6 Q0,9 -9,6 Z" fill="${ARCH_COLORS.crown}" stroke="#1c7ed6" stroke-width="1"/>`);
        case 'implant': return g(`<g><rect x="-7" y="-13" width="14" height="5" rx="1.5" fill="${ARCH_COLORS.implant}"/><path d="M-5,-7 L5,-7 L4,9 Q0,13 -4,9 Z" fill="${ARCH_COLORS.implant}"/><line x1="-4.5" y1="-3" x2="4.5" y2="-3" stroke="#fff" stroke-width="1.4"/><line x1="-4" y1="1" x2="4" y2="1" stroke="#fff" stroke-width="1.4"/><line x1="-3.2" y1="5" x2="3.2" y2="5" stroke="#fff" stroke-width="1.4"/></g>`);
        case 'extract': return g(`<g stroke="${ARCH_COLORS.extract}" stroke-width="3.8" stroke-linecap="round"><line x1="-8" y1="-8" x2="8" y2="8"/><line x1="8" y1="-8" x2="-8" y2="8"/></g>`);
        case 'sparkle': return g(`<path d="M0,-9 L2.4,-2.4 L9,0 L2.4,2.4 L0,9 L-2.4,2.4 L-9,0 L-2.4,-2.4 Z" fill="${ARCH_COLORS.sparkle}"/>`);
        case 'tooth': return g(`<path d="M-8,-7 C-8,-11 8,-11 8,-7 C8,-2 6,1 4,3 C4,7 2,10 0,10 C-2,10 -4,7 -4,3 C-6,1 -8,-2 -8,-7 Z" fill="#495057" transform="translate(0,-1)"/>`);
        default: return g(`<circle r="4.5" fill="${ARCH_COLORS.other}"/>`);
    }
}
function renderArchChart(holder) {
    const arch = holder.dataset.arch;
    const upper = arch === 'upper';
    const teethOrder = ARCH_ORDER[arch];
    const cx = 260, Rx = 185, Ry = 284;
    const cy = upper ? 352 : 48;
    const yOf = (r, t) => upper ? cy - r * Math.sin(t) : cy + r * Math.sin(t);
    let parts = [];
    if (upper) {
        const gx = Rx + 22, gy = Ry + 26, by = cy - 4;
        parts.push(`<path d="M${cx - gx},${by} A${gx},${gy} 0 0 1 ${cx + gx},${by} Q${cx + gx - 55},${by - 28} ${cx + 85},${by - 16} Q${cx},${by - 42} ${cx - 85},${by - 16} Q${cx - gx + 55},${by - 28} ${cx - gx},${by} Z" fill="${ARCH_COLORS.gum}" stroke="${ARCH_COLORS.gumEdge}" stroke-width="2"/>`);
        parts.push(`<path d="M${cx - 60},130 Q${cx},100 ${cx + 60},130 M${cx - 78},170 Q${cx},136 ${cx + 78},170" fill="none" stroke="#e88f8f" stroke-width="2" stroke-linecap="round" opacity="0.7"/>`);
        parts.push(`<line x1="${cx}" y1="72" x2="${cx}" y2="96" stroke="#e88f8f" stroke-width="2" opacity="0.7"/>`);
    } else {
        const gx = Rx + 22, gy = Ry + 26, by = cy + 4;
        const ix = Rx - 48, iy = Ry - 64;
        parts.push(`<path d="M${cx - gx},${by} A${gx},${gy} 0 0 0 ${cx + gx},${by} L${cx + ix},${by} A${ix},${iy} 0 0 1 ${cx - ix},${by} Z" fill="${ARCH_COLORS.gum}" stroke="${ARCH_COLORS.gumEdge}" stroke-width="2"/>`);
    }
    teethOrder.forEach((fdi, i) => {
        const t = (174 - i * (168 / 15)) * Math.PI / 180;
        const x = cx + Rx * Math.cos(t);
        const y = yOf(Ry, t);
        const deg = t * 180 / Math.PI;
        const rot = upper ? (90 - deg) : (270 - deg);
        const shape = occlusalShape(fdi);
        const st = archToothState(fdi);
        const bodyStroke = st.crown ? ARCH_COLORS.crown : ARCH_COLORS.outline;
        const bodyFill = st.crown ? '#e7f3fd' : ARCH_COLORS.body;
        const bodyW = st.crown ? 2.4 : 1.6;
        const ghost = st.extract ? ' opacity="0.35"' : '';
        let overlays = [];
        if (st.filling) overlays.push(archGlyph('filling'));
        if (st.canal) overlays.push(`<g transform="translate(${st.filling ? 9 : 0},${st.filling ? 7 : 0})">${archGlyph('canal')}</g>`);
        if (st.implant) overlays.push(archGlyph('implant'));
        if (st.sparkle) overlays.push(`<g transform="translate(9,-9)">${archGlyph('sparkle', 0.7)}</g>`);
        if (st.dot && !st.filling && !st.canal && !st.implant) overlays.push(archGlyph('dot'));
        if (st.extract) overlays.push(archGlyph('extract'));
        parts.push(`<g class="arch-tooth${st.count ? ' has-treatments' : ''}" data-tooth="${fdi}" onclick="showToothDetail(${fdi})">
            <g transform="translate(${x.toFixed(1)},${y.toFixed(1)}) rotate(${rot.toFixed(1)})"${ghost}>
                <path class="at-body" d="${shape.body}" fill="${bodyFill}" stroke="${bodyStroke}" stroke-width="${bodyW}"/>
                <path d="${shape.detail}" fill="none" stroke="#b9cdd9" stroke-width="1.3" stroke-linecap="round"/>
            </g>
            <g transform="translate(${x.toFixed(1)},${y.toFixed(1)})">${overlays.join('')}</g>
        </g>`);
        const bx = cx + (Rx + 48) * Math.cos(t);
        const by2 = yOf(Ry + 44, t);
        parts.push(`<g class="arch-num" onclick="showToothDetail(${fdi})" style="cursor:pointer">
            <rect x="${(bx - 15).toFixed(1)}" y="${(by2 - 11).toFixed(1)}" width="30" height="22" rx="11" fill="#fff" stroke="${ARCH_COLORS.numBorder}" stroke-width="1.5"/>
            <text x="${bx.toFixed(1)}" y="${(by2 + 4.5).toFixed(1)}" text-anchor="middle" font-size="13" font-weight="700" fill="${ARCH_COLORS.num}" font-family="inherit">${fdi}</text>
        </g>`);
    });
    holder.innerHTML = `<svg viewBox="0 0 520 400" xmlns="http://www.w3.org/2000/svg">${parts.join('')}</svg>`;
}
function renderArchStats(arch) {
    const statsEl = document.querySelector(`[data-arch-stats="${arch}"]`);
    const statusEl = document.querySelector(`[data-arch-status="${arch}"]`);
    if (!statsEl) return;
    const counts = {filling: 0, extract: 0, crown: 0, canal: 0, implant: 0};
    let treatments = 0, price = 0;
    ARCH_ORDER[arch].forEach(fdi => {
        const st = archToothState(fdi);
        if (st.filling) counts.filling++; if (st.extract) counts.extract++; if (st.crown) counts.crown++;
        if (st.canal) counts.canal++; if (st.implant) counts.implant++;
        const d = toothTreatmentMap[fdi];
        if (d) { treatments += d.treatments.length; price += parseFloat(d.total_price) || 0; }
    });
    (serviceTreatmentRows || []).forEach(function (row) { if (row.arch_type === arch) { treatments += 1; price += Number(row.final_price) || 0; } });
    const icon = (kind, s) => `<svg viewBox="-12 -14 24 26" style="width:${s || 20}px;height:${(s || 20) * 1.1}px;">${archGlyph(kind)}</svg>`;
    const card = (value, label, ic) => `<div class="arch-occ-stat"><span class="arch-occ-stat-icon">${ic}</span><b>${value}</b><small>${label}</small></div>`;
    statsEl.innerHTML = card(16, 'کۆی ددان', icon('tooth')) + card(counts.filling, 'پڕکردنەوە', icon('filling')) + card(counts.extract, 'ڕاکێشان', icon('extract'))
        + card(counts.crown, 'تاج', icon('crown')) + card(counts.canal, 'چارەسەری دەمار', icon('canal', 22)) + card(counts.implant, 'ئیمپلانت', icon('implant'));
    if (statusEl) {
        statusEl.innerHTML = treatments > 0
            ? `<i class="fas fa-check-circle"></i> کۆی ئیشی پلانکراو: <b>${treatments}</b> — <b>${numberFormat(price)}</b> (خەمڵاندن)`
            : `<i class="fas fa-info-circle"></i> هیچ ئیشێک پلان نەکراوە`;
        statusEl.classList.toggle('is-empty', treatments === 0);
    }
}
function renderArchLegend() {
    const el = document.getElementById('archOccLegend');
    if (!el) return;
    const icon = kind => `<svg viewBox="-12 -14 24 26" style="width:22px;height:24px;">${archGlyph(kind)}</svg>`;
    el.innerHTML = [['filling', 'پڕکردنەوە'], ['extract', 'ڕاکێشان'], ['crown', 'تاج'], ['canal', 'چارەسەری دەمار'], ['implant', 'ئیمپلانت']]
        .map(([k, label]) => `<div class="arch-occ-legend-item">${icon(k)}<span>${label}</span></div>`).join('');
}
function renderArchCharts() {
    const holders = document.querySelectorAll('.arch-occ-chart[data-arch]');
    if (!holders.length) return;
    holders.forEach(h => { renderArchChart(h); renderArchStats(h.dataset.arch); });
    renderArchLegend();
}

function numberFormat(num) { return new Intl.NumberFormat('en-US').format(Math.round(Number(num) || 0)); }
function escapeHtml(text) { if (text === null || text === undefined) return ''; const div = document.createElement('div'); div.textContent = String(text); return div.innerHTML; }
function toast(type, message) { if (typeof showToast === 'function') showToast(type, message); else alert(message); }

window.showTab = function (tabName, button) {
    const content = document.getElementById('tab-' + tabName);
    if (!content) return;
    document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tp-plan-tabs [role="tab"]').forEach(t => t.setAttribute('aria-selected', t === button ? 'true' : 'false'));
    content.style.display = 'block';
    if (button) button.classList.add('active');
    if (tabName === 'chart') requestAnimationFrame(() => { fitChartScale(); centerJawScroll(); });
};
</script>
