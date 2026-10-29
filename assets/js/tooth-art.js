/* assets/js/tooth-art.js — how one tooth is drawn, shared by a case
 * (cases/view.php) and a treatment plan (treatment-plans/view.php).
 *
 * Uses the page's own chart globals at call time: CHART_COLORS, toothNames,
 * toothState(), toothShapeKey(), mesialIsLeft(), starPath().
 */

// Tooth shapes by type (viewBox 0 0 44 60 — crown up, roots down), drawn
// like a textbook tooth: white enamel crown, ivory root.
// crown = the gold cap overlay, cej = where the enamel ends (y), gloss = the
// shine on the crown, canal = pulp chamber + canals,
// zones = ellipse geometry for the 5 surface areas.
const TOOTH_SHAPES = {
    molar: {
        body: 'M9.5,27 C7,25 5,21 5,16 C5,10 6.5,5.5 11,4.2 C14,3.4 17.2,4 19.2,6 Q22,7.6 24.8,6 C26.8,4 30,3.4 33,4.2 C37.5,5.5 39,10 39,16 C39,21 37,25 34.5,27 C34.6,34 33.2,42 31,50 C30,54 27.4,55 26.6,51.6 C26,46 25.4,40 24,35.2 Q22,31.6 20,35.2 C18.6,40 18,46 17.4,51.6 C16.6,55 14,54 13,50 C10.8,42 9.4,34 9.5,27 Z',
        crown: 'M9.5,27 C7,25 5,21 5,16 C5,10 6.5,5.5 11,4.2 C14,3.4 17.2,4 19.2,6 Q22,7.6 24.8,6 C26.8,4 30,3.4 33,4.2 C37.5,5.5 39,10 39,16 C39,21 37,25 34.5,27 Q22,29.6 9.5,27 Z',
        cej: 27,
        gloss: {cx: 14.5, cy: 13, rx: 3.6, ry: 6.5},
        chamber: {cx: 22, cy: 25, rx: 4.5, ry: 3},
        canals: ['M15.5,30 C14.8,36 14.8,42.5 15.8,48.5', 'M28.5,30 C29.2,36 29.2,42.5 28.2,48.5'],
        zones: {
            occlusal: {star: [22, 14.5, 1]},
            left:  {cx: 10, cy: 15, rx: 4.5, ry: 7},
            right: {cx: 34, cy: 15, rx: 4.5, ry: 7},
            buccal:  {cx: 22, cy: 7.5, rx: 9, ry: 3.6},
            lingual: {cx: 22, cy: 23.5, rx: 9, ry: 4}
        }
    },
    premolar: {
        body: 'M12.5,26.5 C10,24 8,20 8,15.5 C8,9.5 11.5,5.2 16.5,4 Q22,1.6 27.5,4 C32.5,5.2 36,9.5 36,15.5 C36,20 34,24 31.5,26.5 C31.6,34 29.6,43 25.6,51.4 Q22.4,57.4 18.6,51.6 C14.6,43 12.4,34 12.5,26.5 Z',
        crown: 'M12.5,26.5 C10,24 8,20 8,15.5 C8,9.5 11.5,5.2 16.5,4 Q22,1.6 27.5,4 C32.5,5.2 36,9.5 36,15.5 C36,20 34,24 31.5,26.5 Q22,29 12.5,26.5 Z',
        cej: 26.5,
        gloss: {cx: 16, cy: 12.5, rx: 3.2, ry: 6},
        chamber: {cx: 22, cy: 24.5, rx: 3.8, ry: 2.8},
        canals: ['M22,28 C21.8,36 21.8,43 22,49'],
        zones: {
            occlusal: {star: [22, 14, 0.88]},
            left:  {cx: 12, cy: 14.5, rx: 4, ry: 6.2},
            right: {cx: 32, cy: 14.5, rx: 4, ry: 6.2},
            buccal:  {cx: 22, cy: 7, rx: 8, ry: 3.6},
            lingual: {cx: 22, cy: 22.5, rx: 8, ry: 3.6}
        }
    },
    canine: {
        body: 'M14,25.5 C11.5,22 9.6,17.5 10,13 C10.4,9 13,6.4 16.6,5 Q19.6,3.6 22,1.8 Q24.4,3.6 27.4,5 C31,6.4 33.6,9 34,13 C34.4,17.5 32.5,22 30,25.5 C30,35 27.6,46 24,54.4 Q22,58.4 20,54.4 C16.4,46 14,35 14,25.5 Z',
        crown: 'M14,25.5 C11.5,22 9.6,17.5 10,13 C10.4,9 13,6.4 16.6,5 Q19.6,3.6 22,1.8 Q24.4,3.6 27.4,5 C31,6.4 33.6,9 34,13 C34.4,17.5 32.5,22 30,25.5 Q22,28 14,25.5 Z',
        cej: 25.5,
        gloss: {cx: 17, cy: 13, rx: 2.8, ry: 6},
        chamber: {cx: 22, cy: 24, rx: 3.2, ry: 2.6},
        canals: ['M22,27 C21.8,35 21.9,43 22,50'],
        zones: {
            occlusal: {star: [22, 13.5, 0.82]},
            left:  {cx: 12.5, cy: 14, rx: 3.8, ry: 6},
            right: {cx: 31.5, cy: 14, rx: 3.8, ry: 6},
            buccal:  {cx: 22, cy: 7, rx: 7.5, ry: 3.4},
            lingual: {cx: 22, cy: 21.5, rx: 7.5, ry: 3.4}
        }
    },
    incisor: {
        body: 'M14,24 C12.6,19 11,12.5 11,6.2 Q11,3.2 14.2,3 L29.8,3 Q33,3.2 33,6.2 C33,12.5 31.4,19 30,24 C29.6,33 27.2,44 23.8,52.6 Q22,56.6 20.2,52.6 C16.8,44 14.4,33 14,24 Z',
        crown: 'M14,24 C12.6,19 11,12.5 11,6.2 Q11,3.2 14.2,3 L29.8,3 Q33,3.2 33,6.2 C33,12.5 31.4,19 30,24 Q22,26.6 14,24 Z',
        cej: 24,
        gloss: {cx: 17, cy: 11.5, rx: 2.8, ry: 6},
        chamber: {cx: 22, cy: 24, rx: 3, ry: 2.5},
        canals: ['M22,26.5 C21.8,34 21.9,42 22,49.5'],
        zones: {
            occlusal: {star: [22, 12, 0.78]},
            left:  {cx: 13, cy: 12, rx: 3.5, ry: 6},
            right: {cx: 31, cy: 12, rx: 3.5, ry: 6},
            buccal:  {cx: 22, cy: 5, rx: 8, ry: 2.8},
            lingual: {cx: 22, cy: 19, rx: 7.5, ry: 3.2}
        }
    }
};

