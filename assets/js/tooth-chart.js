/**
 * Anatomical tooth drawings — the renderer cases/view.php and the plan page
 * (treatment-plans/_view_script_chart.php) draw their odontograms with, as a
 * standalone module for pages that only need to draw teeth, such as the
 * treatment-plan tab of the patient page.
 *
 *   ToothChart.svg(fdi, treatments, opts)  one tooth, painted from its work
 *   ToothChart.color(category, name)       the colour a kind of work is drawn in
 *
 * A treatment is {category, treatment_name, tooth_surface}. No dependencies.
 */
(function () {
    'use strict';

    var COLORS = {
        amalgam: '#51b56b', composite: '#f06a94', canal: '#e8637f', crown: '#dfa32b',
        implant: '#78909c', extract: '#4a5560', cleaning: '#2bb3c9', whitening: '#4dabf7',
        cosmetic: '#9775fa', ortho: '#f76707', other: '#4dabf7', outline: '#b8c3d1'
    };

    var SHAPES = {
        molar: {
            body: 'M5,17 C5,6 11,3 22,3 C33,3 39,6 39,17 C39,23 37,27 34,30 C34.5,36 33,44 30.5,51 C29.5,54 27,54 26.5,51 C25.8,45 25,39 24.5,35 Q22,32 19.5,35 C19,39 18.2,45 17.5,51 C17,54 14.5,54 13.5,51 C11,44 9.5,36 10,30 C7,27 5,23 5,17 Z',
            crown: 'M6,16 C6,6 12,3 22,3 C32,3 38,6 38,16 C38,20 37,23.5 35.5,26.5 L8.5,26.5 C7,23.5 6,20 6,16 Z',
            chamber: { cx: 22, cy: 25, rx: 4.5, ry: 3 },
            canals: ['M16,30 C15.2,36 15,42.5 16.5,48.5', 'M28,30 C28.8,36 29,42.5 27.5,48.5'],
            zones: { occlusal: [22, 14.5, 1], left: { cx: 10, cy: 15, rx: 4.5, ry: 7 }, right: { cx: 34, cy: 15, rx: 4.5, ry: 7 }, buccal: { cx: 22, cy: 6.5, rx: 9, ry: 4 }, lingual: { cx: 22, cy: 23.5, rx: 9, ry: 4 } }
        },
        premolar: {
            body: 'M8,16 C8,7 13,4 22,4 C31,4 36,7 36,16 C36,22 34,26 31.5,29 C31,37 29,46 24.5,52.5 Q22,55.5 19.5,52.5 C15,46 13,37 12.5,29 C10,26 8,22 8,16 Z',
            crown: 'M9,15 C9,7 14,4 22,4 C30,4 35,7 35,15 C35,19 34,22.5 32.5,25.5 L11.5,25.5 C10,22.5 9,19 9,15 Z',
            chamber: { cx: 22, cy: 24.5, rx: 3.8, ry: 2.8 },
            canals: ['M22,28 C21.8,36 21.8,43 22,49'],
            zones: { occlusal: [22, 14, 0.88], left: { cx: 12, cy: 14.5, rx: 4, ry: 6.2 }, right: { cx: 32, cy: 14.5, rx: 4, ry: 6.2 }, buccal: { cx: 22, cy: 7, rx: 8, ry: 3.6 }, lingual: { cx: 22, cy: 22.5, rx: 8, ry: 3.6 } }
        },
        canine: {
            body: 'M22,2.5 C29,4 35,7 35,14.5 C35,20 33,25 30,28.5 C29.5,38 27,47 23.8,53.5 Q22,56.5 20.2,53.5 C17,47 14.5,38 14,28.5 C11,25 9,20 9,14.5 C9,7 15,4 22,2.5 Z',
            crown: 'M22,2.5 C29,4 35,7 35,14.5 C35,18.5 34,22 32,25 L12,25 C10,22 9,18.5 9,14.5 C9,7 15,4 22,2.5 Z',
            chamber: { cx: 22, cy: 24, rx: 3.2, ry: 2.6 },
            canals: ['M22,27 C21.8,35 21.9,43 22,50'],
            zones: { occlusal: [22, 13.5, 0.82], left: { cx: 12.5, cy: 14, rx: 3.8, ry: 6 }, right: { cx: 31.5, cy: 14, rx: 3.8, ry: 6 }, buccal: { cx: 22, cy: 6.5, rx: 7.5, ry: 3.4 }, lingual: { cx: 22, cy: 21.5, rx: 7.5, ry: 3.4 } }
        },
        incisor: {
            body: 'M11,3.5 C18,2 26,2 33,3.5 C33.5,10 32.5,17 30.5,23.5 C30,32 27,44 23.5,52 Q22,55 20.5,52 C17,44 14,32 13.5,23.5 C11.5,17 10.5,10 11,3.5 Z',
            crown: 'M11,3.5 C18,2 26,2 33,3.5 C33.3,9 32.7,15 31.3,20.5 L12.7,20.5 C11.3,15 10.7,9 11,3.5 Z',
            chamber: { cx: 22, cy: 24, rx: 3, ry: 2.5 },
            canals: ['M22,26.5 C21.8,34 21.9,42 22,49.5'],
            zones: { occlusal: [22, 12, 0.78], left: { cx: 13, cy: 12, rx: 3.5, ry: 6 }, right: { cx: 31, cy: 12, rx: 3.5, ry: 6 }, buccal: { cx: 22, cy: 4.8, rx: 8, ry: 2.8 }, lingual: { cx: 22, cy: 19, rx: 7.5, ry: 3.2 } }
        }
    };
    var ANATOMY = {
        molar: ['M10.5,13.5 C14.5,17.5 18,17.5 22,13.5 C26,17.5 29.5,17.5 33.5,13.5', 'M12.5,22 C16.5,19 19.5,20 22,22 C24.5,20 27.5,19 31.5,22'],
        premolar: ['M13.5,13 C17.5,17 19.5,17 22,13 C24.5,17 26.5,17 30.5,13', 'M15,22 C18.5,19.5 21,20.5 22,22 C23,20.5 25.5,19.5 29,22'],
        canine: ['M14,14 C17.5,17.5 20,17.5 22,14 C24,17.5 26.5,17.5 30,14', 'M16,22 C18.5,20 20.5,20 22,22 C23.5,20 25.5,20 28,22'],
        incisor: ['M13,11.5 Q22,15.5 31,11.5', 'M14.5,19.5 Q22,17.2 29.5,19.5']
    };

    function isPrimary(fdi) { var q = Math.floor(fdi / 10); return q >= 5 && q <= 8; }
    function shapeKey(fdi) {
        var p = fdi % 10;
        if (p <= 2) return 'incisor';
        if (p === 3) return 'canine';
        if (isPrimary(fdi) || p > 5) return 'molar';
        return 'premolar';
    }
    function mesialIsLeft(fdi) { var q = Math.floor(fdi / 10); return q === 1 || q === 4 || q === 5 || q === 8; }
    function starPath(cx, cy, s) {
        var p = [[0,-7],[1.6,-4.6],[4.9,-5.4],[4.2,-2.1],[7,0],[4.2,2.1],[4.9,5.4],[1.6,4.6],[0,7],[-1.6,4.6],[-4.9,5.4],[-4.2,2.1],[-7,0],[-4.2,-2.1],[-4.9,-5.4],[-1.6,-4.6]]
            .map(function (xy) { return (cx + xy[0] * s).toFixed(1) + ',' + (cy + xy[1] * s).toFixed(1); });
        return 'M' + p.join(' L') + ' Z';
    }

    /** The colour one kind of work is drawn in — the same mapping the charts use. */
    function color(category, name) {
        switch (category) {
            case 'filling': return /کۆمپۆزیت|كۆمپۆزیت|composite/i.test(name || '') ? COLORS.composite : COLORS.amalgam;
            case 'root_canal': return COLORS.canal;
            case 'crown': case 'bridge': return COLORS.crown;
            case 'implant': return COLORS.implant;
            case 'extraction': case 'surgery': return COLORS.extract;
            case 'cleaning': return COLORS.cleaning;
            case 'whitening': return COLORS.whitening;
            case 'cosmetic': return COLORS.cosmetic;
            case 'orthodontic': return COLORS.ortho;
            default: return COLORS.other;
        }
    }

    /** What to paint on a tooth, from the work planned or done on it. */
    function stateOf(treatments) {
        var st = { surfaces: {}, rootZone: null, canal: false, crown: false, implant: false, extract: false, sparkle: null, bracket: false, dot: false };
        (treatments || []).forEach(function (t) {
            var cat = t.category || 'other';
            var surfs = String(t.tooth_surface || '').split(',').filter(Boolean)
                .map(function (s) { return s === 'incisal' ? 'occlusal' : s; });
            var paint = function (c, fallback) {
                (surfs.length ? surfs : fallback).forEach(function (s) {
                    if (s === 'root') st.rootZone = c; else st.surfaces[s] = c;
                });
            };
            if (cat === 'filling') paint(color(cat, t.treatment_name), ['occlusal']);
            else if (cat === 'cosmetic') paint(COLORS.cosmetic, ['buccal']);
            else if (cat === 'root_canal') st.canal = true;
            else if (cat === 'crown' || cat === 'bridge') st.crown = true;
            else if (cat === 'implant') st.implant = true;
            else if (cat === 'extraction' || cat === 'surgery') st.extract = true;
            else if (cat === 'cleaning') st.sparkle = COLORS.cleaning;
            else if (cat === 'whitening') st.sparkle = COLORS.whitening;
            else if (cat === 'orthodontic') st.bracket = true;
            else if (surfs.length) paint(COLORS.other, []);
            else st.dot = true;
        });
        return st;
    }

    /**
     * One tooth as SVG markup (viewBox 44×60, crown at the top).
     * opts.idPrefix keeps the gradient/clip ids unique when a page draws the
     * same tooth twice; opts.label is the accessible name.
     */
    function svg(fdi, treatments, opts) {
        opts = opts || {};
        var st = stateOf(treatments);
        var key = shapeKey(fdi), shape = SHAPES[key], z = shape.zones;
        var uid = (opts.idPrefix || 'tc') + '-' + fdi;
        var parts = [];
        if (!st.implant) {
            parts.push('<g' + (st.extract ? ' opacity="0.18"' : '') + '>');
            parts.push('<path d="' + shape.body + '" fill="url(#tb-' + uid + ')" stroke="' + COLORS.outline + '" stroke-width="1.95" stroke-linejoin="round"/>');
            parts.push('<g fill="none" stroke="#d5dee8" stroke-width="1.05" stroke-linecap="round" opacity="0.72">'
                + ANATOMY[key].map(function (d) { return '<path d="' + d + '"/>'; }).join('') + '</g>');
            parts.push('<g clip-path="url(#tk-' + uid + ')">');
            if (st.rootZone) parts.push('<rect x="0" y="29" width="44" height="31" fill="' + st.rootZone + '" fill-opacity="0.55"/>');
            var geo = { mesial: z[mesialIsLeft(fdi) ? 'left' : 'right'], distal: z[mesialIsLeft(fdi) ? 'right' : 'left'], buccal: z.buccal, lingual: z.lingual };
            ['mesial', 'distal', 'buccal', 'lingual'].forEach(function (s) {
                var f = st.surfaces[s], g = geo[s];
                if (f) parts.push('<ellipse cx="' + g.cx + '" cy="' + g.cy + '" rx="' + (g.rx * 1.08).toFixed(2) + '" ry="' + (g.ry * 1.08).toFixed(2) + '" fill="' + f + '" fill-opacity="0.94" stroke="#00000022" stroke-width="0.7"/>');
            });
            if (st.surfaces.occlusal) {
                parts.push('<path d="' + starPath(z.occlusal[0], z.occlusal[1], z.occlusal[2] * 1.16) + '" fill="' + st.surfaces.occlusal + '" fill-opacity="0.96" stroke="#00000022" stroke-width="0.7"/>');
            }
            parts.push('</g>');
            if (st.canal) {
                var c = shape.chamber;
                parts.push('<ellipse cx="' + c.cx + '" cy="' + c.cy + '" rx="' + c.rx + '" ry="' + c.ry + '" fill="' + COLORS.canal + '" fill-opacity="0.9"/>');
                shape.canals.forEach(function (d) { parts.push('<path d="' + d + '" fill="none" stroke="' + COLORS.canal + '" stroke-width="2.6" stroke-linecap="round"/>'); });
            }
            parts.push('</g>');
        } else {
            parts.push('<g fill="' + COLORS.implant + '" stroke="' + COLORS.implant + '">'
                + '<rect x="17.5" y="6" width="9" height="6" rx="1.2"/>'
                + '<path d="M14.5,13.5 L29.5,13.5 L27.8,23 L16.2,23 Z"/>'
                + '<path d="M16.5,25 L27.5,25 L25.8,49 Q22,54 18.2,49 Z"/>');
            [29, 34, 39, 44].forEach(function (y, i) {
                var inset = 16.8 + i * 0.55;
                parts.push('<line x1="' + inset + '" y1="' + y + '" x2="' + (44 - inset) + '" y2="' + y + '" stroke="#fff" stroke-width="1.6"/>');
            });
            parts.push('</g>');
        }
        if (st.crown) parts.push('<path d="' + shape.crown + '" fill="' + COLORS.crown + '" fill-opacity="0.18" stroke="' + COLORS.crown + '" stroke-width="2.65" stroke-linejoin="round"/>');
        if (st.bracket) {
            parts.push('<line x1="5" y1="14.5" x2="39" y2="14.5" stroke="' + COLORS.ortho + '" stroke-width="1.4"/>');
            parts.push('<rect x="18" y="10.5" width="8" height="8" rx="1.5" fill="#fff" stroke="' + COLORS.ortho + '" stroke-width="1.8"/>');
        }
        if (st.sparkle) parts.push('<path d="M34,1 L35.6,5 L39.5,6.5 L35.6,8 L34,12 L32.4,8 L28.5,6.5 L32.4,5 Z" fill="' + st.sparkle + '"/>');
        if (st.dot) parts.push('<circle cx="34" cy="23" r="3.2" fill="' + COLORS.other + '" stroke="#fff" stroke-width="1.2"/>');
        if (st.extract) {
            parts.push('<g stroke="' + COLORS.extract + '" stroke-width="3.6" stroke-linecap="round"><line x1="12" y1="14" x2="32" y2="44"/><line x1="32" y1="14" x2="12" y2="44"/></g>');
        }
        var defs = '<linearGradient id="tb-' + uid + '" x1="18%" y1="0%" x2="82%" y2="100%"><stop offset="0%" stop-color="#ffffff"/><stop offset="58%" stop-color="#fbfcfe"/><stop offset="100%" stop-color="#edf2f7"/></linearGradient>'
                 + '<clipPath id="tk-' + uid + '"><path d="' + shape.body + '"/></clipPath>';
        var label = String(opts.label || ('Tooth ' + fdi)).replace(/[&<>"]/g, '');
        return '<svg viewBox="0 0 44 60" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' + label + '"><defs>' + defs + '</defs>' + parts.join('') + '</svg>';
    }

    window.ToothChart = { COLORS: COLORS, svg: svg, color: color, isPrimary: isPrimary, shapeKey: shapeKey };
})();