// Fine anatomy lines — the cusp ridges and grooves seen on the crown. They
// are drawn underneath treatment overlays, so treatment data remains the
// visual priority and surface picking keeps the same SVG hit targets.
const TOOTH_ANATOMY = {
    molar: [
        'M8.6,9.6 C12,12.6 17,12.4 22,10.2 C27,12.4 32,12.6 35.4,9.6',
        'M22,10.6 C21.6,14 21.8,17 22.4,20'
    ],
    premolar: [
        'M11.2,10.2 C15,12.6 19,12.2 22,9.4 C25,12.2 29,12.6 32.8,10.2'
    ],
    canine: [
        'M22,4.6 C21.5,10 21.6,15.5 22.2,21'
    ],
    incisor: [
        'M13.4,6.6 Q22,8.4 30.6,6.6'
    ]
};

/**
 * Render one tooth as an SVG string.
 * opts: { idPrefix, state, interactive (zones clickable), selectedSurfaces (Set),
 *         showAllZones (ghost zones visible, for the picker) }
 */
function toothSVG(fdi, opts) {
    opts = opts || {};
    const st = opts.state || toothState(fdi);
    const shapeKey = toothShapeKey(fdi);
    const shape = TOOTH_SHAPES[shapeKey];
    const uid = (opts.idPrefix || 't') + '-' + fdi;
    const zoneOf = side => (mesialIsLeft(fdi) === (side === 'left')) ? 'mesial' : 'distal';
    const selected = opts.selectedSurfaces || null;

    // enamel white down to the crown's neck, then ivory root; a side-to-side
    // shade over it rounds the tooth, and a soft shine sits on the crown
    const cej = shape.cej / 60;
    let defs = `
        <linearGradient id="tooth-base-${uid}" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="0" y2="60">
            <stop offset="0" stop-color="#ffffff"/>
            <stop offset="${(cej - 0.07).toFixed(3)}" stop-color="#f8f9fb"/>
            <stop offset="${(cej + 0.025).toFixed(3)}" stop-color="#f3e9d5"/>
            <stop offset="1" stop-color="#dfcba5"/>
        </linearGradient>
        <linearGradient id="tooth-shade-${uid}" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#3f4b5c" stop-opacity=".30"/>
            <stop offset=".17" stop-color="#3f4b5c" stop-opacity=".06"/>
            <stop offset=".35" stop-color="#ffffff" stop-opacity=".6"/>
            <stop offset=".52" stop-color="#ffffff" stop-opacity="0"/>
            <stop offset=".83" stop-color="#3f4b5c" stop-opacity=".08"/>
            <stop offset="1" stop-color="#3f4b5c" stop-opacity=".32"/>
        </linearGradient>
        <radialGradient id="tooth-gloss-${uid}">
            <stop offset="0" stop-color="#ffffff" stop-opacity=".95"/>
            <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
        </radialGradient>
        <linearGradient id="tooth-sweep-${uid}" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#ffffff" stop-opacity="0"/>
            <stop offset=".5" stop-color="#ffffff" stop-opacity=".9"/>
            <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
        </linearGradient>
        <clipPath id="clip-${uid}"><path d="${shape.body}"/></clipPath>`;
    let parts = [];

    // ── tooth body ──
    const ghost = st.extract ? ' opacity="0.18"' : '';
    if (!st.implant) {
        parts.push(`<g${ghost}>`);
        parts.push(`<path d="${shape.body}" fill="url(#tooth-base-${uid})"/>`);
        parts.push(`<path d="${shape.body}" fill="url(#tooth-shade-${uid})"/>`);
        const gl = shape.gloss;
        parts.push(`<ellipse cx="${gl.cx}" cy="${gl.cy}" rx="${gl.rx}" ry="${gl.ry}" fill="url(#tooth-gloss-${uid})"/>`);
        const anatomy = TOOTH_ANATOMY[shapeKey] || [];
        if (anatomy.length) {
            parts.push(`<g fill="none" stroke="#bfc6d0" stroke-width="0.9" stroke-linecap="round" stroke-linejoin="round" opacity="0.8">`);
            anatomy.forEach(path => parts.push(`<path d="${path}"/>`));
            parts.push('</g>');
        }

        // clipped layers (zones, root highlight)
        parts.push(`<g clip-path="url(#clip-${uid})">`);
        // root zone highlight
        const rootFill = selected ? (selected.has('root') ? CHART_COLORS.other : null) : st.rootZone;
        if (opts.showAllZones) {
            parts.push(`<rect x="0" y="29" width="44" height="31" fill="${rootFill || '#eceff3'}" fill-opacity="${rootFill ? 0.75 : 0.55}" data-surface="root" style="cursor:pointer"/>`);
        } else if (rootFill) {
            parts.push(`<rect x="0" y="29" width="44" height="31" fill="${rootFill}" fill-opacity="0.55"/>`);
        }
        // surface zones
        const zoneEls = [];
        const zdef = shape.zones;
        const zoneGeo = {
            occlusal: null, // star, handled below
            mesial: zdef[mesialIsLeft(fdi) ? 'left' : 'right'],
            distal: zdef[mesialIsLeft(fdi) ? 'right' : 'left'],
            buccal: zdef.buccal,
            lingual: zdef.lingual
        };
        ['mesial', 'distal', 'buccal', 'lingual'].forEach(surf => {
            const g = zoneGeo[surf];
            const fill = selected ? (selected.has(surf) ? CHART_COLORS.other : null) : st.surfaces[surf];
            if (opts.showAllZones) {
                zoneEls.push(`<ellipse cx="${g.cx}" cy="${g.cy}" rx="${(g.rx * 1.06).toFixed(2)}" ry="${(g.ry * 1.06).toFixed(2)}" fill="${fill || '#dfe5ea'}" fill-opacity="${fill ? 0.9 : 0.6}" stroke="${fill ? '#00000033' : '#c3ccd4'}" stroke-width="0.7" data-surface="${surf}" style="cursor:pointer"/>`);
            } else if (fill) {
                zoneEls.push(`<ellipse cx="${g.cx}" cy="${g.cy}" rx="${(g.rx * 1.08).toFixed(2)}" ry="${(g.ry * 1.08).toFixed(2)}" fill="${fill}" fill-opacity="0.94" stroke="#00000022" stroke-width="0.7"/>`);
            }
        });
        // occlusal star patch
        const [scx, scy, ss] = zdef.occlusal.star;
        const oFill = selected ? (selected.has('occlusal') ? CHART_COLORS.other : null) : st.surfaces.occlusal;
        if (opts.showAllZones) {
            zoneEls.push(`<path d="${starPath(scx, scy, ss * 1.12)}" fill="${oFill || '#dfe5ea'}" fill-opacity="${oFill ? 0.95 : 0.65}" stroke="${oFill ? '#00000033' : '#c3ccd4'}" stroke-width="0.7" data-surface="occlusal" style="cursor:pointer"/>`);
        } else if (oFill) {
            zoneEls.push(`<path d="${starPath(scx, scy, ss * 1.16)}" fill="${oFill}" fill-opacity="0.96" stroke="#00000022" stroke-width="0.7"/>`);
        }
        parts.push(zoneEls.join(''));
        // a band of light, parked off the tooth until hover sweeps it across (CSS)
        parts.push(`<g transform="skewX(-16)"><rect class="tooth-shine" x="-26" y="-4" width="16" height="68" fill="url(#tooth-sweep-${uid})" pointer-events="none"/></g>`);
        parts.push('</g>'); // end clip
        // the outline last, so it stays crisp over the surface colours
        parts.push(`<path d="${shape.body}" fill="none" stroke="${CHART_COLORS.outline}" stroke-width="1.2" stroke-linejoin="round"/>`);

        // root canal indicator
        if (st.canal) {
            const c = shape.chamber;
            parts.push(`<ellipse cx="${c.cx}" cy="${c.cy}" rx="${c.rx}" ry="${c.ry}" fill="${CHART_COLORS.canal}" fill-opacity="0.9"/>`);
            shape.canals.forEach(p => {
                parts.push(`<path d="${p}" fill="none" stroke="${CHART_COLORS.canal}" stroke-width="2.6" stroke-linecap="round"/>`);
            });
        }
        // a post / screw in the canal, over the canal it sits in: grey metal down
        // the first canal, its head in the crown
        if (st.post) {
            parts.push(`<path d="${shape.canals[0]}" fill="none" stroke="#6f7a86" stroke-width="3.6" stroke-linecap="round"/>`);
            parts.push(`<path d="${shape.canals[0]}" fill="none" stroke="#d5dbe1" stroke-width="1.2" stroke-linecap="round"/>`);
            parts.push(`<rect x="${shape.chamber.cx - 3.6}" y="${shape.chamber.cy - 3}" width="7.2" height="6" rx="1.6" fill="#9aa5b1" stroke="#6f7a86" stroke-width=".8"/>`);
        }
        parts.push('</g>'); // end ghost group
    } else {
        // ── implant: screw instead of tooth ──
        parts.push(`<g fill="${CHART_COLORS.implant}" stroke="${CHART_COLORS.implant}">`);
        parts.push(`<rect x="17.5" y="6" width="9" height="6" rx="1.2"/>`);
        parts.push(`<path d="M14.5,13.5 L29.5,13.5 L27.8,23 L16.2,23 Z"/>`);
        parts.push(`<path d="M16.5,25 L27.5,25 L25.8,49 Q22,54 18.2,49 Z"/>`);
        [29, 34, 39, 44].forEach((y, i) => {
            const inset = 16.8 + i * 0.55;
            parts.push(`<line x1="${inset}" y1="${y}" x2="${44 - inset}" y2="${y}" stroke="#fff" stroke-width="1.6"/>`);
        });
        parts.push('</g>');
    }

    // crown / bridge overlay (also valid on implants)
    if (st.crown) {
        parts.push(`<path d="${shape.crown}" fill="${CHART_COLORS.crown}" fill-opacity="0.18" stroke="${CHART_COLORS.crown}" stroke-width="2.65" stroke-linejoin="round"/>`);
    }
    // orthodontic bracket
    if (st.bracket) {
        parts.push(`<line x1="5" y1="14.5" x2="39" y2="14.5" stroke="${CHART_COLORS.ortho}" stroke-width="1.4"/>`);
        parts.push(`<rect x="18" y="10.5" width="8" height="8" rx="1.5" fill="#fff" stroke="${CHART_COLORS.ortho}" stroke-width="1.8"/>`);
    }
    // cleaning / whitening sparkle
    if (st.sparkle) {
        parts.push(`<path d="M34,1 L35.6,5 L39.5,6.5 L35.6,8 L34,12 L32.4,8 L28.5,6.5 L32.4,5 Z" fill="${st.sparkle}"/>`);
    }
    // examination / generic marker
    if (st.dot) {
        parts.push(`<circle cx="34" cy="23" r="3.2" fill="${CHART_COLORS.other}" stroke="#fff" stroke-width="1.2"/>`);
    }
    // extraction X on top of everything
    if (st.extract) {
        parts.push(`<g stroke="${CHART_COLORS.extract}" stroke-width="3.6" stroke-linecap="round">`);
        parts.push(`<line x1="12" y1="14" x2="32" y2="44"/><line x1="32" y1="14" x2="12" y2="44"/>`);
        parts.push('</g>');
    }

    return `<svg viewBox="0 0 44 60" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="${toothNames[fdi] || ('Tooth ' + fdi)}" shape-rendering="geometricPrecision"><defs>${defs}</defs>${parts.join('')}</svg>`;
}

// ── Whole-arch work on the tooth chart ──────────────────────────────
// Work filed on a whole jaw (arch_type upper / lower: braces, a denture …)
// is shown on that jaw of the first tooth set on screen: a chip a treatment
// (its icon, its name, how many), a soft tint behind the jaw, and — for
// braces — a bracket on every tooth, whose wire segments meet into one wire.
// Shared by cases/view.php and treatment-plans/view.php.

/** The arch work grouped by jaw: {pane, upper: [chips], lower: [chips], braces: {upper, lower}}. */
function archWorkPlan(rows) {
    const visible = document.querySelector('#smartDentalChart .dentition-pane:not([hidden])');
    const plan = { pane: visible ? visible.dataset.dentition : 'permanent', upper: [], lower: [], braces: { upper: false, lower: false } };
    const kindOf = row => {
        const key = window.TreatmentIcons ? TreatmentIcons.keyFor({ name: row.treatment_name, category: row.category, icon: row.icon }) : '';
        if (key === 'orthodontic' || row.category === 'orthodontic') return ['ortho', 'orthodontic'];
        if (key === 'denture') return ['denture', key];
        return ['other', key];
    };
    (rows || []).forEach(row => {
        if (row.arch_type !== 'upper' && row.arch_type !== 'lower') return;
        const [kind, icon] = kindOf(row);
        const jaw = plan[row.arch_type];
        let chip = jaw.find(c => c.name === row.treatment_name);
        if (!chip) { chip = { name: row.treatment_name, kind: kind, icon: icon, count: 0, total: 0 }; jaw.push(chip); }
        chip.count += Number(row.quantity) || 1;
        chip.total += Number(row.final_price) || 0;
        if (kind === 'ortho') plan.braces[row.arch_type] = true;
    });
    return plan;
}

/** Whether a tooth wears the braces of its jaw (only on the set the chips are on). */
function archWorkBracket(plan, fdi) {
    const q = Math.floor(fdi / 10);
    const primary = q >= 5;
    if ((plan.pane === 'primary') !== primary) return false;
    return (q === 1 || q === 2 || q === 5 || q === 6) ? plan.braces.upper : plan.braces.lower;
}

/** The chips and the tint, on the jaws of the set on screen. */
function renderArchWork(plan) {
    const chart = document.getElementById('smartDentalChart');
    if (!chart) return;
    chart.querySelectorAll('.jaw-arch-row').forEach(el => el.remove());
    chart.querySelectorAll('.jaw-section').forEach(el => el.classList.remove('has-arch-ortho', 'has-arch-denture', 'has-arch-other'));
    const pane = chart.querySelector('.dentition-pane[data-dentition="' + plan.pane + '"]');
    if (!pane) return;
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = n => new Intl.NumberFormat('en-US').format(Math.round(Number(n) || 0));
    [['upper', 'سەرەوە', 'fa-arrow-up'], ['lower', 'خوارەوە', 'fa-arrow-down']].forEach(([jaw, label, arrow]) => {
        const chips = plan[jaw];
        const section = pane.querySelector('.jaw-section--' + jaw);
        if (!chips.length || !section) return;
        const kinds = chips.map(c => c.kind);
        section.classList.add('has-arch-' + (kinds.includes('ortho') ? 'ortho' : kinds.includes('denture') ? 'denture' : 'other'));
        const row = document.createElement('div');
        row.className = 'jaw-arch-row';
        row.innerHTML = `<span class="jaw-arch-label"><i class="fas ${arrow}"></i> ئەرکی ${label}</span>` + chips.map(c =>
            `<span class="jaw-arch-chip is-${c.kind}" title="${esc(c.name)} — ${fmt(c.total)}">`
            + `<span class="jaw-arch-ico">${window.TreatmentIcons ? TreatmentIcons.svg(c.icon || 'other') : ''}</span>${esc(c.name)}`
            + (c.count > 1 ? `<b>×${c.count}</b>` : '') + `</span>`).join('');
        if (jaw === 'upper') section.insertBefore(row, section.firstChild); else section.appendChild(row);
    });
}

// ── Per-tooth work, readable at a glance ────────────────────────────
// A type saved as «other» is drawn as what its name and icon say (a metal
// crown is a crown, a screw a post); the tooth's badge carries the icon of
// the work that says most about it now, with how many there are, and the
// tooth is tinted in that work's colour. Shared by both charts.

/** The chart's category for a treatment row. */
function chartCategoryOf(t) {
    const cat = t.category || 'other';
    if (cat !== 'other' && cat !== 'examination') return cat;
    if (!window.TreatmentIcons) return cat;
    const key = TreatmentIcons.keyFor({ name: t.treatment_name, category: cat, icon: t.icon });
    return ({
        crown: 'crown', crown_metal: 'crown', cementation: 'crown', bridge: 'bridge', implant: 'implant',
        root_canal: 'root_canal', retreatment: 'root_canal', post: 'post',
        extraction: 'extraction', surgical_extraction: 'extraction', surgery: 'surgery',
        filling: 'filling', filling_cosmetic: 'filling', temporary: 'filling',
        veneer: 'cosmetic', gem: 'cosmetic', cleaning: 'cleaning', whitening: 'whitening', orthodontic: 'orthodontic'
    })[key] || cat;
}
const CHART_LEAD = ['extraction', 'surgery', 'implant', 'crown', 'bridge', 'cosmetic', 'root_canal', 'post',
    'filling', 'orthodontic', 'whitening', 'cleaning', 'examination', 'other'];
/** The treatment that says most about the tooth now (a crown over a root canal over a filling …). */
function chartLeadTreatment(list) {
    const rank = t => { const i = CHART_LEAD.indexOf(chartCategoryOf(t)); return i === -1 ? CHART_LEAD.length : i; };
    return (list || []).reduce((best, t) => (best === null || rank(t) < rank(best) ? t : best), null);
}
/** The tooth's tint: the lead work's colour. */
function chartTintOf(t) {
    const cat = t ? chartCategoryOf(t) : 'other';
    if (cat === 'filling') return /کۆمپۆزیت|كۆمپۆزیت|composite/i.test(t.treatment_name || '') ? CHART_COLORS.composite : CHART_COLORS.amalgam;
    return ({ root_canal: CHART_COLORS.canal, crown: CHART_COLORS.crown, bridge: CHART_COLORS.crown, implant: CHART_COLORS.implant,
        post: CHART_COLORS.implant, extraction: CHART_COLORS.extract, surgery: CHART_COLORS.extract, cosmetic: CHART_COLORS.cosmetic,
        cleaning: CHART_COLORS.cleaning, whitening: CHART_COLORS.whitening, orthodontic: CHART_COLORS.ortho })[cat] || CHART_COLORS.other;
}
/** Paint a tooth's badge (.treatment-count) and tint for its treatments; none clears both. */
function paintToothWork(el, list) {
    let badge = el.querySelector('.treatment-count');
    if (!list || !list.length) {
        if (badge) badge.remove();
        el.style.removeProperty('--tw');
        return;
    }
    const lead = chartLeadTreatment(list);
    el.style.setProperty('--tw', chartTintOf(lead));
    if (!badge) { badge = document.createElement('span'); el.appendChild(badge); }
    badge.className = 'treatment-count has-ico';
    const key = window.TreatmentIcons ? TreatmentIcons.keyFor({ name: lead.treatment_name, category: lead.category, icon: lead.icon }) : '';
    badge.innerHTML = (key ? `<span class="tb-ico">${TreatmentIcons.svg(key)}</span>` : '') + (list.length > 1 ? `<b>${list.length}</b>` : '');
}
